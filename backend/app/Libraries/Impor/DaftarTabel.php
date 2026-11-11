<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

use App\Libraries\MasterData\WebConfigImport;
use CodeIgniter\Database\BaseConnection;
use Config\WebConfig;

/**
 * Daftar tabel jalur Tukin per tier, urut induk → anak (FK G-02 DBV-019 aktif sesudah `migrate`). Dipanggil sekali per
 * tier per proses impor, jadi aturan khusus yang menyimpan keadaan (dedupe) mulai bersih setiap kali.
 */
final class DaftarTabel
{
    public const TIER_MAKS = 15;

    /**
     * Tier dengan satu transaksi per tabel + berkas jejak (DBV-029 K-M8): riwayat non-Tukin dan snapshot.
     *
     * @var list<int>
     */
    public const TIER_PER_TABEL = [10, 11, 14];

    /**
     * `siasn_referensi.jenis` → tabel legacy `siasn_simpeg` + kolom pemetaan lokal (null = dicari lewat `NAMA`), DBV-027 §3.6.
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    private const REFERENSI_SIASN = [
        'jabatan_fungsional'      => ['JABATAN_FUNGSIONAL_ID', null],
        'jabatan_fungsional_umum' => ['JABATAN_FUNGSIONAL_UMUM_ID', null],
        'jenis_jabatan'           => ['JENIS_JABATAN_ID', 'id_group_jabatan'],
        'latihan_struktural'      => ['LATIHAN_STRUKTURAL_ID', 'id_diklat'],
        'tk_pendidikan'           => ['TK_PENDIDIKAN', 'id_jenjang_pendidikan'],
        'jenis_kp'                => ['JENIS_KP_ID', 'id_jenis_kp'],
        'golongan'                => ['GOLONGAN_ID', 'id_pangkat'],
    ];

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
            7       => self::tier7(),
            8       => self::tier8(),
            9       => self::tier9(),
            10      => self::tier10(),
            11      => self::tier11(),
            12      => self::tier12(),
            13      => self::tierTiketLama(),
            14      => self::tierKonten(),
            15      => self::tierSiasn(),
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
            new DefinisiTabel('unit', 'id_unit', $g02, kolomNama: ['unit'], khusus: self::unit(...)),
            new DefinisiTabel('satker', 'id_satker', $g02, kolomNama: ['satker'], domain: ['is_upt' => [0, 1]]),
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
            // GAP-8 (DBV 30-10): terisi dari sinkron BKN, bukan form → tanpa stripslashes. GAP-9: waktu_dinilai dari BKN, disalin apa adanya.
            new DefinisiTabel('bkn_periode_ekinper', 'id', 'B-01 §3.4.1', tanpaStripslashes: ['*'], statusDomain: null),
            // DBV-027 K-3/K-4: UNIQUE (id_pns, periode_id, skp_id) — kunci ganda diselesaikan sebelum tulis, seperti pembaca Tukin.
            new DefinisiTabel(
                'riwayat_skp_periodik',
                'id_riwayat_skp_periodik',
                'B-01 §6, DBV-027 K-3/K-4',
                tanpaStripslashes: ['*'],
                statusDomain: [0, 1, 2, 10],
                kolomKode: ['nip'],
                waktuBisnis: ['waktu_dinilai'],
                praPindai: AturanSiasn::skpPeriodik(...),
            ),
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
     * T7 — master sisa MAKE-069 (G-01, G-04/05, G-06, G-07/08, G-02b, G-10, DBV-020; DBV-029 K-M9). ID legacy apa adanya;
     * ID hard-code aturan v2 (`bidang_pendidikan` 98, `jurusan_pendidikan` 1185, `jenis_konket` 2/4/5/6, `tanda_jasa`
     * 26/27/28) wajib ada di sumber. Kolom `order` [V2] yang tidak ada di legacy = urut nama per lingkup.
     *
     * @return list<DefinisiTabel>
     */
    private static function tier7(): array
    {
        $nama = static fn (string $kolom, array $lingkup = []): array => ['kolom' => 'order', 'nama' => $kolom, 'lingkup' => $lingkup];

        return [
            new DefinisiTabel('kecamatan', 'id_kecamatan', 'G-01 §8, DBV-011 §2.2 #7', kolomNama: ['kecamatan'], bolehSeed: true, kolomKode: ['id_kecamatan', 'id_kabupaten_kota'], isiUrutan: $nama('kecamatan', ['id_kabupaten_kota'])),
            new DefinisiTabel('kelurahan', 'id_kelurahan', 'G-01 §8, DBV-011 §2.2 #7', kolomNama: ['kelurahan'], bolehSeed: true, kolomKode: ['id_kelurahan', 'id_kecamatan'], isiUrutan: $nama('kelurahan', ['id_kecamatan'])),
            new DefinisiTabel('kantor', 'id_kantor', 'G-07/G-08 F10', kolomNama: ['nama_kantor']),
            new DefinisiTabel('bidang_kursem', 'id_bidang_kursem', 'G-07/G-08 F9', kolomNama: ['bidang_kursem'], isiUrutan: $nama('bidang_kursem')),
            new DefinisiTabel('instansi_kursem', 'id_instansi_kursem', 'G-07/G-08 F9', kolomNama: ['instansi_kursem'], isiUrutan: $nama('instansi_kursem')),
            new DefinisiTabel('bidang_pendidikan', 'id_bidang_pendidikan', 'G-04/G-05 P8/P12', kolomNama: ['bidang_pendidikan'], praPindai: AturanTahap2::wajibAda('id_bidang_pendidikan', [98]), isiUrutan: $nama('bidang_pendidikan')),
            new DefinisiTabel('jurusan_pendidikan', 'id_jurusan_pendidikan', 'G-04/G-05 P8/P12', kolomNama: ['jurusan_pendidikan'], praPindai: AturanTahap2::wajibAda('id_jurusan_pendidikan', [1185]), isiUrutan: $nama('jurusan_pendidikan', ['id_bidang_pendidikan'])),
            new DefinisiTabel('diklat', 'id_diklat', 'G-06 B8', kolomNama: ['nama_diklat'], domain: ['jenis_diklat' => [1, 2, 3, 4, 5]]),
            new DefinisiTabel('tingkat_hukdis', 'id_tingkat_hukdis', 'G-06 B8', kolomNama: ['tingkat_hukdis']),
            new DefinisiTabel('jenis_hukdis', 'id_jenis_hukdis', 'G-06 B8', kolomNama: ['jenis_hukdis']),
            new DefinisiTabel(
                'jenis_konket',
                'id_jenis_konket',
                'G-06 B8, DBV-029 K-M9a',
                kolomNama: ['jenis_konket'],
                khusus: AturanTahap2::jenisKonket(...),
                domain: ['affect_tukin' => [1, 2]],
                praPindai: static fn (BaseConnection $db, string $t, Keputusan $k): array => (AturanTahap2::wajibAda('id_jenis_konket', [2, 4, 5, 6]))($db, $t, $k) + AturanTahap2::oldIdTidakSah($db, $t, $k),
                pascaImpor: AturanTahap2::auditKategoriKonket(...),
            ),
            new DefinisiTabel('tanda_jasa', 'id_tanda_jasa', 'G-06 B8', kolomNama: ['tanda_jasa'], praPindai: AturanTahap2::wajibAda('id_tanda_jasa', [26, 27, 28])),
            new DefinisiTabel('subrumpun_jabatan', 'id_subrumpun_jabatan', 'G-02b §6.5', kolomNama: ['subrumpun_jabatan']),
            new DefinisiTabel('jabatan_akademik', 'id_jabatan_akademik', 'G-02b §6.5', kolomNama: ['jabatan_akademik'], domain: ['is_atasan' => [1, 2]]),
            new DefinisiTabel('peta_jabatan', 'id_peta_jabatan', 'G-02b §6.5'),
            new DefinisiTabel('rumpun_sertifikasi', 'id_rumpun_sertifikasi', 'DBV-020 (1/3)', statusDomain: null),
            new DefinisiTabel('lembaga_sertifikasi', 'id_lembaga_sertifikasi', 'DBV-020 (1/3)', statusDomain: null),
            new DefinisiTabel('dm_ak_jf', 'id_jabatan', 'DBV-020 (2/3)', statusDomain: null, kunciUrut: ['id_jabatan', 'id_pangkat']),
            new DefinisiTabel('faq_topic', 'id_faq_topic', 'G-10', kolomNama: ['faq_topic']),
            new DefinisiTabel('faq_sub_topic', 'id_faq_sub_topic', 'G-10', kolomNama: ['faq_sub_topic']),
            new DefinisiTabel(
                'faq_article',
                'id_faq_article',
                'G-10 D4, DBV-029 K-M9c',
                kolomNama: ['title'],
                khusus: static fn (array $b): array => AturanTahap2::faqArticle($b),
                isiUrutan: $nama('title', ['id_faq_sub_topic']),
            ),
            new DefinisiTabel('faq_related_article', 'id_article_main', 'G-10', statusDomain: null, kunciUrut: ['id_article_main', 'id_article_related']),
            new DefinisiTabel('jenis_rwy', 'id_jenis_rwy', 'B-01/B-02 §3.5.9 (seed)', kolomNama: ['jenis_rwy'], bolehSeed: true, opsional: true, hanyaSeed: true),
        ];
    }

    /**
     * T8 — akun (A-01, DBV-010 §9.4/9.5, rencana MAKE-069 T8). `id_pengguna` = `pengguna.id` legacy; `password_decode`
     * tidak pernah di-SELECT; hash md5 legacy → `password_legacy` (lazy rehash). Tujuan wajib kosong, termasuk dari akun
     * sistem integrasi (Z-1: command akun sistem dijalankan SESUDAH tier ini).
     *
     * @return list<DefinisiTabel>
     */
    private static function tier8(): array
    {
        return [
            new DefinisiTabel(
                'pengguna',
                'id_pengguna',
                'A-01 §2, DBV-010 §9, DBV-029 K-M10',
                statusDomain: ['0', '1'],
                khusus: static fn (array $b): array => AturanTahap2::pengguna($b),
                kolomKode: ['nip'],
                petaKolom: ['id_pengguna' => 'id', 'nip' => 'id_pegawai', 'user_level' => 'UserLevel', 'password_legacy' => 'password'],
                konstanta: ['password' => null, 'last_login_at' => null, 'password_changed_at' => null],
                praPindai: AturanTahap2::penggunaValid(...),
                kolomDilarang: ['password_decode'],
                rujukan: ['nip' => ['pegawai', 'nip']],
            ),
        ];
    }

    /**
     * T9 — pegawai lanjutan: pengajuan biodata pending (K-M9b), foto, aturan lokasi presensi (K-M6, sesudah
     * `lokasi_presensi` T5).
     *
     * @return list<DefinisiTabel>
     */
    private static function tier9(): array
    {
        return [
            new DefinisiTabel(
                'pegawai_hist',
                'id_pegawai_hist',
                'B-01/B-02 §6 #1, DBV-029 K-M9b',
                domain: ['flag_update' => [0, 1, 2, 3], 'jenis_kelamin' => [1, 2]],
                kolomKode: ['nip'],
                praPindai: AturanTahap2::pegawaiHistTerbaru(...),
                pascaImpor: AturanTahap2::rekonsiliasiPegawaiHist(...),
                saringSumber: 'flag_update = 1',
            ),
            new DefinisiTabel('pegawai_foto', 'id_pegawai_foto', 'B-01 §3.1.3', statusDomain: null, kolomKode: ['nip']),
            new DefinisiTabel(
                'dm_user_lokasi_presensi',
                'id_dm_user_lokasi_presensi',
                'G-03 §3, DBV-029 K-M6',
                statusDomain: null,
                konstanta: ['status' => 1],
                praPindai: AturanTahap2::targetLokasiSah(...),
                pascaImpor: AturanTahap2::rujukanLokasi(...),
            ),
        ];
    }

    /**
     * T10 — riwayat non-Tukin (B-01/B-02 §3.3–3.5, §6). Satu transaksi per tabel + berkas jejak (K-M8).
     * `document_attachment` paling akhir (FK `jenis_rwy`; dirujuk `siasn_upload_arsip`).
     *
     * @return list<DefinisiTabel>
     */
    private static function tier10(): array
    {
        $r = 'B-01/B-02 §6, DBV-029';

        return [
            new DefinisiTabel('riwayat_kgb', 'id_riwayat_kgb', $r, statusDomain: [0, 1, 2, 3, 10], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_pendidikan', 'id_riwayat_pendidikan', $r, statusDomain: [0, 1, 2, 3, 10], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_diklat', 'id_riwayat_diklat', $r, statusDomain: [0, 1, 2, 3, 10], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_seminar', 'id_riwayat_seminar', $r, statusDomain: [0, 1, 2, 3, 10], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_skp', 'id_riwayat_skp', $r, statusDomain: [0, 1, 2, 3, 10], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_lckh', 'id_riwayat_lckh', $r, statusDomain: [0, 1, 2, 3, 10], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_hukdis', 'id_riwayat_hukdis', $r, statusDomain: [0, 1, 2, 3, 10], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_ak', 'id_riwayat_ak', $r, statusDomain: [0, 1, 2, 10], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_ak_siasn', 'id_riwayat_ak_siasn', $r, statusDomain: [0, 1, 2, 10], kolomKode: ['nip']),
            new DefinisiTabel('konv_ak', 'nip', $r, statusDomain: [0, 1, 2, 10], kolomKode: ['nip']),
            new DefinisiTabel('ignore_konv_ak', 'nip', $r, statusDomain: null, kolomKode: ['nip'], kunciUrut: ['nip', 'ignore_date']),
            new DefinisiTabel('riwayat_alamat', 'id_riwayat_alamat', $r, statusDomain: [0, 1, 2, 10], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_karpeg', 'id_riwayat_karpeg', $r, statusDomain: [0, 1, 2, 10], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_kariskarsu', 'id_riwayat_kariskarsu', $r, statusDomain: [0, 1, 2, 10], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_tanda_jasa', 'id_riwayat_tanda_jasa', $r, statusDomain: [0, 1, 2, 3, 10], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_organisasi', 'id_riwayat_organisasi', $r, statusDomain: [0, 1, 2, 3, 10], kolomKode: ['nip']),
            new DefinisiTabel('document_attachment', 'id_attachment', 'B-01/B-02 §3.5.8, DBV-029 §5.2', statusDomain: null, kolomKode: ['NIP']),
        ];
    }

    /**
     * T11 — snapshot `pegawai_*` disalin apa adanya (B-01/B-02 §6 #1, #6). `id_riwayat_*` yatim: kolom NULL-able → NULL +
     * dicatat; NOT NULL (`pegawai_cpns`, `pegawai_alamat`, `pegawai_alamat_kantor`) → gagal. Transaksi per tabel (K-M8).
     *
     * @return list<DefinisiTabel>
     */
    private static function tier11(): array
    {
        $r    = 'B-01/B-02 §3.2, §6 #6';
        $snap = static fn (string $tabel, array $yatim = []): DefinisiTabel => new DefinisiTabel($tabel, 'nip', $r, statusDomain: null, kolomKode: ['nip'], yatimKeNull: $yatim);

        return [
            $snap('pegawai_cpns'),
            $snap('pegawai_pns', ['id_riwayat_kp']),
            $snap('pegawai_kgb', ['id_riwayat_kgb']),
            $snap('pegawai_pendidikan', ['id_riwayat_pendidikan']),
            $snap('pegawai_diklat', ['id_riwayat_diklat']),
            $snap('pegawai_hukdis', ['id_riwayat_hukdis']),
            $snap('pegawai_ak', ['id_riwayat_ak']),
            $snap('pegawai_ak_siasn', ['id_riwayat_ak_siasn']),
            $snap('pegawai_keluarga', ['id_riwayat_keluarga']),
            $snap('pegawai_alamat'),
            $snap('pegawai_alamat_kantor'),
            $snap('pegawai_tanda_jasa', ['id_riwayat_tanda_jasa']),
        ];
    }

    /**
     * T12 — layanan sisa (DBV-022 §5): master layanan → petugas/aturan approval → notifikasi KT cuti → IB → PMK → TTE
     * (`esign_hist` utuh, bukti legal). `question_category` di sini karena dirujuk master Halo Simpeg (T12b, DBV-028).
     *
     * @return list<DefinisiTabel>
     */
    private static function tier12(): array
    {
        $r = 'DBV-022 §5';

        return [
            new DefinisiTabel('jenis_layanan', 'id_jenis_layanan', $r . ' (seed migration)', kolomNama: ['jenis_layanan'], bolehSeed: true),
            new DefinisiTabel('jenis_pa', 'id_jenis_pa', $r . ' (seed migration)', kolomNama: ['jenis_pa'], bolehSeed: true),
            new DefinisiTabel('question_category', 'id_question_category', $r, statusDomain: [0, 1]),
            new DefinisiTabel('petugas_layanan', 'id_petugas_layanan', $r . ' (DQ-12/DQ-27)', statusDomain: [0, 1, 10], kolomKode: ['nip']),
            new DefinisiTabel('pa_layanan', 'id_pa_layanan', $r),
            new DefinisiTabel('riwayat_cuti_notif_kt', 'id_riwayat_cuti', $r, statusDomain: [0, 1, 2], kolomKode: ['nip_kt'], kunciUrut: ['id_riwayat_cuti', 'nip_kt']),
            new DefinisiTabel(
                'riwayat_ib',
                'id_riwayat_ib',
                $r,
                statusDomain: [0, 1, 2, 3],
                domain: ['status_admin_upt' => [0, 1, 2], 'status_admin_deputi' => [0, 1, 2], 'rated' => [0, 1, 2]],
                kolomKode: ['nip'],
            ),
            new DefinisiTabel('riwayat_pmk_req', 'id_riwayat_pmk_req', $r, statusDomain: [0, 1, 2, 3], kolomKode: ['nip']),
            new DefinisiTabel('riwayat_pmk', 'id_riwayat_pmk', $r, statusDomain: [1, 2], domain: ['siasn_flag' => [0, 1]], kolomKode: ['nip']),
            new DefinisiTabel('esign_hist', 'id_esign_hist', $r . ' (append-only)', statusDomain: ['1', '2'], kolomKode: ['nip']),
        ];
    }

    /**
     * T13 — tiket lama Halo Simpeg, hasil sortir F-02 (Fase 7 MAKE-086, PRD Fase 7 v2.0 §5.2, Q7-28; MAKE-069 rencana "T13",
     * PRD Fase 8 PN8-2). SELURUH `inbox`/`inbox_msg` legacy = tiket (penulis tunggal `L_support.php`) → `disiplin_ticket(_msg)`
     * rename 1:1 (DBV-022 §2.10/§2.11, PK `id_inbox` tetap); `inbox` v2 (notifikasi) TIDAK ditulis (DBV-022 §3.4).
     * Kelas & eskalasi: {@see AturanTiketLama}. Prasyarat: T8 `pengguna`, T12 `question_category` (FK RESTRICT; kategori
     * yatim → gagal karena legacy FK SET NULL tidak menyisakan yatim — yatim berarti T12 belum dijalankan).
     * `sender`/`receiver`/`closed_by`/`msg_*` tanpa FK (sentinel 0 = semua petugas, DQ-4/DQ-6): disalin, akun tidak ada →
     * INFO. Pesan ber-`addslashes` legacy (`LS:1576`) → `stripslashes` bawaan (R8-5).
     *
     * @return list<DefinisiTabel>
     */
    private static function tierTiketLama(): array
    {
        $r      = 'F-02 MAKE-086, DBV-022 §2.10/§2.11';
        $aturan = new AturanTiketLama();

        return [
            new DefinisiTabel(
                'disiplin_ticket',
                'id_inbox',
                $r,
                statusDomain: AturanTiketLama::STATUS_TIKET,
                domain: AturanTiketLama::DOMAIN_TIKET,
                kolomKode: ['nip'],
                sumber: 'inbox',
                praPindai: $aturan->tiket(...),
                pascaImpor: $aturan->infoTiket(...),
            ),
            new DefinisiTabel(
                'disiplin_ticket_msg',
                'id_inbox_msg',
                $r,
                statusDomain: null,
                domain: AturanTiketLama::DOMAIN_PESAN,
                sumber: 'inbox_msg',
                praPindai: $aturan->pesan(...),
                pascaImpor: $aturan->infoPesan(...),
            ),
        ];
    }

    /**
     * T14 — konten & interaksi Fase 7 (MAKE-089; DBV-032 §10, keputusan reviewer Q-089-1..6 11-11-2026). Transaksi per tabel
     * + `--jejak` (volume `user_popup`/`user_banner`). Urutan induk → anak. Kolom legacy `target`/`type`/`id_unit_satker`/
     * `unit_satker`/`*_desc`/`news.category`/`news.unit_satker_target` tidak ada di v2 sehingga tidak disalin (Q7-03/Q7-47/Q2).
     * Aturan per baris & berkas: {@see AturanKonten}. ENUM legacy (`news.status`/`target`, `news_category.allow_ap`) dibaca
     * sebagai NILAI string lalu dicek domain (tanpa CAST/+0). Popup `tgl_*` DATETIME = waktu bisnis WIB (Jalur A), tidak
     * dikonversi; stempel lain (termasuk `news.publish_at`) R1a WIB → UTC; DATE apa adanya.
     * M1: tanpa form digital (konverter SkemaForm Jalur B menyusul M2).
     *
     * @return list<DefinisiTabel>
     */
    private static function tierKonten(): array
    {
        $r = 'MAKE-089, DBV-032 §10';

        return [
            new DefinisiTabel('news_category', 'id_news_category', $r, statusDomain: [0, 1], domain: ['allow_ap' => [1, 2]], khusus: AturanKonten::khususKategoriBerita()),
            new DefinisiTabel(
                'news',
                'id_news',
                $r,
                statusDomain: [0, 1, 2],
                domain: ['target' => [1, 2, 3], 'show_notif' => [0, 1, 2, 3]],
                khusus: AturanKonten::khususBerita(),
                praPindai: AturanKonten::praPindaiBerkas('news', 'id_news', ['lampiran' => \App\Libraries\Dashboard\Konten\Berita\PenyimpananLampiran::EKSTENSI]),
            ),
            new DefinisiTabel('news_flag', 'id_news_flag', $r, statusDomain: null, kunciUrut: ['id_user', 'id_news'], pkSintetis: true),
            new DefinisiTabel('jenis_dibo', 'id_jenis_dibo', $r . ' (seed T4)', kolomNama: ['jenis_dibo'], statusDomain: [1, 2], bolehSeed: true, hanyaSeed: true),
            new DefinisiTabel(
                'dibo',
                'id_dibo',
                $r,
                statusDomain: [1, 2],
                khusus: AturanKonten::khususDibo(),
                praPindai: AturanKonten::praPindaiBerkas('dibo', 'id_dibo', array_fill_keys(['file_1', 'file_2', 'file_3'], \App\Libraries\Dashboard\Konten\Dibo\PenyimpananDibo::EKSTENSI)),
            ),
            new DefinisiTabel(
                'popup',
                'id_popup',
                $r,
                statusDomain: null,
                waktuBisnis: ['tgl_mulai', 'tgl_akhir'],
                khusus: AturanKonten::khususPengumuman('popup', 'id_popup', 'popup'),
                praPindai: AturanKonten::praPindaiPengumuman('popup', 'id_popup', 'popup', true),
            ),
            new DefinisiTabel(
                'user_popup',
                'id_user_popup',
                $r,
                statusDomain: null,
                kolomKode: ['nip'],
                kunciUrut: ['id_popup', 'nip'],
                praPindai: AturanKonten::praPindaiAnak('popup', 'id_popup', 'popup', true),
                pkSintetis: true,
            ),
            new DefinisiTabel(
                'banner',
                'id_banner',
                $r,
                statusDomain: null,
                khusus: AturanKonten::khususPengumuman('banner', 'id_banner', 'banner'),
                praPindai: AturanKonten::praPindaiPengumuman('banner', 'id_banner', 'banner', false),
            ),
            new DefinisiTabel(
                'user_banner',
                'id_user_banner',
                $r,
                statusDomain: null,
                kolomKode: ['nip'],
                kunciUrut: ['id_banner', 'nip'],
                praPindai: AturanKonten::praPindaiAnak('banner', 'id_banner', 'banner', false),
                pkSintetis: true,
            ),
            new DefinisiTabel(
                'skl',
                'id_skl',
                $r . ' (kritik_saran data pribadi, N3)',
                statusDomain: null,
                domain: ['jenis_rwy' => [1, 2, 3, 4, 5], 'rate' => [1, 2, 3, 4, 5]],
                kolomKode: ['nip'],
                praPindai: AturanKonten::praPindaiSkl(...),
            ),
        ];
    }

    /**
     * T15 — tabel kerja SIASN Fase 6 (DBV-027 §13.3), selalu tier TERAKHIR (`TIER_MAKS`, dikunci test). Rujukan
     * `id_rw`/`id_da` menunjuk `riwayat_mutasi_jabatan` (T3), `riwayat_diklat` dan `document_attachment` (belum ada di
     * T10 MAKE-069). Bila dijalankan sebelum induknya terisi, INFO rujukan menyebut induk belum diimpor. `siasn_ref_unor` dan
     * `siasn_referensi` dibaca dari skema `--sumber-siasn` (K-9); isinya dari sinkron BKN dan pemetaan lokal, jadi disalin
     * mentah (pola GAP-8). `siasn_status_pegawai` (padanan `dsp`, K-5) dan `firebase_token` (AC-01.2) tidak diimpor.
     *
     * @return list<DefinisiTabel>
     */
    private static function tierSiasn(): array
    {
        $defs = [
            new DefinisiTabel('siasn_ref_unor', 'id', 'DBV-027 §3.5, K-9', tanpaStripslashes: ['*'], statusDomain: null, sumber: 'REF_UNOR', skemaSiasn: true),
        ];

        foreach (self::REFERENSI_SIASN as $jenis => [$tabel, $lokal]) {
            $defs[] = new DefinisiTabel(
                'siasn_referensi',
                'id_siasn',
                'DBV-027 §3.6, K-9',
                tanpaStripslashes: ['*'],
                statusDomain: null,
                sumber: $tabel,
                skemaSiasn: true,
                petaKolom: ['id_siasn' => 'ID', 'nama' => 'NAMA'] + ($lokal === null ? [] : ['id_lokal' => $lokal]),
                konstanta: ['jenis' => $jenis],
            );
        }

        $defs[] = new DefinisiTabel('double_riwayat_kp', 'nip', 'DBV-027 §3.4, K-8', statusDomain: null, kolomKode: ['nip']);
        $defs[] = new DefinisiTabel(
            'siasn_upload_arsip',
            'id_siasn_upload_arsip',
            'DBV-027 §3.3, K-6/K-7',
            // Respons BKN & pesan galat ditulis mentah oleh legacy (SS/Arsip :73-112); arsip_path/arsip_name ikut
            // stripslashes bawaan agar sama dengan document_attachment.path hasil impor (DBV-029).
            tanpaStripslashes: ['resp_dok_id', 'resp_dok_nama', 'resp_dok_uri', 'resp_object', 'resp_slug', 'error_msg'],
            statusDomain: [0, 1, 2, 3],
            kolomKode: ['nip'],
            khusus: static fn (array $b): array => AturanSiasn::terhentiSaatCutover($b),
            praPindai: AturanSiasn::uploadArsip(...),
            pascaImpor: AturanSiasn::rujukanArsip(...),
        );

        return $defs;
    }

    /**
     * `unit.is_upt` 2 ("TIDAK" dari form legacy `unit/form.php:44`) → 0 = G-02 Bagian 4 #9, dicatat di laporan. Nilai
     * lain di luar 0/1 (CHECK `chk_unit_is_upt`) → berkas keputusan, kalau tidak gagal — juga saat dry-run (CR-089).
     *
     * @param array<string, mixed> $baris
     *
     * @return array<string, mixed>
     */
    private static function unit(array $baris, Keputusan $keputusan): array
    {
        if (! array_key_exists('is_upt', $baris)) {
            return $baris;
        }

        if (Transformasi::kunciStatus($baris['is_upt']) === '2') {
            $baris['is_upt']    = 0;
            $baris['__koreksi'] = ['G-02 #9 is_upt 2→0'];
        }

        $baris['is_upt'] = $keputusan->domain('unit', 'is_upt', $baris['is_upt'], [0, 1]);

        return $baris;
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
