<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\Layanan\Cuti\AturanCuti;
use CodeIgniter\Config\BaseConfig;

/**
 * Konstanta cuti Modul C (PRD Fase 4 v1.1 §6.3.1; MAKE-022 Tahap 2b). Pola `Config\AngkaKredit`: nilai di config, tanpa
 * override `.env` (perubahan aturan lewat rilis yang direview).
 *
 * Nilai bawaan = D8 (12/6/6, total 24, utang 12) dan batas legacy [K]: alasan penting `+1 month` PHP
 * (`L_cuti.php:1666-1674`), CLTN `+3 year` (`L:1567-1575`), tunda sebelum `tgl_mulai` 12:00:00 (`Cuti.php:971-976`).
 *
 * Library murni `App\Libraries\Layanan\Cuti\*` tidak boleh membaca config (pemindai kemurnian menolak nama `Config`),
 * sehingga service ber-DB (M3) menyalurkan nilai ini lewat {@see self::aturan()} → value object `AturanCuti`.
 * `CutiMurniTest` menjaga bawaan di sini = `AturanCuti::bawaan()`.
 */
class Cuti extends BaseConfig
{
    /** Kuota cuti tahunan tahun berjalan (N). */
    public int $kuotaN = AturanCuti::KUOTA_N;

    /** Sisa maksimum yang boleh dibawa dari N-1. */
    public int $maksN1 = AturanCuti::MAKS_N1;

    /** Sisa maksimum yang boleh dibawa dari N-2. */
    public int $maksN2 = AturanCuti::MAKS_N2;

    /** Total N + N-1 + N-2 maksimum. */
    public int $maksTotal = AturanCuti::MAKS_TOTAL;

    /** Utang cuti (porsi tahun depan) maksimum. */
    public int $maksUtang = AturanCuti::MAKS_UTANG;

    /**
     * Jenis cuti yang lamanya dihitung hari kerja (1 Tahunan, 3 Sakit, 5 Alasan Penting).
     *
     * @var list<int>
     */
    public array $jenisHariKerja = AturanCuti::JENIS_HARI_KERJA;

    /**
     * Jenis cuti yang lamanya dihitung hari kalender (2 Besar, 4 Melahirkan, 6 CLTN).
     *
     * @var list<int>
     */
    public array $jenisHariKalender = AturanCuti::JENIS_HARI_KALENDER;

    /**
     * Pilihan durasi cuti Besar (bulan).
     *
     * @var list<int>
     */
    public array $durasiBesar = AturanCuti::DURASI_BESAR;

    /** Batas cuti alasan penting: `tgl_mulai` + n bulan PHP (inklusif). */
    public int $maksBulanAlasanPenting = AturanCuti::MAKS_BULAN_ALASAN_PENTING;

    /** Batas CLTN: `tgl_mulai` + n tahun PHP (inklusif). */
    public int $maksTahunCltn = AturanCuti::MAKS_TAHUN_CLTN;

    /** Jam batas tunda pengingat pada `tgl_mulai` (WIB), format `HH:MM`. */
    public string $jamBatasTunda = AturanCuti::JAM_BATAS_TUNDA;

    /**
     * Value object aturan untuk library murni.
     */
    public function aturan(): AturanCuti
    {
        return new AturanCuti(
            kuotaN: $this->kuotaN,
            maksN1: $this->maksN1,
            maksN2: $this->maksN2,
            maksTotal: $this->maksTotal,
            maksUtang: $this->maksUtang,
            jenisHariKerja: $this->jenisHariKerja,
            jenisHariKalender: $this->jenisHariKalender,
            durasiBesar: $this->durasiBesar,
            maksBulanAlasanPenting: $this->maksBulanAlasanPenting,
            maksTahunCltn: $this->maksTahunCltn,
            jamBatasTunda: $this->jamBatasTunda,
        );
    }
}
