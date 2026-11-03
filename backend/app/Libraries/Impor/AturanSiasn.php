<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;

/**
 * Aturan impor tabel Fase 6 yang butuh melihat seluruh sumber atau tujuan (DBV-027 §13.3). Semua pesan dan kode laporan
 * hanya memuat jumlah, tanpa NIP/id.
 */
final class AturanSiasn
{
    public const PESAN_TERHENTI = 'Terhenti saat cutover — periksa di SIASN sebelum mengantre ulang';

    private const BATCH = 5000;

    private function __construct()
    {
    }

    /**
     * K-3/K-4 — kunci UNIQUE `uq_rsp_pns_periode_skp` (id_pns, periode_id, skp_id). Per kunci ganda dipertahankan baris
     * yang dibaca Tukin (`SumberDataPegawaiTukin::predikatSkp`: `status = 1`, `hasil_akhir IS NOT NULL`, id terkecil),
     * kalau tidak ada, id terkecil. Grup dengan NIP berbeda → gagal (DBV memutus per kasus). Kunci ber-NULL tidak dibatasi
     * UNIQUE, jadi tidak dikelompokkan.
     *
     * @return array<int|string, string> id sumber → kode aturan (kunci angka menjadi int di PHP)
     */
    public static function skpPeriodik(BaseConnection $db, string $tabel, Keputusan $keputusan): array
    {
        $grup = [];

        foreach (self::baca($db, $tabel, 'id_riwayat_skp_periodik', ['id_pns', 'periode_id', 'skp_id', 'nip', 'status', 'hasil_akhir']) as $r) {
            $kunci = self::kunci([$r['id_pns'], $r['periode_id'], $r['skp_id']]);

            if ($kunci === null) {
                continue;
            }

            $status         = self::status($keputusan, 'riwayat_skp_periodik', $r['status'], [0, 1, 2, 10]);
            $grup[$kunci][] = [
                'id'    => (int) $r['id_riwayat_skp_periodik'],
                'utama' => $status === '1' && $r['hasil_akhir'] !== null,
                'nip'   => (string) Transformasi::kode($r['nip'] === null ? null : (string) $r['nip']),
            ];
        }

        $buang  = [];
        $campur = 0;

        foreach ($grup as $baris) {
            if (count($baris) < 2) {
                continue;
            }

            if (count(array_unique(array_column($baris, 'nip'))) > 1) {
                $campur++;

                continue;
            }

            usort($baris, static fn (array $a, array $b): int => [$a['utama'] ? 0 : 1, $a['id']] <=> [$b['utama'] ? 0 : 1, $b['id']]);

            foreach (array_slice($baris, 1) as $kalah) {
                $buang[(string) $kalah['id']] = 'DBV-027 K-3 kunci ganda';
            }
        }

        if ($campur > 0) {
            throw new ImporGagal("riwayat_skp_periodik: {$campur} grup kunci (id_pns, periode_id, skp_id) berisi NIP berbeda; diputus DBV per kasus (DBV-027 K-4).");
        }

        return $buang;
    }

    /**
     * K-6 — kunci UNIQUE `uq_sua_rw_da` (rw_name, id_rw, id_da): satu baris per kunci, prioritas `status = 1` (terunggah,
     * mencegah unggah ulang ke BKN) lalu id terbesar. `id_da` NULL → gagal (kolom NOT NULL).
     *
     * @return array<int|string, string> id sumber → kode aturan (kunci angka menjadi int di PHP)
     */
    public static function uploadArsip(BaseConnection $db, string $tabel, Keputusan $keputusan): array
    {
        $grup    = [];
        $tanpaDa = 0;

        foreach (self::baca($db, $tabel, 'id_siasn_upload_arsip', ['rw_name', 'id_rw', 'id_da', 'status']) as $r) {
            if ($r['id_da'] === null) {
                $tanpaDa++;

                continue;
            }

            $kunci = self::kunci([Transformasi::stripslashes($r['rw_name'] === null ? null : (string) $r['rw_name']), $r['id_rw'], $r['id_da']]);

            if ($kunci === null) {
                continue;
            }

            $grup[$kunci][] = [
                'id'       => (int) $r['id_siasn_upload_arsip'],
                'unggahan' => self::status($keputusan, 'siasn_upload_arsip', $r['status'], [0, 1, 2, 3]) === '1',
            ];
        }

        if ($tanpaDa > 0) {
            throw new ImporGagal("siasn_upload_arsip: {$tanpaDa} baris id_da NULL (id_da NOT NULL, DBV-027 K-6).");
        }

        $buang = [];

        foreach ($grup as $baris) {
            if (count($baris) < 2) {
                continue;
            }

            usort($baris, static fn (array $a, array $b): int => [$a['unggahan'] ? 0 : 1, -$a['id']] <=> [$b['unggahan'] ? 0 : 1, -$b['id']]);

            foreach (array_slice($baris, 1) as $kalah) {
                $buang[(string) $kalah['id']] = 'DBV-027 K-6 kunci ganda';
            }
        }

        return $buang;
    }

    /**
     * K-7 — baris yang sedang diproses saat cutover tidak diantre ulang otomatis (legacy hanya mengambil status 0).
     *
     * @param array<string, mixed> $baris
     *
     * @return array<string, mixed>
     */
    public static function terhentiSaatCutover(array $baris): array
    {
        if (Transformasi::kunciStatus($baris['status'] ?? null) !== '3') {
            return $baris;
        }

        $baris['status']    = 2;
        $baris['error_msg'] = self::PESAN_TERHENTI;
        $baris['__koreksi'] = ['DBV-027 K-7 status 3→2'];

        return $baris;
    }

    /**
     * K-6 INFO — `id_rw`/`id_da` tanpa baris di tujuan (tanpa FK; pemroses menandai gagal saat berkas tidak ada). Bila
     * tabel induk masih kosong, kodenya menyebut bahwa induk belum diimpor (tier SIASN dijalankan terlalu awal).
     *
     * @return array<string, int>
     */
    public static function rujukanArsip(BaseConnection $tujuan): array
    {
        $sua = $tujuan->prefixTable('siasn_upload_arsip');
        $cek = [
            'id_da'                => ['document_attachment', 'id_attachment', '1 = 1'],
            'id_rw Rw Jabatan'     => ['riwayat_mutasi_jabatan', 'id_riwayat_mutasi_jabatan', "s.rw_name = 'Rw Jabatan'"],
            'id_rw Rw Sertifikasi' => ['riwayat_diklat', 'id_riwayat_diklat', "s.rw_name = 'Rw Sertifikasi'"],
        ];
        $hasil = [];

        foreach ($cek as $label => [$induk, $pk, $syarat]) {
            $kolom = str_starts_with($label, 'id_da') ? 'id_da' : 'id_rw';
            $t     = $tujuan->prefixTable($induk);
            $n     = self::hitung($tujuan, "SELECT COUNT(*) AS n FROM `{$sua}` s WHERE {$syarat} AND NOT EXISTS (SELECT 1 FROM `{$t}` i WHERE i.`{$pk}` = s.`{$kolom}`)");

            if ($n > 0) {
                $kosong                                                                                                                      = self::hitung($tujuan, "SELECT COUNT(*) AS n FROM `{$t}`") === 0;
                $hasil[$kosong ? "INFO {$label} tanpa rujukan: {$induk} belum diimpor (kosong)" : "INFO {$label} tanpa rujukan di {$induk}"] = $n;
            }
        }

        $lain = self::hitung($tujuan, "SELECT COUNT(*) AS n FROM `{$sua}` WHERE rw_name NOT IN ('Rw Jabatan', 'Rw Sertifikasi')");

        if ($lain > 0) {
            $hasil['INFO rw_name di luar Rw Jabatan/Rw Sertifikasi'] = $lain;
        }

        return $hasil;
    }

    /**
     * Baca sumber per batch keyset PK (hanya kolom yang dibutuhkan aturan).
     *
     * @param list<string> $kolom
     *
     * @return iterable<array<string, mixed>>
     */
    private static function baca(BaseConnection $db, string $tabel, string $pk, array $kolom): iterable
    {
        $pilih    = implode(', ', array_map(static fn (string $k): string => $db->escapeIdentifiers($k), [$pk, ...$kolom]));
        $terakhir = null;

        do {
            $sql = "SELECT {$pilih} FROM " . $db->escapeIdentifiers($tabel)
                . ($terakhir === null ? '' : ' WHERE ' . $db->escapeIdentifiers($pk) . ' > ?')
                . ' ORDER BY ' . $db->escapeIdentifiers($pk) . ' LIMIT ' . self::BATCH;
            $hasil = $db->query($sql, $terakhir === null ? null : [$terakhir]);

            if (! $hasil instanceof ResultInterface) {
                throw new ImporGagal("Galat DB saat pra-pindai {$tabel} (kode " . (string) ($db->error()['code'] ?? '?') . ').');
            }

            $baris = $hasil->getResultArray();

            foreach ($baris as $r) {
                $terakhir = $r[$pk];

                yield $r;
            }
        } while (count($baris) === self::BATCH);
    }

    /**
     * Kunci UNIQUE seperti pembanding `utf8mb4_unicode_ci` di mesin (huruf besar/kecil & spasi akhir diabaikan); null bila
     * ada bagian NULL (UNIQUE tidak membatasi NULL).
     *
     * @param list<mixed> $bagian
     */
    private static function kunci(array $bagian): ?string
    {
        $out = [];

        foreach ($bagian as $b) {
            if ($b === null) {
                return null;
            }

            $out[] = mb_strtolower(rtrim((string) $b, ' '));
        }

        return implode("\x1F", $out);
    }

    /**
     * Status sesudah pemetaan berkas keputusan, sama dengan lintasan impor (nilai → domain). NULL tetap NULL.
     *
     * @param list<int|string> $domain
     */
    private static function status(Keputusan $keputusan, string $tabel, mixed $nilai, array $domain): ?string
    {
        $peta  = $keputusan->nilai($tabel, 'status', $nilai);
        $nilai = $peta['ada'] ? $peta['nilai'] : $nilai;

        return $nilai === null ? null : (string) $keputusan->domain($tabel, 'status', $nilai, $domain);
    }

    private static function hitung(BaseConnection $db, string $sql): int
    {
        $hasil = $db->query($sql);

        if (! $hasil instanceof ResultInterface) {
            throw new ImporGagal('Galat DB saat hitung INFO rujukan siasn_upload_arsip (kode ' . (string) ($db->error()['code'] ?? '?') . ').');
        }

        return (int) ($hasil->getRowArray()['n'] ?? 0);
    }
}
