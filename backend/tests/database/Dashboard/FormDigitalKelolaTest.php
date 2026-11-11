<?php

declare(strict_types=1);

namespace Tests\Database\Dashboard;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * F-06 kelola form digital (MAKE-083, role 1): buat + snapshot target, 422 (judul/periode/skema/target), daftar dengan
 * hitungan, ubah sinkron selisih yang mempertahankan pegawai yang sudah mengisi (Q7-20), kunci pertanyaan 409 (Q7-25),
 * status 1↔2, hapus lunak menjaga jawaban (Q7-19), target sudah/belum, audit, 403 role lain.
 *
 * @internal
 */
final class FormDigitalKelolaTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use PegawaiFixtureTrait;

    /** @var array<string, mixed> */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->admin = $this->buatAkunTanpaNip('admin.fd', Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testBuatMenyimpanFormDanSnapshotTarget(): void
    {
        [$a, $b, $c] = [$this->buatPegawai(), $this->buatPegawai(), $this->buatPegawai()];

        $hasil = $this->asUser($this->admin)->withBodyFormat('json')->post('api/v1/form-digital', $this->input(['target' => ['pegawai' => [$a, $b, $c], 'kecuali' => [$c]]]));

        $hasil->assertStatus(201);
        $data = $this->json($hasil)['data'];
        $this->assertSame(['judul_form' => 'Survei', 'status' => 1, 'jumlah_target' => 2, 'sudah_mengisi' => 0, 'belum_mengisi' => 2, 'terkunci' => false], array_intersect_key($data, array_flip(['judul_form', 'status', 'jumlah_target', 'sudah_mengisi', 'belum_mengisi', 'terkunci'])));
        $this->assertSame('q1', $data['form']['pertanyaan'][0]['id']);
        $this->assertSame(['unit_satker' => [], 'pegawai' => [$a, $b, $c], 'kecuali' => [$c]], array_intersect_key($data['target'], array_flip(['unit_satker', 'pegawai', 'kecuali'])));
        $kunci = array_map('strval', array_keys($data['target']['label_pegawai']));
        sort($kunci);
        $harap = [$a, $b, $c];
        sort($harap);
        $this->assertSame($harap, $kunci);
        $this->assertStringStartsWith($a . ' - ', $data['target']['label_pegawai'][$a]);
        $detail = $this->json($this->asUser($this->admin)->get('api/v1/form-digital/' . $data['id']))['data'];
        $this->assertSame($data['target']['label_pegawai'], $detail['target']['label_pegawai']);
        $nip = array_column($this->db->table('user_form_digital')->where('id_form_digital', $data['id'])->orderBy('nip')->get()->getResultArray(), 'nip');
        $exp = [$a, $b];
        sort($exp);
        $this->assertSame($exp, $nip);
        $this->assertSame(1, $this->db->table('audit_logs')->where('entity', 'form_digital')->where('entity_id', (string) $data['id'])->where('event', 'create')->countAllResults());
    }

    public function testValidasi422(): void
    {
        $nip = $this->buatPegawai();

        foreach ([
            'judul_form'      => ['judul_form' => ' '],
            'tgl_akhir'       => ['tgl_mulai' => '2026-11-10', 'tgl_akhir' => '2026-11-09'],
            'form.pertanyaan' => ['form' => []],
            'target'          => ['target' => []],
        ] as $field => $ubah) {
            $hasil = $this->asUser($this->admin)->withBodyFormat('json')->post('api/v1/form-digital', $this->input($ubah + ['target' => ['pegawai' => [$nip]]]));
            $hasil->assertStatus(422);
            $this->assertArrayHasKey($field, $this->json($hasil)['errors'] ?? [], $field);
        }

        $this->asUser($this->admin)->withBodyFormat('json')->post('api/v1/form-digital', $this->input(['target' => ['unit_satker' => ['bukan-format']]]))->assertStatus(422);
        $this->assertSame(0, $this->db->table('form_digital')->countAllResults());
    }

    public function testUbahSinkronSelisihMempertahankanYangSudahMengisiDanKunciPertanyaan(): void
    {
        [$a, $b, $c] = [$this->buatPegawai(), $this->buatPegawai(), $this->buatPegawai()];
        $id          = $this->buat(['target' => ['pegawai' => [$a, $b]]]);

        // Sebelum ada jawaban: pertanyaan boleh diubah.
        $this->asUser($this->admin)->withBodyFormat('json')->put("api/v1/form-digital/{$id}", $this->input(['target' => ['pegawai' => [$a, $b]], 'form' => [['id' => 'q1', 'tipe' => 'teks', 'label' => 'Nama lengkap']]]))->assertStatus(200);

        $this->db->table('filled_form_digital')->insert(['id_form_digital' => $id, 'nip' => $a, 'response' => '{}']);

        // Target diganti ke b, c: a sudah mengisi → tetap; c ditambah.
        $data = $this->json($this->asUser($this->admin)->withBodyFormat('json')->put("api/v1/form-digital/{$id}", $this->input(['judul_form' => 'Survei 2', 'target' => ['pegawai' => [$b, $c]], 'form' => [['id' => 'q1', 'tipe' => 'teks', 'label' => 'Nama lengkap']]])))['data'];
        $nip  = array_column($this->db->table('user_form_digital')->where('id_form_digital', $id)->get()->getResultArray(), 'nip');
        sort($nip);
        $exp = [$a, $b, $c];
        sort($exp);
        $this->assertSame($exp, $nip);
        $this->assertSame(['Survei 2', true, 3, 1, 2], [$data['judul_form'], $data['terkunci'], $data['jumlah_target'], $data['sudah_mengisi'], $data['belum_mengisi']]);

        $kunci = $this->asUser($this->admin)->withBodyFormat('json')->put("api/v1/form-digital/{$id}", $this->input(['target' => ['pegawai' => [$b]], 'form' => [['id' => 'q1', 'tipe' => 'teks', 'label' => 'Diubah']]]));
        $kunci->assertStatus(409);
        $this->assertSame('form_terkunci', $this->json($kunci)['code'] ?? null);
        $this->assertSame(3, $this->db->table('user_form_digital')->where('id_form_digital', $id)->countAllResults(), 'ditolak tanpa mengubah target');
    }

    public function testDaftarStatusHapusLunakDanTarget(): void
    {
        [$a, $b] = [$this->buatPegawai(), $this->buatPegawai()];
        $id      = $this->buat(['target' => ['pegawai' => [$a, $b]]]);
        $lain    = $this->buat(['judul_form' => 'Lain', 'order' => 2, 'target' => ['pegawai' => [$a]]]);
        $this->db->table('filled_form_digital')->insert(['id_form_digital' => $id, 'nip' => $a, 'response' => '{}']);

        $daftar = $this->json($this->asUser($this->admin)->get('api/v1/form-digital'))['data'];
        $this->assertSame([2, [$id, $lain]], [$daftar['total'], array_column($daftar['items'], 'id')]);
        $this->assertSame([2, 1, 1, true], [$daftar['items'][0]['jumlah_target'], $daftar['items'][0]['sudah_mengisi'], $daftar['items'][0]['belum_mengisi'], $daftar['items'][0]['terkunci']]);

        $target = $this->json($this->asUser($this->admin)->get("api/v1/form-digital/{$id}/target?isi=belum"))['data'];
        $this->assertSame([$b], array_column($target['items'], 'nip'));

        $this->asUser($this->admin)->withBodyFormat('json')->patch("api/v1/form-digital/{$id}/status", ['status' => 2])->assertStatus(200);
        $this->asUser($this->admin)->withBodyFormat('json')->patch("api/v1/form-digital/{$id}/status", ['status' => 10])->assertStatus(422);
        $this->assertSame(2, (int) $this->db->table('form_digital')->where('id_form_digital', $id)->get()->getRow('status'));

        $this->asUser($this->admin)->delete("api/v1/form-digital/{$id}")->assertStatus(200);
        $row = $this->db->table('form_digital')->where('id_form_digital', $id)->get()->getRowArray();
        $this->assertSame(10, (int) $row['status']);
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame(1, $this->db->table('filled_form_digital')->where('id_form_digital', $id)->countAllResults(), 'jawaban tetap (Q7-19)');
        $this->assertSame([$lain], array_column($this->json($this->asUser($this->admin)->get('api/v1/form-digital'))['data']['items'], 'id'));
        $this->assertSame([$id], array_column($this->json($this->asUser($this->admin)->get('api/v1/form-digital?status=10'))['data']['items'], 'id'));
        $this->asUser($this->admin)->withBodyFormat('json')->put("api/v1/form-digital/{$id}", $this->input(['target' => ['pegawai' => [$a]]]))->assertStatus(409);
        $this->asUser($this->admin)->get('api/v1/form-digital/999999')->assertStatus(404);
    }

    public function testRoleLainDitolak403(): void
    {
        $nip = $this->buatPegawai();
        $this->buatAkunUntuk($nip, Role::ADMIN_SATKER);

        $this->asNip($nip)->get('api/v1/form-digital')->assertStatus(403);
        $this->asNip($nip)->withBodyFormat('json')->post('api/v1/form-digital', $this->input(['target' => ['pegawai' => [$nip]]]))->assertStatus(403);
    }

    /**
     * @param array<string, mixed> $ubah
     *
     * @return array<string, mixed>
     */
    private function input(array $ubah = []): array
    {
        return $ubah + [
            'judul_form' => 'Survei',
            'tgl_mulai'  => '2026-11-01',
            'tgl_akhir'  => '2026-11-30',
            'order'      => 1,
            'keterangan' => 'Uji',
            'form'       => [['tipe' => 'teks', 'label' => 'Nama', 'wajib' => true]],
        ];
    }

    /**
     * @param array<string, mixed> $ubah
     */
    private function buat(array $ubah): int
    {
        $hasil = $this->asUser($this->admin)->withBodyFormat('json')->post('api/v1/form-digital', $this->input($ubah));
        $hasil->assertStatus(201);

        return (int) $this->json($hasil)['data']['id'];
    }
}
