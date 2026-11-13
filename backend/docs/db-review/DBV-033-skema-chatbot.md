# DB Validator Review — DBV-033 skema chatbot "Vanya" (Fase 8 E-10)

**Key review:** `DBV-033`. Judul PR: `[CR][DBV] DBV-033 skema chatbot Vanya`.
Branch `fase8/dbv-033-skema-chatbot` (dari `main` `483c5892`). Alur `[CR]+[DBV]`:
1. Reviewer CR membuka PR; DB Validator (WS-2) me-review dan memberi approve.
2. Reviewer CR me-merge dengan izin Horii.

**Status:** ⏳ menunggu review DB Validator.

**Penulis:** pelaksana C1 (izin `[#8-WorkloadChatbot]`), 13-11-2026.

**Asal kebutuhan:** PRD Fase 8 v1.1 (`docs/fase8/PRD_Fase8_HaloSimpeg.md`) §0.1 (gerbang E-10), §5.2 F8-25, F8-26, F8-38..F8-41,
F8-46, §7.10.1, AC-E10.1..2, Lampiran B.2 (A1, A10, A11, A13); `docs/fase8/8_1.md` §5 (C1).

**Bahan:** D1 chatbot dari DB dev legacy (`simpegdev`, **bukan** produksi), blok [1]–[6] diterima 12-11-2026: struktur keenam
tabel, isi `qna_status`, jumlah, sebaran, integritas, bentuk jawaban. Hasil D1 disimpan di luar repo; hanya angka ringkas yang
dikutip di sini. Isi QnA dev tidak dibawa di DBV ini (C4, PN8-6) dan **tidak pernah** masuk repo.

**Label sumber:**
- **[K dev D1]**: ikut DDL DB dev hasil D1.
- **[K dev]**: ikut perilaku kode dev (`dev:` = bahan chatbot dev, relatif `application/`).
- **[V2]**: keputusan v2 (konvensi, keamanan, integritas).
- **[I]**: inferensi pelaksana, perlu konfirmasi DB Validator.

---

## 0. Ringkasan

- Enam tabel baru: `qna_status`, `qna_kategori`, `qna_tag`, `qna`, `qna_has_tag`, `log_chatbot`, satu migration
  `2026-11-13-100000_CreateChatbotFase8` (langsung di `Migrations/`; belum ada di `main`, jadi bisa diubah selama review).
- Nama tabel/kolom ikut dev agar impor isi C4 lurus (F8-25).
- Tidak ada ALTER tabel lama, tidak ada FK ke tabel helpdesk (DBV-028) atau FAQ, tidak ada data selain master status.
- Perbedaan utama terhadap dev:
  - status 1/2/10 dengan CHECK (A1);
  - collation `utf8mb4_unicode_ci` (dev `utf8mb4_0900_ai_ci` di lima tabel);
  - judul kategori/tag unik di antara status 1/2 lewat kolom bangkitan (F8-40, K-12);
  - relasi `qna_has_tag` ber-PK komposit tanpa `id_qna_has_tag` dan FK RESTRICT (dev CASCADE tanpa UNIQUE);
  - `log_chatbot`: `id_log`/`top_1_id_qna` UNSIGNED, `top_1_skor` DECIMAL(5,4), `top_3_kandidat` + CHECK JSON, `status_match`
    NOT NULL, `waktu_interaksi` NOT NULL tanpa DEFAULT (UTC diisi aplikasi), indeks `idx_log_waktu` baru.

## 1. Konvensi

| Butir | Aturan |
|---|---|
| Engine/collation | InnoDB, `utf8mb4` / `utf8mb4_unicode_ci` per tabel dan per kolom string (AGENTS.md §2, `CollationInvariantTest`) |
| Status | `1` Aktif, `2` Tidak Aktif, `10` Dihapus; CHECK `IN (1, 2, 10)` + FK ke `qna_status` (F8-25) |
| Hapus | lunak = status 10 (F8-38); tidak ada hapus fisik dari aplikasi → semua FK **RESTRICT/RESTRICT** |
| Waktu | UTC, diisi aplikasi (`StempelUtc`, BP8-8); tanpa `DEFAULT CURRENT_TIMESTAMP` di kolom baru |
| Identitas | `log_chatbot` tanpa `id_pengguna`/NIP (K-4, BP8-15) |
| JSON | `LONGTEXT` + `CHECK (JSON_VALID(...))`, portabel MySQL 8 / MariaDB 10.4 (pola DBV-032) |
| Nama konstrain | `fk_<tabel>_<arti>`, `chk_<tabel>_<kolom>`, `uk_<tabel>_<kolom>` |

## 2. Pencocokan D1 (DDL dev) → v2, per kolom

### 2.1 `qna_status`

| Kolom | Dev [K dev D1] | v2 | Label |
|---|---|---|---|
| `id_qna_status` | int unsigned AI PK | INT UNSIGNED PK **tanpa AI**, CHECK `IN (1, 2, 10)` | [V2] id baku |
| `judul_status` | varchar(50) NOT NULL | sama | [K dev D1] |

Isi dev: `1 Aktif`, `2 Nonaktif` (tanpa "Dihapus": hapus dev = set 2, A1). Isi v2 (di-seed migration):
`1 Aktif`, `2 Tidak Aktif`, `10 Dihapus` [V2]. Label "Tidak Aktif" mengikuti badge baku AGENTS.md §1.5.

### 2.2 `qna_kategori`

| Kolom | Dev [K dev D1] | v2 | Label |
|---|---|---|---|
| `id_qna_kategori` | int unsigned AI PK | sama | [K dev D1] |
| `judul_kategori` | varchar(150) NOT NULL | sama; di-trim aplikasi | [K dev D1] |
| `status_qna_kategori_fk` | int unsigned NOT NULL DEFAULT 1, FK status | sama + CHECK 1/2/10, FK RESTRICT | [K dev D1]+[V2] |
| `tampil_bubble` | tinyint(1) NOT NULL DEFAULT 0 | sama + CHECK 0/1 | [K dev D1]+[V2] |
| `created_at`, `updated_at` | — | DATETIME NULL, UTC diisi aplikasi | [V2] aditif (audit) |
| `judul_unik` | — | VARCHAR(150) STORED `IF(status <> 10, judul_kategori, NULL)` + UNIQUE | [V2] F8-40 |

### 2.3 `qna_tag`

| Kolom | Dev [K dev D1] | v2 | Label |
|---|---|---|---|
| `id_qna_tag` | int unsigned AI PK | sama | [K dev D1] |
| `judul_tag` | varchar(100) NOT NULL | sama; di-trim aplikasi | [K dev D1] |
| `status_qna_tag_fk` | int unsigned NOT NULL DEFAULT 1, FK status | sama + CHECK 1/2/10, FK RESTRICT | [K dev D1]+[V2] |
| `created_at`, `updated_at` | — | DATETIME NULL | [V2] aditif |
| `judul_unik` | — | VARCHAR(100) STORED + UNIQUE | [V2] F8-40 |

### 2.4 `qna`

| Kolom | Dev [K dev D1] | v2 | Label |
|---|---|---|---|
| `id_qna` | int unsigned AI PK | sama | [K dev D1] |
| `pertanyaan` | text NOT NULL | sama (D1: terpanjang 193 karakter) | [K dev D1] |
| `jawaban` | text NOT NULL (HTML) | sama; disanitasi saat simpan (BP8-14; D1: terpanjang 1.318) | [K dev D1] |
| `id_qna_kategori_fk` | int unsigned **NULL**, FK kategori | sama (NULL-able), FK RESTRICT; **wajib di aplikasi** (F8-41) | [K dev D1] |
| `status_qna_fk` | int unsigned NOT NULL DEFAULT 1, FK status | sama + CHECK 1/2/10 | [K dev D1]+[V2] |
| `is_fallback_option` | tinyint(1) NOT NULL DEFAULT 0 | sama + CHECK 0/1 | [K dev D1]+[V2] |
| `created_at`, `updated_at` | datetime NULL | sama; UTC diisi aplikasi | [K dev D1] |

Indeks: `status_kategori (status_qna_fk, id_qna_kategori_fk)` (korpus = status 1; bubble pertanyaan per kategori, PRD §7.10.1)
+ `id_qna_kategori_fk` (indeks FK). `id_qna_kategori_fk` tetap NULL-able seperti dev (keputusan reviewer 13-11-2026): QnA tanpa
kategori dilaporkan dan tidak dibawa saat impor C4 (F8-46; D1: 0 baris), dan form/impor Excel v2 menolaknya (422).

### 2.5 `qna_has_tag`

| Kolom | Dev [K dev D1] | v2 | Label |
|---|---|---|---|
| `id_qna_has_tag` | int unsigned AI PK | **dibuang** | [V2] |
| `qna_id` | int unsigned NOT NULL, FK `qna` ON DELETE CASCADE | sama; FK **RESTRICT** | [K dev D1]+[V2] |
| `qna_tag_id` | int unsigned NOT NULL, FK `qna_tag` ON DELETE CASCADE | sama; FK **RESTRICT** + KEY | [K dev D1]+[V2] |
| PK | `id_qna_has_tag` (tanpa UNIQUE relasi) | **PK komposit `(qna_id, qna_tag_id)`** | [V2] |

Alasan [V2]:
- **PK komposit:** relasi murni tanpa atribut. Tidak ada kode dev yang membaca `id_qna_has_tag`; dev hanya menyisipkan dan
  menghapus per `qna_id` (`dev:LC:363`, `:493`). PK komposit sekaligus menegakkan UNIQUE relasi (A13; D1: 1 relasi ganda, dibuang
  saat impor) dan menjadi indeks pencarian tag per QnA.
- **RESTRICT:** v2 tidak menghapus QnA/tag secara fisik (hapus = status 10, F8-38). Relasi tag yang dihapus lunak tetap ada
  (F8-38, F8-49b). CASCADE hanya akan berlaku bila ada hapus fisik manual, dan RESTRICT mencegah relasi hilang diam-diam. Ganti
  set tag saat ubah QnA = DELETE relasi lalu INSERT dalam satu transaksi (F8-41), yang tidak terpengaruh aturan FK.

Alternatif ikut dev (bila DB Validator memilih): `id_qna_has_tag` INT UNSIGNED AI PK + `UNIQUE (qna_id, qna_tag_id)`, FK
CASCADE. Dampaknya terhadap aplikasi tidak ada (model tidak memakai `id_qna_has_tag`).

### 2.6 `log_chatbot`

| Kolom | Dev [K dev D1] | v2 | Label |
|---|---|---|---|
| `id_log` | int **signed** AI PK | INT **UNSIGNED** AI PK | [V2] konsisten dengan tabel lain; log dev tidak dimigrasi |
| `pesan_user` | text NOT NULL | sama; jalur bubble = `pertanyaan` QnA (F8-48) | [K dev D1] |
| `top_1_id_qna` | int NOT NULL, tanpa FK | INT **UNSIGNED** NOT NULL, tanpa FK | [K dev D1]+[V2] (QnA yang hilang tampil `#id`, §7.10.5) |
| `top_1_skor` | **float** NOT NULL | **DECIMAL(5,4)** NOT NULL, CHECK 0..1 | [V2] skor asli dibulatkan 4 desimal (§7.10.2 langkah 9); AVG statistik tanpa galat FLOAT |
| `top_3_kandidat` | longtext (NULL) | LONGTEXT **NOT NULL** + CHECK JSON_VALID | [K dev D1]+[V2]; selalu diisi mesin (`dev:LC:749-756`) |
| `status_match` | tinyint **NULL** | TINYINT **NOT NULL**, CHECK 0/1 | [V2]; selalu diisi mesin |
| `feedback` | tinyint NULL | sama + CHECK NULL/-1/1 | [K dev D1]+[V2] (`dev:LC:800`) |
| `waktu_interaksi` | datetime DEFAULT CURRENT_TIMESTAMP | DATETIME **NOT NULL tanpa DEFAULT**, UTC diisi aplikasi | [V2] BP8-8 (DEFAULT DB = zona waktu server) |

Indeks:
- `idx_log_qna_feedback (top_1_id_qna, status_match, feedback)`: **ikut dev** [K dev D1]. Melayani pengali feedback per QnA
  (`dev:LC:635-659`, filter `status_match = 1` per `top_1_id_qna`), urutan "paling sering cocok" bubble pertanyaan (`:597-616`),
  dan top-10 statistik. Usulan awal PRD `(status_match, top_1_id_qna)` tidak dipakai, karena indeks dev sudah mencakup pola
  akses "per QnA".
- `idx_log_waktu (waktu_interaksi)`: **baru** [V2]. Untuk riwayat urut terbaru, filter rentang tanggal, dan "chat hari ini" sebagai
  rentang UTC tanpa `DATE()` (A8, F8-42).

Tanpa kolom identitas pengirim (K-4): kepemilikan feedback dijaga token bertanda tangan (F8-35), bukan kolom DB.

## 3. Skema v2 (= DDL final)

DDL lengkap ada di migration `backend/app/Database/Migrations/2026-11-13-100000_CreateChatbotFase8.php` (sumber kebenaran).
Ringkasan konstrain:

| Tabel | PK | UNIQUE | FK (semua RESTRICT/RESTRICT) | CHECK |
|---|---|---|---|---|
| `qna_status` | `id_qna_status` | — | — | `chk_qna_status_id` IN (1,2,10) |
| `qna_kategori` | `id_qna_kategori` | `uk_qna_kategori_judul_unik (judul_unik)` | `fk_qna_kategori_status` → `qna_status` | `chk_qna_kategori_status`, `chk_qna_kategori_tampil_bubble` |
| `qna_tag` | `id_qna_tag` | `uk_qna_tag_judul_unik (judul_unik)` | `fk_qna_tag_status` → `qna_status` | `chk_qna_tag_status` |
| `qna` | `id_qna` | — | `fk_qna_kategori` → `qna_kategori`, `fk_qna_status` → `qna_status` | `chk_qna_status`, `chk_qna_is_fallback_option` |
| `qna_has_tag` | `(qna_id, qna_tag_id)` | (PK) | `fk_qna_has_tag_qna` → `qna`, `fk_qna_has_tag_tag` → `qna_tag` | — |
| `log_chatbot` | `id_log` | — | — | `chk_log_chatbot_top_3_json`, `chk_log_chatbot_status_match`, `chk_log_chatbot_feedback`, `chk_log_chatbot_skor` |

Total: 6 FK, 2 UNIQUE, 10 CHECK.

### 3.1 Judul unik (F8-40, K-12)

Aturan: judul kategori dan judul tag unik di antara status 1 dan 2. Perbandingan memakai trim dan mengabaikan huruf
besar/kecil. Judul yang sama dengan baris status 10 boleh dipakai lagi sebagai baris baru.

**Pilihan utama (diusulkan):** aplikasi + pengaman DB.
- Aplikasi (MAKE-094) men-trim judul, memeriksa keunikan di dalam transaksi tulis, dan mengembalikan 422 berbahasa Indonesia.
- DB menegakkan hal yang sama lewat kolom bangkitan `judul_unik = IF(status <> 10, judul, NULL)` STORED + UNIQUE:
  - collation `_ci` membuat "Cuti" = "cuti";
  - NULL (status 10) tidak bentrok, jadi judul baris yang dihapus boleh dipakai lagi;
  - balapan dua permintaan bersamaan berakhir 1062, yang diterjemahkan aplikasi menjadi 422 yang sama.
- Spasi tepi tidak ditangani DB (nilai disimpan sudah di-trim aplikasi).
- Konsekuensi: baris status 10 tidak bisa "dipulihkan" bila judulnya sudah dipakai baris aktif. v2 tidak punya Pulihkan (F8-38),
  jadi konsekuensi ini tidak muncul di aplikasi.
- Kompatibilitas: MySQL 8.0 dan MariaDB 10.4 mendukung kolom bangkitan STORED + UNIQUE.
- MySQL melarang kolom bangkitan STORED atas kolom FK ber-`ON UPDATE CASCADE/SET NULL`. FK status di sini RESTRICT, jadi aman.
- Model aplikasi tidak boleh menulis kolom `judul_unik` (dikecualikan dari `allowedFields`).

**Alternatif (aplikasi saja):** tanpa kolom `judul_unik`, hanya pemeriksaan aplikasi di dalam transaksi. Lebih sederhana, tetapi
dua tulis bersamaan bisa lolos (tidak ada kunci baris untuk judul yang belum ada), kecuali transaksi memakai `SELECT … FOR
UPDATE` pada rentang judul atau kunci aplikasi. Bila dipilih, kolom dan UNIQUE dihapus dari migration dan test skema.

**Data dev (D1 blok [5]):** 2 judul kategori kembar dan 11 judul tag kembar. UNIQUE tidak menghalangi skema (tabel v2 kosong).
Saat impor C4, judul kembar digabung ke baris aktif ber-id terkecil (K-12, F8-46). Kueri audit A6/A7 menghitungnya.

## 4. Pemakaian aplikasi (MAKE-094, setelah DBV-033 di `main`)

- Model kategori/tag/QnA lewat `BaseAuditableModel` (`audit_logs`); `log_chatbot` lewat model biasa tanpa audit (BP8-15).
- Korpus = `qna.status_qna_fk = 1` + tag `status_qna_tag_fk = 1` (F8-39); bubble topik = kategori status 1, `tampil_bubble = 1`,
  punya ≥ 1 QnA status 1.
- Statistik/riwayat memakai rentang UTC atas `waktu_interaksi` (indeks `idx_log_waktu`); pengali feedback dan top-10 memakai
  `idx_log_qna_feedback`.
- Model aplikasi tidak pernah menulis `judul_unik` (kolom bangkitan).

## 5. Pra-cek

Tabel v2 baru dan kosong. Tidak ada pra-cek yang memblokir pemasangan skema. Syarat data **sebelum impor isi C4** diperiksa
dengan kueri audit Bagian 6 terhadap DB dev. Angka D1 yang sudah diketahui:

| Butir | D1 dev | Perlakuan |
|---|---|---|
| Relasi QnA-tag ganda | 1 | dibuang + dilaporkan saat impor (F8-46) |
| QnA tanpa kategori | 0 | dilaporkan, tidak dibawa (F8-46) |
| Judul kategori kembar | 2 | digabung (K-12) |
| Judul tag kembar | 11 | digabung (K-12) |
| Jawaban berisiko (script/javascript:/on…=) | 0 | sanitasi saat impor (BP8-14) |

## 6. Kueri audit baca-saja

Berkas `backend/docs/db-review/sql/DBV-033-audit-chatbot.sql`, 13 kueri, masing-masing mengembalikan `butir`, `jumlah`.
Kolom yang dipakai ada di DDL dev **dan** v2, sehingga berkas yang sama berjalan di keduanya.

| Kode | Klasifikasi | Isi |
|---|---|---|
| A1 | WAJIB_0 | relasi `qna_has_tag` ganda |
| A2 | WAJIB_0 | relasi tanpa QnA |
| A3 | WAJIB_0 | relasi tanpa tag |
| A4 | INFO | QnA tanpa kategori |
| A5 | WAJIB_0 | QnA dengan kategori yang tidak ada |
| A6 | INFO | judul kategori kembar (trim, _ci) |
| A7 | INFO | judul tag kembar |
| A8 | INFO | judul berspasi tepi |
| A9 | WAJIB_0 | status di luar 1/2/10 |
| A10 | WAJIB_0 | `tampil_bubble`/`is_fallback_option` di luar 0/1 |
| A11 | INFO | QnA aktif di kategori nonaktif (F8-39, F8-46) |
| A12 | INFO | relasi QnA aktif ke tag nonaktif (F8-46) |
| A13 | INFO | log di luar domain v2 (log tidak dimigrasi) |

WAJIB_0 di DB dev = baris yang dibersihkan oleh impor C4. Di skema v2 selalu 0 (konstrain terpasang), dan itu ditegaskan
oleh test skema.

## 7. Verifikasi developer

### 7.1 Hasil (13-11-2026)

Dijalankan di DB scratch `simpeg_v2_t_f8cb`, MySQL 8.0.30 port 3307, prefix test `t_`:
- `vendor/bin/phpunit tests/database/HaloSimpeg/Chatbot/SkemaChatbotTest.php tests/database/CollationInvariantTest.php` → OK.
- PHPStan dan PHP-CS-Fixer bersih untuk berkas baru.
- MariaDB 10.4 diuji oleh matriks CI.

### 7.2 Isi test skema (`backend/tests/database/HaloSimpeg/Chatbot/SkemaChatbotTest.php`)

| Test | Menegaskan |
|---|---|
| `testStrukturDanCollation` | InnoDB + `utf8mb4_unicode_ci` per tabel/kolom; tipe dan NULL tiap kolom; `log_chatbot` tanpa kolom identitas |
| `testIndeksDanFk` | `idx_log_waktu`, `idx_log_qna_feedback`, `status_kategori`, PK komposit relasi, dua UNIQUE judul; 6 FK RESTRICT/RESTRICT; tanpa FK ke helpdesk/FAQ; 10 CHECK |
| `testSeedStatus` | isi 1 Aktif / 2 Tidak Aktif / 10 Dihapus; id 3 ditolak CHECK |
| `testCheckFkDanUnique` | relasi ganda 1062; FK 1452/1451; CHECK status/flag/JSON/status_match/feedback/skor; DECIMAL 0.2500; `top_1_id_qna` tanpa FK |
| `testJudulUnikHanyaStatusAktifDanTidakAktif` | judul kembar beda huruf 1062 (status 1 dan 2); judul status 10 boleh dipakai lagi; dua baris status 10 berjudul sama; mengaktifkan ulang baris yang judulnya sudah dipakai 1062 |
| `testDownLaluUpKembali` | `down()` men-DROP keenam tabel, FAQ tetap ada; `up()` memasang ulang + seed |
| `testKueriAuditBerjalan` | 13 kueri audit berjalan di skema v2; butir WAJIB_0 = 0 |

## 8. Keputusan yang diminta dari DB Validator

| No | Pertanyaan | Usulan | Alternatif |
|---|---|---|---|
| DBV-033-Q1 | Judul unik F8-40 | kolom bangkitan `judul_unik` STORED + UNIQUE + cek aplikasi | aplikasi saja (§3.1) |
| DBV-033-Q2 | `qna_has_tag` | PK komposit, FK RESTRICT [V2] | ikut dev: `id_qna_has_tag` AI + UNIQUE, FK CASCADE |
| DBV-033-Q3 | `top_1_skor` | DECIMAL(5,4) | FLOAT ikut dev |
| DBV-033-Q4 | `id_log`, `top_1_id_qna` | UNSIGNED | signed ikut dev |
| DBV-033-Q5 | `top_3_kandidat` | LONGTEXT NOT NULL + CHECK JSON_VALID | LONGTEXT NULL tanpa CHECK (dev) |
| DBV-033-Q6 | `status_match` | NOT NULL + CHECK 0/1 | NULL ikut dev |
| DBV-033-Q7 | Indeks ranking feedback | `idx_log_qna_feedback` ikut dev | `(status_match, top_1_id_qna)` (usulan awal PRD) |
| DBV-033-Q8 | `qna_status.id_qna_status` | tanpa AUTO_INCREMENT + CHECK 1/2/10 | AI ikut dev |
| DBV-033-Q9 | `id_qna_kategori_fk` | NULL-able ikut dev, wajib di aplikasi (✔ reviewer 13-11-2026) | NOT NULL |

## 9. Syarat merge (⛔)

- DB Validator (WS-2) approve PR.
- CI Quality gate hijau (MySQL 8.0 + MariaDB 10.4).
- Gate penuh `./check.sh` atas aba-aba reviewer.
- Izin merge Horii; merge oleh reviewer CR.

## 10. Checklist DB Validator

- [ ] Kolom dan tipe cocok dengan DDL D1 atau deviasinya berlabel [V2] beralasan (Bagian 2).
- [ ] Status 1/2/10 + CHECK + seed.
- [ ] Collation `utf8mb4_unicode_ci` keenam tabel.
- [ ] FK RESTRICT/RESTRICT; tanpa FK ke helpdesk/FAQ; `log_chatbot` tanpa FK dan tanpa identitas.
- [ ] Indeks `idx_log_waktu` dan `idx_log_qna_feedback`.
- [ ] Keputusan Q1–Q9.
- [ ] Kueri audit dapat dijalankan di DB dev untuk impor C4.
- [ ] Migration naik/turun bersih (test skema).
