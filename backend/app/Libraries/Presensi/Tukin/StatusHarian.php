<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use InvalidArgumentException;

/**
 * Evaluator status satu hari kerja mengikuti precedence `laporan_tukin` legacy (:4611-4810):
 * akhir pekan → tugas belajar → cuti → konket `affect_tukin=1` → presensi (TK / hadir / TPM / TPP).
 * Hari libur sudah disingkirkan `HariKerja`; potongan bulanan (TB, cuti besar/melahirkan/alasan penting) dan urutan
 * cuti sakit dihitung `KalkulasiTukin` karena butuh konteks periode.
 *
 * Input harian (semua opsional):
 * - `tugas_belajar`: true | array{mulai?: string, perpanjangan?: bool, id?: string}
 * - `cuti`: array{jenis: string, mulai?: string, akhir?: string, lama?: int, anak_sebelumnya?: int}
 * - `konket`: array{affect_tukin: int|string, kategori?: int|string}
 * - `presensi`: array{masuk?: string|null, pulang?: string|null} (`HH:MM` atau `HH:MM:SS`)
 * - `wajibLkh`: bool (sub group jabatan ≠ 1), `lkhTerisi`: bool (LKH berstatus disetujui)
 */
final class StatusHarian
{
    /** Kode jenis cuti v2 → `id_jenis_cuti` legacy (`rwy/L_cuti.php:295-375`). */
    public const JENIS_CUTI = [
        'tahunan'        => 1,
        'besar'          => 2,
        'sakit'          => 3,
        'melahirkan'     => 4,
        'alasan_penting' => 5,
        'cltn'           => 6,
    ];

    /** Cuti sakit mulai dipotong pada hari kerja berurutan ke-15 (legacy :4691 `> 14`). */
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
            if ($jenisCuti === 'sakit' && $hariSakitBerurutan > self::BATAS_HARI_CUTI_SAKIT) {
                return new HasilHarian('cuti', $config->tarif('CUTI_SAKIT'), ['CS'], jenisCuti: $jenisCuti);
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
     * @param array<string, mixed> $input
     */
    public static function adaKonket(array $input): bool
    {
        return isset($input['konket']) && is_array($input['konket']);
    }

    /**
     * Konket bebas potongan cukup `affect_tukin = 1` (legacy :4758). `kategori = 13` hanya menentukan uang makan
     * (:4763), bukan potongan.
     *
     * @param array<string, mixed> $input
     */
    public static function konketBebas(array $input): bool
    {
        return self::adaKonket($input) && (string) ($input['konket']['affect_tukin'] ?? '') === '1';
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function presensi(string $tanggal, array $input, KonfigurasiTukin $config): HasilHarian
    {
        $presensi = is_array($input['presensi'] ?? null) ? $input['presensi'] : [];
        $masuk    = self::jam($presensi['masuk'] ?? null);
        $pulang   = self::jam($presensi['pulang'] ?? null);
        $lkh      = self::potonganLkh($input, $config);

        if ($masuk === null && $pulang === null) {
            return new HasilHarian('TK', $config->tarif('TK'), self::denganLkh(['TK'], $lkh), potonganLkh: $lkh);
        }

        $standar = JamKerja::untuk($tanggal, $config);
        // TL positif = datang terlambat; PSW positif = pulang sebelum waktunya (legacy :4835-4847).
        $tl  = $masuk === null ? 0 : JamKerja::selisihMenit($standar['masuk'], $masuk);
        $psw = $pulang === null ? 0 : JamKerja::selisihMenit($pulang, $standar['pulang']);

        if ($masuk === null) {
            // TPM: TA + denda PSW satu arah (:4910-4935).
            [$kodePsw, $tarifPsw] = self::golongan('PSW', $psw, $config);

            return new HasilHarian('TPM', $config->tarif('TA') + $tarifPsw, self::denganLkh(array_merge(['TPM'], $kodePsw), $lkh), 0, $psw, $lkh);
        }
        if ($pulang === null) {
            // TPP: TA + denda TL satu arah, tanpa kompensasi jam pulang (:4968-4993).
            [$kodeTl, $tarifTl] = self::golongan('TL', $tl, $config);

            return new HasilHarian('TPP', $config->tarif('TA') + $tarifTl, self::denganLkh(array_merge(['TPP'], $kodeTl), $lkh), $tl, 0, $lkh);
        }

        // Hadir lengkap: TL1/TL2 gugur bila pulang cukup lambat (:4851-4864); TL3 tidak pernah gugur.
        [$kodeTl, $tarifTl] = self::golongan('TL', $tl, $config);
        if (($kodeTl === ['TL2'] && $psw <= -60) || ($kodeTl === ['TL1'] && $psw <= -30)) {
            [$kodeTl, $tarifTl] = [[], 0];
        }
        [$kodePsw, $tarifPsw] = self::golongan('PSW', $psw, $config);

        return new HasilHarian('hadir', $tarifTl + $tarifPsw, self::denganLkh(array_merge($kodeTl, $kodePsw), $lkh), $tl, $psw, $lkh);
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
            $menit <= 30 => [[$prefix . '1'], $config->tarif('TL1/PSW1')],
            $menit <= 60 => [[$prefix . '2'], $config->tarif('TL2/PSW2')],
            default      => [[$prefix . '3'], $config->tarif('TL3/PSW3')],
        };
    }

    /**
     * LKH dikenakan pada baris presensi (TK, hadir, TPM, TPP) bila pegawai wajib LKH dan LKH hari itu belum disetujui
     * (legacy :4804, :4888, :4946, :5004, ekspresi `potongan_lkh` yang dikomentari).
     *
     * @param array<string, mixed> $input
     */
    private static function potonganLkh(array $input, KonfigurasiTukin $config): int
    {
        return ($input['wajibLkh'] ?? false) === true && ($input['lkhTerisi'] ?? false) !== true ? $config->tarif('LKH') : 0;
    }

    /**
     * @param list<string> $kategori
     *
     * @return list<string>
     */
    private static function denganLkh(array $kategori, int $potonganLkh): array
    {
        return $potonganLkh > 0 ? [...$kategori, 'LKH'] : $kategori;
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
