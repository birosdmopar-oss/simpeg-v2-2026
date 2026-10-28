<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\MasterData\WebConfigService;
use App\Libraries\Presensi\Rekap\PenandaTangan;
use CodeIgniter\Database\BaseConnection;
use Config\Presensi;

/**
 * Pemuat data penanda tangan rekap Tukin (PRD 5B §7.3.4, F5B-13) — port `L_presensi.php:12018-12140` legacy `0cf7e67`;
 * keputusan kasus & teks di {@see PenandaTangan} (murni).
 *
 * - Lingkup kementerian & IL → menteri `web_config` `jabatan_menteri_full` / `nama_menteri` [K `:12074-12082`].
 * - Satker S (unit U): penyaring jabatan = satker `web_config` `id_satker_kepegawaian` bila U = `id_unit_kepegawaian` atau
 *   `Config\Presensi::$unitTtdSatkerKepegawaian`; satker S bila U = `$unitTtdSatkerTerpilih`; selain itu unit U
 *   [K `:12019`]. Unit U: satker kepegawaian bila U ∈ {`id_unit_kepegawaian`, `$unitTtdSatkerTerpilih`,
 *   `$unitTtdSatkerKepegawaian`}, selain itu unit U [K `:12107`]. Level `struktur_jabatan` 2 bila U = `id_unit_inspektorat`,
 *   selain itu 3 [K `:12020`].
 * - Jabatan = `struktur_jabatan` periode aktif [V2, pola `StrukturService`] ⋈ `jabatan` status 1 ⋈ `satker`, urut `urutan`,
 *   id [V2 deterministik; legacy `row_array` tanpa urutan].
 * - Definitif = `pegawai_mutasi_jabatan` jabatan itu, pegawai `status = 1`, `tmtsk` terbaru (seri → NIP naik [V2])
 *   [K `:12029-12039`]; tidak ada → Plt `pegawai_plt` status 1 berlaku pada tanggal cetak (F2, {@see PenandaTangan::pilihPlt()}).
 */
final class PejabatPenandaTangan
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly WebConfigService $webConfig,
        private readonly Presensi $config,
    ) {
    }

    /**
     * @return array{kasus: string, jabatan: string, nama: string, nip: string}
     */
    public function untuk(LingkupLaporan $lingkup, string $tanggalCetak): array
    {
        $wc = $this->webConfig->values();

        if ($lingkup->tipe === LingkupLaporan::KEMENTERIAN) {
            return PenandaTangan::menteri(self::teks($wc['jabatan_menteri_full'] ?? null), self::teks($wc['nama_menteri'] ?? null));
        }

        $unitKepeg   = (int) ($wc['id_unit_kepegawaian'] ?? 0);
        $satkerKepeg = (int) ($wc['id_satker_kepegawaian'] ?? 0);
        $unit        = $lingkup->tipe === LingkupLaporan::UNIT
            ? (int) $lingkup->id
            : (int) ($this->db->table('satker')->select('id_unit')->where('id_satker', $lingkup->id)->get()->getRow('id_unit') ?? 0);

        if ($lingkup->tipe === LingkupLaporan::SATKER) {
            $saring = match (true) {
                $unit === $unitKepeg || $unit === $this->config->unitTtdSatkerKepegawaian => ['satker', $satkerKepeg],
                $unit === $this->config->unitTtdSatkerTerpilih                            => ['satker', (int) $lingkup->id],
                default                                                                   => ['unit', $unit],
            };
        } else {
            $saring = in_array($unit, [$unitKepeg, $this->config->unitTtdSatkerTerpilih, $this->config->unitTtdSatkerKepegawaian], true)
                ? ['satker', $satkerKepeg]
                : ['unit', $unit];
        }

        $level   = $unit !== 0 && $unit === (int) ($wc['id_unit_inspektorat'] ?? -1) ? 2 : 3;
        $jabatan = $this->jabatan($saring, $level);

        if ($jabatan === null) {
            return PenandaTangan::struktural(null, null, null);
        }

        return PenandaTangan::struktural($jabatan['jabatan'], $this->definitif($jabatan['id_jabatan']), $this->plt($jabatan['id_jabatan'], $tanggalCetak));
    }

    /**
     * @param array{0: string, 1: int} $saring
     *
     * @return array{id_jabatan: int, jabatan: string}|null
     */
    private function jabatan(array $saring, int $level): ?array
    {
        $periode = $this->db->table('periode_struktur_jabatan')->select('id_periode_struktur_jabatan')->where('status', 1)
            ->orderBy('id_periode_struktur_jabatan', 'DESC')->get(1)->getRow('id_periode_struktur_jabatan');

        $b = $this->db->table('struktur_jabatan sj')
            ->select('jab.id_jabatan, jab.jabatan')
            ->join('jabatan jab', 'jab.id_jabatan = sj.id_jabatan', 'inner')
            ->join('satker sat', 'sat.id_satker = jab.id_satker', 'inner')
            ->where('sj.level_struktur', $level)
            ->where('jab.status', 1)
            ->where($saring[0] === 'satker' ? 'sat.id_satker' : 'sat.id_unit', $saring[1]);

        $periode === null ? $b->where('sj.id_periode_struktur_jabatan IS NULL', null, false) : $b->where('sj.id_periode_struktur_jabatan', $periode);

        $baris = $b->orderBy('sj.urutan', 'ASC')->orderBy('sj.id_struktur_jabatan', 'ASC')->get(1)->getRowArray();

        return is_array($baris) ? ['id_jabatan' => (int) $baris['id_jabatan'], 'jabatan' => (string) $baris['jabatan']] : null;
    }

    /**
     * @return array{nama: string, nip: string}|null
     */
    private function definitif(int $idJabatan): ?array
    {
        $baris = $this->db->table('pegawai_mutasi_jabatan pmj')
            ->select('peg.nip, peg.nama, peg.glr_awal, peg.glr_akhir')
            ->join('pegawai peg', 'peg.nip = pmj.nip', 'inner')
            ->where('pmj.id_jabatan', $idJabatan)->where('peg.status', 1)
            ->orderBy('pmj.tmtsk', 'DESC')->orderBy('peg.nip', 'ASC')->get(1)->getRowArray();

        return is_array($baris) ? self::orang($baris) : null;
    }

    /**
     * @return array{nama: string, nip: string}|null
     */
    private function plt(int $idJabatan, string $tanggal): ?array
    {
        $kandidat = $this->db->table('pegawai_plt plt')
            ->select('plt.id_pegawai_plt, plt.tgl_mulai, plt.tgl_akhir, peg.nip, peg.nama, peg.glr_awal, peg.glr_akhir')
            ->join('pegawai peg', 'peg.nip = plt.nip_plt', 'inner')
            ->where('plt.id_jabatan', $idJabatan)->where('plt.status', 1)
            ->get()->getResultArray();

        $pilih = PenandaTangan::pilihPlt($kandidat, $tanggal);

        return $pilih === null ? null : self::orang($pilih);
    }

    /**
     * @param array<string, mixed> $b
     *
     * @return array{nama: string, nip: string}
     */
    private static function orang(array $b): array
    {
        return ['nama' => StrukturService::namaGelar($b['glr_awal'] ?? null, (string) ($b['nama'] ?? ''), $b['glr_akhir'] ?? null), 'nip' => (string) $b['nip']];
    }

    private static function teks(mixed $nilai): ?string
    {
        return is_string($nilai) ? $nilai : null;
    }
}
