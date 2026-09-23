<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * G-01/G-03 — Master lokasi presensi (geofencing) + pemetaan pegawai ↔ lokasi (Tier 1 & Tier 3).
 *
 * DDL legacy untuk kedua tabel ini tidak tersedia (Trello ISSUE-003) → kolom mengikuti
 * `simpeg_v2_local_seed.sql` + SRS-FR (radius minimal 10 meter divalidasi di aplikasi, G-03 DoD).
 *
 * FK `user_lokasi_presensi.nip` → `pegawai.nip` DI-DEFER ke Fase 3 (B-01), pola sama dengan `pengguna.nip` (A-01):
 * tabel `pegawai` belum ada. `dm_user_lokasi_presensi` (ada di ERD legacy) belum dibuat — DDL belum tersedia.
 */
class CreateMasterLokasiPresensi extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id_lokasi_presensi' => ['type' => 'VARCHAR', 'constraint' => 5],
            'nama_lokasi'        => ['type' => 'VARCHAR', 'constraint' => 150],
            'latitude'           => ['type' => 'DECIMAL', 'constraint' => '10,7'],
            'longitude'          => ['type' => 'DECIMAL', 'constraint' => '10,7'],
            'radius_meter'       => ['type' => 'INT', 'unsigned' => true, 'comment' => 'minimal 10 meter (validasi aplikasi, G-03)'],
            'order'              => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'             => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_lokasi_presensi');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('lokasi_presensi', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_user_lokasi_presensi' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'nip'                     => ['type' => 'VARCHAR', 'constraint' => 20, 'comment' => 'FK ke pegawai.nip di-defer ke Fase 3 (B-01)'],
            'id_lokasi_presensi'      => ['type' => 'VARCHAR', 'constraint' => 5],
            'status'                  => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_user_lokasi_presensi');
        $this->forge->addUniqueKey(['nip', 'id_lokasi_presensi']);
        $this->forge->addKey('id_lokasi_presensi');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('id_lokasi_presensi', 'lokasi_presensi', 'id_lokasi_presensi', 'RESTRICT', 'RESTRICT', 'fk_user_lokasi_presensi_lokasi');
        $this->forge->createTable('user_lokasi_presensi', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('user_lokasi_presensi', true);
        $this->forge->dropTable('lokasi_presensi', true);
    }
}
