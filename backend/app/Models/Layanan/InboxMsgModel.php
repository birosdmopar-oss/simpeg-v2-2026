<?php

declare(strict_types=1);

namespace App\Models\Layanan;

use CodeIgniter\Model;

/**
 * Pesan notifikasi `inbox_msg` v2: satu baris per kejadian, tanpa balasan. `push_status`: 0 antre, 1 terkirim, 2 gagal,
 * 3 tanpa token (diubah job push).
 */
class InboxMsgModel extends Model
{
    public const PUSH_ANTRE = 0;

    public const PUSH_TERKIRIM = 1;

    public const PUSH_GAGAL = 2;

    public const PUSH_TANPA_TOKEN = 3;

    protected $table         = 'inbox_msg';
    protected $primaryKey    = 'id_inbox_msg';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['id_inbox', 'tipe', 'status_sumber', 'isi', 'reason_note', 'push_status', 'created_at'];
}
