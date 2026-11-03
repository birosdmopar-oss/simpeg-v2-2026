<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Bkn\Ekinerja\PemetaEkinerja;
use App\Libraries\Bkn\Ekinerja\SinkronEkinerja;
use App\Libraries\Presensi\TukinData\SumberDataPegawaiTukin;
use App\Libraries\Presensi\TukinData\SumberPeriodeTukin;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-064 (H-10) — penulis e-Kinerja: periode insert-baru-saja, SKP periodik insert/update-bila-hasil_akhir-berubah,
 * idempoten (AC-10.1), hitungan tidak terpasang/tidak dikenal (AC-10.2), dan hasilnya terbaca pembaca Tukin Fase 5 tanpa
 * perubahan logika Tukin (AC-10.3). Fixture sintetis `tests/_support/Integrasi/Bkn/`.
 *
 * @internal
 */
final class SinkronEkinerjaTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    public function testPeriodeHanyaInsertBaruDanTerbacaTukin(): void
    {
        $sinkron = new SinkronEkinerja($this->db);
        $baris   = PemetaEkinerja::periode($this->fixture('periode_ok.json'))['baris'];

        $this->assertSame(['baru' => 4, 'sudah_ada' => 0], $sinkron->simpanPeriode($baris));

        $ubah            = $baris;
        $ubah[0]['nama'] = 'Nama BKN berubah';
        $this->assertSame(['baru' => 0, 'sudah_ada' => 4], $sinkron->simpanPeriode($ubah), 'periode lama tidak diperbarui [K]');
        $this->assertSame('Periode Uji Oktober 2026', $this->db->table('bkn_periode_ekinper')->where('id', 'PER-UJI-2026-10')->get()->getRow()->nama);

        $tukin = (new SumberPeriodeTukin($this->db))->barisBkn(2026, 10);
        $this->assertSame(['id' => 'PER-UJI-2026-10', 'awal' => '2026-09-16', 'akhir' => '2026-10-15'], $tukin);
    }

    public function testSkpInsertUpdateIdempotenDanTerbacaTukin(): void
    {
        $nip1 = $this->buatPegawai(['nip' => '199001012020011001', 'id_pns_siasn' => 'PNS-UJI-001']);
        $this->buatPegawai(['nip' => '199001012020011002', 'id_pns_siasn' => null]);
        $sinkron = new SinkronEkinerja($this->db);
        $baris   = PemetaEkinerja::skpPeriodik($this->fixture('skp_periodik_ok.json'))['baris'];

        $this->assertSame(['baru' => 2, 'diperbarui' => 0, 'tetap' => 0, 'tidak_terpasang' => 1, 'tidak_dikenal' => 0], $sinkron->simpanSkpPeriodik($baris));
        $this->assertSame(['baru' => 0, 'diperbarui' => 0, 'tetap' => 2, 'tidak_terpasang' => 1, 'tidak_dikenal' => 0], $sinkron->simpanSkpPeriodik($baris), 'ulang = tanpa perubahan');
        $this->assertSame(2, $this->db->table('riwayat_skp_periodik')->countAllResults());

        $tukin = new SumberDataPegawaiTukin($this->db);
        $this->assertSame('baik', $tukin->predikatSkp($nip1, 'PER-UJI-2026-10'));

        $baris[0]['hasil_akhir'] = 'SANGAT BAIK';
        $baris[0]['skp_jabatan'] = 'Jabatan Baru';
        $baris[1]['hasil_akhir'] = null;
        $this->assertSame(['baru' => 0, 'diperbarui' => 1, 'tetap' => 1, 'tidak_terpasang' => 1, 'tidak_dikenal' => 0], $sinkron->simpanSkpPeriodik($baris), 'update hanya bila hasil_akhir terisi & berubah');
        $satu = $this->db->table('riwayat_skp_periodik')->where('nip', $nip1)->get()->getRowArray();
        $this->assertSame(['SANGAT BAIK', 'Jabatan Baru', '1'], [$satu['hasil_akhir'], $satu['skp_jabatan'], (string) $satu['status']], 'seluruh kolom ditimpa [K EB:303-336]');
        $this->assertSame('sangat baik', $tukin->predikatSkp($nip1, 'PER-UJI-2026-10'));
    }

    public function testNipTidakDikenalTetapDisimpanDanDihitung(): void
    {
        $baris = PemetaEkinerja::skpPeriodik($this->fixture('skp_periodik_ok.json'))['baris'];

        $hasil = (new SinkronEkinerja($this->db))->simpanSkpPeriodik($baris);

        $this->assertSame(['baru' => 2, 'tidak_terpasang' => 0, 'tidak_dikenal' => 2], array_intersect_key($hasil, ['baru' => 0, 'tidak_terpasang' => 0, 'tidak_dikenal' => 0]));
        $this->assertSame(2, $this->db->table('riwayat_skp_periodik')->countAllResults(), 'ikut legacy: tetap disimpan');
    }

    public function testUniqueMenolakDuplikatKunciDariJalurLain(): void
    {
        $baris = PemetaEkinerja::skpPeriodik($this->fixture('skp_periodik_ok.json'))['baris'];
        (new SinkronEkinerja($this->db))->simpanSkpPeriodik([$baris[0]]);

        $this->assertDbError([1062], fn () => $this->db->table('riwayat_skp_periodik')->insert($baris[0]));
    }

    public function testKunciGandaTarikBersamaanGagalUtuhTanpaSetengahJadi(): void
    {
        $baris = PemetaEkinerja::skpPeriodik($this->fixture('skp_periodik_ok.json'))['baris'];
        (new SinkronEkinerja($this->db))->simpanSkpPeriodik([$baris[0]]);

        // Proses lain menyisipkan kunci baris kedua di antara baca dan tulis; batch ini juga memperbarui baris pertama.
        $baris[0]['hasil_akhir'] = 'SANGAT BAIK';
        $lain                    = $baris[1] + ['created_at' => gmdate('Y-m-d H:i:s')];
        $sinkron                 = new SinkronEkinerja($this->db, fn () => $this->db->table('riwayat_skp_periodik')->insert($lain));

        try {
            $sinkron->simpanSkpPeriodik($baris);
            $this->fail('1062 harus menggagalkan simpan, bukan rollback diam-diam');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('SKP periodik', $e->getMessage() . ($e->getPrevious()?->getMessage() ?? ''));
        }

        $this->assertSame('BAIK', $this->db->table('riwayat_skp_periodik')->where('skp_id', $baris[0]['skp_id'])->get()->getRow()->hasil_akhir, 'update ikut dibatalkan');
        $this->assertSame(2, $this->db->table('riwayat_skp_periodik')->countAllResults());
        $this->assertTrue($this->db->transStatus(), 'status transaksi koneksi dipulihkan');

        $this->assertSame(1, (new SinkronEkinerja($this->db))->simpanSkpPeriodik($baris)['diperbarui'], 'koneksi tetap dapat menulis sesudahnya');
    }

    public function testNilaiDitolakStrictGagalTanpaBarisSetengahJadi(): void
    {
        $baris            = PemetaEkinerja::skpPeriodik($this->fixture('skp_periodik_ok.json'))['baris'];
        $baris[1]['nama'] = str_repeat('x', 300);

        try {
            (new SinkronEkinerja($this->db))->simpanSkpPeriodik($baris);
            $this->fail('nilai terlalu panjang harus menggagalkan simpan');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->db->table('riwayat_skp_periodik')->countAllResults(), 'baris pertama ikut dibatalkan');
        $this->assertTrue($this->db->transStatus());
    }

    public function testPeriodeGandaTarikBersamaanGagal(): void
    {
        $baris   = PemetaEkinerja::periode($this->fixture('periode_ok.json'))['baris'];
        $sinkron = new SinkronEkinerja($this->db, fn () => $this->db->table('bkn_periode_ekinper')->insert($baris[1] + ['created_at' => gmdate('Y-m-d H:i:s')]));

        $this->expectException(DatabaseException::class);
        $sinkron->simpanPeriode($baris);
    }

    public function testKunciDinormalisasiTrimHurufKecil(): void
    {
        $baris = PemetaEkinerja::skpPeriodik($this->fixture('skp_periodik_ok.json'))['baris'];
        (new SinkronEkinerja($this->db))->simpanSkpPeriodik($baris);

        $baris[0]['id_pns'] = strtolower((string) $baris[0]['id_pns']);
        $baris[1]['skp_id'] = strtoupper((string) $baris[1]['skp_id']) . ' ';

        $this->assertSame(['baru' => 0, 'tetap' => 2], array_intersect_key((new SinkronEkinerja($this->db))->simpanSkpPeriodik($baris), ['baru' => 0, 'tetap' => 0]));
    }

    /**
     * @param list<int> $kode
     */
    private function assertDbError(array $kode, callable $aksi): void
    {
        try {
            $aksi();
            $this->fail('galat DB diharapkan');
        } catch (\Throwable $e) {
            $this->assertContains((int) $e->getCode(), $kode, $e->getMessage());
        }
    }

    /**
     * @return array<mixed>
     */
    private function fixture(string $nama): array
    {
        return (array) json_decode((string) file_get_contents(SUPPORTPATH . 'Integrasi/Bkn/' . $nama), true);
    }
}
