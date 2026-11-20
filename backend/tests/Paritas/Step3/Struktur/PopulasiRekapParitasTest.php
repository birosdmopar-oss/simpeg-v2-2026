<?php

declare(strict_types=1);

namespace Tests\Paritas\Step3\Struktur;

use App\Libraries\Presensi\Laporan\LingkupLaporan;
use App\Libraries\Presensi\Laporan\PopulasiRekap;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * CR-164 pagar paritas step 3 — test KARAKTERISASI {@see PopulasiRekap::dh()} / {@see PopulasiRekap::il()}: mengunci
 * perilaku sekarang (riwayat jabatan & KP berlapis, tmtsk kembar, lingkup satker role 3, urutan usort, status 2).
 *
 * @internal
 */
final class PopulasiRekapParitasTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private const AWAL  = '2026-04-01';
    private const AKHIR = '2026-04-30';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'PNS Uji', 2 => 'PPPK Uji'] as $id => $nama) {
            if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
                $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'status' => 1]);
            }
        }
    }

    public function testDhRiwayatJabatanBerlapisBarisTerbaruSampaiAkhirBulan(): void
    {
        $nip = $this->pegawai();
        $s1  = $this->buatSatker();
        $s2  = $this->buatSatker();
        $s3  = $this->buatSatker();
        $this->rmj($nip, $s1, '2025-01-01', 'Jabatan Lama');
        $this->rmj($nip, $s2, self::AKHIR, 'Jabatan Akhir Bulan');
        $this->rmj($nip, $s3, '2026-05-01', 'Jabatan Bulan Depan');
        $this->rmj($nip, $s3, '2026-04-15', 'Jabatan Status 2', ['status' => 2]);

        $baris = $this->dhUntuk([$nip]);

        $this->assertSame(['Jabatan Akhir Bulan', $s2], [$baris[0]['jabatan'], $baris[0]['id_satker']], 'tmtsk = akhir bulan ikut; sesudahnya & status 2 tidak');
    }

    public function testDhTmtskKembarIdTerbesarMenang(): void
    {
        $nip = $this->pegawai();
        $s1  = $this->buatSatker();
        $s2  = $this->buatSatker();
        $this->rmj($nip, $s1, '2026-01-01', 'Kembar Pertama');
        $this->rmj($nip, $s2, '2026-01-01', 'Kembar Kedua');

        $this->assertSame('Kembar Kedua', $this->dhUntuk([$nip])[0]['jabatan'], 'tmtsk kembar → id_riwayat_mutasi_jabatan DESC');
    }

    public function testDhPangkatBerlapisDibatasiAwalBulanDanKembarIdTerbesar(): void
    {
        // Pangkat tidak keluar di hasil; dikunci lewat metode private terbaru() (dipanggil lengkapi() dengan batas awal).
        $nip = $this->pegawai();
        $this->kp($nip, 'III/a', '2020-01-01');
        $this->kp($nip, 'III/b', self::AWAL);
        $this->kp($nip, 'III/c', '2026-04-15');
        $this->kp($nip, 'IV/a', '2026-03-01', 2);

        $this->assertSame(['III/b', self::AWAL], $this->pangkat($nip, self::AWAL), 'tmtsk = awal ikut, sesudah awal tidak, status 2 diabaikan');

        $kembar = $this->pegawai();
        $this->kp($kembar, 'II/c', '2025-01-01');
        $this->kp($kembar, 'II/b', '2025-01-01');
        $this->assertSame(['II/b', '2025-01-01'], $this->pangkat($kembar, self::AWAL), 'tmtsk kembar → id_riwayat_kp DESC (baris terakhir disisip)');
    }

    public function testDhUrutanKelasGolTmtSatkerNip(): void
    {
        $sKecil = $this->buatSatker();
        $sBesar = $this->buatSatker();
        $p      = [];

        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $k) {
            $p[$k] = $this->pegawai();
        }

        // a: kelas 12 (tertinggi) → pertama meski gol rendah.
        $this->rmj($p['a'], $sBesar, '2020-01-01', 'A', ['kelas_jabatan' => 12]);
        $this->kp($p['a'], 'II/a', '2020-01-01');
        // b, c: kelas 10; gol teks turun: IV/a > III/d.
        $this->rmj($p['b'], $sBesar, '2020-01-01', 'B', ['kelas_jabatan' => 10]);
        $this->kp($p['b'], 'III/d', '2020-01-01');
        $this->rmj($p['c'], $sBesar, '2020-01-01', 'C', ['kelas_jabatan' => 10]);
        $this->kp($p['c'], 'IV/a', '2020-01-01');
        // d, e: kelas 8, gol sama; TMT pangkat naik.
        $this->rmj($p['d'], $sBesar, '2020-01-01', 'D', ['kelas_jabatan' => 8]);
        $this->kp($p['d'], 'III/a', '2019-01-01');
        $this->rmj($p['e'], $sBesar, '2020-01-01', 'E', ['kelas_jabatan' => 8]);
        $this->kp($p['e'], 'III/a', '2018-01-01');
        // f, g: kelas 6, gol & TMT sama; id_satker naik.
        $this->rmj($p['f'], $sBesar, '2020-01-01', 'F', ['kelas_jabatan' => 6]);
        $this->kp($p['f'], 'II/b', '2018-01-01');
        $this->rmj($p['g'], $sKecil, '2020-01-01', 'G', ['kelas_jabatan' => 6]);
        $this->kp($p['g'], 'II/b', '2018-01-01');
        // h: tanpa riwayat jabatan & KP → kelas 0, gol '', tmt 9999-12-31, satker null → paling akhir.

        $urut = array_column($this->dhUntuk(array_values($p)), 'nip');
        $map  = array_flip($p);

        $this->assertSame(['a', 'c', 'b', 'e', 'd', 'g', 'f', 'h'], array_map(static fn (string $n): string => $map[$n], $urut));
    }

    public function testDhUrutanNipSebagaiPemutusSeri(): void
    {
        $s  = $this->buatSatker();
        $n1 = $this->pegawai();
        $n2 = $this->pegawai();
        $this->rmj($n2, $s, '2020-01-01', 'Sama', ['kelas_jabatan' => 7]);
        $this->rmj($n1, $s, '2020-01-01', 'Sama', ['kelas_jabatan' => 7]);

        $this->assertSame([$n1, $n2], array_column($this->dhUntuk([$n2, $n1]), 'nip'));
    }

    public function testDhGolTeksBukanUrutanPangkat(): void
    {
        $s  = $this->buatSatker();
        $iv = $this->pegawai();
        $ii = $this->pegawai();
        $this->rmj($iv, $s, '2020-01-01', 'X', ['kelas_jabatan' => 5]);
        $this->kp($iv, 'IV/a', '2020-01-01');
        $this->rmj($ii, $s, '2020-01-01', 'X', ['kelas_jabatan' => 5]);
        $this->kp($ii, 'II/e', '2020-01-01');

        // perilaku sekarang (dikunci CR-164): gol_ruang dibandingkan sebagai TEKS (<=>), bukan peringkat pangkat
        // (PopulasiRekap.php:111-112) — 'IV/a' > 'II/e' benar secara kebetulan karena 'V' > 'I'; 'IV' < 'V' dst. tidak diuji.
        $this->assertSame([$iv, $ii], array_column($this->dhUntuk([$ii, $iv]), 'nip'));
    }

    public function testDhLingkupSatkerRole3MemakaiJabatanBerlaku(): void
    {
        $sLama  = $this->buatSatker();
        $sBaru  = $this->buatSatker();
        $pindah = $this->pegawai();
        $this->rmj($pindah, $sLama, '2020-01-01', 'Di Satker Lama');
        $this->rmj($pindah, $sBaru, '2026-04-10', 'Di Satker Baru');
        $tanpa = $this->pegawai();

        $lama = $this->dhUntuk([$pindah, $tanpa], new LingkupLaporan(LingkupLaporan::SATKER, $sLama, 'Lama'));
        $baru = $this->dhUntuk([$pindah, $tanpa], new LingkupLaporan(LingkupLaporan::SATKER, $sBaru, 'Baru'));
        $kem  = $this->dhUntuk([$pindah, $tanpa], new LingkupLaporan(LingkupLaporan::KEMENTERIAN, null, 'Kem'));

        $this->assertSame([], $lama, 'satker lama tidak mencakup pegawai yang sudah pindah');
        $this->assertSame([$pindah], array_column($baru, 'nip'), 'tanpa jabatan → tidak masuk lingkup satker');
        $this->assertEqualsCanonicalizing([$pindah, $tanpa], array_column($kem, 'nip'), 'kementerian mencakup tanpa jabatan');
        // perilaku sekarang (dikunci CR-164): pegawai tanpa riwayat jabatan tetap muncul dengan jabatan/satker kosong.
        $this->assertSame(['', null, null], [$kem[1]['jabatan'], $kem[1]['id_satker'], $kem[1]['id_unit']]);
    }

    public function testDhStatus2DenganTmtStatusSesudahAwal(): void
    {
        $sesudah = $this->pegawai(['status' => 2, 'tmt_status' => '2026-04-02']);
        $tepat   = $this->pegawai(['status' => 2, 'tmt_status' => self::AWAL]);
        $null    = $this->pegawai(['status' => 2, 'tmt_status' => null]);
        $hapus   = $this->pegawai(['status' => 10, 'tmt_status' => '2026-05-01']);

        $this->assertSame([$sesudah], array_column($this->dhUntuk([$sesudah, $tepat, $null, $hapus]), 'nip'), 'hanya tmt_status > awal (ketat)');
    }

    public function testDhCreatedAtDanJenisPegawai(): void
    {
        $sebelum = $this->pegawai(['created_at' => '2026-04-30 16:59:59']); // 30-04 23:59:59 WIB
        $sesudah = $this->pegawai(['created_at' => '2026-04-30 17:00:00']); // 01-05 00:00 WIB
        $jenis2  = $this->pegawai(['id_jenis_pegawai' => 2]);

        $this->assertSame([$sebelum], array_column($this->dhUntuk([$sebelum, $sesudah, $jenis2]), 'nip'));
    }

    public function testIlJabatanJenis2BatasAwalBulanDanCreatedAt(): void
    {
        $s1 = $this->buatSatker();
        $s2 = $this->buatSatker();
        $il = $this->pegawai([], ['jenis_jabatan' => 2]);
        $this->rmj($il, $s1, '2025-01-01', 'IL Lama', ['jenis_jabatan' => 2]);
        $this->rmj($il, $s2, self::AWAL, 'IL Awal', ['jenis_jabatan' => 2]);
        $this->rmj($il, $s2, '2026-04-02', 'IL Sesudah Awal', ['jenis_jabatan' => 2]);
        $this->rmj($il, $s1, '2026-03-01', 'Jenis 1', ['jenis_jabatan' => 1]);
        $bukanIl  = $this->pegawai();
        $baruIl   = $this->pegawai(['created_at' => '2026-04-01 17:00:00'], ['jenis_jabatan' => 2]); // 02-04 WIB
        $keluarIl = $this->pegawai(['status' => 2, 'tmt_status' => '2026-04-02'], ['jenis_jabatan' => 2]);

        $hasil = array_values(array_filter((new PopulasiRekap($this->db))->il(self::AWAL), static fn (array $r): bool => in_array($r['nip'], [$il, $bukanIl, $baruIl, $keluarIl], true)));

        $this->assertEqualsCanonicalizing([$il, $keluarIl], array_column($hasil, 'nip'));
        $baris = array_values(array_filter($hasil, static fn (array $r): bool => $r['nip'] === $il))[0];
        $this->assertSame(['IL Awal', $s2], [$baris['jabatan'], $baris['id_satker']], 'IL: tmtsk ≤ awal bulan, jenis_jabatan 2 saja');
    }

    // ---- bantuan ----

    /**
     * @param array<string, mixed> $override
     * @param array<string, mixed> $pmj
     */
    private function pegawai(array $override = [], array $pmj = []): string
    {
        return $this->buatPegawai($override + ['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 1], $pmj);
    }

    /**
     * @param array<string, mixed> $ekstra
     */
    private function rmj(string $nip, int $idSatker, string $tmt, string $nama, array $ekstra = []): void
    {
        $jab = $this->buatJabatan($idSatker);
        $this->buatRiwayatMutasiJabatan($nip, ['id_jabatan' => $jab['id_jabatan'], 'tmtsk' => $tmt, 'jabatan' => $nama] + $ekstra);
    }

    private function kp(string $nip, string $gol, string $tmt, int $status = 1): void
    {
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'gol_ruang' => $gol, 'tmtsk' => $tmt, 'status' => $status]);
    }

    /**
     * @param list<string> $nip
     *
     * @return list<array<string, mixed>>
     */
    private function dhUntuk(array $nip, ?LingkupLaporan $lingkup = null): array
    {
        $hasil = (new PopulasiRekap($this->db))->dh(self::AWAL, self::AKHIR, $lingkup ?? new LingkupLaporan(LingkupLaporan::KEMENTERIAN, null, 'Kem'), [1]);

        return array_values(array_filter($hasil, static fn (array $r): bool => in_array($r['nip'], $nip, true)));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pangkat(string $nip, string $batas): array
    {
        $m   = new \ReflectionMethod(PopulasiRekap::class, 'terbaru');
        $row = $m->invoke(new PopulasiRekap($this->db), 'riwayat_kp', 'id_riwayat_kp', 'nip, gol_ruang, tmtsk', [$nip], $batas, [])[$nip] ?? [];

        return [(string) ($row['gol_ruang'] ?? ''), (string) ($row['tmtsk'] ?? '')];
    }
}
