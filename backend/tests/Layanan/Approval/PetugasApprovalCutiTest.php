<?php

declare(strict_types=1);

namespace Tests\Layanan\Approval;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Layanan\Cuti\PetugasApprovalCutiService;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * MAKE-022 M3 (PRD Fase 4 §6.4.8, QASMTASK-067) — aturan petugas approval cuti `pa_layanan`: validasi per kolom,
 * whitelist, role 3 hanya unit sendiri (tambah wajib unit sendiri), ubah hanya status 1, hapus fisik + audit,
 * resolusi petugas (jabatan struktural / pegawai tertaut).
 *
 * @internal
 */
final class PetugasApprovalCutiTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private function service(): PetugasApprovalCutiService
    {
        return new PetugasApprovalCutiService($this->db);
    }

    /**
     * @param array<string, mixed> $lebih
     *
     * @return array<string, mixed>
     */
    private function isian(array $lebih = []): array
    {
        $j = $this->buatJabatan();
        $u = $this->buatJabatan();

        return $lebih + [
            'id_jenis_pa'                  => 1, 'id_jenis_pegawai' => 1,
            'id_unit_pemohon'              => $j['id_unit'], 'id_group_jabatan_pemohon' => $j['id_group_jabatan'], 'id_sub_group_jabatan_pemohon' => $j['id_sub_group_jabatan'],
            'id_unit_petugas'              => $u['id_unit'], 'id_satker_petugas' => $u['id_satker'], 'id_group_jabatan_petugas' => $u['id_group_jabatan'],
            'id_sub_group_jabatan_petugas' => $u['id_sub_group_jabatan'], 'petugas' => 'struk_' . $u['id_jabatan'],
        ];
    }

    public function testTambahStrukturalMenulisBarisAktifDanMeresolusiPetugas(): void
    {
        $admin          = $this->buatPemohon(1);
        $isi            = $this->isian();
        $pej            = $this->buatPejabat(2);
        $isi['petugas'] = 'struk_' . $pej['id_jabatan'];

        $hasil = $this->service()->tambah($admin['auth'], $isi);

        $this->assertSame(1, (int) $hasil['status']);
        $this->assertSame($pej['id_jabatan'], (int) $hasil['id_jabatan']);
        $this->assertNull($hasil['nip']);
        $this->assertSame($pej['nip'], $hasil['petugas']['nip']);
        $this->seeInDatabase('audit_logs', ['entity' => 'pa_layanan', 'event' => 'create']);
    }

    public function testPetugasLainnyaMemakaiNipDanGolRuangUntukGroupJf(): void
    {
        $admin = $this->buatPemohon(1);
        $pej   = $this->buatPejabat(2);
        $j     = $this->buatJabatan();
        $this->db->table('group_jabatan')->where('id_group_jabatan', $j['id_group_jabatan'])->get();
        $isi = $this->isian(['petugas' => 'other_' . $pej['nip'], 'id_group_jabatan_pemohon' => 2, 'gol_ruang_pemohon' => 'III/a']);
        unset($isi['id_sub_group_jabatan_pemohon']);
        $this->db->table('group_jabatan')->ignore(true)->insert(['id_group_jabatan' => 2, 'group_jabatan' => 'JF', 'status' => 1]);

        $hasil = $this->service()->tambah($admin['auth'], $isi);

        $this->assertSame($pej['nip'], $hasil['nip']);
        $this->assertSame('III/a', $hasil['gol_ruang_pemohon']);
        $this->assertNull($hasil['id_sub_group_jabatan_pemohon']);
    }

    public function testValidasiKolomWajibDikumpulkanBersama(): void
    {
        $admin = $this->buatPemohon(1);

        try {
            $this->service()->tambah($admin['auth'], ['id_jenis_pegawai' => 3, 'petugas' => 'ngawur']);
            $this->fail('Harus 422');
        } catch (ValidationException $e) {
            foreach (['id_jenis_pa', 'id_jenis_pegawai', 'id_unit_pemohon', 'id_group_jabatan_pemohon', 'id_sub_group_jabatan_pemohon', 'id_unit_petugas', 'id_satker_petugas', 'petugas'] as $f) {
                $this->assertArrayHasKey($f, $e->getErrors() ?? [], $f);
            }
        }
    }

    public function testMassAssignmentDiabaikanStatusDipaksaSatu(): void
    {
        $admin = $this->buatPemohon(1);

        $hasil = $this->service()->tambah($admin['auth'], $this->isian(['status' => 10, 'id_pa_layanan' => 999, 'created_at' => '2000-01-01 00:00:00']));

        $this->assertSame(1, (int) $hasil['status']);
        $this->assertNotSame(999, (int) $hasil['id_pa_layanan']);
        $this->assertNotSame('2000-01-01 00:00:00', $hasil['created_at']);
    }

    public function testUbahHanyaBarisAktifDanHapusFisik(): void
    {
        $admin = $this->buatPemohon(1);
        $baris = $this->service()->tambah($admin['auth'], $this->isian());
        $id    = (int) $baris['id_pa_layanan'];
        $pej   = $this->buatPejabat(2);

        $ubah = $this->service()->ubah($admin['auth'], $id, $this->isian(['id_unit_pemohon' => $baris['id_unit_pemohon'], 'id_group_jabatan_pemohon' => $baris['id_group_jabatan_pemohon'], 'id_sub_group_jabatan_pemohon' => $baris['id_sub_group_jabatan_pemohon'], 'petugas' => 'struk_' . $pej['id_jabatan']]));
        $this->assertSame($pej['id_jabatan'], (int) $ubah['id_jabatan']);

        $this->db->table('pa_layanan')->where('id_pa_layanan', $id)->update(['status' => 2]);

        try {
            $this->service()->ubah($admin['auth'], $id, $this->isian());
            $this->fail('Baris nonaktif tidak boleh diubah');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors() ?? []);
        }

        $this->assertSame(['deleted' => true, 'id' => $id], $this->service()->hapus($admin['auth'], $id));
        $this->dontSeeInDatabase('pa_layanan', ['id_pa_layanan' => $id]);
        $this->seeInDatabase('audit_logs', ['entity' => 'pa_layanan', 'event' => 'delete']);
    }

    public function testRole3TambahWajibUnitSendiriDanEditHapusHanyaUnitSendiri(): void
    {
        $admin3 = $this->buatPejabat(3);
        $unit3  = (int) $this->db->table('pegawai_mutasi_jabatan')->where('nip', $admin3['nip'])->get()->getRowArray()['id_unit'];
        $this->db->table('pengguna')->where('id_pengguna', $admin3['id_pengguna'])->update(['id_unit' => (string) $unit3]);
        $auth3 = $this->authUntukAkun((int) $admin3['id_pengguna']);

        // Unit pemohon bukan unit sendiri -> 422.
        try {
            $this->service()->tambah($auth3, $this->isian());
            $this->fail('Unit lain harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('id_unit_pemohon', $e->getErrors() ?? []);
        }

        // Unit sendiri -> boleh; baris unit lain tidak terlihat dan tidak dapat dihapus (403).
        $j       = $this->buatJabatan((int) $this->db->table('pegawai_mutasi_jabatan')->where('nip', $admin3['nip'])->get()->getRowArray()['id_satker']);
        $sendiri = $this->service()->tambah($auth3, $this->isian(['id_unit_pemohon' => $unit3, 'id_group_jabatan_pemohon' => $j['id_group_jabatan'], 'id_sub_group_jabatan_pemohon' => $j['id_sub_group_jabatan']]));
        $admin   = $this->buatPemohon(1);
        $lain    = $this->service()->tambah($admin['auth'], $this->isian());

        $ids = array_column($this->service()->daftar($auth3, [])['items'], 'id_pa_layanan');
        $this->assertSame([(int) $sendiri['id_pa_layanan']], array_map('intval', $ids));
        $this->assertSame(2, $this->service()->daftar($admin['auth'], [])['total']);

        $this->expectException(ForbiddenException::class);
        $this->service()->hapus($auth3, (int) $lain['id_pa_layanan']);
    }

    public function testRoleSelainSatuDanTigaDitolak(): void
    {
        $p = $this->buatPemohon(2);

        $this->expectException(ForbiddenException::class);
        $this->service()->daftar($p['auth'], []);
    }
}
