<?php

declare(strict_types=1);

namespace Tests\Layanan;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\Ib\IbApprovalService;
use App\Libraries\Layanan\Ib\IbService;
use App\Libraries\Layanan\NotifikasiService;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Pmk\PmkApprovalService;
use App\Libraries\Layanan\Pmk\PmkPengajuanService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Tb\TbApprovalService;
use App\Libraries\Layanan\Tb\TbService;
use Config\Services;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\LayananStudiUjiTrait;

/**
 * CR-149: alur layanan TB / IB / PMK (Fase 4) tidak menulis tabel tiket helpdesk Halo Simpeg (`disiplin_ticket`,
 * `disiplin_ticket_msg`; PRD Fase 8 "tidak ter-mix"). Ajukan → setujui dan ajukan → tolak per layanan; jumlah baris kedua
 * tabel dan jejak audit tiket tidak berubah, sementara notifikasi layanan asli benar-benar menulis `inbox_msg`. Arah sebaliknya: `Tests\Database\HaloSimpeg\TiketTanpaLayananTest`. Data sintetis.
 *
 * @internal
 */
final class LayananTanpaTiketTest extends DatabaseTestCase
{
    use LayananStudiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private AuthContext $picTb;

    private AuthContext $picIb;

    private AuthContext $picPmk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();
        // Layanan notifikasi asli (menulis `inbox`/`inbox_msg`), hanya pengantre push yang tiruan: jalur yang paling rawan
        // tercampur dengan tiket karena keduanya berasal dari inbox legacy (review CR-149 R1).
        Services::injectMock('notifikasiLayanan', new NotifikasiService($this->db, static fn (int $id): bool => true));

        foreach ([PetugasLayananService::JENIS_TB => 'picTb', PetugasLayananService::JENIS_IB => 'picIb', PetugasLayananService::JENIS_PMK => 'picPmk'] as $jenis => $prop) {
            [$nip, $this->{$prop}] = $this->aktorStudi(Role::ADMIN_SATKER, [], $this->buatSatker());
            $this->jadikanPic($nip, $jenis);
        }
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testTbAjukanSetujuiTolakTanpaTiket(): void
    {
        $awal      = $this->jejakTiket();
        $inboxAwal = $this->db->table('inbox_msg')->countAllResults();

        $setuju = $this->ajukanTb();
        (new TbApprovalService())->proses($this->picTb, $setuju, ['aksi' => 'setujui', 'perihal_ds_tb' => 'Izin studi'] + $this->dataSkTb(), $this->berkasPdf('sk.pdf'));
        $tolak = $this->ajukanTb();
        (new TbApprovalService())->proses($this->picTb, $tolak, ['aksi' => 'tolak', 'reason_note' => 'Berkas belum lengkap']);

        $this->assertSame([1, 2], $this->statusLayanan('riwayat_tb', 'id_riwayat_tb', [$setuju, $tolak]), 'alur benar-benar dijalankan');
        $this->assertGreaterThan($inboxAwal, $this->db->table('inbox_msg')->countAllResults(), 'notifikasi TB lewat inbox benar-benar berjalan');
        $this->assertSame($awal, $this->jejakTiket(), 'TB tidak menulis disiplin_ticket(_msg)');
    }

    public function testIbAjukanSetujuiTolakTanpaTiket(): void
    {
        $awal      = $this->jejakTiket();
        $inboxAwal = $this->db->table('inbox_msg')->countAllResults();

        $setuju = $this->ajukanIb();
        (new IbApprovalService())->proses($this->picIb, $setuju, ['aksi' => 'setujui', 'no_sk' => 'SK/IB/149', 'tgl_sk' => '2026-10-21', 'ds_ib' => 'Surat Kampus', 'no_ds_ib' => 'K/149', 'tgl_ds_ib' => '2026-10-01']);
        $tolak = $this->ajukanIb();
        (new IbApprovalService())->proses($this->picIb, $tolak, ['aksi' => 'tolak', 'reason_note' => 'Jadwal bentrok jam kerja']);

        $this->assertSame([1, 2], $this->statusLayanan('riwayat_ib', 'id_riwayat_ib', [$setuju, $tolak]), 'alur benar-benar dijalankan');
        $this->assertGreaterThan($inboxAwal, $this->db->table('inbox_msg')->countAllResults(), 'notifikasi IB lewat inbox benar-benar berjalan');
        $this->assertSame($awal, $this->jejakTiket(), 'IB tidak menulis disiplin_ticket(_msg)');
    }

    public function testPmkAjukanSetujuiTolakTanpaTiket(): void
    {
        $awal      = $this->jejakTiket();
        $inboxAwal = $this->db->table('inbox_msg')->countAllResults();

        $setuju = $this->ajukanPmk();
        (new PmkApprovalService())->proses($this->picPmk, $setuju, ['aksi' => 'setujui']);
        $tolak = $this->ajukanPmk();
        (new PmkApprovalService())->proses($this->picPmk, $tolak, ['aksi' => 'tolak', 'reason_note' => 'Masa kerja tidak sesuai']);

        $this->assertSame([1, 2], $this->statusLayanan('riwayat_pmk_req', 'id_riwayat_pmk_req', [$setuju, $tolak]), 'alur benar-benar dijalankan');
        $this->assertGreaterThan($inboxAwal, $this->db->table('inbox_msg')->countAllResults(), 'notifikasi PMK lewat inbox benar-benar berjalan');
        $this->assertSame($awal, $this->jejakTiket(), 'PMK tidak menulis disiplin_ticket(_msg)');
    }

    /**
     * Jumlah baris tabel tiket helpdesk + jejak audit berentitas tiket.
     *
     * @return array<string, int>
     */
    private function jejakTiket(): array
    {
        return [
            'disiplin_ticket'     => $this->db->table('disiplin_ticket')->countAllResults(),
            'disiplin_ticket_msg' => $this->db->table('disiplin_ticket_msg')->countAllResults(),
            'audit_tiket'         => $this->db->table('audit_logs')->whereIn('entity', ['disiplin_ticket', 'disiplin_ticket_msg'])->countAllResults(),
        ];
    }

    /**
     * @param list<int> $id
     *
     * @return list<int>
     */
    private function statusLayanan(string $tabel, string $pk, array $id): array
    {
        return array_map(fn (int $i): int => (int) $this->db->table($tabel)->where($pk, $i)->get()->getRow('status'), $id);
    }

    private function ajukanTb(): int
    {
        [, $pemohon] = $this->aktorStudi(Role::PEGAWAI);

        return (int) (new TbService())->tambah($pemohon, $this->isianStudi(), $this->berkasSemuaSlot(AturanStudi::JENIS_TB))['id_riwayat_tb'];
    }

    private function ajukanIb(): int
    {
        [, $pemohon] = $this->aktorStudi(Role::PPPK, ['id_jenis_pegawai' => 6]);

        return (int) (new IbService())->tambah($pemohon, $this->isianStudi(), $this->berkasSemuaSlot(AturanStudi::JENIS_IB))['id_riwayat_ib'];
    }

    private function ajukanPmk(): int
    {
        [, $pemohon] = $this->aktorStudi(Role::PEGAWAI);
        $berkas      = [];

        foreach (array_keys(PmkPengajuanService::BERKAS) as $kolom) {
            $berkas[$kolom] = $this->berkasPdf("{$kolom}.pdf");
        }

        return (int) (new PmkPengajuanService())->tambah($pemohon, ['jenis_pmk' => 'Negeri', 'instansi' => 'Pemda Uji', 'tgl_awal' => '2010-01-01', 'tgl_akhir' => '2012-12-31', 'mker_th' => '2', 'mker_bl' => '11'], $berkas)['id_riwayat_pmk_req'];
    }
}
