<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Diklat;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Closure;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-11 Riwayat Diklat (WS-1 M4 MAKE-007): B-TC generik, isian per jenis (legacy set_param/validate_param), snapshot
 * `pegawai_diklat` hanya jenis 1 struktural, duplikat hanya jenis 1, [V2] status ikut engine (perbaikan bug legacy) dan
 * jenis 5 tanpa master rumpun/lembaga.
 *
 * @internal
 */
final class DiklatTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private Diklat $d;
    private string $nip;
    private AuthContext $admin;
    private int $subFungsional = 0;
    private int $subLain       = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new Diklat();
        $this->pasangEngineNyata([$this->d]);

        foreach ([[1, 1, 'Diklatpim IV'], [2, 2, 'Diklat Teknis Pariwisata'], [3, 4, 'Latsar CPNS']] as [$id, $jenis, $nama]) {
            $this->db->table('diklat')->insert(['id_diklat' => $id, 'jenis_diklat' => $jenis, 'nama_diklat' => $nama, 'order' => 1, 'status' => 1]);
        }

        if ($this->db->table('group_jabatan')->where('id_group_jabatan', Diklat::GROUP_JABATAN_FUNGSIONAL)->countAllResults() === 0) {
            $this->db->table('group_jabatan')->insert(['id_group_jabatan' => Diklat::GROUP_JABATAN_FUNGSIONAL, 'group_jabatan' => 'Fungsional', 'status' => 1]);
        }

        $this->db->table('sub_group_jabatan')->insert(['id_group_jabatan' => Diklat::GROUP_JABATAN_FUNGSIONAL, 'sub_group_jabatan' => 'Analis Kepegawaian', 'status' => 1]);
        $this->subFungsional = (int) $this->db->insertID();
        $this->subLain       = $this->buatJabatan()['id_sub_group_jabatan'];

        $this->nip   = $this->buatPegawaiDiSatker($this->buatSatker());
        $this->admin = $this->aktor(Role::SUPER_ADMIN)[1];
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    protected function btcDefinisi(): RiwayatDefinisi
    {
        return $this->d;
    }

    protected function btcPayload(string $nip): array
    {
        return $this->dataStruktural('2015-06-01');
    }

    protected function btcBaris(string $nip): array
    {
        return $this->dataStruktural('2015-06-01') + ['nama_diklat' => 'Diklatpim IV'];
    }

    protected function btcBerkas(): array
    {
        return [Diklat::LAMPIRAN_SERTIFIKAT => $this->berkasUji('sertifikat.pdf')];
    }

    protected function btcScopeRole3(array $nipDalam): null
    {
        return null;
    }

    public function testIzinMatriksV2(): void
    {
        foreach (Role::all() as $role) {
            $boleh = in_array($role, [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PPPK], true);
            $this->assertSame($boleh, $this->d->boleh(AksiRiwayat::Tambah, $role), "tambah {$role}");
            $this->assertSame($boleh, $this->d->boleh(AksiRiwayat::Lihat, $role), "lihat {$role}");
            $this->assertSame(in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true), $this->d->boleh(AksiRiwayat::Proses, $role));
        }
    }

    public function testStatusIkutEngineBukanBugLegacy(): void
    {
        // [V2] Legacy set_param() tidak menyimpan status: admin pun tersimpan 0. v2: admin → 1 dan snapshot terisi.
        $row = $this->tambah($this->dataStruktural('2015-06-01'));

        $this->assertSame('1', (string) $row['status']);
        $this->assertSame((string) $row['id_riwayat_diklat'], (string) $this->snapshotUji('pegawai_diklat', $this->nip)['id_riwayat_diklat']);

        $this->assertSame('2', (string) $this->engine()->ubah($this->admin, $this->nip, 'diklat', (int) $row['id_riwayat_diklat'], ['status' => 2, 'reason_note' => 'Salah'])['status']);
        $this->assertNull($this->snapshotUji('pegawai_diklat', $this->nip));
    }

    public function testSnapshotHanyaStruktural(): void
    {
        $this->tambah($this->dataStruktural('2015-06-01'));
        $teknis = $this->tambah(['jenis_diklat' => 2, 'id_diklat' => 2, 'deskripsi' => 'Teknis', 'no_sertifikat' => 'T-1', 'tgl_sertifikat' => '2020-01-01', 'jumlah_jp' => 30, 'instansi_penyelenggara' => 'Pusdiklat']);

        $this->assertSame('Diklat Teknis Pariwisata', $teknis['nama_diklat']);
        $this->assertSame('Diklatpim IV', $this->snapshotUji('pegawai_diklat', $this->nip)['nama_diklat']);
    }

    public function testIsianPerJenis(): void
    {
        // Diklat harus milik jenis terpilih; teknis wajib deskripsi.
        $this->assertErrors(['deskripsi', 'id_diklat'], fn () => $this->tambah(['jenis_diklat' => 2, 'id_diklat' => 1] + $this->umum()));

        // Fungsional: sub-group jabatan group 2 + deskripsi; nama sub-group disalin; id_diklat dikosongkan.
        $this->assertErrors(['id_sub_group_jabatan'], fn () => $this->tambah(['jenis_diklat' => 3, 'id_sub_group_jabatan' => $this->subLain, 'deskripsi' => 'X'] + $this->umum()));
        $fungsional = $this->tambah(['jenis_diklat' => 3, 'id_sub_group_jabatan' => $this->subFungsional, 'id_diklat' => 1, 'deskripsi' => 'Pembentukan'] + $this->umum('2021-01-01'));
        $this->assertSame(['Analis Kepegawaian', null, null], [$fungsional['sub_group_jabatan'], $fungsional['id_diklat'], $fungsional['nama_diklat']]);

        // Sertifikasi: nama, rumpun, masa berlaku wajib; JP tidak dipakai; id rumpun/lembaga NULL (master belum ada).
        $this->assertErrors(
            ['nama_diklat', 'rumpun_sertifikasi', 'mb_sertifikat_awal', 'mb_sertifikat_akhir'],
            fn () => $this->tambah(['jenis_diklat' => 5, 'no_sertifikat' => 'C-1', 'tgl_sertifikat' => '2022-01-01', 'instansi_penyelenggara' => 'LSP']),
        );
        $sert = $this->tambah([
            'jenis_diklat'        => 5, 'nama_diklat' => 'Sertifikasi Pemandu Wisata', 'rumpun_sertifikasi' => 'Pariwisata', 'mb_sertifikat_awal' => '2022-01-01',
            'mb_sertifikat_akhir' => '2025-01-01', 'no_sertifikat' => 'C-1', 'tgl_sertifikat' => '2022-01-01', 'jumlah_jp' => 10, 'instansi_penyelenggara' => 'LSP Pariwisata',
        ]);
        $this->assertSame(['Sertifikasi Pemandu Wisata', 'Pariwisata', null, null, null], [$sert['nama_diklat'], $sert['rumpun_sertifikasi'], $sert['jumlah_jp'], $sert['id_rumpun_sertifikasi'], $sert['id_lembaga_sertifikasi']]);
    }

    public function testDuplikatHanyaStrukturalDanLampiranSatuMb(): void
    {
        $this->tambah($this->dataStruktural('2015-06-01'));
        $this->assertErrors(['tgl_sertifikat'], fn () => $this->tambah($this->dataStruktural('2015-06-01')));

        $prajab = ['jenis_diklat' => 4, 'id_diklat' => 3] + $this->umum('2010-01-01');
        $this->tambah($prajab);
        $this->tambah($prajab);

        $this->assertSame(1, $this->d->lampiran()[0]->batasMb);
    }

    /**
     * @return array<string, int|string>
     */
    private function dataStruktural(string $tgl): array
    {
        return ['jenis_diklat' => 1, 'id_diklat' => 1] + $this->umum($tgl);
    }

    /**
     * @return array<string, int|string>
     */
    private function umum(string $tgl = '2019-01-01'): array
    {
        return ['no_sertifikat' => 'SRT/' . $tgl, 'tgl_sertifikat' => $tgl, 'jumlah_jp' => 40, 'instansi_penyelenggara' => 'LAN'];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function tambah(array $data): array
    {
        return $this->engine()->tambah($this->admin, $this->nip, 'diklat', $data, [Diklat::LAMPIRAN_SERTIFIKAT => $this->berkasUji('srt.pdf')]);
    }

    private function engine(): RiwayatEngine
    {
        /** @var RiwayatEngine $engine */
        $engine = service('riwayatService');

        return $engine;
    }

    /**
     * @param list<string> $fields
     */
    private function assertErrors(array $fields, Closure $aksi): void
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            $this->assertSame($fields, array_keys($e->getErrors() ?? []), (string) json_encode($e->getErrors()));

            return;
        }

        $this->fail('Diharapkan 422 untuk ' . implode(', ', $fields));
    }
}
