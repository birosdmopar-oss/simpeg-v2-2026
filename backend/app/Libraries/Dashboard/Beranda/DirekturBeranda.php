<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Beranda;

use App\Libraries\Integrasi\Stat\PenyajiStat;
use CodeIgniter\Database\BaseConnection;
use Config\Dashboard;
use DateTimeImmutable;

/**
 * Tabel "Masa Kerja Direktur Politeknik" beranda role 1/4 (`views/user/dashboard_ad.php:200-232`; data
 * `L_user::dashboard_sa` "MKER DIREKTUR") [K]:
 * - pegawai `status = 1` dengan `pegawai_mutasi_jabatan.id_jabatan` ∈ `Config\Dashboard::$idJabatanDirektur` (Q7-38);
 * - urut `pmj.tmtsk` DESC, satu baris per `id_jabatan` (yang pertama = TMT terbaru);
 * - masa kerja = selisih TMT jabatan ke hari ini: "N Tahun M Bulan" (bagian 0 dihilangkan; keduanya 0 / TMT kosong → '').
 * Kolom tampilan: Nama (bergelar), Politeknik (`pmj.satker`, `stripslashes`), Masa Kerja.
 */
final class DirekturBeranda
{
    public function __construct(private readonly BaseConnection $db, private readonly Dashboard $config, private readonly string $hariIni)
    {
    }

    /**
     * @return list<array{nama: string, politeknik: string, masa_kerja: string}>
     */
    public function daftar(): array
    {
        $id = array_values(array_map('intval', $this->config->idJabatanDirektur));

        if ($id === []) {
            return [];
        }

        $rows = $this->db->table('pegawai peg')
            ->select('peg.glr_awal, peg.nama, peg.glr_akhir, pmj.satker, pmj.id_jabatan, pmj.tmtsk')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->where('peg.status', 1)
            ->whereIn('pmj.id_jabatan', $id)
            ->orderBy('pmj.tmtsk', 'DESC')
            ->get()->getResultArray();

        $perJabatan = [];

        foreach ($rows as $r) {
            $perJabatan[(int) $r['id_jabatan']] ??= $r;
        }

        $hasil = [];

        foreach ($perJabatan as $r) {
            $hasil[] = [
                'nama'       => PenyajiStat::namaGelar($r['glr_awal'], $r['nama'], $r['glr_akhir']),
                'politeknik' => stripslashes((string) ($r['satker'] ?? '')),
                'masa_kerja' => $this->masaKerja($r['tmtsk'] ?? null),
            ];
        }

        return $hasil;
    }

    private function masaKerja(?string $tmt): string
    {
        if ($tmt === null || preg_match('/^\d{4}-\d{2}-\d{2}/', $tmt) !== 1) {
            return '';
        }

        $selisih = (new DateTimeImmutable(substr($tmt, 0, 10)))->diff(new DateTimeImmutable($this->hariIni));
        $bagian  = [];

        if ($selisih->y > 0) {
            $bagian[] = $selisih->y . ' Tahun';
        }

        if ($selisih->m > 0) {
            $bagian[] = $selisih->m . ' Bulan';
        }

        return implode(' ', $bagian);
    }
}
