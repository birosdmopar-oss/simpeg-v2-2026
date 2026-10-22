<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Dokumen;

use App\Libraries\Kepegawaian\Kalkulasi\MasaKerja;
use App\Libraries\Kepegawaian\Kalkulasi\Pensiun;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

/**
 * Kolom dokumen F-09 (PRD Fase 7 F7-16, §7.2.3) — kalkulasi murni tanpa DB, dipakai JSON, PDF, dan XLSX.
 *
 * XLSX = seluruh kolom legacy (`libraries/hr/L_document.php:26` DUK/Nominatif 37 kolom; Pensiun `:220`; Baperjakat
 * `:531-577` tanpa kolom tersembunyi/checkbox); PDF = subset inti. Turunan nilai mengikuti `L_employee.php:4696-5053`
 * [K]: nama bergelar, jenis kelamin 1 = LAKI-LAKI, tempat lahir/jurusan "LAIN-LAIN" memakai kolom `_lain`, eselon
 * E.I–E.IV dari `sub_group_jabatan` 1–4 group struktural (`function_helper.php:164-166`), usia/MKER/KGB dihitung
 * terhadap hari ini (F7-24), TMT UNIT = TMT jabatan snapshot (F7-23), tanggal `d M Y` (`dateToUser`).
 */
final class KolomDokumen
{
    public const DUK = 'duk';

    public const NOMINATIF = 'nominatif';

    public const PENSIUN = 'pensiun';

    public const BAPERJAKAT = 'baperjakat';

    /**
     * @var array<string, string>
     */
    public const JUDUL = [
        self::DUK        => 'Daftar Urut Kepangkatan',
        self::NOMINATIF  => 'Daftar Nominatif Pegawai',
        self::PENSIUN    => 'Daftar Proyeksi Pensiun',
        self::BAPERJAKAT => 'Dokumen Baperjakat',
    ];

    /**
     * Kolom XLSX DUK/Nominatif/Baperjakat (37, urutan legacy): key → label.
     *
     * @var array<string, string>
     */
    public const KOLOM_LENGKAP = [
        'nama'           => 'NAMA',
        'nip'            => 'NIP',
        'nip_lama'       => 'NIP LAMA',
        'gol_ruang'      => 'KP (GOL)',
        'tmtsk_pangkat'  => 'KP (TMT)',
        'gol'            => 'GOL',
        'ruang'          => 'RUANG',
        'pangkat'        => 'PANGKAT',
        'jabatan'        => 'JABATAN',
        'tmtsk_jabatan'  => 'TMT JABATAN',
        'satker'         => 'SATUAN KERJA',
        'tmtsk_satker'   => 'TMT UNIT',
        'diklat'         => 'DIKLAT STRUKTUR',
        'strata'         => 'STRATA',
        'jurusan'        => 'JURUSAN',
        'institusi'      => 'INSTITUSI PENDIDIKAN',
        'tgl_lulus'      => 'TGL LULUS',
        'ipk'            => 'IPK',
        'usia_th'        => 'USIA (TH)',
        'usia_bl'        => 'USIA (BL)',
        'tempat_lahir'   => 'TEMPAT LAHIR',
        'tgl_lahir'      => 'TGL LAHIR',
        'tmtsk_cpns'     => 'TMT CPNS',
        'tmtsk_pns'      => 'TMT PNS',
        'agama'          => 'AGAMA',
        'jenis_kelamin'  => 'JENIS KELAMIN',
        'mker_th'        => 'MKER (TH)',
        'mker_bl'        => 'MKER (BL)',
        'kgb_th'         => 'KGB (TH)',
        'kgb_bl'         => 'KGB (BL)',
        'kgb_mker'       => 'KGB (MKER)',
        'tgl_sertifikat' => 'TGL SERTIFIKAT',
        'tanda_jasa'     => 'NAMA TANDA JASA',
        'eselon'         => 'ESELON',
        'tgl_pensiun'    => 'TGL PENSIUN',
        'pensiun_th'     => 'PENSIUN (TH)',
        'pensiun_bl'     => 'PENSIUN (BL)',
    ];

    /**
     * Kolom XLSX Pensiun (urutan legacy `L_document.php:220`, tanpa kolom tersembunyi).
     *
     * @var array<string, string>
     */
    public const KOLOM_PENSIUN = [
        'nama'          => 'NAMA',
        'nip'           => 'NIP',
        'tgl_lahir'     => 'TGL LAHIR',
        'jabatan'       => 'JABATAN',
        'tmtsk_jabatan' => 'TMT JABATAN',
        'satker'        => 'SATKER',
        'eselon'        => 'ESELON',
        'tgl_pensiun'   => 'TGL PENSIUN',
        'pensiun_th'    => 'PENSIUN (TH)',
        'pensiun_bl'    => 'PENSIUN (BL)',
        'usia_th'       => 'USIA (TH)',
        'usia_bl'       => 'USIA (BL)',
    ];

    /**
     * Label PDF Pensiun (CR-066): Title Case seperti DUK dan dipendekkan agar kolom angka sempit tidak memotong kata di
     * tengah. `pensiun_th` = BUP (tahun), `pensiun_bl` = bulan TMT pensiun; XLSX/JSON tetap `KOLOM_PENSIUN` (legacy).
     *
     * @var array<string, string>
     */
    public const KOLOM_PENSIUN_PDF = [
        'nama'          => 'Nama',
        'nip'           => 'NIP',
        'tgl_lahir'     => 'Tgl Lahir',
        'jabatan'       => 'Jabatan',
        'tmtsk_jabatan' => 'TMT Jabatan',
        'satker'        => 'Satker',
        'eselon'        => 'Eselon',
        'tgl_pensiun'   => 'Tgl Pensiun',
        'pensiun_th'    => 'BUP (Th)',
        'pensiun_bl'    => 'Pensiun (Bl)',
        'usia_th'       => 'Usia (Th)',
        'usia_bl'       => 'Usia (Bl)',
    ];

    /**
     * Subset inti PDF (F7-16): key → label; `pangkat_gol`, `unit_satker`, `pendidikan`, `masa_kerja`, `lahir_usia`
     * = kolom gabungan.
     *
     * @var array<string, string>
     */
    public const KOLOM_PDF = [
        'nama'          => 'Nama',
        'nip'           => 'NIP',
        'pangkat_gol'   => 'Pangkat / Gol',
        'tmtsk_pangkat' => 'TMT Pangkat',
        'jabatan'       => 'Jabatan',
        'tmtsk_jabatan' => 'TMT Jabatan',
        'unit_satker'   => 'Unit / Satker',
        'pendidikan'    => 'Pendidikan',
        'tmtsk_cpns'    => 'TMT CPNS',
        'masa_kerja'    => 'Masa Kerja',
        'lahir_usia'    => 'Tgl Lahir (Usia)',
        'diklat'        => 'Diklat Struktural',
        'tgl_pensiun'   => 'Tgl Pensiun',
    ];

    private function __construct()
    {
    }

    /**
     * @return list<string> key kolom XLSX untuk $jenis
     */
    public static function kolomXlsx(string $jenis): array
    {
        return array_keys($jenis === self::PENSIUN ? self::KOLOM_PENSIUN : self::KOLOM_LENGKAP);
    }

    /**
     * @return list<string> label header XLSX untuk $jenis
     */
    public static function headerXlsx(string $jenis): array
    {
        return array_values($jenis === self::PENSIUN ? self::KOLOM_PENSIUN : self::KOLOM_LENGKAP);
    }

    /**
     * @return array<string, string> key → label kolom PDF untuk $jenis
     */
    public static function kolomPdf(string $jenis): array
    {
        return $jenis === self::PENSIUN ? self::KOLOM_PENSIUN_PDF : self::KOLOM_PDF;
    }

    /**
     * Baris dokumen dari baris kueri `KueriDaftarPegawai` (semua nilai string siap tampil; '' bila kosong).
     *
     * @param array<string, mixed> $r
     *
     * @return array<string, string>
     */
    public static function baris(array $r, string $hariIni): array
    {
        $tglLahir = self::tanggal($r['tgl_lahir'] ?? null);
        $usia     = self::selisih($r['tgl_lahir'] ?? null, $hariIni);
        $mker     = self::selisih($r['tmtsk_cpns'] ?? null, $hariIni);
        $kgb      = self::selisih($r['tmtsk_kgb'] ?? null, $hariIni);
        $pensiun  = self::pensiun($r);
        $gelar    = trim(trim((string) ($r['glr_awal'] ?? '')) . ' ' . trim((string) ($r['nama'] ?? '')));
        $nama     = trim((string) ($r['glr_akhir'] ?? '')) !== '' ? $gelar . ', ' . trim((string) $r['glr_akhir']) : $gelar;
        $strata   = (string) ($r['jenjang_pendidikan_singkat'] ?? '');
        $jurusan  = self::lain((string) ($r['jurusan_pendidikan'] ?? ''), (string) ($r['jurusan_pendidikan_lain'] ?? ''));
        $unit     = (string) ($r['unit'] ?? '');
        $satker   = (string) ($r['satker'] ?? '');
        $golRuang = (string) ($r['gol_ruang'] ?? '');
        $pangkat  = (string) ($r['pangkat'] ?? '');

        return [
            'nama'           => $nama,
            'nip'            => (string) ($r['nip'] ?? ''),
            'nip_lama'       => (string) ($r['nip_lama'] ?? ''),
            'gol_ruang'      => $golRuang,
            'tmtsk_pangkat'  => self::tanggal($r['tmtsk_pangkat'] ?? null),
            'gol'            => (string) ($r['gol'] ?? ''),
            'ruang'          => (string) ($r['ruang'] ?? ''),
            'pangkat'        => $pangkat,
            'jabatan'        => (string) ($r['jabatan'] ?? ''),
            'tmtsk_jabatan'  => self::tanggal($r['tmtsk_jabatan'] ?? null),
            'satker'         => $satker,
            'tmtsk_satker'   => self::tanggal($r['tmtsk_jabatan'] ?? null),
            'diklat'         => (string) ($r['nama_diklat'] ?? ''),
            'strata'         => $strata,
            'jurusan'        => $jurusan,
            'institusi'      => (string) ($r['institusi_pendidikan'] ?? ''),
            'tgl_lulus'      => self::tanggal($r['tgl_lulus'] ?? null),
            'ipk'            => str_replace(',', '.', (string) ($r['ipk'] ?? '')),
            'usia_th'        => $usia === null ? '' : (string) $usia['tahun'],
            'usia_bl'        => $usia === null ? '' : (string) $usia['bulan'],
            'tempat_lahir'   => self::lain((string) ($r['kabupaten_kota_lahir'] ?? ''), (string) ($r['kabupaten_kota_lahir_lain'] ?? '')),
            'tgl_lahir'      => $tglLahir,
            'tmtsk_cpns'     => self::tanggal($r['tmtsk_cpns'] ?? null),
            'tmtsk_pns'      => self::tanggal($r['tmtsk_pns'] ?? null),
            'agama'          => (string) ($r['agama'] ?? ''),
            'jenis_kelamin'  => (string) ($r['jenis_kelamin'] ?? '') === '1' ? 'LAKI-LAKI' : 'PEREMPUAN',
            'mker_th'        => $mker === null ? '' : (string) $mker['tahun'],
            'mker_bl'        => $mker === null ? '' : (string) $mker['bulan'],
            'kgb_th'         => $kgb === null ? '' : (string) $kgb['tahun'],
            'kgb_bl'         => $kgb === null ? '' : (string) $kgb['bulan'],
            'kgb_mker'       => $kgb === null ? '' : MasaKerja::format($kgb['tahun'], $kgb['bulan']),
            'tgl_sertifikat' => self::tanggal($r['tgl_sertifikat'] ?? null),
            'tanda_jasa'     => (string) ($r['tanda_jasa'] ?? ''),
            'eselon'         => self::eselon($r['id_group_jabatan'] ?? null, $r['id_sub_group_jabatan'] ?? null),
            'tgl_pensiun'    => $pensiun === null ? '' : self::tanggal($pensiun['tmt']),
            'pensiun_th'     => $pensiun === null ? '' : (string) $pensiun['bup'],
            'pensiun_bl'     => $pensiun === null ? '' : (string) (int) substr($pensiun['tmt'], 5, 2),
            // Kolom gabungan PDF.
            'pangkat_gol' => trim($pangkat . ($golRuang !== '' ? " ({$golRuang})" : '')),
            'unit_satker' => trim($unit . ($satker !== '' && $satker !== $unit ? " / {$satker}" : '')),
            'pendidikan'  => trim($strata . ($jurusan !== '' ? " {$jurusan}" : '')),
            'masa_kerja'  => $mker === null ? '' : MasaKerja::format($mker['tahun'], $mker['bulan']),
            'lahir_usia'  => $tglLahir . ($usia === null ? '' : " ({$usia['tahun']} th)"),
        ];
    }

    /**
     * BUP dan TMT pensiun PNS dengan jabatan aktif (F7-20; `Pensiun::batasUsia`/`tanggalPensiun`); null bila bukan PNS,
     * tanpa jabatan aktif, atau tanggal lahir tidak valid.
     *
     * @param array<string, mixed> $r
     *
     * @return array{bup: int, tmt: string}|null
     */
    public static function pensiun(array $r): ?array
    {
        if ((string) ($r['id_jenis_pegawai'] ?? '') !== '1' || ! isset($r['id_jabatan']) || ! TanggalBisnis::valid((string) ($r['tgl_lahir'] ?? ''))) {
            return null;
        }

        $bup = Pensiun::batasUsia(
            isset($r['umur_pensiun']) ? (int) $r['umur_pensiun'] : null,
            isset($r['id_group_jabatan']) ? (int) $r['id_group_jabatan'] : null,
            isset($r['id_sub_group_jabatan']) ? (int) $r['id_sub_group_jabatan'] : null,
        );

        return ['bup' => $bup, 'tmt' => Pensiun::tanggalPensiun((string) $r['tgl_lahir'], $bup)];
    }

    /**
     * Eselon E.I–E.IV dari sub group 1–4 pada group struktural (1); selain itu ''.
     */
    public static function eselon(mixed $idGroup, mixed $idSub): string
    {
        if ((string) $idGroup !== '1') {
            return '';
        }

        return ['1' => 'E.I', '2' => 'E.II', '3' => 'E.III', '4' => 'E.IV'][(string) $idSub] ?? '';
    }

    /**
     * Tanggal `d M Y` [K `dateToUser`]; '' bila kosong/tidak valid.
     */
    public static function tanggal(mixed $tanggal): string
    {
        $t = (string) ($tanggal ?? '');

        return TanggalBisnis::valid($t) ? date('d M Y', (int) strtotime($t)) : '';
    }

    /**
     * @return array{tahun: int, bulan: int}|null
     */
    private static function selisih(mixed $dari, string $sampai): ?array
    {
        $d = (string) ($dari ?? '');

        if (! TanggalBisnis::valid($d) || $d > $sampai) {
            return null;
        }

        return MasaKerja::selisih($d, $sampai);
    }

    private static function lain(string $nilai, string $lain): string
    {
        return strtoupper(trim($nilai)) === 'LAIN-LAIN' && trim($lain) !== '' ? trim($lain) : trim($nilai);
    }
}
