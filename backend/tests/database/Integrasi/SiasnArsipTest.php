<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Exceptions\NotFoundException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Interfaces\SiasnDorongInterface;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\Log\IntegrasiLog;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Siasn\Arsip\AntreArsip;
use App\Libraries\Siasn\Arsip\UnggahArsip;
use App\Libraries\Siasn\Tarik\TarikRefUnor;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SiasnUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-067 M3 (H-03, F6-14; DBV-027 §3.3/§3.5) — fixture sintetis, FakeTransport:
 * - `siasn:tarik --jenis=ref-unor`: sisip baru (pemetaan lokal NULL), ubah kolom respons saja (pemetaan TIDAK disentuh),
 *   tetap, tolak, gagal → tanpa tulis, tanpa hapus;
 * - `siasn:unggah-arsip`: antre jabatan (9/36/41) & sertifikasi (5) yang sudah terkirim, kunci anti-antre-ulang legacy +
 *   UNIQUE, path tidak sah tidak diantre, idempoten; unggah status 0 → 3 (+ `updated_at`) → 1 (resp_* mentah) / 2
 *   (berkas hilang, bukan PDF / > 1 MB, gagal, timeout = status tidak diketahui); multipart `id_riwayat` + `id_ref_dokumen`
 *   872/881; exception → status 3 kembali 0; dry-run; command.
 *
 * @internal
 */
final class SiasnArsipTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SiasnUjiTrait;
    use StreamFilterTrait;

    private const NIP_A = '199001012020011001';

    protected $seed = MasterDataSeeder::class;

    /** @var array<int, string> id_da → isi berkas */
    private array $berkas = [];

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

    public function testRefUnorSisipUbahTanpaMenyentuhPemetaanTolakDanGagalTanpaTulis(): void
    {
        $this->db->table('siasn_ref_unor')->insertBatch([
            ['id' => 'U-1', 'instansi_id' => 'INS-UJI', 'nama_unor' => 'Unor Lama', 'nama_jabatan' => 'Kepala', 'id_jabatan' => 77, 'id_unit' => 5, 'id_satker' => 6, 'lvl_uker' => 2],
            ['id' => 'U-2', 'instansi_id' => 'INS-UJI', 'nama_unor' => 'Unor Tetap', 'nama_jabatan' => '', 'id_jabatan' => null, 'id_unit' => null, 'id_satker' => null, 'lvl_uker' => null],
            ['id' => 'U-HILANG', 'instansi_id' => 'INS-UJI', 'nama_unor' => 'Tidak dikembalikan', 'nama_jabatan' => '', 'id_jabatan' => 9, 'id_unit' => null, 'id_satker' => null, 'lvl_uker' => null],
        ]);

        $this->antreToken();
        $this->antreData([
            $this->unor('U-1', 'Unor Baru Nama', ['IsUnorSekolah' => false, 'EselonId' => '21']),
            $this->unor('U-2', 'Unor Tetap', ['NamaJabatan' => '']),
            $this->unor('U-3', 'Unor Baru', ['IsUnorSekolah' => true]),
            $this->unor(str_repeat('X', 101), 'Terlalu panjang'),
        ]);

        $h = $this->refUnor()->jalankan();

        $this->assertSame([true, 1, 1, 1, ['Id_panjang' => 1]], [$h['sukses'], $h['sisip'], $h['ubah'], $h['tetap'], $h['ditolak']]);
        $u = array_column($this->db->table('siasn_ref_unor')->get()->getResultArray(), null, 'id');
        $this->assertSame(['Unor Baru Nama', '21', '0', '77', '5', '6', '2'], [$u['U-1']['nama_unor'], $u['U-1']['eselon_id'], (string) $u['U-1']['is_unor_sekolah'], (string) $u['U-1']['id_jabatan'], (string) $u['U-1']['id_unit'], (string) $u['U-1']['id_satker'], (string) $u['U-1']['lvl_uker']], 'pemetaan lokal tidak disentuh');
        $this->assertSame(['1', null], [(string) $u['U-3']['is_unor_sekolah'], $u['U-3']['id_jabatan']]);
        $this->assertArrayHasKey('U-HILANG', $u, 'tanpa hapus [K]');
        $this->assertStringEndsWith('/referensi/ref-unor', $this->transport->tercatat()[2]['permintaan']->url);

        // Gagal → tanpa tulis, command keluar 1.
        $this->transport->antre(new Respons(503, 'x'), new Respons(503, 'x'), new Respons(503, 'x'));
        Services::injectMock('siasnGateway', $this->adapter);
        command('siasn:tarik --jenis=ref-unor');
        $this->assertStringContainsString('Tarik SIASN ref-unor: GAGAL (tanpa tulis)', $this->getStreamFilterBuffer());
        $this->assertSame('Unor Baru Nama', $this->db->table('siasn_ref_unor')->where('id', 'U-1')->get()->getRow()->nama_unor);

        $this->resetStreamFilterBuffer();
        command('siasn:tarik --jenis=ref-unor --nip=199001012020011001');
        $this->assertStringContainsString('ref-unor tanpa --nip', $this->getStreamFilterBuffer());
    }

    public function testAntreJabatanDanSertifikasiIdempotenKunciLegacyDanPathTidakSah(): void
    {
        $this->siapkanRiwayat();
        // Lampiran yang path-nya sudah pernah diantre (id_da lain) → kunci legacy nip***id_rw***path mencegah antre ulang.
        $this->db->table('document_attachment')->insert(['NIP' => self::NIP_A, 'id_riwayat' => 9, 'id_entri' => (string) $this->rmj, 'path' => 'arsip/jab/sk-lama.pdf', 'file_ext' => '.pdf', 'file_size' => 2000]);
        $this->db->table('siasn_upload_arsip')->insert(['nip' => self::NIP_A, 'rw_name' => AntreArsip::RW_JABATAN, 'id_rw' => $this->rmj, 'id_da' => 999999, 'arsip_path' => 'arsip/jab/sk-lama.pdf', 'status' => 1]);

        $h = (new AntreArsip($this->db))->jalankan(false, '2026-11-04 01:00:00');

        $this->assertSame(['baru' => 4, 'sudah_ada' => 1, 'path_tidak_sah' => 1], $h);
        $antre = $this->db->table('siasn_upload_arsip')->where('status', 0)->orderBy('id_siasn_upload_arsip', 'ASC')->get()->getResultArray();
        $this->assertSame(
            [['Rw Jabatan', 'SK Jabatan', 'arsip/jab/sk.pdf', 'RJ-1'], ['Rw Jabatan', 'SK Jabatan JFT', 'arsip/jab/sk-jft.pdf', 'RJ-1'], ['Rw Jabatan', 'Perjanjian Kerja', 'arsip/jab/pk.pdf', 'RJ-1'], ['Rw Sertifikasi', 'Sertifikat Sertifikasi Uji', 'arsip/dik/sert.pdf', 'SR-1']],
            array_map(static fn (array $b): array => [$b['rw_name'], $b['arsip_name'], $b['arsip_path'], $b['id_rw_siasn']], $antre),
        );
        $this->assertSame(['baru' => 0, 'sudah_ada' => 5, 'path_tidak_sah' => 1], (new AntreArsip($this->db))->jalankan(false, '2026-11-04 01:01:00'), 'idempoten (BP-6)');
    }

    public function testUnggahSuksesBerkasHilangTidakSahGagalTimeoutDanMultipart(): void
    {
        $this->siapkanRiwayat();
        (new AntreArsip($this->db))->jalankan(false, '2026-11-04 01:00:00');
        $id = array_map('intval', array_column($this->db->table('siasn_upload_arsip')->orderBy('id_siasn_upload_arsip', 'ASC')->get()->getResultArray(), 'id_siasn_upload_arsip'));
        // [0] sk.pdf sukses, [1] sk-jft.pdf hilang, [2] pk.pdf terlalu besar, [3] sert.pdf timeout.
        unset($this->berkas[$this->da['sk-jft']]);
        $this->berkas[$this->da['pk']] = '%PDF-' . str_repeat('x', UnggahArsip::BATAS_BYTE);

        $this->antreToken();
        $this->transport->antreJson(200, ['code' => 1, 'data' => ['dok_id' => 'DOK-1', 'dok_nama' => 'sk.pdf', 'dok_uri' => 'peremajaan/uji/sk.pdf', 'object' => 'obj-1', 'slug' => '872']]);
        $this->transport->antre(new GalatTransport('putus', true));

        $h = $this->unggah('2026-11-04 02:00:00')->jalankan('unggah', 10);

        $this->assertSame(['diproses' => 4, 'sukses' => 1, 'gagal' => 1, 'berkas_hilang' => 1, 'tidak_sah' => 1, 'status_tidak_diketahui' => 1], $h['unggah']);
        $r = array_column($this->db->table('siasn_upload_arsip')->get()->getResultArray(), null, 'id_siasn_upload_arsip');
        $this->assertSame(['1', 'DOK-1', 'peremajaan/uji/sk.pdf', '872', null], [(string) $r[$id[0]]['status'], $r[$id[0]]['resp_dok_id'], $r[$id[0]]['resp_dok_uri'], $r[$id[0]]['resp_slug'], $r[$id[0]]['error_msg']]);
        $this->assertSame(['2', 'Berkas tidak ditemukan.'], [(string) $r[$id[1]]['status'], $r[$id[1]]['error_msg']]);
        $this->assertSame(['2', 'Invalid file type or file size, size: 1MB'], [(string) $r[$id[2]]['status'], $r[$id[2]]['error_msg']]);
        $this->assertSame(['2', 'Timeout upload, size: 0MB'], [(string) $r[$id[3]]['status'], $r[$id[3]]['error_msg']]);
        $this->assertSame('2026-11-04 02:00:00', $r[$id[0]]['updated_at']);

        $post = array_values(array_filter($this->transport->tercatat(), static fn (array $t): bool => str_ends_with($t['permintaan']->url, '/upload-dok-rw')));
        $this->assertCount(2, $post, 'tanpa retry POST');
        $this->assertSame([['id_riwayat', 'RJ-1'], ['id_ref_dokumen', '872'], ['file', 'sk.pdf']], array_map(static fn (array $b): array => [$b['nama'], $b['namaBerkas'] ?? $b['isi']], $post[0]['permintaan']->multipart));
        $this->assertSame('881', $post[1]['permintaan']->multipart[1]['isi'], 'sertifikasi → 881 [K]');
        $this->assertStringNotContainsString(self::NIP_A, (string) json_encode($this->db->table('integrasi_log')->get()->getResultArray()));
    }

    public function testExceptionMengembalikanStatusTigaKeNolDanDryRunTanpaTulis(): void
    {
        $this->siapkanRiwayat();
        (new AntreArsip($this->db))->jalankan(false, '2026-11-04 01:00:00');

        $d = $this->unggah()->jalankan('semua', 10, true);
        $this->assertSame([0, 4], [$d['antre']['baru'], $d['unggah']['diproses']]);
        $this->assertSame(4, $this->db->table('siasn_upload_arsip')->where('status', 0)->countAllResults(), 'dry-run tanpa tulis');
        $this->assertSame([], $this->transport->tercatat());

        $putus = new class () implements SiasnDorongInterface {
            public function simpanRiwayat(string $jalur, array $payload, array $rahasia = [], ?string $refId = null): array
            {
                throw new RuntimeException('tidak dipakai');
            }

            public function unggahDokumen(string $idRiwayatSiasn, string $idRefDokumen, string $isi, string $namaBerkas, array $rahasia = []): array
            {
                throw new RuntimeException('proses terhenti');
            }
        };

        try {
            (new UnggahArsip($this->db, $putus, $this->lampiranPalsu(), new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db)))->jalankan('unggah', 10);
            $this->fail('exception harus dilempar ulang');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(4, $this->db->table('siasn_upload_arsip')->where('status', 0)->countAllResults(), 'status 3 kembali ke 0');
        $this->assertSame('galat', $this->db->table('integrasi_log')->where('operasi', 'unggah_arsip')->get()->getRow()->kode);
    }

    public function testCommandOpsiSalahDanBawaanSatuBaris(): void
    {
        foreach (['--langkah=hapus', '--limit=0', '--limit=101'] as $salah) {
            $this->resetStreamFilterBuffer();
            command("siasn:unggah-arsip {$salah}");
            $this->assertStringContainsString('Pemakaian:', $this->getStreamFilterBuffer(), $salah);
        }

        $this->siapkanRiwayat();
        Services::injectMock('siasnGateway', $this->adapter);
        Services::injectMock('attachmentService', $this->lampiranPalsu());
        $this->resetStreamFilterBuffer();
        command('siasn:unggah-arsip --dry-run');
        $keluar = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('[dry-run] Antre arsip SIASN: baru 4', $keluar);
        $this->assertStringContainsString('[dry-run] Unggah arsip SIASN: akan diproses 0', $keluar, 'dry-run: antrean belum ditulis');
        $this->assertStringNotContainsString(self::NIP_A, $keluar);
    }

    private int $rmj = 0;

    /** @var array<string, int> */
    private array $da = [];

    /**
     * PNS berpasangan dengan riwayat jabatan & sertifikasi terkirim + lampiran (salah satunya path tidak sah, satu bukan PDF).
     */
    private function siapkanRiwayat(): void
    {
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1, 'id_pns_siasn' => 'PNS-UJI-A']);
        $pmj = $this->db->table('pegawai_mutasi_jabatan')->where('nip', self::NIP_A)->get()->getRowArray();
        $this->db->table('riwayat_mutasi_jabatan')->insert([
            'nip'           => self::NIP_A, 'id_group_jabatan' => 1, 'id_jabatan' => $pmj['id_jabatan'], 'id_unit' => $pmj['id_unit'], 'id_satker' => $pmj['id_satker'],
            'jenis_jabatan' => 1, 'jenis_mutasi' => 1, 'tmtsk' => '2021-01-01', 'no_sk' => 'SK-1', 'status' => 1, 'siasn_flag' => 1, 'id_rw_siasn' => 'RJ-1',
        ]);
        $this->rmj = (int) $this->db->insertID();
        $this->db->table('riwayat_mutasi_jabatan')->insert([
            'nip'           => self::NIP_A, 'id_group_jabatan' => 1, 'id_jabatan' => $pmj['id_jabatan'], 'id_unit' => $pmj['id_unit'], 'id_satker' => $pmj['id_satker'],
            'jenis_jabatan' => 1, 'jenis_mutasi' => 1, 'tmtsk' => '2022-01-01', 'no_sk' => 'SK-2', 'status' => 1, 'siasn_flag' => 0,
        ]);
        $belum = (int) $this->db->insertID();
        $this->db->table('riwayat_diklat')->insert(['nip' => self::NIP_A, 'jenis_diklat' => 5, 'nama_diklat' => 'Sertifikasi Uji', 'id_rw_siasn' => 'SR-1', 'status' => 1, 'siasn_flag' => 1]);
        $sert = (int) $this->db->insertID();

        foreach ([
            'sk'     => [9, $this->rmj, 'arsip/jab/sk.pdf', '.pdf'],
            'sk-jft' => [36, $this->rmj, 'arsip/jab/sk-jft.pdf', 'pdf'],
            'pk'     => [41, $this->rmj, 'arsip/jab/pk.pdf', '.PDF'],
            'foto'   => [9, $this->rmj, 'arsip/jab/foto.jpg', '.jpg'],
            'jahat'  => [9, $this->rmj, 'arsip/../rahasia.pdf', '.pdf'],
            'belum'  => [9, $belum, 'arsip/jab/sk-belum.pdf', '.pdf'],
            'sert'   => [5, $sert, 'arsip/dik/sert.pdf', '.pdf'],
        ] as $kunci => [$kode, $entri, $path, $ext]) {
            $this->db->table('document_attachment')->insert(['NIP' => self::NIP_A, 'id_riwayat' => $kode, 'id_entri' => (string) $entri, 'path' => $path, 'file_ext' => $ext, 'file_size' => 2000]);
            $this->da[$kunci]                = (int) $this->db->insertID();
            $this->berkas[$this->da[$kunci]] = '%PDF-1.4 uji ' . $kunci;
        }
    }

    private function refUnor(): TarikRefUnor
    {
        return new TarikRefUnor($this->db, $this->adapter, new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db));
    }

    private function unggah(?string $jam = null): UnggahArsip
    {
        return new UnggahArsip($this->db, $this->adapter, $this->lampiranPalsu(), new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db), $jam === null ? null : static fn (): string => $jam);
    }

    private function lampiranPalsu(): AttachmentServiceInterface
    {
        $berkas = &$this->berkas;

        return new class ($berkas) implements AttachmentServiceInterface {
            /** @param array<int, string> $berkas */
            public function __construct(private array &$berkas)
            {
            }

            public function simpan(string $nip, int $idRiwayat, int|string $idEntri, UploadedFile $berkas, AturanLampiran $aturan): array
            {
                throw new RuntimeException('tidak dipakai');
            }

            public function ambil(string $nip, int $idAttachment): array
            {
                if (! isset($this->berkas[$idAttachment])) {
                    throw new NotFoundException();
                }

                return ['lampiran' => ['id_attachment' => $idAttachment], 'isi' => $this->berkas[$idAttachment]];
            }

            public function hapus(string $nip, int $idAttachment): void
            {
            }

            public function daftar(string $nip, int $idRiwayat, int|string $idEntri): array
            {
                return [];
            }
        };
    }

    /**
     * @param array<string, mixed> $lain
     *
     * @return array<string, mixed>
     */
    private function unor(string $id, string $nama, array $lain = []): array
    {
        return $lain + ['Id' => $id, 'NamaUnor' => $nama, 'NamaJabatan' => 'Kepala', 'InstansiId' => 'INS-UJI', 'DiatasanId' => '', 'EselonId' => '', 'IndukUnorId' => '', 'PemimpinPnsId' => '', 'JenisUnorId' => '', 'IsUnorSekolah' => '', 'CepatKode' => ''];
    }
}
