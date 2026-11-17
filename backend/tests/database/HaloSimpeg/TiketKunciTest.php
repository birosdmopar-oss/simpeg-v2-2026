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

        $tunggu = (int) $this->db->query('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->getRow('t');
        $lain   = Database::connect('tests', false);

        try {
            $lain->transBegin();
            $lain->query('SELECT id_inbox FROM ' . $lain->escapeIdentifiers($lain->prefixTable('disiplin_ticket')) . ' WHERE id_inbox = ? FOR UPDATE', [$t]);
            $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
            $sidik = $this->sidik($t);
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

    /**
     * L1 (a), K2: buat tiket menunggu kunci kategori (LOCK IN SHARE MODE) yang dipegang FOR UPDATE transaksi lain — 1205 tanpa
     * tiket/pesan tertulis dan tanpa sisa berkas unggahan; sesudah kunci lepas → 201.
     */
    public function testBuatTiketMenungguKunciKategori(): void
    {
        $this->pasangThrottlerHs();
        $akar    = $this->pasangStorageHs();
        $k       = $this->buatKategoriHs('Kepegawaian');
        $pemilik = $this->buatAkunUntuk($this->buatPegawai(['nama' => 'Pelapor Kunci']), Role::PEGAWAI);
        $tunggu  = (int) $this->db->query('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->getRow('t');
        $lain    = Database::connect('tests', false);

        try {
            $lain->transBegin();
            $lain->query('SELECT id_question_category FROM ' . $lain->escapeIdentifiers($lain->prefixTable('question_category')) . ' WHERE id_question_category = ? FOR UPDATE', [$k]);
            $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
            $tmp = $this->unggahHs('Surat.pdf', self::pdfHs());

            try {
                $this->assertSame(1205, $this->kode(fn () => $this->sebagai($pemilik)->post(self::URL . 'tiket', ['id_question_category' => $k, 'subject' => 'S', 'msg' => 'M'])));
            } finally {
                $this->lepasUnggahHs($tmp);
            }

            $this->assertSame([0, 0], [$this->db->table('disiplin_ticket')->countAllResults(), $this->db->table('disiplin_ticket_msg')->countAllResults()]);
            $this->assertSame([], glob($akar . '/halo-simpeg/*/*/*') ?: [], 'berkas unggahan dikompensasi PenjagaBerkas');
        } finally {
            $lain->transRollback();
            $lain->close();
            $this->db->query('SET SESSION innodb_lock_wait_timeout = ' . $tunggu);
        }

        try {
            $this->sebagai($pemilik)->post(self::URL . 'tiket', ['id_question_category' => $k, 'subject' => 'S', 'msg' => 'M'])->assertStatus(201);
        } finally {
            self::hapusFolderHs($akar);
        }
    }

    /**
     * L1 (b), K2: FK 1452 saat INSERT tiket (kategori lenyap di sela cek — ditiru trigger SIGNAL) melewati callback galat DB
     * `TiketService` → 422 `errors.id_question_category` "Kategori tidak tersedia.", tanpa baris tertulis.
     */
    public function testGalatFk1452BuatTiketDiterjemahkan422(): void
    {
        $this->pasangThrottlerHs();
        $k       = $this->buatKategoriHs('Kepegawaian');
        $pemilik = $this->buatAkunUntuk($this->buatPegawai(['nama' => 'Pelapor Fk']), Role::PEGAWAI);
        $trg     = $this->db->prefixTable('trg_uji_m071_fk');
        $this->db->query("DROP TRIGGER IF EXISTS {$trg}");
        $this->db->query("CREATE TRIGGER {$trg} BEFORE INSERT ON " . $this->db->prefixTable('disiplin_ticket')
            . " FOR EACH ROW SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO = 1452, MESSAGE_TEXT = 'Cannot add or update a child row'");

        try {
            $res = $this->sebagai($pemilik)->post(self::URL . 'tiket', ['id_question_category' => $k, 'subject' => 'S', 'msg' => 'M']);
            $res->assertStatus(422);
            $this->assertSame(['id_question_category' => ['Kategori tidak tersedia.']], $this->json($res)['errors']);
            $this->assertSame([0, 0], [$this->db->table('disiplin_ticket')->countAllResults(), $this->db->table('disiplin_ticket_msg')->countAllResults()]);
        } finally {
            $this->db->query("DROP TRIGGER IF EXISTS {$trg}");
        }
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
