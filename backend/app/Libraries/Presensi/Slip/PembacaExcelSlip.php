<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\Presensi\SlipGaji\HasilSlip;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Pembaca berkas Excel impor Slip Gaji (PRD 5A §7.2.3 butir 1; README `Libraries/Presensi` D-10). Di luar folder murni
 * `SlipGaji` karena memakai PhpSpreadsheet dan I/O berkas.
 *
 * - `IOFactory::createReaderForFile` (xlsx/xls), `setReadDataOnly(true)`, hanya sheet `GPP` dan `TK`
 *   (`KolomGaji::cariSheetWajib`, keduanya wajib, I-1).
 * - Nilai sel **mentah** tanpa format tampilan; sel rumus = hasil hitung (`getCalculatedValue`); rich text → teks polos.
 * - Keluaran per sheet = `[nomor baris berkas => list nilai sel]` mulai baris 1 (header) — masukan
 *   `PratinjauImpor::uraiSheet($baris, $sheet)`.
 * - Baris data (di luar header) per sheet > batas → {@see BerkasImporDitolak} "Jumlah baris melebihi batas …".
 * - Berkas rusak/tidak terbaca → {@see BerkasImporDitolak} pesan generik (P5-39); rincian exception hanya ke log
 *   aplikasi, tanpa isi sel.
 */
final class PembacaExcelSlip
{
    public const PESAN_TIDAK_TERBACA = 'Berkas tidak dapat dibaca. Pastikan berkas Excel (.xlsx/.xls) yang valid.';

    public function __construct(private readonly int $maksBarisPerSheet)
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
            $reader = IOFactory::createReaderForFile($path);
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
     * @return array<int, list<mixed>>
     */
    private function barisMentah(Worksheet $lembar): array
    {
        $barisTerakhir = $lembar->getHighestDataRow();

        if ($barisTerakhir - 1 > $this->maksBarisPerSheet) {
            throw new BerkasImporDitolak('Jumlah baris melebihi batas ' . number_format($this->maksBarisPerSheet, 0, ',', '.') . ' per sheet.');
        }

        $kolomTerakhir = Coordinate::columnIndexFromString($lembar->getHighestDataColumn());
        $baris         = [];

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
