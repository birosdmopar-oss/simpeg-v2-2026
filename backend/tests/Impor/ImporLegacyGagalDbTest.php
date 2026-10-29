<?php

declare(strict_types=1);

namespace Tests\Impor;

use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use App\Libraries\Impor\PengimporLegacy;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Config\ImporLegacy;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * MAKE-056 R-1 review PR #32 — galat DB tidak boleh lolos diam-diam. Dengan `DBDebug = false`, CI4 tidak melempar
 * exception (insert → false, transaksi → status gagal); mesin wajib memeriksa hasil dan command wajib memaksa
 * `DBDebug = true`. Pelanggaran yang dipakai: `pegawai.nama` NULL di sumber vs NOT NULL di v2 (strict → 1048).
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporLegacyGagalDbTest extends ImporLegacyTestCase
{
    use StreamFilterTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isiMasterDasar();
        $this->src->table('jenis_pegawai')->insert(['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS', 'order' => 1, 'status' => 1]);
        $this->src->query('ALTER TABLE pegawai MODIFY nama VARCHAR(100) NULL');
        $this->src->table('pegawai')->insertBatch([
            ['nip' => '000000000000000001', 'nama' => 'Pegawai Uji A', 'tgl_lahir' => '1990-01-01', 'id_jenis_pegawai' => 1, 'jenis_kelamin' => 1, 'status' => 1],
            ['nip' => '000000000000000002', 'nama' => null, 'tgl_lahir' => '1991-02-02', 'id_jenis_pegawai' => 1, 'jenis_kelamin' => 2, 'status' => 1],
        ]);

        $this->jalankanImpor([1]);
    }

    public function testDbDebugFalseTulisGagalMenjadiImporGagalDanTierDiRollback(): void
    {
        $grup            = config(Database::class)->tests;
        $grup['DBDebug'] = false;
        $tujuan          = Database::connect($grup, false);

        try {
            (new PengimporLegacy($this->src, $tujuan, 'WIB', Keputusan::kosong()))->jalankan([2], false);
            $this->fail('Galat tulis dengan DBDebug = false harus menjadi ImporGagal.');
        } catch (ImporGagal $e) {
            // insertBatch() tidak selalu mengembalikan false; status transaksi yang gagal tetap tertangkap.
            $this->assertMatchesRegularExpression('/^(Galat DB saat tulis pegawai \(kode 1048\)|Tier 2 gagal di-commit)/', $e->getMessage());
        } finally {
            $tujuan->close();
        }

        $this->assertSame(0, $this->db->table('pegawai')->countAllResults(), 'tier 2 di-rollback');
    }

    public function testCommandMemaksaDbDebugDanTidakMencetakSelesai(): void
    {
        $config                   = config(Database::class);
        $asli                     = $config->tests['DBDebug'];
        $config->tests['DBDebug'] = false;
        $impor                    = new ImporLegacy();
        $impor->tujuanTerlarang   = [];
        Factories::injectMock('config', ImporLegacy::class, $impor);

        try {
            // Bentuk `--k=nilai` (runbook) dan `--k nilai` sama-sama diterima.
            command("impor:legacy --sumber={$this->lgc} --zona-sumber=WIB --konfirmasi {$this->db->getDatabase()} --tier=2");
        } finally {
            $config->tests['DBDebug'] = $asli;
        }

        $keluaran = $this->getStreamFilterBuffer();
        $this->assertStringContainsString("Tujuan: {$this->db->hostname}:{$this->db->port} / {$this->db->getDatabase()}", $keluaran, 'tujuan dicetak sebelum konfirmasi (R-2)');
        // Di dalam transaksi CI4 tidak selalu melempar exception walau DBDebug = true; pemeriksaan status tetap menangkapnya.
        $this->assertMatchesRegularExpression('/GAGAL( \(CodeIgniter\\Database\\Exceptions\\DatabaseException\): kode 1048|: Tier 2 gagal di-commit)/', $keluaran);
        $this->assertStringNotContainsString('Impor selesai', $keluaran);
        $this->assertSame(0, $this->db->table('pegawai')->countAllResults());
    }
}
