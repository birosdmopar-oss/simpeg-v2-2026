<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Pegawai\BiodataService;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use App\Models\Kepegawaian\PegawaiHistModel;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * B-04 Approval pengajuan biodata (MAKE-011) dengan PegawaiScope NYATA: antrean jalur proses (CR-045), setujui menyalin
 * biodata + sinkron email + galeri foto, tolak dengan alasan wajib tanpa mengubah pegawai (bug legacy :1098 tidak
 * ditiru), penanda notifikasi, lingkup role 3, urutan 403/404/422, endpoint.
 *
 * @internal
 */
final class ApprovalBiodataTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LampiranUjiTrait;
    use PegawaiFixtureTrait;

    protected function setUp(): void
    {
        parent::setUp();

        Services::injectMock('pegawaiScope', new PegawaiScope());
        Services::injectMock('storageAdapter', new LocalStorageAdapter($this->akarLampiranUji()));
    }

    protected function tearDown(): void
    {
        $this->bersihkanLampiranUji();
        Services::resetSingle('storageAdapter');
        Services::resetSingle('pegawaiScope');

        parent::tearDown();
    }

    public function testSetujuiMenyalinBiodataSinkronEmailDanPenanda(): void
    {
        $nip   = $this->buatPegawai(['no_hp' => '0811', 'status' => 1]);
        $akun  = $this->buatAkunUntuk($nip, Role::PEGAWAI, ['email' => 'lama@contoh.test']);
        $id    = $this->ajukan($nip, ['no_hp' => '0822', 'email' => 'baru@contoh.test', 'status' => 2, 'foto' => 'kolom/2026/10/baru.png']);
        $super = $this->auth(Role::SUPER_ADMIN);

        $hasil = $this->service()->prosesPengajuan($super, $id, 'setujui', null);

        $pegawai = $this->baris('pegawai', 'nip', $nip);
        $this->assertSame(['0822', PegawaiHistModel::FLAG_DISETUJUI, 1], [$pegawai['no_hp'], (int) $pegawai['flag_update'], (int) $pegawai['status']], 'status pegawai tidak ikut disalin [V2]');
        $this->assertSame('kolom/2026/10/baru.png', $pegawai['foto']);
        $this->assertSame(1, $this->db->table('pegawai_foto')->where('nip', $nip)->where('foto', 'kolom/2026/10/baru.png')->countAllResults());
        $this->assertSame('baru@contoh.test', $this->baris('pengguna', 'id_pengguna', $akun)['email']);

        $hist = $this->baris('pegawai_hist', 'id_pegawai_hist', $id);
        $this->assertSame([3, 1, 1, 0, 0], [(int) $hist['flag_update'], (int) $hist['show_notif'], (int) $hist['show_ua_biro'], (int) $hist['show_ua_upt'], (int) $hist['show_ua_deputi']]);
        $this->assertNotNull($hist['notif_date']);
        $this->assertSame((string) $super->idPengguna(), (string) $hist['approved_by']);
        $this->assertFalse($hasil['can_process']);
    }

    public function testTolakWajibAlasanDanPegawaiTidakBerubah(): void
    {
        $nip   = $this->buatPegawai(['no_hp' => '0811']);
        $id    = $this->ajukan($nip, ['no_hp' => '0822']);
        $super = $this->auth(Role::SUPER_ADMIN);

        $this->assertErrors(['reason_note'], fn () => $this->service()->prosesPengajuan($super, $id, 'tolak', '  '));
        $this->assertErrors(['aksi'], fn () => $this->service()->prosesPengajuan($super, $id, 'hapus', null));

        $this->service()->prosesPengajuan($super, $id, 'tolak', 'NPWP tidak sesuai');

        $pegawai = $this->baris('pegawai', 'nip', $nip);
        $this->assertSame(['0811', PegawaiHistModel::FLAG_DITOLAK], [$pegawai['no_hp'], (int) $pegawai['flag_update']]);
        $hist = $this->baris('pegawai_hist', 'id_pegawai_hist', $id);
        $this->assertSame([2, 'NPWP tidak sesuai', 1], [(int) $hist['flag_update'], $hist['reason_note'], (int) $hist['show_notif']]);

        // Sudah diproses → 422, tidak bisa diproses ulang.
        $this->assertErrors(['aksi'], fn () => $this->service()->prosesPengajuan($super, $id, 'setujui', null));
    }

    public function testLingkupAdminSatkerDanUrutanPemeriksaan(): void
    {
        $idSatker = $this->buatSatker();
        $dalam    = $this->ajukan($this->buatPegawaiDiSatker($idSatker));
        $luar     = $this->ajukan($this->buatPegawaiDiSatker($this->buatSatker()));
        $admin3   = $this->auth(Role::ADMIN_SATKER, $idSatker);

        $this->assertForbidden(fn () => $this->service()->prosesPengajuan($admin3, $luar, 'setujui', null));
        // Lingkup diperiksa sebelum validasi: di luar lingkup tetap 403 walau aksi tidak sah.
        $this->assertForbidden(fn () => $this->service()->prosesPengajuan($admin3, $luar, 'xx', null));

        $this->service()->prosesPengajuan($admin3, $dalam, 'setujui', null);
        $hist = $this->baris('pegawai_hist', 'id_pegawai_hist', $dalam);
        $this->assertSame([1, 0, 0], [(int) $hist['show_ua_upt'], (int) $hist['show_ua_deputi'], (int) $hist['show_ua_biro']]);

        foreach ([Role::PEGAWAI, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN, Role::PTT, Role::PPPK] as $role) {
            $this->assertForbidden(fn () => $this->service()->prosesPengajuan($this->auth($role), $luar, 'setujui', null));
        }

        $this->expectException(NotFoundException::class);
        $this->service()->prosesPengajuan($this->auth(Role::SUPER_ADMIN), 999999, 'setujui', null);
    }

    public function testAntreanJalurProsesAdminUnitKelembagaanTanpaUpt(): void
    {
        // CR-045: daftar pegawai admin unit 20 memuat UPT, antrean prosesnya TIDAK (L_notification get_na_ap_unit).
        $satker20  = $this->buatSatker($this->buatUnit(['id_unit' => PegawaiScope::UNIT_KELEMBAGAAN]));
        $satkerUpt = $this->buatSatker($this->buatUnit(['id_unit' => PegawaiScope::UNIT_UPT]));
        $kel       = $this->ajukan($this->buatPegawaiDiSatker($satker20));
        $upt       = $this->ajukan($this->buatPegawaiDiSatker($satkerUpt));
        $selesai   = $this->ajukan($this->buatPegawaiDiSatker($satker20), [], PegawaiHistModel::FLAG_DITOLAK);
        $admin     = $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawaiDiSatker($satker20), Role::ADMIN_SATKER, ['id_unit' => (string) PegawaiScope::UNIT_KELEMBAGAAN, 'id_satker' => null]));

        $ids = array_column($this->service()->antreanPengajuan($admin, ['per_page' => '100'])['items'], 'id_pegawai_hist');

        $this->assertContains((string) $kel, array_map('strval', $ids));
        $this->assertNotContains((string) $upt, array_map('strval', $ids));
        $this->assertNotContains((string) $selesai, array_map('strval', $ids), 'hanya pengajuan menunggu');

        $semua = array_map('strval', array_column($this->service()->antreanPengajuan($this->auth(Role::SUPER_ADMIN), ['per_page' => '100'])['items'], 'id_pegawai_hist'));
        $this->assertContains((string) $upt, $semua);
        $this->assertForbidden(fn () => $this->service()->antreanPengajuan($this->auth(Role::ADMIN_VIEW_ESELON1)));
    }

    public function testDetailPerubahanDanHakLihat(): void
    {
        $nip = $this->buatPegawai(['no_hp' => '0811', 'foto' => 'kolom/2026/10/lama.png']);
        $this->buatAkunUntuk($nip, Role::PEGAWAI, ['email' => 'a@contoh.test']);
        $id    = $this->ajukan($nip, ['no_hp' => '0822', 'email' => 'b@contoh.test', 'foto' => 'kolom/2026/10/baru.png']);
        $milik = $this->authUntukAkun((int) $this->db->table('pengguna')->where('nip', $nip)->get()->getRow()->id_pengguna);

        $detail = $this->service()->pengajuan($this->auth(Role::SUPER_ADMIN), $id);
        $kolom  = array_column($detail['perubahan'], null, 'kolom');

        $this->assertSame(['0811', '0822'], [$kolom['no_hp']['lama'], $kolom['no_hp']['baru']]);
        $this->assertSame(['a@contoh.test', 'b@contoh.test'], [$kolom['email']['lama'], $kolom['email']['baru']]);
        $this->assertSame([true, true], [$kolom['foto']['lama'], $kolom['foto']['baru']], 'path foto tidak dikirim');
        $this->assertArrayNotHasKey('foto', $detail['pengajuan']);
        $this->assertTrue($detail['can_process']);

        $this->assertFalse($this->service()->pengajuan($milik, $id)['can_process'], 'pemilik boleh melihat, tidak memproses');
        [, $lain] = $this->aktor(Role::PEGAWAI);
        $this->assertForbidden(fn () => $this->service()->pengajuan($lain, $id));
    }

    public function testEndpointProsesDanDetail(): void
    {
        $nip           = $this->buatPegawai(['no_hp' => '0811']);
        $id            = $this->ajukan($nip, ['no_hp' => '0822']);
        [, , $super]   = $this->aktorBaris(Role::SUPER_ADMIN);
        [, , $pegawai] = $this->aktorBaris(Role::PEGAWAI);

        $this->withBodyFormat('json')->post("api/v1/biodata/pengajuan/{$id}/process", ['aksi' => 'setujui'])->assertStatus(401);
        $this->asUser($pegawai)->withBodyFormat('json')->post("api/v1/biodata/pengajuan/{$id}/process", ['aksi' => 'setujui'])->assertStatus(403);
        $this->asUser($pegawai)->get('api/v1/biodata/pengajuan')->assertStatus(403);
        $this->asUser($super)->withBodyFormat('json')->post("api/v1/biodata/pengajuan/{$id}/process", ['aksi' => 'tolak'])->assertStatus(422);

        $antrean = $this->json($this->asUser($super)->get('api/v1/biodata/pengajuan?per_page=100'))['data'];
        $this->assertContains((string) $id, array_map('strval', array_column($antrean['items'], 'id_pegawai_hist')));

        $result = $this->asUser($super)->withBodyFormat('json')->post("api/v1/biodata/pengajuan/{$id}/process", ['aksi' => 'setujui', 'reason_note' => 'OK']);
        $result->assertStatus(200);
        $this->assertSame('3', (string) $this->json($result)['data']['pengajuan']['flag_update']);
        $this->assertSame('0822', $this->baris('pegawai', 'nip', $nip)['no_hp']);

        $this->asUser($super)->get("api/v1/biodata/pengajuan/{$id}")->assertStatus(200);
        $this->asUser($super)->get('api/v1/biodata/pengajuan/999999')->assertStatus(404);
    }

    public function testFotoPengajuan(): void
    {
        $nip   = $this->buatPegawai();
        $super = $this->auth(Role::SUPER_ADMIN);
        $tanpa = $this->ajukan($nip);

        try {
            $this->service()->fotoPengajuan($super, $tanpa);
            $this->fail('Seharusnya 404');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }

        $storage = new LocalStorageAdapter($this->akarLampiranUji());
        $storage->simpan('kolom/2026/10/uji.png', (string) file_get_contents($this->berkasPng()->getTempName()));
        $this->db->table('pegawai_hist')->where('id_pegawai_hist', $tanpa)->update(['foto' => 'kolom/2026/10/uji.png']);

        $this->assertSame('image/png', $this->service()->fotoPengajuan($super, $tanpa)['mime']);
    }

    private function service(): BiodataService
    {
        return new BiodataService(null, null, new KolomBerkas(new LocalStorageAdapter($this->akarLampiranUji()), $this->db));
    }

    /**
     * Pengajuan biodata `pegawai_hist` (salinan baris pegawai + $ubah); `pegawai.flag_update` = 1 bila menunggu.
     *
     * @param array<string, mixed> $ubah
     */
    private function ajukan(string $nip, array $ubah = [], int $flag = PegawaiHistModel::FLAG_DIAJUKAN): int
    {
        $pegawai = $this->baris('pegawai', 'nip', $nip);
        $kolom   = array_flip($this->db->getFieldNames('pegawai_hist'));
        $baris   = array_intersect_key(array_merge($pegawai, $ubah), $kolom);
        unset($baris['id_pegawai_hist'], $baris['created_at'], $baris['updated_at']);
        $baris['flag_update'] = $flag;

        $this->db->table('pegawai_hist')->insert($baris);
        $id = (int) $this->db->insertID();

        if ($flag === PegawaiHistModel::FLAG_DIAJUKAN) {
            $this->db->table('pegawai')->where('nip', $nip)->update(['flag_update' => PegawaiHistModel::FLAG_DIAJUKAN]);
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(string $tabel, string $pk, int|string $id): array
    {
        return (array) $this->db->table($tabel)->where($pk, $id)->get()->getRowArray();
    }

    private function auth(int $role, ?int $idSatker = null): AuthContext
    {
        $nip = $idSatker === null ? $this->buatPegawai() : $this->buatPegawaiDiSatker($idSatker);

        return $this->authUntukAkun($this->buatAkunUntuk($nip, $role));
    }

    /**
     * @return array{0: string, 1: AuthContext}
     */
    private function aktor(int $role): array
    {
        $nip = $this->buatPegawai();

        return [$nip, $this->authUntukAkun($this->buatAkunUntuk($nip, $role))];
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     */
    private function aktorBaris(int $role): array
    {
        $nip = $this->buatPegawai();
        $id  = $this->buatAkunUntuk($nip, $role);

        return [$nip, $id, $this->baris('pengguna', 'id_pengguna', $id)];
    }

    private function assertForbidden(callable $panggil): void
    {
        try {
            $panggil();
            $this->fail('Seharusnya 403');
        } catch (ForbiddenException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param list<string> $kolom
     */
    private function assertErrors(array $kolom, callable $panggil): void
    {
        try {
            $panggil();
            $this->fail('Seharusnya 422: ' . implode(', ', $kolom));
        } catch (ValidationException $e) {
            foreach ($kolom as $k) {
                $this->assertArrayHasKey($k, $e->getErrors(), "errors.{$k}");
            }
        }
    }
}
