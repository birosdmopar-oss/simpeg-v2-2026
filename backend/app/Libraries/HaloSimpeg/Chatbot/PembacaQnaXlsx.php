<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg\Chatbot;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx\Namespaces;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Shared\File;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Pembaca impor QnA mandiri (review CR-182 R5/R6): membaca HANYA sheet pertama, sel A1:D(maks+1) dan E1, tanpa
 * `Reader\Xlsx::load()`/`listWorksheetInfo()`.
 *
 * Ditulis terhadap phpoffice/phpspreadsheet **5.10.0** persis; potongan yang ditiru dari `Reader/Xlsx.php` versi itu:
 * `getWorkbookBaseName` (:2639-2663), `load()` resolusi relasi/workbook/sharedStrings/richData/sheet (:667-1012),
 * `loadSheetData` (:2062-2252), `parseRichText` (teks saja, :2254), `castTo*`/`castToFormula` (diwarisi, :449-505),
 * `REL_TO_MAIN` (:259) dan `boolean` (:2449), serta `listWorksheetInfo` (:329-447, nama sheet pertama). Test
 * `ImporQnaPemindaiTest::testVersiPhpSpreadsheetTerkunci` gagal bila versi vendor berubah: tinjau ulang kelas ini dulu.
 *
 * Kenapa: sejak R1 setiap pagar yang meniru perilaku `load()` tanpa read filter (relasi, nama sheet, rumus shared,
 * kolom, `<col>`) membuka celah baru, karena `load()` membangun SELURUH workbook (semua sheet bernama sama, semua sel,
 * definisi kolom, terjemahan rumus shared per sel). Pembaca ini biayanya linear terhadap byte part sheet dan shared
 * strings yang dialirkan (parser SAX ext-xml di atas aliran `zip://`, potongan 64 KB), dan memorinya dibatasi jumlah
 * sel yang dipakai (≤ 4 × (baris maks + 1) + E1) serta shared string yang dirujuk sel-sel itu.
 *
 * Paritas nilai dengan main (read filter A-D/E1 + `rangeToArray(A1:D(maks+1), null, false, false, false)`):
 *
 * - resolusi berkas memakai `getFromZipArchive` vendor (subkelas), pemindai keamanan `XmlScanner`, dan namespace yang
 *   sama: `_rels/.rels` → relasi `officeDocument` → `xl/_rels/workbook.xml.rels` (worksheet, sharedStrings) → elemen
 *   `sheet` pertama workbook (nama sheet = `listWorksheetInfo()[0]`), lalu sheet pertama yang namanya cocok secara
 *   longgar (`in_array` seperti `setLoadSheetsOnly`) dan punya part;
 * - setiap sel di dalam filter dikonversi dengan potongan `Reader\Xlsx::loadSheetData` yang sama (jenis `s`, `b`,
 *   `str`, `inlineStr`, `e`, angka, tanggal, rumus termasuk anak rumus shared lewat `castToFormula` warisan), lalu
 *   ditulis ke Worksheet PhpSpreadsheet dengan `setValueExplicit`/`setValue`/`setCalculatedValue` yang sama, sehingga
 *   `rangeToArray` dan `getHighestDataRow` identik;
 * - sel di luar filter hanya didaftarkan bila memuat rumus shared (induk dipakai anak di A-D), seperti vendor;
 * - shared string: `<t>` langsung, atau gabungan teks run (`<r>`), tanpa fonetik `<rPh>`, `_xHHHH_` didekode;
 * - pesan jumlah baris: begitu ada sel berisi (punya anak atau teks) di baris > maks+1, pembacaan BERHENTI dan
 *   `total_baris` = baris itu. Untuk berkas berurutan sama dengan `totalRows` `listWorksheetInfo` main; untuk urutan `r`
 *   acak main tidak memberi pesan (baris lebih dibuang diam-diam), di sini pesan baris. Sel berbentuk `<c ...></c>`
 *   (tanpa isi) tidak dihitung, sedangkan `listWorksheetInfo` menghitungnya.
 *
 * Bedanya hanya pada berkas yang tidak sah (lihat README Chatbot).
 *
 * @internal dipakai {@see ImporQna}
 */
final class PembacaQnaXlsx extends Xlsx
{
    private const KOLOM = ['A', 'B', 'C', 'D'];

    /** @var array<int, true> indeks shared string yang dirujuk sel di dalam filter */
    private array $sstPerlu = [];

    /**
     * @return array{total_baris: int, sel: mixed[][], ekstra: mixed, gagal: bool}|null null = bukan xlsx (tanpa
     *                                                                                          workbook, seperti `canRead`)
     *
     * @throws RuntimeException berkas tidak dapat dibaca
     */
    public function bacaQna(string $path, int $maksBaris): ?array
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Arsip xlsx tidak dapat dibuka.');
        }

        $this->elemen       = 0;
        $this->byteBaca     = 0;
        $this->terlaluBesar = false;
        $this->cacheMuat    = [];
        $this->biayaShared  = 0;

        try {
            [$basename, $nsBase] = $this->namaWorkbook($zip);

            if ($basename === '') {
                return null;
            }

            $lokasi = $this->lokasiSheet($zip, $nsBase);

            if ($lokasi === false) {
                // listWorksheetInfo tanpa sheet: main menolak "rusak".
                throw new RuntimeException('Workbook tanpa sheet.');
            }

            if ($lokasi === null) {
                // Ada nama sheet tetapi tidak ada part worksheet yang bisa dibaca (mis. sheet pertama chartsheet, part
                // hilang): main menolak "rusak" (review CR-182 R6 C1).
                throw new RuntimeException('Part sheet pertama tidak ada.');
            }

            [$entriSheet, $entriSst, $mainNs, $tanggal1904] = $lokasi;
            Date::setExcelCalendar($tanggal1904 ? Date::CALENDAR_MAC_1904 : Date::CALENDAR_WINDOWS_1900);

            $hasil = $this->alirkanSheet($path, $entriSheet, $mainNs, $maksBaris);
            $sst   = $entriSst === null || $this->sstPerlu === [] ? [] : $this->alirkanSst($path, $entriSst, $mainNs);

            $buku  = new Spreadsheet();
            $sheet = $buku->getActiveSheet();

            try {
                foreach ($hasil['sel'] as [$r, $jenis, $nilai, $hitung, $numerikAsli, $indeksSst]) {
                    if ($indeksSst !== null) {
                        if (! array_key_exists($indeksSst, $sst)) {
                            throw new RuntimeException('Rujukan shared string tidak ada.');
                        }

                        $nilai = $sst[$indeksSst];
                    }

                    $this->tulisSel($sheet, $r, $jenis, $nilai, $hitung, $numerikAsli);
                }

                $akhir = max(1, min($sheet->getHighestDataRow(), $maksBaris + 1));

                return [
                    'total_baris' => $hasil['total_baris'],
                    'sel'         => $sheet->rangeToArray('A1:D' . $akhir, null, false, false, false),
                    'ekstra'      => $sheet->getCell('E1')->getValue(),
                    'gagal'       => $hasil['gagal'],
                ];
            } finally {
                $buku->disconnectWorksheets();
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Seperti `Reader\Xlsx::getWorkbookBaseName` (privat; dipakai `canRead`).
     *
     * @return array{0: string, 1: string}
     */
    private function namaWorkbook(ZipArchive $zip): array
    {
        $basename = '';
        $nsBase   = '';
        $rels     = $this->muat($zip, self::INITIAL_FILE);

        foreach ($rels->children(Namespaces::RELATIONSHIPS)->Relationship as $rel) {
            $rel  = self::getAttributes($rel);
            $type = (string) $rel['Type'];

            if ($type === Namespaces::OFFICE_DOCUMENT || $type === Namespaces::PURL_OFFICE_DOCUMENT) {
                $nama   = basename((string) $rel['Target']);
                $nsBase = dirname($type);

                if (preg_match('/workbook.*\.xml/', $nama) === 1) {
                    $basename = $nama;
                }
            }
        }

        return [$basename, $nsBase];
    }

    /**
     * Nama sheet pertama seperti `listWorksheetInfo()[0]`, lalu part sheet pertama yang akan dimuat `load()` dengan
     * `setLoadSheetsOnly([nama])`.
     *
     * @return array{0: string, 1: string|null, 2: string, 3: bool}|false|null [entri sheet, entri sharedStrings, ns utama,
     *                                                                        1904]; false = tanpa nama sheet; null = tanpa part
     */
    private function lokasiSheet(ZipArchive $zip, string $nsBase): array|false|null
    {
        $rels = $this->muat($zip, self::INITIAL_FILE, Namespaces::RELATIONSHIPS);
        $nama = null;

        // listWorksheetInfo: setiap relasi selain thumbnail; nama = elemen sheet pertama yang ditemukan.
        self::batasiRelasi($rels);

        foreach ($rels->Relationship as $relx) {
            $rel = self::getAttributes($relx);

            if ((string) $rel['Type'] === Namespaces::THUMBNAIL) {
                continue;
            }

            $workbook = $this->muat($zip, (string) $rel['Target'], self::nsUtama((string) $rel['Type']));

            if ($workbook->sheets) {
                foreach ($workbook->sheets->sheet as $eleSheet) {
                    $nama = (string) self::getAttributes($eleSheet)['name'];

                    break 2;
                }
            }
        }

        if ($nama === null) {
            return false;
        }

        // load(): relasi officeDocument, sheet pertama yang lolos setLoadSheetsOnly dan punya part.
        foreach ($rels->Relationship as $relx) {
            $rel       = self::getAttributes($relx);
            $relTarget = (string) $rel['Target'];
            $relType   = (string) $rel['Type'];

            if ($relTarget !== '' && $relTarget[0] === '/') {
                $relTarget = substr($relTarget, 1);
            }

            if ($relType !== "{$nsBase}/officeDocument") {
                continue;
            }

            $mainNs       = self::nsUtama($relType);
            $dir          = dirname($relTarget);
            $relsWorkbook = $this->muat($zip, "{$dir}/_rels/" . basename($relTarget) . '.rels', Namespaces::RELATIONSHIPS);
            $worksheets   = [];
            $sst          = null;

            foreach ($relsWorkbook->Relationship as $elex) {
                $ele  = self::getAttributes($elex);
                $tipe = (string) $ele['Type'];

                if ($tipe === Namespaces::WORKSHEET || $tipe === Namespaces::PURL_WORKSHEET) {
                    $worksheets[(string) $ele['Id']] = (string) $ele['Target'];
                } elseif ($sst === null && $tipe === "{$nsBase}/sharedStrings") {
                    $target = (string) $ele['Target'];
                    $sst    = str_starts_with($target, '/') ? substr($target, 1) : "{$dir}/{$target}";
                }
            }

            $workbook = $this->muat($zip, $relTarget, $mainNs);
            $tahun    = false;

            // Seperti load(): relasi gambar dalam sel (richData), nama entri dicocokkan persis.
            $this->gambarSel = [];
            $relsRich        = $dir . '/richData/_rels/richValueRel.xml.rels';

            if ($zip->locateName($relsRich) !== false) {
                foreach ($this->muat($zip, $relsRich, Namespaces::RELATIONSHIPS)->Relationship as $elex) {
                    $ele = self::getAttributes($elex);

                    if ((string) $ele['Type'] === Namespaces::IMAGE) {
                        $this->gambarSel[(string) $ele['Id']] = (string) $ele['Target'];
                    }
                }
            }

            if ($workbook->workbookPr) {
                $attr  = self::getAttributes($workbook->workbookPr);
                $tahun = isset($attr['date1904']) && self::logika((string) $attr['date1904']);
            }

            if (! $workbook->sheets) {
                continue;
            }

            foreach ($workbook->sheets->sheet as $eleSheet) {
                $attr = self::getAttributes($eleSheet);

                // phpcs:ignore -- perbandingan longgar disengaja, sama dengan in_array vendor
                if (! in_array((string) $attr['name'], [$nama])) {
                    continue;
                }

                $rid = (string) (self::getAttributes($eleSheet, $nsBase)['id'] ?? '');

                if (! isset($worksheets[$rid])) {
                    continue;
                }

                $file = $worksheets[$rid];

                if ($file !== '' && $file[0] === '/' && $dir !== '.') {
                    $file = substr($file, strlen($dir) + 2);
                }

                $entri = $this->namaEntri($zip, "{$dir}/{$file}");

                if ($entri === null) {
                    return null;
                }

                return [$entri, $sst === null ? null : $this->namaEntri($zip, $sst), $mainNs, $tahun];
            }
        }

        return null;
    }

    /**
     * Potongan `Reader\Xlsx::loadSheetData` untuk satu sel di dalam filter (readDataOnly, tanpa gambar dalam sel).
     *
     * @return array{0: string, 1: string, 2: mixed, 3: mixed, 4: bool, 5: int|null}
     */
    private function nilaiSel(SimpleXMLElement $c, string $r): array
    {
        $cAttr           = self::getAttributes($c);
        $cellDataType    = (string) $cAttr['t'];
        $numerikAsli     = $cellDataType === '';
        $value           = null;
        $calculatedValue = null;
        $indeksSst       = null;
        $useFormula      = isset($c->f)
            && ((string) $c->f !== '' || (isset($c->f->attributes()['t']) && strtolower((string) $c->f->attributes()['t']) === 'shared'));

        if ($useFormula) {
            $this->periksaBiayaShared($c);
        }

        switch ($cellDataType) {
            case DataType::TYPE_STRING:
                if ((string) $c->v != '') {
                    $indeksSst                  = (int) $c->v;
                    $this->sstPerlu[$indeksSst] = true;
                } else {
                    $value = '';
                }

                break;

            case DataType::TYPE_BOOL:
                if (! $useFormula) {
                    if (isset($c->v)) {
                        $value = self::castToBoolean($c);
                    } else {
                        $cellDataType = DataType::TYPE_NULL;
                    }
                } else {
                    $this->castToFormula($c, $r, $cellDataType, $value, $calculatedValue, 'castToBoolean');
                }

                break;

            case DataType::TYPE_STRING2:
                if ($useFormula) {
                    $this->castToFormula($c, $r, $cellDataType, $value, $calculatedValue, 'castToString');
                } else {
                    $value = self::castToString($c);
                }

                break;

            case DataType::TYPE_INLINE:
                if ($useFormula) {
                    $this->castToFormula($c, $r, $cellDataType, $value, $calculatedValue, 'castToError');
                } else {
                    $value = self::teksKaya($c->is);
                }

                break;

            case DataType::TYPE_ERROR:
                if (isset($cAttr->vm) && isset($this->gambarSel['rId' . $cAttr->vm]) && ! $useFormula) {
                    // Gambar dalam sel: vendor mengisi objek Drawing (bukan teks); yang dipakai impor hanya "bukan skalar".
                    $value        = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                    $cellDataType = DataType::TYPE_DRAWING_IN_CELL;

                    break;
                }

                if (! $useFormula) {
                    $value = self::castToError($c);
                } else {
                    $this->castToFormula($c, $r, $cellDataType, $value, $calculatedValue, 'castToError');
                }

                break;

            default:
                if (! $useFormula) {
                    $value = self::castToString($c);

                    if (is_numeric($value)) {
                        $value += 0;
                        $cellDataType = DataType::TYPE_NUMERIC;
                    }
                } else {
                    $this->castToFormula($c, $r, $cellDataType, $value, $calculatedValue, 'castToString');

                    if (is_numeric($calculatedValue)) {
                        $calculatedValue += 0;
                    }
                }

                break;
        }

        return [$r, $cellDataType, $value, $calculatedValue, $numerikAsli, $indeksSst];
    }

    /**
     * Anak rumus shared di A-D diterjemahkan vendor (`ReferenceHelper::updateFormulaReferences`, O(rujukan × panjang)
     * per anak). Penyimpangan paritas yang disetujui reviewer (R6 B2), hanya mengenai berkas yang tidak realistis untuk
     * QnA: induk > {@see self::MAKS_PANJANG_INDUK} karakter, atau Σ(100 + panjang induk) atas anak > 1 MB → "rusak".
     */
    private function periksaBiayaShared(SimpleXMLElement $c): void
    {
        $attr = $c->f->attributes();

        if (! isset($attr['t']) || strtolower((string) $attr['t']) !== 'shared') {
            return;
        }

        $induk = $this->sharedFormulae[(string) $attr['si']] ?? null;

        if ($induk === null) {
            return; // sel ini induknya sendiri: tidak diterjemahkan
        }

        $panjang = mb_strlen($induk->formula());

        if ($panjang > self::MAKS_PANJANG_INDUK) {
            throw new RuntimeException('Induk rumus shared terlalu panjang.');
        }

        $this->biayaShared += 100 + $panjang;

        if ($this->biayaShared > self::MAKS_BIAYA_SHARED) {
            throw new RuntimeException('Biaya rumus shared terlalu besar.');
        }
    }

    /**
     * Penulisan sel seperti `loadSheetData` (readEmptyCells false, readDataOnly).
     */
    private function tulisSel(Worksheet $sheet, string $r, string $cellDataType, mixed $value, mixed $calculatedValue, bool $numerikAsli): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        $cell = $sheet->getCell($r);

        if ($cellDataType !== '') {
            // Vendor juga memeriksa null; di sini nilai null sudah tersaring di atas.
            if ($cellDataType === DataType::TYPE_NUMERIC && $value === '') {
                $cellDataType = DataType::TYPE_NULL;
            }

            if ($cellDataType !== DataType::TYPE_NULL) {
                $cell->setValueExplicit($value, $cellDataType);
            }
        } else {
            $cell->setValue($value);
        }

        if ($calculatedValue !== null) {
            $cell->setCalculatedValue($calculatedValue, $numerikAsli);
        }
    }

    /**
     * Seperti `Reader\Xlsx::parseRichText` untuk teks saja (readDataOnly membuang format; yang dipakai getPlainText).
     */
    private static function teksKaya(?SimpleXMLElement $is): RichText
    {
        $nilai = new RichText();

        if (isset($is->t)) {
            $nilai->createText(StringHelper::controlCharacterOOXML2PHP((string) $is->t));
        } elseif ($is !== null && is_object($is->r)) {
            foreach ($is->r as $run) {
                $nilai->createText(StringHelper::controlCharacterOOXML2PHP((string) $run->t));
            }
        }

        return $nilai;
    }

    // ---- Aliran SAX (ext-xml): jauh lebih murah per elemen daripada loop XMLReader di PHP. ----

    private const POTONG = 65536;

    /**
     * Anggaran elemen XML part sheet yang dialirkan (R6 B4): border A1:Z20000 + 1.000 baris data (±540 ribu elemen, ±2,0 s)
     * lolos seperti main; ±4 µs per `<c/>`, jadi penolakan di batas ±2,3-2,6 s. Angka terukur di README Chatbot.
     */
    public const MAKS_ELEMEN = 700_000;

    /**
     * Anggaran elemen shared strings (terpisah): `<si>` yang tidak dirujuk hanya dilewati (±1,6 µs per elemen). Rich text
     * 20 run × 4.000 string ±320 ribu elemen.
     */
    public const MAKS_ELEMEN_SST = 1_000_000;

    private int $anggaran = self::MAKS_ELEMEN;

    /** Batas panjang teks induk rumus shared yang anaknya diterjemahkan di A-D (karakter, R6 B2). */
    public const MAKS_PANJANG_INDUK = 1024;

    /** Batas Σ(100 + panjang induk) atas anak rumus shared di A-D (R6 B2). */
    public const MAKS_BIAYA_SHARED = 1024 * 1024;

    /** Batas panjang rekaman XML satu sel/`<si>` (sesudah dekode dan escape ulang), R6 B1 pertahanan berlapis. */
    public const MAKS_REKAMAN = 2 * 1024 * 1024;

    /** Batas Relationship `_rels/.rels` yang diproses (R6 B3). Excel/LibreOffice menulis 3-4. */
    public const MAKS_RELASI = 32;

    private int $biayaShared = 0;

    /** Nama SAX `uri\x1Fc` untuk jalur cepat sel. */
    private string $namaC = '';

    /** @var array<string, SimpleXMLElement> hasil muat() per nama entri + namespace (R6 B3) */
    private array $cacheMuat = [];

    /** Batas byte terdekompresi yang benar-benar dibaca per berkas (ukuran di header ZIP bisa palsu). */
    public const MAKS_BYTE = 32 * 1024 * 1024;

    private int $elemen        = 0;
    private int $byteBaca      = 0;
    private bool $terlaluBesar = false;

    private string $mainNs = '';
    private int $maksBaris = 0;
    private int $dalam     = 0;

    /** null = belum ketemu, true = di dalam sheetData pertama, false = sudah lewat */
    private ?bool $sdPertama = null;
    private bool $diRow      = false;
    private int $cIndex      = 0;
    private int $rowIndex    = 1;
    private bool $henti      = false;
    private bool $gagal      = false;
    private int $barisLebih  = 0;

    // Sel yang sedang dibaca.
    private int $dalamSel     = 0;
    private bool $isiSel      = false;
    private bool $selDiFilter = false;
    private string $rSel      = '';
    private int $barisSel     = 0;
    private string $bukaSel   = '';

    /** @var array<string, string> */
    private array $atributSel = [];
    private ?string $rekam    = null;
    private ?string $rekamF   = null;
    private int $dalamF       = 0;

    /** @var list<string> nama tag yang dibuka di rekaman, untuk penutupnya */
    private array $tumpuk = [];

    /** @var list<array{0: string, 1: string, 2: mixed, 3: mixed, 4: bool, 5: int|null}> */
    private array $selBaca = [];

    // Shared strings.
    private int $indeksSi = -1;
    private bool $modeSst = false;

    /** @var array<int, string> */
    private array $sstHasil = [];

    /** @var array<string, string> rId gambar dalam sel (richData) */
    private array $gambarSel = [];

    /**
     * @return array{total_baris: int, gagal: bool, sel: list<array{0: string, 1: string, 2: mixed, 3: mixed, 4: bool, 5: int|null}>}
     */
    private function alirkanSheet(string $path, string $entri, string $mainNs, int $maksBaris): array
    {
        $this->sharedFormulae = [];
        $this->sstPerlu       = [];
        $this->selBaca        = [];
        $this->elemen         = 0;
        $this->anggaran       = self::MAKS_ELEMEN;
        $this->mainNs         = $mainNs;
        $this->namaC          = "{$mainNs}\x1Fc";
        $this->maksBaris      = $maksBaris;
        $this->modeSst        = false;
        $this->dalam          = 0;
        $this->sdPertama      = null;
        $this->diRow          = false;
        $this->cIndex         = 0;
        $this->henti          = false;
        $this->gagal          = false;
        $this->barisLebih     = 0;
        $this->dalamSel       = 0;

        $this->alirkan($path, $entri);

        return ['total_baris' => $this->barisLebih, 'gagal' => $this->gagal, 'sel' => $this->selBaca];
    }

    /**
     * Shared strings yang dirujuk saja; aturan sama dengan `load()`: `<t>` langsung → teks, `<r>` → gabungan teks run
     * (getPlainText), selain itu ''.
     *
     * @return array<int, string>
     */
    private function alirkanSst(string $path, string $entri, string $mainNs): array
    {
        $this->mainNs   = $mainNs;
        $this->modeSst  = true;
        $this->elemen   = 0;
        $this->anggaran = self::MAKS_ELEMEN_SST;
        $this->dalam    = 0;
        $this->indeksSi = -1;
        $this->sstHasil = [];
        $this->rekam    = null;
        $this->henti    = false;

        $this->alirkan($path, $entri);

        return $this->sstHasil;
    }

    private function alirkan(string $path, string $entri): void
    {
        $aliran = @fopen('zip://' . $path . '#' . $entri, 'rb');

        if ($aliran === false) {
            throw new RuntimeException('Part xlsx tidak dapat dibuka.');
        }

        $awalPart = true;
        $parser   = xml_parser_create_ns(null, "\x1F");
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
        xml_set_element_handler($parser, $this->buka(...), $this->tutup(...));
        xml_set_character_data_handler($parser, $this->teks(...));
        $ekor = '';

        try {
            while (! $this->henti && ! feof($aliran)) {
                $potong = fread($aliran, self::POTONG);

                if ($potong === false || $potong === '') {
                    break;
                }

                if ($awalPart) {
                    self::periksaEncoding($potong);
                    $awalPart = false;
                }

                // Seperti XmlScanner vendor: DOCTYPE (byte nol diabaikan) ditolak.
                $periksa = $ekor . (str_contains($potong, "\0") ? str_replace("\0", '', $potong) : $potong);

                if (str_contains($periksa, '<!DOCTYPE')) {
                    throw new RuntimeException('DOCTYPE tidak diizinkan.');
                }

                $ekor = substr($periksa, -16);
                $this->byteBaca += strlen($potong);

                if ($this->byteBaca > self::MAKS_BYTE) {
                    throw new RuntimeException('Isi xlsx terlalu besar.');
                }

                if (xml_parse($parser, $potong, false) !== 1) {
                    throw new RuntimeException('XML part xlsx tidak sah.');
                }
            }

            if (! $this->henti && xml_parse($parser, '', true) !== 1) {
                throw new RuntimeException('XML part xlsx tidak sah.');
            }
        } finally {
            fclose($aliran);
        }

        if ($this->terlaluBesar) {
            throw new RuntimeException('Elemen xlsx terlalu banyak.');
        }
    }

    /**
     * @param array<string, string> $atribut
     */
    private function buka(\XMLParser $parser, string $nama, array $atribut): void
    {
        if ($this->henti) {
            return;
        }

        $this->dalam++;

        if (++$this->elemen > $this->anggaran) {
            $this->terlaluBesar = true;
            $this->henti        = true;

            return;
        }

        if ($nama === $this->namaC && $this->dalam === 4 && $this->diRow && $this->sdPertama === true
            && $this->rekam === null && $this->dalamSel === 0) {
            $this->mulaiSel($atribut);

            return;
        }

        [$uri, $lokal] = self::pecah($nama);

        if ($this->rekam !== null) {
            $this->rekam .= $this->tag($nama, $atribut);
            $this->isiSel = true;

            return;
        }

        if ($this->rekamF !== null) {
            $this->rekamF .= $this->tag($nama, $atribut);

            return;
        }

        if ($this->modeSst) {
            if ($this->dalam === 2 && $uri === $this->mainNs && $lokal === 'si') {
                $this->indeksSi++;

                if (isset($this->sstPerlu[$this->indeksSi])) {
                    $this->tumpuk   = [];
                    $this->dalamSel = $this->dalam;
                    $this->rekam    = '<si xmlns="' . self::esc($this->mainNs) . '">';
                }
            }

            return;
        }

        if ($this->dalamSel !== 0) {
            // Anak sel di luar filter: hanya `<f>` yang direkam (induk rumus shared).
            $this->isiSel = true;

            if ($this->dalam === $this->dalamSel + 1 && $uri === $this->mainNs && $lokal === 'f') {
                $this->tumpuk = [];
                $this->dalamF = $this->dalam;
                $this->rekamF = $this->tag($nama, $atribut);
            }

            return;
        }

        $utama = $uri === $this->mainNs;

        if ($this->dalam === 2) {
            if ($this->sdPertama === null && $utama && $lokal === 'sheetData') {
                $this->sdPertama = true;
            }
        } elseif ($this->dalam === 3 && $this->sdPertama === true) {
            $this->diRow = $utama && $lokal === 'row';

            if ($this->diRow) {
                $this->cIndex++;
                $this->rowIndex = 1;
            }
        } elseif ($this->dalam === 4 && $this->diRow && $this->sdPertama === true && $utama && $lokal === 'c') {
            $this->mulaiSel($atribut);
        }
    }

    /**
     * @param array<string, string> $atribut
     */
    private function mulaiSel(array $atribut): void
    {
        $this->dalamSel   = $this->dalam;
        $this->isiSel     = false;
        $this->rekamF     = null;
        $this->atributSel = $atribut;
        $this->bukaSel    = '';

        try {
            $r = (string) ($atribut['r'] ?? '');

            if ($r === '') {
                $r = Coordinate::stringFromColumnIndex($this->rowIndex) . $this->cIndex;
            }

            $this->rSel = $r;
            $this->rowIndex++;
            // Jalur cepat untuk bentuk biasa ("A12"); selain itu aturan vendor apa adanya.
            [$kol, $baris]     = preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})$/', $r, $m) === 1 ? [$m[1], $m[2]] : Coordinate::coordinateFromString($r);
            $this->barisSel    = (int) $baris;
            $this->selDiFilter = ($this->barisSel <= $this->maksBaris + 1 && in_array($kol, self::KOLOM, true))
                || ($this->barisSel === 1 && $kol === 'E');
        } catch (\Throwable) {
            $this->gagal       = true;
            $this->barisSel    = 0;
            $this->selDiFilter = false;
        }

        if ($this->selDiFilter) {
            $this->tumpuk = [];
            $this->rekam  = $this->bukaSel();
        }
    }

    private function tutup(\XMLParser $parser, string $nama): void
    {
        if ($this->henti) {
            return;
        }

        if ($this->dalamSel !== 0 && $this->dalam === $this->dalamSel) {
            $this->selesaiSel();
        } elseif ($this->rekam !== null) {
            $this->rekam .= '</' . array_pop($this->tumpuk) . '>';
        } elseif ($this->rekamF !== null && $this->dalam >= $this->dalamF) {
            $this->rekamF .= '</' . array_pop($this->tumpuk) . '>';
        } elseif (! $this->modeSst && $this->dalam === 2 && $this->sdPertama === true) {
            $this->sdPertama = false;
        }

        $this->dalam--;
    }

    private function selesaiSel(): void
    {
        $this->dalamSel = 0;

        if ($this->modeSst) {
            $si                              = $this->simpul((string) $this->rekam . '</si>');
            $this->rekam                     = null;
            $this->sstHasil[$this->indeksSi] = isset($si->t)
                ? StringHelper::controlCharacterOOXML2PHP((string) $si->t)
                : (isset($si->r) ? self::teksKaya($si)->getPlainText() : '');

            return;
        }

        try {
            if ($this->rekam !== null) {
                $c           = $this->simpul($this->rekam . '</c>');
                $this->rekam = null;

                if (! $this->gagal) {
                    $this->selBaca[] = $this->nilaiSel($c, $this->rSel);
                }
            } elseif ($this->rekamF !== null) {
                $c            = $this->simpul($this->bukaSel() . $this->rekamF . '</c>');
                $this->rekamF = null;

                if (isset($c->f, $c->f->attributes()['t']) && strtolower((string) $c->f->attributes()['t']) === 'shared') {
                    $jenis = (string) self::getAttributes($c)['t'];
                    $v     = $h = null;
                    $this->castToFormula($c, $this->rSel, $jenis, $v, $h, 'castToError', false);
                }
            }
        } catch (\Throwable) {
            $this->gagal = true;
        }

        // Henti dini (review CR-182 R5): sel berisi di baris > maks+1 → pesan jumlah baris; sisa sheet tidak dibaca.
        if ($this->isiSel && $this->barisSel > $this->maksBaris + 1) {
            $this->barisLebih = $this->barisSel;
            $this->henti      = true;
        }
    }

    private function teks(\XMLParser $parser, string $data): void
    {
        if ($this->henti) {
            return;
        }

        if ($this->dalamSel !== 0) {
            $this->isiSel = true;
        }

        if ($this->rekam !== null) {
            $this->rekam .= self::esc($data);

            if (strlen($this->rekam) > self::MAKS_REKAMAN) {
                throw new RuntimeException('Isi satu sel xlsx terlalu besar.');
            }
        } elseif ($this->rekamF !== null) {
            $this->rekamF .= self::esc($data);

            if (strlen($this->rekamF) > self::MAKS_REKAMAN) {
                throw new RuntimeException('Isi satu sel xlsx terlalu besar.');
            }
        }
    }

    /**
     * Tag pembuka hasil rekonstruksi dari nama SAX (`uri\x1Flokal`), dengan deklarasi namespace yang diperlukan.
     *
     * @param array<string, string> $atribut
     */
    private function tag(string $nama, array $atribut, bool $tanpaTumpuk = false): string
    {
        [$uri, $lokal] = self::pecah($nama);
        $dekl          = '';

        if ($uri === $this->mainNs) {
            $qn   = $lokal;
            $dekl = ' xmlns="' . self::esc($this->mainNs) . '"';
        } elseif ($uri === '') {
            $qn   = $lokal;
            $dekl = ' xmlns=""';
        } else {
            $qn   = 'n0:' . $lokal;
            $dekl = ' xmlns:n0="' . self::esc($uri) . '"';
        }

        $s = '<' . $qn . $dekl;
        $k = 0;

        foreach ($atribut as $an => $av) {
            [$au, $al] = self::pecah($an);

            if ($au === '') {
                $s .= ' ' . $al . '="' . self::esc($av) . '"';
            } elseif ($au === 'http://www.w3.org/XML/1998/namespace') {
                $s .= ' xml:' . $al . '="' . self::esc($av) . '"';
            } else {
                $k++;
                $s .= " xmlns:a{$k}=\"" . self::esc($au) . "\" a{$k}:{$al}=\"" . self::esc($av) . '"';
            }
        }

        if (! $tanpaTumpuk) {
            $this->tumpuk[] = $qn;
        }

        return $s . '>';
    }

    private function bukaSel(): string
    {
        if ($this->bukaSel === '') {
            $this->bukaSel = $this->tag("{$this->mainNs}\x1Fc", $this->atributSel, true);
        }

        return $this->bukaSel;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function pecah(string $nama): array
    {
        $p = strrpos($nama, "\x1F");

        return $p === false ? ['', $nama] : [substr($nama, 0, $p), substr($nama, $p + 1)];
    }

    /**
     * Escape ulang teks/atribut hasil SAX. CR, LF, dan TAB ditulis sebagai rujukan karakter supaya tidak dinormalkan saat
     * diurai ulang (CR dalam teks, dan ketiganya dalam nilai atribut), sehingga nilainya sama dengan yang diurai vendor
     * dari XML asli (R6 C2).
     */
    private static function esc(string $s): string
    {
        return strtr(htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ["\r" => '&#13;', "\n" => '&#10;', "\t" => '&#9;']);
    }

    /**
     * Encoding part yang dialirkan (R6 B1): hanya UTF-8/UTF-16/UTF-32 dan keluarga yang kompatibel ASCII (US-ASCII,
     * ISO-8859-*, windows-125x). Encoding lain (UTF-7, EBCDIC, …) membuat DOCTYPE bisa disamarkan dari pemeriksaan byte,
     * lalu entitas internal diekspansi parser → "rusak". Deklarasi dibaca dari potongan pertama sesudah BOM, dengan byte nol
     * dibuang (UTF-16/32 tanpa BOM tetap terbaca); awal dokumen selain `<` juga ditolak.
     */
    private static function periksaEncoding(string $potong): void
    {
        $awal = substr($potong, 0, 1024);

        foreach (["\xEF\xBB\xBF", "\x00\x00\xFE\xFF", "\xFF\xFE\x00\x00", "\xFE\xFF", "\xFF\xFE"] as $bom) {
            if (str_starts_with($awal, $bom)) {
                $awal = substr($awal, strlen($bom));

                break;
            }
        }

        $awal = ltrim(str_replace("\0", '', $awal), " \t\r\n");

        if ($awal === '' || $awal[0] !== '<') {
            throw new RuntimeException('Awal part xlsx tidak dikenal.');
        }

        if (! str_starts_with($awal, '<?xml')) {
            return;
        }

        $akhir = strpos($awal, '?>');
        $dekl  = $akhir === false ? $awal : substr($awal, 0, $akhir);

        if (preg_match('/\sencoding\s*=\s*["\']([^"\']*)["\']/i', $dekl, $m) !== 1) {
            return;
        }

        if (preg_match('/^(utf-?8|utf-?16(le|be)?|utf-?32(le|be)?|us-ascii|ascii|iso-8859-[0-9]{1,2}|iso8859-[0-9]{1,2}|latin-?1|windows-125[0-8]|cp125[0-8])$/i', trim($m[1])) !== 1) {
            throw new RuntimeException('Encoding part xlsx tidak diizinkan.');
        }
    }

    private function simpul(string $xml): SimpleXMLElement
    {
        $simpul = @simplexml_load_string($xml, SimpleXMLElement::class, 0, $this->mainNs);

        if ($simpul === false) {
            throw new RuntimeException('Sel xlsx tidak sah.');
        }

        return $simpul;
    }

    /**
     * Nama entri sebenarnya untuk path yang akan dibaca `getFromZipArchive` vendor (aturan dan urutan sama).
     */
    private function namaEntri(ZipArchive $zip, string $fileName): ?string
    {
        if (str_contains($fileName, '//')) {
            $fileName = substr($fileName, (int) strpos($fileName, '//') + 1);
        }

        $fileName = (string) preg_replace('/^\.\//', '', $fileName);
        $fileName = File::realpath($fileName);

        foreach ([$fileName, substr($fileName, 1), str_replace('/', '\\', $fileName)] as $calon) {
            $i = $zip->locateName($calon, ZipArchive::FL_NOCASE);

            if ($i !== false) {
                $nama = $zip->getNameIndex($i);

                return $nama === false ? null : $nama;
            }
        }

        return null;
    }

    /**
     * Seperti `Reader\Xlsx::loadZip` (privat): isi lewat `getFromZipArchive`, dipindai `XmlScanner`, lalu SimpleXML.
     */
    private function muat(ZipArchive $zip, string $nama, string $ns = ''): SimpleXMLElement
    {
        // Cache per entri sebenarnya (R6 B3): relasi berulang ke part besar tidak dibaca berulang kali; byte setiap bacaan
        // masuk anggaran yang sama dengan aliran.
        $kunci = ($this->namaEntri($zip, $nama) ?? "?{$nama}") . "\x1F{$ns}";

        if (isset($this->cacheMuat[$kunci])) {
            return $this->cacheMuat[$kunci];
        }

        $isi = $this->getFromZipArchive($zip, $nama);
        $this->byteBaca += strlen($isi);

        if ($this->byteBaca > self::MAKS_BYTE) {
            throw new RuntimeException('Isi xlsx terlalu besar.');
        }

        $xml = @simplexml_load_string($this->getSecurityScannerOrThrow()->scan($isi), SimpleXMLElement::class, 0, $ns);

        return $this->cacheMuat[$kunci] = self::testSimpleXml($xml);
    }

    private static function batasiRelasi(SimpleXMLElement $rels): void
    {
        if (count($rels->Relationship) > self::MAKS_RELASI) {
            throw new RuntimeException('Relasi xlsx terlalu banyak.');
        }
    }

    /** Sama dengan `Reader\Xlsx::boolean` (privat). */
    private static function logika(string $value): bool
    {
        if (is_numeric($value)) {
            return (bool) $value;
        }

        return $value === 'true' || $value === 'TRUE';
    }

    /** Sama dengan `Reader\Xlsx::REL_TO_MAIN` (privat). */
    private static function nsUtama(string $relType): string
    {
        return $relType === Namespaces::PURL_OFFICE_DOCUMENT ? Namespaces::PURL_MAIN : Namespaces::MAIN;
    }
}
