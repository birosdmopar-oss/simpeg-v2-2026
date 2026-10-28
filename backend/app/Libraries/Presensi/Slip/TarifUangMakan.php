<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\Kepegawaian\Riwayat\Definisi\Jabatan;
use App\Libraries\MasterData\WebConfigService;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use CodeIgniter\Database\BaseConnection;

/**
 * Tarif uang makan per hari (rupiah, string desimal) untuk bulan kehadiran — port `Lsl_gaji::_getTarifUangMakan` legacy
 * `0cf7e67` (`libraries/hr/Lsl_gaji.php:390-414`) [K], PRD 5B §7.2.3:
 *
 * - PPPK (`pegawai.id_jenis_pegawai` = `Jabatan::JENIS_PEGAWAI_PPPK`, F5-24): `riwayat_mutasi_jabatan` `status = 1`,
 *   `jenis_jabatan = 1`, `tmtsk` ≤ tanggal 1 bulan kehadiran, terbaru (`tmtsk DESC`, seri → id terbesar [V2]) →
 *   `id_gol_pppk` → `gol_pppk.uang_makan` (DOUBLE, apa adanya);
 * - lainnya: `riwayat_kp` `status = 1`, `tmtsk` ≤ tanggal 1, terbaru → `trim(gol)` ∈ {I, II, III, IV} → `web_config`
 *   `uang_makan_gol_1..4` lewat `WebConfigService` (tipe INTEGER; nilai tersimpan tak sah dibaca service sebagai bawaan
 *   katalog = kosong); golongan berbentuk lain, mis. "IV/a", tidak cocok [K];
 * - golongan lain / tanpa baris / tarif belum diatur / tarif ≤ 0 → `'0'` (PTT praktis 0 [K/I], F5B-19).
 *
 * Total uang makan = {@see self::totalSen()}: tarif × hari dibulatkan setengah-menjauhi-nol ke sen, dihitung secara
 * desimal eksak dari string (tanpa float, tanpa ekstensi BCMath).
 *
 * **[CATAT] Keputusan Horii 28-10-2026 — tarif `gol_pppk.uang_makan` yang tidak bulat sampai sen** (menggantikan usulan
 * awal M2: galat `DataUangMakanTidakSah` → sumber `null`, yang DITOLAK):
 *
 * | v2 dipakai | Legacy [K] bagaimana (berkas:baris) | Gap |
 * |---|---|---|
 * | Ikut legacy: total = tarif × hari apa adanya, lalu dibulatkan **eksplisit** setengah-menjauhi-nol ke 2 desimal, sama dengan konversi MySQL ke kolom `gaji_pegawai.uang_makan` DECIMAL(14,2) (DBV-023); hitungan desimal eksak dari string | `(float) $gp['uang_makan']` (`Lsl_gaji.php:404`), `$result['total'] = $hari * $tarif` float (`:381`), ditulis ke `gaji_pegawai.uang_makan` (`:193`) sehingga pembulatan ke 2 desimal terjadi **implisit** di kolom DB | v2 deterministik. Pada tarif tepat setengah sen (`.xx5`) hasil legacy bisa selisih 1 sen dari v2 karena galat representasi float — gap diterima (v2 = hitungan eksak). Tidak ada galat/`null`; tarif lebih dari 2 desimal tetap dicatat log warning tanpa nominal sebagai jejak data master |
 */
final class TarifUangMakan
{
    public const KUNCI_WEB_CONFIG = ['I' => 'uang_makan_gol_1', 'II' => 'uang_makan_gol_2', 'III' => 'uang_makan_gol_3', 'IV' => 'uang_makan_gol_4'];

    private readonly BaseConnection $db;

    private readonly WebConfigService $webConfig;

    public function __construct(?BaseConnection $db = null, ?WebConfigService $webConfig = null)
    {
        $this->db        = $db ?? db_connect();
        $this->webConfig = $webConfig ?? service('webConfigService');
    }

    /**
     * Tarif rupiah per hari sebagai string desimal non-negatif tanpa tanda (mis. `'37000'`, `'37000.333'`); `'0'` bila
     * tidak ada tarif.
     */
    public function tarifRupiah(string $nip, PeriodeSlip $kehadiran): string
    {
        $tanggal1 = sprintf('%04d-%02d-01', $kehadiran->tahun, $kehadiran->bulan);
        $pegawai  = $this->db->table('pegawai')->select('id_jenis_pegawai')->where('nip', $nip)->get()->getRowArray();

        if (! is_array($pegawai)) {
            return '0';
        }

        if ((int) ($pegawai['id_jenis_pegawai'] ?? 0) === Jabatan::JENIS_PEGAWAI_PPPK) {
            return $this->tarifPppk($nip, $tanggal1);
        }

        $kp = $this->db->table('riwayat_kp')
            ->select('gol')
            ->where('nip', $nip)
            ->where('status', 1)
            ->where('tmtsk <=', $tanggal1)
            ->orderBy('tmtsk', 'DESC')
            ->orderBy('id_riwayat_kp', 'DESC')
            ->get(1)
            ->getRowArray();

        $kunci = self::KUNCI_WEB_CONFIG[trim((string) ($kp['gol'] ?? ''))] ?? null;

        return $kunci === null ? '0' : self::positif($this->webConfig->values()[$kunci] ?? null);
    }

    /**
     * Tarif rupiah × hari → sen, dibulatkan setengah-menjauhi-nol ke 2 desimal (setara konversi MySQL ke DECIMAL(14,2)).
     * Desimal eksak: perkalian digit string × bilangan bulat kecil, tanpa float.
     */
    public static function totalSen(string $tarifRupiah, int $hari): int
    {
        if ($hari <= 0 || preg_match('/^(\d+)(?:\.(\d+))?$/', $tarifRupiah, $m) !== 1) {
            return 0;
        }

        $pecahan = $m[2] ?? '';
        $digit   = ltrim($m[1] . $pecahan, '0');

        if ($digit === '') {
            return 0;
        }

        $hasil  = '';
        $simpan = 0;

        for ($i = strlen($digit) - 1; $i >= 0; $i--) {
            $nilai  = (int) $digit[$i] * $hari + $simpan;
            $hasil  = ($nilai % 10) . $hasil;
            $simpan = intdiv($nilai, 10);
        }

        $hasil = ($simpan > 0 ? (string) $simpan : '') . $hasil;
        $skala = strlen($pecahan);

        if ($skala <= 2) {
            return (int) ($hasil . str_repeat('0', 2 - $skala));
        }

        $buang = $skala - 2;
        $hasil = str_pad($hasil, $skala + 1, '0', STR_PAD_LEFT);

        return (int) substr($hasil, 0, -$buang) + ((int) $hasil[strlen($hasil) - $buang] >= 5 ? 1 : 0);
    }

    /**
     * Rupiah dari DB/config → string desimal non-negatif; ≤ 0, kosong, atau bukan angka → `'0'`. Float dikonversi dengan
     * 14 digit bermakna (sama dengan konversi float → string PHP legacy, `precision = 14`).
     */
    public static function positif(mixed $rupiah): string
    {
        if (is_int($rupiah)) {
            $teks = (string) $rupiah;
        } elseif (is_float($rupiah) && is_finite($rupiah)) {
            $teks = sprintf('%.14G', $rupiah);

            if (stripos($teks, 'E') !== false) {
                $teks = rtrim(rtrim(sprintf('%.14F', $rupiah), '0'), '.');
            }
        } elseif (is_string($rupiah)) {
            $teks = trim($rupiah);
        } else {
            return '0';
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $teks, $m) !== 1 || $m[1] === '-') {
            return '0';
        }

        $bulat   = ltrim($m[2], '0');
        $pecahan = rtrim($m[3] ?? '', '0');

        if ($bulat === '' && $pecahan === '') {
            return '0';
        }

        return ($bulat === '' ? '0' : $bulat) . ($pecahan === '' ? '' : '.' . $pecahan);
    }

    private function tarifPppk(string $nip, string $tanggal1): string
    {
        $jabatan = $this->db->table('riwayat_mutasi_jabatan')
            ->select('id_gol_pppk')
            ->where('nip', $nip)
            ->where('status', 1)
            ->where('jenis_jabatan', 1)
            ->where('tmtsk <=', $tanggal1)
            ->orderBy('tmtsk', 'DESC')
            ->orderBy('id_riwayat_mutasi_jabatan', 'DESC')
            ->get(1)
            ->getRowArray();

        $idGol = $jabatan['id_gol_pppk'] ?? null;

        if ($idGol === null || $idGol === '') {
            return '0';
        }

        $gol = $this->db->table('gol_pppk')->select('uang_makan')->where('id_gol_pppk', $idGol)->get()->getRowArray();

        if (! is_array($gol)) {
            return '0';
        }

        $tarif = self::positif($gol['uang_makan']);

        if (preg_match('/\.\d{3,}$/', $tarif) === 1) {
            log_message('warning', 'Tarif uang makan gol_pppk id {id} lebih dari dua desimal; total dibulatkan ke sen.', ['id' => (string) $idGol]);
        }

        return $tarif;
    }
}
