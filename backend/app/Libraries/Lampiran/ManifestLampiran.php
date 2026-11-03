<?php

declare(strict_types=1);

namespace App\Libraries\Lampiran;

use App\Libraries\Kepegawaian\Lampiran\PathLegacy;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;

/**
 * Manifest berkas lampiran legacy (MAKE-069 §4, K-M3 opsi A, K-M4 opsi 1): daftar path unik dari kolom berkas DB v2
 * SESUDAH impor (nilai sudah di-stripslashes, DBV-029 §5.2), dinormalisasi seperti `fixArsipPath`, dikelompokkan per
 * sumber (`arsip` / `aplikasi`). Disimpan di folder kerja di luar repo (berisi nama berkas nyata). Path yang tidak sah
 * dihitung per kolom, tidak dimasukkan.
 *
 * Bentuk: {versi, tujuan, berkas: {path: {sumber, rujukan: {"tabel.kolom": n}, sha256, hasil}}, tidak_sah: {"tabel.kolom": n}}.
 */
final class ManifestLampiran
{
    /**
     * Kolom berkas legacy per tabel (audit skema v2; kolom yang tidak ada di tujuan dilewati).
     */
    public const KOLOM = [
        'absen_ijin'             => ['file_bukti', 'file_bukti_2', 'file_bukti_3', 'file_bukti_4', 'file_bukti_5'],
        'document_attachment'    => ['path'],
        'esign_hist'             => ['file_pdf_to_sign'],
        'konv_ak'                => ['file_dupak'],
        'pegawai'                => ['foto'],
        'pegawai_ak'             => ['file_pak'],
        'pegawai_ak_siasn'       => ['f_pak_siasn', 'f_pak'],
        'pegawai_foto'           => ['foto'],
        'pegawai_hist'           => ['foto'],
        'pegawai_mutasi_jabatan' => ['f_spmt', 'f_bapel'],
        'pegawai_plh'            => ['lampiran'],
        'pegawai_plt'            => ['lampiran'],
        'riwayat_ak'             => ['file_pak'],
        'riwayat_ak_siasn'       => ['f_pak_siasn', 'f_pak'],
        'riwayat_cuti'           => ['file_bukti', 'file_sc_unsign', 'file_sc_signed_bef', 'file_surat_cuti'],
        'riwayat_ib'             => ['file_sp', 'file_1', 'file_2', 'file_surat_ib'],
        'riwayat_kariskarsu'     => ['file_1', 'file_2', 'file_3', 'file_4', 'file_5', 'file_surat_hilang', 'file_alasan', 'file_tanda_terima'],
        'riwayat_karpeg'         => ['file_surat_hilang', 'file_1', 'file_2', 'file_3', 'file_4', 'file_5', 'file_tanda_terima'],
        'riwayat_lckh'           => ['file_lckh'],
        'riwayat_mutasi_jabatan' => ['f_spmt', 'f_bapel'],
        'riwayat_pmk_req'        => ['file_kontrak', 'file_paklaring', 'file_surat_phk', 'file_slip_gaji'],
        'riwayat_skp_periodik'   => ['f_arsip_1', 'f_arsip_2'],
        'riwayat_tb'             => ['file_sp', 'file_1', 'file_2', 'file_3', 'file_surat_tb'],
        'riwayat_tb_perpan'      => ['file_sperpan'],
        'siasn_upload_arsip'     => ['arsip_path'],
    ];

    /**
     * @return array{versi: int, tujuan: string, berkas: array<string, array{sumber: string, rujukan: array<string, int>, sha256: string|null, hasil: string|null}>, tidak_sah: array<string, int>}
     */
    public static function bangun(BaseConnection $db): array
    {
        $berkas   = [];
        $tidakSah = [];

        foreach (self::KOLOM as $tabel => $kolom) {
            $nama = $db->prefixTable($tabel);

            foreach ($kolom as $k) {
                if (! self::adaKolom($db, $nama, $k)) {
                    throw new RuntimeException("Kolom berkas {$tabel}.{$k} tidak ada di DB tujuan (migrate --all belum lengkap?).");
                }

                $label = "{$tabel}.{$k}";
                // BINARY: path yang beda huruf besar/kecil, aksen, atau spasi akhir adalah berkas berbeda (review P-1).
                $hasil = $db->query("SELECT MIN(`{$k}`) AS p, COUNT(*) AS n FROM `{$nama}` WHERE `{$k}` IS NOT NULL AND TRIM(`{$k}`) <> '' GROUP BY BINARY `{$k}`");

                if (! $hasil instanceof ResultInterface) {
                    throw new RuntimeException("Galat DB saat membaca {$label}.");
                }

                foreach ($hasil->getResultArray() as $r) {
                    $normal = PathLegacy::normalisasi((string) $r['p']);

                    if ($normal === null) {
                        $tidakSah[$label] = ($tidakSah[$label] ?? 0) + (int) $r['n'];

                        continue;
                    }

                    $berkas[$normal] ??= ['sumber' => PathLegacy::sumber($normal), 'rujukan' => [], 'sha256' => null, 'hasil' => null];
                    $berkas[$normal]['rujukan'][$label] = ($berkas[$normal]['rujukan'][$label] ?? 0) + (int) $r['n'];
                }
            }
        }

        ksort($berkas, SORT_STRING);

        return ['versi' => 1, 'tujuan' => $db->getDatabase(), 'berkas' => $berkas, 'tidak_sah' => $tidakSah];
    }

    /**
     * @return array{versi: int, tujuan: string, berkas: array<string, array{sumber: string, rujukan: array<string, int>, sha256: string|null, hasil: string|null}>, tidak_sah: array<string, int>}
     */
    public static function baca(string $berkas): array
    {
        $isi = is_file($berkas) ? json_decode((string) file_get_contents($berkas), true) : null;

        if (! is_array($isi) || ($isi['versi'] ?? null) !== 1 || ! isset($isi['berkas']) || ! is_array($isi['berkas'])) {
            throw new RuntimeException('Manifest tidak ada atau rusak.');
        }

        /** @var array{versi: int, tujuan: string, berkas: array<string, array{sumber: string, rujukan: array<string, int>, sha256: string|null, hasil: string|null}>, tidak_sah: array<string, int>} */
        return $isi;
    }

    /**
     * Tulis atomik (sementara → rename), supaya penarikan yang terputus bisa dilanjutkan.
     *
     * @param array<string, mixed> $manifest
     */
    public static function tulis(string $berkas, array $manifest): void
    {
        $json      = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sementara = $berkas . '.tmp';

        if ($json === false || file_put_contents($sementara, $json) === false || ! @chmod($sementara, 0600) || ! rename($sementara, $berkas)) {
            @unlink($sementara);

            throw new RuntimeException('Manifest gagal ditulis.');
        }
    }

    /**
     * Ringkasan jumlah per sumber (tanpa nama berkas).
     *
     * @param array{berkas: array<string, array{sumber: string, rujukan: array<string, int>, sha256: string|null, hasil: string|null}>, tidak_sah: array<string, int>} $manifest
     *
     * @return list<string>
     */
    public static function ringkas(array $manifest): array
    {
        $per = [];

        foreach ($manifest['berkas'] as $b) {
            $per[$b['sumber']] = ($per[$b['sumber']] ?? 0) + 1;
        }

        ksort($per);
        $out = [];

        foreach ($per as $sumber => $n) {
            $out[] = "berkas unik sumber {$sumber}: {$n}";
        }

        foreach ($manifest['tidak_sah'] as $label => $n) {
            $out[] = "path tidak sah {$label}: {$n}";
        }

        return $out;
    }

    private static function adaKolom(BaseConnection $db, string $tabel, string $kolom): bool
    {
        $hasil = $db->query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$db->getDatabase(), $tabel, $kolom]);

        if (! $hasil instanceof ResultInterface) {
            throw new RuntimeException('Galat DB saat memeriksa kolom berkas.');
        }

        return $hasil->getRowArray() !== null;
    }
}
