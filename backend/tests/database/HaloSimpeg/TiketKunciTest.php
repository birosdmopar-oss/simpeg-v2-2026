<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg;

use App\Constants\Role;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\HaloSimpegFixtureTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-071 K3 #13 — balas, tutup, dan hapus tiket menunggu kunci baris tiket yang dipegang transaksi lain (FOR UPDATE), sehingga
 * cek boleh-balas/tutup/hapus tidak bisa dilangkahi balapan; tanpa baris tertulis selama menunggu, normal sesudah kunci lepas.
 * Pola `MasterHaloSimpegTest::testHapusDanNonaktifkanMenungguKunciKategori`.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class TiketKunciTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use HaloSimpegFixtureTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/halo-simpeg/';

    protected $seed = AuthSeeder::class;

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testBalasTutupHapusMenungguKunciTiket(): void
    {
        $this->pasangThrottlerHs();
        $k       = $this->buatKategoriHs('Kepegawaian');
        $pemilik = $this->buatAkunUntuk($this->buatPegawai(['nama' => 'Pelapor Kunci']), Role::PEGAWAI);
        $admin   = (int) $this->db->table('pengguna')->where('username', AuthSeeder::nipForRole(Role::SUPER_ADMIN))->get()->getRow('id_pengguna');
        $this->buatPetugasHs($admin, [$k]);
        $t = $this->buatTiketHs($k, $pemilik);
        $this->buatPesanHs($t, $pemilik, 'tanya', '2026-11-17 01:00:00');

        $lain = Database::connect('tests', false);
        $lain->transBegin();
        $lain->query('SELECT id_inbox FROM ' . $lain->escapeIdentifiers($lain->prefixTable('disiplin_ticket')) . ' WHERE id_inbox = ? FOR UPDATE', [$t]);
        $tunggu = (int) $this->db->query('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->getRow('t');
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
        $sidik = $this->sidik($t);

        try {
            $this->assertSame(1205, $this->kode(fn () => $this->sebagai($admin)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'jawab'])), 'balas menunggu kunci');
            $this->assertSame(1205, $this->kode(fn () => $this->sebagai($pemilik)->post(self::URL . "tiket/{$t}/tutup")), 'tutup menunggu kunci');
            $this->assertSame(1205, $this->kode(fn () => $this->sebagai($admin)->delete(self::URL . "tiket/{$t}")), 'hapus menunggu kunci');
            $this->assertSame($sidik, $this->sidik($t), 'tanpa baris tertulis selama menunggu');
        } finally {
            $lain->transRollback();
            $lain->close();
            $this->db->query('SET SESSION innodb_lock_wait_timeout = ' . $tunggu);
        }

        $this->sebagai($admin)->post(self::URL . "tiket/{$t}/pesan", ['msg' => 'jawab'])->assertStatus(201);
        $this->sebagai($pemilik)->post(self::URL . "tiket/{$t}/tutup")->assertStatus(200);
        $this->sebagai($admin)->delete(self::URL . "tiket/{$t}")->assertStatus(200);
    }

    private function kode(callable $aksi): ?int
    {
        try {
            $aksi();
        } catch (DatabaseException $e) {
            return (int) $e->getCode();
        }

        return null;
    }

    private function sidik(int $t): string
    {
        return sha1(json_encode([
            $this->db->table('disiplin_ticket')->where('id_inbox', $t)->get()->getResultArray(),
            $this->db->table('disiplin_ticket_msg')->where('id_inbox', $t)->get()->getResultArray(),
        ], JSON_THROW_ON_ERROR));
    }

    private function sebagai(int $idPengguna): static
    {
        return $this->asUser((array) $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->get()->getRowArray());
    }
}
