<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Simapi;

use CodeIgniter\Database\BaseConnection;

/**
 * MAKE-059 (H-07) — data master jabatan SIMAPI `a_dm/*` [K `A_dm.php`]. Nama field, urutan, filter, dan urutan baris persis
 * legacy; bentuk nilai lewat {@see Nilai} (field ber-`stripslashes` legacy → string, lainnya string/null).
 */
final class PenyajiDm
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * [K `A_dm.php:55-65`] group_jabatan status 1, `order` ASC.
     *
     * @return list<array<string, string|null>>
     */
    public function groupJabatan(): array
    {
        $rows = $this->db->table('group_jabatan')->select('id_group_jabatan, group_jabatan')
            ->where('status', 1)->orderBy('order', 'ASC')->orderBy('id_group_jabatan', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id_group_jabatan' => Nilai::mentah($r['id_group_jabatan']),
            'group_jabatan'    => Nilai::teks($r['group_jabatan']),
        ], $rows);
    }

    /**
     * [K `A_dm.php:112-146`] dua blok: group 1 (urut id sub, dengan nama group) lalu group ≠ 1 (urut id group, nama sub;
     * TANPA field `group_jabatan`).
     *
     * @return list<array<string, string|null>>
     */
    public function subGroupJabatan(): array
    {
        $dasar = fn () => $this->db->table('sub_group_jabatan sgj')
            ->select('sgj.id_group_jabatan, sgj.id_sub_group_jabatan, sgj.sub_group_jabatan, gj.group_jabatan')
            ->join('group_jabatan gj', 'gj.id_group_jabatan = sgj.id_group_jabatan', 'left')
            ->where('sgj.status', 1);

        $ds = [];

        foreach ($dasar()->where('sgj.id_group_jabatan', 1)->orderBy('sgj.id_sub_group_jabatan', 'ASC')->get()->getResultArray() as $r) {
            $ds[] = [
                'id_group_jabatan'     => Nilai::mentah($r['id_group_jabatan']),
                'group_jabatan'        => Nilai::teks($r['group_jabatan']),
                'id_sub_group_jabatan' => Nilai::mentah($r['id_sub_group_jabatan']),
                'sub_group_jabatan'    => Nilai::teks($r['sub_group_jabatan']),
            ];
        }

        foreach ($dasar()->where('sgj.id_group_jabatan !=', 1)->orderBy('sgj.id_group_jabatan', 'ASC')->orderBy('sgj.sub_group_jabatan', 'ASC')->get()->getResultArray() as $r) {
            $ds[] = [
                'id_group_jabatan'     => Nilai::mentah($r['id_group_jabatan']),
                'id_sub_group_jabatan' => Nilai::mentah($r['id_sub_group_jabatan']),
                'sub_group_jabatan'    => Nilai::teks($r['sub_group_jabatan']),
            ];
        }

        return $ds;
    }

    /**
     * [K `A_dm.php:192-219`] jabatan aktif dengan group/sub/unit/satker aktif (INNER efektif lewat WHERE status).
     *
     * @return list<array<string, string|null>>
     */
    public function jabatan(): array
    {
        $rows = $this->db->table('jabatan jab')
            ->select('jab.id_jabatan, jab.jabatan, jab.id_group_jabatan, jab.id_sub_group_jabatan, jab.id_satker, jab.umur_pensiun, gj.group_jabatan, sgj.sub_group_jabatan, unt.id_unit, unt.unit, sat.satker')
            ->join('sub_group_jabatan sgj', 'sgj.id_sub_group_jabatan = jab.id_sub_group_jabatan', 'left')
            ->join('group_jabatan gj', 'gj.id_group_jabatan = sgj.id_group_jabatan', 'left')
            ->join('satker sat', 'sat.id_satker = jab.id_satker', 'left')
            ->join('unit unt', 'unt.id_unit = sat.id_unit', 'left')
            ->where('jab.status', 1)->where('unt.status', 1)->where('sat.status', 1)->where('gj.status', 1)->where('sgj.status', 1)
            ->orderBy('jab.id_group_jabatan', 'ASC')->orderBy('sgj.id_sub_group_jabatan', 'ASC')->orderBy('unt.order', 'ASC')
            ->orderBy('sat.order', 'ASC')->orderBy('sat.id_satker', 'ASC')
            ->orderBy('jab.id_jabatan', 'ASC') // [V2] kunci sekunder agar urutan stabil (legacy tak menentukan)
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id_jabatan'           => Nilai::mentah($r['id_jabatan']),
            'jabatan'              => Nilai::teks($r['jabatan']),
            'id_group_jabatan'     => Nilai::mentah($r['id_group_jabatan']),
            'group_jabatan'        => Nilai::teks($r['group_jabatan']),
            'id_sub_group_jabatan' => Nilai::mentah($r['id_sub_group_jabatan']),
            'sub_group_jabatan'    => Nilai::teks($r['sub_group_jabatan']),
            'id_unit'              => Nilai::mentah($r['id_unit']),
            'unit'                 => Nilai::teks($r['unit']),
            'id_satker'            => Nilai::mentah($r['id_satker']),
            'satker'               => Nilai::teks($r['satker']),
            'umur_pensiun'         => Nilai::mentah($r['umur_pensiun']),
        ], $rows);
    }

    /**
     * [K `A_dm.php:265-289`] periode struktur jabatan status 1 terbaru (id DESC), baris selain id 1, urut level lalu urutan.
     * Nama jabatan dari tabel `jabatan` (bukan salinan di `struktur_jabatan`). Baris v2 status 10 (= hapus keras legacy)
     * tidak dikirim [V2].
     *
     * @return list<array<string, string|null>>
     */
    public function strukturJabatan(): array
    {
        $periode = $this->db->table('periode_struktur_jabatan')->select('id_periode_struktur_jabatan')
            ->where('status', 1)->orderBy('id_periode_struktur_jabatan', 'DESC')->limit(1)->get()->getRowArray();

        if ($periode === null) {
            return []; // [K] id periode '' → tidak ada baris yang cocok
        }

        $rows = $this->db->table('struktur_jabatan sj')
            ->select('sj.id_struktur_jabatan, sj.id_jabatan, sj.id_jabatan_atasan, sj.level_struktur, jab.jabatan AS jabatan_upd, jabat.jabatan AS jabatan_atasan_upd')
            ->join('jabatan jab', 'jab.id_jabatan = sj.id_jabatan', 'left')
            ->join('jabatan jabat', 'jabat.id_jabatan = sj.id_jabatan_atasan', 'left')
            ->where('sj.id_periode_struktur_jabatan', $periode['id_periode_struktur_jabatan'])
            ->where('sj.id_struktur_jabatan !=', 1)
            ->where('sj.status !=', 10)
            ->orderBy('sj.level_struktur', 'ASC')->orderBy('sj.urutan', 'ASC')
            ->orderBy('sj.id_struktur_jabatan', 'ASC') // [V2] kunci sekunder
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id_struktur_jabatan' => Nilai::mentah($r['id_struktur_jabatan']),
            'id_jabatan'          => Nilai::mentah($r['id_jabatan']),
            'jabatan'             => Nilai::teks($r['jabatan_upd']),
            'id_jabatan_atasan'   => Nilai::mentah($r['id_jabatan_atasan']),
            'jabatan_atasan'      => Nilai::teks($r['jabatan_atasan_upd']),
            'level_struktur'      => Nilai::mentah($r['level_struktur']),
        ], $rows);
    }
}
