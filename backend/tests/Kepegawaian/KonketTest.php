<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Konket\KonketService;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use Closure;
use CodeIgniter\Config\Factories;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Konket;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-13 Konket (WS-2 M5 MAKE-013): aturan legacy L_konket validate_param (tanggal, minimal, irisan, sakit, berkas),
 * dinas gabungan (peserta), proses, hapus status 10, hak akses & lingkup (PegawaiScope nyata). Hari ini dibekukan
 * 2026-10-13 WIB (tanggal minimal 2026-10-01).
 *
 * @internal
 */
final class KonketTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LampiranUjiTrait;
    use RiwayatUjiTrait {
        LampiranUjiTrait::berkasUji insteadof RiwayatUjiTrait;
    }

    private int $idSatker;
    private string $nip;
    private AuthContext $pegawai;
    private AuthContext $admin;
    private AuthContext $adminSatker;

    protected function setUp(): void
    {
        parent::setUp();

        Time::setTestNow('2026-10-13 10:00:00', 'Asia/Jakarta');
        Services::injectMock('pegawaiScope', new PegawaiScope());
        Services::injectMock('storageAdapter', new LocalStorageAdapter($this->akarLampiranUji()));

        foreach ([[1, 1, 'Izin'], [2, 2, 'Tugas Kantor'], [8, 8, 'Dinas Luar'], [10, 10, 'Sakit']] as [$id, $old, $nama]) {
            $this->db->table('jenis_konket')->insert(['id_jenis_konket' => $id + 100, 'old_id' => $old, 'jenis_konket' => "{$nama} Uji", 'affect_tukin' => 1, 'status' => 1]);
        }

        // id_jenis_konket 2 (terlarang untuk pegawai & role 3, legacy form) memakai id nyata 2.
        $this->db->table('jenis_konket')->where('old_id', 2)->update(['id_jenis_konket' => 2]);

        $this->idSatker              = $this->buatSatker();
        [$this->nip, $this->pegawai] = $this->aktor(Role::PEGAWAI, [], $this->idSatker);
        $this->admin                 = $this->aktor(Role::SUPER_ADMIN)[1];
        $this->adminSatker           = $this->aktor(Role::ADMIN_SATKER, [], $this->idSatker)[1];
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        $this->bersihkanLampiranUji();
        Services::reset(true);

        parent::tearDown();
    }

    public function testPegawaiMengajukanStatusMenungguDenganBerkas(): void
    {
        $row = $this->tambah($this->pegawai, $this->data('2026-10-05'));

        $this->assertSame([$this->nip, 'W', 1, 'Izin Uji', '2026-10-05', true], [$row['nip'], $row['status'], $row['affect_tukin'], $row['jenis_konket'], $row['date_start'], $row['ada_berkas']]);
        $this->assertArrayNotHasKey('file_bukti', $row, 'path berkas tidak dibocorkan');
        $this->assertSame(['ubah' => true, 'hapus' => true, 'proses' => false], $row['aksi']);
        $this->assertStringStartsWith('%PDF', $this->svc()->berkas($this->pegawai, $row['id'])['isi']);
    }

    /**
     * [V2] CR-050 (QA B-F1): pengajuan baru menyalin `affect_tukin` dari master jenis; admin tetap bisa mengubahnya; ubah
     * tidak menyalin ulang dari master.
     */
    public function testAffectTukinAwalDariMasterJenis(): void
    {
        $this->db->table('jenis_konket')->where('old_id', 8)->update(['affect_tukin' => 2]);
        $dinas = ['kategori' => 8] + $this->data('2026-10-05');

        $row = $this->tambah($this->pegawai, $dinas);
        $this->assertSame(2, $row['affect_tukin'], 'pegawai: nilai master');
        $this->assertSame(1, $this->tambah($this->pegawai, $this->data('2026-10-06'))['affect_tukin'], 'master jenis lain = 1');

        $admin = $this->tambah($this->admin, array_merge($dinas, ['nip' => $this->nip, 'affect_tukin' => 1, 'date_start' => '2026-10-07', 'date_end' => '2026-10-07']));
        $this->assertSame(1, $admin['affect_tukin'], 'admin menimpa nilai master');

        $this->db->table('jenis_konket')->where('old_id', 8)->update(['affect_tukin' => 1]);
        $ubah = $this->svc()->ubah($this->pegawai, $row['id'], ['alasan' => 'Diubah'] + $dinas, null);
        $this->assertSame(2, $ubah['affect_tukin'], 'jenis tetap: nilai tersimpan dipertahankan');
    }

    /**
     * CR-051 (QA R1F-F1): ubah yang mengganti jenis mengambil `affect_tukin` dari master jenis baru (pegawai, atau admin tanpa
     * `affect_tukin`); admin yang mengirim `affect_tukin` tetap menimpa.
     */
    public function testUbahJenisMengambilAffectTukinMasterJenisBaru(): void
    {
        $this->db->table('jenis_konket')->where('old_id', 8)->update(['affect_tukin' => 2]);
        $row = $this->tambah($this->pegawai, $this->data('2026-10-05'));
        $this->assertSame(1, $row['affect_tukin']);

        // Pegawai mengganti jenis 1 → 8 (master 2).
        $dinas = ['kategori' => 8, 'jenis_dinas' => 0] + $this->data('2026-10-05');
        $this->assertSame(2, $this->svc()->ubah($this->pegawai, $row['id'], $dinas, null)['affect_tukin'], 'pegawai: master jenis baru');

        // Admin mengganti jenis 8 → 1 tanpa affect_tukin → master jenis 1 (= 1).
        $this->assertSame(1, $this->svc()->ubah($this->admin, $row['id'], $this->data('2026-10-05'), null)['affect_tukin'], 'admin tanpa affect_tukin: master');

        // Admin mengganti jenis 1 → 8 sambil mengirim affect_tukin 1 → menimpa master (2).
        $this->assertSame(1, $this->svc()->ubah($this->admin, $row['id'], ['affect_tukin' => 1] + $dinas, null)['affect_tukin'], 'admin menimpa');
    }

    public function testValidasiTanggalJenisBerkasDanIrisan(): void
    {
        $this->assertErrors(['file_bukti'], fn () => $this->svc()->tambah($this->pegawai, $this->data('2026-10-05'), null));
        $this->assertErrors(['date_start', 'date_end'], fn () => $this->tambah($this->pegawai, $this->data('2026-10-14')));
        $this->assertErrors(['date_start'], fn () => $this->tambah($this->pegawai, $this->data('2026-09-30')), 'sebelum tanggal minimal');
        $this->assertErrors(['date_end'], fn () => $this->tambah($this->pegawai, ['date_end' => '2026-10-04'] + $this->data('2026-10-05')));
        $this->assertErrors(['kategori'], fn () => $this->tambah($this->pegawai, ['kategori' => 2] + $this->data('2026-10-05')), 'jenis terlarang untuk pegawai');
        $this->assertErrors(['alasan'], fn () => $this->tambah($this->pegawai, ['alasan' => ' '] + $this->data('2026-10-05')));

        $this->tambah($this->pegawai, ['date_end' => '2026-10-07'] + $this->data('2026-10-05'));
        $this->assertErrors(['date_start'], fn () => $this->tambah($this->pegawai, $this->data('2026-10-07')), 'irisan W/V');

        // Role 1 boleh jenis 2 (legacy form_ad).
        $this->assertSame(2, $this->tambah($this->admin, ['nip' => $this->nip, 'kategori' => 2] + $this->data('2026-10-09'))['kategori']);
    }

    public function testSakitMaksSatuHariDanTidakBerturutKecualiPtt(): void
    {
        $sakit = ['kategori' => 10];
        $this->assertErrors(['kategori'], fn () => $this->tambah($this->pegawai, $sakit + ['date_end' => '2026-10-06'] + $this->data('2026-10-05')));

        $this->tambah($this->pegawai, $sakit + $this->data('2026-10-05'));
        // Selasa 06 setelah Senin 05 → berturut-turut.
        $this->assertErrors(['kategori'], fn () => $this->tambah($this->pegawai, $sakit + $this->data('2026-10-06')));
        // Sabtu-Minggu tidak dihitung: Jumat 09 + akhir pekan = satu hari kerja, hari kerja sebelumnya Kamis 08 bebas.
        $this->tambah($this->pegawai, $sakit + ['date_end' => '2026-10-11'] + $this->data('2026-10-09'));

        // PTT (jenis pegawai 2) boleh lebih dari 1 hari.
        if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', 2)->countAllResults() === 0) {
            $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => 2, 'jenis_pegawai' => 'PTT', 'status' => 1]);
        }

        [$nipPtt, $ptt] = $this->aktor(Role::PTT, [], $this->idSatker);
        $this->db->table('pegawai')->where('nip', $nipPtt)->update(['id_jenis_pegawai' => 2]);
        $this->assertSame('W', $this->tambah($ptt, $sakit + ['date_end' => '2026-10-07'] + $this->data('2026-10-05'))['status']);
    }

    public function testDinasGabunganPesertaProsesDanHapusBersama(): void
    {
        $p1    = $this->buatPegawai();
        $p2    = $this->buatPegawai();
        $p3    = $this->buatPegawai();
        $dinas = ['kategori' => 8, 'jenis_dinas' => 1];

        $this->assertErrors(['peserta'], fn () => $this->tambah($this->pegawai, $dinas + $this->data('2026-10-05')));
        $row = $this->tambah($this->pegawai, $dinas + ['peserta' => [$p1, $p2, $this->nip]] + $this->data('2026-10-05'));

        $this->assertSame([$this->nip, $p1, $p2], array_column($row['peserta'], 'nip'));
        $anak = $this->db->table('absen_ijin')->where('id_parent', $row['id'])->get()->getResultArray();
        $this->assertCount(2, $anak);
        $this->assertSame([1], array_values(array_unique(array_map(static fn ($r) => (int) $r['jenis_dinas'], $anak))));

        // Ubah: p2 keluar (status 10), p3 masuk; isian disalin.
        $this->svc()->ubah($this->pegawai, $row['id'], $dinas + ['peserta' => [$p1, $p3], 'alasan' => 'Rapat koordinasi'] + $this->data('2026-10-05'), null);
        $aktif = $this->db->table('absen_ijin')->where('id_parent', $row['id'])->where('status !=', '10')->get()->getResultArray();
        $this->assertSame([$p1, $p3], array_column($aktif, 'nip'));
        $this->assertSame(['Rapat koordinasi'], array_values(array_unique(array_column($aktif, 'alasan'))));

        // Proses induk → peserta ikut; baris peserta tidak bisa diproses sendiri.
        $this->assertErrors(['id_parent'], fn () => $this->svc()->proses($this->admin, (int) $aktif[0]['id'], 'setujui', null));
        $this->svc()->proses($this->adminSatker, $row['id'], 'setujui', null);
        $this->assertSame(['V'], array_values(array_unique(array_column($this->db->table('absen_ijin')->whereIn('id', [$row['id'], ...array_column($aktif, 'id')])->get()->getResultArray(), 'status'))));

        // M1: role 3 hanya boleh memilih peserta dalam lingkupnya; pegawai boleh semua pegawai aktif (sudah di atas).
        $luar = $this->buatPegawaiDiSatker($this->buatSatker());
        $this->assertErrors(['peserta'], fn () => $this->tambah($this->adminSatker, ['nip' => $this->nip, 'peserta' => [$luar]] + $dinas + $this->data('2026-10-09')));
        $dalam = $this->buatPegawaiDiSatker($this->idSatker);
        $this->assertSame(2, count($this->tambah($this->adminSatker, ['nip' => $this->nip, 'peserta' => [$dalam]] + $dinas + $this->data('2026-10-09'))['peserta']));

        // Hapus dari baris peserta → seluruh kelompok status 10 (legacy delete :897-925).
        $hapus = $this->svc()->hapus($this->admin, (int) $aktif[0]['id']);
        $this->assertCount(3, $hapus['ids']);
        $this->assertSame(0, $this->db->table('absen_ijin')->whereIn('id', $hapus['ids'])->where('status !=', '10')->countAllResults());
    }

    public function testProsesTolakWajibAlasanDanHanyaMenunggu(): void
    {
        $row = $this->tambah($this->pegawai, $this->data('2026-10-05'));

        $this->assertErrors(['reason_note'], fn () => $this->svc()->proses($this->admin, $row['id'], 'tolak', ' '));
        $hasil = $this->svc()->proses($this->admin, $row['id'], 'tolak', 'Bukti tidak sesuai');
        $this->assertSame(['X', 'Bukti tidak sesuai'], [$hasil['status'], $hasil['reason_note']]);
        $db = $this->db->table('absen_ijin')->where('id', $row['id'])->get()->getRowArray();
        $this->assertSame(['1', '1'], [(string) $db['show_notif'], (string) $db['show_ua_biro']]);

        $this->assertErrors(['status'], fn () => $this->svc()->proses($this->admin, $row['id'], 'setujui', null));
    }

    public function testHakAksesDanLingkup(): void
    {
        $row      = $this->tambah($this->pegawai, $this->data('2026-10-05'));
        [, $lain] = $this->aktor(Role::PEGAWAI, [], $this->buatSatker());

        // Pegawai lain tidak melihat; role 8 tidak punya akses.
        $this->assertStatusApi(404, fn () => $this->svc()->detail($lain, $row['id']));
        $this->assertStatusApi(403, fn () => $this->svc()->daftar($this->aktor(Role::PIMPINAN)[1]));

        // [K] role 4/5 lihat saja (legacy list_ad/detail): antrean semua + detail, tanpa tulis/proses.
        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI] as $role) {
            $pengamat = $this->aktor($role)[1];
            $daftar   = $this->svc()->daftar($pengamat);
            $this->assertSame(['admin', false, [$row['id']]], [$daftar['mode'], $daftar['can_create'], array_column($daftar['items'], 'id')]);
            $this->assertSame(['ubah' => false, 'hapus' => false, 'proses' => false], $this->svc()->detail($pengamat, $row['id'])['aksi']);
            $this->assertStatusApi(403, fn () => $this->tambah($pengamat, ['nip' => $this->nip] + $this->data('2026-10-09')));
            $this->assertStatusApi(403, fn () => $this->svc()->hapus($pengamat, $row['id']));
            $this->assertStatusApi(403, fn () => $this->svc()->proses($pengamat, $row['id'], 'setujui', null));
        }

        // Admin satker lain: tidak terlihat, tidak bisa menambah untuk pegawai di luar lingkup.
        $adminLain = $this->aktor(Role::ADMIN_SATKER, [], $this->buatSatker())[1];
        $this->assertStatusApi(404, fn () => $this->svc()->detail($adminLain, $row['id']));
        $this->assertStatusApi(403, fn () => $this->tambah($adminLain, ['nip' => $this->nip] + $this->data('2026-10-09')));
        $this->assertSame([$row['id']], array_column($this->svc()->daftar($this->adminSatker)['items'], 'id'));
        $this->assertSame([], $this->svc()->daftar($adminLain)['items']);

        // Pegawai tidak bisa mengubah setelah diproses, tetapi tetap boleh menghapus (legacy delete tanpa syarat status).
        $this->svc()->proses($this->admin, $row['id'], 'setujui', null);
        $this->assertStatusApi(403, fn () => $this->svc()->ubah($this->pegawai, $row['id'], $this->data('2026-10-05'), null));
        $this->assertSame([$row['id']], $this->svc()->hapus($this->pegawai, $row['id'])['ids']);
        $this->assertStatusApi(404, fn () => $this->svc()->detail($this->pegawai, $row['id']));
        $this->assertSame('10', (string) $this->db->table('absen_ijin')->where('id', $row['id'])->get()->getRow()->status);
    }

    /**
     * CR-050 (QA B-F4): daftar jenis terlarang dari `Config\Konket` (kunci id_jenis_konket [K]), bukan hard-code service.
     * Bawaan = legacy; mengganti konfigurasi langsung mengubah opsi & validasi tanpa menyentuh service.
     */
    public function testJenisTerlarangDariKonfigurasi(): void
    {
        $config = config(Konket::class);
        $this->assertSame([[2, 4, 5, 6], [4, 5]], [$config->jenisTerlarangPegawai, $config->jenisTerlarangAdmin], 'bawaan = legacy');

        // Pegawai bisa mengajukan Dinas (id 108) dan Sakit (id 110): id bebas.
        $this->assertSame(8, $this->tambah($this->pegawai, ['kategori' => 8] + $this->data('2026-10-05'))['kategori']);

        $config->jenisTerlarangPegawai = [108];

        try {
            $this->assertErrors(['kategori'], fn () => $this->tambah($this->pegawai, ['kategori' => 8] + $this->data('2026-10-06')), 'Dinas kini terlarang');
            $this->assertSame(2, $this->tambah($this->pegawai, ['kategori' => 2] + $this->data('2026-10-07'))['kategori'], 'id 2 kini boleh');
            $kategori = array_column($this->svc()->opsi($this->pegawai)['jenis'], 'kategori');
            $this->assertNotContains(8, $kategori);
            $this->assertContains(2, $kategori);
        } finally {
            Factories::reset('config');
        }
    }

    public function testDaftarDanOpsiPerRole(): void
    {
        $this->tambah($this->pegawai, $this->data('2026-10-05'));
        $this->tambah($this->admin, ['nip' => $this->nip, 'affect_tukin' => 2] + $this->data('2026-10-09'));

        $milik = $this->svc()->daftar($this->pegawai);
        $this->assertSame(['pegawai', 2], [$milik['mode'], $milik['meta']['total']]);
        $this->assertSame(['2026-10-09', '2026-10-05'], array_column($milik['items'], 'date_start'));

        $admin = $this->svc()->daftar($this->admin, ['ds' => '2026-10-06', 'de' => '2026-10-31']);
        $this->assertSame(['admin', ['2026-10-09'], 2], [$admin['mode'], array_column($admin['items'], 'date_start'), $admin['items'][0]['affect_tukin']]);

        $opsi = $this->svc()->opsi($this->pegawai);
        $this->assertSame('2026-10-01', $opsi['tanggal_minimal']);
        $this->assertNotContains(2, array_column($opsi['jenis'], 'kategori'));
        $this->assertContains(2, array_column($this->svc()->opsi($this->admin)['jenis'], 'kategori'));
    }

    public function testEndpoint(): void
    {
        $akun = $this->db->table('pengguna')->where('nip', $this->nip)->get()->getRowArray();
        $this->asUser($akun)->get('api/v1/konket/opsi')->assertStatus(200);
        $this->asUser($akun)->get('api/v1/konket')->assertStatus(200);
        $this->asUser($akun)->withBodyFormat('json')->post('api/v1/konket', $this->data('2026-10-05'))->assertStatus(422);
        [, , $view] = $this->aktor(Role::PIMPINAN);
        $this->asUser($view)->get('api/v1/konket')->assertStatus(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(string $tanggal): array
    {
        return ['date_start' => $tanggal, 'date_end' => $tanggal, 'kategori' => 1, 'alasan' => 'Keperluan keluarga'];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function tambah(AuthContext $auth, array $data): array
    {
        return $this->svc()->tambah($auth, $data, $this->berkasPdf('bukti.pdf'));
    }

    private function svc(): KonketService
    {
        return new KonketService(null, null, new KolomBerkas(new LocalStorageAdapter($this->akarLampiranUji()), $this->db));
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
