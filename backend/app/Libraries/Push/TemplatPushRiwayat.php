<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Libraries\Waktu\StempelUtc;
use DateTimeImmutable;
use Throwable;

/**
 * Templat push web riwayat (PRD Fase 6 §7.4, F6-13; MAKE-066) — ikut `fcmQueue_rwy` / `fcmQueue_layanan` legacy
 * [K `FH:588-915`]:
 *
 * - judul: tipe 1 "Permohonan {judul} Baru", tipe 2 "{judul} {aksi}", tipe 3 "Perubahan {judul}";
 * - isi: "Data: {pratinjau}" + (tipe 1/3) " oleh {nama bergelar} / {nip}"; ". Ket: {alasan}" hanya pada kondisi legacy
 *   (tipe 2, status ditolak, alasan terisi — SKP memakai status 'X' di legacy sehingga tidak pernah tampil, ikut legacy);
 * - penerima: tipe 1/3 → admin pembawah ({@see PenerimaTokenFcm::webAdmin}), LKH → atasan (`nip_atasan`), Karpeg/Kariskarsu →
 *   petugas layanan 1/2; tipe 2 → pegawai;
 * - tautan [V2]: rute SPA relatif (legacy `hr/rwy/<jenis>/process|detail/<id>` tidak ada di v2), dibawa di `data.link`.
 *
 * Jenis tanpa push di legacy (`ak` dikomentari, `ak-siasn`, `hukdis`, `skp-periodik`) → tanpa push. Kelas murni (tanpa DB).
 */
final class TemplatPushRiwayat
{
    public const PENERIMA_ADMIN = 'admin';

    public const PENERIMA_ATASAN = 'atasan';

    public const PENERIMA_PEGAWAI = 'pegawai';

    public const PENERIMA_PETUGAS = 'petugas';

    /**
     * jenis v2 → [judul legacy, tabel, kolom PK, id petugas layanan (getTokenPL) | null].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: int|null}>
     */
    public const JENIS = [
        'alamat'     => ['Data Alamat', 'riwayat_alamat', 'id_riwayat_alamat', null],
        'diklat'     => ['Riwayat Pelatihan', 'riwayat_diklat', 'id_riwayat_diklat', null],
        'jabatan'    => ['Riwayat Jabatan', 'riwayat_mutasi_jabatan', 'id_riwayat_mutasi_jabatan', null],
        'kgb'        => ['Riwayat KGB', 'riwayat_kgb', 'id_riwayat_kgb', null],
        'kp'         => ['Riwayat Pangkat', 'riwayat_kp', 'id_riwayat_kp', null],
        'organisasi' => ['Riwayat Organisasi', 'riwayat_organisasi', 'id_riwayat_organisasi', null],
        'pendidikan' => ['Riwayat Pendidikan', 'riwayat_pendidikan', 'id_riwayat_pendidikan', null],
        'keluarga'   => ['Data Keluarga', 'riwayat_keluarga', 'id_riwayat_keluarga', null],
        'seminar'    => ['Riwayat Kursus/Seminar', 'riwayat_seminar', 'id_riwayat_seminar', null],
        'tanda-jasa' => ['Riwayat Tanda Jasa', 'riwayat_tanda_jasa', 'id_riwayat_tanda_jasa', null],
        'skp'        => ['Riwayat SKP', 'riwayat_skp', 'id_riwayat_skp', null],
        'karpeg'     => ['KARPEG', 'riwayat_karpeg', 'id_riwayat_karpeg', 1],
        'kariskarsu' => ['KARISKARSU', 'riwayat_kariskarsu', 'id_riwayat_kariskarsu', 2],
        'lkh'        => ['Data LKH', 'riwayat_lckh', 'id_riwayat_lckh', null],
        'konket'     => ['Konfirmasi Ketidakhadiran', 'absen_ijin', 'id', null],
    ];

    /** `id_tanda_jasa` "lainnya" — nama dari `tanda_jasa_lain` [K `FH:705`]. */
    private const TANDA_JASA_LAIN = 44;

    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public static function didukung(string $jenis): bool
    {
        return isset(self::JENIS[$jenis]);
    }

    /**
     * Jenis penerima menurut tipe + jenis [K `FH:604-617`].
     */
    public static function penerima(int $tipe, string $jenis): ?string
    {
        if ($tipe === 2) {
            return self::PENERIMA_PEGAWAI;
        }

        if ($tipe !== 1 && $tipe !== 3) {
            return null;
        }

        return match (true) {
            $jenis === 'lkh'                          => self::PENERIMA_ATASAN,
            (self::JENIS[$jenis][3] ?? null) !== null => self::PENERIMA_PETUGAS,
            default                                   => self::PENERIMA_ADMIN,
        };
    }

    /**
     * @param array<string, mixed>      $baris   baris riwayat (kolom tabel jenis)
     * @param array<string, mixed>|null $pegawai `{glr_awal, nama, glr_akhir}` pegawai pemilik (untuk tipe 1/3)
     * @param string|null               $jenisKonket nama jenis konket (`jenis_konket.old_id` = `kategori`)
     *
     * @return array{judul: string, isi: string, link: string}|null null bila jenis/tipe tidak punya push di legacy
     */
    public static function susun(int $tipe, string $jenis, array $baris, ?array $pegawai, ?string $aksi, ?string $jenisKonket = null): ?array
    {
        if (! self::didukung($jenis) || self::penerima($tipe, $jenis) === null) {
            return null;
        }

        $judulJenis = self::JENIS[$jenis][0];
        $judul      = match ($tipe) {
            1       => "Permohonan {$judulJenis} Baru",
            2       => trim("{$judulJenis} " . (string) $aksi),
            default => "Perubahan {$judulJenis}",
        };

        $isi = 'Data: ' . self::pratinjau($tipe, $jenis, $baris, $jenisKonket);
        $nip = (string) ($baris['nip'] ?? '');

        if ($tipe !== 2 && $pegawai !== null) {
            $isi .= ' oleh ' . self::namaGelar($pegawai) . " / {$nip}";
        }

        return ['judul' => $judul, 'isi' => $isi, 'link' => self::tautan($tipe, $jenis, $nip)];
    }

    /**
     * Tanggal "j F Y" bulan Indonesia — `dateToUserFull_local` [K `FH:1170`]; nilai kosong/rusak → ''.
     */
    public static function tanggal(mixed $nilai): string
    {
        $teks = trim((string) $nilai);

        if ($teks === '' || str_starts_with($teks, '0000-00-00')) {
            return '';
        }

        try {
            $dt = new DateTimeImmutable($teks);
        } catch (Throwable) {
            return '';
        }

        return $dt->format('j') . ' ' . self::BULAN[(int) $dt->format('n')] . ' ' . $dt->format('Y');
    }

    /**
     * @param array<string, mixed> $b
     */
    private static function pratinjau(int $tipe, string $jenis, array $b, ?string $jenisKonket): string
    {
        $s = static fn (string $k): string => trim((string) ($b[$k] ?? ''));

        $teks = match ($jenis) {
            'alamat'     => self::pilih([1 => 'Alamat KTP', 2 => 'Alamat Domisili'], $s('jenis_alamat')) . " - {$s('alamat')}",
            'diklat'     => self::pilih([1 => 'Struktural', 2 => 'Teknis', 3 => 'Fungsional', 4 => 'Prajabatan'], $s('jenis_diklat')) . " - {$s('deskripsi')}",
            'jabatan'    => "{$s('jabatan')} - {$s('satker')}",
            'kgb'        => 'Rp. ' . ((float) $s('gaji_pokok') > 0 ? number_format((float) $s('gaji_pokok'), 0, ',', '.') : '0') . " - Masa Kerja: {$s('mker_gol_th')} Tahun",
            'kp'         => "{$s('jenis_kp')} - {$s('gol_ruang')}",
            'organisasi' => $s('nama_organisasi'),
            'pendidikan' => "{$s('jenjang_pendidikan_singkat')} - {$s('institusi_pendidikan')}",
            'keluarga'   => "{$s('nama_pasangan')} - {$s('jumlah_anak')} Anak",
            'seminar'    => self::pilih([1 => 'Seminar', 2 => 'Kursus'], $s('jenis_seminar')) . " - {$s('nama_seminar')}",
            'tanda-jasa' => ((int) $s('id_tanda_jasa') === self::TANDA_JASA_LAIN ? $s('tanda_jasa_lain') : $s('tanda_jasa')) . ' - ' . self::tanggal($b['tgl_sertifikat'] ?? null),
            'skp'        => "{$s('tahun')} - " . ((float) $s('nilai_prestasi_kerja') > 0 ? str_replace('.', ',', (string) ((float) $s('nilai_prestasi_kerja') + 0)) : '0') . " [{$s('kategori_nilai_prestasi')}]",
            // `created_at` v2 = UTC (CR-086); legacy menyimpan jam dinding WIB → tanggal bisnis WIB (BP-10, review MAKE-066 P6).
            'karpeg'     => 'Permohonan tanggal ' . self::tanggal(StempelUtc::tanggalWib($s('created_at') ?: null)),
            'kariskarsu' => "({$s('jenis_permohonan')}) " . self::tanggal(StempelUtc::tanggalWib($s('created_at') ?: null)),
            'lkh'        => 'LKH ' . self::tanggal($b['tgl_laporan'] ?? null),
            'konket'     => '(' . self::jenisKonket($jenisKonket, $b) . ') ' . self::tanggal($b['date_start'] ?? null) . ' s/d ' . self::tanggal($b['date_end'] ?? null),
            default      => '',
        };

        if ($tipe !== 2) {
            return $teks;
        }

        // Keterangan penolakan pada kondisi legacy masing-masing jenis.
        $ket = match ($jenis) {
            'lkh'    => in_array($s('status'), ['2', '3'], true) ? $s('catatan') : '',
            'konket' => $s('status') === 'X' ? $s('reason_note') : '',
            'skp'    => '',
            default  => $s('status') === '2' ? $s('reason_note') : '',
        };

        return $ket === '' ? $teks : "{$teks}. Ket: {$ket}";
    }

    /**
     * @param array<string, mixed> $b
     */
    private static function jenisKonket(?string $nama, array $b): string
    {
        $nama = trim((string) $nama);

        // Kategori dinas (8) + jenis dinas 1 → "GABUNGAN" [K `FH:723-725`].
        return $nama !== '' && (string) ($b['kategori'] ?? '') === '8' && (string) ($b['jenis_dinas'] ?? '') === '1' ? "{$nama} GABUNGAN" : $nama;
    }

    private static function tautan(int $tipe, string $jenis, string $nip): string
    {
        return match ($jenis) {
            'lkh'                  => '/lkh/verifikasi' . ($tipe === 2 ? '' : '?tab=verifikasi'),
            'konket'               => '/konket',
            'karpeg', 'kariskarsu' => "/karpeg-karis?jenis={$jenis}",
            default                => '/pegawai/' . rawurlencode($nip) . "/riwayat?tab={$jenis}",
        };
    }

    /**
     * `formatNamaGelar` [K `FH:102`].
     *
     * @param array<string, mixed> $p
     */
    private static function namaGelar(array $p): string
    {
        $hasil = trim((string) ($p['glr_awal'] ?? '')) . ' ' . trim((string) ($p['nama'] ?? ''));
        $akhir = trim((string) ($p['glr_akhir'] ?? ''));

        return trim($akhir === '' ? $hasil : "{$hasil}, {$akhir}");
    }

    /**
     * @param array<int, string> $peta label legacy per kode angka
     */
    private static function pilih(array $peta, string $kunci): string
    {
        return ctype_digit($kunci) ? ($peta[(int) $kunci] ?? '') : '';
    }
}
