<?php

declare(strict_types=1);

namespace Tests\Layanan\Esign;

use App\Exceptions\ValidationException;
use App\Libraries\Esign\BsreEsignAdapter;
use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\Log\IntegrasiLog;
use App\Libraries\Layanan\Cuti\CutiService;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\EsignUjiTrait;

/**
 * MAKE-065 (H-02) — regresi C-05 dengan adapter BSrE REAL + FakeTransport (AC-02.1): NIK penanda tangan dibaca dari
 * `pegawai.nik` (F6-17), surat ber-TTE tersimpan; gateway mati/timeout → `esign_hist` status 2 + `status_code` + pesan,
 * cuti tidak berubah (AC-02.2); passphrase & NIK tidak ada di `esign_hist`, `audit_logs`, `integrasi_log`, maupun berkas
 * log (AC-02.3).
 *
 * @internal
 */
final class EsignCutiBsreTest extends DatabaseTestCase
{
    use EsignUjiTrait;

    private const NIK_PYB = '3171000000000001';

    protected $seed = MasterDataSeeder::class;

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport();
    }

    public function testSuksesDenganAdapterRealMenyimpanPdfBerTte(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $this->transport->antreJson(200, ['status_code' => '1111', 'message' => 'Sertifikat aktif'])
            ->antre(new Respons(200, "%PDF-1.7\n% ber-tte bsre uji", ['id_dokumen' => 'DOK-1']));

        $this->esignService([], $this->bsre())->terbitkan($this->adminAuth(), $id, ['passphrase' => self::PASSPHRASE_UJI], '10.0.0.5', 'PHPUnit');

        $tte = $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_TTE, $id);
        $this->assertCount(1, $tte);
        $this->assertStringContainsString('ber-tte bsre uji', $this->lampiranUji->ambil($a['pemohon']['nip'], (int) $tte[0]['id_attachment'])['isi']);

        $cek = $this->transport->tercatat()[0]['permintaan'];
        $this->assertStringEndsWith('/api/user/status/' . self::NIK_PYB, $cek->url, 'NIK dari pegawai.nik PyB (F6-17)');
        $this->assertSame([self::NIK_PYB, self::PASSPHRASE_UJI], [$this->transport->tercatat()[1]['permintaan']->query['nik'], $this->transport->tercatat()[1]['permintaan']->query['passphrase']]);
        $this->assertSame('1', $this->db->table('esign_hist')->where('id_rw', $id)->get()->getRow()->status);
    }

    public function testGatewayTimeoutDicatatGagalDanCutiTidakBerubah(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $this->transport->antreJson(200, ['status_code' => '1111', 'message' => 'Sertifikat aktif'])
            ->antre(new GalatTransport('Operation timed out', true));

        try {
            $this->esignService([], $this->bsre())->terbitkan($this->adminAuth(), $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
            $this->fail('gagal gateway harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('passphrase', $e->getErrors());
        }

        $hist = $this->db->table('esign_hist')->where('id_rw', $id)->get()->getRowArray();
        $this->assertSame(['2', 'timeout'], [(string) $hist['status'], $hist['status_code']], 'AC-02.2');
        $this->assertSame([], $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_TTE, $id), 'tidak ada surat ber-TTE');
    }

    public function testPassphraseDanNikTidakBocorDiSemuaLog(): void
    {
        ['id' => $id] = $this->cutiFinal();
        $this->transport->antreJson(200, ['status_code' => '1111', 'message' => 'Sertifikat aktif'])
            ->antreJson(400, ['status_code' => '2031', 'error' => 'Passphrase ' . self::PASSPHRASE_UJI . ' salah untuk NIK ' . self::NIK_PYB]);

        try {
            $this->esignService([], $this->bsre())->terbitkan($this->adminAuth(), $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
            $this->fail('passphrase salah harus 422');
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString(self::PASSPHRASE_UJI, (string) json_encode($e->getErrors()));
            $this->assertStringNotContainsString(self::NIK_PYB, (string) json_encode($e->getErrors()));
        }

        $this->assertSame('2031', $this->db->table('esign_hist')->where('id_rw', $id)->get()->getRow()->status_code);
        $sumber = [
            'esign_hist'    => json_encode(array_map(static fn (array $r): array => array_diff_key($r, ['nik' => 1]), $this->db->table('esign_hist')->get()->getResultArray())),
            'audit_logs'    => json_encode($this->db->table('audit_logs')->get()->getResultArray()),
            'integrasi_log' => json_encode($this->db->table('integrasi_log')->get()->getResultArray()),
        ];

        foreach ($sumber as $nama => $isi) {
            $this->assertStringNotContainsString(self::PASSPHRASE_UJI, (string) $isi, "passphrase bocor ke {$nama}");
        }

        $this->assertStringNotContainsString(self::NIK_PYB, $sumber['integrasi_log'], 'NIK tidak masuk integrasi_log');

        foreach (glob(WRITEPATH . 'logs/*.log') ?: [] as $berkas) {
            $this->assertStringNotContainsString(self::PASSPHRASE_UJI, (string) file_get_contents($berkas), "passphrase bocor ke {$berkas}");
        }
    }

    private function bsre(): BsreEsignAdapter
    {
        return new BsreEsignAdapter(
            new KlienHttp($this->transport, new IntegrasiLog($this->db), tidur: static function (int $ms): void {
            }),
            ['driver' => 'bsre', 'baseUrl' => 'https://bsre.contoh', 'username' => 'klien-uji', 'password' => 'rahasia-uji', 'tlsVerify' => true, 'timeout' => 30],
        );
    }

    private function adminAuth(): \App\Libraries\Auth\AuthContext
    {
        return $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), 1));
    }
}
