<?php

declare(strict_types=1);

namespace Tests\Presensi\Rekap;

use App\Constants\Role;
use App\Libraries\Presensi\Slip\TarifUangMakan;
use App\Libraries\Presensi\Slip\UangMakanSlipPresensi;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\SlipUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.4.2 AC-D09b.1–.4 (MAKE-042): rekap uang makan = aturan hari slip (F5B-2) — satu test per baris [CATAT] F5B-2
 * (kategori 13 dibayar, TB + penanda melekat, perpanjangan TB, pegawai baru dalam bulan); bentuk `{nip, tgl_absen}` &
 * XLSX `NIP | TGL_ABSEN` tanpa nominal (F5B-20); jumlah baris = `uang_makan_hari` snapshot slip periode M+1; lingkup role
 * 3; varian IL (`jenis_jabatan = 2`). September 2026 (1 Sep = Selasa).
 *
 * @internal
 */
final class RekapUangMakanTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use SlipUjiTrait;
    use TukinDataUjiTrait;

    private int $satker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->siapkanJenisPegawai();
        $this->satker = $this->buatSatker($this->buatUnit());
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    public function testKonketKategori13DibayarKategoriLainTidak(): void
    {
        $n = $this->pegawaiRekap($this->satker);
        $this->konketRekap($n, '2026-09-01', 13);
        $this->konketRekap($n, '2026-09-02', 8);
        $this->hadir($n, '2026-09-02');
        $this->hadir($n, '2026-09-03');

        $this->assertSame(['2026-09-01', '2026-09-03'], $this->tanggal($n));
    }

    public function testTbPenandaMelekatSampaiAkhirBulan(): void
    {
        $n = $this->pegawaiRekap($this->satker);
        $this->tbRekap($n, '2025-09-01', '2026-09-02');

        foreach (['2026-09-01', '2026-09-03', '2026-09-28'] as $t) {
            $this->hadir($n, $t);
        }

        $this->assertSame([], $this->tanggal($n), 'potongan 25 → hari setelah TB berakhir tidak dibayar');
    }

    public function testPerpanjanganTbTerbacaDanTbMulaiTengahBulan(): void
    {
        $n  = $this->pegawaiRekap($this->satker);
        $id = $this->tbRekap($n, '2026-09-03', '2026-09-03');
        $this->db->table('riwayat_tb_perpan')->insert(['id_riwayat_tb' => $id, 'tgl_mulai_perpan' => '2026-09-28', 'tgl_akhir_perpan' => '2026-09-28', 'file_sperpan' => 'uji.pdf']);

        foreach (['2026-09-01', '2026-09-03', '2026-09-28', '2026-09-29'] as $t) {
            $this->hadir($n, $t);
        }

        $this->assertSame(['2026-09-01', '2026-09-29'], $this->tanggal($n));
    }

    public function testPegawaiBaruDalamBulanDihitung(): void
    {
        $n = $this->pegawaiRekap($this->satker, ['created_at' => '2026-09-15 08:00:00']);
        $this->hadir($n, '2026-09-16');

        $this->assertSame(['2026-09-16'], $this->tanggal($n), 'legacy :2914 created_at < awal bulan → 0 hari');
    }

    public function testBentukTanpaNominalDanSamaDenganSnapshotSlip(): void
    {
        $this->siapkanSlip();
        $this->aturWebConfig(['uang_makan_gol_3' => '37000']);
        $n = $this->pegawaiRekap($this->satker);
        $this->db->table('riwayat_kp')->insert(['nip' => $n, 'tmtsk' => '2020-01-01', 'gol' => 'III', 'gol_ruang' => 'III/a', 'status' => 1]);

        foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-08'] as $t) {
            $this->hadir($n, $t);
        }

        $this->konketRekap($n, '2026-09-09', 13);
        $this->cutiRekap($n, '2026-09-08', '2026-09-08');

        $data = $this->serviceUangMakan()->daftar($this->adminRekap(), ['bulan' => '2026-09']);
        $this->assertSame([['nip' => $n, 'tgl_absen' => '2026-09-01'], ['nip' => $n, 'tgl_absen' => '2026-09-02'], ['nip' => $n, 'tgl_absen' => '2026-09-03'], ['nip' => $n, 'tgl_absen' => '2026-09-09']], $data['items']);

        $id     = $this->slip($n, 2026, 10);
        $sumber = new UangMakanSlipPresensi($this->db, new TarifUangMakan($this->db, service('webConfigService')), null, self::jamWib('2026-10-27 09:00:00'));
        $this->slipPegawai('2026-10-27 09:00:00', $sumber)->buka($this->authPegawaiSlip($n), '2026-10', '203.0.113.1');
        $this->seeInDatabase('gaji_pegawai', ['id' => $id, 'uang_makan_hari' => $data['total'], 'uang_makan_periode' => '9-2026']);
        $this->bersihkanSlip();
    }

    public function testLingkupRole3DanUrutanPopulasi(): void
    {
        $lain = $this->buatSatker($this->buatUnit());
        $a    = $this->pegawaiRekap($this->satker);
        $b    = $this->pegawaiRekap($lain);
        $this->hadir($a, '2026-09-01');
        $this->hadir($b, '2026-09-01');
        $this->hadir($b, '2026-09-02');

        $r3 = $this->serviceUangMakan()->daftar($this->adminRekap(Role::ADMIN_SATKER, (string) $this->satker), ['bulan' => '2026-09', 'unit_satker' => '0']);
        $this->assertSame([$a], array_values(array_unique(array_column($r3['items'], 'nip'))));
        $this->assertSame(3, $this->serviceUangMakan()->daftar($this->adminRekap(), ['bulan' => '2026-09'])['total']);

        $hal = $this->serviceUangMakan()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $lain, 'per_page' => '1', 'page' => '2']);
        $this->assertSame([2, [['nip' => $b, 'tgl_absen' => '2026-09-02']]], [$hal['total'], $hal['items']]);
    }

    public function testVarianIlDanXlsx(): void
    {
        $dh = $this->pegawaiRekap($this->satker, ['nip' => '199001012020121002']);
        $il = $this->buatPegawai(['created_at' => '2020-01-01 00:00:00', 'id_jenis_pegawai' => 2, 'nip' => '199001012020121003'], ['jenis_jabatan' => 2]);
        $this->buatRiwayatMutasiJabatan($il, ['jenis_jabatan' => 2]);
        $this->hadir($dh, '2026-09-01');
        $this->hadir($il, '2026-09-02');

        $ilData = $this->serviceUangMakan()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'jenis_pegawai' => ['99']], true);
        $this->assertSame([['nip' => $il, 'tgl_absen' => '2026-09-02']], $ilData['items'], 'IL: tanpa filter jenis & lingkup');

        $berkas = $this->serviceUangMakan()->xlsx($this->adminRekap(), ['bulan' => '2026-09']);
        $this->assertSame('rekap_uang_makan_0_2026-09.xlsx', $berkas['nama']);
        $this->assertSame([['NIP', 'TGL_ABSEN'], ['199001012020121002', '2026-09-01']], $this->baca($berkas['isi']), 'dua kolom, tanpa nominal');

        $this->assertSame('rekap_uang_makan_il_2026-09.xlsx', $this->serviceUangMakan()->xlsx($this->adminRekap(), ['bulan' => '2026-09'], true)['nama']);
    }

    /**
     * @return list<string>
     */
    private function tanggal(string $nip): array
    {
        $data = $this->serviceUangMakan()->daftar($this->adminRekap(), ['bulan' => '2026-09', 'unit_satker' => 'sat_' . $this->satker, 'per_page' => '100']);

        return array_column(array_filter($data['items'], static fn (array $b): bool => $b['nip'] === $nip), 'tgl_absen');
    }

    /**
     * @return list<list<mixed>>
     */
    private function baca(string $isi): array
    {
        $berkas = tempnam(sys_get_temp_dir(), 'rum');
        file_put_contents((string) $berkas, $isi);

        try {
            return IOFactory::load((string) $berkas)->getActiveSheet()->toArray(null, false, false, false);
        } finally {
            @unlink((string) $berkas);
        }
    }
}
