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
 * strings yang dialirkan (parser SAX ext-xml TANPA mode namespace di atas aliran `zip://`, potongan 64 KB, prefiks diresolusikan manual), dan memorinya dibatasi jumlah
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
        $this->entitasAsing = false;

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

    private const NS_XML = 'http://www.w3.org/XML/1998/namespace';

    /** Batas panjang nilai satu deklarasi `xmlns`/`xmlns:*` (R7 K2). URI OOXML terpanjang < 100 byte. */
    public const MAKS_URI_NS = 256;

    /** Batas jumlah prefiks namespace dalam satu lingkup (R7 K2). Tulisan Excel ±10. */
    public const MAKS_PREFIKS = 64;

    /** @var array<string, string> peta prefiks → URI lingkup saat ini ('' = default) */
    private array $ns = [];

    /** @var list<array<string, string>> */
    private array $nsTumpuk = [];

    /** @var array<string, string> lingkup namespace saat `<c>` dibuka */
    private array $nsSel = [];

    private string $namaSel = 'c';

    private bool $entitasAsing = false;

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
        $this->mainNs    = $mainNs;
        $this->modeSst   = true;
        $this->sdPertama = null;
        $this->diRow     = false;
        $this->dalamSel  = 0;
        $this->elemen    = 0;
        $this->anggaran  = self::MAKS_ELEMEN_SST;
        $this->dalam     = 0;
        $this->indeksSi  = -1;
        $this->sstHasil  = [];
        $this->rekam     = null;
        $this->henti     = false;

        $this->alirkan($path, $entri);

        return $this->sstHasil;
    }

    private function alirkan(string $path, string $entri): void
    {
        $aliran = @fopen('zip://' . $path . '#' . $entri, 'rb');

        if ($aliran === false) {
            throw new RuntimeException('Part xlsx tidak dapat dibuka.');
        }

        // Parser TANPA mode namespace (R7 K2): dengan xml_parser_create_ns, URI namespace panjang ikut disalin ke setiap
        // nama elemen/atribut sehingga biaya per elemen membengkak. Prefiks diresolusikan manual dengan tumpukan lingkup.
        $awalPart       = true;
        $this->ns       = ['xml' => self::NS_XML];
        $this->nsTumpuk = [];
        $parser         = xml_parser_create('UTF-8');
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
        xml_set_element_handler($parser, $this->buka(...), $this->tutup(...));
        xml_set_character_data_handler($parser, $this->teks(...));
        // Pertahanan berlapis (R7 K1): rujukan entitas selain bawaan tidak diekspansi tetapi sampai ke handler bawaan.
        xml_set_default_handler($parser, $this->bawaan(...));
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

                if ($this->entitasAsing) {
                    throw new RuntimeException('Rujukan entitas tidak diizinkan.');
                }
            }

            if (! $this->henti && xml_parse($parser, '', true) !== 1) {
                throw new RuntimeException('XML part xlsx tidak sah.');
            }
        } finally {
            fclose($aliran);
        }

        if ($this->entitasAsing) {
            throw new RuntimeException('Rujukan entitas tidak diizinkan.');
        }

        if ($this->terlaluBesar) {
            throw new RuntimeException('Elemen xlsx terlalu banyak.');
        }
    }

    private function bawaan(\XMLParser $parser, string $data): void
    {
        if ($data !== '' && $data[0] === '&' && preg_match('/^&(#[0-9]+|#x[0-9A-Fa-f]+|amp|lt|gt|quot|apos);$/', $data) !== 1) {
            $this->entitasAsing = true;
            $this->henti        = true;
        }
    }

    /**
     * Lingkup namespace elemen ini: deklarasi `xmlns`/`xmlns:*` diterapkan pada salinan peta induk (R7 K2: nilai > 256
     * byte atau > 64 prefiks dalam lingkup → "rusak").
     *
     * @param array<string, string> $atribut
     */
    private function masukLingkup(array $atribut): void
    {
        $this->nsTumpuk[] = $this->ns;

        if ($atribut === []) {
            return;
        }

        foreach ($atribut as $an => $av) {
            if ($an === 'xmlns') {
                $prefiks = '';
            } elseif (str_starts_with($an, 'xmlns:')) {
                $prefiks = substr($an, 6);
            } else {
                continue;
            }

            if (strlen($av) > self::MAKS_URI_NS) {
                throw new RuntimeException('URI namespace terlalu panjang.');
            }

            $this->ns[$prefiks] = $av;

            if (count($this->ns) > self::MAKS_PREFIKS) {
                throw new RuntimeException('Prefiks namespace terlalu banyak.');
            }
        }
    }

    /**
     * @return array{0: string|null, 1: string} [URI namespace elemen (null = prefiks tidak terikat), nama lokal]
     */
    private function resolusi(string $nama): array
    {
        $p = strpos($nama, ':');

        if ($p === false) {
            return [$this->ns[''] ?? '', $nama];
        }

        return [$this->ns[substr($nama, 0, $p)] ?? null, substr($nama, $p + 1)];
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
        $this->masukLingkup($atribut);

        if (++$this->elemen > $this->anggaran) {
            $this->terlaluBesar = true;
            $this->henti        = true;

            return;
        }

        if ($this->rekam !== null) {
            $this->rekam .= $this->tag($nama, $atribut);
            $this->isiSel = true;
            $this->periksaRekaman($this->rekam);

            return;
        }

        if ($this->rekamF !== null) {
            $this->rekamF .= $this->tag($nama, $atribut);
            $this->periksaRekaman($this->rekamF);

            return;
        }

        [$uri, $lokal] = $this->resolusi($nama);
        $utama         = $uri === $this->mainNs;

        if ($this->modeSst) {
            if ($this->dalam === 2 && $utama && $lokal === 'si') {
                $this->indeksSi++;

                if (isset($this->sstPerlu[$this->indeksSi])) {
                    $this->tumpuk   = [];
                    $this->dalamSel = $this->dalam;
                    $this->rekam    = $this->tagAkar($nama, $atribut);
                    $this->tumpuk[] = $nama;
                    $this->periksaRekaman($this->rekam);
                }
            }

            return;
        }

        if ($this->dalamSel !== 0) {
            // Anak sel di luar filter: hanya `<f>` yang direkam (induk rumus shared).
            $this->isiSel = true;

            if ($this->dalam === $this->dalamSel + 1 && $utama && $lokal === 'f') {
                $this->tumpuk = [];
                $this->dalamF = $this->dalam;
                $this->rekamF = $this->tag($nama, $atribut);
                $this->periksaRekaman($this->rekamF);
            }

            return;
        }

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
            $this->mulaiSel($nama, $atribut);
        }
    }

    /**
     * @param array<string, string> $atribut
     */
    private function mulaiSel(string $nama, array $atribut): void
    {
        $this->dalamSel   = $this->dalam;
        $this->isiSel     = false;
        $this->rekamF     = null;
        $this->namaSel    = $nama;
        $this->atributSel = $atribut;
        $this->nsSel      = $this->ns;
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
            $this->periksaRekaman($this->rekam);
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

        $this->ns = array_pop($this->nsTumpuk) ?? ['xml' => self::NS_XML];
        $this->dalam--;
    }

    private function selesaiSel(): void
    {
        $this->dalamSel = 0;

        if ($this->modeSst) {
            $si                              = $this->simpul((string) $this->rekam . '</' . array_pop($this->tumpuk) . '>');
            $this->rekam                     = null;
            $this->sstHasil[$this->indeksSi] = isset($si->t)
                ? StringHelper::controlCharacterOOXML2PHP((string) $si->t)
                : (isset($si->r) ? self::teksKaya($si)->getPlainText() : '');

            return;
        }

        try {
            if ($this->rekam !== null) {
                $c           = $this->simpul($this->rekam . '</' . $this->namaSel . '>');
                $this->rekam = null;

                if (! $this->gagal) {
                    $this->selBaca[] = $this->nilaiSel($c, $this->rSel);
                }
            } elseif ($this->rekamF !== null) {
                $c            = $this->simpul($this->bukaSel() . $this->rekamF . '</' . $this->namaSel . '>');
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
            $this->periksaRekaman($this->rekam);
        } elseif ($this->rekamF !== null) {
            $this->rekamF .= self::esc($data);
            $this->periksaRekaman($this->rekamF);
        }
    }

    private function periksaRekaman(string $rekaman): void
    {
        if (strlen($rekaman) > self::MAKS_REKAMAN) {
            throw new RuntimeException('Isi satu sel xlsx terlalu besar.');
        }
    }

    /**
     * Tag pembuka elemen di dalam rekaman: nama dan atribut mentah (termasuk deklarasi namespace elemen itu sendiri),
     * nilai di-escape ulang. Prefiks dari leluhur sudah dideklarasikan sekali di akar rekaman ({@see tagAkar}).
     *
     * @param array<string, string> $atribut
     */
    private function tag(string $nama, array $atribut): string
    {
        $s = '<' . $nama;

        foreach ($atribut as $an => $av) {
            $s .= ' ' . $an . '="' . self::esc($av) . '"';
        }

        $this->tumpuk[] = $nama;

        return $s . '>';
    }

    /**
     * Tag pembuka akar rekaman (`<c>` atau `<si>`): seluruh namespace dalam lingkup dideklarasikan SEKALI di sini,
     * lalu atribut mentah selain deklarasi namespace.
     *
     * @param array<string, string> $atribut
     * @param array<string, string>|null $ns
     */
    private function tagAkar(string $nama, array $atribut, ?array $ns = null): string
    {
        $s = '<' . $nama;

        foreach ($ns ?? $this->ns as $prefiks => $uri) {
            if ($prefiks === 'xml') {
                continue;
            }

            $s .= ($prefiks === '' ? ' xmlns="' : " xmlns:{$prefiks}=\"") . self::esc($uri) . '"';
        }

        foreach ($atribut as $an => $av) {
            if ($an === 'xmlns' || str_starts_with($an, 'xmlns:')) {
                continue;
            }

            $s .= ' ' . $an . '="' . self::esc($av) . '"';
        }

        return $s . '>';
    }

    private function bukaSel(): string
    {
        if ($this->bukaSel === '') {
            $this->bukaSel = $this->tagAkar($this->namaSel, $this->atributSel, $this->nsSel);
        }

        return $this->bukaSel;
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

        // Deklarasi wajar < 100 byte; bila penutupnya tidak ada di jendela 1 KB, parser tetap membaca seluruh deklarasi dan
        // bisa beralih encoding sesudah jendela ini (R7 K1) → "rusak".
        if ($akhir === false) {
            throw new RuntimeException('Deklarasi XML part xlsx tidak wajar.');
        }

        $dekl = substr($awal, 0, $akhir);

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
