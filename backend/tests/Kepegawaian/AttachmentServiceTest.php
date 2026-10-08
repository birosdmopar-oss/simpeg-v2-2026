<?php

declare(strict_types=1);

namespace Tests\Kepegawaian;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Kepegawaian\Lampiran\AttachmentService;
use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use App\Libraries\Kepegawaian\Riwayat\AturanLampiran;
use CodeIgniter\Database\Exceptions\DatabaseException;
use InvalidArgumentException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\LampiranUjiTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * MAKE-009 — AttachmentService nyata (DoD M1: simpan/hapus transaksional + kompensasi berkas): validasi dari isi,
 * keterikatan NIP, hapus keras + audit, dan rekonsiliasi berkas saat transaksi pemanggil commit/rollback.
 *
 * @internal
 */
final class AttachmentServiceTest extends DatabaseTestCase
{
    use LampiranUjiTrait;
    use PegawaiFixtureTrait;

    private const KODE_IJAZAH = 14;

    private AttachmentService $service;

    private LocalStorageAdapter $storage;

    private AturanLampiran $aturan;

    private string $nip;

    private int $idEntri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new LocalStorageAdapter($this->akarLampiranUji());
        $this->service = new AttachmentService($this->storage, $this->db);
        $this->aturan  = new AturanLampiran(self::KODE_IJAZAH, true, 1, ['pdf']);
        $this->nip     = $this->buatPegawai();
        $this->idEntri = $this->buatRiwayatPendidikanUji($this->nip);
    }

    protected function tearDown(): void
    {
        $this->bersihkanLampiranUji();

        parent::tearDown();
    }

    public function testSimpanMenulisBarisBerkasDanAudit(): void
    {
        $row = $this->service->simpan($this->nip, self::KODE_IJAZAH, $this->idEntri, $this->berkasPdf('../Ijazah S1.pdf'), $this->aturan);

        $this->assertArrayNotHasKey('path', $row, 'path internal tidak keluar');
        $this->assertSame($this->nip, $row['NIP']);
        $this->assertSame(self::KODE_IJAZAH, (int) $row['id_riwayat']);
        $this->assertSame((string) $this->idEntri, $row['id_entri']);
        $this->assertSame('Pendidikan', $row['nama_riwayat']);
        $this->assertSame('Ijazah S1.pdf', $row['display_name'], 'nama tampil tanpa folder kiriman klien');
        $this->assertSame('pdf', $row['file_ext']);
        $this->assertSame('application/pdf', $row['file_type']);
        $this->assertSame(2048, (int) $row['file_size']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', (string) $row['basename'], 'nama simpan dari server');

        $db = $this->db->table('document_attachment')->where('id_attachment', $row['id_attachment'])->get()->getRowArray();
        $this->assertIsArray($db);
        $this->assertSame([$db['path']], $this->berkasDiAkarUji());
        $this->assertStringEndsWith('/' . $row['basename'], (string) $db['path']);

        $audit = $this->db->table('audit_logs')->where('entity', 'document_attachment')->where('entity_id', (string) $row['id_attachment'])->get()->getResultArray();
        $this->assertSame(['create'], array_column($audit, 'event'));
    }

    public function testJenisBerkasDibacaDariIsiBukanNama(): void
    {
        $this->assertGagalValidasi(fn () => $this->service->simpan($this->nip, self::KODE_IJAZAH, $this->idEntri, $this->berkasPng('ijazah.pdf'), $this->aturan));
    }

    public function testUkuranMelebihiBatasDitolak(): void
    {
        $berkas = $this->berkasPdf('besar.pdf', 1024 * 1024 + 1);

        $this->assertGagalValidasi(fn () => $this->service->simpan($this->nip, self::KODE_IJAZAH, $this->idEntri, $berkas, $this->aturan));
    }

    public function testBerkasKosongAtauGagalUnggahDitolak(): void
    {
        $this->assertGagalValidasi(fn () => $this->service->simpan($this->nip, self::KODE_IJAZAH, $this->idEntri, $this->berkasUji('', 'kosong.pdf', 'application/pdf'), $this->aturan));
        $this->assertGagalValidasi(fn () => $this->service->simpan($this->nip, self::KODE_IJAZAH, $this->idEntri, $this->berkasUji('%PDF-1.4', 'a.pdf', 'application/pdf', UPLOAD_ERR_PARTIAL), $this->aturan));
    }

    public function testAturanBedaKodeDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->simpan($this->nip, 39, $this->idEntri, $this->berkasPdf(), $this->aturan);
    }

    public function testInsertGagalMenghapusBerkasSeketika(): void
    {
        // NIP tidak ada → FK document_attachment.NIP menolak insert; berkas yang sempat ditulis tidak boleh tertinggal.
        try {
            $this->service->simpan('000000000000000000', self::KODE_IJAZAH, 1, $this->berkasPdf(), $this->aturan);
            $this->fail('insert seharusnya gagal');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], $this->berkasDiAkarUji());
    }

    public function testAmbilHapusDanDaftarTerikatNip(): void
    {
        $row     = $this->service->simpan($this->nip, self::KODE_IJAZAH, $this->idEntri, $this->berkasPdf(), $this->aturan);
        $id      = (int) $row['id_attachment'];
        $nipLain = $this->buatPegawai();

        $this->assertSame([$id], array_map('intval', array_column($this->service->daftar($this->nip, self::KODE_IJAZAH, $this->idEntri), 'id_attachment')));
        $this->assertSame([], $this->service->daftar($nipLain, self::KODE_IJAZAH, $this->idEntri));
        $this->assertSame([], $this->service->daftar($this->nip, 39, $this->idEntri));

        foreach ([fn () => $this->service->ambil($nipLain, $id), fn () => $this->service->hapus($nipLain, $id)] as $aksi) {
            try {
                $aksi();
                $this->fail('lampiran NIP lain seharusnya 404');
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $ambil = $this->service->ambil($this->nip, $id);
        $this->assertStringStartsWith('%PDF-1.4', $ambil['isi']);
        $this->assertArrayNotHasKey('path', $ambil['lampiran']);

        $this->service->hapus($this->nip, $id);

        $this->assertSame(0, $this->db->table('document_attachment')->where('id_attachment', $id)->countAllResults());
        $this->assertSame([], $this->berkasDiAkarUji(), 'hapus keras: berkas ikut terhapus');

        $audit = $this->db->table('audit_logs')->where('entity', 'document_attachment')->where('entity_id', (string) $id)->orderBy('id_log')->get()->getResultArray();
        $this->assertSame(['create', 'delete'], array_column($audit, 'event'));
        $this->assertNotNull($audit[1]['before_json']);
        $this->assertNull($audit[1]['after_json']);
    }

    public function testRollbackSimpanMengompensasiBerkas(): void
    {
        $this->db->transBegin();
        $this->service->simpan($this->nip, self::KODE_IJAZAH, $this->idEntri, $this->berkasPdf(), $this->aturan);

        $this->assertCount(1, $this->berkasDiAkarUji(), 'berkas ditulis di dalam transaksi');
        $this->assertFalse($this->service->selesaikan(), 'rekonsiliasi menunggu transaksi selesai');

        $this->db->transRollback();
        $this->assertTrue($this->service->selesaikan());

        $this->assertSame(0, $this->db->table('document_attachment')->where('NIP', $this->nip)->countAllResults());
        $this->assertSame([], $this->berkasDiAkarUji(), 'rollback: berkas unggahan dihapus');
    }

    public function testRollbackHapusMempertahankanBerkas(): void
    {
        $row = $this->service->simpan($this->nip, self::KODE_IJAZAH, $this->idEntri, $this->berkasPdf(), $this->aturan);

        $this->db->transBegin();
        $this->service->hapus($this->nip, (int) $row['id_attachment']);
        $this->assertCount(1, $this->berkasDiAkarUji(), 'berkas belum dihapus selama transaksi terbuka');
        $this->db->transRollback();
        $this->service->selesaikan();

        $this->assertSame(1, $this->db->table('document_attachment')->where('id_attachment', $row['id_attachment'])->countAllResults());
        $this->assertCount(1, $this->berkasDiAkarUji(), 'rollback: berkas lampiran tetap ada');
        $this->assertStringStartsWith('%PDF', $this->service->ambil($this->nip, (int) $row['id_attachment'])['isi']);
    }

    public function testCommitHapusDalamTransaksiMenghapusBerkas(): void
    {
        $row = $this->service->simpan($this->nip, self::KODE_IJAZAH, $this->idEntri, $this->berkasPdf(), $this->aturan);

        $this->db->transBegin();
        $this->service->hapus($this->nip, (int) $row['id_attachment']);
        $this->db->transCommit();
        $this->service->selesaikan();

        $this->assertSame([], $this->berkasDiAkarUji());
    }

    public function testRekonsiliasiOportunistikPadaOperasiBerikutnya(): void
    {
        // Pemanggil yang lupa memanggil selesaikan(): operasi lampiran berikutnya membersihkan sisa rollback.
        $this->db->transBegin();
        $this->service->simpan($this->nip, self::KODE_IJAZAH, $this->idEntri, $this->berkasPdf('batal.pdf'), $this->aturan);
        $this->db->transRollback();

        $this->service->simpan($this->nip, self::KODE_IJAZAH, $this->idEntri, $this->berkasPdf('jadi.pdf'), $this->aturan);

        $baris = $this->service->daftar($this->nip, self::KODE_IJAZAH, $this->idEntri);
        $this->assertSame(['jadi.pdf'], array_column($baris, 'display_name'));
        $this->assertCount(1, $this->berkasDiAkarUji());
    }

    /**
     * @param callable(): mixed $aksi
     */
    private function assertGagalValidasi(callable $aksi): void
    {
        try {
            $aksi();
            $this->fail('seharusnya 422');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertArrayHasKey('berkas', $e->getErrors() ?? []);
        }

        $this->assertSame(0, $this->db->table('document_attachment')->where('NIP', $this->nip)->countAllResults());
        $this->assertSame([], $this->berkasDiAkarUji(), 'berkas yang ditolak tidak ditulis');
    }
}
