<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg;

use App\Constants\Role;
use App\Jobs\PushPesanJob;
use App\Libraries\CacheService;
use App\Libraries\HaloSimpeg\TicketNotifService;
use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Push\FcmV1Adapter;
use App\Libraries\Push\MockFcmAdapter;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use Config\Services;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\HaloSimpegFixtureTrait;
use Tests\Support\Integrasi\KunciRsaUji;
use Tests\Support\Integrasi\LogIntegrasiTiruan;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-072 — notifikasi push tiket (PRD Fase 8 v1.4 §6.4, §7.6, AC-E06.1..AC-E06.7, F8-13..F8-16, H8-14 (a)). Job `push-pesan`
 * diperiksa dari `queue_jobs` antrean `push`; pengiriman lewat `MockFcmAdapter` dan `FcmV1Adapter` + `FakeTransport` (CI tanpa
 * layanan nyata, AC-E06.4).
 *
 * @internal
 */
final class TiketNotifTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use HaloSimpegFixtureTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/halo-simpeg/';

    protected $seed = AuthSeeder::class;

    private int $k1;

    private int $k2;

    private int $pemilik;

    /** Petugas role 1 tanpa NIP (AC-E06.7). */
    private int $adminTanpaNip;

    /** Petugas role 6 (AC-E06.7). */
    private int $ptt;

    private int $petugasK2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->pasangThrottlerHs();
        $this->k1      = $this->buatKategoriHs('Kepegawaian');
        $this->k2      = $this->buatKategoriHs('Gaji');
        $this->pemilik = $this->akunPegawai('Pelapor Notif', Role::PEGAWAI);
        $this->db->table('pengguna')->insert(['username' => 'admin.helpdesk', 'name' => 'Admin Helpdesk', 'user_level' => Role::SUPER_ADMIN, 'status' => '1']);
        $this->adminTanpaNip = (int) $this->db->insertID();
        $this->ptt           = $this->akunPegawai('Petugas PTT', Role::PTT);
        $this->petugasK2     = $this->akunPegawai('Petugas Gaji', Role::ADMIN_SATKER);
        $this->buatPetugasHs($this->adminTanpaNip, [$this->k1]);
        $this->buatPetugasHs($this->ptt, [$this->k1]);
        $this->buatPetugasHs($this->petugasK2, [$this->k2]);

        // Bukan penjawab: petugas nonaktif, akun nonaktif, akun role 8 (S12).
        $this->buatPetugasHs($this->akunPegawai('Petugas Nonaktif', Role::ADMIN_SATKER), [$this->k1], 0);
        $mati = $this->akunPegawai('Akun Mati', Role::ADMIN_SATKER);
        $this->buatPetugasHs($mati, [$this->k1]);
        $this->db->table('pengguna')->where('id_pengguna', $mati)->update(['status' => '0']);
        $this->buatPetugasHs($this->akunPegawai('Akun Pimpinan', Role::PIMPINAN), [$this->k1]);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testTiketBaruBalasanDanTutupMengantreKePihakLawan(): void
    {
        $inbox     = [$this->db->table('inbox')->countAllResults(), $this->db->table('inbox_msg')->countAllResults()];
        $petugasK1 = [$this->adminTanpaNip, $this->ptt];
        sort($petugasK1);

        // AC-E06.1: tiket baru → semua petugas aktif kategori itu saja.
        $id = $this->json($this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => 'Cuti', 'msg' => 'Tanya cuti']))['data']['id'];
        $this->assertSame([['akun' => $petugasK1, 'isi' => 'Tanya cuti']], $this->antreanBaru());

        // AC-E06.2: balasan petugas → pemilik; balasan pemilik → petugas aktif kategori; pelaku tidak menerima.
        $this->sebagai($this->ptt)->post(self::URL . "tiket/{$id}/pesan", ['msg' => 'Jawab'])->assertStatus(201);
        $this->assertSame([['akun' => [$this->pemilik], 'isi' => 'Jawab']], $this->antreanBaru());
        $this->sebagai($this->pemilik)->post(self::URL . "tiket/{$id}/pesan", ['msg' => 'Terima kasih'])->assertStatus(201);
        $this->assertSame([['akun' => $petugasK1, 'isi' => 'Terima kasih']], $this->antreanBaru());

        // F8-14: tutup → pihak lawan.
        $this->sebagai($this->ptt)->post(self::URL . "tiket/{$id}/tutup")->assertStatus(200);
        $this->assertSame([['akun' => [$this->pemilik], 'isi' => TicketNotifService::ISI_DITUTUP]], $this->antreanBaru());
        $id2 = $this->json($this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => 'Dua', 'msg' => 'Pesan dua']))['data']['id'];
        $this->antreanBaru();
        $this->sebagai($this->pemilik)->post(self::URL . "tiket/{$id2}/tutup")->assertStatus(200);
        $this->assertSame([['akun' => $petugasK1, 'isi' => TicketNotifService::ISI_DITUTUP]], $this->antreanBaru());

        $this->assertSame($inbox, [$this->db->table('inbox')->countAllResults(), $this->db->table('inbox_msg')->countAllResults()], 'AC-E06.6 / §7.8 (3)');
    }

    public function testPetugasYangPemilikTidakMenerimaPushBuatannyaSendiri(): void
    {
        $id = $this->json($this->sebagai($this->ptt)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => 'Milik petugas', 'msg' => 'Halo']))['data']['id'];
        $this->assertSame([['akun' => [$this->adminTanpaNip], 'isi' => 'Halo']], $this->antreanBaru(), 'pelaku (petugas k1) dikecualikan');
        $this->sebagai($this->adminTanpaNip)->post(self::URL . "tiket/{$id}/pesan", ['msg' => 'Jawab'])->assertStatus(201);
        $this->assertSame([['akun' => [$this->ptt], 'isi' => 'Jawab']], $this->antreanBaru());
    }

    public function testTiketNullTanpaPetugasPenerima(): void
    {
        $t = $this->buatTiketHs(null, $this->pemilik);
        $this->sebagai($this->pemilik)->post(self::URL . "tiket/{$t}/tutup")->assertStatus(200);
        $this->assertSame([], $this->antreanBaru());
    }

    public function testPayloadTerbatasTanpaDataPribadi(): void
    {
        $panjang = 'Rahasia ' . str_repeat('kata ', 60) . 'akhir';
        $id      = $this->json($this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => 'Payload', 'msg' => $panjang]))['data']['id'];
        $job     = $this->antrean();
        $this->assertCount(1, $job);
        [$nama, $data] = $job[0];

        $this->assertSame(PushPesanJob::NAMA, $nama);
        $this->assertSame(['akun', 'judul', 'isi', 'data', 'kanal'], array_keys($data));
        $this->assertSame([TicketNotifService::JUDUL, 'semua'], [$data['judul'], $data['kanal']]);
        $this->assertSame(['modul' => 'halo-simpeg', 'id_inbox' => (string) $id, 'url' => "/halo-simpeg/tiket/{$id}", 'link' => "/halo-simpeg/tiket/{$id}"], $data['data']);
        $this->assertSame(101, mb_strlen($data['isi']), 'isi terpotong 100 karakter + elipsis');
        $this->assertStringNotContainsString('akhir', $data['isi']);

        $t     = $this->db->table('disiplin_ticket')->where('id_inbox', $id)->get()->getRowArray();
        $semua = (string) json_encode($data);

        foreach (array_filter([(string) $t['nip'], (string) $t['jabatan'], (string) $t['satker']]) as $pribadi) {
            $this->assertStringNotContainsString($pribadi, $semua, 'AC-E06.5');
        }
    }

    public function testGagalAntreTidakMenggagalkanPesan(): void
    {
        Services::injectMock('queue', new class () {
            public function push(): never
            {
                throw new RuntimeException('antrean mati');
            }
        });

        $res = $this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => 'Tetap', 'msg' => 'Tersimpan']);
        $res->assertStatus(201);
        $id = $this->json($res)['data']['id'];
        $this->sebagai($this->ptt)->post(self::URL . "tiket/{$id}/pesan", ['msg' => 'Balas'])->assertStatus(201);
        $this->assertSame(2, $this->db->table('disiplin_ticket_msg')->where('id_inbox', $id)->countAllResults(), 'AC-E06.3');
    }

    /**
     * AC-E06.4 + AC-E06.7 dengan MockFcmAdapter: petugas role 1 tanpa NIP menerima token web; petugas role 6 menerima web +
     * Android lewat NIP.
     */
    public function testJobTerkirimKePetugasRole1TanpaNipDanRole6(): void
    {
        $mock = new MockFcmAdapter();
        Services::injectMock('pushNotifGateway', $mock);
        $this->tokenWeb($this->adminTanpaNip, 'tok-web-admin');
        $this->tokenWeb($this->ptt, 'tok-web-ptt');
        $this->tokenAndroid($this->ptt, 'tok-android-ptt');

        $this->sebagai($this->pemilik)->post(self::URL . 'tiket', ['id_question_category' => $this->k1, 'subject' => 'Kirim', 'msg' => 'Isi'])->assertStatus(201);
        $this->assertSame(PushPesanJob::HASIL_TERKIRIM, (new PushPesanJob($this->antrean()[0][1]))->process());
        $this->assertEqualsCanonicalizing(['tok-web-admin', 'tok-web-ptt', 'tok-android-ptt'], array_column($mock->sent(), 'token'));
    }

    /**
     * AC-E06.3 + AC-E06.4 dengan FcmV1Adapter + FakeTransport: token UNREGISTERED dihapus, token lain terkirim; semua gagal →
     * job melempar agar worker mencoba ulang terbatas (`TRIES`).
     */
    public function testFcmV1TokenTidakSahDihapusDanRetryTerbatas(): void
    {
        $this->tokenWeb($this->adminTanpaNip, 'tok-web-sah');
        $this->tokenWeb($this->ptt, 'tok-web-mati');
        $galat     = ['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]];
        $transport = (new FakeTransport())
            ->antreJson(200, ['access_token' => 'akses-uji', 'expires_in' => 3599])
            ->antreJson(200, ['name' => 'projects/proyek-uji/messages/1'])
            ->antreJson(404, $galat);
        $this->pasangFcm($transport);

        $data = ['akun' => [$this->adminTanpaNip, $this->ptt], 'judul' => 'Halo Simpeg', 'isi' => 'Isi', 'data' => ['modul' => 'halo-simpeg', 'id_inbox' => '1', 'url' => '/halo-simpeg/tiket/1', 'link' => '/halo-simpeg/tiket/1'], 'kanal' => 'web'];
        $this->assertSame(PushPesanJob::HASIL_TERKIRIM, (new PushPesanJob($data))->process());
        $this->assertSame(0, $this->db->table('firebase_token')->where('fcm_token', 'tok-web-mati')->countAllResults(), 'token UNREGISTERED dihapus');
        $this->assertSame(1, $this->db->table('firebase_token')->where('fcm_token', 'tok-web-sah')->countAllResults());

        $this->pasangFcm((new FakeTransport())->antreJson(200, ['access_token' => 'akses-uji', 'expires_in' => 3599])->antreJson(500, ['error' => ['code' => 500, 'status' => 'INTERNAL', 'message' => 'galat']]));
        $this->expectException(RuntimeException::class);
        $this->assertSame(3, PushPesanJob::TRIES);
        (new PushPesanJob(['akun' => [$this->adminTanpaNip]] + $data))->process();
    }

    /**
     * Job push-pesan baru sejak awal test: [{akun, isi}], lalu antrean dikosongkan.
     *
     * @return list<array{akun: list<int>, isi: string}>
     */
    private function antreanBaru(): array
    {
        $hasil = array_map(static function (array $j): array {
            $akun = $j[1]['akun'];
            sort($akun);

            return ['akun' => $akun, 'isi' => (string) $j[1]['isi']];
        }, $this->antrean());
        $this->db->table('queue_jobs')->where('queue', 'push')->delete();

        return $hasil;
    }

    /**
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function antrean(): array
    {
        return array_map(static function (array $r): array {
            $p = (array) json_decode((string) $r['payload'], true);

            return [(string) $p['job'], (array) $p['data']];
        }, $this->db->table('queue_jobs')->where('queue', 'push')->orderBy('id', 'ASC')->get()->getResultArray());
    }

    private function tokenWeb(int $akun, string $token): void
    {
        $this->db->table('firebase_token')->insert(['nip' => null, 'id_pengguna' => $akun, 'platform' => 1, 'fcm_token' => $token, 'token_hash' => hash('sha256', $token), 'created_at' => '2026-11-17 00:00:00']);
    }

    private function tokenAndroid(int $akun, string $token): void
    {
        $nip = (string) $this->db->table('pengguna')->where('id_pengguna', $akun)->get()->getRow('nip');
        $this->db->table('firebase_token')->insert(['nip' => $nip, 'platform' => 2, 'fcm_token' => $token, 'token_hash' => hash('sha256', $token), 'created_at' => '2026-11-17 00:00:00']);
    }

    private function pasangFcm(FakeTransport $transport): void
    {
        $klien = new KlienHttp($transport, new LogIntegrasiTiruan(), tidur: static function (int $ms): void {
        });
        Services::injectMock('klienHttp', $klien);
        Services::injectMock('pushNotifGateway', new FcmV1Adapter(
            $klien,
            new CacheService(new MockCache(), 'u_'),
            ['projectId' => 'proyek-uji', 'credentialsPath' => '/di/luar/repo/sa.json'],
            static fn (): string => (string) json_encode(['client_email' => 'sa-uji@proyek-uji.contoh', 'private_key' => KunciRsaUji::pasangan()['privat'], 'token_uri' => 'https://oauth2.googleapis.com/token']),
        ));
    }

    private function akunPegawai(string $nama, int $role): int
    {
        return $this->buatAkunUntuk($this->buatPegawai(['nama' => $nama]), $role);
    }

    private function sebagai(int $idPengguna): static
    {
        return $this->asUser((array) $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->get()->getRowArray());
    }
}
