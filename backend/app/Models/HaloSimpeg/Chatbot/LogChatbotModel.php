<?php

declare(strict_types=1);

namespace App\Models\HaloSimpeg\Chatbot;

use CodeIgniter\Model;

/**
 * Log percakapan chatbot anonim (DBV-033, K-4). Model BIASA tanpa audit: isi pesan pengguna tidak boleh masuk `audit_logs`
 * (BP8-15). Tanpa kolom identitas.
 */
final class LogChatbotModel extends Model
{
    protected $table         = 'log_chatbot';
    protected $primaryKey    = 'id_log';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['pesan_user', 'top_1_id_qna', 'top_1_skor', 'top_3_kandidat', 'status_match', 'feedback', 'waktu_interaksi'];
}
