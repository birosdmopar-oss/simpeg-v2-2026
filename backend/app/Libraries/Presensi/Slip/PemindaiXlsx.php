<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use RuntimeException;
use XMLParser;
use ZipArchive;

/**
 * Pemindai berkas `.xlsx` SEBELUM dimuat PhpSpreadsheet (CR-177). Membaca setiap entri ZIP sebagai aliran
 * (`ZipArchive::getStreamIndex` + parser SAX `ext-xml`, potongan 64 KB) tanpa pernah menampung satu entri utuh di memori,
 * lalu mengembalikan hitungan untuk dibandingkan dengan anggaran SELURUH berkas di {@see PembacaExcelSlip}.
 *
 * Kenapa tidak memakai `Reader\Xlsx::listWorksheetInfo` (PhpSpreadsheet 5.10): `totalRows` = atribut `r` milik `<row>`
 * TERAKHIR (bukan maksimum; tanpa `r` dihitung 0), path part sheet untuk `Target` relasi dihitung berbeda dengan pemuat,
 * isi part ditampung utuh, dan yang dihitung hanya sel, padahal biaya memuat juga datang dari elemen lain.
 *
 * Karena itu pemindai TIDAK mengikuti relasi dan tidak memilih sheet: SEMUA entri ZIP dipindai (termasuk yang namanya
 * berakhiran `/`, yang tetap dimuat pemuat bila `Target` relasinya sama), dan hasilnya batas ATAS dari yang dibangun pemuat:
 *
 * - `sel`           = jumlah `<c>` pada jalur yang dibaca `Reader\Xlsx::loadSheetData` (akar apa pun → `sheetData` → `row`
 *                     → `c`, namespace utama SpreadsheetML transitional atau strict), di semua entri;
 * - `bobot`         = jumlah semua elemen + 2 × jumlah semua atribut, di semua entri. Pemuat membangun pohon SimpleXML
 *                     (libxml, DI LUAR `memory_limit`) untuk setiap part, part sheet bahkan dua kali; terukur ±125 byte
 *                     per elemen dan ±235 byte per atribut untuk tiap pohon;
 * - `iterasi_kolom` = jumlah langkah perulangan `Reader\Xlsx\ColumnAndRowAttributes::readColumnAttributes` untuk semua
 *                     `<col>`: 1 bila `max` = 16384, selain itu `max − min + 1`. `min`/`max` yang tidak sah (`max` < `min`
 *                     membuat perulangan pemuat tidak pernah berhenti) dihitung tak hingga;
 * - `byte`          = jumlah ukuran sesudah dekompresi semua entri (`statIndex`), dijaga lagi dengan byte yang benar-benar
 *                     terbaca.
 *
 * Supaya yang dihitung sama dengan yang diurai pemuat (`Reader\Security\XmlScanner` lalu SimpleXML):
 *
 * - entri yang deklarasi `encoding` PERTAMA-nya (sesudah byte nol dibuang, seperti `XmlScanner::findCharSet`) bukan UTF-8
 *   membuat berkas DITOLAK: pemuat mengonversi isinya dengan `mb_convert_encoding` sebelum diurai, jadi byte yang dipindai
 *   bukan byte yang diurai. Alias `UTF8` diterima. Kecuali entri itu juga memuat `<!DOCTYPE`;
 * - entri yang memuat `<!DOCTYPE` tidak diurai lebih lanjut dan tidak membuat berkas ditolak: pemuat menolak part
 *   seperti itu bila benar-benar dimuat (gambar SVG di `xl/media` tidak pernah dimuat sebagai sheet);
 * - entri ber-BOM UTF-16/UTF-32 diurai apa adanya (libxml mengenali BOM, sama seperti hasil konversi pemuat);
 * - entri XML (byte bermakna pertamanya `<`, atau ber-BOM UTF-16/32) yang GAGAL diurai membuat berkas DITOLAK: parser SAX
 *   berhenti pada galat yang masih diteruskan SimpleXML (mis. prefiks namespace tak dideklarasikan), jadi sisa entri
 *   tidak terhitung. Entri bukan XML (gambar, biner) dilewati: libxml milik pemuat juga menolaknya di byte pertama.
 */
final class PemindaiXlsx
{
    /** Lebar lembar `.xlsx`; `<col max="16384">` membuat pemuat berhenti sesudah satu langkah. */
    private const KOLOM_LEMBAR = 16384;

    private const POTONG = 65536;

    /** Byte terakhir potongan sebelumnya yang ikut diperiksa lagi, supaya pola yang terpotong di batas potongan tetap ketemu. */
    private const EKOR = 64;

    private const NS = ['http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'http://purl.oclc.org/ooxml/spreadsheetml/main'];

    private int $sel          = 0;
    private int $bobot        = 0;
    private int $iterasiKolom = 0;

    // Keadaan parser per entri.
    private int $dalam       = 0;
    private bool $dalamData  = false;
    private bool $dalamBaris = false;

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

    /**
     * @var array<string, true>
     */
    private array $namaKolom = [];

    private function __construct(private readonly int $maksBobot)
    {
        foreach (self::NS as $ns) {
            $this->namaData[$ns . "\x1F" . 'sheetData'] = true;
            $this->namaBaris[$ns . "\x1F" . 'row']      = true;
            $this->namaSel[$ns . "\x1F" . 'c']          = true;
            $this->namaKolom[$ns . "\x1F" . 'col']      = true;
        }
    }

    /**
     * Pemindaian berhenti lebih awal begitu `byte` melewati `$maksByte` atau `bobot` melewati `$maksBobot`; nilai yang
     * dikembalikan saat itu sudah melewati batasnya.
     *
     * @return array{sel: int, bobot: int, iterasi_kolom: int, byte: int}
     *
     * @throws RuntimeException berkas bukan ZIP yang terbaca, atau memuat entri yang hitungannya tidak bisa dipercaya
     */
    public static function pindai(string $path, int $maksByte, int $maksBobot = PHP_INT_MAX): array
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

            $pindai = new self($maksBobot);

            if ($byte <= $maksByte) {
                $terbaca = 0;

                for ($i = 0; $i < $zip->numFiles && $terbaca <= $maksByte && $pindai->bobot <= $maksBobot; $i++) {
                    $terbaca += $pindai->entri($zip, $i, $maksByte - $terbaca);
                }

                $byte = max($byte, $terbaca);
            }

            return ['sel' => $pindai->sel, 'bobot' => $pindai->bobot, 'iterasi_kolom' => $pindai->iterasiKolom, 'byte' => $byte];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return int byte yang terbaca dari entri ini (berhenti segera sesudah melewati `$sisaByte`)
     */
    private function entri(ZipArchive $zip, int $indeks, int $sisaByte): int
    {
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

        $terbaca  = 0;
        $ekor     = '';
        $jenis    = 'lain';   // 'xml' | 'bom' | 'lain' (bukan XML: tidak diurai)
        $urai     = true;
        $encoding = null;     // null = belum ketemu deklarasi, true = UTF-8, false = selain UTF-8
        $doctype  = false;

        try {
            while (! feof($aliran)) {
                $potong = fread($aliran, self::POTONG);

                if ($potong === false || $potong === '') {
                    break;
                }

                if ($terbaca === 0) {
                    $jenis = self::jenis($potong);
                }

                $terbaca += strlen($potong);

                if ($terbaca > $sisaByte) {
                    return $terbaca;
                }

                $teks = $ekor . (str_contains($potong, "\0") ? str_replace("\0", '', $potong) : $potong);

                if (! $doctype && str_contains($teks, '<!DOCTYPE')) {
                    $doctype = true;
                    $urai    = false;
                }

                // Entri ber-BOM: pemuat memakai BOM, bukan deklarasi, untuk menentukan encoding.
                if ($encoding === null && $jenis !== 'bom') {
                    $encoding = self::encodingPertama($teks, false);
                    $urai     = $urai && $encoding !== false;
                }

                $ekor = self::ekor($teks);

                if ($urai && $jenis !== 'lain') {
                    if (xml_parse($parser, $potong, false) !== 1) {
                        throw new RuntimeException('Entri XML di arsip xlsx tidak dapat diurai sampai selesai.');
                    }

                    if ($this->bobot > $this->maksBobot) {
                        return $terbaca;
                    }
                }
            }

            if ($encoding === null && $jenis !== 'bom') {
                $encoding = self::encodingPertama($ekor, true);
            }

            if ($encoding === false && ! $doctype) {
                throw new RuntimeException('Entri xlsx berdeklarasi encoding selain UTF-8.');
            }
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
        $this->bobot += 1 + 2 * count($atribut);

        if (isset($this->namaKolom[$nama])) {
            $this->hitungKolom((int) ($atribut['min'] ?? 0), (int) ($atribut['max'] ?? 0));
        }

        if ($this->dalam === 2) {
            $this->dalamData = isset($this->namaData[$nama]);
        } elseif ($this->dalam === 3) {
            $this->dalamBaris = $this->dalamData && isset($this->namaBaris[$nama]);
        } elseif ($this->dalam === 4 && $this->dalamBaris && isset($this->namaSel[$nama])) {
            $this->sel++;
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

    private function hitungKolom(int $min, int $maks): void
    {
        if ($maks === self::KOLOM_LEMBAR) {
            $langkah = 1;
        } elseif ($min >= 1 && $maks >= $min && $maks < self::KOLOM_LEMBAR) {
            $langkah = $maks - $min + 1;
        } else {
            $langkah = PHP_INT_MAX;
        }

        $this->iterasiKolom = $langkah > PHP_INT_MAX - $this->iterasiKolom ? PHP_INT_MAX : $this->iterasiKolom + $langkah;
    }

    /**
     * `bom` = BOM UTF-16/UTF-32; `xml` = byte bermakna pertama `<` (atau potongan pertama hanya berisi spasi/byte nol:
     * dianggap XML supaya tidak ada yang lolos tanpa diurai); selain itu `lain`.
     */
    private static function jenis(string $awal): string
    {
        foreach (["\xFF\xFE", "\xFE\xFF", "\x00\x00\xFE\xFF"] as $bom) {
            if (str_starts_with($awal, $bom)) {
                return 'bom';
            }
        }

        $isi = ltrim(str_replace("\0", '', str_starts_with($awal, "\xEF\xBB\xBF") ? substr($awal, 3) : $awal), " \t\r\n");

        return $isi === '' || $isi[0] === '<' ? 'xml' : 'lain';
    }

    /**
     * Deklarasi `encoding` pertama di `$teks` (sudah tanpa byte nol), dengan pola `XmlScanner::ENCODING_PATTERN`.
     *
     * @return bool|null true = UTF-8 (atau alias UTF8), false = selain itu, null = belum ada yang bisa diputuskan
     */
    private static function encodingPertama(string $teks, bool $akhir): ?bool
    {
        if (! str_contains($teks, 'encoding') || preg_match('/encoding\s*=\s*(["\'])/', $teks, $cocok, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        // Kecocokan di ekor diputuskan bersama potongan berikutnya (nilainya bisa terpotong).
        if (! $akhir && $cocok[0][1] >= strlen($teks) - self::EKOR) {
            return null;
        }

        return preg_match('/\Gutf-?8' . $cocok[1][0] . '/i', $teks, $nilai, 0, $cocok[0][1] + strlen($cocok[0][0])) === 1;
    }

    /**
     * Ekor yang disambung ke potongan berikutnya. `encoding` yang diikuti deretan spasi panjang sampai ujung potongan
     * diringkas supaya tidak hilang dari ekor.
     */
    private static function ekor(string $teks): string
    {
        if (preg_match('/encoding[\s=]*\z/', $teks, $ujung) === 1 && strlen($ujung[0]) > self::EKOR) {
            return 'encoding' . (str_contains($ujung[0], '=') ? '=' : '');
        }

        return substr($teks, -self::EKOR);
    }
}
