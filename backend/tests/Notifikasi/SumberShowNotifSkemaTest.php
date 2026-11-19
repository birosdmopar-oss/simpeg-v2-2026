<?php

declare(strict_types=1);

namespace Tests\Notifikasi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Dashboard\Konten\Berita\BeritaService;
use App\Libraries\Notifikasi\Pusat\SumberShowNotif;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use ReflectionClassConstant;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * CR-159 — penjaga optimasi lonceng: (C-01) definisi sumber `show_notif` tidak lagi mengintrospeksi skema per request, jadi
 * konstanta {@see SumberShowNotif::TANPA_NOTIF_DATE}/{@see SumberShowNotif::TANPA_REASON_NOTE} wajib cocok dengan skema
 * hasil migration; (C-02) COUNT per sumber lewat satu `UNION ALL` wajib identik dengan COUNT per tabel (B-F8-1) dan hanya
 * satu kueri; (C-03) lonceng role 1 tidak menjalankan daftar berita demi `url_fe`.
 *
 * @internal
 */
final class SumberShowNotifSkemaTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;

    protected $seed = AuthSeeder::class;

    /** Tabel dan kolom status sumber khusus; kuncinya wajib sama dengan `SumberShowNotif::KHUSUS` (dicek di test). */
    private const KHUSUS = ['konket' => ['absen_ijin', 'status'], 'lkh' => ['riwayat_lckh', 'status'], 'biodata' => ['pegawai_hist', 'flag_update']];

    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->nip = AuthSeeder::nipForRole(Role::PEGAWAI);
        $this->daftarkanPegawai(...array_column(AuthSeeder::accounts(), 'nip'));
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        parent::tearDown();
    }

    public function testKonstantaDefinisiCocokDenganSkema(): void
    {
        // Daftar jenis dibaca dari kelasnya (bukan disalin): jenis baru otomatis ikut diperiksa terhadap skema.
        $konstanta = static fn (string $nama): array => (array) (new ReflectionClassConstant(SumberShowNotif::class, $nama))->getValue();
        $registry  = array_values(array_unique([...$konstanta('JENIS_PNS'), ...$konstanta('JENIS_PTT')]));
        $this->assertSame($konstanta('KHUSUS'), array_keys(self::KHUSUS), 'sumber khusus di test = di kelas');
        $this->assertNotSame([], $registry);

        $tabel = self::KHUSUS;

        foreach ($registry as $jenis) {
            $d = Services::riwayatRegistry()->definisi($jenis);
            $this->assertNotNull($d, "jenis {$jenis} ada di registry");
            $tabel[$jenis] = [$d->tabel(), $d->kolomStatus()];
        }

        $this->assertCount(count($registry) + count(self::KHUSUS), $tabel);
        $this->assertCount(17, $tabel, 'saat CR-159: 14 jenis registry + 3 khusus');

        foreach ($tabel as $kunci => [$nama, $kolomStatus]) {
            $this->assertTrue($this->db->tableExists($this->db->prefixTable($nama), false), "tabel {$nama} ada (migration di main)");
            $kolom = $this->db->getFieldNames($nama);

            foreach (['nip', 'show_notif', 'status', $kolomStatus] as $wajib) {
                $this->assertContains($wajib, $kolom, "{$nama}.{$wajib}");
            }

            $this->assertSame(! in_array($kunci, SumberShowNotif::TANPA_NOTIF_DATE, true), in_array('notif_date', $kolom, true), "{$nama}.notif_date vs TANPA_NOTIF_DATE");
            $this->assertSame(! in_array($kunci, SumberShowNotif::TANPA_REASON_NOTE, true), in_array('reason_note', $kolom, true), "{$nama}.reason_note vs TANPA_REASON_NOTE");
            $this->assertContains('updated_at', $kolom, "{$nama}.updated_at (kolom waktu pengganti)");
        }
    }

    public function testJumlahUnionAllIdentikPerSumberDanSatuKueri(): void
    {
        $this->db->table('riwayat_alamat')->insert(['nip' => $this->nip, 'status' => 1, 'show_notif' => 1, 'notif_date' => '2026-11-02 09:00:00']);
        $this->db->table('riwayat_alamat')->insert(['nip' => $this->nip, 'status' => 2, 'show_notif' => 1, 'notif_date' => '2026-11-03 09:00:00']);
        $this->db->table('riwayat_alamat')->insert(['nip' => $this->nip, 'status' => 1, 'show_notif' => 3, 'notif_date' => '2026-11-03 09:00:00']);
        $this->db->table('riwayat_alamat')->insert(['nip' => $this->nip, 'status' => 10, 'show_notif' => 1]); // hapus lunak: dilewati
        $this->db->table('riwayat_alamat')->insert(['nip' => AuthSeeder::nipForRole(Role::PPPK), 'status' => 1, 'show_notif' => 1]); // milik orang lain
        $this->db->table('riwayat_alamat')->insert(['nip' => $this->nip, 'status' => 1, 'show_notif' => 1, 'notif_date' => '2026-11-04 09:00:00']);
        $this->db->table('riwayat_alamat')->insert(['nip' => $this->nip, 'status' => 0, 'show_notif' => 1, 'notif_date' => '2026-11-05 09:00:00']);

        // Jumlah belum dibaca sengaja berbeda per sumber (alamat 4, konket 3, biodata 2, lkh 1) supaya angka yang tertukar
        // antar-cabang UNION ALL langsung terlihat.
        for ($i = 1; $i <= 3; $i++) {
            $this->db->table('absen_ijin')->insert(['nip' => $this->nip, 'date_start' => "2026-11-0{$i} 07:30:00", 'date_end' => "2026-11-0{$i} 16:00:00", 'kategori' => 1, 'alasan' => 'Uji', 'status' => 'V', 'show_notif' => 1]);
        }

        $this->db->table('absen_ijin')->insert(['nip' => $this->nip, 'date_start' => '2026-11-06 07:30:00', 'date_end' => '2026-11-06 16:00:00', 'kategori' => 1, 'alasan' => 'Uji', 'status' => 'V', 'show_notif' => 3]);

        foreach ([1, 1, 3] as $showNotif) {
            $this->db->table('pegawai_hist')->insert(['nip' => $this->nip, 'nama' => 'Uji', 'tgl_lahir' => '1990-01-01', 'flag_update' => 3, 'show_notif' => $showNotif]);
        }

        $this->db->table('riwayat_lckh')->insert([
            'nip'         => $this->nip, 'tgl_laporan' => '2026-11-03', 'nama' => 'Uji', 'nip_atasan' => AuthSeeder::nipForRole(Role::PPPK),
            'nama_atasan' => 'Atasan', 'kegiatan' => '["A"]', 'output' => '["B"]', 'status' => 1, 'show_notif' => 1,
        ]);

        $auth = new AuthContext();
        $auth->setClaims(['sub' => 1, 'role' => Role::PEGAWAI, 'nip' => $this->nip]);
        $sumber = new SumberShowNotif($this->db, Services::riwayatRegistry());
        $kunci  = $sumber->kunci($auth);
        $this->assertCount(17, $kunci, 'role 2: 14 jenis registry + 3 khusus');

        $kueri  = [];
        $dengar = static function ($q) use (&$kueri): void {
            $kueri[] = (string) $q;
        };
        Events::on('DBQuery', $dengar);

        try {
            $belum = $sumber->jumlahPerKunci($auth, true);
            $this->assertCount(1, $kueri, 'satu kueri UNION ALL, tanpa kueri metadata');
            $this->assertStringContainsString('UNION ALL', $kueri[0]);
            $semua = $sumber->jumlahPerKunci($auth, false);
        } finally {
            Events::removeListener('DBQuery', $dengar);
        }

        $this->assertSame($kunci, array_keys($belum), 'urutan cabang = urutan kunci');
        $this->assertSame(['alamat' => 4, 'konket' => 3, 'lkh' => 1, 'biodata' => 2], array_filter($belum), 'jumlah per sumber tidak tertukar');
        $this->assertSame(10, array_sum($belum));
        $this->assertSame(10, $sumber->jumlah($auth, true));
        $this->assertSame(['alamat' => 5, 'konket' => 4, 'lkh' => 1, 'biodata' => 3], array_filter($semua), 'daftar: show_notif 1, 3, 4');
        $this->assertSame(13, $sumber->jumlah($auth, false));

        // Identik dengan COUNT per tabel memakai kondisi daftar (satu kondisi dengan halaman modul, B-F8-1).
        foreach (['alamat' => 'riwayat_alamat', 'konket' => 'absen_ijin', 'lkh' => 'riwayat_lckh', 'biodata' => 'pegawai_hist'] as $k => $tabel) {
            $harap = $this->db->table($tabel)->where('nip', $this->nip)->where('status !=', '10')->where('show_notif', 1)->countAllResults();
            $this->assertSame($harap, $belum[$k], $k);
        }

        $data = json_decode($this->asRole(Role::PEGAWAI)->get('api/v1/notifikasi/ringkasan')->getJSON(), true)['data'];
        $this->assertSame(10, $data['notifikasi_belum_dibaca'], 'angka lonceng = jumlah sumber show_notif (tanpa inbox)');

        // Satu kondisi dengan halaman notifikasi (B-F8-1): `belum_dibaca` dan `total` daftar = jumlah UNION ALL.
        Services::resetSingle('response');
        $daftar = json_decode($this->asRole(Role::PEGAWAI)->get('api/v1/notifikasi?status=belum&per_page=50')->getJSON(), true)['data'];
        $this->assertSame([10, 10], [$daftar['belum_dibaca'], $daftar['total']]);
        $this->assertCount(10, $daftar['items']);
    }

    public function testRingkasanRole2TanpaKueriMetadata(): void
    {
        Services::reset(true);
        Database::connect($this->DBGroup)->dataCache = [];
        $this->clearAuthState();

        $klien  = $this->asRole(Role::PEGAWAI);
        $kueri  = [];
        $dengar = static function ($q) use (&$kueri): void {
            $kueri[] = (string) $q;
        };
        Events::on('DBQuery', $dengar);

        try {
            $klien->get('api/v1/notifikasi/ringkasan')->assertStatus(200);
        } finally {
            Events::removeListener('DBQuery', $dengar);
        }

        $metadata = array_values(array_filter($kueri, static fn (string $q): bool => preg_match('/^\s*(SHOW|DESCRIBE)\b|information_schema/i', $q) === 1));
        $this->assertSame([], $metadata, 'lonceng role 2 tanpa SHOW TABLES / SHOW COLUMNS (C-01)');
        $this->assertSame(1, count(array_filter($kueri, static fn (string $q): bool => str_contains($q, 'UNION ALL') && str_contains($q, 'show_notif'))), 'satu UNION ALL show_notif (C-02)');
    }

    public function testLoncengRole1TidakMenjalankanDaftarBerita(): void
    {
        if (! $this->db->tableExists($this->db->prefixTable('news'), false)) {
            $this->markTestSkipped('tabel news belum ada');
        }

        // Ada berita yang menunggu persetujuan: tanpa ini lonceng tidak pernah menyentuh cabang berita dan test tidak
        // mengunci apa pun (review R1).
        foreach (['Berita menunggu A', 'Berita menunggu B'] as $judul) {
            $this->db->table('news')->insert(['title' => $judul, 'news' => '<p>Isi</p>', 'publish_at' => '2026-11-10 02:00:00', 'status' => BeritaService::MENUNGGU]);
        }

        $this->db->table('news')->insert(['title' => 'Berita terbit', 'news' => '<p>Isi</p>', 'publish_at' => '2026-11-10 02:00:00', 'status' => BeritaService::TERBIT]);

        $klien  = $this->asRole(Role::SUPER_ADMIN);
        $kueri  = [];
        $dengar = static function ($q) use (&$kueri): void {
            $kueri[] = (string) $q;
        };
        Events::on('DBQuery', $dengar);

        try {
            $res = $klien->get('api/v1/notifikasi/ringkasan');
        } finally {
            Events::removeListener('DBQuery', $dengar);
        }

        $res->assertStatus(200);
        $ringkasan = json_decode($res->getJSON(), true)['data'];
        $this->assertSame(2, $ringkasan['butuh_approval'], 'dua berita menunggu terhitung di lonceng role 1');

        $news = array_values(array_filter($kueri, static fn (string $q): bool => preg_match('/FROM\s+`?\w*news`?/i', $q) === 1));
        $this->assertNotSame([], $news, 'lonceng menghitung berita menunggu');

        foreach ($news as $q) {
            $this->assertMatchesRegularExpression('/COUNT\(/i', $q, 'lonceng hanya menghitung berita, tanpa SELECT daftar demi url_fe (C-03)');
        }

        // Ujung ke ujung: halaman butuh-approval tetap memuat grup berita dengan jumlah dan url_fe seperti sebelumnya.
        Services::resetSingle('response');
        $data = json_decode($this->asRole(Role::SUPER_ADMIN)->get('api/v1/notifikasi/butuh-approval')->getJSON(), true)['data'];
        $grup = array_column($data['grup'], null, 'kode');
        $this->assertArrayHasKey('berita', $grup);
        $this->assertSame(['kode' => 'berita', 'label' => 'Berita', 'jumlah' => 2, 'url_fe' => '/konten/berita'], $grup['berita']);
        $this->assertSame($ringkasan['butuh_approval'], $data['total'], 'lonceng = total halaman butuh-approval');
    }

    public function testGrupBeritaTanpaAntreanUrlFeNull(): void
    {
        if (! $this->db->tableExists($this->db->prefixTable('news'), false)) {
            $this->markTestSkipped('tabel news belum ada');
        }

        $data = json_decode($this->asRole(Role::SUPER_ADMIN)->get('api/v1/notifikasi/butuh-approval')->getJSON(), true)['data'];
        $grup = array_column($data['grup'], null, 'kode');

        if (isset($grup['berita'])) {
            $this->assertSame([0, null], [$grup['berita']['jumlah'], $grup['berita']['url_fe']]);
        } else {
            $this->addToAssertionCount(1);
        }
    }
}
