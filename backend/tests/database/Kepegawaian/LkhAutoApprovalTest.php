<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use App\Libraries\Integrasi\AkunSistemTidakSiap;
use App\Libraries\Kepegawaian\Lkh\LkhAutoApproval;
use App\Libraries\Kepegawaian\Lkh\LkhService;
use InvalidArgumentException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\AkunSistemUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * H-12 / MAKE-068 (F6-25, CR-093): auto-approval LKH tanggal 5 ikut legacy. Hanya LKH bulan lalu berstatus 0 yang
 * disetujui (`status=1`, `auto_approval=1`) oleh akun sistem integrasi; status 3 dan bulan lain tidak tersentuh; ulang =
 * tanpa efek ganda; tidak ada PDF yang disimpan dan tidak ada tabel `aa_lkh`; dry-run tidak menulis apa pun.
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

    public function testHanyaStatusMenungguBulanLaluYangDisetujui(): void
    {
        $akun = $this->siapkanAkunSistem();

        $menunggu1 = $this->barisLkh('2026-10-01', LkhService::MENUNGGU);
        $menunggu2 = $this->barisLkh('2026-10-31', LkhService::MENUNGGU);
        $revisi    = $this->barisLkh('2026-10-15', LkhService::REVISI);
        $sudahOk   = $this->barisLkh('2026-10-20', LkhService::DISETUJUI);
        $bulanIni  = $this->barisLkh('2026-11-02', LkhService::MENUNGGU);
        $duaBulan  = $this->barisLkh('2026-09-30', LkhService::MENUNGGU);

        $hasil = (new LkhAutoApproval($this->db))->jalankan('2026-10');

        $this->assertSame(['bulan' => '2026-10', 'kandidat' => 2, 'disetujui' => 2, 'dry_run' => false], $hasil);

        foreach ([$menunggu1, $menunggu2] as $id) {
            $this->assertSame(['1', '1', (string) $akun['id_pengguna'], (string) $akun['id_pengguna']], $this->kolomLkh($id, ['status', 'auto_approval', 'approved_by', 'updated_by']));
        }

        $this->assertSame(['3', '2'], $this->kolomLkh($revisi, ['status', 'auto_approval']));
        $this->assertSame(['1', '2'], $this->kolomLkh($sudahOk, ['status', 'auto_approval']));
        $this->assertSame(['0', '2'], $this->kolomLkh($bulanIni, ['status', 'auto_approval']));
        $this->assertSame(['0', '2'], $this->kolomLkh($duaBulan, ['status', 'auto_approval']));
    }

    public function testUlangTanpaEfekGandaDanTercatatDiAudit(): void
    {
        $akun    = $this->siapkanAkunSistem();
        $id      = $this->barisLkh('2026-10-10', LkhService::MENUNGGU);
        $layanan = new LkhAutoApproval($this->db);

        $layanan->jalankan('2026-10');
        $audit = count($this->auditUji('riwayat_lckh', (string) $id));
        $ulang = $layanan->jalankan('2026-10');

        $this->assertSame(['kandidat' => 0, 'disetujui' => 0], ['kandidat' => $ulang['kandidat'], 'disetujui' => $ulang['disetujui']]);
        $this->assertSame($audit, count($this->auditUji('riwayat_lckh', (string) $id)), 'ulang tidak menambah audit');
        $this->assertGreaterThan(0, $audit);
        $this->assertSame((string) $akun['id_pengguna'], (string) $this->auditUji('riwayat_lckh', (string) $id)[0]['id_pengguna_actor']);
    }

    public function testTanpaPdfDanTanpaTabelAaLkh(): void
    {
        $this->siapkanAkunSistem();
        $id = $this->barisLkh('2026-10-12', LkhService::MENUNGGU);

        (new LkhAutoApproval($this->db))->jalankan('2026-10');

        $this->assertNull($this->db->table('riwayat_lckh')->where('id_riwayat_lckh', $id)->get()->getRowArray()['file_lckh']);
        $this->assertFalse($this->db->tableExists('aa_lkh'));
    }

    public function testDryRunTidakMenulis(): void
    {
        $this->siapkanAkunSistem();
        $id = $this->barisLkh('2026-10-03', LkhService::MENUNGGU);

        $hasil = (new LkhAutoApproval($this->db))->jalankan('2026-10', true);

        $this->assertSame(['kandidat' => 1, 'disetujui' => 0, 'dry_run' => true], ['kandidat' => $hasil['kandidat'], 'disetujui' => $hasil['disetujui'], 'dry_run' => $hasil['dry_run']]);
        $this->assertSame(['0', '2'], $this->kolomLkh($id, ['status', 'auto_approval']));
    }

    public function testAkunSistemBelumSiapMenggagalkanTanpaMenyentuhData(): void
    {
        $id = $this->barisLkh('2026-10-07', LkhService::MENUNGGU);

        $this->expectException(AkunSistemTidakSiap::class);

        try {
            (new LkhAutoApproval($this->db))->jalankan('2026-10');
        } finally {
            $this->assertSame(['0', '2'], $this->kolomLkh($id, ['status', 'auto_approval']));
        }
    }

    public function testBulanTidakValidDitolak(): void
    {
        $this->siapkanAkunSistem();

        $this->expectException(InvalidArgumentException::class);

        (new LkhAutoApproval($this->db))->jalankan('2026-13');
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
