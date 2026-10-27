<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use App\Exceptions\ApiException;
use App\Libraries\Presensi\Slip\KodeGalatSlip;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\SlipUjiTrait;

/**
 * PRD 5A §7.3.3 langkah 5 (AC-D11.4): dua permintaan buka bersamaan → tepat satu sukses. Permintaan lain disimulasikan
 * koneksi DB kedua yang membuka slip (commit) tepat sebelum UPDATE bersyarat `viewed_at IS NULL` (seam `kaitBuka`);
 * permintaan ini mengenai 0 baris → rollback (tanpa riwayat token & audit dari permintaan ini) + 422 `slip_sudah_dibuka`,
 * tidak pernah 500. Koneksi kedua menulis data test → `db-isolasi-penuh`.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class BukaSlipKonkurensiTest extends DatabaseTestCase
{
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
        parent::tearDown();
    }

    public function testBukaBersamaanSatuSukses(): void
    {
        $nip  = $this->pegawaiSlip()['nip'];
        $id   = $this->slip($nip, 2026, 9);
        $lain = db_connect('tests', false);
        $kait = static function (int $idSlip) use ($lain): void {
            $lain->table('gaji_pegawai')->where('id', $idSlip)->update(['viewed_at' => '2026-10-27 01:59:00', 'qr_token' => str_repeat('c', 64)]);
        };

        try {
            $this->slipPegawai('2026-10-27 09:00:00', null, $kait)->buka($this->authPegawaiSlip($nip), '2026-09', '203.0.113.5');
            $this->fail('permintaan kedua harus 422');
        } catch (ApiException $e) {
            $this->assertSame([422, KodeGalatSlip::SLIP_SUDAH_DIBUKA], [$e->getStatusCode(), $e->getKode()]);
        } finally {
            $lain->close();
        }

        $row = $this->db->table('gaji_pegawai')->where('id', $id)->get()->getRowArray();
        $this->assertSame(['2026-10-27 01:59:00', str_repeat('c', 64)], [$row['viewed_at'] ?? null, $row['qr_token'] ?? null], 'pemenang = permintaan lain');
        $this->assertSame(0, $this->db->table('gaji_pegawai_qr_history')->where('id_gaji_pegawai', $id)->countAllResults(), 'riwayat permintaan kalah di-rollback');
        $this->assertSame(0, $this->db->table('audit_logs')->where(['entity' => 'gaji_pegawai', 'entity_id' => (string) $id])->countAllResults());
    }
}
