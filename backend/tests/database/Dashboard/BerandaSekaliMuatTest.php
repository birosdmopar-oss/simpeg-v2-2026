<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\CacheService;
use App\Libraries\Dashboard\Beranda\BawahanPegawai;
use App\Libraries\Dashboard\Beranda\KelengkapanData;
use App\Libraries\Dashboard\Statistik\StatistikCache;
use App\Libraries\Dashboard\Statistik\StatistikService;
use App\Libraries\Kepegawaian\AngkaKredit\AkPermenpanrb;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestLogger;
use Config\Services;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\Dashboard\StatistikUjiTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\FakeStorageAdapter;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Paritas\Kepegawaian\CacheTtlSpy;
use Tests\Support\Ukur\PenghitungKueri;

/**
 * CR-166 — data beranda dan statistik dimuat sekali, bukan per baris:
 *
 * - AK bawahan JF: `AkPermenpanrb::hitungBanyak()` sama persis dengan `hitung()` per NIP, dan jumlah kueri
 *   `BawahanPegawai::untuk()` tidak bertambah mengikuti jumlah bawahan JF;
 * - kartu pendidikan: satu kueri `riwayat_pendidikan`, berapa pun jenjang pada aturannya;
 * - statistik: satu baca cache per permintaan (dulu `has()` lalu `remember()`).
 *
 * Nilai keluarannya sendiri dikunci test pagar `tests/Paritas/Step3/Kepegawaian`. Data sintetis.
 *
 * @internal
 */
final class BerandaSekaliMuatTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use StatistikUjiTrait;

    private const HARI_INI = '2026-11-10';

    protected function tearDown(): void
    {
        Services::reset(true);
        parent::tearDown();
    }

    public function testHitungBanyakSamaDenganHitungPerNip(): void
    {
        [$satker, , $jab, $idJenjang] = $this->siapkanAtasanEs4();

        $biasa        = $this->jf($satker, $jab, '199001012020012101', 'JF Biasa', $idJenjang, 20);
        $tanpaJenjang = $this->jf($satker, $jab, '199001012020012102', 'JF Tanpa Jenjang', null, 35);
        $negatif      = $this->jf($satker, $jab, '199001012020012103', 'JF Negatif', $idJenjang, 0);
        $bergelar     = $this->jf($satker, $jab, '199001012020012104', 'JF Bergelar', $idJenjang, 0);
        $tanpaJabatan = $this->jf($satker, $jab, '199001012020012105', 'JF Tanpa Jabatan', null, 0);
        $this->db->table('pegawai')->where('nip', $bergelar)->update(['glr_awal' => 'Dr.', 'glr_akhir' => 'M.Si.']);
        $jabBiasa = $this->idJabatan($biasa);
        // Jumlah negatif dipotong 0; baris jabatan lain dan baris tanpa konversi/integrasi tidak ikut.
        $this->siasn($negatif, $this->idJabatan($negatif), -10, '1', null);
        $this->siasn($negatif, $jabBiasa, 40, '1', null);
        $this->siasn($biasa, $jabBiasa, 7.125, '0', '1');
        $this->siasn($biasa, $jabBiasa, 99, '0', '0');
        $this->siasn($bergelar, $this->idJabatan($bergelar), 60, null, '1');
        // Pegawai tanpa jabatan di snapshot tetapi punya baris SIASN ber-jabatan: AK sekarang tetap 0.
        $this->siasn($tanpaJabatan, $jabBiasa, 12, '1', null);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $tanpaJabatan)->update(['id_jabatan' => null]);

        $nips  = [$biasa, $tanpaJenjang, $negatif, $bergelar, $tanpaJabatan, '000000000000000000'];
        $ak    = new AkPermenpanrb($this->db);
        $hasil = $ak->hitungBanyak([...$nips, $biasa]);

        $this->assertCount(count($nips), $hasil, 'NIP kembar di masukan tidak menggandakan hasil');

        foreach ($nips as $nip) {
            $this->assertSame($ak->hitung($nip), $hasil[$nip], "NIP {$nip}");
        }

        $this->assertSame(27.125, $hasil[$biasa]['current_ak']);
        $this->assertSame(0.0, $hasil[$negatif]['current_ak']);
        $this->assertSame(0.0, $hasil[$tanpaJabatan]['current_ak']);
        $this->assertSame('', $hasil['000000000000000000']['nip'], 'NIP tidak dikenal: bentuk kosong yang sama dengan hitung()');
        $this->assertSame([], $ak->hitungBanyak([]));
    }

    /**
     * Collation `nip` PAD SPACE: baris AK impor yang NIP-nya berspasi di ujung dihitung `hitung()` (`WHERE nip = ?`), jadi
     * jalur banyak NIP harus menghitungnya juga.
     */
    public function testNipBerspasiDiUjungSamaDiJalurTunggalDanBanyak(): void
    {
        [$satker, , $jab, $idJenjang] = $this->siapkanAtasanEs4();
        // Semua baris AK pegawai ini ber-NIP dengan spasi di ujung (tidak ada baris ber-NIP bersih), jadi wakil kelompok
        // `GROUP BY nip` pasti NIP ber-spasi.
        $nip  = $this->jf($satker, $jab, '199001012020012301', 'JF Spasi', $idJenjang, 0);
        $lain = $this->jf($satker, $jab, '199001012020012302', 'JF Lain', $idJenjang, 4);
        $this->siasn($nip . ' ', $this->idJabatan($nip), 5.5, '1', null);
        $this->siasn($nip . '  ', $this->idJabatan($nip), 2, null, '1');

        $ak    = new AkPermenpanrb($this->db);
        $hasil = $ak->hitungBanyak([$nip, $lain]);

        $this->assertSame(7.5, $ak->hitung($nip)['current_ak'], 'jalur tunggal menghitung baris ber-spasi');
        $this->assertSame($ak->hitung($nip), $hasil[$nip]);
        $this->assertSame($ak->hitung($lain), $hasil[$lain]);
        $this->assertSame(4.0, $hasil[$lain]['current_ak']);
    }

    /**
     * Galat dari perhitungan statistik diteruskan apa adanya: dihitung SEKALI (dulu tertangkap di dalam `remember()`,
     * dicatat sebagai "cache statistik … gagal", lalu dihitung lagi) dan tanpa warning cache.
     */
    public function testGalatHitungStatistikDiteruskanSekaliTanpaWarningCache(): void
    {
        $spy            = CacheTtlSpy::baru();
        $this->cacheUji = new CacheService($spy, '');
        Services::injectMock('cacheService', $this->cacheUji);
        Services::injectMock('pegawaiScope', new PegawaiScope());
        $this->siapkanMasterStatistik();
        $this->pegawaiStatistik('A1', ['jenis' => 1]);

        $scope = new class () implements PegawaiScopeInterface {
            public int $dipanggil = 0;

            public function bolehLihat(AuthContext $auth, string $nip): bool
            {
                return true;
            }

            public function bolehUbah(AuthContext $auth, string $nip): bool
            {
                return true;
            }

            public function bolehProses(AuthContext $auth, string $nip): bool
            {
                return true;
            }

            public function terapkanKeQuery(BaseBuilder $builder, AuthContext $auth, string $kolomNip = 'nip'): void
            {
                $this->dipanggil++;

                throw new RuntimeException('hitung uji gagal');
            }
        };
        $auth = new AuthContext();
        $auth->setClaims(['sub' => '900001', 'role' => Role::SUPER_ADMIN]);

        // Log TestLogger statis dan terkumpul lintas test dalam satu proses: yang diperiksa hanya yang ditulis sesudah titik ini.
        $logSebelum = count($this->logUji());

        try {
            (new StatistikService($this->db, $scope, $this->cacheUji))->statistik($auth, StatistikService::UNIT_KERJA, []);
            $this->fail('galat hitung harus diteruskan');
        } catch (RuntimeException $e) {
            $this->assertSame('hitung uji gagal', $e->getMessage());
        }

        $this->assertSame(1, $scope->dipanggil, 'perhitungan dijalankan tepat satu kali');
        $logBaru = array_slice($this->logUji(), $logSebelum);
        $this->assertSame([], array_values(array_filter(
            $logBaru,
            static fn (array $l): bool => strtolower((string) $l['level']) === 'warning' && str_contains((string) $l['message'], 'cache statistik'),
        )), 'tanpa warning "cache statistik … gagal" selama permintaan ini');
        $this->assertSame([], array_values(array_filter($spy->simpan, static fn (array $s): bool => str_starts_with($s['key'], StatistikCache::PREFIKS))), 'tidak ada yang disimpan');
    }

    public function testJumlahKueriBawahanTidakMengikutiJumlahJf(): void
    {
        [$satker, $atasan, $jab, $idJenjang] = $this->siapkanAtasanEs4();
        $this->jf($satker, $jab, '199001012020012201', 'JF Satu', $idJenjang, 10);

        $satu = $this->kueri(fn () => (new BawahanPegawai($this->db, self::HARI_INI))->untuk($atasan));

        for ($i = 2; $i <= 6; $i++) {
            $this->jf($satker, $jab, "19900101202001220{$i}", "JF {$i}", $idJenjang, 10 * $i);
        }

        $hasil = null;
        $enam  = $this->kueri(function () use ($atasan, &$hasil): void {
            $hasil = (new BawahanPegawai($this->db, self::HARI_INI))->untuk($atasan);
        });

        $this->assertCount(6, $hasil['ak']);
        $this->assertCount(count($satu), $enam, "kueri tidak bertambah per bawahan JF:\n" . implode("\n", $enam));
        // snapshot atasan, daftar bawahan, dh_online, lalu AK: pegawai gabungan + SUM riwayat_ak_siasn.
        $this->assertCount(5, $enam);
    }

    public function testKartuPendidikanSatuKueriRiwayat(): void
    {
        if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', 1)->countAllResults() === 0) {
            $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS', 'status' => 1]);
        }

        $jenjang = [];

        foreach (['SD', 'SLTP', 'SLTA', 'D.IV', 'S.1', 'S.2'] as $i => $singkat) {
            $ada = $this->db->table('jenjang_pendidikan')->where('jenjang_pendidikan_singkat', $singkat)->where('status', 1)->get()->getRowArray();

            if ($ada === null) {
                $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => $singkat, 'jenjang_pendidikan' => "Jenjang {$singkat}", 'order' => 90 + $i, 'status' => 1]);
                $jenjang[$singkat] = (int) $this->db->insertID();
            } else {
                $jenjang[$singkat] = (int) $ada['id_jenjang_pendidikan'];
            }
        }

        $nip = $this->buatPegawaiDiSatker($this->buatSatker(), ['id_jenis_pegawai' => 1, 'tgl_lahir' => '1990-01-01']);
        // Snapshot S.2 → aturan lima jenjang (SD, SLTP, SLTA, S.1, S.2). Dimiliki: SD, SLTA dua kali, D.IV (pengganti S.1);
        // S.2 berstatus ditolak tidak dihitung → 3 dari 5.
        $this->db->table('pegawai_pendidikan')->insert(['nip' => $nip, 'id_jenjang_pendidikan' => $jenjang['S.2'], 'jenjang_pendidikan_singkat' => 'S.2', 'tgl_lulus' => '2015-08-01']);

        foreach ([['SD', 1], ['SLTA', 1], ['SLTA', 1], ['D.IV', 0], ['S.2', 2]] as [$singkat, $status]) {
            $this->db->table('riwayat_pendidikan')->insert(['nip' => $nip, 'id_jenjang_pendidikan' => $jenjang[$singkat], 'tgl_lulus' => '2010-01-01', 'status' => $status]);
        }

        $kartu = [];
        $kueri = $this->kueri(function () use ($nip, &$kartu): void {
            $kartu = (new KelengkapanData($this->db, self::HARI_INI, new FakeStorageAdapter()))->kartu(Role::PEGAWAI, $nip);
        });

        $this->assertSame(60, array_column($kartu, 'persen', 'kode')['pendidikan'] ?? null);
        $riwayat = array_values(array_filter($kueri, static fn (string $sql): bool => str_contains($sql, 'riwayat_pendidikan')));
        $this->assertCount(1, $riwayat, implode("\n", $riwayat));
    }

    public function testStatistikSatuBacaCachePerPermintaan(): void
    {
        $cache = new class (CacheTtlSpy::baru(), '') extends CacheService {
            /** Baca cache khusus kunci statistik (cache lain, mis. `web_config_values`, diabaikan). */
            public int $baca = 0;

            public function get(string $key): mixed
            {
                $this->baca += (int) str_starts_with($key, StatistikCache::PREFIKS);

                return parent::get($key);
            }

            public function has(string $key): bool
            {
                $this->baca += (int) str_starts_with($key, StatistikCache::PREFIKS);

                return parent::has($key);
            }

            public function remember(string $key, int $ttl, Closure $callback): mixed
            {
                $this->baca += (int) str_starts_with($key, StatistikCache::PREFIKS);

                return parent::remember($key, $ttl, $callback);
            }
        };
        $this->cacheUji = $cache;
        Services::injectMock('cacheService', $cache);
        Services::injectMock('pegawaiScope', new PegawaiScope());
        $this->siapkanMasterStatistik();
        $this->pegawaiStatistik('A1', ['jenis' => 1]);

        $auth = new AuthContext();
        $auth->setClaims(['sub' => '900001', 'role' => Role::SUPER_ADMIN]);
        $svc = new StatistikService($this->db, new PegawaiScope(), $cache);

        $pertama = $svc->statistik($auth, StatistikService::UNIT_KERJA, []);
        $this->assertSame(1, $cache->baca, 'cache kosong: satu baca, lalu simpan');
        $kedua = $svc->statistik($auth, StatistikService::UNIT_KERJA, []);
        $this->assertSame(2, $cache->baca, 'cache terisi: satu baca');

        $this->assertFalse($pertama['cache']['hit']);
        $this->assertTrue($kedua['cache']['hit']);
        $this->assertSame($pertama['baris'], $kedua['baris']);
    }

    // ---- bantu --------------------------------------------------------------------------------------------------------

    /**
     * @return list<array{level: string, message: string, file: string}>
     */
    private function logUji(): array
    {
        return (new ReflectionProperty(TestLogger::class, 'op_logs'))->getValue();
    }

    /**
     * Teks kueri data (tanpa metadata) selama `$aksi`.
     *
     * @return list<string>
     */
    private function kueri(callable $aksi): array
    {
        $hitung = new PenghitungKueri();
        $hitung->mulai();

        try {
            $aksi();
        } finally {
            $hitung->selesai();
        }

        return array_values(array_filter($hitung->kueri(), static fn (string $sql): bool => ! PenghitungKueri::apakahMetadata($sql)));
    }

    /**
     * @return array{0: int, 1: string, 2: int, 3: int} satker, NIP atasan, id_jabatan atasan, id_jenjang_jf
     */
    private function siapkanAtasanEs4(): array
    {
        foreach ([1 => 'Struktural', 2 => 'Fungsional'] as $id => $nama) {
            if ($this->db->table('group_jabatan')->where('id_group_jabatan', $id)->countAllResults() === 0) {
                $this->db->table('group_jabatan')->insert(['id_group_jabatan' => $id, 'group_jabatan' => $nama, 'status' => 1]);
            }
        }

        if ($this->db->table('sub_group_jabatan')->where('id_sub_group_jabatan', 4)->countAllResults() === 0) {
            $this->db->table('sub_group_jabatan')->insert(['id_sub_group_jabatan' => 4, 'id_group_jabatan' => 1, 'sub_group_jabatan' => 'E.IV', 'status' => 1]);
        }

        $this->db->table('jenjang_jf')->insert(['kategori_jf' => 'Ahli', 'jenjang_jf' => 'Ahli Pertama', 'extra_nama_jab' => '', 'order' => 1, 'status' => 1]);
        $idJenjang = (int) $this->db->insertID();

        $satker = $this->buatSatker();
        $atasan = $this->buatPegawaiDiSatker($satker, ['nama' => 'Atasan']);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $atasan)->update(['id_group_jabatan' => 1, 'id_sub_group_jabatan' => 4]);

        return [$satker, $atasan, $this->idJabatan($atasan), $idJenjang];
    }

    private function jf(int $satker, int $jabAtasan, string $nip, string $nama, ?int $idJenjang, float $ak): string
    {
        $this->buatPegawaiDiSatker($satker, ['nip' => $nip, 'nama' => $nama]);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->update(['id_atasan_es_4' => $jabAtasan, 'id_group_jabatan' => 2]);

        if ($idJenjang !== null) {
            $this->db->table('jabatan')->where('id_jabatan', $this->idJabatan($nip))->update(['id_jenjang_jf' => $idJenjang]);
            $this->db->table('pegawai_kp')->insert([
                'nip' => $nip, 'tmtsk' => '2020-01-01', 'tgl_sk' => '2019-12-01', 'jenis_kp' => 'Reguler',
                'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda',
            ]);
        }

        if ($ak > 0) {
            $this->siasn($nip, $this->idJabatan($nip), $ak, '1', null);
        }

        return $nip;
    }

    private function siasn(string $nip, int $idJabatan, float $kredit, ?string $konversi, ?string $integrasi): void
    {
        $this->db->table('riwayat_ak_siasn')->insert([
            'nip'               => $nip, 'id_jabatan' => $idJabatan, 'id_rw_siasn' => 'rw-' . bin2hex(random_bytes(6)),
            'kredit_baru_total' => $kredit, 'is_konversi' => $konversi, 'is_integrasi' => $integrasi, 'status' => 1,
        ]);
    }

    private function idJabatan(string $nip): int
    {
        return (int) $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRow('id_jabatan');
    }
}
