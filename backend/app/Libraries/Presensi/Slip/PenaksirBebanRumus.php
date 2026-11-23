<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use Config\Presensi;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Calculation\Engine\Operands\StructuredReference;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\DefinedName;
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
 * dibaca dari workbook hanya teks rumus, daftar sheet, nama terdefinisi, rentang tabel, dan baris/kolom data tertinggi.
 * Karena itu fungsi vendor yang memanggil `Worksheet::getCell()` atau `Cell::getCalculatedValue()` (mis.
 * `StructuredReference::parse`) tidak boleh dipakai di sini (dijaga `PembacaExcelSlipAnggaranRumusTest`).
 *
 * Satuan beban ±5 mikrodetik hitung di mesin ukur saat tenang (tabel per kelas di README `Libraries/Presensi` BB-12):
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
 *   {@see self::FUNGSI_SEDANG} `PEMBAGI_KUADRAT_BARIS_SEDANG_RUMUS_IMPOR`, fungsi LAIN
 *   `PEMBAGI_KUADRAT_BARIS_UMUM_RUMUS_IMPOR`; untuk fungsi berkriteria dan sejenisnya luasnya dikalikan
 *   ({@see self::PENGALI_LUAS});
 * - LARIK HASIL: tiap operand membawa ukuran lariknya (baris × kolom). Operator atas larik menagih satu satuan per
 *   elemen hasil dan meneruskan ukuran itu; ukuran hasil = baris terbesar × kolom terbesar kedua operand (kalkulator
 *   memperluas keduanya, jadi kolom × baris menjadi matriks penuh). Fungsi per elemen dan fungsi yang tidak dikenal
 *   berlaku sama; `TRANSPOSE` menukar ukuran; fungsi agregat ({@see self::FUNGSI_SKALAR}) menghasilkan satu nilai;
 * - fungsi pencarian ({@see self::FUNGSI_PENCARIAN}) dengan nilai cari berupa larik dipanggil kalkulator sekali per
 *   elemen: biaya rentang tabelnya dikalikan jumlah elemen itu. Fungsi tidak dikenal dengan dua larik atau lebih
 *   ditaksir sama (terburuk);
 * - rentang kolom/baris penuh (`J:J`, `2:2`) dipangkas ke baris/kolom data tertinggi sheet rujukan, sama seperti
 *   kalkulator, ditambah jumlah sel sheet itu / `SEL_PER_BEBAN_PINDAI_RUMUS_IMPOR` (kalkulator memindai indeks sel);
 * - nama terdefinisi, nama tabel, dan rujukan terstruktur ditaksir dari isinya / rentang tabelnya (berulang, paling
 *   dalam `MAKS_KEDALAMAN_NAMA`);
 * - teks: `REPT` berargumen literal = byte hasil / `BYTE_PER_BEBAN_TEKS_RUMUS_IMPOR`; operator `&` = elemen hasil ×
 *   taksiran byte tiap elemen / `BYTE_PER_BEBAN_TEKS_LARIK_RUMUS_IMPOR` (hasil `REPT` literal yang disalin ke tiap
 *   elemen larik);
 * - {@see self::TAK_HINGGA} untuk rumus yang kerjanya baru diketahui saat dihitung: `INDIRECT`, `OFFSET`, `ANCHORARRAY`
 *   (operator `#`), `SINGLE` (operator `@`), pembangkit larik (`SEQUENCE`, `RANDARRAY`, `MUNIT`, `EXPAND`), `REPT`
 *   berjumlah ulang bukan literal, operator `:` dengan operand hasil hitung, rujukan 3D, rumus yang tidak terurai, dan
 *   nama terdefinisi yang berputar atau terlalu dalam.
 *
 * Supaya tidak menyimpang dari kalkulator, teks rumus diurai dengan pengurai kalkulator itu sendiri
 * (`Calculation::parseFormula` pada kalkulator TERSENDIRI, diperiksa pada PhpSpreadsheet 5.10.0), lalu daftar tokennya
 * dijalankan secara abstrak: yang dilacak hanya rujukan dan ukuran larik tiap operand, mengikuti aturan
 * `Calculation::processTokenStack`. Semua cabang `IF` dihitung (kalkulator bisa melewati cabang yang tidak terpakai),
 * jadi taksiran rentang tidak pernah di bawah kerja kalkulator. Pengurai dipanggil tanpa sel, sehingga dua hal yang
 * bergantung pada sel ditiru di sini: sheet bawaan rujukan tanpa nama sheet, dan pemangkasan rentang kolom/baris penuh.
 *
 * Rumus tanpa `:`, `[`, `#`, `{`, nama terdefinisi, nama tabel, dan fungsi berat tidak mungkin memuat larik, jadi
 * tidak diurai sama sekali (jalur cepat). Penaksiran berhenti begitu beban berjalan melewati batas yang diberikan
 * pemanggil, jadi penaksir sendiri tidak bisa dibuat bekerja lama.
 *
 * @phpstan-type Operand array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed, baris: int, kolom: int, byte: int, rentang: bool, fungsi: string|null}
 */
final class PenaksirBebanRumus
{
    public const TAK_HINGGA = PHP_INT_MAX;

    private const MAKS_KEDALAMAN_NAMA = 8;
    private const BARIS_LEMBAR        = 1_048_576;
    private const KOLOM_LEMBAR        = 'XFD';

    /** Sisi larik terbesar yang masih dihitung tanpa meluap; lebih besar langsung {@see self::TAK_HINGGA}. */
    private const MAKS_SISI_LARIK = 4_000_000;

    /** Karakter (kode ASCII) yang dihitung sebagai operator pada beban dasar: `+ - * / ^ & < > = %` dan spasi. */
    private const KODE_OPERATOR = [43, 45, 42, 47, 94, 38, 60, 62, 61, 37, 32];

    /**
     * Fungsi yang kerjanya ditentukan nilai saat dihitung, bukan teks rumus.
     */
    private const FUNGSI_DINAMIS = [
        'indirect'    => true,
        'offset'      => true,
        'anchorarray' => true,
        'single'      => true,
        'sequence'    => true,
        'randarray'   => true,
        'munit'       => true,
        'expand'      => true,
    ];

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
     * satu kolom 10.000 baris ±2,4× `SUM`, `MAX` ±4,5×).
     */
    private const FUNGSI_SEDANG = ['SUBTOTAL' => true];

    /**
     * Pengali luas larik argumen (terukur per elemen terhadap `SUM`): fungsi berkriteria mengevaluasi kriterianya lewat
     * kalkulator untuk tiap sel; fungsi basis data (`D…`, belum diukur) menyusun ulang tabelnya per baris.
     */
    private const PENGALI_LUAS = [
        'COUNTIF'  => 6, 'COUNTIFS' => 6, 'SUMIFS' => 6, 'AVERAGEIFS' => 6, 'MAXIFS' => 6, 'MINIFS' => 6,
        'SUMIF'    => 4, 'AVERAGEIF' => 4, 'SUBTOTAL' => 2,
        'DAVERAGE' => 20, 'DCOUNT' => 20, 'DCOUNTA' => 20, 'DGET' => 20, 'DMAX' => 20, 'DMIN' => 20, 'DPRODUCT' => 20,
        'DSTDEV'   => 20, 'DSTDEVP' => 20, 'DSUM' => 20, 'DVAR' => 20, 'DVARP' => 20,
    ];

    /**
     * Fungsi agregat: seluruh larik argumennya diserap dalam satu panggilan dan hasilnya satu nilai (terukur untuk yang
     * berkriteria, `LARGE`, dan `RANK`: argumen kedua berupa larik tidak memperbanyak panggilan).
     */
    private const FUNGSI_SKALAR = [
        'SUM'        => true, 'SUMSQ' => true, 'PRODUCT' => true, 'COUNT' => true, 'COUNTA' => true, 'COUNTBLANK' => true,
        'AVERAGE'    => true, 'AVERAGEA' => true, 'MAX' => true, 'MAXA' => true, 'MIN' => true, 'MINA' => true,
        'MEDIAN'     => true, 'MODE' => true, 'STDEV' => true, 'STDEVP' => true, 'VAR' => true, 'VARP' => true,
        'SUMPRODUCT' => true, 'SUBTOTAL' => true, 'AND' => true, 'OR' => true, 'XOR' => true, 'ROWS' => true,
        'COLUMNS'    => true, 'COUNTIF' => true, 'COUNTIFS' => true, 'SUMIF' => true, 'SUMIFS' => true,
        'AVERAGEIF'  => true, 'AVERAGEIFS' => true, 'MAXIFS' => true, 'MINIFS' => true, 'LARGE' => true, 'SMALL' => true,
        'RANK'       => true, 'DAVERAGE' => true, 'DCOUNT' => true, 'DCOUNTA' => true, 'DGET' => true, 'DMAX' => true,
        'DMIN'       => true, 'DPRODUCT' => true, 'DSTDEV' => true, 'DSTDEVP' => true, 'DSUM' => true, 'DVAR' => true,
        'DVARP'      => true,
    ];

    /**
     * Fungsi pencarian: argumen pertama (nilai cari) berupa larik membuat kalkulator memanggil fungsinya sekali per
     * elemen, tiap kali menelusuri larik argumen lainnya (`MATCH` 1.000 nilai ke 1.000 baris ±8 detik).
     */
    private const FUNGSI_PENCARIAN = [
        'MATCH' => true, 'VLOOKUP' => true, 'HLOOKUP' => true, 'LOOKUP' => true, 'XLOOKUP' => true, 'XMATCH' => true,
    ];

    /**
     * Fungsi yang dihitung per elemen larik argumennya tanpa menelusuri larik lain (terukur ±1 satuan per elemen, tanpa
     * suku kuadratik). Fungsi di luar semua daftar diperlakukan sama bila larik argumennya satu, dan terburuk (tiap
     * elemen menelusuri semua larik) bila dua atau lebih.
     */
    private const FUNGSI_ELEMEN = [
        'IF'       => true, 'IFERROR' => true, 'IFNA' => true, 'CHOOSE' => true, 'NOT' => true, 'ROUND' => true,
        'ROUNDUP'  => true, 'ROUNDDOWN' => true, 'INT' => true, 'TRUNC' => true, 'ABS' => true, 'MOD' => true,
        'SIGN'     => true, 'SQRT' => true, 'POWER' => true, 'CEILING' => true, 'FLOOR' => true, 'MROUND' => true,
        'LEN'      => true, 'LEFT' => true, 'RIGHT' => true, 'MID' => true, 'TEXT' => true, 'VALUE' => true,
        'UPPER'    => true, 'LOWER' => true, 'PROPER' => true, 'TRIM' => true, 'SUBSTITUTE' => true, 'REPLACE' => true,
        'FIND'     => true, 'SEARCH' => true, 'EXACT' => true, 'N' => true, 'T' => true, 'REPT' => true,
        'ISNUMBER' => true, 'ISTEXT' => true, 'ISBLANK' => true, 'ISERROR' => true, 'ISERR' => true, 'ISNA' => true,
        'DATE'     => true, 'YEAR' => true, 'MONTH' => true, 'DAY' => true, 'DATEDIF' => true, 'EDATE' => true,
        'EOMONTH'  => true,
    ];

    /** Fungsi penggabung teks: byte hasil = jumlah byte semua elemen argumennya. */
    private const FUNGSI_GABUNG = ['CONCATENATE' => true, 'CONCAT' => true, 'TEXTJOIN' => true];

    private const OPERATOR_BINER = [
        '+' => true, '-' => true, '*' => true, '/' => true, '^' => true, '&' => true, '>' => true, '<' => true,
        '=' => true, '>=' => true, '<=' => true, '<>' => true, '∩' => true, '∪' => true, ':' => true,
    ];

    private const POLA_SEL    = '/^' . Calculation::CALCULATION_REGEXP_CELLREF . '$/i';
    private const POLA_FUNGSI = '/^' . Calculation::CALCULATION_REGEXP_FUNCTION . '$/miu';
    private const POLA_NAMA   = '/^' . Calculation::CALCULATION_REGEXP_DEFINEDNAME . '$/miu';
    private const POLA_KATA   = '/[\p{L}_\\\\][\p{L}\p{N}_.\\\\]*/u';

    private readonly Calculation $pengurai;

    /**
     * Nama terdefinisi, nama tabel, fungsi dinamis, dan `REPT` (huruf kecil): rumus yang memuat salah satunya wajib
     * diurai.
     *
     * @var array<string, true>
     */
    private array $kataBerat = self::FUNGSI_DINAMIS + ['rept' => true];

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
        }

        foreach ($buku->getAllSheets() as $lembar) {
            foreach ($lembar->getTableCollection() as $tabel) {
                $this->kataBerat[mb_strtolower($tabel->getName())] = true;
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
            $this->pakai(self::bebanDasar($rumus));

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
    private static function bebanDasar(string $rumus): int
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

    private function perluDiurai(string $rumus): bool
    {
        if (strpbrk($rumus, ':[#{') !== false) {
            return true;
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
     * @return array<string, mixed>|null operand hasil rumus; `null` bila kalkulator berhenti dengan galat di tengah jalan
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

                // Kalkulator berhenti dengan galat di sini; yang sudah dijabarkan sebelumnya tetap tercatat.
                if ($kanan === null || $kiri === null) {
                    return null;
                }

                $tumpukan[] = $nilai === ':' ? $this->rentang($kiri, $kanan, $konteks) : $this->operator($nilai, $kiri, $kanan);

                continue;
            }

            if ($nilai === '~' || $nilai === '%') {
                $operand = array_pop($tumpukan);

                if ($operand === null) {
                    return null;
                }

                $tumpukan[] = $this->operator($nilai, $operand, $operand);

                continue;
            }

            if (is_string($nilai) && preg_match(self::POLA_SEL, $nilai, $m) === 1) {
                $sheet = trim((string) $m[2], "\"'");

                // Rujukan ke workbook lain: kalkulator berhenti di token ini.
                if (str_contains($sheet, '[') || str_contains($sheet, ']')) {
                    return null;
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
                $tumpukan[] = self::operand([$kolom . $baris], $sheet !== '' && $this->buku->getSheetByName($sheet) !== null ? $sheet : null);

                continue;
            }

            if (is_string($nilai) && preg_match(self::POLA_FUNGSI, $nilai, $m) === 1) {
                $fungsi = strtoupper($m[1]);

                if (isset(self::FUNGSI_DINAMIS[strtolower($fungsi)])) {
                    throw new RuntimeException('Fungsi dinamis.');
                }

                $jumlah = array_pop($tumpukan)['nilai'] ?? null;

                if (! is_int($jumlah)) {
                    return null;
                }

                $argumen = [];

                for ($a = 0; $a < $jumlah; $a++) {
                    $x = array_pop($tumpukan);

                    if ($x === null) {
                        return null;
                    }

                    $argumen[] = $x;
                }

                $tumpukan[] = $this->fungsi($fungsi, array_reverse($argumen));

                continue;
            }

            if (is_string($nilai) && $nilai !== '' && ! is_numeric($nilai) && ! Calculation::keyInExcelConstants(strtoupper($nilai))
                && $nilai[0] !== '"' && $nilai[0] !== '#' && preg_match(self::POLA_NAMA, $nilai, $m) === 1) {
                // Kalkulator mengembalikan nilai tersimpan untuk nama `_xleta…` tanpa melanjutkan.
                if (str_starts_with($m[6], '_xleta')) {
                    return null;
                }

                $tumpukan[] = $this->nama($m[6], trim($m[2], "'"), $konteks, $kedalaman);

                continue;
            }

            // Angka, teks, konstanta, galat, argumen kosong, dan jumlah argumen fungsi.
            $literal          = self::operand(is_string($t['reference'] ?? null) ? [$t['reference']] : null);
            $literal['nilai'] = $nilai;
            $literal['byte']  = is_string($nilai) && str_starts_with($nilai, '"') ? max(1, strlen($nilai) - 2) : Presensi::BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR;
            $tumpukan[]       = $literal;
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

        if ($elemen > 1) {
            $this->pakai($elemen);
        }

        if ($operator === '&') {
            $byte = self::tambah($kiri['byte'], $kanan['byte']);
            $this->pakai(self::kali($elemen, intdiv($byte, Presensi::BYTE_PER_BEBAN_TEKS_LARIK_RUMUS_IMPOR)));
        }

        return self::larik($baris, $kolom, $byte);
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
            $sekali    = false;
        } elseif ($fungsi === 'INDEX') {
            $hasil = self::ukuranIndex($argumen);
        } elseif ($fungsi === 'TRANSPOSE') {
            $hasil = isset($argumen[0]) ? [$argumen[0]['kolom'], $argumen[0]['baris']] : [1, 1];
        } elseif ($fungsi === 'MMULT' && count($argumen) === 2) {
            $hasil = [$argumen[0]['baris'], $argumen[1]['kolom']];
            $this->pakai(self::kali(self::elemen($argumen[0]['baris'], $argumen[0]['kolom']), $argumen[1]['kolom']));
        } elseif ($fungsi === 'MKMATRIX') {
            // Larik literal `{1,2;3,4}` = MKMATRIX(MKMATRIX(1,2),MKMATRIX(3,4)): yang luar berisi baris-barisnya.
            $luar  = $argumen !== [] && array_filter($argumen, static fn (array $a): bool => $a['fungsi'] !== 'MKMATRIX') === [];
            $hasil = $luar ? [count($argumen), max(array_column($argumen, 'kolom'))] : [1, max(1, count($argumen))];
        } else {
            $hasil     = self::siaran($argumen);
            $ditelusur = $perElemen || count($larik) < 2 ? [] : $larik;
            $sekali    = false;
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
            $this->pakai(self::kali($pengali - 1, self::elemen($a['baris'], $a['kolom'])));
        }

        if ($panggilan > 1) {
            $this->pakai($panggilan);

            foreach ($ditelusur as $a) {
                $luas = self::elemen($a['baris'], $a['kolom']);

                if ($luas > 1) {
                    $this->pakai(self::kali($panggilan - 1, self::tambah(self::kali($luas, $pengali), self::kuadrat($a['baris'], $a['kolom'], $pembagi))));
                }
            }
        }

        $byte = Presensi::BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR;

        foreach ($argumen as $a) {
            $byte = isset(self::FUNGSI_GABUNG[$fungsi])
                ? self::tambah($byte, self::kali($a['byte'], self::elemen($a['baris'], $a['kolom'])))
                : max($byte, $a['byte']);
        }

        // `REPT` memanggil `str_repeat` sebelum memeriksa panjang hasil: jumlah ulang yang bukan literal tidak terbatas.
        if ($fungsi === 'REPT' && count($argumen) === 2) {
            $ulang = $argumen[1]['nilai'];

            if (! is_int($ulang) && ! is_float($ulang)) {
                throw new RuntimeException('REPT berjumlah ulang bukan literal.');
            }

            $teks    = $argumen[0]['nilai'];
            $satu    = is_string($teks) && str_starts_with($teks, '"') ? max(1, strlen($teks) - 2) : Presensi::BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR;
            $panjang = max(0.0, (float) $ulang) * $satu;
            $byte    = $panjang >= (float) self::TAK_HINGGA ? self::TAK_HINGGA : (int) $panjang;

            $this->pakai(self::kali($panggilan, intdiv($byte, Presensi::BYTE_PER_BEBAN_TEKS_RUMUS_IMPOR)));
        }

        $operand           = self::larik($hasil[0], $hasil[1], $byte);
        $operand['fungsi'] = $fungsi;

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
            return self::galat();
        }

        // Operand kiri berupa nama terdefinisi: kalkulator mengganti rujukannya dengan isi rentang bernama itu.
        if ($kiri['nama'] !== null) {
            $bernama = $this->buku->getNamedRange($kiri['nama']);

            if ($bernama !== null) {
                $isi = str_replace('$', '', $bernama->getValue());
                /** @var array{0: string|null, 1: string|null} $pecah */
                $pecah = str_contains($isi, '!') ? Worksheet::extractSheetTitle($isi, true, true) : [null, $isi];
                $kiri  = self::operand(explode(':', (string) $pecah[1]), $pecah[0]);
            }
        }

        // Operand tanpa rujukan (hasil fungsi atau operator): kalkulator menyusun rentang dari NILAI-nya.
        if ($kiri['koordinat'] === null || $kanan['koordinat'] === null) {
            throw new RuntimeException('Rentang dinamis.');
        }

        $sheetKiri  = $kiri['sheet'] ?? $konteks->getTitle();
        $sheetKanan = $kanan['sheet'] ?? $sheetKiri;

        if ($sheetKiri !== $sheetKanan) {
            return self::galat();
        }

        $kolom = $baris = [];

        foreach ([...$kiri['koordinat'], ...$kanan['koordinat']] as $k) {
            try {
                [$huruf, $angka] = Coordinate::coordinateFromString($k);
                $kolom[]         = Coordinate::columnIndexFromString($huruf);
                $baris[]         = (int) $angka;
            } catch (Throwable) {
                return self::galat();
            }
        }

        $tinggi = $lebar = 1;

        if ($this->buku->getSheetByName($sheetKiri) !== null) {
            $tinggi = max($baris) - min($baris) + 1;
            $lebar  = max($kolom) - min($kolom) + 1;
            $this->catatRentang($tinggi, $lebar);
        }

        return self::operand(
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
                return self::galat();
            }

            // Kalkulator membuang baris judul/total; taksiran memakai seluruh rentang tabel.
            [$tinggi, $lebar] = $this->catatTabel($tabel);

            return self::operand([$nama], null, $nama, $tinggi, $lebar, true);
        }

        $cakupan = $terdefinisi->getScope();

        if ($cakupan !== null && $cakupan !== $konteks && $sheet === '') {
            return self::galat();
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
        $this->pakai(self::bebanDasar($isi));
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
        $operand         = self::operand([$rujukan], null, $rujukan, $tinggi, $lebar, $rentang);
        $operand['byte'] = $hasil['byte'] ?? Presensi::BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR;

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
            return self::operand(null);
        }

        [$tinggi, $lebar] = $this->catatTabel($tabel);

        return self::operand(null, null, null, $tinggi, $lebar, true);
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
    private static function operand(?array $koordinat, ?string $sheet = null, ?string $nama = null, int $baris = 1, int $kolom = 1, bool $rentang = false): array
    {
        return [
            'koordinat' => $koordinat, 'sheet' => $sheet, 'nama' => $nama, 'galat' => false, 'nilai' => null,
            'baris'     => $baris, 'kolom' => $kolom, 'byte' => Presensi::BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR, 'rentang' => $rentang, 'fungsi' => null,
        ];
    }

    /**
     * Operand hasil hitung (operator atau fungsi) berukuran `$baris` × `$kolom`.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return Operand
     */
    private static function larik(int $baris, int $kolom, int $byte): array
    {
        $operand         = self::operand(null, null, null, $baris, $kolom);
        $operand['byte'] = $byte;

        return $operand;
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-return Operand
     */
    private static function galat(): array
    {
        $operand          = self::operand(null);
        $operand['galat'] = true;

        return $operand;
    }
}
