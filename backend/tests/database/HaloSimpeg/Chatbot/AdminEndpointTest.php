<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg\Chatbot;

use App\Constants\Role;
use App\Libraries\HaloSimpeg\Chatbot\ImporQna;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\Chatbot\ChatbotFixtureTrait;

/**
 * Admin Chatbot Manager lewat HTTP (MAKE-094; PRD Fase 8 §6.5, AC-E10.9, .12, .13, .16, .19, .24..28). Data sintetis.
 *
 * @internal
 */
final class AdminEndpointTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use ChatbotFixtureTrait;
    use FeatureTestTrait;

    private const URL = 'api/v1/halo-simpeg/chatbot/';

    protected $seed = AuthSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangConfigChatbot();
        $this->pasangThrottlerChatbot();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        Factories::reset('config');
        parent::tearDown();
    }

    public function testRoleAdminSaja(): void
    {
        foreach ([Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PIMPINAN] as $role) {
            foreach (['kategori', 'tag', 'qna', 'qna/opsi', 'statistik', 'riwayat', 'tag/ekspor?format=xlsx', 'qna/template'] as $p) {
                $this->asRole($role)->get(self::URL . $p)->assertStatus(403);
            }

            $this->asRole($role)->post(self::URL . 'kategori', ['judul' => 'X'])->assertStatus(403);
        }
    }

    public function testKategoriCrudStatusHapus(): void
    {
        $k = $this->data($this->admin()->post(self::URL . 'kategori', ['judul' => '  Cuti  ', 'tampil_bubble' => 1]), 201);
        $this->assertSame(['judul' => 'Cuti', 'status' => 1, 'tampil_bubble' => 1], array_diff_key($k, ['id' => 0]));

        $this->admin()->post(self::URL . 'kategori', ['judul' => ''])->assertStatus(422);
        $this->assertSame('Judul kategori tidak boleh kosong!', $this->errors($this->admin()->post(self::URL . 'kategori', ['judul' => ' ']))['judul']);

        $u = $this->data($this->admin()->withBodyFormat('json')->put(self::URL . "kategori/{$k['id']}", ['judul' => 'Cuti Pegawai', 'status' => 2, 'tampil_bubble' => 0]));
        $this->assertSame(['Cuti Pegawai', 2, 0], [$u['judul'], $u['status'], $u['tampil_bubble']]);

        $s = $this->data($this->admin()->withBodyFormat('json')->patch(self::URL . "kategori/{$k['id']}/status", ['status' => 1]));
        $this->assertSame(1, $s['status']);
        $this->admin()->withBodyFormat('json')->patch(self::URL . "kategori/{$k['id']}/status", ['status' => 10])->assertStatus(422);

        $this->admin()->post(self::URL . 'kategori', ['judul' => 'Lain']);
        $daftar = $this->data($this->admin()->get(self::URL . 'kategori?status=1'));
        $this->assertSame(['Cuti Pegawai', 'Lain'], array_column($daftar['items'], 'judul'));

        $this->admin()->delete(self::URL . "kategori/{$k['id']}")->assertStatus(200);
        $this->assertSame('10', (string) $this->db->table('qna_kategori')->where('id_qna_kategori', $k['id'])->get()->getRow('status_qna_kategori_fk'));
        $this->admin()->get(self::URL . "kategori/{$k['id']}")->assertStatus(404);
        $this->assertSame(['Lain'], array_column($this->data($this->admin()->get(self::URL . 'kategori'))['items'], 'judul'), 'status 10 tidak tampil');
        $this->assertGreaterThan(0, $this->db->table('audit_logs')->where('entity', 'qna_kategori')->countAllResults(), 'audit');
    }

    public function testJudulUnikStatusAktifTidakAktif(): void
    {
        foreach (['kategori', 'tag'] as $jalur) {
            $a = $this->data($this->admin()->post(self::URL . $jalur, ['judul' => 'Tunjangan Kinerja']), 201);
            $this->admin()->post(self::URL . $jalur, ['judul' => ' tunjangan kinerja '])->assertStatus(422);
            $this->admin()->post(self::URL . $jalur, ['judul' => 'Tunjangan Kinérja'])->assertStatus(422);
            $this->admin()->withBodyFormat('json')->patch(self::URL . "{$jalur}/{$a['id']}/status", ['status' => 2]);
            $this->admin()->post(self::URL . $jalur, ['judul' => 'TUNJANGAN KINERJA'])->assertStatus(422);

            $b = $this->data($this->admin()->post(self::URL . $jalur, ['judul' => 'Lain']), 201);
            $this->admin()->withBodyFormat('json')->put(self::URL . "{$jalur}/{$b['id']}", ['judul' => 'tunjangan kinerja', 'status' => 1])->assertStatus(422);

            $this->admin()->delete(self::URL . "{$jalur}/{$a['id']}");
            $this->data($this->admin()->post(self::URL . $jalur, ['judul' => 'Tunjangan Kinerja']), 201); // judul status 10 dipakai lagi
        }
    }

    public function testTagUrutIdMenurunDanEkspor(): void
    {
        $this->buatTag('cuti');
        $this->buatTag('=SUM(A1)', 2);
        $this->buatTag('dihapus', 10);

        $this->assertSame(['=SUM(A1)', 'cuti'], array_column($this->data($this->admin()->get(self::URL . 'tag'))['items'], 'judul'));

        $r = $this->admin()->get(self::URL . 'tag/ekspor?format=xlsx');
        $r->assertStatus(200);
        $this->assertMatchesRegularExpression('/attachment; filename="Export_Tag_QnA_\d{8}_\d{6}\.xlsx"/', $r->response()->getHeaderLine('Content-Disposition'));
        $baris = $this->bacaXlsx((string) $r->response()->getBody());
        $this->assertSame([['No', 'Tag', 'Status'], ['1', '=SUM(A1)', 'Tidak Aktif'], ['2', 'cuti', 'Aktif']], $baris);
        $this->assertStringNotContainsString('<f>', $this->sheetXml((string) $r->response()->getBody()), 'bukan rumus');

        $this->admin()->get(self::URL . 'tag/ekspor?format=csv')->assertStatus(422);
    }

    public function testTemplateImpor(): void
    {
        $r = $this->admin()->get(self::URL . 'qna/template');
        $r->assertStatus(200);
        $this->assertSame([ImporQna::JUDUL_KOLOM, ImporQna::CONTOH], $this->bacaXlsx((string) $r->response()->getBody()));
    }

    public function testQnaSimpanUbahTagDanSanitasi(): void
    {
        $kat = $this->buatKategori('Cuti');
        $t1  = $this->buatTag('cuti');
        $t2  = $this->buatTag('tahunan');
        $t3  = $this->buatTag('besar');

        $q = $this->data($this->admin()->withBodyFormat('json')->post(self::URL . 'qna', [
            'pertanyaan'  => 'Cara cuti?', 'jawaban' => '<p onclick="x()">Isi<script>alert(1)</script> <a href="javascript:alert(1)">t</a></p>',
            'id_kategori' => $kat, 'tag' => [$t1, $t2, $t1], 'is_fallback_option' => 1,
        ]), 201);

        $this->assertSame([1, 1, ['id' => $kat, 'judul' => 'Cuti', 'status' => 1]], [$q['status'], $q['is_fallback_option'], $q['kategori']]);
        $this->assertSame([$t1, $t2], array_column($q['tag'], 'id'));
        $this->assertStringNotContainsStringIgnoringCase('script', $q['jawaban']);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $q['jawaban']);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $q['jawaban']);

        $u = $this->data($this->admin()->withBodyFormat('json')->put(self::URL . "qna/{$q['id']}", [
            'pertanyaan' => 'Cara cuti tahunan?', 'jawaban' => '<p>Baru</p>', 'id_kategori' => $kat, 'tag' => [$t3], 'is_fallback_option' => 0, 'status' => 2,
        ]));
        $this->assertSame([[$t3], 2, '<p>Baru</p>'], [array_column($u['tag'], 'id'), $u['status'], $u['jawaban']]);
        $this->assertSame(1, $this->db->table('qna_has_tag')->where('qna_id', $q['id'])->countAllResults(), 'set tag diganti');
        $this->assertGreaterThan(0, $this->db->table('audit_logs')->where('entity', 'qna_has_tag')->countAllResults(), 'audit relasi');

        $s = $this->data($this->admin()->withBodyFormat('json')->patch(self::URL . "qna/{$q['id']}/status", ['status' => 1]));
        $this->assertSame([1, '<p>Baru</p>', [$t3]], [$s['status'], $s['jawaban'], array_column($s['tag'], 'id')]);

        $this->admin()->delete(self::URL . "qna/{$q['id']}")->assertStatus(200);
        $this->admin()->get(self::URL . "qna/{$q['id']}")->assertStatus(404);
        $this->assertSame(0, $this->data($this->admin()->get(self::URL . 'qna'))['total']);
    }

    public function testQnaValidasi(): void
    {
        $aktif    = $this->buatKategori('Aktif');
        $nonaktif = $this->buatKategori('Nonaktif', 2);
        $tagNon   = $this->buatTag('lama', 2);
        $dasar    = ['pertanyaan' => 'P?', 'jawaban' => '<p>J</p>', 'id_kategori' => $aktif, 'tag' => [], 'is_fallback_option' => 0];

        $e = $this->errors($this->admin()->withBodyFormat('json')->post(self::URL . 'qna', ['pertanyaan' => ' ', 'jawaban' => '<script>x</script>', 'tag' => []]));
        $this->assertSame(['pertanyaan' => 'Pertanyaan tidak boleh kosong!', 'jawaban' => 'Jawaban tidak boleh kosong!', 'id_kategori' => 'Kategori wajib dipilih!'], array_intersect_key($e, array_flip(['pertanyaan', 'jawaban', 'id_kategori'])));
        $this->assertArrayHasKey('id_kategori', $this->errors($this->admin()->withBodyFormat('json')->post(self::URL . 'qna', ['id_kategori' => $nonaktif] + $dasar)));
        $this->assertArrayHasKey('tag', $this->errors($this->admin()->withBodyFormat('json')->post(self::URL . 'qna', ['tag' => [$tagNon]] + $dasar)));
        $this->assertArrayHasKey('tag', $this->errors($this->admin()->withBodyFormat('json')->post(self::URL . 'qna', ['tag' => [999999]] + $dasar)));
        $this->assertSame(0, $this->db->table('qna')->countAllResults());
    }

    public function testStatusIndukQna(): void
    {
        $kat   = $this->buatKategori('Kategori');
        $tagA  = $this->buatTag('aktif');
        $tagN  = $this->buatTag('nonaktif', 2);
        $tagH  = $this->buatTag('dihapus', 10);
        $id    = $this->buatQna('Pertanyaan', $kat, [$tagA, $tagN, $tagH]);
        $dasar = ['pertanyaan' => 'Pertanyaan', 'jawaban' => '<p>J</p>', 'is_fallback_option' => 0];

        // Daftar/detail: tag status 2 berbadge (status 2), tag status 10 tidak tampil.
        $d = $this->data($this->admin()->get(self::URL . "qna/{$id}"));
        $this->assertSame([[$tagA, 1], [$tagN, 2]], array_map(static fn (array $t): array => [$t['id'], $t['status']], $d['tag']));

        // Ubah mempertahankan tag status 2 yang dikirim; tag status 10 tetap di DB.
        $this->db->table('qna_kategori')->where('id_qna_kategori', $kat)->update(['status_qna_kategori_fk' => 2]);
        $u = $this->data($this->admin()->withBodyFormat('json')->put(self::URL . "qna/{$id}", $dasar + ['id_kategori' => $kat, 'tag' => [$tagA, $tagN]]));
        $this->assertSame([$tagA, $tagN], array_column($u['tag'], 'id'), 'kategori lama status 2 boleh dipertahankan');
        $this->assertSame(3, $this->db->table('qna_has_tag')->where('qna_id', $id)->countAllResults());

        // Kategori lama status 10 tidak diganti → 422.
        $this->db->table('qna_kategori')->where('id_qna_kategori', $kat)->update(['status_qna_kategori_fk' => 10]);
        $d2 = $this->data($this->admin()->get(self::URL . 'qna'))['items'][0];
        $this->assertSame(10, $d2['kategori']['status'], 'penanda Dihapus');
        $e = $this->errors($this->admin()->withBodyFormat('json')->put(self::URL . "qna/{$id}", $dasar + ['id_kategori' => $kat, 'tag' => [$tagA]]));
        $this->assertSame('Kategori QnA sudah dihapus. Pilih kategori lain.', $e['id_kategori']);

        // Filter daftar.
        $lain = $this->buatKategori('Lain');
        $this->buatQna('Kedua', $lain, [], 2);
        $this->assertSame(['Kedua'], array_column($this->data($this->admin()->get(self::URL . "qna?kategori={$lain}&status=2"))['items'], 'pertanyaan'));
        $this->assertSame(['Pertanyaan'], array_column($this->data($this->admin()->get(self::URL . "qna?tag={$tagA}"))['items'], 'pertanyaan'));
        $this->assertSame(['Kedua'], array_column($this->data($this->admin()->get(self::URL . 'qna?q=kedu'))['items'], 'pertanyaan'));
        $this->admin()->get(self::URL . 'qna?kategori=abc')->assertStatus(422);
    }

    public function testOpsi(): void
    {
        $this->buatKategori('B');
        $this->buatKategori('A');
        $this->buatKategori('Non', 2);
        $this->buatTag('t');
        $this->buatTag('h', 10);

        $o = $this->data($this->admin()->get(self::URL . 'qna/opsi'));
        $this->assertSame(['A', 'B'], array_column($o['kategori'], 'judul'));
        $this->assertSame(['t'], array_column($o['tag'], 'judul'));
    }

    private function admin(): static
    {
        return $this->asRole(Role::SUPER_ADMIN);
    }

    /**
     * @return mixed
     */
    private function data(TestResponse $r, int $status = 200)
    {
        $r->assertStatus($status);

        return json_decode($r->getJSON(), true)['data'];
    }

    /**
     * @return array<string, string> pesan pertama per field
     */
    private function errors(TestResponse $r): array
    {
        $r->assertStatus(422);

        return array_map(static fn (array $m): string => (string) $m[0], json_decode($r->getJSON(), true)['errors'] ?? []);
    }

    /**
     * @return list<list<string>>
     */
    private function bacaXlsx(string $isi): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cbx');
        file_put_contents((string) $tmp, $isi);
        $r = new Xlsx();
        $r->setReadDataOnly(true);
        $sel = $r->load((string) $tmp)->getSheet(0)->toArray(null, false, false, false);
        @unlink((string) $tmp);

        return array_map(static fn (array $b): array => array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $b), $sel);
    }

    private function sheetXml(string $isi): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cbx');
        file_put_contents((string) $tmp, $isi);
        $zip = new \ZipArchive();
        $zip->open((string) $tmp);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink((string) $tmp);

        return $xml;
    }
}
