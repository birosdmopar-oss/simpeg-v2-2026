<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Siasn\SiasnAdapter;
use CodeIgniter\Database\BaseConnection;

/**
 * `siasn:tarik --jenis=skp` — padanan `SS/Rw_skp::get_siasn` [K `Rw_skp.php:10-264`] (`pns/rw-skp22`; duplikat
 * `SI::sync_rw_skp23` tidak dibawa) ke `riwayat_skp` tahun ≥ 2022, semua pegawai aktif:
 *
 * - baris lokal dipasangkan lewat `id_rw_siasn`, lalu lewat `tahun` (baris tanpa pasangan);
 * - rating SIASN ↔ lokal 1↔3, 2↔2, 3↔1 [K `:83-87`]; kuadran → huruf awal kapital;
 * - ada + rating/kuadran berubah atau belum berpasangan → UPDATE penilai, rating, kuadran, `id_rw_siasn` [K `:121-139`];
 *   belum ada → INSERT `siasn_flag = 4` ("unduh dari SIASN"), `status = 1` [K `:145-173`];
 * - HAPUS FISIK baris berpasangan yang tidak lagi dikembalikan BKN [K `:184-220`] — HANYA untuk NIP yang panggilan API-nya
 *   SUKSES dengan data tidak kosong (pengaman wajib F6-14 / AC-03.3; legacy ikut menghapus saat API gagal, `:219`).
 */
final class TarikSkp implements JenisTarikSiasn
{
    private const TABEL = 'riwayat_skp';

    private const TAHUN_MIN = 2022;

    /** Rating SIASN → lokal [K]. */
    private const RATING = [1 => 3, 2 => 2, 3 => 1];

    public function nama(): string
    {
        return 'skp';
    }

    public function kolomStatus(): string
    {
        return 'rw_skp22';
    }

    public function jenisPegawai(): ?array
    {
        return null;
    }

    public function ambil(SiasnGatewayInterface $siasn, string $nip): array
    {
        return $siasn->getRiwayat($nip, 'skp22');
    }

    public function rencana(BaseConnection $db, array $hasil, int $idAkun, string $sekarang): RencanaTarik
    {
        $rencana = new RencanaTarik();

        if ($hasil === []) {
            return $rencana;
        }

        $golongan    = array_column($db->table('siasn_referensi')->select('id_siasn, nama')->where('jenis', 'golongan')->get()->getResultArray(), 'nama', 'id_siasn');
        $berpasangan = $perTahun = [];

        foreach ($db->table(self::TABEL)->where('status', 1)->where('tahun >=', self::TAHUN_MIN)->whereIn('nip', array_map('strval', array_keys($hasil)))
            ->orderBy('nip', 'ASC')->orderBy('tahun', 'ASC')->get()->getResultArray() as $baris) {
            if (trim((string) $baris['id_rw_siasn']) !== '') {
                $berpasangan[$baris['nip']][(string) $baris['id_rw_siasn']] = $baris;
            } elseif ((string) $baris['tahun'] !== '') {
                $perTahun[$baris['nip']][(int) $baris['tahun']] = $baris;
            }
        }

        foreach ($hasil as $nip => $h) {
            $nip = (string) $nip;

            if ($h['status_code'] !== SiasnAdapter::SUKSES || ! is_array($h['data']) || $h['data'] === []) {
                // AC-03.3: tanpa jawaban sah (gagal / kosong) tidak ada hapus fisik untuk NIP ini.
                unset($berpasangan[$nip]);

                continue;
            }

            foreach ($h['data'] as $si) {
                if (! is_array($si)) {
                    continue;
                }

                try {
                    $idSiasn = NilaiSiasn::teks($si, 'id', 100) ?? throw new NilaiTidakSah('id', 'tipe');
                    $tahun   = NilaiSiasn::bulat($si, 'tahun', 1901, 2155) ?? throw new NilaiTidakSah('tahun', 'tipe');
                    $nilai   = $this->nilai($si, $golongan);
                    $lokal   = null;

                    if (isset($berpasangan[$nip][$idSiasn])) {
                        $lokal = $berpasangan[$nip][$idSiasn];
                        unset($berpasangan[$nip][$idSiasn]);
                    } elseif (isset($perTahun[$nip][$tahun])) {
                        $lokal = $perTahun[$nip][$tahun];
                    }

                    $data = [
                        'tahun'     => $tahun,
                        'tgl_mulai' => "{$tahun}-01-02",
                        'tgl_akhir' => "{$tahun}-12-30",
                    ] + $nilai + ['id_rw_siasn' => $idSiasn];

                    if ($lokal !== null) {
                        if (NilaiSiasn::berbeda($lokal['rating_skp'], $nilai['rating_skp']) || NilaiSiasn::berbeda($lokal['rating_perilaku'], $nilai['rating_perilaku'])
                            || NilaiSiasn::berbeda($lokal['kategori_nilai_prestasi'], $nilai['kategori_nilai_prestasi']) || trim((string) $lokal['id_rw_siasn']) === '') {
                            $rencana->ubah(self::TABEL, ['id_riwayat_skp' => (int) $lokal['id_riwayat_skp']], $data + ['updated_by' => $idAkun, 'updated_at' => $sekarang], trim((string) $lokal['id_rw_siasn']) === '' ? 'pasang' : 'ubah');
                        }

                        continue;
                    }

                    $rencana->sisip(self::TABEL, ['nip' => $nip] + $data + [
                        'nip_atasan_penilai'       => null,
                        'nama_atasan_penilai'      => null,
                        'jabatan_atasan_penilai'   => null,
                        'nilai_skp'                => null,
                        'nilai_skp_60_persen'      => null,
                        'nilai_perilaku'           => null,
                        'nilai_perilaku_40_persen' => null,
                        'nilai_prestasi_kerja'     => null,
                        'keterangan'               => 'Sync SIASN',
                        'status'                   => 1,
                        'siasn_flag'               => 4,
                        'updated_by'               => $idAkun,
                        'approved_by'              => $idAkun,
                        'created_at'               => $sekarang,
                        'updated_at'               => $sekarang,
                    ]);
                } catch (NilaiTidakSah $e) {
                    $rencana->tolak($e->alasan);
                }
            }
        }

        foreach ($berpasangan as $perNip) {
            foreach ($perNip as $ada) {
                $rencana->hapus(self::TABEL, ['id_riwayat_skp' => (int) $ada['id_riwayat_skp']]);
            }
        }

        return $rencana;
    }

    /**
     * @param array<string, mixed> $si
     * @param array<string, mixed> $golongan
     *
     * @return array<string, int|string|null>
     */
    private function nilai(array $si, array $golongan): array
    {
        // Legacy `intval()`: nilai bukan angka → di luar pemetaan → NULL (bukan penolakan baris).
        $kinerja  = is_numeric($si['hasilKinerjaNilai'] ?? null) ? (int) $si['hasilKinerjaNilai'] : null;
        $perilaku = is_numeric($si['PerilakuKerjaNilai'] ?? null) ? (int) $si['PerilakuKerjaNilai'] : null;
        $kuadran  = NilaiSiasn::teks($si, 'kuadranKinerja', 50);
        $gol      = NilaiSiasn::teks($si, 'penilaiGolonganId', 100);

        return [
            'nip_penilai'             => NilaiSiasn::teks($si, 'nipNrpPenilai', 18),
            'nama_penilai'            => NilaiSiasn::teks($si, 'namaPenilai', 150),
            'jabatan_penilai'         => NilaiSiasn::teks($si, 'penilaiJabatanNm', 150),
            'rating_skp'              => self::RATING[$kinerja] ?? null,
            'rating_perilaku'         => self::RATING[$perilaku] ?? null,
            'kategori_nilai_prestasi' => $kuadran === null ? '' : ucwords(strtolower($kuadran)),
            'gol_ruang_penilai'       => $gol !== null && isset($golongan[$gol]) ? mb_substr((string) $golongan[$gol], 0, 50) : null,
            'unor_penilai'            => NilaiSiasn::teks($si, 'penilaiUnorNm', 255),
            'status_penilai'          => NilaiSiasn::teks($si, 'statusPenilai', 50),
        ];
    }
}
