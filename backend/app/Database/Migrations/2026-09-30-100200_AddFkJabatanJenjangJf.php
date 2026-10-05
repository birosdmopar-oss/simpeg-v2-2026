<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * DBV-018 — FK legacy `fk_id_jenjang_jf_jab_to_jenjang_jf`: `jabatan.id_jenjang_jf` → `jenjang_jf.id_jenjang_jf`
 * ([K] D1:1469; kolom TINYINT D1:1453 dan KEY-nya sudah dibuat DBV-008). Aksi RESTRICT/RESTRICT [V2] (legacy ON DELETE
 * SET NULL ON UPDATE CASCADE). Dipisah dari migration CREATE agar migration DBV-008 tidak diubah lagi dan rollback
 * melepas FK sebelum tabel `jenjang_jf` di-drop.
 *
 * Tabel masih kosong saat migration ini jalan (DB v2 baru). Impor wajib mengaudit `jabatan.id_jenjang_jf` yatim lebih
 * dulu (G-02 Bagian 6.5 #2, G-02b Bagian 6.5).
 * Review DB Validator: backend/docs/db-review/G-02b-jabatan-sisa-schema.md.
 */
class AddFkJabatanJenjangJf extends Migration
{
    private const FK = 'fk_id_jenjang_jf_jab_to_jenjang_jf';

    public function up(): void
    {
        $this->exec(
            "ALTER TABLE {$this->t('jabatan')} ADD CONSTRAINT `" . self::FK . '` FOREIGN KEY (`id_jenjang_jf`) '
            . "REFERENCES {$this->t('jenjang_jf')} (`id_jenjang_jf`) ON DELETE RESTRICT ON UPDATE RESTRICT",
        );
    }

    public function down(): void
    {
        // KEY `fk_id_jenjang_jf_jab_to_jenjang_jf` milik DBV-008 tetap ada; hanya constraint yang dilepas.
        $this->exec("ALTER TABLE {$this->t('jabatan')} DROP FOREIGN KEY `" . self::FK . '`');
    }

    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-018: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-018: DDL FK jabatan → jenjang_jf gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
