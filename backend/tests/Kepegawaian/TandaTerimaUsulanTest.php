<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kariskarsu;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Karpeg;
use App\Libraries\Kepegawaian\Usulan\TandaTerimaUsulan;
use Closure;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * PDF tanda terima usulan Karpeg/Karis (F3 WS-1 MAKE-007; WS-2 MAKE-013, ADR-033): hanya usulan Disetujui, hak lihat =
 * engine riwayat (pemohon, role 1/4/5), endpoint `usulan/{jenis}/{id}/tanda-terima`.
 *
 * @internal
 */
final class TandaTerimaUsulanTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pasangEngineNyata([new Karpeg(), new Kariskarsu()]);
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    public function testPdfUntukUsulanDisetujuiSaja(): void
    {
        [$nip, $pemohon, $akun] = $this->aktor(Role::PEGAWAI);
        $setuju                 = $this->sisipRiwayat(new Karpeg(), $nip, $this->berkasKarpeg() + ['status' => 1]);
        $tunggu                 = $this->sisipRiwayat(new Karpeg(), $nip, $this->berkasKarpeg() + ['status' => 0]);

        $pdf = (new TandaTerimaUsulan())->pdf($pemohon, 'karpeg', $setuju);
        $this->assertStringStartsWith('%PDF-', $pdf['isi']);
        $this->assertSame("tt_karpeg_{$nip}_{$setuju}.pdf", $pdf['nama']);
        $this->assertStringStartsWith('%PDF-', (new TandaTerimaUsulan())->pdf($this->admin(), 'karpeg', $setuju)['isi']);

        $this->assertStatusApi(422, fn () => (new TandaTerimaUsulan())->pdf($pemohon, 'karpeg', $tunggu));
        $this->assertStatusApi(404, fn () => (new TandaTerimaUsulan())->pdf($pemohon, 'pendidikan', $setuju));
        $this->assertStatusApi(404, fn () => (new TandaTerimaUsulan())->pdf($pemohon, 'karpeg', 999999));

        // Pegawai lain tidak boleh melihat usulan orang lain (engine).
        [, $lain] = $this->aktor(Role::PEGAWAI);
        $this->assertStatusApi(403, fn () => (new TandaTerimaUsulan())->pdf($lain, 'karpeg', $setuju));

        $res = $this->asUser($akun)->get("api/v1/usulan/karpeg/{$setuju}/tanda-terima");
        $res->assertStatus(200);
        $this->assertSame('application/pdf', explode(';', $res->response()->getHeaderLine('Content-Type'))[0]);
    }

    /**
     * @return array<string, string>
     */
    private function berkasKarpeg(): array
    {
        return ['jenis_permohonan' => Karpeg::PERTAMA, 'file_1' => 'kolom/a.pdf', 'file_2' => 'kolom/b.pdf', 'file_3' => 'kolom/c.pdf', 'file_4' => 'kolom/d.pdf', 'file_5' => 'kolom/e.pdf'];
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
