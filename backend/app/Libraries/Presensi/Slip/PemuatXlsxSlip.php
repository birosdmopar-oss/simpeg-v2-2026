<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use ZipArchive;

/**
 * Reader Xlsx PhpSpreadsheet untuk impor Slip Gaji (CR-177) yang menolak berkas bila DUA sheet yang dimuat berisi part yang
 * sama (mis. relasi GPP dan TK menunjuk satu part). {@see PemindaiXlsx} menghitung part itu sekali, sedangkan pemuat
 * membangunnya sekali per sheet, jadi anggaran pemindai terlampaui dua kali tanpa ketahuan.
 *
 * Caranya tidak menebak path part (aturan `Target` relatif/absolut, huruf besar-kecil, dan nama cadangan milik pemuat
 * berubah antarversi), tetapi mencegat pengambilan isi part itu sendiri: `Reader\Xlsx` (diperiksa pada 5.10.0, versi di
 * `composer.lock`) mengambil setiap part sheet tepat DUA kali lewat `getFromZipArchive()` (`loadZipNoNamespace` lalu
 * `loadZip`, `Xlsx.php:997-998`). Isi ber-`sheetData` yang sama diambil untuk ketiga kalinya berarti sheet kedua memakai
 * part yang sama → dilempar sebelum salinan keduanya diurai. Dua entri berbeda yang isinya persis sama diperlakukan sama
 * (GPP dan TK yang sah tidak pernah identik: header-nya berbeda).
 *
 * Part sheet dikenali dari ELEMEN `<sheetData>` (dengan atau tanpa prefiks), bukan dari kata "sheetData": relasi sheet
 * yang URL hyperlink-nya memuat kata itu diambil pemuat lebih dari dua kali pada berkas sah (sheet bertabel), dan isinya
 * bisa identik untuk GPP dan TK. `<` di nilai atribut dan teks XML selalu di-escape, jadi pola elemen tidak bisa muncul
 * di sana.
 *
 * Keterbatasan: yang dicegah hanya part ber-`<sheetData>` yang sama untuk dua sheet. Part LAIN yang dipakai bersama oleh
 * GPP dan TK (mis. relasi, gambar, atau part tanpa `<sheetData>` yang ditunjuk kedua sheet) tetap diurai pemuat untuk
 * tiap sheet, sama seperti sebelum CR-177; terukur RSS 827 MB menjadi 1.213 MB pada berkas buatan. Dicatat sebagai sisa
 * risiko (README `Libraries/Presensi` BB-11), tidak ditutup.
 *
 * Bila versi PhpSpreadsheet berganti dan cara pengambilan part berubah, `PembacaExcelSlipBatasBacaTest` (test "part yang
 * sama") yang akan merah.
 */
final class PemuatXlsxSlip extends Xlsx
{
    private const POLA_SHEET_DATA = '/<(?:[\w.-]+:)?sheetData[\s\/>]/';

    /**
     * Sidik isi part ber-`sheetData` => berapa kali diambil dalam muatan ini.
     *
     * @var array<string, int>
     */
    private array $diambil = [];

    public function load(string $filename, int $flags = 0): Spreadsheet
    {
        $this->diambil = [];

        return parent::load($filename, $flags);
    }

    protected function getFromZipArchive(ZipArchive $archive, string $fileName = ''): string
    {
        $isi = parent::getFromZipArchive($archive, $fileName);

        if ($isi !== '' && preg_match(self::POLA_SHEET_DATA, str_contains($isi, "\0") ? str_replace("\0", '', $isi) : $isi) === 1) {
            $sidik                 = strlen($isi) . ':' . hash('xxh3', $isi);
            $this->diambil[$sidik] = ($this->diambil[$sidik] ?? 0) + 1;

            if ($this->diambil[$sidik] > 2) {
                throw new RuntimeException('Dua sheet yang dimuat berisi part yang sama.');
            }
        }

        return $isi;
    }
}
