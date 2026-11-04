<?php

declare(strict_types=1);

namespace Tests\Layanan\Notifikasi;

use App\Jobs\PushPesanJob;
use App\Libraries\Push\MockFcmAdapter;
use App\Libraries\Push\TemplatPushRiwayat;
use Config\Services;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * MAKE-066 (F6-13, keputusan reviewer 04-11-2026) — push Android cuti ikut legacy `andr_notif_queue`
 * [K `L_cuti.php:1063-1110`, `:1196-1245`, `:2462-2548`]: penerima, judul/isi legacy, dan BENTUK `data`
 * `{title, body, data: JSON baris riwayat_cuti + file_bukti_url + status_str}` (kontrak app Android di luar repo); hanya
 * driver `fcm`, hanya kanal Android (`push-pesan` kanal android).
 *
 * @internal
 */
final class PushAndroidCutiTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    /** @var array<string, mixed> */
    private array $pushAsli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pushAsli = config(\Config\Integrations::class)->push;
    }

    protected function tearDown(): void
    {
        config(\Config\Integrations::class)->push = $this->pushAsli;
        Services::reset(true);

        parent::tearDown();
    }

    public function testDriverMockTidakMengantrePushAndroid(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);

        $this->assertSame([], $this->pushAndroid());
    }

    public function testAlurAjukanUbahSetujuiAlTolakPybIkutLegacy(): void
    {
        config(\Config\Integrations::class)->push = ['driver' => 'fcm', 'projectId' => '', 'credentialsPath' => ''];
        $a                                        = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $isian = $this->isianCuti($a['al']['nip'], $a['pyb']['nip']);
        $id    = (int) $this->cutiService()->tambah($a['pemohon']['auth'], $isian, null)['id_riwayat_cuti'];
        $cuti  = $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRowArray();
        $nama  = (string) $cuti['nama'];
        $tgl   = TemplatPushRiwayat::tanggal($cuti['tgl_mulai']) . ' s/d ' . TemplatPushRiwayat::tanggal($cuti['tgl_akhir']);

        $this->cutiService()->ubah($a['pemohon']['auth'], $id, $isian, null);
        $this->approvalService()->process($a['al']['auth'], $id, ['aksi' => 'setujui']);
        $this->approvalService()->process($a['pyb']['auth'], $id, ['aksi' => 'tolak', 'reason_note' => 'Tidak sesuai']);

        $namaAl  = $this->namaGelar($a['al']['nip']);
        $namaPyb = $this->namaGelar($a['pyb']['nip']);
        $this->assertSame([
            [$a['al']['nip'], 'Monitoring Cuti', "{$nama} telah mengajukan cuti untuk tanggal {$tgl}", 'Menunggu'],
            [$a['al']['nip'], 'Monitoring Cuti', "{$nama} telah mengubah cuti untuk tanggal {$tgl}", 'Menunggu'],
            [$a['pemohon']['nip'], 'Cuti', "Pengajuan cuti anda anda pada tanggal {$tgl} diproses oleh {$namaAl}", 'Diproses'],
            [$a['pyb']['nip'], 'Monitoring Cuti', "{$nama} telah mengajukan cuti untuk tanggal {$tgl}", 'Diproses'],
            [$a['pemohon']['nip'], 'Cuti', "Pengajuan cuti anda anda pada tanggal {$tgl} ditolak oleh {$namaPyb}", 'Ditolak'],
        ], array_map(static fn (array $j): array => [$j['nip'][0], $j['judul'], $j['isi'], json_decode($j['data']['data'], true)['status_str']], $this->pushAndroid()));

        foreach ($this->pushAndroid() as $j) {
            $this->assertSame('android', $j['kanal']);
            $this->assertSame(['title', 'body', 'data'], array_keys($j['data']), 'bentuk data legacy');
            $this->assertSame([$j['judul'], $j['isi']], [$j['data']['title'], $j['data']['body']]);
        }

        // Kunci `data.data` = kolom baris riwayat_cuti + file_bukti_url + status_str (kontrak app Android).
        $baris = json_decode($this->pushAndroid()[0]['data']['data'], true);
        $kolom = array_column($this->db->query('SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$this->db->prefixTable('riwayat_cuti')])->getResultArray(), 'c');
        $this->assertSame([...$kolom, 'file_bukti_url', 'status_str'], array_keys($baris));
        $this->assertSame(['', $id], [$baris['file_bukti_url'], (int) $baris['id_riwayat_cuti']]);
    }

    public function testJobKirimHanyaTokenAndroidDenganOpsiAndroid(): void
    {
        config(\Config\Integrations::class)->push = ['driver' => 'fcm', 'projectId' => '', 'credentialsPath' => ''];
        $a                                        = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null);
        $this->db->table('firebase_token')->insert(['nip' => $a['al']['nip'], 'platform' => 2, 'fcm_token' => 'tok-android-al', 'token_hash' => hash('sha256', 'tok-android-al'), 'created_at' => '2026-11-04 00:00:00']);
        $mock = new MockFcmAdapter();
        Services::injectMock('pushNotifGateway', $mock);

        $this->assertSame(PushPesanJob::HASIL_TERKIRIM, (new PushPesanJob($this->pushAndroid()[0]))->process());
        $this->assertSame([['tok-android-al', 2, 'Monitoring Cuti']], array_map(static fn (array $k): array => [$k['token'], $k['platform'], $k['title']], $mock->sent()));
    }

    /**
     * Data job `push-pesan` antrean `push`, urut masuk.
     *
     * @return list<array{nip: list<string>, judul: string, isi: string, data: array<string, string>, kanal: string}>
     */
    private function pushAndroid(): array
    {
        $hasil = [];

        foreach ($this->db->table('queue_jobs')->where('queue', 'push')->orderBy('id', 'ASC')->get()->getResultArray() as $r) {
            $p = (array) json_decode((string) $r['payload'], true);

            if (($p['job'] ?? null) === PushPesanJob::NAMA) {
                $hasil[] = $p['data'];
            }
        }

        return $hasil;
    }

    private function namaGelar(string $nip): string
    {
        $p = $this->db->table('pegawai')->select('glr_awal, nama, glr_akhir')->where('nip', $nip)->get()->getRowArray();

        return \App\Libraries\Kepegawaian\Struktur\StrukturService::namaGelar($p['glr_awal'], (string) $p['nama'], $p['glr_akhir']);
    }
}
