<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Libraries\Dashboard\Interaksi\FormDigital\SkemaForm;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F-06 rekap jawaban (MAKE-083, role 1): kolom dari skema (bukan jawaban pertama), unit/satker satu baris per NIP dari
 * snapshot jabatan, nilai ganda/berkas diformat, waktu WIB, XLSX semua baris + audit `dokumen_unduhan`, unduh berkas
 * jawaban hanya kunci milik form itu, 403 role lain.
 *
 * @internal
 */
final class FormDigitalResponsTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    /** @var array<string, mixed> */
    private array $admin;

    /** @var array<string, string> */
    private array $berkas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->admin = $this->buatAkunTanpaNip('admin.fd.rekap', Role::SUPER_ADMIN);
        $simpan      = &$this->berkas;
        Services::injectMock('storageAdapter', new class ($simpan) implements StorageAdapterInterface {
            /** @param array<string, string> $isi */
            public function __construct(private array &$isi)
            {
            }

            public function simpan(string $path, string $isi): void
            {
                $this->isi[$path] = $isi;
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
                return $this->isi[$path];
            }
        });
    }

    protected function tearDown(): void
    {
        Services::reset();
        Factories::reset();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testRekapKolomDariSkemaDanNilaiTerformat(): void
    {
        $id = $this->form();
        $a  = $this->buatPegawai(['nama' => 'Ani']);
        $b  = $this->buatPegawai(['nama' => 'Budi']);
        // Jawaban pertama tidak lengkap: kolom tetap dari skema.
        $this->jawab($id, $a, ['nama' => 'Ani'], '2026-11-11 01:00:00');
        $this->jawab($id, $b, ['nama' => 'Budi', 'minat' => ['A', 'B'], 'bukti' => ['berkas' => "form-digital/{$id}/x.pdf", 'nama' => 'bukti.pdf', 'ukuran' => 3]], '2026-11-11 02:00:00');

        $data = $this->json($this->asUser($this->admin)->get("api/v1/form-digital/{$id}/respons"))['data'];

        $this->assertSame(['nama', 'minat', 'bukti'], array_column($data['kolom'], 'id'));
        $this->assertSame(2, $data['total']);
        $this->assertSame([$a, $b], array_column($data['items'], 'nip'));
        $this->assertSame(['nama' => 'Ani', 'minat' => '', 'bukti' => ''], $data['items'][0]['jawaban']);
        $this->assertSame(['nama' => 'Budi', 'minat' => 'A, B', 'bukti' => 'bukti.pdf'], $data['items'][1]['jawaban']);
        $this->assertSame(['bukti'], $data['items'][1]['berkas']);
        $this->assertSame('2026-11-11 08:00:00', $data['items'][0]['diisi_at'], 'UTC → WIB');
    }

    public function testXlsxSemuaBarisDanAudit(): void
    {
        $id = $this->form();

        foreach (range(1, 3) as $i) {
            $this->jawab($id, $this->buatPegawai(), ['nama' => 'P' . $i], '2026-11-11 01:00:00');
        }

        $hasil = $this->asUser($this->admin)->get("api/v1/form-digital/{$id}/respons?format=xlsx&per_page=1");

        $hasil->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $hasil->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('PK', (string) $hasil->response()->getBody());
        $audit = $this->db->table('audit_logs')->where('entity', 'dokumen_unduhan')->where('entity_id', 'form_digital_' . $id)->get()->getRowArray();
        $this->assertIsArray($audit);
        $this->assertSame(3, json_decode((string) $audit['after_json'], true)['jumlah_baris']);
    }

    public function testUnduhBerkasJawabanHanyaKunciFormItu(): void
    {
        $id                                          = $this->form();
        $this->berkas["form-digital/{$id}/aman.pdf"] = '%PDF-1.4 isi';
        $this->berkas['form-digital/999/lain.pdf']   = '%PDF-1.4 lain';
        $j1                                          = $this->jawab($id, $this->buatPegawai(), ['nama' => 'A', 'bukti' => ['berkas' => "form-digital/{$id}/aman.pdf", 'nama' => 'bukti.pdf', 'ukuran' => 12]], '2026-11-11 01:00:00');
        $j2                                          = $this->jawab($id, $this->buatPegawai(), ['nama' => 'B', 'bukti' => ['berkas' => 'form-digital/999/lain.pdf', 'nama' => 'x.pdf', 'ukuran' => 1]], '2026-11-11 01:00:00');

        $ok = $this->asUser($this->admin)->get("api/v1/form-digital/{$id}/respons/{$j1}/berkas/bukti");
        $ok->assertStatus(200);
        $this->assertSame('%PDF-1.4 isi', (string) $ok->response()->getBody());
        $this->assertStringContainsString('bukti.pdf', $ok->response()->getHeaderLine('Content-Disposition'));

        $this->asUser($this->admin)->get("api/v1/form-digital/{$id}/respons/{$j2}/berkas/bukti")->assertStatus(404);
        $this->asUser($this->admin)->get("api/v1/form-digital/{$id}/respons/{$j1}/berkas/nama")->assertStatus(404);
        $this->asUser($this->admin)->get('api/v1/form-digital/' . ($id + 1) . "/respons/{$j1}/berkas/bukti")->assertStatus(404);
    }

    public function testRoleLainDitolak403(): void
    {
        $id  = $this->form();
        $nip = $this->buatPegawai();
        $this->buatAkunUntuk($nip, Role::ADMIN_SATKER);

        $this->asNip($nip)->get("api/v1/form-digital/{$id}/respons")->assertStatus(403);
        $this->asNip($nip)->get("api/v1/form-digital/{$id}/respons?format=xlsx")->assertStatus(403);
    }

    private function form(): int
    {
        $this->db->table('form_digital')->insert([
            'judul_form'   => 'Survei',
            'tgl_mulai'    => '2026-11-01',
            'tgl_akhir'    => '2026-11-30',
            'form_digital' => SkemaForm::keJson(SkemaForm::normalisasi([
                ['id' => 'nama', 'tipe' => 'teks', 'label' => 'Nama'],
                ['id' => 'minat', 'tipe' => 'pilihan_ganda', 'label' => 'Minat', 'opsi' => ['A', 'B']],
                ['id' => 'bukti', 'tipe' => 'berkas', 'label' => 'Bukti'],
            ])),
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $jawaban
     */
    private function jawab(int $id, string $nip, array $jawaban, string $utc): int
    {
        $this->db->table('filled_form_digital')->insert(['id_form_digital' => $id, 'nip' => $nip, 'response' => json_encode(['versi' => 1, 'jawaban' => $jawaban]), 'created_at' => $utc]);

        return (int) $this->db->insertID();
    }
}
