<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use App\Filters\JwtAuthFilter;
use App\Libraries\ApiExceptionHandler;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\NipCascadeService;
use App\Libraries\Presensi\Kehadiran\PresensiOnlineService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestLogger;
use CodeIgniter\Test\TestResponse;
use Config\Presensi;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AuthTestTrait;
use Tests\Support\Controllers\SesiBasiProbeController;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;
use Tests\Support\Presensi\PresensiUjiTrait;

/**
 * CR-185: sisa risiko CR-175. Cek `masihMemegangNip` di JwtAuthFilter berupa SELECT biasa, jadi request yang datang selama
 * transaksi koreksi NIP / hapus pegawai belum commit tetap lolos filter; INSERT ber-NIP lama lalu kena FK 1452 sesudah commit
 * → dulu 500 "Terjadi kesalahan pada server.". Kini jaring terpusat di ApiController::_remap: 1452 + sesi TERBUKTI basi →
 * 401 dengan body persis seperti filter. Sesi masih sah / token tanpa NIP → tetap 500 (bug FK sungguhan tetap terlihat).
 *
 * @internal
 */
final class JaringFk1452SesiBasiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PresensiUjiTrait;
    use RiwayatUjiTrait;

    private const NIP_BARU = '198801012010011998';

    private const BODY_UNAUTHORIZED = ['status' => 'error', 'message' => 'Unauthorized'];

    private const MASUK = ['latitude' => '-6.2005', 'longitude' => '106.8166667', 'akurasi' => 12.5];

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        Time::setTestNow('2026-11-10 10:00:00', 'Asia/Jakarta');

        $this->withRoutes([
            ['POST', 'api/v1/_probe/fk/(:segment)', '\\' . SesiBasiProbeController::class . '::fk/$1', ['filter' => 'jwt']],
            ['POST', 'api/v1/_probe/presensi/masuk', '\\' . SesiBasiProbeController::class . '::presensiMasuk', ['filter' => 'jwt']],
        ]);
    }

    protected function tearDown(): void
    {
        SesiBasiProbeController::$kaitPresensi = null;
        $this->clearAuthState();
        Time::setTestNow();
        // withRoutes() mengubah RouteCollection shared: bangun ulang agar route probe tidak bocor ke test lain.
        Services::resetSingle('routes');
        Services::resetSingle('router');
        Services::reset(true);
        parent::tearDown();
    }

    public function testBodyFilterDanJaringBersumberDariKonstantaYangSama(): void
    {
        $this->assertSame(self::BODY_UNAUTHORIZED, JwtAuthFilter::BODY_UNAUTHORIZED);
    }

    // ---- Jaring terpusat (route uji) ------------------------------------------------------------------------------

    public function testFk1452SesudahAkunBerhentiMemegangNip401BodyPersis(): void
    {
        [, $token] = $this->pegawaiDenganToken();

        $this->assertUnauthorized($this->withBearer($token)->post('api/v1/_probe/fk/ganti'));
        $this->assertNull(service('authContext')->idPengguna(), 'authContext dibersihkan');
        $this->assertTrue(TestLogger::didLog('warning', 'FK 1452 pada sesi NIP basi diterjemahkan ke 401: [1452] ' . DatabaseException::class . '.'), 'galat asli dicatat (kode + kelas, tanpa NIP)');
    }

    public function testFk1452SesiMasihSahTetap500(): void
    {
        [, $token] = $this->pegawaiDenganToken();

        $this->assertTetap500(fn () => $this->withBearer($token)->post('api/v1/_probe/fk/tetap'));
    }

    public function testFk1452TokenTanpaNipTetap500(): void
    {
        $this->db->table('pengguna')->insert(['nip' => null, 'username' => 'admin.tanpa.nip.fk', 'name' => 'Admin Tanpa NIP', 'user_level' => Role::SUPER_ADMIN, 'status' => 1]);
        $token = $this->tokenUntuk((int) $this->db->insertID());

        $this->assertTetap500(fn () => $this->withBearer($token)->post('api/v1/_probe/fk/ganti'));
    }

    public function testErrorData1406Tetap422(): void
    {
        [, $token] = $this->pegawaiDenganToken();

        $res = $this->withBearer($token)->post('api/v1/_probe/fk/data');
        $res->assertStatus(422);
        $this->assertSame('error', $this->json($res)['status']);
    }

    // ---- Presensi: balapan deterministik lewat seam kaitSebelumTulis -----------------------------------------------

    public function testPresensiKoreksiNipDiSelaFilterDanTulis401TanpaBarisTanpaAudit(): void
    {
        $this->siapkanPresensi();
        $pp                                    = $this->pegawaiPresensi();
        $lama                                  = $pp['nip'];
        $token                                 = $this->tokenUntuk($this->buatAkunUntuk($lama, Role::PEGAWAI));
        $super                                 = $this->superAdmin();
        SesiBasiProbeController::$kaitPresensi = static function () use ($super, $lama): void {
            (new NipCascadeService())->koreksi($super, $lama, self::NIP_BARU);
        };

        $res = $this->withBearer($token)->withBodyFormat('json')->post('api/v1/_probe/presensi/masuk', self::MASUK);

        $this->assertUnauthorized($res);
        $this->assertSame(0, $this->db->table('dh_online')->where('nip', $lama)->countAllResults(), 'tanpa baris ber-NIP lama');
        $this->assertSame(0, $this->db->table('dh_online')->where('nip', self::NIP_BARU)->countAllResults());
        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', 'dh_online')->like('entity_id', $lama . ':', 'after')->countAllResults(), 'tanpa audit dh_online lama');
    }

    /**
     * Lapis service: 1452 dari `dh_online` kini DatabaseException berkode (dulu RuntimeException tanpa kode), sesudah rollback.
     */
    public function testPresensiServiceMelempar1452BerkodeTanpaBaris(): void
    {
        $this->siapkanPresensi();
        $pp    = $this->pegawaiPresensi();
        $lama  = $pp['nip'];
        $auth  = $this->authUntukAkun($this->buatAkunUntuk($lama, Role::PEGAWAI));
        $super = $this->superAdmin();
        $svc   = new PresensiOnlineService(null, null, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-10 10:00:00', new DateTimeZone('Asia/Jakarta')), static function () use ($super, $lama): void {
            (new NipCascadeService())->koreksi($super, $lama, self::NIP_BARU);
        });

        try {
            $svc->masuk($auth, self::MASUK);
            $this->fail('Seharusnya DatabaseException 1452');
        } catch (DatabaseException $e) {
            $this->assertSame(1452, $e->getCode());
        }

        $this->assertSame(0, $this->db->table('dh_online')->where('nip', $lama)->countAllResults());
    }

    // ---- Non-regresi ------------------------------------------------------------------------------------------------

    public function testRequestSuksesTanpaKueriTambahan(): void
    {
        $this->siapkanPresensi();
        $pp     = $this->pegawaiPresensi();
        $token  = $this->tokenUntuk($this->buatAkunUntuk($pp['nip'], Role::PEGAWAI));
        $kueri  = [];
        $dengar = static function (Query $q) use (&$kueri): void {
            $kueri[] = $q->getQuery();
        };
        Events::on('DBQuery', $dengar);

        try {
            $this->withBearer($token)->withBodyFormat('json')->post('api/v1/_probe/presensi/masuk', self::MASUK)->assertStatus(201);
        } finally {
            Events::removeListener('DBQuery', $dengar);
        }

        // Cek NIP filter (CR-175) tepat satu; jaring 1452 tidak menambah kueri di jalur sukses.
        $this->assertCount(1, array_filter($kueri, static fn (string $q): bool => str_contains($q, 'pengguna') && str_contains($q, 'COUNT')), implode("\n", $kueri));
    }

    private function siapkanPresensi(): void
    {
        $presensi                   = config(Presensi::class);
        $presensi->jamMasukMulai    = '00:00:00';
        $presensi->jamMasukSelesai  = '23:59:59';
        $presensi->jamPulangMulai   = '00:00:00';
        $presensi->jamPulangSelesai = '23:59:59';
        $this->aturan([$this->lokasi('-6.2005', '106.8166667', 200)], ['0'], [1]);
    }

    /**
     * @return array{0: string, 1: string} [nip, access token]
     */
    private function pegawaiDenganToken(): array
    {
        $nip = $this->buatPegawai();

        return [$nip, $this->tokenUntuk($this->buatAkunUntuk($nip, Role::PEGAWAI))];
    }

    private function tokenUntuk(int $idPengguna): string
    {
        return $this->issueTokensForUser((array) $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->get()->getRowArray())['access_token'];
    }

    private function superAdmin(): AuthContext
    {
        return $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), Role::SUPER_ADMIN));
    }

    private function assertUnauthorized(TestResponse $res): void
    {
        $res->assertStatus(401);
        $this->assertSame(self::BODY_UNAUTHORIZED, $this->json($res));
    }

    /**
     * 1452 tidak ditelan _remap(): tetap dilempar ke handler global (500).
     */
    private function assertTetap500(callable $request): void
    {
        try {
            $request();
            $this->fail('1452 harus tetap dilempar ke handler global');
        } catch (DatabaseException $e) {
            $this->assertSame([1452, 500], [$e->getCode(), ApiExceptionHandler::toEnvelope($e)[0]]);
        }
    }
}
