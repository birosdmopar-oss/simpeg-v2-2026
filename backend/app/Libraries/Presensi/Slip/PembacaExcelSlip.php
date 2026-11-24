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
 * - Nilai sel **mentah** tanpa format tampilan; sel rumus = hasil hitung (`getCalculatedValue`); rich text → teks polos.
 * - Keluaran per sheet = `[nomor baris berkas => list nilai sel]` mulai baris 1 (header) — masukan
 *   `PratinjauImpor::uraiSheet($baris, $sheet)`.
 * - Berkas rusak/tidak terbaca → {@see BerkasImporDitolak} pesan generik (P5-39); rincian exception hanya ke log
 *   aplikasi, tanpa isi sel.
 *
 * Pengaman memori dan waktu (CR-177), dua lapis:
 *
 * 1. SEBELUM dimuat, anggaran SELURUH berkas. `.xlsx`: {@see PemindaiXlsx} memindai semua entri ZIP sebagai aliran →
 *    ukuran isi sesudah dekompresi, jumlah sel, bobot elemen XML, dan iterasi `<col>` (konstanta `Config\Presensi`).
 *    `.xls`: `listWorksheetInfo` (rekaman sel yang sama dengan pemuat) → baris, kolom, dan baris × kolom sheet GPP/TK.
 *    Anggaran ini yang membatasi memori pemuat; batas baris/kolom per part TIDAK diperiksa di sini, jadi sheet lain di
 *    berkas (referensi panjang, matriks lebar) dan `<row>` berformat tanpa sel tetap diterima seperti sebelum CR-177.
 * 2. SESUDAH dimuat, batas baris data dan kolom sheet GPP dan TK ("Jumlah baris melebihi batas …", "Jumlah kolom melebihi
 *    batas …"), diperiksa sebelum array baris × kolom disusun: satu sel di kolom XFD tidak lagi berarti 16.384 kolom ×
 *    semua baris. GPP diperiksa dulu, lalu TK, jadi pesan tidak bergantung urutan tab. Keduanya dimuat dalam SATU
 *    muatan supaya rumus lintas sheet tetap terhitung; berkas yang kedua sheet-nya berisi part yang sama ditolak
 *    {@see PemuatXlsxSlip}.
 *
 * Read filter (`IReadFilter`) SENGAJA TIDAK dipasang pada pemuat, jangan ditambahkan: begitu ada read filter kustom,
 * reader Xlsx memanggilnya untuk setiap kolom `<cols>` × setiap `<row>` (`Reader/Xlsx/ColumnAndRowAttributes`, juga saat
 * `setReadDataOnly(true)`). Diukur pada PhpSpreadsheet 5.10 dengan berkas 51 KB berisi 10.001 baris satu sel:
 * `<cols min="1" max="16383">` → muat 0,2 detik tanpa filter, 28,4 detik dengan filter (163,9 juta panggilan); `<cols>`
 * sampai 1025 (bentuk yang biasa ditulis LibreOffice) → 0,2 detik menjadi 2,0 detik. Filter menukar celah memori dengan
 * celah waktu (dijaga `PembacaExcelSlipBatasBacaTest`).
 */
final class PembacaExcelSlip
{
    public const PESAN_TIDAK_TERBACA = 'Berkas tidak dapat dibaca. Pastikan berkas Excel (.xlsx/.xls) yang valid.';
    public const PESAN_STRUKTUR      = 'Struktur berkas terlalu besar untuk diproses. Simpan ulang berkas hanya dengan sheet GPP dan TK, tanpa rumus (tempel sebagai nilai) atau teks sangat panjang.';

    /**
     * Reader yang boleh dipakai; tanpa daftar ini `createReaderForFile` mencoba semua reader (Csv, Html, Ods, …).
     */
    private const READER = ['Xlsx', 'Xls'];

    private readonly int $maksSel;
    private readonly int $maksBobot;

    /**
     * @param int|null $maksSel   bawaan (batas baris + 1) × `Presensi::SEL_PER_BARIS_IMPOR`; batas baris di bawah
     *                            `Presensi::BARIS_ACUAN_ANGGARAN_IMPOR` dihitung sebagai nilai acuan itu
     * @param int|null $maksBobot bawaan batas sel × `Presensi::BOBOT_PER_SEL_IMPOR`
     */
    public function __construct(
        private readonly int $maksBarisPerSheet,
        private readonly int $maksKolomPerSheet = Presensi::MAKS_KOLOM_IMPOR,
        ?int $maksSel = null,
        private readonly int $maksByteIsi = Presensi::MAKS_BYTE_ISI_IMPOR,
        ?int $maksBobot = null,
        private readonly int $maksIterasiKolom = Presensi::MAKS_ITERASI_KOLOM_IMPOR,
    ) {
        $this->maksSel   = $maksSel ?? (max($maksBarisPerSheet, Presensi::BARIS_ACUAN_ANGGARAN_IMPOR) + 1) * Presensi::SEL_PER_BARIS_IMPOR;
        $this->maksBobot = $maksBobot ?? $this->maksSel * Presensi::BOBOT_PER_SEL_IMPOR;
    }

    /**
     * @return HasilSlip `nilai` = array{GPP: array<int, list<mixed>>, TK: array<int, list<mixed>>}; gagal
     *                   `sheet_tidak_ada` (dari `KolomGaji::cariSheetWajib`)
     *
     * @throws BerkasImporDitolak
     */
    public function baca(string $path): HasilSlip
    {
        // Sebelum reader vendor menyentuh berkas (CR-188): mengenali jenis berkas saja sudah menampung entri ZIP utuh.
        $this->praCekArsip($path);

        try {
            $reader = IOFactory::createReaderForFile($path, self::READER);
            $xlsx   = $reader instanceof Xlsx;

            // Pemuat xlsx yang menolak dua sheet berisi part yang sama (lihat PemuatXlsxSlip).
            if ($xlsx) {
                $reader = new PemuatXlsxSlip();
            }

            $reader->setReadDataOnly(true);
        } catch (Throwable $e) {
            throw self::tidakTerbaca($e);
        }

        // xlsx dipindai sebelum apa pun dibaca reader (termasuk daftar nama sheet, yang menampung `workbook.xml` utuh).
        if ($xlsx) {
            $this->praCekXlsx($path);
        }

        try {
            /** @var list<string> $namaSheet */
            $namaSheet = array_values($reader->listWorksheetNames($path));
        } catch (Throwable $e) {
            throw self::tidakTerbaca($e);
        }

        // Excel tidak mengizinkan nama sheet kembar (tanpa membedakan huruf besar-kecil). Berkas buatan dengan nama kembar
        // membuat pemuat memuat part yang sama berkali-kali, padahal pemindai menghitungnya sekali.
        if (count(array_unique(array_map(static fn (string $n): string => mb_strtolower($n), $namaSheet))) !== count($namaSheet)) {
            throw self::tidakTerbaca(new BerkasImporDitolak('Nama sheet kembar.'));
        }

        $cari = KolomGaji::cariSheetWajib($namaSheet);

        if (! $cari->valid) {
            return $cari;
        }

        /** @var array{GPP: int, TK: int} $indeks */
        $indeks = $cari->nilai;
        $nama   = [KolomGaji::SHEET_GPP => $namaSheet[$indeks[KolomGaji::SHEET_GPP]], KolomGaji::SHEET_TK => $namaSheet[$indeks[KolomGaji::SHEET_TK]]];

        if (! $xlsx) {
            $this->praCekXls($path, array_values($nama));
        }

        // GPP dan TK dimuat dalam SATU muatan: rumus lintas sheet (TK → GPP, GPP → TK, nama terdefinisi) hanya terhitung
        // bila kedua sheet ada di workbook yang sama. Dimuat terpisah, hasilnya "#REF!".
        try {
            $reader->setLoadSheetsOnly(array_values($nama));
            $buku = $reader->load($path);
        } catch (Throwable $e) {
            throw self::tidakTerbaca($e);
        }

        try {
            $hasil = [];

            // GPP diperiksa dulu, lalu TK; masing-masing sebelum array baris × kolomnya disusun.
            foreach ($nama as $sheet => $namaAsli) {
                $lembar = $buku->getSheetByName($namaAsli);

                if ($lembar === null) {
                    throw new BerkasImporDitolak(self::PESAN_TIDAK_TERBACA);
                }

                $barisTerakhir = $lembar->getHighestDataRow();
                $kolomTerakhir = Coordinate::columnIndexFromString($lembar->getHighestDataColumn());

                $this->periksaUkuran([[$barisTerakhir, $kolomTerakhir]]);
                $hasil[$sheet] = self::barisMentah($lembar, $barisTerakhir, $kolomTerakhir);
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
     * Ukuran isi arsip sebelum APA PUN dibaca darinya (CR-188). `IOFactory::createReaderForFile` menjalankan
     * `Xlsx::canRead`, yang mendekompresi `_rels/.rels` utuh ke memori (dua kali) SEBELUM {@see PemindaiXlsx::pindai()}
     * berjalan: `_rels/.rels` 700 MB (berkas ±3 MB) dulu berakhir fatal memori (500), bukan 422. Hanya entri itu yang
     * ditampung utuh sebelum pemindai; entri lain yang dibaca utuh (`[Content_Types].xml`, relasi workbook,
     * `xl/workbook.xml` lewat `listWorksheetNames`) baru dibaca sesudah pemindai dan sudah tertolak di sana. Sebagai pagar
     * umum, jumlah ukuran SEMUA entri menurut direktori pusat ZIP dibandingkan dengan batas isi yang sama dengan
     * {@see self::praCekXlsx()}, tanpa mendekompresi apa pun.
     *
     * Berkas yang bukan ZIP (`.xls`) tidak diperiksa di sini, dan tahap pengenalan reader untuk `.xls` BELUM dipagari:
     * berkas berkepala OLE palsu masih bisa fatal memori di `Xls::canRead` (lihat README BB-2).
     *
     * @throws BerkasImporDitolak
     */
    private function praCekArsip(string $path): void
    {
        $byte = PemindaiXlsx::ukuranTerdekompresi($path);

        if ($byte !== null && $byte > $this->maksByteIsi) {
            throw $this->isiTerlaluBesar();
        }
    }

    private function isiTerlaluBesar(): BerkasImporDitolak
    {
        return new BerkasImporDitolak('Isi berkas terlalu besar sesudah dibuka (maksimal ' . number_format($this->maksByteIsi / 1048576, 0, ',', '.') . ' MB).');
    }

    /**
     * Anggaran seluruh berkas `.xlsx` (lihat {@see PemindaiXlsx} dan docblock konstanta `Config\Presensi`). Urutan: ukuran
     * isi, jumlah sel, lalu struktur (satu pesan): bobot elemen, iterasi `<col>`, jumlah `<tablePart>`, dan byte teks yang
     * disalin pemuat per sel (shared string dan rumus shared; batasnya sama dengan batas ukuran isi).
     *
     * @throws BerkasImporDitolak
     */
    private function praCekXlsx(string $path): void
    {
        try {
            $ukuran = PemindaiXlsx::pindai($path, $this->maksByteIsi, $this->maksBobot);
        } catch (Throwable $e) {
            throw self::tidakTerbaca($e);
        }

        if ($ukuran['byte'] > $this->maksByteIsi) {
            throw $this->isiTerlaluBesar();
        }

        $this->periksaSel($ukuran['sel']);

        if ($ukuran['bobot'] > $this->maksBobot || $ukuran['iterasi_kolom'] > $this->maksIterasiKolom
            || $ukuran['tabel'] > Presensi::MAKS_TABEL_IMPOR || $ukuran['salinan'] > $this->maksByteIsi) {
            throw new BerkasImporDitolak(self::PESAN_STRUKTUR);
        }
    }

    /**
     * Pra-cek `.xls`: `listWorksheetInfo` reader Xls memindai rekaman sel yang sama dengan yang dipakai pemuat, jadi
     * hitungannya sepadan. Batas sel memakai baris × kolom sheet GPP dan TK (batas atas jumlah sel yang dimuat): tanpa
     * itu `.xls` 5 MB masih bisa memuat ±870 ribu sel (rekaman MULRK, 6 byte per sel).
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
        $sel    = 0;

        foreach ($nama as $n) {
            if (isset($info[$n])) {
                $ukuran[] = [(int) $info[$n]['totalRows'], (int) $info[$n]['totalColumns']];
                $sel += (int) $info[$n]['totalRows'] * (int) $info[$n]['totalColumns'];
            }
        }

        $this->periksaUkuran($ukuran);
        $this->periksaSel($sel);
    }

    /**
     * Baris dulu untuk semua sheet yang diberikan, baru kolom.
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
            throw new BerkasImporDitolak('Jumlah sel melebihi batas ' . number_format($this->maksSel, 0, ',', '.') . ' per berkas. Hapus sheet atau kolom yang tidak dipakai, lalu unggah ulang.');
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
