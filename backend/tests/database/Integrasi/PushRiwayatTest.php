<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Constants\Role;
use App\Jobs\PushPesanJob;
use App\Jobs\PushRiwayatJob;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use App\Libraries\Push\AntrePushPesan;
use App\Libraries\Push\AntrePushRiwayat;
use App\Libraries\Push\MockFcmAdapter;
use App\Libraries\Push\PenerimaTokenFcm;
use CodeIgniter\Events\Events;
use CodeIgniter\Queue\Interfaces\QueueInterface;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-066 (H-04, F6-13; AC-04.4) — push web riwayat ikut `fcmQueue_rwy`/`fcmQueue_layanan` legacy [K `FH:389-915`]:
 * pelanggan event `riwayatNotifikasi` mengantre `push-riwayat` hanya untuk driver `fcm` dan jenis yang punya push di legacy;
 * job mengirim ke penerima legacy (admin pembawah per unit pmj, petugas Karpeg/Kariskarsu, pegawai), hanya token WEB akun
 * aktif; token tidak sah dihapus; semua kiriman gagal → dilempar (retry terbatas).
 *
 * @internal
 */
final class PushRiwayatTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    /** @var array<string, mixed> */
    private array $pushAsli;

    private MockFcmAdapter $mock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pushAsli = config(\Config\Integrations::class)->push;
        $this->mock     = new MockFcmAdapter();
        Services::injectMock('pushNotifGateway', $this->mock);
    }

    protected function tearDown(): void
    {
        config(\Config\Integrations::class)->push = $this->pushAsli;
        Services::reset(true);

        parent::tearDown();
    }

    public function testListenerEventHanyaDriverFcmDanJenisLegacy(): void
    {
        $payload = ['tipe' => RiwayatEngine::NOTIF_DIAJUKAN, 'jenis' => 'kp', 'nip' => '1', 'id' => 5, 'status' => 0, 'aksi' => null, 'reason_note' => null];

        Events::trigger(RiwayatEngine::EVENT_NOTIFIKASI, $payload);
        $this->assertSame([], $this->antrean(), 'driver mock tidak mengantre');

        config(\Config\Integrations::class)->push = ['driver' => 'fcm', 'projectId' => '', 'credentialsPath' => ''];
        Events::trigger(RiwayatEngine::EVENT_NOTIFIKASI, $payload);
        Events::trigger(RiwayatEngine::EVENT_NOTIFIKASI, ['jenis' => 'hukdis'] + $payload);
        Events::trigger(RiwayatEngine::EVENT_NOTIFIKASI, ['tipe' => RiwayatEngine::NOTIF_DIPROSES, 'aksi' => 'Disetujui'] + $payload);

        $this->assertSame([
            ['tipe' => 1, 'jenis' => 'kp', 'id' => 5, 'aksi' => null],
            ['tipe' => 2, 'jenis' => 'kp', 'id' => 5, 'aksi' => 'Disetujui'],
        ], $this->antrean(), 'hukdis tanpa push di legacy');

        (new AntrePushRiwayat(static fn (): QueueInterface => throw new RuntimeException('antrean mati'), 'fcm'))->antre(1, 'kp', 5);
        $this->addToAssertionCount(1);
    }

    public function testDiajukanKeAdminSatkerUnitPmjTanpaSatker(): void
    {
        $idUnit = $this->buatUnit();
        $nip    = $this->buatPegawai(['nama' => 'Budi', 'glr_awal' => 'Dr.', 'glr_akhir' => 'M.Si'], ['id_unit' => $idUnit, 'id_satker' => $this->buatSatker($idUnit)]);
        $this->web($this->buatAkunTanpaNip('adm.unit', Role::ADMIN_SATKER, ['id_unit' => (string) $idUnit, 'id_satker' => null]), 'tok-adm-unit');
        $this->web($this->buatAkunTanpaNip('adm.satker', Role::ADMIN_SATKER, ['id_unit' => (string) $idUnit, 'id_satker' => '99']), 'tok-adm-satker');
        $this->web($this->buatAkunTanpaNip('super', Role::SUPER_ADMIN, ['id_unit' => null, 'id_satker' => null]), 'tok-super');
        $this->web($this->buatAkunTanpaNip('adm.nonaktif', Role::ADMIN_SATKER, ['id_unit' => (string) $idUnit, 'id_satker' => null, 'status' => '0']), 'tok-nonaktif');
        $id = $this->organisasi($nip, ['nama_organisasi' => 'Korpri']);

        $this->assertSame(PushRiwayatJob::HASIL_TERKIRIM, (new PushRiwayatJob(['tipe' => 1, 'jenis' => 'organisasi', 'id' => $id]))->process());

        $this->assertSame(['tok-adm-unit'], array_column($this->mock->sent(), 'token'));
        $kirim = $this->mock->sent()[0];
        $this->assertSame(['Permohonan Riwayat Organisasi Baru', "Data: Korpri oleh Dr. Budi, M.Si / {$nip}", 1], [$kirim['title'], $kirim['body'], $kirim['platform']]);
        $this->assertSame("/pegawai/{$nip}/riwayat?tab=organisasi", $kirim['data']['link']);
    }

    public function testUnitLainDanUptIkutLegacy(): void
    {
        foreach ([PenerimaTokenFcm::UNIT_LAIN, PenerimaTokenFcm::UNIT_UPT] as $unit) {
            if ($this->db->table('unit')->where('id_unit', $unit)->countAllResults() === 0) {
                $this->buatUnit(['id_unit' => $unit]);
            }
        }

        $this->web($this->buatAkunTanpaNip('super', Role::SUPER_ADMIN, ['id_unit' => null, 'id_satker' => null]), 'tok-super');
        $lain = $this->buatPegawai([], ['id_unit' => PenerimaTokenFcm::UNIT_LAIN]);

        (new PushRiwayatJob(['tipe' => 3, 'jenis' => 'organisasi', 'id' => $this->organisasi($lain)]))->process();
        $this->assertSame([['tok-super', 'Perubahan Riwayat Organisasi']], array_map(static fn (array $k): array => [$k['token'], $k['title']], $this->mock->sent()));

        $satker = $this->buatSatker(PenerimaTokenFcm::UNIT_UPT);
        $upt    = $this->buatPegawai([], ['id_unit' => PenerimaTokenFcm::UNIT_UPT, 'id_satker' => $satker]);
        $this->web($this->buatAkunTanpaNip('adm.upt', Role::ADMIN_SATKER, ['id_unit' => (string) PenerimaTokenFcm::UNIT_UPT, 'id_satker' => (string) $satker]), 'tok-upt');
        $this->web($this->buatAkunTanpaNip('adm.upt.lain', Role::ADMIN_SATKER, ['id_unit' => (string) PenerimaTokenFcm::UNIT_UPT, 'id_satker' => (string) ($satker + 1000)]), 'tok-upt-lain');

        (new PushRiwayatJob(['tipe' => 1, 'jenis' => 'organisasi', 'id' => $this->organisasi($upt)]))->process();
        $this->assertSame('tok-upt', $this->mock->sent()[1]['token']);
        $this->assertCount(2, $this->mock->sent());
    }

    public function testDiprosesKePegawaiDenganKeteranganTolak(): void
    {
        $nip = $this->buatPegawai();
        $this->web($this->buatAkunUntuk($nip, Role::PEGAWAI), 'tok-pegawai');
        $this->db->table('firebase_token')->insert(['nip' => $nip, 'platform' => 2, 'fcm_token' => 'tok-android', 'token_hash' => hash('sha256', 'tok-android'), 'created_at' => '2026-11-04 00:00:00']);
        $id = $this->organisasi($nip, ['nama_organisasi' => 'Korpri', 'status' => 2, 'reason_note' => 'Berkas buram']);

        (new PushRiwayatJob(['tipe' => 2, 'jenis' => 'organisasi', 'id' => $id, 'aksi' => 'Ditolak']))->process();

        $this->assertSame([['tok-pegawai', 'Riwayat Organisasi Ditolak', 'Data: Korpri. Ket: Berkas buram']], array_map(static fn (array $k): array => [$k['token'], $k['title'], $k['body']], $this->mock->sent()), 'kanal web saja, tanpa "oleh"');
    }

    public function testKarpegKePetugasLayanan(): void
    {
        $nip     = $this->buatPegawai();
        $petugas = $this->buatPegawai();
        $this->db->table('petugas_layanan')->insert(['id_jenis_layanan' => 1, 'nip' => $petugas, 'status' => 1]);
        $this->web($this->buatAkunUntuk($petugas, Role::PEGAWAI), 'tok-petugas');
        $this->web($this->buatAkunTanpaNip('super', Role::SUPER_ADMIN, ['id_unit' => null, 'id_satker' => null]), 'tok-super');
        $this->db->table('riwayat_karpeg')->insert(['nip' => $nip, 'file_1' => 'a', 'file_2' => 'b', 'file_3' => 'c', 'file_4' => 'd', 'file_5' => 'e', 'status' => 0, 'created_at' => '2026-11-03 02:00:00']);

        (new PushRiwayatJob(['tipe' => 1, 'jenis' => 'karpeg', 'id' => (int) $this->db->insertID()]))->process();

        $this->assertSame(['tok-petugas'], array_column($this->mock->sent(), 'token'));
        $this->assertStringStartsWith('Data: Permohonan tanggal 3 November 2026 oleh ', $this->mock->sent()[0]['body']);
    }

    public function testTokenTidakSahDihapusDanSemuaGagalDilempar(): void
    {
        $nip = $this->buatPegawai();
        $this->web($this->buatAkunUntuk($nip, Role::PEGAWAI), 'tok-mati');
        Services::injectMock('pushNotifGateway', new class () extends MockFcmAdapter {
            public function sendMulticast(array $deviceTokens, string $title, string $body, array $data = []): array
            {
                return ['status_code' => '502', 'message' => 'UNREGISTERED', 'success_count' => 0, 'failure_count' => count($deviceTokens), 'invalid_tokens' => $deviceTokens];
            }
        });

        try {
            (new PushRiwayatJob(['tipe' => 2, 'jenis' => 'organisasi', 'id' => $this->organisasi($nip), 'aksi' => 'Disetujui']))->process();
            $this->fail('Semua gagal harus dilempar agar diulang worker.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('UNREGISTERED', $e->getMessage());
        }

        $this->assertSame(0, $this->db->table('firebase_token')->where('fcm_token', 'tok-mati')->countAllResults(), 'AC-04.2: token tidak sah dihapus');
        $this->assertSame(PushRiwayatJob::HASIL_TANPA_TOKEN, (new PushRiwayatJob(['tipe' => 2, 'jenis' => 'organisasi', 'id' => $this->organisasi($nip), 'aksi' => 'Disetujui']))->process());
        $this->assertSame(PushRiwayatJob::HASIL_DILEWATI, (new PushRiwayatJob(['tipe' => 2, 'jenis' => 'organisasi', 'id' => 999999]))->process());
        $this->assertSame(PushRiwayatJob::class, config(\Config\Queue::class)->jobHandlers[PushRiwayatJob::NAMA]);
    }

    public function testTitikSambungPushPesanPerKanal(): void
    {
        // Halo Simpeg (Fase 8) [K `L_support.php:1080`]: produsen generik, kanal Android bawaan.
        $nip = $this->buatPegawai();
        $this->web($this->buatAkunUntuk($nip, Role::PEGAWAI), 'tok-web');
        $this->db->table('firebase_token')->insert(['nip' => $nip, 'platform' => 2, 'fcm_token' => 'tok-android', 'token_hash' => hash('sha256', 'tok-android'), 'created_at' => '2026-11-04 00:00:00']);

        $antre = new AntrePushPesan();
        $this->assertTrue($antre->antre([$nip, $nip, ' '], 'Halo Simpeg', 'Pesan baru', ['id_inbox' => '7']));
        $this->assertFalse($antre->antre([$nip], 'Halo Simpeg', 'x', [], 'sms'), 'kanal tidak dikenal');
        $this->assertFalse($antre->antre([], 'Halo Simpeg', 'x'));
        $this->assertFalse((new AntrePushPesan(static fn (): QueueInterface => throw new RuntimeException('antrean mati')))->antre([$nip], 'J', 'I'));

        $job = $this->db->table('queue_jobs')->where('queue', 'push')->get()->getResultArray();
        $this->assertCount(1, $job);
        $data = (array) json_decode((string) $job[0]['payload'], true)['data'];
        $this->assertSame(['nip' => [$nip], 'judul' => 'Halo Simpeg', 'isi' => 'Pesan baru', 'data' => ['id_inbox' => '7'], 'kanal' => 'android'], $data);

        $this->assertSame(PushPesanJob::HASIL_TERKIRIM, (new PushPesanJob($data))->process());
        $this->assertSame([['tok-android', 2]], array_map(static fn (array $k): array => [$k['token'], $k['platform']], $this->mock->sent()));

        (new PushPesanJob(['kanal' => 'semua'] + $data))->process();
        $this->assertSame(['tok-web', 'tok-android'], array_column(array_slice($this->mock->sent(), 1), 'token'));
        $this->assertSame(PushPesanJob::HASIL_TANPA_TOKEN, (new PushPesanJob(['nip' => ['tidak-ada']] + $data))->process());
    }

    /**
     * @param array<string, mixed> $override
     */
    private function organisasi(string $nip, array $override = []): int
    {
        $this->db->table('riwayat_organisasi')->insert(array_merge(['nip' => $nip, 'nama_organisasi' => 'Organisasi Uji', 'status' => 0], $override));

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed>|int $akun baris akun atau id_pengguna
     */
    private function web(array|int $akun, string $token): void
    {
        $this->db->table('firebase_token')->insert(['nip' => null, 'id_pengguna' => is_array($akun) ? $akun['id_pengguna'] : $akun, 'platform' => 1, 'fcm_token' => $token, 'token_hash' => hash('sha256', $token), 'created_at' => '2026-11-04 00:00:00']);
    }

    /**
     * Data job `push-riwayat` di antrean `push`.
     *
     * @return list<array<string, mixed>>
     */
    private function antrean(): array
    {
        $hasil = [];

        foreach ($this->db->table('queue_jobs')->where('queue', 'push')->orderBy('id', 'ASC')->get()->getResultArray() as $r) {
            $p = (array) json_decode((string) $r['payload'], true);

            if (($p['job'] ?? null) === PushRiwayatJob::NAMA) {
                $hasil[] = (array) $p['data'];
            }
        }

        return $hasil;
    }
}
