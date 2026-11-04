<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Interfaces\SiasnDorongInterface;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\Log\IntegrasiLog;
use App\Libraries\Siasn\Dorong\DorongDasar;
use App\Libraries\Siasn\Dorong\DorongKursus;
use App\Libraries\Siasn\Dorong\DorongSiasn;
use App\Libraries\Siasn\Dorong\JenisDorongSiasn;
use App\Libraries\Siasn\Dorong\SiasnTokenGagal;
use App\Libraries\Siasn\SiasnAdapter;
use CodeIgniter\Database\BaseConnection;
use Config\Integrations;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SiasnUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-067 M2 — pengaman `siasn:dorong` dari review kode B6 M2 (fixture sintetis, FakeTransport):
 * B-1 hasil POST sukses tidak pernah memicu POST ulang (keterangan per byte, data "tidak tersimpan"), P-1 id jabatan lama
 * dipertahankan, P-2 edit pengguna selama batch tetap terdorong ulang, P-3 galat token/401 menghentikan batch tanpa tulis,
 * P-4 `siasn_error` per byte, 401→200 / 401→401, kunci dilepas sesudah exception, NIP disensor, `uraiSimpan`, payload jabatan
 * & sertifikasi non-PNS.
 *
 * @internal
 */
final class SiasnDorongPengamanTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SiasnUjiTrait;

    private const NIP_A = '199001012020011001';

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSiasn();
        config(Integrations::class)->bkn = ['siasnInstansiId' => 'INS-UJI', 'siasnSatkerId' => 'SAT-UJI', 'siasnUnorBawaan' => 'UNOR-BAWAAN'] + config(Integrations::class)->bkn;
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1, 'id_pns_siasn' => 'PNS-UJI-A']);
    }

    protected function tearDown(): void
    {
        $this->pulihkanSiasn();
        parent::tearDown();
    }

    public function testKeterangan250ByteTanpaPenandaDanSiklusKeduaTanpaPost(): void
    {
        $id = $this->seminar(['keterangan' => str_repeat('k', 250)]);
        $this->antreToken();
        $this->transport->antreJson(200, ['success' => true, 'mapData' => ['rwKursusId' => 'K-1']]);

        $this->assertSame(1, $this->dorong()->jalankan('kursus')['sukses']);
        $r = $this->db->table('riwayat_seminar')->where('id_riwayat_seminar', $id)->get()->getRow();
        $this->assertSame(['K-1', '1', str_repeat('k', 250)], [$r->id_rw_siasn, (string) $r->siasn_flag, $r->keterangan], 'penanda tidak muat → dilewati (TINYTEXT 255 byte)');

        $jumlah = count($this->transport->tercatat());
        $this->assertSame(0, $this->dorong()->jalankan('kursus')['baris']);
        $this->assertCount($jumlah, $this->transport->tercatat(), 'siklus kedua TANPA POST');

        // Penanda tidak ditambah ulang (saran #5).
        $this->assertSame('Lokal ' . DorongDasar::PENANDA, (new DorongKursus())->dataSukses(['keterangan' => 'Lokal ' . DorongDasar::PENANDA], 'X', 1, 'x')['keterangan']);
    }

    public function testHasilSuksesTidakTersimpanDitandaiTidakTerpilihLagiDanDicatat(): void
    {
        $id = $this->seminar();
        $this->antreToken();
        $this->transport->antreJson(200, ['success' => true, 'mapData' => ['rwKursusId' => 'K-RUSAK']]);

        $h = $this->dorong(['kursus' => $this->kursusRusak()])->jalankan('kursus');

        $this->assertSame(1, $h['sukses']);
        $r = $this->db->table('riwayat_seminar')->where('id_riwayat_seminar', $id)->get()->getRow();
        $this->assertSame(['K-RUSAK', '3', DorongDasar::PESAN_TIDAK_TERSIMPAN], [$r->id_rw_siasn, (string) $r->siasn_flag, $r->siasn_error]);
        $log = $this->db->table('integrasi_log')->where('kode', 'hasil_tidak_tersimpan')->get()->getRow();
        $this->assertStringContainsString("riwayat_seminar:{$id} id_siasn K-RUSAK", (string) $log->pesan);
        $this->assertStringNotContainsString(self::NIP_A, (string) json_encode($log));

        $jumlah = count($this->transport->tercatat());
        $this->assertSame(0, $this->dorong()->jalankan('kursus')['baris'], 'tidak terpilih lagi → tanpa duplikat di BKN');
        $this->assertCount($jumlah, $this->transport->tercatat());
    }

    public function testGagalKirimTidakTersimpanSekaliDorongTidakTerpilihLagiDanJabatanTunduk6Jam(): void
    {
        // Review B6 M3 P-1: balasan gagal (5xx) lalu tulis gagal → baris TIDAK dipulihkan ke flag asal (POST sudah terjadi).
        $k = $this->seminar();
        $this->antreToken();
        $this->transport->antre(new Respons(503, 'x'));

        $h = $this->dorong(['kursus' => $this->rusak(new DorongKursus(), false, true)])->jalankan('kursus');

        $this->assertSame([1, 1], [$h['gagal'], $h['tidak_tersimpan']]);
        $r = $this->db->table('riwayat_seminar')->where('id_riwayat_seminar', $k)->get()->getRow();
        $this->assertSame(['3', DorongDasar::PESAN_TIDAK_TERSIMPAN, null], [(string) $r->siasn_flag, $r->siasn_error, $r->id_rw_siasn]);
        $this->assertSame(1, $this->db->table('integrasi_log')->where(['kode' => 'hasil_tidak_tersimpan', 'ref_id' => "riwayat_seminar:{$k}"])->countAllResults());
        $this->assertStringContainsString('tidak_tersimpan 1', (string) $this->db->table('integrasi_log')->where(['operasi' => 'dorong_kursus', 'kode' => 'sebagian_gagal'])->get()->getRow()->pesan, 'saran #13');

        $jumlah = count($this->transport->tercatat());
        $this->assertSame(0, $this->dorong()->jalankan('kursus')['baris'], 'tidak terpilih lagi → tanpa POST ulang');
        $this->assertCount($jumlah, $this->transport->tercatat());

        // Jabatan: tetap flag 3 dengan siasn_lu = sekarang → baru dipilih lagi setelah 6 jam [K] (siasn_lu NULL = langsung).
        $j = $this->rmj([]);
        $this->transport->antre(new Respons(500, 'x'));
        $h = $this->dorong(['jabatan' => $this->rusak(DorongSiasn::semuaJenis()['jabatan'], false, true)], '2026-11-04 01:00:00')->jalankan('jabatan');

        $this->assertSame([1, 1], [$h['gagal'], $h['tidak_tersimpan']]);
        $r = $this->db->table('riwayat_mutasi_jabatan')->where('id_riwayat_mutasi_jabatan', $j)->get()->getRow();
        $this->assertSame(['3', '2026-11-04 01:00:00', DorongDasar::PESAN_TIDAK_TERSIMPAN], [(string) $r->siasn_flag, $r->siasn_lu, $r->siasn_error]);

        $jumlah = count($this->transport->tercatat());
        $this->assertSame(0, $this->dorong(null, '2026-11-04 06:59:00')->jalankan('jabatan')['baris'], '< 6 jam tidak dipilih');
        $this->assertCount($jumlah, $this->transport->tercatat());
        $this->assertSame(1, $this->dorong(null, '2026-11-04 07:01:00')->jalankan('jabatan', 20, null, true)['baris'], 'aturan 6 jam [K]');
    }

    public function testJabatanSuksesTanpaIdMempertahankanIdLamaDanEditSelamaBatchTerdorongUlang(): void
    {
        $lama = $this->rmj(['id_rw_siasn' => 'RJ-LAMA', 'siasn_flag' => 1, 'siasn_lu' => '2026-01-01 00:00:00', 'updated_at' => '2026-02-01 00:00:00']);
        $this->antreToken();
        $this->transport->antreJson(200, ['success' => true, 'mapData' => []]);

        $h = $this->dorong()->jalankan('jabatan');
        $this->assertSame([1, 1], [$h['sukses'], $h['sukses_tanpa_id']]);
        $this->assertSame('RJ-LAMA', $this->db->table('riwayat_mutasi_jabatan')->where('id_riwayat_mutasi_jabatan', $lama)->get()->getRow()->id_rw_siasn, 'P-1');

        // P-2: pengguna mengubah riwayat saat POST berjalan.
        $edit = $this->rmj(['tmtsk' => '2023-05-01']);
        $this->db->table('riwayat_mutasi_jabatan')->where('id_riwayat_mutasi_jabatan', $edit)->set('updated_at', '2026-03-01 00:00:00')->update();
        $db   = $this->db;
        $stub = $this->stub(static function () use ($db, $edit): array {
            $db->table('riwayat_mutasi_jabatan')->where('id_riwayat_mutasi_jabatan', $edit)->set('updated_at', '2026-03-01 00:05:00')->update();

            return ['status_code' => '200', 'message' => 'OK', 'data' => ['rwJabatanId' => 'RJ-EDIT']];
        });
        $this->dorongDengan($stub, '2026-03-01 00:10:00')->jalankan('jabatan', 20, null);

        $r = $this->db->table('riwayat_mutasi_jabatan')->where('id_riwayat_mutasi_jabatan', $edit)->get()->getRow();
        $this->assertSame(['RJ-EDIT', '1', '2026-03-01 00:00:00', '2026-03-01 00:05:00'], [$r->id_rw_siasn, (string) $r->siasn_flag, $r->siasn_lu, $r->updated_at], 'siasn_lu = snapshot, updated_at pengguna dipertahankan');
        $this->assertSame(1, $this->db->table('riwayat_mutasi_jabatan')->where('id_riwayat_mutasi_jabatan', $edit)->where('siasn_lu < updated_at', null, false)->countAllResults(), 'edit terdorong ulang pada siklus berikutnya');
    }

    public function testTokenGagalMenghentikanBatchTanpaMenulisDan401Kedua(): void
    {
        $k1 = $this->seminar();
        $k2 = $this->seminar();
        $j  = $this->rmj([]);

        // Token gateway gagal → status 'token' → berhenti pada baris pertama, satu percobaan login saja.
        $this->transport->antre(new Respons(500, 'x'));

        try {
            $this->dorong()->jalankan('kursus');
            $this->fail('harus berhenti');
        } catch (SiasnTokenGagal) {
            $this->addToAssertionCount(1);
        }

        $this->assertCount(1, $this->transport->tercatat(), 'tanpa login SSO per baris');
        $this->assertSame(2, $this->db->table('riwayat_seminar')->whereIn('id_riwayat_seminar', [$k1, $k2])->where('siasn_flag', 0)->where('siasn_error', null)->countAllResults(), 'baris tidak ditulis (CR-090: tetap terpilih)');

        // 401 → token diperbarui → 401 lagi: tepat 2 POST, batch berhenti, penanda jabatan dipulihkan.
        $this->antreToken();
        $this->transport->antre(new Respons(401, '{"message":"Unauthorized"}'));
        $this->antreToken();
        $this->transport->antre(new Respons(401, '{"message":"Unauthorized"}'));

        try {
            $this->dorong()->jalankan('jabatan');
            $this->fail('401 kedua harus menghentikan batch');
        } catch (SiasnTokenGagal) {
            $this->addToAssertionCount(1);
        }

        $post = array_filter($this->transport->tercatat(), static fn (array $t): bool => str_ends_with($t['permintaan']->url, '/jabatan/unorjabatan/save'));
        $this->assertCount(2, $post, 'tanpa POST ketiga');
        $this->assertSame('0', (string) $this->db->table('riwayat_mutasi_jabatan')->where('id_riwayat_mutasi_jabatan', $j)->get()->getRow()->siasn_flag, 'penanda proses dipulihkan');
        $this->assertSame('1', (string) $this->db->query('SELECT IS_FREE_LOCK(?) AS bebas', [DorongSiasn::namaKunci($this->db, 'jabatan')])->getRowArray()['bebas'], 'kunci dilepas sesudah exception');

        // 401 → token diperbarui → 200: tepat 2 POST, sukses (token masih di cache dari percobaan sebelumnya).
        $this->transport->antre(new Respons(401, '{"message":"Unauthorized"}'));
        $this->antreToken();
        $this->transport->antreJson(200, ['success' => true, 'mapData' => ['rwJabatanId' => 'RJ-401']]);
        $sebelum = count($post);
        $this->assertSame(1, $this->dorong()->jalankan('jabatan')['sukses']);
        $post = array_filter($this->transport->tercatat(), static fn (array $t): bool => str_ends_with($t['permintaan']->url, '/jabatan/unorjabatan/save'));
        $this->assertCount($sebelum + 2, $post);
    }

    public function testSiasnErrorPerByteMultibyteDanNipDisensor(): void
    {
        $id = $this->seminar();
        $this->antreToken();
        $this->transport->antreJson(422, ['message' => 'NIP ' . self::NIP_A . ' ' . str_repeat('é', 200)]);

        $this->dorong()->jalankan('kursus');

        $e = (string) $this->db->table('riwayat_seminar')->where('id_riwayat_seminar', $id)->get()->getRow()->siasn_error;
        $this->assertLessThanOrEqual(255, strlen($e), 'TINYTEXT dihitung byte (P-4)');
        $this->assertTrue(mb_check_encoding($e, 'UTF-8'), 'tidak memotong di tengah karakter');
        $this->assertStringStartsWith('HTTP 422: NIP *** é', $e);
        $this->assertStringNotContainsString(self::NIP_A, $e);
    }

    public function testUraiSimpan500DenganSuccessTrueGagalDan2xxTanpaMapDataSuksesTanpaId(): void
    {
        $this->assertSame('500', SiasnAdapter::uraiSimpan(new Respons(500, '{"success":true,"mapData":{"rwKursusId":"K"}}'))['status_code']);
        $tanpa = SiasnAdapter::uraiSimpan(new Respons(201, '{"success":true}'));
        $this->assertSame(['200', null], [$tanpa['status_code'], $tanpa['data']]);
        $this->assertNull((new DorongKursus())->idDariBalasan($tanpa['data']));
    }

    public function testPayloadJabatanUnorCadanganEselonSubJabatanJfuDanSertifikasiNonPns(): void
    {
        $pmj = $this->db->table('pegawai_mutasi_jabatan')->where('nip', self::NIP_A)->get()->getRowArray();
        $this->db->table('siasn_ref_unor')->insert(['id' => 'UNOR-UNIT', 'id_unit' => $pmj['id_unit'], 'id_satker' => $pmj['id_satker'], 'lvl_uker' => 2]);
        $this->db->table('siasn_referensi')->insert(['jenis' => 'jabatan_fungsional_umum', 'id_siasn' => 'JFU-FAS', 'nama' => 'FASILITATOR PEMERINTAHAN', 'id_lokal' => null]);

        foreach ([2 => 1, 14 => 2] as $sub => $kelompok) {
            if ($this->db->table('sub_group_jabatan')->where('id_sub_group_jabatan', $sub)->countAllResults() === 0) {
                if ($this->db->table('group_jabatan')->where('id_group_jabatan', $kelompok)->countAllResults() === 0) {
                    $this->db->table('group_jabatan')->insert(['id_group_jabatan' => $kelompok, 'group_jabatan' => "Kelompok Uji {$kelompok}", 'status' => 1]);
                }

                $this->db->table('sub_group_jabatan')->insert(['id_sub_group_jabatan' => $sub, 'id_group_jabatan' => $kelompok, 'sub_group_jabatan' => "Sub Uji {$sub}", 'status' => 1]);
            }
        }
        $this->rmj(['id_group_jabatan' => 1, 'id_sub_group_jabatan' => 2, 'tmtsk' => '2021-01-01']);
        $this->rmj(['id_group_jabatan' => 2, 'id_sub_group_jabatan' => 14, 'jabatan' => 'Dosen Uji', 'tmtsk' => '2022-01-01']);
        $this->rmj(['id_group_jabatan' => 3, 'jabatan' => 'Fasilitator Pemerintah', 'tmtsk' => '2023-01-01']);

        $kirim = [];
        $stub  = $this->stub(static function (string $jalur, array $payload) use (&$kirim): array {
            $kirim[] = $payload;

            return ['status_code' => '200', 'message' => 'OK', 'data' => ['rwJabatanId' => 'RJ-' . count($kirim)]];
        });
        $this->dorongDengan($stub)->jalankan('jabatan');

        $this->assertSame(['UNOR-UNIT', '21', '', null], [$kirim[0]['unorId'], $kirim[0]['eselonId'], $kirim[0]['subJabatanId'], $kirim[0]['jabatanFungsionalUmumId']], 'unor cadangan unit+satker lvl 2, eselon sub 2 → 21');
        $this->assertSame('1102', $kirim[1]['subJabatanId'], 'fungsional sub 14 → 1102');
        $this->assertSame(['JFU-FAS', ''], [$kirim[2]['jabatanFungsionalUmumId'], $kirim[2]['jabatanFungsionalId']], 'pelaksana → JFU per nama padanan');

        // Sertifikasi tanpa saring jenis pegawai [K]: PPPK ikut.
        $this->buatPegawai(['nip' => '199001012020011002', 'id_jenis_pegawai' => 6, 'id_pns_siasn' => 'PNS-UJI-B']);
        $this->db->table('rumpun_sertifikasi')->insert(['id_rumpun_sertifikasi' => 7, 'rumpun_sertifikasi' => 'Rumpun', 'status' => 1]);
        $this->db->table('lembaga_sertifikasi')->insert(['id_lembaga_sertifikasi' => 9, 'lembaga_sertifikasi' => 'Lembaga', 'status' => 1]);
        $this->db->table('riwayat_diklat')->insert(['nip' => '199001012020011002', 'jenis_diklat' => 5, 'id_rumpun_sertifikasi' => 7, 'id_lembaga_sertifikasi' => 9, 'tgl_sertifikat' => '2022-01-01', 'mb_sertifikat_awal' => '2022-01-01', 'mb_sertifikat_akhir' => '2024-01-01', 'status' => 1, 'siasn_flag' => 0]);
        $this->assertSame(1, $this->dorongDengan($stub)->jalankan('sertifikasi', 20, null, true)['baris']);
    }

    /**
     * @param array<string, JenisDorongSiasn>|null $jenis
     */
    private function dorong(?array $jenis = null, ?string $jam = null): DorongSiasn
    {
        return new DorongSiasn($this->db, $this->adapter, new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db), ($jenis ?? []) + DorongSiasn::semuaJenis(), $jam === null ? null : static fn (): string => $jam);
    }

    private function dorongDengan(SiasnDorongInterface $siasn, ?string $jam = null): DorongSiasn
    {
        return new DorongSiasn($this->db, $siasn, new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db), DorongSiasn::semuaJenis(), $jam === null ? null : static fn (): string => $jam);
    }

    /**
     * @param callable(string, array<string, mixed>): array{status_code: string, message: string, data: mixed} $balas
     */
    private function stub(callable $balas): SiasnDorongInterface
    {
        return new class ($balas) implements SiasnDorongInterface {
            /** @var callable */
            private $balas;

            public function __construct(callable $balas)
            {
                $this->balas = $balas;
            }

            public function simpanRiwayat(string $jalur, array $payload, array $rahasia = [], ?string $refId = null): array
            {
                return ($this->balas)($jalur, $payload);
            }

            public function unggahDokumen(string $idRiwayatSiasn, string $idRefDokumen, string $isi, string $namaBerkas, array $rahasia = [], ?string $refId = null): array
            {
                throw new RuntimeException('tidak dipakai');
            }
        };
    }

    /**
     * Jenis kursus yang data suksesnya selalu gagal ditulis (kolom tidak ada) — memicu jalur "hasil tidak tersimpan".
     */
    private function kursusRusak(): JenisDorongSiasn
    {
        return $this->rusak(new DorongKursus(), true, false);
    }

    /**
     * Jenis yang data hasilnya (sukses dan/atau gagal) memakai kolom tak dikenal → penulisan hasil selalu gagal.
     */
    private function rusak(JenisDorongSiasn $asli, bool $suksesRusak, bool $gagalRusak): JenisDorongSiasn
    {
        return new class ($asli, $suksesRusak, $gagalRusak) implements JenisDorongSiasn {
            public function __construct(private readonly JenisDorongSiasn $asli, private readonly bool $suksesRusak, private readonly bool $gagalRusak)
            {
            }

            public function nama(): string
            {
                return $this->asli->nama();
            }

            public function tabel(): string
            {
                return $this->asli->tabel();
            }

            public function pk(): string
            {
                return $this->asli->pk();
            }

            public function jalur(): string
            {
                return $this->asli->jalur();
            }

            public function pilih(BaseConnection $db, int $limit, ?string $nip, string $sekarang): array
            {
                return $this->asli->pilih($db, $limit, $nip, $sekarang);
            }

            public function flagProses(): ?int
            {
                return $this->asli->flagProses();
            }

            public function payload(BaseConnection $db, array $baris): array
            {
                return $this->asli->payload($db, $baris);
            }

            public function idDariBalasan(mixed $mapData): ?string
            {
                return $this->asli->idDariBalasan($mapData);
            }

            public function dataSukses(array $baris, ?string $idSiasn, int $idAkun, string $sekarang): array
            {
                return $this->suksesRusak ? ['kolom_tidak_ada' => 1] : $this->asli->dataSukses($baris, $idSiasn, $idAkun, $sekarang);
            }

            public function dataTidakTersimpan(array $baris, ?string $idSiasn, int $idAkun, string $sekarang): array
            {
                return $this->asli->dataTidakTersimpan($baris, $idSiasn, $idAkun, $sekarang);
            }

            public function rahasia(array $baris): array
            {
                return $this->asli->rahasia($baris);
            }

            public function dataGagal(array $baris, ?string $kode, string $pesan, int $idAkun, string $sekarang): array
            {
                return $this->gagalRusak ? ['kolom_tidak_ada' => 1] : $this->asli->dataGagal($baris, $kode, $pesan, $idAkun, $sekarang);
            }
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function seminar(array $data = []): int
    {
        $this->db->table('riwayat_seminar')->insert($data + ['nip' => self::NIP_A, 'jenis_seminar' => 2, 'nama_seminar' => 'Seminar Uji', 'tgl_sertifikat' => '2024-01-01', 'status' => 1, 'siasn_flag' => 0]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function rmj(array $data): int
    {
        $pmj = $this->db->table('pegawai_mutasi_jabatan')->where('nip', self::NIP_A)->get()->getRowArray();
        $this->db->table('riwayat_mutasi_jabatan')->insert($data + [
            'nip'           => self::NIP_A, 'id_group_jabatan' => 1, 'id_jabatan' => $pmj['id_jabatan'], 'id_unit' => $pmj['id_unit'], 'id_satker' => $pmj['id_satker'],
            'jenis_jabatan' => 1, 'jenis_mutasi' => 1, 'tmtsk' => '2021-01-01', 'no_sk' => 'SK-JAB-UJI', 'status' => 1, 'siasn_flag' => 0,
        ]);

        return (int) $this->db->insertID();
    }
}
