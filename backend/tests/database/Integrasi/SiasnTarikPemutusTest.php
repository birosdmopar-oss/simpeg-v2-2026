<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Log\IntegrasiLog;
use App\Libraries\Siasn\SiasnAdapter;
use App\Libraries\Siasn\Tarik\JenisTarikSiasn;
use App\Libraries\Siasn\Tarik\RencanaTarik;
use App\Libraries\Siasn\Tarik\TarikSiasn;
use ArrayObject;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SiasnUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * CR-162 (C-20) — `siasn:tarik` saat SIASN tidak terjangkau atau lambat: NIP yang gagal transport (timeout/koneksi/token)
 * dan NIP yang belum sempat dicoba TIDAK ditandai selesai (dipilih lagi pada jalan berikutnya); batch diputus sesudah
 * gagal transport beruntun atau anggaran waktu habis. AC-03.1 tetap: gagal per NIP (jawaban SIASN, atau gagal transport
 * yang tidak beruntun) tidak menghentikan batch, dan NIP yang dijawab SIASN tetap ditandai selesai seperti sebelumnya.
 *
 * @internal
 */
final class SiasnTarikPemutusTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SiasnUjiTrait;

    private const NIP = ['199001012020011001', '199001012020011002', '199001012020011003', '199001012020011004', '199001012020011005'];

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSiasn();

        foreach (self::NIP as $nip) {
            $this->buatPegawai(['nip' => $nip, 'id_jenis_pegawai' => 1]);
        }
    }

    protected function tearDown(): void
    {
        $this->pulihkanSiasn();
        parent::tearDown();
    }

    public function testTimeoutBeruntunMemutusBatchDanNipGagalAtauBelumDicobaDipilihLagi(): void
    {
        $this->antreToken();
        $this->transport->antreJson(200, ['code' => 1, 'data' => ['id' => 'PNS-UJI-A']]);

        // SIASN lambat: setiap percobaan berikutnya timeout (antrean berlebih; yang tidak terpakai membuktikan pemutus).
        for ($i = 0; $i < 30; $i++) {
            $this->transport->antre(new GalatTransport('timeout tiruan', true));
        }

        $h = $this->tarik()->jalankan('data-utama');

        $this->assertSame([5, 1, 4], [$h['nip'], $h['sukses'], $h['gagal']]);
        $this->assertSame([TarikSiasn::BATAS_GAGAL_BERUNTUN, 1, true], [$h['gagal_transport'], $h['tidak_dicoba'], $h['diputus']]);

        $url = array_map(static fn (array $t): string => $t['permintaan']->url, $this->transport->tercatat());
        $this->assertSame([], array_filter($url, static fn (string $u): bool => str_ends_with($u, '/' . self::NIP[4])), 'NIP kelima tidak pernah dipanggil');

        $status = array_column($this->db->table('siasn_status_pegawai')->get()->getResultArray(), null, 'nip');
        $this->assertNotNull($status[self::NIP[0]]['du_lu'], 'NIP yang dijawab SIASN ditandai selesai');

        foreach (array_slice(self::NIP, 1) as $nip) {
            $this->assertSame(['0', null], [(string) $status[$nip]['du'], $status[$nip]['du_lu']], "{$nip}: flag kembali 0 tanpa tanda selesai");
        }

        $log = $this->db->table('integrasi_log')->where('operasi', 'tarik_data_utama')->get()->getRowArray();
        $this->assertSame('diputus', $log['kode']);
        $this->assertStringContainsString('gagal_transport 3, tidak_dicoba 1', (string) $log['pesan']);

        // Jalan berikutnya (hari yang sama): hanya empat NIP yang belum selesai yang dipilih lagi.
        $this->assertSame(4, $this->tarik()->jalankan('data-utama', TarikSiasn::LIMIT_BAWAAN, null, true)['nip']);
    }

    public function testGagalTransportTidakBeruntunTidakMemutusBatch(): void
    {
        $dipanggil = new ArrayObject();
        $jenis     = $this->jenisTiruan(['timeout', SiasnAdapter::SUKSES, 'koneksi', '500', 'token'], $dipanggil);
        $h         = $this->tarikTiruan($jenis)->jalankan('tiruan');

        $this->assertSame([5, 1, 4], [$h['nip'], $h['sukses'], $h['gagal']], 'AC-03.1: semua NIP tetap dicoba');
        $this->assertSame([3, 0, false], [$h['gagal_transport'], $h['tidak_dicoba'], $h['diputus']]);
        $this->assertSame(self::NIP, $dipanggil->getArrayCopy());

        $flag = array_map('strval', array_column($this->db->table('siasn_status_pegawai')->orderBy('nip', 'ASC')->get()->getResultArray(), 'rw_gol', 'nip'));
        $this->assertSame(
            [self::NIP[0] => '0', self::NIP[1] => '1', self::NIP[2] => '0', self::NIP[3] => '1', self::NIP[4] => '0'],
            $flag,
            'dijawab SIASN (sukses maupun HTTP 500) → selesai seperti sebelumnya; gagal transport → kembali 0',
        );
        $this->assertSame('sebagian_gagal', $this->db->table('integrasi_log')->where('operasi', 'tarik_tiruan')->get()->getRow()->kode);
    }

    public function testAnggaranWaktuHabisSisaTidakDicoba(): void
    {
        $dipanggil = new ArrayObject();
        $jenis     = $this->jenisTiruan(array_fill(0, 5, SiasnAdapter::SUKSES), $dipanggil);
        $waktu     = 0.0;
        // Setiap pembacaan jam maju 350 detik: NIP ketiga baru akan dimulai sesudah anggaran 600 detik terlewati.
        $detik = static function () use (&$waktu): float {
            return $waktu += 350.0;
        };

        $h = $this->tarikTiruan($jenis, $detik)->jalankan('tiruan');

        $this->assertSame([5, 2, 3, true], [$h['nip'], $h['sukses'], $h['tidak_dicoba'], $h['diputus']]);
        $this->assertSame(array_slice(self::NIP, 0, 2), $dipanggil->getArrayCopy());

        $flag = array_map('strval', array_column($this->db->table('siasn_status_pegawai')->orderBy('nip', 'ASC')->get()->getResultArray(), 'rw_gol', 'nip'));
        $this->assertSame(['1', '1', '0', '0', '0'], array_values($flag));
        $this->assertSame('diputus', $this->db->table('integrasi_log')->where('operasi', 'tarik_tiruan')->get()->getRow()->kode);
    }

    public function testSemuaSuksesTidakBerubah(): void
    {
        $jenis = $this->jenisTiruan(array_fill(0, 5, SiasnAdapter::SUKSES));
        $h     = $this->tarikTiruan($jenis)->jalankan('tiruan');

        $this->assertSame([5, 5, 0, 0, 0, false], [$h['nip'], $h['sukses'], $h['gagal'], $h['gagal_transport'], $h['tidak_dicoba'], $h['diputus']]);
        $this->assertSame(5, $this->db->table('siasn_status_pegawai')->where('rw_gol', 1)->countAllResults());
        $this->assertNull($this->db->table('integrasi_log')->where('operasi', 'tarik_tiruan')->get()->getRow()->kode);
    }

    /**
     * @param list<string>                  $statusBerurutan status_code hasil per panggilan
     * @param ArrayObject<int, string>|null $dipanggil       diisi NIP sesuai urutan panggilan
     */
    private function jenisTiruan(array $statusBerurutan, ?ArrayObject $dipanggil = null): JenisTarikSiasn
    {
        return new class ($statusBerurutan, $dipanggil ?? new ArrayObject()) implements JenisTarikSiasn {
            /**
             * @param list<string>             $status
             * @param ArrayObject<int, string> $dipanggil
             */
            public function __construct(private array $status, private readonly ArrayObject $dipanggil)
            {
            }

            public function nama(): string
            {
                return 'tiruan';
            }

            public function kolomStatus(): string
            {
                return 'rw_gol';
            }

            public function jenisPegawai(): ?array
            {
                return null;
            }

            public function ambil(SiasnGatewayInterface $siasn, string $nip): array
            {
                $this->dipanggil->append($nip);
                $kode = array_shift($this->status) ?? SiasnAdapter::SUKSES;

                return ['status_code' => $kode, 'message' => 'tiruan', 'data' => $kode === SiasnAdapter::SUKSES ? [] : null];
            }

            public function rencana(BaseConnection $db, array $hasil, int $idAkun, string $sekarang): RencanaTarik
            {
                return new RencanaTarik();
            }
        };
    }

    /**
     * @param (Closure(): float)|null $detik
     */
    private function tarikTiruan(JenisTarikSiasn $jenis, ?Closure $detik = null): TarikSiasn
    {
        return new TarikSiasn(
            $this->db,
            $this->adapter,
            new IntegrasiLog($this->db),
            new AkunSistemIntegrasi($this->db),
            ['tiruan' => $jenis],
            null,
            TarikSiasn::BATAS_GAGAL_BERUNTUN,
            TarikSiasn::ANGGARAN_DETIK,
            $detik,
        );
    }
}
