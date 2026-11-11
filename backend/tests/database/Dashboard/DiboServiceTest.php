<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Dashboard\Konten\Dibo\DiboService;
use App\Libraries\Dashboard\Konten\Dibo\PenyimpananDibo;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Konten;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F-12 DIBO (MAKE-082; PRD Fase 7 v2.0 §5.6, Q7-11..14; legacy `L_dibo.php`): CRUD role 1 dengan berkas 1–3 privat, hapus lunak
 * [V2], baca semua role HANYA status aktif (perbaikan anomali legacy), filter jenis/tahun/cari, unduh terautentikasi.
 *
 *
 * @internal
 */
final class DiboServiceTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private string $akar;

    /** @var list<string> */
    private array $tmp = [];

    private int $jenisA;

    private int $jenisB;

    private AuthContext $admin;

    private AuthContext $pegawai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->akar = sys_get_temp_dir() . '/dibo_uji_' . bin2hex(random_bytes(4));

        $satker        = $this->buatSatker($this->buatUnit());
        $this->admin   = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($satker), Role::SUPER_ADMIN));
        $this->pegawai = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($satker), Role::PEGAWAI));

        $this->jenisA = $this->buatJenis('Peraturan', 1);
        $this->jenisB = $this->buatJenis('Surat Edaran', 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }

        foreach (glob($this->akar . '/*/*') ?: [] as $f) {
            @unlink($f);
        }

        foreach (glob($this->akar . '/*') ?: [] as $d) {
            @rmdir($d);
        }

        @rmdir($this->akar);
        parent::tearDown();
    }

    public function testBuatDenganTigaBerkasDanBaca(): void
    {
        $svc = $this->service();
        $r   = $svc->buat($this->admin, $this->input(), [1 => $this->pdf(), 2 => $this->pdf('b'), 3 => $this->pdf('c')]);

        $this->assertSame([1, 2, 3], $r['berkas']);
        $this->assertSame(1, $r['status']);
        $this->assertSame('Peraturan', $r['jenis']);

        $baca = $svc->bacaBerkas($this->pegawai, $r['id'], 2);
        $this->assertSame('application/pdf', $baca['mime']);
        $this->assertStringStartsWith('%PDF', $baca['isi']);

        $k = (string) $this->db->table('dibo')->where('id_dibo', $r['id'])->get()->getRow()->file_1;
        $this->assertMatchesRegularExpression('#^dibo/[0-9a-f]{32}\.pdf$#', $k);
        $this->assertFileExists($this->akar . '/' . $k);
    }

    public function testNomorMaksimal50Karakter(): void
    {
        $svc = $this->service();

        try {
            $svc->buat($this->admin, $this->input(['nomor' => str_repeat('N', 51)]), [1 => $this->pdf()]);
            $this->fail('Nomor 51 karakter harus ditolak (VARCHAR(50), DBV-032)');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nomor', $e->getErrors() ?? []);
        }

        $this->assertSame(str_repeat('N', 50), $svc->buat($this->admin, $this->input(['nomor' => str_repeat('N', 50)]), [1 => $this->pdf()])['nomor']);
    }

    public function testBarisLegacyJenisNomorTahunNullTetapTerbaca(): void
    {
        $svc = $this->service();
        $r   = $svc->buat($this->admin, $this->input(), [1 => $this->pdf()]);
        $this->db->table('dibo')->where('id_dibo', $r['id'])->update(['id_jenis_dibo' => null, 'nomor' => null, 'tahun' => null]);

        $baca = $svc->baca([])['items'][0];
        $this->assertSame(['', null, ''], [$baca['nomor'], $baca['tahun'], $baca['jenis']]);
        $kelola = $svc->kelola($this->admin, [])['items'][0];
        $this->assertNull($kelola['id_jenis']);
    }

    public function testValidasiDanFile1Wajib(): void
    {
        $svc = $this->service();

        foreach ([
            'id_jenis' => [['id_jenis' => $this->jenisB], [1 => $this->pdf()]],   // jenis nonaktif
            'nomor'    => [['nomor' => ' '], [1 => $this->pdf()]],
            'tahun'    => [['tahun' => '26'], [1 => $this->pdf()]],
            'tentang'  => [['tentang' => ''], [1 => $this->pdf()]],
            'file_1'   => [[], []],
        ] as $field => [$ubah, $file]) {
            try {
                $svc->buat($this->admin, $this->input($ubah), $file);
                $this->fail("Harus 422: {$field}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->getErrors() ?? [], $field);
            }
        }

        $this->assertSame(0, $this->db->table('dibo')->countAllResults());
    }

    public function testJenisBerkasDiperiksaDanTidakAdaBerkasYatim(): void
    {
        $svc = $this->service();

        try {
            $svc->buat($this->admin, $this->input(), [1 => $this->pdf(), 2 => $this->berkas('<?php echo 1;', 'php')]);
            $this->fail('Harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file_2', $e->getErrors() ?? []);
        }

        $this->assertSame(0, $this->db->table('dibo')->countAllResults());
        $this->assertSame([], glob($this->akar . '/dibo/*') ?: [], 'berkas 1 tidak disimpan bila berkas 2 ditolak');
    }

    public function testBacaHanyaStatusAktifDenganFilter(): void
    {
        $svc = $this->service();
        $a   = $svc->buat($this->admin, $this->input(['nomor' => 'PER-1', 'tahun' => '2025', 'tentang' => 'Cuti tahunan']), [1 => $this->pdf()]);
        $b   = $svc->buat($this->admin, $this->input(['nomor' => 'PER-2', 'tahun' => '2026', 'tentang' => 'Tunjangan kinerja']), [1 => $this->pdf()]);
        $c   = $svc->buat($this->admin, $this->input(['nomor' => 'PER-3', 'tahun' => '2026', 'tentang' => 'Dicabut']), [1 => $this->pdf()]);
        $d   = $svc->buat($this->admin, $this->input(['nomor' => 'PER-4', 'tahun' => '2026', 'tentang' => 'Dihapus']), [1 => $this->pdf()]);

        $svc->setStatus($this->admin, $c['id'], '2');
        $svc->hapus($this->admin, $d['id']);

        $nomor = static fn (array $h): array => array_column($h['items'], 'nomor');

        $this->assertSame(['PER-2', 'PER-1'], $nomor($svc->baca([])), 'hanya aktif, tahun terbaru dulu');
        $this->assertSame(['PER-2'], $nomor($svc->baca(['tahun' => '2026'])));
        $this->assertSame(['PER-1'], $nomor($svc->baca(['q' => 'cuti'])));
        $this->assertSame(['PER-2'], $nomor($svc->baca(['q' => 'PER-2'])));
        $this->assertSame([], $nomor($svc->baca(['jenis' => (string) $this->jenisB])));
        $this->assertSame(0, $svc->baca(['q' => '%'])['total'], 'wildcard dicari sebagai karakter biasa');
        $this->assertSame(2, $svc->baca([])['total']);

        // berkas non-aktif tidak bisa diunduh non-admin; admin bisa
        $this->assertSame('application/pdf', $svc->bacaBerkas($this->admin, $c['id'], 1)['mime']);

        foreach ([$c['id'], $d['id']] as $id) {
            try {
                $svc->bacaBerkas($this->pegawai, $id, 1);
                $this->fail('Harus 404');
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($a['id'] + 1, $b['id']);
    }

    public function testKelolaHanyaRole1DenganFilterStatus(): void
    {
        $svc = $this->service();
        $a   = $svc->buat($this->admin, $this->input(['nomor' => 'A']), [1 => $this->pdf()]);
        $b   = $svc->buat($this->admin, $this->input(['nomor' => 'B']), [1 => $this->pdf()]);
        $svc->hapus($this->admin, $b['id']);

        $this->assertSame([$a['id']], array_column($svc->kelola($this->admin, [])['items'], 'id'), 'bawaan tanpa status 10');
        $this->assertSame([$b['id']], array_column($svc->kelola($this->admin, ['status' => '10'])['items'], 'id'));
        $this->assertArrayHasKey('status', $svc->kelola($this->admin, [])['items'][0]);

        $svc->setStatus($this->admin, $b['id'], '1'); // pulihkan
        $this->assertSame(2, $svc->kelola($this->admin, [])['total']);

        $this->expectException(ApiException::class);
        $svc->kelola($this->pegawai, []);
    }

    public function testUbahMempertahankanMenggantiDanMenghapusBerkas(): void
    {
        $svc  = $this->service();
        $r    = $svc->buat($this->admin, $this->input(), [1 => $this->pdf(), 2 => $this->pdf('b'), 3 => $this->pdf('c')]);
        $row  = fn (): array => (array) $this->db->table('dibo')->where('id_dibo', $r['id'])->get()->getRowArray();
        $lama = $row();

        // tanpa berkas baru: semua dipertahankan
        $svc->ubah($this->admin, $r['id'], $this->input(['tentang' => 'Baru']), []);
        $this->assertSame([$lama['file_1'], $lama['file_2'], $lama['file_3']], [$row()['file_1'], $row()['file_2'], $row()['file_3']]);

        // ganti berkas 1, hapus berkas 3 → berkas lama dihapus dari penyimpanan setelah commit
        $u    = $svc->ubah($this->admin, $r['id'], $this->input(['hapus_file_3' => '1']), [1 => $this->pdf('baru')]);
        $baru = $row();

        $this->assertSame([1, 2], $u['berkas']);
        $this->assertNotSame($lama['file_1'], $baru['file_1']);
        $this->assertNull($baru['file_3']);
        $this->assertFileDoesNotExist($this->akar . '/' . $lama['file_1']);
        $this->assertFileDoesNotExist($this->akar . '/' . $lama['file_3']);
        $this->assertFileExists($this->akar . '/' . $lama['file_2']);
        $this->assertFileExists($this->akar . '/' . $baru['file_1']);
    }

    public function testHapusLunakMempertahankanBerkasDanTidakBisaDiubah(): void
    {
        $svc = $this->service();
        $r   = $svc->buat($this->admin, $this->input(), [1 => $this->pdf()]);
        $k   = (string) $this->db->table('dibo')->where('id_dibo', $r['id'])->get()->getRow()->file_1;

        $svc->hapus($this->admin, $r['id']);

        $this->assertFileExists($this->akar . '/' . $k, 'berkas dipertahankan agar bisa dipulihkan');
        $this->assertSame(10, (int) $this->db->table('dibo')->where('id_dibo', $r['id'])->get()->getRow()->status);

        $audit = $this->db->table('audit_logs')->where(['entity' => 'dibo', 'entity_id' => (string) $r['id']])->countAllResults();
        $this->assertGreaterThanOrEqual(2, $audit, 'create + update (status) tercatat (Q7-16)');

        $this->expectException(ApiException::class);
        $svc->ubah($this->admin, $r['id'], $this->input(), []);
    }

    public function testTulisHanyaRole1DanStatusValid(): void
    {
        $svc = $this->service();
        $r   = $svc->buat($this->admin, $this->input(), [1 => $this->pdf()]);

        foreach ([
            fn () => $svc->buat($this->pegawai, $this->input(), [1 => $this->pdf()]),
            fn () => $svc->ubah($this->pegawai, $r['id'], $this->input(), []),
            fn () => $svc->setStatus($this->pegawai, $r['id'], '2'),
            fn () => $svc->hapus($this->pegawai, $r['id']),
        ] as $aksi) {
            try {
                $aksi();
                $this->fail('Harus 403');
            } catch (ApiException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $this->expectException(ValidationException::class);
        $svc->setStatus($this->admin, $r['id'], '10');
    }

    public function testIndeksBerkasDiLuarSatuSampaiTigaMenghasilkan404(): void
    {
        $svc = $this->service();
        $r   = $svc->buat($this->admin, $this->input(), [1 => $this->pdf()]);

        foreach ([0, 4, -1, 99, PHP_INT_MAX] as $no) {
            try {
                $svc->bacaBerkas($this->admin, $r['id'], $no);
                $this->fail("Harus 404 untuk indeks {$no}");
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        // indeks sah tetapi berkas tidak ada → 404 juga
        $this->expectException(NotFoundException::class);
        $svc->bacaBerkas($this->admin, $r['id'], 3);
    }

    public function testKunciBerkasRusakTidakBisaMenembusPath(): void
    {
        $svc = $this->service();
        $r   = $svc->buat($this->admin, $this->input(), [1 => $this->pdf()]);

        // berkas "rahasia" DI LUAR akar penyimpanan
        $luar = dirname($this->akar) . '/luar_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($luar, "%PDF-1.4\nRAHASIA\n%%EOF\n");
        $this->tmp[] = $luar;
        $nama        = basename($luar);

        foreach (['../' . basename($this->akar) . '/../' . $nama, '../' . $nama, 'dibo/../../' . $nama, '/etc/passwd', 'C:/Windows/win.ini', "dibo/x.pdf\0.png", $luar] as $jahat) {
            $this->db->table('dibo')->where('id_dibo', $r['id'])->update(['file_1' => $jahat]);

            try {
                $hasil = $svc->bacaBerkas($this->admin, $r['id'], 1);
                $this->fail('Kunci "' . addcslashes($jahat, "\0") . '" tidak boleh terbaca: ' . substr($hasil['isi'], 0, 20));
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }

            // Peringatan hanya memuat jenis + id baris, TIDAK memuat isi kunci/path.
            $this->assertLogged('warning', 'Kunci berkas dibo tidak valid (data diubah?): id=' . $r['id']);
        }
    }

    public function testNamaUnduhanTidakPernahMemuatEkstensiBerbahaya(): void
    {
        $svc  = $this->service();
        $r    = $svc->buat($this->admin, $this->input(), [1 => $this->pdf()]);
        $baca = $svc->bacaBerkas($this->admin, $r['id'], 1);

        $this->assertMatchesRegularExpression('/^dibo-' . $r['id'] . '-1\.pdf\z/', $baca['nama']);
        $this->assertDoesNotMatchRegularExpression('/[\r\n";\\\\\/ ]/', $baca['nama']);
    }

    /**
     * @dataProvider provBerkasPalsu
     */
    public function testBerkasPalsuDitolak(string $isi, string $ekstensi): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->buat($this->admin, $this->input(), [1 => $this->berkas($isi, $ekstensi)]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provBerkasPalsu(): iterable
    {
        yield 'PHP bernama .pdf' => ["<?php system('id');", 'pdf'];
        yield 'HTML+script bernama .pdf' => ['<html><script>alert(1)</script></html>', 'pdf'];
        yield 'HTML bernama .docx' => ['<html><body>x</body></html>', 'docx'];
        yield 'PDF bernama .xlsx (isi != ekstensi)' => ["%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n", 'xlsx'];
        yield 'ZIP polos bernama .docx' => ["PK\x03\x04" . str_repeat("\0", 64), 'docx'];
        yield 'teks bernama .doc' => ['hanya teks biasa', 'doc'];
        yield 'ekstensi ganda .php.pdf berisi PHP' => ['<?php echo 1;', 'php.pdf'];
        yield 'ekstensi terlarang .php' => ["%PDF-1.4\n%%EOF\n", 'php'];
        yield 'ekstensi terlarang .html' => ["%PDF-1.4\n%%EOF\n", 'html'];
        yield 'berkas kosong' => ['', 'pdf'];
    }

    // ------------------------------------------------------------------

    private function service(): DiboService
    {
        $c                  = new Konten();
        $c->akarPenyimpanan = $this->akar;

        return new DiboService($this->db, new PenyimpananDibo($c));
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    private function input(array $o = []): array
    {
        return $o + ['id_jenis' => $this->jenisA, 'nomor' => 'PER-9/2026', 'tahun' => '2026', 'tentang' => 'Uji', 'keterangan' => ''];
    }

    private function buatJenis(string $nama, int $status): int
    {
        $this->db->table('jenis_dibo')->insert(['jenis_dibo' => $nama, 'order' => $status, 'status' => $status]);

        return (int) $this->db->insertID();
    }

    private function pdf(string $penanda = 'a'): UploadedFile
    {
        return $this->berkas("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% {$penanda}\ntrailer<</Root 1 0 R>>\n%%EOF\n", 'pdf');
    }

    private function berkas(string $isi, string $ext): UploadedFile
    {
        $f = tempnam(sys_get_temp_dir(), 'dbo') . '.' . $ext;
        file_put_contents($f, $isi);
        $this->tmp[] = $f;

        return new UploadedFile($f, 'berkas.' . $ext, null, (int) filesize($f), UPLOAD_ERR_OK);
    }
}
