<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * XLSX rekap Tukin (PRD 5B §7.3.4, PhpSpreadsheet ADR-032) — port tata letak `L_presensi.php:13139-13486` legacy `0cf7e67`:
 * sheet "Laporan Tunjangan Kinerja"; judul A1:Q6 "<judul>\n<LINGKUP>\nPeriode: <bulan>"; header baris 7–8 `NO | NAMA |
 * NIP | UNIT ES.I | UNIT ES.II | JUMLAH PENGURANGAN (%) [TL|PSW|TK|TA|CUTI|TB] | TOTAL PENGURANGAN PRESENSI (%) | TOTAL
 * PENGURANGAN SKP (%) | TOTAL POTONGAN (%) (PRESENSI + SKP) | KETERANGAN`; data mulai baris 9 (angka sebagai angka, NIP teks);
 * legenda TL/PSW/TK/TA di A; blok penanda tangan di kolom M baris data terakhir +2 (jabatan), +9 (nama), +10 (NIP)
 * [K `:13421-13444`]; A4 lanskap, fit to width. [V2] salah ketik legacy "POTONGGAN" dan "Telambat" diperbaiki; pegawai yang
 * gagal dihitung: kolom angka kosong, KETERANGAN = pesan galat.
 */
final class EksporTukinXlsx
{
    public const HEADER = ['NO', 'NAMA', 'NIP', 'UNIT ES.I', 'UNIT ES.II', 'JUMLAH PENGURANGAN (%)', 'TOTAL PENGURANGAN PRESENSI (%)', 'TOTAL PENGURANGAN SKP (%)', 'TOTAL POTONGAN (%) (PRESENSI + SKP)', 'KETERANGAN'];

    public const SUB_PENGURANGAN = ['TL', 'PSW', 'TK', 'TA', 'CUTI', 'TB'];

    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>>                                         $baris         baris rekap (`RekapTukinService`)
     * @param array{kasus: string, jabatan: string, nama: string, nip: string}   $penandaTangan
     */
    public static function rekap(string $judul, string $lingkup, string $periode, array $baris, array $penandaTangan): string
    {
        $buku = new Spreadsheet();
        $buku->getProperties()->setCreator('SIMPEG')->setTitle('Laporan Tunjangan Kinerja')->setSubject($lingkup);
        $sheet = $buku->getActiveSheet()->setTitle('Laporan Tunjangan Kinerja');
        $sheet->getParent()?->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);

        $sheet->mergeCells('A1:Q6');
        $sheet->setCellValueExplicit('A1', $judul . "\n" . mb_strtoupper($lingkup) . "\nPeriode: " . $periode, DataType::TYPE_STRING);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1:Q6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

        foreach (['A' => 'NO', 'B' => 'NAMA', 'C' => 'NIP', 'D' => 'UNIT ES.I', 'E' => 'UNIT ES.II', 'L' => self::HEADER[6], 'M' => self::HEADER[7], 'N' => self::HEADER[8], 'O' => 'KETERANGAN'] as $kol => $teks) {
            $sheet->mergeCells("{$kol}7:{$kol}8");
            $sheet->setCellValueExplicit("{$kol}7", $teks, DataType::TYPE_STRING);
        }

        $sheet->mergeCells('F7:K7');
        $sheet->setCellValueExplicit('F7', self::HEADER[5], DataType::TYPE_STRING);

        foreach (self::SUB_PENGURANGAN as $i => $teks) {
            $sheet->setCellValueExplicit(chr(ord('F') + $i) . '8', $teks, DataType::TYPE_STRING);
        }

        $sheet->getStyle('A7:O8')->getFont()->setBold(true);
        $sheet->getStyle('A7:O8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $r = 8;

        foreach ($baris as $i => $b) {
            $r++;
            $sheet->setCellValue("A{$r}", $i + 1);

            foreach (['B' => 'nama', 'C' => 'nip', 'D' => 'unit_es1', 'E' => 'unit_es2'] as $kol => $kunci) {
                $sheet->setCellValueExplicit("{$kol}{$r}", (string) ($b[$kunci] ?? ''), DataType::TYPE_STRING);
            }

            if (is_array($b['pengurangan'] ?? null)) {
                foreach (self::SUB_PENGURANGAN as $j => $k) {
                    $sheet->setCellValue(chr(ord('F') + $j) . $r, (float) $b['pengurangan'][$k]);
                }

                $sheet->setCellValue("L{$r}", (float) $b['total_presensi']);
                $sheet->setCellValue("M{$r}", (float) $b['total_skp']);
                $sheet->setCellValue("N{$r}", (float) $b['total_potongan']);
            }

            $ket = is_array($b['galat'] ?? null) ? (string) $b['galat']['message'] : (string) ($b['keterangan'] ?? '');
            $sheet->setCellValueExplicit("O{$r}", $ket, DataType::TYPE_STRING);
        }

        if ($r > 8) {
            $sheet->getStyle("A7:O{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('666666');
            $sheet->getStyle("A9:O{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        }

        $r++;

        foreach ([2 => ['Note', ''], 3 => ['TL', ': Terlambat'], 4 => ['PSW', ': Pulang Sebelum Waktunya'], 5 => ['TK', ': Tanpa Keterangan'], 6 => ['TA', ': Tidak Absen']] as $tambah => [$a, $b]) {
            $sheet->setCellValueExplicit('A' . ($r + $tambah), $a, DataType::TYPE_STRING);

            if ($b !== '') {
                $sheet->setCellValueExplicit('B' . ($r + $tambah), $b, DataType::TYPE_STRING);
            }
        }

        $sheet->mergeCells('M' . ($r + 2) . ':Q' . ($r + 3));
        $sheet->mergeCells('M' . ($r + 4) . ':Q' . ($r + 8));
        $sheet->mergeCells('M' . ($r + 9) . ':Q' . ($r + 9));
        $sheet->mergeCells('M' . ($r + 10) . ':Q' . ($r + 10));
        $sheet->setCellValueExplicit('M' . ($r + 2), $penandaTangan['jabatan'], DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('M' . ($r + 9), $penandaTangan['nama'], DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('M' . ($r + 10), $penandaTangan['nip'] === '' ? '' : 'NIP. ' . $penandaTangan['nip'], DataType::TYPE_STRING);
        $sheet->getStyle('M' . ($r + 9))->getFont()->setUnderline(true);
        $sheet->getStyle('M' . ($r + 2) . ':Q' . ($r + 10))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);

        foreach (['A' => 7, 'B' => 40, 'C' => 21, 'D' => 40, 'E' => 40, 'F' => 8, 'G' => 8, 'H' => 8, 'I' => 8, 'J' => 8, 'K' => 8, 'L' => 11.67, 'M' => 11.67, 'N' => 11.67, 'O' => 50] as $kol => $lebar) {
            $sheet->getColumnDimension($kol)->setWidth($lebar);
        }

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0)->setHorizontalCentered(true);
        $sheet->getPageSetup()->setPrintArea('A1:Q' . ($r + 10));

        $aliran = fopen('php://memory', 'w+b');

        if ($aliran === false) {
            throw new RuntimeException('Gagal membuat berkas XLSX.');
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
