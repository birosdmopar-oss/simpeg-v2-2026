<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\MasterData\WebConfigService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SimapiUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-059 (H-07, F6-10) — `services/s_dm/*` lewat route sungguhan: semua method butuh Basic klien berhak `dm` (legacy `p_*`
 * publik → F6-03), amplop INT `{"status":1|2,…}` HTTP 200, pesan gagal persis legacy [K `S_dm.php`], `p_web_config` tidak ada.
 *
 * @internal
 */
final class ServicesSDmTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use SimapiUjiTrait;

    /** @var array{id: int, username: string, rahasia: string} */
    private array $klien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasangSimapiUji();
        $this->klien = $this->klienUji('mudig', 3, ['dm']);
    }

    protected function tearDown(): void
    {
        $this->lepasSimapiUji();
        parent::tearDown();
    }

    private function sdm(string $path): TestResponse
    {
        return $this->withHeaders(['Authorization' => $this->basicUji($this->klien)])->call('get', 'services/s_dm/' . $path);
    }

    public function testSemuaMethodButuhHakDm(): void
    {
        $tanpaHak = $this->klienUji('statonly', 3, ['stat']);

        foreach (['list_unit', 'list_satker', 'p_list_unit', 'p_list_unit_wo_kep', 'p_get_unit', 'p_list_satker', 'p_get_satker'] as $m) {
            foreach ([['Authorization' => ''], ['Authorization' => $this->basicUji($tanpaHak)]] as $header) {
                $res = $this->withHeaders($header)->call('get', 'services/s_dm/' . $m);
                $res->assertStatus(401);
                $this->assertSame(['status' => 2, 'msg' => 'Authentication failed', 'data' => []], json_decode((string) $res->getJSON(), true), $m);
            }
        }
    }

    public function testDaftarUnitDanSatker(): void
    {
        $u1 = $this->buatUnit(['order' => 2]);
        $u2 = $this->buatUnit(['order' => 1]);
        $this->buatUnit(['status' => 2]);
        $s1 = $this->buatSatker($u1);
        $this->buatSatker($u1, ['status' => 10]);

        foreach (['list_unit', 'p_list_unit'] as $m) {
            $badan = $this->jsonUji($this->sdm($m));
            $this->assertSame([1, ''], [$badan['status'], $badan['msg']]);
            $this->assertSame([(string) $u2, (string) $u1], array_column($badan['data'], 'id_unit'), "{$m}: status 1, urut order");
            $this->assertSame('unit_str', array_key_last($badan['data'][0]));
            $this->assertSame($badan['data'][0]['unit'], $badan['data'][0]['unit_str']);
        }

        foreach (['list_satker', 'p_list_satker'] as $m) {
            $this->assertSame(['status' => 2, 'msg' => 'Invalid parameter.', 'data' => []], $this->jsonUji($this->sdm($m)), $m);
            $data = $this->jsonUji($this->sdm($m . '?id_unit=' . $u1))['data'];
            $this->assertSame([(string) $s1], array_column($data, 'id_satker'));
            $this->assertSame('satker_str', array_key_last($data[0]));
            $this->assertSame([], $this->jsonUji($this->sdm($m . '?id_unit=' . $u2))['data']);
        }
    }

    public function testListUnitTanpaUnitKepegawaian(): void
    {
        $kep  = $this->buatUnit();
        $lain = $this->buatUnit();
        // Nilai web_config disuntik lewat tiruan: validasi ID_REF WebConfigService memakai cache referensi yang tidak
        // mengikuti rollback antar-test (id unit berulang), di luar cakupan endpoint ini.
        $wc = $this->createMock(WebConfigService::class);
        $wc->method('value')->willReturnCallback(static fn (string $k) => $k === 'id_unit_kepegawaian' ? $kep : null);
        Services::injectMock('webConfigService', $wc);

        $this->assertSame([(string) $lain], array_column($this->jsonUji($this->sdm('p_list_unit_wo_kep'))['data'], 'id_unit'));

        $wc2 = $this->createMock(WebConfigService::class);
        $wc2->method('value')->willReturn(null);
        Services::injectMock('webConfigService', $wc2);
        $this->assertSame(['status' => 2, 'msg' => 'Unit Kepegawaian tidak ditemukan didalam sistem.', 'data' => []], $this->jsonUji($this->sdm('p_list_unit_wo_kep')));
        Services::resetSingle('webConfigService');
    }

    public function testGetUnitDanSatker(): void
    {
        $u = $this->buatUnit();
        $s = $this->buatSatker($u);
        $h = $this->buatUnit(['status' => 10]);

        $this->assertSame(['status' => 2, 'msg' => 'Invalid parameter.', 'data' => []], $this->jsonUji($this->sdm('p_get_unit')));
        $this->assertSame(['status' => 2, 'msg' => 'Unit Eselon I tidak terdaftar didalam sistem.', 'data' => []], $this->jsonUji($this->sdm('p_get_unit?id_unit=' . $h)));
        $unit = $this->jsonUji($this->sdm('p_get_unit?id_unit=' . $u))['data'];
        $this->assertSame([(string) $u, $unit['unit']], [$unit['id_unit'], $unit['unit_str']]);

        $this->assertSame(['status' => 2, 'msg' => 'Invalid parameter.', 'data' => []], $this->jsonUji($this->sdm('p_get_satker')));
        $this->assertSame(['status' => 2, 'msg' => 'Unit Eselon II tidak terdaftar didalam sistem.', 'data' => []], $this->jsonUji($this->sdm('p_get_satker?id_satker=999999')));
        $satker = $this->jsonUji($this->sdm('p_get_satker?id_satker=' . $s))['data'];
        $this->assertSame([(string) $s, $unit['unit'], $satker['satker']], [$satker['id_satker'], $satker['unit_str'], $satker['satker_str']]);
        $this->assertSame(['unit_str', 'satker_str'], array_slice(array_keys($satker), -2));
    }

    public function testPWebConfigTidakAda(): void
    {
        $this->expectException(PageNotFoundException::class);
        $this->sdm('p_web_config');
    }
}
