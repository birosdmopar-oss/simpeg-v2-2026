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
 * Satuan beban ±5 mikrodetik hitung di mesin ukur saat tenang (tabel di README `Libraries/Presensi` BB-12):
 *
 * - beban dasar sel = `BEBAN_DASAR_RUMUS_IMPOR` + panjang teks + panjang² /
 *   `PEMBAGI_KUADRAT_PANJANG_RUMUS_IMPOR` (biaya urai kalkulator tumbuh kuadratik menurut panjang teks); teks lebih
 *   panjang dari `MAKS_PANJANG_RUMUS_IMPOR` (batas Excel) langsung {@see self::TAK_HINGGA};
 * - tiap rentang yang dijabarkan kalkulator = `BEBAN_TETAP_RENTANG_RUMUS_IMPOR` + baris × kolom + baris² /
 *   `PEMBAGI_KUADRAT_BARIS_RUMUS_IMPOR` (kalkulator menjabarkan rentang menjadi daftar sel, dan biayanya tumbuh
 *   kuadratik menurut jumlah baris);
 * - rentang yang menjadi argumen fungsi: suku kuadratiknya menjadi baris² / `PEMBAGI_KUADRAT_BARIS_UMUM_RUMUS_IMPOR`
 *   kecuali untuk {@see self::FUNGSI_MURAH} (terukur: hanya `SUM`, `COUNT`, `AVERAGE`, dan beberapa lain yang meratakan
 *   rentang secara linear); untuk fungsi berkriteria ({@see self::PENGALI_LUAS}) luasnya dikalikan, karena kriteria
 *   dievaluasi lewat kalkulator untuk tiap sel;
 * - rentang kolom/baris penuh (`J:J`, `2:2`) dipangkas ke baris/kolom data tertinggi sheet rujukan, sama seperti
 *   kalkulator, ditambah jumlah sel sheet itu / `SEL_PER_BEBAN_PINDAI_RUMUS_IMPOR` (kalkulator memindai indeks sel);
 * - nama terdefinisi, nama tabel, dan rujukan terstruktur ditaksir dari isinya / rentang tabelnya (berulang, paling
 *   dalam `MAKS_KEDALAMAN_NAMA`);
 * - `REPT` berargumen literal = byte hasil / `BYTE_PER_BEBAN_TEKS_RUMUS_IMPOR`;
 * - {@see self::TAK_HINGGA} untuk rumus yang kerjanya baru diketahui saat dihitung: `INDIRECT`, `OFFSET`, `ANCHORARRAY`
 *   (operator `#`), `SINGLE` (operator `@`), pembangkit larik (`SEQUENCE`, `RANDARRAY`, `MUNIT`, `EXPAND`), `REPT`
 *   berjumlah ulang bukan literal, operator `:` dengan operand hasil hitung, rujukan 3D, rumus yang tidak terurai, dan
 *   nama terdefinisi yang berputar atau terlalu dalam.
 *
 * Supaya tidak menyimpang dari kalkulator, teks rumus diurai dengan pengurai kalkulator itu sendiri
 * (`Calculation::parseFormula` pada kalkulator TERSENDIRI, diperiksa pada PhpSpreadsheet 5.10.0), lalu daftar tokennya
 * dijalankan secara abstrak: yang dilacak hanya rujukan tiap operand, mengikuti aturan
 * `Calculation::processTokenStack`. Semua cabang `IF` dihitung (kalkulator bisa melewati cabang yang tidak terpakai),
 * jadi taksiran rentang tidak pernah di bawah kerja kalkulator. Pengurai dipanggil tanpa sel, sehingga dua hal yang
 * bergantung pada sel ditiru di sini: sheet bawaan rujukan tanpa nama sheet, dan pemangkasan rentang kolom/baris penuh.
 *
 * Rumus tanpa `:`, `[`, `#`, nama terdefinisi, nama tabel, dan fungsi berat tidak mungkin menjabarkan rentang, jadi
 * tidak diurai sama sekali (jalur cepat). Penaksiran berhenti begitu beban berjalan melewati batas yang diberikan
 * pemanggil, jadi penaksir sendiri tidak bisa dibuat bekerja lama.
 */
final class PenaksirBebanRumus
{
    public const TAK_HINGGA = PHP_INT_MAX;

    private const MAKS_KEDALAMAN_NAMA = 8;
    private const BARIS_LEMBAR        = 1_048_576;
    private const KOLOM_LEMBAR        = 'XFD';

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
     * Fungsi (huruf besar) yang biaya kuadratik rentang argumennya sama dengan penjabaran biasa. Fungsi LAIN memakai
     * `PEMBAGI_KUADRAT_BARIS_UMUM_RUMUS_IMPOR` (README BB-12: `MAX` atas satu kolom 10.000 baris ±4,5× `SUM`).
     */
    private const FUNGSI_MURAH = [
        'SUM'      => true, 'COUNT' => true, 'COUNTA' => true, 'AVERAGE' => true, 'HLOOKUP' => true, 'INDEX' => true,
        'ROWS'     => true, 'COLUMNS' => true, 'ROW' => true, 'COLUMN' => true, 'AND' => true, 'OR' => true,
        'MKMATRIX' => true,
    ];

    /**
     * Pengali luas rentang argumen fungsi berkriteria (huruf besar), terukur ±8× `SUM` per sel; fungsi basis data
     * (`D…`) menyusun ulang tabelnya per baris.
     */
    private const PENGALI_LUAS = [
        'COUNTIF'  => 10, 'COUNTIFS' => 10, 'SUMIF' => 10, 'SUMIFS' => 10, 'AVERAGEIF' => 10, 'AVERAGEIFS' => 10,
        'MAXIFS'   => 10, 'MINIFS' => 10,
        'DAVERAGE' => 20, 'DCOUNT' => 20, 'DCOUNTA' => 20, 'DGET' => 20, 'DMAX' => 20, 'DMIN' => 20, 'DPRODUCT' => 20,
        'DSTDEV'   => 20, 'DSTDEVP' => 20, 'DSUM' => 20, 'DVAR' => 20, 'DVARP' => 20,
    ];

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
     * [luas, baris] rentang terbesar yang dicatat di tingkat nama terdefinisi yang sedang dijalankan.
     *
     * @var array{0: int, 1: int}
     */
    private array $terbesar = [0, 0];

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
        $this->terbesar = [0, 0];

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
     * Beban dasar satu teks rumus (sel atau isi nama terdefinisi).
     *
     * @throws RuntimeException teks lebih panjang dari batas Excel
     */
    private static function bebanDasar(string $rumus): int
    {
        $panjang = strlen($rumus);

        if ($panjang > Presensi::MAKS_PANJANG_RUMUS_IMPOR) {
            throw new RuntimeException('Rumus terlalu panjang.');
        }

        return Presensi::BEBAN_DASAR_RUMUS_IMPOR + $panjang + intdiv($panjang * $panjang, Presensi::PEMBAGI_KUADRAT_PANJANG_RUMUS_IMPOR);
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

    private function perluDiurai(string $rumus): bool
    {
        if (strpbrk($rumus, ':[#') !== false) {
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
     * kalkulator ({@see self::pakai()}).
     *
     * @throws Throwable rumus tidak terurai, kerjanya tidak bisa dibatasi, atau beban melewati batas
     */
    private function jalankan(string $rumus, Worksheet $konteks, ?Cell $sel, int $kedalaman): void
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
                    return;
                }

                $tumpukan[] = $nilai === ':' ? $this->rentang($kiri, $kanan, $konteks) : self::operand(null);

                continue;
            }

            if ($nilai === '~' || $nilai === '%') {
                if (array_pop($tumpukan) === null) {
                    return;
                }

                $tumpukan[] = self::operand(null);

                continue;
            }

            if (is_string($nilai) && preg_match(self::POLA_SEL, $nilai, $m) === 1) {
                $sheet = trim((string) $m[2], "\"'");

                // Rujukan ke workbook lain: kalkulator berhenti di token ini.
                if (str_contains($sheet, '[') || str_contains($sheet, ']')) {
                    return;
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
                    return;
                }

                $argumen = [];

                for ($a = 0; $a < $jumlah; $a++) {
                    $x = array_pop($tumpukan);

                    if ($x === null) {
                        return;
                    }

                    $argumen[] = $x;
                }

                $this->fungsi($fungsi, array_reverse($argumen));
                $tumpukan[] = self::operand(null);

                continue;
            }

            if (is_string($nilai) && $nilai !== '' && ! is_numeric($nilai) && ! Calculation::keyInExcelConstants(strtoupper($nilai))
                && $nilai[0] !== '"' && $nilai[0] !== '#' && preg_match(self::POLA_NAMA, $nilai, $m) === 1) {
                // Kalkulator mengembalikan nilai tersimpan untuk nama `_xleta…` tanpa melanjutkan.
                if (str_starts_with($m[6], '_xleta')) {
                    return;
                }

                $tumpukan[] = $this->nama($m[6], trim($m[2], "'"), $konteks, $kedalaman);

                continue;
            }

            // Angka, teks, konstanta, galat, argumen kosong, dan jumlah argumen fungsi.
            $rujukan    = is_string($t['reference'] ?? null) ? [$t['reference']] : null;
            $tumpukan[] = ['koordinat' => $rujukan, 'sheet' => null, 'nama' => null, 'galat' => false, 'nilai' => $nilai, 'luas' => 0, 'baris' => 0];
        }
    }

    /**
     * Biaya tambahan pemanggilan fungsi atas rentang yang menjadi argumennya (suku kuadratik fungsi umum, pengali luas
     * fungsi berkriteria), dan ukuran teks hasil `REPT`.
     *
     * @param list<array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed, luas: int, baris: int}> $argumen
     */
    private function fungsi(string $fungsi, array $argumen): void
    {
        $umum    = ! isset(self::FUNGSI_MURAH[$fungsi]);
        $pengali = self::PENGALI_LUAS[$fungsi] ?? 1;

        foreach ($argumen as $a) {
            if ($a['luas'] === 0) {
                continue;
            }

            if ($umum) {
                $kuadrat = $a['baris'] * $a['baris'];
                $this->pakai(intdiv($kuadrat, Presensi::PEMBAGI_KUADRAT_BARIS_UMUM_RUMUS_IMPOR) - intdiv($kuadrat, Presensi::PEMBAGI_KUADRAT_BARIS_RUMUS_IMPOR));
            }

            $this->pakai(($pengali - 1) * $a['luas']);
        }

        // `REPT` memanggil `str_repeat` sebelum memeriksa panjang hasil: jumlah ulang yang bukan literal tidak terbatas.
        if ($fungsi === 'REPT' && count($argumen) === 2) {
            $ulang = $argumen[1]['nilai'];

            if (! is_int($ulang) && ! is_float($ulang)) {
                throw new RuntimeException('REPT berjumlah ulang bukan literal.');
            }

            $teks  = $argumen[0]['nilai'];
            $byte  = is_string($teks) && str_starts_with($teks, '"') ? max(1, strlen($teks) - 2) : Presensi::BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR;
            $hasil = max(0.0, (float) $ulang) * $byte;

            $this->pakai($hasil >= (float) self::TAK_HINGGA ? self::TAK_HINGGA : intdiv((int) $hasil, Presensi::BYTE_PER_BEBAN_TEKS_RUMUS_IMPOR));
        }
    }

    /**
     * Operator `:` (`Calculation::processTokenStack`): kotak pembatas semua koordinat kedua operand, dijabarkan di sheet
     * operand kiri.
     *
     * @param array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed, luas: int, baris: int} $kiri
     * @param array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed, luas: int, baris: int} $kanan
     *
     * @return array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed, luas: int, baris: int}
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

        $tinggi = $lebar = 0;

        if ($this->buku->getSheetByName($sheetKiri) !== null) {
            $tinggi = max($baris) - min($baris) + 1;
            $lebar  = max($kolom) - min($kolom) + 1;
            $this->catatRentang($tinggi, $lebar);
        }

        return self::operand(
            [Coordinate::stringFromColumnIndex(min($kolom)) . min($baris), Coordinate::stringFromColumnIndex(max($kolom)) . max($baris)],
            $sheetKiri,
            null,
            $tinggi * $lebar,
            $tinggi,
        );
    }

    /**
     * Nama terdefinisi atau nama tabel (`Calculation::processTokenStack` + `evaluateDefinedName`): isinya dihitung ulang
     * setiap kali dipakai.
     *
     * @return array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed, luas: int, baris: int}
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

            return self::operand([$nama], null, $nama, $tinggi * $lebar, $tinggi);
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

        // Rentang terbesar di dalam isi nama menjadi ukuran operand ini (untuk fungsi yang menerimanya sebagai argumen).
        $luar           = $this->terbesar;
        $this->terbesar = [0, 0];

        $isi = str_starts_with($isi, '=') ? $isi : '=' . $isi;
        $this->pakai(self::bebanDasar($isi));
        $this->jalankan($isi, $sheetHitung, null, $kedalaman + 1);

        [$luas, $tinggi] = $this->terbesar;
        $this->terbesar  = [max($luar[0], $luas), max($luar[1], $tinggi)];

        // Rujukan operand hasil nama = namanya sendiri (bukan koordinat), persis seperti kalkulator.
        $rujukan = $terdefinisi->getLocalOnly() && $lembarNama !== null ? "'" . $lembarNama->getTitle() . "'!" . $terdefinisi->getName() : $terdefinisi->getName();

        return self::operand([$rujukan], null, $rujukan, $luas, $tinggi);
    }

    /**
     * Rujukan terstruktur (`Tabel[Kolom]`, `[@Kolom]`): kalkulator mengubahnya menjadi rentang di dalam tabel lalu
     * menghitung rentang itu. Ditaksir sebagai seluruh rentang tabel, TANPA `StructuredReference::parse` (yang membuat
     * sel dan menghitung sel judul tabel lewat kalkulator workbook).
     *
     * @return array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed, luas: int, baris: int}
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

        return self::operand(null, null, null, $tinggi * $lebar, $tinggi);
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
        $this->terbesar = [max($this->terbesar[0], $baris * $kolom), max($this->terbesar[1], $baris)];
        $this->pakai(Presensi::BEBAN_TETAP_RENTANG_RUMUS_IMPOR + $baris * $kolom + intdiv($baris * $baris, Presensi::PEMBAGI_KUADRAT_BARIS_RUMUS_IMPOR));
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
     * @param list<string>|null $koordinat
     *
     * @return array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed, luas: int, baris: int}
     */
    private static function operand(?array $koordinat, ?string $sheet = null, ?string $nama = null, int $luas = 0, int $baris = 0): array
    {
        return ['koordinat' => $koordinat, 'sheet' => $sheet, 'nama' => $nama, 'galat' => false, 'nilai' => null, 'luas' => $luas, 'baris' => $baris];
    }

    /**
     * @return array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed, luas: int, baris: int}
     */
    private static function galat(): array
    {
        return ['koordinat' => null, 'sheet' => null, 'nama' => null, 'galat' => true, 'nilai' => null, 'luas' => 0, 'baris' => 0];
    }
}
