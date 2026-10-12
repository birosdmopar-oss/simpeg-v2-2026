<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\TandaJasa;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Closure;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-17 Riwayat Tanda Jasa (WS-1 M4 MAKE-007): B-TC generik, snapshot `pegawai_tanda_jasa` hanya Satyalancana Karya
 * Satya (26/27/28), LAIN-LAIN (44) wajib teks, tanggal tidak kembar, nama master disalin.
 *
 * @internal
 */
final class TandaJasaTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private TandaJasa $d;
    private string $nip;
    private AuthContext $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new TandaJasa();
        $this->pasangEngineNyata([$this->d]);

        foreach ([5 => 'Bintang Jasa', 26 => 'Satyalancana Karya Satya X Tahun', 27 => 'Satyalancana Karya Satya XX Tahun', 44 => 'LAIN-LAIN'] as $id => $nama) {
            $this->db->table('tanda_jasa')->insert(['id_tanda_jasa' => $id, 'tanda_jasa' => $nama, 'order' => 1, 'status' => 1]);
        }

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
        return $this->dataTj(26, '2015-08-17');
    }

    protected function btcBaris(string $nip): array
    {
        return $this->dataTj(26, '2015-08-17') + ['tanda_jasa' => 'Satyalancana Karya Satya X Tahun'];
    }

    protected function btcScopeRole3(array $nipDalam): null
    {
        return null;
    }

    public function testIzinMatriksV2(): void
    {
        foreach (Role::all() as $role) {
            $admin = in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true);
            $lihat = $admin || in_array($role, [Role::PEGAWAI, Role::ADMIN_VIEW_ESELON1, Role::MENTERI], true);

            $this->assertSame($lihat, $this->d->boleh(AksiRiwayat::Lihat, $role), "lihat {$role}");

            foreach ([AksiRiwayat::Ubah, AksiRiwayat::Hapus, AksiRiwayat::Proses] as $aksi) {
                $this->assertSame($admin, $this->d->boleh($aksi, $role), "{$aksi->value} {$role}");
            }

            // Tambah hanya role 1 (tombol legacy Tj.php:64, keputusan user 12-10-2026).
            $this->assertSame($role === Role::SUPER_ADMIN, $this->d->boleh(AksiRiwayat::Tambah, $role), "tambah {$role}");
        }
    }

    public function testSnapshotHanyaKaryaSatyaTerbaru(): void
    {
        $this->tambah($this->dataTj(26, '2015-08-17'));
        $xx = $this->tambah($this->dataTj(27, '2025-08-17'));
        $this->tambah($this->dataTj(5, '2026-01-01'));

        $snap = $this->snapshotUji('pegawai_tanda_jasa', $this->nip);
        $this->assertSame([(string) $xx['id_riwayat_tanda_jasa'], 'Satyalancana Karya Satya XX Tahun'], [(string) $snap['id_riwayat_tanda_jasa'], $snap['tanda_jasa']]);
    }

    public function testLainLainWajibTeksDanDiabaikanUntukLainnya(): void
    {
        $this->assertErrors(['tanda_jasa_lain'], fn () => $this->tambah($this->dataTj(44, '2020-01-01')));
        $this->assertSame('Piagam Daerah', $this->tambah($this->dataTj(44, '2020-01-01') + ['tanda_jasa_lain' => 'Piagam Daerah'])['tanda_jasa_lain']);
        $this->assertNull($this->tambah($this->dataTj(5, '2021-01-01') + ['tanda_jasa_lain' => 'abaikan'])['tanda_jasa_lain']);
    }

    public function testTanggalTidakKembarDanSertifikatWajib(): void
    {
        $this->tambah($this->dataTj(5, '2020-01-01'));
        $this->assertErrors(['tgl_sertifikat'], fn () => $this->tambah($this->dataTj(26, '2020-01-01')));
        $this->assertErrors(['berkas.23'], fn () => $this->engine()->tambah($this->admin, $this->nip, 'tanda-jasa', $this->dataTj(26, '2022-01-01')));
    }

    /**
     * @return array<string, int|string>
     */
    private function dataTj(int $idTandaJasa, string $tgl): array
    {
        return ['id_tanda_jasa' => $idTandaJasa, 'no_sertifikat' => 'TJ/' . $tgl, 'tgl_sertifikat' => $tgl, 'negara' => 'Indonesia'];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function tambah(array $data): array
    {
        return $this->engine()->tambah($this->admin, $this->nip, 'tanda-jasa', $data, [TandaJasa::LAMPIRAN_TANDA_JASA => $this->berkasUji('tj.pdf')]);
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
