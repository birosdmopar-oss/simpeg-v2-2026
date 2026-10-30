<?php

declare(strict_types=1);

namespace App\Models\Layanan;

use CodeIgniter\Model;

/**
 * Tembusan ketua tim `riwayat_cuti_notif_kt` (PK komposit `(id_riwayat_cuti, nip_kt)`). Tabel pendamping yang
 * disinkronkan service cuti di dalam transaksi pengajuan; perubahannya tercermin di audit `riwayat_cuti`, jadi
 * model ini tidak ber-audit sendiri.
 */
class RiwayatCutiNotifKtModel extends Model
{
    protected $table         = 'riwayat_cuti_notif_kt';
    protected $primaryKey    = 'id_riwayat_cuti';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['id_riwayat_cuti', 'nip_kt', 'status', 'created_at', 'updated_at'];
}
