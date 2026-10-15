<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Alamat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Kp;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Pendidikan;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-21 butir "Snapshot sync logic (hanya di final approval)" (`docs/fase3/03-Kepegawaian.md` §B-21; WS-1 M5 MAKE-008):
 * test ber-DB lewat Definisi NYATA, PegawaiScope + lampiran nyata, untuk tiga pola snapshot:
 *
 * - self-service satu target (`pendidikan` → `pegawai_pendidikan`);
 * - self-service dua target (`alamat` → `pegawai_alamat` + `pegawai_alamat_kantor`);
 * - input admin multi-target (`kp` → `pegawai_kp` / `pegawai_cpns` / `pegawai_pns`).
 *
 * Aturan (ADR-006, README "Rincian engine riwayat"): pengajuan (0), penolakan baris yang belum disetujui, dan pengajuan
 * ulang TIDAK menyentuh snapshot; snapshot hanya berubah saat baris masuk/keluar status 1 (setujui, input admin langsung
 * Disetujui, ubah baris Disetujui, ubah status oleh role 1, hapus baris Disetujui).
 *
 * @internal
 */
final class SnapshotApprovalFinalTest extends DatabaseTestCase
{
    use RiwayatUjiTrait;

    private AuthContext $admin;
    private AuthContext $pengusul;
    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pasangEngineNyata([new Pendidikan(), new Alamat(), new Kp()]);
        $this->siapkanMasterPendidikan();
        $this->siapkanMasterKp();
        $this->siapkanWilayah();

        $this->admin                  = $this->aktor(Role::SUPER_ADMIN)[1];
        [$this->nip, $this->pengusul] = $this->aktor(Role::PPPK);
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    public function testPendidikanSnapshotHanyaSaatDisetujui(): void
    {
        $berkas = fn (): array => [Pendidikan::LAMPIRAN_IJAZAH => $this->berkasUji()];
        $data   = [
            'id_jenjang_pendidikan' => 6, 'id_bidang_pendidikan' => 1, 'id_jurusan_pendidikan' => 10,
            'institusi_pendidikan'  => 'Universitas Uji', 'no_ijazah' => 'IJZ-1', 'tgl_lulus' => '2012-08-30',
        ];

        // Pengajuan → 0, snapshot kosong.
        $id = (int) $this->engine()->tambah($this->pengusul, $this->nip, 'pendidikan', $data, $berkas())['id_riwayat_pendidikan'];
        $this->assertSnapshot(['pegawai_pendidikan' => null], 'pengajuan');

        // Tolak baris yang belum disetujui → snapshot tidak tersentuh.
        $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $id, 'tolak', 'Ijazah buram');
        $this->assertSnapshot(['pegawai_pendidikan' => null], 'tolak');

        // Pengajuan ulang (ubah oleh pengusul) → 0, tetap kosong.
        $this->engine()->ubah($this->pengusul, $this->nip, 'pendidikan', $id, ['no_ijazah' => 'IJZ-1A']);
        $this->assertSnapshot(['pegawai_pendidikan' => null], 'pengajuan ulang');

        // Approval final → snapshot terisi.
        $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $id, 'setujui', null);
        $this->assertSnapshot(['pegawai_pendidikan' => $id], 'setujui');
        $this->assertSame('IJZ-1A', $this->snapshotUji('pegawai_pendidikan', $this->nip)['no_ijazah']);

        // Pengajuan baru (jenjang lebih tinggi) belum disetujui → snapshot tetap baris lama.
        $s2 = (int) $this->engine()->tambah($this->pengusul, $this->nip, 'pendidikan', ['id_jenjang_pendidikan' => 7, 'tgl_lulus' => '2016-08-30', 'no_ijazah' => 'IJZ-2'] + $data, $berkas())['id_riwayat_pendidikan'];
        $this->assertSnapshot(['pegawai_pendidikan' => $id], 'pengajuan kedua');

        $this->engine()->proses($this->admin, $this->nip, 'pendidikan', $s2, 'setujui', null);
        $this->assertSnapshot(['pegawai_pendidikan' => $s2], 'jenjang tertinggi');

        // Hapus baris aktif → snapshot dihitung ulang ke baris Disetujui berikutnya.
        $this->engine()->hapus($this->admin, $this->nip, 'pendidikan', $s2);
        $this->assertSnapshot(['pegawai_pendidikan' => $id], 'hapus baris aktif');
    }

    public function testAlamatDuaTargetHanyaSaatDisetujui(): void
    {
        $data = [
            'jenis_alamat' => 1, 'alamat_utama' => 1, 'id_provinsi' => '31', 'id_kabupaten_kota' => '3171',
            'id_kecamatan' => '3171010', 'id_kelurahan' => '3171010001', 'alamat' => 'Jl. Uji 1',
        ];
        $ktp    = (int) $this->engine()->tambah($this->pengusul, $this->nip, 'alamat', $data, [Alamat::LAMPIRAN_KTP => $this->berkasUji('ktp.pdf')])['id_riwayat_alamat'];
        $kantor = (int) $this->engine()->tambah($this->pengusul, $this->nip, 'alamat', ['jenis_alamat' => 3, 'nama_kantor' => 'Kantor Uji'] + $data)['id_riwayat_alamat'];
        $this->assertSnapshot(['pegawai_alamat' => null, 'pegawai_alamat_kantor' => null], 'dua pengajuan');

        $this->engine()->proses($this->admin, $this->nip, 'alamat', $kantor, 'setujui', null);
        $this->assertSnapshot(['pegawai_alamat' => null, 'pegawai_alamat_kantor' => $kantor], 'kantor disetujui');

        $this->engine()->proses($this->admin, $this->nip, 'alamat', $ktp, 'setujui', null);
        $this->assertSnapshot(['pegawai_alamat' => $ktp, 'pegawai_alamat_kantor' => $kantor], 'KTP disetujui');

        // Ubah data baris Disetujui oleh admin → snapshot ikut (baris tetap status 1).
        $this->engine()->ubah($this->admin, $this->nip, 'alamat', $ktp, ['alamat' => 'Jl. Uji 2']);
        $this->assertSame('Jl. Uji 2', $this->snapshotUji('pegawai_alamat', $this->nip)['alamat']);
    }

    public function testKpInputAdminDanUbahStatusRole1(): void
    {
        $berkas = fn (): array => [Kp::LAMPIRAN_SK_KP => $this->berkasUji('sk.pdf')];
        $data   = fn (int $jenis, string $tmt): array => [
            'id_jenis_kp' => $jenis, 'id_pangkat' => $this->idPangkatUji, 'tmtsk' => $tmt, 'tgl_sk' => $tmt,
            'no_sk'       => 'SK/' . $tmt, 'gaji_pokok' => 2900000, 'mker_th' => 1, 'mker_bl' => 0,
        ];

        // Input admin = langsung Disetujui → approval final: tiga target sesuai filter.
        $cpns = (int) $this->engine()->tambah($this->admin, $this->nip, 'kp', $data(1, '2010-03-01'), $berkas())['id_riwayat_kp'];
        $reg  = (int) $this->engine()->tambah($this->admin, $this->nip, 'kp', $data(3, '2015-04-01'), $berkas())['id_riwayat_kp'];
        $this->assertSnapshot(['pegawai_kp' => $reg, 'pegawai_cpns' => $cpns, 'pegawai_pns' => null], 'input admin');

        // Role 1 memilih status 2 (keluar dari Disetujui) → snapshot dihitung ulang.
        $this->engine()->ubah($this->admin, $this->nip, 'kp', $reg, ['status' => 2, 'reason_note' => 'SK keliru']);
        $this->assertSnapshot(['pegawai_kp' => $cpns, 'pegawai_cpns' => $cpns, 'pegawai_pns' => null], 'keluar status 1');

        // Kembali Disetujui → snapshot kembali ke TMT terbaru.
        $this->engine()->ubah($this->admin, $this->nip, 'kp', $reg, ['status' => 1]);
        $this->assertSnapshot(['pegawai_kp' => $reg, 'pegawai_cpns' => $cpns, 'pegawai_pns' => null], 'masuk status 1');
    }

    /**
     * @param array<string, int|null> $harapan tabel snapshot => PK riwayat yang dirujuk (null = tidak ada baris)
     */
    private function assertSnapshot(array $harapan, string $langkah): void
    {
        $pk = [
            'pegawai_pendidikan'    => 'id_riwayat_pendidikan',
            'pegawai_alamat'        => 'id_riwayat_alamat',
            'pegawai_alamat_kantor' => 'id_riwayat_alamat',
            'pegawai_kp'            => 'id_riwayat_kp',
            'pegawai_cpns'          => 'id_riwayat_kp',
            'pegawai_pns'           => 'id_riwayat_kp',
        ];

        foreach ($harapan as $tabel => $id) {
            $row = $this->snapshotUji($tabel, $this->nip);
            $this->assertSame($id, $row === null ? null : (int) $row[$pk[$tabel]], "{$langkah}: {$tabel}");
        }
    }

    /**
     * Wilayah uji 31 → 3171 → 3171010 → 3171010001 (pola AlamatTest).
     */
    private function siapkanWilayah(): void
    {
        foreach ([
            'provinsi'       => ['id_provinsi' => '31', 'provinsi' => 'DKI Jakarta'],
            'kabupaten_kota' => ['id_kabupaten_kota' => '3171', 'id_provinsi' => '31', 'kabupaten_kota' => 'Jakarta Pusat'],
            'kecamatan'      => ['id_kecamatan' => '3171010', 'id_kabupaten_kota' => '3171', 'kecamatan' => 'Gambir'],
            'kelurahan'      => ['id_kelurahan' => '3171010001', 'id_kecamatan' => '3171010', 'kelurahan' => 'Gambir'],
        ] as $tabel => $row) {
            $pk = array_key_first($row);

            if ($this->db->table($tabel)->where($pk, $row[$pk])->countAllResults() === 0) {
                $this->db->table($tabel)->insert($row + ['order' => 1, 'status' => 1]);
            }
        }
    }

    private function engine(): RiwayatEngine
    {
        /** @var RiwayatEngine $engine */
        $engine = service('riwayatService');

        return $engine;
    }
}
