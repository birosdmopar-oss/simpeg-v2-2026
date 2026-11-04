<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Exceptions\NotFoundException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Interfaces\SiasnDorongInterface;
use App\Libraries\Integrasi\AkunSistemIntegrasi;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\Log\IntegrasiLog;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Siasn\Arsip\AntreArsip;
use App\Libraries\Siasn\Arsip\UnggahArsip;
use App\Libraries\Siasn\Dorong\SiasnTokenGagal;
use App\Libraries\Siasn\Tarik\SiasnTarikSibuk;
use App\Libraries\Siasn\Tarik\TarikRefUnor;
use App\Libraries\Siasn\Tarik\TarikSiasn;
use Closure;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Database;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SiasnUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Throwable;

/**
 * MAKE-067 M3 — pengaman `siasn:unggah-arsip` & `siasn:tarik --jenis=ref-unor` dari review kode B6 M3 (fixture sintetis):
 * B-1 hasil sesudah POST tidak pernah mengembalikan baris ke 0 (tanpa unggah ganda), P-2 hanya "tidak ada" yang permanen,
 * P-3 token/401, `code ≠ 1` & 3xx, isi tanpa `%PDF`, status 3 tidak dipilih, kunci sibuk, UNIQUE `id_da`, pengecualian
 * antre, dan validasi/rollback ref-unor.
 *
 * @internal
 */
final class SiasnArsipPengamanTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;
    use SiasnUjiTrait;

    private const NIP_A = '199001012020011001';

    private const NIP_B = '199202022021012002';

    protected $seed = MasterDataSeeder::class;

    /** @var array<int, string> id_da → isi berkas */
    private array $berkas = [];

    /** id_da → Throwable yang dilempar `ambil()` (galat selain "tidak ada"). */
    private array $galatAmbil = [];

    private int $rmj = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSiasn();
        $this->buatPegawai(['nip' => self::NIP_A, 'id_jenis_pegawai' => 1, 'id_pns_siasn' => 'PNS-UJI-A']);
        $this->rmj = $this->rmj(self::NIP_A, ['siasn_flag' => 1, 'id_rw_siasn' => 'RJ-1']);
    }

    protected function tearDown(): void
    {
        $this->pulihkanSiasn();
        parent::tearDown();
    }

    public function testHasilSesudahPostTidakTersimpanTidakKembaliKeNolDanJalanKeduaTanpaPost(): void
    {
        $a     = $this->antre('arsip/jab/a.pdf');
        $b     = $this->antre('arsip/jab/b.pdf');
        $rusak = false;
        $post  = 0;
        // [a] sukses, [b] timeout; sesudah POST pertama semua UPDATE hasil gagal (datetime tidak sah di mode strict).
        $siasn = $this->stub(static function () use (&$rusak, &$post): array {
            $rusak = true;

            return ++$post === 1
                ? ['status_code' => '200', 'message' => 'OK', 'data' => ['dok_id' => 'DOK-A']]
                : ['status_code' => 'timeout', 'message' => 'x', 'data' => null];
        });
        $jam = static function () use (&$rusak): string {
            return $rusak ? 'bukan-waktu' : '2026-11-04 02:00:00';
        };

        $h = $this->unggah($siasn, $jam)->jalankan('unggah', 10);

        $this->assertSame([2, 1, 1, 1, 2], [$h['unggah']['diproses'], $h['unggah']['sukses'], $h['unggah']['gagal'], $h['unggah']['status_tidak_diketahui'], $h['unggah']['tidak_tersimpan']]);
        $this->assertSame(['3', '3'], [$this->statusArsip($a), $this->statusArsip($b)], 'TIDAK kembali ke 0 (K-7: tidak diulang otomatis)');
        $this->assertSame(1, $this->db->table('integrasi_log')->where(['kode' => 'hasil_tidak_tersimpan', 'ref_id' => "siasn_upload_arsip:{$a}"])->countAllResults());
        $this->assertSame(1, $this->db->table('integrasi_log')->where(['kode' => 'hasil_tidak_tersimpan', 'ref_id' => "siasn_upload_arsip:{$b}"])->countAllResults());
        $this->assertStringNotContainsString(self::NIP_A, (string) json_encode($this->db->table('integrasi_log')->get()->getResultArray()));

        $rusak = false;
        $this->assertSame(0, $this->unggah($siasn, $jam)->jalankan('unggah', 10)['unggah']['diproses']);
        $this->assertSame(2, $post, 'jalan kedua tanpa POST');
    }

    public function testTokenDan401SaatUnggahMengembalikanKeNolDanKunciDilepas(): void
    {
        $id = $this->antre('arsip/jab/a.pdf');
        $this->transport->antre(new Respons(500, 'x'));
        $this->harusTokenGagal();
        $this->assertSame('0', $this->statusArsip($id));

        $this->antreToken();
        $this->transport->antre(new Respons(401, '{"message":"Unauthorized"}'));
        $this->antreToken();
        $this->transport->antre(new Respons(401, '{"message":"Unauthorized"}'));
        $this->harusTokenGagal();

        $this->assertSame('0', $this->statusArsip($id), '3 → 0 (POST tidak diterima)');
        $this->assertSame('1', (string) $this->db->query('SELECT IS_FREE_LOCK(?) AS bebas', [UnggahArsip::namaKunci($this->db)])->getRowArray()['bebas'], 'kunci dilepas');
    }

    public function testCodeBukanSatuDan3xxGagalUploadIsiTanpaPdfDanAwalanSampah(): void
    {
        $kode   = $this->antre('arsip/jab/a.pdf');
        $alih   = $this->antre('arsip/jab/b.pdf');
        $bukan  = $this->antre('arsip/jab/c.pdf', 'bukan dokumen pdf');
        $awalan = $this->antre('arsip/jab/d.pdf', "\xEF\xBB\xBF\r\n%PDF-1.4 uji");
        $tiga   = $this->antre('arsip/jab/e.pdf', '%PDF-1.4', 3);

        $this->antreToken();
        $this->transport->antreJson(200, ['code' => 0, 'data' => ['dok_id' => 'X']]);
        $this->transport->antre(new Respons(302, ''));
        $this->transport->antreJson(200, ['code' => 1, 'data' => ['dok_id' => 'DOK-D']]);

        $h = $this->unggah()->jalankan('unggah', 10);

        $this->assertSame([4, 1, 2, 1], [$h['unggah']['diproses'], $h['unggah']['sukses'], $h['unggah']['gagal'], $h['unggah']['tidak_sah']], 'status 3 tidak dipilih');
        $this->assertSame(['2', '2', '2', '1', '3'], [$this->statusArsip($kode), $this->statusArsip($alih), $this->statusArsip($bukan), $this->statusArsip($awalan), $this->statusArsip($tiga)]);
        $this->assertSame('Failed to upload to SIASN, size: 0MB', $this->baris($kode)['error_msg']);
        $this->assertSame('Failed to upload to SIASN, size: 0MB', $this->baris($alih)['error_msg'], '3xx = gagal (redirect tidak diikuti)');
        $this->assertSame('Invalid file type or file size, size: 0MB', $this->baris($bukan)['error_msg']);
        $this->assertSame('DOK-D', $this->baris($awalan)['resp_dok_id'], '%PDF di 1024 byte pertama diterima (saran #5)');

        $post = array_values(array_filter($this->transport->tercatat(), static fn (array $t): bool => str_ends_with($t['permintaan']->url, '/upload-dok-rw')));
        $this->assertSame("siasn_upload_arsip:{$kode}", $post[0]['permintaan']->refId, 'saran #7');
    }

    public function testGalatAmbilSelainTidakAdaDilemparDanBarisKembaliKeNol(): void
    {
        $id                    = $this->antre('arsip/jab/a.pdf');
        $da                    = (int) $this->baris($id)['id_da'];
        $this->galatAmbil[$da] = new DatabaseException('putus');

        try {
            $this->unggah()->jalankan('unggah', 10);
            $this->fail('galat DB harus dilempar (review B6 M3 P-2)');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(['0', null], [$this->statusArsip($id), $this->baris($id)['error_msg']], 'bukan "Berkas tidak ditemukan" permanen');
        $this->assertSame([], $this->transport->tercatat());
    }

    public function testKunciSibukUnggahArsipDanRefUnorTanpaPanggilanApi(): void
    {
        $this->antre('arsip/jab/a.pdf');
        $lain = Database::connect('tests', false);

        try {
            foreach ([UnggahArsip::namaKunci($this->db), TarikSiasn::namaKunci($this->db, 'ref-unor')] as $kunci) {
                $lain->query('SELECT GET_LOCK(?, 0)', [$kunci]);
            }

            foreach ([fn () => $this->unggah()->jalankan('semua', 10), fn () => $this->refUnor()->jalankan()] as $jalan) {
                try {
                    $jalan();
                    $this->fail('run kedua harus ditolak');
                } catch (SiasnTarikSibuk $e) {
                    $this->assertStringContainsString('sedang berjalan', $e->getMessage());
                }
            }
        } finally {
            $lain->query('SELECT RELEASE_LOCK(?)', [UnggahArsip::namaKunci($this->db)]);
            $lain->query('SELECT RELEASE_LOCK(?)', [TarikSiasn::namaKunci($this->db, 'ref-unor')]);
            $lain->close();
        }

        $this->assertSame([], $this->transport->tercatat());
        $this->assertSame(1, $this->db->table('siasn_upload_arsip')->where('status', 0)->countAllResults());
    }

    public function testAntrePengecualianDanUniqueIdDaDenganPathBerbeda(): void
    {
        // Lampiran sudah diantre dengan path lain (UNIQUE rw_name,id_rw,id_da) → tidak diantre ulang.
        $da = $this->lampiran(self::NIP_A, 9, $this->rmj, 'arsip/jab/sk-baru.pdf');
        $this->db->table('siasn_upload_arsip')->insert(['nip' => self::NIP_A, 'rw_name' => AntreArsip::RW_JABATAN, 'id_rw' => $this->rmj, 'id_da' => $da, 'arsip_path' => 'arsip/jab/sk-lama.pdf', 'status' => 1]);

        // Dikecualikan: satker 171, TMT < 2020, jenis_jabatan ≠ 1, sertifikasi non-PNS.
        if ($this->db->table('satker')->where('id_satker', 171)->countAllResults() === 0) {
            $this->buatSatker(null, ['id_satker' => 171]);
        }

        foreach ([['id_satker' => 171], ['tmtsk' => '2019-12-31'], ['jenis_jabatan' => 2]] as $i => $beda) {
            $this->lampiran(self::NIP_A, 9, $this->rmj(self::NIP_A, $beda + ['siasn_flag' => 1, 'id_rw_siasn' => "RJ-X{$i}"]), "arsip/jab/x{$i}.pdf");
        }

        $this->buatPegawai(['nip' => self::NIP_B, 'id_jenis_pegawai' => 2, 'id_pns_siasn' => 'PNS-UJI-B']);
        $this->db->table('riwayat_diklat')->insert(['nip' => self::NIP_B, 'jenis_diklat' => 5, 'nama_diklat' => 'Sertifikasi Uji', 'id_rw_siasn' => 'SR-B', 'status' => 1, 'siasn_flag' => 1]);
        $this->lampiran(self::NIP_B, 5, (int) $this->db->insertID(), 'arsip/dik/sert-b.pdf');

        // Kontrol positif: lampiran sah ikut diantre.
        $this->lampiran(self::NIP_A, 41, $this->rmj, 'arsip/jab/pk.pdf');

        $this->assertSame(['baru' => 1, 'sudah_ada' => 1, 'path_tidak_sah' => 0], (new AntreArsip($this->db))->jalankan(false, '2026-11-04 01:00:00'));
        $this->assertSame(['arsip/jab/pk.pdf'], array_column($this->db->table('siasn_upload_arsip')->where('status', 0)->get()->getResultArray(), 'arsip_path'));
    }

    public function testRefUnorIdGandaBenderaTidakSahPanjangDanGalatDbRollback(): void
    {
        $this->db->table('siasn_ref_unor')->insert(['id' => 'U-LAMA', 'instansi_id' => 'INS-UJI', 'nama_unor' => 'Lama', 'nama_jabatan' => '']);
        $this->antreToken();
        $this->antreData([
            $this->unor('U-1', 'Pertama'),
            $this->unor('U-1', 'Kedua'),
            $this->unor('U-2', 'Bendera', ['IsUnorSekolah' => 'ya']),
            $this->unor('U-3', str_repeat('n', 256)),
            $this->unor('U-4', 'Eselon', ['EselonId' => str_repeat('e', 51)]),
        ]);

        $h = $this->refUnor()->jalankan();

        $this->assertSame([1, 1, ['IsUnorSekolah_tipe' => 1, 'NamaUnor_panjang' => 1, 'EselonId_panjang' => 1]], [$h['sisip'], $h['ubah'], $h['ditolak']]);
        $this->assertSame(['Kedua'], array_column($this->db->table('siasn_ref_unor')->where('id', 'U-1')->get()->getResultArray(), 'nama_unor'), 'Id ganda → satu baris, nilai terakhir');
        $this->assertSame('sebagian_ditolak', $this->db->table('integrasi_log')->where('operasi', 'tarik_ref_unor')->orderBy('id_integrasi_log', 'DESC')->get()->getRow()->kode, 'saran #14');

        // Id beda huruf besar/kecil = sama di collation → INSERT kedua gagal → SATU transaksi di-rollback.
        $this->antreData([$this->unor('U-LAMA', 'Diubah'), $this->unor('U-9', 'A'), $this->unor('u-9', 'B')]);

        try {
            $this->refUnor()->jalankan();
            $this->fail('galat DB harus dilempar');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->db->table('siasn_ref_unor')->where('id', 'U-9')->countAllResults(), 'sisip di-rollback');
        $this->assertSame('Lama', $this->db->table('siasn_ref_unor')->where('id', 'U-LAMA')->get()->getRow()->nama_unor, 'ubah di-rollback');
        $this->assertSame('galat_db', $this->db->table('integrasi_log')->where('operasi', 'tarik_ref_unor')->orderBy('id_integrasi_log', 'DESC')->get()->getRow()->kode);
    }

    private function harusTokenGagal(): void
    {
        try {
            $this->unggah()->jalankan('unggah', 10);
            $this->fail('token/401 harus menghentikan unggah');
        } catch (SiasnTokenGagal) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function rmj(string $nip, array $data = []): int
    {
        $pmj = $this->db->table('pegawai_mutasi_jabatan')->where('nip', $nip)->get()->getRowArray();
        $this->db->table('riwayat_mutasi_jabatan')->insert($data + [
            'nip'           => $nip, 'id_group_jabatan' => 1, 'id_jabatan' => $pmj['id_jabatan'], 'id_unit' => $pmj['id_unit'], 'id_satker' => $pmj['id_satker'],
            'jenis_jabatan' => 1, 'jenis_mutasi' => 1, 'tmtsk' => '2021-01-01', 'no_sk' => 'SK-UJI', 'status' => 1, 'siasn_flag' => 0,
        ]);

        return (int) $this->db->insertID();
    }

    private function lampiran(string $nip, int $kode, int $entri, string $path, string $isi = '%PDF-1.4 uji'): int
    {
        $this->db->table('document_attachment')->insert(['NIP' => $nip, 'id_riwayat' => $kode, 'id_entri' => (string) $entri, 'path' => $path, 'file_ext' => '.pdf', 'file_size' => 2000]);
        $da                = (int) $this->db->insertID();
        $this->berkas[$da] = $isi;

        return $da;
    }

    /**
     * Baris antrean langsung (tanpa AntreArsip) untuk riwayat jabatan terkirim.
     */
    private function antre(string $path, string $isi = '%PDF-1.4 uji', int $status = 0): int
    {
        $da = $this->lampiran(self::NIP_A, 9, $this->rmj, $path, $isi);
        $this->db->table('siasn_upload_arsip')->insert([
            'nip'        => self::NIP_A, 'rw_name' => AntreArsip::RW_JABATAN, 'id_rw' => $this->rmj, 'id_rw_siasn' => 'RJ-1', 'id_da' => $da,
            'arsip_path' => $path, 'arsip_name' => 'SK Jabatan', 'status' => $status,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(int $id): array
    {
        return $this->db->table('siasn_upload_arsip')->where('id_siasn_upload_arsip', $id)->get()->getRowArray();
    }

    private function statusArsip(int $id): string
    {
        return (string) $this->baris($id)['status'];
    }

    /**
     * @param (Closure(): string)|null $jam
     */
    private function unggah(?SiasnDorongInterface $siasn = null, ?Closure $jam = null): UnggahArsip
    {
        return new UnggahArsip($this->db, $siasn ?? $this->adapter, $this->lampiranPalsu(), new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db), $jam);
    }

    private function refUnor(): TarikRefUnor
    {
        return new TarikRefUnor($this->db, $this->adapter, new IntegrasiLog($this->db), new AkunSistemIntegrasi($this->db));
    }

    /**
     * @param callable(): array{status_code: string, message: string, data: mixed} $balas
     */
    private function stub(callable $balas): SiasnDorongInterface
    {
        return new class ($balas) implements SiasnDorongInterface {
            /** @var callable */
            private $balas;

            public function __construct(callable $balas)
            {
                $this->balas = $balas;
            }

            public function simpanRiwayat(string $jalur, array $payload, array $rahasia = [], ?string $refId = null): array
            {
                throw new RuntimeException('tidak dipakai');
            }

            public function unggahDokumen(string $idRiwayatSiasn, string $idRefDokumen, string $isi, string $namaBerkas, array $rahasia = [], ?string $refId = null): array
            {
                return ($this->balas)();
            }
        };
    }

    private function lampiranPalsu(): AttachmentServiceInterface
    {
        $berkas = &$this->berkas;
        $galat  = &$this->galatAmbil;

        return new class ($berkas, $galat) implements AttachmentServiceInterface {
            /**
             * @param array<int, string>    $berkas
             * @param array<int, Throwable> $galat
             */
            public function __construct(private array &$berkas, private array &$galat)
            {
            }

            public function simpan(string $nip, int $idRiwayat, int|string $idEntri, UploadedFile $berkas, AturanLampiran $aturan): array
            {
                throw new RuntimeException('tidak dipakai');
            }

            public function ambil(string $nip, int $idAttachment): array
            {
                if (isset($this->galat[$idAttachment])) {
                    throw $this->galat[$idAttachment];
                }

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
