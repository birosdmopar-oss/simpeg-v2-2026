<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\StatusHarian;

use App\Libraries\Layanan\Cuti\AturanCuti;
use App\Libraries\Layanan\Cuti\StatusCuti;
use App\Libraries\Layanan\Studi\StatusStudi;
use App\Models\AuditLogModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * MAKE-062 (lapisan DB) — status harian TB/CLTN (`layanan:status-harian`, PRD Fase 6 §7.9, F6-20, CR-098). Keputusan per
 * pegawai ada di {@see PenentuStatusHarian} (murni); kelas ini memuat populasi, menerapkan hasilnya, dan menghitung INFO.
 *
 * Populasi = pegawai berstatus 2/3 (kandidat pemulihan) ditambah pegawai yang punya TB (`riwayat_tb` status 1, induk atau
 * perpanjangan) atau CLTN (`riwayat_cuti` `id_jenis_cuti = 6` status 1) yang memuat tanggal proses. Pegawai `status ≠ 1`
 * (tidak aktif/dihapus) dilewati dan dihitung INFO; `id_jenis_status` selain 1/2/3 (termasuk NULL) tidak disentuh.
 *
 * Penulisan (BP-6, BP-7): transaksi kecil per pegawai pada SATU koneksi — kunci baris pegawai dan riwayat TB/CLTN-nya
 * (`FOR UPDATE`), muat ulang riwayat dan hitung ulang keputusan di dalam transaksi (admin yang mengubah/menghapus riwayat
 * sesudah pemuatan awal tidak menimbulkan status basi), update, lalu `audit_logs` pada koneksi yang sama sehingga update dan
 * audit atomik. Kegagalan satu pegawai dihitung dan dicatat (`log_message('error')`: cabang, kelas, kode — tanpa pesan DB
 * dan tanpa NIP) dan tidak menghentikan yang lain. Run nyata dilindungi `GET_LOCK` (tunggu 0); dry-run tidak mengunci dan
 * tidak menulis. Keluaran hanya jumlah — tanpa NIP/nama.
 *
 * Tulis memakai query builder + `audit_logs` manual (bukan event `PegawaiModel`), sama dengan `EfekFinalCutiService`; pelanggan
 * event perubahan `pegawai` (mis. `change_history` A5) tidak ikut terpicu oleh job ini.
 */
final class StatusHarianService
{
    /** Jumlah NIP per kueri WHERE IN. */
    public const BATCH = 500;

    /** Penanda `audit_logs.after.jenis_audit` (pola `EfekFinalCutiService`). */
    public const JENIS_AUDIT = 'layanan.status_harian';

    /** `pegawai.status` aktif [K]. */
    private const PEGAWAI_AKTIF = 1;

    /**
     * @param (Closure(BaseConnection): AuditLogModel)|null $pabrikAudit    pembuat model audit pada koneksi yang diberikan
     *                                                                      (bawaan `new AuditLogModel($db)`); hanya untuk test
     * @param (Closure(string): void)|null                  $sebelumTerapkan dipanggil sebelum transaksi tiap pegawai (hanya test)
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly ?Closure $pabrikAudit = null,
        private readonly ?Closure $sebelumTerapkan = null,
    ) {
    }

    public static function namaKunci(BaseConnection $db): string
    {
        return 'layanan_status_harian:' . substr(sha1($db->getDatabase() . '|' . $db->getPrefix()), 0, 16);
    }

    /**
     * @param int|null $idPelaku wajib untuk run nyata (id akun sistem); dry-run boleh null
     *
     * @return array{tanggal: string, dry_run: bool, diperiksa: int, berubah: int, tb_aktif: int, cltn_aktif: int, pulih_tb: int, pulih_cltn: int, gagal: int, info: array{bentrok: int, celah_tb: int, tanpa_riwayat: int, segmen_tidak_sah: int, dilewati_nonaktif: int, dilewati_status_lain: int, berubah_saat_proses: int}}
     *
     * @throws StatusHarianSibuk run nyata lain sedang berjalan
     */
    public function jalankan(string $tanggal, bool $dryRun, ?int $idPelaku): array
    {
        if (! $dryRun && $idPelaku === null) {
            throw new RuntimeException('Run nyata status harian wajib memakai pelaku akun sistem.');
        }

        if ($dryRun) {
            return $this->proses($tanggal, true, null);
        }

        $kunci = self::namaKunci($this->db);
        $dapat = $this->db->query('SELECT GET_LOCK(?, 0) AS dapat', [$kunci])->getRowArray();

        if ((string) ($dapat['dapat'] ?? '') !== '1') {
            throw new StatusHarianSibuk('Status harian sedang berjalan di proses lain.');
        }

        try {
            return $this->proses($tanggal, false, $idPelaku);
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$kunci]);
        }
    }

    /**
     * @return array{tanggal: string, dry_run: bool, diperiksa: int, berubah: int, tb_aktif: int, cltn_aktif: int, pulih_tb: int, pulih_cltn: int, gagal: int, info: array{bentrok: int, celah_tb: int, tanpa_riwayat: int, segmen_tidak_sah: int, dilewati_nonaktif: int, dilewati_status_lain: int, berubah_saat_proses: int}}
     */
    private function proses(string $tanggal, bool $dryRun, ?int $idPelaku): array
    {
        $hasil = [
            'tanggal'    => $tanggal,
            'dry_run'    => $dryRun,
            'diperiksa'  => 0,
            'berubah'    => 0,
            'tb_aktif'   => 0,
            'cltn_aktif' => 0,
            'pulih_tb'   => 0,
            'pulih_cltn' => 0,
            'gagal'      => 0,
            'info'       => ['bentrok' => 0, 'celah_tb' => 0, 'tanpa_riwayat' => 0, 'segmen_tidak_sah' => 0, 'dilewati_nonaktif' => 0, 'dilewati_status_lain' => 0, 'berubah_saat_proses' => 0],
        ];

        foreach (array_chunk($this->populasi($tanggal), self::BATCH) as $potongan) {
            $pegawai = $this->pegawai($potongan);
            $tb      = $this->daftarTb($potongan);
            $cltn    = $this->daftarCltn($potongan);

            foreach ($potongan as $nip) {
                $baris = $pegawai[$nip] ?? null;

                if ($baris === null) {
                    continue;
                }

                $hasil['diperiksa']++;

                if ((int) $baris['status'] !== self::PEGAWAI_AKTIF) {
                    $hasil['info']['dilewati_nonaktif']++;

                    continue;
                }

                $sebelum = $baris['id_jenis_status'] === null ? null : (int) $baris['id_jenis_status'];

                if (! in_array($sebelum, [PenentuStatusHarian::AKTIF, PenentuStatusHarian::TB, PenentuStatusHarian::CLTN], true)) {
                    $hasil['info']['dilewati_status_lain']++;

                    continue;
                }

                $k = PenentuStatusHarian::tentukan((int) $sebelum, $tb[$nip] ?? [], $cltn[$nip] ?? [], $tanggal);

                $hasil['info']['bentrok'] += $k->bentrok ? 1 : 0;
                $hasil['info']['celah_tb'] += $k->celahTb ? 1 : 0;
                $hasil['info']['tanpa_riwayat'] += $k->tanpaRiwayat ? 1 : 0;
                $hasil['info']['segmen_tidak_sah'] += $k->segmenTidakSah ? 1 : 0;

                if (! $k->berubah()) {
                    continue;
                }

                if (! $dryRun) {
                    $akhir = $this->terapkan($nip, (int) $sebelum, $k, $tanggal, (int) $idPelaku);

                    if ($akhir === 'gagal') {
                        $hasil['gagal']++;

                        continue;
                    }

                    if ($akhir === 'berubah_saat_proses') {
                        $hasil['info']['berubah_saat_proses']++;

                        continue;
                    }
                }

                $hasil['berubah']++;
                $hasil[(string) $k->alasan]++;
            }
        }

        return $hasil;
    }

    /**
     * NIP kandidat: pegawai 2/3, atau punya TB/CLTN yang memuat tanggal.
     *
     * @return list<string>
     */
    private function populasi(string $tanggal): array
    {
        $nip = [];

        $kumpulkan = static function (array $rows) use (&$nip): void {
            foreach ($rows as $r) {
                $nip[(string) $r['nip']] = true;
            }
        };

        $kumpulkan($this->db->table('pegawai')->select('nip')->whereIn('id_jenis_status', [PenentuStatusHarian::TB, PenentuStatusHarian::CLTN])->get()->getResultArray());
        $kumpulkan($this->db->table('riwayat_tb')->select('nip')->where('status', StatusStudi::DISETUJUI)
            ->where('tgl_mulai <=', $tanggal)->where('tgl_akhir >=', $tanggal)->get()->getResultArray());
        $kumpulkan($this->db->table('riwayat_tb t')->select('t.nip')->join('riwayat_tb_perpan p', 'p.id_riwayat_tb = t.id_riwayat_tb')
            ->where('t.status', StatusStudi::DISETUJUI)->where('p.tgl_mulai_perpan <=', $tanggal)->where('p.tgl_akhir_perpan >=', $tanggal)->get()->getResultArray());
        $kumpulkan($this->db->table('riwayat_cuti')->select('nip')->where('id_jenis_cuti', AturanCuti::JENIS_CLTN)->where('status', StatusCuti::DISETUJUI)
            ->where('tgl_mulai <=', $tanggal)->where('tgl_akhir >=', $tanggal)->get()->getResultArray());

        $daftar = array_map('strval', array_keys($nip));
        sort($daftar, SORT_STRING);

        return $daftar;
    }

    /**
     * @param list<string> $nip
     *
     * @return array<string, array{status: mixed, id_jenis_status: mixed}>
     */
    private function pegawai(array $nip): array
    {
        $hasil = [];

        foreach ($this->db->table('pegawai')->select('nip, status, id_jenis_status')->whereIn('nip', $nip)->get()->getResultArray() as $r) {
            $hasil[(string) $r['nip']] = $r;
        }

        return $hasil;
    }

    /**
     * TB status 1 (dengan perpanjangan) per NIP — bentuk masukan {@see PenentuStatusHarian}.
     *
     * @param list<string> $nip
     *
     * @return array<string, list<array{id: int, status: int, tgl_mulai: string, tgl_akhir: string, perpanjangan: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}>>
     */
    private function daftarTb(array $nip): array
    {
        $tb = $this->db->table('riwayat_tb')->select('id_riwayat_tb, nip, status, tgl_mulai, tgl_akhir')
            ->whereIn('nip', $nip)->where('status', StatusStudi::DISETUJUI)->orderBy('id_riwayat_tb')->get()->getResultArray();

        if ($tb === []) {
            return [];
        }

        $perpan = [];

        foreach (array_chunk(array_map(static fn (array $r): int => (int) $r['id_riwayat_tb'], $tb), self::BATCH) as $id) {
            $rows = $this->db->table('riwayat_tb_perpan')->select('id_riwayat_tb, tgl_mulai_perpan, tgl_akhir_perpan')
                ->whereIn('id_riwayat_tb', $id)->where('tgl_mulai_perpan IS NOT NULL')->where('tgl_akhir_perpan IS NOT NULL')->get()->getResultArray();

            foreach ($rows as $r) {
                $perpan[(int) $r['id_riwayat_tb']][] = ['tgl_mulai_perpan' => (string) $r['tgl_mulai_perpan'], 'tgl_akhir_perpan' => (string) $r['tgl_akhir_perpan']];
            }
        }

        $hasil = [];

        foreach ($tb as $r) {
            $hasil[(string) $r['nip']][] = [
                'id'           => (int) $r['id_riwayat_tb'],
                'status'       => (int) $r['status'],
                'tgl_mulai'    => (string) $r['tgl_mulai'],
                'tgl_akhir'    => (string) $r['tgl_akhir'],
                'perpanjangan' => $perpan[(int) $r['id_riwayat_tb']] ?? [],
            ];
        }

        return $hasil;
    }

    /**
     * CLTN (`riwayat_cuti` jenis 6) status 1 per NIP.
     *
     * @param list<string> $nip
     *
     * @return array<string, list<array{id: int, status: int, tgl_mulai: string, tgl_akhir: string}>>
     */
    private function daftarCltn(array $nip): array
    {
        $hasil = [];
        $rows  = $this->db->table('riwayat_cuti')->select('id_riwayat_cuti, nip, status, tgl_mulai, tgl_akhir')
            ->whereIn('nip', $nip)->where('id_jenis_cuti', AturanCuti::JENIS_CLTN)->where('status', StatusCuti::DISETUJUI)->orderBy('id_riwayat_cuti')->get()->getResultArray();

        foreach ($rows as $r) {
            $hasil[(string) $r['nip']][] = ['id' => (int) $r['id_riwayat_cuti'], 'status' => (int) $r['status'], 'tgl_mulai' => (string) $r['tgl_mulai'], 'tgl_akhir' => (string) $r['tgl_akhir']];
        }

        return $hasil;
    }

    private function modelAudit(): AuditLogModel
    {
        return $this->pabrikAudit !== null ? ($this->pabrikAudit)($this->db) : new AuditLogModel($this->db);
    }

    /**
     * Satu pegawai = satu transaksi pada koneksi layanan: kunci baris pegawai dan riwayatnya, muat ulang riwayat, hitung
     * ulang keputusan, update, audit (koneksi yang sama).
     *
     * @return 'ok'|'berubah_saat_proses'|'gagal'
     */
    private function terapkan(string $nip, int $sebelum, KeputusanStatusHarian $rencana, string $tanggal, int $idPelaku): string
    {
        if ($this->sebelumTerapkan !== null) {
            ($this->sebelumTerapkan)($nip);
        }

        if ($this->db->transBegin() === false) {
            $this->db->resetTransStatus();
            $this->catatGagal('mulai transaksi', null);

            return 'gagal';
        }

        try {
            $baris = $this->kunciSatu($this->db->table('pegawai')->select('status, id_jenis_status')->where('nip', $nip));

            if ($baris === null || (int) $baris['status'] !== self::PEGAWAI_AKTIF || $baris['id_jenis_status'] === null || (int) $baris['id_jenis_status'] !== $sebelum) {
                return $this->batal('berubah_saat_proses');
            }

            // Kunci riwayat (admin yang menghapus/mengubah riwayat menunggu), lalu muat ulang dan hitung ulang.
            $this->db->query($this->db->table('riwayat_tb')->select('id_riwayat_tb')->where('nip', $nip)->where('status', StatusStudi::DISETUJUI)->getCompiledSelect() . ' FOR UPDATE');
            $this->db->query($this->db->table('riwayat_cuti')->select('id_riwayat_cuti')->where('nip', $nip)->where('id_jenis_cuti', AturanCuti::JENIS_CLTN)->where('status', StatusCuti::DISETUJUI)->getCompiledSelect() . ' FOR UPDATE');

            $k = PenentuStatusHarian::tentukan($sebelum, $this->daftarTb([$nip])[$nip] ?? [], $this->daftarCltn([$nip])[$nip] ?? [], $tanggal);

            if ($k->statusBaru !== $rencana->statusBaru) {
                return $this->batal('berubah_saat_proses');
            }

            $this->db->table('pegawai')->where('nip', $nip)->update([
                'id_jenis_status' => $k->statusBaru,
                'updated_at'      => gmdate('Y-m-d H:i:s'),
                'updated_by'      => $idPelaku,
            ]);

            $idAudit = $this->modelAudit()->record(
                'pegawai',
                $nip,
                AuditLogModel::EVENT_UPDATE,
                ['id_jenis_status' => $sebelum],
                array_filter(
                    ['id_jenis_status' => $k->statusBaru, 'jenis_audit' => self::JENIS_AUDIT, 'alasan' => $k->alasan, 'jenis_riwayat' => $k->jenisRiwayat, 'id_riwayat' => $k->idRiwayat],
                    static fn ($nilai): bool => $nilai !== null,
                ),
                $idPelaku,
                null,
            );

            if ($idAudit <= 0 || $this->db->transStatus() === false) {
                $this->catatGagal('tulis', null);

                return $this->batal('gagal');
            }

            $this->db->transCommit();
            $this->db->resetTransStatus();

            return 'ok';
        } catch (Throwable $e) {
            $this->catatGagal('tulis', $e);

            return $this->batal('gagal');
        }
    }

    /**
     * @param 'berubah_saat_proses'|'gagal' $hasil
     *
     * @return 'berubah_saat_proses'|'gagal'
     */
    private function batal(string $hasil): string
    {
        $this->db->transRollback();
        $this->db->resetTransStatus();

        return $hasil;
    }

    /**
     * Baris pertama dengan `FOR UPDATE` dari builder yang sudah disusun.
     *
     * @param \CodeIgniter\Database\BaseBuilder $builder
     *
     * @return array<string, mixed>|null
     */
    private function kunciSatu($builder): ?array
    {
        return $this->db->query($builder->limit(1)->getCompiledSelect() . ' FOR UPDATE')->getRowArray();
    }

    /**
     * Jejak kegagalan per pegawai: cabang, kelas pendek, dan kode saja — pesan galat DB bisa memuat NIP (pola SiasnTarik).
     */
    private function catatGagal(string $cabang, ?Throwable $e): void
    {
        log_message('error', sprintf(
            'layanan:status-harian gagal pada %s: %s kode %s.',
            $cabang,
            $e === null ? 'tanpa pengecualian' : (new ReflectionClass($e))->getShortName(),
            $e === null ? '-' : (string) $e->getCode(),
        ));
    }
}
