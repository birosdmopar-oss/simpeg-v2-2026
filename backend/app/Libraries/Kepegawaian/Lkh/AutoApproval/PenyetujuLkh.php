<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lkh\AutoApproval;

use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lkh\LkhService;
use App\Models\Kepegawaian\LkhModel;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * MAKE-068 (lapisan DB) — penulisan satu LKH: kunci baris (`FOR UPDATE`), cek status masih 0 dan tanggal masih di bulan
 * yang diproses, lalu ubah lewat {@see LkhModel} beraudit dengan pelaku akun sistem. Transaksi dan kunci run ditangani
 * {@see AutoApprovalService}; kelas ini tidak membuka transaksi sendiri.
 *
 * Tidak membangkitkan atau menyimpan PDF (AC-12.3, CR-093) dan tidak menyentuh `show_notif` atau push (legacy job tidak
 * mengirim notifikasi).
 */
class PenyetujuLkh
{
    /** Hasil {@see setujui()}: LKH disetujui. */
    public const OK = 'ok';

    /** Hasil {@see setujui()}: baris sudah berubah atau tidak lagi masuk bulan yang diproses. */
    public const BERUBAH_SAAT_PROSES = 'berubah_saat_proses';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param string $awal  tanggal awal bulan (YYYY-MM-DD)
     * @param string $akhir tanggal akhir bulan (YYYY-MM-DD)
     *
     * @return self::OK|self::BERUBAH_SAAT_PROSES
     *
     * @throws RuntimeException kueri gagal (tidak dibaca sebagai "tidak ada")
     */
    public function setujui(int $id, string $awal, string $akhir, int $pelaku): string
    {
        $sql = $this->db->table('riwayat_lckh')
            ->select('id_riwayat_lckh, status, tgl_laporan')
            ->where('id_riwayat_lckh', $id)
            ->getCompiledSelect() . ' FOR UPDATE';

        $hasil = $this->db->query($sql);

        if ($hasil === false) {
            throw new RuntimeException('Auto-approval LKH gagal membaca baris: ' . $this->db->error()['message']);
        }

        $baris = $hasil->getRowArray();

        if (
            $baris === null
            || (int) $baris['status'] !== LkhService::MENUNGGU
            || (string) $baris['tgl_laporan'] < $awal
            || (string) $baris['tgl_laporan'] > $akhir
        ) {
            return self::BERUBAH_SAAT_PROSES;
        }

        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) $pelaku]);
        $model = new LkhModel($this->db);

        $ubah = $model->withActor($auth, static fn () => $model->update($id, [
            'status'        => LkhService::DISETUJUI,
            'auto_approval' => 1,
            'approved_by'   => $pelaku,
            'updated_by'    => $pelaku,
        ]));

        if ($ubah === false) {
            throw new RuntimeException('Auto-approval LKH gagal menulis baris.');
        }

        return self::OK;
    }
}
