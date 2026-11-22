<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\Presensi\SlipGaji\HasilSlip;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use Config\Presensi;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
 * - Ukuran kedua sheet diperiksa SEBELUM berkas dimuat (CR-177, {@see self::praCek()}): berkas kecil yang "lebar" atau
 *   padat ratusan ribu baris ditolak tanpa dimuat ke memori. Sesudah lolos, yang dimuat paling banyak
 *   (batas baris + 1) × batas kolom sel per sheet.
 * - Nilai sel **mentah** tanpa format tampilan; sel rumus = hasil hitung (`getCalculatedValue`); rich text → teks polos.
 * - Keluaran per sheet = `[nomor baris berkas => list nilai sel]` mulai baris 1 (header) — masukan
 *   `PratinjauImpor::uraiSheet($baris, $sheet)`.
 * - Baris data (di luar header) per sheet > batas → {@see BerkasImporDitolak} "Jumlah baris melebihi batas …"; kolom
 *   per sheet > batas (`Config\Presensi::MAKS_KOLOM_IMPOR`) → "Jumlah kolom melebihi batas …".
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

    public function __construct(private readonly int $maksBarisPerSheet, private readonly int $maksKolomPerSheet = Presensi::MAKS_KOLOM_IMPOR)
    {
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

        $this->praCek($path, array_values($nama));

        try {
            $reader->setLoadSheetsOnly(array_values($nama));
            $buku = $reader->load($path);
        } catch (Throwable $e) {
            throw self::tidakTerbaca($e);
        }

        try {
            $hasil = [];

            foreach ($nama as $sheet => $namaAsli) {
                $lembar = $buku->getSheetByName($namaAsli);

                if ($lembar === null) {
                    throw new BerkasImporDitolak(self::PESAN_TIDAK_TERBACA);
                }

                $hasil[$sheet] = $this->barisMentah($lembar);
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
     * Ukuran sheet GPP dan TK dibaca lewat `listWorksheetInfo` (xlsx: XML sheet dipindai berurutan tanpa membangun sel;
     * xls: rekaman sel dipindai) dengan reader TERSENDIRI, supaya setelan reader pemuat tidak berubah.
     *
     * `setReadEmptyCells` sengaja dibiarkan bawaan (true): sel kosong yang hanya bergaya ikut dihitung, sama seperti saat
     * dimuat (pemuat membuat sel untuk `<c r="…" s="…"/>`), jadi berkas yang dulu ditolak karena itu tetap ditolak. Bedanya
     * dengan hitungan pasca-muat hanya satu: pada xlsx, `<row>` TANPA sel (mis. hanya tinggi baris) ikut dihitung sebagai
     * baris.
     *
     * Read filter (`IReadFilter`) SENGAJA TIDAK dipasang pada pemuat, jangan ditambahkan: begitu ada read filter kustom,
     * reader Xlsx memanggilnya untuk setiap kolom `<cols>` × setiap `<row>` (`Reader/Xlsx/ColumnAndRowAttributes`, juga saat
     * `setReadDataOnly(true)`). Diukur pada PhpSpreadsheet 5.10 dengan berkas 51 KB berisi 10.001 baris satu sel:
     * `<cols min="1" max="16383">` → muat 0,2 detik tanpa filter, 28,4 detik dengan filter (163,9 juta panggilan);
     * `<cols>` sampai 1025 (bentuk yang biasa ditulis LibreOffice) → 0,2 detik menjadi 2,0 detik. Filter menukar celah
     * memori dengan celah waktu. Tanpa filter pun muatan sudah terbatas: sesudah pra-cek lolos, sel yang dibuat paling
     * banyak (batas baris + 1) × batas kolom per sheet (dijaga `PembacaExcelSlipBatasBacaTest`).
     *
     * @param list<string> $nama nama asli sheet GPP dan TK di berkas
     *
     * @throws BerkasImporDitolak
     */
    private function praCek(string $path, array $nama): void
    {
        try {
            $info = IOFactory::createReaderForFile($path, self::READER)->listWorksheetInfo($path);
        } catch (Throwable $e) {
            throw self::tidakTerbaca($e);
        }

        foreach ($info as $i) {
            if (in_array($i['worksheetName'], $nama, true)) {
                $this->periksaUkuran((int) $i['totalRows'], (int) $i['totalColumns']);
            }
        }
    }

    /**
     * @throws BerkasImporDitolak
     */
    private function periksaUkuran(int $barisTerakhir, int $kolomTerakhir): void
    {
        if ($barisTerakhir - 1 > $this->maksBarisPerSheet) {
            throw new BerkasImporDitolak('Jumlah baris melebihi batas ' . number_format($this->maksBarisPerSheet, 0, ',', '.') . ' per sheet.');
        }

        if ($kolomTerakhir > $this->maksKolomPerSheet) {
            throw new BerkasImporDitolak('Jumlah kolom melebihi batas ' . number_format($this->maksKolomPerSheet, 0, ',', '.') . ' per sheet.');
        }
    }

    /**
     * @return array<int, list<mixed>>
     */
    private function barisMentah(Worksheet $lembar): array
    {
        $barisTerakhir = $lembar->getHighestDataRow();
        $kolomTerakhir = Coordinate::columnIndexFromString($lembar->getHighestDataColumn());

        // Lapis kedua sesudah pra-cek (mis. `<row>` tidak berurutan di XML): diperiksa sebelum array baris × kolom disusun.
        $this->periksaUkuran($barisTerakhir, $kolomTerakhir);

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
