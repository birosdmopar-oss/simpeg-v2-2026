<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg;

use App\Constants\Role;
use App\Libraries\HaloSimpeg\EksporTiketService;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\HaloSimpeg;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SimpleXMLElement;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\HaloSimpegFixtureTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use ZipArchive;

/**
 * MAKE-074 — ekspor rekap petugas (E-09; PRD Fase 8 v1.4 §7.9, F8-20, §7.3a, AC-E09.1..AC-E09.8). Isi xlsx dibaca langsung dari
 * `xl/worksheets/sheet1.xml` (semua sel `inlineStr`). Waktu tiket/pesan ditulis UTC; periode = hari kalender WIB (UTC+7).
 *
 * @internal
 */
final class EksporTiketTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use HaloSimpegFixtureTrait;
    use PegawaiFixtureTrait;

    private const URL = 'api/v1/halo-simpeg/tiket/ekspor';

    /** Baris atas berkas: judul, periode, baris kosong; header di baris 4. */
    private const BARIS_HEADER = 3;

    protected $seed = AuthSeeder::class;

    private int $k1;

    private int $k2;

    private int $pemilik;

    private string $nipPemilik;

    private int $petugas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->k1         = $this->buatKategoriHs('Kepegawaian');
        $this->k2         = $this->buatKategoriHs('Gaji');
        $this->nipPemilik = $this->buatPegawai(['nama' => 'Penanya Ekspor']);
        $this->pemilik    = $this->buatAkunUntuk($this->nipPemilik, Role::PEGAWAI);
        $this->petugas    = $this->buatAkunUntuk($this->buatPegawai(['nama' => 'Petugas Ekspor']), Role::PTT);
        $this->db->table('pengguna')->where('id_pengguna', $this->petugas)->update(['name' => 'Petugas Ekspor']);
        $this->buatPetugasHs($this->petugas, [$this->k1]);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        Services::reset(true);
        Factories::reset('config');
        parent::tearDown();
    }

    /**
     * AC-E09.1: hanya petugas aktif (`bisa_ekspor`); petugas berkategori tunggal boleh. Bukan petugas dan petugas nonaktif → 403
     * `bukan_petugas`; role 8 ditolak filter route. Flag `bisa_ekspor` = hasil aksi.
     */
    public function testHanyaPetugasAktif(): void
    {
        $this->tiket($this->k1, '2026-11-10 03:00:00');

        $bukan = $this->buatAkunUntuk($this->buatPegawai(), Role::PEGAWAI);
        $this->cocokFixtureHs('galat-403-bukan-petugas', $this->ekspor($bukan), 403);
        $this->assertSame('bukan_petugas', $this->json($this->ekspor($bukan))['code']);
        $this->assertFalse($this->json($this->sebagai($bukan)->get('api/v1/halo-simpeg/petugas/saya'))['data']['bisa_ekspor']);

        $nonaktif = $this->buatAkunUntuk($this->buatPegawai(), Role::ADMIN_SATKER);
        $this->buatPetugasHs($nonaktif, [$this->k1], 0);
        $this->assertSame('bukan_petugas', $this->json($this->st($this->ekspor($nonaktif), 403))['code']);

        // Akun role 8 yang terdaftar sebagai petugas: ditolak filter route (F8-04), bukan sampai ke service.
        $pimpinan = $this->buatAkunUntuk($this->buatPegawai(), Role::PIMPINAN);
        $this->buatPetugasHs($pimpinan, [$this->k1]);
        $this->st($this->ekspor($pimpinan), 403);

        $this->assertTrue($this->json($this->sebagai($this->petugas)->get('api/v1/halo-simpeg/petugas/saya'))['data']['bisa_ekspor']);
        $ok = $this->st($this->ekspor($this->petugas), 200);
        $ok->assertHeader('Content-Disposition', 'attachment; filename="Rekap_Halo_Simpeg_2026-11-01_2026-11-30.xlsx"');
        $this->assertSame(1, $this->jumlahAudit(), 'audit hanya untuk berkas yang terkirim');
    }

    /**
     * S12 di tingkat service (review E1): petugas `support_officer.status = 1` di k1 yang akunnya nonaktif (status '0'), terhapus
     * (`deleted_at`), atau `user_level` di DB berubah ke 8 sesudah token role 1–7 terbit → 403 `bukan_petugas` dan
     * `bisa_ekspor = false`. Filter route hanya membaca role di token, jadi ketiganya sampai ke `EksporTiketService`.
     */
    public function testS12AkunTidakAktifDitolakService(): void
    {
        $this->tiket($this->k1, '2026-11-10 03:00:00');

        $kasus = [
            'nonaktif'  => ['status' => '0'], // `pengguna.status` enum('0','1') di v2: nonaktif = '0'
            'terhapus'  => ['deleted_at' => '2026-11-12 01:00:00'],
            'role 8 db' => ['user_level' => Role::PIMPINAN],
        ];
        $i = 0;

        foreach ($kasus as $nama => $ubah) {
            $akun = $this->buatAkunUntuk($this->buatPegawai(), Role::ADMIN_SATKER);
            $this->buatPetugasHs($akun, [$this->k1], 1, 's12-' . ++$i . '@contoh.test');
            $minta = $this->sebagai($akun); // token role 3 terbit sebelum akun diubah
            $this->assertTrue($this->db->table('pengguna')->where('id_pengguna', $akun)->update($ubah), $nama);
            $this->assertSame(1, $this->db->affectedRows(), $nama);

            $this->assertFalse($this->json($minta->get('api/v1/halo-simpeg/petugas/saya'))['data']['bisa_ekspor'], $nama);
            Services::resetSingle('response');
            $r = $minta->get(self::URL . '?ds=2026-11-01&de=2026-11-30');
            $this->assertSame('bukan_petugas', $this->json($this->st($r, 403))['code'], $nama);
            Services::resetSingle('response');
        }

        $this->assertSame(0, $this->jumlahAudit());
    }

    /**
     * V5 (review E2 (c)): non-petugas dengan `ds` kosong → 403 `bukan_petugas`, bukan 422 (izin dicek sebelum validasi).
     */
    public function testBukanPetugasSebelumValidasi(): void
    {
        $bukan = $this->buatAkunUntuk($this->buatPegawai(), Role::PEGAWAI);
        $this->assertSame('bukan_petugas', $this->json($this->st($this->ekspor($bukan, ['ds' => '', 'de' => '']), 403))['code']);
    }

    /**
     * V11 (review E2 (a)/(b)): kategori nonaktif yang masih dipegang petugas tetap ikut; petugas aktif tanpa `officer_category` →
     * 200 `ekspor-kosong`, tanpa audit, `bisa_ekspor = true`.
     */
    public function testKategoriNonaktifIkutDanPetugasTanpaKategori(): void
    {
        $k3 = $this->buatKategoriHs('Pensiun');
        $this->db->table('question_category')->where('id_question_category', $k3)->update(['status' => 0]);
        $this->db->table('officer_category')->insert(['id_support_officer' => $this->idPetugasHs($this->petugas), 'id_question_category' => $k3, 'created_at' => '2026-11-17 01:00:00']);
        $t1 = $this->tiket($this->k1, '2026-11-10 03:00:00', 'Pertanyaan k1');
        $t3 = $this->tiket($k3, '2026-11-11 03:00:00', 'Pertanyaan k3');

        $this->assertSame([
            ['1', (string) $t1, 'Kepegawaian', 'Penanya Ekspor', $this->nipPemilik, 'Pertanyaan k1', '10/11/2026 10:00'],
            ['2', (string) $t3, 'Pensiun', 'Penanya Ekspor', $this->nipPemilik, 'Pertanyaan k3', '11/11/2026 10:00'],
        ], array_slice($this->sel($this->st($this->ekspor($this->petugas), 200)), self::BARIS_HEADER + 1));
        Services::resetSingle('response');

        $tanpa = $this->buatAkunUntuk($this->buatPegawai(), Role::ADMIN_SATKER);
        $this->buatPetugasHs($tanpa, [], 1, 'tanpa@contoh.test');
        $this->assertTrue($this->json($this->sebagai($tanpa)->get('api/v1/halo-simpeg/petugas/saya'))['data']['bisa_ekspor']);
        Services::resetSingle('response');
        $this->cocokFixtureHs('ekspor-kosong', $this->ekspor($tanpa), 200);
        $this->assertSame(1, $this->jumlahAudit(), 'hanya berkas petugas pertama yang diaudit');
    }

    /**
     * Header keamanan (review E2 (d), README ekspor): `nosniff` + `Cache-Control: private, no-store` pada xlsx dan JSON kosong.
     */
    public function testHeaderKeamananXlsxDanJsonKosong(): void
    {
        $kosong = $this->st($this->ekspor($this->petugas), 200);
        $this->assertTrue($this->json($kosong)['data']['kosong']);
        $kosong->assertHeader('X-Content-Type-Options', 'nosniff');
        $kosong->assertHeader('Cache-Control', 'private, no-store');
        Services::resetSingle('response');

        $this->tiket($this->k1, '2026-11-10 03:00:00');
        $xlsx = $this->st($this->ekspor($this->petugas), 200);
        $xlsx->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $xlsx->assertHeader('X-Content-Type-Options', 'nosniff');
        $xlsx->assertHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Review E3: kunci `.env` ambang ekspor = prefix pendek huruf kecil `halosimpeg.` (konvensi CI4 `BaseConfig`, seperti `arsip.`).
     * Penulisan `haloSimpeg.` tidak dibaca.
     */
    public function testKunciEnvAmbangEkspor(): void
    {
        $simpan = $_ENV;

        try {
            $_ENV['haloSimpeg.eksporMaksBaris']    = '7';
            $_ENV['halosimpeg.eksporMaksKarakter'] = '1234';
            $config                                = new HaloSimpeg();
            $this->assertSame(1234, $config->eksporMaksKarakter);
            $this->assertSame(10000, $config->eksporMaksBaris, 'huruf besar tidak cocok dengan prefix pendek');
        } finally {
            $_ENV = $simpan;
        }
    }

    /**
     * AC-E09.2..AC-E09.4 + F8-20: batas hari WIB inklusif, kategori lain dan NULL tidak ikut, tiket tanpa balasan petugas ikut,
     * urut tiket lalu pesan, kolom TIMESTAMP WIB, NIP tetap teks. AC-E09.7: audit tanpa isi/NIP penanya. BP8-1: baca saja.
     */
    public function testPeriodeWibKolomDanAudit(): void
    {
        $awal  = $this->tiket($this->k1, '2026-10-31 17:00:00', 'Pertanyaan awal');           // 1 Nov 00:00 WIB → ikut
        $akhir = $this->tiket($this->k1, '2026-11-30 16:59:59', 'Pertanyaan akhir');          // 30 Nov 23:59:59 WIB → ikut
        $this->tiket($this->k1, '2026-10-31 16:59:59', 'Sehari sebelum');                      // 31 Okt 23:59:59 WIB → tidak
        $this->tiket($this->k1, '2026-11-30 17:00:00', 'Sehari sesudah');                      // 1 Des 00:00 WIB → tidak
        $this->tiket($this->k2, '2026-11-10 03:00:00', 'Kategori lain');                       // AC-E09.3
        $this->tiket(null, '2026-11-10 03:00:00', 'Tanpa kategori');                           // H8-16 (a1)
        $this->buatPesanHs($awal, $this->petugas, 'Jawaban petugas', '2026-11-01 02:30:00', null, 'Petugas Ekspor');
        $this->buatPesanHs($awal, $this->pemilik, 'Terima kasih', '2026-11-01 03:00:00', null, 'Penanya Ekspor');

        $sebelum = $this->hitungTabel();
        $r       = $this->st($this->ekspor($this->petugas), 200);
        $r->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $r->assertHeader('Content-Disposition', 'attachment; filename="Rekap_Halo_Simpeg_2026-11-01_2026-11-30.xlsx"');
        $this->assertSame($sebelum, $this->hitungTabel(), 'BP8-1: ekspor tidak menulis tabel helpdesk/inbox');

        $sel = $this->sel($r);
        $this->assertSame(['REKAP PIC HALO SIMPEG Petugas Ekspor'], $sel[0]);
        $this->assertSame(['Periode: 1 November 2026 - 30 November 2026'], $sel[1]);
        $this->assertSame(EksporTiketService::HEADER, $sel[self::BARIS_HEADER]);
        $this->assertSame([
            ['1', (string) $awal, 'Kepegawaian', 'Penanya Ekspor', $this->nipPemilik, 'Pertanyaan awal', '01/11/2026 00:00'],
            ['2', (string) $awal, 'Kepegawaian', 'Petugas Ekspor', $this->nipPetugas(), 'Jawaban petugas', '01/11/2026 09:30'],
            ['3', (string) $awal, 'Kepegawaian', 'Penanya Ekspor', $this->nipPemilik, 'Terima kasih', '01/11/2026 10:00'],
            ['4', (string) $akhir, 'Kepegawaian', 'Penanya Ekspor', $this->nipPemilik, 'Pertanyaan akhir', '30/11/2026 23:59'],
        ], array_slice($sel, self::BARIS_HEADER + 1));
        $this->assertStringContainsString('t="inlineStr"><is><t xml:space="preserve">' . $this->nipPemilik . '</t>', $this->sheetXml($r), 'AC-E09.4');

        $audit = $this->db->table('audit_logs')->where('entity', EksporTiketService::ENTITY_AUDIT)->get()->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertSame($this->petugas, (int) $audit[0]['id_pengguna_actor']);
        $this->assertSame(EksporTiketService::JENIS_AUDIT, $audit[0]['entity_id']);
        $this->assertEquals(
            ['jenis' => 'rekap_halo_simpeg', 'format' => 'xlsx', 'parameter' => ['ds' => '2026-11-01', 'de' => '2026-11-30'], 'jumlah_baris' => 4],
            json_decode((string) $audit[0]['after_json'], true),
        );

        foreach ([$this->nipPemilik, 'Pertanyaan awal', 'Penanya Ekspor'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, (string) $audit[0]['after_json'], 'AC-E09.7');
        }

        // Mulai = akhir lolos (satu hari WIB).
        $sel = $this->sel($this->st($this->ekspor($this->petugas, ['ds' => '2026-11-30', 'de' => '2026-11-30']), 200));
        $this->assertSame([['1', (string) $akhir, 'Kepegawaian', 'Penanya Ekspor', $this->nipPemilik, 'Pertanyaan akhir', '30/11/2026 23:59']], array_slice($sel, self::BARIS_HEADER + 1));
    }

    /**
     * V1/V2 (keputusan Horii 18-11, opsi (a) ikut legacy [K `LS:1626`, `LS:1797-1799`]): hanya pesan pemilik tiket + pesan petugas
     * yang mengekspor; balasan petugas lain di kategori yang sama tidak ikut, juga tidak dihitung batas (COUNT). Label = pengirim
     * baris, tanpa penyamaran. Tiket tanpa balasan pengekspor tetap ikut.
     */
    public function testCakupanPemilikDanPengeksporSaja(): void
    {
        $lain = $this->buatAkunUntuk($this->buatPegawai(['nama' => 'Petugas Lain']), Role::ADMIN_SATKER);
        $this->buatPetugasHs($lain, [$this->k1], 1, 'lain@contoh.test');
        $t = $this->tiket($this->k1, '2026-11-10 03:00:00', 'Pertanyaan');
        $this->buatPesanHs($t, $lain, 'Jawaban petugas lain', '2026-11-10 04:00:00', null, 'Petugas Lain');
        $this->buatPesanHs($t, $this->petugas, 'Jawaban pengekspor', '2026-11-10 05:00:00', null, 'Petugas Ekspor');
        $tanpaBalasan = $this->tiket($this->k1, '2026-11-11 03:00:00', 'Belum dibalas');

        // Batas 3 baris: pesan petugas lain (baris ke-4 bila ikut) tidak boleh membuat 422.
        config(HaloSimpeg::class)->eksporMaksBaris = 3;
        $this->assertSame([
            ['1', (string) $t, 'Kepegawaian', 'Penanya Ekspor', $this->nipPemilik, 'Pertanyaan', '10/11/2026 10:00'],
            ['2', (string) $t, 'Kepegawaian', 'Petugas Ekspor', $this->nipPetugas(), 'Jawaban pengekspor', '10/11/2026 12:00'],
            ['3', (string) $tanpaBalasan, 'Kepegawaian', 'Penanya Ekspor', $this->nipPemilik, 'Belum dibalas', '11/11/2026 10:00'],
        ], array_slice($this->sel($this->st($this->ekspor($this->petugas), 200)), self::BARIS_HEADER + 1));

        Services::resetSingle('response');
        $nipLain = (string) $this->db->table('pengguna')->select('nip')->where('id_pengguna', $lain)->get()->getRowArray()['nip'];
        $this->assertSame([
            ['1', (string) $t, 'Kepegawaian', 'Penanya Ekspor', $this->nipPemilik, 'Pertanyaan', '10/11/2026 10:00'],
            ['2', (string) $t, 'Kepegawaian', 'Petugas Lain', $nipLain, 'Jawaban petugas lain', '10/11/2026 11:00'],
            ['3', (string) $tanpaBalasan, 'Kepegawaian', 'Penanya Ekspor', $this->nipPemilik, 'Belum dibalas', '11/11/2026 10:00'],
        ], array_slice($this->sel($this->st($this->ekspor($lain), 200)), self::BARIS_HEADER + 1));
    }

    /**
     * V1/V2: pengekspor role 1 (tanpa NIP) mengikuti aturan yang sama — balasan petugas lain tidak ikut; NIP PENANYA baris
     * pengekspor kosong karena pengirim tanpa NIP.
     */
    public function testPengeksporRole1AturanSama(): void
    {
        $this->db->table('pengguna')->insert(['username' => 'admin.ekspor', 'name' => 'Admin Ekspor', 'user_level' => Role::SUPER_ADMIN, 'status' => '1']);
        $admin = (int) $this->db->insertID();
        $this->buatPetugasHs($admin, [$this->k1], 1, 'admin@contoh.test');
        $t = $this->tiket($this->k1, '2026-11-10 03:00:00', 'Pertanyaan');
        $this->buatPesanHs($t, $this->petugas, 'Jawaban petugas lain', '2026-11-10 04:00:00', null, 'Petugas Ekspor');
        $this->buatPesanHs($t, $admin, 'Jawaban admin', '2026-11-10 05:00:00', null, 'Admin Ekspor');

        $this->assertSame([
            ['1', (string) $t, 'Kepegawaian', 'Penanya Ekspor', $this->nipPemilik, 'Pertanyaan', '10/11/2026 10:00'],
            ['2', (string) $t, 'Kepegawaian', 'Admin Ekspor', '', 'Jawaban admin', '10/11/2026 12:00'],
        ], array_slice($this->sel($this->st($this->ekspor($admin), 200)), self::BARIS_HEADER + 1));
    }

    /**
     * AC-E09.5: periode tanpa data → 200 JSON (fixture `ekspor-kosong`), tanpa `Content-Disposition`, tanpa audit. Tiket kategori
     * lain/NULL di periode itu tidak dihitung.
     */
    public function testKosong200JsonTanpaAudit(): void
    {
        $this->tiket($this->k2, '2026-11-10 03:00:00');
        $this->tiket(null, '2026-11-10 03:00:00');

        $r = $this->ekspor($this->petugas);
        $this->cocokFixtureHs('ekspor-kosong', $r, 200);
        $this->assertSame(['status' => 'success', 'data' => ['kosong' => true, 'jumlah' => 0], 'message' => EksporTiketService::PESAN_KOSONG], $this->json($r));
        $this->assertStringStartsWith('application/json', $r->response()->getHeaderLine('Content-Type'));
        $this->assertFalse($r->response()->hasHeader('Content-Disposition'));
        $this->assertSame(0, $this->jumlahAudit());
    }

    /**
     * AC-E09.6 + §7.3a: `ds`/`de` wajib, `Y-m-d` nyata, `ds` ≤ `de`, format hanya xlsx (kosong = xlsx), parameter array → 422.
     */
    public function testValidasiPeriodeDanFormat(): void
    {
        $r = $this->ekspor($this->petugas, ['ds' => '', 'de' => '']);
        $this->cocokFixtureHs('galat-422-ekspor', $r, 422);
        $this->assertSame(['ds' => [EksporTiketService::PESAN_TANGGAL_WAJIB], 'de' => [EksporTiketService::PESAN_TANGGAL_WAJIB]], $this->json($r)['errors']);

        $this->assertSame(['ds' => [EksporTiketService::PESAN_TANGGAL_URUT]], $this->json($this->st($this->ekspor($this->petugas, ['ds' => '2026-11-02', 'de' => '2026-11-01']), 422))['errors']);
        $this->assertSame(['format' => [EksporTiketService::PESAN_FORMAT]], $this->json($this->st($this->ekspor($this->petugas, ['format' => 'csv']), 422))['errors']);
        $this->assertSame(['de' => [EksporTiketService::PESAN_TANGGAL_FORMAT]], $this->json($this->st($this->ekspor($this->petugas, ['de' => '2026-02-30']), 422))['errors']);
        $this->assertSame(['ds' => [EksporTiketService::PESAN_TANGGAL_FORMAT]], $this->json($this->st($this->ekspor($this->petugas, ['ds' => '01-11-2026']), 422))['errors']);
        $this->st($this->ekspor($this->petugas, ['ds' => ['2026-11-01']]), 422);
        $this->st($this->ekspor($this->petugas, ['format' => 'xlsx']), 200);
        $this->assertSame(0, $this->jumlahAudit(), 'kosong/galat tidak diaudit');
    }

    /**
     * AC-E09.8: tepat `eksporMaksBaris` (10.000) baris pesan → berkas utuh; satu baris lagi → 422 `ekspor_terlalu_besar`, tanpa
     * berkas dan tanpa audit.
     */
    public function testBatasSepuluhRibuBaris(): void
    {
        $maks = config(HaloSimpeg::class)->eksporMaksBaris;
        $this->assertSame(10000, $maks);
        $t = $this->tiket($this->k1, '2026-11-10 03:00:00', 'Pesan 1');

        foreach (array_chunk(range(2, $maks), 1000) as $potong) {
            $this->db->table('disiplin_ticket_msg')->insertBatch(array_map(fn (int $i): array => [
                'id_inbox' => $t, 'msg_sender' => $this->pemilik, 'msg_sender_name' => 'Penanya Ekspor', 'msg_receiver' => 0, 'msg' => "Pesan {$i}", 'created_at' => '2026-11-10 03:00:00',
            ], $potong));
        }

        $sel = $this->sel($this->st($this->ekspor($this->petugas), 200));
        $this->assertCount($maks, array_slice($sel, self::BARIS_HEADER + 1));
        $this->assertSame([(string) $maks, (string) $t], array_slice($sel[array_key_last($sel)], 0, 2));
        $this->assertSame("Pesan {$maks}", $sel[array_key_last($sel)][5]);

        $this->buatPesanHs($t, $this->pemilik, 'Lebih satu', '2026-11-10 03:00:00');
        Services::resetSingle('response'); // objek respons uji dipakai ulang antar-request; header berkas sebelumnya dibuang.
        $r = $this->ekspor($this->petugas);
        $this->cocokFixtureHs('galat-422-ekspor-terlalu-besar', $r, 422);
        $this->assertSame(['status' => 'error', 'code' => 'ekspor_terlalu_besar', 'message' => EksporTiketService::PESAN_TERLALU_BESAR], $this->json($r));
        $this->assertFalse($r->response()->hasHeader('Content-Disposition'));
        $this->assertSame(1, $this->jumlahAudit(), 'hanya unduhan pertama yang diaudit');
    }

    /**
     * AC-E09.8 (v1.3): `SUM(CHAR_LENGTH(msg))` = ambang lolos; ambang + 1 → 422 teks sama. CHAR_LENGTH = karakter, bukan byte.
     */
    public function testBatasUkuranKarakter(): void
    {
        config(HaloSimpeg::class)->eksporMaksKarakter = 10;

        // 5 karakter = 10 byte: ambang dihitung per karakter.
        $t = $this->tiket($this->k1, '2026-11-10 03:00:00', 'ééééé');
        $this->buatPesanHs($t, $this->petugas, 'abcde', '2026-11-10 04:00:00');
        $this->st($this->ekspor($this->petugas), 200);

        $this->buatPesanHs($t, $this->pemilik, 'x', '2026-11-10 05:00:00');
        $this->assertSame('ekspor_terlalu_besar', $this->json($this->st($this->ekspor($this->petugas), 422))['code']);
    }

    /**
     * AC-E09.8 pengecualian (B-27): isi sel > 32.767 karakter dipotong dengan penanda "… [dipotong]"; baris tetap ada.
     */
    public function testSelPanjangDipotong(): void
    {
        $t = $this->tiket($this->k1, '2026-11-10 03:00:00', str_repeat('a', 40000));
        $this->buatPesanHs($t, $this->petugas, 'Pendek', '2026-11-10 04:00:00');

        $sel = $this->sel($this->st($this->ekspor($this->petugas), 200));
        $isi = $sel[self::BARIS_HEADER + 1][5];
        $this->assertSame(EksporTiketService::MAKS_SEL, mb_strlen($isi));
        $this->assertStringEndsWith(EksporTiketService::PENANDA_POTONG, $isi);
        $this->assertSame('Pendek', $sel[self::BARIS_HEADER + 2][5]);
    }

    /**
     * V12: pesan yang masuk di antara kueri agregat dan kueri data → baris dan karakter dicek ulang; lewat → 422 tanpa audit.
     *
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function balapanProvider(): array
    {
        return ['baris' => ['eksporMaksBaris', 2, 'x'], 'karakter' => ['eksporMaksKarakter', 20, 'pesan lima belas']];
    }

    /**
     * Pesan disisipkan lewat koneksi KEDUA (autocommit) tepat sesudah kueri agregat, sehingga hanya kueri data yang melihatnya. Sisip
     * lewat koneksi yang sama di dalam listener akan menimpa hasil kueri agregat, jadi test ini butuh isolasi penuh.
     */
    #[DataProvider('balapanProvider')]
    #[Group('db-isolasi-penuh')]
    public function testCekUlangSesudahDataDiambil(string $batas, int $nilai, string $sisipan): void
    {
        config(HaloSimpeg::class)->{$batas} = $nilai;

        // 2 baris, 10 karakter: lolos agregat untuk kedua batas.
        $t = $this->tiket($this->k1, '2026-11-10 03:00:00', 'Tanya');
        $this->buatPesanHs($t, $this->petugas, 'Jawab', '2026-11-10 04:00:00');
        $lain    = Database::connect('tests', false);
        $pemilik = $this->pemilik;
        $sekali  = false;
        $balap   = static function (Query $q) use ($lain, $t, $pemilik, $sisipan, &$sekali): void {
            if (! $sekali && str_contains($q->getQuery(), 'COUNT(*) AS jumlah')) {
                $sekali = true;
                $lain->table('disiplin_ticket_msg')->insert(['id_inbox' => $t, 'msg_sender' => $pemilik, 'msg_sender_name' => 'Penanya Ekspor', 'msg_receiver' => 0, 'msg' => $sisipan, 'created_at' => '2026-11-10 05:00:00']);
            }
        };
        Events::on('DBQuery', $balap);

        try {
            $r = $this->ekspor($this->petugas);
        } finally {
            Events::removeListener('DBQuery', $balap);
            $lain->close();
        }

        $this->assertTrue($sekali);
        $this->assertSame(3, $this->db->table('disiplin_ticket_msg')->where('id_inbox', $t)->countAllResults());
        $this->assertSame('ekspor_terlalu_besar', $this->json($this->st($r, 422))['code']);
        $this->assertSame(0, $this->jumlahAudit());
    }

    /**
     * V10: penanda termasuk dalam 32.767 unit UTF-16; pasangan surrogate (emoji) tidak terbelah di titik potong.
     */
    public function testPotongSelEmojiDiBatas(): void
    {
        $unit = static fn (string $s): int => intdiv(strlen((string) mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
        $sisa = EksporTiketService::MAKS_SEL - $unit(EksporTiketService::PENANDA_POTONG);

        // Tepat 32.767 unit dengan emoji di ujung → tidak dipotong.
        $pas = str_repeat('a', EksporTiketService::MAKS_SEL - 2) . "\u{1F600}";
        $this->assertSame($pas, EksporTiketService::potongSel($pas));

        // Titik potong jatuh di tengah emoji → emoji dibuang utuh (tidak terbelah), total 32.766 unit.
        $hasil = EksporTiketService::potongSel(str_repeat('a', $sisa - 1) . "\u{1F600}" . str_repeat('z', 20));
        $this->assertSame(str_repeat('a', $sisa - 1) . EksporTiketService::PENANDA_POTONG, $hasil);
        $this->assertTrue(mb_check_encoding($hasil, 'UTF-8'));
        $this->assertSame(EksporTiketService::MAKS_SEL - 1, $unit($hasil));

        // Emoji pas mengisi sisa → ikut utuh, total tepat 32.767 unit.
        $hasil = EksporTiketService::potongSel(str_repeat('a', $sisa - 2) . "\u{1F600}" . str_repeat('z', 20));
        $this->assertStringEndsWith("\u{1F600}" . EksporTiketService::PENANDA_POTONG, $hasil);
        $this->assertSame(EksporTiketService::MAKS_SEL, $unit($hasil));
    }

    public function testPotongSelMenghitungUnitUtf16(): void
    {
        $this->assertSame('pendek', EksporTiketService::potongSel('pendek'));
        $pas = str_repeat('b', EksporTiketService::MAKS_SEL);
        $this->assertSame($pas, EksporTiketService::potongSel($pas));

        // 20.000 emoji = 40.000 unit UTF-16 (> batas) walau hanya 20.000 code point.
        $hasil = EksporTiketService::potongSel(str_repeat("\u{1F600}", 20000));
        $this->assertStringEndsWith(EksporTiketService::PENANDA_POTONG, $hasil);
        $this->assertLessThanOrEqual(EksporTiketService::MAKS_SEL, intdiv(strlen((string) mb_convert_encoding($hasil, 'UTF-16LE', 'UTF-8')), 2));
        $this->assertGreaterThan(EksporTiketService::MAKS_SEL - 20, intdiv(strlen((string) mb_convert_encoding($hasil, 'UTF-16LE', 'UTF-8')), 2));
    }

    public function testRentangUtc(): void
    {
        $this->assertSame(['2026-10-31 17:00:00', '2026-11-30 17:00:00'], EksporTiketService::rentangUtc('2026-11-01', '2026-11-30'));
        $this->assertSame(['2026-12-30 17:00:00', '2026-12-31 17:00:00'], EksporTiketService::rentangUtc('2026-12-31', '2026-12-31'));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function ekspor(int $akun, array $query = []): TestResponse
    {
        return $this->sebagai($akun)->get(self::URL . '?' . http_build_query($query + ['ds' => '2026-11-01', 'de' => '2026-11-30']));
    }

    /** Tiket kategori `$kategori` bertanggal `$waktu` (UTC) dengan pesan pertama pemilik pada waktu yang sama. */
    private function tiket(?int $kategori, string $waktu, string $pesan = 'Pertanyaan'): int
    {
        $t = $this->buatTiketHs($kategori, $this->pemilik);
        $this->db->table('disiplin_ticket')->where('id_inbox', $t)->update(['created_at' => $waktu, 'nip' => $this->nipPemilik]);
        $this->buatPesanHs($t, $this->pemilik, $pesan, $waktu, null, 'Penanya Ekspor');

        return $t;
    }

    private function idPetugasHs(int $idUser): int
    {
        return (int) $this->db->table('support_officer')->where('id_user', $idUser)->get()->getRow('id_support_officer');
    }

    private function nipPetugas(): string
    {
        return (string) $this->db->table('pengguna')->select('nip')->where('id_pengguna', $this->petugas)->get()->getRowArray()['nip'];
    }

    private function jumlahAudit(): int
    {
        return $this->db->table('audit_logs')->where('entity', EksporTiketService::ENTITY_AUDIT)->countAllResults();
    }

    /**
     * @return list<int>
     */
    private function hitungTabel(): array
    {
        return array_map(fn (string $t): int => $this->db->table($t)->countAllResults(), ['disiplin_ticket', 'disiplin_ticket_msg', 'inbox', 'inbox_msg']);
    }

    private function sheetXml(TestResponse $r): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'hsx');
        file_put_contents($tmp, (string) $r->response()->getBody());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'berkas xlsx sah');
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);

        return $xml;
    }

    /**
     * Sel per baris (teks `inlineStr`), urut baris berkas.
     *
     * @return list<list<string>>
     */
    private function sel(TestResponse $r): array
    {
        $doc = new SimpleXMLElement($this->sheetXml($r));
        $out = [];

        foreach ($doc->sheetData->row as $row) {
            $baris = [];

            foreach ($row->c as $c) {
                $baris[] = (string) $c->is->t;
            }

            $out[] = $baris;
        }

        return $out;
    }

    private function st(TestResponse $r, int $status): TestResponse
    {
        $r->assertStatus($status);

        return $r;
    }

    private function sebagai(int $idPengguna): static
    {
        return $this->asUser((array) $this->db->table('pengguna')->where('id_pengguna', $idPengguna)->get()->getRowArray());
    }
    /**
     * CR-162 (C-22): `potongSel` melewati konversi UTF-16 bila jumlah byte sudah muat. Hasilnya wajib sama persis dengan
     * aturan lama (selalu menghitung unit UTF-16), termasuk di sekitar batas dan untuk byte UTF-8 tidak sah.
     */
    public function testPotongSelPintasByteSamaDenganHitungUnitUtf16(): void
    {
        $unit = static fn (string $s): int => intdiv(strlen((string) mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
        $lama = static function (string $nilai) use ($unit): string {
            if ($unit($nilai) <= EksporTiketService::MAKS_SEL) {
                return $nilai;
            }

            $sisa = EksporTiketService::MAKS_SEL - $unit(EksporTiketService::PENANDA_POTONG);
            $isi  = mb_substr($nilai, 0, $sisa);

            while (($lebih = $unit($isi) - $sisa) > 0) {
                $isi = mb_substr($isi, 0, max(0, mb_strlen($isi) - (int) ceil($lebih / 2)));
            }

            return $isi . EksporTiketService::PENANDA_POTONG;
        };

        $m     = EksporTiketService::MAKS_SEL;
        $kasus = [
            'kosong'                   => '',
            'ascii di batas byte'      => str_repeat('a', $m),
            'ascii lewat satu'         => str_repeat('a', $m + 1),
            '2 byte: unit muat'        => str_repeat('é', $m),
            '2 byte: unit lewat'       => str_repeat('é', $m + 1),
            '3 byte: byte lewat'       => str_repeat('漢', intdiv($m, 3) + 1),
            '3 byte: unit lewat'       => str_repeat('漢', $m + 5),
            '4 byte: unit pas'         => str_repeat("\u{1F600}", intdiv($m, 2)) . 'a',
            '4 byte: unit lewat'       => str_repeat("\u{1F600}", intdiv($m, 2) + 1),
            'byte tidak sah pendek'    => "abc\xFF\xFEdef",
            'byte tidak sah di batas'  => str_repeat("\xFF", $m),
            'byte tidak sah lewat'     => str_repeat("\xFF", $m + 3),
            'campuran lewat byte saja' => str_repeat('a', $m - 10) . str_repeat('é', 10),
            'campuran lewat unit'      => str_repeat('a', $m - 1) . "\u{1F600}",
        ];

        foreach ($kasus as $nama => $nilai) {
            $this->assertSame($lama($nilai), EksporTiketService::potongSel($nilai), $nama);
        }

        // Jalur pintas (byte ≤ batas): dikembalikan apa adanya tanpa konversi, termasuk tepat di batas.
        foreach (['kosong', 'ascii di batas byte', 'byte tidak sah pendek', 'byte tidak sah di batas'] as $nama) {
            $this->assertLessThanOrEqual($m, strlen($kasus[$nama]), "{$nama}: memang lewat jalur pintas");
            $this->assertSame($kasus[$nama], EksporTiketService::potongSel($kasus[$nama]), $nama);
        }

        // Bukan jalur pintas (byte > batas) tetapi unit UTF-16 masih muat: tetap utuh lewat hitungan unit.
        $this->assertGreaterThan($m, strlen($kasus['2 byte: unit muat']));
        $this->assertLessThanOrEqual($m, $unit($kasus['2 byte: unit muat']));
        $this->assertSame($kasus['2 byte: unit muat'], EksporTiketService::potongSel($kasus['2 byte: unit muat']));
    }
}
