<?php

declare(strict_types=1);

namespace App\Libraries\Layanan;

use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\ValidasiBerkas;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Libraries\Layanan\Studi\StatusStudi;
use App\Libraries\Layanan\Studi\TransisiStudi;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Proses PIC TB/IB satu langkah (PRD Fase 4 §6.7.3, §6.8.1, D14): `POST {tb|ib}/{id}/process` body `{aksi: setujui|tolak,
 * reason_note, <data SK>}`. Pelaku = PIC aktif jenis itu (role route mana pun, tanpa batas unit); status 0 → 1/2 (final),
 * selain itu 422 `errors.status` (`TransisiStudi`). Setujui: data SK wajib, `reason_note = NULL`, `show_pl = 1`,
 * `approved_by`, efek final; tolak: `reason_note` wajib ≤ 255 byte. Keduanya mengisi `show_notif`/`notif_date`
 * (pengganti trigger `riwayat_*_beUpd`, V3) dan menulis notifikasi ke pemohon.
 */
abstract class StudiApprovalService extends LayananStudi
{
    /**
     * Data SK opsional yang ikut disimpan saat setujui (TB `tgl_ds_tb`/`perihal_ds_tb`, IB `tgl_ds_ib`/`perihal_ds_ib`).
     *
     * @return list<string>
     */
    abstract protected function kolomSkOpsional(): array;

    /**
     * Efek final setuju — di dalam transaksi, sesudah baris → 1.
     *
     * @param array<string, mixed> $row
     */
    abstract protected function efekFinalSetuju(AuthContext $auth, array $row): void;

    /**
     * @param array<string, mixed> $data
     */
    public function proses(AuthContext $auth, int $id, array $data, ?UploadedFile $berkasSk = null): void
    {
        $awal = $this->barisTerlihat($auth, $id);

        if (! $this->adalahPic($auth)) {
            $this->terlarang();
        }

        if ($berkasSk !== null) {
            ValidasiBerkas::periksa($berkasSk, $this->aturanBerkas(BerkasStudi::kodeSurat($this->jenis()), BerkasStudi::EKSTENSI_SK), 'file_surat_' . $this->jenis());
        }

        $nip = (string) $awal['nip'];

        $this->transaksi(function () use ($auth, $id, $nip, $data, $berkasSk): void {
            $row = $this->kunciBaris($nip, $id);

            if (! $this->adalahPic($auth)) {
                $this->terlarang();
            }

            $aksi  = isset($data['aksi']) && is_scalar($data['aksi']) ? trim((string) $data['aksi']) : '';
            $alas  = isset($data['reason_note']) && is_scalar($data['reason_note']) ? (string) $data['reason_note'] : null;
            $hasil = TransisiStudi::proses($this->jenis(), (int) $row['status'], $aksi, $data, $alas, $this->aturan());

            if (! $hasil->valid) {
                $errors = $hasil->detail['errors'] ?? [(string) $hasil->kode => (string) $hasil->pesan];

                throw new ValidationException('Validasi gagal.', array_map(static fn (string $m): array => [$m], $errors));
            }

            $param = [
                'status'      => (int) $hasil->detail['status'],
                'reason_note' => $hasil->detail['reason_note'],
                'approved_by' => $auth->idPengguna(),
                'updated_by'  => $auth->idPengguna(),
                'updated_at'  => $this->sekarang(),
                'show_notif'  => 1,
                'notif_date'  => $this->sekarang(),
            ];

            if ($hasil->detail['show_pl'] !== null) {
                $param['show_pl'] = (int) $hasil->detail['show_pl'];
            }

            if ($param['status'] === StatusStudi::DISETUJUI) {
                foreach ([...array_keys(TransisiStudi::fieldSk($this->jenis())), ...$this->kolomSkOpsional()] as $kolom) {
                    $nilai         = isset($data[$kolom]) && is_scalar($data[$kolom]) ? trim((string) $data[$kolom]) : '';
                    $param[$kolom] = $nilai === '' ? null : $nilai;
                }
            }

            $model = $this->model();
            $model->withActor($auth, static fn () => $model->update($id, $param));

            if ($param['status'] === StatusStudi::DISETUJUI && $berkasSk !== null) {
                $kode = BerkasStudi::kodeSurat($this->jenis());
                $this->hapusLampiran($nip, $kode, $id);
                $this->lampiran()->simpan($nip, $kode, $id, $berkasSk, $this->aturanBerkas($kode, BerkasStudi::EKSTENSI_SK));
            }

            $baru = $this->kunciBaris($nip, $id);

            if ((bool) $hasil->detail['efek_final']) {
                $this->efekFinalSetuju($auth, $baru);
            }

            $this->notifikasiKeputusan($auth, $baru);
        });
    }
}
