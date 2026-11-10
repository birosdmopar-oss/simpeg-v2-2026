<?php

declare(strict_types=1);

namespace Tests\Notifikasi;

use App\Constants\Role;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * F-08 Pusat Notifikasi (MAKE-085, PRD Fase 7 v2.0 §5.9): ringkasan lonceng, daftar gabungan S1 (`inbox` v2) + S2
 * (`show_notif`), tandai dibaca (Q7-31), hapus (S2 saja, Q7-33), role ikut legacy (Q7-29), butuh approval, histori,
 * dan regresi BP8-1 (alur pusat notifikasi tidak menyentuh `disiplin_ticket*`).
 *
 * @internal
 */
final class PusatNotifikasiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;

    protected $seed = AuthSeeder::class;

    private const BASE = 'api/v1/notifikasi';

    private string $nipPegawai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->nipPegawai = AuthSeeder::nipForRole(Role::PEGAWAI);
        $this->daftarkanPegawai(...array_column(AuthSeeder::accounts(), 'nip'));
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testRingkasanBerlakuUntukSemuaRoleDenganSumberNoOpNull(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $res = $this->asRole($role)->get(self::BASE . '/ringkasan');
            $res->assertStatus(200);
            $this->assertStringContainsString('no-store', (string) $res->response()->getHeaderLine('Cache-Control'));

            $data = $this->json($res)['data'];
            $this->assertSame(['notifikasi_belum_dibaca', 'butuh_approval', 'berita_belum_dibaca', 'tiket_belum_dibaca', 'diperbarui_at'], array_keys($data), "role {$role}");
            $this->assertSame(0, $data['notifikasi_belum_dibaca']);
            $this->assertSame(0, $data['butuh_approval']);
            $this->assertNull($data['berita_belum_dibaca'], 'berita NoOp sampai F-07 di main');
            $this->assertNull($data['tiket_belum_dibaca'], 'tiket NoOp sampai Fase 8 (Q8-06)');
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['diperbarui_at']);
        }
    }

    public function testDaftarGabunganLayananDanRiwayatTerbaruDulu(): void
    {
        $idInbox  = $this->notifLayanan($this->nipPegawai, '2026-11-01 08:00:00');
        $idAlamat = $this->riwayatAlamat($this->nipPegawai, ['status' => 1, 'show_notif' => 1, 'notif_date' => '2026-11-02 09:00:00']);
        $this->riwayatAlamat($this->nipPegawai, ['status' => 0, 'show_notif' => 0]);
        $this->riwayatAlamat($this->nipPegawai, ['status' => 2, 'show_notif' => 2, 'notif_date' => '2026-11-03 09:00:00']);
        $this->riwayatAlamat(AuthSeeder::nipForRole(Role::PPPK), ['status' => 1, 'show_notif' => 1, 'notif_date' => '2026-11-04 09:00:00']);

        $this->assertSame(2, $this->ringkasan(Role::PEGAWAI)['notifikasi_belum_dibaca']);

        $data = $this->json($this->asRole(Role::PEGAWAI)->get(self::BASE))['data'];
        $this->assertSame(2, $data['total']);
        $this->assertSame(2, $data['belum_dibaca']);
        $this->assertSame(["alamat:{$idAlamat}", "layanan:{$idInbox}"], array_column($data['items'], 'id'), 'terbaru dulu; dihapus (2), belum diproses (0), dan milik orang lain tidak tampil');

        [$alamat, $layanan] = $data['items'];
        $this->assertSame('Disetujui', $alamat['status_label']);
        $this->assertSame("/pegawai/{$this->nipPegawai}?tab=alamat", $alamat['url_fe']);
        $this->assertTrue($alamat['dapat_dihapus']);
        $this->assertFalse($alamat['dibaca']);
        $this->assertSame('2026-11-02T09:00:00Z', $alamat['waktu']);
        $this->assertSame('Keputusan', $layanan['status_label']);
        $this->assertSame('Alasan uji', $layanan['reason_note']);
        $this->assertSame('/layanan/cuti/7', $layanan['url_fe']);
        $this->assertFalse($layanan['dapat_dihapus'], 'Q7-33: notifikasi layanan tanpa hapus');

        $hal2 = $this->json($this->asRole(Role::PEGAWAI)->get(self::BASE, ['per_page' => 1, 'page' => 2]))['data'];
        $this->assertSame(["layanan:{$idInbox}"], array_column($hal2['items'], 'id'));
    }

    public function testTandaiDibacaHanyaMilikSendiri(): void
    {
        $idInbox  = $this->notifLayanan($this->nipPegawai, '2026-11-01 08:00:00');
        $idAlamat = $this->riwayatAlamat($this->nipPegawai, ['status' => 1, 'show_notif' => 1, 'notif_date' => '2026-11-02 09:00:00']);
        $lain     = $this->riwayatAlamat(AuthSeeder::nipForRole(Role::PPPK), ['status' => 1, 'show_notif' => 1, 'notif_date' => '2026-11-02 09:00:00']);

        $res = $this->asRole(Role::PEGAWAI)->withBodyFormat('json')->post(self::BASE . '/baca', ['ids' => ["alamat:{$idAlamat}", "layanan:{$idInbox}", "alamat:{$lain}"]]);
        $res->assertStatus(200);
        $this->assertSame(2, $this->json($res)['data']['diubah']);

        $this->assertSame(3, (int) $this->db->table('riwayat_alamat')->where('id_riwayat_alamat', $idAlamat)->get()->getRow()->show_notif);
        $this->assertSame(1, (int) $this->db->table('riwayat_alamat')->where('id_riwayat_alamat', $lain)->get()->getRow()->show_notif, 'milik orang lain tidak berubah');
        $inbox = $this->db->table('inbox')->where('id_inbox', $idInbox)->get()->getRow();
        $this->assertSame(1, (int) $inbox->is_read);
        $this->assertNotNull($inbox->read_at);
        $this->assertSame(0, $this->ringkasan(Role::PEGAWAI)['notifikasi_belum_dibaca']);

        $data = $this->json($this->asRole(Role::PEGAWAI)->get(self::BASE))['data'];
        $this->assertSame(2, $data['total'], 'yang sudah dibaca tetap di daftar (show_notif 3)');
        $this->assertSame([true, true], array_column($data['items'], 'dibaca'));
        $this->assertSame([], $this->json($this->asRole(Role::PEGAWAI)->get(self::BASE, ['status' => 'belum']))['data']['items']);
    }

    public function testTandaiSemuaDanValidasiId(): void
    {
        $this->notifLayanan($this->nipPegawai, '2026-11-01 08:00:00');
        $this->riwayatAlamat($this->nipPegawai, ['status' => 2, 'show_notif' => 1, 'notif_date' => '2026-11-02 09:00:00', 'reason_note' => 'Kurang berkas']);

        $this->assertSame(2, $this->json($this->asRole(Role::PEGAWAI)->withBodyFormat('json')->post(self::BASE . '/baca', ['semua' => true]))['data']['diubah']);

        $this->asRole(Role::PEGAWAI)->withBodyFormat('json')->post(self::BASE . '/baca', ['ids' => ['salah']])->assertStatus(422);
        $this->asRole(Role::PEGAWAI)->withBodyFormat('json')->post(self::BASE . '/baca', [])->assertStatus(422);
        $this->asRole(Role::PEGAWAI)->get(self::BASE, ['status' => 'x'])->assertStatus(422);
    }

    public function testHapusHanyaRiwayatDanMilikSendiri(): void
    {
        $idInbox  = $this->notifLayanan($this->nipPegawai, '2026-11-01 08:00:00');
        $idAlamat = $this->riwayatAlamat($this->nipPegawai, ['status' => 1, 'show_notif' => 3, 'notif_date' => '2026-11-02 09:00:00']);
        $lain     = $this->riwayatAlamat(AuthSeeder::nipForRole(Role::PPPK), ['status' => 1, 'show_notif' => 1, 'notif_date' => '2026-11-02 09:00:00']);

        $this->asRole(Role::PEGAWAI)->delete(self::BASE . "/alamat/{$idAlamat}")->assertStatus(200);
        $this->assertSame(2, (int) $this->db->table('riwayat_alamat')->where('id_riwayat_alamat', $idAlamat)->get()->getRow()->show_notif);
        $this->assertSame(["layanan:{$idInbox}"], array_column($this->json($this->asRole(Role::PEGAWAI)->get(self::BASE))['data']['items'], 'id'));

        $this->asRole(Role::PEGAWAI)->delete(self::BASE . "/layanan/{$idInbox}")->assertStatus(404);
        $this->asRole(Role::PEGAWAI)->delete(self::BASE . "/alamat/{$lain}")->assertStatus(404);
        $this->asRole(Role::PEGAWAI)->delete(self::BASE . '/tidak-ada/1')->assertStatus(404);
        $this->assertSame(1, (int) $this->db->table('riwayat_alamat')->where('id_riwayat_alamat', $lain)->get()->getRow()->show_notif);
    }

    public function testRoleIkutLegacy(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            $this->asRole($role)->get(self::BASE)->assertStatus(403);
            $this->asRole($role)->withBodyFormat('json')->post(self::BASE . '/baca', ['semua' => true])->assertStatus(403);
        }

        foreach ([Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK] as $role) {
            $this->asRole($role)->get(self::BASE)->assertStatus(200);
        }

        foreach ([Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            $this->asRole($role)->delete(self::BASE . '/alamat/1')->assertStatus(403);
        }

        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $this->asRole($role)->get(self::BASE . '/butuh-approval')->assertStatus(403);
            $this->asRole($role)->get(self::BASE . '/histori-approval')->assertStatus(403);
        }
    }

    public function testTanpaLoginDitolak(): void
    {
        $this->get(self::BASE . '/ringkasan')->assertStatus(401);
        $this->get(self::BASE)->assertStatus(401);
    }

    public function testButuhApprovalSuperAdmin(): void
    {
        $menunggu = $this->riwayatAlamat($this->nipPegawai, ['status' => 0, 'updated_at' => '2026-11-05 10:00:00']);
        $kedua    = $this->riwayatAlamat($this->nipPegawai, ['status' => 0, 'updated_at' => '2026-11-06 10:00:00']);
        $this->riwayatAlamat($this->nipPegawai, ['status' => 1]);
        $this->riwayatAlamat($this->nipPegawai, ['status' => 10]);
        $this->konket($this->nipPegawai, 'W');
        $this->konket($this->nipPegawai, 'V');

        $data = $this->json($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/butuh-approval'))['data'];

        $grup = array_column($data['grup'], 'jumlah', 'kode');
        $this->assertSame(2, $grup['riwayat']);
        $this->assertSame(1, $grup['konket']);
        $this->assertSame($data['total'], array_sum($grup));
        $this->assertSame(["alamat:{$kedua}", "alamat:{$menunggu}"], array_column($data['riwayat']['items'], 'id'));
        $this->assertSame("/pegawai/{$this->nipPegawai}?tab=alamat", $data['riwayat']['items'][0]['url_fe']);
        $this->assertSame($this->nipPegawai, $data['riwayat']['items'][0]['nip']);
        $this->assertSame($data['total'], $this->ringkasan(Role::SUPER_ADMIN)['butuh_approval']);

        // Pegawai tanpa antrean atasan/PIC → 0, riwayat lintas pegawai tidak berlaku.
        $pegawai = $this->json($this->asRole(Role::PEGAWAI)->get(self::BASE . '/butuh-approval'))['data'];
        $this->assertSame(0, $pegawai['total']);
        $this->assertNull($pegawai['riwayat']);
    }

    public function testButuhApprovalAtasanLkhLewatServiceModul(): void
    {
        $bawahan = AuthSeeder::nipForRole(Role::PPPK);

        foreach ([0, 0, 1] as $status) {
            $this->db->table('riwayat_lckh')->insert([
                'nip'         => $bawahan, 'tgl_laporan' => '2026-11-03', 'nama' => 'Bawahan Uji', 'nip_atasan' => $this->nipPegawai,
                'nama_atasan' => 'Atasan Uji', 'kegiatan' => 'Uji', 'output' => 'Uji', 'status' => $status,
            ]);
        }

        $data = $this->json($this->asRole(Role::PEGAWAI)->get(self::BASE . '/butuh-approval'))['data'];
        $this->assertSame([['kode' => 'lkh', 'label' => 'Verifikasi LKH', 'jumlah' => 2, 'url_fe' => '/lkh/verifikasi?tab=verifikasi']], $data['grup']);
        $this->assertSame(2, $this->ringkasan(Role::PEGAWAI)['butuh_approval']);
    }

    public function testHistoriApprovalBulanDanKolomUa(): void
    {
        $bulan = Time::now('Asia/Jakarta')->format('Y-m');
        $waktu = $bulan . '-01 03:00:00';
        $biro  = $this->riwayatAlamat($this->nipPegawai, ['status' => 1, 'show_ua_biro' => 1, 'notif_date' => $waktu]);
        $this->riwayatAlamat($this->nipPegawai, ['status' => 2, 'show_ua_upt' => 1, 'notif_date' => $waktu]);
        $this->riwayatAlamat($this->nipPegawai, ['status' => 1, 'show_ua_biro' => 1, 'notif_date' => '2020-01-01 03:00:00']);

        $data = $this->json($this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/histori-approval'))['data'];
        $this->assertSame($bulan, $data['bulan']);
        $this->assertCount(12, $data['pilihan_bulan']);
        $this->assertSame(1, $data['total']);
        $this->assertSame(["alamat:{$biro}"], array_column($data['items'], 'id'));
        $this->assertSame('Disetujui', $data['items'][0]['status_label']);

        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/histori-approval', ['bulan' => '2020-01'])->assertStatus(422);
    }

    public function testAlurPusatNotifikasiTidakMenyentuhTicketing(): void
    {
        $idAlamat = $this->riwayatAlamat($this->nipPegawai, ['status' => 1, 'show_notif' => 1, 'notif_date' => '2026-11-02 09:00:00']);
        $this->notifLayanan($this->nipPegawai, '2026-11-01 08:00:00');

        $this->asRole(Role::PEGAWAI)->get(self::BASE)->assertStatus(200);
        $this->asRole(Role::PEGAWAI)->withBodyFormat('json')->post(self::BASE . '/baca', ['semua' => true])->assertStatus(200);
        $this->asRole(Role::PEGAWAI)->delete(self::BASE . "/alamat/{$idAlamat}")->assertStatus(200);
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/butuh-approval')->assertStatus(200);
        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE . '/histori-approval')->assertStatus(200);

        $this->assertSame(0, $this->db->table('disiplin_ticket')->countAllResults());
        $this->assertSame(0, $this->db->table('disiplin_ticket_msg')->countAllResults());
    }

    /**
     * @return array<string, mixed>
     */
    private function ringkasan(int $role): array
    {
        return $this->json($this->asRole($role)->get(self::BASE . '/ringkasan'))['data'];
    }

    private function notifLayanan(string $nip, string $waktu): int
    {
        $this->db->table('inbox')->insert([
            'nip_penerima' => $nip, 'modul' => 'cuti', 'id_rwy' => 7, 'judul' => 'Pengajuan cuti', 'url' => '/layanan/cuti/7',
            'is_read'      => 0, 'created_at' => $waktu,
        ]);
        $id = (int) $this->db->insertID();
        $this->db->table('inbox_msg')->insert(['id_inbox' => $id, 'tipe' => 1, 'status_sumber' => 0, 'isi' => 'Diajukan', 'push_status' => 1, 'created_at' => $waktu]);
        $this->db->table('inbox_msg')->insert(['id_inbox' => $id, 'tipe' => 2, 'status_sumber' => 2, 'isi' => 'Cuti ditolak', 'reason_note' => 'Alasan uji', 'push_status' => 1, 'created_at' => $waktu]);

        return $id;
    }

    /**
     * @param array<string, mixed> $kolom
     */
    private function riwayatAlamat(string $nip, array $kolom): int
    {
        $this->db->table('riwayat_alamat')->insert($kolom + ['nip' => $nip]);

        return (int) $this->db->insertID();
    }

    private function konket(string $nip, string $status): int
    {
        $this->db->table('absen_ijin')->insert([
            'nip' => $nip, 'date_start' => '2026-11-03 07:30:00', 'date_end' => '2026-11-03 16:00:00', 'kategori' => 1, 'alasan' => 'Uji', 'status' => $status,
        ]);

        return (int) $this->db->insertID();
    }
}
