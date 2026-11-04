<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Http\Respons;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SiasnUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-067 M1 (H-03, F6-14) — `siasn:tarik --jenis=skp|pendidikan|pmk|ak` dengan FakeTransport dan fixture sintetis:
 * - SKP ≥ 2022: rating 1↔3, ubah/pasang/sisip `siasn_flag = 4`, HAPUS FISIK pasangan yang tak lagi dikembalikan hanya
 *   untuk NIP yang API-nya sukses; NIP gagal / data kosong tidak kehilangan baris (AC-03.3, test negatif);
 * - pendidikan (PNS + PPPK): kunci jenjang-tahun lulus, pasang, sisip LAIN-LAIN, lepas;
 * - PMK: pasang lewat `tgl_sk`, sisip dengan `masaKerjaTahun`, tolak tanggal tidak sah, lepas;
 * - AK: perbaikan `Rw_ak:65` (bandingkan baris AK yang benar), jabatan fungsional pada tanggal SK, tanpa hapus.
 *
 * @internal
 */
final class SiasnTarikRiwayatTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SiasnUjiTrait;

    private const NIP_A = '199001012020011001';

    private const NIP_B = '199001012020011002';

    private const NIP_C = '199001012020011003';

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSiasn();
    }

    protected function tearDown(): void
    {
        $this->pulihkanSiasn();
        parent::tearDown();
    }

    public function testSkpHapusFisikHanyaUntukNipYangApiNyaSukses(): void
    {
        foreach ([self::NIP_A, self::NIP_B, self::NIP_C] as $nip) {
            $this->buatPegawai(['nip' => $nip, 'id_jenis_pegawai' => 1]);
        }

        $this->db->table('siasn_referensi')->insert(['jenis' => 'golongan', 'id_siasn' => '34', 'nama' => 'IV/a']);
        $s1    = $this->skp(self::NIP_A, ['tahun' => 2022, 'id_rw_siasn' => 'SKP-1', 'rating_skp' => 1, 'rating_perilaku' => 2, 'kategori_nilai_prestasi' => 'Baik']);
        $s2    = $this->skp(self::NIP_A, ['tahun' => 2023, 'id_rw_siasn' => 'SKP-HILANG']);
        $s3    = $this->skp(self::NIP_A, ['tahun' => 2024]);
        $s2021 = $this->skp(self::NIP_A, ['tahun' => 2021, 'id_rw_siasn' => 'SKP-LAMA']);
        $sB    = $this->skp(self::NIP_B, ['tahun' => 2022, 'id_rw_siasn' => 'SKP-B']);
        $sC    = $this->skp(self::NIP_C, ['tahun' => 2022, 'id_rw_siasn' => 'SKP-C']);

        $this->antreToken();
        $this->antreData([
            $this->dataSkp('SKP-1', 2022, '1', '2', 'SANGAT BAIK', ['penilaiGolonganId' => '34']),
            $this->dataSkp('SKP-3', 2024, '2', '2', 'baik'),
            $this->dataSkp('SKP-5', 2025, '3', '1', 'BUTUH PERBAIKAN'),
            $this->dataSkp('SKP-X', 2025, '2', '2', 'baik', ['nipNrpPenilai' => str_repeat('9', 19)]),
        ]);
        $this->transport->antre(new Respons(500, 'galat'), new Respons(500, 'galat'), new Respons(500, 'galat'));
        $this->antreData([]);

        $h = $this->tarik()->jalankan('skp');

        $this->assertSame(['sisip' => 1, 'ubah' => 1, 'pasang' => 1, 'lepas' => 0, 'hapus' => 1, 'ganda' => 0], $h['jumlah']);
        $this->assertSame(['nipNrpPenilai_panjang' => 1], $h['ditolak']);

        $skp = array_column($this->db->table('riwayat_skp')->get()->getResultArray(), null, 'id_riwayat_skp');
        $this->assertSame(['3', '2', 'Sangat Baik', 'IV/a', (string) $this->idAkun], [(string) $skp[$s1]['rating_skp'], (string) $skp[$s1]['rating_perilaku'], $skp[$s1]['kategori_nilai_prestasi'], $skp[$s1]['gol_ruang_penilai'], (string) $skp[$s1]['updated_by']]);
        $this->assertArrayNotHasKey($s2, $skp, 'pasangan yang tidak dikembalikan BKN dihapus fisik [K Rw_skp:217-220]');
        $this->assertSame('SKP-3', $skp[$s3]['id_rw_siasn'], 'dipasangkan lewat tahun');
        $this->assertSame('SKP-LAMA', $skp[$s2021]['id_rw_siasn'], 'tahun < 2022 tidak disentuh');
        $this->assertArrayHasKey($sB, $skp, 'AC-03.3: API NIP B gagal → SKP-nya TIDAK dihapus (legacy menghapus, Rw_skp:219)');
        $this->assertArrayHasKey($sC, $skp, 'data kosong → tanpa hapus');

        $baru = $this->db->table('riwayat_skp')->where('id_rw_siasn', 'SKP-5')->get()->getRowArray();
        $this->assertSame(['2025', '2025-01-02', '2025-12-30', '1', '3', 'Butuh Perbaikan', '4', '1', 'Sync SIASN'], [(string) $baru['tahun'], $baru['tgl_mulai'], $baru['tgl_akhir'], (string) $baru['rating_skp'], (string) $baru['rating_perilaku'], $baru['kategori_nilai_prestasi'], (string) $baru['siasn_flag'], (string) $baru['status'], $baru['keterangan']]);
    }

    public function testPendidikanPasangSisipLainLainDanLepas(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 6]);
        $this->buatPegawai(['nip' => self::NIP_B, 'id_jenis_pegawai' => 2]);
        $this->db->table('siasn_referensi')->insertBatch([
            ['jenis' => 'tk_pendidikan', 'id_siasn' => 'TK-SLTA', 'id_lokal' => '3'],
            ['jenis' => 'tk_pendidikan', 'id_siasn' => 'TK-S1', 'id_lokal' => '8'],
            ['jenis' => 'tk_pendidikan', 'id_siasn' => 'TK-S2', 'id_lokal' => '9'],
            ['jenis' => 'tk_pendidikan', 'id_siasn' => 'TK-HILANG', 'id_lokal' => '77'],
        ]);
        $p1 = $this->didik(self::NIP_A, ['id_jenjang_pendidikan' => 8, 'tgl_lulus' => '2010-08-01', 'id_rw_siasn' => 'DIDIK-1']);
        $p2 = $this->didik(self::NIP_A, ['id_jenjang_pendidikan' => 3, 'tgl_lulus' => '2005-06-01']);
        $p3 = $this->didik(self::NIP_A, ['id_jenjang_pendidikan' => 9, 'tgl_lulus' => '2015-02-01', 'id_rw_siasn' => 'DIDIK-LAMA']);

        $this->antreToken();
        $this->antreData([
            ['id' => 'DIDIK-1', 'tkPendidikanId' => 'TK-S1', 'tglLulus' => '01-08-2010', 'tahunLulus' => '2010'],
            ['id' => 'DIDIK-2', 'tkPendidikanId' => 'TK-SLTA', 'tglLulus' => '15-06-2005', 'tahunLulus' => '2005'],
            ['id' => 'DIDIK-3', 'tkPendidikanId' => 'TK-S2', 'tglLulus' => '', 'tahunLulus' => '2020', 'namaSekolah' => 'Universitas Contoh', 'pendidikanNama' => 'Magister Contoh', 'gelarBelakang' => 'M.Uji', 'nomorIjasah' => 'IJZ-UJI-3'],
            ['id' => 'DIDIK-4', 'tkPendidikanId' => 'TK-HILANG', 'tglLulus' => '', 'tahunLulus' => '1999'],
            ['id' => 'DIDIK-5', 'tkPendidikanId' => 'TK-S1', 'tglLulus' => '', 'tahunLulus' => ''],
        ]);

        $h = $this->tarik()->jalankan('pendidikan');

        $this->assertSame(1, $h['nip'], 'PNS (1) dan PPPK (6) saja');
        $this->assertSame(['sisip' => 2, 'ubah' => 0, 'pasang' => 1, 'lepas' => 1, 'hapus' => 0, 'ganda' => 0], $h['jumlah']);

        $rw = array_column($this->db->table('riwayat_pendidikan')->where('nip', self::NIP_A)->get()->getResultArray(), null, 'id_rw_siasn');
        $this->assertSame((string) $p2, (string) $rw['DIDIK-2']['id_riwayat_pendidikan']);
        $this->assertNull($this->db->table('riwayat_pendidikan')->where('id_riwayat_pendidikan', $p3)->get()->getRow()->id_rw_siasn);
        $this->assertSame((string) $p1, (string) $rw['DIDIK-1']['id_riwayat_pendidikan']);
        $this->assertSame(['2020-01-01', '9', '98', '1185', 'LAIN-LAIN', 'Magister Contoh', 'Universitas Contoh', 'S.2', 'M.Uji', 'Sync SIASN'], [
            $rw['DIDIK-3']['tgl_lulus'], (string) $rw['DIDIK-3']['id_jenjang_pendidikan'], (string) $rw['DIDIK-3']['id_bidang_pendidikan'], (string) $rw['DIDIK-3']['id_jurusan_pendidikan'],
            $rw['DIDIK-3']['bidang_pendidikan'], $rw['DIDIK-3']['jurusan_pendidikan_lain'], $rw['DIDIK-3']['institusi_pendidikan'], $rw['DIDIK-3']['jenjang_pendidikan_singkat'], $rw['DIDIK-3']['glr_akhir'], $rw['DIDIK-3']['keterangan'],
        ]);
        $this->assertNull($rw['DIDIK-4']['id_jenjang_pendidikan'], 'pemetaan ke jenjang yang tidak ada di master = tidak terpetakan');
        $this->assertSame('-', $rw['DIDIK-4']['institusi_pendidikan']);
        $this->assertArrayNotHasKey('DIDIK-5', $rw, 'tanpa tanggal & tahun lulus dilewati');
    }

    public function testPmkPasangLewatTglSkSisipTolakDanLepas(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1]);
        $m1 = $this->pmk(self::NIP_A, ['tgl_sk' => '2008-01-10', 'id_rw_siasn' => 'PMK-1']);
        $m2 = $this->pmk(self::NIP_A, ['tgl_sk' => '2010-05-01']);
        $m3 = $this->pmk(self::NIP_A, ['tgl_sk' => '2012-03-01', 'id_rw_siasn' => 'PMK-LAMA']);

        $this->antreToken();
        $this->antreData([
            ['id' => 'PMK-1', 'tanggalSk' => '10-01-2008'],
            ['id' => 'PMK-2', 'tanggalSk' => '01-05-2010'],
            ['id' => 'PMK-3', 'idPns' => 'PNS-UJI-A', 'nomorSk' => 'SK-PMK-3', 'tanggalSk' => '02-02-2020', 'nomorBkn' => 'BKN-3', 'tanggalBkn' => '03-02-2020', 'pengalaman' => 'PT Contoh', 'tanggalAwal' => '01-01-2005', 'tanggalSelesai' => '31-12-2007', 'masaKerjaTahun' => '3', 'masaKerjaBulan' => '0', 'dinilai' => 'Ya'],
            ['id' => 'PMK-4', 'tanggalSk' => '2020/02/02'],
        ]);

        $h = $this->tarik()->jalankan('pmk');

        $this->assertSame(['sisip' => 1, 'ubah' => 0, 'pasang' => 1, 'lepas' => 1, 'hapus' => 0, 'ganda' => 0], $h['jumlah']);
        $this->assertSame(['tanggalSk_tanggal' => 1], $h['ditolak']);

        $rw = array_column($this->db->table('riwayat_pmk')->where('nip', self::NIP_A)->get()->getResultArray(), null, 'id_riwayat_pmk');
        $this->assertSame(['PMK-1', 'PMK-2', null], [$rw[$m1]['id_rw_siasn'], $rw[$m2]['id_rw_siasn'], $rw[$m3]['id_rw_siasn']]);
        $baru = $this->db->table('riwayat_pmk')->where('id_rw_siasn', 'PMK-3')->get()->getRowArray();
        $this->assertSame(['SK-PMK-3', '2020-02-02', 'PT Contoh', '3', '0', '1', '1', (string) $this->idAkun], [$baru['no_sk'], $baru['tgl_sk'], $baru['instansi'], (string) $baru['mker_th'], (string) $baru['mker_bl'], (string) $baru['siasn_flag'], (string) $baru['status'], (string) $baru['updated_by']]);
        $this->assertNotNull($baru['siasn_lu'], 'siasn_lu = waktu tarik (legacy menulis teks ke DATETIME)');
    }

    public function testAkMembandingkanBarisAkYangBenarDanJabatanPadaTanggalSkTanpaHapus(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1]);
        $jabLama = (int) $this->buatJabatan()['id_jabatan'];
        $jabBaru = (int) $this->buatJabatan()['id_jabatan'];
        $this->db->table('riwayat_mutasi_jabatan')->insertBatch([
            ['nip' => self::NIP_A, 'tmtsk' => '2018-01-01', 'id_jabatan' => $jabLama, 'jenis_jabatan' => 1, 'id_group_jabatan' => 2, 'status' => 1],
            ['nip' => self::NIP_A, 'tmtsk' => '2021-01-01', 'id_jabatan' => $jabBaru, 'jenis_jabatan' => 1, 'id_group_jabatan' => 2, 'status' => 1],
        ]);
        $this->db->table('riwayat_ak_siasn')->insertBatch([
            ['nip' => self::NIP_A, 'id_rw_siasn' => 'AK-1', 'kredit_utama_baru' => '10.000', 'kredit_penunjang_baru' => '1.000', 'kredit_baru_total' => '11.000', 'status' => 1],
            ['nip' => self::NIP_A, 'id_rw_siasn' => 'AK-TETAP', 'kredit_utama_baru' => '5.000', 'kredit_penunjang_baru' => '0.000', 'kredit_baru_total' => '5.000', 'status' => 1],
            ['nip' => self::NIP_A, 'id_rw_siasn' => 'AK-LOKAL-SAJA', 'kredit_utama_baru' => '1.000', 'kredit_penunjang_baru' => '0.000', 'kredit_baru_total' => '1.000', 'status' => 1],
        ]);

        $this->antreToken();
        $this->antreData([
            ['id' => 'AK-1', 'kreditUtamaBaru' => '12.5', 'kreditPenunjangBaru' => '1', 'kreditBaruTotal' => '13.5', 'bulanMulaiPenailan' => '1', 'tahunMulaiPenailan' => '2022', 'bulanSelesaiPenailan' => '12', 'tahunSelesaiPenailan' => '2022'],
            ['id' => 'AK-TETAP', 'kreditUtamaBaru' => '5', 'kreditPenunjangBaru' => '0', 'kreditBaruTotal' => '5'],
            ['id'             => 'AK-2', 'pns' => 'PNS-UJI-A', 'nomorSk' => 'PAK-UJI-2', 'tanggalSk' => '01-06-2021', 'kreditUtamaBaru' => '20', 'kreditPenunjangBaru' => '2', 'kreditBaruTotal' => '22', 'bulanMulaiPenailan' => '0', 'tahunMulaiPenailan' => '2021',
                'namaJabatan' => 'Analis Uji', 'isAngkaKreditPertama' => '0', 'Sumber' => 'SIASN', 'path' => ['873' => ['dok_uri' => 'peremajaan/uji/pak.pdf']]],
            ['id' => 'AK-3', 'tanggalSk' => '01-06-2017', 'kreditBaruTotal' => '99999999'],
        ]);

        $h = $this->tarik()->jalankan('ak');

        $this->assertSame(['sisip' => 1, 'ubah' => 1, 'pasang' => 0, 'lepas' => 0, 'hapus' => 0, 'ganda' => 0], $h['jumlah']);
        $this->assertSame(['kreditBaruTotal_rentang' => 1], $h['ditolak']);

        $ak = array_column($this->db->table('riwayat_ak_siasn')->where('nip', self::NIP_A)->get()->getResultArray(), null, 'id_rw_siasn');
        $this->assertSame(['12.500', '13.500', '1', '2022'], [$ak['AK-1']['kredit_utama_baru'], $ak['AK-1']['kredit_baru_total'], (string) $ak['AK-1']['bulan_mulai_penilaian'], (string) $ak['AK-1']['tahun_selesai_penilaian']]);
        $this->assertArrayHasKey('AK-LOKAL-SAJA', $ak, 'tanpa hapus (legacy mengomentari hapus AK)');
        $this->assertSame([(string) $jabBaru, '2021-06-01', null, 'peremajaan/uji/pak.pdf', 'Analis Uji', (string) $this->idAkun], [(string) $ak['AK-2']['id_jabatan'], $ak['AK-2']['tgl_sk'], $ak['AK-2']['bulan_mulai_penilaian'], $ak['AK-2']['f_pak_siasn'], $ak['AK-2']['nama_jabatan'], (string) $ak['AK-2']['updated_by']]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function skp(string $nip, array $data): int
    {
        $this->db->table('riwayat_skp')->insert($data + ['nip' => $nip, 'status' => 1]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function didik(string $nip, array $data): int
    {
        $this->db->table('riwayat_pendidikan')->insert($data + ['nip' => $nip, 'status' => 1]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function pmk(string $nip, array $data): int
    {
        $this->db->table('riwayat_pmk')->insert($data + ['nip' => $nip, 'no_sk' => 'SK-LOKAL', 'status' => 1]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, string> $lain
     *
     * @return array<string, int|string>
     */
    private function dataSkp(string $id, int $tahun, string $kinerja, string $perilaku, string $kuadran, array $lain = []): array
    {
        return $lain + [
            'id'                 => $id,
            'tahun'              => $tahun,
            'hasilKinerjaNilai'  => $kinerja,
            'PerilakuKerjaNilai' => $perilaku,
            'kuadranKinerja'     => $kuadran,
            'nipNrpPenilai'      => '198001012005011001',
            'namaPenilai'        => 'Penilai Uji',
            'penilaiJabatanNm'   => 'Kepala Uji',
            'penilaiGolonganId'  => '',
            'penilaiUnorNm'      => 'Unit Uji',
            'statusPenilai'      => 'ASN',
        ];
    }
}
