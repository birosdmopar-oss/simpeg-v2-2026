<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Konket\KonketService;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Scope\PegawaiScope;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\RiwayatUjiTrait;

/**
 * Koreksi dokumen usang PRD F-10 §7.5 (MAKE-093): sejak adapter MAKE-069 (K-M3 opsi A), path legacy `arsip/…` di kolom
 * berkas dibaca lewat `PathLegacy` dari storage v2. Bila berkasnya sudah disalin, endpoint foto, lampiran Plt/Plh, dan
 * berkas Konket menjawab 200 (bukan 404 seperti tertulis di kontrak lama); berkas yang belum disalin tetap 404.
 * Perilaku ini sudah ada sebelum F-10; test ini hanya mengunci perilaku yang kini didokumentasikan.
 *
 * @internal
 */
final class PathArsipLegacyTerbacaTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LampiranUjiTrait;
    use RiwayatUjiTrait {
        LampiranUjiTrait::berkasUji insteadof RiwayatUjiTrait;
    }

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /**
     * @var array<string, mixed>
     */
    private array $super;

    protected function setUp(): void
    {
        parent::setUp();

        Time::setTestNow('2026-10-13 10:00:00', 'Asia/Jakarta');
        Services::injectMock('pegawaiScope', new PegawaiScope());
        Services::injectMock('storageAdapter', new LocalStorageAdapter($this->akarLampiranUji()));
        [, , $this->super] = $this->aktor(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        $this->bersihkanLampiranUji();
        Services::reset(true);

        parent::tearDown();
    }

    public function testFotoPathArsipLegacyYangSudahDisalin200(): void
    {
        $this->tulisBerkas('arsip/uji/umum/foto lama.png', (string) base64_decode(self::PNG_1X1, true));
        $nip = $this->buatPegawai(['foto' => '/arsip/uji/umum/foto lama.png']);

        $res = $this->asUser($this->super)->get("api/v1/pegawai/{$nip}/foto");
        $res->assertStatus(200);
        $this->assertStringStartsWith('image/png', $res->response()->getHeaderLine('Content-Type'));

        $this->db->table('pegawai')->where('nip', $nip)->update(['foto' => 'arsip/uji/umum/belum disalin.png']);
        $this->asUser($this->super)->get("api/v1/pegawai/{$nip}/foto")->assertStatus(404);
    }

    public function testLampiranPltPlhPathArsipLegacyYangSudahDisalin200(): void
    {
        $jab = $this->buatJabatan();
        $nip = $this->buatPegawai();
        $isi = "%PDF-1.4\n%%EOF";
        $this->tulisBerkas('arsip/uji/plt/sk plt.pdf', $isi);

        foreach (['plt', 'plh'] as $jenis) {
            $res = $this->asUser($this->super)->withBodyFormat('json')->post("api/v1/penugasan/{$jenis}", $this->dataPenugasan($jenis, $jab, $nip));
            $res->assertStatus(201);
            $id = (int) json_decode($res->getJSON(), true)['data']['id'];

            $this->db->table("pegawai_{$jenis}")->where("id_pegawai_{$jenis}", $id)->update(['lampiran' => 'arsip/uji/plt/sk plt.pdf']);
            $ok = $this->asUser($this->super)->get("api/v1/penugasan/{$jenis}/{$id}/lampiran");
            $ok->assertStatus(200);
            $this->assertSame($isi, $ok->response()->getBody());

            $this->db->table("pegawai_{$jenis}")->where("id_pegawai_{$jenis}", $id)->update(['lampiran' => 'arsip/uji/plt/belum disalin.pdf']);
            $this->asUser($this->super)->get("api/v1/penugasan/{$jenis}/{$id}/lampiran")->assertStatus(404);
        }
    }

    public function testBerkasKonketPathArsipLegacyYangSudahDisalin200(): void
    {
        $this->db->table('jenis_konket')->insert(['id_jenis_konket' => 101, 'old_id' => 1, 'jenis_konket' => 'Izin Uji', 'affect_tukin' => 1, 'status' => 1]);
        [, $pegawai] = $this->aktor(Role::PEGAWAI, [], $this->buatSatker());
        $svc         = new KonketService(null, null, new KolomBerkas(new LocalStorageAdapter($this->akarLampiranUji()), $this->db));
        $id          = (int) $svc->tambah($pegawai, ['date_start' => '2026-10-05', 'date_end' => '2026-10-05', 'kategori' => 1, 'alasan' => 'Keperluan keluarga'], $this->berkasPdf('bukti.pdf'))['id'];

        $isi = "%PDF-1.4\n%%EOF";
        $this->tulisBerkas('arsip/uji/konket/bukti lama.pdf', $isi);
        $this->db->table('absen_ijin')->where('id', $id)->update(['file_bukti' => '/arsip/uji/konket/bukti lama.pdf']);

        $ok = $this->asUser($this->super)->get("api/v1/konket/{$id}/berkas");
        $ok->assertStatus(200);
        $this->assertSame($isi, $ok->response()->getBody());

        $this->db->table('absen_ijin')->where('id', $id)->update(['file_bukti' => 'arsip/uji/konket/belum disalin.pdf']);
        $this->asUser($this->super)->get("api/v1/konket/{$id}/berkas")->assertStatus(404);
    }

    private function tulisBerkas(string $relatif, string $isi): void
    {
        $path = $this->akarLampiranUji() . '/' . $relatif;
        is_dir(dirname($path)) || mkdir(dirname($path), 0750, true);
        file_put_contents($path, $isi);
    }

    /**
     * @param array{id_unit: int, id_satker: int, id_group_jabatan: int, id_sub_group_jabatan: int, id_jabatan: int} $jab
     *
     * @return array<string, int|string>
     */
    private function dataPenugasan(string $jenis, array $jab, string $nip): array
    {
        return [
            'status'                        => 1,
            'jenis_jabatan'                 => 1,
            'tgl_mulai'                     => '2026-01-01',
            'tgl_akhir'                     => '2026-12-31',
            'id_group_jabatan'              => $jab['id_group_jabatan'],
            'id_sub_group_jabatan'          => $jab['id_sub_group_jabatan'],
            'id_jabatan'                    => $jab['id_jabatan'],
            'id_unit'                       => $jab['id_unit'],
            'id_satker'                     => $jab['id_satker'],
            "id_group_jabatan_{$jenis}"     => $jab['id_group_jabatan'],
            "id_sub_group_jabatan_{$jenis}" => $jab['id_sub_group_jabatan'],
            "id_unit_{$jenis}"              => $jab['id_unit'],
            "id_satker_{$jenis}"            => $jab['id_satker'],
            "nip_{$jenis}"                  => $nip,
        ];
    }
}
