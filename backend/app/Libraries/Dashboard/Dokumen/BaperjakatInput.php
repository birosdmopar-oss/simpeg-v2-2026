<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Dokumen;

use App\Exceptions\ValidationException;

/**
 * Validasi body cetak Baperjakat (PRD Fase 7 F7-21, §7.2.6): `nip[]` 1..2000 unik, `judul` wajib ≤ 150, `subjudul`
 * ≤ 150, `teks` ≤ 1000. Kalkulasi murni; keberadaan/lingkup NIP diperiksa service (NIP bermasalah → 422 `errors.nip`,
 * maks 20 ditampilkan). Legacy: form Judul/Sub Judul/Text + checkbox (`views/hr/document/baperjakat.php:13-40`).
 */
final class BaperjakatInput
{
    public const NIP_MAKS = 2000;

    public const JUDUL_MAKS = 150;

    public const TEKS_MAKS = 1000;

    public const NIP_DITAMPILKAN = 20;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{nip: list<string>, judul: string, subjudul: string, teks: string}
     */
    public static function dari(array $body): array
    {
        $errors = [];
        $nipRaw = $body['nip'] ?? null;
        $nip    = [];

        if (! is_array($nipRaw) || $nipRaw === []) {
            $errors['nip'] = ['Pilih minimal satu pegawai.'];
        } else {
            foreach ($nipRaw as $v) {
                if (! is_string($v) && ! is_int($v)) {
                    $errors['nip'] = ['Daftar NIP harus berisi teks.'];
                    break;
                }

                $s = trim((string) $v);

                if ($s === '' || mb_strlen($s) > 30) {
                    $errors['nip'] = ['NIP tidak valid.'];
                    break;
                }

                $nip[] = $s;
            }

            $nip = array_values(array_unique($nip));

            if (! isset($errors['nip']) && count($nip) > self::NIP_MAKS) {
                $errors['nip'] = ['Maksimal ' . self::NIP_MAKS . ' pegawai per dokumen.'];
            }
        }

        $judul    = self::teks($body, 'judul', self::JUDUL_MAKS, true, $errors);
        $subjudul = self::teks($body, 'subjudul', self::JUDUL_MAKS, false, $errors);
        $teks     = self::teks($body, 'teks', self::TEKS_MAKS, false, $errors);

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }

        return ['nip' => $nip, 'judul' => $judul, 'subjudul' => $subjudul, 'teks' => $teks];
    }

    /**
     * Pesan 422 untuk NIP yang tidak ada / nonaktif / di luar lingkup (maks NIP_DITAMPILKAN disebut).
     *
     * @param list<string> $bermasalah
     */
    public static function galatNip(array $bermasalah): ValidationException
    {
        $tampil = array_slice($bermasalah, 0, self::NIP_DITAMPILKAN);
        $sisa   = count($bermasalah) - count($tampil);

        return new ValidationException('Validasi gagal.', ['nip' => [
            'NIP tidak ditemukan, nonaktif, atau di luar lingkup Anda: ' . implode(', ', $tampil) . ($sisa > 0 ? " (+{$sisa} lagi)" : '') . '.',
        ]]);
    }

    /**
     * @param array<string, mixed>              $body
     * @param array<string, list<string>>       $errors
     */
    private static function teks(array $body, string $field, int $maks, bool $wajib, array &$errors): string
    {
        $v = $body[$field] ?? null;

        if ($v === null || $v === '') {
            if ($wajib) {
                $errors[$field] = ['Isian wajib diisi.'];
            }

            return '';
        }

        if (! is_string($v)) {
            $errors[$field] = ['Isian harus berupa teks.'];

            return '';
        }

        $v = trim($v);

        if ($wajib && $v === '') {
            $errors[$field] = ['Isian wajib diisi.'];
        } elseif (mb_strlen($v) > $maks) {
            $errors[$field] = ["Maksimal {$maks} karakter."];
        }

        return $v;
    }
}
