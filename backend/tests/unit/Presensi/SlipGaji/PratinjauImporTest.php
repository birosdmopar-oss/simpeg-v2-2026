<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Libraries\Presensi\SlipGaji\BarisSheet;
use App\Libraries\Presensi\SlipGaji\KolomGaji;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use App\Libraries\Presensi\SlipGaji\PratinjauImpor;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * CR-027 (I-1..I-13, M-1..M-6, D-10 DoD "mismatch di-log", "duplikat ditolak saat preview") — pratinjau impor tanpa
 * I/O dari sel mentah sheet GPP/TK. Pegawai, NIP, satker, dan nominal sintetis.
 *
 * @internal
 */
final class PratinjauImporTest extends CIUnitTestCase
{
    private const NIP_A = '199001012020011001';

    private const NIP_B = '199001012020011002';

    private const NIP_PENDEK = '19900101202001101';

    private const NIP_ASING = '199001012020011009';

    /**
     * @var array<int|string, array{nama: string, id_satker: ?string}>
     */
    private const PEGAWAI = [
        self::NIP_A      => ['nama' => 'Pegawai Contoh', 'id_satker' => 'S1'],
        self::NIP_B      => ['nama' => 'Pegawai Sintetis', 'id_satker' => 'S2'],
        self::NIP_PENDEK => ['nama' => 'Pegawai Fiktif', 'id_satker' => 'S1'],
    ];

    /**
     * Sheet mentah: header (baris 1) + baris data bernomor (bawaan mulai 2). Kolom nominal yang tidak disebut kosong,
     * kecuali kolom wajib terisi yang diberi nominal sintetis.
     *
     * @param array<int, array<string, mixed>> $data nomor baris => kolom Excel (huruf kecil) => nilai mentah
     *
     * @return array<int, list<mixed>>
     */
    private static function sheet(string $sheet, array $data, bool $denganNama = false): array
    {
        $kolom = [...KolomGaji::KUNCI, ...array_keys(KolomGaji::sumber($sheet))];

        if ($denganNama) {
            $kolom[] = 'nama';
        }

        $bawaan = $sheet === 'GPP'
            ? ['gjpokok' => 3_000_000, 'bersih' => 3_000_000]
            : ['kotor' => 4_000_000, 'bersih' => 3_800_000];

        $baris = [1 => array_map('strtoupper', $kolom)];

        foreach ($data as $nomor => $isi) {
            $isi = array_merge($bawaan, $isi);

            $baris[$nomor] = array_map(static fn (string $k): mixed => $isi[$k] ?? null, $kolom);
        }

        return $baris;
    }

    /**
     * @param array<int, array<string, mixed>> $data
     *
     * @return list<BarisSheet>
     */
    private static function urai(string $sheet, array $data, bool $denganNama = false): array
    {
        $hasil = PratinjauImpor::uraiSheet(self::sheet($sheet, $data, $denganNama), $sheet);

        self::assertTrue($hasil->valid, (string) $hasil->pesan);
        self::assertIsArray($hasil->nilai);

        return $hasil->nilai['baris'];
    }

    /**
     * @param array<string, mixed> $tambahan
     *
     * @return array<string, mixed>
     */
    private static function kunci(string $nip, mixed $bulan = 10, mixed $tahun = 2026, array $tambahan = []): array
    {
        return ['nip' => $nip, 'bulan' => $bulan, 'tahun' => $tahun, ...$tambahan];
    }

    /**
     * @param list<BarisSheet>    $gpp
     * @param list<BarisSheet>    $tk
     * @param array<string, true> $sudahAda
     *
     * @return array<string, mixed>
     */
    private static function susun(array $gpp, array $tk, array $sudahAda = [], ?PeriodeSlip $filter = null, ?string $cakupan = null): array
    {
        $hasil = PratinjauImpor::susun($gpp, $tk, self::PEGAWAI, $sudahAda, $filter, $cakupan);

        self::assertTrue($hasil->valid, (string) $hasil->pesan);
        self::assertIsArray($hasil->nilai);

        return $hasil->nilai;
    }

    /**
     * @param array<string, mixed> $pratinjau
     *
     * @return list<string>
     */
    private static function kodeGalat(array $pratinjau, int $indeks = 0): array
    {
        return array_column($pratinjau['baris'][$indeks]['galat'], 'kode');
    }

    public function testUraiSheetHanyaHeaderKosong(): void
    {
        foreach ([self::sheet('GPP', []), [], self::sheet('GPP', [2 => ['gjpokok' => null, 'bersih' => '  ']])] as $sheet) {
            $hasil = PratinjauImpor::uraiSheet($sheet, 'GPP');

            $this->assertFalse($hasil->valid);
            $this->assertSame('sheet_kosong', $hasil->kode);
        }
    }

    public function testUraiSheetMeneruskanGalatHeader(): void
    {
        $sheet    = self::sheet('TK', [2 => self::kunci(self::NIP_A)]);
        $sheet[1] = array_map(static fn (mixed $h): mixed => $h === 'PAJAK' ? 'PPH' : $h, $sheet[1]);

        $hasil = PratinjauImpor::uraiSheet($sheet, 'TK');

        $this->assertSame('kolom_tidak_ada', $hasil->kode);
    }

    public function testUraiSheetBarisTanpaNipDanNomorBaris(): void
    {
        $sheet = self::sheet('GPP', [
            2 => self::kunci(self::NIP_A),
            3 => ['nip' => '', 'gjpokok' => 9_000_000, 'bersih' => 9_000_000],
            4 => ['nip' => null, 'bulan' => null, 'tahun' => null, 'gjpokok' => null, 'bersih' => null],
            5 => self::kunci(self::NIP_B),
        ]);
        $sheet[1][] = 'Keterangan';

        $hasil = PratinjauImpor::uraiSheet($sheet, 'GPP');

        $this->assertTrue($hasil->valid);
        $this->assertIsArray($hasil->nilai);
        $this->assertSame([3], $hasil->nilai['baris_tanpa_nip']);
        $this->assertSame(['keterangan'], $hasil->nilai['kolom_diabaikan']);
        $this->assertSame('baris_tanpa_nip', $hasil->kode);
        $this->assertStringContainsString('baris tanpa NIP dilewati: 3', (string) $hasil->pesan);
        $this->assertSame([2, 5], array_map(static fn (BarisSheet $b): int => $b->nomorBaris, $hasil->nilai['baris']));
    }

    public function testPasanganValidMenggabungkanDuaPuluhDelapanField(): void
    {
        $gpp = self::urai('GPP', [2 => self::kunci(self::NIP_A, tambahan: ['tjistri' => '300.000', 'nama' => 'PEGAWAI CONTOH'])], true);
        $tk  = self::urai('TK', [2 => self::kunci(self::NIP_A, 'Oktober', '2026', ['bersih_2' => 3_800_000])]);

        $pratinjau = self::susun($gpp, $tk);

        $this->assertSame(1, $pratinjau['jumlah_valid']);
        $this->assertSame(0, $pratinjau['jumlah_duplikat']);
        $this->assertSame(0, $pratinjau['jumlah_galat']);
        $this->assertSame([], $pratinjau['nama_tidak_cocok']);

        $baris = $pratinjau['baris'][0];
        $this->assertSame('valid', $baris['status']);
        $this->assertSame(self::NIP_A . '|2026-10', $baris['kunci']);
        $this->assertSame('GPP', $baris['sheet']);
        $this->assertSame(2, $baris['nomor_baris']);
        $this->assertSame([], $baris['galat']);
        $this->assertCount(28, $baris['nominal']);
        $this->assertSame(KolomGaji::semuaField(), array_keys($baris['nominal']));
        $this->assertSame(300_000_000, $baris['nominal']['gjpokok']);
        $this->assertSame(30_000_000, $baris['nominal']['tjistri']);
        $this->assertSame(300_000_000, $baris['nominal']['bersih_gpp']);
        $this->assertSame(400_000_000, $baris['nominal']['kotor']);
        $this->assertSame(380_000_000, $baris['nominal']['bersih_tk']);
        $this->assertSame(380_000_000, $baris['nominal']['bersih_2']);
        $this->assertSame(0, $baris['nominal']['potlain']);
        $this->assertSame(0, $baris['nominal']['pajak']);
    }

    public function testKunciWajibAdaDiKeduaSheet(): void
    {
        $hanyaGpp = self::susun(self::urai('GPP', [2 => self::kunci(self::NIP_A)]), []);
        $this->assertSame(['tidak_ada_di_tk'], self::kodeGalat($hanyaGpp));
        $this->assertSame('galat', $hanyaGpp['baris'][0]['status']);
        $this->assertSame(1, $hanyaGpp['jumlah_galat']);

        $hanyaTk = self::susun([], self::urai('TK', [4 => self::kunci(self::NIP_A)]));
        $this->assertSame(['tidak_ada_di_gpp'], self::kodeGalat($hanyaTk));
        $this->assertSame('TK', $hanyaTk['baris'][0]['sheet']);
        $this->assertSame(4, $hanyaTk['baris'][0]['nomor_baris']);
    }

    public function testBarisGandaDalamSatuSheetDitolak(): void
    {
        $gpp = self::urai('GPP', [
            2 => self::kunci(self::NIP_A, 8),
            3 => self::kunci(self::NIP_B, 8),
            5 => self::kunci(self::NIP_A, 'Agustus', tambahan: ['gjpokok' => 9_999_999]),
        ]);
        $tk = self::urai('TK', [2 => self::kunci(self::NIP_A, 8), 3 => self::kunci(self::NIP_B, 8)]);

        $pratinjau = self::susun($gpp, $tk);

        $this->assertSame(1, $pratinjau['jumlah_valid']);
        $this->assertSame(1, $pratinjau['jumlah_galat']);

        $ganda = $pratinjau['baris'][0];
        $this->assertSame(self::NIP_A, $ganda['nip']);
        $this->assertSame('galat', $ganda['status']);
        $this->assertSame(['baris_ganda'], array_column($ganda['galat'], 'kode'));
        $this->assertStringContainsString('baris 2 dan baris 5', $ganda['galat'][0]['pesan']);
        $this->assertStringContainsString('Agustus 2026', $ganda['galat'][0]['pesan']);
        // Baris pertama yang dipertahankan.
        $this->assertSame(2, $ganda['nomor_baris']);
        $this->assertSame(300_000_000, $ganda['nominal']['gjpokok']);
    }

    public function testNipTidakTerdaftar(): void
    {
        $pratinjau = self::susun(self::urai('GPP', [2 => self::kunci(self::NIP_ASING)]), self::urai('TK', [2 => self::kunci(self::NIP_ASING)]));

        $this->assertSame(['nip_tidak_terdaftar'], self::kodeGalat($pratinjau));
    }

    public function testNamaTidakCocokDitolakDanDicatatUntukLog(): void
    {
        $gpp = self::urai('GPP', [2 => self::kunci(self::NIP_A, tambahan: ['nama' => 'Pegawai Contah'])], true);
        $tk  = self::urai('TK', [2 => self::kunci(self::NIP_A)]);

        $pratinjau = self::susun($gpp, $tk);

        $this->assertSame(['nama_tidak_cocok'], self::kodeGalat($pratinjau));
        $this->assertSame([[
            'nip'         => self::NIP_A,
            'sheet'       => 'GPP',
            'nomor_baris' => 2,
            'nama_berkas' => 'Pegawai Contah',
            'nama_db'     => 'Pegawai Contoh',
        ]], $pratinjau['nama_tidak_cocok']);
    }

    public function testNamaKosongPadahalKolomAda(): void
    {
        $gpp = self::urai('GPP', [2 => self::kunci(self::NIP_A, tambahan: ['nama' => '  '])], true);

        $pratinjau = self::susun($gpp, self::urai('TK', [2 => self::kunci(self::NIP_A)]));

        $this->assertSame(['nama_kosong'], self::kodeGalat($pratinjau));
        $this->assertSame([], $pratinjau['nama_tidak_cocok']);
    }

    public function testSetiapSheetBernamaDiperiksaSendiri(): void
    {
        $gpp = self::urai('GPP', [2 => self::kunci(self::NIP_A, tambahan: ['nama' => 'Pegawai Contoh'])], true);
        $tk  = self::urai('TK', [2 => self::kunci(self::NIP_A, tambahan: ['nama' => 'Pegawai Sintetis'])], true);

        $pratinjau = self::susun($gpp, $tk);

        $this->assertSame(['nama_tidak_cocok'], self::kodeGalat($pratinjau));
        $this->assertSame('TK', $pratinjau['nama_tidak_cocok'][0]['sheet']);
    }

    public function testDuplikatDiDbDanPrioritasGalat(): void
    {
        $sudahAda = [self::NIP_A . '|2026-10' => true, self::NIP_B . '|2026-10' => true];

        $gpp = self::urai('GPP', [2 => self::kunci(self::NIP_A), 3 => self::kunci(self::NIP_B, tambahan: ['tjanak' => 'abc'])]);
        $tk  = self::urai('TK', [2 => self::kunci(self::NIP_A), 3 => self::kunci(self::NIP_B)]);

        $pratinjau = self::susun($gpp, $tk, $sudahAda);

        $this->assertSame('duplikat', $pratinjau['baris'][0]['status']);
        $this->assertSame('galat', $pratinjau['baris'][1]['status']);
        $this->assertSame(['nominal_tidak_valid'], self::kodeGalat($pratinjau, 1));
        $this->assertSame(0, $pratinjau['jumlah_valid']);
        $this->assertSame(1, $pratinjau['jumlah_duplikat']);
        $this->assertSame(1, $pratinjau['jumlah_galat']);
    }

    public function testFilterPeriode(): void
    {
        $gpp = self::urai('GPP', [2 => self::kunci(self::NIP_A, 9), 3 => self::kunci(self::NIP_A, 10)]);
        $tk  = self::urai('TK', [2 => self::kunci(self::NIP_A, 9), 3 => self::kunci(self::NIP_A, 10)]);

        $oktober = self::susun($gpp, $tk, [], PeriodeSlip::buat(2026, 10));
        $this->assertCount(1, $oktober['baris']);
        $this->assertSame('2026-10', $oktober['baris'][0]['periode']->kunci());
        $this->assertSame(1, $oktober['jumlah_valid']);

        $kosong = PratinjauImpor::susun($gpp, $tk, self::PEGAWAI, [], PeriodeSlip::buat(2026, 11));
        $this->assertFalse($kosong->valid);
        $this->assertSame('periode_tidak_ada_di_berkas', $kosong->kode);
        $this->assertSame('Tidak ada baris data untuk periode November 2026 pada berkas ini.', $kosong->pesan);
    }

    public function testFilterPeriodeTetapMelaporkanBarisBerperiodeTidakValid(): void
    {
        // Periode baris 4 tidak terbaca, jadi tidak bisa dipastikan berada di luar filter: tetap dilaporkan sebagai galat
        // (legacy membuangnya diam-diam), dan berkas tidak dianggap "tanpa data periode itu".
        $gpp = self::urai('GPP', [2 => self::kunci(self::NIP_A, 9), 4 => self::kunci(self::NIP_B, 'Agt')]);
        $tk  = self::urai('TK', [2 => self::kunci(self::NIP_A, 9)]);

        $hasil = PratinjauImpor::susun($gpp, $tk, self::PEGAWAI, [], PeriodeSlip::buat(2026, 11));

        $this->assertTrue($hasil->valid);
        $this->assertIsArray($hasil->nilai);
        $this->assertCount(1, $hasil->nilai['baris']);
        $this->assertSame(4, $hasil->nilai['baris'][0]['nomor_baris']);
        $this->assertSame(['bulan_tidak_valid'], self::kodeGalat($hasil->nilai));
        $this->assertSame(0, $hasil->nilai['jumlah_valid']);
        $this->assertSame(1, $hasil->nilai['jumlah_galat']);
    }

    public function testFilterDariMasukan(): void
    {
        $this->assertSame('filter_periode_tidak_lengkap', PratinjauImpor::filterDariMasukan(10, null)->kode);
        $this->assertSame('filter_periode_tidak_lengkap', PratinjauImpor::filterDariMasukan('', '2026')->kode);

        $tanpa = PratinjauImpor::filterDariMasukan(null, null);
        $this->assertTrue($tanpa->valid);
        $this->assertNull($tanpa->nilai);
        $this->assertNull(PratinjauImpor::filterDariMasukan('', ' ')->nilai);

        $this->assertSame('periode_tidak_valid', PratinjauImpor::filterDariMasukan(13, 2026)->kode);
        $this->assertSame('periode_tidak_valid', PratinjauImpor::filterDariMasukan(10, '2026abc')->kode);
        $this->assertSame('periode_tidak_valid', PratinjauImpor::filterDariMasukan(0, 2026)->kode);

        $oktober = PratinjauImpor::filterDariMasukan('10', '2026');
        $this->assertInstanceOf(PeriodeSlip::class, $oktober->nilai);
        $this->assertSame('2026-10', $oktober->nilai->kunci());
    }

    public function testUrutanTahunBulanNipNumerik(): void
    {
        $data = [
            2 => self::kunci(self::NIP_B, 1, 2027),
            3 => self::kunci(self::NIP_B, 10),
            4 => self::kunci(self::NIP_A, 10),
            5 => self::kunci(self::NIP_PENDEK, 10),
            6 => self::kunci(self::NIP_A, 9),
            7 => self::kunci('JUMLAH'),
        ];

        $pratinjau = self::susun(self::urai('GPP', $data), self::urai('TK', array_slice($data, 0, 4, true)));

        $urutan = array_map(
            static fn (array $b): string => ($b['nip'] ?? '-') . '@' . ($b['periode']?->kunci() ?? '-'),
            $pratinjau['baris'],
        );

        $this->assertSame([
            self::NIP_A . '@2026-09',
            self::NIP_PENDEK . '@2026-10',
            self::NIP_A . '@2026-10',
            self::NIP_B . '@2026-10',
            '-@2026-10',
            self::NIP_B . '@2027-01',
        ], $urutan);

        // Baris tanpa NIP valid tetap dilaporkan sebagai galat (tidak dipasangkan), di akhir kelompok periodenya.
        $akhir = $pratinjau['baris'][4];
        $this->assertNull($akhir['kunci']);
        $this->assertSame('galat', $akhir['status']);
        $this->assertSame(['nip_tidak_valid'], array_column($akhir['galat'], 'kode'));
        $this->assertSame(7, $akhir['nomor_baris']);
        // NIP_A September tidak ada di TK.
        $this->assertSame(['tidak_ada_di_tk'], self::kodeGalat($pratinjau, 0));
    }

    public function testCakupanSatkerRole3(): void
    {
        $gpp = self::urai('GPP', [2 => self::kunci(self::NIP_A, tambahan: ['nama' => 'Pegawai Contoh']), 3 => self::kunci(self::NIP_B, tambahan: ['nama' => 'Nama Lain'])], true);
        $tk  = self::urai('TK', [2 => self::kunci(self::NIP_A), 3 => self::kunci(self::NIP_B)]);

        $s1 = self::susun($gpp, $tk, [], null, 'S1');
        $this->assertSame('valid', $s1['baris'][0]['status']);
        $this->assertSame(['nip_di_luar_cakupan'], self::kodeGalat($s1, 1));
        // Nama pegawai di luar cakupan tidak dibandingkan dan tidak dikutip.
        $this->assertSame([], $s1['nama_tidak_cocok']);
        $this->assertStringNotContainsString('Pegawai Sintetis', $s1['baris'][1]['galat'][0]['pesan']);

        // Satker admin kosong → fail-closed.
        $kosong = self::susun($gpp, $tk, [], null, '');
        $this->assertSame(['nip_di_luar_cakupan'], self::kodeGalat($kosong));

        // Satker pegawai kosong tidak pernah "sama" dengan satker admin kosong.
        $tanpaSatker = [self::NIP_A => ['nama' => 'Pegawai Contoh', 'id_satker' => ''], self::NIP_B => ['nama' => 'Pegawai Sintetis', 'id_satker' => null]];
        $hasil       = PratinjauImpor::susun($gpp, $tk, $tanpaSatker, [], null, '');
        $this->assertIsArray($hasil->nilai);
        $this->assertSame(2, $hasil->nilai['jumlah_galat']);
        $this->assertSame(['nip_di_luar_cakupan'], self::kodeGalat($hasil->nilai));

        // Role 1 (null) → semua; nama NIP_B dibandingkan.
        $semua = self::susun($gpp, $tk);
        $this->assertSame(['nama_tidak_cocok'], self::kodeGalat($semua, 1));
    }

    public function testSheetTertukarMelempar(): void
    {
        $gpp = self::urai('GPP', [2 => self::kunci(self::NIP_A)]);

        $this->expectException(InvalidArgumentException::class);
        PratinjauImpor::susun([], $gpp, self::PEGAWAI, []);
    }

    public function testRencanaCommit(): void
    {
        $this->assertSame('gagal', PratinjauImpor::rencanaCommit('galat', true));
        $this->assertSame('gagal', PratinjauImpor::rencanaCommit('galat', false));
        $this->assertSame('perbarui', PratinjauImpor::rencanaCommit('duplikat', true));
        $this->assertSame('lewati', PratinjauImpor::rencanaCommit('duplikat', false));
        $this->assertSame('sisip', PratinjauImpor::rencanaCommit('valid', true));
        $this->assertSame('sisip', PratinjauImpor::rencanaCommit('valid', false));

        $this->expectException(InvalidArgumentException::class);
        PratinjauImpor::rencanaCommit('error', true);
    }
}
