<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti\Servis;

use App\Libraries\Layanan\Cuti\AturanCuti;
use App\Libraries\Layanan\Cuti\SaldoCuti;
use App\Libraries\Layanan\Cuti\SaldoTahunanCuti;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use Throwable;

/**
 * Pembentukan baris saldo tahun baru `pegawai_sisa_cuti` (PRD Fase 4 v1.3 §6.3.6, Q-02) untuk command
 * `cuti:saldo-tahunan`. Populasi `pegawai.status = 1 AND id_jenis_pegawai IN (1, 6)`, tanpa syarat masa kerja.
 * Idempoten: NIP yang sudah punya baris `(nip, tahun)` dilewati. `--dry-run` tidak menulis apa pun.
 *
 * Penulisan: query builder dalam satu transaksi + satu baris `audit_logs` ringkasan per eksekusi tulis
 * (`jenis_audit` {@see self::JENIS_AUDIT}, aktor sistem). Eksekusi tulis untuk tahun < {@see self::TAHUN_TULIS_MULAI} ditolak
 * (keputusan Horii 27-10: saldo 2026 mengikuti data impor; command hanya `--dry-run` untuk laporan Biro SDM).
 */
final class SaldoTahunanService
{
    /** Penanda `after_json.jenis_audit` pada baris audit ringkasan (PRD §6.3.6 "event cuti.saldo_tahunan"). */
    public const JENIS_AUDIT = 'cuti.saldo_tahunan';

    public const TAHUN_TULIS_MULAI = 2027;

    public const TAHUN_MINIMAL = 2000;

    public const JENIS_PEGAWAI = [1, 6];

    private const CHUNK = 500;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AturanCuti $aturan,
        private readonly AuditLogModel $audit,
    ) {
    }

    /**
     * @return array{tahun: int, dry_run: bool, populasi: int, dibuat: int, dilewati: int, tanpa_tahun_lalu: int, distribusi_n: array<int, int>, distribusi_n1: array<int, int>, distribusi_n2: array<int, int>}
     */
    public function jalankan(int $tahun, bool $dryRun, ?string $nip = null, ?int $tahunBerjalan = null): array
    {
        $tahunBerjalan ??= (int) date('Y');

        if ($tahun < self::TAHUN_MINIMAL || $tahun > $tahunBerjalan) {
            throw new InvalidArgumentException('Tahun harus antara ' . self::TAHUN_MINIMAL . " dan {$tahunBerjalan}.");
        }

        if (! $dryRun && $tahun < self::TAHUN_TULIS_MULAI) {
            throw new InvalidArgumentException('Eksekusi tulis hanya untuk tahun ' . self::TAHUN_TULIS_MULAI . " ke atas; gunakan --dry-run untuk tahun {$tahun}.");
        }

        $pegawai = $this->db->table('pegawai')
            ->select('nip')
            ->where('status', 1)
            ->whereIn('id_jenis_pegawai', self::JENIS_PEGAWAI);

        if ($nip !== null) {
            $pegawai->where('nip', $nip);
        }

        $daftar = array_column($pegawai->orderBy('nip')->get()->getResultArray(), 'nip');

        $sudahAda = array_flip(array_column(
            $this->db->table('pegawai_sisa_cuti')->select('nip')->where('tahun', $tahun)->get()->getResultArray(),
            'nip',
        ));

        $lalu = [];

        foreach ($this->db->table('pegawai_sisa_cuti')->where('tahun', $tahun - 1)->get()->getResultArray() as $baris) {
            $lalu[(string) $baris['nip']] = SaldoCuti::dariBaris($baris);
        }

        $ringkasan = [
            'tahun'            => $tahun,
            'dry_run'          => $dryRun,
            'populasi'         => count($daftar),
            'dibuat'           => 0,
            'dilewati'         => 0,
            'tanpa_tahun_lalu' => 0,
            'distribusi_n'     => [],
            'distribusi_n1'    => [],
            'distribusi_n2'    => [],
        ];
        $baru = [];
        $now  = date('Y-m-d H:i:s');

        foreach ($daftar as $n) {
            $n = (string) $n;

            if (isset($sudahAda[$n])) {
                $ringkasan['dilewati']++;

                continue;
            }

            if (! isset($lalu[$n])) {
                $ringkasan['tanpa_tahun_lalu']++;
            }

            $saldo = isset($lalu[$n]) ? SaldoTahunanCuti::bentuk($lalu[$n], $this->aturan) : SaldoTahunanCuti::tanpaTahunLalu($this->aturan);

            $ringkasan['dibuat']++;
            $ringkasan['distribusi_n'][$saldo->n]   = ($ringkasan['distribusi_n'][$saldo->n] ?? 0) + 1;
            $ringkasan['distribusi_n1'][$saldo->n1] = ($ringkasan['distribusi_n1'][$saldo->n1] ?? 0) + 1;
            $ringkasan['distribusi_n2'][$saldo->n2] = ($ringkasan['distribusi_n2'][$saldo->n2] ?? 0) + 1;

            $baru[] = ['nip' => $n, 'tahun' => $tahun, 'digunakan' => 0, 'created_at' => $now, 'updated_by' => 0] + $saldo->keBaris();
        }

        ksort($ringkasan['distribusi_n']);
        ksort($ringkasan['distribusi_n1']);
        ksort($ringkasan['distribusi_n2']);

        if ($dryRun || $baru === []) {
            return $ringkasan;
        }

        $this->db->transBegin();

        try {
            foreach (array_chunk($baru, self::CHUNK) as $potong) {
                $this->db->table('pegawai_sisa_cuti')->insertBatch($potong);
            }

            // Kolom `audit_logs.event` ber-ENUM (create/update/delete/login/logout): jenis audit Layanan dibawa di after_json.
            $this->audit->record('pegawai_sisa_cuti', (string) $tahun, AuditLogModel::EVENT_CREATE, null, ['jenis_audit' => self::JENIS_AUDIT] + $ringkasan);

            if (! $this->db->transStatus()) {
                throw new InvalidArgumentException('Penulisan saldo tahunan gagal.');
            }

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        }

        return $ringkasan;
    }
}
