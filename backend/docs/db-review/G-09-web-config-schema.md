# DB Validator Review — G-09 Web Config (`web_config`, skema legacy)

**Key review:** `DBV-006` (review DB Validator, skema) + `CR-030` (review kode) — satu pull request, judul `[DBV-006][CR-030] …`, branch `dbv-006/g09-web-config` (berbasis `main` `cc553be`). Alur CR+DBV: DB Validator me-review/approve, merge oleh reviewer CR setelah kedua review setuju.

**Status:** ⏳ **MENUNGGU REVIEW DB VALIDATOR (DBV-006) DAN REVIEW KODE (CR-030).** Belum ada keputusan DB Validator di dokumen ini. Kolom **Keputusan** di Bagian 4 masih kosong (⏳); isi Bagian 6 adalah verifikasi developer sebelum review, **bukan** hasil review DB Validator. Migration jangan dijalankan di Dev/Production sebelum disetujui.

**Rujukan:** `02-MasterData.md` G-09 ("CRUD key-value (tarif tukin, uang makan, jam puasa, header PDF, dsb)", "tipe data per key distandarkan", "perubahan value langsung ter-reflect (cache invalidate)"; Exit Fase 2: unit test G-09 tipe data config), Tech Spec G-09 + MTC-012, Mapping Migrasi Tier 1 baris `web_config` ("cek isi tiap key — standarkan tipe data per key"), Matriks Role x Endpoint Modul G (`hr/master/web_config/*` = role 1), DDL struktur produksi `simpeg01_struktur_lengkap_20261001.sql:7622-7631`, riset katalog J4.04 (`J4-04_katalog_web_config.md`, di luar repo), kode legacy (`controllers/hr/master/C_web.php`, `libraries/hr/master/Lm_web.php`, `libraries/hr/L_presensi.php`, `libraries/hr/Lsl_gaji.php`, `controllers/services/S_dm.php`, `core/MY_Controller.php`, aplikasi mudig).

**Label sumber:** **[K]** terkonfirmasi — DDL struktur produksi (nomor baris) atau kode legacy (`berkas:baris`); **[V2]** tambahan/ubahan v2 yang disengaja (alasan di Bagian 3); **[I]** dugaan (wajib dikonfirmasi). Rujukan baris kode legacy diambil dari salinan lokal repo legacy (HEAD `39b6b15`, pascabaseline), **belum dicocokkan dengan kode yang berjalan di produksi** (AS-09).

| File | Isi |
|---|---|
| `app/Database/Migrations/2026-10-01-100000_CreateWebConfig.php` | CREATE `web_config` (SQL mentah sadar prefix), `down()` = DROP |
| `tests/MasterData/WebConfigSchemaTest.php` | skema hasil migration vs Bagian 2.1 (`information_schema`), UNIQUE peka/tidak peka huruf, rollback |

Kode aplikasi CR-030 (review kode; perilaku di Bagian 2.3):

| File | Isi |
|---|---|
| `app/Config/WebConfig.php` | katalog 35 key bertipe (tipe, bawaan, batasan, label) — Bagian 2.2 |
| `app/Libraries/MasterData/WebConfigTypes.php` | normalisasi + validasi per tipe, konversi nilai bertipe, validasi katalog |
| `app/Libraries/MasterData/WebConfigImport.php` | aturan impor satu baris legacy (Bagian 5) |
| `app/Libraries/MasterData/WebConfigService.php`, `app/Models/MasterData/WebConfigModel.php`, `app/Controllers/Api/MasterData/WebConfigController.php`, `app/Config/Routes.php` (blok DBV-006), `app/Config/Services.php` | endpoint `api/v1/web-config` (role 1) + `value()`/`values()` untuk modul lain, cache + invalidasi |
| `tests/MasterData/WebConfigTest.php`, `tests/unit/Libraries/WebConfigTypesTest.php` | test fitur + unit tipe data |
| `frontend/src/features/master-data/…` (`webConfig.*`, `WebConfigView/Page`, `WebConfigFormDialog`), `frontend/src/router/index.ts`, `frontend/src/shared/components/AppShell.vue` | halaman admin `/web-config` (role 1), menu ⋮ |

## 1. Latar belakang

DDL [K] (`simpeg01_struktur_lengkap_20261001.sql:7622-7631`): `web_config` (`id_web_config` INT AUTO_INCREMENT, `config_name` VARCHAR(255) NOT NULL, `config_value` TEXT NULL, `remark` VARCHAR(255) NULL, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, `updated_by` INT NULL), PK + **KEY non-unik** `config_name`, `AUTO_INCREMENT=37`, collation `utf8mb4_0900_ai_ci`. Nilai baris tidak tersedia (dump struktur saja).

Perilaku legacy yang memengaruhi skema [K]:
- **Satu-satunya penulis:** CRUD admin `hr/master/c_web` (`C_web.php:11, 38, 82, 134`, semua `userAuth(['1'])`). Keunikan `config_name` hanya dicek aplikasi (`Lm_web.php:176-195`), tidak di DB. Remark, nama, dan nilai wajib diisi di form (`Lm_web.php:161-172`).
- **Nilai tersimpan ter-`addslashes`:** `set_param_config` meng-`addslashes` `config_name`, `config_value`, `remark` (`Lm_web.php:204-210`). Sebagian pembaca memanggil `stripslashes`, sebagian tidak.
- **`updated_by`** diisi saat tambah maupun ubah (`Lm_web.php:210`), tidak ada `created_*`. Hapus = hard DELETE (`Lm_web.php:134`); tombol hapus legacy rusak karena memanggil `lm_web->delete()` yang tidak ada (`C_web.php:148`).
- **Tidak ada tipe:** semua nilai teks bebas; tipe tersirat dari cara kode memakainya (Bagian 2.2).
- **Pembaca:** seluruh baris dimuat ke sesi pada setiap request yang login (`core/MY_Controller.php:34-42`), ditambah query langsung per key. Aplikasi **mudig** membaca `simpeg01.web_config` langsung di setiap request (`html/mudig/application/core/MY_Controller.php:18-25`). Endpoint **publik tanpa auth** `services/s_dm/p_web_config` (`S_dm.php:266-286`) membocorkan semua nilai.

## 2. Skema & katalog hasil DBV-006

### 2.1 `web_config` — `simpeg01_struktur_lengkap_20261001.sql:7622-7631`

| Kolom / atribut | Legacy [K] | v2 | Label |
|---|---|---|---|
| `id_web_config` | INT NOT NULL AUTO_INCREMENT, PK | sama | [K] |
| `config_name` | VARCHAR(255) NOT NULL | sama | [K] |
| `config_value` | TEXT NULL | sama (aplikasi v2 tidak menulis NULL/kosong) | [K] |
| `remark` | VARCHAR(255) NULL | sama | [K] |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | sama (aplikasi mengisi waktu UTC, pola master v2) | [K] |
| `updated_by` | INT NULL | sama + COMMENT `id_pengguna yang terakhir mengubah` | [K] + COMMENT [V2] |
| index `config_name` | KEY (non-unik) | **UNIQUE KEY `config_name`** (nama index legacy dipertahankan) | [V2] — Bagian 4 #2 |
| collation | `utf8mb4_0900_ai_ci` | `utf8mb4_unicode_ci` (tabel + 3 kolom string) | [V2] — DBV-010 |
| `created_at`/`created_by`/`status`/`order` | tidak ada | tidak ditambahkan | [K] — Bagian 4 #4 |
| kolom tipe | tidak ada | **tidak ditambahkan**; tipe di katalog kode | Bagian 4 #3 |
| FK | tidak ada | tidak ada (`updated_by` → `pengguna` dijaga aplikasi, pola master v2) | [K] |
| `AUTO_INCREMENT` awal | 37 | tidak ditulis (impor memakai ID legacy) | — |

`SHOW CREATE TABLE` hasil migration ada di Bagian 6.2.

### 2.2 Katalog key bertipe (`Config\WebConfig`)

Riset J4.04 mencatat 24 key baseline + 1 key Slip Gaji dan memperkirakan ±11 key "hanya ada di data" dari `AUTO_INCREMENT=37`. **Temuan DBV-006:** 10 dari key itu dibaca kode dengan pola `config_name IN (…)` yang tidak tertangkap riset: `TL1/PSW1`, `TL2/PSW2`, `TL3/PSW3`, `TA`, `TK`, `LKH`, `uang_makan_gol_1..4` (`L_presensi.php:1483, 3990, 5495, 7044, 7814, 9729, 11812, 13554, 15513, 17532, 19279`; dipakai a.l. `:2102-2194` potongan tukin dan `:4025` uang makan PNS). Total katalog **35 key**; dengan `AUTO_INCREMENT=37` sisa ±1 baris yang mungkin pernah dibuat/dihapus — tetap diminta daftar `config_name` (tanpa nilai) dari DBA (J1.03) sebelum impor.

Catatan MTC-012: kasus uji menyebut `tarif_uang_makan_pns`; key legacy sebenarnya `uang_makan_gol_1..4` (per golongan; PPPK dari `gol_pppk.uang_makan`). Kasus uji perlu disesuaikan QA.

| Kelompok | Key | Tipe v2 | Bawaan | Sumber pemakaian [K] |
|---|---|---|---|---|
| Identitas | `nama_kementerian` | text ≤255 | `''` | banyak (J4.04 #1), mis. `Cron.php:471`, `L_user.php:3437` |
| | `nama_kementerian_short` | text ≤100 | `''` | `L_user.php:3442` |
| | `alamat_kementerian` | textarea | `''` | `Cron.php:476`, `L_presensi.php:2338` |
| Kop PDF | `pdf_header_nama_kementerian` | html | `''` | `L_chart.php:180`, `Lsl_gaji.php:356` |
| | `pdf_header_nama_unit` | html | `''` | `Lsl_gaji.php:357` (key Slip Gaji pascabaseline) |
| | `pdf_header_alamat_kementerian` | html | `''` | `L_chart.php:181`, `Lsl_gaji.php:358` |
| | `logo_kementerian_pdf`, `logo_wonderful_pdf` | asset_path | `''` (= logo bawaan v2) | `L_chart.php:176-177`, `Lsl_gaji.php:336, 341` |
| | `logo_kementerian_url`, `logo_wonderful_url` | url | `''` | `L_chart.php:178-179` |
| | `kantor_pdf` | text | `''` | mudig `S_cron.php:275` |
| Pejabat | `nama_menteri`, `jabatan_menteri_full`, `jabatan_menteri_singkat`, `nama_wakil_menteri`, `jabatan_wakil_menteri_singkat` | text | `''` | `Ekinerja.php:43`, `L_presensi.php:8094-8095`, `L_jabatan.php:5434-5440` |
| Acuan | `id_unit_kepegawaian`, `id_unit_inspektorat`, `id_unit_kelembagaan` (→ `unit`), `id_satker_kepegawaian` (→ `satker`), `id_jabatan_kabiro_kepegawaian` (→ `jabatan`) | id_ref | null | `L_presensi.php:1520-1522`, `function_helper.php:398`, `L_ib.php:178` |
| SK KGB | `tembusan_kppn` (textarea), `lokasi_kppn` (text) | | `''` | mudig `S_cron.php:273-274` |
| Potongan tukin (%) | `TL1/PSW1`, `TL2/PSW2`, `TL3/PSW3`, `TA`, `TK`, `LKH` | decimal 0..100, 2 desimal | null | `L_presensi.php:1483, 2102-2194` |
| Uang makan PNS (Rp) | `uang_makan_gol_1..4` | integer 0..10.000.000 | null | `L_presensi.php:1483, 4025` |
| Email ulang tahun | `email_sent_time` | time | `06:00:00` (fallback `Cron.php:483-484`) | `Cron.php:483` (cron dimatikan) |
| | `email_ultah_ad` | email_list (data pribadi) | `''` | `Cron.php:540-548` (cron dimatikan) |

Tipe [I] untuk potongan tukin: satuan persen dan jumlah desimal disimpulkan dari pemakaian (`$total += $config['TK']` lalu dipakai sebagai persen potongan); batas 2 desimal wajib dicocokkan dengan nilai produksi saat audit impor. "Jam puasa" di DoD tidak ditemukan sebagai key `web_config` di kode legacy; bila Fase 5 membutuhkannya, ditambahkan sebagai key baru (tanpa migration).

### 2.3 Perilaku aplikasi (CR-030)

- **Akses:** `api/v1/web-config` GET/PUT/DELETE, seluruhnya role 1. Tidak ada endpoint baca publik (Matriks Modul G; padanan `p_web_config` tidak dibuat). Modul lain membaca lewat `WebConfigService::value()`/`values()` di server.
- **Simpan (PUT)** = upsert per `config_name`: hanya key katalog; nilai wajib dan dinormalisasi per tipe (`WebConfigTypes`), HTML disanitasi HTMLPurifier (whitelist DBV-002). Baris baru → 201, `remark` bawaan = label katalog; tipe salah → 422 `errors.config_value` (MTC-012). Balapan dua tambah key yang sama (1062) diulang sekali sebagai ubah.
- **Hapus (DELETE)** = hard delete baris → nilai kembali ke bawaan katalog (Bagian 4 #6). Audit `delete` menyimpan before lengkap.
- **Key tak dikenal** (baris hasil impor yang tidak ada di katalog) tampil read-only di akhir daftar, hanya bisa dihapus; `value()` menolaknya.
- **Nilai tersimpan tidak sah** (mis. impor tanpa normalisasi) ditandai `valid: false`; konsumen menerima nilai bawaan, bukan nilai rusak.
- **Cache:** peta `config_name → nilai` di CacheService (TTL 3600 dtk, ADR-014), diinvalidasi setelah setiap tulis yang berhasil. Catatan: cache driver file per server; bila v2 berjalan di lebih dari satu server aplikasi, invalidasi hanya lokal (sama dengan cache dropdown master).
- **Audit:** create/update/delete tercatat di `audit_logs` (MasterModel); `updated_by` = id_pengguna aktor saat tambah dan ubah (legacy).
- **FE:** halaman `/web-config` (menu "Web Config", role 1): per kelompok, badge Bawaan/Tidak valid/Tidak dikenal, menu ⋮ Edit → Hapus (`danger`, hanya bila ada nilai tersimpan, lewat ConfirmDialog), form bertipe.

## 3. Deviasi dari legacy

| # | Deviasi | Alasan |
|---|---|---|
| 1 | Index `config_name` → UNIQUE | Keunikan hanya dijaga aplikasi legacy (cek-lalu-insert, tanpa lock); v2 menegakkannya di DB. Prasyarat impor: audit duplikat (Bagian 5 #2) |
| 2 | Collation `utf8mb4_unicode_ci` | Standar proyek (DBV-010); `0900_ai_ci` tidak ada di MariaDB 10.4. Akibat: UNIQUE tidak peka huruf besar/kecil (`TA` = `ta`) |
| 3 | COMMENT `updated_by` | Pola tabel master v2 |
| 4 | Tipe di katalog kode, bukan kolom | Bagian 4 #3 |
| 5 | Nilai disimpan bersih (tanpa `addslashes`), dinormalisasi per tipe | Mapping :40; konsumen tidak perlu `stripslashes` |
| 6 | Path logo tidak lagi path absolut server | Server legacy tidak ada di v2 (Bagian 5 #4) |

## 4. Keputusan yang diminta dari DB Validator (DBV-006)

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| 1 | Setujui skema Bagian 2.1 + migration `2026-10-01-100000_CreateWebConfig` untuk Dev | Setujui. Jalankan `migrate` → `migrate:rollback` → `migrate` sekali di MariaDB 10.4 sebelum dipakai di server | ⏳ |
| 2 | UNIQUE `config_name` (nama index legacy `config_name` dipertahankan, bukan `uq_web_config_*`) | Setujui. Nama legacy dipertahankan supaya perbandingan dengan DDL [K] hanya berbeda di NON_UNIQUE. Alternatif: ganti nama ke pola `uq_` | ⏳ |
| 3 | Tipe nilai **tidak** disimpan di tabel; katalog key bertipe di `Config\WebConfig` (kode) | Setujui. Alasan: satu sumber kebenaran yang direview bersama kode pemakainya; admin tidak bisa mengubah tipe; tidak menambah kolom yang tidak dikenal mudig/view kompatibilitas; menambah key tidak butuh migration. Alternatif: kolom `tipe_data` VARCHAR + seed (dulu tercatat di G-01 Bagian 3) | ⏳ |
| 4 | Tanpa `status`/`order`/`created_*` (tetap [K]) | Setujui. Konfigurasi bukan master yang dirujuk data; "nonaktif" tidak punya arti selain "pakai bawaan" | ⏳ |
| 5 | `config_value` tetap TEXT NULL [K]; aplikasi tidak pernah menulis NULL/kosong (kosong = hapus baris) | Setujui | ⏳ |
| 6 | Hapus = **hard delete** baris (nilai kembali ke bawaan katalog), bukan soft delete; before lengkap di `audit_logs` | Setujui. Deviasi dari pola "master tidak pernah di-hard-delete": baris ini bukan master yang dirujuk FK/data, dan legacy juga hard delete. Alternatif: kolom `status` [V2] — ditolak karena view kompatibilitas mudig harus menyaringnya | ⏳ |
| 7 | Tanpa FK `updated_by` → `pengguna` | Setujui (pola tabel master v2; DDL [K] tanpa FK) | ⏳ |
| 8 | Katalog 35 key (Bagian 2.2), termasuk 10 key potongan tukin/uang makan yang tidak ada di riset J4.04 | Setujui sebagai daftar impor; daftar `config_name` produksi (J1.03) tetap dicocokkan sebelum impor | ⏳ |
| 9 | Aturan impor Bagian 5 (stripslashes, normalisasi tipe, path logo tidak diimpor, key tak dikenal & key REVIEW diputuskan manusia) | Setujui sebagai langkah runbook impor Tier 1 | ⏳ |
| 10 | View kompatibilitas `simpeg01.web_config` untuk mudig (kolom `config_name`, `config_value`) | Dicatat untuk J3.02 (bukan bagian migration ini). Nilai v2 sudah bersih tanpa `addslashes`; mudig memanggil `stripslashes` pada sebagian besar pembacaan sehingga hasilnya sama untuk teks tanpa backslash | ⏳ |

## 5. Aturan impor (Mapping Tier 1, `WebConfigImport`)

1. **Konversi collation** `utf8mb4_0900_ai_ci` → `utf8mb4_unicode_ci` lewat impor ke tabel v2 (bukan ALTER di legacy).
2. **Audit duplikat sebelum impor** (UNIQUE #2): `SELECT config_name, COUNT(*) FROM web_config GROUP BY config_name COLLATE utf8mb4_unicode_ci HAVING COUNT(*) > 1` — termasuk duplikat yang hanya beda huruf besar/kecil. Duplikat diputuskan manual (tanpa menyalin nilainya ke dokumen repo).
3. **`stripslashes`** pada `config_name`, `config_value`, `remark`, lalu normalisasi per tipe (`WebConfigTypes`); HTML disanitasi ulang. Nilai yang gagal validasi → REVIEW (tidak diimpor otomatis).
4. **Path logo** (`logo_kementerian_pdf`, `logo_wonderful_pdf`): **tidak diimpor** (path absolut server legacy). v2 memakai logo bawaan sampai admin menunjuk berkas di penyimpanan aset v2 (path relatif; mekanisme unggah menyusul bersama ADR-032 library PDF).
5. **REVIEW manusia** walau sah: `logo_kementerian_url`, `logo_wonderful_url` (bisa menunjuk host server lama), `email_sent_time`, `email_ultah_ad` (cron legacy dimatikan; `email_ultah_ad` berisi alamat email → data pribadi, jangan disalin ke seed/dokumen).
6. **Key tak dikenal** → REVIEW bersama TL: impor apa adanya (tampil read-only "Tidak dikenal") atau buang.
7. Nilai kosong → tidak diimpor (v2 memakai bawaan). ID `id_web_config` legacy dipakai apa adanya; counter AUTO_INCREMENT disalin dari legacy (pola DBV-005).
8. Key `id_*` (rujukan unit/satker/jabatan): setelah G-02 (DBV-008) masuk, cek bahwa ID yang diimpor ada di tabel rujukan.

## 6. Verifikasi developer (sebelum review DB Validator)

### 6.1 Lingkungan

MySQL 8.0.30 lokal (Laragon). Database scratch `simpeg_v2_scr_dbv006` (DBPrefix kosong) untuk siklus `spark migrate`, dicek dengan `SELECT DATABASE()` lewat koneksi group `default` sebelum dijalankan, lalu di-DROP. `.env` worktree khusus uji (tidak di-commit, bukan salinan `.env` checkout utama). Database dev `simpeg_v2` tidak disentuh (tabel `migrations` tetap 23 baris, versi terakhir `2026-09-25-130200`, tanpa `web_config`). PHPUnit memakai DB test per shard (`fastcheck`). Verifikasi MariaDB 10.4 belum dilakukan (diminta di Bagian 4 #1).

### 6.2 Hasil

- Siklus migrate di scratch: batch 1 = seluruh migration `main`, batch 2 = `CreateWebConfig` saja → `migrate:rollback` hanya membatalkan batch 2 (daftar tabel identik dengan sebelum DBV-006) → `migrate` ulang bersih; `SHOW CREATE TABLE` identik antar-run:

```sql
CREATE TABLE `web_config` (
  `id_web_config` int NOT NULL AUTO_INCREMENT,
  `config_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_value` text COLLATE utf8mb4_unicode_ci,
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_web_config`),
  UNIQUE KEY `config_name` (`config_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

- UNIQUE: `INSERT 'TL1/PSW1'` lalu `'tl1/psw1'` → `ERROR 1062 … for key 'web_config.config_name'`.
- Quality gate (`fastcheck`, setara `./check.sh`): lihat ringkasan di deskripsi PR / laporan CR-030.

## 7. Checklist DB Validator

- [ ] Bagian 2.1 cocok dengan DDL [K] `:7622-7631`, deviasi hanya yang tercatat di Bagian 3
- [ ] `migrate` → `migrate:rollback` → `migrate` di MariaDB 10.4: bersih, `SHOW CREATE TABLE` identik antar-run
- [ ] UNIQUE `config_name` menolak duplikat (termasuk beda huruf besar/kecil) di MariaDB 10.4; panjang index VARCHAR(255) utf8mb4 (1.020 byte) diterima
- [ ] `WebConfigSchemaTest` + `WebConfigTest` + `CollationInvariantTest` lolos di MariaDB 10.4
- [ ] Keputusan Bagian 4 #1–#10 diisi
- [ ] Aturan impor Bagian 5 diterima sebagai langkah runbook (audit duplikat, stripslashes, path logo, REVIEW)
- [ ] Daftar `config_name` produksi (J1.03) diminta untuk dicocokkan dengan katalog 35 key sebelum impor
