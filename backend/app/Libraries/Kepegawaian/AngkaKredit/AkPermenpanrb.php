<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\AngkaKredit;

use App\Libraries\Kepegawaian\Struktur\StrukturService;
use CodeIgniter\Database\BaseConnection;
use Config\AngkaKredit;

/**
 * Target angka kredit kenaikan pangkat JF menurut PermenPAN-RB 1/2023 (MAKE-021, keputusan user 16-10-2026 Q2) — port
 * PERSIS [K] `application/helpers/function_helper.php` getAKAccu() :2049-2165.
 *
 * - AK untuk KP: konstanta `Config\AngkaKredit::$akUntukKp` per jenjang JF pegawai (`jenjang_jf.jenjang_jf` jabatan di
 *   snapshot `pegawai_mutasi_jabatan`) × golongan (`pegawai_kp.gol_ruang`). Golongan di atas batas jenjang
 *   (`$batasGolonganJenjang`) → AK terbesar jenjang itu. Jenjang tidak dikenal / kosong → 0.
 * - AK saat ini: SUM `riwayat_ak_siasn.kredit_baru_total` untuk jabatan pegawai saat ini dengan `is_konversi = '1'` atau
 *   `is_integrasi = '1'` (tanpa saringan status, sama dengan legacy); ≤ 0 → 0.
 * - Kekurangan & `ak_maxed`: rumus prediksi KP JF dashboard pegawai legacy (`libraries/hr/L_user.php:719-740`).
 *
 * Pemakai legacy: dashboard pegawai (prediksi KP JF), dashboard atasan (AK bawahan JF), rekap pegawai — bukan tab riwayat
 * AK. Di v2 disajikan lewat `GET pegawai/{nip}/ak/target-kp` sebagai sumber data dashboard Fase berikut; tab AK tidak
 * berubah. [V2] `jenjang` diisi nama jenjang JF (legacy membaca kunci `jenjang` yang tidak ada → selalu kosong).
 */
final class AkPermenpanrb
{
    public function __construct(private readonly ?BaseConnection $db = null, private readonly ?AngkaKredit $config = null)
    {
    }

    /**
     * @return array{nip: string, nama: string, jabatan: string, satker: string, pangkat: string, gol_ruang: string,
     *     jenjang: string, current_ak: float, ak_for_kp: float, ak_kurang: float, ak_maxed: bool}
     */
    public function hitung(string $nip): array
    {
        $db  = $this->db ?? db_connect();
        $peg = $db->table('pegawai peg')
            ->select('peg.nip, peg.glr_awal, peg.nama, peg.glr_akhir, pmj.id_jabatan, pmj.jabatan, pmj.satker, '
                . 'jjf.jenjang_jf, pkp.pangkat, pkp.gol_ruang')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
            ->join('jabatan jab', 'jab.id_jabatan = pmj.id_jabatan', 'left')
            ->join('jenjang_jf jjf', 'jjf.id_jenjang_jf = jab.id_jenjang_jf', 'left')
            ->join('pegawai_kp pkp', 'pkp.nip = peg.nip', 'left')
            ->where('peg.nip', $nip)
            ->get()
            ->getRowArray() ?? [];

        $idJabatan = $peg['id_jabatan'] ?? null;
        $jenjang   = trim((string) ($peg['jenjang_jf'] ?? ''));
        $golRuang  = trim((string) ($peg['gol_ruang'] ?? ''));
        $saatIni   = 0.0;

        if ($idJabatan !== null && $idJabatan !== '') {
            $total = $db->table('riwayat_ak_siasn')->selectSum('kredit_baru_total', 'total')
                ->where('nip', $nip)->where('id_jabatan', $idJabatan)
                ->groupStart()->where('is_konversi', '1')->orWhere('is_integrasi', '1')->groupEnd()
                ->get()->getRowArray();
            $saatIni = max(0.0, (float) ($total['total'] ?? 0));
        }

        $target = $this->akUntukKp($jenjang, $golRuang);

        return [
            'nip'        => (string) ($peg['nip'] ?? ''),
            'nama'       => isset($peg['nama']) ? StrukturService::namaGelar($peg['glr_awal'] ?? null, (string) $peg['nama'], $peg['glr_akhir'] ?? null) : '',
            'jabatan'    => (string) ($peg['jabatan'] ?? ''),
            'satker'     => (string) ($peg['satker'] ?? ''),
            'pangkat'    => (string) ($peg['pangkat'] ?? ''),
            'gol_ruang'  => $golRuang,
            'jenjang'    => $jenjang,
            'current_ak' => $saatIni,
            'ak_for_kp'  => $target,
            'ak_kurang'  => $target > $saatIni ? $target - $saatIni : 0.0,
            'ak_maxed'   => ! ($target > $saatIni),
        ];
    }

    /**
     * AK kumulatif untuk KP: konstanta jenjang × golongan; golongan di atas batas jenjang → nilai terbesar jenjang.
     */
    public function akUntukKp(string $jenjang, string $golRuang): float
    {
        $config = $this->config ?? config(AngkaKredit::class);

        if ($jenjang === '' || ! isset($config->batasGolonganJenjang[$jenjang])) {
            return 0.0;
        }

        $nilaiGol = $config->nilaiGolongan[$golRuang] ?? 0;
        $daftar   = $config->akUntukKp[$jenjang] ?? [];

        if ($config->batasGolonganJenjang[$jenjang] >= $nilaiGol) {
            return (float) ($daftar[$golRuang] ?? 0);
        }

        return $daftar === [] ? 0.0 : (float) max($daftar);
    }
}
