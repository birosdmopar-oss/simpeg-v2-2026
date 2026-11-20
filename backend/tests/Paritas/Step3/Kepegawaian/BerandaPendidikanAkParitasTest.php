<?php

declare(strict_types=1);

namespace Tests\Paritas\Step3\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Dashboard\Beranda\BawahanPegawai;
use App\Libraries\Dashboard\Beranda\KelengkapanData;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\FakeStorageAdapter;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * CR-164 pagar paritas step 3 (no. 6) — test karakterisasi beranda pegawai, mengunci perilaku SEKARANG:
 *
 * - `KelengkapanData` kartu pendidikan: jenjang akhir = maks(jenjang dari golongan CPNS [bawaan S.1], snapshot
 *   `pegawai_pendidikan`); aturan JP per jenjang; S.1 juga menerima D.IV; riwayat status 2/10 tidak dihitung.
 * - `BawahanPegawai` AK bawahan JF: nilai `order`/`kurang` dan urutan `order` ASC (stabil, ikut urutan NIP).
 *
 * Data sintetis. Hari ini 10-11-2026.
 *
 * @internal
 */
final class BerandaPendidikanAkParitasTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private const HARI_INI = '2026-11-10';

    /** @var array<string, int> singkat => id */
    private array $jenjang = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', 1)->countAllResults() === 0) {
            $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS', 'status' => 1]);
        }

        foreach (['SD', 'SLTP', 'SLTA', 'D.III', 'D.IV', 'S.1', 'S.2'] as $i => $singkat) {
            $ada = $this->db->table('jenjang_pendidikan')->where('jenjang_pendidikan_singkat', $singkat)->where('status', 1)->get()->getRowArray();

            if ($ada === null) {
                $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => $singkat, 'jenjang_pendidikan' => "Jenjang {$singkat}", 'order' => 90 + $i, 'status' => 1]);
                $this->jenjang[$singkat] = (int) $this->db->insertID();
            } else {
                $this->jenjang[$singkat] = (int) $ada['id_jenjang_pendidikan'];
            }
        }
    }

    // ---- KelengkapanData: pendidikan -----------------------------------------------------------------------------------

    public function testPendidikanBawaanS1DanD4MenggantikanS1(): void
    {
        $lengkap = $this->pns();
        $this->riwayat($lengkap, ['SD', 'SLTP', 'SLTA', 'D.IV']);
        $this->assertSame(100, $this->persenPendidikan($lengkap), 'tanpa CPNS/snapshot → aturan S.1; D.IV menutup kolom S.1');

        $setengah = $this->pns();
        $this->riwayat($setengah, ['SD', 'SLTA']);
        $this->assertSame(50, $this->persenPendidikan($setengah));

        $kosong = $this->pns();
        $this->assertSame(0, $this->persenPendidikan($kosong));
    }

    public function testPendidikanStatusDitolakDanDihapusTidakDihitungMenungguDihitung(): void
    {
        $nip = $this->pns();
        $this->riwayat($nip, ['SD'], 2);
        $this->riwayat($nip, ['SLTP'], 10);
        $this->riwayat($nip, ['SLTA'], 0);
        $this->riwayat($nip, ['S.1'], 1);

        // perilaku sekarang (dikunci CR-164): status 0 (Menunggu) ikut dihitung karena saringan hanya NOT IN (2, 10)
        // (KelengkapanData.php:204-205).
        $this->assertSame(50, $this->persenPendidikan($nip));
    }

    public function testPendidikanJenjangDariGolonganCpns(): void
    {
        $slta = $this->pns();
        $this->cpns($slta, 'II', 'a');
        $this->riwayat($slta, ['SD']);
        $this->assertSame(33, $this->persenPendidikan($slta), 'II/a → SLTA (3 kolom), 100/3 dibulatkan');

        $d3 = $this->pns();
        $this->cpns($d3, 'II', 'c');
        $this->riwayat($d3, ['SD', 'SLTP', 'SLTA', 'D.IV']);
        $this->assertSame(75, $this->persenPendidikan($d3), 'II/c → D.III; D.IV tidak menggantikan D.III');

        $tak = $this->pns();
        $this->cpns($tak, 'IV', 'a');
        $this->riwayat($tak, ['SD', 'SLTP', 'SLTA', 'S.1']);
        $this->assertSame(100, $this->persenPendidikan($tak), 'golongan di luar peta CPNS → bawaan S.1');
    }

    public function testPendidikanSnapshotHanyaMenaikkanJenjang(): void
    {
        $s2 = $this->pns();
        $this->snapshot($s2, 'S.2');
        $this->riwayat($s2, ['SD', 'D.IV']);
        $this->assertSame(40, $this->persenPendidikan($s2), 'S.2: 5 kolom (SD,SLTP,SLTA,S.1,S.2); D.IV menutup S.1');

        $d4 = $this->pns();
        $this->snapshot($d4, 'D.IV');
        // Riwayat S.1 tanpa D.IV: aturan S.1 → 100, aturan D.IV → 75 (kolom D.IV kosong); hasil membedakan keduanya.
        $this->riwayat($d4, ['SD', 'SLTP', 'SLTA', 'S.1']);
        $this->assertSame(100, $this->persenPendidikan($d4), 'snapshot D.IV < bawaan S.1 → tetap aturan S.1 (bukan aturan D.IV)');

        $asing = $this->pns();
        $this->snapshot($asing, 'S.3 Profesi');
        $this->riwayat($asing, ['SD', 'SLTP']);
        $this->assertSame(50, $this->persenPendidikan($asing), 'jenjang snapshot di luar daftar diabaikan → S.1');

        $turun = $this->pns();
        $this->cpns($turun, 'III', 'b');
        $this->snapshot($turun, 'SLTA');
        $this->riwayat($turun, ['SD', 'SLTP', 'SLTA', 'S.1']);
        $this->assertSame(80, $this->persenPendidikan($turun), 'CPNS III/b → S.2; snapshot lebih rendah tidak menurunkan');
    }

    // ---- BawahanPegawai: AK bawahan JF ---------------------------------------------------------------------------------

    public function testAkBawahanJfUrutOrderNaikStabilPerNip(): void
    {
        [$satker, $atasan, $jab, $idJenjang] = $this->siapkanAtasanEs4();

        // NIP eksplisit agar urutan dasar (peg.nip ASC) jelas.
        $nol1   = $this->jf($satker, $jab, '199001012020011101', 'JF Nol Satu', null, 0);
        $target = $this->jf($satker, $jab, '199001012020011102', 'JF Target', $idJenjang, 20);   // target 50 → order 30
        $lebih  = $this->jf($satker, $jab, '199001012020011103', 'JF Lebih', $idJenjang, 70);    // target 50 → order -20
        $besar  = $this->jf($satker, $jab, '199001012020011104', 'JF Tanpa Jenjang', null, 35);  // target 0 → order -35
        $nol2   = $this->jf($satker, $jab, '199001012020011105', 'JF Nol Dua', null, 0);
        $kecil  = $this->jf($satker, $jab, '199001012020011106', 'JF Tanpa Jenjang Kecil', null, 5); // order -5
        $staf   = $this->buatPegawaiDiSatker($satker, ['nip' => '199001012020011100', 'nama' => 'Staf Non JF']);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $staf)->update(['id_atasan_es_4' => $jab]);

        $hasil = (new BawahanPegawai($this->db, self::HARI_INI))->untuk($atasan);

        $this->assertCount(7, $hasil['presensi'], 'presensi semua bawahan, termasuk non-JF');
        $this->assertSame([$besar, $lebih, $kecil, $target, $nol1, $nol2], array_column($hasil['ak'], 'nip'), 'urut order ASC; seri 1000 ikut urutan NIP');
        $this->assertSame(
            [[-35.0, 35.0, 0.0, 0.0], [-20.0, 70.0, 50.0, 0.0], [-5.0, 5.0, 0.0, 0.0], [30.0, 20.0, 50.0, -30.0], [1000.0, 0.0, 0.0, 0.0], [1000.0, 0.0, 0.0, 0.0]],
            array_map(static fn (array $r): array => [$r['order'], $r['ak_now'], $r['ak_kp'], $r['kurang']], $hasil['ak']),
        );
        // perilaku sekarang (dikunci CR-164): JF tanpa jenjang (target 0) tetapi ber-AK mendapat order negatif sehingga
        // tampil PALING ATAS, mendahului JF yang masih kurang AK (BawahanPegawai.php:82, :95).
        $this->assertSame('III/a', $hasil['ak'][1]['gol_ruang']);
    }

    public function testAkBawahanSumPerNipJabatanSekarangDanFieldBaris(): void
    {
        [$satker, $atasan, $jab, $idJenjang] = $this->siapkanAtasanEs4();
        $namaSatker                          = (string) $this->db->table('satker')->where('id_satker', $satker)->get()->getRow('satker');

        $a = $this->jf($satker, $jab, '199001012020011201', 'Andi', $idJenjang, 0);
        $b = $this->jf($satker, $jab, '199001012020011202', 'Budi', $idJenjang, 0);
        $c = $this->jf($satker, $jab, '199001012020011203', 'Citra', null, 0);
        $this->db->table('pegawai')->where('nip', $a)->update(['glr_awal' => 'Dr.', 'glr_akhir' => 'S.Kom.']);
        $this->db->table('pegawai')->where('nip', $b)->update(['glr_akhir' => 'M.Si.']);
        $jabA = $this->idJabatan($a);
        $jabB = $this->idJabatan($b);
        $jabC = $this->idJabatan($c);

        // A: beberapa baris di jabatan sekarang → SUM 10 + 15 (konversi) + 7 (integrasi saja, is_konversi '0') = 32;
        // baris tanpa konversi/integrasi (99) tidak dihitung.
        $this->siasn($a, $jabA, 10, '1', null);
        $this->siasn($a, $jabA, 15, '1', '0');
        $this->siasn($a, $jabA, 7, '0', '1');
        $this->siasn($a, $jabA, 99, '0', '0');
        // Baris berstatus 2 (Ditolak) tetap ikut SUM: AkPermenpanrb sengaja tanpa saringan status (docblock :18-19) → A = 36.
        $this->siasn($a, $jabA, 4, '1', null);
        $this->db->table('riwayat_ak_siasn')->where('nip', $a)->where('kredit_baru_total', 4)->update(['status' => 2]);
        // A pernah di jabatan yang id-nya = jabatan B sekarang → tidak ikut untuk A (bukan jabatan sekarang) dan tidak
        // untuk B (saringan nip).
        $this->siasn($a, $jabB, 40, '1', null);
        $this->siasn($b, $jabB, 5, null, '1');
        // C: jumlah negatif → dipotong 0 (AkPermenpanrb.php:60, max(0.0, …)).
        $this->siasn($c, $jabC, -10, '1', null);
        $this->siasn($c, $jabC, 3, '1', null);

        $hasil = (new BawahanPegawai($this->db, self::HARI_INI))->untuk($atasan);

        $this->assertSame([$a, $b, $c], array_column($hasil['ak'], 'nip'), 'order ASC: A 14, B 45, C 1000');
        $this->assertSame(
            [[14.0, 36.0, 50.0, -14.0], [45.0, 5.0, 50.0, -45.0], [1000.0, 0.0, 0.0, 0.0]],
            array_map(static fn (array $r): array => [$r['order'], $r['ak_now'], $r['ak_kp'], $r['kurang']], $hasil['ak']),
        );
        $jabatan = fn (string $nip): string => (string) $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRow('jabatan');
        $this->assertSame([
            ['Dr. Andi, S.Kom.', $jabatan($a), $namaSatker, 'III/a'],
            ['Budi, M.Si.', $jabatan($b), $namaSatker, 'III/a'],
            ['Citra', $jabatan($c), $namaSatker, ''],
        ], array_map(static fn (array $r): array => [$r['nama'], $r['jabatan'], $r['satker'], $r['gol_ruang']], $hasil['ak']));
        $this->assertNotSame('', $jabatan($a));
    }

    // ---- bantu --------------------------------------------------------------------------------------------------------

    /**
     * Master group/sub group, jenjang JF Ahli Pertama, satker, dan atasan Eselon IV.
     *
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
        $jab = (int) $this->db->table('pegawai_mutasi_jabatan')->where('nip', $atasan)->get()->getRow('id_jabatan');


        return [$satker, $atasan, $jab, $idJenjang];
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

    private function jf(int $satker, int $jabAtasan, string $nip, string $nama, ?int $idJenjang, float $ak): string
    {
        $this->buatPegawaiDiSatker($satker, ['nip' => $nip, 'nama' => $nama]);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->update(['id_atasan_es_4' => $jabAtasan, 'id_group_jabatan' => 2]);
        $idJab = (int) $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRow('id_jabatan');

        if ($idJenjang !== null) {
            $this->db->table('jabatan')->where('id_jabatan', $idJab)->update(['id_jenjang_jf' => $idJenjang]);
            $this->db->table('pegawai_kp')->insert([
                'nip' => $nip, 'tmtsk' => '2020-01-01', 'tgl_sk' => '2019-12-01', 'jenis_kp' => 'Reguler',
                'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda',
            ]);
        }

        if ($ak > 0) {
            $this->db->table('riwayat_ak_siasn')->insert([
                'nip'               => $nip, 'id_jabatan' => $idJab, 'id_rw_siasn' => 'rw-' . bin2hex(random_bytes(6)),
                'kredit_baru_total' => $ak, 'is_konversi' => '1', 'is_integrasi' => null, 'status' => 1,
            ]);
        }

        return $nip;
    }

    private function pns(): string
    {
        return $this->buatPegawaiDiSatker($this->buatSatker(), ['id_jenis_pegawai' => 1, 'tgl_lahir' => '1990-01-01']);
    }

    /**
     * @param list<string> $jenjang
     */
    private function riwayat(string $nip, array $jenjang, int $status = 1): void
    {
        foreach ($jenjang as $singkat) {
            $this->db->table('riwayat_pendidikan')->insert(['nip' => $nip, 'id_jenjang_pendidikan' => $this->jenjang[$singkat], 'tgl_lulus' => '2010-01-01', 'status' => $status]);
        }
    }

    private function cpns(string $nip, string $gol, string $ruang): void
    {
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'id_jenis_kp' => null, 'gol' => $gol, 'ruang' => $ruang, 'gol_ruang' => "{$gol}/{$ruang}", 'tmtsk' => '2015-01-01', 'status' => 1]);
        $this->db->table('pegawai_cpns')->insert([
            'nip'      => $nip, 'id_riwayat_kp' => (int) $this->db->insertID(), 'tmtsk' => '2015-01-01', 'tgl_sk' => '2015-01-01',
            'jenis_kp' => 'CPNS', 'gol' => $gol, 'ruang' => $ruang, 'gol_ruang' => "{$gol}/{$ruang}", 'pangkat' => 'Pangkat Uji',
        ]);
    }

    private function snapshot(string $nip, string $singkat): void
    {
        $this->db->table('pegawai_pendidikan')->insert([
            'nip' => $nip, 'id_jenjang_pendidikan' => $this->jenjang[$singkat] ?? $this->jenjang['S.1'], 'jenjang_pendidikan_singkat' => $singkat, 'tgl_lulus' => '2010-08-01',
        ]);
    }

    /** Persen kartu pendidikan; kartu tidak tampil (≥ 100) → 100. */
    private function persenPendidikan(string $nip): int
    {
        $kode = array_column((new KelengkapanData($this->db, self::HARI_INI, new FakeStorageAdapter()))->kartu(Role::PEGAWAI, $nip), 'persen', 'kode');

        return $kode['pendidikan'] ?? 100;
    }
}
