<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\SlipUjiTrait;

/**
 * PRD 5A §7.3.1–7.3.2, §7.3.6 (AC-D11.2, .3, .11): pegawai hanya slip sendiri — NIP dari token, parameter NIP di query/
 * body diabaikan, tidak ada jalur pegawai yang mengembalikan slip NIP lain; akun tanpa NIP → 403; PDF lewat HTTP hanya
 * setelah dibuka dengan header unduhan aman (`attachment`, `nosniff`, `no-store`) dan nama berkas tersanitasi.
 *
 * @internal
 */
final class SlipPegawaiAksesTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use SlipUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSlip();
    }

    protected function tearDown(): void
    {
        $this->bersihkanSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testNipDariTokenDanPdf(): void
    {
        $saya = $this->pegawaiSlip('Citra Saya')['nip'];
        $lain = $this->pegawaiSlip('Dodi Lain')['nip'];
        $this->slip($lain, 2026, 9, ['gjpokok' => '9999999.00']);
        $akun = $this->buatAkunTanpaNip('sl' . bin2hex(random_bytes(3)), Role::PEGAWAI, ['nip' => $saya, 'must_change_password' => 0]);

        $this->asUser($akun)->get("api/v1/slip-gaji/saya/2026-09?nip={$lain}")->assertStatus(404);
        $this->asUser($akun)->withBodyFormat('json')->post('api/v1/slip-gaji/saya/2026-09/buka', ['nip' => $lain])->assertStatus(404);
        $this->seeInDatabase('gaji_pegawai', ['nip' => $lain, 'viewed_at' => null]);

        $this->slip($saya, 2026, 9);
        $this->asUser($akun)->get('api/v1/slip-gaji/saya/2026-09/pdf')->assertStatus(422);

        $res = $this->asUser($akun)->withBodyFormat('json')->post('api/v1/slip-gaji/saya/2026-09/buka', ['nip' => $lain]);
        $res->assertStatus(200);
        $this->assertSame('3000000.00', $this->json($res)['data']['rincian']['gjpokok'], 'slip milik sendiri');
        $this->seeInDatabase('gaji_pegawai', ['nip' => $lain, 'viewed_at' => null]);

        $pdf = $this->asUser($akun)->get('api/v1/slip-gaji/saya/2026-09/pdf');
        $pdf->assertStatus(200);
        $this->assertStringStartsWith('%PDF-', (string) $pdf->response()->getBody());
        $this->assertSame('attachment; filename="Slip_Gaji_CITRA_SAYA_September_2026.pdf"', $pdf->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame('nosniff', $pdf->response()->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $pdf->response()->getHeaderLine('Cache-Control'));

        $daftar = $this->json($this->asUser($akun)->get('api/v1/slip-gaji/saya'))['data'];
        $per    = array_column($daftar['items'], null, 'periode');
        $this->assertTrue($per['2026-09']['dibuka']);
        $this->assertFalse($per['2026-08']['ada_data']);
    }

    public function testAkunTanpaNip403(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->slipPegawai()->daftar($this->authPegawaiSlip(''));
    }
}
