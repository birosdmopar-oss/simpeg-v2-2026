<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Presensi\SlipGaji\BarisSheet;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use App\Libraries\Presensi\SlipGaji\NominalGaji;
use App\Libraries\Presensi\SlipGaji\PencocokanNama;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use App\Libraries\Presensi\SlipGaji\PratinjauImpor;
use App\Models\AuditLogModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Presensi;
use RuntimeException;
use Throwable;

/**
 * D-10 impor Slip Gaji 3 langkah (PRD 5A §7.2, QASMTASK-085) — port `Sl_gaji.php:390-489` / `Lsl_gaji.php:1063-1351`
 * [K] dengan perbaikan keamanan [V2] (P5-37..P5-40). Aturan validasi tetap di logika murni CR-027
 * (`PratinjauImpor`, `KolomGaji`, `BarisImpor`, `NominalGaji`, `PencocokanNama`, `AksesSlip`); kelas ini hanya I/O.
 *
 * - {@see self::pratinjau()} (langkah 1+2): akses role 1 / role 3 ber-satker → validasi berkas (`ValidasiBerkas::periksaImpor`)
 *   → filter periode → simpan pratinjau (`PenyimpanPratinjau`, satu aktif per pengguna) → baca Excel → `PratinjauImpor`
 *   dengan data DB → audit `warning` sekali per kunci bernama tidak cocok (F5-4). Gagal setelah tersimpan → pratinjau dihapus.
 * - {@see self::commit()} (langkah 3): token milik pemanggil (selain itu 404 seragam) → validasi ulang penuh dengan data &
 *   cakupan terkini (F5-3, baris klien tidak pernah diterima) → satu transaksi: kunci baris yang ada `FOR UPDATE`, sisip /
 *   perbarui 28 kolom / lewati / gagal (`PratinjauImpor::rencanaCommit`), audit ringkasan `import` → 1062 = 409
 *   `konflik_data` + rollback, galat lain = 500 generik + rollback. Berkas pratinjau dihapus di `finally`.
 * - Transaksi: `transBegin` → `transStatus` → `transCommit`/`transRollback` + `resetTransStatus` (tanpa `transException`).
 */
final class ImporSlipService
{
    public const EKSTENSI = ['xlsx', 'xls'];

    public const PESAN_FORMAT = 'Format berkas harus .xlsx atau .xls.';

    public const PESAN_PRATINJAU_TIDAK_ADA = 'Pratinjau tidak ditemukan atau sudah kedaluwarsa. Unggah ulang berkas.';

    public const PESAN_GAGAL_COMMIT = 'Impor gagal diproses. Tidak ada data yang disimpan.';

    public const ENTITAS_AUDIT = 'gaji_pegawai_impor';

    private const ER_DUP_ENTRY = 1062;

    private readonly Presensi $config;

    private readonly PenyimpanPratinjau $penyimpan;

    /** @var Closure(): int */
    private readonly Closure $jam;

    /**
     * @param (Closure(): int)|null                              $jam              detik Unix sekarang (bawaan `time()`); disuntik test untuk TTL
     * @param (Closure(BaseConnection, string, PeriodeSlip): void)|null $kaitSebelumTulis seam test (PRD 5A §7.2.6 "boleh memakai seam"): dipanggil
     *                                                                                    tepat sebelum tiap tulis commit, mis. untuk memicu 1062
     */
    public function __construct(
        private readonly ?BaseConnection $db = null,
        ?Presensi $config = null,
        ?PenyimpanPratinjau $penyimpan = null,
        ?Closure $jam = null,
        private readonly ?Closure $kaitSebelumTulis = null,
    ) {
        $this->config    = $config ?? config(Presensi::class);
        $this->penyimpan = $penyimpan ?? new PenyimpanPratinjau($this->config->direktoriPratinjau, $this->config->ttlPratinjauDetik);
        $this->jam       = $jam ?? static fn (): int => time();
    }

    /**
     * Langkah 1+2: unggah + pratinjau.
     *
     * @return array<string, mixed>
     */
    public function pratinjau(AuthContext $auth, ?UploadedFile $berkas, mixed $bulan, mixed $tahun): array
    {
        $cakupan = $this->cakupan($auth);

        if ($berkas === null) {
            throw ValidationException::forField('berkas', 'Berkas wajib diunggah.');
        }

        $valid  = ValidasiBerkas::periksaImpor($berkas, self::EKSTENSI, $this->config->maksUkuranImporByte, self::PESAN_FORMAT, $this->pesanUkuran());
        $filter = PratinjauImpor::filterDariMasukan($bulan, $tahun);

        if (! $filter->valid) {
            throw (new ValidationException((string) $filter->pesan, ['filter_bulan' => [(string) $filter->pesan]]))->denganKode((string) $filter->kode);
        }

        /** @var PeriodeSlip|null $periodeFilter */
        $periodeFilter = $filter->nilai;
        $sekarang      = ($this->jam)();
        $simpan        = $this->penyimpan->simpan($valid['isi'], $valid['ekstensi'], [
            'id_pengguna' => (int) $auth->idPengguna(),
            'role'        => (int) $auth->role(),
            'id_satker'   => $auth->idSatker(),
            'filter'      => $periodeFilter === null ? [] : ['bulan' => $periodeFilter->bulan, 'tahun' => $periodeFilter->tahun],
            'nama_berkas' => $valid['nama_tampil'],
        ], $sekarang);

        try {
            $muat = $this->penyimpan->muat($simpan['token'], (int) $auth->idPengguna(), $sekarang)
                ?? throw new RuntimeException('Pratinjau yang baru disimpan tidak dapat dimuat.');
            $analisis = $this->analisis($muat['path'], $periodeFilter, $cakupan, $this->kunciSudahAda(...));
            $this->auditNamaTidakCocok($auth, $analisis, $simpan['token']);
        } catch (Throwable $e) {
            $this->penyimpan->hapus($simpan['token']);

            throw $e;
        }

        return [
            'token_pratinjau' => $simpan['token'],
            'kedaluwarsa_at'  => $simpan['kedaluwarsa_at'],
            'timpa_bawaan'    => PratinjauImpor::TIMPA_BAWAAN,
            'filter'          => $periodeFilter?->kunci(),
            'ringkasan'       => [
                'valid'    => $analisis['susun']['jumlah_valid'],
                'duplikat' => $analisis['susun']['jumlah_duplikat'],
                'galat'    => $analisis['susun']['jumlah_galat'],
            ],
            'info'  => $analisis['info'],
            'baris' => array_map(fn (array $b): array => $this->barisPratinjau($b, $analisis['nama']), $analisis['susun']['baris']),
        ];
    }

    /**
     * Langkah 3: commit pratinjau milik pemanggil dengan validasi ulang penuh.
     *
     * @return array{disisip: int, diperbarui: int, dilewati: int, gagal: int, baris: list<array<string, mixed>>}
     */
    public function commit(AuthContext $auth, string $token, mixed $timpa): array
    {
        $cakupan = $this->cakupan($auth);

        if ($timpa === null) {
            $timpa = PratinjauImpor::TIMPA_BAWAAN;
        } elseif (! is_bool($timpa)) {
            throw ValidationException::forField('timpa', 'Opsi timpa harus bernilai true atau false.');
        }

        $sekarang = ($this->jam)();
        $this->penyimpan->sapuKedaluwarsa($sekarang);
        $muat = $this->penyimpan->muat($token, (int) $auth->idPengguna(), $sekarang);

        if ($muat === null) {
            throw (new NotFoundException(self::PESAN_PRATINJAU_TIDAK_ADA))->denganKode('pratinjau_tidak_ditemukan');
        }

        try {
            return $this->commitTerkunci($auth, $muat, $cakupan, $timpa, $token, $sekarang);
        } finally {
            $this->penyimpan->hapus($token);
        }
    }

    /**
     * @param array{path: string, meta: array<string, mixed>} $muat
     *
     * @return array{disisip: int, diperbarui: int, dilewati: int, gagal: int, baris: list<array<string, mixed>>}
     */
    private function commitTerkunci(AuthContext $auth, array $muat, ?string $cakupan, bool $timpa, string $token, int $sekarang): array
    {
        $db     = $this->koneksi();
        $filter = $muat['meta']['filter'] ?? [];
        $filter = is_array($filter) && isset($filter['bulan'], $filter['tahun']) ? PeriodeSlip::buat((int) $filter['tahun'], (int) $filter['bulan']) : null;
        $waktu  = gmdate('Y-m-d H:i:s', $sekarang);
        $id     = $auth->idPengguna();

        $db->transBegin();

        try {
            $analisis = $this->analisis($muat['path'], $filter, $cakupan, fn (array $nip): array => $this->kunciSudahAda($nip, true));
            $hitung   = ['disisip' => 0, 'diperbarui' => 0, 'dilewati' => 0, 'gagal' => 0];
            $keluaran = [];
            $periode  = [];

            foreach ($analisis['susun']['baris'] as $b) {
                $rencana = PratinjauImpor::rencanaCommit($b['status'], $timpa);
                $kunci   = $b['periode'] instanceof PeriodeSlip ? $b['periode']->kunci() : null;

                if ($rencana === PratinjauImpor::RENCANA_SISIP || $rencana === PratinjauImpor::RENCANA_PERBARUI) {
                    $this->tulis($db, $rencana, (string) $b['nip'], $b['periode'], $b['nominal'], $waktu, $id);
                    $periode[(string) $kunci] = true;
                }

                $hitung[match ($rencana) {
                    PratinjauImpor::RENCANA_SISIP    => 'disisip',
                    PratinjauImpor::RENCANA_PERBARUI => 'diperbarui',
                    PratinjauImpor::RENCANA_LEWATI   => 'dilewati',
                    default                          => 'gagal',
                }]++;

                $keluaran[] = [
                    'nip'     => $b['nip'],
                    'periode' => $kunci,
                    'hasil'   => $rencana,
                    'galat'   => $this->galatBaris($b),
                ];
            }

            ksort($periode);
            $this->audit($auth, null, 'import', [
                'nama_berkas' => $muat['meta']['nama_berkas'] ?? null,
                'filter'      => $filter?->kunci(),
                'timpa'       => $timpa,
                ...$hitung,
                'periode' => array_keys($periode),
            ], substr(hash('sha256', $token), 0, 8));

            if ($db->transStatus() === false) {
                throw new RuntimeException('Transaksi impor slip gagal.');
            }

            $db->transCommit();

            return [...$hitung, 'baris' => $keluaran];
        } catch (KonflikImporSlip $e) {
            $this->rollback($db);

            throw (new ApiException(
                "Data slip NIP {$e->nip} periode {$e->periode->label()} sudah ditambahkan proses lain. Tidak ada data yang disimpan; ulangi unggah dan pratinjau.",
                409,
                ['nip_periode' => ["{$e->nip} {$e->periode->kunci()}"]],
            ))->denganKode('konflik_data');
        } catch (ApiException $e) {
            $this->rollback($db);

            throw $e;
        } catch (Throwable $e) {
            $this->rollback($db);
            log_message('error', 'Commit impor slip gagal: {kelas} {pesan}', ['kelas' => $e::class, 'pesan' => $e->getMessage()]);

            throw new ApiException(self::PESAN_GAGAL_COMMIT, 500);
        }
    }

    /**
     * Sisip baris baru atau perbarui 28 kolom nominal baris yang ada (tanpa menyentuh status dibuka, token, uang makan; S-6).
     *
     * @param array<string, int> $nominal
     */
    private function tulis(BaseConnection $db, string $rencana, string $nip, PeriodeSlip $periode, array $nominal, string $waktu, ?int $id): void
    {
        $kolom = [];

        foreach (KolomGaji::semuaField() as $field) {
            $kolom[$field] = NominalGaji::keDesimal($nominal[$field] ?? 0);
        }

        try {
            if ($this->kaitSebelumTulis !== null) {
                ($this->kaitSebelumTulis)($db, $nip, $periode);
            }

            if ($rencana === PratinjauImpor::RENCANA_SISIP) {
                $ok = $db->table('gaji_pegawai')->insert(['nip' => $nip, 'bulan' => $periode->bulan, 'tahun' => $periode->tahun, ...$kolom, 'created_at' => $waktu, 'created_by' => $id]);
            } else {
                $ok = $db->table('gaji_pegawai')->where(['nip' => $nip, 'bulan' => $periode->bulan, 'tahun' => $periode->tahun])
                    ->update([...$kolom, 'updated_at' => $waktu, 'updated_by' => $id]);
            }
        } catch (DatabaseException $e) {
            if ((int) $e->getCode() === self::ER_DUP_ENTRY) {
                throw new KonflikImporSlip($nip, $periode);
            }

            throw $e;
        }

        if ($ok === false) {
            if ((int) ($db->error()['code'] ?? 0) === self::ER_DUP_ENTRY) {
                throw new KonflikImporSlip($nip, $periode);
            }

            throw new RuntimeException('Tulis gaji_pegawai gagal: ' . (string) ($db->error()['code'] ?? ''));
        }
    }

    /**
     * Baca berkas + uraikan sheet + susun pratinjau dengan data DB.
     *
     * @param Closure(list<string>): array<string, true> $kunciSudahAda
     *
     * @return array{susun: array<string, mixed>, nama: array<string, string>, info: array<string, mixed>, periodeBaris: array<string, PeriodeSlip>}
     */
    private function analisis(string $path, ?PeriodeSlip $filter, ?string $cakupan, Closure $kunciSudahAda): array
    {
        try {
            $baca = (new PembacaExcelSlip($this->config->maksBarisImporPerSheet))->baca($path);
        } catch (BerkasImporDitolak $e) {
            throw ValidationException::forField('berkas', $e->getMessage());
        }

        $baca->lemparBilaGagal('berkas');
        /** @var array{GPP: array<int, array<int, mixed>>, TK: array<int, array<int, mixed>>} $mentah */
        $mentah = $baca->nilai;
        $sheet  = [];
        $info   = ['baris_tanpa_nip' => [], 'kolom_diabaikan' => []];

        foreach ([KolomGaji::SHEET_GPP, KolomGaji::SHEET_TK] as $nama) {
            $urai = PratinjauImpor::uraiSheet($mentah[$nama], $nama)->lemparBilaGagal('berkas');
            /** @var array{baris: list<BarisSheet>, baris_tanpa_nip: list<int>, kolom_diabaikan: list<string>} $nilai */
            $nilai                          = $urai->nilai;
            $sheet[$nama]                   = $nilai['baris'];
            $info['baris_tanpa_nip'][$nama] = $nilai['baris_tanpa_nip'];
            $info['kolom_diabaikan'][$nama] = $nilai['kolom_diabaikan'];
        }

        $semua                = [...$sheet[KolomGaji::SHEET_GPP], ...$sheet[KolomGaji::SHEET_TK]];
        $nip                  = array_values(array_unique(array_filter(array_map(static fn (BarisSheet $b): ?string => $b->nip, $semua), static fn (?string $n): bool => $n !== null)));
        [$pegawai, $bergelar] = $this->pegawai($nip);

        $susun = PratinjauImpor::susun($sheet[KolomGaji::SHEET_GPP], $sheet[KolomGaji::SHEET_TK], $pegawai, $kunciSudahAda($nip), $filter, $cakupan);

        if (! $susun->valid) {
            throw (new ValidationException((string) $susun->pesan, ['filter_bulan' => [(string) $susun->pesan]]))->denganKode((string) $susun->kode);
        }

        $periodeBaris = [];

        foreach ($semua as $b) {
            if ($b->periode !== null) {
                $periodeBaris[$b->sheet . '#' . $b->nomorBaris] = $b->periode;
            }
        }

        /** @var array<string, mixed> $nilaiSusun */
        $nilaiSusun = $susun->nilai;

        return ['susun' => $nilaiSusun, 'nama' => $bergelar, 'info' => $info, 'periodeBaris' => $periodeBaris];
    }

    /**
     * Data pegawai untuk `PratinjauImpor::susun` (nama tanpa gelar, `id_satker` jabatan saat ini dari
     * `pegawai_mutasi_jabatan`) dan nama bergelar untuk tampilan/audit.
     *
     * @param list<string> $nip
     *
     * @return array{0: array<string, array{nama: string, id_satker: ?string}>, 1: array<string, string>}
     */
    private function pegawai(array $nip): array
    {
        if ($nip === []) {
            return [[], []];
        }

        $rows = $this->koneksi()->table('pegawai p')
            ->select('p.nip, p.nama, p.glr_awal, p.glr_akhir, pmj.id_satker')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'left')
            ->whereIn('p.nip', $nip)
            ->get()->getResultArray();

        $data     = [];
        $bergelar = [];

        foreach ($rows as $row) {
            $kunci            = (string) $row['nip'];
            $data[$kunci]     = ['nama' => (string) $row['nama'], 'id_satker' => $row['id_satker'] === null ? null : (string) $row['id_satker']];
            $bergelar[$kunci] = PencocokanNama::denganGelar($row['glr_awal'] ?? null, (string) $row['nama'], $row['glr_akhir'] ?? null);
        }

        return [$data, $bergelar];
    }

    /**
     * Kunci `(nip, bulan, tahun)` yang sudah ada untuk NIP di berkas; `$kunci = true` → baris dikunci `FOR UPDATE`.
     *
     * @param list<string> $nip
     *
     * @return array<string, true>
     */
    private function kunciSudahAda(array $nip, bool $kunci = false): array
    {
        if ($nip === []) {
            return [];
        }

        $builder = $this->koneksi()->table('gaji_pegawai')->select('nip, bulan, tahun')->whereIn('nip', $nip);
        $sql     = $builder->getCompiledSelect() . ($kunci ? ' FOR UPDATE' : '');
        $ada     = [];

        foreach ($this->koneksi()->query($sql)->getResultArray() as $row) {
            $ada[PratinjauImpor::kunci((string) $row['nip'], PeriodeSlip::buat((int) $row['tahun'], (int) $row['bulan']))] = true;
        }

        return $ada;
    }

    /**
     * Satu baris audit `warning` per kunci bernama tidak cocok (F5-4; ditulis sekali saat pratinjau, tidak saat commit).
     *
     * @param array{susun: array<string, mixed>, nama: array<string, string>, info: array<string, mixed>, periodeBaris: array<string, PeriodeSlip>} $analisis
     */
    private function auditNamaTidakCocok(AuthContext $auth, array $analisis, string $token): void
    {
        $sudah = [];

        /** @var list<array{nip: string, sheet: string, nomor_baris: int, nama_berkas: string, nama_db: string}> $daftar */
        $daftar = $analisis['susun']['nama_tidak_cocok'];

        if ($daftar === []) {
            return;
        }

        $db = $this->koneksi();
        $db->transBegin();

        try {
            $this->tulisAuditNama($auth, $analisis, $daftar, $token, $sudah);

            if ($db->transStatus() === false) {
                throw new RuntimeException('Audit ketidakcocokan nama impor slip gagal ditulis.');
            }

            $db->transCommit();
        } catch (Throwable $e) {
            $this->rollback($db);

            throw $e;
        }
    }

    /**
     * @param array{susun: array<string, mixed>, nama: array<string, string>, info: array<string, mixed>, periodeBaris: array<string, PeriodeSlip>} $analisis
     * @param list<array{nip: string, sheet: string, nomor_baris: int, nama_berkas: string, nama_db: string}>                                         $daftar
     * @param array<string, true>                                                                                                                       $sudah
     */
    private function tulisAuditNama(AuthContext $auth, array $analisis, array $daftar, string $token, array &$sudah): void
    {
        foreach ($daftar as $m) {
            $periode = $analisis['periodeBaris'][$m['sheet'] . '#' . $m['nomor_baris']] ?? null;
            $kunci   = $m['nip'] . ':' . ($periode?->kunci() ?? '-');

            if (isset($sudah[$kunci])) {
                continue;
            }

            $sudah[$kunci] = true;
            $this->audit($auth, $kunci, 'warning', [
                'nip'         => $m['nip'],
                'periode'     => $periode?->kunci(),
                'sheet'       => $m['sheet'],
                'baris'       => $m['nomor_baris'],
                'nama_berkas' => $m['nama_berkas'],
                'nama_db'     => $analisis['nama'][$m['nip']] ?? $m['nama_db'],
                'pratinjau'   => substr(hash('sha256', $token), 0, 8),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $after
     */
    private function audit(AuthContext $auth, ?string $entityId, string $event, array $after, ?string $idCadangan = null): void
    {
        $id = (new AuditLogModel($this->koneksi()))->record(self::ENTITAS_AUDIT, $entityId ?? (string) $idCadangan, $event, null, $after, $auth->idPengguna(), $auth->nip());

        if ($id <= 0) {
            throw new RuntimeException("Audit {$event} impor slip gagal ditulis.");
        }
    }

    /**
     * @param array<string, mixed>  $b
     * @param array<string, string> $nama
     *
     * @return array<string, mixed>
     */
    private function barisPratinjau(array $b, array $nama): array
    {
        $nilai = [];

        foreach (KolomGaji::semuaField() as $field) {
            $nilai[$field] = NominalGaji::keDesimal((int) ($b['nominal'][$field] ?? 0));
        }

        return [
            'nomor'                    => $b['nomor_baris'],
            'sheet'                    => $b['sheet'],
            'nip'                      => $b['nip'],
            'nama'                     => $b['nip'] === null ? null : ($nama[$b['nip']] ?? null),
            'periode'                  => $b['periode'] instanceof PeriodeSlip ? $b['periode']->kunci() : null,
            'status'                   => $b['status'],
            'rencana_bila_timpa'       => PratinjauImpor::rencanaCommit($b['status'], true),
            'rencana_bila_tidak_timpa' => PratinjauImpor::rencanaCommit($b['status'], false),
            'galat'                    => $this->galatBaris($b),
            'info'                     => [],
            'nilai'                    => $nilai,
        ];
    }

    /**
     * @param array<string, mixed> $b
     *
     * @return list<array{kode: string, pesan: string, sheet: string, baris: int}>
     */
    private function galatBaris(array $b): array
    {
        /** @var list<array{kode: string, pesan: string}> $galat */
        $galat = $b['galat'];

        return array_map(static fn (array $g): array => [...$g, 'sheet' => (string) $b['sheet'], 'baris' => (int) $b['nomor_baris']], $galat);
    }

    /**
     * Cakupan satker `PratinjauImpor::susun`: role 1 → null (semua); role 3 → satker akun (tanpa satker → 403, SG-2);
     * role lain → 403 (route sudah menolak; lapis kedua).
     */
    private function cakupan(AuthContext $auth): ?string
    {
        $role = $auth->role();

        if ($role === Role::SUPER_ADMIN) {
            return null;
        }

        $satker = $auth->idSatker();

        if ($role === Role::ADMIN_SATKER && $satker !== null && $satker !== '') {
            return $satker;
        }

        throw new ForbiddenException('Anda tidak berhak mengimpor slip gaji.');
    }

    private function pesanUkuran(): string
    {
        $byte = $this->config->maksUkuranImporByte;
        $mb   = $byte % (1024 * 1024) === 0 ? (string) intdiv($byte, 1024 * 1024) : number_format($byte / (1024 * 1024), 1, ',', '');

        return "Ukuran berkas maksimal {$mb} MB.";
    }

    private function rollback(BaseConnection $db): void
    {
        $db->transRollback();
        $db->resetTransStatus();
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
