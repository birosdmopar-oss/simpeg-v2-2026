<?php

declare(strict_types=1);

namespace Tests\Layanan\Approval;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * MAKE-022 M3 (PRD Fase 4 v1.3 §6.4.10, T14, AC-04.9) — proses atas nama oleh role 1: per tahap dan aksi, alasan wajib,
 * 403 non-role 1, 422 bila approver masih terjangkau, audit `jenis_audit = cuti.process_atas_nama`, keterangan, dan
 * pemohon eselon I (AL = PyB = Menteri, tanpa NIP).
 *
 * @internal
 */
final class CutiAtasNamaTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    /**
     * Pemohon dengan AL ber-role $roleAl (null = tanpa akun) dan PyB ber-role $rolePyb.
     *
     * @return array{id: int, a: array<string, mixed>}
     */
    private function ajukan(?int $roleAl, ?int $rolePyb): array
    {
        $al  = $this->buatPejabat($roleAl);
        $pyb = $this->buatPejabat($rolePyb);
        $p   = $this->buatPemohon(2, [], [], ['id_atasan_es_4' => $al['id_jabatan']]);
        $this->aturanPyb($p['jabatan'], $pyb['id_jabatan'], 'III/a');
        $this->buatSaldo($p['nip'], 2026);
        $id = (int) $this->cutiService()->tambah($p['auth'], $this->isianCuti($al['nip'], $pyb['nip']), null)['id_riwayat_cuti'];

        return ['id' => $id, 'a' => ['pemohon' => $p, 'al' => $al, 'pyb' => $pyb]];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditAtasNama(int $id): array
    {
        foreach ($this->db->table('audit_logs')->where('entity', 'riwayat_cuti')->where('entity_id', (string) $id)->get()->getResultArray() as $r) {
            $after = json_decode((string) $r['after_json'], true);

            if (is_array($after) && ($after['jenis_audit'] ?? null) === 'cuti.process_atas_nama') {
                return $after;
            }
        }

        $this->fail('Audit atas nama tidak ditemukan');
    }

    /**
     * @return iterable<string, array{?int, string}>
     */
    public static function approverTakTerjangkau(): iterable
    {
        yield 'tanpa akun' => [null, 'tanpa akun'];
        yield 'role 4' => [4, 'role'];
        yield 'role 5' => [5, 'role'];
        yield 'role 8' => [8, 'role'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('approverTakTerjangkau')]
    public function testAdminMemprosesTahapAlAtasNamaLaluTahapPyb(?int $roleAl, string $alasan): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan($roleAl, $roleAl);
        $admin                   = $this->buatPemohon(1);
        $svc                     = $this->approvalService();

        $t4 = $svc->process($admin['auth'], $id, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'Pejabat belum punya akun']);
        $this->assertSame(3, (int) $t4['status']);
        $this->assertStringContainsString('atas nama', (string) $t4['keterangan_petugas_approval']);
        $this->assertStringContainsString('oleh admin (', (string) $t4['keterangan_petugas_approval']);

        $audit = $this->auditAtasNama($id);
        $this->assertSame($alasan, $audit['alasan_tak_terjangkau']);
        $this->assertSame($a['al']['nip'], $audit['nip_approver']);
        $this->assertSame('Pejabat belum punya akun', $audit['reason_note']);

        $t8 = $svc->process($admin['auth'], $id, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'Tahap PyB juga tak terjangkau']);
        $this->assertSame(1, (int) $t8['status']);
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $a['pemohon']['nip'], 'sisa_cuti_n2' => 3]);
    }

    public function testAlasanWajibUntukSemuaAksiTermasukSetujui(): void
    {
        ['id' => $id] = $this->ajukan(null, null);
        $admin        = $this->buatPemohon(1);

        foreach (['setujui', 'tolak', 'tangguhkan'] as $aksi) {
            try {
                $this->approvalService()->process($admin['auth'], $id, ['aksi' => $aksi, 'atas_nama' => true]);
                $this->fail("{$aksi} tanpa alasan");
            } catch (ValidationException $e) {
                $this->assertSame('Alasan tindakan atas nama wajib diisi.', $e->getErrors()['reason_note'][0], $aksi);
            }
        }

        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $id, 'status' => 0]);
    }

    public function testTolakDanTangguhkanAtasNama(): void
    {
        ['id' => $id] = $this->ajukan(null, null);
        $admin        = $this->buatPemohon(1);

        $tolak = $this->approvalService()->process($admin['auth'], $id, ['aksi' => 'tolak', 'atas_nama' => true, 'reason_note' => 'Tidak memenuhi syarat']);
        $this->assertSame(2, (int) $tolak['status']);

        ['id' => $id2, 'a' => $b] = $this->ajukan(null, null);
        $tang                     = $this->approvalService()->process($admin['auth'], $id2, ['aksi' => 'tangguhkan', 'atas_nama' => true, 'reason_note' => 'Ditunda anggaran']);
        $this->assertSame(4, (int) $tang['status']);
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $b['pemohon']['nip'], 'sisa_cuti_ditangguhkan' => 3]);
    }

    public function testApproverMasihTerjangkauDitolak422(): void
    {
        ['id' => $id] = $this->ajukan(2, 2);
        $admin        = $this->buatPemohon(1);

        try {
            $this->approvalService()->process($admin['auth'], $id, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'Mencoba']);
            $this->fail('Approver terjangkau');
        } catch (ValidationException $e) {
            $this->assertSame('Pejabat tahap ini masih dapat memproses permohonan.', $e->getErrors()['status'][0]);
        }

        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $id, 'status' => 0]);
    }

    public function testRoleSelainSatuTidakBolehAtasNama(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan(null, null);
        $admin3                  = $this->buatPemohon(3);

        // Pemilik (terlihat) -> 403.
        try {
            $this->approvalService()->process($a['pemohon']['auth'], $id, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'x']);
            $this->fail('Harus 403');
        } catch (ForbiddenException) {
            $this->addToAssertionCount(1);
        }

        // Admin Satker di luar lingkup: baris tidak terlihat -> 404 (pemeriksaan terlihat mendahului 403, PRD 6.4.3).
        try {
            $this->approvalService()->process($admin3['auth'], $id, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'x']);
            $this->fail('Harus 404');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testAdminTanpaAtasNamaBukanApproverDitolak403(): void
    {
        ['id' => $id] = $this->ajukan(null, null);
        $admin        = $this->buatPemohon(1);

        $this->expectException(ForbiddenException::class);
        $this->approvalService()->process($admin['auth'], $id, ['aksi' => 'setujui']);
    }

    public function testPemohonEselonIDiselesaikanAdminAtasNamaMenteri(): void
    {
        $p = $this->buatPemohon(2, [], [], ['id_sub_group_jabatan' => $this->idSubGroupEselonI()]);
        $this->buatSaldo($p['nip'], 2026);

        $hasil = $this->cutiService()->tambah($p['auth'], $this->isianCuti(null, null), null);

        $this->assertNull($hasil['nip_atasan_langsung']);
        $this->assertNull($hasil['nip_yang_menyetujui']);
        $this->assertSame('Menunggu persetujuan Menteri (diproses admin)', $hasil['label_status']);

        $admin = $this->buatPemohon(1);
        $aksi  = $this->cutiService()->detail($admin['auth'], (int) $hasil['id_riwayat_cuti'])['aksi'];
        $this->assertTrue($aksi['proses_atas_nama']);
        $this->assertTrue($aksi['tangguhkan_atas_nama'], 'cuti tahunan boleh ditangguhkan atas nama');
        $this->assertFalse($this->cutiService()->detail($p['auth'], (int) $hasil['id_riwayat_cuti'])['aksi']['tangguhkan_atas_nama']);

        $selesai = $this->approvalService()->process($admin['auth'], (int) $hasil['id_riwayat_cuti'], ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'Persetujuan Menteri']);
        $this->assertSame(1, (int) $selesai['status']);
        $this->assertSame(1, (int) $selesai['status_al']);
        $this->assertSame(1, (int) $selesai['status_pyb']);
    }

    private function idSubGroupEselonI(): int
    {
        $ada = $this->db->table('sub_group_jabatan')->where('id_sub_group_jabatan', 1)->get()->getRowArray();

        if ($ada === null) {
            $idGroup = (int) $this->db->table('group_jabatan')->select('id_group_jabatan')->get()->getRowArray()['id_group_jabatan'];
            $this->db->table('sub_group_jabatan')->insert(['id_sub_group_jabatan' => 1, 'id_group_jabatan' => $idGroup, 'sub_group_jabatan' => 'Eselon I', 'status' => 1]);
        }

        return 1;
    }
}
