<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Interfaces\SiasnDorongInterface;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\KonfigurasiIntegrasiTidakLengkap;
use App\Libraries\Integrasi\Log\IntegrasiLog;
use App\Libraries\Siasn\Dorong\DorongJabatan;
use App\Libraries\Siasn\Dorong\DorongSiasn;
use App\Libraries\Siasn\SiasnAdapter;
use App\Libraries\Siasn\Tarik\SiasnTarikSibuk;
use App\Libraries\Siasn\Tarik\SiasnTidakSiap;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Config\Integrations;
use Config\Services;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SiasnUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-067 M2 (H-03, F6-14; PRD Fase 6 §7.3, CR-090) — `siasn:dorong` dengan FakeTransport (tanpa jaringan), fixture
 * sintetis: kriteria per tabel (jabatan dorong ulang bila diubah; diklat/sertifikasi/kursus/SKP 2022 sekali), payload
 * ikut legacy, POST tidak di-retry, id hanya dari balasan sukses, `siasn_error` tanpa id PNS/NIP, validasi sebelum kirim,
 * hasil ditulis per baris, penanda proses jabatan tidak menggeser `updated_at`, dry-run, kunci, pemulihan saat exception.
 *
 * @internal
 */
final class SiasnDorongTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SiasnUjiTrait;
    use StreamFilterTrait;

    private const NIP_A = '199001012020011001';

    private const NIP_B = '199001012020011002';

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStreamFilterBuffer();
        $this->siapkanSiasn();
        config(Integrations::class)->bkn = ['siasnInstansiId' => 'INS-UJI', 'siasnSatkerId' => 'SAT-UJI', 'siasnUnorBawaan' => 'UNOR-BAWAAN'] + config(Integrations::class)->bkn;
    }

    protected function tearDown(): void
    {
        $this->pulihkanSiasn();
        parent::tearDown();
    }

    public function testKursusSuksesTidakSahGagalTanpaRetryDanPesanTanpaIdPns(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1, 'id_pns_siasn' => 'PNS-UJI-A']);
        $this->buatPegawai(['nip' => self::NIP_B, 'id_jenis_pegawai' => 1]);
        $sukses   = $this->seminar(self::NIP_A, ['jenis_seminar' => 1, 'tgl_sertifikat' => '2024-03-05', 'jumlah_jp' => 0, 'keterangan' => 'Lokal']);
        $tanpaTgl = $this->seminar(self::NIP_A, ['tgl_sertifikat' => null]);
        $gagal    = $this->seminar(self::NIP_A, ['tgl_sertifikat' => '2023-01-02', 'no_sertifikat' => 'NO-9']);
        $sudah    = $this->seminar(self::NIP_A, ['tgl_sertifikat' => '2023-01-02', 'id_rw_siasn' => 'K-LAMA', 'siasn_flag' => 1]);
        $tanpaPns = $this->seminar(self::NIP_B, ['tgl_sertifikat' => '2023-01-02']);

        $this->antreToken();
        // Urutan pilih legacy: id DESC → $gagal, $tanpaTgl, $sukses.
        $this->transport->antreJson(500, ['message' => 'Gagal menyimpan untuk PNS-UJI-A']);
        $this->transport->antreJson(200, ['success' => true, 'mapData' => ['rwKursusId' => 'K-BARU']]);

        $h = $this->dorong()->jalankan('kursus');

        $this->assertSame(['baris' => 3, 'terkirim' => 2, 'sukses' => 1, 'gagal' => 1, 'tidak_sah' => 1, 'sukses_tanpa_id' => 0], array_intersect_key($h, array_flip(['baris', 'terkirim', 'sukses', 'gagal', 'tidak_sah', 'sukses_tanpa_id'])));
        $this->assertCount(4, $this->transport->tercatat(), '2 token + 2 POST: POST 500 TIDAK di-retry (BP-3)');

        $rw = array_column($this->db->table('riwayat_seminar')->get()->getResultArray(), null, 'id_riwayat_seminar');
        $this->assertSame(['K-BARU', '1', 'Lokal **Upload To SIASN**', (string) $this->idAkun], [$rw[$sukses]['id_rw_siasn'], (string) $rw[$sukses]['siasn_flag'], $rw[$sukses]['keterangan'], (string) $rw[$sukses]['updated_by']]);
        $this->assertSame(['2', 'Data tidak lengkap: tgl_sertifikat'], [(string) $rw[$tanpaTgl]['siasn_flag'], $rw[$tanpaTgl]['siasn_error']]);
        $this->assertSame(['2', null, 'HTTP 500: Gagal menyimpan untuk ***'], [(string) $rw[$gagal]['siasn_flag'], $rw[$gagal]['id_rw_siasn'], $rw[$gagal]['siasn_error']]);
        $this->assertSame(['K-LAMA', '0'], [$rw[$sudah]['id_rw_siasn'], (string) $rw[$tanpaPns]['siasn_flag']], 'sudah terkirim / tanpa id PNS tidak dipilih');

        $kirim = $this->transport->tercatat()[3]['permintaan'];
        $this->assertSame(['POST', self::DASAR_SIASN . '/kursus/save'], [$kirim->metode, $kirim->url]);
        // Header postApi_siasn (ekstraksi 04-11): Auth + Authorization + Content-Type JSON + accept.
        $this->assertSame(['application/json', 'application/json'], [$kirim->header['Content-Type'] ?? null, $kirim->header['accept'] ?? null]);
        $this->assertStringStartsWith('bearer ', $kirim->header['Auth'] ?? '');
        $this->assertStringStartsWith('Bearer ', $kirim->header['Authorization'] ?? '');
        $this->assertSame(['9', 'SEMINAR/WORKSHOP/SEJENISNYA', 30, 'SERT/2024', 'PNS-UJI-A', 2024, '05-03-2024', 'KEMENPAR'], [
            $kirim->json['jenisDiklatId'], $kirim->json['jenisKursusSertipikat'], $kirim->json['jumlahJam'], $kirim->json['nomorSertipikat'],
            $kirim->json['pnsOrangId'], $kirim->json['tahunKursus'], $kirim->json['tanggalKursus'], $kirim->json['institusiPenyelenggara'],
        ]);

        $log = (string) json_encode($this->db->table('integrasi_log')->get()->getResultArray());
        $this->assertStringNotContainsString('PNS-UJI-A', $log, 'id PNS disensor dari log');
        $this->assertStringContainsString('baris 3, terkirim 2, sukses 1, gagal 1, tidak_sah 1', (string) $this->db->table('integrasi_log')->where('operasi', 'dorong_kursus')->get()->getRow()->pesan);

        // CR-090: gagal (flag 2) tidak didorong ulang untuk kursus.
        $this->assertSame(0, $this->dorong()->jalankan('kursus')['baris']);
    }

    public function testDiklatSertifikasiSkp22PayloadDanAturanPilih(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1, 'id_pns_siasn' => 'PNS-UJI-A']);
        $this->buatPegawai(['nip' => self::NIP_B, 'id_jenis_pegawai' => 1, 'id_pns_siasn' => 'PNS-UJI-B']);
        $this->db->table('siasn_referensi')->insertBatch([
            ['jenis' => 'latihan_struktural', 'id_siasn' => 'LAT-IV', 'id_lokal' => '1'],
            ['jenis' => 'golongan', 'id_siasn' => 'GOL-34', 'id_lokal' => '15'],
        ]);
        $d1 = $this->diklat(self::NIP_A, ['jenis_diklat' => 1, 'id_diklat' => 1, 'tgl_sertifikat' => '2021-06-30', 'keterangan' => null]);
        $this->diklat(self::NIP_A, ['jenis_diklat' => 1, 'id_diklat' => 1, 'tgl_sertifikat' => '2021-06-30', 'keterangan' => 'Sync SIASN']);
        $this->db->table('rumpun_sertifikasi')->insert(['id_rumpun_sertifikasi' => 7, 'rumpun_sertifikasi' => 'Rumpun Uji', 'status' => 1]);
        $this->db->table('lembaga_sertifikasi')->insert(['id_lembaga_sertifikasi' => 9, 'lembaga_sertifikasi' => 'Lembaga Uji', 'status' => 1]);
        $s1 = $this->diklat(self::NIP_A, ['jenis_diklat' => 5, 'id_rumpun_sertifikasi' => 7, 'id_lembaga_sertifikasi' => 9, 'tgl_sertifikat' => '2022-01-10', 'mb_sertifikat_awal' => '2022-01-10', 'mb_sertifikat_akhir' => '2025-01-09', 'nama_diklat' => 'Sertifikasi Uji', 'no_sertifikat' => 'S-1']);
        $s2 = $this->diklat(self::NIP_A, ['jenis_diklat' => 5, 'id_rumpun_sertifikasi' => 7, 'id_lembaga_sertifikasi' => 9, 'tgl_sertifikat' => '2022-01-10', 'mb_sertifikat_awal' => null, 'mb_sertifikat_akhir' => '2025-01-09']);
        $this->db->table('pegawai_kp')->insert(['nip' => self::NIP_B, 'id_pangkat' => 15, 'tmtsk' => '2020-01-01', 'tgl_sk' => '2020-01-01', 'jenis_kp' => 'Reguler', 'gol' => 'III', 'ruang' => 'c', 'gol_ruang' => 'III/c', 'pangkat' => 'Penata']);
        $k1 = $this->skp22(self::NIP_A, ['rating_skp' => 3, 'rating_perilaku' => 1, 'kategori_nilai_prestasi' => 'Sangat Baik', 'nip_atasan_penilai' => self::NIP_B, 'nama_atasan_penilai' => 'Atasan Uji', 'jabatan_atasan_penilai' => 'Kepala Uji']);
        $k2 = $this->skp22(self::NIP_A, ['rating_skp' => 2, 'rating_perilaku' => 2, 'kategori_nilai_prestasi' => 'Istimewa']);

        $this->antreToken();
        $this->transport->antreJson(200, ['success' => true, 'mapData' => ['rwDiklatId' => 'D-BARU']]);
        $this->transport->antreJson(200, ['success' => true, 'mapData' => ['rwSertifikasiId' => 'S-BARU']]);
        $this->transport->antreJson(200, ['success' => true, 'mapData' => 'SKP-BARU']);

        $this->assertSame([1, 1], $this->ringkas($this->dorong()->jalankan('diklat'), ['baris', 'sukses']), 'keterangan NULL ikut, "Sync SIASN" tidak (usulan Q3)');
        $this->assertSame([2, 1, 1], $this->ringkas($this->dorong()->jalankan('sertifikasi'), ['baris', 'sukses', 'tidak_sah']));
        $this->assertSame([2, 1, 1], $this->ringkas($this->dorong()->jalankan('skp22'), ['baris', 'sukses', 'tidak_sah']));

        $kirim = array_values(array_filter($this->transport->tercatat(), static fn (array $t): bool => $t['permintaan']->metode === 'POST' && str_ends_with($t['permintaan']->url, '/save')));
        $this->assertSame(['LAT-IV', 360, 'DIKLATPIM/2021', 'LAN RI', '30-06-2021'], [$kirim[0]['permintaan']->json['latihanStrukturalId'], $kirim[0]['permintaan']->json['jumlahJam'], $kirim[0]['permintaan']->json['nomor'], $kirim[0]['permintaan']->json['institusiPenyelenggara'], $kirim[0]['permintaan']->json['tanggal']]);
        $this->assertSame(['9', 'Lembaga Uji', '7', '10-01-2022', '09-01-2025'], [$kirim[1]['permintaan']->json['lembagaSertifikasiId'], $kirim[1]['permintaan']->json['lembagaSertifikasiNama'], $kirim[1]['permintaan']->json['rumpunJabatanId'], $kirim[1]['permintaan']->json['masaBerlakuSertMulai'], $kirim[1]['permintaan']->json['masaBerlakuSertSelasai']]);
        $this->assertSame([1, 3, 1, 'GOL-34', 'PNS-UJI-A', 'ASN'], [$kirim[2]['permintaan']->json['hasilKinerjaNilai'], $kirim[2]['permintaan']->json['perilakuKerjaNilai'], $kirim[2]['permintaan']->json['kuadranKinerjaNilai'], $kirim[2]['permintaan']->json['penilaiGolongan'], $kirim[2]['permintaan']->json['pnsDinilaiOrang'], $kirim[2]['permintaan']->json['statusPenilai']]);

        $this->assertSame('D-BARU', $this->db->table('riwayat_diklat')->where('id_riwayat_diklat', $d1)->get()->getRow()->id_rw_siasn);
        $this->assertSame('S-BARU', $this->db->table('riwayat_diklat')->where('id_riwayat_diklat', $s1)->get()->getRow()->id_rw_siasn);
        $this->assertSame('Data tidak lengkap: mb_sertifikat_awal', $this->db->table('riwayat_diklat')->where('id_riwayat_diklat', $s2)->get()->getRow()->siasn_error);
        $this->assertSame('SKP-BARU', $this->db->table('riwayat_skp')->where('id_riwayat_skp', $k1)->get()->getRow()->id_rw_siasn, 'id skp22 = mapData');
        $k2r = $this->db->table('riwayat_skp')->where('id_riwayat_skp', $k2)->get()->getRow();
        $this->assertSame(['3', 'Data Invalid, kategori_nilai_prestasi:Istimewa'], [(string) $k2r->siasn_flag, $k2r->siasn_error], '[K Rw_skp:599-607]');
    }

    public function testJabatanKriteriaDorongUlangPayloadDanStempel(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1, 'id_pns_siasn' => 'PNS-UJI-A']);
        $jab = $this->buatJabatan();
        $this->db->table('jabatan')->where('id_jabatan', $jab['id_jabatan'])->update(['id_group_jabatan' => 2]);
        $this->db->table('siasn_referensi')->insertBatch([
            ['jenis' => 'jabatan_fungsional', 'id_siasn' => 'JFT-SDM', 'nama' => 'Analis Sumber Daya Manusia Aparatur', 'id_lokal' => null],
            ['jenis' => 'jenis_jabatan', 'id_siasn' => 'JJ-2', 'nama' => null, 'id_lokal' => '2'],
        ]);
        $this->db->table('siasn_ref_unor')->insert(['id' => 'UNOR-JAB', 'id_jabatan' => $jab['id_jabatan'], 'eselon_id' => 'ES-X', 'instansi_id' => 'INS-UNOR']);

        $baru    = $this->rmj(['id_jabatan' => $jab['id_jabatan'], 'id_group_jabatan' => 2, 'sub_group_jabatan' => 'Analis SDM Aparatur', 'jabatan' => 'Analis', 'jenis_mutasi' => 1, 'tmtsk' => '2021-02-01', 'tgl_sk' => '2021-01-20']);
        $diubah  = $this->rmj(['id_group_jabatan' => 3, 'jabatan' => 'pns tugas belajar s2', 'id_rw_siasn' => 'RJ-LAMA', 'siasn_flag' => 1, 'siasn_lu' => '2026-01-01 00:00:00', 'updated_at' => '2026-02-01 00:00:00', 'jenis_mutasi' => 2]);
        $sinkron = $this->rmj(['id_rw_siasn' => 'RJ-OK', 'siasn_flag' => 1, 'siasn_lu' => '2026-02-01 00:00:00', 'updated_at' => '2026-02-01 00:00:00']);
        $this->rmj(['tmtsk' => '2019-12-31']);
        $this->buatSatker(null, ['id_satker' => DorongJabatan::SATKER_DIKECUALIKAN]);
        $this->rmj(['id_satker' => DorongJabatan::SATKER_DIKECUALIKAN]);

        $this->antreToken();
        $this->transport->antreJson(200, ['success' => true, 'mapData' => ['rwJabatanId' => 'RJ-BARU']]);
        $this->transport->antreJson(500, ['message' => 'Internal Server Error']);

        $h = $this->dorong('2026-11-04 03:00:00')->jalankan('jabatan');

        $this->assertSame([2, 1, 1], $this->ringkas($h, ['baris', 'sukses', 'gagal']), 'baru + diubah; sinkron/TMT<2020/satker 171 tidak');
        $rw = array_column($this->db->table('riwayat_mutasi_jabatan')->get()->getResultArray(), null, 'id_riwayat_mutasi_jabatan');
        $this->assertSame(['RJ-BARU', '1', '2026-11-04 03:00:00', '2026-11-04 03:00:00'], [$rw[$baru]['id_rw_siasn'], (string) $rw[$baru]['siasn_flag'], $rw[$baru]['siasn_lu'], $rw[$baru]['updated_at']], 'sukses → siasn_lu = updated_at [K]');
        $this->assertSame(['RJ-LAMA', '3', 'Internal Server Error'], [$rw[$diubah]['id_rw_siasn'], (string) $rw[$diubah]['siasn_flag'], $rw[$diubah]['siasn_error']], 'ISE → flag 3, id tetap [K :311-316]');
        $this->assertSame(['1', '2026-02-01 00:00:00'], [(string) $rw[$sinkron]['siasn_flag'], $rw[$sinkron]['updated_at']], 'baris lain tidak tersentuh');

        $kirim = array_values(array_filter($this->transport->tercatat(), static fn (array $t): bool => str_ends_with($t['permintaan']->url, '/jabatan/unorjabatan/save')));
        $p1    = $kirim[0]['permintaan']->json;
        $this->assertSame(['JFT-SDM', 'JJ-2', 'UNOR-JAB', 'INS-UNOR', 'SAT-UJI', 'ES-X', 'MJ', '01-02-2021', '01-02-2021', '', '20-01-2021', null], [
            $p1['jabatanFungsionalId'], $p1['jenisJabatan'], $p1['unorId'], $p1['instansiId'], $p1['satuanKerjaId'], $p1['eselonId'], $p1['jenisMutasiId'],
            $p1['tmtJabatan'], $p1['tmtPelantikan'], $p1['tmtMutasi'], $p1['tanggalSk'], $p1['id'],
        ]);
        $p2 = $kirim[1]['permintaan']->json;
        $this->assertSame(['RJ-LAMA', 'MU', 'UNOR-BAWAAN', 'INS-UJI', '4', null], [$p2['id'], $p2['jenisMutasiId'], $p2['unorId'], $p2['instansiId'], $p2['jenisJabatan'], $p2['jabatanFungsionalUmumId']], 'unor/instansi bawaan dari .env, jenis jabatan bawaan 4');

        // Gagal ISE (flag 3) diulang hanya setelah 6 jam.
        $this->assertSame(0, $this->dorong('2026-11-04 08:59:00')->jalankan('jabatan')['baris']);
        $this->transport->antreJson(200, ['success' => true, 'mapData' => ['rwJabatanId' => 'RJ-LAMA']]);
        $this->assertSame(1, $this->dorong('2026-11-04 09:01:00')->jalankan('jabatan')['sukses']);
    }

    public function testJabatanTanpaKonfigurasiInstansiGagalCepat(): void
    {
        config(Integrations::class)->bkn = ['siasnSatkerId' => ''] + config(Integrations::class)->bkn;
        $this->expectException(KonfigurasiIntegrasiTidakLengkap::class);
        $this->dorong()->jalankan('jabatan');
    }

    public function testPenandaProsesTidakMenggeserUpdatedAtDanDipulihkanSaatException(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1, 'id_pns_siasn' => 'PNS-UJI-A']);
        $r1 = $this->rmj(['tmtsk' => '2021-01-01']);
        $r2 = $this->rmj(['tmtsk' => '2022-01-01']);
        // r3: pernah terkirim lalu gagal lama (flag 2, siasn_lu = updated_at) — dipilih lewat aturan "gagal > 6 jam".
        $r3 = $this->rmj(['tmtsk' => '2023-01-01', 'id_rw_siasn' => 'RJ-3', 'siasn_flag' => 2, 'siasn_lu' => '2026-03-01 00:00:00']);
        $this->db->table('riwayat_mutasi_jabatan')->whereIn('id_riwayat_mutasi_jabatan', [$r1, $r2, $r3])->set('updated_at', '2026-03-01 00:00:00')->update();

        $putus = new class () implements SiasnDorongInterface {
            public int $ke = 0;

            public function simpanRiwayat(string $jalur, array $payload, array $rahasia = [], ?string $refId = null): array
            {
                if (++$this->ke === 2) {
                    throw new RuntimeException('proses terhenti');
                }

                return ['status_code' => '200', 'message' => 'OK', 'data' => ['rwJabatanId' => 'RJ-1']];
            }

            public function unggahDokumen(string $idRiwayatSiasn, string $idRefDokumen, string $isi, string $namaBerkas, array $rahasia = []): array
            {
                throw new RuntimeException('tidak dipakai');
            }
        };
        $dorong = new DorongSiasn($this->db, $putus, new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db), DorongSiasn::semuaJenis());

        try {
            $dorong->jalankan('jabatan');
            $this->fail('exception harus dilempar ulang');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $rw = array_column($this->db->table('riwayat_mutasi_jabatan')->get()->getResultArray(), null, 'id_riwayat_mutasi_jabatan');
        $this->assertSame(['RJ-1', '1'], [$rw[$r1]['id_rw_siasn'], (string) $rw[$r1]['siasn_flag']], 'POST sukses sebelum terhenti tetap tercatat (tulis per baris)');
        $this->assertSame(['0', '2026-03-01 00:00:00'], [(string) $rw[$r2]['siasn_flag'], $rw[$r2]['updated_at']], 'penanda proses dipulihkan, updated_at tidak bergeser');
        $this->assertSame(['2', '2026-03-01 00:00:00', '2026-03-01 00:00:00'], [(string) $rw[$r3]['siasn_flag'], $rw[$r3]['siasn_lu'], $rw[$r3]['updated_at']]);
        $this->assertSame(0, $this->db->table('riwayat_mutasi_jabatan')->where('id_riwayat_mutasi_jabatan', $r3)->where('siasn_lu < updated_at', null, false)->countAllResults(), 'penanda flag 3 tidak membuat siasn_lu < updated_at benar palsu (dorong ulang palsu)');
        $this->assertSame('galat', $this->db->table('integrasi_log')->where('operasi', 'dorong_jabatan')->get()->getRow()->kode);
    }

    public function testTimeoutPostSekaliDryRunKunciDanDriver(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1, 'id_pns_siasn' => 'PNS-UJI-A']);
        $s = $this->seminar(self::NIP_A, ['tgl_sertifikat' => '2024-01-01']);

        // Dry-run: tanpa POST dan tanpa tulis.
        $d = $this->dorong()->jalankan('kursus', 20, null, true);
        $this->assertSame([true, 1, 1], [$d['dry_run'], $d['baris'], $d['terkirim']]);
        $this->assertSame([], $this->transport->tercatat());
        $this->assertSame('0', (string) $this->db->table('riwayat_seminar')->where('id_riwayat_seminar', $s)->get()->getRow()->siasn_flag);

        // Kunci dipegang proses lain → ditolak tanpa POST.
        $lain  = Database::connect('tests', false);
        $kunci = DorongSiasn::namaKunci($this->db, 'kursus');

        try {
            $lain->query('SELECT GET_LOCK(?, 0)', [$kunci]);

            try {
                $this->dorong()->jalankan('kursus');
                $this->fail('dorong kedua harus ditolak');
            } catch (SiasnTarikSibuk) {
                $this->assertSame([], $this->transport->tercatat(), 'tanpa POST');
            }
        } finally {
            $lain->query('SELECT RELEASE_LOCK(?)', [$kunci]);
            $lain->close();
        }

        // Timeout POST: satu percobaan saja, status SIASN tidak diketahui.
        $this->antreToken();
        $this->transport->antre(new GalatTransport('putus', true));
        $h = $this->dorong()->jalankan('kursus');
        $this->assertSame([1, 1], [$h['gagal'], $h['status_tidak_diketahui']], 'putusan Q9: INFO status_tidak_diketahui');
        $this->assertStringContainsString('status_tidak_diketahui 1', (string) $this->db->table('integrasi_log')->where('operasi', 'dorong_kursus')->get()->getRow()->pesan);
        $this->assertCount(3, $this->transport->tercatat(), '2 token + 1 POST (tanpa retry)');
        $this->assertSame('Gagal terhubung (timeout); status di SIASN tidak diketahui.', $this->db->table('riwayat_seminar')->where('id_riwayat_seminar', $s)->get()->getRow()->siasn_error);

        config(Integrations::class)->siasn = ['driver' => 'mock'] + config(Integrations::class)->siasn;
        $this->expectException(SiasnTidakSiap::class);
        DorongSiasn::dariLayanan();
    }

    public function testCommandOpsiSalahDanDryRun(): void
    {
        Services::injectMock('siasnGateway', $this->adapter);

        foreach (['', '--jenis=pmk', '--jenis=kursus --limit=600'] as $salah) {
            $this->resetStreamFilterBuffer();
            command(trim("siasn:dorong {$salah}"));
            $this->assertStringContainsString('Pemakaian:', $this->getStreamFilterBuffer(), $salah);
        }

        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1, 'id_pns_siasn' => 'PNS-UJI-A']);
        $this->seminar(self::NIP_A, ['tgl_sertifikat' => '2024-01-01']);
        $this->resetStreamFilterBuffer();
        command('siasn:dorong --jenis kursus --dry-run');
        $keluar = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('[dry-run] Dorong SIASN kursus: baris 1; akan dikirim 1', $keluar);
        $this->assertStringNotContainsString(self::NIP_A, $keluar);
    }

    public function testUraiSimpanHanyaSuccessTrue(): void
    {
        $this->assertSame('200', SiasnAdapter::uraiSimpan(new Respons(200, '{"success":true,"mapData":{"rwKursusId":"K"}}'))['status_code']);
        // Truthy legacy (ekstraksi 04-11): hanya true/"true"/1/"1".
        foreach (['true', '"true"', '1', '"1"'] as $ya) {
            $this->assertSame('200', SiasnAdapter::uraiSimpan(new Respons(200, '{"success":' . $ya . ',"mapData":{}}'))['status_code'], $ya);
        }

        foreach (['false', '"false"', '0', '"0"', '"ya"', '2', 'null', '""'] as $tidak) {
            $this->assertSame('respons', SiasnAdapter::uraiSimpan(new Respons(200, '{"success":' . $tidak . ',"mapData":{}}'))['status_code'], $tidak);
        }

        $this->assertSame('302', SiasnAdapter::uraiSimpan(new Respons(302, ''))['status_code'], '3xx = gagal (redirect tidak diikuti)');
        $this->assertSame('respons', SiasnAdapter::uraiSimpan(new Respons(200, '{"success":false,"message":"NIP salah"}'))['status_code']);
        $this->assertSame('respons', SiasnAdapter::uraiSimpan(new Respons(200, '<html>'))['status_code']);
        $this->assertSame(['422', 'Validasi gagal'], array_values(array_intersect_key(SiasnAdapter::uraiSimpan(new Respons(422, '{"message":"Validasi gagal"}')), ['status_code' => 1, 'message' => 1])));
    }

    private function dorong(?string $jam = null): DorongSiasn
    {
        return new DorongSiasn($this->db, $this->adapter, new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db), DorongSiasn::semuaJenis(), $jam === null ? null : static fn (): string => $jam);
    }

    /**
     * @param array<string, mixed> $h
     * @param list<string>         $kunci
     *
     * @return list<mixed>
     */
    private function ringkas(array $h, array $kunci): array
    {
        return array_map(static fn (string $k): mixed => $h[$k], $kunci);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function seminar(string $nip, array $data): int
    {
        $this->db->table('riwayat_seminar')->insert($data + ['nip' => $nip, 'jenis_seminar' => 2, 'nama_seminar' => 'Seminar Uji', 'status' => 1, 'siasn_flag' => 0]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function diklat(string $nip, array $data): int
    {
        $this->db->table('riwayat_diklat')->insert($data + ['nip' => $nip, 'status' => 1, 'siasn_flag' => 0]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function skp22(string $nip, array $data): int
    {
        $this->db->table('riwayat_skp')->insert($data + ['nip' => $nip, 'tahun' => 2022, 'status' => 1, 'siasn_flag' => 0]);

        return (int) $this->db->insertID();
    }

    /**
     * Riwayat jabatan PNS NIP_A (satker sama dengan `pegawai_mutasi_jabatan` fixture).
     *
     * @param array<string, mixed> $data
     */
    private function rmj(array $data): int
    {
        $pmj = $this->db->table('pegawai_mutasi_jabatan')->where('nip', self::NIP_A)->get()->getRowArray();
        $this->db->table('riwayat_mutasi_jabatan')->insert($data + [
            'nip'              => self::NIP_A,
            'id_group_jabatan' => 1,
            'id_jabatan'       => $pmj['id_jabatan'],
            'id_unit'          => $pmj['id_unit'],
            'id_satker'        => $pmj['id_satker'],
            'jenis_jabatan'    => 1,
            'jenis_mutasi'     => 1,
            'tmtsk'            => '2021-01-01',
            'no_sk'            => 'SK-JAB-UJI',
            'status'           => 1,
            'siasn_flag'       => 0,
        ]);

        return (int) $this->db->insertID();
    }
}
