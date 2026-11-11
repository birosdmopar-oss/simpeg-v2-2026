<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Database\Migrations\CreateKontenInteraksiFase7;
use App\Database\Migrations\SeedJenisDibo;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\SkemaKepegawaianTestTrait;

require_once APPPATH . 'Database/MigrationsDitahan/CreateKontenInteraksiFase7.php';
require_once APPPATH . 'Database/MigrationsDitahan/SeedJenisDibo.php';

/**
 * DBV-032 — test skema draf konten & interaksi Fase 7 (DBV-032 Bagian 7). Diuji:
 * - struktur & collation;
 * - kolom yang sengaja tidak dibuat (Q7-02, Q7-03, Q7-47);
 * - UNIQUE (1062);
 * - CHECK (3819 MySQL / 4025 MariaDB);
 * - FK NIP & induk (1451/1452);
 * - CASCADE anak saat induk dihapus;
 * - siklus down()/up();
 * - kueri audit berjalan.
 * Masih memuat draf di `MigrationsDitahan/`; saat dipindah (setelah approve DBV) `require_once` dihapus.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SkemaKontenInteraksiFase7Test extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SkemaKepegawaianTestTrait;

    private const CHECK = [3819, 4025];

    /** Nama FK NIP = registry TabelAnakNip (tabel => nama FK). */
    private const FK_NIP = [
        'user_popup'          => 'fk_nip_userpopup_to_pegawai',
        'user_banner'         => 'fk_nip_usbanner_to_pegawai',
        'user_form_digital'   => 'fk_nip_user_fd_to_pegawai',
        'filled_form_digital' => 'fk_nip_ffd_to_pegawai',
        'skl'                 => 'fk_nip_skl_to_peg',
    ];

    private CreateKontenInteraksiFase7 $m;

    private bool $naik = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new CreateKontenInteraksiFase7();
    }

    protected function tearDown(): void
    {
        if ($this->naik) {
            $this->m->down();
        }

        parent::tearDown();
    }

    public function testStrukturDanCollation(): void
    {
        $this->naik();

        foreach (CreateKontenInteraksiFase7::TABEL as $tabel) {
            $info = $this->skemaTableInfo($tabel);
            $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null], $tabel);

            foreach ($this->skemaStringCollations($tabel) as $kolom => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "{$tabel}.{$kolom}");
            }
        }

        foreach (array_keys(self::FK_NIP) as $tabel) {
            $this->assertSame('varchar(30)', $this->skemaColumns($tabel)['nip'], "{$tabel}.nip");
        }

        $this->assertArrayNotHasKey('dilihat_at', $this->skemaColumns('user_popup'), 'Q7-02');

        foreach (['popup', 'banner', 'form_digital'] as $tabel) {
            $kolom = $this->skemaColumns($tabel);

            foreach (['target', 'type', 'id_unit_satker', 'unit_satker', 'target_uns_desc', 'target_peg_desc', 'excl_peg_desc'] as $tidak) {
                $this->assertArrayNotHasKey($tidak, $kolom, "{$tabel}.{$tidak} (Q7-03/Q7-47)");
            }

            $this->assertSame(['mediumtext null', 'mediumtext null', 'mediumtext null'], [$kolom['target_uns'], $kolom['target_peg'], $kolom['excl_peg']], $tabel);
        }

        $this->assertSame('tinyint', $this->skemaColumns('news')['show_notif']);
        $this->assertSame('year null', $this->skemaColumns('dibo')['tahun']);
        $this->assertSame('tinyint null', $this->skemaColumns('news')['ul_creator']);
        $this->assertSame('int', $this->skemaColumns('skl')['id_rwy']);

        foreach (['inbox', 'inbox_msg'] as $tabel) {
            $this->assertNotContains($tabel, CreateKontenInteraksiFase7::TABEL, 'inbox tidak dibuat ulang (DBV-022)');
        }
    }

    public function testFkNipMemakaiNamaRegistry(): void
    {
        $this->naik();
        $nama = array_column($this->db->query(
            'SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND REFERENCED_TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable('pegawai')],
        )->getResultArray(), 'CONSTRAINT_NAME');

        foreach (self::FK_NIP as $tabel => $fk) {
            $this->assertContains($fk, $nama, $tabel);
        }
    }

    public function testUniqueCheckDanFk(): void
    {
        $this->naik();
        $nip  = $this->buatPegawai();
        $nip2 = $this->buatPegawai();

        $popup = $this->sisip('popup', ['tgl_mulai' => '2026-11-10 08:00:00', 'tgl_akhir' => '2026-11-11 08:00:00', 'file' => 'konten/a.png', 'target_peg' => json_encode([$nip])]);
        $this->sisip('user_popup', ['id_popup' => $popup, 'nip' => $nip]);
        $this->assertDbError([1062], fn () => $this->sisip('user_popup', ['id_popup' => $popup, 'nip' => $nip]));
        $this->assertDbError([1452], fn () => $this->sisip('user_popup', ['id_popup' => $popup, 'nip' => '000000000000000009']));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('popup', ['tgl_mulai' => '2026-11-11 00:00:00', 'tgl_akhir' => '2026-11-10 00:00:00', 'file' => 'x']));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('popup', ['tgl_mulai' => '2026-11-10 00:00:00', 'tgl_akhir' => '2026-11-10 00:00:00', 'file' => 'x', 'target_peg' => '[bukan json']));

        $kategori = $this->sisip('news_category', ['category' => 'Umum']);
        $this->assertDbError(self::CHECK, fn () => $this->sisip('news_category', ['category' => 'X', 'status' => 0]));
        $berita = $this->sisip('news', ['id_news_category' => $kategori, 'title' => 'Judul', 'news' => '<p>isi</p>', 'publish_at' => '2026-11-10 00:00:00', 'ul_creator' => 1]);
        $this->assertDbError(self::CHECK, fn () => $this->sisip('news', ['id_news_category' => $kategori, 'title' => 'J', 'news' => 'x', 'publish_at' => '2026-11-10 00:00:00', 'ul_creator' => 1, 'status' => 3]));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('news', ['id_news_category' => $kategori, 'title' => 'J', 'news' => 'x', 'publish_at' => '2026-11-10 00:00:00', 'ul_creator' => 1, 'show_notif' => 4]));
        $this->assertDbError([1452], fn () => $this->sisip('news', ['id_news_category' => 999999, 'title' => 'J', 'news' => 'x', 'publish_at' => '2026-11-10 00:00:00', 'ul_creator' => 1]));
        $this->assertDbError([1451], fn () => $this->db->table('news_category')->where('id_news_category', $kategori)->delete());

        $this->db->table('pengguna')->insert(['nip' => $nip, 'username' => 'dbv032.uji', 'user_level' => 2]);
        $pengguna = (int) $this->db->insertID();
        $this->sisip('news_flag', ['id_user' => $pengguna, 'id_news' => $berita]);
        $this->assertDbError([1062], fn () => $this->sisip('news_flag', ['id_user' => $pengguna, 'id_news' => $berita]));
        $this->assertDbError([1452], fn () => $this->sisip('news_flag', ['id_user' => 999999, 'id_news' => $berita]));

        $jenis = $this->sisip('jenis_dibo', ['jenis_dibo' => 'Peraturan']);
        $this->sisip('dibo', ['id_jenis_dibo' => $jenis, 'nomor' => '1', 'tahun' => 2026, 'tentang' => 'Uji', 'file_1' => 'konten/d.pdf']);
        $this->assertDbError(self::CHECK, fn () => $this->sisip('dibo', ['id_jenis_dibo' => $jenis, 'nomor' => '2', 'tahun' => 2026, 'tentang' => 'Uji', 'file_1' => 'x', 'status' => 3]));
        $this->sisip('dibo', ['tentang' => 'Impor legacy tanpa jenis/nomor/tahun', 'file_1' => 'konten/e.pdf']);
        $this->assertDbError([1451], fn () => $this->db->table('jenis_dibo')->where('id_jenis_dibo', $jenis)->delete());

        $form = $this->sisip('form_digital', ['judul_form' => 'Kuesioner', 'tgl_mulai' => '2026-11-10', 'tgl_akhir' => '2026-11-30', 'form_digital' => '{"pertanyaan":[]}']);
        $this->assertDbError(self::CHECK, fn () => $this->sisip('form_digital', ['judul_form' => 'X', 'tgl_mulai' => '2026-11-10', 'tgl_akhir' => '2026-11-30', 'form_digital' => 'bukan json']));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('form_digital', ['judul_form' => 'X', 'tgl_mulai' => '2026-11-10', 'tgl_akhir' => '2026-11-30', 'form_digital' => '{}', 'status' => 3]));
        $this->sisip('user_form_digital', ['id_form_digital' => $form, 'nip' => $nip]);
        $this->assertDbError([1062], fn () => $this->sisip('user_form_digital', ['id_form_digital' => $form, 'nip' => $nip]));
        $this->sisip('filled_form_digital', ['id_form_digital' => $form, 'nip' => $nip, 'response' => '{"q1":"ya"}']);
        $this->assertDbError([1062], fn () => $this->sisip('filled_form_digital', ['id_form_digital' => $form, 'nip' => $nip, 'response' => '{}'])); // submit ganda (Q7-17)
        $this->assertDbError(self::CHECK, fn () => $this->sisip('filled_form_digital', ['id_form_digital' => $form, 'nip' => $nip2, 'response' => 'bukan json']));
        $this->assertDbError([1451], fn () => $this->db->table('form_digital')->where('id_form_digital', $form)->delete()); // jawaban menahan hapus keras (Q7-19)

        $this->sisip('skl', ['nip' => $nip, 'jenis_rwy' => 1, 'id_rwy' => 7, 'rate' => 5]);
        $this->assertDbError([1062], fn () => $this->sisip('skl', ['nip' => $nip, 'jenis_rwy' => 1, 'id_rwy' => 7, 'rate' => 4]));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('skl', ['nip' => $nip, 'jenis_rwy' => 1, 'id_rwy' => 8, 'rate' => 6]));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('skl', ['nip' => $nip, 'jenis_rwy' => 6, 'id_rwy' => 8, 'rate' => 3]));
        $this->assertDbError([1452], fn () => $this->sisip('skl', ['nip' => '000000000000000009', 'jenis_rwy' => 1, 'id_rwy' => 9, 'rate' => 3]));
        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->where('nip', $nip)->delete());
    }

    public function testHapusIndukMenghapusAnak(): void
    {
        $this->naik();
        $nip = $this->buatPegawai();

        $popup = $this->sisip('popup', ['tgl_mulai' => '2026-11-10 00:00:00', 'tgl_akhir' => '2026-11-10 00:00:00', 'file' => 'a']);
        $this->sisip('user_popup', ['id_popup' => $popup, 'nip' => $nip]);
        $banner = $this->sisip('banner', ['tgl_mulai' => '2026-11-10', 'tgl_akhir' => '2026-11-10', 'file' => 'b']);
        $this->sisip('user_banner', ['id_banner' => $banner, 'nip' => $nip]);
        $form = $this->sisip('form_digital', ['judul_form' => 'F', 'tgl_mulai' => '2026-11-10', 'tgl_akhir' => '2026-11-10', 'form_digital' => '{}']);
        $this->sisip('user_form_digital', ['id_form_digital' => $form, 'nip' => $nip]);
        $kategori = $this->sisip('news_category', ['category' => 'K']);
        $berita   = $this->sisip('news', ['id_news_category' => $kategori, 'title' => 'J', 'news' => 'x', 'publish_at' => '2026-11-10 00:00:00', 'ul_creator' => 1]);
        $this->db->table('pengguna')->insert(['nip' => $nip, 'username' => 'dbv032.cascade', 'user_level' => 2]);
        $this->sisip('news_flag', ['id_user' => (int) $this->db->insertID(), 'id_news' => $berita]);

        $this->db->table('popup')->where('id_popup', $popup)->delete();
        $this->db->table('banner')->where('id_banner', $banner)->delete();
        $this->db->table('form_digital')->where('id_form_digital', $form)->delete();
        $this->db->table('news')->where('id_news', $berita)->delete();

        foreach (['user_popup', 'user_banner', 'user_form_digital', 'news_flag'] as $anak) {
            $this->assertSame(0, $this->db->table($anak)->countAllResults(), $anak);
        }
    }

    public function testDownLaluUpKembali(): void
    {
        $this->naik();
        $this->m->down();
        $this->naik = false;

        foreach (CreateKontenInteraksiFase7::TABEL as $tabel) {
            $this->assertFalse($this->skemaTableExists($tabel), $tabel);
        }

        $this->naik();

        foreach (CreateKontenInteraksiFase7::TABEL as $tabel) {
            $this->assertTrue($this->skemaTableExists($tabel), $tabel);
        }
    }

    public function testSeedJenisDiboIdLegacyDanAmanDiulang(): void
    {
        $this->naik();
        $seed = new SeedJenisDibo();
        $seed->up();
        $seed->up(); // subset identik → dilewati

        $isi = array_map(
            static fn (array $r): array => [(string) $r['jenis_dibo'], (int) $r['order'], (int) $r['status']],
            array_column($this->db->table('jenis_dibo')->orderBy('id_jenis_dibo')->get()->getResultArray(), null, 'id_jenis_dibo'),
        );
        $this->assertSame(array_map(static fn (array $v): array => [$v[0], $v[1], 1], SeedJenisDibo::ISI), $isi);

        $this->db->table('jenis_dibo')->whereIn('id_jenis_dibo', [2, 3, 4, 5, 6, 7, 8])->delete();
        $seed->up(); // sebagian sudah ada (identik) → sisanya diisi
        $this->assertSame(8, $this->db->table('jenis_dibo')->countAllResults());

        $seed->down();
        $this->assertSame(0, $this->db->table('jenis_dibo')->countAllResults(), 'down menghapus 8 id');
    }

    public function testSeedJenisDiboMenolakIsiBerbeda(): void
    {
        $this->naik();
        $this->db->table('jenis_dibo')->insert(['id_jenis_dibo' => 3, 'jenis_dibo' => 'Lain', 'order' => 3, 'status' => 1]);

        try {
            (new SeedJenisDibo())->up();
            $this->fail('seed seharusnya menolak isi berbeda');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('id 3', $e->getMessage());
        }

        $this->assertSame(1, $this->db->table('jenis_dibo')->countAllResults(), 'data tidak diubah');
    }

    public function testKueriAuditBerjalan(): void
    {
        $this->naik();
        $sql   = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(ROOTPATH . 'docs/db-review/sql/DBV-032-audit-konten-interaksi.sql'));
        $tabel = [...CreateKontenInteraksiFase7::TABEL, 'pegawai', 'riwayat_cuti', 'riwayat_kariskarsu', 'riwayat_karpeg', 'riwayat_tb', 'riwayat_ib', 'unit', 'satker'];
        $n     = 0;

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) {
            foreach ($tabel as $t) {
                $q = (string) preg_replace('/(FROM|JOIN)\s+' . $t . '\b/', '$1 ' . $this->db->prefixTable($t), $q);
            }

            try {
                $hasil = $this->db->query($q)->getResultArray();
            } catch (\Throwable $e) {
                throw new RuntimeException($e->getMessage() . "\n" . $q);
            }

            $this->assertSame(['butir', 'jumlah'], array_keys($hasil[0] ?? []), $q);
            $n++;
        }

        $this->assertSame(35, $n);
    }

    /**
     * @param array<string, mixed> $baris
     */
    private function sisip(string $tabel, array $baris): int
    {
        $this->db->table($tabel)->insert($baris);

        return (int) $this->db->insertID();
    }

    private function naik(): void
    {
        $this->m->up();
        $this->naik = true;
    }
}
