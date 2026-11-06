<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Layanan;

use App\Libraries\Integrasi\Simapi\Nilai;
use CodeIgniter\Database\BaseConnection;

/**
 * MAKE-059 (H-07, F6-10) — data unit kerja (Es. I) dan satuan kerja (Es. II) untuk `services/s_dm/*` [K `S_dm.php`]. Legacy
 * mengirim baris `SELECT *` utuh ditambah `unit_str`/`satker_str` di akhir; v2 mengirim kolom tabel v2 yang sama (skema
 * unit/satker ikut D1, DBV-008) dengan bentuk nilai {@see Nilai::mentahSemua()}. Baris v2 status 10 (= hapus keras legacy)
 * tidak pernah dikirim; daftar diberi kunci urut sekunder id agar stabil [V2]. `p_web_config` sengaja tidak dibuat (F6-10).
 */
final class PenyajiSDm
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * [K `S_dm.php:41-48`, `:112-119`, `:143-150`] unit status 1 urut `order`, opsional tanpa satu id unit.
     *
     * @return list<array<string, string|null>>
     */
    public function daftarUnit(?string $kecualiIdUnit = null): array
    {
        $q = $this->db->table('unit')->where('status', 1);

        if ($kecualiIdUnit !== null) {
            $q->where('id_unit !=', $kecualiIdUnit);
        }

        return array_map(static function (array $r): array {
            $r             = Nilai::mentahSemua($r);
            $r['unit_str'] = Nilai::teks($r['unit']);

            return $r;
        }, $q->orderBy('order', 'ASC')->orderBy('id_unit', 'ASC')->get()->getResultArray());
    }

    /**
     * [K `S_dm.php:83-90`, `:212-219`] satker status 1 milik satu unit, urut `order`.
     *
     * @return list<array<string, string|null>>
     */
    public function daftarSatker(string $idUnit): array
    {
        $rows = $this->db->table('satker')->where('status', 1)->where('id_unit', $idUnit)
            ->orderBy('order', 'ASC')->orderBy('id_satker', 'ASC')->get()->getResultArray();

        return array_map(static function (array $r): array {
            $r               = Nilai::mentahSemua($r);
            $r['satker_str'] = Nilai::teks($r['satker']);

            return $r;
        }, $rows);
    }

    /**
     * [K `S_dm.php:175-183`] satu unit (status apa pun kecuali 10), atau null.
     *
     * @return array<string, string|null>|null
     */
    public function unit(string $idUnit): ?array
    {
        $r = $this->db->table('unit')->where('id_unit', $idUnit)->where('status !=', 10)->get()->getRowArray();

        if ($r === null) {
            return null;
        }

        $r             = Nilai::mentahSemua($r);
        $r['unit_str'] = Nilai::teks($r['unit']);

        return $r;
    }

    /**
     * [K `S_dm.php:244-257`] satu satker + nama unit induk (`unit`, `unit_str`, `satker_str`), atau null.
     *
     * @return array<string, string|null>|null
     */
    public function satker(string $idSatker): ?array
    {
        $r = $this->db->table('satker stkr')->select('stkr.*, unt.unit')
            ->join('unit unt', 'unt.id_unit = stkr.id_unit', 'left')
            ->where('stkr.id_satker', $idSatker)->where('stkr.status !=', 10)->get()->getRowArray();

        if ($r === null) {
            return null;
        }

        $r               = Nilai::mentahSemua($r);
        $r['unit_str']   = Nilai::teks($r['unit']);
        $r['satker_str'] = Nilai::teks($r['satker']);

        return $r;
    }
}
