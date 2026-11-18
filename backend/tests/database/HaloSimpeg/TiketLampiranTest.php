<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg;

use App\Constants\Role;
use App\Libraries\HaloSimpeg\TiketService;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Arsip;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\HaloSimpegFixtureTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-071 — lampiran tiket (F8-17, K-A6, AC-E03.5/E03.6, AC-E04.7, AC-E05.5/E05.7, Q-A0-6 (a')). Storage di folder sementara.
 * Rekonsiliasi `PenjagaBerkas` hanya berjalan tanpa transaksi terbuka, jadi test hapus/rollback memakai `db-isolasi-penuh`.
 *
 * @internal
 */
final class TiketLampiranTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use HaloSimpegFixtureTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/halo-simpeg/';

    protected $seed = AuthSeeder::class;

    private string $akar = '';

    private int $pemilik;

    private int $petugas;

    private int $lain;

    private int $kategori;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangThrottlerHs();
        $this->akar     = $this->pasangStorageHs();
        $this->kategori = $this->buatKategoriHs('Kepegawaian');
        $this->pemilik  = $this->buatAkunUntuk($this->buatPegawai(['nama' => 'Pelapor Lampiran']), Role::PEGAWAI);
        $this->lain     = $this->buatAkunUntuk($this->buatPegawai(['nama' => 'Orang Lain']), Role::PEGAWAI);
        $this->petugas  = (int) $this->db->table('pengguna')->where('username', AuthSeeder::nipForRole(Role::SUPER_ADMIN))->get()->getRow('id_pengguna');
        $this->buatPetugasHs($this->petugas, [$this->kategori]);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        service('superglobals')->setFilesArray([]);
        self::hapusFolderHs($this->akar);
        Services::reset(true);
        parent::tearDown();
    }

    public function testUnggahBaruKunciK6DanUnduhAmanHanyaPihakBerhak(): void
    {
        $tmp = $this->unggahHs('Surat Tugas.pdf', self::pdfHs());

        try {
            $res = $this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->kategori, 'subject' => 'Lampiran', 'msg' => 'Ada berkas']);
        } finally {
            $this->lepasUnggahHs($tmp);
        }

        $res->assertStatus(201);
        $d     = $this->json($res)['data'];
        $pesan = $d['pesan'][0];
        $this->assertSame(['nama' => "lampiran-{$d['id']}-{$pesan['id_inbox_msg']}.pdf", 'tipe' => 'application/pdf', 'ukuran' => null], $pesan['lampiran']);
        $this->assertSame(['nama' => "lampiran-{$d['id']}-{$pesan['id_inbox_msg']}.pdf", 'tipe' => 'application/pdf'], $this->json($this->sebagai($this->pemilik)->get(self::URL . 'tiket'))['data']['items'][0]['pesan_terakhir']['lampiran']);

        $path = (string) $this->db->table('disiplin_ticket_msg')->where('id_inbox_msg', $pesan['id_inbox_msg'])->get()->getRow('attachments');
        $this->assertMatchesRegularExpression('#^halo-simpeg/\d{4}/\d{2}/[0-9a-f]{32}\.pdf$#', $path, 'K-A6: tanpa NIP/nama asli');
        $this->assertStringNotContainsString($path, (string) $this->sebagai($this->pemilik)->get(self::URL . "tiket/{$d['id']}")->getJSON(), 'path tidak pernah dikirim');

        foreach ([$this->pemilik, $this->petugas] as $akun) {
            $unduh = $this->sebagai($akun)->get(self::URL . "tiket/{$d['id']}/pesan/{$pesan['id_inbox_msg']}/lampiran");
            $unduh->assertStatus(200);
            $r = $unduh->response();
            $this->assertSame(self::pdfHs(), (string) $r->getBody());
            $this->assertStringStartsWith('application/pdf', $r->getHeaderLine('Content-Type'));
            $this->assertSame("inline; filename=\"lampiran-{$d['id']}-{$pesan['id_inbox_msg']}.pdf\"", $r->getHeaderLine('Content-Disposition'));
            $this->assertSame(['nosniff', Arsip::CSP_BERKAS], [$r->getHeaderLine('X-Content-Type-Options'), $r->getHeaderLine('Content-Security-Policy')]);
            $this->assertStringContainsString('private', $r->getHeaderLine('Cache-Control'));
            $this->assertStringContainsString('no-store', $r->getHeaderLine('Cache-Control'));
        }

        $this->sebagai($this->lain)->get(self::URL . "tiket/{$d['id']}/pesan/{$pesan['id_inbox_msg']}/lampiran")->assertStatus(404);

        // IDOR lintas tiket: id pesan milik tiket lain lewat tiket sendiri → 404 lampiran.
        $milikLain = $this->buatTiketHs($this->kategori, $this->lain);
        $this->harap404Lampiran($this->sebagai($this->lain)->get(self::URL . "tiket/{$milikLain}/pesan/{$pesan['id_inbox_msg']}/lampiran"));
        $this->sebagai($this->pemilik)->get(self::URL . "tiket/{$d['id']}/pesan/999999/lampiran")->assertStatus(404);
    }

    public function testLampiranLamaPathLegacyDibacaLewatEndpoint(): void
    {
        $legacy = 'arsip/000000000000000056/inbox/lampiran_1_20240101101010.xlsx';
        mkdir($this->akar . '/arsip/000000000000000056/inbox', 0700, true);
        file_put_contents($this->akar . '/' . $legacy, 'isi lama');
        $t = $this->buatTiketHs($this->kategori, $this->pemilik);
        $m = $this->buatPesanHs($t, $this->pemilik, 'lama', '2024-01-01 03:10:10', $legacy);

        // AC-E04.7: berlaku pada saklar penyajian berkas legacy `publik` maupun `privat` (F8-17).
        $arsip  = config(Arsip::class);
        $semula = $arsip->penyajianBerkasLegacy;

        try {
            foreach (['publik', 'privat'] as $saklar) {
                $arsip->penyajianBerkasLegacy = $saklar;
                $res                          = $this->sebagai($this->petugas)->get(self::URL . "tiket/{$t}/pesan/{$m}/lampiran");
                $res->assertStatus(200);
                $this->assertSame('isi lama', (string) $res->response()->getBody(), $saklar);
                $this->assertSame("attachment; filename=\"lampiran-{$t}-{$m}.xlsx\"", $res->response()->getHeaderLine('Content-Disposition'));
            }
        } finally {
            $arsip->penyajianBerkasLegacy = $semula;
        }

        // Ekstensi di luar daftar → octet-stream + attachment; tanpa ekstensi → nama tanpa akhiran.
        foreach (['arsip/000000000000000056/inbox/lama.txt' => ['application/octet-stream', "lampiran-{$t}-%d.txt"], 'arsip/000000000000000056/inbox/tanpaekstensi' => ['application/octet-stream', "lampiran-{$t}-%d"]] as $p => [$mime, $nama]) {
            file_put_contents($this->akar . '/' . $p, 'isi');
            $id  = $this->buatPesanHs($t, $this->pemilik, 'x', '2024-01-02 00:00:00', $p);
            $res = $this->sebagai($this->pemilik)->get(self::URL . "tiket/{$t}/pesan/{$id}/lampiran");
            $res->assertStatus(200);
            $this->assertStringStartsWith($mime, $res->response()->getHeaderLine('Content-Type'));
            $this->assertSame('attachment; filename="' . sprintf($nama, $id) . '"', $res->response()->getHeaderLine('Content-Disposition'));
        }

        $hilang = $this->buatPesanHs($t, $this->pemilik, 'hilang', '2024-01-02 00:00:00', 'arsip/x/inbox/tidak-ada.pdf');
        $this->harap404Lampiran($this->sebagai($this->pemilik)->get(self::URL . "tiket/{$t}/pesan/{$hilang}/lampiran"));
        $rusak = $this->buatPesanHs($t, $this->pemilik, 'rusak', '2024-01-03 00:00:00', '../../etc/passwd');
        $this->harap404Lampiran($this->sebagai($this->pemilik)->get(self::URL . "tiket/{$t}/pesan/{$rusak}/lampiran"));
    }

    public function testThrottleBuatDenganUnggahanTanpaBerkas(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->kategori, 'subject' => "S{$i}", 'msg' => 'M'])->assertStatus(201);
        }

        $tmp = $this->unggahHs('Surat.pdf', self::pdfHs());

        try {
            $this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->kategori, 'subject' => 'S6', 'msg' => 'M'])->assertStatus(429);
        } finally {
            $this->lepasUnggahHs($tmp);
        }

        $this->assertSame([], glob($this->akar . '/halo-simpeg/*/*/*') ?: [], 'throttle dicek sebelum unggah');
    }

    public function testUnggahDitolakTanpaBarisDanTanpaBerkas(): void
    {
        foreach ([['catatan.txt', 'teks biasa'], ['palsu.pdf', 'bukan pdf'], ['besar.pdf', self::pdfHs() . str_repeat('x', 5 * 1024 * 1024)]] as [$nama, $isi]) {
            $this->pasangThrottlerHs();
            $tmp = $this->unggahHs($nama, $isi);

            try {
                $res = $this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->kategori, 'subject' => 'S', 'msg' => 'M']);
            } finally {
                $this->lepasUnggahHs($tmp);
            }

            $res->assertStatus(422);
            $this->assertArrayHasKey('arsip', $this->json($res)['errors'], $nama);
        }

        $this->assertSame(0, $this->db->table('disiplin_ticket')->countAllResults());
        $this->assertSame([], glob($this->akar . '/halo-simpeg/*/*/*') ?: []);
    }

    /**
     * AC-E03.6: gagal di tengah transaksi (sisip pesan ditolak trigger) → tidak ada tiket, pesan, atau berkas sisa.
     */
    #[Group('db-isolasi-penuh')]
    public function testGagalDiTengahTransaksiTanpaSisa(): void
    {
        $trg = $this->db->prefixTable('trg_uji_m071_msg');
        $this->db->query("DROP TRIGGER IF EXISTS {$trg}");
        $this->db->query("CREATE TRIGGER {$trg} BEFORE INSERT ON " . $this->db->prefixTable('disiplin_ticket_msg') . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gagal uji'");
        $tmp = $this->unggahHs('Surat.pdf', self::pdfHs());

        try {
            $gagal = false;

            try {
                $this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->kategori, 'subject' => 'Gagal', 'msg' => 'M']);
            } catch (\CodeIgniter\Database\Exceptions\DatabaseException) {
                $gagal = true;
            }

            $this->assertTrue($gagal);
        } finally {
            $this->lepasUnggahHs($tmp);
            $this->db->query("DROP TRIGGER IF EXISTS {$trg}");
        }

        $this->assertSame([0, 0], [$this->db->table('disiplin_ticket')->countAllResults(), $this->db->table('disiplin_ticket_msg')->countAllResults()]);
        $this->assertSame([], glob($this->akar . '/halo-simpeg/*/*/*') ?: [], 'berkas yang sempat disimpan dihapus PenjagaBerkas');
    }

    /**
     * Q-A0-6 (a'): hapus tiket menghapus berkas baru miliknya; path legacy yang dirujuk tiket lain dipertahankan; path berspasi
     * (ditolak aturan tulis adapter) tidak menggagalkan hapus dan berkasnya tertinggal.
     */
    #[Group('db-isolasi-penuh')]
    public function testHapusTiketBerkasBersamaDanPathBerspasi(): void
    {
        $bersama = 'arsip/000000000000000056/inbox/lampiran_1_20240101101010.pdf';
        $sendiri = 'arsip/000000000000000056/inbox/lampiran_1_20240101101011.pdf';
        $spasi   = 'arsip/000000000000000056/inbox/Surat Tugas (1).pdf';
        $baru    = 'halo-simpeg/2026/11/' . str_repeat('a', 32) . '.pdf';

        foreach ([$bersama, $sendiri, $spasi, $baru] as $p) {
            @mkdir(dirname($this->akar . '/' . $p), 0700, true);
            file_put_contents($this->akar . '/' . $p, self::pdfHs());
        }

        $t1 = $this->buatTiketHs($this->kategori, $this->pemilik);
        $t2 = $this->buatTiketHs($this->kategori, $this->pemilik);
        $this->buatPesanHs($t1, $this->pemilik, 'a', '2024-01-01 00:00:00', $bersama);
        $this->buatPesanHs($t1, $this->pemilik, 'b', '2024-01-01 00:01:00', $spasi);
        $this->buatPesanHs($t1, $this->petugas, 'c', '2024-01-01 00:02:00', $baru);
        $this->buatPesanHs($t1, $this->pemilik, 'e', '2024-01-01 00:04:00', $sendiri);
        $m2 = $this->buatPesanHs($t2, $this->pemilik, 'd', '2024-01-01 00:03:00', $bersama);

        $this->sebagai($this->petugas)->delete(self::URL . "tiket/{$t1}")->assertStatus(200);

        $this->assertFileDoesNotExist($this->akar . '/' . $baru, 'berkas baru milik tiket dihapus');
        $this->assertFileDoesNotExist($this->akar . '/' . $sendiri, 'path legacy sah yang hanya dirujuk tiket ini ikut dihapus');
        $this->assertFileExists($this->akar . '/' . $bersama, 'masih dirujuk tiket kedua');
        $this->assertFileExists($this->akar . '/' . $spasi, 'path legacy berspasi tidak dihapus fisik (dicatat tanpa path)');
        $this->sebagai($this->pemilik)->get(self::URL . "tiket/{$t2}/pesan/{$m2}/lampiran")->assertStatus(200);
    }

    private function harap404Lampiran(TestResponse $res): void
    {
        $res->assertStatus(404);
        $this->assertSame(TiketService::PESAN_LAMPIRAN_404, $this->json($res)['message']);
    }

    private function sebagai(int $idPengguna): static
    {
        return $this->asUser((array) $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->get()->getRowArray());
    }
}
