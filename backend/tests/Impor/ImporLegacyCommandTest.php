<?php

declare(strict_types=1);

namespace Tests\Impor;

use App\Libraries\Impor\DaftarTabel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\StreamFilterTrait;
use Config\ImporLegacy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * MAKE-056 — penguraian opsi `impor:legacy` lewat `OpsiCli` (CR-080): `--k=v` dan `--k v` setara, opsi tak dikenal /
 * tanpa nilai / argumen berlebih ditolak sebelum koneksi apa pun dibuat.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporLegacyCommandTest extends ImporLegacyTestCase
{
    use StreamFilterTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isiMasterDasar();
        $impor                  = new ImporLegacy();
        $impor->tujuanTerlarang = [];
        Factories::injectMock('config', ImporLegacy::class, $impor);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bentukOpsi(): iterable
    {
        yield 'bentuk =' => ['--sumber=%s --zona-sumber=WIB --konfirmasi=%s --tier=1 --batch=1 --dry-run'];
        yield 'bentuk spasi' => ['--sumber %s --zona-sumber WIB --konfirmasi %s --tier 1 --batch 1 --dry-run'];
    }

    #[DataProvider('bentukOpsi')]
    public function testKeduaBentukOpsiSetara(string $pola): void
    {
        command('impor:legacy ' . sprintf($pola, $this->lgc, $this->db->getDatabase()));

        $keluaran = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('Dry-run selesai', $keluaran);
        $this->assertMatchesRegularExpression('/T1 unit +sumber=1 ditulis=1/', $keluaran);
        $this->assertSame(0, $this->db->table('unit')->countAllResults(), 'dry-run tidak menulis');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function opsiSalah(): iterable
    {
        yield 'tak dikenal' => ['--sumbr=x --zona-sumber=WIB', 'Opsi --sumbr tidak dikenal'];
        yield 'tanpa nilai' => ['--sumber --zona-sumber=WIB', 'Opsi --sumber wajib diberi nilai'];
        yield 'ganda' => ['--sumber=a --sumber=b --zona-sumber=WIB', 'diberikan lebih dari sekali'];
        yield 'tier tak sah' => ['--sumber=a --zona-sumber=WIB --tier=99', 'Pemakaian:'];
        yield 'tanpa zona' => ['--sumber=a', 'Pemakaian:'];
        yield 'argumen lebih' => ['x --sumber=a --zona-sumber=WIB', 'Pemakaian:'];
    }

    #[DataProvider('opsiSalah')]
    public function testOpsiSalahDitolakSebelumKoneksi(string $opsi, string $pesan): void
    {
        command('impor:legacy ' . $opsi);

        $keluaran = $this->getStreamFilterBuffer();
        $this->assertStringContainsString($pesan, $keluaran);
        $this->assertStringNotContainsString('Tujuan:', $keluaran);
    }

    public function testSumberSiasnDicetakDanDijagaSepertiSumber(): void
    {
        $db = $this->db->getDatabase();

        command("impor:legacy --sumber={$this->lgc} --sumber-siasn={$db} --zona-sumber=WIB --konfirmasi={$db} --tier=" . DaftarTabel::TIER_MAKS . ' --dry-run');
        $keluaran = $this->getStreamFilterBuffer();
        $this->assertStringContainsString("sumber SIASN: {$db}", $keluaran, 'dicetak sebelum konfirmasi (R-2)');
        $this->assertStringContainsString('GAGAL: Database sumber dan tujuan sama.', $keluaran);

        // Salinan siasn_simpeg tanpa salah satu dari 8 tabel → gagal (snapshot tidak lengkap, K-9).
        $this->siapkanSumberSiasn(['GOLONGAN_ID' => 'id_pangkat']);
        command("impor:legacy --sumber={$this->lgc} --sumber-siasn={$this->lgc}_sia --zona-sumber=WIB --konfirmasi={$db} --tier=" . DaftarTabel::TIER_MAKS . ' --dry-run');
        $this->assertStringContainsString('GAGAL: Tabel sumber JABATAN_FUNGSIONAL_ID tidak ada.', $this->getStreamFilterBuffer());
    }

    public function testSemuaTierTanpaTabelSiasnDiSalinanGagalMenyebutTabel(): void
    {
        // Salinan lama (ekstraksi sebelum B3) tanpa siasn_upload_arsip: gagal jelas, bukan diam (review P-3).
        $this->src->query('DROP TABLE siasn_upload_arsip');
        $db = $this->db->getDatabase();

        command("impor:legacy --sumber={$this->lgc} --zona-sumber=WIB --konfirmasi={$db} --dry-run");
        $this->assertStringContainsString('GAGAL: Tabel sumber siasn_upload_arsip tidak ada.', $this->getStreamFilterBuffer());
    }

    public function testTierPerTabelTanpaJejakDitolakSebelumKoneksi(): void
    {
        $db = $this->db->getDatabase();

        command("impor:legacy --sumber={$this->lgc} --zona-sumber=WIB --konfirmasi={$db}");
        $keluaran = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('wajib --jejak=<berkas> (DBV-029 K-M8). Tidak ada yang ditulis.', $keluaran);
        $this->assertStringNotContainsString('Tujuan:', $keluaran, 'ditolak sebelum koneksi (review P-4)');
        $this->assertSame(0, $this->db->table('unit')->countAllResults());
    }
}
