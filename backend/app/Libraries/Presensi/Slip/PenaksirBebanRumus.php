<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use Config\Presensi;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Calculation\Engine\Operands\StructuredReference;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\DefinedName;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Penaksir beban hitung satu sel rumus impor Slip Gaji (CR-181), dari TEKS rumusnya saja. Dipakai
 * {@see PembacaExcelSlip} sebelum `getCalculatedValue()`; nilai sel tetap hasil hitung ulang seperti legacy.
 *
 * Penaksir TIDAK menghitung, membuat, atau mengubah sel apa pun, dan tidak menyentuh kalkulator milik workbook: yang
 * dibaca dari workbook hanya teks rumus, nilai tersimpan sel yang BUKAN rumus (panjang teks dan jumlah `*`-nya), daftar
 * sheet, nama terdefinisi, rentang tabel, dan baris/kolom data tertinggi. Karena itu fungsi vendor yang memanggil
 * `Worksheet::getCell()` atau `Cell::getCalculatedValue()` (mis. `StructuredReference::parse`) tidak boleh dipakai di
 * sini (dijaga `PembacaExcelSlipAnggaranRumusTest`).
 *
 * DAFTAR PUTIH FUNGSI ({@see self::DAFTAR_PUTIH}): hanya fungsi yang biayanya sudah diukur dan dimodelkan yang boleh ada
 * di rumus. Fungsi lain apa pun membuat selnya {@see self::TAK_HINGGA}, karena biaya fungsi bisa bergantung pada NILAI
 * argumen atau membangkitkan larik dari satu sel, dan itu tidak terlihat dari teks rumus.
 *
 * Satuan beban ±5 mikrodetik hitung di mesin ukur saat tenang (tabel per fungsi di README `Libraries/Presensi` BB-12):
 *
 * - beban dasar sel = `BEBAN_DASAR_RUMUS_IMPOR` + rujukan sel × `BEBAN_RUJUKAN_RUMUS_IMPOR` + operator ×
 *   `BEBAN_OPERATOR_RUMUS_IMPOR` + kurung buka × `BEBAN_FUNGSI_RUMUS_IMPOR` + pemisah argumen ×
 *   `BEBAN_ARGUMEN_RUMUS_IMPOR` (dihitung dari karakter teks, tanpa mengurai; panjang angka dan teks literal tidak
 *   menambah beban) + panjang² / `PEMBAGI_KUADRAT_PANJANG_RUMUS_IMPOR` (biaya urai kalkulator tumbuh kuadratik menurut
 *   panjang teks); teks lebih panjang dari `MAKS_PANJANG_RUMUS_IMPOR` (batas Excel) langsung {@see self::TAK_HINGGA};
 * - tiap rentang yang dijabarkan kalkulator = `BEBAN_TETAP_RENTANG_RUMUS_IMPOR` + baris × kolom + baris² × (kolom + 1)
 *   / (2 × `PEMBAGI_KUADRAT_BARIS_RUMUS_IMPOR`): kalkulator menjabarkan rentang menjadi daftar sel, dan biayanya
 *   tumbuh kuadratik menurut jumlah baris;
 * - larik yang menjadi argumen fungsi: pembagi suku kuadratiknya menurut kelas fungsi, yaitu
 *   {@see self::FUNGSI_MURAH} dan fungsi per elemen ({@see self::FUNGSI_ELEMEN}) tetap,
 *   {@see self::FUNGSI_SEDANG} `PEMBAGI_KUADRAT_BARIS_SEDANG_RUMUS_IMPOR`, fungsi lain di daftar putih
 *   `PEMBAGI_KUADRAT_BARIS_UMUM_RUMUS_IMPOR`; untuk fungsi berkriteria luasnya dikalikan ({@see self::PENGALI_LUAS});
 * - LARIK HASIL: tiap operand membawa ukuran lariknya (baris × kolom). Operator atas larik menagih satu satuan per
 *   elemen hasil dan meneruskan ukuran itu; ukuran hasil = baris terbesar × kolom terbesar kedua operand (kalkulator
 *   memperluas keduanya, jadi kolom × baris menjadi matriks penuh). Fungsi per elemen berlaku sama; `TRANSPOSE` menukar
 *   ukuran; fungsi agregat ({@see self::FUNGSI_SKALAR}) menghasilkan satu nilai; larik literal `{…}` ditagih seperti
 *   rentang kecil per barisnya;
 * - fungsi pencarian ({@see self::FUNGSI_PENCARIAN}) dengan nilai cari berupa larik dipanggil kalkulator sekali per
 *   elemen: biaya rentang tabelnya dikalikan jumlah elemen itu;
 * - rentang kolom/baris penuh (`J:J`, `2:2`) dipangkas ke baris/kolom data tertinggi sheet rujukan, sama seperti
 *   kalkulator, ditambah jumlah sel sheet itu / `SEL_PER_BEBAN_PINDAI_RUMUS_IMPOR` (kalkulator memindai indeks sel);
 * - nama terdefinisi, nama tabel, dan rujukan terstruktur ditaksir dari isinya / rentang tabelnya (berulang, paling
 *   dalam `MAKS_KEDALAMAN_NAMA`);
 * - TEKS PANJANG: teks terpanjang di workbook (sel bernilai teks dan literal teks di rumus; {@see self::$byteSel})
 *   dibagi `BYTE_PER_BEBAN_TEKS_SEL_RUMUS_IMPOR` ditagihkan untuk tiap kurung buka, tiap operator, dan tiap elemen
 *   larik, karena fungsi teks, pembandingan, dan pencarian menyalin seluruh teks operandnya. Workbook tanpa teks 128
 *   byte atau lebih tidak terkena;
 * - teks hasil: `REPT` = byte hasil (ulang × byte teks operandnya: literal, isi sel yang dirujuk, atau teks terpanjang
 *   workbook) / `BYTE_PER_BEBAN_TEKS_RUMUS_IMPOR`; operator `&` = elemen hasil
 *   × taksiran byte tiap elemen / `BYTE_PER_BEBAN_TEKS_LARIK_RUMUS_IMPOR`; `SUBSTITUTE` = (byte sumber + byte hasil) /
 *   `BYTE_PER_BEBAN_TEKS_SEL_RUMUS_IMPOR` dengan byte hasil = sumber + kemunculan × pengganti, dan
 *   {@see self::TAK_HINGGA} bila hasil itu bisa melewati `MAKS_BYTE_HASIL_TEKS_RUMUS_IMPOR`; batas yang sama untuk
 *   hasil `REPT`, operator `&`, dan penggabung (`CONCATENATE`, `CONCAT`, `REPLACE`) (review R3 B4);
 * - TEKS OPERAND (review R3 B2/B3): `TEXT` ditagih panjang format² / `PEMBAGI_KUADRAT_FORMAT_TEKS_RUMUS_IMPOR` per
 *   panggilan (format yang pasti lebih panjang dari `MAKS_PANJANG_FORMAT_TEKS_RUMUS_IMPOR` → {@see self::TAK_HINGGA});
 *   `SEARCH`/`FIND` ditagih byte teks cari × byte teks sumber / `PEMBAGI_CARI_TEKS_RUMUS_IMPOR` per panggilan; panjang
 *   teks dari literal, isi sel bukan rumus yang dirujuk (tanpa menghitung), atau teks terpanjang workbook
 *   ({@see self::byteNilai()});
 * - OPERAND BURAM (review R3 B1): rujukan workbook eksternal dan variabel `_xleta` menjadi operand satu nilai dengan teks
 *   terpanjang workbook, lalu penaksiran berlanjut ke token sesudahnya (kalkulator bisa melewati cabang `IF` yang
 *   dipangkas lalu menghitung token sesudahnya); penaksir tidak pernah berhenti di tengah daftar token;
 * - WILDCARD: kriteria `COUNTIF`/`SUMIF`/`AVERAGEIF`/`…IFS` dan nilai cari `MATCH` bertipe cocok 0 yang memuat `*`
 *   dicocokkan kalkulator dengan pola `.*` berantai; tiap sel larik ditagih {@see self::bebanWildcard()} menurut jumlah
 *   `*`. Jumlah `*` diambil dari literal di rumus, dari isi sel bernilai yang dirujuk langsung, atau (untuk sel rumus
 *   dan hasil fungsi) dari jumlah `*` terbanyak di workbook ({@see self::$bintangBuku});
 * - {@see self::TAK_HINGGA} untuk: fungsi di luar daftar putih (termasuk `INDIRECT`, `OFFSET`, operator `#` dan `@`,
 *   pembangkit larik, fungsi yang tidak dikenal kalkulator), `REPT` berjumlah ulang bukan literal, operator `:` dengan
 *   operand hasil hitung, rujukan 3D, rumus yang tidak terurai, urutan token yang tidak terduga (tumpukan operand atau
 *   argumen kurang, jumlah argumen tidak dikenal), dan nama terdefinisi yang berputar atau terlalu dalam.
 *
 * Supaya tidak menyimpang dari kalkulator, teks rumus diurai dengan pengurai kalkulator itu sendiri
 * (`Calculation::parseFormula` pada kalkulator TERSENDIRI, diperiksa pada PhpSpreadsheet 5.10.0), lalu daftar tokennya
 * dijalankan secara abstrak: yang dilacak hanya rujukan, ukuran larik, panjang teks, dan jumlah `*` tiap operand,
 * mengikuti aturan `Calculation::processTokenStack`. Semua cabang `IF` dihitung (kalkulator bisa melewati cabang yang
 * tidak terpakai), jadi taksiran rentang tidak pernah di bawah kerja kalkulator. Pengurai dipanggil tanpa sel, sehingga
 * dua hal yang bergantung pada sel ditiru di sini: sheet bawaan rujukan tanpa nama sheet, dan pemangkasan rentang
 * kolom/baris penuh.
 *
 * JALUR CEPAT ({@see self::perluDiurai()}): rumus tanpa `:`, `[`, `#`, `{`, nama terdefinisi, dan nama tabel, yang semua
 * nama-diikuti-kurungnya ada di daftar putih dan tidak termasuk {@see self::WAJIB_DIURAI}, tidak memuat rentang atau
 * larik, jadi cukup beban dasarnya. Penaksiran berhenti begitu beban berjalan melewati batas yang diberikan pemanggil,
 * jadi penaksir sendiri tidak bisa dibuat bekerja lama.
 *
 * Yang TIDAK terlihat dari sini (README BB-11 (j)): nilai hasil rumus LAIN. Teks hasil `&` berantai lintas sel bisa lebih
 * panjang, dan memuat lebih banyak `*`, daripada teks terpanjang workbook.
 *
 * @phpstan-type Operand array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed, baris: int, kolom: int, byte: int, rentang: bool, fungsi: string|null, bintang: int|null, sel: array{0: string, 1: string}|null}
 */
final class PenaksirBebanRumus
{
    public const TAK_HINGGA = PHP_INT_MAX;

    /** Penanda operand buram hasil rujukan workbook eksternal (bukan nama fungsi kalkulator). */
    private const EKSTERNAL = '#EKSTERNAL';

    private const MAKS_KEDALAMAN_NAMA = 8;
    private const BARIS_LEMBAR        = 1_048_576;
    private const KOLOM_LEMBAR        = 'XFD';

    /** Sisi larik terbesar yang masih dihitung tanpa meluap; lebih besar langsung {@see self::TAK_HINGGA}. */
    private const MAKS_SISI_LARIK = 4_000_000;

    /** Karakter (kode ASCII) yang dihitung sebagai operator pada beban dasar: `+ - * / ^ & < > = %` dan spasi. */
    private const KODE_OPERATOR = [43, 45, 42, 47, 94, 38, 60, 62, 61, 37, 32];

    /**
     * Fungsi (huruf besar) yang biaya kuadratik larik argumennya sama dengan penjabaran biasa (terukur).
     */
    private const FUNGSI_MURAH = [
        'SUM'      => true, 'COUNT' => true, 'COUNTA' => true, 'AVERAGE' => true, 'HLOOKUP' => true, 'INDEX' => true,
        'ROWS'     => true, 'COLUMNS' => true, 'ROW' => true, 'COLUMN' => true, 'AND' => true, 'OR' => true,
        'MKMATRIX' => true, 'TRANSPOSE' => true, 'VLOOKUP' => true,
    ];

    /**
     * Fungsi yang biaya kuadratiknya di antara {@see self::FUNGSI_MURAH} dan fungsi lain (README BB-12: `SUBTOTAL` atas
     * satu kolom 10.000 baris ±2,4× `SUM`, `MAX` ±4,5×; `LOOKUP` dua vektor 2.500–10.000 baris setara `VLOOKUP` per satuan
     * dengan pembagi ini, review R3).
     */
    private const FUNGSI_SEDANG = ['SUBTOTAL' => true, 'LOOKUP' => true];

    /**
     * Pengali luas larik argumen (terukur per elemen terhadap `SUM`): fungsi berkriteria mengevaluasi kriterianya lewat
     * kalkulator untuk tiap sel.
     */
    private const PENGALI_LUAS = [
        'COUNTIF' => 6, 'COUNTIFS' => 6, 'SUMIFS' => 6, 'AVERAGEIFS' => 6, 'SUMIF' => 4, 'AVERAGEIF' => 4, 'SUBTOTAL' => 2,
        // D1-b (keputusan Horii 25-11): jalur basis data kalkulator yang sama dengan `SUMIFS`.
        'MAXIFS' => 6, 'MINIFS' => 6,
        // Pengurut (CR-181 R3 butir d): sekali urut (`sort`/`rsort`) per panggilan atas seluruh larik argumennya; dengan
        // pembagi umum, 3 = per satuan setara `MAX` pada 2.500 / 5.000 / 10.000 baris (README BB-12).
        'LARGE' => 3, 'SMALL' => 3, 'MEDIAN' => 3, 'RANK' => 3, 'RANK.EQ' => 3,
    ];

    /**
     * Fungsi agregat: seluruh larik argumennya diserap dalam satu panggilan dan hasilnya satu nilai.
     */
    private const FUNGSI_SKALAR = [
        'SUM'       => true, 'COUNT' => true, 'COUNTA' => true, 'COUNTBLANK' => true, 'AVERAGE' => true, 'MAX' => true,
        'MIN'       => true, 'SUMPRODUCT' => true, 'SUBTOTAL' => true, 'AND' => true, 'OR' => true, 'ROWS' => true,
        'COLUMNS'   => true, 'COUNTIF' => true, 'COUNTIFS' => true, 'SUMIF' => true, 'SUMIFS' => true,
        'AVERAGEIF' => true, 'AVERAGEIFS' => true, 'LARGE' => true, 'SMALL' => true, 'MEDIAN' => true, 'RANK' => true,
        'RANK.EQ'   => true,
        // D1 (keputusan Horii 25-11): agregat satu nilai; `TEXTJOIN` juga penggabung (byte hasil dijumlah, batas 4 MB).
        'PRODUCT' => true, 'MAXIFS' => true, 'MINIFS' => true, 'TEXTJOIN' => true,
    ];

    /**
     * Fungsi pencarian: argumen pertama (nilai cari) berupa larik membuat kalkulator memanggil fungsinya sekali per
     * elemen, tiap kali menelusuri larik argumen lainnya (`MATCH` 1.000 nilai ke 1.000 baris ±8 detik).
     */
    private const FUNGSI_PENCARIAN = ['MATCH' => true, 'VLOOKUP' => true, 'HLOOKUP' => true, 'LOOKUP' => true, 'XLOOKUP' => true];

    /**
     * Fungsi yang dihitung per elemen larik argumennya tanpa menelusuri larik lain (terukur ±1 satuan per elemen, tanpa
     * suku kuadratik).
     */
    private const FUNGSI_ELEMEN = [
        'IF'       => true, 'IFS' => true, 'IFERROR' => true, 'IFNA' => true, 'NOT' => true, 'TRUE' => true, 'FALSE' => true,
        'NA'       => true, 'ROUND' => true, 'ROUNDUP' => true, 'ROUNDDOWN' => true, 'INT' => true, 'TRUNC' => true,
        'ABS'      => true, 'MOD' => true, 'CEILING' => true, 'FLOOR' => true, 'LEN' => true, 'LEFT' => true,
        'RIGHT'    => true, 'MID' => true, 'TEXT' => true, 'VALUE' => true, 'UPPER' => true, 'LOWER' => true,
        'PROPER'   => true, 'TRIM' => true, 'SUBSTITUTE' => true, 'REPLACE' => true, 'REPT' => true,
        'ISNUMBER' => true, 'ISTEXT' => true, 'ISBLANK' => true, 'ISERROR' => true, 'ISNA' => true, 'DATE' => true,
        'YEAR'     => true, 'MONTH' => true, 'DAY' => true, 'ROW' => true, 'COLUMN' => true,
        // CR-181 R3 butir d: pencarian teks tanpa wildcard (`mb_stripos`/`mb_strpos`) dan tanggal (masa kerja).
        'SEARCH' => true, 'FIND' => true, 'DATEDIF' => true, 'EDATE' => true, 'EOMONTH' => true,
        // D1-a/D1-c (keputusan Horii 25-11): skalar per elemen; `EXACT` menagih teks lewat beban teks panjang; `TODAY`/`NOW`
        // bernilai tanggal impor (sama dengan legacy).
        'MROUND'    => true, 'N' => true, 'POWER' => true, 'SQRT' => true, 'SIGN' => true, 'ISERR' => true,
        'ISLOGICAL' => true, 'EXACT' => true, 'DAYS' => true, 'WEEKDAY' => true, 'TODAY' => true, 'NOW' => true,
    ];

    /** Fungsi penggabung teks: byte hasil = jumlah byte semua elemen argumennya. */
    private const FUNGSI_GABUNG = ['CONCATENATE' => true, 'CONCAT' => true, 'REPLACE' => true, 'TEXTJOIN' => true];

    /**
     * DAFTAR PUTIH: hanya fungsi ini yang boleh ada di rumus GPP/TK. Tiap fungsi punya kelas biaya yang diukur (README
     * `Libraries/Presensi` BB-12), termasuk dengan argumen bernilai ekstrem dan teks 32.767 huruf. Fungsi lain apa pun
     * (juga yang tidak dikenal kalkulator, yang diurainya sebagai `NAME.ERROR`, dan yang belum diimplementasikan
     * kalkulator) membuat sel itu {@see self::TAK_HINGGA}: biayanya bisa bergantung pada NILAI argumen (mis. `CRITBINOM`,
     * `CUMIPMT`, `MODE`) atau membangkitkan larik dari satu sel (`TEXTSPLIT`, `SEQUENCE`), dan itu tidak terlihat dari
     * teks rumus. `MKMATRIX` = larik literal `{…}` (fungsi dalam kalkulator).
     */
    private const DAFTAR_PUTIH = self::FUNGSI_SKALAR + self::FUNGSI_PENCARIAN + self::FUNGSI_ELEMEN + self::FUNGSI_GABUNG
        + ['INDEX' => true, 'TRANSPOSE' => true, 'MKMATRIX' => true];

    /**
     * Fungsi daftar putih yang rumusnya wajib diurai walau tanpa rentang: biayanya bergantung pada isi argumen yang hanya
     * terlihat sesudah diurai (jumlah ulang `REPT`, teks pengganti `SUBSTITUTE`, wildcard di kriteria).
     */
    private const WAJIB_DIURAI = [
        'REPT'   => true, 'SUBSTITUTE' => true, 'REPLACE' => true, 'COUNTIF' => true, 'COUNTIFS' => true, 'SUMIF' => true,
        'SUMIFS' => true, 'AVERAGEIF' => true, 'AVERAGEIFS' => true, 'MATCH' => true, 'MKMATRIX' => true, 'XLOOKUP' => true,
        // CR-181 R4: biaya menurut panjang teks operandnya (format `TEXT`, teks cari/sumber `SEARCH`/`FIND`).
        'TEXT' => true, 'SEARCH' => true, 'FIND' => true, 'MAXIFS' => true, 'MINIFS' => true, 'TEXTJOIN' => true,
    ];

    /**
     * Letak argumen kriteria (yang bisa berwildcard) fungsi berkriteria: [indeks pertama, langkah].
     */
    private const LETAK_KRITERIA = [
        'COUNTIF' => [1, 0], 'SUMIF' => [1, 0], 'AVERAGEIF' => [1, 0], 'COUNTIFS' => [1, 2], 'SUMIFS' => [2, 2], 'AVERAGEIFS' => [2, 2],
        'MAXIFS'  => [2, 2], 'MINIFS' => [2, 2],
    ];

    private const OPERATOR_BINER = [
        '+' => true, '-' => true, '*' => true, '/' => true, '^' => true, '&' => true, '>' => true, '<' => true,
        '=' => true, '>=' => true, '<=' => true, '<>' => true, '∩' => true, '∪' => true, ':' => true,
    ];

    private const POLA_SEL    = '/^' . Calculation::CALCULATION_REGEXP_CELLREF . '$/i';
    private const POLA_FUNGSI = '/^' . Calculation::CALCULATION_REGEXP_FUNCTION . '$/miu';
    private const POLA_NAMA   = '/^' . Calculation::CALCULATION_REGEXP_DEFINEDNAME . '$/miu';
    private const POLA_KATA   = '/[\p{L}_\\\\][\p{L}\p{N}_.\\\\]*/u';

    /** Nama yang diikuti kurung buka = pemanggilan fungsi (awalan `_xlfn.` / `_xlws.` dibuang, seperti kalkulator). */
    private const POLA_PANGGILAN = '/(?:_xlfn\.)?(?:_xlws\.)?([\p{L}_][\p{L}\p{N}_.]*)\s*\(/u';

    /** Literal teks di dalam rumus (`""` = tanda kutip di dalam teks). */
    private const POLA_LITERAL = '/"(?:[^"]|"")*"/';

    private readonly Calculation $pengurai;

    /**
     * Nama terdefinisi dan nama tabel (huruf kecil): rumus yang memuat salah satunya wajib diurai.
     *
     * @var array<string, true>
     */
    private array $kataBerat = [];

    /**
     * Batas atas byte teks satu nilai sel: teks terpanjang di workbook (sel bernilai teks dan literal teks di rumus),
     * paling sedikit `BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR`. Teks hasil rumus lain yang lebih panjang dari ini (hasil `&`
     * berantai lintas sel) tidak terlihat dari sini (README BB-11 (j)).
     */
    private int $byteSel = Presensi::BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR;

    /** Beban tambahan tiap operasi atas teks = {@see self::$byteSel} / `BYTE_PER_BEBAN_TEKS_SEL_RUMUS_IMPOR`. */
    private int $bebanTeks = 0;

    /**
     * Jumlah `*` terbanyak di satu nilai teks workbook (sel bernilai teks, literal di satu rumus atau nama terdefinisi):
     * anggapan untuk kriteria yang isinya tidak terlihat dari teks rumus (rujukan ke sel rumus, hasil fungsi).
     */
    private int $bintangBuku = 0;

    /**
     * @var array<string, int> judul sheet => baris data tertinggi
     */
    private array $barisTertinggi = [];

    /**
     * @var array<string, string> "judul sheet|baris" => kolom data tertinggi baris itu
     */
    private array $kolomTertinggi = [];

    /**
     * Beban sel yang sedang ditaksir sejauh ini, dan batasnya.
     */
    private int $berjalan = 0;

    private int $batas = self::TAK_HINGGA;

    /**
     * [baris, kolom] rentang terluas yang dicatat di tingkat nama terdefinisi yang sedang dijalankan.
     *
     * @var array{0: int, 1: int}
     */
    private array $terbesar = [1, 1];

    public function __construct(private readonly Spreadsheet $buku)
    {
        // Kalkulator tersendiri, hanya untuk mengurai: keadaan kalkulator milik workbook tidak tersentuh.
        $this->pengurai = new Calculation($buku);

        foreach ($buku->getDefinedNames() as $nama) {
            $this->kataBerat[mb_strtolower($nama->getName())] = true;
            $this->catatLiteral($nama->getValue());
        }

        foreach ($buku->getAllSheets() as $lembar) {
            foreach ($lembar->getTableCollection() as $tabel) {
                $this->kataBerat[mb_strtolower($tabel->getName())] = true;
            }

            // Hanya membaca isi sel yang sudah ada (tidak membuat sel, tidak menghitung).
            $sel = $lembar->getCellCollection();

            foreach ($sel->getCoordinates() as $koordinat) {
                $s     = $sel->get($koordinat);
                $nilai = $s?->getValue();

                if ($nilai instanceof RichText) {
                    $nilai = $nilai->getPlainText();
                }

                if ($s === null || ! is_string($nilai)) {
                    continue;
                }

                if ($s->getDataType() === DataType::TYPE_FORMULA) {
                    $this->catatLiteral($nilai);

                    continue;
                }

                $this->byteSel = max($this->byteSel, strlen($nilai));

                if (str_contains($nilai, '*')) {
                    $this->bintangBuku = max($this->bintangBuku, substr_count($nilai, '*'));
                }
            }
        }

        $this->bebanTeks = intdiv($this->byteSel, Presensi::BYTE_PER_BEBAN_TEKS_SEL_RUMUS_IMPOR);
    }

    /**
     * Literal teks di dalam satu rumus (sel atau nama terdefinisi): nilai sel itu bisa sepanjang literal terpanjangnya dan
     * memuat semua `*` di literalnya.
     */
    private function catatLiteral(string $rumus): void
    {
        $bintang = str_contains($rumus, '*');

        if (! str_contains($rumus, '"') || (! $bintang && strlen($rumus) <= $this->byteSel)) {
            return;
        }

        if (preg_match_all(self::POLA_LITERAL, $rumus, $literal) > 0) {
            $this->byteSel = max($this->byteSel, max(array_map(strlen(...), $literal[0])) - 2);

            if ($bintang) {
                $this->bintangBuku = max($this->bintangBuku, substr_count(implode('', $literal[0]), '*'));
            }
        }
    }

    /**
     * Beban hitung sel rumus `$sel`. {@see self::TAK_HINGGA} bila melewati `$batas` (penaksiran berhenti saat itu juga)
     * atau tidak bisa dibatasi dari teksnya.
     */
    public function beban(Cell $sel, int $batas = self::TAK_HINGGA): int
    {
        $rumus = $sel->getValue();

        if (! is_string($rumus)) {
            return Presensi::BEBAN_DASAR_RUMUS_IMPOR;
        }

        $this->berjalan = 0;
        $this->batas    = $batas;
        $this->terbesar = [1, 1];

        try {
            $this->pakai($this->bebanDasar($rumus));

            if ($this->perluDiurai($rumus)) {
                $this->jalankan($rumus, $sel->getWorksheet(), $sel, 0);
            }
        } catch (Throwable) {
            // Lewat batas, tidak terurai, dinamis, atau nama terdefinisi terlalu dalam/berputar: salah ke arah menolak.
            return self::TAK_HINGGA;
        }

        return $this->berjalan;
    }

    /**
     * Beban dasar satu teks rumus (sel atau isi nama terdefinisi), dihitung dari karakternya tanpa mengurai.
     *
     * @throws RuntimeException teks lebih panjang dari batas Excel
     */
    private function bebanDasar(string $rumus): int
    {
        $panjang = strlen($rumus);

        if ($panjang > Presensi::MAKS_PANJANG_RUMUS_IMPOR) {
            throw new RuntimeException('Rumus terlalu panjang.');
        }

        /** @var array<int, int> $hitung */
        $hitung   = count_chars($rumus, 1);
        $operator = 0;

        foreach (self::KODE_OPERATOR as $kode) {
            $operator += $hitung[$kode] ?? 0;
        }

        return Presensi::BEBAN_DASAR_RUMUS_IMPOR
            + Presensi::BEBAN_RUJUKAN_RUMUS_IMPOR * (int) preg_match_all('/[A-Za-z]\$?\d/', $rumus)
            + Presensi::BEBAN_OPERATOR_RUMUS_IMPOR * $operator
            + Presensi::BEBAN_FUNGSI_RUMUS_IMPOR * ($hitung[40] ?? 0)
            + $this->bebanTeks * ($operator + ($hitung[40] ?? 0))
            + Presensi::BEBAN_ARGUMEN_RUMUS_IMPOR * (($hitung[44] ?? 0) + ($hitung[59] ?? 0))
            + intdiv($panjang * $panjang, Presensi::PEMBAGI_KUADRAT_PANJANG_RUMUS_IMPOR);
    }

    /**
     * @throws RuntimeException beban berjalan melewati batas
     */
    private function pakai(int $beban): void
    {
        $this->berjalan = $this->berjalan > self::TAK_HINGGA - $beban ? self::TAK_HINGGA : $this->berjalan + $beban;

        if ($this->berjalan > $this->batas) {
            throw new RuntimeException('Beban rumus melewati batas.');
        }
    }

    /**
     * Perkalian dua bilangan tak negatif yang berhenti di {@see self::TAK_HINGGA}.
     */
    private static function kali(int $a, int $b): int
    {
        return $a > 0 && $b > intdiv(self::TAK_HINGGA, $a) ? self::TAK_HINGGA : $a * $b;
    }

    /**
     * Suku kuadratik larik `$baris` × `$kolom` untuk pembagi kelas `$pembagi`.
     */
    private static function kuadrat(int $baris, int $kolom, int $pembagi): int
    {
        if ($baris > self::MAKS_SISI_LARIK || $kolom > self::MAKS_SISI_LARIK) {
            return self::TAK_HINGGA;
        }

        return intdiv(self::kali($baris * $baris, $kolom + 1), 2 * $pembagi);
    }

    /**
     * Rumus tanpa `:`, `[`, `#`, `{`, nama terdefinisi, dan nama tabel tidak memuat rentang atau larik; bila semua
     * fungsinya ada di daftar putih dan tidak ada yang wajib diurai, beban dasarnya sudah cukup (jalur cepat). Nama
     * fungsi dicari di teks mentah (nama diikuti kurung buka), jadi teks literal seperti `"Gaji (bruto)"` hanya membuat
     * rumusnya ikut diurai; yang memutuskan ditolak atau tidak adalah token fungsi hasil pengurai kalkulator.
     */
    private function perluDiurai(string $rumus): bool
    {
        if (strpbrk($rumus, ':[#{') !== false) {
            return true;
        }

        if (str_contains($rumus, '(')) {
            if (preg_match_all(self::POLA_PANGGILAN, $rumus, $panggilan) === false) {
                return true;
            }

            foreach ($panggilan[1] as $f) {
                $f = strtoupper($f);

                if (! isset(self::DAFTAR_PUTIH[$f]) || isset(self::WAJIB_DIURAI[$f])) {
                    return true;
                }
            }
        }

        if ($this->kataBerat === []) {
            return false;
        }

        if (preg_match_all(self::POLA_KATA, $rumus, $kata) === false) {
            return true;
        }

        foreach ($kata[0] as $k) {
            $k     = mb_strtolower($k);
            $titik = strrpos($k, '.');

            if (isset($this->kataBerat[$k]) || ($titik !== false && isset($this->kataBerat[substr($k, $titik + 1)]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Menjalankan token `$rumus` secara abstrak di sheet `$konteks` dan mencatat beban semua rentang yang akan dijabarkan
     * kalkulator serta semua larik yang dihasilkan operator dan fungsi ({@see self::pakai()}).
     *
     * @return array<string, mixed>|null operand hasil rumus; `null` bila daftar tokennya kosong. Penaksiran tidak pernah
     *                                   berhenti di tengah daftar token (CR-181 R4 B1): token yang nilainya tidak terlihat
     *                                   menjadi operand buram, urutan token yang tidak terduga → {@see self::TAK_HINGGA}
     *
     * @phpstan-return Operand|null
     *
     * @throws Throwable rumus tidak terurai, kerjanya tidak bisa dibatasi, atau beban melewati batas
     */
    private function jalankan(string $rumus, Worksheet $konteks, ?Cell $sel, int $kedalaman): ?array
    {
        $token = $this->pengurai->parseFormula($rumus);

        if (! is_array($token)) {
            throw new RuntimeException('Rumus tidak terurai.');
        }

        $token    = array_values($token);
        $tumpukan = [];

        foreach ($token as $i => $t) {
            $t     = is_array($t) ? $t : [];
            $nilai = $t['value'] ?? null;

            if ($nilai instanceof StructuredReference) {
                $tumpukan[] = $this->rujukanTerstruktur($nilai->value(), $konteks, $sel);

                continue;
            }

            if (is_string($nilai) && isset(self::OPERATOR_BINER[$nilai])) {
                $kanan = array_pop($tumpukan);
                $kiri  = array_pop($tumpukan);

                // Tumpukan kurang = urutan token yang tidak terduga: tidak bisa dibatasi (CR-181 R4 B1).
                if ($kanan === null || $kiri === null) {
                    throw new RuntimeException('Tumpukan operand kurang.');
                }

                $tumpukan[] = $nilai === ':' ? $this->rentang($kiri, $kanan, $konteks) : $this->operator($nilai, $kiri, $kanan);

                continue;
            }

            if ($nilai === '~' || $nilai === '%') {
                $operand = array_pop($tumpukan);

                if ($operand === null) {
                    throw new RuntimeException('Tumpukan operand kurang.');
                }

                $tumpukan[] = $this->operator($nilai, $operand, $operand);

                continue;
            }

            if (is_string($nilai) && preg_match(self::POLA_SEL, $nilai, $m) === 1) {
                $sheet = trim((string) $m[2], "\"'");

                // Rujukan ke workbook lain (`[1]Sheet!A1`): nilainya dari luar workbook ini. Kalkulator bisa melewati token
                // ini (cabang `IF` yang dipangkas) lalu memproses token sesudahnya, jadi penaksir TIDAK berhenti: operand
                // buram satu nilai, lalu lanjut (CR-181 R4 B1).
                if (str_contains($sheet, '[') || str_contains($sheet, ']')) {
                    $tumpukan[] = $this->buram(true);

                    continue;
                }

                $kolom = strtoupper($m[6]);
                $baris = $m[7];
                $jenis = $t['type'] ?? '';

                if ($jenis === 'Column Reference' && (int) $baris === self::BARIS_LEMBAR) {
                    $rujuk = $this->sheetPangkas($token[$i - 1] ?? null, $konteks);

                    if ($rujuk !== null) {
                        $baris = (string) $this->barisTertinggi($rujuk);
                        $this->pakai(self::bebanPindai($rujuk));
                    }
                } elseif ($jenis === 'Row Reference' && $kolom === self::KOLOM_LEMBAR) {
                    $rujuk = $this->sheetPangkas($token[$i - 1] ?? null, $konteks);

                    if ($rujuk !== null) {
                        $kolom = $this->kolomTertinggi($rujuk, (int) $baris);
                        $this->pakai(self::bebanPindai($rujuk));
                    }
                }

                // Sheet yang tidak ada: kalkulator memakai rujukan tanpa sheet (= sheet sel yang dihitung).
                $ada                = $sheet !== '' && $this->buku->getSheetByName($sheet) !== null;
                $rujukan            = $this->operand([$kolom . $baris], $ada ? $sheet : null);
                $rujukan['sel']     = [$ada ? $sheet : $konteks->getTitle(), $kolom . $baris];
                $rujukan['bintang'] = null;
                $tumpukan[]         = $rujukan;

                continue;
            }

            if (is_string($nilai) && preg_match(self::POLA_FUNGSI, $nilai, $m) === 1) {
                $fungsi = strtoupper($m[1]);

                if (! isset(self::DAFTAR_PUTIH[$fungsi])) {
                    throw new RuntimeException('Fungsi di luar daftar putih.');
                }

                $jumlah = array_pop($tumpukan)['nilai'] ?? null;

                if (! is_int($jumlah)) {
                    throw new RuntimeException('Jumlah argumen fungsi tidak dikenal.');
                }

                $argumen = [];

                for ($a = 0; $a < $jumlah; $a++) {
                    $x = array_pop($tumpukan);

                    if ($x === null) {
                        throw new RuntimeException('Argumen fungsi kurang.');
                    }

                    $argumen[] = $x;
                }

                $tumpukan[] = $this->fungsi($fungsi, array_reverse($argumen));

                continue;
            }

            if (is_string($nilai) && $nilai !== '' && ! is_numeric($nilai) && ! Calculation::keyInExcelConstants(strtoupper($nilai))
                && $nilai[0] !== '"' && $nilai[0] !== '#' && preg_match(self::POLA_NAMA, $nilai, $m) === 1) {
                // Nama `_xleta…` (variabel `LET`): operand buram satu nilai, lalu lanjut ke token sesudahnya (CR-181 R4 B1).
                if (str_starts_with($m[6], '_xleta')) {
                    $tumpukan[] = $this->buram();

                    continue;
                }

                $tumpukan[] = $this->nama($m[6], trim($m[2], "'"), $konteks, $kedalaman);

                continue;
            }

            // Angka, teks, konstanta, galat, argumen kosong, dan jumlah argumen fungsi.
            $teks               = is_string($nilai) && str_starts_with($nilai, '"');
            $literal            = $this->operand(is_string($t['reference'] ?? null) ? [$t['reference']] : null);
            $literal['nilai']   = $nilai;
            $literal['byte']    = $teks ? max(0, strlen($nilai) - 2) : Presensi::BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR;
            $literal['bintang'] = $teks ? substr_count($nilai, '*') : 0;
            $tumpukan[]         = $literal;
        }

        return array_pop($tumpukan);
    }

    /**
     * Operator selain `:` (`executeNumericBinaryOperation`, perbandingan, `&`, negasi, persen): bila salah satu operand
     * berupa larik, kalkulator memperluas keduanya ke baris terbesar × kolom terbesar lalu mengulang tiap elemen.
     *
     * @param array<string, mixed> $kiri
     * @param array<string, mixed> $kanan
     *
     * @phpstan-param Operand $kiri
     * @phpstan-param Operand $kanan
     *
     * @return array<string, mixed>
     *
     * @phpstan-return Operand
     */
    private function operator(string $operator, array $kiri, array $kanan): array
    {
        // Gabungan (`,` di dalam rujukan): isi kedua operand disatukan.
        $baris  = $operator === '∪' ? $kiri['baris'] + $kanan['baris'] : max($kiri['baris'], $kanan['baris']);
        $kolom  = max($kiri['kolom'], $kanan['kolom']);
        $elemen = self::elemen($baris, $kolom);
        $byte   = Presensi::BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR;

        $bintang = 0;

        if ($elemen > 1) {
            // Tiap elemen dibandingkan atau digabung sebagai teks (beban dasar sudah menagih satu elemen per operator).
            $this->pakai(self::kali($elemen, 1 + $this->bebanTeks));
        }

        if ($operator === '&') {
            $byte = self::tambah($kiri['byte'], $kanan['byte']);

            // Kalkulator menyusun teks hasil dulu, baru memeriksa batas 32.767 huruf (CR-181 R4 B4).
            if ($byte > Presensi::MAKS_BYTE_HASIL_TEKS_RUMUS_IMPOR) {
                throw new RuntimeException('Hasil & terlalu besar.');
            }


            $bintang = self::tambah($this->bintang($kiri), $this->bintang($kanan));
            $this->pakai(self::kali($elemen, intdiv($byte, Presensi::BYTE_PER_BEBAN_TEKS_LARIK_RUMUS_IMPOR)));
        }

        $hasil            = $this->larik($baris, $kolom, $byte);
        $hasil['bintang'] = $bintang;

        return $hasil;
    }

    /**
     * Pemanggilan fungsi: biaya tambahan atas larik argumennya menurut kelas fungsi, jumlah panggilan bila kalkulator
     * memanggilnya per elemen, ukuran teks hasil `REPT`, dan ukuran larik hasilnya.
     *
     * @param list<array<string, mixed>> $argumen
     *
     * @phpstan-param list<Operand> $argumen
     *
     * @return array<string, mixed>
     *
     * @phpstan-return Operand
     */
    private function fungsi(string $fungsi, array $argumen): array
    {
        $perElemen = isset(self::FUNGSI_ELEMEN[$fungsi]);
        $pengali   = self::PENGALI_LUAS[$fungsi] ?? 1;
        $pembagi   = match (true) {
            isset(self::FUNGSI_MURAH[$fungsi]) || $perElemen => Presensi::PEMBAGI_KUADRAT_BARIS_RUMUS_IMPOR,
            isset(self::FUNGSI_SEDANG[$fungsi])              => Presensi::PEMBAGI_KUADRAT_BARIS_SEDANG_RUMUS_IMPOR,
            default                                          => Presensi::PEMBAGI_KUADRAT_BARIS_UMUM_RUMUS_IMPOR,
        };

        // [baris, kolom] larik hasil, dan argumen yang ditelusuri ulang pada tiap panggilan per elemen.
        $hasil     = [1, 1];
        $ditelusur = [];
        $sekali    = true;
        $larik     = array_values(array_filter($argumen, static fn (array $a): bool => self::elemen($a['baris'], $a['kolom']) > 1));

        if (isset(self::FUNGSI_SKALAR[$fungsi])) {
            // Satu panggilan, satu nilai.
        } elseif (isset(self::FUNGSI_PENCARIAN[$fungsi])) {
            $cari      = $argumen[0] ?? null;
            $hasil     = $cari === null ? [1, 1] : [$cari['baris'], $cari['kolom']];
            $ditelusur = array_slice($argumen, 1);

            $sekali = false;
        } elseif ($fungsi === 'INDEX') {
            $hasil = self::ukuranIndex($argumen);
        } elseif ($fungsi === 'TRANSPOSE') {
            $hasil = isset($argumen[0]) ? [$argumen[0]['kolom'], $argumen[0]['baris']] : [1, 1];
        } elseif ($fungsi === 'MKMATRIX') {
            // Larik literal `{1,2;3,4}` = MKMATRIX(MKMATRIX(1,2),MKMATRIX(3,4)): yang luar berisi baris-barisnya.
            // Tiap penyusunan baris/larik literal terukur ±100 mikrodetik, seperti menjabarkan satu rentang kecil.
            $this->pakai(Presensi::BEBAN_TETAP_RENTANG_RUMUS_IMPOR);
            $luar  = $argumen !== [] && array_filter($argumen, static fn (array $a): bool => $a['fungsi'] !== 'MKMATRIX') === [];
            $hasil = $luar ? [count($argumen), max(array_column($argumen, 'kolom'))] : [1, max(1, count($argumen))];
        } else {
            // Fungsi per elemen dan penggabung teks: satu panggilan per elemen larik hasil siaran argumennya.
            $hasil  = self::siaran($argumen);
            $sekali = false;
        }

        $panggilan = $sekali ? 1 : self::elemen($hasil[0], $hasil[1]);

        foreach ($larik as $a) {
            $kuadrat = self::kuadrat($a['baris'], $a['kolom'], $pembagi);

            // Rentang biasa sudah ditagih suku kuadratik kelas murah saat dijabarkan.
            if ($a['rentang']) {
                $kuadrat = max(0, $kuadrat - self::kuadrat($a['baris'], $a['kolom'], Presensi::PEMBAGI_KUADRAT_BARIS_RUMUS_IMPOR));
            } elseif ($perElemen) {
                $kuadrat = 0;
            }

            $this->pakai($kuadrat);
            $this->pakai(self::kali($pengali - 1 + $this->bebanTeks, self::elemen($a['baris'], $a['kolom'])));
        }

        if ($panggilan > 1) {
            $this->pakai(self::kali($panggilan, 1 + $this->bebanTeks));

            foreach ($ditelusur as $a) {
                $luas = self::elemen($a['baris'], $a['kolom']);

                if ($luas > 1) {
                    $this->pakai(self::kali($panggilan - 1, self::tambah(self::kali($luas, $pengali + $this->bebanTeks), self::kuadrat($a['baris'], $a['kolom'], $pembagi))));
                }
            }
        }

        $this->wildcard($fungsi, $argumen, $larik, $panggilan);

        $byte    = Presensi::BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR;
        $bintang = 0;

        foreach ($argumen as $a) {
            if (isset(self::FUNGSI_GABUNG[$fungsi])) {
                $elemen  = self::elemen($a['baris'], $a['kolom']);
                $byte    = self::tambah($byte, self::kali($a['byte'], $elemen));
                $bintang = self::tambah($bintang, self::kali($this->bintang($a), $elemen));
            } else {
                $byte    = max($byte, $a['byte']);
                $bintang = max($bintang, $this->bintang($a));
            }
        }

        // `SUBSTITUTE` menyusun hasilnya (`str_replace`) sebelum memeriksa panjang: tiap kemunculan teks lama diganti.
        if ($fungsi === 'SUBSTITUTE' && count($argumen) >= 3) {
            $lama   = $argumen[1]['nilai'];
            $sumber = $argumen[0]['byte'];
            $muncul = intdiv($sumber, is_string($lama) && str_starts_with($lama, '"') ? max(1, strlen($lama) - 2) : 1);
            $byte   = self::tambah($sumber, self::kali($muncul, $argumen[2]['byte']));

            if ($byte > Presensi::MAKS_BYTE_HASIL_TEKS_RUMUS_IMPOR) {
                throw new RuntimeException('Hasil SUBSTITUTE terlalu besar.');
            }

            $bintang = self::tambah($this->bintang($argumen[0]), self::kali($muncul, $this->bintang($argumen[2])));
            $this->pakai(self::kali($panggilan, intdiv($sumber + $byte, Presensi::BYTE_PER_BEBAN_TEKS_SEL_RUMUS_IMPOR)));
        }

        // `TEXT` menafsirkan kode formatnya dengan biaya kuadratik menurut panjangnya (CR-181 R4 B2).
        if ($fungsi === 'TEXT' && isset($argumen[1])) {
            [$format, $pasti] = $this->byteNilai($argumen[1]);

            if ($pasti && $format > Presensi::MAKS_PANJANG_FORMAT_TEKS_RUMUS_IMPOR) {
                throw new RuntimeException('Format TEXT melewati batas Excel.');
            }

            $this->pakai(self::kali($panggilan, intdiv(self::kali($format, $format), Presensi::PEMBAGI_KUADRAT_FORMAT_TEKS_RUMUS_IMPOR)));
        }

        // `SEARCH`/`FIND`: kasus terburuk ±byte teks cari × byte teks sumber per panggilan (CR-181 R4 B3).
        if (($fungsi === 'SEARCH' || $fungsi === 'FIND') && isset($argumen[1])) {
            $this->pakai(self::kali($panggilan, intdiv(self::kali($this->byteNilai($argumen[0])[0], $this->byteNilai($argumen[1])[0]), Presensi::PEMBAGI_CARI_TEKS_RUMUS_IMPOR)));
        }

        // `TEXTJOIN(pemisah, abaikan, teks…)`: pemisah disisipkan di antara SEMUA elemen teks, bukan sekali.
        if ($fungsi === 'TEXTJOIN' && isset($argumen[0])) {
            $elemenTeks = 0;

            foreach (array_slice($argumen, 2) as $a) {
                $elemenTeks = self::tambah($elemenTeks, self::elemen($a['baris'], $a['kolom']));
            }

            $byte = self::tambah($byte, self::kali($this->byteNilai($argumen[0])[0], $elemenTeks));
        }

        // Penggabung teks: kalkulator menyusun hasilnya dulu, baru memeriksa batas 32.767 huruf (CR-181 R4 B4).
        if (isset(self::FUNGSI_GABUNG[$fungsi]) && $byte > Presensi::MAKS_BYTE_HASIL_TEKS_RUMUS_IMPOR) {
            throw new RuntimeException('Hasil penggabung teks terlalu besar.');
        }

        // `REPT` memanggil `str_repeat` sebelum memeriksa panjang hasil: jumlah ulang yang bukan literal tidak terbatas.
        if ($fungsi === 'REPT' && count($argumen) === 2) {
            $ulang = $argumen[1]['nilai'];

            if (! is_int($ulang) && ! is_float($ulang)) {
                throw new RuntimeException('REPT berjumlah ulang bukan literal.');
            }

            // Teks yang diulang = byte operandnya sendiri (literal, isi sel yang dirujuk, atau teks terpanjang workbook),
            // bukan anggapan 32 byte (CR-181 R4 B4: teks sel 6.000 huruf × 90.000 = 540 MB fatal).
            $satu    = max(1, $this->byteNilai($argumen[0])[0]);
            $panjang = max(0.0, (float) $ulang) * $satu;
            $byte    = $panjang >= (float) self::TAK_HINGGA ? self::TAK_HINGGA : (int) $panjang;

            if ($byte > Presensi::MAKS_BYTE_HASIL_TEKS_RUMUS_IMPOR) {
                throw new RuntimeException('Hasil REPT terlalu besar.');
            }
            $bintang = self::kali($this->bintang($argumen[0]), (int) min(max(0.0, (float) $ulang), 1e9));

            $this->pakai(self::kali($panggilan, intdiv($byte, Presensi::BYTE_PER_BEBAN_TEKS_RUMUS_IMPOR)));
        }

        // XLOOKUP dengan returnArray beberapa kolom mengembalikan satu baris per nilai cari (jumlah panggilan tetap).
        if ($fungsi === 'XLOOKUP' && isset($argumen[2]) && $argumen[2]['kolom'] > 1) {
            $hasil = [$hasil[0], self::kali($hasil[1], $argumen[2]['kolom'])];
        }

        $operand            = $this->larik($hasil[0], $hasil[1], $byte);
        $operand['fungsi']  = $fungsi;
        $operand['bintang'] = $bintang;

        return $operand;
    }

    /**
     * Kriteria berwildcard (`COUNTIF`, `SUMIF`, `AVERAGEIF`, versi `…IFS`, dan `MATCH` bertipe cocok 0): kalkulator
     * mencocokkan tiap sel larik dengan pola `.*` berantai, dan biayanya ditentukan jumlah `*` di kriteria, bukan luas
     * lariknya saja ({@see self::bebanWildcard()}).
     *
     * @param list<array<string, mixed>> $argumen
     * @param list<array<string, mixed>> $larik
     *
     * @phpstan-param list<Operand> $argumen
     * @phpstan-param list<Operand> $larik
     */
    private function wildcard(string $fungsi, array $argumen, array $larik, int $panggilan): void
    {
        $kriteria = [];

        if (isset(self::LETAK_KRITERIA[$fungsi])) {
            [$awal, $langkah] = self::LETAK_KRITERIA[$fungsi];

            for ($i = $awal; isset($argumen[$i]); $i += $langkah) {
                $kriteria[] = $argumen[$i];

                if ($langkah === 0) {
                    break;
                }
            }
        } elseif ($fungsi === 'XLOOKUP' && isset($argumen[0])) {
            // match_mode bawaan 0; hanya 2 yang memakai wildcard (`XLookup::wildcardMatch`). Bukan angka literal dianggap 2.
            $mode = $argumen[4]['nilai'] ?? 0;

            if (! ((is_int($mode) || is_float($mode)) && (float) $mode !== 2.0)) {
                $kriteria[] = $argumen[0];
            }
        } elseif ($fungsi === 'MATCH' && isset($argumen[0])) {
            // Tipe cocok bawaan 1; hanya tipe 0 yang memakai wildcard. Tipe yang bukan angka literal dianggap 0.
            $tipe = $argumen[2]['nilai'] ?? 1;

            if (! ((is_int($tipe) || is_float($tipe)) && (float) $tipe !== 0.0)) {
                $kriteria[] = $argumen[0];
            }
        }

        $bintang = 0;
        $banyak  = 1;

        foreach ($kriteria as $k) {
            $bintang = max($bintang, $this->bintang($k));
            $banyak  = max($banyak, self::elemen($k['baris'], $k['kolom']));
        }

        if ($bintang === 0) {
            return;
        }

        $luas = 0;

        foreach ($larik as $a) {
            $luas = self::tambah($luas, self::elemen($a['baris'], $a['kolom']));
        }

        $this->pakai(self::kali(self::kali(max(1, $luas), max($panggilan, $banyak)), $this->bebanWildcard($bintang)));
    }

    /**
     * Beban mencocokkan SATU sel dengan kriteria ber-`$bintang` tanda `*`: langkah penelusuran balik ±panjang teks
     * pangkat jumlah bintang / faktorial jumlah bintang, berhenti di batas mesin PCRE; teks panjang menambah biaya tiap
     * langkah.
     */
    private function bebanWildcard(int $bintang): int
    {
        $langkah = 1.0;
        $batas   = (float) (Presensi::MAKS_BEBAN_WILDCARD_RUMUS_IMPOR * Presensi::LANGKAH_PER_BEBAN_WILDCARD_RUMUS_IMPOR);

        for ($i = 1; $i <= $bintang && $langkah < $batas; $i++) {
            $langkah *= $this->byteSel / $i;
        }

        return Presensi::BEBAN_TETAP_WILDCARD_RUMUS_IMPOR
            + (int) (min($langkah, $batas) / Presensi::LANGKAH_PER_BEBAN_WILDCARD_RUMUS_IMPOR)
            + ($this->byteSel > 256 ? intdiv($this->byteSel, 4) : 0);
    }

    /**
     * Batas atas jumlah `*` di nilai operand. Literal dan hasil operator/fungsi sudah membawanya; rujukan satu sel
     * dibaca dari isi sel itu bila bukan rumus (membaca nilai tersimpan, tanpa menghitung); selain itu (sel rumus,
     * rentang, nama) dipakai anggapan workbook {@see self::$bintangBuku}.
     *
     * @param array<string, mixed> $operand
     *
     * @phpstan-param Operand $operand
     */
    private function bintang(array $operand): int
    {
        if ($operand['bintang'] !== null) {
            return $operand['bintang'];
        }

        if ($this->bintangBuku === 0 || $operand['sel'] === null) {
            return $this->bintangBuku;
        }

        $sel = $this->buku->getSheetByName($operand['sel'][0])?->getCellCollection();

        if ($sel === null || ! $sel->has($operand['sel'][1])) {
            return 0;
        }

        $s = $sel->get($operand['sel'][1]);

        if ($s === null || $s->getDataType() === DataType::TYPE_FORMULA) {
            return $this->bintangBuku;
        }

        $nilai = $s->getValue();

        if ($nilai instanceof RichText) {
            $nilai = $nilai->getPlainText();
        }

        return is_string($nilai) ? substr_count($nilai, '*') : 0;
    }

    /**
     * Byte teks nilai operand dan apakah angka itu PASTI: literal teks (panjangnya), rujukan satu sel bernilai bukan rumus
     * (panjang nilai tersimpannya, dibaca tanpa menghitung), selain itu batas atas operand (teks terpanjang workbook atau
     * taksiran hasil fungsi/operator; tidak pasti).
     *
     * @param array<string, mixed> $operand
     *
     * @phpstan-param Operand $operand
     *
     * @return array{0: int, 1: bool}
     */
    private function byteNilai(array $operand): array
    {
        $nilai = $operand['nilai'];

        if (is_string($nilai) && str_starts_with($nilai, '"')) {
            return [max(0, strlen($nilai) - 2), true];
        }

        if ($operand['sel'] !== null) {
            $sel = $this->buku->getSheetByName($operand['sel'][0])?->getCellCollection();

            if ($sel !== null && $sel->has($operand['sel'][1])) {
                $s = $sel->get($operand['sel'][1]);

                if ($s !== null && $s->getDataType() !== DataType::TYPE_FORMULA) {
                    $isi = $s->getValue();

                    return [strlen($isi instanceof RichText ? $isi->getPlainText() : (is_scalar($isi) ? (string) $isi : '')), true];
                }
            } elseif ($sel !== null) {
                return [0, true];
            }
        }

        return [$operand['byte'], false];
    }

    /**
     * Operand buram satu nilai (rujukan workbook eksternal, variabel `LET`): nilainya tidak terlihat dari teks rumus,
     * jadi teksnya dianggap paling panjang teks terpanjang workbook dan jumlah `*`-nya anggapan workbook. Rujukan
     * eksternal ditandai {@see self::EKSTERNAL} supaya operator `:` dengannya tidak dianggap rentang dinamis.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return Operand
     */
    private function buram(bool $eksternal = false): array
    {
        $operand            = $this->operand(null);
        $operand['byte']    = $this->byteSel;
        $operand['bintang'] = null;
        $operand['fungsi']  = $eksternal ? self::EKSTERNAL : null;

        return $operand;
    }

    /**
     * Ukuran hasil `INDEX(larik, baris, kolom)`: satu nilai hanya bila semua nomor yang diberikan pasti bukan nol
     * (angka literal atau hasil `MATCH`) dan mencukupi untuk larik itu; selain itu sebesar lariknya (nomor 0 atau kosong
     * mengembalikan seluruh baris/kolom).
     *
     * @param list<array<string, mixed>> $argumen
     *
     * @phpstan-param list<Operand> $argumen
     *
     * @return array{0: int, 1: int}
     */
    private static function ukuranIndex(array $argumen): array
    {
        $sumber = $argumen[0] ?? null;
        $nomor  = array_slice($argumen, 1);
        $ukuran = self::siaran($nomor);

        if ($sumber === null) {
            return $ukuran;
        }

        $pasti = $nomor !== [];

        foreach ($nomor as $n) {
            $angka = $n['nilai'];

            if ($n['fungsi'] !== 'MATCH' && ! ((is_int($angka) || is_float($angka)) && (float) $angka !== 0.0)) {
                $pasti = false;
            }
        }

        if ($pasti && (count($nomor) >= 2 || $sumber['baris'] === 1 || $sumber['kolom'] === 1)) {
            return $ukuran;
        }

        return [max($ukuran[0], $sumber['baris']), max($ukuran[1], $sumber['kolom'])];
    }

    /**
     * Ukuran larik hasil siaran (broadcast) semua operand: baris terbesar × kolom terbesar.
     *
     * @param list<array<string, mixed>> $operand
     *
     * @phpstan-param list<Operand> $operand
     *
     * @return array{0: int, 1: int}
     */
    private static function siaran(array $operand): array
    {
        $baris = $kolom = 1;

        foreach ($operand as $o) {
            $baris = max($baris, $o['baris']);
            $kolom = max($kolom, $o['kolom']);
        }

        return [$baris, $kolom];
    }

    private static function elemen(int $baris, int $kolom): int
    {
        return $baris > self::MAKS_SISI_LARIK || $kolom > self::MAKS_SISI_LARIK ? self::TAK_HINGGA : $baris * $kolom;
    }

    private static function tambah(int $a, int $b): int
    {
        return $a > self::TAK_HINGGA - $b ? self::TAK_HINGGA : $a + $b;
    }

    /**
     * Operator `:` (`Calculation::processTokenStack`): kotak pembatas semua koordinat kedua operand, dijabarkan di sheet
     * operand kiri.
     *
     * @param array<string, mixed> $kiri
     * @param array<string, mixed> $kanan
     *
     * @phpstan-param Operand $kiri
     * @phpstan-param Operand $kanan
     *
     * @return array<string, mixed>
     *
     * @phpstan-return Operand
     */
    private function rentang(array $kiri, array $kanan, Worksheet $konteks): array
    {
        if ($kiri['galat'] || $kanan['galat']) {
            return $this->galat();
        }

        // Rentang ke workbook eksternal (`[1]Sheet!A1:B10`): kalkulator tidak menjabarkannya (berhenti dengan "Unable to
        // access External Workbook" lalu sel memakai nilai tersimpan), jadi hasilnya tetap operand buram, bukan rentang
        // dinamis (review R4: templat sah ber-rujukan eksternal tetap diterima seperti di main).
        if ($kiri['fungsi'] === self::EKSTERNAL || $kanan['fungsi'] === self::EKSTERNAL) {
            return $this->buram(true);
        }

        // Operand kiri berupa nama terdefinisi: kalkulator mengganti rujukannya dengan isi rentang bernama itu.
        if ($kiri['nama'] !== null) {
            $bernama = $this->buku->getNamedRange($kiri['nama']);

            if ($bernama !== null) {
                $isi = str_replace('$', '', $bernama->getValue());
                /** @var array{0: string|null, 1: string|null} $pecah */
                $pecah = str_contains($isi, '!') ? Worksheet::extractSheetTitle($isi, true, true) : [null, $isi];
                $kiri  = $this->operand(explode(':', (string) $pecah[1]), $pecah[0]);
            }
        }

        // Operand tanpa rujukan (hasil fungsi atau operator): kalkulator menyusun rentang dari NILAI-nya.
        if ($kiri['koordinat'] === null || $kanan['koordinat'] === null) {
            throw new RuntimeException('Rentang dinamis.');
        }

        $sheetKiri  = $kiri['sheet'] ?? $konteks->getTitle();
        $sheetKanan = $kanan['sheet'] ?? $sheetKiri;

        if ($sheetKiri !== $sheetKanan) {
            return $this->galat();
        }

        $kolom = $baris = [];

        foreach ([...$kiri['koordinat'], ...$kanan['koordinat']] as $k) {
            try {
                [$huruf, $angka] = Coordinate::coordinateFromString($k);
                $kolom[]         = Coordinate::columnIndexFromString($huruf);
                $baris[]         = (int) $angka;
            } catch (Throwable) {
                return $this->galat();
            }
        }

        $tinggi = $lebar = 1;

        if ($this->buku->getSheetByName($sheetKiri) !== null) {
            $tinggi = max($baris) - min($baris) + 1;
            $lebar  = max($kolom) - min($kolom) + 1;
            $this->catatRentang($tinggi, $lebar);
        }

        return $this->operand(
            [Coordinate::stringFromColumnIndex(min($kolom)) . min($baris), Coordinate::stringFromColumnIndex(max($kolom)) . max($baris)],
            $sheetKiri,
            null,
            $tinggi,
            $lebar,
            true,
        );
    }

    /**
     * Nama terdefinisi atau nama tabel (`Calculation::processTokenStack` + `evaluateDefinedName`): isinya dihitung ulang
     * setiap kali dipakai.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return Operand
     */
    private function nama(string $nama, string $sheet, Worksheet $konteks, int $kedalaman): array
    {
        $terdefinisi = DefinedName::resolveName($nama, $konteks, $sheet);

        if ($terdefinisi === null) {
            $tabel = $this->buku->getTableByName($nama);

            if ($tabel === null) {
                return $this->galat();
            }

            // Kalkulator membuang baris judul/total; taksiran memakai seluruh rentang tabel.
            [$tinggi, $lebar] = $this->catatTabel($tabel);

            return $this->operand([$nama], null, $nama, $tinggi, $lebar, true);
        }

        $cakupan = $terdefinisi->getScope();

        if ($cakupan !== null && $cakupan !== $konteks && $sheet === '') {
            return $this->galat();
        }

        $isi         = $terdefinisi->getValue();
        $lembarNama  = $terdefinisi->getWorksheet();
        $sheetHitung = $konteks;

        if (! $terdefinisi->isFormula()) {
            // Sama dengan kalkulator: spasi = irisan, koma = gabungan.
            if (preg_match('/^(.*!)?(.*)$/', $isi, $m) === 1) {
                $isi = $m[1] . preg_replace(['/ +/', '/,/'], [' ∩ ', ' ∪ '], trim($m[2]));
            }

            if ($lembarNama !== null && $lembarNama !== $konteks) {
                $sheetHitung = $lembarNama;
            }
        }

        if ($kedalaman >= self::MAKS_KEDALAMAN_NAMA) {
            throw new RuntimeException('Nama terdefinisi terlalu dalam.');
        }

        // Ukuran operand ini = yang terbesar antara rentang di dalam isi nama dan larik hasil isinya.
        $luar           = $this->terbesar;
        $this->terbesar = [1, 1];

        $isi = str_starts_with($isi, '=') ? $isi : '=' . $isi;
        $this->pakai($this->bebanDasar($isi));
        $hasil = $this->jalankan($isi, $sheetHitung, null, $kedalaman + 1);

        [$tinggi, $lebar] = $this->terbesar;
        $this->terbesar   = self::elemen($luar[0], $luar[1]) >= self::elemen($tinggi, $lebar) ? $luar : [$tinggi, $lebar];
        $rentang          = $hasil === null || $hasil['rentang'];

        if ($hasil !== null) {
            $tinggi = max($tinggi, $hasil['baris']);
            $lebar  = max($lebar, $hasil['kolom']);
        }

        // Rujukan operand hasil nama = namanya sendiri (bukan koordinat), persis seperti kalkulator.
        $rujukan         = $terdefinisi->getLocalOnly() && $lembarNama !== null ? "'" . $lembarNama->getTitle() . "'!" . $terdefinisi->getName() : $terdefinisi->getName();
        $operand         = $this->operand([$rujukan], null, $rujukan, $tinggi, $lebar, $rentang);
        $operand['byte'] = $hasil['byte'] ?? $this->byteSel;

        return $operand;
    }

    /**
     * Rujukan terstruktur (`Tabel[Kolom]`, `[@Kolom]`): kalkulator mengubahnya menjadi rentang di dalam tabel lalu
     * menghitung rentang itu. Ditaksir sebagai seluruh rentang tabel, TANPA `StructuredReference::parse` (yang membuat
     * sel dan menghitung sel judul tabel lewat kalkulator workbook).
     *
     * @return array<string, mixed>
     *
     * @phpstan-return Operand
     */
    private function rujukanTerstruktur(string $teks, Worksheet $konteks, ?Cell $sel): array
    {
        $nama  = trim(substr($teks, 0, (int) strpos($teks, '[')));
        $tabel = null;

        if ($nama !== '') {
            $tabel = $this->buku->getTableByName($nama);
        } elseif ($sel !== null) {
            // Tanpa nama tabel: tabel di sheet sel itu yang rentangnya memuat sel.
            foreach ($konteks->getTableCollection() as $t) {
                if (Coordinate::coordinateIsInsideRange($t->getRange(), $sel->getCoordinate())) {
                    $tabel = $t;

                    break;
                }
            }
        } else {
            throw new RuntimeException('Rujukan terstruktur tanpa nama tabel di luar sel.');
        }

        // Tabel tidak ada: kalkulator menghasilkan #REF! atau berhenti dengan galat, tidak ada yang dijabarkan.
        if ($tabel === null) {
            return $this->operand(null);
        }

        [$tinggi, $lebar] = $this->catatTabel($tabel);

        $operand            = $this->operand(null, null, null, $tinggi, $lebar, true);
        $operand['byte']    = $this->byteSel;
        $operand['bintang'] = null;

        return $operand;
    }

    /**
     * Mencatat beban menjabarkan seluruh rentang tabel, ditambah satu satuan per kolom (kalkulator membaca semua sel
     * judul tabel setiap kali rujukan terstruktur diurai).
     *
     * @return array{0: int, 1: int} [baris, kolom] tabel
     */
    private function catatTabel(Table $tabel): array
    {
        [[$kolomAwal, $barisAwal], [$kolomAkhir, $barisAkhir]] = Coordinate::rangeBoundaries($tabel->getRange());
        $tinggi                                                = (int) $barisAkhir - (int) $barisAwal + 1;
        $lebar                                                 = $kolomAkhir - $kolomAwal + 1;

        $this->catatRentang($tinggi, $lebar);
        $this->pakai($lebar);

        return [$tinggi, $lebar];
    }

    /**
     * Mencatat beban satu rentang yang dijabarkan kalkulator.
     */
    private function catatRentang(int $baris, int $kolom): void
    {
        if ($baris * $kolom > self::elemen($this->terbesar[0], $this->terbesar[1])) {
            $this->terbesar = [$baris, $kolom];
        }

        $this->pakai(Presensi::BEBAN_TETAP_RENTANG_RUMUS_IMPOR + $baris * $kolom + self::kuadrat($baris, $kolom, Presensi::PEMBAGI_KUADRAT_BARIS_RUMUS_IMPOR));
    }

    /**
     * Sheet yang dipakai pengurai kalkulator untuk memangkas ujung rentang kolom/baris penuh: sheet pada pangkal rentang
     * (token sebelumnya), atau sheet sel yang dihitung. `null` = sheet itu tidak ada (kalkulator tidak memangkas).
     */
    private function sheetPangkas(mixed $pangkal, Worksheet $konteks): ?Worksheet
    {
        $teks = is_array($pangkal) && is_string($pangkal['value'] ?? null) ? $pangkal['value'] : '';
        /** @var array{0: string|null, 1: string|null} $pecah */
        $pecah = Worksheet::extractSheetTitle($teks, true);
        $sheet = Worksheet::unApostrophizeTitle((string) $pecah[0]);

        return $sheet === '' || $sheet === $konteks->getTitle() ? $konteks : $this->buku->getSheetByName($sheet);
    }

    private function barisTertinggi(Worksheet $lembar): int
    {
        return $this->barisTertinggi[$lembar->getTitle()] ??= $lembar->getHighestDataRow();
    }

    private function kolomTertinggi(Worksheet $lembar, int $baris): string
    {
        return $this->kolomTertinggi[$lembar->getTitle() . '|' . $baris] ??= $lembar->getHighestDataColumn($baris);
    }

    /**
     * Kalkulator memindai seluruh indeks sel sheet rujukan setiap kali memangkas rentang kolom/baris penuh.
     */
    private static function bebanPindai(Worksheet $lembar): int
    {
        return intdiv(count($lembar->getCellCollection()->getCoordinates()), Presensi::SEL_PER_BEBAN_PINDAI_RUMUS_IMPOR);
    }

    /**
     * Operand berujukan (sel, rentang, nama); `$baris` × `$kolom` = ukuran lariknya, `$rentang` = sudah ditagih sebagai
     * rentang yang dijabarkan.
     *
     * @param list<string>|null $koordinat
     *
     * @return array<string, mixed>
     *
     * @phpstan-return Operand
     */
    private function operand(?array $koordinat, ?string $sheet = null, ?string $nama = null, int $baris = 1, int $kolom = 1, bool $rentang = false): array
    {
        // Rujukan (sel, rentang, nama): teksnya paling panjang teks terpanjang workbook; jumlah `*`-nya belum diketahui.
        return [
            'koordinat' => $koordinat, 'sheet' => $sheet, 'nama' => $nama, 'galat' => false, 'nilai' => null,
            'baris'     => $baris, 'kolom' => $kolom, 'byte' => $koordinat === null ? Presensi::BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR : $this->byteSel,
            'rentang'   => $rentang, 'fungsi' => null, 'bintang' => $koordinat === null ? 0 : null, 'sel' => null,
        ];
    }

    /**
     * Operand hasil hitung (operator atau fungsi) berukuran `$baris` × `$kolom`.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return Operand
     */
    private function larik(int $baris, int $kolom, int $byte): array
    {
        $operand         = $this->operand(null, null, null, $baris, $kolom);
        $operand['byte'] = $byte;

        return $operand;
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-return Operand
     */
    private function galat(): array
    {
        $operand          = $this->operand(null);
        $operand['galat'] = true;

        return $operand;
    }
}
