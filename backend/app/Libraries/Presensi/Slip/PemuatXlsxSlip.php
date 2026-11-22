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
 * Bila versi PhpSpreadsheet berganti dan cara pengambilan part berubah, `PembacaExcelSlipBatasBacaTest` (test "part yang
 * sama") yang akan merah.
 */
final class PemuatXlsxSlip extends Xlsx
{
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

        if ($isi !== '' && str_contains(str_contains($isi, "\0") ? str_replace("\0", '', $isi) : $isi, 'sheetData')) {
            $sidik                 = strlen($isi) . ':' . hash('xxh3', $isi);
            $this->diambil[$sidik] = ($this->diambil[$sidik] ?? 0) + 1;

            if ($this->diambil[$sidik] > 2) {
                throw new RuntimeException('Dua sheet yang dimuat berisi part yang sama.');
            }
        }

        return $isi;
    }
}
