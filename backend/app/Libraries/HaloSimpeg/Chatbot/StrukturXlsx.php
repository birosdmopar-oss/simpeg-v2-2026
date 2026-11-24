<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg\Chatbot;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx\Namespaces;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Pra-pemeriksaan struktur `.xlsx` impor QnA yang MENIRU langkah awal `Reader\Xlsx::listWorksheetInfo` PhpSpreadsheet
 * 5.10 (review CR-182 R4 B2), tanpa mengurai part sheet. Subkelas reader dipakai supaya resolusi path entri
 * (`getFromZipArchive`: `//`, `./`, `realpath`, tanpa peka huruf, backslash), pemindai keamanan (`XmlScanner::scan`,
 * termasuk konversi UTF-16/encoding lain) dan namespace SimpleXML sama persis dengan vendor:
 *
 * - setiap Relationship di `_rels/.rels` selain thumbnail diproses vendor sebagai workbook (`listWorksheetInfoFromFile`);
 * - untuk setiap relasi itu, setiap `<sheet>` di `sheets` workbook-nya membuat vendor mengurai satu part sheet, termasuk
 *   rujukan berulang ke part yang sama.
 *
 * Juga menghitung biaya penerjemahan rumus shared (B3): vendor menerjemahkan rumus induk untuk setiap sel anak
 * (`Reader\Xlsx::castToFormula` → `ReferenceHelper::updateFormulaReferences`) walau readDataOnly; dulu read filter
 * melewati sel di luar A-D. Dihitung dari SEMUA entri dengan XMLReader (batas atas; entri yang gagal diurai berhenti di
 * titik galat, sama seperti SimpleXML vendor yang menolak part rusak).
 *
 * @internal dipakai {@see ImporQna}
 */
final class StrukturXlsx extends Xlsx
{
    private const NS_SHEET = [Namespaces::MAIN, Namespaces::PURL_MAIN];

    /**
     * Biaya tetap per sel anak rumus shared dalam satuan "byte rumus": terukur ±0,12 ms per anak walau rumusnya pendek
     * (44 ribu anak rumus 5 byte: 5,3 s), sebanding dengan ±100 byte teks rumus.
     */
    private const BIAYA_TETAP_ANAK = 100;

    /**
     * @return array{relasi: int, sheet: int, nama: list<string>, byte_meta: int}
     *
     * @throws RuntimeException berkas tidak terbaca / XML tidak sah / pemindai keamanan menolak
     */
    public function workbook(string $path, int $maksByteMeta): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Arsip xlsx tidak dapat dibuka.');
        }

        try {
            $byteMeta = 0;
            $rels     = $this->muat($zip, self::INITIAL_FILE, Namespaces::RELATIONSHIPS, $maksByteMeta, $byteMeta);
            $relasi   = 0;
            $sheet    = 0;
            $nama     = [];

            foreach ($rels->Relationship as $relx) {
                $rel     = self::getAttributes($relx);
                $relType = (string) $rel['Type'];

                // Sama dengan `Reader\Xlsx::REL_TO_MAIN` (privat): thumbnail dilewati, selain itu diproses.
                if ($relType === Namespaces::THUMBNAIL) {
                    continue;
                }

                $mainNs = $relType === Namespaces::PURL_OFFICE_DOCUMENT ? Namespaces::PURL_MAIN : Namespaces::MAIN;
                $relasi++;
                $workbook = $this->muat($zip, (string) $rel['Target'], $mainNs, $maksByteMeta, $byteMeta);

                if ($workbook->sheets) {
                    foreach ($workbook->sheets->sheet as $eleSheet) {
                        $sheet++;
                        $nama[] = (string) self::getAttributes($eleSheet)['name'];
                    }
                }
            }

            return ['relasi' => $relasi, 'sheet' => $sheet, 'nama' => $nama, 'byte_meta' => $byteMeta];
        } finally {
            $zip->close();
        }
    }

    /**
     * Biaya penerjemahan rumus shared: Σ per sel anak (BIAYA_TETAP_ANAK + panjang teks rumus induk), dari semua entri.
     */
    public static function biayaRumusShared(string $path): int
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Arsip xlsx tidak dapat dibuka.');
        }

        $biaya = 0;

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nama = $zip->getNameIndex($i);

                if ($nama === false) {
                    continue;
                }

                $biaya += self::biayaEntri('zip://' . $path . '#' . $nama);
            }
        } finally {
            $zip->close();
        }

        return $biaya;
    }

    private static function biayaEntri(string $uri): int
    {
        $xml = new XMLReader();

        if (! @$xml->open($uri, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return 0;
        }

        /** @var array<string, int> $induk si => panjang teks induk */
        $induk = [];
        $biaya = 0;

        try {
            while (@$xml->read()) {
                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'f' || ! in_array($xml->namespaceURI, self::NS_SHEET, true)) {
                    continue;
                }

                if (strtolower((string) $xml->getAttribute('t')) !== 'shared') {
                    continue;
                }

                $si = (string) $xml->getAttribute('si');

                if (! isset($induk[$si])) {
                    $induk[$si] = $xml->isEmptyElement ? 0 : strlen($xml->readString());
                } else {
                    $biaya += self::BIAYA_TETAP_ANAK + $induk[$si];
                }
            }
        } finally {
            $xml->close();
        }

        return $biaya;
    }

    /**
     * Seperti `Reader\Xlsx::loadZip` (privat): isi lewat `getFromZipArchive`, dipindai `XmlScanner`, lalu SimpleXML
     * dengan namespace yang sama. Isi di atas `$maksByteMeta` (jumlah semua dokumen meta yang dibaca) ditolak.
     */
    private function muat(ZipArchive $zip, string $nama, string $ns, int $maksByteMeta, int &$byteMeta): \SimpleXMLElement
    {
        $isi = $this->getFromZipArchive($zip, $nama);
        $byteMeta += strlen($isi);

        if ($byteMeta > $maksByteMeta) {
            throw new RuntimeException('Dokumen meta xlsx terlalu besar.');
        }

        $xml = @simplexml_load_string($this->getSecurityScannerOrThrow()->scan($isi), \SimpleXMLElement::class, 0, $ns);

        return self::testSimpleXml($xml);
    }
}
