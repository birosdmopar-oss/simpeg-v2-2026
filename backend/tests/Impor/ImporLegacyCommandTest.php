<?php

declare(strict_types=1);

namespace Tests\Impor;

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
        yield 'tier tak sah' => ['--sumber=a --zona-sumber=WIB --tier=9', 'Pemakaian:'];
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
}
