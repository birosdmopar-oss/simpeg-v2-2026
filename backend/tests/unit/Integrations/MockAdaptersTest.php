<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Interfaces\EsignGatewayInterface;
use App\Interfaces\PushNotifGatewayInterface;
use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Esign\MockEsignAdapter;
use App\Libraries\Push\MockFcmAdapter;
use App\Libraries\Siasn\MockSiasnAdapter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F0-15 / F0-16 / F0-17 — mock adapter bekerja tanpa network call.
 *
 * @internal
 */
final class MockAdaptersTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Guard: kalau ada adapter yang mencoba hit network, HTTP wrapper harus gagal keras.
        ini_set('default_socket_timeout', '1');
    }

    public function testServicesResolveMockDriversFromConfig(): void
    {
        $this->assertInstanceOf(MockEsignAdapter::class, service('esignGateway', false));
        $this->assertInstanceOf(MockSiasnAdapter::class, service('siasnGateway', false));
        $this->assertInstanceOf(MockFcmAdapter::class, service('pushNotifGateway', false));
    }

    // F0-15 — BSrE
    public function testEsignMockReturnsSignedDocumentWithoutNetwork(): void
    {
        $esign = new MockEsignAdapter();
        $this->assertInstanceOf(EsignGatewayInterface::class, $esign);

        $check = $esign->checkUser('198501012010011001');
        $this->assertSame('200', $check['status_code']);
        $this->assertTrue($check['has_certificate']);

        $pdf    = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF";
        $signed = $esign->signPdf($pdf, '198501012010011001', 'rahasia', ['page' => 1]);

        $this->assertSame('200', $signed['status_code']);
        $this->assertNotNull($signed['signed_pdf']);
        $this->assertStringStartsWith('%PDF-1.4', $signed['signed_pdf']);
        $this->assertStringEndsWith(MockEsignAdapter::MOCK_TRAILER, $signed['signed_pdf']);
        $this->assertNotNull($signed['signed_at']);
        $this->assertStringStartsWith('mock-', (string) $signed['signature_id']);
    }

    public function testEsignMockFailurePathsAreExplicitNotSilent(): void
    {
        $esign = new MockEsignAdapter(['000000000000000000']);

        $this->assertSame('404', $esign->checkUser('000000000000000000')['status_code']);
        $this->assertSame('401', $esign->signPdf('%PDF-1.4', '198501012010011001', '')['status_code']);
        $this->assertSame('400', $esign->signPdf('', '198501012010011001', 'x')['status_code']);
    }

    // F0-16 — SIASN
    public function testSiasnMockReturnsSyncDataWithoutNetwork(): void
    {
        $siasn = new MockSiasnAdapter();
        $this->assertInstanceOf(SiasnGatewayInterface::class, $siasn);

        $pegawai = $siasn->getPegawai('199002152015022002');
        $this->assertSame('200', $pegawai['status_code']);
        $this->assertSame('199002152015022002', $pegawai['data']['nip']);

        $riwayat = $siasn->getRiwayat('199002152015022002', 'jabatan');
        $this->assertSame('200', $riwayat['status_code']);
        $this->assertCount(2, $riwayat['data']);
        $this->assertSame('jabatan', $riwayat['data'][0]['jenis']);

        $sync = $siasn->syncPegawai('199002152015022002', ['nama' => 'Siti Nurhaliza']);
        $this->assertSame('200', $sync['status_code']);
        $this->assertNotNull($sync['synced_at']);
        $this->assertSame('Siti Nurhaliza', $sync['data']['nama']);
        $this->assertCount(1, $siasn->synced());
    }

    // F0-17 — FCM
    public function testFcmMockLogsPayloadLocallyWithoutNetwork(): void
    {
        $fcm = new MockFcmAdapter();
        $this->assertInstanceOf(PushNotifGatewayInterface::class, $fcm);

        $result = $fcm->send('fcm_dummy_token_siti', 'Cuti Disetujui', 'Pengajuan cuti Anda telah disetujui', ['id_cuti' => '3']);

        $this->assertSame('200', $result['status_code']);
        $this->assertStringStartsWith('mock-msg-', (string) $result['message_id']);
        $this->assertCount(1, $fcm->sent());
        $this->assertSame('Cuti Disetujui', $fcm->sent()[0]['title']);

        // Payload ter-log ke logger lokal.
        $this->assertLogContains('info', MockFcmAdapter::LOG_PREFIX . ' push payload=');
        $this->assertLogContains('info', 'fcm_dummy_token_siti');
    }

    public function testFcmMockMulticastReportsInvalidTokens(): void
    {
        $fcm    = new MockFcmAdapter();
        $result = $fcm->sendMulticast(['tok-a', '', 'tok-b'], 'Judul', 'Isi');

        $this->assertSame(2, $result['success_count']);
        $this->assertSame(1, $result['failure_count']);
        $this->assertSame([''], $result['invalid_tokens']);
    }
}
