<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg\Chatbot;

use App\Exceptions\ValidationException;
use App\Libraries\HaloSimpeg\Chatbot\ImporQna;
use App\Libraries\Kepegawaian\Pegawai\EksporTabel;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Chatbot;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\Chatbot\ChatbotFixtureTrait;

/**
 * Impor QnA Excel (MAKE-094; PRD Fase 8 F8-37, AC-E10.12, AC-E10.15, AC-E10.24). Berkas xlsx SINTETIS dibuat di dalam test.
 *
 * @internal
 */
final class ImporQnaTest extends DatabaseTestCase
{
    use ChatbotFixtureTrait;

    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }

        Factories::reset('config');
        parent::tearDown();
    }

    public function testImporMembuatKategoriTagDanDeduplikasi(): void
    {
        $adaKat = $this->buatKategori('Cuti');
        $adaTag = $this->buatTag('Cuti');
        $hapus  = $this->buatKategori('Arsip', 10);

        $hasil = $this->impor([
            ['Cara cuti tahunan?', '<p>Isi <script>x</script></p>', ' cuti ', 'cuti, CUTI ,tahunan,, '],
            ['Cara cuti besar?', 'Jawab', 'Kinerja', 'tahunan'],
            ['', 'tanpa pertanyaan', 'Cuti', ''],
            ['Kinerja lagi?', 'Jawab', 'kinerja', ''],
            ['Arsip baru?', 'Jawab', 'Arsip', ''],
            ['', '', '', ''],
        ]);

        $this->assertSame(['ditambah' => 4, 'kategori_baru' => 2, 'tag_baru' => 1, 'dilewati' => [['baris' => 4, 'alasan' => 'Pertanyaan atau jawaban kosong.']], 'galat' => []], $hasil);

        $q = $this->db->table('qna')->orderBy('id_qna')->get()->getResultArray();
        $this->assertSame([$adaKat, 0, 1], [(int) $q[0]['id_qna_kategori_fk'], (int) $q[0]['is_fallback_option'], (int) $q[0]['status_qna_fk']]);
        $this->assertStringNotContainsString('script', (string) $q[0]['jawaban']);
        $this->assertSame((int) $q[1]['id_qna_kategori_fk'], (int) $q[2]['id_qna_kategori_fk'], 'judul baru di berkas yang sama = satu id (_ci)');
        $this->assertNotSame($hapus, (int) $q[3]['id_qna_kategori_fk'], 'judul status 10 → kategori baru');
        $this->assertSame('0', (string) $this->db->table('qna_kategori')->where('id_qna_kategori', $q[1]['id_qna_kategori_fk'])->get()->getRow('tampil_bubble'));

        $rel = array_map('intval', array_column($this->db->table('qna_has_tag')->where('qna_id', $q[0]['id_qna'])->get()->getResultArray(), 'qna_tag_id'));
        sort($rel);
        $this->assertCount(2, $rel, '"cuti, CUTI" = satu relasi');
        $this->assertContains($adaTag, $rel);
        $this->assertGreaterThan(0, $this->db->table('audit_logs')->where('entity', 'qna')->countAllResults());
    }

    public function testSatuGalatTidakMenyimpanApaPun(): void
    {
        $this->buatKategori('Nonaktif', 2);
        $this->buatTag('lama', 2);

        $hasil = $this->impor([
            ['Baik?', 'Jawab', 'Baru', 'baru'],
            ['Tanpa kategori?', 'Jawab', '', ''],
            ['Kategori nonaktif?', 'Jawab', 'nonaktif', ''],
            ['Tag nonaktif?', 'Jawab', 'Baru', 'LAMA'],
            ['Jawaban skrip?', '<script>x</script>', 'Baru', ''],
        ]);

        $this->assertSame(0, $hasil['ditambah']);
        $this->assertSame([3, 4, 5, 6], array_column($hasil['galat'], 'baris'));
        $this->assertSame(0, $this->db->table('qna')->countAllResults());
        $this->assertSame(1, $this->db->table('qna_kategori')->countAllResults(), 'kategori baru ikut dibatalkan');
        $this->assertSame(1, $this->db->table('qna_tag')->countAllResults());
    }

    public function testBerkasDitolak(): void
    {
        $this->assertGalatBerkas('Format template tidak sesuai.', $this->berkas(EksporTabel::xlsx(['Pertanyaan', 'Jawaban', 'Tag', 'Kategori'], [['a', 'b', 'c', 'd']])));
        $this->assertGalatBerkas('Berkas harus berformat .xlsx.', $this->berkas('a,b,c', 'qna.csv'));
        $this->assertGalatBerkas('Berkas harus berformat .xlsx.', $this->berkas('bukan zip', 'qna.xlsx'));
        $this->assertGalatBerkas('Berkas Excel wajib diunggah.', null);

        $this->pasangConfigChatbot(self::KUNCI_UJI, static function (Chatbot $c): void {
            $c->imporMaksBaris = 2;
            $c->imporMaksByte  = 100_000;
        });
        $this->assertGalatBerkas('Jumlah baris data maksimal 2.', $this->berkas(EksporTabel::xlsx(ImporQna::JUDUL_KOLOM, [['a', 'b', 'c', ''], ['a', 'b', 'c', ''], ['a', 'b', 'c', '']])));
        $this->assertGalatBerkas('Ukuran berkas maksimal 2 MB.', $this->berkas(str_repeat('x', 100_001), 'qna.xlsx'));
        $this->assertSame(0, $this->db->table('qna')->countAllResults());
    }

    public function testSelJauhDitolakTanpaMemuatPenuh(): void
    {
        $buku = new Spreadsheet();
        $buku->getActiveSheet()->fromArray(ImporQna::JUDUL_KOLOM, null, 'A1');
        $buku->getActiveSheet()->setCellValue('D1048576', 'x');
        $path = (string) tempnam(sys_get_temp_dir(), 'cbj');
        (new XlsxWriter($buku))->save($path);
        $buku->disconnectWorksheets();
        $this->tmp[] = $path;
        $this->assertLessThan(20_000, (int) filesize($path), 'berkas kecil');

        $this->assertGalatBerkas('Jumlah baris data maksimal 1000.', new UploadedFile($path, 'qna.xlsx', 'application/octet-stream', (int) filesize($path), UPLOAD_ERR_OK));
    }

    public function testIsiTerlaluPanjangJadiGalatBaris(): void
    {
        $hasil = $this->impor([
            // Sel Excel maks 32.767 karakter → pakai karakter 3 byte agar melewati TEXT 65.535 byte.
            [str_repeat('€', 30000), 'Jawab', 'Cuti', ''],
            ['Pendek?', '<p>' . str_repeat('€', 30000) . '</p>', 'Cuti', ''],
        ]);

        $this->assertSame([['baris' => 2, 'alasan' => 'Pertanyaan terlalu panjang.'], ['baris' => 3, 'alasan' => 'Jawaban terlalu panjang.']], $hasil['galat']);
        $this->assertSame([0, 0], [$hasil['ditambah'], $this->db->table('qna')->countAllResults()]);
    }

    #[Group('db-isolasi-penuh')]
    public function testBalapanJudulTag1062(): void
    {
        $trg = $this->db->prefixTable('trg_uji_1062_tag');
        $this->db->query("CREATE TRIGGER {$trg} BEFORE INSERT ON " . $this->db->prefixTable('qna_tag') . " FOR EACH ROW SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO = 1062, MESSAGE_TEXT = 'Duplicate entry uji'");

        try {
            try {
                $this->impor([['P?', 'J', 'Cuti', 'balapan']]);
                $this->fail('seharusnya 422');
            } catch (ValidationException $e) {
                $this->assertSame(['berkas' => ['Tag "balapan" baru saja dibuat pengguna lain. Ulangi impor.']], $e->getErrors());
            }

            $this->assertSame([0, 0], [$this->db->table('qna')->countAllResults(), $this->db->table('qna_kategori')->countAllResults()], 'semua dibatalkan');
        } finally {
            $this->db->query("DROP TRIGGER IF EXISTS {$trg}");
            $this->bersihkanChatbot();
        }
    }

    /**
     * @param list<list<string>> $baris
     *
     * @return array<string, mixed>
     */
    private function impor(array $baris): array
    {
        return (new ImporQna($this->db))->impor($this->berkas(EksporTabel::xlsx(ImporQna::JUDUL_KOLOM, $baris)));
    }

    private function berkas(string $isi, string $nama = 'qna.xlsx'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cbi');
        file_put_contents($path, $isi);
        $this->tmp[] = $path;

        return new UploadedFile($path, $nama, 'application/octet-stream', strlen($isi), UPLOAD_ERR_OK);
    }

    private function assertGalatBerkas(string $pesan, ?UploadedFile $berkas): void
    {
        try {
            (new ImporQna($this->db))->impor($berkas);
            $this->fail("seharusnya 422: {$pesan}");
        } catch (ValidationException $e) {
            $this->assertSame(['berkas' => [$pesan]], $e->getErrors());
        }
    }
}
