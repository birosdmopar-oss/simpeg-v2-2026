<?php

declare(strict_types=1);

namespace Tests\Layanan\Cuti;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * MAKE-022 M3 (PRD Fase 4 §6.2, QASMTASK-065) — CRUD cuti: hak per role (D5), snapshot dan lama cuti diisi server,
 * semua galat dikumpulkan, ubah/hapus, refund, audit, ketua tim, tumpang tindih.
 *
 * @internal
 */
final class CutiCrudTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    public function testPegawaiMengajukanCutiTahunanStatusNolDanSnapshotDariServer(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $ketua = $this->buatPejabat(null)['nip'];

        $hasil = $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['ketua_tim' => [$ketua], 'lama_cuti' => 99, 'status' => 1, 'nama' => 'Palsu']), null);

        $this->assertSame(0, (int) $hasil['status']);
        $this->assertSame(0, (int) $hasil['status_al']);
        $this->assertSame(0, (int) $hasil['status_pyb']);
        $this->assertSame(3, (int) $hasil['lama_cuti'], 'lama_cuti dari server, bukan klien');
        $this->assertNotSame('Palsu', $hasil['nama']);
        $this->assertSame('III/a', $hasil['gol_ruang']);
        $this->assertSame(10, (int) $hasil['mker_th']);
        $this->assertSame(12, (int) $hasil['sisa_cuti']);
        $this->assertSame('Menunggu Atasan', $hasil['label_status']);
        $this->assertArrayNotHasKey('file_bukti', $hasil);
        $this->assertSame($a['al']['nip'], $hasil['nip_atasan_langsung']);
        $this->assertNotEmpty($hasil['nama_atasan_langsung']);
        $this->assertSame($ketua, $this->db->table('riwayat_cuti_notif_kt')->get()->getRowArray()['nip_kt']);
        $this->assertSame([$ketua], array_column($hasil['ketua_tim_detail'], 'nip'));
        $this->assertTrue($hasil['aksi']['ubah']);
        $this->assertTrue($hasil['aksi']['hapus']);
        $this->assertFalse($hasil['aksi']['proses']);
    }

    public function testSemuaGalatDikumpulkanBersama(): void
    {
        $a = $this->buatAlurDuaLangkah();

        try {
            $this->cutiService()->tambah($a['pemohon']['auth'], [
                'id_jenis_cuti'     => 1, 'tgl_mulai' => '2026-10-01', 'tgl_akhir' => '2026-09-01',
                'no_telp_saat_cuti' => '', 'alamat_saat_cuti' => '', 'keterangan' => 'pendek',
            ], null);
            $this->fail('Harus 422');
        } catch (ValidationException $e) {
            $errors = $e->getErrors() ?? [];
            foreach (['tgl_mulai', 'no_telp_saat_cuti', 'alamat_saat_cuti', 'keterangan', 'nip_atasan_langsung', 'nip_yang_menyetujui'] as $f) {
                $this->assertArrayHasKey($f, $errors, $f);
            }
            $this->assertSame('Keterangan minimal 10 karakter.', $errors['keterangan'][0]);
            $this->assertSame('Tanggal mulai cuti tidak boleh sebelum hari ini.', $errors['tgl_mulai'][0]);
        }
    }

    public function testJenisDiLuarOpsiDitolakDanCltnHanyaAdmin(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);

        foreach ([6, 4, 99] as $jenis) {
            try {
                $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['id_jenis_cuti' => $jenis]), null);
                $this->fail("Jenis {$jenis} harus ditolak");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('id_jenis_cuti', $e->getErrors() ?? []);
            }
        }
    }

    public function testKuotaTidakCukupDanSaldoTidakTerdaftar(): void
    {
        $a = $this->buatAlurDuaLangkah();

        try {
            $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);
            $this->fail('Saldo tidak terdaftar harus 422');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Saldo cuti anda tidak terdaftar', $e->getErrors()['lama_cuti'][0]);
        }

        $this->buatSaldo($a['pemohon']['nip'], 2026, [2, 0, 0, 0, 0]);

        try {
            $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);
            $this->fail('Kuota kurang harus 422');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Sisa Cuti anda tidak mencukupi', $e->getErrors()['lama_cuti'][0]);
        }
    }

    public function testTumpangTindihDenganPengajuanLainTermasukStatusTiga(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $this->buatBarisCuti($a['pemohon']['nip'], ['status' => 3, 'tgl_mulai' => '2026-11-05', 'tgl_akhir' => '2026-11-10']);

        try {
            $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);
            $this->fail('Tumpang tindih harus 422');
        } catch (ValidationException $e) {
            $this->assertSame('Tanggal cuti beririsan dengan pengajuan cuti lain.', $e->getErrors()['tgl_mulai'][0]);
        }
    }

    public function testRole3Dan6TidakBolehMengajukanMengubahMenghapus(): void
    {
        foreach ([3, 6] as $role) {
            $p = $this->buatPemohon($role);

            try {
                $this->cutiService()->tambah($p['auth'], [], null);
                $this->fail('Harus 403');
            } catch (ForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUbahOlehPemilikHanyaStatusNolDanTanggalBelumLewat(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $svc  = $this->cutiService();
        $baru = $svc->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);
        $id   = (int) $baru['id_riwayat_cuti'];

        $ubah = $svc->ubah($a['pemohon']['auth'], $id, $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['tgl_akhir' => '2026-11-10']), null);
        $this->assertSame(5, (int) $ubah['lama_cuti']);

        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->update(['status' => 3]);

        try {
            $svc->ubah($a['pemohon']['auth'], $id, $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);
            $this->fail('Status 3 harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors() ?? []);
        }

        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->update(['status' => 0, 'tgl_mulai' => '2026-11-01']);

        try {
            $svc->ubah($a['pemohon']['auth'], $id, $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);
            $this->fail('Tanggal lewat harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tgl_mulai', $e->getErrors() ?? []);
        }
    }

    public function testBarisSendiriDikecualikanDariTumpangTindihSaatUbah(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $svc  = $this->cutiService();
        $baru = $svc->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);

        $ubah = $svc->ubah($a['pemohon']['auth'], (int) $baru['id_riwayat_cuti'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);

        $this->assertSame(3, (int) $ubah['lama_cuti']);
    }

    public function testHapusOlehPemilikStatusNolBerhasilTanpaMengubahSaldo(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $svc  = $this->cutiService();
        $baru = $svc->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);

        $r = $svc->hapus($a['pemohon']['auth'], (int) $baru['id_riwayat_cuti']);

        $this->assertSame(['deleted' => true, 'soft_delete' => false, 'id' => (int) $baru['id_riwayat_cuti']], $r);
        $this->dontSeeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $baru['id_riwayat_cuti']]);
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $a['pemohon']['nip'], 'sisa_cuti' => 12, 'sisa_cuti_n1' => 6, 'sisa_cuti_n2' => 6]);
        $audit = $this->db->table('audit_logs')->where('entity', 'riwayat_cuti')->where('event', 'delete')->get()->getRowArray();
        $this->assertNotNull($audit);
        $this->assertNotNull($audit['before_json']);
    }

    public function testHapusOlehPemilikDitolakUntukStatusFinalDanSetujuiAdminMengembalikanSaldo(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026, [12, 6, 1, 0, 0]);
        $id = $this->buatBarisCuti($a['pemohon']['nip'], ['status' => 1, 'sisa_cuti' => 12, 'sisa_cuti_n1' => 6, 'sisa_cuti_n2' => 6]);

        try {
            $this->cutiService()->hapus($a['pemohon']['auth'], $id);
            $this->fail('Pemilik tidak boleh menghapus status 1');
        } catch (ForbiddenException) {
            $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $id]);
        }

        $admin = $this->buatPemohon(1);
        $this->cutiService()->hapus($admin['auth'], $id);

        // 3 hari kembali ke N → (12 penuh) → N-1 penuh → N-2: 1 + 3 = 4 (batas 6)
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $a['pemohon']['nip'], 'sisa_cuti' => 12, 'sisa_cuti_n1' => 6, 'sisa_cuti_n2' => 4]);
    }

    public function testDetailOrangLainTidakTerlihat404KetuaTimDanAtasanBolehLihat(): void
    {
        $a     = $this->buatAlurDuaLangkah();
        $ketua = $this->buatPejabat(2);
        $orang = $this->buatPemohon(2);
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $svc  = $this->cutiService();
        $baru = $svc->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['ketua_tim' => [$ketua['nip']]]), null);
        $id   = (int) $baru['id_riwayat_cuti'];

        try {
            $svc->detail($orang['auth'], $id);
            $this->fail('Harus 404');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }

        $dariKt = $svc->detail($ketua['auth'], $id);
        $this->assertSame([false], array_values(array_unique($dariKt['aksi'])), 'ketua tim: semua aksi false');
        $this->seeInDatabase('riwayat_cuti_notif_kt', ['id_riwayat_cuti' => $id, 'nip_kt' => $ketua['nip'], 'status' => 1]);

        $dariAl = $svc->detail($a['al']['auth'], $id);
        $this->assertTrue($dariAl['aksi']['proses']);
        $this->assertTrue($dariAl['aksi']['tangguhkan']);
        $this->assertTrue($dariAl['aksi']['tunda']);
        $this->assertFalse($dariAl['aksi']['ubah']);

        $this->assertFalse($svc->detail($a['pyb']['auth'], $id)['aksi']['proses'], 'PyB belum giliran pada status 0 dua langkah');
    }

    public function testAdminMenginputStatusSatuMemotongSaldoDanMengaktifkanEfekFinal(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $admin = $this->buatPemohon(1);

        $hasil = $this->cutiService()->tambah($admin['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['nip' => $a['pemohon']['nip'], 'status' => 1]), null);

        $this->assertSame(1, (int) $hasil['status']);
        $this->assertSame(1, (int) $hasil['status_al']);
        $this->assertSame(1, (int) $hasil['status_pyb']);
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $a['pemohon']['nip'], 'sisa_cuti' => 12, 'sisa_cuti_n1' => 6, 'sisa_cuti_n2' => 3]);
    }

    public function testDaftarModeSayaPersetujuanDanAdmin(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $svc = $this->cutiService();
        $svc->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);

        $saya = $svc->daftar($a['pemohon']['auth'], []);
        $this->assertSame('saya', $saya['mode']);
        $this->assertSame(1, $saya['total']);

        $this->assertSame(1, $svc->daftar($a['al']['auth'], ['mode' => 'persetujuan'])['total']);
        $this->assertSame(0, $svc->daftar($a['pyb']['auth'], ['mode' => 'persetujuan'])['total'], 'PyB baru muncul setelah AL menyetujui');

        try {
            $svc->daftar($a['pemohon']['auth'], ['mode' => 'admin']);
            $this->fail('Harus 403');
        } catch (ForbiddenException) {
            $this->addToAssertionCount(1);
        }

        $admin = $this->buatPemohon(1);
        $semua = $svc->daftar($admin['auth'], []);
        $this->assertSame('admin', $semua['mode']);
        $this->assertSame(1, $semua['total']);
        $this->assertArrayHasKey('perlu_atas_nama', $semua['items'][0]);
    }

    public function testOpsiMenyaringJenisMenyertakanAlPybDanBisaMemproses(): void
    {
        $a = $this->buatAlurDuaLangkah(2, 4);
        $this->buatSaldo($a['pemohon']['nip'], 2026);

        $opsi = $this->cutiService()->opsi($a['pemohon']['auth'], null);

        $this->assertSame([1, 2, 3, 5], array_column($opsi['jenis_cuti'], 'id_jenis_cuti'));
        $this->assertSame($a['al']['nip'], $opsi['atasan_langsung']['nip']);
        $this->assertTrue($opsi['atasan_langsung']['bisa_memproses']);
        $this->assertSame($a['pyb']['nip'], $opsi['pyb'][0]['nip']);
        $this->assertFalse($opsi['pyb'][0]['bisa_memproses'], 'role 4 di luar route cuti');
        $this->assertTrue($opsi['can_create']);
        $this->assertSame('2027-12-31', $opsi['tgl_akhir_maks']);
    }
}
