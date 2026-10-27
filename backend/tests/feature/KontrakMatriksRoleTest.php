<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\Role;
use App\Libraries\ApiExceptionHandler;
use App\Libraries\Kepegawaian\Konket\KonketService;
use App\Libraries\Kepegawaian\Lkh\LkhService;
use App\Libraries\Kepegawaian\Pegawai\PegawaiService;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\JenisRiwayat;
use App\Libraries\Kepegawaian\Struktur\PenugasanService;
use App\Libraries\MasterData\FaqService;
use App\Libraries\MasterData\HariLiburService;
use App\Libraries\MasterData\WebConfigService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\MasterData as MasterDataConfig;
use Config\Services;

/**
 * MAKE-038 — kontrak 403 Matriks Role x Endpoint v2 (endpoint x role) untuk seluruh route `api/v1` yang ada di main.
 *
 * Tiga lapis:
 *  1. Kontrak route: setiap route di modul yang sudah dibangun (Fase 0–3) WAJIB tercatat di tabel kontrak di bawah
 *     dengan akses yang sama persis dengan filter route-nya (publik / jwt / jwt:ganti_password / daftar role). Route
 *     baru di modul itu tanpa kontrak → gagal; tambahkan barisnya beserta rujukan Matriks.
 *  2. Sapu HTTP: tanpa token → 401, dan setiap role DI LUAR daftar role route → 403 envelope ADR-001. Ditolak filter
 *     sebelum controller, jadi tidak butuh DB.
 *  3. Role di level service untuk route ber-filter `jwt` saja (izin dicek service): izin `lihat` setiap jenis riwayat
 *     dan konstanta role layanan Kepegawaian disamakan dengan Matriks, lalu role di luar izin `lihat` riwayat → 403.
 *
 * Route modul yang belum ada di main (Fase 4/5A dst.) di luar cakupan: tidak diuji dan tidak ditebak rolenya. Modul
 * baru masuk cakupan dengan menambah prefiksnya di PREFIKS_CAKUPAN beserta baris kontraknya.
 *
 * @internal
 */
final class KontrakMatriksRoleTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const PUBLIK = 'publik';

    private const JWT = 'jwt';

    private const JWT_GANTI_PASSWORD = 'jwt:ganti_password';

    /**
     * Prefiks modul yang sudah ada di main (Fase 0–3). Route di luar prefiks ini tidak diperiksa.
     */
    private const PREFIKS_CAKUPAN = '#^api/v1/(health|auth|master|hari-libur|web-config|faq|_rbac|pegawai|biodata|struktur-organisasi|penugasan|konket|lkh|usulan|ak-jf|statistik|dokumen|cuti)(/|$)#';

    /**
     * Master yang dropdown `{key}/options`-nya hanya role 1 (publicOptions false, CR-003): konten FAQ.
     */
    private const OPTIONS_KHUSUS_ADMIN = ['faq-topic', 'faq-sub-topic', 'faq-article'];

    /**
     * Kontrak route non-generik: "VERB path-route" => akses. Path memakai pola RouteCollection (regex placeholder).
     * Rujukan: Matriks_Role_x_Endpoint_SIMPEG_v2 Bagian 2 (baris legacy di komentar).
     *
     * @return array<string, list<int>|string>
     */
    private static function kontrak(): array
    {
        return [
            'GET api/v1/health' => self::PUBLIK,

            // Modul F — Fase 7 F-03 statistik (MAKE-036): Matriks Bagian 2 `hr/chart/unit_kerja`, `hr/chart/usia|jabatan|
            // gender|eselon|education|gol` = 1, 3, 4, 5, 8 (role 3 terbatas satker/unit di service, PegawaiScope).
            'GET api/v1/statistik/lingkup'      => [1, 3, 4, 5, 8],
            'GET api/v1/statistik/([^/]+)/xlsx' => [1, 3, 4, 5, 8],
            'GET api/v1/statistik/([^/]+)'      => [1, 3, 4, 5, 8],
            // F-09 generator dokumen (MAKE-037): Matriks Bagian 2 `hr/document/duk|nominatif|pensiun|baperjakat` = 1, 3, 4, 5, 8.
            'POST api/v1/dokumen/baperjakat/pdf'  => [1, 3, 4, 5, 8],
            'POST api/v1/dokumen/baperjakat/xlsx' => [1, 3, 4, 5, 8],
            'GET api/v1/dokumen/([^/]+)/pdf'      => [1, 3, 4, 5, 8],
            'GET api/v1/dokumen/([^/]+)/xlsx'     => [1, 3, 4, 5, 8],
            'GET api/v1/dokumen/([^/]+)'          => [1, 3, 4, 5, 8],

            // Modul C — Fase 4 Cuti (MAKE-022, PRD §5.1): route role 1, 2, 3, 6, 7; hak per baris (pemilik, AL/PyB, ketua tim,
            // lingkup) di CutiService. `hitung-lama` = semua login (jwt saja).
            'GET api/v1/cuti/opsi'              => [1, 2, 3, 6, 7],
            'POST api/v1/cuti/hitung-lama'      => self::JWT,
            'GET api/v1/cuti'                   => [1, 2, 3, 6, 7],
            'POST api/v1/cuti'                  => [1, 2, 3, 6, 7],
            'GET api/v1/cuti/([0-9]+)'          => [1, 2, 3, 6, 7],
            'PUT api/v1/cuti/([0-9]+)'          => [1, 2, 3, 6, 7],
            'DELETE api/v1/cuti/([0-9]+)'       => [1, 2, 3, 6, 7],
            'GET api/v1/cuti/([0-9]+)/lampiran' => [1, 2, 3, 6, 7],
            'GET api/v1/cuti/([0-9]+)/surat'    => [1, 2, 3, 6, 7],

            // Modul A — user/login (Publik), logout/change_password/me (UL_ALL; tetap terbuka saat wajib ganti password).
            'POST api/v1/auth/login'           => self::PUBLIK,
            'POST api/v1/auth/refresh'         => self::PUBLIK,
            'POST api/v1/auth/forgot-password' => self::PUBLIK,
            'POST api/v1/auth/reset-password'  => self::PUBLIK,
            'POST api/v1/auth/logout'          => self::JWT_GANTI_PASSWORD,
            'GET api/v1/auth/me'               => self::JWT_GANTI_PASSWORD,
            'POST api/v1/auth/change-password' => self::JWT_GANTI_PASSWORD,
            // Modul A — user/index, add, edit, delete: 1, 3.
            'GET api/v1/auth/users'                   => [1, 3],
            'POST api/v1/auth/users'                  => [1, 3],
            'GET api/v1/auth/users/([0-9]+)'          => [1, 3],
            'PUT api/v1/auth/users/([0-9]+)'          => [1, 3],
            'PATCH api/v1/auth/users/([0-9]+)/status' => [1, 3],
            'DELETE api/v1/auth/users/([0-9]+)'       => [1, 3],

            // Modul G — hari libur (legacy Presensi.php:1015): lihat 1, 4, 5, 8; kelola 1.
            'GET api/v1/hari-libur'                  => [1, 4, 5, 8],
            'GET api/v1/hari-libur/([^/]+)'          => [1, 4, 5, 8],
            'POST api/v1/hari-libur'                 => [1],
            'PUT api/v1/hari-libur/([^/]+)'          => [1],
            'PATCH api/v1/hari-libur/([^/]+)/status' => [1],
            'DELETE api/v1/hari-libur/([^/]+)'       => [1],
            // Modul G — hr/master/web_config/*: 1.
            'GET api/v1/web-config'         => [1],
            'GET api/v1/web-config/(.*)'    => [1],
            'PUT api/v1/web-config/(.*)'    => [1],
            'DELETE api/v1/web-config/(.*)' => [1],
            // Modul G — hr/faq/*: UL_ALL (lihat); rating UL_PEGAWAI (2, 6, 7).
            'GET api/v1/faq'               => self::JWT,
            'GET api/v1/faq/([^/]+)'       => self::JWT,
            'POST api/v1/faq/([^/]+)/rate' => [2, 6, 7],

            // Endpoint dummy RBAC F0-08 (pola Matriks Bagian 3).
            'GET api/v1/_rbac/any'           => self::JWT,
            'GET api/v1/_rbac/admin-crud'    => [1, 3],
            'GET api/v1/_rbac/admin-view'    => [1, 2, 3, 4, 5],
            'POST api/v1/_rbac/self-service' => [1, 2, 3, 6, 7],
            'GET api/v1/_rbac/leadership'    => [1, 3, 4, 5, 8],
            'GET api/v1/_rbac/super-admin'   => [1],

            // Modul B — hr/employee/*.
            'GET api/v1/pegawai'                             => [1, 3, 4, 5, 8], // index
            'GET api/v1/pegawai/export'                      => [1, 3, 4, 5, 8], // index (ekspor daftar)
            'GET api/v1/pegawai/pencarian/katalog'           => [1, 3, 4, 5],    // advsearch
            'POST api/v1/pegawai/pencarian'                  => [1, 3, 4, 5],
            'POST api/v1/pegawai/pencarian/export'           => [1, 3, 4, 5],
            'POST api/v1/pegawai'                            => [1],             // add
            'DELETE api/v1/pegawai/([^/]+)'                  => [1],             // delete
            'POST api/v1/pegawai/([^/]+)/koreksi-nip'        => [1],             // edit_nip
            'GET api/v1/biodata/pengajuan'                   => [1, 3],          // process (antrean)
            'POST api/v1/biodata/pengajuan/([0-9]+)/process' => [1, 3],       // process
            // detail (UL_ALL) / edit (1,2,3,6,7) / print_drh: lingkup & role dicek PegawaiService.
            'GET api/v1/pegawai/([^/]+)'                 => self::JWT,
            'PUT api/v1/pegawai/([^/]+)/biodata'         => self::JWT,
            'GET api/v1/pegawai/([^/]+)/foto'            => self::JWT,
            'GET api/v1/pegawai/([^/]+)/drh'             => self::JWT,
            'GET api/v1/biodata/pengajuan/([0-9]+)'      => self::JWT,
            'GET api/v1/biodata/pengajuan/([0-9]+)/foto' => self::JWT,
            // Lampiran pegawai: lingkup pegawai di service.
            'GET api/v1/pegawai/([^/]+)/lampiran'                => self::JWT,
            'POST api/v1/pegawai/([^/]+)/lampiran'               => self::JWT,
            'GET api/v1/pegawai/([^/]+)/lampiran/([0-9]+)/unduh' => self::JWT,
            'DELETE api/v1/pegawai/([^/]+)/lampiran/([0-9]+)'    => self::JWT,
            // hr/so/full (Session Logged In) + Plt/Plh (PenugasanService::ROLE_LIHAT/ROLE_TULIS).
            'GET api/v1/struktur-organisasi'                   => self::JWT,
            'GET api/v1/struktur-organisasi/pdf'               => self::JWT,
            'GET api/v1/penugasan/(plt|plh)'                   => self::JWT,
            'POST api/v1/penugasan/(plt|plh)'                  => self::JWT,
            'GET api/v1/penugasan/(plt|plh)/([0-9]+)'          => self::JWT,
            'PUT api/v1/penugasan/(plt|plh)/([0-9]+)'          => self::JWT,
            'DELETE api/v1/penugasan/(plt|plh)/([0-9]+)'       => self::JWT,
            'GET api/v1/penugasan/(plt|plh)/([0-9]+)/lampiran' => self::JWT,
            // hr/rwy/konket/* (KonketService::ROLE_*).
            'GET api/v1/konket'                   => self::JWT,
            'GET api/v1/konket/opsi'              => self::JWT,
            'POST api/v1/konket'                  => self::JWT,
            'GET api/v1/konket/([0-9]+)'          => self::JWT,
            'PUT api/v1/konket/([0-9]+)'          => self::JWT,
            'DELETE api/v1/konket/([0-9]+)'       => self::JWT,
            'POST api/v1/konket/([0-9]+)/process' => self::JWT,
            'GET api/v1/konket/([0-9]+)/berkas'   => self::JWT,
            // hr/rwy/lkh/* (LkhService::ROLE_LKH).
            'GET api/v1/lkh'                   => self::JWT,
            'GET api/v1/lkh/opsi'              => self::JWT,
            'GET api/v1/lkh/verifikasi'        => self::JWT,
            'GET api/v1/lkh/rekap'             => self::JWT,
            'GET api/v1/lkh/rekap/([^/]+)'     => self::JWT,
            'POST api/v1/lkh'                  => self::JWT,
            'GET api/v1/lkh/([0-9]+)'          => self::JWT,
            'PUT api/v1/lkh/([0-9]+)'          => self::JWT,
            'DELETE api/v1/lkh/([0-9]+)'       => self::JWT,
            'POST api/v1/lkh/([0-9]+)/process' => self::JWT,
            'GET api/v1/lkh/([0-9]+)/pdf'      => self::JWT,
            // hr/rwy/* — tab riwayat, anak keluarga, usulan karpeg/kariskarsu, target KP: izin per jenis riwayat.
            'GET api/v1/pegawai/([^/]+)/riwayat'                                    => self::JWT,
            'GET api/v1/pegawai/([^/]+)/riwayat/keluarga/([0-9]+)/anak'             => self::JWT,
            'POST api/v1/pegawai/([^/]+)/riwayat/keluarga/([0-9]+)/anak'            => self::JWT,
            'PUT api/v1/pegawai/([^/]+)/riwayat/keluarga/([0-9]+)/anak/([0-9]+)'    => self::JWT,
            'DELETE api/v1/pegawai/([^/]+)/riwayat/keluarga/([0-9]+)/anak/([0-9]+)' => self::JWT,
            'GET api/v1/usulan/karpeg'                                              => self::JWT,
            'GET api/v1/usulan/karpeg/saya'                                         => self::JWT,
            'GET api/v1/usulan/karpeg/([0-9]+)/tanda-terima'                        => self::JWT,
            'GET api/v1/usulan/kariskarsu'                                          => self::JWT,
            'GET api/v1/usulan/kariskarsu/saya'                                     => self::JWT,
            'GET api/v1/usulan/kariskarsu/([0-9]+)/tanda-terima'                    => self::JWT,
            'GET api/v1/pegawai/([^/]+)/ak/target-kp'                               => self::JWT,
            // Master AK JF (dm_ak_jf, Modul G "Super Admin only").
            'GET api/v1/ak-jf'                   => [1],
            'GET api/v1/ak-jf/opsi'              => [1],
            'GET api/v1/ak-jf/jabatan'           => [1],
            'POST api/v1/ak-jf'                  => [1],
            'PUT api/v1/ak-jf/([0-9]+)/([0-9]+)' => [1],
        ] + self::kontrakRiwayat() + self::kontrakMaster();
    }

    /**
     * Route CRUD riwayat per jenis: filter `jwt` saja, izin per aksi di RiwayatDefinisi::izin() (lapis 3).
     *
     * @return array<string, string>
     */
    private static function kontrakRiwayat(): array
    {
        $kontrak = [];

        foreach (JenisRiwayat::SLUG as $jenis) {
            $dasar = "api/v1/pegawai/([^/]+)/riwayat/{$jenis}";

            foreach ([
                "GET {$dasar}", "POST {$dasar}", "GET {$dasar}/([0-9]+)", "PUT {$dasar}/([0-9]+)", "DELETE {$dasar}/([0-9]+)",
                "POST {$dasar}/([0-9]+)/process", "GET {$dasar}/([0-9]+)/berkas/([^/]+)", "GET {$dasar}/pratinjau",
            ] as $route) {
                $kontrak[$route] = self::JWT;
            }
        }

        return $kontrak;
    }

    /**
     * Modul G — "Super Admin only, seluruh Master Data tanpa kecuali" (Matriks Bagian 3). Dropdown `{key}/options`
     * (read-only, entri aktif, dipakai form modul lain) UL_ALL kecuali konten FAQ (CR-003).
     *
     * @return array<string, list<int>|string>
     */
    private static function kontrakMaster(): array
    {
        $kontrak = ['GET api/v1/master/meta' => [1]];

        foreach (array_keys(config(MasterDataConfig::class)->entities) as $key) {
            $dasar = "api/v1/master/{$key}";

            $kontrak["GET {$dasar}/options"] = in_array($key, self::OPTIONS_KHUSUS_ADMIN, true) ? [1] : self::JWT;

            foreach ([
                "GET {$dasar}", "POST {$dasar}", "GET {$dasar}/([^/]+)", "PUT {$dasar}/([^/]+)",
                "PATCH {$dasar}/([^/]+)/status", "PATCH {$dasar}/([^/]+)/order", "DELETE {$dasar}/([^/]+)",
            ] as $route) {
                $kontrak[$route] = [1];
            }
        }

        return $kontrak;
    }

    /**
     * Izin `lihat` per jenis riwayat menurut Matriks v2 Bagian 2 Modul B (hr/rwy/<jenis>/*). Jenis v2 tanpa baris
     * Matriks sendiri mengikuti induknya: skp-periodik → skp, ak-siasn → ak.
     *
     * @return array<string, list<int>>
     */
    private static function matriksLihatRiwayat(): array
    {
        $adminPegawaiView = [1, 2, 3, 4, 5]; // "Admin CRUD (1,3) + Pegawai View (2,4,5)"

        return [
            'jabatan'      => $adminPegawaiView,
            'kp'           => $adminPegawaiView,
            'kgb'          => $adminPegawaiView,
            'tanda-jasa'   => $adminPegawaiView,
            'pendidikan'   => [1, 2, 3, 6, 7],
            'alamat'       => [1, 2, 3, 6, 7],
            'diklat'       => [1, 2, 3, 7],
            'seminar'      => [1, 2, 3, 7],
            'skp'          => [1, 2, 3, 7],
            'skp-periodik' => [1, 2, 3, 7],
            'keluarga'     => [1, 2, 3, 7],
            'organisasi'   => [1, 2, 3, 7],
            'hukdis'       => [1, 3],
            'ak'           => [1, 2, 3, 4, 7],
            'ak-siasn'     => [1, 2, 3, 4, 7],
            'karpeg'       => [1, 2, 4, 5, 7],
            'kariskarsu'   => [1, 2, 4, 5, 7],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        service('authContext')->clear();
    }

    protected function tearDown(): void
    {
        service('authContext')->clear();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Lapis 1 — kontrak route
    // ------------------------------------------------------------------

    public function testSetiapRouteModulTerbangunPunyaKontrakYangSamaDenganFilternya(): void
    {
        $kontrak = self::kontrak();
        $aktual  = $this->aksesRoute();

        $tanpaKontrak = array_diff_key($aktual, $kontrak);
        $this->assertSame([], array_keys($tanpaKontrak), 'Route tanpa kontrak Matriks — tambahkan barisnya di KontrakMatriksRoleTest::kontrak()');

        $hilang = array_diff_key($kontrak, $aktual);
        $this->assertSame([], array_keys($hilang), 'Kontrak untuk route yang tidak terdaftar (route dihapus/diganti?)');

        $beda = [];

        foreach ($kontrak as $route => $akses) {
            if (self::teks($akses) !== self::teks($aktual[$route])) {
                $beda[$route] = 'kontrak ' . self::teks($akses) . ', filter ' . self::teks($aktual[$route]);
            }
        }

        $this->assertSame([], $beda, 'Akses route berbeda dari kontrak Matriks');
    }

    // ------------------------------------------------------------------
    // Lapis 2 — sapu HTTP 401/403
    // ------------------------------------------------------------------

    public function testTanpaToken401DanRoleDiLuarKontrak403(): void
    {
        $token = [];

        foreach (Role::all() as $role) {
            $token[$role] = service('jwt')->issueAccessToken(['sub' => (string) (900000 + $role), 'role' => $role]);
        }

        $gagal  = [];
        $jumlah = 0;

        foreach (self::kontrak() as $route => $akses) {
            if ($akses === self::PUBLIK) {
                continue;
            }

            [$verb, $pola] = explode(' ', $route, 2);
            $path          = self::isiPlaceholder($pola);

            $status = $this->statusPermintaan($verb, $path, null);
            $jumlah++;

            if ($status !== 401) {
                $gagal[] = "{$route} tanpa token → {$status} (harus 401)";
            }

            if (! is_array($akses)) {
                continue;
            }

            foreach (array_diff(Role::all(), $akses) as $role) {
                $status = $this->statusPermintaan($verb, $path, $token[$role]);
                $jumlah++;

                if ($status !== 403) {
                    $gagal[] = "{$route} role {$role} → {$status} (harus 403)";
                }
            }
        }

        $this->assertSame([], $gagal);
        $this->assertGreaterThan(1000, $jumlah, 'sapu mencakup seluruh route modul terbangun');
    }

    // ------------------------------------------------------------------
    // Lapis 3 — role di service untuk route ber-filter jwt saja
    // ------------------------------------------------------------------

    public function testIzinLihatSetiapJenisRiwayatSesuaiMatriks(): void
    {
        $matriks = self::matriksLihatRiwayat();
        $this->assertEqualsCanonicalizing(JenisRiwayat::SLUG, array_keys($matriks), 'setiap jenis riwayat punya baris Matriks');

        foreach ($matriks as $jenis => $roles) {
            $definisi = service('riwayatRegistry')->definisi($jenis);
            $this->assertNotNull($definisi, $jenis);

            $aktual = array_values(array_filter(Role::all(), static fn (int $r): bool => $definisi->boleh(AksiRiwayat::Lihat, $r)));
            $this->assertSame($roles, $aktual, "izin lihat riwayat {$jenis}");
        }
    }

    public function testRoleDiLuarIzinLihatRiwayatDitolak403(): void
    {
        $gagal = [];

        foreach (self::matriksLihatRiwayat() as $jenis => $roles) {
            foreach (array_diff(Role::all(), $roles) as $role) {
                $token  = service('jwt')->issueAccessToken(['sub' => (string) (900000 + $role), 'role' => $role]);
                $status = $this->statusPermintaan('GET', "api/v1/pegawai/199001012015011001/riwayat/{$jenis}", $token);

                if ($status !== 403) {
                    $gagal[] = "riwayat {$jenis} role {$role} → {$status} (harus 403)";
                }
            }
        }

        $this->assertSame([], $gagal);
    }

    public function testRoleLayananKepegawaianSesuaiMatriks(): void
    {
        // hr/employee/index | advsearch | edit (Matriks Modul B).
        $this->assertSame([1, 3, 4, 5, 8], PegawaiService::ROLE_DAFTAR);
        $this->assertSame([1, 3, 4, 5], PegawaiService::ROLE_PENCARIAN);
        $this->assertSame([1, 2, 3, 6, 7], PegawaiService::ROLE_UBAH_BIODATA);
        // hr/rwy/lkh/* = 1, 2, 3, 6, 7.
        $this->assertSame([1, 2, 3, 6, 7], LkhService::ROLE_LKH);
        // hr/rwy/konket/* = 1, 2, 3, 6, 7 (tulis); lihat + pengamat 4, 5 (legacy list_ad/detail, lihat saja).
        $this->assertSame([1, 2, 3, 6, 7], KonketService::ROLE_TULIS);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], KonketService::ROLE_LIHAT);
        $this->assertSame([4, 5], KonketService::ROLE_PENGAMAT);
        // Plt/Plh tidak ada baris Matriks: ikut legacy (keputusan user 13-10-2026).
        $this->assertSame([1, 3, 4, 5], PenugasanService::ROLE_LIHAT);
        $this->assertSame([1], PenugasanService::ROLE_TULIS);
        // Modul G: FAQ rating UL_PEGAWAI, hari libur lihat 1/4/5/8 kelola 1, web config 1.
        $this->assertSame(Role::UL_PEGAWAI, FaqService::RATER_ROLES);
        $this->assertSame([1, 4, 5, 8], HariLiburService::READ_ROLES);
        $this->assertSame([1], HariLiburService::WRITE_ROLES);
        $this->assertSame([1], WebConfigService::WRITE_ROLES);
    }

    // ------------------------------------------------------------------

    /**
     * Akses aktual setiap route modul terbangun: "VERB path" => publik | jwt | jwt:ganti_password | daftar role.
     *
     * @return array<string, list<int>|string>
     */
    private function aksesRoute(): array
    {
        /** @var RouteCollection $routes */
        $routes = service('routes')->loadRoutes();
        $akses  = [];

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $from) {
                $from = (string) $from;

                if (preg_match(self::PREFIKS_CAKUPAN, $from) !== 1) {
                    continue;
                }

                $filters = $routes->getFiltersForRoute($from, $verb);
                $role    = array_values(array_filter($filters, static fn (string $f): bool => str_starts_with($f, 'role:')));

                $akses["{$verb} {$from}"] = match (true) {
                    $role !== []                                       => array_map('intval', explode(',', substr($role[0], 5))),
                    in_array(self::JWT_GANTI_PASSWORD, $filters, true) => self::JWT_GANTI_PASSWORD,
                    in_array(self::JWT, $filters, true)                => self::JWT,
                    default                                            => self::PUBLIK,
                };

                $this->assertLessThanOrEqual(1, count($role), "{$verb} {$from}: satu filter role saja");

                if ($role !== []) {
                    $this->assertContains(self::JWT, $filters, "{$verb} {$from}: filter role selalu bersama jwt");
                }
            }
        }

        return $akses;
    }

    /**
     * @param list<int>|string $akses
     */
    private static function teks(array|string $akses): string
    {
        return is_array($akses) ? 'role:' . implode(',', $akses) : $akses;
    }

    /**
     * Path contoh dari pola route: placeholder diisi nilai yang cocok (filter menolak sebelum nilai dipakai).
     */
    private static function isiPlaceholder(string $pola): string
    {
        return strtr($pola, [
            '([0-9]+)'  => '1',
            '([^/]+)'   => '199001012015011001',
            '(.*)'      => 'nama_kementerian',
            '(plt|plh)' => 'plt',
        ]);
    }

    private function statusPermintaan(string $verb, string $path, ?string $token): int
    {
        Services::resetSingle('response');
        service('authContext')->clear();

        $this->withHeaders($token === null ? [] : ['Authorization' => 'Bearer ' . $token]);

        try {
            return $this->call($verb, $path)->response()->getStatusCode();
        } catch (PageNotFoundException $e) {
            return ApiExceptionHandler::toEnvelope($e)[0];
        }
    }
}
