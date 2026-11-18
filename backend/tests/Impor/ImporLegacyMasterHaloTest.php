<?php

declare(strict_types=1);

namespace Tests\Impor;

use App\Libraries\Impor\DaftarTabel;
use App\Libraries\Impor\DefinisiTabel;
use App\Libraries\Impor\ImporGagal;
use App\Libraries\Impor\Keputusan;
use App\Libraries\Impor\PengimporLegacy;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Support\Impor\ImporLegacyTestCase;

/**
 * T12b master Halo Simpeg (MAKE-097; DBV-028 §2/§8 N1/N2/N4/Q2/Q9/Q10, K-M11/K-M12, F8-21) dengan data SINTETIS:
 * `id_user` disalin apa adanya, yatim dibuang + dicatat per tabel, email NULL → '', stempel R1a, `contact` di-trim,
 * `officer_category` yatim (petugas/kategori) dibuang dan jumlahnya ada di laporan, PK `id` dicocokkan/dibangkitkan.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class ImporLegacyMasterHaloTest extends ImporLegacyTestCase
{
    private const TIER = 12;

    private int $akunA;

    private int $akunB;

    protected function setUp(): void
    {
        parent::setUp();

        // Bentuk legacy: tanpa CHECK, email petugas boleh NULL (tanpa NOT NULL v2).
        foreach (['help_desk_contact', 'support_officer', 'officer_category', 'question_category'] as $t) {
            $this->lepasCheck($t);
        }

        $this->src->query('ALTER TABLE `support_officer` MODIFY `email` VARCHAR(150) NULL');

        // Akun v2 hasil T8 (id legacy disalin apa adanya ke `id_pengguna`).
        $this->db->table('pengguna')->insertBatch([
            ['id_pengguna' => 21, 'username' => 'make097.a', 'user_level' => 1],
            ['id_pengguna' => 22, 'username' => 'make097.b', 'user_level' => 1],
        ]);
        $this->akunA = 21;
        $this->akunB = 22;

        $this->src->table('question_category')->insertBatch([
            ['id_question_category' => 3, 'question_category' => 'Kepegawaian', 'status' => 1],
            ['id_question_category' => 4, 'question_category' => 'Gaji', 'status' => 1],
        ]);

        $this->src->table('help_desk_contact')->insertBatch([
            ['id_help_desk_contact' => 1, 'name' => 'Helpdesk Pusat', 'contact' => ' 0800111 ', 'email' => null, 'status' => 1, 'created_at' => '2026-01-05 10:00:00'],
            ['id_help_desk_contact' => 2, 'name' => 'Helpdesk Cadangan', 'contact' => '0800222', 'email' => 'cadangan@contoh.test', 'status' => 0, 'created_at' => null],
        ]);

        $this->src->table('support_officer')->insertBatch([
            ['id_support_officer' => 5, 'id_user' => $this->akunA, 'email' => 'a@contoh.test', 'no_hp' => '0811', 'status' => 1, 'created_at' => '2026-01-05 10:00:00'],
            ['id_support_officer' => 6, 'id_user' => $this->akunB, 'email' => null, 'no_hp' => null, 'status' => 0, 'created_at' => '2026-01-05 10:00:00'],
            ['id_support_officer' => 7, 'id_user' => 999, 'email' => 'yatim@contoh.test', 'no_hp' => null, 'status' => 1, 'created_at' => null],
            ['id_support_officer' => 8, 'id_user' => 0, 'email' => 'kosong@contoh.test', 'no_hp' => null, 'status' => 1, 'created_at' => null],
        ]);

        $this->src->table('officer_category')->insertBatch([
            ['id' => 40, 'id_support_officer' => 5, 'id_question_category' => 3, 'created_at' => '2026-01-05 10:00:00'],
            ['id' => 41, 'id_support_officer' => 5, 'id_question_category' => 4, 'created_at' => null],
            ['id' => 42, 'id_support_officer' => 6, 'id_question_category' => 3, 'created_at' => null],
            ['id' => 43, 'id_support_officer' => 7, 'id_question_category' => 3, 'created_at' => null],
            ['id' => 44, 'id_support_officer' => 5, 'id_question_category' => 77, 'created_at' => null],
            ['id' => 45, 'id_support_officer' => 99, 'id_question_category' => 4, 'created_at' => null],
        ]);
    }

    public function testMasterHaloDiimporDenganLaporanPerTabel(): void
    {
        $laporan = $this->jalankanImpor([self::TIER]);

        $kontak = $laporan['help_desk_contact'];
        $this->assertSame([2, 2, []], [$kontak['sumber'], $kontak['ditulis'], $kontak['dibuang']]);
        $this->assertSame('0800111', $this->db->table('help_desk_contact')->where('id_help_desk_contact', 1)->get()->getRow()->contact, 'N4: contact di-trim');
        $this->assertNull($this->db->table('help_desk_contact')->where('id_help_desk_contact', 1)->get()->getRow()->email, 'email kontak nullable tetap NULL');
        $this->assertSame('2026-01-05 03:00:00', $this->db->table('help_desk_contact')->where('id_help_desk_contact', 1)->get()->getRow()->created_at, 'N2: R1a WIB → UTC');
        $this->assertNull($this->db->table('help_desk_contact')->where('id_help_desk_contact', 2)->get()->getRow()->created_at);

        $petugas = $laporan['support_officer'];
        $this->assertSame(4, $petugas['sumber']);
        $this->assertSame(2, $petugas['ditulis']);
        $this->assertEqualsCanonicalizing([DefinisiTabel::kodeYatimBuang('id_user') => 1, DaftarTabel::KODE_HALO_AKUN_KOSONG => 1], $petugas['dibuang']);
        $this->assertSame(1, $petugas['koreksi'][DaftarTabel::KODE_HALO_EMAIL_NULL] ?? null, 'N1: email NULL dicatat');
        $this->assertSame($petugas['sumber'], $petugas['ditulis'] + $petugas['dilewati'] + array_sum($petugas['dibuang']));

        $so = $this->db->table('support_officer')->orderBy('id_support_officer')->get()->getResultArray();
        $this->assertSame([5, 6], array_map('intval', array_column($so, 'id_support_officer')), 'PK legacy tetap');
        $this->assertSame([$this->akunA, $this->akunB], array_map('intval', array_column($so, 'id_user')), 'K-M11: id_user disalin apa adanya');
        $this->assertSame(['a@contoh.test', ''], array_column($so, 'email'));
        $this->assertSame('2026-01-05 03:00:00', $so[0]['created_at'], 'N2: R1a WIB → UTC');

        $oc = $laporan['officer_category'];
        $this->assertSame(6, $oc['sumber']);
        $this->assertSame(3, $oc['ditulis']);
        $this->assertEqualsCanonicalizing([
            DefinisiTabel::kodeYatimBuang('id_support_officer')   => 2,
            DefinisiTabel::kodeYatimBuang('id_question_category') => 1,
        ], $oc['dibuang'], 'Q9: yatim ke petugas (termasuk petugas yang dibuang) dan ke kategori tampil di laporan');
        $this->assertSame($oc['sumber'], $oc['ditulis'] + $oc['dilewati'] + array_sum($oc['dibuang']));

        $pasangan = array_map(
            static fn (array $r): string => "{$r['id']}:{$r['id_support_officer']}-{$r['id_question_category']}",
            $this->db->table('officer_category')->orderBy('id')->get()->getResultArray(),
        );
        $this->assertSame(['40:5-3', '41:5-4', '42:6-3'], $pasangan, 'Q10: kolom id legacy bernama sama → disalin');
    }

    public function testPkOfficerCategoryLegacyBedaNamaDibangkitkanUlang(): void
    {
        // Q10: bila salinan legacy tidak punya kolom `id`, AUTO_INCREMENT v2 membangkitkan PK; skema v2 tidak berubah.
        $this->src->query('ALTER TABLE `officer_category` DROP PRIMARY KEY, CHANGE `id` `id_officer_category` INT NOT NULL, ADD PRIMARY KEY (`id_officer_category`)');

        $laporan = $this->jalankanImpor([self::TIER]);

        $this->assertSame(3, $laporan['officer_category']['ditulis']);
        $this->assertSame(3, $this->db->table('officer_category')->where('id IS NOT NULL')->countAllResults());
        $this->assertSame(
            ['5-3', '5-4', '6-3'],
            array_map(static fn (array $r): string => "{$r['id_support_officer']}-{$r['id_question_category']}", $this->db->table('officer_category')->orderBy('id_support_officer')->orderBy('id_question_category')->get()->getResultArray()),
        );
    }

    public function testPasanganGandaDibuangDanDicatat(): void
    {
        $this->src->query('ALTER TABLE `officer_category` DROP INDEX `uk_officer_category`');
        $this->src->table('officer_category')->insert(['id' => 46, 'id_support_officer' => 5, 'id_question_category' => 3, 'created_at' => null]);

        $laporan = $this->jalankanImpor([self::TIER]);

        $this->assertSame(1, $laporan['officer_category']['dibuang'][DefinisiTabel::KODE_GANDA] ?? null, 'Q-H3 WAJIB_0; bila tetap ada, dibuang + dicatat');
        $this->assertSame(3, $laporan['officer_category']['ditulis']);
    }

    public function testPanjangMelebihiKolomV2Gagal(): void
    {
        // Q2: Q-H9 > 0 wajib diputus sebelum T12b; bila terlewat, impor gagal (tanpa nilai baris di pesan).
        $this->src->query('ALTER TABLE `help_desk_contact` MODIFY `contact` VARCHAR(40) NOT NULL');
        $this->src->table('help_desk_contact')->insert(['id_help_desk_contact' => 3, 'name' => 'Panjang', 'contact' => str_repeat('9', 25), 'status' => 1]);

        $this->expectException(ImporGagal::class);
        $this->expectExceptionMessage('Panjang help_desk_contact.contact melebihi 20 karakter.');
        $this->jalankanImpor([self::TIER], dryRun: true);
    }

    public function testDryRunTidakMenulis(): void
    {
        $laporan = $this->jalankanImpor([self::TIER], dryRun: true);

        $this->assertSame(4, $laporan['support_officer']['sumber']);
        $this->assertSame(0, $this->db->table('support_officer')->countAllResults());
        $this->assertSame(0, $this->db->table('officer_category')->countAllResults());
        $this->assertSame(0, $this->db->table('help_desk_contact')->countAllResults());
    }

    public function testStatusLegacyStringDanKeputusan(): void
    {
        // R4/R6: status legacy varchar '1'/'0' → TINYINT; '2' tanpa keputusan → gagal; dengan `status.support_officer` → lolos.
        $this->src->query('ALTER TABLE `support_officer` MODIFY `status` VARCHAR(2) NOT NULL');
        $this->src->query('ALTER TABLE `help_desk_contact` MODIFY `status` VARCHAR(2) NOT NULL');
        $this->src->table('support_officer')->where('id_support_officer', 6)->update(['status' => '2']);

        try {
            $this->jalankanImpor([self::TIER], dryRun: true);
            $this->fail('status di luar 0/1 tanpa keputusan wajib gagal');
        } catch (ImporGagal $e) {
            $this->assertStringContainsString('support_officer', $e->getMessage());
        }

        $this->jalankanImpor([self::TIER], k: Keputusan::dariArray(['status' => ['support_officer' => ['2' => 0]]]));

        $status = array_column($this->db->table('support_officer')->orderBy('id_support_officer')->get()->getResultArray(), 'status', 'id_support_officer');
        $this->assertSame(['5' => '1', '6' => '0'], array_map('strval', $status));
        $this->assertSame('0', (string) $this->db->table('help_desk_contact')->where('id_help_desk_contact', 2)->get()->getRow('status'));
    }

    public function testRujukanKosongDanYatimDuaArahDihitungSekali(): void
    {
        // R1/R4: kunci NULL/0 → `rujukan kosong` (sekali, tidak dihitung ganda); yatim dua arah dihitung tepat sekali;
        // officer_category milik petugas yang dibuang karena id_user 0 ikut dibuang. Batch 2 → NULL di batas batch.
        $this->src->query('ALTER TABLE `officer_category` MODIFY `id_support_officer` INT NULL, MODIFY `id_question_category` INT NULL');
        $this->src->table('officer_category')->insertBatch([
            ['id' => 50, 'id_support_officer' => null, 'id_question_category' => 3, 'created_at' => null],
            ['id' => 51, 'id_support_officer' => null, 'id_question_category' => 4, 'created_at' => null],
            ['id' => 52, 'id_support_officer' => 5, 'id_question_category' => null, 'created_at' => null],
            ['id' => 53, 'id_support_officer' => 0, 'id_question_category' => 3, 'created_at' => null],
            ['id' => 54, 'id_support_officer' => 99, 'id_question_category' => 77, 'created_at' => null],
            ['id' => 55, 'id_support_officer' => 8, 'id_question_category' => 4, 'created_at' => null],
        ]);

        $oc = $this->jalankanImpor([self::TIER])['officer_category'];

        $this->assertSame(12, $oc['sumber']);
        $this->assertSame(3, $oc['ditulis']);
        $yatimSo = $oc['dibuang'][DefinisiTabel::kodeYatimBuang('id_support_officer')] ?? 0;
        $yatimQc = $oc['dibuang'][DefinisiTabel::kodeYatimBuang('id_question_category')] ?? 0;
        $this->assertSame(4, $oc['dibuang'][DaftarTabel::KODE_HALO_RUJUKAN_KOSONG] ?? null, 'NULL/0 (50–53) sekali, tidak dihitung ganda');
        $this->assertSame(5, $yatimSo + $yatimQc, 'yatim 43/44/45/54/55: baris dua arah (54) dihitung tepat sekali');
        $this->assertGreaterThanOrEqual(3, $yatimSo, 'termasuk 55 milik petugas yang dibuang karena id_user 0');
        $this->assertGreaterThanOrEqual(1, $yatimQc);
        $this->assertArrayNotHasKey(DefinisiTabel::KODE_GANDA, $oc['dibuang']);
        $this->assertSame($oc['sumber'], $oc['ditulis'] + $oc['dilewati'] + array_sum($oc['dibuang']));
    }

    public function testN1HanyaUntukBarisYangDitulis(): void
    {
        // R2: petugas yatim ber-email NULL tidak terhitung N1; email spasi-saja → '' tanpa kode.
        $this->src->table('support_officer')->where('id_support_officer', 7)->update(['email' => null]);
        $this->src->table('support_officer')->where('id_support_officer', 5)->update(['email' => '  a@x.id  ']);
        $this->db->table('pengguna')->insert(['id_pengguna' => 23, 'username' => 'make097.c', 'user_level' => 1]);
        $this->src->table('support_officer')->insert(['id_support_officer' => 9, 'id_user' => 23, 'email' => '   ', 'no_hp' => null, 'status' => 1, 'created_at' => null]);

        $so = $this->jalankanImpor([self::TIER])['support_officer'];

        $this->assertSame(1, $so['koreksi'][DaftarTabel::KODE_HALO_EMAIL_NULL] ?? null);
        $this->assertSame('', $this->db->table('support_officer')->where('id_support_officer', 9)->get()->getRow('email'));
        $this->assertSame('a@x.id', $this->db->table('support_officer')->where('id_support_officer', 5)->get()->getRow('email'), 'V3: spasi tepi di-trim tanpa kode');
    }

    public function testKeysetPkSintetisHanyaMembungkusKunciNullableDiSumber(): void
    {
        // K-1 (review DB MAKE-097): kunci NOT NULL di sumber (mis. tier 14 `news_flag`) tetap kolom asli → indeks terpakai,
        // tanpa filesort per batch; hanya kunci NULLABLE di sumber yang dibungkus COALESCE ke sentinel.
        $def   = new DefinisiTabel('news_flag', 'id_news_flag', 'uji K-1', statusDomain: null, kunciUrut: ['id_user', 'id_news'], pkSintetis: true);
        $kolom = [
            'id_user' => ['src' => 'id_user', 'tipeSumber' => 'int', 'nullSumber' => false],
            'id_news' => ['src' => 'id_news', 'tipeSumber' => 'varchar', 'nullSumber' => true],
        ];
        $urut = new ReflectionMethod(PengimporLegacy::class, 'urutKunci');
        /** @var array<string, int|string> $sentinel */
        $sentinel = [];
        $sql      = (string) $urut->invokeArgs(null, [$this->src, $kolom, $def, ['id_user', 'id_news'], &$sentinel]);

        $this->assertSame($this->src->escapeIdentifiers('id_user') . ", COALESCE({$this->src->escapeIdentifiers('id_news')}, '')", $sql);
        $this->assertSame(['id_news' => ''], $sentinel);

        $kolom['id_news']['nullSumber'] = false;
        /** @var array<string, int|string> $sentinel */
        $sentinel = [];
        $sql      = (string) $urut->invokeArgs(null, [$this->src, $kolom, $def, ['id_user', 'id_news'], &$sentinel]);
        $this->assertStringNotContainsString('COALESCE', $sql);
        $this->assertSame([], $sentinel);
    }
}
