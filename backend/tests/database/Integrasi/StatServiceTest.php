<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Stat\PenyajiStat;
use App\Libraries\Integrasi\Stat\StatService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\FakeStorageAdapter;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-061 AC-08.2 — kontrak kolom keluaran `services/s_stat` = legacy (fixture per endpoint), arsip lewat storage v2
 * (ada → URL unduh klien; tidak ada → tidak dikeluarkan), Q6-37 (`rekomendasi_kp_uns` berfilter → kosong), kolom `file_*`
 * daftar tanpa path internal (P3), dan aturan unduh berkas Q6-38 (404 seragam). Hari ini diinjeksi = 2026-11-05.
 *
 * Fixture mematuhi FK snapshot → riwayat (DBV-019): `pegawai_kp.id_riwayat_kp` → `riwayat_kp`, `pegawai_kgb.id_riwayat_kgb`
 * → `riwayat_kgb`, `pegawai_ak.id_riwayat_ak` → `riwayat_ak`, `pegawai_mutasi_jabatan.id_riwayat_mutasi_jabatan` →
 * `riwayat_mutasi_jabatan`.
 *
 * @internal
 */
final class StatServiceTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private const HARI_INI = '2026-11-05';

    private const BASE = 'http://uji.local/';

    private FakeStorageAdapter $storage;

    private StatService $service;

    private int $idPangkatIIIa = 0;

    private int $idJenjangS1 = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pastikanEselon();
        $this->storage = new FakeStorageAdapter();
        $this->service = new StatService($this->db, $this->storage, new PenyajiStat(self::BASE), 'Kementerian Uji');

        $this->pastikanMaster('jenis_pegawai', 'id_jenis_pegawai', [1 => ['jenis_pegawai' => 'PNS'], 6 => ['jenis_pegawai' => 'PPPK']]);
        $this->pastikanMaster('jenis_kp', 'id_jenis_kp', [2 => ['jenis_kp' => 'Pengangkatan PNS'], 3 => ['jenis_kp' => 'Reguler']]);
        $this->db->table('pangkat')->insert(['pangkat' => 'Penata Muda', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'order' => 16, 'status' => 1]);
        $this->idPangkatIIIa = (int) $this->db->insertID();
        $this->db->table('pangkat')->insert(['pangkat' => 'Penata Muda Tk. I', 'gol' => 'III', 'ruang' => 'b', 'gol_ruang' => 'III/b', 'order' => 17, 'status' => 1]);
        $this->db->table('jenjang_pendidikan')->insert(['jenjang_pendidikan_singkat' => 'S.1', 'jenjang_pendidikan' => 'Strata 1', 'status' => 1]);
        $this->idJenjangS1 = (int) $this->db->insertID();
    }

    // ------------------------------------------------------------------------------------------------------------------

    public function testRekomendasiKpFormatSimpegKolomArsipDanUrutan(): void
    {
        [$a, $rkA] = $this->pegawaiKp('2022-04-01', 'III/a'); // reguler → 1 April 2026
        [$b]       = $this->pegawaiKp('2023-04-01', 'III/a'); // siklus tidak pas → tidak masuk
        [$c]       = $this->pegawaiKp('2022-10-01', 'II/a');  // 1 Oktober 2026; gol lebih rendah → diurut setelah III/a
        $satkerA   = (int) $this->db->table('pegawai_mutasi_jabatan')->select('id_satker')->where('nip', $a)->get()->getRow('id_satker');
        [$e]       = $this->pegawaiKp('2022-04-01', 'III/a', [], ['nama' => 'Aaa Pertama'], ['id_satker' => $satkerA]); // S1: tie gol+satker → nama ASC
        $this->pendidikan($e);
        $this->pendidikan($a, 'Hukum');
        $this->pendidikan($b);
        $this->pendidikan($c);

        // Arsip KP: dua lampiran untuk entri snapshot (satu ada di storage, satu tidak) + satu untuk riwayat lain.
        $rkLain = $this->riwayatKp($a, '2018-04-01');
        $idAda  = $this->lampiran($a, 11, (string) $rkA, 'arsip/kp/a-ada.pdf', true);
        $this->lampiran($a, 11, (string) $rkA, 'arsip/kp/a-hilang.pdf', false);
        $this->lampiran($a, 11, (string) $rkLain, 'arsip/kp/a-lain.pdf', true);
        // SKP 2025 status 1 dengan arsip; SKP 2024 status 0 (tidak ikut).
        $idSkp25 = $this->skp($a, 2025, 1, '2025-01-02', '2025-12-31');
        $this->skp($a, 2024, 0, '2024-01-02', '2024-12-31');
        $idArsipSkp = $this->lampiran($a, 32, (string) $idSkp25, 'arsip/skp/a-2025.pdf', true);

        $daftar = $this->service->rekomendasiKp(self::HARI_INI);

        $this->assertSame([$e, $a, $c], array_column($daftar, 'nip'), 'gol_ruang DESC, id_satker ASC, nama ASC (S1); b tidak masuk');
        $this->assertSame(
            array_merge(StatService::KOLOM_KP, ['id_jenis_kp', 'arsip_cpns', 'arsip_pns', 'arsip_last_kp', 'arsip_skp']),
            array_keys($daftar[0]),
        );

        $r = $daftar[0];
        $this->assertSame('01 Apr 2022', $r['tmtsk_pangkat']);
        $this->assertSame('01 Apr 2026', $r['tgl_kp']);
        $this->assertSame('Apr 2026', $r['periode_kp']);
        $this->assertSame('April', $r['kp_bl']);
        $this->assertSame('S.1', $r['strata']);
        $this->assertSame('3', $r['id_jenis_kp']);
        $this->assertSame('Penata Muda', $r['pangkat']);
        $this->assertSame('Dr. Aaa Pertama, M.Si.', $r['nama']);
        $r = $daftar[1];
        $this->assertStringStartsWith('Dr. Pegawai Fixture', $r['nama']);
        $this->assertSame([self::BASE . "services/s_stat/berkas/kp/{$a}/{$idAda}"], $r['arsip_last_kp'], 'hanya yang ada di storage, hanya entri snapshot');
        $this->assertSame([], $r['arsip_cpns']);
        $this->assertSame([2024 => [], 2025 => [self::BASE . "services/s_stat/berkas/skp/{$a}/{$idArsipSkp}"]], $r['arsip_skp'], '[K] kunci Y-2 dulu, lalu Y-1 (S_stat:230)');
        $this->assertSame('01 Oct 2026', $daftar[2]['tgl_kp']);
        $this->assertSame('October', $daftar[2]['kp_bl']);
    }

    public function testRekomendasiKpUnsFormatMudigDanQ637(): void
    {
        [$a] = $this->pegawaiKp('2022-04-01', 'III/a', ['mker_th' => 10, 'mker_bl' => 5, 'gaji_pokok' => 3000000]);
        $this->pendidikan($a, 'LAIN-LAIN', 'Pariwisata');
        [$d] = $this->pegawaiKp('2022-04-01', 'III/b'); // P1: gol_ruang_old DESC (string) → III/b sebelum III/a
        $this->pendidikan($d);
        $rmj = $this->buatRiwayatMutasiJabatan($a);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $a)->update(['id_riwayat_mutasi_jabatan' => $rmj, 'kredit_jft' => 12.5]);
        $idJab = $this->lampiran($a, 9, (string) $rmj, 'arsip/jab/a.pdf', true);
        $rak   = $this->riwayatAk($a);
        $this->db->table('pegawai_ak')->insert(['nip' => $a, 'id_riwayat_ak' => $rak, 'nilai_ak' => 150.25, 'file_pak' => 'arsip/pak/a.pdf']);
        $this->storage->simpan('arsip/pak/a.pdf', '%PDF-pak');
        // SKP MuDig ketat: 2025 (Jan–Des) masuk; 2024 mulai Maret → tidak masuk.
        $idSkp25  = $this->skp($a, 2025, 1, '2025-02-01', '2025-12-31');
        $idSkp25b = $this->skp($a, 2025, 1, '2025-01-05', '2025-12-31'); // S2: urut tgl_mulai DESC → baris ini terakhir → menang
        $this->skp($a, 2024, 1, '2024-03-01', '2024-12-31');
        $this->lampiran($a, 32, (string) $idSkp25, 'arsip/skp/a-2025.pdf', true);
        $idArsip25b = $this->lampiran($a, 32, (string) $idSkp25b, 'arsip/skp/a-2025b.pdf', true);

        // [K] bug legacy Q6-37: filter tidak kosong → kosong.
        $this->assertSame([], $this->service->rekomendasiKpUns(self::HARI_INI, '', '12'));
        $this->assertSame([], $this->service->rekomendasiKpUns(self::HARI_INI, '3', ''));

        $daftar = $this->service->rekomendasiKpUns(self::HARI_INI, '', '');
        $this->assertSame([$d, $a], array_column($daftar, 'nip'), 'P1 [K S_stat:477-479]: gol_ruang_old DESC, id_satker ASC');
        $r = $daftar[1];

        // P3 [K S_stat:638]: urutan kunci arsip dari assignment berantai (kanan → kiri); lu_ppen sudah ada di kolom MuDig.
        $this->assertSame(
            array_merge(StatService::KOLOM_MUDIG, ['arsip_last_pak', 'arsip_last_jab', 'arsip_skp', 'arsip_last_kp', 'arsip_pns', 'arsip_cpns', 'lu_ppak', 'lu_pcpns', 'lu_ppns', 'lu_pkp', 'lu_pmj', 'lu_rskp_y1', 'lu_rskp_y2']),
            array_keys($r),
        );
        $this->assertNull($r['lu_ppen'], 'P2 [K S_stat:722]: selalu NULL');
        $this->assertSame([2024, 2025], array_keys($r['arsip_skp']), 'arsip_skp {Y-2, Y-1}');
        $this->assertSame('2026-04-01', $r['tmt_kp_new'], 'format MuDig: tanggal mentah');
        $this->assertSame('2022-04-01', $r['tmt_kp_old']);
        $this->assertSame(['3', 'Reguler'], [$r['id_jenis_kp'], $r['jenis_kp']]);
        $this->assertSame(['Penata Muda Tk. I', 'III/b', ''], [$r['pangkat_new'], $r['gol_ruang_new'], $r['gaji_pokok_new']]);
        $this->assertSame(['14', '5', '14 Tahun 5 Bulan'], [(string) $r['mker_th_new'], (string) $r['mker_bl_new'], $r['mker_new']]);
        $this->assertSame('10 Tahun 5 Bulan', $r['mker_old']);
        $this->assertSame('Pariwisata', $r['bid_pen']);
        $this->assertSame('Strata 1', $r['jen_pen']);
        $this->assertSame('Kementerian Uji', $r['ins_induk']);
        $this->assertSame('16', $r['order_pangkat_old']);
        $this->assertSame('150.25', (string) $r['ak_old'], 'ak_old ditimpa pegawai_ak.nilai_ak [K]');
        $this->assertSame([self::BASE . "services/s_stat/berkas/jabatan/{$a}/{$idJab}"], $r['arsip_last_jab']);
        $this->assertSame([self::BASE . "services/s_stat/berkas/pak/{$a}/{$rak}/file_pak"], $r['arsip_last_pak']);
        $this->assertArrayHasKey(2025, $r['arsip_skp']);
        $this->assertSame([self::BASE . "services/s_stat/berkas/skp/{$a}/{$idArsip25b}"], $r['arsip_skp'][2025], 'S2 [K S_stat:581]: hanya baris SKP terakhir per tahun');
        $this->assertSame([], $r['arsip_skp'][2024]);
        $this->assertNotNull($r['lu_rskp_y1']);
        $this->assertNull($r['lu_rskp_y2'], 'SKP 2024 tidak lolos saringan ketat MuDig');
        $this->assertNotNull($r['lu_ppak']);
    }

    public function testRekomendasiPensiunKolomDanTahunIni(): void
    {
        $a = $this->buatPegawai(['tgl_lahir' => '1968-03-15', 'id_jenis_pegawai' => 1, 'glr_awal' => 'Ir.', 'glr_akhir' => null]);
        $b = $this->buatPegawai(['tgl_lahir' => '1970-03-15', 'id_jenis_pegawai' => 1]); // pensiun 2028
        $p = $this->buatPegawai(['tgl_lahir' => '1968-03-15', 'id_jenis_pegawai' => 6]); // PPPK tidak ikut
        $this->umurPensiunJabatan($a, 58);
        $this->umurPensiunJabatan($b, 58);
        $idSkp   = $this->skp($a, 2025, 1, '2025-01-02', '2025-12-31');
        $idArsip = $this->lampiran($a, 32, (string) $idSkp, 'arsip/skp/p.pdf', true);

        $daftar = $this->service->rekomendasiPensiun(self::HARI_INI);

        $this->assertSame([$a], array_column($daftar, 'nip'));
        $this->assertSame(
            array_merge(StatService::KOLOM_PENSIUN, ['usia_th', 'usia_bl', 'arsip_cpns', 'arsip_pns', 'arsip_last_kp', 'arsip_skp']),
            array_keys($daftar[0]),
        );
        $r = $daftar[0];
        $this->assertSame('15 Mar 1968', $r['tgl_lahir']);
        $this->assertSame('01 Jan 2015', $r['tmtsk_jabatan']);
        $this->assertSame(['2026-04-01', '2026', '4'], [$r['tgl_pensiun'], $r['pensiun_th'], $r['pensiun_bl']]);
        $this->assertSame([58, 7], [$r['usia_th'], $r['usia_bl']]);
        $this->assertStringStartsWith('Ir. Pegawai Fixture', $r['nama']);
        $this->assertSame([2025 => [self::BASE . "services/s_stat/berkas/skp/{$a}/{$idArsip}"]], $r['arsip_skp'], 'pensiun hanya Y-1');
        $this->assertNotContains($b, array_column($daftar, 'nip'));
        $this->assertNotContains($p, array_column($daftar, 'nip'));

        // BUP 60 (umur_pensiun jabatan) → 2028 → tidak masuk tahun ini.
        $this->umurPensiunJabatan($a, 60);
        $this->assertSame([], $this->service->rekomendasiPensiun(self::HARI_INI));
    }

    public function testRekomendasiKgbKolomUrutanDanArsip(): void
    {
        $a   = $this->buatPegawai(['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS']);
        $b   = $this->buatPegawai(['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'PNS']);
        $rkA = $this->riwayatKgb($a, '2025-03-01');
        $rkB = $this->riwayatKgb($b, '2024-06-01');
        $this->db->table('pegawai_kgb')->insert(['nip' => $a, 'id_riwayat_kgb' => $rkA, 'tmtsk' => '2025-03-01', 'tgl_sk' => '2025-02-01', 'no_sk' => 'SK-A', 'gaji_pokok' => 4000000]);
        $this->db->table('pegawai_kgb')->insert(['nip' => $b, 'id_riwayat_kgb' => $rkB, 'tmtsk' => '2024-06-01', 'tgl_sk' => '2024-05-01', 'no_sk' => 'SK-B']);
        $rkpA = $this->riwayatKp($a, '2020-04-01');
        $this->db->table('pegawai_kp')->insert(['nip' => $a, 'id_riwayat_kp' => $rkpA, 'id_jenis_kp' => 3, 'id_pangkat' => $this->idPangkatIIIa, 'tmtsk' => '2020-04-01', 'tgl_sk' => '2020-03-01', 'jenis_kp' => 'Reguler', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda']);
        $idKgb = $this->lampiran($a, 10, (string) $rkA, 'arsip/kgb/a.pdf', true);
        $this->lampiran($a, 11, (string) $rkpA, 'arsip/kp/a.pdf', true); // arsip KP tidak dikeluarkan [K]

        $daftar = $this->service->rekomendasiKgb(self::HARI_INI);

        $this->assertSame([$b, $a], array_column($daftar, 'nip'), '[V2] urut tmtsk_kgb: b = hari ini (2026-11-05) < a = 2027-03-01');
        $this->assertSame(array_merge(StatService::KOLOM_KGB, ['arsip_last_kgb']), array_keys($daftar[1]));
        $r = $daftar[1];
        $this->assertSame(['2027-03-01', 1, 8], [$r['tmtsk_kgb'], $r['kgb_th'], $r['kgb_bl']]);
        $this->assertSame(['SK-A', '2025-03-01', '4000000'], [$r['no_sk_last_kgb'], $r['tmtsk_last_kgb'], $r['gaji_last_kgb']]);
        $this->assertSame(['III/a', '1', 'PNS'], [$r['gol_ruang'], $r['id_jenis_pegawai'], $r['jenis_pegawai']]);
        $this->assertSame([self::BASE . "services/s_stat/berkas/kgb/{$a}/{$idKgb}"], $r['arsip_last_kgb']);
        $this->assertArrayNotHasKey('arsip_kp', $r);
        $this->assertSame('2026-11-05', $daftar[0]['tmtsk_kgb']);
        $this->assertNull($daftar[0]['gaji_last_kgb'], 'S5: NULL tetap null');
        $this->assertSame('SK-B', $daftar[0]['no_sk_last_kgb']);
    }

    public function testListTbDanKarpegTanpaPathInternal(): void
    {
        $a     = $this->buatPegawai(['glr_awal' => 'Dr.', 'jenis_kelamin' => 2]);
        $idTb  = $this->tb($a, 1, ['file_sp' => 'arsip/tb/sp.pdf', 'file_1' => 'arsip/tb/hilang.pdf']);
        $idTb2 = $this->tb($a, 2, ['file_sp' => 'arsip/tb/sp2.pdf']); // status 2 tidak ikut
        $this->storage->simpan('arsip/tb/sp.pdf', '%PDF-tb');
        $this->storage->simpan('arsip/tb/sp2.pdf', '%PDF-tb2');

        // B1: sp_admin_deputi = path berkas legacy → URL/kosong. P4: file_2 kosong tetapi ada lampiran v2 kode 46 tag file_2;
        // file_surat_tb kosong tetapi ada SK kode 47 (tanpa tag); lampiran tag file_3 ada di DB tapi tidak di storage → kosong.
        $this->db->table('riwayat_tb')->where('id_riwayat_tb', $idTb)->update(['sp_admin_deputi' => 'arsip/tb/sp-deputi.pdf']);
        $this->storage->simpan('arsip/tb/sp-deputi.pdf', '%PDF-deputi');
        $idLamp2  = $this->lampiranTag($a, 46, (string) $idTb, 'file_2', 'arsip/tb/v2-file2.pdf', true);
        $idLamp2b = $this->lampiranTag($a, 46, (string) $idTb, 'file_2', 'arsip/tb/v2-file2-baru.pdf', true); // terbaru menang
        $this->lampiranTag($a, 46, (string) $idTb, 'file_3', 'arsip/tb/v2-file3-hilang.pdf', false);
        $idSk = $this->lampiranTag($a, 47, (string) $idTb, null, 'arsip/tb/v2-sk.pdf', true);

        $tb = $this->service->listTb();
        $this->assertSame([$idTb], array_map(static fn (array $r): int => (int) $r['id_riwayat_tb'], $tb));
        $r = $tb[0];
        $this->assertSame(self::BASE . "services/s_stat/berkas/tb/{$a}/{$idTb}/file_sp", $r['file_sp']);
        $this->assertSame('', $r['file_1'], 'path impor yang tidak ada di storage → kosong');
        $this->assertSame(self::BASE . "services/s_stat/berkas/lampiran_tb/{$a}/{$idLamp2b}", $r['file_2'], 'P4: lampiran v2 terbaru untuk slot file_2');
        $this->assertSame('', $r['file_3'], 'lampiran v2 yang tidak ada di storage → kosong');
        $this->assertSame(self::BASE . "services/s_stat/berkas/surat_tb/{$a}/{$idSk}", $r['file_surat_tb'], 'P4: SK TB kode 47');
        $this->assertSame(self::BASE . "services/s_stat/berkas/tb/{$a}/{$idTb}/sp_admin_deputi", $r['sp_admin_deputi'], 'B1: path legacy tidak keluar mentah');
        $this->assertNotSame($idLamp2, $idLamp2b);
        $this->assertSame(['isi' => '%PDF-1.4', 'nama' => "s_stat_lampiran_tb_{$idLamp2b}.pdf"], $this->service->berkasLampiran('lampiran_tb', $a, $idLamp2b, self::HARI_INI));
        $this->assertSame(['isi' => '%PDF-deputi', 'nama' => "s_stat_tb_{$idTb}.pdf"], $this->service->berkasKolom('tb', $a, $idTb, 'sp_admin_deputi'));
        $this->assertSame(['id_unit', 'id_satker'], array_slice(array_keys($r), -2));
        $this->assertStringNotContainsString('arsip/tb', json_encode($tb, JSON_THROW_ON_ERROR), 'path internal tidak pernah keluar');
        $this->assertNotContains($idTb2, array_map(static fn (array $r): int => (int) $r['id_riwayat_tb'], $tb));
        $idLampTolak = $this->lampiranTag($a, 46, (string) $idTb2, 'file_sp', 'arsip/tb/v2-tolak.pdf', true);
        $this->assertNull($this->service->berkasLampiran('lampiran_tb', $a, $idLampTolak, self::HARI_INI), 'baris status 2 tidak muncul di list_tb → 404');

        $idK = $this->kartu('riwayat_karpeg', $a, 1, ['file_1' => 'arsip/karpeg/1.pdf']);
        $this->kartu('riwayat_karpeg', $a, 2, []);
        $this->storage->simpan('arsip/karpeg/1.pdf', 'x');
        $k = $this->service->listKarpeg();
        $this->assertCount(1, $k);
        $this->assertSame(self::BASE . "services/s_stat/berkas/karpeg/{$a}/{$idK}/file_1", $k[0]['file_1']);
        $this->assertSame('', $k[0]['file_2']);
        $this->assertSame(['Dr.', 2], [$k[0]['glr_awal'], (int) $k[0]['jenis_kelamin']]);

        foreach (['glr_awal', 'nama', 'glr_akhir', 'jenis_kelamin', 'id_sub_group_jabatan', 'sub_group_jabatan', 'id_unit', 'id_satker', 'unit', 'satker', 'jabatan'] as $kolom) {
            $this->assertArrayHasKey($kolom, $k[0]);
        }

        $this->assertSame([], $this->service->listIb());
        $this->assertSame([], $this->service->listKariskarsu());
    }

    public function testBerkasLampiranAturan404Seragam(): void
    {
        [$a, $rkA]      = $this->pegawaiKp('2022-04-01', 'III/a');
        $b              = $this->buatPegawai();
        $rkLain         = $this->riwayatKp($a, '2018-04-01');
        $idOk           = $this->lampiran($a, 11, (string) $rkA, 'arsip/kp/ok.pdf', true, '%PDF-ok');
        $idHilang       = $this->lampiran($a, 11, (string) $rkA, 'arsip/kp/hilang.pdf', false);
        $idEntri        = $this->lampiran($a, 11, (string) $rkLain, 'arsip/kp/entri-lain.pdf', true);
        $idSkpLama      = $this->skp($a, 2022, 1, '2022-01-02', '2022-12-31');
        $idArsipSkpLama = $this->lampiran($a, 32, (string) $idSkpLama, 'arsip/skp/2022.pdf', true);
        $idSkpOk        = $this->skp($a, 2025, 1, '2025-01-02', '2025-12-31');
        $idArsipSkpOk   = $this->lampiran($a, 32, (string) $idSkpOk, 'arsip/skp/2025.pdf', true);

        $ok = $this->service->berkasLampiran('kp', $a, $idOk, self::HARI_INI);
        $this->assertSame(['isi' => '%PDF-ok', 'nama' => "s_stat_kp_{$idOk}.pdf"], $ok);

        $this->assertNull($this->service->berkasLampiran('kp', $b, $idOk, self::HARI_INI), 'bukan milik NIP');
        $this->assertNull($this->service->berkasLampiran('skp', $a, $idOk, self::HARI_INI), 'jenis tidak cocok kode');
        $this->assertNull($this->service->berkasLampiran('kp', $a, $idHilang, self::HARI_INI), 'tidak ada di storage');
        $this->assertNull($this->service->berkasLampiran('kp', $a, $idEntri, self::HARI_INI), 'entri bukan snapshot');
        $this->assertNull($this->service->berkasLampiran('lain', $a, $idOk, self::HARI_INI), 'jenis di luar daftar putih');
        $this->assertNull($this->service->berkasLampiran('kp', $a, 0, self::HARI_INI));
        $this->assertNull($this->service->berkasLampiran('skp', $a, $idArsipSkpLama, self::HARI_INI), 'SKP di luar Y-1/Y-2');
        $this->assertNotNull($this->service->berkasLampiran('skp', $a, $idArsipSkpOk, self::HARI_INI));
    }

    public function testBerkasKolomAturan404Seragam(): void
    {
        $a         = $this->buatPegawai();
        $idTb      = $this->tb($a, 1, ['file_sp' => 'arsip/tb/sp.pdf']);
        $idTbTolak = $this->tb($a, 2, ['file_sp' => 'arsip/tb/sp-tolak.pdf']);
        $this->storage->simpan('arsip/tb/sp.pdf', '%PDF-sp');
        $this->storage->simpan('arsip/tb/sp-tolak.pdf', '%PDF-tolak');
        $rak = $this->riwayatAk($a);
        $this->db->table('pegawai_ak')->insert(['nip' => $a, 'id_riwayat_ak' => $rak, 'file_pak' => 'arsip/pak/a.pdf']);
        $this->storage->simpan('arsip/pak/a.pdf', '%PDF-pak');

        $this->assertSame(['isi' => '%PDF-sp', 'nama' => "s_stat_tb_{$idTb}.pdf"], $this->service->berkasKolom('tb', $a, $idTb, 'file_sp'));
        $this->assertSame(['isi' => '%PDF-pak', 'nama' => "s_stat_pak_{$rak}.pdf"], $this->service->berkasKolom('pak', $a, $rak, 'file_pak'));

        $this->assertNull($this->service->berkasKolom('tb', $a, $idTb, 'file_1'), 'kolom kosong');
        $this->assertNull($this->service->berkasKolom('tb', $a, $idTb, 'keterangan'), 'kolom di luar daftar putih');
        $this->assertNull($this->service->berkasKolom('tb', $a, $idTbTolak, 'file_sp'), 'status 2 tidak muncul di list_tb');
        $this->assertNull($this->service->berkasKolom('tb', '000', $idTb, 'file_sp'), 'bukan milik NIP');
        $this->assertNull($this->service->berkasKolom('cuti', $a, $idTb, 'file_sp'), 'jenis di luar daftar putih');
        $this->assertNull($this->service->berkasKolom('pak', $a, $rak + 1000, 'file_pak'));
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * Master `group_jabatan` 1 (Struktural) + `sub_group_jabatan` 1..4 (E.I..E.IV) [K `getEselon`] disisipkan lebih dulu agar
     * id sub group fixture pegawai biasa jatuh di luar 1..4 (tidak dianggap pejabat struktural).
     */
    private function pastikanEselon(): void
    {
        if ($this->db->table('group_jabatan')->where('id_group_jabatan', 1)->countAllResults() === 0) {
            $this->db->table('group_jabatan')->insert(['id_group_jabatan' => 1, 'group_jabatan' => 'Struktural', 'status' => 1]);
        }

        foreach ([1 => 'Eselon I', 2 => 'Eselon II', 3 => 'Eselon III', 4 => 'Eselon IV'] as $id => $nama) {
            if ($this->db->table('sub_group_jabatan')->where('id_sub_group_jabatan', $id)->countAllResults() === 0) {
                $this->db->table('sub_group_jabatan')->insert(['id_sub_group_jabatan' => $id, 'id_group_jabatan' => 1, 'sub_group_jabatan' => $nama, 'status' => 1]);
            }
        }
    }

    /**
     * Master yang dirujuk FK, disisipkan bila id belum ada (id tetap, mis. jenis_pegawai 1/6, jenis_kp 3).
     *
     * @param array<int, array<string, mixed>> $baris id => kolom lain
     */
    private function pastikanMaster(string $tabel, string $pk, array $baris): void
    {
        foreach ($baris as $id => $kolom) {
            if ($this->db->table($tabel)->where($pk, $id)->countAllResults() === 0) {
                $this->db->table($tabel)->insert([$pk => $id, 'status' => 1] + $kolom);
            }
        }
    }

    /**
     * Pegawai PNS + riwayat KP + snapshot `pegawai_kp`; mengembalikan [nip, id_riwayat_kp].
     *
     * @param array<string, mixed> $kp
     * @param array<string, mixed> $pegawai
     * @param array<string, mixed> $pmj
     *
     * @return array{0: string, 1: int}
     */
    private function pegawaiKp(string $tmtsk, string $golRuang, array $kp = [], array $pegawai = [], array $pmj = []): array
    {
        $nip = $this->buatPegawai(['id_jenis_pegawai' => 1, 'glr_awal' => 'Dr.', 'glr_akhir' => 'M.Si.'] + $pegawai, $pmj);
        $rk  = $this->riwayatKp($nip, $tmtsk);
        $this->db->table('pegawai_kp')->insert(array_merge([
            'nip'      => $nip, 'id_riwayat_kp' => $rk, 'id_jenis_kp' => 3, 'id_pangkat' => $this->idPangkatIIIa, 'tmtsk' => $tmtsk, 'tgl_sk' => $tmtsk,
            'jenis_kp' => 'Reguler', 'gol' => explode('/', $golRuang)[0], 'ruang' => explode('/', $golRuang)[1], 'gol_ruang' => $golRuang, 'pangkat' => 'Penata Muda',
        ], $kp));

        return [$nip, $rk];
    }

    private function riwayatKp(string $nip, string $tmtsk): int
    {
        $this->db->table('riwayat_kp')->insert(['nip' => $nip, 'tmtsk' => $tmtsk, 'id_jenis_kp' => 3, 'id_pangkat' => $this->idPangkatIIIa, 'status' => 1]);

        return (int) $this->db->insertID();
    }

    private function riwayatKgb(string $nip, string $tmtsk): int
    {
        $this->db->table('riwayat_kgb')->insert(['nip' => $nip, 'tmtsk' => $tmtsk, 'tgl_sk' => $tmtsk, 'status' => 1]);

        return (int) $this->db->insertID();
    }

    private function riwayatAk(string $nip): int
    {
        $this->db->table('riwayat_ak')->insert(['nip' => $nip, 'status' => 1]);

        return (int) $this->db->insertID();
    }

    private function pendidikan(string $nip, ?string $bidang = null, ?string $bidangLain = null): void
    {
        $this->db->table('pegawai_pendidikan')->insert([
            'nip'               => $nip, 'id_jenjang_pendidikan' => $this->idJenjangS1, 'jenjang_pendidikan_singkat' => 'S.1', 'tgl_lulus' => '2010-08-01',
            'bidang_pendidikan' => $bidang, 'bidang_pendidikan_lain' => $bidangLain,
        ]);
    }

    private function umurPensiunJabatan(string $nip, int $umur): void
    {
        $idJabatan = (int) $this->db->table('pegawai_mutasi_jabatan')->select('id_jabatan')->where('nip', $nip)->get()->getRow('id_jabatan');
        $this->db->table('jabatan')->where('id_jabatan', $idJabatan)->update(['umur_pensiun' => $umur]);
    }

    private function lampiran(string $nip, int $kode, string $idEntri, string $path, bool $diStorage, string $isi = '%PDF-1.4'): int
    {
        $this->db->table('document_attachment')->insert(['NIP' => $nip, 'id_riwayat' => $kode, 'id_entri' => $idEntri, 'path' => $path, 'display_name' => basename($path), 'file_ext' => 'pdf', 'file_type' => 'application/pdf']);
        $id = (int) $this->db->insertID();

        if ($diStorage) {
            $this->storage->simpan($path, $isi);
        }

        return $id;
    }

    private function lampiranTag(string $nip, int $kode, string $idEntri, ?string $tag, string $path, bool $diStorage): int
    {
        $this->db->table('document_attachment')->insert(['NIP' => $nip, 'id_riwayat' => $kode, 'id_entri' => $idEntri, 'tag' => $tag, 'path' => $path, 'display_name' => basename($path), 'file_ext' => 'pdf', 'file_type' => 'application/pdf']);
        $id = (int) $this->db->insertID();

        if ($diStorage) {
            $this->storage->simpan($path, '%PDF-1.4');
        }

        return $id;
    }

    private function skp(string $nip, int $tahun, int $status, string $mulai, string $akhir): int
    {
        $this->db->table('riwayat_skp')->insert(['nip' => $nip, 'tahun' => $tahun, 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'status' => $status, 'updated_at' => '2026-01-01 00:00:00']);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $override
     */
    private function tb(string $nip, int $status, array $override): int
    {
        $this->db->table('riwayat_tb')->insert(array_merge([
            'nip'                => $nip, 'tgl_mulai' => '2026-09-01', 'tgl_akhir' => '2028-08-31', 'jenjang_pendidikan_singkat' => 'S.2', 'institusi_pendidikan' => 'UI',
            'jurusan_pendidikan' => 'Hukum', 'dalam_negeri' => 1, 'lokasi_pendidikan' => 'Jakarta', 'lembaga_yang_mendanai' => 'LPDP', 'status' => $status,
        ], $override));

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $override
     */
    private function kartu(string $tabel, string $nip, int $status, array $override): int
    {
        $this->db->table($tabel)->insert(array_merge(
            ['nip' => $nip, 'file_1' => '', 'file_2' => '', 'file_3' => '', 'file_4' => '', 'file_5' => '', 'status' => $status],
            $override,
        ));

        return (int) $this->db->insertID();
    }
}
