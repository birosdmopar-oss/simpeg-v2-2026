<?php

declare(strict_types=1);

namespace App\Models\Presensi;

use App\Models\BaseAuditableModel;

/**
 * Tabel `gaji_pegawai_qr_history` (DBV-023): riwayat setiap token QR verifikasi yang pernah terbit per slip (hash SHA-256,
 * UNIQUE) + snapshot verifikasi saat terbit (`snapshot_satker`, `snapshot_nama` tersamar, AS-06). Ditulis
 * `SlipGajiService::buka` di transaksi yang sama dengan audit `update` slip; tabel ini sendiri jejak, jadi audit model
 * dimatikan (token & IP tidak boleh masuk audit, PRD 5A §8.1). Tidak punya kolom NIP (tidak masuk registry NIP).
 */
class GajiPegawaiQrHistoryModel extends BaseAuditableModel
{
    protected $table             = 'gaji_pegawai_qr_history';
    protected $primaryKey        = 'id';
    protected $returnType        = 'array';
    protected $useTimestamps     = false;
    protected bool $auditEnabled = false;

    /**
     * @var list<string>
     */
    protected $allowedFields = ['id_gaji_pegawai', 'qr_token', 'viewed_at', 'viewed_ip', 'created_at', 'snapshot_satker', 'snapshot_nama'];
}
