<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\AkunSistem;
use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\AkunSistemUjiTrait;

/**
 * DBV-027 T3 — akun sistem integrasi (`sistem.integrasi`, role 1 ikut legacy DBV-Q10) bukan akun manusia: login ditolak
 * walau sudah diaktifkan dan diberi password (defense in depth), tidak tampil di daftar Manajemen Akun, dan semua aksi
 * (lihat, edit, aktifkan, reset password, ubah level, hapus) ditolak 403 — juga untuk Super Admin.
 *
 * Akun dibuat lewat service command `integrasi:siapkan-akun-sistem` (Z-1 opsi c), lalu diubah ke bentuk terburuk (aktif +
 * ber-password, seolah dirusak langsung di DB) untuk membuktikan penjaga berbasis username.
 *
 * @internal
 */
final class AkunSistemTerkunciTest extends DatabaseTestCase
{
    use AkunSistemUjiTrait;
    use AuthTestTrait;
    use FeatureTestTrait;

    protected $seed = AuthSeeder::class;

    /** @var array<string, mixed> */
    private array $sistem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->sistem = $this->siapkanAkunSistem();
        $this->db->table('pengguna')->where('id_pengguna', $this->sistem['id_pengguna'])
            ->update(['status' => '1', 'password' => password_hash(AuthSeeder::PASSWORD, PASSWORD_ARGON2ID)]);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        $this->bersihkanDataDbv010();
        parent::tearDown();
    }

    public function testLoginDitolakWalauAktifDanBerPassword(): void
    {
        $this->login(AkunSistem::USERNAME, AuthSeeder::PASSWORD)->assertStatus(401);
        $this->assertNull($this->db->table('pengguna')->where('id_pengguna', $this->sistem['id_pengguna'])->get()->getRow()->last_login_at);
    }

    public function testTersembunyiDariDaftarDanSemuaAksiDitolak(): void
    {
        $admin = $this->buatAkunTanpaNip('admin.uji.t3');
        $id    = (int) $this->sistem['id_pengguna'];

        $daftar = $this->json($this->asUser($admin)->get('api/v1/auth/users?per_page=100'));
        $this->assertNotContains(AkunSistem::USERNAME, array_column($daftar['data']['items'], 'username'));
        $cari = $this->json($this->asUser($admin)->get('api/v1/auth/users?search=sistem'));
        $this->assertSame(0, $cari['data']['total']);

        $this->asUser($admin)->get("api/v1/auth/users/{$id}")->assertStatus(403);

        foreach ([['name' => 'Ubah'], ['password' => 'PasswordBaru2026'], ['user_level' => Role::PEGAWAI], ['status' => '1']] as $body) {
            $this->asUser($admin)->withBodyFormat('json')->put("api/v1/auth/users/{$id}", $body)->assertStatus(403);
        }

        $this->asUser($admin)->withBodyFormat('json')->patch("api/v1/auth/users/{$id}/status", ['status' => '0'])->assertStatus(403);
        $this->asUser($admin)->delete("api/v1/auth/users/{$id}")->assertStatus(403);

        $baris = $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray();
        $this->assertSame(['Sistem Integrasi', (string) Role::SUPER_ADMIN, null], [$baris['name'], (string) $baris['user_level'], $baris['deleted_at']]);
    }
}
