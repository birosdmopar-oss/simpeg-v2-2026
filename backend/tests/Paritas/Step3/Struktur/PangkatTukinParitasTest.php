<?php

declare(strict_types=1);

namespace Tests\Paritas\Step3\Struktur;

use App\Libraries\Kepegawaian\Riwayat\Definisi\Jabatan;
use App\Libraries\Presensi\Tukin\DataPegawaiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Presensi\TukinData\SumberDataPegawaiTukin;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * CR-164 pagar paritas step 3 — test KARAKTERISASI pangkat & mutasi PPPK Tukin lewat API publik
 * {@see SumberDataPegawaiTukin::untukBanyak()} (dan {@see SumberDataPegawaiTukin::untuk()} sebagai pembanding jalur tunggal).
 *
 * Pangkat terpilih teramati dari `tmtMasuk`: gol berawalan "CPNS" (panjang > 5) → `tmtMasuk` = tmtsk baris terpilih;
 * gol lain (PNS) → null. PPPK: baris `riwayat_mutasi_jabatan` terpilih menggantikan gol/TMT; `tmtMasuk` = tmtsk bila
 * > hari kerja pertama periode (01-04-2026, tanpa hari libur), gol_pppk "CPNS…" → tmtsk apa adanya.
 * Dua batas pangkat: awal periode, lalu akhir periode hanya bila pegawai dibuat sesudah akhir periode (WIB atas UTC).
 *
 * @internal
 */
final class PangkatTukinParitasTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private const AWAL  = '2026-04-01';
    private const AKHIR = '2026-04-30';

    /** 30-04 23:59:59 WIB — belum "sesudah akhir periode". */
    private const DIBUAT_DALAM = '2026-04-30 16:59:59';

    /** 01-05 00:00 WIB — sesudah akhir periode. */
    private const DIBUAT_SESUDAH = '2026-04-30 17:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'PNS Uji', Jabatan::JENIS_PEGAWAI_PPPK => 'PPPK Uji'] as $id => $nama) {
            if ($this->db->table('jenis_pegawai')->where('id_jenis_pegawai', $id)->countAllResults() === 0) {
                $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => $id, 'jenis_pegawai' => $nama, 'status' => 1]);
            }
        }
    }

    public function testPangkatBerlapisBatasAwalPeriodeDanStatusLebihBaruDiabaikan(): void
    {
        $nip = $this->pns(self::DIBUAT_DALAM);
        $this->kp($nip, 'CPNS III/a', '2020-01-01');
        $this->kp($nip, 'CPNS III/b', self::AWAL);      // pemenang: tmtsk = awal ikut
        $this->kp($nip, 'CPNS III/c', '2026-04-15');    // di antara awal–akhir → tidak
        $this->kp($nip, 'CPNS IV/a', '2026-03-15', 2);  // status 2 → diabaikan
        $this->kp($nip, 'CPNS IV/b', '2026-03-20', 10); // status 10 → diabaikan

        $this->assertSame(self::AWAL, $this->tmtMasuk($nip));

        // Status 2/10 LEBIH BARU dari pemenang (di bawah batas) tidak boleh menggeser pemenang.
        $lain = $this->pns(self::DIBUAT_DALAM);
        $this->kp($lain, 'CPNS II/a', '2025-01-01');
        $this->kp($lain, 'III/a', '2026-02-01', 2);
        $this->kp($lain, 'III/b', '2026-03-01', 10);
        $this->assertSame('2025-01-01', $this->tmtMasuk($lain));
    }

    public function testPegawaiDibuatSesudahAkhirPeriodeJatuhKeBatasAkhir(): void
    {
        $dalam   = $this->pns(self::DIBUAT_DALAM);
        $sesudah = $this->pns(self::DIBUAT_SESUDAH);
        $tumpuk  = $this->pns(self::DIBUAT_SESUDAH);
        $depan   = $this->pns(self::DIBUAT_SESUDAH);

        foreach ([$dalam, $sesudah] as $n) {
            $this->kp($n, 'CPNS II/a', '2026-04-15');
        }
        // Batas awal tetap didahulukan: ada baris ≤ awal (PNS) → baris CPNS 15-04 tidak dicoba.
        $this->kp($tumpuk, 'II/a', '2025-01-01');
        $this->kp($tumpuk, 'CPNS II/b', '2026-04-15');
        // Baris sesudah akhir periode tidak pernah dipilih.
        $this->kp($depan, 'CPNS IV/a', '2026-05-01');

        $hasil = $this->banyak([$dalam, $sesudah, $tumpuk, $depan]);

        $this->assertSame([null, '2026-04-15', null, null], [$hasil[$dalam]->tmtMasuk, $hasil[$sesudah]->tmtMasuk, $hasil[$tumpuk]->tmtMasuk, $hasil[$depan]->tmtMasuk]);
        $this->assertSame([false, false, false, false], [$hasil[$dalam]->lewatiLoopHarian, $hasil[$sesudah]->lewatiLoopHarian, $hasil[$tumpuk]->lewatiLoopHarian, $hasil[$depan]->lewatiLoopHarian]);
    }

    public function testPangkatTmtskKembarIdTerbesarMenang(): void
    {
        $a = $this->pns(self::DIBUAT_DALAM);
        $this->kp($a, 'III/a', '2025-01-01');
        $this->kp($a, 'CPNS III/a', '2025-01-01'); // id lebih besar → menang

        $b = $this->pns(self::DIBUAT_DALAM);
        $this->kp($b, 'CPNS III/a', '2025-01-01');
        $this->kp($b, 'III/a', '2025-01-01'); // id lebih besar → menang

        $hasil = $this->banyak([$a, $b]);

        $this->assertSame(['2025-01-01', null], [$hasil[$a]->tmtMasuk, $hasil[$b]->tmtMasuk]);
    }

    public function testGolTidakDipangkasSebelumCekCpns(): void
    {
        $nip = $this->pns(self::DIBUAT_DALAM);
        $this->kp($nip, 'CPNS II/a', '2020-01-01');
        $this->kp($nip, ' CPNS II/b', '2025-01-01');

        // perilaku sekarang (dikunci CR-164): gol_ruang dipakai tanpa trim (SumberDataPegawaiTukin.php:350 dan :247),
        // jadi " CPNS…" tidak dikenali CPNS; berbeda dengan StrukturService yang memangkas gol.
        $this->assertNull($this->tmtMasuk($nip));
    }

    public function testMutasiPppkSatuBatchBerlapisKembarDanSaringan(): void
    {
        $berlapis = $this->pppk();
        $this->mutasi($berlapis, 'IX', '2025-01-01');
        $this->mutasi($berlapis, 'X', '2026-04-10');                            // pemenang
        $this->mutasi($berlapis, 'XI', '2026-05-02');                           // > akhir periode → diabaikan
        $this->mutasi($berlapis, 'XII', '2026-04-20', ['status' => 2]);
        $this->mutasi($berlapis, 'XIV', '2026-04-22', ['status' => 10]); // C2: lebih baru, status 10 → diabaikan         // lebih baru, status 2 → diabaikan
        $this->mutasi($berlapis, 'XIII', '2026-04-25', ['jenis_jabatan' => 2]); // lebih baru, jenis lain → diabaikan

        $kembarA = $this->pppk();
        $this->mutasi($kembarA, 'IX', self::AWAL);
        $this->mutasi($kembarA, 'CPNS IX', self::AWAL); // id lebih besar → menang → tmtMasuk = tmtsk

        $kembarB = $this->pppk();
        $this->mutasi($kembarB, 'CPNS IX', self::AWAL);
        $this->mutasi($kembarB, 'IX', self::AWAL); // id lebih besar → menang → tmtsk tidak > hari kerja pertama → null

        $tanpa = $this->pppk();
        $this->kp($tanpa, 'IX', '2026-04-08'); // tanpa mutasi; KP tmtsk > awal → tidak terpilih

        // R7: PPPK yang semua mutasinya tersaring → jatuh ke KP sah ≤ awal periode.
        $kpSaja = $this->pppk();
        $this->mutasi($kpSaja, 'IX', '2026-05-05');
        $this->mutasi($kpSaja, 'X', '2026-03-05', ['status' => 2]);
        $this->kp($kpSaja, 'CPNS IX', '2026-03-01');

        $hasil = $this->banyak([$berlapis, $kembarA, $kembarB, $tanpa, $kpSaja]);
        $this->assertSame(['2026-03-01', false], [$hasil[$kpSaja]->tmtMasuk, $hasil[$kpSaja]->lewatiLoopHarian]);

        $this->assertSame(['2026-04-10', false], [$hasil[$berlapis]->tmtMasuk, $hasil[$berlapis]->lewatiLoopHarian]);
        $this->assertSame(self::AWAL, $hasil[$kembarA]->tmtMasuk);
        $this->assertNull($hasil[$kembarB]->tmtMasuk);
        // PPPK tanpa TMT sama sekali → seluruh loop harian dilewati.
        $this->assertSame([null, true], [$hasil[$tanpa]->tmtMasuk, $hasil[$tanpa]->lewatiLoopHarian]);
    }

    public function testJalurTunggalSamaDenganJalurMassal(): void
    {
        $nip = [];

        foreach ([self::DIBUAT_DALAM, self::DIBUAT_SESUDAH] as $dibuat) {
            $n = $this->pns($dibuat);
            $this->kp($n, 'III/a', '2026-04-20');
            $this->kp($n, 'CPNS III/a', '2026-04-20');
            $this->kp($n, 'CPNS III/b', '2026-05-02');
            $nip[] = $n;
        }

        $p = $this->pppk();
        $this->mutasi($p, 'IX', '2026-04-10');
        $this->mutasi($p, 'X', '2026-04-10');
        $nip[] = $p;

        $massal = $this->banyak($nip);
        $sumber = new SumberDataPegawaiTukin($this->db);

        foreach ($nip as $n) {
            $tunggal = $sumber->untuk($n, new PeriodeTukin(self::AWAL, self::AKHIR), null);
            $this->assertSame([$tunggal->tmtMasuk, $tunggal->lewatiLoopHarian], [$massal[$n]->tmtMasuk, $massal[$n]->lewatiLoopHarian], $n);
        }

        $this->assertSame([null, '2026-04-20', '2026-04-10'], [$massal[$nip[0]]->tmtMasuk, $massal[$nip[1]]->tmtMasuk, $massal[$nip[2]]->tmtMasuk]);
    }

    // ---- bantuan ----

    private function pns(string $dibuat): string
    {
        return $this->buatPegawai(['id_jenis_pegawai' => 1, 'created_at' => $dibuat]);
    }

    private function pppk(): string
    {
        return $this->buatPegawai(['id_jenis_pegawai' => Jabatan::JENIS_PEGAWAI_PPPK, 'created_at' => self::DIBUAT_DALAM]);
    }

    private function kp(string $nip, string $gol, string $tmt, int $status = 1): void
    {
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'gol_ruang' => $gol, 'tmtsk' => $tmt, 'status' => $status]);
    }

    /**
     * @param array<string, mixed> $ekstra
     */
    private function mutasi(string $nip, string $gol, string $tmt, array $ekstra = []): void
    {
        $this->buatRiwayatMutasiJabatan($nip, $ekstra + ['gol_pppk' => $gol, 'tmtsk' => $tmt]);
    }

    /**
     * @param list<string> $nip
     *
     * @return array<string, DataPegawaiTukin>
     */
    private function banyak(array $nip): array
    {
        $hasil = [];

        foreach ((new SumberDataPegawaiTukin($this->db))->untukBanyak($nip, new PeriodeTukin(self::AWAL, self::AKHIR), null) as $n => $d) {
            $this->assertInstanceOf(DataPegawaiTukin::class, $d, (string) $n);
            $hasil[(string) $n] = $d;
        }

        return $hasil;
    }

    private function tmtMasuk(string $nip): ?string
    {
        return $this->banyak([$nip])[$nip]->tmtMasuk;
    }
}
