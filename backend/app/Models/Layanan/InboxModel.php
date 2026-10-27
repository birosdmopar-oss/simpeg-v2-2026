<?php

declare(strict_types=1);

namespace App\Models\Layanan;

use CodeIgniter\Model;

/**
 * Induk notifikasi satu arah `inbox` v2 (PRD Fase 4 §6.6, D10/D11): satu baris per penerima per pengajuan
 * (`nip_penerima`, `modul`, `id_rwy`). BUKAN ticketing (`disiplin_ticket*`); tidak ada status open/closed maupun balasan.
 * Stempel `created_at` UTC diisi aplikasi (tanpa DEFAULT di DDL).
 */
class InboxModel extends Model
{
    protected $table         = 'inbox';
    protected $primaryKey    = 'id_inbox';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'nip_penerima', 'id_pengguna_penerima', 'modul', 'id_jenis_layanan', 'id_rwy', 'judul', 'url', 'is_read', 'read_at', 'created_at',
    ];
}
