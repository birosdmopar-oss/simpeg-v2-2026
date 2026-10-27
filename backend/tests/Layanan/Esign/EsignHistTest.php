<?php

declare(strict_types=1);

namespace Tests\Layanan\Esign;

use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\AttachmentServiceInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Esign\MockEsignAdapter;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use App\Libraries\Layanan\Cuti\CutiService;
use App\Libraries\Layanan\Cuti\EsignCutiService;
use App\Libraries\Layanan\Cuti\SuratCutiService;
use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\FakeAttachmentService;
use Tests\Support\Layanan\EsignUjiTrait;

/**
 * MAKE-023 M4 (PRD Fase 4 §6.5.3, AC-05.3) — jalur GAGAL: percobaan dicatat `esign_hist` status '2' dengan pesan
 * gateway (dipotong 255), status cuti tidak berubah, tidak ada berkas ber-TTE; percobaan tetap tercatat walau penyimpanan
 * berkas ber-TTE gagal dan transaksi bisnis di-rollback (perbaikan E5); satu baris per percobaan, append-only.
 *
 * @internal
 */
final class EsignHistTest extends DatabaseTestCase
{
    use EsignUjiTrait;

    protected $seed = MasterDataSeeder::class;

    private function admin(): AuthContext
    {
        return $this->authUntukAkun($this->buatAkunUntuk($this->buatPegawai(), 1));
    }

    public function testNipTanpaSertifikat404MockDicatatGagalDanStatusCutiTetap(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();

        try {
            $this->esignService([$a['pyb']['nip']])->terbitkan($this->admin(), $id, ['passphrase' => self::PASSPHRASE_UJI], '10.0.0.9', 'UA');
            $this->fail('Harus gagal.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Sertifikat tidak ditemukan', $e->getErrors()['nip'][0]);
        }

        $hist = $this->db->table('esign_hist')->where('id_rw', $id)->get()->getResultArray();
        $this->assertCount(1, $hist);
        $this->assertSame('2', $hist[0]['status']);
        $this->assertSame('404', $hist[0]['status_code']);
        $this->assertStringContainsString('Sertifikat tidak ditemukan', (string) $hist[0]['keterangan']);
        $this->assertSame(1, (int) $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->get()->getRowArray()['status']);
        $this->assertSame([], $this->lampiranUji->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_TTE, $id));
    }

    public function testNikPejabatKosongDicatatGagalDenganPesanNik(): void
    {
        ['id' => $id] = $this->cutiFinal(1, false);

        try {
            $this->esignService()->terbitkan($this->admin(), $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
            $this->fail('Harus gagal.');
        } catch (ValidationException $e) {
            $this->assertSame([EsignCutiService::PESAN_NIK], $e->getErrors()['nip']);
        }

        $hist = $this->db->table('esign_hist')->where('id_rw', $id)->get()->getRowArray();
        $this->assertSame('2', $hist['status']);
        $this->assertSame(EsignCutiService::PESAN_NIK, $hist['keterangan']);
    }

    public function testPenyimpananTteGagalMenulisHistGagalMeskiTransaksiBisnisRollback(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();

        $lampiran = new class (new FakeAttachmentService()) implements AttachmentServiceInterface {
            public function __construct(private readonly FakeAttachmentService $dalam)
            {
            }

            public function simpan(string $nip, int $idRiwayat, int|string $idEntri, UploadedFile $berkas, AturanLampiran $aturan): array
            {
                if ($idRiwayat === CutiService::ID_SURAT_TTE) {
                    throw new RuntimeException('disk penuh');
                }

                return $this->dalam->simpan($nip, $idRiwayat, $idEntri, $berkas, $aturan);
            }

            public function ambil(string $nip, int $idAttachment): array
            {
                return $this->dalam->ambil($nip, $idAttachment);
            }

            public function hapus(string $nip, int $idAttachment): void
            {
                $this->dalam->hapus($nip, $idAttachment);
            }

            public function daftar(string $nip, int $idRiwayat, int|string $idEntri): array
            {
                return $this->dalam->daftar($nip, $idRiwayat, $idEntri);
            }
        };
        $this->lampiranUji = new FakeAttachmentService();

        $svc = new EsignCutiService($this->db, $this->cutiService(), new SuratCutiService($this->db, $lampiran), new MockEsignAdapter(), new PegawaiScope($this->db));

        try {
            $svc->terbitkan($this->admin(), $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
            $this->fail('Harus gagal.');
        } catch (RuntimeException $e) {
            $this->assertSame('disk penuh', $e->getMessage());
        }

        $hist = $this->db->table('esign_hist')->where('id_rw', $id)->get()->getResultArray();
        $this->assertCount(1, $hist);
        $this->assertSame('2', $hist[0]['status']);
        $this->assertStringStartsWith('Dokumen ditandatangani gateway tetapi gagal disimpan', (string) $hist[0]['keterangan']);
        $this->assertSame([], $lampiran->daftar($a['pemohon']['nip'], CutiService::ID_SURAT_TTE, $id));
    }

    public function testSatuBarisPerPercobaanDanGagalLaluBerhasilMenambahBarisBaru(): void
    {
        ['id' => $id, 'a' => $a] = $this->cutiFinal();
        $admin                   = $this->admin();

        try {
            $this->esignService([$a['pyb']['nip']])->terbitkan($admin, $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
        } catch (ValidationException) {
            // percobaan pertama gagal dan tercatat
        }

        $this->esignService()->terbitkan($admin, $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);

        $status = array_column($this->db->table('esign_hist')->where('id_rw', $id)->orderBy('id_esign_hist')->get()->getResultArray(), 'status');
        $this->assertSame(['2', '1'], $status);
    }

    public function testKeteranganDipotong255Karakter(): void
    {
        ['id' => $id] = $this->cutiFinal();

        $gateway = new class () extends MockEsignAdapter {
            public function checkUser(string $nip): array
            {
                return ['status_code' => '500', 'message' => str_repeat('x', 400), 'nip' => $nip, 'has_certificate' => false, 'certificate_expires_at' => null];
            }
        };
        $svc = new EsignCutiService($this->db, $this->cutiService(), new SuratCutiService($this->db, $this->lampiranUji), $gateway, new PegawaiScope($this->db));

        try {
            $svc->terbitkan($this->admin(), $id, ['passphrase' => self::PASSPHRASE_UJI], null, null);
        } catch (ValidationException) {
            // dicatat sebagai percobaan gagal
        }

        $this->assertSame(255, strlen((string) $this->db->table('esign_hist')->where('id_rw', $id)->get()->getRowArray()['keterangan']));
    }
}
