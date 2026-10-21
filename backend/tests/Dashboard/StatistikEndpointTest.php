<?php

declare(strict_types=1);

namespace Tests\Dashboard;

use App\Constants\Role;
use App\Libraries\Dashboard\Statistik\StatistikService;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\ListQuery;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Dashboard\StatistikUjiTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F-03 statistik (MAKE-036, PRD Fase 7 §7.1.8 StatistikEndpointTest): 7 jenis × 3 lingkup, bentuk respons, populasi
 * (PTT/nonaktif/unit nonaktif tidak dihitung), "Tanpa data" pendidikan, bucket usia, golongan gabung CPNS, 404/422.
 *
 * @internal
 */
final class StatistikEndpointTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use StatistikUjiTrait;

    protected $seed = AuthSeeder::class;

    private const BASE = 'api/v1/statistik';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangCacheUji();
        $this->siapkanMasterStatistik();

        // Unit A: PNS L 41 th III/a S1 struktural Eselon II; PNS P 30 th CPNS III/a tanpa pendidikan fungsional;
        //         PPPK L 56 th gol V pelaksana (A2). Tidak dihitung: PTT, PNS nonaktif.
        $this->pegawaiStatistik('A1', ['jenis' => 1, 'jk' => 1, 'lahir' => $this->lahir(41), 'pangkat' => 'III/a', 'jenjang' => 'S1', 'eselon' => 1]);
        $this->pegawaiStatistik('A1', ['jenis' => 1, 'jk' => 2, 'lahir' => $this->lahir(30), 'pangkat' => 'CPNS III/a', 'group' => 2]);
        $this->pegawaiStatistik('A2', ['jenis' => 6, 'jk' => 1, 'lahir' => $this->lahir(56), 'gol_pppk' => 'V', 'group' => 3]);
        $this->pegawaiStatistik('A1', ['jenis' => 2, 'jk' => 1, 'lahir' => $this->lahir(35)]);
        $this->pegawaiStatistik('A1', ['jenis' => 1, 'jk' => 1, 'lahir' => $this->lahir(35), 'status' => 2, 'pangkat' => 'IV/a']);
        // Unit B: PNS L 65 th IV/a S2 struktural Eselon III. Unit C nonaktif: PNS tidak dihitung.
        $this->pegawaiStatistik('B1', ['jenis' => 1, 'jk' => 1, 'lahir' => $this->lahir(65), 'pangkat' => 'IV/a', 'jenjang' => 'S2', 'eselon' => 2]);
        $this->pegawaiStatistik('C1', ['jenis' => 1, 'jk' => 1, 'lahir' => $this->lahir(40), 'pangkat' => 'IV/a']);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testUnitKerjaLingkupKementerianDirinciPerUnitAktif(): void
    {
        $data = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja'));

        $this->assertSame('unit-kerja', $data['jenis']);
        $this->assertSame('Formasi Unit Kerja', $data['judul']);
        $this->assertSame(['tipe' => 'kementerian', 'id' => null], ['tipe' => $data['lingkup']['tipe'], 'id' => $data['lingkup']['id']]);
        $this->assertNotSame('', $data['lingkup']['nama']);
        $this->assertSame('Unit Kerja Eselon I', $data['label_kategori']);
        $this->assertSame(['Unit A', 'Unit B'], array_column($data['kategori'], 'label'), 'unit nonaktif tidak jadi kategori');
        $this->assertSame(['pns', 'pppk'], array_column($data['seri'], 'key'));
        $this->assertSame(['pns' => 2, 'pppk' => 1], $this->nilaiBaris($data, 'Unit A'), 'PTT dan PNS nonaktif tidak dihitung');
        $this->assertSame(['pns' => 1, 'pppk' => 0], $this->nilaiBaris($data, 'Unit B'));
        $this->assertSame(3, $data['baris'][0]['total']);
        $this->assertSame([3, 1], $data['subtotal']);
        $this->assertSame(4, $data['total']);
        $this->assertSame(TanggalBisnis::hariIni(), $data['periode']);
        $this->assertFalse($data['cache']['hit']);
        $this->assertSame(900, $data['cache']['ttl']);
    }

    public function testLingkupUnitDanSatker(): void
    {
        $unit = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja', ['lingkup' => 'unit', 'id' => (string) $this->unit['A']]));

        $this->assertSame(['tipe' => 'unit', 'id' => $this->unit['A'], 'nama' => 'Unit A'], $unit['lingkup']);
        $this->assertSame('Satuan Kerja', $unit['label_kategori']);
        $this->assertSame(['Satker A1', 'Satker A2'], array_column($unit['kategori'], 'label'));
        $this->assertSame(['pns' => 2, 'pppk' => 0], $this->nilaiBaris($unit, 'Satker A1'));
        $this->assertSame(['pns' => 0, 'pppk' => 1], $this->nilaiBaris($unit, 'Satker A2'));
        $this->assertSame(3, $unit['total']);

        $satker = $this->dataStatistik($this->asRole(Role::ADMIN_VIEW_ESELON1)->get(self::BASE . '/unit-kerja', ['lingkup' => 'satker', 'id' => (string) $this->satker['A2']]));

        $this->assertSame(['tipe' => 'satker', 'id' => $this->satker['A2'], 'nama' => 'Satker A2'], $satker['lingkup']);
        $this->assertSame(['Satker A2'], array_column($satker['kategori'], 'label'));
        $this->assertSame(1, $satker['total']);

        // Lingkup kementerian eksplisit = default.
        $this->assertSame(4, $this->dataStatistik($this->asRole(Role::MENTERI)->get(self::BASE . '/unit-kerja', ['lingkup' => 'kementerian']))['total']);
    }

    public function testJenisKelaminJabatanEselon(): void
    {
        $jk = $this->dataStatistik($this->asRole(Role::PIMPINAN)->get(self::BASE . '/jenis-kelamin'));
        $this->assertSame(['Laki-laki', 'Perempuan'], array_column($jk['seri'], 'label'));
        $this->assertSame(['l' => 2, 'p' => 1], $this->nilaiBaris($jk, 'Unit A'));
        $this->assertSame(['l' => 1, 'p' => 0], $this->nilaiBaris($jk, 'Unit B'));

        $jab = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/jabatan'));
        $this->assertSame(['struktural', 'fungsional', 'pelaksana', 'lain'], array_column($jab['seri'], 'key'));
        $this->assertSame(['struktural' => 1, 'fungsional' => 1, 'pelaksana' => 1, 'lain' => 0], $this->nilaiBaris($jab, 'Unit A'));
        $this->assertSame(['struktural' => 1, 'fungsional' => 0, 'pelaksana' => 0, 'lain' => 0], $this->nilaiBaris($jab, 'Unit B'));

        $es = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/eselon'));
        $this->assertSame(['Eselon II', 'Eselon III'], array_column($es['seri'], 'label'), 'seri = master sub_group_jabatan group 1 urut id');
        $this->assertSame(['e' . $this->subGroupEselon2 => 1, 'e' . $this->subGroupEselon3 => 0], $this->nilaiBaris($es, 'Unit A'));
        $this->assertSame(['e' . $this->subGroupEselon2 => 0, 'e' . $this->subGroupEselon3 => 1], $this->nilaiBaris($es, 'Unit B'));
        $this->assertSame(2, $es['total'], 'hanya struktural');
    }

    public function testPendidikanLeftJoinDenganTanpaData(): void
    {
        $data = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/pendidikan'));

        $this->assertSame(['S1', 'S2', 'Tanpa data'], array_column($data['seri'], 'label'));
        $this->assertSame(['j' . $this->jenjang['S1'] => 1, 'j' . $this->jenjang['S2'] => 0, 'tanpa' => 2], $this->nilaiBaris($data, 'Unit A'));
        $this->assertSame(['j' . $this->jenjang['S1'] => 0, 'j' . $this->jenjang['S2'] => 1, 'tanpa' => 0], $this->nilaiBaris($data, 'Unit B'));
        $this->assertSame(4, $data['total'], 'total = statistik unit kerja (F7-9)');
    }

    public function testUsiaDiBucket(): void
    {
        $data = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/usia'));

        $this->assertSame(['≤ 30', '31–40', '41–45', '46–50', '51–56', '57–60', '> 60'], array_column($data['seri'], 'label'));
        $this->assertSame(['le30' => 1, '31_40' => 0, '41_45' => 1, '46_50' => 0, '51_56' => 1, '57_60' => 0, 'gt60' => 0], $this->nilaiBaris($data, 'Unit A'));
        $this->assertSame(['le30' => 0, '31_40' => 0, '41_45' => 0, '46_50' => 0, '51_56' => 0, '57_60' => 0, 'gt60' => 1], $this->nilaiBaris($data, 'Unit B'));
    }

    public function testGolonganGabungCpnsUrutOrderPppkDiAkhir(): void
    {
        $data = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/golongan'));

        // II/a tidak punya data → tidak jadi kolom; CPNS III/a digabung ke III/a; PPPK V setelah PNS.
        $this->assertSame(['III/a', 'IV/a', 'V'], array_column($data['seri'], 'label'));
        $this->assertSame(['pns_iii_a' => 2, 'pns_iv_a' => 0, 'pppk_v' => 1], $this->nilaiBaris($data, 'Unit A'));
        $this->assertSame(['pns_iii_a' => 0, 'pns_iv_a' => 1, 'pppk_v' => 0], $this->nilaiBaris($data, 'Unit B'));
        $this->assertSame(4, $data['total']);
    }

    public function testSemuaJenisMenjawabUntukTigaLingkup(): void
    {
        foreach (array_keys(StatistikService::JENIS) as $jenis) {
            foreach ([[], ['lingkup' => 'unit', 'id' => (string) $this->unit['B']], ['lingkup' => 'satker', 'id' => (string) $this->satker['B1']]] as $q) {
                $data = $this->dataStatistik($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/' . $jenis, $q));
                $this->assertSame($jenis, $data['jenis']);
                $this->assertSame(count($data['seri']), count($data['subtotal']), $jenis);
                $this->assertSame(array_sum($data['subtotal']), $data['total'], $jenis);
            }
        }
    }

    public function testLingkupTersediaRoleLintasSatker(): void
    {
        $result = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/lingkup');
        $result->assertStatus(200);
        $data = $this->json($result)['data'];

        $this->assertNotNull($data['kementerian']);
        $this->assertNotSame('', $data['kementerian']['nama']);
        $unitA = array_values(array_filter($data['unit'], fn (array $u): bool => $u['id'] === $this->unit['A']));
        $this->assertCount(1, $unitA);
        $this->assertSame(['Satker A1', 'Satker A2'], array_column($unitA[0]['satker'], 'nama'));
        $this->assertNotContains($this->unit['C'], array_column($data['unit'], 'id'), 'unit nonaktif tidak ditawarkan');
    }

    public function testJenisAtauUnitTidakDikenal404(): void
    {
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/agama')->assertStatus(404);
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja', ['lingkup' => 'unit', 'id' => '999999'])->assertStatus(404);
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja', ['lingkup' => 'satker', 'id' => '999999'])->assertStatus(404);
        // Unit nonaktif = tidak ada.
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja', ['lingkup' => 'unit', 'id' => (string) $this->unit['C']])->assertStatus(404);
    }

    public function testParameterTidakValid422(): void
    {
        foreach ([
            [['lingkup' => 'provinsi'], 'lingkup'],
            [['lingkup' => 'unit'], 'id'],
            [['lingkup' => 'satker', 'id' => 'abc'], 'id'],
            [['lingkup' => 'unit', 'id' => '0'], 'id'],
            [['lingkup' => ['unit']], 'lingkup'],
            [['lingkup' => 'unit', 'id' => ['1']], 'id'],
        ] as [$q, $field]) {
            $result = $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja', $q);
            $result->assertStatus(422);
            $this->assertArrayHasKey($field, $this->json($result)['errors'], json_encode($q));
        }

        $arr = $this->json($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/unit-kerja', ['lingkup' => ['unit']]));
        $this->assertSame(ListQuery::INVALID_MESSAGE, $arr['message']);
    }

    /**
     * Tanggal lahir supaya umur tepat $umur tahun hari ini (ulang tahun sudah lewat 100 hari).
     */
    private function lahir(int $umur): string
    {
        return date('Y-m-d', strtotime(TanggalBisnis::hariIni() . " -{$umur} years -100 days"));
    }
}
