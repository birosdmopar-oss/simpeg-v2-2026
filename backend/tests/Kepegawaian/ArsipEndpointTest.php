<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Arsip\ArsipService;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Models\AuditLogModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;
use Config\Arsip;
use Config\Services;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-090 — halaman Arsip F-10 Tahap 1 (PRD F-10 §5.2 AC1-01..AC1-13, AC1-16, AC1-17; §8.1–§8.2; §12.1).
 *
 * Endpoint nyata lewat HTTP (jwt + Role::filter(1–7) + PegawaiScope nyata) dengan akar penyimpanan sementara
 * (LocalStorageAdapter), throttler MockCache + waktu tetap, dan cache dedup MockCache.
 *
 * @internal
 */
final class ArsipEndpointTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LampiranUjiTrait;
    use PegawaiFixtureTrait;

    private const KUNCI_PDF = '2026/11/0123456789abcdef0123456789abcdef.pdf';

    private const KUNCI_DOCX = '2026/11/fedcba9876543210fedcba9876543210.docx';

    private string $nip;

    private int $satker;

    private Throttler $throttler;

    protected function setUp(): void
    {
        parent::setUp();

        Services::injectMock('storageAdapter', new LocalStorageAdapter($this->akarLampiranUji()));
        Services::injectMock('cache', new MockCache());
        $this->throttler = new Throttler(new MockCache());
        $this->throttler->setTestTime(1_790_000_000);
        Services::injectMock('throttler', $this->throttler);

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

    public function testTanpaToken401(): void
    {
        $v2 = $this->arsip(9, self::KUNCI_PDF);

        foreach (['arsip', 'arsip/berkas', "arsip/berkas/{$v2}/buka"] as $akhir) {
            $this->get("api/v1/pegawai/{$this->nip}/{$akhir}")->assertStatus(401);
        }
    }

    public function testMatriksRoleFolderDanDaftar(): void
    {
        $this->arsip(9, self::KUNCI_PDF, ['tag' => 'SK Jabatan']);
        $lain     = $this->buatPegawaiDiSatker($this->buatSatker());
        $tidakAda = '199912312099121001';
        $harapan  = [];
        $diSatker = $this->buatPegawaiDiSatker($this->satker);

        foreach ([Role::SUPER_ADMIN, Role::ADMIN_VIEW_ESELON1, Role::MENTERI] as $role) {
            $harapan[] = [$this->akun($this->buatPegawai(), $role), $this->nip, 200];
            $harapan[] = [$this->akun($this->buatPegawai(), $role), $lain, 200];
            $harapan[] = [$this->akun($this->buatPegawai(), $role), $tidakAda, 404];
        }

        foreach (Role::UL_PEGAWAI as $role) {
            $sendiri   = $this->buatPegawaiDiSatker($this->satker);
            $harapan[] = [$this->akun($sendiri, $role), $sendiri, 200];
            $harapan[] = [$this->akun($this->buatPegawaiDiSatker($this->satker), $role), $this->nip, 403];
        }

        $admin3    = $this->akun($diSatker, Role::ADMIN_SATKER);
        $harapan[] = [$admin3, $this->nip, 200];
        $harapan[] = [$admin3, $lain, 403];
        $harapan[] = [$admin3, $tidakAda, 403];
        $harapan[] = [$this->akun($this->buatPegawai(), Role::PIMPINAN), $this->nip, 403];

        foreach ($harapan as [$akun, $nip, $status]) {
            $pesan = 'role ' . $akun['user_level'] . ' → ' . ($nip === $this->nip ? 'nip uji' : $nip);
            $this->assertSame($status, $this->asUser($akun)->get("api/v1/pegawai/{$nip}/arsip")->response()->getStatusCode(), $pesan . ' (folder)');
            $this->assertSame($status, $this->asUser($akun)->get("api/v1/pegawai/{$nip}/arsip/berkas")->response()->getStatusCode(), $pesan . ' (berkas)');
        }

    }

    public function testFolderAgregatUrutanDanPenandaBerkas(): void
    {
        $this->arsip(9, self::KUNCI_PDF);
        $this->arsip(9, '2026/11/' . str_repeat('a', 32) . '.pdf', [], false); // hilang
        $this->arsip(14, 'arsip/' . $this->nip . '/pendidikan/ijazah.pdf');
        $this->arsip(38, 'kolom/foto/x.jpg', [], false);                         // tak dapat dibuka
        $this->arsip(0, 'assets/upload/arsip/lama.pdf');
        $this->arsip(null, 'arsip/' . $this->nip . '/.rahasia/a.pdf', [], false); // segmen berawalan titik
        $this->arsip(45, self::KUNCI_DOCX);

        $res = $this->asUser($this->akun($this->nip, Role::PEGAWAI))->get("api/v1/pegawai/{$this->nip}/arsip");
        $res->assertStatus(200);
        $data = $this->json($res)['data'];

        // Urutan `jenis_rwy.order`: 38 (3) → 9 (6) → 14 (13) → 45 (layanan), lalu Belum Terhubung (0), lalu Tanpa Jenis.
        $this->assertSame([38, 9, 14, 45, 0, null], array_column($data['folder'], 'id_jenis_rwy'));
        $perKode = array_column($data['folder'], null, 'kode');
        $this->assertSame([2, 1, 0], [$perKode['jabatan']['jumlah'], $perKode['jabatan']['jumlah_hilang'], $perKode['jabatan']['jumlah_tak_dapat_dibuka']]);
        $this->assertSame([1, 0, 1], [$perKode['arsip_du']['jumlah'], $perKode['arsip_du']['jumlah_hilang'], $perKode['arsip_du']['jumlah_tak_dapat_dibuka']]);
        $this->assertSame(['Tanpa Jenis', null, 1], [$perKode['tanpa_jenis']['label'], $perKode['tanpa_jenis']['urutan'], $perKode['tanpa_jenis']['jumlah_tak_dapat_dibuka']]);
        $this->assertSame(['Belum Terhubung', 0], [$perKode['belum_terhubung']['label'], $perKode['belum_terhubung']['urutan']]);
        $this->assertSame([7, 1, 2], [$data['total'], $data['total_hilang'], $data['total_tak_dapat_dibuka']]);
        $this->assertSame('publik', $data['penyajian_berkas_legacy']);
        $this->assertSame(['tulis' => false, 'jenis_hubungkan' => []], $data['aksi']);
        $this->assertSame($this->nip, $data['pegawai']['nip']);
        $this->assertSame(['nip', 'nama', 'glr_depan', 'glr_belakang'], array_keys($data['pegawai']));

        // Folder 0 berkas tidak dikirim.
        $this->assertNotContains(5, array_column($data['folder'], 'id_jenis_rwy'));
    }

    public function testDaftarKolomTertutupUrlDanAksesBerkas(): void
    {
        $v2     = $this->arsip(9, self::KUNCI_PDF, ['tag' => null, 'display_name' => null, 'filename' => basename(self::KUNCI_PDF)]);
        $docx   = $this->arsip(45, self::KUNCI_DOCX, ['tag' => 'SK Jabatan', 'display_name' => 'sk.docx']);
        $legacy = $this->arsip(0, '/arsip\\' . $this->nip . '/sk cpns/SK CPNS.PDF', ['tag' => '', 'filename' => 'SK CPNS.PDF', 'url' => 'arsip/x', 'basename' => 'SK CPNS', 'document_id' => 9]);
        $assets = $this->arsip(38, 'assets/upload/ktp/ktp.jpg', ['tag' => 'KTP']);
        $hilang = $this->arsip(14, 'arsip/' . $this->nip . '/hilang.pdf', [], false);
        $titik  = $this->arsip(14, 'arsip/' . $this->nip . '/.git/a.pdf');
        $form   = $this->arsip(14, 'form-digital/1/a.pdf');

        $res = $this->asUser($this->akun($this->nip, Role::PEGAWAI))->get("api/v1/pegawai/{$this->nip}/arsip/berkas");
        $res->assertStatus(200);
        $body  = (string) $res->response()->getBody();
        $data  = $this->json($res)['data'];
        $items = array_column($data['items'], null, 'id_attachment');

        // AC1-10: kolom tertutup; kunci v2 (termasuk basename) tidak pernah keluar.
        foreach ($data['items'] as $item) {
            foreach (['path', 'url', 'basename', 'document_id', 'NIP', 'filename'] as $terlarang) {
                $this->assertArrayNotHasKey($terlarang, $item);
            }
        }

        $this->assertStringNotContainsString('0123456789abcdef0123456789abcdef', $body);
        $this->assertStringNotContainsString('fedcba9876543210', $body);
        $this->assertSame([$form, $titik, $hilang, $assets, $legacy, $docx, $v2], array_keys($items), 'urut id menurun');
        $this->assertSame(7, $data['total']);
        $this->assertSame([1, 25], [$data['page'], $data['per_page']]);

        $this->assertSame(['Berkas ' . $v2, 'privat', "pegawai/{$this->nip}/arsip/berkas/{$v2}/buka", 'pratinjau', 'ada', true], [
            $items[$v2]['nama_arsip'], $items[$v2]['akses_berkas'], $items[$v2]['url_berkas'], $items[$v2]['jenis_buka'], $items[$v2]['status_berkas'], $items[$v2]['berkas_ada'],
        ]);
        $this->assertSame(['SK Jabatan', 'privat', 'unduh'], [$items[$docx]['nama_arsip'], $items[$docx]['akses_berkas'], $items[$docx]['jenis_buka']]);
        // Path legacy (awalan `/`, `\`, spasi, ekstensi huruf besar) → URL publik ter-encode per segmen.
        $this->assertSame(['SK CPNS.PDF', 'publik', '/berkas/arsip/' . $this->nip . '/sk%20cpns/SK%20CPNS.PDF', 'pratinjau'], [
            $items[$legacy]['nama_arsip'], $items[$legacy]['akses_berkas'], $items[$legacy]['url_berkas'], $items[$legacy]['jenis_buka'],
        ]);
        $this->assertSame(['publik', '/berkas/assets/upload/ktp/ktp.jpg'], [$items[$assets]['akses_berkas'], $items[$assets]['url_berkas']]);
        $this->assertSame(['hilang', false, null, null], [$items[$hilang]['status_berkas'], $items[$hilang]['berkas_ada'], $items[$hilang]['url_berkas'], $items[$hilang]['akses_berkas']]);

        foreach ([$titik, $form] as $id) {
            $this->assertSame(['tak_dapat_dibuka', null, null, null], [$items[$id]['status_berkas'], $items[$id]['url_berkas'], $items[$id]['akses_berkas'], $items[$id]['jenis_buka']]);
        }

        $this->assertSame(['Belum Terhubung', 'Data Umum', 'Jabatan'], [$items[$legacy]['jenis_riwayat'], $items[$assets]['jenis_riwayat'], $items[$v2]['jenis_riwayat']]);
        $this->assertSame(['edit' => false, 'hubungkan' => false, 'hapus' => false], $items[$v2]['aksi']);
    }

    public function testSaklarPrivatMembuatBerkasLegacyLewatBuka(): void
    {
        config(Arsip::class)->penyajianBerkasLegacy = 'privat';
        $legacy                                     = $this->arsip(0, 'arsip/' . $this->nip . '/sk.pdf');
        $v2                                         = $this->arsip(9, self::KUNCI_PDF);
        $pegawai                                    = $this->akun($this->nip, Role::PEGAWAI);

        $data  = $this->json($this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/arsip/berkas"))['data'];
        $items = array_column($data['items'], null, 'id_attachment');
        $this->assertSame(['privat', "pegawai/{$this->nip}/arsip/berkas/{$legacy}/buka"], [$items[$legacy]['akses_berkas'], $items[$legacy]['url_berkas']]);
        $this->assertSame('privat', $items[$v2]['akses_berkas']);
        $this->assertSame('privat', $this->json($this->asUser($pegawai)->get("api/v1/pegawai/{$this->nip}/arsip"))['data']['penyajian_berkas_legacy']);
    }

    public function testFilterJenisPencarianLiteralDanPaginasi(): void
    {
        $persen = $this->arsip(9, self::KUNCI_PDF, ['tag' => 'Diskon 50% SK']);
        $this->arsip(9, '2026/11/' . str_repeat('b', 32) . '.pdf', ['tag' => 'Diskon 50 SK']);
        $tanpa = $this->arsip(null, 'kolom/a.pdf', ['tag' => 'tanpa'], false);

        for ($i = 0; $i < 3; $i++) {
            $this->arsip(14, 'arsip/' . $this->nip . "/p{$i}.pdf", ['display_name' => "ijazah_{$i}"]);
        }

        $akun = $this->akun($this->nip, Role::PEGAWAI);
        $ids  = fn (string $qs): array => array_column($this->json($this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip/berkas?{$qs}"))['data']['items'], 'id_attachment');

        $this->assertSame([$persen], $ids('q=' . rawurlencode('50%')));
        $this->assertCount(0, $ids('q=' . rawurlencode('ijazah%')));
        $this->assertCount(0, $ids('q=' . rawurlencode('ijazah__')));
        $this->assertCount(3, $ids('q=ijazah_'));
        $this->assertSame([$tanpa], $ids('id_jenis_rwy=tanpa_jenis'));
        $this->assertCount(2, $ids('id_jenis_rwy=9'));
        $this->assertCount(0, $ids('id_jenis_rwy=5'));

        $halaman = $this->json($this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip/berkas?per_page=500&page=1"))['data'];
        $this->assertSame([100, 6], [$halaman['per_page'], $halaman['total']]);
        $dua = $this->json($this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip/berkas?per_page=4&page=2"))['data'];
        $this->assertCount(2, $dua['items']);

        $this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip/berkas?id_jenis_rwy=abc")->assertStatus(422);
        $this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip/berkas?sort=path")->assertStatus(422);
        $this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip/berkas?order=naik")->assertStatus(422);
        $nama = array_column($this->json($this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip/berkas?sort=nama&order=asc"))['data']['items'], 'nama_arsip');
        $this->assertSame('Diskon 50 SK', $nama[0]);
    }

    public function testRingkasanRiwayatPerKodeDanMenggantung(): void
    {
        $rmj = $this->buatRiwayatMutasiJabatan($this->nip, ['jabatan' => 'Analis SDM', 'satker' => 'Biro Uji']);
        $this->db->table('riwayat_pendidikan')->insert(['nip' => $this->nip, 'jenjang_pendidikan_singkat' => 'S1', 'institusi_pendidikan' => 'Univ Uji', 'tgl_lulus' => '2012-08-30', 'status' => 10]);
        $pend = (int) $this->db->insertID();
        $this->db->table('riwayat_hukdis')->insert(['nip' => $this->nip, 'tmtsk' => '2021-03-05', 'tingkat_hukdis' => 'Ringan', 'status' => 1]);
        $hukdis = (int) $this->db->insertID();
        $this->db->table('riwayat_keluarga')->insert(['nip' => $this->nip, 'nama_pasangan' => 'Pasangan Uji', 'jumlah_anak' => 2, 'tgl_perkawinan' => '2010-01-01', 'status' => 1]);
        $kel = (int) $this->db->insertID();
        $this->db->table('detail_anak')->insert(['id_riwayat_keluarga' => $kel, 'nama' => 'Anak Uji', 'tgl_lahir' => '2012-01-01']);
        $anak = (int) $this->db->insertID();
        $lain = $this->buatRiwayatMutasiJabatan($this->buatPegawai(), ['jabatan' => 'Rahasia', 'satker' => 'Lain']);

        $id = [
            'jabatan'     => $this->arsip(9, 'arsip/a/1.pdf', [], false, (string) $rmj),
            'pjft'        => $this->arsip(36, 'arsip/a/2.pdf', [], false, (string) $rmj),
            'pendidikan'  => $this->arsip(14, 'arsip/a/3.pdf', [], false, (string) $pend),
            'hukdis'      => $this->arsip(7, 'arsip/a/4.pdf', [], false, (string) $hukdis),
            'keluarga'    => $this->arsip(20, 'arsip/a/5.pdf', [], false, (string) $kel),
            'anak'        => $this->arsip(3, 'arsip/a/6.pdf', [], false, (string) $anak),
            'menggantung' => $this->arsip(11, 'arsip/a/7.pdf', [], false, '999999'),
            'nip_lain'    => $this->arsip(9, 'arsip/a/8.pdf', [], false, (string) $lain),
            'data_umum'   => $this->arsip(37, 'arsip/a/9.pdf', [], false, $this->nip),
        ];

        $items = array_column($this->json($this->asUser($this->akun($this->nip, Role::PEGAWAI))->get("api/v1/pegawai/{$this->nip}/arsip/berkas"))['data']['items'], 'ringkasan_riwayat', 'id_attachment');

        $this->assertSame('Analis SDM - Biro Uji', $items[$id['jabatan']]);
        $this->assertSame('Analis SDM - Biro Uji', $items[$id['pjft']], 'kode 36 = bentuk kode 9');
        $this->assertSame('S1 - Univ Uji', $items[$id['pendidikan']], 'status 10 tetap diringkas (F10-16)');
        $this->assertSame('5 Maret 2021 - Ringan', $items[$id['hukdis']]);
        $this->assertSame('Pasangan Uji - 2 Anak', $items[$id['keluarga']]);
        $this->assertSame('Anak Uji', $items[$id['anak']]);

        foreach (['menggantung', 'nip_lain', 'data_umum'] as $k) {
            $this->assertSame('—', $items[$id[$k]], $k);
        }
    }

    public function testCatatanPembukaanDaftarDedupDanPemilikTidakDicatat(): void
    {
        $this->arsip(9, self::KUNCI_PDF);
        $admin = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);

        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip")->assertStatus(200);
        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/berkas?q=rahasia-cari")->assertStatus(200);

        $rows = $this->db->table('audit_logs')->where('entity', 'arsip_lihat')->get()->getResultArray();
        $this->assertCount(1, $rows, 'dedup 15 menit per (aktor, NIP)');
        $this->assertSame([$this->nip, 'create', (int) $admin['id_pengguna']], [$rows[0]['entity_id'], $rows[0]['event'], (int) $rows[0]['id_pengguna_actor']]);
        $this->assertSame('folder', json_decode((string) $rows[0]['after_json'], true)['endpoint']);

        // Sesudah cache dedup kedaluwarsa: tercatat lagi; teks q tidak pernah dicatat.
        Services::injectMock('cache', new MockCache());
        $this->asUser($admin)->get("api/v1/pegawai/{$this->nip}/arsip/berkas?q=rahasia-cari")->assertStatus(200);
        $rows = $this->db->table('audit_logs')->where('entity', 'arsip_lihat')->orderBy('id_log', 'ASC')->get()->getResultArray();
        $this->assertCount(2, $rows);
        $this->assertEquals(['endpoint' => 'berkas', 'filter' => ['id_jenis_rwy' => null, 'belum_terhubung' => null, 'ada_pencarian' => true]], json_decode((string) $rows[1]['after_json'], true));
        $this->assertStringNotContainsString('rahasia-cari', (string) $rows[1]['after_json']);

        // Pemilik (role 2) dan admin yang membuka arsipnya sendiri tidak dicatat.
        $this->asUser($this->akun($this->nip, Role::PEGAWAI))->get("api/v1/pegawai/{$this->nip}/arsip")->assertStatus(200);
        $this->asUser($admin)->get("api/v1/pegawai/{$admin['nip']}/arsip")->assertStatus(200);
        $this->assertSame(2, $this->db->table('audit_logs')->where('entity', 'arsip_lihat')->countAllResults());
    }

    public function testCatatanPembukaanDaftarFailClosed(): void
    {
        $admin   = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $service = new ArsipService($this->db, service('pegawaiScope'), service('storageAdapter'), new Arsip(), $this->throttler, $this->auditNol(), new MockCache());

        $this->expectException(RuntimeException::class);
        $service->folder($this->authUntukAkun((int) $admin['id_pengguna']), $this->nip);
    }

    public function testBukaHeaderAuditDanNamaBerekstensi(): void
    {
        $pdf     = $this->arsip(9, self::KUNCI_PDF, ['tag' => 'SK "Jabatan"' . "\r\nX: 1"]);
        $docx    = $this->arsip(45, self::KUNCI_DOCX, ['tag' => 'SK Jabatan', 'display_name' => 'surat.docx']);
        $pemilik = $this->akun($this->nip, Role::PEGAWAI);

        $res = $this->asUser($pemilik)->get("api/v1/pegawai/{$this->nip}/arsip/berkas/{$pdf}/buka");
        $res->assertStatus(200);
        $r = $res->response();
        $this->assertStringStartsWith('%PDF', (string) $r->getBody());
        $this->assertSame('application/pdf', $r->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('inline; filename="SK_Jabatan_X_1.pdf"', $r->getHeaderLine('Content-Disposition'));
        $this->assertStringNotContainsString("\n", $r->getHeaderLine('Content-Disposition'));
        $this->assertSame('nosniff', $r->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('private, no-store', $r->getHeaderLine('Cache-Control'));
        $this->assertSame('no-referrer', $r->getHeaderLine('Referrer-Policy'));
        $this->assertSame(Arsip::CSP_BERKAS, $r->getHeaderLine('Content-Security-Policy'));

        $unduh = $this->asUser($pemilik)->get("api/v1/pegawai/{$this->nip}/arsip/berkas/{$docx}/buka")->response();
        $this->assertSame(200, $unduh->getStatusCode());
        $this->assertSame('application/octet-stream', $unduh->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="SK_Jabatan.docx"; filename*=UTF-8\'\'SK%20Jabatan.docx', $unduh->getHeaderLine('Content-Disposition'));

        // F10-35: setiap 200 dicatat, termasuk pemilik; tanpa nama berkas/path/tag.
        $rows = $this->db->table('audit_logs')->where('entity', 'arsip_unduh')->orderBy('id_log', 'ASC')->get()->getResultArray();
        $this->assertCount(2, $rows);
        $this->assertSame([$this->nip, 'create', (int) $pemilik['id_pengguna'], $this->nip], [$rows[0]['entity_id'], $rows[0]['event'], (int) $rows[0]['id_pengguna_actor'], $rows[0]['nip_actor']]);
        $this->assertEquals(['id_attachment' => $pdf, 'id_riwayat' => 9, 'jenis_buka' => 'pratinjau'], json_decode((string) $rows[0]['after_json'], true));
        $this->assertSame('unduh', json_decode((string) $rows[1]['after_json'], true)['jenis_buka']);
        $this->assertNull($rows[0]['before_json']);
    }

    public function testBukaPenolakanDanJalurLegacy(): void
    {
        $v2        = $this->arsip(9, self::KUNCI_PDF);
        $legacy    = $this->arsip(0, 'arsip/' . $this->nip . '/sk.pdf');
        $assets    = $this->arsip(38, 'assets/upload/ktp.jpg');
        $hilang    = $this->arsip(9, '2026/11/' . str_repeat('c', 32) . '.pdf', [], false);
        $takCocok  = $this->arsip(9, 'kolom/a.pdf');
        $gantung   = $this->arsip(14, '2026/11/' . str_repeat('d', 32) . '.pdf', [], true, '424242');
        $nipLain   = $this->buatPegawaiDiSatker($this->satker);
        $milikLain = $this->arsip(9, '2026/11/' . str_repeat('e', 32) . '.pdf', [], true, null, $nipLain);
        $admin     = $this->akun($this->buatPegawai(), Role::SUPER_ADMIN);
        $url       = fn (int $id, ?string $nip = null): string => 'api/v1/pegawai/' . ($nip ?? $this->nip) . "/arsip/berkas/{$id}/buka";

        foreach ([$legacy, $assets, $hilang, $takCocok, 999999, $milikLain] as $id) {
            $this->asUser($admin)->get($url($id))->assertStatus(404);
        }

        $this->asUser($admin)->get($url($gantung))->assertStatus(200);
        $this->asUser($admin)->get($url($v2))->assertStatus(200);
        $this->asUser($this->akun($this->buatPegawai(), Role::PIMPINAN))->get($url($v2))->assertStatus(403);
        $this->asUser($this->akun($nipLain, Role::PEGAWAI))->get($url($v2))->assertStatus(403);
        $this->asUser($this->akun($this->buatPegawaiDiSatker($this->buatSatker()), Role::ADMIN_SATKER))->get($url($v2))->assertStatus(403);

        // Rollback 'privat': berkas legacy (dibaca lewat PathLegacy) juga 200 dengan header dan audit yang sama.
        config(Arsip::class)->penyajianBerkasLegacy = 'privat';
        $res                                        = $this->asUser($admin)->get($url($legacy))->response();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/pdf', $res->getHeaderLine('Content-Type'));
        $this->asUser($admin)->get($url($assets))->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(4, $this->db->table('audit_logs')->where('entity', 'arsip_unduh')->countAllResults());
    }

    public function testThrottleTokenBucketSesudahPenolakanPasti(): void
    {
        $v2   = $this->arsip(9, self::KUNCI_PDF);
        $akun = $this->akun($this->nip, Role::PEGAWAI);
        $url  = "api/v1/pegawai/{$this->nip}/arsip/berkas/{$v2}/buka";

        // 404/403 tidak memakan jatah.
        for ($i = 0; $i < 8; $i++) {
            $this->asUser($akun)->get("api/v1/pegawai/{$this->nip}/arsip/berkas/999999/buka")->assertStatus(404);
            $this->asUser($akun)->get('api/v1/pegawai/' . $this->buatPegawai() . "/arsip/berkas/{$v2}/buka")->assertStatus(403);
        }

        for ($i = 0; $i < 6; $i++) {
            $this->asUser($akun)->get($url)->assertStatus(200);
        }

        $tolak = $this->asUser($akun)->get($url);
        $tolak->assertStatus(429);
        $this->assertSame(Arsip::PESAN_THROTTLE, $this->json($tolak)['message']);

        // Ember terpisah per akun: akun lain tetap boleh; ±10 detik kemudian satu jatah terisi lagi.
        $this->asUser($this->akun($this->buatPegawai(), Role::SUPER_ADMIN))->get($url)->assertStatus(200);
        $this->throttler->setTestTime(1_790_000_011);
        $this->asUser($akun)->get($url)->assertStatus(200);
        $this->assertSame(8, $this->db->table('audit_logs')->where('entity', 'arsip_unduh')->countAllResults());
    }

    public function testBukaAuditNolTetapBerhasil(): void
    {
        $v2      = $this->arsip(9, self::KUNCI_PDF);
        $service = new ArsipService($this->db, service('pegawaiScope'), service('storageAdapter'), new Arsip(), $this->throttler, $this->auditNol(), new MockCache());
        $hasil   = $service->buka($this->authUntukAkun($this->buatAkunUntuk($this->nip, Role::PEGAWAI)), $this->nip, $v2);

        $this->assertSame(['application/pdf', true], [$hasil['tipe'], $hasil['inline']]);
    }

    /**
     * Baris `document_attachment` milik $nip (bawaan NIP uji); $tulis = buat berkasnya di akar uji (path ternormalisasi).
     *
     * @param array<string, mixed> $kolom
     */
    private function arsip(?int $kode, string $path, array $kolom = [], bool $tulis = true, ?string $idEntri = null, ?string $nip = null): int
    {
        if ($tulis) {
            $normal  = (string) \App\Libraries\Kepegawaian\Lampiran\PathLegacy::normalisasi($path);
            $lengkap = $this->akarLampiranUji() . '/' . $normal;

            if (! is_dir(dirname($lengkap))) {
                mkdir(dirname($lengkap), 0777, true);
            }

            file_put_contents($lengkap, str_ends_with(strtolower($normal), '.pdf') ? "%PDF-1.4\n%%EOF" : 'ISI');
        }

        $this->db->table('document_attachment')->insert(array_merge([
            'NIP'        => $nip ?? $this->nip,
            'filename'   => basename(str_replace('\\', '/', $path)),
            'id_riwayat' => $kode,
            'id_entri'   => $idEntri,
            'path'       => $path,
            'file_type'  => 'application/pdf',
        ], $kolom));

        return (int) $this->db->insertID();
    }

    private function auditNol(): AuditLogModel
    {
        return new class () extends AuditLogModel {
            public function record(string $entity, int|string $entityId, string $event, ?array $before, ?array $after, ?int $actorId = null, ?string $actorNip = null): int
            {
                return 0;
            }
        };
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
