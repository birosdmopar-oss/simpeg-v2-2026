<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi Konfirmasi Ketidakhadiran (B-13, CR-050 temuan QA B-F4; keputusan reviewer CR 15-10-2026 opsi A).
 *
 * Jenis Konket yang TIDAK boleh dipilih per role, berkunci PK `jenis_konket.id_jenis_konket` [K] — sama dengan legacy
 * `libraries/hr/rwy/L_konket.php` (pegawai & role 3: `NOT IN (2,4,5,6)` :91,:153; role 1: `NOT IN (4,5)` :36,:149).
 * Dipilih id, bukan `old_id` atau nama: impor master "copy langsung" mempertahankan PK legacy; `old_id` produksi bisa
 * NULL/ganda/bukan angka (DBV-005 risiko #2, UI master legacy tidak pernah mengisinya); nama bisa diganti admin. Arti
 * id 2/4/5/6 di data produksi WAJIB diverifikasi saat cutover (runbook DBV-011 2.4, B7).
 *
 * Override `.env` (opsional): `konket.jenisTerlarangPegawai = 2,4,5,6` / `konket.jenisTerlarangAdmin = 4,5` — daftar
 * bilangan bulat positif dipisah koma. Nilai kosong atau berisi token yang bukan bilangan bulat positif DIABAIKAN
 * seluruhnya (nilai bawaan legacy tetap dipakai), supaya salah ketik tidak membuka jenis terlarang.
 */
class Konket extends BaseConfig
{
    /**
     * id_jenis_konket terlarang untuk pegawai (role 2/6/7) dan Admin Satker (role 3).
     *
     * @var list<int>
     */
    public array $jenisTerlarangPegawai = [2, 4, 5, 6];

    /**
     * id_jenis_konket terlarang untuk Super Admin (role 1).
     *
     * @var list<int>
     */
    public array $jenisTerlarangAdmin = [4, 5];

    public function __construct()
    {
        parent::__construct();

        $this->jenisTerlarangPegawai = self::daftarDariEnv('konket.jenisTerlarangPegawai', $this->jenisTerlarangPegawai);
        $this->jenisTerlarangAdmin   = self::daftarDariEnv('konket.jenisTerlarangAdmin', $this->jenisTerlarangAdmin);
    }

    /**
     * Daftar id dari variabel lingkungan `$kunci` ("2,4,5,6"); tidak ada, kosong, atau ada token tidak sah → $bawaan.
     *
     * @param list<int> $bawaan
     *
     * @return list<int>
     */
    public static function daftarDariEnv(string $kunci, array $bawaan): array
    {
        $nilai = env($kunci);

        return is_string($nilai) ? (self::parseDaftar($nilai) ?? $bawaan) : $bawaan;
    }

    /**
     * "2, 4,5" → [2, 4, 5]; kosong atau ada token bukan bilangan bulat positif → null.
     *
     * @return list<int>|null
     */
    public static function parseDaftar(string $nilai): ?array
    {
        $token = array_map('trim', explode(',', $nilai));

        if (trim($nilai) === '' || array_filter($token, static fn (string $t): bool => preg_match('/^[1-9][0-9]{0,9}$/', $t) !== 1) !== []) {
            return null;
        }

        return array_values(array_unique(array_map('intval', $token)));
    }
}
