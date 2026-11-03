<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-066 (H-04, F6-13) — `POST|DELETE api/v1/integrasi/fcm-token`: upsert PER PEMILIK (keputusan Horii 03-11-2026,
 * DBV-Q8), perpanjang `refresh_date` 1 minggu hanya bila lewat [K `L:4254-4261`], token web akun tanpa NIP, token sama
 * milik akun lain tidak disentuh (AC-04.5: token web terduplikasi tidak membuat baris baru), validasi, dan isi token tidak
 * dikembalikan.
 *
 * @internal
 */
final class TokenFcmTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/integrasi/fcm-token';

    public function testDaftarUlangTanpaBarisBaruDanPerpanjangBilaLewat(): void
    {
        $admin = $this->buatAkunTanpaNip('admin.uji.fcm', Role::SUPER_ADMIN);

        $r = $this->asUser($admin)->withBodyFormat('json')->post(self::URL, ['fcm_token' => 'tok-web-uji-1']);
        $r->assertStatus(200);
        $this->assertSame(['status' => 'baru'], json_decode($r->getJSON(), true)['data']);
        $this->assertStringNotContainsString('tok-web-uji-1', $r->getJSON(), 'isi token tidak dikembalikan');

        $baris = $this->db->table('firebase_token')->get()->getResultArray();
        $this->assertCount(1, $baris);
        $this->assertSame([null, (string) $admin['id_pengguna'], '1', hash('sha256', 'tok-web-uji-1')], [$baris[0]['nip'], (string) $baris[0]['id_pengguna'], (string) $baris[0]['platform'], $baris[0]['token_hash']]);
        $this->assertEqualsWithDelta(time() + 7 * 86400, strtotime($baris[0]['refresh_date'] . ' UTC'), 5);

        $this->assertSame('sudah_terdaftar', $this->daftar($admin, 'tok-web-uji-1'));
        $this->db->table('firebase_token')->update(['refresh_date' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $this->assertSame('diperpanjang', $this->daftar($admin, 'tok-web-uji-1'));
        $this->assertSame(1, $this->db->table('firebase_token')->countAllResults(), 'AC-04.5 tanpa baris ganda');
    }

    public function testTokenSamaAkunLainTercatatTerpisahDanNipPegawaiDisimpan(): void
    {
        $nip     = $this->buatPegawai();
        $pegawai = $this->buatAkunTanpaNip('pegawai.uji.fcm', Role::PEGAWAI, ['nip' => $nip]);
        $admin   = $this->buatAkunTanpaNip('admin.uji.fcm2', Role::SUPER_ADMIN);

        $this->assertSame('baru', $this->daftar($pegawai, 'tok-bersama'));
        $this->assertSame('baru', $this->daftar($admin, 'tok-bersama'), 'DBV-Q8 per pemilik: ikut legacy fb_token');
        $this->assertSame(2, $this->db->table('firebase_token')->countAllResults());
        $this->assertSame($nip, $this->db->table('firebase_token')->where('id_pengguna', $pegawai['id_pengguna'])->get()->getRow()->nip);

        $r = $this->asUser($admin)->withBodyFormat('json')->delete(self::URL, ['fcm_token' => 'tok-bersama']);
        $r->assertStatus(200);
        $this->assertSame(['dihapus' => 1], json_decode($r->getJSON(), true)['data']);
        $this->assertSame(1, $this->db->table('firebase_token')->countAllResults(), 'token akun lain tidak disentuh');
    }

    public function testValidasiDanWajibLogin(): void
    {
        $admin = $this->buatAkunTanpaNip('admin.uji.fcm3', Role::SUPER_ADMIN);

        foreach ([[], ['fcm_token' => ''], ['fcm_token' => 'ada spasi'], ['fcm_token' => str_repeat('a', 4097)], ['fcm_token' => ['x']]] as $body) {
            $this->asUser($admin)->withBodyFormat('json')->post(self::URL, $body)->assertStatus(422);
        }

        $this->assertSame(0, $this->db->table('firebase_token')->countAllResults());
    }

    public function testTanpaLoginDitolak(): void
    {
        $this->withBodyFormat('json')->post(self::URL, ['fcm_token' => 'tok'])->assertStatus(401);
        $this->assertSame(0, $this->db->table('firebase_token')->countAllResults());
    }

    /**
     * @param array<string, mixed> $akun
     */
    private function daftar(array $akun, string $token): string
    {
        $r = $this->asUser($akun)->withBodyFormat('json')->post(self::URL, ['fcm_token' => $token]);
        $r->assertStatus(200);

        return (string) json_decode($r->getJSON(), true)['data']['status'];
    }
}
