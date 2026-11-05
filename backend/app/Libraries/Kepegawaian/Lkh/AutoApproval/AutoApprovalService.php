<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lkh\AutoApproval;

use App\Libraries\Kepegawaian\Lkh\LkhService;
use CodeIgniter\Database\BaseConnection;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * MAKE-068 (lapisan DB) — auto-approval LKH bulan lalu (H-12, F6-25, PRD Fase 6 §7.10, ikut legacy `queue_aa_lkh` dan
 * `process_queue_aa_lkh`). Pemilihan: LKH status 0 dengan `tgl_laporan` di bulan yang diproses. Status 3 (revisi) dan
 * status lain tidak disentuh.
 *
 * Penulisan (BP-6, BP-7): transaksi kecil per LKH (cek `transBegin() === false`, `transStatus()`, commit/rollback,
 * `resetTransStatus()`); galat satu LKH dihitung di `gagal` dan tidak menghentikan yang lain. Run nyata dilindungi
 * `GET_LOCK` (tunggu 0; sibuk → {@see AutoApprovalSibuk}); dry-run tidak mengunci dan tidak menulis. Idempoten: LKH yang
 * sudah disetujui tidak lagi berstatus 0, jadi ulang tidak punya efek ganda.
 */
final class AutoApprovalService
{
    private readonly PenyetujuLkh $penyetuju;

    public function __construct(
        private readonly BaseConnection $db,
        ?PenyetujuLkh $penyetuju = null,
    ) {
        $this->penyetuju = $penyetuju ?? new PenyetujuLkh($db);
    }

    public static function namaKunci(BaseConnection $db): string
    {
        return 'lkh_auto_approval:' . substr(sha1($db->getDatabase() . '|' . $db->getPrefix()), 0, 16);
    }

    /**
     * @param int|null $idPelaku wajib untuk run nyata (id akun sistem); dry-run boleh null
     *
     * @return array{bulan: string, dry_run: bool, kandidat: int, disetujui: int, berubah_saat_proses: int, gagal: int}
     *
     * @throws AutoApprovalSibuk run nyata lain sedang berjalan
     */
    public function jalankan(string $bulan, bool $dryRun, ?int $idPelaku): array
    {
        if (! $dryRun && $idPelaku === null) {
            throw new RuntimeException('Run nyata auto-approval LKH wajib memakai pelaku akun sistem.');
        }

        ['awal' => $awal, 'akhir' => $akhir] = PenentuAutoApproval::rentangBulan($bulan);

        if ($dryRun) {
            return $this->proses($bulan, $awal, $akhir, true, null);
        }

        $kunci = self::namaKunci($this->db);
        $dapat = $this->db->query('SELECT GET_LOCK(?, 0) AS dapat', [$kunci])->getRowArray();

        if ((string) ($dapat['dapat'] ?? '') !== '1') {
            throw new AutoApprovalSibuk('Auto-approval LKH sedang berjalan di proses lain.');
        }

        try {
            return $this->proses($bulan, $awal, $akhir, false, $idPelaku);
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$kunci]);
        }
    }

    /**
     * @return array{bulan: string, dry_run: bool, kandidat: int, disetujui: int, berubah_saat_proses: int, gagal: int}
     */
    private function proses(string $bulan, string $awal, string $akhir, bool $dryRun, ?int $idPelaku): array
    {
        $ids = $this->idMenunggu($awal, $akhir);

        $hasil = [
            'bulan'               => $bulan,
            'dry_run'             => $dryRun,
            'kandidat'            => count($ids),
            'disetujui'           => 0,
            'berubah_saat_proses' => 0,
            'gagal'               => 0,
        ];

        if ($dryRun) {
            return $hasil;
        }

        foreach ($ids as $id) {
            $akhirBaris = $this->terapkan($id, $awal, $akhir, (int) $idPelaku);

            if ($akhirBaris === PenyetujuLkh::OK) {
                $hasil['disetujui']++;
            } elseif ($akhirBaris === PenyetujuLkh::BERUBAH_SAAT_PROSES) {
                $hasil['berubah_saat_proses']++;
            } else {
                $hasil['gagal']++;
            }
        }

        return $hasil;
    }

    /**
     * @return list<int>
     */
    private function idMenunggu(string $awal, string $akhir): array
    {
        $baris = $this->db->table('riwayat_lckh')
            ->select('id_riwayat_lckh')
            ->where('status', LkhService::MENUNGGU)
            ->where('tgl_laporan >=', $awal)
            ->where('tgl_laporan <=', $akhir)
            ->orderBy('id_riwayat_lckh', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['id_riwayat_lckh'], $baris);
    }

    /**
     * Satu LKH = satu transaksi.
     *
     * @return 'ok'|'berubah_saat_proses'|'gagal'
     */
    private function terapkan(int $id, string $awal, string $akhir, int $pelaku): string
    {
        if ($this->db->transBegin() === false) {
            $this->db->resetTransStatus();
            $this->catatGagal('mulai transaksi');

            return 'gagal';
        }

        try {
            $akhirBaris = $this->penyetuju->setujui($id, $awal, $akhir, $pelaku);

            if ($this->db->transStatus() === false) {
                $this->db->transRollback();
                $this->db->resetTransStatus();
                $this->catatGagal('tulis');

                return 'gagal';
            }

            $this->db->transCommit();
            $this->db->resetTransStatus();

            return $akhirBaris;
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
            $this->catatGagal('tulis', $e);

            return 'gagal';
        }
    }

    /**
     * Jejak galat per LKH tanpa pesan galat DB dan tanpa NIP/ID: hanya cabang, kelas, dan kode.
     */
    private function catatGagal(string $cabang, ?Throwable $e = null): void
    {
        $kelas = $e === null ? '-' : (new ReflectionClass($e))->getShortName();
        $kode  = $e === null ? '-' : (string) $e->getCode();

        log_message('error', sprintf('lkh:auto-approval gagal pada %s: %s kode %s.', $cabang, $kelas, $kode));
    }
}
