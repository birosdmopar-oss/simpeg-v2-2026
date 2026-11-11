<?php

declare(strict_types=1);

namespace App\Models\Konten;

use App\Models\BaseAuditableModel;

/**
 * Model `dibo` — Kamus Digital Kepegawaian (F-12 MAKE-082; DBV-032 §3.9). Audit otomatis (Q7-16). Berkas `file_1..3` berisi kunci
 * penyimpanan privat; waktu UTC dan `created_by`/`updated_by` diisi service.
 */
final class DiboModel extends BaseAuditableModel
{
    protected $table         = 'dibo';
    protected $primaryKey    = 'id_dibo';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'id_jenis_dibo', 'nomor', 'tahun', 'tentang', 'keterangan', 'file_1', 'file_2', 'file_3', 'status',
        'created_at', 'updated_at', 'created_by', 'updated_by',
    ];
}
