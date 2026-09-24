<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\Role;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;

/**
 * ApiController::payload() — body JSON rusak harus 400 + envelope ADR-001, bukan 500 (HTTPException CI4).
 * Fallback body kosong / form-urlencoded tetap jalan. Endpoint contoh: POST master/provinsi (Super Admin).
 *
 * @internal
 */
final class InvalidJsonBodyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = MasterDataSeeder::class;

    private const URI = 'api/v1/master/provinsi';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    /**
     * withHeaders() mengganti seluruh header, jadi token dan Content-Type dikirim bersama.
     *
     * @return $this
     */
    private function asSuperAdminWith(string $contentType): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->issueTokensFor(AuthSeeder::nipForRole(Role::SUPER_ADMIN))['access_token'],
            'Content-Type'  => $contentType,
        ]);
    }

    public function testMalformedJsonBodyReturns400WithErrorEnvelope(): void
    {
        $bodies = [
            'newline mentah di string' => "{\"id_provinsi\":\"33\",\"provinsi\":\"Jawa\nTengah\"}",
            'JSON terpotong'           => '{"id_provinsi":"33","provinsi":"Jawa Te',
        ];

        foreach ($bodies as $case => $body) {
            $result = $this->asSuperAdminWith('application/json')->withBody($body)->post(self::URI);

            $result->assertStatus(400);
            $this->assertSame(
                ['status' => 'error', 'message' => 'Body JSON tidak valid.'],
                $this->json($result),
                $case,
            );
        }

        $this->dontSeeInDatabase('provinsi', ['id_provinsi' => '33']);
    }

    public function testFormUrlencodedBodyStillFallsBackToPost(): void
    {
        $data = ['id_provinsi' => '33', 'provinsi' => 'Jawa Tengah'];

        $result = $this->asSuperAdminWith('application/x-www-form-urlencoded')
            ->withBody(http_build_query($data))
            ->post(self::URI, $data);

        $result->assertStatus(201);
        $this->assertSame('Jawa Tengah', $this->json($result)['data']['provinsi']);
    }

    public function testEmptyBodyIsValidatedNotRejectedAsBadJson(): void
    {
        $result = $this->asSuperAdminWith('application/json')->post(self::URI);

        $result->assertStatus(422);
        $this->assertSame('error', $this->json($result)['status']);
    }
}
