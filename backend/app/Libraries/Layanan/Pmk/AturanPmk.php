<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Pmk;

use InvalidArgumentException;

/**
 * MAKE-024 (bagian murni) — aturan pengajuan PMK sebagai value object murni (PRD Fase 4 v1.1 §6.9.1, Q-15 P-5, Q-23).
 * Sumber nilai runtime = `Config\Layanan::aturanPmk()`; {@see self::bawaan()} = nilai PRD.
 */
final readonly class AturanPmk
{
    /** Nilai `riwayat_pmk_req.jenis_pmk` [K DDL :6235 'Swasta'/'Negeri']; enum ditegakkan [V2 P-5]. */
    public const JENIS_PMK = ['Swasta', 'Negeri'];

    /** `reason_note` ≤ 255 byte [V2 Q-23; kolom [K] TINYTEXT]. */
    public const MAKS_BYTE_REASON = 255;

    /** `mker_th`/`mker_bl` TINYINT [K DDL]. */
    public const MAKS_MKER = 255;

    /**
     * @param list<string> $jenisPmk
     */
    public function __construct(
        public array $jenisPmk = self::JENIS_PMK,
        public int $maksByteReason = self::MAKS_BYTE_REASON,
        public int $maksMker = self::MAKS_MKER,
    ) {
        if ($jenisPmk === [] || $maksByteReason < 1 || $maksMker < 1) {
            throw new InvalidArgumentException('Aturan PMK tidak valid.');
        }
    }

    public static function bawaan(): self
    {
        return new self();
    }
}
