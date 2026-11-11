<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Interaksi\FormDigital;

use App\Exceptions\ValidationException;
use DateTimeImmutable;

/**
 * Validasi jawaban pegawai terhadap skema form (F-06, PRD Fase 7 §5.7). Legacy tidak memvalidasi jawaban di server
 * (`controllers/hr/Announcement.php:495-520` menyimpan `$_POST` apa adanya); v2 menolak dengan 422 per field
 * `jawaban.<idPertanyaan>`:
 * - kunci yang tidak ada di skema;
 * - pertanyaan wajib yang kosong;
 * - tipe salah, panjang teks melebihi `maks`, opsi di luar daftar, tanggal bukan Y-m-d, angka di luar rentang.
 *
 * Pertanyaan bertipe berkas tidak dinilai dari JSON jawaban: pemanggil mengirim daftar id pertanyaan yang berkasnya diunggah
 * (`$adaBerkas`), isi berkas diperiksa `ValidasiBerkas`.
 */
final class ValidatorJawaban
{
    private function __construct()
    {
    }

    /**
     * @param array{versi: int, pertanyaan: list<array<string, mixed>>} $skema kanonik
     * @param mixed                                                    $jawaban map id => nilai
     * @param list<string>                                             $adaBerkas id pertanyaan berkas yang diunggah
     *
     * @return array<string, mixed> jawaban bersih (tanpa pertanyaan berkas dan tanpa nilai kosong)
     *
     * @throws ValidationException
     */
    public static function periksa(array $skema, mixed $jawaban, array $adaBerkas = []): array
    {
        if ($jawaban === null || $jawaban === []) {
            $jawaban = [];
        }

        if (! is_array($jawaban) || ($jawaban !== [] && array_is_list($jawaban))) {
            throw ValidationException::forField('jawaban', 'Format jawaban tidak valid.');
        }

        $perId = [];

        foreach ($skema['pertanyaan'] as $p) {
            $perId[(string) $p['id']] = $p;
        }

        $galat = [];

        foreach (array_keys($jawaban) as $k) {
            if (! isset($perId[(string) $k])) {
                $galat['jawaban.' . $k] = ['Pertanyaan tidak dikenal.'];
            }
        }

        foreach ($adaBerkas as $k) {
            if (! isset($perId[$k]) || $perId[$k]['tipe'] !== 'berkas') {
                $galat['berkas_' . $k] = ['Berkas untuk pertanyaan yang tidak meminta berkas.'];
            }
        }

        $bersih = [];

        foreach ($perId as $id => $p) {
            $f     = 'jawaban.' . $id;
            $wajib = ($p['wajib'] ?? false) === true;

            if ($p['tipe'] === 'berkas') {
                if ($wajib && ! in_array($id, $adaBerkas, true)) {
                    $galat['berkas_' . $id] = ['Berkas wajib diunggah.'];
                }

                continue;
            }

            $v = $jawaban[$id] ?? null;

            if (self::kosong($v)) {
                if ($wajib) {
                    $galat[$f] = ['Wajib diisi.'];
                }

                continue;
            }

            $pesan = self::nilai($p, $v);

            if (is_string($pesan)) {
                $galat[$f] = [$pesan];

                continue;
            }

            $bersih[$id] = $pesan['nilai'];
        }

        if ($galat !== []) {
            throw new ValidationException('Jawaban tidak valid.', $galat);
        }

        return $bersih;
    }

    private static function kosong(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '') || $v === [];
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array{nilai: mixed}|string pesan galat
     */
    private static function nilai(array $p, mixed $v): array|string
    {
        switch ($p['tipe']) {
            case 'teks':
            case 'paragraf':
                if (! is_string($v)) {
                    return 'Harus berupa teks.';
                }

                $v = trim($v);

                return mb_strlen($v) > (int) $p['maks'] ? 'Maksimal ' . (int) $p['maks'] . ' karakter.' : ['nilai' => $v];

            case 'pilihan_tunggal':
                return is_string($v) && in_array($v, $p['opsi'], true) ? ['nilai' => $v] : 'Pilihan tidak ada di daftar.';

            case 'pilihan_ganda':
                if (! is_array($v) || ! array_is_list($v)) {
                    return 'Pilihan tidak valid.';
                }

                foreach ($v as $o) {
                    if (! is_string($o) || ! in_array($o, $p['opsi'], true)) {
                        return 'Pilihan tidak ada di daftar.';
                    }
                }

                return count(array_unique($v)) === count($v) ? ['nilai' => $v] : 'Pilihan tidak boleh ganda.';

            case 'tanggal':
                $d = is_string($v) ? DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;

                return $d !== false && $d->format('Y-m-d') === $v ? ['nilai' => $v] : 'Tanggal tidak valid (format YYYY-MM-DD).';

            case 'angka':
                if (is_string($v) && is_numeric(trim($v))) {
                    $v = trim($v) + 0;
                }

                if (! is_int($v) && ! is_float($v)) {
                    return 'Harus berupa angka.';
                }

                if (isset($p['min']) && $v < $p['min']) {
                    return 'Minimal ' . $p['min'] . '.';
                }

                if (isset($p['maks_angka']) && $v > $p['maks_angka']) {
                    return 'Maksimal ' . $p['maks_angka'] . '.';
                }

                return ['nilai' => $v];
        }

        return 'Tipe pertanyaan tidak dikenal.';
    }
}
