<?php

declare(strict_types=1);

namespace Tests\Database\HaloSimpeg\Chatbot;

use App\Database\Migrations\CreateChatbotFase8;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\SkemaKepegawaianTestTrait;

require_once APPPATH . 'Database/Migrations/2026-11-13-100000_CreateChatbotFase8.php';

/**
 * DBV-033 — test skema chatbot "Vanya" (PRD Fase 8 §7.10.1, AC-E10.2). Diuji:
 * - struktur, tipe, collation `utf8mb4_unicode_ci` keenam tabel;
 * - seed `qna_status` 1/2/10;
 * - indeks (`idx_log_waktu`, `idx_log_qna_feedback`, `status_kategori`) dan PK komposit relasi;
 * - FK RESTRICT/RESTRICT (1451/1452), UNIQUE (1062), CHECK (3819 MySQL / 4025 MariaDB);
 * - judul unik status 1/2 lewat `judul_unik` (F8-40): judul status 10 boleh dipakai lagi;
 * - tanpa kolom identitas di `log_chatbot` (K-4);
 * - siklus down()/up().
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SkemaChatbotTest extends DatabaseTestCase
{
    use SkemaKepegawaianTestTrait;

    private const CHECK = [3819, 4025];

    private const VERSI = '2026-11-13-100000';

    protected function tearDown(): void
    {
        if ($this->adaYangDilepas()) {
            $this->pasangUlang();
        }

        parent::tearDown();
    }

    public function testStrukturDanCollation(): void
    {
        foreach (CreateChatbotFase8::TABEL as $tabel) {
            $info = $this->skemaTableInfo($tabel);
            $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null], $tabel);

            foreach ($this->skemaStringCollations($tabel) as $kolom => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "{$tabel}.{$kolom}");
            }
        }

        $this->assertSame(['id_qna_status' => 'int unsigned', 'judul_status' => 'varchar(50)'], $this->skemaColumns('qna_status'));
        $this->assertSame([
            'id_qna_kategori'        => 'int unsigned',
            'judul_kategori'         => 'varchar(150)',
            'status_qna_kategori_fk' => 'int unsigned',
            'tampil_bubble'          => 'tinyint(1)',
            'created_at'             => 'datetime null',
            'updated_at'             => 'datetime null',
            'judul_unik'             => 'varchar(150) null',
        ], $this->skemaColumns('qna_kategori'));
        $this->assertSame([
            'id_qna_tag'        => 'int unsigned',
            'judul_tag'         => 'varchar(100)',
            'status_qna_tag_fk' => 'int unsigned',
            'created_at'        => 'datetime null',
            'updated_at'        => 'datetime null',
            'judul_unik'        => 'varchar(100) null',
        ], $this->skemaColumns('qna_tag'));
        $this->assertSame([
            'id_qna'             => 'int unsigned',
            'pertanyaan'         => 'text',
            'jawaban'            => 'text',
            'id_qna_kategori_fk' => 'int unsigned null',
            'status_qna_fk'      => 'int unsigned',
            'is_fallback_option' => 'tinyint(1)',
            'created_at'         => 'datetime null',
            'updated_at'         => 'datetime null',
        ], $this->skemaColumns('qna'));
        $this->assertSame(['qna_id' => 'int unsigned', 'qna_tag_id' => 'int unsigned'], $this->skemaColumns('qna_has_tag'));
        $this->assertSame([
            'id_log'          => 'int unsigned',
            'pesan_user'      => 'text',
            'top_1_id_qna'    => 'int unsigned',
            'top_1_skor'      => 'decimal(5,4)',
            'top_3_kandidat'  => 'longtext',
            'status_match'    => 'tinyint',
            'feedback'        => 'tinyint null',
            'waktu_interaksi' => 'datetime',
        ], $this->skemaColumns('log_chatbot'), 'anonim: tanpa kolom identitas (K-4)');
    }

    public function testIndeksDanFk(): void
    {
        $this->assertSame([['id_log'], [
            'idx_log_qna_feedback' => ['top_1_id_qna', 'status_match', 'feedback'],
            'idx_log_waktu'        => ['waktu_interaksi'],
        ], []], $this->skemaIndexes('log_chatbot'));
        $this->assertSame([['qna_id', 'qna_tag_id'], ['qna_tag_id' => ['qna_tag_id']], []], $this->skemaIndexes('qna_has_tag'));
        $this->assertSame([['id_qna'], [
            'id_qna_kategori_fk' => ['id_qna_kategori_fk'],
            'status_kategori'    => ['status_qna_fk', 'id_qna_kategori_fk'],
        ], []], $this->skemaIndexes('qna'));
        $this->assertSame('uk_qna_kategori_judul_unik', $this->skemaIndexes('qna_kategori')[2][0] ?? null);
        $this->assertSame('uk_qna_tag_judul_unik', $this->skemaIndexes('qna_tag')[2][0] ?? null);

        [$fks, , $bukanRestrict] = $this->skemaForeignKeys(CreateChatbotFase8::TABEL, []);
        $this->assertSame([
            'fk_qna_has_tag_qna'     => ['qna_has_tag', 'qna_id', 'qna', 'id_qna'],
            'fk_qna_has_tag_tag'     => ['qna_has_tag', 'qna_tag_id', 'qna_tag', 'id_qna_tag'],
            'fk_qna_kategori'        => ['qna', 'id_qna_kategori_fk', 'qna_kategori', 'id_qna_kategori'],
            'fk_qna_kategori_status' => ['qna_kategori', 'status_qna_kategori_fk', 'qna_status', 'id_qna_status'],
            'fk_qna_status'          => ['qna', 'status_qna_fk', 'qna_status', 'id_qna_status'],
            'fk_qna_tag_status'      => ['qna_tag', 'status_qna_tag_fk', 'qna_status', 'id_qna_status'],
        ], $fks, 'log_chatbot tanpa FK; tidak ada FK ke helpdesk/FAQ');
        $this->assertSame([], $bukanRestrict);
        $this->assertCount(10, $this->skemaChecks(CreateChatbotFase8::TABEL));
    }

    public function testSeedStatus(): void
    {
        $isi = array_column($this->db->table('qna_status')->orderBy('id_qna_status')->get()->getResultArray(), 'judul_status', 'id_qna_status');
        $this->assertSame([1 => 'Aktif', 2 => 'Tidak Aktif', 10 => 'Dihapus'], array_map('strval', $isi));
        $this->assertDbError(self::CHECK, fn () => $this->db->table('qna_status')->insert(['id_qna_status' => 3, 'judul_status' => 'Lain']));
    }

    public function testCheckFkDanUnique(): void
    {
        $kat = $this->sisip('qna_kategori', ['judul_kategori' => 'Cuti']);
        $tag = $this->sisip('qna_tag', ['judul_tag' => 'cuti']);
        $qna = $this->sisip('qna', ['pertanyaan' => 'Bagaimana?', 'jawaban' => '<p>Begini</p>', 'id_qna_kategori_fk' => $kat]);
        $this->sisip('qna', ['pertanyaan' => 'Tanpa kategori', 'jawaban' => 'x']); // NULL-able ikut dev
        $this->db->table('qna_has_tag')->insert(['qna_id' => $qna, 'qna_tag_id' => $tag]);

        $this->assertDbError([1062], fn () => $this->db->table('qna_has_tag')->insert(['qna_id' => $qna, 'qna_tag_id' => $tag]));
        $this->assertDbError([1452], fn () => $this->db->table('qna_has_tag')->insert(['qna_id' => 999999, 'qna_tag_id' => $tag]));
        $this->assertDbError([1452], fn () => $this->sisip('qna', ['pertanyaan' => 'p', 'jawaban' => 'j', 'id_qna_kategori_fk' => 999999]));
        $this->assertDbError([1451], fn () => $this->db->table('qna')->where('id_qna', $qna)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('qna_tag')->where('id_qna_tag', $tag)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('qna_kategori')->where('id_qna_kategori', $kat)->delete());

        $this->assertDbError(self::CHECK, fn () => $this->sisip('qna_kategori', ['judul_kategori' => 'X', 'status_qna_kategori_fk' => 3]));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('qna_kategori', ['judul_kategori' => 'Y', 'tampil_bubble' => 2]));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('qna_tag', ['judul_tag' => 'z', 'status_qna_tag_fk' => 0]));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('qna', ['pertanyaan' => 'p', 'jawaban' => 'j', 'status_qna_fk' => 4]));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('qna', ['pertanyaan' => 'p', 'jawaban' => 'j', 'is_fallback_option' => 2]));

        $log = ['pesan_user' => 'cuti', 'top_1_id_qna' => $qna, 'top_1_skor' => 0.25, 'top_3_kandidat' => '[]', 'status_match' => 1, 'waktu_interaksi' => '2026-11-13 01:00:00'];
        $id  = $this->sisip('log_chatbot', $log);
        $this->assertSame('0.2500', (string) $this->db->table('log_chatbot')->where('id_log', $id)->get()->getRow('top_1_skor'));
        $this->db->table('log_chatbot')->where('id_log', $id)->update(['feedback' => -1]);
        $this->assertDbError(self::CHECK, fn () => $this->sisip('log_chatbot', ['top_3_kandidat' => 'bukan json'] + $log));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('log_chatbot', ['status_match' => 2] + $log));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('log_chatbot', ['feedback' => 0] + $log));
        $this->assertDbError(self::CHECK, fn () => $this->sisip('log_chatbot', ['top_1_skor' => -0.1] + $log));
        $this->sisip('log_chatbot', ['top_1_id_qna' => 999999] + $log); // tanpa FK: QnA hilang tetap tercatat (#id)
    }

    public function testJudulUnikHanyaStatusAktifDanTidakAktif(): void
    {
        foreach ([['qna_kategori', 'judul_kategori', 'status_qna_kategori_fk', 'id_qna_kategori'], ['qna_tag', 'judul_tag', 'status_qna_tag_fk', 'id_qna_tag']] as [$tabel, $judul, $status, $pk]) {
            $a = $this->sisip($tabel, [$judul => 'Tunjangan Kinerja']);
            $this->assertDbError([1062], fn () => $this->sisip($tabel, [$judul => 'tunjangan kinerja'])); // _ci
            $this->assertDbError([1062], fn () => $this->sisip($tabel, [$judul => 'Tunjangan Kinerja', $status => 2]));

            $this->db->table($tabel)->where($pk, $a)->update([$status => 10]);
            $b = $this->sisip($tabel, [$judul => 'Tunjangan Kinerja']); // judul status 10 boleh dipakai lagi
            $this->sisip($tabel, [$judul => 'Tunjangan Kinerja', $status => 10]); // dua baris status 10 berjudul sama
            $this->assertDbError([1062], fn () => $this->db->table($tabel)->where($pk, $a)->update([$status => 1])); // pulih bentrok
            $this->assertGreaterThan($a, $b, $tabel);
        }
    }

    public function testDownLaluUpKembali(): void
    {
        $this->lepasSejak(self::VERSI);

        foreach (CreateChatbotFase8::TABEL as $tabel) {
            $this->assertFalse($this->skemaTableExists($tabel), $tabel);
        }

        $this->assertTrue($this->skemaTableExists('faq_article'), 'FAQ tidak tersentuh');

        $this->pasangUlang();

        foreach (CreateChatbotFase8::TABEL as $tabel) {
            $this->assertTrue($this->skemaTableExists($tabel), $tabel);
        }

        $this->assertSame(3, $this->db->table('qna_status')->countAllResults(), 'seed ikut terpasang ulang');
    }

    public function testKueriAuditBerjalan(): void
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(ROOTPATH . 'docs/db-review/sql/DBV-033-audit-chatbot.sql'));
        $n   = 0;

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) {
            foreach (CreateChatbotFase8::TABEL as $t) {
                $q = (string) preg_replace('/(FROM|JOIN)\s+' . $t . '\b/', '$1 ' . $this->db->prefixTable($t), $q);
            }

            $hasil = $this->db->query($q)->getResultArray();
            $this->assertSame(['butir', 'jumlah'], array_keys($hasil[0] ?? []), $q);

            if (str_contains((string) $hasil[0]['butir'], 'WAJIB_0')) {
                $this->assertSame(0, (int) $hasil[0]['jumlah'], (string) $hasil[0]['butir']);
            }

            $n++;
        }

        $this->assertSame(13, $n);
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
