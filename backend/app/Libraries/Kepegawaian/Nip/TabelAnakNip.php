<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Nip;

use InvalidArgumentException;

/**
 * CR-036 (B-06, B-21) — registry statis kolom yang merujuk NIP pegawai, landasan koreksi NIP (B-06).
 *
 * PK `pegawai` = `nip` (K1) dan FK v2 `ON UPDATE RESTRICT`, sehingga koreksi NIP dijalankan aplikasi dalam satu
 * transaksi: salin baris pegawai ke NIP baru → arahkan ulang setiap kolom di registry ini → hapus baris lama. Legacy
 * cukup `UPDATE pegawai SET nip` karena FK-nya `ON UPDATE CASCADE` (`libraries/hr/L_employee.php:586-587`).
 *
 * Isi (79 entri):
 * - {@see JenisRujukanNip::FkLegacy} (75): semua `REFERENCES pegawai (nip)` di dump struktur produksi 01-10-2026 (D1,
 *   285 tabel), 70 tabel. Semuanya `ON UPDATE CASCADE`; nama FK legacy dicatat.
 * - {@see JenisRujukanNip::NonFkUbahNipLegacy} (1): `pengguna.username`, diganti kode Ubah NIP legacy.
 * - {@see JenisRujukanNip::NonFkSlipGaji} (1): `gaji_pegawai.nip`.
 * - {@see JenisRujukanNip::NonFkFkV2} (2): `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui`, tanpa FK di legacy
 *   tetapi wajib FK di v2 (DoD C-01).
 *
 * Label sumber: **[K]** = DDL dump struktur produksi D1; **[K-kode]** = kode legacy (path relatif ke `application/`);
 * **[V2]** = dokumen v2. Fase/pemilik diambil dari `0x-*.md`, Mapping Migrasi, dan draf DBV-012/DBV-013 (belum
 * disetujui DB Validator). Kolom NIP tanpa FK lain yang tidak diganti legacy tidak masuk registry; daftarnya di README
 * modul (keputusan B-06).
 *
 * Kecocokan registry dengan skema nyata (`information_schema.KEY_COLUMN_USAGE`) diuji test ber-DB B-21 setelah tabel
 * B-01/B-02 ada; test unit di sini hanya memeriksa kelengkapan data.
 */
final class TabelAnakNip
{
    public const FASE_MIN = 0;
    public const FASE_MAX = 8;

    /**
     * FK legacy → `pegawai (nip)`: [tabel, kolom, nama FK legacy, nullable, keberadaan, fase, pemilik, catatan].
     */
    private const FK_LEGACY = [
        ['absen_ijin', 'nip', 'fk_nip_abijin_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Konket/izin (B-13), draf DBV-013'],
        ['dh_online', 'nip', 'fk_nip_dh_to_pegawai', false, KeberadaanV2::Ada, 5, 'D-01', 'Presensi harian'],
        ['document_attachment', 'NIP', 'fk_NIP_da_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Lampiran riwayat (B-18); nama kolom huruf besar dipertahankan draf DBV-013'],
        ['email_pool', 'nip', 'fk_nip_emailpool_to_pegawai', true, KeberadaanV2::BelumDiputuskan, null, null, 'Antrean email legacy (Cron); tidak ada di Mapping Migrasi; antrean email v2 = Queue CR-014'],
        ['email_retry', 'nip', 'fk_nip_emailretry_to_pegawai', true, KeberadaanV2::BelumDiputuskan, null, null, 'Antrean ulang email legacy, tidak dipakai kode; tidak ada di Mapping Migrasi'],
        ['email_sent', 'nip', 'fk_nip_emailsent_to_pegawai', true, KeberadaanV2::BelumDiputuskan, null, null, 'Arsip email terkirim legacy (Cron); tidak ada di Mapping Migrasi'],
        ['esign_hist', 'nip', 'fk_esign_hist_ibfk01', true, KeberadaanV2::Ada, 6, 'H-01', 'Bukti legal tanda tangan elektronik (Mapping Tier 6)'],
        ['faq_rate', 'nip', 'fk_nip_faqrate_to_peg', false, KeberadaanV2::Ada, 2, 'G-10', 'Sudah di main tanpa FK; FK menunggu migration 120200 yang ditahan (draf DBV-012)'],
        ['filled_form_digital', 'nip', 'fk_nip_ffd_to_pegawai', false, KeberadaanV2::Ada, 7, 'F-01', 'Isian form digital'],
        ['ignore_konv_ak', 'nip', 'fk_nip_ignorekonvak_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Angka kredit (B-15); nip bagian PK (draf DBV-013)'],
        ['konv_ak', 'nip', 'fk_nip_konvak_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Angka kredit (B-15), draf DBV-013'],
        ['layanan_pegawai', 'nip', 'fk_nip_laypeg_to_pegawai', false, KeberadaanV2::BelumDiputuskan, null, null, 'Dipakai L_employee/L_notification legacy; tidak ada di Mapping Migrasi maupun C-01'],
        ['login_mysapk', 'nip', 'fk_nip_loginsapk_to_pegawai', false, KeberadaanV2::BelumDiputuskan, null, null, 'Log login MySAPK (controllers/User.php); tidak ada di Mapping Migrasi'],
        ['pegawai_ak', 'nip', 'fk_nip_cak_to_peg', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pegawai_ak_siasn', 'nip', 'fk_nip_peg_ak_siasn_01', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot riwayat_ak_siasn yang diisi trigger; masuk B-01 di revisi D1 draf DBV-012 (keputusan 8 #6, belum disetujui)'],
        ['pegawai_alamat', 'nip', 'fk_nip_pegalamat_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pegawai_alamat_kantor', 'nip', 'pegawai_alamat_kantor_ibfk_6', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pegawai_cpns', 'nip', 'fk_nip_pcpns_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pegawai_diklat', 'nip', 'fk_nip_pegdiklat_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pegawai_foto', 'nip', 'fk_nip_pegfoto_to_peg', false, KeberadaanV2::Ada, 3, 'B-01', 'Riwayat foto profil, draf DBV-012'],
        ['pegawai_hist', 'nip', 'fk_peg_hist_ibfk_01', false, KeberadaanV2::Ada, 3, 'B-01', 'Draf perubahan biodata (B-03/B-04), draf DBV-012'],
        ['pegawai_hukdis', 'nip', 'fk_nip_peghukdis_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pegawai_keluarga', 'nip', 'fk_nip_pegkeluarga_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pegawai_kgb', 'nip', 'fk_nip_pegkgb_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pegawai_kp', 'nip', 'fk_nip_pegkp_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pegawai_mutasi_jabatan', 'nip', 'fk_nip_pmj_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pegawai_pendidikan', 'nip', 'fk_nip_pegpend_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pegawai_plh', 'nip_plh', 'fk_pegawai_plh_ibfk_01', false, KeberadaanV2::Ada, 3, 'B-02', 'Penugasan Plh (B-07), draf DBV-013'],
        ['pegawai_plt', 'nip_plt', 'fk_nip_plt_plt_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Penugasan Plt (B-07), draf DBV-013'],
        ['pegawai_pns', 'nip', 'fk_nip_ppns_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pegawai_sisa_cuti', 'nip', 'fk_nip_psc_to_pegawai', false, KeberadaanV2::Ada, 4, 'C-01', 'Kuota cuti (C-03); tidak tercantum di daftar file C-01, pemilik menurut draf DBV-012 1.3'],
        ['pegawai_tanda_jasa', 'nip', 'fk_nip_pegtj_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-01', 'Snapshot aktif (ADR-006), draf DBV-012'],
        ['pengguna', 'id_pegawai', 'fk_id_pegawai_pengguna_to_pegawai', true, KeberadaanV2::Ada, 1, 'A-01', 'Di main sebagai pengguna.nip (DBV-010), NULL untuk akun non-pegawai (K2); FK menunggu migration 120200 yang ditahan'],
        ['riwayat_ak', 'nip', 'fk_nip_rak_to_peg', false, KeberadaanV2::Ada, 3, 'B-02', 'Angka kredit (B-15), draf DBV-013'],
        ['riwayat_ak_siasn', 'nip', 'fk_nip_rw_ak_siasn_01', false, KeberadaanV2::Ada, 3, 'B-02', 'Angka kredit SIASN (B-15), draf DBV-013'],
        ['riwayat_alamat', 'nip', 'fk_nip_rwyalamat_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat alamat (B-16), draf DBV-013'],
        ['riwayat_cuti', 'nip', 'fk_nip_rwycuti_to_pegawai', false, KeberadaanV2::Ada, 4, 'C-01', 'Pengajuan cuti'],
        ['riwayat_cuti_notif_kt', 'nip_kt', 'fk_nip_kt_rcnkt_2', false, KeberadaanV2::BelumDiputuskan, null, null, 'Mapping Tier 7: isinya ikut ke inbox/inbox_msg; draf DBV-012 mencatatnya di C-01'],
        ['riwayat_diklat', 'nip', 'fk_nip_rwydiklat_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat diklat (B-11), draf DBV-013'],
        ['riwayat_hukdis', 'nip', 'fk_nip_rwyhukdis_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat hukdis (B-14), draf DBV-013'],
        ['riwayat_ib', 'nip', 'fk_nip_rwyib_to_pegawai', false, KeberadaanV2::Ada, 4, 'C-01', 'Izin belajar'],
        ['riwayat_kariskarsu', 'nip', 'fk_nip_rkariskarsu_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat Karis/Karsu (B-17), draf DBV-013'],
        ['riwayat_karpeg', 'nip', 'fk_nip_rkarpeg_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat Karpeg (B-17), draf DBV-013'],
        ['riwayat_kartu_kariskarsu_20201228', 'nip', 'fk_nip_rwykaris_to_pegawai', false, KeberadaanV2::TidakDiimpor, null, null, 'Tabel cadangan bertanggal (draf DBV-012 1.3)'],
        ['riwayat_kartu_pegawai_20211111', 'nip', 'fk_nip_rwykarpeg_to_pegawai', false, KeberadaanV2::TidakDiimpor, null, null, 'Tabel cadangan bertanggal (draf DBV-012 1.3)'],
        ['riwayat_keluarga', 'nip', 'fk_nip_rwykeluarga_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat keluarga (B-16), draf DBV-013'],
        ['riwayat_kgb', 'nip', 'fk_nip_rwykgb_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat KGB (B-09), draf DBV-013'],
        ['riwayat_kp', 'nip', 'fk_nip_rwykp_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat KP (B-08), draf DBV-013'],
        ['riwayat_layanan', 'nip', 'fk_nip_rwylayanan_to_pegawai', false, KeberadaanV2::TidakDiimpor, null, null, 'Tidak dipakai kode legacy (draf DBV-012 1.3)'],
        ['riwayat_lckh', 'nip', 'fk_riwayat_lckh_ibfk_01', false, KeberadaanV2::Ada, 3, 'B-02', 'LKH (B-12), draf DBV-013'],
        ['riwayat_lckh', 'nip_atasan', 'fk_riwayat_lckh_ibfk_02', true, KeberadaanV2::Ada, 3, 'B-02', 'Atasan pemeriksa LKH, draf DBV-013'],
        ['riwayat_lckh_2019', 'nip', 'riwayat_lckh_2019_ibfk_1', false, KeberadaanV2::TidakDiimpor, null, null, 'Arsip LKH per tahun, tidak dibuat sebagai tabel v2 (draf DBV-012 1.3)'],
        ['riwayat_lckh_2019', 'nip_atasan', 'riwayat_lckh_2019_ibfk_2', true, KeberadaanV2::TidakDiimpor, null, null, 'Arsip LKH per tahun, tidak dibuat sebagai tabel v2 (draf DBV-012 1.3)'],
        ['riwayat_lckh_2020', 'nip', 'riwayat_lckh_2020_ibfk_1', false, KeberadaanV2::TidakDiimpor, null, null, 'Arsip LKH per tahun, tidak dibuat sebagai tabel v2 (draf DBV-012 1.3)'],
        ['riwayat_lckh_2020', 'nip_atasan', 'riwayat_lckh_2020_ibfk_2', true, KeberadaanV2::TidakDiimpor, null, null, 'Arsip LKH per tahun, tidak dibuat sebagai tabel v2 (draf DBV-012 1.3)'],
        ['riwayat_lckh_2021', 'nip', 'riwayat_lckh_2021_ibfk_1', false, KeberadaanV2::TidakDiimpor, null, null, 'Arsip LKH per tahun, tidak dibuat sebagai tabel v2 (draf DBV-012 1.3)'],
        ['riwayat_lckh_2021', 'nip_atasan', 'riwayat_lckh_2021_ibfk_2', true, KeberadaanV2::TidakDiimpor, null, null, 'Arsip LKH per tahun, tidak dibuat sebagai tabel v2 (draf DBV-012 1.3)'],
        ['riwayat_lckh_2022', 'nip', 'riwayat_lckh_2022_ibfk_1', false, KeberadaanV2::TidakDiimpor, null, null, 'Arsip LKH per tahun, tidak dibuat sebagai tabel v2 (draf DBV-012 1.3)'],
        ['riwayat_lckh_2022', 'nip_atasan', 'riwayat_lckh_2022_ibfk_2', true, KeberadaanV2::TidakDiimpor, null, null, 'Arsip LKH per tahun, tidak dibuat sebagai tabel v2 (draf DBV-012 1.3)'],
        ['riwayat_mutasi_jabatan', 'nip', 'fk_nip_rwymutasijabatan_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat jabatan (B-07), draf DBV-013'],
        ['riwayat_organisasi', 'nip', 'fk_nip_rwyorganisasi_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat organisasi (B-17), draf DBV-013'],
        ['riwayat_pendidikan', 'nip', 'fk_nip_rwypendidikan_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat pendidikan (B-10), draf DBV-013'],
        ['riwayat_pmk', 'nip', 'fk_rw_pmk_01', false, KeberadaanV2::Ada, 4, 'C-01', 'Penyesuaian masa kerja'],
        ['riwayat_pmk_req', 'nip', 'fk_rriwayat_pmk_req_01', false, KeberadaanV2::Ada, 4, 'C-01', 'Permohonan PMK'],
        ['riwayat_seminar', 'nip', 'fk_nip_rwyseminar_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat seminar (B-11), draf DBV-013'],
        ['riwayat_skp', 'nip', 'fk_nip_rwyskp_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat SKP (B-12), draf DBV-013'],
        ['riwayat_tanda_jasa', 'nip', 'fk_nip_rwytj_to_pegawai', false, KeberadaanV2::Ada, 3, 'B-02', 'Riwayat tanda jasa (B-17), draf DBV-013'],
        ['riwayat_tb', 'nip', 'fk_nip_rwytb_to_pegawai', false, KeberadaanV2::Ada, 4, 'C-01', 'Tugas belajar'],
        ['skl', 'nip', 'fk_nip_skl_to_peg', false, KeberadaanV2::Ada, 7, 'F-01', 'Survei kepuasan layanan (F-11)'],
        ['token', 'nip', 'fk_nip_token_to_pegawai', true, KeberadaanV2::Ada, 0, 'F0-06', 'Di main tanpa FK: jejak NIP pemilik saat token terbit (A-01 D-7); identitas sesi = id_pengguna (DBV-010)'],
        ['user_banner', 'nip', 'fk_nip_usbanner_to_pegawai', false, KeberadaanV2::Ada, 7, 'F-01', 'Banner per pegawai'],
        ['user_form_digital', 'nip', 'fk_nip_user_fd_to_pegawai', false, KeberadaanV2::Ada, 7, 'F-01', 'Penugasan form digital'],
        ['user_geo', 'nip', 'fk_nip_usergeo_to_pegawai', false, KeberadaanV2::BelumDiputuskan, null, null, 'Log koordinat (controllers/hr/services/Local.php); tidak ada di Mapping Migrasi'],
        ['user_lokasi_presensi', 'nip', 'fk_user_lokasi_presensi_01', false, KeberadaanV2::Ada, 2, 'G-03', 'Pemetaan pegawai ke lokasi presensi (DoD G-03; Mapping Tier 3)'],
        ['user_popup', 'nip', 'fk_nip_userpopup_to_pegawai', false, KeberadaanV2::Ada, 7, 'F-01', 'Popup per pegawai'],
    ];

    /**
     * Kolom tanpa FK di legacy: [tabel, kolom, jenis, sumber, nullable, keberadaan, fase, pemilik, catatan].
     */
    private const NON_FK = [
        [
            'pengguna', 'username', JenisRujukanNip::NonFkUbahNipLegacy,
            '[K-kode] libraries/hr/L_employee.php:589-599 (update_nip)', false, KeberadaanV2::Ada, 1, 'A-01',
            'Legacy mengganti username = NIP baru sekaligus password = md5(NIP baru) dan password_decode; v2 tidak boleh '
            . 'mereset password (K4) dan tidak punya password_decode (A-01)',
        ],
        [
            'gaji_pegawai', 'nip', JenisRujukanNip::NonFkSlipGaji,
            '[K] gaji_pegawai.nip tanpa FK, UNIQUE (nip, bulan, tahun)', false, KeberadaanV2::Ada, 5, 'D-01',
            'Slip Gaji (D-10/D-11, CR-027); tidak diganti Ubah NIP legacy sehingga slip lama tidak lagi terlihat pegawai',
        ],
        [
            'riwayat_cuti', 'nip_atasan_langsung', JenisRujukanNip::NonFkFkV2,
            '[K] tanpa FK, VARCHAR(50); [V2] 04-Layanan.md:21 (DoD C-01), SRS :161', true, KeberadaanV2::Ada, 4, 'C-01',
            'NIP atasan langsung; FK v2 menuntut VARCHAR(30) dan audit orphan sebelum impor',
        ],
        [
            'riwayat_cuti', 'nip_yang_menyetujui', JenisRujukanNip::NonFkFkV2,
            '[K] tanpa FK, VARCHAR(50); [V2] 04-Layanan.md:21 (DoD C-01), SRS :161', true, KeberadaanV2::Ada, 4, 'C-01',
            'NIP pejabat yang menyetujui; FK v2 menuntut VARCHAR(30) dan audit orphan sebelum impor',
        ],
    ];

    /**
     * Nama kolom FK v2 yang berbeda dari legacy (`tabel.kolom` legacy → kolom v2). Kolom tanpa FK di {@see self::NON_FK}
     * tidak berganti nama.
     */
    private const KOLOM_V2 = ['pengguna.id_pegawai' => 'nip'];

    private function __construct()
    {
    }

    /**
     * Seluruh entri: FK legacy (urut tabel, kolom) lalu kolom tanpa FK.
     *
     * @return list<RujukanNip>
     */
    public static function semua(): array
    {
        $hasil = [];

        foreach (self::FK_LEGACY as [$tabel, $kolom, $namaFk, $nullable, $keberadaan, $fase, $pemilik, $catatan]) {
            $hasil[] = new RujukanNip(
                $tabel,
                $kolom,
                JenisRujukanNip::FkLegacy,
                '[K] FK ' . $namaFk,
                $namaFk,
                $nullable,
                $keberadaan,
                $fase,
                $pemilik,
                $catatan,
                self::KOLOM_V2[$tabel . '.' . $kolom] ?? null,
            );
        }

        foreach (self::NON_FK as [$tabel, $kolom, $jenis, $sumber, $nullable, $keberadaan, $fase, $pemilik, $catatan]) {
            $hasil[] = new RujukanNip(
                $tabel,
                $kolom,
                $jenis,
                $sumber,
                null,
                $nullable,
                $keberadaan,
                $fase,
                $pemilik,
                $catatan,
            );
        }

        return $hasil;
    }

    /**
     * Entri yang tabelnya dibuat di v2 oleh fase `$fase` (0–8).
     *
     * @return list<RujukanNip>
     */
    public static function perFase(int $fase): array
    {
        self::periksaFase($fase);

        return array_values(array_filter(
            self::semua(),
            static fn (RujukanNip $r): bool => $r->keberadaan === KeberadaanV2::Ada && $r->fase === $fase,
        ));
    }

    /**
     * Entri yang tabelnya sudah ada di v2 setelah fase `$fase` selesai: cakupan koreksi NIP B-06 pada fase itu
     * (mis. `sampaiFase(3)` saat Fase 3). Fase berikutnya menambah entri tanpa mengubah B-06.
     *
     * @return list<RujukanNip>
     */
    public static function sampaiFase(int $fase): array
    {
        self::periksaFase($fase);

        return array_values(array_filter(
            self::semua(),
            static fn (RujukanNip $r): bool => $r->keberadaan === KeberadaanV2::Ada && $r->fase !== null && $r->fase <= $fase,
        ));
    }

    /**
     * @return list<RujukanNip>
     */
    public static function perJenis(JenisRujukanNip $jenis): array
    {
        return array_values(array_filter(self::semua(), static fn (RujukanNip $r): bool => $r->jenis === $jenis));
    }

    /**
     * @return list<RujukanNip>
     */
    public static function perKeberadaan(KeberadaanV2 $keberadaan): array
    {
        return array_values(array_filter(self::semua(), static fn (RujukanNip $r): bool => $r->keberadaan === $keberadaan));
    }

    private static function periksaFase(int $fase): void
    {
        if ($fase < self::FASE_MIN || $fase > self::FASE_MAX) {
            throw new InvalidArgumentException(sprintf('Fase harus %d-%d: %d', self::FASE_MIN, self::FASE_MAX, $fase));
        }
    }
}
