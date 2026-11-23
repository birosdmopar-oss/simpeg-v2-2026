<?php

declare(strict_types=1);

namespace Tests\Support\Presensi;

use RuntimeException;
use ZipArchive;

/**
 * Penulis `.xlsx` minimal dari XML mentah (CR-177), untuk berkas uji yang tidak bisa atau terlalu berat dibuat lewat
 * writer PhpSpreadsheet: sel di kolom XFD, ratusan ribu baris, sel kosong tutup-sendiri, `<row>` tanpa sel atau tanpa
 * atribut `r`, relasi sheet ber-`Target` absolut, entri tambahan. Hanya bagian yang dibaca reader Xlsx:
 * `[Content_Types].xml`, relasi, `workbook.xml`, dan satu `sheetN.xml` per sheet.
 */
final class XlsxMentah
{
    public const KEPALA = '<?xml version="1.0" encoding="UTF-8"?>';
    public const UTAMA  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $sheet  nama sheet => isi `<sheetData>` (deretan `<row>`)
     * @param array<string, string> $awalan nama sheet => XML sebelum `<sheetData>` (mis. `<cols>…</cols>`)
     */
    public static function tulis(string $path, array $sheet, array $awalan = []): void
    {
        self::tulisEntri($path, self::entri($sheet, $awalan));
    }

    /**
     * Entri ZIP baku untuk workbook berisi `$sheet`; boleh diubah/ditambah pemanggil sebelum {@see tulisEntri()}.
     * Part sheet ke-N ada di `xl/worksheets/sheetN.xml`, relasinya `rIdN` di `xl/_rels/workbook.xml.rels`.
     *
     * @param array<string, string> $sheet
     * @param array<string, string> $awalan
     *
     * @return array<string, string> nama entri => isi
     */
    public static function entri(array $sheet, array $awalan = []): array
    {
        $paket  = 'http://schemas.openxmlformats.org/package/2006/';
        $dok    = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $jenis  = 'application/vnd.openxmlformats-officedocument.spreadsheetml.';
        $tipe   = '';
        $daftar = '';
        $relasi = '';
        $part   = [];
        $i      = 0;

        foreach ($sheet as $nama => $isi) {
            $i++;
            $tipe .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="' . $jenis . 'worksheet+xml"/>';
            $daftar .= '<sheet name="' . $nama . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $relasi .= '<Relationship Id="rId' . $i . '" Type="' . $dok . '/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $part['xl/worksheets/sheet' . $i . '.xml'] = self::lembar($isi, $awalan[$nama] ?? '');
        }

        return [
            '[Content_Types].xml' => self::KEPALA . '<Types xmlns="' . $paket . 'content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="' . $jenis . 'sheet.main+xml"/>' . $tipe . '</Types>',
            '_rels/.rels'                => self::KEPALA . '<Relationships xmlns="' . $paket . 'relationships"><Relationship Id="rId1" Type="' . $dok . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'            => self::KEPALA . '<workbook xmlns="' . self::UTAMA . '" xmlns:r="' . $dok . '"><sheets>' . $daftar . '</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => self::KEPALA . '<Relationships xmlns="' . $paket . 'relationships">' . $relasi . '</Relationships>',
            ...$part,
        ];
    }

    /**
     * Part worksheet utuh dari isi `<sheetData>`.
     */
    public static function lembar(string $isi, string $awalan = ''): string
    {
        return self::KEPALA . '<worksheet xmlns="' . self::UTAMA . '">' . $awalan . '<sheetData>' . $isi . '</sheetData></worksheet>';
    }

    /**
     * @param array<string, string> $entri nama entri ZIP => isi
     */
    public static function tulisEntri(string $path, array $entri): void
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Berkas xlsx uji gagal dibuat.');
        }

        foreach ($entri as $nama => $isi) {
            $zip->addFromString($nama, $isi);
        }

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

    /**
     * Sel rumus TANPA nilai tersimpan (`<v>`), seperti tulisan generator yang tidak menghitung: nilainya hanya bisa
     * berasal dari hitung ulang.
     */
    public static function rumus(string $alamat, string $rumus): string
    {
        return '<c r="' . $alamat . '"><f>' . htmlspecialchars(ltrim($rumus, '='), ENT_XML1) . '</f></c>';
    }

    public static function kolom(int $indeks): string
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
