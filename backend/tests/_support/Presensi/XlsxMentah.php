<?php

declare(strict_types=1);

namespace Tests\Support\Presensi;

use RuntimeException;
use ZipArchive;

/**
 * Penulis `.xlsx` minimal dari XML mentah (CR-177), untuk berkas uji yang tidak bisa atau terlalu berat dibuat lewat
 * writer PhpSpreadsheet: sel di kolom XFD, ratusan ribu baris, sel kosong tutup-sendiri, `<row>` tanpa sel. Hanya bagian
 * yang dibaca reader Xlsx: `[Content_Types].xml`, relasi, `workbook.xml`, dan satu `sheetN.xml` per sheet.
 */
final class XlsxMentah
{
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $sheet  nama sheet => isi `<sheetData>` (deretan `<row>`)
     * @param array<string, string> $awalan nama sheet => XML sebelum `<sheetData>` (mis. `<cols>…</cols>`)
     */
    public static function tulis(string $path, array $sheet, array $awalan = []): void
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Berkas xlsx uji gagal dibuat.');
        }

        $kepala = '<?xml version="1.0" encoding="UTF-8"?>';
        $paket  = 'http://schemas.openxmlformats.org/package/2006/';
        $dok    = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $utama  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $jenis  = 'application/vnd.openxmlformats-officedocument.spreadsheetml.';
        $tipe   = '';
        $daftar = '';
        $relasi = '';
        $i      = 0;

        foreach ($sheet as $nama => $isi) {
            $i++;
            $tipe .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="' . $jenis . 'worksheet+xml"/>';
            $daftar .= '<sheet name="' . $nama . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $relasi .= '<Relationship Id="rId' . $i . '" Type="' . $dok . '/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', $kepala . '<worksheet xmlns="' . $utama . '">' . ($awalan[$nama] ?? '') . '<sheetData>' . $isi . '</sheetData></worksheet>');
        }

        $zip->addFromString('[Content_Types].xml', $kepala . '<Types xmlns="' . $paket . 'content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="' . $jenis . 'sheet.main+xml"/>' . $tipe . '</Types>');
        $zip->addFromString('_rels/.rels', $kepala . '<Relationships xmlns="' . $paket . 'relationships"><Relationship Id="rId1" Type="' . $dok . '/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $kepala . '<workbook xmlns="' . $utama . '" xmlns:r="' . $dok . '"><sheets>' . $daftar . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $kepala . '<Relationships xmlns="' . $paket . 'relationships">' . $relasi . '</Relationships>');
        $zip->close();
    }

    /**
     * Satu `<row>` berisi sel teks (inline string) atau angka, mulai kolom A. `null` = sel dilewati.
     *
     * @param list<float|int|string|null> $nilai
     */
    public static function baris(int $nomor, array $nilai): string
    {
        $sel = '';

        foreach ($nilai as $k => $v) {
            if ($v !== null) {
                $sel .= self::sel(self::kolom($k + 1) . $nomor, $v);
            }
        }

        return '<row r="' . $nomor . '">' . $sel . '</row>';
    }

    public static function sel(string $alamat, float|int|string $nilai): string
    {
        if (is_string($nilai)) {
            return '<c r="' . $alamat . '" t="inlineStr"><is><t>' . htmlspecialchars($nilai, ENT_XML1) . '</t></is></c>';
        }

        return '<c r="' . $alamat . '"><v>' . $nilai . '</v></c>';
    }

    private static function kolom(int $indeks): string
    {
        $huruf = '';

        while ($indeks > 0) {
            $sisa   = ($indeks - 1) % 26;
            $huruf  = chr(65 + $sisa) . $huruf;
            $indeks = intdiv($indeks - 1, 26);
        }

        return $huruf;
    }
}
