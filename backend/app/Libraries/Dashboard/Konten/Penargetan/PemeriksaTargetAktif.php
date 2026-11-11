<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Penargetan;

use CodeIgniter\Database\BaseConnection;

/**
 * Memastikan token unit/satker pada penargetan popup/banner/form digital MASUK API merujuk unit/satker yang ada dan aktif
 * (CR-119, temuan QA Lapis 1 T-07; pesan setara berita `BeritaService::targetKolom`). {@see SpesifikasiTarget} hanya memeriksa
 * format token. Hanya untuk jalur tulis lewat API: data tersimpan ({@see SpesifikasiTarget::dariKolom()}) dan impor legacy tidak
 * diperiksa. Pegawai eksplisit (`pegawai`/`kecuali`) sengaja tidak diperiksa di sini (tanpa filter status, PRD v2.1 §11-6 [K]).
 */
final class PemeriksaTargetAktif
{
    public const PESAN_UNIT = 'Unit target tidak ditemukan atau tidak aktif.';

    public const PESAN_SATKER = 'Satker target tidak ditemukan atau tidak aktif.';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return string|null pesan galat untuk field `target`, atau null bila semua token unit/satker ada dan berstatus 1
     */
    public function periksa(SpesifikasiTarget $spes): ?string
    {
        $unit   = array_values(array_unique($spes->idUnit()));
        $satker = array_values(array_unique($spes->idSatker()));

        if ($unit !== [] && $this->hitungAktif('unit', 'id_unit', $unit) !== count($unit)) {
            return self::PESAN_UNIT;
        }

        if ($satker !== [] && $this->hitungAktif('satker', 'id_satker', $satker) !== count($satker)) {
            return self::PESAN_SATKER;
        }

        return null;
    }

    /**
     * @param list<int> $id
     */
    private function hitungAktif(string $tabel, string $kolom, array $id): int
    {
        return (int) $this->db->table($tabel)->whereIn($kolom, $id)->where('status', 1)->countAllResults();
    }
}
