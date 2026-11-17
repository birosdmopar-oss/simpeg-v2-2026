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
}
