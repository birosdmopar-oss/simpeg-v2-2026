<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02 (kelompok 2): riwayat jabatan `riwayat_mutasi_jabatan` dan penugasan Plt/Plh `pegawai_plt`,
 * `pegawai_plh` (B-07; bukan snapshot) dengan skema SIMPEG legacy. Review DB Validator:
 * backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2.2) — JANGAN dijalankan di Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber (label dokumen B-01/B-02):
 *   - `riwayat_mutasi_jabatan`: stub [L] `simpeg_prod_duplikat` (7 kolom: `id_group_jabatan` INT NULL, `jenis_jabatan`
 *     TINYINT(1) NULL), kolom bisnis = snapshot `pegawai_mutasi_jabatan` [L] (DDL lengkap), penulisan kode [I]
 *     `L_jabatan.php:2085-2319` (`set_param`, `$param = $postData`), upload `ser_dosen`/`f_spmt`/`f_bapel`
 *     (`L_jabatan.php:1113, 1186, 1217`), approval `L_jabatan.php:2428-2448`, sinkron SIASN
 *     `siasn_sync/Rw_jabatan.php:165, 265-332` (`id_rw_siasn`, `siasn_flag`, `siasn_error`, `siasn_lu`); nama FK
 *     [K-erd] `simpeg01.erd`. Titik awal DDL: seed rekonstruksi `simpeg_v2_local_seed_legacy.sql:1422-1510` [I].
 *   - `pegawai_plt` / `pegawai_plh`: kode [I] `L_jabatan.php:4890-4912` (`set_param_off_plt`), `:5389-5411`
 *     (`sp_off_plh`), `lampiran` `:4612-4622, 5111-5121`; UI `views/hr/officer/plt/form.php:24-60` (status 1 Aktif /
 *     2 Tidak Aktif; jenis jabatan 1 Normal / 2 Koordinasi). Nama FK [K-erd]; pemetaan nomor `fk_pegawai_plh_ibfk_04..11`
 *     ke kolom = [I] (urutan kolom `sp_off_plh`).
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - `nip` riwayat NOT NULL (stub [L] DEFAULT NULL); `nip_plt`/`nip_plh` NOT NULL (satu-satunya FK ke `pegawai`).
 *   - Status riwayat TINYINT NOT NULL DEFAULT 0 + CHECK 0/1/2/3/10 (stub [L] VARCHAR(5) DEFAULT '1'); status Plt/Plh
 *     TINYINT NOT NULL DEFAULT 1 + CHECK 1/2/10 (legacy hard delete; 10 disiapkan untuk soft delete B-07).
 *   - Semua FK ON DELETE RESTRICT ON UPDATE RESTRICT (seed/[L]: `nip` CASCADE/CASCADE, master SET NULL/CASCADE).
 *   - `created_at` [I] dan `updated_at` NULL ON UPDATE (stub [L]: `updated_at` NOT NULL tanpa ON UPDATE, tanpa
 *     `created_at`); blok workflow bertipe TINYINT (catatan dokumen B-01/B-02 2.0.3 #3: bukti [K] memakai INT).
 *   - FK ke tabel G-02 (`group_jabatan`, `sub_group_jabatan`, `unit`, `satker`, `jabatan`, `jabatan_koordinasi`,
 *     `rumpun_jabatan`) TIDAK dipasang di sini: KEY bernama FK sudah dibuat, constraint ditambahkan migration
 *     `AddFkG02Riwayat` yang ditahan sampai DBV-008 merge (keranjang d).
 *   - Tidak meniru trigger `d_*`: jejak perubahan lewat `audit_logs`.
 *
 * Kolom audit/workflow diisi aplikasi (waktu UTC, id_pengguna aktor); default DB hanya cadangan. Nilai AUTO_INCREMENT
 * awal tidak ditulis (impor memakai ID legacy apa adanya, karena `document_attachment.id_entri` merujuk ID riwayat).
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateRiwayatJabatan extends Migration
{
    private const STATUS_RIWAYAT_COMMENT = '0: Menunggu, 1: Disetujui, 2: Ditolak, 3: Diproses, 10: Dihapus';

    /**
     * Domain status riwayat (dokumen B-01/B-02 2.0.3): 0/1/2/10 + 3 "Diproses" yang ditulis form admin legacy
     * (`views/hr/employee/rwy/<modul>/form.php:68`, opsi `value="3"`) dan tercatat di COMMENT [K-m] `d_riwayat_cuti`.
     */
    private const STATUS_RIWAYAT_DOMAIN = '0, 1, 2, 3, 10';

    private const STATUS_PENUGASAN_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const SIASN_FLAG_COMMENT = 'sinkron SIASN legacy: 0 belum, 1 sukses, 2 gagal, 3 proses, 4 dilewati';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan down(): kebalikan urutan pembuatan (tidak ada FK di antara ketiga tabel ini).
     */
    private const TABLES = ['pegawai_plh', 'pegawai_plt', 'riwayat_mutasi_jabatan'];

    public function up(): void
    {
        $created = [];

        try {
            foreach ($this->createStatements() as $table => $sql) {
                $this->exec($sql);
                $created[] = $table;
            }
        } catch (Throwable $e) {
            $this->dropCreated($created);

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $this->exec("DROP TABLE IF EXISTS {$this->t($table)}");
        }
    }

    /**
     * CREATE TABLE per tabel.
     *
     * @return array<string, string> tabel => SQL
     */
    private function createStatements(): array
    {
        $sql = [];

        // Kolom bisnis = pegawai_mutasi_jabatan [L] (snapshot disalin dari baris riwayat yang disetujui); urutan kolom
        // awal mengikuti stub [L] riwayat. KEY bernama FK G-02 dibuat sekarang agar AddFkG02Riwayat cukup ADD CONSTRAINT.
        $sql['riwayat_mutasi_jabatan'] = "CREATE TABLE {$this->t('riwayat_mutasi_jabatan')} (
            `id_riwayat_mutasi_jabatan` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `id_group_jabatan` INT NULL DEFAULT NULL,
            `jenis_jabatan` TINYINT(1) NULL DEFAULT NULL COMMENT '1: Nomenklatur, 2: Instansi Lain, 3: Pelaksana Tugas (PLT)',
            `id_sub_group_jabatan` INT NULL DEFAULT NULL,
            `id_unit` INT NULL DEFAULT NULL,
            `id_satker` INT NULL DEFAULT NULL,
            `id_atasan_es_1` INT NULL DEFAULT NULL,
            `id_atasan_es_2` INT NULL DEFAULT NULL,
            `id_atasan_es_3` INT NULL DEFAULT NULL,
            `id_atasan_es_3_koord` INT NULL DEFAULT NULL,
            `id_atasan_es_4` INT NULL DEFAULT NULL,
            `id_atasan_es_4_koord` INT NULL DEFAULT NULL,
            `id_jabatan` INT NULL DEFAULT NULL,
            `id_jabatan_koord` INT NULL DEFAULT NULL,
            `id_gol_pppk` TINYINT NULL DEFAULT NULL,
            `id_rumpun_jabatan` TINYINT NULL DEFAULT NULL,
            `jenis_jabatan_koord` TINYINT(1) NOT NULL DEFAULT 3 COMMENT '1: Koordinator, 2: Subkoordinator, 3: Tidak Ada',
            `jenis_mutasi` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1: Mutasi Jabatan, 2: Mutasi UNOR',
            `tmtsk` DATE NOT NULL,
            `no_sk` VARCHAR(100) NULL DEFAULT NULL,
            `tgl_sk` DATE NULL DEFAULT NULL,
            `pmk` YEAR NULL DEFAULT NULL,
            `mhpk_mulai` DATE NULL DEFAULT NULL,
            `mhpk_akhir` DATE NULL DEFAULT NULL,
            `gol_pppk` VARCHAR(10) NULL DEFAULT NULL,
            `nama_instansi` VARCHAR(255) NULL DEFAULT NULL,
            `group_jabatan` VARCHAR(45) NULL DEFAULT NULL,
            `sub_group_jabatan` VARCHAR(100) NULL DEFAULT NULL,
            `unit` VARCHAR(255) NULL DEFAULT NULL,
            `satker` VARCHAR(255) NULL DEFAULT NULL,
            `atasan_es_1` VARCHAR(255) NULL DEFAULT NULL,
            `atasan_es_2` VARCHAR(255) NULL DEFAULT NULL,
            `atasan_es_3` VARCHAR(255) NULL DEFAULT NULL,
            `atasan_es_3_koord` VARCHAR(255) NULL DEFAULT NULL,
            `atasan_es_4` VARCHAR(255) NULL DEFAULT NULL,
            `atasan_es_4_koord` VARCHAR(255) NULL DEFAULT NULL,
            `jabatan` VARCHAR(255) NULL DEFAULT NULL,
            `jabatan_koord` VARCHAR(255) NULL DEFAULT NULL,
            `jabatan_lain` VARCHAR(255) NULL DEFAULT NULL,
            `rumpun_jabatan` VARCHAR(50) NULL DEFAULT NULL,
            `subrumpun_jabatan` TEXT NULL COMMENT 'nama subrumpun jabatan, dipisah ***',
            `kelas_jabatan` TINYINT NULL DEFAULT NULL,
            `kredit_jft` INT NULL DEFAULT NULL,
            `no_induk_jft` VARCHAR(50) NULL DEFAULT NULL,
            `status_jft` TINYINT(1) NULL DEFAULT NULL COMMENT '1: Aktif, 2: Pembebasan Sementara',
            `ser_dosen` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path berkas sertifikat dosen',
            `f_spmt` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path berkas SPMT',
            `f_bapel` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path berkas BA pelantikan',
            `keterangan` MEDIUMTEXT NULL,
            `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
            `siasn_flag` TINYINT NOT NULL DEFAULT 0 COMMENT '" . self::SIASN_FLAG_COMMENT . "',
            `siasn_error` TEXT NULL,
            `siasn_lu` DATETIME NULL DEFAULT NULL,
            {$this->workflowAuditSql()},
            PRIMARY KEY (`id_riwayat_mutasi_jabatan`),
            KEY `fk_nip_rwymutasijabatan_to_pegawai` (`nip`),
            KEY `riwayat_mutasi_jabatan_ibfk_01` (`id_gol_pppk`),
            KEY `fk_id_group_jabatan_rwymj_to_gj` (`id_group_jabatan`),
            KEY `fk_id_sub_group_jabatan_rwymj_to_sgj` (`id_sub_group_jabatan`),
            KEY `fk_id_unit_rwymj_to_unit` (`id_unit`),
            KEY `fk_id_satker_rwymj_to_satker` (`id_satker`),
            KEY `fk_id_jabatan_rwymj_to_jabatan` (`id_jabatan`),
            KEY `fk_id_atasan_es_1_rwymj_to_jabatan` (`id_atasan_es_1`),
            KEY `fk_id_atasan_es_2_rwymj_to_jabatan` (`id_atasan_es_2`),
            KEY `fk_id_atasan_es_3_rwymj_to_jabatan` (`id_atasan_es_3`),
            KEY `fk_id_atasan_es_4_rwymj_to_jabatan` (`id_atasan_es_4`),
            KEY `fk_id_jabatan_koord_rmj_to_jabkoor` (`id_jabatan_koord`),
            KEY `fk_id_atasan_es_3_koord_rmj_to_jabkoor` (`id_atasan_es_3_koord`),
            KEY `fk_id_atasan_es_4_koord_rmj_to_jabkoor` (`id_atasan_es_4_koord`),
            KEY `riwayat_mutasi_jabatan_ibfk_02` (`id_rumpun_jabatan`),
            CONSTRAINT `fk_nip_rwymutasijabatan_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `riwayat_mutasi_jabatan_ibfk_01` FOREIGN KEY (`id_gol_pppk`)
                REFERENCES {$this->t('gol_pppk')} (`id_gol_pppk`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            {$this->statusCheckSql('riwayat_mutasi_jabatan')}
        ) " . self::TABLE_OPTIONS;

        $sql['pegawai_plt'] = $this->penugasanSql('pegawai_plt', 'plt', [
            'nip'                => 'fk_nip_plt_plt_to_pegawai',
            'id_group_jabatan'   => 'fk_id_group_jabatan_plt_to_gj',
            'id_sub_group'       => 'fk_id_sub_group_jabatan_plt_to_sgj',
            'id_unit'            => 'fk_id_unit_plt_to_unit',
            'id_satker'          => 'fk_id_satker_plt_to_satker',
            'id_jabatan'         => 'fk_id_jabatan_plt_to_jabatan',
            'id_jabatan_koord'   => 'fk_id_jabatan_koord_plt_to_jabkoord',
            'id_group_jabatan_x' => 'fk_id_group_jabatan_plt_plt_to_gj',
            'id_sub_group_x'     => 'fk_id_sub_group_jabatan_plt_plt_to_sgj',
            'id_unit_x'          => 'fk_id_unit_plt_plt_to_unit',
            'id_satker_x'        => 'fk_id_satker_plt_plt_to_satker',
        ]);

        // Pemetaan nomor ibfk -> kolom [I]: 01 nip_plh, 02 jabatan, 03 jabatan_koordinasi, 04..07 posisi yang
        // di-Plh-kan (group, sub group, unit, satker), 08..11 posisi pegawai Plh (kolom *_plh). Diverifikasi DBV lewat
        // SHOW CREATE TABLE produksi (dokumen kelompok 2, keputusan D #6).
        $sql['pegawai_plh'] = $this->penugasanSql('pegawai_plh', 'plh', [
            'nip'                => 'fk_pegawai_plh_ibfk_01',
            'id_jabatan'         => 'fk_pegawai_plh_ibfk_02',
            'id_jabatan_koord'   => 'fk_pegawai_plh_ibfk_03',
            'id_group_jabatan'   => 'fk_pegawai_plh_ibfk_04',
            'id_sub_group'       => 'fk_pegawai_plh_ibfk_05',
            'id_unit'            => 'fk_pegawai_plh_ibfk_06',
            'id_satker'          => 'fk_pegawai_plh_ibfk_07',
            'id_group_jabatan_x' => 'fk_pegawai_plh_ibfk_08',
            'id_sub_group_x'     => 'fk_pegawai_plh_ibfk_09',
            'id_unit_x'          => 'fk_pegawai_plh_ibfk_10',
            'id_satker_x'        => 'fk_pegawai_plh_ibfk_11',
        ]);

        return $sql;
    }

    /**
     * `pegawai_plt` dan `pegawai_plh` berbentuk sama; kolom `*_plt`/`*_plh` = posisi pegawai Plt/Plh itu sendiri,
     * kolom tanpa akhiran = jabatan yang di-Plt/Plh-kan. Hanya FK ke `pegawai` yang dipasang di sini; KEY lain bernama FK
     * G-02 (constraint ditambahkan AddFkG02Riwayat).
     *
     * @param array<string, string> $fk kunci logis => nama FK/KEY legacy
     */
    private function penugasanSql(string $table, string $suffix, array $fk): string
    {
        return "CREATE TABLE {$this->t($table)} (
            `id_{$table}` INT NOT NULL AUTO_INCREMENT,
            `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_PENUGASAN_COMMENT . "',
            `jenis_jabatan` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1: Jabatan Normal, 2: Jabatan Koordinasi',
            `jenis_jabatan_koord` TINYINT(1) NULL DEFAULT NULL COMMENT '1: Koordinator, 2: Subkoordinator; NULL bila jenis_jabatan 1',
            `tgl_mulai` DATE NOT NULL,
            `tgl_akhir` DATE NOT NULL,
            `id_group_jabatan` INT NULL DEFAULT NULL,
            `id_sub_group_jabatan` INT NULL DEFAULT NULL,
            `id_unit` INT NULL DEFAULT NULL,
            `id_satker` INT NULL DEFAULT NULL,
            `id_jabatan` INT NULL DEFAULT NULL,
            `id_jabatan_koord` INT NULL DEFAULT NULL,
            `id_group_jabatan_{$suffix}` INT NULL DEFAULT NULL,
            `id_sub_group_jabatan_{$suffix}` INT NULL DEFAULT NULL,
            `id_unit_{$suffix}` INT NULL DEFAULT NULL,
            `id_satker_{$suffix}` INT NULL DEFAULT NULL,
            `nip_{$suffix}` VARCHAR(30) NOT NULL,
            `lampiran` VARCHAR(255) NULL DEFAULT NULL COMMENT 'path berkas lampiran (legacy arsip/off_{$suffix}/)',
            {$this->auditSql()},
            PRIMARY KEY (`id_{$table}`),
            KEY `{$fk['nip']}` (`nip_{$suffix}`),
            KEY `{$fk['id_group_jabatan']}` (`id_group_jabatan`),
            KEY `{$fk['id_sub_group']}` (`id_sub_group_jabatan`),
            KEY `{$fk['id_unit']}` (`id_unit`),
            KEY `{$fk['id_satker']}` (`id_satker`),
            KEY `{$fk['id_jabatan']}` (`id_jabatan`),
            KEY `{$fk['id_jabatan_koord']}` (`id_jabatan_koord`),
            KEY `{$fk['id_group_jabatan_x']}` (`id_group_jabatan_{$suffix}`),
            KEY `{$fk['id_sub_group_x']}` (`id_sub_group_jabatan_{$suffix}`),
            KEY `{$fk['id_unit_x']}` (`id_unit_{$suffix}`),
            KEY `{$fk['id_satker_x']}` (`id_satker_{$suffix}`),
            CONSTRAINT `{$fk['nip']}` FOREIGN KEY (`nip_{$suffix}`)
                REFERENCES {$this->t('pegawai')} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `chk_{$table}_status` CHECK (`status` IN (1, 2, 10))
        ) " . self::TABLE_OPTIONS;
    }

    /**
     * Status + blok workflow standar + audit riwayat (konvensi B-02 2.0.3/2.0.4). Tipe TINYINT untuk `show_notif`/
     * `show_ua_*` = stub [L] (`show_notif tinyint NOT NULL DEFAULT '0'`); bukti [K] `absen_ijin` memakai INT (keputusan
     * dokumen B-01/B-02 #3).
     */
    private function workflowAuditSql(): string
    {
        return "`status` TINYINT NOT NULL DEFAULT 0 COMMENT '" . self::STATUS_RIWAYAT_COMMENT . "',
            `reason_note` TEXT NULL,
            `approved_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang menyetujui/menolak',
            `show_notif` TINYINT NOT NULL DEFAULT 0 COMMENT 'penanda notifikasi pegawai (nilai legacy), diisi aplikasi',
            `notif_date` DATETIME NULL DEFAULT NULL,
            `show_ua_upt` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin UPT (satker)',
            `show_ua_deputi` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin deputi (unit)',
            `show_ua_biro` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin biro',
            " . $this->auditSql();
    }

    /**
     * Audit riwayat [I] (pola `absen_ijin`/`d_lkh`/`d_riwayat_cuti`): created_at NOT NULL, updated_at NULL ON UPDATE,
     * updated_by = id_pengguna aktor tanpa FK (preseden DBV-001/010).
     */
    private function auditSql(): string
    {
        return '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . '`updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
            . "`updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah'";
    }

    private function statusCheckSql(string $table): string
    {
        return "CONSTRAINT `chk_{$table}_status` CHECK (`status` IN (" . self::STATUS_RIWAYAT_DOMAIN . '))';
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik. Kegagalan drop tidak
     * menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (dokumen B-02 6.6).
     *
     * @param list<string> $created
     */
    private function dropCreated(array $created): void
    {
        foreach (array_reverse($created) as $table) {
            try {
                $this->db->query("DROP TABLE IF EXISTS {$this->t($table)}");
            } catch (Throwable) {
                // Lanjut ke tabel berikutnya; error asli tetap dilempar oleh up().
            }
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

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-013: DDL riwayat jabatan gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
