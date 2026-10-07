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
use App\Libraries\Kepegawaian\Riwayat\RiwayatRegistry;
use App\Libraries\Kepegawaian\Stub\BelumTersediaException;
use App\Libraries\Kepegawaian\Stub\StubPegawaiScope;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Support\Kepegawaian\Definisi\ContohPendidikan;
use Tests\Support\Kepegawaian\FakePegawaiScope;

/**
 * S0-A (MAKE-002) — 12 service Fase 3 terdaftar sekali di Config\Services, bertipe interface, stub fail-closed; fake
 * hanya di tests/_support dan disuntik lewat Services::injectMock().
 *
 * @internal
 */
final class ServicesKontrakTest extends CIUnitTestCase
{
    private const NIP = '199001012015011001';

    protected function tearDown(): void
    {
        Services::reset(true);

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

    public function testStubPegawaiScopeMenolakSemua(): void
    {
        $scope = service('pegawaiScope');
        $this->assertInstanceOf(StubPegawaiScope::class, $scope);

        foreach (Role::all() as $role) {
            $auth = $this->auth($role);
            $this->assertFalse($scope->bolehLihat($auth, self::NIP), "lihat role {$role}");
            $this->assertFalse($scope->bolehUbah($auth, self::NIP), "ubah role {$role}");
        }

        $builder = Database::connect('tests')->table('pegawai');
        $scope->terapkanKeQuery($builder, $this->auth(Role::SUPER_ADMIN));
        $this->assertStringContainsString('1 = 0', $builder->getCompiledSelect());
    }

    public function testStubServiceMenjawab501(): void
    {
        $auth = $this->auth(Role::SUPER_ADMIN);

        $panggilan = [
            'riwayatService.daftar' => static fn () => service('riwayatService')->daftar($auth, self::NIP, 'pendidikan'),
            'riwayatService.proses' => static fn () => service('riwayatService')->proses($auth, self::NIP, 'pendidikan', 1, 'setujui', null),
            'snapshotSync'          => static fn () => service('snapshotSync')->sinkronkan(new ContohPendidikan(), self::NIP),
            'attachmentService'     => static fn () => service('attachmentService')->daftar(self::NIP, 14, 1),
            'storageAdapter'        => static fn () => service('storageAdapter')->ada('x.pdf'),
        ];

        foreach ($panggilan as $nama => $panggil) {
            try {
                $panggil();
                $this->fail("{$nama} harus melempar BelumTersediaException");
            } catch (BelumTersediaException $e) {
                $this->assertSame(501, $e->getStatusCode(), $nama);
            }
        }
    }

    public function testRegistryProduksiMemakaiScopeYangDisuntik(): void
    {
        $this->assertInstanceOf(RiwayatRegistry::class, service('riwayatRegistry'));

        // Pola test WS: suntik fake scope, registry baru membaca scope itu.
        $fake = FakePegawaiScope::izinkanSemua();
        Services::injectMock('pegawaiScope', $fake);
        $this->assertSame($fake, service('pegawaiScope'));

        $registry = new RiwayatRegistry(service('pegawaiScope'), [new ContohPendidikan()]);
        $this->assertCount(1, $registry->descriptorUntuk($this->auth(Role::SUPER_ADMIN), self::NIP));
        $this->assertNotSame([], $fake->panggilan);
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

    private function auth(int $role): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => '1', 'role' => $role, 'nip' => self::NIP]);

        return $auth;
    }
}
