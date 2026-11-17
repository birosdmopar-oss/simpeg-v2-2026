# DB Validator Review — DBV-028 skema helpdesk Halo Simpeg (Fase 8 E-01)

**Key review:** `DBV-028`. Judul PR: `[CR][DBV] DBV-028 skema helpdesk Halo Simpeg`.

**Branch:** `fase8/dbv-028-skema-helpdesk` (dari `main` `ef83356e`).

**Alur `[CR]+[DBV]`:**
1. Reviewer CR meneruskan PR ke DB Validator (WS-2).
2. DB Validator me-review dan memberi approve.
3. Reviewer CR me-merge dengan izin Horii.

**Status:** ◻ menunggu review DB Validator.

**Penulis:** Handling Case (pelaksana Jalur BE helpdesk A1, izin `[#9-WorkloadHaloSimpeg]`), 16-11-2026.

**Asal kebutuhan:**
- PRD Fase 8 v1.3 (`docs/fase8/PRD_Fase8_HaloSimpeg.md`, CR-136): §7.1 (termasuk "Koreksi indeks (v1.3)"), §6.1b, F8-03, F8-06, F8-09, F8-21 (H8-6), AC-E01.1..4, AC-E04.11,
  AC-E05.7/8.
- `docs/fase8/8_1.md` A1.
- Laporan A0 Handling Case §R/§R2 (16-11-2026, di luar repo, disetujui reviewer). Butir yang dipakai: C3 indeks, B6/B7/C4 pra-cek,
  keputusan Horii H8-16 (a1) dan Q-A0-6 (a').

**Bahan:**
- Kode legacy CI3 HEAD `0cf7e67`, dibaca hanya di `application/{libraries,controllers,views}`:
  - `LS` = `libraries/hr/L_support.php`;
  - `SC` = `controllers/hr/Support.php`;
  - `VI`/`VD` = `views/hr/support/{inbox,detail}.php`.
- DDL legacy ketiga tabel **tidak tersedia** (tidak ada D1 untuk tabel ini; Q-A0-3 tidak menahan), sehingga kolom berlabel [K] dari
  pemakaian kode dan tipe/panjang berlabel [I].

**Label sumber:**
- **[K]**: ikut kode legacy (`berkas:baris`).
- **[V2]**: keputusan v2 (konvensi, keamanan, integritas).
- **[I]**: inferensi pelaksana, perlu keputusan DB Validator.

---

## 0. Ringkasan

**Dua migration baru:**

| Migration | Isi |
|---|---|
| `2026-11-16-100000_CreateHaloSimpegFase8` | CREATE `help_desk_contact`, `support_officer`, `officer_category` |
| `2026-11-16-100100_AlterDisiplinTicketIndeksFase8` | ADD INDEX aditif pada `disiplin_ticket` dan `disiplin_ticket_msg` |

**Tidak dibuat ulang dan tidak diubah:**
- `question_category` (`2026-10-26-100000_CreateMasterLayananFase4.php:145-155`);
- `disiplin_ticket`, `disiplin_ticket_msg` (`2026-10-26-100500_CreateDisiplinTicketFase4.php:76-125`), DBV-022.

Migration lama tidak diedit. Tidak ada tabel notifikasi atau antrean baru (PRD §7.1: digantikan `integrasi_log` dan queue Fase 6).

**Perbedaan utama terhadap legacy:**
- `status` master TINYINT 0/1 + CHECK (legacy varchar `'1'`/`'0'`);
- `support_officer.id_user` INT UNSIGNED NOT NULL + UNIQUE + FK RESTRICT → `pengguna.id_pengguna`;
- `officer_category` UNIQUE pasangan + FK RESTRICT (legacy CASCADE);
- `help_desk_contact.contact` UNIQUE;
- waktu UTC diisi aplikasi tanpa DEFAULT DB.

## 1. Konvensi

| Butir | Aturan |
|---|---|
| Engine/collation | InnoDB, `utf8mb4` / `utf8mb4_unicode_ci` (AGENTS.md §2, `CollationInvariantTest`) |
| Status master | **0 Tidak Aktif / 1 Aktif** + CHECK `IN (0, 1)`, DEFAULT 1. Sama dengan `question_category` (DBV-022 DQ-5, F8-03). Tidak ada status 10: hapus master = hapus fisik [K `LS:136`, `:363-365`, `:709-711`], dicegah FK RESTRICT bila masih dipakai (B-12) dan dijaga service (422) |
| FK | RESTRICT/RESTRICT (kebijakan V1). `officer_category` dihapus lebih dulu oleh service saat set kategori diganti atau petugas dihapus [K `LS:627-629`] |
| Waktu | UTC, diisi aplikasi (`StempelUtc`, BP8-8); kolom baru tanpa `DEFAULT CURRENT_TIMESTAMP`, NULL-able agar impor T12b lurus bila stempel legacy kosong [I] |
| Audit | tulis lewat `BaseAuditableModel` → `audit_logs` (BP8-9); tanpa kolom `created_by`/`updated_by` |
| Nama konstrain | `fk_<kolom>_<singkatan>_to_<tabel>`, `chk_<tabel>_<kolom>`, `uk_<tabel>_<kolom>` |
| Rollback `up()` | gagal → hanya tabel/indeks yang dibuat pada run itu yang di-DROP (pola `CreateChatbotFase8`); tabel bernama sama yang sudah ada (1050) tidak disentuh |

## 2. Kolom per tabel (legacy → v2)

### 2.1 `help_desk_contact` (E-07)

| Kolom v2 | Tipe | Label | Sumber / alasan |
|---|---|---|---|
| `id_help_desk_contact` | INT NOT NULL AI, PK | [K] | `LS:25`, `:98` |
| `name` | VARCHAR(150) NOT NULL | [K] nama + [I] panjang | validasi wajib `LS:156-200` |
| `contact` | VARCHAR(20) NOT NULL, **UNIQUE** | [K] + [V2] UNIQUE + [I] panjang | unik di aplikasi `LS:176-197` (B-16) |
| `email` | VARCHAR(150) NULL | [K] opsional + [I] panjang | form `views/hr/support/help_desk/form.php:52-56`, tanpa validasi |
| `status` | TINYINT NOT NULL DEFAULT 1, CHECK (0,1) | [K] domain + [V2] tipe | form status legacy |
| `created_at` | DATETIME NULL | [K] | urut daftar `LS:16` |
| `updated_at` | DATETIME NULL | [I] | stempel ubah |

### 2.2 `support_officer` (E-02)

| Kolom v2 | Tipe | Label | Sumber / alasan |
|---|---|---|---|
| `id_support_officer` | INT NOT NULL AI, PK | [K] | `LS:500`, `:709` |
| `id_user` | **INT UNSIGNED** NOT NULL, **UNIQUE**, FK RESTRICT → `pengguna.id_pengguna` | [K] nama + [V2] FK/UNIQUE | join `pengguna.id = support_officer.id_user` `LS:440`; unik di aplikasi `LS:765-788`. Tipe wajib sama dengan `pengguna.id_pengguna` (`2026-09-17-000001_CreatePengguna.php:24`); preseden `news_flag.id_user` (DBV-032). Nama `id_user` dipertahankan agar impor T12b menyalin apa adanya (H8-6, K-M11); id tak dikenal dibuang + dicatat (K-M12, MAKE-097) |
| `email` | VARCHAR(150) NOT NULL | [K] wajib + [I] panjang | `LS:756` |
| `no_hp` | VARCHAR(20) NULL | [K] opsional + [I] panjang | `LS:798` (kosong → NULL) |
| `status` | TINYINT NOT NULL DEFAULT 1, CHECK (0,1) | [K] domain + [V2] tipe | legacy varchar `'1'`/`'0'` (`LS:1035`) |
| `created_at` | DATETIME NULL | [K] | urut daftar `LS:441` |
| `updated_at` | DATETIME NULL | [V2] | audit |

Kolom `support_officer.nip` dan `id_category` di Bahan Baku E-01 **tidak** dipakai (PRD §7.1, Q8-13: menyimpang dari legacy dan
DQ-4).

### 2.3 `officer_category` (E-02)

| Kolom v2 | Tipe | Label | Sumber / alasan |
|---|---|---|---|
| `id` | INT NOT NULL AI, PK | [K] | `LS:803-812` |
| `id_support_officer` | INT NOT NULL, FK RESTRICT → `support_officer` | [K] + [V2] RESTRICT | `LS:807` |
| `id_question_category` | INT NOT NULL, FK RESTRICT → `question_category` | [K] + [V2] RESTRICT | `LS:808`; tipe = `question_category.id_question_category` INT |
| `created_at` | DATETIME NULL | [K] | urut `LS:221` |

Indeks:
- UNIQUE `uk_officer_category (id_support_officer, id_question_category)` [V2]. Indeks ini juga melayani FK petugas.
- KEY `id_question_category`, untuk FK kategori dan pencarian "petugas aktif kategori X" (push, akses).

## 3. Skema v2 (= DDL final)

DDL lengkap ada di migration `2026-11-16-100000_CreateHaloSimpegFase8::ddl()`. FK:

| FK | Dari | Ke |
|---|---|---|
| `fk_id_user_so_to_pengguna` | `support_officer.id_user` | `pengguna.id_pengguna` |
| `fk_id_so_oc_to_support_officer` | `officer_category.id_support_officer` | `support_officer.id_support_officer` |
| `fk_id_qc_oc_to_question_category` | `officer_category.id_question_category` | `question_category.id_question_category` |

CHECK: `chk_help_desk_contact_status`, `chk_support_officer_status`.

## 4. Indeks aditif `disiplin_ticket(_msg)` (migration `…100100`)

| Indeks | Kolom | Melayani |
|---|---|---|
| `idx_dticket_kategori_status_baca` | `disiplin_ticket (id_question_category, status, is_read_off)` | antrean petugas per kategori + filter status; `perlu_tindak` (`status = 0`); ringkasan sisi petugas (`is_read_off = 2`, dengan `status IN (0, 1)` eksplisit) |
| `idx_dticket_sender_status` | `disiplin_ticket (sender, status)` | "tiket saya" + filter status; ringkasan sisi pemilik (`is_read = 2`). PK ikut di indeks sekunder InnoDB, jadi urut `id_inbox` tidak perlu kolom ketiga |
| `idx_dticketmsg_inbox_waktu` | `disiplin_ticket_msg (id_inbox, created_at)` | thread urut waktu + pesan terakhir per tiket `ORDER BY created_at DESC, id_inbox_msg DESC LIMIT 1` (tanpa N+1, B-10) |

`(sender, updated_at)` dari usulan awal PRD §7.1 **tidak** dipakai (gugur di PRD v1.3 §7.1). `updated_at` ber-`ON UPDATE CURRENT_TIMESTAMP` ikut berubah saat
tiket dibaca atau ditutup, sehingga tidak mewakili "pesan terakhir" (review A0 C3).

### 4.1 EXPLAIN dua bentuk indeks kategori

**Data uji.** DB scratch MySQL 8.0.30 port 3307, data sintetis sementara:
- 40 kategori;
- 40.000 tiket: 10% Open, `is_read=2` 1/7, `is_read_off=2` 1/9;
- 80.000 pesan.

Filter = 3 kategori (±3.000 tiket). Data dihapus sesudah pengukuran.

| Kueri | Bentuk A `(kategori, is_read_off, status)` | Bentuk B `(kategori, status, is_read_off)` — **dipilih** |
|---|---|---|
| R1 ringkasan petugas tanpa syarat status | key_len 6, ±333 baris | key_len 5, ±3.000 baris |
| R2 ringkasan petugas + `status IN (0, 1)` eksplisit | key_len 10, ±336 baris | key_len 10, ±336 baris |
| R3 `perlu_tindak` (`status = 0` AND (`is_read_off = 2` OR pengingat jatuh tempo)) | key_len 5, ±3.000 baris | key_len 9, ±1.002 baris |
| R4 antrean petugas `status = 0` | key_len 5, ±3.000 baris | key_len 9, ±1.002 baris, *covering* (Using index) |

Bentuk lain diukur dengan `FORCE INDEX`.

Bentuk B dipilih karena:
- melayani keempat kueri, asalkan kueri ringkasan sisi petugas **selalu** menulis `status IN (0, 1)`;
- syarat itu dibekukan di README MAKE-071 dan diuji dengan EXPLAIN di test MAKE-071;
- bentuk A tidak bisa memakai `status` untuk antrean dan `perlu_tindak`.

Pesan terakhir (subkueri bergantung) memakai `idx_dticketmsg_inbox_waktu` (`ref`, ±2 baris per tiket, Using index). Tiket saya
(R5 `sender = ? AND status = ?`) memakai `idx_dticket_sender_status` (`ref const,const`, covering).

### 4.2 KEY lama yang menjadi redundan (diputus DB Validator, Q4)

| KEY DBV-022 | Redundan terhadap | Usulan |
|---|---|---|
| `fk_id_question_category_i_qc (id_question_category)` | `idx_dticket_kategori_status_baca` (berawalan sama; FK boleh memakai indeks kiri komposit) | dibuang di migration ALTER **terpisah** sesudah DBV setuju |
| `fk_id_inbox_im_qc (id_inbox)` | `idx_dticketmsg_inbox_waktu` | sama |

Di migration ini keduanya **tidak** dibuang. Pada EXPLAIN R3, pengoptimal sempat memilih KEY lama, sehingga keduanya ikut menambah
biaya tulis dan membingungkan rencana kueri. Pembuangan cukup satu `ALTER … DROP INDEX` dan tidak mengubah FK.

## 5. Pemakaian aplikasi (MAKE-070/071, sesudah DBV-028 di `main`)

- Model beraudit di `Models/HaloSimpeg/`: `{QuestionCategory,SupportOfficer,OfficerCategory,HelpDeskContact,DisiplinTicket,
  DisiplinTicketMsg}Model`.
- Petugas aktif = `support_officer.status = 1` **dan** akun `pengguna` aktif (F8-06; push [K `LS:1035`]).
- Ganti set kategori = hapus `officer_category` milik petugas + sisip baru dalam satu transaksi.
- Hapus tiket:
  1. `disiplin_ticket_msg` dihapus lebih dulu (FK RESTRICT; legacy CASCADE), lalu `disiplin_ticket`, dalam satu transaksi;
  2. berkas lampiran lewat `PenjagaBerkas::catat` + `selesaikan()` (Q-A0-6 (a')).
- FK `disiplin_ticket.nip` → `pegawai.nip` (`MigrationsDitahan/AddFkNipLayananFase4.php`) **tetap ditahan**. Audit yatim baru
  bermakna sesudah impor F-02 (`AturanTiketLama::INFO_NIP`), jadi validasi dilakukan di aplikasi dan ditinjau ulang saat cutover
  (Q5).

## 6. Pra-cek dan kueri audit baca-saja

Berkas `backend/docs/db-review/sql/DBV-028-audit-halo-simpeg.sql` berisi 17 kueri, semuanya agregat COUNT tanpa isi baris. Hanya
**ditulis**; tidak dijalankan di salinan dev oleh pelaksana (aturan review A0). Berkas yang sama berjalan di skema v2
(`SkemaHaloSimpegTest::testKueriAuditBerjalan`).

**A. Master** — nama sama di legacy dan v2. Dijalankan di salinan legacy sebelum impor T12b dan di v2 sesudahnya:

| Kode | Kelas | Butir |
|---|---|---|
| Q-H1 | WAJIB_0 | nomor kontak ganda (UNIQUE) |
| Q-H2 | WAJIB_0 | `support_officer.id_user` ganda (UNIQUE) |
| Q-H3 | WAJIB_0 | pasangan petugas-kategori ganda (UNIQUE) |
| Q-H4 | WAJIB_0 | `officer_category` yatim ke `support_officer` |
| Q-H5 | INFO | `officer_category` yatim ke `question_category` (B-12), dibuang impor |
| Q-H6 | INFO | `support_officer.status` di luar 0/1 |
| Q-H7 | INFO | `help_desk_contact.status` di luar 0/1 |
| Q-H8 | INFO | nama kategori kembar (Q3) |
| Q-H9 | INFO | panjang melebihi kolom v2 [I] (Q2) |
| Q-H10 | INFO | email petugas kosong |

**B. Tiket v2 pasca impor** — F-02 + T12b, gladi cutover, bahan runbook MAKE-074:

| Kode | Kelas | Butir |
|---|---|---|
| Q-H11 | INFO | tiket kategori NULL (H8-16 (a1)) |
| Q-H12 | INFO | irisan NULL + Open + pengirim yatim (review A0 B6) |
| Q-H13 | INFO | lampiran ber-path di luar aturan tulis ketat adapter, `NOT REGEXP '^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$'` (B7; berkas ini tidak dihapus fisik saat hapus tiket) |
| Q-H14 | INFO | pembanding Q-H13: semua lampiran tidak kosong |
| Q-H15 | INFO | tiket Open di kategori tanpa petugas aktif (C4) |
| Q-H16 | INFO | tiket tanpa pesan (ditampilkan di akhir daftar, B3) |

**C. Legacy saja** — memakai kolom legacy `pengguna.id` (`LS:440`), dijalankan hanya di salinan legacy sebelum impor T12b; di v2
butir ini dijamin FK `fk_id_user_so_to_pengguna`, dan baris yang dibuang impor dihitung di laporan impor (K-M12, MAKE-097):

| Kode | Kelas | Butir |
|---|---|---|
| Q-H17 | INFO | `support_officer.id_user` yang tidak ada di `pengguna` (pra-cek PRD v1.3 §7.1) |

Catatan: baris `disiplin_ticket_msg.attachments` di `ManifestLampiran::KOLOM` (agar lampiran lama ikut disalin) adalah bagian
**MAKE-097**, bukan DBV-028.

## 7. Verifikasi developer

### 7.1 Hasil (16-11-2026)

Dijalankan di DB scratch `simpeg_v2_t_f8be`, MySQL 8.0.30 port 3307, prefix test `t_`:
- `vendor/bin/phpunit tests/database/HaloSimpeg/SkemaHaloSimpegTest.php` → OK (8 test).
- Gate penuh `./check.sh`: hasil di laporan PR.
- MariaDB 10.4 lewat matriks CI.

### 7.2 Isi test skema (`backend/tests/database/HaloSimpeg/SkemaHaloSimpegTest.php`)

| Test | Menegaskan |
|---|---|
| `testStrukturDanCollation` | InnoDB + `utf8mb4_unicode_ci` per tabel/kolom; tipe dan NULL tiap kolom; `id_user` INT UNSIGNED = `pengguna.id_pengguna` |
| `testIndeksFkDanCheck` | indeks penuh tiga tabel (PK, KEY, UNIQUE); 3 FK RESTRICT/RESTRICT; 2 CHECK |
| `testIndeksAditifDisiplinTicket` | tiga indeks aditif ada dengan urutan kolom tepat; KEY DBV-022 tetap |
| `testDefault` | status DEFAULT 1; auto_increment; waktu tanpa DEFAULT (BP8-8) |
| `testUniqueFkDanCheckDitegakkan` | 1062 akun/pasangan/kontak ganda; 1452 akun/petugas/kategori tak ada; 1451 hapus kategori/petugas/akun yang dipakai; CHECK status 2; satu petugas ≥ 2 kategori dan satu kategori ≥ 2 petugas (AC-E02.2); email kontak opsional |
| `testUpGagalTidakMenyentuhTabelYangSudahAda` | `help_desk_contact` sudah ada berisi baris → `up()` gagal (1050), tabel + baris utuh, dua tabel lain tidak tertinggal |
| `testDownLaluUpKembali` | `down()` men-DROP tiga tabel dan tiga indeks aditif; `question_category`, `disiplin_ticket(_msg)`, `pengguna`, dan KEY DBV-022 tetap; `up()` memasang ulang |
| `testKueriAuditBerjalan` | 16 kueri audit bagian A/B berjalan di skema v2; butir WAJIB_0 = 0; bagian C (Q-H17) ada dan tidak dijalankan |

`SkemaInboxTicketTest` dan `SkemaLayananTest` (Fase 4) tetap hijau (AC-E01.4), karena tidak ada kolom atau FK DBV-022 yang berubah.

## 8. Keputusan yang diminta dari DB Validator

| No | Pertanyaan | Usulan | Alternatif |
|---|---|---|---|
| DBV-028-Q1 | Tipe PK tiga tabel | INT signed AI, sama dengan tabel helpdesk DBV-022 (`question_category`, `disiplin_ticket`) [I] | INT UNSIGNED |
| DBV-028-Q2 | Panjang kolom [I] (tanpa DDL legacy) | `name`/`email` 150, `contact`/`no_hp` 20; pra-cek Q-H9 sebelum impor | ikut DDL legacy bila D1 tiga tabel diambil (Q-A0-3) |
| DBV-028-Q3 | Unik nama `question_category` | tetap di aplikasi (trim, ci) seperti legacy `LS:385-424`; Q-H8 sebagai pra-cek | ALTER UNIQUE `question_category` sesudah Q-H8 = 0 |
| DBV-028-Q4 | KEY FK redundan `fk_id_question_category_i_qc`, `fk_id_inbox_im_qc` | dibuang di migration ALTER terpisah sesudah setuju | dipertahankan |
| DBV-028-Q5 | FK `disiplin_ticket.nip` → `pegawai.nip` (ditahan DBV-022) | tetap ditahan; validasi di aplikasi; ditinjau saat cutover | dipasang di DBV-028 |
| DBV-028-Q6 | Bentuk indeks kategori (§4.1) | B `(kategori, status, is_read_off)` + `status IN (0, 1)` eksplisit di kueri ringkasan | A `(kategori, is_read_off, status)` |
| DBV-028-Q7 | Waktu `created_at` master | NULL-able tanpa DEFAULT (diisi aplikasi; impor salin apa adanya) | NOT NULL (impor wajib mengisi) |

## 9. Syarat merge (⛔)

- DB Validator (WS-2) approve PR.
- CI Quality gate hijau (MySQL 8.0 + MariaDB 10.4).
- Gate penuh `./check.sh` lolos.
- Izin merge Horii; merge oleh reviewer CR.

## 10. Checklist DB Validator

- [ ] Kolom dan tipe cocok dengan pemakaian kode legacy, atau deviasinya berlabel [V2]/[I] beralasan (Bagian 2).
- [ ] Status master 0/1 + CHECK + DEFAULT 1.
- [ ] Collation `utf8mb4_unicode_ci` ketiga tabel.
- [ ] FK RESTRICT/RESTRICT; tipe `id_user` = `pengguna.id_pengguna`.
- [ ] UNIQUE `contact`, `id_user`, pasangan petugas-kategori.
- [ ] Indeks aditif + EXPLAIN (Bagian 4); KEY redundan (Q4).
- [ ] Migration lama tidak diedit; `question_category`/`disiplin_ticket(_msg)` tidak dibuat ulang.
- [ ] Kueri audit hanya SELECT, agregat, blok LARANGAN.
- [ ] Keputusan Q1–Q7.
