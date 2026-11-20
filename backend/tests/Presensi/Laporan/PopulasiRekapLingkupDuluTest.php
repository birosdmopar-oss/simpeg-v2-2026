<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Constants\Role;
use App\Libraries\Presensi\Laporan\LingkupLaporan;
use App\Libraries\Presensi\Laporan\PopulasiRekap;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;
use Tests\Support\Ukur\PenghitungKueri;

/**
 * CR-165 — populasi rekap menerapkan lingkup pada jabatan berlaku SEBELUM memuat pangkat, dan rekap uang makan hanya
 * membentuk baris halaman yang diminta. Nilai keluarannya dikunci test pagar `tests/Paritas/Step3`
 * (`PopulasiRekapParitasTest`, `RekapUangMakanParitasTest`); di sini kueri yang dijalankan dan paginasi menyeluruh.
 * Data sintetis, September 2026, jam uji 27-10-2026.
 *
 * @internal
 */
final class PopulasiRekapLingkupDuluTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use TukinDataUjiTrait;

    private int $satkerA;

    private int $satkerB;

    /** @var list<string> */
    private array $nipA = [];

    /** @var list<string> */
    private array $nipB = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->siapkanJenisPegawai();
        $this->satkerA = $this->buatSatker($this->buatUnit());
        $this->satkerB = $this->buatSatker($this->buatUnit());

        foreach ([1, 2, 3] as $i) {
            $a            = $this->pegawaiRekap($this->satkerA, ['nip' => "19990101202601280{$i}"]);
            $b            = $this->pegawaiRekap($this->satkerB, ['nip' => "19990101202601281{$i}"]);
            $this->nipA[] = $a;
            $this->nipB[] = $b;

            foreach ([$a, $b] as $nip) {
                $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'gol_ruang' => 'III/' . ['a', 'b', 'c'][$i - 1], 'tmtsk' => "202{$i}-04-01", 'status' => 1]);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    public function testPangkatHanyaDimuatUntukNipLolosLingkup(): void
    {
        $pop     = new PopulasiRekap($this->db);
        $semua   = $pop->dh('2026-09-01', '2026-09-30', new LingkupLaporan(LingkupLaporan::KEMENTERIAN, null, 'Kementerian'), [1]);
        $kueri   = [];
        $satkerA = $this->rekam($kueri, fn (): array => $pop->dh('2026-09-01', '2026-09-30', new LingkupLaporan(LingkupLaporan::SATKER, $this->satkerA, 'Satker A'), [1]));

        // Hasil lingkup satker = hasil kementerian yang disaring, urutan relatif sama (pangkat menentukan urutan).
        $this->assertSame(
            array_values(array_filter($semua, fn (array $p): bool => $p['id_satker'] === $this->satkerA)),
            $satkerA,
        );
        $this->assertSame(array_reverse($this->nipA), array_column($satkerA, 'nip'), 'gol_ruang turun: III/c, III/b, III/a');

        $kp = $this->menyebut($kueri, 'riwayat_kp');
        $this->assertCount(1, $kp);

        foreach ($this->nipA as $nip) {
            $this->assertStringContainsString($nip, $kp[0]);
        }

        foreach ($this->nipB as $nip) {
            $this->assertStringNotContainsString($nip, $kp[0], 'NIP di luar lingkup tidak dimuat pangkatnya');
        }
    }

    public function testLingkupTanpaAnggotaTidakMemuatPangkat(): void
    {
        $kosong = $this->buatSatker($this->buatUnit());
        $kueri  = [];
        $hasil  = $this->rekam($kueri, fn (): array => (new PopulasiRekap($this->db))->dh('2026-09-01', '2026-09-30', new LingkupLaporan(LingkupLaporan::SATKER, $kosong, 'Kosong'), [1]));

        $this->assertSame([], $hasil);
        $this->assertSame([], $this->menyebut($kueri, 'riwayat_kp'));
    }

    public function testInstansiLainTetapMemuatPangkatSemuaNip(): void
    {
        $il = $this->buatPegawai(['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 2, 'nip' => '199901012026012899'], ['jenis_jabatan' => 2]);
        $this->buatRiwayatMutasiJabatan($il, ['jenis_jabatan' => 2]);
        $kueri = [];
        $hasil = $this->rekam($kueri, fn (): array => (new PopulasiRekap($this->db))->il('2026-09-01'));

        $this->assertSame([$il], array_column($hasil, 'nip'));
        $kp = $this->menyebut($kueri, 'riwayat_kp');
        $this->assertCount(1, $kp);
        $this->assertStringContainsString($il, $kp[0]);
    }

    public function testSemuaHalamanUangMakanSamaDenganBarisUtuh(): void
    {
        // Jumlah hari berbeda per pegawai (termasuk nol) supaya batas halaman jatuh di tengah, di awal, dan di akhir pegawai.
        $hari = ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-07'];

        foreach ([...$this->nipA, ...$this->nipB] as $i => $nip) {
            foreach (array_slice($hari, 0, [3, 0, 5, 1, 2, 4][$i]) as $t) {
                $this->hadir($nip, $t);
            }
        }

        $svc   = $this->serviceUangMakan();
        $auth  = $this->adminRekap(Role::SUPER_ADMIN);
        $utuh  = $svc->daftar($auth, ['bulan' => '2026-09', 'per_page' => '100']);
        $baris = $utuh['items'];
        $this->assertSame(15, $utuh['total']);
        $this->assertCount(15, $baris);

        foreach ([1, 2, 3, 4, 5, 7, 15, 16] as $perPage) {
            $gabung = [];

            for ($page = 1; $page <= intdiv(15, $perPage) + 2; $page++) {
                $d = $svc->daftar($auth, ['bulan' => '2026-09', 'per_page' => (string) $perPage, 'page' => (string) $page]);
                $this->assertSame(15, $d['total'], "total per_page {$perPage} halaman {$page}");
                $this->assertSame(array_slice($baris, ($page - 1) * $perPage, $perPage), $d['items'], "per_page {$perPage} halaman {$page}");
                $gabung = [...$gabung, ...$d['items']];
            }

            $this->assertSame($baris, $gabung, "per_page {$perPage}");
        }
    }

    /**
     * @template T
     *
     * @param list<string>  $kueri diisi teks kueri data selama `$aksi`
     * @param callable(): T $aksi
     *
     * @return T
     */
    private function rekam(array &$kueri, callable $aksi): mixed
    {
        $hitung = new PenghitungKueri();
        $hitung->mulai();

        try {
            return $aksi();
        } finally {
            $hitung->selesai();
            $kueri = array_values(array_filter($hitung->kueri(), static fn (string $sql): bool => ! PenghitungKueri::apakahMetadata($sql)));
        }
    }

    /**
     * @param list<string> $kueri
     *
     * @return list<string>
     */
    private function menyebut(array $kueri, string $tabel): array
    {
        return array_values(array_filter($kueri, fn (string $sql): bool => str_contains($sql, $this->db->prefixTable($tabel))));
    }
}
