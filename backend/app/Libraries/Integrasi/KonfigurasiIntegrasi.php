<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi;

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
}
