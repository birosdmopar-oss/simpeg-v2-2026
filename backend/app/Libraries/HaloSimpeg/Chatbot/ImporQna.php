<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg\Chatbot;

use App\Exceptions\ValidationException;
use App\Libraries\Html\HtmlSanitizer;
use App\Models\AuditLogModel;
use App\Models\HaloSimpeg\Chatbot\QnaKategoriModel;
use App\Models\HaloSimpeg\Chatbot\QnaModel;
use App\Models\HaloSimpeg\Chatbot\QnaTagModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Chatbot;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;

/**
 * Impor QnA dari Excel template (PRD Fase 8 F8-37, AC-E10.15; `dev:libraries/hr/L_chatbot.php:405-468`).
 *
 * - Hanya `.xlsx` (diperiksa PhpSpreadsheet, bukan MIME browser), maks 2 MB dan 1.000 baris data; judul kolom harus sama
 *   dengan template (4 kolom, urutan sama) → selain itu 422 `errors.berkas` tanpa memproses baris.
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
        return \App\Libraries\Kepegawaian\Pegawai\EksporTabel::xlsx(self::JUDUL_KOLOM, [self::CONTOH], 'Template QnA');
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
        $reader = new Xlsx();

        try {
            if (! $reader->canRead($path)) {
                throw ValidationException::forField('berkas', 'Berkas harus berformat .xlsx.');
            }

            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);
            $sel   = $sheet->toArray(null, false, false, false);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw ValidationException::forField('berkas', 'Berkas Excel rusak atau tidak dapat dibaca.');
        }

        $judul = array_map(static fn (mixed $v): string => trim(self::teks($v)), $sel[0] ?? []);

        while ($judul !== [] && end($judul) === '') {
            array_pop($judul);
        }

        if ($judul !== self::JUDUL_KOLOM) {
            throw ValidationException::forField('berkas', 'Format template tidak sesuai.');
        }

        $data = [];

        foreach (array_slice($sel, 1, null, true) as $i => $r) {
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
        $id    = (int) $model->insert($data, true);
        $kat ? $this->kategoriBaru++ : $this->tagBaru++;

        return $id;
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
