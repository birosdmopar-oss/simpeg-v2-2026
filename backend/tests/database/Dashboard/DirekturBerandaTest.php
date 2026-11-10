<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Dashboard;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-087 (F-13 M2) — `GET api/v1/dashboard/direktur` (role 1, 4): masa kerja direktur politeknik ikut `L_user::dashboard_sa`
 * "MKER DIREKTUR" — jabatan dari `Config\Dashboard::$idJabatanDirektur` (Q7-38), satu baris per jabatan (TMT terbaru), urut
 * TMT DESC, pegawai aktif saja, masa kerja "N Tahun M Bulan". Data sintetis.
 *
 * @internal
 */
final class DirekturBerandaTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/dashboard/direktur';

    /** @var list<int> */
    private array $idAsli;

    protected function setUp(): void
    {
        parent::setUp();
        Time::setTestNow('2026-11-10 09:00:00', 'Asia/Jakarta');
        $this->idAsli = config(Dashboard::class)->idJabatanDirektur;
    }

    protected function tearDown(): void
    {
        config(Dashboard::class)->idJabatanDirektur = $this->idAsli;
        Time::setTestNow();
        Services::reset(true);
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testSatuBarisPerJabatanTmtTerbaruUrutTmtDescDanMasaKerja(): void
    {
        $satkerA                                    = $this->buatSatker(null, ['satker' => "Politeknik A\\'s"]);
        $satkerB                                    = $this->buatSatker(null, ['satker' => 'Politeknik B']);
        $jabA                                       = $this->buatJabatan($satkerA)['id_jabatan'];
        $jabB                                       = $this->buatJabatan($satkerB)['id_jabatan'];
        $jabLain                                    = $this->buatJabatan($satkerB)['id_jabatan'];
        config(Dashboard::class)->idJabatanDirektur = [$jabA, $jabB];

        $this->direktur($jabA, 'Lama A', '2019-01-01');
        $this->direktur($jabA, 'Baru A', '2024-08-10', ['glr_awal' => 'Dr.', 'glr_akhir' => 'M.Par.']); // 2 Tahun 3 Bulan
        $this->direktur($jabB, 'Baru B', '2026-11-01');                                                 // < 1 bulan → ''
        $this->direktur($jabB, 'Nonaktif B', '2026-11-05', ['status' => 2]);                            // tidak aktif
        $this->direktur($jabLain, 'Bukan Direktur', '2025-01-01');

        $items = $this->items($this->buatAkunTanpaNip('admin.dir', Role::SUPER_ADMIN));

        $this->assertSame([
            ['nama' => 'Baru B', 'politeknik' => 'Politeknik B', 'masa_kerja' => ''],
            ['nama' => 'Dr. Baru A, M.Par.', 'politeknik' => "Politeknik A's", 'masa_kerja' => '2 Tahun 3 Bulan'],
        ], $items);
    }

    public function testDaftarJabatanKosongTanpaBaris(): void
    {
        config(Dashboard::class)->idJabatanDirektur = [];

        $this->assertSame([], $this->items($this->buatAkunTanpaNip('admin.dir0', Role::ADMIN_VIEW_ESELON1)));
    }

    public function testHanyaRole1Dan4(): void
    {
        foreach ([Role::ADMIN_SATKER, Role::MENTERI, Role::PIMPINAN] as $role) {
            $this->asUser($this->buatAkunTanpaNip('akun.dir.' . $role, $role))->get(self::URL)->assertStatus(403);
        }
    }

    /**
     * @param array<string, mixed> $override
     */
    private function direktur(int $idJabatan, string $nama, string $tmt, array $override = []): void
    {
        $this->buatPegawai(array_merge(['nama' => $nama], $override), ['id_jabatan' => $idJabatan, 'tmtsk' => $tmt]);
    }

    /**
     * @param array<string, mixed> $akun
     *
     * @return list<array<string, string>>
     */
    private function items(array $akun): array
    {
        $res = $this->asUser($akun)->get(self::URL);
        $res->assertStatus(200);

        return json_decode($res->getJSON(), true)['data']['items'];
    }
}
