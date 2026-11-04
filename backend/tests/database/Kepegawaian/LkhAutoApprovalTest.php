<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use App\Libraries\Kepegawaian\Lkh\AutoApproval\AutoApprovalService;
use App\Libraries\Kepegawaian\Lkh\AutoApproval\AutoApprovalSibuk;
use App\Libraries\Kepegawaian\Lkh\AutoApproval\PenyetujuLkh;
use App\Libraries\Kepegawaian\Lkh\LkhService;
use Config\Database;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\AkunSistemUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * MAKE-068 (H-12, F6-25, CR-093): auto-approval LKH bulan lalu ikut legacy. Satu test per AC (12.1–12.3) plus status 3,
 * dry-run, galat sebagian, kunci sibuk, dan pelaku wajib. Pegawai tidak aktif dan atasan tidak difilter (ikut legacy).
 *
 * @internal
 */
final class LkhAutoApprovalTest extends DatabaseTestCase
{
    use AkunSistemUjiTrait;
    use PegawaiFixtureTrait;
    use RiwayatUjiTrait;

    private string $nip;
    private string $nipAtasan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nip       = $this->buatPegawai();
        $this->nipAtasan = $this->buatPegawai();
    }

    /**
     * AC-12.1: hanya LKH bulan lalu berstatus 0 yang disetujui; bulan lain dan status lain tidak tersentuh.
     */
    public function testAc121HanyaStatusMenungguBulanLalu(): void
    {
        $akun = $this->siapkanAkunSistem();

        $menunggu1 = $this->barisLkh('2026-10-01', LkhService::MENUNGGU);
        $menunggu2 = $this->barisLkh('2026-10-31', LkhService::MENUNGGU);
        $bulanIni  = $this->barisLkh('2026-11-02', LkhService::MENUNGGU);
        $duaBulan  = $this->barisLkh('2026-09-30', LkhService::MENUNGGU);

        $hasil = $this->layanan()->jalankan('2026-10', false, (int) $akun['id_pengguna']);

        $this->assertSame(2, $hasil['disetujui']);

        foreach ([$menunggu1, $menunggu2] as $id) {
            $this->assertSame(['1', '1', (string) $akun['id_pengguna'], (string) $akun['id_pengguna']], $this->kolomLkh($id, ['status', 'auto_approval', 'approved_by', 'updated_by']));
        }

        $this->assertSame(['0', '2'], $this->kolomLkh($bulanIni, ['status', 'auto_approval']));
        $this->assertSame(['0', '2'], $this->kolomLkh($duaBulan, ['status', 'auto_approval']));
    }

    /**
     * AC-12.2: ulang = tanpa efek ganda (nol kandidat, tidak ada audit baru).
     */
    public function testAc122UlangTanpaEfekGanda(): void
    {
        $akun    = $this->siapkanAkunSistem();
        $id      = $this->barisLkh('2026-10-10', LkhService::MENUNGGU);
        $layanan = $this->layanan();

        $layanan->jalankan('2026-10', false, (int) $akun['id_pengguna']);
        $audit = count($this->auditUji('riwayat_lckh', (string) $id));

        $ulang = $layanan->jalankan('2026-10', false, (int) $akun['id_pengguna']);

        $this->assertSame([0, 0], [$ulang['kandidat'], $ulang['disetujui']]);
        $this->assertSame($audit, count($this->auditUji('riwayat_lckh', (string) $id)), 'ulang tidak menambah audit');
        $this->assertGreaterThan(0, $audit);
        $this->assertSame((string) $akun['id_pengguna'], (string) $this->auditUji('riwayat_lckh', (string) $id)[0]['id_pengguna_actor']);
    }

    /**
     * AC-12.3: job tidak membangkitkan atau menyimpan PDF, dan tidak membuat tabel `aa_lkh`.
     */
    public function testAc123TanpaPdfDanTanpaTabelAaLkh(): void
    {
        $akun = $this->siapkanAkunSistem();
        $id   = $this->barisLkh('2026-10-12', LkhService::MENUNGGU);

        $this->layanan()->jalankan('2026-10', false, (int) $akun['id_pengguna']);

        $this->assertNull($this->db->table('riwayat_lckh')->where('id_riwayat_lckh', $id)->get()->getRowArray()['file_lckh']);
        $this->assertFalse($this->db->tableExists('aa_lkh'));
    }

    public function testStatusRevisiTidakDiproses(): void
    {
        $akun = $this->siapkanAkunSistem();
        $id   = $this->barisLkh('2026-10-15', LkhService::REVISI);

        $hasil = $this->layanan()->jalankan('2026-10', false, (int) $akun['id_pengguna']);

        $this->assertSame(0, $hasil['kandidat']);
        $this->assertSame(['3', '2'], $this->kolomLkh($id, ['status', 'auto_approval']));
    }

    public function testDryRunTidakMenulisDanTanpaAkun(): void
    {
        $id = $this->barisLkh('2026-10-03', LkhService::MENUNGGU);

        $hasil = $this->layanan()->jalankan('2026-10', true, null);

        $this->assertSame([1, 0, true], [$hasil['kandidat'], $hasil['disetujui'], $hasil['dry_run']]);
        $this->assertSame(['0', '2'], $this->kolomLkh($id, ['status', 'auto_approval']));
    }

    /**
     * Galat satu LKH dihitung di `gagal`, rollback hanya baris itu, dan LKH lain tetap disetujui.
     */
    public function testGagalSebagianDihitungDanTidakMenghentikanYangLain(): void
    {
        $akun   = $this->siapkanAkunSistem();
        $gagal  = $this->barisLkh('2026-10-04', LkhService::MENUNGGU);
        $sukses = $this->barisLkh('2026-10-05', LkhService::MENUNGGU);

        $penyetuju = new class ($this->db, $gagal) extends PenyetujuLkh {
            public function __construct(\CodeIgniter\Database\BaseConnection $db, private readonly int $idGagal)
            {
                parent::__construct($db);
            }

            public function setujui(int $id, string $awal, string $akhir, int $pelaku): string
            {
                if ($id === $this->idGagal) {
                    throw new RuntimeException('galat uji');
                }

                return parent::setujui($id, $awal, $akhir, $pelaku);
            }
        };

        $hasil = (new AutoApprovalService($this->db, $penyetuju))->jalankan('2026-10', false, (int) $akun['id_pengguna']);

        $this->assertSame([2, 1, 1], [$hasil['kandidat'], $hasil['disetujui'], $hasil['gagal']]);
        $this->assertSame(['0', '2'], $this->kolomLkh($gagal, ['status', 'auto_approval']));
        $this->assertSame(['1', '1'], $this->kolomLkh($sukses, ['status', 'auto_approval']));
    }

    public function testKunciSibukDitolak(): void
    {
        $akun = $this->siapkanAkunSistem();
        $this->barisLkh('2026-10-06', LkhService::MENUNGGU);

        $lain  = Database::connect('tests', false);
        $kunci = AutoApprovalService::namaKunci($this->db);
        $lain->query('SELECT GET_LOCK(?, 0)', [$kunci]);

        try {
            $this->expectException(AutoApprovalSibuk::class);

            $this->layanan()->jalankan('2026-10', false, (int) $akun['id_pengguna']);
        } finally {
            $lain->query('SELECT RELEASE_LOCK(?)', [$kunci]);
        }
    }

    public function testRunNyataWajibPelaku(): void
    {
        $this->barisLkh('2026-10-08', LkhService::MENUNGGU);

        $this->expectException(RuntimeException::class);

        $this->layanan()->jalankan('2026-10', false, null);
    }

    /**
     * Ikut legacy: pegawai tidak aktif dan atasan tidak difilter pada job ini.
     */
    public function testPegawaiTidakAktifIkutLegacyTanpaFilter(): void
    {
        $akun = $this->siapkanAkunSistem();
        $this->db->table('pegawai')->where('nip', $this->nip)->update(['status' => 10]);
        $id = $this->barisLkh('2026-10-09', LkhService::MENUNGGU);

        $this->layanan()->jalankan('2026-10', false, (int) $akun['id_pengguna']);

        $this->assertSame(['1', '1'], $this->kolomLkh($id, ['status', 'auto_approval']));
    }

    private function layanan(): AutoApprovalService
    {
        return new AutoApprovalService($this->db);
    }

    /**
     * @param string $tanggal YYYY-MM-DD
     */
    private function barisLkh(string $tanggal, int $status): int
    {
        $this->db->table('riwayat_lckh')->insert([
            'nip'           => $this->nip,
            'tgl_laporan'   => $tanggal,
            'nama'          => 'Pelapor Uji',
            'nip_atasan'    => $this->nipAtasan,
            'nama_atasan'   => 'Atasan Uji',
            'kegiatan'      => '[]',
            'output'        => '[]',
            'status'        => $status,
            'auto_approval' => 2,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * @param list<string> $kolom
     *
     * @return list<string>
     */
    private function kolomLkh(int $id, array $kolom): array
    {
        $baris = $this->db->table('riwayat_lckh')->select(implode(', ', $kolom))->where('id_riwayat_lckh', $id)->get()->getRowArray();

        return array_map(static fn (string $k): string => (string) $baris[$k], $kolom);
    }
}
