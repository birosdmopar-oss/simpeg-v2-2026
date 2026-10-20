<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\DataPegawaiTukin;
use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * `DataPegawaiTukin` dari data v2 (PRD 5A §7.7.5) mengikuti rekap legacy `laporan_tukin_us_skp` [K]:
 *
 * - `predikatSkp` = `riwayat_skp_periodik.hasil_akhir` (huruf kecil) baris `status = 1`, `periode_id` = id baris
 *   `bkn_periode_ekinper` bulan itu, `hasil_akhir IS NOT NULL` (`L_presensi.php:12397-12401`); tanpa baris BKN atau
 *   tanpa data → null.
 * - `jumlahAnak` = jumlah `detail_anak` ber-`tgl_lahir` ≤ akhir periode milik `riwayat_keluarga` `status = 1` dengan
 *   `tgl_perkawinan` ≤ akhir periode (:12170-12198).
 * - `tmtMasuk` (pegawai baru, :12699-12716; sumber data `L_employee::get_all_pegawai_dh_spe`): CPNS — `gol_ruang`
 *   (lebih dari 5 karakter, diawali "CPNS") dari `riwayat_kp` `status = 1` terakhir dengan `tmtsk` ≤ awal periode →
 *   `tmtsk`; PPPK (`id_jenis_pegawai = 6`) — `tmtsk` jabatan `riwayat_mutasi_jabatan` `status = 1`,
 *   `jenis_jabatan = 1` terakhir dengan `tmtsk` ≤ akhir periode, bila setelah hari kerja pertama periode; selain itu
 *   null. **Menunggu konfirmasi reviewer CR (eskalasi 20-10-2026)**: sumber kolom & pengenal PPPK lewat id [V2].
 * - `tmtKeluar` = `pegawai.tmt_status` bila `pegawai.status = 2` [V2 README Tukin].
 */
final class SumberDataPegawaiTukin
{
    public const ID_JENIS_PPPK = 6;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<string> $hariLibur dipakai menentukan hari kerja pertama periode (PPPK)
     */
    public function untuk(string $nip, PeriodeTukin $periode, ?string $idPeriodeBkn, array $hariLibur = []): DataPegawaiTukin
    {
        $pegawai = $this->db->table('pegawai')
            ->select(['status', 'tmt_status', 'id_jenis_pegawai'])
            ->where('nip', $nip)
            ->get()
            ->getRowArray();

        if (! is_array($pegawai)) {
            throw new DataTukinTidakSah("Pegawai {$nip} tidak ditemukan.");
        }

        try {
            return new DataPegawaiTukin(
                $this->predikatSkp($nip, $idPeriodeBkn),
                $this->jumlahAnak($nip, $periode->akhir),
                $this->tmtMasuk($nip, $pegawai, $periode, $hariLibur),
                (int) $pegawai['status'] === 2 && $pegawai['tmt_status'] !== null ? (string) $pegawai['tmt_status'] : null,
            );
        } catch (InvalidArgumentException $e) {
            throw new DataTukinTidakSah("Data pegawai {$nip} untuk Tukin tidak sah: " . $e->getMessage(), 0, $e);
        }
    }

    public function predikatSkp(string $nip, ?string $idPeriodeBkn): ?string
    {
        if ($idPeriodeBkn === null) {
            return null;
        }

        $baris = $this->db->table('riwayat_skp_periodik')
            ->select('hasil_akhir')
            ->where('nip', $nip)
            ->where('status', 1)
            ->where('periode_id', $idPeriodeBkn)
            ->where('hasil_akhir IS NOT NULL', null, false)
            ->orderBy('id_riwayat_skp_periodik', 'ASC')
            ->get(1)
            ->getRowArray();

        return is_array($baris) ? strtolower((string) $baris['hasil_akhir']) : null;
    }

    public function jumlahAnak(string $nip, string $akhir): int
    {
        $baris = $this->db->table('detail_anak da')
            ->select('COUNT(*) AS jumlah')
            ->join('riwayat_keluarga rk', 'rk.id_riwayat_keluarga = da.id_riwayat_keluarga')
            ->where('rk.nip', $nip)
            ->where('rk.status', 1)
            ->where('rk.tgl_perkawinan <=', $akhir)
            ->where('da.tgl_lahir <=', $akhir)
            ->get()
            ->getRowArray();

        return (int) ($baris['jumlah'] ?? 0);
    }

    /**
     * @param array<string, mixed> $pegawai
     * @param list<string>         $hariLibur
     */
    public function tmtMasuk(string $nip, array $pegawai, PeriodeTukin $periode, array $hariLibur): ?string
    {
        if ((int) ($pegawai['id_jenis_pegawai'] ?? 0) === self::ID_JENIS_PPPK) {
            $jabatan = $this->db->table('riwayat_mutasi_jabatan')
                ->select('tmtsk')
                ->where('nip', $nip)
                ->where('status', 1)
                ->where('jenis_jabatan', 1)
                ->where('tmtsk <=', $periode->akhir)
                ->orderBy('tmtsk', 'DESC')
                ->orderBy('id_riwayat_mutasi_jabatan', 'DESC')
                ->get(1)
                ->getRowArray();
            $tmt     = is_array($jabatan) ? (string) $jabatan['tmtsk'] : '';
            $pertama = HariKerja::daftar($periode, $hariLibur)[0] ?? null;

            return $tmt !== '' && $pertama !== null && $tmt > $pertama ? $tmt : null;
        }

        $kp = $this->db->table('riwayat_kp')
            ->select(['gol_ruang', 'tmtsk'])
            ->where('nip', $nip)
            ->where('status', 1)
            ->where('tmtsk <=', $periode->awal)
            ->orderBy('tmtsk', 'DESC')
            ->orderBy('id_riwayat_kp', 'DESC')
            ->get(1)
            ->getRowArray();

        if (! is_array($kp)) {
            return null;
        }

        $gol = (string) ($kp['gol_ruang'] ?? '');

        return strlen($gol) > 5 && strtoupper(substr($gol, 0, 4)) === 'CPNS' ? (string) $kp['tmtsk'] : null;
    }
}
