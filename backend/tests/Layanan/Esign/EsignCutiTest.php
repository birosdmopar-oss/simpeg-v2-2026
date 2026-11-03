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
     * Penanda tangan (PyB) cuti terakhir — satu-satunya yang boleh menandatangani (F6-18 K1, keputusan Horii 03-11-2026).
     *
     * @return array<string, mixed>
     */
    private function admin(): array
    {
        return ['auth' => $this->penandaTerakhir];
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
            $this->esignService([$b['pyb']['nip']])->terbitkan($b['pyb']['auth'], $idGagal, ['passphrase' => self::PASSPHRASE_UJI], null, null);
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

    public function testHanyaPenandaTanganPemilikTugas403UntukLainnya(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $lain                    = [
            'super admin'                                                => $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), 1)),
            'admin satker'                                               => $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), 3)),
            'atasan langsung (bukan penanda tangan di alur dua langkah)' => $a['al']['auth'],
            'pemohon'                                                    => $a['pemohon']['auth'],
            'pegawai lain'                                               => $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), 2)),
        ];

        foreach ($lain as $nama => $auth) {
            try {
                $this->esignService()->terbitkan($auth, $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
                $this->fail("{$nama} harus ditolak.");
            } catch (ForbiddenException $e) {
                $this->assertSame(EsignCutiService::PESAN_BUKAN_PENANDA, $e->getMessage(), $nama);
            }
        }

        $this->assertSame(0, $this->db->table('esign_hist')->countAllResults(), 'penolakan tidak menulis percobaan');
    }

    public function testBatasLimaPercobaanGagalPer15MenitLalu429(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $svc                     = $this->esignService([$a['pyb']['nip']]);

        for ($i = 0; $i < EsignCutiService::BATAS_PERCOBAAN; $i++) {
            try {
                $svc->terbitkan($this->admin()['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
            } catch (ValidationException) {
                // percobaan gagal (sertifikat tidak aktif) dicatat esign_hist status 2
            }
        }

        try {
            $svc->terbitkan($this->admin()['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
            $this->fail('percobaan ke-6 harus 429');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame([429, EsignCutiService::KODE_TERKUNCI], [$e->getStatusCode(), $e->getKode()]);
            $this->assertStringNotContainsString(self::PASSPHRASE_UJI, $e->getMessage());
        }

        $this->assertSame(EsignCutiService::BATAS_PERCOBAAN, $this->db->table('esign_hist')->where('id_rw', $id)->countAllResults(), 'percobaan terkunci tidak sampai gateway');

        // Jendela 15 menit lewat → boleh mencoba lagi.
        $this->db->table('esign_hist')->where('id_rw', $id)->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 16 * 60)]);
        $this->esignService()->terbitkan($this->admin()['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
        $this->assertSame(1, $this->db->table('esign_hist')->where('id_rw', $id)->where('status', '1')->countAllResults());
    }

    public function testAksiEsignHanyaUntukPenandaTanganSesudahFinal(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $svc                     = $this->cutiService();
        $baris                   = $svc->ambil($id);

        $this->assertTrue($svc->aksi($a['pyb']['auth'], $baris, false, true)['esign'], 'PyB pemilik tugas');
        $this->assertFalse($svc->aksi($a['pyb']['auth'], $baris, true, true)['esign'], 'sudah ber-TTE');
        $this->assertFalse($svc->aksi($this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), 1)), $baris, false, true)['esign'], 'super admin tidak lagi');
        $this->assertFalse($svc->aksi($a['al']['auth'], $baris, false, true)['esign'], 'AL di alur dua langkah');

        // K3a: Tolak (status 2) tidak menghasilkan surat sehingga tidak meminta passphrase.
        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->update(['status' => 2, 'status_pyb' => 2]);
        $this->assertFalse($svc->aksi($a['pyb']['auth'], $svc->ambil($id), false, false)['esign']);
    }

    public function testQrTandaTanganBerisiTautanPdfBilaTersedia(): void
    {
        ['id' => $id] = $this->cutiFinal();
        $gateway      = new class () extends MockEsignAdapter {
            /** @var array<string, mixed> */
            public array $opsi = [];

            public function signPdf(string $pdfContent, string $nip, string $passphrase, array $options = []): array
            {
                $this->opsi = $options;

                return parent::signPdf($pdfContent, $nip, $passphrase, $options);
            }
        };
        $tautan = new class () implements \App\Interfaces\Layanan\TautanSuratCutiInterface {
            public function urlPdfTte(int $idCuti): string
            {
                return 'https://simpeg.contoh/surat-cuti/pdf/' . str_repeat('ab', 32);
            }
        };

        $this->esignService([], $gateway, $tautan)->terbitkan($this->admin()['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);

        $this->assertSame(['##', 'visible', '50', '50', 'Jakarta'], [$gateway->opsi['tag_koordinat'], $gateway->opsi['tampilan'], $gateway->opsi['width'], $gateway->opsi['height'], $gateway->opsi['location']]);
        $this->assertSame('Dokumen ini ditandatangani secara elektronik menggunakan Aplikasi SIMPEG Kemenpar', $gateway->opsi['reason']);

        if (extension_loaded('gd')) {
            $this->assertStringStartsWith("\x89PNG", (string) $gateway->opsi['imageTTD']);
            $this->assertSame([300, 300], array_slice((array) getimagesizefromstring((string) $gateway->opsi['imageTTD']), 0, 2));
        }

        $tanpa = new class () extends MockEsignAdapter {
            public ?bool $adaGambar = null;

            public function signPdf(string $pdfContent, string $nip, string $passphrase, array $options = []): array
            {
                $this->adaGambar = isset($options['imageTTD']);

                return parent::signPdf($pdfContent, $nip, $passphrase, $options);
            }
        };
        ['id' => $id2] = $this->cutiFinal();
        $this->esignService([], $tanpa)->terbitkan($this->admin()['auth'], $id2, ['passphrase' => self::PASSPHRASE_UJI], null, null);
        $this->assertFalse($tanpa->adaGambar, 'token belum tersedia (DBV-030) → tanpa gambar');
    }

    public function testBarisTidakAdaMengembalikan404(): void
    {
        $this->expectException(\App\Exceptions\NotFoundException::class);
        $this->esignService()->terbitkan($this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), 2)), 999999, ['passphrase' => self::PASSPHRASE_UJI], null, null);
    }

    public function testTanpaNipPejabatEselonIDitolak422KeyNip(): void
    {
        ['id' => $id] = $this->cutiFinal();
        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->update(['nip_yang_menyetujui' => null, 'nip_atasan_langsung' => null]);

        // F6-18 K1: tanpa penanda tangan tidak ada pemilik tugas → 403.
        $this->expectException(ForbiddenException::class);
        $this->esignService()->terbitkan($this->admin()['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
    }

    public function testLangkahTunggalTanpaPybMemakaiNipAtasanLangsung(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $this->db->table('pegawai')->where('nip', $a['al']['nip'])->update(['nik' => '3171000000000002']);
        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->update(['nip_yang_menyetujui' => null]);

        $this->esignService()->terbitkan($a['al']['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);

        $this->assertSame($a['al']['nip'], $this->db->table('esign_hist')->where('id_rw', $id)->get()->getRowArray()['nip']);
    }

    public function testSuratTerbitMengabariPemohonDanMengantrekanPush(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $this->pasangNotifikasi();

        $this->esignService()->terbitkan($this->admin()['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);

        $pesan = $this->db->table('inbox_msg')->select('inbox.judul, inbox_msg.status_sumber, inbox_msg.id_inbox_msg')->join('inbox', 'inbox.id_inbox = inbox_msg.id_inbox')->where('inbox.nip_penerima', $a['pemohon']['nip'])->get()->getResultArray();
        $this->assertCount(1, $pesan);
        $this->assertSame('Surat Cuti Online Terbit', $pesan[0]['judul']);
        $this->assertSame(1, (int) $pesan[0]['status_sumber']);
        $this->assertSame([(int) $pesan[0]['id_inbox_msg']], $this->diantrekan);
    }

    public function testGagalTidakMengabariPemohon(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $this->pasangNotifikasi();

        try {
            $this->esignService([$a['pyb']['nip']])->terbitkan($this->admin()['auth'], $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
        } catch (ValidationException) {
            // gagal gateway
        }

        $this->assertSame(0, $this->db->table('inbox')->countAllResults());
        $this->assertSame([], $this->diantrekan);
    }

    public function testAdapterDiambilDariLayananEsignGatewayDriverMockTanpaJaringan(): void
    {
        $this->assertInstanceOf(MockEsignAdapter::class, service('esignGateway', false));
        $this->assertSame('mock', config(\Config\Integrations::class)->esign['driver']);
    }
}
