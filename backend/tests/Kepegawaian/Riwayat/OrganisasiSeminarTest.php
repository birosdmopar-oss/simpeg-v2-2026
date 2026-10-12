<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Organisasi;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Seminar;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Closure;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-17 Organisasi dan B-11 Kursus/Seminar (WS-1 M4 MAKE-007): aturan per jenis lewat Definisi nyata (B-TC generik ada di
 * OrganisasiBtcTest/SeminarBtcTest). Keduanya self-service tanpa snapshot.
 *
 * @internal
 */
final class OrganisasiSeminarTest extends DatabaseTestCase
{
    use RiwayatUjiTrait;

    private string $nip;
    private AuthContext $admin;
    private AuthContext $pegawai;
    private string $nipPegawai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pasangEngineNyata([new Organisasi(), new Seminar()]);
        $this->nip                          = $this->buatPegawaiDiSatker($this->buatSatker());
        $this->admin                        = $this->aktor(Role::SUPER_ADMIN)[1];
        [$this->nipPegawai, $this->pegawai] = $this->aktor(Role::PEGAWAI);
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    public function testIzinMatriksV2(): void
    {
        foreach ([new Organisasi(), new Seminar()] as $d) {
            foreach (Role::all() as $role) {
                $boleh = in_array($role, [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PPPK], true);

                foreach ([AksiRiwayat::Lihat, AksiRiwayat::Tambah, AksiRiwayat::Ubah, AksiRiwayat::Hapus] as $aksi) {
                    $this->assertSame($boleh, $d->boleh($aksi, $role), "{$d->jenis()} {$aksi->value} {$role}");
                }

                $this->assertSame(in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true), $d->boleh(AksiRiwayat::Proses, $role));
            }
        }
    }

    public function testOrganisasiDuplikatNamaPeriodeTermasukMasihAktif(): void
    {
        $this->organisasi($this->dataOrganisasi());
        $this->assertErrors(['nama_organisasi'], fn () => $this->organisasi($this->dataOrganisasi()));

        // Periode berakhir berbeda = bukan duplikat; tanggal akhir kosong = masih aktif (NULL).
        $row = $this->organisasi($this->dataOrganisasi(['tgl_akhir' => '2020-12-31']));
        $this->assertSame('2020-12-31', $row['tgl_akhir']);
        $this->assertNull($this->organisasi($this->dataOrganisasi(['tgl_mulai' => '2021-01-01']))['tgl_akhir']);
    }

    public function testOrganisasiPegawaiMengajukanStatus0(): void
    {
        $row = $this->engine()->tambah($this->pegawai, $this->nipPegawai, 'organisasi', $this->dataOrganisasi(), [Organisasi::LAMPIRAN_ORGANISASI => $this->berkasUji('org.pdf')]);

        $this->assertSame('0', (string) $row['status']);
    }

    public function testSeminarDuplikatDanLampiranSatuMb(): void
    {
        $this->seminar($this->dataSeminar());
        $this->assertErrors(['nama_seminar'], fn () => $this->seminar($this->dataSeminar(['nama_seminar' => '  Seminar Pariwisata  '])));

        // Legacy L_seminar: lampiran maksimal 1 MB.
        $this->assertSame(1, (new Seminar())->lampiran()[0]->batasMb);
        $this->assertErrors(['berkas.22'], fn () => $this->seminar($this->dataSeminar(['tgl_sertifikat' => '2024-02-02']), $this->berkasUji('besar.pdf', true, 1024 * 1024 + 1)));
    }

    public function testSeminarBidangDanInstansiTeksBebas(): void
    {
        $row = $this->seminar($this->dataSeminar(['bidang_seminar' => 'Bidang Baru Lainnya', 'instansi_penyelenggara' => 'Panitia Lokal', 'jenis_seminar' => 2]));

        $this->assertSame(['Bidang Baru Lainnya', 'Panitia Lokal', '2'], [$row['bidang_seminar'], $row['instansi_penyelenggara'], (string) $row['jenis_seminar']]);
        $this->assertErrors(['jenis_seminar'], fn () => $this->seminar($this->dataSeminar(['jenis_seminar' => 3, 'tgl_sertifikat' => '2025-01-01'])));
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function dataOrganisasi(array $override = []): array
    {
        return $override + ['nama_organisasi' => 'Korpri', 'kedudukan' => 'Anggota', 'tgl_mulai' => '2015-01-01', 'tgl_akhir' => ''];
    }

    /**
     * @param array<string, int|string> $override
     *
     * @return array<string, int|string>
     */
    private function dataSeminar(array $override = []): array
    {
        return $override + [
            'jenis_seminar'          => 1,
            'bidang_seminar'         => 'Pariwisata',
            'nama_seminar'           => 'Seminar Pariwisata',
            'no_sertifikat'          => 'SRT-1',
            'tgl_sertifikat'         => '2024-01-01',
            'jumlah_jp'              => 8,
            'instansi_penyelenggara' => 'Kemenparekraf',
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function organisasi(array $data): array
    {
        return $this->engine()->tambah($this->admin, $this->nip, 'organisasi', $data, [Organisasi::LAMPIRAN_ORGANISASI => $this->berkasUji('org.pdf')]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function seminar(array $data, ?\CodeIgniter\HTTP\Files\UploadedFile $berkas = null): array
    {
        return $this->engine()->tambah($this->admin, $this->nip, 'seminar', $data, [Seminar::LAMPIRAN_SERTIFIKAT => $berkas ?? $this->berkasUji('srt.pdf')]);
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
