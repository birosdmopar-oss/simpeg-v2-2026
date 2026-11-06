<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Simapi\PenyajiPegawai;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SimapiUjiTrait;
use Tests\Support\Kepegawaian\FakeStorageAdapter;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-059 (H-06) — SIMAPI `a_employee/{method}/{nip}` lewat route sungguhan. AC-06.1: test kontrak per method (nama field,
 * urutan, pemetaan nilai teks) terhadap fixture `tests/_support/Integrasi/Simapi/a_employee.json` yang diturunkan dari kode
 * legacy; AC-06.2: tanpa token → "02". Data uji sintetis; FK dimatikan sementara (sesi uji, di dalam transaksi rollback)
 * supaya setiap tabel sumber cukup diisi satu baris tanpa menyiapkan seluruh master.
 *
 * @internal
 */
final class SimapiEmployeeTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use PegawaiFixtureTrait;
    use SimapiUjiTrait;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasangSimapiUji();
        $this->token = $this->tokenUji($this->klienUji('pusdatin', 1, ['simapi']));
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
    }

    protected function tearDown(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->lepasSimapiUji();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function emp(string $method, string $nip, string $httpMethod = 'get'): array
    {
        return $this->jsonUji($this->simapiUji('simapi/v1/a_employee/' . $method . ($nip === '' ? '' : '/' . $nip), $this->token, $httpMethod));
    }

    /**
     * Sisip satu baris; kolom NOT NULL tanpa default yang tidak diberikan diisi nilai sintetis sesuai tipe.
     *
     * @param array<string, mixed> $data
     */
    private function sisip(string $tabel, array $data): int
    {
        $kolom = $this->db->query(
            'SELECT column_name AS c, data_type AS t, column_type AS ct FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND is_nullable = ? AND column_default IS NULL AND extra NOT LIKE ?',
            [$this->db->prefixTable($tabel), 'NO', '%auto_increment%'],
        )->getResultArray();

        foreach ($kolom as $k) {
            $data[$k['c']] ??= match (true) {
                in_array($k['t'], ['date', 'datetime', 'timestamp'], true)                                      => '2020-01-01',
                $k['t'] === 'year'                                                                              => 2020,
                $k['t'] === 'enum'                                                                              => (string) preg_replace("/^enum\\('([^']*)'.*$/", '$1', (string) $k['ct']),
                in_array($k['t'], ['tinyint', 'smallint', 'int', 'bigint', 'decimal', 'float', 'double'], true) => 1,
                default                                                                                         => 'x',
            };
        }

        $this->db->table($tabel)->insert($data);

        return (int) $this->db->insertID();
    }

    /**
     * Pegawai dengan satu baris di setiap tabel sumber 25 method.
     */
    private function pegawaiLengkap(): string
    {
        $nip = $this->buatPegawai(['jenis_kelamin' => 2, 'status_pernikahan' => 1, 'foto' => 'foto/a.jpg']);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->update(['id_riwayat_mutasi_jabatan' => $this->sisip('riwayat_mutasi_jabatan', ['nip' => $nip, 'status' => 1, 'jenis_jabatan' => 2, 'status_jft' => 2])]);

        $idKp = $this->sisip('riwayat_kp', ['nip' => $nip, 'status' => 1]);
        foreach (['pegawai_kp', 'pegawai_cpns', 'pegawai_pns'] as $t) {
            $this->sisip($t, ['nip' => $nip, 'id_riwayat_kp' => $idKp]);
        }

        $idPen = $this->sisip('riwayat_pendidikan', ['nip' => $nip, 'status' => 1, 'id_jenjang_pendidikan' => 2, 'nem' => '45.5', 'ipk' => '3.5']);
        $this->sisip('pegawai_pendidikan', ['nip' => $nip, 'id_riwayat_pendidikan' => $idPen, 'id_jenjang_pendidikan' => 2, 'nem' => '45.5', 'ipk' => '3.5']);
        $idDik = $this->sisip('riwayat_diklat', ['nip' => $nip, 'status' => 1, 'jenis_diklat' => 3, 'deskripsi' => 'PIM', 'sub_group_jabatan' => 'Es.III']);
        $this->sisip('pegawai_diklat', ['nip' => $nip, 'id_riwayat_diklat' => $idDik, 'nama_diklat' => 'Diklat X']);
        $idKel = $this->sisip('riwayat_keluarga', ['nip' => $nip, 'status' => 1]);
        $this->sisip('pegawai_keluarga', ['nip' => $nip, 'id_riwayat_keluarga' => $idKel]);
        $this->sisip('detail_anak', ['id_riwayat_keluarga' => $idKel, 'urutan' => 2, 'nama' => 'Anak B']);
        $this->sisip('detail_anak', ['id_riwayat_keluarga' => $idKel, 'urutan' => 1, 'nama' => 'Anak A']);
        $idAl = $this->sisip('riwayat_alamat', ['nip' => $nip, 'status' => 1]);
        $this->sisip('pegawai_alamat', ['nip' => $nip, 'id_riwayat_alamat' => $idAl, 'id_provinsi' => 99, 'provinsi_lain' => 'Provinsi Lain']);
        $this->sisip('pegawai_alamat_kantor', ['nip' => $nip, 'id_riwayat_alamat' => $idAl]);
        $idKgb = $this->sisip('riwayat_kgb', ['nip' => $nip, 'status' => 1, 'jym' => 'Kepala Biro']);
        $this->sisip('pegawai_kgb', ['nip' => $nip, 'id_riwayat_kgb' => $idKgb, 'jym' => 'Kepala Biro']);
        $idTj = $this->sisip('riwayat_tanda_jasa', ['nip' => $nip, 'status' => 1, 'tanda_jasa' => 'LAIN-LAIN', 'tanda_jasa_lain' => 'Satyalancana X']);
        $this->sisip('pegawai_tanda_jasa', ['nip' => $nip, 'id_riwayat_tanda_jasa' => $idTj]);
        $this->sisip('riwayat_skp', ['nip' => $nip, 'status' => 1, 'tahun' => 2024]);
        $this->sisip('riwayat_seminar', ['nip' => $nip, 'status' => 1, 'jenis_seminar' => 2]);
        $this->sisip('riwayat_organisasi', ['nip' => $nip, 'status' => 1]);
        $this->sisip('riwayat_hukdis', ['nip' => $nip, 'status' => 1]);

        return $nip;
    }

    public function testKontrakSemuaMethodSamaDenganFixtureLegacy(): void
    {
        $kontrak = $this->fixtureKontrak('a_employee');
        $nip     = $this->pegawaiLengkap();

        $this->assertSame(array_keys(PenyajiPegawai::METHOD), array_values(array_filter(array_keys($kontrak), static fn (string $k): bool => $k[0] !== '_')), '25 method, urutan legacy');

        foreach (PenyajiPegawai::METHOD as $method => $_) {
            $badan = $this->emp($method, $nip, $method === 'detil' ? 'post' : 'get');
            $this->assertSame(['status', 'msg', 'data'], array_keys($badan), $method);
            $this->assertSame(['01', 'Success'], [$badan['status'], $badan['msg']], $method);

            $baris = $kontrak[$method]['daftar'] ? $badan['data'][0] : $badan['data'];
            $this->assertSame($kontrak[$method]['kunci'], array_keys($baris), "{$method} ({$kontrak[$method]['_rujukan']})");

            if (isset($kontrak[$method]['kunci_anak'])) {
                $this->assertSame($kontrak[$method]['kunci_anak'], array_keys($baris['daftar_anak'][0]), "{$method} daftar_anak");
            }
        }
    }

    public function testValidasiNipDanDataKosong(): void
    {
        $this->assertSame(['status' => '02', 'msg' => 'Invalid paramater', 'data' => []], $this->emp('data_umum', ''));
        $this->assertSame(['status' => '02', 'msg' => 'NIP not valid', 'data' => []], $this->emp('riwayat_kp', '999999999999999999'));
        $this->assertSame(['status' => '02', 'msg' => 'Token is not valid', 'data' => []], $this->jsonUji($this->tanpaAuthUji('simapi/v1/a_employee/data_umum/1')), 'AC-06.2');

        $nip = $this->buatPegawai(['status' => 10]);
        $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->delete();
        $this->sisip('riwayat_kp', ['nip' => $nip, 'status' => 10]);

        $this->assertSame('Tidak Aktif', $this->emp('data_umum', $nip)['data']['status'], 'status 10 tidak ditolak "NIP not valid" [K]');

        foreach (PenyajiPegawai::METHOD as $method => $pesan) {
            if (in_array($method, ['data_umum', 'detil'], true)) {
                continue;
            }

            $this->assertSame(['status' => '02', 'msg' => $pesan, 'data' => []], $this->emp($method, $nip), $method);
        }
    }

    public function testPemetaanNilaiTeksLegacy(): void
    {
        $nip = $this->pegawaiLengkap();
        $this->db->table('pegawai')->where('nip', $nip)->update(['id_provinsi_lahir' => 99, 'provinsi_lahir_lain' => 'Lahir Lain']);
        $this->assertNull($this->emp('data_umum', $nip)['data']['email'], 'tanpa akun → null');
        $akun = $this->buatAkunUntuk($nip, 6, ['email' => 'pertama@contoh.test']);

        $umum = $this->emp('data_umum', $nip)['data'];
        $this->assertSame(['Perempuan', 'Menikah', 'Aktif', 'Lahir Lain', 'pertama@contoh.test'], [$umum['jenis_kelamin'], $umum['status_pernikahan'], $umum['status'], $umum['provinsi_lahir'], $umum['email']]);
        $this->assertSame('', $umum['foto'], 'berkas foto belum ada di storage v2 → ""');

        $this->db->table('pengguna')->where('id_pengguna', $akun)->update(['status' => '0']);
        $this->assertNull($this->emp('data_umum', $nip)['data']['email'], 'akun nonaktif tidak dipakai [K peng.status=1]');

        $this->assertSame('Provinsi Lain', $this->emp('alamat_rumah', $nip)['data']['provinsi'], 'id 99 → provinsi_lain');
        $this->assertSame(['Anak A', 'Anak B'], array_column($this->emp('keluarga', $nip)['data']['daftar_anak'], 'nama_anak'), 'urut urutan');
        $this->assertSame('45.50', $this->emp('pendidikan_terakhir', $nip)['data']['nilai'], 'jenjang 1/2/3 → NEM');
        $this->assertSame(['Fungsional', 'PIM (Es.III)'], array_values(array_intersect_key($this->emp('riwayat_pelatihan', $nip)['data'][0], array_flip(['jenis_pelatihan', 'nama_pelatihan']))));
        $this->assertSame(['Struktural', 'Diklat X'], array_values(array_intersect_key($this->emp('pelatihan_struktural_terakhir', $nip)['data'], array_flip(['jenis_pelatihan', 'nama_pelatihan']))));
        $this->assertSame('Kursus', $this->emp('riwayat_kursus_seminar', $nip)['data'][0]['jenis']);
        $this->assertSame(['Instansi Lain', 'Tidak Ada', 'Pembebasan Sementara'], array_values(array_intersect_key($this->emp('riwayat_jabatan', $nip)['data'][0], array_flip(['jenis_jabatan', 'jenis_jabatan_koordinasi', 'status_jft']))));
        $this->assertSame('Satyalancana X', $this->emp('riwayat_tj', $nip)['data'][0]['tanda_jasa'], "'LAIN-LAIN' → tanda_jasa_lain");
        $this->assertSame('Kepala Biro', $this->emp('kgb_terakhir', $nip)['data']['jabatan_yang_menandatangani']);
    }

    /**
     * Review P3 / AC-06.1: setiap cabang pemetaan nilai teks legacy (fixture `_nilai_teks`, rujukan baris AE per kasus).
     */
    public function testNilaiTeksSemuaCabangSesuaiFixture(): void
    {
        $nip = $this->pegawaiLengkap();

        foreach ($this->fixtureKontrak('a_employee')['_nilai_teks'] as $k) {
            $this->db->table($k['tabel'])->where('nip', $k['nip'] ?? $nip)->update($k['set']);
            $data  = $this->emp($k['method'], $nip)['data'];
            $baris = array_is_list($data) ? $data[0] : $data;

            $this->assertSame($k['nilai'], $baris[$k['field']], "{$k['method']}.{$k['field']} " . json_encode($k['set']) . " ({$k['_rujukan']})");
        }
    }

    public function testRiwayatHanyaStatus1DanUrutTmt(): void
    {
        $nip = $this->buatPegawai();
        $this->sisip('riwayat_hukdis', ['nip' => $nip, 'status' => 1, 'tmtsk' => '2022-01-01', 'no_sk' => 'B']);
        $this->sisip('riwayat_hukdis', ['nip' => $nip, 'status' => 1, 'tmtsk' => '2021-01-01', 'no_sk' => 'A']);
        $this->sisip('riwayat_hukdis', ['nip' => $nip, 'status' => 0, 'tmtsk' => '2020-01-01', 'no_sk' => 'MENUNGGU']);
        $this->sisip('riwayat_skp', ['nip' => $nip, 'status' => 1, 'tahun' => 2023]);
        $this->sisip('riwayat_skp', ['nip' => $nip, 'status' => 1, 'tahun' => 2025]);

        $this->assertSame(['A', 'B'], array_column($this->emp('riwayat_hukdis', $nip)['data'], 'no_sk'));
        $this->assertSame('2025', $this->emp('skp_terakhir', $nip)['data']['tahun'], 'tahun DESC');
        $this->assertSame(['2023', '2025'], array_column($this->emp('riwayat_skp', $nip)['data'], 'tahun'));
    }

    /**
     * [K] bug legacy B-9, keputusan Horii 04-11 (Q6-36): `detil` tidak memilih nama atasan Es.III/IV (`AE:278`), jadi bagian
     * unit dari atasan selalu "" — hanya unit dari jabatan sendiri (sub group 3/4 atau koordinator/subkoordinator) terisi.
     */
    public function testDetilUnitEs34IkutLegacyB9(): void
    {
        $nip = $this->buatPegawai([], ['id_sub_group_jabatan' => 4, 'jabatan' => 'Kepala Subbagian Umum', 'atasan_es_3' => 'Kepala Bagian Rumah Tangga', 'id_atasan_es_3' => 1]);
        $d   = $this->emp('detil', $nip)['data'];
        $this->assertSame(['', 'Umum'], [$d['unit_es_3'], $d['unit_es_4']], 'Es.IV: unit_es_3 dari atasan tetap "" (B-9)');

        $nip = $this->buatPegawai([], ['id_sub_group_jabatan' => 3, 'jabatan' => 'Kepala Bagian Keuangan']);
        $d   = $this->emp('detil', $nip)['data'];
        $this->assertSame(['Keuangan', ''], [$d['unit_es_3'], $d['unit_es_4']]);

        $nip = $this->buatPegawai([], ['id_sub_group_jabatan' => 5, 'atasan_es_4' => 'Kepala Subbagian Umum', 'id_atasan_es_4' => 1, 'atasan_es_3' => 'Kepala Bagian X', 'id_atasan_es_3' => 2]);
        $d   = $this->emp('detil', $nip)['data'];
        $this->assertSame(['', ''], [$d['unit_es_3'], $d['unit_es_4']], 'pelaksana: unit dari atasan "" (B-9)');
        $this->assertSame([], $d['atasan_langsung'], 'getAL kosong → []');
    }

    public function testTokenKlienNonaktifDitolak(): void
    {
        $nip = $this->buatPegawai();
        $this->assertSame('01', $this->emp('data_umum', $nip)['status']);

        (new \App\Libraries\Integrasi\Klien\PenyimpanKlien($this->db))->ubahStatus('pusdatin', \App\Libraries\Integrasi\Klien\PenyimpanKlien::STATUS_NONAKTIF);

        $this->assertSame(['status' => '02', 'msg' => 'Token is not valid', 'data' => []], $this->emp('data_umum', $nip));
    }

    /**
     * Keputusan Horii (= A): foto lewat endpoint khusus klien SIMAPI `a_employee/foto/{nip}` (token SIMAPI), 404 seragam.
     */
    public function testFotoKhususKlienSimapi(): void
    {
        $storage = new FakeStorageAdapter();
        Services::injectMock('storageAdapter', $storage);
        Services::resetSingle('biodataService');
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);

        $ada = $this->buatPegawai(['foto' => 'pegawai/rahasia-dir/foto asli.png', 'status' => 10]);
        $storage->simpan('pegawai/rahasia-dir/foto asli.png', $png);
        $tanpaBerkas = $this->buatPegawai(['foto' => 'arsip/lama/foto.jpg']);
        $tanpaFoto   = $this->buatPegawai();
        $bukanGambar = $this->buatPegawai(['foto' => 'pegawai/x.png']);
        $storage->simpan('pegawai/x.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $url = $this->emp('data_umum', $ada)['data']['foto'];
        $this->assertStringEndsWith('simapi/v1/a_employee/foto/' . $ada, $url, 'URL absolut endpoint foto SIMAPI');
        $this->assertSame('', $this->emp('data_umum', $tanpaBerkas)['data']['foto'], 'berkas belum dimigrasi → ""');
        $this->assertSame('', $this->emp('data_umum', $tanpaFoto)['data']['foto']);

        $this->assertSame(['status' => '02', 'msg' => 'Token is not valid', 'data' => []], $this->jsonUji($this->tanpaAuthUji('simapi/v1/a_employee/foto/' . $ada)));

        $res = $this->simapiUji('simapi/v1/a_employee/foto/' . $ada, $this->token);
        $res->assertStatus(200);
        $this->assertSame($png, (string) $res->response()->getBody());
        $this->assertStringStartsWith('image/png', $res->response()->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $res->response()->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $res->response()->getHeaderLine('Cache-Control'));
        $this->assertSame('inline; filename="foto_' . $ada . '.png"', $res->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringNotContainsString('rahasia-dir', $res->response()->getHeaderLine('Content-Disposition') . $url, 'path tidak bocor');

        foreach ([$tanpaBerkas, $tanpaFoto, $bukanGambar, '999999999999999999'] as $nip) {
            $r = $this->simapiUji('simapi/v1/a_employee/foto/' . $nip, $this->token);
            $r->assertStatus(404);
            $this->assertSame('', (string) $r->response()->getBody(), '404 seragam tanpa alasan');
        }

        Services::resetSingle('storageAdapter');
        Services::resetSingle('biodataService');
    }

    public function testNamaUnitPortFixUnitName(): void
    {
        $this->assertSame('Umum', PenyajiPegawai::namaUnit('Kepala Subbagian Umum'));
        $this->assertSame('Tata Usaha', PenyajiPegawai::namaUnit('KOORDINATOR Tata Usaha'));
        $this->assertSame('', PenyajiPegawai::namaUnit('Analis Kepegawaian'), 'tanpa awalan → "" [K]');
    }
}
