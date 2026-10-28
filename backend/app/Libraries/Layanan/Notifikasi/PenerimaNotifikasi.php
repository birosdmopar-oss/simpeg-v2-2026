<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Notifikasi;

use CodeIgniter\Database\BaseConnection;

/**
 * Penentu penerima notifikasi cuti yang memerlukan DB (PRD Fase 4 §6.6.2): ketua tim (tembusan) dan admin role 1 aktif.
 * Hanya membaca; tidak menyentuh tabel ticketing.
 */
class PenerimaNotifikasi
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * NIP ketua tim yang di-CC pada pengajuan (`riwayat_cuti_notif_kt`).
     *
     * @return list<string>
     */
    public function ketuaTim(int $idCuti): array
    {
        $baris = $this->db->table('riwayat_cuti_notif_kt')->select('nip_kt')->where('id_riwayat_cuti', $idCuti)->get()->getResultArray();

        return array_map('strval', array_column($baris, 'nip_kt'));
    }

    /**
     * NIP semua akun role 1 yang aktif dan belum dihapus. Akun role 1 tanpa NIP (bukan pegawai) tidak bisa menerima
     * `inbox` (kunci penerima = NIP) dan dilewati.
     *
     * @return list<string>
     */
    public function adminAktif(): array
    {
        $baris = $this->db->table('pengguna')
            ->select('nip')
            ->where('user_level', 1)
            ->where('status', '1')
            ->where('deleted_at', null)
            ->where('nip IS NOT NULL', null, false)
            ->get()->getResultArray();

        return array_values(array_unique(array_map('strval', array_column($baris, 'nip'))));
    }
}
