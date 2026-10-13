<?php

declare(strict_types=1);

namespace App\Models\Kepegawaian;

use App\Models\BaseAuditableModel;

/**
 * Tabel `pegawai_foto` (B-01 DBV-012) — galeri foto profil. Legacy mengisinya lewat trigger `pegawai_afIns/afUpd`
 * (baris baru setiap `pegawai.foto` berubah dan pasangan (nip, foto) belum ada); v2 menulisnya di service biodata pada
 * transaksi yang sama (dok DBV-012 5.1).
 */
class PegawaiFotoModel extends BaseAuditableModel
{
    use PelakuAuditTrait;

    protected $table         = 'pegawai_foto';
    protected $primaryKey    = 'id_pegawai_foto';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['nip', 'foto', 'created_at'];
}
