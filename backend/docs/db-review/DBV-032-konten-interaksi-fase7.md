# DB Validator Review — DBV-032 skema konten & interaksi Fase 7 (F-01)

**Key review:** `DBV-032`. Judul PR: `[DBV] DBV-032 skema konten & interaksi Fase 7`.
Branch `fase7/dbv-032-skema-dashboard` (dari `main` `57914b45`). Alur `[DBV]`:
1. PR dibuka, lalu DB Validator (WS-2) me-review dan memberi approve.
2. Migration dipindah dari `MigrationsDitahan/` ke `Migrations/`, test skema dijadikan permanen, dan registry NIP diperbarui.
3. Reviewer CR me-merge dengan izin Horii.

**Status:** ⏳ draf, **dicocokkan dengan D1 Fase 7 lengkap 13/13 (11-11-2026, salinan dev, §2a)**; siap PR [DBV].

**Penulis:** Jalur B Fase 7, 10-11-2026.

**Asal kebutuhan:** PRD Fase 7 v2.0 (`docs/fase7/PRD_Fase7_v2_Dashboard-Konten-Notifikasi.md`) §5.1, §0 (G7-2/G7-3), §4
(Q7-02, 03, 09, 11, 14, 15, 17–24, 27, 47). Bahan kolom: M0 Jalur B dan M0 Jalur A (di luar repo), keduanya disarikan di bawah.
DoD Trello F-01 (QASMTASK-098): tabel dibuat; `inbox`/`inbox_msg` tidak dibuat ulang; skema di-approve DB Validator.

**Rujukan:**
- `DBV-022`: inbox, `id_rwy` polimorfik tanpa FK, audit WAJIB_0/INFO.
- `DBV-026`: pola dokumen dengan D1 tidak tersedia, draf ditahan, `*_by` tanpa FK.
- `TabelAnakNip`: nama FK NIP.
- `NipCascadeService`: koreksi NIP, termasuk `JsonArrayNip`.

**Label sumber:**
- **[K]**: ikut DDL legacy.
- **[K-kode]**: diturunkan dari kode legacy (path relatif `application/`).
- **[K-reg]**: registry v2 `TabelAnakNip`, yang disusun dari D1 01-10-2026.
- **[V2]**: deviasi v2 yang disengaja.
- **[I]**: inferensi.

Kolom bertanda ⚑ **wajib dicocokkan dengan D1 Fase 7** begitu hasilnya tersedia.

---

## 0. Ringkasan

| Kelompok | Tabel | Pemilik aplikasi | Catatan |
|---|---|---|---|
| Popup (F-04) | `popup`, `user_popup` | Jalur A MAKE-079 | tanpa `dilihat_at` (Q7-02), tanpa kolom konstan (Q7-03), tanpa `*_desc` (Q7-47) |
| Banner (F-05) | `banner`, `user_banner` | Jalur A MAKE-080 | tanggal DATE |
| Berita (F-07) | `news_category`, `news`, `news_flag` | Jalur A MAKE-081 | `show_notif` dimuat; `news_flag` → `pengguna` |
| DIBO (F-12) | `jenis_dibo`, `dibo` | Jalur A MAKE-082 | `jenis_dibo` master generik (Q7-11) |
| Form digital (F-06) | `form_digital`, `user_form_digital`, `filled_form_digital` | Jalur B MAKE-083 | hapus lunak (Q7-19), target permanen (Q7-20), satu jawaban (Q7-17) |
| SKL (F-11) | `skl` | Jalur B MAKE-084 | satu penilaian per riwayat (Q7-21) |

- 13 tabel baru, tanpa ALTER tabel lama dan tanpa data.
- Impor adalah tiket tersendiri (Q7-24); tier-nya didefinisikan saat pemindahan (Bagian 10).
- `inbox`/`inbox_msg` tidak disentuh karena sudah ada sejak DBV-022.
- Tidak ada perubahan pada tabel riwayat: kolom `rated` untuk SKL sudah ada di kelima tabel `riwayat_*` v2.
- Tidak ada nilai ENUM baru di `audit_logs` (Q7-16).

## 1. Konvensi

- InnoDB, `utf8mb4_unicode_ci`. Tiap tabel punya PK `INT UNSIGNED AUTO_INCREMENT`; nama PK induk mengikuti legacy [K-kode].
- Tabel anak yang PK legacy-nya tidak terlihat di kode (`user_popup`, `user_banner`, `user_form_digital`, `filled_form_digital`, `news_flag`) diberi PK sintetis `id_<tabel>` [V2] ⚑.
- **FK NIP:** kolom `nip` VARCHAR(30) (V4, lebar `pegawai.nip`), dengan nama FK dari registry [K-reg]. Aksi `RESTRICT/RESTRICT`, karena koreksi NIP dijalankan aplikasi (CR-036, B-06).
- **FK anak → induk:** `ON DELETE CASCADE ON UPDATE RESTRICT` [V2]. Ini memperbaiki baris yatim legacy (`delete_popup` `L_announcement.php:326`, `delete_dashban` :1480).
  - Pengecualian: `filled_form_digital` memakai RESTRICT, karena form dihapus lunak (Q7-19) dan jawaban tidak boleh hilang.
  - Master (`news_category`, `jenis_dibo`) juga RESTRICT.
- **Kolom `*_by`:** `id_pengguna` INT UNSIGNED **tanpa FK** (konvensi v2, pola DBV-026). Pengecualian: `news_flag.id_user` adalah kunci perilaku, jadi diberi FK ke `pengguna` RESTRICT.
- **Waktu:** `created_at/updated_at/approved_at/publish_at/deleted_at` disimpan dalam UTC dan diisi aplikasi (CR-086).
  - `popup.tgl_*` DATETIME dan `banner.tgl_*`/`form_digital.tgl_*` DATE adalah tanggal kalender WIB [K-kode]. Aplikasi membandingkannya dengan waktu WIB. ⚑ D1 untuk tipe.
- **Status baris:** 1/2/10 (aktif/nonaktif/dihapus) bila tabel punya status [V2].
  - Pengecualian: `news.status` 0/1/2 adalah alur persetujuan [K], bukan status baris.
  - Pengecualian: `dibo.status` 2 = dicabut [K-kode].
- **Path berkas:** VARCHAR(255) berisi kunci storage privat (Q7-14), bukan `assets/upload/...`.
- **Kolom target JSON** (`target_uns`, `target_peg`, `excl_peg`): MEDIUMTEXT + `CHECK (… IS NULL OR JSON_VALID(…))`. Pola ini sama dengan `dm_user_lokasi_presensi`, dan ditambah CHECK [V2].
  - Legacy menyimpan JSON ber-`addslashes`, sehingga impor wajib menormalkannya ke JSON valid.

## 2. Sumber legacy

**D1 tidak tersedia di mesin pelaksana** (dump `simpeg01_struktur_lengkap_20261001.sql` sudah dihapus). Duplikat struktur lokal ada di 3306, yang terlarang untuk dipakai. Karena itu:
- Kolom diturunkan dari kode [K-kode].
- FK NIP diambil dari registry [K-reg]. Registry disusun dari D1 dan mencatat `REFERENCES pegawai (nip)` legacy untuk `user_popup`, `user_banner`, `user_form_digital`, `filled_form_digital`, `skl`.

Pencocokan dengan D1 Fase 7 (`D1_fase7/ambil_d1_fase7.sql`, milik Horii, G7-2) menahan PR ini.

| Tabel legacy | Ditulis | Dibaca | Catatan |
|---|---|---|---|
| `popup` | `libraries/hr/L_announcement.php:430-441` (`sp_popup`), 148, 268, 326 | `controllers/User.php:401-420`, `controllers/hr/services/Cron.php:906-976` | method `*_20220907` (:631-1190) = kode mati |
| `user_popup` | `L_announcement.php:159`, 279-280 | `User.php:413`, `Local.php:4336` | snapshot NIP, dedup di aplikasi |
| `banner` | `L_announcement.php:1572-1583`, 1311, 1422, 1480 | `libraries/hr/L_user.php:627-634` | `*_20220907` (:1773-2338) mati |
| `user_banner` | `L_announcement.php:1322`, 1433-1434 | `L_user.php:627-634`, `Local.php:4355` | — |
| `news_category` | `libraries/hr/L_news.php:1317-1352` | `L_news.php:90`, 255, 1164 | nama unik di antara status 1 di aplikasi (:1326) |
| `news` | `L_news.php:866-930`, 1147-1157 | `L_news.php:35-153`, 202-231; `libraries/hr/L_notification.php:460-471`, 2893-3100, 3262 | `show_notif` hanya di notifikasi |
| `news_flag` | `L_news.php:351-460`, 561-693, 996-1105 | `core/MY_Controller.php:119-124`, `controllers/hr/News.php:19` | arti = belum dibaca |
| `jenis_dibo` | — (hanya dibaca) | `libraries/hr/L_dibo.php:36`, 51 | seed dari D1 |
| `dibo` | `L_dibo.php:599-608`, 238, 455, 513 | `L_dibo.php:12-66` | — |
| `form_digital` | `L_announcement.php:2615-2632` (`sp_fd`), 2421, 2483, 2535 | `L_announcement.php:2343-2347`, `MY_Controller.php:247-250` | `*_20220907` (:2880-3363) mati |
| `user_form_digital` | `L_announcement.php:2432`, 2494-2495; dihapus saat submit `controllers/hr/Announcement.php:584` | `MY_Controller.php:246-250`, `Local.php:4299-4305` | — |
| `filled_form_digital` | `Announcement.php:511-518`, 567, 580-581 | `Announcement.php:495`, `MY_Controller.php:252-255`, `L_announcement.php:2822-2828` | cek-lalu-insert tanpa kunci (race) |
| `skl` | `Announcement.php:697-707` | `Announcement.php:679`, `libraries/hr/L_survey.php:22-29` | cek-lalu-insert (race) |

## 2a. Pencocokan D1 Fase 7 (11-11-2026)

Sumber: hasil `ambil_d1_fase7.sql` blok 1–10 dari **D1 dari salinan dev** legacy (server dev, bukan produksi;
struktur dan agregat, tanpa NIP), di luar repo. SHOW CREATE lengkap 13/13.
**Struktur** dipakai sebagai acuan skema. **Semua angka di bagian ini (jumlah, ganda, yatim, sebaran) adalah angka dev;
verifikasi ulang di produksi saat cutover/impor** dengan kueri audit Bagian 6.

**Temuan umum:**
- 13/13 tabel ada, semuanya InnoDB.
- Kelompok ganda = 0 (angka dev) untuk semua kunci UNIQUE v2, termasuk `skl (nip,jenis_rwy,id_rwy)` dan `news_flag (id_user,id_news)` yang di legacy belum UNIQUE. UNIQUE v2 aman untuk impor.
- Baris yatim = 0 (angka dev) untuk semua relasi yang dicek.
- Collation: `dibo`, `news`, `news_category`, `news_flag` di legacy memakai `utf8mb4_0900_ai_ci`, sisanya `utf8mb4_unicode_ci`. v2 tetap `utf8mb4_unicode_ci` (standar proyek). **Impor:** konversi eksplisit saat membaca; perbandingan string antar-collation jangan dilakukan di SQL lintas DB.
- Volume besar (angka dev): `user_popup` ±1,2 juta baris, `user_banner` ±250 ribu. **Impor bertahap** (batch per induk), lihat Bagian 10.
- Trigger `news.news_beUpd`: status 0 → 1/2 mengisi `show_notif = 1` dan `notif_date = NOW()`. **v2 tanpa trigger**; service persetujuan/penolakan berita (Jalur A) wajib mengisi kedua kolom dalam UPDATE yang sama.

**Disesuaikan ke D1 (draf diubah 11-11):**

| Tabel.kolom | Sebelum | Sesudah | Label |
|---|---|---|---|
| `popup/banner.order`, `form_digital.order` | DEFAULT 0 | DEFAULT 1 | [K] |
| `popup/banner.url` | VARCHAR(500) | VARCHAR(255) | [K] |
| `news_category.allow_ap` | 0/1, DEFAULT 0 | 1 ya / 2 tidak, DEFAULT 2 (legacy ENUM '1','2') | [K] |
| `news.show_notif` | NULL, CHECK 1/2/3 | NOT NULL DEFAULT 0, CHECK 0–3 (0 = belum ada notifikasi) | [K] |
| `news.notif_date` | tidak ada | DATETIME NULL (diisi service, pengganti trigger) | [K] |
| `news_flag.created_at` | tidak ada | DATETIME NULL DEFAULT CURRENT_TIMESTAMP | [K] |
| `form_digital.form_digital`, `filled_form_digital.response` | MEDIUMTEXT | LONGTEXT | [K] |
| `popup.tgl_*` DATETIME; `banner/form_digital.tgl_*` DATE | ⚑ | dikunci | [K] |
| `news.id_news_category` | NOT NULL | NULL-able (legacy FK SET NULL); wajib di form v2 | [K] + validasi aplikasi (Q10) |
| `jenis_dibo.jenis_dibo` | VARCHAR(150) | VARCHAR(255) | [K] |
| `jenis_dibo.keterangan` | tidak ada | TEXT NULL | [K] |
| `dibo.id_jenis_dibo`, `nomor`, `tahun` | NOT NULL; nomor VARCHAR(150); tahun SMALLINT | NULL-able; nomor VARCHAR(50); tahun YEAR; wajib di form v2 | [K] + validasi aplikasi (Q12) |
| `dibo.tentang`, `dibo.keterangan` | TEXT | MEDIUMTEXT | [K] |
| `skl` | ⚑ | cocok: jenis_rwy/rate TINYINT, id_rwy INT, kritik_saran TINYTEXT→TEXT (superset); `rate` tanpa DEFAULT (legacy DEFAULT 1 tidak dipakai, nilai wajib dikirim) | [K]/[V2] |

**Tetap berbeda dari legacy (deviasi v2 yang disengaja):**

| Hal | Legacy | v2 | Alasan |
|---|---|---|---|
| PK tabel anak `user_popup/user_banner/user_form_digital/filled_form_digital` | PK komposit (nip, id_induk) | PK sintetis + UNIQUE (id_induk, nip) + KEY nip | DBV-032-Q5; dipakai Penargetan Jalur A. Ganda = 0, impor aman |
| `news_flag` | tanpa PK/UNIQUE | PK sintetis + UNIQUE (id_user, id_news) | ganda = 0 |
| `skl (nip,jenis_rwy,id_rwy)` | KEY biasa | UNIQUE | Q7-21 idempoten; ganda = 0 |
| FK NIP | CASCADE/CASCADE | RESTRICT/RESTRICT | registry `TabelAnakNip`, koreksi NIP lewat aplikasi |
| FK `filled_form_digital → form_digital` | CASCADE | RESTRICT | hapus form = lunak (Q7-19) |
| FK `news_flag.id_user → pengguna` | CASCADE | RESTRICT | pengguna v2 dihapus lunak |
| Tipe PK/FK | `int` | `INT UNSIGNED` | konvensi v2; nilai legacy positif |
| `keterangan` | TINYTEXT | TEXT | superset, impor aman |
| Tipe `jenis_dibo.id_jenis_dibo` | TINYINT | INT UNSIGNED | konvensi v2; 8 baris |
| FK `dibo → jenis_dibo`, `news → news_category` | ON DELETE SET NULL | RESTRICT | master dihapus lunak (status 10), jadi DELETE fisik tidak terjadi |
| `target_uns` | VARCHAR(255) | MEDIUMTEXT + CHECK JSON | superset, impor aman |
| `news_category.status` | 0 nonaktif / 1 aktif | 1/2/10 | 0 → 2 saat impor |
| `news.category`, `news.unit_satker_target` | salinan nama | tidak dibawa | DBV-032-Q2 |
| Kolom konstan popup/banner/form (`target`, `type`, `id_unit_satker`, `unit_satker`) dan `*_desc` | ada | tidak dibawa | Q7-03/Q7-47; lihat DBV-032-Q9 |

## 3. Skema v2 usulan

DDL lengkap ada di `app/Database/MigrationsDitahan/CreateKontenInteraksiFase7.php`. Ringkasan per tabel tercantum di bawah. Selain yang disebut, setiap tabel induk memakai kolom audit v2 `created_at`, `updated_at`, `created_by`, `updated_by`.

### 3.1 `popup` [K-kode]
| Kolom | Tipe | Label | Catatan |
|---|---|---|---|
| `id_popup` | INT UNSIGNED AI | [K-kode] | PK |
| `tgl_mulai`, `tgl_akhir` | DATETIME NOT NULL | [K-kode] ⚑ | jam:menit WIB; CHECK `tgl_akhir >= tgl_mulai` [V2] |
| `order` | INT NOT NULL DEFAULT 0 | [K-kode] | urutan tampil |
| `url` | VARCHAR(500) NULL | [K-kode] ⚑ | |
| `file` | VARCHAR(255) NOT NULL | [V2] | kunci storage; wajib saat tambah (legacy) |
| `keterangan` | TEXT NULL | [K-kode] ⚑ | |
| `target_uns`, `target_peg`, `excl_peg` | MEDIUMTEXT NULL + CHECK JSON | [K-kode] | `target_peg`/`excl_peg` = JSON array NIP → `JsonArrayNip` (Bagian 10) |

Tidak dibuat:
- `target`, `type`, `id_unit_satker`, `unit_satker`: selalu konstan di versi aktif (Q7-03).
- `target_uns_desc`, `target_peg_desc`, `excl_peg_desc`: berisi "NIP – nama", NIP-nya tidak tertangkap koreksi NIP, jadi nama diturunkan saat baca (Q7-47).
- `dilihat_at` (Q7-02).

### 3.2 `user_popup` [K-kode]+[K-reg]
`id_user_popup` PK [V2] ⚑, `id_popup` (FK CASCADE), `nip` VARCHAR(30) (`fk_nip_userpopup_to_pegawai` [K-reg]),
**UNIQUE (`id_popup`,`nip`)** [V2], index (`nip`). Snapshot saat simpan (Q7-15); ubah popup = sinkron selisih (PRD §5.3).

### 3.3 `banner` / 3.4 `user_banner`
Sama dengan popup, dengan perbedaan:
- `tgl_mulai/tgl_akhir` bertipe **DATE** [K-kode `L_announcement.php:1572-1574`] ⚑.
- `user_banner` memakai `fk_nip_usbanner_to_pegawai` [K-reg] dan **UNIQUE (`id_banner`,`nip`)**.

### 3.5 `news_category`
- `category` VARCHAR(150) ⚑ dan `order` INT.
- `allow_ap` TINYINT 0/1 (CHECK), artinya boleh dipakai role 3.
- `keterangan` TEXT NULL.
- `status` 1/2/10 [V2]. Legacy memakai 1/0, sehingga impor memetakan 0 → 2.
- Nama unik di antara kategori aktif tetap dijaga aplikasi. Alasannya, UNIQUE DB tidak bisa bersyarat status (DBV-032-Q4).

### 3.6 `news`
| Kolom | Tipe | Label | Catatan |
|---|---|---|---|
| `id_news_category` | INT UNSIGNED NOT NULL | [K-kode] | FK RESTRICT |
| `title` | VARCHAR(255) | [K-kode] ⚑ | |
| `news` | MEDIUMTEXT | [K-kode] ⚑ | HTML terbatas, dibersihkan server (Q7-10) |
| `lampiran` | VARCHAR(255) NULL | [V2] | kunci storage |
| `publish_at` | DATETIME | [K-kode] | |
| `status` | TINYINT DEFAULT 0, CHECK 0/1/2 | [K] | 0 menunggu, 1 terbit, 2 ditolak |
| `show_notif` | TINYINT NULL, CHECK NULL/1/2/3 | [K-kode] ⚑ | 1 baru, 3 dibaca, 2 dihapus pembuat; **DEFAULT legacy ⚑ D1** (`L_news::process` tidak mengisinya) |
| `target` | TINYINT DEFAULT 1, CHECK 1/2/3 | [K-kode] | 1 kementerian, 2 unit, 3 satker |
| `id_unit_target`, `id_satker_target` | INT NULL | [K-kode] ⚑ | tanpa FK (DBV-032-Q3) |
| `ul_creator`, `id_unit_creator`, `id_satker_creator` | TINYINT / INT NULL | [K-kode] ⚑ | role + lingkup pembuat (role 3) |
| `approved_at`, `approved_by`, `reason_note` | DATETIME / INT UNSIGNED / TEXT, NULL | [K-kode] | persetujuan (Q7-08) |

Index: (`status`,`publish_at`), (`id_news_category`).
Tidak dibuat: salinan nama `category` dan `unit_satker_target`, karena keduanya bisa basi dan diturunkan saat baca. Kolom ini menunggu konfirmasi Jalur A (DBV-032-Q2).

### 3.7 `news_flag`
`id_news_flag` PK [V2] ⚑, `id_user` → `pengguna.id_pengguna` (RESTRICT), `id_news` → `news` (CASCADE),
**UNIQUE (`id_user`,`id_news`)** [V2]; index (`id_news`). Nama kolom `id_user` [K-kode] dipertahankan.

### 3.8 `jenis_dibo` / 3.9 `dibo`
- `jenis_dibo`: `jenis_dibo` VARCHAR(150) ⚑, `order`, `status` 1/2/10 (Q7-11). Isinya di-seed dari D1 (G7-2).
- `dibo`:
  - `id_jenis_dibo` (FK RESTRICT);
  - `nomor` VARCHAR(150) ⚑, `tahun` SMALLINT UNSIGNED ⚑ (legacy year/varchar), `tentang` TEXT ⚑, `keterangan` TEXT NULL;
  - `file_1` NOT NULL, `file_2`/`file_3` NULL;
  - `status` 1 aktif / 2 dicabut [K-kode] / 10 dihapus [V2];
  - index (`id_jenis_dibo`,`status`). Pegawai hanya melihat `status=1` (Q7-13).

### 3.10 `form_digital`
| Kolom | Tipe | Label | Catatan |
|---|---|---|---|
| `judul_form` | VARCHAR(255) | [K-kode] ⚑ | |
| `tgl_mulai`, `tgl_akhir` | DATE | [K-kode] ⚑ | CHECK periode [V2] |
| `order` | INT | [K-kode] | |
| `form_digital` | MEDIUMTEXT + CHECK JSON_VALID | [K-kode]+[V2] | **skema form v2** (Q7-18: teks, paragraf, pilihan tunggal/ganda, tanggal, angka, berkas); legacy = JSON formBuilder → konverter impor |
| `keterangan` | TEXT NULL | [K-kode] ⚑ | |
| `status` | TINYINT DEFAULT 1, CHECK 1/2/10 | [V2] | hapus = 10 (Q7-19) |
| `deleted_at` | DATETIME NULL | [V2] | |
| `target_uns`, `target_peg`, `excl_peg` | MEDIUMTEXT NULL + CHECK JSON | [I] ⚑ | form legacy memilih target dengan pola yang sama dengan popup (`target_unit_satker[]`/`target_pegawai[]`, `L_announcement.php:2637-2815`). Apakah pilihan itu juga disimpan di `form_digital` belum terlihat → D1 (DBV-032-Q1) |

Index: (`status`,`tgl_mulai`,`tgl_akhir`).
Tidak dibuat: `target`, `id_unit_satker`, `unit_satker`, yang di versi aktif selalu konstan atau NULL (selaras dengan Q7-03).
Kunci pertanyaan untuk form yang sudah dijawab (Q7-25) ditegakkan di aplikasi, bukan di DB.

### 3.11 `user_form_digital`
- `id_user_form_digital` PK [V2] ⚑, `id_form_digital` (FK CASCADE), `nip` (`fk_nip_user_fd_to_pegawai` [K-reg]), `created_at`.
- **UNIQUE (`id_form_digital`,`nip`)** [V2], index (`nip`).
- **Permanen** (Q7-20): legacy menghapus baris target saat pegawai submit, sedangkan v2 tidak. "Belum mengisi" dihitung sebagai target dikurangi yang sudah mengisi.

### 3.12 `filled_form_digital`
- `id_filled_form_digital` PK [V2] ⚑, `id_form_digital` (FK **RESTRICT**), `nip` (`fk_nip_ffd_to_pegawai` [K-reg]).
- `response` MEDIUMTEXT + CHECK JSON_VALID ⚑: jawaban per id pertanyaan; berkas disimpan sebagai kunci storage.
- `created_at`, `created_by`.
- **UNIQUE (`id_form_digital`,`nip`)** [V2]: submit ganda ditolak 409 (Q7-17) dan dijamin DB, menutup race cek-lalu-insert legacy `Announcement.php:495 → 581`.
- Tanpa `updated_*`, karena jawaban tidak bisa diubah.

### 3.13 `skl`
| Kolom | Tipe | Label | Catatan |
|---|---|---|---|
| `id_skl` | INT UNSIGNED AI | [K-kode] | |
| `nip` | VARCHAR(30) | [K-reg] | `fk_nip_skl_to_peg` |
| `jenis_rwy` | TINYINT, CHECK 1–5 | [K-kode] | 1 cuti, 2 KARIS/KARSU, 3 KARPEG, 4 TB, 5 IB (`L_survey.php:15-21`); lookup master v2 (Q7-22) |
| `id_rwy` | INT NOT NULL | [K-kode] | PK `riwayat_*` (INT di v2); polimorfik → **tanpa FK** (pola DBV-022) |
| `rate` | TINYINT, CHECK 1–5 | [K-kode] | `survey_kepuasan.php:28-37` |
| `kritik_saran` | TEXT NULL | [K-kode] ⚑ | |
| `created_at`, `created_by` | | [K-kode] | |

**UNIQUE (`nip`,`jenis_rwy`,`id_rwy`)** [V2]: SKL ganda dibalas 200 idempoten (Q7-21) dan DB menjamin tidak ada duplikat. Index: (`jenis_rwy`,`created_at`) untuk rekap dan rata-rata per jenis (Q7-26).

### 3.14 Ringkasan konstrain
| Tabel | UNIQUE | FK | CHECK |
|---|---|---|---|
| `popup` | — | — | periode, 3× JSON |
| `user_popup` | (id_popup, nip) | popup CASCADE; nip `fk_nip_userpopup_to_pegawai` | — |
| `banner` | — | — | periode, 3× JSON |
| `user_banner` | (id_banner, nip) | banner CASCADE; nip `fk_nip_usbanner_to_pegawai` | — |
| `news_category` | — | — | allow_ap, status |
| `news` | — | news_category RESTRICT | status, show_notif, target |
| `news_flag` | (id_user, id_news) | pengguna RESTRICT; news CASCADE | — |
| `jenis_dibo` | — | — | status |
| `dibo` | — | jenis_dibo RESTRICT | status |
| `form_digital` | — | — | skema JSON, periode, status, 3× JSON |
| `user_form_digital` | (id_form_digital, nip) | form CASCADE; nip `fk_nip_user_fd_to_pegawai` | — |
| `filled_form_digital` | (id_form_digital, nip) | form RESTRICT; nip `fk_nip_ffd_to_pegawai` | response JSON |
| `skl` | (nip, jenis_rwy, id_rwy) | nip `fk_nip_skl_to_peg` | jenis_rwy, rate |

## 4. Pemakaian aplikasi (setelah approve)

| Tabel | Penulis | Catatan kontrak |
|---|---|---|
| popup/banner/news/dibo | Jalur A (`Libraries/Dashboard/Konten/**`) | sinkron selisih target, bukan hapus-isi ulang (PRD §5.3) |
| form digital/skl | Jalur B (`Libraries/Dashboard/Interaksi/**`) | 409 dari 1062 `uk_filled_form_digital_id_fd_nip`; SKL 1062 → 200 idempoten + `rated=1` satu transaksi |
| Koreksi NIP | `NipCascadeService` | 5 FK NIP + 4 kolom `JsonArrayNip` (Bagian 10) |

## 5. Pra-cek

- Migration hanya `CREATE TABLE`, jadi tabel lama tidak tersentuh dan tidak ada risiko kunci tabel besar.
- `up()` yang gagal men-DROP tabel yang sudah terbuat (urutan anak dulu). `down()` men-DROP ketiga belas tabel.
- Induk FK yang dirujuk (`pegawai.nip`, `pengguna.id_pengguna`) sudah ada di main.
- Nama FK NIP sudah dicatat registry, sehingga `NipReferenceRegistryTest` mengenalinya begitu tabel dipindah ke `Migrations/`.

## 6. Kueri audit baca-saja

`docs/db-review/sql/DBV-032-audit-konten-interaksi.sql` berisi 28 SELECT agregat, tanpa isi baris. Berkas ini dijalankan di **salinan** legacy saat gladi/freeze.

| Bagian | Butir | Kelas |
|---|---|---|
| 1 | Ganda pada 6 kunci UNIQUE [V2] | WAJIB_0: syarat UNIQUE; > 0 → keputusan DBV (aturan impor "simpan terbaru" atau UNIQUE ditahan) |
| 2 | Domain CHECK (skl.rate/jenis_rwy, news.status/target, allow_ap), periode terbalik, NIP kosong/> 30 | WAJIB_0 |
| 3 | Yatim anak→induk (7) dan NIP yatim (5 tabel) | INFO: dibuang + dicatat saat impor |
| 4 | `filled` tanpa baris target (impor menambah target, Q7-20), SKL menunjuk riwayat yang tidak ada, nama kategori ganda | INFO |

- Struktur, jumlah baris, indeks, FK, sebaran kolom yang hanya ada di legacy, dan isi master `jenis_dibo` diambil oleh kueri D1 Fase 7 milik Horii (G7-2).
- Berkas audit ini hanya memakai kolom yang ada di legacy **dan** di v2, sehingga test bisa menjalankannya di skema v2.

## 7. Verifikasi developer

### 7.1 Hasil (10-11-2026) — DB scratch `simpeg_v2_t_f7b`, MySQL 8.0.30 port 3307

| Butir | Hasil |
|---|---|
| `tests/database/Dashboard/SkemaKontenInteraksiFase7Test.php` | ✅ 6 test, 171 assertion |
| PHPStan (berkas baru) | ✅ No errors |
| PHP-CS-Fixer (berkas baru) | ✅ 0 berkas |
| MariaDB | ⏳ lewat CI (kode CHECK 4025 sudah ditangani test) |

### 7.2 Isi test skema
- **Struktur & collation:** InnoDB/`utf8mb4_unicode_ci` untuk 13 tabel, `nip` VARCHAR(30), kolom JSON MEDIUMTEXT, `show_notif`, dan `id_rwy` INT.
- **Kolom yang sengaja tidak ada:** `dilihat_at`, kolom konstan, `*_desc`. `inbox` tidak termasuk.
- **Nama FK NIP:** sama dengan registry.
- **Galat yang diharapkan:**
  - UNIQUE: 1062 untuk ×6 kunci;
  - CHECK: 3819/4025 untuk periode, JSON, status/news/show_notif/allow, dibo, form, response, skl rate/jenis;
  - FK: 1452 untuk induk/NIP/pengguna tidak ada; 1451 saat menghapus master, form ber-jawaban, atau pegawai.
- **CASCADE:** anak ikut terhapus ketika popup/banner/form/news dihapus.
- **Siklus:** `down()` lalu `up()` kembali.
- **Audit:** kueri audit berjalan (28 kueri, kolom `butir`/`jumlah`).

## 8. Rencana test skema (setelah approve)

- Test dipindah dari draf menjadi permanen (`require_once` dihapus).
- `NipReferenceRegistryTest` + `TabelAnakNipTest` ditambah 4 entri `JsonArrayNip`.
- Uji `NipCascadeService` pada popup/banner/form_digital tanpa kolom `*_desc` (Bagian 10).

## 9. Keputusan yang diminta dari DB Validator

| # | Pertanyaan | Usulan |
|---|---|---|
| DBV-032-Q1 | `form_digital` menyimpan pilihan target (JSON) atau tidak | ✅ D1: legacy `form_digital` memiliki `target_uns`/`target_peg`/`excl_peg`; disimpan seperti popup [K] |
| DBV-032-Q2 | Salinan nama `news.category` / `news.unit_satker_target` | Tidak dibawa; nama diturunkan dengan join ke `news_category`/`unit`/`satker` (target kementerian memakai `web_config`) [V2]. **Disetujui Jalur A 10-11** (tanpa kebutuhan histori); catatan impor di Bagian 10 |
| DBV-032-Q3 | FK `news.id_unit_target/id_satker_target/id_*_creator` ke master unit/satker | Tanpa FK di draf; semua kolom NULL-able [K-kode]: target kementerian = NULL (`L_news::set_param` 866-930; '0' hanya ada di input form, tidak disimpan); `id_*_creator` = NULL untuk role 1 dan role 3 tanpa satker. Tipe ikut D1; FK dipasang bila D1 membuktikan yatim = 0. Disetujui Jalur A 10-11; **keputusan akhir DB Validator** |
| DBV-032-Q4 | Nama kategori unik | Aplikasi saja, karena status 2/10 boleh berulang |
| DBV-032-Q5 | PK sintetis tabel anak | D1: legacy PK komposit (nip, id_induk); `news_flag` tanpa PK. Usulan tetap PK sintetis + UNIQUE [V2] (impor tidak memakainya; ganda = 0) |
| DBV-032-Q6 | CHECK `JSON_VALID` di kolom target/skema/jawaban | Dipakai (MySQL 8 & MariaDB); impor menormalkan `addslashes` lebih dulu |
| DBV-032-Q7 | Tipe tanggal popup (DATETIME) vs banner/form (DATE) | ✅ Dikunci D1 11-11 (sesuai draf) |
| DBV-032-Q8 | `news.show_notif` DEFAULT | ✅ D1: NOT NULL DEFAULT 0; draf disesuaikan (CHECK 0–3) + `notif_date` ditambahkan |
| DBV-032-Q9 | D1 menunjukkan `popup.target` bervariasi (1/2/3) dan ada 1 popup `type` = 2 (video); `banner.target` 1/3; `form_digital.target` 1/3 | Usulan: tetap tidak dibawa (Q7-03 [V2]). `target` dapat diturunkan dari isi `target_uns`/`target_peg` saat impor (⚑ dicek pada data impor); popup video tunggal dicatat sebagai pengecualian impor. **Keputusan reviewer/Horii** karena menyentuh keputusan PRD Q7-03 |
| DBV-032-Q10 | `news.id_news_category` legacy NULL-able (FK SET NULL) | Kolom NULL-able [K]; wajib di form/service v2. Baris impor NULL dibawa apa adanya dan tampil "Tanpa kategori" (Jalur A). Hitungan: audit `INFO news tanpa kategori (NULL)` |
| DBV-032-Q12 | `dibo.id_jenis_dibo`/`nomor`/`tahun` NULL-able di legacy, wajib di form v2 | Kolom NULL-able [K]; validasi wajib hanya di form/service v2 (create/edit). Baris impor NULL dibawa apa adanya tanpa nilai karangan; edit baris itu meminta pengguna melengkapinya. Hitungan: audit `INFO dibo kolom NULL` (mintakan di salinan dev/produksi) |
| DBV-032-Q11 | Legacy memasang FK `news` ke `unit`, `satker`, `user_level`, `pengguna` (ON DELETE SET NULL) | Relevan untuk Q3: data legacy bersih karena FK. Usulan tetap tanpa FK di v2 (konsisten `*_by` tanpa FK; unit/satker v2 berstatus lunak); keputusan DB Validator |

## 10. Langkah pemindahan (setelah approve + izin Horii)

1. Migration dipindah ke `Migrations/<timestamp>_CreateKontenInteraksiFase7.php` tanpa perubahan DDL dari versi yang disetujui. Bila ada koreksi D1, itu dibuat sebagai commit terpisah dan direview lebih dulu.
2. Registry NIP: tambah `JenisRujukanNip::JsonArrayNip` untuk `popup.target_peg/excl_peg` dan `banner.target_peg/excl_peg`. Bila Q1 = simpan, tambahkan juga `form_digital.target_peg/excl_peg`. `NipCascadeService` disesuaikan agar tidak mewajibkan kolom `<kolom>_desc` (Q7-47).
3. Definisi tier impor Fase 7 di `Libraries/Impor` dibuat aditif. Isinya:
   - urutan induk → anak;
   - transformasi `stripslashes`/JSON;
   - path → kunci storage;
   - status 0 → 2;
   - kolom legacy `news.category` dan `news.unit_satker_target` (salinan nama, [V2] tidak dibawa, Q2) dilewati;
     `id_news_category`/`id_*_target` yang dipakai;
   - target `user_form_digital` dari `filled`.
   - **D1 11-11:** `news_category.allow_ap` ENUM '1'/'2' → TINYINT 1/2; `news.show_notif` 0 tetap 0; `news.status` ENUM → TINYINT;
     baca 4 tabel ber-collation `utf8mb4_0900_ai_ci` dengan konversi eksplisit;
   - `user_popup` (±1,2 juta) dan `user_banner` (±250 ribu) diimpor **bertahap per induk** dalam batch, dengan
     `INSERT IGNORE`-setara (UNIQUE) agar bisa diulang.
   Eksekusinya mengikuti tiket impor Q7-24.
4. `docs/progress/07-Dashboard.md` diperbarui.

## 11. Syarat merge (⛔)

- D1 Fase 7 diterima dan kolom ⚑ dikunci (G7-2).
- Audit Bagian 1–2 = 0 di salinan legacy, atau ada keputusan DBV untuk butir > 0.
- DB Validator APPROVE, CI hijau, izin Horii; merge oleh reviewer CR.

## 12. Checklist DB Validator

- [ ] Kolom & tipe cocok D1 (⚑)
- [ ] Nama FK NIP = registry
- [ ] UNIQUE/CHECK sesuai PRD §5.1 dan audit = 0
- [ ] CASCADE/RESTRICT sesuai Bagian 1
- [ ] `inbox`/`inbox_msg` tidak tersentuh
- [ ] Tidak ada kolom NIP tanpa klasifikasi registry
- [ ] Kueri audit hanya SELECT
