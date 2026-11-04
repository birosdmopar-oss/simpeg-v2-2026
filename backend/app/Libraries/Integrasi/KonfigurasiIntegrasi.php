<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi;

use App\Libraries\Integrasi\Http\OpsiTransport;
use InvalidArgumentException;

/**
 * Fail-fast konfigurasi driver real (PRD Fase 6 BP-2, AC-K.4): adapter/penyedia token memanggil
 * {@see self::pastikan()} di konstruktor sehingga konfigurasi kosong gagal SAAT DIBUAT (`service('…')`), bukan saat
 * dipanggil di tengah job. Pesan hanya menyebut NAMA kunci yang kosong, tidak pernah nilainya.
 */
final class KonfigurasiIntegrasi
{
    /**
     * @param array<string, mixed> $konfigurasi entri `Config\Integrations` satu layanan
     * @param list<string>         $wajib       kunci yang harus terisi (string tidak kosong setelah trim)
     *
     * @throws KonfigurasiIntegrasiTidakLengkap
     */
    public static function pastikan(string $layanan, array $konfigurasi, array $wajib): void
    {
        $kosong = [];

        foreach ($wajib as $kunci) {
            $nilai = $konfigurasi[$kunci] ?? null;

            if (! is_string($nilai) || trim($nilai) === '') {
                $kosong[] = $kunci;
            }
        }

        if ($kosong !== []) {
            throw new KonfigurasiIntegrasiTidakLengkap($layanan, $kosong);
        }
    }

    /**
     * Batas waktu & TLS dari konfigurasi dengan validasi ketat (AC-K.4) — nilai `.env` kosong/salah tidak boleh diam-diam
     * mematikan TLS (`(bool) ''` = false) atau memberi timeout 0.
     *
     * - `tlsVerify`: bool, atau teks `true`/`false`/`1`/`0`; tidak ada → true; lainnya ditolak. `$wajibTls` (BKN) menolak
     *   nilai false.
     * - `timeout`: angka detik > 0; tidak ada → 30; kosong/0/negatif/bukan angka ditolak.
     * - `caBundle`: kosong/tidak ada = CA sistem; terisi wajib berkas yang terbaca (fail-fast), dipakai bila `tlsVerify`.
     *
     * @param array<string, mixed> $konfigurasi
     *
     * @throws KonfigurasiIntegrasiTidakLengkap
     */
    public static function opsiTransport(string $layanan, array $konfigurasi, bool $wajibTls = false): OpsiTransport
    {
        $tls = array_key_exists('tlsVerify', $konfigurasi) ? self::bool($konfigurasi['tlsVerify']) : true;

        if ($tls === null) {
            throw new KonfigurasiIntegrasiTidakLengkap($layanan, ['tlsVerify'], 'tidak sah (true/false)');
        }

        if ($wajibTls && ! $tls) {
            throw new KonfigurasiIntegrasiTidakLengkap($layanan, ['tlsVerify'], 'wajib true untuk layanan ini');
        }

        $timeout = $konfigurasi['timeout'] ?? 30.0;

        if (! is_int($timeout) && ! is_float($timeout) && ! (is_string($timeout) && is_numeric(trim($timeout)))) {
            throw new KonfigurasiIntegrasiTidakLengkap($layanan, ['timeout'], 'tidak sah (angka detik > 0)');
        }

        $timeout = (float) $timeout;
        $ca      = $konfigurasi['caBundle'] ?? '';
        $ca      = is_string($ca) ? trim($ca) : null;

        if ($ca === null || ($ca !== '' && (! is_file($ca) || ! is_readable($ca)))) {
            throw new KonfigurasiIntegrasiTidakLengkap($layanan, ['caBundle'], 'tidak terbaca (path berkas CA di luar repo/webroot, kosong = CA sistem)');
        }

        try {
            return new OpsiTransport(min(5.0, $timeout), $timeout, $tls, $ca === '' ? null : $ca);
        } catch (InvalidArgumentException) {
            throw new KonfigurasiIntegrasiTidakLengkap($layanan, ['timeout'], 'tidak sah (angka detik > 0)');
        }
    }

    private static function bool(mixed $nilai): ?bool
    {
        if (is_bool($nilai)) {
            return $nilai;
        }

        if (is_int($nilai) && in_array($nilai, [0, 1], true)) {
            return $nilai === 1;
        }

        return match (is_string($nilai) ? strtolower(trim($nilai)) : null) {
            'true', '1'  => true,
            'false', '0' => false,
            default      => null,
        };
    }
}
