<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg;

use App\Database\Migrations\AlterDisiplinTicketIndeksFase8;
use App\Database\Migrations\CreateHaloSimpegFase8;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\SkemaKepegawaianTestTrait;

require_once APPPATH . 'Database/Migrations/2026-11-16-100000_CreateHaloSimpegFase8.php';
require_once APPPATH . 'Database/Migrations/2026-11-16-100100_AlterDisiplinTicketIndeksFase8.php';

/**
 * DBV-028 — test skema helpdesk Halo Simpeg (PRD Fase 8 v1.3 §7.1, AC-E01.2/AC-E01.3). Diuji:
 * - struktur, tipe, collation `utf8mb4_unicode_ci` tiga tabel baru (`id_user` INT UNSIGNED agar FK ke `pengguna`);
 * - UNIQUE `contact`, `id_user`, pasangan petugas-kategori (1062); FK RESTRICT/RESTRICT (1451/1452); CHECK status 0/1
 *   (3819 MySQL / 4025 MariaDB);
 * - default status 1 dan waktu tanpa DEFAULT (BP8-8);
 * - indeks aditif `disiplin_ticket(_msg)` tanpa mengubah kolom/FK DBV-022;
 * - `question_category`, `disiplin_ticket(_msg)` tidak dibuat ulang dan tidak ikut di-DROP;
 * - up() gagal tidak menyentuh tabel yang sudah ada; siklus down()/up();
 * - kueri audit DBV-028 berjalan di skema v2.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SkemaHaloSimpegTest extends DatabaseTestCase
{
    use SkemaKepegawaianTestTrait;

    private const CHECK = [3819, 4025];

    private const VERSI = '2026-11-16-100000';

    protected function tearDown(): void
    {
        if ($this->adaYangDilepas()) {
            $this->pasangUlang();
        }

        parent::tearDown();
    }

    public function testStrukturDanCollation(): void
    {
        foreach (CreateHaloSimpegFase8::TABEL as $tabel) {
            $info = $this->skemaTableInfo($tabel);
            $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null], $tabel);

            foreach ($this->skemaStringCollations($tabel) as $kolom => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "{$tabel}.{$kolom}");
            }
        }

        $this->assertSame([
            'id_help_desk_contact' => 'int',
            'name'                 => 'varchar(150)',
            'contact'              => 'varchar(20)',
            'email'                => 'varchar(150) null',
            'status'               => 'tinyint',
            'created_at'           => 'datetime null',
            'updated_at'           => 'datetime null',
        ], $this->skemaColumns('help_desk_contact'));
        $this->assertSame([
            'id_support_officer' => 'int',
            'id_user'            => 'int unsigned',
            'email'              => 'varchar(150)',
            'no_hp'              => 'varchar(20) null',
            'status'             => 'tinyint',
            'created_at'         => 'datetime null',
            'updated_at'         => 'datetime null',
        ], $this->skemaColumns('support_officer'));
        $this->assertSame([
            'id'                   => 'int',
            'id_support_officer'   => 'int',
            'id_question_category' => 'int',
            'created_at'           => 'datetime null',
        ], $this->skemaColumns('officer_category'));

        $this->assertSame('int unsigned', $this->skemaColumns('pengguna')['id_pengguna'], 'tipe FK id_user = pengguna.id_pengguna');
        $this->assertSame('int', $this->skemaColumns('question_category')['id_question_category'], 'tipe FK kategori');
    }

    public function testIndeksFkDanCheck(): void
    {
        $this->assertSame([['id_help_desk_contact'], [
            'status'                       => ['status'],
            'uk_help_desk_contact_contact' => ['contact'],
        ], ['uk_help_desk_contact_contact']], $this->skemaIndexes('help_desk_contact'));
        $this->assertSame([['id_support_officer'], [
            'status'                     => ['status'],
            'uk_support_officer_id_user' => ['id_user'],
        ], ['uk_support_officer_id_user']], $this->skemaIndexes('support_officer'));
        $this->assertSame([['id'], [
            'id_question_category' => ['id_question_category'],
            'uk_officer_category'  => ['id_support_officer', 'id_question_category'],
        ], ['uk_officer_category']], $this->skemaIndexes('officer_category'));

        [$fks, , $bukanRestrict] = $this->skemaForeignKeys(CreateHaloSimpegFase8::TABEL, []);
        $this->assertSame([
            'fk_id_qc_oc_to_question_category' => ['officer_category', 'id_question_category', 'question_category', 'id_question_category'],
            'fk_id_so_oc_to_support_officer'   => ['officer_category', 'id_support_officer', 'support_officer', 'id_support_officer'],
            'fk_id_user_so_to_pengguna'        => ['support_officer', 'id_user', 'pengguna', 'id_pengguna'],
        ], $fks);
        $this->assertSame([], $bukanRestrict);
        $this->assertSame([
            'chk_help_desk_contact_status' => ['help_desk_contact', 'statusin0,1'],
            'chk_support_officer_status'   => ['support_officer', 'statusin0,1'],
        ], $this->skemaChecks(CreateHaloSimpegFase8::TABEL));
    }

    public function testIndeksAditifDisiplinTicket(): void
    {
        $tiket = $this->skemaIndexes('disiplin_ticket')[1];
        $pesan = $this->skemaIndexes('disiplin_ticket_msg')[1];

        foreach (AlterDisiplinTicketIndeksFase8::INDEKS as $nama => [$tabel, $kolom]) {
            $this->assertSame($kolom, ($tabel === 'disiplin_ticket' ? $tiket : $pesan)[$nama] ?? null, $nama);
        }

        // KEY lama DBV-022 tetap (pembuangan = keputusan DB Validator, DBV-028 Q4); kolom/FK tidak berubah.
        $this->assertSame(['id_question_category'], $tiket['fk_id_question_category_i_qc'] ?? null);
        $this->assertSame(['id_inbox'], $pesan['fk_id_inbox_im_qc'] ?? null);
        $this->assertSame(['created_at'], $tiket['created_at'] ?? null);
        $this->assertSame(['nip'], $tiket['nip'] ?? null);
    }

    public function testDefault(): void
    {
        $this->assertSame(['id_help_desk_contact' => 'auto_increment', 'status' => '1'], $this->skemaDefaults('help_desk_contact'));
        $this->assertSame(['id_support_officer' => 'auto_increment', 'status' => '1'], $this->skemaDefaults('support_officer'));
        $this->assertSame(['id' => 'auto_increment'], $this->skemaDefaults('officer_category'), 'created_at tanpa DEFAULT (BP8-8)');
    }

    public function testUniqueFkDanCheckDitegakkan(): void
    {
        $akunA = $this->akun('dbv028.a');
        $akunB = $this->akun('dbv028.b');
        $kat   = $this->sisip('question_category', ['question_category' => 'Kategori Uji']);
        $kat2  = $this->sisip('question_category', ['question_category' => 'Kategori Uji 2']);

        $so = $this->sisip('support_officer', ['id_user' => $akunA, 'email' => 'a@contoh.test']);
        $this->assertSame(1, (int) $this->db->table('support_officer')->where('id_support_officer', $so)->get()->getRow('status'), 'DEFAULT 1');
        $this->assertDbError([1062], fn () => $this->sisip('support_officer', ['id_user' => $akunA, 'email' => 'x@contoh.test']));
        $this->assertDbError([1452], fn () => $this->sisip('support_officer', ['id_user' => 999999, 'email' => 'x@contoh.test']));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('support_officer', ['id_user' => $akunB, 'email' => 'b@contoh.test', 'status' => 2]));

        // Satu petugas ≥ 2 kategori dan satu kategori ≥ 2 petugas (AC-E02.2); pasangan ganda ditolak.
        $so2 = $this->sisip('support_officer', ['id_user' => $akunB, 'email' => 'b@contoh.test', 'status' => 0]);
        $this->sisip('officer_category', ['id_support_officer' => $so, 'id_question_category' => $kat]);
        $this->sisip('officer_category', ['id_support_officer' => $so, 'id_question_category' => $kat2]);
        $this->sisip('officer_category', ['id_support_officer' => $so2, 'id_question_category' => $kat]);
        $this->assertDbError([1062], fn () => $this->sisip('officer_category', ['id_support_officer' => $so, 'id_question_category' => $kat]));
        $this->assertDbError([1452], fn () => $this->sisip('officer_category', ['id_support_officer' => 999999, 'id_question_category' => $kat]));
        $this->assertDbError([1452], fn () => $this->sisip('officer_category', ['id_support_officer' => $so, 'id_question_category' => 999999]));

        // RESTRICT: kategori/petugas/akun yang dipakai tidak bisa dihapus (E-02: hapus 422 di service, B-12).
        $this->assertDbError([1451], fn () => $this->db->table('question_category')->where('id_question_category', $kat)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('support_officer')->where('id_support_officer', $so)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('pengguna')->where('id_pengguna', $akunA)->delete());

        // Kontak: nomor unik (_ci), status 0/1.
        $this->sisip('help_desk_contact', ['name' => 'Helpdesk', 'contact' => '0800111']);
        $this->assertDbError([1062], fn () => $this->sisip('help_desk_contact', ['name' => 'Lain', 'contact' => '0800111']));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('help_desk_contact', ['name' => 'Lain', 'contact' => '0800222', 'status' => 2]));
        $this->sisip('help_desk_contact', ['name' => 'Tanpa email', 'contact' => '0800333', 'status' => 0]);
    }

    public function testUpGagalTidakMenyentuhTabelYangSudahAda(): void
    {
        $this->lepasSejak(self::VERSI);
        $this->db->query('CREATE TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable('help_desk_contact')) . ' (`id_help_desk_contact` INT NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->db->table('help_desk_contact')->insert(['id_help_desk_contact' => 1]);

        try {
            (new CreateHaloSimpegFase8())->up();
            $this->fail('up() seharusnya gagal (1050)');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('help_desk_contact', $e->getMessage());
        }

        $this->assertSame(1, $this->db->table('help_desk_contact')->countAllResults(), 'tabel lama tetap utuh');

        foreach (array_slice(CreateHaloSimpegFase8::TABEL, 1) as $tabel) {
            $this->assertFalse($this->skemaTableExists($tabel), $tabel);
        }

        $this->db->query('DROP TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable('help_desk_contact')));
    }

    public function testDownLaluUpKembali(): void
    {
        $this->lepasSejak(self::VERSI);

        foreach (CreateHaloSimpegFase8::TABEL as $tabel) {
            $this->assertFalse($this->skemaTableExists($tabel), $tabel);
        }

        foreach (['question_category', 'disiplin_ticket', 'disiplin_ticket_msg', 'pengguna'] as $tabel) {
            $this->assertTrue($this->skemaTableExists($tabel), "{$tabel} tidak tersentuh");
        }

        $tiket = $this->skemaIndexes('disiplin_ticket')[1];
        $this->assertArrayNotHasKey('idx_dticket_sender_status', $tiket, 'indeks aditif ikut dilepas');
        $this->assertArrayHasKey('fk_id_question_category_i_qc', $tiket, 'KEY DBV-022 tetap');

        $this->pasangUlang();

        foreach (CreateHaloSimpegFase8::TABEL as $tabel) {
            $this->assertTrue($this->skemaTableExists($tabel), $tabel);
        }

        $this->assertArrayHasKey('idx_dticket_sender_status', $this->skemaIndexes('disiplin_ticket')[1]);
    }

    public function testKueriAuditBerjalan(): void
    {
        $isi = (string) file_get_contents(ROOTPATH . 'docs/db-review/sql/DBV-028-audit-halo-simpeg.sql');
        // Bagian C memakai kolom legacy (`pengguna.id`) dan hanya dijalankan di salinan legacy.
        [$v2, $legacy] = explode('-- C. LEGACY SAJA', $isi, 2) + ['', ''];
        $this->assertStringContainsString('Q-H17', $legacy);

        $sql   = (string) preg_replace('/^\s*--.*$/m', '', $v2);
        $tabel = [...CreateHaloSimpegFase8::TABEL, 'question_category', 'disiplin_ticket_msg', 'disiplin_ticket', 'pengguna'];
        $n     = 0;

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) {
            foreach ($tabel as $t) {
                $q = (string) preg_replace('/(FROM|JOIN)\s+' . $t . '\b/', '$1 ' . $this->db->prefixTable($t), $q);
            }

            $hasil = $this->db->query($q)->getResultArray();
            $this->assertSame(['butir', 'jumlah'], array_keys($hasil[0] ?? []), $q);

            if (str_contains((string) $hasil[0]['butir'], 'WAJIB_0')) {
                $this->assertSame(0, (int) $hasil[0]['jumlah'], (string) $hasil[0]['butir']);
            }

            $n++;
        }

        $this->assertSame(16, $n);
    }

    private function akun(string $username): int
    {
        $this->db->table('pengguna')->insert(['username' => $username, 'user_level' => 1]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string, mixed> $baris
     */
    private function sisip(string $tabel, array $baris): int
    {
        $this->db->table($tabel)->insert($baris);

        return (int) $this->db->insertID();
    }
}
