<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use App\Libraries\Kepegawaian\NipCascadeService;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * CR-171 (G2): sesudah koreksi NIP (B-06) access token lama masih membawa NIP lama di claim. `POST faq/{id}/rate` dengan token itu
 * ditolak 403 (seperti penolakan identitas penilai lain), tanpa rating yatim ber-NIP lama dan tanpa rating ganda (rating lama
 * sudah ikut pindah ke NIP baru). Token baru (NIP baru) tetap dilayani normal.
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

        $res = $this->rate($tokenLama, 1);
        $res->assertStatus(403);
        $this->assertSame('Sesi tidak lagi sesuai data pegawai. Silakan login ulang.', $this->json($res)['message']);

        $this->assertSame(0, $this->db->table('faq_rate')->where('nip', $lama)->countAllResults(), 'tanpa rating yatim ber-NIP lama');
        $this->assertSame(1, $this->db->table('faq_rate')->where('id_faq_article', 1)->countAllResults(), 'tanpa rating ganda');

        // Sesi baru (NIP baru; sesudah ganti password wajib) tetap dikenali: rating yang sudah pindah → 422 "sudah dinilai", bukan 403.
        $akunBaru  = ['must_change_password' => 0] + (array) $this->db->table('pengguna')->where('id_pengguna', $idAkun)->get()->getRowArray();
        $tokenBaru = $this->issueTokensForUser($akunBaru)['access_token'];
        $this->rate($tokenBaru, 1)->assertStatus(422);
    }

    private function rate(string $token, int $artikel): TestResponse
    {
        return $this->withBearer($token)->withBodyFormat('json')->post("api/v1/faq/{$artikel}/rate", ['rate' => 1]);
    }

}
