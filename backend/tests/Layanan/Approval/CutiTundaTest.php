<?php

declare(strict_types=1);

namespace Tests\Layanan\Approval;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Layanan\Cuti\ResetTundaCuti;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * MAKE-022 M3 (PRD Fase 4 §6.4.7, AC-04.3/04.10, T17) — tunda pengingat: set flag tahap tanpa mengubah status, batas
 * `tgl_mulai` 12:00 inklusif (WIB), item hilang dari `pengingat` tetapi tetap di `persetujuan`, reset oleh listener
 * login hanya milik NIP itu dan hanya tahap/status yang berlaku, idempoten.
 *
 * @internal
 */
final class CutiTundaTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private function jam(string $waktu): \Closure
    {
        return static fn (): \DateTimeImmutable => new \DateTimeImmutable($waktu, new \DateTimeZone(\App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis::ZONA));
    }

    /**
     * @return array{id: int, a: array<string, mixed>}
     */
    private function ajukan(): array
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $id = (int) $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null)['id_riwayat_cuti'];

        return ['id' => $id, 'a' => $a];
    }

    public function testTundaTahapAlSetSkipAlTanpaMengubahStatusDanItemHilangDariPengingat(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $svc                     = $this->approvalService();

        $this->assertSame(1, $this->cutiService()->pengingat($a['al']['auth'])['total']);

        $hasil = $svc->tunda($a['al']['auth'], $id);

        $this->assertSame(0, (int) $hasil['status']);
        $this->assertSame(1, (int) $hasil['skip_al']);
        $this->assertSame(0, (int) $hasil['skip_pyb']);
        $this->assertSame(0, $this->cutiService()->pengingat($a['al']['auth'])['total']);
        $this->assertSame(1, $this->cutiService()->daftar($a['al']['auth'], ['mode' => 'persetujuan'])['total'], 'tetap ada di antrean persetujuan');
        $this->assertFalse($hasil['aksi']['tunda'], 'sudah ditunda');
    }

    public function testTundaTahapPybSetSkipPybPadaStatusTiga(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $svc                     = $this->approvalService();
        $svc->process($a['al']['auth'], $id, ['aksi' => 'setujui']);

        $hasil = $svc->tunda($a['pyb']['auth'], $id);

        $this->assertSame(3, (int) $hasil['status']);
        $this->assertSame(1, (int) $hasil['skip_pyb']);
        $this->assertSame(0, (int) $hasil['skip_al']);
    }

    public function testBatasJam12InklusifDanLewatDitolak(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan(); // tgl_mulai 2026-11-04

        $this->approvalService($this->jam('2026-11-04 12:00:00'))->tunda($a['al']['auth'], $id);
        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->update(['skip_al' => 0]);

        try {
            $this->approvalService($this->jam('2026-11-04 12:00:01'))->tunda($a['al']['auth'], $id);
            $this->fail('Lewat 12:00:00 harus 422');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Anda hanya dapat melakukan skip sebelum tanggal 2026-11-04 jam 12:00:00', $e->getErrors()['status'][0]);
        }

        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $id, 'skip_al' => 0, 'status' => 0]);
    }

    public function testTundaOlehBukanApproverAtauBukanTahapnya(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $svc                     = $this->approvalService();

        try {
            $svc->tunda($a['pemohon']['auth'], $id);
            $this->fail('Pemilik bukan approver');
        } catch (ForbiddenException) {
            $this->addToAssertionCount(1);
        }

        try {
            $svc->tunda($a['pyb']['auth'], $id);
            $this->fail('PyB belum giliran');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors() ?? []);
        }
    }

    public function testResetSaatLoginHanyaMilikNipDanTahapYangBerlaku(): void
    {
        $a     = $this->buatAlurDuaLangkah();
        $b     = $this->buatAlurDuaLangkah();
        $nipAl = $a['al']['nip'];

        $sebagaiAl      = $this->buatBarisCuti($a['pemohon']['nip'], ['nip_atasan_langsung' => $nipAl, 'nip_yang_menyetujui' => $a['pyb']['nip'], 'skip_al' => 1, 'skip_pyb' => 1]);
        $sebagaiPybTiga = $this->buatBarisCuti($b['pemohon']['nip'], ['status' => 3, 'nip_atasan_langsung' => $b['al']['nip'], 'nip_yang_menyetujui' => $nipAl, 'skip_al' => 1, 'skip_pyb' => 1]);
        $final          = $this->buatBarisCuti($b['pemohon']['nip'], ['status' => 1, 'tgl_mulai' => '2026-12-07', 'tgl_akhir' => '2026-12-08', 'nip_atasan_langsung' => $nipAl, 'nip_yang_menyetujui' => $nipAl, 'skip_al' => 1, 'skip_pyb' => 1]);
        $milikLain      = $this->buatBarisCuti($b['pemohon']['nip'], ['tgl_mulai' => '2026-12-14', 'tgl_akhir' => '2026-12-15', 'nip_atasan_langsung' => $b['al']['nip'], 'nip_yang_menyetujui' => $b['pyb']['nip'], 'skip_al' => 1, 'skip_pyb' => 1]);

        $auditSebelum = $this->db->table('audit_logs')->countAllResults();

        ResetTundaCuti::saatLogin(['id_pengguna' => 1, 'nip' => $nipAl]);

        // Status 0 sebagai AL: skip_al direset; skip_pyb bukan tahap NIP ini (bukan PyB) → tetap.
        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $sebagaiAl, 'skip_al' => 0, 'skip_pyb' => 1]);
        // Status 3 sebagai PyB: skip_pyb direset; skip_al (NIP ini bukan AL baris itu) tetap.
        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $sebagaiPybTiga, 'skip_al' => 1, 'skip_pyb' => 0]);
        // Status final dan baris NIP lain tidak disentuh; tanpa audit.
        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $final, 'skip_al' => 1, 'skip_pyb' => 1]);
        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $milikLain, 'skip_al' => 1, 'skip_pyb' => 1]);
        $this->assertSame($auditSebelum, $this->db->table('audit_logs')->countAllResults(), 'reset tanpa audit');

        // Idempoten; NIP kosong tidak menyentuh apa pun.
        ResetTundaCuti::saatLogin(['id_pengguna' => 1, 'nip' => $nipAl]);
        ResetTundaCuti::saatLogin(['id_pengguna' => 1, 'nip' => null]);
        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $milikLain, 'skip_al' => 1]);
    }

    public function testPengingatMunculLagiSetelahResetLogin(): void
    {
        ['id' => $id, 'a' => $a] = $this->ajukan();
        $this->approvalService()->tunda($a['al']['auth'], $id);
        $this->assertSame(0, $this->cutiService()->pengingat($a['al']['auth'])['total']);

        ResetTundaCuti::saatLogin(['id_pengguna' => $a['al']['id_pengguna'], 'nip' => $a['al']['nip']]);

        $this->assertSame(1, $this->cutiService()->pengingat($a['al']['auth'])['total']);
    }
}
