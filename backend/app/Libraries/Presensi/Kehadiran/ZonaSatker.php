<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Kehadiran;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Waktu lokal presensi per pegawai (PRD 5A §7.5.2, F5-11, PO-1..PO-3 [K]): satker = `pegawai_mutasi_jabatan.id_satker`
 * jabatan saat ini → `satker.zonasi` (menit) dibaca langsung tanpa filter status satker (G-02 catatan). Tanpa jabatan/satker
 * → zonasi 0 [K] (Q5A-24). Waktu lokal = waktu Asia/Jakarta + zonasi menit bila > 0 [K PO-2]; disimpan apa adanya (bukan
 * UTC). Waktu dari klien tidak pernah dipakai — "sekarang" disuntik pemanggil (service memakai jam server).
 */
final class ZonaSatker
{
    public const ZONA = 'Asia/Jakarta';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Zonasi (menit) satker jabatan saat ini pegawai; 0 bila tidak ada jabatan/satker.
     */
    public function zonasi(string $nip): int
    {
        $row = $this->db->table('pegawai_mutasi_jabatan pmj')
            ->select('s.zonasi')
            ->join('satker s', 's.id_satker = pmj.id_satker')
            ->where('pmj.nip', $nip)
            ->get(1)->getRowArray();

        $zonasi = (int) ($row['zonasi'] ?? 0);

        return $zonasi > 0 ? $zonasi : 0;
    }

    /**
     * Waktu lokal satker dari instan `$sekarang` (zona apa pun).
     */
    public static function waktuLokal(DateTimeInterface $sekarang, int $zonasi): DateTimeImmutable
    {
        $wib = DateTimeImmutable::createFromInterface($sekarang)->setTimezone(new DateTimeZone(self::ZONA));

        return $zonasi > 0 ? $wib->modify("+{$zonasi} minutes") : $wib;
    }
}
