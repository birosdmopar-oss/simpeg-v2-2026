<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Interfaces\Kepegawaian\BiodataServiceInterface;
use App\Interfaces\Kepegawaian\KonketServiceInterface;
use App\Interfaces\Kepegawaian\LkhServiceInterface;
use App\Interfaces\Kepegawaian\NipCascadeInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\PegawaiServiceInterface;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Interfaces\Kepegawaian\RiwayatServiceInterface;
use App\Interfaces\Kepegawaian\SnapshotSyncInterface;
use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Interfaces\Kepegawaian\StrukturServiceInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Kepegawaian;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Support\Kepegawaian\FakePegawaiScope;

/**
 * S0-A (MAKE-002) — 12 service Fase 3 terdaftar sekali di Config\Services, bertipe interface, shared; wiring registry
 * mengikuti scope yang disuntik. Test ini TIDAK mengunci kelas di balik service (stub atau nyata), sehingga pemilik
 * yang mengganti stub tidak perlu mengubahnya; perilaku fail-closed stub diuji langsung per kelas di
 * tests/unit/Kepegawaian/Stub/StubFailClosedTest.php.
 *
 * @internal
 */
final class ServicesKontrakTest extends CIUnitTestCase
{
    private const NIP = '199001012015011001';

    protected function tearDown(): void
    {
        Services::reset(true);
        Factories::reset('config');

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function serviceProvider(): array
    {
        return [
            'pegawaiScope'      => ['pegawaiScope', PegawaiScopeInterface::class],
            'attachmentService' => ['attachmentService', AttachmentServiceInterface::class],
            'storageAdapter'    => ['storageAdapter', StorageAdapterInterface::class],
            'riwayatRegistry'   => ['riwayatRegistry', RiwayatRegistryInterface::class],
            'riwayatService'    => ['riwayatService', RiwayatServiceInterface::class],
            'snapshotSync'      => ['snapshotSync', SnapshotSyncInterface::class],
            'pegawaiService'    => ['pegawaiService', PegawaiServiceInterface::class],
            'biodataService'    => ['biodataService', BiodataServiceInterface::class],
            'nipCascade'        => ['nipCascade', NipCascadeInterface::class],
            'strukturService'   => ['strukturService', StrukturServiceInterface::class],
            'lkhService'        => ['lkhService', LkhServiceInterface::class],
            'konketService'     => ['konketService', KonketServiceInterface::class],
        ];
    }

    /**
     * @param class-string $interface
     */
    #[DataProvider('serviceProvider')]
    public function testServiceTerdaftarBertipeInterface(string $nama, string $interface): void
    {
        $method = new ReflectionMethod(Services::class, $nama);
        $tipe   = $method->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $tipe);
        $this->assertSame($interface, $tipe->getName(), "tipe kembalian Services::{$nama}()");
        $this->assertTrue(interface_exists($interface));

        $baru = $method->invoke(null, false);
        $this->assertInstanceOf($interface, $baru);

        // Produksi tidak pernah memakai fake test.
        $this->assertStringStartsWith('App\\', $baru::class);

        $shared = $method->invoke(null, true);
        $this->assertInstanceOf($interface, $shared);
        $this->assertSame($shared, $method->invoke(null, true), 'shared instance');
        $this->assertNotSame($shared, $baru);
    }

    public function testRegistrySharedMengikutiScopeYangDisuntik(): void
    {
        // Folder Definisi diarahkan ke Definisi contoh (pendidikan = tab untuk role 1).
        $config                           = new Kepegawaian();
        $config->definisiRiwayatPath      = SUPPORTPATH . 'Kepegawaian/Definisi';
        $config->definisiRiwayatNamespace = 'Tests\Support\Kepegawaian\Definisi';
        Factories::injectMock('config', Kepegawaian::class, $config);

        $auth = new AuthContext();
        $auth->setClaims(['sub' => '1', 'role' => Role::SUPER_ADMIN, 'nip' => self::NIP]);

        Services::injectMock('pegawaiScope', FakePegawaiScope::izinkanSemua());
        $registry = service('riwayatRegistry');
        $this->assertCount(1, $registry->descriptorUntuk($auth, self::NIP));

        // Registry shared yang SAMA mengikuti scope yang disuntik kemudian (tanpa resetSingle).
        Services::injectMock('pegawaiScope', FakePegawaiScope::tolakSemua());
        $this->assertSame($registry, service('riwayatRegistry'));
        $this->assertSame([], $registry->descriptorUntuk($auth, self::NIP));

        Services::injectMock('pegawaiScope', FakePegawaiScope::izinkanSemua());
        $this->assertCount(1, $registry->descriptorUntuk($auth, self::NIP));
    }

    public function testKodeProduksiTidakMerujukTestSupport(): void
    {
        $pelanggar = [];
        $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'Tests\\Support')) {
                $pelanggar[] = $file->getPathname();
            }
        }

        $this->assertSame([], $pelanggar);
    }
}
