<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\Role;
use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\Exceptions\BadRequestException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Controllers\DataErrorProbeController;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;

/**
 * CR-007 (prasyarat strictOn) — penjaga UTF-8 di ApiController: byte non-UTF-8 di body form (payload()), query string,
 * dan parameter route → 422 "Input tidak valid (encoding)." SEBELUM menyentuh DB.
 *
 * Koneksi `tests` sudah strictOn=true: tanpa penjaga, INSERT byte non-UTF-8 gagal 1366 (500), dan setelah tabel auth
 * utf8mb4_unicode_ci username 'NIP\xC3(' bisa cocok dengan 'NIP' sehingga percobaan gagal tidak tercatat (lockout
 * terlewati). Body JSON non-UTF-8 sudah ditolak json_decode (400) — tidak berubah.
 *
 * @internal
 */
final class InvalidUtf8InputTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = MasterDataSeeder::class;

    private const NIP = '199002152015022002'; // akun seed role 2

    private const FORM = 'application/x-www-form-urlencoded';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        // withRoutes() mengubah RouteCollection shared (dan router shared memegang referensinya): bangun ulang
        // keduanya agar route probe tidak bocor ke test lain.
        Services::resetSingle('routes');
        Services::resetSingle('router');
        parent::tearDown();
    }

    public function testConnectionUnderTestIsStrict(): void
    {
        // Prasyarat seluruh test CR-007: tanpa strict, byte non-UTF-8 dipotong diam-diam alih-alih error.
        $this->assertStringContainsString('STRICT_ALL_TABLES', (string) $this->db->query('SELECT @@SESSION.sql_mode AS m')->getRow()->m);
    }

    public function testNonUtf8LoginUsernameIsRejectedBeforeAnyAttemptIsRecorded(): void
    {
        foreach (["\xC3(", "\xFF", "\xC3"] as $suffix) {
            $result = $this->sendForm('POST', 'api/v1/auth/login', [
                'username'      => self::NIP . $suffix,
                'password'      => 'salah-password-1',
                'captcha_token' => 'ok',
            ]);

            $this->assertEncodingRejected($result, ['username'], bin2hex($suffix));
        }

        // Belum ada yang menyentuh tabel auth: tidak ada percobaan (gagal/sukses) dan tidak ada sesi.
        $this->assertSame(0, $this->db->table('login_attempts')->countAllResults());
        $this->assertSame(0, $this->db->table('token')->countAllResults());

        // Login form yang valid tetap jalan lewat jalur yang sama.
        $this->sendForm('POST', 'api/v1/auth/login', [
            'username'      => self::NIP,
            'password'      => AuthSeeder::PASSWORD,
            'captcha_token' => 'ok',
        ])->assertStatus(200);
    }

    public function testNonUtf8ForgotPasswordUsernameIsRejected(): void
    {
        $result = $this->sendForm('POST', 'api/v1/auth/forgot-password', ['username' => self::NIP . "\xC3("]);

        $this->assertEncodingRejected($result, ['username']);
        $this->assertSame(0, $this->db->table('forgot_attempts')->countAllResults());
    }

    public function testNonUtf8UsernameOnUserCreateIsRejected(): void
    {
        $before = $this->db->table('pengguna')->countAllResults();

        $result = $this->sendForm('POST', 'api/v1/auth/users', [
            'nip'        => '199501012020121001',
            'username'   => "pengguna\xC3(",
            'password'   => AuthSeeder::PASSWORD,
            'user_level' => '2',
        ], Role::SUPER_ADMIN);

        $this->assertEncodingRejected($result, ['username']);
        $this->assertSame($before, $this->db->table('pengguna')->countAllResults());
    }

    public function testNonUtf8UpdateThroughFormMethodSpoofingIsRejected(): void
    {
        // PUT form-urlencoded asli tidak mengisi getPost(); body form baru sampai ke payload() lewat POST + _method=PUT
        // (method spoofing CI4). Jalur itu yang dijaga di sini — untuk update akun dan update master.
        // Router shared menyimpan verb dari request yang membangunnya, jadi dibangun ulang sebelum tiap request spoofing.
        $nip = AuthSeeder::nipForRole(Role::PEGAWAI);
        $id  = (int) $this->db->table('pengguna')->where('nip', $nip)->get()->getRow()->id_pengguna;

        Services::resetSingle('router');
        $result = $this->sendForm('POST', "api/v1/auth/users/{$id}", ['_method' => 'PUT', 'username' => "baru\xC3("], Role::SUPER_ADMIN);
        $this->assertEncodingRejected($result, ['username']);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $id, 'username' => $nip]);

        Services::resetSingle('router');
        $result = $this->sendForm('POST', 'api/v1/master/agama/6', ['_method' => 'PUT', 'agama' => "Khonghucu\xFF"], Role::SUPER_ADMIN);
        $this->assertEncodingRejected($result, ['agama']);
        $this->seeInDatabase('agama', ['id_agama' => 6, 'agama' => 'Konghucu']);

        // Jalur spoofing yang sama dengan nilai valid memang sampai ke update (bukan ditolak karena hal lain).
        Services::resetSingle('router');
        $this->sendForm('POST', 'api/v1/master/agama/6', ['_method' => 'PUT', 'agama' => 'Khonghucu'], Role::SUPER_ADMIN)->assertStatus(200);
        $this->seeInDatabase('agama', ['id_agama' => 6, 'agama' => 'Khonghucu']);
    }

    public function testNonUtf8MasterNameAndKeysAreRejectedPerField(): void
    {
        // Nilai bersarang → notasi titik; key yang bukan UTF-8 ikut ditolak dengan nama field yang di-scrub (JSON aman).
        $result = $this->sendForm('POST', 'api/v1/master/agama', [
            'agama'  => "Kepercayaan\xFF",
            'extra'  => ['catatan' => "x\xC3"],
            "k\xC3"  => '1',
            'status' => '1',
        ], Role::SUPER_ADMIN);

        $this->assertEncodingRejected($result, ['agama', 'extra.catatan', 'k?']);
        $this->assertSame(0, $this->db->table('agama')->like('agama', 'Kepercayaan', 'after')->countAllResults());

        // UTF-8 multibyte yang valid tidak ikut ditolak.
        $this->sendForm('POST', 'api/v1/master/agama', ['agama' => 'Kepercayaan Été'], Role::SUPER_ADMIN)->assertStatus(201);
        $this->seeInDatabase('agama', ['agama' => 'Kepercayaan Été']);
    }

    public function testNonUtf8QueryStringIsRejectedOnEveryApiEndpoint(): void
    {
        // Query string mentah (didekode URI), termasuk endpoint yang membaca getGet() langsung tanpa payload().
        $cases = [
            'api/v1/master/agama?search=%C3'            => 'search',
            'api/v1/auth/users?search=abc%FF'           => 'search',
            'api/v1/master/agama/options?parent=%C3%28' => 'parent',
        ];

        foreach ($cases as $uri => $field) {
            $result = $this->asRole(Role::SUPER_ADMIN)->get($uri);
            $this->assertEncodingRejected($result, [$field], $uri);
        }

        // Query bersarang.
        $result = $this->asRole(Role::SUPER_ADMIN)->get('api/v1/master/agama', ['filter' => ['nama' => "\xC3"]]);
        $this->assertEncodingRejected($result, ['filter.nama']);

        // Query UTF-8 valid tetap diproses.
        $this->asRole(Role::SUPER_ADMIN)->get('api/v1/master/agama', ['search' => 'Hindu'])->assertStatus(200);
    }

    public function testJsonBodyWithNonUtf8BytesIsStillRejectedAsBadJson(): void
    {
        $result = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->issueTokensFor(AuthSeeder::nipForRole(Role::SUPER_ADMIN))['access_token'],
            'Content-Type'  => 'application/json',
        ])->withBody("{\"agama\":\"Kepercayaan\xC3(\"}")->post('api/v1/master/agama');

        $result->assertStatus(400);
        $this->assertSame(['status' => 'error', 'message' => 'Body JSON tidak valid.'], $this->json($result));
    }

    public function testNonUtf8RouteParamIsRejected(): void
    {
        $this->withRoutes([['GET', 'api/v1/_probe/echo/(:segment)', '\\' . DataErrorProbeController::class . '::echoParam/$1']]);

        // Lapis pertama: Router CI4 menolak segmen non-UTF-8 (permittedURIChars dengan modifier /u) sebelum controller.
        try {
            $this->get('api/v1/_probe/echo/%C3');
            $this->fail('Router harus menolak segmen URI non-UTF-8.');
        } catch (BadRequestException $e) {
            $this->assertStringContainsString('disallowed characters', $e->getMessage());
        }

        // Lapis kedua: _remap() sendiri menolak parameter non-UTF-8 (mis. bila permittedURIChars dikosongkan).
        $controller = new DataErrorProbeController();
        $controller->initController(Services::incomingrequest(null, false), Services::response(null, false), service('logger'));

        $response = $controller->_remap('echoParam', "abc\xC3(");
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['status' => 'error', 'message' => ApiController::INVALID_ENCODING_MESSAGE],
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );

        // Parameter UTF-8 valid tetap diteruskan.
        $this->get('api/v1/_probe/echo/abc')->assertStatus(200);
    }

    /**
     * Body form-urlencoded (satu-satunya jalur byte non-UTF-8 ke payload(); JSON seperti itu ditolak json_decode).
     * withHeaders() mengganti seluruh header, jadi token dan Content-Type dikirim bersama.
     *
     * @param array<array-key, mixed> $data
     */
    private function sendForm(string $method, string $uri, array $data, ?int $role = null): TestResponse
    {
        $headers = ['Content-Type' => self::FORM];

        if ($role !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->issueTokensFor(AuthSeeder::nipForRole($role))['access_token'];
        }

        return $this->withHeaders($headers)->withBody(http_build_query($data))->call($method, $uri, $data);
    }

    /**
     * @param list<string> $fields
     */
    private function assertEncodingRejected(TestResponse $result, array $fields, string $case = ''): void
    {
        $result->assertStatus(422);

        $expected = array_fill_keys($fields, [ApiController::INVALID_ENCODING_FIELD_MESSAGE]);
        $this->assertSame(
            ['status' => 'error', 'message' => ApiController::INVALID_ENCODING_MESSAGE, 'errors' => $expected],
            $this->json($result),
            $case,
        );
    }
}
