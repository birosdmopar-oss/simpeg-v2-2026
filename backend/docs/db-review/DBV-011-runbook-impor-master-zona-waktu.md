# DB Validator Review — DBV-011 Runbook impor master legacy (audit & dedupe duplikat) dan zona waktu / sql_mode sesi

**Key review:** `DBV-011` saja. Tidak ada key CR karena unit ini tidak mengubah kode maupun migration; isinya hanya dokumen dan skrip SQL baca-saja. PR berjudul `[DBV-011] …` dari branch `dbv-011/runbook-impor-dedupe-zona-waktu`. Menurut aturan PR yang hanya ber-key `[DBV]` (`AGENTS.md` bagian 2), DB Validator yang me-review dan me-merge.

**Status:** ⏳ **MENUNGGU REVIEW DB VALIDATOR (DBV-011).** Diperluas 01-10-2026 (putaran 5; ringkasannya di 1.4, buktinya di 6.6).
- **Konteks 01-10-2026:** rencana in-place yang dikaji 30-09 **dibatalkan**. v2 memakai DB terpisah yang diisi lewat impor (ADR-007 Opsi B), dan ID legacy diimpor apa adanya. Karena itu bagian impor dokumen ini **berlaku penuh**. Salinan untuk impor final = **dump final saat freeze**, mengikuti Cutover Plan (1.4 butir e).
- **Bagian A (ISSUE-009)** diajukan untuk disetujui sebagai langkah wajib runbook impor data master legacy. Bagian ini sekaligus memenuhi syarat approval DBV-005 (audit data sebelum impor + penyalinan counter AUTO_INCREMENT). Sejak putaran 5, Bagian A juga memuat pembersihan salinan (trigger dan `password_decode` dibuang), audit format hash dan akun tanpa email, serta selisih terhadap dump struktur prod 01-10 (2.9).
- **Bagian B (ISSUE-022 + D-11) DITAHAN** sampai kartu ISSUE-024 di board tim terjawab (parameter server DB produksi: versi, `sql_mode`, `time_zone`, `table_rows`). Putaran 5 tidak memutuskan Bagian B. Yang berubah hanya isi permintaannya: `sql_mode` dan `time_zone` GLOBAL tidak diminta diubah; strict mode dan `time_zone = '+00:00'` dipasang per sesi koneksi (1.4 butir a). **Keputusan final soal zona waktu dan `sql_mode` tetap di TL/DBA** (A-01 Bagian 8 #7, 9.7 D-11).
- Kedua skrip SQL **belum pernah dijalankan ke data legacy**, karena mesin developer tidak punya salinan DATA legacy. Skrip diuji sintaks dan hasilnya di database scratch lokal (Bagian 6); sejak putaran 5 juga pada struktur dump prod 01-10 (tanpa data) ditambah data sintetis. Eksekusi sesungguhnya menunggu salinan data produksi.
- Sebelum diajukan, dokumen dan kedua skrip melewati 4 putaran **pra-review internal** (reviewer CR, bukan DB Validator); temuan tiap putaran beserta perbaikannya dicatat di 6.2–6.5. Perluasan putaran 5 (6.6) **belum** melalui pra-review internal maupun review DB Validator. Branch disinkronkan dengan `main` `cc553be` (berisi CR-014 dan CR-025) lewat merge, bukan rebase.

**Rujukan:**
- Keputusan user 29-09-2026: ISSUE-009 (runbook + skrip audit untuk review DBV), ISSUE-022 (usulan ke TL/DBA lewat DBV, satu paket dengan D-11), ISSUE-014 opsi A (hook deploy Dev `RUN_MIGRATIONS=0` bawaan, `migrate` manual lewat runbook setelah approval DBV).
- Butir audit impor yang digabung ke sini: `G-01-master-schema.md` 8.3 dan 8.4 #2–#3; `G-04-G-05-pangkat-pendidikan-schema.md` 6.3 dan Bagian 7; `G-06-diklat-hukdis-konket-tanda-jasa-schema.md` Bagian 3, 6.4, 6.5 (syarat DBV-005) dan catatan zona waktu di bagian Status; `G-07-G-08-kantor-hari-libur-kursem-schema.md` 3.1 dan 6.3; `G-10-faq-schema.md` Bagian 3 dan 6.3; `A-01-auth-schema.md` Bagian 8 #7, 9.7 D-4/D-6/D-11/D-12, 9.8 langkah 6, dan 9.10.
- Migration di `main` (`ff74bf9`; sama dengan `0f6268a`, karena PR #11/CR-016 tidak mengubah migration), `MasterService::normalizeName()`, `MasterService::createLocked()` (trim kode & induk), `MasterField::normalize()`, `HariLiburService::nama()`, `UserService` (email D-6, CR-019). Sejak putaran 4 branch ini di-rebase ke `ff74bf9`; PR #11 tidak mengubah fungsi normalisasi tersebut (`git diff 0f6268a ff74bf9`). Di putaran 5 `main` `cc553be` di-merge ke branch ini: migration tetap sama dengan `ff74bf9`, dan fungsi normalisasi yang dirujuk tidak berubah (perubahan `MasterService` di CR-024 hanya menyangkut filter `parent` options).
- DDL produksi `simpeg_prod.sql`, kode legacy (`Lm_umum.php`, `config/config.php`), dan audit issue 29-09-2026.
- Sejak putaran 5:
  - keputusan user 01-10-2026: DB v2 terpisah + impor (ADR-007 Opsi B), rencana in-place 30-09 dibatalkan, ID legacy apa adanya;
  - asumsi PRD (dokumen privat tim) AS-02 (engine dan instance DB v2) dan AS-12 (zona waktu dan `sql_mode` per sesi);
  - Cutover Plan (Adendum Operasional Privat, dokumen privat tim);
  - **dump struktur prod 01-10**: struktur penuh `simpeg01` tanpa data (header MySQL 8.0.21), disimpan di luar repo;
  - kartu ISSUE-024 di board tim (parameter server DB produksi).

**Label sumber:**
- **[K]** terkonfirmasi (DDL produksi, kode, atau hasil ukur). Sejak putaran 5, DDL produksi diambil dari dump struktur prod 01-10.
- **[L]** rekonstruksi lokal (`simpeg_prod_duplikat`, `simpegdev_local`), belum tentu sama dengan produksi.
- **[V2]** keputusan v2.
- **[I]** dugaan.

Kolom `cek` pada hasil skrip memakai tiga awalan: **WAJIB_0**, **PERIKSA**, dan **INFO** (arti di 2.3).

| File | Isi |
|---|---|
| `backend/docs/db-review/DBV-011-runbook-impor-master-zona-waktu.md` | dokumen ini |
| `backend/docs/db-review/sql/DBV-011-audit-duplikat-master-legacy.sql` | Audit **baca-saja** pada salinan data legacy (dijalankan pemegang akses): 111 kueri tetap + 2 kueri dinamis (ditambah 2 SELECT penyusun dan 4 SELECT pencetak teks kueri/daftar entri terlewat). Isinya: prasyarat salinan (kolom `password_decode` dan trigger sudah dibuang; putaran 5), duplikat untuk seluruh 28 UNIQUE master v2 dan UNIQUE akun, format hash `pengguna.password` dan akun tanpa email (putaran 5), integritas pendukung (termasuk pasangan `kategori` ↔ `old_id`, kolom kode NOT NULL, panjang kolom akun, serta kode wilayah ganda setelah trim dan panjang/format kode wilayah), profil per tabel termasuk status NULL dan counter AUTO_INCREMENT, nama yang NULL/berubah/tidak muat, tanggal nol, dan daftar kolom TIMESTAMP legacy |
| `backend/docs/db-review/sql/DBV-011-counter-auto-increment-v2.sql` | Skrip **baca-saja** di DB v2 setelah impor. Membandingkan counter AUTO_INCREMENT v2 dengan counter legacy, lalu **mencetak** perintah `ALTER TABLE … AUTO_INCREMENT` untuk ditinjau (syarat DBV-005 6.5 #10). Berjalan tanpa error di MySQL 8 maupun MariaDB. Kesetiaan counter salinan terhadap sumber dicek dulu (2.4 langkah 1) |
| `backend/docs/progress/02-MasterData.md` | satu baris rujukan di G-01 bagian "Belum". Dua baris basi di atasnya (DBV-002 ⏳, strict mode) dibersihkan CR-022, jadi tidak disentuh di sini |
| `backend/docs/progress/01-Auth.md` | rujukan ke Bagian 3 pada butir D-11 |
| `backend/docs/db-review/A-01-auth-schema.md` | satu kalimat rujukan di Bagian 8 #7 dan di 9.7 D-11 |
| `backend/docs/db-review/G-01-master-schema.md`, `G-06-diklat-hukdis-konket-tanda-jasa-schema.md` | satu kalimat rujukan "langkah runbook: DBV-011" di bawah G-01 8.4 dan di G-06 6.5 (syarat DBV-005) |

## 1. Latar belakang & keputusan

### 1.1 Keputusan user 29-09-2026 (sumber otoritatif)

| Issue | Keputusan | Dipakai di |
|---|---|---|
| ISSUE-009 | Tulis runbook dan skrip audit duplikat data legacy untuk direview DB Validator. Skrip belum dijalankan karena menunggu salinan data produksi | Bagian 2 |
| ISSUE-022 | Usulan diajukan ke TL/DBA lewat DBV dalam satu paket dengan D-11. Isinya: aplikasi tetap UTC; aturan runbook impor/SQL manual (+07 → +00, kolom DATE tidak dikonversi); dan `SET time_zone = '+00:00'` per koneksi aplikasi. Semuanya berstatus usulan; keputusan final di TL/DBA | Bagian 3 |
| ISSUE-014 | Opsi A: hook deploy Dev memakai `RUN_MIGRATIONS=0` sebagai bawaan, deploy hanya dari `main`, dan `migrate` dijalankan manual lewat runbook setelah approval DBV | 2.4 langkah 6 dan 8 (impor dan `ALTER` counter adalah penulisan manual, bukan dari hook) |

### 1.2 Keputusan yang sudah final (tidak dibuka ulang)

- **UNIQUE nama master** berlaku untuk semua status, termasuk 2/10. Pembandingnya tidak peka huruf besar/kecil maupun aksen, lewat `utf8mb4_unicode_ci` (G2; G-01 8.4 #2). Konsekuensinya, audit duplikat data legacy wajib dilakukan sebelum impor.
- **Syarat approval DBV-005:** audit data sebelum impor (G-06 6.5) dan penyalinan counter AUTO_INCREMENT legacy (6.5 #10) wajib menjadi langkah runbook impor.
- **ID legacy diimpor apa adanya.** Dasarnya: G-06 B8, G-04 P12, G-07 6.3 #6, dan A-01 9.4 (`id_pengguna` = `pengguna.id` legacy).
- **Penyimpanan waktu = UTC.** Kolom audit diisi aplikasi; default DB hanya cadangan (G2; G-01 8.4 #3).
- **Keputusan DBV-010 yang terkait:**
  - D-4: tanpa CHECK role↔NIP, dan duplikat `id_pegawai` wajib diaudit sebelum impor.
  - D-6: email tanpa UNIQUE di DB; keunikan ditegakkan aplikasi. Bagian aplikasinya sudah di `main` lewat CR-019.
  - D-10: `strictOn = true`.
  - D-11 ⏳ masih menunggu DBA. Isi permintaannya direvisi di putaran 5 (1.4 butir a).

### 1.3 Lingkup

**Masuk:**
- Audit duplikat untuk semua UNIQUE master yang sudah ada di `main`: Batch 1, FAQ, dan G-04..G-08 (28 index), ditambah `pengguna.username`, `pengguna.nip` (dari `id_pegawai` legacy), dan email (D-6).
- Pemeriksaan pendukung yang ikut menggagalkan impor: FK yatim, CHECK, panjang kolom, dan tanggal nol.
- Syarat DBV-005 6.5.
- Prosedur dedupe dan penyalinan counter.
- Usulan zona waktu dan `sql_mode` sesi.
- Sejak putaran 5: pembersihan salinan (trigger dan kolom `password_decode` dibuang), audit format hash `pengguna.password`, dan akun tanpa email.

**Tidak masuk:**
- Perubahan kode atau migration. Bila opsi 3.3 disetujui, implementasinya menjadi unit DBV+CR terpisah.
- Skrip ekspor/transformasi impor itu sendiri.
- Tabel G-02/G-03/G-09. UNIQUE dan butir auditnya ditambahkan saat DBV-006..008.
- Runbook impor tabel Tier 2–9 dan rekonsiliasi jumlah baris (dokumen DBV terpisah, DBV-014).
- Pengosongan `password_decode` di DB legacy produksi saat freeze. Langkah itu milik Cutover Plan (dokumen privat tim), bukan runbook ini.
- Perbaikan tampilan "Login terakhir" (Bagian 7).

**Mengapa belum bisa dijalankan** (audit 29-09-2026, hanya baca):
- `simpeg01` lokal hanya berisi struktur (0 baris).
- `simpeg_prod_duplikat` dan `simpegdev_local` hanya berisi baris contoh atau rekonstruksi.
- DB lokal `production` adalah aplikasi lain.

Jadi tidak ada data legacy nyata untuk diaudit. Sejak 01-10-2026 struktur produksi lengkap sudah tersedia (dump struktur prod 01-10), tetapi datanya belum.

### 1.4 Perluasan 01-10-2026 (putaran 5)

**Dasar:** keputusan user 01-10-2026.
- v2 memakai DB terpisah yang diisi impor dari dump legacy (ADR-007 Opsi B).
- Rencana in-place yang dikaji 30-09 **dibatalkan**, sehingga bagian impor dokumen ini berlaku.
- ID legacy diimpor apa adanya.

Lingkup dokumen tetap master + `pengguna`.

| # | Perluasan | Isi | Tempat |
|---|---|---|---|
| a | **Revisi permintaan A-01 9.8 langkah 2** (baris 285 di `main` `cc553be`) | Permintaan "DBA memastikan `sql_mode` GLOBAL memuat …" **ditarik**. `sql_mode` dan `time_zone` GLOBAL server **tidak diubah**. Strict mode (`STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`) dan `time_zone = '+00:00'` dipasang **per sesi koneksi**:<br>• sesi impor dan SQL manual: sekarang, lewat runbook 2.4 langkah 6 (R4);<br>• koneksi aplikasi: setelah keputusan Bagian B (3.3–3.4).<br>Alasannya, instance DB produksi dipakai bersama aplikasi lain, sehingga perubahan global ikut mengubah perilaku aplikasi itu. Teks A-01 **tidak** diubah di PR ini; koreksinya lewat DBV-017 terpisah | 2.4 langkah 6; 3.4–3.6; Bagian 4 #12 |
| b | **Syarat engine** | Syarat "MySQL ≥ 8.0.30" diganti: versi **mengikuti keputusan AS-02** (asumsi PRD tentang engine dan instance DB v2; pemilik TL + DBA). Header dump struktur prod 01-10 menulis MySQL 8.0.21. Parameter server terkini menunggu kartu ISSUE-024 di board tim. Salinan tetap wajib MySQL 8 (alasan teknis di 2.4 langkah 1); di versi selain 8.0.30, uji deteksi data sintetis dijalankan dulu | 2.4 langkah 1; kepala skrip audit; Bagian 4 #13 |
| c | **`password_decode`** | Kolom password plaintext legacy ini tidak pernah diimpor. Kolomnya dibuang dari **semua** tabel salinan segera setelah restore, sebelum audit dan sebelum langkah lain. Trigger legacy ikut dibuang (2.9). Bagian 0 skrip audit memeriksa keduanya (WAJIB_0) | 2.4 langkah 1; skrip bagian 0; Bagian 4 #14 |
| d | **Audit hash dan email** | Format `pengguna.password`: tepat 32 karakter heksadesimal → `password_legacy` VARCHAR(32) (A-02b); format lain dilaporkan per akun (PERIKSA). Akun tanpa email (A-01 D-6) diringkas per role dan status (PERIKSA). Email ganda sudah diaudit sejak putaran 1 | skrip A6; 2.3; 2.4 langkah 5; 2.5; Bagian 4 #15 |
| e | **Salinan untuk impor final** | = **dump final yang diambil saat freeze**, mengikuti Cutover Plan (Adendum Operasional Privat). Salinan dari dump sebelum freeze hanya untuk audit awal dan gladi; audit, dedupe, dan log 2.8 diulang pada dump final | 2.4 langkah 1 dan 10; Bagian 4 #17 |
| f | **Selisih dump struktur prod 01-10** | DDL `pengguna` dan beberapa master berbeda dari asumsi dokumen. Selisihnya dicatat, dan tindak lanjutnya tidak mengedit migration di `main` | 2.9; Bagian 4 #16 |

**Bagian B tetap ditahan** sampai kartu ISSUE-024 terjawab. Putaran 5 tidak memilih opsi zona waktu (3.5) dan tidak memastikan offset R1a. Yang berubah hanya isi permintaan (butir a).

## 2. Bagian A — Audit & dedupe duplikat master legacy (ISSUE-009)

### 2.1 Aturan keunikan v2 yang ditiru persis

Daftar di tabel berikut dicocokkan dengan `information_schema.STATISTICS` di DB scratch hasil `migrate --all` (`main` `0f6268a`). Hasilnya 28 UNIQUE non-PK di tabel master, sama dengan bagian A skrip.

Arti kolom **Normalisasi kunci**:
- **nama+SS** = stripslashes → deret whitespace dijadikan satu spasi → trim.
- **nama** = sama, tetapi tanpa stripslashes.
- **kode** = stripslashes → trim saja, dengan kelas karakter `trim()` PHP (spasi, `\t`, `\n`, `\r`, `\0`, `\x0B`; 2.2 #2).

| Grup (migration) | Tabel | Index v2 | Lingkup (kolom depan) | Kolom | Normalisasi kunci |
|---|---|---|---|---|---|
| Batch 1 (`AlterBatch1KeSkemaLegacy`) | `provinsi` | `uq_provinsi_nama` | — | `provinsi` | nama+SS |
| | `kabupaten_kota` | `uq_kabupaten_kota_nama` | `id_provinsi` | `kabupaten_kota` | nama+SS |
| | `kecamatan` | `uq_kecamatan_nama` | `id_kabupaten_kota` | `kecamatan` | nama+SS |
| | `kelurahan` | `uq_kelurahan_nama` | `id_kecamatan` | `kelurahan` | nama+SS |
| | `agama` | `uq_agama_nama` | — | `agama` (legacy `utf8mb4_0900_ai_ci` NO PAD di tabel latin1 [K]) | nama+SS |
| | `jenis_pegawai` | `uq_jenis_pegawai_nama` | — | `jenis_pegawai` | nama+SS |
| | `jenis_status` | `uq_jenis_status_nama` | `status_pegawai` (sebagai bilangan, 2.2 #5) | `jenis_status` | nama+SS |
| FAQ (`CreateFaq`) | `faq_topic` | `uq_faq_topic_nama` | — | `faq_topic` | nama+SS |
| | `faq_sub_topic` | `uq_faq_sub_topic_nama` | `id_faq_topic` | `faq_sub_topic` | nama+SS |
| | `faq_article` | `uq_faq_article_nama` | `id_faq_sub_topic` | `title` | nama+SS |
| DBV-003 (`CreateHariLibur`, `CreateKursem`, `CreateKantor`) | `jenis_libur` | `uq_jenis_libur_nama` | — | `jenis_libur` | nama+SS |
| | `hari_libur` | `uq_hari_libur_tgl_mulai` | — | `tgl_mulai` (DATE) | nilai tanggal |
| | `bidang_kursem`, `instansi_kursem` | `uq_{tabel}_nama` | — | kolom senama | nama+SS |
| | `kantor` | `uq_kantor_nama` | — | `nama_kantor` | nama+SS |
| DBV-004 (`CreateMasterKenaikanPangkat`, `CreateMasterPendidikan`) | `pangkat` | `uq_pangkat_nama` | — | `gol_ruang` | nama+SS |
| | `jenis_kp` | `uq_jenis_kp_nama` | — | `jenis_kp` | nama+SS |
| | `gol_pppk` | `uq_gol_pppk_nama` | — | `gol_pppk` | nama+SS |
| | `jenjang_pendidikan` | `uq_jenjang_pendidikan_nama` | — | `jenjang_pendidikan` | nama+SS |
| | `jenjang_pendidikan` | `uq_jenjang_pendidikan_singkat` | — | `jenjang_pendidikan_singkat` (`uniqueFields`) | kode |
| | `bidang_pendidikan` | `uq_bidang_pendidikan_nama` | — | `bidang_pendidikan` | nama+SS |
| | `jurusan_pendidikan` | `uq_jurusan_pendidikan_nama` | `id_bidang_pendidikan` | `jurusan_pendidikan` | nama+SS |
| DBV-005 (`CreateDiklatHukdisKonketTandaJasa`) | `diklat` | `uq_diklat_nama` | `jenis_diklat` (`uniqueScope`) | `nama_diklat` | nama+SS (kondisional, 2.2 #3) |
| | `tingkat_hukdis` | `uq_tingkat_hukdis_nama` | — | `tingkat_hukdis` | nama+SS |
| | `jenis_hukdis` | `uq_jenis_hukdis_nama` | `id_tingkat_hukdis` | `jenis_hukdis` | **nama** (tanpa SS, 6.5 #2) |
| | `jenis_konket` | `uq_jenis_konket_nama` | — | `jenis_konket` | nama+SS |
| | `jenis_konket` | `uq_jenis_konket_old_id` | — | `old_id` (INT) | bilangan (`'08'` = `8`) |
| | `tanda_jasa` | `uq_tanda_jasa_nama` | — | `tanda_jasa` | nama+SS |
| DBV-010 (akun) | `pengguna` | `username`, `nip` (dari `id_pegawai` legacy) | — | `username`, `id_pegawai` | kode tanpa SS (`trim()` PHP, unicode_ci) |
| D-6 (aplikasi, tanpa index) | `pengguna` | — | — | `email` | kode tanpa SS (`trim()` PHP, unicode_ci), **PERIKSA** |

PK kode wilayah (`provinsi`, `kabupaten_kota`, `kecamatan`, `kelurahan`), kolom induknya, dan empat kode wilayah `kantor` tidak tercantum di tabel di atas karena bukan UNIQUE non-PK. Nilai impornya = hasil `trim()` PHP di semua kueri dan di transformasi (2.2 #7). PK yang menjadi ganda setelah trim dicek di B9.

### 2.2 Aturan pembanding

1. **Collation.** Kunci dibandingkan dengan `COLLATE utf8mb4_unicode_ci`: tidak peka huruf besar/kecil dan aksen, serta PAD SPACE (spasi di akhir diabaikan). Kolom legacy selalu di-`CONVERT(… USING utf8mb4)` dulu.
   - Legacy `agama.agama` memakai `utf8mb4_0900_ai_ci` NO PAD di tabel latin1 (`simpeg_prod.sql:175-186`) [K].
   - Akibatnya, `'Islam'` dan `'islam '` berbeda di legacy tetapi bertabrakan di v2. Kasus ini terbukti di 6.2.
   - Dump struktur prod 01-10 [K] menunjukkan tiga tabel lain dengan collation berbeda: `jenis_status` dan `jenis_kp` (collation tabel `utf8mb4_0900_ai_ci`, NO PAD), serta `pangkat` (charset `utf8` mb3, `utf8_general_ci`). Pembandingnya tetap benar karena semua kolom di-`CONVERT` lalu di-`COLLATE utf8mb4_unicode_ci` (2.9).
2. **Normalisasi = aplikasi.** Kunci meniru nilai yang akan disimpan aplikasi v2:
   - **nama**: `MasterService::normalizeName()` / `HariLiburService::nama()` → `preg_replace('/\s+/u', ' ')` lalu trim.
   - **field unik lain** (kunci "kode": `jenjang_pendidikan_singkat`, `username`, `id_pegawai`/`nip`, `email`): `MasterField::normalize()` / `UserService` → `trim()` PHP. SQL meniru kelas karakternya persis (spasi, `\t`, `\n`, `\r`, `\0`, `\x0B`) dengan pola `@dbv011_trim` = `^[ \t\n\r\x{0B}\x{00}]+|[ \t\n\r\x{0B}\x{00}]+$`, bukan `[[:space:]]` yang ikut membuang NBSP, `\f`, dan U+2000–U+3000 (lapor-lebih). Pola itu berisi **escape regex** yang disusun dengan `CHAR(92)`, bukan karakter kontrol asli; alasannya di 2.2 #6. Diuji identik dengan `trim()` PHP 8.4 pada 73 string di tiga `sql_mode`, langsung maupun lewat tabel turunan + WHERE (6.5). Kueri tabrakan akun 2.4 langkah 6 memakai pola yang sama.
   - **Impor wajib memakai normalisasi yang sama** (2.4 langkah 5). Tanpa itu, v2 akan berisi nilai yang tidak mungkin dibuat aplikasi, dan hasil audit tidak lagi memprediksi tabrakan UNIQUE.
3. **stripslashes per tabel.**
   - Legacy menyimpan teks yang sudah `addslashes`, lalu query builder meng-escape lagi. Akibatnya data berisi `\'`, `\"`, atau `\\` literal [K]. Buktinya:
     - Batch 1: `Lm_umum.php:203` (agama), `404` (jenis_pegawai), `612` (jenis_status), `830` (provinsi), `1072` (kabupaten_kota), `1313` (kecamatan), `1561` (kelurahan).
     - FAQ: G-10 6.3 #1.
     - G-04/G-05: 6.3 #2.
     - `kantor`: G-07 6.3 #1.
     - `diklat`, `tingkat_hukdis`, `jenis_konket`, `tanda_jasa`: G-06 6.5 #2.
   - **Pengecualian:**
     - `jenis_hukdis` disimpan mentah → kunci tanpa stripslashes.
     - `diklat` dari jalur `dm_diklat` juga mentah → stripslashes kondisional, diputuskan per baris lewat bagian D skrip.
   - `jenis_libur` dan kursem tidak tercatat di dokumen G-07 [I]. Kuncinya tetap memakai stripslashes, yang tidak berpengaruh bila tidak ada backslash. Nama ber-backslash tetap muncul di bagian D.
   - **SQL meniru `stripslashes` PHP secara penuh** dengan `REGEXP_REPLACE(x, '\\(.?)', '$1')`: setiap backslash dibuang dan karakter sesudahnya dipertahankan, dibaca dari kiri. Jadi `\\` → `\`, `\'` → `'`, `\d` → `d`, dan backslash di akhir dibuang. Polanya disimpan di variabel sesi `@dbv011_ss` yang disusun dengan `CHAR(92, 92)` (2.2 #6).
     - Diuji terhadap `stripslashes` PHP 8.4 pada 23 string (hex) di tiga `sql_mode` (6.3 butir 7): hasilnya sama, kecuali `\0` (PHP menjadi byte NUL, SQL menjadi `0`).
     - Versi putaran 1 hanya mengganti `\\`, `\'`, `\"` berurutan. Akibatnya `Ber\at` tidak bertabrakan dengan `Berat` (duplikat terlewat), sedangkan `Bintang \\'Y` dianggap sama dengan `Bintang 'Y` (duplikat palsu). Keduanya kini benar (6.3 butir 7).
     - Rujukan grup `$1` adalah sintaks ICU MySQL 8. MariaDB memakai `\\1`, sehingga di MariaDB hasil untuk nilai ber-backslash berbeda. Ini sejalan dengan 2.4 langkah 1 (salinan wajib MySQL 8).
     - Semua nilai ber-backslash tetap muncul sebagai PERIKSA di bagian D dan dicocokkan ulang dengan `stripslashes` PHP sebelum grup duplikat diputuskan (2.5). Hasil akhir per baris memakai `stripslashes` PHP saat ekspor.
4. **Semua status.** Baris status 2/10 (dan status legacy lain) ikut diperiksa, karena UNIQUE v2 berlaku untuk semua status.
5. **Lingkup** = kolom depan UNIQUE v2, dengan nilai impornya. `jenis_status.status_pegawai` (v2 TINYINT; `scopeValues()` men-trim lalu meng-cast, dan form legacy mengisinya lewat `addslashes`, `Lm_umum.php:611`) dibandingkan sebagai bilangan: `'1'`, `' 1'`, `'01'`, dan `'+1'` satu lingkup di A1, B6 (`pangkat.cpns`), dan B8. Kode induk wilayah (lingkup `kabupaten_kota`/`kecamatan`/`kelurahan`) = hasil `trim()` PHP (#7): `' 11'` dan `'11'` satu lingkup. Semua kolom lingkup di v2 NOT NULL, kecuali yang tidak berlaku. Karena itu nilai NULL di lingkup dilaporkan terpisah sebagai WAJIB_0, bukan dianggap unik: induk NULL di B2 (termasuk empat kode wilayah `kantor`), `jenis_diklat` di B4, `old_id` di B5, serta `jenis_status.status_pegawai` dan `pangkat.cpns` di B8. Nilai NULL di ke-27 kolom nama/unik katalog (semuanya NOT NULL di v2, diukur di `information_schema` DB scratch) dilaporkan WAJIB_0 di bagian D.
6. **Batas yang diketahui:**
   - `[[:space:]]` (kunci nama): MySQL 8 (ICU) mencakup NBSP U+00A0, sama dengan `\s` PHP ber-`/u`. PCRE MariaDB 10.4 hanya mencakup whitespace ASCII.
   - Kolom kode/bilangan di bagian B (`old_id`, `row_jurusan`, `status_pegawai`, `cpns`) di-trim dengan `[[:space:]]` sebelum diuji; transformasi impor men-trim nilai yang sama sebelum di-cast.
   - `utf8mb4_unicode_ci` = UCA 4.0.0 di kedua engine.
   - **Teks kueri skrip tanpa backslash.** Pola yang memuat backslash atau karakter kontrol (`@dbv011_ss`, `@dbv011_trim`, dan `@dbv011_bs` untuk `LOCATE` di bagian D) disusun sekali di kepala skrip dengan `CHAR()`. Putaran 2 memakai literal hex (`_utf8mb4 X'…'`), dan itu ternyata tidak cukup: *derived condition pushdown* MySQL 8.0.30 mem-parse ulang kondisi WHERE yang didorong ke tabel turunan, dan pada `sql_mode` `NO_BACKSLASH_ESCAPES` literal ber-backslash di kondisi itu berubah. Terukur di 6.4: kode wilayah 7 digit yang berakhiran `0` dilaporkan "bukan 7 digit", dan uji `trim()` memberi 52 selisih, semuanya di `NO_BACKSLASH_ESCAPES` lewat tabel turunan + WHERE. Dengan variabel sesi, hasil ketiga `sql_mode` identik.
   - **Pola tanpa byte NUL (putaran 4).** Putaran 3 menyusun `@dbv011_trim` dari karakter kontrol asli (`CHAR(9, 10, 13, 11, 0)`), sehingga pola itu memuat byte NUL di posisi ke-8 (terukur: `LOCATE(CHAR(0), …) = 8`). Menurut pra-review internal putaran 3, MariaDB 10.4 masih memakai PCRE1 yang meng-compile pola sebagai string C berakhiran NUL (PCRE2 dengan panjang eksplisit baru dipakai mulai 10.5, MDEV-14024). Akibatnya pola terpotong menjadi `^[ <TAB><LF><CR><VT>`, kelas karakternya tanpa `]`, dan kueri yang memakainya (A4 singkatan, A6, B1, B2 wilayah dan `kantor`, B9, D `kode_ss`) diperkirakan gagal ERROR 1139. MariaDB tidak tersedia di mesin developer, jadi perilaku ini belum diuji langsung (checklist Bagian 5). Sejak putaran 4 kelasnya berisi **escape regex** yang disusun dengan `CHAR(92)`: `\t`, `\n`, `\r`, `\x{0B}`, `\x{00}`. ICU (MySQL 8) dan PCRE (MariaDB) sama-sama mengenal escape ini. Pola tidak lagi memuat byte NUL maupun karakter kontrol (terukur: `LOCATE(CHAR(0), @dbv011_trim) = 0`), dan teks kueri tetap tanpa backslash.
   - Selisih yang lolos audit tetap tertahan UNIQUE saat impor (error 1062), sehingga tidak ada duplikat yang diam-diam masuk.
7. **Kode wilayah = nilai hasil `trim()` PHP, di semua kueri dan di impor (putaran 4).** Berlaku untuk PK `provinsi`/`kabupaten_kota`/`kecamatan`/`kelurahan`, kolom induknya, dan empat kode wilayah `kantor`. Dasarnya perilaku aplikasi: `MasterService::createLocked()` men-trim kode dan kode induk, dan `MasterField::normalize()` men-trim field rujukan `kantor`, sehingga v2 tidak pernah menyimpan kode berspasi. Aturan ini dipakai di:
   - **A1**: lingkup `kabupaten_kota`/`kecamatan`/`kelurahan` = kode induk hasil trim (kolom `anggota` menampilkan kode induk mentah);
   - **B1**: sentinel dikenali dari kode hasil trim;
   - **B2**: rantai wilayah dan `kantor` → wilayah dibandingkan dengan kode hasil trim di kedua sisi, dalam `utf8mb4_unicode_ci` (kolom `rujukan` menampilkan nilai mentah);
   - **B9**: PK ganda setelah trim = WAJIB_0 (PK v2 menolak impor, 1062); kode yang berubah oleh trim = PERIKSA;
   - **transformasi impor** (2.4 langkah 5) men-trim kode yang sama.

   Sebelum putaran 4, A1/PK/B2 memakai nilai mentah sedangkan B9 dan transformasi memakai nilai hasil trim. Akibatnya provinsi `' 11'` dan `'11'` dengan kabupaten senama di bawah masing-masing tidak menghasilkan satu baris pun, padahal impor ber-trim gagal 1062 di PK `provinsi` dan di `uq_kabupaten_kota_nama`. Sebaliknya, rujukan `' 1101'` dilaporkan yatim padahal cocok setelah trim (6.5).

### 2.3 Isi skrip audit (`DBV-011-audit-duplikat-master-legacy.sql`)

Seluruh kueri audit berjalan dalam `START TRANSACTION READ ONLY`. Isinya hanya `SELECT`, `SET SESSION`/variabel, dan `PREPARE`/`EXECUTE` atas SELECT yang disusun dari `information_schema`; teks kueri dinamis ikut dicetak sebelum dijalankan. Sebelum transaksi ada satu `PREPARE`/`EXECUTE` bersyarat engine: `SET SESSION information_schema_stats_expiry = 0` di MySQL 8, `DO 0` di MariaDB (variabel itu tidak ada di MariaDB). Tidak ada INSERT, UPDATE, DELETE, maupun DDL.

Kueri yang error dicatat di hasil lalu dilewati berkat `--force`. **Error berarti audit belum lengkap**, karena kueri yang error tidak menghasilkan baris sehingga "WAJIB_0 kosong" terpenuhi tanpa pernah diperiksa. Aturan triage-nya:
- Hanya satu error yang boleh dibiarkan: **1146** untuk tabel yang **memang tidak ada** di salinan, dipastikan dengan `SHOW TABLES` dan dicatat di log 2.8. (Putaran 2 masih mengecualikan 1193 `information_schema_stats_expiry` di MariaDB; sejak putaran 3 penyetelannya bersyarat engine sehingga error itu tidak muncul lagi.)
- Error lain, terutama **1054** karena nama kolom tabel [I] (wilayah, `jenis_status`, `tingkat_hukdis`, `kantor`, dan lainnya) berbeda di salinan: sesuaikan kueri dengan kolom yang nyata, jalankan ulang, lalu catat penyesuaiannya di log 2.8.
- Kueri dinamis D dan E mencetak daftar entri yang terlewat, yaitu tabel, kolom, atau PK yang tidak ditemukan (baris `PERIKSA D: … terlewat` dan `PERIKSA E: … tidak ada`). Daftar ini diperlakukan sama dengan error di atas.
- **WAJIB_0 panjang atau tipe pada kolom berlabel [I]** (D, A6 panjang akun, B8, B9): tipe kolom v2 masih dugaan, jadi **skema dicocokkan dulu dengan DDL dump** (G-01 8.3, G-04 Bagian 7, G-06 Bagian 3 dan 6.4, G-07 3.1, G-10 Bagian 3). Bila dugaan v2 yang keliru (mis. `jenis_pegawai`/`jenis_status` VARCHAR(50) [I], G-01 8.3 #1, atau kode wilayah CHAR(2/4/7/10) [I]), skema dikoreksi lewat migration ALTER baru + review DBV (migration di `main` tidak diedit), angka panjang di katalog D/A6/B9 disesuaikan, lalu audit dijalankan ulang. Data baru diubah (PERBAIKI, 2.5) bila tipe v2 memang benar. Jangan memotong atau mengganti nama data hanya agar muat di tipe dugaan.

Arti awalan kolom `cek`:
- **WAJIB_0** harus 0 baris sebelum impor. Temuan jenis ini membuat impor gagal (1062/1048/1452/3819/1406/1264/1366/1292) atau merusak data.
- **PERIKSA** tidak menggagalkan impor, tetapi wajib diputuskan bersama pemilik proses.
- **INFO** cukup dicatat.

| Bagian | Isi | Awalan | Butir asal |
|---|---|---|---|
| 0 | Jam dan `sql_mode` sesi instance salinan (INFO). Sejak putaran 5 juga prasyarat salinan: kolom `password_decode` di tabel mana pun dan trigger di skema salinan (WAJIB_0, 2.4 langkah 1) | INFO / WAJIB_0 | ISSUE-022 / D-11; 1.4 butir c |
| A1–A5 | Duplikat untuk 28 UNIQUE master (satu kueri per index). Kolom `anggota` = `id:status:nilai legacy` (status NULL tampil sebagai `NULL`; wilayah ditambah kode induk mentah). Lingkup `jenis_status.status_pegawai` = nilai impor sebagai bilangan (2.2 #5); lingkup wilayah = kode induk hasil trim (2.2 #7) | WAJIB_0 | G-01 8.4 #2, G-10 6.3 #5, G-07 6.3 #2–#3, G-04 6.3 #4, G-06 6.5 #1/#3 |
| A6 | Duplikat `username` dan `id_pegawai` (WAJIB_0); nilai impor yang melebihi kolom v2: `username` > 100, `nip` > 30, `name`/`email` > 150 (WAJIB_0, strict → 1406); email ganda dan akun role 2/6/7 tanpa `id_pegawai` (PERIKSA). Sejak putaran 5:<br>• profil format hash `pengguna.password` per kelas: tepat 32 heks = INFO, kelas lain = PERIKSA;<br>• daftar akun yang hash-nya bukan 32 heks, tanpa nilai hash (PERIKSA);<br>• akun tanpa email per role dan status (PERIKSA) | WAJIB_0 / PERIKSA / INFO | A-01 9.1, 9.8 langkah 6, 9.10, D-4, D-6; A-02b |
| B1 | Kode wilayah legacy (hasil trim, 2.2 #7) yang sama dengan sentinel LAIN-LAIN v2 | PERIKSA | G-07 2.5, 6.3 #5 |
| B2 | Rujukan yatim untuk semua FK antar-master v2: rantai wilayah (kode hasil trim di kedua sisi, 2.2 #7), FAQ (termasuk tabel anak `faq_rate.id_faq_article`, `faq_related_article.id_article_main`/`id_article_related`), jurusan→bidang, jenis_hukdis→tingkat (termasuk NULL), hari_libur→jenis_libur, kantor→wilayah (kode hasil trim; kode sentinel dilewati, kode NULL dilaporkan). `hari_libur` tanpa jenis dan `faq_related_article` yang merujuk dirinya sendiri = PERIKSA | WAJIB_0 / PERIKSA | G-06 6.5 #4, G-07 6.3 #2/#4, G-10 6.3 #10 |
| B3 | `hari_libur`: rentang terbalik (CHECK) = WAJIB_0; rentang tumpang tindih = PERIKSA | WAJIB_0 / PERIKSA | G-07 6.3 #2 |
| B4 | CHECK `chk_diklat_jenis_diklat` (1–5) dan `chk_jenjang_pendidikan_row_jurusan` (7 kode, biner) | WAJIB_0 | G-06 6.5 #5, G-04 6.3 #11 |
| B5 | `jenis_konket.old_id` NULL, kosong, bukan angka, ≤ 0, atau > 2.147.483.647 (WAJIB_0). Setiap `absen_ijin.kategori` dan `d_konket.kategori` yang tidak punya pasangan `old_id`, dengan jumlah barisnya (PERIKSA). Batas bawah kode baru = nilai terbesar dari `old_id`, `absen_ijin.kategori`, dan `d_konket.kategori` (INFO) | WAJIB_0 / PERIKSA / INFO | G-06 6.5 #3, C4 |
| B6 | Kapasitas urutan TINYINT per lingkup: `diklat` per jenis, `jenis_hukdis` per tingkat (baris ber-status NULL dihitung tampil; kolom `st_null`), `pangkat.order` (level, mode manual) = WAJIB_0. Peta lengkap `pangkat.order` per `cpns` (nilai impor sebagai bilangan) sebagai dasar keputusan UNIQUE(cpns, order): ganda dalam satu `cpns` atau bernilai 0 = PERIKSA, sisanya INFO | WAJIB_0 / PERIKSA / INFO | G-06 6.5 #5, G-04 6.3 #5 |
| B7 | Baris hard-coded: `tanda_jasa` 26/27/28/44 (44 harus `LAIN-LAIN`), `jenis_konket` id 2/4/5/6 dan `old_id` 8/10/13, `diklat` 8, `jenis_kp` 1/2/3/5/6, `jenjang_pendidikan` 1/2/3, `bidang_pendidikan` 98, `jurusan_pendidikan` 1185, `gol_pppk` 7/9/10/11/12. Hasilnya menandai `TIDAK ADA` atau nama yang salah | INFO | G-06 2.6 / 6.5 #6, G-04 6.4 |
| B8 | Kolom kode TINYINT NOT NULL v2 dengan pilihan tetap 1/2 di aplikasi: `jenis_status.status_pegawai` (lingkup UNIQUE) dan `pangkat.cpns`. Dibandingkan sebagai bilangan setelah trim (`'01'` = 1, kolom `nilai_impor`). NULL, bukan bilangan, atau di luar TINYINT = WAJIB_0 (1048/1366/1264); bilangan lain di luar 1/2 = PERIKSA | WAJIB_0 / PERIKSA | 2.2 #5, G-04 D12 |
| B9 | Kode wilayah (PK dan kolom induk di tabel wilayah, serta empat kode `kantor`), nilai impor = hasil trim (2.2 #7): (1) PK ganda setelah trim, mis. `' 11'` dan `'11'` = WAJIB_0 (PK v2 → 1062); (2) terhadap CHAR(2/4/7/10) v2 [I]: lebih panjang = WAJIB_0 (strict → 1406; cocokkan tipe dengan dump dulu, aturan triage di atas); bukan tepat N digit (titik, huruf, lebih pendek) = PERIKSA, karena aplikasi v2 hanya menerima tepat N digit; berubah oleh trim = PERIKSA (kolom `nilai_legacy` dan `nilai_impor`) | WAJIB_0 / PERIKSA | G-01 8.3, G-07 3.1, ISSUE-008 |
| C | Profil per tabel (28 master + `pengguna`): jumlah baris per status, `st_null` (status NULL, dihitung eksplisit), `st_lain` (status NULL atau di luar 1/2/10; mencakup `st_null`), `non_10` (NULL dihitung tampil), `id_maks`, `counter_ai`, dan tipe PK v2. `faq_rate` dan `faq_related_article` hanya jumlah baris. Kolom `cek` mengikuti `peringatan`: **PK TINYINT > 127** atau **baris tampil > 127** untuk master berurutan TINYINT global = WAJIB_0 (impor gagal 1264, sama dengan B6); **status NULL** (kolom status v2 NOT NULL) atau status di luar 1/2/10 (keduanya dinormalkan di langkah 5), atau **counter ≥ 128** di tabel ber-PK TINYINT (2.7) = PERIKSA. Untuk `pengguna` (status legacy 0/1/2) hanya status NULL yang diberi peringatan | WAJIB_0 / PERIKSA / INFO | G-06 6.5 #5/#6/#10, G-04 6.3 #3/#15/#16, G-07 6.3 #6, G-10 6.3 #10 |
| D | Kueri dinamis atas katalog 27 kolom nama/unik: nilai NULL (semua NOT NULL di v2 → 1048), atau nilai impor yang kosong atau melebihi panjang kolom v2 (strict → 1406) = WAJIB_0; nilai yang berubah oleh normalisasi atau masih memuat backslash = PERIKSA. Entri katalog yang tabel/kolom/PK-nya tidak ditemukan dicetak sebagai daftar terlewat | WAJIB_0 / PERIKSA | 2.2, G-06 6.5 #2, G-01 8.3 #1 |
| E | Kueri dinamis: tanggal nol / bulan-hari nol di seluruh kolom DATE/DATETIME/TIMESTAMP ke-28 tabel master dan `pengguna` (tabel yang tidak ada dicetak sebagai daftar). E2: daftar kolom TIMESTAMP di seluruh skema salinan, sebagai dasar R1b | WAJIB_0 / INFO | A-01 9.8 langkah 6, 9.10; aturan R1b dan R3 (3.2) |

Butir asal yang **tidak** dicakup skrip ini tetap menjadi langkah manual dari dokumen asalnya (G-04 6.3 #5 dan G-10 6.3 #10 kini dicakup B6 dan C):
- **G-04 6.3:** #7 (jurusan `S_2` = `S_1`), #8/#10 (orphan di tabel anak Fase 3, yang belum ada di v2), #9 (`gol_ruang` di luar kunci standar).
- **G-06 6.5 #3:** tabel arsip `absen_ijin_20240106` dan `absen_ijin_20240702` (`simpeg_prod.sql:74, 108`) tidak ikut dicek pasangannya. Bila tabel arsip itu ikut dimigrasi, jalankan kueri pasangan B5 dengan nama tabel arsip.
- **G-07 6.3 #4:** rantai wilayah kantor tidak konsisten, `kode_pos` bukan 5 digit, `telp`/`faks` > 50, dan `*_lain` terisi.
- **G-10 6.3:** #2/#3 (sanitasi konten dan gambar), #6 (`faq_rate.nip` orphan, butuh `pegawai`).
- **A-01 9.10:** username non-ASCII/berkarakter kontrol, dan `expired_at` (keputusan FCP). Panjang `username` > 100 kini dicek A6.
- **Nilai [I]** (G-01 8.3, G-04 Bagian 7, G-06 Bagian 3 dan 6.4, G-07 3.1, G-10 Bagian 3): dicocokkan dengan DDL dump sebagai prasyarat impor (2.4 langkah 6). Skrip hanya menguji data terhadap tipe dugaan v2 (D, A6, B8, B9).

### 2.4 Langkah runbook impor

1. **Siapkan salinan.**
   - **Sumber salinan (putaran 5).** Untuk impor final, salinan = **dump final yang diambil saat freeze** legacy, mengikuti Cutover Plan (Adendum Operasional Privat, dokumen privat tim). Saat itu `password_decode` di produksi sudah dikosongkan lewat langkah Cutover Plan, bukan lewat runbook ini. Salinan dari dump sebelum freeze hanya dipakai untuk audit awal dan gladi. Langkah 1–10 diulang pada dump final, dan hasil audit, log 2.8, serta keluaran counter yang dilampirkan ke kartu harus berasal dari dump final.
   - Restore dump data dan struktur penuh `simpeg01` ke **skema tersendiri**, terpisah dari produksi dan dari skema v2. Instance-nya tidak harus terpisah: boleh instance yang sama dengan DB v2 bila engine-nya memenuhi syarat di bawah (kueri tabrakan akun di langkah 6 dan skrip counter di langkah 8 memanfaatkan itu). Bila instance v2 tidak memenuhi syarat (mis. MariaDB, D-11), salinan di-restore di instance MySQL 8 lain, lalu dipakai jalur fallback di langkah 6 dan 8.
   - **Engine: MySQL 8; versinya mengikuti keputusan AS-02** (putaran 5; sampai putaran 4 syaratnya ≥ 8.0.30).
     - AS-02 = asumsi PRD tentang engine dan instance DB v2, dengan pemilik TL + DBA. Header dump struktur prod 01-10 menulis MySQL 8.0.21. Versi terkini dan parameter server lain (`sql_mode`, `time_zone`, `table_rows`) menunggu kartu ISSUE-024 di board tim.
     - Salinan tetap wajib MySQL 8: dump memakai `utf8mb4_0900_ai_ci` yang tidak dikenal MariaDB 10.4, dan tiruan stripslashes memakai rujukan grup `$1` (2.2 #3).
     - Skrip diuji di 8.0.30 (6.1). Bila salinan memakai versi lain (mis. 8.0.21 seperti produksi), jalankan dulu uji deteksi data sintetis seperti 6.2/6.5 di instance itu sebelum hasil audit dipakai, lalu catat versinya di log 2.8.
   - Dump dibuat dengan `mysqldump` bawaan (`--tz-utc` aktif; **jangan** `--skip-tz-utc`), agar instan kolom TIMESTAMP terjaga saat restore (R1b). Bila dump terlanjur dibuat dengan `--skip-tz-utc`, zona sesi restore harus sama dengan zona server produksi.
   - Bila dump dibuat dengan alat selain `mysqldump` (mis. HeidiSQL, yang membuat dump struktur `simpeg_prod.sql`), perilaku zona TIMESTAMP-nya belum diketahui. Sebelum R1b dipakai, bandingkan contoh `SELECT <pk>, UNIX_TIMESTAMP(<kolom_timestamp>)` untuk beberapa baris per kolom TIMESTAMP (daftar dari E2) di sumber (dijalankan pemegang akses produksi, hanya baca) dan di salinan. Nilainya harus sama; bila berbeda, restore ulang dengan `mysqldump` atau catat selisihnya di log 2.8 dan koreksi di transformasi.
   - **Bersihkan salinan segera setelah restore, sebelum langkah lain (putaran 5).** Langkah ini hanya untuk salinan; tidak pernah dijalankan di produksi maupun di v2.
     1. Buang semua **trigger** salinan. Trigger legacy menulis ke tabel lain, termasuk ke skema aplikasi lain di instance yang sama (2.9). Tanpa langkah ini, UPDATE/DELETE di salinan kerja (2.5) bisa mengubah data di luar salinan, atau gagal bila skema tujuannya tidak ada.
     2. Buang kolom **`password_decode`** dari semua tabel salinan: `pengguna` dan tabel arsip/cadangan akun. Tabelnya dicari lewat `information_schema`, bukan dari daftar tetap. Kolom ini berisi password plaintext legacy, tidak pernah diimpor, dan tidak dibaca oleh langkah mana pun.

     Caranya:
     - Simpan kueri berikut sebagai `cetak_bersihkan.sql`.
     - Jalankan `mysql -N -B -r --default-character-set=utf8mb4 --user=<akun_salinan> -p <db_salinan> < cetak_bersihkan.sql > bersihkan_salinan.sql`. `<akun_salinan>` adalah akun pemilik skema salinan, bukan akun baca audit.
     - Tinjau isinya. Baris pertama menyebut skema dan versi; pastikan itu skema salinan.
     - Jalankan `mysql --default-character-set=utf8mb4 --user=<akun_salinan> -p <db_salinan> < bersihkan_salinan.sql`.
     ```sql
     SELECT perintah FROM (
               SELECT 0 urut, CONCAT('-- salinan: ', DATABASE(), ', ', VERSION()) perintah
     UNION ALL SELECT 1, CONCAT('DROP TRIGGER `', TRIGGER_NAME, '`;')
                 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()
     UNION ALL SELECT 2, CONCAT('ALTER TABLE `', TABLE_NAME, '` DROP COLUMN `', COLUMN_NAME, '`;')
                 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'password_decode') x
      ORDER BY urut, perintah;
     ```
     - **Bukti selesai:** bagian 0 skrip audit tidak menghasilkan baris `WAJIB_0 kolom password_decode …` maupun `WAJIB_0 trigger …`.
     - Uji putaran 5 (6.6): `ALTER TABLE … DROP COLUMN` tidak mengubah counter AUTO_INCREMENT. Meski begitu, kesetiaan counter (butir berikut) tetap dicek **sesudah** pembersihan.
     - Dump sebelum freeze masih memuat isi `password_decode`. Berkas dump dan salinannya diperlakukan sebagai data rahasia: disimpan terenkripsi, aksesnya dibatasi, dan dihapus sesuai retensi di Cutover Plan.
   - **Kesetiaan counter AUTO_INCREMENT salinan** (syarat DBV-005 6.5 #10, 2.7). Skrip counter langkah 8 dan kolom `counter_ai` bagian C membaca counter **salinan**. `mysqldump` bawaan menyertakan opsi tabel `AUTO_INCREMENT=`, tetapi dump data-saja (`--no-create-info`) ke tabel yang dibuat terpisah, atau alat yang tidak menyertakan opsi itu, membuat counter salinan = `MAX(id)+1`. Akibatnya counter legacy yang lebih tinggi (ID yang pernah dihapus keras) hilang dan langkah 8 diam-diam menyalin terlalu sedikit. Karena itu, pada saat yang sama dengan pembuatan dump, pemegang akses produksi menjalankan kueri berikut di sumber (hanya baca; `SET SESSION` hanya berlaku di sesinya). Kueri yang sama lalu dijalankan di salinan:
     ```sql
     SET @dbv011_s = IF(VERSION() LIKE '%MariaDB%', 'DO 0', 'SET SESSION information_schema_stats_expiry = 0');
     PREPARE dbv011_s FROM @dbv011_s;
     EXECUTE dbv011_s;
     DEALLOCATE PREPARE dbv011_s;
     SELECT TABLE_NAME tabel, AUTO_INCREMENT counter
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('agama', 'jenis_pegawai', 'jenis_status', 'faq_topic', 'faq_sub_topic', 'faq_article', 'jenis_libur',
                           'hari_libur', 'bidang_kursem', 'instansi_kursem', 'kantor', 'pangkat', 'jenis_kp', 'gol_pppk',
                           'jenjang_pendidikan', 'bidang_pendidikan', 'jurusan_pendidikan', 'diklat', 'tingkat_hukdis',
                           'jenis_hukdis', 'jenis_konket', 'tanda_jasa', 'pengguna')
      ORDER BY TABLE_NAME;
     ```
     - Kedua hasil (`mysql -N -B … > counter_sumber.txt` / `counter_salinan.txt`) harus identik baris demi baris, dan sama dengan `counter_ai` bagian C audit. Keduanya dilampirkan ke kartu.
     - Bila berbeda: catat di log 2.8. Selisih karena entri baru di produksi setelah dump berarti salinan basi, jadi buat dump ulang. Selisih karena cara restore (counter salinan lebih kecil) diperbaiki dengan menyamakan counter di salinan (bukan produksi): `ALTER TABLE <tabel> AUTO_INCREMENT = <counter sumber>`, lalu kueri di atas diulang. Cara lain, perintah langkah 8 ditulis manual dari counter sumber dengan aturan 2.7.
     - Diuji di 6.5: salinan `mysqldump` bawaan identik dengan sumber (23 tabel); salinan data-saja berbeda tepat di 4 tabel yang counter-nya melebihi `MAX(id)+1`.
   - Siapkan akun baca untuk audit.
   - Dump struktur penuh (DDL) dari sumber yang sama dipakai untuk mencocokkan nilai [I] (ISSUE-003). Pencocokan ini **prasyarat impor** (langkah 6), bukan sekadar catatan. Dump struktur prod 01-10 sudah tersedia dan selisihnya dicatat di 2.9; pencocokan diulang terhadap struktur dump final.
2. **Jalankan audit** (hasilnya dilampirkan ke kartu ISSUE-009 / DBV-011):
   ```
   mysql --force --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_salinan> \
         < backend/docs/db-review/sql/DBV-011-audit-duplikat-master-legacy.sql > hasil_audit.txt 2>&1
   ```
   - `-vvv` mencetak setiap kueri beserta "N rows in set" atau "Empty set", sehingga hasil membuktikan setiap kueri benar-benar jalan.
   - `--default-character-set=utf8mb4` wajib. Klien Windows bawaan memakai cp850 (terukur di 6.1).
   - Penyetelan `information_schema_stats_expiry` bersyarat engine, jadi tidak ada error 1193 di MariaDB (sejak putaran 3).
3. **Triage.**
   - **ERROR dulu** (aturan di 2.3): selain 1146 untuk tabel yang memang tidak ada, setiap error serta setiap baris `PERIKSA D/E: … terlewat` berarti audit belum lengkap. Sesuaikan kuerinya dengan kolom salinan yang nyata, lalu jalankan ulang langkah 2.
   - WAJIB_0 panjang/tipe pada kolom [I] (D, A6 panjang akun, B8, B9) → cocokkan dulu tipe kolom v2 dengan DDL dump; bila dugaan v2 keliru, skema dikoreksi (migration ALTER + DBV) sebelum data diubah (aturan di 2.3).
   - WAJIB_0 lainnya → dedupe atau perbaikan (2.5).
   - PERIKSA → keputusan pemilik proses, dicatat di log (2.8).
   - INFO → dicatat. `counter_ai` dipakai di langkah 8 (setelah dicocokkan dengan sumber di langkah 1), dan `st_lain` (termasuk `st_null`) harus 0 setelah normalisasi.
4. **Perbaiki di salinan kerja**, yaitu salinan kedua dari salinan audit, bukan produksi dan bukan v2. Kerjakan top-down (2.5), lalu **ulangi langkah 2–4 sampai tidak ada error di luar pengecualian 2.3 dan semua WAJIB_0 kosong**. Menggabungkan induk bisa memunculkan duplikat baru di tabel anak.
5. **Transformasi impor.** Langkah ini di luar skrip ini dan mengikuti G-xx 6.3 / G-06 6.5:
   - stripslashes per tabel, dengan normalisasi nama persis 2.2;
   - kode wilayah (PK, kolom induk, dan empat kode `kantor`) = hasil `trim()` PHP, sama dengan yang diaudit (2.2 #7);
   - status ENUM → `CAST(status AS CHAR)` (G-04 6.3 #3/#15); status NULL (`st_null` bagian C) dan status di luar 1/2/10 dipetakan eksplisit ke 1/2/10 sesuai keputusan di log 2.8, karena kolom `status` v2 NOT NULL (strict → 1048; bila kolom tidak diisi, nilainya diam-diam menjadi default);
   - `order` 1..n per lingkup, kecuali `pangkat` (level);
   - kolom baru: `affect_tukin` = 1, `masa_sanksi_bulan` = NULL;
   - pemetaan `created_by`/`updated_by`;
   - sanitasi konten FAQ;
   - konversi stempel waktu **R1a/R1b, R2, R3** (3.2) di sesi baca `+00:00` (**R4**): DATETIME dikonversi, TIMESTAMP tidak;
   - salinan nama di riwayat disalin apa adanya (G-06 6.5 #9);
   - `pengguna` (putaran 5):
     - `password` yang masuk kelas 32 heks (A6) → `password_legacy` apa adanya, dengan `password` v2 NULL. Verifier menyamakan huruf besar/kecil, jadi heks huruf besar tidak perlu diubah;
     - akun dengan format hash lain diperlakukan sesuai keputusan di log 2.8 (2.5);
     - `password_decode` tidak pernah dipilih; kolomnya sudah dibuang di langkah 1;
     - pemetaan kolom akun lain (`id` → `id_pengguna`, `id_pegawai` → `nip`, `UserLevel` → `user_level`, status) mengikuti A-01 dan DBV-009.
6. **Impor ke DB v2** (Dev dulu). Ini penulisan manual setelah approval DBV (ISSUE-014).
   - **Prasyarat: D-12 tuntas** (A-01 9.7, keputusan Bagian 4 #11). Tipe `pengguna.id_pengguna` v2 (INT UNSIGNED) berbeda dengan legacy `pengguna.id` dan kolom `*_by`/`id_pengguna` legacy (INT signed). DBV-010 menetapkan penyelarasannya di DBV-009 dan **"wajib tuntas sebelum FK `*_by` dibuat atau data legacy diimpor"**. Runbook ini ikut mengimpor `pengguna` dan `created_by`/`updated_by` master, jadi migration DBV-009 (PK + `token.id_pengguna` + `audit_logs.id_pengguna_actor`) harus sudah disetujui DBV dan dijalankan.
   - **Prasyarat: semua nilai [I] tabel yang diimpor sudah dicocokkan dengan DDL dump** (langkah 1; keputusan Bagian 4 #11): G-01 8.3, G-04 Bagian 7, G-06 Bagian 3 dan 6.4, G-07 3.1, G-10 Bagian 3. Selisih dikoreksi lewat migration ALTER baru + review DBV (migration di `main` tidak diedit). Setelah itu angka panjang di katalog D/A6/B9 disesuaikan dengan tipe hasil koreksi, lalu audit (langkah 2–4) dijalankan ulang. Tanpa langkah ini, WAJIB_0 "lebih dari kolom v2" bisa keliru diselesaikan dengan memotong atau mengganti nama data, padahal yang salah adalah dugaan tipe v2.
   - **Prasyarat: tabel target v2 kosong** (keputusan Bagian 4 #10). Audit hanya membaca legacy, jadi tabrakan PK, `username`, NIP, atau nama dengan baris yang sudah ada di v2 tidak terprediksi dan baru muncul sebagai 1062 di langkah 7.
     - Pengecualian: 4 baris sentinel wilayah (migration `100200`) dan akun `pengguna` yang disepakati.
     - Cek sebelum impor: `SELECT COUNT(*)` untuk setiap tabel target. Hasilnya harus 0, kecuali `provinsi`/`kabupaten_kota`/`kecamatan`/`kelurahan` = 1 (sentinel) dan `pengguna` = akun yang disepakati.
     - Dev berisi data QA di tabel master dan akun QA. Data QA master dikosongkan dulu (backup, urutan anak → induk, sentinel tidak disentuh), sebagai penulisan manual di jendela impor yang sama.
     - Akun v2 yang sudah ada (Dev: akun QA; Prod: akun admin awal bila sudah dibuat) diperiksa dulu dengan kueri tabrakan berikut, lalu diputuskan per akun (Bagian 4 #10). Kuerinya hanya membaca; salinan kerja legacy berada di instance yang sama dengan nama skema `<legacy>`. Normalisasinya sama dengan A6 (`trim()` PHP lewat `@dbv011_trim`, 2.2 #2):
       ```sql
       SET @dbv011_kls  = CONCAT(' ', CHAR(92), 't', CHAR(92), 'n', CHAR(92), 'r', CHAR(92), 'x{0B}', CHAR(92), 'x{00}');
       SET @dbv011_trim = CONVERT(CONCAT('^[', @dbv011_kls, ']+|[', @dbv011_kls, ']+$') USING utf8mb4);
       SELECT v.id_pengguna, v.username, v.nip, l.id legacy_id, l.username legacy_username, l.id_pegawai legacy_nip
         FROM pengguna v
         JOIN <legacy>.pengguna l
           ON l.id = v.id_pengguna
           OR REGEXP_REPLACE(CONVERT(l.username USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci = v.username
           OR REGEXP_REPLACE(CONVERT(l.id_pegawai USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci = v.nip;
       ```
     - **Fallback bila salinan legacy tidak satu instance dengan DB v2** (mis. v2 MariaDB, langkah 1). Sisi v2 tetap hanya dibaca:
       1. Di DB v2, cetak identitas akun sebagai perintah INSERT (tanpa kolom lain; `-r` agar backslash hasil `QUOTE()` tidak di-escape dua kali):
          ```
          mysql -N -B -r --default-character-set=utf8mb4 --user=<akun_baca_v2> -p <db_v2> \
                -e "SELECT CONCAT('INSERT INTO akun_v2 VALUES (', id_pengguna, ', ', QUOTE(username), ', ', QUOTE(nip), ');') FROM pengguna" > akun_v2.sql
          ```
       2. Di instance salinan legacy, pada skema kerja `<kerja>` (bukan produksi, bukan v2), buat tabel penampung lalu muat berkasnya dengan `sql_mode` tanpa `NO_BACKSLASH_ESCAPES`:
          ```sql
          CREATE TABLE <kerja>.akun_v2 (id_pengguna INT UNSIGNED PRIMARY KEY,
                                        username VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
                                        nip VARCHAR(30) COLLATE utf8mb4_unicode_ci NULL) DEFAULT CHARSET = utf8mb4;
          ```
          `mysql --default-character-set=utf8mb4 --user=<akun> -p <kerja> < akun_v2.sql`
       3. Jalankan kueri tabrakan di atas (termasuk kedua baris `SET`) dengan `FROM <kerja>.akun_v2 v` sebagai ganti `FROM pengguna v`. Diuji di 6.4 dan diulang di 6.5 dengan pola putaran 4: hasilnya sama dengan kueri satu instance, dan tanpa `-r` username berisi kutip/backslash menghasilkan INSERT yang rusak.
       4. Setelah keputusan dicatat di log 2.8, hapus `akun_v2.sql` dan `DROP TABLE <kerja>.akun_v2`. Isinya data akun, jadi jangan dilampirkan ke kartu.
   - Pakai ID legacy apa adanya.
   - **Setiap sesi impor diawali pengaturan per sesi** (putaran 5, 1.4 butir a), di sisi baca maupun sisi tulis. `sql_mode` dan `time_zone` GLOBAL tidak diubah:
     ```sql
     SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
     SET SESSION time_zone = '+00:00';
     SELECT @@SESSION.sql_mode, @@SESSION.time_zone, NOW() = UTC_TIMESTAMP() utc_ok;
     ```
     - Hasil kueri terakhir dicatat di log 2.8; `utc_ok` harus 1.
     - `time_zone` sesi = aturan **R4** (3.2), yang sudah wajib apa pun opsi Bagian B. Strict mode sesi menggantikan butir "koneksi strict (`STRICT_ALL_TABLES`)" sebelum putaran 5, dan menambah mode tanggal nol sehingga tanggal nol yang lolos R3 ditolak 1292.
   - Urutan: induk → anak.
   - Kode sentinel wilayah (dikenali dari kode hasil trim, 2.2 #7) dilewati, karena barisnya sudah dibuat migration `100200`.
7. **Verifikasi pasca-impor.**
   - Jumlah baris per tabel = profil C, dikurangi baris yang sengaja tidak diimpor (log 2.8).
   - Tidak ada error 1062, 1048, 1452, 3819, 1406, 1264, 1366, maupun 1292.
   - Periksa contoh baris per tabel.
8. **Counter AUTO_INCREMENT** (syarat DBV-005 6.5 #10, 2.7):
   - Jalankan `DBV-011-counter-auto-increment-v2.sql` (cara pakai di 2.7), lalu tinjau perintah yang dicetak. Bila salinan legacy di instance lain, pakai `counter_ai` bagian C audit dan tulis perintahnya manual dengan aturan 2.7.
   - Jalankan perintah `ALTER` itu secara manual.
   - Jalankan ulang skrip sampai setiap baris berisi "tidak perlu" atau INFO. Baris PERIKSA dicatat.
9. Jalankan `ANALYZE TABLE faq_article` (G-10 6.3 #12).
10. **Catat di kartu:** hasil audit awal dan akhir (semua WAJIB_0 kosong), log dedupe 2.8, keluaran skrip counter sebelum dan sesudah `ALTER`, serta pelaksana dan waktunya. Sejak putaran 5 juga: asal dump (dump final saat freeze, atau dump gladi), versi engine salinan, dan bukti pembersihan salinan (bagian 0 audit kosong).

### 2.5 Prosedur dedupe

**Urutan top-down.** Kerjakan induk sebelum anak:
- `provinsi` → `kabupaten_kota` → `kecamatan` → `kelurahan` → `kantor`
- `faq_topic` → `faq_sub_topic` → `faq_article`
- `bidang_pendidikan` → `jurusan_pendidikan`
- `tingkat_hukdis` → `jenis_hukdis`
- `jenis_libur` → `hari_libur`

Tabel mandiri bisa dikerjakan kapan saja. Setelah induk digabung, jalankan ulang audit: anak yang tadinya berada di dua induk kini satu lingkup dan bisa bertabrakan.

**Nilai ber-backslash dicocokkan ulang dengan PHP.** Sebelum grup duplikat A di tabel yang memakai stripslashes diputuskan, setiap baris PERIKSA bagian D yang `nilai_legacy`-nya memuat backslash dijalankan ulang dengan `stripslashes` PHP, dengan normalisasi 2.2. Hasilnya dibandingkan dengan kolom `kunci` bagian A. Selisih yang diketahui hanya `\0` (2.2 #3), tetapi pencocokan ini juga menjadi jaring pengaman bila instance salinan berperilaku lain. Grup yang berubah akibat pencocokan ulang dicatat di log 2.8.

**Jenis keputusan per grup duplikat.** Diputuskan bersama pemilik proses dan dicatat di log 2.8:
- **GABUNG** — baris-baris itu entitas yang sama. Pilih penyintas, alihkan rujukan, lalu tandai baris lain.
- **BEDAKAN** — entitas berbeda yang kebetulan bernama sama, mis. dua desa senama dalam satu kecamatan dengan kode BPS berbeda. Ubah nama salah satunya dengan pembeda yang disepakati. Tidak ada pengalihan rujukan.
- **PERBAIKI** — untuk temuan non-duplikat: `old_id` diberi kode baru, rentang tanggal dibetulkan, rujukan yatim dipetakan, dan sejenisnya. Untuk WAJIB_0 panjang/tipe pada kolom [I], PERBAIKI data hanya boleh setelah tipe v2 dipastikan benar dengan DDL dump; bila dugaan v2 yang keliru, skemanya yang dikoreksi (2.3, 2.4 langkah 6).

**Aturan penyintas (GABUNG), berurutan:**
1. ID hard-coded (B7; G-06 2.6, G-04 6.4) dan kode sentinel selalu menjadi penyintas.
2. Bila hanya satu ID yang tercatat di tabel pemetaan tanpa FK, ID itu penyintasnya. Tabel pemetaan yang dimaksud: `siasn_simpeg` (`LATIHAN_STRUKTURAL_ID`, `JENIS_HUKUMAN_ID`, `HARGA_ID`, `GOLONGAN_ID`, `JENIS_KP_ID`, `TK_PENDIDIKAN`).
3. Status 1, lalu 2, lalu 10.
4. Paling banyak dirujuk tabel anak.
5. ID terkecil.

Ejaan nama penyintas disepakati pemilik proses; boleh diambil dari baris lain.

**Kasus khusus:**
- **`jenis_konket.old_id` ganda atau tidak valid:** baris selain penyintas diberi kode baru > MAX yang tidak dipakai `absen_ijin.kategori`/`d_konket.kategori` (G-06 6.5 #3), yaitu lebih besar dari nilai `batas` di B5.
- **`kategori` tanpa pasangan `old_id`** (PERIKSA B5): pemilik proses memilih, per kategori, antara memetakan ke `jenis_konket` yang sudah ada (mengisi `old_id` yang kosong/tidak valid dengan kode itu) atau membuat entri `jenis_konket` baru ber-`old_id` tersebut. Jumlah baris pengajuan per kategori ikut dicatat di log 2.8.
- **`hari_libur.tgl_mulai` ganda:** gabungkan (nama/keterangan disatukan) atau betulkan tanggalnya.
- **`id_pegawai` ganda di `pengguna`:** tentukan per akun. Biasanya akun aktif terbaru tetap tertaut NIP. Akun lain tidak diimpor, atau diimpor tanpa NIP berstatus nonaktif (nama wajib, A-01 9.5).
- **`username` ganda di `pengguna`** (putaran 5; produksi tanpa UNIQUE `username`, 2.9): tentukan per akun dengan aturan yang sama seperti `id_pegawai` ganda. Akun yang tetap diimpor diberi `username` pembeda yang disepakati, lalu pemiliknya diberi tahu, karena login v2 memakai `username`.
- **Akun dengan hash bukan 32 heks** (PERIKSA A6, putaran 5): akun ini tidak bisa masuk lewat jalur A-02b. Pilihannya per akun: diimpor tanpa `password_legacy` (masuk lewat lupa password atau reset admin), atau dinonaktifkan. Merapikan spasi atau baris baru di tepi hash adalah keputusan PERBAIKI yang dicatat, bukan transformasi otomatis.
- **Akun tanpa email** (PERIKSA A6, putaran 5): tidak bisa memakai lupa password (A-01 D-6, K3), sehingga setelah window `password_legacy` berakhir hanya bisa direset admin. Jumlahnya per role dan status dicatat di log 2.8, sebagai bahan komunikasi pengguna di Cutover Plan.

**Pengalihan rujukan** (di salinan kerja):

(1) Rujukan fisik: daftar FK yang menunjuk tabel master, dibaca dari `information_schema`:
```sql
SELECT TABLE_NAME anak, COLUMN_NAME kolom, REFERENCED_COLUMN_NAME kolom_induk, CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = '<tabel_master>';
```

(2) Rujukan logis tanpa FK, per grup:
- G-06: 2.6, 6.4 #3–#4, `siasn_simpeg`.
- G-04/G-05: 2.9, 6.4, dan 7 (tabel anak Fase 3).
- G-07: `kantor` → wilayah.
- G-10: `faq_rate`, `faq_related_article`.
- A-01: 9.4 (`id_pengguna` di `fb_token`, `news`, `user_log`, dan lainnya).

(3) Hitung rujukan per kandidat, lalu alihkan:
```sql
SELECT <kolom>, COUNT(*) FROM <anak> WHERE <kolom> IN (<id_kandidat…>) GROUP BY <kolom>;  -- baca
UPDATE <anak> SET <kolom> = <id_penyintas> WHERE <kolom> IN (<id_lain…>);                -- HANYA di salinan kerja
```
Salinan nama master di tabel riwayat tidak diubah (G-06 6.5 #9).

**GABUNG `faq_article`.** Tabel anaknya ber-PK komposit: `faq_rate (id_faq_article, nip)` dan `faq_related_article (id_article_main, id_article_related)`. UPDATE generik di atas bisa gagal 1062 (dua artikel sama-sama dinilai satu NIP, atau sama-sama berelasi ke artikel yang sama) atau diam-diam menghasilkan relasi artikel ke dirinya sendiri (penyintas berelasi dengan artikel yang digabungkan ke dalamnya). Jadi sebelum UPDATE, di salinan kerja:
- rating ganda per NIP diputuskan dulu (mis. pertahankan yang terbaru menurut `created_at`, hapus sisanya) dan dicatat di log 2.8;
- pasangan relasi yang akan menjadi ganda dihapus satu, dan relasi yang akan merujuk diri sendiri dihapus;
- audit dijalankan ulang: B2 melaporkan rujukan yatim tabel anak FAQ (WAJIB_0) dan relasi yang merujuk dirinya sendiri (PERIKSA).

**Nasib baris selain penyintas** (keputusan Bagian 4 #5). Usulannya:
- **(a) Bawaan: tetap diimpor dengan status 10.** Namanya diberi akhiran ` #<id baris itu>`. Bila melebihi panjang kolom v2, potong bagian namanya; contohnya `gol_pppk`/`gol_ruang` VARCHAR(10). Hasilnya harus unik (audit ulang). Dengan cara ini ID tetap ada, sehingga rujukan tanpa FK yang terlewat tetap valid dan jejaknya tercatat. Admin v2 bisa melihatnya lewat filter status Dihapus.
  - Akhiran dipasang pada **kolom yang bertabrakan**. Untuk `jenjang_pendidikan` yang ganda di singkatan, `jenjang_pendidikan_singkat` (VARCHAR(50)) ikut diberi akhiran, bukan hanya namanya.
  - **Tidak berlaku** bila UNIQUE-nya bukan kolom nama:
    - `hari_libur` (UNIQUE `tgl_mulai`): baris selain penyintas digabung ke penyintas, tanggalnya dibetulkan (PERBAIKI), atau tidak diimpor (b);
    - `jenis_konket.old_id`: memakai kode baru (kasus khusus di atas);
    - `pengguna.username`/`nip`: diputuskan per akun (kasus khusus di atas).
- **(b) Tidak diimpor.** Hanya boleh bila rujukan fisik dan logisnya 0, dan ID-nya bukan hard-coded maupun tercatat di tabel pemetaan.

Setelah setiap putaran perbaikan, jalankan ulang skrip audit **sampai semua WAJIB_0 kosong**, baru lanjut ke impor.

### 2.6 Pemetaan syarat DBV-005 (G-06 6.5) ke runbook

| 6.5 | Isi | Dipenuhi oleh |
|---|---|---|
| #1 | Audit duplikat `utf8mb4_unicode_ci` setelah stripslashes + trim, termasuk status 2/10 | A5 (6 kueri: `diklat`, `tingkat_hukdis`, `jenis_hukdis`, `jenis_konket` nama dan `old_id`, `tanda_jasa`); 2.2 |
| #2 | stripslashes hanya untuk tabel ber-`addslashes`; `jenis_hukdis` mentah; `diklat` kondisional | 2.2 #3; A5 (`jenis_hukdis` tanpa SS); D (nama ber-backslash); langkah 5 |
| #3 | `old_id` NULL/kosong/≤ 0/ganda/bukan angka → kode baru > MAX yang tidak dipakai `absen_ijin.kategori`/`d_konket.kategori`; setiap `absen_ijin.kategori` punya pasangan `old_id` | A5 `uq_jenis_konket_old_id`; B5 (`old_id` tidak valid = WAJIB_0; `absen_ijin.kategori` dan `d_konket.kategori` tanpa pasangan = PERIKSA; `batas` kode baru = INFO); B7 (`old_id` 8/10/13); 2.5 kasus khusus |
| #4 | `jenis_hukdis.id_tingkat_hukdis` NULL/yatim | B2 |
| #5 | `jenis_diklat` 1–5, status 1/2/10, `order` | B4, C (`st_null`, `st_lain`), B6 dan C (`non_10`, urutan > 127 = WAJIB_0; status NULL dihitung tampil); normalisasi status dan `order` di langkah 5 |
| #6 | Baris hard-coded ada dan namanya benar; `MAX(id_diklat)` ≤ 127 | B7, C (`id_maks`; PK > 127 = WAJIB_0) |
| #7 | `affect_tukin` = 1, `masa_sanksi_bulan` NULL | langkah 5 (transformasi, bukan audit) |
| #8 | `updated_by` → `id_pengguna`; `updated_at` → keputusan zona waktu; status 2 "macet" | langkah 5; R1a/R1b–R4 (3.2; tipe per kolom dari E2: TIMESTAMP → R1b, DATETIME → R1a); C (`st_2`) → PERIKSA bersama admin |
| #9 | Salinan nama di riwayat disalin apa adanya | langkah 5 dan 2.5 |
| #10 | Counter AUTO_INCREMENT legacy disalin setelah impor | Kesetiaan counter salinan terhadap sumber (langkah 1); C (`counter_ai`), `DBV-011-counter-auto-increment-v2.sql`, langkah 8, 2.7 |

### 2.7 Counter AUTO_INCREMENT (6.5 #10)

**Mengapa perlu disalin:**
- Impor dengan ID eksplisit hanya menaikkan counter ke `MAX(id)+1`.
- Legacy menghapus baris master secara keras (Batch 1, `Lm_umum`; G-04/G-05; G-06; kantor). Akibatnya, ID tertinggi yang pernah dihapus akan dipakai lagi oleh entri baru v2.
- Padahal rujukan tanpa FK masih menyimpan ID lama: `siasn_simpeg`, salinan di riwayat, dan data yang belum dimigrasi.

**Usulan cakupan (Bagian 4 #6):**
- Salin counter untuk **semua 22 master ber-AUTO_INCREMENT dan `pengguna`**.
- Untuk lima tabel G-06 ini sudah wajib (syarat DBV-005). Perluasan ke tabel lain adalah usulan: memakai ulang ID lama lebih berbahaya daripada kehilangan beberapa ID yang tidak terpakai.

**Aturan skrip `DBV-011-counter-auto-increment-v2.sql`:**
- Skrip membaca counter **salinan**, jadi hasilnya hanya benar bila counter salinan sama dengan sumber produksi. Kesetiaan itu dicek di 2.4 langkah 1 sebelum skrip dipakai. Diuji di 6.5: dengan salinan data-saja, skrip tetap berjalan tanpa error tetapi mencetak `diklat AUTO_INCREMENT = 9` (seharusnya 17), dan PERIKSA counter ≥ 128 `bidang_kursem`/`jenis_kp` berubah menjadi ALTER ke 3/9.
- Perintah ALTER hanya dicetak bila counter legacy lebih besar dari counter v2. Counter tidak pernah diturunkan.
- Untuk PK TINYINT, **counter ≥ 128** tidak disalin dan ditandai PERIKSA (putaran 1 memakai `> 128`, sehingga counter tepat 128 lolos). Diuji di MySQL 8.0.30 (6.3 butir 3):
  - `ALTER TABLE … AUTO_INCREMENT = 128` (juga 200): setiap INSERT berikutnya gagal **1467** "Failed to read auto-increment value from storage engine". Error ini tidak diterjemahkan engine master, sehingga menjadi 500.
  - Tanpa ALTER: `AUTO_INCREMENT = 127` masih memberi id 127. Setelah id 127 terisi, counter terbaca 127 dan INSERT berikutnya gagal **1062**, yang diterjemahkan aplikasi menjadi 422 "Kode … sudah mencapai batas" (G-04 6.3 #16).
  - Jadi **di MySQL 8** menyalin counter 128 justru mengubah 422 yang ramah menjadi 500.
  - Di MariaDB 10.4, TINYINT yang habis memberi error 167 (G-04 Bagian 8 #10), yang diterjemahkan CR-011 menjadi 422. Perilaku `AUTO_INCREMENT = 128` di MariaDB belum diuji (butir checklist Bagian 5). Keputusan untuk tidak menyalin counter ≥ 128 tetap aman di kedua engine.
- Bila `@legacy` kosong atau skemanya tidak ada di instance, skrip hanya mencetak `BERHENTI: …` dan kueri utamanya menghasilkan Empty set, sehingga tidak ada baris yang bisa disalahbaca sebagai hasil.
- Penyetelan `information_schema_stats_expiry` (MySQL 8 meng-cache counter di `information_schema` selama 86400 detik) bersyarat engine: `SET SESSION` di MySQL 8, `DO 0` di MariaDB. Versi putaran 2 memakai `SET SESSION` langsung; di MariaDB itu error 1193, dan karena cara pakai `-e "…; SOURCE …"` berhenti pada error pertama, keluarannya hanya satu baris ERROR (temuan pra-review internal putaran 2).
- Tabel yang terkena PERIKSA butuh keputusan pelebaran tipe (G-06 Bagian 3 #8, G-04 6.3 #16).
- Dari profil produksi yang diketahui: `bidang_pendidikan` counter 100 → sisa 28 ID (100..127); `diklat` 17; `gol_pppk` 19 (`simpeg_prod.sql`).

**Cara pakai:**
- Butuh salinan legacy di instance yang sama; nama skemanya diisi lewat `@legacy` dengan `--init-command`, dan berkasnya dikirim lewat stdin. Tidak ada baris baru di dalam argumen, jadi perintahnya bisa ditulis satu baris di shell Linux maupun cmd Windows; opsi ini ada di klien MySQL 8 dan MariaDB:
  ```
  mysql --table --default-character-set=utf8mb4 --init-command="SET @legacy = '<skema_salinan_legacy>'" \
        --user=<akun> -p <db_v2> < backend/docs/db-review/sql/DBV-011-counter-auto-increment-v2.sql
  ```
- Skrip tidak punya error yang diharapkan di kedua engine, jadi cara lama (`-e "SET @legacy = …;` lalu `SOURCE …` di baris sendiri) juga berjalan sampai akhir. Error apa pun berarti hasil belum lengkap.
- Bila salinan legacy tidak satu instance: pakai kolom `counter_ai` dari bagian C audit, lalu tulis perintahnya manual dengan aturan yang sama.

### 2.8 Log keputusan (dilampirkan ke kartu ISSUE-009 / DBV-011)

| Tabel | Cek / lingkup / kunci | Anggota (`id:status`) | Keputusan (GABUNG / BEDAKAN / PERBAIKI) | Penyintas / nilai baru | Rujukan dialihkan (`anak.kolom`: jumlah) | Nasib baris lain (a/b) | Pemilik proses | Tanggal |
|---|---|---|---|---|---|---|---|---|
| … | … | … | … | … | … | … | … | … |

### 2.9 Selisih dump struktur prod 01-10 terhadap asumsi dokumen (putaran 5)

Dump struktur prod 01-10 berisi struktur penuh `simpeg01` tanpa data (285 tabel, 313 FK, 101 trigger, 1 function). Tabel di bawah membandingkannya dengan asumsi dokumen dan skrip sampai putaran 4, khusus untuk 28 tabel master lingkup dokumen ini, `pengguna`, dan tabel pendukung yang dibaca skrip. Semua isi kolom "Dump" berlabel [K].

Koreksi skema v2 lewat **migration ALTER baru + review DBV** (migration di `main` tidak diedit). Koreksi teks dokumen DBV yang sudah disetujui lewat **DBV-017**, bukan PR ini.

| # | Objek | Asumsi sampai putaran 4 | Dump struktur prod 01-10 | Akibat dan tindak lanjut |
|---|---|---|---|---|
| 1 | `pengguna.username` | VARCHAR(100) **UNIQUE** [L] (`simpeg_prod_duplikat`; dirujuk A-01 9.1 dan D-5 sebagai [K], dan dipakai uji 6.2) | VARCHAR(255) NOT NULL, **tanpa UNIQUE** | Duplikat `username` memang bisa ada di produksi, jadi kueri duplikat A6 kini relevan (uji 6.6 dengan duplikat tertanam). Kasusnya diputuskan di 2.5. `username` > 100 → WAJIB_0 A6 |
| 2 | `pengguna.name`, `email` | VARCHAR(150) NULL [L] | `name` VARCHAR(255) **NOT NULL**; `email` VARCHAR(255) NULL | Nilai > 150 → WAJIB_0 A6. Lebar v2 diputuskan DBV setelah audit data: dipertahankan bila data muat, atau dilebarkan lewat migration ALTER baru + DBV. Koreksi teks A-01 (rujukan [K] `simpeg_prod_duplikat` di 9.1, D-5 "ikut legacy [K]") lewat DBV-017 |
| 3 | `pengguna.status` | Komentar skrip C: "status legacy 0/1/2" | INT NULL DEFAULT 1; komentar kolom "1 aktif, 2 tidak aktif, 3 dihapus" | Nilai nyata dibaca dari profil C (`st_1`, `st_2`, `st_lain`, `st_null`). Pemetaannya ke v2 di DBV-009 dan runbook `pengguna` (DBV-014). Komentar skrip disesuaikan |
| 4 | `pengguna.expired_at` | DATETIME NULL [L] | DATETIME **NOT NULL**, diisi trigger legacy saat akun dibuat dan saat password berubah | Bahan keputusan paksa ganti password dari `expired_at` (A-01 9.10), yang wajib diputuskan sebelum impor `pengguna` |
| 5 | `pengguna.id_pegawai` | VARCHAR(30) NULL, tanpa FK | VARCHAR(30) NULL + **FK fisik** ke `pegawai.nip` (ON DELETE SET NULL), tanpa UNIQUE | NIP yatim kecil kemungkinannya. Akun yang pegawainya dihapus menjadi NULL, sehingga PERIKSA "role 2/6/7 tanpa `id_pegawai`" relevan. Duplikat tetap mungkin (A6) |
| 6 | `pengguna.id` | INT signed (A-01 D-12) | INT signed | Cocok. D-12/DBV-009 tetap prasyarat impor (2.4 langkah 6) |
| 7 | `password_decode` | Hanya di `pengguna` (A-01 9.1) | Ada di `pengguna` dan beberapa tabel arsip/cadangan akun | Dibuang dari semua tabel salinan berdasarkan `information_schema` (2.4 langkah 1); bagian 0 skrip memeriksanya |
| 8 | Kolom `pengguna` tanpa padanan di A-01 | — | `is_admin`, `id_kode_unit_instansi` | Nasibnya diputuskan di runbook `pengguna` (DBV-014). Tidak memengaruhi audit |
| 9 | Trigger | Tidak dibahas | 101 trigger. Yang ada di tabel lingkup dokumen ini:<br>• `pangkat`: menulis ke skema aplikasi lain di instance yang sama;<br>• `pengguna`: menulis tabel antrean sinkron di skema yang sama dan mengisi `expired_at` | Dibuang dari salinan (2.4 langkah 1); bagian 0 skrip memeriksanya |
| 10 | `jenis_pegawai.jenis_pegawai`, `jenis_status.jenis_status` | v2 VARCHAR(50) [I] (G-01 8.3 #1; katalog D) | VARCHAR(255) dan VARCHAR(100) | v2 lebih sempit dari legacy. Data > 50 → WAJIB_0 D, diselesaikan dengan migration ALTER baru + DBV (aturan 2.3), bukan dengan memotong data. Katalog D tetap memakai lebar v2 di `main` |
| 11 | Collation | Hanya `agama` yang berbeda (`utf8mb4_0900_ai_ci` NO PAD di tabel latin1) | Juga `jenis_status` dan `jenis_kp` (tabel `utf8mb4_0900_ai_ci`), serta `pangkat` (`utf8` mb3, `utf8_general_ci`) | Pembanding audit tidak berubah (CONVERT + COLLATE, 2.2 #1). Collation dikonversi saat impor |
| 12 | `jenis_konket.old_id` | VARCHAR di rekonstruksi lokal [L] (DB uji 6.1) | INT NULL, tanpa UNIQUE | Di produksi hanya NULL dan ≤ 0 yang mungkin. B5 tetap utuh |
| 13 | `jenis_status.status_pegawai`, `pangkat.cpns` | Tipe [I] (B8) | TINYINT(1) NOT NULL | Hanya cabang "di luar 1/2" B8 yang mungkin muncul. B8 tetap utuh |
| 14 | Kode wilayah (PK, kolom induk, `kantor`) | v2 CHAR(2/4/7/10) [I] (G-01 8.3, G-07 3.1, ISSUE-008) | CHAR(2/4/7/10) `utf8mb4_unicode_ci`; kolom induk dan kode wilayah `kantor` NULL dengan FK SET NULL | Tipe cocok, jadi "lebih dari CHAR(N)" di B9 tidak mungkin muncul. Induk NULL dilaporkan B2 |
| 15 | Kolom `status` master | Status NULL dicek (`st_null`) | Semua kolom `status` NOT NULL: TINYINT, kecuali `faq_topic` INT dan `gol_pppk` ENUM('1','2'). `hari_libur`, `faq_rate`, dan `faq_related_article` tanpa kolom status | `st_null` diperkirakan 0. Ceknya tetap ada |
| 16 | Counter AUTO_INCREMENT | — | Saat dump diambil, tidak ada tabel ber-PK TINYINT yang counter-nya ≥ 128; tertinggi `bidang_pendidikan` 100 | Tidak ada PERIKSA counter yang diperkirakan. Tetap dicek ulang pada dump final (2.4 langkah 1) |
| 17 | Kolom TIMESTAMP | 3.1: 5 kolom, dari dump parsial | 13 kolom di 8 tabel; tidak satu pun di 28 master + `pengguna` | R1b tidak berlaku untuk tabel lingkup dokumen ini. Daftar lengkapnya dari E2 |
| 18 | Nama tabel/kolom yang dirujuk skrip | Sebagian [I] | Semua ada | Skrip audit pada struktur dump: 0 error, `INFO D: ke-27 …`, `INFO E: ke-29 …` (6.6) |

## 3. Bagian B — Zona waktu & `sql_mode` sesi (ISSUE-022, D-11) — USULAN; keputusan final TL/DBA; DITAHAN menunggu ISSUE-024

**Status (01-10-2026): DITAHAN.** Bagian ini menunggu jawaban kartu ISSUE-024 di board tim, yaitu versi engine, `sql_mode`, `time_zone`, dan `table_rows` server DB produksi. Putaran 5 **tidak** memutuskan Bagian B: opsi 3.5 belum dipilih, dan offset R1a belum dipastikan.

Yang berubah di putaran 5 hanya isi permintaan (1.4 butir a):
- `sql_mode` dan `time_zone` GLOBAL tidak diminta diubah;
- strict mode dan `'+00:00'` dipasang per sesi koneksi (3.4, 3.5, 3.6).

Aturan R1–R6 (3.2) tetap wajib untuk impor dan SQL manual, karena penyimpanan UTC sudah final (1.2).

### 3.1 Kondisi sekarang

| Aspek | Temuan | Label |
|---|---|---|
| Aplikasi | `appTimezone = 'UTC'` (`app/Config/App.php`). Stempel ditulis eksplisit dari jam PHP (`AuthService`, `JwtService`, `ResetPasswordService`, `PasswordService`, `FaqService`, `MasterModel`). `MasterModel::shiftOrder` memakai `updated_at = updated_at` agar ON UPDATE tidak terpicu. Di `app/` tidak ada `NOW()`/`CURDATE()`/`CURRENT_TIMESTAMP` selain di definisi migration; `SeedWilayahLainLain` memakai `UTC_TIMESTAMP()` | [K] |
| Skema v2 | Semua kolom waktu bertipe **DATETIME**; **0 TIMESTAMP** (diukur di `information_schema` DB scratch). DATE hanya ada di `hari_libur.tgl_mulai`/`tgl_akhir`. Antrean memakai INT epoch. `DEFAULT CURRENT_TIMESTAMP`/`ON UPDATE CURRENT_TIMESTAMP` ada di tabel Batch 1, FAQ, DBV-003/004/005 | [K] |
| Legacy | `date_default_timezone_set("Asia/Jakarta")` (`application/config/config.php:4`). Ada 209 kemunculan `NOW()`/`CURDATE()`/`CURRENT_TIMESTAMP` di 18 berkas PHP `application/` (grep peka huruf besar/kecil `NOW\(\)\|CURDATE\(\)\|CURRENT_TIMESTAMP`, 30-09-2026; grep tidak peka huruf memberi 211 di 19 berkas karena ikut menghitung fungsi PHP `getWibNow()` di `libraries/hr/Lsl_gaji.php:80, 85`). Artinya kolom **DATETIME** legacy berisi jam dinding WIB, dan legacy bergantung pada jam sesi DB | [K] |
| Legacy: tipe kolom waktu | Dump produksi punya kolom **TIMESTAMP**: `firebase_message_logs.created_at`/`updated_at`, `firebase_token.created_at`/`updated_at`, dan `forgot_attempts.attempt_time` (`simpeg_prod.sql:1147-1171`). Dari 12 tabel lingkup DBV-011 yang DDL-nya ada di dump (`agama`, lima tabel FAQ, `hari_libur`, `bidang_kursem`, `instansi_kursem`, `gol_pppk`, `bidang_pendidikan`, `diklat`), tidak ada yang ber-TIMESTAMP. Tipe kolom tabel [I] lainnya dibaca dari E2 skrip audit. TIMESTAMP disimpan UTC dan ditampilkan mengikuti zona sesi. `mysqldump` bawaan (`--tz-utc`) mempertahankan instannya. Putaran 5: dump struktur prod 01-10 memuat 13 kolom TIMESTAMP di 8 tabel, tidak satu pun di 28 master + `pengguna` (2.9 #17) | [K] / [I] |
| Catatan DBV (G-06 Status) | Default DB terukur 11:36:43 WIB, sedangkan aplikasi menulis 04:36:43 UTC di kolom yang sama | [K] |
| MySQL 8.0.30 lokal (diukur 29-09-2026, hanya sesi / DB scratch) | `@@GLOBAL.time_zone = SYSTEM` (SE Asia Standard Time); `mysql.time_zone_name` 0 baris; `SET time_zone = 'UTC'` → **1298**; `CONVERT_TZ(…, 'Asia/Jakarta', 'UTC')` → NULL; `CONVERT_TZ('2026-09-29 11:36:43', '+07:00', '+00:00')` → `04:36:43`; `SET time_zone = '+00:00'` → `NOW() = UTC_TIMESTAMP()`. `DEFAULT CURRENT_TIMESTAMP`: sesi SYSTEM menulis 23:59:26, sesi `+00:00` menulis 16:59:26 (selisih 420 menit); `ON UPDATE` juga UTC. Kolom **DATETIME** yang sudah tersimpan tidak berubah saat `time_zone` sesi diganti. Kolom **TIMESTAMP** ditulis 11:36:43 di sesi WIB, dibaca 04:36:43 di sesi `+00:00`. `CONVERT_TZ('0000-00-00 00:00:00', …)` → NULL | [K] |
| CI4 4.7.4 | Tidak ada opsi `time_zone` untuk koneksi. `strictOn` dipasang lewat `MYSQLI_INIT_COMMAND` (`system/Database/MySQLi/Connection.php`). Driver kustom FQCN didukung (pola `tests/_support/Database/CommitFailing`). `.env.example` menulis `DBDriver = MySQLi`, sehingga `.env` server bisa menimpa driver kustom | [K] |
| QA | Skrip QA yang memakai `NOW()` MySQL membuat token berlaku 7 jam lebih lama (QAFUNC-002). Baris seed yang memakai `DEFAULT CURRENT_TIMESTAMP` tercatat +07 (QAFUNC-002-R2 TS-INFO) | [K] |

### 3.2 Aturan impor & SQL manual — WAJIB, apa pun opsi yang dipilih, selama penyimpanan = UTC

- **R1 — Stempel waktu legacy menjadi UTC, dibedakan per tipe kolom SUMBER.** Tipe tiap kolom dibaca dari DDL dump (3.1) dan E2 skrip audit.
  - **R1a — DATETIME = jam dinding WIB → dikonversi.** Contoh: `created_at`, `updated_at`, `deleted_at`, `expired_at`, `faq_rate.created_at`, dan kolom stempel DATETIME lain. Nilainya tidak ikut zona sesi, jadi hasilnya sama di sesi mana pun.
    - Rumus: `CONVERT_TZ(kolom, '+07:00', '+00:00')`, atau setara `kolom - INTERVAL 7 HOUR`.
    - Pakai **offset angka**. Nama zona butuh tabel zona, yang kosong di server lokal (1298/NULL).
    - Syarat: DBA mengonfirmasi jam server produksi legacy (3.6 #3). Bila server legacy ternyata UTC, kolom yang diisi DEFAULT DB/`NOW()` tidak dikonversi, sedangkan kolom yang diisi PHP `date()` tetap dikonversi. Keputusan dibuat per kolom.
  - **R1b — TIMESTAMP = sudah UTC di penyimpanan → dibaca di sesi `+00:00` (R4) dan TIDAK dikonversi lagi.**
    - TIMESTAMP dibaca mengikuti zona sesi. Di sesi `+00:00` nilai yang terbaca sudah UTC; mengonversinya lagi dengan R1a menggeser −7 jam tanpa error. Contoh terukur (3.1): ditulis 11:36:43 di sesi WIB, dibaca 04:36:43 di sesi `+00:00`. Bila dikonversi lagi, nilainya menjadi 21:36:43 hari sebelumnya.
    - Hanya bila terpaksa dibaca di sesi WIB (`+07:00`/SYSTEM), nilai yang terbaca adalah WIB dan barulah dikonversi seperti R1a. Zona sesi baca wajib dicatat di log 2.8.
    - Syarat salinan: dump dibuat dengan `--tz-utc` bawaan (2.4 langkah 1).
    - Kolom TIMESTAMP legacy yang diketahui ada di 3.1. Tidak satu pun dari 12 tabel [K] lingkup DBV-011 ber-TIMESTAMP; tabel [I] dicek lewat E2.
- **R2 — Kolom DATE tidak dikonversi.** Contohnya `hari_libur.tgl_mulai`/`tgl_akhir`, serta nanti `tmt`, `tgl_lahir`, dan tanggal presensi. Nilainya tanggal kalender tanpa zona.
- **R3 — Tanggal nol dibereskan dulu** (bagian E audit).
  - `CONVERT_TZ` dan `- INTERVAL` menghasilkan NULL untuk tanggal nol, sehingga kolom NOT NULL gagal.
  - Kolom nullable → NULL. Kolom NOT NULL → nilai pengganti yang diputuskan, mis. stempel lain di baris yang sama. Keputusan dicatat di log 2.8.
- **R4 — Setiap sesi impor atau SQL manual diawali `SET time_zone = '+00:00';`, di KEDUA sisi.** Berlaku untuk mysql CLI, HeidiSQL, skrip ekspor/transformasi/impor, dan skrip QA.
  - **Sisi baca** (salinan legacy dan salinan kerja, termasuk skrip ekspor/transformasi): TIMESTAMP terbaca UTC, sehingga R1b berlaku. DATETIME tidak terpengaruh.
  - **Sisi tulis** (DB v2): `DEFAULT`/`ON UPDATE CURRENT_TIMESTAMP` dari SQL manual ikut UTC. v2 tidak punya kolom TIMESTAMP (R6), jadi nilai DATETIME yang ditulis eksplisit tidak bergeser.
  - Bila baca dan tulis berjalan dalam satu sesi (mis. `INSERT … SELECT` antar-skema di instance yang sama), satu `SET` berlaku untuk keduanya. Bila lewat berkas (`mysqldump` salinan kerja → `mysql` ke v2), kedua sesi di-`SET` sendiri-sendiri. Dump bawaan (`--tz-utc`) memasang `+00:00` di kepala berkasnya.
  - Verifikasi di setiap sesi: `SELECT @@session.time_zone, NOW(), UTC_TIMESTAMP();`. `NOW()` harus sama dengan `UTC_TIMESTAMP()`.
- **R5 — Tanggal bisnis tidak memakai `CURDATE()`/`NOW()` di SQL manual.** Tulis tanggal sebagai literal.
  - "Hari ini" versi bisnis (Fase 5: presensi, cuti) dihitung di PHP dengan `Asia/Jakarta` atau `offset_zona_menit` satker.
  - Jangan pakai `CURDATE()` atau `date()` dengan zona UTC: pukul 00:00–06:59 WIB akan terbaca sebagai hari kemarin.
- **R6 — Tidak menambah kolom TIMESTAMP.** Nilainya bergeser mengikuti `time_zone` sesi (terukur di 3.1). Stempel baru tetap DATETIME yang diisi aplikasi (UTC).

### 3.3 Usulan: `SET time_zone = '+00:00'` per koneksi aplikasi

Setiap koneksi yang dibuka aplikasi — web, `spark migrate`/`db:seed`, worker antrean, dan test — menjalankan `SET time_zone = '+00:00'` tepat setelah connect, termasuk saat reconnect.

| Aspek | Dampak |
|---|---|
| `DEFAULT` / `ON UPDATE CURRENT_TIMESTAMP` | Ditulis dalam UTC oleh koneksi aplikasi, sama dengan nilai eksplisit aplikasi. Artefak seed +07 hilang, dan ada jaring pengaman bila kelak ada jalur yang lupa mengisi stempel. Manfaat langsungnya kecil karena aplikasi sudah menulis eksplisit |
| Penulisan di luar aplikasi | **Tidak berubah**: HeidiSQL, skrip impor, dan perbaikan manual DBA tetap memakai `time_zone` global. Kasus yang dicatat DBV di G-06 hanya tertutup oleh R4 |
| Data tersimpan | Semua kolom DATETIME, jadi nilai lama tidak bergeser dan tidak perlu migrasi data Dev. Kolom TIMESTAMP (saat ini tidak ada) akan bergeser, karena itu ada R6. Antrean INT epoch tidak terpengaruh |
| Kolom DATE / "hari ini" | `CURDATE()`/`NOW()` di sesi UTC memberi tanggal UTC, sama dengan `date()` PHP saat ini. Aplikasi tidak memakai `CURDATE()`, jadi tidak ada perubahan perilaku; aturannya R5 |
| MariaDB 10.4 vs MySQL 8 | Offset angka `'+00:00'` berjalan di keduanya tanpa tabel zona. Nama zona (`'UTC'`, `'Asia/Jakarta'`) butuh tabel zona (1298 bila kosong) |
| Instance dipakai bersama legacy | Hanya sesi v2 yang berubah, jadi aplikasi legacy tidak terpengaruh. Bandingkan dengan opsi C di 3.5 |
| `GET_LOCK` (CR-020), transaksi, `strictOn` | Tidak terpengaruh. `strictOn` tetap lewat init command |
| Implementasi (unit DBV+CR terpisah setelah keputusan) | Driver kustom FQCN, mis. `App\Database\MySQLiSesi\Connection`, subclass MySQLi dengan `connect()` = parent + `SET time_zone` (+ `sql_mode` bila 3.4 dipilih). Perlu kelas pembungkus `Builder`/`Forge`/`Result`/`Utils`/`PreparedQuery`, `DBDriver` di `Config\Database` dan `.env.example`, penjagaan di `DatabaseConfigTest`, dan larangan menimpa `DBDriver` di runbook A-01 9.8 langkah 4. Test yang diperlukan: `@@session.time_zone = '+00:00'`; INSERT tanpa `created_at` ≈ `UTC_TIMESTAMP()`; `ON UPDATE` dalam UTC; tetap berlaku setelah reconnect. File yang disentuh berada di wilayah DBV-010 dan tidak bersinggungan dengan PR #11 |

### 3.4 `sql_mode` sesi (D-11b; opsional sampai putaran 4, permintaan utama sejak putaran 5)

**Isi.** Dalam init yang sama, tambahkan `NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO` ke `@@sql_mode` sesi. `strictOn` sudah menambah `STRICT_ALL_TABLES`.

**Dampak:**
- Aplikasi tidak lagi bergantung pada `sql_mode` global dari DBA. Hasil ukur DBV di MariaDB 10.4.27: nilai global tanpa STRICT dan tanpa `ERROR_FOR_DIVISION_BY_ZERO`.
- Tanggal nol ditolak 1292 di koneksi aplikasi. Validasi aplikasi sudah menolaknya lebih dulu (CR-007, `HariLiburController`).
- `ALTER` lewat migration atas tabel yang berisi tanggal nol akan gagal. Ini selaras dengan pengaman DBV-010 `130000`.
- MySQL 8.0.30 tidak memberi peringatan bila mode ini dipasang bersama strict (diuji). Mode ini berstatus *deprecated* di MySQL 8 tetapi tetap berfungsi.

**Posisi (direvisi putaran 5, 1.4 butir a):** **permintaan utama**, menggantikan permintaan mengubah `sql_mode` GLOBAL di A-01 9.8 langkah 2 (D-11 versi DBV-010: "minta DBA").
- Alasannya sama dengan kekurangan opsi C di 3.5: perubahan global ikut mengubah aplikasi lain di instance yang sama.
- Untuk sesi impor dan SQL manual, mode ini sudah dipasang lewat runbook 2.4 langkah 6.
- Untuk koneksi aplikasi, implementasinya tetap menunggu keputusan Bagian B (ISSUE-024, TL/DBA).
- Teks A-01 dikoreksi lewat DBV-017, bukan PR ini.

Sampai putaran 4, posisinya fallback: hanya dipakai bila jawaban DBA lambat, atau bila server Dev/Prod ternyata MariaDB tanpa mode tersebut.

### 3.5 Opsi & rekomendasi

| Opsi | Isi | Kelebihan | Kekurangan |
|---|---|---|---|
| **A (rekomendasi)** | UTC + R1–R6 + `SET time_zone = '+00:00'` per koneksi aplikasi (3.3) + `sql_mode` sesi (3.4). Sejak putaran 5, `sql_mode` dan `time_zone` GLOBAL tidak diubah (1.4 butir a). Sampai putaran 4: D-11b opsional, dan `time_zone` global `'+00:00'` hanya bila DBA memastikan instance v2 tidak dipakai bersama legacy | Menutup impor/SQL manual (R4) dan default DB dari aplikasi (seed/migrate). Tanpa risiko data; tidak menyentuh legacy | Butuh satu unit kode kecil (driver kustom + test) lewat DBV+CR |
| B | UTC + R1–R6 saja, tanpa kode | Tanpa perubahan kode | Default DB dari koneksi aplikasi (seed, migrate, jalur yang lupa mengisi stempel) tetap WIB tanpa terdeteksi |
| C | Hanya `time_zone` global `'+00:00'` di server (DBA) | Menutup juga SQL manual yang lupa R4 | Bila instance juga dipakai SIMPEG legacy, 209 pemakaian `NOW()`/`CURDATE()`/`CURRENT_TIMESTAMP` legacy (3.1) bergeser ke UTC sehingga data dan logika legacy rusak. R1–R3 tetap perlu. Sejak putaran 5 bertentangan dengan permintaan yang direvisi (perubahan global ditarik, 1.4 butir a); tetap dicantumkan sebagai pembanding untuk keputusan TL/DBA |
| D | Beralih ke WIB (`appTimezone = 'Asia/Jakarta'` + sesi `+07:00`) | Impor tanpa konversi | Membalik keputusan UTC di DBV-001..005/G-10 dan belasan test `Time::setTestNow('UTC')`. Kontrak API dan FE (mis. `FaqView` yang menambah `Z`) ikut berubah |

**Rekomendasi: A.** Keputusan final ada di TL/DBA, setelah kartu ISSUE-024 terjawab.

### 3.6 Pertanyaan ke DBA/TL (paket D-11)

Pertanyaan 1–4 sebagian sudah diminta lewat kartu ISSUE-024 di board tim (parameter server DB produksi). Jawabannya dicatat di sana dan dirujuk di sini.

1. Engine dan versi server Dev/Prod v2? README-deploy menyebut MySQL 8.x, sedangkan validasi DBV dilakukan di MariaDB 10.4. Putaran 5: keputusan ini mengikuti AS-02; header dump struktur prod 01-10 menulis MySQL 8.0.21.
2. Apakah `@@GLOBAL.sql_mode` Dev/Prod memuat `STRICT_TRANS_TABLES`, `NO_ZERO_IN_DATE`, `NO_ZERO_DATE`, dan `ERROR_FOR_DIVISION_BY_ZERO` (runbook A-01 9.8 langkah 1–2)? Sejak putaran 5 nilai ini **hanya dicatat**; mengubahnya tidak diminta lagi (1.4 butir a).
3. Jam server **produksi legacy**: jalankan `SELECT @@GLOBAL.time_zone, @@system_time_zone, NOW(), UTC_TIMESTAMP();` (hanya baca). Jawaban ini memastikan offset +07 di R1.
4. Berapa `@@GLOBAL.time_zone` server v2 Dev/Prod? Apakah instance (mysqld) v2 dipakai bersama SIMPEG legacy atau aplikasi lain? Jawaban ini menentukan boleh tidaknya opsi C, dan sejak putaran 5 juga menjadi masukan AS-02.
5. Keputusan: opsi A/B/C/D. Sejak putaran 5, opsi C bertentangan dengan permintaan yang direvisi (1.4 butir a), sehingga hanya relevan bila TL/DBA menolak revisi itu. Bila A, `sql_mode` sesi (3.4) ikut dipasang sesuai revisi putaran 5.

## 4. Keputusan yang diminta dari DB Validator (DBV-011)

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| 1 | Setujui `DBV-011-audit-duplikat-master-legacy.sql` dan runbook 2.4 sebagai langkah wajib impor master legacy. Ini memenuhi syarat DBV-005 (2.6), G-01 8.4 #2, G-04/G-07/G-10 6.3, dan A-01 9.8 langkah 6 | Setujui. Skrip dijalankan pemegang akses di salinan data, dan hasilnya dilampirkan ke kartu | ⏳ |
| 2 | Aturan pembanding 2.2: normalisasi = aplikasi (stripslashes per tabel, whitespace dirapatkan, trim), `utf8mb4_unicode_ci`, semua status, dan lingkup = kolom depan UNIQUE. Kode wilayah = hasil `trim()` PHP di semua kueri (lingkup, PK, rujukan, sentinel) dan di impor (2.2 #7). Impor wajib memakai normalisasi yang sama | Setujui | ⏳ |
| 3 | Klasifikasi WAJIB_0/PERIKSA/INFO (2.3); impor hanya boleh bila semua WAJIB_0 kosong **dan** hasil audit tidak memuat error maupun entri D/E terlewat di luar pengecualian 2.3 (hanya 1146 untuk tabel yang memang tidak ada). WAJIB_0 panjang/tipe pada kolom [I] diselesaikan dengan mencocokkan skema ke DDL dump lebih dulu (2.3) | Setujui | ⏳ |
| 4 | Prosedur dedupe 2.5: top-down, GABUNG/BEDAKAN/PERBAIKI, aturan penyintas, pengalihan rujukan hanya di salinan kerja, diulang sampai 0 | Setujui | ⏳ |
| 5 | Nasib baris selain penyintas: (a) diimpor status 10 dengan akhiran ` #<id>`, atau (b) tidak diimpor | (a) sebagai bawaan; (b) hanya bila tanpa rujukan fisik/logis dan bukan ID hard-coded/pemetaan | ⏳ |
| 6 | Counter AUTO_INCREMENT (2.7): disalin untuk semua master AUTO_INCREMENT dan `pengguna` (G-06 wajib, sisanya usulan); tidak pernah diturunkan; counter ≥ 128 di tabel ber-PK TINYINT = PERIKSA (tidak disalin); counter salinan dicocokkan dulu dengan sumber produksi (2.4 langkah 1) | Setujui | ⏳ |
| 7 | Email ganda legacy (D-6) = PERIKSA. Tidak memblokir impor karena tanpa UNIQUE di DB; diputuskan per akun sebelum driver SMTP (CR-014) aktif | Setujui | ⏳ |
| 8 | Aturan R1–R6 (3.2) wajib di runbook impor dan SQL manual, apa pun opsi zona waktunya. R1 dibedakan per tipe kolom sumber: DATETIME dikonversi +07 → +00 (R1a); TIMESTAMP dibaca di sesi `+00:00` dan tidak dikonversi lagi (R1b). Zona sesi `+00:00` dipasang di sisi baca dan sisi tulis (R4) | Setujui | ⏳ |
| 9 | Teruskan usulan opsi A (3.3) + `sql_mode` sesi (3.4; sejak putaran 5 bagian dari permintaan, bukan opsional) beserta pertanyaan 3.6 ke TL/DBA. Implementasi lewat unit DBV+CR terpisah setelah keputusan | Setujui untuk diteruskan; keputusan final di TL/DBA. **Ditahan** sampai kartu ISSUE-024 terjawab | ⏳ |
| 10 | Prasyarat impor (2.4 langkah 6): tabel target v2 kosong, kecuali 4 sentinel wilayah dan akun yang disepakati. Nasib akun v2 yang sudah ada diputuskan per akun dengan kueri tabrakan 2.4 langkah 6 | **Dev:** data QA master dikosongkan (backup dulu); akun QA yang bertabrakan (ID, `username`, atau NIP) dihapus lalu dibuat ulang setelah impor dengan `username`/NIP yang tidak bertabrakan. Rujukan `audit_logs.id_pengguna_actor` dan `token` ikut diperhitungkan. **Prod:** impor dijalankan sebelum akun admin awal dibuat. Bila akun admin sudah terlanjur dibuat, `id_pengguna`-nya harus > counter legacy dan lolos kueri tabrakan. Bila salinan legacy tidak satu instance dengan v2, kueri tabrakan dijalankan lewat fallback 2.4 langkah 6 (identitas akun v2 disalin ke skema kerja di instance salinan) | ⏳ |
| 11 | Prasyarat impor lain (2.4 langkah 6): D-12 tuntas (penyelarasan tipe `pengguna.id_pengguna` di DBV-009, A-01 9.7 "wajib tuntas sebelum … data legacy diimpor") dan semua nilai [I] tabel yang diimpor sudah dicocokkan dengan DDL dump lalu dikoreksi lewat migration ALTER + DBV | Setujui sebagai gerbang impor; hasil pencocokan dicatat di kartu ISSUE-003/ISSUE-015 dan dirujuk di log 2.8 | ⏳ |
| 12 | **Putaran 5.** Revisi permintaan A-01 9.8 langkah 2 (1.4 butir a): `sql_mode`/`time_zone` GLOBAL tidak diubah; strict mode dan `'+00:00'` dipasang per sesi koneksi. Sesi impor dan SQL manual: sekarang (2.4 langkah 6). Koneksi aplikasi: setelah keputusan Bagian B. Teks A-01 dikoreksi lewat DBV-017 | Setujui | ⏳ |
| 13 | **Putaran 5.** Engine salinan (2.4 langkah 1): MySQL 8, versi mengikuti AS-02; di versi selain 8.0.30, uji deteksi data sintetis dijalankan dulu | Setujui. Bila memungkinkan, verifikasi DB Validator juga dijalankan di MySQL 8.0.x target | ⏳ |
| 14 | **Putaran 5.** Pembersihan salinan (2.4 langkah 1): trigger dan kolom `password_decode` dibuang segera setelah restore, sebelum audit; bagian 0 skrip = WAJIB_0 | Setujui | ⏳ |
| 15 | **Putaran 5.** Audit format hash dan akun tanpa email (A6): tepat 32 heks → `password_legacy`; format lain dan akun tanpa email = PERIKSA, diputuskan per akun (2.5) | Setujui | ⏳ |
| 16 | **Putaran 5.** Selisih dump struktur prod 01-10 (2.9): lebar `pengguna.username`/`name`/`email` dan `jenis_pegawai`/`jenis_status` diputuskan setelah audit data (dipertahankan bila data muat, atau migration ALTER baru + DBV); koreksi teks dokumen yang sudah disetujui lewat DBV-017 | Setujui sebagai tindak lanjut; tidak memblokir approval runbook | ⏳ |
| 17 | **Putaran 5.** Salinan untuk impor final = dump final saat freeze (Cutover Plan); hasil audit dari dump sebelum freeze hanya untuk audit awal dan gladi (2.4 langkah 1) | Setujui | ⏳ |

## 5. Checklist verifikasi DB Validator

- [ ] **Kedua skrip hanya baca.** Isinya hanya `SELECT`, `SET SESSION`, variabel sesi (`SET @…`, termasuk pola `@dbv011_ss`/`@dbv011_kls`/`@dbv011_trim`/`@dbv011_bs`), `START TRANSACTION READ ONLY`/`COMMIT`, `PREPARE`/`EXECUTE` atas SELECT yang disusun dari `information_schema` (teks kueri ikut dicetak), dan satu `PREPARE`/`EXECUTE` bersyarat engine atas `SET SESSION information_schema_stats_expiry = 0` (MySQL 8) atau `DO 0` (MariaDB). Cek cepat berikut harus 0 untuk kedua berkas:
  ```
  grep -ciE "^\s*(INSERT|UPDATE|DELETE|REPLACE INTO|CREATE|DROP|ALTER|TRUNCATE|RENAME|GRANT|LOAD)" backend/docs/db-review/sql/*.sql
  ```
  Hasil developer: 0. `ALTER` di skrip counter hanya berupa teks yang dicetak.
- [ ] **Daftar UNIQUE 2.1 = skema v2.** Kueri berikut harus menghasilkan 28 index di tabel master, sama dengan bagian A skrip. Tanpa filter `TABLE_NAME`, hasilnya 32 = 28 master + 4 index auth (`pengguna.nip`, `pengguna.username`, `token.token_hash`, `forgot_attempts.token_hash`); diukur di DB scratch.
  ```sql
  SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) kolom
    FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'
     AND TABLE_NAME NOT IN ('pengguna', 'token', 'forgot_attempts')
   GROUP BY TABLE_NAME, INDEX_NAME ORDER BY TABLE_NAME;
  ```
  Jalankan di DB v2 hasil `migrate --all`.
- [ ] **Skrip audit di MariaDB 10.4**, pada DB berisi `migrate --all` (dan seed test bila ada). Harapannya:
  - error 1054 hanya pada 8 kueri (5 kueri akun A6 lama, 2 kueri A6 putaran 5 — daftar hash bukan 32 heks dan akun tanpa email — serta profil `pengguna`), karena kolom legacy `id`/`id_pegawai`/`UserLevel` tidak ada di v2. Profil format hash berjalan, karena hanya membaca `password` dan `status`;
  - bagian 0 kosong (v2 tanpa trigger dan tanpa `password_decode`);
  - error 1146 hanya pada 3 kueri B5 yang membaca `absen_ijin`/`d_konket` (tabel presensi legacy, tidak ada di v2);
  - **tidak ada** error 1193: penyetelan `information_schema_stats_expiry` bersyarat engine (`DO 0` di MariaDB);
  - **tidak ada** error 1139 (atau error regex lain) di A4 singkatan, A6, B1, B2 wilayah/`kantor`, B9, dan D: `@dbv011_trim` kini berisi escape regex, bukan byte NUL (2.2 #6);
  - 0 baris WAJIB_0; PERIKSA hanya 4 sentinel wilayah (B1), ditambah baris profil format hash bila DB berisi akun (Argon2id atau NULL; bukan temuan); baris `INFO D: ke-27 entri katalog diperiksa` serta `INFO E: ke-29 tabel daftar ada di salinan`;
  - kueri dinamis D dan E jalan.

  Cek cepat pola di MariaDB (satu sesi, setelah kedua baris `SET @dbv011_kls`/`SET @dbv011_trim` dari kepala skrip audit):
  ```sql
  SELECT LOCATE(CHAR(0), @dbv011_trim) posisi_nul,
         HEX(REGEXP_REPLACE(CONVERT(CONCAT(CHAR(0), CHAR(11), ' a', CHAR(9), CHAR(0)) USING utf8mb4), @dbv011_trim, '')) harus_61,
         HEX(REGEXP_REPLACE(CONVERT(CONCAT(CHAR(0xC2, 0xA0), 'a ') USING utf8mb4), @dbv011_trim, '')) harus_C2A061;
  ```
  Hasil developer di MySQL 8.0.30 (tiga `sql_mode`): `0`, `61`, `C2A061` (NUL/VT/TAB/spasi di tepi dibuang, NBSP tidak).

  Poin terakhir penting: kompatibilitas `REGEXP_REPLACE` (PCRE), pola regex dari variabel sesi yang disusun dengan `CHAR()` (termasuk escape `\t`, `\n`, `\r`, `\x{0B}`, `\x{00}` di kelas karakter `@dbv011_trim`), `GROUP_CONCAT … INTO @a, @b`, dan `PREPARE` (termasuk `PREPARE … FROM 'DO 0'`) di MariaDB 10.4 **belum diuji developer**. Catatan: tiruan stripslashes memakai rujukan grup `$1` (MySQL 8/ICU). Di MariaDB, `$1` tidak dikenali (MariaDB memakai `\\1`), sehingga kunci nilai ber-backslash berbeda. DB v2 bersih tidak berisi backslash, jadi hasil uji ini tidak terpengaruh; salinan legacy tetap wajib MySQL 8 (2.4 langkah 1).
- [ ] Opsional: uji deteksi di DB scratch tanpa UNIQUE berisi duplikat sintetis, seperti di 6.2 dan 6.5 (kode wilayah berspasi, status NULL).
- [ ] **Kesetiaan counter salinan** (2.4 langkah 1): kueri counter sumber vs salinan dipahami dan disetujui sebagai langkah wajib sebelum langkah 8.
- [ ] **Pembersihan salinan** (2.4 langkah 1, putaran 5): kueri pencetak `DROP TRIGGER` dan `ALTER TABLE … DROP COLUMN password_decode` dipahami dan disetujui. Kueri ini hanya dijalankan di salinan, dan buktinya bagian 0 audit kosong.
- [ ] **Skrip counter di MariaDB 10.4**, dengan cara pakai 2.7 (`--init-command="SET @legacy = '<skema lain di instance yang sama>'"` + stdin, tanpa `--force`), dan juga cara lama `-e "SET @legacy = …;` + `SOURCE`. Keluarannya harus tanpa ERROR, baris `legacy = …, v2 = …`, lalu satu baris per tabel AUTO_INCREMENT dengan perintah atau "tidak perlu". Tanpa `@legacy`, atau dengan nama skema yang tidak ada, keluarannya hanya `BERHENTI: …` lalu Empty set.
- [ ] Opsional, **MariaDB 10.4: `AUTO_INCREMENT = 128` pada tabel ber-PK TINYINT** di DB scratch (mis. `ALTER TABLE jenis_kp AUTO_INCREMENT = 128` lalu INSERT lewat API atau SQL). Catat error yang muncul (1467, 167, atau lainnya) dan apakah API memberi 422 atau 500. Hasilnya melengkapi alasan 2.7; keputusan "tidak disalin" tidak bergantung padanya.
- [ ] Putuskan butir Bagian 4. Teruskan Bagian 3 (3.6) ke TL/DBA setelah kartu ISSUE-024 terjawab.
- [ ] Laporkan hasilnya di PR.

## 6. Verifikasi developer

### 6.1 Lingkungan

**Engine dan klien:**
- MySQL 8.0.30 lokal (Laragon). `@@GLOBAL.sql_mode` = STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO, NO_ENGINE_SUBSTITUTION. `time_zone` = SYSTEM (+07).
- Klien `mysql` 8.0.30 dengan `--default-character-set=utf8mb4`. Tanpa opsi ini klien Windows memakai **cp850**; percobaan pertama gagal 1253 karena hal ini.
- MariaDB 10.4 tidak tersedia di mesin developer.

**DB scratch putaran 1 (29-09-2026), dibuat lalu di-DROP dengan nama persis:**
- **`simpeg_v2_s_dbv011q7`**: `php spark migrate --all` di worktree `origin/main` `0f6268a`, ditambah seed test `MasterDataSeeder` (memuat `AuthSeeder`). Hasilnya 23 migration dalam satu batch.
- **`simpeg_v2_s_dbv011q7l`**, berbentuk legacy:
  - 10 tabel [K] dengan `CREATE TABLE … LIKE simpeg01.*` (struktur saja; `agama` tetap tabel latin1 + kolom `utf8mb4_0900_ai_ci`);
  - `pengguna` LIKE `simpeg_prod_duplikat`;
  - `jenis_konket` LIKE `simpegdev_local` (`old_id` VARCHAR);
  - tabel [I] lain berbentuk v2 tanpa UNIQUE/CHECK;
  - diisi data sintetis yang sengaja melanggar setiap cek.

**DB scratch putaran 2 (30-09-2026), dibuat lalu di-DROP dengan nama persis:**
- **`simpeg_v2_s_dbv011bv`**: `php spark migrate --all` di worktree branch ini (migration = `0f6268a`) tanpa seed; 23 migration dalam satu batch.
- **`simpeg_v2_s_dbv011bl`**, berbentuk legacy: data sintetis putaran 1 dan data adversarial pra-review internal, ditambah kasus 6.3 (nilai ber-backslash ditulis sebagai literal hex agar tidak diubah escape SQL), serta `absen_ijin`/`d_konket`/`faq_rate`/`faq_related_article`/`firebase_token`/`forgot_attempts` LIKE `simpeg01`.
- **`simpeg_v2_s_dbv011bm`**: DB mini berisi `provinsi`, `kantor` (kolom `nama_kantor` diganti nama), dan `jenis_kp` (PK diganti nama), untuk uji daftar terlewat D/E, uji TINYINT 127/128, dan uji TIMESTAMP R1b.

**DB scratch putaran 3 (30-09-2026), dibuat lalu di-DROP dengan nama persis:**
- **`simpeg_v2_s_dbv011cv`**: `php spark migrate --all` di worktree branch ini (migration = `0f6268a`; PR #11 `ff74bf9` tidak mengubah migration) tanpa seed; 23 migration dalam satu batch. Setelah audit, diisi 5 akun uji untuk kueri tabrakan (6.4 butir 4).
- **`simpeg_v2_s_dbv011cl`**, berbentuk legacy: data putaran 2, kasus tambahan pra-review internal putaran 2 (username diawali NBSP, nama provinsi ber-TAB/LF, kode kelurahan `'11.01.01.2004'`, `jenis_kp` berisi satu backslash, `old_id` `'+9'`/`' 14 '`), dan kasus 6.4 (karakter khusus ditulis hex).
- **`simpeg_v2_s_dbv011cm`**: DB mini seperti putaran 2.
- **`simpeg_v2_s_dbv011ck`**: skema kerja untuk uji fallback kueri tabrakan akun (tabel `akun_v2`).

**DB scratch putaran 4 (30-09-2026), dibuat lalu di-DROP dengan nama persis:**
- **`simpeg_v2_s_dbv011dv`**: `php spark migrate --all` di worktree branch ini setelah rebase ke `ff74bf9`, tanpa seed; 23 migration dalam satu batch. Setelah audit, diisi 5 akun uji untuk kueri tabrakan (sama dengan putaran 3).
- **`simpeg_v2_s_dbv011dl`**, berbentuk legacy: data putaran 3 (dibangun ulang dari skrip data yang sama) ditambah kasus 6.5 (kode wilayah berspasi/ber-TAB di tabel [I] ber-kode VARCHAR, sentinel berspasi, status NULL).
- **`simpeg_v2_s_dbv011dm`**: DB mini seperti putaran 2/3.
- **`simpeg_v2_s_dbv011dk`**: skema kerja untuk uji fallback kueri tabrakan akun.
- **`simpeg_v2_s_dbv011dc`** dan **`simpeg_v2_s_dbv011dd`**: salinan `…dl` lewat `mysqldump` bawaan dan lewat dump data-saja (`--no-create-info` ke tabel `CREATE TABLE … LIKE`), untuk uji kesetiaan counter (2.4 langkah 1).
- **`simpeg_v2_s_dbv011dp`**: tabel berbentuk v2 dengan wilayah berukuran nyata (34 provinsi, 510 kabupaten/kota, 7.140 kecamatan, 85.682 kelurahan, 300 kantor) untuk uji waktu jalan B2/B9 yang kini membandingkan kode hasil trim.

**Yang tidak ditulis:** DB dev `simpeg_v2` (tetap 11 migration, batch 3; dicek setelah uji), DB legacy lokal (`simpeg01`, `simpeg_prod_duplikat`, `simpegdev_local`; hanya struktur yang dibaca), dan server lain.

### 6.2 Hasil putaran 1 (29-09-2026)

| Uji | Hasil |
|---|---|
| Audit di DB berbentuk legacy (`-vvv --table --force`) | rc 0, **0 error**. **29 dari 30 kueri duplikat** menemukan grup yang ditanam; `pengguna.username` tidak bisa ditanam karena legacy sudah UNIQUE `username`. Kasus yang terdeteksi: beda kapitalisasi; spasi akhir (`agama` 0900_ai_ci NO PAD: `'Islam'`/`'islam '`); spasi ganda (`'Sumatera  Utara'`); `\'` vs `'` via stripslashes (`agama`, `diklat`); `'08'` vs `'8'` (`old_id`); singkatan `'S.1'`/`'s.1 '`; NIP dengan spasi akhir; email beda kapital + spasi (PERIKSA). **Kasus negatif** (tidak boleh terdeteksi) lolos: nama sama di lingkup berbeda (kabupaten beda provinsi, `jenis_status` beda `status_pegawai`, `diklat` beda jenis), dan `jenis_hukdis` `\'lisan\'` vs `'lisan'` karena tanpa stripslashes. Semua pelanggaran pendukung yang ditanam juga ditemukan: yatim di 6 FK dan `kantor` (kode sentinel tidak dianggap yatim), `jenis_hukdis` NULL, `hari_libur` terbalik/tumpang tindih/tanpa jenis/jenis yatim, `jenis_diklat` 7, `row_jurusan` `'d_iii'`, `old_id` NULL/`'abc'`/`'0'`, `pangkat.order` 200, tanggal nol (`hari_libur.tgl_mulai`, `pengguna.created_at`), nama 60 karakter di kolom 50, nama berisi spasi saja, status ENUM kosong `gol_pppk`, counter TINYINT 200 (`counter > 128`; putaran 2: `>= 128`), baris hard-coded hilang (`TIDAK ADA`), dan `tanda_jasa` 44 yang bukan `LAIN-LAIN`. Semua PERIKSA/INFO muncul sesuai rancangan |
| Audit di DB berbentuk v2 (bersih, UNIQUE aktif) | **0 baris WAJIB_0**. Hanya 5 error **1054** yang diharapkan (kolom legacy `pengguna.id`/`id_pegawai` tidak ada di v2). PERIKSA: 4 sentinel wilayah (baris migration `100200`) dan 1 `hari_libur` tanpa jenis (baris seed test) |
| Perbaikan selama pengujian | Kueri penyusun D semula memakai `COLLATE utf8_general_ci` dan gagal di klien cp850 → dihapus. `UNION` di D gagal 1271 karena campuran collation legacy → nilai legacy di-`CONVERT` ke utf8mb4. Tumpang tindih `hari_libur` kini mengabaikan tanggal nol (dilaporkan di E). `hari_libur` dengan jenis NULL dipisah menjadi PERIKSA karena kolom v2 nullable |
| Skrip counter (`@legacy` = DB berbentuk legacy, v2 = DB scratch v2) | 23 tabel AUTO_INCREMENT. `ALTER` dicetak untuk 11 tabel yang counter legacy-nya lebih besar, "tidak perlu" untuk sisanya, dan `bidang_kursem` (TINYINT, 200) → PERIKSA. `ALTER TABLE diklat AUTO_INCREMENT = 17` yang dijalankan di scratch berlaku; skrip ulang menjawab "tidak perlu". `ALTER TABLE bidang_kursem AUTO_INCREMENT = 200` di scratch membuat INSERT berikutnya gagal **1467**; skrip tetap menandai PERIKSA setelah counter v2 ikut 200 |
| Zona waktu (sesi / DB scratch) | Hasil ukur di 3.1 |
| Gate `fastcheck --only backend` (setara `./check.sh backend`: PHPStan level 5, PHP-CS-Fixer, PHPUnit 2 shard dengan DB test per shard) | `SEMUA CHECK LOLOS`: PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 237 files that can be fixed`, PHPUnit `OK` di kedua shard (231 + 295 = 526 test, 16.901 assertion). Dokumen dan skrip SQL tidak disentuh gate; gate hanya memastikan tidak ada yang rusak |

### 6.3 Revisi putaran 2 (30-09-2026): tindak lanjut pra-review internal putaran 1

Pra-review internal putaran 1 (commit `ba89dcf`) memberi verdict **perlu perbaikan**: 2 major, 7 minor, dan 4 nit. Semua butir ditangani sebagai berikut.

| # | Temuan | Perubahan | Bukti uji ulang (MySQL 8.0.30, DB scratch 6.1) |
|---|---|---|---|
| 1 | **Major:** R1 (konversi +07 → +00 untuk "DATETIME/TIMESTAMP") bertentangan dengan R4 (sesi `+00:00`). Akibatnya TIMESTAMP bergeser 7 jam dua kali tanpa error | R1 dipecah menjadi **R1a** (DATETIME dikonversi) dan **R1b** (TIMESTAMP dibaca di sesi `+00:00`, tidak dikonversi). R4 kini berlaku di sisi baca dan sisi tulis. Kolom TIMESTAMP legacy dicatat di 3.1, syarat `--tz-utc` di 2.4 langkah 1, dan E2 skrip mendaftar kolom TIMESTAMP salinan. Bagian 4 #8 dan 2.6 #8 ikut diperbarui | TIMESTAMP ditulis 11:36:43 di sesi `+07:00`, dibaca 04:36:43 di sesi `+00:00`. Bila dikonversi lagi, hasilnya 2026-09-28 21:36:43 (kesalahan yang dicegah R1b). DATETIME tetap 11:36:43 di kedua sesi, dan R1a memberi 04:36:43. E2 di DB berbentuk legacy mendaftar `firebase_token.created_at`/`updated_at` dan `forgot_attempts.attempt_time` |
| 2 | **Major:** syarat DBV-005 6.5 #3 "setiap `absen_ijin.kategori` punya pasangan `old_id`" belum dicek | B5: `absen_ijin.kategori` dan `d_konket.kategori` tanpa pasangan `old_id` = PERIKSA, dengan jumlah baris. `batas` kode baru = INFO. 2.6 #3 dan kasus khusus 2.5 dilengkapi; tabel arsip `absen_ijin_2024*` dicatat di 2.3 | `absen_ijin` kategori 10 (1 baris) dan 99 (2 baris), serta `d_konket` 77, terlaporkan. Kategori berpasangan (8, 13) tidak muncul. `batas` = 99 |
| 3 | Minor: ambang counter TINYINT `> 128` meleset satu | `>= 128` di skrip counter, bagian C, 2.7, dan Bagian 4 #6 | `jenis_kp` counter legacy 128 → PERIKSA di C maupun skrip counter. Di tabel TINYINT: `AUTO_INCREMENT = 128` → INSERT gagal **1467**; `= 127` → id 127 masih diberikan; setelah id 127 terisi, counter terbaca 127 dan INSERT berikutnya gagal **1062** |
| 4 | Minor: NULL di kolom NOT NULL v2 tidak menjadi WAJIB_0 | D: NULL = WAJIB_0. B2 `kantor`: `NOT (x <=> kode)`. B8 baru: `jenis_status.status_pegawai` dan `pangkat.cpns` (NULL/bukan bilangan/di luar TINYINT = WAJIB_0, di luar 1/2 = PERIKSA). 2.2 #5 diperbarui | `jenjang_pendidikan_singkat` NULL → WAJIB_0 (D). `kantor` id 5 `id_kelurahan=NULL` → WAJIB_0. `status_pegawai` NULL/`'A'` → WAJIB_0, `9`/`'0'` → PERIKSA. `cpns` NULL/`'x'` → WAJIB_0, `'0'` → PERIKSA, `' 1 '` lolos. Ke-27 kolom katalog terukur NOT NULL di v2 |
| 5 | Minor: kueri yang error bisa membuat audit tampak lolos | Aturan triage di kepala skrip, 2.3, 2.4 langkah 3–4, dan Bagian 4 #3: selain 1193 dan 1146 untuk tabel yang memang tidak ada, error = audit belum lengkap. D dan E mencetak daftar entri terlewat | DB mini: D mencetak 24 entri "tabel tidak ada", `kantor.nama_kantor (kolom tidak ada)`, dan `jenis_kp.jenis_kp (PK id_jenis_kp tidak ada)`. E mencetak 26 tabel yang tidak ada. Di DB legacy dan v2 lengkap tercetak `INFO D: ke-27 entri katalog diperiksa` dan `INFO E: ke-29 tabel daftar ada di salinan` |
| 6 | Minor: PK TINYINT > 127 dan urutan > 127 berlabel PERIKSA, padahal pasti gagal 1264 | Kolom `cek` bagian C diturunkan dari `peringatan`: PK > 127 dan urutan > 127 = WAJIB_0; status di luar 1/2/10 dan counter ≥ 128 = PERIKSA | `jenis_pegawai` id 130 → `WAJIB_0 profil`; `tanda_jasa` 141 baris tampil → `WAJIB_0 profil`; `bidang_kursem` (200), `jenis_kp` (128), dan `gol_pppk` (ENUM kosong) → `PERIKSA profil` |
| 7 | Minor: tiruan stripslashes bisa melewatkan duplikat dan melaporkan duplikat palsu | Tiruan penuh `REGEXP_REPLACE(x, '\\(.?)', '$1')` (hex) di A dan D. Aturan pencocokan ulang nilai ber-backslash dengan PHP ditambahkan di 2.5. 2.2 #3 memuat batasnya (`\0`; MariaDB `\\1`) | 23 string hex dibandingkan dengan `stripslashes` PHP 8.4 di tiga `sql_mode`: identik kecuali `\0`. DB: `Ber\at`/`Berat`, `Bintang \'Z`/`Bintang 'Z`, dan `Libur X\`/`libur x` kini duplikat; `Bintang \\'Y`/`Bintang 'Y` bukan duplikat. Catatan: data adversarial pra-review internal `'Se\dang'` tersimpan sebagai `Sedang` (escape `\d` dibuang MySQL saat INSERT), jadi kasus backslash putaran 2 ditulis sebagai literal hex |
| 8 | Minor: tabel target v2 yang sudah berisi data tidak dibahas | 2.4 langkah 6: prasyarat tabel target kosong (kecuali sentinel dan akun yang disepakati), cek `COUNT(*)`, dan kueri tabrakan akun v2 ↔ legacy. Bagian 4 #10 baru untuk keputusan Dev/Prod | Kueri tabrakan diuji di DB scratch. Akun v2 id 1 (tabrakan ID), `Admin` (tabrakan `username` tak peka huruf dengan `admin`), dan NIP sama terdeteksi; akun lain tidak. Baris uji dihapus lagi |
| 9 | Minor: G-04 6.3 #5 dan G-10 6.3 #10 belum dicakup | B6: peta `pangkat.order` per `cpns` (di-trim); ganda atau 0 = PERIKSA. C: jumlah baris `faq_rate` dan `faq_related_article` | `(cpns 0, order 9)` × 2 dan `(2, 0)` → PERIKSA, grup lain INFO; `faq_rate` 2 baris, `faq_related_article` 1 baris |
| 10 | Nit: dua baris basi di `02-MasterData.md` | Status DBV-002 ✅; strict mode ✅ DBV-010; audit duplikat → DBV-011 | — |
| 11 | Nit: kueri checklist Bagian 5 menghasilkan 32, bukan 28 | Filter `TABLE_NAME NOT IN ('pengguna', 'token', 'forgot_attempts')` dan penjelasan 32 = 28 + 4 | Terukur: 32 tanpa filter, 28 dengan filter |
| 12 | Nit: skrip counter tidak berhenti bila `@legacy` kosong | Kueri utama disaring `@legacy` terisi dan skemanya ada | Tanpa `@legacy`: `BERHENTI…` lalu Empty set. `@legacy = 'tidak_ada_xyz'`: `BERHENTI: skema … tidak ditemukan` lalu Empty set. `@legacy` valid: 23 baris (`jenis_kp` 128, `jenis_pegawai` 131, dan `bidang_kursem` 200 → PERIKSA) |
| 13 | Nit: base branch, opsi (a), versi MySQL | Opsi (a) 2.5 diberi pengecualian: `hari_libur`, `old_id`, akun, dan akhiran pada singkatan `jenjang_pendidikan`. 2.4 langkah 1 menetapkan MySQL ≥ 8.0.30 (uji ulang bila 8.0.21). Base tidak di-rebase: PR #11 (`ff74bf9`) tidak menyentuh migration maupun normalisasi yang dirujuk, dan `git merge-tree` bersih (Rujukan) | — |

**Hasil audit putaran 2** (`-vvv --table --force`, tiga `sql_mode`: global lokal; bawaan MySQL 8 + ONLY_FULL_GROUP_BY; ANSI_QUOTES + NO_BACKSLASH_ESCAPES + PIPES_AS_CONCAT). Baris data identik di ketiga mode; satu-satunya selisih di DB mini adalah lebar kolom tampilan.

| DB | Hasil |
|---|---|
| Berbentuk legacy `…bl` | rc 0, **0 error**, 103 result set (97 kueri tetap + 4 cetak + 2 EXECUTE). 67 baris WAJIB_0; 29 dari 30 kueri duplikat menemukan grup yang ditanam (`pengguna.username` tetap tidak bisa ditanam). 36 baris PERIKSA sesuai rancangan |
| Berbentuk v2 `…bv` (bersih, tanpa seed) | **0 baris WAJIB_0**. Error hanya 5 × 1054 (kolom legacy `pengguna.id`/`id_pegawai`) dan 3 × 1146 (`absen_ijin`/`d_konket` tidak ada di v2), sesuai harapan Bagian 5. PERIKSA: 4 sentinel wilayah |
| Mini `…bm` | 88 × 1146 dan 4 × 1054, sesuai rancangan (tabel sengaja dihilangkan, kolom sengaja diganti nama). Daftar terlewat D/E tercetak (butir 5) |

Cek hanya-baca Bagian 5 (`grep -ciE …`) = 0 untuk kedua berkas.

| Uji | Hasil |
|---|---|
| Gate `fastcheck --only backend` (prefix `dbv11b`, setara `./check.sh backend`) | `SEMUA CHECK LOLOS`: PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 237 files that can be fixed`, PHPUnit `OK` di kedua shard (231 + 295 = 526 test, 16.901 assertion). Revisi ini hanya menyentuh dokumen dan skrip SQL |

### 6.4 Revisi putaran 3 (30-09-2026): tindak lanjut pra-review internal putaran 2

Pra-review internal putaran 2 (commit `e8fe815`) memberi verdict **perlu perbaikan**: 1 major, 3 minor, dan 6 nit. Semua butir ditangani. Saat menguji ulang ditemukan satu masalah tambahan (butir 11).

| # | Temuan | Perubahan | Bukti uji ulang (MySQL 8.0.30, DB scratch 6.1) |
|---|---|---|---|
| 1 | **Major:** skrip counter tidak menghasilkan apa-apa di MariaDB bila dijalankan sesuai petunjuknya. `SET SESSION information_schema_stats_expiry = 0` memberi 1193, dan `-e "…; SOURCE …"` berhenti pada error pertama | Penyetelan bersyarat engine di **kedua** skrip: `SET @dbv011_s = IF(VERSION() LIKE '%MariaDB%', 'DO 0', 'SET SESSION …')` + `PREPARE`/`EXECUTE`. Cara pakai counter kini `--init-command="SET @legacy = '…'"` + stdin (satu baris, jalan di klien MySQL 8 dan MariaDB); cara lama tetap jalan. Pengecualian 1193 dihapus dari triage (2.3, 2.4 langkah 2–3, Bagian 4 #3, Bagian 5, kepala skrip). 2.7 dan Bagian 5 diperbarui | Tiruan MariaDB (variabel diganti nama yang tidak dikenal, seperti cara reviewer): skrip putaran 2 lewat `-e … SOURCE` → hanya `ERROR 1193 … at line 29`. Skrip putaran 3 dengan cabang MariaDB dipaksa (`DO 0`) dan cabang MySQL berisi variabel tak dikenal → 0 error, 23 baris tabel, lewat `SOURCE` maupun `--init-command` + stdin; keluarannya sama dengan jalur MySQL. Jalur MySQL: 0 error, 23 baris (`jenis_kp` 128, `jenis_pegawai` 131, `bidang_kursem` 200 → PERIKSA). Tanpa `@legacy` / skema `tidak_ada_xyz` → `BERHENTI…` lalu Empty set. Skrip audit dengan cabang MariaDB dipaksa: 0 error, 108 result set, baris identik dengan jalur MySQL |
| 2 | Minor: prasyarat impor tidak memuat gerbang D-12/DBV-009 dan pencocokan nilai [I]; WAJIB_0 panjang bisa keliru diselesaikan dengan memotong data; `username` > 100 dan kode wilayah tidak dicek | 2.4 langkah 6: prasyarat **D-12 tuntas** dan **nilai [I] dicocokkan dengan DDL dump + dikoreksi lewat migration ALTER + DBV**; Bagian 4 #11 baru. Aturan triage "WAJIB_0 panjang/tipe pada kolom [I] → skema dicocokkan dulu" di 2.3, 2.4 langkah 3, 2.5 (PERBAIKI), dan kepala skrip. Cek baru: A6 panjang akun (`username` > 100, `nip` > 30, `name`/`email` > 150 = WAJIB_0) dan **B9** kode wilayah (lebih dari CHAR(N) = WAJIB_0, bukan tepat N digit = PERIKSA) | `'11.01.01.2004'` (kelurahan) → `WAJIB_0 kode wilayah lebih dari CHAR(10)`; `'1.01'`, `'11O1'`, `'A1'`, `'110101000'` → PERIKSA bukan tepat N digit; kode 2/4/7/10 digit yang benar dan sentinel tidak muncul. Akun `username` 101 karakter dan akun `nip` 31/`name` 151/`email` 151 → WAJIB_0; `username` 100 karakter + 2 spasi akhir tidak muncul |
| 3 | Minor: lingkup A1 `jenis_status.status_pegawai` belum dinormalkan; B8 membandingkan sebagai teks | A1 mengelompokkan per nilai impor (trim → bilangan; bukan bilangan dikelompokkan per nilai mentah), anggota menampilkan `status_pegawai` mentah. B8 membandingkan secara numerik dan menampilkan `nilai_impor`. B6 `pangkat.cpns` ikut dinormalkan. 2.2 #5 memuat buktinya (`Lm_umum.php:611`) | `Pensiun` dengan `'1'`, `'01'`, `' 1'` → satu grup duplikat A1 (3 anggota); `'+2'` tidak masuk grup. B8: `'01'`, `'+2'`, `' 0001'` tidak lagi dilaporkan (putaran 2 melaporkan `'01'` sebagai PERIKSA dan `'+2'`/`' 0001'` sebagai WAJIB_0); `'1000'` → WAJIB_0. B6: `cpns` `' 1 '` dan `'01'` dengan `order` 3 → satu grup PERIKSA ganda |
| 4 | Minor: kueri tabrakan akun dan skrip counter mensyaratkan satu instance, padahal engine v2 bisa berbeda | 2.4 langkah 1: salinan di skema tersendiri, instance tidak harus terpisah. Fallback kueri tabrakan: ekspor `(id_pengguna, username, nip)` akun v2 sebagai INSERT (`-N -B -r`) → tabel `akun_v2` di skema kerja instance salinan → kueri yang sama; sisi v2 hanya dibaca, berkas dan tabel dihapus setelahnya. Langkah 8 merujuk fallback counter. Bagian 4 #10 diperbarui | 5 akun uji v2 (`Admin`, `budi2` + NIP, `qa_lain`, `qa_nip` + NIP, `O'Ne\il`). Kueri satu instance: 6 baris (ID + username `admin`; `budi2` ↔ legacy 6, NIP ↔ legacy 2 dan 3 (spasi akhir); NIP ↔ legacy 12 (`\x0B` akhir) dan 13). Legacy 5 (NBSP + `budi2`) dan 14 (NBSP + NIP) tidak ikut, sesuai `trim()` PHP. Fallback memberi keluaran yang sama persis; `akun_v2` = 5/5 baris identik biner. Tanpa `-r`, INSERT untuk `O'Ne\il` rusak (`'O\\'Ne\\\\il'`) |
| 5 | Nit: normalisasi "kode" `[[:space:]]` membuang lebih banyak daripada `trim()` PHP (NBSP, `\f`, U+2000–U+3000) | Kunci kode (A4 singkatan, A6 `username`/`id_pegawai`/`email`, D `kode_ss`, B9, kueri tabrakan) memakai `@dbv011_trim` = kelas `trim()` PHP. 2.1, 2.2 #2/#6, dan kepala skrip diperbarui | `@dbv011_trim` vs `trim()` PHP 8.4: 61 string (tiap karakter spasi/kontrol/Unicode di depan, tengah, belakang; `0`, `t`, `n`, `x`, `B`, backslash) × 3 `sql_mode`, langsung maupun lewat tabel turunan + WHERE → 183/183 identik. Data: `X'C2A0'`+`budi2` vs `budi2` dan `X'C2A0'`+`SD` vs `SD` tidak lagi dilaporkan duplikat; `S3`+`\x0B` vs `S3` tetap duplikat |
| 6 | Nit: angka 211 kemunculan di 19 berkas ikut menghitung `getWibNow()` | 3.1 dan 3.5 opsi C: 209 di 18 berkas (grep peka huruf), metode dan pengecualian `getWibNow()` (`Lsl_gaji.php:80, 85`) dicatat | Grep peka huruf: 209 / 18; tidak peka huruf: 211 / 19; selisihnya hanya `Lsl_gaji.php` |
| 7 | Nit: bukti `addslashes` Batch 1 baru 5 dari 7 tabel | 2.2 #3: ditambah `:1313` (kecamatan) dan `:1561` (kelurahan), dengan nama tabel per baris | Dibaca di `Lm_umum.php` legacy: kedua baris `addslashes` |
| 8 | Nit: B2 tidak memeriksa FK tabel anak FAQ; GABUNG `faq_article` belum dibahas | B2: yatim `faq_rate.id_faq_article` dan `faq_related_article` (kedua kolom) = WAJIB_0; relasi ke diri sendiri = PERIKSA. 2.5: paragraf GABUNG `faq_article` (rating ganda per NIP, relasi ganda/ke diri sendiri diputuskan sebelum UPDATE) | `faq_rate (999, '1990')` → WAJIB_0; `faq_related_article (998, 1)` → WAJIB_0 `id_article_main=998`; `(1, 1)` → PERIKSA; baris valid tidak muncul |
| 9 | Nit: kalimat "counter 128 mengubah 422 menjadi 500" hanya teruji di MySQL 8 | 2.7 dan kepala skrip counter dibatasi ke MySQL 8, ditambah catatan MariaDB (167, belum diuji). Butir uji opsional MariaDB di Bagian 5 | — |
| 10 | Nit: dokumen asal tidak merujuk DBV-011; dump non-mysqldump | Satu kalimat "Langkah runbook: DBV-011" di G-06 6.5 dan di bawah G-01 8.4; rujukan di butir D-11 `01-Auth.md`. 2.4 langkah 1: dump dengan alat lain (mis. HeidiSQL) → bandingkan contoh `UNIX_TIMESTAMP(kolom_timestamp)` di sumber dan salinan sebelum R1b dipakai | — |
| 11 | **Tambahan (ditemukan saat uji ulang):** B9 versi awal melaporkan kode 7 digit yang benar (`'1101010'`) sebagai "bukan 7 digit", hanya di `sql_mode` ANSI_QUOTES + NO_BACKSLASH_ESCAPES + PIPES_AS_CONCAT | Penyebab terukur: *derived condition pushdown* MySQL 8.0.30 mem-parse ulang kondisi WHERE yang didorong ke tabel turunan; di `NO_BACKSLASH_ESCAPES` literal ber-backslash di kondisi itu berubah (pola `trim()` hex ikut membuang `0` di akhir). Semua pola yang memuat backslash/karakter kontrol kini variabel sesi yang disusun dengan `CHAR()` (`@dbv011_ss`, `@dbv011_trim`, `@dbv011_bs`); teks kueri kedua skrip tidak lagi memuat backslash (2.2 #6). Pola B9 juga dibuat konstan | Dengan `optimizer_switch='derived_condition_pushdown=off'` kesalahan hilang (0 baris keliru). Kueri turunan minimal `WHERE CHAR_LENGTH(t) <> CHAR_LENGTH(k)` mengembalikan `'1101010'` walaupun `t` yang ditampilkan 7 karakter. Uji `trim()` dengan pola hex lama: 52 selisih dari 183, semuanya di `NO_BACKSLASH_ESCAPES` lewat tabel turunan + WHERE; dengan variabel sesi 0 selisih. Kueri turunan serupa dengan pola stripslashes hex melewatkan `Ber\at` di mode itu; dengan `@dbv011_ss` tidak lagi. Setelah perubahan, baris data audit identik di ketiga mode |

**Hasil audit putaran 3** (`-vvv --table --force`, tiga `sql_mode` yang sama dengan putaran 2). Baris data identik di ketiga mode untuk ketiga DB.

| DB | Hasil |
|---|---|
| Berbentuk legacy `…cl` | rc 0, **0 error**, 108 result set (102 kueri tetap + 4 cetak + 2 EXECUTE). 81 baris WAJIB_0, 44 PERIKSA. Skrip putaran 2 pada data yang sama: 79 WAJIB_0 / 41 PERIKSA. Selisihnya persis perubahan butir 2, 3, 5, dan 8: +1 grup A1 `Pensiun`, +2 panjang akun, +2 yatim FAQ, +1 B9 WAJIB_0, −2 B8 WAJIB_0 keliru (`'+2'`, `' 0001'`), −2 duplikat palsu NBSP (singkatan `SD`, username `budi2`); PERIKSA +4 B9, +1 relasi FAQ ke diri sendiri, +1 B6 `cpns` 1/`order` 3, −2 B8 `'01'`, −1 D NBSP `SD`. Anggota NBSP juga keluar dari grup NIP `198001012005011001` |
| Berbentuk v2 `…cv` (bersih, tanpa seed) | **0 baris WAJIB_0**. Error hanya 6 × 1054 (kolom legacy `pengguna.id`/`id_pegawai`: 5 kueri A6 + profil `pengguna`) dan 3 × 1146 (`absen_ijin`/`d_konket`), sesuai Bagian 5. Tidak ada 1193. PERIKSA: 4 sentinel wilayah. `INFO D: ke-27 …` dan `INFO E: ke-29 …` tercetak |
| Mini `…cm` | 93 × 1146 dan 4 × 1054, sesuai rancangan (5 kueri baru membaca tabel yang sengaja tidak ada). Daftar terlewat D/E tercetak |

Cek hanya-baca Bagian 5 (`grep -ciE …`) = 0 untuk kedua berkas.

| Uji | Hasil |
|---|---|
| Gate `fastcheck --only backend` (prefix `dbv11b`, setara `./check.sh backend`) | `SEMUA CHECK LOLOS`: PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 237 files that can be fixed`, PHPUnit `OK` di keempat shard (105 + 125 + 181 + 115 = 526 test, 16.901 assertion). Revisi ini hanya menyentuh dokumen dan skrip SQL |

### 6.5 Revisi putaran 4 (30-09-2026): tindak lanjut pra-review internal putaran 3

Pra-review internal putaran 3 (commit `2cb6d71`) memberi verdict **approve** dengan 3 minor dan 2 nit. Semua butir ditangani. Sebelum itu branch di-rebase ke `main` `ff74bf9` (PR #11/CR-016) tanpa konflik; migration di `ff74bf9` sama dengan `0f6268a`.

| # | Temuan | Perubahan | Bukti uji ulang (MySQL 8.0.30, DB scratch 6.1 putaran 4) |
|---|---|---|---|
| 1 | Minor: `@dbv011_trim` memuat byte NUL mentah (`CHAR(9, 10, 13, 11, 0)`). MariaDB 10.4 (PCRE1) memotong pola di NUL, sehingga A4 singkatan, A6, B9, dan D `kode_ss` diperkirakan gagal 1139, bertentangan dengan harapan Bagian 5 | Kelas karakter disusun dari **escape regex** dengan `CHAR(92)`: `@dbv011_kls` = spasi + `\t\n\r\x{0B}\x{00}`, lalu `@dbv011_trim` = `^[…]+\|[…]+$`. Pola tidak memuat byte NUL maupun karakter kontrol, dan teks kueri tetap tanpa backslash. Dipakai di skrip audit dan kueri tabrakan 2.4 langkah 6. Penjelasan di 2.2 #6; 2.1, 2.2 #2, kepala skrip, dan Bagian 5 (harapan tanpa 1139 + cek cepat pola) diperbarui | `LOCATE(CHAR(0), @dbv011_trim)` = 0 (pola lama: 8), tanpa karakter kontrol. Dibandingkan dengan `trim()` PHP 8.4 pada 73 string (61 string putaran 3 ditambah `{`, `}`, `x{0B}` literal, `\x{00}` literal, NUL tunggal/ganda, kode wilayah berspasi/ber-TAB) × 3 `sql_mode`, langsung maupun lewat tabel turunan + WHERE: 219/219 identik. Kueri tabrakan akun: 6 baris, identik dengan putaran 3; fallback beda skema identik, `akun_v2` 5/5 identik biner. MariaDB tetap belum diuji (tidak tersedia) |
| 2 | Minor: kode wilayah diperlakukan mentah di A1/PK/B2 tetapi hasil trim di B9/transformasi. Provinsi `' 11'` dan `'11'` dengan `Kab. Contoh` di bawah masing-masing tidak menghasilkan satu baris pun, padahal impor ber-trim gagal 1062 | **Satu aturan (2.2 #7): kode wilayah = hasil `trim()` PHP** di semua kueri dan di impor, sama dengan `MasterService::createLocked()` dan `MasterField::normalize()`. A1: lingkup induk hasil trim (`anggota` ditambah kode induk mentah). B1: sentinel dikenali dari kode hasil trim. B2: rantai wilayah dan `kantor` dibandingkan dengan kode hasil trim di kedua sisi (`utf8mb4_unicode_ci`; induk di-DISTINCT agar dimaterialisasi dengan index). B9 (1) baru: PK ganda setelah trim per tabel wilayah = WAJIB_0 (4 kueri). B9 (2): kode yang berubah oleh trim = PERIKSA, dengan kolom `nilai_impor`. Transformasi 2.4 langkah 5 dan Bagian 4 #2 diperbarui | Kasus reviewer: kini WAJIB_0 `kode wilayah ganda setelah trim (PK provinsi)` `11` dan WAJIB_0 `duplikat uq_kabupaten_kota_nama` lingkup `id_provinsi=11` (`1190:1:'11'` \| `1191:1:' 11'`); skrip putaran 3 pada data yang sama: 0 baris. Rujukan `' 1101'` (kecamatan), `'1101010<TAB>'` (kelurahan), `kantor` `' 11'`/`'1101 '`, dan sentinel `' 99'`: tidak lagi yatim palsu (skrip putaran 3: 3 baris WAJIB_0 palsu), melainkan 8 baris PERIKSA berubah oleh trim. Kabupaten `' 9999'` → PERIKSA sentinel (B1). Waktu jalan di DB berwilayah ukuran nyata `…dp` (85.682 kelurahan, 300 kantor): seluruh audit 13 detik (skrip putaran 3: 6 detik), kueri terlama 4,4 detik |
| 3 | Minor: status NULL tidak terlihat di profil C maupun B6 (`NOT IN`/`<>` mengabaikan NULL), padahal kolom `status` v2 NOT NULL | C: kolom baru `st_null` (hitung eksplisit `status IS NULL`); `st_lain` mencakup NULL; `non_10` menghitung NULL sebagai tampil; peringatan `PERIKSA status NULL (v2 NOT NULL)`, termasuk untuk `pengguna`. B6: `IFNULL(CAST(status AS CHAR), '') <> '10'` dan kolom `st_null` (juga di peta `pangkat`). Kolom `anggota` A dan B6: status NULL tampil `NULL`; sebelumnya anggota ber-status NULL hilang dari `GROUP_CONCAT`. Transformasi 2.4 langkah 5: status NULL dipetakan eksplisit | `jenis_libur` dengan 1 baris NULL → `PERIKSA profil`, `st_null` 1, `st_lain` 1 (skrip putaran 3: INFO, `st_lain` 0). Grup duplikat `Libur Status Null` → `anggota` `18:NULL:…` \| `19:1:…` (skrip putaran 3: hanya `19:1:…` padahal jumlah 2). `jenis_hukdis` tingkat 2 berisi 127 baris status 1 + 1 baris NULL → WAJIB_0 B6 jumlah 128, `st_null` 1 (skrip putaran 3: tidak muncul) |
| 4 | Nit: kesetiaan counter AUTO_INCREMENT salinan (syarat 6.5 #10) belum dicek; salinan data-saja membuat counter = `MAX(id)+1` dan skrip counter diam-diam menyalin terlalu sedikit | 2.4 langkah 1: kueri counter 23 tabel dijalankan di sumber (hanya baca) dan di salinan; hasilnya harus identik dan sama dengan `counter_ai` C. Tindakan bila berbeda (dump basi → dump ulang; cara restore → samakan counter salinan, atau tulis ALTER langkah 8 manual). 2.6 #10, 2.7, Bagian 4 #6, Bagian 5, dan kepala skrip counter merujuknya | Salinan `…dc` (`mysqldump` bawaan) vs sumber `…dl`: identik 23/23. Salinan `…dd` (data-saja ke tabel `CREATE TABLE … LIKE`): beda tepat di 4 tabel yang counter-nya melebihi `MAX(id)+1` (`bidang_kursem` 200 → 3, `diklat` 17 → 9, `jenis_kp` 128 → 9, `pengguna` 50 → 15). Skrip counter dengan `@legacy` = `…dd`: 0 error, tetapi mencetak `diklat AUTO_INCREMENT = 9` serta ALTER `bidang_kursem`/`jenis_kp` ke 3/9; dengan `…dl`: 17 dan PERIKSA counter ≥ 128. Inilah kesalahan diam-diam yang kini dicegah langkah 1 |
| 5 | Nit: catatan "di luar diff" tentang `01-Auth.md` | Catatan dihapus dari Bagian 7 dan PR. Status CR-018/CR-019 di `01-Auth.md` dibersihkan CR-022. Sekalian, dua baris basi `02-MasterData.md` yang diubah putaran 2 (6.3 butir 10) dikembalikan seperti `main`, karena CR-022 membersihkan baris yang sama; baris rujukan DBV-011 dipindah ke setelah baris "Tabel Tier 0/1 sisanya" | `git merge-tree` branch ini dengan branch CR-022 (`cr-022/sinkron-dokumen-keputusan`): bersih. Sebelum perubahan ini: konflik di `02-MasterData.md`. Sekalian dirapikan: pola grep di tabel 3.1 kini memakai `\|` agar sel tabel Markdown tidak terpecah |

**Hasil audit putaran 4** (`-vvv --table --force`, tiga `sql_mode` yang sama dengan putaran 2/3). Baris data identik di ketiga mode untuk semua DB.

| DB | Hasil |
|---|---|
| Berbentuk legacy `…dl` | rc 0, **0 error**, 112 result set (106 kueri tetap + 4 cetak + 2 EXECUTE). **85 WAJIB_0, 56 PERIKSA.** Pada data putaran 3 saja (sebelum kasus di atas ditambahkan): 81 / 44, sama dengan putaran 3; selisih teksnya hanya format (kode induk di `anggota` A1, `QUOTE` di B1/B2, kolom `st_null`/`nilai_impor`). Skrip putaran 3 pada data putaran 4: 85 / 45, dengan 3 yatim palsu dan tanpa 14 temuan butir 2–3 |
| Berbentuk v2 `…dv` (bersih, tanpa seed) | **0 baris WAJIB_0**. Error hanya 6 × 1054 dan 3 × 1146, sesuai Bagian 5. PERIKSA: 4 sentinel wilayah. `INFO D: ke-27 …` dan `INFO E: ke-29 …` tercetak. 103 result set (skrip putaran 3: 99; selisihnya 4 kueri PK ganda yang menghasilkan Empty set) |
| Mini `…dm` | 96 × 1146 dan 4 × 1054, sesuai rancangan (3 kueri PK ganda baru membaca tabel wilayah yang sengaja tidak ada). Daftar terlewat D/E tercetak |

| Uji | Hasil |
|---|---|
| Skrip counter (tidak berubah selain komentar) | `--init-command` + stdin dan `-e "SET @legacy …"` + `SOURCE` di baris sendiri: 0 error, 23 baris tabel, keluaran identik. Tanpa `@legacy`: `BERHENTI…` |
| Cek hanya-baca Bagian 5 (`grep -ciE …`) | 0 untuk kedua berkas; teks SQL di luar komentar tanpa backslash |
| Gate `fastcheck --only backend` (prefix `dbv11d`, setara `./check.sh backend`) | `SEMUA CHECK LOLOS`: PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 239 files that can be fixed`, PHPUnit `OK` di keempat shard (133 + 129 + 146 + 126 = 534 test, 17.177 assertion; jumlah test naik dari 526 karena test PR #11 di `ff74bf9`). Revisi ini hanya menyentuh dokumen dan skrip SQL |

### 6.6 Revisi putaran 5 (01-10-2026): perluasan setelah keputusan 01-10

Putaran ini bukan tindak lanjut pra-review. Isinya perluasan yang mengikuti keputusan user 01-10-2026 (1.4), dan **belum** melalui pra-review internal maupun review DB Validator.

**Sinkronisasi branch.** `origin/main` `cc553be` (CR-014, CR-025) di-merge ke branch ini, bukan di-rebase, supaya riwayat PR tidak ditulis ulang. Merge berjalan tanpa konflik. Kedua CR itu tidak menyentuh migration maupun fungsi normalisasi yang dirujuk (Rujukan).

| Butir 1.4 | Perubahan |
|---|---|
| a | 1.4; 2.4 langkah 6 (`SET SESSION sql_mode`/`time_zone` + kueri bukti); 3.4 (posisi menjadi permintaan utama); 3.5 opsi A/C; 3.6 #2, #4, #5; Bagian 4 #9, #12. A-01 tidak diubah (koreksi lewat DBV-017) |
| b | 2.4 langkah 1 (engine mengikuti AS-02); kepala skrip audit; 3.6 #1; Bagian 4 #13 |
| c | 2.4 langkah 1 (pembersihan salinan + kueri pencetak); skrip bagian 0 (2 kueri WAJIB_0); 2.3; Bagian 4 #14; Bagian 5 |
| d | Skrip A6 (3 kueri baru: profil format hash, daftar akun hash bukan 32 heks, akun tanpa email); 2.3; 2.4 langkah 5; 2.5 kasus khusus; Bagian 4 #15 |
| e | Status; 1.4; 2.4 langkah 1 dan 10; Bagian 4 #17; Bagian 7 |
| f | 2.9 (18 butir); 2.2 #1; 3.1; komentar skrip A6, B5, B8, B9, C (`pengguna`), dan D; Bagian 4 #16 |

**Lingkungan uji** (MySQL 8.0.30 lokal; dibuat lalu di-DROP dengan nama persis):
- **`simpeg_v2_s_dbv011el`**: struktur dump struktur prod 01-10 (285 tabel; trigger dan function tidak ikut, tanpa data), ditambah 12 akun sintetis di `pengguna`, 1 baris `d_user`, dan 2 trigger uji (`pengguna`, `pangkat`). Semua nilai fiktif.
- **`simpeg_v2_s_dbv011ev`**: salinan struktur (`CREATE TABLE … LIKE`, tanpa awalan `t_`, tanpa data) dari DB shard gate `simpeg_v2_t_dbv011c_s0`, yang dimigrasi test PHPUnit dari branch ini. Dipakai untuk menguji skrip pada skema v2; kedua DB, beserta shard lain, di-DROP setelahnya.

| Uji | Hasil |
|---|---|
| Audit sebelum pembersihan | rc 0, 0 error. Bagian 0: 7 baris `WAJIB_0 kolom password_decode` (`pengguna` dan 6 tabel arsip/cadangan akun) dan 2 baris `WAJIB_0 trigger` |
| Kueri pencetak pembersihan (2.4 langkah 1), di `sql_mode` global lokal dan di ANSI_QUOTES + NO_BACKSLASH_ESCAPES + PIPES_AS_CONCAT | Keluaran identik: 1 baris komentar skema/versi, 2 `DROP TRIGGER`, 7 `ALTER TABLE … DROP COLUMN`. Setelah dijalankan: 0 kolom `password_decode`, 0 trigger. Counter AUTO_INCREMENT `pengguna`, `pangkat`, `agama`, dan `bidang_pendidikan` sama sebelum dan sesudah (dibaca dengan `information_schema_stats_expiry = 0`) |
| Audit sesudah pembersihan, tiga `sql_mode` yang sama dengan putaran 2–4 | rc 0, **0 error**, 117 result set (111 kueri tetap + 4 cetak + 2 EXECUTE). Baris data identik di ketiga mode. Bagian 0 kosong. `INFO D: ke-27 entri katalog diperiksa` dan `INFO E: ke-29 tabel daftar ada di salinan`, jadi semua tabel dan kolom yang dirujuk skrip ada di struktur produksi |
| Format hash (12 akun) | INFO `md5 (32 heks)` = 5, termasuk heks huruf besar. PERIKSA: `bcrypt`, `argon2`, `kosong atau spasi saja`, `heks 40 karakter`, `lain, 32 karakter` (32 karakter non-heks), dan `lain, 33 karakter` × 2 (md5 + spasi, md5 + baris baru). Daftar akun memuat 7 akun itu dengan panjang hash, tanpa nilai hash. Versi awal kelas "heks N" memakai `REGEXP '^[0-9a-fA-F]+$'`; `$` ICU juga cocok sebelum baris baru di akhir, sehingga md5 + baris baru terbaca "heks 33". Kini dihitung dengan `CHAR_LENGTH(REGEXP_REPLACE(…, '[0-9a-fA-F]', '')) = 0` |
| Akun tanpa email | Role 2 status 1: 3 (email `''`, NULL, NULL). Role 3 status NULL: 1 (email berisi TAB saja). Akun dengan email berspasi di tepi tidak ikut |
| Duplikat `username` (mungkin di produksi karena tanpa UNIQUE, 2.9 #1) | `admin` vs `Admin ` → WAJIB_0. Putaran 1–4 tidak bisa menanam kasus ini karena DB uji memakai rekonstruksi lokal ber-UNIQUE |
| Panjang akun | `username` 101 dan `name` 151 karakter → WAJIB_0 A6 |
| Profil C `pengguna` | `st_1` 9, `st_2` 1, `st_null` 1, `st_lain` 2 (NULL dan status 3), `PERIKSA status NULL` |
| Pengaturan sesi impor (2.4 langkah 6) | Tanpa peringatan; `@@SESSION.sql_mode` sesuai, `time_zone` `+00:00`, `utc_ok` = 1; `@@GLOBAL.time_zone` tetap `SYSTEM` |
| Skrip audit pada skema v2 | Tabel shard gate memakai awalan `t_`, jadi strukturnya disalin dengan `CREATE TABLE … LIKE` ke **`simpeg_v2_s_dbv011ev`** tanpa awalan dan tanpa data. Hasil: 106 result set; error hanya 8 × 1054 (5 kueri akun A6 lama, 2 kueri A6 putaran 5, profil `pengguna`) dan 3 × 1146 (`absen_ijin`/`d_konket`), sesuai harapan Bagian 5 yang diperbarui. Bagian 0 kosong; profil format hash berjalan tanpa error (0 baris karena tanpa akun). 0 baris WAJIB_0; `INFO D: ke-27 …` dan `INFO E: ke-29 …` tercetak |
| Cek hanya-baca Bagian 5 (`grep -ciE …`) | 0 untuk kedua berkas; teks SQL di luar komentar tanpa backslash |
| Gate `fastcheck --only backend` (prefix `dbv011c`; worktree tanpa `backend/.env`, sesuai aturan kerja) | PHPStan `[OK] No errors`; PHP-CS-Fixer `Found 0 of 265 files that can be fixed`. PHPUnit **gagal**: 76 test (Auth/token/reset, mis. login 422), **identik** dengan gate branch dokumen lain yang juga berbasis `cc553be` dan juga dijalankan tanpa `.env`. Kegagalan ini berasal dari lingkungan (konfigurasi test yang biasanya diisi `.env`), bukan dari putaran ini: PR ini hanya menyentuh dokumen dan skrip SQL, yang tidak dimuat PHPUnit. Gate penuh perlu diulang di lingkungan dengan konfigurasi test lengkap sebelum merge |

## 7. Efek ke dokumen lain & di luar cakupan

**Diubah di PR ini:**
- `02-MasterData.md` G-01 "Belum": satu baris rujukan ke dokumen ini (setelah baris "Tabel Tier 0/1 sisanya"). Dua baris basi di atasnya (DBV-002 ⏳, strict mode) dibersihkan CR-022; sejak putaran 4 PR ini tidak lagi mengubahnya, supaya kedua PR tidak bentrok.
- `A-01-auth-schema.md` Bagian 8 #7 dan 9.7 D-11: satu kalimat rujukan ke Bagian 3.
- `G-06-diklat-hukdis-konket-tanda-jasa-schema.md` 6.5 (di bawah syarat approval DBV-005) dan `G-01-master-schema.md` (di bawah tabel 8.4): satu kalimat "Langkah runbook: DBV-011" (putaran 3, nit pra-review internal putaran 2). Isi keputusan yang sudah disetujui tidak diubah.
- `01-Auth.md` butir D-11: rujukan ke 3.4–3.6.
- Putaran 5 hanya mengubah dokumen ini dan skrip audit. A-01 **tidak** diubah lagi: revisi permintaan A-01 9.8 langkah 2 dicatat di 1.4 butir a, dan koreksi teksnya lewat DBV-017.

**Tidak diubah:** isi G-01/G-04/G-06/G-07/G-10 selain kalimat rujukan di atas. Butir audit impor masing-masing tetap berlaku; dokumen ini hanya menggabungkannya menjadi satu runbook.

**Di luar PR ini (tindak lanjut putaran 5):**
- DBV-017: koreksi teks dokumen DBV yang sudah disetujui, yaitu A-01 9.8 langkah 2 (1.4 butir a), A-01 9.1 dan D-5 (rujukan [K] `simpeg_prod_duplikat`), serta label [I] yang kini [K] (2.9).
- Migration ALTER baru + DBV untuk selisih lebar kolom di 2.9 #1, #2, dan #10, bila audit data membutuhkannya.
- DBV-014: runbook impor Tier 2–9 dan `pengguna` lengkap (status, `is_admin`, `id_kode_unit_instansi`), serta rekonsiliasi jumlah baris.

**Setelah approval:**
- Kartu ISSUE-009: runbook tercatat, eksekusi menunggu salinan data produksi (dump final saat freeze untuk impor final).
- Kartu ISSUE-022 dan D-11: menunggu keputusan TL/DBA atas 3.5/3.6, setelah kartu ISSUE-024 terjawab.
- Bila opsi A dipilih, implementasinya menjadi unit DBV+CR baru.

**Di luar cakupan (temuan terkait, bukan DBV):** kolom "Login terakhir" di Manajemen Akun tampil 7 jam lebih awal, karena string UTC dari API di-parse sebagai jam lokal. Sudah diperbaiki CR-023 (di `main` sejak `228c1f6`).
