<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\Layanan\Pmk\AturanPmk;
use App\Libraries\Layanan\Studi\AturanStudi;
use CodeIgniter\Config\BaseConfig;

/**
 * Konstanta layanan TB, IB, PMK Modul C (PRD Fase 4 v1.1 §6.7–§6.9, Q-23; MAKE-024 bagian murni). Pola `Config\Cuti`:
 * nilai di config, tanpa override `.env` (perubahan aturan lewat rilis yang direview).
 *
 * Library murni `App\Libraries\Layanan\{Studi,Tb,Pmk}\*` tidak boleh membaca config (pemindai kemurnian), sehingga
 * service ber-DB (M5) menyalurkan nilai ini lewat {@see self::aturanStudi()} / {@see self::aturanPmk()}. `LayananMurniTest`
 * menjaga bawaan di sini = `AturanStudi::bawaan()` / `AturanPmk::bawaan()`.
 *
 * Hanya konstanta aturan. Nilai pejabat, kop, dan penanda tangan (`id_jabatan_kabiro_kepegawaian`, `id_unit_kepegawaian`,
 * `id_satker_kepegawaian`, `nama_kementerian`, `pdf_header_*`, dsb.) TIDAK di sini: dibaca lewat
 * `WebConfigService::value()` dari katalog `Config\WebConfig`, tanpa override `.env` (PRD v1.2 §6.4.1, Q-06(a) 27-10).
 */
class Layanan extends BaseConfig
{
    /** `id_jenjang_pendidikan` TB/IB harus lebih besar dari nilai ini (S1 ke atas). */
    public int $jenjangMinEksklusif = AturanStudi::JENJANG_MIN_EKSKLUSIF;

    /** Bawaan isian `up` (U.P. laporan) TB/IB. */
    public string $upBawaan = AturanStudi::UP_BAWAAN;

    /** Batas `reason_note` (byte) untuk TB, IB, PMK (Q-23). */
    public int $maksByteReason = AturanStudi::MAKS_BYTE_REASON;

    /**
     * Pilihan `jenis_pmk`.
     *
     * @var list<string>
     */
    public array $jenisPmk = AturanPmk::JENIS_PMK;

    /** Batas `mker_th`/`mker_bl` (TINYINT). */
    public int $maksMkerPmk = AturanPmk::MAKS_MKER;

    public function aturanStudi(): AturanStudi
    {
        return new AturanStudi(
            jenjangMinEksklusif: $this->jenjangMinEksklusif,
            upBawaan: $this->upBawaan,
            maksByteReason: $this->maksByteReason,
        );
    }

    public function aturanPmk(): AturanPmk
    {
        return new AturanPmk(
            jenisPmk: $this->jenisPmk,
            maksByteReason: $this->maksByteReason,
            maksMker: $this->maksMkerPmk,
        );
    }
}
