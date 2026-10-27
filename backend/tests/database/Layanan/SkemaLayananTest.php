<?php

declare(strict_types=1);

namespace Tests\Database\Layanan;

use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\SkemaKepegawaianTestTrait;

/**
 * DBV-022 M2 — skema Modul C Layanan Fase 4 (backend/docs/db-review/DBV-022-layanan-fase4-schema.md, disetujui DB Validator
 * 27-10-2026): 9 migration `2026-10-26-100000..100800`.
 *   - 20 tabel InnoDB `utf8mb4_unicode_ci` (termasuk semua kolom teks);
 *   - 31 FK, seluruhnya RESTRICT/RESTRICT (K1); 5 FK NIP approver/petugas DITAHAN (`AddFkNipLayananFase4`, tidak ada di
 *     skema — diuji MigrasiLayananSiklusTest);
 *   - 39 CHECK (nama & klausa domain utama);
 *   - kolom kunci (NIP VARCHAR(30), `ketua_tim` TEXT, `lama_tahun` DECIMAL(10,2)), UNIQUE `(nip, tahun)` saldo;
 *   - seed `jenis_cuti` 1..6 (Tukin meng-hard-code id), `jenis_layanan` 1..5, `jenis_rwy` 43..50;
 *   - constraint ditegakkan: 1452 (NIP/induk yatim), 1451 (hapus induk), 1062 (UNIQUE), CHECK 3819/4025.
 * Jumlah CHECK MariaDB = MySQL + 3 (CHECK `json_valid` implisit kolom JSON, catatan DBV C-1): test ini hanya memeriksa
 * CHECK bernama DBV-022, jadi sama di kedua engine.
 *
 * @internal
 */
final class SkemaLayananTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SkemaKepegawaianTestTrait;

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

    public function testTabelFkDanCheckSesuaiDokumen(): void
    {
        $this->assertCount(20, self::TABEL);
        $this->assertCount(31, self::FK);
        $this->assertCount(39, self::CHECK);

        foreach (self::TABEL as $tabel) {
            $this->assertTrue($this->skemaTableExists($tabel), "tabel {$tabel}");
            $info = $this->skemaTableInfo($tabel);
            $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null], $tabel);

            foreach ($this->skemaStringCollations($tabel) as $kolom => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "{$tabel}.{$kolom}");
            }
        }

        [$fk, $ditahan, $bukanRestrict] = $this->skemaForeignKeys(self::TABEL, self::FK_DITAHAN);
        $harapan                        = self::FK;
        ksort($harapan, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame($harapan, $fk, 'FK DBV-022 (nama [K]/[V2], kolom, induk)');
        $this->assertSame([], $ditahan, 'FK NIP approver/petugas masih ditahan (AddFkNipLayananFase4)');
        $this->assertSame([], $bukanRestrict, 'semua FK Modul C RESTRICT/RESTRICT (K1)');

        $check        = array_map(static fn (array $c): string => $c[0], $this->skemaChecks(self::TABEL));
        $harapanCheck = self::CHECK;
        ksort($harapanCheck, SORT_STRING);
        // MariaDB menambah CHECK json_valid implisit (nama = kolom JSON) — hanya CHECK bernama chk_* yang dibandingkan.
        $this->assertSame($harapanCheck, array_filter($check, static fn (string $nama): bool => str_starts_with($nama, 'chk_'), ARRAY_FILTER_USE_KEY));

        $klausa = $this->skemaChecks(['riwayat_cuti', 'pegawai_sisa_cuti', 'riwayat_tb', 'riwayat_pmk_req']);
        $this->assertSame('statusin0,1,2,3,4', $klausa['chk_rwycuti_status'][1]);
        $this->assertSame('statusin0,1,2,3', $klausa['chk_rwytb_status'][1]);
        $this->assertSame('statusin0,1,2,3', $klausa['chk_rwpmkreq_status'][1]);
        $this->assertStringContainsString('sisa_cuti>=0', $klausa['chk_psc_saldo_nonnegatif'][1]);
        $this->assertStringContainsString('utang_cuti>=0', $klausa['chk_psc_saldo_nonnegatif'][1]);
    }

    /**
     * Kolom NIP Modul C VARCHAR(30) (V4, = `pegawai.nip`), `ketua_tim` TEXT multi-NIP (DQ-15), `lama_tahun` DECIMAL(10,2),
     * UNIQUE `(nip, tahun)` saldo (DQ-10), index hot path approval (DQ-16).
     */
    public function testKolomKunciDanIndex(): void
    {
        $cuti = $this->skemaColumns('riwayat_cuti');
        $this->assertSame('varchar(30)', $cuti['nip']);
        $this->assertSame('varchar(30) null', $cuti['nip_atasan_langsung']);
        $this->assertSame('varchar(30) null', $cuti['nip_yang_menyetujui']);
        $this->assertSame('text null', $cuti['ketua_tim']);

        foreach ([
            'pegawai_sisa_cuti' => 'nip', 'riwayat_cuti_notif_kt' => 'nip_kt', 'riwayat_tb' => 'nip', 'riwayat_ib' => 'nip',
            'riwayat_pmk'       => 'nip', 'riwayat_pmk_req' => 'nip', 'inbox' => 'nip_penerima', 'disiplin_ticket' => 'nip',
            'petugas_layanan'   => 'nip', 'firebase_token' => 'nip',
        ] as $tabel => $kolom) {
            $this->assertSame('varchar(30)', $this->skemaColumns($tabel)[$kolom], "{$tabel}.{$kolom}");
        }

        $this->assertSame('varchar(30) null', $this->skemaColumns('pa_layanan')['nip']);
        $this->assertSame('varchar(30) null', $this->skemaColumns('esign_hist')['nip']);
        $this->assertSame('decimal(10,2)', $this->skemaColumns('riwayat_tb')['lama_tahun']);
        $this->assertSame('decimal(10,2)', $this->skemaColumns('riwayat_ib')['lama_tahun']);

        [, , $unique] = $this->skemaIndexes('pegawai_sisa_cuti');
        $this->assertContains('nip_tahun', $unique);
        $this->assertSame(['nip', 'tahun'], $this->skemaIndexes('pegawai_sisa_cuti')[1]['nip_tahun'] ?? null);

        $indexCuti = $this->skemaIndexes('riwayat_cuti')[1];
        $this->assertArrayHasKey('nip_atasan_langsung_status', $indexCuti);
        $this->assertArrayHasKey('nip_yang_menyetujui_status', $indexCuti);
        $this->assertSame(['id_riwayat_cuti', 'nip_kt'], $this->skemaIndexes('riwayat_cuti_notif_kt')[0], 'PK komposit notif_kt');
    }

    public function testSeedMaster(): void
    {
        $id = fn (string $tabel, string $pk): array => array_map('intval', array_column($this->db->table($tabel)->select($pk)->orderBy($pk)->get()->getResultArray(), $pk));

        $this->assertSame([1, 2, 3, 4, 5, 6], $id('jenis_cuti', 'id_jenis_cuti'), 'id tetap = StatusHarian::JENIS_CUTI (Tukin)');
        $this->assertSame([1, 2, 3, 4, 5], $id('jenis_layanan', 'id_jenis_layanan'));

        $rwy = $this->db->table('jenis_rwy')->select('id_jenis_rwy, kode')->where('id_jenis_rwy >=', 43)->orderBy('id_jenis_rwy')->get()->getResultArray();
        $this->assertSame(range(43, 50), array_map('intval', array_column($rwy, 'id_jenis_rwy')));
        $this->assertSame('cuti', $rwy[0]['kode']);
        $this->assertSame('surat_ib', $rwy[7]['kode']);
        $this->assertSame(8, $this->db->table('jenis_rwy')->where('id_jenis_rwy >=', 43)->countAllResults(), 'tidak ada kode > 50');
    }

    /**
     * Lapis DB: FK menolak NIP/induk yatim (1452) dan hapus induk terpakai (1451), UNIQUE saldo (1062), CHECK status cuti
     * 0..4 dan saldo ≥ 0 (3819 MySQL / 4025 MariaDB).
     */
    public function testConstraintDitegakkan(): void
    {
        $nip = $this->buatPegawai();

        $this->assertDbError([1452], fn () => $this->isi('riwayat_cuti', ['nip' => '199912312099121001', 'id_jenis_cuti' => 1]));
        $this->assertDbError([1452], fn () => $this->isi('riwayat_cuti', ['nip' => $nip, 'id_jenis_cuti' => 99]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('riwayat_cuti', ['nip' => $nip, 'id_jenis_cuti' => 1, 'status' => 5]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('riwayat_cuti', ['nip' => $nip, 'id_jenis_cuti' => 1, 'status' => 10]));

        $idCuti = $this->isi('riwayat_cuti', ['nip' => $nip, 'id_jenis_cuti' => 1, 'status' => 4, 'ketua_tim' => "{$nip}***{$nip}"]);
        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $idCuti, 'status' => 4, 'nip_atasan_langsung' => null]);
        $this->assertDbError([1451], fn () => $this->db->table('jenis_cuti')->where('id_jenis_cuti', 1)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->where('nip', $nip)->delete());

        $this->isi('pegawai_sisa_cuti', ['nip' => $nip, 'tahun' => 2026, 'sisa_cuti' => 12, 'sisa_cuti_n1' => 9]);
        $this->assertDbError([1062], fn () => $this->isi('pegawai_sisa_cuti', ['nip' => $nip, 'tahun' => 2026]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('pegawai_sisa_cuti', ['nip' => $nip, 'tahun' => 2027, 'sisa_cuti' => -1]));
        $this->assertDbError([3819, 4025], fn () => $this->isi('pegawai_sisa_cuti', ['nip' => $nip, 'tahun' => 2028, 'utang_cuti' => -1]));
        // Batas atas D8 (N-1 ≤ 6) tidak di-CHECK: 303 baris legacy 2026 N-1 > 6 diimpor apa adanya (PRD v1.3 DQ-23/DQ-10).
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $nip, 'tahun' => 2026, 'sisa_cuti_n1' => 9]);

        $this->assertDbError([1452], fn () => $this->isi('inbox', ['nip_penerima' => '199912312099121001', 'modul' => 'cuti']));
        $this->assertDbError([3819, 4025], fn () => $this->isi('inbox', ['nip_penerima' => $nip, 'modul' => 'tiket']));
        $this->assertDbError([1452], fn () => $this->isi('riwayat_tb', ['nip' => $nip, 'id_jenjang_pendidikan' => 99_999]));
    }

    /**
     * Baris uji dengan kolom wajib diisi otomatis (pola NipCascadeTest::sisip); $isi menimpa.
     *
     * @param array<string, mixed> $isi
     */
    private function isi(string $tabel, array $isi): int
    {
        $kolom = $this->db->query(
            'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($tabel)],
        )->getResultArray();
        $baris = [];

        foreach ($kolom as $k) {
            $nama = (string) $k['COLUMN_NAME'];

            if (array_key_exists($nama, $isi) || $k['IS_NULLABLE'] === 'YES' || $k['COLUMN_DEFAULT'] !== null || str_contains((string) $k['EXTRA'], 'auto_increment')) {
                continue;
            }

            $baris[$nama] = match ((string) $k['DATA_TYPE']) {
                'date'                                                                            => '2026-01-05',
                'datetime', 'timestamp'                                                           => '2026-01-05 00:00:00',
                'year'                                                                            => 2026,
                'int', 'tinyint', 'smallint', 'bigint', 'mediumint', 'decimal', 'double', 'float' => 1,
                default                                                                           => substr('Uji ' . $nama, 0, max(1, (int) ($k['CHARACTER_MAXIMUM_LENGTH'] ?? 10))),
            };
        }

        $this->db->table($tabel)->insert(array_merge($baris, $isi));

        return (int) $this->db->insertID();
    }
}
