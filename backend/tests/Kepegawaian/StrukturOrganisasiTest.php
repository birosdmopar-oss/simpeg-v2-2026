<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-19 Struktur Organisasi (WS-2 M4 MAKE-012): pohon struktur_jabatan periode aktif, pejabat definitif → Plt → Plh,
 * filter unit/satker, akses semua pengguna login.
 *
 * @internal
 */
final class StrukturOrganisasiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    private int $idPeriode;

    /**
     * @var array<string, int> nama simpul => id_jabatan
     */
    private array $jab = [];

    private int $idSatkerA;
    private int $idSatkerB;
    private int $idUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idUnit    = $this->buatUnit();
        $this->idSatkerA = $this->buatSatker($this->idUnit);
        $this->idSatkerB = $this->buatSatker($this->idUnit);

        // Periode lama (status 2) diabaikan; periode aktif terbaru dipakai.
        $this->db->table('periode_struktur_jabatan')->insert(['periode_struktur_jabatan' => 'Periode Lama Uji', 'status' => 2]);
        $this->db->table('periode_struktur_jabatan')->insert(['periode_struktur_jabatan' => 'Periode Uji ' . $this->idUnit, 'status' => 1]);
        $this->idPeriode = (int) $this->db->insertID();

        $this->jab['es1']  = $this->buatJabatan($this->idSatkerA)['id_jabatan'];
        $this->jab['es2a'] = $this->buatJabatan($this->idSatkerA)['id_jabatan'];
        $this->jab['es2b'] = $this->buatJabatan($this->idSatkerB)['id_jabatan'];
        $this->jab['es3']  = $this->buatJabatan($this->idSatkerB)['id_jabatan'];

        $this->struktur('es1', null, 2, 1);
        $this->struktur('es2b', 'es1', 3, 2);
        $this->struktur('es2a', 'es1', 3, 1);
        $this->struktur('es3', 'es2b', 4, 1);
    }

    public function testPohonDanPejabatDefinitifPltPlh(): void
    {
        $definitif = $this->buatPegawai(['nama' => 'Kepala', 'glr_awal' => 'Dr.', 'glr_akhir' => 'M.Si.'], ['id_jabatan' => $this->jab['es1']]);
        $this->db->table('pegawai_kp')->insert([
            'nip'   => $definitif, 'gol_ruang' => 'IV/e', 'gol' => 'IV', 'ruang' => 'e', 'pangkat' => 'Pembina Utama', 'jenis_kp' => 'Reguler',
            'tmtsk' => '2020-04-01', 'tgl_sk' => '2020-03-01',
        ]);
        // Pegawai nonaktif pada jabatan es2a tidak dihitung → Plt aktif mengisi.
        $this->buatPegawai(['nama' => 'Pensiun', 'status' => 2], ['id_jabatan' => $this->jab['es2a']]);
        $plt = $this->buatPegawai(['nama' => 'Pelaksana Tugas']);
        $this->penugasan('plt', $plt, 'es2a');
        // Plt kedaluwarsa/berstatus 2 untuk es2b diabaikan → Plh yang berlaku.
        $this->penugasan('plt', $this->buatPegawai(), 'es2b', '-30 days', '-1 day');
        $this->penugasan('plt', $this->buatPegawai(), 'es2b', status: 2);
        $plh = $this->buatPegawai(['nama' => 'Pelaksana Harian']);
        $this->penugasan('plh', $plh, 'es2b');

        $hasil = (new StrukturService())->pohon($this->admin());

        $this->assertSame($this->idPeriode, $hasil['periode']['id_periode_struktur_jabatan'] ?? null);
        $this->assertCount(1, $hasil['nodes']);
        $akar = $hasil['nodes'][0];
        $this->assertSame($this->jab['es1'], $akar['id_jabatan']);
        $this->assertSame(['definitif', $definitif, 'Dr. Kepala, M.Si.', "Dr. Kepala, M.Si., IV/e, {$definitif}"], [
            $akar['pejabat']['jenis'], $akar['pejabat']['nip'], $akar['pejabat']['nama_lengkap'], $akar['pejabat']['pejabat_so'],
        ]);

        // Anak urut level, urutan.
        $this->assertSame([$this->jab['es2a'], $this->jab['es2b']], array_column($akar['children'], 'id_jabatan'));
        [$es2a, $es2b] = $akar['children'];
        $this->assertSame(['plt', $plt], [$es2a['pejabat']['jenis'], $es2a['pejabat']['nip']]);
        $this->assertStringStartsWith('Plt. ', $es2a['pejabat']['pejabat_so']);
        $this->assertSame(['plh', $plh], [$es2b['pejabat']['jenis'], $es2b['pejabat']['nip']]);
        $this->assertSame($this->jab['es3'], $es2b['children'][0]['id_jabatan']);
        $this->assertNull($es2b['children'][0]['pejabat'], 'jabatan kosong');
        $this->assertSame([$this->idUnit, $this->idSatkerB], [$es2b['id_unit'], $es2b['id_satker']]);
    }

    public function testFilterSatkerMenjadikanSimpulTeratasAkar(): void
    {
        $hasil = (new StrukturService())->pohon($this->admin(), ['id_satker' => (string) $this->idSatkerB]);

        $this->assertSame([$this->jab['es2b']], array_column($hasil['nodes'], 'id_jabatan'));
        $this->assertSame([$this->jab['es3']], array_column($hasil['nodes'][0]['children'], 'id_jabatan'));

        $unit = (new StrukturService())->pohon($this->admin(), ['id_unit' => (string) $this->idUnit]);
        $this->assertSame([$this->jab['es1']], array_column($unit['nodes'], 'id_jabatan'));
    }

    public function testTanpaPeriodeAktifKosong(): void
    {
        $this->db->table('periode_struktur_jabatan')->where('status', 1)->update(['status' => 2]);

        $hasil = (new StrukturService())->pohon($this->admin());
        $this->assertNull($hasil['periode']);
        $this->assertSame([], $hasil['nodes']);
    }

    public function testEndpointTanpaLogin401(): void
    {
        $this->get('api/v1/struktur-organisasi')->assertStatus(401);
    }

    public function testEndpointSemuaRoleLoginDanFilterTidakValid(): void
    {
        foreach (Role::all() as $role) {
            [, , $akun] = $this->aktor($role);
            $this->asUser($akun)->get('api/v1/struktur-organisasi')->assertStatus(200);
        }

        [, , $akun] = $this->aktor(Role::PEGAWAI);
        $this->asUser($akun)->get('api/v1/struktur-organisasi?id_satker=abc')->assertStatus(422);
    }

    private function struktur(string $nama, ?string $atasan, int $level, int $urutan): void
    {
        $this->db->table('struktur_jabatan')->insert([
            'id_periode_struktur_jabatan' => $this->idPeriode,
            'id_jabatan'                  => $this->jab[$nama],
            'id_jabatan_atasan'           => $atasan === null ? null : $this->jab[$atasan],
            'periode_struktur_jabatan'    => 'Periode Uji',
            'jabatan'                     => $nama,
            'level_struktur'              => $level,
            'urutan'                      => $urutan,
            'status'                      => 1,
        ]);
    }

    private function penugasan(string $jenis, string $nip, string $jabatan, string $mulai = '-1 day', string $akhir = '+30 days', int $status = 1): void
    {
        $this->db->table("pegawai_{$jenis}")->insert([
            "nip_{$jenis}"  => $nip,
            'jenis_jabatan' => 1,
            'id_jabatan'    => $this->jab[$jabatan],
            'tgl_mulai'     => Time::today()->modify($mulai)->toDateString(),
            'tgl_akhir'     => Time::today()->modify($akhir)->toDateString(),
            'status'        => $status,
        ]);
    }
}
