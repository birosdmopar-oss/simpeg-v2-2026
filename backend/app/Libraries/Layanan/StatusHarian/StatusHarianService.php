<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\StatusHarian;

use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/**
 * MAKE-062 (lapisan DB) — status harian TB/CLTN (`layanan:status-harian`, PRD Fase 6 §7.9, F6-20, CR-098). Keputusan per
 * pegawai ada di {@see PenentuStatusHarian} (murni); kelas ini memuat populasi, menerapkan hasilnya, dan menghitung INFO.
 *
 * Populasi = pegawai berstatus 2/3 (kandidat pemulihan) ditambah pegawai yang punya TB (`riwayat_tb` status 1, induk atau
 * perpanjangan) atau CLTN (`riwayat_cuti` `id_jenis_cuti = 6` status 1) yang memuat tanggal proses. Pegawai `status ≠ 1`
 * (tidak aktif/dihapus) dilewati dan dihitung INFO; `id_jenis_status` selain 1/2/3 tidak disentuh.
 *
 * Penulisan (BP-6, BP-7): transaksi kecil per pegawai (kunci baris, cek status belum berubah, update, `audit_logs`);
 * kegagalan satu pegawai dihitung dan tidak menghentikan yang lain. Run nyata dilindungi `GET_LOCK` (tunggu 0); dry-run
 * tidak mengunci dan tidak menulis. Keluaran hanya jumlah — tanpa NIP/nama.
 */
final class StatusHarianService
{
    /** Jumlah NIP per kueri WHERE IN. */
    public const BATCH = 500;

    /** `pegawai.status` aktif [K]. */
    private const PEGAWAI_AKTIF = 1;

    private const JENIS_CUTI_CLTN = 6;

    private const STATUS_DISETUJUI = 1;

    /** Penanda `audit_logs.after.jenis_audit` (pola `EfekFinalCutiService`). */
    public const JENIS_AUDIT = 'layanan.status_harian';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditLogModel $audit,
    ) {
    }

    public static function namaKunci(BaseConnection $db): string
    {
        return 'layanan_status_harian:' . substr(sha1($db->getDatabase() . '|' . $db->getPrefix()), 0, 16);
    }

    /**
     * @param int|null $idPelaku wajib untuk run nyata (id akun sistem); dry-run boleh null
     *
     * @return array{tanggal: string, dry_run: bool, diperiksa: int, berubah: int, tb_aktif: int, cltn_aktif: int, pulih_tb: int, pulih_cltn: int, gagal: int, info: array{bentrok: int, celah_tb: int, tanpa_riwayat: int, dilewati_nonaktif: int, dilewati_status_lain: int, berubah_saat_proses: int}}
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
     * @return array{tanggal: string, dry_run: bool, diperiksa: int, berubah: int, tb_aktif: int, cltn_aktif: int, pulih_tb: int, pulih_cltn: int, gagal: int, info: array{bentrok: int, celah_tb: int, tanpa_riwayat: int, dilewati_nonaktif: int, dilewati_status_lain: int, berubah_saat_proses: int}}
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
            'info'       => ['bentrok' => 0, 'celah_tb' => 0, 'tanpa_riwayat' => 0, 'dilewati_nonaktif' => 0, 'dilewati_status_lain' => 0, 'berubah_saat_proses' => 0],
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

                $sebelum = (int) $baris['id_jenis_status'];

                if (! in_array($sebelum, [PenentuStatusHarian::AKTIF, PenentuStatusHarian::TB, PenentuStatusHarian::CLTN], true)) {
                    $hasil['info']['dilewati_status_lain']++;

                    continue;
                }

                $k = PenentuStatusHarian::tentukan($sebelum, $tb[$nip] ?? [], $cltn[$nip] ?? [], $tanggal);

                $hasil['info']['bentrok'] += $k->bentrok ? 1 : 0;
                $hasil['info']['celah_tb'] += $k->celahTb ? 1 : 0;
                $hasil['info']['tanpa_riwayat'] += $k->tanpaRiwayat ? 1 : 0;

                if (! $k->berubah()) {
                    continue;
                }

                if (! $dryRun) {
                    $akhir = $this->terapkan($nip, $sebelum, $k, (int) $idPelaku);

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
        $kumpulkan($this->db->table('riwayat_tb')->select('nip')->where('status', self::STATUS_DISETUJUI)
            ->where('tgl_mulai <=', $tanggal)->where('tgl_akhir >=', $tanggal)->get()->getResultArray());
        $kumpulkan($this->db->table('riwayat_tb t')->select('t.nip')->join('riwayat_tb_perpan p', 'p.id_riwayat_tb = t.id_riwayat_tb')
            ->where('t.status', self::STATUS_DISETUJUI)->where('p.tgl_mulai_perpan <=', $tanggal)->where('p.tgl_akhir_perpan >=', $tanggal)->get()->getResultArray());
        $kumpulkan($this->db->table('riwayat_cuti')->select('nip')->where('id_jenis_cuti', self::JENIS_CUTI_CLTN)->where('status', self::STATUS_DISETUJUI)
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
     * @return array<string, list<array{status: int, tgl_mulai: string, tgl_akhir: string, perpanjangan: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}>>
     */
    private function daftarTb(array $nip): array
    {
        $tb = $this->db->table('riwayat_tb')->select('id_riwayat_tb, nip, status, tgl_mulai, tgl_akhir')
            ->whereIn('nip', $nip)->where('status', self::STATUS_DISETUJUI)->orderBy('id_riwayat_tb')->get()->getResultArray();

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
     * @return array<string, list<array{status: int, tgl_mulai: string, tgl_akhir: string}>>
     */
    private function daftarCltn(array $nip): array
    {
        $hasil = [];
        $rows  = $this->db->table('riwayat_cuti')->select('nip, status, tgl_mulai, tgl_akhir')
            ->whereIn('nip', $nip)->where('id_jenis_cuti', self::JENIS_CUTI_CLTN)->where('status', self::STATUS_DISETUJUI)->get()->getResultArray();

        foreach ($rows as $r) {
            $hasil[(string) $r['nip']][] = ['status' => (int) $r['status'], 'tgl_mulai' => (string) $r['tgl_mulai'], 'tgl_akhir' => (string) $r['tgl_akhir']];
        }

        return $hasil;
    }

    /**
     * Satu pegawai = satu transaksi: kunci baris, cek `id_jenis_status` belum berubah sejak dimuat, update, audit.
     *
     * @return 'ok'|'berubah_saat_proses'|'gagal'
     */
    private function terapkan(string $nip, int $sebelum, KeputusanStatusHarian $k, int $idPelaku): string
    {
        $mulai = $this->db->transBegin();

        if ($mulai === false) {
            $this->db->resetTransStatus();

            return 'gagal';
        }

        try {
            $sql   = $this->db->table('pegawai')->select('id_jenis_status, status')->where('nip', $nip)->getCompiledSelect() . ' FOR UPDATE';
            $baris = $this->db->query($sql)->getRowArray();

            if ($baris === null || (int) $baris['status'] !== self::PEGAWAI_AKTIF || (int) $baris['id_jenis_status'] !== $sebelum) {
                $this->db->transRollback();
                $this->db->resetTransStatus();

                return 'berubah_saat_proses';
            }

            $this->db->table('pegawai')->where('nip', $nip)->update([
                'id_jenis_status' => $k->statusBaru,
                'updated_at'      => gmdate('Y-m-d H:i:s'),
                'updated_by'      => $idPelaku,
            ]);

            $this->audit->record(
                'pegawai',
                $nip,
                AuditLogModel::EVENT_UPDATE,
                ['id_jenis_status' => $sebelum],
                ['id_jenis_status' => $k->statusBaru, 'jenis_audit' => self::JENIS_AUDIT, 'alasan' => $k->alasan],
                $idPelaku,
                null,
            );

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();
                $this->db->resetTransStatus();

                return 'gagal';
            }

            $this->db->transCommit();
            $this->db->resetTransStatus();

            return 'ok';
        } catch (Throwable) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            return 'gagal';
        }
    }
}
