<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Models\AuditLogModel;
use App\Models\BaseAuditableModel;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Tabel `faq_rate` (G-10, DBV-002): satu penilaian per artikel per pegawai, PK komposit (id_faq_article, nip).
 * Rating tidak bisa diubah/dihapus (legacy), jadi model ini hanya menyisipkan.
 *
 * Model Events CI4 hanya mengenal satu kolom PK, sehingga audit otomatis dimatikan dan ditulis manual lewat
 * writeAudit() dengan id gabungan "{id_faq_article}:{nip}" (event create). Audit tetap fail-open (F0-04).
 */
class FaqRateModel extends BaseAuditableModel
{
    public const RATE_HELPFUL     = 1;
    public const RATE_NOT_HELPFUL = 2;

    protected $table             = 'faq_rate';
    protected $primaryKey        = 'id_faq_article';
    protected $useAutoIncrement  = false;
    protected $returnType        = 'array';
    protected $useTimestamps     = false;
    protected $allowedFields     = ['id_faq_article', 'nip', 'rate', 'reason', 'created_at', 'created_by'];
    protected bool $auditEnabled = false;

    /**
     * Penilaian pegawai untuk satu artikel (null bila belum menilai).
     *
     * @return array<string, mixed>|null
     */
    public function findRating(int $articleId, string $nip): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->db->table($this->table)
            ->where('id_faq_article', $articleId)
            ->where('nip', $nip)
            ->get()
            ->getRowArray();

        return $row;
    }

    /**
     * Sisipkan satu penilaian + audit. Gagal tulis (mis. 1062 PK ganda saat balapan) → DatabaseException berkode
     * error MySQL, juga saat DBDebug = false.
     *
     * @param array{id_faq_article: int, nip: string, rate: int, reason: string|null, created_at: string, created_by: int|null} $row
     *
     * @throws DatabaseException
     */
    public function record(array $row): void
    {
        if ($this->db->table($this->table)->insert($row) === false) {
            $error = $this->db->error();

            throw new DatabaseException('Penyimpanan penilaian FAQ gagal: ' . $error['message'], (int) $error['code']);
        }

        $this->writeAudit(
            AuditLogModel::EVENT_CREATE,
            "{$row['id_faq_article']}:{$row['nip']}",
            null,
            $this->findRating($row['id_faq_article'], $row['nip']) ?? $row,
        );
    }
}
