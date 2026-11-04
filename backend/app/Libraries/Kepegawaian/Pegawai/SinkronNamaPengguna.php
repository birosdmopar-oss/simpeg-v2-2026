<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Pegawai;

use App\Constants\AkunSistem;
use App\Constants\Role;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Models\AuditLogModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * MAKE-062 (F6-21 bersyarat) — `pengguna:sinkron-nama`: menyamakan `pengguna.name` dengan nama pegawai bergelar (PRD Fase 6
 * §5.2 F6-21, reviewer CR 04-11-2026). Ikut legacy `Cron.php:2818-2877` (`sync_nama_pengguna`): pengguna `status = 1`
 * ber-NIP dengan `UserLevel = 2` (padanan v2: {@see Role::PEGAWAI}) diisi `formatNamaGelar(glr_awal, nama, glr_akhir)`
 * dari pegawai `status = 1` ber-NIP sama, hanya bila berbeda dan nama hasil tidak kosong (legacy `Cron.php:2840-2844`).
 *
 * Deviasi [V2]: akun yang soft-deleted dilewati; nama dipotong ke 150 karakter (`pengguna.name` VARCHAR(150), mode strict
 * menolak yang lebih panjang) dan dihitung INFO; akun sistem integrasi dikecualikan eksplisit; tulis lewat transaksi kecil
 * per akun (kunci baris, cek belum berubah, update, `audit_logs`); run nyata dilindungi `GET_LOCK`. Keluaran hanya jumlah —
 * tanpa NIP/nama.
 */
final class SinkronNamaPengguna
{
    /** Jumlah baris per halaman kueri (keyset pada `id_pengguna`). */
    public const BATCH = 500;

    /** Panjang kolom `pengguna.name`. */
    public const PANJANG_MAKS = 150;

    /** `audit_logs.after.jenis_audit` (pola `EfekFinalCutiService`). */
    public const JENIS_AUDIT = 'pengguna.sinkron_nama';

    private const STATUS_AKTIF = 1;

    /**
     * @param (Closure(BaseConnection): AuditLogModel)|null $pabrikAudit pembuat model audit pada koneksi yang diberikan
     *                                                                   (bawaan `new AuditLogModel($db)`, satu koneksi dengan transaksi); hanya untuk test
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly ?Closure $pabrikAudit = null,
    ) {
    }

    public static function namaKunci(BaseConnection $db): string
    {
        return 'pengguna_sinkron_nama:' . substr(sha1($db->getDatabase() . '|' . $db->getPrefix()), 0, 16);
    }

    /**
     * @param int|null $idPelaku wajib untuk run nyata (id akun sistem); dry-run boleh null
     *
     * @return array{dry_run: bool, diperiksa: int, berubah: int, sama: int, gagal: int, info: array{dipotong: int, nama_kosong: int, berubah_saat_proses: int}}
     *
     * @throws SinkronNamaSibuk run nyata lain sedang berjalan
     */
    public function jalankan(bool $dryRun, ?int $idPelaku): array
    {
        if (! $dryRun && $idPelaku === null) {
            throw new RuntimeException('Run nyata sinkron nama wajib memakai pelaku akun sistem.');
        }

        if ($dryRun) {
            return $this->proses(true, null);
        }

        $kunci = self::namaKunci($this->db);
        $dapat = $this->db->query('SELECT GET_LOCK(?, 0) AS dapat', [$kunci])->getRowArray();

        if ((string) ($dapat['dapat'] ?? '') !== '1') {
            throw new SinkronNamaSibuk('Sinkron nama pengguna sedang berjalan di proses lain.');
        }

        try {
            return $this->proses(false, $idPelaku);
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$kunci]);
        }
    }

    /**
     * @return array{dry_run: bool, diperiksa: int, berubah: int, sama: int, gagal: int, info: array{dipotong: int, nama_kosong: int, berubah_saat_proses: int}}
     */
    private function proses(bool $dryRun, ?int $idPelaku): array
    {
        $hasil = ['dry_run' => $dryRun, 'diperiksa' => 0, 'berubah' => 0, 'sama' => 0, 'gagal' => 0, 'info' => ['dipotong' => 0, 'nama_kosong' => 0, 'berubah_saat_proses' => 0]];
        $akhir = 0;

        while (true) {
            $halaman = $this->halaman($akhir);

            if ($halaman === []) {
                return $hasil;
            }

            foreach ($halaman as $r) {
                $akhir = (int) $r['id_pengguna'];
                $hasil['diperiksa']++;

                $nama = StrukturService::namaGelar($r['glr_awal'] === null ? null : (string) $r['glr_awal'], (string) $r['nama_pegawai'], $r['glr_akhir'] === null ? null : (string) $r['glr_akhir']);

                if ($nama === '') {
                    $hasil['info']['nama_kosong']++;

                    continue;
                }

                if (mb_strlen($nama) > self::PANJANG_MAKS) {
                    $nama = mb_substr($nama, 0, self::PANJANG_MAKS);
                    $hasil['info']['dipotong']++;
                }

                $lama = $r['name'] === null ? null : (string) $r['name'];

                if ($lama === $nama) {
                    $hasil['sama']++;

                    continue;
                }

                if (! $dryRun) {
                    $akhirTulis = $this->terapkan($akhir, $lama, $nama, (int) $idPelaku);

                    if ($akhirTulis === 'gagal') {
                        $hasil['gagal']++;

                        continue;
                    }

                    if ($akhirTulis === 'berubah_saat_proses') {
                        $hasil['info']['berubah_saat_proses']++;

                        continue;
                    }
                }

                $hasil['berubah']++;
            }
        }
    }

    /**
     * Satu halaman pengguna sasaran (keyset `id_pengguna > $setelah`).
     *
     * @return list<array<string, mixed>>
     */
    private function halaman(int $setelah): array
    {
        return $this->db->table('pengguna p')
            ->select('p.id_pengguna, p.name, g.nama AS nama_pegawai, g.glr_awal, g.glr_akhir')
            ->join('pegawai g', 'g.nip = p.nip')
            ->where('p.status', '1')
            ->where('p.user_level', Role::PEGAWAI)
            ->where('p.deleted_at IS NULL')
            ->where('p.nip IS NOT NULL')
            ->where('p.username !=', AkunSistem::USERNAME)
            ->where('g.status', self::STATUS_AKTIF)
            ->where('p.id_pengguna >', $setelah)
            ->orderBy('p.id_pengguna', 'ASC')
            ->limit(self::BATCH)
            ->get()->getResultArray();
    }

    /**
     * @return 'ok'|'berubah_saat_proses'|'gagal'
     */
    private function terapkan(int $idPengguna, ?string $lama, string $baru, int $idPelaku): string
    {
        if ($this->db->transBegin() === false) {
            $this->db->resetTransStatus();

            return 'gagal';
        }

        try {
            $sql   = $this->db->table('pengguna')->select('name')->where('id_pengguna', $idPengguna)->getCompiledSelect() . ' FOR UPDATE';
            $baris = $this->db->query($sql)->getRowArray();

            if ($baris === null || ($baris['name'] === null ? null : (string) $baris['name']) !== $lama) {
                $this->db->transRollback();
                $this->db->resetTransStatus();

                return 'berubah_saat_proses';
            }

            $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->update(['name' => $baru, 'updated_at' => gmdate('Y-m-d H:i:s')]);

            $idAudit = ($this->pabrikAudit !== null ? ($this->pabrikAudit)($this->db) : new AuditLogModel($this->db))->record(
                'pengguna',
                $idPengguna,
                AuditLogModel::EVENT_UPDATE,
                ['name' => $lama],
                ['name' => $baru, 'jenis_audit' => self::JENIS_AUDIT],
                $idPelaku,
                null,
            );

            if ($idAudit <= 0 || $this->db->transStatus() === false) {
                $this->catatGagal('tulis', null);
                $this->db->transRollback();
                $this->db->resetTransStatus();

                return 'gagal';
            }

            $this->db->transCommit();
            $this->db->resetTransStatus();

            return 'ok';
        } catch (Throwable $e) {
            $this->catatGagal('tulis', $e);
            $this->db->transRollback();
            $this->db->resetTransStatus();

            return 'gagal';
        }
    }

    /**
     * Jejak kegagalan per akun: cabang, kelas pendek, kode saja — pesan galat DB bisa memuat nama/NIP (pola SiasnTarik).
     */
    private function catatGagal(string $cabang, ?Throwable $e): void
    {
        log_message('error', sprintf(
            'pengguna:sinkron-nama gagal pada %s: %s kode %s.',
            $cabang,
            $e === null ? 'tanpa pengecualian' : (new ReflectionClass($e))->getShortName(),
            $e === null ? '-' : (string) $e->getCode(),
        ));
    }
}
