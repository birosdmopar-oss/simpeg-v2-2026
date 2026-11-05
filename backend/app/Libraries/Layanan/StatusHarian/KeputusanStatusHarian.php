<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\StatusHarian;

/**
 * MAKE-062 (bagian murni) — hasil keputusan status harian satu pegawai (PRD Fase 6 §7.9, F6-20, CR-098).
 * `statusBaru` null = tidak ada perubahan. Penanda INFO tetap dilaporkan walau status tidak berubah:
 *  - `bentrok`: TB dan CLTN aktif bersamaan (Q6-24);
 *  - `celahTb`: pemulihan dari TB padahal TB yang sama masih punya segmen yang BELUM mulai, yakni hari ini jatuh di celah
 *    antara akhir TB (atau perpanjangan) dan perpanjangan berikutnya;
 *  - `tanpaRiwayat`: status 2/3 tanpa riwayat pendukung yang sudah berakhir (tidak disentuh, Q6-26);
 *  - `segmenTidakSah`: ada segmen riwayat bertanggal tidak sah (dilewati).
 * `jenisRiwayat` ('tb'/'cuti') dan `idRiwayat` menunjuk riwayat penentu (yang aktif, atau yang sudah berakhir untuk
 * pemulihan) bila id-nya diberikan pemanggil; dipakai jejak audit.
 */
final class KeputusanStatusHarian
{
    public const ALASAN_TB_AKTIF   = 'tb_aktif';
    public const ALASAN_CLTN_AKTIF = 'cltn_aktif';
    public const ALASAN_PULIH_TB   = 'pulih_tb';
    public const ALASAN_PULIH_CLTN = 'pulih_cltn';

    public const RIWAYAT_TB   = 'tb';
    public const RIWAYAT_CUTI = 'cuti';

    public function __construct(
        public readonly ?int $statusBaru = null,
        public readonly ?string $alasan = null,
        public readonly bool $bentrok = false,
        public readonly bool $celahTb = false,
        public readonly bool $tanpaRiwayat = false,
        public readonly bool $segmenTidakSah = false,
        public readonly ?string $jenisRiwayat = null,
        public readonly ?int $idRiwayat = null,
    ) {
    }

    public function berubah(): bool
    {
        return $this->statusBaru !== null;
    }
}
