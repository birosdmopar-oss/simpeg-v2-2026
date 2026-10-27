<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Dokumen;

use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Dashboard\Statistik\LingkupStatistik;
use App\Libraries\ListQuery;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Builder daftar pegawai untuk dokumen F-09 (PRD Fase 7 §7.2.2) — satu kueri per dokumen atas `pegawai` + snapshot
 * aktif `pegawai_*` + master (padanan `L_employee::get_all_pegawai()` `:4432-5069` tanpa riwayat yang tidak dipakai).
 *
 * Cakupan (F7-19): DUK = PNS aktif (jenis 1, termasuk CPNS); Nominatif = semua aktif; Pensiun = PNS aktif (rentang
 * tahun disaring di PHP, `ProyeksiPensiun`); Baperjakat = aktif. Lingkup unit/satker atas `pmj.id_unit`/`pmj.id_satker`;
 * `PegawaiScope::terapkanKeQuery` selalu dipasang (F7-22). Alias: peg, pkp, pang, pmj, unt, sat, jab, pcp, ppn, pdk,
 * ppen, pkg, ptj.
 */
final class KueriDaftarPegawai
{
    private const SELECT = 'peg.nip, peg.nip_lama, peg.nama, peg.glr_awal, peg.glr_akhir, peg.tgl_lahir, peg.jenis_kelamin, peg.agama, '
        . 'peg.kabupaten_kota_lahir, peg.kabupaten_kota_lahir_lain, peg.id_jenis_pegawai, peg.status, '
        . 'pkp.id_pangkat, pkp.gol, pkp.ruang, pkp.gol_ruang, pkp.pangkat, pkp.tmtsk AS tmtsk_pangkat, pang.`order` AS pangkat_order, '
        . 'pmj.id_unit, pmj.unit, pmj.id_satker, pmj.satker, pmj.id_jabatan, pmj.jabatan, pmj.tmtsk AS tmtsk_jabatan, '
        . 'pmj.id_group_jabatan, pmj.id_sub_group_jabatan, jab.kelas_jabatan, jab.umur_pensiun, '
        . 'pcp.tmtsk AS tmtsk_cpns, ppn.tmtsk AS tmtsk_pns, pdk.nama_diklat, '
        . 'ppen.jenjang_pendidikan_singkat, ppen.jurusan_pendidikan, ppen.jurusan_pendidikan_lain, ppen.institusi_pendidikan, ppen.tgl_lulus, ppen.ipk, '
        . 'pkg.tmtsk AS tmtsk_kgb, ptj.tgl_sertifikat, ptj.tanda_jasa';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly PegawaiScopeInterface $scope,
    ) {
    }

    /**
     * Builder (belum diurutkan) untuk $jenis pada $lingkup; `$search` = nama/NIP LIKE (teks biasa).
     */
    public function builder(AuthContext $auth, string $jenis, LingkupStatistik $lingkup, string $search = ''): BaseBuilder
    {
        $b = $this->db->table('pegawai peg')
            ->select(self::SELECT, false)
            ->join('pegawai_kp pkp', 'pkp.nip = peg.nip', 'left')
            ->join('pangkat pang', 'pang.id_pangkat = pkp.id_pangkat', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->join('unit unt', 'unt.id_unit = pmj.id_unit', 'left')
            ->join('satker sat', 'sat.id_satker = pmj.id_satker', 'left')
            ->join('jabatan jab', 'jab.id_jabatan = pmj.id_jabatan', 'left')
            ->join('pegawai_cpns pcp', 'pcp.nip = peg.nip', 'left')
            ->join('pegawai_pns ppn', 'ppn.nip = peg.nip', 'left')
            ->join('pegawai_diklat pdk', 'pdk.nip = peg.nip', 'left')
            ->join('pegawai_pendidikan ppen', 'ppen.nip = peg.nip', 'left')
            ->join('pegawai_kgb pkg', 'pkg.nip = peg.nip', 'left')
            ->join('pegawai_tanda_jasa ptj', 'ptj.nip = peg.nip', 'left')
            ->where('peg.status', 1);

        if ($jenis === KolomDokumen::DUK || $jenis === KolomDokumen::PENSIUN) {
            $b->where('peg.id_jenis_pegawai', 1);
        }

        if ($jenis === KolomDokumen::PENSIUN) {
            // Proyeksi hanya untuk pegawai berjabatan aktif (BUP butuh jabatan; README Libraries/Kepegawaian).
            $b->where('pmj.id_jabatan IS NOT NULL', null, false);
        }

        if ($lingkup->tipe === LingkupStatistik::UNIT) {
            $b->where('pmj.id_unit', $lingkup->id);
        } elseif ($lingkup->tipe === LingkupStatistik::SATKER) {
            $b->where('pmj.id_satker', $lingkup->id);
        }

        if ($search !== '') {
            $s = ListQuery::likeLiteral($this->db, $search);
            $b->groupStart()->like('peg.nama', $s)->orLike('peg.nip', $s)->groupEnd();
        }

        $this->scope->terapkanKeQuery($b, $auth, 'peg.nip');

        return $b;
    }

    /**
     * Terapkan urutan per jenis (Pensiun diurutkan di PHP oleh `ProyeksiPensiun`).
     */
    public function urutkan(BaseBuilder $b, string $jenis): BaseBuilder
    {
        return match ($jenis) {
            KolomDokumen::DUK        => UrutanDuk::terapkanDuk($b),
            KolomDokumen::NOMINATIF  => UrutanDuk::terapkanNominatif($b),
            KolomDokumen::BAPERJAKAT => UrutanDuk::terapkanBaperjakat($b),
            default                  => $b->orderBy('peg.nip', 'ASC'),
        };
    }

    /**
     * Baris Baperjakat untuk NIP terpilih (aktif + dalam lingkup akun), urut Baperjakat.
     *
     * @param list<string> $nip
     *
     * @return list<array<string, mixed>>
     */
    public function baperjakat(AuthContext $auth, array $nip): array
    {
        $b = $this->builder($auth, KolomDokumen::BAPERJAKAT, new LingkupStatistik(LingkupStatistik::KEMENTERIAN, null, ''))
            ->whereIn('peg.nip', $nip);

        return $this->urutkan($b, KolomDokumen::BAPERJAKAT)->get()->getResultArray();
    }
}
