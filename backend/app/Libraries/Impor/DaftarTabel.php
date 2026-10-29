<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use App\Libraries\MasterData\WebConfigImport;
use Config\WebConfig;

/**
 * Daftar tabel jalur Tukin per tier, urut induk → anak (FK G-02 DBV-019 aktif sesudah `migrate`). Tier 2–6 ditambah di
 * M2/M3 MAKE-056.
 */
final class DaftarTabel
{
    public const TIER_MAKS = 1;

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
            default => throw new ImporGagal("Tier {$tier} belum tersedia."),
        };
    }

    /**
     * T1 — master: G-02/G-02b (DBV-008/019), G-01, G-04, G-07, G-09, DBV-022 §5.
     *
     * @return list<DefinisiTabel>
     */
    private static function tier1(): array
    {
        $g02 = 'G-02 §6.5, DBV-019, DBV-011 §2.10';

        return [
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
