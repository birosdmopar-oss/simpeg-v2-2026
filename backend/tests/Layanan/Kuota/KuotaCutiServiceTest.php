<?php

declare(strict_types=1);

namespace Tests\Layanan\Kuota;

use App\Libraries\Layanan\Cuti\KuotaCuti;
use App\Libraries\Layanan\Cuti\KuotaCutiService;
use App\Libraries\MasterData\HariLiburService;
use Config\Cuti as CutiConfig;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-022 M3 (PRD Fase 4 §6.3.4–§6.3.5, §6.3.8 versi DB) — `KuotaCutiService` membungkus library murni: validasi
 * kuota dari baris DB (menunggu status 0 dan 3 dihitung ulang dari tanggal, baris sendiri dikecualikan), potong,
 * refund dengan snapshot, tahun acuan dari `created_at`, saldo tidak terdaftar, audit, dan lama cuti dengan libur.
 *
 * @internal
 */
final class KuotaCutiServiceTest extends DatabaseTestCase
{
    protected $seed = MasterDataSeeder::class;

    private const NIP = 'K0001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->table('pegawai')->insert(['nip' => self::NIP, 'nama' => 'Uji', 'tgl_lahir' => '1990-01-01', 'jenis_kelamin' => 1, 'id_jenis_pegawai' => 1, 'status' => 1]);
    }

    public function testHitungLamaMengurangiLiburDanAkhirPekan(): void
    {
        $this->db->table('hari_libur')->insert(['tgl_mulai' => '2026-11-11', 'tgl_akhir' => '2026-11-11', 'nama_libur' => 'Libur uji', 'status' => 1]);

        // Senin 9 s.d. Jumat 13 Nov 2026, Rabu 11 libur → 4 hari kerja (K22).
        $r = $this->service()->hitungLama(1, '2026-11-09', '2026-11-13');
        $this->assertSame(4, $r['lama_cuti']);
        $this->assertSame(['2026-11-11'], $r['hari_libur']);

        // Jumat–Senin → 2 (K24).
        $this->assertSame(2, $this->service()->hitungLama(1, '2026-11-06', '2026-11-09')['lama_cuti']);
        // Jenis 4 kalender inklusif.
        $this->assertSame(4, $this->service()->hitungLama(4, '2026-11-06', '2026-11-09')['lama_cuti']);
    }

    public function testSaldoTidakTerdaftar(): void
    {
        $h = $this->service()->periksa(self::NIP, '2026-11-09', '2026-11-10', 2026);

        $this->assertFalse($h->valid);
        $this->assertSame(KuotaCuti::KODE_SALDO_TIDAK_TERDAFTAR, $h->kode);
    }

    public function testBatasTepat24LolosDan25Ditolak(): void
    {
        $this->saldo(self::NIP, 2026, [12, 6, 6, 0, 0]);

        // 24 hari kerja: 2 Nov – 4 Des 2026 = 25 hari kerja tanpa libur → 27 Nov = 20; gunakan rentang eksplisit.
        $this->assertTrue($this->service()->periksa(self::NIP, '2026-11-02', '2026-12-04', 2026)->valid === false);
        $this->assertTrue($this->service()->periksa(self::NIP, '2026-11-02', '2026-12-01', 2026)->valid);
    }

    public function testMenungguStatus0Dan3DihitungDanBarisSendiriDikecualikan(): void
    {
        $this->saldo(self::NIP, 2026, [10, 3, 0, 0, 0]);
        $a = $this->cuti(self::NIP, 1, 0, '2026-11-02', '2026-11-06');   // 5 hari kerja, menunggu
        $this->cuti(self::NIP, 1, 3, '2026-11-09', '2026-11-11');        // 3 hari, menunggu (status 3)
        $this->cuti(self::NIP, 1, 1, '2026-11-16', '2026-11-20');        // final: tidak dihitung
        $this->cuti(self::NIP, 3, 0, '2026-11-23', '2026-11-27');        // jenis lain: tidak dihitung

        $this->assertSame(['tahun_ini' => 8, 'tahun_depan' => 0], $this->service()->menunggu(self::NIP, 2026));
        $this->assertSame(['tahun_ini' => 3, 'tahun_depan' => 0], $this->service()->menunggu(self::NIP, 2026, $a));

        // K8/K9: saldo 13, menunggu 8 + diajukan 5 = 13 lolos; 6 hari → 14 ditolak.
        $this->assertTrue($this->service()->periksa(self::NIP, '2026-12-07', '2026-12-11', 2026)->valid);
        $this->assertFalse($this->service()->periksa(self::NIP, '2026-12-07', '2026-12-14', 2026)->valid);
    }

    public function testPotongLaluRefundKembaliKeAwalDenganAudit(): void
    {
        $this->saldo(self::NIP, 2026, [12, 6, 6, 0, 0]);
        $id   = $this->cuti(self::NIP, 1, 1, '2026-11-02', '2026-11-06', ['sisa_cuti' => 12, 'sisa_cuti_n1' => 6, 'sisa_cuti_n2' => 6]);
        $cuti = $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRowArray();

        $h = $this->service()->potong($cuti, 1);
        $this->assertTrue($h->valid);
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => self::NIP, 'tahun' => 2026, 'sisa_cuti' => 12, 'sisa_cuti_n1' => 6, 'sisa_cuti_n2' => 1]); // K3

        $this->assertTrue($this->service()->kembalikan($cuti));
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => self::NIP, 'tahun' => 2026, 'sisa_cuti' => 12, 'sisa_cuti_n1' => 6, 'sisa_cuti_n2' => 6]); // K16

        $audit = $this->db->table('audit_logs')->where('entity', 'pegawai_sisa_cuti')->where('event', 'update')->get()->getResultArray();
        $this->assertCount(2, $audit);
        $this->assertNotNull($audit[0]['before_json']);
    }

    public function testPotongKuotaKurangTidakMengubahSaldo(): void
    {
        $this->saldo(self::NIP, 2026, [2, 0, 0, 0, 0]);
        $id   = $this->cuti(self::NIP, 1, 1, '2026-11-02', '2026-11-06');
        $cuti = $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRowArray();

        $h = $this->service()->potong($cuti, 1);

        $this->assertFalse($h->valid);
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => self::NIP, 'tahun' => 2026, 'sisa_cuti' => 2]);
    }

    public function testRefundMemakaiSnapshotSaldoImporDanTahunCreatedAt(): void
    {
        // K25: snapshot (12,9,0), saldo sesudah potong (12,4,0), lama 5 → (12,9,0). Baris dibuat 2025 → saldo 2025.
        $this->saldo(self::NIP, 2025, [12, 4, 0, 0, 0]);
        $this->saldo(self::NIP, 2026, [1, 1, 1, 0, 0]);
        $id   = $this->cuti(self::NIP, 1, 1, '2025-12-15', '2025-12-19', ['sisa_cuti' => 12, 'sisa_cuti_n1' => 9, 'sisa_cuti_n2' => 0, 'created_at' => '2025-12-01 08:00:00']);
        $cuti = $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRowArray();

        $this->service()->kembalikan($cuti);

        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => self::NIP, 'tahun' => 2025, 'sisa_cuti' => 12, 'sisa_cuti_n1' => 9, 'sisa_cuti_n2' => 0]);
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => self::NIP, 'tahun' => 2026, 'sisa_cuti' => 1, 'sisa_cuti_n1' => 1, 'sisa_cuti_n2' => 1]);
    }

    public function testRefundTanpaBarisSaldoMengembalikanFalse(): void
    {
        $id   = $this->cuti(self::NIP, 1, 1, '2026-11-02', '2026-11-06');
        $cuti = $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRowArray();

        $this->assertFalse($this->service()->kembalikan($cuti));
    }

    private function service(): KuotaCutiService
    {
        return new KuotaCutiService($this->db, config(CutiConfig::class)->aturan(), new HariLiburService($this->db));
    }

    /**
     * @param array{int, int, int, int, int} $n
     */
    private function saldo(string $nip, int $tahun, array $n): void
    {
        $this->db->table('pegawai_sisa_cuti')->insert([
            'nip'        => $nip, 'tahun' => $tahun, 'sisa_cuti' => $n[0], 'sisa_cuti_n1' => $n[1], 'sisa_cuti_n2' => $n[2],
            'utang_cuti' => $n[3], 'sisa_cuti_ditangguhkan' => $n[4],
        ]);
    }

    /**
     * @param array<string, mixed> $lebih
     */
    private function cuti(string $nip, int $jenis, int $status, string $mulai, string $akhir, array $lebih = []): int
    {
        $this->db->table('riwayat_cuti')->insert($lebih + [
            'nip'       => $nip, 'id_jenis_cuti' => $jenis, 'nama_jenis_cuti' => 'Cuti ' . $jenis, 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir,
            'lama_cuti' => 1, 'status' => $status, 'created_at' => '2026-10-27 08:00:00',
        ]);

        return (int) $this->db->insertID();
    }
}
