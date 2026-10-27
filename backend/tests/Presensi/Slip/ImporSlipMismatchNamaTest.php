<?php

declare(strict_types=1);

namespace Tests\Presensi\Slip;

use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\ImporSlipUjiTrait;

/**
 * PRD 5A §7.2.3 butir 5 (AC-D10.2, F5-4): nama di berkas tidak cocok dengan NIP → baris galat `nama_tidak_cocok` dan
 * tepat satu `audit_logs` `event = warning` per kunci per pratinjau (meski kedua sheet tidak cocok), berisi nama berkas,
 * nama DB bergelar, sheet, baris, dan 8 karakter awal hash token; commit tidak menambah audit mismatch; nama yang
 * cocok (substring, tanpa beda huruf/tanda baca) tidak diaudit.
 *
 * @internal
 */
final class ImporSlipMismatchNamaTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use ImporSlipUjiTrait;
    use PegawaiFixtureTrait;

    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanImporSlip();
        $this->nip = $this->buatPegawai(['nama' => 'Rina Wulandari', 'glr_awal' => null, 'glr_akhir' => 'S.E.']);
    }

    protected function tearDown(): void
    {
        $this->bersihkanImporSlip();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testMismatchSatuAuditPerKunci(): void
    {
        $admin = $this->adminSlip();
        $data  = $this->dataOk($this->unggahSlip($admin, $this->berkasSlip(
            [$this->barisGpp($this->nip, 9, 2026, 'Joko Susilo'), $this->barisGpp($this->nip, 10, 2026, 'RINA wulandari')],
            [$this->barisTk($this->nip, 9, 2026, 'Joko S.'), $this->barisTk($this->nip, 10, 2026, 'Rina')],
        )));

        $perPeriode = array_column($data['baris'], null, 'periode');
        $this->assertSame('galat', $perPeriode['2026-09']['status']);
        $this->assertSame(['nama_tidak_cocok', 'nama_tidak_cocok'], array_column($perPeriode['2026-09']['galat'], 'kode'), 'GPP dan TK dicek sendiri (M-6)');
        $this->assertSame('valid', $perPeriode['2026-10']['status'], 'beda huruf besar & substring tetap cocok [K]');

        $audit = $this->auditWarning();
        $this->assertCount(1, $audit, 'satu audit per kunci per pratinjau walau dua sheet tidak cocok');
        $this->assertSame("{$this->nip}:2026-09", $audit[0]['entity_id']);
        $this->assertSame((string) $admin['id_pengguna'], (string) $audit[0]['id_pengguna_actor']);

        $after = json_decode((string) $audit[0]['after_json'], true);
        $this->assertSame($this->nip, $after['nip']);
        $this->assertSame('2026-09', $after['periode']);
        $this->assertSame('GPP', $after['sheet']);
        $this->assertSame(2, $after['baris']);
        $this->assertSame('Joko Susilo', $after['nama_berkas']);
        $this->assertSame('Rina Wulandari, S.E.', $after['nama_db'], 'nama DB bergelar');
        $this->assertSame(substr(hash('sha256', $data['token_pratinjau']), 0, 8), $after['pratinjau']);

        $hasil = $this->dataOk($this->commitSlip($admin, $data['token_pratinjau']));
        $this->assertSame(1, $hasil['disisip']);
        $this->assertSame(1, $hasil['gagal']);
        $this->assertCount(1, $this->auditWarning(), 'commit tidak menulis ulang audit mismatch');
        $this->assertSame(0, $this->db->table('gaji_pegawai')->where(['nip' => $this->nip, 'bulan' => 9])->countAllResults());
    }

    public function testPratinjauUlangMenulisAuditBaru(): void
    {
        $admin  = $this->adminSlip();
        $berkas = fn () => $this->berkasSlip([$this->barisGpp($this->nip, 9, 2026, 'Orang Lain')], [$this->barisTk($this->nip, 9, 2026, 'Rina')]);

        $this->dataOk($this->unggahSlip($admin, $berkas()));
        $this->dataOk($this->unggahSlip($admin, $berkas()));

        $this->assertCount(2, $this->auditWarning(), 'satu audit per kunci PER PRATINJAU');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditWarning(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->table('audit_logs')->where('entity', 'gaji_pegawai_impor')->where('event', 'warning')->orderBy('id_log')->get()->getResultArray();

        return $rows;
    }
}
