<?php

declare(strict_types=1);

namespace App\Libraries\Layanan;

use App\Libraries\Kepegawaian\Struktur\StrukturService;
use CodeIgniter\Database\BaseConnection;

/**
 * Penanda tangan & tujuan laporan surat TB/IB (PRD Fase 4 §6.7.6, §6.8.1; Q-06(a)): pemegang jabatan
 * `WebConfigService::value('id_jabatan_kabiro_kepegawaian')` dengan `tmtsk` terbaru, dan nama unit
 * `id_unit_kepegawaian` [K `Tb.php:326-473`, templat `print/surat_tb`].
 */
final class PenandaTanganKabiro
{
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * @return array{nip: string, nama: string, jabatan: string}|null
     */
    public function kabiro(): ?array
    {
        $idJabatan = service('webConfigService')->value('id_jabatan_kabiro_kepegawaian');

        if ($idJabatan === null || $idJabatan === '') {
            return null;
        }

        $row = $this->db()->table('pegawai_mutasi_jabatan pmj')
            ->select('p.nip, p.glr_awal, p.nama, p.glr_akhir, pmj.jabatan')
            ->join('pegawai p', 'p.nip = pmj.nip')
            ->where('pmj.id_jabatan', (int) $idJabatan)->where('p.status', 1)
            ->orderBy('pmj.tmtsk', 'DESC')->limit(1)->get()->getRowArray();

        if (! is_array($row)) {
            return null;
        }

        return [
            'nip'     => (string) $row['nip'],
            'nama'    => StrukturService::namaGelar($row['glr_awal'], (string) $row['nama'], $row['glr_akhir']),
            'jabatan' => (string) $row['jabatan'],
        ];
    }

    public function unitKepegawaian(): ?string
    {
        $idUnit = service('webConfigService')->value('id_unit_kepegawaian');

        if ($idUnit === null || $idUnit === '') {
            return null;
        }

        $row = $this->db()->table('unit')->select('unit')->where('id_unit', (int) $idUnit)->get()->getRowArray();

        return is_array($row) ? (string) $row['unit'] : null;
    }

    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
