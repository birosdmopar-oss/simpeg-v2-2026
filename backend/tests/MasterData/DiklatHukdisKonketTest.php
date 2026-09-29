<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;

/**
 * DBV-005/CR-012 — perilaku master G-06 (diklat, tingkat & jenis hukdis, jenis konket, tanda jasa) lewat engine master
 * generik. G-TC umum (keunikan nama, soft delete, toggle status → dropdown, audit, RBAC) sudah dijalankan untuk kelima
 * master oleh MasterGenericTcTest dan RbacMasterEndpointsTest; di sini hanya aturan khusus G-06
 * (backend/docs/db-review/G-06-diklat-hukdis-konket-tanda-jasa-schema.md Bagian 2.7):
 *
 *  - diklat: pilihan jenis 1–5, urutan & keunikan nama per jenis, filter per jenis;
 *  - tingkat hukdis: `bobot_ipasn` disimpan tetapi tidak pernah dikirim/ditulis (B5);
 *  - jenis hukdis: dropdown ikut status tingkat (statusChain), `masa_sanksi_bulan` opsional 1–255 (K5b);
 *  - jenis konket: `old_id` wajib, unik, bisa diubah (B2); `affect_tukin` wajib 1/2 (K5a);
 *  - baris ber-ID hard-coded legacy tidak dikunci (B6);
 *  - kolom `order` TINYINT (batas 127) dan kolom audit pola `diklat` (updated_at/updated_by saja).
 *
 * Setiap nilai isian (teks/angka) yang ditolak kolom NOT NULL/CHECK di DB harus sudah ditolak validasi (422 pada
 * field-nya): CR-007 tidak menerjemahkan 1048 dan pelanggaran CHECK (3819/4025), jadi nilai itu akan menjadi 500 bila
 * lolos ke DB. Nilai yang dianggap kosong oleh permit_empty (spasi/tab saja, JSON `false`) disimpan NULL; JSON
 * array/objek ditolak 422 oleh engine (CR-011). PK `diklat` TINYINT yang habis juga dijawab 422 (G-06 Bagian 2.7,
 * batas #1 dan #2 yang tertutup setelah merge DBV-004/CR-011).
 *
 * @internal
 */
final class DiklatHukdisKonketTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = MasterDataSeeder::class;

    private const ADMIN_NIP = '198501012010011001';

    private const G06 = ['diklat', 'tingkat-hukdis', 'jenis-hukdis', 'jenis-konket', 'tanda-jasa'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
        $this->asRole(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        $this->clearAuthState();
        parent::tearDown();
    }

    /**
     * Metadata form (GET master/meta) kelima master: label legacy, pilihan select, batas angka, lingkup urutan & filter
     * diklat, induk + statusChain jenis hukdis. `bobot_ipasn` tidak muncul di mana pun. Kolom `order` TINYINT → batas 127.
     */
    public function testMetaDescribesG06Masters(): void
    {
        $meta = array_column($this->json($this->get('api/v1/master/meta'))['data'], null, 'key');

        $expected = [
            'diklat'         => ['Pelatihan', 'nama_diklat', 'Nama Pelatihan', 255, null, ['jenis_diklat'], false],
            'tingkat-hukdis' => ['Tingkat Hukuman Disiplin', 'tingkat_hukdis', 'Tingkat Hukuman Disiplin', 100, null, [], false],
            'jenis-hukdis'   => ['Jenis Hukuman Disiplin', 'jenis_hukdis', 'Jenis Hukuman Disiplin', 255, ['field' => 'id_tingkat_hukdis', 'entity' => 'tingkat-hukdis'], [], true],
            'jenis-konket'   => ['Jenis Konfirmasi Ketidakhadiran', 'jenis_konket', 'Jenis Konfirmasi Ketidakhadiran', 255, null, [], false],
            'tanda-jasa'     => ['Tanda Jasa', 'tanda_jasa', 'Tanda Jasa', 255, null, [], false],
        ];

        foreach ($expected as $key => [$label, $nameField, $nameLabel, $nameMax, $parent, $scope, $chain]) {
            $m = $meta[$key];
            $this->assertSame(
                [$label, true, $nameField, $nameLabel, $nameMax, $parent, 'shift', $scope, $scope, null, $chain, true, true],
                [
                    $m['label'], $m['auto_increment'], $m['name_field'], $m['name_label'], $m['name_max_length'], $m['parent'],
                    $m['order_mode'], $m['order_scope'], $m['filters'], $m['order_max'], $m['status_chain'], $m['has_order'], $m['has_status'],
                ],
                $key,
            );
            $this->assertSame(127, service('masterRegistry')->get($key)->orderMax(), "{$key}: kolom order TINYINT");
            // Panjang kode = batas rule induk/field ref yang merujuk master ini (mis. riwayat_diklat.id_diklat di B-11):
            // `diklat` TINYINT signed → 3 karakter, empat lainnya INT → bawaan 11.
            $this->assertSame($key === 'diklat' ? 3 : 11, $m['id_max_length'], "{$key}: id_max_length");
        }

        $field = static fn (string $name, string $label, string $type, bool $required, ?array $options = null, ?string $hint = null, ?int $min = null, ?int $max = null): array => [
            'name'       => $name,
            'label'      => $label,
            'type'       => $type,
            'required'   => $required,
            'options'    => $options,
            'hint'       => $hint,
            'max_bytes'  => null,
            'min'        => $min,
            'max'        => $max,
            'entity'     => null,
            'depends_on' => null,
        ];
        $options = static fn (array $labels): array => array_map(
            static fn (int $value, string $text): array => ['value' => (string) $value, 'label' => $text],
            array_keys($labels),
            array_values($labels),
        );

        $this->assertSame([
            $field('jenis_diklat', 'Jenis Pelatihan', 'select', true, $options([1 => 'Struktural', 2 => 'Teknis', 3 => 'Fungsional', 4 => 'Prajabatan', 5 => 'Sertifikasi'])),
        ], $meta['diklat']['fields']);
        $this->assertSame([], $meta['tingkat-hukdis']['fields']);
        $this->assertSame([
            $field('masa_sanksi_bulan', 'Masa Sanksi (bulan)', 'int', false, null, 'Opsional. Nilai awal hitung akhir hukuman (TMT + masa); kosongkan bila tanpa masa. Riwayat tetap menyimpan masa per SK.', 1, 255),
        ], $meta['jenis-hukdis']['fields']);
        $this->assertSame([
            $field('old_id', 'Kode Kategori', 'int', true, null, 'Kode yang disimpan di pengajuan konket (absen_ijin.kategori). Unik. Mengubahnya memutus pengajuan lama yang memakai kode ini.', 1, 2147483647),
            $field('affect_tukin', 'Pengaruh ke Tukin', 'select', true, $options([1 => 'Ya', 2 => 'Tidak']), 'Nilai awal saat pengajuan konket; admin tetap bisa mengubahnya per pengajuan.'),
        ], $meta['jenis-konket']['fields']);
        $this->assertSame([], $meta['tanda-jasa']['fields']);

        $this->assertStringNotContainsString('bobot_ipasn', (string) json_encode(array_intersect_key($meta, array_flip(self::G06))));
    }

    /**
     * Dropdown kelima master G-06 = UL_ALL (dipakai riwayat B-11/B-13/B-14/B-17 dan presensi D-06): setiap role login
     * mendapat options berisi entri, termasuk saringan per jenis pelatihan dan per tingkat hukdis. Dikunci eksplisit di
     * sini karena RbacMasterEndpointsTest membaca daftar master admin-only dari config itu sendiri.
     */
    public function testOptionsAreOpenToEveryRole(): void
    {
        foreach (self::G06 as $key) {
            $this->assertTrue(service('masterRegistry')->get($key)->publicOptions, "{$key}: publicOptions");
        }

        foreach (Role::all() as $role) {
            $this->asRole($role);

            foreach (self::G06 as $key) {
                $this->assertNotSame([], $this->optionIds($key), "{$key} role {$role}");
            }

            $this->assertSame(['1', '2'], $this->optionIds('diklat', null, ['jenis_diklat' => '1']), "diklat per jenis role {$role}");
            $this->assertSame(['4'], $this->optionIds('jenis-hukdis', '2'), "jenis-hukdis per tingkat role {$role}");
        }

        $this->withHeaders(['Authorization' => ''])->get('api/v1/master/diklat/options')->assertStatus(401);
    }

    /**
     * diklat: urutan (MAX+1, sisip, pindah jenis) dan keunikan nama berlaku per jenis pelatihan; daftar & dropdown bisa
     * disaring per jenis (nilai filter di luar pilihan → 422); jenis wajib salah satu dari 1–5.
     */
    public function testDiklatIsOrderedAndUniquePerJenis(): void
    {
        $base = 'api/v1/master/diklat';

        // Tambah tanpa order: akhir jenis 1 (3), bukan akhir global.
        $created = $this->sendJson('POST', $base, ['jenis_diklat' => '1', 'nama_diklat' => 'Diklatpim Tingkat II']);
        $created->assertStatus(201);
        $row = $this->json($created)['data'];
        $this->assertSame(['9', '1', 3], [(string) $row['id_diklat'], (string) $row['jenis_diklat'], (int) $row['order']]);

        // Dropdown urut jenis → order → nama; status 10 (id 6) tidak tampil.
        $this->assertSame(['1', '2', '9', '3', '4', '8', '5'], $this->optionIds('diklat'));
        $this->assertSame(['1', '2', '9'], $this->optionIds('diklat', null, ['jenis_diklat' => '1']));
        $this->assertSame(['3'], $this->listIds('diklat', ['jenis_diklat' => '2']));
        $this->assertSame(['6'], $this->listIds('diklat', ['jenis_diklat' => '2', 'status' => '10']));

        foreach (['6', '0', 'abc', ['1']] as $invalid) {
            foreach (["{$base}/options", $base] as $uri) {
                $result = $this->get($uri, ['jenis_diklat' => $invalid]);
                $result->assertStatus(422);
                $this->assertSame(['Filter Jenis Pelatihan tidak valid.'], $this->json($result)['errors']['jenis_diklat'], $uri);
            }
        }

        // Reorder hanya menggeser jenis yang sama.
        $this->sendJson('PATCH', "{$base}/9/order", ['order' => 1])->assertStatus(200);
        $this->assertSame([1, 2, 3, 1, 2, 1], $this->orders('diklat', 'id_diklat', ['9', '1', '2', '3', '6', '8']));

        // Pindah jenis: ditaruh di akhir jenis baru (setelah 3; baris status 10 tidak dihitung), jenis lama dirapatkan.
        $this->sendJson('PUT', "{$base}/2", ['jenis_diklat' => '2'])->assertStatus(200);
        $this->seeInDatabase('diklat', ['id_diklat' => 2, 'jenis_diklat' => 2, 'order' => 2]);
        $this->assertSame(['9', '1'], $this->optionIds('diklat', null, ['jenis_diklat' => '1']));
        $this->assertSame([1, 2], $this->orders('diklat', 'id_diklat', ['9', '1']));

        // Nama unik per jenis: sama beda jenis boleh; sama jenis (beda kapitalisasi) ditolak, termasuk terhadap baris
        // yang sudah dihapus (saran pulihkan) dan saat pindah jenis.
        $other = $this->sendJson('POST', $base, ['jenis_diklat' => '4', 'nama_diklat' => 'Diklatpim Tingkat IV']);
        $other->assertStatus(201);
        $otherId = (string) $this->json($other)['data']['id_diklat'];

        $count = $this->db->table('diklat')->countAllResults();
        $this->assertSame(
            ['Nama Pelatihan "diklatpim tingkat iv" sudah ada dengan kode 1.'],
            $this->rejected('POST', $base, ['jenis_diklat' => '1', 'nama_diklat' => 'diklatpim tingkat iv'], 'nama_diklat'),
        );
        $this->assertStringContainsString(
            'sudah dihapus',
            $this->rejected('POST', $base, ['jenis_diklat' => '2', 'nama_diklat' => 'PELATIHAN TEKNIS LAMA'], 'nama_diklat')[0],
        );
        $this->rejected('PUT', "{$base}/{$otherId}", ['jenis_diklat' => '1'], 'nama_diklat');
        $this->seeInDatabase('diklat', ['id_diklat' => $otherId, 'jenis_diklat' => 4]);

        // Jenis wajib salah satu pilihan 1–5 (CHECK chk_diklat_jenis_diklat tidak pernah tercapai).
        foreach (['0', '6', '', 'abc', '1.0', null, [], ['1']] as $invalid) {
            $this->rejected('POST', $base, ['jenis_diklat' => $invalid, 'nama_diklat' => 'Pelatihan Jenis Salah'], 'jenis_diklat');
        }

        $this->assertSame(['Jenis Pelatihan wajib diisi.'], $this->rejected('POST', $base, ['nama_diklat' => 'Pelatihan Tanpa Jenis'], 'jenis_diklat'));

        foreach (['', null, '7'] as $invalid) {
            $this->rejected('PUT', "{$base}/1", ['jenis_diklat' => $invalid], 'jenis_diklat');
        }

        $this->assertSame($count, $this->db->table('diklat')->countAllResults());
        $this->seeInDatabase('diklat', ['id_diklat' => 1, 'jenis_diklat' => 1]);

        // Jenis 5 (Sertifikasi) sah walau form legacy tidak menawarkannya (keputusan B1).
        $this->sendJson('POST', $base, ['jenis_diklat' => 5, 'nama_diklat' => 'Sertifikasi Manajemen Risiko'])->assertStatus(201);
        $this->assertSame(['5', (string) ($count + 2)], $this->optionIds('diklat', null, ['jenis_diklat' => '5']));
    }

    /**
     * PK `diklat` TINYINT signed (G-06 Bagian 3 #8): setelah id 127 terpakai, tambah pelatihan dijawab 422 dengan
     * penjelasan, bukan 500 — MySQL 8 memberi 1062 PRIMARY (jalur di sini), MariaDB 10.4 memberi 167 (disimulasikan untuk
     * semua master AUTO_INCREMENT di MasterGenericTcTest::testMariaDbAutoIncrementOutOfRangeGives422ForEveryAutoIncrementMaster).
     * Engine CR-011 (DBV-004); menutup batas #1 / C5 G-06 Bagian 2.7. Tanpa baris, audit, maupun geseran urutan yang
     * tertinggal; nama ganda tetap dilaporkan sebagai ganda.
     */
    public function testDiklatFullTinyintKeyGives422(): void
    {
        $base = 'api/v1/master/diklat';
        $this->db->table('diklat')->insert(['id_diklat' => 127, 'jenis_diklat' => 2, 'nama_diklat' => 'Pelatihan Batas Atas', 'order' => 2, 'status' => 1]);
        $rows   = $this->db->table('diklat')->countAllResults();
        $audits = $this->db->table('audit_logs')->countAllResults();

        // Tanpa order (akhir jenis 1) dan dengan order (sisip di awal jenis 2: saudaranya sempat digeser).
        foreach ([['jenis_diklat' => '1', 'nama_diklat' => 'Diklatpim Tingkat II'], ['jenis_diklat' => '2', 'nama_diklat' => 'Pelatihan Teknis Baru', 'order' => 1]] as $body) {
            $result = $this->sendJson('POST', $base, $body);
            $result->assertStatus(422);
            $this->assertSame(
                ['status' => 'error', 'message' => 'Kode Pelatihan sudah mencapai batas maksimal tipe kolom, sehingga entri baru tidak bisa ditambahkan. Hubungi admin database.'],
                $this->json($result),
            );
            $this->dontSeeInDatabase('diklat', ['nama_diklat' => $body['nama_diklat']]);
        }

        $this->rejected('POST', $base, ['jenis_diklat' => '1', 'nama_diklat' => 'DIKLATPIM TINGKAT IV'], 'nama_diklat');

        $this->assertSame($rows, $this->db->table('diklat')->countAllResults());
        $this->assertSame($audits, $this->db->table('audit_logs')->countAllResults());
        $this->assertSame([1, 2], $this->orders('diklat', 'id_diklat', ['3', '127']));
        $this->assertTrue($this->db->transStatus());

        // Entri yang sudah ada tetap bisa diubah.
        $this->sendJson('PUT', "{$base}/127", ['nama_diklat' => 'Pelatihan Batas Atas Diubah'])->assertStatus(200);
    }

    /**
     * tingkat hukdis: `bobot_ipasn` (skor IPASN legacy) tidak dikirim di respons mana pun dan tidak bisa ditulis lewat
     * API: tambah memakai default DB (5), ubah tidak mengubah nilai lama (B5).
     */
    public function testTingkatHukdisKeepsBobotIpasnHidden(): void
    {
        $base = 'api/v1/master/tingkat-hukdis';

        $list = $this->json($this->get($base))['data']['items'];
        $this->assertCount(3, $list);
        $this->assertSame([], array_filter($list, static fn (array $row): bool => array_key_exists('bobot_ipasn', $row)));
        $this->assertSame(['id_tingkat_hukdis', 'tingkat_hukdis', 'order', 'status', 'updated_at', 'updated_by'], array_keys($list[0]));
        $this->assertHidden($this->json($this->get("{$base}/2"))['data']);

        $created = $this->sendJson('POST', $base, ['tingkat_hukdis' => 'Sangat Berat', 'bobot_ipasn' => 99]);
        $created->assertStatus(201);
        $row = $this->json($created)['data'];
        $this->assertHidden($row);
        $this->seeInDatabase('tingkat_hukdis', ['id_tingkat_hukdis' => $row['id_tingkat_hukdis'], 'bobot_ipasn' => 5]);

        $updated = $this->sendJson('PUT', "{$base}/2", ['tingkat_hukdis' => 'Sedang Sekali', 'bobot_ipasn' => 99]);
        $updated->assertStatus(200);
        $this->assertHidden($this->json($updated)['data']);
        $this->seeInDatabase('tingkat_hukdis', ['id_tingkat_hukdis' => 2, 'tingkat_hukdis' => 'Sedang Sekali', 'bobot_ipasn' => 3]);

        foreach ([['PATCH', "{$base}/2/order", ['order' => 1]], ['PATCH', "{$base}/2/status", ['status' => '2']]] as [$method, $uri, $body]) {
            $result = $this->sendJson($method, $uri, $body);
            $result->assertStatus(200);
            $this->assertHidden($this->json($result)['data']);
        }

        $deleted = $this->delete("{$base}/2");
        $deleted->assertStatus(200);
        $this->assertHidden($this->json($deleted)['data']['item']);

        $this->seeInDatabase('tingkat_hukdis', ['id_tingkat_hukdis' => 2, 'status' => 10, 'bobot_ipasn' => 3]);
        $this->seeInDatabase('tingkat_hukdis', ['id_tingkat_hukdis' => 3, 'bobot_ipasn' => 1]);
    }

    /**
     * jenis hukdis: dropdown hanya memuat jenis yang tingkatnya aktif (statusChain, legacy Lm_hukdis.php:221) dan
     * langsung berubah saat status tingkat berubah; induk wajib aktif; `masa_sanksi_bulan` opsional 1–255 (kosong =
     * NULL); nama unik per tingkat.
     */
    public function testJenisHukdisFollowsTingkatChainAndMasaSanksi(): void
    {
        $base = 'api/v1/master/jenis-hukdis';

        $this->assertSame(['1', '2', '3', '4', '5'], $this->sortedOptionIds('jenis-hukdis'));
        $this->assertSame(['4'], $this->optionIds('jenis-hukdis', '2'));

        // Tingkat 2 tidak aktif → jenis 4 hilang dari dropdown (dengan & tanpa ?parent), status jenis 4 sendiri tetap 1.
        $this->sendJson('PATCH', 'api/v1/master/tingkat-hukdis/2/status', ['status' => '2'])->assertStatus(200);
        $this->assertSame(['1', '2', '3', '5'], $this->sortedOptionIds('jenis-hukdis'));
        $this->assertSame([], $this->optionIds('jenis-hukdis', '2'));
        $this->seeInDatabase('jenis_hukdis', ['id_jenis_hukdis' => 4, 'status' => 1]);
        $this->assertSame(
            ['Tingkat Hukuman Disiplin Sedang sedang non-aktif.'],
            $this->rejected('POST', $base, ['id_tingkat_hukdis' => '2', 'jenis_hukdis' => 'Penundaan Pangkat'], 'id_tingkat_hukdis'),
        );

        // Tingkat dihapus (status 10) juga menyembunyikan jenisnya; memulihkan membuka kembali (cache di-invalidate).
        $this->delete('api/v1/master/tingkat-hukdis/3')->assertStatus(200);
        $this->assertSame(['1', '2', '3'], $this->sortedOptionIds('jenis-hukdis'));
        $this->sendJson('PATCH', 'api/v1/master/tingkat-hukdis/2/status', ['status' => '1'])->assertStatus(200);
        $this->sendJson('PATCH', 'api/v1/master/tingkat-hukdis/3/status', ['status' => '1'])->assertStatus(200);
        $this->assertSame(['1', '2', '3', '4', '5'], $this->sortedOptionIds('jenis-hukdis'));
        $this->assertSame(['4'], $this->optionIds('jenis-hukdis', '2'));

        // masa_sanksi_bulan: kosong/null = NULL, 1..255 diterima.
        $expected = ['' => null, '1' => 1, '255' => 255];

        foreach ($expected as $masa => $stored) {
            $name    = "Uji Masa {$masa}";
            $created = $this->sendJson('POST', $base, ['id_tingkat_hukdis' => '1', 'jenis_hukdis' => $name, 'masa_sanksi_bulan' => (string) $masa]);
            $created->assertStatus(201);
            $this->seeInDatabase('jenis_hukdis', ['jenis_hukdis' => $name, 'masa_sanksi_bulan' => $stored]);
        }

        $this->sendJson('POST', $base, ['id_tingkat_hukdis' => '1', 'jenis_hukdis' => 'Uji Masa Null', 'masa_sanksi_bulan' => null])->assertStatus(201);
        $this->seeInDatabase('jenis_hukdis', ['jenis_hukdis' => 'Uji Masa Null', 'masa_sanksi_bulan' => null]);
        $this->sendJson('POST', $base, ['id_tingkat_hukdis' => '3', 'jenis_hukdis' => 'Uji Masa Angka JSON', 'masa_sanksi_bulan' => 36])->assertStatus(201);
        $this->seeInDatabase('jenis_hukdis', ['jenis_hukdis' => 'Uji Masa Angka JSON', 'masa_sanksi_bulan' => 36]);

        // Kosong menurut rule permit_empty (spasi/tab saja, JSON false) lolos tanpa dicek is_natural, jadi harus disimpan
        // NULL, bukan (int) 0 yang melanggar CHECK chk_jenis_hukdis_masa_sanksi_bulan (3819/4025 → 500).
        foreach (['Spasi' => ' ', 'Tab' => "\t", 'False' => false] as $label => $blank) {
            $this->sendJson('POST', $base, ['id_tingkat_hukdis' => '1', 'jenis_hukdis' => "Uji Masa {$label}", 'masa_sanksi_bulan' => $blank])->assertStatus(201);
            $this->seeInDatabase('jenis_hukdis', ['jenis_hukdis' => "Uji Masa {$label}", 'masa_sanksi_bulan' => null]);
        }

        $this->sendJson('PUT', "{$base}/5", ['masa_sanksi_bulan' => " \t "])->assertStatus(200);
        $this->seeInDatabase('jenis_hukdis', ['id_jenis_hukdis' => 5, 'masa_sanksi_bulan' => null]);
        $this->sendJson('PUT', "{$base}/5", ['masa_sanksi_bulan' => '12'])->assertStatus(200);

        // Nilai di luar 1..255 atau bukan bilangan bulat → 422 (CHECK chk_jenis_hukdis_masa_sanksi_bulan / TINYINT UNSIGNED
        // tidak pernah tercapai).
        $count = $this->db->table('jenis_hukdis')->countAllResults();
        $this->assertSame(['Masa Sanksi (bulan) minimal 1.'], $this->rejected('POST', $base, ['id_tingkat_hukdis' => '1', 'jenis_hukdis' => 'Masa Salah', 'masa_sanksi_bulan' => '0'], 'masa_sanksi_bulan'));
        $this->assertSame(['Masa Sanksi (bulan) maksimal 255.'], $this->rejected('POST', $base, ['id_tingkat_hukdis' => '1', 'jenis_hukdis' => 'Masa Salah', 'masa_sanksi_bulan' => '256'], 'masa_sanksi_bulan'));

        foreach (['-1', '1.5', 'abc', 0] as $invalid) {
            $this->rejected('POST', $base, ['id_tingkat_hukdis' => '1', 'jenis_hukdis' => 'Masa Salah', 'masa_sanksi_bulan' => $invalid], 'masa_sanksi_bulan');
        }

        // JSON array/objek lolos permit_empty tanpa dicek is_natural; engine menolaknya 422 sebelum dinormalkan (CR-011),
        // jadi tidak lagi menjadi (int) 0/1 yang melanggar CHECK (dulu 500, G-06 Bagian 2.7 batas #2).
        foreach ([[], ['3'], ['bulan' => 3]] as $invalid) {
            $this->assertSame(
                ['Masa Sanksi (bulan) tidak valid.'],
                $this->rejected('POST', $base, ['id_tingkat_hukdis' => '1', 'jenis_hukdis' => 'Masa Array', 'masa_sanksi_bulan' => $invalid], 'masa_sanksi_bulan'),
            );
            $this->assertSame(['Masa Sanksi (bulan) tidak valid.'], $this->rejected('PUT', "{$base}/4", ['masa_sanksi_bulan' => $invalid], 'masa_sanksi_bulan'));
        }

        $this->rejected('PUT', "{$base}/4", ['masa_sanksi_bulan' => '0'], 'masa_sanksi_bulan');
        $this->assertSame($count, $this->db->table('jenis_hukdis')->countAllResults());
        $this->seeInDatabase('jenis_hukdis', ['id_jenis_hukdis' => 4, 'masa_sanksi_bulan' => 6]);

        // Ubah: dikosongkan = NULL; field tidak dikirim = tidak berubah.
        $this->sendJson('PUT', "{$base}/5", ['jenis_hukdis' => 'Penurunan Jabatan Setingkat Lebih Rendah'])->assertStatus(200);
        $this->seeInDatabase('jenis_hukdis', ['id_jenis_hukdis' => 5, 'masa_sanksi_bulan' => 12]);
        $this->sendJson('PUT', "{$base}/4", ['masa_sanksi_bulan' => ''])->assertStatus(200);
        $this->seeInDatabase('jenis_hukdis', ['id_jenis_hukdis' => 4, 'masa_sanksi_bulan' => null]);

        // Nama unik per tingkat: sama di tingkat lain boleh, di tingkat yang sama ditolak (termasuk saat pindah tingkat).
        $same = $this->sendJson('POST', $base, ['id_tingkat_hukdis' => '2', 'jenis_hukdis' => 'Teguran Lisan']);
        $same->assertStatus(201);
        $sameId = (string) $this->json($same)['data']['id_jenis_hukdis'];
        $this->rejected('POST', $base, ['id_tingkat_hukdis' => '1', 'jenis_hukdis' => 'TEGURAN LISAN'], 'jenis_hukdis');
        $this->rejected('PUT', "{$base}/{$sameId}", ['id_tingkat_hukdis' => '1'], 'jenis_hukdis');
        $this->seeInDatabase('jenis_hukdis', ['id_jenis_hukdis' => $sameId, 'id_tingkat_hukdis' => 2]);
    }

    /**
     * jenis konket: `old_id` (kode kategori = absen_ijin.kategori) wajib bilangan bulat ≥ 1 dan unik global, termasuk
     * terhadap entri non-aktif/dihapus (saran pulihkan); tetap bisa diubah admin (B2, C2).
     */
    public function testJenisKonketOldIdIsRequiredUniqueAndEditable(): void
    {
        $base  = 'api/v1/master/jenis-konket';
        $valid = ['jenis_konket' => 'Tugas Belajar', 'affect_tukin' => '1'];
        $count = $this->db->table('jenis_konket')->countAllResults();

        $this->assertSame(['Kode Kategori wajib diisi.'], $this->rejected('POST', $base, $valid, 'old_id'));
        $this->assertSame(['Kode Kategori minimal 1.'], $this->rejected('POST', $base, $valid + ['old_id' => '0'], 'old_id'));
        $this->assertSame(['Kode Kategori maksimal 2.147.483.647.'], $this->rejected('POST', $base, $valid + ['old_id' => '2147483648'], 'old_id'));

        foreach (['', null, '-1', 'abc', '1.5', 0, [], ['8']] as $invalid) {
            $this->rejected('POST', $base, $valid + ['old_id' => $invalid], 'old_id');
        }

        // Unik global (UNIQUE uq_jenis_konket_old_id): 422 menyebut entri pemiliknya, bukan 1062/500.
        $this->assertSame(
            ['Kode Kategori "8" sudah dipakai Jenis Konfirmasi Ketidakhadiran "Dinas" (kode 2).'],
            $this->rejected('POST', $base, $valid + ['old_id' => '8'], 'old_id'),
        );
        $this->delete("{$base}/6")->assertStatus(200);
        $this->assertStringContainsString('sudah dihapus', $this->rejected('POST', $base, $valid + ['old_id' => '5'], 'old_id')[0]);
        $this->sendJson('PATCH', "{$base}/1/status", ['status' => '2'])->assertStatus(200);
        $this->assertStringContainsString('tidak aktif', $this->rejected('POST', $base, $valid + ['old_id' => '1'], 'old_id')[0]);
        $this->assertSame($count, $this->db->table('jenis_konket')->countAllResults());

        $created = $this->sendJson('POST', $base, $valid + ['old_id' => 21]);
        $created->assertStatus(201);
        $this->assertSame(21, (int) $this->json($created)['data']['old_id']);

        // Bisa diubah (dikelola admin): ke kode milik entri lain ditolak, ke kode bebas diterima, kode sendiri bukan bentrok.
        $this->rejected('PUT', "{$base}/1", ['old_id' => '13'], 'old_id');
        $this->rejected('PUT', "{$base}/1", ['old_id' => ''], 'old_id');
        $this->seeInDatabase('jenis_konket', ['id_jenis_konket' => 1, 'old_id' => 1]);
        $this->sendJson('PUT', "{$base}/1", ['old_id' => '14'])->assertStatus(200);
        $this->sendJson('PUT', "{$base}/1", ['old_id' => '14', 'jenis_konket' => 'Izin Terlambat Masuk'])->assertStatus(200);
        $this->seeInDatabase('jenis_konket', ['id_jenis_konket' => 1, 'old_id' => 14, 'jenis_konket' => 'Izin Terlambat Masuk']);
    }

    /**
     * jenis konket: `affect_tukin` (nilai awal "Pengaruh ke Tukin" pengajuan, K5a) wajib dipilih 1 Ya / 2 Tidak — kosong
     * akan menjadi NULL di kolom NOT NULL dan nilai lain melanggar CHECK chk_jenis_konket_affect_tukin.
     */
    public function testJenisKonketAffectTukinChoices(): void
    {
        $base  = 'api/v1/master/jenis-konket';
        $count = $this->db->table('jenis_konket')->countAllResults();

        $this->assertSame(['Pengaruh ke Tukin wajib diisi.'], $this->rejected('POST', $base, ['old_id' => '30', 'jenis_konket' => 'Tanpa Pengaruh'], 'affect_tukin'));

        foreach (['', null, '0', '3', 'ya', 0, [], ['1']] as $invalid) {
            $this->rejected('POST', $base, ['old_id' => '30', 'jenis_konket' => 'Pengaruh Salah', 'affect_tukin' => $invalid], 'affect_tukin');
        }

        $this->assertSame($count, $this->db->table('jenis_konket')->countAllResults());

        $ya = $this->sendJson('POST', $base, ['old_id' => '30', 'jenis_konket' => 'Pengaruh Ya', 'affect_tukin' => '1']);
        $ya->assertStatus(201);
        $yaId = (string) $this->json($ya)['data']['id_jenis_konket'];
        $this->sendJson('POST', $base, ['old_id' => '31', 'jenis_konket' => 'Pengaruh Tidak', 'affect_tukin' => 2])->assertStatus(201);
        $this->seeInDatabase('jenis_konket', ['id_jenis_konket' => $yaId, 'affect_tukin' => 1]);
        $this->seeInDatabase('jenis_konket', ['old_id' => 31, 'affect_tukin' => 2]);

        $this->sendJson('PUT', "{$base}/{$yaId}", ['affect_tukin' => '2'])->assertStatus(200);
        $this->seeInDatabase('jenis_konket', ['id_jenis_konket' => $yaId, 'affect_tukin' => 2]);

        foreach (['', null, '3'] as $invalid) {
            $this->rejected('PUT', "{$base}/{$yaId}", ['affect_tukin' => $invalid], 'affect_tukin');
        }

        // Field tidak dikirim = tidak berubah.
        $this->sendJson('PUT', "{$base}/{$yaId}", ['jenis_konket' => 'Pengaruh Diubah'])->assertStatus(200);
        $this->seeInDatabase('jenis_konket', ['id_jenis_konket' => $yaId, 'affect_tukin' => 2, 'jenis_konket' => 'Pengaruh Diubah']);
    }

    /**
     * Keputusan B6: baris yang ID-nya di-hard-code kode legacy (G-06 Bagian 2.6) didokumentasikan tetapi tidak dikunci —
     * admin tetap bisa mengganti nama, kode, status, dan menghapusnya. Dampaknya ke B-13/B-17/D-06/SIASN ditinjau di Fase 3.
     */
    public function testHardCodedLegacyRowsAreNotLocked(): void
    {
        $this->sendJson('PUT', 'api/v1/master/tanda-jasa/44', ['tanda_jasa' => 'Lainnya'])->assertStatus(200);
        $this->seeInDatabase('tanda_jasa', ['id_tanda_jasa' => 44, 'tanda_jasa' => 'Lainnya']);

        $this->sendJson('PUT', 'api/v1/master/jenis-konket/2', ['old_id' => '80'])->assertStatus(200);
        $this->seeInDatabase('jenis_konket', ['id_jenis_konket' => 2, 'old_id' => 80]);

        $this->delete('api/v1/master/jenis-konket/4')->assertStatus(200);
        $this->seeInDatabase('jenis_konket', ['id_jenis_konket' => 4, 'status' => 10]);

        $this->sendJson('PATCH', 'api/v1/master/diklat/8/status', ['status' => '2'])->assertStatus(200);
        $this->seeInDatabase('diklat', ['id_diklat' => 8, 'status' => 2]);
        $this->assertNotContains('8', $this->optionIds('diklat'));
    }

    /**
     * Kolom `order` G-06 TINYINT: satu lingkup urutan paling banyak 127 entri tampil. Tambah berikutnya (tanpa atau
     * dengan `order`) → 422 pada `order`, bukan 1264 (422 generik tanpa `errors`) atau terpotong. Entri yang dihapus
     * tidak dihitung.
     */
    public function testOrderCapacityFollowsTinyintColumn(): void
    {
        $rows = [];

        for ($order = 5; $order <= 127; $order++) {
            $rows[] = ['tanda_jasa' => "Tanda Jasa Uji {$order}", 'order' => $order, 'status' => 1];
        }

        $this->db->table('tanda_jasa')->insertBatch($rows);
        $this->assertSame(127, $this->db->table('tanda_jasa')->countAllResults());

        foreach ([[], ['order' => 1], ['order' => 200]] as $extra) {
            $this->assertSame(
                ['Urutan Tanda Jasa sudah mencapai batas maksimal 127.'],
                $this->rejected('POST', 'api/v1/master/tanda-jasa', ['tanda_jasa' => 'Satyalancana Wira Karya'] + $extra, 'order'),
            );
        }

        $this->dontSeeInDatabase('tanda_jasa', ['tanda_jasa' => 'Satyalancana Wira Karya']);
        $this->assertSame([1, 2, 3, 4], $this->orders('tanda_jasa', 'id_tanda_jasa', ['26', '27', '28', '44']));

        // Satu entri dihapus → kapasitas kembali, entri baru di akhir (127).
        $this->delete('api/v1/master/tanda-jasa/27')->assertStatus(200);
        $created = $this->sendJson('POST', 'api/v1/master/tanda-jasa', ['tanda_jasa' => 'Satyalancana Wira Karya']);
        $created->assertStatus(201);
        $this->assertSame(127, (int) $this->json($created)['data']['order']);
    }

    /**
     * Kolom audit pola `diklat` (updated_at NOT NULL ON UPDATE + updated_by, tanpa created_*): diisi aplikasi (UTC,
     * id_pengguna aktor) saat tambah, ubah, dan hapus — hanya pada baris yang diedit. Saudara yang bergeser urutannya
     * tidak di-stamp (ON UPDATE CURRENT_TIMESTAMP tidak ikut terpicu), tetapi tetap tercatat di audit_logs.
     */
    public function testAuditColumnsStampEditedRowOnly(): void
    {
        $adminId = (int) $this->db->table('pengguna')->select('id_pengguna')->where('nip', self::ADMIN_NIP)->get()->getRowArray()['id_pengguna'];
        $now     = '2021-05-06 07:08:09';
        $seeded  = ['updated_at' => '2024-01-01 00:00:00', 'updated_by' => null];
        Time::setTestNow($now, 'UTC');

        $this->sendJson('PATCH', 'api/v1/master/tanda-jasa/44/order', ['order' => 1])->assertStatus(200);
        $this->seeInDatabase('tanda_jasa', ['id_tanda_jasa' => 44, 'order' => 1, 'updated_at' => $now, 'updated_by' => $adminId]);

        foreach (['26' => 2, '27' => 3, '28' => 4] as $id => $order) {
            $this->seeInDatabase('tanda_jasa', ['id_tanda_jasa' => $id, 'order' => $order] + $seeded);
        }

        $this->seeInDatabase('audit_logs', ['entity' => 'tanda_jasa', 'entity_id' => '26', 'event' => 'update', 'nip_actor' => self::ADMIN_NIP]);

        // Tambah: updated_at/updated_by terisi (tidak ada created_*).
        $created = $this->sendJson('POST', 'api/v1/master/diklat', ['jenis_diklat' => '4', 'nama_diklat' => 'Pelatihan Uji Audit']);
        $created->assertStatus(201);
        $row = $this->json($created)['data'];
        $this->assertSame([$now, $adminId], [$row['updated_at'], (int) $row['updated_by']]);
        $this->assertArrayNotHasKey('created_at', $row);
        $this->assertArrayNotHasKey('created_by', $row);

        // Ubah: di-stamp.
        $this->sendJson('PUT', 'api/v1/master/jenis-konket/1', ['jenis_konket' => 'Izin Terlambat Masuk'])->assertStatus(200);
        $this->seeInDatabase('jenis_konket', ['id_jenis_konket' => 1, 'updated_at' => $now, 'updated_by' => $adminId]);

        // Hapus: baris yang dihapus di-stamp, saudara yang dirapatkan tidak.
        $this->delete('api/v1/master/jenis-hukdis/1')->assertStatus(200);
        $this->seeInDatabase('jenis_hukdis', ['id_jenis_hukdis' => 1, 'status' => 10, 'updated_at' => $now, 'updated_by' => $adminId]);
        $this->seeInDatabase('jenis_hukdis', ['id_jenis_hukdis' => 2, 'order' => 1] + $seeded);
        $this->seeInDatabase('jenis_hukdis', ['id_jenis_hukdis' => 3, 'order' => 2] + $seeded);
    }

    /**
     * Kirim permintaan yang harus ditolak validasi: 422 dengan pesan pada $field (bukan 500 dari NOT NULL/CHECK DB).
     *
     * @param array<string, mixed> $body
     *
     * @return list<string> pesan error $field
     */
    private function rejected(string $method, string $uri, array $body, string $field): array
    {
        $result = $this->sendJson($method, $uri, $body);
        $result->assertStatus(422);
        $errors = $this->json($result)['errors'] ?? [];
        $this->assertArrayHasKey($field, $errors, "{$method} {$uri} " . json_encode($body));

        return array_values((array) $errors[$field]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertHidden(array $row): void
    {
        $this->assertArrayHasKey('id_tingkat_hukdis', $row);
        $this->assertArrayNotHasKey('bobot_ipasn', $row);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<string>
     */
    private function listIds(string $entity, array $query): array
    {
        $def    = service('masterRegistry')->get($entity);
        $result = $this->get("api/v1/master/{$entity}", $query);
        $result->assertStatus(200);

        return array_map('strval', array_column($this->json($result)['data']['items'], $def->primaryKey));
    }

    /**
     * @return list<string>
     */
    private function sortedOptionIds(string $entity): array
    {
        $ids = $this->optionIds($entity);
        sort($ids);

        return $ids;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<int>
     */
    private function orders(string $table, string $pk, array $ids): array
    {
        return array_map(
            fn (string $id): int => (int) $this->db->table($table)->where($pk, $id)->get()->getRowArray()['order'],
            $ids,
        );
    }
}
