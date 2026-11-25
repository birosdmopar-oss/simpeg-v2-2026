<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\Presensi\TukinData\SumberCutiTbFase4;
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

    // --- Pengaman memori/waktu pembaca impor Slip Gaji (CR-177) ---
    // Konstanta, bukan setelan `.env`: ini pengaman server, bukan aturan bisnis. Rincian dan angka ukur lengkap di README
    // `Libraries/Presensi` (BB-1..BB-11). Acuan ukur (PHP 8.4, PhpSpreadsheet 5.10): template resmi terisi penuh = 10.000
    // baris GPP 26 kolom + TK 10 kolom = 360.036 sel, berkas 1,5 MB, isi 12,4 MB, 780.461 elemen XML + 440.502 atribut.
    // Membacanya: ±8 detik, puncak memori PHP +214 MB (kena `memory_limit`), dan puncak RSS proses ±612 MB, karena pohon
    // XML libxml yang dibangun PhpSpreadsheet berada DI LUAR `memory_limit`. Kasus sah terberat yang masih lolos anggaran
    // (390.039 sel teks pendek `t="s"`): PHP +258 MB (fatal pada 256M), RSS ±925 MB. Jadi server impor wajib
    // `memory_limit` ≥ 512M dan RAM bebas ±1 GB per impor yang berjalan bersamaan (README-deploy §1).

    /**
     * Batas kolom sheet GPP/TK, diperiksa sesudah muat. Template resmi 26 kolom (GPP: nip, bulan, tahun, nama + 22
     * nominal) dan 9–10 kolom (TK); kolom tak dikenal dari ekspor aplikasi gaji diabaikan penguraian, jadi tetap diberi
     * ruang. 256 = lebar penuh `.xls`, sehingga `.xls` tidak pernah ditolak karena kolom, dan pembaca paling banyak
     * menyusun (batas baris + 1) × 256 nilai per sheet, bukan × 16.384 (lebar `.xlsx`).
     */
    public const MAKS_KOLOM_IMPOR = 256;

    /**
     * Batas jumlah sel per berkas = (batas baris + 1) × nilai ini, dihitung dari semua sheet `.xlsx` (atau baris × kolom
     * GPP + TK pada `.xls`). 40 = 36 kolom template (GPP 26 + TK 10) + margin ±11%: bawaan 400.040 sel. Mengikuti
     * `$maksBarisImporPerSheet` bila dinaikkan lewat `.env`, supaya tidak diam-diam tertahan di sini (memorinya ikut naik:
     * ±0,6 KB PHP per sel); tidak pernah di bawah hitungan untuk {@see self::BARIS_ACUAN_ANGGARAN_IMPOR}.
     */
    public const SEL_PER_BARIS_IMPOR = 40;

    /**
     * Batas baris terkecil yang dipakai untuk menghitung anggaran sel (= bawaan `$maksBarisImporPerSheet`). Anggaran adalah
     * pengaman memori, jadi batas baris yang diturunkan tidak ikut mengecilkannya: berkas dengan sheet tambahan tetap
     * diterima, dan kelebihan baris tetap dilaporkan sebagai kelebihan baris.
     */
    public const BARIS_ACUAN_ANGGARAN_IMPOR = 10000;

    /**
     * Batas bobot struktur XML `.xlsx` = batas sel × nilai ini; bobot = jumlah semua elemen + 2 × jumlah semua atribut di
     * semua entri (atribut ±2× lebih mahal dari elemen di libxml). Template penuh tulisan PhpSpreadsheet berbobot 4,6
     * per sel; tulisan Excel (sel ber-atribut gaya) ±7 per sel. 8 → bawaan 3.200.320. Terukur di batas ini (berkas yang
     * isinya hanya elemen pengisi): puncak RSS ±840 MB; 390.039 sel teks pendek `t="s"`: ±925 MB (±1,5× template penuh). Tanpa batas ini 32 MB isi masih
     * mengizinkan ±8 juta elemen (RSS ±2 GB).
     */
    public const BOBOT_PER_SEL_IMPOR = 8;

    /**
     * Batas jumlah langkah perulangan definisi kolom (`<col min max>`) `.xlsx`: PhpSpreadsheet mengulang `min..max` untuk
     * setiap `<col>`. Berkas sah puluhan sampai ±16 ribu langkah (satu `<col>` selebar lembar); 1.000 `<col>` selebar
     * 16.383 = 16 juta langkah (16,5 detik). `min`/`max` tidak sah dihitung tak hingga.
     */
    public const MAKS_ITERASI_KOLOM_IMPOR = 200_000;

    /**
     * Batas jumlah `<tablePart>` (tabel Excel) `.xlsx`: PhpSpreadsheet mengurai ulang relasi sheet untuk setiap
     * `<tablePart>` (berkas 14 KB berisi 1.000 tablePart: 157 detik). Berkas gaji sah: nol sampai beberapa.
     */
    public const MAKS_TABEL_IMPOR = 50;

    /**
     * Batas jumlah ukuran semua entri `.xlsx` sesudah dekompresi. Dipakai juga sebagai batas byte teks yang disalin
     * pemuat per sel (shared string yang dirujuk sel + teks rumus shared × sel turunannya). Template penuh = 12,4 MB;
     * dengan 30 kolom tambahan 22,1 MB. 32 MB memberi ruang ±2,5× untuk berkas tulisan Excel (XML lebih boros), dan menutup berkas yang mengembang
     * saat dibuka (zip bomb, XML berisi pengisi teks, sharedStrings raksasa): pemuat menampung setiap part utuh sebagai
     * string sebelum diurai.
     */
    public const MAKS_BYTE_ISI_IMPOR = 32 * 1024 * 1024;

    // --- Anggaran beban hitung rumus impor Slip Gaji (CR-181) ---
    // Sel rumus GPP/TK tetap dihitung ulang (legacy, AC-D10.9); yang dibatasi adalah perkiraan kerjanya, ditaksir dari teks
    // rumus sebelum dihitung (`App\Libraries\Presensi\Slip\PenaksirBebanRumus`). Satu satuan beban ±5 mikrodetik hitung di
    // mesin ukur saat tenang (±2× saat sibuk). Tabel ukur dan alasan angka: README `Libraries/Presensi` BB-12.

    /**
     * Beban tetap satu sel rumus. Beban dasar sel = nilai ini + rujukan sel × `BEBAN_RUJUKAN…` + operator ×
     * `BEBAN_OPERATOR…` + kurung buka × `BEBAN_FUNGSI…` + pemisah argumen × `BEBAN_ARGUMEN…` + panjang² / pembagi di
     * bawah, semuanya dihitung dari karakter teks rumus (`=C2` terukur ±56 mikrodetik, `=C2-D2` ±87,
     * `=ROUND(IF(C2>0,C2*0.05+D2*0.1-E2,0),0)` ±330, PPh progresif 154 karakter ±600; tabel README BB-12).
     */
    public const BEBAN_DASAR_RUMUS_IMPOR = 8;

    /** Beban tiap rujukan sel di teks rumus (huruf diikuti angka; mengambil nilai sel ±30 mikrodetik). */
    public const BEBAN_RUJUKAN_RUMUS_IMPOR = 6;

    /** Beban tiap karakter operator (`+ - * / ^ & < > = %` dan spasi) di teks rumus. */
    public const BEBAN_OPERATOR_RUMUS_IMPOR = 2;

    /** Beban tiap kurung buka (pemanggilan fungsi) di teks rumus. */
    public const BEBAN_FUNGSI_RUMUS_IMPOR = 7;

    /** Beban tiap pemisah argumen (`,` dan `;`) di teks rumus. */
    public const BEBAN_ARGUMEN_RUMUS_IMPOR = 4;

    /**
     * Bagian kuadratik beban dasar = panjang² / nilai ini: biaya urai kalkulator tumbuh kuadratik menurut panjang teks
     * (1.750 karakter ±16 ms, 8.050 karakter ±76 ms), dan penaksir mengurai rumus berentang sekali lagi.
     */
    public const PEMBAGI_KUADRAT_PANJANG_RUMUS_IMPOR = 3_000;

    /** Panjang teks rumus terbesar = batas Excel; lebih panjang hanya bisa berasal dari berkas buatan → ditolak. */
    public const MAKS_PANJANG_RUMUS_IMPOR = 8_192;

    /** Beban tetap tiap rentang yang dijabarkan (operator `:` terukur ±100 mikrodetik walau rentangnya kecil). */
    public const BEBAN_TETAP_RENTANG_RUMUS_IMPOR = 20;

    /**
     * Beban satu rentang = beban tetap + baris × kolom + baris² × (kolom + 1) / (2 × nilai ini). Kalkulator menjabarkan
     * rentang menjadi daftar sel dan biayanya tumbuh kuadratik menurut jumlah baris (`SUM` satu kolom: 2.500 baris
     * 21 ms, 5.000 baris 55 ms, 10.000 baris 146 ms; 24 kolom × 10.000 baris 2,1 detik).
     */
    public const PEMBAGI_KUADRAT_BARIS_RUMUS_IMPOR = 6_000;

    /**
     * Pembagi suku kuadratik larik argumen `SUBTOTAL` (satu kolom: 2.500 baris 36 ms, 5.000 baris 128 ms, 10.000 baris
     * 350 ms) dan `LOOKUP` (review R3: dua vektor 2.500–10.000 baris per satuan setara `VLOOKUP`).
     */
    public const PEMBAGI_KUADRAT_BARIS_SEDANG_RUMUS_IMPOR = 2_000;

    /**
     * Pembagi suku kuadratik larik argumen fungsi selain yang terukur lebih murah: `MAX`, `MATCH`, `COUNTIF`,
     * `SUMPRODUCT` dan kebanyakan fungsi lain meratakan lariknya dengan biaya kuadratik ±7× `SUM` (`MAX` satu kolom:
     * 2.500 baris 42 ms, 5.000 baris 169 ms, 10.000 baris 653 ms; 24 kolom × 10.000 baris 5,9 detik).
     */
    public const PEMBAGI_KUADRAT_BARIS_UMUM_RUMUS_IMPOR = 900;

    /** Sel sheet rujukan per satuan beban untuk memangkas rentang kolom/baris penuh (`J:J`): kalkulator memindai indeks sel sheet itu. */
    public const SEL_PER_BEBAN_PINDAI_RUMUS_IMPOR = 200;

    /** Byte teks hasil `REPT` per satuan beban (tiap hasil disimpan ±3 salinan sampai impor selesai). */
    public const BYTE_PER_BEBAN_TEKS_RUMUS_IMPOR = 4;

    /**
     * Panjang teks terkecil yang dianggap untuk operand yang bukan literal teks (rujukan sel, hasil fungsi). Yang dipakai
     * penaksir = nilai ini atau teks terpanjang di workbook (sel bernilai teks dan literal teks di rumus), mana yang
     * lebih besar.
     */
    public const BYTE_TEKS_ANGGAPAN_RUMUS_IMPOR = 32;

    /**
     * Byte teks terpanjang workbook per satuan beban, ditagihkan untuk TIAP kurung buka, operator, dan elemen larik:
     * fungsi teks, pembandingan, dan pencarian menyalin atau mengubah huruf seluruh teks operandnya (teks 32.767 huruf:
     * `PROPER` ±1,1 ms, `=` ±0,8 ms, `UPPER` ±0,55 ms, `MATCH` ±1 ms per sel yang dibandingkan). Workbook tanpa teks
     * 128 byte atau lebih tidak terkena.
     */
    public const BYTE_PER_BEBAN_TEKS_SEL_RUMUS_IMPOR = 128;

    /**
     * Batas atas byte hasil `SUBSTITUTE` (teks sumber + kemunculan × teks pengganti, ditaksir dari teks terpanjang
     * workbook dan literal di rumus): kalkulator menyusun hasilnya dulu, baru memeriksa batas 32.767 huruf, jadi hasil
     * sementara puluhan MB menghabiskan memori. Lebih besar dari ini → ditolak.
     */
    public const MAKS_BYTE_HASIL_TEKS_RUMUS_IMPOR = 4 * 1024 * 1024;

    /**
     * CR-181 R4 (B2): `TEXT` menafsirkan kode formatnya dengan biaya kuadratik menurut panjang format (format 32.000 `?`
     * ±0,43 detik per panggilan). Tiap panggilan ditagih panjang format² / nilai ini.
     */
    public const PEMBAGI_KUADRAT_FORMAT_TEKS_RUMUS_IMPOR = 12_000;

    /** Panjang kode format `TEXT` terbesar = batas Excel (255 karakter); format yang pasti lebih panjang → ditolak. */
    public const MAKS_PANJANG_FORMAT_TEKS_RUMUS_IMPOR = 255;

    /**
     * CR-181 R4 (B3): `SEARCH`/`FIND` (`mb_stripos`/`mb_strpos`) pada kasus terburuk ±byte teks cari × byte teks sumber
     * (15.000 × 30.000 byte ±0,1 detik per panggilan). Tiap panggilan ditagih hasil kali itu / nilai ini.
     */
    public const PEMBAGI_CARI_TEKS_RUMUS_IMPOR = 15_000;

    /** Beban tetap tiap sel yang dicocokkan dengan kriteria berwildcard `*` (±15 mikrodetik di atas kriteria biasa). */
    public const BEBAN_TETAP_WILDCARD_RUMUS_IMPOR = 4;

    /**
     * Langkah penelusuran balik pencocokan wildcard per satuan beban. Kriteria ber-`*` dijadikan pola `.*` berantai;
     * langkahnya ±panjang teks pangkat jumlah `*` / faktorial jumlah `*` (teks 30 huruf: 4 bintang ±45 mikrodetik per
     * sel, 5 bintang ±160, 7 ke atas ±2,7 ms saat mesin PCRE berhenti di `pcre.backtrack_limit` bawaan 1.000.000).
     */
    public const LANGKAH_PER_BEBAN_WILDCARD_RUMUS_IMPOR = 2_000;

    /** Beban terbesar pencocokan wildcard per sel (mesin PCRE berhenti di batas penelusuran balik, ±2,7 ms). */
    public const MAKS_BEBAN_WILDCARD_RUMUS_IMPOR = 600;

    /**
     * Byte teks per satuan beban untuk hasil operator `&` (teks sementara, dikalikan jumlah elemen bila operandnya
     * larik): batas per sel × nilai ini = ±128 MB teks.
     */
    public const BYTE_PER_BEBAN_TEKS_LARIK_RUMUS_IMPOR = 256;

    /**
     * Batas beban SATU sel rumus: membatasi memori satu larik (±0,1 KB per elemen hasil operator, ±0,55 KB per baris
     * rentang) dan waktu satu sel (±3–4 detik). Cukup untuk `INDEX(GPP!A2:Z10001,MATCH(…,GPP!A2:A10001,0),26)` (±606
     * ribu) dan `VLOOKUP` ke GPP 10.000 baris × 40 kolom (±742 ribu); `MAX` atau `MATCH` bernilai cari larik atas seluruh
     * GPP (1,4–2,1 juta) tetap ditolak.
     */
    public const MAKS_BEBAN_PER_RUMUS_IMPOR = 800_000;

    /**
     * Batas jumlah beban semua sel rumus GPP + TK. Diukur saat mesin tenang (1 satuan ±3,2–5,3 mikrodetik, terburuk ±6):
     * 6 juta ≈ 36 detik hitung + taksir ±3 detik, di bawah batas waktu request impor 60 detik (tabel ukur BB-12).
     */
    public const MAKS_BEBAN_RUMUS_IMPOR = 6_000_000;

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
     * Bawaan 5B = `UangMakanSlipPresensi` (D-09a, MAKE-040; menutup blocker go-live F5-1); `UangMakanBelumTersedia`
     * (selalu null, bawaan 5A) tetap tersedia. Nama kelas divalidasi saat dipakai (`SlipGajiService`).
     */
    public string $sumberUangMakan = 'App\Libraries\Presensi\Slip\UangMakanSlipPresensi';

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
     * Sumber data cuti & tugas belajar harian untuk adapter Tukin (PRD 5A §7.7.6). 5B M1 (MAKE-039, PRD 5B §7.1.4):
     * `SumberCutiTbFase4` — data Fase 4 (`riwayat_cuti` status 1, TB lewat `statusTugasBelajar`), menggantikan bawaan 5A
     * yang fail-closed (`SumberCutiTbBelumTersedia`). Nama kelas divalidasi saat dipakai
     * (`TukinDataService::sumberCutiTb()`: wajib mengimplementasikan {@see SumberCutiTbInterface}).
     */
    public string $sumberCutiTb = SumberCutiTbFase4::class;

    // --- 5B M1 sumber cuti/TB nyata (MAKE-039) ---

    /**
     * Jumlah NIP per batch jalur massal Tukin (`TukinDataService::siapkanBanyak`, PRD 5B §7.1.4, §7.3.8): jumlah kueri
     * tetap per batch. Override `.env` `presensi.tukinBatchNip` (bilangan bulat positif; nilai lain diabaikan).
     */
    public int $tukinBatchNip = 500;

    // --- 5B M5 laporan Tukin (MAKE-041) ---

    /**
     * Batas baris PDF rekap Tukin (PRD 5B §7.3.5, F5B-16): awal 1.700 (ADR-034) diturunkan ke 800 menurut uji volume
     * ADR-035 (1.700 baris = 440 MB, di atas target 256 MB). Di atas batas → 422 `errors.unit_satker`. Override `.env`
     * `presensi.pdfRekapMaksBaris` (bilangan bulat positif).
     */
    public int $pdfRekapMaksBaris = 800;

    /**
     * Id unit yang penanda tangan rekapnya diambil dari **satker terpilih** (legacy hard-code `'6'`,
     * `L_presensi.php:12019`, `:12107`) dan yang kop PDF per pegawainya memakai nama/alamat satker (`:7106`). Keputusan
     * reviewer CR M0 F3: konstanta bernama + override `.env` `presensi.unitTtdSatkerTerpilih`; nilai produksi dicek saat
     * cutover (QC).
     */
    public int $unitTtdSatkerTerpilih = 6;

    /**
     * Id unit yang penanda tangan rekapnya diambil dari satker kepegawaian (`web_config` `id_satker_kepegawaian`), selain
     * unit `id_unit_kepegawaian` (legacy hard-code `'7'`, `L_presensi.php:12019`, `:12107`; kop PDF `:7110`). Override
     * `.env` `presensi.unitTtdSatkerKepegawaian`; dicek saat cutover (QC, F3).
     */
    public int $unitTtdSatkerKepegawaian = 7;

    public function __construct()
    {
        parent::__construct();

        $this->maksUkuranImporByte      = self::angkaPositifDariEnv('presensi.maksUkuranImporByte', $this->maksUkuranImporByte);
        $this->maksBarisImporPerSheet   = self::angkaPositifDariEnv('presensi.maksBarisImporPerSheet', $this->maksBarisImporPerSheet);
        $this->ttlPratinjauDetik        = self::angkaPositifDariEnv('presensi.ttlPratinjauDetik', $this->ttlPratinjauDetik);
        $this->tukinBatchNip            = self::angkaPositifDariEnv('presensi.tukinBatchNip', $this->tukinBatchNip);
        $this->pdfRekapMaksBaris        = self::angkaPositifDariEnv('presensi.pdfRekapMaksBaris', $this->pdfRekapMaksBaris);
        $this->unitTtdSatkerTerpilih    = self::angkaPositifDariEnv('presensi.unitTtdSatkerTerpilih', $this->unitTtdSatkerTerpilih);
        $this->unitTtdSatkerKepegawaian = self::angkaPositifDariEnv('presensi.unitTtdSatkerKepegawaian', $this->unitTtdSatkerKepegawaian);

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
