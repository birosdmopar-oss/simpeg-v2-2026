<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Pegawai;

use RuntimeException;
use ZipArchive;

/**
 * Penulis tabel ekspor (B-20 penutup, MAKE-014; keputusan user 13-10-2026 Q-M6-2): CSV UTF-8 ber-BOM (pemisah `,`,
 * dibuka Excel tanpa salah enkode) dan XLSX minimal (OOXML SpreadsheetML lewat ZipArchive, tanpa library baru). Semua sel
 * teks (`inlineStr`) sehingga NIP 18 digit tidak berubah menjadi angka ilmiah dan tidak pernah dievaluasi sebagai rumus.
 * CSV: nilai yang (setelah spasi awal) diawali `=`, `+`, `-`, `@`, atau diawali TAB/CR diberi apostrof (cegah CSV/formula
 * injection, OWASP).
 *
 * CSV tidak punya tipe sel, jadi kolom IDENTITAS (NIP, NIP lama; CR-050 temuan QA A-F1) ditulis sebagai rumus teks Excel
 * `="<nilai>"` agar Excel/LibreOffice membacanya sebagai teks (NIP 18 digit tidak menjadi angka ilmiah, nol depan utuh).
 * Hanya untuk nilai berkarakter aman `[0-9A-Za-z./ -]` (tanpa `"`, tidak bisa keluar dari literal string → tidak bisa
 * menyisipkan rumus lain); nilai lain di kolom itu tetap lewat aman(). Pembaca CSV non-Excel melihat `="…"`; XLSX
 * (bawaan UI) tetap sel teks murni.
 */
final class EksporTabel
{
    public const CSV  = 'csv';
    public const XLSX = 'xlsx';

    public const MIME = [
        self::CSV  => 'text/csv; charset=UTF-8',
        self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** Nilai sel CSV aman dari formula injection. */
    public static function aman(string $nilai): string
    {
        $awal = ltrim($nilai);

        return ($awal !== '' && str_contains('=+-@', $awal[0])) || ($nilai !== '' && str_contains("\t\r", $nilai[0])) ? "'" . $nilai : $nilai;
    }

    /**
     * Nilai sel CSV kolom identitas: `="<nilai>"` bila berkarakter aman (lihat docblock kelas), selain itu aman().
     */
    public static function teks(string $nilai): string
    {
        return preg_match('/^[0-9A-Za-z.\/ -]+$/', $nilai) === 1 ? '="' . $nilai . '"' : self::aman($nilai);
    }

    /**
     * @param list<string>       $header
     * @param list<list<string>> $baris
     * @param list<int>          $kolomTeks indeks kolom identitas (NIP dsb.) yang ditulis sebagai teks Excel — teks()
     */
    public static function csv(array $header, array $baris, array $kolomTeks = []): string
    {
        $f = fopen('php://temp', 'w+b');

        if ($f === false) {
            throw new RuntimeException('Tidak dapat membuat berkas ekspor.');
        }

        fwrite($f, "\xEF\xBB\xBF");

        fputcsv($f, array_map(self::aman(...), $header), ',', '"', '');

        foreach ($baris as $r) {
            $sel = [];

            foreach ($r as $i => $v) {
                $sel[] = in_array($i, $kolomTeks, true) ? self::teks($v) : self::aman($v);
            }

            fputcsv($f, $sel, ',', '"', '');
        }

        rewind($f);
        $isi = (string) stream_get_contents($f);
        fclose($f);

        return $isi;
    }

    /**
     * @param list<string>       $header
     * @param list<list<string>> $baris
     */
    public static function xlsx(array $header, array $baris, string $judulSheet = 'Data'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($tmp === false) {
            throw new RuntimeException('Tidak dapat membuat berkas ekspor.');
        }

        $zip = new ZipArchive();

        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);

            throw new RuntimeException('Tidak dapat membuat berkas ekspor.');
        }

        $sheet = self::esc(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/u', ' ', $judulSheet) ?? 'Data', 0, 31));

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $sheet . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Gaya 0 = biasa, 1 = tebal (header).
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>');

        $rows = '';

        foreach ([$header, ...$baris] as $i => $r) {
            $n    = $i + 1;
            $sel  = '';
            $gaya = $i === 0 ? ' s="1"' : '';

            foreach ($r as $j => $v) {
                $sel .= '<c r="' . self::kolom($j) . $n . '" t="inlineStr"' . $gaya . '><is><t xml:space="preserve">' . self::esc($v) . '</t></is></c>';
            }

            $rows .= '<row r="' . $n . '">' . $sel . '</row>';
        }

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>');
        $zip->close();

        $isi = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $isi;
    }

    /** Huruf kolom Excel dari indeks 0 (A, B, …, Z, AA, …). */
    public static function kolom(int $i): string
    {
        $s = '';

        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }

        return $s;
    }

    private static function esc(string $v): string
    {
        // Buang karakter kontrol yang tidak sah di XML 1.0.
        $v = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $v);

        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
