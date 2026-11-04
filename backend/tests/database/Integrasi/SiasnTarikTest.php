<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Interfaces\SiasnGatewayInterface;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\Log\IntegrasiLog;
use App\Libraries\Siasn\SiasnAdapter;
use App\Libraries\Siasn\Tarik\JenisTarikSiasn;
use App\Libraries\Siasn\Tarik\RencanaTarik;
use App\Libraries\Siasn\Tarik\TarikSiasn;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SiasnUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-067 M1 (H-03, F6-14 ikut legacy penuh; PRD Fase 6 §7.3) — `siasn:tarik` dengan FakeTransport (tanpa jaringan),
 * fixture sintetis: data utama (salin `nip_lama`/`npwp`/`no_hp` + pairing `id_pns_siasn`), golongan (ubah/pasang/sisip/
 * ganda/tolak, lepas hanya untuk NIP yang API-nya sukses — pengaman F6-14), idempoten (AC-03.2), dry-run tanpa tulis,
 * galat DB → flag batal, akun sistem di awal (Z-1), log tanpa NIP, command.
 *
 * @internal
 */
final class SiasnTarikTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SiasnUjiTrait;
    use StreamFilterTrait;

    private const NIP_A = '199001012020011001';

    private const NIP_B = '199001012020011002';

    private const NIP_C = '199001012020011003';

    private const NIP_D = '199001012020011004';

    protected $seed = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStreamFilterBuffer();
        $this->siapkanSiasn();
    }

    protected function tearDown(): void
    {
        $this->pulihkanSiasn();
        parent::tearDown();
    }

    public function testDataUtamaSalinPegawaiPairingDanGagalDitandaiLaluIdempoten(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1, 'npwp' => '000000000000000', 'nip_lama' => null, 'no_hp' => null]);
        $this->buatPegawai(['nip' => self::NIP_B, 'id_jenis_pegawai' => 1]);
        $this->buatPegawai(['nip' => self::NIP_C, 'id_jenis_pegawai' => 1, 'status' => 2]);
        $this->db->table('siasn_status_pegawai')->insert(['nip' => self::NIP_C, 'du' => 0]);

        $this->antreToken();
        $this->transport->antreJson(200, ['code' => 1, 'data' => ['id' => 'PNS-UJI-A', 'nipLama' => '010000001', 'noNpwp' => '01.234.567.8-901.000', 'noHp' => '081200000001']]);
        $this->transport->antre(new Respons(500, 'galat'), new Respons(500, 'galat'), new Respons(500, 'galat'));

        $h = $this->tarik()->jalankan('data-utama');

        $this->assertSame([2, 1, 1], [$h['nip'], $h['sukses'], $h['gagal']]);
        $this->assertSame(['sisip' => 0, 'ubah' => 1, 'pasang' => 1, 'lepas' => 0, 'hapus' => 0, 'ganda' => 0], $h['jumlah']);

        $a = $this->pegawai(self::NIP_A);
        $this->assertSame(['010000001', '012345678-901000', '081200000001', 'Sync SIASN', 'PNS-UJI-A', '1', (string) $this->idAkun], [$a['nip_lama'], $a['npwp'], $a['no_hp'], $a['keterangan'], $a['id_pns_siasn'], (string) $a['siasn_flag'], (string) $a['updated_by']]);
        $b = $this->pegawai(self::NIP_B);
        $this->assertSame([null, '2'], [$b['id_pns_siasn'], (string) $b['siasn_flag']], 'pairing gagal → siasn_flag 2, dicoba lagi besok [K SI:497-500]');
        $this->assertNotNull($b['siasn_lu']);

        $status = array_column($this->db->table('siasn_status_pegawai')->get()->getResultArray(), null, 'nip');
        $this->assertArrayNotHasKey(self::NIP_C, $status, 'pegawai tidak aktif keluar dari daftar [K sync_dsp]');
        $this->assertSame(['0', \App\Libraries\Waktu\StempelUtc::tanggalWib(gmdate('Y-m-d H:i:s'))], [(string) $status[self::NIP_A]['du'], $status[self::NIP_A]['du_lu']]);
        $this->assertSame('/pns/data-utama/' . self::NIP_A, substr($this->transport->tercatat()[2]['permintaan']->url, strlen(self::DASAR_SIASN)));
        $this->assertSame('Bearer gw-uji', $this->transport->tercatat()[2]['permintaan']->header['Authorization']);
        $this->assertSame('bearer sso-uji', $this->transport->tercatat()[2]['permintaan']->header['Auth']);

        // AC-03.2: siklus kedua pada hari yang sama tidak memilih NIP apa pun dan tidak memanggil API.
        $jumlahPanggilan = count($this->transport->tercatat());
        $this->assertSame(0, $this->tarik()->jalankan('data-utama')['nip']);
        $this->assertCount($jumlahPanggilan, $this->transport->tercatat());

        $ringkas = $this->db->table('integrasi_log')->where('operasi', 'tarik_data_utama')->get()->getResultArray();
        $this->assertSame('sebagian_gagal', $ringkas[0]['kode']);
        $semua = (string) json_encode($this->db->table('integrasi_log')->get()->getResultArray());
        $this->assertStringNotContainsString(self::NIP_A, $semua, 'log tanpa NIP');
        $this->assertStringNotContainsString('sso-uji', $semua);
    }

    public function testGolonganUbahPasangSisipGandaTolakDanLepasHanyaUntukNipYangSukses(): void
    {
        foreach ([self::NIP_A, self::NIP_B, self::NIP_C, self::NIP_D] as $nip) {
            $this->buatPegawai(['nip' => $nip, 'id_jenis_pegawai' => 1]);
        }

        $r1 = $this->kp(self::NIP_A, ['gol_ruang' => 'III/a', 'id_rw_siasn' => 'GOL-1', 'no_sk' => 'SK-LAMA', 'tmtsk' => '2015-01-01', 'id_jenis_kp' => 3, 'id_pangkat' => 13, 'pangkat' => 'Penata Muda', 'mker_th' => 0, 'mker_bl' => 0]);
        $r2 = $this->kp(self::NIP_A, ['gol_ruang' => 'III/b', 'tmtsk' => '2019-04-01']);
        $r3 = $this->kp(self::NIP_A, ['gol_ruang' => 'II/d', 'id_rw_siasn' => 'GOL-LAMA', 'tmtsk' => '2010-04-01']);
        $rB = $this->kp(self::NIP_B, ['gol_ruang' => 'III/a', 'id_rw_siasn' => 'GOL-B', 'tmtsk' => '2015-01-01']);
        $rC = $this->kp(self::NIP_C, ['gol_ruang' => 'III/a', 'id_rw_siasn' => 'GOL-C', 'tmtsk' => '2015-01-01']);
        $rD = $this->kp(self::NIP_D, ['gol_ruang' => 'IV/a', 'id_rw_siasn' => 'GOL-D', 'tmtsk' => '2024-02-01']);

        $this->antreToken();
        $this->transport->antreJson(200, ['code' => 1, 'data' => [
            $this->gol('GOL-1', 'III/a', '01-01-2015', ['skNomor' => 'SK-BARU', 'skTanggal' => '20-12-2014']),
            $this->gol('GOL-2', 'III/b', '01-04-2019'),
            $this->gol('GOL-3', 'III/c', '01-04-2023', ['masaKerjaGolonganTahun' => '8', 'masaKerjaGolonganBulan' => '0']),
            $this->gol('GOL-4', 'III/b', '01-04-2020'),
            $this->gol('GOL-6', '', '01-01-2000'),
        ]]);
        $this->transport->antre(new Respons(500, 'galat'), new Respons(500, 'galat'), new Respons(500, 'galat'));
        $this->transport->antreJson(200, ['code' => 1, 'data' => []]);
        // NIP D: satu-satunya baris ditolak (tanggal tidak sah, id berbeda dari pasangan lokal).
        $this->transport->antreJson(200, ['code' => 1, 'data' => [$this->gol('GOL-5', 'IV/a', '31-02-2024')]]);

        $h = $this->tarik()->jalankan('golongan');

        $this->assertSame([4, 3, 1], [$h['nip'], $h['sukses'], $h['gagal']]);
        $this->assertSame(['sisip' => 1, 'ubah' => 1, 'pasang' => 1, 'lepas' => 1, 'hapus' => 0, 'ganda' => 1], $h['jumlah']);
        $this->assertSame(['tmtGolongan_tanggal' => 1], $h['ditolak']);
        // Putusan reviewer (2) & (c): NIP C sukses + data kosong; jenisKPId '101' tanpa pemetaan → bawaan "Reguler" (3 baris;
        // baris ganda tidak diurai). Review B6 M1 B-1a: NIP D punya baris ditolak → tanpa lepas.
        $this->assertSame(['data_kosong' => 1, 'pairing_ganda' => 0, 'referensi_tak_terpetakan' => 3, 'ditolak_tanpa_destruktif' => 1], $h['info']);
        $this->assertStringContainsString('data_kosong 1, pairing_ganda 0, referensi_tak_terpetakan 3, ditolak_tanpa_destruktif 1', (string) $this->db->table('integrasi_log')->where('operasi', 'tarik_golongan')->get()->getRow()->pesan);
        $this->assertSame('GOL-D', $this->db->table('riwayat_kp')->where('id_riwayat_kp', $rD)->get()->getRow()->id_rw_siasn, 'B-1a: baris ditolak → pasangan tidak dilepas');

        $kp = array_column($this->db->table('riwayat_kp')->whereIn('nip', [self::NIP_A, self::NIP_B, self::NIP_C])->get()->getResultArray(), null, 'id_riwayat_kp');
        $this->assertSame(['SK-BARU', '2014-12-20', 'Sync SIASN', (string) $this->idAkun], [$kp[$r1]['no_sk'], $kp[$r1]['tgl_sk'], $kp[$r1]['keterangan'], (string) $kp[$r1]['updated_by']]);
        $this->assertSame('GOL-2', $kp[$r2]['id_rw_siasn'], 'dipasangkan lewat gol_ruang');
        $this->assertNull($kp[$r3]['id_rw_siasn'], 'pasangan yang tidak dikembalikan SIASN dilepas');
        $this->assertSame('GOL-B', $kp[$rB]['id_rw_siasn'], 'pengaman F6-14: API NIP B gagal → pasangan tidak disentuh');
        $this->assertSame('GOL-C', $kp[$rC]['id_rw_siasn'], 'data kosong → tanpa aksi destruktif');

        $baru = $this->db->table('riwayat_kp')->where('id_rw_siasn', 'GOL-3')->get()->getRowArray();
        $this->assertSame([self::NIP_A, '2023-04-01', '2023-04-01', '15', 'Penata', 'III', 'c', '8', '1', (string) $this->idAkun, 'Reguler'], [$baru['nip'], $baru['tmtsk'], $baru['tgl_sk'], (string) $baru['id_pangkat'], $baru['pangkat'], $baru['gol'], $baru['ruang'], (string) $baru['mker_th'], (string) $baru['status'], (string) $baru['approved_by'], $baru['jenis_kp']]);
        $this->assertSame(1, $this->db->table('double_riwayat_kp')->where('nip', self::NIP_A)->countAllResults());
        $this->assertSame(0, $this->db->table('riwayat_kp')->where('id_rw_siasn', 'GOL-4')->countAllResults(), 'golongan ganda tidak disisipkan');

        $status = array_column($this->db->table('siasn_status_pegawai')->get()->getResultArray(), null, 'nip');
        $this->assertSame(['1', '1', '1'], [(string) $status[self::NIP_A]['rw_gol'], (string) $status[self::NIP_B]['rw_gol'], (string) $status[self::NIP_C]['rw_gol']]);

        // AC-03.2: tarik ulang dengan jawaban sama untuk NIP A → tanpa sisip/ubah baru.
        $this->transport->antreJson(200, ['code' => 1, 'data' => [
            $this->gol('GOL-1', 'III/a', '01-01-2015', ['skNomor' => 'SK-BARU', 'skTanggal' => '20-12-2014']),
            $this->gol('GOL-2', 'III/b', '01-04-2019'),
            $this->gol('GOL-3', 'III/c', '01-04-2023', ['masaKerjaGolonganTahun' => '8', 'masaKerjaGolonganBulan' => '0']),
        ]]);
        $ulang = $this->tarik()->jalankan('golongan', 20, self::NIP_A);
        $this->assertSame(['sisip' => 0, 'ubah' => 0, 'pasang' => 0, 'lepas' => 0, 'hapus' => 0, 'ganda' => 0], $ulang['jumlah']);
    }

    /**
     * Putusan reviewer B6 (f): `du_lu` = tanggal bisnis WIB (BP-10). Pukul 01:30 WIB (18:30 UTC hari sebelumnya) dicatat
     * tanggal WIB; tarik lagi pukul 06:00 WIB hari yang sama tidak memilih NIP; lewat 00:00 WIB berikutnya (masih tanggal
     * UTC yang sama dengan stempel UTC sebelumnya + 1) dipilih lagi.
     */
    public function testDuLuMemakaiTanggalWibLintasTengahMalam(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1, 'id_pns_siasn' => 'PNS-UJI-A']);
        $jam   = '2026-11-04 18:30:00';
        $tarik = new TarikSiasn($this->db, $this->adapter, new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db), TarikSiasn::semuaJenis(), static function () use (&$jam): string {
            return $jam;
        });

        $this->antreToken();
        $this->transport->antreJson(200, ['code' => 1, 'data' => ['id' => 'PNS-UJI-A']]);
        $this->assertSame(1, $tarik->jalankan('data-utama')['nip']);
        $this->assertSame('2026-11-05', $this->db->table('siasn_status_pegawai')->where('nip', self::NIP_A)->get()->getRow()->du_lu, '01:30 WIB = tanggal WIB 5 Nov (UTC masih 4 Nov)');

        $jam = '2026-11-04 23:00:00'; // 06:00 WIB tanggal 5
        $this->assertSame(0, $tarik->jalankan('data-utama')['nip'], 'hari WIB yang sama tidak diulang');

        $jam = '2026-11-05 17:00:00'; // 6 Nov 00:00 WIB
        $this->transport->antreJson(200, ['code' => 1, 'data' => ['id' => 'PNS-UJI-A']]);
        $this->assertSame(1, $tarik->jalankan('data-utama')['nip'], 'hari WIB berikutnya dipilih lagi');
        $this->assertSame('2026-11-06', $this->db->table('siasn_status_pegawai')->where('nip', self::NIP_A)->get()->getRow()->du_lu);
    }

    public function testDryRunTanpaTulisApaPun(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1]);
        $this->db->table('siasn_status_pegawai')->insert(['nip' => self::NIP_A]);
        $this->antreToken();
        $this->transport->antreJson(200, ['code' => 1, 'data' => [$this->gol('GOL-1', 'III/a', '01-01-2015')]]);

        $h = $this->tarik()->jalankan('golongan', 20, null, true);

        $this->assertSame([true, 1], [$h['dry_run'], $h['jumlah']['sisip']]);
        $this->assertSame(0, $this->db->table('riwayat_kp')->where('nip', self::NIP_A)->countAllResults());
        $this->assertSame('0', (string) $this->db->table('siasn_status_pegawai')->where('nip', self::NIP_A)->get()->getRow()->rw_gol, 'flag tidak disentuh');
        $this->assertSame(0, $this->db->table('integrasi_log')->like('operasi', 'tarik_', 'after')->countAllResults());
    }

    public function testGalatDbMengembalikanFlagKeNolDanDilempar(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1]);
        $rusak = new class () implements JenisTarikSiasn {
            public function nama(): string
            {
                return 'rusak';
            }

            public function kolomStatus(): string
            {
                return 'rw_gol';
            }

            public function jenisPegawai(): ?array
            {
                return null;
            }

            public function ambil(SiasnGatewayInterface $siasn, string $nip): array
            {
                return ['status_code' => SiasnAdapter::SUKSES, 'message' => 'OK', 'data' => []];
            }

            public function rencana(BaseConnection $db, array $hasil, int $idAkun, string $sekarang): RencanaTarik
            {
                $r = new RencanaTarik();
                $r->sisip('riwayat_kp', ['nip' => 'TIDAK-ADA-DI-PEGAWAI', 'tmtsk' => '2020-01-01', 'status' => 1]);

                return $r;
            }
        };
        $tarik = new TarikSiasn($this->db, $this->adapter, new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db), ['rusak' => $rusak]);

        try {
            $tarik->jalankan('rusak');
            $this->fail('galat DB harus dilempar');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('0', (string) $this->db->table('siasn_status_pegawai')->where('nip', self::NIP_A)->get()->getRow()->rw_gol, 'rollback flag [K]');
        $this->assertSame('galat_db', $this->db->table('integrasi_log')->where('operasi', 'tarik_rusak')->get()->getRow()->kode);
    }

    public function testCommandOpsiSalahDanDryRun(): void
    {
        Services::injectMock('siasnGateway', $this->adapter);

        foreach (['', '--jenis=tidak-ada', '--jenis=golongan --limit=0', '--jenis=golongan --nip=xx/yy'] as $salah) {
            $this->resetStreamFilterBuffer();
            command(trim("siasn:tarik {$salah}"));
            $this->assertStringContainsString('Pemakaian:', $this->getStreamFilterBuffer(), $salah);
        }

        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1]);
        $this->db->table('siasn_status_pegawai')->insert(['nip' => self::NIP_A]);
        $this->antreToken();
        $this->transport->antreJson(200, ['code' => 1, 'data' => [$this->gol('GOL-1', 'III/a', '01-01-2015')]]);
        $this->resetStreamFilterBuffer();
        command('siasn:tarik --jenis golongan --dry-run');
        $keluar = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('[dry-run] Tarik SIASN golongan: NIP 1 (sukses 1, gagal 0); sisip 1', $keluar);
        $this->assertStringNotContainsString(self::NIP_A, $keluar);
    }

    /**
     * @return array<string, mixed>
     */
    private function pegawai(string $nip): array
    {
        return (array) $this->db->table('pegawai')->where('nip', $nip)->get()->getRowArray();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function kp(string $nip, array $data): int
    {
        $this->db->table('riwayat_kp')->insert($data + ['nip' => $nip, 'status' => 1, 'id_jenis_kp' => 3]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, string> $lain
     *
     * @return array<string, string>
     */
    private function gol(string $id, string $golongan, string $tmt, array $lain = []): array
    {
        return $lain + [
            'id'                     => $id,
            'golongan'               => $golongan,
            'tmtGolongan'            => $tmt,
            'jenisKPId'              => '101',
            'skNomor'                => '',
            'skTanggal'              => '',
            'tglPertekBkn'           => '',
            'noPertekBkn'            => '',
            'jumlahKreditUtama'      => '0',
            'jumlahKreditTambahan'   => '0',
            'masaKerjaGolonganTahun' => '0',
            'masaKerjaGolonganBulan' => '0',
        ];
    }
}
