# DB Validator Review — DBV-028 skema helpdesk Halo Simpeg (Fase 8 E-01)

**Key review:** `DBV-028`. Judul PR: `[CR][DBV] DBV-028 skema helpdesk Halo Simpeg`.

**Branch:** `fase8/dbv-028-skema-helpdesk` (dari `main` `ef83356e`; sudah memuat `main` `46f4d669` = PRD v1.3, CR-136).

**Alur `[CR]+[DBV]`:**
1. Reviewer CR meneruskan PR ke DB Validator (WS-2).
2. DB Validator me-review dan memberi approve.
3. Reviewer CR me-merge dengan izin Horii.

**Status:** ✔ DBV SETUJU @`9189c696` (Q1–Q7) dan @`c147f24a` (selisih review CR + Q8–Q10), WS-2 17-11-2026 (Bagian 8, 10).
Menunggu izin merge Horii.

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
  pemakaian kode, sedangkan tipe, panjang, nama PK `officer_category`, dan aksi FK legacy berlabel [I].
- Aplikasi legacy **tidak pernah mengisi** `created_at` ketiga tabel (`LS:55`, `:205`, `:563`, `:794`, `:806-809`;
  `MY_Model.php:127-148`), jadi stempel lama kemungkinan berasal dari DEFAULT DB legacy [I].

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
- `status` master TINYINT 0/1 + CHECK (domain 0/1 [K]; tipe kolom legacy [I]);
- `support_officer.id_user` INT UNSIGNED NOT NULL + UNIQUE + FK RESTRICT → `pengguna.id_pengguna`;
- `officer_category` UNIQUE pasangan + FK RESTRICT (aksi FK legacy [I], Q9);
- `help_desk_contact.contact` UNIQUE;
- waktu UTC diisi aplikasi tanpa DEFAULT DB [V2] (legacy kemungkinan memakai DEFAULT DB [I]).

## 1. Konvensi

| Butir | Aturan |
|---|---|
| Engine/collation | InnoDB, `utf8mb4` / `utf8mb4_unicode_ci` (AGENTS.md §2, `CollationInvariantTest`) |
| Status master | **0 Tidak Aktif / 1 Aktif** + CHECK `IN (0, 1)`, DEFAULT 1. Domain/CHECK/DEFAULT sama dengan `question_category` (DBV-022 DQ-5, F8-03); di sana INT, di sini TINYINT. Tidak ada status 10: hapus master = hapus fisik [K `LS:136`, `:363-365`, `:709-711`], dicegah FK RESTRICT bila masih dipakai (B-12) dan dijaga service (422) |
| FK | RESTRICT/RESTRICT (kebijakan V1). `officer_category` dihapus lebih dulu oleh service saat set kategori diganti atau petugas dihapus [K `LS:627-629`] |
| Waktu | UTC, diisi aplikasi (`StempelUtc`, BP8-8); kolom baru tanpa `DEFAULT CURRENT_TIMESTAMP` [V2], NULL-able agar impor T12b lurus bila stempel legacy kosong (Q7). Stempel legacy kemungkinan dari DEFAULT DB legacy [I]; pra-cek Q-H18 |
| Klasifikasi T12b | `created_at`/`updated_at` ketiga tabel = **R1a** (DBV-029 §5); `waktuBisnis` T12b kosong (Kesiapan `:231`, DBV-029 `:165-166`) |
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
| `status` | TINYINT NOT NULL DEFAULT 1, CHECK (0,1) | [K] domain + [I] tipe legacy + [V2] TINYINT/CHECK | form status legacy |
| `created_at` | DATETIME NULL, tanpa DEFAULT | [K] kolom + [V2] tanpa DEFAULT + [I] sumber legacy | urut daftar `LS:16`; tidak diisi aplikasi legacy (`LS:55`, `:205`) |
| `updated_at` | DATETIME NULL | [V2] | stempel ubah |

### 2.2 `support_officer` (E-02)

| Kolom v2 | Tipe | Label | Sumber / alasan |
|---|---|---|---|
| `id_support_officer` | INT NOT NULL AI, PK | [K] | `LS:500`, `:709` |
| `id_user` | **INT UNSIGNED** NOT NULL, **UNIQUE**, FK RESTRICT → `pengguna.id_pengguna` | [K] nama + [V2] FK/UNIQUE | join `pengguna.id = support_officer.id_user` `LS:440`; unik di aplikasi `LS:765-788`. Tipe wajib sama dengan `pengguna.id_pengguna` (`2026-09-17-000001_CreatePengguna.php:24`); preseden `news_flag.id_user` (DBV-032). Nama `id_user` dipertahankan agar impor T12b menyalin apa adanya (H8-6, K-M11); id tak dikenal dibuang + dicatat (K-M12, MAKE-097) |
| `email` | VARCHAR(150) NOT NULL | [K] wajib + [I] panjang | `LS:756` |
| `no_hp` | VARCHAR(20) NULL | [K] opsional + [I] panjang | `LS:798` (kosong → NULL) |
| `status` | TINYINT NOT NULL DEFAULT 1, CHECK (0,1) | [K] domain + [I] tipe legacy + [V2] TINYINT/CHECK | domain dari form `officer/form.php:28-29`; nilai dikutip di query (`LS:1035`) tidak membuktikan varchar (kolom INT pun dikutip, `MY_Controller.php:277`) |
| `created_at` | DATETIME NULL, tanpa DEFAULT | [K] kolom + [V2] tanpa DEFAULT + [I] sumber legacy | urut daftar `LS:441`; tidak diisi aplikasi legacy (`LS:563`, `:794`) |
| `updated_at` | DATETIME NULL | [V2] | audit |

Kolom `support_officer.nip` dan `id_category` di Bahan Baku E-01 **tidak** dipakai (PRD §7.1, Q8-13: menyimpang dari legacy dan
DQ-4).

### 2.3 `officer_category` (E-02)

| Kolom v2 | Tipe | Label | Sumber / alasan |
|---|---|---|---|
| `id` | INT NOT NULL AI, PK | [I] nama PK (Q10) | `LS:803-812` tidak menyebut kolom PK; tabel saudara memakai pola `id_<tabel>` |
| `id_support_officer` | INT NOT NULL, FK RESTRICT → `support_officer` | [K] + [V2] RESTRICT + [I] aksi legacy (Q9) | `LS:807`; `delete_officer` `LS:711` dan `delete_category` `LS:365` tidak menyentuh `officer_category` |
| `id_question_category` | INT NOT NULL, FK RESTRICT → `question_category` | [K] + [V2] RESTRICT | `LS:808`; tipe = `question_category.id_question_category` INT |
| `created_at` | DATETIME NULL, tanpa DEFAULT | [K] kolom + [V2] tanpa DEFAULT + [I] sumber legacy | urut `LS:221`; tidak diisi aplikasi legacy (`LS:806-809`) |

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
| `fk_id_question_category_i_qc (id_question_category)` | `idx_dticket_kategori_status_baca` (berawalan sama; FK boleh memakai indeks kiri komposit) | **tidak dibuang di DBV-028** (Q4 SETUJU); boleh menyusul lewat migration ALTER terpisah dengan kartu DBV sendiri |
| `fk_id_inbox_im_qc (id_inbox)` | `idx_dticketmsg_inbox_waktu` | sama |

Keduanya menambah biaya tulis dan kadang dipilih pengoptimal (EXPLAIN R6d dan R6f memilih `fk_id_question_category_i_qc`).
Pembuangan nanti cukup satu `ALTER … DROP INDEX` dan tidak mengubah FK; tidak mendesak.

### 4.3 EXPLAIN daftar `GET tiket` (R6) dan opsi `last_msg_at` (Q8)

**Data uji** (DB scratch 3307, sementara, dibuang sesudah pengukuran): 40 kategori; 40.000 tiket (10% Open, 2% kategori NULL,
2.000 pengirim ±20 tiket + satu pengirim berat 500 tiket); 77.362 pesan (5% tiket tanpa pesan, waktu acak).

Sebaran dibangkitkan dengan modulo nomor baris `i` (kategori `i % 40 + 1`, Open bila `i % 10 = 0`, pengirim berat bila `i % 80 = 0`,
kategori NULL bila `i % 50 = 0`), sehingga sebarannya **berkorelasi**:
- semua 500 tiket pengirim berat Open (`i % 80 = 0` ⇒ `i % 10 = 0`), jadi R6c = R6b = 500;
- Open hanya jatuh di kategori 1, 11, 21, 31 dan keempatnya seluruhnya Open; kategori 1 berisi 800 tiket (1.000 − 200 yang
  jatuh ke kategori NULL), jadi R6e (kategori 1–3 + Open) = 800 dan R6g (kategori 1–10 + Closed) = 9.800 − 800 = 9.000.

Angka baris di tabel adalah hitungan nyata data itu, bukan perkiraan 10%.

**Kueri** (urutan PRD v1.3 §6.1b: `pesan_terakhir.waktu DESC` NULL terakhir, lalu `id_inbox DESC`; halaman 20):

```sql
SELECT t.id_inbox, lm.created_at AS waktu
FROM disiplin_ticket t
LEFT JOIN disiplin_ticket_msg lm ON lm.id_inbox_msg = (
  SELECT m.id_inbox_msg FROM disiplin_ticket_msg m WHERE m.id_inbox = t.id_inbox
  ORDER BY m.created_at DESC, m.id_inbox_msg DESC LIMIT 1)
WHERE <filter peran/status>
ORDER BY lm.created_at IS NULL, lm.created_at DESC, t.id_inbox DESC
LIMIT 20;
```

| Kueri | Baris cocok | Akses `t` | Waktu (`EXPLAIN ANALYZE`) |
|---|---|---|---|
| R6a `peran=saya` (pengirim biasa) | 20 | `ref` `idx_dticket_sender_status`, covering | 0,5 ms |
| R6b `peran=saya` (pengirim berat) | 500 | sama | 3,0 ms |
| R6c `peran=saya` + `status=open` (pengirim berat) | 500 | `ref const,const` | 2,7 ms |
| R6d `peran=petugas` 3 kategori | 2.800 | `range` `fk_id_question_category_i_qc` | 35 ms |
| R6e `peran=petugas` 3 kategori + `status=open` | 800 | `range` `idx_dticket_kategori_status_baca` key_len 9 | 4,4 ms |
| R6f `peran=petugas` 10 kategori | 9.800 | `range` `fk_id_question_category_i_qc` | 120 ms |
| R6g `peran=petugas` 10 kategori + `status=closed` | 9.000 | `range` `idx_dticket_kategori_status_baca` key_len 9 | 125 ms |

Subkueri pesan terakhir selalu `DEPENDENT SUBQUERY` `ref` `idx_dticketmsg_inbox_waktu` (Using index, 1–3 baris per tiket); biaya
tumbuh linear terhadap jumlah tiket yang cocok (±12 µs per tiket) karena seluruh tiket cocok harus dihitung pesan terakhirnya
sebelum diurutkan. `COUNT(*)` untuk paginasi memakai indeks yang sama tanpa subkueri.

**Pembanding `last_msg_at`** (kolom sementara di DB scratch, diisi `MAX(created_at)`, indeks `(id_question_category, last_msg_at)`
dan `(sender, last_msg_at)`): R6b 0,24 ms; R6d 1,5 ms; R6f 4,4 ms; R6g 13,6 ms (pengoptimal memilih scan penuh).

**Q8: `last_msg_at` TIDAK ditambahkan di DBV-028** (✔ SETUJU WS-2 dengan syarat, Bagian 8). Data sintetis R6f/R6g (satu petugas,
10 kategori, 9.000–9.800 tiket, 120–125 ms) **melewati kedua pemicu** di bawah; keputusan tetap "tidak sekarang" karena pemicu
dinilai pada volume nyata (alasan WS-2 di Bagian 8). Alasan pelaksana:
- daftar pemilik dan petugas dengan ≤ ±3.000 tiket ≤ 35 ms; yang lebih berat adalah petugas dengan banyak kategori, dengan atau
  tanpa filter status (R6g `status=closed` 125 ms);
- kolom turunan menambah kewajiban tulis di setiap sisip pesan (konsistensi transaksi), pengisian ulang saat impor T12b, dan dua
  indeks baru;
- volume nyata belum diketahui (DDL/D1 tiket legacy tidak diambil untuk DBV-028).

Pemicu terukur: Q-H19 (berkas audit v2) menghitung tiket terbanyak yang terlihat satu petugas aktif (petugas status 1 dan akun
aktif, batas atas karena tiket milik petugas sendiri tidak dikecualikan) sesudah gladi impor. Bila
Q-H19 > 5.000 **atau** test/EXPLAIN MAKE-071 pada data gladi menunjukkan daftar petugas > 100 ms, `last_msg_at` diajukan lewat
kartu DBV terpisah (ALTER aditif + indeks + isi ulang), tanpa mengubah kontrak API.

## 5. Pemakaian aplikasi (MAKE-070/071, sesudah DBV-028 di `main`)

- Model beraudit di `Models/HaloSimpeg/`: `{QuestionCategory,SupportOfficer,OfficerCategory,HelpDeskContact,DisiplinTicket,
  DisiplinTicketMsg}Model`.
- Petugas aktif = `support_officer.status = 1` **dan** akun `pengguna` aktif (`pengguna.status = '1'`, `deleted_at IS NULL`;
  F8-06/F8-13; push [K `LS:1035`]). Definisi yang sama dipakai Q-H15 dan Q-H19.
- Ganti set kategori = hapus `officer_category` milik petugas + sisip baru dalam satu transaksi.
- Hapus tiket:
  1. `disiplin_ticket_msg` dihapus lebih dulu (FK RESTRICT; legacy CASCADE), lalu `disiplin_ticket`, dalam satu transaksi;
  2. berkas lampiran lewat `PenjagaBerkas::catat` + `selesaikan()` (Q-A0-6 (a')).
- FK `disiplin_ticket.nip` → `pegawai.nip` (`MigrationsDitahan/AddFkNipLayananFase4.php`) **tetap ditahan**. Audit yatim baru
  bermakna sesudah impor F-02 (`AturanTiketLama::INFO_NIP`), jadi validasi dilakukan di aplikasi dan ditinjau ulang saat cutover
  (Q5).

## 6. Pra-cek dan kueri audit baca-saja

Dua berkas, semuanya agregat COUNT tanpa isi baris. Hanya **ditulis**; tidak dijalankan di salinan dev oleh pelaksana (aturan
review A0). Dijalankan **tanpa `--force`**: tiap berkas hanya berisi kueri yang valid di skemanya.

| Berkas | Tempat menjalankan | Isi |
|---|---|---|
| `backend/docs/db-review/sql/DBV-028-audit-legacy.sql` | salinan legacy, sebelum impor T12b | A + C (14 kueri) |
| `backend/docs/db-review/sql/DBV-028-audit-v2.sql` | DB v2 sesudah impor F-02 + T12b (gladi) | A + B (20 kueri) |

Blok A ditulis identik di kedua berkas (diapit penanda `>>> A MULAI` / `<<< A SELESAI`). `SkemaHaloSimpegTest::testKueriAuditBerjalan`
menegaskan blok A sama persis, bagian C hanya di berkas legacy, dan menjalankan seluruh berkas v2 di skema v2 dengan data uji
(Q-H13, Q-H15, Q-H19).

**A. Master** — nama sama di legacy dan v2:

| Kode | Kelas | Butir |
|---|---|---|
| Q-H1 | WAJIB_0 | nomor kontak ganda (UNIQUE). Konservatif: TRIM kedua sisi, sedangkan UNIQUE `_ci` PAD SPACE hanya mengabaikan spasi akhir (N4) |
| Q-H2 | WAJIB_0 | `support_officer.id_user` ganda (UNIQUE) |
| Q-H3 | WAJIB_0 | pasangan petugas-kategori ganda (UNIQUE) |
| Q-H4 | INFO (usulan Q9; sebelumnya WAJIB_0) | `officer_category` yatim ke `support_officer`; dibuang + dicatat impor, simetris dengan Q-H5 |
| Q-H5 | INFO | `officer_category` yatim ke `question_category` (B-12), dibuang impor |
| Q-H6 | INFO | `support_officer.status` di luar 0/1 |
| Q-H7 | INFO | `help_desk_contact.status` di luar 0/1 |
| Q-H8 | INFO | nama kategori kembar (Q3) |
| Q-H9 | BERHENTI sebelum T12b | panjang melebihi kolom v2 [I]; hasil > 0 di salinan legacy diputus sebelum impor (Q2) |
| Q-H10 | INFO | email petugas kosong (N1) |
| Q-H18a / Q-H18b / Q-H18c | INFO | `created_at` NULL / `'0000-00-00 …'` di `help_desk_contact` / `support_officer` / `officer_category` (satu angka per tabel; CR-9, N2) |

**B. Tiket v2 pasca impor** — F-02 + T12b, gladi cutover, bahan runbook MAKE-074:

| Kode | Kelas | Butir |
|---|---|---|
| Q-H11 | INFO | tiket kategori NULL (H8-16 (a1)) |
| Q-H12 | INFO | irisan NULL + Open + pengirim yatim (review A0 B6) |
| Q-H13 | INFO | lampiran ber-path di luar aturan tulis ketat adapter: `NOT REGEXP '^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$'` **atau** mengandung segmen `.`/`..` (ditolak `LocalStorageAdapter`); berkas ini tidak dihapus fisik saat hapus tiket (B7) |
| Q-H14 | INFO | pembanding Q-H13: semua lampiran tidak kosong |
| Q-H15 | INFO | tiket Open di kategori tanpa petugas aktif (petugas status 1 **dan** akun aktif; C4) |
| Q-H16 | INFO | tiket tanpa pesan (ditampilkan di akhir daftar, B3) |
| Q-H19 | INFO | tiket terbanyak yang terlihat satu petugas aktif = status 1 **dan** akun aktif, sama dengan Q-H15; batas atas (tiket milik petugas sendiri tidak dikecualikan); pemicu Q8, Bagian 4.3 |

**C. Legacy saja** — memakai kolom legacy `pengguna.id` (`LS:440`); di v2 butir ini dijamin FK `fk_id_user_so_to_pengguna`, dan baris
yang dibuang impor dihitung di laporan impor (K-M12, MAKE-097):

| Kode | Kelas | Butir |
|---|---|---|
| Q-H17 | INFO | `support_officer.id_user` yang tidak ada di `pengguna` (pra-cek PRD v1.3 §7.1) |

Catatan: baris `disiplin_ticket_msg.attachments` di `ManifestLampiran::KOLOM` (agar lampiran lama ikut disalin) adalah bagian
**MAKE-097**, bukan DBV-028.

## 7. Verifikasi developer

### 7.1 Hasil (16-11-2026; revisi review CR 17-11-2026)

Dijalankan di DB scratch `simpeg_v2_t_f8be`, MySQL 8.0.30 port 3307, prefix test `t_`:
- `vendor/bin/phpunit tests/database/HaloSimpeg/SkemaHaloSimpegTest.php` → OK (9 test).
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
| `testUpGagalMelepasTabelYangDibuatSajaDanTidakMenyentuhTabelLama` | `officer_category` (tabel **terakhir**) sudah ada berisi baris → `up()` gagal dengan kode 1050; `help_desk_contact` + `support_officer` yang sempat dibuat di-DROP; tabel lama + barisnya utuh; `finally` men-DROP ketiga tabel bila masih ada |
| `testUpIndeksGagalMelepasIndeksYangDibuatSaja` | `idx_dticketmsg_inbox_waktu` (indeks **terakhir**) dipasang manual → `up()` migration indeks gagal dengan kode 1061; dua indeks yang sempat dibuat dilepas; indeks manual dan KEY DBV-022 utuh; `finally` melepas indeks DBV-028 yang masih ada (dicek dulu, tanpa 1091) |
| `testDownLaluUpKembali` | `down()` men-DROP tiga tabel dan ketiga indeks `AlterDisiplinTicketIndeksFase8::INDEKS`; `question_category`, `disiplin_ticket(_msg)`, `pengguna`, KEY `fk_id_question_category_i_qc` dan `fk_id_inbox_im_qc` tetap; `up()` memasang ulang ketiga indeks dengan urutan kolom tepat |
| `testKueriAuditBerjalan` | blok A identik di kedua berkas; C hanya di berkas legacy (14 kueri); berkas v2 (20 kueri) berjalan di skema v2, WAJIB_0 = 0; Q-H13 = 3 untuk `../x.pdf`, `arsip/./x.pdf`, `a b.pdf`; saat akun petugas dinonaktifkan Q-H15 berubah 0 → 1 dan Q-H19 1 → 0 |

`SkemaInboxTicketTest` dan `SkemaLayananTest` (Fase 4) tetap hijau (AC-E01.4), karena tidak ada kolom atau FK DBV-022 yang berubah.

## 8. Keputusan DB Validator

Q1–Q7 diputus WS-2 @`9189c696`, Q8–Q10 @`c147f24a` (17-11-2026), memakai nomor di tabel ini.

| No | Pertanyaan | Usulan pelaksana | Keputusan DBV |
|---|---|---|---|
| DBV-028-Q1 | Tipe PK tiga tabel | INT signed AI, sama dengan tabel helpdesk DBV-022 [I]; `id_user` INT UNSIGNED = `pengguna.id_pengguna` | ✔ **SETUJU** |
| DBV-028-Q2 | Panjang kolom [I] | `name`/`email` 150, `contact`/`no_hp` 20 | ✔ **SETUJU**, dengan syarat Q-H9 dijalankan di salinan legacy sebelum T12b; hasil > 0 diputus sebelum impor |
| DBV-028-Q3 | Unik nama `question_category` | tetap di aplikasi (trim, ci) seperti legacy `LS:385-424` | ✔ **SETUJU**, tanpa ALTER UNIQUE; Q-H8 = INFO |
| DBV-028-Q4 | KEY FK redundan `fk_id_question_category_i_qc`, `fk_id_inbox_im_qc` | tidak dibuang di DBV-028 | ✔ **SETUJU**; pembuangan boleh menyusul lewat migration ALTER terpisah dengan kartu DBV sendiri, tidak mendesak |
| DBV-028-Q5 | FK `disiplin_ticket.nip` → `pegawai.nip` (ditahan DBV-022) | tetap ditahan; validasi di aplikasi | ✔ **SETUJU**; ditinjau ulang saat cutover sesudah impor F-02 |
| DBV-028-Q6 | Bentuk indeks kategori (Bagian 4.1) | B `(kategori, status, is_read_off)` + `status IN (0, 1)` eksplisit di kueri ringkasan petugas | ✔ **SETUJU**; `status IN (0,1)` no-op semantik (CHECK 0/1) dan **wajib dibekukan di README + test EXPLAIN MAKE-071**. `(sender, status)` dan `(id_inbox, created_at)` juga SETUJU |
| DBV-028-Q7 | `created_at` master | NULL-able tanpa DEFAULT [V2] (BP8-8); stempel legacy dari DEFAULT DB [I], dicek Q-H18 | ✔ **SETUJU** |
| DBV-028-Q8 | Kolom turunan `disiplin_ticket.last_msg_at` (PRD v1.3 §7.1 "opsi terukur") | **tidak** di DBV-028; pemicu terukur Q-H19 > 5.000 atau daftar petugas > 100 ms → kartu DBV terpisah (Bagian 4.3) | ✔ **SETUJU tidak di DBV-028, dengan syarat:** (a) Q-H19 + EXPLAIN daftar petugas **wajib dijalankan di gladi impor sebelum cutover** dan hasilnya dicatat di runbook MAKE-074; (b) bila pemicu terpenuhi, kartu DBV `last_msg_at` **wajib di `main` sebelum go-live**, bukan pasca-cutover; (c) test EXPLAIN MAKE-071 memakai ambang yang sama (100 ms / Q-H19 5.000). Alasan WS-2: biaya linear ±12 µs per tiket membuat ambang 100 ms tercapai di ±8.300 tiket, sehingga Q-H19 > 5.000 (±60 ms) memberi margin; R6f/R6g 120–125 ms berasal dari data sintetis satu petugas, 10 kategori, 9.800 tiket; volume nyata per petugas belum diketahui; jalur umum 0,5–4,4 ms. Pemicu dinilai pada volume nyata (gladi / EXPLAIN MAKE-071), bukan pada data sintetis. Saran produk (bukan DBV) untuk MAKE-071: bawaan daftar petugas = filter Open (R6e 4,4 ms, key_len 9) |
| DBV-028-Q9 | Kelas Q-H4 (`officer_category` yatim ke petugas) | **INFO**: aksi FK legacy [I] (tanpa DDL; `delete_officer` `LS:711` tidak menyentuh `officer_category`), jadi yatim mungkin ada; dibuang + dicatat impor T12b, simetris dengan Q-H5 (K-M12, F8-21). Bila ditolak, kembali WAJIB_0 | ✔ **SETUJU** (WAJIB_0 → INFO), dengan syarat jumlahnya tampil di laporan impor MAKE-097 |
| DBV-028-Q10 | Nama PK `officer_category.id` [I] | tetap `id`; dicocokkan dengan salinan legacy saat MAKE-097; bila berbeda, impor memetakan kolom tanpa mengubah skema v2 | ✔ **SETUJU**; dicocokkan saat MAKE-097, impor memetakan kolom atau AUTO_INCREMENT membangkitkan ulang, tanpa mengubah skema v2 (tidak ada FK v2 ke kolom ini) |

### 8.1 Catatan untuk paket berikut (DBV, non-blok)

- **N1 (MAKE-097):** `support_officer.email` NOT NULL, jadi baris legacy ber-email NULL gagal di-insert (strict). Impor wajib
  menangani NULL secara eksplisit (petakan ke `''` atau buang + catat), dan aturannya ditulis di runbook (pra-cek Q-H10).
- **N2 (MAKE-097):** stempel waktu master legacy berasal dari DEFAULT DB legacy (kemungkinan waktu lokal server). Impor
  mengonversinya ke UTC seperti tier F-02 lain (kelas R1a), setelah zona salinan legacy dicek (pra-cek Q-H18).
- **N3 (MAKE-070/071):** urutan petugas/kategori `created_at ASC` diberi pemutus seri `id ASC`, karena `officer_category` ditulis
  ulang serentak.
- **N4 (dok):** Q-H1 men-TRIM kedua sisi, sedangkan UNIQUE `_ci` PAD SPACE hanya mengabaikan spasi akhir; Q-H1 konservatif. Impor
  men-trim `contact`.
- **N5 (dok):** basis `main` diperbarui (branch memuat `46f4d669`), dan penomoran Q diseragamkan ke nomor tabel ini.
- **N6 (dok):** definisi Q-H19 disamakan dengan Q-H15 (petugas status 1 dan akun aktif); **diselesaikan dengan JOIN `pengguna`**
  di berkas audit v2.

## 9. Syarat merge (⛔)

- DB Validator (WS-2) approve PR.
- CI Quality gate hijau (MySQL 8.0 + MariaDB 10.4).
- Gate penuh `./check.sh` lolos.
- Izin merge Horii; merge oleh reviewer CR.

## 10. Checklist DB Validator

**DBV SETUJU @`9189c696` (WS-2, 17-11-2026).** **DBV SETUJU @`c147f24a` (WS-2): selisih CR + Q8–Q10.**

- [x] Kolom dan tipe cocok dengan pemakaian kode legacy, atau deviasinya berlabel [V2]/[I] beralasan (Bagian 2).
- [x] Status master 0/1 + CHECK + DEFAULT 1.
- [x] Collation `utf8mb4_unicode_ci` ketiga tabel.
- [x] FK RESTRICT/RESTRICT; tipe `id_user` = `pengguna.id_pengguna`.
- [x] UNIQUE `contact`, `id_user`, pasangan petugas-kategori.
- [x] Indeks aditif + EXPLAIN (Bagian 4); KEY redundan (Q4).
- [x] Migration lama tidak diedit; `question_category`/`disiplin_ticket(_msg)` tidak dibuat ulang.
- [x] Kueri audit hanya SELECT, agregat, blok LARANGAN.
- [x] Keputusan Q1–Q7.
- [x] Selisih revisi review CR (CR-1..CR-12) dan keputusan Q8–Q10.
