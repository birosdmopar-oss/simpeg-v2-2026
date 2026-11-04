<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Siasn\SiasnAdapter;
use CodeIgniter\Database\BaseConnection;

/**
 * `siasn:tarik --jenis=golongan` — padanan `SS/Rw_gol::get_siasn` [K `Rw_gol.php:10-457`], PNS (`id_jenis_pegawai` 1) aktif:
 *
 * - baris lokal: `riwayat_kp` `status = 1`, `id_jenis_kp` bukan 2/6; dipasangkan lewat `id_rw_siasn`, lalu lewat
 *   `gol_ruang` (termasuk baris yang sudah berpasangan lain — ikut legacy `listRw`);
 * - per baris SIASN: `golongan` kosong dilewati; `jenisKPId` 211 → `CPNS <golongan>`; golongan yang sama dua kali untuk satu
 *   NIP → NIP dicatat di `double_riwayat_kp` (sekali) dan baris kedua dilewati [K `:131-143`];
 * - berpasangan + ada beda pada kolom yang dibandingkan legacy [K `:236-247`] → UPDATE seluruh kolom; cocok `gol_ruang` →
 *   UPDATE + pasang `id_rw_siasn`; selain itu INSERT (`status = 1`, `approved_by` akun sistem);
 * - jenis KP: `siasn_referensi` jenis `jenis_kp` → `id_jenis_kp` lokal, bawaan 3 "Reguler" [K `:161-171`]; pangkat dari
 *   master `pangkat` aktif per `gol_ruang`;
 * - pasangan lokal yang tidak lagi dikembalikan SIASN → `id_rw_siasn = NULL` (lepas) — HANYA untuk NIP yang panggilan
 *   API-nya sukses dan datanya tidak kosong (pengaman F6-14; legacy ikut melepas saat API gagal, `:352-363`).
 */
final class TarikGolongan implements JenisTarikSiasn
{
    private const TABEL = 'riwayat_kp';

    public function nama(): string
    {
        return 'golongan';
    }

    public function kolomStatus(): string
    {
        return 'rw_gol';
    }

    public function jenisPegawai(): array
    {
        return [1];
    }

    public function ambil(SiasnGatewayInterface $siasn, string $nip): array
    {
        return $siasn->getRiwayat($nip, 'golongan');
    }

    public function rencana(BaseConnection $db, array $hasil, int $idAkun, string $sekarang): RencanaTarik
    {
        $rencana = new RencanaTarik();

        if ($hasil === []) {
            return $rencana;
        }

        $nips      = array_map('strval', array_keys($hasil));
        $pangkat   = array_column($db->table('pangkat')->where('status', 1)->orderBy('order', 'ASC')->get()->getResultArray(), null, 'gol_ruang');
        $jenisKpSi = array_column($db->table('siasn_referensi')->select('id_siasn, id_lokal')->where('jenis', 'jenis_kp')->get()->getResultArray(), 'id_lokal', 'id_siasn');
        // Semua baris master (FK `riwayat_kp.id_jenis_kp`); pemetaan ke id yang tidak ada = tidak terpetakan → bawaan 3.
        $jenisKp = array_column($db->table('jenis_kp')->get()->getResultArray(), 'jenis_kp', 'id_jenis_kp');
        $ganda   = array_column($db->table('double_riwayat_kp')->select('nip')->get()->getResultArray(), 'nip', 'nip');

        $berpasangan = $perGol = [];

        foreach ($db->table(self::TABEL)->where('status', 1)->whereNotIn('id_jenis_kp', [2, 6])->whereIn('nip', $nips)
            ->orderBy('nip', 'ASC')->orderBy('tmtsk', 'ASC')->get()->getResultArray() as $baris) {
            if (trim((string) $baris['id_rw_siasn']) !== '') {
                $berpasangan[$baris['nip']][(string) $baris['id_rw_siasn']] = $baris;
            }

            if (trim((string) $baris['gol_ruang']) !== '') {
                $perGol[$baris['nip']][(string) $baris['gol_ruang']] = $baris;
            }
        }

        foreach ($hasil as $nip => $h) {
            $nip = (string) $nip;

            if ($h['status_code'] !== SiasnAdapter::SUKSES || ! is_array($h['data']) || $h['data'] === []) {
                // Pengaman F6-14: tanpa jawaban sah, pasangan lokal NIP ini tidak disentuh.
                unset($berpasangan[$nip]);

                continue;
            }

            $golDiproses = [];

            foreach ($h['data'] as $si) {
                if (! is_array($si) || trim((string) (is_scalar($si['golongan'] ?? null) ? $si['golongan'] : '')) === '') {
                    continue;
                }

                try {
                    $kolom = $this->kolom($si, $pangkat, $jenisKpSi, $jenisKp);
                } catch (NilaiTidakSah $e) {
                    $rencana->tolak($e->alasan);

                    continue;
                }

                $gol = $kolom['gol_ruang'];

                if (isset($golDiproses[$gol])) {
                    if (! isset($ganda[$nip])) {
                        $rencana->sisip('double_riwayat_kp', ['nip' => $nip, 'created_at' => $sekarang], 'ganda');
                        $ganda[$nip] = $nip;
                    }

                    continue;
                }

                $golDiproses[$gol] = true;
                $idSiasn           = (string) $kolom['id_rw_siasn'];

                if (isset($berpasangan[$nip][$idSiasn])) {
                    $ada = $berpasangan[$nip][$idSiasn];
                    unset($berpasangan[$nip][$idSiasn]);

                    if ($this->berubah($ada, $kolom)) {
                        $rencana->ubah(self::TABEL, ['id_riwayat_kp' => (int) $ada['id_riwayat_kp']], $this->dataUbah($ada, $kolom, $idAkun, $sekarang));
                    }

                    continue;
                }

                if (isset($perGol[$nip][$gol])) {
                    $ada = $perGol[$nip][$gol];
                    // Baris yang dipasangkan ulang tidak ikut dilepas (legacy: UPDATE lalu lepas pada transaksi yang sama,
                    // pasangan baru stabil satu siklus kemudian).
                    unset($perGol[$nip][$gol], $berpasangan[$nip][(string) $ada['id_rw_siasn']]);
                    $rencana->ubah(self::TABEL, ['id_riwayat_kp' => (int) $ada['id_riwayat_kp']], $this->dataUbah($ada, $kolom, $idAkun, $sekarang), 'pasang');

                    continue;
                }

                $rencana->sisip(self::TABEL, [
                    'nip'         => $nip,
                    'tgl_sk'      => $kolom['tgl_sk'] ?? $kolom['tmtsk'],
                    'no_sk'       => $kolom['no_sk'],
                    'gaji_pokok'  => null,
                    'status'      => 1,
                    'approved_by' => $idAkun,
                    'created_at'  => $sekarang,
                ] + $this->dataDasar($kolom, $idAkun, $sekarang));
            }
        }

        foreach ($berpasangan as $perNip) {
            foreach ($perNip as $ada) {
                $rencana->ubah(self::TABEL, ['id_riwayat_kp' => (int) $ada['id_riwayat_kp']], ['id_rw_siasn' => null], 'lepas');
            }
        }

        return $rencana;
    }

    /**
     * Kolom dari satu baris SIASN (tervalidasi).
     *
     * @param array<string, mixed>                $si
     * @param array<string, array<string, mixed>> $pangkat
     * @param array<string, mixed>                $jenisKpSi
     * @param array<int|string, mixed>            $jenisKp
     *
     * @return array<string, mixed>
     */
    private function kolom(array $si, array $pangkat, array $jenisKpSi, array $jenisKp): array
    {
        $golongan  = (string) NilaiSiasn::teks($si, 'golongan', 10);
        $jenisKpId = NilaiSiasn::teks($si, 'jenisKPId', 100);
        $golRuang  = $jenisKpId === '211' ? trim("CPNS {$golongan}") : $golongan;

        if (mb_strlen($golRuang) > 10) {
            throw new NilaiTidakSah('golongan', 'panjang');
        }

        $idJenisKp = 3;
        $namaKp    = 'Reguler';

        if ($jenisKpId !== null && isset($jenisKpSi[$jenisKpId]) && isset($jenisKp[(int) $jenisKpSi[$jenisKpId]])) {
            $idJenisKp = (int) $jenisKpSi[$jenisKpId];
            $namaKp    = (string) $jenisKp[$idJenisKp];
        }

        $p = $pangkat[$golRuang] ?? null;

        return [
            'id_rw_siasn'            => NilaiSiasn::teks($si, 'id', 100) ?? throw new NilaiTidakSah('id', 'tipe'),
            'id_jenis_kp'            => $idJenisKp,
            'jenis_kp'               => $namaKp,
            'id_pangkat'             => $p === null ? null : (int) $p['id_pangkat'],
            'gol'                    => $p === null || (string) $p['gol'] === '' ? null : (string) $p['gol'],
            'ruang'                  => $p === null || (string) $p['ruang'] === '' ? null : (string) $p['ruang'],
            'pangkat'                => $p === null || (string) $p['pangkat'] === '' ? null : (string) $p['pangkat'],
            'gol_ruang'              => $golRuang,
            'tmtsk'                  => NilaiSiasn::tanggal($si, 'tmtGolongan') ?? throw new NilaiTidakSah('tmtGolongan', 'tanggal'),
            'tgl_sk'                 => NilaiSiasn::tanggal($si, 'skTanggal'),
            'no_sk'                  => NilaiSiasn::teks($si, 'skNomor', 100),
            'tgl_pertek_bkn'         => NilaiSiasn::tanggal($si, 'tglPertekBkn'),
            'no_pertek_bkn'          => NilaiSiasn::teks($si, 'noPertekBkn', 100),
            'jumlah_kredit_utama'    => NilaiSiasn::desimal($si, 'jumlahKreditUtama', 12),
            'jumlah_kredit_tambahan' => NilaiSiasn::desimal($si, 'jumlahKreditTambahan', 12),
            'mker_th'                => NilaiSiasn::bulat($si, 'masaKerjaGolonganTahun', 0, 127),
            'mker_bl'                => NilaiSiasn::bulat($si, 'masaKerjaGolonganBulan', 0, 127),
        ];
    }

    /**
     * Kolom yang dibandingkan legacy [K `Rw_gol.php:236-247`]; tanggal/nomor SIASN kosong tidak dianggap beda.
     *
     * @param array<string, mixed> $ada
     * @param array<string, mixed> $k
     */
    private function berubah(array $ada, array $k): bool
    {
        foreach (['id_jenis_kp', 'id_pangkat', 'jumlah_kredit_utama', 'jumlah_kredit_tambahan', 'pangkat', 'mker_th', 'mker_bl'] as $kolom) {
            if (NilaiSiasn::berbeda($ada[$kolom], $k[$kolom])) {
                return true;
            }
        }

        foreach (['tmtsk', 'tgl_sk', 'no_sk', 'tgl_pertek_bkn', 'no_pertek_bkn'] as $kolom) {
            if ($k[$kolom] !== null && NilaiSiasn::berbeda($ada[$kolom], $k[$kolom])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Data UPDATE [K `:249-272`, `:287-310`]: `tgl_sk`/`no_sk` SIASN kosong → nilai lokal (tgl_sk lokal kosong → tmt).
     *
     * @param array<string, mixed> $ada
     * @param array<string, mixed> $k
     *
     * @return array<string, mixed>
     */
    private function dataUbah(array $ada, array $k, int $idAkun, string $sekarang): array
    {
        return [
            'tgl_sk'     => $k['tgl_sk'] ?? (trim((string) $ada['tgl_sk']) !== '' ? $ada['tgl_sk'] : $k['tmtsk']),
            'no_sk'      => $k['no_sk'] ?? (trim((string) $ada['no_sk']) !== '' ? $ada['no_sk'] : null),
            'gaji_pokok' => trim((string) $ada['gaji_pokok']) !== '' ? $ada['gaji_pokok'] : null,
        ] + $this->dataDasar($k, $idAkun, $sekarang);
    }

    /**
     * @param array<string, mixed> $k
     *
     * @return array<string, mixed>
     */
    private function dataDasar(array $k, int $idAkun, string $sekarang): array
    {
        return [
            'id_jenis_kp'            => $k['id_jenis_kp'],
            'id_pangkat'             => $k['id_pangkat'],
            'tmtsk'                  => $k['tmtsk'],
            'tgl_pertek_bkn'         => $k['tgl_pertek_bkn'],
            'no_pertek_bkn'          => $k['no_pertek_bkn'],
            'jumlah_kredit_utama'    => $k['jumlah_kredit_utama'],
            'jumlah_kredit_tambahan' => $k['jumlah_kredit_tambahan'],
            'jenis_kp'               => $k['jenis_kp'],
            'gol'                    => $k['gol'],
            'ruang'                  => $k['ruang'],
            'gol_ruang'              => $k['gol_ruang'],
            'pangkat'                => $k['pangkat'],
            'mker_th'                => $k['mker_th'],
            'mker_bl'                => $k['mker_bl'],
            'keterangan'             => 'Sync SIASN',
            'id_rw_siasn'            => $k['id_rw_siasn'],
            'updated_by'             => $idAkun,
            'updated_at'             => $sekarang,
        ];
    }
}
