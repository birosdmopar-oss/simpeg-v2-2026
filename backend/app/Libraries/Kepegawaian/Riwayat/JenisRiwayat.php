<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

/**
 * Daftar slug `{jenis}` riwayat (DIBEKUKAN S0-A MAKE-002; pemilik WS-1). Slug dipakai di URL
 * `api/v1/pegawai/{nip}/riwayat/{jenis}`, di RiwayatDefinisi::jenis(), dan sebagai nama berkas registry frontend
 * `riwayat/jenis/<slug>.ts`. Perubahan setelah beku hanya aditif (README kontrak Kepegawaian).
 *
 * Konket dan LKH BUKAN jenis engine (endpoint sendiri milik WS-2). `karpeg`/`kariskarsu` memakai alur usulan mandiri
 * (bukan tab Detail Pegawai).
 */
final class JenisRiwayat
{
    /**
     * @var list<string>
     */
    public const SLUG = [
        'jabatan',
        'kp',
        'kgb',
        'pendidikan',
        'diklat',
        'seminar',
        'skp',
        'skp-periodik',
        'hukdis',
        'ak',
        'ak-siasn',
        'keluarga',
        'alamat',
        'tanda-jasa',
        'organisasi',
        'karpeg',
        'kariskarsu',
    ];

    private function __construct()
    {
    }

    public static function valid(string $slug): bool
    {
        return in_array($slug, self::SLUG, true);
    }
}
