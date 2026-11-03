<?php

declare(strict_types=1);

namespace App\Libraries\Bkn\Ekinerja;

/**
 * Parser tanggal respons BKN yang KETAT (PRD Fase 6 BP-10, jawaban B0 Q6): hanya `YYYY-MM-DD[ HH:MM:SS]` dan
 * `DD-MM-YYYY[ HH:MM:SS]` (pemisah `-`, jam opsional, detik wajib bila jam ada); tanggal harus ada di kalender. Selain itu
 * `null` → baris ditolak pemanggil + dicatat. Legacy menulis string mentah ke kolom DATE/DATETIME (`EB:276-294`).
 */
final class ParserTanggalBkn
{
    /**
     * @return string|null `Y-m-d` (atau `Y-m-d H:i:s` bila `$denganJam` dan masukan berjam); null bila tidak sah
     */
    public static function urai(string $nilai, bool $denganJam = false): ?string
    {
        $nilai = trim($nilai);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}):(\d{2}))?$/', $nilai, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{2})-(\d{2})-(\d{4})(?: (\d{2}):(\d{2}):(\d{2}))?$/', $nilai, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        if (! checkdate($mo, $d, $y) || $y < 1900) {
            return null;
        }

        $tanggal = sprintf('%04d-%02d-%02d', $y, $mo, $d);

        if (! isset($m[4]) || $m[4] === '') {
            return $denganJam ? $tanggal . ' 00:00:00' : $tanggal;
        }

        [$h, $i, $s] = [(int) $m[4], (int) $m[5], (int) $m[6]];

        if ($h > 23 || $i > 59 || $s > 59) {
            return null;
        }

        return $denganJam ? sprintf('%s %02d:%02d:%02d', $tanggal, $h, $i, $s) : $tanggal;
    }
}
