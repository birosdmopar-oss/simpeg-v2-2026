<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-028 (Fase 8 E-01; PRD Fase 8 v1.3 §7.1, A0 §R C3 / review putaran 2) — indeks aditif untuk daftar, antrean, ringkasan, dan
 * thread helpdesk pada tabel DBV-022 yang sudah ada. Tidak mengubah kolom, domain, FK, atau migration lama
 * (`2026-10-26-100500_CreateDisiplinTicketFase4` tetap). Dokumen: backend/docs/db-review/DBV-028-skema-helpdesk.md Bagian 4.
 *
 * - `disiplin_ticket (id_question_category, status, is_read_off)`: antrean petugas per kategori + filter status, `perlu_tindak`
 *   (`status = 0`), ringkasan sisi petugas (`is_read_off = 2`) — kueri ringkasan WAJIB menulis `status IN (0, 1)` eksplisit
 *   agar `is_read_off` ikut dipakai indeks (EXPLAIN dua bentuk: DBV-028 Bagian 4). Menjadi indeks kiri untuk FK kategori.
 * - `disiplin_ticket (sender, status)`: daftar "tiket saya" + filter status + ringkasan sisi pemilik (PK ikut di indeks
 *   sekunder InnoDB, sehingga urut `id_inbox` tidak perlu kolom ketiga).
 * - `disiplin_ticket_msg (id_inbox, created_at)`: thread urut waktu + pesan terakhir per tiket
 *   (`ORDER BY created_at DESC, id_inbox_msg DESC LIMIT 1`). Menjadi indeks kiri untuk FK tiket.
 *
 * KEY FK lama `fk_id_question_category_i_qc` dan `fk_id_inbox_im_qc` menjadi redundan tetapi TIDAK dibuang di sini (keputusan
 * DB Validator, DBV-028 Q4). up() gagal → indeks yang sempat dibuat pada run itu di-DROP; down() men-DROP ketiganya.
 */
class AlterDisiplinTicketIndeksFase8 extends Migration
{
    /** nama indeks => [tabel, kolom] */
    public const INDEKS = [
        'idx_dticket_kategori_status_baca' => ['disiplin_ticket', ['id_question_category', 'status', 'is_read_off']],
        'idx_dticket_sender_status'        => ['disiplin_ticket', ['sender', 'status']],
        'idx_dticketmsg_inbox_waktu'       => ['disiplin_ticket_msg', ['id_inbox', 'created_at']],
    ];

    public function up(): void
    {
        $dibuat = [];

        try {
            foreach (self::INDEKS as $nama => [$tabel, $kolom]) {
                $daftar = implode(', ', array_map(fn (string $k): string => $this->q($k), $kolom));
                $this->exec("ALTER TABLE {$this->t($tabel)} ADD INDEX {$this->q($nama)} ({$daftar})", "ADD INDEX {$nama}");
                $dibuat[] = $nama;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($dibuat) as $nama) {
                try {
                    $this->db->query("ALTER TABLE {$this->t(self::INDEKS[$nama][0])} DROP INDEX {$this->q($nama)}");
                } catch (Throwable) {
                    // Lanjut; galat asli tetap dilempar.
                }
            }

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::INDEKS, true) as $nama => [$tabel]) {
            $this->exec("ALTER TABLE {$this->t($tabel)} DROP INDEX {$this->q($nama)}", "DROP INDEX {$nama}");
        }
    }

    private function t(string $table): string
    {
        return $this->q($this->koneksi()->prefixTable($table));
    }

    /** Identifier (nama indeks/kolom) yang di-escape untuk SQL mentah. */
    private function q(string $identifier): string
    {
        return $this->koneksi()->escapeIdentifiers($identifier);
    }

    private function koneksi(): BaseConnection
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-028: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db;
    }

    private function exec(string $sql, string $label): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-028: {$label} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
