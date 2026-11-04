<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\StatusHarian;

/**
 * MAKE-062 (bagian murni) — hasil keputusan status harian satu pegawai (PRD Fase 6 §7.9, F6-20, CR-098).
 * `statusBaru` null = tidak ada perubahan. Tiga penanda INFO tetap dilaporkan walau status tidak berubah.
 */
final class KeputusanStatusHarian
{
    public const ALASAN_TB_AKTIF   = 'tb_aktif';
    public const ALASAN_CLTN_AKTIF = 'cltn_aktif';
    public const ALASAN_PULIH_TB   = 'pulih_tb';
    public const ALASAN_PULIH_CLTN = 'pulih_cltn';

    public function __construct(
        public readonly ?int $statusBaru = null,
        public readonly ?string $alasan = null,
        public readonly bool $bentrok = false,
        public readonly bool $celahTb = false,
        public readonly bool $tanpaRiwayat = false,
    ) {
    }

    public function berubah(): bool
    {
        return $this->statusBaru !== null;
    }
}
