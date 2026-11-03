# PRD Fase 8 — Halo Simpeg (Modul E, ticketing helpdesk, SIMPEG v2)

| Butir | Isi |
|---|---|
| Dokumen | `docs/fase8/PRD_Fase8_HaloSimpeg.md` — **acuan utama** coder/agen AI Fase 8 (Bahan Baku `08-HaloSimpeg.md` E-01..E-08). Pembagian kerja paralel: [`8_1.md`](8_1.md) (Jalur BE) dan [`8_2.md`](8_2.md) (Jalur FE) |
| Versi | **v1.0 — 03-11-2026** (keputusan Horii 03-11-2026 atas Q8-01..Q8-15: semua ikut saran; v0.1 `70466eac`; riwayat §Lampiran D) |
| Status | **BERLAKU setelah masuk `main`** (izin Horii). Keputusan dikunci di §5.2 dan §12. Koding Fase 8 terkunci gerbang §0.1 |
| Prinsip | **"Intinya ikut legacy"** (prinsip umum Horii 01-11-2026 pada Fase 6, dipakai sebagai default di sini): perilaku bisnis & kontrak ikut legacy [K]. Deviasi [V2] hanya bila (a) keputusan eksplisit Horii, atau (b) teknis wajib/keamanan dasar — setiap deviasi tercatat di §5.2 dan Lampiran B |
| Basis kode | `origin/main` = **`f7b9221e`** (rujukan `path:baris` repo dari commit ini) |
| Acuan legacy | Salinan kerja legacy CI3 (HEAD `0cf7e67`, sama dengan Fase 6; rujukan baris bercatatan AS-09, belum dicocokkan checksum produksi, Q5A-14). Rujukan relatif ke `application/`: `LS` = `libraries/hr/L_support.php`, `SC` = `controllers/hr/Support.php`, `MC` = `core/MY_Controller.php`, `LN` = `libraries/hr/L_notification.php`, `CR` = `controllers/hr/services/Cron.php`, `VI` = `views/hr/support/inbox.php`, `VD` = `views/hr/support/detail.php`, `VM` = `views/template/modal.php` |
| Scope | **Fase 8** = E-01..E-08 (`Bahan Baku SIMPEG/08-HaloSimpeg.md`) + ekspor rekap petugas (E-09, baru; ada di legacy `LS:1600`, tidak ada di Bahan Baku) + penyediaan titik sambung notifikasi ke Fase 6 (MAKE-066) dan lonceng Fase 7 (F-08). **Penutup seluruh development** (sign-off Horii) |
| Pelaksana | **Dua jalur paralel** (Q8-14 = B): **Jalur BE** ([`8_1.md`](8_1.md)) dan **Jalur FE** ([`8_2.md`](8_2.md)). **Reviewer** = reviewer CR (review, gate, merge, QA); **Horii** = keputusan & sign-off; **DBV** = review skema |
| Key | Skema: **DBV-028**. Pekerjaan baru: **MAKE-070..MAKE-074** (MAKE-069 dipakai paket impor cutover tahap 2). Perbaikan review/QA: CR-094 dst. ADR baru: tidak ada |
| Sumber | `08-HaloSimpeg.md` (E-01..E-08, exit criteria); Matriks Role × Endpoint Modul E (dicocokkan saat M0); PRD Fase 4 (§C-01, D10, N1/N9–N13, K-22, `docs/fase4/PRD_Fase4.md:50,126,148,265,1130,1184,1406,1506`); DBV-022 §2.14, §3 (`backend/docs/db-review/DBV-022-layanan-fase4-schema.md`); PRD Fase 6 F6-12/F6-13 (`docs/fase6/PRD_Fase6_Integrasi.md:194-195`); `Mapping_Migrasi_Tidak_Dimigrasi.md:52`; PRD Fase 7 (F-02, F-08); frontend redesign `67fa7678` (branch `feat/frontend-ui-redesign`); riset kode legacy & v2 03-11-2026 (Lampiran A) |

## Cara pakai (WAJIB dibaca)

1. **Baca seluruh PRD ini sebelum menulis satu baris kode.** Laporan M0 membuktikan pemahaman.
2. Bila bertentangan dengan sumber lain (Trello, Bahan Baku, FSD/SRS, Matriks, README modul), **PRD ini + keputusan Horii
   (§5) MENANG** untuk lingkup Fase 8. Keputusan fase sebelumnya (§5.1) tetap berlaku.
3. Hal yang **tidak tercakup** atau ambigu → **BERHENTI dan eskalasi ke reviewer CR**. **DILARANG MENEBAK.**
4. **⛔** = titik tunggu / wajib dijawab. **◻ default** = berlaku bila Horii tidak menolak saat review PRD.
5. Label: **[K]** ikut legacy (rujukan berkas:baris), **[V2]** deviasi v2 disengaja (alasan + keputusan), **[I]** inferensi
   (wajib diverifikasi di M0 atau di review DBV).
6. **Repo publik.** Dokumen, kode, test, fixture, dan commit **DILARANG** memuat host/IP/URL server internal, kredensial,
   token, NIP/nama/NIK nyata, atau isi percakapan nyata. Data uji = sintetis. Legacy `application/config/constants.php`,
   `database.php`, `config.php`, `libraries/bsre_esign/config/bsre_config.php`, `views/template/header.php` (konfigurasi
   Firebase) dan `helpers/function_helper.php` baris 2513–2955 **memuat rahasia**: jangan dibuka/dicetak. Grep legacy wajib
   `--exclude=constants.php --exclude=database.php --exclude=config.php --exclude=bsre_config.php`. Kebutuhan isi berkas itu →
   minta reviewer CR.
7. **Percakapan nyata adalah data pribadi.** Isi `disiplin_ticket(_msg)` produksi tidak pernah masuk repo, log, fixture, atau
   chat agen; hanya struktur dan data sintetis.

---

## 0. Gerbang & prasyarat

### 0.1 Gerbang koding (usulan F8-01)

- **PRD ini harus v1.0 dan masuk `main`** (keputusan Horii atas §12) sebelum koding atau migration DBV-028 dimulai.
- **Boleh sebelum itu:** membaca PRD, M0 (laporan pemahaman tanpa commit kode), dan menjawab pertanyaan §12.
- **Urutan terhadap fase lain (◻):** M1–M3 (skema, master, tiket + percakapan) tidak bergantung pada Fase 6 dan boleh jalan
  paralel dengannya. **M4 (push) menunggu MAKE-066** (adapter FCM HTTP v1, Fase 6 jalur B5) di `main`; sebelum itu notifikasi
  memakai `MockFcmAdapter` dan lolos test dengan mock. Uji push real = syarat **sebelum deploy** (dilacak MT/DEPLOY, bukan
  menahan sign-off Fase 8), sama dengan F6-23.

### 0.2 Prasyarat non-teknis

| ID | Kebutuhan | Pemilik | Menahan |
|---|---|---|---|
| PN8-1 | Pemilik aplikasi **Android** mengonfirmasi kunci `data` pada push Halo Simpeg yang dibaca app (Q8-08), dan siapa penulis token Android ke DB v2 setelah cutover (pertanyaan cutover F6-13, PN-3 Fase 6) | Horii/TL | Q8-08, uji push real |
| PN8-2 | **F-02 sortir `inbox` legacy** (Fase 7) selesai dan hasilnya siap diimpor ke `disiplin_ticket(_msg)`; Bahan Baku mensyaratkannya sebagai entry criteria | Reviewer/DBV | Impor data tiket (bukan koding) |
| PN8-3 | Keputusan Horii untuk impor master Halo Simpeg (Q8-13): `support_officer`, `officer_category`, `help_desk_contact`, serta daftar petugas aktif dan kategori yang berlaku | Horii/Biro SDM | Cutover |
| PN8-4 | Frame **Figma** untuk halaman yang belum ada (daftar tiket petugas, master kategori/petugas/kontak). Yang ada: Gambar 33 (drawer pegawai) dan Gambar 34 (inbox admin) di frontend redesign | Horii/desainer | QA visual (AGENTS.md §3.6: tanpa frame → ikut pola halaman lain + dicatat) |

Kredensial dan alamat **tidak pernah** ditulis di repo, Trello, atau chat agen; hanya di `.env` server.

---

## 1. Scope

### 1.1 Di dalam scope Fase 8

| Task | Judul | Inti v2 | Key (usulan) |
|---|---|---|---|
| E-01 | Skema pendukung helpdesk | **DBV-028**: `support_officer`, `officer_category`, `help_desk_contact` (belum ada); verifikasi `question_category`, `disiplin_ticket`, `disiplin_ticket_msg` (**sudah ada**, DBV-022 — tidak dibuat ulang); ALTER indeks/kolom bila perlu | DBV-028 |
| E-02 | Master kategori & petugas | CRUD `question_category` dan `support_officer` + `officer_category` (role 1) ikut legacy `LS:213-813` | MAKE-070 |
| E-03 | Buat tiket | `disiplin_ticket` + pesan pertama + lampiran dalam satu transaksi `LS:937-1095`; **tidak** menyentuh `inbox` | MAKE-071 |
| E-04 | Thread percakapan | Daftar tiket, baca thread, balas, tandai baca, lampiran `LS:817-927`, `LS:1097-1430`; cakupan data per pemilik/petugas kategori | MAKE-071 |
| E-05 | Tutup tiket | `close_inbox` `LS:1432-1470`; hapus tiket `LS:1472-1503` sesuai Q8-03 | MAKE-071 |
| E-06 | Notifikasi tiket | Push (queue `push`) ke pihak lawan + penghitung belum dibaca; titik sambung ke MAKE-066 (F6-13) dan lonceng F-08; **tidak** menulis `inbox` | MAKE-072 |
| E-07 | Kontak helpdesk | CRUD `help_desk_contact` (role 1) `LS:14-210` + tampilan daftar (Q8-12) | MAKE-070 |
| E-08 | Frontend | Ubah `frontend/src/features/halo-simpeg/` dari data contoh ke API; tampilan pegawai vs petugas | MAKE-073 |
| E-09 | Ekspor rekap petugas | `export_hs` `LS:1600-1830` (xlsx; periode) — baru dibanding Bahan Baku | MAKE-074 |
| Penutup | Progres, README modul, runbook cutover (impor master), QA Lapis 1, sign-off | `backend/docs/progress/08-HaloSimpeg.md` | MAKE-074 |

### 1.2 DI LUAR scope Fase 8

| Di luar scope | Milik | Catatan |
|---|---|---|
| Skema `disiplin_ticket`, `disiplin_ticket_msg`, `question_category`, `inbox`, `inbox_msg` | Sudah dibuat (DBV-022, Fase 4) | Fase 8 hanya ALTER aditif bila DBV setuju (§7.1) |
| Notifikasi approval (cuti/TB/IB/PMK) | Fase 4 `NotifikasiService` | Tidak diubah; Halo Simpeg **tidak menulis** `inbox`/`inbox_msg` dan sebaliknya |
| Lonceng / pusat notifikasi terpadu | Fase 7 F-08 | Fase 8 hanya menyediakan sumber penghitung (§6.2) bila Q8-06 = B |
| Adapter FCM nyata, antrean/topik, tabel `firebase_token` | Fase 6 (MAKE-066, DBV-027) | Fase 8 memakai, tidak membangun |
| Sortir & impor data lama `inbox`/`inbox_msg` → `disiplin_ticket(_msg)` | Fase 7 F-02 + runbook cutover | Fase 8 hanya memastikan skema/logika cocok dengan hasil impor |
| Sistem Disiplin Terintegrasi (modul disiplin) | Di luar Bahan Baku Fase 8 | Nama tabel `disiplin_ticket` hanya warisan penamaan DBV-022 |
| Aplikasi Android (backend penulis token, tampilan push) | Di luar repo | Pertanyaan cutover (PN8-1) |
| Chatbot / pencarian FAQ terintegrasi | Tidak ada di legacy | Usulan masa depan |
| Server Arsip remote untuk lampiran (J3.06) | Ditunda (F6-22) | Lampiran tiket memakai storage lokal v2 |

---

## 2. Aturan proyek & best practice kode (WAJIB)

### 2.1 Commit, key, branch, gate

- Satu milestone = satu key = satu branch dari `origin/main` terbaru: `fase8/<key>-<ringkas>`. Pesan Bahasa Indonesia
  `feat(halo-simpeg): MAKE-0xx …` / `fix(...)`. **Tanpa** penyebutan AI/tool dan **tanpa** trailer `Co-Authored-By`.
- Milestone skema = PR `[DBV]`; DBV review, **reviewer CR** yang merge (pola Fase 4–6). Migration yang sudah di `main`
  **tidak diedit**; perubahan = migration ALTER baru. Dokumen `backend/docs/db-review/DBV-028-*.md`.
- Gate cepat per paket boleh; **`./check.sh` penuh** sekali sebelum merge (reviewer/CI). Test DB wajib
  `extends Tests\Support\DatabaseTestCase` (AGENTS.md §4). Satu `./check.sh` penuh per mesin.
- Lapor ke reviewer setiap push (SHA + run CI). Tertahan kuota API GitHub → segera kabari reviewer.

### 2.2 Best practice Halo Simpeg (LOCKED)

| No | Aturan | Alasan |
|---|---|---|
| BP8-1 | **Dua rumah, tidak pernah bercampur.** Tiket = `disiplin_ticket(_msg)`; notifikasi approval = `inbox(_msg)`. Kode Halo Simpeg tidak mengimpor `NotifikasiService`/`InboxModel` dan sebaliknya. Test regresi dua arah (§7.8) | D10, exit criteria Bahan Baku |
| BP8-2 | **Cakupan data di service, bukan controller/UI** (ADR-005): pemilik hanya melihat tiketnya; petugas hanya tiket kategori yang ditugaskan kepadanya; tiket orang lain → 404 (bukan 403, agar id tidak bisa ditebak). Satu kelas `AksesTiket` dipakai semua endpoint | Isi percakapan = data pribadi; legacy mengulang pengecekan di tiap method controller (`SC:502-523`, `SC:571-585`, …) |
| BP8-3 | **Query builder / binding saja.** Tidak ada SQL string + `addslashes` | Legacy (B-7) |
| BP8-4 | **Teks polos.** `msg`/`subject` disimpan apa adanya (bukan `addslashes`), ditampilkan ter-escape; tidak ada HTML di pesan. Panjang dibatasi (§7.3) | Legacy menyimpan teks ber-`addslashes` (B-3) |
| BP8-5 | **Lampiran** lewat `AturanLampiran` + `ValidasiBerkas` + `LocalStorageAdapter` yang sudah ada (jpg/jpeg/png/gif/pdf/doc/docx/xls/xlsx, 5 MB — sama dengan legacy `LS:963-975`; struktur isi dicek, MAKE-010); diunduh lewat endpoint ber-otorisasi (BP8-2), bukan URL berkas langsung | `ArsipDataUmum.php:45`, `ValidasiBerkas.php:22-34` |
| BP8-6 | **Push setelah commit lewat queue** (ADR-013), tidak sinkron, dan kegagalan push **tidak menggagalkan** pengiriman pesan (BP-7 Fase 6). Payload tanpa isi rahasia; token tidak pernah dicatat | Legacy mengantre setelah commit (`LS:1078-1090`); N8 |
| BP8-7 | **GET tidak mengubah data.** Penandaan "sudah dibaca" lewat `POST`, bukan efek samping membuka halaman (legacy `LS:907-920`) | Konsisten dengan keputusan Fase 6 (GET yang mengubah data tidak dibawa) |
| BP8-8 | **Waktu:** DB menyimpan UTC (CR-086); `created_at`/`notify_at` diisi aplikasi (`StempelUtc`), bukan default DB; tampilan WIB di FE | CR-086 |
| BP8-9 | **Audit:** semua tulis lewat model `BaseAuditableModel` → `audit_logs` (pengganti `mylog` legacy); hapus menyimpan snapshot `before` | ADR-011/012 |
| BP8-10 | **Throttle** pembuatan tiket dan pesan per akun (batas dikonfigurasi di `Config\HaloSimpeg`), karena legacy tanpa batas dan modul ini paling terbuka | Pengaman dasar [V2] |
| BP8-11 | **Tabel daftar:** pagination di server (legacy memuat semua tiket + N+1 query, `LS:864-875`) dan aksi baris lewat menu ⋮ (`RowActionsMenu`, AGENTS.md §1) | AGENTS.md §1, BP-13 |
| BP8-12 | Kualitas: PHPStan level 5, PHP-CS-Fixer, ESLint/vue-tsc, satu test per AC; README modul (`Controllers/Api/HaloSimpeg`, `Libraries/HaloSimpeg`, `Models/HaloSimpeg`, `features/halo-simpeg`) diperbarui per endpoint | AGENTS.md §2 |
| BP8-13 | UI memakai komponen custom `shared/ui` (AGENTS.md §3): `UiSelect`, `UiTextField`, `UiFileField`, `UiTooltip`, `UiExportMenu`; tanpa `<select>`/`window.confirm` native | AGENTS.md §3 |

---

## 3. Pembagian kerja & anti-tabrakan

Pembagian **dua jalur paralel** (Q8-14 = B): **Jalur BE** ([`8_1.md`](8_1.md): DBV-028, MAKE-070, MAKE-071, MAKE-072, MAKE-074 bagian BE) dan **Jalur FE** ([`8_2.md`](8_2.md): MAKE-073 dan tombol ekspor MAKE-074). FE mulai setelah kontrak API master (MAKE-070) dan tiket (MAKE-071) **dibekukan** di README `Controllers/Api/HaloSimpeg` dan disetujui reviewer; sebelum itu FE hanya menyiapkan kerangka terhadap kontrak di §6.1. Berkas milik per bagian:

| Bagian | Berkas | Isi |
|---|---|---|
| **BE-1 master** | `Controllers/Api/HaloSimpeg/{Kategori,Petugas,Kontak}Controller.php`, `Libraries/HaloSimpeg/{Kategori,Petugas,Kontak}Service.php`, `Models/HaloSimpeg/{QuestionCategory,SupportOfficer,OfficerCategory,HelpDeskContact}Model.php`, migration DBV-028 | E-01, E-02, E-07 |
| **BE-2 tiket** | `Controllers/Api/HaloSimpeg/{Tiket,Pesan}Controller.php`, `Libraries/HaloSimpeg/{TiketService,PercakapanService,AksesTiket,LampiranTiket,RekapTiket}.php`, `Models/HaloSimpeg/{DisiplinTicket,DisiplinTicketMsg}Model.php` | E-03, E-04, E-05, E-09 |
| **BE-3 notifikasi** | `Libraries/HaloSimpeg/{TicketNotifService,PenerimaTiket}.php`, `Jobs/PushHaloSimpegJob.php` | E-06 |
| **FE** | `frontend/src/features/halo-simpeg/*`, `frontend/src/router`/`nav.config.ts` (item Halo Simpeg), `frontend/src/shared/layouts/*` (badge) | E-08 |

| Jalur | Pelaksana | Berkas milik | Tidak boleh disentuh |
|---|---|---|---|
| **BE** | satu pelaksana (ditunjuk reviewer) | semua `backend/`, `docs/fase8/8_1.md`, `backend/docs/db-review/DBV-028-*.md` | `frontend/` |
| **FE** | satu pelaksana (ditunjuk reviewer) | `frontend/src/features/halo-simpeg/*`, item menu `halo` di `nav.config.ts`, router, badge layout, `docs/fase8/8_2.md` | `backend/` (kebutuhan kontrak baru → CR ke jalur BE lewat reviewer) |

Titik temu: kontrak API beku (§6.1) dan fixture JSON sintetis respons API yang dipakai bersama sebagai bahan test FE; perubahan kontrak
setelah beku = CR yang disepakati kedua jalur lewat reviewer.

**Berkas BERSAMA — hanya ADITIF, commit TERPISAH:** `Config/Routing.php` (+ `Config/RoutesHaloSimpeg.php` baru),
`Config/Services.php`, `Config/Queue.php` (`$jobHandlers`), `Config/Filters.php`, `.env.example`, `README-deploy.md`,
`backend/docs/progress/08-HaloSimpeg.md`, `frontend` router/nav.

**DILARANG disentuh:** migration yang sudah di `main` (termasuk `CreateDisiplinTicketFase4`, `CreateMasterLayananFase4`);
`Libraries/Layanan/Notifikasi*` dan model `Inbox*` (rumah notifikasi approval); kontrak `Interfaces/PushNotifGatewayInterface.php`;
`Config/Database.php`; `MigrationsDitahan/AddFkNipLayananFase4.php` (keputusan FK di DBV-028, bukan edit berkas lama); berkas legacy rahasia (§Cara pakai 6).

---

## 4. Glosarium

| Istilah | Arti |
|---|---|
| Tiket / percakapan | Satu baris `disiplin_ticket` (legacy `inbox`) = satu topik pertanyaan pegawai ke helpdesk, berisi banyak pesan `disiplin_ticket_msg` |
| Pemilik | Pembuat tiket (`disiplin_ticket.sender` = id pengguna; `nip` = pelapor) |
| Petugas (officer / PIC Halo Simpeg) | Akun yang ditugaskan ke satu atau lebih kategori (`support_officer` + `officer_category`); menjawab tiket kategorinya |
| Kategori | `question_category`; kunci pengarah tiket ke petugas |
| Sisi pemilik / sisi petugas | Penanda baca terpisah: `is_read` (pemilik) dan `is_read_off` (petugas); domain 1 = sudah dibaca, 2 = belum |
| Pengingat 24 jam | `notify_pic` = 1 dan `notify_at` = +24 jam; tiket muncul lagi ke petugas setelah waktu itu [K `LS:1348-1352`, `MC:292`] |
| Titik sambung B5 | Antarmuka yang disiapkan PRD Fase 6 F6-13 untuk Halo Simpeg (§6.4): job `push` + `PushNotifGatewayInterface` |
| Rumah tiket / rumah notifikasi | `disiplin_ticket(_msg)` vs `inbox(_msg)` (BP8-1) |

---

## 5. Keputusan

### 5.1 Keputusan yang SUDAH berlaku (diwarisi, tidak dibuka ulang)

| ID | Isi | Sumber |
|---|---|---|
| D10 (Fase 4) | `inbox`/`inbox_msg` v2 = notifikasi satu arah; `disiplin_ticket(_msg)` = ticketing dua arah, skema final Fase 8 | `PRD_Fase4.md:148`, DBV-022 §3 |
| DBV-022 DQ-4 / DQ-6 | Nama PK/kolom `id_inbox` dipertahankan agar impor lurus; `sender`/`receiver`/`closed_by`/`msg_sender`/`msg_receiver` **tanpa FK** ke `pengguna` (sentinel 0 = semua petugas), divalidasi aplikasi | DBV-022 `:599-600` |
| DBV-022 DQ-5 | `question_category.status` domain [K] 0/1 + CHECK, DEFAULT 1 | DBV-022 §2.14 |
| DBV-022 V3 / Bagian 3.4 | Trigger `inbox_msg_afIns` tidak dibawa; logika pindah ke service Fase 8; tidak ada hapus lunak (N11) | DBV-022 `:67,301,283` |
| AC-06.4 (Fase 4) | Skenario Fase 4 tidak menulis satu baris pun ke `disiplin_ticket(_msg)` (test `NotifikasiTidakKeTicketingTest`) | `PRD_Fase4.md:808` |
| F6-13 / F6-11 | Push: kanal Android + web ikut legacy; FCM HTTP v1; `firebase_token` (`platform` 1 web / 2 Android); produsen pesan "support" (Halo Simpeg) disiapkan titik sambungnya, **Fase 8 yang memanggilnya** | `PRD_Fase6_Integrasi.md:193-195` |
| ADR-013 | Push lewat Queue driver database | `Config/Queue.php:14` |
| ADR-015 | Interface + adapter; mock → real tanpa ubah kontrak | PRD Fase 4 |
| CR-086 | Sesi DB UTC; stempel audit UTC diisi aplikasi | `App\Database\MySQLi\Connection` |
| AGENTS.md §1, §3 | Aksi baris lewat ⋮; komponen custom | AGENTS.md |

### 5.2 Keputusan Fase 8 (LOCKED — Horii 03-11-2026; ✔ Q8-xx = dijawab eksplisit di §12, ✔ (default diterima) = usulan tanpa pertanyaan yang tidak ditolak)

| ID | Keputusan | Label | Status |
|---|---|---|---|
| F8-01 | Koding menunggu PRD v1.0 berlaku; M4 (push real) menunggu MAKE-066; M1–M3 paralel dengan Fase 6 (§0.1) | — | ✔ (default diterima) |
| F8-02 | **Model data = tiket per topik** (kategori + subjek + pesan pertama), bukan satu utas per pegawai. Tampilan boleh bergaya chat (Gambar 33/34) tetapi unit data tetap tiket; data impor `inbox` legacy cocok 1:1 | [K `LS:937-1007`] | ✔ Q8-15 |
| F8-03 | **Skema baru hanya tiga tabel** (DBV-028): `support_officer`, `officer_category`, `help_desk_contact`. Kolom ikut legacy ([K]/[I] dari kode karena DDL produksi tidak ada di repo); deviasi [V2]: status 0/1 bertipe tinyint + CHECK, UNIQUE `(id_support_officer, id_question_category)`, FK RESTRICT (kebijakan V1), `support_officer.id_user` → `pengguna.id_pengguna` | [K]+[V2] | ✔ (default diterima) |
| F8-04 | **Pembuat tiket** = role pegawai (`UL_PEGAWAI` 2, 6, 7) yang punya NIP [K `SC:608`]; melihat daftar/percakapan = role 1–7 [K `SC:466,498,550`]; **role 8 (Pimpinan) tidak punya akses** [K, tidak ada di daftar legacy]. Matriks Bahan Baku "role 1–7 sebagai pengguna" dibaca sebagai akses daftar/baca, bukan pembuatan | [K] | ✔ Q8-01 |
| F8-05 | **Master (kategori, petugas, kontak) hanya role 1** untuk tulis [K `SC:13,39,…,438`]; kategori aktif dibaca semua role 1–7 untuk form tiket [K `LS:931`] | [K] | ✔ (default diterima) |
| F8-06 | **Petugas harus berstatus aktif** untuk melihat/menjawab tiket (legacy hanya memeriksa status untuk push & popup, bukan akses: `SC:507-523` vs `LS:1035`, `MC:272`). Petugas dinonaktifkan → kehilangan akses antrean (tiket miliknya sendiri tetap terlihat) | [V2] keamanan | ✔ Q8-02 |
| F8-07 | **Kandidat petugas** = pegawai aktif satker kepegawaian + akun role 1 aktif [K `LS:468-491`]. Satker diambil dari konfigurasi (bukan literal `'101'`): memakai `WebConfigService::value('id_satker_kepegawaian')` seperti PIC layanan (Q-06a Fase 4), dan peringatan bila kosong (pola `PetugasLayananService::PERINGATAN_SATKER_KOSONG`) | [K]+[V2] | ✔ Q8-11, **bersyarat**: setelah Biro SDM mengonfirmasi satker `101` = satker kepegawaian |
| F8-08 | **Siapa yang menutup**: petugas kategori tiket itu dan pemilik tiket [K controller `SC:676-690`; UI legacy hanya menampilkan tombol untuk petugas `VI:133`]. Tutup: `status=1`, `allow_reply=0`, `is_read=1`, `is_read_off=1` [K `LS:1444-1449`] **dan `closed_by` diisi** (kolom ada tetapi tidak pernah ditulis legacy) [V2 perbaikan]. **Tanpa buka kembali**; pegawai membuat tiket baru [K `LS:1117`] | [K]+[V2] | ✔ Q8-04 |
| F8-09 | **Hapus tiket**: petugas kategori itu (dan role 1 yang juga petugas) — bukan pemilik; hapus fisik [K `LS:1485`] dengan snapshot `before` di `audit_logs`; berkas lampiran ikut dihapus dari storage (legacy meninggalkan berkas yatim, B-2). Legacy controller juga mengizinkan pemilik yang bukan petugas menghapus (`SC:728-733`) walau UI tidak menampilkannya — tidak dibawa. Alternatif hapus lunak butuh ALTER skema | [K]+[V2] | ✔ Q8-03 |
| F8-10 | **Balas**: syarat tiket `status=0` dan `allow_reply=1` [K `LS:1116-1123`]; pelaku = pemilik atau petugas aktif kategorinya. Pesan: teks wajib, lampiran opsional (5 MB, 9 ekstensi) [K `LS:1552-1565`, `LS:963-975`]. Penanda baca otomatis: balasan pemilik → `is_read_off=2`; balasan petugas → `is_read=2` [I — logika trigger `inbox_msg_afIns`, DBV-022 `:301`; M0 mencocokkan dengan D1:7730-7739 lewat reviewer] | [K]+[I] | ✔ (default diterima) |
| F8-11 | **Pengingat 24 jam** (`notify_pic`/`notify_at`): petugas dapat mencentang saat membalas; reset ke 2 bila petugas membalas lagi [K `LS:1172-1178`, `LS:1348-1352`]. Tiket `perlu_tindak` = `status=0` dan (`is_read_off=2` atau `notify_pic=1` dan `notify_at < sekarang`) [K `MC:292`] | [K] | ✔ (default diterima) |
| F8-12 | **Popup login petugas dihapus**; diganti badge pada menu Halo Simpeg + panel "Perlu ditindak" di halaman petugas (legacy: modal saat login, hanya role 2/6, `MC:268-305`, `VM:734-807`) | [V2] UX | ✔ Q8-07 |
| F8-13 | **Penerima push** — tiket baru → petugas aktif kategori (akun aktif); balasan petugas → pemilik; balasan pemilik → petugas aktif kategori [K `LS:1029-1075`, `LS:1202-1285`]. Perbaikan: akun petugas **semua role** diikutkan (legacy hanya `UserLevel='2'`, sehingga petugas role 1/6 tidak pernah dapat push, B-5); token dideduplikasi per perangkat | [K]+[V2] | ✔ (default diterima) |
| F8-14 | **Perubahan status (tutup) memberi notifikasi** ke pihak lawan sesuai Bahan Baku E-06 (legacy tidak) | [V2] | ✔ Q8-05 |
| F8-15 | **Kanal push**: Android ikut legacy; **web ditambahkan** karena infrastruktur web push Fase 6 sudah ada dan petugas bekerja di web (legacy hanya Android untuk Halo Simpeg) | [K]+[V2] | ✔ Q8-09 |
| F8-16 | **Payload push**: judul "Halo Simpeg", isi = teks pesan terpotong; `data` = `{modul:'halo-simpeg', id_inbox, url}` + kunci kompatibilitas Android yang dikonfirmasi PN8-1. Legacy mengirim seluruh baris tiket (nip, jabatan, satker, nama pengirim) — **tidak** dibawa kecuali pemilik app Android memastikan membutuhkannya | [V2] privasi | ✔ Q8-08, **bersyarat**: payload minimal setelah pemilik app Android mengonfirmasi (PN8-1); sebelum itu hanya kunci `data` yang dibaca app |
| F8-17 | **Lampiran** memakai `AturanLampiran`/`ValidasiBerkas` yang ada (BP8-5); path `halo-simpeg/{nip}/{id_inbox}/…` di storage lokal; unduh lewat endpoint ber-otorisasi. Berkas lampiran impor (path legacy `arsip/{nip}/inbox/…`) dicocokkan saat cutover | [K]+[V2] | ✔ Q8-10 |
| F8-18 | **Kontak helpdesk**: CRUD role 1 [K `SC:11-160`]; daftar kontak aktif dapat dibaca semua role 1–7 (di halaman Halo Simpeg) — legacy hanya menampilkannya ke admin | [K]+[V2] | ✔ Q8-12 |
| F8-19 | **Lonceng**: Fase 8 menyediakan `GET halo-simpeg/tiket/ringkasan` (jumlah belum dibaca sisi pemilik + sisi petugas [K `LN:12-60`]) dan **tidak** menulis ke `inbox`. Konsumsi oleh lonceng terpadu F-08 = keputusan Q8-06 | [K]+[V2] | ✔ Q8-06 (sumber lonceng hanya bila F-08 sudah ada saat M5; selain itu badge menu saja dan disambung di F-08) |
| F8-20 | **Ekspor rekap** `export_hs` ikut legacy: hanya petugas; periode berdasar tanggal tiket (inklusif); kolom NO, ID PERTANYAAN, KATEGORI, NAMA PENANYA, NIP PENANYA, PERTANYAAN / JAWABAN, TIMESTAMP; xlsx [K `LS:1600-1830`, `SC:751-786`]. Perbaikan: sertakan tiket yang periode-nya cocok walau petugas belum menulis, urut tiket lalu pesan | [K]+[V2] | ✔ (default diterima) |
| F8-21 | **Impor master**: `support_officer`, `officer_category`, `help_desk_contact` dipindah saat cutover (daftar petugas dan kategori berlaku langsung); `id_user` dipetakan ke `id_pengguna` v2 lewat tabel pemetaan UP-1; baris tanpa pemetaan dilaporkan, bukan dibuang diam-diam | [V2] | ✔ Q8-13 |
| F8-22 | **Tidak dibawa**: popup modal login (F8-12), `firebase_message_logs` (digantikan `integrasi_log` Fase 6), `andr_notif_queue` (digantikan queue `push`), file view bertanggal (`inbox_20220216.php` dst.), cabang debug berkode-keras NIP (`SC:469`), log `mylog` rusak (B-9) | — | ✔ (default diterima) |

---

## 6. Matriks endpoint & integrasi

### 6.1 Endpoint v2 (amplop v2 + field `code`, prefix `api/v1`, filter `jwt` + `role`)

Role route mengikuti pola `Role::filter(...)`; kewenangan objek (pemilik/petugas) di service (BP8-2). Nama path final dicocokkan
dengan Matriks Modul E saat M0.

| Path | Method | Role route | Aturan objek | Task |
|---|---|---|---|---|
| `halo-simpeg/kategori/opsi` | GET | 1–7 | kategori `status=1` untuk form tiket | E-02 |
| `halo-simpeg/kategori` | GET, POST | 1 | list semua status (paginasi), tambah | E-02 |
| `halo-simpeg/kategori/{id}` | PUT, DELETE | 1 | ubah; hapus ditolak 422 bila dipakai tiket/petugas (sarankan nonaktifkan) | E-02 |
| `halo-simpeg/petugas` | GET, POST | 1 | list + kategori, tambah (id_user unik) | E-02 |
| `halo-simpeg/petugas/{id}` | PUT, DELETE | 1 | ubah (ganti set kategori atomik); hapus bila tidak ada tiket aktif | E-02 |
| `halo-simpeg/petugas/kandidat` | GET `?search=` | 1 | pencarian di server (F8-07) | E-02 |
| `halo-simpeg/kontak` | GET | 1–7 (aktif), 1 (semua) | | E-07 |
| `halo-simpeg/kontak`, `…/{id}` | POST, PUT, DELETE | 1 | | E-07 |
| `halo-simpeg/tiket` | GET | 1–7 | `peran=saya|petugas`, filter `status`, `kategori`, `q`; urut pesan terakhir desc; paginasi | E-04 |
| `halo-simpeg/tiket` | POST (multipart) | `UL_PEGAWAI` (Q8-01) | `id_question_category`, `subject`, `msg`, `arsip?` | E-03 |
| `halo-simpeg/tiket/ringkasan` | GET | 1–7 | belum dibaca per sisi, `perlu_tindak` | E-06 |
| `halo-simpeg/tiket/{id}` | GET | 1–7 | pemilik atau petugas kategori; **tanpa efek samping** | E-04 |
| `halo-simpeg/tiket/{id}/baca` | POST | 1–7 | menyetel `is_read` (pemilik) / `is_read_off` (petugas) | E-04 |
| `halo-simpeg/tiket/{id}/pesan` | POST (multipart) | 1–7 | `msg`, `arsip?`, `ingatkan_24_jam?` (petugas) | E-04 |
| `halo-simpeg/tiket/{id}/pesan/{idPesan}/lampiran` | GET | 1–7 | berkas dialirkan setelah cek akses | E-04 |
| `halo-simpeg/tiket/{id}/tutup` | POST | 1–7 | F8-08 | E-05 |
| `halo-simpeg/tiket/{id}` | DELETE | 1–7 | F8-09 | E-05 |
| `halo-simpeg/tiket/ekspor` | GET `?ds&de&format=xlsx` | 1–7 | hanya petugas (F8-20); 403 selain petugas | E-09 |

Pesan galat berbahasa Indonesia dan mengikuti teks legacy bila ada (mis. "Percakapan ini telah ditutup …" [K `LS:1117`],
"Percakapan ini tidak memerlukan balasan …" [K `LS:1121`]).

### 6.2 Penghitung belum dibaca (E-06, titik sambung lonceng)

`GET halo-simpeg/tiket/ringkasan` → `{ belum_dibaca_saya, belum_dibaca_petugas, perlu_tindak, total }`. Aturan hitung [K
`LN:12-60`]: tiket milik pengguna dengan `is_read=2`, ditambah (bila petugas aktif) tiket kategorinya dengan `is_read_off=2`
yang bukan milik pengguna sendiri (tidak dihitung dua kali). Hanya membaca `disiplin_ticket`.

### 6.3 Frontend (E-08)

Rute Halo Simpeg dipasang di `nav.config.ts` (item `halo`, `phase: 8` → aktif) dengan `roles` 1–7. Mengganti `halo.store.ts`
(memori, branch redesign) menjadi store/service API (ADR-021/022). Tampilan:

| Tampilan | Pengguna | Isi |
|---|---|---|
| Drawer / halaman pegawai (Gambar 33) | pemilik | daftar tiket saya + form tiket baru (kategori, subjek, pesan, lampiran) + thread; badge status Open/Closed |
| Inbox petugas (Gambar 34) | petugas | antrean tiket kategorinya (kiri), thread + balasan (kanan), tutup/hapus lewat menu ⋮, "Ingatkan 24 jam" |
| Admin: Kategori, Petugas, Kontak | role 1 | tabel `page-fill` + ⋮ (Edit, Aktifkan/Nonaktifkan, Hapus); tanpa frame Figma → ikut pola Master Data/PIC layanan dan dicatat (AGENTS.md §3.6) |

Kerangka tampilan berupa **data contoh** ada di branch `feat/frontend-ui-redesign` (commit `67fa7678`: `halo.store.ts`, `types.ts`,
`components/HaloDrawer.vue`, `views/AdminHaloPage.vue`) dan **belum ada di `main`** (di `main` hanya README modul dan item menu `halo`
ber-`phase: 8` di `nav.config.ts:262`). Kerangka itu mengasumsikan satu percakapan per pengguna dan admin = role 1 & 3 (`types.ts`, "ASUMSI");
keduanya diganti oleh model F8-02 dan F8-04/F8-06. Bila branch redesign belum di `main` saat M5, M5 mengambil berkas-berkas itu sebagai titik awal.

### 6.4 Titik sambung ke Fase 6 (B5 / MAKE-066)

| Kebutuhan | Disediakan Fase 6 | Dipakai Fase 8 |
|---|---|---|
| Kirim push | `PushNotifGatewayInterface` + `FcmV1Adapter`/`MockFcmAdapter` (`Config/Services.php`) | `PushHaloSimpegJob` memanggil gateway yang sama |
| Penyimpanan token | `firebase_token` (`nip`, `fcm_token`, `platform`, `token_hash`; DBV-027) | penerima ditentukan per NIP → semua token aktif (web + Android) |
| Antrean | queue `push` (`queue:work push`) | job baru `push-halo-simpeg` terdaftar di `Config/Queue.php` |
| Token tidak valid | job menghapus token invalid dari error `UNREGISTERED`/`INVALID_ARGUMENT` | pola sama (`PushNotifLayananJob`: retry terbatas `TRIES`) |
| Catatan percobaan | `integrasi_log` (F6-24, `IntegrasiLogInterface`) | tiap job mencatat hasil teredaksi |
| Daftar token web | `POST api/v1/integrasi/fcm-token` | tidak diubah |

Fase 8 **tidak** mengubah job `push-notif` (`PushNotifLayananJob`, terikat `inbox_msg`) — memakai job terpisah agar BP8-1 terjaga.

---

## 7. Spesifikasi per task

### 7.1 E-01 — Skema (DBV-028)

**Sudah ada (dibuat DBV-022, jangan dibuat ulang):** `question_category` (`2026-10-26-100000_CreateMasterLayananFase4.php`),
`disiplin_ticket`, `disiplin_ticket_msg` (`2026-10-26-100500_CreateDisiplinTicketFase4.php`). Kolom `disiplin_ticket`: `id_inbox` PK,
`id_question_category`, `subject`, `sender`, `sender_name`, `nip`, `jabatan`, `satker`, `receiver`, `receiver_name`, `allow_reply`,
`status` (0 Open/1 Closed), `is_read`, `is_read_off`, `notify_pic`, `notify_at`, `created_at`, `updated_at`, `closed_by`.

**DBV-028 membuat:**

| Tabel | Kolom (sumber) | Catatan |
|---|---|---|
| `support_officer` | `id_support_officer` PK, `id_user` (→ `pengguna.id_pengguna`), `email` (wajib di form, `LS:756`), `no_hp` (opsional, `LS:798`), `status` (varchar '1'/'0' di legacy), `created_at` | Kolom `email`/`no_hp`/`created_at` diturunkan dari kode legacy [I] (DDL produksi tak tersedia). v2: `status` TINYINT 0/1 + CHECK; `id_user` NOT NULL, UNIQUE (petugas unik per akun, `LS:765-788`), FK RESTRICT; `updated_at` ditambah (audit). Bahan Baku E-01 menyebut `support_officer.nip` dan `id_category` — **tidak** dipakai karena menyimpang dari legacy dan dari DQ-4 (impor lurus); dicatat sebagai deviasi dokumen (Q8-13) |
| `officer_category` | `id` PK, `id_support_officer`, `id_question_category`, `created_at` | [K `LS:803-812`]; v2: UNIQUE `(id_support_officer, id_question_category)`, FK RESTRICT (legacy CASCADE); penggantian set kategori = hapus + sisip dalam satu transaksi di service [K `LS:627-629`] |
| `help_desk_contact` | `id_help_desk_contact` PK, `name`, `contact`, `email`, `status` (0/1), `created_at`, `updated_at` | [K `LS:31-210`]; unik nomor `contact` ditegakkan aplikasi di legacy (`LS:178-197`) → v2: UNIQUE setelah pra-cek duplikat di data impor |

**ALTER aditif yang diusulkan (keputusan DBV):** indeks `disiplin_ticket (sender, updated_at)` dan
`(id_question_category, status, is_read_off)` untuk daftar/antrean; indeks `disiplin_ticket_msg (id_inbox, created_at)`;
keputusan **FK `nip` → `pegawai.nip`** yang ditahan di `MigrationsDitahan/AddFkNipLayananFase4.php`
(`fk_nip_disiplin_ticket_to_pegawai`): dipasang hanya bila audit yatim setelah impor = 0, bila tidak validasi di aplikasi.
Domain `is_read`/`is_read_off` (1/2) dan `notify_pic` (0/1/2) tidak diubah.

**Tidak** ada tabel notifikasi/antrean baru (digantikan `integrasi_log` dan queue Fase 6). Label [K]/[V2]/[I] per kolom di dokumen DBV.

**AC-E01.1** `backend/docs/db-review/DBV-028-*.md` di-approve DBV; **AC-E01.2** migration naik/turun bersih di DB scratch dan
`disiplin_ticket(_msg)`/`question_category` tidak dibuat ulang; **AC-E01.3** tes skema menegaskan FK, UNIQUE, CHECK; **AC-E01.4**
tes skema `inbox`/`inbox_msg` (Fase 4, `SkemaInboxTicketTest`) tetap hijau.

### 7.2 E-02 + E-07 — Master (MAKE-070 BE; layar admin di MAKE-073)

[K `LS:14-813`, `SC:11-460`].
- **Kategori:** `question_category` (≤250 karakter), `status` 0/1, nama unik (`LS:392-424`, pesan "Kategori sudah terdaftar …"). Hapus
  fisik di legacy tanpa cek (`LS:363-365`); v2 menolak 422 bila dipakai `disiplin_ticket` atau `officer_category` (FK RESTRICT) dan
  menyarankan Nonaktifkan (B-12).
- **Petugas:** `id_user` wajib & terdaftar (`LS:741-751`), minimal 1 kategori (`LS:752-755`), `email` wajib, `status` wajib,
  `no_hp` opsional; petugas unik per akun (`LS:765-788`). Daftar menampilkan nama petugas (role 1: nama akun; lain: NIP – nama –
  jabatan) dan kategori bernomor (`LS:437-465`). Kandidat: F8-07. Satu kategori boleh punya banyak petugas (Bahan Baku E-02).
  Menonaktifkan petugas menghentikan akses antrean (F8-06) tanpa mengubah tiket.
- **Kontak:** `name`, `contact`, `email?`, `status`; nomor unik (`LS:156-200`).
- Tulis lewat model beraudit; menu ⋮ (AGENTS.md §1). Menu navigasi: Pengaturan → "Halo Simpeg" (Kategori, Petugas, Kontak), role 1.

**AC-E02.1** CRUD kategori/petugas/kontak role 1; role lain 403; **AC-E02.2** satu kategori dengan ≥2 petugas, satu petugas dengan ≥2
kategori; **AC-E02.3** petugas duplikat ditolak 422; **AC-E02.4** hapus kategori yang dipakai → 422; **AC-E02.5** kandidat hanya
pegawai aktif satker kepegawaian + role 1; **AC-E02.6** semua tulis tercatat di `audit_logs`; **AC-E07.1** role 1–7 melihat kontak
aktif, hanya role 1 melihat semua dan mengubah.

### 7.3 E-03 — Buat tiket (MAKE-071)

[K `LS:937-1095`, `LS:1505-1550`, `SC:606-659`].
- Input: `id_question_category` (kategori **aktif**, `LS:931`), `subject` (≤250), `msg` (wajib; batas panjang [V2] di
  `Config\HaloSimpeg`), `arsip?`. Pelapor = akun login dengan NIP yang ada di `pegawai` (`SC:612-617`, pesan "Data pegawai tidak
  ditemukan …").
- Satu transaksi: sisipkan `disiplin_ticket` — `sender` = id pengguna, `sender_name` = nama bergelar, `nip`, `jabatan` & `satker`
  (**snapshot** jabatan aktif, `LS:1538-1547`), `receiver=0`, `receiver_name=NULL`, `status=0`, `allow_reply=1`, `is_read=1`,
  `is_read_off=2`, `notify_pic=2`; sisipkan pesan pertama; simpan lampiran (dihapus bila transaksi gagal, `LS:1016-1020`).
- Setelah commit: antre push ke petugas kategori (§7.6). Tiket **tidak** menulis `inbox`/`inbox_msg` (BP8-1).
- Throttle (BP8-10); pesan sukses/galat mengikuti legacy ("Berhasil menambahkan data halo simpeg.").

**AC-E03.1** tiket tersimpan di `disiplin_ticket` berstatus Open dengan pesan pertama; **AC-E03.2** `inbox`/`inbox_msg` tidak
bertambah satu baris pun (test eksplisit); **AC-E03.3** kategori nonaktif/tidak ada → 422; **AC-E03.4** pesan kosong, subjek kosong
→ 422; **AC-E03.5** lampiran >5 MB / ekstensi di luar daftar / isi tidak cocok ekstensi → 422 dan tidak ada baris; **AC-E03.6**
gagal di tengah transaksi → tidak ada tiket, pesan, atau berkas sisa; **AC-E03.7** role di luar `UL_PEGAWAI` (Q8-01) → 403; **AC-E03.8**
snapshot jabatan/satker tersimpan; **AC-E03.9** melebihi throttle → 429; **AC-E03.10** push antre sesudah commit dan kegagalan
antrean tidak menggagalkan tiket.

### 7.4 E-04 — Daftar & percakapan (MAKE-071)

[K `LS:817-927`, `LS:1097-1305`, `LS:1307-1430`, `LS:1567-1597`, `VI`, `VD`].
- **Daftar** (`peran=saya|petugas`): `saya` = `sender` = pengguna; `petugas` = `id_question_category` ∈ kategori petugas aktif
  (F8-06). Gabungan menandai `sebagai_petugas`. Hanya tiket yang punya pesan ditampilkan (`LS:868`). Kolom: tanggal tiket, subjek,
  kategori, pengirim (petugas melihat), tanggal & isi pesan terakhir (terpotong), status Open/Closed, penanda belum dibaca per sisi
  (`VI:87-92`). Urut pesan terakhir desc (`LS:878-883`). Filter kategori/status/kata kunci; paginasi di server.
- **Detail:** semua pesan urut `created_at` ascending; lampiran per pesan; tiket orang lain → 404 (BP8-2).
- **Tandai baca:** `POST …/baca` — pemilik menyetel `is_read=1`; petugas menyetel `is_read_off=1` (`LS:907-920`).
- **Balas:** F8-10. `msg_sender` = id pengguna, `msg_sender_name` = nama bergelar untuk akun ber-NIP (`LS:1581-1588`),
  `msg_receiver` = pemilik bila pembalas petugas; bila pembalas pemilik → `receiver` tiket (0) (`LS:1590-1595`). Petugas boleh
  `ingatkan_24_jam` (F8-11).
- Petugas yang juga pemilik tiket di kategorinya sendiri: dua sisi berlaku (tidak dihitung ganda di ringkasan).

**AC-E04.1** pemilik hanya melihat tiketnya; petugas melihat tiket kategorinya saja; pengguna lain 404; **AC-E04.2** pesan tersimpan
urut di `disiplin_ticket_msg` dan tidak bercampur antar tiket (test dua tiket paralel); **AC-E04.3** balasan pada tiket Closed atau
`allow_reply=0` ditolak dengan pesan legacy; **AC-E04.4** penanda baca: balasan petugas → `is_read=2`, balasan pemilik →
`is_read_off=2`, buka thread → ditandai dibaca sesuai sisi; **AC-E04.5** petugas nonaktif tidak melihat antrean (F8-06) tetapi
tetap melihat tiket miliknya; **AC-E04.6** daftar terpaginasi dan terurut pesan terakhir; **AC-E04.7** unduh lampiran hanya untuk
pihak berhak, header aman (`Content-Disposition`, tipe dari daftar); **AC-E04.8** `GET` tidak mengubah baris (test); **AC-E04.9**
teks dengan tanda kutip/backslash tersimpan utuh (BP8-4); **AC-E04.10** balasan petugas dengan `ingatkan_24_jam` menyetel
`notify_pic=1`, `notify_at` = +24 jam UTC, dan balasan petugas berikutnya mereset ke 2.

### 7.5 E-05 — Tutup & hapus (MAKE-071)

[K `LS:1432-1503`, `SC:661-749`]. Tutup: F8-08; sudah ditutup → 422 "Percakapan ini telah ditutup sebelumnya …" (`SC:672`).
Hapus: F8-09 (fisik + audit `before` + hapus berkas). Setelah ditutup tidak bisa dibalas; alur berujung ke satu node akhir
("Closed"), tidak ada status menggantung (Bahan Baku E-05).

**AC-E05.1** tutup mengubah status & flag sesuai F8-08 dan `closed_by` terisi; **AC-E05.2** balasan sesudah tutup ditolak;
**AC-E05.3** menutup dua kali ditolak; **AC-E05.4** pihak tak berhak tutup/hapus → 404/403; **AC-E05.5** hapus meninggalkan jejak
audit dan menghapus pesan serta berkas lampiran tiket itu saja; **AC-E05.6** tidak ada tiket Open yang tak bisa ditutup (test
alur penuh buat → balas → tutup).

### 7.6 E-06 — Notifikasi tiket (MAKE-072)

[K `LS:1029-1090`, `LS:1202-1300`, `LS:1373-1425`, `CR:987-1066`].
- `TicketNotifService` dipanggil **sesudah commit** oleh `TiketService`/`PercakapanService`; menentukan penerima (`PenerimaTiket`),
  lalu mengantre `PushHaloSimpegJob` per pesan.
- **Penerima:** tiket baru → petugas aktif kategori; balasan petugas → pemilik; balasan pemilik → petugas aktif kategori; tutup →
  pihak lawan (F8-14). Pelaku tidak menerima notifikasi buatannya sendiri.
- **Job:** ambil token `firebase_token` penerima (deduplikasi), kirim lewat `PushNotifGatewayInterface`, hapus token invalid, retry
  terbatas (`TRIES`), catat hasil ke `integrasi_log`. Idempoten (pesan yang sudah dikirim tidak dikirim ulang — penanda pada job,
  bukan kolom baru). Payload: F8-16.
- Penghitung belum dibaca: §6.2 (sumber badge menu dan, bila Q8-06 = B, lonceng F-08).
- **Tidak** menulis `inbox`/`inbox_msg`/`show_notif`.

**AC-E06.1** tiket baru mengantre push ke semua petugas aktif kategori (dan hanya kategori itu); **AC-E06.2** balasan mengantre
ke pihak lawan, tidak ke pelaku; **AC-E06.3** push gagal tidak menggagalkan pesan; token invalid dihapus; tidak retry selamanya;
**AC-E06.4** dengan `MockFcmAdapter` job lolos test; dengan `FcmV1Adapter` + `FakeTransport` (setelah MAKE-066) lolos test —
CI tidak memanggil layanan nyata; **AC-E06.5** payload tidak memuat NIP, jabatan, satker, token, atau isi pesan lengkap;
**AC-E06.6** `inbox`/`inbox_msg` tidak berubah oleh seluruh alur Halo Simpeg dan `disiplin_ticket(_msg)` tidak berubah oleh
alur approval Fase 4 (dua test regresi, BP8-1); **AC-E06.7** petugas role 1 dan role 6 (bukan hanya role 2) menerima push (perbaikan B-5);
**AC-E06.8** uji push sampai ke perangkat (Bahan Baku E-06) dilakukan **real** oleh reviewer sebelum deploy — dilacak MT/DEPLOY (bukan sign-off).

### 7.7 E-08 — Frontend (MAKE-073)

Per §6.3. Detail: halaman daftar tiket dengan `UiSearchInput`, `UiSelect` (kategori/status), `UiBadge` (Open/Closed, belum dibaca);
thread bergaya gelembung (Gambar 33/34) dengan lampiran; form tiket baru (kategori, subjek, pesan, `UiFileField`); aksi baris tiket
petugas lewat ⋮ (Tutup, Hapus; label baku AGENTS.md §1); panel "Perlu ditindak" untuk petugas; badge angka pada item menu Halo
Simpeg; tampilan kosong/galat/memuat. Teks pesan ditampilkan sebagai teks (tanpa `v-html`). Tanpa data contoh; `halo.store.ts` mock
(branch redesign) tidak dibawa. Test Vitest per komponen (keyboard + ARIA, AGENTS.md §3.3) dan test klien API asli (bukan hanya mock, pelajaran
amplop axios). QA Lapis 1 oleh reviewer: daftar tiket, thread, badge status, form buat tiket, beda tampilan pemilik vs petugas
(Bahan Baku E-08).

**AC-E08.1** pemilik melihat "tiket saya"; petugas melihat antrean kategorinya + tiket sendiri; **AC-E08.2** badge status & belum
dibaca benar; **AC-E08.3** form tiket menolak input tidak valid dan menampilkan galat 422 per kolom; **AC-E08.4** semua kontrol dapat
dioperasikan keyboard dan beraria benar; **AC-E08.5** halaman admin memakai `page-fill` + ⋮; **AC-E08.6** tidak ada `window.confirm`,
`<select>` native, atau `title` sebagai tooltip; **AC-E08.7** QA Lapis 1 vs Figma lolos (frame yang belum ada dicatat).

### 7.8 Verifikasi "thread tidak ter-mix" (exit criteria Bahan Baku)

Paket test lintas modul (milik MAKE-071/072, dijalankan di CI): (1) dua tiket berbeda dengan pesan bersilang tidak saling
menampakkan pesan; (2) alur penuh Halo Simpeg tidak menambah baris `inbox`/`inbox_msg`; (3) alur penuh cuti/TB/IB/PMK (Fase 4)
tidak menambah baris `disiplin_ticket(_msg)` (AC-06.4, `NotifikasiTidakKeTicketingTest` yang sudah ada tetap hijau); (4) petugas kategori A tidak melihat tiket kategori B; (5) lonceng/ringkasan
tidak memuat notifikasi approval.

### 7.9 E-09 — Ekspor rekap petugas (MAKE-074)

F8-20 [K `LS:1600-1830`, `SC:751-786`]. Parameter `ds`, `de` (wajib, inklusif), `format=xlsx` (satu-satunya format legacy,
`SC:763`). Judul "REKAP PIC HALO SIMPEG {nama}" + periode; kolom bernomor; Excel lewat library yang sudah dipakai (ADR-032/035),
sinkron dengan batas baris. NIP penanya sebagai teks. Tombol ekspor di halaman petugas (`UiExportMenu`).

**AC-E09.1** hanya petugas; **AC-E09.2** periode inklusif (23:59:59 hari akhir); **AC-E09.3** tiket kategori lain tidak ikut;
**AC-E09.4** NIP tidak berubah menjadi angka ilmiah; **AC-E09.5** periode tanpa data → 200 dengan pesan kosong (bukan berkas rusak); **AC-E09.6** format selain xlsx → 422.

---

## 8. Aturan lintas modul

- **Pegawai/riwayat:** snapshot jabatan/satker pada tiket dibaca dari jabatan aktif (`pegawai_mutasi_jabatan` v2), tidak ditulis balik.
- **RBAC:** role kode mengikuti `App\Constants\Role`; tidak ada role baru. Petugas bukan role, melainkan penugasan data (`support_officer`).
- **Notifikasi Fase 4:** tidak diubah (BP8-1). **Lonceng F-08:** hanya mengonsumsi `ringkasan` bila Q8-06 = B.
- **Fase 6:** token, adapter, antrean, log = milik Fase 6 (§6.4). Perubahan kontrak yang dibutuhkan Fase 8 = CR baru yang disepakati lewat reviewer.
- **Impor/cutover:** urutan: pemetaan id pengguna (UP-1) → master (F8-21) → tiket & pesan (F-02) → berkas lampiran (path `attachments`).
- **Privasi:** tidak ada pencatatan isi pesan di log aplikasi/queue; `audit_logs` menyimpan metadata + snapshot `before` hanya untuk hapus.

## 9. Ketergantungan

| Dari | Ke | Catatan |
|---|---|---|
| PRD Fase 8 v1.0 di `main` | Semua koding | F8-01 |
| DBV-028 | MAKE-070 → MAKE-071 → MAKE-072/073 | skema dulu; MAKE-073 (FE) menunggu kontrak API MAKE-071 beku |
| MAKE-070 (master) | MAKE-071 (tiket butuh kategori & petugas) | |
| MAKE-066 (Fase 6 B5) | M4 push real | mock sebelum itu |
| DBV-027 (`firebase_token.platform`, `token_hash`) | M4 | dari Fase 6 |
| Fase 7 F-08 (lonceng) | Q8-06 = B | konsumen `ringkasan` |
| Fase 7 F-02 + UP-1 | Impor data tiket & master | bukan koding |
| PN8-1 / PN-3 | Uji push real, payload Android | sebelum deploy |

## 10. Urutan kerja (rincian per jalur: `8_1.md` BE, `8_2.md` FE)

| M | Isi | Key | Menunggu |
|---|---|---|---|
| M0 | Laporan pemahaman ⛔ reviewer | — | PRD v1.0 |
| M1 | Skema pendukung (PR `[DBV]`), E-01 | DBV-028 | M0 |
| M2 | Master kategori/petugas/kontak — **BE** (layar admin FE ada di M5), E-02/E-07 | MAKE-070 | M1 di `main` |
| M3 | Tiket, percakapan, tutup/hapus, lampiran, ringkasan BE, E-03/E-04/E-05 | MAKE-071 | M2 |
| M4 | Notifikasi push + penerima + job, E-06 | MAKE-072 | M3; MAKE-066 untuk real |
| M5 | Frontend pemilik/petugas **dan layar admin** Kategori/Petugas/Kontak, E-08 | MAKE-073 | kontrak API M2 + M3 beku |
| M6 | Ekspor rekap (BE) + tombol ekspor (FE kecil lewat Jalur FE), penutup (progres, README, runbook cutover), E-09 | MAKE-074 | M3, M5 |
| M7 | Reviewer: QA Lapis 1 Fase 8, laporan akhir → ⛔ sign-off Horii (penutup seluruh development) | — | M6 |

Setiap M: branch baru dari `origin/main` → gate cepat → push → CI `quality-gate` hijau → lapor → ⛔ merge oleh reviewer CR (izin Horii per paket).

## 11. Definition of Done & exit criteria

**Fase 8 DONE** bila: E-01..E-09 DONE (AC terpenuhi, di `main`); verifikasi "thread tidak ter-mix" (§7.8) hijau; regresi Fase 4
(notifikasi approval, C-05/C-06) hijau; QA Lapis 1 Fase 8 lolos; runbook cutover (impor master, pemetaan `id_user`, berkas
lampiran) tercatat; laporan akhir `docs/fase8/Laporan_Akhir_Fase8.md`; **sign-off Horii sebagai penutup seluruh development**.
Kartu Fase 8 dan kartu QA terkait di Trello disinkron. **Uji push real** tidak menahan sign-off tetapi
wajib lolos sebelum deploy (MT/DEPLOY, pola F6-23).

Format laporan milestone (blok kode): Status / Branch-SHA-CI / Berkas / Test (jumlah) / Berkas bersama / Deviasi / Blocker.

## 12. Register keputusan (v1.0 — dijawab Horii 03-11-2026: semua ikut saran)

Setiap baris: pertanyaan, opsi, saran, dan keputusan. Syarat pada Q8-06, Q8-08, dan Q8-11 tetap berlaku.

| ID | Pertanyaan | Opsi | Saran | Keputusan |
|---|---|---|---|---|
| Q8-01 | **Siapa boleh membuat tiket?** Legacy: hanya akun pegawai (`UL_PEGAWAI` 2, 6, 7); role 1–7 dapat melihat daftar. Mock redesign mengizinkan role 4/5; Bahan Baku menulis "role 1–7 sebagai pengguna" | **A** ikut legacy (2, 6, 7). **B** semua role 1–7 yang memiliki NIP. **C** semua role 1–7 | **A** (prinsip ikut legacy; role 1/3/4/5 boleh tanpa NIP sehingga tidak punya identitas pelapor) | **Ikut saran (Horii 03-11-2026).** |
| Q8-02 | **Petugas nonaktif**: legacy tetap mengizinkan petugas nonaktif membuka/menjawab tiket (hanya push & popup yang memeriksa status). Apakah akses antrean ikut dinonaktifkan? | **A** ya, hanya petugas aktif (F8-06). **B** ikut legacy persis | **A** (kebocoran akses pasca-penonaktifan; perubahan kecil) | **Ikut saran (Horii 03-11-2026).** |
| Q8-03 | **Hapus tiket**: legacy hapus fisik oleh petugas kategori (pesan ikut hilang, berkas lampiran yatim; N9–N13) | **A** hapus fisik + audit snapshot + hapus berkas (F8-09). **B** hapus lunak (ALTER skema + filter di semua kueri). **C** tidak ada hapus, hanya tutup | **A** (ikut legacy; B bila DBV/Horii menginginkan jejak penuh) | **Ikut saran (Horii 03-11-2026).** |
| Q8-04 | **Tutup tiket**: siapa yang boleh, dan apakah perlu "buka kembali"? Legacy controller mengizinkan petugas kategori dan pemilik; UI hanya petugas; tidak ada buka kembali | **A** petugas kategori + pemilik, tanpa buka kembali (F8-08). **B** A + petugas boleh membuka kembali. **C** hanya petugas | **A** | **Ikut saran (Horii 03-11-2026).** |
| Q8-05 | **Notifikasi saat tiket ditutup** ke pihak lawan (legacy tidak ada; Bahan Baku E-06 menyebut "perubahan status") | **A** kirim. **B** tidak (legacy) | **A** | **Ikut saran (Horii 03-11-2026).** |
| Q8-06 | **Notifikasi tiket di lonceng/pusat notifikasi** (F-08, Fase 7)? | **A** hanya badge menu Halo Simpeg + halaman sendiri. **B** A + sumber tersendiri di lonceng terpadu yang membaca `ringkasan` (tanpa menulis `inbox`) | **B** bila F-08 sudah ada saat M5; selain itu **A** dan sambungkan di F-08 | **Ikut saran (Horii 03-11-2026).** Sumber lonceng hanya bila F-08 sudah ada saat M5. |
| Q8-07 | **Popup login petugas** (legacy: modal "PIC Halo Simpeg" saat login, hanya role 2/6, tiket belum dibaca atau pengingat 24 jam jatuh tempo; `MC:268-305`) | **A** diganti badge + panel "Perlu ditindak" (F8-12). **B** modal saat login seperti legacy | **A** | **Ikut saran (Horii 03-11-2026).** |
| Q8-08 | **Payload push Android**: legacy mengirim seluruh baris tiket (NIP, jabatan, satker, nama pengirim, kategori) di `data` | **A** kompatibel penuh (ikut legacy). **B** minimal (`modul`, `id_inbox`, `url`) + kunci yang dikonfirmasi pemilik app Android (PN8-1) | **B** setelah pemilik app mengonfirmasi; sebelum itu **A** terbatas pada kunci yang app baca | **Ikut saran (Horii 03-11-2026).** **Bersyarat:** payload minimal setelah pemilik app Android mengonfirmasi (PN8-1). |
| Q8-09 | **Kanal push**: legacy hanya Android untuk Halo Simpeg | **A** Android saja. **B** Android + web (F8-15) | **B** (infrastruktur web push Fase 6 sudah ada; petugas bekerja di web) | **Ikut saran (Horii 03-11-2026).** |
| Q8-10 | **Lampiran**: legacy 9 ekstensi, 5 MB; v2 sudah punya aturan identik + validasi isi | **A** ikut legacy via `AturanLampiran` (F8-17). **B** perketat (pdf/jpg/png saja) | **A** | **Ikut saran (Horii 03-11-2026).** |
| Q8-11 | **Sumber kandidat petugas**: legacy hard-code satker `'101'` | **A** pakai `id_satker_kepegawaian` (Web Config) seperti PIC layanan; konfirmasi `101` = satker kepegawaian. **B** kunci konfigurasi baru khusus Halo Simpeg | **A** (satu sumber kebenaran) setelah Biro SDM mengonfirmasi | **Ikut saran (Horii 03-11-2026).** **Bersyarat:** setelah Biro SDM mengonfirmasi satker `101` = satker kepegawaian. |
| Q8-12 | **Kontak helpdesk**: legacy hanya admin yang melihat daftar; Bahan Baku E-07 "view UL_ALL" | **A** ikut legacy (role 1 saja). **B** role 1–7 melihat kontak aktif | **B** | **Ikut saran (Horii 03-11-2026).** |
| Q8-13 | **Impor master** (`support_officer`, `officer_category`, `help_desk_contact`) dan **nama kolom** (`id_user` vs `nip` di Bahan Baku E-01) | **A** impor master dengan `id_user`→`id_pengguna` (pemetaan UP-1); skema ikut legacy. **B** buat ulang manual lewat UI saat cutover. **C** skema memakai `nip` seperti Bahan Baku | **A** (impor lurus; DQ-4) | **Ikut saran (Horii 03-11-2026).** |
| Q8-14 | **Pembagian pelaksana**: satu coder BE→FE berurutan atau dua jalur paralel (BE: M1–M4, FE: M5 setelah kontrak API M3 beku)? Pelaksana jalur? | **A** satu jalur berurutan. **B** dua jalur paralel (pola Fase 6) | **B** bila ada dua pelaksana; kontrak API M3 dibekukan di PRD sebelum FE mulai | **Ikut saran (Horii 03-11-2026).** Pembagian jalur: §3, `8_1.md`, `8_2.md`. |
| Q8-15 | **Model percakapan**: desain Figma/mock = satu utas per pegawai (drawer); legacy = tiket per topik dengan kategori + subjek (data impor bertipe ini) | **A** ikut legacy: tiket per topik, tampilan chat per tiket (F8-02). **B** satu utas per pegawai (menyimpang; kategori/petugas dan impor perlu rancang ulang) | **A** | **Ikut saran (Horii 03-11-2026).** |

**Trigger `inbox_msg_afIns`** (D1:7730-7739) tetap berlabel [I] dan menjadi kewajiban M0; ekstraksi oleh DBA/Horii menyusul (dump D1 tidak ada di mesin reviewer).

Prinsip umum: bila coder menemukan perilaku legacy yang belum diputus, default = ikut legacy [K]; deviasi hanya untuk alasan
teknis wajib/keamanan dasar dan **wajib** dicatat di laporan milestone + `backend/docs/progress/08-HaloSimpeg.md`.

## 13. Risiko

| ID | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R8-1 | Payload push tidak cocok dengan app Android (kunci `data`) | Notifikasi Android tidak membuka tiket | PN8-1 / Q8-08 sebelum M4; test kontrak payload |
| R8-2 | Pemetaan `id_user`/`sender`/`msg_sender` legacy → `id_pengguna` v2 tidak terawat saat impor UP-1 | Tiket yatim, petugas tak terhubung | Tabel pemetaan wajib; laporan baris tanpa pemetaan (F8-21); audit yatim sebelum FK `nip` |
| R8-3 | F-02 (sortir `inbox`) belum selesai | Data lama tidak bisa diimpor | Tidak menahan koding; menahan cutover (PN8-2) |
| R8-4 | Logika baca-otomatis yang dulu di trigger `inbox_msg_afIns` ditebak keliru | Badge belum-dibaca salah | M0 mencocokkan dengan D1:7730-7739 (via reviewer); test per skenario (AC-E04.4) |
| R8-5 | Pesan impor berisi backslash hasil `addslashes` (`LS:1576`) | Teks tampil dengan `\'` | Keputusan normalisasi di F-02/impor; FE tidak men-strip |
| R8-6 | Isi percakapan = data pribadi; kebocoran lewat lampiran/URL/log | Pelanggaran privasi | BP8-2/BP8-5, endpoint unduh ber-otorisasi, fixture sintetis, payload minimal |
| R8-7 | Modul paling terbuka (role 1–7) → spam/penyalahgunaan | Antrean petugas banjir | Throttle (BP8-10), batas panjang, lampiran tervalidasi |
| R8-8 | Frame Figma tidak lengkap | QA visual subjektif | PN8-4; ikut pola halaman lain dan dicatat |
| R8-9 | Push real tidak teruji saat sign-off | Cacat muncul di produksi | Uji real terjadwal sebelum deploy (MT/DEPLOY) |

---

## Lampiran A — Kesesuaian legacy → v2 per fitur

### A.1 Petunjuk rujukan

`LS` = `libraries/hr/L_support.php` (1.831 baris), `SC` = `controllers/hr/Support.php` (858 baris), `MC` = `core/MY_Controller.php`,
`LN` = `libraries/hr/L_notification.php`, `CR` = `controllers/hr/services/Cron.php`, `VI`/`VD` = view daftar/detail,
`VM` = `views/template/modal.php`. Tabel legacy: `inbox`, `inbox_msg`, `question_category`, `support_officer`, `officer_category`,
`help_desk_contact`, `firebase_token`, `andr_notif_queue`, `firebase_message_logs`.

### A.2 Peta fitur

| Fitur | Legacy [K] | v2 |
|---|---|---|
| Kontak helpdesk (CRUD) | `LS:14-210`; `SC:11-160` (role `['1']`); validasi nomor/nama/status + nomor unik `LS:156-200` | E-07, F8-18, `Kontak*` |
| Kategori pertanyaan (CRUD) | `LS:213-433`; `SC:162-310` (role 1); daftar + petugas per kategori `LS:213-248`; nama unik `LS:385-424`; hapus fisik `LS:363-365` | E-02; hapus 422 bila dipakai |
| Petugas (CRUD) | `LS:437-813`; `SC:313-460` (role 1); kandidat role 1 aktif + pegawai aktif satker `'101'` `LS:468-491`; validasi `LS:734-791`; set kategori `LS:803-812` (hapus lalu sisip `LS:627-629`) | E-02, F8-06/F8-07 |
| Daftar tiket | `LS:817-893`; `SC:464-494` (role 1–7); pemilik `LS:821-825`, petugas `LS:826-859`, urut pesan terakhir `LS:861-883`; tampilan `VI:87-190` (tombol tutup/hapus hanya petugas `VI:133-142`) | E-04, `GET tiket` |
| Detail percakapan | `LS:895-927` (tandai baca `LS:907-920`); `SC:496-546`; akses `SC:507-523`; tampilan `VD` | E-04, `GET tiket/{id}`, `POST …/baca` |
| Buat tiket | `LS:937-1095`; `SC:606-659` (`UL_PEGAWAI`); validasi `LS:1505-1526`; lampiran `LS:959-993`; sisip tiket+pesan `LS:996-1007`; snapshot `LS:1528-1550` | E-03 |
| Balas (petugas/pemilik) | `LS:1097-1305`; `SC:548-604`; guard status/allow_reply `LS:1116-1123`; reset pengingat `LS:1171-1179`; penyusun pesan `LS:1567-1597` | E-04 |
| Balas cepat petugas (popup) + pengingat 24 jam | `SC:788-856` (`submit_phs`, JSON); `LS:1307-1430` (`notify_pic`/`notify_at` `LS:1348-1352`); popup login `MC:268-305`, `VM:734-807` | F8-11 (balasan + `ingatkan_24_jam`), F8-12 (popup diganti) |
| Tutup | `LS:1432-1470`; `SC:661-706` | E-05, F8-08 |
| Hapus | `LS:1472-1503`; `SC:708-749` | E-05, F8-09 |
| Ekspor rekap petugas | `LS:1600-1830`; `SC:751-786` (hanya petugas) | E-09, F8-20 |
| Penghitung/pratinjau belum dibaca (lonceng) | `LN:12-130` (pemilik `is_read='2'`; petugas `is_read_off='2'`) | §6.2, F8-19 |
| Push tiket baru | `LS:1029-1090` (antre `andr_notif_queue` `LS:1080`, log `firebase_message_logs` `LS:1087`) | E-06, F8-13 |
| Push balasan | pemilik→petugas `LS:1240-1285`; petugas→pemilik `LS:1205-1238` (antre `LS:1290`); balas cepat `LS:1373-1425` (antre `LS:1415`) | E-06 |
| Pengirim push (konsumen antrean) | `CR:987-1066` (hapus baris sebelum kirim) | Fase 6 H-04 (`queue:work push`) |
| Audit | `mylog(...)` per aksi ("Tambah", "Tambah Percakapan", "Balas Percakapan", "Tutup", "Hapus", master) | `audit_logs` (BP8-9) |

### A.3 Pemetaan Bahan Baku → PRD

| Bahan Baku | PRD | Catatan |
|---|---|---|
| E-01 migration tabel pendukung | §7.1 | FK `support_officer.nip`/`id_category` diganti `id_user`/`id_question_category` (ikut legacy; Q8-13) |
| E-02 master kategori & petugas | §7.2 | + kandidat, hapus 422 |
| E-03 buat tiket | §7.3 | pembuat `UL_PEGAWAI` (Q8-01) |
| E-04 thread | §7.4 | cakupan data BP8-2 |
| E-05 close | §7.5 | tanpa buka kembali (Q8-04) |
| E-06 notifikasi | §7.6 | tutup ikut notifikasi (Q8-05); tidak mencemari `inbox` (BP8-1) |
| E-07 kontak | §7.2 | tampilan Q8-12 |
| E-08 frontend | §7.7 | |
| (baru) E-09 ekspor rekap | §7.9 | ada di legacy, tidak di Bahan Baku |

## Lampiran B — Anomali legacy & perlakuan v2

| No | Anomali | Bukti | Perlakuan |
|---|---|---|---|
| B-1 | Kolom `closed_by` ada tetapi tidak pernah ditulis saat tutup | `LS:1444-1449` | Diisi (F8-08) |
| B-2 | Hapus fisik tiket (pesan ikut hilang), berkas lampiran remote tidak dihapus | `LS:1483-1485` | Q8-03; berkas ikut dihapus |
| B-3 | Pesan disimpan dengan `addslashes` (dan di-`stripslashes` hanya saat kirim push) | `LS:1576`, `SC:812`, `CR:1036,1043` | Teks polos (BP8-4); risiko data impor R8-5 |
| B-4 | Akses petugas tidak memeriksa status petugas, sementara push/popup memeriksa | `SC:507-523`, `LS:827` vs `LS:1035`, `MC:272` | F8-06 (Q8-02) |
| B-5 | Push hanya ke petugas ber-`UserLevel='2'`; petugas role 1/6 tidak pernah dapat push | `LS:1035`, `LS:1245` | F8-13 |
| B-6 | `data` push memuat seluruh baris tiket (NIP, jabatan, satker, nama) | `LS:1039-1041`, `LS:1213-1215` | F8-16 (Q8-08) |
| B-7 | SQL dirakit string + `addslashes`; id dari URL tanpa binding | seluruh `LS`/`SC` | BP8-3 |
| B-8 | Membuka halaman percakapan (GET) mengubah status baca | `LS:907-920` | `POST …/baca` (BP8-7) |
| B-9 | Log audit rusak: `explode("\n", $array)` terbalik, menghasilkan galat/kosong | `LS:578`, `LS:661-664` | Diganti `audit_logs` |
| B-10 | Memuat semua tiket + N+1 query per tiket | `LS:864-875` | Paginasi + join (BP8-11) |
| B-11 | UI hanya petugas yang melihat tombol tutup/hapus, controller mengizinkan pemilik juga | `VI:133-142` vs `SC:676-690`, `SC:719-733` | Tutup: keduanya (F8-08); hapus: petugas saja (F8-09) |
| B-12 | Hapus kategori/petugas tanpa cek ketergantungan | `LS:363-365`, `LS:709-711` | FK RESTRICT + pesan 422 (E-02) |
| B-13 | Popup login hanya muncul untuk petugas ber-role 2/6; petugas role 1 tidak diingatkan | `MC:271` | F8-12 |
| B-14 | `support_officer.email` wajib di form tetapi tidak ditemukan dipakai mengirim surel di berkas yang dibaca | `LS:756` [I] | Dipertahankan sebagai data kontak |
| B-15 | Cabang debug berkode-keras NIP di controller | `SC:469-471` | Tidak dibawa (F8-22) |
| B-16 | Unik nomor kontak/petugas hanya di aplikasi (kondisi balapan) | `LS:176-199`, `LS:765-788` | UNIQUE di DB (F8-03) |
| B-17 | Ekspor hanya memuat tiket yang pernah dijawab petugas yang sama dalam periode | `LS:1603-1609` | F8-20 (diperluas) |
| B-18 | Antrean push dihapus sebelum dikirim → pesan hilang bila gagal | `CR:1013-1020` | Fase 6 (queue + retry); BP8-6 |

## Lampiran C — Komponen v2 yang dipakai ulang

| Kebutuhan | Komponen v2 |
|---|---|
| Penentuan petugas aktif satker | `WebConfigService::value('id_satker_kepegawaian')`; pola `PetugasLayananService::kandidat` (`Libraries/Layanan/PetugasLayananService.php`) |
| Lampiran | `AturanLampiran`, `ValidasiBerkas`, `LocalStorageAdapter` (`Libraries/Kepegawaian/Lampiran/*`) |
| Push | `PushNotifGatewayInterface`, `MockFcmAdapter`, `FcmV1Adapter` (MAKE-066), pola `Jobs/PushNotifLayananJob.php` |
| Log integrasi | `IntegrasiLogInterface::catat(CatatanIntegrasi)` (Fase 6, kontrak bersama) |
| Waktu | `StempelUtc`, `TanggalBisnis` (`Libraries/Waktu`) |
| Excel | library ekspor ADR-032/035 |
| Role | `App\Constants\Role` (`UL_PEGAWAI`), `Role::filter(...)` di route |
| FE | `UiSelect`, `UiTextField`, `UiFileField`, `UiBadge`, `UiAvatar`, `UiSearchInput`, `UiExportMenu`, `RowActionsMenu`, `HaloDrawer.vue`/`AdminHaloPage.vue` (kerangka tampilan di branch `feat/frontend-ui-redesign`, data contoh diganti) |

## Lampiran D — Riwayat versi

| Versi | Tanggal | Perubahan |
|---|---|---|
| v0.1 | 03-11-2026 | Draf awal dari riset kode legacy (`0cf7e67`: `L_support`, `Support`, `MY_Controller`, `L_notification`, `Cron`, view support) dan v2 (`f7b9221e`): usulan F8-01..F8-22, pertanyaan Q8-01..Q8-15, peta legacy → v2, anomali B-1..B-18, risiko R8-1..R8-9, milestone M0..M7. Tidak ada kode dan tidak ada migration |
| v1.0 | 03-11-2026 | Keputusan Horii 03-11-2026: Q8-01..Q8-15 semua ikut saran (F8-xx menjadi ✔; syarat Q8-06, Q8-08, Q8-11 tetap dicatat); key final DBV-028 dan MAKE-070..074 (MAKE-069 dipakai paket impor cutover tahap 2); dua jalur paralel BE/FE (§3, `8_1.md`, `8_2.md`); trigger `inbox_msg_afIns` tetap [I] + kewajiban M0 |
