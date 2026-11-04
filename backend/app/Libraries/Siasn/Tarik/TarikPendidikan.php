<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Siasn\SiasnAdapter;
use CodeIgniter\Database\BaseConnection;

/**
 * `siasn:tarik --jenis=pendidikan` — padanan `SS/Rw_pendidikan::get_siasn` [K `Rw_pendidikan.php:10-273`], pegawai aktif
 * `id_jenis_pegawai` 1 (PNS) dan 6 (PPPK):
 *
 * - kunci baris = `<id_jenjang_pendidikan>-<tahun lulus>`; jenjang SIASN (`tkPendidikanId`) → lokal lewat `siasn_referensi`
 *   jenis `tk_pendidikan` (padanan `siasn_simpeg.TK_PENDIDIKAN`);
 * - baris SIASN tanpa `tglLulus` dan `tahunLulus` dilewati; `tglLulus` kosong → `<tahunLulus>-01-01` [K `:112-124`];
 * - berpasangan (`id_rw_siasn`) → tidak diubah; kunci cocok dengan baris lokal tanpa pasangan → pasang `id_rw_siasn`;
 *   kunci belum ada sama sekali → INSERT (`keterangan` "Sync SIASN", `status = 1`, `approved_by` akun sistem; jenjang di
 *   luar 1–3 → bidang 98 / jurusan 1185 "LAIN-LAIN" + nama pendidikan SIASN [K `:147-170`]);
 * - pasangan lokal yang tidak lagi dikembalikan → `id_rw_siasn = NULL`, hanya untuk NIP yang API-nya sukses dengan data
 *   tidak kosong (pengaman F6-14).
 */
final class TarikPendidikan implements JenisTarikSiasn
{
    private const TABEL = 'riwayat_pendidikan';

    private const JENJANG_TANPA_JURUSAN = [1, 2, 3];

    public function nama(): string
    {
        return 'pendidikan';
    }

    public function kolomStatus(): string
    {
        return 'rw_didik';
    }

    public function jenisPegawai(): array
    {
        return [1, 6];
    }

    public function ambil(SiasnGatewayInterface $siasn, string $nip): array
    {
        return $siasn->getRiwayat($nip, 'pendidikan');
    }

    public function rencana(BaseConnection $db, array $hasil, int $idAkun, string $sekarang): RencanaTarik
    {
        $rencana = new RencanaTarik();

        if ($hasil === []) {
            return $rencana;
        }

        $tkPendidikan = array_column($db->table('siasn_referensi')->select('id_siasn, id_lokal')->where('jenis', 'tk_pendidikan')->get()->getResultArray(), 'id_lokal', 'id_siasn');
        $singkat      = array_column($db->table('jenjang_pendidikan')->select('id_jenjang_pendidikan, jenjang_pendidikan_singkat')->get()->getResultArray(), 'jenjang_pendidikan_singkat', 'id_jenjang_pendidikan');

        $semua = $tanpaPasangan = $berpasangan = [];

        foreach ($db->table(self::TABEL)->where('status', 1)->whereIn('nip', array_map('strval', array_keys($hasil)))
            ->orderBy('nip', 'ASC')->orderBy('tgl_lulus', 'ASC')->get()->getResultArray() as $baris) {
            $kunci                        = $baris['id_jenjang_pendidikan'] . '-' . substr((string) $baris['tgl_lulus'], 0, 4);
            $semua[$baris['nip']][$kunci] = true;

            if (trim((string) $baris['id_rw_siasn']) !== '') {
                $berpasangan[$baris['nip']][(string) $baris['id_rw_siasn']] = $baris;
            } elseif ((string) $baris['id_jenjang_pendidikan'] !== '' && (string) $baris['tgl_lulus'] !== '') {
                $tanpaPasangan[$baris['nip']][$kunci] = $baris;
            }
        }

        foreach ($hasil as $nip => $h) {
            $nip = (string) $nip;

            if ($h['status_code'] !== SiasnAdapter::SUKSES || ! is_array($h['data']) || $h['data'] === []) {
                unset($berpasangan[$nip]);

                continue;
            }

            foreach ($h['data'] as $si) {
                if (! is_array($si)) {
                    continue;
                }

                try {
                    $idSiasn = NilaiSiasn::teks($si, 'id', 100) ?? throw new NilaiTidakSah('id', 'tipe');

                    if (isset($berpasangan[$nip][$idSiasn])) {
                        unset($berpasangan[$nip][$idSiasn]);

                        continue;
                    }

                    $tglLulus = NilaiSiasn::tanggal($si, 'tglLulus');
                    $tahun    = NilaiSiasn::bulat($si, 'tahunLulus', 1901, 2155);

                    if ($tglLulus === null && $tahun === null) {
                        continue;
                    }

                    $tglLulus ??= sprintf('%04d-01-01', $tahun);
                    $tk = NilaiSiasn::teks($si, 'tkPendidikanId', 100);
                    // Pemetaan ke jenjang yang tidak ada di master (FK) = tidak terpetakan (NULL), seperti legacy.
                    $jenjang = $tk !== null && isset($tkPendidikan[$tk]) && array_key_exists((int) $tkPendidikan[$tk], $singkat) ? (int) $tkPendidikan[$tk] : null;

                    if ($tk !== null && $jenjang === null) {
                        // Putusan reviewer B6 (c): jenjang NULL seperti legacy + INFO referensi_tak_terpetakan.
                        $rencana->info['referensi_tak_terpetakan']++;
                    }
                    $kunci = $jenjang . '-' . substr($tglLulus, 0, 4);

                    if (isset($tanpaPasangan[$nip][$kunci])) {
                        $ada = $tanpaPasangan[$nip][$kunci];
                        unset($tanpaPasangan[$nip][$kunci]);
                        $rencana->ubah(self::TABEL, ['id_riwayat_pendidikan' => (int) $ada['id_riwayat_pendidikan']], ['id_rw_siasn' => $idSiasn, 'updated_by' => $idAkun, 'updated_at' => $sekarang], 'pasang');

                        continue;
                    }

                    if (isset($semua[$nip][$kunci])) {
                        continue;
                    }

                    $rencana->sisip(self::TABEL, $this->dataSisip($nip, $si, $idSiasn, $jenjang, $tglLulus, $singkat, $idAkun, $sekarang));
                    $semua[$nip][$kunci] = true;
                } catch (NilaiTidakSah $e) {
                    $rencana->tolak($e->alasan);
                }
            }
        }

        foreach ($berpasangan as $perNip) {
            foreach ($perNip as $ada) {
                $rencana->ubah(self::TABEL, ['id_riwayat_pendidikan' => (int) $ada['id_riwayat_pendidikan']], ['id_rw_siasn' => null], 'lepas');
            }
        }

        return $rencana;
    }

    /**
     * @param array<string, mixed>     $si
     * @param array<int|string, mixed> $singkat
     *
     * @return array<string, mixed>
     */
    private function dataSisip(string $nip, array $si, string $idSiasn, ?int $jenjang, string $tglLulus, array $singkat, int $idAkun, string $sekarang): array
    {
        $tanpaJurusan = in_array($jenjang, self::JENJANG_TANPA_JURUSAN, true);
        $namaDidik    = $tanpaJurusan ? null : NilaiSiasn::teks($si, 'pendidikanNama', 100);
        $namaSingkat  = $jenjang !== null && isset($singkat[$jenjang]) && (string) $singkat[$jenjang] !== '' ? (string) $singkat[$jenjang] : null;

        return [
            'nip'                        => $nip,
            'id_jenjang_pendidikan'      => $jenjang,
            'id_bidang_pendidikan'       => $tanpaJurusan ? null : 98,
            'id_jurusan_pendidikan'      => $tanpaJurusan ? null : 1185,
            'tgl_lulus'                  => $tglLulus,
            'no_ijazah'                  => NilaiSiasn::teks($si, 'nomorIjasah', 100),
            'no_sk_penc_glr'             => null,
            'institusi_pendidikan'       => NilaiSiasn::teks($si, 'namaSekolah', 255) ?? '-',
            'jenjang_pendidikan_singkat' => $namaSingkat === null ? null : mb_substr($namaSingkat, 0, 15),
            'bidang_pendidikan'          => $tanpaJurusan ? null : 'LAIN-LAIN',
            'bidang_pendidikan_lain'     => $namaDidik,
            'jurusan_pendidikan'         => $tanpaJurusan ? null : 'LAIN-LAIN',
            'jurusan_pendidikan_lain'    => $namaDidik,
            'nem'                        => null,
            'ipk'                        => null,
            'keterangan'                 => 'Sync SIASN',
            'glr_awal'                   => NilaiSiasn::teks($si, 'gelarDepan', 50),
            'glr_akhir'                  => NilaiSiasn::teks($si, 'gelarBelakang', 50),
            'id_rw_siasn'                => $idSiasn,
            'status'                     => 1,
            'updated_by'                 => $idAkun,
            'approved_by'                => $idAkun,
            'created_at'                 => $sekarang,
            'updated_at'                 => $sekarang,
        ];
    }
}
