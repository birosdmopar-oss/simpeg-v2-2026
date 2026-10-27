<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Exceptions\NotFoundException;
use App\Libraries\Presensi\SlipGaji\FormatSlip;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use App\Libraries\Presensi\SlipGaji\TokenQr;
use CodeIgniter\Database\BaseConnection;

/**
 * D-14 verifikasi QR publik (PRD 5A §7.4, F5-7, AS-06): token 64 heks huruf kecil → hash SHA-256 → riwayat
 * `gaji_pegawai_qr_history` (token lama sebelum buka ulang tetap sah). Respons 404 seragam untuk semua alasan; data dari
 * snapshot saat terbit, tanpa nominal, NIP, IP, token, id baris. Log `info` per permintaan tanpa IP dan token.
 */
final class VerifikasiSlipService
{
    public const PESAN_TIDAK_DITEMUKAN = 'Dokumen tidak ditemukan atau tidak valid.';

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * @return array{status_keaslian: string, periode: string, label_periode: string, satker_penerbit: string|null, waktu_terbit: string, nama: string|null}
     */
    public function verifikasi(string $token): array
    {
        $row = TokenQr::valid($token)
            ? $this->koneksi()->table('gaji_pegawai_qr_history h')
                ->select('h.viewed_at, h.snapshot_satker, h.snapshot_nama, g.bulan, g.tahun')
                ->join('gaji_pegawai g', 'g.id = h.id_gaji_pegawai')
                ->where('h.qr_token', TokenSlipService::hash($token))
                ->get(1)->getRowArray()
            : null;

        if ($row === null) {
            log_message('info', 'Verifikasi slip publik: tidak ditemukan.');

            throw new NotFoundException(self::PESAN_TIDAK_DITEMUKAN);
        }

        log_message('info', 'Verifikasi slip publik: ditemukan.');
        $periode = PeriodeSlip::buat((int) $row['tahun'], (int) $row['bulan']);

        return [
            'status_keaslian' => 'asli',
            'periode'         => $periode->kunci(),
            'label_periode'   => $periode->label(),
            'satker_penerbit' => $row['snapshot_satker'],
            'waktu_terbit'    => FormatSlip::waktuWib(SlipGajiData::utc((string) $row['viewed_at']) ?? throw new \RuntimeException('viewed_at kosong')),
            'nama'            => $row['snapshot_nama'],
        ];
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
