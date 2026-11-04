<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Siasn\SiasnAdapter;
use CodeIgniter\Database\BaseConnection;

/**
 * `siasn:tarik --jenis=ak` — padanan `SS/Rw_ak::sync_siasn` [K `Rw_ak.php:11-243`] ke `riwayat_ak_siasn`, PNS aktif:
 *
 * - baris lokal dipasangkan lewat `id_rw_siasn` — salah variabel legacy `:65` (`$row` alih-alih `$rowAK`, sehingga
 *   pembandingan memakai baris yang salah) DIPERBAIKI (pengaman F6-14);
 * - ada + angka kredit berubah / belum berpasangan → UPDATE periode & kredit [K `:110-124`]; belum ada → INSERT dengan
 *   `id_jabatan` = jabatan fungsional (`riwayat_mutasi_jabatan` jenis 1, kelompok 2) terakhir yang TMT-nya ≤ tanggal SK
 *   (daftar jabatan kosong dijaga, legacy memberi peringatan) dan `f_pak_siasn` = `dok_uri` berkas pertama [K `:128-161`];
 * - TANPA hapus: penghapusan legacy dikomentari [K `:191-199`].
 * Nama kunci `*Penailan` ikut respons BKN apa adanya [K].
 */
final class TarikAk implements JenisTarikSiasn
{
    private const TABEL = 'riwayat_ak_siasn';

    public function nama(): string
    {
        return 'ak';
    }

    public function kolomStatus(): string
    {
        return 'rw_ak';
    }

    public function jenisPegawai(): array
    {
        return [1];
    }

    public function ambil(SiasnGatewayInterface $siasn, string $nip): array
    {
        return $siasn->getRiwayat($nip, 'ak');
    }

    public function rencana(BaseConnection $db, array $hasil, int $idAkun, string $sekarang): RencanaTarik
    {
        $rencana = new RencanaTarik();

        if ($hasil === []) {
            return $rencana;
        }

        $nips    = array_map('strval', array_keys($hasil));
        $jabatan = [];

        foreach ($db->table('riwayat_mutasi_jabatan')->select('nip, id_jabatan, tmtsk')->where(['status' => 1, 'jenis_jabatan' => 1, 'id_group_jabatan' => 2])
            ->whereIn('nip', $nips)->orderBy('nip', 'ASC')->orderBy('tmtsk', 'ASC')->get()->getResultArray() as $baris) {
            $jabatan[$baris['nip']][(string) $baris['tmtsk']] = $baris['id_jabatan'];
        }

        $ada = array_column($db->table(self::TABEL)->where('status', 1)->whereIn('nip', $nips)->orderBy('nip', 'ASC')->orderBy('tgl_sk', 'DESC')
            ->get()->getResultArray(), null, 'id_rw_siasn');

        foreach ($hasil as $nip => $h) {
            $nip = (string) $nip;

            if ($h['status_code'] !== SiasnAdapter::SUKSES || ! is_array($h['data'])) {
                continue;
            }

            foreach ($h['data'] as $si) {
                if (! is_array($si)) {
                    continue;
                }

                try {
                    $idSiasn = NilaiSiasn::teks($si, 'id', 100) ?? throw new NilaiTidakSah('id', 'tipe');
                    $periode = $this->periode($si);

                    if (isset($ada[$idSiasn])) {
                        $lokal = $ada[$idSiasn];
                        unset($ada[$idSiasn]);

                        if (NilaiSiasn::berbeda($lokal['kredit_utama_baru'], $periode['kredit_utama_baru'])
                            || NilaiSiasn::berbeda($lokal['kredit_penunjang_baru'], $periode['kredit_penunjang_baru'])
                            || NilaiSiasn::berbeda($lokal['kredit_baru_total'], $periode['kredit_baru_total'])) {
                            $rencana->ubah(self::TABEL, ['id_riwayat_ak_siasn' => (int) $lokal['id_riwayat_ak_siasn']], $periode + ['id_rw_siasn' => $idSiasn, 'updated_by' => $idAkun, 'updated_at' => $sekarang]);
                        }

                        continue;
                    }

                    $tglSk = NilaiSiasn::tanggal($si, 'tanggalSk');

                    $rencana->sisip(self::TABEL, [
                        'id_rw_siasn'             => $idSiasn,
                        'id_pns'                  => NilaiSiasn::teks($si, 'pns', 100),
                        'id_jabatan'              => $tglSk === null ? null : $this->jabatanPada($jabatan[$nip] ?? [], $tglSk),
                        'id_pangkat'              => null,
                        'nip'                     => $nip,
                        'no_sk'                   => NilaiSiasn::teks($si, 'nomorSk', 100),
                        'tgl_sk'                  => $tglSk,
                        'id_rw_jabatan_siasn'     => NilaiSiasn::teks($si, 'rwJabatan', 100),
                        'nama_jabatan'            => NilaiSiasn::teks($si, 'namaJabatan', 255),
                        'is_angka_kredit_pertama' => NilaiSiasn::teks($si, 'isAngkaKreditPertama', 10),
                        'is_integrasi'            => NilaiSiasn::teks($si, 'isIntegrasi', 10),
                        'is_konversi'             => NilaiSiasn::teks($si, 'isKonversi', 10),
                        'f_pak_siasn'             => $this->dokPak($si),
                        'sumber'                  => NilaiSiasn::teks($si, 'Sumber', 255),
                        'is_pemenuhan_kp'         => NilaiSiasn::teks($si, 'isPemenuhanKp', 10),
                        'status'                  => 1,
                        'updated_by'              => $idAkun,
                        'created_at'              => $sekarang,
                        'updated_at'              => $sekarang,
                    ] + $periode);
                } catch (NilaiTidakSah $e) {
                    $rencana->tolak($e->alasan);
                }
            }
        }

        return $rencana;
    }

    /**
     * @param array<string, mixed> $si
     *
     * @return array<string, int|string|null>
     */
    private function periode(array $si): array
    {
        return [
            // Bulan 0 = tidak diisi [K `> 0 ? … : NULL`].
            'bulan_mulai_penilaian'   => NilaiSiasn::bulat($si, 'bulanMulaiPenailan', 0, 12) ?: null,
            'tahun_mulai_penilaian'   => NilaiSiasn::bulat($si, 'tahunMulaiPenailan', 1901, 2155),
            'bulan_selesai_penilaian' => NilaiSiasn::bulat($si, 'bulanSelesaiPenailan', 0, 12) ?: null,
            'tahun_selesai_penilaian' => NilaiSiasn::bulat($si, 'tahunSelesaiPenailan', 1901, 2155),
            'kredit_utama_baru'       => NilaiSiasn::desimal($si, 'kreditUtamaBaru', 7),
            'kredit_penunjang_baru'   => NilaiSiasn::desimal($si, 'kreditPenunjangBaru', 7),
            'kredit_baru_total'       => NilaiSiasn::desimal($si, 'kreditBaruTotal', 7),
        ];
    }

    /**
     * Jabatan terakhir dengan TMT ≤ tanggal SK [K `:90-99`].
     *
     * @param array<string, mixed> $perTmt TMT (urut naik) → id_jabatan
     */
    private function jabatanPada(array $perTmt, string $tglSk): ?int
    {
        $id = null;

        foreach ($perTmt as $tmt => $idJabatan) {
            if ((string) $tmt > $tglSk) {
                break;
            }

            $id = $idJabatan === null ? null : (int) $idJabatan;
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $si
     */
    private function dokPak(array $si): ?string
    {
        $path = $si['path'] ?? null;

        if (! is_array($path) || $path === []) {
            return null;
        }

        $pertama = $path[array_key_first($path)];

        return is_array($pertama) ? NilaiSiasn::teks($pertama, 'dok_uri', 255) : null;
    }
}
