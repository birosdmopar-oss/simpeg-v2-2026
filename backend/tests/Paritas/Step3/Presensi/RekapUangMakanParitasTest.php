<?php

declare(strict_types=1);

namespace Tests\Paritas\Step3\Presensi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * CR-164 butir 5 (pagar paritas step 3, test karakterisasi): rekap uang makan — `total` = jumlah baris `(nip, tgl_absen)`,
 * urutan populasi lalu tanggal, halaman terakhir parsial & halaman di luar rentang, serta isi JSON (`daftar`) = isi XLSX
 * (`xlsx`) untuk role 1 (kementerian), role 3 (satker), dan varian IL. Mengunci perilaku SEKARANG; tidak memperbaiki apa
 * pun. Data sintetis, NIP sintetis. September 2026, jam uji 27-10-2026.
 *
 * @internal
 */
final class RekapUangMakanParitasTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use TukinDataUjiTrait;

    private const BULAN = ['bulan' => '2026-09'];

    private int $satkerA;

    private int $satkerB;

    /** @var array{a1: string, a2: string, b1: string, il: string} */
    private array $nip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->siapkanJenisPegawai();
        $this->satkerA = $this->buatSatker($this->buatUnit());
        $this->satkerB = $this->buatSatker($this->buatUnit());

        // NIP sintetis sengaja disisip tidak berurutan untuk mengamati urutan populasi.
        $a2 = $this->pegawaiRekap($this->satkerA, ['nip' => '199901012026011903']);
        $b1 = $this->pegawaiRekap($this->satkerB, ['nip' => '199901012026011901']);
        $a1 = $this->pegawaiRekap($this->satkerA, ['nip' => '199901012026011902']);
        $il = $this->buatPegawai(['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 2, 'nip' => '199901012026011904'], ['jenis_jabatan' => 2]);
        $this->buatRiwayatMutasiJabatan($il, ['jenis_jabatan' => 2]);

        foreach (['2026-09-03', '2026-09-01', '2026-09-02'] as $t) {
            $this->hadir($a1, $t);
        }

        $this->hadir($a2, '2026-09-04');
        $this->hadir($b1, '2026-09-07');
        $this->hadir($b1, '2026-09-01');
        $this->hadir($il, '2026-09-08');
        $this->hadir($il, '2026-09-02');

        $this->nip = ['a1' => $a1, 'a2' => $a2, 'b1' => $b1, 'il' => $il];
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    public function testTotalSamaDenganJumlahBarisNipTanggalDanUrutan(): void
    {
        $data = $this->serviceUangMakan()->daftar($this->adminRekap(), [...self::BULAN, 'per_page' => '100']);

        $pasangan = array_map(static fn (array $b): string => $b['nip'] . '|' . $b['tgl_absen'], $data['items']);
        $this->assertSame(count($data['items']), $data['total'], 'total = jumlah baris');
        $this->assertSame(count(array_unique($pasangan)), $data['total'], 'total = jumlah pasangan (nip, tgl_absen) unik');

        // perilaku sekarang (dikunci CR-164): urut populasi PopulasiRekap.php:106 (kelas jabatan turun, golongan turun, TMT
        // naik, id_satker naik, NIP naik) — bukan NIP murni: b1 (NIP terkecil, satker B) di belakang — lalu tanggal naik; IL
        // tidak ikut di rekap DH.
        $this->assertSame([
            [$this->nip['a1'], '2026-09-01'], [$this->nip['a1'], '2026-09-02'], [$this->nip['a1'], '2026-09-03'],
            [$this->nip['a2'], '2026-09-04'],
            [$this->nip['b1'], '2026-09-01'], [$this->nip['b1'], '2026-09-07'],
        ], array_map(static fn (array $b): array => [$b['nip'], $b['tgl_absen']], $data['items']));
        $this->assertSame([1, 100, '2026-09'], [$data['page'], $data['per_page'], $data['bulan']]);
    }

    public function testHalamanTerakhirDanDiLuarRentang(): void
    {
        $svc = $this->serviceUangMakan();

        $akhir = $svc->daftar($this->adminRekap(), [...self::BULAN, 'per_page' => '4', 'page' => '2']);
        $this->assertSame([6, 2, 4], [$akhir['total'], $akhir['page'], $akhir['per_page']]);
        $this->assertSame([[$this->nip['b1'], '2026-09-01'], [$this->nip['b1'], '2026-09-07']], array_map(static fn (array $b): array => [$b['nip'], $b['tgl_absen']], $akhir['items']), 'halaman terakhir parsial');

        // perilaku sekarang (dikunci CR-164): RekapUangMakanService::daftar (array_slice) — halaman di luar rentang → 200 dengan items kosong, total & page apa adanya.
        $luar = $svc->daftar($this->adminRekap(), [...self::BULAN, 'per_page' => '4', 'page' => '3']);
        $this->assertSame([[], 6, 3, 4], [$luar['items'], $luar['total'], $luar['page'], $luar['per_page']]);
    }

    /**
     * R4: halaman yang memotong baris satu pegawai (urutan penuh: a1×3, a2×1, b1×2).
     */
    public function testPaginasiMemotongBarisSatuPegawai(): void
    {
        $hal = fn (int $per, int $page): array => $this->halaman($per, $page);

        $this->assertSame([6, [[$this->nip['a1'], '2026-09-03'], [$this->nip['a2'], '2026-09-04']]], $hal(2, 2), 'per_page 2 page 2: sisa a1 + a2');
        $this->assertSame([6, [[$this->nip['b1'], '2026-09-07']]], $hal(5, 2), 'per_page 5 page 2: baris kedua b1 saja');
        $this->assertSame([6, [[$this->nip['a1'], '2026-09-03']]], $hal(1, 3), 'per_page 1 halaman tengah: baris ketiga a1');
        $this->assertSame([6, [[$this->nip['b1'], '2026-09-01']]], $hal(1, 5), 'per_page 1 page 5: baris pertama b1');

        $gabung = [];

        foreach ([1, 2, 3] as $p) {
            $gabung = [...$gabung, ...$hal(2, $p)[1]];
        }

        $this->assertSame($hal(100, 1)[1], $gabung, 'gabungan halaman per_page 2 = daftar penuh, tanpa baris hilang/ganda');
    }

    public function testJsonSamaDenganXlsxRoleKementerian(): void
    {
        $this->assertJsonSamaXlsx($this->adminRekap(), [], false, 'rekap_uang_makan_0_2026-09.xlsx', 6);
    }

    public function testJsonSamaDenganXlsxRole3(): void
    {
        $auth = $this->adminRekap(Role::ADMIN_SATKER, (string) $this->satkerA);
        $this->assertJsonSamaXlsx($auth, ['unit_satker' => '0'], false, null, 4);

        $items = $this->serviceUangMakan()->daftar($auth, [...self::BULAN, 'unit_satker' => '0', 'per_page' => '100'])['items'];
        $this->assertSame([$this->nip['a1'], $this->nip['a2']], array_values(array_unique(array_column($items, 'nip'))), 'role 3 hanya satker sendiri');
    }

    public function testJsonSamaDenganXlsxIl(): void
    {
        $this->assertJsonSamaXlsx($this->adminRekap(), [], true, 'rekap_uang_makan_il_2026-09.xlsx', 2);
        $items = $this->serviceUangMakan()->daftar($this->adminRekap(), [...self::BULAN, 'per_page' => '100'], true)['items'];
        $this->assertSame([[$this->nip['il'], '2026-09-02'], [$this->nip['il'], '2026-09-08']], array_map(static fn (array $b): array => [$b['nip'], $b['tgl_absen']], $items));
    }

    /**
     * @return array{0: int, 1: list<array{0: string, 1: string}>}
     */
    private function halaman(int $perPage, int $page): array
    {
        $d = $this->serviceUangMakan()->daftar($this->adminRekap(), [...self::BULAN, 'per_page' => (string) $perPage, 'page' => (string) $page]);

        return [$d['total'], array_map(static fn (array $b): array => [$b['nip'], $b['tgl_absen']], $d['items'])];
    }

    /**
     * @param array<string, string> $tambahan
     */
    private function assertJsonSamaXlsx(AuthContext $auth, array $tambahan, bool $il, ?string $nama, int $total): void
    {
        $svc  = $this->serviceUangMakan();
        $json = $svc->daftar($auth, [...self::BULAN, ...$tambahan, 'per_page' => '100'], $il);
        $file = $svc->xlsx($auth, [...self::BULAN, ...$tambahan], $il);

        $this->assertSame($total, $json['total']);

        if ($nama !== null) {
            $this->assertSame($nama, $file['nama']);
        }

        $baris = $this->baca($file['isi']);
        $this->assertSame(['NIP', 'TGL_ABSEN'], array_shift($baris), 'header XLSX');
        $this->assertSame(array_map(static fn (array $b): array => [$b['nip'], $b['tgl_absen']], $json['items']), $baris, 'isi XLSX = isi JSON, urutan sama');
    }

    /**
     * @return list<list<mixed>>
     */
    private function baca(string $isi): array
    {
        $berkas = (string) tempnam(sys_get_temp_dir(), 'rump');
        file_put_contents($berkas, $isi);

        try {
            return IOFactory::load($berkas)->getActiveSheet()->toArray(null, false, false, false);
        } finally {
            @unlink($berkas);
        }
    }
}
