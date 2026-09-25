# DB Validator Review — G-10 FAQ (pilot skema legacy)

**Key review:** `DBV-002` (review DB Validator, skema) + `CR-003` (review kode) — satu pull request, judul `[DBV-002][CR-003] …`, branch `dbv-002/g10-faq-pilot`. Merge hanya setelah **kedua** review menyatakan setuju. Aturan 24-09-2026 (G-01 Bagian 8): untuk PR dengan dua key [CR] & [DBV], DB Validator **hanya me-review/approve** (tidak merge); **merge dilakukan oleh reviewer CR**. (Key `CR-002` sudah dipakai PR #5.)

**Status:** ✅ **DISETUJUI DB VALIDATOR (DBV-002, jjoseph48, komentar "DBV-002 ✅" di PR #10, 25-09-2026) DAN REVIEW KODE (CR-003, 24-09-2026)**; di-merge ke `main` (merge commit `f958cb5`). Migration `2026-09-24-000001_CreateFaq.php` boleh dijalankan di Dev; deploy otomatis ke server Dev tetap mengikuti Trello ISSUE-014 (HOLD).

**Rujukan:** `02-MasterData.md` G-10, Tech Spec §2.3 G-10 ("DB Impact: faq_topic, faq_sub_topic, faq_article, faq_rate"), `Mapping_Migrasi_Data_SIMPEG_v2.docx` (FAQ: "sama / Copy langsung"), DDL produksi `simpeg_prod.sql:949-1030` (HeidiSQL, host 172.17.100.83, MySQL 8.0.21), ERD legacy `simpeg01.erd` (nama FK), kode legacy (`application/libraries/hr/L_faq.php`, `application/controllers/hr/Faq.php`, `application/controllers/hr/services/Local.php:4125-4222`, `application/views/hr/faq/**`), Matriks Role x Endpoint Modul G ("Lihat FAQ"), MTC-013/MTC-014, `G-01-master-schema.md` (Keputusan #5–#7, Bagian 8 / DBV-001).

**Label sumber:** **[K]** terkonfirmasi — DDL `simpeg_prod.sql` (nomor baris), ERD `simpeg01.erd`, atau kode legacy (`berkas:baris`); **[V2]** tambahan/ubahan v2 yang disengaja (deviasi dari legacy — alasan di Bagian 3); **[I]** dugaan (tidak ada sumber langsung, wajib dikonfirmasi).

| File | Isi |
|---|---|
| `app/Database/Migrations/2026-09-24-000001_CreateFaq.php` | 5 tabel: `faq_topic`, `faq_sub_topic`, `faq_article`, `faq_rate`, `faq_related_article` (SQL mentah, sadar prefix tabel; `down()` men-drop anak → induk) |
| `tests/MasterData/FaqSchemaTest.php` | skema hasil migration dibandingkan dengan Bagian 2 lewat `information_schema`, constraint DB, rollback |

## 1. Latar belakang & keputusan

G-10 sebelumnya **TODO (blocked)**: `faq_rate` dianggap tanpa definisi kolom (G-01 Bagian 3) dan FK `nip` → `pegawai` baru bisa dibuat di Fase 3. Ternyata DDL **kelima** tabel FAQ ada di `simpeg_prod.sql:949-1030` [K]. Isinya identik dengan `SHOW CREATE TABLE` di DB lokal `simpeg_prod_duplikat` dan `simpeg01`, dan keenam nama FK sama dengan ERD `simpeg01.erd`. Di semua salinan lokal kelima tabel berisi 0 baris. Karena DDL-nya lengkap dan dependensi keluarnya hanya FK `nip` (bisa ditunda), FAQ dipakai sebagai **pilot** penerapan skema legacy ke master baru, dengan prinsip yang sama dengan DBV-001: nama tabel & kolom sama dengan legacy (Mapping Prinsip #1, ADR-023), status `1`/`2`/`10`, hapus = soft delete, UNIQUE nama, `utf8mb4_unicode_ci`.

Perilaku legacy yang memengaruhi skema [K]:
- Kelola konten hanya UserLevel 1 (`Faq.php:14-15`, dst.); **hapus = hard DELETE** (`L_faq.php:246, 455, 669`) dan FK `CASCADE` ikut menghapus sub topik, artikel, dan rating. Tidak ada soft delete maupun pemulihan.
- Baca & cari FAQ tidak memeriksa login (`Faq.php:637-741`).
- Rating sekali per artikel per pegawai (PK komposit `faq_rate`). Widget tampil untuk `UL_PEGAWAI = ['2','6','7']` (`config/constants.php:300`), tetapi server hanya menerima `['2','6']` (`Local.php:4153`).
- `faq_related_article` tidak dirujuk kode sama sekali; "Artikel Terkait" dihitung otomatis: ≤5 artikel aktif lain di sub topik yang sama, `id DESC` (`L_faq.php:1034`).
- Konten artikel = HTML CKEditor 4 tanpa sanitasi server, disimpan dengan `addslashes` (`L_faq.php:720-725`) dan dirender dengan `stripslashes` (`views/hr/faq/detail.php:116-117`).

### 1.1 Keputusan user (24-09-2026)

| # | Topik | Keputusan | Diterapkan |
|---|---|---|---|
| U1 | Konten HTML artikel | Sanitasi server (HTMLPurifier, whitelist) saat tulis + DOMPurify saat render. Admin mengisi textarea HTML + tombol pratinjau. WYSIWYG & upload gambar ditunda (PR lain). Dependency baru: `ezyang/htmlpurifier` (composer), `dompurify` (npm) | `FaqArticleHooks`, `App\Libraries\Html\HtmlSanitizer`; FE `sanitizeHtml()` |
| U2 | Siapa boleh rating | Role 2 (Pegawai), 6 (PTT), 7 (PPPK) = `UL_PEGAWAI` legacy. Role lain 403 | filter route + `FaqService::RATER_ROLES` |
| U3 | Hapus induk (status 10) | Status anak **tidak** ditulis ulang. Tampilan pegawai hanya menampilkan entri yang **seluruh** rantainya status 1 (topik, sub topik, artikel). Memulihkan induk otomatis memunculkan anaknya kembali | `FaqService` (join rantai + filter status) |
| U4 | Aksi FK | `ON DELETE RESTRICT ON UPDATE RESTRICT` (seperti Batch 1), nama FK tetap legacy. Deviasi dari legacy `CASCADE` dicatat untuk DBV | migration |

### 1.2 Keputusan usulan (diimplementasikan sesuai usulan, menunggu approval DBV — Bagian 4)

| # | Usulan |
|---|---|
| D1 | `faq_related_article` **dibuat persis DDL legacy** (FK RESTRICT) tanpa API/UI; "artikel terkait" tetap dihitung otomatis seperti legacy |
| D2 | `faq_rate.nip` VARCHAR(30) `utf8mb4_unicode_ci` **tanpa FK sekarang**; FK `fk_nip_faqrate_to_peg` → `pegawai.nip` ditambahkan lewat migration baru di B-01 (Fase 3). KEY `fk_nip_faqrate_to_peg (nip)` tetap dibuat |
| D3 | `faq_topic.status` tetap **INT** seperti legacy [K] (tabel FAQ lain & Batch 1 TINYINT) — poin konfirmasi DBV |
| D4 | `faq_article.order` INT NOT NULL DEFAULT 1 ditambahkan (kolom v2, Keputusan #5 G-01) |
| D5 | UNIQUE nama, berlaku juga untuk baris status 2/10, case-insensitive lewat collation: `uq_faq_topic_nama (faq_topic)`, `uq_faq_sub_topic_nama (id_faq_topic, faq_sub_topic)`, `uq_faq_article_nama (id_faq_sub_topic, title)` |
| D6 | Ikon topik: kolom `icon` ada (NULL) tetapi tidak diekspos API/FE — tidak ada di form/meta dan tidak dikirim di respons admin mana pun (opsi definisi `hiddenColumns`); upload ditunda |
| D7 | Tab "Pertanyaan Umum / Panduan Pengguna" (Redesign) & tombol "Chat Admin" tidak dibuat di pilot |
| D8 | Baca FAQ **wajib login** (UL_ALL, filter `jwt`), bukan tamu seperti legacy — sesuai Matriks Role x Endpoint dan `02-MasterData.md` ("View FAQ terbuka untuk UL_ALL") |

## 2. Skema hasil DBV-002

Berlaku untuk kelima tabel:
- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, termasuk seluruh kolom string [K].
- Nama constraint/index **tanpa** prefix tabel (DBPrefix hanya berlaku untuk nama tabel, mis. `t_` di DB test).
- Nilai `AUTO_INCREMENT` awal tidak ditulis [V2] — dump legacy memuat snapshot 12/32/178; impor dengan ID eksplisit menaikkan counter otomatis (preseden DBV-001 `agama`).
- Kolom audit (topik, sub topik, artikel): `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `created_by INT NULL`, `updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP`, `updated_by INT NULL` [K]. COMMENT `'id_pengguna pembuat'` / `'id_pengguna yang terakhir mengubah'` [V2, preseden `AlterBatch1KeSkemaLegacy`]. Tanpa FK (ERD legacy juga tanpa relasi kolom audit).
- COMMENT status (topik, sub topik, artikel): `'1: Aktif, 2: Tidak Aktif, 10: Dihapus'` [V2] (legacy `'1: Active, 2: Not Active'`).

### 2.1 faq_topic — `simpeg_prod.sql:1018-1030`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_faq_topic` | INT NOT NULL AUTO_INCREMENT | [K] :1019 |
| `faq_topic` | VARCHAR(255) NOT NULL | [K] :1020 |
| `icon` | VARCHAR(255) NULL DEFAULT NULL | [K] :1021 — tidak diekspos API/FE (D6): tidak ada di form/meta, dibuang dari seluruh respons admin (`hiddenColumns`), nilai di DB tidak disentuh |
| `order` | INT NOT NULL DEFAULT 1 | [K] :1022 |
| `status` | **INT** NOT NULL DEFAULT 1, COMMENT v2 | tipe & default [K] :1023 (D3); nilai 10 & COMMENT [V2] |
| `remark` | TINYTEXT NULL | [K] :1024 |
| `created_at`, `created_by`, `updated_at`, `updated_by` | lihat di atas | [K] :1025-1028 |

Kunci: `PRIMARY (id_faq_topic)` [K] :1029; **UNIQUE** `uq_faq_topic_nama (faq_topic)` [V2, D5].

### 2.2 faq_sub_topic — `simpeg_prod.sql:999-1013`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_faq_sub_topic` | INT NOT NULL AUTO_INCREMENT | [K] :1000 |
| `id_faq_topic` | INT NOT NULL | [K] :1001 |
| `faq_sub_topic` | VARCHAR(255) NOT NULL | [K] :1002 |
| `order` | INT NOT NULL DEFAULT 1 | [K] :1003 |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | tipe & default [K] :1004; nilai 10 & COMMENT [V2] |
| `remark` | TINYTEXT NULL | [K] :1005 |
| audit (4 kolom) | lihat di atas | [K] :1006-1009 |

Kunci: `PRIMARY (id_faq_sub_topic)` [K] :1010; **UNIQUE** `uq_faq_sub_topic_nama (id_faq_topic, faq_sub_topic)` [V2, D5]; `KEY fk_id_faq_topic_faqst_to_faqto (id_faq_topic)` [K] :1011; FK `fk_id_faq_topic_faqst_to_faqto` → `faq_topic (id_faq_topic)` **ON DELETE RESTRICT ON UPDATE RESTRICT** — nama [K] :1012 + ERD, aksi [V2] (legacy CASCADE, U4).

### 2.3 faq_article — `simpeg_prod.sql:950-966`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_faq_article` | INT NOT NULL AUTO_INCREMENT | [K] :951 |
| `id_faq_sub_topic` | INT NOT NULL | [K] :952 |
| `title` | VARCHAR(255) NOT NULL | [K] :953 |
| `content` | LONGTEXT NOT NULL | [K] :954 — HTML tersanitasi (U1) |
| `content_stripped` | LONGTEXT NOT NULL | [K] :955 — teks polos turunan `content`, diisi server (rumus legacy `L_faq.php:720`) |
| `order` | INT NOT NULL DEFAULT 1 | **[V2]** (D4); tipe & default **[I]** meniru `order` faq_topic/faq_sub_topic |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | tipe & default [K] :956; nilai 10 & COMMENT [V2] |
| audit (4 kolom) | lihat di atas | [K] :957-960 |

Kunci: `PRIMARY (id_faq_article)` [K] :961; **UNIQUE** `uq_faq_article_nama (id_faq_sub_topic, title)` [V2, D5]; `KEY fk_id_faq_sub_topic_faqar_to_faqst (id_faq_sub_topic)` [K] :962 — secara teknis berlebih karena UNIQUE sudah diawali kolom yang sama, tetapi dipertahankan agar DDL sebanding dengan produksi; `KEY status (status)` [K] :963; **FULLTEXT** `title_content_stripped (title, content_stripped)` [K] :964; FK `fk_id_faq_sub_topic_faqar_to_faqst` → `faq_sub_topic (id_faq_sub_topic)` **RESTRICT/RESTRICT** — nama [K] :965 + ERD, aksi [V2].

### 2.4 faq_rate — `simpeg_prod.sql:971-982`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_faq_article` | INT NOT NULL | [K] :972 |
| `nip` | VARCHAR(30) NOT NULL | [K] :973 — v2 berisi `pengguna.nip` penilai (JWT `sub`) |
| `rate` | TINYINT NOT NULL COMMENT `'1: Membantu, 2: Kurang Membantu'` | [K] :974 |
| `reason` | TINYTEXT NULL | [K] :975 — wajib bila `rate` = 2, NULL bila `rate` = 1 (`Local.php:4182-4189, 4197`) |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [K] :976 |
| `created_by` | INT NULL, COMMENT `'id_pengguna pembuat'` | [K] :977; COMMENT [V2] |

Kunci: `PRIMARY (id_faq_article, nip)` [K] :978 — satu rating per artikel per pegawai; `KEY fk_nip_faqrate_to_peg (nip)` [K] :979; FK `fk_id_faq_article_faqrate_to_faqar` → `faq_article` **RESTRICT/RESTRICT** — nama [K] :980, aksi [V2]. FK `fk_nip_faqrate_to_peg` → `pegawai (nip)` [K] :981 **tidak dibuat sekarang** (D2). Tanpa `status` dan `updated_*` [K]: rating tidak bisa diubah atau dihapus.

### 2.5 faq_related_article — `simpeg_prod.sql:987-994`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_article_main` | INT NOT NULL | [K] :988 |
| `id_article_related` | INT NOT NULL | [K] :989 |

Kunci: `PRIMARY (id_article_main, id_article_related)` [K] :990; `KEY fk_id_article_related_faqrel_to_faqar (id_article_related)` [K] :991; FK `fk_id_article_main_faqrel_to_faqar` dan `fk_id_article_related_faqrel_to_faqar` → `faq_article (id_faq_article)` **RESTRICT/RESTRICT** — nama [K] :992-993 + ERD, aksi [V2]. Tanpa API/UI (D1).

### 2.6 Perilaku aplikasi yang bergantung pada skema (bahan CR-003)

Rincian endpoint: `app/Controllers/Api/MasterData/README.md` bagian FAQ.

- **Kelola konten (role 1)** memakai engine master generik: `master/faq-topic`, `master/faq-sub-topic`, `master/faq-article` (`FaqController` mendaftarkan 3 key). Hapus = status `10` (soft), pulihkan lewat `PATCH …/status`. Aplikasi tidak pernah hard delete, jadi FK RESTRICT tidak terpicu dari aplikasi.
- **Dropdown options master FAQ = role 1 saja** (CR-003, opsi definisi `publicOptions: false`): `master/faq-*/options` hanya dipakai form admin. Options generik hanya menyaring status baris itu sendiri, bukan seluruh rantai, sehingga bila terbuka untuk semua role (UL_ALL, bawaan master lain) judul sub topik/artikel yang disembunyikan U3 tetap terbaca. Query options hanya membaca kolom kode, nama, dan induk (LONGTEXT isi artikel tidak ikut terbaca).
- **Urutan (`order`):** entri baru dengan `order` langsung di-insert di posisi finalnya (dijepit 1..jumlah saudara tampil + 1), jadi tidak di-update lagi sesudahnya (`updated_by` tetap NULL sesuai E1, tanpa audit `update` tambahan). Saudara yang hanya bergeser (reorder, sisip, hapus, pindah induk entri lain; memulihkan entri hanya menaruhnya di akhir tanpa menggeser saudara) **tidak** diubah `updated_at`/`updated_by`-nya — legacy tidak me-renumber saudara, dan tanggal "Diperbarui" artikel pegawai tidak boleh bergeser hanya karena urutan. Perubahan `order` saudara tetap tercatat di `audit_logs`. Berlaku generik (Batch 1 juga); penulisan saudara memakai `updated_at = updated_at` agar `ON UPDATE CURRENT_TIMESTAMP` tidak terpicu.
- **Kolom audit (E1):** untuk tabel yang punya `created_by`, insert mengisi `created_by` = `id_pengguna` aktor dan membiarkan `updated_by` NULL, sedangkan update mengisi `updated_by` — sama dengan legacy (`L_faq.php:306-312`). Timestamp ditulis aplikasi dalam UTC. Tabel Batch 1 (tanpa `created_by`) tidak berubah perilakunya.
- **Isi artikel (E2):** hook `FaqArticleHooks` menyanitasi `content` (whitelist HTMLPurifier), lalu menghitung `content_stripped = trim(preg_replace('/\t+/', '', strip_tags($content)))` (rumus legacy `L_faq.php:720`) dari konten **tersanitasi**, di dalam transaksi tulis yang sama. `content_stripped` tidak pernah diambil dari input. Konten yang kosong setelah sanitasi → 422 `content` "Isi artikel wajib diisi.".
- **Batas panjang (E3, E5):** `content` tipe `html` maksimal 1.000.000 byte. `remark` (topik, sub topik) dan `reason` (rating) maksimal **255 byte** (TINYTEXT dihitung byte, bukan karakter). Koneksi aplikasi `strictOn=false` akan memotong nilai tanpa error bila batas ini tidak divalidasi.
- **Daftar admin (E4, E7):** daftar `faq-article` tidak mengirim `content`/`content_stripped`; detail satu entri tetap mengirimnya. Pencarian admin ikut mencari `content_stripped` (LIKE).
- **Rantai induk (E6, generik — wilayah juga):** tambah entri dan pindah induk hanya diizinkan bila **seluruh** leluhur ada dan berstatus 1. Id induk dari input harus bentuk kanonik (induk AUTO_INCREMENT: `1`, bukan `01`/`1abc`/`1.0`), karena MySQL meng-cast string seperti itu ke INT secara implisit; selain itu 422 "`<Induk>` tidak ditemukan.".
- **Baca pegawai (U3):** `api/v1/faq*` hanya menampilkan entri yang rantai topik → sub topik → artikel seluruhnya status 1 (JOIN + filter status), tanpa cache (MTC-014).
- **Pencarian** memakai FULLTEXT `title_content_stripped`: `MATCH(title, content_stripped) AGAINST(? IN NATURAL LANGUAGE MODE)` dengan parameter terikat (legacy `L_faq.php:992` menyisipkan string ber-`addslashes`), urut relevansi lalu id terbaru. Kata kunci < 3 karakter, atau FULLTEXT tanpa hasil → fallback `title LIKE %q%` dengan wildcard di-escape. Maksimal 50 hasil di kedua jalur. Kata kunci yang bukan UTF-8 valid → 422 `search` (sebelumnya lolos ke query lalu gagal saat dipantulkan ke JSON).
- **Rating:** `nip` = `pengguna.nip` aktor (JWT `sub`, VARCHAR(20) — muat di VARCHAR(30)), `created_by` = `id_pengguna` aktor, `created_at` = waktu UTC aplikasi. `reason` yang bukan UTF-8 valid → 422 (koneksi `strictOn=false` akan memotong/mengosongkannya diam-diam). Cek "sudah menilai" dilakukan dulu; pelanggaran PK (1062) akibat balapan diterjemahkan ke 422 `rate` "Artikel ini sudah Anda nilai.". Audit ke `audit_logs` (event `create`, entity `faq_rate`, entity_id `"{id_faq_article}:{nip}"`, fail-open seperti F0-04). `id_pengguna` dicari dari `pengguna` dengan parameter terikat, bukan JOIN, sehingga perbedaan collation `pengguna.nip` (`utf8mb4_general_ci`) dan `faq_rate.nip` (`utf8mb4_unicode_ci`) tidak berpengaruh.

## 3. Deviasi dari legacy & nilai [I]

| # | Item | Legacy [K] | v2 | Alasan / konsekuensi |
|---|---|---|---|---|
| 1 | Aksi FK | Keenam FK FAQ `ON DELETE CASCADE ON UPDATE CASCADE` (:965, :980-981, :992-993, :1012) | Lima FK dibuat `RESTRICT/RESTRICT`, nama legacy (U4); FK nip ditunda (#5) | Aplikasi tidak pernah hard delete dan status 10 dirancang bisa dipulihkan. CASCADE hanya berefek pada SQL manual/impor, dan di sana justru diam-diam menghapus sub topik, artikel, rating, dan relasi artikel. Konsekuensi: hapus fisik manual harus berurutan anak → induk. MySQL 8 menampilkan `ON DELETE RESTRICT ON UPDATE RESTRICT` yang ditulis eksplisit di `SHOW CREATE TABLE` (terverifikasi di MySQL 8.0.30 lokal); MariaDB dapat menghilangkan klausa RESTRICT karena itu nilai default. Verifikasi pasti lewat `information_schema.REFERENTIAL_CONSTRAINTS` (dilakukan `FaqSchemaTest`) |
| 2 | `faq_article.order` | Tidak ada. Legacy mengurutkan artikel menurut `title ASC` (`L_faq.php:1049, 1072`) atau `id_faq_article DESC` (beranda, terkait, pencarian) | INT NOT NULL DEFAULT 1 (D4) — tipe & default **[I]** | Keputusan #5 G-01 menyebut `faq_article` secara eksplisit. Tipe mengikuti `order` tabel FAQ lain (INT DEFAULT 1 [K]), bukan `INT UNSIGNED DEFAULT 0` seperti wilayah Batch 1. Urutan v2: pohon = `order`, judul; artikel terkait = `id DESC` (seperti legacy); pencarian = relevansi, `id DESC` |
| 3 | UNIQUE nama | Tidak ada UNIQUE selain PK; kode juga tidak mengecek duplikat (`L_faq.php:274-295, 476-501, 690-715`) | 3 UNIQUE (D5), termasuk baris status 2/10 | Aturan sama dengan DBV-001 #2. Lingkup per induk untuk sub topik & artikel **[I]** (alternatif: judul unik global). `utf8mb4_unicode_ci` tidak membedakan huruf besar/kecil maupun aksen. Konsekuensi: nama yang pernah dihapus (10) tidak bisa dibuat ulang, harus dipulihkan; **audit duplikat data legacy wajib sebelum impor**. Panjang key terbesar 1.024 byte (4 + 255 × 4), di bawah batas 3.072 byte InnoDB (row format DYNAMIC) |
| 4 | Status 10 & COMMENT | COMMENT `'1: Active, 2: Not Active'` (:956, :1004, :1023); kode FAQ hanya memakai 1/2. Nilai 10 = Deleted adalah konvensi legacy di tabel lain (`agama`, `simpeg_prod.sql:179`) | COMMENT `'1: Aktif, 2: Tidak Aktif, 10: Dihapus'` (preseden `AlterBatch1KeSkemaLegacy`) | Hapus = soft delete 10 (DBV-001 keputusan b). Status anak tidak ditulis ulang (U3). `faq_rate` & `faq_related_article` tanpa status (sama dengan legacy) |
| 5 | FK `faq_rate.nip` | `fk_nip_faqrate_to_peg` → `pegawai (nip)` CASCADE (:981) | Tidak dibuat; KEY dengan nama legacy dibuat (D2) | Tabel `pegawai` baru ada di Fase 3 (B-01); preseden A-01 #1 (FK `pengguna.nip` di-defer). Syarat saat FK ditambahkan: `pegawai.nip` VARCHAR(30) `utf8mb4_unicode_ci` (FK string beda collation ditolak MySQL 8, error 3780 — G-01 8.5) dan audit orphan `nip` dulu. Jangan JOIN `pengguna` ↔ `faq_rate` lewat `nip` sebelum collation tabel auth diseragamkan (error 1267, A-01 Bagian 8 #3) |
| 6 | Tipe `faq_topic.status` | INT (:1023), tabel FAQ lain TINYINT | INT dipertahankan (D3) | Nilai 1/2/10 muat, engine tidak terpengaruh. Bila DBV memilih TINYINT, cukup ALTER tanpa perubahan kode |
| 7 | FULLTEXT `title_content_stripped` | Ada (:964), dipakai pencarian legacy | Sama [K] | Terverifikasi di MySQL 8.0.30 lokal. **Belum diverifikasi di MariaDB 10.4** (lingkungan DB Validator). Yang perlu dicek: `CREATE TABLE` dengan FULLTEXT InnoDB, `innodb_ft_min_token_size` (default 3; kata < 3 karakter tidak terindeks, aplikasi memakai fallback LIKE), stopword bawaan InnoDB, serta UNIQUE 1.024 byte (butuh row format DYNAMIC, default MariaDB 10.4). Catatan (MySQL 8.0.30): peringkat relevansi InnoDB memakai statistik jumlah baris tabel; tepat setelah tabel dibuat/diimpor statistiknya bisa masih 0 sehingga relevansi seluruh hasil 0 dan urutan jatuh ke id terbaru sampai statistik dihitung ulang (otomatis di latar; setelah impor jalankan `ANALYZE TABLE faq_article`, Bagian 6.3 #12) |
| 8 | Pengisian kolom audit | Kode tidak menulis `created_at`/`updated_at` (default DB, jam server); `updated_by` hanya saat ubah (`L_faq.php:306-312`) | Ditulis aplikasi dalam UTC (G-01 8.4 #3). `created_by` saat insert, `updated_by` NULL saat insert | Insert v2 juga mengisi `updated_at` (timestamp aplikasi), sedangkan legacy membiarkannya NULL sampai baris diubah. Detail artikel pegawai memakai `created_at` bila `updated_at` NULL (baris impor yang belum pernah diubah) |
| 9 | `faq_rate.created_at` | Default DB (`Local.php:4193-4199` tidak mengisinya) | Ditulis aplikasi (UTC) | Konsisten dengan kolom audit lain |
| 10 | AUTO_INCREMENT awal, COMMENT kolom audit | `AUTO_INCREMENT=12/32/178`, kolom audit tanpa COMMENT | Tidak ditulis / COMMENT ditambahkan [V2] | Preseden DBV-001 |

Nilai **[I]** yang tersisa: tipe & default `faq_article.order` (#2) dan lingkup UNIQUE per induk (#3). Nilai lain [K] atau keputusan user/DBV di atas.

## 4. Keputusan yang diminta dari DB Validator (DBV-002)

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| 1 | Setujui skema Bagian 2 + migration `2026-09-24-000001_CreateFaq` untuk dijalankan di Dev | Setujui. Sebelum dijalankan di server MariaDB, jalankan sekali `migrate` → `migrate:rollback` → `migrate` di sana (Bagian 3 #7) | ✅ Disetujui sesuai usulan* |
| 2 | Aksi FK `RESTRICT/RESTRICT` dengan nama FK legacy, bukan `CASCADE` legacy (U4) | Setujui (sudah diputuskan user, alasan Bagian 3 #1) | ✅ Disetujui sesuai usulan* |
| 3 | D1: `faq_related_article` dibuat persis DDL legacy tanpa API/UI | Setujui. DBV cukup mengecek `COUNT(*)` di produksi; bila > 0 datanya ikut disalin tanpa dipakai aplikasi | ✅ Disetujui sesuai usulan* |
| 4 | D2: `faq_rate.nip` tanpa FK sekarang; FK `fk_nip_faqrate_to_peg` lewat migration baru di B-01 | Setujui (preseden A-01 #1). Syarat collation & audit orphan di Bagian 3 #5 | ✅ Disetujui sesuai usulan* |
| 5 | D3: `faq_topic.status` tetap INT | Pertahankan INT seperti legacy [K] | ✅ Disetujui sesuai usulan* |
| 6 | D4: `faq_article.order` INT NOT NULL DEFAULT 1 | Setujui; saat impor `order` diisi urutan `title` per sub topik (Bagian 6.3) | ✅ Disetujui sesuai usulan* |
| 7 | D5: 3 UNIQUE nama, termasuk baris status 2/10 (case-insensitive lewat collation) | Setujui — aturan sama dengan DBV-001 #2. Konsekuensi: audit duplikat data legacy wajib sebelum impor | ✅ Disetujui sesuai usulan* |
| 8 | Status 10 (Dihapus) + COMMENT v2 untuk tabel FAQ; status anak tidak ditulis ulang saat induk dinonaktifkan/dihapus (U3) | Setujui | ✅ Disetujui sesuai usulan* |
| 9 | Kolom audit diisi aplikasi: `created_by`/`updated_by` = `id_pengguna` tanpa FK; `updated_at` ikut terisi saat insert (Bagian 3 #8) | Setujui; zona waktu mengikuti keputusan global A-01 | ✅ Disetujui sesuai usulan* |
| 10 | Perubahan engine generik yang **ikut berlaku untuk 7 tabel Batch 1 (DBV-001)**: entri yang hanya bergeser urutannya karena entri lain dipindah/ditambah/dihapus/pindah induk **tidak lagi** di-stamp `updated_at`/`updated_by` (perubahan `order` tetap tercatat di `audit_logs`); tambah dengan `order` langsung di posisi final (tanpa event audit `update` tambahan untuk baris baru) | Setujui — legacy tidak me-renumber saudara, dan tanggal "Diperbarui" artikel pegawai tidak boleh bergeser hanya karena urutan. Mengubah perilaku yang tercatat di G-01 Bagian 8.2 (catatan rujukan ditambahkan di sana) | ✅ Disetujui eksplisit |

\* Approval DBV-002 (jjoseph48, komentar "DBV-002 ✅" di PR #10, 25-09-2026): "Skema DB sudah sesuai dengan DB legacy. Setuju untuk perubahan pencatatan: entri yang hanya bergeser urutannya tidak lagi di-stamp updated_at/updated_by." Poin #10 disetujui eksplisit; poin lain dicatat mengikuti kolom **Usulan** karena approval tanpa catatan per poin. Verifikasi MariaDB 10.4 tidak dilaporkan terpisah — jalankan `migrate` → `migrate:rollback` → `migrate` sekali sebelum dipakai di server MariaDB. Koreksi lewat perubahan lanjutan bila DB Validator bermaksud lain.

## 5. Status keputusan G-01 terkait

| G-01 | Isi | Sebelumnya | Setelah DBV-002 |
|---|---|---|---|
| Keputusan #5 | Semua master wajib `order` + `status`, termasuk `faq_sub_topic` & `faq_article` | ✅ YA (23-09-2026) | Tidak berubah, dijalankan: `faq_sub_topic.order` ternyata sudah ada di DDL legacy [K]; `faq_article.order` ditambahkan (D4, ✅ DBV-002 #6) |
| Keputusan #6 | `jenjang_jf`, `faq_related_article`, `dm_user_lokasi_presensi` ikut dimigrasi di G-01? | BELUM DIPUTUSKAN (blocked ISSUE-003) | `faq_related_article`: DDL ada, diusulkan dibuat tanpa API/UI (D1, ✅ DBV-002 #3). `jenjang_jf` & `dm_user_lokasi_presensi` tetap menunggu DDL |
| Keputusan #7 | FK `user_lokasi_presensi.nip` & `faq_rate.nip` → `pegawai.nip` | BELUM DIPUTUSKAN | `faq_rate.nip`: diusulkan defer ke B-01 (D2, ✅ DBV-002 #4). `user_lokasi_presensi` tetap di G-03 |
| Bagian 3 | `faq_rate` "tanpa definisi kolom"; `faq_sub_topic`/`faq_article` "tanpa `order`" di seed | Menunggu DDL | DDL kelima tabel ditemukan (`simpeg_prod.sql:949-1030`) |
| Bagian 7 #1 | Induk non-aktif tidak menurunkan status ke anak; perilaku tidak konsisten | Belum diputuskan | Sebagian ditangani: tambah/pindah induk kini memeriksa seluruh rantai (E6, generik), dan tampilan FAQ pegawai menyaring seluruh rantai (U3). `options()` tetap hanya menyaring status baris itu sendiri: untuk FAQ endpoint options dibatasi ke role 1 (`publicOptions: false`, CR-003) agar entri tersembunyi tidak bocor ke pegawai; options wilayah belum menyaring rantai (tetap UL_ALL) |

## 6. Verifikasi developer (sebelum review DB Validator)

### 6.1 Lingkungan

MySQL 8.0.30 lokal (Laragon), database test `simpeg_v2_testing` (DBPrefix `t_`, `strictOn=true`) — migration dijalankan otomatis oleh PHPUnit (`migrate:refresh`). Database dev `simpeg_v2` **belum** dijalankan migration ini (dicek: tidak ada tabel `faq_*`). Belum diverifikasi di MariaDB 10.4.

### 6.2 Hasil

| Perintah | Hasil |
|---|---|
| `composer check` di `backend/` (PHPStan + CS-Fixer + PHPUnit, sama dengan `check.sh`) | exit 0 |
| `phpstan analyse --memory-limit=1G --no-progress` (level 5) | `[OK] No errors` |
| `php-cs-fixer fix --dry-run --diff` | `Found 0 of 165 files that can be fixed` |
| `phpunit --no-coverage` (seluruh suite, termasuk test lama) | `OK (239 tests, 3365 assertions)`, 10:26 (setelah tindak lanjut review CR-003) |
| `FaqSchemaTest` | 5 test / 143 assertion — kolom & tipe persis Bagian 2 lewat `information_schema`, collation `utf8mb4_unicode_ci` per tabel & kolom, InnoDB, PK komposit, 3 UNIQUE, FULLTEXT (`INDEX_TYPE` FULLTEXT), 5 nama FK + kolom induk (`REFERENCED_COLUMN_NAME`) + `UPDATE_RULE`/`DELETE_RULE` = RESTRICT, tidak ada FK dari `faq_rate.nip`, default & COMMENT status/order/audit, COMMENT `faq_rate.created_by`, constraint DB menolak hard delete induk & nama ganda beda kapitalisasi, `down()` lalu `up()` mengembalikan skema yang sama, `up()` yang gagal di tengah men-drop hanya tabel yang dibuat run itu lalu bisa diulang |
| `FaqTest` | 23 test / 488 assertion — baca untuk 8 role + 401 tanpa token, rantai status (topik 2/10 menyembunyikan turunan, pulihkan memunculkan kembali), pencarian FULLTEXT & fallback pendek/wildcard ter-escape, urutan relevansi lalu id terbaru, maksimal 50 hasil di jalur FULLTEXT maupun fallback, `updated_at` detail (hasil ubah / `created_at` untuk baris belum diubah), perubahan admin langsung terlihat, CRUD admin hanya role 1, sanitasi, list admin tanpa `content`, `created_by`/`updated_by`, rating role 2/6/7 (201 + baris `faq_rate` + `audit_logs`), role 1/3/4/5/8 → 403, penilaian kedua → 422, validasi `rate`/`reason` (batas 255 byte dengan karakter multibyte), artikel tersembunyi → 404, balapan PK → 422; tindak lanjut CR-003: options master FAQ role 1 saja (tanpa membaca LONGTEXT), `icon` tidak ada di respons admin, tambah dengan `order` tanpa update baris baru, saudara yang bergeser tidak di-stamp, id induk non-kanonik → 422, `search`/`reason` bukan UTF-8 valid → 422 |
| `MasterGenericTcTest` + `RbacMasterEndpointsTest` | 33 test / 1824 assertion — G-TC generik kini juga mencakup 3 master FAQ; regresi E6 (rantai induk wilayah), `remark` > 255 byte, saudara Batch 1 yang bergeser tidak di-stamp; options UL_ALL kecuali master FAQ (role 1) |
| `tests/unit/Libraries/HtmlSanitizerTest` | 7 test / 28 assertion |
| `npm run check` di `frontend/` | exit 0 — ESLint 0 error/0 warning, vue-tsc tanpa error, Vitest 12 file / 94 test lolos, build sukses |

Setelah CR-003: uji E2E API 84/84 (DB scratch) dan uji UI di browser (Super Admin & Pegawai, termasuk lebar HP) sudah dilakukan. Verifikasi di MariaDB 10.4 tidak dilaporkan terpisah saat approval DBV-002.

### 6.3 Catatan migrasi data untuk Mapping

Salinan lokal kelima tabel berisi 0 baris, jadi semua butir di bawah hanya bisa diperiksa di data produksi.

1. **`stripslashes`**: kode legacy menyimpan teks dengan `addslashes`, lalu query builder CI3 meng-escape lagi untuk SQL, sehingga data produksi berisi `\'` dan `\"` literal (tampilan legacy menutupinya dengan `stripslashes`, `views/hr/faq/detail.php:116-117`). Kolom yang terdampak: `faq_topic.faq_topic`, `faq_topic.remark` (`L_faq.php:300, 303`); `faq_sub_topic.faq_sub_topic`, `faq_sub_topic.remark` (`L_faq.php:507, 510`); `faq_article.title`, `content`, `content_stripped` (`L_faq.php:723-725`); `faq_rate.reason` (`Local.php:4184`). Tanpa `stripslashes` backslash akan tampil di v2 dan mengacaukan pengecekan UNIQUE.
2. **Sanitasi saat impor**: impor lewat SQL tidak melewati `FaqArticleHooks`. `content` legacy tidak pernah disanitasi server, jadi saat impor jalankan sanitizer yang sama (`HtmlSanitizer`) lalu hitung ulang `content_stripped` dari hasilnya (setelah `stripslashes`), bukan menyalin kolom legacy.
3. **Gambar konten** menunjuk folder KCFinder `assets/upload/news` di host legacy (dipakai bersama modul News); file ikon topik ada di `assets/upload/faq/topic/`. File-file ini **belum dicakup** Mapping. Catatan: sanitizer v2 hanya menerima `img src` URL absolut http/https, sehingga gambar ber-path relatif akan terbuang saat impor bila tidak diubah dulu ke URL absolut yang tetap bisa diakses.
4. **`order`**: di legacy berupa teks bebas tanpa cek numerik/unik (`L_faq.php:288-291`), dan dengan strict mode mati bisa bernilai 0 atau ganda. Normalkan ke 1..n (topik global, sub topik per topik) saat impor. `faq_article.order` diisi urutan `title` per sub topik (sama dengan urutan halaman legacy).
5. **Audit duplikat nama sebelum impor** (UNIQUE D5): setelah `stripslashes` & trim, cari duplikat `faq_topic`, (`id_faq_topic`, `faq_sub_topic`), dan (`id_faq_sub_topic`, `title`) dengan perbandingan `utf8mb4_unicode_ci` (tidak peka huruf besar/kecil & aksen). Duplikat harus dirapikan dulu, karena impor akan gagal.
6. **`faq_rate.nip` legacy berisi `$user['id_pegawai']`** (`Local.php:4195`), bukan pasti NIP. Pertanyaan yang sama dengan A-01 Bagian 8 #9 ("`pengguna.id_pegawai` berisi NIP atau `pegawai.id_pegawai`?"); bila bukan NIP, kolom ini harus dipetakan ke NIP saat impor. Setelah itu **audit orphan `nip`** (tidak ada di `pegawai`) wajib sebelum FK `fk_nip_faqrate_to_peg` ditambahkan di B-01.
7. **`created_by` / `updated_by` legacy** berisi `user.id` akun legacy (`L_faq.php:306-312`, `Local.php:4198`), sedangkan v2 berisi `id_pengguna`. Perlu dipetakan bila ID akun tidak dipertahankan saat migrasi `pengguna`.
8. **Status** legacy hanya 1/2 — salin langsung (tidak ada baris berstatus 10). Baris yang dihapus di legacy sudah hilang secara fisik.
9. **Waktu**: `created_at`/`updated_at` legacy dari jam server DB (default `CURRENT_TIMESTAMP`), sedangkan v2 menulis UTC. Konversi mengikuti keputusan zona waktu global (A-01).
10. **`faq_related_article`**: cek `COUNT(*)` produksi; bila berisi data, salin apa adanya (tidak dipakai aplikasi, D1).
11. **Collation**: tabel legacy sudah `utf8mb4_unicode_ci`, sama dengan v2; tidak perlu konversi.
12. **Statistik tabel setelah impor**: jalankan `ANALYZE TABLE faq_article` (dengan prefix bila ada) setelah impor massal, agar peringkat relevansi pencarian FULLTEXT langsung memakai jumlah baris yang benar (Bagian 3 #7).

**Pemulihan bila `up()` gagal di tengah** (DDL MySQL ter-commit per statement, migration tidak tercatat di tabel `migrations`): `up()` otomatis men-drop tabel FAQ yang sempat dibuat **pada run itu** (urutan terbalik, masih kosong) lalu melempar ulang error, sehingga setelah penyebabnya diperbaiki cukup jalankan ulang `php spark migrate` (diuji `FaqSchemaTest::testFailedUpDropsOnlyTablesCreatedInThatRun`). Tabel `faq_*` yang sudah ada sebelum run tidak disentuh. Bila pembersihan otomatis itu ikut gagal (mis. koneksi putus), pulihkan manual — **jangan `migrate:rollback` batch**: migration ini tidak tercatat, sehingga rollback justru membatalkan batch terakhir yang tercatat (mis. `AlterBatch1KeSkemaLegacy`, yang mengembalikan skema Batch 1 lama). Drop tabel FAQ kosong yang tersisa dengan urutan `faq_related_article`, `faq_rate`, `faq_article`, `faq_sub_topic`, `faq_topic`, lalu `php spark migrate`. Catat kejadian di kartu DBV-002.
