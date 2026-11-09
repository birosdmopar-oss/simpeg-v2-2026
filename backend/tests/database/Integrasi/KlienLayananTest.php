<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Libraries\Integrasi\Klien\KlienTidakAktif;
use App\Libraries\Integrasi\Klien\PembangkitKredensial;
use App\Libraries\Integrasi\Klien\PengesahKlien;
use App\Libraries\Integrasi\Klien\PenyimpanKlien;
use App\Libraries\Integrasi\Klien\TokenSimapi;
use App\Models\AuditLogModel;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-058 (A3) — klien layanan: penyimpan (`integrasi:klien`), pengesah Basic, dan token SIMAPI. AC-F.1 (rahasia tidak
 * pernah tersimpan/terlog mentah), AC-F.2 (status 2/10 ditolak), AC-F.3 (IP di luar allowlist), AC-F.5 (token kedaluwarsa/dicabut),
 * serta syarat DBV-026 §13.2 A1/A2/A3/A4.
 *
 * @internal
 */
final class KlienLayananTest extends DatabaseTestCase
{
    private function penyimpan(?callable $pabrikAudit = null): PenyimpanKlien
    {
        return new PenyimpanKlien($this->db, $pabrikAudit === null ? null : \Closure::fromCallable($pabrikAudit));
    }

    /**
     * @return array{id: int, rahasia: string}
     */
    private function klien(string $username = 'simanta', int $jenis = 2, array $hak = ['simapi'], ?string $ip = null): array
    {
        return $this->penyimpan()->buat('Sistem Uji', $username, $jenis, $hak, $ip);
    }

    private function token(): TokenSimapi
    {
        return new TokenSimapi($this->db);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Penyimpan

    public function testBuatMenyimpanHashDanMenampilkanRahasiaSekali(): void
    {
        $r = $this->klien();

        $baris = $this->db->table('klien_layanan')->where('id_klien', $r['id'])->get()->getRowArray();
        $this->assertNotSame($r['rahasia'], $baris['rahasia_hash']);
        $this->assertTrue(password_verify($r['rahasia'], (string) $baris['rahasia_hash']));
        $this->assertSame(40, strlen($r['rahasia']));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{40}$/', $r['rahasia']);
        $this->assertSame(['simapi', 1, null], [$baris['hak'], (int) $baris['status'], $baris['ip_allowlist']]);
        $this->assertNotNull($baris['rahasia_diubah_at']);
    }

    public function testRahasiaMentahTidakAdaDiDbAuditAtauLog(): void
    {
        $r = $this->klien();
        $this->penyimpan()->rotasi('simanta');

        foreach (['klien_layanan', 'audit_logs', 'simapi_token'] as $tabel) {
            $this->assertStringNotContainsString($r['rahasia'], json_encode($this->db->table($tabel)->get()->getResultArray(), JSON_THROW_ON_ERROR), "{$tabel}: rahasia mentah (AC-F.1)");
        }

        $audit = $this->db->table('audit_logs')->where('entity', PenyimpanKlien::AUDIT_ENTITAS)->get()->getResultArray();
        $this->assertCount(2, $audit, 'buat + rotasi');
        $this->assertStringNotContainsString('rahasia_hash', json_encode($audit, JSON_THROW_ON_ERROR));
    }

    public function testUsernameGandaDanMasukanTidakSahDitolak(): void
    {
        $this->klien();

        foreach ([
            'username ganda'        => fn () => $this->klien(),
            'username salah'        => fn () => $this->penyimpan()->buat('X', 'Ab', 2, ['simapi'], null),
            'username huruf besar'  => fn () => $this->penyimpan()->buat('X', 'Klien.Besar', 2, ['simapi'], null),
            'nama kosong'           => fn () => $this->penyimpan()->buat('  ', 'klien-baru', 2, ['simapi'], null),
            'jenis 2 tanpa simapi'  => fn () => $this->penyimpan()->buat('X', 'klien-baru', 2, ['stat'], null),
            'jenis 3 dengan simapi' => fn () => $this->penyimpan()->buat('X', 'klien-baru', 3, ['simapi'], null),
            'ip kosong'             => fn () => $this->penyimpan()->buat('X', 'klien-baru', 3, ['stat'], ''),
            'ip tidak sah'          => fn () => $this->penyimpan()->buat('X', 'klien-baru', 3, ['stat'], '10.0.0.0/99'),
        ] as $kasus => $aksi) {
            try {
                $aksi();
                $this->fail("{$kasus}: harus ditolak");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, $this->db->table('klien_layanan')->countAllResults(), 'tidak ada baris sisa');
    }

    public function testAllowlistDinormalkanSaatSimpan(): void
    {
        $r = $this->klien('mudig', 3, ['stat', 'dm'], ' 203.0.113.1 ,203.0.113.1, 10.0.0.0/8 ');

        $this->assertSame('203.0.113.1,10.0.0.0/8', $this->db->table('klien_layanan')->select('ip_allowlist')->where('id_klien', $r['id'])->get()->getRowArray()['ip_allowlist']);
    }

    public function testNonaktifDanHapusMencabutSemuaTokenAktifDalamTransaksiSama(): void
    {
        $a  = $this->klien('simanta');
        $b  = $this->klien('pusdatin', 1);
        $t1 = $this->terbit($a['id']);
        $this->terbit($a['id']);
        $tLain = $this->terbit($b['id']);

        $hasil = $this->penyimpan()->ubahStatus('simanta', PenyimpanKlien::STATUS_NONAKTIF);

        $this->assertSame(2, $hasil['token_dicabut']);
        $this->assertNull($this->token()->periksa('Bearer ' . $t1['token']), 'AC-F.2: token klien nonaktif ditolak');
        $this->assertNotNull($this->token()->periksa('Bearer ' . $tLain['token']), 'token klien lain tidak terdampak');
        $this->assertSame(0, $this->db->table('simapi_token')->where('id_klien', $a['id'])->where('status', 1)->countAllResults(), 'audit B3 = 0');

        $this->penyimpan()->ubahStatus('simanta', PenyimpanKlien::STATUS_AKTIF);
        $this->assertNull($this->token()->periksa('Bearer ' . $t1['token']), 'diaktifkan kembali tidak menghidupkan token yang sudah dicabut');

        $this->terbit($a['id']);
        $hapus = $this->penyimpan()->ubahStatus('simanta', PenyimpanKlien::STATUS_DIHAPUS);
        $this->assertSame(1, $hapus['token_dicabut']);
        $baris = $this->db->table('klien_layanan')->where('id_klien', $a['id'])->get()->getRowArray();
        $this->assertSame(10, (int) $baris['status']);
        $this->assertNotNull($baris['deleted_at']);
    }

    public function testKlienDihapusTidakBisaDiaktifkanAtauDirotasi(): void
    {
        $this->klien();
        $this->penyimpan()->ubahStatus('simanta', PenyimpanKlien::STATUS_DIHAPUS);

        foreach ([fn () => $this->penyimpan()->ubahStatus('simanta', PenyimpanKlien::STATUS_AKTIF), fn () => $this->penyimpan()->rotasi('simanta')] as $aksi) {
            try {
                $aksi();
                $this->fail('klien dihapus harus ditolak');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testKlienTidakDitemukan(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->penyimpan()->rotasi('tidak-ada');
    }

    public function testRotasiMenggantiRahasiaDanMencabutToken(): void
    {
        $lama = $this->klien();
        $tok  = $this->terbit($lama['id']);

        $baru = $this->penyimpan()->rotasi('simanta');

        $this->assertNotSame($lama['rahasia'], $baru['rahasia']);
        $this->assertSame(1, $baru['token_dicabut']);
        $this->assertNull($this->token()->periksa('Bearer ' . $tok['token']));

        $pengesah = new PengesahKlien($this->db);
        $this->assertSame(PengesahKlien::TIDAK_SAH, $pengesah->periksa('simanta', $lama['rahasia'], '203.0.113.7', 'simapi')['hasil']);
        $this->assertSame(PengesahKlien::DITERIMA, $pengesah->periksa('simanta', $baru['rahasia'], '203.0.113.7', 'simapi')['hasil']);
    }

    public function testAuditGagalMembatalkanPerubahan(): void
    {
        $this->klien();
        $tok   = $this->terbit((int) $this->db->table('klien_layanan')->get()->getRowArray()['id_klien']);
        $gagal = fn (BaseConnection $koneksi): AuditLogModel => new class ($koneksi) extends AuditLogModel {
            public function record(string $entity, string|int $entityId, string $event, ?array $before, ?array $after, ?int $actorId = null, ?string $actorNip = null): int
            {
                throw new RuntimeException('audit gagal (uji)');
            }
        };

        try {
            $this->penyimpan($gagal)->ubahStatus('simanta', PenyimpanKlien::STATUS_NONAKTIF);
            $this->fail('harus gagal');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, (int) $this->db->table('klien_layanan')->select('status')->get()->getRowArray()['status'], 'status ikut dibatalkan');
        $this->assertNotNull($this->token()->periksa('Bearer ' . $tok['token']), 'token tidak jadi dicabut');
    }

    public function testDaftarTanpaRahasiaDenganJumlahTokenAktif(): void
    {
        $a = $this->klien();
        $this->terbit($a['id']);
        $this->db->table('simapi_token')->insert(['token_hash' => str_repeat('a', 64), 'id_klien' => $a['id'], 'status' => 1, 'expired_at' => '2000-01-01 00:00:00', 'created_at' => '2000-01-01 00:00:00']);

        $daftar = $this->penyimpan()->daftar();

        $this->assertCount(1, $daftar);
        $this->assertSame(['simanta', 'simapi', 1], [$daftar[0]['username'], $daftar[0]['hak'], $daftar[0]['token_aktif']], 'token kedaluwarsa tidak dihitung aktif');
        $this->assertStringNotContainsString($a['rahasia'], json_encode($daftar, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('rahasia_hash', $daftar[0]);
    }

    public function testBersihkanTokenKedaluwarsaDenganRetensiDanDryRun(): void
    {
        $a = $this->klien();
        $this->terbit($a['id']);
        $this->db->table('simapi_token')->insertBatch([
            ['token_hash' => str_repeat('a', 64), 'id_klien' => $a['id'], 'status' => 1, 'expired_at' => gmdate('Y-m-d H:i:s', time() - 10 * 86400), 'created_at' => gmdate('Y-m-d H:i:s', time() - 11 * 86400)],
            ['token_hash' => str_repeat('b', 64), 'id_klien' => $a['id'], 'status' => 2, 'expired_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400), 'created_at' => gmdate('Y-m-d H:i:s', time() - 4 * 86400)],
        ]);

        $kering = $this->penyimpan()->bersihkanToken(7, true);
        $this->assertSame(1, $kering['jumlah']);
        $this->assertSame(3, $this->db->table('simapi_token')->countAllResults(), 'dry-run tidak menghapus');

        $nyata = $this->penyimpan()->bersihkanToken(7, false);
        $this->assertSame(1, $nyata['jumlah']);
        $this->assertSame(2, $this->db->table('simapi_token')->countAllResults(), 'hanya yang lebih tua dari retensi');
        $this->assertSame(0, $this->penyimpan()->bersihkanToken(7, false)['jumlah'], 'idempoten');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Pengesah (AC-F.1..F.3)

    public function testPengesahDiterimaDanAlasanGagal(): void
    {
        $klien    = $this->klien('simanta', 2, ['simapi'], '203.0.113.0/24');
        $pengesah = new PengesahKlien($this->db);

        $diterima = $pengesah->periksa('simanta', $klien['rahasia'], '203.0.113.9', 'simapi');
        $this->assertSame(['diterima', $klien['id'], 2], [$diterima['hasil'], $diterima['id_klien'], $diterima['jenis']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $diterima['sidik']);
        $this->assertNull($pengesah->periksa('simanta', 'rahasia-salah', '203.0.113.9', 'simapi')['sidik'], 'sidik hanya untuk yang diterima');
        $this->assertSame('tidak_sah', $pengesah->periksa('simanta', 'rahasia-salah', '203.0.113.9', 'simapi')['hasil']);
        $this->assertSame('tidak_sah', $pengesah->periksa('tidak-ada', $klien['rahasia'], '203.0.113.9', 'simapi')['hasil']);
        $this->assertSame('tidak_sah', $pengesah->periksa('', '', '203.0.113.9', 'simapi')['hasil']);
        $this->assertSame('ip_ditolak', $pengesah->periksa('simanta', $klien['rahasia'], '198.51.100.9', 'simapi')['hasil'], 'AC-F.3');
        $this->assertSame('hak_kurang', $pengesah->periksa('simanta', $klien['rahasia'], '203.0.113.9', 'stat')['hasil']);

        $this->penyimpan()->ubahStatus('simanta', PenyimpanKlien::STATUS_NONAKTIF);
        $this->assertSame('tidak_aktif', $pengesah->periksa('simanta', $klien['rahasia'], '203.0.113.9', 'simapi')['hasil'], 'AC-F.2');
    }

    public function testPengesahTidakPernahMenganggapAllowlistKosongSebagaiSemuaIp(): void
    {
        $klien = $this->klien();
        $this->db->table('klien_layanan')->where('id_klien', $klien['id'])->update(['ip_allowlist' => '']);

        $this->assertSame('ip_ditolak', (new PengesahKlien($this->db))->periksa('simanta', $klien['rahasia'], '203.0.113.9', 'simapi')['hasil']);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Token SIMAPI (AC-F.5, DBV-026 §13.2 A4)

    public function testTokenDiterbitkanSebagaiHashDenganMasaAktifSatuJam(): void
    {
        $klien = $this->klien();
        $t     = $this->terbit($klien['id']);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{30}$/', $t['token']);
        $baris = $this->db->table('simapi_token')->get()->getRowArray();
        $this->assertSame(hash('sha256', $t['token']), $baris['token_hash']);
        $this->assertNotSame($t['token'], $baris['token_hash']);
        $this->assertEqualsWithDelta(time() + 3600, strtotime($baris['expired_at'] . ' UTC'), 5);
        $this->assertSame(1, (int) $baris['status']);
        $this->assertStringNotContainsString($t['token'], json_encode($this->db->table('simapi_token')->get()->getResultArray(), JSON_THROW_ON_ERROR));

        $this->terbit($klien['id']);
        $this->assertSame(2, $this->db->table('simapi_token')->countAllResults(), 'satu baris per handshake; token lama tetap sah');
        $this->assertNotNull($this->token()->periksa('Basic ' . $t['token']), 'skema bebas, elemen kedua = token');
    }

    public function testTokenKedaluwarsaDicabutDanBentukSalahDitolak(): void
    {
        $klien = $this->klien();
        $t     = $this->terbit($klien['id']);

        $this->assertSame(['id_klien' => $klien['id'], 'jenis' => 2, 'ip_allowlist' => null], $this->token()->periksa('Bearer ' . $t['token']));

        $hash = hash('sha256', $t['token']);
        $this->db->table('simapi_token')->where('token_hash', $hash)->update(['expired_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $this->assertNull($this->token()->periksa('Bearer ' . $t['token']), 'kedaluwarsa (AC-F.5)');

        $this->db->table('simapi_token')->where('token_hash', $hash)->update(['expired_at' => gmdate('Y-m-d H:i:s', time() + 600), 'status' => 2]);
        $this->assertNull($this->token()->periksa('Bearer ' . $t['token']), 'dicabut (AC-F.5)');

        $this->db->table('simapi_token')->where('token_hash', $hash)->update(['status' => 1]);
        foreach (['', 'Bearer', $t['token'], 'Bearer ' . strtolower($t['token']), 'Bearer ' . $t['token'] . 'x', 'Bearer ' . substr($t['token'], 1), 'Bearer ' . str_repeat('A', 600), 'Bearer ' . substr($t['token'], 0, 29) . '!'] as $header) {
            $this->assertNull($this->token()->periksa($header), 'header tidak sah: [' . substr($header, 0, 20) . ']');
        }

        $this->assertNotNull($this->token()->periksa("Bearer \t " . $t['token']), 'spasi ganda dimaklumi');
    }

    public function testTokenKlienTanpaHakSimapiDitolak(): void
    {
        $klien = $this->klien();
        $t     = $this->terbit($klien['id']);
        $this->db->table('klien_layanan')->where('id_klien', $klien['id'])->update(['hak' => 'stat']);

        $this->assertNull($this->token()->periksa('Bearer ' . $t['token']));
    }

    public function testPembangkitKredensial(): void
    {
        $this->assertNotSame(PembangkitKredensial::rahasia(40), PembangkitKredensial::rahasia(40));
        $this->assertSame(64, strlen(PembangkitKredensial::hashToken('x')));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', PembangkitKredensial::hashToken('X'));
        $this->assertSame(30, strlen(PembangkitKredensial::token(30)));

        $this->expectException(InvalidArgumentException::class);
        PembangkitKredensial::rahasia(8);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Review MAKE-058 (P-1, S-3, S-6)

    public function testHashTiruanKonstanSamaParameterDenganHashNyata(): void
    {
        $tiruan = password_get_info(PengesahKlien::hashTiruan());
        $nyata  = password_get_info(PembangkitKredensial::hashRahasia('x'));

        $this->assertSame($nyata['algo'], $tiruan['algo'], 'algoritma sama (P-1)');
        $this->assertSame($nyata['options'], $tiruan['options'], 'parameter biaya sama dengan bawaan PHP; perbarui konstanta bila PHP mengubah bawaan');
        $this->assertFalse(password_needs_rehash(PengesahKlien::hashTiruan(), defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT));
        $this->assertFalse(password_verify('apa-saja', PengesahKlien::hashTiruan()));
    }

    public function testTerbitkanUntukKlienTidakAktifAtauTidakAdaDitolak(): void
    {
        $klien = $this->klien();
        $this->penyimpan()->ubahStatus('simanta', PenyimpanKlien::STATUS_NONAKTIF);

        foreach ([$klien['id'], 99999] as $id) {
            try {
                $this->terbit($id);
                $this->fail("klien {$id}: token tidak boleh diterbitkan");
            } catch (KlienTidakAktif) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->db->table('simapi_token')->countAllResults());
    }

    public function testJumlahTokenDicabutHanyaYangMasihBerlaku(): void
    {
        $klien = $this->klien();
        $this->terbit($klien['id']);
        $this->db->table('simapi_token')->insert(['token_hash' => str_repeat('d', 64), 'id_klien' => $klien['id'], 'status' => 1, 'expired_at' => '2000-01-01 00:00:00', 'created_at' => '2000-01-01 00:00:00']);

        $hasil = $this->penyimpan()->ubahStatus('simanta', PenyimpanKlien::STATUS_NONAKTIF);

        $this->assertSame(1, $hasil['token_dicabut'], 'konsisten dengan daftar: token kedaluwarsa tidak dihitung');
        $this->assertSame(0, $this->db->table('simapi_token')->where('status', 1)->countAllResults(), 'semua status 1 tetap dibersihkan');
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

    public function testHandshakeDenganRahasiaLamaSetelahRotasiTidakMenerbitkanToken(): void
    {
        $klien   = $this->klien();
        $hasil   = (new PengesahKlien($this->db))->periksa('simanta', $klien['rahasia'], '203.0.113.7', 'simapi');
        $sidikLm = (string) $hasil['sidik'];
        $this->assertSame(PengesahKlien::DITERIMA, $hasil['hasil']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $sidikLm);

        // Rotasi berlomba setelah verifikasi: status tetap 1, hanya rahasia berganti.
        $baru = $this->penyimpan()->rotasi('simanta');

        try {
            $this->token()->terbitkan($klien['id'], $sidikLm);
            $this->fail('token tidak boleh terbit untuk rahasia yang sudah dirotasi (S-3)');
        } catch (KlienTidakAktif) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->db->table('simapi_token')->countAllResults());

        $sidikBaru = (string) (new PengesahKlien($this->db))->periksa('simanta', $baru['rahasia'], '203.0.113.7', 'simapi')['sidik'];
        $this->assertNotSame($sidikLm, $sidikBaru, 'sidik berubah setiap rotasi');
        $this->assertNotEmpty($this->token()->terbitkan($klien['id'], $sidikBaru)['token']);
    }
}
