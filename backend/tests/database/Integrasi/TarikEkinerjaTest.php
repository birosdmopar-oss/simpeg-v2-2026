<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Constants\Role;
use App\Jobs\BknTarikJob;
use App\Libraries\Bkn\Ekinerja\TarikEkinerja;
use App\Libraries\Bkn\TokenBkn;
use App\Libraries\CacheService;
use App\Libraries\Integrasi\Http\FakeTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\Log\IntegrasiLog;
use App\Libraries\Integrasi\Log\StatusIntegrasi;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Integrations;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-064 (H-10) — tarik e-Kinerja ujung-ke-ujung dengan FakeTransport (tanpa jaringan): `bkn:tarik-*`, job `bkn-tarik`,
 * endpoint `POST api/v1/integrasi/bkn/tarik` (role 1, antre — bukan sinkron), ringkasan `integrasi_log` tanpa NIP, dan
 * peringatan periode Tukin tanpa SKP periodik di `integrasi:status` (F6-15). Fixture sintetis.
 *
 * @internal
 */
final class TarikEkinerjaTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use StreamFilterTrait;

    private const DASAR = 'https://kinerja.contoh/api_kinerja';

    private FakeTransport $transport;

    /** @var array<string, bool|float|string> */
    private array $bknAsli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport                 = new FakeTransport();
        $this->bknAsli                   = config(Integrations::class)->bkn;
        $konfig                          = ['baseUrlEkinerja' => self::DASAR, 'ekinerjaToken' => 'tok-statis-uji'] + $this->bknAsli;
        config(Integrations::class)->bkn = $konfig;
        Services::injectMock('tokenBkn', new TokenBkn(new KlienHttp($this->transport, new IntegrasiLog($this->db), tidur: static function (int $ms): void {
        }), new CacheService(new MockCache(), 'uji_'), $konfig));
    }

    protected function tearDown(): void
    {
        config(Integrations::class)->bkn = $this->bknAsli;
        Services::reset(true);
        parent::tearDown();
    }

    public function testTarikPeriodeLaluSkpUjungKeUjung(): void
    {
        $this->buatPegawai(['nip' => '199001012020011001', 'id_pns_siasn' => 'PNS-UJI-001']);
        $this->transport->antreBerkas(200, SUPPORTPATH . 'Integrasi/Bkn/periode_ok.json')->antreBerkas(200, SUPPORTPATH . 'Integrasi/Bkn/skp_periodik_ok.json');
        $tarik = TarikEkinerja::dariLayanan();

        $p = $tarik->periode();
        $this->assertSame(['ok', 200, 4, 0, 2], [$p['status'], $p['http'], $p['baru'], $p['sudah_ada'], $p['ditolak']]);

        $s = $tarik->skpPeriodik(2026, 10);
        $this->assertSame(['ok', 'PER-UJI-2026-10', 2, 3, 0, 1], [$s['status'], $s['periode_id'], $s['baru'], $s['ditolak'], $s['tidak_terpasang'], $s['tidak_dikenal']]);
        $this->assertSame(self::DASAR . '/laporan/penilaian/2026/PER-UJI-2026-10', $this->transport->tercatat()[1]['permintaan']->url);
        $this->assertSame('Bearer tok-statis-uji', $this->transport->tercatat()[1]['permintaan']->header['Authorization']);

        $ringkas = $this->db->table('integrasi_log')->where('operasi', 'tarik_skp_periodik')->get()->getRowArray();
        $this->assertSame(['ekinerja', '1', 'ok', 'PER-UJI-2026-10'], [$ringkas['layanan'], (string) $ringkas['status'], $ringkas['kode'], $ringkas['ref_id']]);
        $this->assertStringContainsString('ditolak:skp_id_kosong=1', $ringkas['pesan']);
        $semua = implode(' ', array_column($this->db->table('integrasi_log')->get()->getResultArray(), 'pesan'));
        $this->assertStringNotContainsString('199001012020011', $semua, 'tanpa NIP di log');
        $this->assertStringNotContainsString('tok-statis-uji', $semua);
    }

    public function testPeriodeManualTanpaPeriodeDanGagal(): void
    {
        $this->periode('SKPManualUji2026', 2026, '11');
        $this->periode('PER-UJI-2026-12', 2026, '12');
        $tarik = TarikEkinerja::dariLayanan();

        $this->assertSame('dilewati_manual', $tarik->skpPeriodik(2026, 11)['status']);
        $this->assertSame('periode_tidak_ada', $tarik->skpPeriodik(2027, 1)['status']);
        $this->assertSame([], $this->transport->tercatat(), 'tanpa panggilan API');

        $this->transport->antre(new Respons(500, 'galat'), new Respons(502, 'galat'), new Respons(503, 'galat'));
        $g = $tarik->skpPeriodik(2026, 12);
        $this->assertSame(['gagal', 503], [$g['status'], $g['http']]);
        $this->assertCount(3, $this->transport->tercatat(), 'GET di-retry 3x oleh KlienHttp');
    }

    public function testPemilihanPeriodeOtomatisDanDryRunTanpaTulis(): void
    {
        $this->periode('PER-UJI-2026-11', 2026, '11');
        $this->transport->antreBerkas(200, SUPPORTPATH . 'Integrasi/Bkn/skp_periodik_ok.json');

        $h = TarikEkinerja::dariLayanan()->skpPeriodik(null, null, new DateTimeImmutable('2026-10-25 09:00:00', new DateTimeZone('Asia/Jakarta')), true);

        $this->assertSame(['ok', 'PER-UJI-2026-11', 2026, '11', 2], [$h['status'], $h['periode_id'], $h['tahun'], $h['bulan'], $h['baru']]);
        $this->assertSame(0, $this->db->table('riwayat_skp_periodik')->countAllResults(), 'dry-run tidak menulis');
        $this->assertSame('tarik_skp_periodik_dry_run', $this->db->table('integrasi_log')->where('kode', 'ok')->get()->getRow()->operasi);
    }

    public function testCommandSkpPeriodikOpsi(): void
    {
        command('bkn:tarik-skp-periodik --tahun 2026');
        $this->assertStringContainsString('wajib diisi berpasangan', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        command('bkn:tarik-skp-periodik --tahun=2026 --bulan=13');
        $this->assertStringContainsString('wajib diisi berpasangan', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        $this->periode('PER-UJI-2026-10', 2026, '10');
        $this->transport->antreBerkas(200, SUPPORTPATH . 'Integrasi/Bkn/skp_periodik_ok.json');
        command('bkn:tarik-skp-periodik --tahun=2026 --bulan=10');
        $this->assertStringContainsString('Periode 2026-10 (PER-UJI-2026-10): baru 2', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        $this->periode('PER-UJI-2026-09', 2026, '09');
        $this->transport->antreBerkas(200, SUPPORTPATH . 'Integrasi/Bkn/skp_periodik_ok.json')->antreJson(200, ['success' => '1', 'data' => []]);
        command('bkn:tarik-skp-periodik --tahun=2026 --bulan=10 --sebelumnya --dry-run');
        $keluaran = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('[dry-run] Periode 2026-10 (PER-UJI-2026-10)', $keluaran);
        $this->assertStringContainsString('[dry-run] Periode 2026-09 (PER-UJI-2026-09): baru 0', $keluaran);
        $this->assertSame(self::DASAR . '/laporan/penilaian/2026/PER-UJI-2026-09', $this->transport->tercatat()[2]['permintaan']->url);

        $this->resetStreamFilterBuffer();
        $this->transport->antreBerkas(200, SUPPORTPATH . 'Integrasi/Bkn/periode_ok.json');
        command('bkn:tarik-periode --dry-run');
        $this->assertStringContainsString('[dry-run] Periode e-Kinerja: baru 3, sudah ada 1, ditolak 2.', $this->getStreamFilterBuffer());
    }

    public function testJobMenarikDanMelemparBilaGagal(): void
    {
        $this->transport->antreBerkas(200, SUPPORTPATH . 'Integrasi/Bkn/periode_ok.json');
        $this->assertSame('ok', (new BknTarikJob(['jenis' => 'periode']))->process());

        $this->transport->antre(new Respons(500, 'x'), new Respons(500, 'x'), new Respons(500, 'x'));
        $this->expectException(RuntimeException::class);
        (new BknTarikJob(['jenis' => 'periode']))->process();
    }

    public function testEndpointRole1MengantreRoleLainDitolak(): void
    {
        $admin = $this->buatAkunTanpaNip('admin.uji.bkn', Role::SUPER_ADMIN);
        $r     = $this->asUser($admin)->withBodyFormat('json')->post('api/v1/integrasi/bkn/tarik', ['jenis' => 'skp_periodik', 'tahun' => 2026, 'bulan' => 10]);
        $r->assertStatus(202);
        $this->assertSame(['jenis' => 'skp_periodik', 'tahun' => 2026, 'bulan' => 10, 'antre' => true], json_decode($r->getJSON(), true)['data']);
        $job = $this->db->table('queue_jobs')->where('queue', 'integrasi')->get()->getRowArray();
        $this->assertNotNull($job);
        $this->assertStringContainsString('bkn-tarik', (string) $job['payload']);
        $this->assertSame([], $this->transport->tercatat(), 'endpoint tidak memanggil BKN secara sinkron');

        $this->asUser($admin)->withBodyFormat('json')->post('api/v1/integrasi/bkn/tarik', ['jenis' => 'lain'])->assertStatus(422);
        $this->asUser($admin)->withBodyFormat('json')->post('api/v1/integrasi/bkn/tarik', ['jenis' => 'skp_periodik', 'tahun' => 2026])->assertStatus(422);

        foreach ([Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1] as $role) {
            $this->asUser($this->buatAkunTanpaNip('akun.uji.' . $role, $role))->withBodyFormat('json')->post('api/v1/integrasi/bkn/tarik', ['jenis' => 'periode'])->assertStatus(403);
        }
    }

    public function testPeringatanPeriodeTukinTanpaSkp(): void
    {
        $status   = new StatusIntegrasi($this->db);
        $sekarang = new DateTimeImmutable('2026-11-03 08:00:00', new DateTimeZone('Asia/Jakarta'));

        $this->assertStringContainsString('2026-11 belum ada di bkn_periode_ekinper', (string) $status->peringatanEkinerja($sekarang));

        $this->periode('PER-UJI-2026-11', 2026, '11');
        $this->assertStringContainsString('potongan SKP bawaan 32%', (string) $status->peringatanEkinerja($sekarang));

        $this->db->table('riwayat_skp_periodik')->insert(['id_pns' => 'P', 'periode_id' => 'PER-UJI-2026-11', 'skp_id' => 'S', 'nip' => '199001012020011001', 'nama' => 'Fiktif', 'status' => 1]);
        $this->assertNull($status->peringatanEkinerja($sekarang));
    }

    private function periode(string $id, int $tahun, string $bulan): void
    {
        $this->db->table('bkn_periode_ekinper')->insert(['id' => $id, 'nama' => $id, 'tahun' => $tahun, 'bulan' => $bulan, 'periode_awal' => '01-01', 'periode_akhir' => $bulan . '-15', 'status' => 1]);
    }
}
