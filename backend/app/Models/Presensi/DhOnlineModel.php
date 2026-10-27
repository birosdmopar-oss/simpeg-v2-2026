<?php

declare(strict_types=1);

namespace App\Models\Presensi;

use App\Models\BaseAuditableModel;

/**
 * Tabel `dh_online` (DBV-023, migration `2026-10-27-100000_CreatePresensiGaji`): presensi harian per `(nip, tgl)` — PK
 * komposit [K], `tgl`/`jam_*` jam dinding lokal satker (bukan UTC), bukti lokasi online F5-12, `sumber` 1 online / 2 manual /
 * NULL impor legacy (DBV-023 C-4: baris baru selalu 1/2).
 *
 * Audit otomatis `BaseAuditableModel` tidak mendukung PK komposit, jadi `$auditEnabled = false` dan service menulis audit
 * manual `AuditLogModel::record('dh_online', '{nip}:{tgl}', …)` di transaksi yang sama (PRD 5A §8.1) —
 * `PresensiOnlineService`, `PresensiManualService`.
 */
class DhOnlineModel extends BaseAuditableModel
{
    protected $table         = 'dh_online';
    protected $primaryKey    = 'nip';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected bool $auditEnabled = false;

    /**
     * @var list<string>
     */
    protected $allowedFields = [
        'nip', 'tgl', 'jam_masuk', 'jam_pulang', 'created_at', 'updated_at', 'updated_by',
        'lat_masuk', 'lng_masuk', 'akurasi_masuk', 'id_lokasi_masuk',
        'lat_pulang', 'lng_pulang', 'akurasi_pulang', 'id_lokasi_pulang',
        'sumber', 'created_by',
    ];
}
