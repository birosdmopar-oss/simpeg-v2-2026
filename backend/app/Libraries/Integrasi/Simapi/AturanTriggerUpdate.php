<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use Closure;

/**
 * MAKE-076 (Q6-41, DBV-031 §4) — port aturan trigger legacy penulis `simapi_update` (antrean API SIMAPI lama
 * `simapi/a_pegawai/pegawai_update`; D1 ISSUE-056, isi mentah tidak disalin). Murni (tanpa DB/IO), pasangan {@see AturanTrigger}
 * dengan semantik MySQL yang sama (CONCAT NULL, COALESCE, perbandingan NULL = salah).
 *
 * `last_action` per peristiwa (DBV T2): `pegawai` INSERT/UPDATE/DELETE; `pegawai_mutasi_jabatan` dan `pengguna` selalu 'UPDATE'.
 *
 * Penyimpangan [V2]:
 *  - URL foto = `PenyajiPegawai::urlFoto()` (Q6-39), bukan host arsip legacy.
 *  - Nilai `password` = hash yang SEDANG berlaku untuk akun: `password` (Argon2id) bila terisi, selain itu `password_legacy`
 *    (MD5 legacy) — keputusan reviewer 09-11 (opsi b). Pembanding "berubah" memakai nilai yang sama, sehingga rehash otomatis
 *    MD5 → Argon2id saat login ikut tercatat.
 *  - `pegawai` INSERT: legacy menulis `MD5(nip)` (password bawaan legacy) — v2 tidak pernah memakai password bawaan itu, jadi
 *    diisi hash akun ber-NIP sama bila sudah ada, selain itu '' (akun dibuat sesudahnya memancar `pengguna` INSERT sendiri).
 *  - `pengguna`: `id_pegawai` → `nip`, `UserLevel` → `user_level` (skema v2).
 */
final class AturanTriggerUpdate
{
    /** Tabel sumber penulis simapi_update. [K D1] */
    public const TABEL = ['pegawai', 'pegawai_mutasi_jabatan', 'pengguna'];

    private const FIELD_PEGAWAI_INSERT = 'nip|nama_lengkap|foto|username|password|email|jabatan|unit_kerja|satuan_kerja|jenis_pegawai|status';
    private const FIELD_PMJ            = 'jabatan|unit_kerja|satuan_kerja';

    /**
     * @param array<string, mixed>|null $lama
     * @param array<string, mixed>|null $baru
     * @param array{url_foto?: Closure(string, string): string, hash_akun?: Closure(string): ?string} $bantu
     *
     * @return list<array{nip: ?string, last_action: string, nama_field: ?string, nilai_lama: ?string, nilai_baru: ?string}>
     */
    public static function baris(string $tabel, string $peristiwa, ?array $lama, ?array $baru, array $bantu = []): array
    {
        $urlFoto = $bantu['url_foto'] ?? static fn (): string => '';

        return match ($tabel) {
            'pegawai'                => self::pegawai($peristiwa, $lama, $baru, $urlFoto, $bantu['hash_akun'] ?? static fn (): ?string => null),
            'pegawai_mutasi_jabatan' => self::pmj($peristiwa, $lama, $baru),
            'pengguna'               => self::pengguna($peristiwa, $lama, $baru),
            default                  => [],
        };
    }

    /**
     * Hash password yang sedang berlaku untuk baris `pengguna` v2 (Argon2id, selain itu MD5 legacy); null bila keduanya kosong.
     *
     * @param array<string, mixed> $akun
     */
    public static function hashBerlaku(array $akun): ?string
    {
        $argon = $akun['password'] ?? null;

        if ($argon !== null && (string) $argon !== '') {
            return (string) $argon;
        }

        $md5 = $akun['password_legacy'] ?? null;

        return $md5 === null || (string) $md5 === '' ? null : (string) $md5;
    }

    // ------------------------------------------------------------------
    // pegawai
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private static function pegawai(string $p, ?array $lama, ?array $baru, Closure $urlFoto, Closure $hashAkun): array
    {
        if ($p === AturanTrigger::DELETE) {
            return [self::u($lama['nip'] ?? null, 'DELETE', null, null, null)];
        }

        $n = $baru ?? [];

        if (! AturanTrigger::sama($n['status'] ?? null, '1')) {
            return [];
        }

        if ($p === AturanTrigger::INSERT) {
            $nip   = $n['nip'] ?? null;
            $hash  = $nip === null ? null : $hashAkun((string) $nip);
            $nilai = AturanTrigger::gabung($nip, '|', self::namaLengkap($n), '|', self::foto($n, $urlFoto), '|', $nip, '|', $hash ?? '', '|||||', AturanTrigger::k($n['jenis_pegawai'] ?? null), '|Aktif');

            return [self::u($nip, 'INSERT', self::FIELD_PEGAWAI_INSERT, null, $nilai)];
        }

        $l      = $lama ?? [];
        $field  = [];
        $nLama  = [];
        $nBaru  = [];
        $ubah   = false;
        $tambah = static function (string $f, ?string $a, ?string $b) use (&$field, &$nLama, &$nBaru, &$ubah): void {
            $ubah    = true;
            $field[] = $f;
            $nLama[] = $a;
            $nBaru[] = $b;
        };

        if (AturanTrigger::k($l['glr_awal'] ?? null) !== AturanTrigger::k($n['glr_awal'] ?? null) || AturanTrigger::beda($l['nama'] ?? null, $n['nama'] ?? null) || AturanTrigger::k($l['glr_akhir'] ?? null) !== AturanTrigger::k($n['glr_akhir'] ?? null)) {
            $tambah('nama_lengkap', self::namaLengkap($l), self::namaLengkap($n));
        }

        if (AturanTrigger::beda($l['nip'] ?? null, $n['nip'] ?? null)) {
            $tambah('nip', (string) $l['nip'], (string) $n['nip']);
        }

        if (AturanTrigger::beda($l['status'] ?? null, $n['status'] ?? null)) {
            $tambah('status', AturanTrigger::sama($l['status'] ?? null, '1') ? 'Aktif' : 'Tidak Aktif', 'Aktif');
        }

        if (AturanTrigger::k($l['jenis_pegawai'] ?? null) !== AturanTrigger::k($n['jenis_pegawai'] ?? null)) {
            $tambah('jenis_pegawai', AturanTrigger::k($l['jenis_pegawai'] ?? null), AturanTrigger::k($n['jenis_pegawai'] ?? null));
        }

        if (AturanTrigger::k($l['foto'] ?? null) !== AturanTrigger::k($n['foto'] ?? null)) {
            $tambah('foto', self::foto($l, $urlFoto), self::foto($n, $urlFoto));
        }

        if (! $ubah) {
            return [];
        }

        $nip = AturanTrigger::beda($l['nip'] ?? null, $n['nip'] ?? null) ? $l['nip'] : ($n['nip'] ?? null);

        return [self::u($nip, 'UPDATE', implode('|', $field), self::pipa($nLama), self::pipa($nBaru))];
    }

    /**
     * [K] `TRIM(CONCAT(COALESCE(glr_awal,''), ' ', nama[, ', ', COALESCE(glr_akhir,'')]))`; nama NULL → NULL.
     *
     * @param array<string, mixed> $r
     */
    private static function namaLengkap(array $r): ?string
    {
        $akhir = AturanTrigger::k($r['glr_akhir'] ?? null);
        $s     = $akhir === ''
            ? AturanTrigger::gabung(AturanTrigger::k($r['glr_awal'] ?? null), ' ', $r['nama'] ?? null)
            : AturanTrigger::gabung(AturanTrigger::k($r['glr_awal'] ?? null), ' ', $r['nama'] ?? null, ', ', $akhir);

        return $s === null ? null : trim($s, ' ');
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function foto(array $r, Closure $urlFoto): string
    {
        $path = AturanTrigger::k($r['foto'] ?? null);

        return $path === '' ? '' : $urlFoto(AturanTrigger::k($r['nip'] ?? null), $path);
    }

    // ------------------------------------------------------------------
    // pegawai_mutasi_jabatan — selalu 'UPDATE'
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private static function pmj(string $p, ?array $lama, ?array $baru): array
    {
        $nilai = static fn (array $r): string => AturanTrigger::k($r['jabatan'] ?? null) . '|' . AturanTrigger::k($r['unit'] ?? null) . '|' . AturanTrigger::k($r['satker'] ?? null);

        if ($p === AturanTrigger::INSERT) {
            return [self::u($baru['nip'] ?? null, 'UPDATE', self::FIELD_PMJ, '||', $nilai($baru ?? []))];
        }

        if ($p === AturanTrigger::DELETE) {
            return [self::u($lama['nip'] ?? null, 'UPDATE', self::FIELD_PMJ, $nilai($lama ?? []), '||')];
        }

        $l     = $lama ?? [];
        $n     = $baru ?? [];
        $field = [];
        $a     = [];
        $b     = [];

        foreach (['jabatan' => 'jabatan', 'unit' => 'unit_kerja', 'satker' => 'satuan_kerja'] as $kolom => $nama) {
            if (AturanTrigger::beda($l[$kolom] ?? null, $n[$kolom] ?? null)) { // [K] `OLD.x != NEW.x` (NULL → tidak)
                $field[] = $nama;
                $a[]     = AturanTrigger::k($l[$kolom] ?? null);
                $b[]     = AturanTrigger::k($n[$kolom] ?? null);
            }
        }

        return $field === [] ? [] : [self::u($n['nip'] ?? null, 'UPDATE', implode('|', $field), implode('|', $a), implode('|', $b))];
    }

    // ------------------------------------------------------------------
    // pengguna — selalu 'UPDATE'; hanya level pegawai (2/6/7) berstatus aktif
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private static function pengguna(string $p, ?array $lama, ?array $baru): array
    {
        if ($p === AturanTrigger::DELETE || $baru === null) {
            return [];
        }

        if (! in_array(AturanTrigger::k($baru['user_level'] ?? null), ['2', '6', '7'], true) || ! AturanTrigger::sama($baru['status'] ?? null, '1')) {
            return [];
        }

        $hashBaru = self::hashBerlaku($baru);

        if ($p === AturanTrigger::INSERT) {
            $nilai = AturanTrigger::gabung($baru['username'] ?? null, '|', $hashBaru, '|', AturanTrigger::k($baru['email'] ?? null));

            return [self::u($baru['nip'] ?? null, 'UPDATE', 'username|password|email', '||', $nilai)];
        }

        $l     = $lama ?? [];
        $field = [];
        $a     = [];
        $b     = [];
        $cek   = [
            'username' => [AturanTrigger::k($l['username'] ?? null), AturanTrigger::k($baru['username'] ?? null)],
            'password' => [AturanTrigger::k(self::hashBerlaku($l)), AturanTrigger::k($hashBaru)],
            'email'    => [AturanTrigger::k($l['email'] ?? null), AturanTrigger::k($baru['email'] ?? null)],
        ];

        foreach ($cek as $nama => [$x, $y]) {
            if ($x !== $y) {
                $field[] = $nama;
                $a[]     = $x;
                $b[]     = $y;
            }
        }

        return $field === [] ? [] : [self::u($baru['nip'] ?? null, 'UPDATE', implode('|', $field), implode('|', $a), implode('|', $b))];
    }

    // ------------------------------------------------------------------

    /**
     * Gabung nilai dengan `|` mengikuti CONCAT berantai trigger (satu bagian NULL → seluruhnya NULL).
     *
     * @param list<?string> $nilai
     */
    private static function pipa(array $nilai): ?string
    {
        $bagian = [];

        foreach ($nilai as $i => $v) {
            if ($i > 0) {
                $bagian[] = '|';
            }

            $bagian[] = $v;
        }

        return AturanTrigger::gabung(...$bagian);
    }

    /**
     * @return array{nip: ?string, last_action: string, nama_field: ?string, nilai_lama: ?string, nilai_baru: ?string}
     */
    private static function u(mixed $nip, string $aksi, ?string $field, ?string $lama, ?string $baru): array
    {
        return ['nip' => $nip === null ? null : (string) $nip, 'last_action' => $aksi, 'nama_field' => $field, 'nilai_lama' => $lama, 'nilai_baru' => $baru];
    }
}
