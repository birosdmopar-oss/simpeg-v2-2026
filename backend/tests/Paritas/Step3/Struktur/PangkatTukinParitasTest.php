<?php

declare(strict_types=1);

namespace Tests\Paritas\Step3\Struktur;

use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Presensi\TukinData\SumberDataPegawaiTukin;
use ReflectionMethod;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * CR-164 pagar paritas step 3 — test KARAKTERISASI pangkat Tukin: {@see SumberDataPegawaiTukin} `pangkatDari()` (jalur
 * massal, baris sudah dimuat) dan `pangkat()` (jalur satu NIP, kueri sendiri). Keduanya private → lewat reflection.
 * Dua batas: awal periode, lalu akhir periode hanya bila pegawai dibuat sesudah akhir periode (WIB atas `created_at` UTC).
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

    public function testPangkatDariBatasAwalPeriodeSaja(): void
    {
        $baris = [
            $this->b('III/c', '2026-04-15'),
            $this->b('III/b', self::AWAL),
            $this->b('III/a', '2020-01-01'),
        ];

        $this->assertSame(['III/b', self::AWAL], $this->dari($baris, self::DIBUAT_DALAM), 'tmtsk = awal ikut; di antara awal–akhir tidak');
        $this->assertSame(['III/b', self::AWAL], $this->dari($baris, ''), 'created_at kosong → hanya batas awal');
    }

    public function testPangkatDariPegawaiDibuatSesudahAkhirPeriode(): void
    {
        $hanyaTengah = [$this->b('II/a', '2026-04-15')];

        $this->assertSame(['', ''], $this->dari($hanyaTengah, self::DIBUAT_DALAM), 'dibuat dalam periode → batas akhir tidak dipakai');
        $this->assertSame(['II/a', '2026-04-15'], $this->dari($hanyaTengah, self::DIBUAT_SESUDAH), 'dibuat sesudah akhir → jatuh ke batas akhir');

        // Batas awal tetap didahulukan bila ada baris ≤ awal, meski ada baris lebih baru ≤ akhir.
        $berlapis = [$this->b('II/b', '2026-04-15'), $this->b('II/a', '2025-01-01')];
        $this->assertSame(['II/a', '2025-01-01'], $this->dari($berlapis, self::DIBUAT_SESUDAH));

        // Baris sesudah akhir periode tidak pernah dipilih.
        $this->assertSame(['', ''], $this->dari([$this->b('IV/a', '2026-05-01')], self::DIBUAT_SESUDAH));
    }

    public function testPangkatDariUrutanMasukanMenentukanTmtskKembar(): void
    {
        // pangkatDari mengambil baris PERTAMA yang lolos batas; urutan id DESC dijamin oleh kueri pemanggil
        // (SumberDataPegawaiTukin.php:115). Dengan masukan apa adanya, baris pertama yang menang.
        $this->assertSame(['III/d', '2025-01-01'], $this->dari([$this->b('III/d', '2025-01-01'), $this->b('III/c', '2025-01-01')], ''));
        $this->assertSame(['III/c', '2025-01-01'], $this->dari([$this->b('III/c', '2025-01-01'), $this->b('III/d', '2025-01-01')], ''));
    }

    public function testPangkatDariGolKosongTidakDilewati(): void
    {
        // perilaku sekarang (dikunci CR-164): baris terbaru dengan gol_ruang kosong/null tetap dipakai apa adanya
        // (SumberDataPegawaiTukin.php:350-353), berbeda dengan StrukturService yang melewati gol kosong sesudah trim.
        $this->assertSame(['', '2026-03-01'], $this->dari([['gol_ruang' => null, 'tmtsk' => '2026-03-01'], $this->b('III/a', '2020-01-01')], ''));
        $this->assertSame([' ', '2026-03-01'], $this->dari([$this->b(' ', '2026-03-01'), $this->b('III/a', '2020-01-01')], ''), 'tanpa trim');
    }

    public function testPangkatDbBerlapisKembarDanStatus(): void
    {
        $nip = $this->buatPegawai();
        $this->kp($nip, 'III/a', '2020-01-01');
        $this->kp($nip, 'III/b', '2025-06-01');
        $this->kp($nip, 'III/c', '2025-06-01'); // kembar, id lebih besar
        $this->kp($nip, 'IV/a', '2026-01-01', 2); // status 2 diabaikan
        $this->kp($nip, 'IV/b', '2026-04-10');

        $this->assertSame(['III/c', '2025-06-01'], $this->pangkatDb($nip, self::DIBUAT_DALAM), 'kembar → id_riwayat_kp DESC');
        $this->assertSame(['III/c', '2025-06-01'], $this->pangkatDb($nip, self::DIBUAT_SESUDAH), 'ada baris ≤ awal → batas akhir tidak dicoba');

        $baru = $this->buatPegawai();
        $this->kp($baru, 'II/c', '2026-04-10');
        $this->assertSame(['', ''], $this->pangkatDb($baru, self::DIBUAT_DALAM));
        $this->assertSame(['II/c', '2026-04-10'], $this->pangkatDb($baru, self::DIBUAT_SESUDAH));
    }

    public function testPangkatDbSamaDenganPangkatDariBarisKueriMassal(): void
    {
        $nip = $this->buatPegawai();
        $this->kp($nip, 'II/a', '2026-04-20');
        $this->kp($nip, 'II/b', '2026-04-20');
        $this->kp($nip, 'II/c', '2026-05-02');

        // Prafilter massal = tmtsk ≤ max(awal, akhir), urut tmtsk DESC, id DESC (SumberDataPegawaiTukin.php:110-115).
        $baris = $this->db->table('riwayat_kp')->select(['nip', 'gol_ruang', 'tmtsk'])->where('nip', $nip)->where('status', 1)
            ->where('tmtsk <=', self::AKHIR)->orderBy('tmtsk', 'DESC')->orderBy('id_riwayat_kp', 'DESC')->get()->getResultArray();

        foreach ([self::DIBUAT_DALAM, self::DIBUAT_SESUDAH] as $dibuat) {
            $this->assertSame($this->pangkatDb($nip, $dibuat), $this->dari($baris, $dibuat), $dibuat);
        }

        $this->assertSame(['II/b', '2026-04-20'], $this->dari($baris, self::DIBUAT_SESUDAH));
    }

    // ---- bantuan ----

    /**
     * @return array{gol_ruang: string, tmtsk: string}
     */
    private function b(string $gol, string $tmt): array
    {
        return ['gol_ruang' => $gol, 'tmtsk' => $tmt];
    }

    private function kp(string $nip, string $gol, string $tmt, int $status = 1): void
    {
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'gol_ruang' => $gol, 'tmtsk' => $tmt, 'status' => $status]);
    }

    /**
     * @param list<array<string, mixed>> $baris
     *
     * @return array{0: string, 1: string}
     */
    private function dari(array $baris, string $createdAt): array
    {
        /** @var array{0: string, 1: string} */
        return (new ReflectionMethod(SumberDataPegawaiTukin::class, 'pangkatDari'))->invoke(null, $baris, new PeriodeTukin(self::AWAL, self::AKHIR), $createdAt);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pangkatDb(string $nip, string $createdAt): array
    {
        /** @var array{0: string, 1: string} */
        return (new ReflectionMethod(SumberDataPegawaiTukin::class, 'pangkat'))->invoke(new SumberDataPegawaiTukin($this->db), $nip, new PeriodeTukin(self::AWAL, self::AKHIR), $createdAt);
    }
}
