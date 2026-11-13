<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Arsip\AksiArsip;
use App\Libraries\Kepegawaian\Arsip\OtorisasiArsip;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Lampiran\PathLegacy;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use ZipArchive;

/**
 * MAKE-092 — aksi Arsip F-10 Tahap 2 (PRD F-10 §6.1–§6.2 AC2-01..AC2-13, F10-26..F10-28, F10-31, F10-38, §12.1).
 *
 * HTTP nyata (jwt + Role::filter + PegawaiScope + RiwayatRegistry produksi) dengan akar penyimpanan sementara.
 *
 * @internal
 */
final class ArsipAksiTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LampiranUjiTrait;
    use PegawaiFixtureTrait;

    private string $nip;

    private int $satker;

    protected function setUp(): void
    {
        parent::setUp();

        Services::injectMock('storageAdapter', new LocalStorageAdapter($this->akarLampiranUji()));
        Services::injectMock('cache', new MockCache());

        $this->satker = $this->buatSatker();
        $this->nip    = $this->buatPegawaiDiSatker($this->satker);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLampiranUji();
        Services::reset(true);
        Factories::reset('config');

        parent::tearDown();
    }

    public function testTidakAdaRouteGetPengubahDanFilterRoleTulis(): void
    {
        $routes = service('routes')->loadRoutes();
        $get    = array_filter(array_keys($routes->getRoutes('GET')), static fn ($r): bool => str_starts_with((string) $r, 'api/v1/pegawai/') && str_contains((string) $r, '/arsip'));

        // AC2-01: GET hanya baca (folder, daftar, buka, ekspor, opsi-hubungkan).
        $this->assertEqualsCanonicalizing([
            'api/v1/pegawai/([^/]+)/arsip',
            'api/v1/pegawai/([^/]+)/arsip/berkas',
            'api/v1/pegawai/([^/]+)/arsip/berkas/([0-9]+)/buka',
            'api/v1/pegawai/([^/]+)/arsip/ekspor',
            'api/v1/pegawai/([^/]+)/arsip/opsi-hubungkan',
        ], array_values($get));

        $id = $this->arsip(0, 'arsip/x/a.pdf');

        // AC2-02: role 4/5/8 → 403 untuk semua aksi tulis dan opsi-hubungkan.
        foreach ([Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN] as $role) {
            $akun = $this->akun($this->buatPegawai(), $role);

            foreach ($this->permintaanTulis($id) as $label => $kirim) {
                $this->assertSame(403, $kirim($akun)->response()->getStatusCode(), "role {$role} {$label}");
            }
        }

        $this->assertSame(1, $this->db->table('document_attachment')->where('id_attachment', $id)->where('tag', null)->countAllResults());
    }

    public function testLingkupUbahDanAntiIdor(): void
    {
        $id        = $this->arsip(0, 'arsip/x/a.pdf');
        $nipLain   = $this->buatPegawaiDiSatker($this->satker);
        $milikLain = $this->arsip(0, 'arsip/y/b.pdf', [], $nipLain);

        // AC2-03: 2/6/7 terhadap NIP lain → 403; {id} milik NIP lain → 404.
        foreach (Role::UL_PEGAWAI as $role) {
            $akun = $this->akun($this->buatPegawaiDiSatker($this->satker), $role);

            foreach ($this->permintaanTulis($id) as $label => $kirim) {
                $this->assertSame(403, $kirim($akun)->response()->getStatusCode(), "role {$role} {$label}");
            }
        }

        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);

        foreach ($this->permintaanTulis($milikLain) as $label => $kirim) {
            if ($label !== 'opsi') {
                $this->assertSame(404, $kirim($admin)->response()->getStatusCode(), "milik NIP lain {$label}");
            }
        }

        // AC2-04: role 3 di luar lingkup ubah → 403; di dalam lingkup → boleh.
        $adminLuar = $this->akun($this->buatPegawaiDiSatker($this->buatSatker()), Role::ADMIN_SATKER);

        foreach ($this->permintaanTulis($id) as $label => $kirim) {
            $this->assertSame(403, $kirim($adminLuar)->response()->getStatusCode(), "role 3 luar {$label}");
        }

        $adminDalam = $this->akun($this->buatPegawaiDiSatker($this->satker), Role::ADMIN_SATKER);
        $this->ubah($adminDalam, $id, 'Nama Baru')->assertStatus(200);
        $this->asUser($this->akun($this->buatPegawai(), Role::SUPER_ADMIN))->patch("api/v1/pegawai/199912312099121001/arsip/berkas/{$id}", ['tag' => 'x'])->assertStatus(404);
    }

    public function testHubungkanDanHapusHanyaKodeNolDanValidasiTujuan(): void
    {
        $terhubung = $this->arsip(9, 'arsip/x/a.pdf');
        $tanpa     = $this->arsip(null, 'arsip/x/b.pdf');
        $yatim     = $this->arsip(0, 'arsip/x/c.pdf');
        $admin     = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $rmj       = $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 1]);

        // AC2-05: bukan kode 0 → 422 errors.id_attachment dengan pesan legacy apa adanya.
        foreach ([$terhubung, $tanpa] as $id) {
            $res = $this->hubungkan($admin, $id, ['id_riwayat' => 9, 'id_entri' => $rmj]);
            $res->assertStatus(422);
            $this->assertSame([AksiArsip::PESAN_SUDAH_TERHUBUNG], $this->json($res)['errors']['id_attachment']);
            $hapus = $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/arsip/berkas/{$id}");
            $hapus->assertStatus(422);
            $this->assertSame([AksiArsip::PESAN_SUDAH_TERHUBUNG], $this->json($hapus)['errors']['id_attachment']);
        }

        // AC2-06: jenis di luar F10-28 → 422; data riwayat kosong → 422.
        foreach ([37, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 0, 2, 'abc', '', '9.0'] as $kode) {
            $res = $this->hubungkan($admin, $yatim, ['id_riwayat' => $kode, 'id_entri' => $rmj]);
            $res->assertStatus(422);
            $this->assertArrayHasKey('id_riwayat', $this->json($res)['errors'], (string) $kode);
        }

        $res = $this->hubungkan($admin, $yatim, ['id_riwayat' => 9]);
        $res->assertStatus(422);
        $this->assertArrayHasKey('id_entri', $this->json($res)['errors']);

        // id_entri wajib PK kanonik: MySQL akan mencocokkan "05"/"5abc" dengan PK 5, tetapi B-18 membandingkan string.
        foreach (['0' . $rmj, $rmj . 'abc', '+' . $rmj, '1e1', '0', '-1', ' '] as $buruk) {
            $res = $this->hubungkan($admin, $yatim, ['id_riwayat' => 9, 'id_entri' => $buruk]);
            $res->assertStatus(422);
            $this->assertArrayHasKey('id_entri', $this->json($res)['errors'], $buruk);
        }

        // Riwayat bukan milik NIP / bukan Disetujui / tidak ada → 404.
        $lain     = $this->buatRiwayatMutasiJabatan($this->buatPegawai(), ['status' => 1]);
        $menunggu = $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 0]);
        $hapusan  = $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 10]);

        foreach ([$lain, $menunggu, $hapusan, 99999999] as $entri) {
            $this->hubungkan($admin, $yatim, ['id_riwayat' => 9, 'id_entri' => $entri])->assertStatus(404);
        }

        $this->assertSame(0, (int) $this->db->table('document_attachment')->select('id_riwayat')->where('id_attachment', $yatim)->get()->getRowArray()['id_riwayat']);
    }

    public function testHubungkanBerhasilAuditDanSiasnTidakTersentuh(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $rmj   = $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 1, 'jabatan' => 'Analis Uji', 'satker' => 'Biro Uji']);
        $yatim = $this->arsip(0, 'arsip/x/c.pdf', ['tag' => 'SK']);
        $du    = $this->arsip(0, 'arsip/x/d.pdf');
        $siasn = $this->db->table('siasn_upload_arsip')->countAllResults();

        $kueri = [];
        $catat = static function (Query $q) use (&$kueri): void {
            $kueri[] = $q->getQuery();
        };
        Events::on('DBQuery', $catat);

        try {
            $res = $this->hubungkan($admin, $yatim, ['id_riwayat' => 9, 'id_entri' => (string) $rmj]);
        } finally {
            Events::removeListener('DBQuery', $catat);
        }

        $res->assertStatus(200);
        $item = $this->json($res)['data'];
        $this->assertSame([9, 'Jabatan', (string) $rmj, 'Analis Uji - Biro Uji'], [$item['id_riwayat'], $item['jenis_riwayat'], $item['id_entri'], $item['ringkasan_riwayat']]);
        $this->assertSame(['edit' => true, 'hubungkan' => false, 'hapus' => false], $item['aksi']);

        $row = $this->db->table('document_attachment')->where('id_attachment', $yatim)->get()->getRowArray();
        $this->assertSame([9, 'Jabatan', (string) $rmj], [(int) $row['id_riwayat'], $row['nama_riwayat'], $row['id_entri']]);

        $audit = $this->db->table('audit_logs')->where('entity', 'document_attachment')->where('entity_id', (string) $yatim)->where('event', 'update')->get()->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertSame((int) $admin['id_pengguna'], (int) $audit[0]['id_pengguna_actor']);

        // AC2-07: siasn_upload_arsip tidak dibaca dan tidak ditulis.
        $this->assertSame($siasn, $this->db->table('siasn_upload_arsip')->countAllResults());
        $this->assertSame([], array_values(array_filter($kueri, static fn (string $sql): bool => str_contains($sql, 'siasn_upload_arsip'))));

        // Kode 38 (Data Umum): tanpa data riwayat, id_entri NULL (id_entri kiriman diabaikan) [K].
        $this->hubungkan($admin, $du, ['id_riwayat' => 38, 'id_entri' => '123'])->assertStatus(200);
        $row = $this->db->table('document_attachment')->where('id_attachment', $du)->get()->getRowArray();
        $this->assertSame([38, 'Data Umum', null], [(int) $row['id_riwayat'], $row['nama_riwayat'], $row['id_entri']]);
    }

    public function testHubungkanKodeTigaLewatInduk(): void
    {
        $admin    = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $kel      = $this->insert('riwayat_keluarga', ['nip' => $this->nip, 'nama_pasangan' => 'P', 'jumlah_anak' => 1, 'tgl_perkawinan' => '2010-01-01', 'status' => 1]);
        $anak     = $this->insert('detail_anak', ['id_riwayat_keluarga' => $kel, 'nama' => 'Anak Uji', 'tgl_lahir' => '2012-01-01']);
        $kelLain  = $this->insert('riwayat_keluarga', ['nip' => $this->buatPegawai(), 'nama_pasangan' => 'Q', 'jumlah_anak' => 1, 'tgl_perkawinan' => '2010-01-01', 'status' => 1]);
        $anakLain = $this->insert('detail_anak', ['id_riwayat_keluarga' => $kelLain, 'nama' => 'Lain', 'tgl_lahir' => '2012-01-01']);
        $yatim    = $this->arsip(0, 'arsip/x/c.pdf');

        $opsi = $this->json($this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/opsi-hubungkan?id_riwayat=3"))['data'];
        $this->assertSame(['id_riwayat' => 3, 'butuh_data' => true, 'items' => [['id_entri' => (string) $anak, 'ringkasan_riwayat' => 'Anak Uji']]], $opsi);

        $this->hubungkan($admin, $yatim, ['id_riwayat' => 3, 'id_entri' => $anakLain])->assertStatus(404);
        $this->hubungkan($admin, $yatim, ['id_riwayat' => 3, 'id_entri' => $anak])->assertStatus(200);
        $this->assertSame('Anak Uji', $this->json($this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/berkas"))['data']['items'][0]['ringkasan_riwayat']);
    }

    public function testKunciBarisDisetujuiUntukPegawaiF1031(): void
    {
        $rmj     = $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 1]);
        $yatim   = $this->arsip(0, 'arsip/x/c.pdf');
        $pegawai = $this->akun($this->nip, Role::PEGAWAI);

        // AC2-13: role 2 ke riwayat Disetujui milik Definisi terkunci → 403; ke kode 38 → sukses.
        $tolak = $this->hubungkan($pegawai, $yatim, ['id_riwayat' => 9, 'id_entri' => $rmj]);
        $tolak->assertStatus(403);
        $this->assertSame(OtorisasiArsip::PESAN_KUNCI, $this->json($tolak)['message']);
        // Urutan F10-38: 404 riwayat tujuan didahulukan dari 403 kunci.
        $this->hubungkan($pegawai, $yatim, ['id_riwayat' => 9, 'id_entri' => 99999999])->assertStatus(404);
        $opsi = $this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/arsip/opsi-hubungkan?id_riwayat=9");
        $opsi->assertStatus(403);
        $this->assertSame(OtorisasiArsip::PESAN_KUNCI, $this->json($opsi)['message']);

        $folder = $this->json($this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/arsip"))['data'];
        $this->assertSame(['tulis' => true, 'jenis_hubungkan' => [['id_jenis_rwy' => 38, 'label' => 'Data Umum']]], $folder['aksi']);
        $this->assertSame(['id_riwayat' => 38, 'butuh_data' => false, 'items' => []], $this->json($this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/arsip/opsi-hubungkan?id_riwayat=38"))['data']);

        $this->hubungkan($pegawai, $yatim, ['id_riwayat' => 38])->assertStatus(200);

        // Role 1/3 ke riwayat Disetujui → sukses; jenis_hubungkan penuh (F10-28).
        foreach ([Role::SUPER_ADMIN, Role::ADMIN_SATKER] as $role) {
            $admin = $this->akun($this->buatPegawaiDiSatker($this->satker), $role);
            $baru  = $this->arsip(0, "arsip/x/r{$role}.pdf");
            $this->hubungkan($admin, $baru, ['id_riwayat' => 9, 'id_entri' => $rmj])->assertStatus(200);
            $jenis = $this->json($this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip"))['data']['aksi']['jenis_hubungkan'];
            $this->assertEqualsCanonicalizing([1, 3, 5, 7, 9, 10, 11, 13, 14, 20, 22, 23, 32, 36, 38], array_column($jenis, 'id_jenis_rwy'), 'daftar literal F10-28');
            // Objek berlabel dari jenis_rwy, urut `order` (38 Data Umum order 3 sebelum 9 Jabatan order 6, 14 Pendidikan 13).
            $urut = $this->db->table('jenis_rwy')->select('id_jenis_rwy, jenis_rwy')->whereIn('id_jenis_rwy', [1, 3, 5, 7, 9, 10, 11, 13, 14, 20, 22, 23, 32, 36, 38])->orderBy('order', 'ASC')->orderBy('id_jenis_rwy', 'ASC')->get()->getResultArray();
            $this->assertSame(array_map(static fn (array $r): array => ['id_jenis_rwy' => (int) $r['id_jenis_rwy'], 'label' => $r['jenis_rwy']], $urut), $jenis);
            $this->assertContains(['id_jenis_rwy' => 14, 'label' => 'Pendidikan'], $jenis);
            $this->assertLessThan(array_search(9, array_column($jenis, 'id_jenis_rwy'), true), array_search(38, array_column($jenis, 'id_jenis_rwy'), true));
        }

        // Role 4 hanya lihat: tulis false, semua aksi baris false.
        $view = $this->akun($this->buatPegawai(), Role::ADMIN_VIEW_ESELON1);
        $this->arsip(0, 'arsip/x/z.pdf');
        $this->assertSame(['tulis' => false, 'jenis_hubungkan' => []], $this->json($this->asUser($view)->get("api/v1/pegawai/{$this->nip}/arsip"))['data']['aksi']);

        foreach ($this->json($this->asUser($view)->get("api/v1/pegawai/{$this->nip}/arsip/berkas"))['data']['items'] as $item) {
            $this->assertSame(['edit' => false, 'hubungkan' => false, 'hapus' => false], $item['aksi']);
        }
    }

    public function testHubungkanDiulangSesudahKunciForUpdate(): void
    {
        // Status tujuan berubah di antara jalan cepat dan baca ulang `FOR UPDATE` (disimulasikan tepat saat baris pegawai
        // dikunci di dalam transaksi) → 404, baris arsip tidak berubah (F10-38 (1), AC2-13).
        $admin  = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $rmj    = $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 1]);
        $yatim  = $this->arsip(0, 'arsip/x/c.pdf');
        $db     = $this->db;
        $sekali = false;
        $balap  = static function (Query $q) use ($db, $rmj, &$sekali): void {
            if (! $sekali && str_contains($q->getQuery(), 'pegawai') && str_contains($q->getQuery(), 'FOR UPDATE')) {
                $sekali = true;
                $db->table('riwayat_mutasi_jabatan')->where('id_riwayat_mutasi_jabatan', $rmj)->update(['status' => 0]);
            }
        };
        Events::on('DBQuery', $balap);

        try {
            $this->hubungkan($admin, $yatim, ['id_riwayat' => 9, 'id_entri' => $rmj])->assertStatus(404);
        } finally {
            Events::removeListener('DBQuery', $balap);
        }

        $this->assertTrue($sekali);
        $this->assertSame(0, (int) $this->db->table('document_attachment')->select('id_riwayat')->where('id_attachment', $yatim)->get()->getRowArray()['id_riwayat']);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function ulPegawaiProvider(): array
    {
        return ['pegawai (2)' => [Role::PEGAWAI], 'ptt (6)' => [Role::PTT], 'pppk (7)' => [Role::PPPK]];
    }

    #[DataProvider('ulPegawaiProvider')]
    public function testPemilikUlPegawaiF1031(int $role): void
    {
        $rmj      = $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 1]);
        $sembilan = $this->arsip(9, 'arsip/x/9.pdf', ['id_entri' => (string) $rmj]);
        $status   = $this->arsip(37, 'arsip/x/37.pdf', ['tag' => 'Arsip Status']);
        $yatim    = $this->arsip(0, 'arsip/x/0.pdf');
        $yatim2   = $this->arsip(0, 'arsip/x/00.pdf');
        $akun     = $this->akun($this->nip, $role);

        $this->ubah($akun, $sembilan, 'Nama Baru')->assertStatus(200);
        $tolak = $this->ubah($akun, $status, 'Ganti');
        $tolak->assertStatus(403);
        $this->assertSame(OtorisasiArsip::PESAN_DATA_UMUM, $this->json($tolak)['message']);
        $aksi = array_column($this->json($this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip/berkas"))['data']['items'], 'aksi', 'id_attachment');
        $this->assertFalse($aksi[$status]['edit']);
        $this->assertSame(['edit' => true, 'hubungkan' => true, 'hapus' => true], $aksi[$yatim]);

        $this->hubungkan($akun, $yatim, ['id_riwayat' => 38])->assertStatus(200);
        $kunci = $this->hubungkan($akun, $yatim2, ['id_riwayat' => 9, 'id_entri' => $rmj]);
        $kunci->assertStatus(403);
        $this->assertSame(OtorisasiArsip::PESAN_KUNCI, $this->json($kunci)['message']);
        $opsi = $this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip/opsi-hubungkan?id_riwayat=9");
        $opsi->assertStatus(403);
        $this->assertSame(OtorisasiArsip::PESAN_KUNCI, $this->json($opsi)['message']);
        $this->assertSame([['id_jenis_rwy' => 38, 'label' => 'Data Umum']], $this->json($this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip"))['data']['aksi']['jenis_hubungkan']);
    }

    public function testBalapanKodeDicekUlangSesudahForUpdate(): void
    {
        $admin   = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $pegawai = $this->akun($this->nip, Role::PEGAWAI);

        // Hapus & Hubungkan: baris kode 0 menjadi id_riwayat 9 tepat saat `pegawai … FOR UPDATE` → 422, baris tidak terhapus
        // dan tidak terhubung ke 38.
        foreach (['hapus', 'hubungkan'] as $aksi) {
            $id = $this->arsip(0, "arsip/x/{$aksi}.pdf");
            $this->denganBalapan(['id_riwayat' => 9], $id, function () use ($admin, $id, $aksi): void {
                $res = $aksi === 'hapus'
                    ? $this->asUser($admin)->delete("api/v1/pegawai/{$this->nip}/arsip/berkas/{$id}")
                    : $this->hubungkan($admin, $id, ['id_riwayat' => 38]);
                $res->assertStatus(422);
                $this->assertSame([AksiArsip::PESAN_SUDAH_TERHUBUNG], $this->json($res)['errors']['id_attachment'], $aksi);
            });
            $row = $this->db->table('document_attachment')->where('id_attachment', $id)->get()->getRowArray();
            $this->assertIsArray($row, $aksi);
            $this->assertNotSame(38, (int) $row['id_riwayat'], $aksi);
        }

        // Ubah Nama: baris 38 menjadi 37 tepat saat kunci → role 2 ditolak 403 dan tag tidak berubah.
        $du = $this->arsip(38, 'arsip/x/du.pdf', ['tag' => 'KTP']);
        $this->denganBalapan(['id_riwayat' => 37], $du, function () use ($pegawai, $du): void {
            $res = $this->ubah($pegawai, $du, 'Ganti');
            $res->assertStatus(403);
            $this->assertSame(OtorisasiArsip::PESAN_DATA_UMUM, $this->json($res)['message']);
        });
        $this->assertSame('KTP', $this->db->table('document_attachment')->select('tag')->where('id_attachment', $du)->get()->getRowArray()['tag']);
    }

    /**
     * Jalankan $kerja sambil mengubah baris arsip $id menjadi $ubah tepat saat baris `pegawai` dikunci `FOR UPDATE` di dalam
     * transaksi aksi (simulasi tulis paralel di antara jalan cepat dan baca ulang terkunci).
     *
     * @param array<string, mixed> $ubah
     * @param callable(): void     $kerja
     */
    private function denganBalapan(array $ubah, int $id, callable $kerja): void
    {
        $db     = $this->db;
        $sekali = false;
        $balap  = static function (Query $q) use ($db, $ubah, $id, &$sekali): void {
            if (! $sekali && str_contains($q->getQuery(), 'pegawai') && str_contains($q->getQuery(), 'FOR UPDATE')) {
                $sekali = true;
                $db->table('document_attachment')->where('id_attachment', $id)->update($ubah);
            }
        };
        Events::on('DBQuery', $balap);

        try {
            $kerja();
        } finally {
            Events::removeListener('DBQuery', $balap);
        }

        $this->assertTrue($sekali, 'balapan tersimulasi');
    }

    public function testOpsiHubungkanHanyaDisetujuiMilikNipUrutLegacy(): void
    {
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $a     = $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 1, 'jabatan' => 'A', 'satker' => 'S', 'tmtsk' => '2010-01-01']);
        $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 0]);
        $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 2]);
        $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 10]);
        $b = $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 1, 'jabatan' => 'B', 'satker' => 'S', 'tmtsk' => '2005-01-01']);
        $c = $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 1, 'jabatan' => 'C', 'satker' => 'S', 'tmtsk' => '2010-01-01']);
        $this->buatRiwayatMutasiJabatan($this->buatPegawai(), ['status' => 1]);

        // Kode 9 dan 36 berbagi tabel [K]; hukdis (kode 7) tetap ditawarkan walau tersembunyi di B-18 (F10-30).
        foreach ([9, 36] as $kode) {
            $this->assertSame([
                // tmtsk menaik [K Local.php:3352], seri tmtsk diputus PK menaik.
                ['id_entri' => (string) $b, 'ringkasan_riwayat' => 'B - S'],
                ['id_entri' => (string) $a, 'ringkasan_riwayat' => 'A - S'],
                ['id_entri' => (string) $c, 'ringkasan_riwayat' => 'C - S'],
            ], $this->json($this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/opsi-hubungkan?id_riwayat={$kode}"))['data']['items']);
        }

        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/opsi-hubungkan?id_riwayat=7")->assertStatus(200);
        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/opsi-hubungkan?id_riwayat=42")->assertStatus(422);
        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/opsi-hubungkan")->assertStatus(422);
    }

    public function testUbahNamaSanitasiDanKodeTigaPuluhTujuh(): void
    {
        $disetujui = $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 1]);
        $terhubung = $this->arsip(9, 'arsip/x/a.pdf', ['id_entri' => (string) $disetujui]);
        $status    = $this->arsip(37, 'arsip/x/s.pdf', ['tag' => 'Arsip Status']);
        $umum      = $this->arsip(38, 'arsip/x/u.pdf', ['tag' => 'KTP']);
        $pegawai   = $this->akun($this->nip, Role::PEGAWAI);

        // AC2-08: tag HTML & byte kontrol dibuang, trim; 1–255.
        $res = $this->ubah($pegawai, $terhubung, "  <b>SK</b>\x01 Jabatan <script>x</script> ");
        $res->assertStatus(200);
        $this->assertSame('SK Jabatan x', $this->json($res)['data']['tag']);
        $this->assertSame('SK Jabatan x', $this->db->table('document_attachment')->select('tag')->where('id_attachment', $terhubung)->get()->getRowArray()['tag']);
        $this->assertSame(1, $this->db->table('audit_logs')->where('entity', 'document_attachment')->where('entity_id', (string) $terhubung)->where('event', 'update')->countAllResults());

        // Byte kontrol dibuang SEBELUM strip_tags: "<\nimg …>" tidak bisa menyatu kembali menjadi tag.
        $res = $this->ubah($pegawai, $terhubung, "<\nimg src=x onerror=alert(1)>Foto");
        $res->assertStatus(200);
        $this->assertSame('Foto', $this->json($res)['data']['tag']);
        $this->assertSame('a < b', $this->json($this->ubah($pegawai, $terhubung, 'a < b'))['data']['tag']);

        foreach (['', '   ', '<i></i>', "\x00\x01", str_repeat('a', 256), "<\timg>", "<\x00script>", "\u{00A0}\u{3000}", "\u{00A0}"] as $buruk) {
            $res = $this->ubah($pegawai, $terhubung, $buruk);
            $res->assertStatus(422);
            $this->assertArrayHasKey('tag', $this->json($res)['errors']);
        }

        $this->asUser($pegawai)->patch("api/v1/pegawai/{$this->nip}/arsip/berkas/{$terhubung}", [])->assertStatus(422);
        $this->ubah($pegawai, $terhubung, str_repeat('é', 255))->assertStatus(200);

        // Kode 38 ikut legacy (QF10-8): pemilik boleh. Kode 37: hanya role 1 (F10-31 (b)), aksi.edit false bagi lainnya.
        $this->ubah($pegawai, $umum, 'NPWP')->assertStatus(200);

        foreach ([$pegawai, $this->akun($this->buatPegawaiDiSatker($this->satker), Role::ADMIN_SATKER)] as $akun) {
            $tolak = $this->ubah($akun, $status, 'Ganti');
            $tolak->assertStatus(403);
            $this->assertSame(OtorisasiArsip::PESAN_DATA_UMUM, $this->json($tolak)['message']);
            $items = array_column($this->json($this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip/berkas"))['data']['items'], 'aksi', 'id_attachment');
            $this->assertFalse($items[$status]['edit']);
            $this->assertTrue($items[$umum]['edit']);
        }

        // Urutan F10-38 (4): 404 baris → 403 bolehTulis → 422 tag.
        $this->ubah($pegawai, $status, '')->assertStatus(403);
        $this->ubah($pegawai, 99999999, '')->assertStatus(404);

        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $this->ubah($admin, $status, 'Status Baru')->assertStatus(200);
        $this->assertTrue(array_column($this->json($this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/berkas"))['data']['items'], 'aksi', 'id_attachment')[$status]['edit']);
    }

    public function testHapusBarisSajaBerkasFisikTertinggal(): void
    {
        $yatim   = $this->arsip(0, 'arsip/' . $this->nip . '/yatim.pdf', ['tag' => 'Yatim']);
        $pegawai = $this->akun($this->nip, Role::PEGAWAI);
        $berkas  = $this->akarLampiranUji() . '/arsip/' . $this->nip . '/yatim.pdf';
        $this->assertFileExists($berkas);

        $res = $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/arsip/berkas/{$yatim}");
        $res->assertStatus(200);
        $this->assertSame(['deleted' => true, 'soft_delete' => false, 'item' => ['id_attachment' => $yatim]], $this->json($res)['data']);

        // AC2-09: baris hilang, audit delete dengan snapshot, berkas di disk tetap ada [K].
        $this->assertSame(0, $this->db->table('document_attachment')->where('id_attachment', $yatim)->countAllResults());
        $this->assertFileExists($berkas);
        $audit = $this->db->table('audit_logs')->where('entity', 'document_attachment')->where('entity_id', (string) $yatim)->where('event', 'delete')->get()->getRowArray();
        $this->assertIsArray($audit);
        $this->assertSame('Yatim', json_decode((string) $audit['before_json'], true)['tag']);

        $this->asUser($pegawai)->delete("api/v1/pegawai/{$this->nip}/arsip/berkas/{$yatim}")->assertStatus(404);
    }

    public function testFilterBelumTerhubungDanAudit(): void
    {
        $nol = $this->arsip(0, 'arsip/x/a.pdf');
        $this->arsip(9, 'arsip/x/b.pdf');
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $ids   = fn (string $qs): array => array_column($this->json($this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/berkas?{$qs}"))['data']['items'], 'id_attachment');

        $this->assertSame([$nol], $ids('belum_terhubung=1'));
        $this->assertCount(2, $ids('belum_terhubung=0'));
        // Centang + jenis lain → kosong [K list.php:431-442].
        $this->assertSame([], $ids('belum_terhubung=1&id_jenis_rwy=9'));
        $this->assertSame([$nol], $ids('belum_terhubung=1&id_jenis_rwy=0'));
        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/berkas?belum_terhubung=ya")->assertStatus(422);

        $audit = json_decode((string) $this->db->table('audit_logs')->where('entity', 'arsip_lihat')->get()->getRowArray()['after_json'], true);
        $this->assertTrue($audit['filter']['belum_terhubung']);
    }

    public function testEksporXlsxCsvFilterAmanDanAudit(): void
    {
        $this->buatRiwayatMutasiJabatan($this->nip, ['status' => 1, 'jabatan' => 'Analis', 'satker' => 'Biro']);
        $rmj = (int) $this->db->table('riwayat_mutasi_jabatan')->select('id_riwayat_mutasi_jabatan')->where('nip', $this->nip)->get()->getRowArray()['id_riwayat_mutasi_jabatan'];
        $this->arsip(9, 'arsip/x/a.pdf', ['tag' => '=1+1', 'id_entri' => (string) $rmj]);
        $this->arsip(0, 'arsip/x/b.pdf', ['tag' => 'Yatim']);
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);

        $csv = $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/ekspor?format=csv");
        $csv->assertStatus(200);
        $r = $csv->response();
        $this->assertSame('no-store', $r->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('text/csv', $r->getHeaderLine('Content-Type'));
        $this->assertMatchesRegularExpression('/filename="Arsip_\d+_\d{8}\.csv"/', $r->getHeaderLine('Content-Disposition'));
        $isi = (string) $r->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $isi);
        $this->assertStringContainsString('"Nama Arsip","Jenis Riwayat","Data Riwayat"', $isi);
        $this->assertStringContainsString("'=1+1,Jabatan,\"Analis - Biro\"", $isi);
        $this->assertStringContainsString('Yatim,"Belum Terhubung",—', $isi);
        $this->assertStringNotContainsString('arsip/x', $isi, 'tanpa URL/path berkas');

        // Filter sama dengan daftar.
        $saring = (string) $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/ekspor?format=csv&belum_terhubung=1")->response()->getBody();
        $this->assertStringContainsString('Yatim', $saring);
        $this->assertStringNotContainsString('=1+1', $saring);

        $xlsx = $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/ekspor?format=xlsx")->response();
        $this->assertSame(200, $xlsx->getStatusCode());
        $sheet = $this->isiSheet((string) $xlsx->getBody());
        $this->assertStringContainsString('<is><t xml:space="preserve">=1+1</t></is>', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);

        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/ekspor?format=pdf")->assertStatus(422);

        // F10-26: ekspor arsip pegawai lain dicatat SETIAP kali (tanpa dedup); pemilik tidak.
        $rows = $this->db->table('audit_logs')->where('entity', 'arsip_lihat')->get()->getResultArray();
        $this->assertCount(3, $rows);
        $this->assertSame(['ekspor', 'ekspor', 'ekspor'], array_map(static fn (array $r): string => json_decode((string) $r['after_json'], true)['endpoint'], $rows));

        $this->asUser($this->akun($this->nip, Role::PEGAWAI))->get("api/v1/pegawai/{$this->nip}/arsip/ekspor?format=csv")->assertStatus(200);
        $this->asUser($this->akun($this->buatPegawai(), Role::MENTERI))->get("api/v1/pegawai/{$this->nip}/arsip/ekspor?format=csv")->assertStatus(200);
        $this->asUser($this->akun($this->buatPegawai(), Role::PIMPINAN))->get("api/v1/pegawai/{$this->nip}/arsip/ekspor?format=csv")->assertStatus(403);
        $this->asUser($this->akun($this->buatPegawaiDiSatker($this->buatSatker()), Role::ADMIN_SATKER))->get("api/v1/pegawai/{$this->nip}/arsip/ekspor?format=csv")->assertStatus(403);
        $this->assertSame(4, $this->db->table('audit_logs')->where('entity', 'arsip_lihat')->countAllResults());

        // Ekspor tidak mengisi kunci dedup: folder oleh admin yang sama sesudah ekspor tetap tercatat (endpoint folder).
        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip")->assertStatus(200);
        $terakhir = $this->db->table('audit_logs')->where('entity', 'arsip_lihat')->where('id_pengguna_actor', $admin['id_pengguna'])->orderBy('id_log', 'DESC')->get()->getRowArray();
        $this->assertSame('folder', json_decode((string) $terakhir['after_json'], true)['endpoint']);

        // Ekspor role 4 dicatat.
        $view = $this->akun($this->buatPegawai(), Role::ADMIN_VIEW_ESELON1);
        $this->asUser($view)->get("api/v1/pegawai/{$this->nip}/arsip/ekspor?format=xlsx")->assertStatus(200);
        $this->assertSame(1, $this->db->table('audit_logs')->where('entity', 'arsip_lihat')->where('id_pengguna_actor', $view['id_pengguna'])->countAllResults());
    }

    /**
     * @return array<string, callable(array<string, mixed>): TestResponse>
     */
    private function permintaanTulis(int $id): array
    {
        return [
            'patch'     => fn (array $akun): TestResponse => $this->ubah($akun, $id, 'X'),
            'hubungkan' => fn (array $akun): TestResponse => $this->hubungkan($akun, $id, ['id_riwayat' => 38]),
            'hapus'     => fn (array $akun): TestResponse => $this->asUser($akun)->delete("api/v1/pegawai/{$this->nip}/arsip/berkas/{$id}"),
            'opsi'      => fn (array $akun): TestResponse => $this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip/opsi-hubungkan?id_riwayat=38"),
        ];
    }

    /**
     * @param array<string, mixed> $akun
     */
    private function ubah(array $akun, int $id, string $tag): TestResponse
    {
        return $this->asUser($akun)->withBodyFormat('json')->patch("api/v1/pegawai/{$this->nip}/arsip/berkas/{$id}", ['tag' => $tag]);
    }

    /**
     * @param array<string, mixed> $akun
     * @param array<string, mixed> $body
     */
    private function hubungkan(array $akun, int $id, array $body): TestResponse
    {
        return $this->asUser($akun)->withBodyFormat('json')->post("api/v1/pegawai/{$this->nip}/arsip/berkas/{$id}/hubungkan", $body);
    }

    /**
     * @param array<string, mixed> $kolom
     */
    private function arsip(?int $kode, string $path, array $kolom = [], ?string $nip = null): int
    {
        $normal  = (string) PathLegacy::normalisasi($path);
        $lengkap = $this->akarLampiranUji() . '/' . $normal;

        if (! is_dir(dirname($lengkap))) {
            mkdir(dirname($lengkap), 0777, true);
        }

        file_put_contents($lengkap, '%PDF-1.4');

        return $this->insert('document_attachment', array_merge([
            'NIP'        => $nip ?? $this->nip,
            'filename'   => basename($normal),
            'id_riwayat' => $kode,
            'path'       => $path,
            'file_type'  => 'application/pdf',
        ], $kolom));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insert(string $tabel, array $data): int
    {
        $this->db->table($tabel)->insert($data);

        return (int) $this->db->insertID();
    }

    private function isiSheet(string $xlsx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents((string) $path, $xlsx);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open((string) $path) === true);
        $isi = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink((string) $path);

        return $isi;
    }

    /**
     * @return array<string, mixed>
     */
    private function akun(string $nip, int $role): array
    {
        $id = $this->buatAkunUntuk($nip, $role);

        /** @var array<string, mixed> $row */
        $row = $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray();

        return $row;
    }
}
