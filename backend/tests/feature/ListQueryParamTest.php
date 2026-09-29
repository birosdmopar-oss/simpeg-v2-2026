<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\Role;
use App\Libraries\ListQuery;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;
use Tests\Support\MasterUjiTestTrait;

/**
 * ISSUE-019 — parameter query endpoint daftar (MasterService::list dan UserService::list) lewat ListQuery.
 *
 * Sebelumnya query string berbentuk array (`?search[]=a`, PHP sah) jatuh ke `(string) $array` →
 * ErrorException "Array to string conversion" → 500, dan `?page=99999999999999999999` membuat offset
 * (page - 1) * per_page meluap int → TypeError di BaseBuilder::limit() → 500. Keduanya sekarang 422 dengan
 * errors per key (ADR-001). Halaman valid yang melewati jumlah data tetap 200 dengan daftar kosong.
 *
 * Pencarian kedua daftar juga meng-escape wildcard LIKE (`%`, `_`, `!`) seperti FaqService, sehingga
 * `?search=%` mencari karakter '%' dan tidak lagi mencocokkan seluruh baris.
 *
 * @internal
 */
final class ListQueryParamTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;
    use MasterUjiTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = MasterDataSeeder::class;

    /** Master tanpa induk (6 entri seed). */
    private const MASTER = 'api/v1/master/agama';

    /** Master berinduk, untuk parameter `parent`. */
    private const KECAMATAN = 'api/v1/master/kecamatan';

    private const USERS = 'api/v1/auth/users';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
        $this->asRole(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        // Master UJI hanya dipasang di satu test, tetapi dilepas tanpa syarat: useMasterUji() mengubah
        // RouteCollection & registry yang dipakai bersama antar test dalam satu proses.
        $this->forgetMasterUji();
        $this->clearAuthState();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1. Bentuk array → 422 per key (bukan 500)
    // ------------------------------------------------------------------

    public function testArrayShapedListParamsAreRejectedPerKeyInsteadOfServerError(): void
    {
        $cases = [
            [self::MASTER, ['search' => ['a']], ['search']],
            [self::MASTER, ['status' => ['1']], ['status']],
            [self::KECAMATAN, ['parent' => ['3171']], ['parent']],
            [self::MASTER, ['page' => ['1']], ['page']],
            [self::MASTER, ['per_page' => ['10']], ['per_page']],
            [self::USERS, ['search' => ['a']], ['search']],
            [self::USERS, ['status' => ['1']], ['status']],
            [self::USERS, ['user_level' => ['1']], ['user_level']],
            [self::USERS, ['id_satker' => ['S01']], ['id_satker']],
            [self::USERS, ['sort' => ['nip']], ['sort']],
            [self::USERS, ['order' => ['desc']], ['order']],
            [self::USERS, ['page' => ['1']], ['page']],
            [self::USERS, ['per_page' => ['10']], ['per_page']],
            // Array bersarang juga bukan nilai tunggal.
            [self::MASTER, ['search' => ['a' => ['b']]], ['search']],
            // Beberapa parameter salah sekaligus → SATU 422 yang memuat seluruh key bermasalah.
            [self::MASTER, ['search' => ['a'], 'status' => ['1'], 'page' => ['2']], ['page', 'search', 'status']],
            [self::USERS, ['sort' => ['nip'], 'order' => ['desc']], ['order', 'sort']],
        ];

        foreach ($cases as [$uri, $query, $fields]) {
            $result = $this->asRole(Role::SUPER_ADMIN)->get($uri, $query);
            $label  = $uri . ' ' . json_encode($query);

            $result->assertStatus(422);

            $body = $this->json($result);
            $this->assertSame('error', $body['status'], $label);
            $this->assertSame(ListQuery::INVALID_MESSAGE, $body['message'], $label);

            // Dibandingkan sebagai himpunan: urutan key mengikuti urutan daftar parameter di service, bukan kontrak.
            /** @var array<string, list<string>> $errors */
            $errors   = $body['errors'];
            $expected = array_fill_keys($fields, [ListQuery::SCALAR_MESSAGE]);
            ksort($errors);
            ksort($expected);
            $this->assertSame($expected, $errors, $label);
        }
    }

    /**
     * Filter kolom allowlist (opsi `filters` master, mis. `?cpns=1`) berbentuk array juga ditolak 422, bukan 500.
     *
     * Diuji lewat master UJI `uji-level` (filters: `kategori`) karena master yang punya `filters` di Config\MasterData
     * baru masuk lewat DBV-004/005: memakai `agama` (tanpa `filters`) membuat parameter itu sekadar diabaikan,
     * sehingga assertion tidak menguji apa pun (CR-016).
     */
    public function testArrayShapedMasterColumnFilterIsRejectedAsValidationError(): void
    {
        $this->useMasterUji();
        $this->db->table('uji_level')->insertBatch([
            ['id_level' => 1, 'level' => 'I/a', 'kategori' => 2, 'order' => 1, 'status' => 1],
            ['id_level' => 2, 'level' => 'CPNS I/a', 'kategori' => 1, 'order' => 2, 'status' => 1],
        ]);

        $uri = 'api/v1/master/uji-level';

        // Prasyarat: filter itu memang aktif di daftar (kalau tidak, 422 di bawah bisa datang dari sebab lain).
        $filtered = $this->data($this->asRole(Role::SUPER_ADMIN)->get($uri, ['kategori' => '1']));
        $this->assertSame(['2'], array_map('strval', array_column($filtered['items'], 'id_level')));
        $this->assertSame(2, $this->data($this->asRole(Role::SUPER_ADMIN)->get($uri))['total']);

        // Bentuk array -> 422 pada key filter itu (dulu `(string) $array` -> 500).
        $result = $this->asRole(Role::SUPER_ADMIN)->get($uri, ['kategori' => ['1']]);
        $result->assertStatus(422);
        $this->assertSame(['Filter Kategori tidak valid.'], $this->json($result)['errors']['kategori']);

        // Nilai di luar pilihan tetap ditolak seperti sebelumnya.
        $this->asRole(Role::SUPER_ADMIN)->get($uri, ['kategori' => '9'])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 2. page: bukan angka positif / meluap → 422; halaman valid di luar data → 200 kosong
    // ------------------------------------------------------------------

    public function testInvalidPageIsRejectedWithValidationError(): void
    {
        $invalid = [
            '99999999999999999999',                 // meluap jangkauan int (asal TypeError limit())
            '9223372036854775808',                  // PHP_INT_MAX + 1
            '0',
            '00',
            '-1',
            'abc',
            '1.5',
            '1e3',
            '+1',
            (string) (ListQuery::PAGE_MAX + 1),
        ];

        foreach ([self::MASTER, self::USERS] as $uri) {
            foreach ($invalid as $page) {
                $result = $this->asRole(Role::SUPER_ADMIN)->get($uri, ['page' => $page]);
                $label  = $uri . ' ?page=' . $page;

                $result->assertStatus(422);
                $this->assertSame([
                    'status'  => 'error',
                    'message' => ListQuery::PAGE_MESSAGE,
                    'errors'  => ['page' => [ListQuery::PAGE_MESSAGE]],
                ], $this->json($result), $label);
            }
        }
    }

    public function testValidPageBeyondAvailableDataReturnsEmptyListNotError(): void
    {
        foreach ([self::MASTER, self::USERS] as $uri) {
            $data = $this->data($this->asRole(Role::SUPER_ADMIN)->get($uri, ['page' => '5000', 'per_page' => '10']));

            $this->assertSame([], $data['items'], $uri);
            $this->assertSame(5000, $data['page'], $uri);
            $this->assertSame(10, $data['per_page'], $uri);
            $this->assertGreaterThan(0, $data['total'], $uri . ' — total tetap jumlah seluruh data');

            // Batas atas yang masih diterima.
            $max = $this->data($this->asRole(Role::SUPER_ADMIN)->get($uri, ['page' => (string) ListQuery::PAGE_MAX]));
            $this->assertSame(ListQuery::PAGE_MAX, $max['page'], $uri);
            $this->assertSame([], $max['items'], $uri);

            // `?page=` kosong sama dengan tidak dikirim → halaman 1.
            $empty = $this->data($this->asRole(Role::SUPER_ADMIN)->get($uri, ['page' => '']));
            $this->assertSame(1, $empty['page'], $uri);
            $this->assertNotSame([], $empty['items'], $uri);
        }
    }

    // ------------------------------------------------------------------
    // 3. Wildcard LIKE pada pencarian master di-escape (pola FaqService)
    // ------------------------------------------------------------------

    public function testSearchWildcardsAreMatchedAsPlainTextOnMasterList(): void
    {
        // Entri yang benar-benar memuat karakter wildcard, supaya "tidak cocok" bisa dibedakan dari "cocok literal".
        // `Tanya!` (memuat ESCAPE char) dan `Diskon 100%` (BERAKHIR `%`) wajib ada: tanpa keduanya, menghapus `!`
        // dari ListQuery::likeLiteral() tidak membuat satu assertion pun gagal (CR-016). Bila `!` tidak di-escape,
        // `?search=!` menjadi pola `%!%` = "berakhir dengan %" -> `Diskon 100%`, dan `?search=!%` menjadi
        // "memuat !" -> `Tanya!` — persis kebalikan hasil yang benar di bawah.
        foreach (['Aliran 100% Baru', 'Aliran_Lama', 'Tanya!', 'Diskon 100%'] as $nama) {
            $this->sendJson('POST', self::MASTER, ['agama' => $nama])->assertStatus(201);
        }

        // `%` dan `_` dicari sebagai karakter biasa: hanya entri yang memuatnya yang cocok
        // (sebelum ISSUE-019 `%` mencocokkan SEMUA baris dan `_` mencocokkan semua nama berisi >= 1 karakter).
        $this->assertSame(['Aliran 100% Baru', 'Diskon 100%'], $this->names(['search' => '%']));
        $this->assertSame(['Aliran_Lama'], $this->names(['search' => '_']));
        $this->assertSame(['Aliran 100% Baru', 'Diskon 100%'], $this->names(['search' => '100%']));

        // `!` = ESCAPE char Query Builder CI4, ikut di-escape jadi teks biasa: `?search=!` HANYA mengembalikan
        // baris yang memuat '!', bukan baris yang berakhir '%'.
        $this->assertSame(['Tanya!'], $this->names(['search' => '!']));
        $this->assertSame(['Tanya!'], $this->names(['search' => 'Tanya!']));
        $this->assertSame([], $this->names(['search' => '!%']));
        $this->assertSame([], $this->names(['search' => '!!']));
        $this->assertSame([], $this->names(['search' => '%!%']));

        // Regresi: kata kunci biasa tetap cocok seperti sebelumnya.
        $this->assertSame(['Aliran 100% Baru', 'Aliran_Lama'], $this->names(['search' => 'Aliran']));
        $this->assertSame(['Katolik'], $this->names(['search' => 'kat']));
    }

    // ------------------------------------------------------------------
    // Regresi: pencarian & paging normal tidak berubah
    // ------------------------------------------------------------------

    public function testMasterSearchFilterAndPagingRegression(): void
    {
        $first = $this->data($this->asRole(Role::SUPER_ADMIN)->get(self::MASTER, ['page' => '1', 'per_page' => '4']));
        $this->assertSame(6, $first['total']);
        $this->assertSame(['Islam', 'Kristen Protestan', 'Katolik', 'Hindu'], array_column($first['items'], 'agama'));

        $second = $this->data($this->asRole(Role::SUPER_ADMIN)->get(self::MASTER, ['page' => '2', 'per_page' => '4']));
        $this->assertSame(2, $second['page']);
        $this->assertSame(['Buddha', 'Konghucu'], array_column($second['items'], 'agama'));

        // per_page di luar jangkauan tetap DIJEPIT (bukan ditolak) seperti perilaku lama.
        $this->assertSame(100, $this->data($this->asRole(Role::SUPER_ADMIN)->get(self::MASTER, ['per_page' => '9999']))['per_page']);
        $this->assertSame(1, $this->data($this->asRole(Role::SUPER_ADMIN)->get(self::MASTER, ['per_page' => '0']))['per_page']);

        // Filter status dan induk.
        $this->sendJson('PATCH', self::MASTER . '/5/status', ['status' => '2'])->assertStatus(200);
        $this->assertSame(['Buddha'], $this->names(['status' => '2']));

        $kec = $this->data($this->asRole(Role::SUPER_ADMIN)->get(self::KECAMATAN, ['parent' => '3171']));
        $this->assertSame(['3171010', '3171020'], array_column($kec['items'], 'id_kecamatan'));

        // Spasi di sekitar nilai tidak mengubah arti filter.
        $this->assertSame(['3171010', '3171020'], array_column(
            $this->data($this->asRole(Role::SUPER_ADMIN)->get(self::KECAMATAN, ['parent' => ' 3171 ']))['items'],
            'id_kecamatan',
        ));
    }

    public function testUserListSearchFilterSortAndPagingRegression(): void
    {
        $superAdminNip = AuthSeeder::nipForRole(Role::SUPER_ADMIN);

        // Pencarian username/nip.
        $found = $this->data($this->asRole(Role::SUPER_ADMIN)->get(self::USERS, ['search' => $superAdminNip]));
        $this->assertSame([$superAdminNip], array_column($found['items'], 'nip'));

        // Filter role dan satker.
        $byLevel = $this->data($this->asRole(Role::SUPER_ADMIN)->get(self::USERS, ['user_level' => (string) Role::PEGAWAI]));
        $this->assertNotSame([], $byLevel['items']);
        $this->assertSame([Role::PEGAWAI], array_values(array_unique(array_column($byLevel['items'], 'user_level'))));

        $bySatker = $this->data($this->asRole(Role::SUPER_ADMIN)->get(self::USERS, ['id_satker' => 'S02']));
        $this->assertNotSame([], $bySatker['items']);
        $this->assertSame(['S02'], array_values(array_unique(array_column($bySatker['items'], 'id_satker'))));

        // Urutan (sort/order) — dipakai CR-010..012, perilakunya tidak berubah.
        $desc = array_column($this->data($this->asRole(Role::SUPER_ADMIN)->get(self::USERS, ['sort' => 'nip', 'order' => 'desc']))['items'], 'nip');
        $asc  = array_column($this->data($this->asRole(Role::SUPER_ADMIN)->get(self::USERS, ['sort' => 'nip', 'order' => 'asc']))['items'], 'nip');
        $this->assertNotSame([], $asc);
        $this->assertSame($asc, array_reverse($desc));

        // sort di luar allowlist dan order selain desc tetap jatuh ke default (username ASC).
        $default = array_column($this->data($this->asRole(Role::SUPER_ADMIN)->get(self::USERS))['items'], 'username');
        $this->assertSame($default, array_column(
            $this->data($this->asRole(Role::SUPER_ADMIN)->get(self::USERS, ['sort' => 'password', 'order' => 'sideways']))['items'],
            'username',
        ));

        // Paging: halaman 1 + 2 dengan per_page kecil menyusun ulang daftar penuh.
        $page1 = array_column($this->data($this->asRole(Role::SUPER_ADMIN)->get(self::USERS, ['page' => '1', 'per_page' => '3']))['items'], 'username');
        $page2 = array_column($this->data($this->asRole(Role::SUPER_ADMIN)->get(self::USERS, ['page' => '2', 'per_page' => '3']))['items'], 'username');
        $this->assertCount(3, $page1);
        $this->assertSame(array_slice($default, 0, 6), array_merge($page1, $page2));
    }

    public function testSearchWildcardsAreMatchedAsPlainTextOnUserList(): void
    {
        // Akun pembanding yang benar-benar memuat karakter wildcard di username.
        $this->createAccount('200001012024011001', 'admin%satu');
        $this->createAccount('200001012024011002', 'admin_dua');
        // `admin!tiga` mengunci escaping `!`: tanpanya, menghapus `!` dari ListQuery::likeLiteral() tidak membuat
        // satu assertion pun gagal, karena tidak ada username yang memuat '!' atau berakhir '%' (CR-016).
        $this->createAccount('200001012024011003', 'admin!tiga');

        // Sebelum ISSUE-019 `%` mencocokkan SELURUH akun dan `_` mencocokkan semua username berisi >= 1 karakter.
        $this->assertSame(['admin%satu'], $this->usernames(['search' => '%']));
        $this->assertSame(['admin_dua'], $this->usernames(['search' => '_']));
        $this->assertSame(['admin%satu'], $this->usernames(['search' => 'admin%']));
        $this->assertSame(['admin_dua'], $this->usernames(['search' => 'n_d']));

        // `!` = ESCAPE char Query Builder CI4, ikut di-escape jadi teks biasa: `?search=!` HANYA mengembalikan
        // akun yang username-nya memuat '!'.
        $this->assertSame(['admin!tiga'], $this->usernames(['search' => '!']));
        $this->assertSame(['admin!tiga'], $this->usernames(['search' => 'admin!']));
        $this->assertSame([], $this->usernames(['search' => '!%']));
        $this->assertSame([], $this->usernames(['search' => '!!']));
        $this->assertSame([], $this->usernames(['search' => '%!%']));

        // Regresi: kata kunci biasa tetap cocok, baik lewat username maupun NIP.
        $this->assertSame(['admin!tiga', 'admin%satu', 'admin_dua'], $this->usernames(['search' => 'admin']));
        $this->assertSame(['admin%satu'], $this->usernames(['search' => '200001012024011001']));
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function data(TestResponse $result): array
    {
        $result->assertStatus(200);

        /** @var array<string, mixed> $data */
        $data = $this->json($result)['data'];

        return $data;
    }

    /**
     * Akun uji dengan username apa adanya (bukan NIP), untuk menguji pencocokan karakter wildcard.
     */
    private function createAccount(string $nip, string $username): void
    {
        $this->sendJson('POST', self::USERS, [
            'nip'        => $nip,
            'username'   => $username,
            'password'   => 'AkunBaru2026',
            'user_level' => Role::PEGAWAI,
        ])->assertStatus(201);
    }

    /**
     * Username hasil daftar akun, diurutkan agar assertion tidak bergantung pada urutan tampil
     * (urutan sort/order diuji terpisah).
     *
     * @param array<string, mixed> $query
     *
     * @return list<string>
     */
    private function usernames(array $query): array
    {
        $items = $this->data($this->asRole(Role::SUPER_ADMIN)->get(self::USERS, $query))['items'];

        /** @var list<array<string, mixed>> $items */
        $names = array_map(static fn (array $row): string => (string) $row['username'], $items);
        sort($names);

        return $names;
    }

    /**
     * Nama entri master (agama) hasil daftar, diurutkan agar assertion tidak bergantung pada urutan tampil
     * (urutan daftar diuji terpisah di testMasterSearchFilterAndPagingRegression).
     *
     * @param array<string, mixed> $query
     *
     * @return list<string>
     */
    private function names(array $query): array
    {
        $items = $this->data($this->asRole(Role::SUPER_ADMIN)->get(self::MASTER, $query))['items'];

        /** @var list<array<string, mixed>> $items */
        $names = array_map(static fn (array $row): string => (string) $row['agama'], $items);
        sort($names);

        return $names;
    }
}
