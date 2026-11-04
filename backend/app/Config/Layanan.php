<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\Layanan\Pmk\AturanPmk;
use App\Libraries\Layanan\Studi\AturanStudi;
use CodeIgniter\Config\BaseConfig;
use RuntimeException;

/**
 * Konstanta layanan TB, IB, PMK Modul C (PRD Fase 4 v1.1 §6.7–§6.9, Q-23; MAKE-024 bagian murni). Pola `Config\Cuti`:
 * nilai di config, tanpa override `.env` (perubahan aturan lewat rilis yang direview) — kecuali
 * {@see self::$urlPublikSurat} (alamat server, MAKE-065).
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

    /**
     * Dasar URL publik surat cuti (MAKE-065, PRD Fase 6 v1.0f Q6-22/Q6-23; review B4a P5) — satu-satunya nilai di kelas ini
     * yang di-override `.env` (`layanan.urlPublikSurat`), karena berbeda per server. URL di QR surat = dasar +
     * `verify/surat-cuti/{token}` (kaki surat) dan dasar + `api/v1/publik/surat-cuti/{token}/pdf` (tanda tangan); URL ini
     * DIBEKUKAN permanen di PDF ber-TTE, jadi divalidasi ketat lewat {@see self::urlPublikSurat()}.
     */
    public string $urlPublikSurat = '';

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

    /**
     * Dasar URL publik surat cuti, diakhiri `/`. Gagal-cepat (pesan menyebut kunci, tanpa nilai) bila: diisi tetapi bukan URL
     * absolut http/https ({@see Presensi::urlAbsolut()}); kosong di production (wajib eksplisit); bukan `https` di
     * production. Kosong di luar production → `App::$baseURL`.
     *
     * @throws RuntimeException
     */
    public function urlPublikSurat(?string $lingkungan = null): string
    {
        $lingkungan ??= ENVIRONMENT;
        $isian = trim($this->urlPublikSurat);

        if ($isian === '') {
            if ($lingkungan === 'production') {
                throw new RuntimeException('layanan.urlPublikSurat wajib diisi di production (URL publik QR surat cuti).');
            }

            $isian = (string) config(App::class)->baseURL;
        }

        $url = Presensi::urlAbsolut($isian)
            ?? throw new RuntimeException('layanan.urlPublikSurat harus URL absolut http/https.');

        if ($lingkungan === 'production' && strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('layanan.urlPublikSurat wajib https di production.');
        }

        return $url;
    }
}
