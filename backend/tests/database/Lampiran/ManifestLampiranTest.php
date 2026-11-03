<?php

declare(strict_types=1);

namespace Tests\Database\Lampiran;

use App\Libraries\Lampiran\ManifestLampiran;
use CodeIgniter\Test\StreamFilterTrait;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-069 §4 — manifest berkas dari kolom berkas DB v2: path unik ternormalisasi (`fixArsipPath`), sumber per awalan,
 * rujukan per kolom, path tidak sah dihitung; command hanya mencetak jumlah.
 *
 * @internal
 */
final class ManifestLampiranTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    private const NIP = '000000000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('pegawai')->insert(['nip' => self::NIP, 'nama' => 'Uji', 'tgl_lahir' => '1990-01-01', 'jenis_kelamin' => 1, 'status' => 1, 'foto' => 'assets/upload/foto/1.jpg']);
        $this->db->table('document_attachment')->insertBatch([
            ['NIP' => self::NIP, 'path' => '/arsip/rw jabatan/SK 01.pdf'],
            ['NIP' => self::NIP, 'path' => 'arsip\\rw jabatan\\SK 01.pdf'],
            ['NIP' => self::NIP, 'path' => 'https://host-lain.test/x.pdf'],
            ['NIP' => self::NIP, 'path' => 'arsip/../rahasia'],
        ]);
    }

    public function testManifestUnikTernormalisasiDanTidakSahDihitung(): void
    {
        $m = ManifestLampiran::bangun($this->db);

        $this->assertSame(['arsip/rw jabatan/SK 01.pdf', 'assets/upload/foto/1.jpg'], array_keys($m['berkas']));
        $this->assertSame('arsip', $m['berkas']['arsip/rw jabatan/SK 01.pdf']['sumber']);
        $this->assertSame(['document_attachment.path' => 2], $m['berkas']['arsip/rw jabatan/SK 01.pdf']['rujukan']);
        $this->assertSame('aplikasi', $m['berkas']['assets/upload/foto/1.jpg']['sumber']);
        $this->assertSame(['document_attachment.path' => 2], $m['tidak_sah']);
    }

    public function testCommandMenulisManifestDanHanyaMencetakJumlah(): void
    {
        // command() mengurai argumen seperti shell: backslash Windows hilang, jadi pakai garis miring.
        $berkas = str_replace('\\', '/', WRITEPATH) . 'uji_manifest_' . bin2hex(random_bytes(4)) . '.json';
        $db     = $this->db->getDatabase();

        try {
            command("lampiran:manifest --keluaran={$berkas} --konfirmasi={$db}");
            $keluaran = $this->getStreamFilterBuffer();

            $this->assertStringContainsString('berkas unik sumber arsip: 1', $keluaran);
            $this->assertStringContainsString('path tidak sah document_attachment.path: 2', $keluaran);
            $this->assertStringNotContainsString('SK 01', $keluaran, 'nama berkas tidak dicetak');
            $this->assertCount(2, ManifestLampiran::baca($berkas)['berkas']);
        } finally {
            @unlink($berkas);
        }
    }
}
