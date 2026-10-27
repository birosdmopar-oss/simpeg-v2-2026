<?php

declare(strict_types=1);

namespace Tests\Layanan\Esign;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Libraries\Esign\MockEsignAdapter;
use App\Libraries\Layanan\Cuti\CutiService;
use App\Libraries\Layanan\Cuti\EsignCutiService;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\EsignUjiTrait;

/**
 * MAKE-023 M4 (PRD Fase 4 §6.5, AC-05.1/05.2/05.5/05.6) — alur `POST cuti/{id}/esign` lewat service: PDF belum TTE
 * dirender bila belum ada, gateway mock menandatangani, versi ber-TTE tersimpan terpisah, passphrase tidak bocor,
 * penerbitan kedua ditolak tanpa menimpa berkas ber-TTE.
 *
 * @internal
 */
final class EsignCutiTest extends DatabaseTestCase
{
    use EsignUjiTrait;

    protected $seed = MasterDataSeeder::class;

    /**
     * @return array<string, mixed>
     */
    private function admin(): array
    {
        return ['auth' => $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), 1))];
    }

    public function testSukses_MenyimpanPdfBerTteDenganKodeSuratTteDanTrailerMock(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $admin                   = $this->admin();

        $hasil = $this->esignService()->terbitkan($admin['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], '10.0.0.5', 'PHPUnit');

        $this->assertSame($id, (int) $hasil['id_riwayat_cuti']);

        $tte = $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_TTE, $id);
        $this->assertCount(1, $tte, 'AC-05.1: satu berkas ber-TTE');

        $isi = $this->lampiranUji->ambil($a['pemohon']['nip'], (int) $tte[0]['id_attachment'])['isi'];
        $this->assertStringStartsWith('%PDF', $isi);
        $this->assertStringContainsString(trim(MockEsignAdapter::MOCK_TRAILER), $isi);

        $this->assertCount(1, $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_BELUM_TTE, $id), 'versi belum TTE dibuat otomatis dan tetap ada');
    }

    public function testSukses_MencatatEsignHistBerhasilDanAuditTanpaPassphrase(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();

        $this->esignService()->terbitkan($this->admin()['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], '10.0.0.5', 'PHPUnit Agent');

        $hist = $this->db->table('esign_hist')->where('id_rw', $id)->get()->getResultArray();
        $this->assertCount(1, $hist);
        $this->assertSame('1', $hist[0]['status']);
        $this->assertSame($a['pyb']['nip'], $hist[0]['nip']);
        $this->assertSame('Cuti Online', $hist[0]['modul']);
        $this->assertSame('3171000000000001', $hist[0]['nik']);
        $this->assertSame('10.0.0.5', $hist[0]['ip_address']);
        $this->assertSame('PHPUnit Agent', $hist[0]['user_agent']);

        $audit = $this->db->table('audit_logs')->where('entity', 'riwayat_cuti')->where('entity_id', (string) $id)->get()->getResultArray();
        $this->assertNotEmpty(array_filter($audit, static fn (array $r): bool => str_contains((string) $r['after_json'], EsignCutiService::JENIS_AUDIT)));
    }

    public function testPassphraseTidakAdaDiEsignHistAuditMaupunLog(): void
    {
        ['id' => $id] = $this->cutiFinal();
        $admin        = $this->admin();

        $this->esignService()->terbitkan($admin['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);

        ['id' => $idGagal, 'a' => $b] = $this->cutiFinal(4);

        try {
            $this->esignService([$b['pyb']['nip']])->terbitkan($admin['auth'], $idGagal, ['passphrase' => self::PASSPHRASE_UJI], null, null);
        } catch (ValidationException) {
            // jalur gagal: percobaan dicatat tanpa passphrase
        }

        $this->assertStringNotContainsString(self::PASSPHRASE_UJI, json_encode($this->db->table('esign_hist')->get()->getResultArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::PASSPHRASE_UJI, json_encode($this->db->table('audit_logs')->get()->getResultArray(), JSON_THROW_ON_ERROR));

        foreach (glob(WRITEPATH . 'logs/*.log') ?: [] as $berkas) {
            $this->assertStringNotContainsString(self::PASSPHRASE_UJI, (string) file_get_contents($berkas), "passphrase bocor ke {$berkas}");
        }
    }

    public function testPenerbitanKeduaDitolakDanBerkasTteTidakTertimpa(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $admin                   = $this->admin();
        $svc                     = $this->esignService();

        $svc->terbitkan($admin['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
        $awal = $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_TTE, $id);

        try {
            $svc->terbitkan($admin['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
            $this->fail('Penerbitan kedua harus ditolak.');
        } catch (ValidationException $e) {
            $this->assertSame([EsignCutiService::PESAN_SUDAH_TTE], $e->getErrors()['status']);
        }

        $this->assertEquals($awal, $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_TTE, $id), 'AC-05.6: berkas ber-TTE tidak tertimpa');
        $this->assertSame(1, $this->db->table('esign_hist')->where('id_rw', $id)->countAllResults(), 'penolakan sebelum gateway tidak menulis percobaan');
    }

    public function testStatusSelainSatuAtauEmpatDitolak(): void
    {
        foreach ([0, 2, 3] as $status) {
            ['id' => $id] = $this->cutiFinal();
            $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->update(['status' => $status]);

            try {
                $this->esignService()->terbitkan($this->admin()['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
                $this->fail("Status {$status} harus ditolak.");
            } catch (ValidationException $e) {
                $this->assertSame([EsignCutiService::PESAN_STATUS], $e->getErrors()['status']);
            }
        }
    }

    public function testStatusEmpatDitangguhkanBolehDitandatangani(): void
    {
        ['id' => $id] = $this->cutiFinal(4);

        $this->esignService()->terbitkan($this->admin()['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);

        $this->assertSame('1', $this->db->table('esign_hist')->where('id_rw', $id)->get()->getRowArray()['status']);
    }

    public function testPassphraseKosongAtauBukanTeksDitolak422TanpaMenyentuhGateway(): void
    {
        ['id' => $id] = $this->cutiFinal();
        $admin        = $this->admin();

        foreach ([[], ['passphrase' => ''], ['passphrase' => '   '], ['passphrase' => ['x']], ['passphrase' => 123]] as $in) {
            try {
                $this->esignService()->terbitkan($admin['auth'], $id, $in, null, null);
                $this->fail('Passphrase tidak sah harus ditolak.');
            } catch (ValidationException $e) {
                $this->assertSame([EsignCutiService::PESAN_PASSPHRASE], $e->getErrors()['passphrase']);
            }
        }

        $this->assertSame(0, $this->db->table('esign_hist')->countAllResults());
    }

    public function testAdminSatkerDiLuarLingkupDitolak403(): void
    {
        ['id' => $id] = $this->cutiFinal();
        $satker       = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), 3));

        $this->expectException(ForbiddenException::class);
        $this->esignService()->terbitkan($satker, $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
    }

    public function testRoleSelainSatuDanTigaDitolak403(): void
    {
        ['id' => $id] = $this->cutiFinal();

        foreach ([2, 6, 7] as $role) {
            try {
                $this->esignService()->terbitkan($this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), $role)), $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
                $this->fail("Role {$role} harus ditolak.");
            } catch (ForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBarisTidakAdaMengembalikan404(): void
    {
        $this->expectException(\App\Exceptions\NotFoundException::class);
        $this->esignService()->terbitkan($this->admin()['auth'], 999999, ['passphrase' => self::PASSPHRASE_UJI], null, null);
    }

    public function testTanpaNipPejabatEselonIDitolak422KeyNip(): void
    {
        ['id' => $id] = $this->cutiFinal();
        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->update(['nip_yang_menyetujui' => null, 'nip_atasan_langsung' => null]);

        try {
            $this->esignService()->terbitkan($this->admin()['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
            $this->fail('Tanpa NIP pejabat harus ditolak.');
        } catch (ValidationException $e) {
            $this->assertSame([EsignCutiService::PESAN_PEJABAT], $e->getErrors()['nip']);
        }
    }

    public function testLangkahTunggalTanpaPybMemakaiNipAtasanLangsung(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $this->db->table('pegawai')->where('nip', $a['al']['nip'])->update(['nik' => '3171000000000002']);
        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->update(['nip_yang_menyetujui' => null]);

        $this->esignService()->terbitkan($this->admin()['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);

        $this->assertSame($a['al']['nip'], $this->db->table('esign_hist')->where('id_rw', $id)->get()->getRowArray()['nip']);
    }

    public function testAdapterDiambilDariLayananEsignGatewayDriverMockTanpaJaringan(): void
    {
        $this->assertInstanceOf(MockEsignAdapter::class, service('esignGateway', false));
        $this->assertSame('mock', config(\Config\Integrations::class)->esign['driver']);
    }
}
