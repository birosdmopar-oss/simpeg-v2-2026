<?php

declare(strict_types=1);

namespace Tests\Database\Layanan;

use App\Libraries\Cli\KoneksiCli;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Layanan\StatusHarian\StatusHarianService;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-062 review B-1 — update `pegawai` dan `audit_logs` harus berada pada SATU koneksi/transaksi. Test ini memakai koneksi
 * kedua (`KoneksiCli::baru()`, sesi MySQL lain dengan `DBDebug` aktif seperti command CLI) dengan data yang di-commit
 * sungguhan (grup `db-isolasi-penuh`), supaya terbukti: (1) model audit dibuat pada koneksi layanan, bukan koneksi bersama;
 * (2) audit gagal membatalkan update; (3) keberhasilan terlihat oleh sesi lain sebagai update + audit sekaligus.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class StatusHarianKoneksiTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    protected $seed = MasterDataSeeder::class;

    private BaseConnection $lain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lain = KoneksiCli::baru();
    }

    protected function tearDown(): void
    {
        $this->lain->close();
        parent::tearDown();
    }

    /**
     * @return array{0: string, 1: int} NIP dan id pelaku
     */
    private function siapkan(): array
    {
        $this->db->table('jenis_status')->insert(['id_jenis_status' => 12, 'jenis_status' => 'Status Lain', 'status_pegawai' => 1, 'order' => 12, 'status' => 1]);
        (new AkunSistemIntegrasi($this->db))->siapkan();
        $nip = $this->buatPegawai(['id_jenis_status' => 1]);
        $this->db->table('riwayat_tb')->insert([
            'nip' => $nip, 'tgl_mulai' => '2026-01-01', 'tgl_akhir' => '2026-12-31', 'status' => 1, 'jenjang_pendidikan_singkat' => 'S2',
            'institusi_pendidikan' => 'Universitas Uji', 'jurusan_pendidikan' => 'Jurusan Uji', 'dalam_negeri' => 1,
            'lokasi_pendidikan' => 'Kota Uji', 'lembaga_yang_mendanai' => 'Lembaga Uji',
        ]);

        return [$nip, (new AkunSistemIntegrasi($this->db))->id()];
    }

    public function testModelAuditDibuatPadaKoneksiLayananBukanKoneksiBersama(): void
    {
        [, $pelaku] = $this->siapkan();
        $dipakai    = null;

        $service = new StatusHarianService($this->lain, static function (BaseConnection $koneksi) use (&$dipakai): AuditLogModel {
            $dipakai = $koneksi;

            return new AuditLogModel($koneksi);
        });

        $hasil = $service->jalankan('2026-06-01', false, $pelaku);

        $this->assertSame(1, $hasil['berubah']);
        $this->assertSame($this->lain, $dipakai, 'audit memakai koneksi layanan');
        $this->assertNotSame($this->db, $dipakai);
    }

    public function testAuditGagalMembatalkanUpdatePadaKoneksiKedua(): void
    {
        [$nip, $pelaku] = $this->siapkan();

        $service = new StatusHarianService($this->lain, static fn (BaseConnection $koneksi): AuditLogModel => new class ($koneksi) extends AuditLogModel {
            public function record(string $entity, string|int $entityId, string $event, ?array $before, ?array $after, ?int $actorId = null, ?string $actorNip = null): int
            {
                throw new RuntimeException('audit gagal (uji)');
            }
        });

        $hasil = $service->jalankan('2026-06-01', false, $pelaku);

        $this->assertSame(1, $hasil['gagal']);
        $this->assertSame(1, (int) $this->db->table('pegawai')->select('id_jenis_status')->where('nip', $nip)->get()->getRowArray()['id_jenis_status'], 'update ikut dibatalkan');
        $this->assertSame(0, $this->db->table('audit_logs')->where('entity', 'pegawai')->where('entity_id', $nip)->countAllResults());
    }

    public function testKeberhasilanTerlihatDiSesiLainSebagaiUpdateDanAudit(): void
    {
        [$nip, $pelaku] = $this->siapkan();

        (new StatusHarianService($this->lain))->jalankan('2026-06-01', false, $pelaku);

        $this->assertSame(2, (int) $this->db->table('pegawai')->select('id_jenis_status')->where('nip', $nip)->get()->getRowArray()['id_jenis_status']);
        $this->assertSame(1, $this->db->table('audit_logs')->where('entity', 'pegawai')->where('entity_id', $nip)->countAllResults());
    }
}
