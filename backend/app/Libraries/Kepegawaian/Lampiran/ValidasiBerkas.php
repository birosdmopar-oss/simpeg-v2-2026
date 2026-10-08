<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lampiran;

use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Mimes;

/**
 * Validasi berkas unggahan terhadap AturanLampiran (B-18, MAKE-009), dipakai AttachmentService dan KolomBerkas.
 *
 * - Ukuran = ukuran berkas sementara di disk (bukan angka kiriman klien); kosong atau > batas → 422.
 * - Jenis = MIME dari ISI berkas (finfo) dipetakan ke ekstensi lewat Config\Mimes; ekstensi nama berkas klien hanya
 *   dipakai sebagai petunjuk bila satu MIME punya beberapa ekstensi. Tidak cocok dengan aturan → 422.
 * - Nama asli klien hanya metadata tampilan (dibersihkan), tidak pernah menjadi nama simpan.
 */
final class ValidasiBerkas
{
    private function __construct()
    {
    }

    /**
     * @return array{isi: string, ekstensi: string, mime: string, ukuran: int, nama_tampil: string}
     *
     * @throws ValidationException 422 dengan kunci $field
     */
    public static function periksa(UploadedFile $berkas, AturanLampiran $aturan, string $field = 'berkas'): array
    {
        $gagal = static fn (string $pesan): ValidationException => new ValidationException('Validasi gagal.', [$field => [$pesan]]);

        $error = $berkas->getError();

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw $gagal('Berkas wajib diunggah.');
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw $gagal("Ukuran berkas melebihi {$aturan->batasMb} MB.");
        }

        $temp = $berkas->getTempName();

        if ($error !== UPLOAD_ERR_OK || $temp === '' || ! is_file($temp) || ! is_readable($temp)) {
            throw $gagal('Berkas gagal diunggah.');
        }

        $ukuran = (int) filesize($temp);

        if ($ukuran <= 0) {
            throw $gagal('Berkas kosong.');
        }

        if ($ukuran > $aturan->batasByte()) {
            throw $gagal("Ukuran berkas melebihi {$aturan->batasMb} MB.");
        }

        $mime     = self::mimeDariIsi($temp);
        $usulan   = strtolower(pathinfo($berkas->getClientName(), PATHINFO_EXTENSION));
        $ekstensi = $mime === '' ? null : Mimes::guessExtensionFromType($mime, $usulan === '' ? null : $usulan);

        if ($ekstensi === null || $ekstensi === '' || ! $aturan->ekstensiDiizinkan($ekstensi)) {
            throw $gagal('Jenis berkas tidak diizinkan (hanya ' . implode(', ', $aturan->ekstensi) . ').');
        }

        $isi = @file_get_contents($temp);

        if ($isi === false) {
            throw $gagal('Berkas gagal diunggah.');
        }

        return [
            'isi'         => $isi,
            'ekstensi'    => strtolower($ekstensi),
            'mime'        => $mime,
            'ukuran'      => $ukuran,
            'nama_tampil' => self::namaTampil($berkas->getClientName(), strtolower($ekstensi)),
        ];
    }

    /**
     * Nama tampilan aman dari nama kiriman klien: tanpa folder, tanpa karakter kontrol, UTF-8 valid, ≤ 200 karakter
     * (kolom `display_name`); kosong → "lampiran.<ekstensi>".
     */
    public static function namaTampil(string $namaKlien, string $ekstensi): string
    {
        $nama = basename(str_replace('\\', '/', $namaKlien));
        $nama = mb_check_encoding($nama, 'UTF-8') ? $nama : mb_convert_encoding($nama, 'UTF-8', 'UTF-8');
        $nama = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $nama));

        if ($nama === '' || $nama === '.' || $nama === '..') {
            return 'lampiran.' . $ekstensi;
        }

        return mb_substr($nama, 0, 200);
    }

    private static function mimeDariIsi(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return '';
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? strtolower($mime) : '';
    }
}
