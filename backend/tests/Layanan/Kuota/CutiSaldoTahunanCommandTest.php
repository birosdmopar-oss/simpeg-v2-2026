<?php

declare(strict_types=1);

namespace Tests\Layanan\Kuota;

use App\Libraries\Layanan\Cuti\SaldoTahunanService;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-022 M3 — `php spark cuti:saldo-tahunan` (PRD Fase 4 v1.3 §6.3.6): idempoten, `--dry-run` tidak menulis,
 * hanya pegawai aktif jenis 1/6, rumus Q-02 dari baris tahun lalu, satu baris audit ringkasan, tanpa syarat masa kerja,
 * eksekusi tulis tahun < 2027 ditolak.
 *
 * @internal
 */
final class CutiSaldoTahunanCommandTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStreamFilterBuffer();
    }

    public function testDryRunTidakMenulisApaPun(): void
    {
        $this->pegawai('A001', 1);
        $this->pegawai('A002', 6);

        command('cuti:saldo-tahunan 2026 --dry-run');
        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('dry-run', $output);
        $this->assertStringContainsString('Populasi: 2, dibuat: 2', $output);
        $this->assertSame(0, $this->db->table('pegawai_sisa_cuti')->countAllResults());
        $this->assertSame(0, $this->jumlahAuditRingkasan());
    }

    /**
     * CR-080: `--nip=<nip>` dulu tiba sebagai kunci `"nip=<nip>"` dan diabaikan diam-diam (semua pegawai diproses).
     */
    public function testOpsiNipBentukSamaDenganDanSpasi(): void
    {
        $this->pegawai('E001', 1);
        $this->pegawai('E002', 1);

        command('cuti:saldo-tahunan 2026 --dry-run --nip=E002');
        $this->assertStringContainsString('Populasi: 1, dibuat: 1', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        command('cuti:saldo-tahunan 2026 --nip E002 --dry-run');
        $this->assertStringContainsString('Populasi: 1, dibuat: 1', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        command('cuti:saldo-tahunan 2026 --dry-run --nip=');
        $this->assertStringContainsString('Opsi --nip wajib diberi nilai', $this->getStreamFilterBuffer());
        $this->assertStringNotContainsString('Populasi:', $this->getStreamFilterBuffer());
        $this->assertSame(0, $this->db->table('pegawai_sisa_cuti')->countAllResults());
    }

    public function testEksekusiTulisTahun2026Ditolak(): void
    {
        $this->pegawai('A001', 1);

        command('cuti:saldo-tahunan 2026');

        $keluaran = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('Eksekusi tulis saldo cuti hanya untuk tahun 2027', $keluaran);
        $this->assertStringContainsString('--dry-run', $keluaran);
        $this->assertStringContainsString('§6.3.6', $keluaran);
        $this->assertSame(0, $this->db->table('pegawai_sisa_cuti')->countAllResults());
    }

    public function testServiceMembentukSaldoDariTahunLaluDanIdempoten(): void
    {
        $this->pegawai('A001', 1);
        $this->pegawai('A002', 6);
        $this->pegawai('A003', 1);
        $this->saldo('A001', 2026, [6, 2, 5, 3, 4]);
        $this->saldo('A003', 2027, [1, 1, 1, 1, 1]);

        $r = $this->service()->jalankan(2027, false, null, 2027);

        $this->assertSame(3, $r['populasi']);
        $this->assertSame(2, $r['dibuat']);
        $this->assertSame(1, $r['dilewati']);
        $this->assertSame(1, $r['tanpa_tahun_lalu']);

        // A001: pool = min(12, 4 + min(6, 6) + min(6, 2)) = 12 → N-1 6, N-2 6; N = 12 − 3 = 9
        $this->seeInDatabase('pegawai_sisa_cuti', [
            'nip' => 'A001', 'tahun' => 2027, 'sisa_cuti' => 9, 'sisa_cuti_n1' => 6, 'sisa_cuti_n2' => 6, 'utang_cuti' => 0, 'sisa_cuti_ditangguhkan' => 0,
        ]);
        // A002 tanpa baris tahun lalu → pool 0, N penuh.
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => 'A002', 'tahun' => 2027, 'sisa_cuti' => 12, 'sisa_cuti_n1' => 0, 'sisa_cuti_n2' => 0]);
        // A003 sudah punya baris 2027: tidak disentuh.
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => 'A003', 'tahun' => 2027, 'sisa_cuti' => 1, 'sisa_cuti_n1' => 1]);

        $audit = $this->db->table('audit_logs')->where('entity', 'pegawai_sisa_cuti')->get()->getResultArray();
        $this->assertSame('cuti.saldo_tahunan', json_decode((string) $audit[0]['after_json'], true)['jenis_audit']);
        $this->assertCount(1, $audit);
        $this->assertNull($audit[0]['id_pengguna_actor']);
        $this->assertSame('2027', $audit[0]['entity_id']);

        // Eksekusi ulang: tidak ada yang baru, tidak ada audit tambahan.
        $ulang = $this->service()->jalankan(2027, false, null, 2027);
        $this->assertSame(0, $ulang['dibuat']);
        $this->assertSame(3, $ulang['dilewati']);
        $this->assertSame(3, $this->db->table('pegawai_sisa_cuti')->where('tahun', 2027)->countAllResults());
        $this->assertSame(1, $this->jumlahAuditRingkasan());
    }

    public function testHanyaPegawaiAktifJenis1Dan6(): void
    {
        $this->pegawai('B001', 1);
        $this->pegawai('B002', 6);
        $this->pegawai('B003', 2);          // jenis 2: tidak masuk populasi
        $this->pegawai('B004', 1, 2);       // nonaktif
        $this->pegawai('B005', 1, 10);      // dihapus

        $r = $this->service()->jalankan(2027, false, null, 2027);

        $this->assertSame(2, $r['populasi']);
        $this->assertSame(2, $this->db->table('pegawai_sisa_cuti')->where('tahun', 2027)->countAllResults());
        $this->dontSeeInDatabase('pegawai_sisa_cuti', ['nip' => 'B003']);
        $this->dontSeeInDatabase('pegawai_sisa_cuti', ['nip' => 'B004']);
    }

    public function testOpsiNipDanTanpaSyaratMasaKerja(): void
    {
        $this->pegawai('C001', 1);
        $this->pegawai('C002', 1);
        $this->db->table('pegawai')->where('nip', 'C002')->update(['tmt_status' => '2027-01-02']); // baru masuk: tetap dapat saldo

        $r = $this->service()->jalankan(2027, false, 'C002', 2027);

        $this->assertSame(1, $r['dibuat']);
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => 'C002', 'tahun' => 2027, 'sisa_cuti' => 12]);
        $this->dontSeeInDatabase('pegawai_sisa_cuti', ['nip' => 'C001']);
    }

    public function testTahunDiLuarRentangDitolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->jalankan(2099, true, null, 2027);
    }

    public function testDistribusiMencatatNilaiNN1N2(): void
    {
        $this->pegawai('D001', 1);
        $this->pegawai('D002', 1);
        $this->saldo('D001', 2026, [6, 6, 0, 0, 0]);

        $r = $this->service()->jalankan(2027, true, null, 2027);

        $this->assertSame([12 => 2], $r['distribusi_n']);
        $this->assertSame([0 => 1, 6 => 1], $r['distribusi_n1']);
        $this->assertSame([0 => 1, 6 => 1], $r['distribusi_n2']);
    }

    private function jumlahAuditRingkasan(): int
    {
        return $this->db->table('audit_logs')->where('entity', 'pegawai_sisa_cuti')->like('after_json', SaldoTahunanService::JENIS_AUDIT)->countAllResults();
    }

    private function service(): SaldoTahunanService
    {
        return new SaldoTahunanService($this->db, config(\Config\Cuti::class)->aturan(), new \App\Models\AuditLogModel());
    }

    private function pegawai(string $nip, int $jenis, int $status = 1): void
    {
        $this->db->table('pegawai')->insert([
            'nip'              => $nip,
            'nama'             => 'Pegawai ' . $nip,
            'tgl_lahir'        => '1990-01-01',
            'jenis_kelamin'    => 1,
            'id_jenis_pegawai' => $jenis,
            'status'           => $status,
        ]);
    }

    /**
     * @param array{int, int, int, int, int} $nilai N, N-1, N-2, utang, ditangguhkan
     */
    private function saldo(string $nip, int $tahun, array $nilai): void
    {
        $this->db->table('pegawai_sisa_cuti')->insert([
            'nip'        => $nip, 'tahun' => $tahun, 'sisa_cuti' => $nilai[0], 'sisa_cuti_n1' => $nilai[1], 'sisa_cuti_n2' => $nilai[2],
            'utang_cuti' => $nilai[3], 'sisa_cuti_ditangguhkan' => $nilai[4],
        ]);
    }
}
