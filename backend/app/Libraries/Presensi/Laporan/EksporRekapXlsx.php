<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Libraries\Presensi\Rekap\KlasifikasiKehadiran;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Penulis XLSX rekap D-09b (PhpSpreadsheet, ADR-032), dibangun server [V2] (legacy: ekspor DataTables di browser untuk
 * ringkas & uang makan; `.xls` Excel 97 untuk harian). NIP & tanggal ditulis sebagai teks eksplisit (NIP 18 digit tidak
 * menjadi angka ilmiah); semua nilai teks ditulis sebagai string (tidak pernah dievaluasi sebagai rumus).
 */
final class EksporRekapXlsx
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const WARNA_WEEKEND = 'C5D9F1';

    private const WARNA_LIBUR = 'F2DCDB';

    private function __construct()
    {
    }

    /**
     * Tabel ringkas: baris judul, satu baris kosong, header, data. Kolom ke-3 (NIP) teks; angka sebagai angka.
     *
     * @param list<string>                   $judul
     * @param list<string>                   $header
     * @param list<list<int|string>>         $baris
     */
    public static function ringkas(array $judul, array $header, array $baris, string $namaSheet = 'Rekap Presensi', ?int $kolomNip = 3): string
    {
        $buku  = new Spreadsheet();
        $sheet = $buku->getActiveSheet()->setTitle($namaSheet);
        $r     = 1;

        foreach ($judul as $teks) {
            self::teks($sheet, 1, $r++, $teks);
        }

        if ($judul !== []) {
            $r++;
        }

        foreach ($header as $i => $teks) {
            self::teks($sheet, $i + 1, $r, $teks);
        }

        $sheet->getStyle([1, $r, count($header), $r])->getFont()->setBold(true);

        foreach ($baris as $isi) {
            $r++;

            foreach ($isi as $i => $nilai) {
                is_int($nilai) && $i + 1 !== $kolomNip
                    ? $sheet->setCellValue([$i + 1, $r], $nilai)
                    : self::teks($sheet, $i + 1, $r, (string) $nilai);
            }
        }

        return self::tulis($buku);
    }

    /**
     * Grid harian [K `L_presensi.php:1156-1424`]: judul gabungan baris 1–6; header baris 7 `NO, NAMA, NIP, UNIT ES.I,
     * UNIT ES.II, TANGGAL…, KETERANGAN` (KETERANGAN digabung baris 7–8, isinya kosong [K `:1340-1341`]); baris 8 nomor hari;
     * data mulai baris 9; hari non-kerja kosong berwarna (Sabtu/Minggu `C5D9F1`, libur `F2DCDB`).
     *
     * @param list<string> $tanggal semua tanggal kalender sampai batas
     * @param list<string> $libur
     * @param list<array{no: int, nama: string, nip: string, unit: string, satker: string, harian: array<string, string|null>}> $baris
     */
    public static function harian(string $judul, array $tanggal, array $libur, array $baris): string
    {
        $buku   = new Spreadsheet();
        $sheet  = $buku->getActiveSheet()->setTitle('Laporan Kehadiran');
        $jumlah = count($tanggal);
        $akhir  = 5 + max(1, $jumlah) + 1;
        $libur  = array_fill_keys($libur, true);

        $sheet->mergeCells([1, 1, $akhir, 6]);
        self::teks($sheet, 1, 1, $judul);
        $sheet->getStyle([1, 1, $akhir, 6])->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        foreach (['NO', 'NAMA', 'NIP', 'UNIT ES.I', 'UNIT ES.II'] as $i => $teks) {
            $sheet->mergeCells([$i + 1, 7, $i + 1, 8]);
            self::teks($sheet, $i + 1, 7, $teks);
        }

        self::teks($sheet, 6, 7, 'TANGGAL');
        $sheet->mergeCells([6, 7, max(6, 5 + $jumlah), 7]);
        $sheet->mergeCells([$akhir, 7, $akhir, 8]);
        self::teks($sheet, $akhir, 7, 'KETERANGAN');

        foreach ($tanggal as $i => $t) {
            $sheet->setCellValue([6 + $i, 8], (int) substr($t, 8, 2));
        }

        $sheet->getStyle([1, 7, $akhir, 8])->getFont()->setBold(true);
        $r = 8;

        foreach ($baris as $b) {
            $r++;
            $sheet->setCellValue([1, $r], $b['no']);
            self::teks($sheet, 2, $r, $b['nama']);
            self::teks($sheet, 3, $r, $b['nip']);
            self::teks($sheet, 4, $r, $b['unit']);
            self::teks($sheet, 5, $r, $b['satker']);

            foreach ($tanggal as $i => $t) {
                $kode = $b['harian'][$t] ?? null;

                if ($kode === KlasifikasiKehadiran::WEEKEND || $kode === KlasifikasiKehadiran::LIBUR) {
                    $warna = $kode === KlasifikasiKehadiran::WEEKEND ? self::WARNA_WEEKEND : self::WARNA_LIBUR;
                    $sheet->getStyle([6 + $i, $r])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($warna);

                    continue;
                }

                if ($kode !== null) {
                    self::teks($sheet, 6 + $i, $r, $kode);
                }
            }
        }

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);

        return self::tulis($buku);
    }

    /**
     * Uang makan [K `V/rekap_uma.php:89-104`]: dua kolom `NIP | TGL_ABSEN`, tanpa judul.
     *
     * @param list<array{nip: string, tgl_absen: string}> $baris
     */
    public static function uangMakan(array $baris): string
    {
        $buku  = new Spreadsheet();
        $sheet = $buku->getActiveSheet()->setTitle('Rekap Uang Makan');
        self::teks($sheet, 1, 1, 'NIP');
        self::teks($sheet, 2, 1, 'TGL_ABSEN');
        $sheet->getStyle([1, 1, 2, 1])->getFont()->setBold(true);

        foreach ($baris as $i => $b) {
            self::teks($sheet, 1, $i + 2, $b['nip']);
            self::teks($sheet, 2, $i + 2, $b['tgl_absen']);
        }

        return self::tulis($buku);
    }

    private static function teks(Worksheet $sheet, int $kolom, int $baris, string $nilai): void
    {
        $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($kolom) . $baris, $nilai, DataType::TYPE_STRING);
    }

    private static function tulis(Spreadsheet $buku): string
    {
        $aliran = fopen('php://memory', 'w+b');

        if ($aliran === false) {
            throw new \RuntimeException('Gagal membuat berkas XLSX.');
        }

        try {
            (new Xlsx($buku))->save($aliran);
            rewind($aliran);

            return (string) stream_get_contents($aliran);
        } finally {
            fclose($aliran);
            $buku->disconnectWorksheets();
        }
    }
}
