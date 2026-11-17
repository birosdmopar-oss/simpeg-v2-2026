<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg;

use App\Constants\Role;
use App\Libraries\HaloSimpeg\RingkasanTiket;
use App\Libraries\HaloSimpeg\TiketService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HaloSimpeg\HaloSimpegFixtureTrait;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;

/**
 * Syarat DB Validator DBV-028 Q6 (dibekukan di README tiket): kueri ringkasan sisi petugas menulis `status IN (0, 1)` eksplisit
 * sehingga memakai indeks `idx_dticket_kategori_status_baca` (kategori, status, is_read_off) sampai kolom ketiga; pesan terakhir
 * daftar memakai `idx_dticketmsg_inbox_waktu`. Q8 (c): ambang ukur daftar petugas (100 ms / Q-H19 5.000 tiket) dicatat di README
 * dan dinilai pada data gladi, bukan di CI. Data sintetis + ANALYZE TABLE → `db-isolasi-penuh`.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class TiketEksplainTest extends DatabaseTestCase
{
    use HaloSimpegFixtureTrait;
    use PegawaiFixtureTrait;

    protected $seed = AuthSeeder::class;

    public function testRingkasanPetugasDanDaftarPetugasMemakaiIndeksDbv028(): void
    {
        $admin = (int) $this->db->table('pengguna')->where('username', AuthSeeder::nipForRole(Role::SUPER_ADMIN))->get()->getRow('id_pengguna');
        $kat   = [];

        for ($i = 1; $i <= 30; $i++) {
            $kat[] = $this->buatKategoriHs("Kategori {$i}");
        }

        $this->buatPetugasHs($admin, array_slice($kat, 0, 3));
        $t   = $this->db->prefixTable('disiplin_ticket');
        $m   = $this->db->prefixTable('disiplin_ticket_msg');
        $min = min($kat);
        // Pembangkit 6.000 baris tanpa CTE rekursif (portabel MySQL 8 / MariaDB 10.4).
        $digit = 'SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9';
        $this->db->query("INSERT INTO {$t} (id_question_category, subject, sender, sender_name, nip, status, is_read, is_read_off)
            SELECT {$min} + (n.i % 30), CONCAT('S', n.i), 900000 + (n.i % 400), 'Uji', '-', IF(n.i % 10 = 0, 0, 1), IF(n.i % 7 = 0, 2, 1), IF(n.i % 9 = 0, 2, 1)
            FROM (SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 1 AS i FROM ({$digit}) a, ({$digit}) b, ({$digit}) c, ({$digit}) e) n WHERE n.i <= 6000");
        $this->db->query("INSERT INTO {$m} (id_inbox, msg_sender, msg_sender_name, msg, created_at)
            SELECT id_inbox, sender, 'Uji', 'isi', '2026-11-17 01:00:00' FROM {$t}");
        $this->db->query("INSERT INTO {$m} (id_inbox, msg_sender, msg_sender_name, msg, created_at)
            SELECT id_inbox, sender, 'Uji', 'isi 2', '2026-11-17 02:00:00' FROM {$t} WHERE id_inbox % 2 = 0");
        $this->db->query("ANALYZE TABLE {$t}, {$m}");

        // Ringkasan sisi petugas (bentuk B + status IN (0, 1)).
        $sql   = (new RingkasanTiket($this->db))->kueriBelumDibacaPetugas($admin, array_slice($kat, 0, 3))->select('COUNT(*) AS n', false)->getCompiledSelect();
        $rinci = $this->eksplain($sql)[0];
        $this->assertSame('idx_dticket_kategori_status_baca', $rinci['key'], $sql);
        // key_len = 5 (id_question_category INT NULL) + 4 (status INT) + 1 (is_read_off TINYINT) = 10 (DBV-028 R2).
        $this->assertSame(10, (int) $rinci['key_len'], 'indeks terpakai sampai is_read_off');

        // Daftar petugas: pesan terakhir lewat idx_dticketmsg_inbox_waktu (subkueri bergantung, tanpa N+1 tambahan).
        (new TiketService($this->db))->daftar($this->authUntukAkun($admin), ['peran' => 'petugas', 'status' => 'open']);
        $baris = $this->eksplain((string) $this->db->getLastQuery());
        $kunci = array_column($baris, 'key', 'table');
        $this->assertContains('idx_dticketmsg_inbox_waktu', $kunci, json_encode($baris, JSON_THROW_ON_ERROR));
        $this->assertSame('idx_dticket_kategori_status_baca', $kunci['t'] ?? null, json_encode($baris, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eksplain(string $sql): array
    {
        return $this->db->query('EXPLAIN ' . $sql)->getResultArray();
    }
}
