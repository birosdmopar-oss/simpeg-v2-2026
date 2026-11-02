<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Log;

use CodeIgniter\Database\BaseConnection;

/**
 * Ringkasan `integrasi_log` untuk `php spark integrasi:status` (F6-24): per layanan × arah dalam jendela N jam terakhir —
 * jumlah sukses/gagal, waktu & kode kegagalan terakhir. Hanya agregat (tanpa `pesan`/`ref_id`), aman ditampilkan di
 * terminal/log cron. Peringatan periode Tukin tanpa SKP periodik ditambahkan B3 (MAKE-064).
 */
final class StatusIntegrasi
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return list<array{layanan: string, arah: int, sukses: int, gagal: int, gagal_terakhir_at: string|null, kode_terakhir: string|null}>
     */
    public function ringkasan(int $jam = 24): array
    {
        $batas = gmdate('Y-m-d H:i:s', time() - max(1, $jam) * 3600);
        $baris = $this->db->table('integrasi_log')
            ->select('layanan, arah')
            ->select('SUM(status = 1) AS sukses, SUM(status = 2) AS gagal', false)
            ->select('MAX(CASE WHEN status = 2 THEN created_at END) AS gagal_terakhir_at', false)
            ->where('created_at >=', $batas)
            ->groupBy(['layanan', 'arah'])
            ->orderBy('layanan', 'ASC')
            ->orderBy('arah', 'ASC')
            ->get()->getResultArray();

        $hasil = [];

        foreach ($baris as $b) {
            $kode = null;

            if ($b['gagal_terakhir_at'] !== null) {
                $akhir = $this->db->table('integrasi_log')->select('kode, http_status')
                    ->where(['layanan' => $b['layanan'], 'arah' => $b['arah'], 'status' => 2, 'created_at' => $b['gagal_terakhir_at']])
                    ->orderBy('id_integrasi_log', 'DESC')->get(1)->getRowArray();
                $kode = $akhir === null ? null : (string) ($akhir['kode'] ?? $akhir['http_status'] ?? '');
            }

            $hasil[] = [
                'layanan'           => (string) $b['layanan'],
                'arah'              => (int) $b['arah'],
                'sukses'            => (int) $b['sukses'],
                'gagal'             => (int) $b['gagal'],
                'gagal_terakhir_at' => $b['gagal_terakhir_at'] === null ? null : (string) $b['gagal_terakhir_at'],
                'kode_terakhir'     => $kode === '' ? null : $kode,
            ];
        }

        return $hasil;
    }
}
