<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02 (kelompok 4): lookup `jenis_rwy` [V2] + seed 21 baris. Tabel ini mengganti konstanta PHP legacy yang
 * menjadi kode jenis lampiran `document_attachment.id_riwayat` (DoD B-02: mapping jenis_rwy sebagai lookup eksplisit,
 * bukan hard-code). Review DB Validator:
 * backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2.4) — JANGAN dijalankan di Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber:
 *   - Kode (PK) dan kunci `kode` = `ARSIP_RWY` legacy (`config/constants.php:193-214`) [I]; label `jenis_rwy` dan
 *     urutan `order` = `JENIS_RWY_ARSIP` (`config/constants.php:215-238`, urutan elemen array) [I].
 *   - `tabel_entri` = tabel yang ditunjuk `document_attachment.id_entri` untuk kode itu: `previewRwy()`
 *     (`helpers/function_helper.php:2260-2383`) dan titik tulis lampiran per modul (`L_keluarga.php:322-366`,
 *     `L_pmk.php:106-122`, `L_jabatan.php:938-941`, `controllers/hr/rwy/Pendidikan.php:566, 618`) [I]. 37/38
 *     (arsip data umum) ditulis dengan `id_entri` NULL (`L_employee.php:289-304, 497-512`) → `tabel_entri` NULL.
 *   - Baris 0 "Belum Terhubung" [V2]: nilai legacy `id_riwayat = 0` = arsip yang belum ditautkan ke riwayat
 *     (`views/hr/employee/arsip/list.php:77-125`, `L_arsip.php:17`); tanpa baris ini FK dari `document_attachment`
 *     menolak data legacy tersebut. `order` 0 [V2] = di luar urutan tampil (preseden sentinel DBV-003).
 *   - Kolom `order`/`status`/`updated_at`/`updated_by` meniru master di `main` (pola `diklat` [K], G-06).
 *
 * BUKAN kode `skl.jenis_rwy` (1 Cuti, 2 Kariskarsu, 3 Karpeg, 4 TB, 5 IB; `function_helper.php:241`,
 * `controllers/hr/Announcement.php:677`) — kode survei layanan itu milik Tier 7 dan tidak boleh dicampur ke lookup ini.
 *
 * Deviasi / keputusan [V2] (dicatat untuk DBV):
 *   - Tabel baru (legacy hanya konstanta PHP); PK = kode legacy apa adanya, TANPA AUTO_INCREMENT, tipe INT = tipe
 *     `document_attachment.id_riwayat` [K].
 *   - UNIQUE `kode` dan `jenis_rwy`; CHECK status 1/2/10.
 *   - Tidak didaftarkan ke engine Master Data (tanpa UI): perubahan isi hanya lewat migration baru. Kode aplikasi
 *     (B-18, arsip Fase 7) membaca lookup ini dan tidak meng-hard-code angka.
 *   - `tabel_entri` 42 = `riwayat_pmk` (tabel Fase 4 C-01, belum ada) — hanya nama, bukan FK.
 *
 * Seed ditulis literal (tidak dibaca dari config aplikasi) agar migration tidak berubah bila kode aplikasi berubah.
 * DDL MySQL/MariaDB ter-commit per statement: bila CREATE atau INSERT seed gagal, up() men-drop tabel yang sempat dibuat
 * PADA RUN ITU lalu melempar ulang error, sehingga `php spark migrate` bisa langsung diulang. INSERT seed adalah satu
 * statement multi-baris (atomik per statement di InnoDB). down() men-drop tabel; selama `document_attachment` masih
 * merujuknya (FK `fk_id_riwayat_da_to_jenis_rwy`, migration 130900) DROP ditolak (3730) — rollback batch menjalankan
 * 130900 lebih dulu.
 */
class CreateJenisRwy extends Migration
{
    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const TABLE = 'jenis_rwy';

    /**
     * Seed: id_jenis_rwy => [kode, jenis_rwy, tabel_entri, order]. Urutan baris = urutan INSERT (tidak bermakna);
     * urutan tampil = kolom `order` (= urutan JENIS_RWY_ARSIP legacy).
     *
     * @var array<int, array{0: string, 1: string, 2: string|null, 3: int}>
     */
    public const ROWS = [
        0  => ['belum_terhubung', 'Belum Terhubung', null, 0],
        1  => ['alamat', 'Alamat', 'riwayat_alamat', 1],
        3  => ['anak', 'Anak', 'detail_anak', 2],
        5  => ['diklat', 'Pelatihan', 'riwayat_diklat', 4],
        7  => ['hukdis', 'Hukuman Disiplin', 'riwayat_hukdis', 5],
        9  => ['jabatan', 'Jabatan', 'riwayat_mutasi_jabatan', 6],
        10 => ['kgb', 'KGB', 'riwayat_kgb', 9],
        11 => ['kp', 'Kenaikan Pangkat', 'riwayat_kp', 8],
        13 => ['organisasi', 'Organisasi', 'riwayat_organisasi', 11],
        14 => ['pendidikan', 'Pendidikan', 'riwayat_pendidikan', 13],
        20 => ['keluarga', 'Keluarga', 'riwayat_keluarga', 7],
        22 => ['seminar', 'Kursus / Seminar', 'riwayat_seminar', 10],
        23 => ['tj', 'Tanda Jasa', 'riwayat_tanda_jasa', 16],
        32 => ['skp', 'Sasaran Kerja', 'riwayat_skp', 14],
        36 => ['jabatan_pjft', 'Pemberhentian Sementara JFT', 'riwayat_mutasi_jabatan', 12],
        37 => ['status_du', 'Status Data Umum', null, 15],
        38 => ['arsip_du', 'Data Umum', null, 3],
        39 => ['pencantuman_gelar', 'Arsip Pencantuman Gelar', 'riwayat_pendidikan', 17],
        40 => ['transkrip_nilai', 'Transkrip Nilai', 'riwayat_pendidikan', 18],
        41 => ['perjanjian_kerja', 'Perjanjian Kerja', 'riwayat_mutasi_jabatan', 19],
        42 => ['pmk', 'Penyesuaian Masa Kerja', 'riwayat_pmk', 20],
    ];

    public function up(): void
    {
        $this->exec($this->createSql(), [], 'CREATE TABLE jenis_rwy');

        try {
            $this->exec($this->seedSql(), $this->seedBinds(), 'seed jenis_rwy');
        } catch (Throwable $e) {
            $this->dropCreated();

            throw $e;
        }
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS {$this->t(self::TABLE)}", [], 'DROP TABLE jenis_rwy');
    }

    private function createSql(): string
    {
        return "CREATE TABLE {$this->t(self::TABLE)} (
            `id_jenis_rwy` INT NOT NULL COMMENT 'kode jenis arsip legacy (ARSIP_RWY) = document_attachment.id_riwayat; bukan AUTO_INCREMENT',
            `kode` VARCHAR(30) NOT NULL COMMENT 'kunci ARSIP_RWY legacy',
            `jenis_rwy` VARCHAR(100) NOT NULL COMMENT 'label JENIS_RWY_ARSIP legacy',
            `tabel_entri` VARCHAR(64) NULL DEFAULT NULL COMMENT 'tabel yang dirujuk document_attachment.id_entri; NULL = tanpa entri',
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_COMMENT . "',
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
            PRIMARY KEY (`id_jenis_rwy`),
            UNIQUE KEY `uq_jenis_rwy_kode` (`kode`),
            UNIQUE KEY `uq_jenis_rwy_nama` (`jenis_rwy`),
            CONSTRAINT `chk_jenis_rwy_status` CHECK (`status` IN (1, 2, 10))
        ) " . self::TABLE_OPTIONS;
    }

    /**
     * Satu INSERT multi-baris; `updated_at` = UTC (konvensi kolom audit diisi aplikasi dalam UTC), `updated_by` NULL
     * (ditulis migration, bukan pengguna).
     */
    private function seedSql(): string
    {
        $values = implode(', ', array_fill(0, count(self::ROWS), '(?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())'));

        return "INSERT INTO {$this->t(self::TABLE)} (`id_jenis_rwy`, `kode`, `jenis_rwy`, `tabel_entri`, `order`, `status`, `updated_at`) VALUES {$values}";
    }

    /**
     * @return list<int|string|null>
     */
    private function seedBinds(): array
    {
        $binds = [];

        foreach (self::ROWS as $id => [$kode, $nama, $tabelEntri, $order]) {
            array_push($binds, $id, $kode, $nama, $tabelEntri, $order);
        }

        return $binds;
    }

    /**
     * Pembersihan setelah seed gagal: drop tabel yang dibuat pada run ini. Kegagalan drop tidak menutupi error asli
     * (yang dilempar ulang up()) — sisa tabel lalu dibersihkan manual (dokumen B-02 Bagian 6.6).
     */
    private function dropCreated(): void
    {
        try {
            $this->db->query("DROP TABLE IF EXISTS {$this->t(self::TABLE)}");
        } catch (Throwable) {
            // Error asli tetap dilempar oleh up().
        }
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-013: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    /**
     * @param list<int|string|null> $binds
     */
    private function exec(string $sql, array $binds, string $what): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL/seed gagal diam-diam.
        $result = $binds === [] ? $this->db->query($sql) : $this->db->query($sql, $binds);

        if ($result === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-013: {$what} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
