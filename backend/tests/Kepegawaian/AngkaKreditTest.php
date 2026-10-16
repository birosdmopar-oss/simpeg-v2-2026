<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Kepegawaian\AngkaKredit\AkPermenpanrb;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Ak;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * MAKE-021 (keputusan user 16-10-2026): target AK kenaikan pangkat PermenPAN-RB 1/2023 (`AkPermenpanrb`, port getAKAccu
 * legacy) + endpoint `GET pegawai/{nip}/ak/target-kp` (izin = lihat riwayat AK), dan CRUD master `dm_ak_jf` ikut legacy
 * `C_jabatan`/`Lm_jabatan` akjf (role 1; daftar/tambah/ubah AK; tanpa hapus [K]). Master kosong/terisi untuk AK kumulatif.
 *
 * @internal
 */
final class AngkaKreditTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    /**
     * @var array<string, int>
     */
    private array $pangkat = [];

    private int $jenjangPertama = 0;
    private int $jabatanJf      = 0;
    private int $subGroupJf     = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([2 => 'Jabatan Fungsional Tertentu', 3 => 'Jabatan Fungsional Umum'] as $id => $nama) {
            if ($this->db->table('group_jabatan')->where('id_group_jabatan', $id)->countAllResults() === 0) {
                $this->db->table('group_jabatan')->insert(['id_group_jabatan' => $id, 'group_jabatan' => $nama, 'status' => 1]);
            }
        }

        foreach (['III/a' => [9, 2], 'III/b' => [10, 2], 'II/a' => [5, 1]] as $gol => [$order, $cpns]) {
            $this->db->table('pangkat')->insert(['pangkat' => 'Pangkat ' . $gol, 'gol' => 'III', 'ruang' => substr($gol, -1), 'gol_ruang' => ($cpns === 1 ? 'C-' : '') . $gol, 'cpns' => $cpns, 'order' => $order, 'status' => 1]);
            $this->pangkat[$gol] = (int) $this->db->insertID();
        }

        $this->db->table('jenjang_jf')->insert(['kategori_jf' => 'Ahli', 'jenjang_jf' => 'Ahli Pertama', 'extra_nama_jab' => '', 'order' => 1, 'status' => 1]);
        $this->jenjangPertama = (int) $this->db->insertID();

        $rantai = $this->buatJabatan();
        $this->db->table('sub_group_jabatan')->where('id_sub_group_jabatan', $rantai['id_sub_group_jabatan'])->update(['id_group_jabatan' => 2]);
        $this->db->table('jabatan')->where('id_jabatan', $rantai['id_jabatan'])->update(['id_group_jabatan' => 2, 'id_jenjang_jf' => $this->jenjangPertama]);
        $this->jabatanJf  = $rantai['id_jabatan'];
        $this->subGroupJf = $rantai['id_sub_group_jabatan'];
    }

    // ---- PermenPAN-RB 1/2023: target AK KP --------------------------------------------------------------------------

    public function testTargetKpAkSaatIniDariSiasnKonversiIntegrasiJabatanSekarang(): void
    {
        $nip = $this->pegawaiJf('III/a');
        $this->siasn($nip, $this->jabatanJf, 30, '1', null);
        $this->siasn($nip, $this->jabatanJf, 10, null, '1');
        $this->siasn($nip, $this->jabatanJf, 99, '0', '0');            // bukan konversi/integrasi
        $this->siasn($nip, $this->buatJabatan()['id_jabatan'], 50, '1', null); // jabatan lain

        $hasil = (new AkPermenpanrb())->hitung($nip);

        $this->assertSame(['Ahli Pertama', 'III/a', 40.0, 50.0, 10.0, false], [$hasil['jenjang'], $hasil['gol_ruang'], $hasil['current_ak'], $hasil['ak_for_kp'], $hasil['ak_kurang'], $hasil['ak_maxed']]);

        // AK saat ini ≥ target → kekurangan 0, ak_maxed (rumus dashboard_pns legacy).
        $this->siasn($nip, $this->jabatanJf, 15, '1', null);
        $this->assertSame([55.0, 0.0, true], [($h = (new AkPermenpanrb())->hitung($nip))['current_ak'], $h['ak_kurang'], $h['ak_maxed']]);
    }

    public function testTargetKpPegawaiTanpaJenjangDanTanpaRiwayat(): void
    {
        $nip   = $this->buatPegawai();
        $hasil = (new AkPermenpanrb())->hitung($nip);

        $this->assertSame(['', 0.0, 0.0, 0.0, true], [$hasil['jenjang'], $hasil['current_ak'], $hasil['ak_for_kp'], $hasil['ak_kurang'], $hasil['ak_maxed']]);
    }

    public function testEndpointTargetKpIzinSamaDenganLihatAk(): void
    {
        $nip         = $this->pegawaiJf('III/b');
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);

        $res = $this->asUser($super)->get("api/v1/pegawai/{$nip}/ak/target-kp");
        $res->assertStatus(200);
        $this->assertSame([100.0, 0.0], [(float) $this->json($res)['data']['ak_for_kp'], (float) $this->json($res)['data']['current_ak']]);

        $this->asUser($super)->get('api/v1/pegawai/199912312099121999/ak/target-kp')->assertStatus(404);

        // Role 8 tidak punya izin lihat AK.
        [, , $pimpinan] = $this->aktor(Role::PIMPINAN);
        $this->asUser($pimpinan)->get("api/v1/pegawai/{$nip}/ak/target-kp")->assertStatus(403);

        // Role 2: hanya data sendiri dan hanya bila JF tertentu (group 2).
        [$nipSendiri, , $pegawai] = $this->aktor(Role::PEGAWAI);
        $this->asUser($pegawai)->get("api/v1/pegawai/{$nipSendiri}/ak/target-kp")->assertStatus(403);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nipSendiri)->update(['id_group_jabatan' => 2]);
        $this->asUser($pegawai)->get("api/v1/pegawai/{$nipSendiri}/ak/target-kp")->assertStatus(200);
        $this->asUser($pegawai)->get("api/v1/pegawai/{$nip}/ak/target-kp")->assertStatus(403);
    }

    // ---- Master dm_ak_jf ------------------------------------------------------------------------------------------------

    public function testMasterAkJfDaftarTambahUbahTanpaHapus(): void
    {
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $base        = 'api/v1/ak-jf';

        $this->assertSame([], $this->json($this->asUser($super)->get($base))['data'], 'master kosong');

        $opsi = $this->json($this->asUser($super)->get("{$base}/opsi"))['data'];
        $this->assertSame([2, 3], array_map('intval', array_column($opsi['group_jabatan'], 'id_group_jabatan')));
        $this->assertNotContains($this->pangkat['II/a'], array_map('intval', array_column($opsi['pangkat'], 'id_pangkat')), 'pangkat CPNS tidak ditawarkan');

        $jab = $this->json($this->asUser($super)->get("{$base}/jabatan?id_group_jabatan=2&id_sub_group_jabatan={$this->subGroupJf}&id_jenjang_jf={$this->jenjangPertama}"))['data'];
        $this->assertSame([$this->jabatanJf], array_map('intval', array_column($jab, 'id_jabatan')));
        $this->asUser($super)->get("{$base}/jabatan?id_group_jabatan=1&id_sub_group_jabatan=1&id_jenjang_jf=1")->assertStatus(422);

        $buat = $this->asUser($super)->withBodyFormat('json')->post($base, ['id_jabatan' => (string) $this->jabatanJf, 'id_pangkat' => (string) $this->pangkat['III/a'], 'ak' => '100.5']);
        $buat->assertStatus(201);
        $this->assertSame([100.5, 'Ahli Pertama', 'III/a'], [(float) $this->json($buat)['data']['ak'], $this->json($buat)['data']['jenjang_jf'], $this->json($buat)['data']['gol_ruang']]);
        $this->seeInDatabase('dm_ak_jf', ['id_jabatan' => $this->jabatanJf, 'id_pangkat' => $this->pangkat['III/a'], 'updated_by' => $super['id_pengguna']]);
        $this->seeInDatabase('audit_logs', ['entity' => 'dm_ak_jf', 'entity_id' => "{$this->jabatanJf}-{$this->pangkat['III/a']}", 'event' => 'create']);

        // Pasangan kembar → 422 pesan legacy.
        $kembar = $this->asUser($super)->withBodyFormat('json')->post($base, ['id_jabatan' => $this->jabatanJf, 'id_pangkat' => $this->pangkat['III/a'], 'ak' => '1']);
        $kembar->assertStatus(422);
        $this->assertStringContainsString('sudah terdaftar di sistem', $this->json($kembar)['errors']['id_pangkat'][0]);

        // Validasi: wajib, angka, jabatan JF aktif, pangkat aktif non-CPNS.
        $salah = $this->json($this->asUser($super)->withBodyFormat('json')->post($base, ['id_jabatan' => '', 'id_pangkat' => 'x', 'ak' => 'abc']))['errors'];
        $this->assertSame(['id_jabatan', 'id_pangkat', 'ak'], array_keys($salah));
        foreach (['-1', '1.2345', '12345678', ['1']] as $ak) {
            $this->asUser($super)->withBodyFormat('json')->post($base, ['id_jabatan' => $this->jabatanJf, 'id_pangkat' => $this->pangkat['III/b'], 'ak' => $ak])->assertStatus(422);
        }
        $bukanJf = $this->buatJabatan()['id_jabatan'];
        $this->assertArrayHasKey('id_jabatan', $this->json($this->asUser($super)->withBodyFormat('json')->post($base, ['id_jabatan' => $bukanJf, 'id_pangkat' => $this->pangkat['III/b'], 'ak' => '1']))['errors']);
        $this->assertArrayHasKey('id_pangkat', $this->json($this->asUser($super)->withBodyFormat('json')->post($base, ['id_jabatan' => $this->jabatanJf, 'id_pangkat' => $this->pangkat['II/a'], 'ak' => '1']))['errors']);

        // Ubah: hanya AK; jabatan/pangkat di payload diabaikan.
        $ubah = $this->asUser($super)->withBodyFormat('json')->put("{$base}/{$this->jabatanJf}/{$this->pangkat['III/a']}", ['ak' => 80, 'id_pangkat' => $this->pangkat['III/b']]);
        $ubah->assertStatus(200);
        $this->assertSame([80.0, $this->pangkat['III/a']], [(float) $this->json($ubah)['data']['ak'], $this->json($ubah)['data']['id_pangkat']]);
        $this->asUser($super)->withBodyFormat('json')->put("{$base}/{$this->jabatanJf}/{$this->pangkat['III/b']}", ['ak' => 1])->assertStatus(404);
        $this->asUser($super)->withBodyFormat('json')->put("{$base}/{$this->jabatanJf}/{$this->pangkat['III/a']}", ['ak' => ''])->assertStatus(422);

        // Tanpa hapus [K].
        $this->assertSame(404, $this->statusHapus("{$base}/{$this->jabatanJf}/{$this->pangkat['III/a']}", $super));
        $this->seeInDatabase('dm_ak_jf', ['id_jabatan' => $this->jabatanJf, 'id_pangkat' => $this->pangkat['III/a']]);
        $this->assertCount(1, $this->json($this->asUser($super)->get($base))['data']);
    }

    public function testMasterAkJfHanyaRole1(): void
    {
        foreach ([Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::PIMPINAN] as $role) {
            [, , $akun] = $this->aktor($role);
            $this->asUser($akun)->get('api/v1/ak-jf')->assertStatus(403);
            $this->asUser($akun)->withBodyFormat('json')->post('api/v1/ak-jf', ['id_jabatan' => $this->jabatanJf, 'id_pangkat' => $this->pangkat['III/a'], 'ak' => '1'])->assertStatus(403);
        }

        $this->dontSeeInDatabase('dm_ak_jf', ['id_jabatan' => $this->jabatanJf]);
    }

    /**
     * Master terisi lewat CRUD langsung dipakai AK kumulatif riwayat AK (Ak::turunan, legacy L_ak form() :357-405).
     */
    public function testMasterTerisiDipakaiAkKumulatif(): void
    {
        $nip         = $this->pegawaiJf('III/a');
        [, , $super] = $this->aktor(Role::SUPER_ADMIN);
        $this->assertSame(0.0, Ak::turunan($nip)['ak_kum_kp'], 'master kosong');

        foreach (['III/a' => '60', 'III/b' => '40'] as $gol => $ak) {
            $this->asUser($super)->withBodyFormat('json')->post('api/v1/ak-jf', ['id_jabatan' => $this->jabatanJf, 'id_pangkat' => $this->pangkat[$gol], 'ak' => $ak])->assertStatus(201);
        }

        $this->assertSame(60.0, Ak::turunan($nip)['ak_kum_kp'], 'KP: pangkat ≤ III/a');
    }

    private function pegawaiJf(string $gol): string
    {
        $nip = $this->buatPegawai([], ['id_jabatan' => $this->jabatanJf]);
        $this->db->table('pegawai_kp')->insert([
            'nip' => $nip, 'id_pangkat' => $this->pangkat[$gol], 'tmtsk' => '2020-01-01', 'tgl_sk' => '2019-12-01', 'jenis_kp' => 'Reguler',
            'gol' => 'III', 'ruang' => substr($gol, -1), 'gol_ruang' => $gol, 'pangkat' => 'Pangkat ' . $gol,
        ]);

        return $nip;
    }

    private function siasn(string $nip, int $idJabatan, float $kredit, ?string $konversi, ?string $integrasi): void
    {
        $this->db->table('riwayat_ak_siasn')->insert([
            'nip'         => $nip, 'id_jabatan' => $idJabatan, 'id_rw_siasn' => 'rw-' . bin2hex(random_bytes(6)), 'kredit_baru_total' => $kredit,
            'is_konversi' => $konversi, 'is_integrasi' => $integrasi, 'status' => 1,
        ]);
    }

    /**
     * @param array<string, mixed> $akun
     */
    private function statusHapus(string $path, array $akun): int
    {
        try {
            return $this->asUser($akun)->delete($path)->response()->getStatusCode();
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            return 404;
        }
    }
}
