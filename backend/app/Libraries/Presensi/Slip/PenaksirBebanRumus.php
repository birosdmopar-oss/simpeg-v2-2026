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
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Penaksir beban hitung satu sel rumus impor Slip Gaji (CR-181), dari TEKS rumusnya saja: tidak ada sel yang dihitung
 * dan tidak ada nilai tersimpan yang dibaca. Dipakai {@see PembacaExcelSlip} sebelum `getCalculatedValue()`; nilai sel
 * tetap hasil hitung ulang seperti legacy.
 *
 * Satuan beban ±5–10 mikrodetik hitung di mesin ukur (tabel di README `Libraries/Presensi` BB-12):
 *
 * - beban dasar = `Presensi::BEBAN_DASAR_RUMUS_IMPOR` + panjang teks rumus;
 * - tiap rentang yang dijabarkan kalkulator = baris × kolom + baris² / `Presensi::PEMBAGI_KUADRAT_BARIS_RUMUS_IMPOR`
 *   (kalkulator PhpSpreadsheet menjabarkan rentang menjadi daftar sel, dan biayanya tumbuh kuadratik menurut jumlah
 *   baris);
 * - rentang kolom/baris penuh (`J:J`, `2:2`) dipangkas ke baris/kolom data tertinggi sheet rujukan, sama seperti
 *   kalkulator, ditambah biaya pemindaian indeks sel yang dilakukan kalkulator untuk memangkasnya;
 * - nama terdefinisi dan nama tabel ditaksir dari isinya (berulang, paling dalam `MAKS_KEDALAMAN_NAMA`);
 * - {@see self::TAK_HINGGA} untuk rumus yang luas rujukannya baru diketahui saat dihitung (`INDIRECT`, `OFFSET`,
 *   pembangkit larik seperti `SEQUENCE`, operator `:` dengan operand hasil hitung) dan untuk rumus yang tidak bisa diurai.
 *
 * Supaya tidak menyimpang dari kalkulator, teks rumus diurai dengan pengurai kalkulator itu sendiri
 * (`Calculation::parseFormula`, diperiksa pada PhpSpreadsheet 5.10.0), lalu daftar tokennya dijalankan secara abstrak:
 * yang dilacak hanya rujukan tiap operand, mengikuti aturan `Calculation::processTokenStack`. Semua cabang `IF` dihitung
 * (kalkulator bisa melewati cabang yang tidak terpakai), jadi taksiran tidak pernah di bawah kerja kalkulator untuk
 * rentang. Pengurai dipanggil tanpa sel, sehingga dua hal yang bergantung pada sel ditiru di sini: sheet bawaan rujukan
 * tanpa nama sheet, dan pemangkasan rentang kolom/baris penuh.
 *
 * Rumus tanpa `:`, `[`, `#`, nama terdefinisi, nama tabel, dan fungsi dinamis tidak mungkin menjabarkan rentang, jadi
 * tidak diurai sama sekali (jalur cepat).
 */
final class PenaksirBebanRumus
{
    public const TAK_HINGGA = PHP_INT_MAX;

    private const MAKS_KEDALAMAN_NAMA = 8;
    private const BARIS_LEMBAR        = 1_048_576;
    private const KOLOM_LEMBAR        = 'XFD';

    /**
     * Fungsi yang luas kerjanya ditentukan nilai saat dihitung, bukan teks rumus.
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
     * Nama terdefinisi, nama tabel, dan fungsi dinamis (huruf kecil): rumus yang memuat salah satunya wajib diurai.
     *
     * @var array<string, true>
     */
    private array $kataBerat = self::FUNGSI_DINAMIS;

    /**
     * @var array<string, int> judul sheet => baris data tertinggi
     */
    private array $barisTertinggi = [];

    /**
     * @var array<string, string> "judul sheet|baris" => kolom data tertinggi baris itu
     */
    private array $kolomTertinggi = [];

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
     * Beban hitung sel rumus `$sel`; {@see self::TAK_HINGGA} bila tidak bisa dibatasi dari teksnya.
     */
    public function beban(Cell $sel): int
    {
        $rumus = $sel->getValue();

        if (! is_string($rumus)) {
            return Presensi::BEBAN_DASAR_RUMUS_IMPOR;
        }

        $dasar = Presensi::BEBAN_DASAR_RUMUS_IMPOR + strlen($rumus);

        if (! $this->perluDiurai($rumus)) {
            return $dasar;
        }

        try {
            return self::tambah($dasar, $this->bebanRujukan($rumus, $sel->getWorksheet(), $sel, 0));
        } catch (Throwable) {
            // Tidak terurai, dinamis, atau nama terdefinisi terlalu dalam/berputar: salah ke arah menolak.
            return self::TAK_HINGGA;
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
     * Jumlah beban semua rentang yang dijabarkan kalkulator untuk `$rumus` bila dihitung di sheet `$konteks`.
     *
     * @throws Throwable rumus tidak terurai atau luas rujukannya tidak bisa dibatasi
     */
    private function bebanRujukan(string $rumus, Worksheet $konteks, ?Cell $sel, int $kedalaman): int
    {
        $token = $this->pengurai->parseFormula($rumus);

        if (! is_array($token)) {
            throw new RuntimeException('Rumus tidak terurai.');
        }

        $token    = array_values($token);
        $beban    = 0;
        $tumpukan = [];

        foreach ($token as $i => $t) {
            $t     = is_array($t) ? $t : [];
            $nilai = $t['value'] ?? null;

            if ($nilai instanceof StructuredReference) {
                $beban      = self::tambah($beban, $this->bebanRujukanTerstruktur($nilai, $sel, $kedalaman));
                $tumpukan[] = self::operand(null);

                continue;
            }

            if (is_string($nilai) && isset(self::OPERATOR_BINER[$nilai])) {
                $kanan = array_pop($tumpukan);
                $kiri  = array_pop($tumpukan);

                // Kalkulator berhenti dengan galat di sini; yang sudah dijabarkan sebelumnya tetap dihitung.
                if ($kanan === null || $kiri === null) {
                    return $beban;
                }

                $tumpukan[] = $nilai === ':' ? $this->rentang($kiri, $kanan, $konteks, $beban) : self::operand(null);

                continue;
            }

            if ($nilai === '~' || $nilai === '%') {
                if (array_pop($tumpukan) === null) {
                    return $beban;
                }

                $tumpukan[] = self::operand(null);

                continue;
            }

            if (is_string($nilai) && preg_match(self::POLA_SEL, $nilai, $m) === 1) {
                $sheet = trim((string) $m[2], "\"'");

                // Rujukan ke workbook lain: kalkulator berhenti di token ini.
                if (str_contains($sheet, '[') || str_contains($sheet, ']')) {
                    return $beban;
                }

                $kolom = strtoupper($m[6]);
                $baris = $m[7];
                $jenis = $t['type'] ?? '';

                if ($jenis === 'Column Reference' && (int) $baris === self::BARIS_LEMBAR) {
                    $rujuk = $this->sheetPangkas($token[$i - 1] ?? null, $konteks);

                    if ($rujuk !== null) {
                        $baris = (string) $this->barisTertinggi($rujuk);
                        $beban = self::tambah($beban, self::bebanPindai($rujuk));
                    }
                } elseif ($jenis === 'Row Reference' && $kolom === self::KOLOM_LEMBAR) {
                    $rujuk = $this->sheetPangkas($token[$i - 1] ?? null, $konteks);

                    if ($rujuk !== null) {
                        $kolom = $this->kolomTertinggi($rujuk, (int) $baris);
                        $beban = self::tambah($beban, self::bebanPindai($rujuk));
                    }
                }

                // Sheet yang tidak ada: kalkulator memakai rujukan tanpa sheet (= sheet sel yang dihitung).
                $tumpukan[] = self::operand([$kolom . $baris], $sheet !== '' && $this->buku->getSheetByName($sheet) !== null ? $sheet : null);

                continue;
            }

            if (is_string($nilai) && preg_match(self::POLA_FUNGSI, $nilai, $m) === 1) {
                if (isset(self::FUNGSI_DINAMIS[strtolower($m[1])])) {
                    throw new RuntimeException('Fungsi dinamis.');
                }

                $jumlah = array_pop($tumpukan)['nilai'] ?? null;

                if (! is_int($jumlah)) {
                    return $beban;
                }

                for ($a = 0; $a < $jumlah; $a++) {
                    if (array_pop($tumpukan) === null) {
                        return $beban;
                    }
                }

                $tumpukan[] = self::operand(null);

                continue;
            }

            if (is_string($nilai) && $nilai !== '' && ! is_numeric($nilai) && ! Calculation::keyInExcelConstants(strtoupper($nilai))
                && $nilai[0] !== '"' && $nilai[0] !== '#' && preg_match(self::POLA_NAMA, $nilai, $m) === 1) {
                // Kalkulator mengembalikan nilai tersimpan untuk nama `_xleta…` tanpa melanjutkan.
                if (str_starts_with($m[6], '_xleta')) {
                    return $beban;
                }

                $tumpukan[] = $this->nama($m[6], trim($m[2], "'"), $konteks, $kedalaman, $beban);

                continue;
            }

            // Angka, teks, konstanta, galat, argumen kosong, dan jumlah argumen fungsi.
            $rujukan    = is_string($t['reference'] ?? null) ? [$t['reference']] : null;
            $tumpukan[] = ['koordinat' => $rujukan, 'sheet' => null, 'nama' => null, 'galat' => false, 'nilai' => $nilai];
        }

        return $beban;
    }

    /**
     * Operator `:` (`Calculation::processTokenStack`): kotak pembatas semua koordinat kedua operand, dijabarkan di sheet
     * operand kiri.
     *
     * @param array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed} $kiri
     * @param array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed} $kanan
     *
     * @return array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed}
     */
    private function rentang(array $kiri, array $kanan, Worksheet $konteks, int &$beban): array
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

        if ($this->buku->getSheetByName($sheetKiri) !== null) {
            $beban = self::tambah($beban, self::bebanRentang(max($baris) - min($baris) + 1, max($kolom) - min($kolom) + 1));
        }

        return self::operand(
            [Coordinate::stringFromColumnIndex(min($kolom)) . min($baris), Coordinate::stringFromColumnIndex(max($kolom)) . max($baris)],
            $sheetKiri,
        );
    }

    /**
     * Nama terdefinisi atau nama tabel (`Calculation::processTokenStack` + `evaluateDefinedName`): isinya dihitung ulang
     * setiap kali dipakai.
     *
     * @return array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed}
     */
    private function nama(string $nama, string $sheet, Worksheet $konteks, int $kedalaman, int &$beban): array
    {
        $terdefinisi = DefinedName::resolveName($nama, $konteks, $sheet);

        if ($terdefinisi === null) {
            $tabel = $this->buku->getTableByName($nama);

            if ($tabel === null) {
                return self::galat();
            }

            // Kalkulator membuang baris judul/total; taksiran memakai seluruh rentang tabel.
            $beban = self::tambah($beban, $this->bebanIsiNama('=' . $tabel->getRange(), $tabel->getWorksheet() ?? $konteks, $kedalaman));

            return self::operand([$nama], null, $nama);
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

        $beban = self::tambah($beban, $this->bebanIsiNama(str_starts_with($isi, '=') ? $isi : '=' . $isi, $sheetHitung, $kedalaman));

        // Rujukan operand hasil nama = namanya sendiri (bukan koordinat), persis seperti kalkulator.
        $rujukan = $terdefinisi->getLocalOnly() && $lembarNama !== null ? "'" . $lembarNama->getTitle() . "'!" . $terdefinisi->getName() : $terdefinisi->getName();

        return self::operand([$rujukan], null, $rujukan);
    }

    private function bebanIsiNama(string $rumus, Worksheet $konteks, int $kedalaman): int
    {
        if ($kedalaman >= self::MAKS_KEDALAMAN_NAMA) {
            throw new RuntimeException('Nama terdefinisi terlalu dalam.');
        }

        return self::tambah(Presensi::BEBAN_DASAR_RUMUS_IMPOR + strlen($rumus), $this->bebanRujukan($rumus, $konteks, null, $kedalaman + 1));
    }

    /**
     * Rujukan terstruktur (`Tabel[Kolom]`): kalkulator mengubahnya menjadi rentang biasa lalu menghitung rentang itu.
     */
    private function bebanRujukanTerstruktur(StructuredReference $rujukan, ?Cell $sel, int $kedalaman): int
    {
        if ($sel === null) {
            throw new RuntimeException('Rujukan terstruktur di luar sel.');
        }

        try {
            $rentang = $rujukan->parse($sel);
        } catch (Throwable) {
            // Kalkulator menghasilkan #REF! atau berhenti dengan galat: tidak ada yang dijabarkan.
            return 0;
        }

        return str_contains($rentang, ':') ? $this->bebanIsiNama('=' . $rentang, $sel->getWorksheet(), $kedalaman) : 0;
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

    private static function bebanRentang(int $baris, int $kolom): int
    {
        return $baris * $kolom + intdiv($baris * $baris, Presensi::PEMBAGI_KUADRAT_BARIS_RUMUS_IMPOR);
    }

    private static function tambah(int $a, int $b): int
    {
        return $a > self::TAK_HINGGA - $b ? self::TAK_HINGGA : $a + $b;
    }

    /**
     * @param list<string>|null $koordinat
     *
     * @return array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed}
     */
    private static function operand(?array $koordinat, ?string $sheet = null, ?string $nama = null): array
    {
        return ['koordinat' => $koordinat, 'sheet' => $sheet, 'nama' => $nama, 'galat' => false, 'nilai' => null];
    }

    /**
     * @return array{koordinat: list<string>|null, sheet: string|null, nama: string|null, galat: bool, nilai: mixed}
     */
    private static function galat(): array
    {
        return ['koordinat' => null, 'sheet' => null, 'nama' => null, 'galat' => true, 'nilai' => null];
    }
}
