<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Stub;

use App\Constants\Role;
use App\Exceptions\BelumTersediaException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Stub\StubPegawaiScope;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Tests\Support\Kepegawaian\Definisi\ContohPendidikan;

/**
 * S0-A (MAKE-002) — stub produksi `App\Libraries\Kepegawaian\Stub` fail-closed, diuji per kelas (bukan lewat service(),
 * jadi tetap berlaku setelah pemilik mengganti stub di Services.php):
 * - StubPegawaiScope menolak semua role dan menyaring daftar menjadi kosong;
 * - SETIAP method publik stub lain (termasuk method yang kelak ditambahkan pemilik interface penanda) melempar
 *   BelumTersediaException 501.
 *
 * @internal
 */
final class StubFailClosedTest extends CIUnitTestCase
{
    private const NIP = '199001012015011001';

    public function testStubPegawaiScopeMenolakSemua(): void
    {
        $scope = new StubPegawaiScope();

        foreach (Role::all() as $role) {
            $auth = $this->auth($role);
            $this->assertFalse($scope->bolehLihat($auth, self::NIP), "lihat role {$role}");
            $this->assertFalse($scope->bolehUbah($auth, self::NIP), "ubah role {$role}");
        }

        $builder = Database::connect('tests')->table('pegawai');
        $scope->terapkanKeQuery($builder, $this->auth(Role::SUPER_ADMIN));
        $this->assertStringContainsString('1 = 0', $builder->getCompiledSelect());
    }

    public function testSetiapMethodStubLainMenjawab501(): void
    {
        $diuji = 0;

        foreach ($this->kelasStub() as $kelas) {
            if ($kelas === StubPegawaiScope::class) {
                continue;
            }

            $reflection = new ReflectionClass($kelas);
            $this->assertTrue($reflection->isFinal(), "{$kelas} final");
            $objek = $reflection->newInstance();

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || $method->isStatic()) {
                    continue;
                }

                $argumen = array_map(fn (ReflectionParameter $p): mixed => $this->argumenDummy($p), $method->getParameters());

                try {
                    $method->invokeArgs($objek, $argumen);
                    $this->fail("{$kelas}::{$method->getName()} harus melempar BelumTersediaException");
                } catch (BelumTersediaException $e) {
                    $this->assertSame(501, $e->getStatusCode(), "{$kelas}::{$method->getName()}");
                    $diuji++;
                }
            }
        }

        // riwayatService 6 + snapshotSync 1 + attachmentService 4 + storageAdapter 4.
        $this->assertGreaterThanOrEqual(15, $diuji);
    }

    /**
     * @return list<class-string>
     */
    private function kelasStub(): array
    {
        $kelas = [];

        foreach (glob(APPPATH . 'Libraries/Kepegawaian/Stub/*.php') ?: [] as $file) {
            /** @var class-string $nama */
            $nama    = 'App\Libraries\Kepegawaian\Stub\\' . basename($file, '.php');
            $kelas[] = $nama;
        }

        $this->assertNotSame([], $kelas);

        return $kelas;
    }

    private function argumenDummy(ReflectionParameter $parameter): mixed
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $tipe = $parameter->getType();
        $nama = $tipe instanceof ReflectionNamedType ? $tipe->getName() : 'string';

        return match ($nama) {
            'int'                  => 1,
            'string'               => 'x',
            'array'                => [],
            'bool'                 => false,
            AuthContext::class     => $this->auth(Role::SUPER_ADMIN),
            RiwayatDefinisi::class => new ContohPendidikan(),
            AturanLampiran::class  => new AturanLampiran(14, true, 5, ['pdf']),
            UploadedFile::class    => new UploadedFile(__FILE__, 'x.pdf', 'application/pdf', 1, UPLOAD_ERR_OK),
            BaseBuilder::class     => Database::connect('tests')->table('pegawai'),
            default                => 'x', // union int|string dan tipe skalar lain
        };
    }

    private function auth(int $role): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => '1', 'role' => $role, 'nip' => self::NIP]);

        return $auth;
    }
}
