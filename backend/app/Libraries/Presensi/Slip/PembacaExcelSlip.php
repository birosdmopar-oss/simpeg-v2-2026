<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\Presensi\SlipGaji\HasilSlip;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use Config\Presensi;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Pembaca berkas Excel impor Slip Gaji (PRD 5A §7.2.3 butir 1; README `Libraries/Presensi` D-10). Di luar folder murni
 * `SlipGaji` karena memakai PhpSpreadsheet dan I/O berkas.
 *
 * - `IOFactory::createReaderForFile` dikunci ke reader `Xlsx`/`Xls` (berkas yang hanya dikenali reader lain, mis. HTML
 *   atau CSV, ditolak sebagai tidak terbaca), `setReadDataOnly(true)`, hanya sheet `GPP` dan `TK`
 *   (`KolomGaji::cariSheetWajib`, keduanya wajib, I-1).
 * - Ukuran berkas diperiksa SEBELUM dimuat (CR-177, {@see self::praCek()}): `.xlsx` lewat {@see PemindaiXlsx} (semua
 *   entri ZIP, aliran), `.xls` lewat `listWorksheetInfo`. Berkas kecil yang "lebar", padat ratusan ribu baris, atau
 *   mengembang besar saat dibuka ditolak tanpa dimuat ke memori.
 * - Nilai sel **mentah** tanpa format tampilan; sel rumus = hasil hitung (`getCalculatedValue`); rich text → teks polos.
 * - Keluaran per sheet = `[nomor baris berkas => list nilai sel]` mulai baris 1 (header) — masukan
 *   `PratinjauImpor::uraiSheet($baris, $sheet)`.
 * - Batas → {@see BerkasImporDitolak}: baris data per sheet ("Jumlah baris melebihi batas …"), kolom per sheet
 *   (`Config\Presensi::MAKS_KOLOM_IMPOR`), jumlah sel per berkas (`MAKS_SEL_IMPOR`), dan ukuran isi sesudah dibuka
 *   (`MAKS_BYTE_ISI_IMPOR`). Urutan pemeriksaan: ukuran isi, baris, kolom, jumlah sel.
 * - Berkas rusak/tidak terbaca → {@see BerkasImporDitolak} pesan generik (P5-39); rincian exception hanya ke log
 *   aplikasi, tanpa isi sel.
 */
final class PembacaExcelSlip
{
    public const PESAN_TIDAK_TERBACA = 'Berkas tidak dapat dibaca. Pastikan berkas Excel (.xlsx/.xls) yang valid.';

    /**
     * Reader yang boleh dipakai; tanpa daftar ini `createReaderForFile` mencoba semua reader (Csv, Html, Ods, …).
     */
    private const READER = ['Xlsx', 'Xls'];

    public function __construct(
        private readonly int $maksBarisPerSheet,
        private readonly int $maksKolomPerSheet = Presensi::MAKS_KOLOM_IMPOR,
        private readonly int $maksSel = Presensi::MAKS_SEL_IMPOR,
        private readonly int $maksByteIsi = Presensi::MAKS_BYTE_ISI_IMPOR,
    ) {
    }

    /**
     * @return HasilSlip `nilai` = array{GPP: array<int, list<mixed>>, TK: array<int, list<mixed>>}; gagal
     *                   `sheet_tidak_ada` (dari `KolomGaji::cariSheetWajib`)
     *
     * @throws BerkasImporDitolak
     */
    public function baca(string $path): HasilSlip
    {
        try {
            $reader = IOFactory::createReaderForFile($path, self::READER);
            $reader->setReadDataOnly(true);
        } catch (Throwable $e) {
            throw self::tidakTerbaca($e);
        }

        // xlsx dipindai sebelum apa pun dibaca reader (termasuk daftar nama sheet, yang menampung `workbook.xml` utuh).
        $ukuran = $reader instanceof Xlsx ? $this->pindaiXlsx($path) : null;

        try {
            /** @var list<string> $namaSheet */
            $namaSheet = array_values($reader->listWorksheetNames($path));
        } catch (Throwable $e) {
            throw self::tidakTerbaca($e);
        }

        $cari = KolomGaji::cariSheetWajib($namaSheet);

        if (! $cari->valid) {
            return $cari;
        }

        /** @var array{GPP: int, TK: int} $indeks */
        $indeks = $cari->nilai;
        $nama   = [KolomGaji::SHEET_GPP => $namaSheet[$indeks[KolomGaji::SHEET_GPP]], KolomGaji::SHEET_TK => $namaSheet[$indeks[KolomGaji::SHEET_TK]]];

        if ($ukuran !== null) {
            $this->periksaUkuran([[$ukuran['baris'], $ukuran['kolom']]]);
            $this->periksaSel($ukuran['sel']);
        } else {
            $this->praCekXls($path, array_values($nama));
        }

        try {
            $reader->setLoadSheetsOnly(array_values($nama));
            $buku = $reader->load($path);
        } catch (Throwable $e) {
            throw self::tidakTerbaca($e);
        }

        try {
            $lembar = [];
            $ukuran = [];

            foreach ($nama as $sheet => $namaAsli) {
                $l = $buku->getSheetByName($namaAsli);

                if ($l === null) {
                    throw new BerkasImporDitolak(self::PESAN_TIDAK_TERBACA);
                }

                $lembar[$sheet] = $l;
                $ukuran[$sheet] = [$l->getHighestDataRow(), Coordinate::columnIndexFromString($l->getHighestDataColumn())];
            }

            // Lapis kedua sesudah pra-cek: diperiksa sebelum array baris × kolom disusun.
            $this->periksaUkuran(array_values($ukuran));
            $hasil = [];

            foreach ($lembar as $sheet => $l) {
                $hasil[$sheet] = self::barisMentah($l, $ukuran[$sheet][0], $ukuran[$sheet][1]);
            }
        } catch (BerkasImporDitolak $e) {
            throw $e;
        } catch (Throwable $e) {
            throw self::tidakTerbaca($e);
        } finally {
            $buku->disconnectWorksheets();
        }

        return HasilSlip::berhasil($hasil);
    }

    /**
     * Pra-cek `.xlsx` lewat {@see PemindaiXlsx}: SEMUA entri ZIP dipindai sebagai aliran, tanpa mengikuti relasi, jadi
     * batas baris dan kolom berlaku untuk setiap sheet di berkas (bukan hanya GPP dan TK) dan hitungannya tidak bisa
     * berbeda dari part yang akhirnya dimuat. `listWorksheetInfo` reader Xlsx TIDAK dipakai (alasan di docblock pemindai).
     *
     * Hitungan baris = batas atas dari yang dibuat pemuat: sel kosong yang hanya bergaya ikut dihitung, sama seperti saat
     * dimuat (pemuat membuat sel untuk `<c r="…" s="…"/>`), jadi berkas yang dulu ditolak karena itu tetap ditolak.
     * Bedanya dengan hitungan pasca-muat: `<row>` TANPA sel (mis. hanya tinggi baris) ikut dihitung sebagai baris.
     *
     * Read filter (`IReadFilter`) SENGAJA TIDAK dipasang pada pemuat, jangan ditambahkan: begitu ada read filter kustom,
     * reader Xlsx memanggilnya untuk setiap kolom `<cols>` × setiap `<row>` (`Reader/Xlsx/ColumnAndRowAttributes`, juga saat
     * `setReadDataOnly(true)`). Diukur pada PhpSpreadsheet 5.10 dengan berkas 51 KB berisi 10.001 baris satu sel:
     * `<cols min="1" max="16383">` → muat 0,2 detik tanpa filter, 28,4 detik dengan filter (163,9 juta panggilan);
     * `<cols>` sampai 1025 (bentuk yang biasa ditulis LibreOffice) → 0,2 detik menjadi 2,0 detik. Filter menukar celah
     * memori dengan celah waktu. Tanpa filter pun muatan sudah terbatas oleh batas jumlah sel dan ukuran isi
     * (dijaga `PembacaExcelSlipBatasBacaTest`).
     *
     * @return array{baris: int, kolom: int, sel: int, byte: int}
     *
     * @throws BerkasImporDitolak
     */
    private function pindaiXlsx(string $path): array
    {
        try {
            $ukuran = PemindaiXlsx::pindai($path, $this->maksByteIsi);
        } catch (Throwable $e) {
            throw self::tidakTerbaca($e);
        }

        if ($ukuran['byte'] > $this->maksByteIsi) {
            throw new BerkasImporDitolak('Isi berkas terlalu besar sesudah dibuka (maksimal ' . number_format($this->maksByteIsi / 1048576, 0, ',', '.') . ' MB).');
        }

        return $ukuran;
    }

    /**
     * Pra-cek `.xls`: `listWorksheetInfo` reader Xls memindai rekaman sel yang sama dengan yang dipakai pemuat, jadi
     * hitungannya sepadan. Jumlah sel `.xls` sudah terbatas oleh ukuran unggah (tanpa kompresi, ≥ 6 byte per sel).
     *
     * @param list<string> $nama nama asli sheet GPP dan TK di berkas
     *
     * @throws BerkasImporDitolak
     */
    private function praCekXls(string $path, array $nama): void
    {
        try {
            $info = array_column(IOFactory::createReaderForFile($path, self::READER)->listWorksheetInfo($path), null, 'worksheetName');
        } catch (Throwable $e) {
            throw self::tidakTerbaca($e);
        }

        $ukuran = [];

        foreach ($nama as $n) {
            if (isset($info[$n])) {
                $ukuran[] = [(int) $info[$n]['totalRows'], (int) $info[$n]['totalColumns']];
            }
        }

        $this->periksaUkuran($ukuran);
    }

    /**
     * Baris dulu untuk semua sheet, baru kolom: hasilnya tidak bergantung urutan tab maupun urutan entri di berkas.
     *
     * @param list<array{0: int, 1: int}> $ukuran [baris terakhir, kolom terakhir] per sheet
     *
     * @throws BerkasImporDitolak
     */
    private function periksaUkuran(array $ukuran): void
    {
        foreach ($ukuran as [$barisTerakhir]) {
            if ($barisTerakhir - 1 > $this->maksBarisPerSheet) {
                throw new BerkasImporDitolak('Jumlah baris melebihi batas ' . number_format($this->maksBarisPerSheet, 0, ',', '.') . ' per sheet.');
            }
        }

        foreach ($ukuran as [, $kolomTerakhir]) {
            if ($kolomTerakhir > $this->maksKolomPerSheet) {
                throw new BerkasImporDitolak('Jumlah kolom melebihi batas ' . number_format($this->maksKolomPerSheet, 0, ',', '.') . ' per sheet.');
            }
        }
    }

    /**
     * @throws BerkasImporDitolak
     */
    private function periksaSel(int $sel): void
    {
        if ($sel > $this->maksSel) {
            throw new BerkasImporDitolak('Jumlah sel melebihi batas ' . number_format($this->maksSel, 0, ',', '.') . ' per berkas.');
        }
    }

    /**
     * @return array<int, list<mixed>>
     */
    private static function barisMentah(Worksheet $lembar, int $barisTerakhir, int $kolomTerakhir): array
    {
        $baris = [];

        for ($r = 1; $r <= $barisTerakhir; $r++) {
            $sel = [];

            for ($c = 1; $c <= $kolomTerakhir; $c++) {
                $sel[] = self::nilaiMentah($lembar, $c, $r);
            }

            $baris[$r] = $sel;
        }

        return $baris;
    }

    private static function nilaiMentah(Worksheet $lembar, int $kolom, int $baris): mixed
    {
        if (! $lembar->cellExists([$kolom, $baris])) {
            return null;
        }

        $cell  = $lembar->getCell([$kolom, $baris]);
        $nilai = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();

        return $nilai instanceof RichText ? $nilai->getPlainText() : $nilai;
    }

    private static function tidakTerbaca(Throwable $e): BerkasImporDitolak
    {
        log_message('error', 'Impor Slip Gaji: berkas tidak terbaca ({jenis}).', ['jenis' => $e::class]);

        return new BerkasImporDitolak(self::PESAN_TIDAK_TERBACA, 0, $e);
    }
}
