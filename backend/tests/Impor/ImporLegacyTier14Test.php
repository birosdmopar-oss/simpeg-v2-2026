<?php

declare(strict_types=1);

namespace Tests\Impor;

use App\Libraries\Impor\AturanKonten;
use App\Libraries\Impor\DefinisiTabel;
use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * MAKE-089 — impor tier 14 Fase 7 (konten & interaksi, DBV-032 §10) dengan skema legacy tiruan berbentuk D1 (ENUM, PK
 * komposit, `news_flag` tanpa PK, kolom legacy yang tidak dibawa, collation 0900 di 4 tabel) dan data SINTETIS.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporLegacyTier14Test extends ImporLegacyTestCase
{
    private const TIER = 14;

    private const NIP_A = '000000000000000001';

    private const NIP_B = '000000000000000002';

    private const RAHASIA = 'Kritik pribadi uji';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('pegawai')->insertBatch([
            ['nip' => self::NIP_A, 'nama' => 'Pegawai Uji A', 'tgl_lahir' => '1990-01-01', 'jenis_kelamin' => 1, 'status' => 1],
            ['nip' => self::NIP_B, 'nama' => 'Pegawai Uji B', 'tgl_lahir' => '1991-02-02', 'jenis_kelamin' => 2, 'status' => 1],
        ]);
        $this->db->table('pengguna')->insert(['id_pengguna' => 7, 'username' => 'uji7', 'name' => 'Akun Uji', 'user_level' => 1, 'status' => '1']);

        $this->bentukLegacy();
        $this->isiSumber();
    }

    protected function tearDown(): void
    {
        foreach (['user_popup', 'user_banner', 'news_flag', 'news', 'news_category', 'dibo', 'popup', 'banner', 'skl'] as $t) {
            $this->db->table($t)->where('1 = 1', null, false)->delete();
        }

        $this->db->table('pengguna')->where('id_pengguna', 7)->delete();
        $this->db->table('pegawai')->whereIn('nip', [self::NIP_A, self::NIP_B])->delete();

        parent::tearDown();
    }

    public function testTanpaKeputusanAmbiguGagalDanTidakAdaYangDitulis(): void
    {
        try {
            $this->jalankanImpor([self::TIER], dryRun: true);
            $this->fail('baris ambigu tanpa keputusan wajib gagal');
        } catch (ImporGagal $e) {
            $this->assertStringContainsString('baris ambigu belum diputuskan', $e->getMessage());
            $this->assertStringNotContainsString(self::NIP_A, $e->getMessage());
        }

        $this->assertSame(0, $this->db->table('news')->countAllResults());
    }

    public function testImporTier14DenganKeputusan(): void
    {
        $laporan = $this->jalankanImpor([self::TIER], k: $this->keputusan());

        // news_category: status 0 → 2, allow_ap ENUM nilai string → angka.
        $kat = array_column($this->db->table('news_category')->orderBy('id_news_category')->get()->getResultArray(), null, 'id_news_category');
        $this->assertSame(['1', '2'], [(string) $kat[1]['status'], (string) $kat[2]['status']]);
        $this->assertSame(['1', '2'], [(string) $kat[1]['allow_ap'], (string) $kat[2]['allow_ap']]);
        $this->assertSame(1, $laporan['news_category']['koreksi']['MAKE-089 status 0→2'] ?? null);

        // news: ENUM status/target dari nilai (termasuk '0'), sanitasi, lampiran → kunci, publish_at R1a, id 3 dibuang keputusan.
        $news = array_column($this->db->table('news')->orderBy('id_news')->get()->getResultArray(), null, 'id_news');
        $this->assertSame([1, 2], array_map('intval', array_keys($news)));
        $this->assertSame(['1', '0'], [(string) $news[1]['status'], (string) $news[2]['status']]);
        $this->assertSame(['1', '3'], [(string) $news[1]['target'], (string) $news[2]['target']]);
        $this->assertSame('3', (string) $news[1]['show_notif'], 'show_notif ikut legacy');
        $this->assertStringNotContainsString('<script', (string) $news[1]['news']);
        $this->assertStringContainsString('Halo', (string) $news[1]['news']);
        $this->assertSame(AturanKonten::kunciBerkas('berita', 'news', 'lampiran', '1', 'assets/upload/news/a.pdf', ['pdf']), $news[1]['lampiran']);
        $this->assertMatchesRegularExpression('#^berita/[0-9a-f]{32}\.pdf$#', (string) $news[1]['lampiran']);
        $this->assertNull($news[2]['lampiran']);
        $this->assertSame('2026-01-05 03:00:00', $news[1]['publish_at'], 'stempel WIB → UTC (R1a)');
        $this->assertSame([AturanKonten::DIBUANG_KEPUTUSAN => 1], $laporan['news']['dibuang']);

        // news_flag: PK sintetis; kunci ganda di batas batch → dibuang + dicatat; total seimbang.
        $this->assertSame(2, $this->db->table('news_flag')->countAllResults());
        $this->assertSame([DefinisiTabel::KODE_GANDA => 1], $laporan['news_flag']['dibuang']);
        $this->assertSame(3, $laporan['news_flag']['sumber']);

        // jenis_dibo: seed identik → dilewati; dibo tahun 0000 → NULL, berkas → kunci, id 2 dibuang keputusan.
        $this->assertSame(8, $laporan['jenis_dibo']['dilewati']);
        $dibo = $this->db->table('dibo')->get()->getResultArray();
        $this->assertCount(1, $dibo);
        $this->assertNull($dibo[0]['tahun']);
        $this->assertMatchesRegularExpression('#^dibo/[0-9a-f]{32}\.pdf$#', (string) $dibo[0]['file_1']);
        $this->assertSame(1, $laporan['dibo']['koreksi']['MAKE-089 N2 tahun 0000→NULL'] ?? null);

        // popup: video dilewati (id dicatat), id 3 JSON rusak dibuang keputusan; waktu bisnis WIB tidak dikonversi;
        // target ber-addslashes jadi JSON sah; kolom legacy tidak dibawa.
        $popup = $this->db->table('popup')->get()->getResultArray();
        $this->assertCount(1, $popup);
        $this->assertSame('2026-02-01 08:00:00', $popup[0]['tgl_mulai'], 'waktu bisnis WIB (Jalur A)');
        $this->assertSame(['0'], json_decode((string) $popup[0]['target_uns'], true));
        $this->assertSame(AturanKonten::kunciBerkas('popup', 'popup', 'file', '1', 'assets/upload/popup/p.jpg', AturanKonten::EKSTENSI_GAMBAR), $popup[0]['file']);
        $this->assertSame([AturanKonten::VIDEO . ' (id 2)' => 1, AturanKonten::DIBUANG_KEPUTUSAN => 1], $laporan['popup']['dibuang']);

        // user_popup: anak popup video ikut dibuang; sisanya ditulis dengan PK sintetis.
        $this->assertSame(2, $this->db->table('user_popup')->where('id_popup', 1)->countAllResults());
        $this->assertSame([AturanKonten::IKUT_INDUK => 1], $laporan['user_popup']['dibuang']);

        // banner + user_banner.
        $this->assertSame(1, $this->db->table('banner')->countAllResults());
        $this->assertSame(1, $this->db->table('user_banner')->countAllResults());

        // skl: kunci UNIQUE ganda → id terkecil dipertahankan; data pribadi tidak ada di laporan.
        $this->assertSame([1, 3], array_map('intval', array_column($this->db->table('skl')->orderBy('id_skl')->get()->getResultArray(), 'id_skl')));
        $this->assertSame([DefinisiTabel::KODE_GANDA => 1], $laporan['skl']['dibuang']);

        $teks = (string) json_encode($laporan);
        $this->assertStringNotContainsString(self::RAHASIA, $teks);
        $this->assertStringNotContainsString(self::NIP_A, $teks);

        foreach ($laporan as $kunci => $t) {
            $this->assertSame($t['sumber'], $t['ditulis'] + $t['dilewati'] + array_sum($t['dibuang']), "rekonsiliasi {$kunci}");
        }
    }

    public function testKeputusanSelainBuangDitolak(): void
    {
        $isi                          = $this->isiKeputusan();
        $isi['baris']['news.f7']['3'] = 'impor';

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('hanya boleh "__buang"');

        $this->jalankanImpor([self::TIER], dryRun: true, k: Keputusan::dariArray($isi));
    }

    public function testPathTraversalDanEkstensiDitolak(): void
    {
        $this->assertNull(AturanKonten::kunciBerkas('popup', 'popup', 'file', 1, '../rahasia.jpg', AturanKonten::EKSTENSI_GAMBAR));
        $this->assertNull(AturanKonten::kunciBerkas('popup', 'popup', 'file', 1, '/etc/x.jpg', AturanKonten::EKSTENSI_GAMBAR));
        $this->assertNull(AturanKonten::kunciBerkas('popup', 'popup', 'file', 1, 'assets\\upload\\x.jpg', AturanKonten::EKSTENSI_GAMBAR));
        $this->assertNull(AturanKonten::kunciBerkas('popup', 'popup', 'file', 1, 'assets/upload/x.php', AturanKonten::EKSTENSI_GAMBAR));
        $this->assertSame(
            AturanKonten::kunciBerkas('popup', 'popup', 'file', 1, 'assets/upload/popup/a.JPG', AturanKonten::EKSTENSI_GAMBAR),
            AturanKonten::kunciBerkas('popup', 'popup', 'file', 1, 'assets/upload/popup/a.JPG', AturanKonten::EKSTENSI_GAMBAR),
            'kunci deterministik',
        );
        $this->assertTrue(AturanKonten::targetSah('["0","12","sat_5"]', 'target_uns'));
        $this->assertFalse(AturanKonten::targetSah('["unit 1"]', 'target_uns'));
        $this->assertFalse(AturanKonten::targetSah('bukan json', 'target_peg'));
    }

    /**
     * Ganti tabel sumber tiruan (dibuat LIKE v2 oleh ImporLegacyTestCase) dengan bentuk legacy D1.
     */
    private function bentukLegacy(): void
    {
        $l = "`{$this->lgc}`";

        foreach (['user_popup', 'user_banner', 'popup', 'banner', 'news_flag', 'news', 'news_category', 'dibo', 'jenis_dibo', 'skl'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$l}.`{$t}`");
        }

        $ddl = [
            "CREATE TABLE {$l}.`news_category` (`id_news_category` int NOT NULL AUTO_INCREMENT, `category` varchar(100) NOT NULL, `allow_ap` enum('1','2') NOT NULL DEFAULT '2', `keterangan` tinytext, `order` tinyint NOT NULL DEFAULT 0, `status` tinyint(1) NOT NULL DEFAULT 1, `created_at` datetime NOT NULL, `updated_at` datetime DEFAULT NULL, `updated_by` int DEFAULT NULL, PRIMARY KEY (`id_news_category`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE {$l}.`news` (`id_news` int NOT NULL AUTO_INCREMENT, `id_news_category` int DEFAULT NULL, `id_unit_target` int DEFAULT NULL, `id_satker_target` int DEFAULT NULL, `ul_creator` tinyint DEFAULT NULL, `id_unit_creator` int DEFAULT NULL, `id_satker_creator` int DEFAULT NULL, `category` varchar(250) DEFAULT NULL, `title` varchar(250) NOT NULL, `news` text NOT NULL, `target` enum('1','2','3') NOT NULL DEFAULT '1', `unit_satker_target` varchar(255) DEFAULT NULL, `lampiran` varchar(250) DEFAULT NULL, `publish_at` datetime NOT NULL, `status` enum('0','1','2') NOT NULL DEFAULT '0', `reason_note` tinytext, `show_notif` tinyint NOT NULL DEFAULT 0, `notif_date` datetime DEFAULT NULL, `created_at` datetime NOT NULL, `created_by` int DEFAULT NULL, `approved_at` datetime DEFAULT NULL, `approved_by` int DEFAULT NULL, `updated_at` datetime DEFAULT NULL, `updated_by` int DEFAULT NULL, PRIMARY KEY (`id_news`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE {$l}.`news_flag` (`id_user` int NOT NULL, `id_news` int NOT NULL, `created_at` datetime DEFAULT NULL) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE {$l}.`jenis_dibo` (`id_jenis_dibo` tinyint NOT NULL AUTO_INCREMENT, `jenis_dibo` varchar(255) NOT NULL, `order` tinyint NOT NULL DEFAULT 0, `keterangan` tinytext, `status` tinyint(1) NOT NULL DEFAULT 1, `created_at` datetime NOT NULL, `updated_at` datetime DEFAULT NULL, PRIMARY KEY (`id_jenis_dibo`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE {$l}.`dibo` (`id_dibo` int NOT NULL AUTO_INCREMENT, `id_jenis_dibo` tinyint DEFAULT NULL, `nomor` varchar(50) DEFAULT NULL, `tahun` year DEFAULT NULL, `tentang` mediumtext NOT NULL, `file_1` varchar(255) NOT NULL, `file_2` varchar(255) DEFAULT NULL, `file_3` varchar(255) DEFAULT NULL, `keterangan` mediumtext, `status` tinyint NOT NULL DEFAULT 1, `created_at` datetime NOT NULL, `updated_at` datetime DEFAULT NULL, `updated_by` int DEFAULT NULL, PRIMARY KEY (`id_dibo`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "CREATE TABLE {$l}.`popup` (`id_popup` int NOT NULL AUTO_INCREMENT, `tgl_mulai` datetime NOT NULL, `tgl_akhir` datetime NOT NULL, `file` varchar(255) NOT NULL, `target` tinyint NOT NULL DEFAULT 1, `type` tinyint NOT NULL DEFAULT 1, `url` varchar(255) DEFAULT NULL, `id_unit_satker` varchar(20) DEFAULT NULL, `unit_satker` varchar(255) DEFAULT NULL, `target_uns` varchar(255) DEFAULT NULL, `target_uns_desc` mediumtext, `target_peg` mediumtext, `target_peg_desc` mediumtext, `excl_peg` mediumtext, `excl_peg_desc` mediumtext, `keterangan` tinytext, `order` int NOT NULL DEFAULT 1, `created_at` datetime NOT NULL, `updated_at` datetime DEFAULT NULL, `updated_by` int DEFAULT NULL, PRIMARY KEY (`id_popup`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE {$l}.`user_popup` (`nip` varchar(30) NOT NULL, `id_popup` int NOT NULL, PRIMARY KEY (`nip`,`id_popup`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE {$l}.`banner` (`id_banner` int NOT NULL AUTO_INCREMENT, `tgl_mulai` date NOT NULL, `tgl_akhir` date NOT NULL, `file` varchar(255) NOT NULL, `target` tinyint NOT NULL DEFAULT 1, `type` tinyint NOT NULL DEFAULT 1, `url` varchar(255) DEFAULT NULL, `id_unit_satker` varchar(20) DEFAULT NULL, `unit_satker` varchar(255) DEFAULT NULL, `target_uns` varchar(255) DEFAULT NULL, `target_uns_desc` mediumtext, `target_peg` mediumtext, `target_peg_desc` mediumtext, `excl_peg` mediumtext, `excl_peg_desc` mediumtext, `keterangan` tinytext, `order` int NOT NULL DEFAULT 1, `created_at` datetime NOT NULL, `updated_at` datetime DEFAULT NULL, `updated_by` int DEFAULT NULL, PRIMARY KEY (`id_banner`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE {$l}.`user_banner` (`nip` varchar(30) NOT NULL, `id_banner` int NOT NULL, PRIMARY KEY (`nip`,`id_banner`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE {$l}.`skl` (`id_skl` int NOT NULL AUTO_INCREMENT, `nip` varchar(30) NOT NULL, `jenis_rwy` tinyint NOT NULL, `id_rwy` int NOT NULL, `rate` tinyint NOT NULL DEFAULT 1, `kritik_saran` tinytext, `created_at` datetime NOT NULL, `created_by` int DEFAULT NULL, PRIMARY KEY (`id_skl`), KEY `nip_jenis_rwy_id_rwy` (`nip`,`jenis_rwy`,`id_rwy`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($ddl as $sql) {
            $this->db->query($sql);
        }
    }

    private function isiSumber(): void
    {
        $t = '2026-01-05 10:00:00';
        $s = $this->src;

        $s->table('news_category')->insertBatch([
            ['id_news_category' => 1, 'category' => 'Umum', 'allow_ap' => '1', 'keterangan' => null, 'order' => 1, 'status' => 1, 'created_at' => $t],
            ['id_news_category' => 2, 'category' => 'Lama', 'allow_ap' => '2', 'keterangan' => null, 'order' => 2, 'status' => 0, 'created_at' => $t],
        ]);
        $berita = static fn (int $id, array $k): array => $k + [
            'id_news'    => $id, 'id_news_category' => 1, 'title' => "Berita {$id}", 'news' => '<p>Isi</p>', 'target' => '1', 'lampiran' => null,
            'publish_at' => $t, 'status' => '1', 'show_notif' => 0, 'created_at' => $t, 'category' => 'Umum', 'id_satker_target' => null,
        ];
        $s->table('news')->insertBatch([
            $berita(1, ['news' => '<p>Halo</p><script>alert(1)</script>', 'lampiran' => 'assets/upload/news/a.pdf', 'show_notif' => 3]),
            $berita(2, ['status' => '0', 'target' => '3', 'id_satker_target' => 5]),
            $berita(3, ['lampiran' => '../etc/passwd.pdf']),
        ]);
        $s->table('news_flag')->insertBatch([
            ['id_user' => 7, 'id_news' => 1, 'created_at' => $t],
            ['id_user' => 7, 'id_news' => 2, 'created_at' => $t],
            ['id_user' => 7, 'id_news' => 2, 'created_at' => $t],
        ]);
        $this->src->query("INSERT INTO jenis_dibo (id_jenis_dibo, jenis_dibo, `order`, keterangan, status, created_at) SELECT id_jenis_dibo, jenis_dibo, `order`, keterangan, status, '{$t}' FROM `{$this->db->getDatabase()}`.`{$this->db->prefixTable('jenis_dibo')}`");
        $s->table('dibo')->insertBatch([
            ['id_dibo' => 1, 'id_jenis_dibo' => 1, 'nomor' => '1', 'tahun' => '0000', 'tentang' => 'Uji', 'file_1' => 'assets/upload/dibo/x.pdf', 'file_2' => null, 'status' => 1, 'created_at' => $t],
            ['id_dibo' => 2, 'id_jenis_dibo' => 2, 'nomor' => '2', 'tahun' => '2020', 'tentang' => 'Uji', 'file_1' => 'assets/upload/dibo/y.exe', 'file_2' => null, 'status' => 1, 'created_at' => $t],
        ]);
        $popup = static fn (int $id, array $k): array => $k + [
            'id_popup' => $id, 'tgl_mulai' => '2026-02-01 08:00:00', 'tgl_akhir' => '2026-02-02 17:00:00', 'file' => 'assets/upload/popup/p.jpg',
            'target'   => 1, 'type' => 1, 'target_uns' => '[\\"0\\"]', 'target_peg' => null, 'created_at' => $t,
        ];
        $s->table('popup')->insertBatch([$popup(1, []), $popup(2, ['type' => 2]), $popup(3, ['target_peg' => 'bukan json'])]);
        $s->table('user_popup')->insertBatch([
            ['nip' => self::NIP_A, 'id_popup' => 1], ['nip' => self::NIP_B, 'id_popup' => 1], ['nip' => self::NIP_A, 'id_popup' => 2],
        ]);
        $s->table('banner')->insert(['id_banner' => 1, 'tgl_mulai' => '2026-02-01', 'tgl_akhir' => '2026-02-10', 'file' => 'assets/upload/banner/b.png', 'target' => 1, 'type' => 1, 'target_uns' => '["sat_5"]', 'created_at' => $t]);
        $s->table('user_banner')->insert(['nip' => self::NIP_A, 'id_banner' => 1]);
        $s->table('skl')->insertBatch([
            ['id_skl' => 1, 'nip' => self::NIP_A, 'jenis_rwy' => 1, 'id_rwy' => 10, 'rate' => 5, 'kritik_saran' => self::RAHASIA, 'created_at' => $t],
            ['id_skl' => 2, 'nip' => self::NIP_A, 'jenis_rwy' => 1, 'id_rwy' => 10, 'rate' => 4, 'kritik_saran' => null, 'created_at' => $t],
            ['id_skl' => 3, 'nip' => self::NIP_B, 'jenis_rwy' => 2, 'id_rwy' => 11, 'rate' => 3, 'kritik_saran' => null, 'created_at' => $t],
        ]);
    }

    private function keputusan(): Keputusan
    {
        return Keputusan::dariArray($this->isiKeputusan());
    }

    /**
     * Putusan contoh (dibuat test): berita lampiran traversal, dibo ekstensi .exe, popup JSON rusak → "__buang".
     *
     * @return array{baris: array<string, array<int|string, string>>}
     */
    private function isiKeputusan(): array
    {
        return ['baris' => [
            'news.f7'  => ['3' => AturanKonten::BUANG],
            'dibo.f7'  => ['2' => AturanKonten::BUANG],
            'popup.f7' => ['3' => AturanKonten::BUANG],
        ]];
    }
}
