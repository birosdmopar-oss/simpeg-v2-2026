<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F-11 SKL sisi pegawai (MAKE-084): `saya` hanya status 1 + rated 2 + milik aktor (urut jenis 1→5, id naik, teks info);
 * POST 201 + `rated = 1`, kirim ulang 200 `sudah_tercatat` tanpa ganda (Q7-21), riwayat orang lain/tidak ada 404,
 * status ≠ 1 dan rated 0 → 422 ber-kode, validasi 422, role selain 2/6 → 403 (Q7-23).
 *
 * @internal
 */
final class SurveiLayananPegawaiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->nip = $this->buatPegawai();
        $this->buatAkunUntuk($this->nip, Role::PEGAWAI);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testSayaHanyaDisetujuiBelumDinilaiMilikAktor(): void
    {
        $karpeg = $this->riwayat('riwayat_karpeg', ['status' => 1, 'rated' => 2, 'created_at' => '2026-11-09 18:00:00']);
        $cuti2  = $this->riwayat('riwayat_cuti', ['status' => 1, 'rated' => 2, 'tgl_mulai' => '2026-11-02', 'tgl_akhir' => '2026-11-03']);
        $cuti1  = $this->riwayat('riwayat_cuti', ['status' => 1, 'rated' => 2, 'tgl_mulai' => '2026-10-01', 'tgl_akhir' => '2026-10-02']);
        $this->riwayat('riwayat_cuti', ['status' => 0, 'rated' => 2]);
        $this->riwayat('riwayat_cuti', ['status' => 1, 'rated' => 1]);
        $this->riwayat('riwayat_cuti', ['status' => 1, 'rated' => 0]);
        $this->riwayat('riwayat_cuti', ['status' => 1, 'rated' => 2], $this->buatPegawai());
        $tb = $this->riwayat('riwayat_tb', ['status' => 1, 'rated' => 2, 'jenjang_pendidikan_singkat' => 'S2', 'institusi_pendidikan' => 'Universitas Uji', 'tgl_mulai' => '2026-09-01']);

        $data = $this->json($this->asNip($this->nip)->get('api/v1/survei-layanan/saya'))['data'];

        $this->assertSame([[1, min($cuti1, $cuti2)], [1, max($cuti1, $cuti2)], [3, $karpeg], [4, $tb]], array_map(static fn (array $r): array => [$r['jenis_rwy'], $r['id_rwy']], $data));
        $this->assertSame('KARPEG', $data[2]['jenis_layanan']);
        $this->assertStringContainsString('10 November 2026', $data[2]['info'], 'created_at UTC → tanggal WIB');
        $this->assertStringContainsString('Tugas Belajar S2 di Universitas Uji', $data[3]['info']);
    }

    public function testKirimTercatatLaluKirimUlangIdempoten(): void
    {
        $id = $this->riwayat('riwayat_karpeg', ['status' => 1, 'rated' => 2]);

        $hasil = $this->asNip($this->nip)->withBodyFormat('json')->post('api/v1/survei-layanan', ['jenis_rwy' => 3, 'id_rwy' => $id, 'rate' => 5, 'kritik_saran' => ' Cepat ']);
        $hasil->assertStatus(201);
        $this->assertSame('tercatat', $this->json($hasil)['data']['status']);
        $skl = $this->db->table('skl')->where('id_rwy', $id)->get()->getRowArray();
        $this->assertSame([3, 5, 'Cepat', $this->nip], [(int) $skl['jenis_rwy'], (int) $skl['rate'], $skl['kritik_saran'], $skl['nip']]);
        $this->assertSame(1, (int) $this->db->table('riwayat_karpeg')->where('id_riwayat_karpeg', $id)->get()->getRow('rated'));

        $ulang = $this->asNip($this->nip)->withBodyFormat('json')->post('api/v1/survei-layanan', ['jenis_rwy' => 3, 'id_rwy' => $id, 'rate' => 1]);
        $ulang->assertStatus(200);
        $this->assertSame('sudah_tercatat', $this->json($ulang)['data']['status']);
        $this->assertSame(1, $this->db->table('skl')->where('id_rwy', $id)->countAllResults());
        $this->assertSame(5, (int) $this->db->table('skl')->where('id_rwy', $id)->get()->getRow('rate'), 'nilai pertama tidak ditimpa');
    }

    public function testBarisSklSudahAdaTetapiRatedBelumDiperbaiki(): void
    {
        $id = $this->riwayat('riwayat_cuti', ['status' => 1, 'rated' => 2]);
        $this->db->table('skl')->insert(['nip' => $this->nip, 'jenis_rwy' => 1, 'id_rwy' => $id, 'rate' => 4]);

        $this->asNip($this->nip)->withBodyFormat('json')->post('api/v1/survei-layanan', ['jenis_rwy' => 1, 'id_rwy' => $id, 'rate' => 2])->assertStatus(200);

        $this->assertSame(1, (int) $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRow('rated'));
        $this->assertSame(1, $this->db->table('skl')->where('id_rwy', $id)->countAllResults());
    }

    public function testDitolak(): void
    {
        $lain    = $this->riwayat('riwayat_cuti', ['status' => 1, 'rated' => 2], $this->buatPegawai());
        $belum   = $this->riwayat('riwayat_cuti', ['status' => 0, 'rated' => 2]);
        $abaikan = $this->riwayat('riwayat_cuti', ['status' => 1, 'rated' => 0]);
        $kirim   = fn (array $b) => $this->asNip($this->nip)->withBodyFormat('json')->post('api/v1/survei-layanan', $b);

        $kirim(['jenis_rwy' => 1, 'id_rwy' => $lain, 'rate' => 5])->assertStatus(404);
        $kirim(['jenis_rwy' => 1, 'id_rwy' => 999999, 'rate' => 5])->assertStatus(404);
        $r = $kirim(['jenis_rwy' => 1, 'id_rwy' => $belum, 'rate' => 5]);
        $r->assertStatus(422);
        $this->assertSame('layanan_belum_selesai', $this->json($r)['code'] ?? null);
        $r = $kirim(['jenis_rwy' => 1, 'id_rwy' => $abaikan, 'rate' => 5]);
        $r->assertStatus(422);
        $this->assertSame('tidak_perlu_dinilai', $this->json($r)['code'] ?? null);
        $kirim(['jenis_rwy' => 6, 'id_rwy' => $belum, 'rate' => 5])->assertStatus(422);
        $kirim(['jenis_rwy' => 1, 'id_rwy' => $belum, 'rate' => 6])->assertStatus(422);
        $kirim(['jenis_rwy' => 1, 'id_rwy' => $belum, 'rate' => 3, 'kritik_saran' => str_repeat('a', 2001)])->assertStatus(422);
        $this->assertSame(0, $this->db->table('skl')->countAllResults());
    }

    public function testRoleSelain2Dan6Ditolak403(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::PPPK] as $role) {
            $nip = $this->buatPegawai();
            $this->buatAkunUntuk($nip, $role);
            $this->asNip($nip)->get('api/v1/survei-layanan/saya')->assertStatus(403);
            $this->asNip($nip)->withBodyFormat('json')->post('api/v1/survei-layanan', ['jenis_rwy' => 1, 'id_rwy' => 1, 'rate' => 5])->assertStatus(403);
        }
    }

    /**
     * @param array<string, mixed> $isi
     */
    private function riwayat(string $tabel, array $isi, ?string $nip = null): int
    {
        $kolom = $this->db->query(
            'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->getPrefix() . $tabel],
        )->getResultArray();
        $baris = ['nip' => $nip ?? $this->nip] + $isi;

        foreach ($kolom as $k) {
            $nama = (string) $k['COLUMN_NAME'];

            if (array_key_exists($nama, $baris) || $k['IS_NULLABLE'] === 'YES' || $k['COLUMN_DEFAULT'] !== null || str_contains((string) $k['EXTRA'], 'auto_increment')) {
                continue;
            }

            $baris[$nama] = match ((string) $k['DATA_TYPE']) {
                'date'                                                                            => '2026-01-01',
                'datetime', 'timestamp'                                                           => '2026-01-01 00:00:00',
                'year'                                                                            => 2026,
                'int', 'tinyint', 'smallint', 'bigint', 'mediumint', 'decimal', 'double', 'float' => 1,
                default                                                                           => substr('Uji ' . $nama, 0, max(1, (int) ($k['CHARACTER_MAXIMUM_LENGTH'] ?? 10))),
            };
        }

        $this->db->table($tabel)->insert($baris);

        return (int) $this->db->insertID();
    }
}
