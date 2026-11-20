<?php

declare(strict_types=1);

namespace Tests\Paritas\Step3\Struktur;

use App\Libraries\Kepegawaian\Struktur\StrukturService;
use CodeIgniter\I18n\Time;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * CR-164 pagar paritas step 3 — test KARAKTERISASI pejabat & golongan bagan {@see StrukturService::pohon()} (format
 * kementerian): riwayat jabatan berlapis per NIP, Plt/Plh, status 2 dengan tmt_status, bulan berjalan vs lampau, dan
 * golongan "gol_ruang pertama yang tidak kosong sesudah trim". Hari ini dikunci 2026-10-13 WIB.
 *
 * Catatan: tmtsk KEMBAR pada riwayat jabatan satu NIP TIDAK dikunci — muatPejabat() hanya `ORDER BY nip, tmtsk DESC`
 * tanpa pemutus seri (StrukturService.php:477), jadi baris yang menang tidak tentu. Begitu pula golongan dengan tmtsk
 * kembar (StrukturService.php:537).
 *
 * @internal
 */
final class StrukturPejabatParitasTest extends DatabaseTestCase
{
    use RiwayatUjiTrait;

    private const HARI_INI = '2026-10-13';

    private int $idPeriode;
    private int $unitSek;
    private int $satSek;

    /**
     * @var array<string, int>
     */
    private array $jab = [];

    protected function setUp(): void
    {
        parent::setUp();
        Time::setTestNow(self::HARI_INI . ' 10:00:00', 'Asia/Jakarta');

        $this->pastikan('group_jabatan', ['id_group_jabatan' => 1, 'group_jabatan' => 'Struktural', 'status' => 1]);
        $this->pastikan('jenis_status', ['id_jenis_status' => 1, 'jenis_status' => 'Aktif Bekerja', 'status_pegawai' => 1]);
        $this->pastikan('sub_group_jabatan', ['id_sub_group_jabatan' => 1, 'id_group_jabatan' => 1, 'sub_group_jabatan' => 'Eselon I', 'need_satker' => 1, 'status' => 1]);

        $unit          = $this->buatUnit(['unit' => 'Sekretariat Uji']);
        $satker        = $this->buatSatker($unit, ['satker' => 'Biro Uji']);
        $this->unitSek = $unit;
        $this->satSek  = $satker;
        $this->db->table('periode_struktur_jabatan')->insert(['periode_struktur_jabatan' => 'Periode Uji', 'status' => 1]);
        $this->idPeriode = (int) $this->db->insertID();

        foreach (['sekretaris' => 'Sekretaris Kementerian', 'staf_ahli' => 'Staf Ahli Uji', 'deputi' => 'Deputi Uji'] as $kunci => $nama) {
            $this->db->table('jabatan')->insert(['id_group_jabatan' => 1, 'id_sub_group_jabatan' => 1, 'id_satker' => $satker, 'jabatan' => $nama, 'status' => 1]);
            $this->jab[$kunci] = (int) $this->db->insertID();
            $this->db->table('struktur_jabatan')->insert([
                'id_periode_struktur_jabatan' => $this->idPeriode, 'id_jabatan' => $this->jab[$kunci], 'id_jabatan_atasan' => null,
                'periode_struktur_jabatan'    => 'Periode Uji', 'jabatan' => $nama, 'level_struktur' => 2, 'urutan' => 1, 'status' => 1,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testRiwayatJabatanBerlapisBarisTmtTerbaruPerNipMenang(): void
    {
        $nip = $this->pegawai(['nama' => 'Berlapis']);
        $this->rmj($nip, 'deputi', '2021-01-01');
        $this->rmj($nip, 'sekretaris', '2024-01-01');
        $this->rmj($nip, 'staf_ahli', '2026-10-14'); // sesudah hari ini → diabaikan
        $this->rmj($nip, 'staf_ahli', '2025-01-01', ['status' => 2]); // riwayat status 2 → diabaikan

        $d = $this->data();

        $this->assertSame(["Berlapis, {$nip}", '', ''], [$d['sekretaris']['pejabat'], $d['deputi'][0]['pejabat'], $d['staf_ahli'][0]['pejabat']]);
    }

    public function testBarisTerbaruNonStrukturalMengosongkanJabatanLama(): void
    {
        $this->pastikan('group_jabatan', ['id_group_jabatan' => 3, 'group_jabatan' => 'Fungsional Uji', 'status' => 1]);
        $nip = $this->pegawai(['nama' => 'Pindah Fungsional']);
        $this->rmj($nip, 'sekretaris', '2021-01-01');
        $this->rmj($nip, 'sekretaris', '2024-01-01', ['id_group_jabatan' => 3]);

        // perilaku sekarang (dikunci CR-164): baris terbaru NIP itu bukan group 1/5 (kunci '') tetap "memakai" NIP
        // (StrukturService.php:486-494), jadi baris struktural lama tidak dipertimbangkan → jabatan lowong.
        $this->assertSame('', $this->data()['sekretaris']['pejabat']);
    }

    public function testDuaNipKunciSamaNipTerakhirMenang(): void
    {
        $kecil = $this->pegawai(['nama' => 'Pertama']);
        $besar = $this->pegawai(['nama' => 'Kedua']);
        $this->rmj($besar, 'sekretaris', '2021-01-01');
        $this->rmj($kecil, 'sekretaris', '2025-01-01');

        $this->assertLessThan(0, strcmp($kecil, $besar));
        // perilaku sekarang (dikunci CR-164): kunci sama → NIP terakhir urut NIP menang, bukan TMT terbaru (StrukturService.php:498).
        $this->assertSame("Kedua, {$besar}", $this->data()['sekretaris']['pejabat']);
    }

    public function testStatusPegawaiDanTmtStatusTerhadapTanggalAcuan(): void
    {
        $this->pastikan('jenis_status', ['id_jenis_status' => 5, 'jenis_status' => 'Status Lain Uji', 'status_pegawai' => 1]);
        $this->rmj($this->pegawai(['nama' => 'Keluar Besok', 'status' => 2, 'tmt_status' => '2026-10-14']), 'sekretaris', '2021-01-01');
        $this->rmj($this->pegawai(['nama' => 'Keluar Hari Ini', 'status' => 2, 'tmt_status' => self::HARI_INI]), 'deputi', '2021-01-01');
        $this->rmj($this->pegawai(['nama' => 'Jenis Status Lain', 'id_jenis_status' => 5]), 'staf_ahli', '2021-01-01');

        $d = $this->data();

        $this->assertStringStartsWith('Keluar Besok, ', $d['sekretaris']['pejabat'], 'status 2, tmt_status > tanggal → masih pejabat');
        $this->assertSame('', $d['deputi'][0]['pejabat'], 'tmt_status = tanggal → tidak (ketat)');
        $this->assertSame('', $d['staf_ahli'][0]['pejabat'], 'status 1 dengan id_jenis_status di luar 1/12 → tidak');
    }

    public function testBulanBerjalanHariIniVsBulanLampauAkhirBulan(): void
    {
        $this->rmj($this->pegawai(['nama' => 'Dilantik Hari Ini']), 'sekretaris', self::HARI_INI);
        $this->rmj($this->pegawai(['nama' => 'Dilantik Akhir September']), 'deputi', '2026-09-30');
        $this->rmj($this->pegawai(['nama' => 'Dilantik Besok']), 'staf_ahli', '2026-10-14');

        $kini = (new StrukturService())->pohon($this->admin());
        $sep  = (new StrukturService())->pohon($this->admin(), ['bulan' => '2026-09']);

        $this->assertSame([self::HARI_INI, '2026-09-30'], [$kini['tanggal'], $sep['tanggal']]);
        $this->assertStringStartsWith('Dilantik Hari Ini', $kini['data']['sekretaris']['pejabat']);
        $this->assertStringStartsWith('Dilantik Akhir September', $kini['data']['deputi'][0]['pejabat']);
        $this->assertSame('', $kini['data']['staf_ahli'][0]['pejabat']);
        $this->assertSame('', $sep['data']['sekretaris']['pejabat'], 'September: acuan 30-09');
        $this->assertStringStartsWith('Dilantik Akhir September', $sep['data']['deputi'][0]['pejabat']);
    }

    public function testPltPlhUrutanDanBatasTanggal(): void
    {
        $def = $this->pegawai(['nama' => 'Definitif']);
        $this->rmj($def, 'sekretaris', '2021-01-01');
        $this->tugas('plt', $this->pegawai(['nama' => 'Plt Diabaikan']), 'sekretaris', '2026-10-01', '2026-10-31');

        $plt1 = $this->pegawai(['nama' => 'Plt Pertama']);
        $this->tugas('plt', $plt1, 'deputi', '2026-10-01', self::HARI_INI); // tgl_akhir = hari ini ikut
        $this->tugas('plt', $this->pegawai(['nama' => 'Plt Kedua']), 'deputi', '2026-10-01', '2026-10-31');
        $this->tugas('plh', $this->pegawai(['nama' => 'Plh Deputi']), 'deputi', '2026-10-01', '2026-10-31');

        $this->tugas('plt', $this->pegawai(['nama' => 'Plt Mulai Besok']), 'staf_ahli', '2026-10-14', '2026-10-31');
        $plh = $this->pegawai(['nama' => 'Plh Staf']);
        $this->tugas('plh', $plh, 'staf_ahli', self::HARI_INI, '2026-10-31');
        $this->kp($plh, 'III/d', '2020-01-01');

        $d = $this->data();

        $this->assertSame(["Definitif, {$def}", 'definitif', false], [$d['sekretaris']['pejabat'], $d['sekretaris']['jenis'], $d['sekretaris']['is_plt']]);
        $this->assertSame(["Plt. Plt Pertama, {$plt1}", 'plt', true], [$d['deputi'][0]['pejabat'], $d['deputi'][0]['jenis'], $d['deputi'][0]['is_plt']], 'id terkecil menang; Plt sebelum Plh');
        $this->assertSame(["Plh. Plh Staf, III/d, {$plh}", 'plh', true], [$d['staf_ahli'][0]['pejabat'], $d['staf_ahli'][0]['jenis'], $d['staf_ahli'][0]['is_plt']]);
    }

    public function testGolonganPertamaTidakKosongSesudahTrim(): void
    {
        $nip = $this->pegawai(['nama' => 'Gol']);
        $this->rmj($nip, 'sekretaris', '2021-01-01');
        $this->kp($nip, 'IV/b', '2026-10-14');            // sesudah tanggal acuan
        $this->kp($nip, 'IV/a', '2026-06-01', 2);         // status 2
        $this->kp($nip, '   ', '2026-01-01');             // terbaru tapi kosong sesudah trim
        $this->kp($nip, '  III/d  ', '2024-01-01');
        $this->kp($nip, 'III/c', '2022-01-01');

        // perilaku sekarang (dikunci CR-164): gol terbaru yang kosong dilewati ke baris lebih lama (StrukturService.php:540-543),
        // dan nilai dipangkas spasinya.
        $this->assertSame("Gol, III/d, {$nip}", $this->data()['sekretaris']['pejabat']);

        $tanpa = $this->pegawai(['nama' => 'Tanpa Gol']);
        $this->rmj($tanpa, 'deputi', '2021-01-01');
        $this->kp($tanpa, '', '2020-01-01');
        $this->assertSame("Tanpa Gol, {$tanpa}", $this->data()['deputi'][0]['pejabat'], 'semua kosong → tanpa bagian gol');
    }

    /**
     * R1 (StrukturService.php:220-237): list_es2 per es1 = level 3, atasan = es1 itu, satker di unit es1; satker 104
     * dibuang hanya di bawah sekretaris; urut `urutan` lalu id_struktur_jabatan.
     */
    public function testListEs2PerEs1IsiDanUrutan(): void
    {
        $unitDep = $this->buatUnit(['unit' => 'Deputi Uji']);
        $satDep  = $this->buatSatker($unitDep, ['satker' => 'Direktorat Uji']);
        $satSek2 = $this->buatSatker($this->unitSek, ['satker' => 'Biro Dua Uji']);
        $this->pastikan('satker', ['id_satker' => StrukturService::SATKER_INSPEKTORAT, 'id_unit' => $this->unitSek, 'satker' => 'Inspektorat Uji', 'status' => 1]);
        $sat104 = StrukturService::SATKER_INSPEKTORAT;
        $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => 1, 'sub_group_jabatan' => 'Eselon II Uji', 'need_satker' => 1, 'status' => 1]);
        $es2Sub = (int) $this->db->insertID();

        // Deputi A (unit Deputi) — jab 'deputi' dari setUp ada di unit Sekretariat; pindahkan ke satker unit Deputi.
        $this->db->table('jabatan')->where('id_jabatan', $this->jab['deputi'])->update(['id_satker' => $satDep]);
        // Deputi B di unit Sekretariat (agar satker 104 sah di bawahnya).
        $this->jabatanStruktur('deputi_b', 'Deputi B Uji', $satSek2, 1, 2, null, 2);

        // Di bawah sekretaris: urutan 2 disisip dulu, lalu urutan 1; satker 104 dibuang; satker unit lain dibuang.
        $this->jabatanStruktur('sek_u2', 'Kepala Biro Urutan Dua', $this->satSek, $es2Sub, 3, 'sekretaris', 2);
        $this->jabatanStruktur('sek_u1', 'Kepala Biro Urutan Satu', $satSek2, $es2Sub, 3, 'sekretaris', 1);
        $this->jabatanStruktur('sek_104', 'Inspektur Di Sekretaris', $sat104, $es2Sub, 3, 'sekretaris', 1);
        $this->jabatanStruktur('sek_lain', 'Direktur Unit Lain', $satDep, $es2Sub, 3, 'sekretaris', 1);
        // Di bawah deputi A: urutan terbalik; satu dengan atasan cocok tapi satker di unit Sekretariat → dibuang.
        $this->jabatanStruktur('depa_u2', 'Direktur A Dua', $satDep, $es2Sub, 3, 'deputi', 2);
        $this->jabatanStruktur('depa_u1', 'Direktur A Satu', $satDep, $es2Sub, 3, 'deputi', 1);
        $this->jabatanStruktur('depa_lain', 'Direktur A Unit Lain', $this->satSek, $es2Sub, 3, 'deputi', 1);
        // Di bawah deputi B: satker 104 TETAP ikut (saringan 104 hanya untuk sekretaris).
        $this->jabatanStruktur('depb_104', 'Inspektur Di Deputi B', $sat104, $es2Sub, 3, 'deputi_b', 1);
        $this->jabatanStruktur('depb_u0', 'Kepala Biro B Nol', $satSek2, $es2Sub, 3, 'deputi_b', 0);

        $d = $this->data();

        $this->assertSame(['Kepala Biro Urutan Satu', 'Kepala Biro Urutan Dua'], array_column($d['sekretaris']['list_es2'], 'jabatan'));
        $this->assertSame(['Biro Dua Uji', 'Biro Uji'], array_column($d['sekretaris']['list_es2'], 'satker'));
        $this->assertSame(['Deputi Uji', 'Deputi B Uji'], array_column($d['deputi'], 'jabatan'));
        $this->assertSame(['Direktur A Satu', 'Direktur A Dua'], array_column($d['deputi'][0]['list_es2'], 'jabatan'));
        $this->assertSame(['Kepala Biro B Nol', 'Inspektur Di Deputi B'], array_column($d['deputi'][1]['list_es2'], 'jabatan'));
        $this->assertSame(['Biro Dua Uji', 'Inspektorat Uji'], array_column($d['deputi'][1]['list_es2'], 'satker'));
    }

    // ---- bantuan ----

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return (new StrukturService())->pohon($this->admin())['data'];
    }

    /**
     * @param array<string, mixed> $override
     */
    private function pegawai(array $override = []): string
    {
        return $this->buatPegawai($override + ['id_jenis_status' => 1, 'created_at' => '2020-01-01 00:00:00']);
    }

    /**
     * @param array<string, mixed> $ekstra
     */
    private function rmj(string $nip, string $kunci, string $tmt, array $ekstra = []): void
    {
        $this->buatRiwayatMutasiJabatan($nip, $ekstra + ['id_jabatan' => $this->jab[$kunci], 'id_group_jabatan' => 1, 'tmtsk' => $tmt]);
    }

    private function kp(string $nip, string $gol, string $tmt, int $status = 1): void
    {
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'gol_ruang' => $gol, 'tmtsk' => $tmt, 'status' => $status]);
    }

    private function tugas(string $jenis, string $nip, string $kunci, string $mulai, string $akhir): void
    {
        $this->db->table("pegawai_{$jenis}")->insert([
            "nip_{$jenis}" => $nip, 'jenis_jabatan' => 1, 'id_jabatan' => $this->jab[$kunci], 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'status' => 1,
        ]);
    }

    /**
     * @param array<string, mixed> $baris
     */
    private function pastikan(string $tabel, array $baris): void
    {
        $pk = (string) array_key_first($baris);

        if ($this->db->table($tabel)->where($pk, $baris[$pk])->countAllResults() === 0) {
            $this->db->table($tabel)->insert($baris);
        }
    }

    private function jabatanStruktur(string $kunci, string $nama, int $satker, int $sub, int $level, ?string $atasan, int $urutan): void
    {
        $this->db->table('jabatan')->insert(['id_group_jabatan' => 1, 'id_sub_group_jabatan' => $sub, 'id_satker' => $satker, 'jabatan' => $nama, 'status' => 1]);
        $this->jab[$kunci] = (int) $this->db->insertID();
        $this->db->table('struktur_jabatan')->insert([
            'id_periode_struktur_jabatan' => $this->idPeriode, 'id_jabatan' => $this->jab[$kunci], 'id_jabatan_atasan' => $atasan === null ? null : $this->jab[$atasan],
            'periode_struktur_jabatan'    => 'Periode Uji', 'jabatan' => $nama, 'level_struktur' => $level, 'urutan' => $urutan, 'status' => 1,
        ]);
    }
}
