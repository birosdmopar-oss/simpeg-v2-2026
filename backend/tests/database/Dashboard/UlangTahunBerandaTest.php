<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Interfaces\Kepegawaian\BiodataServiceInterface;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Dashboard;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-087 (F-13 M2, Q7-45 ikut legacy) — `GET dashboard/ulang-tahun?hari=ini|besok` + `GET dashboard/ulang-tahun/{nip}/foto`:
 * pemilihan `function_helper.php:1419-1475` (bulan-hari lahir, status 1, satker 171 dikecualikan, tanpa snapshot jabatan
 * tersaring, urut NIP), field view `dashboard_ad.php:250-298` (baris khusus `nip = '1'`), foto hanya untuk NIP di daftar
 * (Q-D19) dan boleh dilihat role 2. Hari ini 10-11-2026 WIB. Data sintetis.
 *
 * @internal
 */
final class UlangTahunBerandaTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/dashboard/ulang-tahun';

    /** @var list<int> */
    private array $satkerAsli;

    protected function setUp(): void
    {
        parent::setUp();
        Time::setTestNow('2026-11-10 09:00:00', 'Asia/Jakarta');
        $this->satkerAsli = config(Dashboard::class)->idSatkerDikecualikanUlangTahun;
    }

    protected function tearDown(): void
    {
        config(Dashboard::class)->idSatkerDikecualikanUlangTahun = $this->satkerAsli;
        Time::setTestNow();
        Services::reset(true);
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testHariIniDanBesokDenganAturanPemilihanLegacy(): void
    {
        $satker                                                  = $this->buatSatker(null, ['satker' => 'Satker Fixture']);
        $kecuali                                                 = $this->buatSatker();
        config(Dashboard::class)->idSatkerDikecualikanUlangTahun = [$kecuali];

        $b = $this->buatPegawaiDiSatker($satker, ['nip' => '198011102005011002', 'nama' => 'Budi', 'glr_akhir' => 'S.T.', 'tgl_lahir' => '1980-11-10', 'jenis_pegawai' => 'PNS']);
        $a = $this->buatPegawaiDiSatker($satker, ['nip' => '198511102010012001', 'nama' => 'Ani', 'tgl_lahir' => '1985-11-10', 'jenis_pegawai' => 'PNS']);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $a)->update(['jabatan' => 'Analis', 'jabatan_koord' => 'Ketua Tim']);
        $this->buatPegawaiDiSatker($satker, ['tgl_lahir' => '1990-11-11', 'nama' => 'Besok']);
        $this->buatPegawaiDiSatker($kecuali, ['tgl_lahir' => '1982-11-10', 'nama' => 'Satker Dikecualikan']);
        $this->buatPegawaiDiSatker($satker, ['tgl_lahir' => '1983-11-10', 'nama' => 'Nonaktif', 'status' => 2]);
        $tanpaPmj = $this->buatPegawaiDiSatker($satker, ['tgl_lahir' => '1984-11-10', 'nama' => 'Tanpa Jabatan']);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $tanpaPmj)->delete();

        $hariIni = $this->items($this->buatAkunTanpaNip('admin.ultah', Role::SUPER_ADMIN), 'ini');

        $this->assertSame([$b, $a], array_column($hariIni, 'nip'), 'urut NIP ASC [I]; satker dikecualikan, nonaktif, tanpa jabatan tersaring');
        $this->assertSame([
            'nip'     => $a, 'nama' => 'Ani', 'tanggal_lahir' => '10 November 1985', 'jenis_pegawai' => 'PNS',
            'jabatan' => 'Analis (Ketua Tim)', 'satker' => 'Satker Fixture', 'khusus' => false, 'ada_foto' => false, 'foto_kunci' => $a,
        ], $hariIni[1]);
        $this->assertSame('Budi, S.T.', $hariIni[0]['nama']);

        $besok = $this->items($this->buatAkunTanpaNip('admin.ultah2', Role::SUPER_ADMIN), 'besok');
        $this->assertSame(['Besok'], array_column($besok, 'nama'));
    }

    public function testBarisKhususNipSatuHanyaNamaTanggalDanLabel(): void
    {
        $this->buatPegawaiDiSatker($this->buatSatker(), ['nip' => '1', 'nama' => 'Pejabat', 'tgl_lahir' => '1970-11-10']);

        $item = $this->items($this->buatAkunTanpaNip('admin.ultah3', Role::SUPER_ADMIN), 'ini')[0];

        $this->assertSame([null, 'Pejabat', '10 November 1970', null, config(Dashboard::class)->labelJabatanNipSatu, null, true], [
            $item['nip'], $item['nama'], $item['tanggal_lahir'], $item['jenis_pegawai'], $item['jabatan'], $item['satker'], $item['khusus'],
        ]);
    }

    public function testSemuaRoleMelihatDaftarYangSamaDanHariSalah422(): void
    {
        $this->buatPegawaiDiSatker($this->buatSatker(), ['tgl_lahir' => '1980-11-10']);

        foreach ([Role::ADMIN_SATKER, Role::MENTERI] as $role) {
            $this->assertCount(1, $this->items($this->buatAkunTanpaNip('ultah.r' . $role, $role), 'ini'), (string) $role);
        }

        $nip  = $this->buatPegawaiDiSatker($this->buatSatker(), ['tgl_lahir' => '1991-01-01']);
        $akun = $this->db->table('pengguna')->where('id_pengguna', $this->buatAkunUntuk($nip, Role::PEGAWAI))->get()->getRowArray();
        $this->assertCount(1, $this->items($akun, 'ini'), 'role 2 melihat daftar kementerian');
        $this->asUser($this->buatAkunTanpaNip('ultah.salah', Role::SUPER_ADMIN))->get(self::URL . '?hari=lusa')->assertStatus(422);
    }

    public function testFotoHanyaUntukNipDiDaftarDanRole2BolehMelihatRekan(): void
    {
        $satker = $this->buatSatker();
        $ultah  = $this->buatPegawaiDiSatker($satker, ['tgl_lahir' => '1980-11-10', 'foto' => 'foto/x.png']);
        $lain   = $this->buatPegawaiDiSatker($satker, ['tgl_lahir' => '1980-05-05', 'foto' => 'foto/y.png']);

        $biodata = $this->createMock(BiodataServiceInterface::class);
        $biodata->method('fotoSistem')->willReturnCallback(static fn (string $nip): array => ['isi' => "PNG-{$nip}", 'mime' => 'image/png', 'nama' => "foto_{$nip}.png"]);
        Services::injectMock('biodataService', $biodata);

        $rekan = $this->buatPegawaiDiSatker($this->buatSatker());
        $akun  = $this->db->table('pengguna')->where('id_pengguna', $this->buatAkunUntuk($rekan, Role::PEGAWAI))->get()->getRowArray();

        $res = $this->asUser($akun)->get(self::URL . '/' . $ultah . '/foto');
        $res->assertStatus(200);
        $this->assertSame('PNG-' . $ultah, $res->response()->getBody());
        $this->assertStringStartsWith('image/png', $res->response()->getHeaderLine('Content-Type'));

        $this->asUser($akun)->get(self::URL . '/' . $lain . '/foto')->assertStatus(404);
        $this->asUser($akun)->get(self::URL . '/199999999999999999/foto')->assertStatus(404);
    }

    public function testTanpaToken401(): void
    {
        $this->get(self::URL)->assertStatus(401);
    }

    /**
     * @param array<string, mixed> $akun
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $akun, string $hari): array
    {
        $res = $this->asUser($akun)->get(self::URL . '?hari=' . $hari);
        $res->assertStatus(200);
        $data = json_decode($res->getJSON(), true)['data'];
        $this->assertSame($hari, $data['hari']);

        return $data['items'];
    }
}
