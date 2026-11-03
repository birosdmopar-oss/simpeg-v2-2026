<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Log;

use App\Libraries\Bkn\Ekinerja\PemilihPeriode;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Ringkasan `integrasi_log` untuk `php spark integrasi:status` (F6-24): per layanan × arah dalam jendela N jam terakhir —
 * jumlah sukses/gagal, waktu & kode kegagalan terakhir. Hanya agregat (tanpa `pesan`/`ref_id`), aman ditampilkan di
 * terminal/log cron. {@see self::peringatanEkinerja()}: periode Tukin berjalan yang belum punya baris SKP periodik (F6-15).
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

    /**
     * Peringatan F6-15 (MAKE-064): periode e-Kinerja yang sedang dipakai Tukin (aturan pemilihan sama dengan cron, tanggal
     * WIB > 19 → bulan berikutnya) belum ada di `bkn_periode_ekinper` atau belum punya satu pun baris
     * `riwayat_skp_periodik` aktif — tanpa data itu Tukin memakai potongan SKP bawaan 32% [K]. Null = aman.
     */
    /**
     * Status TLS e-sign untuk `integrasi:status` (keputusan Horii 03-11-2026): driver `bsre` dengan `tlsVerify` false →
     * baris peringatan; selain itu null.
     *
     * @param array<string, mixed> $esign `Config\Integrations::$esign`
     */
    public function peringatanTlsEsign(array $esign): ?string
    {
        $tls  = $esign['tlsVerify'] ?? true;
        $mati = $tls === false || (is_string($tls) && in_array(strtolower(trim($tls)), ['false', '0'], true)) || $tls === 0;

        return ($esign['driver'] ?? 'mock') === 'bsre' && $mati
            ? 'TLS BSrE: TIDAK DIVERIFIKASI (esign.tlsVerify = false; upgrade lewat .env, lihat README-deploy bagian e-sign).'
            : null;
    }

    public function peringatanEkinerja(?DateTimeImmutable $sekarang = null): ?string
    {
        $pilih   = PemilihPeriode::untuk($sekarang ?? new DateTimeImmutable('now'));
        $label   = $pilih['tahun'] . '-' . $pilih['bulan'];
        $periode = $this->db->table('bkn_periode_ekinper')->select('id')
            ->where('tahun', $pilih['tahun'])->where('bulan', $pilih['bulan'])->where('jenis_periode', null)
            ->orderBy('id', 'ASC')->get(1)->getRowArray();

        if ($periode === null) {
            $pesan = "Periode e-Kinerja {$label} belum ada di bkn_periode_ekinper (jalankan bkn:tarik-periode).";
        } elseif ($this->db->table('riwayat_skp_periodik')->where('periode_id', $periode['id'])->where('status', 1)->countAllResults() === 0) {
            $pesan = "Periode Tukin {$label} belum punya data SKP periodik e-Kinerja; tanpa data, potongan SKP bawaan 32% berlaku (jalankan bkn:tarik-skp-periodik).";
        } else {
            return null;
        }

        log_message('warning', '[integrasi:ekinerja] ' . $pesan);

        return $pesan;
    }
}
