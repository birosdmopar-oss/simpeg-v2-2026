<?php

declare(strict_types=1);

namespace Tests\Support\HaloSimpeg;

use App\Libraries\Kepegawaian\Lampiran\LocalStorageAdapter;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use CodeIgniter\Throttle\Throttler;
use Config\Services;

/**
 * Data SINTETIS helpdesk Halo Simpeg untuk test MAKE-070 (master) dan paket tiket berikutnya, plus verifikasi bahwa contoh respons
 * `tests/_support/HaloSimpeg/*.json` (disalin FE ke `frontend/src/features/halo-simpeg/__tests__/fixtures/`, PRD Fase 8 v1.3
 * §6.1b) sama bentuknya dengan respons nyata. Tulis langsung lewat query builder (tanpa audit) agar test dapat memeriksa
 * `audit_logs` hanya dari aksi yang diuji. Tanpa NIP/nama asli (repo publik).
 */
trait HaloSimpegFixtureTrait
{
    protected function buatKategoriHs(string $nama, int $status = 1, string $dibuat = '2026-11-17 01:00:00'): int
    {
        $this->db->table('question_category')->insert(['question_category' => $nama, 'status' => $status, 'created_at' => $dibuat, 'updated_at' => $dibuat]);

        return (int) $this->db->insertID();
    }

    /**
     * @param list<int> $kategori
     */
    protected function buatPetugasHs(int $idUser, array $kategori, int $status = 1, string $email = 'petugas@contoh.test', string $dibuat = '2026-11-17 01:00:00'): int
    {
        $this->db->table('support_officer')->insert(['id_user' => $idUser, 'email' => $email, 'no_hp' => null, 'status' => $status, 'created_at' => $dibuat, 'updated_at' => $dibuat]);
        $id = (int) $this->db->insertID();

        foreach ($kategori as $k) {
            $this->db->table('officer_category')->insert(['id_support_officer' => $id, 'id_question_category' => $k, 'created_at' => $dibuat]);
        }

        return $id;
    }

    protected function buatKontakHs(string $nama, string $kontak, int $status = 1, ?string $email = null, string $dibuat = '2026-11-17 01:00:00'): int
    {
        $this->db->table('help_desk_contact')->insert(['name' => $nama, 'contact' => $kontak, 'email' => $email, 'status' => $status, 'created_at' => $dibuat, 'updated_at' => $dibuat]);

        return (int) $this->db->insertID();
    }

    /** Tiket sintetis (status 0 Open / 1 Closed) di kategori `$kategori`. */
    protected function buatTiketHs(?int $kategori, int $pengirim, int $status = 0): int
    {
        $this->db->table('disiplin_ticket')->insert([
            'id_question_category' => $kategori,
            'subject'              => 'Pertanyaan uji',
            'sender'               => $pengirim,
            'sender_name'          => 'Pengirim Uji',
            'nip'                  => '-',
            'status'               => $status,
            'allow_reply'          => $status === 0 ? 1 : 0,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * Pesan sintetis pada tiket `$tiket` (waktu UTC eksplisit agar urutan deterministik).
     */
    protected function buatPesanHs(int $tiket, int $pengirim, string $isi = 'Pesan uji', string $waktu = '2026-11-17 01:00:00', ?string $lampiran = null, string $nama = 'Pengirim Uji'): int
    {
        $this->db->table('disiplin_ticket_msg')->insert([
            'id_inbox' => $tiket, 'msg_sender' => $pengirim, 'msg_sender_name' => $nama, 'msg_receiver' => 0, 'msg' => $isi, 'attachments' => $lampiran, 'created_at' => $waktu,
        ]);

        return (int) $this->db->insertID();
    }

    /** Throttler MockCache (tidak berbagi kuota antar test). */
    protected function pasangThrottlerHs(): Throttler
    {
        $t = new Throttler(new MockCache());
        $t->setTestTime(1_790_000_000);
        Services::injectMock('throttler', $t);

        return $t;
    }

    /** Storage lampiran di folder sementara milik test; dikembalikan path akarnya. */
    protected function pasangStorageHs(): string
    {
        $akar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hs-uji-' . bin2hex(random_bytes(4));
        mkdir($akar, 0700, true);
        Services::injectMock('storageAdapter', new LocalStorageAdapter($akar));

        return $akar;
    }

    protected static function hapusFolderHs(string $akar): void
    {
        if ($akar === '' || ! is_dir($akar)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($akar, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }

        @rmdir($akar);
    }

    /**
     * Pasang `$_FILES['arsip']` untuk request berikutnya; kembalikan path sementara (hapus sesudah request).
     */
    protected function unggahHs(string $nama, string $isi): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'hsu');
        file_put_contents($path, $isi);
        service('superglobals')->setFilesArray(['arsip' => ['name' => $nama, 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($isi)]]);

        return $path;
    }

    protected function lepasUnggahHs(string $path): void
    {
        service('superglobals')->setFilesArray([]);
        @unlink($path);
    }

    /** Isi PDF minimal yang lolos deteksi MIME (`application/pdf`). */
    protected static function pdfHs(): string
    {
        return '%PDF-1.4
1 0 obj << /Type /Catalog >> endobj
trailer << /Root 1 0 R >>
%%EOF
';
    }

    /**
     * Tegaskan bentuk (kunci + tipe JSON, rekursif) respons = fixture `$fixture`.json. Daftar dibandingkan lewat elemen pertama;
     * daftar kosong = `[]`; `null` di satu sisi = kolom nullable skalar, sama dengan tipe skalar sisi lain (pola KontrakFixtureTest
     * chatbot). `$status` → status HTTP ikut ditegaskan.
     */
    protected function cocokFixtureHs(string $fixture, TestResponse|string $json, ?int $status = null): void
    {
        if ($json instanceof TestResponse) {
            if ($status !== null) {
                $json->assertStatus($status);
            }

            $json = (string) $json->getJSON();
        }

        $harap  = json_decode((string) file_get_contents(SUPPORTPATH . "HaloSimpeg/{$fixture}.json"), true, 512, JSON_THROW_ON_ERROR);
        $aktual = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(self::samakanNullHs(self::bentukHs($harap), self::bentukHs($aktual)), self::samakanNullHs(self::bentukHs($aktual), self::bentukHs($harap)), $fixture);
    }

    private static function samakanNullHs(mixed $a, mixed $b): mixed
    {
        if ($a === 'null' && in_array($b, ['number', 'string', 'bool'], true)) {
            return $b;
        }

        if (is_array($a) && is_array($b)) {
            foreach ($a as $k => $v) {
                if (array_key_exists($k, $b)) {
                    $a[$k] = self::samakanNullHs($v, $b[$k]);
                }
            }
        }

        return $a;
    }

    private static function bentukHs(mixed $v): mixed
    {
        if (is_array($v) && array_is_list($v)) {
            return $v === [] ? '[]' : ['[]' => self::bentukHs($v[0])];
        }

        if (is_array($v)) {
            $out = [];
            ksort($v);

            foreach ($v as $k => $x) {
                $out[$k] = self::bentukHs($x);
            }

            return $out;
        }

        return match (true) {
            is_int($v), is_float($v) => 'number',
            is_bool($v)              => 'bool',
            is_string($v)            => 'string',
            default                  => 'null',
        };
    }
}
