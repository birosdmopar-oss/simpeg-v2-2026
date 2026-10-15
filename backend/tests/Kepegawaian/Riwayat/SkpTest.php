<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Skp;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Closure;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-12a Riwayat SKP tahunan (WS-1 M5 MAKE-008): B-TC generik, skema nilai (≤2021) vs rating (≥2022) ikut legacy
 * `L_skp.php`, kolom skema lain dikosongkan, duplikat periode hanya terhadap status 1, lampiran 32 wajib.
 *
 * @internal
 */
final class SkpTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private Skp $d;
    private string $nip;
    private AuthContext $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new Skp();
        $this->pasangEngineNyata([$this->d]);
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
        return $this->rating2022();
    }

    protected function btcBaris(string $nip): array
    {
        return $this->rating2022();
    }

    protected function btcBerkas(): array
    {
        return [Skp::LAMPIRAN_SKP => $this->berkasUji('skp.pdf')];
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
            $this->assertSame($boleh, $this->d->boleh(AksiRiwayat::Tambah, $role), "tambah {$role}");
            $this->assertSame(in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true), $this->d->boleh(AksiRiwayat::Proses, $role));
        }
    }

    public function testSkemaNilaiDanRatingMenurutTahun(): void
    {
        // ≥2022: rating wajib, kolom nilai dikosongkan walau dikirim.
        $this->assertErrors(['rating_skp', 'rating_perilaku'], fn () => $this->tambah(['rating_skp' => '', 'rating_perilaku' => ''] + $this->rating2022()));
        $row = $this->tambah(['nilai_skp' => 90] + $this->rating2022());
        $this->assertSame(['3', '2', null], [(string) $row['rating_skp'], (string) $row['rating_perilaku'], $row['nilai_skp']]);

        // ≤2021: nilai wajib, rating dikosongkan.
        $this->assertErrors(
            ['nilai_skp', 'nilai_skp_60_persen', 'nilai_perilaku', 'nilai_perilaku_40_persen', 'nilai_prestasi_kerja'],
            fn () => $this->tambah(['tahun' => 2020, 'tgl_mulai' => '2020-01-02', 'tgl_akhir' => '2020-12-31', 'kategori_nilai_prestasi' => 'Baik']),
        );
        $lama = $this->tambah([
            'tahun'          => 2020, 'tgl_mulai' => '2020-01-02', 'tgl_akhir' => '2020-12-31', 'nilai_skp' => 90, 'nilai_skp_60_persen' => 54,
            'nilai_perilaku' => 85, 'nilai_perilaku_40_persen' => 34, 'nilai_prestasi_kerja' => 88, 'kategori_nilai_prestasi' => 'Baik', 'rating_skp' => 3,
        ]);
        $this->assertSame(['88.00', null], [(string) $lama['nilai_prestasi_kerja'], $lama['rating_skp']]);
    }

    public function testDuplikatPeriodeHanyaTerhadapBarisDisetujui(): void
    {
        $this->tambah($this->rating2022());
        $this->assertErrors(['tahun'], fn () => $this->tambah($this->rating2022()));

        // Baris menunggu dengan periode sama tidak memblok (legacy hanya memeriksa status 1).
        $this->sisipRiwayat($this->d, $this->nip, ['tahun' => 2021, 'tgl_mulai' => '2021-01-02', 'tgl_akhir' => '2021-12-31', 'kategori_nilai_prestasi' => 'Baik', 'status' => 0]);
        $this->assertSame('2021', (string) $this->tambah([
            'tahun'          => 2021, 'tgl_mulai' => '2021-01-02', 'tgl_akhir' => '2021-12-31', 'nilai_skp' => 100, 'nilai_skp_60_persen' => 60,
            'nilai_perilaku' => 100, 'nilai_perilaku_40_persen' => 40, 'nilai_prestasi_kerja' => 100, 'kategori_nilai_prestasi' => 'Baik',
        ])['tahun']);
    }

    /**
     * Form legacy (`skp/form.php:306-372`, :455-500): tahun maksimal 2022, periode 2022 = 1 Jan s.d. 31 Des, batas nilai
     * skala 120 mulai 2021 dan 100 sebelumnya.
     */
    public function testAturanFormLegacyTahunPeriodeDanBatasNilai(): void
    {
        $this->assertErrors(['tahun'], fn () => $this->tambah(['tahun' => 2023] + $this->rating2022()));

        $row = $this->tambah(['tgl_mulai' => '2022-03-01', 'tgl_akhir' => '2022-06-30'] + $this->rating2022());
        $this->assertSame(['2022-01-01', '2022-12-31'], [$row['tgl_mulai'], $row['tgl_akhir']]);

        $nilai = static fn (int $tahun, float $skp, float $skp60): array => [
            'tahun'          => $tahun, 'tgl_mulai' => "{$tahun}-01-02", 'tgl_akhir' => "{$tahun}-12-31", 'nilai_skp' => $skp, 'nilai_skp_60_persen' => $skp60,
            'nilai_perilaku' => 50, 'nilai_perilaku_40_persen' => 20, 'nilai_prestasi_kerja' => 50, 'kategori_nilai_prestasi' => 'Kurang',
        ];
        $this->assertErrors(['nilai_skp'], fn () => $this->tambah($nilai(2020, 101, 60)));
        $this->assertErrors(['nilai_skp_60_persen'], fn () => $this->tambah($nilai(2021, 120, 72.5)));
        $this->assertSame('120.00', (string) $this->tambah($nilai(2021, 120, 72))['nilai_skp']);
    }

    public function testLampiranWajib(): void
    {
        $this->assertErrors(['berkas.32'], fn () => $this->engine()->tambah($this->admin, $this->nip, 'skp', $this->rating2022()));
    }

    /**
     * @return array<string, int|string>
     */
    private function rating2022(): array
    {
        return [
            'tahun'                   => 2022, 'tgl_mulai' => '2022-01-01', 'tgl_akhir' => '2022-12-31', 'rating_skp' => 3, 'rating_perilaku' => 2,
            'kategori_nilai_prestasi' => 'Baik', 'nama_penilai' => 'Penilai Uji',
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function tambah(array $data): array
    {
        return $this->engine()->tambah($this->admin, $this->nip, 'skp', $data, [Skp::LAMPIRAN_SKP => $this->berkasUji('skp.pdf')]);
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
