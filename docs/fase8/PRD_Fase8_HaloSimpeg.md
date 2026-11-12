# PRD Fase 8 — Halo Simpeg (Modul E, ticketing helpdesk, SIMPEG v2)

| Butir | Isi |
|---|---|
| Dokumen | `docs/fase8/PRD_Fase8_HaloSimpeg.md` — **acuan utama** coder/agen AI Fase 8 (Bahan Baku `08-HaloSimpeg.md` E-01..E-08, ditambah E-09 dan E-10 Chatbot "Vanya"). Pembagian kerja paralel: [`8_1.md`](8_1.md) (Jalur BE) dan [`8_2.md`](8_2.md) (Jalur FE) |
| Versi | **v1.1 — 12-11-2026 (CR-121)**: tambah **E-10 Chatbot "Vanya"** atas keputusan Horii 12-11-2026 ("ikut saran" K-1..K-11). v1.0 03-11-2026 (keputusan Horii atas Q8-01..Q8-15: semua ikut saran); v0.1 `70466eac`; riwayat §Lampiran D |
| Status | **BERLAKU setelah masuk `main`** (izin Horii). Keputusan dikunci di §5.2 dan §12. Koding Fase 8 terkunci gerbang §0.1; E-10 punya gerbang tambahan (§0.1, D1 chatbot) |
| Prinsip | **"Intinya ikut legacy"** (prinsip umum Horii 01-11-2026 pada Fase 6, dipakai sebagai default di sini): perilaku bisnis & kontrak ikut legacy [K]. Untuk E-10, acuannya **legacy dev** [K dev] karena fitur chatbot belum ada di legacy produksi. Deviasi [V2] hanya bila (a) keputusan eksplisit Horii, atau (b) teknis wajib/keamanan dasar — setiap deviasi tercatat di §5.2 dan Lampiran B |
| Basis kode | `origin/main` = **`f7b9221e`** untuk rujukan `path:baris` repo v1.0; rujukan E-10 (v1.1) dicek di **`241ef9e5`** |
| Acuan legacy | Salinan kerja legacy CI3 (HEAD `0cf7e67`, sama dengan Fase 6; rujukan baris bercatatan AS-09, belum dicocokkan checksum produksi, Q5A-14). Rujukan relatif ke `application/`: `LS` = `libraries/hr/L_support.php`, `SC` = `controllers/hr/Support.php`, `MC` = `core/MY_Controller.php`, `LN` = `libraries/hr/L_notification.php`, `CR` = `controllers/hr/services/Cron.php`, `VI` = `views/hr/support/inbox.php`, `VD` = `views/hr/support/detail.php`, `VM` = `views/template/modal.php`. **Bahan chatbot legacy dev** (12 berkas CI3 dari codebase legacy dev, belum ada di produksi, diterima 12-11-2026): rujukan berawalan **`dev:`** relatif ke `application/` bahan itu (`dev:controllers/hr/Chatbot.php`, `dev:libraries/hr/L_chatbot.php`, `dev:libraries/Nlp_engine.php`, `dev:views/hr/chatbot/*`) supaya tidak tertukar dengan legacy produksi |
| Scope | **Fase 8** = E-01..E-08 (`Bahan Baku SIMPEG/08-HaloSimpeg.md`) + ekspor rekap petugas (E-09, baru; ada di legacy `LS:1600`, tidak ada di Bahan Baku) + **E-10 Chatbot "Vanya"** (baru di v1.1; dari legacy dev, K-1) + penyediaan titik sambung notifikasi ke Fase 6 (MAKE-066) dan lonceng Fase 7 (F-08). **Penutup seluruh development** (sign-off Horii) |
| Pelaksana | **Dua jalur paralel** (Q8-14 = B): **Jalur BE** ([`8_1.md`](8_1.md)) dan **Jalur FE** ([`8_2.md`](8_2.md)). E-10 dikerjakan sebagai sub-jalur chatbot di kedua berkas itu; pelaksananya boleh berbeda dan ditunjuk lewat kode izin workload sesudah PRD v1.1 disetujui (§3). **Reviewer** = reviewer CR (review, gate, merge, QA); **Horii** = keputusan & sign-off; **DBV** = review skema |
| Key | Skema: **DBV-028** (helpdesk) dan **DBV-033** (chatbot, K-3). Pekerjaan baru: **MAKE-070..MAKE-074** (MAKE-069 dipakai paket impor cutover tahap 2) dan **MAKE-094** (BE chatbot) + **MAKE-095** (FE chatbot). Revisi PRD v1.1: **CR-121**. Perbaikan review/QA: CR-095 dst. ADR baru: tidak ada |
| Sumber | `08-HaloSimpeg.md` (E-01..E-08, exit criteria); Matriks Role × Endpoint Modul E (dicocokkan saat M0); PRD Fase 4 (§C-01, D10, N1/N9–N13, K-22, `docs/fase4/PRD_Fase4.md:50,126,148,265,1130,1184,1406,1506`); DBV-022 §2.14, §3 (`backend/docs/db-review/DBV-022-layanan-fase4-schema.md`); PRD Fase 6 F6-12/F6-13 (`docs/fase6/PRD_Fase6_Integrasi.md:194-195`); `Mapping_Migrasi_Tidak_Dimigrasi.md:52`; PRD Fase 7 (F-02, F-08); frontend redesign `67fa7678` (branch `feat/frontend-ui-redesign`); riset kode legacy & v2 03-11-2026 (Lampiran A). **E-10:** bahan chatbot legacy dev (`dev:`); dokumen kerja reviewer `Mapping_Chatbot_Fase8.md` (12-11-2026, di luar repo; keputusan K-1..K-11 §6, anomali A1..A9 §3.4); paket permintaan D1 chatbot dari DB dev legacy (di luar repo, baca saja) |

## Cara pakai (WAJIB dibaca)

1. **Baca seluruh PRD ini sebelum menulis satu baris kode.** Laporan M0 membuktikan pemahaman.
2. Bila bertentangan dengan sumber lain (Trello, Bahan Baku, FSD/SRS, Matriks, README modul), **PRD ini + keputusan Horii
   (§5) MENANG** untuk lingkup Fase 8. Keputusan fase sebelumnya (§5.1) tetap berlaku.
3. Hal yang **tidak tercakup** atau ambigu → **BERHENTI dan eskalasi ke reviewer CR**. **DILARANG MENEBAK.**
4. **⛔** = titik tunggu / wajib dijawab. **◻ default** = berlaku bila Horii tidak menolak saat review PRD.
5. Label: **[K]** ikut legacy (rujukan berkas:baris), **[K dev]** ikut legacy **dev** (bahan chatbot E-10, rujukan `dev:`),
   **[V2]** deviasi v2 disengaja (alasan + keputusan), **[I]** inferensi (wajib diverifikasi di M0 atau di review DBV),
   **[K dev D1]** struktur yang terkonfirmasi DDL DB dev hasil D1 chatbot blok [1] (`HASIL_D1_chatbot_1211.md`, di luar repo).
6. **Repo publik.** Dokumen, kode, test, fixture, dan commit **DILARANG** memuat host/IP/URL server internal, kredensial,
   token, NIP/nama/NIK nyata, atau isi percakapan nyata. Data uji = sintetis. Legacy `application/config/constants.php`,
   `database.php`, `config.php`, `libraries/bsre_esign/config/bsre_config.php`, `views/template/header.php` (konfigurasi
   Firebase) dan `helpers/function_helper.php` baris 2513–2955 **memuat rahasia**: jangan dibuka/dicetak. Grep legacy wajib
   `--exclude=constants.php --exclude=database.php --exclude=config.php --exclude=bsre_config.php`. Kebutuhan isi berkas itu →
   minta reviewer CR.
7. **Percakapan nyata adalah data pribadi.** Isi `disiplin_ticket(_msg)` produksi tidak pernah masuk repo, log, fixture, atau
   chat agen; hanya struktur dan data sintetis. Berlaku juga untuk `log_chatbot.pesan_user` (pesan bebas pegawai ke Vanya):
   isi log DB dev tidak diambil (untuk `log_chatbot`, D1 chatbot hanya mengambil struktur dan angka) dan tidak dimigrasi.
   Isi konten QnA dev (CSV D1 blok [7], bila diambil) **tidak pernah masuk repo**, termasuk sebagai seeder atau fixture (F8-46).

---

## 0. Gerbang & prasyarat

### 0.1 Gerbang koding (usulan F8-01)

- **PRD ini harus v1.0 dan masuk `main`** (keputusan Horii atas §12) sebelum koding atau migration DBV-028 dimulai.
- **Boleh sebelum itu:** membaca PRD, M0 (laporan pemahaman tanpa commit kode), dan menjawab pertanyaan §12.
- **Urutan terhadap fase lain (◻):** M1–M3 (skema, master, tiket + percakapan) tidak bergantung pada Fase 6 dan boleh jalan
  paralel dengannya. **M4 (push) menunggu MAKE-066** (adapter FCM HTTP v1, Fase 6 jalur B5) di `main`; sebelum itu notifikasi
  memakai `MockFcmAdapter` dan lolos test dengan mock. Uji push real = syarat **sebelum deploy** (dilacak MT/DEPLOY, bukan
  menahan sign-off Fase 8), sama dengan F6-23.
- **Gerbang E-10 Chatbot (v1.1):** koding E-10 dan migration DBV-033 menunggu **PRD v1.1 masuk `main`** (CR-121). DBV-033
  baru disusun **sesudah D1 chatbot dari DB dev legacy** diterima (PN8-5), karena DDL keenam tabel tidak ikut di bahan kode; D1
  chatbot adalah prasyarat DBV-033, **bukan** prasyarat PRD. Status 12-11-2026: blok [1]–[6] (struktur, `qna_status`, jumlah,
  sebaran, integritas, bentuk jawaban) **sudah diterima** dan dipakai di §7.10.1, PN8-5, F8-40, F8-46. MAKE-094 menunggu DBV-033 di `main`; MAKE-095 menunggu kontrak
  API MAKE-094 beku (§6.5). E-10 **tidak** bergantung pada DBV-028/MAKE-070..074, Fase 6 (tidak ada push), maupun F-08, dan
  sebaliknya: helpdesk dan chatbot boleh berjalan paralel. Pelaksana E-10 ditunjuk lewat kode izin workload sesudah v1.1
  disetujui.

### 0.2 Prasyarat non-teknis

| ID | Kebutuhan | Pemilik | Menahan |
|---|---|---|---|
| PN8-1 | Pemilik aplikasi **Android** mengonfirmasi kunci `data` pada push Halo Simpeg yang dibaca app (Q8-08), dan siapa penulis token Android ke DB v2 setelah cutover (pertanyaan cutover F6-13, PN-3 Fase 6) | Horii/TL | Q8-08, uji push real |
| PN8-2 | **F-02 sortir `inbox` legacy** (Fase 7) selesai dan hasilnya siap diimpor ke `disiplin_ticket(_msg)`; Bahan Baku mensyaratkannya sebagai entry criteria | Reviewer/DBV | Impor data tiket (bukan koding) |
| PN8-3 | Keputusan Horii untuk impor master Halo Simpeg (Q8-13): `support_officer`, `officer_category`, `help_desk_contact`, serta daftar petugas aktif dan kategori yang berlaku | Horii/Biro SDM | Cutover |
| PN8-4 | Frame **Figma** untuk halaman yang belum ada (daftar tiket petugas, master kategori/petugas/kontak). Yang ada: Gambar 33 (drawer pegawai) dan Gambar 34 (inbox admin) di frontend redesign | Horii/desainer | QA visual (AGENTS.md §3.6: tanpa frame → ikut pola halaman lain + dicatat) |
| PN8-5 | **D1 chatbot** dari DB **dev** legacy (baca saja): `SHOW CREATE TABLE` keenam tabel chatbot, isi `qna_status`, jumlah baris & sebaran status, QnA aktif per kategori, relasi yatim/ganda, panjang teks & jawaban ber-HTML, ringkasan angka log (blok [1]–[6]). Isi `log_chatbot.pesan_user` **tidak** diambil. **Status 12-11-2026: blok [1]–[6] DITERIMA** (struktur di §7.10.1, label [K dev D1]). Angka dev: 274 QnA (271 aktif, 3 unggulan); 60 kategori (56 aktif, 4 bubble: Cuti, Kinerja, Simpeg, Syarat dan Hak Pensiun; 3 kategori uji "test…" nonaktif; 8 kategori aktif tanpa QnA); 696 tag (688 aktif, 84 tanpa QnA); 827 relasi (1 ganda); QnA tanpa kategori 0; judul kategori kembar 2, judul tag kembar 11; pertanyaan terpanjang 193 karakter, jawaban terpanjang 1.318 karakter, jawaban ber-HTML 2, pola berisiko 0; log 74 baris uji (36% tidak cocok). Rincian di dokumen kerja `HASIL_D1_chatbot_1211.md` (di luar repo). **Urutan dua tahap:** D1 blok [1]–[6] → keputusan K-5 (PN8-6) → isi konten (blok [7]) diminta terpisah sesudahnya; bila blok [7] terlanjur diambil lebih awal, CSV-nya tetap disimpan di luar repo dan tidak dipakai sebelum K-5 diputus | Horii | DBV-033 (bukan PRD) |
| PN8-6 | **Keputusan isi QnA awal (K-5)** — **DITUNDA ke saat deploy/cutover** (Horii 12-11-2026: "nanti saja, catat pada saat deploy"; dicatat di Trello DEPLOY-002). Saat itu Horii menetapkan apakah isi dev dibawa (CSV blok [7] → perintah impor sekali jalan lewat runbook cutover, F8-46; CSV tidak pernah di-commit) atau v2 mulai kosong dan diisi admin | Horii | Isi awal chatbot saat cutover (bukan koding) |
| PN8-7 | **Foto avatar Vanya (opsional)**: legacy dev memakai foto `Vanya_Avatar.*` bila ada, selain itu ilustrasi SVG (`dev:views/hr/chatbot/chatbot_popup.php:19-27`). Berkas foto (bila dipakai) diserahkan sebagai aset FE dengan izin pemakaian gambar | Horii/desainer | Tidak menahan (SVG bawaan dipakai) |
| PN8-8 | **Acuan visual widget dev** untuk QA Lapis 1 chatbot (AC-E10.22): tangkapan layar widget dev (launcher, kartu sapaan, jendela chat, bubble, penilaian, layar sempit) dari Horii, atau render ulang view dev di luar repo oleh reviewer. Tanpa frame Figma (K-11); tangkapan layar tidak masuk repo bila memuat data nyata | Horii/reviewer | QA Lapis 1 chatbot (bukan koding) |

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
| E-10 (skema) | Skema chatbot "Vanya" | **DBV-033** (K-3): 6 tabel `qna`, `qna_kategori`, `qna_tag`, `qna_has_tag`, `qna_status`, `log_chatbot` ikut DDL dev hasil D1 chatbot; status 1/2/10 (memperbaiki anomali A1); terpisah dari DBV-028 | DBV-033 |
| E-10 (BE) | Chatbot "Vanya" — backend | Mesin jawab TF-IDF port apa adanya [K dev `dev:libraries/Nlp_engine.php`, `dev:libraries/hr/L_chatbot.php:573-769`]; endpoint widget (role 2/6/7); CRUD admin kategori/tag/QnA, impor Excel + template, ekspor tag, statistik, riwayat berhalaman (role 1); throttle; token feedback (K-4) | MAKE-094 |
| E-10 (FE) | Chatbot "Vanya" — frontend | Widget melayang di layout untuk role 2/6/7 (K-8) dengan tampilan legacy dev disesuaikan token desain v2 (K-11); halaman admin "Chatbot Manager" (role 1) dengan tabel ⋮ (AGENTS.md §1) | MAKE-095 |
| Penutup | Progres, README modul, runbook cutover (impor master; isi QnA bila K-5 membawa isi dev), QA Lapis 1 (termasuk chatbot), sign-off | `backend/docs/progress/08-HaloSimpeg.md` | MAKE-074 (+ MAKE-094/095 untuk bagian chatbot; impor isi QnA = C4, key ◻ §10) |

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
| Integrasi chatbot → tiket Halo Simpeg (tombol "Tanya petugas" saat Vanya tidak memahami pertanyaan) | Usulan lanjutan (K-9) | Chatbot berdiri sendiri seperti legacy dev (F8-31) |
| LLM / AI generatif / layanan NLP eksternal untuk chatbot | Tidak ada di legacy dev | Mesin jawab = pencocokan teks lokal TF-IDF (PHP murni, tanpa panggilan layanan luar) [K dev `dev:libraries/Nlp_engine.php`] |
| Ekspor QnA ke Excel | Tidak ada di legacy dev (K-7) | Ekspor hanya daftar tag |
| Isi `log_chatbot` DB dev | Pesan bebas pegawai (data pribadi) | Tidak diambil dan tidak dimigrasi; log v2 mulai kosong |
| Server Arsip remote untuk lampiran (J3.06) | Ditunda (F6-22) | Lampiran tiket memakai storage lokal v2 |

Catatan K-1 (v1.1): baris v1.0 "Chatbot / pencarian FAQ terintegrasi — tidak ada di legacy — usulan masa depan" dihapus dari
tabel ini dan diganti E-10 (§1.1, F8-23); bahan chatbot legacy dev tersedia 12-11-2026.

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
| BP8-14 | **Chatbot — jawaban HTML terbatas** (K-6): `qna.jawaban` disanitasi whitelist saat **simpan** (form dan impor) lewat `HtmlSanitizer` yang sama dengan FAQ, dan dirender di FE **hanya** lewat `SafeHtml` (DOMPurify). Teks lain (pesan pengguna, pertanyaan, judul kategori/tag, riwayat, statistik) selalu teks ter-escape, tanpa `v-html` | Anomali A2 (`dev:views/hr/chatbot/chatbot_popup.php:325`) |
| BP8-15 | **Chatbot — log anonim** (K-4): `log_chatbot` tidak menyimpan `id_pengguna`/NIP; isi pesan pengguna tidak ditulis ke log aplikasi, `audit_logs`, atau queue; kepemilikan feedback lewat token bertanda tangan (F8-35), bukan kolom identitas | K-4, privasi |

---

## 3. Pembagian kerja & anti-tabrakan

Pembagian **dua jalur paralel** (Q8-14 = B): **Jalur BE** ([`8_1.md`](8_1.md): DBV-028, MAKE-070, MAKE-071, MAKE-072, MAKE-074 bagian BE) dan **Jalur FE** ([`8_2.md`](8_2.md): MAKE-073 dan tombol ekspor MAKE-074). FE mulai setelah kontrak API master (MAKE-070) dan tiket (MAKE-071) **dibekukan** di README `Controllers/Api/HaloSimpeg` dan disetujui reviewer; sebelum itu FE hanya menyiapkan kerangka terhadap kontrak di §6.1. Berkas milik per bagian:

| Bagian | Berkas | Isi |
|---|---|---|
| **BE-1 master** | `Controllers/Api/HaloSimpeg/{Kategori,Petugas,Kontak}Controller.php`, `Libraries/HaloSimpeg/{Kategori,Petugas,Kontak}Service.php`, `Models/HaloSimpeg/{QuestionCategory,SupportOfficer,OfficerCategory,HelpDeskContact}Model.php`, migration DBV-028 | E-01, E-02, E-07 |
| **BE-2 tiket** | `Controllers/Api/HaloSimpeg/{Tiket,Pesan}Controller.php`, `Libraries/HaloSimpeg/{TiketService,PercakapanService,AksesTiket,LampiranTiket,RekapTiket}.php`, `Models/HaloSimpeg/{DisiplinTicket,DisiplinTicketMsg}Model.php` | E-03, E-04, E-05, E-09 |
| **BE-3 notifikasi** | `Libraries/HaloSimpeg/{TicketNotifService,PenerimaTiket}.php`, `Jobs/PushHaloSimpegJob.php` | E-06 |
| **FE** | `frontend/src/features/halo-simpeg/*` (kecuali `chatbot/`), route Halo Simpeg di `frontend/src/router` dan item menu `halo` di `nav.config.ts` (aditif, berkas bersama), badge di `frontend/src/shared/layouts/*` (aditif; `RedesignShell.vue` dan `nav.config.ts` = berkas bersama) | E-08 |
| **BE-4 chatbot** | `Controllers/Api/HaloSimpeg/Chatbot/*`, `Libraries/HaloSimpeg/Chatbot/*` (mesin `MesinJawab`/`TfIdf`, `ChatbotService`, `TokenFeedback`, `ImporQna`, `StatistikChatbot`), `Models/HaloSimpeg/Chatbot/*`, `Config/Chatbot.php`, `Config/RoutesChatbot.php`, migration DBV-033, `backend/docs/db-review/DBV-033-*.md`, test `backend/tests/**/HaloSimpeg/Chatbot/*` dan `backend/tests/_support/HaloSimpeg/Chatbot/*`, perintah spark impor isi QnA C4 (`backend/app/Commands/HaloSimpeg/Chatbot/*`, ◻) (nama kelas final di M0, F8-43) | E-10 |
| **FE chatbot** | `frontend/src/features/halo-simpeg/chatbot/*` (widget `VanyaWidget.vue`, halaman admin Chatbot Manager, `routes.chatbot.ts`, fixture test), item menu Chatbot Manager di `nav.config.ts`, satu titik pasang widget di `shared/layouts/RedesignShell.vue`, satu baris import route di `frontend/src/router/index.ts`, satu panggilan reset store di `frontend/src/features/auth/stores/auth.store.ts` `clearSession()` (empat terakhir = berkas bersama, aditif) | E-10 |

| Jalur | Pelaksana | Berkas milik | Tidak boleh disentuh |
|---|---|---|---|
| **BE** | satu pelaksana (ditunjuk reviewer) | semua `backend/` **kecuali** berkas BE-4 chatbot, `docs/fase8/8_1.md`, `backend/docs/db-review/DBV-028-*.md` | `frontend/`; berkas BE-4 chatbot |
| **FE** | satu pelaksana (ditunjuk reviewer) | `frontend/src/features/halo-simpeg/*` (kecuali `chatbot/`), item menu `halo` di `nav.config.ts`, route Halo Simpeg di router, badge di layout, `docs/fase8/8_2.md` | `backend/` (kebutuhan kontrak baru → CR ke jalur BE lewat reviewer); `features/halo-simpeg/chatbot/*` |
| **Chatbot BE** (E-10, MAKE-094) | ditunjuk lewat kode izin workload (boleh sama atau berbeda dengan Jalur BE) | berkas BE-4 chatbot; bagian E-10 di `8_1.md` | `frontend/`; berkas BE-1..BE-3 helpdesk dan DBV-028 |
| **Chatbot FE** (E-10, MAKE-095) | ditunjuk lewat kode izin workload (boleh sama atau berbeda dengan Jalur FE) | berkas FE chatbot; bagian E-10 di `8_2.md` | `backend/`; berkas FE helpdesk (`features/halo-simpeg/*` di luar `chatbot/`) |

Titik temu: kontrak API beku (§6.1, §6.5) dan fixture JSON sintetis respons API yang dipakai bersama sebagai bahan test FE; perubahan kontrak
setelah beku = CR yang disepakati kedua jalur lewat reviewer. Untuk chatbot (◻): Chatbot BE menghasilkan contoh respons di
`backend/tests/_support/HaloSimpeg/Chatbot/*.json`; Chatbot FE **menyalin** berkas itu (bukan merujuk lintas folder, karena
test FE tidak membaca `backend/`) ke `frontend/src/features/halo-simpeg/chatbot/__tests__/fixtures/` dan menyalin ulang bila
kontrak berubah lewat CR. **Jalur chatbot terpisah dari helpdesk:** tidak memakai tabel,
service, model, route file, atau komponen helpdesk dan sebaliknya; satu-satunya kesamaan adalah folder induk `HaloSimpeg`/`halo-simpeg`.
Berkas bersama yang disentuh keduanya (`Config/Routing.php` untuk mendaftarkan route file, `nav.config.ts`, `RedesignShell.vue`,
`frontend/src/router/index.ts`, `backend/docs/progress/08-HaloSimpeg.md`) diubah aditif dengan commit terpisah; bila dua jalur berjalan bersamaan, yang
merge kedua me-rebase dari `origin/main` dan reviewer memeriksa konflik di berkas bersama.

**Berkas BERSAMA — hanya ADITIF, commit TERPISAH:** `Config/Routing.php` (satu baris pendaftaran per route file baru:
`Config/RoutesHaloSimpeg.php` milik BE, `Config/RoutesChatbot.php` milik BE-4), `Config/Services.php`, `Config/Queue.php` (`$jobHandlers`), `Config/Filters.php`,
`.env.example` (chatbot: nama kunci token feedback F8-35 tanpa nilai), `README-deploy.md` (chatbot: pemeriksaan kunci F8-35),
`backend/docs/progress/08-HaloSimpeg.md`, `frontend/src/router/index.ts`, `frontend/src/shared/layouts/nav.config.ts`,
`frontend/src/shared/layouts/RedesignShell.vue`, `frontend/src/features/auth/stores/auth.store.ts` (chatbot: satu panggilan
reset di `clearSession()`).

**DILARANG disentuh:** migration yang sudah di `main` (termasuk `CreateDisiplinTicketFase4`, `CreateMasterLayananFase4`);
`Libraries/Layanan/Notifikasi*` dan model `Inbox*` (rumah notifikasi approval); kontrak `Interfaces/PushNotifGatewayInterface.php`;
`Config/Database.php`; `MigrationsDitahan/AddFkNipLayananFase4.php` (keputusan FK di DBV-028, bukan edit berkas lama); berkas legacy rahasia (§Cara pakai 6).
Untuk E-10 juga: modul FAQ (`Controllers/Api/MasterData/FaqController.php`, `Libraries/MasterData/Faq*`), `Libraries/Html/HtmlSanitizer.php`,
`shared/components/SafeHtml.vue`, `shared/utils/sanitizeHtml.ts`, dan `Libraries/Kepegawaian/Pegawai/EksporTabel.php` **dipakai, tidak diubah**
(kebutuhan perubahan → CR lewat reviewer).

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
| Vanya | Nama dan persona chatbot "asisten virtual SDMO" (K-2) [K dev `dev:views/hr/chatbot/chatbot_popup.php:67-82`]; widget melayang di pojok kanan bawah untuk pegawai |
| QnA | Satu pasangan pertanyaan + jawaban (`qna`) milik satu kategori (`qna_kategori`) dengan nol atau lebih tag (`qna_tag` lewat `qna_has_tag`); sumber pengetahuan Vanya |
| Unggulan / opsi alternatif | QnA bertanda `is_fallback_option = 1`: tampil di tombol "Populer", sebagai saran saat tidak cocok, dan di urutan teratas bubble pertanyaan [K dev `dev:libraries/hr/L_chatbot.php:597-632`] |
| Bubble | Tombol pilihan di dalam widget: **bubble topik** = kategori bertanda `tampil_bubble` yang punya QnA aktif; **bubble pertanyaan** = maks 6 pertanyaan dalam satu kategori; jawaban dari bubble pertanyaan diambil langsung (skor 1,0) tanpa pencocokan |
| TF-IDF + cosine | Pencocokan teks: tiap QnA aktif menjadi dokumen (pertanyaan + nama tag), bobot kata = frekuensi kata × log(jumlah dokumen / dokumen yang memuat kata), lalu kemiripan sudut (cosine) antara vektor pesan pengguna dan vektor tiap QnA [K dev `dev:libraries/Nlp_engine.php:19-104`] |
| Cocok / fallback | Pesan **cocok** bila skor cosine asli kandidat teratas ≥ ambang (0,25); bila tidak, Vanya menjawab kalimat fallback + saran QnA unggulan dan jawaban itu tidak bisa dinilai |
| Token feedback | Tanda tangan HMAC per `id_log` yang dikembalikan bersama jawaban dan wajib dikirim saat memberi 👍/👎 (K-4, F8-35); pengganti daftar `id_log` di session PHP legacy dev |

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

### 5.2 Keputusan Fase 8 (LOCKED — Horii 03-11-2026 dan 12-11-2026; ✔ Q8-xx / ✔ K-n = dijawab eksplisit di §12, ✔ (default diterima) = usulan tanpa pertanyaan yang tidak ditolak, ◻ = bawaan usulan reviewer yang menunggu konfirmasi)

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

**E-10 Chatbot "Vanya" (v1.1).** F8-23..F8-33 = keputusan Horii 12-11-2026 ("ikut saran" K-1..K-11, §12). F8-34..F8-51 =
bawaan teknis usulan reviewer (◻), berlaku bila tidak ditolak saat review PRD v1.1; rincian perilaku di §7.10.

| ID | Keputusan | Label | Status |
|---|---|---|---|
| F8-23 | **Chatbot masuk Fase 8** sebagai task baru **E-10 Chatbot "Vanya"** (revisi PRD v1.1, key revisi CR-121). Baris lama "Chatbot / pencarian FAQ terintegrasi — tidak ada di legacy — usulan masa depan" di §1.2 diganti (bahan legacy dev tersedia 12-11-2026) | — | ✔ K-1 |
| F8-24 | **Nama & persona tetap "Vanya"** ("asisten virtual SDMO") dengan avatar ilustrasi SVG legacy dev; teks sapaan, kartu hover, dan kalimat fallback ikut dev | [K dev `dev:views/hr/chatbot/chatbot_popup.php:29-82`, `:371`; `dev:libraries/hr/L_chatbot.php:746`] | ✔ K-2 |
| F8-25 | **Skema = DBV terpisah DBV-033**: 6 tabel `qna`, `qna_kategori`, `qna_tag`, `qna_has_tag`, `qna_status`, `log_chatbot` ikut DDL dev hasil D1 chatbot (PN8-5); status **1 Aktif / 2 Tidak Aktif / 10 Dihapus** (memperbaiki A1: di dev Tidak Aktif = Dihapus = 2). Kolom final ditetapkan DBV-033 sesudah D1; DBV-028 helpdesk tidak tertahan | [K dev `dev:libraries/hr/L_chatbot.php:48-50`, `:124`; DDL D1 blok [1]–[2]]+[V2] | ✔ K-3 |
| F8-26 | **`log_chatbot` anonim** seperti dev (tanpa `id_pengguna`/NIP). Kepemilikan feedback 👍/👎 dijaga **token bertanda tangan per log** (pengganti session PHP, A7) yang dikembalikan di respons balas dan wajib dikirim saat beri feedback; `id_pengguna` tidak disimpan di tabel. Rincian token: F8-35 | [K dev `dev:libraries/hr/L_chatbot.php:749-756`]+[V2] | ✔ K-4 (rincian ◻ F8-35) |
| F8-27 | **Isi QnA awal bergantung D1 chatbot**: bila isi dev layak (D1 blok [4]), dibawa ke v2 lewat impor sekali jalan (CSV blok [7] → perintah impor sekali jalan dalam runbook cutover, aturan F8-46; CSV dan isi QnA dev tidak di-commit); bila tidak, v2 mulai kosong dan diisi admin (form/impor Excel). Ditetapkan Horii **saat deploy/cutover** (PN8-6; ditunda Horii 12-11-2026, D1 sudah lengkap) | — | ✔ K-5, **ditunda ke deploy** |
| F8-28 | **Jawaban berformat HTML dibatasi whitelist** seperti FAQ v2: sanitasi saat simpan di BE + render lewat `SafeHtml`/DOMPurify di FE (BP8-14, A2) | [K dev `dev:views/hr/chatbot/chatbot_popup.php:325` (jawaban HTML)]+[V2] keamanan | ✔ K-6 |
| F8-29 | **Ekspor hanya daftar tag** (ikut dev); tanpa ekspor QnA | [K dev `dev:libraries/hr/L_chatbot.php:216-252`] | ✔ K-7 |
| F8-30 | **Widget hanya untuk role 2/6/7** (`Role::UL_PEGAWAI`): widget tidak dirender untuk role lain dan endpoint widget menolak role lain (403) | [K dev `dev:controllers/hr/Chatbot.php:215-243`; `dev:views/hr/chatbot/chatbot_popup.php:5`] | ✔ K-8 |
| F8-31 | **Chatbot berdiri sendiri**: tanpa tombol "Tanya petugas"/buat tiket Halo Simpeg; integrasi itu usulan lanjutan di luar scope (§1.2) | [K dev `dev:controllers/hr/Chatbot.php:214-243` (tidak ada aksi tiket)] | ✔ K-9 |
| F8-32 | **Admin Chatbot Manager = role 1 saja** (Kategori, Tag, QnA, impor/ekspor, Statistik, Riwayat) | [K dev `dev:controllers/hr/Chatbot.php:58-211`] | ✔ K-10 |
| F8-33 | **Desain ikut tampilan widget legacy dev**, disesuaikan token desain v2, tanpa menunggu Figma (dicatat seperti PN8-4); halaman admin ikut pola tabel v2 (`page-fill` + ⋮) | [K dev `dev:views/hr/chatbot/chatbot_popup.php:29-100`, `:102-205`]+[V2] | ✔ K-11 |
| F8-34 | **Mesin jawab port apa adanya** (§7.10.2): tokenisasi, TF-IDF, cosine, ambang dari skor asli, pengali feedback hanya untuk kandidat lolos ambang, bubble langsung skor 1,0, fallback 3 QnA unggulan terbaru. Semua angka di `Config\Chatbot` (ambang 0,25; bobot feedback 0,10; smoothing 5; maks bubble pertanyaan 6; jumlah populer 3; kandidat dicatat 3; maks pesan 300) dan diuji dengan test pembanding hasil legacy dev. Kandidat berskor sama diurutkan `id_qna` menaik. Semantik tokenisasi ikut dev persis, termasuk token `"0"` ikut terbuang dan kesetaraan token angka mengikuti perbandingan longgar `==` PHP (§7.10.2 langkah 4; alternatif perbandingan ketat = pertanyaan §12). Batas 300 dihitung `mb_strlen` UTF-8 sesudah trim | [K dev]+[I] (urutan seri di dev bergantung urutan hasil `GROUP BY` dan `usort`, `dev:libraries/hr/L_chatbot.php:679-685`, `dev:libraries/Nlp_engine.php:99-101`) | ◻ |
| F8-35 | **Rincian token feedback (K-4)**: token = `base64url(kedaluwarsa) . '.' . base64url(HMAC-SHA256(kunci, 'vanya-fb\|v1\|' + id_log + '\|' + id_pengguna + '\|' + kedaluwarsa))`; `kedaluwarsa` = detik Unix (bilangan bulat, UTC); kunci khusus di `.env` (nama kunci di `.env.example` tanpa nilai, minimal 32 byte, tidak memakai ulang rahasia JWT); masa berlaku 24 jam; diverifikasi dengan `hash_equals`; token yang tidak terurai, diubah, kedaluwarsa, atau milik akun lain → 403 yang sama (§6.5); token dan kunci tidak pernah dicatat. Nilai boleh ditimpa selama token berlaku [K dev `dev:libraries/hr/L_chatbot.php:799-800`]; hanya log berstatus cocok yang dapat dinilai [K dev `:791`]. Kunci kosong atau kurang dari 32 byte = **tidak tersedia** → balas tetap jalan dengan `bisa_feedback = false` tanpa `token_feedback`, feedback → 403, dan peringatan di log aplikasi; pemeriksaan kunci ini masuk daftar periksa `README-deploy.md` agar salah konfigurasi tidak mematikan penilaian diam-diam. Dev membatasi 50 `id_log` terakhir per session (`:577`, `:662-670`); batas itu digantikan masa berlaku token. Token hanya menjaga kepemilikan log, bukan satu suara per pengguna per QnA (R8-17) | [K dev]+[V2] | ◻ |
| F8-36 | **Throttle per akun** (A5) lewat `Services::throttler()` pola `DokumenService::wajibThrottle`: balas 20 per menit, feedback 30 per menit, endpoint baca widget 60 per menit sebagai **kuota gabungan** ketiga endpoint baca (`widget/kategori`, `widget/kategori/{id}/pertanyaan`, `widget/populer`; satu kunci throttle per akun); tiga kelompok itu dihitung terpisah; melebihi → 429 berbahasa Indonesia; permintaan balas yang kena 429 tidak menulis `log_chatbot`; angka di `Config\Chatbot` | [V2] keamanan | ◻ |
| F8-37 | **Batas impor Excel** (A4): hanya `.xlsx` (template dev `.xlsx`, pembaca Xlsx `dev:libraries/hr/L_chatbot.php:413`); maks 2 MB dan 1.000 baris data; berkas diperiksa dengan PhpSpreadsheet (bukan MIME browser); satu transaksi semua-atau-tidak [K dev `:417-465`] dengan laporan galat per baris (nomor baris + alasan). Baris judul harus sama dengan template F8-47 (4 kolom, urutan sama); berbeda → 422 "Format template tidak sesuai." (teks usulan) tanpa memproses baris [V2]. Baris tanpa pertanyaan atau jawaban dilewati dan dilaporkan [K dev `:425`]; baris tanpa kategori ditolak [V2, konsisten dengan form `:318`]; kategori/tag dicocokkan berdasarkan judul dengan aturan F8-40 (trim, abaikan huruf besar/kecil) di antara status 1/2, termasuk kategori/tag yang baru dibuat oleh baris sebelumnya di berkas yang sama (satu judul = satu id); judul yang cocok dengan kategori/tag **Tidak Aktif** → baris ditolak [V2]; yang belum ada dibuat otomatis berstatus 1 (kategori memakai DEFAULT `tampil_bubble = 0` DDL dev [K dev D1]; dev tidak mengisinya eksplisit `:434`) [K dev `:428-459`]; daftar tag satu baris dipecah koma, di-trim, yang kosong dibuang [K dev `:449`], lalu **dideduplikasi** tanpa membedakan huruf besar/kecil [V2; dev menyisipkan relasi ganda `:488-496`, yang akan melanggar UNIQUE `qna_has_tag` v2]; QnA impor `is_fallback_option = 0` [K dev `:439-445`]; jawaban disanitasi (BP8-14) | [K dev]+[V2] | ◻ |
| F8-38 | **Status & hapus**: Hapus = set status 10 (hapus lunak; dev "mengarsipkan" `dev:libraries/hr/L_chatbot.php:120-127`, `:207-214`, `:370-377`); daftar admin menampilkan status 1 dan 2 dengan filter status (bawaan Aktif, ikut dev `dev:views/hr/chatbot/list_qna.php:25-29`); baris status 10 disembunyikan dan **tanpa Pulihkan** (dev tidak punya). Baris status 10 tidak ikut widget, pencocokan impor, maupun ekspor; relasi `qna_has_tag` tidak dihapus saat tag dihapus lunak (perlakuannya di QnA: F8-49). Aktifkan/Nonaktifkan lewat endpoint `PATCH …/{id}/status` terpisah (§6.5) | [K dev]+[V2] | ◻ |
| F8-39 | **Korpus**: QnA status 1 [K dev `dev:libraries/hr/L_chatbot.php:683`] dengan tag **status 1 saja** [V2; dev menggabungkan tag berstatus apa pun, A10]. QnA aktif di kategori Tidak Aktif **atau Dihapus** **tetap** ikut korpus ketikan bebas (bubble topik menyembunyikan kategorinya) [K dev: korpus tidak menyaring kategori `dev:libraries/hr/L_chatbot.php:679-685`; hapus kategori hanya mengubah status kategori `:120-127`] | [K dev]+[V2] | ◻ |
| F8-40 | **Judul kategori dan judul tag unik** (trim, abaikan huruf besar/kecil sesuai collation `_ci`) di antara status 1 dan 2, ditegakkan **aplikasi** di dalam transaksi tulis (422) (A11). Judul yang sama dengan baris status 10 **boleh** dipakai lagi (baris baru), sehingga UNIQUE biasa atas `judul` **tidak** dipakai (akan memblokir judul yang pernah dihapus dan memicu galat 1062 saat impor). Opsi indeks DB untuk DBV-033: kolom bangkitan, mis. `judul_unik = IF(status <> 10, judul, NULL)` + UNIQUE. D1: data dev memuat 2 judul kategori kembar dan 11 judul tag kembar; saat impor isi dev keduanya **digabung** (F8-46). **Keputusan Horii 12-11-2026 (K-12): judul kembar digabung saat impor, lalu judul dibuat unik** | [V2] | ✔ K-12 |
| F8-41 | **Validasi & batas panjang**: pesan jalur ketikan wajib, 1..300 karakter (`mb_strlen` UTF-8 sesudah trim), ditegakkan BE [V2; dev hanya `maxlength` HTML `dev:views/hr/chatbot/chatbot_popup.php:95`, A14]; jalur bubble: F8-48; pertanyaan, judul, dan jawaban mengikuti tipe kolom DDL dev (`pertanyaan`/`jawaban` TEXT, `judul_kategori` varchar(150), `judul_tag` varchar(100) [K dev D1]; final DBV-033); jawaban yang kosong setelah sanitasi ditolak 422 (pola `FaqArticleHooks`); kategori QnA wajib dan berstatus 1, tag yang **ditambahkan** berstatus 1 [K dev `dev:libraries/hr/L_chatbot.php:318`, `:472-478`] (kategori/tag lama yang sudah terpasang: F8-49); simpan/ubah QnA + relasi tag dalam satu transaksi, ubah = ganti seluruh set tag [V2; dev tanpa transaksi `:323-333`, `:352-365`, A13] | [K dev]+[V2] | ◻ |
| F8-42 | **Riwayat & statistik** (role 1): riwayat dengan paginasi server, urut waktu terbaru, filter rentang tanggal, status cocok, dan feedback (A6); statistik = KPI dan tiga tabel top-10 ikut dev (`dev:libraries/hr/L_chatbot.php:502-555`); "hari ini" dan filter tanggal = hari kalender WIB yang dikonversi ke rentang UTC (BP8-8, tanpa `DATE()` atas kolom, A8); tanpa ekspor riwayat; tanpa penghapusan log otomatis [K dev] | [K dev]+[V2] | ◻ |
| F8-43 | **Penempatan kode & menu**: route `api/v1/halo-simpeg/chatbot/...` di `Config/RoutesChatbot.php` (didaftarkan di `Config/Routing.php:34-59`); folder `Chatbot` di bawah `Controllers/Api/HaloSimpeg`, `Libraries/HaloSimpeg`, `Models/HaloSimpeg`, dan `features/halo-simpeg`; menu admin Pengaturan → "Chatbot Manager" (Kategori, Tag, QnA, Statistik, Riwayat) role 1 `phase: 8`; route halaman admin di `features/halo-simpeg/chatbot/routes.chatbot.ts` (satu baris import di `frontend/src/router/index.ts`, pola `routes.*` per fitur `:13-25`); widget dipasang sekali di `shared/layouts/RedesignShell.vue` dengan syarat render F8-30 + `!auth.mustChangePassword` (pola `NotifikasiPushShell` `RedesignShell.vue:186`; selama wajib ganti password semua route non-`jwt:ganti_password` ditolak 403, `backend/app/Filters/JwtAuthFilter.php:49`) + gerbang rilis F8-50 | [V2] pola | ◻ |
| F8-44 | **Kontrak widget** memakai amplop v2 (bukan `status: 'sukses'/'kosong'` dev): daftar kosong = 200 dengan `data: []`; pesan jalur ketikan kosong/lebih dari 300 → 422 (F8-41, F8-48); korpus QnA kosong → 200 dengan balasan sistem "Database QnA masih kosong.", `cocok = false`, tanpa baris log [K dev `dev:libraries/hr/L_chatbot.php:687-689`] | [K dev]+[V2] | ◻ |
| F8-45 | **Avatar**: ilustrasi SVG dev di-port sebagai komponen Vue; foto opsional = aset statis FE yang diserahkan PN8-7 (bukan unggah lewat aplikasi, tanpa pemeriksaan berkas saat runtime seperti `dev:views/hr/chatbot/chatbot_popup.php:19-27`); foto gagal dimuat → kembali ke SVG [K dev `:396-411`] | [K dev]+[V2] | ◻ |
| F8-46 | **Aturan impor isi dev** (hanya bila F8-27 membawa isi): **QnA** — hanya baris berstatus 1 yang dibawa (status 2 dev bisa berarti Tidak Aktif atau Dihapus, A1); QnA tanpa kategori (`id_qna_kategori_fk` NULL dimungkinkan DDL dev [K dev D1] dan impor dev, A13) **dilaporkan dan tidak dibawa** sampai Horii/admin menetapkan kategorinya (alternatif: kategori penampung, pertanyaan §12). **Kategori/tag** — status 1 dibawa sebagai status 1; status 2 dev yang **masih dipakai QnA aktif** dibawa sebagai status 2 (Tidak Aktif) agar QnA itu tetap terjawab seperti di dev (F8-39) dan relasinya tidak yatim; status 2 lainnya tidak dibawa. Relasi tag yatim/ganda dibuang dan dilaporkan; jawaban disanitasi dan baris yang berubah oleh sanitasi dilaporkan; isi disaring sesuai §Cara pakai 6 sebelum impor (kontak/URL internal/nama nyata dilaporkan ke Horii, D1 blok [6]); `id` dev tidak wajib dipertahankan karena `log_chatbot` tidak dimigrasi. **Pelaksanaan:** perintah impor sekali jalan (bukan seeder) yang menerima path CSV di **luar repo** sebagai argumen saat cutover; CSV dan isi QnA dev tidak pernah di-commit; test memakai CSV sintetis; satu transaksi semua-atau-tidak; menolak berjalan bila tabel QnA sudah berisi (tidak menggandakan isi). **Keputusan Horii 12-11-2026:** (K-12) judul kategori/tag kembar **digabung** ke satu baris (baris aktif ber-id terkecil dipertahankan, relasi QnA dari baris lain dialihkan ke sana, relasi yang jadi ganda dibuang); (K-13) kategori uji/nonaktif (mis. "test…") **tidak dibawa**, kategori **aktif** yang belum punya QnA **tetap dibawa** agar admin bisa mengisinya. Angka D1: relasi ganda 1, QnA tanpa kategori 0 | [V2] | ✔ K-12/K-13 (sisa rincian ◻) |
| F8-47 | **Ekspor tag & template impor**: xlsx lewat `EksporTabel::xlsx` (anti formula injection), kolom No, Tag, Status [K dev `dev:libraries/hr/L_chatbot.php:227-236`]; status 1 dan 2 saja [V2; dev mengekspor semua status `:217-221`]; nama berkas `Export_Tag_QnA_{Ymd_His}.xlsx` (waktu WIB) [K dev `:245`]. Template impor 4 kolom "Pertanyaan", "Jawaban", "Kategori", "Tag (Pisahkan dengan koma)" + satu baris contoh [K dev `:379-403`] | [K dev]+[V2] | ◻ |
| F8-48 | **Jalur bubble & isi log**: bila `id_qna` ada di korpus, BE memakai `pertanyaan` QnA dari DB sebagai `pesan_user` di log dan **mengabaikan** teks `pesan` klien (hasil sama dengan dev pada pemakaian normal, karena widget dev mengirim teks pertanyaan itu sendiri `dev:views/hr/chatbot/chatbot_popup.php:319-327`, `:341`); batas 1..300 (F8-41) **hanya** untuk jalur ketikan, sehingga bubble/saran untuk QnA berpertanyaan panjang (`pertanyaan` TEXT [K dev D1]) tetap dijawab. `id_qna` tidak sah → jalur ketikan dengan validasi F8-41. `pertanyaan` **tidak** dibatasi 300 di form/impor (alternatif = pertanyaan §12) | [K dev]+[V2] | ◻ |
| F8-49 | **QnA yang induknya berubah status**: (a) tag status 2 yang terpasang tampil di daftar/form admin berbadge "Tidak Aktif" dan **dipertahankan** saat QnA disimpan bila tidak dilepas admin (dev membuangnya diam-diam karena form hanya memuat tag aktif `dev:views/hr/chatbot/form_qna.php:54-61`) [V2]; (b) tag status 10 tidak tampil di admin dan relasinya diabaikan (tetap di DB, tidak dibaca korpus/daftar) [V2, A10]; (c) kategori status 2 milik QnA boleh dipertahankan saat ubah (tampil berbadge "Tidak Aktif"); mengganti kategori hanya ke status 1; (d) kategori status 10 tampil di daftar admin dengan penanda "Dihapus" dan menyimpan QnA tanpa mengganti kategorinya → 422 "Kategori QnA sudah dihapus. Pilih kategori lain." (teks usulan) [V2]; (e) tag status 2/10 tidak menambah skor korpus (F8-39) | [K dev]+[V2] | ◻ |
| F8-50 | **Gerbang rilis menu & widget** (`ACTIVE_PHASE` = 5 di `frontend/src/shared/layouts/nav.config.ts:94`; semantik `rilis` `:120-124`): bawaan = pola lonceng F-08 (`RedesignShell.vue:110-118`): item menu Chatbot Manager `phase: 8` **tanpa** `rilis` (QA lewat URL langsung, pola F7-14) dan widget dirender hanya bila `ACTIVE_PHASE >= 8` (konstanta fase chatbot di fitur), sehingga menu dan widget dibuka bersamaan. Alternatif: menu `rilis: true` dan widget ikut tampil saat MAKE-095 masuk `main` (pola Integrasi `:293`). Siapa yang menaikkan `ACTIVE_PHASE` (atau menghapus `rilis`) = pertanyaan §12 | [V2] pola | ◻ |
| F8-51 | **Key C4** (impor isi QnA dev): C4 adalah milestone tersendiri (menunggu PN8-6, bisa jauh sesudah C2 merge), sehingga mengikuti §2.1 "satu milestone = satu key = satu branch": bawaan = key MAKE tersendiri yang ditetapkan reviewer saat PN8-6 diputus (nomor berikutnya yang kosong), branch `fase8/<key>-impor-isi-qna`. Alternatif: bila PN8-6 diputus sebelum C2 merge, C4 menjadi bagian akhir branch MAKE-094 | — | ◻ |

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

### 6.3 Frontend (E-08, E-10)

Rute Halo Simpeg dipasang di `nav.config.ts` (item `halo`, `phase: 8` → aktif) dengan `roles` 1–7. Mengganti `halo.store.ts`
(memori, branch redesign) menjadi store/service API (ADR-021/022). Tampilan:

| Tampilan | Pengguna | Isi |
|---|---|---|
| Drawer / halaman pegawai (Gambar 33) | pemilik | daftar tiket saya + form tiket baru (kategori, subjek, pesan, lampiran) + thread; badge status Open/Closed |
| Inbox petugas (Gambar 34) | petugas | antrean tiket kategorinya (kiri), thread + balasan (kanan), tutup/hapus lewat menu ⋮, "Ingatkan 24 jam" |
| Admin: Kategori, Petugas, Kontak | role 1 | tabel `page-fill` + ⋮ (Edit, Aktifkan/Nonaktifkan, Hapus); tanpa frame Figma → ikut pola Master Data/PIC layanan dan dicatat (AGENTS.md §3.6) |
| **Widget Vanya** (E-10) | role 2/6/7 (K-8) | tombol avatar melayang di pojok kanan bawah semua halaman ber-layout (`RedesignShell.vue`), kecuali selama sesi wajib ganti password (F8-43); kartu sapaan saat hover/fokus, ditambah peek otomatis sekali per sesi browser untuk semua perangkat [K dev `dev:views/hr/chatbot/chatbot_popup.php:413-422`]; jendela chat: salam, bubble topik → bubble pertanyaan, tombol "Topik" dan "Populer", kotak ketik maks 300 karakter, 👍/👎 untuk jawaban cocok; tampilan ikut dev disesuaikan token v2 (K-11) |
| **Admin: Chatbot Manager** (E-10) | role 1 (K-10) | Kategori (judul, status, "Bubble Vanya"), Tag (judul, status, tombol ekspor `UiExportMenu`), QnA (kategori, tag multi, opsi alternatif, status, pertanyaan, jawaban HTML + pratinjau `SafeHtml`; tombol "Unduh template" dan "Impor Excel" di level halaman), Statistik (KPI + tiga tabel top-10), Riwayat (tabel berhalaman, filter tanggal/status cocok/feedback, tanpa aksi baris). Tabel `page-fill` + ⋮ (Edit → Aktifkan/Nonaktifkan → Hapus) |

Menu E-10 (◻ F8-43): Pengaturan → **"Chatbot Manager"** (role 1, `phase: 8`) dengan submenu Kategori, Tag, QnA, Statistik, Riwayat
(nama menu ikut dev `dev:controllers/hr/Chatbot.php:39`). Widget tidak memakai item menu; komponen dipasang sekali di
`RedesignShell.vue` (`<main id="main-content">` di `frontend/src/shared/layouts/RedesignShell.vue:188`) dan dirender hanya bila role sesi ∈
`UL_PEGAWAI` **dan** `!auth.mustChangePassword` (pola `NotifikasiPushShell` `:186`), dengan gerbang rilis F8-50. Kerangka redesign `67fa7678` tidak memuat chatbot; di `main` `241ef9e5` folder `features/halo-simpeg/` baru berisi
README + `.gitkeep`.

Kerangka tampilan berupa **data contoh** ada di branch `feat/frontend-ui-redesign` (commit `67fa7678`: `halo.store.ts`, `types.ts`,
`components/HaloDrawer.vue`, `views/AdminHaloPage.vue`) dan **belum ada di `main`** (di `main` hanya README modul dan item menu `halo`
ber-`phase: 8` di `nav.config.ts:262` pada basis `f7b9221e`; di `241ef9e5` item itu ada di `:301`). Kerangka itu mengasumsikan satu percakapan per pengguna dan admin = role 1 & 3 (`types.ts`, "ASUMSI");
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

### 6.5 Endpoint chatbot E-10 (prefix `api/v1/halo-simpeg/chatbot`, amplop v2, filter `jwt` + `role`)

Semua **tulis memakai method non-GET** (dev menghapus lewat GET tanpa CSRF, A3; BP8-7); ubah status lewat `PATCH …/{id}/status`
terpisah dari PUT payload penuh (pola v2). Kontrak dibekukan di README
`Controllers/Api/HaloSimpeg/Chatbot` (MAKE-094) sebelum MAKE-095 mulai; nama path final dicocokkan saat M0.

| Path (relatif prefix) | Method | Role route | Isi / aturan | Rujukan dev |
|---|---|---|---|---|
| `widget/kategori` | GET | `UL_PEGAWAI` | bubble topik: kategori status 1, `tampil_bubble = 1`, punya ≥ 1 QnA status 1; urut id menaik | `dev:libraries/hr/L_chatbot.php:580-593` |
| `widget/kategori/{id}/pertanyaan` | GET | `UL_PEGAWAI` | maks 6 QnA status 1 di kategori itu: unggulan dulu, lalu paling sering cocok, lalu id menaik; `id` ≤ 0 / tidak ada → `data: []` | `:597-616` |
| `widget/populer` | GET | `UL_PEGAWAI` | 3 QnA unggulan status 1 terbaru (id menurun) | `:619-632` |
| `widget/balas` | POST | `UL_PEGAWAI` | masuk `{pesan, id_qna?}` (`pesan` diabaikan bila `id_qna` sah, F8-48); keluar `{balasan, cocok, skor, id_log, bisa_feedback, token_feedback?, saran[]}`; `balasan` = jawaban HTML tersanitasi atau teks sistem; menulis satu baris `log_chatbot`; throttle (F8-36) | `:674-769`; `dev:controllers/hr/Chatbot.php:230-235` |
| `widget/feedback` | POST | `UL_PEGAWAI` | masuk `{id_log, nilai: 1\|-1, token}`; token tidak terurai/salah/kedaluwarsa/bukan milik akun, atau kunci tidak tersedia (F8-35) → 403 "Sesi penilaian tidak ditemukan. Silakan ajukan pertanyaan lagi."; log tidak cocok → 422 "Jawaban ini tidak dapat dinilai." | `:774-812` |
| `kategori` | GET, POST | 1 | daftar berhalaman (filter status 1/2, `q`), tambah (judul, `tampil_bubble`; status awal 1) | `:46-98` |
| `kategori/{id}` | GET, PUT, DELETE | 1 | detail; ubah (judul, status 1/2, `tampil_bubble`); hapus = status 10 (F8-38) | `:69-127` |
| `kategori/{id}/status` | PATCH | 1 | masuk `{status: 1\|2}`; dipakai aksi ⋮ Aktifkan/Nonaktifkan; diaudit (◻, pola `backend/app/Config/Routes.php:71`, `RoutesKonten.php:60`) | `:100-118` |
| `tag` | GET, POST | 1 | daftar berhalaman (urut id menurun), tambah | `:133-186` |
| `tag/{id}` | GET, PUT, DELETE | 1 | detail; ubah (judul, status 1/2); hapus = status 10 | `:158-214` |
| `tag/{id}/status` | PATCH | 1 | masuk `{status: 1\|2}`; aksi ⋮ Aktifkan/Nonaktifkan; diaudit (◻) | `:188-205` |
| `tag/ekspor` | GET `?format=xlsx` | 1 | xlsx daftar tag (F8-47); format lain → 422 | `:216-252` |
| `qna` | GET, POST | 1 | daftar berhalaman (urut id menurun; filter status, kategori, tag, `q`) dengan nama kategori dan tag; tambah (F8-41) | `:258-336` |
| `qna/{id}` | GET, PUT, DELETE | 1 | detail (+ tag, berpenanda status F8-49); ubah (ganti set tag atomik); hapus = status 10 | `:293-377` |
| `qna/{id}/status` | PATCH | 1 | masuk `{status: 1\|2}`; aksi ⋮ Aktifkan/Nonaktifkan tanpa mengirim ulang jawaban/tag (tanpa sanitasi ulang dan tanpa ganti relasi); diaudit (◻) | `:338-368` |
| `qna/opsi` | GET | 1 | kategori dan tag status 1 untuk form QnA | `:472-478` |
| `qna/template` | GET | 1 | template impor xlsx (F8-47) | `:379-403` |
| `qna/impor` | POST (multipart) | 1 | berkas `.xlsx` (F8-37); keluar ringkasan `{ditambah, kategori_baru, tag_baru, dilewati[], galat[]}`; ada galat → tidak ada baris tersimpan | `:405-468` |
| `statistik` | GET | 1 | `{kpi, kepuasan, top_pesan, top_qna_cocok, top_qna_negatif}` (§7.10.5) | `:502-555` |
| `riwayat` | GET `?dari&sampai&cocok&feedback&page` | 1 | log berhalaman, urut waktu terbaru, dengan pertanyaan QnA teratas (F8-42) | `:557-567` |

Role lain → 403 dari filter route. Pesan galat berbahasa Indonesia mengikuti teks dev bila ada.

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

### 7.10 E-10 — Chatbot "Vanya" (DBV-033 skema, MAKE-094 BE, MAKE-095 FE)

Keputusan: F8-23..F8-33 (✔ K-1..K-11); bawaan teknis: F8-34..F8-51 (◻). Singkatan rujukan legacy dev (relatif `application/`
bahan dev): `dev:CB` = `controllers/hr/Chatbot.php` (246 baris), `dev:LC` = `libraries/hr/L_chatbot.php` (823 baris), `dev:NE` =
`libraries/Nlp_engine.php` (105 baris), `dev:VP` = `views/hr/chatbot/chatbot_popup.php` (424 baris), `dev:V/<berkas>` = view admin
lain di `views/hr/chatbot/`. Label **[K dev]** = ikut legacy dev.

#### 7.10.1 Skema (DBV-033)

Kolom di bawah dipakai kode dev dan **terkonfirmasi DDL DB dev** hasil D1 chatbot blok [1] (12-11-2026) **[K dev D1]**; blok
[4]–[6] (sebaran isi, integritas relasi, bentuk jawaban) menyusul. Kolom final, tipe, indeks, dan default tetap **ditetapkan
DBV-033** di `backend/docs/db-review/DBV-033-*.md` (tanpa ✔ di PRD ini). Nama tabel/kolom ikut dev agar impor isi (F8-27) lurus.
Isi dev (D1 blok [3]): 274 QnA (271 aktif, 3 unggulan), 60 kategori (56 aktif, 4 tampil bubble), 696 tag, 827 relasi, 74 log uji.

| Tabel | Kolom DDL dev [K dev D1] (pemakaian di kode dev) | Catatan v2 (keputusan DBV-033) |
|---|---|---|
| `qna_status` | `id_qna_status` int unsigned PK, `judul_status` varchar(50) (`dev:LC:48-50`) | Isi baku 1 Aktif, 2 Tidak Aktif, 10 Dihapus [V2, A1]; dev berisi 1 Aktif, 2 Nonaktif (D1 blok [2]) |
| `qna_kategori` | `id_qna_kategori` int unsigned PK, `judul_kategori` varchar(150), `status_qna_kategori_fk` DEFAULT 1, `tampil_bubble` tinyint(1) DEFAULT 0 (`dev:LC:91-95`, `:584-585`) | FK status → `qna_status`; unik judul F8-40 (tanpa UNIQUE biasa); `created_at`/`updated_at` untuk audit (tidak ada di dev) |
| `qna_tag` | `id_qna_tag` int unsigned PK, `judul_tag` varchar(100), `status_qna_tag_fk` DEFAULT 1 (`dev:LC:180-183`) | FK status; unik judul F8-40 |
| `qna` | `id_qna` int unsigned PK, `pertanyaan` TEXT, `jawaban` TEXT (HTML), `id_qna_kategori_fk` **NULL**, `status_qna_fk` DEFAULT 1, `is_fallback_option` tinyint(1) DEFAULT 0, `created_at`/`updated_at` datetime NULL (`dev:LC:323-330`, `:353-360`) | FK kategori dan status. Kategori **nullable di dev**: v2 tetap mewajibkannya di aplikasi untuk QnA baru (F8-41); NOT NULL atau nullable diputus DBV-033 bersama perlakuan QnA tanpa kategori saat impor isi (F8-46). Indeks `(status_qna_fk, id_qna_kategori_fk)` |
| `qna_has_tag` | `id_qna_has_tag` int unsigned PK, `qna_id`, `qna_tag_id`; FK ke `qna` dan `qna_tag` **ON DELETE CASCADE**; tanpa UNIQUE (`dev:LC:493`, `:363`) | UNIQUE `(qna_id, qna_tag_id)` setelah pra-cek ganda D1 blok [5]; `id_qna_has_tag` dipertahankan atau dibuang diputus DBV-033; aturan FK (CASCADE dev vs RESTRICT) diputus DBV-033 (hapus di v2 = status 10, bukan hapus fisik) |
| `log_chatbot` | `id_log` int **signed** PK, `pesan_user` TEXT, `top_1_id_qna` int NOT NULL tanpa FK, `top_1_skor` **FLOAT**, `top_3_kandidat` longtext (JSON), `status_match` tinyint NULL, `feedback` tinyint NULL, `waktu_interaksi` datetime DEFAULT CURRENT_TIMESTAMP; KEY `idx_log_qna_feedback (top_1_id_qna, status_match, feedback)` (`dev:LC:749-756`, `:800`) | **Anonim** (K-4): tanpa kolom identitas (sama dengan dev); waktu UTC diisi aplikasi (BP8-8, bukan DEFAULT DB); indeks `waktu_interaksi` (tidak ada di dev) + indeks ranking feedback (pembanding: indeks dev `idx_log_qna_feedback`, yang juga melayani rekap feedback `dev:LC:635-659` dan bubble pertanyaan `:597-616`; usulan awal `(status_match, top_1_id_qna)`); CHECK domain `status_match`/`feedback`. **◻ untuk DBV-033:** `top_1_skor` DECIMAL(5,4) (pembulatan 4 desimal terjaga saat dibaca/AVG) atau FLOAT ikut dev; `id_log` unsigned atau signed ikut dev |

Konvensi v2: collation `utf8mb4_unicode_ci` (dev: lima tabel `utf8mb4_0900_ai_ci`, `log_chatbot` `utf8mb4_unicode_ci`), status
1/2/10, migration baru (tidak mengedit migration di `main`), label [K dev D1]/[V2]/[I] per kolom di dokumen DBV. Tidak ada FK ke
tabel helpdesk atau FAQ.

#### 7.10.2 Mesin jawab [K dev] (`dev:NE:7-104`, `dev:LC:573-769`)

1. **Masukan:** `id_qna` opsional (dari bubble). Bila `id_qna` ada di korpus → langkah 3 (teks `pesan` klien diabaikan, F8-48).
   Selain itu `pesan` di-trim; kosong atau lebih dari 300 karakter (`mb_strlen` UTF-8) → 422 (F8-41).
2. **Korpus:** semua QnA status 1; teks dokumen = `pertanyaan` + spasi + nama tag (status 1, F8-39) digabung spasi
   (`dev:LC:679-685`). Korpus kosong → F8-44. Catatan port: dev menggabungkan tag dengan `GROUP_CONCAT` tanpa dedup, sehingga
   relasi ganda di dev mengulang nama tag (menaikkan TF) dan teks panjang bisa terpotong batas `group_concat_max_len`; v2 dengan
   UNIQUE relasi tidak mengulang tag dan tidak memotong teks (beda yang diharapkan, dikecualikan dari test pembanding).
3. **Jalur bubble:** bila `id_qna` ada di korpus → jawaban QnA itu, skor 1,0, cocok, kandidat `[{id_qna, skor: 1.0, skor_akhir: 1.0}]`,
   tanpa ranking (`dev:LC:696-705`); `pesan_user` di log = `pertanyaan` QnA itu (F8-48). Bila tidak ada di korpus → lanjut ke
   jalur ketikan memakai teks `pesan` (validasi langkah 1).
4. **Tokenisasi** (`dev:NE:7-16`): `strtolower(trim())` (huruf ASCII saja), buang semua karakter selain `a-z`, `0-9`, dan spasi,
   pecah per satu atau lebih spasi, lalu `array_filter` tanpa callback: buang token kosong **dan token `"0"`** (juga
   `!empty($kata)` `dev:NE:36`). **Tanpa** stemming dan stopword; huruf non-ASCII ikut terbuang; tanda baca yang menyambung kata
   ikut terbuang sehingga kata tersambung (mis. "cuti/izin" → "cutiizin"). **Kesetaraan token** di dev memakai perbandingan
   longgar PHP (`in_array` `dev:NE:36`, `:48`; `==` `:62`): dua token string numerik dianggap kata yang sama bila nilainya sama
   (mis. "01" = "1", "007" = "7", "1e1" = "10"); token lain dibandingkan sebagai string biasa. Bawaan ◻: ditiru (kunci peta
   untuk token numerik dinormalisasi ke nilai numeriknya); alternatif perbandingan ketat = deviasi [V2] (pertanyaan §12).
   Masukan dev juga melewati `xss_clean` sebelum dicocokkan dan dicatat (`dev:CB:232`); v2 tidak (A12), sehingga hasil untuk
   masukan ber-HTML bisa berbeda (beda yang diharapkan).
5. **TF-IDF** (`dev:NE:19-67`): dokumen = korpus + pesan pengguna (pesan ikut dihitung sebagai dokumen ke-N); kosakata = token unik
   seluruh dokumen; `idf = ln(N / df)`; `tf = kemunculan / jumlah token dokumen` (0 bila dokumen tanpa token); bobot = `tf × idf`.
6. **Cosine** (`dev:NE:69-96`): `dot / (|pesan| × |qna|)`, 0 bila salah satu magnitudo 0.
7. **Ranking** (`dev:LC:707-738`): `skor_akhir = skor × pengali`; `pengali = 1` bila belum ada feedback, selain itu
   `1 + 0,10 × (up − down) / (up + down + 5)` dengan up/down = jumlah 👍/👎 pada log cocok yang top-1-nya QnA itu (`dev:LC:635-659`).
   Kandidat **lolos** = skor **asli** ≥ 0,25, diurutkan `skor_akhir` menurun; tidak lolos diurutkan skor asli menurun; urutan akhir =
   lolos lalu tidak lolos; seri → `id_qna` menaik (F8-34). Teratas = urutan pertama; **cocok** bila ada yang lolos. Tiga teratas
   dicatat dengan skor dan `skor_akhir` dibulatkan 4 desimal.
8. **Balasan** (`dev:LC:740-747`): cocok → `jawaban`; tidak cocok → "Maaf, Vanya belum memahami pertanyaan Anda. Mungkin salah satu
   pertanyaan berikut yang Anda maksud?" + `saran` = QnA populer (maks 3).
9. **Log** (`dev:LC:749-757`): satu baris per pesan: `pesan_user` (teks ketikan; jalur bubble = `pertanyaan` QnA, F8-48), `top_1_id_qna` (kandidat teratas, juga saat
   tidak cocok), `top_1_skor` = skor **asli** 4 desimal, `top_3_kandidat` JSON, `status_match`, `waktu_interaksi` (UTC).
10. **Respons** (`dev:LC:760-768`): `skor` 4 desimal, `id_log`, `bisa_feedback` = cocok **dan** kunci token tersedia (F8-35),
   `token_feedback` hanya bila `bisa_feedback`.

Implementasi boleh mengganti pencarian `in_array` dengan struktur data efisien (mis. peta hash, A9) yang **mempertahankan
semantik langkah 4** (token `"0"` terbuang, kesetaraan token numerik); rumus dan angka tidak diubah tanpa keputusan baru. Angka di `Config\Chatbot` (F8-34). Kelas mesin murni (tanpa DB) agar bisa diuji unit.

#### 7.10.3 Widget Vanya (MAKE-095) [K dev `dev:VP`]

- **Pemasangan:** sekali di `RedesignShell.vue`, hanya untuk role `UL_PEGAWAI` (F8-30) **dan** bila `!auth.mustChangePassword`
  (F8-43; selama wajib ganti password semua panggilan widget akan 403), dengan gerbang rilis F8-50; posisi tetap kanan bawah, di atas konten,
  tidak menutupi tombol aksi halaman pada layar sempit (`dev:VP:104`, `:196-200`).
- **Launcher:** tombol avatar ber-`aria-label` "Chat dengan Vanya, asisten virtual SDMO" + titik "online" (`dev:VP:67-70`); kartu
  sapaan "Halo, Saya Vanya, asisten virtual SDMO, klik untuk mulai chat!" saat hover/fokus, ditambah **peek otomatis sekali per
  sesi browser untuk semua perangkat** (`sessionStorage`, dibungkus try/catch; dev tidak mendeteksi layar sentuh, hanya
  komentarnya yang menyebut layar sentuh) [K dev `dev:VP:73-75`, `:413-422`].
- **Jendela chat:** dialog berlabel "Chat dengan Vanya", kepala "Vanya · Asisten Virtual SDMO · Online", tombol tutup, isi
  `aria-live="polite"` (`dev:VP:77-99`). Buka pertama kali: salam pembuka (`dev:VP:371`) lalu bubble topik; tanpa topik → populer
  tanpa pesan galat (`dev:VP:356-362`). Fokus ke kotak ketik saat buka; **Escape** menutup (`dev:VP:374`, `:384`) dan fokus kembali
  ke launcher [V2 aksesibilitas].
- **Alur:** pilih topik → pesan pengguna = judul topik, lalu bubble pertanyaan atau "Belum ada pertanyaan pada topik ini."
  (`dev:VP:336-343`); pilih pertanyaan → kirim teks pertanyaan + `id_qna` (`dev:VP:341`); tombol "Topik" dan "Populer"
  (`dev:VP:90-93`, `:345-354`); kirim ketikan (trim, kosong diabaikan, maks 300) (`dev:VP:388-394`); indikator mengetik; saran
  sebagai bubble (`dev:VP:319-334`).
- **Render:** jawaban lewat `SafeHtml` (bukan `innerHTML`, A2); pesan pengguna dan teks sistem sebagai teks (`dev:VP:253-261`).
- **Penilaian:** "Apakah jawaban ini membantu?" + 👍/👎 hanya bila `bisa_feedback`; sesudah menilai tampil teks terima kasih dan
  tombol lain hilang; gagal → pesan server + "Coba lagi" (`dev:VP:283-314`). Tombol ber-`aria-label` + `UiTooltip`, bukan atribut
  `title` (dev `dev:VP:286`, `:288`; AGENTS.md §3).
- **Galat:** "Maaf, Vanya sedang tidak dapat memuat data. Silakan coba beberapa saat lagi." dan "Maaf, terjadi gangguan koneksi.
  Silakan coba lagi." (`dev:VP:317`, `:332`); 429 → pesan throttle dari server.
- **Riwayat percakapan widget** hanya di memori, tidak disimpan di `localStorage`. `RedesignShell` dipasang per halaman sebagai
  pembungkus route (mis. `frontend/src/features/auth/views/ChangePasswordPage.vue:9`), sehingga isi percakapan dan status buka/tutup
  disimpan di store fitur chatbot agar tidak hilang saat pindah halaman, dan dibersihkan saat muat ulang atau keluar (◻ [V2] UX;
  di dev percakapan hilang setiap pindah halaman karena halaman dimuat ulang). Pembersihan saat keluar/ganti akun dipanggil dari
  `clearSession()` di `frontend/src/features/auth/stores/auth.store.ts` (pola `resetSesiKonten()` `:49-54`), mengosongkan pesan,
  status buka, dan token feedback, sehingga pengguna berikutnya di browser yang sama tidak melihat percakapan sebelumnya (BP8-15).
- **Avatar:** F8-45. Gerak dikurangi untuk `prefers-reduced-motion` (`dev:VP:201-204`).

#### 7.10.4 Admin Chatbot Manager (MAKE-094 API, MAKE-095 layar) [K dev `dev:CB:57-211`]

- **Kategori:** judul wajib ("Judul kategori tidak boleh kosong!"), `tampil_bubble` Ya/Tidak dengan petunjuk dev
  (`dev:V/form_kategori.php:41-50`), status 1/2 saat ubah; kolom tabel No, Kategori, Bubble, Status (`dev:V/list_kategori.php:39-46`).
- **Tag:** judul wajib, status 1/2; kolom No, Tag, Status; urut id menurun (`dev:LC:139`); ekspor F8-47.
- **QnA:** kategori wajib (status 1), tag multi (status 1; `shared/ui` di `241ef9e5` belum punya kontrol pilihan ganda —
  `UiSelect`/`UiCombobox` tunggal — sehingga bawaan ◻ = daftar `UiCheckbox` dengan pencarian, atau komponen baru lewat reviewer
  sesuai AGENTS.md §3), "Opsi Alternatif" Ya/Tidak dengan petunjuk
  "Maks. 3 QnA unggulan" yang **tidak** ditegakkan (dev juga tidak; populer mengambil 3 terbaru) (`dev:V/form_qna.php:67-77`),
  pertanyaan wajib, jawaban wajib berformat HTML (textarea HTML + pratinjau `SafeHtml`, pola field `html` FAQ
  `frontend/src/features/master-data/components/MasterFormDialog.vue:463-470`); kolom No, Kategori, Tag, Pertanyaan, Opsi
  Alternatif, Status (`dev:V/list_qna.php:36-46`). Ubah QnA mengganti set tag dalam satu transaksi (F8-41). Kategori/tag
  terpasang yang Tidak Aktif atau Dihapus ditampilkan sesuai F8-49 (badge "Tidak Aktif"; kategori "Dihapus"; tag status 10 tidak tampil).
- **Impor & template:** tombol level halaman "Unduh template" dan "Impor Excel" (dialog `UiFileField` + ringkasan hasil) (F8-37, F8-47).
- **Aksi baris (AGENTS.md §1):** ⋮ Edit → Aktifkan/Nonaktifkan (lewat `PATCH …/{id}/status`, §6.5) → Hapus (`danger`, `ConfirmDialog`); Status hanya badge; `testid`
  `chatbot-kategori-actions-<id>`, `chatbot-tag-actions-<id>`, `chatbot-qna-actions-<id>`; label "Aksi untuk <judul/pertanyaan>".
- **Pesan sukses/galat** ikut teks dev bila ada (mis. "Kategori baru berhasil ditambahkan.", "QnA baru berhasil ditambahkan.",
  "Data QnA beserta Kategori dan Tag berhasil diimpor!", `dev:LC:97`, `:335`, `:467`).
- **Audit:** tulis kategori/tag/QnA/relasi lewat `BaseAuditableModel` → `audit_logs` (dev tanpa jejak); `log_chatbot` **tidak**
  memakai model beraudit (BP8-15).

#### 7.10.5 Statistik & Riwayat (MAKE-094 API, MAKE-095 layar) [K dev `dev:LC:502-567`]

- **KPI:** total chat, chat hari ini (hari WIB, F8-42), rata-rata skor semua log dan log cocok (4 desimal); **kepuasan** dari log
  cocok: membantu, tidak membantu, total, persen puas (1 desimal; 0 bila belum ada penilaian) (`dev:LC:503-541`).
- **Tabel:** top 10 pesan paling sering diketik (dikelompokkan per teks pesan); top 10 QnA paling sering cocok; top 10 QnA dengan 👎
  terbanyak (hanya yang 👎 > 0, urut 👎 menurun); QnA yang sudah tidak ada ditampilkan sebagai `#id` (`dev:LC:526-551`,
  `dev:V/dashboard_statistik.php:44-110`). Tampilan memakai `UiStatTile` + tabel; semua teks ter-escape.
- **Riwayat:** kolom Waktu (WIB), Pesan pengguna, QnA teratas (pertanyaan kandidat teratas; "(Tidak ada kecocokan)" bila kosong),
  Skor, Status ("Cocok"/"Fallback"; dev "Match"/"Fallback"), Feedback (ikon + teks untuk pembaca layar) (`dev:V/list_log.php:11-43`);
  paginasi dan filter di server (F8-42).

#### 7.10.6 Kriteria penerimaan

**AC-E10.1** `backend/docs/db-review/DBV-033-*.md` (dengan acuan DDL D1) di-approve DBV; **AC-E10.2** migration naik/turun bersih di
DB scratch, tes skema menegaskan FK, UNIQUE, CHECK status 1/2/10, collation `utf8mb4_unicode_ci` keenam tabel, serta indeks
`waktu_interaksi` dan indeks ranking feedback `log_chatbot`; tabel helpdesk/FAQ tidak berubah; **AC-E10.3** test pembanding:
untuk fixture korpus dan pesan sintetis dengan skenario minimum §7.10.7, top-1, status cocok, skor (4 desimal), dan tiga kandidat
sama dengan hasil mesin dev (kecuali beda yang diharapkan yang dicatat di §7.10.2);
**AC-E10.4** skor tepat 0,25 dianggap cocok; feedback tidak meloloskan kandidat di bawah ambang tetapi dapat mengubah urutan kandidat
yang lolos; **AC-E10.5** jalur bubble mengembalikan jawaban QnA itu dengan skor 1,0; `id_qna` nonaktif/tidak ada → jalur ketikan;
**AC-E10.6** pesan tidak cocok → kalimat fallback + maks 3 saran populer, `bisa_feedback = false`, log `status_match = 0`;
**AC-E10.7** setiap balas menulis tepat satu baris `log_chatbot` tanpa identitas pengirim; korpus kosong tidak menulis log;
**AC-E10.8** feedback dengan token sah tersimpan dan dapat ditimpa; token akun lain, token diubah, token tidak terurai, atau token
kedaluwarsa → 403; log tidak cocok → 422; pada semua penolakan baris log tidak berubah; kunci token tidak tersedia (kosong atau
kurang dari 32 byte) → balas tetap 200 dengan `bisa_feedback = false` tanpa `token_feedback`, dan feedback → 403;
**AC-E10.9** endpoint widget hanya role 2/6/7 dan endpoint admin hanya role 1 (role lain 403); **AC-E10.10** throttle per
kelompok: balas melebihi 20/menit, feedback melebihi 30/menit, dan gabungan endpoint baca widget melebihi 60/menit masing-masing
→ 429 dan kelompok lain tidak terpengaruh; balas yang kena 429 tidak menambah baris `log_chatbot`; **AC-E10.11** pesan jalur
ketikan kosong atau lebih dari 300 karakter (`mb_strlen` sesudah trim; 300 huruf beraksen/emoji = lolos) → 422;
**AC-E10.12** jawaban yang memuat `<script>`, atribut `on*`, atau `javascript:` tersimpan bersih lewat form maupun impor, dan widget
tidak menghasilkan skrip/handler di DOM; **AC-E10.13** Nonaktifkan (lewat `PATCH …/status`) → baris tetap di daftar admin
berbadge "Tidak Aktif" (A1), jawaban/relasi tag QnA tidak berubah; Hapus → status 10, hilang dari daftar, widget, pencocokan
impor, dan ekspor; **AC-E10.14** bubble topik hanya kategori aktif ber-
`tampil_bubble` yang punya QnA aktif; bubble pertanyaan maks 6 dengan urutan unggulan → paling sering cocok → id; populer = 3
unggulan terbaru; **AC-E10.15** impor: kategori/tag baru dibuat (kategori `tampil_bubble = 0`, tag status 1), judul yang sudah ada
dipakai ulang tanpa membedakan huruf besar/kecil dan spasi tepi (termasuk judul baru yang muncul dua kali di satu berkas = satu id),
tag ganda dalam satu baris (mis. "cuti, Cuti") menjadi satu relasi, QnA impor `is_fallback_option = 0`; baris tanpa pertanyaan atau
jawaban dilewati dan dilaporkan; baris tanpa kategori atau yang judulnya cocok dengan kategori/tag Tidak Aktif → galat baris;
satu baris galat → tidak ada baris tersimpan + laporan per baris; judul kolom tidak sesuai template, bukan xlsx, lebih dari 2 MB,
lebih dari 1.000 baris, atau berkas rusak → 422;
**AC-E10.16** ekspor tag xlsx: kolom dan nama berkas sesuai F8-47, status 10 tidak ikut, nilai berawalan `=` tidak menjadi rumus;
**AC-E10.17** statistik sesuai fixture: total chat dan chat hari ini (hari WIB: log 00:00 dan 23:59 WIB ikut, 23:59 WIB hari
sebelumnya tidak), rata-rata skor semua log dan log cocok 4 desimal, kepuasan (membantu, tidak membantu, total, persen puas 1
desimal; 0 bila belum ada penilaian), top-10 pesan, top-10 QnA cocok, top-10 QnA 👎 (hanya 👎 > 0, urut 👎 menurun), QnA yang sudah
tidak ada tampil `#id`, semua teks ter-escape; **AC-E10.18** riwayat terpaginasi dan tersaring di server; filter `dari`/`sampai`
memakai hari WIB inklusif yang dikonversi ke rentang UTC (log 00:00 dan 23:59 WIB tanggal batas ikut; log 23:30 WIB tidak bergeser ke hari
berikutnya); pesan pengguna tampil sebagai teks; **AC-E10.19** semua tulis admin tercatat di `audit_logs`; `log_chatbot` dan isi pesan tidak masuk
`audit_logs` maupun log aplikasi; **AC-E10.20** widget: dapat dioperasikan keyboard (buka, pilih bubble, kirim, nilai, Escape),
ARIA benar, tidak dirender untuk role di luar `UL_PEGAWAI`, saat sesi wajib ganti password, atau sebelum gerbang rilis F8-50
terbuka; sesudah logout atau login akun lain store chatbot kosong (pesan, status buka, token) dan widget hilang bila role sesi baru
di luar `UL_PEGAWAI`; kartu sapaan tetap berfungsi (tanpa galat) bila `sessionStorage` melempar galat; **AC-E10.21** halaman admin `page-fill` + ⋮ dengan urutan dan label
baku, Hapus lewat `ConfirmDialog`; tidak ada `v-html` di luar `SafeHtml`, `<select>`/`window.confirm` native, atau `title` sebagai
tooltip; **AC-E10.22** QA Lapis 1 chatbot (reviewer) dibandingkan dengan acuan visual widget dev (PN8-8, K-11), butir: launcher +
kartu sapaan/peek, salam pembuka, bubble topik → bubble pertanyaan, "Topik"/"Populer", fallback + saran, penilaian 👍/👎 + "Coba
lagi", galat koneksi/429, layar sempit, `prefers-reduced-motion`, dan lima halaman admin (Kategori, Tag, QnA + impor,
Statistik, Riwayat); **AC-E10.23** regresi FAQ dan helpdesk tetap hijau (dijalankan kedua jalur chatbot di gate masing-masing dan
diperiksa reviewer di M7).

**AC admin & impor tambahan (v1.1, ◻ F8-40/41/48/49/46):**
**AC-E10.24** keunikan judul kategori/tag: judul ganda di antara status 1/2 (beda huruf besar/kecil atau spasi tepi) → 422; judul
yang sama dengan baris status 10 boleh dipakai lagi (baris baru), lewat form maupun impor, tanpa galat DB;
**AC-E10.25** validasi: judul kategori/tag wajib; pertanyaan dan jawaban QnA wajib; QnA baru tanpa kategori, dengan kategori baru yang tidak aktif, atau
dengan tag tambahan yang tidak aktif → 422; jawaban yang kosong setelah sanitasi → 422; gagal sisip relasi tag → QnA tidak
tersimpan (rollback); ubah set tag mengganti seluruhnya; **AC-E10.26** daftar kategori/tag/QnA admin terpaginasi dan tersaring di
server (status 1/2, kategori, tag, `q`), status 10 tidak tampil; **AC-E10.27** status induk (F8-49): tag status 2/10 tidak menambah
skor korpus; QnA di kategori Tidak Aktif atau Dihapus tetap terjawab lewat ketikan tetapi bubble topiknya tidak tampil; ubah QnA
mempertahankan tag status 2 yang tidak dilepas dan mengabaikan tag status 10; kategori status 10 yang tidak diganti → 422;
**AC-E10.28** `GET qna/template` mengembalikan xlsx dengan empat judul kolom F8-47 berurutan dan satu baris contoh;
**AC-E10.29** jalur bubble (F8-48): QnA berpertanyaan lebih dari 300 karakter tetap dijawab lewat bubble/saran; teks `pesan` klien
pada jalur bubble tidak masuk log (`pesan_user` = `pertanyaan` QnA); **AC-E10.30** impor isi dev (C4, hanya bila F8-27 membawa
isi): dengan CSV sintetis, hanya QnA status 1 dibawa; kategori/tag status 2 yang masih dipakai QnA aktif dibawa sebagai status 2;
QnA tanpa kategori, relasi yatim/ganda dilaporkan dan tidak dibawa; jawaban disanitasi dan baris yang berubah dilaporkan; satu
transaksi; perintah menolak berjalan bila tabel QnA sudah berisi; repo tidak memuat CSV/isi dev.

#### 7.10.7 Test

- **BE (MAKE-094):** unit test mesin murni dengan fixture pembanding di `backend/tests/_support/HaloSimpeg/Chatbot/` (nilai harapan
  dihasilkan sekali dari mesin dev di luar repo, toleransi 0,0001; kode dev tidak masuk repo; siapa dan bagaimana menjalankan mesin
  dev = pertanyaan §12); test fitur per endpoint (`extends Tests\Support\DatabaseTestCase`); test token (sah, akun lain, diubah,
  tidak terurai, kedaluwarsa, kunci kosong/pendek); test impor dengan berkas xlsx sintetis yang dibuat di dalam test; test impor isi
  dev C4 dengan CSV sintetis; test ekspor dan template; test throttle per kelompok; contoh respons JSON sintetis di
  `backend/tests/_support/HaloSimpeg/Chatbot/*.json` untuk fixture FE (disalin FE, §3).
- **Skenario minimum fixture pembanding (AC-E10.3, ◻):** (1) kecocokan biasa di atas ambang; (2) skor tepat 0,25; (3) huruf
  non-ASCII/beraksen terbuang; (4) pesan yang hanya berisi tanda baca (lolos validasi, token kosong → magnitudo 0 → fallback, tetap
  dicatat); (5) tanda baca penyambung kata ("cuti/izin"); (6) token angka "0", "01", "1", "007"/"7"; (7) skor seri (urut `id_qna`,
  F8-34); (8) pengali feedback yang membalik urutan dua kandidat lolos; (9) kandidat di bawah ambang dengan feedback positif (tetap
  tidak lolos); (10) korpus satu dokumen (`ln(N/df)` dengan pesan sebagai dokumen ke-N); (11) nama tag yang menambah skor; (12) QnA
  dengan tag ganda di dev (beda yang diharapkan, §7.10.2 langkah 2); (13) masukan ber-HTML (beda yang diharapkan karena dev memakai
  `xss_clean`, dicatat, tidak dibandingkan).
- **FE (MAKE-095):** Vitest widget (alur topik → pertanyaan → jawaban → penilaian, fallback + saran, galat/429, keyboard + ARIA,
  tidak tampil untuk role lain atau saat wajib ganti password, store kosong sesudah logout, `sessionStorage` melempar galat, render
  lewat `SafeHtml`); Vitest halaman admin (menu ⋮ dipilih lewat `data-action`, ConfirmDialog, galat 422 per kolom, impor +
  ringkasan, badge status induk F8-49); test klien API asli (bukan hanya mock; pelajaran amplop axios) dengan fixture di
  `frontend/src/features/halo-simpeg/chatbot/__tests__/fixtures/` (salinan contoh respons BE, §3).

---

## 8. Aturan lintas modul

- **Pegawai/riwayat:** snapshot jabatan/satker pada tiket dibaca dari jabatan aktif (`pegawai_mutasi_jabatan` v2), tidak ditulis balik.
- **RBAC:** role kode mengikuti `App\Constants\Role`; tidak ada role baru. Petugas bukan role, melainkan penugasan data (`support_officer`).
- **Notifikasi Fase 4:** tidak diubah (BP8-1). **Lonceng F-08:** hanya mengonsumsi `ringkasan` bila Q8-06 = B.
- **Fase 6:** token, adapter, antrean, log = milik Fase 6 (§6.4). Perubahan kontrak yang dibutuhkan Fase 8 = CR baru yang disepakati lewat reviewer.
- **Impor/cutover:** urutan: pemetaan id pengguna (UP-1) → master (F8-21) → tiket & pesan (F-02) → berkas lampiran (path `attachments`).
- **Privasi:** tidak ada pencatatan isi pesan di log aplikasi/queue; `audit_logs` menyimpan metadata + snapshot `before` hanya untuk hapus.
- **Chatbot ↔ helpdesk (E-10):** tidak berbagi tabel, service, atau route; chatbot tidak membuat tiket dan tidak menulis
  `disiplin_ticket(_msg)`, `inbox(_msg)`, atau push (K-9). Badge/ringkasan Halo Simpeg tidak menghitung apa pun dari chatbot.
- **Chatbot ↔ FAQ (G-10):** dua sumber konten terpisah; chatbot memakai ulang `HtmlSanitizer` dan `SafeHtml` FAQ tanpa mengubahnya;
  tidak ada sinkronisasi isi FAQ ↔ QnA [K dev].
- **Chatbot ↔ impor/cutover:** isi QnA dev hanya dibawa bila F8-27 menetapkan demikian, lewat langkah runbook terpisah dari impor
  helpdesk, dengan perintah impor sekali jalan yang membaca CSV di luar repo (F8-46); `log_chatbot` dev tidak dimigrasi (§1.2).

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
| PRD v1.1 di `main` (CR-121) | Koding E-10 dan DBV-033 | gerbang §0.1 |
| D1 chatbot (PN8-5) | DBV-033 | DDL acuan; bukan prasyarat PRD |
| DBV-033 | MAKE-094 → MAKE-095 | MAKE-095 menunggu kontrak API MAKE-094 beku (§6.5) |
| Keputusan isi K-5 (PN8-6) | C4 (perintah impor isi QnA) dan impor saat cutover | C4 = koding kecil sesudah C2; impor = cutover |
| Acuan visual widget dev (PN8-8) | QA Lapis 1 chatbot (AC-E10.22) | bukan koding |
| DBV-028 / MAKE-070..074 / MAKE-066 / F-08 | — | **tidak** ada ketergantungan dengan E-10 (dua arah) |

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
| M7 | Reviewer: QA Lapis 1 Fase 8 (termasuk chatbot, AC-E10.22/23), laporan akhir → ⛔ sign-off Horii (penutup seluruh development) | — | M6, C3, dan C4 bila F8-27 membawa isi dev |

Sub-jalur **chatbot E-10** (paralel dengan M1–M6; pelaksana lewat kode izin workload):

| M | Isi | Key | Menunggu |
|---|---|---|---|
| C0 | Laporan pemahaman E-10 ⛔ reviewer (termasuk cek ulang rujukan `dev:`) | — | PRD v1.1 |
| C1 | Skema chatbot (PR `[DBV]`), DDL acuan dari D1 chatbot | DBV-033 | C0; D1 chatbot (PN8-5) |
| C2 | BE chatbot: mesin jawab + test pembanding, endpoint widget, CRUD admin (+ `PATCH …/status`), impor/template, ekspor tag, statistik, riwayat, throttle, token; kontrak API dibekukan di README `Controllers/Api/HaloSimpeg/Chatbot`; README `Libraries/HaloSimpeg/Chatbot` dan `Models/HaloSimpeg/Chatbot`; bagian chatbot di `backend/docs/progress/08-HaloSimpeg.md`; pemeriksaan kunci token di `README-deploy.md` | MAKE-094 | C1 di `main` |
| C3 | FE chatbot: widget Vanya + halaman admin Chatbot Manager + menu; README `features/halo-simpeg/chatbot` | MAKE-095 | kontrak API C2 beku |
| C4 | (bila F8-27 membawa isi dev) perintah impor sekali jalan isi QnA dari CSV di luar repo (F8-46, AC-E10.30) + langkah runbook cutover isi QnA | key ◻ F8-51 (MAKE tersendiri dari reviewer; atau bagian akhir MAKE-094 bila PN8-6 diputus sebelum C2 merge) | C2; keputusan PN8-6 |

Setiap M: branch baru dari `origin/main` → gate cepat → push → CI `quality-gate` hijau → lapor → ⛔ merge oleh reviewer CR (izin Horii per paket).

## 11. Definition of Done & exit criteria

**Fase 8 DONE** bila: E-01..E-10 DONE (AC terpenuhi, di `main`; E-10 = DBV-033 + MAKE-094 + MAKE-095 (+ C4 bila F8-27 membawa isi dev), AC-E10.1..30); verifikasi "thread tidak ter-mix" (§7.8) hijau; regresi Fase 4
(notifikasi approval, C-05/C-06) dan FAQ (G-10) hijau; QA Lapis 1 Fase 8 lolos, termasuk widget dan Chatbot Manager; runbook cutover (impor master, pemetaan `id_user`, berkas
lampiran, dan isi QnA bila F8-27 membawa isi dev) tercatat; laporan akhir `docs/fase8/Laporan_Akhir_Fase8.md`; **sign-off Horii sebagai penutup seluruh development**.
Kartu Fase 8 dan kartu QA terkait di Trello disinkron. **Uji push real** tidak menahan sign-off tetapi
wajib lolos sebelum deploy (MT/DEPLOY, pola F6-23).

Format laporan milestone (blok kode): Status / Branch-SHA-CI / Berkas / Test (jumlah) / Berkas bersama / Deviasi / Blocker.

## 12. Register keputusan (v1.0 — dijawab Horii 03-11-2026: semua ikut saran; v1.1 — K-1..K-11 dijawab Horii 12-11-2026: semua ikut saran)

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

**E-10 Chatbot "Vanya" (v1.1).** Pertanyaan K-1..K-11 dari dokumen kerja reviewer `Mapping_Chatbot_Fase8.md` §6 (12-11-2026). Syarat
pada K-5 tetap berlaku.

| ID | Pertanyaan | Opsi | Saran | Keputusan |
|---|---|---|---|---|
| K-1 | **Penempatan chatbot** | **a** task baru E-10 di PRD Fase 8 (v1.1). **b** fase/paket tersendiri sesudah Fase 8 | **a** (Fase 8 belum mulai koding) | **Ikut saran (Horii 12-11-2026).** F8-23; key revisi CR-121 |
| K-2 | **Nama & persona** | **a** tetap "Vanya" + avatar legacy dev. **b** nama netral "Chatbot SIMPEG" | **a** | **Ikut saran (Horii 12-11-2026).** F8-24 |
| K-3 | **Skema** | **a** DBV terpisah (DBV-033) agar DBV-028 tidak tertahan D1 chatbot. **b** digabung ke DBV-028 | **a** | **Ikut saran (Horii 12-11-2026).** F8-25 |
| K-4 | **Identitas di `log_chatbot`** | **a** anonim seperti legacy dev + kepemilikan feedback lewat token bertanda tangan per log. **b** simpan `id_pengguna` | **a** | **Ikut saran (Horii 12-11-2026).** F8-26; rincian token = bawaan ◻ F8-35 |
| K-5 | **Isi QnA awal** | **a** mulai kosong, diisi admin/impor Excel. **b** bawa isi QnA dari DB dev (D1) | bergantung D1: bila isi dev sudah dikurasi, **b** | **Ikut saran (Horii 12-11-2026).** F8-27. **Ditunda ke saat deploy** (Horii 12-11-2026, sesudah D1 lengkap; dicatat di Trello DEPLOY-002, PN8-6) |
| K-6 | **Jawaban berformat** | **a** HTML dibatasi whitelist seperti FAQ v2. **b** teks polos | **a** | **Ikut saran (Horii 12-11-2026).** F8-28, BP8-14 |
| K-7 | **Ekspor QnA** | **a** hanya ekspor tag seperti legacy dev. **b** tambah ekspor QnA | **a** | **Ikut saran (Horii 12-11-2026).** F8-29 |
| K-8 | **Siapa melihat widget** | **a** role 2/6/7 seperti legacy dev. **b** semua role yang login | **a** | **Ikut saran (Horii 12-11-2026).** F8-30 |
| K-9 | **Hubungan dengan Halo Simpeg** | **a** berdiri sendiri. **b** tombol "Tanya petugas" (buat tiket) bila tidak terjawab | **a**; **b** sebagai usulan lanjutan | **Ikut saran (Horii 12-11-2026).** F8-31; **b** dicatat di luar scope (§1.2) |
| K-10 | **Akses admin** | **a** role 1 saja. **b** role 1 + 3 | **a** | **Ikut saran (Horii 12-11-2026).** F8-32 |
| K-11 | **Desain** | **a** ikut tampilan widget legacy dev disesuaikan token v2, tanpa menunggu Figma. **b** tunggu frame Figma | **a** | **Ikut saran (Horii 12-11-2026).** F8-33 |
| K-12 | **Judul kategori/tag kembar** (D1: 2 kategori, 11 tag) | **a** dibiarkan seperti dev, tanpa judul unik. **b** digabung saat impor, lalu judul dibuat unik | **b** (impor Excel mencocokkan menurut judul) | **Ikut saran (Horii 12-11-2026).** F8-40, F8-46 |
| K-13 | **Kategori uji & kategori kosong** dari dev | **a** dibawa apa adanya. **b** kategori uji/nonaktif tidak dibawa; kategori aktif kosong tetap dibawa | **b** | **Ikut saran (Horii 12-11-2026).** F8-46 |

**Pertanyaan terbuka E-10 (◻ bawaan usulan reviewer, berlaku bila tidak ditolak saat review PRD v1.1).** Rincian di §5.2.

| ID | Pertanyaan | Bawaan ◻ |
|---|---|---|
| F8-34 | Port mesin jawab: angka di Config, urutan kandidat berskor sama? | Port apa adanya; seri → `id_qna` menaik |
| F8-34a | **Kesetaraan token angka**: dev memakai perbandingan longgar PHP sehingga "01" = "1" (§7.10.2 langkah 4). Tiru atau ketat? | Tiru (kunci numerik dinormalisasi), agar test pembanding berlaku penuh; alternatif: deviasi [V2] perbandingan ketat dan kasus angka dikecualikan dari test pembanding |
| F8-34b | **Siapa menjalankan mesin dev** untuk menghasilkan nilai harapan fixture pembanding (AC-E10.3), dan bagaimana? | Pelaksana MAKE-094 menjalankan `Nlp_engine.php` dev + salinan fungsi ranking `L_chatbot` dalam skrip di luar repo terhadap korpus sintetis; hanya angka hasilnya yang masuk fixture; reviewer memeriksa ulang satu skenario |
| F8-35 | **Token feedback (rincian K-4)**: skema tanda tangan, kunci, masa berlaku, perilaku saat kunci kosong? | HMAC-SHA256 berawalan konteks atas `id_log`/`id_pengguna`/kedaluwarsa (detik Unix); kunci khusus `.env` ≥ 32 byte; 24 jam; kunci tidak tersedia → feedback mati (403), balas tetap jalan, diperiksa di daftar periksa deploy |
| F8-36 | **Throttle** per akun (dev tanpa batas)? | Balas 20/menit, feedback 30/menit, baca widget 60/menit (kuota gabungan tiga endpoint baca) → 429; 429 tidak menulis log |
| F8-37 | **Batas impor Excel** (ukuran, baris, format, kategori kosong/nonaktif, judul kolom, tag ganda)? | `.xlsx` saja, 2 MB, 1.000 baris, judul kolom = template, semua-atau-tidak + laporan per baris; baris tanpa kategori atau ke kategori/tag nonaktif ditolak; judul dicocokkan tanpa membedakan huruf besar/kecil; tag ganda satu baris dideduplikasi |
| F8-38 | Perlakuan Hapus & Pulihkan (dev: Hapus = arsip, tanpa pulihkan)? | Hapus = status 10, disembunyikan, tanpa Pulihkan |
| F8-39 | Tag nonaktif ikut korpus? QnA di kategori nonaktif tetap dijawab? | Tag nonaktif tidak ikut; QnA di kategori nonaktif tetap ikut ketikan bebas [K dev] |
| F8-40 | Judul kategori/tag wajib unik (dev tidak)? Ditegakkan di mana? | Unik di antara status 1/2 (abaikan huruf besar/kecil), ditegakkan aplikasi; judul baris status 10 boleh dipakai lagi; indeks DB hanya lewat kolom bangkitan bila DBV-033 menghendaki |
| F8-41 | Batas 300 karakter di BE; transaksi simpan QnA + tag? | Ya, keduanya; 300 dihitung `mb_strlen`, hanya jalur ketikan |
| F8-42 | Riwayat/statistik: paginasi, zona waktu "hari ini", ekspor riwayat, retensi log? | Paginasi server; hari WIB; tanpa ekspor; tanpa penghapusan otomatis |
| F8-43 | Lokasi kode, route file, dan menu admin? | `Chatbot` di bawah `HaloSimpeg`; `Config/RoutesChatbot.php`; Pengaturan → "Chatbot Manager" |
| F8-44 | Bentuk respons widget (dev `status: sukses/kosong`)? | Amplop v2; daftar kosong `data: []`; korpus kosong 200 tanpa log |
| F8-45 | Foto avatar: unggah lewat aplikasi atau aset statis? | Aset statis FE (PN8-7); SVG bila tidak ada |
| F8-46 | Aturan impor isi dev bila K-5 membawa isi (status 2 dev ambigu; QnA tanpa kategori)? | QnA hanya status 1; kategori/tag status 2 yang masih dipakai QnA aktif dibawa sebagai status 2; relasi yatim/ganda dibuang + dilaporkan; perintah impor sekali jalan, CSV di luar repo |
| F8-46a | ~~**QnA dev tanpa kategori**~~ — **gugur**: D1 blok [5] = 0 QnA tanpa kategori (aturan tetap berlaku sebagai pengaman impor). Pertanyaan semula: dibuang+dilaporkan atau ditampung? | Dilaporkan dan tidak dibawa sampai Horii/admin menetapkan kategorinya; alternatif: dimasukkan ke satu kategori penampung (nama ditetapkan Horii, `tampil_bubble = 0`) |
| F8-47 | Ekspor tag: status yang ikut, format template? | Status 1/2; template 4 kolom dev; nama berkas `Export_Tag_QnA_{Ymd_His}.xlsx` |
| F8-48 | Jalur bubble: teks `pesan` klien dicatat apa adanya (dev) atau diganti `pertanyaan` QnA dari DB? Perlukah `pertanyaan` dibatasi 300? | Diganti `pertanyaan` dari DB; batas 300 hanya jalur ketikan; `pertanyaan` tidak dibatasi (alternatif: dibatasi ≤ 300 di form/impor) |
| F8-49 | QnA yang kategori/tagnya Tidak Aktif atau Dihapus: tampil, dipertahankan, atau ditolak saat ubah? | Tag status 2 tampil berbadge dan dipertahankan; tag status 10 diabaikan; kategori status 2 boleh dipertahankan; kategori status 10 wajib diganti (422) |
| F8-50 | **Gerbang rilis** menu Chatbot Manager dan widget selama `ACTIVE_PHASE` = 5; siapa yang menaikkan `ACTIVE_PHASE`/menghapus `rilis`? | Pola lonceng F-08: menu tanpa `rilis`, widget dijaga `ACTIVE_PHASE >= 8`; yang menaikkan `ACTIVE_PHASE` = keputusan Horii/reviewer di penutup rilis (di luar MAKE-095) |
| F8-51 | Key milestone C4 (impor isi QnA dev)? | Key MAKE tersendiri dari reviewer saat PN8-6 diputus; atau bagian akhir MAKE-094 bila PN8-6 diputus sebelum C2 merge |

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
| R8-10 | **Kualitas jawaban chatbot** terbatas: tanpa stemming/stopword dan huruf non-ASCII terbuang, sehingga "mengajukan" ≠ "ajukan" dan kata umum ikut berbobot | Vanya sering fallback atau salah cocok | Port apa adanya (F8-34) agar sama dengan dev; admin menambah tag sinonim; statistik "QnA dengan 👎 terbanyak" dan riwayat fallback dipakai mengkurasi isi; perbaikan mesin = keputusan baru di luar Fase 8 |
| R8-11 | **Performa mesin** O(QnA × kosakata) per pesan, korpus dan rekap feedback dibangun ulang tiap pesan (A9) | Respons lambat bila QnA bertambah ke ribuan | Struktur data efisien pengganti `in_array` yang mempertahankan semantik §7.10.2 langkah 4; throttle (F8-36); ukur waktu respons pada fixture ratusan QnA di test; cache korpus = usulan bila terbukti perlu |
| R8-12 | **XSS lewat konten QnA** (form/impor) atau teks lain di halaman admin (A2, A12) | Skrip berjalan di sesi pegawai/admin | BP8-14: sanitasi saat simpan + `SafeHtml`; teks lain ter-escape; AC-E10.12 |
| R8-13 | **Isi QnA dev belum dikurasi** (jawaban usang/salah, data uji) | Pegawai menerima informasi keliru | K-5 diputus saat deploy (PN8-6); kategori uji tidak dibawa (K-13); bila dibawa, Biro SDM meninjau sebelum cutover; bila ragu, mulai kosong |
| R8-14 | **Isi dev berbeda dari dugaan** (D1 blok [1]–[6] sudah diterima 12-11-2026, tetapi angka dev bisa berubah sampai deploy) | DBV-033 dan aturan impor isi (F8-46) tertahan | Tidak menahan PRD atau helpdesk; struktur sudah [K dev D1] di §7.10.1; selisih dicatat sebagai deviasi di DBV-033; aturan F8-46/F8-46a disesuaikan sesudah blok [4]/[5] |
| R8-15 | **Pesan bebas pegawai** di `log_chatbot` dapat memuat data pribadi yang diketik sendiri (NIP, nama) dan tampil di statistik/riwayat admin | Paparan data pribadi ke role 1 | Log anonim (K-4); hanya role 1; tidak ada ekspor riwayat; tidak dicatat di log aplikasi/audit (BP8-15); retensi = pertanyaan lanjutan bila Horii menghendaki |
| R8-16 | Bahan chatbot berasal dari **legacy dev** yang belum diuji di produksi | Perilaku dev mengandung bug yang ikut terbawa | Anomali A1..A16 (Lampiran B.2) diperlakukan eksplisit; test pembanding hanya untuk mesin jawab |
| R8-17 | **Penilaian berulang**: token hanya menjaga kepemilikan log, sehingga satu akun dapat meminta jawaban bubble yang sama berulang dan menilai tiap log; permintaan bubble juga menambah hitungan "paling sering cocok" (sama dengan dev) | Urutan bubble pertanyaan dan pengali feedback bisa digeser satu akun | Dibatasi throttle (F8-36) dan pengali feedback maks ±10% yang hanya berlaku bagi kandidat lolos ambang (F8-34); `pesan_user` jalur bubble diambil dari DB (F8-48); satu suara per akun per QnA = usulan lanjutan bila terbukti disalahgunakan (butuh identitas di log, bertentangan K-4) |

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

### A.2a Peta fitur chatbot "Vanya" (legacy dev → v2, E-10)

Tabel dev: `qna`, `qna_kategori`, `qna_tag`, `qna_has_tag`, `qna_status`, `log_chatbot` (DDL dev dari D1 chatbot blok [1], §7.10.1). Singkatan §7.10.

| Fitur | Legacy dev [K dev] | v2 |
|---|---|---|
| Widget pegawai (avatar, sapaan, jendela chat) | `dev:VP:29-205` (dimuat dari footer hanya untuk pegawai, `dev:VP:5`) | `features/halo-simpeg/chatbot`, dipasang di `RedesignShell.vue`; F8-24, F8-30, F8-33, F8-45 |
| Bubble topik | `dev:CB:215-218`, `dev:LC:580-593` | `GET widget/kategori` |
| Bubble pertanyaan per topik (maks 6) | `dev:CB:220-223`, `dev:LC:597-616` | `GET widget/kategori/{id}/pertanyaan` |
| Populer / saran fallback (3 unggulan terbaru) | `dev:CB:225-228`, `dev:LC:619-632` | `GET widget/populer` |
| Balas pesan (bubble langsung / TF-IDF + feedback) | `dev:CB:230-235`, `dev:LC:674-769`, `dev:NE:7-104` | `POST widget/balas`; §7.10.2; F8-34, F8-36, F8-41 |
| Feedback 👍/👎 (kepemilikan via session) | `dev:CB:237-243`, `dev:LC:774-819`, `:662-670` | `POST widget/feedback` dengan token (F8-26, F8-35) |
| Kategori QnA (CRUD, bubble, hapus = arsip) | `dev:CB:57-97`, `dev:LC:46-127`, `dev:V/list_kategori.php`, `dev:V/form_kategori.php` | `kategori`, `kategori/{id}`; F8-38, F8-40 |
| Tag QnA (CRUD, hapus = arsip) | `dev:CB:99-138`, `dev:LC:133-214`, `dev:V/list_tag.php`, `dev:V/form_tag.php` | `tag`, `tag/{id}` |
| Ekspor tag xlsx | `dev:CB:140-143`, `dev:LC:216-252` | `GET tag/ekspor`; F8-29, F8-47 |
| QnA (CRUD, tag multi, opsi alternatif) | `dev:CB:146-185`, `dev:LC:258-377`, `:470-496`, `dev:V/list_qna.php`, `dev:V/form_qna.php` | `qna`, `qna/{id}`, `qna/opsi`; F8-28, F8-41 |
| Template & impor Excel QnA | `dev:CB:187-197`, `dev:LC:379-468`, modal `dev:V/list_qna.php:99-119` | `GET qna/template`, `POST qna/impor`; F8-37, F8-47 |
| Statistik | `dev:CB:201-205`, `dev:LC:502-555`, `dev:V/dashboard_statistik.php` | `GET statistik`; F8-42 |
| Riwayat chat | `dev:CB:207-211`, `dev:LC:557-567`, `dev:V/list_log.php` | `GET riwayat` berhalaman; F8-42 |
| Hak akses | admin `userAuth(['1'])` (`dev:CB:59` dst.), widget `UL_PEGAWAI` (`dev:CB:216` dst.) | `Role::filter` per route (F8-30, F8-32) |
| Audit | tidak ada | `BaseAuditableModel` untuk tulis admin; `log_chatbot` tanpa audit (BP8-15) |

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
| (baru v1.1) E-10 chatbot "Vanya" | §6.5, §7.10 | ada di legacy dev (bukan produksi), tidak di Bahan Baku; K-1 |

## Lampiran B — Anomali legacy & perlakuan v2

### B.1 Anomali legacy Halo Simpeg (E-01..E-09)

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

### B.2 Anomali legacy dev chatbot (E-10) & perlakuan v2

A1..A9 dari `Mapping_Chatbot_Fase8.md` §3.4; A10..A16 temuan tambahan saat penyusunan v1.1 (dicek di bahan dev). Singkatan §7.10.

| No | Anomali | Bukti | Perlakuan v2 |
|---|---|---|---|
| A1 | Status "Tidak Aktif" = 2 = "Dihapus": mengubah status ke Tidak Aktif membuat baris hilang dari daftar admin (daftar menyaring `!= 2`) | `dev:V/form_qna.php:26-27`; `dev:LC:263`, `:374` (juga kategori `:51`, `:124`; tag `:138`, `:211`) | Status 1/2/10 (F8-25, F8-38); AC-E10.13 |
| A2 | Jawaban HTML dirender `innerHTML` tanpa sanitasi → XSS tersimpan dari konten admin/impor | `dev:VP:325` (juga `dev:VP:244`) | Sanitasi saat simpan + `SafeHtml` (F8-28, BP8-14); AC-E10.12 |
| A3 | Hapus lewat GET dan simpan lewat POST tanpa token CSRF (pola CI3) | `dev:CB:92-96`, `:134`, `:181` | Method non-GET untuk tulis + JWT (§6.5) |
| A4 | Impor Excel hanya memeriksa MIME dari browser, tanpa batas ukuran/baris; pencocokan kategori/tag tidak memeriksa status | `dev:LC:405-468` (`:406-411`, `:430`, `:452`) | F8-37; AC-E10.15 |
| A5 | Endpoint widget tanpa batas laju → `log_chatbot` bisa dibanjiri | `dev:CB:230-235` | Throttle (F8-36); AC-E10.10 |
| A6 | Riwayat Chat memuat seluruh log sekaligus (tanpa paginasi server) | `dev:LC:557-567` | Paginasi + filter server (F8-42) |
| A7 | Kepemilikan feedback bergantung session PHP (v2 memakai JWT tanpa session) | `dev:LC:662-670`, `:783-786` | Token bertanda tangan (F8-26, F8-35); AC-E10.8 |
| A8 | Statistik memakai `DATE(waktu_interaksi)` (tidak memakai indeks) dan `GROUP BY` teks bebas `pesan_user` | `dev:LC:505`, `:542-544` | Rentang UTC dari hari WIB + indeks (F8-42); `GROUP BY` pesan dipertahankan [K dev] |
| A9 | Mesin memuat semua QnA dan membangun vektor tiap pesan (O(QnA × kosakata), `in_array`) | `dev:NE:33-67` | Rumus dan semantik tokenisasi sama (token `"0"` terbuang, kesetaraan token numerik), struktur data efisien (§7.10.2); R8-11 |
| A10 | Korpus menggabungkan nama tag berstatus apa pun (termasuk yang diarsipkan); daftar admin menampilkan tag terarsip milik QnA, sedangkan form ubah hanya memuat tag aktif sehingga tag terarsip terbuang diam-diam saat simpan | `dev:LC:679-685`, `:480-486`; `dev:V/form_qna.php:54-61` | Hanya tag status 1 di korpus (F8-39); tag status 2 tampil berbadge dan dipertahankan, tag status 10 diabaikan (F8-49); AC-E10.27 |
| A11 | Judul kategori/tag tidak dicek unik, padahal impor mencocokkan berdasarkan judul (baris pertama yang ditemukan) | `dev:LC:83-98`, `:172-186` vs `:430`, `:452` | Judul unik (F8-40); pra-cek D1 blok [5] |
| A12 | Teks di halaman admin dicetak tanpa escape (pesan pegawai di riwayat dan statistik, nama tag, judul); masukan widget hanya diredam `xss_clean` | `dev:V/list_log.php:24`, `dev:V/dashboard_statistik.php:51`, `dev:V/list_qna.php:61`, `dev:V/list_kategori.php:56`; `dev:CB:232` | Teks disimpan polos dan ditampilkan ter-escape (BP8-14); tanpa `xss_clean` |
| A13 | Simpan/ubah QnA + relasi tag tanpa transaksi; impor bisa membuat QnA tanpa kategori walau form mewajibkannya (kolom `id_qna_kategori_fk` NULL di DDL dev); impor menyisipkan relasi tag ganda bila satu baris memuat tag yang sama dua kali; kategori hasil impor tanpa `tampil_bubble` eksplisit (mengikuti DEFAULT 0 DDL dev, jadi tidak mengubah perilaku) | `dev:LC:323-333`, `:352-365`; `:427-442` vs `:318`; `:449`, `:488-496`; `:434`; DDL D1 blok [1] | Transaksi (F8-41); baris tanpa kategori ditolak, tag ganda dideduplikasi, `tampil_bubble = 0` (F8-37); QnA dev tanpa kategori saat impor isi (F8-46, F8-46a) |
| A14 | Batas 300 karakter hanya `maxlength` HTML; server tanpa batas; jalur bubble mengirim dan mencatat teks pertanyaan dari klien | `dev:VP:95`, `:319-327`; `dev:CB:232`, `dev:LC:675`, `:749-751` | Ditegakkan BE untuk jalur ketikan (`mb_strlen`, F8-41); jalur bubble memakai `pertanyaan` dari DB (F8-48); AC-E10.11, AC-E10.29 |
| A15 | Petunjuk form "Maks. 3 QnA unggulan" tidak ditegakkan; populer mengambil 3 unggulan terbaru | `dev:V/form_qna.php:75` vs `dev:LC:619-625` | Ikut dev: tidak ditegakkan, tetap 3 terbaru [K dev] |
| A16 | Ekspor tag menyertakan tag terarsip (status 2) sebagai "Tidak Aktif" | `dev:LC:217-221`, `:236` | Status 1/2 diekspor, 10 tidak (F8-47) |

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

**E-10 Chatbot (dicek di `241ef9e5`):**

| Kebutuhan | Komponen v2 | Rujukan |
|---|---|---|
| Pola modul konten tanya-jawab admin | FAQ (G-10, DBV-002): controller, service, hooks tulis | `backend/app/Controllers/Api/MasterData/FaqController.php`, `backend/app/Libraries/MasterData/FaqService.php`, `backend/app/Libraries/MasterData/FaqArticleHooks.php` |
| Sanitasi HTML saat simpan (BE) | `HtmlSanitizer` (HTMLPurifier whitelist, service shared `htmlSanitizer`); pemakaian di hooks FAQ | `backend/app/Libraries/Html/HtmlSanitizer.php:25`, `backend/app/Config/Services.php:296`, `backend/app/Libraries/MasterData/FaqArticleHooks.php:34`; `ezyang/htmlpurifier` `backend/composer.json:30` |
| Render HTML aman (FE) | `SafeHtml.vue` (satu-satunya `v-html`, lewat `sanitizeHtml()`/DOMPurify); contoh pakai di FAQ | `frontend/src/shared/components/SafeHtml.vue:18`, `frontend/src/shared/utils/sanitizeHtml.ts`, `frontend/src/features/master-data/views/FaqView.vue:287`; `dompurify` `frontend/package.json:25` |
| Isian jawaban HTML + pratinjau | Pola field `html` form master | `frontend/src/features/master-data/components/MasterFormDialog.vue:463-470` |
| Excel ekspor (anti formula injection) | `EksporTabel::xlsx`, `EksporTabel::aman` | `backend/app/Libraries/Kepegawaian/Pegawai/EksporTabel.php:34`, `:92`; `phpoffice/phpspreadsheet` `backend/composer.json:32` |
| Excel impor (batas baris, pesan berkas rusak) | Pola `PembacaExcelSlip` | `backend/app/Libraries/Presensi/Slip/PembacaExcelSlip.php:28-32` |
| Role pegawai / admin | `Role::UL_PEGAWAI` = 2/6/7, `Role::SUPER_ADMIN` = 1 | `backend/app/Constants/Role.php:15`, `:30` |
| Throttle per akun | `Services::throttler()` pola `DokumenService::wajibThrottle` (F-09) dan `TokenFcmService`; `TooManyRequestsException` (429) | `backend/app/Libraries/Dashboard/Dokumen/DokumenService.php:353-360`, `backend/app/Libraries/Push/TokenFcmService.php:66`, `backend/app/Exceptions/TooManyRequestsException.php:10` |
| Status 1/2/10 (Dihapus = 10) | Konvensi AGENTS.md §2; contoh konstanta | `backend/app/Libraries/Dashboard/Konten/Dibo/DiboService.php:39`, `backend/app/Libraries/Dashboard/Interaksi/FormDigital/KelolaFormDigital.php:45` |
| Audit tulis | `BaseAuditableModel` → `audit_logs` | `backend/app/Models/BaseAuditableModel.php:21` |
| Tabel aksi ⋮ | `RowActionsMenu.vue` + `rowActions.ts`; `ConfirmDialog.vue` | `frontend/src/shared/components/` |
| Route file modul | Daftar `$routeFiles` | `backend/app/Config/Routing.php:34-59` |
| Layout & menu | `RedesignShell.vue` (titik pasang widget); item `halo` Fase 8 di grup "Pusat Bantuan"; `ACTIVE_PHASE` = 5 dan semantik `rilis` | `frontend/src/shared/layouts/RedesignShell.vue:188`, `frontend/src/shared/layouts/nav.config.ts:301`, `:94`, `:120-124` |
| Syarat render saat wajib ganti password; gerbang fase | `NotifikasiPushShell v-if="!auth.mustChangePassword"`; lonceng `ACTIVE_PHASE >= FASE_LONCENG`; filter JWT menolak route tanpa `jwt:ganti_password` | `frontend/src/shared/layouts/RedesignShell.vue:186`, `:116-118`; `backend/app/Filters/JwtAuthFilter.php:49` |
| Ubah status terpisah | `PATCH …/(:segment)/status` | `backend/app/Config/Routes.php:45`, `:71`; `backend/app/Config/RoutesKonten.php:60`, `:70`; `backend/app/Config/RoutesDashboard.php:51` |
| Route FE per fitur; reset store saat keluar | import `routes.*` per fitur; `clearSession()` memanggil `resetSesiKonten()` | `frontend/src/router/index.ts:13-25`; `frontend/src/features/auth/stores/auth.store.ts:49-54` |
| UI | `UiStatTile`, `UiTooltip`, `UiFileField`, `UiExportMenu`, `UiCheckbox`, `UiSearchInput`, `UiPagination`, `UiBadge` | `frontend/src/shared/ui/` |

## Lampiran D — Riwayat versi

| Versi | Tanggal | Perubahan |
|---|---|---|
| v0.1 | 03-11-2026 | Draf awal dari riset kode legacy (`0cf7e67`: `L_support`, `Support`, `MY_Controller`, `L_notification`, `Cron`, view support) dan v2 (`f7b9221e`): usulan F8-01..F8-22, pertanyaan Q8-01..Q8-15, peta legacy → v2, anomali B-1..B-18, risiko R8-1..R8-9, milestone M0..M7. Tidak ada kode dan tidak ada migration |
| v1.0 | 03-11-2026 | Keputusan Horii 03-11-2026: Q8-01..Q8-15 semua ikut saran (F8-xx menjadi ✔; syarat Q8-06, Q8-08, Q8-11 tetap dicatat); key final DBV-028 dan MAKE-070..074 (MAKE-069 dipakai paket impor cutover tahap 2); dua jalur paralel BE/FE (§3, `8_1.md`, `8_2.md`); trigger `inbox_msg_afIns` tetap [I] + kewajiban M0 |
| v1.1 | 12-11-2026 | **CR-121.** Tambah **E-10 Chatbot "Vanya"** dari bahan legacy dev (rujukan `dev:`, label [K dev]) atas keputusan Horii 12-11-2026 K-1..K-11 (semua ikut saran; K-5 bersyarat D1): header, gerbang E-10 (§0.1), PN8-5..PN8-7, scope (§1.1) dan di luar scope (§1.2, baris chatbot lama diganti), BP8-14/15, jalur chatbot (§3), glosarium, keputusan F8-23..F8-33 (✔) dan bawaan teknis F8-34..F8-47 (◻), FE (§6.3), endpoint (§6.5), spesifikasi §7.10 (AC-E10.1..23), lintas modul, ketergantungan, sub-jalur C0..C4, DoD, register K-1..K-11 + pertanyaan terbuka, risiko R8-10..R8-16, Lampiran A.2a, B.2 (A1..A16), C (komponen E-10). Key DBV-033, MAKE-094, MAKE-095. Rujukan v2 E-10 dicek di `241ef9e5`. Penyuntingan hasil review v1.1 (masih 12-11-2026): hasil D1 chatbot blok [1]–[3] dipakai (§7.10.1 [K dev D1], PN8-5, R8-14), PN8-8 acuan visual, bawaan baru F8-48..F8-51 (+ F8-34a/b, F8-46a di §12), `PATCH …/status`, syarat render widget (wajib ganti password, gerbang rilis), semantik tokenisasi (token "0", kesetaraan numerik), CSV isi dev di luar repo, keunikan judul tanpa UNIQUE biasa, AC-E10.24..30 dan perluasan AC-E10.2/3/8/10/11/15/17/18/20/22, pembagian berkas chatbot (§3), R8-17. Tidak ada kode dan tidak ada migration |
| v1.1 (lanjutan) | 12-11-2026 | Hasil D1 chatbot blok [1]–[6] dicatat (PN8-5); keputusan Horii 12-11-2026: K-5 ditunda ke saat deploy (PN8-6, F8-27, Trello DEPLOY-002), K-12 judul kembar digabung saat impor + judul unik (F8-40 ✔), K-13 kategori uji/nonaktif tidak dibawa, kategori aktif kosong dibawa (F8-46); F8-46a gugur (0 QnA tanpa kategori) |
