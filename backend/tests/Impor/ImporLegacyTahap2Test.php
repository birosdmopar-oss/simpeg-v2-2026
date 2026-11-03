<?php

declare(strict_types=1);

namespace Tests\Impor;

use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use App\Libraries\Impor\PengimporLegacy;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * MAKE-069 — impor cutover tahap 2 (rencana `docs/migrasi/MAKE-069-rencana.md`, DBV-029): akun (T8), pegawai lanjutan
 * (T9), master sisa (T7), transaksi per tabel + berkas jejak (T10, K-M8). Data sintetis.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporLegacyTahap2Test extends ImporLegacyTestCase
{
    private const NIP_A = '000000000000000001';
    private const NIP_B = '000000000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->isiMasterDasar();
        $this->src->table('jenis_pegawai')->insert(['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS', 'order' => 1, 'status' => 1]);
        $this->src->table('pegawai')->insertBatch([
            ['nip' => self::NIP_A, 'nama' => 'Pegawai Uji A', 'tgl_lahir' => '1990-01-01', 'id_jenis_pegawai' => 1, 'jenis_kelamin' => 1, 'status' => 1, 'flag_update' => 1],
            ['nip' => self::NIP_B, 'nama' => 'Pegawai Uji B', 'tgl_lahir' => '1991-02-02', 'id_jenis_pegawai' => 1, 'jenis_kelamin' => 2, 'status' => 1, 'flag_update' => 0],
        ]);
        $this->jalankanImpor([1, 2]);
    }

    /**
     * Akun tanpa NIP hasil impor ter-commit (isolasi penuh) menahan rollback DBV-010 di proses test berikutnya; dibersihkan
     * di sini, sebelum pemulihan data DatabaseTestCase.
     */
    protected function tearDown(): void
    {
        $this->db->table('pengguna')->where('id_pengguna >', 0)->delete();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $kolom
     *
     * @return array<string, mixed>
     */
    private function akun(int $id, int $role, ?string $nip, string $md5, array $kolom = []): array
    {
        return $kolom + [
            'id'       => $id, 'id_pegawai' => $nip, 'username' => "u{$id}", 'name' => "Akun {$id}", 'email' => null, 'UserLevel' => $role,
            'password' => $md5, 'password_decode' => 'rahasia-plaintext', 'status' => '1', 'created_at' => '2026-01-01 08:00:00',
        ];
    }

    public function testPenggunaPasswordLegacyDanWajibGanti(): void
    {
        $this->src->table('pengguna')->insertBatch([
            $this->akun(7, 1, null, md5('x')),
            $this->akun(8, 2, self::NIP_A, md5(self::NIP_A)),
            $this->akun(9, 2, self::NIP_B, md5('lain')),
            $this->akun(10, 4, '', md5('y')),
        ]);

        $laporan = $this->jalankanImpor([8]);

        $this->assertSame(['K-M2 role 1 wajib ganti password' => 1, 'K-M1 password = NIP wajib ganti' => 1], $laporan['pengguna']['koreksi']);

        $akun = $this->db->table('pengguna')->select('id_pengguna, nip, user_level, password, password_legacy, must_change_password, created_at')
            ->orderBy('id_pengguna')->get()->getResultArray();
        $this->assertSame(['7', '8', '9', '10'], array_column($akun, 'id_pengguna'), 'id legacy disalin (DBV-010 §9.4)');
        $this->assertSame([null, null, null, null], array_column($akun, 'password'), 'Argon2id baru saat login pertama');
        $this->assertSame(md5(self::NIP_A), $akun[1]['password_legacy']);
        $this->assertSame(['1', '1', '0', '0'], array_column($akun, 'must_change_password'));
        $this->assertNull($akun[3]['nip'], 'NIP kosong → NULL');
        $this->assertSame('2026-01-01 01:00:00', $akun[0]['created_at']);
        $this->assertArrayNotHasKey('password_decode', $this->db->table('pengguna')->get()->getRowArray());
    }

    public function testPenggunaRolePegawaiTanpaNipGagalDenganJumlah(): void
    {
        $this->src->table('pengguna')->insertBatch([$this->akun(7, 2, null, md5('x')), $this->akun(8, 6, '', md5('x')), $this->akun(9, 3, null, md5('x'), ['name' => ' '])]);

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('pengguna: 2 akun role 2/6/7 tanpa NIP, 1 akun tanpa NIP dan tanpa nama (DBV-010 §9.5).');

        $this->jalankanImpor([8], dryRun: true);
    }

    public function testPenggunaNipTanpaPegawaiGagal(): void
    {
        $this->src->table('pengguna')->insert($this->akun(7, 2, '000000000000000099', md5('x')));

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('Rujukan yatim pengguna.nip: 1 nilai tidak ada di tabel induk');

        $this->jalankanImpor([8]);
    }

    public function testPenggunaGagalBilaAkunSistemSudahAda(): void
    {
        $this->db->table('pengguna')->insert(['username' => 'sistem.integrasi', 'name' => 'Sistem Integrasi', 'user_level' => 1, 'status' => '0']);
        $this->src->table('pengguna')->insert($this->akun(7, 1, null, md5('x')));

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('Tabel tujuan pengguna tidak kosong (1 baris).');

        $this->jalankanImpor([8], dryRun: true);
    }

    public function testPegawaiHistTerbaruDanLokasiPresensi(): void
    {
        $hist = static fn (int $id, string $nip, int $flag): array => ['id_pegawai_hist' => $id, 'nip' => $nip, 'nama' => 'Uji', 'tgl_lahir' => '1990-01-01', 'jenis_kelamin' => 1, 'flag_update' => $flag, 'status' => 1];
        $this->src->table('pegawai_hist')->insertBatch([$hist(1, self::NIP_A, 1), $hist(2, self::NIP_A, 1), $hist(3, self::NIP_A, 2), $hist(4, self::NIP_B, 0)]);
        $this->src->query('ALTER TABLE dm_user_lokasi_presensi DROP COLUMN status');
        $this->src->table('dm_user_lokasi_presensi')->insert([
            'id_dm_user_lokasi_presensi' => 5, 'target_lp' => '[\"99\"]', 'target_lp_desc' => 'Kantor', 'target_peg' => '[\"' . self::NIP_A . '\",\"000000000000000099\"]',
            'created_at'                 => '2026-01-01 08:00:00',
        ]);

        $laporan = $this->jalankanImpor([9]);

        $this->assertSame(2, $laporan['pegawai_hist']['sumber'], 'saringan flag_update = 1');
        $this->assertSame(['DBV-029 K-M9b bukan pengajuan terbaru' => 1], $laporan['pegawai_hist']['dibuang']);
        $this->assertSame(['2'], array_column($this->db->table('pegawai_hist')->select('id_pegawai_hist')->get()->getResultArray(), 'id_pegawai_hist'));

        $aturan = $this->db->table('dm_user_lokasi_presensi')->get()->getRowArray();
        $this->assertSame('1', $aturan['status'], 'status [V2] = 1 (DBV-029 K-M6)');
        $this->assertSame('["99"]', $aturan['target_lp'], 'JSON ber-addslashes di-stripslashes');
        $this->assertSame(1, $laporan['dm_user_lokasi_presensi']['koreksi']['INFO target_lp tanpa lokasi_presensi']);
        $this->assertSame(1, $laporan['dm_user_lokasi_presensi']['koreksi']['INFO target_peg/excl_peg tanpa pegawai']);
    }

    public function testLokasiPresensiJsonRusakGagal(): void
    {
        $this->src->table('dm_user_lokasi_presensi')->insert(['id_dm_user_lokasi_presensi' => 5, 'target_lp' => '[1,', 'target_lp_desc' => 'x']);

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('dm_user_lokasi_presensi: 1 aturan dengan JSON target_* tidak terurai sesudah stripslashes (DBV-029 K-M6).');

        $this->jalankanImpor([9], dryRun: true);
    }

    public function testMasterOldIdUrutanNamaDanFaq(): void
    {
        $this->lepasCheck('jenis_konket');
        $this->src->query('ALTER TABLE jenis_konket MODIFY old_id VARCHAR(20) NULL, DROP INDEX uq_jenis_konket_old_id');
        $this->src->table('jenis_konket')->insertBatch([
            ['id_jenis_konket' => 2, 'jenis_konket' => 'Dinas Luar', 'old_id' => ' 4 ', 'affect_tukin' => 1, 'status' => 1],
            ['id_jenis_konket' => 3, 'jenis_konket' => 'Lama', 'old_id' => 'x', 'affect_tukin' => 1, 'status' => 1],
            ['id_jenis_konket' => 5, 'jenis_konket' => 'Dibuang', 'old_id' => null, 'affect_tukin' => 1, 'status' => 1],
        ]);
        $this->src->query('ALTER TABLE bidang_pendidikan DROP COLUMN `order`');
        $this->src->table('bidang_pendidikan')->insertBatch([
            ['id_bidang_pendidikan' => 1, 'bidang_pendidikan' => 'Teknik', 'status' => 1],
            ['id_bidang_pendidikan' => 2, 'bidang_pendidikan' => 'agama', 'status' => 1],
        ]);
        $this->src->query('ALTER TABLE faq_article DROP COLUMN `order`');
        $this->src->table('faq_topic')->insert(['id_faq_topic' => 1, 'faq_topic' => 'Umum', 'status' => 1]);
        $this->src->table('faq_sub_topic')->insert(['id_faq_sub_topic' => 1, 'id_faq_topic' => 1, 'faq_sub_topic' => 'Akun', 'status' => 1]);
        $this->src->table('faq_article')->insertBatch([
            ['id_faq_article' => 1, 'id_faq_sub_topic' => 1, 'title' => 'Zebra', 'content' => '<p>Aman</p>', 'content_stripped' => 'lama', 'status' => 1],
            ['id_faq_article' => 2, 'id_faq_sub_topic' => 1, 'title' => 'Apel', 'content' => '<p>Isi <script>x()</script>\\\'kutip\\\'</p>', 'content_stripped' => 'lama', 'status' => 1],
        ]);

        try {
            $this->jalankanImpor([7], dryRun: true);
            $this->fail('old_id tidak sah tanpa keputusan harus gagal (K-M9a).');
        } catch (ImporGagal $e) {
            $this->assertStringContainsString('jenis_konket: 2 old_id NULL/kosong/bukan angka', $e->getMessage());
        }

        $k       = Keputusan::dariArray(['baris' => ['jenis_konket.old_id' => ['3' => 7, '5' => '__buang']]]);
        $laporan = $this->jalankanImpor([7], k: $k);

        $this->assertSame(['4', '7'], array_column($this->db->table('jenis_konket')->select('old_id')->orderBy('id_jenis_konket')->get()->getResultArray(), 'old_id'));
        $this->assertSame(['DBV-029 K-M9a keputusan: tidak diimpor' => 1], $laporan['jenis_konket']['dibuang']);
        $this->assertArrayHasKey('WAJIB_0 absen_ijin.kategori tanpa jenis_konket.old_id', $laporan['jenis_konket']['koreksi'] + ['WAJIB_0 absen_ijin.kategori tanpa jenis_konket.old_id' => 0]);

        $urut = array_column($this->db->table('bidang_pendidikan')->select('id_bidang_pendidikan, order')->get()->getResultArray(), 'order', 'id_bidang_pendidikan');
        ksort($urut);
        $this->assertSame([1 => '2', 2 => '1'], $urut, 'urut nama tanpa beda huruf: agama (id 2) lalu Teknik (id 1)');

        $faq = $this->db->table('faq_article')->select('id_faq_article, content, content_stripped, order')->orderBy('id_faq_article')->get()->getResultArray();
        $this->assertStringNotContainsString('<script>', $faq[1]['content']);
        $this->assertStringContainsString("'kutip'", $faq[1]['content'], 'stripslashes sebelum sanitizer');
        $this->assertNotSame('lama', $faq[0]['content_stripped'], 'content_stripped dihitung ulang');
        $this->assertSame(['2', '1'], array_column($faq, 'order'));
        $this->assertSame(1, $laporan['faq_article']['koreksi']['DBV-029 K-M9c konten diubah sanitizer']);
    }

    public function testTierPerTabelJejakDanLanjut(): void
    {
        $this->src->table('riwayat_kgb')->insert(['id_riwayat_kgb' => 1, 'nip' => self::NIP_A, 'tmtsk' => '2026-01-01', 'tgl_sk' => '2026-01-01', 'status' => 1]);
        $this->src->table('riwayat_seminar')->insertBatch([
            ['id_riwayat_seminar' => 1, 'nip' => self::NIP_A, 'nama_seminar' => 'Seminar Uji', 'status' => 1],
            ['id_riwayat_seminar' => 2, 'nip' => '000000000000000099', 'nama_seminar' => 'Yatim', 'status' => 1],
        ]);

        try {
            $this->jalankanImpor([10]);
            $this->fail('NIP yatim di riwayat_seminar harus gagal.');
        } catch (ImporGagal $e) {
            $this->assertStringContainsString('Tier 10 per tabel: selesai 3, gagal 1 (riwayat_seminar), belum 13; tabel yang selesai tetap ter-commit (K-M8).', $e->getMessage());
        }

        $this->assertSame(1, $this->db->table('riwayat_kgb')->countAllResults(), 'tabel selesai tetap ter-commit');
        $this->assertSame(0, $this->db->table('riwayat_seminar')->countAllResults(), 'tabel gagal di-rollback utuh');
        $jejak = json_decode((string) file_get_contents($this->berkasJejak()), true);
        $this->assertSame(['riwayat_kgb', 'riwayat_pendidikan', 'riwayat_diklat'], array_keys($jejak['tabel']));
        $this->assertSame(['tier' => 10, 'baris' => 1, 'pkMaks' => '1'], array_intersect_key($jejak['tabel']['riwayat_kgb'], ['tier' => 0, 'baris' => 0, 'pkMaks' => 0]));

        try {
            $this->jalankanImpor([10]);
            $this->fail('Tanpa --lanjut tier sebagian harus gagal.');
        } catch (ImporGagal $e) {
            $this->assertStringContainsString('Tabel tujuan riwayat_kgb tidak kosong', $e->getMessage());
        }

        $this->src->table('riwayat_seminar')->where('id_riwayat_seminar', 2)->delete();
        $laporan = $this->jalankanImpor([10], lanjut: true);
        $this->assertSame('sudah (berkas jejak, --lanjut)', $laporan['riwayat_kgb']['catatan']);
        $this->assertSame(1, $laporan['riwayat_seminar']['ditulis']);

        // Tujuan berubah sesudah dicatat → --lanjut gagal tanpa menghapus apa pun.
        $this->db->table('riwayat_kgb')->insert(['id_riwayat_kgb' => 5, 'nip' => self::NIP_A, 'tmtsk' => '2026-01-01', 'tgl_sk' => '2026-01-01', 'status' => 1]);
        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('--lanjut: riwayat_kgb tidak cocok dengan berkas jejak');
        $this->jalankanImpor([10], lanjut: true);
    }

    public function testTierPerTabelTanpaJejakGagalKecualiDryRun(): void
    {
        $pengimpor = static fn (self $t): PengimporLegacy => new PengimporLegacy($t->src, $t->db, 'WIB', Keputusan::kosong());

        $this->assertSame(0, $pengimpor($this)->jalankan([10], true)->semua()['riwayat_kgb']['sumber']);

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('Tier 10 memakai transaksi per tabel dan butuh --jejak (DBV-029 K-M8).');
        $pengimpor($this)->jalankan([10], false);
    }
}
