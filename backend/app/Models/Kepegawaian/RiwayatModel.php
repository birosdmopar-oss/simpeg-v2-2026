<?php

declare(strict_types=1);

namespace App\Models\Kepegawaian;

use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Models\AuditLogModel;
use App\Models\BaseAuditableModel;
use Closure;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Model generik semua tabel `riwayat_*` mesin riwayat (WS-1 M1 MAKE-004), dikonfigurasi dari RiwayatDefinisi (pola
 * MasterModel). Turunan BaseAuditableModel → tambah/ubah (termasuk approve/reject = event `update`) tercatat otomatis
 * di audit_logs; hapus lunak (status 10) dicatat sebagai event `delete` lewat hapusLunak().
 *
 * Snapshot `pegawai_*` TIDAK disentuh model ini (ADR-006): RiwayatEngine memanggil SnapshotSync secara eksplisit di
 * transisi masuk/keluar status Disetujui. SnapshotSync memilih ulang baris dari aturan Definisi (multi-target), sehingga
 * pola satu-target BaseSnapshotModel tidak dipakai untuk jenis engine.
 *
 * Kolom sistem (created_at/updated_at/updated_by/…) hanya ditulis bila ada di tabel itu ($kolom = kolom DDL tabel).
 * Timestamp = Time::now() zona aplikasi (UTC). Pelaku audit = AuthContext pemanggil (withActor()), bukan sesi global,
 * supaya service yang dipanggil langsung (test, worker) tetap mencatat pelaku yang benar.
 */
class RiwayatModel extends BaseAuditableModel
{
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $dateFormat     = 'datetime';

    /**
     * @var array{id: int|null, nip: string|null}|null
     */
    private ?array $actorOverride = null;

    /**
     * @param list<string> $kolom kolom DDL tabel riwayat
     */
    public function __construct(private readonly RiwayatDefinisi $definisi, array $kolom, ?ConnectionInterface $db = null)
    {
        $this->table            = $definisi->tabel();
        $this->primaryKey       = $definisi->primaryKey();
        $this->useAutoIncrement = true;
        $this->allowedFields    = array_values(array_diff($kolom, [$definisi->primaryKey()]));
        $this->createdField     = in_array('created_at', $kolom, true) ? 'created_at' : '';
        $this->updatedField     = in_array('updated_at', $kolom, true) ? 'updated_at' : '';
        $this->useTimestamps    = $this->createdField !== '' || $this->updatedField !== '';

        parent::__construct($db);
    }

    public function definisi(): RiwayatDefinisi
    {
        return $this->definisi;
    }

    /**
     * Jalankan $work dengan pelaku audit = $auth.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function withActor(AuthContext $auth, Closure $work): mixed
    {
        $previous            = $this->actorOverride;
        $this->actorOverride = ['id' => $auth->idPengguna(), 'nip' => $auth->nip()];

        try {
            return $work();
        } finally {
            $this->actorOverride = $previous;
        }
    }

    /**
     * @param array<string, mixed>|object|null $row
     *
     * @throws DatabaseException query gagal, juga saat DBDebug = false
     */
    public function insert($row = null, bool $returnID = true)
    {
        $result = parent::insert($row, $returnID);

        if ($result === false) {
            throw $this->writeFailure();
        }

        return $result;
    }

    /**
     * @param array<int|string>|int|string|null $id
     * @param array<string, mixed>|object|null  $row
     *
     * @throws DatabaseException query gagal, juga saat DBDebug = false
     */
    public function update($id = null, $row = null): bool
    {
        if (parent::update($id, $row) === false) {
            throw $this->writeFailure();
        }

        return true;
    }

    /**
     * Hapus lunak: tulis $data (status 10 + kolom sistem) tanpa menghapus baris; audit dicatat sebagai event 'delete'
     * (before/after), sama dengan MasterModel::softDelete().
     *
     * @param array<string, mixed> $data
     */
    public function hapusLunak(int|string $id, array $data): void
    {
        $before = $this->fetchRows([$id])[0] ?? null;

        $this->auditEnabled = false;

        try {
            $this->update($id, $data);
        } finally {
            $this->auditEnabled = true;
        }

        $after = $this->fetchRows([$id])[0] ?? null;
        $this->writeAudit(AuditLogModel::EVENT_DELETE, $id, $before, $after);
    }

    /**
     * Baris milik $nip ber-PK $id (termasuk status 10), atau null.
     *
     * @return array<string, mixed>|null
     */
    public function milik(string $nip, int $id): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->db->table($this->table)
            ->where($this->primaryKey, $id)
            ->where($this->definisi->kolomNip(), $nip)
            ->get()
            ->getRowArray();

        return $row;
    }

    protected function currentActor(): array
    {
        return $this->actorOverride ?? parent::currentActor();
    }

    private function writeFailure(): DatabaseException
    {
        $error = $this->db->error();

        return new DatabaseException('Penulisan riwayat gagal: ' . $error['message'], (int) $error['code']);
    }
}
