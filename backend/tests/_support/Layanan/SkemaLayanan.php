<?php

declare(strict_types=1);

namespace Tests\Support\Layanan;

/**
 * Daftar skema Modul C Layanan Fase 4 (DBV-022, disetujui DB Validator 27-10-2026) yang dipakai bersama test skema
 * (`SkemaLayananTest`, `SkemaInboxTicketTest`, `MigrasiLayananSiklusTest`) dan test master yang melepas FK masuk dari tabel
 * Modul C. Ditaruh di `tests/_support` (autoload) karena test di-shard: kelas test lain belum tentu dimuat di shard yang sama.
 */
final class SkemaLayanan
{
    /**
     * Versi migration Modul C pertama (`2026-10-26-100000`); semua migration App >= versi ini milik DBV-022.
     */
    public const VERSI_PERTAMA = '2026-10-26-100000';

    /**
     * Kode `jenis_rwy` seed Modul C (`SeedJenisRwyLayananFase4::ROWS`); test seed B-02 mengecualikannya.
     */
    public const JENIS_RWY = [43, 44, 45, 46, 47, 48, 49, 50];

    public const TABEL = [
        'jenis_cuti', 'jenis_layanan', 'jenis_pa', 'question_category',
        'pegawai_sisa_cuti', 'riwayat_cuti', 'riwayat_cuti_notif_kt',
        'riwayat_tb', 'riwayat_tb_perpan', 'riwayat_ib',
        'riwayat_pmk', 'riwayat_pmk_req',
        'inbox', 'inbox_msg', 'disiplin_ticket', 'disiplin_ticket_msg',
        'petugas_layanan', 'pa_layanan', 'esign_hist', 'firebase_token',
    ];

    /** FK DBV-022: nama => [tabel, kolom, induk, kolom induk]. */
    public const FK = [
        'fk_nip_psc_to_pegawai'                             => ['pegawai_sisa_cuti', 'nip', 'pegawai', 'nip'],
        'fk_nip_rwycuti_to_pegawai'                         => ['riwayat_cuti', 'nip', 'pegawai', 'nip'],
        'id_jenis_cuti'                                     => ['riwayat_cuti', 'id_jenis_cuti', 'jenis_cuti', 'id_jenis_cuti'],
        'fk_id_riwayat_cuti_rcnkt_1'                        => ['riwayat_cuti_notif_kt', 'id_riwayat_cuti', 'riwayat_cuti', 'id_riwayat_cuti'],
        'fk_nip_kt_rcnkt_2'                                 => ['riwayat_cuti_notif_kt', 'nip_kt', 'pegawai', 'nip'],
        'fk_id_jenjang_pendidikan_twytb_to_jenjp'           => ['riwayat_tb', 'id_jenjang_pendidikan', 'jenjang_pendidikan', 'id_jenjang_pendidikan'],
        'fk_nip_rwytb_to_pegawai'                           => ['riwayat_tb', 'nip', 'pegawai', 'nip'],
        'fk_id_riwayat_tb_tbperpan_to_tb'                   => ['riwayat_tb_perpan', 'id_riwayat_tb', 'riwayat_tb', 'id_riwayat_tb'],
        'fk_id_jenjang_pendidikan_rwyib_to_jp'              => ['riwayat_ib', 'id_jenjang_pendidikan', 'jenjang_pendidikan', 'id_jenjang_pendidikan'],
        'fk_nip_rwyib_to_pegawai'                           => ['riwayat_ib', 'nip', 'pegawai', 'nip'],
        'fk_rw_pmk_01'                                      => ['riwayat_pmk', 'nip', 'pegawai', 'nip'],
        'fk_rriwayat_pmk_req_01'                            => ['riwayat_pmk_req', 'nip', 'pegawai', 'nip'],
        'fk_nip_penerima_inbox_to_pegawai'                  => ['inbox', 'nip_penerima', 'pegawai', 'nip'],
        'fk_id_jenis_layanan_inbox_to_jl'                   => ['inbox', 'id_jenis_layanan', 'jenis_layanan', 'id_jenis_layanan'],
        'fk_id_inbox_inboxmsg_to_inbox'                     => ['inbox_msg', 'id_inbox', 'inbox', 'id_inbox'],
        'fk_id_question_category_i_qc'                      => ['disiplin_ticket', 'id_question_category', 'question_category', 'id_question_category'],
        'fk_id_inbox_im_qc'                                 => ['disiplin_ticket_msg', 'id_inbox', 'disiplin_ticket', 'id_inbox'],
        'fk_id_jenis_layanan_pl_jl'                         => ['petugas_layanan', 'id_jenis_layanan', 'jenis_layanan', 'id_jenis_layanan'],
        'fk_id_group_jabatan_pemohon_pa_layanan_to_gj'      => ['pa_layanan', 'id_group_jabatan_pemohon', 'group_jabatan', 'id_group_jabatan'],
        'fk_id_group_jabatan_petugas_pa_layanan_to_gj'      => ['pa_layanan', 'id_group_jabatan_petugas', 'group_jabatan', 'id_group_jabatan'],
        'fk_id_jabatan_koord_pal_to_jabkoord'               => ['pa_layanan', 'id_jabatan_koord', 'jabatan_koordinasi', 'id_jabatan_koordinasi'],
        'fk_id_jabatan_pa_layanan_to_jabatan'               => ['pa_layanan', 'id_jabatan', 'jabatan', 'id_jabatan'],
        'fk_id_jenis_pa_pa_layanan_to_jenis_pa'             => ['pa_layanan', 'id_jenis_pa', 'jenis_pa', 'id_jenis_pa'],
        'fk_id_satker_petugas_pa_layanan_to_satker'         => ['pa_layanan', 'id_satker_petugas', 'satker', 'id_satker'],
        'fk_id_sub_group_jabatan_pemohon_pa_layanan_to_sgj' => ['pa_layanan', 'id_sub_group_jabatan_pemohon', 'sub_group_jabatan', 'id_sub_group_jabatan'],
        'fk_id_sub_group_jabatan_petugas_pa_layanan_to_sgj' => ['pa_layanan', 'id_sub_group_jabatan_petugas', 'sub_group_jabatan', 'id_sub_group_jabatan'],
        'fk_id_unit_pemohon_pa_layanan_to_unit'             => ['pa_layanan', 'id_unit_pemohon', 'unit', 'id_unit'],
        'fk_id_unit_petugas_pa_layanan_to_unit'             => ['pa_layanan', 'id_unit_petugas', 'unit', 'id_unit'],
        'pa_layanan_ibfk_01'                                => ['pa_layanan', 'id_jenis_pegawai', 'jenis_pegawai', 'id_jenis_pegawai'],
        'fk_esign_hist_ibfk01'                              => ['esign_hist', 'nip', 'pegawai', 'nip'],
        'fk_nip_firebase_token_to_pegawai'                  => ['firebase_token', 'nip', 'pegawai', 'nip'],
    ];

    /** FK NIP yang ditahan (AddFkNipLayananFase4) — boleh ada bila migration itu kelak dipasang. */
    public const FK_DITAHAN = [
        'fk_nip_atasan_langsung_rwycuti_to_pegawai', 'fk_nip_yang_menyetujui_rwycuti_to_pegawai',
        'fk_nip_petugas_layanan_to_pegawai', 'fk_nip_pa_layanan_to_pegawai', 'fk_nip_disiplin_ticket_to_pegawai',
    ];

    /** Nama CHECK DBV-022 => tabel. */
    public const CHECK = [
        'chk_jenis_cuti_status'         => 'jenis_cuti',
        'chk_jenis_layanan_status'      => 'jenis_layanan',
        'chk_jenis_pa_status'           => 'jenis_pa',
        'chk_question_category_status'  => 'question_category',
        'chk_psc_digunakan'             => 'pegawai_sisa_cuti',
        'chk_psc_saldo_nonnegatif'      => 'pegawai_sisa_cuti',
        'chk_rwycuti_status'            => 'riwayat_cuti',
        'chk_rwycuti_status_al'         => 'riwayat_cuti',
        'chk_rwycuti_status_pyb'        => 'riwayat_cuti',
        'chk_rwycuti_status_regen'      => 'riwayat_cuti',
        'chk_rwycuti_rated'             => 'riwayat_cuti',
        'chk_rwycuti_skip_al'           => 'riwayat_cuti',
        'chk_rwycuti_skip_pyb'          => 'riwayat_cuti',
        'chk_rcnkt_status'              => 'riwayat_cuti_notif_kt',
        'chk_rwytb_status'              => 'riwayat_tb',
        'chk_rwytb_status_admin_upt'    => 'riwayat_tb',
        'chk_rwytb_status_admin_deputi' => 'riwayat_tb',
        'chk_rwytb_rated'               => 'riwayat_tb',
        'chk_rwyib_status'              => 'riwayat_ib',
        'chk_rwyib_status_admin_upt'    => 'riwayat_ib',
        'chk_rwyib_status_admin_deputi' => 'riwayat_ib',
        'chk_rwyib_rated'               => 'riwayat_ib',
        'chk_rwpmk_status'              => 'riwayat_pmk',
        'chk_rwpmk_siasn_flag'          => 'riwayat_pmk',
        'chk_rwpmkreq_status'           => 'riwayat_pmk_req',
        'chk_inbox_modul'               => 'inbox',
        'chk_inbox_is_read'             => 'inbox',
        'chk_inboxmsg_tipe'             => 'inbox_msg',
        'chk_inboxmsg_push_status'      => 'inbox_msg',
        'chk_dticket_status'            => 'disiplin_ticket',
        'chk_dticket_allow_reply'       => 'disiplin_ticket',
        'chk_dticket_is_read'           => 'disiplin_ticket',
        'chk_dticket_is_read_off'       => 'disiplin_ticket',
        'chk_dticket_notify_pic'        => 'disiplin_ticket',
        'chk_dticketmsg_show_notif'     => 'disiplin_ticket_msg',
        'chk_dticketmsg_is_read'        => 'disiplin_ticket_msg',
        'chk_petugas_layanan_status'    => 'petugas_layanan',
        'chk_pa_layanan_status'         => 'pa_layanan',
        'chk_esign_hist_status'         => 'esign_hist',
    ];
}
