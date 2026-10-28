<?php

declare(strict_types=1);

namespace App\Models\Layanan;

use CodeIgniter\Model;

/**
 * Token perangkat FCM `firebase_token` (DBV-022). Fase 4 hanya MEMBACA (job push) dan menghapus token yang dilaporkan
 * tidak valid; pendaftaran token oleh klien = Fase 6/7. Isi `fcm_token` tidak boleh masuk log.
 */
class FirebaseTokenModel extends Model
{
    protected $table         = 'firebase_token';
    protected $primaryKey    = 'id_firebase_token';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['nip', 'fcm_token', 'created_at', 'updated_at'];
}
