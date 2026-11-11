<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Interaksi\Survei;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Waktu\StempelUtc;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Ringkasan Survei Kepuasan Layanan per jenis layanan (F-11, MAKE-084, Q7-26) — untuk widget beranda admin (Jalur D).
 * Legacy hanya punya daftar tanpa agregasi (`views/hr/survey/layanan/list.php`); ringkasan adalah [V2].
 *
 * Kontrak (disepakati dengan Worker Muse 10-11):
 *
 *     {periode: {dari, sampai}, per_jenis: [{jenis_rwy, jenis_layanan, jumlah, rata_rata, sebaran: {1..5}}],
 *      total: {jumlah, rata_rata}}
 *
 * - Periode = tanggal penilaian WIB inklusif; bawaan 12 bulan terakhir (`sampai` = hari ini, `dari` = hari ini − 12 bulan + 1).
 * - `rata_rata` skala 1–5 (makin tinggi makin puas, `survey_kepuasan.php:28-37`), 1 desimal; `null` bila jumlah 0.
 * - Kelima jenis selalu ada (jumlah 0 bila tidak ada penilaian). Role 1.
 */
final class RingkasanSkl
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $query `dari`, `sampai` (Y-m-d, WIB)
     *
     * @return array{periode: array{dari: string, sampai: string}, per_jenis: list<array{jenis_rwy: int, jenis_layanan: string, jumlah: int, rata_rata: float|null, sebaran: array<int, int>}>, total: array{jumlah: int, rata_rata: float|null}}
     */
    public function ringkasan(AuthContext $auth, array $query): array
    {
        if ((int) $auth->role() !== Role::SUPER_ADMIN) {
            throw new ApiException('Anda tidak berhak melihat ringkasan survei layanan.', 403);
        }

        [$dari, $sampai] = self::periode($query);

        $rows = $this->db->table('skl')
            ->select('jenis_rwy, rate, COUNT(*) AS n')
            ->where('created_at >=', StempelUtc::awalHari($dari))
            ->where('created_at <', StempelUtc::awalHariBerikut($sampai))
            ->groupBy(['jenis_rwy', 'rate'])
            ->get()->getResultArray();

        $sebaran = [];

        foreach (array_keys(JenisLayananSkl::PETA) as $k) {
            $sebaran[$k] = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        }

        foreach ($rows as $r) {
            $k = (int) $r['jenis_rwy'];
            $n = (int) $r['rate'];

            if (isset($sebaran[$k][$n])) {
                $sebaran[$k][$n] += (int) $r['n'];
            }
        }

        $label  = JenisLayananSkl::label($this->db);
        $perJen = [];
        $jumlah = 0;
        $bobot  = 0;

        foreach ($sebaran as $k => $s) {
            $n = array_sum($s);
            $b = array_sum(array_map(static fn (int $nilai, int $c): int => $nilai * $c, array_keys($s), $s));
            $jumlah += $n;
            $bobot += $b;
            $perJen[] = [
                'jenis_rwy'     => $k,
                'jenis_layanan' => $label[$k],
                'jumlah'        => $n,
                'rata_rata'     => $n === 0 ? null : round($b / $n, 1),
                'sebaran'       => $s,
            ];
        }

        return [
            'periode'   => ['dari' => $dari, 'sampai' => $sampai],
            'per_jenis' => $perJen,
            'total'     => ['jumlah' => $jumlah, 'rata_rata' => $jumlah === 0 ? null : round($bobot / $jumlah, 1)],
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{0: string, 1: string}
     */
    public static function periode(array $query): array
    {
        $hariIni = TanggalBisnis::hariIni();
        $sampai  = is_string($query['sampai'] ?? null) && $query['sampai'] !== '' ? $query['sampai'] : $hariIni;
        $dari    = is_string($query['dari'] ?? null) && $query['dari'] !== ''
            ? $query['dari']
            : (new DateTimeImmutable($sampai, new DateTimeZone(TanggalBisnis::ZONA)))->modify('-12 months +1 day')->format('Y-m-d');
        $galat = [];

        if (! TanggalBisnis::valid($dari)) {
            $galat['dari'] = ['Tanggal dari tidak valid.'];
        }

        if (! TanggalBisnis::valid($sampai)) {
            $galat['sampai'] = ['Tanggal sampai tidak valid.'];
        }

        if ($galat === [] && $dari > $sampai) {
            $galat['sampai'] = ['Tanggal sampai tidak boleh sebelum tanggal dari.'];
        }

        if ($galat !== []) {
            throw new ValidationException('Periode tidak valid.', $galat);
        }

        return [$dari, $sampai];
    }
}
