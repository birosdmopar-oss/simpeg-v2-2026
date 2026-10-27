<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-022 (5/9) — disetujui DB Validator 27-10-2026; dipindah ke app/Database/Migrations di M2 (DDL tidak berubah).
 * Isi = usulan coder; belum direview DB Validator.
 *
 * Kelompok D — notifikasi SATU ARAH `inbox` v2 + `inbox_msg` v2: desain baru [V2], TIDAK ada padanannya di D1
 * (keputusan user D10, dokumen Bagian 3.2). Tabel `inbox`/`inbox_msg` legacy = ticketing dua arah dan dibuat sebagai
 * `disiplin_ticket`/`disiplin_ticket_msg` (migration 100500). Prasyarat: `pegawai` (DBV-012), `jenis_layanan` (100000).
 *
 * Rancangan (PRD §6.1.1 #12/#13):
 *   - `inbox`: satu induk per penerima per pengajuan. `nip_penerima` VARCHAR(30) FK → `pegawai` RESTRICT (K1/V4);
 *     `id_pengguna_penerima` INT NULL opsional (DQ-4); `modul` CHECK ('cuti','tb','ib','pmk'); `id_jenis_layanan`
 *     INT NULL FK → `jenis_layanan` RESTRICT — INT, bukan TINYINT seperti tabel dokumen Bagian 3.2, karena PK
 *     `jenis_layanan` = INT (V5); `id_rwy` BIGINT tanpa FK (polimorfik lintas 4 tabel riwayat); `is_read` CHECK 0/1.
 *     Index `(nip_penerima, is_read, created_at)` dan `(modul, id_rwy)`.
 *   - `inbox_msg`: satu baris per kejadian, tanpa balasan. `tipe` CHECK 1..4 (diajukan/keputusan/perubahan/tembusan),
 *     `status_sumber` = status baris sumber saat kejadian (AC-06.1), `reason_note` VARCHAR(255) (Q-23),
 *     `push_status` CHECK 0..3 (antre/terkirim/gagal/tanpa token).
 *   - `created_at` DATETIME NOT NULL TANPA DEFAULT: stempel UTC wajib diisi aplikasi (G2), tidak boleh jatuh ke waktu
 *     lokal server.
 *   - DILARANG (AC-01.2, diuji SkemaInboxTicketTest M2): kolom `status` open/closed, `allow_reply`, `closed_by`,
 *     `is_read_off`, `notify_pic`, atau kolom balasan/thread apa pun.
 *
 * DDL ter-commit per statement: bila CREATE kedua gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU lalu
 * melempar ulang error. down() men-drop kedua tabel (anak dulu).
 */
class CreateInboxNotifikasiFase4 extends Migration
{
    public const TABLES = ['inbox', 'inbox_msg'];

    /**
     * Kolom ticketing yang TIDAK BOLEH ada di tabel notifikasi (AC-01.2).
     *
     * @var list<string>
     */
    public const KOLOM_TERLARANG = ['status', 'allow_reply', 'closed_by', 'is_read_off', 'notify_pic'];

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        $created = [];

        try {
            foreach (self::TABLES as $table) {
                $this->exec($this->createStatement($table), "CREATE TABLE {$table}");
                $created[] = $table;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($created) as $table) {
                try {
                    $this->db->query("DROP TABLE IF EXISTS {$this->t($table)}");
                } catch (Throwable) {
                    // Error asli tetap dilempar; sisa tabel dibersihkan manual (dokumen DBV-022 Bagian 9).
                }
            }

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->exec("DROP TABLE IF EXISTS {$this->t($table)}", "DROP TABLE {$table}");
        }
    }

    private function createStatement(string $table): string
    {
        return match ($table) {
            'inbox' => "CREATE TABLE {$this->t('inbox')} (
                `id_inbox` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nip_penerima` VARCHAR(30) NOT NULL COMMENT 'pegawai penerima notifikasi (approver, PIC, pemohon, tembusan)',
                `id_pengguna_penerima` INT NULL DEFAULT NULL COMMENT 'opsional: akun penerima saat notifikasi dibuat',
                `modul` VARCHAR(20) NOT NULL COMMENT 'cuti | tb | ib | pmk',
                `id_jenis_layanan` INT NULL DEFAULT NULL COMMENT 'NULL untuk cuti (bukan jenis_layanan)',
                `id_rwy` BIGINT NOT NULL COMMENT 'id baris sumber di riwayat_<modul>; tanpa FK (polimorfik)',
                `judul` VARCHAR(150) NOT NULL,
                `url` VARCHAR(255) NOT NULL COMMENT 'rute FE tujuan',
                `is_read` TINYINT NOT NULL DEFAULT 0 COMMENT '0: Belum Dibaca, 1: Sudah Dibaca',
                `read_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL COMMENT 'UTC, diisi aplikasi',
                PRIMARY KEY (`id_inbox`),
                KEY `nip_penerima_is_read_created_at` (`nip_penerima`, `is_read`, `created_at`),
                KEY `modul_id_rwy` (`modul`, `id_rwy`),
                KEY `fk_id_jenis_layanan_inbox_to_jl` (`id_jenis_layanan`),
                CONSTRAINT `fk_nip_penerima_inbox_to_pegawai` FOREIGN KEY (`nip_penerima`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
                CONSTRAINT `fk_id_jenis_layanan_inbox_to_jl` FOREIGN KEY (`id_jenis_layanan`) REFERENCES {$this->t('jenis_layanan')} (`id_jenis_layanan`) " . self::RESTRICT . ",
                CONSTRAINT `chk_inbox_modul` CHECK (`modul` IN ('cuti', 'tb', 'ib', 'pmk')),
                CONSTRAINT `chk_inbox_is_read` CHECK (`is_read` IN (0, 1))
            ) " . self::TABLE_OPTIONS,
            'inbox_msg' => "CREATE TABLE {$this->t('inbox_msg')} (
                `id_inbox_msg` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_inbox` BIGINT UNSIGNED NOT NULL,
                `tipe` TINYINT NOT NULL COMMENT '1: Diajukan, 2: Keputusan, 3: Perubahan, 4: Tembusan',
                `status_sumber` TINYINT NOT NULL COMMENT 'status baris sumber saat kejadian',
                `isi` VARCHAR(500) NOT NULL,
                `reason_note` VARCHAR(255) NULL DEFAULT NULL COMMENT 'alasan tolak/tangguhkan',
                `push_status` TINYINT NOT NULL DEFAULT 0 COMMENT '0: Antre, 1: Terkirim, 2: Gagal, 3: Tanpa Token',
                `created_at` DATETIME NOT NULL COMMENT 'UTC, diisi aplikasi',
                PRIMARY KEY (`id_inbox_msg`),
                KEY `fk_id_inbox_inboxmsg_to_inbox` (`id_inbox`),
                KEY `push_status_created_at` (`push_status`, `created_at`),
                CONSTRAINT `fk_id_inbox_inboxmsg_to_inbox` FOREIGN KEY (`id_inbox`) REFERENCES {$this->t('inbox')} (`id_inbox`) " . self::RESTRICT . ',
                CONSTRAINT `chk_inboxmsg_tipe` CHECK (`tipe` IN (1, 2, 3, 4)),
                CONSTRAINT `chk_inboxmsg_push_status` CHECK (`push_status` IN (0, 1, 2, 3))
            ) ' . self::TABLE_OPTIONS,
            default => throw new RuntimeException("DBV-022: tabel {$table} tidak dikenal."),
        };
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-022: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql, string $what): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-022: {$what} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
