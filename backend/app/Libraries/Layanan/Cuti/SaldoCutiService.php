<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeProsesInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\ListQuery;
use App\Models\AuditLogModel;
use App\Models\Layanan\PegawaiSisaCutiModel;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Kelola saldo cuti tahunan (`pegawai_sisa_cuti`, PRD Fase 4 §6.3.6, legacy `sc/add_sc/edit_sc/delete_sc`). Role route 1
 * dan 3 (role 3 dibatasi lingkup lewat {@see PegawaiScopeInterface}); role 4/5 → 403 (D5). Input mengikuti batas D8
 * ({@see SaldoCuti::periksaBatas()}), hapus fisik + audit. Tambah target `1` (semua pegawai aktif jenis 1/6 dalam lingkup
 * pemanggil yang belum punya baris tahun itu; satu baris audit ringkasan `jenis_audit = cuti.saldo_massal`) atau `2`
 * (satu pegawai; sudah punya baris → 422 `errors.nip`).
 */
class SaldoCutiService
{
    public const TARGET_SEMUA = 1;

    public const TARGET_PILIH = 2;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AturanCuti $aturan,
        private readonly PegawaiScopeInterface&PegawaiScopeProsesInterface $scope,
        private readonly ?\Closure $sekarang = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function daftar(AuthContext $auth, array $query): array
    {
        $this->wajibRole($auth);

        $q     = ListQuery::from($query, ['tahun', 'search', 'id_unit', 'id_satker', 'page', 'per_page']);
        $ini   = $this->tahunIni();
        $tahun = $q->filled('tahun') ? (ctype_digit($q->string('tahun')) ? (int) $q->string('tahun') : throw ValidationException::forField('tahun', 'Tahun tidak valid.')) : $ini;

        if ($tahun > $ini) {
            throw ValidationException::forField('tahun', 'Tahun tidak boleh melebihi tahun ini.');
        }

        $bangun = function () use ($q, $auth, $tahun): \CodeIgniter\Database\BaseBuilder {
            $b = $this->db->table('pegawai_sisa_cuti psc')
                ->join('pegawai p', 'p.nip = psc.nip', 'inner')
                ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = psc.nip', 'left')
                ->where('psc.tahun', $tahun);
            $this->scope->terapkanKeQuery($b, $auth, 'psc.nip');

            if ($q->filled('search')) {
                $like = ListQuery::likeLiteral($this->db, $q->string('search'));
                $b->groupStart()->like('p.nama', $like, 'both', null, true)->orLike('psc.nip', $like, 'both', null, true)->groupEnd();
            }

            foreach (['id_unit', 'id_satker'] as $f) {
                if ($q->filled($f) && ctype_digit($q->string($f))) {
                    $b->where("pmj.{$f}", (int) $q->string($f));
                }
            }

            return $b;
        };

        $total   = $bangun()->countAllResults();
        $page    = $q->page();
        $perPage = $q->perPage(25, 100);
        $items   = $bangun()
            ->select('psc.id_pegawai_sisa_cuti, psc.nip, p.nama, psc.tahun, psc.sisa_cuti, psc.sisa_cuti_n1, psc.sisa_cuti_n2, psc.sisa_cuti_ditangguhkan, psc.utang_cuti, pmj.jabatan, pmj.satker')
            ->orderBy('p.nama', 'ASC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'tahun' => $tahun];
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    public function tambah(AuthContext $auth, array $in): array
    {
        $this->wajibRole($auth);

        $errors = [];
        $tahun  = $this->tahunInput($in['tahun'] ?? null, $errors);
        $saldo  = $this->saldoInput($in, $errors, true);
        $target = isset($in['target']) && (string) $in['target'] === '1' ? self::TARGET_SEMUA : (isset($in['target']) && (string) $in['target'] === '2' ? self::TARGET_PILIH : null);

        if ($target === null) {
            $errors['target'] = 'Silahkan pilih target (semua pegawai atau pilih pegawai).';
        }

        $nip = null;

        if ($target === self::TARGET_PILIH) {
            $nip = isset($in['nip']) && is_string($in['nip']) ? trim($in['nip']) : '';

            if ($nip === '') {
                $errors['nip'] = 'Silahkan pilih pegawai.';
            } elseif (! $this->pegawaiBolehDikelola($auth, $nip)) {
                $errors['nip'] = 'Pegawai tidak ditemukan atau di luar lingkup Anda.';
            } elseif ($tahun !== null && $this->db->table('pegawai_sisa_cuti')->where('nip', $nip)->where('tahun', $tahun)->countAllResults() > 0) {
                $errors['nip'] = 'Pegawai sudah memiliki saldo cuti untuk tahun ini.';
            }
        }

        if ($errors !== [] || $tahun === null || $saldo === null) {
            throw new ValidationException('Validasi gagal.', array_map(static fn (string $p): array => [$p], $errors));
        }

        if ($target === self::TARGET_PILIH) {
            /** @var string $nip */
            $id = (new PegawaiSisaCutiModel())->insert(['nip' => $nip, 'tahun' => $tahun, 'digunakan' => 0, 'updated_by' => $auth->idPengguna() ?? 0] + $saldo->keBaris());

            $p = $this->db->table('pegawai')->select('glr_awal, nama, glr_akhir')->where('nip', $nip)->get()->getRowArray();

            return $this->hasil($tahun, self::TARGET_PILIH, 1, [['nip' => $nip, 'nama' => StrukturService::namaGelar($p['glr_awal'] ?? null, (string) ($p['nama'] ?? ''), $p['glr_akhir'] ?? null)]], [])
                + ['item' => $this->ambil((int) $id)];
        }

        return $this->tambahSemua($auth, $tahun, $saldo);
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, int $id, array $in): array
    {
        $this->wajibRole($auth);

        $baris  = $this->barisBolehKelola($auth, $id);
        $errors = [];
        $saldo  = $this->saldoInput($in, $errors, true);

        if ($errors !== [] || $saldo === null) {
            throw new ValidationException('Validasi gagal.', array_map(static fn (string $p): array => [$p], $errors));
        }

        (new PegawaiSisaCutiModel())->update((int) $baris['id_pegawai_sisa_cuti'], $saldo->keBaris() + ['updated_by' => $auth->idPengguna() ?? 0]);

        return $this->ambil($id);
    }

    /**
     * @return array{deleted: bool, id: int}
     */
    public function hapus(AuthContext $auth, int $id): array
    {
        $this->wajibRole($auth);
        $this->barisBolehKelola($auth, $id);

        (new PegawaiSisaCutiModel())->delete($id);

        return ['deleted' => true, 'id' => $id];
    }

    // ------------------------------------------------------------------

    public const ALASAN_SUDAH_PUNYA = 'sudah_punya_saldo';

    /**
     * Target 1: pegawai aktif jenis 1/6 dalam lingkup pemanggil. Yang sudah punya baris tahun itu DILEWATI (alasan
     * `sudah_punya_saldo`), yang belum DIBUATKAN. Populasi di luar lingkup atau bukan jenis 1/6 tidak ikut dihitung.
     *
     * @return array<string, mixed>
     */
    private function tambahSemua(AuthContext $auth, int $tahun, SaldoCuti $saldo): array
    {
        $b = $this->db->table('pegawai p')->select('p.nip, p.nama, p.glr_awal, p.glr_akhir')->where('p.status', 1)->whereIn('p.id_jenis_pegawai', SaldoTahunanService::JENIS_PEGAWAI);
        // Jalur PROSES (sama dengan tambah tunggal/ubah/hapus): saldo yang dibuat massal tetap bisa diubah/dihapus pembuatnya.
        $this->scope->terapkanKeQueryProses($b, $auth, 'p.nip');
        $populasi = $b->orderBy('p.nama', 'ASC')->get()->getResultArray();

        $ada = array_flip(array_map('strval', array_column(
            $this->db->table('pegawai_sisa_cuti')->select('nip')->where('tahun', $tahun)->get()->getResultArray(),
            'nip',
        )));

        $baru     = [];
        $dibuat   = [];
        $dilewati = [];
        $now      = date('Y-m-d H:i:s');

        foreach ($populasi as $p) {
            $nip  = (string) $p['nip'];
            $nama = StrukturService::namaGelar($p['glr_awal'], (string) $p['nama'], $p['glr_akhir']);

            if (isset($ada[$nip])) {
                $dilewati[] = ['nip' => $nip, 'nama' => $nama, 'alasan_kode' => self::ALASAN_SUDAH_PUNYA, 'alasan' => "Pegawai sudah memiliki saldo cuti tahun {$tahun}."];

                continue;
            }

            $dibuat[] = ['nip' => $nip, 'nama' => $nama];
            $baru[]   = ['nip' => $nip, 'tahun' => $tahun, 'digunakan' => 0, 'created_at' => $now, 'updated_by' => $auth->idPengguna() ?? 0] + $saldo->keBaris();
        }

        $hasil = $this->hasil($tahun, self::TARGET_SEMUA, count($populasi), $dibuat, $dilewati);

        if ($baru === []) {
            return $hasil;
        }

        $this->db->transBegin();

        try {
            foreach (array_chunk($baru, 500) as $potong) {
                $this->db->table('pegawai_sisa_cuti')->insertBatch($potong);
            }

            (new AuditLogModel())->record('pegawai_sisa_cuti', (string) $tahun, AuditLogModel::EVENT_CREATE, null, [
                'jenis_audit'  => 'cuti.saldo_massal',
                'saldo'        => $saldo->keBaris(),
                'ringkasan'    => $hasil['ringkasan'],
                'nip_dibuat'   => array_column($dibuat, 'nip'),
                'nip_dilewati' => array_column($dilewati, 'nip'),
            ], $auth->idPengguna(), $auth->nip());

            if (! $this->db->transStatus()) {
                throw new \RuntimeException('Penambahan saldo massal gagal.');
            }

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        }

        return $hasil;
    }

    /**
     * Bentuk respons tambah saldo (kedua target): ringkasan angka, daftar dibuat, daftar dilewati beserta alasan.
     *
     * @param list<array{nip: string, nama: string}>                                                   $dibuat
     * @param list<array{nip: string, nama: string, alasan_kode: string, alasan: string}> $dilewati
     *
     * @return array<string, mixed>
     */
    private function hasil(int $tahun, int $target, int $populasi, array $dibuat, array $dilewati): array
    {
        $jumlahDibuat   = count($dibuat);
        $jumlahDilewati = count($dilewati);

        $pesan = $jumlahDibuat === 0
            ? ($jumlahDilewati > 0
                ? "Tidak ada saldo cuti tahun {$tahun} yang dibuat; {$jumlahDilewati} pegawai dilewati karena sudah memiliki saldo."
                : "Tidak ada pegawai yang memenuhi syarat untuk dibuatkan saldo cuti tahun {$tahun}.")
            : "Saldo cuti tahun {$tahun} dibuat untuk {$jumlahDibuat} pegawai"
                . ($jumlahDilewati > 0 ? "; {$jumlahDilewati} pegawai dilewati karena sudah memiliki saldo." : '.');

        return [
            'message'   => $pesan,
            'ringkasan' => ['tahun' => $tahun, 'target' => $target, 'jumlah_populasi' => $populasi, 'jumlah_dibuat' => $jumlahDibuat, 'jumlah_dilewati' => $jumlahDilewati],
            'dibuat'    => $dibuat,
            'dilewati'  => $dilewati,
        ];
    }

    /**
     * Nilai saldo dari input (semua kolom wajib kecuali `sisa_cuti_ditangguhkan`), batas D8 per kolom.
     *
     * @param array<string, mixed>  $in
     * @param array<string, string> $errors
     */
    private function saldoInput(array $in, array &$errors, bool $wajib): ?SaldoCuti
    {
        $nilai = [];

        foreach (SaldoCuti::KOLOM as $prop => $kolom) {
            $v = $in[$kolom] ?? null;

            if ($v === null || $v === '') {
                if ($wajib && $kolom !== 'sisa_cuti_ditangguhkan') {
                    $errors[$kolom] = 'Wajib diisi.';
                }

                $nilai[$prop] = 0;

                continue;
            }

            if (! (is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v) === 1))) {
                $errors[$kolom] = 'Harus berupa bilangan bulat.';
                $nilai[$prop]   = 0;

                continue;
            }

            $nilai[$prop] = (int) $v;
        }

        $saldo = new SaldoCuti($nilai['n'], $nilai['n1'], $nilai['n2'], $nilai['utang'], $nilai['ditangguhkan']);

        foreach ($saldo->periksaBatas($this->aturan) as $kolom => $pesan) {
            $errors[$kolom] ??= $pesan;
        }

        return $errors === [] ? $saldo : null;
    }

    /**
     * @param array<string, string> $errors
     */
    private function tahunInput(mixed $v, array &$errors): ?int
    {
        if (! (is_int($v) || (is_string($v) && ctype_digit($v)))) {
            $errors['tahun'] = 'Tahun wajib diisi.';

            return null;
        }

        $tahun = (int) $v;

        if ($tahun < 2000 || $tahun > $this->tahunIni()) {
            $errors['tahun'] = 'Tahun tidak boleh melebihi tahun ini.';

            return null;
        }

        return $tahun;
    }

    private function tahunIni(): int
    {
        return (int) (($this->sekarang !== null ? ($this->sekarang)() : new \DateTimeImmutable('now', new \DateTimeZone(\App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis::ZONA)))->format('Y'));
    }

    private function pegawaiBolehDikelola(AuthContext $auth, string $nip): bool
    {
        $p = $this->db->table('pegawai')->select('status, id_jenis_pegawai')->where('nip', $nip)->get()->getRowArray();

        if ($p === null || (int) $p['status'] !== 1 || ! in_array((int) $p['id_jenis_pegawai'], SaldoTahunanService::JENIS_PEGAWAI, true)) {
            return false;
        }

        return (int) $auth->role() === Role::SUPER_ADMIN || $this->scope->bolehProses($auth, $nip);
    }

    /**
     * @return array<string, mixed>
     */
    private function barisBolehKelola(AuthContext $auth, int $id): array
    {
        $baris = $this->db->table('pegawai_sisa_cuti')->where('id_pegawai_sisa_cuti', $id)->get()->getRowArray() ?? throw new NotFoundException();

        if ((int) $auth->role() !== Role::SUPER_ADMIN && ! $this->scope->bolehProses($auth, (string) $baris['nip'])) {
            throw new NotFoundException();
        }

        return $baris;
    }

    /**
     * @return array<string, mixed>
     */
    private function ambil(int $id): array
    {
        return $this->db->table('pegawai_sisa_cuti')->where('id_pegawai_sisa_cuti', $id)->get()->getRowArray() ?? throw new NotFoundException();
    }

    private function wajibRole(AuthContext $auth): void
    {
        if (! in_array((int) $auth->role(), [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            throw new ForbiddenException();
        }
    }
}
