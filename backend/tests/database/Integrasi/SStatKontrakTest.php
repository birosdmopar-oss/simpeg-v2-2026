<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Klien\PenyimpanKlien;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;
use Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\LogIntegrasiTiruan;
use Tests\Support\Kepegawaian\FakeStorageAdapter;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-061 — kontrak HTTP `services/s_stat/*` lewat pipeline route sungguhan: AC-08.3 tanpa/salah auth → 401 amplop INT
 * (filter A3); sukses → HTTP 200 `{"status":1,"msg":"","data":[…]}`; AC-08.4 Q6-37 `rekomendasi_kp_uns` berfilter →
 * `data: []`; `list_tb` kolom legacy tanpa path internal; endpoint unduh Q6-38 (404 seragam amplop INT, 200 stream dengan
 * header aman); hak selain `stat` ditolak.
 *
 * @internal
 */
final class SStatKontrakTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    private string $rahasia = '';

    private FakeStorageAdapter $storage;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('cache', new MockCache());
        Services::injectMock('integrasiLog', new LogIntegrasiTiruan());
        $throttler = new Throttler(new MockCache());
        $throttler->setTestTime(1_790_000_000);
        Services::injectMock('throttler', $throttler);
        $this->storage = new FakeStorageAdapter();
        Services::injectMock('storageAdapter', $this->storage);
        Time::setTestNow('2026-11-05 10:00:00', 'Asia/Jakarta');
        $this->pastikanJenisPegawai();
        $this->pastikanEselon();

        $this->rahasia = (new PenyimpanKlien($this->db))->buat('MuDig Uji', 'mudig', 3, ['stat'], null)['rahasia'];
    }

    protected function tearDown(): void
    {
        Time::setTestNow();

        foreach (['cache', 'integrasiLog', 'throttler', 'klienSaatIni', 'storageAdapter'] as $nama) {
            Services::resetSingle($nama);
        }

        parent::tearDown();
    }

    public function testTanpaAuth401AmplopInt(): void
    {
        foreach (['rekomendasi_kp', 'rekomendasi_kp_uns', 'rekomendasi_pensiun', 'rekomendasi_kgb', 'list_tb', 'list_ib', 'list_karpeg', 'list_kariskarsu', 'berkas/kp/1/1', 'berkas/tb/1/1/file_sp'] as $path) {
            $res = $this->call('get', 'services/s_stat/' . $path);
            $res->assertStatus(401);
            $res->assertHeader('WWW-Authenticate', 'Basic realm="SIMPEG"');
            $this->assertSame(['status' => 2, 'msg' => 'Authentication failed', 'data' => []], json_decode($res->getJSON(), true), $path);
        }
    }

    public function testHakLainDitolak(): void
    {
        $r   = (new PenyimpanKlien($this->db))->buat('DM Uji', 'dm-saja', 3, ['dm'], null);
        $res = $this->basic('dm-saja', $r['rahasia'])->call('get', 'services/s_stat/list_tb');
        $res->assertStatus(401);
    }

    public function testSuksesAmplopIntHttp200(): void
    {
        foreach (['rekomendasi_kp', 'rekomendasi_pensiun', 'rekomendasi_kgb', 'list_ib', 'list_karpeg', 'list_kariskarsu'] as $path) {
            $res = $this->basic('mudig', $this->rahasia)->call('get', 'services/s_stat/' . $path);
            $res->assertStatus(200);
            $this->assertSame(['status' => 1, 'msg' => '', 'data' => []], json_decode($res->getJSON(), true), $path);
            $this->assertSame('{"status":1,"msg":"","data":[]}', (string) preg_replace('/\s+/', '', $res->getJSON()), 'status INT, data array kosong (bukan objek)');
        }
    }

    public function testQ637RekomendasiKpUnsBerfilterSelaluKosong(): void
    {
        $nip = $this->buatPegawai(['id_jenis_pegawai' => 1]);
        $this->db->table('pangkat')->insert(['pangkat' => 'Penata Muda', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'order' => 16, 'status' => 1]);
        $idPangkat = (int) $this->db->insertID();
        $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => 'S.1', 'jenjang_pendidikan' => 'Strata 1', 'status' => 1]);
        $idJenjang = (int) $this->db->insertID();
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'tmtsk' => '2022-04-01', 'id_jenis_kp' => 3, 'id_pangkat' => $idPangkat, 'status' => 1]);
        $idRk = (int) $this->db->insertID();
        $this->db->table('pegawai_kp')->insert(['nip' => $nip, 'id_riwayat_kp' => $idRk, 'id_jenis_kp' => 3, 'id_pangkat' => $idPangkat, 'tmtsk' => '2022-04-01', 'tgl_sk' => '2022-04-01', 'jenis_kp' => 'Reguler', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda']);
        $this->db->table('pegawai_pendidikan')->insert(['nip' => $nip, 'id_jenjang_pendidikan' => $idJenjang, 'jenjang_pendidikan_singkat' => 'S.1', 'tgl_lulus' => '2010-08-01']);

        $tanpa = json_decode($this->basic('mudig', $this->rahasia)->call('get', 'services/s_stat/rekomendasi_kp_uns')->getJSON(), true);
        $this->assertSame([1, ''], [$tanpa['status'], $tanpa['msg']]);
        $this->assertCount(1, $tanpa['data'], 'tanpa filter: semua pegawai format MuDig');
        $this->assertSame('2026-04-01', $tanpa['data'][0]['tmt_kp_new']);

        // [K] bug legacy Q6-37 (AC-08.4): dengan id_satker atau id_unit → data [] dengan status 1 dan msg "".
        foreach (['?id_satker=1', '?id_unit=1', '?id_satker=1&id_unit=1', '?id_unit=abc'] as $q) {
            $res = $this->basic('mudig', $this->rahasia)->call('get', 'services/s_stat/rekomendasi_kp_uns' . $q);
            $res->assertStatus(200);
            $this->assertSame('{"status":1,"msg":"","data":[]}', (string) preg_replace('/\s+/', '', $res->getJSON()), $q);
        }

        // Nilai kosong = tanpa filter (legacy `!= ''`).
        $kosong = json_decode($this->basic('mudig', $this->rahasia)->call('get', 'services/s_stat/rekomendasi_kp_uns?id_satker=&id_unit=')->getJSON(), true);
        $this->assertCount(1, $kosong['data']);
    }

    public function testListTbKolomLegacyTanpaPathInternal(): void
    {
        $nip = $this->buatPegawai();
        $this->db->table('riwayat_tb')->insert([
            'nip'                => $nip, 'tgl_mulai' => '2026-09-01', 'tgl_akhir' => '2028-08-31', 'jenjang_pendidikan_singkat' => 'S.2', 'institusi_pendidikan' => 'UI',
            'jurusan_pendidikan' => 'Hukum', 'dalam_negeri' => 1, 'lokasi_pendidikan' => 'Jakarta', 'lembaga_yang_mendanai' => 'LPDP', 'status' => 3, 'file_sp' => 'arsip/tb/sp.pdf',
        ]);
        $id = (int) $this->db->insertID();
        $this->storage->simpan('arsip/tb/sp.pdf', '%PDF-1.4 uji');

        $res   = $this->basic('mudig', $this->rahasia)->call('get', 'services/s_stat/list_tb');
        $badan = json_decode($res->getJSON(), true);
        $res->assertStatus(200);
        $this->assertCount(1, $badan['data']);
        $r = $badan['data'][0];
        $this->assertSame((string) $id, (string) $r['id_riwayat_tb']);
        $this->assertSame('3', (string) $r['status']);
        $this->assertSame(base_url() . "services/s_stat/berkas/tb/{$nip}/{$id}/file_sp", $r['file_sp']);
        $this->assertSame('', $r['file_1']);
        $this->assertStringNotContainsString('arsip/', $res->getJSON());
        $this->assertSame(['id_unit', 'id_satker'], array_slice(array_keys($r), -2));

        // Unduh lewat URL yang dikeluarkan (Q6-38).
        $unduh = $this->basic('mudig', $this->rahasia)->call('get', "services/s_stat/berkas/tb/{$nip}/{$id}/file_sp");
        $unduh->assertStatus(200);
        $unduh->assertHeader('Content-Disposition', "attachment; filename=\"s_stat_tb_{$id}.pdf\"");
        $unduh->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $unduh->response()->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('application/pdf', (string) $unduh->response()->getHeaderLine('Content-Type'), 'mime dari isi');
        $this->assertSame('%PDF-1.4 uji', $unduh->response()->getBody());
    }

    public function testUnduh404SeragamAmplopInt(): void
    {
        $nip = $this->buatPegawai();

        foreach (["berkas/kp/{$nip}/999", "berkas/lain/{$nip}/1", "berkas/tb/{$nip}/1/file_sp", "berkas/tb/{$nip}/1/keterangan", 'berkas/skp/000/1'] as $path) {
            $res = $this->basic('mudig', $this->rahasia)->call('get', 'services/s_stat/' . $path);
            $res->assertStatus(404);
            $this->assertSame(['status' => 2, 'msg' => 'Berkas tidak ditemukan.', 'data' => []], json_decode($res->getJSON(), true), $path);
        }
    }

    /**
     * Master `jenis_pegawai` yang dirujuk FK `pegawai.id_jenis_pegawai` (1 PNS, 6 PPPK) — disisipkan bila belum ada.
     */
    private function pastikanJenisPegawai(): void
    {
        foreach ([1 => 'PNS', 6 => 'PPPK'] as $id => $nama) {
            if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
                $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'status' => 1]);
            }
        }

        if ($this->db->table('jenis_kp')->where('id_jenis_kp', 3)->countAllResults() === 0) {
            $this->db->table('jenis_kp')->insert(['id_jenis_kp' => 3, 'jenis_kp' => 'Reguler', 'status' => 1]);
        }
    }

    /**
     * Master `group_jabatan` 1 (Struktural) + `sub_group_jabatan` 1..4 (E.I..E.IV) [K `getEselon`] disisipkan lebih dulu agar
     * id sub group fixture pegawai biasa jatuh di luar 1..4 (tidak dianggap pejabat struktural).
     */
    private function pastikanEselon(): void
    {
        if ($this->db->table('group_jabatan')->where('id_group_jabatan', 1)->countAllResults() === 0) {
            $this->db->table('group_jabatan')->insert(['id_group_jabatan' => 1, 'group_jabatan' => 'Struktural', 'status' => 1]);
        }

        foreach ([1 => 'Eselon I', 2 => 'Eselon II', 3 => 'Eselon III', 4 => 'Eselon IV'] as $id => $nama) {
            if ($this->db->table('sub_group_jabatan')->where('id_sub_group_jabatan', $id)->countAllResults() === 0) {
                $this->db->table('sub_group_jabatan')->insert(['id_sub_group_jabatan' => $id, 'id_group_jabatan' => 1, 'sub_group_jabatan' => $nama, 'status' => 1]);
            }
        }
    }

    private function basic(string $username, string $rahasia): self
    {
        return $this->withHeaders(['Authorization' => 'Basic ' . base64_encode($username . ':' . $rahasia)]);
    }
}
