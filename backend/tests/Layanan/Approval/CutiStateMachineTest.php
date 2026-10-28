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
 * MAKE-022 M3 (PRD Fase 4 §6.4.2–§6.4.5, QASMTASK-067) — state machine cuti lewat `CutiApprovalService`: T4–T10,
 * langkah tunggal, setiap transisi terlarang (422/403/404), efek final HANYA di 1/4 (saldo, CLTN, penerbit surat tepat
 * sekali), `status_pyb` tidak ditulis pada T4, keterangan petugas, kuota diulang saat final (rollback utuh).
 *
 * @internal
 */
final class CutiStateMachineTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    /**
     * @return array{id: int, a: array<string, mixed>}
     */
    private function ajukan(string $aksiJenis = 'tahunan'): array
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $id = (int) $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null)['id_riwayat_cuti'];

        return ['id' => $id, 'a' => $a];
    }

    private function saldo(string $nip): array
    {
        return $this->db->table('pegawai_sisa_cuti')->select('sisa_cuti, sisa_cuti_n1, sisa_cuti_n2, utang_cuti, sisa_cuti_ditangguhkan')->where('nip', $nip)->where('tahun', 2026)->get()->getRowArray();
    }

    public function testDuaLangkahAlSetujuiKe3TanpaEfekLaluPybSetujuiKe1DenganEfek(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $svc                     = $this->approvalService();
        $awal                    = $this->saldo($a['pemohon']['nip']);

        $t4 = $svc->process($a['al']['auth'], $id, ['aksi' => 'setujui']);

        $this->assertSame(3, (int) $t4['status']);
        $this->assertSame(1, (int) $t4['status_al']);
        $this->assertSame(0, (int) $t4['status_pyb'], 'T4: status_pyb tidak ditulis');
        $this->assertSame(1, (int) $t4['show_atasan']);
        $this->assertNull($t4['reason_note_al']);
        $this->assertStringContainsString('- Disetujui', (string) $t4['keterangan_petugas_approval']);
        $this->assertSame($awal, $this->saldo($a['pemohon']['nip']), 'tanpa efek final pada status 3');
        $this->assertSame(0, $this->penerbitUji()->dipanggil);
        $this->assertSame('Menunggu PyB', $t4['label_status']);

        $t8 = $svc->process($a['pyb']['auth'], $id, ['aksi' => 'setujui']);

        $this->assertSame(1, (int) $t8['status']);
        $this->assertSame(1, (int) $t8['status_pyb']);
        $this->assertSame(1, (int) $t8['show_pejabat']);
        $this->assertSame(['sisa_cuti' => '12', 'sisa_cuti_n1' => '6', 'sisa_cuti_n2' => '3', 'utang_cuti' => '0', 'sisa_cuti_ditangguhkan' => '0'], $this->saldo($a['pemohon']['nip']));
        $this->assertSame(1, $this->penerbitUji()->dipanggil, 'penerbit surat tepat sekali pada final');
        $this->assertCount(2, explode("\n", (string) $t8['keterangan_petugas_approval']), 'baris PyB ditambahkan dengan \n');
    }

    public function testTolakWajibAlasanDiMasingMasingTahap(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $svc                     = $this->approvalService();

        try {
            $svc->process($a['al']['auth'], $id, ['aksi' => 'tolak']);
            $this->fail('Tolak tanpa alasan harus 422');
        } catch (ValidationException $e) {
            $this->assertSame('Silahkan isi alasan penolakan jika menolak permohonan cuti online pegawai.', $e->getErrors()['reason_note'][0]);
        }

        $t5 = $svc->process($a['al']['auth'], $id, ['aksi' => 'tolak', 'reason_note' => 'Bentrok agenda']);
        $this->assertSame(2, (int) $t5['status']);
        $this->assertSame(2, (int) $t5['status_al']);
        $this->assertSame('Bentrok agenda', $t5['reason_note_al']);
        $this->assertSame('Bentrok agenda', $t5['reason_note']);
        $this->assertSame(12, (int) $this->saldo($a['pemohon']['nip'])['sisa_cuti']);
        $this->assertSame(0, $this->penerbitUji()->dipanggil);

        ['id' => $id2, 'a' => $b] = $this->ajukan();
        $svc->process($b['al']['auth'], $id2, ['aksi' => 'setujui']);
        $t9 = $svc->process($b['pyb']['auth'], $id2, ['aksi' => 'tolak', 'reason_note' => 'Kuota unit penuh']);
        $this->assertSame(2, (int) $t9['status']);
        $this->assertSame('Kuota unit penuh', $t9['reason_note_pyb']);
        $this->assertSame(0, $this->penerbitUji()->dipanggil, 'tolak tidak menjalankan efek final');
    }

    public function testAlTangguhkanDari0Ke4DenganEfekDitangguhkan(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();

        $t6 = $this->approvalService()->process($a['al']['auth'], $id, ['aksi' => 'tangguhkan']);

        $this->assertSame(4, (int) $t6['status']);
        $this->assertSame(4, (int) $t6['status_al']);
        // T-12 kasus K12: saldo (12,6,6) − 3 hari → (12,6,3) dan ditangguhkan += 3.
        $this->assertSame(['sisa_cuti' => '12', 'sisa_cuti_n1' => '6', 'sisa_cuti_n2' => '3', 'utang_cuti' => '0', 'sisa_cuti_ditangguhkan' => '3'], $this->saldo($a['pemohon']['nip']));
        $this->assertSame(1, $this->penerbitUji()->dipanggil);
    }

    public function testTransisiTerlarangMenghasilkan422(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $svc                     = $this->approvalService();

        foreach ([['pyb', 'PyB memproses status 0 dua langkah']] as [$pelaku, $ket]) {
            try {
                $svc->process($a[$pelaku]['auth'], $id, ['aksi' => 'setujui']);
                $this->fail($ket);
            } catch (ValidationException $e) {
                $this->assertSame('Permohonan cuti tidak dalam tahap yang dapat Anda proses.', $e->getErrors()['status'][0], $ket);
            }
        }

        $svc->process($a['al']['auth'], $id, ['aksi' => 'setujui']);

        try {
            $svc->process($a['al']['auth'], $id, ['aksi' => 'setujui']);
            $this->fail('AL memproses status 3');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors() ?? []);
        }

        $svc->process($a['pyb']['auth'], $id, ['aksi' => 'setujui']);

        foreach (['al', 'pyb'] as $pelaku) {
            try {
                $svc->process($a[$pelaku]['auth'], $id, ['aksi' => 'setujui']);
                $this->fail('Proses ulang status final');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->getErrors() ?? []);
            }
        }

        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $id, 'status' => 1]);
    }

    public function testAksiTidakDikenalDanTangguhkanBukanTahunan(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $svc                     = $this->approvalService();

        try {
            $svc->process($a['al']['auth'], $id, ['aksi' => 'hapus']);
            $this->fail('Aksi tidak dikenal');
        } catch (ValidationException $e) {
            $this->assertSame('Silahkan setujui, tolak, atau tangguhkan permohonan cuti online pegawai.', $e->getErrors()['aksi'][0]);
        }

        $sakit = $this->buatBarisCuti($a['pemohon']['nip'], ['id_jenis_cuti' => 3, 'nama_jenis_cuti' => 'Cuti Sakit', 'nip_atasan_langsung' => $a['al']['nip'], 'nip_yang_menyetujui' => $a['pyb']['nip'], 'tgl_mulai' => '2026-12-07', 'tgl_akhir' => '2026-12-08']);

        try {
            $svc->process($a['al']['auth'], $sakit, ['aksi' => 'tangguhkan']);
            $this->fail('Tangguhkan hanya jenis 1');
        } catch (ValidationException $e) {
            $this->assertSame('Penangguhan hanya berlaku untuk cuti tahunan.', $e->getErrors()['aksi'][0]);
        }
    }

    public function testBukanApproverTerlihat403DanTakTerlihat404(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $svc                     = $this->approvalService();
        $orang                   = $this->buatPemohon(2);

        try {
            $svc->process($orang['auth'], $id, ['aksi' => 'setujui']);
            $this->fail('Tidak terlihat → 404');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }

        try {
            $svc->process($a['pemohon']['auth'], $id, ['aksi' => 'setujui']);
            $this->fail('Pemilik terlihat tetapi bukan approver → 403');
        } catch (ForbiddenException) {
            $this->addToAssertionCount(1);
        }

        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $id, 'status' => 0]);
    }

    public function testRoleBerapaPunyaYangMenjadiApproverBolehMemproses(): void
    {
        $al  = $this->buatPejabat(7);
        $pyb = $this->buatPejabat(3);
        $p   = $this->buatPemohon(2, [], [], ['id_atasan_es_4' => $al['id_jabatan']]);
        $this->aturanPyb($p['jabatan'], $pyb['id_jabatan'], 'III/a');
        $this->buatSaldo($p['nip'], 2026);
        $id = (int) $this->cutiService()->tambah($p['auth'], $this->isianCuti($al['nip'], $pyb['nip']), null)['id_riwayat_cuti'];

        $svc = $this->approvalService();
        $this->assertSame(3, (int) $svc->process($al['auth'], $id, ['aksi' => 'setujui'])['status']);
        $this->assertSame(1, (int) $svc->process($pyb['auth'], $id, ['aksi' => 'setujui'])['status']);
    }

    public function testLangkahTunggalAlSamaDenganPybDanAlKosong(): void
    {
        foreach (['sama', 'kosong'] as $varian) {
            $pyb = $this->buatPejabat(2);
            $p   = $this->buatPemohon(2);
            $this->buatSaldo($p['nip'], 2026);
            $id = $this->buatBarisCuti($p['nip'], [
                'nip_atasan_langsung'  => $varian === 'sama' ? $pyb['nip'] : null,
                'nip_yang_menyetujui'  => $pyb['nip'],
                'nama_yang_menyetujui' => 'Pejabat',
            ]);

            $hasil = $this->approvalService()->process($pyb['auth'], $id, ['aksi' => 'setujui']);

            $this->assertSame(1, (int) $hasil['status'], $varian);
            $this->assertSame(1, (int) $hasil['status_al'], $varian);
            $this->assertSame(1, (int) $hasil['status_pyb'], $varian);
        }
    }

    public function testKuotaDiulangSaatFinalDanRollbackUtuh(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $svc                     = $this->approvalService();
        $svc->process($a['al']['auth'], $id, ['aksi' => 'setujui']);

        // Saldo habis karena hal lain sebelum PyB memutuskan (T12).
        $this->db->table('pegawai_sisa_cuti')->where('nip', $a['pemohon']['nip'])->update(['sisa_cuti' => 1, 'sisa_cuti_n1' => 0, 'sisa_cuti_n2' => 0]);

        try {
            $svc->process($a['pyb']['auth'], $id, ['aksi' => 'setujui']);
            $this->fail('Kuota kurang harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lama_cuti', $e->getErrors() ?? []);
        }

        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $id, 'status' => 3, 'status_pyb' => 0]);
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $a['pemohon']['nip'], 'sisa_cuti' => 1]);
        $this->assertSame(0, $this->penerbitUji()->dipanggil);
    }

    public function testEfekFinalCltnMengubahStatusPegawaiHanyaBilaBerjalanHariIni(): void
    {
        $pyb      = $this->buatPejabat(2);
        $p        = $this->buatPemohon(2, ['id_jenis_status' => 1]);
        $berjalan = $this->buatBarisCuti($p['nip'], [
            'id_jenis_cuti'       => 6, 'nama_jenis_cuti' => 'Cuti di Luar Tanggungan Negara', 'tgl_mulai' => '2026-11-02', 'tgl_akhir' => '2027-11-01',
            'nip_yang_menyetujui' => $pyb['nip'],
        ]);
        $this->approvalService()->process($pyb['auth'], $berjalan, ['aksi' => 'setujui']);
        $this->seeInDatabase('pegawai', ['nip' => $p['nip'], 'id_jenis_status' => 3]);

        $q    = $this->buatPemohon(2, ['id_jenis_status' => 1]);
        $esok = $this->buatBarisCuti($q['nip'], [
            'id_jenis_cuti'       => 6, 'nama_jenis_cuti' => 'Cuti di Luar Tanggungan Negara', 'tgl_mulai' => '2026-11-03', 'tgl_akhir' => '2027-11-02',
            'nip_yang_menyetujui' => $pyb['nip'],
        ]);
        $this->approvalService()->process($pyb['auth'], $esok, ['aksi' => 'setujui']);
        $this->seeInDatabase('pegawai', ['nip' => $q['nip'], 'id_jenis_status' => 1]);
    }

    public function testSetujuiJenisLainTidakMemotongSaldoTetapMenerbitkanSurat(): void
    {
        $pyb = $this->buatPejabat(2);
        $p   = $this->buatPemohon(2);
        $this->buatSaldo($p['nip'], 2026);
        $id = $this->buatBarisCuti($p['nip'], ['id_jenis_cuti' => 3, 'nama_jenis_cuti' => 'Cuti Sakit', 'nip_yang_menyetujui' => $pyb['nip']]);

        $this->approvalService()->process($pyb['auth'], $id, ['aksi' => 'setujui']);

        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $p['nip'], 'sisa_cuti' => 12, 'sisa_cuti_n1' => 6, 'sisa_cuti_n2' => 6]);
        $this->assertSame(1, $this->penerbitUji()->dipanggil);
    }

    public function testRiwayatAuditUpdateTercatatUntukSetiapTransisi(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $svc                     = $this->approvalService();
        $svc->process($a['al']['auth'], $id, ['aksi' => 'setujui']);
        $svc->process($a['pyb']['auth'], $id, ['aksi' => 'setujui']);

        $audit = $this->db->table('audit_logs')->where('entity', 'riwayat_cuti')->where('entity_id', (string) $id)->where('event', 'update')->get()->getResultArray();
        $this->assertGreaterThanOrEqual(2, count($audit));
    }
}
