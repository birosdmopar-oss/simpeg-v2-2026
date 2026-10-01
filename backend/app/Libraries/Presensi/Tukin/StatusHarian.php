<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

/** Evaluator status satu hari: tugas belajar, cuti, konket, libur, lalu presensi. */
final class StatusHarian
{
    /** @param array<string, mixed> $input */
    public static function evaluasi(string $tanggal, array $input, KonfigurasiTukin $config): HasilHarian
    {
        if (($input['tugas_belajar'] ?? false) === true) return new HasilHarian('tugas_belajar', 0);
        if (isset($input['cuti']) && is_array($input['cuti'])) return self::cuti($input['cuti']);
        if (isset($input['konket']) && is_array($input['konket']) && (string) ($input['konket']['affect_tukin'] ?? '2') === '1' && (string) ($input['konket']['kategori'] ?? '') === '13') return new HasilHarian('konket', 0, 'hadir');
        if (($input['libur'] ?? false) === true) return new HasilHarian('libur', 0);

        $presensi = is_array($input['presensi'] ?? null) ? $input['presensi'] : [];
        $masuk = (string) ($presensi['masuk'] ?? '');
        $pulang = (string) ($presensi['pulang'] ?? '');
        if ($masuk === '' && $pulang === '') return new HasilHarian('TK', $config->tarif('TK'), 'TK');
        $jam = JamKerja::untuk($tanggal, $config);
        if ($masuk === '' || $pulang === '') {
            $kategori = $masuk === '' ? 'TPM' : 'TPP';
            $selisih = $masuk === '' ? JamKerja::selisihMenit($jam['pulang'], $pulang) : JamKerja::selisihMenit($jam['masuk'], $masuk);
            return new HasilHarian($kategori, $config->tarif('TA') + self::tarifKeterlambatan(abs($selisih), $config), $kategori);
        }
        $tl = max(0, JamKerja::selisihMenit($jam['masuk'], $masuk));
        $psw = max(0, JamKerja::selisihMenit($jam['pulang'], $pulang));
        $potongan = self::tarifKeterlambatan($tl, $config) + self::tarifKeterlambatan($psw, $config);
        if (($input['lkh'] ?? false) === true) $potongan += $config->tarif('LKH');
        return new HasilHarian('hadir', $potongan, null, $tl, $psw);
    }

    /** @param array<string, mixed> $cuti */
    private static function cuti(array $cuti): HasilHarian
    {
        $jenis = strtolower((string) ($cuti['jenis'] ?? ''));
        if (in_array($jenis, ['tahunan', 'alasan_penting'], true)) return new HasilHarian('cuti', 0, $jenis);
        if ($jenis === 'sakit') return new HasilHarian('cuti', (int) ($cuti['hari_sakit_berurutan'] ?? 0) > 14 ? 1.0 : 0.0, $jenis);
        if (in_array($jenis, ['besar', 'melahirkan'], true)) {
            return new HasilHarian('cuti', match ((int) ($cuti['bulan_ke'] ?? 0)) { 1 => 40.0, 2 => 70.0, default => 80.0 }, $jenis);
        }
        return new HasilHarian('cuti', 0, $jenis);
    }

    private static function tarifKeterlambatan(int $menit, KonfigurasiTukin $config): float
    {
        return match (true) { $menit <= 0 => 0.0, $menit <= 30 => $config->tarif('TL1/PSW1'), $menit <= 60 => $config->tarif('TL2/PSW2'), default => $config->tarif('TL3/PSW3') };
    }
}
