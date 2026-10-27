<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Notifikasi;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

/**
 * Templat teks notifikasi cuti (PRD Fase 4 v1.3 §6.6.2), fungsi murni tanpa DB/IO. Teks dari legacy
 * (`FH:762-915`, `L:3538-3637`, `L_notification.php:2579-2665, 3201-3245`); typo "anda anda" (N7) diperbaiki.
 *
 * Setiap templat mengembalikan `{judul, isi}`; `tipe` (`inbox_msg.tipe`) dipilih pemanggil. Batas kolom: judul 150, isi 500
 * (dipotong oleh {@see \App\Libraries\Layanan\NotifikasiService}). Data masuk = baris `riwayat_cuti` (snapshot nama/jenis/tanggal).
 */
final class TemplatNotifikasi
{
    /** `inbox_msg.tipe`. */
    public const TIPE_DIAJUKAN = 1;

    public const TIPE_KEPUTUSAN = 2;

    public const TIPE_PERUBAHAN = 3;

    public const TIPE_TEMBUSAN = 4;

    private const LABEL_KEPUTUSAN = [1 => 'disetujui', 2 => 'ditolak', 4 => 'ditangguhkan'];

    private const JUDUL_KEPUTUSAN = [1 => 'Cuti Online Disetujui', 2 => 'Cuti Online Ditolak', 4 => 'Cuti Online Ditangguhkan'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $cuti
     *
     * @return array{judul: string, isi: string}
     */
    public static function diajukan(array $cuti): array
    {
        return [
            'judul' => 'Permohonan Cuti Online Baru',
            'isi'   => self::s($cuti, 'nama') . ' telah mengajukan cuti (' . self::s($cuti, 'nama_jenis_cuti') . ') untuk tanggal ' . self::rentang($cuti, ' s/d ') . '.',
        ];
    }

    /**
     * @param array<string, mixed> $cuti
     *
     * @return array{judul: string, isi: string}
     */
    public static function tembusanDiajukan(array $cuti): array
    {
        return [
            'judul' => 'Tembusan Cuti Online',
            'isi'   => self::s($cuti, 'nama') . ' melakukan pengajuan ' . self::s($cuti, 'nama_jenis_cuti') . ' dari tanggal ' . self::rentang($cuti, ' s.d ') . '.',
        ];
    }

    /**
     * @param array<string, mixed> $cuti
     *
     * @return array{judul: string, isi: string}
     */
    public static function diubah(array $cuti): array
    {
        return [
            'judul' => 'Perubahan Cuti Online',
            'isi'   => 'Data: (' . self::s($cuti, 'nama_jenis_cuti') . ') ' . self::rentang($cuti, ' s/d ') . ' oleh ' . self::s($cuti, 'nama') . ' / ' . self::s($cuti, 'nip'),
        ];
    }

    /**
     * AL setuju pada alur dua langkah (T4): kabar ke pemohon.
     *
     * @param array<string, mixed> $cuti
     *
     * @return array{judul: string, isi: string}
     */
    public static function sedangDiproses(array $cuti): array
    {
        return [
            'judul' => 'Cuti Online Sedang Diproses',
            'isi'   => 'Pengajuan cuti anda pada tanggal ' . self::rentang($cuti, ' s/d ') . ' disetujui atasan langsung ' . self::s($cuti, 'nama_atasan_langsung')
                . ' dan menunggu persetujuan ' . self::s($cuti, 'nama_yang_menyetujui') . '.',
        ];
    }

    /**
     * Keputusan final ke pemohon. `$statusFinal`: 1 disetujui, 2 ditolak, 4 ditangguhkan. Tolak memuat alasan.
     *
     * @param array<string, mixed> $cuti
     *
     * @return array{judul: string, isi: string}
     */
    public static function keputusan(array $cuti, int $statusFinal, string $namaApprover, ?string $reasonNote): array
    {
        $isi = 'Pengajuan cuti anda pada tanggal ' . self::rentang($cuti, ' s/d ') . ' ' . (self::LABEL_KEPUTUSAN[$statusFinal] ?? 'diproses') . ' oleh ' . $namaApprover . '.';

        if ($statusFinal === 2 && $reasonNote !== null && trim($reasonNote) !== '') {
            $isi .= ' Ket: ' . trim($reasonNote);
        }

        return ['judul' => self::JUDUL_KEPUTUSAN[$statusFinal] ?? 'Cuti Online Diproses', 'isi' => $isi];
    }

    /**
     * Tembusan ketua tim pada setiap perubahan status. `$status`: 1, 2, 4 (final) atau 3 (sedang diproses).
     *
     * @param array<string, mixed> $cuti
     *
     * @return array{judul: string, isi: string}
     */
    public static function tembusanStatus(array $cuti, int $status, string $namaApprover): array
    {
        $label = $status === 3 ? 'sedang diproses' : (self::LABEL_KEPUTUSAN[$status] ?? 'diproses');
        $oleh  = $status === 3 || $namaApprover === '' ? '' : ' oleh ' . $namaApprover;

        return [
            'judul' => 'Tembusan Cuti Online',
            'isi'   => 'Pengajuan cuti ' . self::s($cuti, 'nama') . ' (' . self::s($cuti, 'nama_jenis_cuti') . ') pada tanggal ' . self::rentang($cuti, ' s/d ') . ' ' . $label . $oleh . '.',
        ];
    }

    /**
     * @param array<string, mixed> $cuti
     *
     * @return array{judul: string, isi: string}
     */
    public static function suratTerbit(array $cuti): array
    {
        return [
            'judul' => 'Surat Cuti Online Terbit',
            'isi'   => 'Surat cuti tanggal ' . self::rentang($cuti, ' s/d ') . ' sudah ditandatangani secara elektronik.',
        ];
    }

    /**
     * Approver tahap aktif tak terjangkau (T1/T4, eselon I): ke semua admin role 1.
     *
     * @param array<string, mixed> $cuti
     *
     * @return array{judul: string, isi: string}
     */
    public static function perluAdmin(array $cuti, string $namaApprover): array
    {
        $siapa = trim($namaApprover) === '' ? 'Menteri' : trim($namaApprover);

        return [
            'judul' => 'Permohonan Cuti Perlu Diproses Admin',
            'isi'   => 'Pengajuan cuti ' . self::s($cuti, 'nama') . ' (' . self::s($cuti, 'nama_jenis_cuti') . ') ' . self::rentang($cuti, ' s/d ')
                . ' menunggu ' . $siapa . ' yang belum dapat memproses di sistem. Proses atas nama melalui menu Cuti.',
        ];
    }

    /**
     * @param array<string, mixed> $cuti
     */
    private static function s(array $cuti, string $kunci): string
    {
        return trim((string) ($cuti[$kunci] ?? ''));
    }

    /**
     * @param array<string, mixed> $cuti
     */
    private static function rentang(array $cuti, string $pemisah): string
    {
        return self::tanggal(self::s($cuti, 'tgl_mulai')) . $pemisah . self::tanggal(self::s($cuti, 'tgl_akhir'));
    }

    private static function tanggal(string $ymd): string
    {
        return $ymd === '' ? '' : TanggalBisnis::formatIndonesia(substr($ymd, 0, 10));
    }
}
