<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\JenisRiwayat;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatRegistry;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Kepegawaian;
use LogicException;
use Tests\Support\Kepegawaian\Definisi\ContohKarpeg;
use Tests\Support\Kepegawaian\Definisi\ContohPendidikan;
use Tests\Support\Kepegawaian\FakePegawaiScope;

/**
 * S0-A (MAKE-002) — auto-discovery Definisi riwayat dan descriptor tab (izin Definisi × role × PegawaiScope).
 *
 * @internal
 */
final class RiwayatRegistryTest extends CIUnitTestCase
{
    private const NIP = '199001012015011001';

    private const FOLDER_CONTOH = SUPPORTPATH . 'Kepegawaian/Definisi';

    private const NAMESPACE_CONTOH = 'Tests\Support\Kepegawaian\Definisi';

    private ?string $folderSementara = null;

    protected function tearDown(): void
    {
        if ($this->folderSementara !== null) {
            array_map('unlink', glob($this->folderSementara . '/*') ?: []);
            rmdir($this->folderSementara);
            $this->folderSementara = null;
        }

        parent::tearDown();
    }

    public function testDiscoveryMembacaDefinisiContoh(): void
    {
        $definisi = RiwayatRegistry::temukan(self::FOLDER_CONTOH, self::NAMESPACE_CONTOH);

        $this->assertSame([ContohKarpeg::class, ContohPendidikan::class], array_map(static fn (RiwayatDefinisi $d): string => $d::class, $definisi));

        $registry = new RiwayatRegistry(FakePegawaiScope::izinkanSemua(), $definisi);
        $this->assertInstanceOf(ContohPendidikan::class, $registry->definisi('pendidikan'));
        $this->assertNull($registry->definisi('tidak-ada'));
        // Urut urutanTab() (pendidikan 20, karpeg bawaan 100), bukan urutan berkas.
        $this->assertSame(['pendidikan', 'karpeg'], array_map(static fn (RiwayatDefinisi $d): string => $d->jenis(), $registry->semua()));
    }

    public function testFolderKosongAtauTidakAdaAman(): void
    {
        $this->assertSame([], RiwayatRegistry::temukan(SUPPORTPATH . 'Kepegawaian/TidakAda', self::NAMESPACE_CONTOH));

        $this->folderSementara = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'definisi-kosong-' . bin2hex(random_bytes(4));
        mkdir($this->folderSementara);
        $this->assertSame([], RiwayatRegistry::temukan($this->folderSementara, self::NAMESPACE_CONTOH));

        $registry = new RiwayatRegistry(FakePegawaiScope::izinkanSemua(), []);
        $this->assertSame([], $registry->semua());
        $this->assertSame([], $registry->descriptorUntuk($this->auth(Role::SUPER_ADMIN), self::NIP));
    }

    public function testFolderProduksiTerpindaiTanpaError(): void
    {
        $config   = config(Kepegawaian::class);
        $definisi = RiwayatRegistry::temukan($config->definisiRiwayatPath, $config->definisiRiwayatNamespace);
        $registry = new RiwayatRegistry(FakePegawaiScope::izinkanSemua(), $definisi);

        $this->assertDirectoryExists($config->definisiRiwayatPath);
        $this->assertCount(count($definisi), $registry->semua());

        foreach ($registry->semua() as $d) {
            $this->assertTrue(JenisRiwayat::valid($d->jenis()));
        }
    }

    public function testBerkasBukanDefinisiDitolak(): void
    {
        $this->expectException(LogicException::class);
        // Folder _support/Kepegawaian berisi kelas lain (FakePegawaiScope, dll.) — bukan turunan RiwayatDefinisi.
        RiwayatRegistry::temukan(SUPPORTPATH . 'Kepegawaian', 'Tests\Support\Kepegawaian');
    }

    public function testSlugGandaDitolak(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("'pendidikan' terdaftar lebih dari sekali");
        new RiwayatRegistry(FakePegawaiScope::izinkanSemua(), [new ContohPendidikan(), new ContohPendidikan()]);
    }

    public function testDefinisiSalahBentukDitolak(): void
    {
        $kasus = [
            'slug di luar daftar beku' => $this->definisi('konket', ['lihat' => [Role::SUPER_ADMIN]]),
            'aksi tidak dikenal'       => $this->definisi('kp', ['setujui' => [Role::SUPER_ADMIN]]),
            'role tidak dikenal'       => $this->definisi('kp', ['lihat' => [9]]),
            'tabel tidak valid'        => $this->definisi('kp', [], 'riwayat_kp; DROP'),
        ];

        foreach ($kasus as $nama => $definisi) {
            try {
                new RiwayatRegistry(FakePegawaiScope::izinkanSemua(), [$definisi]);
                $this->fail("{$nama} harus ditolak");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDaftarSlugBeku(): void
    {
        $this->assertSame(
            ['jabatan', 'kp', 'kgb', 'pendidikan', 'diklat', 'seminar', 'skp', 'skp-periodik', 'hukdis', 'ak', 'ak-siasn',
                'keluarga', 'alamat', 'tanda-jasa', 'organisasi', 'karpeg', 'kariskarsu'],
            JenisRiwayat::SLUG,
        );
        $this->assertFalse(JenisRiwayat::valid('konket'));
        $this->assertFalse(JenisRiwayat::valid('lkh'));
    }

    public function testDescriptorPerRole(): void
    {
        $registry = $this->registryContoh(FakePegawaiScope::izinkanSemua());

        // Bentuk beku: urutan key dan tipe; karpeg (alur usulan) tidak menjadi tab.
        $this->assertSame([[
            'jenis'       => 'pendidikan',
            'label'       => 'Riwayat Pendidikan',
            'can_view'    => true,
            'can_create'  => true,
            'can_edit'    => true,
            'can_delete'  => true,
            'can_process' => true,
        ]], $registry->descriptorUntuk($this->auth(Role::SUPER_ADMIN), self::NIP));

        foreach ([Role::PEGAWAI, Role::PTT, Role::PPPK] as $role) {
            $this->assertSame(
                [['jenis' => 'pendidikan', 'label' => 'Riwayat Pendidikan', 'can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => false, 'can_process' => false]],
                $registry->descriptorUntuk($this->auth($role), self::NIP),
                "role {$role}",
            );
        }

        // Role tanpa izin lihat dan konteks tanpa role → tidak ada tab.
        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN, null] as $role) {
            $this->assertSame([], $registry->descriptorUntuk($this->auth($role), self::NIP), 'role ' . var_export($role, true));
        }
    }

    public function testDescriptorMengikutiLingkup(): void
    {
        $tolak = FakePegawaiScope::tolakSemua();
        $this->assertSame([], $this->registryContoh($tolak)->descriptorUntuk($this->auth(Role::SUPER_ADMIN), self::NIP));

        $hanyaLihat = FakePegawaiScope::izinkanHanya([self::NIP], []);
        $this->assertSame(
            [['jenis' => 'pendidikan', 'label' => 'Riwayat Pendidikan', 'can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false, 'can_process' => false]],
            $this->registryContoh($hanyaLihat)->descriptorUntuk($this->auth(Role::ADMIN_SATKER), self::NIP),
        );
        // Lingkup ditanya sekali per permintaan.
        $this->assertSame([['bolehLihat', self::NIP], ['bolehUbah', self::NIP]], $hanyaLihat->panggilan);
    }

    public function testIzinDefinisi(): void
    {
        $d = new ContohPendidikan();

        $this->assertTrue($d->boleh(AksiRiwayat::Proses, Role::ADMIN_SATKER));
        $this->assertFalse($d->boleh(AksiRiwayat::Proses, Role::PEGAWAI));
        $this->assertFalse($d->boleh(AksiRiwayat::Lihat, null));
        $this->assertFalse($d->boleh(AksiRiwayat::Lihat, 99));
        $this->assertSame(AlurRiwayat::SelfService, $d->alur());
        $this->assertSame('nip', $d->kolomNip());
        $this->assertTrue($d->kunciBarisDisetujui());
        $this->assertSame([0, 1, 2, 10], array_keys($d->pemetaanStatus()));
        $this->assertSame(14, $d->lampiran()->idRiwayat);
        $this->assertSame('pegawai_pendidikan', $d->snapshot()[0]->tabel);
    }

    private function registryContoh(FakePegawaiScope $scope): RiwayatRegistry
    {
        return new RiwayatRegistry($scope, [new ContohPendidikan(), new ContohKarpeg()]);
    }

    private function auth(?int $role): AuthContext
    {
        $auth = new AuthContext();
        $auth->setClaims($role === null ? ['sub' => '1'] : ['sub' => '1', 'role' => $role, 'nip' => self::NIP]);

        return $auth;
    }

    /**
     * @param array<string, list<int>> $izin
     */
    private function definisi(string $jenis, array $izin, string $tabel = 'riwayat_kp'): RiwayatDefinisi
    {
        return new class ($jenis, $izin, $tabel) extends RiwayatDefinisi {
            /**
             * @param array<string, list<int>> $izinJenis
             */
            public function __construct(private string $slug, private array $izinJenis, private string $tabelJenis)
            {
            }

            public function jenis(): string
            {
                return $this->slug;
            }

            public function label(): string
            {
                return 'Uji';
            }

            public function tabel(): string
            {
                return $this->tabelJenis;
            }

            public function primaryKey(): string
            {
                return 'id_uji';
            }

            public function fields(): array
            {
                return [];
            }

            public function izin(): array
            {
                return $this->izinJenis;
            }

            public function alur(): AlurRiwayat
            {
                return AlurRiwayat::Admin;
            }
        };
    }
}
