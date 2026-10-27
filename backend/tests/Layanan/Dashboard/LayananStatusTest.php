<?php

declare(strict_types=1);

namespace Tests\Layanan\Dashboard;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\LayananDashboardService;
use App\Libraries\Layanan\PetugasLayananService;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\DashboardUjiTrait;
use Tests\Support\Layanan\LayananStudiUjiTrait;
use Tests\Support\Layanan\SisipMinimalTrait;

/**
 * `GET layanan/status` (PRD Fase 4 §6.10.1, AC-10.3, AC-10.4): gabungan keempat jenis pengajuan pegawai, tahap & label
 * sama dengan penyaji modul, petugas (AL/PyB cuti; PIC aktif TB/IB/PMK), filter, urutan, dan paginasi.
 *
 * @internal
 */
final class LayananStatusTest extends DatabaseTestCase
{
    use DashboardUjiTrait;
    use LayananStudiUjiTrait;
    use SisipMinimalTrait;

    protected $seed = MasterDataSeeder::class;

    private string $nip;

    private AuthContext $pegawai;

    /** @var array<string, int> */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLayananStudi();

        [$this->nip, $this->pegawai] = $this->aktorStudi(Role::PEGAWAI);

        $this->id = [
            'cuti' => $this->sisipCuti($this->nip, [
                'nip_atasan_langsung' => '197001012000011001', 'nama_atasan_langsung' => 'Atasan Uji',
                'nip_yang_menyetujui' => '196501011990011001', 'nama_yang_menyetujui' => 'Pejabat Uji',
            ]),
            'tb'  => $this->sisipStudi('tb', $this->nip),
            'ib'  => $this->sisipStudi('ib', $this->nip, ['status' => 1, 'created_at' => '2026-10-23 01:00:00', 'notif_date' => '2026-10-24 01:00:00']),
            'pmk' => $this->sisipPmk($this->nip, ['status' => 3, 'approved_at' => '2026-10-25 01:00:00']),
        ];
    }

    protected function tearDown(): void
    {
        $this->bersihkanLayananStudi();
        parent::tearDown();
    }

    public function testPegawaiMelihatKeempatJenisUrutTerbaru(): void
    {
        $hasil = $this->dasbor($this->pegawai);

        $this->assertSame(4, $hasil['total']);
        $this->assertSame(['ib', 'pmk', 'tb', 'cuti'], array_column($hasil['items'], 'jenis'), 'urut created_at DESC');
        $this->assertSame(['cuti', 'tb', 'ib', 'pmk'], $hasil['jenis']);

        foreach ($hasil['items'] as $item) {
            $this->assertSame($this->id[$item['jenis']], $item['id']);
            $this->assertSame($this->nip, $item['nip']);
            $this->assertTrue($item['aksi']['lihat_detail']);
            $this->assertNotNull($item['url_detail']);
        }

        $this->assertSame('/layanan/pmk/pengajuan/' . $this->id['pmk'], $this->item($hasil, 'pmk')['url_detail']);
    }

    public function testTahapDanLabelKonsistenDenganStateMachineModul(): void
    {
        $hasil = $this->dasbor($this->pegawai);

        $cuti = $this->item($hasil, 'cuti');
        $this->assertSame(['diajukan', 'atasan', 'pejabat', 'selesai'], array_column($cuti['tahap'], 'kode'));
        $this->assertSame(['done', 'current', 'todo', 'todo'], array_column($cuti['tahap'], 'state'));
        $this->assertSame('Menunggu Atasan', $cuti['label_status']);
        $this->assertSame('Cuti Tahunan 02-11-2026 s/d 05-11-2026', $cuti['ringkasan']);
        $this->assertSame('2026-10-20 01:00:00', $cuti['tahap'][0]['waktu']);

        $tb = $this->item($hasil, 'tb');
        $this->assertSame(['diajukan', 'pic', 'selesai'], array_column($tb['tahap'], 'kode'));
        $this->assertSame(['done', 'current', 'todo'], array_column($tb['tahap'], 'state'));
        $this->assertSame('Menunggu PIC', $tb['label_status']);
        $this->assertSame('Tugas Belajar S.1 - Universitas Uji (Manajemen), 01-01-2027 s/d 31-12-2028', $tb['ringkasan']);

        $ib = $this->item($hasil, 'ib');
        $this->assertSame(['done', 'done', 'done'], array_column($ib['tahap'], 'state'));
        $this->assertSame('Disetujui', $ib['label_status']);
        $this->assertSame('success', $ib['nada_status']);
        $this->assertSame('2026-10-24 01:00:00', $ib['tahap'][2]['waktu']);

        $pmk = $this->item($hasil, 'pmk');
        $this->assertSame(['done', 'current', 'todo'], array_column($pmk['tahap'], 'state'));
        $this->assertSame('Diproses', $pmk['label_status']);
        $this->assertSame('Swasta - PT Uji, 01-01-2010 s/d 31-12-2012', $pmk['ringkasan']);
    }

    public function testPetugasCutiAlPybDanPicAktifSaja(): void
    {
        [$picTb]  = $this->aktorStudi(Role::PEGAWAI);
        [$picNon] = $this->aktorStudi(Role::PEGAWAI);
        [$picIb]  = $this->aktorStudi(Role::PEGAWAI);
        $this->jadikanPic($picTb, PetugasLayananService::JENIS_TB);
        $this->jadikanPic($picNon, PetugasLayananService::JENIS_TB, 0);
        $this->jadikanPic($picIb, PetugasLayananService::JENIS_IB);

        $hasil = $this->dasbor($this->pegawai);

        $this->assertSame([
            ['peran' => 'Atasan Langsung', 'nip' => '197001012000011001', 'nama' => 'Atasan Uji'],
            ['peran' => 'Pejabat Berwenang', 'nip' => '196501011990011001', 'nama' => 'Pejabat Uji'],
        ], $this->item($hasil, 'cuti')['petugas']);

        $tb = $this->item($hasil, 'tb');
        $this->assertSame([$picTb], array_column($tb['petugas'], 'nip'), 'PIC nonaktif tidak tampil');
        $this->assertSame('PIC', $tb['petugas'][0]['peran']);
        $this->assertNotNull($tb['petugas'][0]['nama']);
        $this->assertNull($tb['catatan']);

        $this->assertSame([$picIb], array_column($this->item($hasil, 'ib')['petugas'], 'nip'));

        $pmk = $this->item($hasil, 'pmk');
        $this->assertSame([], $pmk['petugas']);
        $this->assertSame(LayananDashboardService::CATATAN_TANPA_PIC, $pmk['catatan']);
    }

    public function testCutiLangkahTunggalHanyaPejabatBerwenang(): void
    {
        $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $this->id['cuti'])
            ->update(['nip_atasan_langsung' => null, 'nama_atasan_langsung' => null, 'status' => 1, 'status_pyb' => 1]);

        $cuti = $this->item($this->dasbor($this->pegawai), 'cuti');

        $this->assertSame(['Pejabat Berwenang'], array_column($cuti['petugas'], 'peran'));
        $this->assertSame(['diajukan', 'pejabat', 'selesai'], array_column($cuti['tahap'], 'kode'));
        $this->assertSame('Disetujui', $cuti['label_status']);
    }

    public function testFilterJenisStatusDanPencarian(): void
    {
        $this->assertSame(['tb'], array_column($this->dasbor($this->pegawai, ['jenis' => 'tb'])['items'], 'jenis'));
        $this->assertSame(['ib'], array_column($this->dasbor($this->pegawai, ['status' => 'selesai'])['items'], 'jenis'));
        $this->assertEqualsCanonicalizing(['pmk', 'tb', 'cuti'], array_column($this->dasbor($this->pegawai, ['status' => 'berjalan'])['items'], 'jenis'));
        $this->assertEqualsCanonicalizing(['tb', 'cuti'], array_column($this->dasbor($this->pegawai, ['status' => '0'])['items'], 'jenis'));
        $this->assertSame(4, $this->dasbor($this->pegawai, ['search' => substr($this->nip, -6)])['total']);
        $this->assertSame(0, $this->dasbor($this->pegawai, ['search' => 'tidak-ada-%'])['total']);
    }

    public function testFilterSatkerDanPaginasi(): void
    {
        [, $admin] = $this->aktorStudi(Role::SUPER_ADMIN);
        $satker    = $this->buatSatker();
        $lain      = $this->buatPegawaiDiSatker($satker, ['id_jenis_pegawai' => 1]);
        $this->sisipCuti($lain, ['created_at' => '2026-10-26 01:00:00']);

        $this->assertSame([$lain], array_column($this->dasbor($admin, ['id_satker' => (string) $satker])['items'], 'nip'));

        $hal = $this->dasbor($admin, ['per_page' => '2', 'page' => '2']);
        $this->assertSame(5, $hal['total']);
        $this->assertSame(2, $hal['per_page']);
        $this->assertSame(['pmk', 'tb'], array_column($hal['items'], 'jenis'));
    }

    public function testParameterTidakSah422(): void
    {
        foreach ([['jenis' => 'karpeg'], ['status' => 'macet'], ['id_unit' => 'x'], ['search' => ['a']]] as $query) {
            try {
                $this->dasbor($this->pegawai, $query);
                $this->fail('Seharusnya 422: ' . json_encode($query));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey((string) array_key_first($query), $e->getErrors());
            }
        }
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function dasbor(AuthContext $auth, array $query = []): array
    {
        return (new LayananDashboardService())->status($auth, $query);
    }

    /**
     * @param array<string, mixed> $hasil
     *
     * @return array<string, mixed>
     */
    private function item(array $hasil, string $jenis): array
    {
        foreach ($hasil['items'] as $item) {
            if ($item['jenis'] === $jenis) {
                return $item;
            }
        }

        $this->fail("Item {$jenis} tidak ada");
    }
}
