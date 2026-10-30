<?php

declare(strict_types=1);

namespace App\Libraries\Waktu;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use Throwable;

/**
 * Audit dan normalisasi baris v2 yang `created_at`/`updated_at`-nya terisi `DEFAULT CURRENT_TIMESTAMP` (zona SESI database,
 * mis. WIB di dev) sebelum CR-086, padahal kontrak proyek: kolom audit DATETIME berisi UTC dan diisi aplikasi.
 *
 * Baris itu tidak dapat dibedakan otomatis dari baris yang sudah UTC (impor R1a DBV-011 sudah mengonversi WIB → UTC; GAP-2:
 * zona server legacy menunggu DBV), karena itu normalisasi **wajib** dibatasi jendela waktu eksplisit `[dari, sampai]` atas
 * nilai `created_at` tersimpan: awal pemakaian tulis v2 (sesudah impor) s.d. waktu CR-086 dideploy. Hanya tabel di daftar
 * {@see self::TABEL} (penulis yang dulu tidak mengisi stempel); `updated_at` ikut digeser hanya bila sama persis dengan
 * `created_at` (baris belum pernah diubah) — selainnya tak dapat dipastikan dan hanya dihitung ("updated_at_tak_pasti").
 */
final class NormalisasiStempel
{
    /** @var array<string, list<string>> tabel => kolom stempel yang ada */
    public const TABEL = [
        'riwayat_tb'        => ['created_at', 'updated_at'],
        'riwayat_ib'        => ['created_at', 'updated_at'],
        'riwayat_tb_perpan' => ['created_at'],
        'riwayat_pmk_req'   => ['created_at', 'updated_at'],
        'riwayat_pmk'       => ['created_at', 'updated_at'],
        'riwayat_lckh'      => ['created_at', 'updated_at'],
        'petugas_layanan'   => ['created_at', 'updated_at'],
        'pa_layanan'        => ['created_at', 'updated_at'],
        'pegawai_plh'       => ['created_at', 'updated_at'],
        'pegawai_plt'       => ['created_at', 'updated_at'],
        'pegawai_sisa_cuti' => ['created_at', 'updated_at'],
        'absen_ijin'        => ['created_at', 'updated_at'],
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Ringkasan per tabel tanpa data pribadi: total baris, rentang `created_at`, dan jumlah baris bertanggal masa depan
     * terhadap UTC sekarang (pasti bukan UTC: tertulis zona sesi di timur UTC).
     *
     * @return array<string, array{total: int, terkecil: ?string, terbesar: ?string, masa_depan: int}>
     */
    public function audit(string $sekarangUtc): array
    {
        $hasil = [];

        foreach (array_keys(self::TABEL) as $tabel) {
            $t   = $this->db->protectIdentifiers($this->db->prefixTable($tabel), true, null, false);
            $row = $this->db->query(
                "SELECT COUNT(*) AS total, MIN(created_at) AS terkecil, MAX(created_at) AS terbesar, COALESCE(SUM(created_at > ?), 0) AS masa_depan FROM {$t}",
                [$sekarangUtc],
            )->getRowArray() ?? [];

            $hasil[$tabel] = [
                'total'      => (int) ($row['total'] ?? 0),
                'terkecil'   => $row['terkecil'] ?? null,
                'terbesar'   => $row['terbesar'] ?? null,
                'masa_depan' => (int) ($row['masa_depan'] ?? 0),
            ];
        }

        return $hasil;
    }

    /**
     * Geser baris dalam jendela `created_at ∈ [dari, sampai]` mundur `$offsetJam` jam (zona sesi → UTC).
     *
     * @return array{tabel: string, terkena: int, updated_at_ikut: int, updated_at_tak_pasti: int, dry_run: bool}
     */
    public function normalisasi(string $tabel, string $dari, string $sampai, int $offsetJam, bool $dryRun): array
    {
        $kolom = self::TABEL[$tabel] ?? throw new InvalidArgumentException("Tabel '{$tabel}' tidak ada di daftar normalisasi.");

        foreach ([$dari, $sampai] as $batas) {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $batas) !== 1) {
                throw new InvalidArgumentException('Jendela waktu harus berformat "Y-m-d H:i:s" (UTC tersimpan).');
            }
        }

        if ($dari > $sampai) {
            throw new InvalidArgumentException('--dari tidak boleh sesudah --sampai.');
        }

        if ($offsetJam < 1 || $offsetJam > 14) {
            throw new InvalidArgumentException('--offset (jam) harus 1..14.');
        }

        $t       = $this->db->protectIdentifiers($this->db->prefixTable($tabel), true, null, false);
        $terkena = (int) ($this->db->query("SELECT COUNT(*) AS n FROM {$t} WHERE created_at BETWEEN ? AND ?", [$dari, $sampai])->getRowArray()['n'] ?? 0);
        $punyaUp = in_array('updated_at', $kolom, true);
        $ikut    = 0;
        $tak     = 0;

        if ($punyaUp) {
            $ikut = (int) ($this->db->query("SELECT COUNT(*) AS n FROM {$t} WHERE created_at BETWEEN ? AND ? AND updated_at = created_at", [$dari, $sampai])->getRowArray()['n'] ?? 0);
            $tak  = (int) ($this->db->query("SELECT COUNT(*) AS n FROM {$t} WHERE created_at BETWEEN ? AND ? AND updated_at IS NOT NULL AND updated_at <> created_at", [$dari, $sampai])->getRowArray()['n'] ?? 0);
        }

        if (! $dryRun && $terkena > 0) {
            $this->db->transStart();

            try {
                // Urutan SET penting (MySQL mengevaluasi kiri → kanan): updated_at dibandingkan dengan created_at ASLI dulu.
                $set = $punyaUp ? "updated_at = IF(updated_at = created_at, created_at - INTERVAL {$offsetJam} HOUR, updated_at), " : '';
                $this->db->query("UPDATE {$t} SET {$set}created_at = created_at - INTERVAL {$offsetJam} HOUR WHERE created_at BETWEEN ? AND ?", [$dari, $sampai]);
            } catch (Throwable $e) {
                $this->db->transRollback();

                throw $e;
            }

            $this->db->transComplete();
        }

        return ['tabel' => $tabel, 'terkena' => $terkena, 'updated_at_ikut' => $ikut, 'updated_at_tak_pasti' => $tak, 'dry_run' => $dryRun];
    }
}
