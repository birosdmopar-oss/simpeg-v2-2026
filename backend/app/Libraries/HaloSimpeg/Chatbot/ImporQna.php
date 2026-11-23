<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg\Chatbot;

use App\Exceptions\ValidationException;
use App\Libraries\Html\HtmlSanitizer;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use App\Libraries\Presensi\Slip\PemindaiXlsx;
use App\Models\AuditLogModel;
use App\Models\HaloSimpeg\Chatbot\QnaKategoriModel;
use App\Models\HaloSimpeg\Chatbot\QnaModel;
use App\Models\HaloSimpeg\Chatbot\QnaTagModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Chatbot;
use Config\Presensi;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;
use ZipArchive;

/**
 * Impor QnA dari Excel template (PRD Fase 8 F8-37, AC-E10.15; `dev:libraries/hr/L_chatbot.php:405-468`).
 *
 * - Hanya `.xlsx` (diperiksa PhpSpreadsheet, bukan MIME browser), maks 2 MB dan 1.000 baris data; judul kolom harus sama
 *   dengan template (4 kolom, urutan sama) → selain itu 422 `errors.berkas` tanpa memproses baris. Struktur XML dipindai
 *   dulu dengan `Presensi\Slip\PemindaiXlsx` (ketergantungan lintas modul, CR-182) sebelum PhpSpreadsheet memuat berkas.
 * - Satu transaksi semua-atau-tidak: ada galat baris → tidak ada yang tersimpan, laporan per baris (nomor baris Excel).
 * - Baris tanpa pertanyaan atau jawaban dilewati dan dilaporkan; baris tanpa kategori → galat; kategori/tag dicocokkan menurut
 *   judul (trim, perbandingan kolom `_ci`) di antara status 1/2, termasuk yang baru dibuat baris sebelumnya; cocok dengan
 *   status 2 → galat; belum ada → dibuat status 1 (kategori `tampil_bubble = 0`). Tag satu baris dipecah koma, di-trim, yang
 *   kosong dibuang, lalu dideduplikasi. QnA impor `is_fallback_option = 0`, status 1; jawaban disanitasi (BP8-14).
 */
final class ImporQna
{
    public const JUDUL_KOLOM = ['Pertanyaan', 'Jawaban', 'Kategori', 'Tag (Pisahkan dengan koma)'];

    public const CONTOH = ['Bagaimana cara mengajukan cuti?', '<p>Ajukan lewat menu Layanan lalu pilih Cuti.</p>', 'Cuti', 'cuti, layanan'];

    /** Kolom yang dipakai: A-D semua baris + E1 (review CR V-2). */
    private const KOLOM_DIBACA = 5;

    /**
     * Pengali ruang sel untuk pemindaian struktur: sel kosong bergaya, kolom di luar A-E, dan sheet lain tetap ada di XML.
     * Karena muat tanpa read filter, SEMUA sel berisi sheet 1 dibangun, jadi nilai ini ditetapkan dari kasus terburuk yang
     * lolos (review CR-182 R1-5/R2-4). Diukur 23-11 di mesin pengembang (Windows, PHP 8.4.27 NTS, satu proses CLI,
     * listWorksheetInfo + load + rangeToArray; beberapa kali, rentang = variasi antarjalan; angka bisa lebih lambat saat
     * proses lain berjalan):
     *
     * | sheet 1 (semua lolos `totalRows` ≤ 1.001)                    | muat        | puncak PHP | puncak RSS |
     * |--------------------------------------------------------------|-------------|------------|------------|
     * | ±50 ribu sel teks inline ±620 byte (isi ±32 MB) ← terburuk    | 2,8-3,3 s   | 76 MB      | 259 MB     |
     * | ±50 ribu sel rumus panjang (isi ±32 MB)                      | 1,9-2,6 s   | 70-74 MB   | 266 MB     |
     * | 1.000 baris × 50 kolom teks pendek = 50.050 sel              | 1,3-1,8 s   | 41 MB      | 164 MB     |
     * | 50.050 sel bergaya kosong                                    | ±1,1 s      | 8 MB       | 103 MB     |
     * | (nilai 40) 200.200 sel teks pendek                           | ±8 s        | 136 MB     | 599 MB     |
     *
     * Pembanding main (read filter A-D/E1) untuk teks panjang: ±1,3 s / 40 MB (verifikator review).
     *
     * 10 → bawaan 50.050 sel, bobot 400.400. Trade-off: sel dihitung di SEMUA sheet, jadi border/format sampai lebih dari
     * ±12.500 baris, sheet referensi besar, atau rich text ber-run banyak ditolak "rusak" walau di bawah 2 MB; berkas yang
     * baris datanya > batas tetap mendapat pesan jumlah baris (R1-4).
     */
    private const RUANG_SEL = 10;

    /**
     * Batas langkah perulangan `<col min max>` (dijumlah di SEMUA sheet). Tanpa read filter biayanya linear (tidak dikali
     * jumlah baris), jadi batas ini hanya menjaga perulangan itu sendiri. Excel menulis sisa lembar sebagai `max="16384"`
     * (1 langkah); LibreOffice ≤ 7.3 sampai `max="1025"` (±1.021 langkah per sheet) → ±19 sheet bergaya LO masih lolos.
     */
    private const MAKS_ITERASI_KOLOM = 20_000;

    /**
     * Batas JUMLAH isi semua entri ZIP (sesudah dekompresi) agar berkas padat masih dibuka listWorksheetInfo untuk pesan
     * baris (R2-1). Jumlah, bukan entri terbesar: part sheet bisa di path mana pun dan listWorksheetInfo membaca semua
     * sheet. Trade-off: entri sah lain yang besar (sharedStrings, gambar) ikut dihitung, jadi berkas padat seperti itu
     * mendapat "rusak", bukan pesan baris; penerimaan berkas yang tidak padat tidak terpengaruh. Terukur: berkas sah
     * 13.000 baris → isi 3,8 MB (pesan baris); 55.000 baris → 16,2 MB ("rusak"); 4 sheet × 7,8 MB `<row/>` (31 MB) →
     * listWorksheetInfo 6,3 s, jadi pada batas 8 MB ±1,6 s. Berkas sah sampai sekitar 25-37 ribu baris masih mendapat
     * pesan baris, bergantung aplikasi penulis (isi per baris terukur: Excel 337 byte, PhpSpreadsheet 289, lainnya 229).
     * Juga dibatasi jumlah `<sheet>` × entri terbesar ≤ 8 MB (rujukan sheet berulang, R3-1).
     */
    private const MAKS_BYTE_ISI_PADAT = 8 * 1024 * 1024;

    /** Batas jumlah elemen `<sheet>` di semua entri (R3-1). Template = 1; berkas sah dengan sheet petunjuk beberapa. */
    private const MAKS_SHEET = 50;

    /** Batas isi terdekompresi dan byte teks yang disalin pemuat; berkas unggahan sendiri maks 2 MB. */
    private const MAKS_BYTE_ISI = 32 * 1024 * 1024;

    /** Batas `<tablePart>`: pemuat mengurai ulang relasi sheet untuk setiap tabel. Template tanpa tabel. */
    private const MAKS_TABEL = 10;

    private readonly BaseConnection $db;

    private readonly Chatbot $config;

    private int $kategoriBaru = 0;

    private int $tagBaru = 0;

    public function __construct(?BaseConnection $db = null, ?Chatbot $config = null, private readonly ?HtmlSanitizer $sanitizer = null)
    {
        $this->db     = $db ?? db_connect();
        $this->config = $config ?? config(Chatbot::class);
    }

    /**
     * @return array{ditambah: int, kategori_baru: int, tag_baru: int, dilewati: list<array{baris: int, alasan: string}>, galat: list<array{baris: int, alasan: string}>}
     */
    public function impor(?UploadedFile $berkas): array
    {
        $baris = $this->baca($berkas);

        $this->kategoriBaru = 0;
        $this->tagBaru      = 0;
        $dilewati           = [];
        $galat              = [];
        $ditambah           = 0;
        $qnaModel           = new QnaModel($this->db);

        $this->db->transException(true)->transBegin();

        try {
            foreach ($baris as $no => [$pertanyaan, $jawabanMentah, $kategori, $tagTeks]) {
                if ($pertanyaan === '' && $jawabanMentah === '' && $kategori === '' && $tagTeks === '') {
                    continue;
                }

                if ($pertanyaan === '' || $jawabanMentah === '') {
                    $dilewati[] = ['baris' => $no, 'alasan' => 'Pertanyaan atau jawaban kosong.'];

                    continue;
                }

                if ($kategori === '') {
                    $galat[] = ['baris' => $no, 'alasan' => 'Kategori wajib diisi.'];

                    continue;
                }

                $jawaban = $this->sanitizer()->sanitize($jawabanMentah);

                if (! HtmlSanitizer::hasVisibleContent($jawaban)) {
                    $galat[] = ['baris' => $no, 'alasan' => 'Jawaban kosong sesudah dibersihkan.'];

                    continue;
                }

                $terlaluPanjang = BatasQna::pertanyaan($pertanyaan) ?? BatasQna::jawaban($jawaban);

                if ($terlaluPanjang !== null) {
                    $galat[] = ['baris' => $no, 'alasan' => $terlaluPanjang];

                    continue;
                }

                $idKategori = $this->cariAtauBuat('kategori', $kategori, $no, $galat);
                $idTag      = [];

                foreach (explode(',', $tagTeks) as $t) {
                    $t = trim($t);

                    if ($t === '') {
                        continue;
                    }

                    $id = $this->cariAtauBuat('tag', $t, $no, $galat);

                    if ($id !== null) {
                        $idTag[$id] = true;
                    }
                }

                if ($idKategori === null || ($galat !== [] && end($galat)['baris'] === $no)) {
                    continue;
                }

                $sekarang = gmdate('Y-m-d H:i:s');
                $idQna    = (int) $qnaModel->insert([
                    'pertanyaan'         => $pertanyaan,
                    'jawaban'            => $jawaban,
                    'id_qna_kategori_fk' => $idKategori,
                    'status_qna_fk'      => 1,
                    'is_fallback_option' => 0,
                    'created_at'         => $sekarang,
                    'updated_at'         => $sekarang,
                ], true);

                $tag = array_keys($idTag);
                sort($tag);

                foreach ($tag as $t) {
                    $this->db->table('qna_has_tag')->insert(['qna_id' => $idQna, 'qna_tag_id' => $t]);
                }

                if ($tag !== []) {
                    $qnaModel->writeAudit(AuditLogModel::EVENT_CREATE, $idQna, null, ['qna_id' => $idQna, 'qna_tag_id' => $tag], 'qna_has_tag');
                }

                $ditambah++;
            }

            if ($galat !== []) {
                $this->db->transRollback();

                return ['ditambah' => 0, 'kategori_baru' => 0, 'tag_baru' => 0, 'dilewati' => $dilewati, 'galat' => $galat];
            }

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        } finally {
            $this->db->transException(false);
        }

        return ['ditambah' => $ditambah, 'kategori_baru' => $this->kategoriBaru, 'tag_baru' => $this->tagBaru, 'dilewati' => $dilewati, 'galat' => $galat];
    }

    /**
     * Template impor (F8-47): empat judul kolom + satu baris contoh.
     */
    public static function template(): string
    {
        return EksporTabel::xlsx(self::JUDUL_KOLOM, [self::CONTOH], 'Template QnA');
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}> nomor baris Excel => sel
     */
    private function baca(?UploadedFile $berkas): array
    {
        if ($berkas === null || $berkas->getError() !== UPLOAD_ERR_OK || ! is_file($berkas->getTempName())) {
            throw ValidationException::forField('berkas', 'Berkas Excel wajib diunggah.');
        }

        if (strtolower($berkas->getClientExtension()) !== 'xlsx') {
            throw ValidationException::forField('berkas', 'Berkas harus berformat .xlsx.');
        }

        if ($berkas->getSize() > $this->config->imporMaksByte) {
            throw ValidationException::forField('berkas', 'Ukuran berkas maksimal 2 MB.');
        }

        $path   = $berkas->getTempName();
        $maks   = $this->config->imporMaksBaris;
        $reader = new Xlsx();
        $buku   = null;
        $ekstra = '';

        // Ukuran sheet diperiksa SEBELUM memuat: xlsx kecil dengan satu sel di baris 1.048.576 tidak boleh dimuat penuh ke
        // memori. listWorksheetInfo memindai XML sheet tanpa membangun sel, tetapi isi part tetap ditampung utuh sebagai
        // string dan `totalRows` = atribut `r` baris TERAKHIR (bukan maksimum). Karena itu struktur berkas dipindai dulu
        // dengan pemindai impor slip (periksaStruktur, CR-182), yang juga membatasi muatan sel.
        try {
            // Urutan penting (review CR-182 R1-1): ukuran isi dari direktori ZIP dulu (tanpa dekompresi), lalu pemindai,
            // BARU reader vendor. `canRead` sudah mendekompresi utuh `_rels/.rels`, jadi bom dekompresi di part itu harus
            // ditolak sebelumnya; fatal memori tidak bisa ditangkap try/catch.
            $zipInfo = $this->periksaUkuranZip($path, $reader);
            $padat   = $this->periksaStruktur($path, $zipInfo['sheet']);

            // Berkas padat (sel/bobot terlampaui) hanya dibuka listWorksheetInfo untuk memilih pesan (R1-4) bila JUMLAH isi
            // semua entrinya wajar untuk berkas sah. listWorksheetInfo menampung setiap part sheet utuh dan membaca semua node
            // di SEMUA sheet, dan part sheet bisa di path mana pun (Target relasi): satu sheet 32 MB `<c/>` 7-12 detik, sheet
            // di `xl/lain/` 6,8 detik, 4 sheet × 7,8 MB `<row/>` 6,3 detik (review CR-182 R2-1). Selain itu langsung "rusak".
            if ($padat && ($zipInfo['isi'] > self::MAKS_BYTE_ISI_PADAT || $zipInfo['sheet'] * $zipInfo['terbesar'] > self::MAKS_BYTE_ISI_PADAT)) {
                throw ValidationException::forField('berkas', 'Berkas Excel rusak atau tidak dapat dibaca.');
            }

            if (! $reader->canRead($path)) {
                throw ValidationException::forField('berkas', 'Berkas harus berformat .xlsx.');
            }

            // Sel kosong yang hanya bergaya (mis. border template sampai baris 1.500) tidak dihitung (review CR V-1).
            $reader->setReadEmptyCells(false);
            $info = $reader->listWorksheetInfo($path)[0] ?? null;

            if ($info === null) {
                throw ValidationException::forField('berkas', 'Berkas Excel rusak atau tidak dapat dibaca.');
            }

            if ((int) $info['totalRows'] - 1 > $maks) {
                throw ValidationException::forField('berkas', "Jumlah baris data maksimal {$maks}.");
            }

            // Hanya sel/bobot yang terlampaui (byte, `<col>`, tabel aman): pesan baris di atas untuk berkas sah yang barisnya
            // terlalu banyak (R1-4), selain itu tetap "rusak". Berkas seperti ini tidak pernah dimuat.
            if ($padat) {
                throw ValidationException::forField('berkas', 'Berkas Excel rusak atau tidak dapat dibaca.');
            }

            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly([(string) $info['worksheetName']]);
            // TANPA read filter (review CR-182 R1-3): dengan filter, `ColumnAndRowAttributes` PhpSpreadsheet 5.10 memanggil
            // readCell untuk setiap kolom `<col>` × setiap baris (`<col 1..2048>` + 20 ribu baris kosong: 7,5-8,6 detik terukur;
            // 130 ribu baris: fatal max_execution_time). Biaya linear berasal dari TIDAK adanya filter: tanpa filter
            // rowsAttributes tidak dibangun di readDataOnly. `setIgnoreRowsWithNoCells(true)` dalam kondisi ini no-op, dipasang
            // hanya sebagai pertahanan berlapis bila filter kelak dipasang lagi. Sel yang dimuat = sel sheet 1, dibatasi
            // `sel`/`bobot` pemindai. Isi tetap hanya A1:D(maks+1) + E1 (review CR V-2); rumus tidak dihitung
            // (`calculateFormulas` false) sehingga teks sel sama dengan saat masih memakai filter. Baris di atas maks+1
            // (mis. urutan `r` acak yang menipu totalRows) tetap tidak ikut, seperti sebelumnya.
            $reader->setIgnoreRowsWithNoCells(true);

            $buku   = $reader->load($path);
            $sheet  = $buku->getSheet(0);
            $akhir  = max(1, min($sheet->getHighestDataRow(), $maks + 1));
            $sel    = $sheet->rangeToArray('A1:D' . $akhir, null, false, false, false);
            $ekstra = trim(self::teks($sheet->getCell('E1')->getValue()));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw ValidationException::forField('berkas', 'Berkas Excel rusak atau tidak dapat dibaca.');
        } finally {
            if ($buku instanceof Spreadsheet) {
                $buku->disconnectWorksheets();
            }
        }

        $judul = array_map(static fn (mixed $v): string => trim(self::teks($v)), $sel[0] ?? []);

        while ($judul !== [] && end($judul) === '') {
            array_pop($judul);
        }

        if ($judul !== self::JUDUL_KOLOM || $ekstra !== '') {
            throw ValidationException::forField('berkas', 'Format template tidak sesuai.');
        }

        $data = [];

        foreach (array_slice(array_values($sel), 1, null, true) as $i => $r) {
            $data[$i + 1] = [trim(self::teks($r[0] ?? null)), trim(self::teks($r[1] ?? null)), trim(self::teks($r[2] ?? null)), trim(self::teks($r[3] ?? null))];
        }

        while ($data !== [] && implode('', end($data)) === '') {
            array_pop($data);
        }

        if (count($data) > $this->config->imporMaksBaris) {
            throw ValidationException::forField('berkas', 'Jumlah baris data maksimal ' . $this->config->imporMaksBaris . '.');
        }

        return $data;
    }

    /**
     * Cari judul (status 1/2, perbandingan `_ci`) atau buat baru status 1. Cocok dengan status 2 → galat baris.
     *
     * @param list<array{baris: int, alasan: string}> $galat
     */
    private function cariAtauBuat(string $jenis, string $judul, int $no, array &$galat): ?int
    {
        $kat   = $jenis === 'kategori';
        $tabel = $kat ? 'qna_kategori' : 'qna_tag';
        $pk    = $kat ? 'id_qna_kategori' : 'id_qna_tag';
        $kol   = $kat ? 'judul_kategori' : 'judul_tag';
        $st    = $kat ? 'status_qna_kategori_fk' : 'status_qna_tag_fk';
        $maks  = $kat ? 150 : 100;
        $label = $kat ? 'Kategori' : 'Tag';

        if (mb_strlen($judul) > $maks) {
            $galat[] = ['baris' => $no, 'alasan' => "{$label} \"{$judul}\" lebih dari {$maks} karakter."];

            return null;
        }

        $r = $this->db->table($tabel)->select("{$pk}, {$st}")->where($kol, $judul)->whereIn($st, [1, 2])->orderBy($pk, 'ASC')->get()->getRowArray();

        if (is_array($r)) {
            if ((int) $r[$st] !== 1) {
                $galat[] = ['baris' => $no, 'alasan' => "{$label} \"{$judul}\" tidak aktif."];

                return null;
            }

            return (int) $r[$pk];
        }

        $data  = [$kol => $judul, $st => 1, 'created_at' => gmdate('Y-m-d H:i:s')];
        $model = $kat ? new QnaKategoriModel($this->db) : new QnaTagModel($this->db);

        try {
            $id = (int) $model->insert($data, true);
        } catch (Throwable $e) {
            if ($e instanceof DatabaseException && $e->getCode() === 1062) {
                // Balapan dengan admin lain (UNIQUE `judul_unik`, catatan DBV N2): baris itu belum terlihat oleh transaksi ini.
                throw ValidationException::forField('berkas', "{$label} \"{$judul}\" baru saja dibuat pengguna lain. Ulangi impor.");
            }

            throw $e;
        }

        $kat ? $this->kategoriBaru++ : $this->tagBaru++;

        return $id;
    }

    /**
     * Pindai struktur `.xlsx` SEBELUM `listWorksheetInfo`/`load()` (CR-182) dengan {@see PemindaiXlsx} milik impor slip
     * (CR-177), dipanggil apa adanya. Ketergantungan lintas modul: perubahan anggaran atau hitungan di pemindai itu ikut
     * memengaruhi impor QnA (test `ImporQnaPemindaiTest`).
     *
     * Batas diturunkan dari `imporMaksBaris` dan 5 kolom yang dibaca (A-D + E1), dengan ruang untuk sel kosong bergaya
     * (border template, review CR V-1) dan kolom lain di luar A-E; anggaran bobot per sel sama dengan impor slip
     * (`Presensi::BOBOT_PER_SEL_IMPOR`). Yang ditolak: `<col>` tidak sah (`max` < `min` membuat
     * `Reader\Xlsx\ColumnAndRowAttributes::readColumnAttributes` PhpSpreadsheet 5.10 berputar tanpa akhir walau
     * readDataOnly), langkah `<col>` di atas batas (tanpa read filter biayanya linear, tidak dikali jumlah baris), isi
     * terdekompresi atau salinan teks terlalu besar, terlalu banyak tabel, dan berkas yang tidak bisa dipindai. Semuanya
     * → 422 dengan pesan "rusak" yang sudah ada; tidak ada pesan baru. Sel/bobot terlampaui → `true` (padat), pemanggil
     * memilih pesan baris atau "rusak".
     *
     * Trade-off yang diterima reviewer (R2-6): entri ZIP yang deklarasi encoding-nya bukan UTF-8 ditolak pemindai walau
     * entri itu tidak pernah dimuat (SVG di `xl/media`, `docProps`, `customXml`); di main berkas seperti ini diterima.
     * PemindaiXlsx tidak boleh diubah di CR ini.
     */
    private function periksaStruktur(string $path, int $jumlahSheet): bool
    {
        $maksSel = ($this->config->imporMaksBaris + 1) * self::KOLOM_DIBACA * self::RUANG_SEL;
        $bobot   = $maksSel * Presensi::BOBOT_PER_SEL_IMPOR;

        try {
            $ukuran = PemindaiXlsx::pindai($path, self::MAKS_BYTE_ISI, $bobot);
        } catch (Throwable) {
            throw ValidationException::forField('berkas', 'Berkas Excel rusak atau tidak dapat dibaca.');
        }

        // Pemindai menghitung satu part sekali, padahal pemuat bisa memproses part yang sama untuk setiap `<sheet>` (nama
        // sheet ganda lolos setLoadSheetsOnly): hitungan dikalikan jumlah `<sheet>` sebagai batas atas (R3-1).
        $padat = $ukuran['sel'] * $jumlahSheet > $maksSel || $ukuran['bobot'] * $jumlahSheet > $bobot;

        // Bila bobot terlampaui, pemindai berhenti lebih awal sehingga `iterasi_kolom` bisa belum lengkap; berkas itu tetap
        // tidak pernah dimuat (lihat pemanggil), jadi aman.
        if ($ukuran['byte'] > self::MAKS_BYTE_ISI || $ukuran['iterasi_kolom'] > self::MAKS_ITERASI_KOLOM
            || $ukuran['tabel'] > self::MAKS_TABEL || $ukuran['salinan'] > self::MAKS_BYTE_ISI) {
            throw ValidationException::forField('berkas', 'Berkas Excel rusak atau tidak dapat dibaca.');
        }

        return $padat;
    }

    /**
     * Pemeriksaan ZIP sebelum reader vendor menyentuh berkas (R1-1, R3-1): jumlah ukuran sesudah dekompresi semua entri
     * menurut direktori ZIP (`statIndex`, tanpa dekompresi), ukuran entri terbesar, dan jumlah elemen `<sheet>`.
     *
     * `listWorksheetInfo` vendor (PhpSpreadsheet 5.10 `Reader\Xlsx::listWorksheetInfo`) mengurai part sheet untuk SETIAP
     * `<sheet>` di workbook, termasuk rujukan berulang ke part yang sama, dan part bisa berada di path mana pun (Target
     * relasi, `../`, `//`, entri ber-backslash, akar ZIP). Karena itu biaya dibatasi kasar TANPA meniru resolusi path:
     * jumlah `<sheet>` × entri terbesar ≤ batas isi, dan jumlah `<sheet>` ≤ {@see self::MAKS_SHEET}. `<sheet>` dihitung
     * dari SEMUA entri (aliran 64 KB, isi sudah dibatasi 32 MB), bukan hanya `xl/workbook.xml`; hitungan berlebih hanya
     * membuat pemeriksaan lebih ketat. Contoh yang ditutup: 500 `<sheet>` → satu part 28 MB spasi (unggahan 35 KB) fatal
     * 60 detik di main.
     *
     * Bukan ZIP: pesan format hanya bila `canRead` juga menolak (perilaku lama). `canRead` untuk berkas bukan ZIP hanya
     * membaca header, jadi aman; xlsx bersandi (paket OLE terenkripsi) lolos `canRead` → "rusak" seperti main (R2-3).
     * Trade-off (R3-4, kasus langka, pesannya tetap pesan lama): arsip ZIP bukan-xlsx yang isinya > 32 MB, entri
     * berdeklarasi encoding selain UTF-8, atau entri terenkripsi AES kini mendapat "rusak", padahal main memberi pesan
     * format.
     *
     * @return array{isi: int, terbesar: int, sheet: int}
     */
    private function periksaUkuranZip(string $path, Xlsx $reader): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            if (! $reader->canRead($path)) {
                throw ValidationException::forField('berkas', 'Berkas harus berformat .xlsx.');
            }

            throw ValidationException::forField('berkas', 'Berkas Excel rusak atau tidak dapat dibaca.');
        }

        try {
            $byte     = 0;
            $terbesar = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat   = $zip->statIndex($i);
                $ukuran = $stat === false ? PHP_INT_MAX >> 1 : (int) $stat['size'];
                $byte += $ukuran;
                $terbesar = max($terbesar, $ukuran);

                if ($byte > self::MAKS_BYTE_ISI) {
                    throw ValidationException::forField('berkas', 'Berkas Excel rusak atau tidak dapat dibaca.');
                }
            }

            $sheet = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $sheet += self::hitungSheet($zip, $i);
            }

            $sheet = max(1, $sheet);

            if ($sheet > self::MAKS_SHEET || $sheet * $terbesar > self::MAKS_BYTE_ISI) {
                throw ValidationException::forField('berkas', 'Berkas Excel rusak atau tidak dapat dibaca.');
            }

            return ['isi' => $byte, 'terbesar' => $terbesar, 'sheet' => $sheet];
        } finally {
            $zip->close();
        }
    }

    /**
     * Jumlah elemen `<sheet>` (dengan atau tanpa prefiks namespace) di satu entri, dibaca sebagai aliran.
     */
    private static function hitungSheet(ZipArchive $zip, int $indeks): int
    {
        $aliran = $zip->getStreamIndex($indeks);

        if ($aliran === false) {
            throw ValidationException::forField('berkas', 'Berkas Excel rusak atau tidak dapat dibaca.');
        }

        $jumlah = 0;
        $ekor   = '';

        try {
            while (! feof($aliran)) {
                $potong = fread($aliran, 65536);

                if ($potong === false || $potong === '') {
                    break;
                }

                // Pola yang terpotong di batas potongan ketemu lewat ekor; pola utuh di ekor tidak dihitung dua kali
                // karena ekor dipotong sesudah kemunculan terakhir.
                $teks = $ekor . $potong;
                $jumlah += (int) preg_match_all('/<(?:[\w.-]+:)?sheet[\s\/>]/', $teks, $m, PREG_OFFSET_CAPTURE);
                $akhir = $m[0] === [] ? 0 : (int) end($m[0])[1] + 1;
                $ekor  = substr($teks, max($akhir, strlen($teks) - 64));
            }
        } finally {
            fclose($aliran);
        }

        return $jumlah;
    }

    private static function teks(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private function sanitizer(): HtmlSanitizer
    {
        return $this->sanitizer ?? service('htmlSanitizer');
    }
}
