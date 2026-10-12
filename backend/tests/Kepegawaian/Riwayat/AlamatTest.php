<?php

declare(strict_types=1);

namespace Tests\Kepegawaian\Riwayat;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\Definisi\Alamat;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Kepegawaian\Riwayat\RiwayatEngine;
use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\RiwayatBtcTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * B-16 Riwayat Alamat (WS-1 M4 MAKE-007): B-TC generik, snapshot dua target (`pegawai_alamat` alamat utama,
 * `pegawai_alamat_kantor` jenis kantor), rantai wilayah + LAIN-LAIN, kode pos, KTP wajib kecuali kantor, lewat Definisi
 * nyata dengan PegawaiScope + lampiran nyata.
 *
 * @internal
 */
final class AlamatTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use RiwayatBtcTrait;
    use RiwayatUjiTrait;

    private Alamat $d;
    private string $nip;
    private AuthContext $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = new Alamat();
        $this->pasangEngineNyata([$this->d]);
        $this->siapkanWilayah();
        $idSatker    = $this->buatSatker();
        $this->nip   = $this->buatPegawaiDiSatker($idSatker);
        $this->admin = $this->aktor(Role::SUPER_ADMIN)[1];
    }

    protected function tearDown(): void
    {
        $this->lepasEngine();

        parent::tearDown();
    }

    // ---- B-TC ----------------------------------------------------------

    protected function btcDefinisi(): RiwayatDefinisi
    {
        return $this->d;
    }

    protected function btcPayload(string $nip): array
    {
        return $this->dataAlamat();
    }

    protected function btcBaris(string $nip): array
    {
        return $this->dataAlamat() + ['provinsi' => 'DKI Jakarta', 'kabupaten_kota' => 'Jakarta Pusat', 'kecamatan' => 'Gambir', 'kelurahan' => 'Gambir'];
    }

    /**
     * Pegawai input mandiri wajib menyertakan KTP (jenis bukan kantor) → lewat service.
     */
    protected function btcBerkas(): array
    {
        return [Alamat::LAMPIRAN_KTP => $this->berkasUji('ktp.pdf')];
    }

    protected function btcScopeRole3(array $nipDalam): null
    {
        return null;
    }

    // ---- Aturan B-16 ---------------------------------------------------

    public function testIzinMatriksV2(): void
    {
        foreach (Role::all() as $role) {
            $boleh = in_array($role, [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK], true);

            foreach ([AksiRiwayat::Lihat, AksiRiwayat::Tambah, AksiRiwayat::Ubah, AksiRiwayat::Hapus] as $aksi) {
                $this->assertSame($boleh, $this->d->boleh($aksi, $role), "{$aksi->value} {$role}");
            }

            $this->assertSame(in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true), $this->d->boleh(AksiRiwayat::Proses, $role), "proses {$role}");
        }
    }

    public function testNamaWilayahDisalinDanSnapshotDuaTarget(): void
    {
        $ktp    = $this->tambah($this->dataAlamat(['alamat_utama' => 1]));
        $kantor = $this->tambah($this->dataAlamat(['jenis_alamat' => 3, 'alamat_utama' => 1, 'nama_kantor' => 'Kantor Pusat', 'telp' => '021-1']), []);

        $this->assertSame(['DKI Jakarta', 'Jakarta Pusat', 'Gambir', 'Gambir', '10110'], [$ktp['provinsi'], $ktp['kabupaten_kota'], $ktp['kecamatan'], $ktp['kelurahan'], $ktp['KdPos']]);
        $this->assertNull($ktp['nama_kantor']);
        $this->assertSame('2', (string) $kantor['alamat_utama'], 'kantor → alamat utama 2 (legacy sp())');

        $this->assertSame((string) $ktp['id_riwayat_alamat'], (string) $this->snapshotUji('pegawai_alamat', $this->nip)['id_riwayat_alamat']);
        $snapKantor = $this->snapshotUji('pegawai_alamat_kantor', $this->nip);
        $this->assertSame([(string) $kantor['id_riwayat_alamat'], 'Kantor Pusat'], [(string) $snapKantor['id_riwayat_alamat'], $snapKantor['nama_kantor']]);

        // Alamat utama lebih baru menggantikan snapshot; alamat bukan utama tidak.
        $baru = $this->tambah($this->dataAlamat(['jenis_alamat' => 2, 'alamat_utama' => 1, 'alamat' => 'Jl. Baru']));
        $this->tambah($this->dataAlamat(['jenis_alamat' => 2, 'alamat_utama' => 2, 'alamat' => 'Jl. Kos']));
        $this->assertSame((string) $baru['id_riwayat_alamat'], (string) $this->snapshotUji('pegawai_alamat', $this->nip)['id_riwayat_alamat']);
    }

    public function testKtpWajibKecualiKantor(): void
    {
        $this->assertErrors(['berkas.1'], fn () => $this->tambah($this->dataAlamat(), []));
        $this->assertSame('3', (string) $this->tambah($this->dataAlamat(['jenis_alamat' => 3]), [])['jenis_alamat']);
    }

    public function testAlamatUtamaWajibKecualiKantor(): void
    {
        $this->assertErrors(['alamat_utama'], fn () => $this->tambah($this->dataAlamat(['alamat_utama' => ''])));
    }

    public function testRantaiWilayahDanLainLain(): void
    {
        // Kecamatan bukan di bawah kabupaten terpilih.
        $this->assertErrors(['id_kecamatan'], fn () => $this->tambah($this->dataAlamat(['id_kecamatan' => '3172010', 'id_kelurahan' => '3172010001', 'kd_pos' => '14440'])));

        // LAIN-LAIN di kabupaten → level bawah wajib LAIN-LAIN dan teks lainnya wajib.
        $this->assertErrors(
            ['kabupaten_kota_lain', 'id_kecamatan', 'id_kelurahan'],
            fn () => $this->tambah($this->dataAlamat(['id_kabupaten_kota' => '9999'])),
        );

        $row = $this->tambah($this->dataAlamat([
            'id_kabupaten_kota' => '9999', 'kabupaten_kota_lain' => 'Kab. Baru', 'id_kecamatan' => '9999999', 'kecamatan_lain' => 'Kec. Baru',
            'id_kelurahan'      => '9999999999', 'kelurahan_lain' => 'Kel. Baru', 'kd_pos' => '12345', 'provinsi_lain' => 'diabaikan',
        ]));
        $this->assertSame(['Kab. Baru', 'Kel. Baru', null, 'DKI Jakarta'], [$row['kabupaten_kota_lain'], $row['kelurahan_lain'], $row['provinsi_lain'], $row['provinsi']]);
    }

    public function testKodePosLimaDigitDanDaftarKelurahan(): void
    {
        $this->assertErrors(['kd_pos'], fn () => $this->tambah($this->dataAlamat(['kd_pos' => '1011'])));
        $this->assertErrors(['kd_pos'], fn () => $this->tambah($this->dataAlamat(['kd_pos' => '10999'])), 'salah satu kode pos');
        $this->assertNull($this->tambah($this->dataAlamat(['kd_pos' => '']))['kd_pos']);
    }

    public function testPegawaiMengajukanStatus0DanTidakBisaHapusYangDisetujui(): void
    {
        [$nipPegawai, $pegawai] = $this->aktor(Role::PEGAWAI);
        $row                    = $this->engine()->tambah($pegawai, $nipPegawai, 'alamat', $this->dataAlamat(), [Alamat::LAMPIRAN_KTP => $this->berkasUji('ktp.pdf')]);

        $this->assertSame('0', (string) $row['status']);
        $this->assertNull($this->snapshotUji('pegawai_alamat', $nipPegawai));

        $this->engine()->proses($this->admin, $nipPegawai, 'alamat', (int) $row['id_riwayat_alamat'], 'setujui', null);

        try {
            $this->engine()->hapus($pegawai, $nipPegawai, 'alamat', (int) $row['id_riwayat_alamat']);
            $this->fail('403 diharapkan');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testStatusDiprosesTidakDipetakan(): void
    {
        // CHECK riwayat_alamat.status IN (0, 1, 2, 10).
        $this->assertSame([0, 1, 2, 10], array_keys($this->d->pemetaanStatus()));
    }

    /**
     * Wilayah uji: 31 DKI Jakarta → 3171 Jakarta Pusat → 3171010 Gambir → 3171010001 Gambir (kd_pos 10110); 3172 Jakarta
     * Utara → 3172010 Penjaringan → 3172010001 Penjaringan. Sentinel LAIN-LAIN sudah ada dari migration DBV-003.
     */
    private function siapkanWilayah(): void
    {
        $baris = [
            'provinsi'       => [['id_provinsi' => '31', 'provinsi' => 'DKI Jakarta']],
            'kabupaten_kota' => [
                ['id_kabupaten_kota' => '3171', 'id_provinsi' => '31', 'kabupaten_kota' => 'Jakarta Pusat'],
                ['id_kabupaten_kota' => '3172', 'id_provinsi' => '31', 'kabupaten_kota' => 'Jakarta Utara'],
            ],
            'kecamatan' => [
                ['id_kecamatan' => '3171010', 'id_kabupaten_kota' => '3171', 'kecamatan' => 'Gambir'],
                ['id_kecamatan' => '3172010', 'id_kabupaten_kota' => '3172', 'kecamatan' => 'Penjaringan'],
            ],
            'kelurahan' => [
                ['id_kelurahan' => '3171010001', 'id_kecamatan' => '3171010', 'kelurahan' => 'Gambir', 'kd_pos' => '10110'],
                ['id_kelurahan' => '3172010001', 'id_kecamatan' => '3172010', 'kelurahan' => 'Penjaringan', 'kd_pos' => '14440'],
            ],
        ];

        foreach ($baris as $tabel => $rows) {
            foreach ($rows as $row) {
                $pk = array_key_first($row);

                if ($this->db->table($tabel)->where($pk, $row[$pk])->countAllResults() === 0) {
                    $this->db->table($tabel)->insert($row + ['order' => 1, 'status' => 1]);
                }
            }
        }
    }

    /**
     * @param array<string, int|string> $override
     *
     * @return array<string, int|string>
     */
    private function dataAlamat(array $override = []): array
    {
        return $override + [
            'jenis_alamat'      => 1,
            'alamat_utama'      => 1,
            'id_provinsi'       => '31',
            'id_kabupaten_kota' => '3171',
            'id_kecamatan'      => '3171010',
            'id_kelurahan'      => '3171010001',
            'kd_pos'            => '10110',
            'alamat'            => 'Jl. Medan Merdeka Barat 17',
        ];
    }

    /**
     * @param array<string, mixed>          $data
     * @param array<int, UploadedFile>|null $berkas null = KTP PDF
     *
     * @return array<string, mixed>
     */
    private function tambah(array $data, ?array $berkas = null): array
    {
        return $this->engine()->tambah($this->admin, $this->nip, 'alamat', $data, $berkas ?? [Alamat::LAMPIRAN_KTP => $this->berkasUji('ktp.pdf')]);
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
    private function assertErrors(array $fields, Closure $aksi, ?string $pesan = null): void
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            $errors = $e->getErrors() ?? [];
            $this->assertSame($fields, array_keys($errors), (string) json_encode($errors));

            if ($pesan !== null) {
                $this->assertStringContainsString($pesan, (string) ($errors[$fields[0]][0] ?? ''));
            }

            return;
        }

        $this->fail('Diharapkan 422 untuk ' . implode(', ', $fields));
    }
}
