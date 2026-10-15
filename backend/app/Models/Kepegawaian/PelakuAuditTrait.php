<?php

declare(strict_types=1);

namespace App\Models\Kepegawaian;

use App\Libraries\Auth\AuthContext;
use Closure;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Pelaku audit eksplisit untuk model Kepegawaian WS-2 (pola RiwayatModel::withActor, MAKE-004): service yang dipanggil
 * langsung (test, worker) tetap mencatat pelaku AuthContext pemanggil, bukan sesi global. Tulis yang gagal (juga saat
 * DBDebug = false) dilempar sebagai DatabaseException agar transaksi pemanggil di-rollback.
 *
 * Dipakai turunan BaseAuditableModel.
 */
trait PelakuAuditTrait
{
    /**
     * @var array{id: int|null, nip: string|null}|null
     */
    private ?array $pelakuOverride = null;

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function withActor(AuthContext $auth, Closure $work): mixed
    {
        $sebelum              = $this->pelakuOverride;
        $this->pelakuOverride = ['id' => $auth->idPengguna(), 'nip' => $auth->nip()];

        try {
            return $work();
        } finally {
            $this->pelakuOverride = $sebelum;
        }
    }

    /**
     * @param array<string, mixed>|object|null $row
     *
     * @return bool|int|string
     */
    public function insert($row = null, bool $returnID = true)
    {
        $hasil = parent::insert($row, $returnID);

        if ($hasil === false) {
            throw $this->gagalTulis();
        }

        return $hasil;
    }

    /**
     * @param array<int|string>|int|string|null $id
     * @param array<string, mixed>|object|null  $row
     */
    public function update($id = null, $row = null): bool
    {
        if (parent::update($id, $row) === false) {
            throw $this->gagalTulis();
        }

        return true;
    }

    /**
     * @return array{id: int|null, nip: string|null}
     */
    protected function currentActor(): array
    {
        return $this->pelakuOverride ?? parent::currentActor();
    }

    private function gagalTulis(): DatabaseException
    {
        $error = $this->db->error();

        return new DatabaseException('Penulisan ' . $this->table . ' gagal: ' . $error['message'], (int) $error['code']);
    }
}
