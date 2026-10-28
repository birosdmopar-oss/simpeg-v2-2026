<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\Presensi\TukinData\SumberCutiTbBelumTersedia;
use App\Libraries\Presensi\TukinData\SumberCutiTbInterface;
use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi Modul D Presensi & Remunerasi paket 5A (PRD `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md`).
 * Berkas milik 5A (PRD §3.1); tiap milestone menambah propertinya secara aditif (satu blok per milestone).
 */
class Presensi extends BaseConfig
{
    // --- M3 impor Slip Gaji (MAKE-030) ---
    // Override `.env` (opsional): `presensi.maksUkuranImporByte`, `presensi.maksBarisImporPerSheet`,
    // `presensi.ttlPratinjauDetik` — hanya bilangan bulat positif yang sah; nilai lain diabaikan dan bawaan dipakai
    // (pola `Config\Konket::daftarDariEnv`).

    /** Batas ukuran berkas impor Slip Gaji (PRD 5A §7.2.1, Q5A-05 default). */
    public int $maksUkuranImporByte = 5 * 1024 * 1024;

    /** Batas baris data per sheet impor (Q5A-05 default). */
    public int $maksBarisImporPerSheet = 10000;

    /** Masa berlaku pratinjau impor dalam detik (Q5A-05 default 30 menit). */
    public int $ttlPratinjauDetik = 1800;

    /** Direktori berkas pratinjau impor (di bawah `writable/`, tidak publik). */
    public string $direktoriPratinjau = WRITEPATH . 'uploads/slip-impor/';

    // --- M4 akses slip (MAKE-031) ---

    /**
     * Akar penyimpanan aset v2 untuk path relatif `web_config` tipe ASSET_PATH (logo kop PDF slip,
     * `App\Libraries\Presensi\Slip\KopSlip`). Override `.env` `presensi.akarAset`: hanya path direktori absolut tanpa
     * segmen `..`; nilai lain diabaikan dan bawaan dipakai. Akar final ditetapkan saat cutover bersama G-09.
     */
    public string $akarAset = WRITEPATH . 'aset/';

    /**
     * Implementasi sumber uang makan slip N−1 (`App\Libraries\Presensi\Slip\UangMakanSlipInterface`, PRD 5A §7.3.7).
     * Bawaan 5A = `UangMakanBelumTersedia` (selalu null); 5B mengganti dengan implementasi D-09 — **wajib sebelum go-live**
     * (F5-1). Nama kelas divalidasi saat dipakai (`SlipGajiService`).
     */
    public string $sumberUangMakan = 'App\Libraries\Presensi\Slip\UangMakanBelumTersedia';

    /**
     * Awalan URL verifikasi QR slip; URL QR = awalan + token (PRD 5A §7.3.6, Q5A-21). Bawaan = `App::$baseURL` +
     * `verify/slip/` (setara `site_url` legacy). Override `.env` `presensi.urlVerifikasiSlip`: hanya URL absolut http/https;
     * nilai lain diabaikan dan bawaan dipakai. Selalu diakhiri `/`.
     */
    public string $urlVerifikasiSlip = '';

    // --- M5 presensi online & manual (MAKE-032) ---

    /** Jendela presensi masuk, jam lokal satker, inklusif [K PO-4] (PRD 5A §7.5.5; tanpa override `.env`). */
    public string $jamMasukMulai = '04:00:00';

    public string $jamMasukSelesai = '12:30:59';

    /** Jendela presensi pulang, jam lokal satker, inklusif [K PO-4]. */
    public string $jamPulangMulai = '12:31:00';

    public string $jamPulangSelesai = '23:59:59';

    /**
     * `pengguna.id_pengguna` akun khusus presensi manual [K `config/constants.php:324-325`, K5-11] (PRD 5A §7.6.1).
     * Override `.env` `presensi.akunPresensiManual = 4675,4676`: daftar bilangan bulat positif dipisah koma; kosong / ada
     * token tidak sah → seluruhnya diabaikan dan bawaan dipakai (pola `Config\Konket::daftarDariEnv`).
     *
     * @var list<int>
     */
    public array $akunPresensiManual = [4675, 4676];

    // --- M6 adapter Tukin (MAKE-033) ---

    /**
     * Sumber data cuti & tugas belajar harian untuk adapter Tukin (PRD 5A §7.7.6). Bawaan 5A = fail-closed
     * (`DataTukinBelumTersedia`); 5B mengganti nilai ini dengan implementasi berbasis data Fase 4. Nama kelas
     * divalidasi saat dipakai (`TukinDataService::sumberCutiTb()`: wajib mengimplementasikan {@see SumberCutiTbInterface}).
     */
    public string $sumberCutiTb = SumberCutiTbBelumTersedia::class;

    // --- 5B M1 sumber cuti/TB nyata (MAKE-039) ---

    /**
     * Jumlah NIP per batch jalur massal Tukin (`TukinDataService::siapkanBanyak`, PRD 5B §7.1.4, §7.3.8): jumlah kueri
     * tetap per batch. Override `.env` `presensi.tukinBatchNip` (bilangan bulat positif; nilai lain diabaikan).
     */
    public int $tukinBatchNip = 500;

    public function __construct()
    {
        parent::__construct();

        $this->maksUkuranImporByte    = self::angkaPositifDariEnv('presensi.maksUkuranImporByte', $this->maksUkuranImporByte);
        $this->maksBarisImporPerSheet = self::angkaPositifDariEnv('presensi.maksBarisImporPerSheet', $this->maksBarisImporPerSheet);
        $this->ttlPratinjauDetik      = self::angkaPositifDariEnv('presensi.ttlPratinjauDetik', $this->ttlPratinjauDetik);
        $this->tukinBatchNip          = self::angkaPositifDariEnv('presensi.tukinBatchNip', $this->tukinBatchNip);

        $this->akarAset = self::direktoriAbsolut($this->akarAset) ?? WRITEPATH . 'aset/';

        $this->urlVerifikasiSlip = self::urlAbsolut($this->urlVerifikasiSlip)
            ?? rtrim((string) config(App::class)->baseURL, '/') . '/verify/slip/';

        $this->akunPresensiManual = Konket::daftarDariEnv('presensi.akunPresensiManual', [4675, 4676]);
    }

    /**
     * URL absolut http/https (diakhiri `/`), atau null bila kosong / bukan URL absolut http(s).
     */
    public static function urlAbsolut(mixed $nilai): ?string
    {
        if (! is_string($nilai) || trim($nilai) === '') {
            return null;
        }

        $nilai = trim($nilai);
        $skema = strtolower((string) parse_url($nilai, PHP_URL_SCHEME));

        if (! in_array($skema, ['http', 'https'], true) || filter_var($nilai, FILTER_VALIDATE_URL) === false || (string) parse_url($nilai, PHP_URL_HOST) === '') {
            return null;
        }

        return rtrim($nilai, '/') . '/';
    }

    /**
     * Bilangan bulat positif dari variabel lingkungan `$kunci`; tidak ada / kosong / tidak sah → `$bawaan`.
     */
    public static function angkaPositifDariEnv(string $kunci, int $bawaan): int
    {
        $nilai = env($kunci);

        return self::angkaPositif($nilai) ?? $bawaan;
    }

    public static function angkaPositif(mixed $nilai): ?int
    {
        if (is_int($nilai)) {
            return $nilai > 0 ? $nilai : null;
        }

        if (! is_string($nilai) || preg_match('/^[1-9][0-9]{0,9}\z/', trim($nilai)) !== 1) {
            return null;
        }

        $angka = (int) trim($nilai);

        return $angka <= 2147483647 ? $angka : null;
    }

    /**
     * Path direktori absolut yang sah (diakhiri `/`), atau null bila kosong / relatif / memuat `..` / karakter kendali.
     */
    public static function direktoriAbsolut(mixed $nilai): ?string
    {
        if (! is_string($nilai)) {
            return null;
        }

        $nilai = trim($nilai);

        if ($nilai === '' || preg_match('/[\x00-\x1F]/', $nilai) === 1
            || preg_match('#^(/|[A-Za-z]:[\\\/])#', $nilai) !== 1
            || preg_match('#(^|[\\\/])\.\.([\\\/]|$)#', $nilai) === 1) {
            return null;
        }

        return rtrim($nilai, '\\/') . '/';
    }
}
