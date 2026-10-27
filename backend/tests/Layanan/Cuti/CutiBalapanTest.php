<?php

declare(strict_types=1);

namespace Tests\Layanan\Cuti;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use App\Libraries\Layanan\Cuti\SaldoCutiService;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;

/**
 * MAKE-022 M3 (temuan review 27-10) — pemeriksaan yang bergantung keadaan diulang setelah lock: balapan disimulasikan lewat
 * seam `sebelumTransaksi()` (DB diubah setelah pemeriksaan awal, sebelum lock). Tidak ada potong/refund ganda, tidak ada
 * reset status final, tidak ada pengajuan tumpang tindih atau melebihi kuota.
 *
 * @internal
 */
final class CutiBalapanTest extends DatabaseTestCase
{
    use CutiUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private function saldo(string $nip): array
    {
        return $this->db->table('pegawai_sisa_cuti')->select('sisa_cuti, sisa_cuti_n1, sisa_cuti_n2')->where('nip', $nip)->where('tahun', 2026)->get()->getRowArray();
    }

    public function testUbahBersamaanDenganPersetujuanTidakMereset(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $id = (int) $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null)['id_riwayat_cuti'];

        // Setelah pemeriksaan awal (status 0), persetujuan PyB final masuk lebih dulu.
        $svc = $this->cutiService(null, function () use ($a, $id): void {
            $ap = $this->approvalService();
            $ap->process($a['al']['auth'], $id, ['aksi' => 'setujui']);
            $ap->process($a['pyb']['auth'], $id, ['aksi' => 'setujui']);
        });

        try {
            $svc->ubah($a['pemohon']['auth'], $id, $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['tgl_akhir' => '2026-11-10']), null);
            $this->fail('Harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors() ?? []);
        }

        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $id, 'status' => 1, 'status_al' => 1, 'status_pyb' => 1, 'lama_cuti' => 3]);
        $this->assertSame(['sisa_cuti' => '12', 'sisa_cuti_n1' => '6', 'sisa_cuti_n2' => '3'], $this->saldo($a['pemohon']['nip']), 'dipotong tepat sekali');
    }

    public function testHapusBersamaanDenganPersetujuanDitolakTanpaRefund(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $id = (int) $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null)['id_riwayat_cuti'];

        $svc = $this->cutiService(null, function () use ($a, $id): void {
            $ap = $this->approvalService();
            $ap->process($a['al']['auth'], $id, ['aksi' => 'setujui']);
            $ap->process($a['pyb']['auth'], $id, ['aksi' => 'setujui']);
        });

        try {
            $svc->hapus($a['pemohon']['auth'], $id);
            $this->fail('Harus 403');
        } catch (ForbiddenException) {
            $this->addToAssertionCount(1);
        }

        $this->seeInDatabase('riwayat_cuti', ['id_riwayat_cuti' => $id, 'status' => 1]);
        $this->assertSame(['sisa_cuti' => '12', 'sisa_cuti_n1' => '6', 'sisa_cuti_n2' => '3'], $this->saldo($a['pemohon']['nip']), 'tanpa refund');
    }

    public function testDuaPengajuanBersamaanUntukNipSamaTidakTumpangTindih(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $isi = $this->isianCuti($a['al']['nip'], $a['pyb']['nip']);

        // Pengajuan kedua lolos pemeriksaan awal; sebelum lock, pengajuan pertama dengan tanggal sama masuk.
        $svc = $this->cutiService(null, function () use ($a, $isi): void {
            $this->buatBarisCuti($a['pemohon']['nip'], ['tgl_mulai' => $isi['tgl_mulai'], 'tgl_akhir' => $isi['tgl_akhir']]);
        });

        try {
            $svc->tambah($a['pemohon']['auth'], $isi, null);
            $this->fail('Harus 422');
        } catch (ValidationException $e) {
            $this->assertSame('Tanggal cuti beririsan dengan pengajuan cuti lain.', $e->getErrors()['tgl_mulai'][0]);
        }

        $this->assertSame(1, $this->db->table('riwayat_cuti')->where('nip', $a['pemohon']['nip'])->countAllResults());
    }

    public function testInputAdminStatusSatuMenghitungUlangKuotaDiBawahLock(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026, [4, 0, 0, 0, 0]);
        $admin = $this->buatPemohon(1);

        // Setelah pemeriksaan awal (kuota cukup: 3 <= 4), pengajuan menunggu 3 hari masuk -> sisa kuota 1 < 3.
        $svc = $this->cutiService(null, function () use ($a): void {
            $this->buatBarisCuti($a['pemohon']['nip'], ['status' => 0, 'tgl_mulai' => '2026-12-07', 'tgl_akhir' => '2026-12-09', 'lama_cuti' => 3]);
        });

        try {
            $svc->tambah($admin['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip'], ['nip' => $a['pemohon']['nip'], 'status' => 1]), null);
            $this->fail('Harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lama_cuti', $e->getErrors() ?? []);
        }

        $this->assertSame(1, $this->db->table('riwayat_cuti')->where('nip', $a['pemohon']['nip'])->countAllResults());
        $this->seeInDatabase('pegawai_sisa_cuti', ['nip' => $a['pemohon']['nip'], 'sisa_cuti' => 4]);
    }

    public function testSaldoMassalMemakaiJalurProsesSehinggaBisaDiubahPembuatnya(): void
    {
        $asli = new PegawaiScope($this->db);
        $spy  = new class ($asli) implements \App\Interfaces\Kepegawaian\PegawaiScopeInterface, \App\Interfaces\Kepegawaian\PegawaiScopeProsesInterface {
            public int $daftar = 0;

            public int $proses = 0;

            public function __construct(private readonly PegawaiScope $asli)
            {
            }

            public function bolehLihat(\App\Libraries\Auth\AuthContext $auth, string $nip): bool
            {
                return $this->asli->bolehLihat($auth, $nip);
            }

            public function bolehUbah(\App\Libraries\Auth\AuthContext $auth, string $nip): bool
            {
                return $this->asli->bolehUbah($auth, $nip);
            }

            public function bolehProses(\App\Libraries\Auth\AuthContext $auth, string $nip): bool
            {
                return $this->asli->bolehProses($auth, $nip);
            }

            public function terapkanKeQuery(\CodeIgniter\Database\BaseBuilder $builder, \App\Libraries\Auth\AuthContext $auth, string $kolomNip = 'nip'): void
            {
                $this->daftar++;
                $this->asli->terapkanKeQuery($builder, $auth, $kolomNip);
            }

            public function terapkanKeQueryProses(\CodeIgniter\Database\BaseBuilder $builder, \App\Libraries\Auth\AuthContext $auth, string $kolomNip = 'nip'): void
            {
                $this->proses++;
                $this->asli->terapkanKeQueryProses($builder, $auth, $kolomNip);
            }
        };

        $admin = $this->buatPemohon(1);
        $this->buatPemohon(2);
        $svc = new SaldoCutiService($this->db, config(\Config\Cuti::class)->aturan(), $spy, fn (): \DateTimeImmutable => new \DateTimeImmutable($this->sekarangUji, new \DateTimeZone('Asia/Jakarta')));

        $svc->tambah($admin['auth'], ['target' => 1, 'tahun' => 2026, 'sisa_cuti' => 12, 'sisa_cuti_n1' => 6, 'sisa_cuti_n2' => 6, 'utang_cuti' => 0]);

        $this->assertSame(1, $spy->proses);
        $this->assertSame(0, $spy->daftar);
    }

    public function testPetugasApprovalRole3TidakBisaMemindahkanAturanKeUnitLain(): void
    {
        $admin3 = $this->buatPejabat(3);
        $unit3  = (int) $this->db->table('pegawai_mutasi_jabatan')->where('nip', $admin3['nip'])->get()->getRowArray()['id_unit'];
        $this->db->table('pengguna')->where('id_pengguna', $admin3['id_pengguna'])->update(['id_unit' => (string) $unit3]);
        $auth3 = $this->authUntukAkun((int) $admin3['id_pengguna']);
        $svc   = new \App\Libraries\Layanan\Cuti\PetugasApprovalCutiService($this->db);

        $j       = $this->buatJabatan((int) $this->db->table('pegawai_mutasi_jabatan')->where('nip', $admin3['nip'])->get()->getRowArray()['id_satker']);
        $petugas = $this->buatJabatan();
        $isi     = [
            'id_jenis_pa'                  => 1, 'id_jenis_pegawai' => 1, 'id_unit_pemohon' => $unit3, 'id_group_jabatan_pemohon' => $j['id_group_jabatan'],
            'id_sub_group_jabatan_pemohon' => $j['id_sub_group_jabatan'], 'id_unit_petugas' => $petugas['id_unit'], 'id_satker_petugas' => $petugas['id_satker'],
            'id_group_jabatan_petugas'     => $petugas['id_group_jabatan'], 'id_sub_group_jabatan_petugas' => $petugas['id_sub_group_jabatan'], 'petugas' => 'struk_' . $petugas['id_jabatan'],
        ];
        $baris = $svc->tambah($auth3, $isi);
        $lain  = $this->buatJabatan();

        try {
            $svc->ubah($auth3, (int) $baris['id_pa_layanan'], ['id_unit_pemohon' => $lain['id_unit']] + $isi);
            $this->fail('Harus 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('id_unit_pemohon', $e->getErrors() ?? []);
        }

        $this->seeInDatabase('pa_layanan', ['id_pa_layanan' => $baris['id_pa_layanan'], 'id_unit_pemohon' => $unit3]);
        $this->assertSame($unit3, (int) $svc->ubah($auth3, (int) $baris['id_pa_layanan'], $isi)['id_unit_pemohon'], 'ubah tetap boleh di unit sendiri');
    }

    public function testProsesTidakMenulisAlasanPadaSetujuiDanAtasNamaMenyimpanDiAudit(): void
    {
        $al  = $this->buatPejabat(null);
        $pyb = $this->buatPejabat(null);
        $p   = $this->buatPemohon(2, [], [], ['id_atasan_es_4' => $al['id_jabatan']]);
        $this->aturanPyb($p['jabatan'], $pyb['id_jabatan'], 'III/a');
        $this->buatSaldo($p['nip'], 2026);
        $id    = (int) $this->cutiService()->tambah($p['auth'], $this->isianCuti($al['nip'], $pyb['nip']), null)['id_riwayat_cuti'];
        $admin = $this->buatPemohon(1);

        $hasil = $this->approvalService()->process($admin['auth'], $id, ['aksi' => 'setujui', 'atas_nama' => true, 'reason_note' => 'Pejabat tanpa akun']);

        $this->assertNull($hasil['reason_note_al'], 'T4: reason_note_al = NULL');
        $this->assertNull($hasil['reason_note']);
        $audit = array_filter(
            $this->db->table('audit_logs')->where('entity', 'riwayat_cuti')->get()->getResultArray(),
            static fn (array $r): bool => str_contains((string) $r['after_json'], 'cuti.process_atas_nama'),
        );
        $this->assertStringContainsString('Pejabat tanpa akun', (string) array_values($audit)[0]['after_json']);
    }

    public function testTundaTidakMengubahBarisYangSudahFinal(): void
    {
        $a = $this->buatAlurDuaLangkah();
        $this->buatSaldo($a['pemohon']['nip'], 2026);
        $id = (int) $this->cutiService()->tambah($a['pemohon']['auth'], $this->isianCuti($a['al']['nip'], $a['pyb']['nip']), null)['id_riwayat_cuti'];
        $this->approvalService()->process($a['al']['auth'], $id, ['aksi' => 'tolak', 'reason_note' => 'Tidak bisa']);

        $this->expectException(ValidationException::class);
        $this->approvalService()->tunda($a['al']['auth'], $id);
    }

    public function testBarisTidakAdaMenghasilkan404SaatUbahAtauHapus(): void
    {
        $p = $this->buatPemohon(2);

        $this->expectException(NotFoundException::class);
        $this->cutiService()->hapus($p['auth'], 999999);
    }
}
