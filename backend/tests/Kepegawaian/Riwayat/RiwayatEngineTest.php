<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\SnapshotSyncInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use App\Models\AuditLogModel;
use Closure;
use Config\Services;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\FakePegawaiScope;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * WS-1 M1 (MAKE-004) — RiwayatEngine lewat service (tanpa HTTP), dengan Definisi uji dan fake scope/lampiran S0:
 * alur draft → setujui/tolak → snapshot → audit → lingkup, kunci baris, validasi, lampiran, transaksi.
 *
 * @internal
 */
final class RiwayatEngineTest extends DatabaseTestCase
{
    use RiwayatUjiTrait;

    private string $nip;
    private AuthContext $pegawai;
    private AuthContext $admin;
    private int $idAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pasangEngine();
        [$this->nip, $this->pegawai] = $this->aktor(Role::PEGAWAI);
        [, $this->admin, $akun]      = $this->aktor(Role::SUPER_ADMIN);
        $this->idAdmin               = (int) $akun['id_pengguna'];

        $this->siapkanMasterKp();
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Alur draft → approve/reject → snapshot → audit
    // ------------------------------------------------------------------

    public function testPegawaiMengajukanStatus0TanpaSnapshot(): void
    {
        $row = $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', [
            'tgl_lulus' => '2012-08-30', 'institusi_pendidikan' => 'Universitas Uji', 'status' => 1, 'approved_by' => 99,
        ]);

        $this->assertSame('0', (string) $row['status'], 'kolom sistem dari klien diabaikan');
        $this->assertNull($row['approved_by']);
        $this->assertSame((string) $this->pegawai->idPengguna(), (string) $row['updated_by']);
        $this->assertNull($this->snapshotUji('pegawai_pendidikan', $this->nip));
        $this->assertSame([], array_filter($this->pendidikanUji->panggilanHook, static fn (array $h): bool => $h[0] === 'afterApprove'));

        $audit = $this->auditUji('riwayat_pendidikan', (string) $row['id_riwayat_pendidikan']);
        $this->assertSame([AuditLogModel::EVENT_CREATE], array_column($audit, 'event'));
        $this->assertSame((string) $this->pegawai->idPengguna(), (string) $audit[0]['id_pengguna_actor']);

        $this->assertCount(1, $this->notifikasiUji);
        $this->assertSame(RiwayatEngine::NOTIF_DIAJUKAN, $this->notifikasiUji[0]['tipe']);
        $this->assertSame($this->nip, $this->notifikasiUji[0]['nip']);
    }

    public function testSetujuiMengisiStatusSnapshotNotifDanAudit(): void
    {
        $id = (int) $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30'])['id_riwayat_pendidikan'];

        $row = $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $id, 'setujui', '');

        $this->assertSame('1', (string) $row['status']);
        $this->assertNull($row['reason_note'], 'reason_note kosong saat setujui = NULL');
        $this->assertSame((string) $this->idAdmin, (string) $row['approved_by']);
        $this->assertSame((string) $this->idAdmin, (string) $row['updated_by']);
        $this->assertSame('1', (string) $row['show_ua_biro']);
        $this->assertSame('1', (string) $row['show_notif']);
        $this->assertNotNull($row['notif_date']);

        $snap = $this->snapshotUji('pegawai_pendidikan', $this->nip);
        $this->assertSame((string) $id, (string) $snap['id_riwayat_pendidikan']);
        $this->assertSame('2012-08-30', $snap['tgl_lulus']);

        $audit = $this->auditUji('riwayat_pendidikan', (string) $id);
        $this->assertSame([AuditLogModel::EVENT_CREATE, AuditLogModel::EVENT_UPDATE], array_column($audit, 'event'), 'approve = event update');
        $this->assertSame('0', (string) json_decode((string) $audit[1]['before_json'], true)['status']);
        $this->assertSame('1', (string) json_decode((string) $audit[1]['after_json'], true)['status']);
        $this->assertSame(AuditLogModel::EVENT_CREATE, $this->auditUji('pegawai_pendidikan', $this->nip)[0]['event']);

        $this->assertSame(['afterApprove'], array_values(array_unique(array_column(
            array_filter($this->pendidikanUji->panggilanHook, static fn (array $h): bool => $h[0] === 'afterApprove'),
            0,
        ))));
        $this->assertSame(RiwayatEngine::NOTIF_DIPROSES, $this->notifikasiUji[1]['tipe']);
        $this->assertSame('Disetujui', $this->notifikasiUji[1]['aksi']);
    }

    public function testTolakWajibAlasanDanTidakMenyentuhSnapshot(): void
    {
        $aktif = (int) $this->engine()->tambah($this->admin, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30'])['id_riwayat_pendidikan'];
        $snap  = $this->snapshotUji('pegawai_pendidikan', $this->nip);
        $id    = (int) $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2020-08-30'])['id_riwayat_pendidikan'];

        $this->assertErrors(['reason_note'], fn () => $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $id, 'tolak', '   '));
        $this->assertErrors(['reason_note'], fn () => $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $id, 'tolak', str_repeat('x', 256)));
        $this->assertErrors(['aksi'], fn () => $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $id, 'hapus', 'x'));

        $row = $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $id, 'tolak', ' Ijazah buram ');

        $this->assertSame('2', (string) $row['status']);
        $this->assertSame('Ijazah buram', $row['reason_note']);
        $this->assertSame('1', (string) $row['show_notif']);
        $this->assertSame($snap, $this->snapshotUji('pegawai_pendidikan', $this->nip), 'snapshot tidak tersentuh');
        $this->assertSame((string) $aktif, (string) $snap['id_riwayat_pendidikan']);
        $this->assertSame('Ditolak', end($this->notifikasiUji)['aksi']);
    }

    public function testHanyaBarisMenungguYangBisaDiproses(): void
    {
        $id = (int) $this->engine()->tambah($this->admin, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30'])['id_riwayat_pendidikan'];

        $this->assertErrors(['status'], fn () => $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $id, 'setujui', null));
    }

    public function testAdminInputLangsungStatus1DanSnapshot(): void
    {
        $row = $this->engine()->tambah($this->admin, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30']);

        $this->assertSame('1', (string) $row['status']);
        $this->assertSame((string) $row['id_riwayat_pendidikan'], (string) $this->snapshotUji('pegawai_pendidikan', $this->nip)['id_riwayat_pendidikan']);
        $this->assertNotEmpty(array_filter($this->pendidikanUji->panggilanHook, static fn (array $h): bool => $h[0] === 'afterApprove'));
        $this->assertSame([], $this->notifikasiUji, 'input admin tidak membuat notifikasi pengajuan');
    }

    public function testShowUaRole3BersatkerDanTanpaSatker(): void
    {
        $idSatker        = $this->buatSatker();
        [, $adminUpt]    = $this->aktor(Role::ADMIN_SATKER, [], $idSatker);
        [, $adminDeputi] = $this->aktor(Role::ADMIN_SATKER, ['id_satker' => null]);
        $a               = (int) $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30'])['id_riwayat_pendidikan'];
        $b               = (int) $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2011-08-30'])['id_riwayat_pendidikan'];

        $rowA = $this->engine()->proses($adminUpt, $this->nip, 'pendidikan', $a, 'setujui', null);
        $rowB = $this->engine()->proses($adminDeputi, $this->nip, 'pendidikan', $b, 'tolak', 'x');

        $this->assertSame(['1', '0', '0'], [(string) $rowA['show_ua_upt'], (string) $rowA['show_ua_deputi'], (string) $rowA['show_ua_biro']]);
        $this->assertSame(['0', '1', '0'], [(string) $rowB['show_ua_upt'], (string) $rowB['show_ua_deputi'], (string) $rowB['show_ua_biro']]);
    }

    // ------------------------------------------------------------------
    // Ubah & hapus
    // ------------------------------------------------------------------

    public function testUbahStatusIkutLegacyPerRole(): void
    {
        $id = (int) $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30'])['id_riwayat_pendidikan'];
        $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $id, 'tolak', 'perbaiki');

        // Pegawai memperbaiki baris ditolak → diajukan ulang (0), notifikasi tipe 3.
        $row = $this->engine()->ubah($this->pegawai, $this->nip, 'pendidikan', $id, ['tgl_lulus' => '2010-09-01']);
        $this->assertSame('0', (string) $row['status']);
        $this->assertSame(RiwayatEngine::NOTIF_DIUBAH, end($this->notifikasiUji)['tipe']);

        // Role 1 mengubah → status tetap.
        $row = $this->engine()->ubah($this->admin, $this->nip, 'pendidikan', $id, ['institusi_pendidikan' => 'Kampus']);
        $this->assertSame('0', (string) $row['status']);
        $this->assertNull($this->snapshotUji('pegawai_pendidikan', $this->nip));

        // Role 3 mengubah → status 1, snapshot + afterApprove + penanda notifikasi.
        [, $adminSatker] = $this->aktor(Role::ADMIN_SATKER);
        $row             = $this->engine()->ubah($adminSatker, $this->nip, 'pendidikan', $id, []);
        $this->assertSame('1', (string) $row['status']);
        $this->assertSame('1', (string) $row['show_notif']);
        $this->assertSame((string) $id, (string) $this->snapshotUji('pegawai_pendidikan', $this->nip)['id_riwayat_pendidikan']);
    }

    public function testUbahBarisDisetujuiMemperbaruiSnapshot(): void
    {
        $id = (int) $this->engine()->tambah($this->admin, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30'])['id_riwayat_pendidikan'];

        $this->engine()->ubah($this->admin, $this->nip, 'pendidikan', $id, ['glr_akhir' => 'S.Par.']);

        $this->assertSame('S.Par.', $this->snapshotUji('pegawai_pendidikan', $this->nip)['glr_akhir']);
    }

    public function testPegawaiTidakBisaMengubahAtauMenghapusBarisDisetujui(): void
    {
        $id = (int) $this->engine()->tambah($this->admin, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30'])['id_riwayat_pendidikan'];

        $this->assertStatus(403, fn () => $this->engine()->ubah($this->pegawai, $this->nip, 'pendidikan', $id, ['glr_akhir' => 'X']));
        $this->assertStatus(403, fn () => $this->engine()->hapus($this->pegawai, $this->nip, 'pendidikan', $id));

        // Legacy: role 3 juga tidak boleh menghapus baris yang sudah disetujui; role 1 boleh.
        [, $adminSatker] = $this->aktor(Role::ADMIN_SATKER);
        $this->assertStatus(403, fn () => $this->engine()->hapus($adminSatker, $this->nip, 'pendidikan', $id));
    }

    public function testHapusLunakMenghitungUlangSnapshot(): void
    {
        $lama = (int) $this->engine()->tambah($this->admin, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30'])['id_riwayat_pendidikan'];
        $baru = (int) $this->engine()->tambah($this->admin, $this->nip, 'pendidikan', ['tgl_lulus' => '2015-08-30'])['id_riwayat_pendidikan'];
        $this->assertSame((string) $baru, (string) $this->snapshotUji('pegawai_pendidikan', $this->nip)['id_riwayat_pendidikan']);

        $this->engine()->hapus($this->admin, $this->nip, 'pendidikan', $baru);

        $this->assertSame('10', (string) $this->db->table('riwayat_pendidikan')->where('id_riwayat_pendidikan', $baru)->get()->getRow()->status);
        $this->assertSame((string) $lama, (string) $this->snapshotUji('pegawai_pendidikan', $this->nip)['id_riwayat_pendidikan']);
        $audit = $this->auditUji('riwayat_pendidikan', (string) $baru);
        $this->assertSame(AuditLogModel::EVENT_DELETE, $audit[count($audit) - 1]['event']);
        $this->assertStatus(404, fn () => $this->engine()->detail($this->admin, $this->nip, 'pendidikan', $baru));
        $this->assertSame([$lama], array_map('intval', array_column($this->engine()->daftar($this->admin, $this->nip, 'pendidikan'), 'id_riwayat_pendidikan')));

        $this->engine()->hapus($this->admin, $this->nip, 'pendidikan', $lama);
        $this->assertNull($this->snapshotUji('pegawai_pendidikan', $this->nip));
    }

    public function testPegawaiMenghapusPengajuanTanpaMenyentuhSnapshot(): void
    {
        $aktif       = (int) $this->engine()->tambah($this->admin, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30'])['id_riwayat_pendidikan'];
        $id          = (int) $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2020-08-30'])['id_riwayat_pendidikan'];
        $jumlahAudit = count($this->auditUji('pegawai_pendidikan', $this->nip));

        $this->engine()->hapus($this->pegawai, $this->nip, 'pendidikan', $id);

        $this->assertSame((string) $aktif, (string) $this->snapshotUji('pegawai_pendidikan', $this->nip)['id_riwayat_pendidikan']);
        $this->assertCount($jumlahAudit, $this->auditUji('pegawai_pendidikan', $this->nip));
    }

    public function testGuardUbahMemakaiStatusTerkiniDiDalamTransaksi(): void
    {
        // F1 (TOCTOU): baris dibaca berstatus 0, lalu disetujui "paralel" sebelum transaksi engine → pegawai ditolak 403
        // oleh guard yang diulang atas baca ulang terkunci; data dan snapshot tidak berubah.
        $id = (int) $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30'])['id_riwayat_pendidikan'];
        $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $id, 'setujui', null);
        $snap = $this->snapshotUji('pegawai_pendidikan', $this->nip);
        $this->db->table('riwayat_pendidikan')->where('id_riwayat_pendidikan', $id)->update(['status' => 0]);

        $this->pendidikanUji->saatValidate = function (): void {
            $this->db->table('riwayat_pendidikan')->where('nip', $this->nip)->update(['status' => 1]);
        };

        $this->assertStatus(403, fn () => $this->engine()->ubah($this->pegawai, $this->nip, 'pendidikan', $id, ['glr_akhir' => 'X']));

        $row = $this->db->table('riwayat_pendidikan')->where('id_riwayat_pendidikan', $id)->get()->getRowArray();
        $this->assertSame('1', (string) $row['status']);
        $this->assertNull($row['glr_akhir'], 'data yang belum disetujui tidak masuk ke baris berstatus 1');
        $this->assertSame($snap, $this->snapshotUji('pegawai_pendidikan', $this->nip));
        $this->assertSame(0, $this->db->transDepth);
    }

    public function testUbahBarisYangDihapusParalel404(): void
    {
        $id = (int) $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30'])['id_riwayat_pendidikan'];

        $this->pendidikanUji->saatValidate = function (): void {
            $this->db->table('riwayat_pendidikan')->where('nip', $this->nip)->update(['status' => 10]);
        };

        $this->assertStatus(404, fn () => $this->engine()->ubah($this->pegawai, $this->nip, 'pendidikan', $id, ['glr_akhir' => 'X']));
    }

    public function testStatusBaruDariBacaUlangBukanBacaAwal(): void
    {
        // Role 3 mengubah baris yang (paralel) sudah ditolak: status baru dihitung dari keadaan terkini.
        [, $adminSatker] = $this->aktor(Role::ADMIN_SATKER);
        $id              = $this->sisipRiwayat($this->pendidikanUji, $this->nip, ['tgl_lulus' => '2010-08-30', 'status' => 0]);

        $this->pendidikanUji->saatValidate = function (): void {
            $this->db->table('riwayat_pendidikan')->where('nip', $this->nip)->update(['status' => 2]);
        };

        $row = $this->engine()->ubah($adminSatker, $this->nip, 'pendidikan', $id, ['glr_akhir' => 'S.E.']);

        $this->assertSame('1', (string) $row['status']);
        $this->assertSame('0', (string) $row['show_notif'], 'transisi 2 → 1 bukan dari Menunggu');
        $this->assertSame((string) $id, (string) $this->snapshotUji('pegawai_pendidikan', $this->nip)['id_riwayat_pendidikan']);
    }

    public function testStatusNullDataLamaSaatDiubah(): void
    {
        // F3: status NULL diperlakukan seperti 0 (legacy Pendidikan.php:255-260); role 1 tidak mengubah status.
        $a               = $this->sisipRiwayat($this->pendidikanUji, $this->nip, ['tgl_lulus' => '2010-08-30', 'status' => null]);
        $b               = $this->sisipRiwayat($this->pendidikanUji, $this->nip, ['tgl_lulus' => '2011-08-30', 'status' => null]);
        $c               = $this->sisipRiwayat($this->pendidikanUji, $this->nip, ['tgl_lulus' => '2012-08-30', 'status' => null]);
        [, $adminSatker] = $this->aktor(Role::ADMIN_SATKER);

        $this->assertSame('0', (string) $this->engine()->ubah($this->pegawai, $this->nip, 'pendidikan', $a, ['glr_akhir' => 'A'])['status']);
        $this->assertSame(RiwayatEngine::NOTIF_DIUBAH, end($this->notifikasiUji)['tipe']);

        $rowB = $this->engine()->ubah($adminSatker, $this->nip, 'pendidikan', $b, ['glr_akhir' => 'B']);
        $this->assertSame('1', (string) $rowB['status']);
        $this->assertSame((string) $b, (string) $this->snapshotUji('pegawai_pendidikan', $this->nip)['id_riwayat_pendidikan']);

        $this->assertNull($this->engine()->ubah($this->admin, $this->nip, 'pendidikan', $c, ['glr_akhir' => 'C'])['status']);
    }

    public function testProses404SebelumValidasiAksi(): void
    {
        // F6: urutan kontrak — baris tidak ada (404) mendahului aksi tidak dikenal (422).
        $this->assertStatus(404, fn () => $this->engine()->proses($this->admin, $this->nip, 'pendidikan', 999999, 'hapus', null));
    }

    // ------------------------------------------------------------------
    // Multi-target
    // ------------------------------------------------------------------

    public function testKpMultiTargetLewatEngine(): void
    {
        $cpns = (int) $this->engine()->tambah($this->admin, $this->nip, 'kp', $this->dataKp(1, '2010-03-01'))['id_riwayat_kp'];
        $this->engine()->tambah($this->admin, $this->nip, 'kp', $this->dataKp(6, '2020-10-01'));

        $this->assertSame((string) $cpns, (string) $this->snapshotUji('pegawai_kp', $this->nip)['id_riwayat_kp'], 'jenis 6 bukan snapshot kp');
        $this->assertSame((string) $cpns, (string) $this->snapshotUji('pegawai_cpns', $this->nip)['id_riwayat_kp']);
        $this->assertNull($this->snapshotUji('pegawai_pns', $this->nip));

        // Alur admin: pegawai tidak berizin tambah (403), dan baris dari admin langsung 1.
        $this->assertStatus(403, fn () => $this->engine()->tambah($this->pegawai, $this->nip, 'kp', $this->dataKp(3, '2015-04-01')));

        $this->engine()->hapus($this->admin, $this->nip, 'kp', $cpns);
        $this->assertNull($this->snapshotUji('pegawai_kp', $this->nip));
        $this->assertNull($this->snapshotUji('pegawai_cpns', $this->nip));
    }

    // ------------------------------------------------------------------
    // Urutan pemeriksaan: jenis → izin → lingkup → NIP → baris
    // ------------------------------------------------------------------

    public function testJenisTidakTerdaftar404(): void
    {
        $this->assertStatus(404, fn () => $this->engine()->daftar($this->admin, $this->nip, 'kgb'));
    }

    public function testRoleTanpaIzin403(): void
    {
        [, $eselon1] = $this->aktor(Role::ADMIN_VIEW_ESELON1);

        $this->assertSame([], $this->engine()->daftar($eselon1, $this->nip, 'pendidikan'), 'role 4 berizin lihat');
        $this->assertStatus(403, fn () => $this->engine()->tambah($eselon1, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30']));
        $this->assertStatus(403, fn () => $this->engine()->proses($this->pegawai, $this->nip, 'pendidikan', 1, 'setujui', null));
    }

    public function testLingkupPegawaiScope(): void
    {
        $lain = $this->buatPegawai();
        Services::injectMock('pegawaiScope', FakePegawaiScope::izinkanHanya([$this->nip], []));

        $this->assertStatus(403, fn () => $this->engine()->daftar($this->admin, $lain, 'pendidikan'), 'di luar lingkup lihat');
        $this->assertSame([], $this->engine()->daftar($this->admin, $this->nip, 'pendidikan'), 'dalam lingkup lihat');
        $this->assertStatus(403, fn () => $this->engine()->tambah($this->admin, $this->nip, 'pendidikan', ['tgl_lulus' => '2010-08-30']), 'lihat saja, tanpa ubah');
        // NIP yang tidak ada dijawab sama dengan di luar lingkup bagi pemanggil ber-lingkup.
        $this->assertStatus(403, fn () => $this->engine()->daftar($this->admin, '999999999999999999', 'pendidikan'));
    }

    public function testNipTidakAdaDanBarisMilikNipLain404(): void
    {
        $this->assertStatus(404, fn () => $this->engine()->daftar($this->admin, '999999999999999999', 'pendidikan'));

        $lain = $this->buatPegawai();
        $id   = (int) $this->engine()->tambah($this->admin, $lain, 'pendidikan', ['tgl_lulus' => '2010-08-30'])['id_riwayat_pendidikan'];

        $this->assertStatus(404, fn () => $this->engine()->detail($this->admin, $this->nip, 'pendidikan', $id));
        $this->assertStatus(404, fn () => $this->engine()->ubah($this->admin, $this->nip, 'pendidikan', $id, []));
        $this->assertStatus(404, fn () => $this->engine()->hapus($this->admin, $this->nip, 'pendidikan', $id));
        $this->assertStatus(404, fn () => $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $id, 'setujui', null));
    }

    // ------------------------------------------------------------------
    // Validasi
    // ------------------------------------------------------------------

    public function testValidasiField(): void
    {
        $this->assertErrors(['tgl_lulus'], fn () => $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', []));
        $this->assertErrors(['tgl_lulus'], fn () => $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '30-08-2012']));
        $this->assertErrors(['institusi_pendidikan'], fn () => $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30', 'institusi_pendidikan' => ['x']]));

        // String kosong pada field opsional = NULL.
        $row = $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30', 'institusi_pendidikan' => '  ']);
        $this->assertNull($row['institusi_pendidikan']);
    }

    public function testValidasiRefMaster(): void
    {
        $aktif    = $this->buatJenjang('S1', 7);
        $nonaktif = $this->buatJenjang('S9', 9, 2);

        $this->assertErrors(['id_jenjang_pendidikan'], fn () => $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30', 'id_jenjang_pendidikan' => 99999]));
        $this->assertErrors(['id_jenjang_pendidikan'], fn () => $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30', 'id_jenjang_pendidikan' => $nonaktif]));

        $row = $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30', 'id_jenjang_pendidikan' => $aktif]);
        $this->assertSame('S1', $row['jenjang_pendidikan_singkat'], 'beforeSave mengisi kolom turunan');

        // Data lama yang merujuk master yang kemudian dinonaktifkan tetap bisa diubah tanpa mengganti rujukannya.
        $this->db->table('jenjang_pendidikan')->where('id_jenjang_pendidikan', $aktif)->update(['status' => 2]);
        $this->engine()->ubah($this->pegawai, $this->nip, 'pendidikan', (int) $row['id_riwayat_pendidikan'], ['id_jenjang_pendidikan' => $aktif, 'glr_akhir' => 'S.T.']);
    }

    public function testHookValidate(): void
    {
        $this->pendidikanUji->errorValidate = ['tgl_lulus' => ['Tanggal lulus tidak masuk akal.']];

        $this->assertErrors(['tgl_lulus'], fn () => $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30']));
        $this->assertSame(0, $this->db->table('riwayat_pendidikan')->where('nip', $this->nip)->countAllResults());
    }

    // ------------------------------------------------------------------
    // Lampiran (fake S0; versi nyata MAKE-009)
    // ------------------------------------------------------------------

    public function testLampiranWajibDanKodeTidakDikenal(): void
    {
        $this->pendidikanUji->lampiranWajib = true;

        $this->assertErrors(['berkas.14'], fn () => $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30']));
        $this->assertErrors(['berkas.99'], fn () => $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30'], [14 => $this->berkasUji(), 99 => $this->berkasUji()]));

        $row = $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30'], [14 => $this->berkasUji()]);
        $id  = (string) $row['id_riwayat_pendidikan'];
        $this->assertCount(1, $this->lampiranUji->daftar($this->nip, 14, $id));

        // Ubah tanpa berkas baru: lampiran wajib sudah ada → lolos.
        $this->engine()->ubah($this->pegawai, $this->nip, 'pendidikan', (int) $id, ['glr_akhir' => 'S.Kom.']);
    }

    public function testBerkasDitolakMembatalkanSeluruhTransaksi(): void
    {
        $this->assertErrors(['berkas.14'], fn () => $this->engine()->tambah($this->pegawai, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30'], [14 => $this->berkasUji('x.pdf', false)]));
        // 39 dibatasi 1 MB.
        $this->assertErrors(['berkas.39'], fn () => $this->engine()->tambah($this->admin, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30'], [39 => $this->berkasUji('besar.pdf', true, 1024 * 1024)]));

        $this->assertSame(0, $this->db->table('riwayat_pendidikan')->where('nip', $this->nip)->countAllResults());
        $this->assertNull($this->snapshotUji('pegawai_pendidikan', $this->nip));
    }

    public function testSnapshotGagalMembatalkanTulisanRiwayat(): void
    {
        Services::injectMock('snapshotSync', new class () implements SnapshotSyncInterface {
            public function sinkronkan(RiwayatDefinisi $definisi, string $nip, ?AuthContext $pelaku = null): void
            {
                throw new RuntimeException('snapshot gagal');
            }
        });

        try {
            $this->engine()->tambah($this->admin, $this->nip, 'pendidikan', ['tgl_lulus' => '2012-08-30']);
            $this->fail('exception diharapkan');
        } catch (RuntimeException $e) {
            $this->assertSame('snapshot gagal', $e->getMessage());
        }

        $this->assertSame(0, $this->db->table('riwayat_pendidikan')->where('nip', $this->nip)->countAllResults());
        $this->assertSame(0, $this->db->transDepth);
    }

    public function testDaftarUrutTerbaruDanTanpaDihapus(): void
    {
        $a = $this->sisipRiwayat($this->pendidikanUji, $this->nip, ['tgl_lulus' => '2010-01-01', 'status' => null]);
        $b = $this->sisipRiwayat($this->pendidikanUji, $this->nip, ['tgl_lulus' => '2011-01-01', 'status' => 2]);
        $this->sisipRiwayat($this->pendidikanUji, $this->nip, ['tgl_lulus' => '2012-01-01', 'status' => 10]);

        $ids = array_map('intval', array_column($this->engine()->daftar($this->pegawai, $this->nip, 'pendidikan'), 'id_riwayat_pendidikan'));

        $this->assertSame([$b, $a], $ids, 'status NULL (data lama) tetap tampil');
    }

    private function engine(): RiwayatEngine
    {
        /** @var RiwayatEngine $engine */
        $engine = service('riwayatService');

        return $engine;
    }

    private function assertStatus(int $kode, Closure $aksi, string $pesan = ''): void
    {
        try {
            $aksi();
        } catch (ApiException $e) {
            $this->assertSame($kode, $e->getStatusCode(), $pesan . ' ' . $e->getMessage());
            $this->assertInstanceOf(match ($kode) {
                403     => ForbiddenException::class,
                404     => NotFoundException::class,
                default => ApiException::class,
            }, $e);

            return;
        }

        $this->fail("Diharapkan {$kode}. {$pesan}");
    }

    /**
     * @param list<string> $fields
     */
    private function assertErrors(array $fields, Closure $aksi): void
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            $this->assertSame($fields, array_keys($e->getErrors() ?? []), (string) json_encode($e->getErrors()));

            return;
        }

        $this->fail('Diharapkan 422 untuk ' . implode(', ', $fields));
    }
}
