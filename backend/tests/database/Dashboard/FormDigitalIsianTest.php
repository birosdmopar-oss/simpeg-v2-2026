<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Dashboard\Interaksi\FormDigital\IsianFormDigital;
use App\Libraries\Dashboard\Interaksi\FormDigital\SkemaForm;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F-06 sisi pegawai (MAKE-083): `GET form-digital/saya` (aktif, periode WIB, ditarget, belum diisi, urut order) dan
 * `POST form-digital/{id}/isi` (201, 409 ganda termasuk cabang UNIQUE, 404 di luar target/periode/nonaktif/dihapus, 422
 * jawaban, 403 role lain, berkas disimpan & dibersihkan saat 409, target tidak dihapus Q7-20).
 *
 * @internal
 */
final class FormDigitalIsianTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    private string $nip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        Time::setTestNow('2026-11-11 08:00:00', 'Asia/Jakarta');
        $this->nip = $this->buatPegawai();
        $this->buatAkunUntuk($this->nip, Role::PEGAWAI);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testSayaHanyaFormAktifDalamPeriodeDitargetBelumDiisiUrutOrder(): void
    {
        $b = $this->form(['judul_form' => 'B', 'order' => 2], [$this->nip]);
        $a = $this->form(['judul_form' => 'A', 'order' => 1], [$this->nip]);
        $this->form(['judul_form' => 'Nonaktif', 'status' => 2], [$this->nip]);
        $this->form(['judul_form' => 'Dihapus', 'status' => 10], [$this->nip]);
        $this->form(['judul_form' => 'Lewat', 'tgl_mulai' => '2026-11-01', 'tgl_akhir' => '2026-11-10'], [$this->nip]);
        $this->form(['judul_form' => 'Belum', 'tgl_mulai' => '2026-11-12', 'tgl_akhir' => '2026-11-30'], [$this->nip]);
        $this->form(['judul_form' => 'Orang lain'], [$this->buatPegawai()]);
        $sudah = $this->form(['judul_form' => 'Sudah'], [$this->nip]);
        $this->db->table('filled_form_digital')->insert(['id_form_digital' => $sudah, 'nip' => $this->nip, 'response' => '{}']);

        $data = $this->json($this->asNip($this->nip)->get('api/v1/form-digital/saya'))['data'];

        $this->assertSame([$a, $b], array_column($data, 'id'));
        $this->assertSame('teks', $data[0]['form']['pertanyaan'][0]['tipe']);
    }

    public function testIsiBerhasil201TargetTetapDanFormHilangDariSaya(): void
    {
        $id = $this->form([], [$this->nip]);

        $hasil = $this->asNip($this->nip)->post("api/v1/form-digital/{$id}/isi", ['jawaban' => json_encode(['nama' => 'Budi', 'setuju' => 'Ya'])]);

        $hasil->assertStatus(201);
        $baris = $this->db->table('filled_form_digital')->where('id_form_digital', $id)->get()->getRowArray();
        $this->assertIsArray($baris);
        $this->assertSame(['versi' => 1, 'jawaban' => ['nama' => 'Budi', 'setuju' => 'Ya']], json_decode((string) $baris['response'], true));
        $this->assertSame($this->nip, $baris['nip']);
        $this->assertNotNull($baris['created_by']);
        $this->assertSame(1, $this->db->table('user_form_digital')->where('id_form_digital', $id)->countAllResults(), 'target permanen (Q7-20)');
        $this->assertSame([], $this->json($this->asNip($this->nip)->get('api/v1/form-digital/saya'))['data']);

        $ulang = $this->asNip($this->nip)->post("api/v1/form-digital/{$id}/isi", ['jawaban' => json_encode(['nama' => 'Lagi'])]);
        $ulang->assertStatus(409);
        $this->assertSame('sudah_diisi', $this->json($ulang)['code'] ?? null);
    }

    public function testIsiDiLuarTargetPeriodeAtauNonaktif404(): void
    {
        $jawaban = ['jawaban' => json_encode(['nama' => 'Budi'])];

        foreach ([
            $this->form([], [$this->buatPegawai()]),
            $this->form(['status' => 2], [$this->nip]),
            $this->form(['status' => 10], [$this->nip]),
            $this->form(['tgl_mulai' => '2026-11-01', 'tgl_akhir' => '2026-11-10'], [$this->nip]),
            999999,
        ] as $id) {
            $this->asNip($this->nip)->post("api/v1/form-digital/{$id}/isi", $jawaban)->assertStatus(404);
        }

        $this->assertSame(0, $this->db->table('filled_form_digital')->countAllResults());
    }

    public function testJawabanTidakValid422(): void
    {
        $id = $this->form([], [$this->nip]);

        $this->asNip($this->nip)->post("api/v1/form-digital/{$id}/isi", ['jawaban' => json_encode(['setuju' => 'Ya'])])->assertStatus(422); // nama wajib
        $this->asNip($this->nip)->post("api/v1/form-digital/{$id}/isi", ['jawaban' => json_encode(['nama' => 'B', 'asing' => 1])])->assertStatus(422);
        $this->asNip($this->nip)->post("api/v1/form-digital/{$id}/isi", ['jawaban' => '{bukan json'])->assertStatus(422);
    }

    public function testRoleSelainPegawaiDitolak403(): void
    {
        $nipAdmin = $this->buatPegawai();
        $this->buatAkunUntuk($nipAdmin, Role::SUPER_ADMIN);

        $this->asNip($nipAdmin)->get('api/v1/form-digital/saya')->assertStatus(403);
        $this->asNip($nipAdmin)->post('api/v1/form-digital/1/isi', [])->assertStatus(403);
    }

    public function testCabangUniqueRace409DanBerkasDibersihkan(): void
    {
        $id      = $this->form(['form_digital' => SkemaForm::keJson(SkemaForm::normalisasi([['id' => 'bukti', 'tipe' => 'berkas', 'label' => 'Bukti', 'wajib' => true, 'berkas' => ['tipe' => ['png'], 'maks_mb' => 1]]]))], [$this->nip]);
        $db      = $this->db;
        $nip     = $this->nip;
        $storage = new class ($db, $id, $nip) implements StorageAdapterInterface {
            /** @var array<string, string> */
            public array $isi = [];

            /** Saat aktif, simpan() menyisipkan jawaban pesaing: meniru dua submit bersamaan yang lolos cek awal. */
            public bool $balapan = false;

            public function __construct(private readonly \CodeIgniter\Database\BaseConnection $db, private readonly int $id, private readonly string $nip)
            {
            }

            public function simpan(string $path, string $isi): void
            {
                $this->isi[$path] = $isi;

                if ($this->balapan) {
                    $this->db->table('filled_form_digital')->insert(['id_form_digital' => $this->id, 'nip' => $this->nip, 'response' => '{}']);
                }
            }

            public function hapus(string $path): void
            {
                unset($this->isi[$path]);
            }

            public function ada(string $path): bool
            {
                return isset($this->isi[$path]);
            }

            public function baca(string $path): string
            {
                return $this->isi[$path] ?? '';
            }
        };
        $auth = new AuthContext();
        $auth->setClaims($this->claimsForNip($this->nip));
        $layanan = new IsianFormDigital($this->db, $storage);

        $hasil = $layanan->isi($auth, $id, [], ['bukti' => $this->png()]);
        $this->assertCount(1, $storage->isi);
        $simpan = json_decode((string) $this->db->table('filled_form_digital')->where('id_filled_form_digital', $hasil['id'])->get()->getRow('response'), true);
        $this->assertStringStartsWith("form-digital/{$id}/", $simpan['jawaban']['bukti']['berkas']);
        $this->assertStringEndsWith('.png', $simpan['jawaban']['bukti']['berkas']);
        $this->assertSame('bukti.png', $simpan['jawaban']['bukti']['nama']);

        $this->db->table('filled_form_digital')->where('id_form_digital', $id)->delete();
        $storage->isi     = [];
        $storage->balapan = true;

        try {
            $layanan->isi($auth, $id, [], ['bukti' => $this->png()]);
            $this->fail('seharusnya 409');
        } catch (ApiException $e) {
            $this->assertSame([409, 'sudah_diisi'], [$e->getStatusCode(), $e->getKode()]);
        }

        $this->assertSame([], $storage->isi, 'berkas unggahan dihapus lagi saat 409');
        $this->assertSame(1, $this->db->table('filled_form_digital')->where('id_form_digital', $id)->countAllResults(), 'hanya jawaban pesaing');
    }

    /**
     * @param array<string, mixed> $isi
     * @param list<string>         $target
     */
    private function form(array $isi, array $target): int
    {
        $this->db->table('form_digital')->insert($isi + [
            'judul_form'   => 'Kuesioner',
            'tgl_mulai'    => '2026-11-01',
            'tgl_akhir'    => '2026-11-30',
            'form_digital' => SkemaForm::keJson(SkemaForm::normalisasi([
                ['id' => 'nama', 'tipe' => 'teks', 'label' => 'Nama', 'wajib' => true],
                ['id' => 'setuju', 'tipe' => 'pilihan_tunggal', 'label' => 'Setuju', 'opsi' => ['Ya', 'Tidak']],
            ])),
        ]);
        $id = (int) $this->db->insertID();

        foreach ($target as $nip) {
            $this->db->table('user_form_digital')->insert(['id_form_digital' => $id, 'nip' => $nip]);
        }

        return $id;
    }

    private function png(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fd');
        file_put_contents((string) $path, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));

        return new UploadedFile((string) $path, 'bukti.png', 'image/png', (int) filesize((string) $path), UPLOAD_ERR_OK);
    }
}
