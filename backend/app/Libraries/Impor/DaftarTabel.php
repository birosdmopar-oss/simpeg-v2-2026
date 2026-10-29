<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use App\Libraries\MasterData\WebConfigImport;
use Config\WebConfig;

/**
 * Daftar tabel jalur Tukin per tier, urut induk → anak (FK G-02 DBV-019 aktif sesudah `migrate`). Dipanggil sekali per
 * tier per proses impor, jadi aturan khusus yang menyimpan keadaan (dedupe) mulai bersih setiap kali.
 */
final class DaftarTabel
{
    public const TIER_MAKS = 6;

    private function __construct()
    {
    }

    /**
     * @return list<DefinisiTabel>
     */
    public static function tier(int $tier): array
    {
        return match ($tier) {
            1       => self::tier1(),
            2       => self::tier2(),
            3       => self::tier3(),
            4       => self::tier4(),
            5       => self::tier5(),
            6       => self::tier6(),
            default => throw new ImporGagal("Tier {$tier} belum tersedia."),
        };
    }

    /**
     * T1 — master: Batch 1 + wilayah (DBV-011 §2.2 #7, sentinel = seed), G-02/G-02b (DBV-008/019), G-01, G-04, G-07,
     * G-09, DBV-022 §5.
     *
     * @return list<DefinisiTabel>
     */
    private static function tier1(): array
    {
        $g02 = 'G-02 §6.5, DBV-019, DBV-011 §2.10';

        return [
            new DefinisiTabel('agama', 'id_agama', 'DBV-011 §2.4 (Batch 1)', kolomNama: ['agama']),
            new DefinisiTabel('jenis_status', 'id_jenis_status', 'DBV-011 §2.4 (Batch 1)', kolomNama: ['jenis_status']),
            new DefinisiTabel('provinsi', 'id_provinsi', 'DBV-011 §2.2 #7 (sentinel = seed)', kolomNama: ['provinsi'], bolehSeed: true, kolomKode: ['id_provinsi']),
            new DefinisiTabel(
                'kabupaten_kota',
                'id_kabupaten_kota',
                'DBV-011 §2.2 #7 (sentinel = seed)',
                kolomNama: ['kabupaten_kota'],
                bolehSeed: true,
                kolomKode: ['id_kabupaten_kota', 'id_provinsi'],
            ),
            new DefinisiTabel('pangkat', 'id_pangkat', 'G-04 §6.3/6.4', kolomNama: ['pangkat']),
            new DefinisiTabel('jenis_kp', 'id_jenis_kp', 'G-04 §6.4 (id 1/2/6 hard-code)', kolomNama: ['jenis_kp']),
            new DefinisiTabel('jenjang_pendidikan', 'id_jenjang_pendidikan', 'G-04/G-05 §6.3', kolomNama: ['jenjang_pendidikan']),
            new DefinisiTabel('unit', 'id_unit', $g02, kolomNama: ['unit']),
            new DefinisiTabel('satker', 'id_satker', $g02, kolomNama: ['satker']),
            new DefinisiTabel('group_jabatan', 'id_group_jabatan', $g02, kolomNama: ['group_jabatan']),
            new DefinisiTabel(
                'sub_group_jabatan',
                'id_sub_group_jabatan',
                $g02,
                kolomNama: ['sub_group_jabatan'],
                urutan: ['kolom' => 'order', 'lingkup' => ['id_group_jabatan']],
            ),
            new DefinisiTabel('rumpun_jabatan', 'id_rumpun_jabatan', 'G-02b §6.5', kolomNama: ['rumpun_jabatan']),
            new DefinisiTabel('kelas_jabatan', 'kelas_jabatan', $g02),
            new DefinisiTabel('jenjang_jf', 'id_jenjang_jf', 'G-02b §6.5', kolomNama: ['jenjang_jf'], opsional: true),
            new DefinisiTabel('jabatan', 'id_jabatan', $g02, kolomNama: ['jabatan']),
            new DefinisiTabel(
                'jabatan_koordinasi',
                'id_jabatan_koordinasi',
                'G-02b §6.5, DBV-019',
                kolomNama: ['jabatan'],
                kolomTunda: ['id_atasan_es_3_koord'],
            ),
            new DefinisiTabel('periode_struktur_jabatan', 'id_periode_struktur_jabatan', 'G-02b §6.5', kolomNama: ['periode_struktur_jabatan']),
            new DefinisiTabel('struktur_jabatan', 'id_struktur_jabatan', 'G-02b §6.5', kolomNama: ['jabatan', 'jabatan_atasan']),
            new DefinisiTabel('jenis_pegawai', 'id_jenis_pegawai', 'G-01, DBV-011 §2.4', kolomNama: ['jenis_pegawai']),
            new DefinisiTabel('gol_pppk', 'id_gol_pppk', 'G-04 §6.3/6.4', kolomNama: ['gol_pppk']),
            new DefinisiTabel('jenis_libur', 'id_jenis_libur', 'G-07 D7', kolomNama: ['jenis_libur']),
            new DefinisiTabel('hari_libur', 'id_libur', 'G-07 §6.3, DBV-011 R2', kolomNama: ['nama_libur']),
            new DefinisiTabel('jenis_cuti', 'id_jenis_cuti', 'DBV-022 §5 (seed migration, GAP-3)', kolomNama: ['nama_jenis_cuti'], bolehSeed: true),
            new DefinisiTabel(
                'web_config',
                'id_web_config',
                'G-09 §5 (WebConfigImport)',
                tanpaStripslashes: ['config_name', 'config_value', 'remark'],
                statusDomain: null,
                khusus: self::webConfig(...),
            ),
        ];
    }

    /**
     * T2 — pegawai: B-01/B-02 §6 #1–4 (NIP ≤ 30, domain CHECK dilaporkan lewat berkas keputusan, `''` FK → NULL).
     *
     * @return list<DefinisiTabel>
     */
    private static function tier2(): array
    {
        return [
            new DefinisiTabel(
                'pegawai',
                'nip',
                'B-01/B-02 §6 #1–4',
                domain: ['flag_update' => [0, 1, 2, 3], 'jenis_kelamin' => [1, 2]],
                kolomKode: ['nip', 'id_provinsi_lahir', 'id_kabupaten_kota_lahir'],
            ),
        ];
    }

    /**
     * T3 — riwayat jabatan/KP lalu snapshot & penugasan: B-01/B-02 §6 #1, #3, #6 (snapshot disalin apa adanya;
     * `id_riwayat_*` yatim → NULL dan dicatat), DBV-019 (FK G-02).
     *
     * @return list<DefinisiTabel>
     */
    private static function tier3(): array
    {
        $riwayat = [0, 1, 2, 3, 10];

        return [
            new DefinisiTabel('riwayat_mutasi_jabatan', 'id_riwayat_mutasi_jabatan', 'B-01 §6 #1/#3, DBV-019', statusDomain: $riwayat, kolomKode: ['nip']),
            new DefinisiTabel('riwayat_kp', 'id_riwayat_kp', 'B-01 §6 #1/#3', statusDomain: $riwayat, kolomKode: ['nip']),
            new DefinisiTabel(
                'pegawai_mutasi_jabatan',
                'nip',
                'B-01 §6 #1/#6 (snapshot)',
                statusDomain: null,
                kolomKode: ['nip'],
                yatimKeNull: ['id_riwayat_mutasi_jabatan'],
            ),
            new DefinisiTabel('pegawai_kp', 'nip', 'B-01 §6 #1/#6 (snapshot)', statusDomain: null, kolomKode: ['nip'], yatimKeNull: ['id_riwayat_kp']),
            new DefinisiTabel('pegawai_plt', 'id_pegawai_plt', 'B-01 §6, DBV-019', kolomKode: ['nip_plt']),
            new DefinisiTabel('pegawai_plh', 'id_pegawai_plh', 'B-01 §6, DBV-019', kolomKode: ['nip_plh']),
        ];
    }

    /**
     * T4 — izin/dinas, keluarga, SKP/BKN: B-01/B-02 §6 #3 (`absen_ijin.status` W/V/X/10; angka/`H` lama → berkas
     * keputusan), DBV-011 §2.4 #5 (`affect_tukin` = 1 bila tidak ada di sumber, lewat DEFAULT).
     *
     * @return list<DefinisiTabel>
     */
    private static function tier4(): array
    {
        return [
            new DefinisiTabel(
                'absen_ijin',
                'id',
                'B-01/B-02 §6 #3, DBV-011 §2.4 #5',
                statusDomain: ['W', 'V', 'X', '10'],
                domain: ['affect_tukin' => [1, 2], 'jenis_dinas' => [0, 1]],
                kolomKode: ['nip'],
                waktuBisnis: ['date_start', 'date_end'],
            ),
            new DefinisiTabel('riwayat_keluarga', 'id_riwayat_keluarga', 'B-02 §6', statusDomain: [0, 1, 2, 3, 10], kolomKode: ['nip']),
            new DefinisiTabel('detail_anak', 'id_detail_anak', 'B-02 §6', statusDomain: null),
            new DefinisiTabel('bkn_periode_ekinper', 'id', 'B-01 §3.4.1', statusDomain: null),
            new DefinisiTabel('riwayat_skp_periodik', 'id_riwayat_skp_periodik', 'B-01 §6', statusDomain: [0, 1, 2, 10], kolomKode: ['nip']),
        ];
    }

    /**
     * T5 — lokasi + presensi: G-03 §6.2, DBV-023 §7.1 (baris tanpa jam masuk & pulang dibuang dan dicatat; `sumber`
     * [V2] NULL = impor; koordinat [V2] NULL).
     *
     * @return list<DefinisiTabel>
     */
    private static function tier5(): array
    {
        return [
            new DefinisiTabel('lokasi_presensi', 'id_lokasi_presensi', 'G-03 §6.2', kolomNama: ['nama_lokasi']),
            new DefinisiTabel(
                'dh_online',
                'nip',
                'DBV-023 §7.1',
                statusDomain: null,
                kolomKode: ['nip'],
                kunciUrut: ['nip', 'tgl'],
                khusus: static fn (array $b): array|string => $b['jam_masuk'] === null && $b['jam_pulang'] === null ? 'DBV-023 A2 tanpa jam' : $b,
            ),
        ];
    }

    /**
     * T6 — layanan cuti/TB: DBV-022 §5, §13.1 / DBV-011 §2.12 (`pegawai_sisa_cuti`: duplikat `(nip, tahun)` → id
     * terkecil, saldo negatif → 0, keduanya dicatat).
     *
     * @return list<DefinisiTabel>
     */
    private static function tier6(): array
    {
        $sudah = [];

        return [
            new DefinisiTabel(
                'pegawai_sisa_cuti',
                'id_pegawai_sisa_cuti',
                'DBV-022 §13.1, DBV-011 §2.12',
                statusDomain: null,
                domain: ['digunakan' => [0, 1]],
                kolomKode: ['nip'],
                khusus: static function (array $b) use (&$sudah): array|string {
                    $kunci = $b['nip'] . '|' . $b['tahun'];

                    if (isset($sudah[$kunci])) {
                        return 'DBV-022 §13.1 duplikat (nip, tahun)';
                    }

                    $sudah[$kunci]  = true;
                    $b['__koreksi'] = [];

                    foreach (['sisa_cuti', 'sisa_cuti_n1', 'sisa_cuti_n2', 'sisa_cuti_ditangguhkan', 'utang_cuti'] as $k) {
                        if (isset($b[$k]) && (int) $b[$k] < 0) {
                            $b[$k]            = 0;
                            $b['__koreksi'][] = "DBV-022 §13.1 {$k} negatif→0";
                        }
                    }

                    return $b;
                },
            ),
            new DefinisiTabel(
                'riwayat_cuti',
                'id_riwayat_cuti',
                'DBV-022 §5',
                statusDomain: [0, 1, 2, 3, 4],
                domain: [
                    'status_al' => [0, 1, 2, 4], 'status_pyb' => [0, 1, 2, 3, 4], 'status_regen' => [0, 1], 'rated' => [0, 1, 2],
                    'skip_al'   => [0, 1], 'skip_pyb' => [0, 1],
                ],
                kolomKode: ['nip'],
            ),
            new DefinisiTabel(
                'riwayat_tb',
                'id_riwayat_tb',
                'DBV-022 §5',
                statusDomain: [0, 1, 2, 3],
                domain: ['status_admin_upt' => [0, 1, 2], 'status_admin_deputi' => [0, 1, 2], 'rated' => [0, 1, 2]],
                kolomKode: ['nip'],
            ),
            new DefinisiTabel('riwayat_tb_perpan', 'id_riwayat_tb_perpan', 'DBV-022 §5', statusDomain: null),
        ];
    }

    /**
     * G-09 §5 lewat `WebConfigImport::row()` (stripslashes, katalog, normalisasi tipe). SKIP/REVIEW tidak diimpor.
     *
     * @param array<string, mixed> $baris
     *
     * @return array<string, mixed>|string
     */
    private static function webConfig(array $baris, Keputusan $keputusan): array|string
    {
        $hasil = WebConfigImport::row(
            config(WebConfig::class)->keys,
            (string) $baris['config_name'],
            isset($baris['config_value']) ? (string) $baris['config_value'] : null,
            isset($baris['remark']) ? (string) $baris['remark'] : null,
        );

        if ($hasil['action'] !== WebConfigImport::IMPORT) {
            return 'G-09 ' . strtoupper($hasil['action']);
        }

        return ['config_name' => $hasil['config_name'], 'config_value' => $hasil['config_value'], 'remark' => $hasil['remark']] + $baris;
    }
}
