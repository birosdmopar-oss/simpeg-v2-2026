<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AnakKeluarga;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Keluarga;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Closure;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-16 Riwayat Keluarga + anak (WS-1 M4 MAKE-007): B-TC generik, snapshot `pegawai_keluarga`, sub-resource anak
 * (`AnakKeluarga`: jumlah_anak, akte kode 3 per anak, status induk, kunci), lampiran akte lewat endpoint lampiran
 * (`entriLampiran()` di OtorisasiLampiran) termasuk akte milik keluarga NIP lain → 404.
 *
 * @internal
 */
final class KeluargaTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private Keluarga $d;
    private string $nip;
    private AuthContext $admin;
    private AuthContext $adminSatker;

    /**
     * @var array<string, mixed>
     */
    private array $akunAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new Keluarga();
        $this->pasangEngineNyata([$this->d]);
        $idSatker                          = $this->buatSatker();
        $this->nip                         = $this->buatPegawaiDiSatker($idSatker);
        [, $this->admin, $this->akunAdmin] = $this->aktor(Role::SUPER_ADMIN);
        $this->adminSatker                 = $this->aktor(Role::ADMIN_SATKER, [], $idSatker)[1];
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
        return $this->dataKeluarga('2015-05-05');
    }

    protected function btcBaris(string $nip): array
    {
        return $this->dataKeluarga('2015-05-05');
    }

    protected function btcBerkas(): array
    {
        return [Keluarga::LAMPIRAN_BUKU_NIKAH => $this->berkasUji('nikah.pdf')];
    }

    protected function btcScopeRole3(array $nipDalam): null
    {
        return null;
    }

    public function testIzinMatriksV2(): void
    {
        foreach (Role::all() as $role) {
            $boleh = in_array($role, [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PPPK], true);
            $this->assertSame($boleh, $this->d->boleh(AksiRiwayat::Lihat, $role), "lihat {$role}");
            $this->assertSame($boleh, $this->d->boleh(AksiRiwayat::Ubah, $role), "ubah {$role}");
            $this->assertSame(in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true), $this->d->boleh(AksiRiwayat::Proses, $role));
        }
    }

    public function testBukuNikahWajibDanKodeAkteBukanLampiranBarisKeluarga(): void
    {
        $this->assertErrors(['berkas.20'], fn () => $this->engine()->tambah($this->admin, $this->nip, 'keluarga', $this->dataKeluarga('2015-05-05')));
        // Kode 3 milik baris detail_anak: tidak diterima di POST riwayat keluarga.
        $this->assertErrors(['berkas.3'], fn () => $this->tambahKeluarga('2015-05-05', [Keluarga::LAMPIRAN_BUKU_NIKAH => $this->berkasUji('nikah.pdf'), Keluarga::LAMPIRAN_AKTE_ANAK => $this->berkasUji('akte.pdf')]));
        $this->assertErrors(['tgl_perkawinan'], fn () => $this->tambahKeluarga('2015-05-05') && $this->tambahKeluarga('2015-05-05'));
    }

    public function testAnakJumlahAnakSnapshotDanAkte(): void
    {
        $kel = (int) $this->tambahKeluarga('2015-05-05')['id_riwayat_keluarga'];
        $this->assertSame('0', (string) $this->snapshotUji('pegawai_keluarga', $this->nip)['jumlah_anak']);

        $this->assertErrors(['berkas'], fn () => $this->anak()->tambah($this->admin, $this->nip, $kel, $this->dataAnak(1), null));
        $this->assertErrors(['nama', 'tempat_lahir', 'tgl_lahir'], fn () => $this->anak()->tambah($this->admin, $this->nip, $kel, ['urutan' => 1], $this->berkasUji('akte.pdf')));

        $a1 = $this->anak()->tambah($this->admin, $this->nip, $kel, $this->dataAnak(1), $this->berkasUji('akte.pdf'));
        $a2 = $this->anak()->tambah($this->admin, $this->nip, $kel, $this->dataAnak(2), $this->berkasUji('akte.pdf'));
        $this->assertSame(['2', '2'], [(string) $this->baris($kel)['jumlah_anak'], (string) $this->snapshotUji('pegawai_keluarga', $this->nip)['jumlah_anak']]);
        $this->assertSame(['Anak 1', 'Anak 2'], array_column($this->anak()->daftar($this->admin, $this->nip, $kel), 'nama'));

        // Ubah tanpa akte boleh bila akte sudah ada.
        $this->assertSame('Anak Satu', $this->anak()->ubah($this->admin, $this->nip, $kel, (int) $a1['id_detail_anak'], ['nama' => 'Anak Satu'], null)['nama']);

        // Hapus anak = hapus keras + aktenya; jumlah dihitung ulang.
        $idA2 = (int) $a2['id_detail_anak'];
        $this->anak()->hapus($this->admin, $this->nip, $kel, $idA2);
        $this->assertSame('1', (string) $this->snapshotUji('pegawai_keluarga', $this->nip)['jumlah_anak']);
        $this->assertSame(0, $this->db->table('document_attachment')->where('id_riwayat', Keluarga::LAMPIRAN_AKTE_ANAK)->where('id_entri', $idA2)->countAllResults());
        $this->assertNotSame([], $this->auditUji('detail_anak', (string) $idA2));
    }

    public function testPegawaiMengubahAnakMengembalikanStatus0DanTerkunciSaatDisetujui(): void
    {
        [$nipPegawai, $pegawai] = $this->aktor(Role::PEGAWAI);
        $kel                    = (int) $this->engine()->tambah($pegawai, $nipPegawai, 'keluarga', $this->dataKeluarga('2016-06-06'), [20 => $this->berkasUji('nikah.pdf')])['id_riwayat_keluarga'];

        $this->engine()->proses($this->admin, $nipPegawai, 'keluarga', $kel, 'tolak', 'Buku nikah buram');
        $this->anak()->tambah($pegawai, $nipPegawai, $kel, $this->dataAnak(1), $this->berkasUji('akte.pdf'));
        $this->assertSame('0', (string) $this->db->table('riwayat_keluarga')->where('id_riwayat_keluarga', $kel)->get()->getRow()->status, 'ditolak → diajukan ulang');

        $this->engine()->proses($this->admin, $nipPegawai, 'keluarga', $kel, 'setujui', null);
        $this->assertStatusApi(403, fn () => $this->anak()->tambah($pegawai, $nipPegawai, $kel, $this->dataAnak(2), $this->berkasUji('akte.pdf')));
    }

    public function testAdminSatkerBolehTambahTetapiTidakHapusAnakKeluargaDisetujui(): void
    {
        $kel = (int) $this->tambahKeluarga('2015-05-05')['id_riwayat_keluarga'];
        $a   = $this->anak()->tambah($this->adminSatker, $this->nip, $kel, $this->dataAnak(1), $this->berkasUji('akte.pdf'));

        $this->assertStatusApi(403, fn () => $this->anak()->hapus($this->adminSatker, $this->nip, $kel, (int) $a['id_detail_anak']));
    }

    public function testLingkupDanKepemilikan(): void
    {
        $kel  = (int) $this->tambahKeluarga('2015-05-05')['id_riwayat_keluarga'];
        $a    = $this->anak()->tambah($this->admin, $this->nip, $kel, $this->dataAnak(1), $this->berkasUji('akte.pdf'));
        $lain = $this->buatPegawaiDiSatker($this->buatSatker());

        // Admin satker di luar lingkup → 403; keluarga milik NIP lain → 404; anak bukan milik keluarga → 404.
        [, $adminLain] = $this->aktor(Role::ADMIN_SATKER, [], $this->buatSatker());
        $this->assertStatusApi(403, fn () => $this->anak()->daftar($adminLain, $this->nip, $kel));
        $this->assertStatusApi(404, fn () => $this->anak()->daftar($this->admin, $lain, $kel));
        $kel2 = (int) $this->tambahKeluarga('2018-08-08')['id_riwayat_keluarga'];
        $this->assertStatusApi(404, fn () => $this->anak()->ubah($this->admin, $this->nip, $kel2, (int) $a['id_detail_anak'], ['nama' => 'X'], null));

        // Role 8 tanpa izin keluarga → 403.
        [, $role8] = $this->aktor(Role::PIMPINAN);
        $this->assertStatusApi(403, fn () => $this->anak()->daftar($role8, $this->nip, $kel));
    }

    public function testEndpointAnakDanLampiranAkte(): void
    {
        $kel  = (int) $this->tambahKeluarga('2015-05-05')['id_riwayat_keluarga'];
        $a    = (int) $this->anak()->tambah($this->admin, $this->nip, $kel, $this->dataAnak(1), $this->berkasUji('akte.pdf'))['id_detail_anak'];
        $base = "api/v1/pegawai/{$this->nip}/riwayat/keluarga/{$kel}/anak";

        $this->get($base)->assertStatus(401);
        $this->assertSame([$a], array_map('intval', array_column($this->json($this->asUser($this->akunAdmin)->get($base))['data'], 'id_detail_anak')));
        $this->asUser($this->akunAdmin)->withBodyFormat('json')->post($base, $this->dataAnak(2))->assertStatus(422);
        $this->asUser($this->akunAdmin)->withBodyFormat('json')->put("{$base}/{$a}", ['nama' => 'Ganti Nama'])->assertStatus(200);

        // Akte lewat endpoint lampiran: entri = id_detail_anak milik keluarga NIP itu.
        $lampiran = $this->asUser($this->akunAdmin)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=3&id_entri={$a}");
        $lampiran->assertStatus(200);
        $this->assertCount(1, $this->json($lampiran)['data']);

        // Anak milik keluarga NIP lain → 404 (bukan IDOR).
        $lain    = $this->buatPegawaiDiSatker($this->buatSatker());
        $kelLain = (int) $this->engine()->tambah($this->admin, $lain, 'keluarga', $this->dataKeluarga('2019-09-09'), [20 => $this->berkasUji('nikah.pdf')])['id_riwayat_keluarga'];
        $aLain   = (int) $this->anak()->tambah($this->admin, $lain, $kelLain, $this->dataAnak(1), $this->berkasUji('akte.pdf'))['id_detail_anak'];
        $this->asUser($this->akunAdmin)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=3&id_entri={$aLain}")->assertStatus(404);

        $this->asUser($this->akunAdmin)->delete("{$base}/{$a}")->assertStatus(200);
    }

    public function testPegawaiMengubahAnakMemicuNotifikasiDiubah(): void
    {
        // Sama dengan RiwayatEngine::ubah(): keluarga diajukan ulang (status 0) → event riwayatNotifikasi tipe 3 sesudah commit.
        [$nipPegawai, $pegawai] = $this->aktor(Role::PEGAWAI);
        $kel                    = (int) $this->engine()->tambah($pegawai, $nipPegawai, 'keluarga', $this->dataKeluarga('2017-07-07'), [20 => $this->berkasUji('nikah.pdf')])['id_riwayat_keluarga'];
        $this->notifikasiUji    = [];

        $this->anak()->tambah($pegawai, $nipPegawai, $kel, $this->dataAnak(1), $this->berkasUji('akte.pdf'));

        $this->assertCount(1, $this->notifikasiUji);
        $this->assertSame(
            [RiwayatEngine::NOTIF_DIUBAH, 'keluarga', $nipPegawai, $kel, 0],
            [$this->notifikasiUji[0]['tipe'], $this->notifikasiUji[0]['jenis'], $this->notifikasiUji[0]['nip'], (int) $this->notifikasiUji[0]['id'], $this->notifikasiUji[0]['status']],
        );

        // Admin mengubah anak: tanpa notifikasi diajukan ulang.
        $this->notifikasiUji = [];
        $this->anak()->tambah($this->admin, $nipPegawai, $kel, $this->dataAnak(2), $this->berkasUji('akte.pdf'));
        $this->assertSame([], $this->notifikasiUji);
    }

    public function testKeluargaDihapusAnakDanAkte404(): void
    {
        $kel = (int) $this->tambahKeluarga('2015-05-05')['id_riwayat_keluarga'];
        $a   = (int) $this->anak()->tambah($this->admin, $this->nip, $kel, $this->dataAnak(1), $this->berkasUji('akte.pdf'))['id_detail_anak'];
        $this->engine()->hapus($this->admin, $this->nip, 'keluarga', $kel);

        $this->asUser($this->akunAdmin)->get("api/v1/pegawai/{$this->nip}/lampiran?id_riwayat=3&id_entri={$a}")->assertStatus(404);
        $this->asUser($this->akunAdmin)->get("api/v1/pegawai/{$this->nip}/riwayat/keluarga/{$kel}/anak")->assertStatus(404);
        $this->assertStatusApi(404, fn () => $this->anak()->ubah($this->admin, $this->nip, $kel, $a, ['nama' => 'X'], null));
    }

    /**
     * @return array<string, int|string>
     */
    private function dataKeluarga(string $tgl): array
    {
        return ['urutan_perkawinan' => 1, 'tgl_perkawinan' => $tgl, 'kota_perkawinan' => 'Jakarta', 'nama_pasangan' => 'Pasangan Uji'];
    }

    /**
     * @return array<string, int|string>
     */
    private function dataAnak(int $urutan): array
    {
        return ['urutan' => $urutan, 'nama' => "Anak {$urutan}", 'tempat_lahir' => 'Jakarta', 'tgl_lahir' => (2015 + $urutan) . '-01-01'];
    }

    /**
     * @param array<int, \CodeIgniter\HTTP\Files\UploadedFile>|null $berkas
     *
     * @return array<string, mixed>
     */
    private function tambahKeluarga(string $tgl, ?array $berkas = null): array
    {
        return $this->engine()->tambah($this->admin, $this->nip, 'keluarga', $this->dataKeluarga($tgl), $berkas ?? [Keluarga::LAMPIRAN_BUKU_NIKAH => $this->berkasUji('nikah.pdf')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(int $id): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->db->table('riwayat_keluarga')->where('id_riwayat_keluarga', $id)->get()->getRowArray();

        return $row;
    }

    private function anak(): AnakKeluarga
    {
        return new AnakKeluarga();
    }

    private function engine(): RiwayatEngine
    {
        /** @var RiwayatEngine $engine */
        $engine = service('riwayatService');

        return $engine;
    }

    private function assertStatusApi(int $status, Closure $aksi): void
    {
        try {
            $aksi();
        } catch (ApiException $e) {
            $this->assertSame($status, $e->getStatusCode(), $e->getMessage());

            return;
        }

        $this->fail("Diharapkan {$status}");
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
