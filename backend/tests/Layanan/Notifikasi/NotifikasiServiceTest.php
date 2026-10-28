<?php

declare(strict_types=1);

namespace Tests\Layanan\Notifikasi;

use App\Libraries\Layanan\NotifikasiService;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-023 M4 (PRD Fase 4 §6.6.1/§6.6.3, AC-06.3) — `NotifikasiService`: tulis `inbox` (satu induk per penerima per
 * pengajuan) dan `inbox_msg` (satu per kejadian), penerima digabung dan pelaku dikecualikan, batas kolom, antrean push
 * setelah commit, kegagalan enqueue tidak membatalkan apa pun, rollback transaksi pemanggil tidak meninggalkan baris.
 *
 * @internal
 */
final class NotifikasiServiceTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    protected $seed = MasterDataSeeder::class;

    protected function tearDown(): void
    {
        service('authContext')->clear();
        parent::tearDown();
    }

    private function service(?\Closure $antre = null): NotifikasiService
    {
        return new NotifikasiService($this->db, $antre ?? static fn (int $id): bool => true);
    }

    public function testKirimMenulisIndukDanPesanDenganKolomBenar(): void
    {
        $nip = $this->buatPegawai();

        $ids = $this->service()->kirim('cuti', 77, 1, [$nip], 'Judul uji', 'Isi uji', null, 0, '/layanan/cuti/77');

        $this->assertCount(1, $ids);
        $induk = $this->db->table('inbox')->where('nip_penerima', $nip)->get()->getRowArray();
        $this->assertSame('cuti', $induk['modul']);
        $this->assertSame(77, (int) $induk['id_rwy']);
        $this->assertNull($induk['id_jenis_layanan']);
        $this->assertSame('/layanan/cuti/77', $induk['url']);
        $this->assertSame(0, (int) $induk['is_read']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $induk['created_at']);

        $msg = $this->db->table('inbox_msg')->where('id_inbox_msg', $ids[0])->get()->getRowArray();
        $this->assertSame('Isi uji', $msg['isi']);
        $this->assertSame(0, (int) $msg['push_status']);
        $this->assertNull($msg['reason_note']);
    }

    public function testModulStudiMengisiIdJenisLayanan(): void
    {
        $nip = $this->buatPegawai();

        foreach (['tb' => 3, 'ib' => 4, 'pmk' => 5] as $modul => $jenis) {
            $this->service()->kirim($modul, 5, 2, [$nip], 'J', 'I', null, 1, "/layanan/{$modul}/5");
            $this->assertSame($jenis, (int) $this->db->table('inbox')->where('nip_penerima', $nip)->where('modul', $modul)->get()->getRowArray()['id_jenis_layanan']);
        }
    }

    public function testPenerimaDuplikatDigabungNipTakDikenalDanKosongDilewati(): void
    {
        $nip = $this->buatPegawai();

        $ids = $this->service()->kirim('cuti', 1, 1, [$nip, $nip, '', '  ', '9999999999999999'], 'J', 'I', null, 0, '/u');

        $this->assertCount(1, $ids);
        $this->assertSame(1, $this->db->table('inbox')->countAllResults());
    }

    public function testPelakuAksiTidakDikirimiNotifikasiAtasAksinyaSendiri(): void
    {
        $pelaku = $this->buatPegawai();
        $lain   = $this->buatPegawai();
        service('authContext')->setClaims($this->authUntukAkun($this->buatAkunUntuk($pelaku, 2))->claims());

        $ids = $this->service()->kirim('cuti', 1, 2, [$pelaku, $lain], 'J', 'I', null, 1, '/u');

        $this->assertCount(1, $ids);
        $this->assertSame(0, $this->db->table('inbox')->where('nip_penerima', $pelaku)->countAllResults());
        $this->assertSame(1, $this->db->table('inbox')->where('nip_penerima', $lain)->countAllResults());
    }

    public function testIndukDipakaiUlangPerPengajuanDanBelumDibacaKembali(): void
    {
        $nip = $this->buatPegawai();
        $svc = $this->service();

        $svc->kirim('cuti', 9, 1, [$nip], 'Judul A', 'Isi A', null, 0, '/a');
        $this->db->table('inbox')->where('nip_penerima', $nip)->update(['is_read' => 1, 'read_at' => '2026-11-02 01:00:00']);
        $svc->kirim('cuti', 9, 2, [$nip], 'Judul B', 'Isi B', null, 1, '/b');

        $induk = $this->db->table('inbox')->where('nip_penerima', $nip)->get()->getResultArray();
        $this->assertCount(1, $induk, 'satu induk per penerima per pengajuan');
        $this->assertSame('Judul B', $induk[0]['judul']);
        $this->assertSame(0, (int) $induk[0]['is_read']);
        $this->assertNull($induk[0]['read_at']);
        $this->assertSame(2, $this->db->table('inbox_msg')->where('id_inbox', $induk[0]['id_inbox'])->countAllResults());
    }

    public function testBatasPanjangKolomDipotong(): void
    {
        $nip = $this->buatPegawai();

        $ids = $this->service()->kirim('cuti', 1, 2, [$nip], str_repeat('j', 300), str_repeat('i', 900), str_repeat('r', 400), 2, '/' . str_repeat('u', 400));

        $this->assertSame(150, mb_strlen((string) $this->db->table('inbox')->get()->getRowArray()['judul']));
        $this->assertSame(255, mb_strlen((string) $this->db->table('inbox')->get()->getRowArray()['url']));
        $msg = $this->db->table('inbox_msg')->where('id_inbox_msg', $ids[0])->get()->getRowArray();
        $this->assertSame(500, mb_strlen((string) $msg['isi']));
        $this->assertSame(255, mb_strlen((string) $msg['reason_note']));
    }

    public function testEnqueuePushMemasukkanSatuJobPerPesanKeAntreanPush(): void
    {
        $nip = $this->buatPegawai();
        $svc = new NotifikasiService($this->db);
        $ids = $svc->kirim('cuti', 1, 1, [$nip], 'J', 'I', null, 0, '/u');
        $ids = array_merge($ids, $svc->kirim('cuti', 2, 1, [$nip], 'J2', 'I2', null, 0, '/u'));

        $svc->enqueuePush($ids);

        $jobs = $this->db->table('queue_jobs')->where('queue', NotifikasiService::ANTREAN)->get()->getResultArray();
        $this->assertCount(2, $jobs, 'AC-06.3: tepat satu baris per inbox_msg');

        foreach ($jobs as $job) {
            $this->assertStringContainsString(NotifikasiService::JOB, (string) $job['payload']);
        }
    }

    public function testRollbackTransaksiPemanggilTidakMeninggalkanBarisDanTidakMengantre(): void
    {
        $nip     = $this->buatPegawai();
        $antrean = [];
        $svc     = $this->service(static function (int $id) use (&$antrean): bool {
            $antrean[] = $id;

            return true;
        });

        $this->db->transBegin();

        try {
            $svc->kirim('cuti', 3, 1, [$nip], 'J', 'I', null, 0, '/u');

            throw new RuntimeException('bisnis gagal');
        } catch (RuntimeException) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
        }

        $this->assertSame(0, $this->db->table('inbox')->countAllResults());
        $this->assertSame(0, $this->db->table('inbox_msg')->countAllResults());
        $this->assertSame([], $antrean, 'enqueuePush hanya dipanggil pemanggil sesudah commit');
    }

    public function testGagalEnqueueMenandaiPushStatusGagalTanpaMembatalkanTransaksi(): void
    {
        $nip = $this->buatPegawai();
        $ids = $this->service()->kirim('cuti', 4, 1, [$nip], 'J', 'I', null, 0, '/u');

        $this->service(static fn (int $id): bool => false)->enqueuePush($ids);
        $this->assertSame(2, (int) $this->db->table('inbox_msg')->where('id_inbox_msg', $ids[0])->get()->getRowArray()['push_status']);

        $this->db->table('inbox_msg')->where('id_inbox_msg', $ids[0])->update(['push_status' => 0]);
        $this->service(static function (int $id): bool {
            throw new RuntimeException('queue mati');
        })->enqueuePush($ids);
        $this->assertSame(2, (int) $this->db->table('inbox_msg')->where('id_inbox_msg', $ids[0])->get()->getRowArray()['push_status']);
        $this->assertSame(1, $this->db->table('inbox_msg')->countAllResults(), 'pesan tetap ada');
    }
}
