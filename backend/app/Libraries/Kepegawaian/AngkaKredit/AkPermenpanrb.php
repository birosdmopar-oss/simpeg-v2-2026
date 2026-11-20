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
     * {@see hitung()} untuk banyak NIP sekaligus (CR-166): dua kueri per 500 NIP, bukan dua kueri per NIP. Hasil per NIP sama
     * persis dengan `hitung($nip)`.
     *
     * Asumsi skema: `pegawai_mutasi_jabatan.nip` dan `pegawai_kp.nip` adalah PRIMARY KEY (satu baris per NIP), jadi join
     * tidak menggandakan baris. NIP yang tidak ditemukan di hasil gabungan dihitung lewat `hitung()` supaya tetap identik.
     *
     * @param list<string> $nips
     *
     * @return array<array{nip: string, nama: string, jabatan: string, satker: string, pangkat: string, gol_ruang: string,
     *     jenjang: string, current_ak: float, ak_for_kp: float, ak_kurang: float, ak_maxed: bool}> kunci = NIP masukan
     *     (PHP menjadikan NIP numerik kunci int; pakai akses `$hasil[$nip]`, jangan mengandalkan tipe kunci)
     */
    public function hitungBanyak(array $nips): array
    {
        $db    = $this->db ?? db_connect();
        $hasil = [];

        foreach (array_chunk(array_values(array_unique($nips)), 500) as $potong) {
            $pegawai = [];
            $baris   = $db->table('pegawai peg')
                ->select('peg.nip, peg.glr_awal, peg.nama, peg.glr_akhir, pmj.id_jabatan, pmj.jabatan, pmj.satker, '
                    . 'jjf.jenjang_jf, pkp.pangkat, pkp.gol_ruang')
                ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = peg.nip', 'left')
                ->join('jabatan jab', 'jab.id_jabatan = pmj.id_jabatan', 'left')
                ->join('jenjang_jf jjf', 'jjf.id_jenjang_jf = jab.id_jenjang_jf', 'left')
                ->join('pegawai_kp pkp', 'pkp.nip = peg.nip', 'left')
                ->whereIn('peg.nip', $potong)
                ->get()->getResultArray();

            foreach ($baris as $r) {
                $pegawai[(string) $r['nip']] = $r;
            }

            // SUM per (nip, id_jabatan); yang dipakai hanya kelompok jabatan pegawai saat ini. Kunci NIP di-`rtrim` dan
            // dijumlahkan: collation kolom PAD SPACE, jadi `hitung()` (`WHERE nip = ?`) ikut menghitung baris yang NIP-nya
            // berspasi di ujung; di sini baris seperti itu harus jatuh ke NIP yang sama.
            $jumlah = [];
            $baris  = $db->table('riwayat_ak_siasn')->select('nip, id_jabatan')->selectSum('kredit_baru_total', 'total')
                ->whereIn('nip', $potong)
                ->groupStart()->where('is_konversi', '1')->orWhere('is_integrasi', '1')->groupEnd()
                ->groupBy('nip, id_jabatan')
                ->get()->getResultArray();

            foreach ($baris as $r) {
                $kunciNip                         = rtrim((string) $r['nip'], ' ');
                $kunciJabatan                     = (string) $r['id_jabatan'];
                $jumlah[$kunciNip][$kunciJabatan] = ($jumlah[$kunciNip][$kunciJabatan] ?? 0.0) + (float) ($r['total'] ?? 0);
            }

            foreach ($potong as $nip) {
                $nip = (string) $nip;
                $peg = $pegawai[$nip] ?? null;

                if ($peg === null) {
                    $hasil[$nip] = $this->hitung($nip);

                    continue;
                }

                $idJabatan = $peg['id_jabatan'] ?? null;
                $jenjang   = trim((string) ($peg['jenjang_jf'] ?? ''));
                $golRuang  = trim((string) ($peg['gol_ruang'] ?? ''));
                $saatIni   = $idJabatan !== null && $idJabatan !== '' ? max(0.0, $jumlah[rtrim($nip, ' ')][(string) $idJabatan] ?? 0.0) : 0.0;
                $target    = $this->akUntukKp($jenjang, $golRuang);

                $hasil[$nip] = [
                    'nip'        => (string) $peg['nip'],
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
        }

        return $hasil;
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
