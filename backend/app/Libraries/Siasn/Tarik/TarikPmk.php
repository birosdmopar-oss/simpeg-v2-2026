<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Siasn\SiasnAdapter;
use CodeIgniter\Database\BaseConnection;

/**
 * `siasn:tarik --jenis=pmk` — padanan `SS/Rw_pmk::get_siasn` [K `Rw_pmk.php:10-245`] (`pns/rw-masakerja`) ke
 * `riwayat_pmk`, PNS aktif. Bug legacy diperbaiki (putusan reviewer B6 Q3/Q4):
 *
 * - pairing berdasarkan `tgl_sk` untuk baris lokal tanpa pasangan (legacy mensyaratkan kolom `tgl_lulus` yang tidak ada
 *   di `riwayat_pmk`, `:57`, sehingga pairing tidak pernah terjadi dan riwayat tersisip ganda), lalu `id_rw_siasn` dipasang
 *   (pembaruan pairing legacy dikomentari, `:193-195`); satu `tgl_sk` cocok ke >1 baris lokal → tidak dipasangkan dan
 *   tidak disisipkan, dihitung INFO `pairing_ganda`;
 * - `siasn_lu` = waktu tarik (legacy menulis teks "Sync SIASN" ke DATETIME, `:148`);
 * - masa kerja tahun dibaca dari `masaKerjaTahun` (legacy salah ketik `tasaKerjaTahun` → selalu 0, `:142`), bawaan 0;
 * - lepas pasangan berjalan juga tanpa sisip (syarat legacy `count($paramUpd)` ganda, `:183`), hanya untuk NIP yang API-nya
 *   sukses dengan data tidak kosong (pengaman F6-14).
 */
final class TarikPmk implements JenisTarikSiasn
{
    private const TABEL = 'riwayat_pmk';

    public function nama(): string
    {
        return 'pmk';
    }

    public function kolomStatus(): string
    {
        return 'rw_pmk';
    }

    public function jenisPegawai(): array
    {
        return [1];
    }

    public function ambil(SiasnGatewayInterface $siasn, string $nip): array
    {
        return $siasn->getRiwayat($nip, 'pmk');
    }

    public function rencana(BaseConnection $db, array $hasil, int $idAkun, string $sekarang): RencanaTarik
    {
        $rencana = new RencanaTarik();

        if ($hasil === []) {
            return $rencana;
        }

        $berpasangan = $perTglSk = [];

        foreach ($db->table(self::TABEL)->where('status', 1)->whereIn('nip', array_map('strval', array_keys($hasil)))
            ->orderBy('nip', 'ASC')->orderBy('tgl_sk', 'ASC')->get()->getResultArray() as $baris) {
            if (trim((string) $baris['id_rw_siasn']) !== '') {
                $berpasangan[$baris['nip']][(string) $baris['id_rw_siasn']] = $baris;
            } elseif ((string) $baris['tgl_sk'] !== '') {
                $perTglSk[$baris['nip']][(string) $baris['tgl_sk']][] = $baris;
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

                    $tglSk = NilaiSiasn::tanggal($si, 'tanggalSk') ?? throw new NilaiTidakSah('tanggalSk', 'tanggal');

                    if (isset($perTglSk[$nip][$tglSk])) {
                        if (count($perTglSk[$nip][$tglSk]) > 1) {
                            // Putusan reviewer B6 Q3: satu tgl_sk cocok ke >1 baris lokal → tidak dipasangkan, tidak disisipkan.
                            $rencana->info['pairing_ganda']++;

                            continue;
                        }

                        $ada = $perTglSk[$nip][$tglSk][0];
                        unset($perTglSk[$nip][$tglSk]);
                        $rencana->ubah(self::TABEL, ['id_riwayat_pmk' => (int) $ada['id_riwayat_pmk']], [
                            'id_rw_siasn' => $idSiasn, 'siasn_flag' => 1, 'siasn_lu' => $sekarang, 'updated_by' => $idAkun, 'updated_at' => $sekarang,
                        ], 'pasang');

                        continue;
                    }

                    $rencana->sisip(self::TABEL, [
                        'nip'          => $nip,
                        'id_pns_siasn' => NilaiSiasn::teks($si, 'idPns', 100),
                        'no_sk'        => NilaiSiasn::teks($si, 'nomorSk', 100) ?? '',
                        'tgl_sk'       => $tglSk,
                        'no_bkn'       => NilaiSiasn::teks($si, 'nomorBkn', 100),
                        'tgl_bkn'      => NilaiSiasn::tanggal($si, 'tanggalBkn'),
                        'instansi'     => NilaiSiasn::teks($si, 'pengalaman', 255),
                        'tgl_awal'     => NilaiSiasn::tanggal($si, 'tanggalAwal'),
                        'tgl_akhir'    => NilaiSiasn::tanggal($si, 'tanggalSelesai'),
                        'mker_th'      => NilaiSiasn::bulat($si, isset($si['masaKerjaTahun']) ? 'masaKerjaTahun' : 'tasaKerjaTahun', 0, 127) ?? 0,
                        'mker_bl'      => NilaiSiasn::bulat($si, 'masaKerjaBulan', 0, 127) ?? 0,
                        'dinilai'      => NilaiSiasn::teks($si, 'dinilai', 50),
                        'id_rw_siasn'  => $idSiasn,
                        'siasn_flag'   => 1,
                        'siasn_error'  => null,
                        'siasn_lu'     => $sekarang,
                        'status'       => 1,
                        'updated_by'   => $idAkun,
                        'created_at'   => $sekarang,
                        'updated_at'   => $sekarang,
                    ]);
                } catch (NilaiTidakSah $e) {
                    $rencana->tolak($e->alasan);
                }
            }
        }

        foreach ($berpasangan as $perNip) {
            foreach ($perNip as $ada) {
                $rencana->ubah(self::TABEL, ['id_riwayat_pmk' => (int) $ada['id_riwayat_pmk']], ['id_rw_siasn' => null], 'lepas');
            }
        }

        return $rencana;
    }
}
