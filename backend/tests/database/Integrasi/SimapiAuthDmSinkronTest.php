<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Klien\PembangkitKredensial;
use App\Libraries\Integrasi\Klien\PenyimpanKlien;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SimapiUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-059 (H-05, H-07, F6-07) — SIMAPI `a_auth/token`, `a_dm/*`, `a_sinkron/ambil_riwayat_mutasi` lewat route sungguhan:
 * amplop "01"/"02" HTTP 200, token baru per handshake (F6-05), AC-05.1 (token kedaluwarsa), AC-06.2 (tanpa token),
 * AC-07.1 (`a_dm/jabatan` butuh token), aturan type 2, dan kontrak field (fixture `tests/_support/Integrasi/Simapi/`).
 *
 * @internal
 */
final class SimapiAuthDmSinkronTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use SimapiUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasangSimapiUji();
    }

    protected function tearDown(): void
    {
        $this->lepasSimapiUji();
        parent::tearDown();
    }

    public function testTokenBaruPerHandshakeHanyaDataToken(): void
    {
        $klien = $this->klienUji('simanta', 2, ['simapi']);

        $a = $this->jsonUji($this->withHeaders(['Authorization' => $this->basicUji($klien)])->call('get', 'simapi/v1/a_auth/token'));
        $b = $this->jsonUji($this->withHeaders(['Authorization' => $this->basicUji($klien)])->call('post', 'simapi/v1/a_auth/token'));

        $this->assertSame(['status', 'msg', 'data'], array_keys($a));
        $this->assertSame(['01', 'Success'], [$a['status'], $a['msg']]);
        $this->assertSame(['token'], array_keys($a['data']), 'Q6-02: data.token saja');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{30}$/', $a['data']['token']);
        $this->assertNotSame($a['data']['token'], $b['data']['token'], 'F6-05: token baru per handshake');
        $this->assertSame(2, $this->db->table('simapi_token')->where('id_klien', $klien['id'])->countAllResults());
        $this->assertSame(1, $this->db->table('simapi_token')->where('token_hash', PembangkitKredensial::hashToken($a['data']['token']))->countAllResults(), 'disimpan sebagai hash');
    }

    public function testAuthGagalSeragamDanKlienLayananDitolak(): void
    {
        $klien = $this->klienUji('simanta', 2, ['simapi']);
        $mudig = $this->klienUji('mudig', 3, ['stat', 'dm']);

        foreach ([['username' => 'simanta', 'rahasia' => 'salah'], $mudig] as $kredensial) {
            $badan = $this->jsonUji($this->withHeaders(['Authorization' => $this->basicUji($kredensial)])->call('get', 'simapi/v1/a_auth/token'));
            $this->assertSame(['status' => '02', 'msg' => 'Authentication failed', 'data' => []], $badan);
        }

        (new PenyimpanKlien($this->db))->ubahStatus('simanta', PenyimpanKlien::STATUS_NONAKTIF);
        $badan = $this->jsonUji($this->withHeaders(['Authorization' => $this->basicUji($klien)])->call('get', 'simapi/v1/a_auth/token'));
        $this->assertSame('Authentication failed', $badan['msg']);
        $this->assertSame(0, $this->db->table('simapi_token')->countAllResults());
    }

    public function testTanpaTokenDanTokenKedaluwarsaDitolakTermasukJabatan(): void
    {
        $klien = $this->klienUji('simanta', 2, ['simapi']);
        $token = $this->tokenUji($klien);
        $gagal = ['status' => '02', 'msg' => 'Token is not valid', 'data' => []];

        foreach (['a_dm/group_jabatan', 'a_dm/sub_group_jabatan', 'a_dm/jabatan', 'a_dm/struktur_jabatan', 'a_sinkron/ambil_riwayat_mutasi'] as $path) {
            $this->assertSame($gagal, $this->jsonUji($this->tanpaAuthUji('simapi/v1/' . $path)), "AC-06.2/AC-07.1 {$path}");
        }

        $this->db->table('simapi_token')->set('expired_at', '2000-01-01 00:00:00')->update();
        $this->assertSame($gagal, $this->jsonUji($this->simapiUji('simapi/v1/a_dm/jabatan', $token)), 'AC-05.1 token kedaluwarsa');
    }

    /**
     * Review P1: `data` gagal SIMAPI = `[]` di BODY MENTAH (json_decode assoc menyamakan `{}` dan `[]`), termasuk gagal auth.
     */
    public function testDataGagalSimapiArrayKosongDiBodyMentah(): void
    {
        $this->klienUji('simanta', 2, ['simapi']);

        $auth  = (string) $this->withHeaders(['Authorization' => 'Basic ' . base64_encode('simanta:salah')])->call('get', 'simapi/v1/a_auth/token')->getJSON();
        $token = (string) $this->tanpaAuthUji('simapi/v1/a_dm/jabatan')->getJSON();

        foreach ([$auth, $token] as $body) {
            $this->assertSame([], json_decode($body, false)->data, $body);
            $this->assertStringNotContainsString('{}', $body);
        }
    }

    public function testAmbilSeluruhPenggunaTidakAda(): void
    {
        $token = $this->tokenUji($this->klienUji('simanta', 2, ['simapi']));

        $this->expectException(PageNotFoundException::class);
        $this->simapiUji('simapi/v1/a_sinkron/ambil_seluruh_pengguna', $token);
    }

    public function testADmKontrakDanUrutan(): void
    {
        $kontrak = $this->fixtureKontrak('a_dm');
        $token   = $this->tokenUji($this->klienUji('simanta', 2, ['simapi']));
        $this->db->table('group_jabatan')->insert(['id_group_jabatan' => 1, 'group_jabatan' => 'Struktural', 'status' => 1, 'order' => 9]);
        $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 1, 'sub_group_jabatan' => 'JPT Madya', 'status' => 1]);
        $j = $this->buatJabatan();
        $this->db->table('jabatan')->where('id_jabatan', $j['id_jabatan'])->update(['umur_pensiun' => 58]);
        $this->db->table('group_jabatan')->insert(['group_jabatan' => 'Nonaktif', 'status' => 2]);

        $gj = $this->jsonUji($this->simapiUji('simapi/v1/a_dm/group_jabatan', $token));
        $this->assertSame(['01', 'Success'], [$gj['status'], $gj['msg']]);
        $this->assertSame([(string) $j['id_group_jabatan'], '1'], array_column($gj['data'], 'id_group_jabatan'), 'status 1 saja, urut order');
        $this->assertSame($kontrak['group_jabatan']['kunci'], array_keys($gj['data'][0]));

        $sgj = $this->jsonUji($this->simapiUji('simapi/v1/a_dm/sub_group_jabatan', $token))['data'];
        $this->assertSame(['1', (string) $j['id_group_jabatan']], array_column($sgj, 'id_group_jabatan'), 'group 1 dulu');
        $this->assertSame($kontrak['sub_group_jabatan']['kunci'], array_keys($sgj[0]));
        $this->assertSame($kontrak['sub_group_jabatan']['kunci_group_lain'], array_keys($sgj[1]));

        $jab = $this->jsonUji($this->simapiUji('simapi/v1/a_dm/jabatan', $token))['data'];
        $this->assertCount(1, $jab);
        $this->assertSame($kontrak['jabatan']['kunci'], array_keys($jab[0]));
        $this->assertSame([(string) $j['id_jabatan'], (string) $j['id_unit'], '58'], [$jab[0]['id_jabatan'], $jab[0]['id_unit'], $jab[0]['umur_pensiun']], 'nilai string seperti legacy');
    }

    public function testStrukturJabatanPeriodeAktifTerbaru(): void
    {
        $kontrak = $this->fixtureKontrak('a_dm');
        $token   = $this->tokenUji($this->klienUji('simanta', 2, ['simapi']));
        $a       = $this->buatJabatan();
        $b       = $this->buatJabatan();
        $sj      = fn (int $periode, int $jab, ?int $atasan, int $level, array $lebih = []) => $this->db->table('struktur_jabatan')->insert($lebih + [
            'id_periode_struktur_jabatan' => $periode, 'id_jabatan' => $jab, 'id_jabatan_atasan' => $atasan, 'periode_struktur_jabatan' => 'P',
            'jabatan'                     => 'salinan lama', 'level_struktur' => $level,
        ]);

        $this->db->table('periode_struktur_jabatan')->insert(['periode_struktur_jabatan' => '2025', 'status' => 1]);
        $lama = (int) $this->db->insertID();
        $this->db->table('periode_struktur_jabatan')->insert(['periode_struktur_jabatan' => '2026', 'status' => 1]);
        $baru = (int) $this->db->insertID();
        $sj($lama, $a['id_jabatan'], null, 2);
        $sj($baru, $b['id_jabatan'], $a['id_jabatan'], 3);
        $sj($baru, $a['id_jabatan'], null, 2);
        $sj($baru, $a['id_jabatan'], $b['id_jabatan'], 4, ['status' => 10]);

        $data = $this->jsonUji($this->simapiUji('simapi/v1/a_dm/struktur_jabatan', $token))['data'];

        $this->assertSame(['2', '3'], array_column($data, 'level_struktur'), 'periode terbaru, urut level, tanpa status 10');
        $this->assertSame($kontrak['struktur_jabatan']['kunci'], array_keys($data[0]));
        $this->assertSame([null, ''], [$data[0]['id_jabatan_atasan'], $data[0]['jabatan_atasan']], 'atasan NULL → id null, nama "" (stripslashes legacy)');
        $this->assertStringStartsWith('Jabatan Fixture', $data[1]['jabatan'], 'nama dari tabel jabatan');
    }

    public function testKlienType1MenerimaDataKosongKecualiJabatan(): void
    {
        $token = $this->tokenUji($this->klienUji('pusdatin', 1, ['simapi']));
        $this->buatJabatan();

        foreach (['group_jabatan', 'sub_group_jabatan', 'struktur_jabatan'] as $m) {
            $this->assertSame(['status' => '01', 'msg' => '', 'data' => []], $this->jsonUji($this->simapiUji('simapi/v1/a_dm/' . $m, $token)), $m);
        }

        $this->assertCount(1, $this->jsonUji($this->simapiUji('simapi/v1/a_dm/jabatan', $token))['data'], 'jabatan tanpa batas type [K]');
    }

    public function testAmbilRiwayatMutasiSeluruhBaris(): void
    {
        $kontrak = $this->fixtureKontrak('a_sinkron');
        $token   = $this->tokenUji($this->klienUji('etalenta', 1, ['simapi']));

        $kosong = $this->jsonUji($this->simapiUji('simapi/v1/a_sinkron/ambil_riwayat_mutasi', $token, 'post'));
        $this->assertSame(['status' => '02', 'msg' => 'Tabel riwayat mutasi jabatan kosong', 'data' => []], $kosong);

        $nip = $this->buatPegawai();
        $this->buatRiwayatMutasiJabatan($nip, ['tmtsk' => '2010-01-01', 'rumpun_jabatan' => 'Rumpun A']);
        $this->buatRiwayatMutasiJabatan($nip, ['tmtsk' => '2020-01-01', 'status' => 0]);
        $this->buatRiwayatMutasiJabatan($nip, ['tmtsk' => '2015-01-01', 'status' => 10]);

        $badan = $this->jsonUji($this->simapiUji('simapi/v1/a_sinkron/ambil_riwayat_mutasi', $token));

        $this->assertSame(['01', 'Berhasil mengambil 2 data riwayat mutasi jabatan terupdate'], [$badan['status'], $badan['msg']]);
        $this->assertSame($kontrak['ambil_riwayat_mutasi']['kunci'], array_keys($badan['data'][0]));
        $this->assertSame(['2020-01-01', '2010-01-01'], array_column($badan['data'], 'tmtsk'), 'status 0 ikut, status 10 tidak (review P2), TMT DESC');
        $this->assertSame([null, 'Rumpun A'], array_column($badan['data'], 'rumpun_jabatan'));
    }
}
