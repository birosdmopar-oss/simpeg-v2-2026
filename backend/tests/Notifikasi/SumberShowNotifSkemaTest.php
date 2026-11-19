<?php

declare(strict_types=1);

namespace Tests\Notifikasi;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Notifikasi\Pusat\SumberShowNotif;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
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

    private const JENIS_REGISTRY = ['kp', 'jabatan', 'kgb', 'pendidikan', 'diklat', 'seminar', 'organisasi', 'tanda-jasa', 'skp', 'keluarga', 'alamat', 'kariskarsu', 'karpeg', 'ak'];

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
        $tabel = self::KHUSUS;

        foreach (self::JENIS_REGISTRY as $jenis) {
            $d = Services::riwayatRegistry()->definisi($jenis);
            $this->assertNotNull($d, "jenis {$jenis} ada di registry");
            $tabel[$jenis] = [$d->tabel(), $d->kolomStatus()];
        }

        $this->assertCount(17, $tabel);

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
        $this->db->table('absen_ijin')->insert(['nip' => $this->nip, 'date_start' => '2026-11-03 07:30:00', 'date_end' => '2026-11-03 16:00:00', 'kategori' => 1, 'alasan' => 'Uji', 'status' => 'V', 'show_notif' => 1]);

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
        $this->assertSame(2, $belum['alamat']);
        $this->assertSame(1, $belum['konket']);
        $this->assertSame(3, array_sum($belum));
        $this->assertSame(3, $sumber->jumlah($auth, true));
        $this->assertSame(3, $semua['alamat'], 'daftar: show_notif 1, 3, 4');
        $this->assertSame(4, $sumber->jumlah($auth, false));

        // Identik dengan COUNT per tabel memakai kondisi daftar (satu kondisi dengan halaman modul, B-F8-1).
        foreach (['alamat' => 'riwayat_alamat', 'konket' => 'absen_ijin', 'lkh' => 'riwayat_lckh', 'biodata' => 'pegawai_hist'] as $k => $tabel) {
            $harap = $this->db->table($tabel)->where('nip', $this->nip)->where('status !=', '10')->where('show_notif', 1)->countAllResults();
            $this->assertSame($harap, $belum[$k], $k);
        }

        $data = json_decode($this->asRole(Role::PEGAWAI)->get('api/v1/notifikasi/ringkasan')->getJSON(), true)['data'];
        $this->assertSame(3, $data['notifikasi_belum_dibaca'], 'angka lonceng = jumlah sumber show_notif (tanpa inbox)');
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

        $klien  = $this->asRole(Role::SUPER_ADMIN);
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

        $news = array_values(array_filter($kueri, static fn (string $q): bool => preg_match('/FROM\s+`?\w*news`?/i', $q) === 1 && stripos($q, 'COUNT(') === false));
        $this->assertSame([], $news, 'lonceng tidak menjalankan SELECT daftar berita demi url_fe (C-03)');
    }
}
