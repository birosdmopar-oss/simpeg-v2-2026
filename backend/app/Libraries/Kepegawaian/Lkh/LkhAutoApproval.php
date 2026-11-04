<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lkh;

use App\Libraries\Auth\AuthContext;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Models\Kepegawaian\LkhModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Auto-approval LKH tanggal 5 (H-12, MAKE-068, F6-25) — ikut legacy `Cron::queue_aa_lkh` dan `process_queue_aa_lkh`.
 *
 * LKH bulan lalu yang masih Menunggu (status 0) → Disetujui (status 1) dengan `auto_approval = 1`, lewat model beraudit
 * dengan pelaku akun sistem integrasi. Status 3 (Revisi) tidak diproses [K efektif `C:147`]. PDF tidak dibangkitkan dan
 * tidak disimpan: PDF LKH Disetujui dibuat saat diminta (CR-093, keputusan Horii 03-11-2026). Tanpa tabel `aa_lkh`.
 *
 * Idempoten: baris yang sudah diproses tidak lagi berstatus 0, sehingga menjalankan ulang tidak punya efek. Seluruh
 * penulisan satu transaksi; galat apa pun membatalkan semuanya dan dilempar ke pemanggil (tidak ditelan diam-diam).
 * Dijaga named lock `GET_LOCK` agar dua proses tidak memproses bulan yang sama bersamaan.
 */
final class LkhAutoApproval
{
    public const KUNCI_LOCK = 'lkh:auto-approval';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param string $bulan YYYY-MM (bulan laporan yang diproses)
     *
     * @return array{bulan: string, kandidat: int, disetujui: int, dry_run: bool}
     *
     * @throws InvalidArgumentException bulan tidak valid
     * @throws RuntimeException         lock dipegang proses lain, atau transaksi gagal
     */
    public function jalankan(string $bulan, bool $dryRun = false): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan) !== 1) {
            throw new InvalidArgumentException('Bulan harus berformat YYYY-MM.');
        }

        $awal  = $bulan . '-01';
        $akhir = (new DateTimeImmutable($awal))->format('Y-m-t');

        if (! $this->dapatkanKunci()) {
            throw new RuntimeException('Auto-approval LKH sedang berjalan di proses lain.');
        }

        try {
            // Pelaku diambil di awal: akun belum siap → gagal sebelum ada yang disentuh (AkunSistemIntegrasi::id()).
            $pelaku      = (new AkunSistemIntegrasi($this->db))->id();
            $idsMenunggu = $this->idMenunggu($awal, $akhir);

            if ($dryRun) {
                return ['bulan' => $bulan, 'kandidat' => count($idsMenunggu), 'disetujui' => 0, 'dry_run' => true];
            }

            $disetujui = $this->setujuiOtomatis($idsMenunggu, $pelaku);

            return ['bulan' => $bulan, 'kandidat' => count($idsMenunggu), 'disetujui' => $disetujui, 'dry_run' => false];
        } finally {
            $this->lepasKunci();
        }
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
     * Setiap baris dikunci (`FOR UPDATE`) dan diperiksa ulang: bila sudah diproses atasan saat cron berjalan, dilewati.
     *
     * @param list<int> $ids
     */
    private function setujuiOtomatis(array $ids, int $pelaku): int
    {
        $auth = new AuthContext();
        $auth->setClaims(['sub' => (string) $pelaku]);
        $model     = new LkhModel($this->db);
        $disetujui = 0;

        if (! $this->db->transBegin()) {
            throw new RuntimeException('Auto-approval LKH gagal memulai transaksi.');
        }

        try {
            foreach ($ids as $id) {
                $tabel   = $this->db->prefixTable('riwayat_lckh');
                $terkini = $this->kueri("SELECT status FROM {$tabel} WHERE id_riwayat_lckh = ? FOR UPDATE", [$id])->getRowArray();

                if ($terkini === null || (int) $terkini['status'] !== LkhService::MENUNGGU) {
                    continue;
                }

                $model->withActor($auth, static fn () => $model->update($id, [
                    'status'        => LkhService::DISETUJUI,
                    'auto_approval' => 1,
                    'approved_by'   => $pelaku,
                    'updated_by'    => $pelaku,
                ]));
                $disetujui++;
            }

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Auto-approval LKH gagal menulis; semua perubahan dibatalkan.');
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $disetujui;
    }

    private function dapatkanKunci(): bool
    {
        $hasil = $this->kueri('SELECT GET_LOCK(?, 0) AS dapat', [self::KUNCI_LOCK])->getRowArray();

        return (int) ($hasil['dapat'] ?? 0) === 1;
    }

    private function lepasKunci(): void
    {
        $this->kueri('SELECT RELEASE_LOCK(?) AS lepas', [self::KUNCI_LOCK]);
    }

    /**
     * Kueri yang galatnya tidak boleh terbaca sebagai hasil kosong: `query()` mengembalikan false bila DBDebug mati.
     *
     * @param array<int, mixed> $bind
     *
     * @throws RuntimeException
     */
    private function kueri(string $sql, array $bind): ResultInterface
    {
        $hasil = $this->db->query($sql, $bind);

        if ($hasil === false) {
            throw new RuntimeException('Auto-approval LKH gagal menjalankan kueri: ' . $this->db->error()['message']);
        }

        return $hasil;
    }
}
