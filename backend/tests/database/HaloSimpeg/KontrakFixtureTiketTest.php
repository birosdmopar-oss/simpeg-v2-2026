<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\HaloSimpegFixtureTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * Kontrak beku MAKE-071 ↔ MAKE-073: bentuk respons tiket ASLI (kunci + tipe JSON, rekursif) sama dengan contoh sintetis
 * `tests/_support/HaloSimpeg/tiket-*.json` dan `galat-*.json` yang disalin FE (PRD Fase 8 v1.3 §6.1b).
 *
 * @internal
 */
final class KontrakFixtureTiketTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use HaloSimpegFixtureTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/halo-simpeg/';

    protected $seed = AuthSeeder::class;

    private string $akar = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangThrottlerHs();
        $this->akar = $this->pasangStorageHs();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        service('superglobals')->setFilesArray([]);
        self::hapusFolderHs($this->akar);
        Services::reset(true);
        parent::tearDown();
    }

    public function testBentukResponsTiketSamaDenganFixture(): void
    {
        $k       = $this->buatKategoriHs('Kepegawaian');
        $pemilik = $this->buatAkunUntuk($this->buatPegawai(['nama' => 'Pelapor Contoh']), Role::PEGAWAI);
        $admin   = (int) $this->db->table('pengguna')->where('username', AuthSeeder::nipForRole(Role::SUPER_ADMIN))->get()->getRow('id_pengguna');
        $petugas = $this->buatAkunUntuk($this->buatPegawai(['nama' => 'Petugas Contoh']), Role::ADMIN_SATKER);
        $this->buatPetugasHs($admin, [$k]);
        $this->buatPetugasHs($petugas, [$k]);

        // Tiket dengan lampiran + balasan petugas (pengingat) + balasan petugas lain.
        $tmp = $this->unggahHs('Surat.pdf', self::pdfHs());

        try {
            $buat = $this->sebagai($pemilik)->post(self::URL . 'tiket', ['id_question_category' => $k, 'subject' => 'Sisa cuti', 'msg' => 'Berapa sisa cuti saya?']);
        } finally {
            $this->lepasUnggahHs($tmp);
        }

        $this->cocokFixtureHs('tiket-buat', $buat, 201);
        $id = $this->json($buat)['data']['id'];
        $this->cocokFixtureHs('tiket-balas', $this->sebagai($admin)->post(self::URL . "tiket/{$id}/pesan", ['msg' => 'Sisa cuti 10 hari.', 'ingatkan_24_jam' => 1]), 201);
        $this->sebagai($petugas)->post(self::URL . "tiket/{$id}/pesan", ['msg' => 'Tambahan dari petugas lain.'])->assertStatus(201);
        $tanpaPesan = $this->buatTiketHs($k, $pemilik);

        $this->cocokFixtureHs('tiket-daftar-pemilik', $this->sebagai($pemilik)->get(self::URL . 'tiket'), 200);
        $this->cocokFixtureHs('tiket-daftar-petugas', $this->sebagai($petugas)->get(self::URL . 'tiket?peran=petugas'), 200);
        $this->cocokFixtureHs('tiket-daftar-role1', $this->sebagai($admin)->get(self::URL . 'tiket?peran=petugas'), 200);
        $this->cocokFixtureHs('tiket-detail-pemilik', $this->sebagai($pemilik)->get(self::URL . "tiket/{$id}"), 200);
        $this->cocokFixtureHs('tiket-detail-petugas', $this->sebagai($petugas)->get(self::URL . "tiket/{$id}"), 200);
        $this->cocokFixtureHs('tiket-detail-role1', $this->sebagai($admin)->get(self::URL . "tiket/{$id}"), 200);
        $this->cocokFixtureHs('tiket-ringkasan', $this->sebagai($admin)->get(self::URL . 'tiket/ringkasan'), 200);
        $this->cocokFixtureHs('baca', $this->sebagai($petugas)->post(self::URL . "tiket/{$id}/baca"), 200);

        // Tiket NULL (H8-16 (a1)) dan galat tiket.
        $null = $this->buatTiketHs(null, $pemilik);
        $this->buatPesanHs($null, $pemilik, 'Tiket lama tanpa kategori', '2024-01-01 01:00:00', null, 'Pelapor Contoh');
        $this->cocokFixtureHs('tiket-detail-tanpa-kategori', $this->sebagai($pemilik)->get(self::URL . "tiket/{$null}"), 200);
        $this->cocokFixtureHs('galat-422-tanpa-kategori', $this->sebagai($pemilik)->post(self::URL . "tiket/{$null}/pesan", ['msg' => 'x']), 422);
        $this->cocokFixtureHs('galat-403-bukan-pihak-berwenang', $this->sebagai($pemilik)->delete(self::URL . "tiket/{$null}"), 403);
        $this->cocokFixtureHs('galat-404', $this->sebagai($pemilik)->get(self::URL . 'tiket/999999'), 404);
        $tmp = $this->unggahHs('catatan.txt', 'teks');

        try {
            $galat = $this->sebagai($pemilik)->post(self::URL . 'tiket', ['id_question_category' => $k, 'subject' => '', 'msg' => '']);
        } finally {
            $this->lepasUnggahHs($tmp);
        }

        $this->cocokFixtureHs('galat-422-tiket', $galat, 422);
        $this->assertSame(['subject', 'msg', 'arsip'], array_keys($this->json($galat)['errors']));

        // Tutup oleh pemilik (ditutup_oleh_label = nama pemilik) lalu galat ditutup; hapus oleh petugas.
        $this->cocokFixtureHs('tiket-tutup', $this->sebagai($pemilik)->post(self::URL . "tiket/{$id}/tutup"), 200);
        $this->cocokFixtureHs('tiket-detail-ditutup', $this->sebagai($admin)->get(self::URL . "tiket/{$id}"), 200);
        $this->cocokFixtureHs('galat-422-ditutup', $this->sebagai($pemilik)->post(self::URL . "tiket/{$id}/pesan", ['msg' => 'lagi']), 422);
        $this->cocokFixtureHs('tiket-hapus', $this->sebagai($admin)->delete(self::URL . "tiket/{$id}"), 200);

        // Throttle pesan (kuota baru).
        $this->pasangThrottlerHs();

        for ($i = 0; $i < 20; $i++) {
            $this->sebagai($pemilik)->post(self::URL . "tiket/{$tanpaPesan}/pesan", ['msg' => "p{$i}"])->assertStatus(201);
        }

        $this->cocokFixtureHs('galat-429', $this->sebagai($pemilik)->post(self::URL . "tiket/{$tanpaPesan}/pesan", ['msg' => 'lagi']), 429);
    }

    private function sebagai(int $idPengguna): static
    {
        return $this->asUser((array) $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->get()->getRowArray());
    }

}
