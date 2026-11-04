<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Exceptions\NotFoundException;
use App\Interfaces\Layanan\TokenSuratCutiInterface;
use App\Libraries\Presensi\SlipGaji\TokenQr;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Route publik surat cuti ber-token (PRD Fase 6 v1.0f §7.2; keputusan Horii 03-11-2026):
 *
 * - {@see self::verifikasi()} — Q6-23 / AC-02.8: halaman verifikasi QR kaki surat (pola slip D-14, ADR-032). Data minimal:
 *   jenis surat, nama penanda tangan, tanggal tanda tangan (WIB), status "ditandatangani"/"belum". TANPA NIP, tanpa unduh PDF.
 * - {@see self::pdfTte()} — Q6-22 / AC-02.7: PDF surat cuti ber-TTE (isi QR gambar tanda tangan, ikut legacy URL langsung).
 *   Hanya berkas surat TTE cuti itu; surat tanpa TTE → 404.
 *
 * Token wajib 64 heks huruf kecil (bentuk {@see TokenQr}); token salah/tidak aktif/cuti tidak ada → 404 seragam dengan
 * pesan sama untuk semua alasan. Log `info` tanpa token dan tanpa IP.
 */
final class VerifikasiSuratCutiService
{
    public const PESAN_TIDAK_DITEMUKAN = 'Dokumen tidak ditemukan atau tidak valid.';

    /** Nama berkas PDF publik (`Content-Disposition: inline`). */
    public const NAMA_BERKAS = 'surat_cuti_ber_tte.pdf';

    public const JENIS_SURAT = 'Surat Cuti';

    public const STATUS_DITANDATANGANI = 'ditandatangani';

    public const STATUS_BELUM = 'belum';

    public function __construct(
        private readonly TokenSuratCutiInterface $token,
        private readonly SuratCutiService $surat,
        private readonly ?BaseConnection $db = null,
    ) {
    }

    /**
     * @return array{status_keaslian: string, jenis_surat: string, jenis_cuti: string|null, penanda_tangan: string|null, tanggal_tanda_tangan: string|null, status_tte: string}
     */
    public function verifikasi(string $token): array
    {
        $cuti = $this->cuti($token, 'verifikasi');
        $ttd  = $this->koneksi()->table('esign_hist')->select('nama_lengkap, created_at')
            ->where(['modul' => EsignCutiService::MODUL, 'id_rw' => (int) $cuti['id_riwayat_cuti'], 'status' => '1'])
            ->orderBy('id_esign_hist', 'DESC')->get(1)->getRowArray();
        $adaTte = $ttd !== null && $this->surat->adaTte((string) $cuti['nip'], (int) $cuti['id_riwayat_cuti']);

        return [
            'status_keaslian'      => 'asli',
            'jenis_surat'          => self::JENIS_SURAT,
            'jenis_cuti'           => $this->teks($cuti['nama_jenis_cuti'] ?? null),
            'penanda_tangan'       => $adaTte ? $this->teks($ttd['nama_lengkap']) : $this->teks($cuti['nama_yang_menyetujui'] ?? null) ?? $this->teks($cuti['nama_atasan_langsung'] ?? null),
            'tanggal_tanda_tangan' => $adaTte ? $this->wib((string) $ttd['created_at']) : null,
            'status_tte'           => $adaTte ? self::STATUS_DITANDATANGANI : self::STATUS_BELUM,
        ];
    }

    /**
     * @return array{isi: string, nama: string}
     */
    public function pdfTte(string $token): array
    {
        $cuti = $this->cuti($token, 'pdf');
        $tte  = $this->surat->tte((string) $cuti['nip'], (int) $cuti['id_riwayat_cuti']);

        if ($tte === null || $tte['isi'] === '') {
            log_message('info', 'Surat cuti publik pdf: tidak ditemukan.');

            throw new NotFoundException(self::PESAN_TIDAK_DITEMUKAN);
        }

        // S6 review B4a: nama berkas generik — tanpa id cuti (urutan id bisa ditebak) maupun NIP.
        return ['isi' => $tte['isi'], 'nama' => self::NAMA_BERKAS];
    }

    /**
     * @return array<string, mixed> baris `riwayat_cuti` (+ `nama_jenis_cuti`) berstatus final 1/4
     */
    private function cuti(string $token, string $jalur): array
    {
        $id   = TokenQr::valid($token) ? $this->token->idCutiAktif($token) : null;
        $cuti = $id === null ? null : $this->koneksi()->table('riwayat_cuti c')
            ->select('c.id_riwayat_cuti, c.nip, c.status, c.nama_yang_menyetujui, c.nama_atasan_langsung, j.nama_jenis_cuti')
            ->join('jenis_cuti j', 'j.id_jenis_cuti = c.id_jenis_cuti', 'left')
            ->where('c.id_riwayat_cuti', $id)->get(1)->getRowArray();

        if ($cuti === null || ! in_array((int) $cuti['status'], StatusCuti::EFEK_FINAL, true)) {
            log_message('info', "Surat cuti publik {$jalur}: tidak ditemukan.");

            throw new NotFoundException(self::PESAN_TIDAK_DITEMUKAN);
        }

        log_message('info', "Surat cuti publik {$jalur}: ditemukan.");

        return $cuti;
    }

    private function wib(string $utc): ?string
    {
        if ($utc === '') {
            return null;
        }

        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i') . ' WIB';
    }

    private function teks(mixed $nilai): ?string
    {
        return is_string($nilai) && trim($nilai) !== '' ? trim($nilai) : null;
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
