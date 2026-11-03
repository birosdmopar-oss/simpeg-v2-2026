<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Log;

use App\Interfaces\Integrasi\IntegrasiLogInterface;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Penulis `integrasi_log` (F6-24). Ditulis di luar transaksi bisnis pemanggil (BP-4) lewat koneksi yang sama; gagal
 * menulis log tidak menggagalkan operasi (BP-7) — galat diteruskan ke log berkas tanpa isi pesan integrasi.
 * Stempel `created_at` UTC (sesi DB UTC, CR-086).
 */
final class IntegrasiLog implements IntegrasiLogInterface
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function catat(CatatanIntegrasi $catatan): void
    {
        try {
            $this->db->table('integrasi_log')->insert([
                'arah'        => $catatan->arah,
                'layanan'     => mb_substr($catatan->layanan, 0, 30),
                'operasi'     => mb_substr($catatan->operasi, 0, 100),
                'status'      => $catatan->status,
                'http_status' => $catatan->httpStatus,
                'kode'        => $catatan->kode === null ? null : mb_substr($catatan->kode, 0, 50),
                'pesan'       => $catatan->pesan === null ? null : mb_substr($catatan->pesan, 0, CatatanIntegrasi::PESAN_MAKS),
                'durasi_ms'   => $catatan->durasiMs,
                'percobaan'   => max(1, min(255, $catatan->percobaan)),
                'ref_id'      => $catatan->refId === null ? null : mb_substr($catatan->refId, 0, 100),
                'id_klien'    => $catatan->idKlien,
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            log_message('error', '[integrasi_log] gagal mencatat {layanan}/{operasi}: {jenis}', [
                'layanan' => $catatan->layanan,
                'operasi' => $catatan->operasi,
                'jenis'   => $e::class,
            ]);
        }
    }
}
