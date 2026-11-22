<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use App\Filters\JwtAuthFilter;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\NipCascadeService;
use App\Libraries\Kepegawaian\Pegawai\PegawaiService;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Presensi;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * CR-175: access token membawa klaim `nip` dan JwtAuthFilter dulu tidak membaca DB. Sesudah koreksi NIP (B-06) atau hapus
 * pegawai, token lama tetap diterima dengan NIP lama sampai `exp` — termasuk membaca/menulis data pegawai lain bila NIP
 * lama dipakai ulang. Kini akun `sub` wajib masih memegang NIP klaim; bila tidak → 401 generik (dicek sebelum
 * `must_change_password`). Refresh token dengan klaim NIP basi juga ditolak (baris dihapus, "tidak dikenal").
 *
 * @internal
 */
final class SesiNipBasiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PresensiUjiTrait;
    use RiwayatUjiTrait;

    private const NIP_BARU = '198801012010011999';

    private const UNAUTHORIZED = ['status' => 'error', 'message' => 'Unauthorized'];

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAuthState();
        Time::setTestNow('2026-11-10 10:00:00', 'Asia/Jakarta');
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Time::setTestNow();
        Services::reset(true);
        parent::tearDown();
    }

    // ---- Kasus wajib (merah sebelum CR-175) ----------------------------------------------------------------------

    public function testKoreksiNipTokenLamaDitolakDiLkh(): void
    {
        [$lama, $token] = $this->pegawaiDenganToken();
        $this->koreksi($lama);

        $this->assertUnauthorized($this->withBearer($token)->get('api/v1/lkh'));
    }

    public function testKoreksiNipTokenLamaDitolakDiPresensiTanpaBaris(): void
    {
        $presensi                   = config(Presensi::class);
        $presensi->jamMasukMulai    = '00:00:00';
        $presensi->jamMasukSelesai  = '23:59:59';
        $presensi->jamPulangMulai   = '00:00:00';
        $presensi->jamPulangSelesai = '23:59:59';

        $pp = $this->pegawaiPresensi();
        $this->aturan([$this->lokasi('-6.2005', '106.8166667', 200)], ['0'], [1]);
        $id    = $this->buatAkunUntuk($pp['nip'], Role::PEGAWAI);
        $token = $this->tokenUntuk($id);
        $this->koreksi($pp['nip']);

        $res = $this->withBearer($token)->withBodyFormat('json')->post('api/v1/presensi/masuk', ['latitude' => '-6.2005', 'longitude' => '106.8166667', 'akurasi' => 12.5]);

        $this->assertUnauthorized($res);
        $this->assertSame(0, $this->db->table('dh_online')->where('nip', $pp['nip'])->countAllResults(), 'tanpa baris presensi ber-NIP lama');

        // Kontrol positif: sesi baru (NIP baru, ganti password wajib sudah beres) dengan payload sama → 201 + 1 baris. Sebelum
        // CR-175, 0 baris ber-NIP lama ditahan FK `fk_nip_dh_to_pegawai` (1452 → jalur 500), bukan oleh perbaikan ini.
        $this->db->table('pengguna')->where('id_pengguna', $id)->update(['must_change_password' => 0]);
        $this->withBearer($this->tokenUntuk($id))->withBodyFormat('json')->post('api/v1/presensi/masuk', ['latitude' => '-6.2005', 'longitude' => '106.8166667', 'akurasi' => 12.5])->assertStatus(201);
        $this->assertSame(1, $this->db->table('dh_online')->where('nip', self::NIP_BARU)->countAllResults());
    }

    /**
     * NIP lama dipakai ulang untuk pegawai + akun Y: token X (NIP lama di klaim) tidak boleh menulis atas nama Y. Tiap jalur
     * tulis diuji terpisah: token X lebih dulu (401, tanpa baris), lalu kontrol positif payload yang sama dengan token sah Y
     * (sukses, baris tertulis) — membuktikan payload memang mencapai jalur tulis (sebelum CR-175 token X ikut sukses).
     */
    public function testNipLamaDipakaiUlangTokenLamaTidakMenulisLkhAtasNamaPemegangBaru(): void
    {
        $satker = $this->buatSatker();
        $jabAt  = $this->buatJabatan($satker)['id_jabatan'];
        $this->buatPegawai(['nama' => 'Atasan Uji', 'id_jenis_status' => 1], ['id_satker' => $satker, 'id_jabatan' => $jabAt]);
        [$lama, $tokenX, $tokenY] = $this->nipDipakaiUlang(['id_jenis_status' => 1], ['id_satker' => $satker, 'id_atasan_es_4' => $jabAt]);

        $isian = ['tgl_laporan' => '2026-11-10', 'kegiatan' => [
            ['kegiatan' => 'Menyusun laporan', 'output' => 'Draf', 'jumlah_diselesaikan' => '1', 'jam_mulai' => '08:00', 'jam_selesai' => '10:00'],
        ]];

        $this->assertUnauthorized($this->withBearer($tokenX)->withBodyFormat('json')->post('api/v1/lkh', $isian));
        $this->assertSame(0, $this->db->table('riwayat_lckh')->where('nip', $lama)->countAllResults(), 'tanpa LKH atas nama Y');
        $this->assertUnauthorized($this->withBearer($tokenX)->get('api/v1/lkh'));

        $this->withBearer($tokenY)->withBodyFormat('json')->post('api/v1/lkh', $isian)->assertStatus(201);
        $this->assertSame(1, $this->db->table('riwayat_lckh')->where('nip', $lama)->countAllResults(), 'kontrol positif: payload mencapai jalur tulis');
    }

    public function testNipLamaDipakaiUlangTokenLamaTidakMemprosesCutiYangApproverNyaPemegangBaru(): void
    {
        [$lama, $tokenX, $tokenY] = $this->nipDipakaiUlang();

        $pemohon = $this->buatPegawai(['nama' => 'Pemohon Uji']);
        $this->db->table('riwayat_cuti')->insert([
            'nip'                 => $pemohon, 'nama' => 'Pemohon Uji', 'id_jenis_cuti' => 1, 'nama_jenis_cuti' => 'Cuti Tahunan', 'tgl_mulai' => '2026-11-17',
            'tgl_akhir'           => '2026-11-18', 'lama_cuti' => 2, 'status' => 0, 'status_al' => 0, 'status_pyb' => 0,
            'nip_atasan_langsung' => $lama, 'created_at' => '2026-11-10 02:00:00',
        ]);
        $idCuti = (int) $this->db->insertID();
        $sidik  = $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $idCuti)->get()->getRowArray();

        $this->assertUnauthorized($this->withBearer($tokenX)->withBodyFormat('json')->post("api/v1/cuti/{$idCuti}/process", ['aksi' => 'setujui']));
        $this->assertSame($sidik, $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $idCuti)->get()->getRowArray(), 'cuti tidak diproses atas nama Y');

        $this->withBearer($tokenY)->withBodyFormat('json')->post("api/v1/cuti/{$idCuti}/process", ['aksi' => 'setujui'])->assertStatus(200);
        $this->assertSame('1', (string) $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $idCuti)->get()->getRow('status_al'), 'kontrol positif: diproses oleh Y');
    }

    public function testNipLamaDipakaiUlangTokenLamaTidakMengajukanBiodataAtasNamaPemegangBaru(): void
    {
        $this->siapkanMasterBiodata();
        [$lama, $tokenX, $tokenY] = $this->nipDipakaiUlang($this->biodataLengkap([]));

        $this->assertUnauthorized($this->withBearer($tokenX)->withBodyFormat('json')->put("api/v1/pegawai/{$lama}/biodata", $this->isianBiodata()));
        $this->assertSame(0, $this->db->table('pegawai_hist')->where('nip', $lama)->countAllResults(), 'tanpa pengajuan biodata atas nama Y');

        $res = $this->withBearer($tokenY)->withBodyFormat('json')->put("api/v1/pegawai/{$lama}/biodata", $this->isianBiodata());
        $res->assertStatus(200);
        $this->assertSame('pengajuan', $this->json($res)['data']['jalur']);
        $this->assertSame(1, $this->db->table('pegawai_hist')->where('nip', $lama)->countAllResults(), 'kontrol positif: pengajuan tertulis');
    }

    public function testHapusPegawaiTokenLamaDitolak(): void
    {
        $nip = '199901012020011888';
        $this->db->table('pegawai')->insert(['nip' => $nip, 'nama' => 'Pegawai Polos', 'tgl_lahir' => '1999-01-01', 'jenis_kelamin' => 1, 'status' => 1]);
        $token = $this->tokenUntuk($this->buatAkunUntuk($nip, Role::PEGAWAI));

        (new PegawaiService())->hapus($this->superAdmin(), $nip);

        $this->assertUnauthorized($this->withBearer($token)->get('api/v1/lkh'));
    }

    // ---- Non-regresi ---------------------------------------------------------------------------------------------

    public function testTokenValidDanTokenTanpaNipTetapDilayani(): void
    {
        [, $token] = $this->pegawaiDenganToken();
        $this->withBearer($token)->get('api/v1/lkh')->assertStatus(200);

        $this->withBearer($this->tokenUntuk($this->akunTanpaNip()))->get('api/v1/auth/me')->assertStatus(200);
    }

    public function testSesudahLoginUlangDenganNipBaruDilayani(): void
    {
        [$lama, , $id] = $this->pegawaiDenganToken();
        $this->koreksi($lama);

        // Sesi baru dari baris akun terkini (NIP baru); sesudah ganti password wajib (MAKE-020) penanda dilepas.
        $this->db->table('pengguna')->where('id_pengguna', $id)->update(['must_change_password' => 0]);
        $this->withBearer($this->tokenUntuk($id))->get('api/v1/lkh')->assertStatus(200);
    }

    public function testPasswordChangeRequiredTetap403DanSesiBasiDidahulukan401(): void
    {
        [, , $id] = $this->pegawaiDenganToken();
        $this->db->table('pengguna')->where('id_pengguna', $id)->update(['must_change_password' => 1]);
        $token = $this->tokenUntuk($id);

        $res = $this->withBearer($token)->get('api/v1/lkh');
        $res->assertStatus(403);
        $this->assertSame(JwtAuthFilter::CODE_PASSWORD_CHANGE_REQUIRED, $this->json($res)['code']);

        // NIP klaim tidak lagi dipegang akun → 401, bukan 403 ganti password.
        $this->db->table('pengguna')->where('id_pengguna', $id)->update(['nip' => null]);
        $this->assertUnauthorized($this->withBearer($token)->get('api/v1/lkh'));
    }

    /**
     * Refresh token dengan klaim NIP basi (akun tidak lagi memegang NIP; mis. NIP dilepas tanpa pencabutan sesi) → 401,
     * baris token dihapus dan cookie refresh dihapus. Logout dengan access token basi → 401 dari filter, sama dengan
     * access token kedaluwarsa; jalan keluar klien tetap refresh 401 (cookie terhapus) → login.
     */
    public function testRefreshDanLogoutDenganNipBasi401DanCookieRefreshDihapus(): void
    {
        [, , $id] = $this->pegawaiDenganToken();
        $pair     = $this->issueTokensForUser($this->akun($id));
        $this->db->table('pengguna')->where('id_pengguna', $id)->update(['nip' => null]);

        $this->clearAuthState();
        $this->setRefreshCookie($pair['refresh_token']);
        $this->assertUnauthorized($this->withBearer($pair['access_token'])->post('api/v1/auth/logout'));

        $this->clearAuthState();
        $this->setRefreshCookie($pair['refresh_token']);
        $res = $this->post('api/v1/auth/refresh');

        $res->assertStatus(401);
        $cookie = $res->response()->getCookie('refresh_token');
        $this->assertNotNull($cookie, 'respons 401 refresh menghapus cookie refresh_token');
        $this->assertSame('', $cookie->getValue());
        $this->assertTrue($cookie->isExpired());
        $this->assertSame(0, $this->db->table('token')->where('token_hash', hash('sha256', $pair['refresh_token']))->countAllResults(), 'baris refresh dihapus');

        // Kiriman ulang tetap "tidak dikenal" (bukan reuse yang mencabut sesi lain).
        $this->clearAuthState();
        $this->setRefreshCookie($pair['refresh_token']);
        $this->post('api/v1/auth/refresh')->assertStatus(401);
    }

    public function testRefreshNipMasihSesuaiTetapBerhasil(): void
    {
        [, , $id] = $this->pegawaiDenganToken();
        $pair     = $this->issueTokensForUser($this->akun($id));

        $this->clearAuthState();
        $this->setRefreshCookie($pair['refresh_token']);
        $this->post('api/v1/auth/refresh')->assertStatus(200);
    }

    public function testTepatSatuKueriTambahanHanyaUntukTokenBerNip(): void
    {
        [, $tokenNip] = $this->pegawaiDenganToken();
        $tokenTanpa   = $this->tokenUntuk($this->akunTanpaNip());

        $this->assertSame([null, 1], $this->kueriFilter($tokenNip));
        $this->assertSame([null, 0], $this->kueriFilter($tokenTanpa));
    }

    // ---- Bantuan -------------------------------------------------------------------------------------------------

    /**
     * @return array{0: int|null, 1: int} [status respons filter (null = lolos), jumlah kueri DB]
     */
    private function kueriFilter(string $token): array
    {
        $this->clearAuthState();

        /** @var IncomingRequest $request */
        $request = Services::incomingrequest(null, false);
        $request->setHeader('Authorization', 'Bearer ' . $token);

        $jumlah = 0;
        $hitung = static function (Query $q) use (&$jumlah): void {
            $jumlah++;
        };

        Events::on('DBQuery', $hitung);

        try {
            $hasil = (new JwtAuthFilter())->before($request);
        } finally {
            Events::removeListener('DBQuery', $hitung);
        }

        return [$hasil?->getStatusCode(), $jumlah];
    }

    /**
     * @return array{0: string, 1: string, 2: int} [nip, access token, id_pengguna]
     */
    private function pegawaiDenganToken(): array
    {
        $nip = $this->buatPegawai();
        $id  = $this->buatAkunUntuk($nip, Role::PEGAWAI);

        return [$nip, $this->tokenUntuk($id), $id];
    }

    /**
     * Pegawai X + token, koreksi NIP X → NIP_BARU, lalu NIP lama dipakai pegawai + akun Y (role 2).
     *
     * @param array<string, mixed> $pegawaiY
     * @param array<string, mixed> $pmjY
     *
     * @return array{0: string, 1: string, 2: string} [NIP lama, token X (basi), token Y]
     */
    private function nipDipakaiUlang(array $pegawaiY = [], array $pmjY = []): array
    {
        [$lama, $tokenX, $idX] = $this->pegawaiDenganToken();
        $this->koreksi($lama);

        $this->buatPegawai(['nip' => $lama, 'nama' => 'Pegawai Y'] + $pegawaiY, $pmjY);
        $idY = $this->buatAkunUntuk($lama, Role::PEGAWAI, ['username' => 'akun.y.' . substr($lama, -4)]);
        $this->assertNotSame($idX, $idY);

        return [$lama, $tokenX, $this->tokenUntuk($idY)];
    }

    private function tokenUntuk(int $idPengguna): string
    {
        return $this->issueTokensForUser($this->akun($idPengguna))['access_token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function akun(int $idPengguna): array
    {
        return (array) $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->get()->getRowArray();
    }

    private function akunTanpaNip(): int
    {
        $this->db->table('pengguna')->insert([
            'nip' => null, 'username' => 'admin.tanpa.nip', 'name' => 'Admin Tanpa NIP', 'user_level' => Role::SUPER_ADMIN, 'status' => 1,
        ]);

        return (int) $this->db->insertID();
    }

    private function koreksi(string $lama): void
    {
        (new NipCascadeService())->koreksi($this->superAdmin(), $lama, self::NIP_BARU);
        $this->assertSame(0, $this->db->table('pengguna')->where('nip', $lama)->countAllResults(), 'akun ikut pindah ke NIP baru');
    }

    private function superAdmin(): AuthContext
    {
        return $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), Role::SUPER_ADMIN));
    }

    private function assertUnauthorized(TestResponse $res): void
    {
        $res->assertStatus(401);
        $this->assertSame(self::UNAUTHORIZED, $this->json($res));
    }

    private function siapkanMasterBiodata(): void
    {
        foreach ([
            'provinsi'       => ['id_provinsi' => '31', 'provinsi' => 'Provinsi Uji'],
            'kabupaten_kota' => ['id_kabupaten_kota' => '3171', 'id_provinsi' => '31', 'kabupaten_kota' => 'Kota Uji'],
            'agama'          => ['id_agama' => 1, 'agama' => 'Agama Uji'],
            'jenis_pegawai'  => ['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS'],
            'jenis_status'   => ['id_jenis_status' => 1, 'jenis_status' => 'Aktif Bekerja', 'status_pegawai' => 1],
        ] as $tabel => $baris) {
            $pk = (string) array_key_first($baris);

            if ($this->db->table($tabel)->where($pk, $baris[$pk])->countAllResults() === 0) {
                $this->db->table($tabel)->insert($baris + ['status' => 1]);
            }
        }
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function biodataLengkap(array $override): array
    {
        return $override + [
            'id_provinsi_lahir' => '31', 'id_kabupaten_kota_lahir' => '3171', 'id_agama' => 1, 'id_jenis_pegawai' => 1,
            'id_jenis_status'   => 1, 'npwp' => '1', 'nik' => '2', 'bpjs_kes' => '3', 'no_taspen' => '4', 'no_hp' => '5',
            'jenis_kerabat'     => 'Orang Tua', 'no_telp_kerabat' => '6', 'status_pernikahan' => 1, 'foto' => 'kolom/2026/10/ada.png',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function isianBiodata(): array
    {
        return [
            'id_provinsi_lahir' => '31', 'id_kabupaten_kota_lahir' => '3171', 'id_agama' => '1',
            'jenis_kelamin'     => '1', 'npwp' => '11', 'nik' => '22', 'bpjs_kes' => '33', 'no_taspen' => '44', 'no_hp' => '0811',
            'jenis_kerabat'     => 'Orang Tua', 'no_telp_kerabat' => '66', 'status_pernikahan' => '1', 'email' => 'pegawai.y@contoh.test',
        ];
    }
}
