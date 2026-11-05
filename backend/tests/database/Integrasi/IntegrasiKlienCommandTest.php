<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Commands\IntegrasiKlien;
use App\Libraries\Integrasi\Klien\PembangkitKredensial;
use App\Libraries\Integrasi\Klien\PengesahKlien;
use App\Libraries\Integrasi\Klien\TokenSimapi;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-058 (A3) — `php spark integrasi:klien <aksi>`: rahasia ditampilkan sekali dan hanya di keluaran (tidak di DB/log/daftar),
 * validasi masukan (EXIT_USER_INPUT), pencabutan token, daftar tanpa rahasia, dan `bersihkan-token`.
 *
 * @internal
 */
final class IntegrasiKlienCommandTest extends DatabaseTestCase
{
    use StreamFilterTrait;

    /**
     * @param array<int|string, string|null> $params
     *
     * @return array{0: int, 1: string}
     */
    private function jalankan(array $params): array
    {
        $this->resetStreamFilterBuffer();
        $kode = (new IntegrasiKlien(Services::logger(), Services::commands()))->run($params);

        return [$kode, $this->getStreamFilterBuffer()];
    }

    /**
     * @return array<int|string, string|null>
     */
    private function paramBuat(string $username = 'simanta', string $jenis = '2', string $hak = 'simapi', ?string $ip = null): array
    {
        return ['buat', 'nama' => 'Sistem Uji', 'username' => $username, 'jenis' => $jenis, 'hak' => $hak] + ($ip === null ? [] : ['ip' => $ip]);
    }

    private function rahasiaDari(string $keluaran): string
    {
        $bersih = (string) preg_replace('/\e\[[0-9;]*m/', '', $keluaran);
        $this->assertSame(1, preg_match('/ditampilkan SEKALI[^\n]*\r?\n([A-Za-z0-9_-]{40})\r?\n/', $bersih, $m), 'rahasia tampil sekali');

        return $m[1];
    }

    public function testBuatMenampilkanRahasiaSekaliDanBisaDipakai(): void
    {
        [$kode, $keluaran] = $this->jalankan($this->paramBuat('simanta', '2', 'simapi', '203.0.113.0/24'));

        $this->assertSame(EXIT_SUCCESS, $kode);
        $rahasia = $this->rahasiaDari($keluaran);
        $this->assertSame('diterima', (new PengesahKlien($this->db))->periksa('simanta', $rahasia, '203.0.113.5', 'simapi')['hasil']);

        [, $daftar] = $this->jalankan(['daftar']);
        $this->assertStringContainsString('simanta', $daftar);
        $this->assertStringNotContainsString($rahasia, $daftar, 'daftar tidak menampilkan rahasia');
        $this->assertStringNotContainsString($rahasia, json_encode($this->db->table('klien_layanan')->get()->getResultArray(), JSON_THROW_ON_ERROR));
    }

    public function testMasukanBuatTidakSahMenghasilkanExitUserInput(): void
    {
        $this->jalankan($this->paramBuat());

        foreach ([
            $this->paramBuat('simanta'),                              // username ganda
            $this->paramBuat('klien-baru', '9'),                      // jenis di luar 1-3
            $this->paramBuat('klien-baru', 'x'),                      // jenis bukan angka
            $this->paramBuat('klien-baru', '2', 'stat'),              // jenis 2 tanpa simapi
            $this->paramBuat('klien-baru', '3', 'simapi'),            // jenis 3 dengan simapi
            $this->paramBuat('klien-baru', '3', 'admin'),             // hak tak dikenal
            $this->paramBuat('klien-baru', '3', 'stat', ''),          // ip kosong ditolak
            $this->paramBuat('klien-baru', '3', 'stat', '10.0.0.0/99'), // ip tidak sah
            $this->paramBuat('A', '3', 'stat'),                       // username tidak sah
            ['buat', 'nama' => 'X', 'username' => 'klien-baru'],      // jenis/hak hilang
            ['buat', 'lebih', 'nama' => 'X'],                         // argumen berlebih
            ['hapus-semua'],                                          // aksi tak dikenal
            [],                                                       // aksi hilang
            ['rotasi'],                                               // username hilang
            ['rotasi', 'tidak-ada'],                                  // klien tak ada
            ['daftar', 'tambahan'],                                   // argumen berlebih
            ['buat', 'tanggal' => '2026-01-01'],                      // opsi tak dikenal
        ] as $i => $params) {
            [$kode, $keluaran] = $this->jalankan($params);
            $this->assertSame(EXIT_USER_INPUT, $kode, "kasus {$i}: " . $keluaran);
        }

        $this->assertSame(1, $this->db->table('klien_layanan')->countAllResults(), 'tidak ada klien sisa');
    }

    public function testRotasiNonaktifAktifkanHapusMencabutToken(): void
    {
        [, $keluaran] = $this->jalankan($this->paramBuat());
        $lama         = $this->rahasiaDari($keluaran);
        $id           = (int) $this->db->table('klien_layanan')->get()->getRowArray()['id_klien'];
        $token        = new TokenSimapi($this->db);
        $t1           = $this->terbit($id)['token'];

        [$kode, $keluaran] = $this->jalankan(['rotasi', 'simanta']);
        $this->assertSame(EXIT_SUCCESS, $kode);
        $baru = $this->rahasiaDari($keluaran);
        $this->assertNotSame($lama, $baru);
        $this->assertStringContainsString('token aktif dicabut: 1', $keluaran);
        $this->assertNull($token->periksa('Bearer ' . $t1));

        $t2                = $this->terbit($id)['token'];
        [$kode, $keluaran] = $this->jalankan(['nonaktif', 'simanta']);
        $this->assertSame(EXIT_SUCCESS, $kode);
        $this->assertStringContainsString('dinonaktifkan', $keluaran);
        $this->assertNull($token->periksa('Bearer ' . $t2));

        $this->assertSame(EXIT_SUCCESS, $this->jalankan(['aktifkan', 'simanta'])[0]);
        $this->assertSame('diterima', (new PengesahKlien($this->db))->periksa('simanta', $baru, '203.0.113.5', 'simapi')['hasil']);

        $this->assertSame(EXIT_SUCCESS, $this->jalankan(['hapus', 'simanta'])[0]);
        $this->assertSame(EXIT_USER_INPUT, $this->jalankan(['aktifkan', 'simanta'])[0], 'klien dihapus tidak bisa diaktifkan');
    }

    public function testBersihkanTokenDryRunLaluNyata(): void
    {
        $this->jalankan($this->paramBuat());
        $id = (int) $this->db->table('klien_layanan')->get()->getRowArray()['id_klien'];
        $this->db->table('simapi_token')->insert(['token_hash' => str_repeat('c', 64), 'id_klien' => $id, 'status' => 1, 'expired_at' => gmdate('Y-m-d H:i:s', time() - 30 * 86400), 'created_at' => gmdate('Y-m-d H:i:s', time() - 31 * 86400)]);

        [$kode, $keluaran] = $this->jalankan(['bersihkan-token', 'dry-run' => null]);
        $this->assertSame(EXIT_SUCCESS, $kode);
        $this->assertStringContainsString('[dry-run] Akan dihapus: 1', $keluaran);
        $this->assertSame(1, $this->db->table('simapi_token')->countAllResults());

        [, $keluaran] = $this->jalankan(['bersihkan-token', 'hari' => '7']);
        $this->assertStringContainsString('Dihapus: 1', $keluaran);
        $this->assertSame(0, $this->db->table('simapi_token')->countAllResults());

        $this->assertSame(EXIT_USER_INPUT, $this->jalankan(['bersihkan-token', 'hari' => '0'])[0]);
        $this->assertSame(EXIT_USER_INPUT, $this->jalankan(['bersihkan-token', 'hari' => 'x'])[0]);
    }

    /**
     * Terbitkan token untuk klien dengan sidik rahasia saat ini (seperti filter Basic lalu handshake).
     *
     * @return array{token: string, expired_at: string}
     */
    private function terbit(int $id): array
    {
        $baris = $this->db->table('klien_layanan')->select('rahasia_hash')->where('id_klien', $id)->get()->getRowArray();

        return (new TokenSimapi($this->db))->terbitkan($id, PembangkitKredensial::sidik((string) ($baris['rahasia_hash'] ?? '')));
    }
}
