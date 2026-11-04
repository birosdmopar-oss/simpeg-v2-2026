<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\Log\IntegrasiLog;
use App\Libraries\Siasn\MockSiasnAdapter;
use App\Libraries\Siasn\Tarik\JenisTarikSiasn;
use App\Libraries\Siasn\Tarik\RencanaTarik;
use App\Libraries\Siasn\Tarik\SiasnTarikSibuk;
use App\Libraries\Siasn\Tarik\SiasnTidakSiap;
use App\Libraries\Siasn\Tarik\StatusTarikSiasn;
use App\Libraries\Siasn\Tarik\TarikGolongan;
use App\Libraries\Siasn\Tarik\TarikSiasn;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Config\Integrations;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SiasnUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-067 M1 — pengaman `siasn:tarik` dari review independen B6 M1 (fixture sintetis, FakeTransport):
 * - B-1: jawaban berbentuk tidak dikenal (objek, elemen bukan objek, `code` ≠ 1, JSON rusak, timeout, token gagal), driver
 *   mock, dan baris tanpa `id` TIDAK memicu hapus fisik/lepas; `siasn:tarik` menolak driver selain `bkn`;
 * - P-1: exception apa pun sesudah ditandai proses → flag kembali 0; `du = 2` macet > 6 jam dipilih lagi;
 * - P-5: kunci per jenis — run kedua ditolak tanpa panggilan API;
 * - B-3: tulis status yang gagal tidak senyap walau `DBDebug` mati;
 * - dry-run SKP tidak menghapus apa pun; P-6: galat DB di command tanpa pesan mentah.
 *
 * @internal
 */
final class SiasnTarikPengamanTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SiasnUjiTrait;
    use StreamFilterTrait;

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStreamFilterBuffer();
        $this->siapkanSiasn();
    }

    protected function tearDown(): void
    {
        $this->pulihkanSiasn();
        parent::tearDown();
    }

    public function testBentukJawabanTidakSahDanGalatTransportTidakMemicuHapusSkp(): void
    {
        $nip = [];

        foreach (range(1, 6) as $i) {
            $nip[$i] = '19900101202001100' . $i;
            $this->buatPegawai(['nip' => $nip[$i], 'id_jenis_pegawai' => 1]);
            $skp[$i] = $this->skp($nip[$i], 'SKP-LAMA-' . $i);
        }

        $this->antreToken();
        // 1: sah (SKP-LAMA-1 tidak dikembalikan → dihapus; batch punya sisip).
        $this->antreData([['id' => 'SKP-BARU-1', 'tahun' => 2024, 'hasilKinerjaNilai' => '2', 'PerilakuKerjaNilai' => '2', 'kuadranKinerja' => 'baik']]);
        // 2: timeout (GET dicoba 3x).
        $this->transport->antre(new GalatTransport('putus', true), new GalatTransport('putus', true), new GalatTransport('putus', true));
        // 3: code ≠ 1.
        $this->transport->antreJson(200, ['code' => 0, 'data' => []]);
        // 4: JSON rusak.
        $this->transport->antre(new Respons(200, '<html>bukan json</html>'));
        // 5: data objek, bukan daftar.
        $this->transport->antreJson(200, ['code' => 1, 'data' => ['id' => 'SKP-OBJ', 'tahun' => 2024]]);
        // 6: elemen daftar bukan objek.
        $this->transport->antreJson(200, ['code' => 1, 'data' => ['SKP-TEKS']]);

        $h = $this->tarik()->jalankan('skp');

        $this->assertSame([6, 1, 5], [$h['nip'], $h['sukses'], $h['gagal']]);
        $this->assertSame([1, 1], [$h['jumlah']['sisip'], $h['jumlah']['hapus']]);
        $this->assertSame(0, $this->db->table('riwayat_skp')->where('id_riwayat_skp', $skp[1])->countAllResults(), 'NIP sah: pasangan hilang dihapus');

        foreach (range(2, 6) as $i) {
            $this->assertSame(1, $this->db->table('riwayat_skp')->where('id_riwayat_skp', $skp[$i])->countAllResults(), "NIP {$i}: jawaban tidak sah → tanpa hapus");
        }
    }

    public function testTokenGagalDicatatGagalTanpaDestruktif(): void
    {
        $nip = '199001012020011001';
        $this->buatPegawai(['nip' => $nip, 'id_jenis_pegawai' => 1]);
        $skp = $this->skp($nip, 'SKP-LAMA');
        $this->transport->antre(new Respons(500, 'galat token'));

        $h = $this->tarik()->jalankan('skp');

        $this->assertSame([1, 0, 1], [$h['nip'], $h['sukses'], $h['gagal']]);
        $this->assertSame(1, $this->db->table('riwayat_skp')->where('id_riwayat_skp', $skp)->countAllResults());
    }

    public function testDriverMockDitolakDanJawabanMockTidakMemicuLepasAtauHapus(): void
    {
        config(Integrations::class)->siasn = ['driver' => 'mock'] + config(Integrations::class)->siasn;

        try {
            TarikSiasn::dariLayanan();
            $this->fail('driver mock harus ditolak (B-1c)');
        } catch (SiasnTidakSiap $e) {
            $this->assertStringContainsString("harus 'bkn'", $e->getMessage());
        }

        command('siasn:tarik --jenis=skp');
        $this->assertStringContainsString("integrations.siasn.driver harus 'bkn'", $this->getStreamFilterBuffer());

        // Bila tetap dirakit langsung dengan gateway mock: baris contoh tanpa `id` ditolak → tanpa lepas/hapus.
        $nip = '199001012020011001';
        $this->buatPegawai(['nip' => $nip, 'id_jenis_pegawai' => 1]);
        $skp = $this->skp($nip, 'SKP-LAMA');
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'gol_ruang' => 'III/a', 'id_rw_siasn' => 'GOL-LAMA', 'tmtsk' => '2015-01-01', 'status' => 1, 'id_jenis_kp' => 3]);
        $kp    = (int) $this->db->insertID();
        $tarik = new TarikSiasn($this->db, new MockSiasnAdapter(), new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db), TarikSiasn::semuaJenis());

        foreach (['golongan', 'skp'] as $jenis) {
            $h = $tarik->jalankan($jenis);
            $this->assertSame(1, $h['info']['ditolak_tanpa_destruktif'], $jenis);
        }

        $this->assertSame('GOL-LAMA', $this->db->table('riwayat_kp')->where('id_riwayat_kp', $kp)->get()->getRow()->id_rw_siasn);
        $this->assertSame(1, $this->db->table('riwayat_skp')->where('id_riwayat_skp', $skp)->countAllResults());
    }

    public function testExceptionNonDbMengembalikanFlagDanDuMacetDipilihLagi(): void
    {
        $nip = '199001012020011001';
        $this->buatPegawai(['nip' => $nip, 'id_jenis_pegawai' => 1]);
        $rusak = new class () implements JenisTarikSiasn {
            public function nama(): string
            {
                return 'rusak';
            }

            public function kolomStatus(): string
            {
                return 'rw_skp22';
            }

            public function jenisPegawai(): ?array
            {
                return null;
            }

            public function ambil(SiasnGatewayInterface $siasn, string $nip): array
            {
                return ['status_code' => '200', 'message' => 'OK', 'data' => []];
            }

            public function rencana(BaseConnection $db, array $hasil, int $idAkun, string $sekarang): RencanaTarik
            {
                throw new RuntimeException('galat baca uji');
            }
        };
        $tarik = new TarikSiasn($this->db, $this->adapter, new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db), ['rusak' => $rusak]);

        try {
            $tarik->jalankan('rusak');
            $this->fail('exception harus dilempar ulang');
        } catch (RuntimeException $e) {
            $this->assertSame('galat baca uji', $e->getMessage());
        }

        $this->assertSame('0', (string) $this->db->table('siasn_status_pegawai')->where('nip', $nip)->get()->getRow()->rw_skp22, 'P-1: flag kembali 0');
        $this->assertSame('galat', $this->db->table('integrasi_log')->where('operasi', 'tarik_rusak')->get()->getRow()->kode);

        // P-1: du = 2 yang macet > 6 jam dipilih lagi; < 6 jam belum.
        $status = new StatusTarikSiasn($this->db);
        $this->db->table('siasn_status_pegawai')->where('nip', $nip)->update(['du' => 2, 'du_lu' => null, 'updated_at' => '2026-11-04 01:00:00']);
        $this->assertSame([], $status->pilih(new \App\Libraries\Siasn\Tarik\TarikDataUtama(), 20, null, '2026-11-04 06:59:00'));
        $this->assertSame([$nip], $status->pilih(new \App\Libraries\Siasn\Tarik\TarikDataUtama(), 20, null, '2026-11-04 07:01:00'));
    }

    public function testKunciPerJenisMenolakRunKeduaTanpaPanggilanApi(): void
    {
        $nip = '199001012020011001';
        $this->buatPegawai(['nip' => $nip, 'id_jenis_pegawai' => 1]);
        $lain  = Database::connect('tests', false);
        $kunci = TarikSiasn::namaKunci($this->db, 'golongan');

        try {
            $lain->query('SELECT GET_LOCK(?, 0)', [$kunci]);

            try {
                $this->tarik()->jalankan('golongan');
                $this->fail('run kedua harus ditolak (P-5)');
            } catch (SiasnTarikSibuk $e) {
                $this->assertStringContainsString('sedang berjalan', $e->getMessage());
            }

            $this->assertSame([], $this->transport->tercatat(), 'tanpa panggilan API');
            $this->assertSame(0, $this->db->table('siasn_status_pegawai')->countAllResults(), 'tanpa tulis apa pun');

            // Dry-run tidak menulis apa pun sehingga tidak memakai kunci.
            $this->assertSame(0, $this->tarik()->jalankan('golongan', 20, null, true)['nip']);
        } finally {
            $lain->query('SELECT RELEASE_LOCK(?)', [$kunci]);
            $lain->close();
        }

        $this->antreToken();
        $this->antreData([]);
        $this->assertSame(1, $this->tarik()->jalankan('golongan')['nip'], 'sesudah dilepas berjalan normal');
    }

    public function testTulisStatusGagalTidakSenyapWalauDbDebugMati(): void
    {
        $grup             = config(Database::class)->tests;
        $grup['DBDebug']  = false;
        $grup['DBPrefix'] = 'tidak_ada_';
        $rusak            = Database::connect($grup, false);

        try {
            $this->expectException(DatabaseException::class);
            (new StatusTarikSiasn($rusak))->tandaiSelesai(new TarikGolongan(), ['199001012020011001'], '2026-11-04 01:00:00');
        } finally {
            $rusak->close();
        }
    }

    public function testDryRunSkpTidakMenghapus(): void
    {
        $nip = '199001012020011001';
        $this->buatPegawai(['nip' => $nip, 'id_jenis_pegawai' => 1]);
        $this->db->table('siasn_status_pegawai')->insert(['nip' => $nip]);
        $lama = $this->skp($nip, 'SKP-LAMA');
        $this->antreToken();
        $this->antreData([['id' => 'SKP-BARU', 'tahun' => 2024, 'hasilKinerjaNilai' => '2', 'PerilakuKerjaNilai' => '2', 'kuadranKinerja' => 'baik']]);

        $h = $this->tarik()->jalankan('skp', 20, null, true);

        $this->assertSame([1, 1], [$h['jumlah']['sisip'], $h['jumlah']['hapus']], 'rencana dihitung');
        $this->assertSame(1, $this->db->table('riwayat_skp')->where('id_riwayat_skp', $lama)->countAllResults(), 'dry-run tanpa DELETE');
        $this->assertSame(0, $this->db->table('riwayat_skp')->where('id_rw_siasn', 'SKP-BARU')->countAllResults());
    }

    private function skp(string $nip, string $idSiasn): int
    {
        $this->db->table('riwayat_skp')->insert(['nip' => $nip, 'tahun' => 2023, 'id_rw_siasn' => $idSiasn, 'status' => 1]);

        return (int) $this->db->insertID();
    }
}
