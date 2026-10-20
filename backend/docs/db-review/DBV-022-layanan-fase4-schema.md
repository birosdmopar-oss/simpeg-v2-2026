# DB Validator Review — DBV-022 skema Modul C Layanan Fase 4 + pemisahan `inbox` (notifikasi) vs `disiplin_ticket` (ticketing)

**Key review:** `DBV-022`. Judul PR yang direncanakan: `[DBV-022] Skema Modul C Fase 4: layanan, notifikasi inbox, ticketing
disiplin_ticket`. Branch `fase4/dbv-022-skema-layanan` (dari `main` `6806c38`). Alur: `[DBV]` (+`[MAKE]` bila ada kode) → PR →
DB Validator review/approve → reviewer CR merge (AGENTS.md §2, PRD §6.1.4).

**Status:** 📋 **PRA-REVIEW INTERNAL SISI CR — MENUNGGU REVIEW DB VALIDATOR.** Dokumen ini **bukan** hasil review DB Validator
dan **belum** disetujui siapa pun. Seluruh 21 butir tabel sudah berisi DDL D1 + usulan v2 + deviasi (Bagian 2) sejak D1
tersedia 20-10-2026. Yang masih kosong: **hasil** kueri audit data (Bagian 6 — perlu akses salinan dump) dan draf migration
(ditahan sampai 14 butir keputusan Bagian 7 dijawab, agar tidak ditulis dua kali). Isi dokumen diturunkan dari
`docs/fase4/PRD_Fase4.md` v1.0 (18-10-2026) + D1; tidak ada tipe kolom atau nama constraint yang dikarang.

**Asal kebutuhan:** PRD Fase 4 §6.1 (C-01), keputusan user **D18** (satu unit review untuk seluruh tabel Modul C) dan **D10**
(pemisahan notifikasi vs ticketing). Prinsip #4 Mapping Migrasi yang menyebut `inbox` dipakai untuk notifikasi dikoreksi di sini
(lihat Bagian 3); revisi dokumen Mapping sendiri = Q-19, di luar coder.

**Rujukan:** `B-01-B-02-pegawai-riwayat-schema.md` (DBV-012/013: konvensi K1, G2, V1–V5, aturan snapshot);
`DBV-019-fk-g02-pegawai-schema.md` (pola `app/Database/MigrationsDitahan/` + pra-cek yatim fail-closed);
`DBV-020-skema-tambahan-fase3.md` (pola unit skema gabungan, `down()` fail-closed);
`DBV-011-runbook-impor-master-zona-waktu.md` (runbook impor tunggal, klasifikasi WAJIB_0/INFO).
**D1** = dump struktur produksi `simpeg01` 01-10-2026 (`simpeg01_struktur_lengkap_20261001.sql`, **di luar repo**).
Kode legacy = folder aplikasi SIMPEG legacy (`application/…`, **di luar repo**; rujukan `berkas:baris`).

**Label sumber:** **[K]** terkonfirmasi DDL D1 / kode legacy · **[V2]** keputusan v2 yang disengaja (alasan ditulis)
· **[I]** inferensi dari kode bila DDL tidak eksplisit (wajib disebut).

---

## 0. Ringkasan

Satu unit review untuk **21 butir** (PRD §6.1.1, D18): 9 tabel layanan, 4 tabel notifikasi/ticketing, 5 master pendukung,
`esign_hist`, `firebase_token`, `question_category`, dan penambahan kode `jenis_rwy`.

| # | Kelompok | Tabel / butir | Sifat |
|---|---|---|---|
| A | Cuti | `riwayat_cuti`, `riwayat_cuti_notif_kt`, `pegawai_sisa_cuti`, `jenis_cuti` | CREATE [K] D1 + deviasi |
| B | TB / IB | `riwayat_tb`, `riwayat_tb_perpan`, `riwayat_ib` | CREATE [K] D1 + deviasi |
| C | PMK | `riwayat_pmk`, `riwayat_pmk_req` | CREATE [K] D1 + deviasi |
| D | **Notifikasi (baru)** | `inbox` v2, `inbox_msg` v2 | CREATE **[V2] desain baru** — satu arah |
| E | **Ticketing (Fase 8)** | `disiplin_ticket`, `disiplin_ticket_msg`, `question_category` | CREATE [K] rename dari `inbox`/`inbox_msg` legacy |
| F | Petugas / aturan approval | `jenis_layanan`, `petugas_layanan`, `pa_layanan`, `jenis_pa` | CREATE [K] D1 + deviasi |
| G | Integrasi (dimajukan) | `esign_hist`, `firebase_token` | CREATE [K] D1 + deviasi |
| H | Kode | `jenis_rwy` kode baru 43..49 (usulan) | Migration **baru** (bukan edit seed lama) |

**Inti keputusan yang diminta:** Bagian 3 (pemisahan `inbox` vs `disiplin_ticket`) dan Bagian 7 (DQ-3, DQ-4, DQ-6, DQ-7, DQ-9,
DQ-10, DQ-12, DQ-16, DQ-17, DQ-18, DQ-20, DQ-21, nomor `jenis_rwy`) = PRD §12 **Q-20**.

**Tabel yang TIDAK dibuat** (PRD §6.1.1): `d_riwayat_cuti` (arsip trigger — diganti `audit_logs`), seluruh cadangan bertanggal
(`riwayat_cuti_2020…`, `riwayat_tb_20201204`, `riwayat_ib_20201213`, `pa_layanan_20211122`, `petugas_approval_20211122`,
`inbox_msg_20251027`, `pegawai_sisa_cuti_20xx`), `layanan`/`layanan_pegawai`/`riwayat_layanan`, antrean push legacy
(`fb_pn_queue`, `andr_notif_queue`, `andr_topic_queue`, `firebase_message_logs`), `fb_token`, `email_notification`,
`support_officer`/`officer_category`/`help_desk_contact` (Fase 8).

---

## 1. Konvensi yang dipakai

Diambil dari DBV-012/013 Bagian 1 dan PRD §2.6; berlaku ke seluruh tabel unit ini.

| Kode | Konvensi | Akibat di DBV-022 |
|---|---|---|
| K1 | PK `pegawai` = `nip` VARCHAR(30) `utf8mb4_unicode_ci`; ganti NIP lewat B-06 (`NipCascadeService`), bukan cascade DB | Semua FK ke `pegawai` RESTRICT; semua kolom NIP ber-FK VARCHAR(30) (V4); registry NIP Bagian 4 |
| G2 | Collation tabel `utf8mb4_unicode_ci` (tidak ditulis per kolom); `AUTO_INCREMENT=<n>` dan `ROW_FORMAT` tidak ditulis; kolom audit diisi aplikasi (UTC, `id_pengguna`) | Tabel D1 bercollation `utf8mb4_0900_ai_ci` (`inbox`, `inbox_msg`, `pa_layanan`, `question_category`, `firebase_token`) dikonversi; counter AUTO_INCREMENT disalin saat impor (Bagian 5) |
| V1 | Aksi FK D1 `CASCADE`/`SET NULL` → **RESTRICT/RESTRICT**, nama FK legacy dipertahankan | Termasuk `riwayat_tb_perpan` → `riwayat_tb` (D1 CASCADE): hapus anak dikerjakan service dalam transaksi yang sama (PRD §6.7.4) |
| V2 | CHECK domain status/flag ditambahkan | Daftar CHECK per tabel di Bagian 2; nilai di luar domain dinormalkan saat impor setelah audit Bagian 6 |
| V3 | Trigger legacy **tidak dibawa**; logikanya pindah ke service | `riwayat_cuti_beDel` (arsip) → `audit_logs` `delete` dengan `before` (D6); `riwayat_cuti_beUpd`, `riwayat_tb`/`riwayat_ib` `beUpd` (`show_notif`) → `NotifikasiService` (PRD §6.6.2); `riwayat_tb_perpan_af*` (ringkasan MAX tanggal ke induk) → `PerpanjanganTbService`; `inbox_msg_afIns` → Fase 8 (dicatat, tidak dibuat) |
| V4 | Kolom NIP ber-FK VARCHAR(50)/(18) → **VARCHAR(30)** | `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui` (50), `petugas_layanan.nip` (50), `pa_layanan.nip` (50), `disiplin_ticket.nip` (18) — diaudit `MAX(CHAR_LENGTH(...))` (Bagian 6) |
| V5 | Kolom FK ke master Fase 2/3 disamakan tipenya dengan PK master v2 | `riwayat_tb.id_jenjang_pendidikan`, `riwayat_ib.id_jenjang_pendidikan` TINYINT → INT (PK `jenjang_pendidikan` v2 INT) |
| Status | Master 1/2/10; status riwayat/layanan **ikut produksi**: cuti 0/1/2/3/4, TB/IB 0/1/2/3, PMK req 0/1/2/3 | Cuti **tanpa** 10 (hapus fisik, D6) |
| K3 | Migration yang sudah ada di `main` **tidak diedit**; koreksi lewat migration baru | Kode `jenis_rwy` baru = migration terpisah, bukan edit `2026-09-30-130000_CreateJenisRwy.php` |
| MigrationsDitahan | FK yang datanya bisa yatim ditaruh di `app/Database/MigrationsDitahan/` dengan pra-cek yatim **fail-closed** (pola DBV-019/DBV-020) | Usulan untuk FK approver `riwayat_cuti` (DQ-3) dan FK NIP tabel yang NIP-nya tanpa FK di legacy |

---

## 2. DDL per tabel

Konvensi penulisan bagian ini = `B-01-B-02-pegawai-riwayat-schema.md`: **kolom = D1 apa adanya** kecuali yang terdaftar di
"Deviasi"; collation per kolom tidak ditulis (G2), `AUTO_INCREMENT=<n>` dan `ROW_FORMAT` tidak ditulis (G2, counter disalin
saat impor). Nilai AUTO_INCREMENT D1 dicatat sebagai perkiraan volume data.

Verifikasi sumber: seluruh nomor baris di bawah dicocokkan ke D1 (`esign_hist` 891, `inbox` 1369, `jenis_cuti` 1634,
`riwayat_cuti` 4396 — cocok dengan rujukan PRD §6.1.1).

### 2.1 `riwayat_cuti` — D1:4396-4463 (AI=22565)

56 kolom. Struktur: PK `id_riwayat_cuti` int AI; `nip` **varchar(30)** NOT NULL (sudah selebar PK `pegawai.nip` — tidak perlu
V4); snapshot pemohon (`nama` varchar(70), `id_sub_group_jabatan` int, `pangkat` varchar(70), `gol_ruang` varchar(45),
`jabatan` varchar(200), `satker` varchar(100), `mker_th`/`mker_bl` int DEFAULT 0); `id_jenis_cuti` int NOT NULL,
`nama_jenis_cuti` varchar(100) NOT NULL; `tgl_mulai`/`tgl_akhir` date NOT NULL; `lama_cuti` int NOT NULL;
`satuan_lama_cuti` varchar(50) DEFAULT 'Hari'; `no_telp_saat_cuti` varchar(50), `alamat_saat_cuti` text,
`file_bukti` varchar(250); snapshot saldo `sisa_cuti`/`sisa_cuti_n1`/`sisa_cuti_n2`/`utang_cuti` int DEFAULT 0;
`keterangan` text; AL (`nip_atasan_langsung` varchar(50), `nama_atasan_langsung` varchar(100),
`jabatan_atasan_langsung` varchar(200), `id_jabatan_al` int, `status_al` tinyint DEFAULT 0, `reason_note_al` mediumtext);
PyB (`nip_yang_menyetujui` varchar(50), `nama_yang_menyetujui` varchar(100), `jabatan_yang_menyetujui` varchar(200),
`id_jabatan_pyb` int, `status_pyb` tinyint DEFAULT 0, `reason_note_pyb` mediumtext); berkas surat
(`file_sc_unsign`/`file_sc_signed_bef`/`file_surat_cuti` varchar(256), masing-masing ber-COMMENT);
`keterangan_petugas_approval` text; `ketua_tim` text; `status` int NOT NULL DEFAULT 0;
`status_regen` tinyint NOT NULL DEFAULT 0; audit (`created_at`, `updated_at` ON UPDATE, `updated_by` int DEFAULT 0,
`approved_by` int); `reason_note` text; `rated` tinyint NOT NULL DEFAULT 2; `skip_al`/`skip_pyb` tinyint NOT NULL DEFAULT 0;
`show_notif` int, `notif_date` datetime, `show_atasan`/`show_pejabat` int DEFAULT 0, `show_history_atasan` int DEFAULT 1.

**COMMENT domain D1 (dikutip apa adanya — menjawab DQ-1 dan butir [I] PRD §6.4.2 T4):**
- `status`: `0: Pengajuan, 1: Disetujui, 2: Ditolak, 3: Diproses / Disetujui AL, 4: Ditangguhkan`
- `status_al`: `0: Pengajuan, 1: Disetujui, 2: Ditolak, 4: Ditangguhkan`
- `status_pyb`: `0: Pengajuan, 1: Disetujui, 2: Ditolak, 3: Disetujui AL - Waiting PYB, 4: Ditangguhkan`
- `status_regen`: `0: Normal State, 1: Process State` · `rated`: `0: Ignore, 1: Sudah, 2: Belum` · `skip_*`: `0: No, 1: Yes`

> **Temuan yang menutup butir [I] PRD:** arti `status_pyb = 3` ("Disetujui AL - Waiting PYB") **terkonfirmasi [K]** di COMMENT
> D1:4431, bukan lagi inferensi. PRD §6.4.2 T4 meminta ini diverifikasi dan dicatat di laporan M3 — sudah terjawab di sini.

**Index D1:** `id_jenis_cuti_idx` (`id_jenis_cuti`), `nip_idx` (`nip`), `jabatan_atasan_langsung`, `nip_atasan_langsung`,
`nip_yang_menyetujui`, `jabatan_yang_menyetujui`, `nip_status_rated_notif_date` (`nip`,`status`,`rated`,`notif_date`).

**FK D1:** `fk_nip_rwycuti_to_pegawai` (`nip`→`pegawai.nip`) ON DELETE CASCADE ON UPDATE CASCADE; `id_jenis_cuti`
(`id_jenis_cuti`→`jenis_cuti.id_jenis_cuti`) ON UPDATE CASCADE (tanpa klausa ON DELETE → RESTRICT implisit).

| # | Deviasi | D1 | v2 | Alasan |
|---|---|---|---|---|
| 1 | Aksi FK | CASCADE/CASCADE dan (implisit RESTRICT)/CASCADE | **RESTRICT/RESTRICT** keduanya | V1; ganti NIP lewat B-06 (K1), bukan cascade DB |
| 2 | Nama FK `id_jenis_cuti` | nama constraint = nama kolom | **usul**: dipertahankan [K] agar diff impor nol; alternatif rename `fk_id_jenis_cuti_rwycuti_to_jeniscuti` | DQ-22 (baru, Bagian 7) |
| 3 | `nip_atasan_langsung`, `nip_yang_menyetujui` | varchar(50), tanpa FK | **varchar(30)** + FK nullable ke `pegawai.nip` (`fk_nip_atasan_langsung_rwycuti_to_pegawai`, `fk_nip_yang_menyetujui_rwycuti_to_pegawai`), **DITAHAN** di `MigrationsDitahan/` dengan pra-cek yatim fail-closed | V4 + DoD 064-4 (AC-01.4); yatim legacy belum diketahui (audit Bagian 6.1) |
| 4 | CHECK domain | tidak ada | `status` IN (0,1,2,3,4); `status_al` IN (0,1,2,4) NULL boleh; `status_pyb` IN (0,1,2,3,4) NULL boleh; `status_regen` IN (0,1); `rated` IN (0,1,2); `skip_al`/`skip_pyb` IN (0,1) | V2; **tanpa 10** untuk `status` karena hapus cuti = fisik (D6) |
| 5 | Trigger `riwayat_cuti_beDel` (D1:8830-8834) | `INSERT INTO d_riwayat_cuti SELECT *` | **tidak dibawa**; `d_riwayat_cuti` tidak dibuat. Diganti `audit_logs` event `delete` dengan `before` lengkap | V3 + D6 (PRD §6.2.6 butir 7) |
| 6 | Trigger `riwayat_cuti_beUpd` (D1:8838-8845) | `IF NEW.status IN (1,2,3,4) AND OLD.status IN (0,3) THEN show_notif=1; notif_date=NOW()` | **tidak dibawa**; kondisi identik ditegakkan `NotifikasiService` saat menulis kolom kompatibilitas | V3 (PRD §6.6.2) |
| 7 | Index approval | — | tambah `(nip_atasan_langsung, status)`, `(nip_yang_menyetujui, status)` | DQ-16; dipakai `mode=persetujuan` dan `GET cuti/pengingat` |
| 8 | Kolom `file_*`, `show_*` | dipakai aplikasi legacy | dipertahankan [K] untuk data impor; berkas v2 di `document_attachment` (D13), `show_*` hanya kompatibilitas | PRD §6.1.1 #1 |

**Risiko impor:** nilai `status_al`/`status_pyb` di luar domain (audit Bagian 2 skrip SQL) harus dinormalkan sebelum CHECK
dipasang; `ketua_tim` berisi NIP ganda dipisah `***` → registry Bagian 4 + audit Bagian 9 skrip.

### 2.2 `riwayat_cuti_notif_kt` — D1:4699-4709

PK komposit (`id_riwayat_cuti`, `nip_kt`); `nip_kt` **varchar(30)** NOT NULL; `status` tinyint NOT NULL DEFAULT 0
COMMENT `0: Belum Dibaca, 1: Sudah Dibaca, 2: Delete`; `created_at` NOT NULL DEFAULT CURRENT_TIMESTAMP;
`updated_at` datetime NULL ON UPDATE. Index `fk_nip_kt_rcnkt_2` (`nip_kt`).
FK D1: `fk_id_riwayat_cuti_rcnkt_1` → `riwayat_cuti` CASCADE/CASCADE; `fk_nip_kt_rcnkt_2` → `pegawai` CASCADE/CASCADE.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Aksi FK | RESTRICT/RESTRICT | V1. Konsekuensi: hapus cuti harus menghapus baris tembusan **eksplisit** lebih dulu (PRD §6.2.6 butir 5) |
| 2 | CHECK | `status` IN (0,1,2) | V2 |

Registry: `nip_kt` = kolom NIP ber-FK; status `BelumDiputuskan` di `TabelAnakNip.php:83` ditutup (AC-01.7).

### 2.3 `jenis_cuti` — D1:1634-1643 (AI=7 → id terpakai 1..6)

`id_jenis_cuti` int AI; `nama_jenis_cuti` varchar(105) NOT NULL; `order` int NOT NULL DEFAULT 0; `status` tinyint NOT NULL
DEFAULT 1; `created_at` NOT NULL; `updated_at` NULL ON UPDATE; `updated_by` int DEFAULT 0.
**PK D1 = komposit (`id_jenis_cuti`, `nama_jenis_cuti`)**; tanpa FK keluar.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | PK | **PK tunggal `id_jenis_cuti`** + UNIQUE `nama_jenis_cuti` | DQ-9. Aman: FK `riwayat_cuti.id_jenis_cuti` di D1 menunjuk kolom terdepan PK komposit, jadi tetap sah setelah PK disederhanakan |
| 2 | CHECK | `status` IN (1,2,10) | V2 (domain master) |
| 3 | Seed | 6 baris literal di migration (pola `CreateJenisRwy`) dengan **id 1..6 dipaksa** | Tukin v2 meng-hard-code `StatusHarian::JENIS_CUTI`; AI=7 di D1 membuktikan hanya 1..6 yang terpakai. Fase 4 tidak membuat UI master `jenis_cuti` |

### 2.4 `pegawai_sisa_cuti` — D1:3576-3592 (AI=20580)

PK `id_pegawai_sisa_cuti` int AI; `nip` varchar(30) NOT NULL; `tahun` **year** NOT NULL; `sisa_cuti`, `sisa_cuti_n1`,
`sisa_cuti_n2`, `sisa_cuti_ditangguhkan`, `utang_cuti` **tinyint** DEFAULT 0; `digunakan` tinyint DEFAULT 0
COMMENT `0: Tidak Digunakak, 1: Digunakan` (typo legacy pada COMMENT); audit `created_at`, `updated_at` ON UPDATE,
`updated_by`. Index `nip_tahun` (`nip`,`tahun`) — **bukan** UNIQUE. FK `fk_nip_psc_to_pegawai` CASCADE/CASCADE.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Aksi FK | RESTRICT/RESTRICT | V1 |
| 2 | `nip_tahun` | **UNIQUE (`nip`,`tahun`)** bila audit duplikat = 0 (Bagian 6 butir 3) | DQ-10; satu baris saldo per pegawai per tahun adalah asumsi `KuotaCutiService` (PRD §6.3.3) |
| 3 | CHECK | `digunakan` IN (0,1); kelima kolom saldo `>= 0` | V2. Batas atas D8 (12/6/6/12) **tidak** dijadikan CHECK — data impor bisa melanggar; ditegakkan aplikasi (PRD §6.3.6) |
| 4 | COMMENT typo | diperbaiki `0: Tidak Digunakan, 1: Digunakan` | [V2] kosmetik |

Cadangan `pegawai_sisa_cuti_20241128` dkk. tidak dibuat/diimpor.

### 2.5 `riwayat_tb` — D1:6544-6604 (AI=187)

55 kolom. PK `id_riwayat_tb` int AI; `nip` varchar(30) NOT NULL; `id_jenjang_pendidikan` **tinyint** NULL;
snapshot (`nama` varchar(100), `gol_ruang` varchar(45), `pangkat` varchar(100), `jabatan` varchar(100),
`unit` varchar(150), `satker` varchar(150), `pendidikan_dasar_singkat` varchar(50)); `tgl_mulai`/`tgl_akhir` date NOT NULL;
`tgl_mulai_perpan`/`tgl_akhir_perpan` date NULL; `lama_tahun` decimal(10,2) NOT NULL DEFAULT 0.00;
`jenjang_pendidikan_singkat` varchar(45) NOT NULL; `institusi_pendidikan`/`jurusan_pendidikan` varchar(255) NOT NULL;
`dalam_negeri` int NOT NULL; `lokasi_pendidikan` varchar(100) NOT NULL; `lembaga_yang_mendanai` varchar(255) NOT NULL;
`up`/`tembusan` varchar(255) NULL; **`file_sp` varchar(255) NOT NULL**, **`file_1`/`file_2`/`file_3` varchar(225) NOT NULL**
(ber-COMMENT: Surat Keterangan Kampus / BAN PT / Pernyataan Bermaterai); `keterangan` mediumtext;
alur admin legacy (`status_admin_upt` tinyint, `rs_admin_upt` tinytext, `status_admin_deputi` tinyint,
`sp_admin_deputi` varchar(255), `rs_admin_deputi` tinytext); SK (`ds_tb` varchar(255), `no_ds_tb` varchar(100),
`tgl_ds_tb` date, `perihal_ds_tb` varchar(255), `no_sk` varchar(50), `tgl_sk` date, `file_surat_tb` varchar(225));
`status` int NOT NULL DEFAULT 0 COMMENT `0: Pengajuan, 1: Disetujui, 2: Ditolak, 3: Diproses`; audit; `reason_note` mediumtext;
`rated` tinyint NOT NULL DEFAULT 2; `show_notif` int DEFAULT 0; `notif_date`; `show_ua_upt`/`show_ua_deputi`/`show_ua_biro`/
`show_pl` int DEFAULT 0.
Index: `fk_nip_rwytb_to_pegawai`, `fk_id_jenjang_pendidikan_twytb_to_jenjp`, `nip_status_rated_notif_date`.
FK: `fk_id_jenjang_pendidikan_twytb_to_jenjp` → `jenjang_pendidikan` SET NULL/CASCADE; `fk_nip_rwytb_to_pegawai` CASCADE/CASCADE.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Aksi FK | RESTRICT/RESTRICT | V1 |
| 2 | `id_jenjang_pendidikan` | tinyint → **int** | V5; PK `jenjang_pendidikan` v2 = INT (G-05), FK mensyaratkan tipe sama |
| 3 | `file_sp`, `file_1`, `file_2`, `file_3` | NOT NULL → **NULL-able** | DQ-20; berkas v2 disimpan di `document_attachment` (D13), nilai impor dipertahankan apa adanya |
| 4 | CHECK | `status` IN (0,1,2,3); `status_admin_upt`/`status_admin_deputi` IN (0,1,2) NULL boleh; `rated` IN (0,1,2) | V2. Kode 3 tidak ditulis v2 (D14 satu langkah) tetapi tetap sah demi data impor |
| 5 | Tanpa `status_penuh` | tidak ditambahkan | D15: dihitung `bebasPresensiPenuh()` |
| 6 | Trigger `riwayat_tb_beUpd` (D1:10478-10484) | tidak dibawa → `NotifikasiService` | V3 |
| 7 | `status_admin_*`, `sp_admin_deputi`, `rs_*`, `show_ua_*` | dipertahankan [K], **tidak diisi** alur v2 (NULL) | D14; data impor dipertahankan (PRD §6.7.2) |

### 2.6 `riwayat_tb_perpan` — D1:6662-6673 (AI=39)

PK `id_riwayat_tb_perpan` int AI; `id_riwayat_tb` int NOT NULL; `tgl_mulai_perpan`/`tgl_akhir_perpan` date NOT NULL;
`file_sperpan` varchar(255) NOT NULL; `created_at` NOT NULL; `created_by` int NULL. **Tanpa kolom status** (sesuai D15/Q-12).
FK `fk_id_riwayat_tb_tbperpan_to_tb` → `riwayat_tb` **CASCADE**/CASCADE.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Aksi FK | **RESTRICT/RESTRICT** | V1. Konsekuensi wajib: hapus TB induk menghapus perpanjangan + berkasnya **eksplisit** di service (PRD §6.7.4, perbaikan TB-14) |
| 2 | Trigger `afIns`/`afUpd`/`afDel` (D1:10487-10527) | **tidak dibawa**; ringkasan `riwayat_tb.tgl_mulai_perpan`/`tgl_akhir_perpan` = `MAX(...)` dihitung `PerpanjanganTbService` **dalam transaksi yang sama**; tak ada perpanjangan → NULL | V3. Logika trigger D1 disalin apa adanya ke service (termasuk cabang "tidak ada baris lagi → NULL") |
| 3 | `file_sperpan` | NOT NULL dipertahankan [K] | Berkas perpanjangan selalu wajib (role 1, PRD §6.7.4) |

### 2.7 `riwayat_ib` — D1:4898-4955 (AI=56)

52 kolom; pola identik `riwayat_tb` **tanpa** `file_3`, tanpa `tgl_*_perpan`, dengan `ds_ib`/`no_ds_ib`/`tgl_ds_ib`/
`perihal_ds_ib`/`file_surat_ib` sebagai ganti varian `_tb`. Perbedaan D1 yang penting: **`nama` varchar(100) NOT NULL**
(di TB nullable) dan **`updated_at` datetime DEFAULT CURRENT_TIMESTAMP tanpa ON UPDATE** (terkonfirmasi [K], sesuai PRD).
Index/FK: `fk_id_jenjang_pendidikan_rwyib_to_jp` SET NULL/CASCADE, `fk_nip_rwyib_to_pegawai` CASCADE/CASCADE,
`nip_status_rated_notif_date`.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Aksi FK | RESTRICT/RESTRICT | V1 |
| 2 | `id_jenjang_pendidikan` | tinyint → int | V5 |
| 3 | `file_sp`, `file_1`, `file_2` | NOT NULL → NULL-able | DQ-20 |
| 4 | CHECK | `status` IN (0,1,2,3); `status_admin_*` IN (0,1,2) NULL boleh; `rated` IN (0,1,2) | V2 |
| 5 | `updated_at` tanpa ON UPDATE | **dipertahankan [K]** | Nilai diisi aplikasi (G2); mengubahnya tidak perlu |
| 6 | Trigger `riwayat_ib_beUpd` (D1:9169-9175) | tidak dibawa → `NotifikasiService` | V3 |

### 2.8 `riwayat_pmk` — D1:6202-6227 (AI=275)

PK `id_riwayat_pmk` **bigint** AI; `nip` varchar(30) NOT NULL; `id_pns_siasn` varchar(100); `no_sk` varchar(100) NOT NULL;
`tgl_sk` date NOT NULL; `no_bkn` varchar(100); `tgl_bkn` date; `instansi` varchar(255); `tgl_awal`/`tgl_akhir` date;
`mker_th`/`mker_bl` tinyint DEFAULT 0; `dinilai` varchar(50); `id_rw_siasn` varchar(100);
`siasn_flag` tinyint NOT NULL DEFAULT 0; `siasn_error` text; `siasn_lu` datetime;
`status` tinyint NOT NULL DEFAULT 1 COMMENT `1: Ok, 2: Fail` (**status sinkron SIASN, bukan approval**); audit.
FK `fk_rw_pmk_01` → `pegawai` CASCADE/CASCADE.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Aksi FK | RESTRICT/RESTRICT | V1 |
| 2 | CHECK | `status` IN (1,2); `siasn_flag` IN (0,1) | V2 |
| 3 | Isi tabel | hanya dari impor/SIASN; Fase 4 **tidak** menulis baris baru | D16/P-2 (PRD §6.9.1); arsip SK di `document_attachment` kode 42 (sudah ada di `main`) |

### 2.9 `riwayat_pmk_req` — D1:6232-6259 (AI=12)

PK `id_riwayat_pmk_req` bigint AI; `nip` varchar(30) NOT NULL; `jenis_pmk` varchar(20) NOT NULL DEFAULT 'Swasta'
COMMENT `Swasta, Negeri`; `instansi` varchar(255) NOT NULL; `tgl_awal`/`tgl_akhir` date NOT NULL;
`mker_th`/`mker_bl` tinyint DEFAULT 0; `file_kontrak`/`file_paklaring`/`file_surat_phk`/`file_slip_gaji` varchar(255) NULL;
`id_rw_siasn` varchar(100); **`status` tinyint NOT NULL DEFAULT 1** COMMENT `0: Pengajuan, 1: Selesai, 2: Ditolak, 3: Proses`;
`reason_note` **tinytext**; `show_notif` tinyint DEFAULT 0; `notif_date`; audit lengkap + `approved_at`, `approved_by`.
FK `fk_rriwayat_pmk_req_01` → `pegawai` CASCADE/CASCADE.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Aksi FK | RESTRICT/RESTRICT | V1 |
| 2 | DEFAULT `status` | **dipertahankan 1 [K]** | DQ-18/P-16: aplikasi **selalu** mengisi status eksplisit (0 saat ajukan), jadi default tidak pernah terpakai; mempertahankannya membuat diff impor nol |
| 3 | CHECK | `status` IN (0,1,2,3) | V2 |
| 4 | Berkas | tetap di kolom (`KolomBerkas`), **bukan** `document_attachment` | Q-21 [K]; batas 2 MB (PRD §7.4) |
| 5 | `reason_note` tinytext | dipertahankan [K]; aplikasi membatasi 255 byte | Q-23 |

### 2.10 `disiplin_ticket` — sumber D1:1369-1393 tabel `inbox` (AI=5174)

Rename [K] 1:1. Kolom D1: PK `id_inbox` int AI; `id_question_category` int NULL; `subject` varchar(250) NOT NULL;
`sender` int NOT NULL; `sender_name` varchar(150) NOT NULL; **`nip` varchar(18) NOT NULL**; `jabatan`/`satker` varchar(150);
`receiver` int NOT NULL DEFAULT 0; `receiver_name` varchar(150); `allow_reply` int NOT NULL DEFAULT 1 `0: No, 1: Yes`;
**`status` int NOT NULL DEFAULT 0 `0: Open, 1: Closed`**; `is_read` tinyint NOT NULL DEFAULT 1 `1: Yes, 2: No`;
`is_read_off` tinyint NOT NULL DEFAULT 1; `notify_pic` tinyint NOT NULL DEFAULT 2 `0: Default / Old Data, 1: Yes, 2: No`;
`notify_at` datetime; `created_at` NOT NULL; `updated_at` NULL ON UPDATE; `closed_by` int NULL.
Index `fk_id_question_category_i_qc`, `created_at`. FK `fk_id_question_category_i_qc` → `question_category` SET NULL/CASCADE.
Collation tabel D1 **`utf8mb4_0900_ai_ci`**.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Nama tabel | `inbox` → **`disiplin_ticket`** | D10; nama `inbox` dipakai tabel notifikasi v2 (Bagian 3) |
| 2 | Collation | `utf8mb4_0900_ai_ci` → **`utf8mb4_unicode_ci`** | G2; MariaDB 10.4 (matriks CI) tidak mengenal `0900_ai_ci` |
| 3 | Aksi FK | SET NULL/CASCADE → RESTRICT/RESTRICT | V1 |
| 4 | `nip` | varchar(18) → **varchar(30)** | V4; FK ke `pegawai.nip` = **DQ-4** (usul: tanpa FK / ditahan, karena tiket bisa dari pelapor yang datanya sudah hilang) |
| 5 | CHECK | `status` IN (0,1); `allow_reply` IN (0,1); `is_read`/`is_read_off` IN (1,2); `notify_pic` IN (0,1,2) | V2. **`status` open/closed wajib ada di sini** (AC-01.3) |
| 6 | Nama PK/kolom `id_inbox` | dipertahankan [K] | DQ-4; impor Fase 8 lurus tanpa pemetaan nama |
| 7 | Hapus lunak | tidak ditambahkan (tanpa status 10) | N11 → Fase 8; keputusan DBV bila ingin lain |

**Fase 4 tidak menulis satu baris pun ke tabel ini** (AC-06.4).

### 2.11 `disiplin_ticket_msg` — sumber D1:1398-1414 tabel `inbox_msg` (AI=10119)

Kolom D1: PK `id_inbox_msg` int AI; **`id_inbox` int NULL**; `msg_sender` int NOT NULL; `msg_sender_name` varchar(150) NOT NULL;
`msg_receiver` int NOT NULL DEFAULT 0; `msg_receiver_name` varchar(150); `msg` text NOT NULL; `attachments` text;
`show_notif` tinyint NOT NULL DEFAULT 1 `0: No, 1: Yes`; `is_read` tinyint NOT NULL DEFAULT 0 `0: No, 1: Yes`;
`created_at` NOT NULL. Index `msg_receiver_show_notif` (`msg_receiver`,`show_notif`), `fk_id_inbox_im_qc`.
FK `fk_id_inbox_im_qc` → `inbox.id_inbox` **CASCADE**/CASCADE. Collation D1 `utf8mb4_0900_ai_ci`.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Nama tabel | `inbox_msg` → **`disiplin_ticket_msg`** | D10 |
| 2 | Collation | → `utf8mb4_unicode_ci` | G2 |
| 3 | Aksi FK | CASCADE/CASCADE → RESTRICT/RESTRICT | V1 |
| 4 | `id_inbox` | NULL → **NOT NULL** bila audit baris NULL = 0 (Bagian 6 butir 7.4) | [V2]; pesan tanpa tiket induk tidak punya arti |
| 5 | Trigger `inbox_msg_afIns` (D1:7730-7739) | **tidak dibawa**; logikanya (menyetel `is_read`/`is_read_off` induk menurut pengirim) dicatat untuk service Fase 8 | V3; di luar scope Fase 4 |

### 2.12 `inbox` (v2) dan 2.13 `inbox_msg` (v2) — **desain baru [V2]**

Tidak ada padanan di D1. Rancangan lengkap + daftar kolom yang **dilarang** ada: **Bagian 3.2**.

### 2.14 `question_category` — D1:4008-4017 (AI=38)

PK `id_question_category` int AI; `question_category` varchar(250) NOT NULL; **`status` int NOT NULL tanpa DEFAULT**
COMMENT `0: Inactive, 1: Active`; `created_at` NOT NULL; `updated_at` NULL ON UPDATE.
Index `question_category`, `status`. Collation D1 `utf8mb4_0900_ai_ci`.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Collation | → `utf8mb4_unicode_ci` | G2; audit tabrakan nama: Bagian 6 butir 7.5 |
| 2 | Domain `status` | **usul [K] 0/1 + CHECK (0,1)**; alternatif master 1/2/10 dengan remap impor 0→2 | DQ-5 (domain): tabel ini milik Fase 8; remap ke pola master menambah risiko tanpa manfaat di Fase 4 |
| 3 | DEFAULT `status` | tambah DEFAULT 1 [V2] | Menghindari INSERT tanpa status saat Fase 8 |

### 2.15 `jenis_layanan` — D1:1705-1714 (AI=6 → id 1..5)

PK `id_jenis_layanan` int AI; `jenis_layanan` varchar(50) NOT NULL; `table` varchar(50) NOT NULL;
`module_name` varchar(50) NOT NULL; `url_process` varchar(100) NOT NULL; `url_pic` varchar(100) NULL;
`status` tinyint NOT NULL DEFAULT 1 COMMENT `1: Aktif, 2: Tidak Aktif`. Tanpa FK.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | CHECK | `status` IN (1,2,10) | V2 (pola master) |
| 2 | `url_process`, `url_pic` | NOT NULL dipertahankan; **diisi path v2** pada seed (bukan URL CI3) | AI=6 membuktikan id 1..5 ([I] PRD terkonfirmasi batasnya); isi kolom = keputusan DBV (DQ-23) |
| 3 | Seed uji | 5 baris: 1 KARPEG, 2 KARISKARSU, 3 Tugas Belajar, 4 Izin Belajar, 5 Peninjauan Masa Kerja | PRD §6.1.1 #15; data tidak ada di dump (hanya struktur) → nama = [I] dari kode legacy |

### 2.16 `petugas_layanan` — D1:3952-3962 (AI=26)

PK `id_petugas_layanan` int AI; `id_jenis_layanan` int NULL; **`nip` varchar(50) NOT NULL tanpa FK**;
`status` int NOT NULL DEFAULT 1 COMMENT `0: Inactive, 1: Active`; `created_at` NOT NULL; `updated_at` NULL ON UPDATE.
Index `id_jenis_layanan_nip_status` (`id_jenis_layanan`,`nip`,`status`).
FK `fk_id_jenis_layanan_pl_jl` → `jenis_layanan` **SET NULL**/CASCADE.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Aksi FK | SET NULL/CASCADE → RESTRICT/RESTRICT | V1 |
| 2 | `nip` | varchar(50) → **varchar(30)**; FK ke `pegawai.nip` **DITAHAN** (pra-cek yatim) | V4 + DQ-12 |
| 3 | `id_jenis_layanan` | NULL → **NOT NULL** [V2] | PIC tanpa jenis layanan tidak punya arti (`adalahPic(nip, idJenisLayanan)`); audit baris NULL: Bagian 6 butir 5 |
| 4 | CHECK | `status` IN (0,1) — ditambah 10 bila audit menemukannya (kode legacy memakai `!= '10'`) | DQ-12 |
| 5 | UNIQUE | `(id_jenis_layanan, nip)` bila audit duplikat = 0 | DQ-12; legacy menolak duplikat di aplikasi saja |

### 2.17 `pa_layanan` — D1:2429-2471 (AI=652)

PK `id_pa_layanan` int AI; `id_jenis_pa` tinyint; `id_jenis_pegawai` tinyint; `id_group_jabatan_pemohon` int;
`id_sub_group_jabatan_pemohon` int; `gol_ruang_pemohon` varchar(20); `id_unit_pemohon` int;
`id_group_jabatan_petugas` int; `id_sub_group_jabatan_petugas` int; `id_unit_petugas` int; `id_satker_petugas` int;
`id_jabatan` int; `id_jabatan_koord` int; **`nip` varchar(50) NULL** COMMENT `Only For Non Struktural / Non Koordinator, Ex: JFT, JF`;
`status` tinyint NOT NULL DEFAULT 1 COMMENT `1: Active, 2: Not Active`; audit.
**11 FK D1, semuanya SET NULL/CASCADE**: `fk_id_jenis_pa_pa_layanan_to_jenis_pa`, `fk_id_unit_pemohon_pa_layanan_to_unit`,
`fk_id_group_jabatan_pemohon_pa_layanan_to_gj`, `fk_id_sub_group_jabatan_pemohon_pa_layanan_to_sgj`,
`fk_id_unit_petugas_pa_layanan_to_unit`, `fk_id_satker_petugas_pa_layanan_to_satker`,
`fk_id_group_jabatan_petugas_pa_layanan_to_gj`, `fk_id_sub_group_jabatan_petugas_pa_layanan_to_sgj`,
`fk_id_jabatan_pa_layanan_to_jabatan`, `fk_id_jabatan_koord_pal_to_jabkoord`, `pa_layanan_ibfk_01` (`id_jenis_pegawai`).
Collation D1 `utf8mb4_0900_ai_ci`.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Collation | → `utf8mb4_unicode_ci` | G2 |
| 2 | Aksi 11 FK | SET NULL/CASCADE → **RESTRICT/RESTRICT**, nama FK legacy dipertahankan | V1; seluruh master tujuan sudah ada di `main` (G-02, DBV-019) |
| 3 | Tipe kolom FK | disamakan dengan PK master v2 (`id_jenis_pegawai`, `id_jenis_pa` tinyint; `group_jabatan`/`sub_group_jabatan`/`unit`/`satker`/`jabatan`/`jabatan_koordinasi` int) | Syarat FK; perlu dicek per master saat menulis migration |
| 4 | `nip` | varchar(50) → varchar(30); FK ditahan | V4 + DQ-17 |
| 5 | CHECK | `status` IN (1,2,10) | V2 |
| 6 | Hapus | **fisik [K]** + `audit_logs` | PRD §6.4.8 (`L:4357`); DBV boleh memutuskan status 10 |

### 2.18 `jenis_pa` — D1:1733-1741 (AI=2 → hanya id 1 = Cuti)

PK `id_jenis_pa` tinyint AI COMMENT `Jenis Layanan Petugas Approval`; `jenis_pa` varchar(100) NOT NULL;
`order` tinyint NOT NULL DEFAULT 1; `status` tinyint NOT NULL DEFAULT 1 COMMENT `1: Active, 2: Non Active`;
`created_at` NOT NULL; **`udpated_at`** datetime NULL ON UPDATE (typo nama kolom, terkonfirmasi [K]).
Collation D1 `utf8mb4_0900_ai_ci`.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Collation | → `utf8mb4_unicode_ci` | G2 |
| 2 | **`udpated_at`** | **usul diperbaiki → `updated_at` [V2]** | Tabel master kecil (1 baris), tidak ada kode v2 yang memakai nama lama; pemetaan impor sepele. DBV memutus (DQ-24) |
| 3 | CHECK | `status` IN (1,2,10) | V2 |
| 4 | Seed | 1 baris `id_jenis_pa = 1` ("Cuti") | AI=2 membuktikan hanya id 1 terpakai |

### 2.19 `esign_hist` — D1:891-907 (AI=110)

PK `id_esign_hist` bigint AI; `nip` varchar(30) **NULL**; `nik` varchar(20) NOT NULL; `nama_lengkap` varchar(256) NOT NULL;
`modul` varchar(50) NOT NULL DEFAULT 'Cuti Online'; `id_rw` int NULL; `file_pdf_to_sign` varchar(256) NOT NULL;
**`status` varchar(256) NOT NULL DEFAULT '1'** COMMENT `1: Signing Berhasil, 2: Signing Gagal`;
`ip_address` varchar(20) NULL; `user_agent` tinytext; `keterangan` tinytext; `created_at` NOT NULL.
Tanpa kolom `updated_*` (append-only [K]). FK `fk_esign_hist_ibfk01` (`nip`) → `pegawai` **SET NULL**/CASCADE.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Aksi FK | SET NULL/CASCADE → **RESTRICT/RESTRICT** (kolom tetap NULL-able) | V1. Catatan: baris `esign_hist` adalah bukti legal; RESTRICT mencegah pegawai terhapus selama masih punya riwayat TTE — konsisten dengan B-05 (hapus pegawai 422 bila ada data terkait) |
| 2 | `status` varchar(256) | **dipertahankan [K]** + CHECK `status` IN ('1','2') | DQ-7; mengubah tipe mengubah arti data impor |
| 3 | `status_code` | **tambah varchar(10) NULL [V2]** | Kontrak QASMTASK-090: menyimpan kode gateway BSrE agar Fase 6 tidak perlu ALTER |
| 4 | `ip_address` | varchar(20) → **varchar(45) [V2]** | IPv6 (maks 45 karakter) tidak muat di 20; audit panjang terpakai: Bagian 6 butir 8 |
| 5 | Index | tambah `(modul, id_rw)` | DQ-7; dipakai "surat untuk cuti ini sudah pernah di-TTE?" |
| 6 | `nik` | dipertahankan [K] | **PII**: NIK polos (E15). Tidak dipakai respons API, tidak masuk log/audit; hanya dikirim ke adapter e-sign (PRD §6.5.3 butir 7) |
| 7 | `id_rw` | int [K] | Menunjuk `riwayat_cuti.id_riwayat_cuti` (int) — cocok; tanpa FK karena polimorfik antar modul |

### 2.20 `firebase_token` — D1:1156-1163 (tanpa AI, tabel kosong)

Kolom D1: `nip` varchar(30) NOT NULL; `fcm_token` text; `auth_token` text; `created_at`/`updated_at` **timestamp** NULL
DEFAULT NULL. **Tanpa PRIMARY KEY**, hanya `KEY nip`. Collation D1 `utf8mb4_0900_ai_ci`.

| # | Deviasi | v2 | Alasan |
|---|---|---|---|
| 1 | Collation | → `utf8mb4_unicode_ci` | G2 |
| 2 | PK | **tambah `id_firebase_token` bigint AI PK [V2]** | Tabel tanpa PK menyulitkan replikasi & penghapusan baris per token |
| 3 | FK | `nip` → `pegawai.nip` RESTRICT [V2] | K1; tabel kosong ([M] Tier 6 tidak dimigrasi) sehingga tidak ada risiko yatim → FK bisa aktif di CREATE |
| 4 | `created_at`/`updated_at` | timestamp → **datetime** | G2 (stempel UTC diisi aplikasi, konsisten seluruh repo) |
| 5 | `auth_token` | **usul dibuang [V2]** | Tidak dipakai kontrak `PushNotifGatewayInterface`; menyimpan token autentikasi tanpa pemakai = risiko tanpa manfaat. DBV memutus (DQ-25) |
| 6 | UNIQUE | usul `(nip, fcm_token)` tidak dipasang (TEXT tidak bisa UNIQUE penuh) | Deduplikasi di aplikasi Fase 6 |

`fb_token` (desktop, per `id_user`) **tidak** dibuat (PRD §6.1.1 #20).

### 2.21 Kode `jenis_rwy` baru — migration terpisah

Seed `jenis_rwy` di `main` (`2026-09-30-130000_CreateJenisRwy.php:63-85`) berisi kode 0..42; tertinggi = 42 (`pmk` →
`riwayat_pmk`). Migration **baru** (K3: seed lama tidak diedit) menambah:

| Kode | Slug | Nama | `tabel_entri` | Dipakai |
|---|---|---|---|---|
| 43 | `cuti` | Lampiran Pengajuan Cuti | `riwayat_cuti` | C-02 `file_bukti` |
| 44 | `surat_cuti` | Surat Cuti (belum TTE) | `riwayat_cuti` | C-04 efek final |
| 45 | `surat_cuti_tte` | Surat Cuti (ber-TTE) | `riwayat_cuti` | C-05 hasil e-sign |
| 46 | `tb` | Lampiran Tugas Belajar | `riwayat_tb` | C-07 (4 berkas) |
| 47 | `surat_tb` | SK Tugas Belajar | `riwayat_tb` | C-07 `file_surat_tb` |
| 48 | `tb_perpan` | Berkas Perpanjangan TB | `riwayat_tb_perpan` | C-07 perpanjangan |
| 49 | `ib` | Lampiran Izin Belajar | `riwayat_ib` | C-08 (3 berkas) |
| 50 | `surat_ib` | Surat Izin Belajar | `riwayat_ib` | C-08 efek final |

**Perbedaan dari usulan PRD §6.1.1 #21:** PRD mengusulkan 7 kode (43..49) dengan satu kode `surat_cuti` untuk kedua versi
surat. Di sini diusulkan **8 kode (43..50)** dengan `surat_cuti` dan `surat_cuti_tte` dipisah — itu jawaban atas **DQ-21**
(pembeda "belum TTE / ber-TTE"): dua kode `jenis_rwy` lebih murah daripada menambah kolom `tag` ke `document_attachment`
Fase 3 (yang akan melanggar K3 / kontrak beku §2.7). Konsekuensi: AC-05.6 ("surat ber-TTE tidak tertimpa") diuji dengan
memeriksa keberadaan baris kode 45. Nomor final = keputusan DBV.

---

## 3. Pemisahan `inbox` (notifikasi) vs `disiplin_ticket` (ticketing) — **bagian inti**

Dasar: keputusan user **D10**; DoD C-01 butir 064-2/064-3/064-6; PRD §6.1.1 #10–#13, §6.6, §7.1.

### 3.1 Temuan T1 — mengapa dipisah

Tabel `inbox`/`inbox_msg` **legacy** bukan tabel notifikasi: isinya 100% tiket Halo Simpeg dua arah (balasan, status
open/closed, `allow_reply`, `closed_by`, penugasan petugas) — rujukan kode `L_support.php:997, 1006, 1169, 1341`. Notifikasi
legacy sendiri tidak punya tabel: ia ditempel sebagai kolom `show_notif`/`notif_date` di tiap tabel riwayat ditambah trigger DB
dan antrean push (`andr_notif_queue` dkk.) — temuan N1/N2.

Memakai satu tabel untuk keduanya akan memaksa notifikasi membawa kolom tiket (status open/closed, balasan) yang tidak punya
arti bagi notifikasi, dan sebaliknya membuat tiket Halo Simpeg (Fase 8) bergantung pada tabel yang dipakai alur approval. Karena
itu D10 membelah keduanya **sejak C-01**, dan DoD mewajibkan kedua skema direview **sekaligus** di sini walau ticketing baru
dipakai Fase 8.

### 3.2 `inbox` v2 + `inbox_msg` v2 — notifikasi satu arah [V2]

Desain baru (tidak ada padanannya di D1). Usulan PRD §6.1.1 #12/#13:

**`inbox`** — satu induk per penerima per pengajuan.

| Kolom | Tipe usulan | Catatan |
|---|---|---|
| `id_inbox` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `nip_penerima` | VARCHAR(30) NOT NULL | FK → `pegawai(nip)` RESTRICT (K1/V4); registry NIP Bagian 4 |
| `id_pengguna_penerima` | INT NULL | Opsional — DQ-4: penerima diidentifikasi NIP, `id_pengguna` atau keduanya |
| `modul` | VARCHAR(20) NOT NULL | `cuti` \| `tb` \| `ib` \| `pmk`; CHECK domain [V2] |
| `id_jenis_layanan` | TINYINT NULL | FK → `jenis_layanan` RESTRICT; NULL untuk cuti (cuti bukan `jenis_layanan`) |
| `id_rwy` | BIGINT NOT NULL | Id baris sumber; **tanpa FK** (polimorfik lintas 4 tabel) |
| `judul` | VARCHAR(150) NOT NULL | |
| `url` | VARCHAR(255) NOT NULL | Rute FE tujuan |
| `is_read` | TINYINT NOT NULL DEFAULT 0 | CHECK 0/1 |
| `read_at` | DATETIME NULL | |
| `created_at` | DATETIME NOT NULL | UTC, diisi aplikasi (G2) |

Index: `(nip_penerima, is_read, created_at)`; usulan tambahan `(modul, id_rwy)` untuk penelusuran per pengajuan.

**`inbox_msg`** — satu baris per kejadian di bawah satu `inbox`, **tanpa balasan**.

| Kolom | Tipe usulan | Catatan |
|---|---|---|
| `id_inbox_msg` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `id_inbox` | BIGINT UNSIGNED NOT NULL | FK → `inbox` RESTRICT |
| `tipe` | TINYINT NOT NULL | 1 diajukan, 2 keputusan, 3 perubahan, 4 tembusan; CHECK |
| `status_sumber` | TINYINT NOT NULL | Status baris sumber saat kejadian (AC-06.1) |
| `isi` | VARCHAR(500) NOT NULL | Templat §6.6.2 |
| `reason_note` | VARCHAR(255) NULL | Alasan tolak (Q-23: ≤ 255 byte) |
| `push_status` | TINYINT NOT NULL DEFAULT 0 | 0 antre / 1 terkirim / 2 gagal / 3 tanpa token; CHECK |
| `created_at` | DATETIME NOT NULL | UTC |

**Larangan yang diuji otomatis (AC-01.2).** `inbox` dan `inbox_msg` v2 **DILARANG** memiliki kolom: `status` (open/closed),
`allow_reply`, `closed_by`, `is_read_off`, `notify_pic`, serta kolom balasan/thread apa pun. `SkemaInboxTicketTest`
menegaskan **ketiadaan** kolom-kolom itu, bukan sekadar keberadaan tabel.

DB Validator boleh menyederhanakan rancangan di atas, **asalkan** dua tabel tetap ada dan tetap satu arah (DoD 064-2).

### 3.3 `disiplin_ticket` + `disiplin_ticket_msg` — ticketing dua arah [K], skema final Fase 8

Rename 1:1 dari `inbox`/`inbox_msg` **legacy** (D1:1369-1393 dan D1:1398-1414), dengan penyesuaian konvensi: collation
`utf8mb4_0900_ai_ci` → `utf8mb4_unicode_ci` (G2), FK RESTRICT (V1), `nip` VARCHAR(18) → VARCHAR(30) (V4), CHECK domain (V2),
dan `disiplin_ticket_msg.id_inbox` NULL → NOT NULL [V2] bila audit Bagian 6.7.4 menunjukkan 0 baris NULL.

Kolom pembeda yang **wajib tetap ada** di sini (dan dilarang ada di `inbox` v2): `status` 0 Open / 1 Closed, `allow_reply`,
`closed_by`, `is_read_off`, `notify_pic`, `notify_at`, `receiver`/`receiver_name`, `msg_sender`/`msg_receiver`, `attachments`.

Fase 4 **hanya membuat skemanya**; tidak ada endpoint, model, UI, maupun baris data ticketing yang ditulis Fase 4
(PRD §1.2). `question_category` ikut dibuat karena dirujuk FK `disiplin_ticket`.

### 3.4 Rencana impor (konsekuensi pemisahan)

- Seluruh isi `inbox`/`inbox_msg` **legacy** → `disiplin_ticket`/`disiplin_ticket_msg` (dikerjakan Fase 8).
- `inbox` v2 **dimulai kosong**. Pengisian dari `riwayat_cuti_notif_kt`/`show_notif` legacy = opsi yang harus diputuskan
  DB Validator bersama F-02 (sortir impor inbox, Fase 7); usulan coder: **tidak** diimpor (notifikasi bersifat sesaat,
  riwayat keputusan sudah ada di `riwayat_*` + `audit_logs`).
- Regression Fase 4: setelah seluruh skenario cuti/TB/IB/PMK dijalankan, `disiplin_ticket` dan `disiplin_ticket_msg`
  **= 0 baris** (AC-06.4).

---

## 4. Registry NIP

Aturan AGENTS/PRD §2.6: setiap kolom NIP baru wajib diklasifikasi di `backend/app/Libraries/Kepegawaian/Nip/TabelAnakNip.php`
dan diuji `backend/tests/database/Kepegawaian/NipReferenceRegistryTest.php`; cakupan koreksi NIP
(`backend/app/Libraries/Kepegawaian/NipCascadeService.php`) diperluas ke tabel Fase 4, dengan konstanta
`NipCascadeService::FASE` dinaikkan ke **4** (test memakai `sampaiFase(NipCascadeService::FASE)`, bukan angka tetap, agar
Fase 5A dapat menaikkannya ke 5 tanpa konflik).

| Kolom | Tabel | Usulan klasifikasi |
|---|---|---|
| `nip` | `riwayat_cuti`, `riwayat_tb`, `riwayat_ib`, `riwayat_pmk`, `riwayat_pmk_req`, `pegawai_sisa_cuti`, `esign_hist`, `firebase_token` | FK v2 (RESTRICT); `esign_hist.nip` nullable |
| `nip_atasan_langsung`, `nip_yang_menyetujui` | `riwayat_cuti` | FK v2 **ditahan** sampai audit yatim = 0 (DQ-3); sampai itu diperlakukan NON_FK + ikut cascade |
| `nip_kt` | `riwayat_cuti_notif_kt` | FK v2 (bagian PK komposit) |
| `nip` | `petugas_layanan`, `pa_layanan` | FK v2 atau NON_FK — DQ-12/DQ-17 |
| `nip` | `disiplin_ticket` | NON_FK atau FK ditahan — DQ-4/DQ-6 |
| `ketua_tim` | `riwayat_cuti` | **Jenis registry baru**: TEXT berisi banyak NIP dipisah `***` (DQ-15). Cascade harus mengganti elemen di dalam string, bukan seluruh nilai kolom |

`riwayat_cuti.ketua_tim` adalah satu-satunya kolom multi-NIP di repo sampai saat ini; bentuk jenis registrinya diputuskan
DB Validator. Audit elemen non-NIP: Bagian 6 butir 9.

---

## 5. Urutan impor (J1.07)

Digabung ke runbook impor DBV-011. Urutan (PRD §6.1.2 butir 6):

```
jenis_cuti, jenis_layanan, jenis_pa, question_category
  -> petugas_layanan, pa_layanan
  -> pegawai_sisa_cuti
  -> riwayat_cuti -> riwayat_cuti_notif_kt
  -> riwayat_tb -> riwayat_tb_perpan
  -> riwayat_ib
  -> riwayat_pmk_req, riwayat_pmk
  -> esign_hist (utuh — bukti legal, append-only)
  -> disiplin_ticket -> disiplin_ticket_msg
  -> inbox v2 (kosong; lihat 3.4)
```

Counter AUTO_INCREMENT disalin (pola DBV-011 `DBV-011-counter-auto-increment-v2.sql`). Normalisasi nilai di luar domain
dilakukan setelah hasil audit Bagian 6 dibaca DB Validator — bukan diputuskan coder.

---

## 6. Kueri audit baca-saja

Berkas: `backend/docs/db-review/sql/DBV-022-audit-layanan.sql` (10 bagian, hanya `SELECT`, dijalankan di salinan dump —
bukan di produksi legacy dan bukan di DB dev v2).

| Bagian | Isi | Klasifikasi |
|---|---|---|
| 1 | `riwayat_cuti` NIP approver: yatim, kosong, panjang > 30 | WAJIB_0 (prasyarat aktivasi FK, DQ-3) |
| 2 | Distribusi `status`, `status_al`, `status_pyb`, `rated`, `skip_*`, `status_regen`, `id_jenis_cuti`, `satuan_lama_cuti` | INFO + prasyarat CHECK |
| 3 | `pegawai_sisa_cuti`: duplikat `(nip, tahun)`, `tahun = 0`, nilai negatif, pelanggaran batas D8 | WAJIB_0 untuk UNIQUE (DQ-10) |
| 4 | Distribusi status TB/IB/PMK/PMK req + `jenis_pmk`; NIP yatim keempat tabel; perpanjangan yatim | WAJIB_0 + INFO (DQ-18) |
| 5 | `petugas_layanan`: NIP yatim/panjang, status di luar {0,1,10}, `id_jenis_layanan` NULL/yatim, duplikat | WAJIB_0 + INFO (DQ-12) |
| 6 | `pa_layanan`: NIP yatim, 11 id master yatim | WAJIB_0 (DQ-17) |
| 7 | `inbox`/`inbox_msg` legacy: kategori NULL/yatim, NIP yatim, domain flag, induk NULL/yatim, tabrakan collation | WAJIB_0 + INFO (DQ-4/DQ-6) |
| 8 | `esign_hist`: status di luar ('1','2'), NIP yatim, panjang `ip_address`; `firebase_token` | INFO (DQ-7) |
| 9 | `riwayat_cuti.ketua_tim`: elemen yang bukan NIP pegawai | INFO (DQ-15) |
| 10 | `riwayat_cuti_notif_kt`: distribusi status, baris yatim | WAJIB_0 + INFO |

Bagian 7.3 (pelaku tiket terhadap tabel pengguna legacy) sengaja dibiarkan **dikomentari**: nama tabel/kolom pengguna di
legacy berbeda dari v2 dan belum dapat dipastikan tanpa D1.

**Hasil audit: belum dijalankan** — tidak ada akses ke salinan dump legacy di lingkungan coder (Bagian 9).

---

## 7. Keputusan yang diminta dari DB Validator

PRD §12 Q-20. Butir yang sudah terjawab keputusan user tidak diajukan ulang.

| # | Pertanyaan | Usulan coder |
|---|---|---|
| DQ-3 | FK `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui`: di CREATE atau ditahan; perlakuan yatim | **Ditahan** di `MigrationsDitahan/` dengan pra-cek fail-closed (pola DBV-019) sampai audit Bagian 6.1 = 0; kolom VARCHAR(30) nullable, snapshot nama/jabatan tetap disimpan |
| DQ-4 | `inbox`/`inbox_msg` v2: penerima `nip` vs `id_pengguna`; `id_inbox` di `disiplin_ticket_msg` dipertahankan/rename | `nip_penerima` sebagai kunci utama penerima (approver & PIC diidentifikasi NIP di seluruh Modul C), `id_pengguna_penerima` opsional; nama kolom legacy `id_inbox` dipertahankan agar impor Fase 8 lurus |
| DQ-6 | FK ke `pengguna` untuk `disiplin_ticket.sender/receiver/closed_by`, `_msg.msg_sender/msg_receiver` (0 = semua petugas) | Tanpa FK (nilai sentinel `0` tidak dapat di-FK-kan tanpa baris khusus); divalidasi aplikasi di Fase 8 |
| DQ-7 | `esign_hist`: tipe `status`, kolom `status_code` [V2], `ip_address` 45 | Pertahankan `status` VARCHAR [K] + CHECK ('1','2'); tambah `status_code` VARCHAR(10) NULL; `ip_address` → VARCHAR(45) (IPv6); index `(modul, id_rw)`; `nik` tetap [K] dengan catatan PII |
| DQ-9 | `jenis_cuti` PK komposit → tunggal + UNIQUE | PK tunggal `id_jenis_cuti` + UNIQUE `nama_jenis_cuti`; id **wajib** 1..6 seperti legacy (Tukin v2 meng-hard-code `StatusHarian::JENIS_CUTI`); seed literal 6 baris di migration (pola `jenis_rwy`); tanpa UI master di Fase 4 |
| DQ-10 | `pegawai_sisa_cuti` UNIQUE `(nip, tahun)`, CHECK ≥ 0 | UNIQUE bila audit duplikat = 0; CHECK ≥ 0 untuk kelima kolom saldo; batas atas D8 ditegakkan aplikasi (§6.3.6), tidak sebagai CHECK, karena data impor bisa melanggarnya |
| DQ-12 | `petugas_layanan.status` domain & FK `nip` | Domain [K] 0/1 dengan CHECK (0,1,10) bila audit menemukan 10; FK `nip` ditahan seperti DQ-3; UNIQUE `(id_jenis_layanan, nip)` bila audit = 0 |
| DQ-16 | Index approval tambahan | Tambahkan `(nip_atasan_langsung, status)` dan `(nip_yang_menyetujui, status)` — dipakai `GET cuti?mode=persetujuan` dan `GET cuti/pengingat` |
| DQ-17 | `id_sub_group_jabatan`, `id_jabatan_al`, `id_jabatan_pyb` tanpa FK | Tanpa FK (nilai historis snapshot; master bisa berubah) |
| DQ-18 | CHECK status TB/IB (0..3) dan default `riwayat_pmk_req.status` | CHECK 0..3; default kolom dipertahankan [K] **atau** diubah ke 0 — aplikasi selalu mengisi eksplisit sehingga keduanya aman; usulan: ikuti [K] agar impor tidak berubah arti |
| DQ-20 | Kolom `file_*` NOT NULL [K] di `riwayat_tb`/`riwayat_ib` sementara berkas v2 di `document_attachment` | Jadikan NULL-able [V2]; nilai impor dipertahankan apa adanya; berkas baru v2 masuk `document_attachment` |
| DQ-21 | Pembeda versi surat (belum TTE vs ber-TTE) | Dua kode `jenis_rwy` terpisah (lebih sederhana dari kolom `tag` baru di `document_attachment`, dan tidak mengubah tabel Fase 3) |
| DQ-jenis_rwy | Nomor kode baru | **Usulan direvisi menjadi 8 kode 43..50** (Bagian 2.21): `surat_cuti` (belum TTE) dan `surat_cuti_tte` dipisah sebagai jawaban DQ-21. Kode tertinggi di `main` = 42 (`pmk`) — tidak bentrok |

**Pertanyaan tambahan yang muncul setelah membaca D1** (tidak ada di PRD):

| # | Pertanyaan | Usulan coder |
|---|---|---|
| DQ-22 | `riwayat_cuti` FK ke `jenis_cuti` di D1 bernama **`id_jenis_cuti`** (nama constraint = nama kolom, menyimpang dari pola `fk_<kolom>_<tabel>_to_<induk>`) — dipertahankan atau diseragamkan? | Dipertahankan [K] agar diff terhadap D1 nol; nama FK legacy adalah konvensi V1 |
| DQ-23 | `jenis_layanan.url_process` NOT NULL dan `url_pic` berisi URL CI3 — diisi path v2, dikosongkan, atau dibiarkan? | Diisi path v2 pada seed (mis. `layanan/petugas`); kolom tidak dipakai kode v2 (routing v2 statis), jadi nilainya hanya dokumentatif |
| DQ-24 | `jenis_pa.udpated_at` (typo nama kolom) | Diperbaiki menjadi `updated_at` [V2]; tabel hanya 1 baris, tidak ada kode v2 yang memakai nama lama |
| DQ-25 | `firebase_token.auth_token` tidak dipakai kontrak push v2 | Dibuang [V2]; menyimpan token autentikasi tanpa pemakai = risiko tanpa manfaat. Bila Fase 6 membutuhkannya → ALTER di DBV Fase 6 |
| DQ-26 | `firebase_token` tanpa PK di D1 | Tambah `id_firebase_token` bigint AI PK [V2]; tabel kosong sehingga tanpa risiko impor |
| DQ-27 | `petugas_layanan.id_jenis_layanan` NULL-able + FK SET NULL di D1 | Jadikan NOT NULL + RESTRICT [V2]; PIC tanpa jenis layanan tidak bisa dipakai `adalahPic()`. Bergantung audit baris NULL (Bagian 6 butir 5) |

---

## 8. Rencana test skema

Berkas (PRD §6.1 file boundary):

| Berkas | Isi |
|---|---|
| `backend/tests/database/Layanan/SkemaLayananTest.php` | `information_schema` per tabel layanan: kolom, tipe, NULL, default, PK, index, FK + aksi, collation, COMMENT; error 1452 (FK), 1451 (hapus induk), 1062 (UNIQUE); CHECK domain |
| `backend/tests/database/Layanan/SkemaInboxTicketTest.php` | **AC-01.2/01.3/01.6**: `inbox`/`inbox_msg` v2 **tidak** punya `status`, `allow_reply`, `closed_by`, `is_read_off`, `notify_pic`; `disiplin_ticket`/`_msg` **punya** `status` open/closed + CHECK; keduanya tabel terpisah |
| `backend/tests/database/Layanan/MigrasiLayananSiklusTest.php` | Siklus `down()`/`up()` tiap migration (`#[Group('db-isolasi-penuh')]`); migration FK ditahan menolak jalan bila ada yatim (fail-closed) |
| `backend/tests/database/Kepegawaian/NipReferenceRegistryTest.php` (diperbarui) | **AC-01.7**: seluruh kolom NIP Fase 4 terklasifikasi, termasuk `ketua_tim`, `nip_kt`, `esign_hist.nip`, `petugas_layanan.nip`, `pa_layanan.nip`, `inbox.nip_penerima`, `disiplin_ticket.nip`, `firebase_token.nip` |

Semua test ber-DB `extends Tests\Support\DatabaseTestCase`; DDL bertanda `#[Group('db-isolasi-penuh')]`; DB scratch
`simpeg_v2_t_dbv022*` di instance MySQL test **port 3307** (`tools/mysql-test/`). **AC-01.8**: `git diff origin/main --
backend/app/Database/Migrations/` hanya berisi berkas baru.

---

## 9. Status & blocker

| # | Status | Butir | Akibat |
|---|---|---|---|
| **B1** | ✅ **selesai 20-10-2026** | D1 (`simpeg01_struktur_lengkap_20261001.sql`) diterima; nomor baris cocok dengan rujukan PRD (`esign_hist` 891, `inbox` 1369, `jenis_cuti` 1634, `riwayat_cuti` 4396) | Bagian 2 terisi lengkap untuk 21 butir |
| **B2** | ⛔ terbuka | Tidak ada akses salinan dump legacy untuk **menjalankan** `sql/DBV-022-audit-layanan.sql` (dump yang diterima hanya **struktur**, tanpa data — `-- Data exporting was unselected.`) | Keputusan DQ-3 (FK approver aktif vs ditahan), DQ-10 (UNIQUE saldo), DQ-12/DQ-27 (domain & NULL PIC), DQ-18, DQ-20 bergantung angka ini. Usulan coder sudah dibuat **fail-safe**: FK yang bergantung audit ditaruh di `MigrationsDitahan/` |
| **B3** | ⛔ terbuka | Salinan kode legacy di mesin kerja (`simpegdev…/application/`) **bukan** versi yang dirujuk PRD (`1_legacy_simpeg…`); nomor baris tidak cocok pada uji acak `Cuti.php:584-591`, `L_cuti.php:1440`, `L_cuti.php:2645-2651` | Rujukan `[K] berkas:baris` tidak dapat diverifikasi sendiri. **Tidak memblokir C-01** (skema seluruhnya bersumber D1); relevan untuk M3–M5 |
| **B4** | ⛔ terbuka | 14 butir keputusan Bagian 7 (DQ-3, 4, 6, 7, 9, 10, 12, 16, 17, 18, 20, 21, 22–27) belum diputus DB Validator | **Draf migration ditahan**: setiap butir mengubah DDL, menulisnya sekarang berarti menulis dua kali |

**Langkah berikutnya begitu Bagian 7 diputus:** tulis migration per kelompok tabel + `MigrationsDitahan/` + seed uji +
pembaruan registry NIP + tiga test skema (Bagian 8), jalankan hanya di DB scratch `simpeg_v2_t_dbv022*` port 3307, lalu
ajukan PR `[DBV-022]`.

Status C-01 = **IN_PROGRESS** (dokumen siap direview; migration menunggu keputusan).
