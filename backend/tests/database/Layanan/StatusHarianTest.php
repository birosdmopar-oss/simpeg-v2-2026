<?php

declare(strict_types=1);

namespace Tests\Database\Layanan;

use App\Commands\LayananStatusHarian;
use App\Constants\AkunSistem;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Layanan\StatusHarian\StatusHarianService;
use App\Libraries\Layanan\StatusHarian\StatusHarianSibuk;
use App\Models\AuditLogModel;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Config\Services;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-062 (H-11) — `layanan:status-harian` ber-DB: AC-11.1..11.10 (PRD Fase 6 §7.9, Q6-24..Q6-27, CR-098) dan pengaman
 * (transaksi per pegawai, kunci, pegawai tidak aktif, tanpa NIP di keluaran).
 *
 * @internal
 */
final class StatusHarianTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use StreamFilterTrait;

    protected $seed = MasterDataSeeder::class;

    private int $pelaku;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->table('jenis_status')->insert(['id_jenis_status' => 12, 'jenis_status' => 'Status Lain', 'status_pegawai' => 1, 'order' => 12, 'status' => 1]);
        (new AkunSistemIntegrasi($this->db))->siapkan();
        $this->pelaku = (new AkunSistemIntegrasi($this->db))->id();
    }

    private function service(?AuditLogModel $audit = null): StatusHarianService
    {
        return new StatusHarianService($this->db, $audit ?? new AuditLogModel());
    }

    /**
     * @param array<string, mixed> $lebih
     */
    private function pegawai(int $idJenisStatus = 1, array $lebih = []): string
    {
        return $this->buatPegawai(['id_jenis_status' => $idJenisStatus] + $lebih);
    }

    private function statusPegawai(string $nip): int
    {
        return (int) $this->db->table('pegawai')->select('id_jenis_status')->where('nip', $nip)->get()->getRow()->id_jenis_status;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function perpanjangan(string ...$rentang): array
    {
        return array_map(static fn (string $r): array => explode('..', $r), $rentang);
    }

    private function tb(string $nip, string $mulai, string $akhir, int $status = 1, array $perpanjangan = []): int
    {
        $this->db->table('riwayat_tb')->insert([
            'nip'                  => $nip, 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'status' => $status, 'jenjang_pendidikan_singkat' => 'S2',
            'institusi_pendidikan' => 'Universitas Uji', 'jurusan_pendidikan' => 'Jurusan Uji', 'dalam_negeri' => 1,
            'lokasi_pendidikan'    => 'Kota Uji', 'lembaga_yang_mendanai' => 'Lembaga Uji',
        ]);
        $id = (int) $this->db->insertID();

        foreach ($perpanjangan as [$m, $a]) {
            $this->db->table('riwayat_tb_perpan')->insert(['id_riwayat_tb' => $id, 'tgl_mulai_perpan' => $m, 'tgl_akhir_perpan' => $a, 'file_sperpan' => 'uji.pdf']);
        }

        return $id;
    }

    private function cltn(string $nip, string $mulai, string $akhir, int $status = 1): int
    {
        $this->db->table('riwayat_cuti')->insert([
            'nip'       => $nip, 'nama' => 'Pegawai Uji', 'id_jenis_cuti' => 6, 'nama_jenis_cuti' => 'CLTN', 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir,
            'lama_cuti' => 1, 'status' => $status, 'status_al' => 0, 'status_pyb' => 0, 'created_at' => '2026-01-02 02:00:00',
        ]);

        return (int) $this->db->insertID();
    }

    private function jumlahAudit(): int
    {
        return $this->db->table('audit_logs')->where('entity', 'pegawai')->like('after_json', 'layanan.status_harian')->countAllResults();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Aturan harian (AC-11.1..11.6)

    public function testAc111Dan112TbAktifTepatPadaTanggalMulaiDanKembaliSetelahAkhir(): void
    {
        $nip = $this->pegawai();
        $this->tb($nip, '2026-11-10', '2026-12-09');

        $this->service()->jalankan('2026-11-09', false, $this->pelaku);
        $this->assertSame(1, $this->statusPegawai($nip), 'sehari sebelum mulai');

        $hasil = $this->service()->jalankan('2026-11-10', false, $this->pelaku);
        $this->assertSame(2, $this->statusPegawai($nip));
        $this->assertSame(['berubah' => 1, 'tb_aktif' => 1], ['berubah' => $hasil['berubah'], 'tb_aktif' => $hasil['tb_aktif']]);

        $this->service()->jalankan('2026-12-09', false, $this->pelaku);
        $this->assertSame(2, $this->statusPegawai($nip), 'hari terakhir masih TB');

        $hasil = $this->service()->jalankan('2026-12-10', false, $this->pelaku);
        $this->assertSame(1, $this->statusPegawai($nip));
        $this->assertSame(1, $hasil['pulih_tb']);
    }

    public function testAc113PerpanjanganBersambungTidakBerkedipDanBerCelahKembaliLaluTb(): void
    {
        $bersambung = $this->pegawai();
        $this->tb($bersambung, '2026-01-01', '2026-06-30', 1, $this->perpanjangan('2026-07-01..2026-12-31'));
        $celah = $this->pegawai();
        $this->tb($celah, '2026-01-01', '2026-06-30', 1, $this->perpanjangan('2026-07-10..2026-12-31'));

        $this->service()->jalankan('2026-06-30', false, $this->pelaku);
        $this->assertSame([2, 2], [$this->statusPegawai($bersambung), $this->statusPegawai($celah)]);

        $hasil = $this->service()->jalankan('2026-07-01', false, $this->pelaku);
        $this->assertSame([2, 1], [$this->statusPegawai($bersambung), $this->statusPegawai($celah)], 'bersambung tetap 2; celah kembali 1');
        $this->assertSame(1, $hasil['info']['celah_tb']);

        $this->service()->jalankan('2026-07-10', false, $this->pelaku);
        $this->assertSame(2, $this->statusPegawai($celah), 'perpanjangan mulai → 2 lagi');
    }

    public function testAc114StatusLainTidakDisentuh(): void
    {
        $nip = $this->pegawai(12);
        $this->tb($nip, '2026-01-01', '2026-12-31');
        $this->cltn($nip, '2026-01-01', '2026-12-31');

        $hasil = $this->service()->jalankan('2026-06-01', false, $this->pelaku);

        $this->assertSame(12, $this->statusPegawai($nip));
        $this->assertSame(0, $hasil['berubah']);
        $this->assertSame(1, $hasil['info']['dilewati_status_lain']);
        $this->assertSame(0, $this->jumlahAudit());
    }

    public function testAc115Idempoten(): void
    {
        $tb   = $this->pegawai();
        $cltn = $this->pegawai();
        $this->tb($tb, '2026-01-01', '2026-12-31');
        $this->cltn($cltn, '2026-01-01', '2026-12-31');

        $pertama = $this->service()->jalankan('2026-06-01', false, $this->pelaku);
        $kedua   = $this->service()->jalankan('2026-06-01', false, $this->pelaku);

        $this->assertSame(2, $pertama['berubah']);
        $this->assertSame(0, $kedua['berubah']);
        $this->assertSame([2, 3], [$this->statusPegawai($tb), $this->statusPegawai($cltn)]);
        $this->assertSame(2, $this->jumlahAudit(), 'run kedua tidak menambah audit');
    }

    public function testAc116SetiapPerubahanTeraudit(): void
    {
        $nip = $this->pegawai();
        $this->tb($nip, '2026-01-01', '2026-12-31');

        $this->service()->jalankan('2026-06-01', false, $this->pelaku);

        $baris = $this->db->table('audit_logs')->where('entity', 'pegawai')->where('entity_id', $nip)->get()->getRowArray();
        $this->assertNotNull($baris);
        $this->assertSame($this->pelaku, (int) $baris['id_pengguna_actor']);
        $this->assertNull($baris['nip_actor']);
        $this->assertSame(['id_jenis_status' => 1], json_decode((string) $baris['before_json'], true));
        $this->assertEquals(
            ['id_jenis_status' => 2, 'jenis_audit' => 'layanan.status_harian', 'alasan' => 'tb_aktif'],
            json_decode((string) $baris['after_json'], true),
        );
        $peg = $this->db->table('pegawai')->where('nip', $nip)->get()->getRowArray();
        $this->assertSame($this->pelaku, (int) $peg['updated_by']);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // CR-098 (Q6-24..Q6-27)

    public function testAc117BentrokKeduaCabang(): void
    {
        $dariAktif = $this->pegawai();
        $this->tb($dariAktif, '2026-01-01', '2026-12-31');
        $this->cltn($dariAktif, '2026-06-01', '2027-05-31');
        $sudahTb = $this->pegawai(2);
        $this->tb($sudahTb, '2026-01-01', '2026-12-31');
        $this->cltn($sudahTb, '2026-06-01', '2027-05-31');
        $seri = $this->pegawai();
        $this->tb($seri, '2026-03-01', '2026-12-31');
        $this->cltn($seri, '2026-03-01', '2026-12-31');

        $hasil = $this->service()->jalankan('2026-07-01', false, $this->pelaku);

        $this->assertSame(3, $this->statusPegawai($dariAktif), 'cabang 1: mulai CLTN lebih baru');
        $this->assertSame(2, $this->statusPegawai($sudahTb), 'cabang 2: sudah 2 dan TB masih aktif → tidak berkedip');
        $this->assertSame(2, $this->statusPegawai($seri), 'seri → TB');
        $this->assertSame(3, $hasil['info']['bentrok']);
        $this->assertSame(2, $hasil['berubah']);
    }

    public function testAc118CltnStatus4TidakMengubahStatus(): void
    {
        $nip = $this->pegawai();
        $this->cltn($nip, '2026-01-01', '2026-12-31', 4);

        $hasil = $this->service()->jalankan('2026-06-01', false, $this->pelaku);

        $this->assertSame(1, $this->statusPegawai($nip));
        $this->assertSame(0, $hasil['berubah']);
        $this->assertSame(0, $this->jumlahAudit());
    }

    public function testAc119StatusTanpaRiwayatPendukungTidakDisentuhDanTerhitungInfo(): void
    {
        $manual2  = $this->pegawai(2);
        $manual3  = $this->pegawai(3);
        $berakhir = $this->pegawai(2);
        $this->tb($berakhir, '2025-01-01', '2025-12-31');

        $hasil = $this->service()->jalankan('2026-06-01', false, $this->pelaku);

        $this->assertSame([2, 3, 1], [$this->statusPegawai($manual2), $this->statusPegawai($manual3), $this->statusPegawai($berakhir)]);
        $this->assertSame(2, $hasil['info']['tanpa_riwayat']);
        $this->assertSame(1, $hasil['pulih_tb']);
    }

    public function testPegawaiTidakAktifDilewatiDanTerhitungInfo(): void
    {
        $nip = $this->pegawai(1, ['status' => 2]);
        $this->tb($nip, '2026-01-01', '2026-12-31');

        $hasil = $this->service()->jalankan('2026-06-01', false, $this->pelaku);

        $this->assertSame(1, $this->statusPegawai($nip));
        $this->assertSame(1, $hasil['info']['dilewati_nonaktif']);
        $this->assertSame(0, $hasil['berubah']);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Pengaman

    public function testDryRunTidakMenulisDanTidakButuhPelaku(): void
    {
        $nip = $this->pegawai();
        $this->tb($nip, '2026-01-01', '2026-12-31');

        $hasil = $this->service()->jalankan('2026-06-01', true, null);

        $this->assertTrue($hasil['dry_run']);
        $this->assertSame(1, $hasil['berubah'], 'dihitung sebagai "akan berubah"');
        $this->assertSame(1, $this->statusPegawai($nip));
        $this->assertSame(0, $this->jumlahAudit());
    }

    public function testRunNyataTanpaPelakuDitolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->jalankan('2026-06-01', false, null);
    }

    public function testKegagalanSatuPegawaiDihitungDanTidakMenghentikanYangLain(): void
    {
        $gagal = $this->pegawai();
        $this->tb($gagal, '2026-01-01', '2026-12-31');
        $baik = $this->pegawai();
        $this->tb($baik, '2026-01-01', '2026-12-31');

        $audit = new class ($gagal) extends AuditLogModel {
            public function __construct(private readonly string $nipGagal)
            {
                parent::__construct();
            }

            public function record(string $entity, string|int $entityId, string $event, ?array $before, ?array $after, ?int $actorId = null, ?string $actorNip = null): int
            {
                if ((string) $entityId === $this->nipGagal) {
                    throw new RuntimeException('audit gagal (uji)');
                }

                return parent::record($entity, $entityId, $event, $before, $after, $actorId, $actorNip);
            }
        };

        $hasil = $this->service($audit)->jalankan('2026-06-01', false, $this->pelaku);

        $this->assertSame(1, $hasil['gagal']);
        $this->assertSame(1, $hasil['berubah']);
        $this->assertSame(1, $this->statusPegawai($gagal), 'transaksi pegawai yang gagal di-rollback');
        $this->assertSame(2, $this->statusPegawai($baik));
    }

    public function testRunNyataKeduaDitolakSelamaKunciDipegang(): void
    {
        $lain  = Database::connect($this->DBGroup, false);
        $kunci = StatusHarianService::namaKunci($this->db);
        $lain->query('SELECT GET_LOCK(?, 0)', [$kunci]);

        try {
            $this->expectException(StatusHarianSibuk::class);
            $this->service()->jalankan('2026-06-01', false, $this->pelaku);
        } finally {
            $lain->query('SELECT RELEASE_LOCK(?)', [$kunci]);
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Command (AC-11.10, Q6-27)

    /**
     * @param array<int|string, string|null> $params
     *
     * @return array{0: int, 1: string}
     */
    private function jalankanCommand(array $params): array
    {
        $this->resetStreamFilterBuffer();
        $kode = (new LayananStatusHarian(Services::logger(), Services::commands()))->run($params);

        return [$kode, $this->getStreamFilterBuffer()];
    }

    public function testAc1110TanggalMasaDepanDitolakDanLampauHanyaBersamaDryRun(): void
    {
        $nip = $this->pegawai();
        $this->tb($nip, '2020-01-01', '2099-12-31');
        $hariIni = TanggalBisnis::hariIni();
        $besok   = date('Y-m-d', strtotime($hariIni . ' +1 day'));
        $kemarin = date('Y-m-d', strtotime($hariIni . ' -1 day'));

        $this->assertSame(EXIT_USER_INPUT, $this->jalankanCommand(['tanggal' => $besok])[0], 'masa depan');
        $this->assertSame(EXIT_USER_INPUT, $this->jalankanCommand(['tanggal' => $besok, 'dry-run' => null])[0], 'masa depan + dry-run tetap ditolak');
        $this->assertSame(EXIT_USER_INPUT, $this->jalankanCommand(['tanggal' => $kemarin])[0], 'lampau tanpa dry-run');
        $this->assertSame(EXIT_USER_INPUT, $this->jalankanCommand(['tanggal' => '2026-13-45'])[0], 'format tidak sah');
        $this->assertSame(1, $this->statusPegawai($nip), 'penolakan tidak menulis apa pun');

        [$kode, $keluaran] = $this->jalankanCommand(['tanggal' => $kemarin, 'dry-run' => null]);
        $this->assertSame(EXIT_SUCCESS, $kode);
        $this->assertStringContainsString('SAAT INI', $keluaran);
        $this->assertSame(1, $this->statusPegawai($nip));
    }

    public function testCommandRunNyataHariIniMenulisDanKeluaranTanpaNip(): void
    {
        $nip = $this->pegawai();
        $this->tb($nip, '2020-01-01', '2099-12-31');

        [$kode, $keluaran] = $this->jalankanCommand([]);

        $this->assertSame(EXIT_SUCCESS, $kode);
        $this->assertSame(2, $this->statusPegawai($nip));
        $this->assertStringContainsString('berubah 1', $keluaran);
        $this->assertStringNotContainsString($nip, $keluaran);
    }

    public function testCommandTanpaAkunSistemGagalSebelumMenulisTetapDryRunJalan(): void
    {
        $nip = $this->pegawai();
        $this->tb($nip, '2020-01-01', '2099-12-31');
        $this->db->table('pengguna')->where('username', AkunSistem::USERNAME)->delete();

        [$kode, $keluaran] = $this->jalankanCommand([]);
        $this->assertSame(EXIT_ERROR, $kode);
        $this->assertStringContainsString('GAGAL', $keluaran);
        $this->assertSame(1, $this->statusPegawai($nip));

        $this->assertSame(EXIT_SUCCESS, $this->jalankanCommand(['dry-run' => null])[0]);
    }
}
