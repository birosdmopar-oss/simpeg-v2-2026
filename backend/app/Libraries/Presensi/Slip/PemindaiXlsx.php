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
 * - `tabel`         = jumlah `<tablePart>`: pemuat mengurai ulang relasi sheet untuk setiap `<tablePart>`;
 * - `salinan`       = byte teks yang disalin pemuat per sel: untuk sel `t="s"`, panjang shared string yang dirujuknya
 *                     (indeks `<v>` → `<si>` ke-n, dari semua entri ber-`<si>`); untuk rumus `<f t="shared">`, panjang teks
 *                     rumus induk × jumlah sel turunannya;
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
 * - entri XML (byte bermakna pertamanya `<`, atau ber-BOM UTF-16/32) yang GAGAL diurai TIDAK membuat berkas ditolak
 *   (VML tulisan Excel memuat `<br>` tanpa penutup; pemuat mengabaikannya), tetapi sisanya dihitung KASAR sebagai batas
 *   atas, karena parser SAX berhenti pada galat yang masih diteruskan SimpleXML (mis. prefiks namespace tak
 *   dideklarasikan): setiap `<` = satu elemen, setiap `=` = satu atribut, setiap `<c>` (dengan atau tanpa prefiks) = satu sel,
 *   dan `<col`, `<tablePart`, `<si`, atau `t="shared"` di entri seperti itu dihitung tak hingga. Entri bukan XML (gambar, biner) dilewati: libxml
 *   milik pemuat juga menolaknya di byte pertama.
 */
final class PemindaiXlsx
{
    /** Lebar lembar `.xlsx`; `<col max="16384">` membuat pemuat berhenti sesudah satu langkah. */
    private const KOLOM_LEMBAR = 16384;

    /** Panjang teks sel terpanjang di Excel; pemuat memotong shared string ke sini (karakter; × 4 byte UTF-8). */
    private const BYTE_TEKS_SEL = 32767 * 4;

    private const POTONG = 65536;

    /** Byte terakhir potongan sebelumnya yang ikut diperiksa lagi, supaya pola yang terpotong di batas potongan tetap ketemu. */
    private const EKOR = 64;

    private const NS = ['http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'http://purl.oclc.org/ooxml/spreadsheetml/main'];

    private int $sel          = 0;
    private int $bobot        = 0;
    private int $iterasiKolom = 0;
    private int $tabel        = 0;
    private int $salinanRumus = 0;

    /**
     * Panjang byte `<si>` ke-n yang tidak kosong (maksimum dari semua entri ber-`<si>`).
     *
     * @var array<int, int>
     */
    private array $panjangSi = [];

    /**
     * Indeks shared string => jumlah sel `t="s"` yang merujuknya.
     *
     * @var array<int, int>
     */
    private array $rujukSi = [];

    /** Sel `t="s"` di entri yang dihitung kasar: indeksnya tidak diketahui, dianggap merujuk `<si>` terpanjang. */
    private int $rujukKasar = 0;

    // Keadaan parser per entri.
    private int $dalam       = 0;
    private bool $dalamData  = false;
    private bool $dalamBaris = false;
    private bool $selString  = false;
    private ?string $teks    = null;
    private int $urutSi      = 0;
    private bool $dalamSi    = false;
    private int $byteSi      = 0;
    private ?string $rumusSi = null;
    private int $byteRumus   = 0;

    /**
     * Rumus shared entri ini: `si` => [panjang teks induk, jumlah sel turunan].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private array $rumus = [];

    /**
     * Nama elemen berkualifikasi namespace => jenis.
     *
     * @var array<string, string>
     */
    private array $nama = [];

    private function __construct(private readonly int $maksBobot)
    {
        foreach (self::NS as $ns) {
            foreach (['sheetData', 'row', 'c', 'v', 'f', 'col', 'tablePart', 'si'] as $lokal) {
                $this->nama[$ns . "\x1F" . $lokal] = $lokal;
            }
        }
    }

    /**
     * Pemindaian berhenti lebih awal begitu `byte` melewati `$maksByte` atau `bobot` melewati `$maksBobot`; nilai yang
     * dikembalikan saat itu sudah melewati batasnya.
     *
     * @return array{sel: int, bobot: int, iterasi_kolom: int, tabel: int, salinan: int, byte: int}
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

            return [
                'sel'           => $pindai->sel,
                'bobot'         => $pindai->bobot,
                'iterasi_kolom' => $pindai->iterasiKolom,
                'tabel'         => $pindai->tabel,
                'salinan'       => $pindai->salinan(),
                'byte'          => $byte,
            ];
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
        xml_set_character_data_handler($parser, $this->isi(...));

        $this->dalam      = 0;
        $this->dalamData  = false;
        $this->dalamBaris = false;
        $this->selString  = false;
        $this->teks       = null;
        $this->urutSi     = 0;
        $this->dalamSi    = false;
        $this->rumusSi    = null;
        $this->rumus      = [];

        $terbaca  = 0;
        $ekor     = '';
        $jenis    = 'lain';   // 'xml' | 'bom' | 'lain' (bukan XML: tidak diurai)
        $urai     = true;
        $kasar    = false;    // urai gagal: sisa entri dihitung kasar
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

                $bersih = str_contains($potong, "\0") ? str_replace("\0", '', $potong) : $potong;
                $teks   = $ekor . $bersih;

                if (! $doctype && str_contains($teks, '<!DOCTYPE')) {
                    $doctype = true;
                    $urai    = false;
                    $kasar   = false;
                }

                // Entri ber-BOM: pemuat memakai BOM, bukan deklarasi, untuk menentukan encoding.
                if ($encoding === null && $jenis !== 'bom') {
                    $encoding = self::encodingPertama($teks, false);
                    $urai     = $urai && $encoding !== false;
                }

                $ekor = self::ekor($teks);

                if ($urai && $jenis !== 'lain' && xml_parse($parser, $potong, false) !== 1) {
                    $urai  = false;
                    $kasar = true;
                }

                if ($kasar) {
                    $this->hitungKasar($teks);
                }

                if ($this->bobot > $this->maksBobot) {
                    return $terbaca;
                }
            }

            if ($encoding === null && $jenis !== 'bom') {
                $encoding = self::encodingPertama($ekor, true);
            }

            if ($encoding === false && ! $doctype) {
                throw new RuntimeException('Entri xlsx berdeklarasi encoding selain UTF-8.');
            }

            foreach ($this->rumus as [$panjang, $turunan]) {
                $this->salinanRumus = self::tambah($this->salinanRumus, self::kali($panjang, $turunan));
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
        $jenis = $this->nama[$nama] ?? null;

        if ($jenis === 'col') {
            $this->hitungKolom((int) ($atribut['min'] ?? 0), (int) ($atribut['max'] ?? 0));
        } elseif ($jenis === 'tablePart') {
            $this->tabel++;
        } elseif ($jenis === 'si' && $this->dalam === 2) {
            $this->dalamSi = true;
            $this->byteSi  = 0;
        }

        if ($this->dalam === 2) {
            $this->dalamData = $jenis === 'sheetData';
        } elseif ($this->dalam === 3) {
            $this->dalamBaris = $this->dalamData && $jenis === 'row';
        } elseif ($this->dalam === 4 && $this->dalamBaris && $jenis === 'c') {
            $this->sel++;
            $this->selString = ($atribut['t'] ?? '') === 's';
        } elseif ($this->dalam === 5 && $this->dalamBaris) {
            if ($jenis === 'v' && $this->selString) {
                $this->teks = '';
            } elseif ($jenis === 'f' && strtolower($atribut['t'] ?? '') === 'shared') {
                $this->rumusSi   = $atribut['si'] ?? '';
                $this->byteRumus = 0;
            }
        }
    }

    private function isi(XMLParser $parser, string $data): void
    {
        if ($this->dalamSi) {
            $this->byteSi += strlen($data);
        } elseif ($this->teks !== null) {
            // Indeks shared string: pemuat memakai `(int)` seluruh teks, jadi spasi dan nol di depan dibuang dulu. Yang
            // disimpan paling banyak 21 karakter (lebih dari 20 = bukan indeks wajar, lihat tutup()).
            $this->teks = substr(ltrim($this->teks . $data, " \t\r\n0"), 0, 21);
        } elseif ($this->rumusSi !== null) {
            $this->byteRumus += strlen($data);
        }
    }

    private function tutup(XMLParser $parser, string $nama): void
    {
        if ($this->dalam === 5) {
            if ($this->teks !== null) {
                if (strlen($this->teks) > 20) {
                    // Terlalu panjang untuk dipercaya: dianggap merujuk `<si>` terpanjang.
                    $this->rujukKasar++;
                } else {
                    $indeks                 = (int) $this->teks;
                    $this->rujukSi[$indeks] = ($this->rujukSi[$indeks] ?? 0) + 1;
                }

                $this->teks = null;
            } elseif ($this->rumusSi !== null) {
                // Rumus ber-teks = induk; tanpa teks = turunan yang menyalin teks induknya.
                $r                           = $this->rumus[$this->rumusSi] ?? [0, 0];
                $this->rumus[$this->rumusSi] = $this->byteRumus > 0 ? [max($r[0], $this->byteRumus), $r[1] + 1] : [$r[0], $r[1] + 1];
                $this->rumusSi               = null;
            }
        } elseif ($this->dalam === 4) {
            $this->selString = false;
        } elseif ($this->dalam === 3) {
            $this->dalamBaris = false;
        } elseif ($this->dalam === 2) {
            $this->dalamData = false;

            if ($this->dalamSi) {
                // `<si>` kosong tidak dicatat (panjang 0 = bawaan), supaya jutaan `<si/>` tidak menumpuk di sini.
                if ($this->byteSi > 0) {
                    $this->panjangSi[$this->urutSi] = max($this->panjangSi[$this->urutSi] ?? 0, min($this->byteSi, self::BYTE_TEKS_SEL));
                }

                $this->urutSi++;
                $this->dalamSi = false;
            }
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

        $this->iterasiKolom = self::tambah($this->iterasiKolom, $langkah);
    }

    /**
     * Batas atas kasar untuk potongan entri XML yang tidak bisa diurai parser SAX (lihat docblock kelas). `$teks` sudah
     * tanpa byte nol dan diawali ekor potongan sebelumnya (64 byte terhitung dua kali: tetap batas atas).
     */
    private function hitungKasar(string $teks): void
    {
        $this->bobot += substr_count($teks, '<') + 2 * substr_count($teks, '=');
        $this->sel += (int) preg_match_all('/<(?:\w+:)?c[\s\/>]/', $teks);
        $this->rujukKasar += substr_count($teks, 't="s"') + substr_count($teks, "t='s'");

        if (preg_match('/<(?:\w+:)?(?:col|tablePart|si)[\s\/>]|t\s*=\s*["\']shared/', $teks) === 1) {
            $this->iterasiKolom = PHP_INT_MAX;
        }
    }

    private function salinan(): int
    {
        $jumlah     = $this->salinanRumus;
        $terpanjang = $this->panjangSi === [] ? 0 : max($this->panjangSi);

        foreach ($this->rujukSi as $indeks => $kali) {
            $jumlah = self::tambah($jumlah, self::kali($this->panjangSi[$indeks] ?? 0, $kali));
        }

        return self::tambah($jumlah, self::kali($terpanjang, $this->rujukKasar));
    }

    private static function tambah(int $a, int $b): int
    {
        return $b > PHP_INT_MAX - $a ? PHP_INT_MAX : $a + $b;
    }

    private static function kali(int $a, int $b): int
    {
        return $a !== 0 && $b > intdiv(PHP_INT_MAX, $a) ? PHP_INT_MAX : $a * $b;
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
