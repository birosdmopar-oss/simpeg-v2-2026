<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use InvalidArgumentException;

/**
 * Evaluator status satu hari kerja mengikuti precedence rekap legacy `laporan_tukin_us_skp` (:12744-13020):
 * akhir pekan → tugas belajar → cuti → konket `affect_tukin=1` → presensi (TK / hadir / TPM / TPP).
 * Hari libur dan hari di luar masa kerja sudah disingkirkan `KalkulasiTukin`; urutan cuti sakit, jadwal cuti
 * besar/melahirkan, SKP, dan pemutihan TB dihitung `KalkulasiTukin` karena butuh konteks periode.
 *
 * Semua potongan harian = tarif `web_config` × faktor presensi 0,2 (`KonfigurasiTukin::potonganHarian`).
 *
 * Input harian (semua opsional):
 * - `tugas_belajar`: true | array (isi tidak dipakai untuk potongan)
 * - `cuti`: array{jenis: string, mulai?: string, akhir?: string, lama?: int}
 * - `konket`: array{affect_tukin: int|string, kategori?: int|string}
 * - `presensi`: array{masuk?: string|null, pulang?: string|null} (`HH:MM` atau `HH:MM:SS`)
 */
final class StatusHarian
{
    /** Kode jenis cuti v2 → `id_jenis_cuti` legacy (`rwy/L_cuti.php:295-375`, rekap :12240-12266). */
    public const JENIS_CUTI = [
        'tahunan'        => 1,
        'besar'          => 2,
        'sakit'          => 3,
        'melahirkan'     => 4,
        'alasan_penting' => 5,
        'cltn'           => 6,
    ];

    /** Cuti sakit mulai dipotong pada hari kerja berurutan ke-15 (legacy :12799 `> 14`). */
    public const BATAS_HARI_CUTI_SAKIT = 14;

    /**
     * @param array<string, mixed> $input
     */
    public static function evaluasi(string $tanggal, array $input, KonfigurasiTukin $config, int $hariSakitBerurutan = 0): HasilHarian
    {
        if (HariKerja::nomorHari($tanggal) > 5) {
            return new HasilHarian('libur');
        }
        if (self::tugasBelajar($input) !== null) {
            return new HasilHarian('tugas_belajar');
        }

        $jenisCuti = self::jenisCuti($input);
        if ($jenisCuti !== null) {
            // Hanya cuti sakit hari ke-15+ yang dipotong harian (:12796-12804); jenis lain tanpa potongan harian.
            if ($jenisCuti === 'sakit' && $hariSakitBerurutan > self::BATAS_HARI_CUTI_SAKIT) {
                $potongan = $config->potonganHarian('CUTI_SAKIT');

                return new HasilHarian('cuti', $potongan, ['CS'], jenisCuti: $jenisCuti, rincian: ['CUTI' => $potongan]);
            }

            return new HasilHarian('cuti', jenisCuti: $jenisCuti);
        }
        if (self::konketBebas($input)) {
            return new HasilHarian('konket');
        }

        return self::presensi($tanggal, $input, $config);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>|null
     */
    public static function tugasBelajar(array $input): ?array
    {
        $tb = $input['tugas_belajar'] ?? false;
        if ($tb === true) {
            return [];
        }

        return is_array($tb) ? $tb : null;
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function jenisCuti(array $input): ?string
    {
        if (! isset($input['cuti'])) {
            return null;
        }
        if (! is_array($input['cuti']) || ! is_string($input['cuti']['jenis'] ?? null) || ! isset(self::JENIS_CUTI[$input['cuti']['jenis']])) {
            throw new InvalidArgumentException('Jenis cuti tidak dikenal; gunakan: ' . implode(', ', array_keys(self::JENIS_CUTI)) . '.');
        }

        return $input['cuti']['jenis'];
    }

    /**
     * Konket bebas potongan cukup `affect_tukin = 1` (rekap hanya memuat konket `affect_tukin='1'`, :12346).
     * `kategori = 13` hanya menentukan uang makan (:12836), bukan potongan.
     *
     * @param array<string, mixed> $input
     */
    public static function konketBebas(array $input): bool
    {
        return isset($input['konket']) && is_array($input['konket']) && (string) ($input['konket']['affect_tukin'] ?? '') === '1';
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function presensi(string $tanggal, array $input, KonfigurasiTukin $config): HasilHarian
    {
        $presensi = is_array($input['presensi'] ?? null) ? $input['presensi'] : [];
        $masuk    = self::jam($presensi['masuk'] ?? null);
        $pulang   = self::jam($presensi['pulang'] ?? null);

        if ($masuk === null && $pulang === null) {
            // TK (:12853-12859).
            $tk = $config->potonganHarian('TK');

            return new HasilHarian('TK', $tk, ['TK'], rincian: ['TK' => $tk]);
        }

        $standar = JamKerja::untuk($tanggal, $config);
        // TL positif = datang terlambat; PSW positif = pulang sebelum waktunya (:12871-12883).
        $tl  = $masuk === null ? 0 : JamKerja::selisihMenit($standar['masuk'], $masuk);
        $psw = $pulang === null ? 0 : JamKerja::selisihMenit($pulang, $standar['pulang']);
        $ta  = $config->potonganHarian('TA');

        if ($masuk === null) {
            // TPM: TA + denda PSW satu arah (:12933-12976).
            [$kodePsw, $tarifPsw] = self::golongan('PSW', $psw, $config);

            return new HasilHarian('TPM', $ta + $tarifPsw, array_merge(['TPM'], $kodePsw), 0, $psw, rincian: self::rincian(['TA' => $ta, 'PSW' => $tarifPsw]));
        }
        if ($pulang === null) {
            // TPP: TA + denda TL satu arah, tanpa kompensasi jam pulang (:12978-13020).
            [$kodeTl, $tarifTl] = self::golongan('TL', $tl, $config);

            return new HasilHarian('TPP', $ta + $tarifTl, array_merge(['TPP'], $kodeTl), $tl, 0, rincian: self::rincian(['TA' => $ta, 'TL' => $tarifTl]));
        }

        // Hadir lengkap: TL1/TL2 gugur bila pulang cukup lambat (:12888-12907); TL3 tidak pernah gugur.
        [$kodeTl, $tarifTl] = self::golongan('TL', $tl, $config);
        if (($kodeTl === ['TL2'] && $psw <= -60) || ($kodeTl === ['TL1'] && $psw <= -30)) {
            [$kodeTl, $tarifTl] = [[], 0];
        }
        [$kodePsw, $tarifPsw] = self::golongan('PSW', $psw, $config);

        return new HasilHarian('hadir', $tarifTl + $tarifPsw, array_merge($kodeTl, $kodePsw), $tl, $psw, rincian: self::rincian(['TL' => $tarifTl, 'PSW' => $tarifPsw]));
    }

    /**
     * Batas inklusif: 1–30 → 1, 31–60 → 2, > 60 → 3; ≤ 0 tidak didenda (satu arah).
     *
     * @return array{0: list<string>, 1: int}
     */
    private static function golongan(string $prefix, int $menit, KonfigurasiTukin $config): array
    {
        return match (true) {
            $menit <= 0  => [[], 0],
            $menit <= 30 => [[$prefix . '1'], $config->potonganHarian('TL1/PSW1')],
            $menit <= 60 => [[$prefix . '2'], $config->potonganHarian('TL2/PSW2')],
            default      => [[$prefix . '3'], $config->potonganHarian('TL3/PSW3')],
        };
    }

    /**
     * @param array<string, int> $rincian
     *
     * @return array<string, int>
     */
    private static function rincian(array $rincian): array
    {
        return array_filter($rincian, static fn (int $nilai): bool => $nilai > 0);
    }

    private static function jam(mixed $jam): ?string
    {
        if ($jam === null || $jam === '') {
            return null;
        }
        if (! is_string($jam)) {
            throw new InvalidArgumentException('Jam presensi wajib string HH:MM atau HH:MM:SS.');
        }

        return JamKerja::normalisasiJam($jam);
    }
}
