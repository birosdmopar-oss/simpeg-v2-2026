<?php

declare(strict_types=1);

namespace Tests\Feature\Ukur;

use App\Constants\Role;
use CodeIgniter\Config\Factories;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Auth as AuthConfig;
use Config\Dashboard;
use Config\Presensi;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\HaloSimpegFixtureTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;
use Tests\Support\Presensi\PresensiUjiTrait;
use Tests\Support\Ukur\PenghitungKueri;

/**
 * CR-157: alat ukur jumlah kueri DB per endpoint (bukan pagar). Hitungan lewat event `DBQuery` hanya selama satu request;
 * kueri metadata (`SHOW`, `information_schema`, `LIMIT 0`, …) dipisah dari kueri data. Tabel hasil ditulis ke STDERR;
 * tidak ada berkas yang ditulis. Assertion hanya status HTTP sesuai harapan dan total kueri > 0, tanpa ambang.
 *
 * Di-skip kecuali env `RUN_UKUR=1` (pola `RUN_VOLUME_TEST`). Data sintetis minimal: atasan dengan 3 bawahan, cuti dan LKH
 * menunggu atasan, petugas helpdesk dengan satu tiket.
 *
 * Mode **dingin** (kolom utama) meniru request PHP-FPM pertama: sebelum tiap request terukur, cache yang tidak bertahan
 * antar-request FPM dikosongkan — `dataCache` koneksi DB (`tableExists`/`getFieldNames`), semua service shared
 * (`Services::reset(true)`, termasuk memo registry lonceng) dan model (`Factories::reset('models')`); throttler uji dipasang
 * ulang, override config test tetap. Dengan begitu urutan request tidak lagi memengaruhi hitungan. Kolom **hangat** = request
 * GET yang sama diulang segera tanpa reset (perilaku proses yang sama); POST tidak diulang (efek samping), ditulis `-`.
 *
 * Deviasi config test (kode aplikasi tidak disentuh): `Time::setTestNow` 2026-11-10 10:00 WIB; jendela presensi masuk/pulang
 * dilebarkan 00:00–23:59 (service presensi memakai jam server); `Dashboard::ttlRingkasanDetik = 0` sehingga beranda diukur
 * tanpa cache ringkasan (jalur terburuk, bukan jalur ber-cache); `captchaDriver = mock`.
 *
 * @internal
 */
#[Group('ukur')]
final class JumlahKueriTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use HaloSimpegFixtureTrait;
    use PresensiUjiTrait;
    use RiwayatUjiTrait;

    private const API = 'api/v1/';

    /** Tanggal uji (WIB) untuk LKH dan beranda; presensi memakai jam server, jendelanya dilebarkan lewat config. */
    private const HARI_INI = '2026-11-10';

    protected $seed = MasterDataSeeder::class;

    /**
     * @var list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int|string, 6: int}>
     */
    private array $hasil = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_UKUR') !== '1') {
            $this->markTestSkipped('Alat ukur CR-157 hanya dijalankan dengan env RUN_UKUR=1.');
        }

        $this->clearAuthState();
        $this->pasangThrottlerHs();
        Time::setTestNow(self::HARI_INI . ' 10:00:00', 'Asia/Jakarta');

        config(AuthConfig::class)->captchaDriver    = 'mock';
        config(Dashboard::class)->ttlRingkasanDetik = 0;
        $presensi                                   = config(Presensi::class);
        $presensi->jamMasukMulai                    = '00:00:00';
        $presensi->jamMasukSelesai                  = '23:59:59';
        $presensi->jamPulangMulai                   = '00:00:00';
        $presensi->jamPulangSelesai                 = '23:59:59';
    }

    protected function tearDown(): void
    {
        if ($this->hasil !== []) {
            $this->cetak(); // tabel tetap tercetak walau satu endpoint gagal
        }

        $this->clearAuthState();
        Time::setTestNow();
        Services::reset(true);
        Factories::reset('config');
        parent::tearDown();
    }

    public function testUkurJumlahKueriPerEndpoint(): void
    {
        $s = $this->skenario();

        $this->ukur('GET notifikasi/ringkasan', 'role 1', $s['admin'], 'get', 'notifikasi/ringkasan');
        $this->ukur('GET notifikasi/ringkasan', 'role 2 pegawai biasa', $s['bawahan'], 'get', 'notifikasi/ringkasan');
        $this->ukur('GET notifikasi/ringkasan', 'role 2 atasan (3 bawahan)', $s['atasan'], 'get', 'notifikasi/ringkasan');
        $this->ukur('GET notifikasi/ringkasan', 'role 3', $s['adminSatker'], 'get', 'notifikasi/ringkasan');
        $this->ukur('GET notifikasi/ringkasan', 'petugas helpdesk', $s['petugas'], 'get', 'notifikasi/ringkasan');

        $this->ukur('GET halo-simpeg/tiket/ringkasan', 'petugas', $s['petugas'], 'get', 'halo-simpeg/tiket/ringkasan');
        $this->ukur('GET halo-simpeg/tiket/ringkasan', 'non-petugas', $s['bawahan'], 'get', 'halo-simpeg/tiket/ringkasan');

        $this->ukur('GET dashboard/ringkasan', 'role 1', $s['admin'], 'get', 'dashboard/ringkasan');
        $this->ukur('GET dashboard/ringkasan', 'role 2', $s['atasan'], 'get', 'dashboard/ringkasan');

        $this->ukur('GET pegawai/{nip}', 'role 1', $s['admin'], 'get', 'pegawai/' . $s['nipBawahan']);
        $this->ukur('GET pegawai/{nip}', 'role 2 (diri)', $s['bawahan'], 'get', 'pegawai/' . $s['nipBawahan']);
        $this->ukur('GET pegawai/{nip}', 'role 3', $s['adminSatker'], 'get', 'pegawai/' . $s['nipBawahan']);

        $this->ukur('GET struktur-organisasi', 'role 1', $s['admin'], 'get', 'struktur-organisasi');

        $this->ukur('POST presensi/masuk', 'role 2', $s['presensi'], 'post', 'presensi/masuk', ['latitude' => '-6.2005', 'longitude' => '106.8166667', 'akurasi' => 12.5], 201);
        $this->ukur('POST presensi/pulang', 'role 2', $s['presensi'], 'post', 'presensi/pulang', ['latitude' => '-6.2005', 'longitude' => '106.8166667', 'akurasi' => 12.5], [200, 201]);

        $this->ukur('GET lkh/opsi', 'role 2 (bawahan)', $s['bawahan'], 'get', 'lkh/opsi');
        $this->ukur('POST lkh', 'role 2 (bawahan)', $s['bawahan'], 'post', 'lkh', ['tgl_laporan' => self::HARI_INI, 'kegiatan' => [
            ['kegiatan' => 'Menyusun laporan', 'output' => 'Draf laporan', 'jumlah_diselesaikan' => '1 dokumen', 'jam_mulai' => '08:00', 'jam_selesai' => '10:00'],
        ]], 201);

        $this->ukur('GET cuti/opsi', 'role 2', $s['bawahan'], 'get', 'cuti/opsi');
        $this->ukur('GET cuti?mode=admin&per_page=100', 'role 1', $s['admin'], 'get', 'cuti?mode=admin&per_page=100');
        $this->ukur('GET cuti?mode=admin&per_page=100', 'role 3', $s['adminSatker'], 'get', 'cuti?mode=admin&per_page=100');

        $this->ukur('POST auth/login', 'role 2', null, 'post', 'auth/login', ['username' => AuthSeeder::nipForRole(Role::PEGAWAI), 'password' => AuthSeeder::PASSWORD, 'captcha_token' => 'ok']);

    }

    /**
     * Satu request terukur. `$akun` null = tanpa autentikasi (login).
     *
     * @param array<string, mixed>|null $akun
     * @param array<string, mixed>      $body
     * @param int|list<int>             $harap
     */
    private function ukur(string $endpoint, string $role, ?array $akun, string $metode, string $path, array $body = [], array|int $harap = 200): void
    {
        [$res, $dingin] = $this->satuRequest($akun, $metode, $path, $body, true);
        $hangat         = $metode === 'get' ? $this->satuRequest($akun, $metode, $path, $body, false)[1]->total() : '-';

        $status        = $res->response()->getStatusCode();
        $this->hasil[] = [$endpoint, $role, $dingin->total(), $dingin->metadata(), $dingin->data(), $hangat, $status];
        $hitung        = $dingin;

        $this->assertContains($status, (array) $harap, "{$endpoint} ({$role}): " . substr((string) $res->response()->getBody(), 0, 300));
        $this->assertGreaterThan(0, $hitung->total(), "{$endpoint} ({$role}): kueri terhitung");
    }

    /**
     * Satu request terukur; `$dingin` = kosongkan cache per-proses lebih dulu (meniru request FPM baru).
     *
     * @param array<string, mixed>|null $akun
     * @param array<string, mixed>      $body
     *
     * @return array{0: TestResponse, 1: PenghitungKueri}
     */
    private function satuRequest(?array $akun, string $metode, string $path, array $body, bool $dingin): array
    {
        if ($dingin) {
            $this->assertSame($this->db, db_connect(), 'aplikasi memakai koneksi yang sama dengan test');
            Services::reset(true);
            Factories::reset('models');
            $this->db->resetDataCache();
            $this->pasangThrottlerHs();
        }

        Services::resetSingle('response'); // Response dipakai bersama antar-request di feature test (CR-151)
        $klien = $akun === null ? $this : $this->asUser($akun);

        if ($metode === 'post') {
            $klien = $klien->withBodyFormat('json');
        }

        $hitung = new PenghitungKueri();
        $hitung->mulai();

        try {
            /** @var TestResponse $res */
            $res = $klien->{$metode}(self::API . $path, $body);
        } finally {
            $hitung->selesai();
            $this->clearAuthState();
        }

        return [$res, $hitung];
    }

    /**
     * @return array{admin: array<string, mixed>, adminSatker: array<string, mixed>, atasan: array<string, mixed>, bawahan: array<string, mixed>, petugas: array<string, mixed>, presensi: array<string, mixed>, nipBawahan: string}
     */
    private function skenario(): array
    {
        $unit   = $this->buatUnit();
        $satker = $this->buatSatker($unit);
        $jabAt  = $this->buatJabatan($satker)['id_jabatan'];

        $nipAtasan = $this->buatPegawai(['nama' => 'Atasan Ukur', 'id_jenis_status' => 1], ['id_satker' => $satker, 'id_unit' => $unit, 'id_jabatan' => $jabAt]);
        $atasan    = $this->baris($this->buatAkunUntuk($nipAtasan, Role::PEGAWAI));

        $nipBawahan = [];

        for ($i = 1; $i <= 3; $i++) {
            $nipBawahan[] = $this->buatPegawai(['nama' => "Bawahan Ukur {$i}", 'id_jenis_status' => 1], ['id_satker' => $satker, 'id_unit' => $unit, 'id_atasan_es_4' => $jabAt]);
        }

        $bawahan = $this->baris($this->buatAkunUntuk($nipBawahan[0], Role::PEGAWAI));

        // Menunggu atasan: LKH (status 0) dan cuti (status_al 0) dari bawahan.
        foreach ($nipBawahan as $nip) {
            $this->db->table('riwayat_lckh')->insert([
                'nip'         => $nip, 'tgl_laporan' => '2026-11-09', 'nama' => 'Bawahan Ukur', 'nip_atasan' => $nipAtasan,
                'nama_atasan' => 'Atasan Ukur', 'kegiatan' => 'Uji', 'output' => 'Uji', 'status' => 0,
            ]);
            $this->db->table('riwayat_cuti')->insert([
                'nip'                 => $nip, 'nama' => 'Bawahan Ukur', 'id_jenis_cuti' => 1, 'nama_jenis_cuti' => 'Cuti Tahunan', 'tgl_mulai' => '2026-11-17',
                'tgl_akhir'           => '2026-11-18', 'lama_cuti' => 2, 'status' => 0, 'status_al' => 0, 'status_pyb' => 0,
                'nip_atasan_langsung' => $nipAtasan, 'created_at' => '2026-11-10 02:00:00',
            ]);
        }

        [, , $adminSatker] = $this->aktor(Role::ADMIN_SATKER, [], $satker);
        $admin             = $this->baris((int) $this->db->table('pengguna')->where('username', AuthSeeder::nipForRole(Role::SUPER_ADMIN))->get()->getRow('id_pengguna'));

        // Petugas helpdesk dengan satu tiket belum dibaca dari bawahan.
        $kategori = $this->buatKategoriHs('Kepegawaian Ukur');
        $petugas  = $this->baris($this->buatAkunUntuk($this->buatPegawai(['nama' => 'Petugas Ukur']), Role::PEGAWAI));
        $this->buatPetugasHs((int) $petugas['id_pengguna'], [$kategori]);
        $tiket = $this->buatTiketHs($kategori, (int) $bawahan['id_pengguna']);
        $this->buatPesanHs($tiket, (int) $bawahan['id_pengguna']);

        // Pegawai presensi di lokasi geofence.
        $pp     = $this->pegawaiPresensi();
        $lokasi = $this->lokasi('-6.2005', '106.8166667', 200, nama: 'Kantor Ukur');
        $this->aturan([$lokasi], ['0'], [1]);
        $presensi = $this->baris($this->buatAkunUntuk($pp['nip'], Role::PEGAWAI));

        return [
            'admin'       => $admin,
            'adminSatker' => $adminSatker,
            'atasan'      => $atasan,
            'bawahan'     => $bawahan,
            'petugas'     => $petugas,
            'presensi'    => $presensi,
            'nipBawahan'  => $nipBawahan[0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(int $idPengguna): array
    {
        return (array) $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->get()->getRowArray();
    }

    private function cetak(): void
    {
        $kepala = ['endpoint', 'role', 'total dingin', 'metadata', 'data', 'total hangat', 'HTTP'];
        $lebar  = array_map('strlen', $kepala);

        foreach ($this->hasil as $b) {
            foreach ($b as $i => $v) {
                $lebar[$i] = max($lebar[$i], strlen((string) $v));
            }
        }

        $garis = static function (array $sel) use ($lebar): string {
            $isi = [];

            foreach ($sel as $i => $v) {
                $isi[] = $i >= 2 ? str_pad((string) $v, $lebar[$i], ' ', STR_PAD_LEFT) : str_pad((string) $v, $lebar[$i]);
            }

            return '| ' . implode(' | ', $isi) . " |\n";
        };

        $out = "\nCR-157 jumlah kueri per endpoint (dingin = request FPM pertama; hangat = GET diulang di proses yang sama)\n" . $garis($kepala) . $garis(array_map(static fn (int $l): string => str_repeat('-', $l), $lebar));

        foreach ($this->hasil as $b) {
            $out .= $garis($b);
        }

        fwrite(STDERR, $out);
    }
}
