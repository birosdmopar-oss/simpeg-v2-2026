<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use App\Libraries\Kepegawaian\Riwayat\Definisi\Jabatan;
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
 * - `tmtMasuk` (pegawai baru; keputusan reviewer CR 20-10-2026, rincian di README folder ini):
 *   1. `gol_ruang`/`tmtsk_pangkat` [K `L_employee.php:6439`, `:6453`, `:6555-6560`]: `riwayat_kp` `status = 1` terakhir
 *      (`tmtsk DESC`) dengan `tmtsk` ≤ awal periode; bila tidak ada dan pegawai dibuat setelah akhir periode, batasnya
 *      ≤ akhir periode;
 *   2. PPPK (`id_jenis_pegawai` = `Jabatan::JENIS_PEGAWAI_PPPK`) [K `:6580-6584`]: nilai di atas ditimpa `gol_pppk`/`tmtsk`
 *      `riwayat_mutasi_jabatan` `status = 1`, `jenis_jabatan = 1` terakhir dengan `tmtsk` ≤ akhir periode
 *      (`:6478-6482`);
 *   3. rekap [K `L_presensi.php:12703-12716`]: `gol_ruang` lebih dari 5 karakter diawali "CPNS" → `tmtsk_pangkat`; PPPK →
 *      `tmtsk_pangkat` bila setelah hari kerja pertama periode (`$firstDayHK_CM`, via `HariKerja`); selain itu null.
 *      [V2] cabang PPPK rekap mengenali PPPK lewat id (legacy :12707 lewat nama `jenis_pegawai == 'PPPK'`): nama master
 *      dapat diubah lewat CRUD, cek nama bisa gagal diam-diam.
 * - `tmtKeluar` = `pegawai.tmt_status` bila `pegawai.status = 2` [V2 README Tukin].
 */
final class SumberDataPegawaiTukin
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<string> $hariLibur dipakai menentukan hari kerja pertama periode (PPPK)
     */
    public function untuk(string $nip, PeriodeTukin $periode, ?string $idPeriodeBkn, array $hariLibur = []): DataPegawaiTukin
    {
        $pegawai = $this->db->table('pegawai')
            ->select(['status', 'tmt_status', 'id_jenis_pegawai', 'created_at'])
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
     * @param array<string, mixed> $pegawai baris `pegawai` (id_jenis_pegawai, created_at)
     * @param list<string>         $hariLibur
     */
    public function tmtMasuk(string $nip, array $pegawai, PeriodeTukin $periode, array $hariLibur): ?string
    {
        [$golRuang, $tmt] = $this->pangkat($nip, $periode, (string) ($pegawai['created_at'] ?? ''));

        $pppk = (int) ($pegawai['id_jenis_pegawai'] ?? 0) === Jabatan::JENIS_PEGAWAI_PPPK;

        if ($pppk) {
            $jabatan = $this->db->table('riwayat_mutasi_jabatan')
                ->select(['gol_pppk', 'tmtsk'])
                ->where('nip', $nip)
                ->where('status', 1)
                ->where('jenis_jabatan', 1)
                ->where('tmtsk <=', $periode->akhir)
                ->orderBy('tmtsk', 'DESC')
                ->orderBy('id_riwayat_mutasi_jabatan', 'DESC')
                ->get(1)
                ->getRowArray();

            if (is_array($jabatan)) {
                $golRuang = (string) ($jabatan['gol_pppk'] ?? '');
                $tmt      = (string) ($jabatan['tmtsk'] ?? '');
            }
        }

        if (strlen($golRuang) > 5 && strtoupper(substr($golRuang, 0, 4)) === 'CPNS') {
            return $tmt !== '' ? $tmt : null;
        }

        if ($pppk && $tmt !== '') {
            $pertama = HariKerja::daftar($periode, $hariLibur)[0] ?? null;

            return $pertama !== null && $tmt > $pertama ? $tmt : null;
        }

        return null;
    }

    /**
     * `gol_ruang`/`tmtsk` `riwayat_kp` terakhir [K `L_employee.php:6439`, `:6453`].
     *
     * @return array{0: string, 1: string}
     */
    private function pangkat(string $nip, PeriodeTukin $periode, string $createdAt): array
    {
        $batas = [$periode->awal];

        // Pegawai dibuat setelah akhir periode: legacy mengisi pangkat yang belum ada dengan batas akhir periode (:6453).
        if ($createdAt !== '' && substr($createdAt, 0, 10) > $periode->akhir) {
            $batas[] = $periode->akhir;
        }

        foreach ($batas as $tanggal) {
            $kp = $this->db->table('riwayat_kp')
                ->select(['gol_ruang', 'tmtsk'])
                ->where('nip', $nip)
                ->where('status', 1)
                ->where('tmtsk <=', $tanggal)
                ->orderBy('tmtsk', 'DESC')
                ->orderBy('id_riwayat_kp', 'DESC')
                ->get(1)
                ->getRowArray();

            if (is_array($kp)) {
                return [(string) ($kp['gol_ruang'] ?? ''), (string) ($kp['tmtsk'] ?? '')];
            }
        }

        return ['', ''];
    }
}
