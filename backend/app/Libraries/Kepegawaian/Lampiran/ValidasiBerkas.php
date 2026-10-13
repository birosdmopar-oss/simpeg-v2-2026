<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lampiran;

use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Mimes;
use ZipArchive;

/**
 * Validasi berkas unggahan terhadap AturanLampiran (B-18, MAKE-009), dipakai AttachmentService dan KolomBerkas.
 *
 * - Ukuran = ukuran berkas sementara di disk (bukan angka kiriman klien); kosong atau > batas → 422.
 * - Jenis = MIME dari ISI berkas (finfo) dipetakan ke ekstensi lewat Config\Mimes; ekstensi nama berkas klien hanya
 *   dipakai sebagai petunjuk bila satu MIME punya beberapa ekstensi. Tidak cocok dengan aturan → 422.
 * - Dokumen Office: struktur isi wajib cocok dengan ekstensi hasil pemetaan (OOXML untuk docx/xlsx, OLE untuk doc/xls;
 *   MAKE-010) — ZIP biasa bernama `.docx` → 422.
 * - Nama asli klien hanya metadata tampilan (dibersihkan), tidak pernah menjadi nama simpan.
 */
final class ValidasiBerkas
{
    /**
     * Folder bagian aplikasi paket OOXML per ekstensi (ECMA-376: `word/` untuk dokumen Word, `xl/` untuk buku kerja
     * Excel).
     */
    private const OOXML_FOLDER = [
        'docx' => 'word/',
        'xlsx' => 'xl/',
    ];

    /**
     * Ekstensi dokumen Office lama berformat OLE Compound File.
     */
    private const EKSTENSI_OLE = ['doc', 'xls'];

    private const TANDA_OLE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

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

        if (! self::strukturDokumenOfficeCocok($temp, strtolower($ekstensi))) {
            throw $gagal('Isi berkas tidak sesuai dengan jenis ' . strtolower($ekstensi) . '.');
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

    /**
     * Struktur isi dokumen Office (MAKE-010, catatan sisa MAKE-009). Config\Mimes memetakan `application/zip` dan
     * `application/msword` ke `docx`/`xlsx`, sehingga ZIP biasa atau dokumen OLE lama yang dinamai `.docx`/`.xlsx`
     * lolos pemetaan MIME. Di sini isi diperiksa sesuai ekstensi hasil pemetaan:
     *  - OOXML (`docx`, `xlsx`): arsip ZIP yang memuat `[Content_Types].xml` (penanda paket OPC) dan bagian di folder
     *    aplikasi jenis itu (`word/` atau `xl/`);
     *  - OLE (`doc`, `xls`): tanda tangan Compound File Binary `D0 CF 11 E0 A1 B1 1A E1` di awal berkas.
     * Ekstensi lain tidak diperiksa di sini (MIME dari isi sudah cukup). Ekstensi ZIP PHP tidak tersedia → OOXML ditolak
     * (fail-closed).
     */
    private static function strukturDokumenOfficeCocok(string $path, string $ekstensi): bool
    {
        if (isset(self::OOXML_FOLDER[$ekstensi])) {
            return self::ooxmlCocok($path, self::OOXML_FOLDER[$ekstensi]);
        }

        if (in_array($ekstensi, self::EKSTENSI_OLE, true)) {
            $awal = @file_get_contents($path, false, null, 0, strlen(self::TANDA_OLE));

            return $awal === self::TANDA_OLE;
        }

        return true;
    }

    private static function ooxmlCocok(string $path, string $folder): bool
    {
        if (! class_exists(ZipArchive::class)) {
            return false;
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            if ($zip->locateName('[Content_Types].xml') === false) {
                return false;
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nama = $zip->getNameIndex($i);

                if (is_string($nama) && str_starts_with($nama, $folder) && ! str_ends_with($nama, '/')) {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
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
