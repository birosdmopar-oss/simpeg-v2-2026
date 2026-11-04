<?php

declare(strict_types=1);

namespace Tests\Layanan\Notifikasi;

use App\Interfaces\PushNotifGatewayInterface;
use App\Jobs\PushNotifLayananJob;
use App\Libraries\Layanan\NotifikasiService;
use App\Libraries\Push\MockFcmAdapter;
use CodeIgniter\Config\Services;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-023 M4 (PRD Fase 4 §6.6.3, AC-06.3) — job `push-notif`: tanpa token → `push_status` 3; sukses → 1 dengan payload
 * `{title, body, data: {modul, id_rwy, url}}` ke `MockFcmAdapter`; gagal → 2 dan dilempar (retry sampai TRIES, lalu
 * `queue_jobs_failed`); token tidak valid dihapus; idempoten; pesan tak ada dilewati. Job dijalankan langsung (`process()`),
 * sama dengan yang dilakukan worker; tidak ada koneksi DB kedua sehingga tidak perlu `db-isolasi-penuh`.
 *
 * @internal
 */
final class PushNotifJobTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    protected $seed = MasterDataSeeder::class;

    protected function tearDown(): void
    {
        Services::resetSingle('pushNotifGateway');
        parent::tearDown();
    }

    private function pesanBaru(string $nip): int
    {
        $ids = (new NotifikasiService($this->db, static fn (int $id): bool => true))->kirim('cuti', 12, 2, [$nip], 'Cuti Online Disetujui', 'Pengajuan cuti anda disetujui.', null, 1, '/layanan/cuti/12');

        return $ids[0];
    }

    private function tambahToken(string $nip, string $token): void
    {
        // DBV-027: token ber-NIP = Android (platform 2, CHECK pemilik), unik per (nip, token_hash).
        $this->db->table('firebase_token')->insert(['nip' => $nip, 'platform' => 2, 'fcm_token' => $token, 'token_hash' => hash('sha256', $token), 'created_at' => '2026-11-02 00:00:00']);
    }

    private function pushStatus(int $id): int
    {
        return (int) $this->db->table('inbox_msg')->where('id_inbox_msg', $id)->get()->getRowArray()['push_status'];
    }

    public function testTanpaTokenMenandaiStatusTigaTanpaGalat(): void
    {
        $id  = $this->pesanBaru($this->buatPegawai());
        $job = new PushNotifLayananJob(['id_inbox_msg' => $id]);

        $this->assertSame(PushNotifLayananJob::HASIL_TANPA_TOKEN, $job->process());
        $this->assertSame(3, $this->pushStatus($id));
    }

    public function testSuksesMengirimPayloadKeMockFcmDanMenandaiTerkirim(): void
    {
        $nip  = $this->buatPegawai();
        $mock = new MockFcmAdapter();
        Services::injectMock('pushNotifGateway', $mock);
        $this->tambahToken($nip, 'token-uji-1');
        $this->tambahToken($nip, 'token-uji-2');
        $id = $this->pesanBaru($nip);

        $this->assertSame(PushNotifLayananJob::HASIL_TERKIRIM, (new PushNotifLayananJob(['id_inbox_msg' => $id]))->process());

        $this->assertSame(1, $this->pushStatus($id));
        $this->assertCount(2, $mock->sent());
        $kirim = $mock->sent()[0];
        $this->assertSame('Cuti Online Disetujui', $kirim['title']);
        $this->assertSame('Pengajuan cuti anda disetujui.', $kirim['body']);
        $this->assertSame(['modul' => 'cuti', 'id_rwy' => '12', 'url' => '/layanan/cuti/12'], $kirim['data']);
        $this->assertStringNotContainsStringIgnoringCase('nik', json_encode($kirim, JSON_THROW_ON_ERROR));
    }

    public function testSudahTerkirimTidakDikirimUlangDanPesanTakAdaDilewati(): void
    {
        $nip  = $this->buatPegawai();
        $mock = new MockFcmAdapter();
        Services::injectMock('pushNotifGateway', $mock);
        $this->tambahToken($nip, 'token-uji');
        $id = $this->pesanBaru($nip);

        (new PushNotifLayananJob(['id_inbox_msg' => $id]))->process();
        $this->assertSame(PushNotifLayananJob::HASIL_DILEWATI, (new PushNotifLayananJob(['id_inbox_msg' => $id]))->process());
        $this->assertCount(1, $mock->sent());

        $this->assertSame(PushNotifLayananJob::HASIL_DILEWATI, (new PushNotifLayananJob(['id_inbox_msg' => 999999]))->process());
    }

    public function testGagalMelemparGalatAgarWorkerMengulangDanMenandaiGagal(): void
    {
        $nip = $this->buatPegawai();
        Services::injectMock('pushNotifGateway', new class () extends MockFcmAdapter {
            public function sendMulticast(array $deviceTokens, string $title, string $body, array $data = []): array
            {
                return ['status_code' => '500', 'message' => 'FCM down (mock)', 'success_count' => 0, 'failure_count' => count($deviceTokens), 'invalid_tokens' => []];
            }
        });
        $this->tambahToken($nip, 'token-uji');
        $id = $this->pesanBaru($nip);

        try {
            (new PushNotifLayananJob(['id_inbox_msg' => $id]))->process();
            $this->fail('Gagal kirim harus dilempar agar dicoba ulang.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('FCM down', $e->getMessage());
        }

        $this->assertSame(2, $this->pushStatus($id));
        $this->assertSame(PushNotifLayananJob::TRIES, (new PushNotifLayananJob([]))->getTries(), 'tidak retry selamanya (H-04)');
        $this->assertLessThanOrEqual(5, (new PushNotifLayananJob([]))->getTries());
    }

    public function testTokenTidakValidDihapusDanSisanyaTetapTerkirim(): void
    {
        $nip = $this->buatPegawai();
        Services::injectMock('pushNotifGateway', new class () extends MockFcmAdapter {
            public function sendMulticast(array $deviceTokens, string $title, string $body, array $data = []): array
            {
                return ['status_code' => '200', 'message' => 'ok', 'success_count' => 1, 'failure_count' => 1, 'invalid_tokens' => ['token-mati']];
            }
        });
        $this->tambahToken($nip, 'token-hidup');
        $this->tambahToken($nip, 'token-mati');
        $id = $this->pesanBaru($nip);

        (new PushNotifLayananJob(['id_inbox_msg' => $id]))->process();

        $this->assertSame(1, $this->pushStatus($id));
        $sisa = array_column($this->db->table('firebase_token')->where('nip', $nip)->get()->getResultArray(), 'fcm_token');
        $this->assertSame(['token-hidup'], $sisa);
    }

    public function testTokenWebAkunPenerimaIkutDikirimiPerPlatform(): void
    {
        // MAKE-066 (F6-13): token web (platform 1, nip NULL, pemilik = akun) milik akun ber-NIP penerima ikut menerima push.
        $nip  = $this->buatPegawai();
        $mock = new MockFcmAdapter();
        Services::injectMock('pushNotifGateway', $mock);
        $idAkun = $this->buatAkunUntuk($nip, \App\Constants\Role::PEGAWAI);
        $this->tambahToken($nip, 'token-android');
        $this->db->table('firebase_token')->insert(['nip' => null, 'id_pengguna' => $idAkun, 'platform' => 1, 'fcm_token' => 'token-web', 'token_hash' => hash('sha256', 'token-web'), 'created_at' => '2026-11-04 00:00:00']);
        $nonaktif = $this->buatPegawai();
        $this->db->table('firebase_token')->insert(['nip' => null, 'id_pengguna' => $this->buatAkunUntuk($nonaktif, \App\Constants\Role::PEGAWAI, ['status' => '0']), 'platform' => 1, 'fcm_token' => 'token-web-nonaktif', 'token_hash' => hash('sha256', 'token-web-nonaktif'), 'created_at' => '2026-11-04 00:00:00']);
        $id = $this->pesanBaru($nip);

        $this->assertSame(PushNotifLayananJob::HASIL_TERKIRIM, (new PushNotifLayananJob(['id_inbox_msg' => $id]))->process());

        $this->assertSame([['token-web', 1], ['token-android', 2]], array_map(static fn (array $k): array => [$k['token'], $k['platform']], $mock->sent()));
        $this->assertSame(PushNotifLayananJob::HASIL_TANPA_TOKEN, (new PushNotifLayananJob(['id_inbox_msg' => $this->pesanBaru($nonaktif)]))->process(), 'akun nonaktif tidak dikirimi');
    }

    public function testJobTerdaftarDiKonfigurasiQueue(): void
    {
        $this->assertSame(PushNotifLayananJob::class, config(\Config\Queue::class)->jobHandlers[NotifikasiService::JOB]);
        $this->assertInstanceOf(PushNotifGatewayInterface::class, service('pushNotifGateway', false));
    }
}
