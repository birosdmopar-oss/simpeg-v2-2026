<?php

declare(strict_types=1);

namespace Tests\Layanan\Tb;

use App\Constants\Role;
use App\Libraries\Layanan\Tb\PerpanjanganTbService;
use App\Libraries\Layanan\Tb\StatusTugasBelajar;
use App\Libraries\Layanan\Tb\TbService;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * MAKE-044 (PRD Fase 5B v1.0 F5B-18, milestone M1a) — `StatusTugasBelajarInterface::rentangBebasPresensiBanyak` ber-DB:
 * hasil per NIP identik dengan `rentangBebasPresensi` tunggal (TB + perpanjangan + celah, TB status 0/2, NIP tanpa TB,
 * NIP tidak ada, TB ganda); jumlah kueri tetap per batch {@see StatusTugasBelajar::BATCH_NIP} (bukan per NIP); input
 * kosong → hasil kosong tanpa kueri.
 *
 * @internal
 */
final class RentangBebasPresensiBanyakTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    private const DARI   = '2026-06-25';
    private const SAMPAI = '2026-08-05';

    protected $seed = MasterDataSeeder::class;

    /** @var list<string> */
    private array $kueri = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('DBQuery');
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testMassalIdentikDenganTunggalPerNip(): void
    {
        $nip = $this->siapkanData();

        $status = service('statusTugasBelajar');
        $hasil  = $status->rentangBebasPresensiBanyak([...$nip, $nip[0]], self::DARI, self::SAMPAI);

        $this->assertSame($nip, array_map('strval', array_keys($hasil)), 'satu kunci per NIP unik, urutan masukan');

        foreach ($nip as $n) {
            $this->assertSame($status->rentangBebasPresensi($n, self::DARI, self::SAMPAI), $hasil[$n], $n);
        }

        [$tbPerpan, $statusNol, $tanpaTb, $tidakAda, $ganda] = $nip;
        $this->assertSame(['2026-06-25', '2026-06-26', '2026-06-27', '2026-06-28', '2026-06-29', '2026-06-30', '2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05'], $hasil[$tbPerpan], 'celah Juli tidak bebas');
        $this->assertSame([], $hasil[$statusNol], 'TB status 0 dan 2 tidak bebas');
        $this->assertSame([], $hasil[$tanpaTb]);
        $this->assertSame([], $hasil[$tidakAda]);
        $this->assertSame(['2026-07-01', '2026-07-02', '2026-07-20', '2026-07-21'], $hasil[$ganda], 'dua TB terpisah');
    }

    public function testBatchTanpaNPlusSatu(): void
    {
        [$tbPerpan, , , , $ganda] = $this->siapkanData();

        // 2.500 NIP fiktif: TB nyata di batch 1 dan batch 3 → 2 + 1 + 2 kueri.
        $fiktif = array_map(static fn (int $i): string => sprintf('19800101200001%04d', $i), range(1, 2498));
        $nip    = [$tbPerpan, ...array_slice($fiktif, 0, 1500), $ganda, ...array_slice($fiktif, 1500)];
        $this->assertCount(2500, $nip);

        $hasil = $this->hitungKueri(static fn () => service('statusTugasBelajar')->rentangBebasPresensiBanyak($nip, self::DARI, self::SAMPAI));

        $this->assertCount(2500, $hasil);
        $this->assertCount(5, $this->kueri, implode("\n", $this->kueri));
        $this->assertNotSame([], $hasil[$tbPerpan]);
        $this->assertNotSame([], $hasil[$ganda]);

        $this->kueri = [];
        $this->hitungKueri(static fn () => service('statusTugasBelajar')->rentangBebasPresensiBanyak(array_slice($fiktif, 0, 3), self::DARI, self::SAMPAI));
        $this->assertCount(1, $this->kueri, 'tanpa TB → tanpa kueri perpanjangan');
    }

    public function testInputKosongTanpaKueri(): void
    {
        $hasil = $this->hitungKueri(static fn () => service('statusTugasBelajar')->rentangBebasPresensiBanyak([], self::DARI, self::SAMPAI));

        $this->assertSame([], $hasil);
        $this->assertSame([], $this->kueri);
    }

    /**
     * Lima NIP: TB + perpanjangan dengan celah; TB status 0 + status 2; tanpa TB; NIP tidak ada; dua TB disetujui.
     *
     * @return list<string>
     */
    private function siapkanData(): array
    {
        [$tbPerpan]  = $this->aktorStudi(Role::PEGAWAI);
        [$statusNol] = $this->aktorStudi(Role::PEGAWAI);
        [$tanpaTb]   = $this->aktorStudi(Role::PEGAWAI);
        [$ganda]     = $this->aktorStudi(Role::PEGAWAI);
        [, $su]      = $this->aktorStudi(Role::SUPER_ADMIN);
        $tb          = new TbService();

        $id = (int) $tb->tambah($su, $this->isianStudi(['nip' => $tbPerpan, 'status' => '1', 'tgl_mulai' => '2026-01-01', 'tgl_akhir' => '2026-06-30']) + $this->dataSkTb(), [])['id_riwayat_tb'];
        (new PerpanjanganTbService())->tambah($su, $id, ['tgl_mulai_perpan' => '2026-08-01', 'tgl_akhir_perpan' => '2026-12-31'], $this->berkasPdf());

        $nol = (int) $tb->tambah($su, $this->isianStudi(['nip' => $statusNol, 'status' => '0', 'tgl_mulai' => '2026-06-01', 'tgl_akhir' => '2026-07-31']), $this->berkasSemuaSlot('tb'))['id_riwayat_tb'];
        $dua = (int) $tb->tambah($su, $this->isianStudi(['nip' => $statusNol, 'status' => '0', 'tgl_mulai' => '2026-08-01', 'tgl_akhir' => '2026-08-31']), $this->berkasSemuaSlot('tb'))['id_riwayat_tb'];
        $this->db->table('riwayat_tb')->where('id_riwayat_tb', $dua)->update(['status' => 2]);
        $this->assertGreaterThan(0, $nol);

        $tb->tambah($su, $this->isianStudi(['nip' => $ganda, 'status' => '1', 'tgl_mulai' => '2026-07-01', 'tgl_akhir' => '2026-07-02']) + $this->dataSkTb(), []);
        $tb->tambah($su, $this->isianStudi(['nip' => $ganda, 'status' => '1', 'tgl_mulai' => '2026-07-20', 'tgl_akhir' => '2026-07-21']) + $this->dataSkTb(), []);

        return [$tbPerpan, $statusNol, $tanpaTb, '199901012099011999', $ganda];
    }

    /**
     * Jalankan `$aksi` sambil mencatat kueri SELECT yang dikirim ke DB.
     */
    private function hitungKueri(callable $aksi): mixed
    {
        Events::on('DBQuery', function (Query $q): void {
            if (stripos(ltrim($q->getQuery()), 'SELECT') === 0) {
                $this->kueri[] = $q->getQuery();
            }
        });

        try {
            return $aksi();
        } finally {
            Events::removeAllListeners('DBQuery');
        }
    }
}
