<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use RuntimeException;
use XMLParser;
use ZipArchive;

/**
 * Pemindai ukuran berkas `.xlsx` SEBELUM dimuat PhpSpreadsheet (CR-177). Membaca setiap entri ZIP sebagai aliran
 * (`ZipArchive::getStreamIndex` + parser SAX `ext-xml`, potongan 64 KB) tanpa pernah menampung satu entri utuh di memori.
 *
 * Kenapa tidak memakai `Reader\Xlsx::listWorksheetInfo` (PhpSpreadsheet 5.10):
 * - `totalRows` = atribut `r` milik `<row>` TERAKHIR (ditimpa, bukan maksimum; `<row>` tanpa `r` dihitung 0), padahal
 *   pemuat menaruh sel menurut `r` milik sel atau urutan `<row>`. Sejuta `<row>` tanpa `r` terbaca "0 baris";
 * - path part sheet untuk `Target` relasi absolut dihitung berbeda dengan pemuat, jadi yang diperiksa bisa bukan part
 *   yang dimuat;
 * - isi part ditampung utuh sebagai string.
 *
 * Karena itu pemindai ini TIDAK mengikuti relasi dan tidak memilih sheet: SEMUA entri ZIP dipindai, dan yang dihitung
 * adalah bentuk yang dibaca pemuat (`Reader\Xlsx::loadSheetData`): elemen akar apa pun → `sheetData` → `row` → `c` di
 * namespace utama SpreadsheetML (transitional atau strict). Hasilnya batas ATAS dari apa yang bisa dibuat pemuat:
 *
 * - `baris` = maksimum dari atribut `r` `<row>`, baris pada `r` sel, dan nomor urut `<row>` (pemuat memakai nomor urut
 *   bila sel tanpa `r`);
 * - `kolom` = maksimum dari kolom pada `r` sel dan nomor urut sel di barisnya;
 * - `sel`   = jumlah `<c>` di semua entri;
 * - `byte`  = jumlah ukuran sesudah dekompresi semua entri (`statIndex`), dijaga lagi dengan byte yang benar-benar terbaca.
 *
 * Supaya byte yang dipindai sama dengan yang diurai pemuat, entri yang akan DIUBAH `Reader\Security\XmlScanner` sebelum
 * diurai ditolak: BOM UTF-16/UTF-32, deklarasi `encoding` selain UTF-8 (pemuat mengonversinya dengan
 * `mb_convert_encoding`), dan `<!DOCTYPE` (pemuat menolaknya). Entri yang gagal diurai (gambar, biner) dilewati: pemuat
 * mengurai byte yang sama dengan libxml yang sama, jadi gagal juga.
 */
final class PemindaiXlsx
{
    private const POTONG = 65536;

    /** Byte terakhir potongan sebelumnya yang ikut diperiksa lagi, supaya pola yang terpotong di batas potongan tetap ketemu. */
    private const EKOR = 64;

    private const NS = ['http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'http://purl.oclc.org/ooxml/spreadsheetml/main'];

    private int $baris = 0;
    private int $kolom = 0;
    private int $sel   = 0;

    // Keadaan parser per entri.
    private int $dalam       = 0;
    private bool $dalamData  = false;
    private bool $dalamBaris = false;
    private int $urutBaris   = 0;
    private int $urutSel     = 0;

    /**
     * @var array<string, true>
     */
    private array $namaData = [];

    /**
     * @var array<string, true>
     */
    private array $namaBaris = [];

    /**
     * @var array<string, true>
     */
    private array $namaSel = [];

    private function __construct()
    {
        foreach (self::NS as $ns) {
            $this->namaData[$ns . "\x1F" . 'sheetData'] = true;
            $this->namaBaris[$ns . "\x1F" . 'row']      = true;
            $this->namaSel[$ns . "\x1F" . 'c']          = true;
        }
    }

    /**
     * @param int $maksByte batas jumlah ukuran sesudah dekompresi; bila terlewati pemindaian berhenti dan `byte` > batas
     *
     * @return array{baris: int, kolom: int, sel: int, byte: int}
     *
     * @throws RuntimeException berkas bukan ZIP yang terbaca, atau memuat entri yang akan diubah pemuat sebelum diurai
     */
    public static function pindai(string $path, int $maksByte): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Arsip xlsx tidak dapat dibuka.');
        }

        try {
            $byte = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    throw new RuntimeException('Entri arsip xlsx tidak terbaca.');
                }

                $byte += (int) $stat['size'];
            }

            $pindai = new self();

            if ($byte <= $maksByte) {
                $terbaca = 0;

                for ($i = 0; $i < $zip->numFiles && $terbaca <= $maksByte; $i++) {
                    $terbaca += $pindai->entri($zip, $i, $maksByte - $terbaca);
                }

                $byte = max($byte, $terbaca);
            }

            return ['baris' => $pindai->baris, 'kolom' => $pindai->kolom, 'sel' => $pindai->sel, 'byte' => $byte];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return int byte yang terbaca dari entri ini (berhenti segera sesudah melewati `$sisaByte`)
     */
    private function entri(ZipArchive $zip, int $indeks, int $sisaByte): int
    {
        $nama = $zip->getNameIndex($indeks);

        if ($nama === false || str_ends_with($nama, '/')) {
            return 0;
        }

        $aliran = $zip->getStreamIndex($indeks);

        if ($aliran === false) {
            throw new RuntimeException('Entri arsip xlsx tidak dapat dibaca.');
        }

        $parser = xml_parser_create_ns(null, "\x1F");
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
        xml_set_element_handler($parser, $this->buka(...), $this->tutup(...));

        $this->dalam      = 0;
        $this->dalamData  = false;
        $this->dalamBaris = false;
        $this->urutBaris  = 0;
        $this->urutSel    = 0;

        $terbaca = 0;
        $ekor    = '';
        $urai    = true;

        try {
            while (! feof($aliran)) {
                $potong = fread($aliran, self::POTONG);

                if ($potong === false || $potong === '') {
                    break;
                }

                if ($terbaca === 0) {
                    self::tolakBom($potong);
                }

                $terbaca += strlen($potong);

                if ($terbaca > $sisaByte) {
                    return $terbaca;
                }

                $ekor = self::periksaMentah($ekor . (str_contains($potong, "\0") ? str_replace("\0", '', $potong) : $potong), false);

                // Sesudah galat urai, sisa entri tetap dibaca untuk pemeriksaan mentah di atas; hanya penguraiannya yang berhenti.
                if ($urai && xml_parse($parser, $potong, false) !== 1) {
                    $urai = false;
                }
            }

            self::periksaMentah($ekor, true);
        } finally {
            fclose($aliran);
        }

        return $terbaca;
    }

    /**
     * @param array<string, string> $atribut
     */
    private function buka(XMLParser $parser, string $nama, array $atribut): void
    {
        $this->dalam++;

        if ($this->dalam === 2) {
            $this->dalamData = isset($this->namaData[$nama]);

            return;
        }

        if ($this->dalam === 3 && $this->dalamData && isset($this->namaBaris[$nama])) {
            $this->dalamBaris = true;
            $this->urutBaris++;
            $this->urutSel = 0;
            $this->baris   = max($this->baris, $this->urutBaris, (int) ($atribut['r'] ?? 0));

            return;
        }

        if ($this->dalam === 4 && $this->dalamBaris && isset($this->namaSel[$nama])) {
            $this->sel++;
            $this->urutSel++;
            $this->kolom = max($this->kolom, $this->urutSel);

            if (preg_match('/^\$?([A-Za-z]{1,3})\$?(\d{1,9})$/', $atribut['r'] ?? '', $cocok) === 1) {
                $indeks = 0;

                foreach (str_split(strtoupper($cocok[1])) as $huruf) {
                    $indeks = $indeks * 26 + ord($huruf) - 64;
                }

                $this->kolom = max($this->kolom, $indeks);
                $this->baris = max($this->baris, (int) $cocok[2]);
            }
        }
    }

    private function tutup(XMLParser $parser, string $nama): void
    {
        if ($this->dalam === 3) {
            $this->dalamBaris = false;
        } elseif ($this->dalam === 2) {
            $this->dalamData = false;
        }

        $this->dalam--;
    }

    private static function tolakBom(string $awal): void
    {
        foreach (["\xFF\xFE", "\xFE\xFF", "\x00\x00\xFE\xFF"] as $bom) {
            if (str_starts_with($awal, $bom)) {
                throw new RuntimeException('Entri xlsx ber-BOM UTF-16/UTF-32 tidak didukung.');
            }
        }
    }

    /**
     * Pola yang membuat pemuat mengubah atau menolak byte entri (lihat docblock kelas). `$teks` sudah tanpa byte nol,
     * sama seperti yang dilakukan `XmlScanner` sebelum mencari pola.
     *
     * @return string ekor yang harus disambung ke potongan berikutnya
     */
    private static function periksaMentah(string $teks, bool $akhir): string
    {
        if (str_contains($teks, '<!DOCTYPE')) {
            throw new RuntimeException('Entri xlsx memuat DOCTYPE.');
        }

        $batas = $akhir ? PHP_INT_MAX : strlen($teks) - self::EKOR;

        if (str_contains($teks, 'encoding') && preg_match_all('/encoding\s*=\s*(["\'])/', $teks, $cocok, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($cocok as $c) {
                // Kecocokan di ekor diperiksa bersama potongan berikutnya (nilainya bisa terpotong).
                if ($c[0][1] >= $batas) {
                    break;
                }

                if (strcasecmp(substr($teks, $c[0][1] + strlen($c[0][0]), 6), 'utf-8' . $c[1][0]) !== 0) {
                    throw new RuntimeException('Entri xlsx berdeklarasi encoding selain UTF-8.');
                }
            }
        }

        if ($akhir) {
            return '';
        }

        // `encoding` yang diikuti deretan spasi panjang sampai ujung potongan: diringkas supaya tidak hilang dari ekor.
        if (preg_match('/encoding[\s=]*\z/', $teks, $ujung) === 1 && strlen($ujung[0]) > self::EKOR) {
            return 'encoding' . (str_contains($ujung[0], '=') ? '=' : '');
        }

        return substr($teks, -self::EKOR);
    }
}
