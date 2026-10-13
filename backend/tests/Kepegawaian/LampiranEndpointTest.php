<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\AttachmentService;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Lampiran\OtorisasiLampiran;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Pendidikan;
use App\Libraries\Kepegawaian\Riwayat\RiwayatRegistry;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-009 — endpoint `pegawai/{nip}/lampiran*` dengan PegawaiScope + AttachmentService nyata dan Definisi contoh
 * pendidikan (kode 14 wajib, 39/40 tidak wajib; izin 1,2,3,6,7; hapus 1,3). Urutan otorisasi: daftar/unggah = kode →
 * izin Definisi → lingkup → keterikatan id_entri → kunci baris Disetujui; unduh/hapus = lingkup lihat → izin di
 * Definisi mana pun → baris lampiran → izin Definisi → baris riwayat (+ lingkup ubah, kunci baris, lampiran wajib
 * terakhir untuk hapus). Unggah/hapus diulang di dalam transaksi (kunciUnggah/kunciHapus).
 * CR-046: unggah/hapus juga memeriksa izin per pegawai `RiwayatDefinisi::bolehAksiUntuk()` sesudah lingkup tulis — diuji
 * dengan Definisi Pendidikan NYATA (registry produksi): role 2 hanya selama CPNS.
 *
 * @internal
 */
final class LampiranEndpointTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LampiranUjiTrait;
    use PegawaiFixtureTrait;

    private string $nip;

    /**
     * Baris riwayat pendidikan status 1 Disetujui milik $nip (dibuat admin).
     */
    private int $idEntri;

    private int $satker;

    protected function setUp(): void
    {
        parent::setUp();

        Services::injectMock('riwayatRegistry', RiwayatRegistry::dariFolder(
            static fn (): PegawaiScopeInterface => service('pegawaiScope'),
            SUPPORTPATH . 'Kepegawaian/Definisi',
            'Tests\Support\Kepegawaian\Definisi',
        ));
        Services::injectMock('attachmentService', new AttachmentService(new LocalStorageAdapter($this->akarLampiranUji())));

        $this->satker  = $this->buatSatker();
        $this->nip     = $this->buatPegawaiDiSatker($this->satker);
        $this->idEntri = $this->buatRiwayatPendidikanUji($this->nip, 1);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLampiranUji();
        Services::reset(true);

        parent::tearDown();
    }

    public function testAlurUnggahDaftarUnduhHapusOlehPegawaiSendiri(): void
    {
        $pegawai = $this->akun($this->nip, Role::PEGAWAI);
        // Definisi contoh tanpa override bolehAksiUntuk (default true): pegawai tanpa snapshot pegawai_kp (bukan CPNS)
        // tetap boleh unggah/hapus — CR-046 tidak mengubah Definisi tanpa hook.
        $this->assertSame(0, $this->db->table('pegawai_kp')->where('nip', $this->nip)->countAllResults());
        // Pegawai hanya mengelola lampiran baris yang belum disetujui (baris Disetujui terkunci, kunciBarisDisetujui()).
        $idEntri = (string) $this->buatRiwayatPendidikanUji($this->nip, 0);

        $unggah = $this->unggah($pegawai, $this->nip, 14, $idEntri, $this->berkasPdf('Ijazah S1 ✓.pdf'));
        $this->assertSame(201, $unggah->response()->getStatusCode(), (string) $unggah->response()->getBody());
        $row = $this->json($unggah)['data'];
        $this->assertArrayNotHasKey('path', $row);
        $this->assertSame($this->nip, $row['NIP']);
        $this->assertSame('Ijazah S1 ✓.pdf', $row['display_name']);
        $id = (int) $row['id_attachment'];

        $daftar = $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=14&id_entri={$idEntri}");
        $daftar->assertStatus(200);
        $this->assertSame([$id], array_map('intval', array_column($this->json($daftar)['data'], 'id_attachment')));
        $this->assertArrayNotHasKey('path', $this->json($daftar)['data'][0]);

        $unduh = $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran/{$id}/unduh");
        $unduh->assertStatus(200);
        $this->assertStringStartsWith('%PDF-1.4', $unduh->response()->getBody());
        $this->assertStringStartsWith('application/pdf', $unduh->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString("filename*=UTF-8''Ijazah%20S1%20%E2%9C%93.pdf", $unduh->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame('nosniff', $unduh->response()->getHeaderLine('X-Content-Type-Options'));

        // "Ganti" = unggah baru dulu, lalu hapus yang lama (kode 14 wajib: lampiran terakhir tidak boleh dihapus).
        $baru = (int) $this->json($this->unggah($pegawai, $this->nip, 14, $idEntri, $this->berkasPdf('Ijazah baru.pdf')))['data']['id_attachment'];

        // Izin hapus Definisi pendidikan hanya role 1, 3 — tetapi `ubah` mencakup role 2, jadi hapus lampiran boleh.
        $hapus = $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$id}");
        $hapus->assertStatus(200);
        $this->assertTrue($this->json($hapus)['data']['deleted']);
        $this->assertSame(0, $this->db->table('document_attachment')->where('id_attachment', $id)->countAllResults());
        $this->assertCount(1, $this->berkasDiAkarUji());

        $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$baru}")->assertStatus(422);
    }

    public function testRole2PnsTidakBolehUnggahHapusLampiranPendidikanNyata(): void
    {
        $this->pakaiDefinisiProduksi();
        $pegawai = $this->akun($this->nip, Role::PEGAWAI);
        $idEntri = (string) $this->buatRiwayatPendidikanUji($this->nip, 0);

        // Selama CPNS: aturan lama (boleh unggah & hapus lampiran baris sendiri yang belum disetujui).
        $this->jadikanCpns($this->nip, true);
        $ijazah = (int) $this->json($this->unggah($pegawai, $this->nip, Pendidikan::LAMPIRAN_IJAZAH, $idEntri, $this->berkasPdf()))['data']['id_attachment'];
        $gelar  = (int) $this->json($this->unggah($pegawai, $this->nip, Pendidikan::LAMPIRAN_PENCANTUMAN_GELAR, $idEntri, $this->berkasPdf()))['data']['id_attachment'];
        $sisa   = (int) $this->json($this->unggah($pegawai, $this->nip, Pendidikan::LAMPIRAN_TRANSKRIP_NILAI, $idEntri, $this->berkasPdf()))['data']['id_attachment'];
        $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$sisa}")->assertStatus(200);

        // Sudah PNS, lalu tanpa snapshot pegawai_kp (= bukan CPNS): unggah & hapus 403, berkas tidak berubah.
        $keadaan = [
            'pns'            => fn () => $this->jadikanCpns($this->nip, false),
            'tanpa snapshot' => fn () => $this->db->table('pegawai_kp')->where('nip', $this->nip)->delete(),
        ];

        foreach ($keadaan as $label => $siapkan) {
            $siapkan();
            $this->unggah($pegawai, $this->nip, Pendidikan::LAMPIRAN_PENCANTUMAN_GELAR, $idEntri, $this->berkasPdf())->assertStatus(403);
            $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$gelar}")->assertStatus(403);
            $this->assertSame(2, $this->db->table('document_attachment')->where('NIP', $this->nip)->countAllResults(), $label);
            $this->assertCount(2, $this->berkasDiAkarUji(), $label);

            // Daftar & unduh tidak dibatasi hook (hanya tambah/ubah/hapus).
            $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=14&id_entri={$idEntri}")->assertStatus(200);
            $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran/{$ijazah}/unduh")->assertStatus(200);

            // Urutan kontrak tetap: id lampiran tidak ada → 404 (dibaca sebelum izin per pegawai).
            $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/lampiran/999999999")->assertStatus(404);
        }

        // Kembali CPNS → boleh lagi.
        $this->jadikanCpns($this->nip, true);
        $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$gelar}")->assertStatus(200);
    }

    public function testIzinPerPegawaiPendidikanTidakMembatasiAdmin(): void
    {
        $this->pakaiDefinisiProduksi();
        $this->jadikanCpns($this->nip, false);
        $entri = (string) $this->idEntri;

        $superAdmin  = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $adminSatker = $this->akun($this->buatPegawaiDiSatker($this->satker), Role::ADMIN_SATKER);

        foreach ([$superAdmin, $adminSatker] as $admin) {
            $unggah = $this->unggah($admin, $this->nip, Pendidikan::LAMPIRAN_PENCANTUMAN_GELAR, $entri, $this->berkasPdf());
            $unggah->assertStatus(201);
            $id = (int) $this->json($unggah)['data']['id_attachment'];
            $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$id}")->assertStatus(200);
        }

        // Pegawai CPNS lain (di luar lingkupnya): tetap 403 lingkup — status CPNS dirinya tidak membuka pegawai lain.
        $pegawaiLain = $this->akun($this->buatPegawaiDiSatker($this->satker), Role::PEGAWAI);
        $this->jadikanCpns((string) $pegawaiLain['nip'], true);
        $this->unggah($pegawaiLain, $this->nip, Pendidikan::LAMPIRAN_PENCANTUMAN_GELAR, $entri, $this->berkasPdf())->assertStatus(403);
    }

    public function testAdminSatkerHanyaDiSatkernya(): void
    {
        $adminSendiri = $this->akun($this->buatPegawaiDiSatker($this->satker), Role::ADMIN_SATKER);
        $adminLain    = $this->akun($this->buatPegawaiDiSatker($this->buatSatker()), Role::ADMIN_SATKER);

        $this->unggah($adminLain, $this->nip, 39, (string) $this->idEntri, $this->berkasPdf())->assertStatus(403);
        $this->assertSame([], $this->berkasDiAkarUji());

        // Admin berizin tetap boleh mengelola lampiran baris Disetujui (kunci hanya untuk UL_PEGAWAI).
        $row = $this->json($this->unggah($adminSendiri, $this->nip, 39, (string) $this->idEntri, $this->berkasPdf()))['data'];
        $id  = (int) $row['id_attachment'];

        $this->asUser($adminLain)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=39&id_entri={$this->idEntri}")->assertStatus(403);
        $this->asUser($adminLain)->get("api/v1/pegawai/{$this->nip}/lampiran/{$id}/unduh")->assertStatus(403);
        $this->asUser($adminLain)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$id}")->assertStatus(403);
        $this->assertSame(1, $this->db->table('document_attachment')->where('id_attachment', $id)->countAllResults());

        $this->asUser($adminSendiri)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$id}")->assertStatus(200);
    }

    public function testUnduhHapusDiLuarLingkup403UntukIdAdaMaupunTidak(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $id    = (int) $this->json($this->unggah($admin, $this->nip, 39, (string) $this->idEntri, $this->berkasPdf()))['data']['id_attachment'];

        $adminLain   = $this->akun($this->buatPegawaiDiSatker($this->buatSatker()), Role::ADMIN_SATKER);
        $pegawaiLain = $this->akun($this->buatPegawaiDiSatker($this->satker), Role::PEGAWAI);

        foreach (['admin satker lain' => $adminLain, 'pegawai lain' => $pegawaiLain] as $label => $akun) {
            foreach ([$id, 999999999] as $idUji) {
                $this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/lampiran/{$idUji}/unduh")->assertStatus(403);
                $this->asUser($akun)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$idUji}")->assertStatus(403);
            }

            $this->assertSame(1, $this->db->table('document_attachment')->where('id_attachment', $id)->countAllResults(), $label);
        }

        // Pemanggil yang lingkupnya mencakup {nip}: id tidak ada → 404.
        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/lampiran/999999999/unduh")->assertStatus(404);
        $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/999999999")->assertStatus(404);
    }

    public function testLampiranWajibTerakhirTidakBolehDihapus(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $entri = (string) $this->idEntri;

        $pertama = (int) $this->json($this->unggah($admin, $this->nip, 14, $entri, $this->berkasPdf()))['data']['id_attachment'];

        $tolak = $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$pertama}");
        $tolak->assertStatus(422);
        $this->assertArrayHasKey('berkas', $this->json($tolak)['errors']);
        $this->assertSame(1, $this->db->table('document_attachment')->where('id_attachment', $pertama)->countAllResults());
        $this->assertCount(1, $this->berkasDiAkarUji());

        // Ganti: unggah baru dulu, lalu hapus yang lama; yang tersisa kembali terlindungi.
        $kedua = (int) $this->json($this->unggah($admin, $this->nip, 14, $entri, $this->berkasPdf()))['data']['id_attachment'];
        $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$pertama}")->assertStatus(200);
        $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$kedua}")->assertStatus(422);

        // Kode tidak wajib (39): lampiran terakhir boleh dihapus.
        $gelar = (int) $this->json($this->unggah($admin, $this->nip, 39, $entri, $this->berkasPdf()))['data']['id_attachment'];
        $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$gelar}")->assertStatus(200);
    }

    public function testBarisDisetujuiTerkunciUntukPegawai(): void
    {
        $admin   = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $pegawai = $this->akun($this->nip, Role::PEGAWAI);
        $entri   = (string) $this->idEntri; // status 1 Disetujui

        $ada = (int) $this->json($this->unggah($admin, $this->nip, 39, $entri, $this->berkasPdf()))['data']['id_attachment'];
        $this->unggah($admin, $this->nip, 39, $entri, $this->berkasPdf())->assertStatus(201);

        $this->unggah($pegawai, $this->nip, 39, $entri, $this->berkasPdf())->assertStatus(403);
        $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$ada}")->assertStatus(403);
        $this->assertSame(2, $this->db->table('document_attachment')->where('NIP', $this->nip)->countAllResults());
        $this->assertCount(2, $this->berkasDiAkarUji());

        // Baca tetap boleh.
        $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=39&id_entri={$entri}")->assertStatus(200);
        $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/lampiran/{$ada}/unduh")->assertStatus(200);
    }

    public function testBarisRiwayatDihapus404(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $entri = (string) $this->idEntri;
        $id    = (int) $this->json($this->unggah($admin, $this->nip, 39, $entri, $this->berkasPdf()))['data']['id_attachment'];

        $this->db->table('riwayat_pendidikan')->where('id_riwayat_pendidikan', $this->idEntri)->update(['status' => 10]);

        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=39&id_entri={$entri}")->assertStatus(404);
        $this->unggah($admin, $this->nip, 39, $entri, $this->berkasPdf())->assertStatus(404);
        $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$id}")->assertStatus(404);
        // Unduh juga 404: lampiran baris riwayat yang sudah dihapus tidak bisa diambil lagi.
        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/lampiran/{$id}/unduh")->assertStatus(404);
        $this->assertSame(1, $this->db->table('document_attachment')->where('id_attachment', $id)->countAllResults());
    }

    public function testRoleTanpaIzinLampiranDiDefinisiManaPun403UntukIdAdaMaupunTidak(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $id    = (int) $this->json($this->unggah($admin, $this->nip, 39, (string) $this->idEntri, $this->berkasPdf()))['data']['id_attachment'];

        // Role 4 melihat semua pegawai (lingkup), tetapi tidak punya izin lihat/hapus/ubah di Definisi berlampiran mana
        // pun: 403 sebelum baris lampiran dibaca, sama untuk id yang ada maupun tidak.
        $adminView = $this->akun($this->buatPegawai(), Role::ADMIN_VIEW_ESELON1);

        foreach ([$id, 999999999] as $idUji) {
            $this->asUser($adminView)->get("api/v1/pegawai/{$this->nip}/lampiran/{$idUji}/unduh")->assertStatus(403);
            $this->asUser($adminView)->delete("api/v1/pegawai/{$this->nip}/lampiran/{$idUji}")->assertStatus(403);
        }

        $this->assertSame(1, $this->db->table('document_attachment')->where('id_attachment', $id)->countAllResults());
    }

    public function testHapusDihitungUlangDiDalamTransaksi(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $entri = (string) $this->idEntri;
        $auth  = new AuthContext();
        $auth->setClaims(['sub' => (string) $admin['id_pengguna'], 'role' => Role::SUPER_ADMIN, 'nip' => $admin['nip']]);
        $otorisasi = new OtorisasiLampiran(service('riwayatRegistry'), service('pegawaiScope'));

        $pertama = (int) $this->json($this->unggah($admin, $this->nip, 14, $entri, $this->berkasPdf()))['data']['id_attachment'];
        $kedua   = (int) $this->json($this->unggah($admin, $this->nip, 14, $entri, $this->berkasPdf()))['data']['id_attachment'];

        // Cek cepat lolos (dua lampiran kode wajib) ...
        $otorisasi->bolehHapus($auth, $this->nip, $pertama);
        // ... lalu hapus paralel menyalip sebelum transaksi: tinggal satu lampiran.
        $this->db->table('document_attachment')->where('id_attachment', $kedua)->delete();

        $this->assertGagalDalamTransaksi(fn () => $otorisasi->kunciHapus($auth, $this->nip, $pertama), ValidationException::class);

        // Lampiran target sendiri hilang di antara cek cepat dan transaksi → 404.
        $ketiga = (int) $this->json($this->unggah($admin, $this->nip, 14, $entri, $this->berkasPdf()))['data']['id_attachment'];
        $otorisasi->bolehHapus($auth, $this->nip, $ketiga);
        $this->db->table('document_attachment')->where('id_attachment', $ketiga)->delete();

        $this->assertGagalDalamTransaksi(fn () => $otorisasi->kunciHapus($auth, $this->nip, $ketiga), NotFoundException::class);

        // Baris riwayat dihapus (status 10) di antara cek cepat unggah dan transaksi → 404.
        $otorisasi->bolehUnggah($auth, $this->nip, 39, $entri);
        $this->db->table('riwayat_pendidikan')->where('id_riwayat_pendidikan', $this->idEntri)->update(['status' => 10]);

        $this->assertGagalDalamTransaksi(fn () => $otorisasi->kunciUnggah($auth, $this->nip, 39, $entri), NotFoundException::class);
        $this->assertSame(1, $this->db->table('document_attachment')->where('id_attachment', $pertama)->countAllResults());
    }

    public function testPegawaiLainDanRoleTanpaIzinDitolak(): void
    {
        $pegawaiLain = $this->akun($this->buatPegawaiDiSatker($this->satker), Role::PEGAWAI);
        $this->unggah($pegawaiLain, $this->nip, 14, (string) $this->idEntri, $this->berkasPdf())->assertStatus(403);

        // Role 4 melihat semua pegawai (lingkup), tetapi Definisi pendidikan tidak memberinya izin apa pun.
        $adminView = $this->akun($this->buatPegawai(), Role::ADMIN_VIEW_ESELON1);
        $this->asUser($adminView)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=14&id_entri={$this->idEntri}")->assertStatus(403);
    }

    public function testKodeEntriDanLampiranTidakDikenal404(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);

        // Kode jenis_rwy yang tidak dimiliki Definisi terdaftar (9 = jabatan, Definisi WS-2 M4 belum ada).
        $this->unggah($admin, $this->nip, 9, (string) $this->idEntri, $this->berkasPdf())->assertStatus(404);

        // id_entri milik pegawai lain / tidak ada.
        $nipLain = $this->buatPegawai();
        $this->unggah($admin, $this->nip, 14, (string) $this->buatRiwayatPendidikanUji($nipLain, 1), $this->berkasPdf())->assertStatus(404);
        $this->unggah($admin, $this->nip, 14, '999999999', $this->berkasPdf())->assertStatus(404);

        // Lampiran milik NIP lain lewat path NIP ini → 404 (tanpa IDOR).
        $row = $this->json($this->unggah($admin, $this->nip, 14, (string) $this->idEntri, $this->berkasPdf()))['data'];
        $this->asUser($admin)->get("api/v1/pegawai/{$nipLain}/lampiran/{$row['id_attachment']}/unduh")->assertStatus(404);
        $this->asUser($admin)->delete("api/v1/pegawai/{$nipLain}/lampiran/{$row['id_attachment']}")->assertStatus(404);

        $this->assertCount(1, $this->berkasDiAkarUji());
    }

    public function testValidasi422(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);

        $tanpaKode = $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/lampiran?id_entri={$this->idEntri}");
        $tanpaKode->assertStatus(422);
        $this->assertArrayHasKey('id_riwayat', $this->json($tanpaKode)['errors']);

        $tanpaBerkas = $this->sebagaiForm($admin)->post("api/v1/pegawai/{$this->nip}/lampiran", ['id_riwayat' => '14', 'id_entri' => (string) $this->idEntri]);
        $tanpaBerkas->assertStatus(422);
        $this->assertArrayHasKey('berkas', $this->json($tanpaBerkas)['errors']);

        $png = $this->unggah($admin, $this->nip, 14, (string) $this->idEntri, $this->berkasPng('ijazah.pdf'));
        $png->assertStatus(422);
        $this->assertArrayHasKey('berkas', $this->json($png)['errors']);

        $this->assertSame([], $this->berkasDiAkarUji());
        $this->assertSame(0, $this->db->table('document_attachment')->where('NIP', $this->nip)->countAllResults());
    }

    public function testTanpaToken401(): void
    {
        $this->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=14&id_entri={$this->idEntri}")->assertStatus(401);
    }

    /**
     * Jalankan $kerja di dalam transaksi tulis (seperti LampiranController) dan pastikan gagal dengan $kelas.
     *
     * @param callable(): mixed              $kerja
     * @param class-string<\Throwable>       $kelas
     */
    private function assertGagalDalamTransaksi(callable $kerja, string $kelas): void
    {
        $this->db->transBegin();

        try {
            $kerja();
            $this->fail('Diharapkan ' . $kelas);
        } catch (\Throwable $e) {
            $this->assertInstanceOf($kelas, $e);
        } finally {
            $this->db->transRollback();
            $this->db->resetTransStatus();
        }
    }

    /**
     * Registry produksi (Definisi Pendidikan & KP nyata; kode 14/39/40 milik Pendidikan) + PegawaiScope nyata.
     */
    private function pakaiDefinisiProduksi(): void
    {
        Services::injectMock('riwayatRegistry', RiwayatRegistry::dariFolder(
            static fn (): PegawaiScopeInterface => service('pegawaiScope'),
            APPPATH . 'Libraries/Kepegawaian/Riwayat/Definisi',
            'App\Libraries\Kepegawaian\Riwayat\Definisi',
        ));
        $this->assertInstanceOf(Pendidikan::class, service('riwayatRegistry')->definisiUntukLampiran(Pendidikan::LAMPIRAN_IJAZAH));
    }

    /**
     * Snapshot `pegawai_kp` $nip berpangkat CPNS (`pangkat.cpns = 1`) atau PNS (`cpns = 2`), sama dengan
     * RiwayatUjiTrait::jadikanPegawaiCpns() (trait itu tidak dipakai di sini: nama berkasUji() bentrok dengan
     * LampiranUjiTrait).
     */
    private function jadikanCpns(string $nip, bool $cpns): void
    {
        $golRuang = $cpns ? 'C-II/a' : 'P-II/a';
        $pangkat  = $this->db->table('pangkat')->where('gol_ruang', $golRuang)->get()->getRowArray();

        if ($pangkat === null) {
            $this->db->table('pangkat')->insert([
                'pangkat' => 'Pengatur Muda', 'gol' => 'II', 'ruang' => 'a', 'gol_ruang' => $golRuang,
                'cpns'    => $cpns ? 1 : 2, 'order' => 5, 'status' => 1,
            ]);
            $pangkat = $this->db->table('pangkat')->where('gol_ruang', $golRuang)->get()->getRowArray();
        }

        $this->db->table('pegawai_kp')->where('nip', $nip)->delete();
        $this->db->table('pegawai_kp')->insert([
            'nip'       => $nip, 'id_pangkat' => $pangkat['id_pangkat'], 'tmtsk' => '2020-03-01', 'tgl_sk' => '2020-02-01',
            'jenis_kp'  => $cpns ? 'Pengangkatan CPNS' : 'Pengangkatan PNS', 'gol' => 'II', 'ruang' => 'a',
            'gol_ruang' => $golRuang, 'pangkat' => 'Pengatur Muda',
        ]);
    }

    /**
     * @return array<string, mixed> baris pengguna
     */
    private function akun(string $nip, int $role): array
    {
        $id = $this->buatAkunUntuk($nip, $role);

        /** @var array<string, mixed> $row */
        $row = $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray();

        return $row;
    }

    /**
     * Header Authorization + Content-Type multipart (withHeaders() menimpa header sebelumnya, jadi digabung di sini).
     *
     * @param array<string, mixed> $akun
     *
     * @return $this
     */
    private function sebagaiForm(array $akun): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->issueTokensForUser($akun)['access_token'],
            'Content-Type'  => 'multipart/form-data; boundary=x',
        ]);
    }

    /**
     * @param array<string, mixed> $akun
     */
    private function unggah(array $akun, string $nip, int $kode, string $idEntri, UploadedFile $berkas): TestResponse
    {
        // FileCollection membaca $_FILES lewat service superglobals (shared): set lewat service itu.
        service('superglobals')->setFilesArray(['berkas' => [
            'name'     => $berkas->getClientName(),
            'type'     => $berkas->getClientMimeType(),
            'tmp_name' => $berkas->getTempName(),
            'error'    => $berkas->getError(),
            'size'     => (int) filesize($berkas->getTempName()),
        ]]);

        try {
            return $this->sebagaiForm($akun)->post("api/v1/pegawai/{$nip}/lampiran", ['id_riwayat' => (string) $kode, 'id_entri' => $idEntri]);
        } finally {
            service('superglobals')->setFilesArray([]);
            Services::resetSingle('request');
        }
    }
}
