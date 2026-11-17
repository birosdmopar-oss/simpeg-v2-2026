<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Constants\Role;
use App\Jobs\PushPesanJob;
use App\Libraries\Push\AntrePushPesan;
use App\Libraries\Push\MockFcmAdapter;
use Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * CR-137 (PRD Fase 8 v1.4 F8-13, H8-14 (a)) — `AntrePushPesan::antreAkun`: penerima push per `id_pengguna`; token web per akun
 * aktif (termasuk akun tanpa NIP, mis. role 1) dan token Android lewat `pengguna.nip`; token sama untuk dua penerima dikirim
 * sekali; akun nonaktif/terhapus dilewati; jalur `nip[]` lama tidak berubah (regresi di `PushRiwayatTest`, `PushAndroidCutiTest`).
 *
 * @internal
 */
final class PushPesanAkunTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private MockFcmAdapter $mock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockFcmAdapter();
        Services::injectMock('pushNotifGateway', $this->mock);
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        parent::tearDown();
    }

    public function testAntreAkunMemasangJobPerAkun(): void
    {
        $antre = new AntrePushPesan();
        $this->assertTrue($antre->antreAkun([5, 5, 0, -1, 7], 'Halo Simpeg', 'Pesan', ['modul' => 'halo-simpeg'], 'semua'));
        $this->assertFalse($antre->antreAkun([], 'Halo Simpeg', 'x'));
        $this->assertFalse($antre->antreAkun([5], 'Halo Simpeg', 'x', [], 'sms'));

        $job  = $this->db->table('queue_jobs')->where('queue', 'push')->get()->getResultArray();
        $data = (array) json_decode((string) $job[0]['payload'], true)['data'];
        $this->assertCount(1, $job);
        $this->assertSame(['akun' => [5, 7], 'judul' => 'Halo Simpeg', 'isi' => 'Pesan', 'data' => ['modul' => 'halo-simpeg'], 'kanal' => 'semua'], $data);
    }

    public function testJobAkunTokenWebTanpaNipAndroidLewatNipDanTokenSamaSekali(): void
    {
        // Akun role 1 tanpa NIP: hanya token web.
        $this->db->table('pengguna')->insert(['username' => 'admin.tanpa.nip', 'name' => 'Admin Tanpa NIP', 'user_level' => Role::SUPER_ADMIN, 'status' => '1']);
        $admin = (int) $this->db->insertID();
        $this->web($admin, 'tok-web-admin');

        // Akun pegawai: token web + Android lewat NIP.
        $nip     = $this->buatPegawai();
        $pegawai = $this->buatAkunUntuk($nip, Role::PEGAWAI);
        $this->web($pegawai, 'tok-web-pegawai');
        $this->db->table('firebase_token')->insert(['nip' => $nip, 'platform' => 2, 'fcm_token' => 'tok-android', 'token_hash' => hash('sha256', 'tok-android'), 'created_at' => '2026-11-04 00:00:00']);

        // Token web yang sama pada dua akun penerima → dikirim sekali.
        $lain = $this->buatAkunUntuk($this->buatPegawai(), Role::PTT);
        $this->web($lain, 'tok-web-pegawai');

        // Akun nonaktif dan terhapus dilewati (token web maupun Android).
        $nipMati = $this->buatPegawai();
        $mati    = $this->buatAkunUntuk($nipMati, Role::PEGAWAI, ['status' => '0']);
        $this->web($mati, 'tok-web-mati');
        $this->db->table('firebase_token')->insert(['nip' => $nipMati, 'platform' => 2, 'fcm_token' => 'tok-android-mati', 'token_hash' => hash('sha256', 'tok-android-mati'), 'created_at' => '2026-11-04 00:00:00']);
        $hapus = $this->buatAkunUntuk($this->buatPegawai(), Role::PEGAWAI, ['deleted_at' => '2026-11-01 00:00:00']);
        $this->web($hapus, 'tok-web-hapus');

        $data = ['akun' => [$admin, $pegawai, $lain, $mati, $hapus], 'judul' => 'Halo Simpeg', 'isi' => 'Pesan baru', 'data' => ['modul' => 'halo-simpeg', 'id_inbox' => '7', 'url' => '/halo-simpeg/tiket/7'], 'kanal' => 'semua'];
        $this->assertSame(PushPesanJob::HASIL_TERKIRIM, (new PushPesanJob($data))->process());

        $terkirim = array_map(static fn (array $k): array => [$k['token'], $k['platform']], $this->mock->sent());
        $this->assertEqualsCanonicalizing([['tok-web-admin', 1], ['tok-web-pegawai', 1], ['tok-android', 2]], $terkirim, 'tanpa ganda, tanpa akun mati/terhapus');

        $sebelum = count($this->mock->sent());
        (new PushPesanJob(['kanal' => 'web'] + $data))->process();
        $this->assertEqualsCanonicalizing(['tok-web-admin', 'tok-web-pegawai'], array_column(array_slice($this->mock->sent(), $sebelum), 'token'));

        $sebelum = count($this->mock->sent());
        (new PushPesanJob(['kanal' => 'android'] + $data))->process();
        $this->assertSame(['tok-android'], array_column(array_slice($this->mock->sent(), $sebelum), 'token'));

        $this->assertSame(PushPesanJob::HASIL_TANPA_TOKEN, (new PushPesanJob(['akun' => [$mati, 999999]] + $data))->process());
    }

    private function web(int $akun, string $token): void
    {
        $this->db->table('firebase_token')->insert(['nip' => null, 'id_pengguna' => $akun, 'platform' => 1, 'fcm_token' => $token, 'token_hash' => hash('sha256', $token), 'created_at' => '2026-11-04 00:00:00']);
    }
}
