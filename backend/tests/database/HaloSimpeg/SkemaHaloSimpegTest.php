<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg;

use App\Database\Migrations\AlterDisiplinTicketIndeksFase8;
use App\Database\Migrations\CreateHaloSimpegFase8;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\SkemaKepegawaianTestTrait;
use Throwable;

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
 * - up() gagal (kedua migration) melepas hanya tabel/indeks yang dibuat pada run itu dan tidak menyentuh yang sudah ada;
 *   siklus down()/up() untuk tiga tabel dan tiga indeks;
 * - kueri audit DBV-028: blok A identik di berkas legacy dan v2; berkas v2 berjalan di skema v2 (Q-H13, Q-H15 dengan data).
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SkemaHaloSimpegTest extends DatabaseTestCase
{
    use SkemaKepegawaianTestTrait;

    private const CHECK = [3819, 4025];

    private const VERSI = '2026-11-16-100000';

    private const VERSI_INDEKS = '2026-11-16-100100';

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

    public function testUpGagalMelepasTabelYangDibuatSajaDanTidakMenyentuhTabelLama(): void
    {
        $this->lepasSejak(self::VERSI);
        // Tabel TERAKHIR sudah ada → dua tabel pertama sempat dibuat pada run ini lalu wajib di-DROP (dropDibuat()).
        $this->db->query('CREATE TABLE ' . $this->skemaQuoted('officer_category') . ' (`id` INT NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        try {
            $this->db->table('officer_category')->insert(['id' => 7]);

            try {
                (new CreateHaloSimpegFase8())->up();
                $this->fail('up() seharusnya gagal (1050)');
            } catch (Throwable $e) {
                $this->assertSame(1050, $this->kodeGalat($e, [1050]), $e->getMessage());
            }

            $this->assertFalse($this->skemaTableExists('help_desk_contact'), 'help_desk_contact dibuat run ini → di-DROP');
            $this->assertFalse($this->skemaTableExists('support_officer'), 'support_officer dibuat run ini → di-DROP');
            $this->assertSame(1, $this->db->table('officer_category')->where('id', 7)->countAllResults(), 'tabel lama + barisnya utuh');
            $this->assertSame(['id' => 'int'], $this->skemaColumns('officer_category'), 'struktur tabel lama tidak disentuh');
        } finally {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->skemaQuoted('officer_category'));
        }
    }

    public function testUpIndeksGagalMelepasIndeksYangDibuatSaja(): void
    {
        $this->lepasSejak(self::VERSI_INDEKS);
        // Indeks TERAKHIR sudah dipasang manual → dua indeks pertama sempat dibuat pada run ini lalu wajib dilepas.
        $this->db->query('ALTER TABLE ' . $this->skemaQuoted('disiplin_ticket_msg') . ' ADD INDEX `idx_dticketmsg_inbox_waktu` (`id_inbox`, `created_at`)');

        try {
            try {
                (new AlterDisiplinTicketIndeksFase8())->up();
                $this->fail('up() seharusnya gagal (1061)');
            } catch (Throwable $e) {
                $this->assertSame(1061, $this->kodeGalat($e, [1061]), $e->getMessage());
            }

            $tiket = $this->skemaIndexes('disiplin_ticket')[1];
            $pesan = $this->skemaIndexes('disiplin_ticket_msg')[1];
            $this->assertArrayNotHasKey('idx_dticket_kategori_status_baca', $tiket, 'dibuat run ini → dilepas');
            $this->assertArrayNotHasKey('idx_dticket_sender_status', $tiket, 'dibuat run ini → dilepas');
            $this->assertSame(['id_inbox', 'created_at'], $pesan['idx_dticketmsg_inbox_waktu'] ?? null, 'indeks lama tidak disentuh');
            $this->assertSame(['id_question_category'], $tiket['fk_id_question_category_i_qc'] ?? null, 'KEY DBV-022 utuh');
            $this->assertSame(['id_inbox'], $pesan['fk_id_inbox_im_qc'] ?? null, 'KEY DBV-022 utuh');
        } finally {
            $this->db->query('ALTER TABLE ' . $this->skemaQuoted('disiplin_ticket_msg') . ' DROP INDEX `idx_dticketmsg_inbox_waktu`');
        }
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

        $indeks = ['disiplin_ticket' => $this->skemaIndexes('disiplin_ticket')[1], 'disiplin_ticket_msg' => $this->skemaIndexes('disiplin_ticket_msg')[1]];

        foreach (AlterDisiplinTicketIndeksFase8::INDEKS as $nama => [$tabel]) {
            $this->assertArrayNotHasKey($nama, $indeks[$tabel], "{$nama} ikut dilepas down()");
        }

        $this->assertSame(['id_question_category'], $indeks['disiplin_ticket']['fk_id_question_category_i_qc'] ?? null, 'KEY DBV-022 tetap');
        $this->assertSame(['id_inbox'], $indeks['disiplin_ticket_msg']['fk_id_inbox_im_qc'] ?? null, 'KEY DBV-022 tetap');

        $this->pasangUlang();

        foreach (CreateHaloSimpegFase8::TABEL as $tabel) {
            $this->assertTrue($this->skemaTableExists($tabel), $tabel);
        }

        $indeks = ['disiplin_ticket' => $this->skemaIndexes('disiplin_ticket')[1], 'disiplin_ticket_msg' => $this->skemaIndexes('disiplin_ticket_msg')[1]];

        foreach (AlterDisiplinTicketIndeksFase8::INDEKS as $nama => [$tabel, $kolom]) {
            $this->assertSame($kolom, $indeks[$tabel][$nama] ?? null, "{$nama} dipasang ulang up() dengan urutan kolom tepat");
        }

        $this->assertSame(['id_inbox'], $indeks['disiplin_ticket_msg']['fk_id_inbox_im_qc'] ?? null);
    }

    public function testKueriAuditBerjalan(): void
    {
        $legacy = (string) file_get_contents(ROOTPATH . 'docs/db-review/sql/DBV-028-audit-legacy.sql');
        $v2     = (string) file_get_contents(ROOTPATH . 'docs/db-review/sql/DBV-028-audit-v2.sql');

        // Blok A identik di kedua berkas; bagian C (kolom legacy `pengguna.id`) hanya di berkas legacy, bagian B hanya di v2.
        $this->assertSame($this->blokA($legacy), $this->blokA($v2));
        $this->assertStringContainsString('Q-H17', $legacy);
        $this->assertStringNotContainsString('Q-H11', $legacy);
        $this->assertStringNotContainsString('Q-H17', $v2);
        $this->assertCount(12, $this->kueri($legacy));

        // Data uji: Q-H13 menangkap segmen `.`/`..` dan karakter di luar pola; Q-H15 menghitung akun nonaktif sebagai tanpa petugas.
        $akun = $this->akun('dbv028.audit');
        $kat  = $this->sisip('question_category', ['question_category' => 'Kategori Audit']);
        $so   = $this->sisip('support_officer', ['id_user' => $akun, 'email' => 'audit@contoh.test']);
        $this->sisip('officer_category', ['id_support_officer' => $so, 'id_question_category' => $kat]);
        $tiket = $this->sisip('disiplin_ticket', ['id_question_category' => $kat, 'subject' => 'Uji', 'sender' => $akun, 'sender_name' => 'Uji', 'nip' => '-']);

        foreach (['arsip/1/inbox/ok.pdf', '../x.pdf', 'arsip/./x.pdf', 'a b.pdf'] as $lampiran) {
            $this->sisip('disiplin_ticket_msg', ['id_inbox' => $tiket, 'msg_sender' => $akun, 'msg_sender_name' => 'Uji', 'msg' => 'isi', 'attachments' => $lampiran]);
        }

        $hasil = $this->jalankanAudit($v2);
        $this->assertCount(18, $hasil);
        $this->assertSame(1, $hasil['Q-H19'], 'satu tiket di kategori petugas aktif');
        $this->assertSame(3, $hasil['Q-H13'], 'lampiran ../x.pdf, arsip/./x.pdf, a b.pdf');
        $this->assertSame(4, $hasil['Q-H14']);
        $this->assertSame(0, $hasil['Q-H15'], 'petugas + akun aktif');

        $this->db->table('pengguna')->where('id_pengguna', $akun)->update(['status' => '0']);
        $this->assertSame(1, $this->jalankanAudit($v2)['Q-H15'], 'akun nonaktif → kategori tanpa petugas aktif');
    }

    /**
     * Menjalankan semua kueri berkas audit di skema v2 (tabel diberi prefix test).
     *
     * @return array<string, int> kode butir (Q-Hn) => jumlah
     */
    private function jalankanAudit(string $isi): array
    {
        $tabel = [...CreateHaloSimpegFase8::TABEL, 'question_category', 'disiplin_ticket_msg', 'disiplin_ticket', 'pengguna'];
        $hasil = [];

        foreach ($this->kueri($isi) as $q) {
            foreach ($tabel as $t) {
                $q = (string) preg_replace('/(FROM|JOIN)\s+' . $t . '\b/', '$1 ' . $this->db->prefixTable($t), $q);
            }

            $baris = $this->db->query($q)->getResultArray();
            $this->assertSame(['butir', 'jumlah'], array_keys($baris[0] ?? []), $q);
            $butir = (string) $baris[0]['butir'];

            if (str_contains($butir, 'WAJIB_0')) {
                $this->assertSame(0, (int) $baris[0]['jumlah'], $butir);
            }

            $hasil[strtok($butir, ' ')] = (int) $baris[0]['jumlah'];
        }

        return $hasil;
    }

    /**
     * @return list<string>
     */
    private function kueri(string $isi): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $isi);

        return array_values(array_filter(array_map('trim', explode(';', $sql))));
    }

    private function blokA(string $isi): string
    {
        $mulai = strpos($isi, '-- >>> A MULAI');
        $akhir = strpos($isi, '-- <<< A SELESAI');
        $this->assertNotFalse($mulai);
        $this->assertNotFalse($akhir);

        return substr($isi, $mulai, $akhir - $mulai);
    }

    /**
     * Kode galat DB pada rantai exception (DatabaseException / RuntimeException migration).
     *
     * @param list<int> $kode
     */
    private function kodeGalat(Throwable $e, array $kode): ?int
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if (in_array((int) $t->getCode(), $kode, true)) {
                return (int) $t->getCode();
            }
        }

        return null;
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
