<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Pmk;

use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;

/**
 * Proses PIC PMK (PRD Fase 4 §6.9.2, D16): `POST pmk/pengajuan/{id}/process` `{aksi: proses|setujui|tolak, reason_note}`.
 * Pelaku = PIC PMK aktif (jenis 5), tanpa batas unit. 0 → proses → 3; 0/3 → setujui → 1 (final) [K P-14 0 → 1]; 0/3 →
 * tolak → 2 (`reason_note` wajib ≤ 255 byte); 1/2 → 422 `errors.status`. Setiap aksi mengisi `show_notif = 1`,
 * `notif_date`, `approved_at`, `approved_by` [K `L_pmk.php:1774-1792`] dan memberi tahu pemohon. **Tanpa** salin ke
 * `riwayat_pmk` dan **tanpa** mengubah masa kerja/KP/KGB/pegawai (D16).
 */
class PmkApprovalService extends DasarPmkService
{
    private const JUDUL = [
        StatusPmk::PROSES  => 'Peninjauan Masa Kerja Sedang Diproses',
        StatusPmk::SELESAI => 'Peninjauan Masa Kerja Selesai',
        StatusPmk::DITOLAK => 'Peninjauan Masa Kerja Ditolak',
    ];

    /**
     * @param array<string, mixed> $data
     */
    public function proses(AuthContext $auth, int $id, array $data): void
    {
        $awal = $this->barisTerlihat($auth, $id);

        if (! $this->adalahPic($auth)) {
            $this->terlarang();
        }

        $nip = (string) $awal['nip'];

        $this->transaksi(function () use ($auth, $id, $nip, $data): void {
            $row = $this->kunciBaris($nip, $id);

            if (! $this->adalahPic($auth)) {
                $this->terlarang();
            }

            $aksi  = isset($data['aksi']) && is_scalar($data['aksi']) ? trim((string) $data['aksi']) : '';
            $alas  = isset($data['reason_note']) && is_scalar($data['reason_note']) ? (string) $data['reason_note'] : null;
            $hasil = TransisiPmk::proses((int) $row['status'], $aksi, $alas, $this->aturanPmk());

            if (! $hasil->valid) {
                throw ValidationException::forField((string) $hasil->kode, (string) $hasil->pesan);
            }

            $status = (int) $hasil->detail['status'];
            $param  = [
                'status'      => $status,
                'reason_note' => $hasil->detail['reason_note'],
                'show_notif'  => 1,
                'notif_date'  => $this->sekarang(),
                'approved_at' => $this->sekarang(),
                'approved_by' => $auth->idPengguna(),
                'updated_by'  => $auth->idPengguna(),
                'updated_at'  => $this->sekarang(),
            ];

            $model = $this->model();
            $model->withActor($auth, static fn () => $model->update($id, $param));

            $baru = $this->kunciBaris($nip, $id);
            $isi  = $this->ringkasPmk($baru) . ($status === StatusPmk::DITOLAK ? '. Ket: ' . (string) $baru['reason_note'] : '');
            $this->notifikasi($auth, $id, 2, [$nip], self::JUDUL[$status], $isi, $status === StatusPmk::DITOLAK ? (string) $baru['reason_note'] : null, $status);
        });
    }
}
