<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Riwayat\RiwayatRegistry;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\Riwayat\UjiPendidikan;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * Hook engine aditif `RiwayatDefinisi::isianMentah()` (MAKE-019, untuk B-07 sub rumpun multiselect WS-2): kunci mentah
 * diteruskan apa adanya ke validate()/beforeSave() saat tambah & ubah hanya bila dikirim (JSON array, form `kolom[]`
 * berulang, form `kolom=''`), tidak pernah ditulis engine ke tabel, nama bentrok ditolak registry; jenis tanpa isian
 * mentah tidak berubah.
 *
 * @internal
 */
final class RiwayatIsianMentahTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatUjiTrait;

    private UjiPendidikan $d;
    private string $nip;

    /**
     * @var array<string, mixed>
     */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d         = new UjiPendidikan();
        $this->d->mentah = ['pilihan_uji'];
        $this->pasangEngine(null, [$this->d]);
        $this->nip         = $this->buatPegawai();
        [, , $this->admin] = $this->aktor(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: bool, 1: mixed, 2: string|null}>
     */
    public static function bentukProvider(): array
    {
        return [
            'JSON array berisi'     => [true, ['A', 'B'], 'A***B'],
            'JSON array kosong'     => [true, [], null],
            'form kolom[] berulang' => [false, ['A', 'B'], 'A***B'],
            "form kolom=''"         => [false, '', null],
        ];
    }

    #[DataProvider('bentukProvider')]
    public function testTambahMeneruskanNilaiMentahApaAdanya(bool $json, mixed $nilai, ?string $tersimpan): void
    {
        $result = $this->kirim('post', $json, ['tgl_lulus' => '2012-08-30', 'pilihan_uji' => $nilai]);

        $result->assertStatus(201);
        $this->assertSame([$nilai, $nilai], [$this->hook('validate')['pilihan_uji'] ?? 'absen', $this->hook('beforeSave')['pilihan_uji'] ?? 'absen']);
        $row = $this->json($result)['data'];
        $this->assertSame($tersimpan, $row['institusi_pendidikan'], 'dipindah Definisi ke kolom nyata');
        $this->assertArrayNotHasKey('pilihan_uji', $row, 'kunci mentah tidak pernah ditulis/dibaca sebagai kolom');
    }

    public function testUbahMeneruskanNilaiMentahDanTidakDikirimBerartiAbsen(): void
    {
        $id = (int) $this->json($this->kirim('post', true, ['tgl_lulus' => '2012-08-30']))['data']['id_riwayat_pendidikan'];
        $this->assertArrayNotHasKey('pilihan_uji', $this->hook('validate'), 'tambah tanpa kunci');
        $this->assertArrayNotHasKey('pilihan_uji', $this->hook('beforeSave'));

        $this->d->panggilanHook = [];
        $ubah                   = $this->kirim('put', true, ['glr_akhir' => 'S.T.', 'pilihan_uji' => ['C']], $id);
        $ubah->assertStatus(200);
        $this->assertSame([['C'], ['C']], [$this->hook('validate')['pilihan_uji'], $this->hook('beforeSave')['pilihan_uji']]);
        $this->assertSame('C', $this->json($ubah)['data']['institusi_pendidikan']);

        $this->d->panggilanHook = [];
        $this->kirim('put', true, ['glr_akhir' => 'M.T.'], $id)->assertStatus(200);
        $this->assertArrayNotHasKey('pilihan_uji', $this->hook('beforeSave'), 'ubah tanpa kunci');
        $this->seeInDatabase('riwayat_pendidikan', ['id_riwayat_pendidikan' => $id, 'institusi_pendidikan' => 'C', 'glr_akhir' => 'M.T.']);
    }

    public function testDefinisiTanpaPemindahanTidakMenulisKunciMentah(): void
    {
        // Definisi mengabaikan kunci mentah (tidak memindahkannya): engine tetap membuangnya → tanpa galat kolom.
        $this->d->mentah = ['pilihan_lain'];
        $this->pasangEngine(null, [$this->d]);

        $this->kirim('post', true, ['tgl_lulus' => '2012-08-30', 'pilihan_lain' => ['X']])->assertStatus(201);
        $this->assertSame(['X'], $this->hook('beforeSave')['pilihan_lain']);
    }

    public function testJenisTanpaIsianMentahTidakBerubah(): void
    {
        $this->d->mentah = [];
        $this->pasangEngine(null, [$this->d]);

        $result = $this->kirim('post', true, ['tgl_lulus' => '2012-08-30', 'pilihan_uji' => ['A']]);

        $result->assertStatus(201);
        $this->assertArrayNotHasKey('pilihan_uji', $this->hook('validate'));
        $this->assertArrayNotHasKey('pilihan_uji', $this->hook('beforeSave'));
        $this->assertNull($this->json($result)['data']['institusi_pendidikan']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bentrokProvider(): array
    {
        return [
            'field fields()' => ['tgl_lulus'],
            'kolom NIP'      => ['nip'],
            'kolom status'   => ['status'],
            'PK'             => ['id_riwayat_pendidikan'],
            'nama tidak sah' => ['pilihan uji'],
        ];
    }

    #[DataProvider('bentrokProvider')]
    public function testNamaBentrokDitolakRegistry(string $kunci): void
    {
        $d         = new UjiPendidikan();
        $d->mentah = [$kunci];

        $this->expectException(LogicException::class);

        new RiwayatRegistry(static fn () => service('pegawaiScope'), [$d]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function kirim(string $metode, bool $json, array $data, ?int $id = null): TestResponse
    {
        $path = "api/v1/pegawai/{$this->nip}/riwayat/pendidikan" . ($id === null ? '' : "/{$id}");
        $req  = $this->asUser($this->admin);

        if ($json) {
            $req = $req->withBodyFormat('json');
        }

        return $req->{$metode}($path, $data);
    }

    /**
     * Data terakhir yang diterima hook $nama Definisi uji.
     *
     * @return array<string, mixed>
     */
    private function hook(string $nama): array
    {
        $hasil = [];

        foreach ($this->d->panggilanHook as [$hook, $data]) {
            if ($hook === $nama) {
                $hasil = $data;
            }
        }

        return $hasil;
    }
}
