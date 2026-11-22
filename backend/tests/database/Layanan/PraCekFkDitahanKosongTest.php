<?php

declare(strict_types=1);

namespace Tests\Database\Layanan;

use App\Database\Migrations\AddFkNipLayananFase4;
use App\Database\Migrations\AddFkPegawaiDiPenggunaFaqRate;
use CodeIgniter\Database\Migration;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Throwable;

/**
 * CR-172 / DBV-034 (G5) — pra-cek dua migration FK NIP yang DITAHAN menghitung NIP `''`/spasi-saja sebagai masalah,
 * TERPISAH dari yatim, dengan jumlah per kolom. Sebelumnya `AddFkNipLayananFase4` melewatkan `''` (ALTER lalu gagal 1452
 * tanpa angka per kolom) dan `AddFkPegawaiDiPenggunaFaqRate` melaporkannya sebagai "NIP tidak ada di pegawai". Saran untuk
 * kolom NOT NULL tidak boleh "NULL-kan". Sesudah data dibereskan, up() memasang FK (tidak ada nilai yang lolos pra-cek
 * lalu ditolak ALTER), lalu down() melepasnya lagi. DDL → `db-isolasi-penuh`.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class PraCekFkDitahanKosongTest extends DatabaseTestCase
{
    use PegawaiFixtureTrait;

    private const USERNAME_KOSONG = 'cr172.kosong';

    private ?Migration $fkDitahan = null;

    protected function tearDown(): void
    {
        if ($this->fkDitahan !== null) {
            $this->fkDitahan->down();
            $this->fkDitahan = null;
        }

        $this->db->table('pengguna')->where('username', self::USERNAME_KOSONG)->delete();

        parent::tearDown();
    }

    public function testFkNipLayananMenolakKosongDanSpasiDenganJumlahPerKolom(): void
    {
        $migration       = $this->instance(AddFkNipLayananFase4::class);
        $this->fkDitahan = $migration;
        $this->assertSame([], $this->fkTerpasang(array_keys(AddFkNipLayananFase4::FOREIGN_KEYS)), 'prasyarat: FK belum terpasang');

        $pemohon = $this->buatPegawai();
        $this->db->table('riwayat_cuti')->insertBatch([
            $this->barisCuti($pemohon, ['nip_atasan_langsung' => '', 'nip_yang_menyetujui' => '   ']),
            $this->barisCuti($pemohon, ['nip_atasan_langsung' => '  ', 'nip_yang_menyetujui' => $pemohon]),
            // Spasi di DEPAN tidak sama dengan NIP mana pun (ALTER menolak) → yatim, bukan kosong.
            $this->barisCuti($pemohon, ['nip_atasan_langsung' => ' ' . $pemohon, 'nip_yang_menyetujui' => null]),
        ]);
        $this->db->table('petugas_layanan')->insert(['id_jenis_layanan' => 1, 'nip' => '', 'status' => 1]);
        $this->db->table('pa_layanan')->insert(['nip' => ' ', 'status' => 1]);

        $pesan = $this->upDitolak($migration);

        $this->assertStringContainsString('riwayat_cuti.nip_atasan_langsung: 2 pengajuan cuti dengan NIP atasan langsung dengan NIP kosong/spasi-saja (kolom NULL-able: NULL-kan)', $pesan);
        $this->assertStringContainsString('riwayat_cuti.nip_atasan_langsung: 1 pengajuan cuti dengan NIP atasan langsung dengan NIP yang tidak ada di pegawai', $pesan);
        $this->assertStringContainsString('riwayat_cuti.nip_yang_menyetujui: 1 pengajuan cuti dengan NIP pejabat yang berwenang dengan NIP kosong/spasi-saja (kolom NULL-able', $pesan);
        $this->assertStringNotContainsString('riwayat_cuti.nip_yang_menyetujui: 1 pengajuan cuti dengan NIP pejabat yang berwenang dengan NIP yang tidak ada', $pesan, 'NIP sah bukan yatim');
        $this->assertStringContainsString('petugas_layanan.nip: 1 petugas layanan (PIC) dengan NIP kosong/spasi-saja (kolom NOT NULL: perbaiki NIP atau buang barisnya, tidak bisa di-NULL-kan)', $pesan);
        $this->assertStringContainsString('pa_layanan.nip: 1 aturan petugas approval per NIP dengan NIP kosong/spasi-saja (kolom NULL-able', $pesan);
        $this->assertStringNotContainsString('disiplin_ticket.nip', $pesan, 'tabel tanpa masalah tidak disebut');
        $this->assertStringContainsString('DBV-022-audit-layanan.sql', $pesan);
        $this->assertSame([], $this->fkTerpasang(array_keys(AddFkNipLayananFase4::FOREIGN_KEYS)), 'tidak ada FK terpasang sebagian');

        // Dibereskan seperti saran pesan: kolom NULL-able → NULL, NOT NULL → NIP diperbaiki. Spasi di BELAKANG NIP sah
        // (PAD SPACE) tidak dihitung dan diterima ALTER — tidak ada positif palsu.
        $this->db->query("UPDATE {$this->db->prefixTable('riwayat_cuti')} SET `nip_atasan_langsung` = NULL WHERE TRIM(`nip_atasan_langsung`) = ''");
        $this->db->table('riwayat_cuti')->where('nip_atasan_langsung', ' ' . $pemohon)->update(['nip_atasan_langsung' => $pemohon . ' ']);
        $this->db->query("UPDATE {$this->db->prefixTable('riwayat_cuti')} SET `nip_yang_menyetujui` = NULL WHERE TRIM(`nip_yang_menyetujui`) = ''");
        $this->db->table('petugas_layanan')->where('nip', '')->update(['nip' => $pemohon]);
        $this->db->table('pa_layanan')->update(['nip' => null]);

        $migration->up();

        $this->assertCount(5, $this->fkTerpasang(array_keys(AddFkNipLayananFase4::FOREIGN_KEYS)));
    }

    public function testFkPenggunaFaqRateMemisahkanKosongDariYatim(): void
    {
        $migration       = $this->instance(AddFkPegawaiDiPenggunaFaqRate::class);
        $this->fkDitahan = $migration;
        $this->assertSame([], $this->fkTerpasang(array_keys(AddFkPegawaiDiPenggunaFaqRate::FOREIGN_KEYS)), 'prasyarat: FK belum terpasang');

        $this->db->table('pengguna')->insert(['nip' => '', 'username' => self::USERNAME_KOSONG, 'user_level' => 1]);

        $pesan = $this->upDitolak($migration);

        $this->assertStringContainsString('pengguna.nip: 1 akun pengguna (termasuk yang soft-deleted) dengan NIP kosong/spasi-saja (kolom NULL-able: NULL-kan)', $pesan);
        $this->assertStringNotContainsString('dengan NIP yang tidak ada di pegawai', $pesan, '`\'\'` bukan yatim');
        $this->assertSame([], $this->fkTerpasang(array_keys(AddFkPegawaiDiPenggunaFaqRate::FOREIGN_KEYS)));

        $this->db->table('pengguna')->where('username', self::USERNAME_KOSONG)->update(['nip' => null]);
        $migration->up();

        $this->assertCount(2, $this->fkTerpasang(array_keys(AddFkPegawaiDiPenggunaFaqRate::FOREIGN_KEYS)));
    }

    private function upDitolak(Migration $migration): string
    {
        try {
            $migration->up();
        } catch (Throwable $e) {
            $this->assertInstanceOf(RuntimeException::class, $e, 'pra-cek, bukan galat ALTER: ' . $e->getMessage());

            return $e->getMessage();
        }

        $this->fail('up() harus ditolak pra-cek');
    }

    /**
     * @template T of Migration
     *
     * @param class-string<T> $kelas
     *
     * @return T
     */
    private function instance(string $kelas): Migration
    {
        $nama = substr($kelas, (int) strrpos($kelas, '\\') + 1);
        $file = APPPATH . "Database/MigrationsDitahan/{$nama}.php";

        if (! is_file($file)) {
            throw new RuntimeException("File migration ditahan {$file} tidak ditemukan.");
        }

        require_once $file;

        return new $kelas();
    }

    /**
     * @param list<string> $nama
     *
     * @return list<string>
     */
    private function fkTerpasang(array $nama): array
    {
        $rows = $this->db->query(
            'SELECT CONSTRAINT_NAME AS n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME IN ? ORDER BY CONSTRAINT_NAME',
            [$this->db->getDatabase(), $nama],
        )->getResultArray();

        return array_map(static fn (array $r): string => (string) $r['n'], $rows);
    }

    /**
     * Kolom wajib `riwayat_cuti` untuk satu baris uji (pola MigrasiLayananSiklusTest).
     *
     * @param array<string, mixed> $timpa
     *
     * @return array<string, mixed>
     */
    private function barisCuti(string $nip, array $timpa): array
    {
        $kolom = $this->db->query(
            'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable('riwayat_cuti')],
        )->getResultArray();
        $baris = ['nip' => $nip, 'id_jenis_cuti' => 1, 'nip_atasan_langsung' => null, 'nip_yang_menyetujui' => null];

        foreach ($kolom as $k) {
            $nama = (string) $k['COLUMN_NAME'];

            if (array_key_exists($nama, $baris) || $k['IS_NULLABLE'] === 'YES' || $k['COLUMN_DEFAULT'] !== null || str_contains((string) $k['EXTRA'], 'auto_increment')) {
                continue;
            }

            $baris[$nama] = match ((string) $k['DATA_TYPE']) {
                'date'                  => '2026-01-05',
                'datetime', 'timestamp' => '2026-01-05 00:00:00',
                'int', 'tinyint'        => 1,
                default                 => substr('Uji ' . $nama, 0, max(1, (int) ($k['CHARACTER_MAXIMUM_LENGTH'] ?? 10))),
            };
        }

        return array_merge($baris, $timpa);
    }
}
