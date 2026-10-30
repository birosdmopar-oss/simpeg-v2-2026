<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lkh\AtasanLangsung;
use App\Libraries\Kepegawaian\Lkh\LkhService;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use Closure;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-12b LKH (WS-2 M5 MAKE-013): atasan langsung (getAL), aturan legacy vp()/sp() (rentang 4 hari kerja, unik per
 * tanggal, kegiatan JSON), kunci tukin, verifikasi atasan (setujui/tolak/revisi), PDF TCPDF, hak akses. Hari ini
 * dibekukan Selasa 2026-10-13 WIB (tanggal minimal 2026-10-08).
 *
 * @internal
 */
final class LkhTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    private string $nip;
    private string $nipAtasan;
    private AuthContext $pegawai;
    private AuthContext $atasan;
    private int $idSatker;

    protected function setUp(): void
    {
        parent::setUp();

        Time::setTestNow('2026-10-13 10:00:00', 'Asia/Jakarta');
        Services::injectMock('pegawaiScope', new PegawaiScope());

        if ($this->db->table('jenis_status')->where('id_jenis_status', 1)->countAllResults() === 0) {
            $this->db->table('jenis_status')->insert(['id_jenis_status' => 1, 'jenis_status' => 'Aktif Bekerja', 'status_pegawai' => 1]);
        }

        $this->idSatker                   = $this->buatSatker();
        $jabAtasan                        = $this->buatJabatan($this->idSatker)['id_jabatan'];
        [$this->nipAtasan, $this->atasan] = $this->aktor(Role::PEGAWAI, [], $this->idSatker);
        $this->db->table('pegawai')->where('nip', $this->nipAtasan)->update(['id_jenis_status' => 1, 'nama' => 'Atasan Uji']);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $this->nipAtasan)->update(['id_jabatan' => $jabAtasan]);

        [$this->nip, $this->pegawai] = $this->aktor(Role::PTT, [], $this->idSatker);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $this->nip)->update(['id_atasan_es_4' => $jabAtasan]);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        Services::reset(true);

        parent::tearDown();
    }

    public function testTambahMengisiAtasanLangsungDanKegiatanJson(): void
    {
        $row = $this->tambah($this->pegawai, '2026-10-12');

        $this->assertSame([$this->nip, 0, $this->nipAtasan, 'Atasan Uji', 2], [$row['nip'], $row['status'], $row['nip_atasan'], $row['nama_atasan'], $row['jumlah']]);
        $this->assertSame('Menyusun laporan', $row['kegiatan'][0]['kegiatan']);
        $this->assertSame('["Menyusun laporan","Rapat"]', $this->db->table('riwayat_lckh')->where('id_riwayat_lckh', $row['id'])->get()->getRow()->kegiatan);
        $this->assertSame(['ubah' => true, 'hapus' => true, 'proses' => false], $row['aksi']);

        $opsi = $this->svc()->opsi($this->pegawai);
        $this->assertSame(['2026-10-08', '2026-10-13', $this->nipAtasan], [$opsi['tanggal_minimal'], $opsi['tanggal_maksimal'], $opsi['atasan']['nip']]);
    }

    public function testStempelCreatedAtDiisiAplikasiUtcBukanZonaSesiDb(): void
    {
        // CR-086: zona sesi +14:00 membuat DEFAULT CURRENT_TIMESTAMP berbeda jauh dari UTC; aplikasi wajib mengisinya.
        $this->db->query("SET time_zone = '+14:00'");

        try {
            $awal = time();
            $row  = $this->tambah($this->pegawai, '2026-10-12');
            $db   = $this->db->table('riwayat_lckh')->where('id_riwayat_lckh', $row['id'])->get()->getRowArray();

            $this->assertEqualsWithDelta($awal, strtotime($db['created_at'] . ' UTC'), 30, 'created_at harus UTC: ' . $db['created_at']);

            $this->svc()->ubah($this->pegawai, $row['id'], ['tgl_laporan' => '2026-10-12', 'kegiatan' => [$this->baris('08:00')]]);
            $db2 = $this->db->table('riwayat_lckh')->where('id_riwayat_lckh', $row['id'])->get()->getRowArray();
            $this->assertEqualsWithDelta($awal, strtotime($db2['updated_at'] . ' UTC'), 30, 'updated_at harus UTC: ' . $db2['updated_at']);
        } finally {
            $this->db->query('SET time_zone = SYSTEM');
        }
    }

    public function testValidasiLegacy(): void
    {
        $this->assertErrors(['tgl_laporan'], fn () => $this->tambah($this->pegawai, '2026-10-07'), 'sebelum 4 hari kerja');
        $this->assertErrors(['tgl_laporan'], fn () => $this->tambah($this->pegawai, '2026-10-14'), 'masa depan');
        $this->assertErrors(['kegiatan'], fn () => $this->svc()->tambah($this->pegawai, ['tgl_laporan' => '2026-10-12', 'kegiatan' => []]));
        $this->assertErrors(['kegiatan.0'], fn () => $this->svc()->tambah($this->pegawai, ['tgl_laporan' => '2026-10-12', 'kegiatan' => [['kegiatan' => 'A', 'output' => 'B']]]));
        $this->assertErrors(['kegiatan.0'], fn () => $this->svc()->tambah($this->pegawai, ['tgl_laporan' => '2026-10-12', 'kegiatan' => [$this->baris('25:00')]]));

        $this->tambah($this->pegawai, '2026-10-12');
        $this->assertErrors(['tgl_laporan'], fn () => $this->tambah($this->pegawai, '2026-10-12'), 'tanggal kembar');

        // Tanpa atasan langsung di snapshot jabatan → 422.
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $this->nip)->update(['id_atasan_es_4' => null]);
        $this->assertErrors(['nip_atasan'], fn () => $this->tambah($this->pegawai, '2026-10-09'));
    }

    public function testVerifikasiAtasanSetujuiTolakRevisi(): void
    {
        $a = $this->tambah($this->pegawai, '2026-10-12');
        $b = $this->tambah($this->pegawai, '2026-10-09');

        $antrean = $this->svc()->verifikasi($this->atasan);
        $this->assertSame([$b['id'], $a['id']], array_column($antrean['items'], 'id'));
        $this->assertTrue($antrean['items'][0]['aksi']['proses']);

        // Bukan atasan → 404; catatan wajib saat revisi/tolak.
        $this->assertStatusApi(404, fn () => $this->svc()->proses($this->pegawai, $a['id'], 'setujui', null));
        $this->assertErrors(['catatan'], fn () => $this->svc()->proses($this->atasan, $a['id'], 'revisi', ''));

        $this->assertSame(3, $this->svc()->proses($this->atasan, $a['id'], 'revisi', 'Rinci output')['status']);
        $this->assertErrors(['status'], fn () => $this->svc()->proses($this->atasan, $a['id'], 'setujui', null));

        // Revisi oleh pegawai → kembali Menunggu.
        $ubah = $this->svc()->ubah($this->pegawai, $a['id'], ['kegiatan' => [$this->baris('08:00')]]);
        $this->assertSame([0, 1], [$ubah['status'], $ubah['jumlah']]);

        $this->assertSame(1, $this->svc()->proses($this->atasan, $a['id'], 'setujui', null)['status']);
        $this->assertSame(2, $this->svc()->proses($this->atasan, $b['id'], 'tolak', 'Tidak sesuai')['status']);

        // Disetujui: pegawai tidak bisa ubah/hapus; Ditolak tidak tampil di daftar pegawai.
        $this->assertStatusApi(403, fn () => $this->svc()->ubah($this->pegawai, $a['id'], ['kegiatan' => [$this->baris('08:00')]]));
        $this->assertStatusApi(403, fn () => $this->svc()->hapus($this->pegawai, $a['id']));
        $this->assertSame([$a['id']], array_column($this->svc()->daftar($this->pegawai)['items'], 'id'));
    }

    public function testKunciTukinUbahDanHapus(): void
    {
        $this->db->table('riwayat_lckh')->insert([
            'nip'      => $this->nip, 'tgl_laporan' => '2026-09-15', 'nama' => 'x', 'nip_atasan' => $this->nipAtasan, 'nama_atasan' => 'Atasan Uji',
            'kegiatan' => '["a"]', 'output' => '["b"]', 'jumlah_diselesaikan' => '["1"]', 'jam_mulai' => '["08:00"]', 'jam_selesai' => '["09:00"]', 'status' => 0,
        ]);
        $id = (int) $this->db->insertID();

        $this->assertErrors(['tgl_laporan'], fn () => $this->svc()->ubah($this->pegawai, $id, ['kegiatan' => [$this->baris('08:00')]]));
        $this->assertErrors(['tgl_laporan'], fn () => $this->svc()->hapus($this->pegawai, $id));
        $this->assertSame(['ubah' => false, 'hapus' => false], array_intersect_key($this->svc()->detail($this->pegawai, $id)['aksi'], ['ubah' => 1, 'hapus' => 1]));

        // Bulan berjalan: hapus = status 10.
        $baru = $this->tambah($this->pegawai, '2026-10-12');
        $this->svc()->hapus($this->pegawai, $baru['id']);
        $this->assertSame('10', (string) $this->db->table('riwayat_lckh')->where('id_riwayat_lckh', $baru['id'])->get()->getRow()->status);
    }

    public function testPdfHanyaDisetujuiDanHakAkses(): void
    {
        $row = $this->tambah($this->pegawai, '2026-10-12');
        // Keputusan user 13-10-2026: PDF hanya untuk LKH Disetujui (legacy regenerate_pdf).
        $this->assertErrors(['status'], fn () => $this->svc()->pdf($this->pegawai, $row['id']));
        $this->svc()->proses($this->atasan, $row['id'], 'setujui', null);

        foreach ([$this->pegawai, $this->atasan, $this->aktor(Role::SUPER_ADMIN)[1], $this->aktor(Role::ADMIN_SATKER, [], $this->idSatker)[1]] as $auth) {
            $pdf = $this->svc()->pdf($auth, $row['id']);
            $this->assertStringStartsWith('%PDF-', $pdf['isi']);
            $this->assertSame("lkh_{$this->nip}_20261012.pdf", $pdf['nama']);
        }

        [, $lain] = $this->aktor(Role::PEGAWAI, [], $this->buatSatker());
        $this->assertStatusApi(404, fn () => $this->svc()->detail($lain, $row['id']));
        $this->assertStatusApi(403, fn () => $this->svc()->daftar($lain, ['nip' => $this->nip]));
        $this->assertStatusApi(403, fn () => $this->svc()->daftar($this->aktor(Role::MENTERI)[1]));
        $this->assertStatusApi(404, fn () => $this->svc()->pdf($this->aktor(Role::ADMIN_SATKER, [], $this->buatSatker())[1], $row['id']));
    }

    public function testHapusDisetujuiHanyaRole1(): void
    {
        $row = $this->tambah($this->pegawai, '2026-10-12');
        $this->svc()->proses($this->atasan, $row['id'], 'setujui', null);
        $role3 = $this->aktor(Role::ADMIN_SATKER, [], $this->idSatker)[1];

        // M2 (legacy Lkh::delete :464-472) + L1: aksi konsisten dengan penegakan server.
        $this->assertSame(['ubah' => true, 'hapus' => false], array_intersect_key($this->svc()->detail($role3, $row['id'])['aksi'], ['ubah' => 1, 'hapus' => 1]));
        $this->assertStatusApi(403, fn () => $this->svc()->hapus($role3, $row['id']));
        $admin = $this->admin();
        $this->assertTrue($this->svc()->detail($admin, $row['id'])['aksi']['hapus']);
        $this->assertSame($row['id'], $this->svc()->hapus($admin, $row['id'])['id']);
    }

    public function testRole1MenimpaAtasanDanUbahMemperbaruiIdentitas(): void
    {
        $lain = $this->buatPegawai(['nama' => 'Atasan Pengganti']);
        $row  = $this->svc()->tambah($this->admin(), ['nip' => $this->nip, 'nip_atasan' => $lain, 'tgl_laporan' => '2026-10-12', 'kegiatan' => [$this->baris('08:00')]]);
        $this->assertSame([$lain, 'Atasan Pengganti'], [$row['nip_atasan'], $row['nama_atasan']]);
        $this->assertErrors(['nip_atasan'], fn () => $this->svc()->tambah($this->admin(), ['nip' => $this->nip, 'nip_atasan' => '000', 'tgl_laporan' => '2026-10-09', 'kegiatan' => [$this->baris('08:00')]]));

        // Pegawai tidak bisa menimpa atasan (diabaikan → atasan otomatis).
        $milik = $this->svc()->tambah($this->pegawai, ['nip_atasan' => $lain, 'tgl_laporan' => '2026-10-09', 'kegiatan' => [$this->baris('08:00')]]);
        $this->assertSame($this->nipAtasan, $milik['nip_atasan']);

        // L5: ubah menulis ulang nama/unit/satker terkini (legacy sp()).
        $this->db->table('pegawai')->where('nip', $this->nip)->update(['nama' => 'Nama Baru']);
        $this->assertSame('Nama Baru', $this->svc()->ubah($this->pegawai, $milik['id'], ['kegiatan' => [$this->baris('09:00')]])['nama']);
    }

    public function testRekapBawahanPerBulan(): void
    {
        // Atasan struktural Es.IV (sub group 4): bawahan = snapshot ber-id_atasan_es_4 = jabatan atasan (legacy list_rekap).
        // ID hard-code legacy: group 1 Struktural, sub group 4 Eselon IV.
        if ($this->db->table('group_jabatan')->where('id_group_jabatan', 1)->countAllResults() === 0) {
            $this->db->table('group_jabatan')->insert(['id_group_jabatan' => 1, 'group_jabatan' => 'Struktural', 'status' => 1]);
        }

        if ($this->db->table('sub_group_jabatan')->where('id_sub_group_jabatan', 4)->countAllResults() === 0) {
            $this->db->table('sub_group_jabatan')->insert(['id_sub_group_jabatan' => 4, 'id_group_jabatan' => 1, 'sub_group_jabatan' => 'Eselon IV', 'need_satker' => 1, 'status' => 1]);
        }
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $this->nipAtasan)->update(['id_group_jabatan' => 1, 'id_sub_group_jabatan' => 4]);
        $a = $this->tambah($this->pegawai, '2026-10-12');
        $this->tambah($this->pegawai, '2026-10-09');
        $this->svc()->proses($this->atasan, $a['id'], 'setujui', null);

        // [V2] + pegawai yang LKH-nya diajukan ke atasan ini (atasan ditimpa role 1).
        [$lain, $authLain] = $this->aktor(Role::PEGAWAI, [], $this->buatSatker());
        $this->svc()->tambah($this->admin(), ['nip' => $lain, 'nip_atasan' => $this->nipAtasan, 'tgl_laporan' => '2026-10-12', 'kegiatan' => [$this->baris('08:00')]]);

        $rekap = $this->svc()->rekap($this->atasan);
        $this->assertSame(['2026-10', '2026-10-01', '2026-10-13'], [$rekap['bulan'], $rekap['ds'], $rekap['de']]);
        $per = array_column($rekap['items'], null, 'nip');
        $this->assertEqualsCanonicalizing([$this->nip, $lain], array_keys($per));
        $this->assertSame([1, 1, 0, 0, 2], [$per[$this->nip]['menunggu'], $per[$this->nip]['disetujui'], $per[$this->nip]['ditolak'], $per[$this->nip]['revisi'], $per[$this->nip]['total']]);
        $this->assertSame(0, $this->svc()->rekap($this->atasan, '2026-09')['items'][0]['total'] ?? 0);

        $daftar = $this->svc()->rekapBawahan($this->atasan, $this->nip);
        $this->assertSame(['2026-10-09', '2026-10-12'], array_column($daftar['items'], 'tgl_laporan'));
        $this->assertSame($this->nip, $daftar['pegawai']['nip']);

        // Bukan bawahan → 403; bulan salah/masa depan → 422; pegawai tanpa bawahan → kosong.
        $this->assertStatusApi(403, fn () => $this->svc()->rekapBawahan($authLain, $this->nip));
        $this->assertErrors(['bulan'], fn () => $this->svc()->rekap($this->atasan, '2026-13'));
        $this->assertErrors(['bulan'], fn () => $this->svc()->rekap($this->atasan, '2026-11'));
        $this->assertSame([], $this->svc()->rekap($this->pegawai)['items']);

        $akun = $this->db->table('pengguna')->where('nip', $this->nipAtasan)->get()->getRowArray();
        $this->asUser($akun)->get('api/v1/lkh/rekap?bulan=2026-10')->assertStatus(200);
        $this->asUser($akun)->get("api/v1/lkh/rekap/{$this->nip}?bulan=2026-10")->assertStatus(200);
    }

    public function testEndpointPdf(): void
    {
        $row = $this->tambah($this->pegawai, '2026-10-12');
        $this->svc()->proses($this->atasan, $row['id'], 'setujui', null);
        $akun = $this->db->table('pengguna')->where('nip', $this->nip)->get()->getRowArray();

        $res = $this->asUser($akun)->get("api/v1/lkh/{$row['id']}/pdf");
        $res->assertStatus(200);
        $this->assertSame('application/pdf', explode(';', $res->response()->getHeaderLine('Content-Type'))[0]);
        $this->assertStringStartsWith('%PDF-', (string) $res->response()->getBody());
        $this->asUser($akun)->get('api/v1/lkh/verifikasi')->assertStatus(200);
    }

    /**
     * @return array<string, string>
     */
    private function baris(string $jamMulai): array
    {
        return ['kegiatan' => 'Menyusun laporan', 'output' => 'Draf laporan', 'jumlah_diselesaikan' => '1 dokumen', 'jam_mulai' => $jamMulai, 'jam_selesai' => '10:00'];
    }

    /**
     * @return array<string, mixed>
     */
    private function tambah(AuthContext $auth, string $tanggal): array
    {
        return $this->svc()->tambah($auth, ['tgl_laporan' => $tanggal, 'kegiatan' => [
            $this->baris('08:00'),
            ['kegiatan' => 'Rapat', 'output' => 'Notula', 'jumlah_diselesaikan' => '1', 'jam_mulai' => '10:00', 'jam_selesai' => '11:00'],
            ['kegiatan' => '', 'output' => '', 'jumlah_diselesaikan' => '', 'jam_mulai' => '', 'jam_selesai' => ''],
        ]]);
    }

    private function svc(): LkhService
    {
        return new LkhService(null, null, new AtasanLangsung($this->db));
    }

    /**
     * @param list<string> $fields
     */
    private function assertErrors(array $fields, Closure $aksi, string $pesan = ''): void
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            $this->assertSame($fields, array_keys($e->getErrors() ?? []), $pesan . ' ' . json_encode($e->getErrors()));

            return;
        }

        $this->fail('Diharapkan 422 untuk ' . implode(', ', $fields) . " {$pesan}");
    }

    private function assertStatusApi(int $status, Closure $aksi): void
    {
        try {
            $aksi();
            $this->fail("Diharapkan {$status}");
        } catch (ApiException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }
}
