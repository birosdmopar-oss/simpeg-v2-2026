<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\Kepegawaian\Riwayat\Definisi\Jabatan;
use App\Libraries\MasterData\WebConfigService;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use CodeIgniter\Database\BaseConnection;

/**
 * Tarif uang makan per hari (sen) untuk bulan kehadiran — port `Lsl_gaji::_getTarifUangMakan` legacy `0cf7e67`
 * (`libraries/hr/Lsl_gaji.php:390-414`) [K], PRD 5B §7.2.3:
 *
 * - PPPK (`pegawai.id_jenis_pegawai` = `Jabatan::JENIS_PEGAWAI_PPPK`, F5-24): `riwayat_mutasi_jabatan` `status = 1`,
 *   `jenis_jabatan = 1`, `tmtsk` ≤ tanggal 1 bulan kehadiran, terbaru (`tmtsk DESC`, seri → id terbesar [V2]) →
 *   `id_gol_pppk` → `gol_pppk.uang_makan`;
 * - lainnya: `riwayat_kp` `status = 1`, `tmtsk` ≤ tanggal 1, terbaru → `trim(gol)` ∈ {I, II, III, IV} → `web_config`
 *   `uang_makan_gol_1..4` lewat `WebConfigService` (golongan berbentuk lain, mis. "IV/a", tidak cocok [K]);
 * - golongan lain / tanpa baris / tarif belum diatur → 0 (PTT praktis 0 [K/I], F5B-19).
 *
 * Rupiah → sen secara eksak (integer); nilai `gol_pppk.uang_makan` (DOUBLE) yang tidak bulat sampai sen →
 * {@see DataUangMakanTidakSah} (pesan tanpa nominal). Tarif `web_config` (tipe INTEGER) sudah disaring `WebConfigService`
 * (nilai tersimpan tak sah dibaca sebagai bawaan katalog = kosong → 0); cabang galatnya hanya pertahanan.
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
     * @throws DataUangMakanTidakSah
     */
    public function tarifSen(string $nip, PeriodeSlip $kehadiran): int
    {
        $tanggal1 = sprintf('%04d-%02d-01', $kehadiran->tahun, $kehadiran->bulan);
        $pegawai  = $this->db->table('pegawai')->select('id_jenis_pegawai')->where('nip', $nip)->get()->getRowArray();

        if (! is_array($pegawai)) {
            return 0;
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

        if ($kunci === null) {
            return 0;
        }

        $nilai = $this->webConfig->values()[$kunci] ?? null;

        if ($nilai === null || $nilai === '') {
            return 0;
        }

        if (is_int($nilai) || (is_string($nilai) && preg_match('/^\d+$/', trim($nilai)) === 1)) {
            return max(0, (int) $nilai) * 100;
        }

        throw new DataUangMakanTidakSah("Tarif uang makan {$kunci} di Web Config bukan rupiah bulat.");
    }

    /**
     * @throws DataUangMakanTidakSah
     */
    private function tarifPppk(string $nip, string $tanggal1): int
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
            return 0;
        }

        $gol = $this->db->table('gol_pppk')->select('uang_makan')->where('id_gol_pppk', $idGol)->get()->getRowArray();

        if (! is_array($gol)) {
            return 0;
        }

        return self::sen($gol['uang_makan'], "gol_pppk id {$idGol}");
    }

    /**
     * Rupiah (DOUBLE dari DB) → sen eksak; ≤ 0 → 0; lebih dari dua desimal → galat.
     *
     * @throws DataUangMakanTidakSah
     */
    public static function sen(mixed $rupiah, string $sumber): int
    {
        $teks = is_float($rupiah) ? rtrim(rtrim(sprintf('%.6F', $rupiah), '0'), '.') : trim((string) $rupiah);

        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $teks, $m) !== 1) {
            throw new DataUangMakanTidakSah("Tarif uang makan {$sumber} tidak sah (harus rupiah dengan paling banyak dua desimal).");
        }

        $sen = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? 0 : $sen;
    }
}
