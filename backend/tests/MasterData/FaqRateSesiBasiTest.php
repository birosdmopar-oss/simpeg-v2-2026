<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use App\Database\Migrations\AddFkPegawaiDiPenggunaFaqRate;
use App\Libraries\Kepegawaian\NipCascadeService;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * CR-171 (G2): sesudah koreksi NIP (B-06) access token lama masih membawa NIP lama di claim. `POST faq/{id}/rate` dengan token itu
 * ditolak tanpa rating yatim ber-NIP lama dan tanpa rating ganda (rating lama sudah ikut pindah ke NIP baru). Sejak CR-175 penolakan
 * itu datang dari JwtAuthFilter (401 generik); cek 403 sesi basi FaqService tetap ada sebagai lapis kedua dan jaring 1452 (R2,
 * balapan sesudah filter) tetap 403. Token baru (NIP baru) tetap dilayani normal.
 *
 * @internal
 */
final class FaqRateSesiBasiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    private const NIP_BARU = '198801012010011999';

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testTokenLamaSesudahKoreksiNipDitolakTanpaYatimDanGanda(): void
    {
        $lama      = $this->buatPegawai();
        $idAkun    = $this->buatAkunUntuk($lama, Role::PEGAWAI);
        $tokenLama = $this->issueTokensForUser((array) $this->db->table('pengguna')->where('id_pengguna', $idAkun)->get()->getRowArray())['access_token'];

        $this->rate($tokenLama, 1)->assertStatus(201);

        $super = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), Role::SUPER_ADMIN));
        (new NipCascadeService())->koreksi($super, $lama, self::NIP_BARU);
        $this->assertSame(1, $this->db->table('faq_rate')->where('id_faq_article', 1)->where('nip', self::NIP_BARU)->countAllResults(), 'rating ikut pindah ke NIP baru');

        // CR-175: JwtAuthFilter menolak sesi NIP basi lebih dulu (401 generik), sebelum cek 403 sesi basi FaqService.
        $res = $this->rate($tokenLama, 1);
        $res->assertStatus(401);
        $this->assertSame(['status' => 'error', 'message' => 'Unauthorized'], $this->json($res));

        $this->assertSame(0, $this->db->table('faq_rate')->where('nip', $lama)->countAllResults(), 'tanpa rating yatim ber-NIP lama');
        $this->assertSame(1, $this->db->table('faq_rate')->where('id_faq_article', 1)->countAllResults(), 'tanpa rating ganda');

        // Sesi baru (NIP baru; sesudah ganti password wajib) tetap dikenali: rating yang sudah pindah → 422 "sudah dinilai", bukan 403.
        $akunBaru  = ['must_change_password' => 0] + (array) $this->db->table('pengguna')->where('id_pengguna', $idAkun)->get()->getRowArray();
        $tokenBaru = $this->issueTokensForUser($akunBaru)['access_token'];
        $this->rate($tokenBaru, 1)->assertStatus(422);
    }

    /**
     * R1 review: token basi akun X tetap ditolak walau NIP lamanya kini dipegang akun Y (pegawai lain ber-NIP sama didaftarkan
     * ulang) — cek terikat ke akun pemilik token (`sub`), bukan sembarang akun ber-NIP itu; tanpa rating atas nama pegawai Y.
     */
    public function testTokenBasiDitolakWalauNipLamaKiniDipegangAkunLain(): void
    {
        $lama   = $this->buatPegawai();
        $idX    = $this->buatAkunUntuk($lama, Role::PEGAWAI);
        $tokenX = $this->issueTokensForUser((array) $this->db->table('pengguna')->where('id_pengguna', $idX)->get()->getRowArray())['access_token'];
        $super  = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), Role::SUPER_ADMIN));
        (new NipCascadeService())->koreksi($super, $lama, self::NIP_BARU);

        $this->buatPegawai(['nip' => $lama, 'nama' => 'Pegawai Y']);
        $idY = $this->buatAkunUntuk($lama, Role::PEGAWAI, ['username' => 'akun.y.' . substr($lama, -4)]);
        $this->assertNotSame($idX, $idY);

        $res = $this->rate($tokenX, 1);
        $res->assertStatus(401); // CR-175: ditolak filter
        $this->assertSame(0, $this->db->table('faq_rate')->where('nip', $lama)->countAllResults(), 'tanpa rating atas nama pegawai Y');
    }

    /**
     * R2 review: cabang 1452 (aktif sesudah FK ditahan DBV-019 `fk_nip_faqrate_to_peg` dipasang). FK dipasang sementara; pegawai
     * penilai dihapus tepat sesudah cek "sudah dinilai" (SELECT faq_rate) dan sebelum INSERT → 1452 → 403 sesi basi, tanpa baris.
     */
    #[Group('db-isolasi-penuh')]
    public function testFk1452PegawaiHilangSebelumInsertDitolak403(): void
    {
        $nip    = $this->buatPegawai();
        $idAkun = $this->buatAkunUntuk($nip, Role::PEGAWAI);
        $token  = $this->issueTokensForUser((array) $this->db->table('pengguna')->where('id_pengguna', $idAkun)->get()->getRowArray())['access_token'];

        // Prasyarat migration (fail-closed): NIP akun seed & rating seed terdaftar sebagai pegawai.
        $nips = array_merge(
            array_column($this->db->table('pengguna')->select('nip')->where('nip IS NOT NULL', null, false)->get()->getResultArray(), 'nip'),
            array_column($this->db->table('faq_rate')->select('nip')->get()->getResultArray(), 'nip'),
        );
        $this->daftarkanPegawai(...array_values(array_unique(array_map('strval', $nips))));

        require_once APPPATH . 'Database/MigrationsDitahan/AddFkPegawaiDiPenggunaFaqRate.php';
        $fk     = new AddFkPegawaiDiPenggunaFaqRate();
        $db     = $this->db;
        $sekali = false;
        $balap  = static function (Query $q) use ($db, $nip, &$sekali): void {
            if (! $sekali && str_contains($q->getQuery(), 'faq_rate') && str_starts_with(ltrim($q->getQuery()), 'SELECT')) {
                $sekali = true;
                $db->query('SET FOREIGN_KEY_CHECKS = 0');
                $db->table('pegawai')->where('nip', $nip)->delete();
                $db->query('SET FOREIGN_KEY_CHECKS = 1');
            }
        };

        try {
            $fk->up();
            Events::on('DBQuery', $balap);
            $res = $this->rate($token, 1);
        } finally {
            Events::removeListener('DBQuery', $balap);
            $fk->down();
        }

        $this->assertTrue($sekali, 'balapan terjadi');
        $res->assertStatus(403);
        $this->assertSame('Sesi tidak lagi sesuai data pegawai. Silakan login ulang.', $this->json($res)['message']);
        $this->assertSame(0, $this->db->table('faq_rate')->where('nip', $nip)->countAllResults());
    }

    private function rate(string $token, int $artikel): TestResponse
    {
        return $this->withBearer($token)->withBodyFormat('json')->post("api/v1/faq/{$artikel}/rate", ['rate' => 1]);
    }

}
