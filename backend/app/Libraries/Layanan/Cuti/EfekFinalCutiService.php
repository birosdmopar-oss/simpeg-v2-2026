<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Interfaces\Layanan\PenerbitSuratCutiInterface;
use App\Libraries\Kepegawaian\Kalkulasi\HasilKalkulasi;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Efek final cuti (PRD Fase 4 v1.3 §6.4.5, D4) — dipanggil HANYA pada transisi ke 1 atau 4 (T3, T6, T7(1,4), T8, T10,
 * T14), di dalam transaksi persetujuan:
 *  1. saldo (jenis 1) lewat {@see KuotaCutiService::potong()};
 *  2. CLTN (jenis 6) status 1 dengan hari ini dalam `tgl_mulai..tgl_akhir` → `pegawai.id_jenis_status = 3` (audit manual);
 *     CLTN yang mulai di masa depan tidak diubah (A-12);
 *  3. surat cuti belum-TTE lewat {@see PenerbitSuratCutiInterface} (M3: no-op; diisi M4).
 * Transisi ke 3 dan 2 tidak memanggil kelas ini. Tidak ada `BaseSnapshotModel` untuk cuti (keputusan reviewer 27-10).
 */
class EfekFinalCutiService
{
    /** `id_jenis_status` pegawai "Cuti di Luar Tanggungan Negara". */
    public const ID_STATUS_CLTN = 3;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly KuotaCutiService $kuota,
        private readonly PenerbitSuratCutiInterface $penerbit,
    ) {
    }

    /**
     * @param array<string, mixed> $cuti baris `riwayat_cuti` terkunci, SUDAH berstatus final (`status` = $statusKe)
     */
    public function terapkan(array $cuti, int $statusKe, string $hariIni): HasilKalkulasi
    {
        $jenis = (int) $cuti['id_jenis_cuti'];

        if (! EfekFinalCuti::adaEfek($statusKe)) {
            return HasilKalkulasi::berhasil(null, ['potong_saldo' => false, 'cltn_aktif' => false, 'surat' => false]);
        }

        $daftar = EfekFinalCuti::daftar($jenis, $statusKe, (string) $cuti['tgl_mulai'], (string) $cuti['tgl_akhir'], $hariIni);

        if ($daftar['potong_saldo']) {
            $hasil = $this->kuota->potong($cuti, $statusKe);

            if (! $hasil->valid) {
                return $hasil;
            }
        }

        if ($daftar['cltn_aktif']) {
            $this->setStatusCltn((string) $cuti['nip'], (int) $cuti['id_riwayat_cuti']);
        }

        if ($daftar['surat']) {
            $this->penerbit->terbitkanBelumTte($cuti);
        }

        return HasilKalkulasi::berhasil(null, $daftar);
    }

    /**
     * Kembalikan pegawai CLTN ke aktif saat hapus cuti 6 berjalan (§6.2.6 #4, `L:1365-1376`): hanya bila `id_jenis_status`
     * sekarang 3.
     */
    public function pulihkanCltn(string $nip, int $idCuti): bool
    {
        $peg = $this->db->table('pegawai')->where('nip', $nip)->get()->getRowArray();

        if ($peg === null || (int) $peg['id_jenis_status'] !== self::ID_STATUS_CLTN) {
            return false;
        }

        $this->tulisStatusPegawai($nip, 1, ['jenis_audit' => 'cuti.cltn_pulih', 'id_riwayat_cuti' => $idCuti], $peg);

        return true;
    }

    private function setStatusCltn(string $nip, int $idCuti): void
    {
        $peg = $this->db->table('pegawai')->where('nip', $nip)->get()->getRowArray();

        if ($peg === null || (int) $peg['id_jenis_status'] === self::ID_STATUS_CLTN) {
            return;
        }

        $this->tulisStatusPegawai($nip, self::ID_STATUS_CLTN, ['jenis_audit' => 'cuti.cltn_aktif', 'id_riwayat_cuti' => $idCuti], $peg);
    }

    /**
     * @param array<string, mixed> $penanda
     * @param array<string, mixed> $sebelum
     */
    private function tulisStatusPegawai(string $nip, int $statusBaru, array $penanda, array $sebelum): void
    {
        $this->db->table('pegawai')->where('nip', $nip)->update(['id_jenis_status' => $statusBaru]);

        (new AuditLogModel())->record(
            'pegawai',
            $nip,
            AuditLogModel::EVENT_UPDATE,
            ['id_jenis_status' => $sebelum['id_jenis_status']],
            ['id_jenis_status' => $statusBaru] + $penanda,
            service('authContext')->idPengguna(),
            service('authContext')->nip(),
        );
    }
}
