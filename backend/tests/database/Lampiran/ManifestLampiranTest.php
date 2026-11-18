<?php

declare(strict_types=1);

namespace Tests\Database\Lampiran;

use App\Libraries\Lampiran\ManifestLampiran;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use RuntimeException;
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

    public function testLampiranTiketLamaIkutManifest(): void
    {
        // MAKE-097 T-8 (Q-A0-5): lampiran pesan tiket lama (`inbox_msg.attachments` → `disiplin_ticket_msg`) ikut disalin.
        $this->db->table('disiplin_ticket')->insert([
            'id_inbox'    => 1, 'subject' => 'Uji', 'sender' => 11, 'sender_name' => 'Uji', 'nip' => self::NIP, 'receiver' => 0,
            'allow_reply' => 1, 'status' => 0, 'is_read' => 1, 'is_read_off' => 2, 'notify_pic' => 2,
        ]);
        $pesan = static fn (int $id, ?string $lampiran): array => [
            'id_inbox_msg' => $id, 'id_inbox' => 1, 'msg_sender' => 11, 'msg_sender_name' => 'Uji', 'msg_receiver' => 0, 'msg' => 'Isi',
            'show_notif'   => 1, 'is_read' => 0, 'attachments' => $lampiran,
        ];
        $this->db->table('disiplin_ticket_msg')->insertBatch([
            $pesan(10, 'arsip/support/lampiran 1.pdf'),
            $pesan(11, '/arsip/support/lampiran 1.pdf'),
            $pesan(12, null),
            $pesan(13, ''),
            $pesan(14, '../luar.pdf'),
        ]);

        $m = ManifestLampiran::bangun($this->db);

        $this->assertSame(['disiplin_ticket_msg.attachments' => 2], $m['berkas']['arsip/support/lampiran 1.pdf']['rujukan'] ?? null);
        $this->assertSame('arsip', $m['berkas']['arsip/support/lampiran 1.pdf']['sumber']);
        $this->assertSame(1, $m['tidak_sah']['disiplin_ticket_msg.attachments'] ?? null, 'path traversal hanya dihitung');
        $this->assertSame(['attachments'], ManifestLampiran::KOLOM['disiplin_ticket_msg']);
    }

    public function testPathBedaHurufBesarKecilAdalahBerkasBerbeda(): void
    {
        $this->db->table('document_attachment')->insertBatch([
            ['NIP' => self::NIP, 'path' => 'arsip/Kasus/A.pdf'],
            ['NIP' => self::NIP, 'path' => 'arsip/kasus/a.pdf'],
        ]);

        $m = ManifestLampiran::bangun($this->db);

        $this->assertArrayHasKey('arsip/Kasus/A.pdf', $m['berkas']);
        $this->assertArrayHasKey('arsip/kasus/a.pdf', $m['berkas'], 'GROUP BY BINARY (review P-1)');
    }

    public function testKolomBerkasTidakAdaGagalBukanDilewati(): void
    {
        $grup             = config(Database::class)->tests;
        $grup['DBDebug']  = false;
        $grup['DBPrefix'] = 'zz_tidak_ada_';
        $db               = Database::connect($grup, false);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('tidak ada di DB tujuan');
            ManifestLampiran::bangun($db);
        } finally {
            $db->close();
        }
    }
}
