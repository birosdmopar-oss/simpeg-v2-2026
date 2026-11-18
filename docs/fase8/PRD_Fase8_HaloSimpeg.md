# PRD Fase 8 v1.4 — Halo Simpeg (Modul E, ticketing helpdesk, SIMPEG v2)

| Butir | Isi |
|---|---|
| Dokumen | `docs/fase8/PRD_Fase8_HaloSimpeg.md` — **acuan utama** coder/agen AI Fase 8 (Bahan Baku `08-HaloSimpeg.md` E-01..E-08, ditambah E-09 dan E-10 Chatbot "Vanya"; Trello **DEV-009** (10 task), **QASMTASK-111..118** (E-01..E-08), **QASMTASK-123** (E-09), **QASMTASK-124** (E-10); pemetaan per task §1.1). Pembagian kerja paralel: [`8_1.md`](8_1.md) (Jalur BE) dan [`8_2.md`](8_2.md) (Jalur FE) |
| Versi | **v1.4 — 17-11-2026 (CR-138)**: ralat status paket (DBV-028, MAKE-070, CR-132, CR-133, CR-134 paket 1 dan 2, CR-139 di `main` `d659acf6`; MAKE-071, MAKE-097, MAKE-073 B1 berjalan; §0.1, §3.1); keputusan Horii 17-11-2026 (PN8-8 rinci, H8-10 tetap [I], izin [#10-WorkloadQAChatbot], S8 (b), T2 (b), H8-8 dan H8-7 belum ada beserta perlakuan pengganti, teks paginasi CR-141; §5.2, §12); keputusan reviewer CR 17-11-2026 (selisih README master S1–S12, keputusan MAKE-071 T1–T13, kunci transaksi W1, path admin FE, R10 langkah 1 pindah ke CR-134 paket 1; §3, §6.1b, §6.3, §7.2); keputusan DB Validator DBV-028 Q8–Q10 dan ralat label §7.1; key **CR-138** (v1.4) serta **CR-139**..**CR-141**. v1.3 — 16-11-2026 (CR-136): hasil laporan A0/B0, keputusan Horii dan reviewer 16-11-2026, kontrak §6.1b, key CR-136 dan CR-137. v1.2 — 16-11-2026 (CR-131): keputusan H8-1..H8-6 dan H8-11..H8-13, tabel paket §3.1, kewajiban A0. v1.1 12-11-2026 (CR-121): tambah **E-10 Chatbot "Vanya"** (K-1..K-11). v1.0 03-11-2026 (Q8-01..Q8-15: semua ikut saran); v0.1 `70466eac`; rincian tiap versi di §Lampiran D |
| Status | **BERLAKU setelah masuk `main`** (izin Horii). Keputusan dikunci di §5.2 dan §12. Koding helpdesk terkunci gerbang §0.1 (gerbang sudah terbuka 16-11-2026; A0 dan B0 mulai sesudah CR-130 di `main`, laporan A0/B0 disetujui reviewer sesudah CR-131 di `main`). **(v1.3)** A0 ✔ dan B0 ✔ disetujui reviewer 16-11-2026; A1/DBV-028 dibuka 16-11-2026; kontrak helpdesk di §6.1b menjadi bahan README beku MAKE-070/071; E-10 sudah di `main` (§0.1), tinggal QA Lapis 1 dan rilis. **(v1.4)** DBV-028 ✔ dan MAKE-070 ✔ di `main` (README master beku; selisih S1–S12 diterima reviewer 17-11-2026, §6.1b); MAKE-071 berjalan (README tiket beku di branch, belum di `main`); QA Lapis 1 bagian A chatbot putaran 1 selesai 17-11-2026 |
| Prinsip | **"Intinya ikut legacy"** (prinsip umum Horii 01-11-2026 pada Fase 6, dipakai sebagai default di sini): perilaku bisnis & kontrak ikut legacy [K]. Untuk E-10, acuannya **legacy dev** [K dev] karena fitur chatbot belum ada di legacy produksi. Deviasi [V2] hanya bila (a) keputusan eksplisit Horii, atau (b) teknis wajib/keamanan dasar — setiap deviasi tercatat di §5.2 dan Lampiran B |
| Basis kode | `origin/main` = **`f7b9221e`** untuk rujukan `path:baris` repo v1.0; rujukan E-10 (v1.1) dicek di **`241ef9e5`**; rujukan yang ditambah atau diubah di v1.2 dicek di **`603af133`** (rujukan lama yang tidak disentuh v1.2 tetap bertanggal basisnya dan dicek ulang di A0, T-3); rujukan yang ditambah atau diubah di **v1.3** dicek di **`ef83356e`** (A0 T-3: di luar `docs/fase8/`, `git diff 603af133 ef83356e` hanya menyentuh `README-deploy.md`, ADR-038, dan progres 08, sehingga rujukan kode v1.2 tetap berlaku); rujukan yang ditambah atau diubah di **v1.4** dicek di **`d659acf6`** |
| Acuan legacy | Salinan kerja legacy CI3 (HEAD `0cf7e67`, sama dengan Fase 6; rujukan baris bercatatan AS-09, belum dicocokkan checksum produksi, Q5A-14). Rujukan relatif ke `application/`: `LS` = `libraries/hr/L_support.php`, `SC` = `controllers/hr/Support.php`, `MC` = `core/MY_Controller.php`, `LN` = `libraries/hr/L_notification.php`, `CR` = `controllers/hr/services/Cron.php`, `VI` = `views/hr/support/inbox.php`, `VD` = `views/hr/support/detail.php`, `VM` = `views/template/modal.php`. **Bahan chatbot legacy dev** (12 berkas CI3 dari codebase legacy dev, belum ada di produksi, diterima 12-11-2026): rujukan berawalan **`dev:`** relatif ke `application/` bahan itu (`dev:controllers/hr/Chatbot.php`, `dev:libraries/hr/L_chatbot.php`, `dev:libraries/Nlp_engine.php`, `dev:views/hr/chatbot/*`) supaya tidak tertukar dengan legacy produksi |
| Scope | **Fase 8** = E-01..E-08 (`Bahan Baku SIMPEG/08-HaloSimpeg.md`) + ekspor rekap petugas (E-09, baru; ada di legacy `LS:1600`, tidak ada di Bahan Baku) + **E-10 Chatbot "Vanya"** (baru di v1.1; dari legacy dev, K-1) + penyediaan titik sambung notifikasi ke Fase 6 (MAKE-066) dan lonceng Fase 7 (F-08). **Penutup seluruh development** (sign-off Horii) |
| Pelaksana | **Dua jalur paralel** (Q8-14 = B): **Jalur BE** ([`8_1.md`](8_1.md)) dan **Jalur FE** ([`8_2.md`](8_2.md)). E-10 dikerjakan sebagai sub-jalur chatbot di kedua berkas itu. Agen per paket dan kode izin: **§3.1** (Jalur BE helpdesk = Handling Case, Jalur FE helpdesk = Worker Muse, DB Validator = WS-2, chatbot BE = ReadOne, chatbot FE = Worker Muse). **Reviewer** = reviewer CR (review, gate, merge, QA); **Horii** = keputusan & sign-off; **DBV** = review skema |
| Key | Skema: **DBV-028** (helpdesk) dan **DBV-033** (chatbot, K-3). Pekerjaan baru: **MAKE-070..MAKE-074** (MAKE-069 dipakai paket impor cutover tahap 2), **MAKE-094** (BE chatbot) + **MAKE-095** (FE chatbot), dan **MAKE-097** (T12b impor master helpdesk, H8-12). Revisi PRD v1.1: **CR-121**; v1.2: **CR-131**; v1.3: **CR-136**; v1.4: **CR-138**. CR berkas bersama Push (H8-14 (a)): **CR-137** (v1.3). Paket dokumen/QA/rilis: **CR-130** (sinkron status), **CR-132** (checklist QA Lapis 1), **CR-133** (test AC-E10.19), **CR-134** dst. (perbaikan QA chatbot), **CR-135** (rilis menu); v1.4: **CR-139** (helper satker `WebConfigService::satkerKepegawaian()`), **CR-140** (stabilkan test `alurInti.e2e` yang flaky di bawah beban), **CR-141** (teks info paginasi bersama). Nomor CR lain = nomor kosong berikutnya dari reviewer (rujukan lama "CR-095 dst." gugur: CR-095..CR-129 sudah terpakai). ADR baru: tidak ada |
| Sumber | `08-HaloSimpeg.md` (E-01..E-08, exit criteria); Matriks Role × Endpoint Modul E (dicocokkan saat M0); PRD Fase 4 (§C-01, D10, N1/N9–N13, K-22, `docs/fase4/PRD_Fase4.md:50,126,148,265,1130,1184,1406,1506`); DBV-022 §2.14, §3 (`backend/docs/db-review/DBV-022-layanan-fase4-schema.md`); PRD Fase 6 F6-12/F6-13 (`docs/fase6/PRD_Fase6_Integrasi.md:194-195`); `Mapping_Migrasi_Tidak_Dimigrasi.md:52`; PRD Fase 7 (F-02, F-08); frontend redesign `67fa7678` (branch `feat/frontend-ui-redesign`); riset kode legacy & v2 03-11-2026 (Lampiran A). **E-10:** bahan chatbot legacy dev (`dev:`); dokumen kerja reviewer `Mapping_Chatbot_Fase8.md` (12-11-2026, di luar repo; keputusan K-1..K-11 §6, anomali A1..A9 §3.4); paket permintaan D1 chatbot dari DB dev legacy (di luar repo, baca saja). **v1.2:** keputusan Horii H8-1, H8-2, H8-5, H8-6 13-11-2026 (H8-4 terjawab lewat H8-1; `docs/fase8/Kesiapan_Fase8.md` §6.1, `:239-240`, `:243-244`) dan keputusan 16-11-2026 (H8-3, H8-11, H8-12, H8-13; §12 register H8); rencana impor `docs/migrasi/MAKE-069-rencana.md` (T12b `:108-111`). **v1.3:** laporan A0 (Handling Case, disetujui reviewer 16-11-2026; daftar kontrak final = bagian revisi putaran 2 laporan itu), laporan B0 (Worker Muse, disetujui final 16-11-2026), keputusan Horii dan reviewer CR 16-11-2026 (di luar repo; isinya dimasukkan ke §5.2, §6.1b, §12). **v1.4:** README master `backend/app/Controllers/Api/HaloSimpeg/README.md` bagian "Selisih terhadap PRD v1.3" (S1–S12), dokumen DBV-028 §2 dan §8 (`backend/docs/db-review/DBV-028-skema-helpdesk.md`), `docs/fase8/Checklist_QA_Lapis1_Fase8.md` (CR-132), keputusan Horii, reviewer CR, dan DB Validator 17-11-2026 (di luar repo; isinya dimasukkan ke §0.1, §3.1, §5.2, §6.1b, §7.1, §12) |

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
  paralel dengannya. **M4 (push) menunggu MAKE-066** (adapter FCM HTTP v1, Fase 6 jalur B5) di `main` — **terpenuhi (v1.2):
  MAKE-066 sudah di `main` (`9debb366`)**, sehingga M4 memakai `FcmV1Adapter` + `FakeTransport` di test dan `MockFcmAdapter`
  di dev. Uji push real = syarat **sebelum deploy** (dilacak MT-010/DEPLOY-001.1, bukan menahan sign-off Fase 8), sama dengan F6-23.
- **Gerbang helpdesk (v1.2):** H8-2 (Horii 13-11-2026) = helpdesk mulai **sesudah F-10 sign-off**; F-10 sign-off Horii
  16-11-2026 pada `main` `fd9e8371`, dicatat CR-129 `603af133`, sehingga gerbang **terbuka** (izin [#9-WorkloadHaloSimpeg]).
  **Syarat mulai (v1.2):** A0 (Handling Case) dan B0 (Worker Muse) mulai sesudah CR-130 di `main`; laporan A0/B0 disetujui
  reviewer sesudah CR-131 (PRD v1.2) di `main`.
- **Status helpdesk (v1.3, 16-11-2026):** CR-130 dan CR-131 di `main` (`ef83356e`). **A0 ✔** (laporan pemahaman BE Handling
  Case, disetujui reviewer 16-11-2026 dengan syarat kontrak dilengkapi di revisi putaran 2; daftar kontrak final = §6.1b) dan
  **B0 ✔** (laporan pemahaman FE Worker Muse, disetujui final 16-11-2026). Keputusan Horii atas usulan A0/B0 (H8-14..H8-16 dan
  butir turunannya) diambil 16-11-2026 ("ikut rekomendasi semua", §12). **A1/DBV-028 dibuka 16-11-2026** (Handling Case menulis,
  review DB WS-2, PR `[CR][DBV]`). MAKE-070/071 dibuka sesudah DBV-028 di `main`; B1 dibuka sesudah kontrak MAKE-070 beku
  di README `Controllers/Api/HaloSimpeg`; bagian B1 yang memakai kontrak tiket (service/store tiket, ringkasan, badge) serta B2–B3
  menunggu README MAKE-071 beku (§3, `8_2.md` §2). CR-136 = PRD v1.3 ini.
- **Status helpdesk (v1.4, 17-11-2026, `main` `d659acf6`):** **DBV-028 ✔ di `main`** (PR #41, `a5cb7300`; DB Validator WS-2
  SETUJU Q1–Q10, §7.1). **MAKE-070 ✔ di `main`** (`56a700b1`): bagian master README `Controllers/Api/HaloSimpeg` **beku**;
  selisihnya terhadap v1.3 (S1–S12) diterima reviewer 17-11-2026 dan diringkas di §6.1b; B1 bagian master terbuka. **Belum di
  `main`:** MAKE-071 ▶ berjalan (keputusan T1–T13 diambil sebelum README tiket beku; README tiket beku di branch, §6.1b);
  MAKE-097 ▶ PR #42, DBV SETUJU, revisi berjalan; MAKE-073 B1 bagian master selesai review, masih di branch; MAKE-072 dan
  MAKE-074 belum mulai. Gerbang MAKE-071 atas H8-10 terpenuhi (tetap [I], Horii 17-11-2026, §12). CR-139 (`ccb90f78`) di
  `main`: satu pembacaan satker kepegawaian lewat `WebConfigService::satkerKepegawaian()` (F8-07). CR-138 = PRD v1.4 ini.
- **Gerbang E-10 Chatbot (v1.1):** koding E-10 dan migration DBV-033 menunggu **PRD v1.1 masuk `main`** (CR-121). DBV-033
  baru disusun **sesudah D1 chatbot dari DB dev legacy** diterima (PN8-5), karena DDL keenam tabel tidak ikut di bahan kode; D1
  chatbot adalah prasyarat DBV-033, **bukan** prasyarat PRD. Status 12-11-2026: blok [1]–[6] (struktur, `qna_status`, jumlah,
  sebaran, integritas, bentuk jawaban) **sudah diterima** dan dipakai di §7.10.1, PN8-5, F8-40, F8-46. E-10 **tidak** bergantung
  pada DBV-028/MAKE-070..074, Fase 6 (tidak ada push), maupun F-08, dan sebaliknya: helpdesk dan chatbot boleh berjalan paralel.
- **Status E-10 (v1.2, di `main` 603af133):** DBV-033 `3fb8c5f7` (PR #40), MAKE-094 `4af0c188`, MAKE-095 `2a8f405d` — kode,
  skema, dan test **di `main`**. Yang tersisa: QA Lapis 1 chatbot (AC-E10.22/23, checklist CR-132; v1.3: acuan PN8-8 ✔ diterima 16-11-2026), test AC-E10.19
  sisi log aplikasi (CR-133), perbaikan temuan QA (CR-134 dst.; v1.3: termasuk R10 langkah 2, migrasi helper galat dan pilih
  banyak chatbot ke `shared/`, §3), rilis menu dan widget bersama helpdesk (H8-11 (a),
  CR-135). Pelaksana CR-133 (ReadOne) dan CR-134+ (ReadOne BE, Worker Muse FE) = usulan; izin workload pekerjaan chatbot
  pasca-#8 diajukan ke Horii sebelum paket dibuka (◻). C4 impor isi QnA dev **keluar dari DoD** (H8-13 (b)) dan dilacak di DEPLOY-002.
- **Status E-10 (v1.4, 17-11-2026):** CR-133 ✔ di `main` (`19a11ed5`, test AC-E10.19 sisi log aplikasi dan `audit_logs`);
  CR-132 ✔ checklist QA Lapis 1 Fase 8 di `main` (`67f3303c`); CR-134 paket 1 ✔ (di `main` per `67f3303c`: avatar mini rata
  atas [K], catatan README [V2] PN8-8, R10 langkah 1 + 2: `shared/utils/galatKolom.ts` dan `shared/ui/UiPilihBanyak.vue`
  dibuat, `chatbot/galat.ts` dan `chatbot/components/admin/TagPilihan.vue` dihapus) dan paket 2 ✔ (T-02, T-03, `d659acf6`).
  QA Lapis 1 bagian A chatbot putaran 1 selesai 17-11-2026; temuan T-02/T-03 tuntas. CR-133 (ReadOne) dan CR-134 dst. (Worker
  Muse FE, ReadOne BE) berjalan dalam izin [#10-WorkloadQAChatbot] (Horii 17-11-2026), sehingga rumusan "usulan … izin
  workload pasca-#8 ◻" di atas gugur v1.4. Sisa E-10: rilis menu CR-135 (H8-11 (a)).
- **Status akhir (18-11-2026, CR-144, `main` `8bc6c509`):** semua paket Fase 8 di `main` (helpdesk: DBV-028, MAKE-070
  `56a700b1`, MAKE-071 `066315c5`, MAKE-097 `82d698ec`, MAKE-072 + CR-137 `64d68377`, MAKE-074 BE `947b94fd`, MAKE-073
  `d90d8a75`, MAKE-074 FE `e4392507`; E-10 seperti di atas). **QA Lapis 1 Fase 8 selesai** 18-11-2026 pada `main` `e4392507`
  (bagian B) + verifikasi perbaikan `8bc6c509`, 0 ❌ terbuka; temuan bagian B diperbaiki CR-143 `4d06a8c6` dan CR-142 `8bc6c509`
  (checklist CR-132, laporan `docs/fase8/Laporan_Akhir_Fase8.md`, di `main` `d8020fc3`). **SIGN-OFF Horii 18-11-2026** pada
  `main` `d8020fc3` (CR-145); sesudahnya rilis menu CR-135 (⛔ izin Horii).

### 0.2 Prasyarat non-teknis

| ID | Kebutuhan | Pemilik | Menahan |
|---|---|---|---|
| PN8-1 | Pemilik aplikasi **Android** mengonfirmasi kunci `data` pada push Halo Simpeg yang dibaca app (Q8-08), dan siapa penulis token Android ke DB v2 setelah cutover (pertanyaan cutover F6-13, PN-3 Fase 6) | Horii/TL | Q8-08, uji push real |
| PN8-2 | **F-02 sortir `inbox` legacy** (Fase 7) selesai dan hasilnya siap diimpor ke `disiplin_ticket(_msg)`; Bahan Baku mensyaratkannya sebagai entry criteria | Reviewer/DBV | Impor data tiket (bukan koding) |
| PN8-3 | Keputusan Horii untuk impor master Halo Simpeg (Q8-13): `support_officer`, `officer_category`, `help_desk_contact`, serta daftar petugas aktif dan kategori yang berlaku | Horii/Biro SDM | Cutover |
| PN8-4 | Frame **Figma** untuk halaman yang belum ada (daftar tiket petugas, master kategori/petugas/kontak). Yang ada: Gambar 33 (drawer pegawai) dan Gambar 34 (inbox admin) di frontend redesign | Horii/desainer | QA visual (AGENTS.md §3.6: tanpa frame → ikut pola halaman lain + dicatat). **✔ (v1.3) ditutup lewat H8-9 (Horii 16-11-2026): frame helpdesk belum ada**; daftar tiket petugas = master-detail ala Gambar 34, master Kategori/Petugas/Kontak = pola Master Data/PIC layanan (AGENTS.md:93-94), tiap layar tanpa frame dicatat; layar pemilik mengacu Gambar 33 |
| PN8-5 | **D1 chatbot** dari DB **dev** legacy (baca saja): `SHOW CREATE TABLE` keenam tabel chatbot, isi `qna_status`, jumlah baris & sebaran status, QnA aktif per kategori, relasi yatim/ganda, panjang teks & jawaban ber-HTML, ringkasan angka log (blok [1]–[6]). Isi `log_chatbot.pesan_user` **tidak** diambil. **Status 12-11-2026: blok [1]–[6] DITERIMA** (struktur di §7.10.1, label [K dev D1]). Angka dev: 274 QnA (271 aktif, 3 unggulan); 60 kategori (56 aktif, 4 bubble: Cuti, Kinerja, Simpeg, Syarat dan Hak Pensiun; 3 kategori uji "test…" nonaktif; 8 kategori aktif tanpa QnA); 696 tag (688 aktif, 84 tanpa QnA); 827 relasi (1 ganda); QnA tanpa kategori 0; judul kategori kembar 2, judul tag kembar 11; pertanyaan terpanjang 193 karakter, jawaban terpanjang 1.318 karakter, jawaban ber-HTML 2, pola berisiko 0; log 74 baris uji (36% tidak cocok). Rincian di dokumen kerja `HASIL_D1_chatbot_1211.md` (di luar repo). **Urutan dua tahap:** D1 blok [1]–[6] → keputusan K-5 (PN8-6) → isi konten (blok [7]) diminta terpisah sesudahnya; bila blok [7] terlanjur diambil lebih awal, CSV-nya tetap disimpan di luar repo dan tidak dipakai sebelum K-5 diputus | Horii | DBV-033 (bukan PRD) |
| PN8-6 | **Keputusan isi QnA awal (K-5)** — **DITUNDA ke saat deploy/cutover** (Horii 12-11-2026: "nanti saja, catat pada saat deploy"; dicatat di Trello DEPLOY-002). Saat itu Horii menetapkan apakah isi dev dibawa (CSV blok [7] → perintah impor sekali jalan lewat runbook cutover, F8-46; CSV tidak pernah di-commit) atau v2 mulai kosong dan diisi admin | Horii | Isi awal chatbot saat cutover (bukan koding) |
| PN8-7 | **Foto avatar Vanya (opsional)**: legacy dev memakai foto `Vanya_Avatar.*` bila ada, selain itu ilustrasi SVG (`dev:views/hr/chatbot/chatbot_popup.php:19-27`). Berkas foto (bila dipakai) diserahkan sebagai aset FE dengan izin pemakaian gambar. **✔ (v1.4)** foto tidak dipakai; avatar tetap ilustrasi SVG (PN8-8, Horii 17-11-2026) | Horii/desainer | Tidak menahan (SVG bawaan dipakai) |
| PN8-8 | **Acuan visual widget dev** untuk QA Lapis 1 chatbot (AC-E10.22): tangkapan layar widget dev (launcher, kartu sapaan, jendela chat, bubble, penilaian, layar sempit) dari Horii, atau render ulang view dev di luar repo oleh reviewer. Tanpa frame Figma (K-11); tangkapan layar tidak masuk repo bila memuat data nyata. **✔ (v1.3) tangkapan layar widget chatbot dev diterima dari Horii 16-11-2026** sebagai acuan visual QA chatbot; disimpan di luar repo karena memuat foto avatar dan tidak di-commit; selisih dibahas di CR-132 bagian A / CR-134. **✔ (v1.4)** Horii 17-11-2026 "ikut rekomendasi": selisih yang disengaja dicatat sebagai keputusan tampilan [V2] (§12, F8-33, README `features/halo-simpeg/chatbot`); perbaikan [K] avatar mini rata atas tuntas di CR-134 | Horii/reviewer | QA Lapis 1 chatbot (bukan koding) |

Kredensial dan alamat **tidak pernah** ditulis di repo, Trello, atau chat agen; hanya di `.env` server.

---

## 1. Scope

### 1.1 Di dalam scope Fase 8

| Task | Judul | Inti v2 | Key (usulan) | Kartu Trello |
|---|---|---|---|---|
| E-01 | Skema pendukung helpdesk | **DBV-028**: `support_officer`, `officer_category`, `help_desk_contact` (belum ada); verifikasi `question_category`, `disiplin_ticket`, `disiplin_ticket_msg` (**sudah ada**, DBV-022 — tidak dibuat ulang); ALTER indeks/kolom bila perlu. **Di `main` (PR #41, `a5cb7300`, v1.4)** | DBV-028 | QASMTASK-111 |
| E-02 | Master kategori & petugas | CRUD `question_category` dan `support_officer` + `officer_category` (role 1) ikut legacy `LS:213-813`. **Di `main` `56a700b1` (v1.4)** | MAKE-070 | QASMTASK-112 |
| E-03 | Buat tiket | `disiplin_ticket` + pesan pertama + lampiran dalam satu transaksi `LS:937-1095`; **tidak** menyentuh `inbox` | MAKE-071 | QASMTASK-113 |
| E-04 | Thread percakapan | Daftar tiket, baca thread, balas, tandai baca, lampiran `LS:817-927`, `LS:1097-1430`; cakupan data per pemilik/petugas kategori | MAKE-071 | QASMTASK-114 |
| E-05 | Tutup tiket | `close_inbox` `LS:1432-1470`; hapus tiket `LS:1472-1503` sesuai Q8-03 | MAKE-071 | QASMTASK-115 |
| E-06 | Notifikasi tiket | Push (queue `push`) ke pihak lawan + penghitung belum dibaca; titik sambung ke MAKE-066 (F6-13) dan lonceng F-08; **tidak** menulis `inbox` | MAKE-072 (push); binding ringkasan lonceng di MAKE-071 (§7.6) | QASMTASK-116 |
| E-07 | Kontak helpdesk | CRUD `help_desk_contact` (role 1) `LS:14-210` + tampilan daftar (Q8-12); BE **di `main` `56a700b1`** (v1.4) | MAKE-070 | QASMTASK-117 |
| E-08 | Frontend | Ubah `frontend/src/features/halo-simpeg/` dari data contoh ke API; tampilan pegawai vs petugas | MAKE-073 | QASMTASK-118 |
| E-09 | Ekspor rekap petugas | `export_hs` `LS:1600-1830` (xlsx; periode) — baru dibanding Bahan Baku | MAKE-074 | QASMTASK-123 |
| E-10 (skema) | Skema chatbot "Vanya" | **DBV-033** (K-3): 6 tabel `qna`, `qna_kategori`, `qna_tag`, `qna_has_tag`, `qna_status`, `log_chatbot` ikut DDL dev hasil D1 chatbot; status 1/2/10 (memperbaiki anomali A1); terpisah dari DBV-028. **Di `main` `3fb8c5f7`** | DBV-033 | QASMTASK-124 |
| E-10 (BE) | Chatbot "Vanya" — backend | Mesin jawab TF-IDF port apa adanya [K dev `dev:libraries/Nlp_engine.php`, `dev:libraries/hr/L_chatbot.php:573-769`]; endpoint widget (role 2/6/7); CRUD admin kategori/tag/QnA, impor Excel + template, ekspor tag, statistik, riwayat berhalaman (role 1); throttle; token feedback (K-4). **Di `main` `4af0c188`** | MAKE-094 | QASMTASK-124 |
| E-10 (FE) | Chatbot "Vanya" — frontend | Widget melayang di layout untuk role 2/6/7 (K-8) dengan tampilan legacy dev disesuaikan token desain v2 (K-11); halaman admin "Chatbot Manager" (role 1) dengan tabel ⋮ (AGENTS.md §1). **Di `main` `2a8f405d`** | MAKE-095 | QASMTASK-124 |
| Penutup | Progres, README modul, runbook cutover (impor master T12b per H8-6; berkas lampiran), QA Lapis 1 (termasuk chatbot), laporan akhir, sign-off | `backend/docs/progress/08-HaloSimpeg.md` | MAKE-074 (+ MAKE-094/095 untuk bagian chatbot); kode impor master T12b = **MAKE-097** (H8-12); checklist QA = CR-132; rilis menu = CR-135 | DEV-009 (kartu induk) |
| (pasca Fase 8) | Impor isi QnA dev (C4) dan uji push real | Keluar dari DoD (C4: H8-13 (b); uji push real: §0.1, AC-E06.8) | C4: key MAKE dari reviewer saat PN8-6 (F8-51) | DEPLOY-002 (C4, K-5); MT-010 / DEPLOY-001.1 (uji push real, F6-23) |

Kartu Trello per 16-11-2026 (disinkron reviewer, akun Horii terverifikasi): QASMTASK-111..118 = E-01..E-08 berurutan,
QASMTASK-123 = E-09, QASMTASK-124 = E-10 (label sudah diganti ke Testing-QA-Func pada 16-11-2026, sinkron reviewer: kode di
`main`, tinggal QA), DEV-009 = kartu induk Fase 8 (10 task). Notion: E.6 (E-10) = Berjalan. Kartu baru atau perubahan pemetaan dicatat di sini dan di `docs/fase8/Kesiapan_Fase8.md` (CR-130).

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
| Kriteria UAT dan sign-off resmi pihak Kemenparekraf/Baparekraf (PIC, berita acara serah terima/BAST) | MT/DEPLOY (di luar 9 berkas checklist Bahan Baku) | Bahan Baku "Catatan Pasca-Fase 8" (`08-HaloSimpeg.md:99-105`); sign-off Horii Fase 8 = penutup development, bukan UAT/BAST (v1.2) |
| Rencana cutover produksi (migrasi data produksi, jendela downtime, rencana rollback) | MT/DEPLOY (kartu DEPLOY di Trello) | Fase 8 hanya menyumbang runbook bagian Halo Simpeg (MAKE-074, T12b MAKE-097) dan chatbot (§1m README-deploy, K-5) (v1.2) |
| Penghapusan kolom hash password legacy sesudah masa transisi | MT/DEPLOY | Bahan Baku "Catatan Pasca-Fase 8"; tidak disentuh Fase 8 (v1.2) |
| Impor isi QnA dev (C4) dan keputusan K-5 | DEPLOY-002 (H8-13 (b), Horii 16-11-2026) | Keluar dari DoD Fase 8; key C4 tetap per F8-51 saat PN8-6; pelaksana bila dibuat: ReadOne (pemilik MAKE-094) (v1.2) |

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
| BP8-5 | **Lampiran** lewat `AturanLampiran` + `ValidasiBerkas` + `LocalStorageAdapter` yang sudah ada (jpg/jpeg/png/gif/pdf/doc/docx/xls/xlsx, 5 MB — sama dengan legacy `LS:963-975`; struktur isi dicek, MAKE-010); diunduh lewat endpoint ber-otorisasi (BP8-2), bukan URL berkas langsung. **Berlaku untuk lampiran lama (impor, path `arsip/{nip}/inbox/…`) dan baru** (v1.2): aplikasi selalu memakai endpoint E-04 `tiket/{id}/pesan/{idPesan}/lampiran`. Paparan publik lampiran lama lewat alias Nginx `/berkas/` F-10 = **risiko sadar H8-5 (a)** (F8-17, R8-6), bukan jalur aplikasi | `ArsipDataUmum.php:45`, `ValidasiBerkas.php:22-34`; H8-5 `docs/fase8/Kesiapan_Fase8.md:243` |
| BP8-6 | **Push setelah commit lewat queue** (ADR-013), tidak sinkron, dan kegagalan push **tidak menggagalkan** pengiriman pesan (BP-7 Fase 6). Payload tanpa isi rahasia; token tidak pernah dicatat | Legacy mengantre setelah commit (`LS:1078-1090`); N8 |
| BP8-7 | **GET tidak mengubah data.** Penandaan "sudah dibaca" lewat `POST`, bukan efek samping membuka halaman (legacy `LS:907-920`) | Konsisten dengan keputusan Fase 6 (GET yang mengubah data tidak dibawa) |
| BP8-8 | **Waktu:** DB menyimpan UTC (CR-086); `created_at`/`notify_at` diisi aplikasi (`StempelUtc`), bukan default DB; tampilan WIB di FE | CR-086 |
| BP8-9 | **Audit:** semua tulis lewat model `BaseAuditableModel` → `audit_logs` (pengganti `mylog` legacy); hapus menyimpan snapshot `before` | ADR-011/012 |
| BP8-10 | **Throttle** pembuatan tiket dan pesan per akun (batas dikonfigurasi di `Config\HaloSimpeg`), karena legacy tanpa batas dan modul ini paling terbuka. Angka **ditetapkan di A0, dikonfirmasi Horii** (§7.3a) | Pengaman dasar [V2] |
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
| **BE-3 notifikasi** | `Libraries/HaloSimpeg/{TicketNotifService,PenerimaTiket}.php`, ~~`Jobs/PushHaloSimpegJob.php`~~ (gugur v1.3: H8-14 = (a), tanpa job tersendiri); produsen memakai `AntrePushPesan::antreAkun(id_pengguna[])` → job `push-pesan` yang sudah ada; perubahan penerima per akun = **CR-137** (CR berkas bersama Push, commit terpisah di branch MAKE-072, §6.4); implementasi `App\Libraries\HaloSimpeg\RingkasanTiket` untuk `RingkasanTiketInterface` (pengganti `RingkasanTiketNoOp`, MAKE-071, §6.2) | E-06 |
| **FE** | `frontend/src/features/halo-simpeg/*` (kecuali `chatbot/`), route Halo Simpeg di `frontend/src/router` dan item menu `halo` di `nav.config.ts` (aditif, berkas bersama), badge di `frontend/src/shared/layouts/*` (aditif; `RedesignShell.vue` dan `nav.config.ts` = berkas bersama), satu panggilan reset store helpdesk di `clearSession()` `auth.store.ts` (v1.2, berkas bersama). **v1.3 (final):** entri "Tiket Halo Simpeg (n)" di `frontend/src/features/notifikasi/views/NotifikasiPage.vue` (H8-15 (b), berkas bersama); titik penanda badge saat sidebar ciut di `frontend/src/shared/layouts/AppSidebar.vue` (sekitar `:258-261`, CR kecil berkas bersama, commit terpisah dalam #9, MAKE-073); R10 langkah 1 `frontend/src/shared/utils/galatKolom.ts` + `frontend/src/shared/ui/UiPilihBanyak.vue` (diekspor dari `shared/ui/index.ts`; aditif, dipakai helpdesk saja; R10 langkah 2 = migrasi chatbot di paket perbaikan QA chatbot, CR-134 dst.); **v1.4:** kedua berkas R10 dibuat di CR-134 paket 1 (di `main` per `67f3303c`) dan dipakai chatbot sekaligus helpdesk, sehingga MAKE-073 memakainya, tidak membuatnya | E-08 |
| **BE-4 chatbot** | `Controllers/Api/HaloSimpeg/Chatbot/*`, `Libraries/HaloSimpeg/Chatbot/*` (mesin `MesinJawab`/`TfIdf`, `ChatbotService`, `TokenFeedback`, `ImporQna`, `StatistikChatbot`), `Models/HaloSimpeg/Chatbot/*`, `Config/Chatbot.php`, `Config/RoutesChatbot.php`, migration DBV-033, `backend/docs/db-review/DBV-033-*.md`, test `backend/tests/**/HaloSimpeg/Chatbot/*` dan `backend/tests/_support/HaloSimpeg/Chatbot/*`, perintah spark impor isi QnA C4 (`backend/app/Commands/HaloSimpeg/Chatbot/*`, ✔) (nama kelas final di M0, F8-43) | E-10 |
| **FE chatbot** | `frontend/src/features/halo-simpeg/chatbot/*` (widget `VanyaWidget.vue`, halaman admin Chatbot Manager, `routes.chatbot.ts`, fixture test), item menu Chatbot Manager di `nav.config.ts`, satu titik pasang widget di `shared/layouts/RedesignShell.vue`, satu baris import route di `frontend/src/router/index.ts`, satu panggilan reset store di `frontend/src/features/auth/stores/auth.store.ts` `clearSession()` (empat terakhir = berkas bersama, aditif) | E-10 |

| Jalur | Pelaksana | Berkas milik | Tidak boleh disentuh |
|---|---|---|---|
| **BE** | **Handling Case** (H8-1, izin #9) | semua `backend/` **kecuali** berkas BE-4 chatbot, kode impor T12b (MAKE-097), dan `Libraries/Lampiran/ManifestLampiran.php` (T-5, MAKE-097; v1.3), `docs/fase8/8_1.md`, `backend/docs/db-review/DBV-028-*.md` | `frontend/`; berkas BE-4 chatbot |
| **FE** | **Worker Muse** (H8-3, amandemen izin #9; cadangan Worker Fix Bug) | `frontend/src/features/halo-simpeg/*` (kecuali `chatbot/`), item menu `halo` di `nav.config.ts`, route Halo Simpeg di router, badge di layout, reset store helpdesk di `clearSession()`, `docs/fase8/8_2.md`; v1.3: entri `NotifikasiPage.vue`, titik penanda `AppSidebar.vue`, `shared/utils/galatKolom.ts`, `shared/ui/UiPilihBanyak.vue` (semua berkas bersama, aditif, commit terpisah; v1.4: dua berkas R10 sudah dibuat CR-134 paket 1) | `backend/` (kebutuhan kontrak baru → CR ke jalur BE lewat reviewer); `features/halo-simpeg/chatbot/*` kecuali perbaikan QA chatbot yang ditugaskan (CR-134 dst.; v1.4: R10 langkah 2 selesai di CR-134 paket 1) |
| **Chatbot BE** (E-10, MAKE-094) | **ReadOne** (izin #8, pekerjaan selesai; di `main`; v1.4: CR-133 dan CR-134 dst. sisi BE dalam izin #10) | berkas BE-4 chatbot; bagian E-10 di `8_1.md` | `frontend/`; berkas BE-1..BE-3 helpdesk dan DBV-028 |
| **Chatbot FE** (E-10, MAKE-095) | **Worker Muse** (izin #8, pekerjaan selesai; di `main`; v1.4: CR-134 dst. sisi FE dalam izin #10) | berkas FE chatbot; bagian E-10 di `8_2.md` | `backend/`; berkas FE helpdesk saat bertindak sebagai jalur chatbot |
| **Impor master T12b** (MAKE-097) | **ReadOne** (H8-12, amandemen scope izin #9); review DB **WS-2** | kode impor tier T12b di `backend/app/Libraries/Impor/*` dan perintah/test terkait, bagian T12b di `docs/migrasi/MAKE-069-rencana.md`; v1.3: T-5 baris `disiplin_ticket_msg` di `backend/app/Libraries/Lampiran/ManifestLampiran.php` + test + kueri pra-cek + runbook (keputusan reviewer Q-A0-5) | berkas BE-1..BE-4, migration (skema milik DBV-028) |

Titik temu: kontrak API beku (§6.1, §6.5) dan fixture JSON sintetis respons API yang dipakai bersama sebagai bahan test FE; perubahan kontrak
setelah beku = CR yang disepakati kedua jalur lewat reviewer. Untuk chatbot (✔): Chatbot BE menghasilkan contoh respons di
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
reset di `clearSession()`; **helpdesk v1.2**: satu panggilan reset store tiket + badge ringkasan di `clearSession()`
`auth.store.ts:52-60`, MAKE-073, dengan test "store helpdesk kosong sesudah logout/ganti akun" pola AC-E10.20),
**`backend/tests/feature/KontrakMatriksRoleTest.php`** (v1.2: `PREFIKS_CAKUPAN` `:60` saat ini memuat `halo-simpeg/chatbot`;
MAKE-070/071 memperluasnya menjadi prefiks `halo-simpeg` dan menambah baris endpoint §6.1, commit terpisah, AC-E02.7),
`frontend/src/features/notifikasi/*` (v1.2: hanya bila H8-15 memilih entri lonceng; CR berkas bersama lewat reviewer), dan
berkas Push `backend/app/Libraries/Push/*` + `backend/app/Jobs/PushPesanJob.php` (v1.2: hanya bila H8-14 = (a), CR berkas
bersama lewat reviewer; kontrak `PushNotifGatewayInterface` tetap tidak disentuh).

**Berkas bersama final (v1.3, sesudah keputusan Horii 16-11-2026):**

| Berkas | Perubahan | Paket / commit |
|---|---|---|
| `backend/app/Libraries/Push/AntrePushPesan.php`, `PenerimaTokenFcm.php`, `README.md`; `backend/app/Jobs/PushPesanJob.php` | `antreAkun(list<int> $idPengguna, …)` aditif (kunci data `id_pengguna[]`), cabang `id_pengguna` di job, `webAkun()`/`androidAkun()` per akun + dedup token; pemanggil `nip[]` lama dan job yang sudah antre tidak berubah | **CR-137**, commit terpisah di branch MAKE-072, test regresi cuti/LKH/Konket (§6.4) |
| `backend/app/Config/Services.php:805` + `backend/tests/Notifikasi/PusatNotifikasiTest.php:72-86` + `backend/app/Controllers/Api/Notifikasi/README.md:45` + `frontend/src/features/notifikasi/README.md:37` | binding `RingkasanTiket`; test yang meng-`assertNull` `tiket_belum_dibaca` (`:84`) diganti angka (0 untuk role 1–8 tanpa tiket, ditambah pemilik, petugas, petugas-pemilik); README dua sisi diselaraskan (README FE dikoordinasikan reviewer dengan Worker Muse); `RingkasanTiketNoOp` tidak dipakai test mana pun → dihapus di commit yang sama kecuali reviewer menyimpannya sebagai cadangan rollback | MAKE-071, satu commit berkas bersama |
| `backend/tests/feature/KontrakMatriksRoleTest.php:60` | `PREFIKS_CAKUPAN` `halo-simpeg/chatbot` → `halo-simpeg` (route chatbot tetap tercakup) + baris endpoint §6.1 | MAKE-070 (master, ✔ di `main` v1.4), MAKE-071 (tiket), commit terpisah |
| `backend/app/Libraries/Lampiran/ManifestLampiran.php:25-51` | **T-5** (temuan laporan A0; di `Kesiapan_Fase8.md` §6.2 dicatat sebagai T-8 karena nomor T-5 di sana sudah dipakai untuk key CR): baris `'disiplin_ticket_msg' => ['attachments']` di `KOLOM` + test, supaya `lampiran:manifest`/`lampiran:tarik` menyalin lampiran tiket lama; dua kueri pra-cek (B6 irisan tiket NULL + Open + sender yatim; B7 path tidak cocok pola segmen) + runbook | **MAKE-097 (ReadOne)**, keputusan reviewer Q-A0-5; Handling Case tidak menyentuh berkas ini |
| `frontend/src/features/notifikasi/views/NotifikasiPage.vue` | entri "Tiket Halo Simpeg (n)" (H8-15 (b), §6.3) | MAKE-073, commit terpisah |
| `frontend/src/shared/layouts/AppSidebar.vue` (sekitar `:258-261`) | titik penanda + teks `sr-only` jumlah saat `ciut` dan `item.badge > 0`, berlaku untuk semua item ber-badge; test di `shared/layouts/__tests__`. Di `ef83356e` belum ada item ber-badge dan belum ada test badge (dicek ulang di basis B1; bila ada, diperbarui di commit yang sama) | MAKE-073, CR kecil commit terpisah dalam #9 |
| `frontend/src/shared/utils/galatKolom.ts`, `frontend/src/shared/ui/UiPilihBanyak.vue` (+ `shared/ui/index.ts`) | R10 langkah 1: helper galat 422 per kolom dan pilih banyak berbasis `UiCheckbox` + pencarian, aditif, test di `__tests__` masing-masing; chatbot tidak disentuh (rumusan v1.3; v1.4: chatbot ikut memakai, baris berikut) | ~~MAKE-073~~ (gugur v1.4) → **CR-134 paket 1** (`abe677ed`), ✔ di `main` per `67f3303c` |
| `frontend/src/features/halo-simpeg/chatbot/galat.ts`, `chatbot/components/admin/TagPilihan.vue` + pemakainya | R10 langkah 2: migrasi ke versi `shared/` lalu hapus versi lokal; syarat test chatbot tetap **83/83** (VanyaWidget 36, chatbot.amplop 21, ChatbotManager 23, VanyaShell 3), termasuk jalur 422 `stores/percakapan.ts`; testid `chatbot-qna-tag-<id>` tetap. Sampai saat itu duplikasi = deviasi sementara (Lampiran B) | paket perbaikan QA chatbot (CR-134 dst.), ✔ izin Horii 16-11-2026; v1.4: CR-134 paket 1 (`629d4387`), ✔ di `main` per `67f3303c`, duplikasi tertutup (B-30) |

**DILARANG disentuh:** migration yang sudah di `main` (termasuk `CreateDisiplinTicketFase4`, `CreateMasterLayananFase4`);
`Libraries/Layanan/Notifikasi*` dan model `Inbox*` (rumah notifikasi approval); kontrak `Interfaces/PushNotifGatewayInterface.php`;
`Config/Database.php`; `MigrationsDitahan/AddFkNipLayananFase4.php` (keputusan FK di DBV-028, bukan edit berkas lama); berkas legacy rahasia (§Cara pakai 6).
Untuk E-10 juga: modul FAQ (`Controllers/Api/MasterData/FaqController.php`, `Libraries/MasterData/Faq*`), `Libraries/Html/HtmlSanitizer.php`,
`shared/components/SafeHtml.vue`, `shared/utils/sanitizeHtml.ts`, dan `Libraries/Kepegawaian/Pegawai/EksporTabel.php` **dipakai, tidak diubah**
(kebutuhan perubahan → CR lewat reviewer). `Libraries/Kepegawaian/Lampiran/LocalStorageAdapter.php` juga dipakai, tidak diubah,
kecuali A0 memutus hapus berkas legacy lewat CR adapter (§7.5, AC-E05.7). **v1.3:** Q-A0-6 = (a') (Horii 16-11-2026), jadi
adapter **tidak** diubah; hapus lampiran lewat `PenjagaBerkas` (§7.5). `frontend/src/features/kepegawaian/services/berkasUnduhan.ts`
(`ambilBerkas` `:59-66`) dipakai, tidak diubah: pemeriksaan ekspor kosong (200 JSON) ada di service helpdesk (§6.3).

### 3.1 Paket × key × agen × izin (v1.2)

Keputusan Horii 13-11-2026 (H8-1: izin [#9-WorkloadHaloSimpeg], BE Handling Case, DB Validator WS-2) dan 16-11-2026 ("ikut
rekomendasi semua": H8-3, H8-11, H8-12, H8-13). Izin [#8-WorkloadChatbot] = pekerjaannya **selesai** (DBV-033, MAKE-094, MAKE-095
di `main`). Izin [#9-WorkloadHaloSimpeg] = **aktif** dengan amandemen 16-11-2026 (anggota FE = Worker Muse; scope + T12b/MAKE-097).
Setiap paket: branch dari `origin/main`, gate cepat lokal, CI gate penuh, merge atas izin Horii, serah terima lewat reviewer.
**v1.3:** status per 16-11-2026 (A0 ✔, B0 ✔, A1/DBV-028 dibuka) dan baris baru CR-136, CR-137, T-5 di MAKE-097, serta
sub-langkah berkas bersama MAKE-073. **v1.4:** status per 17-11-2026 (`main` `d659acf6`); baris baru CR-138..CR-141; R10
langkah 1 pindah dari MAKE-073 ke CR-134 paket 1; CR-133 dan CR-134 dst. masuk izin [#10-WorkloadQAChatbot] (Horii
17-11-2026: CR-133 oleh ReadOne; CR-134 dst. oleh Worker Muse (FE) dan ReadOne (BE)).

| Urut | Paket | Key | Isi | Agen | Izin | Menunggu | Status |
|---|---|---|---|---|---|---|---|
| 0a | Sinkron status | CR-130 | `Kesiapan_Fase8.md` + progres `08-HaloSimpeg.md` + tabel kartu Trello | Reviewer CR (bagian progres BE: ReadOne; FE: Worker Muse) | — (tanpa kode workload); merge ⛔ izin Horii | — | ✔ di `main` (v1.3; CR-131 lanjutan rujukan baris `ef83356e`) |
| 0b | PRD v1.2 | CR-131 | dokumen ini | Reviewer CR | — (tanpa kode workload); merge ⛔ izin Horii | — | ✔ di `main` `ef83356e` (v1.3) |
| 0c | PRD v1.3 | CR-136 | dokumen ini + `8_1.md`, `8_2.md`, `Kesiapan_Fase8.md`, progres `08-HaloSimpeg.md`: hasil A0/B0, keputusan Horii dan reviewer 16-11-2026, kontrak §6.1b | Reviewer CR | — (tanpa kode workload); merge ⛔ izin Horii | A0 ✔, B0 ✔ | ✔ di `main` `46f4d669` (v1.4) |
| 0d | PRD v1.4 | CR-138 | dokumen ini + `8_1.md`, `8_2.md`, `Kesiapan_Fase8.md`, progres `08-HaloSimpeg.md`: status paket, keputusan Horii, reviewer, dan DB Validator 17-11-2026, ralat label §7.1 | Reviewer CR | — (tanpa kode workload); merge ⛔ izin Horii | DBV-028 ✔, MAKE-070 ✔ | ✔ di `main` (merge `60f6754d`, commit `7ecbe12b`; CR-144) |
| 1 | A0 laporan pemahaman BE (bagian MAKE-070, tanpa kode) | — | §12 register H8 + daftar kewajiban A0 (§6.1a, §7.3a) | Handling Case | #9 | mulai sesudah CR-130 di `main`; laporan disetujui reviewer sesudah CR-131 (PRD v1.2) di `main` | ✔ disetujui reviewer 16-11-2026 (dua putaran revisi; daftar kontrak final §6.1b) |
| 1' | B0 laporan pemahaman FE (bagian MAKE-073, tanpa kode) | — | rencana berkas bersama FE (nav, router, shell, `clearSession()`, lonceng) | Worker Muse | #9 (amandemen) | mulai sesudah CR-130 di `main`; laporan disetujui reviewer sesudah CR-131 (PRD v1.2) di `main` | ✔ disetujui final 16-11-2026 (semua keputusan Horii untuk B0 lengkap) |
| 2 | Skema helpdesk E-01 | DBV-028 | PR `[CR][DBV]`, dokumen `backend/docs/db-review/DBV-028-*.md`; indeks §7.1 (v1.3) dan kueri pra-cek | ditulis Handling Case; DB Validator WS-2 | #9 | A0 disetujui | ✔ di `main` (PR #41, `a5cb7300`, v1.4); DB Validator WS-2 SETUJU Q1–Q10 (§7.1) |
| 3 | Master E-02/E-07 | MAKE-070 | + `PATCH …/status`, matriks role prefiks `halo-simpeg`, `kontak/aktif` + `kontak`, `petugas/saya`, K-A4 (1'), README beku bagian master + fixture §6.1b; v1.4: + S1–S12 (§6.1b), S8 (b) | Handling Case | #9 | DBV-028 di `main` (✔) | ✔ di `main` `56a700b1` (v1.4); README master beku |
| 4 | Tiket E-03..E-05 + ringkasan | MAKE-071 | + lampiran lama/baru lewat E-04, binding `RingkasanTiketInterface` (commit berkas bersama §3), §7.8 (1)(2)(4)(5), kategori NULL (H8-16 (a1)), penyamaran D7 (a), `badge`, hapus lampiran lewat `PenjagaBerkas` (Q-A0-6 (a')), README beku bagian tiket + fixture §6.1b; v1.4: T1–T13 (§6.1b), syarat DBV Q6 dan Q8 (c) | Handling Case | #9 | MAKE-070 (✔); H8-10 (✔ tetap [I], v1.4) | ✔ di `main` (merge `066315c5`; CR-144) |
| 5a | FE helpdesk E-08 + layar admin (B1–B3, B5) | MAKE-073 | AGENTS.md §1, badge, reset store saat logout; v1.3 sub-langkah berkas bersama (commit terpisah): R10 langkah 1 (`galatKolom.ts`, `UiPilihBanyak.vue`; gugur v1.4: sudah dibuat CR-134 paket 1), entri `NotifikasiPage.vue` (H8-15 (b)), titik penanda `AppSidebar.vue` saat ciut, `sisipBadgeHalo` di `RedesignShell.vue`; tata letak master-detail (§6.3); v1.4: path admin `/halo-simpeg-admin/:bagian` (§6.3) | Worker Muse (cadangan Worker Fix Bug) | #9 (amandemen) | kontrak MAKE-070 + MAKE-071 beku (v1.3: B1 bagian master boleh mulai sesudah MAKE-070 beku, `8_2.md` §2); H8-9 ✔ (v1.3, gerbang B3 terpenuhi) | ✔ di `main` (merge `d90d8a75`; B5 bersama MAKE-074 FE `e4392507`; CR-144); temuan QA T-B1, T-B3, T-B4 diperbaiki CR-142 `8bc6c509` |
| 5b | Push E-06 | MAKE-072 | penerima per H8-14 (a) `antreAkun`; §7.8 (3) | Handling Case | #9 | MAKE-071; H8-8 (v1.4: belum ada → payload terbatas F8-16, uji push real G8-7 ke DEPLOY-001.1) | ✔ di `main` (merge `64d68377`, bersama CR-137; CR-144) |
| 5b' | CR berkas bersama Push (v1.3) | CR-137 | `AntrePushPesan::antreAkun`, cabang `id_pengguna` di `PushPesanJob`, `PenerimaTokenFcm::webAkun`/`androidAkun`, satu kalimat `Push/README.md`; test regresi cuti/LKH/Konket (job lama `nip[]` tetap terkirim, akun role 1 tanpa NIP menerima token web, dedup) | Handling Case | #9 | commit terpisah di branch MAKE-072, review lewat reviewer | ✔ di `main` `64d68377` (CR-144) |
| 5c | Impor master T12b | MAKE-097 | salin `id_user` apa adanya (H8-6, K-M11/K-M12); **v1.3 + T-5**: `'disiplin_ticket_msg' => ['attachments']` di `ManifestLampiran::KOLOM` + test + 2 kueri pra-cek (B6, B7) + runbook (jumlah tiket Open di kategori tanpa petugas aktif sesudah impor) | ReadOne; review DB WS-2 | #9 (amandemen scope) | DBV-028 di `main` (✔) | ✔ di `main` (merge `82d698ec`, PR #42; CR-144) |
| 6 | Ekspor E-09 + penutup BE (A5) | MAKE-074 | WIB↔UTC dan audit unduhan (✔ v1.3, Q-A0-9 Horii 16-11-2026; ekspor kosong tidak diaudit), batas baris 10.000 + batas ukuran (ambang diukur di test MAKE-074), sel dipotong 32.767; runbook (jumlah tiket NULL dan irisan NULL + Open + sender yatim, berkas lampiran anomali); v1.4: hasil Q-H19 + EXPLAIN daftar petugas pada gladi impor (DBV-028 Q8 (a)) | Handling Case | #9 | MAKE-071 (M3) | ✔ di `main` (merge `947b94fd`; CR-144); sisi BE temuan QA T-B1 diperbaiki CR-143 `4d06a8c6` |
| 6' | Tombol ekspor (B4) | MAKE-074 (FE) | `UiExportMenu` | Worker Muse | #9 (amandemen) | MAKE-074 BE + MAKE-073 | ✔ di `main` (merge `e4392507`; CR-144) |
| — | Checklist QA Lapis 1 Fase 8 | CR-132 | `docs/fase8/Checklist_QA_Lapis1_Fase8.md` (bagian A chatbot sekarang, bagian B helpdesk sesudah MAKE-073) | Reviewer CR (+ Worker Muse untuk chatbot, usulan) | — (tanpa kode workload); merge ⛔ izin Horii | bagian A: PN8-8 (✔ diterima 16-11-2026, v1.3) | ✔ di `main` `67f3303c` (v1.4); QA Lapis 1 selesai 18-11-2026 pada `e4392507` + verifikasi perbaikan `8bc6c509`, 0 ❌ terbuka (CR-144); SIGN-OFF Horii 18-11-2026, `main` `d8020fc3` (CR-145) |
| — | Test AC-E10.19 sisi log aplikasi | CR-133 | logger uji: teks pesan chatbot tidak masuk log aplikasi (v1.4: juga `audit_logs`) | ReadOne | #10 (v1.4) | — | ✔ di `main` `19a11ed5` (v1.4) |
| — | Perbaikan temuan QA chatbot | CR-134 dst. | v1.3: termasuk R10 langkah 2 (migrasi `chatbot/galat.ts` + `TagPilihan.vue` ke `shared/`, ✔ izin Horii 16-11-2026, syarat test chatbot 83/83). v1.4: paket 1 (di `main` per `67f3303c`): avatar mini rata atas [K], catatan README [V2] PN8-8, R10 langkah 1 + 2 (`shared/` dibuat, versi `chatbot/` dihapus); paket 2 (`d659acf6`): T-02, T-03 | ReadOne (BE) / Worker Muse (FE) | #10 (v1.4) | QA bagian A | ✔ paket 1 dan 2 di `main` (v1.4); sisa QA bagian A 18-11-2026 tanpa temuan baru (CR-144) |
| 9 | Rilis menu (H8-11 (a)) | CR-135 | `ACTIVE_PHASE` 5 → 8, pembersihan `rilis`, item `halo` diberi `to`/`roles` | Worker Muse (atau Worker Fix Bug) | ⛔ izin Horii | sign-off Fase 8 | ◻ sesudah sign-off (SIGN-OFF Horii 18-11-2026, CR-145); menunggu izin Horii |
| — | Helper satker | CR-139 | `WebConfigService::satkerKepegawaian()` dipakai `PetugasLayananService` dan `HaloSimpeg\PetugasService` (method privat di keduanya dihapus, perilaku tetap) | — | — | — | ✔ di `main` `ccb90f78` (v1.4) |
| — | Stabilkan test flaky | CR-140 | `frontend/src/router/__tests__/alurInti.e2e.spec.ts` flaky di bawah beban | — | — | — | ✔ di `main` `6c2b3305` (CR-144) |
| — | Teks paginasi bersama | CR-141 | `frontend/src/shared/ui/UiPagination.vue:42` "Showing … entries" → "Menampilkan a sampai b dari n data" (Horii 17-11-2026, §12) | — | — | — | ✔ di `main` (merge `dd7cafe3`; CR-144) |
| — | Impor isi QnA dev | C4 (key per F8-51 saat PN8-6) | F8-46, AC-E10.30 | ReadOne | izin baru saat PN8-6 | PN8-6 (deploy) | **di luar DoD** (H8-13 (b), DEPLOY-002) |
| ✔ | Skema chatbot | DBV-033 | E-10 | ditulis ReadOne; DB Validator WS-2 | #8 | — | ✔ di `main` `3fb8c5f7` |
| ✔ | BE chatbot | MAKE-094 | E-10 | ReadOne | #8 | — | ✔ di `main` `4af0c188` |
| ✔ | FE chatbot | MAKE-095 | E-10 | Worker Muse | #8 | — | ✔ di `main` `2a8f405d` |

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

### 5.2 Keputusan Fase 8 (LOCKED — Horii 03-11-2026, 12-11-2026, 16-11-2026 (v1.3: keputusan atas usulan A0/B0, §12), dan 17-11-2026 (v1.4: T2 (b), H8-10, PN8-8, §12); ✔ Q8-xx / ✔ K-n = dijawab eksplisit di §12, ✔ (default diterima) = usulan tanpa pertanyaan yang tidak ditolak, ✔ = bawaan usulan reviewer yang menunggu konfirmasi)

| ID | Keputusan | Label | Status |
|---|---|---|---|
| F8-01 | Koding menunggu PRD v1.0 berlaku; M4 (push real) menunggu MAKE-066; M1–M3 paralel dengan Fase 6 (§0.1) | — | ✔ (default diterima) |
| F8-02 | **Model data = tiket per topik** (kategori + subjek + pesan pertama), bukan satu utas per pegawai. Tampilan boleh bergaya chat (Gambar 33/34) tetapi unit data tetap tiket; data impor `inbox` legacy cocok 1:1 | [K `LS:937-1007`] | ✔ Q8-15 |
| F8-03 | **Skema baru hanya tiga tabel** (DBV-028): `support_officer`, `officer_category`, `help_desk_contact`. Kolom ikut legacy ([K]/[I] dari kode karena DDL produksi tidak ada di repo); deviasi [V2]: status 0/1 bertipe tinyint + CHECK, UNIQUE `(id_support_officer, id_question_category)`, FK RESTRICT (kebijakan V1), `support_officer.id_user` → `pengguna.id_pengguna` | [K]+[V2] | ✔ (default diterima) |
| F8-04 | **Pembuat tiket** = role pegawai (`UL_PEGAWAI` 2, 6, 7) yang punya NIP [K `SC:608`]; melihat daftar/percakapan = role 1–7 [K `SC:466,498,550`]; **role 8 (Pimpinan) tidak punya akses** [K, tidak ada di daftar legacy]. Matriks Bahan Baku "role 1–7 sebagai pengguna" dibaca sebagai akses daftar/baca, bukan pembuatan | [K] | ✔ Q8-01 |
| F8-05 | **Master (kategori, petugas, kontak) hanya role 1** untuk tulis [K `SC:13,39,…,438`]; kategori aktif dibaca semua role 1–7 untuk form tiket [K `LS:931`] | [K] | ✔ (default diterima) |
| F8-06 | **Petugas harus berstatus aktif** untuk melihat/menjawab tiket (legacy hanya memeriksa status untuk push & popup, bukan akses: `SC:507-523` vs `LS:1035`, `MC:272`). Petugas dinonaktifkan → kehilangan akses antrean (tiket miliknya sendiri tetap terlihat). **v1.3:** syarat aktif berlaku untuk antrean, `GET tiket?peran=petugas` (non-petugas/nonaktif → 200 `Halaman` kosong, bukan 403), detail, balas, baca, `belum_dibaca_petugas`/`perlu_tindak`, dan ekspor (`bisa_ekspor`). Untuk **push**, melewati petugas/akun nonaktif = [K] (legacy memfilter `so.status='1' AND peng.status='1'`, `LS:1035`, `:1245`); [V2] F8-06 hanya untuk akses dan hitungan | [V2] keamanan | ✔ Q8-02 |
| F8-07 | **Kandidat petugas** = pegawai aktif satker kepegawaian + akun role 1 aktif [K `LS:468-491`]. Satker diambil dari konfigurasi (bukan literal `'101'`): memakai `WebConfigService::value('id_satker_kepegawaian')` seperti PIC layanan (Q-06a Fase 4), dan peringatan bila kosong (pola `PetugasLayananService::PERINGATAN_SATKER_KOSONG`). **v1.4:** pembacaan lewat `WebConfigService::satkerKepegawaian()` (CR-139, `backend/app/Libraries/MasterData/WebConfigService.php:121`); kandidat pegawai = akun **aktif** ber-role 1–7 (S5, S12; respons `{items, peringatan}`, S1). H8-7 belum ada (Horii 17-11-2026): QA bagian B memakai satker kepegawaian demo di Web Config, butir QA yang bergantung pada H8-7 ditandai bersyarat, dan konfirmasi Biro SDM menjadi butir cutover | [K]+[V2] | ✔ Q8-11, **bersyarat**: setelah Biro SDM mengonfirmasi satker `101` = satker kepegawaian (v1.4: butir cutover, H8-7) |
| F8-08 | **Siapa yang menutup**: petugas kategori tiket itu dan pemilik tiket [K controller `SC:676-690`; UI legacy hanya menampilkan tombol untuk petugas `VI:133`]. Tutup: `status=1`, `allow_reply=0`, `is_read=1`, `is_read_off=1` [K `LS:1444-1449`] **dan `closed_by` diisi** (kolom ada tetapi tidak pernah ditulis legacy) [V2 perbaikan]. **Tanpa buka kembali**; pegawai membuat tiket baru [K `LS:1117`]. **v1.3:** legacy menolak pemilik yang tercatat sebagai petugas kategori **lain** untuk balas (`SC:571-579`), tutup (`:676-684`), dan hapus (`:719-727`); v2: pemilik **selalu** boleh balas dan tutup [V2, B-19], hapus tetap hanya petugas (F8-09); test balas oleh pemilik yang petugas kategori lain. Tiket berkategori NULL hanya dapat ditutup pemiliknya (H8-16 (a1)). `ditutup_oleh_label` mengikuti aturan detail D7 (a) (§6.1b) | [K]+[V2] | ✔ Q8-04 |
| F8-09 | **Hapus tiket**: petugas kategori itu (dan role 1 yang juga petugas) — bukan pemilik; hapus fisik [K `LS:1485`] dengan snapshot `before` di `audit_logs`; berkas lampiran ikut dihapus dari storage (legacy meninggalkan berkas yatim, B-2). Legacy controller juga mengizinkan pemilik yang bukan petugas menghapus (`SC:728-733`) walau UI tidak menampilkannya — tidak dibawa. Alternatif hapus lunak butuh ALTER skema. **v1.3:** pemilik (termasuk pemilik tiket berkategori NULL) yang menghapus → 403 `bukan_pihak_berwenang` [V2 terhadap `SC:728-733`]; tiket NULL karena itu tidak dapat dihapus siapa pun, hanya ditutup pemiliknya (H8-16 (a1)), dan jumlahnya dicatat di runbook. Berkas lampiran dihapus lewat `PenjagaBerkas::catat` di dalam transaksi + `selesaikan()` sesudah commit (Q-A0-6 (a'), Horii 16-11-2026): berkas hanya dihapus bila tidak dirujuk baris lain, path legacy anomali tidak dihapus dan dicatat tanpa path (§7.5). **v1.4 (✔ T2 (b), Horii 17-11-2026: F8-09 harfiah):** pemilik tiket **selalu** 403 `bukan_pihak_berwenang` untuk hapus, **walau** ia petugas aktif kategori tiket itu; petugas aktif lain di kategori yang sama boleh menghapus (AC-E05.11) | [K]+[V2] | ✔ Q8-03; ✔ Q-A0-6 (a'); ✔ T2 (b) |
| F8-10 | **Balas**: syarat tiket `status=0` dan `allow_reply=1` [K `LS:1116-1123`]; pelaku = pemilik atau petugas aktif kategorinya. Pesan: teks wajib, lampiran opsional (5 MB, 9 ekstensi) [K `LS:1552-1565`, `LS:963-975`]. Penanda baca otomatis: balasan pemilik → `is_read_off=2`; balasan petugas → `is_read=2` [I — logika trigger `inbox_msg_afIns`, DBV-022 `:301`; M0 mencocokkan dengan D1:7730-7739 lewat reviewer]. **v1.3 (A0):** penanda baca **sebagian [K]**: membuka thread di legacy menyetel `is_read=1` bila pembuka = pemilik, selain itu `is_read_off=1` (`LS:907-919`; di v2 lewat `POST …/baca`, BP8-7); balas cepat petugas menyetel `is_read_off=1` eksplisit (`LS:1344-1347`) beserta `notify_pic`/`notify_at` (`:1348-1352`). Jadi balasan petugas → `is_read_off=1`, `is_read=2`; balasan pemilik → `is_read=1`, `is_read_off=2`. Yang tetap **[I]** (H8-10): penyetelan sisi **lawan** ke 2 dan sisi pembalas **pemilik** = 1; ekstraksi D1 tidak tersedia di mesin A0. Pesan pertama saat buat tiket dikecualikan (flag dari §7.3). Balasan pada tiket berkategori NULL → 422 `tiket_tanpa_kategori` (H8-16 (a1)). **v1.4 (✔ H8-10 = tetap [I], Horii 17-11-2026):** sisi lawan ke 2 dan sisi pembalas pemilik = 1 tetap [I] tanpa ekstraksi trigger, diuji per skenario AC-E04.4; gerbang MAKE-071 terpenuhi | [K]+[I] | ✔ (default diterima); ✔ penanda sebagian [K] (A0); ✔ H8-10 tetap [I] |
| F8-11 | **Pengingat 24 jam** (`notify_pic`/`notify_at`): petugas dapat mencentang saat membalas; reset ke 2 bila petugas membalas lagi [K `LS:1172-1178`, `LS:1348-1352`]. Tiket `perlu_tindak` = `status=0` dan (`is_read_off=2` atau `notify_pic=1` dan `notify_at < sekarang`) [K `MC:292`]. **v1.3:** field masukan `ingatkan_24_jam` ∈ {0,1} (nilai lain → 422 `errors.ingatkan_24_jam`); berlaku hanya bila pembalas = petugas **aktif** kategori dan bukan pemilik; nilai 1 dari pemilik/non-petugas → 422 "Pengingat 24 jam hanya dapat diatur petugas.", nilai 0 atau tanpa nilai diabaikan. Pada balasan petugas: 1 → `notify_pic=1`, `notify_at` = +24 jam UTC; tanpa nilai atau 0 → `notify_pic=2`, `notify_at=NULL` [K `LS:1348-1352`, `:1171-1178`]. Centang legacy hanya ada di popup login (dihapus F8-12), sehingga di v2 pindah ke form balas petugas dengan label legacy "Ingatkan Saya Kembali?" (deviasi tempat, bukan perilaku). `perlu_tindak` per baris memakai ekspresi SQL yang sama dengan `Ringkasan.perlu_tindak` | [K] | ✔ (default diterima) |
| F8-12 | **Popup login petugas dihapus**; diganti badge pada menu Halo Simpeg + panel "Perlu ditindak" di halaman petugas (legacy: modal saat login, hanya role 2/6, `MC:268-305`, `VM:734-807`). **v1.3:** angka badge menu = `Ringkasan.badge` = jumlah tiket **distinct** (belum dibaca ∪ `perlu_tindak`), sehingga pengingat 24 jam yang jatuh tempo ikut terlihat; `total` tetap = angka lonceng (D8 (a), Horii 16-11-2026). Saat sidebar ciut tampil titik penanda + teks `sr-only` jumlah (CR kecil `AppSidebar.vue`, §3). Panel "Perlu ditindak" memuat daftar sendiri lewat `GET tiket?peran=petugas&perlu_tindak=1` (bukan menyaring halaman aktif) | [V2] UX | ✔ Q8-07; ✔ D8 (a) |
| F8-13 | **Penerima push** — tiket baru → petugas aktif kategori (akun aktif); balasan petugas → pemilik; balasan pemilik → petugas aktif kategori [K `LS:1029-1075`, `LS:1202-1285`]. Perbaikan: akun petugas **semua role** diikutkan (legacy hanya `UserLevel='2'`, sehingga petugas role 1/6 tidak pernah dapat push, B-5); token dideduplikasi per perangkat. **v1.2 — kontradiksi internal:** titik sambung MAKE-066 `AntrePushPesan::antre(array $nips, …)` (`backend/app/Libraries/Push/AntrePushPesan.php:48`) dan `PushPesanJob` (`backend/app/Jobs/PushPesanJob.php:39-47`) mencari token per **NIP** (§6.4 "per NIP"), padahal kandidat petugas mencakup akun role 1 yang umumnya tanpa NIP (F8-07), sehingga petugas role 1 tanpa NIP tidak akan menerima push (AC-E06.7). Diputus lewat **H8-14** sesudah A0 (§12). **v1.3 — ✔ H8-14 (a) (Horii 16-11-2026):** semua penerima Halo Simpeg (petugas maupun pemilik) diantre **per `id_pengguna`** lewat `AntrePushPesan::antreAkun(list<int> $idPengguna, …)` (aditif, job `push-pesan` yang sama, kanal `semua` F8-15); token web per akun (`firebase_token.id_pengguna`) dan token Android lewat `pengguna.nip` bila ada; pelaku dikecualikan; akun nonaktif dan petugas nonaktif dilewati [K B4, F8-06]; token yang sama untuk dua penerima dikirim sekali. Perubahan berkas Push = **CR-137** (§3, §6.4) | [K]+[V2] | ✔ (default diterima); ✔ H8-14 (a) |
| F8-14 | **Perubahan status (tutup) memberi notifikasi** ke pihak lawan sesuai Bahan Baku E-06 (legacy tidak) | [V2] | ✔ Q8-05 |
| F8-15 | **Kanal push**: Android ikut legacy; **web ditambahkan** karena infrastruktur web push Fase 6 sudah ada dan petugas bekerja di web (legacy hanya Android untuk Halo Simpeg) | [K]+[V2] | ✔ Q8-09 |
| F8-16 | **Payload push**: judul "Halo Simpeg", isi = teks pesan terpotong; `data` = `{modul:'halo-simpeg', id_inbox, url}` + kunci kompatibilitas Android yang dikonfirmasi PN8-1. Legacy mengirim seluruh baris tiket (nip, jabatan, satker, nama pengirim) — **tidak** dibawa kecuali pemilik app Android memastikan membutuhkannya | [V2] privasi | ✔ Q8-08, **bersyarat**: payload minimal setelah pemilik app Android mengonfirmasi (PN8-1); sebelum itu hanya kunci `data` yang dibaca app; v1.4: H8-8 belum ada → MAKE-072 memakai payload terbatas, uji push real (G8-7) dicatat ke DEPLOY-001.1 |
| F8-17 | **Lampiran** memakai `AturanLampiran`/`ValidasiBerkas` yang ada (BP8-5); path `halo-simpeg/{nip}/{id_inbox}/…` di storage lokal (pola kunci final diputus di A0, T-4); unduh lewat endpoint ber-otorisasi. Berkas lampiran impor (path legacy `arsip/{nip}/inbox/…`) dicocokkan saat cutover. **v1.2 — H8-5 (a), Horii 13-11-2026 (risiko sadar):** lampiran lama tetap ber-path legacy dan **ikut tersaji publik** lewat alias Nginx `/berkas/` F-10 seperti legacy (F10-18; README-deploy §1l); Halo Simpeg **tidak** mempersempit alias itu. Aplikasi v2 **tetap** menyajikan lampiran lama **dan** baru lewat endpoint E-04 `tiket/{id}/pesan/{idPesan}/lampiran` dengan cek akses BP8-2 (AC-E04.7), **bukan** dengan merujuk URL publik `/berkas/`: saklar rollback `arsip.penyajianBerkasLegacy = 'privat'` (`backend/app/Config/Arsip.php:12-17`, `:97`) memindahkan berkas legacy ke endpoint arsip F-10 yang izinnya per NIP pegawai, sehingga tautan `/berkas/` akan putus dan petugas kehilangan akses. `LocalStorageAdapter::baca()` sudah menerima path legacy (`backend/app/Libraries/Kepegawaian/Lampiran/LocalStorageAdapter.php:78`, `:104-109`). Unggahan v2 tetap privat. **v1.3 — pola kunci final (K-A6, A0):** `halo-simpeg/{YYYY}/{MM}/{32 hex}.{ext}` (UTC, `random_bytes(16)`, pola modul v2 `berita/…`, `kolom/YYYY/MM/…`); rumusan `halo-simpeg/{nip}/{id_inbox}/…` di atas **gugur v1.3** (NIP di path = data pribadi dan `id_inbox` belum ada saat berkas disimpan sebelum transaksi). Awalan `halo-simpeg/` tidak cocok regex legacy `/berkas/` maupun `REGEX_KUNCI_V2` (`backend/app/Config/Arsip.php:32`), sehingga tetap privat; kunci baru tidak boleh berawalan `arsip/` atau `assets/upload/`. Nama asli berkas tidak disimpan; nama unduhan = `lampiran-{id_tiket}-{id_pesan}.{ext}` = `Content-Disposition` = `lampiran.nama` di respons. Unggahan dicatat `PenjagaBerkas::catat(path, 'disiplin_ticket_msg', 'attachments')` agar dikompensasi bila transaksi gagal (AC-E03.6). Header unduhan E-04: `Content-Type` dari ekstensi `AturanLampiran`, `Content-Disposition` inline untuk pdf/jpg/jpeg/png/gif dan attachment untuk lainnya, `X-Content-Type-Options: nosniff`, CSP `Config\Arsip::CSP_BERKAS`, `Cache-Control: private, no-store`. Lampiran lama baru tersalin ke storage v2 bila T-5 (Q-A0-5, MAKE-097) selesai | [K]+[V2] | ✔ Q8-10; ✔ H8-5 (a); ✔ K-A6 (A0) |
| F8-18 | **Kontak helpdesk**: CRUD role 1 [K `SC:11-160`]; daftar kontak aktif dapat dibaca semua role 1–7 (di halaman Halo Simpeg) — legacy hanya menampilkannya ke admin. **v1.3 (Q-A0-7, keputusan reviewer):** dua path — `GET halo-simpeg/kontak/aktif` (role 1–7, array kontak `status=1`, urut `created_at` naik [K `LS:16`], tanpa paginasi) dan `GET halo-simpeg/kontak` (role 1, semua status, `Halaman<T>`, filter `status`, `q`); role 2–7 yang memanggil `kontak` → 403 dari filter route. Usulan parameter `?status=semua` gugur | [K]+[V2] | ✔ Q8-12; ✔ Q-A0-7 |
| F8-19 | **Lonceng**: Fase 8 menyediakan `GET halo-simpeg/tiket/ringkasan` (jumlah belum dibaca sisi pemilik + sisi petugas [K `LN:12-60`]) dan **tidak** menulis ke `inbox`. Konsumsi oleh lonceng terpadu F-08 = keputusan Q8-06. **v1.2:** F-08 sudah di `main`, jadi Q8-06 = B berlaku: MAKE-071 mengganti binding `ringkasanTiket` NoOp (`backend/app/Config/Services.php:799-806`) dengan implementasi `RingkasanTiketInterface` yang memakai aturan hitung §6.2, dan `PusatNotifikasiService` (`backend/app/Libraries/Notifikasi/Pusat/PusatNotifikasiService.php:74`, `:110`) mengisi `tiket_belum_dibaca` dengan angka (AC-E06.9). Tujuan klik angka lonceng = **H8-15** (§12). **v1.3:** `GET tiket/ringkasan` → `Ringkasan {belum_dibaca_saya, belum_dibaca_petugas, perlu_tindak, badge, total}` (§6.2); `jumlahBelumDibaca()` = `total` (satu sumber hitung, sehingga AC-E06.9 berlaku menurut konstruksi) dan mengembalikan **0** untuk role di luar 1–7 (role 8 lonceng), `null` hanya untuk sumber yang belum terpasang. **✔ H8-15 (b) (Horii 16-11-2026):** entri "Tiket Halo Simpeg (n)" di `NotifikasiPage.vue` (kartu/baris di atas tab, angka dari `tiket_belum_dibaca` yang sudah ada) menuju route Halo Simpeg; ke tab antrean bila sisi petugas punya angka (`belum_dibaca_petugas`/`perlu_tindak` > 0 dari ringkasan helpdesk, bila sudah dimuat), selain itu ke `/halo-simpeg`; hanya `NOTIFIKASI_ROLES` 1/2/3/6/7 (`frontend/src/features/notifikasi/roles.ts:16`; klik lonceng `RedesignShell.vue:127-130`); petugas role 4/5 (petugas bisa ber-role 3/4/5 karena kandidat legacy = akun apa pun milik NIP satker kepegawaian, `LS:470-484`) memakai badge menu. BE tanpa perubahan kontrak | [K]+[V2] | ✔ Q8-06 (sumber lonceng hanya bila F-08 sudah ada saat M5; selain itu badge menu saja dan disambung di F-08); ✔ H8-15 (b) |
| F8-20 | **Ekspor rekap** `export_hs` ikut legacy: hanya petugas; periode berdasar tanggal tiket (inklusif); kolom NO, ID PERTANYAAN, KATEGORI, NAMA PENANYA, NIP PENANYA, PERTANYAAN / JAWABAN, TIMESTAMP; xlsx [K `LS:1600-1830`, `SC:751-786`]. Perbaikan: sertakan tiket yang periode-nya cocok walau petugas belum menulis, urut tiket lalu pesan. **v1.2:** `ds`/`de` = tanggal kalender **WIB** yang dikonversi ke rentang UTC tanpa `DATE()` atas kolom (pola F8-42); kolom TIMESTAMP ditulis dalam WIB; unduhan ekspor diaudit (pola F7-27/F-10: siapa, periode, jumlah baris, tanpa isi; AC-E09.7). **v1.3:** WIB↔UTC dan audit unduhan **✔ Q-A0-9 (Horii 16-11-2026)**; ekspor periode kosong = 200 JSON tanpa berkas dan **tanpa audit unduhan** (tidak ada berkas; dicatat di README). Tombol/izin ekspor = `bisa_ekspor` = petugas **aktif** berapa pun jumlah kategorinya [V2 terhadap `VI:13` yang mensyaratkan > 1 kategori; keputusan reviewer]; selain itu 403 `bukan_petugas`. Pengaman sebelum membangun berkas: `COUNT` baris ≤ `eksporMaksBaris` (10.000) **dan** `SUM(CHAR_LENGTH(msg))` ≤ `eksporMaksKarakter` (ambang dari pengukuran memori di test MAKE-074); lewat salah satu → 422 `ekspor_terlalu_besar`. Isi sel dipotong di 32.767 karakter (batas sel Excel) dengan penanda "… [dipotong]" [V2], karena pesan impor legacy tidak dibatasi. Tiket berkategori NULL tidak ikut (H8-16 (a1)). `EksporTabel` menulis OOXML lewat ZipArchive (`backend/app/Libraries/Kepegawaian/Pegawai/EksporTabel.php:11-13`) | [K]+[V2] | ✔ (default diterima); ✔ Q-A0-9 (WIB↔UTC, AC-E09.7) |
| F8-21 | **Impor master** (**v1.2, H8-6 Horii 13-11-2026 "seperti legacy saja"**): `support_officer`, `officer_category`, `help_desk_contact` dipindah saat cutover (daftar petugas dan kategori berlaku langsung); `support_officer.id_user` **disalin apa adanya** ke kolom akun v2 **tanpa tabel pemetaan UP-1** (K-M11); baris yang `id_user`-nya tidak ada di `pengguna` v2 **dibuang dan jumlahnya dicatat** di laporan impor (K-M12), dan `officer_category` milik baris yang dibuang ikut dibuang (`docs/migrasi/MAKE-069-rencana.md:108-111`). Kode impor = tier T12b **MAKE-097** (ReadOne, review DB WS-2; H8-12), mulai sesudah DBV-028 di `main`. Rumusan v1.0 ("dipetakan lewat tabel pemetaan UP-1; baris tanpa pemetaan dilaporkan") **gugur**. **v1.3 (T-5, keputusan reviewer Q-A0-5):** `disiplin_ticket_msg.attachments` belum tercantum di `ManifestLampiran::KOLOM` (`backend/app/Libraries/Lampiran/ManifestLampiran.php:25-51`), sehingga `lampiran:manifest`/`lampiran:tarik` tidak menyalin lampiran tiket lama dan E-04 akan 404 untuk semua lampiran lama; barisnya + test + 2 kueri pra-cek + runbook masuk **MAKE-097**. Runbook juga menghitung tiket Open di kategori tanpa petugas aktif sesudah impor (kueri angka) | [K] (ikut legacy) | ✔ Q8-13; ✔ H8-6; ✔ Q-A0-5 |
| F8-22 | **Tidak dibawa**: popup modal login (F8-12), `firebase_message_logs` (digantikan `integrasi_log` Fase 6), `andr_notif_queue` (digantikan queue `push`), file view bertanggal (`inbox_20220216.php` dst.), cabang debug berkode-keras NIP (`SC:469`), log `mylog` rusak (B-9) | — | ✔ (default diterima) |

**E-10 Chatbot "Vanya" (v1.1).** F8-23..F8-33 = keputusan Horii 12-11-2026 ("ikut saran" K-1..K-11, §12). F8-34..F8-51 =
bawaan teknis usulan reviewer, **dikonfirmasi Horii 12-11-2026 ("setuju semua")** (✔); rincian perilaku di §7.10.

| ID | Keputusan | Label | Status |
|---|---|---|---|
| F8-23 | **Chatbot masuk Fase 8** sebagai task baru **E-10 Chatbot "Vanya"** (revisi PRD v1.1, key revisi CR-121). Baris lama "Chatbot / pencarian FAQ terintegrasi — tidak ada di legacy — usulan masa depan" di §1.2 diganti (bahan legacy dev tersedia 12-11-2026) | — | ✔ K-1 |
| F8-24 | **Nama & persona tetap "Vanya"** ("asisten virtual SDMO") dengan avatar ilustrasi SVG legacy dev; teks sapaan, kartu hover, dan kalimat fallback ikut dev | [K dev `dev:views/hr/chatbot/chatbot_popup.php:29-82`, `:371`; `dev:libraries/hr/L_chatbot.php:746`] | ✔ K-2 |
| F8-25 | **Skema = DBV terpisah DBV-033**: 6 tabel `qna`, `qna_kategori`, `qna_tag`, `qna_has_tag`, `qna_status`, `log_chatbot` ikut DDL dev hasil D1 chatbot (PN8-5); status **1 Aktif / 2 Tidak Aktif / 10 Dihapus** (memperbaiki A1: di dev Tidak Aktif = Dihapus = 2). Kolom final ditetapkan DBV-033 sesudah D1; DBV-028 helpdesk tidak tertahan | [K dev `dev:libraries/hr/L_chatbot.php:48-50`, `:124`; DDL D1 blok [1]–[2]]+[V2] | ✔ K-3 |
| F8-26 | **`log_chatbot` anonim** seperti dev (tanpa `id_pengguna`/NIP). Kepemilikan feedback 👍/👎 dijaga **token bertanda tangan per log** (pengganti session PHP, A7) yang dikembalikan di respons balas dan wajib dikirim saat beri feedback; `id_pengguna` tidak disimpan di tabel. Rincian token: F8-35 | [K dev `dev:libraries/hr/L_chatbot.php:749-756`]+[V2] | ✔ K-4 (rincian ✔ F8-35) |
| F8-27 | **Isi QnA awal bergantung D1 chatbot**: bila isi dev layak (D1 blok [4]), dibawa ke v2 lewat impor sekali jalan (CSV blok [7] → perintah impor sekali jalan dalam runbook cutover, aturan F8-46; CSV dan isi QnA dev tidak di-commit); bila tidak, v2 mulai kosong dan diisi admin (form/impor Excel). Ditetapkan Horii **saat deploy/cutover** (PN8-6; ditunda Horii 12-11-2026, D1 sudah lengkap) | — | ✔ K-5, **ditunda ke deploy** |
| F8-28 | **Jawaban berformat HTML dibatasi whitelist** seperti FAQ v2: sanitasi saat simpan di BE + render lewat `SafeHtml`/DOMPurify di FE (BP8-14, A2) | [K dev `dev:views/hr/chatbot/chatbot_popup.php:325` (jawaban HTML)]+[V2] keamanan | ✔ K-6 |
| F8-29 | **Ekspor hanya daftar tag** (ikut dev); tanpa ekspor QnA | [K dev `dev:libraries/hr/L_chatbot.php:216-252`] | ✔ K-7 |
| F8-30 | **Widget hanya untuk role 2/6/7** (`Role::UL_PEGAWAI`): widget tidak dirender untuk role lain dan endpoint widget menolak role lain (403) | [K dev `dev:controllers/hr/Chatbot.php:215-243`; `dev:views/hr/chatbot/chatbot_popup.php:5`] | ✔ K-8 |
| F8-31 | **Chatbot berdiri sendiri**: tanpa tombol "Tanya petugas"/buat tiket Halo Simpeg; integrasi itu usulan lanjutan di luar scope (§1.2) | [K dev `dev:controllers/hr/Chatbot.php:214-243` (tidak ada aksi tiket)] | ✔ K-9 |
| F8-32 | **Admin Chatbot Manager = role 1 saja** (Kategori, Tag, QnA, impor/ekspor, Statistik, Riwayat) | [K dev `dev:controllers/hr/Chatbot.php:58-211`] | ✔ K-10 |
| F8-33 | **Desain ikut tampilan widget legacy dev**, disesuaikan token desain v2, tanpa menunggu Figma (dicatat seperti PN8-4); halaman admin ikut pola tabel v2 (`page-fill` + ⋮). **v1.4 (PN8-8, Horii 17-11-2026 "ikut rekomendasi"):** selisih tampilan yang disengaja terhadap tangkapan layar dev = [V2]: avatar tetap ilustrasi SVG (foto tidak dipakai, PN8-7); aksen biru `brand-tertiary`, bukan pink dev; saat memuat, tombol/chip disabled + indikator (perilaku v2); launcher 64 px dan gaya nama "Vanya" tetap v2. Perbaikan [K] avatar mini rata atas tuntas di CR-134; rincian per elemen di README `features/halo-simpeg/chatbot` | [K dev `dev:views/hr/chatbot/chatbot_popup.php:29-100`, `:102-205`]+[V2] | ✔ K-11 |
| F8-34 | **Mesin jawab port apa adanya** (§7.10.2): tokenisasi, TF-IDF, cosine, ambang dari skor asli, pengali feedback hanya untuk kandidat lolos ambang, bubble langsung skor 1,0, fallback 3 QnA unggulan terbaru. Semua angka di `Config\Chatbot` (ambang 0,25; bobot feedback 0,10; smoothing 5; maks bubble pertanyaan 6; jumlah populer 3; kandidat dicatat 3; maks pesan 300) dan diuji dengan test pembanding hasil legacy dev. Kandidat berskor sama diurutkan `id_qna` menaik. Semantik tokenisasi ikut dev persis, termasuk token `"0"` ikut terbuang dan kesetaraan token angka mengikuti perbandingan longgar `==` PHP (§7.10.2 langkah 4; alternatif perbandingan ketat = pertanyaan §12). Batas 300 dihitung `mb_strlen` UTF-8 sesudah trim | [K dev]+[I] (urutan seri di dev bergantung urutan hasil `GROUP BY` dan `usort`, `dev:libraries/hr/L_chatbot.php:679-685`, `dev:libraries/Nlp_engine.php:99-101`) | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-35 | **Rincian token feedback (K-4)**: token = `base64url(kedaluwarsa) . '.' . base64url(HMAC-SHA256(kunci, 'vanya-fb\|v1\|' + id_log + '\|' + id_pengguna + '\|' + kedaluwarsa))`; `kedaluwarsa` = detik Unix (bilangan bulat, UTC); kunci khusus di `.env` (nama kunci di `.env.example` tanpa nilai, minimal 32 byte, tidak memakai ulang rahasia JWT); masa berlaku 24 jam; diverifikasi dengan `hash_equals`; token yang tidak terurai, diubah, kedaluwarsa, atau milik akun lain → 403 yang sama (§6.5); token dan kunci tidak pernah dicatat. Nilai boleh ditimpa selama token berlaku [K dev `dev:libraries/hr/L_chatbot.php:799-800`]; hanya log berstatus cocok yang dapat dinilai [K dev `:791`]. Kunci kosong atau kurang dari 32 byte = **tidak tersedia** → balas tetap jalan dengan `bisa_feedback = false` tanpa `token_feedback`, feedback → 403, dan peringatan di log aplikasi; pemeriksaan kunci ini masuk daftar periksa `README-deploy.md` agar salah konfigurasi tidak mematikan penilaian diam-diam. Dev membatasi 50 `id_log` terakhir per session (`:577`, `:662-670`); batas itu digantikan masa berlaku token. Token hanya menjaga kepemilikan log, bukan satu suara per pengguna per QnA (R8-17) | [K dev]+[V2] | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-36 | **Throttle per akun** (A5) lewat `Services::throttler()` pola `DokumenService::wajibThrottle`: balas 20 per menit, feedback 30 per menit, endpoint baca widget 60 per menit sebagai **kuota gabungan** ketiga endpoint baca (`widget/kategori`, `widget/kategori/{id}/pertanyaan`, `widget/populer`; satu kunci throttle per akun); tiga kelompok itu dihitung terpisah; melebihi → 429 berbahasa Indonesia; permintaan balas yang kena 429 tidak menulis `log_chatbot`; angka di `Config\Chatbot` | [V2] keamanan | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-37 | **Batas impor Excel** (A4): hanya `.xlsx` (template dev `.xlsx`, pembaca Xlsx `dev:libraries/hr/L_chatbot.php:413`); maks 2 MB dan 1.000 baris data; berkas diperiksa dengan PhpSpreadsheet (bukan MIME browser); satu transaksi semua-atau-tidak [K dev `:417-465`] dengan laporan galat per baris (nomor baris + alasan). Baris judul harus sama dengan template F8-47 (4 kolom, urutan sama); berbeda → 422 "Format template tidak sesuai." (teks usulan) tanpa memproses baris [V2]. Baris tanpa pertanyaan atau jawaban dilewati dan dilaporkan [K dev `:425`]; baris tanpa kategori ditolak [V2, konsisten dengan form `:318`]; kategori/tag dicocokkan berdasarkan judul dengan aturan F8-40 (trim, abaikan huruf besar/kecil) di antara status 1/2, termasuk kategori/tag yang baru dibuat oleh baris sebelumnya di berkas yang sama (satu judul = satu id); judul yang cocok dengan kategori/tag **Tidak Aktif** → baris ditolak [V2]; yang belum ada dibuat otomatis berstatus 1 (kategori memakai DEFAULT `tampil_bubble = 0` DDL dev [K dev D1]; dev tidak mengisinya eksplisit `:434`) [K dev `:428-459`]; daftar tag satu baris dipecah koma, di-trim, yang kosong dibuang [K dev `:449`], lalu **dideduplikasi** tanpa membedakan huruf besar/kecil [V2; dev menyisipkan relasi ganda `:488-496`, yang akan melanggar UNIQUE `qna_has_tag` v2]; QnA impor `is_fallback_option = 0` [K dev `:439-445`]; jawaban disanitasi (BP8-14) | [K dev]+[V2] | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-38 | **Status & hapus**: Hapus = set status 10 (hapus lunak; dev "mengarsipkan" `dev:libraries/hr/L_chatbot.php:120-127`, `:207-214`, `:370-377`); daftar admin menampilkan status 1 dan 2 dengan filter status (bawaan Aktif, ikut dev `dev:views/hr/chatbot/list_qna.php:25-29`); baris status 10 disembunyikan dan **tanpa Pulihkan** (dev tidak punya). Baris status 10 tidak ikut widget, pencocokan impor, maupun ekspor; relasi `qna_has_tag` tidak dihapus saat tag dihapus lunak (perlakuannya di QnA: F8-49). Aktifkan/Nonaktifkan lewat endpoint `PATCH …/{id}/status` terpisah (§6.5) | [K dev]+[V2] | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-39 | **Korpus**: QnA status 1 [K dev `dev:libraries/hr/L_chatbot.php:683`] dengan tag **status 1 saja** [V2; dev menggabungkan tag berstatus apa pun, A10]. QnA aktif di kategori Tidak Aktif **atau Dihapus** **tetap** ikut korpus ketikan bebas (bubble topik menyembunyikan kategorinya) [K dev: korpus tidak menyaring kategori `dev:libraries/hr/L_chatbot.php:679-685`; hapus kategori hanya mengubah status kategori `:120-127`] | [K dev]+[V2] | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-40 | **Judul kategori dan judul tag unik** (trim, abaikan huruf besar/kecil sesuai collation `_ci`) di antara status 1 dan 2, ditegakkan **aplikasi** di dalam transaksi tulis (422) (A11). Judul yang sama dengan baris status 10 **boleh** dipakai lagi (baris baru), sehingga UNIQUE biasa atas `judul` **tidak** dipakai (akan memblokir judul yang pernah dihapus dan memicu galat 1062 saat impor). Opsi indeks DB untuk DBV-033: kolom bangkitan, mis. `judul_unik = IF(status <> 10, judul, NULL)` + UNIQUE. D1: data dev memuat 2 judul kategori kembar dan 11 judul tag kembar; saat impor isi dev keduanya **digabung** (F8-46). **Keputusan Horii 12-11-2026 (K-12): judul kembar digabung saat impor, lalu judul dibuat unik** | [V2] | ✔ K-12 |
| F8-41 | **Validasi & batas panjang**: pesan jalur ketikan wajib, 1..300 karakter (`mb_strlen` UTF-8 sesudah trim), ditegakkan BE [V2; dev hanya `maxlength` HTML `dev:views/hr/chatbot/chatbot_popup.php:95`, A14]; jalur bubble: F8-48; pertanyaan, judul, dan jawaban mengikuti tipe kolom DDL dev (`pertanyaan`/`jawaban` TEXT, `judul_kategori` varchar(150), `judul_tag` varchar(100) [K dev D1]; final DBV-033); jawaban yang kosong setelah sanitasi ditolak 422 (pola `FaqArticleHooks`); kategori QnA wajib dan berstatus 1, tag yang **ditambahkan** berstatus 1 [K dev `dev:libraries/hr/L_chatbot.php:318`, `:472-478`] (kategori/tag lama yang sudah terpasang: F8-49); simpan/ubah QnA + relasi tag dalam satu transaksi, ubah = ganti seluruh set tag [V2; dev tanpa transaksi `:323-333`, `:352-365`, A13] | [K dev]+[V2] | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-42 | **Riwayat & statistik** (role 1): riwayat dengan paginasi server, urut waktu terbaru, filter rentang tanggal, status cocok, dan feedback (A6); statistik = KPI dan tiga tabel top-10 ikut dev (`dev:libraries/hr/L_chatbot.php:502-555`); "hari ini" dan filter tanggal = hari kalender WIB yang dikonversi ke rentang UTC (BP8-8, tanpa `DATE()` atas kolom, A8); tanpa ekspor riwayat; tanpa penghapusan log otomatis [K dev] | [K dev]+[V2] | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-43 | **Penempatan kode & menu**: route `api/v1/halo-simpeg/chatbot/...` di `Config/RoutesChatbot.php` (didaftarkan di `Config/Routing.php:34-61`, baris `RoutesChatbot` `:60-61` di `603af133`); folder `Chatbot` di bawah `Controllers/Api/HaloSimpeg`, `Libraries/HaloSimpeg`, `Models/HaloSimpeg`, dan `features/halo-simpeg`; menu admin Pengaturan → "Chatbot Manager" (Kategori, Tag, QnA, Statistik, Riwayat) role 1 `phase: 8`; route halaman admin di `features/halo-simpeg/chatbot/routes.chatbot.ts` (satu baris import di `frontend/src/router/index.ts`, pola `routes.*` per fitur `:13-27`, import chatbot `:23` di `603af133`); widget dipasang sekali di `shared/layouts/RedesignShell.vue` dengan syarat render F8-30 + `!auth.mustChangePassword` (pola `NotifikasiPushShell` `RedesignShell.vue:187`, widget terpasang `:199` di `603af133`; selama wajib ganti password semua route non-`jwt:ganti_password` ditolak 403, `backend/app/Filters/JwtAuthFilter.php:49`) + gerbang rilis F8-50 | [V2] pola | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-44 | **Kontrak widget** memakai amplop v2 (bukan `status: 'sukses'/'kosong'` dev): daftar kosong = 200 dengan `data: []`; pesan jalur ketikan kosong/lebih dari 300 → 422 (F8-41, F8-48); korpus QnA kosong → 200 dengan balasan sistem "Database QnA masih kosong.", `cocok = false`, tanpa baris log [K dev `dev:libraries/hr/L_chatbot.php:687-689`] | [K dev]+[V2] | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-45 | **Avatar**: ilustrasi SVG dev di-port sebagai komponen Vue; foto opsional = aset statis FE yang diserahkan PN8-7 (bukan unggah lewat aplikasi, tanpa pemeriksaan berkas saat runtime seperti `dev:views/hr/chatbot/chatbot_popup.php:19-27`); foto gagal dimuat → kembali ke SVG [K dev `:396-411`] | [K dev]+[V2] | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-46 | **Aturan impor isi dev** (hanya bila F8-27 membawa isi): **QnA** — hanya baris berstatus 1 yang dibawa (status 2 dev bisa berarti Tidak Aktif atau Dihapus, A1); QnA tanpa kategori (`id_qna_kategori_fk` NULL dimungkinkan DDL dev [K dev D1] dan impor dev, A13) **dilaporkan dan tidak dibawa** sampai Horii/admin menetapkan kategorinya (alternatif: kategori penampung, pertanyaan §12). **Kategori/tag** — status 1 dibawa sebagai status 1; status 2 dev yang **masih dipakai QnA aktif** dibawa sebagai status 2 (Tidak Aktif) agar QnA itu tetap terjawab seperti di dev (F8-39) dan relasinya tidak yatim; status 2 lainnya tidak dibawa. Relasi tag yatim/ganda dibuang dan dilaporkan; jawaban disanitasi dan baris yang berubah oleh sanitasi dilaporkan; isi disaring sesuai §Cara pakai 6 sebelum impor (kontak/URL internal/nama nyata dilaporkan ke Horii, D1 blok [6]); `id` dev tidak wajib dipertahankan karena `log_chatbot` tidak dimigrasi. **Pelaksanaan:** perintah impor sekali jalan (bukan seeder) yang menerima path CSV di **luar repo** sebagai argumen saat cutover; CSV dan isi QnA dev tidak pernah di-commit; test memakai CSV sintetis; satu transaksi semua-atau-tidak; menolak berjalan bila tabel QnA sudah berisi (tidak menggandakan isi). **Keputusan Horii 12-11-2026:** (K-12) judul kategori/tag kembar **digabung** ke satu baris (baris aktif ber-id terkecil dipertahankan, relasi QnA dari baris lain dialihkan ke sana, relasi yang jadi ganda dibuang); (K-13) kategori uji/nonaktif (mis. "test…") **tidak dibawa**, kategori **aktif** yang belum punya QnA **tetap dibawa** agar admin bisa mengisinya. Angka D1: relasi ganda 1, QnA tanpa kategori 0 | [V2] | ✔ K-12/K-13 (rincian dikonfirmasi 12-11-2026) |
| F8-47 | **Ekspor tag & template impor**: xlsx lewat `EksporTabel::xlsx` (anti formula injection), kolom No, Tag, Status [K dev `dev:libraries/hr/L_chatbot.php:227-236`]; status 1 dan 2 saja [V2; dev mengekspor semua status `:217-221`]; nama berkas `Export_Tag_QnA_{Ymd_His}.xlsx` (waktu WIB) [K dev `:245`]. Template impor 4 kolom "Pertanyaan", "Jawaban", "Kategori", "Tag (Pisahkan dengan koma)" + satu baris contoh [K dev `:379-403`] | [K dev]+[V2] | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-48 | **Jalur bubble & isi log**: bila `id_qna` ada di korpus, BE memakai `pertanyaan` QnA dari DB sebagai `pesan_user` di log dan **mengabaikan** teks `pesan` klien (hasil sama dengan dev pada pemakaian normal, karena widget dev mengirim teks pertanyaan itu sendiri `dev:views/hr/chatbot/chatbot_popup.php:319-327`, `:341`); batas 1..300 (F8-41) **hanya** untuk jalur ketikan, sehingga bubble/saran untuk QnA berpertanyaan panjang (`pertanyaan` TEXT [K dev D1]) tetap dijawab. `id_qna` tidak sah → jalur ketikan dengan validasi F8-41. `pertanyaan` **tidak** dibatasi 300 di form/impor (alternatif = pertanyaan §12) | [K dev]+[V2] | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-49 | **QnA yang induknya berubah status**: (a) tag status 2 yang terpasang tampil di daftar/form admin berbadge "Tidak Aktif" dan **dipertahankan** saat QnA disimpan bila tidak dilepas admin (dev membuangnya diam-diam karena form hanya memuat tag aktif `dev:views/hr/chatbot/form_qna.php:54-61`) [V2]; (b) tag status 10 tidak tampil di admin dan relasinya diabaikan (tetap di DB, tidak dibaca korpus/daftar) [V2, A10]; (c) kategori status 2 milik QnA boleh dipertahankan saat ubah (tampil berbadge "Tidak Aktif"); mengganti kategori hanya ke status 1; (d) kategori status 10 tampil di daftar admin dengan penanda "Dihapus" dan menyimpan QnA tanpa mengganti kategorinya → 422 "Kategori QnA sudah dihapus. Pilih kategori lain." (teks usulan) [V2]; (e) tag status 2/10 tidak menambah skor korpus (F8-39) | [K dev]+[V2] | ✔ (dikonfirmasi Horii 12-11-2026) |
| F8-50 | **Gerbang rilis menu & widget** (`ACTIVE_PHASE` = 5 di `frontend/src/shared/layouts/nav.config.ts:97`; semantik `rilis` `:122-127`; rujukan `603af133`): bawaan = pola lonceng F-08 (`RedesignShell.vue:111-119`): item menu Chatbot Manager `phase: 8` **tanpa** `rilis` (QA lewat URL langsung, pola F7-14) dan widget dirender hanya bila `ACTIVE_PHASE >= 8` (konstanta fase chatbot di fitur), sehingga menu dan widget dibuka bersamaan. Alternatif: menu `rilis: true` dan widget ikut tampil saat MAKE-095 masuk `main` (pola Integrasi `:296`). Siapa yang menaikkan `ACTIVE_PHASE` (atau menghapus `rilis`) = pertanyaan §12. **v1.2 — H8-11 (a), Horii 16-11-2026:** chatbot dan helpdesk **dirilis bersama** di penutup Fase 8 lewat paket **CR-135** sesudah sign-off (⛔ izin Horii): `ACTIVE_PHASE` 5 → 8, pembersihan `rilis` yang sudah lewat fasenya (mis. Integrasi `:296`), item `halo` (`:320`, saat ini tanpa `to` dan `roles`) diberi `to`/`roles` 1–7. Menaikkan `ACTIVE_PHASE` juga membuka menu Fase 6/7. QA sebelum rilis memakai build lokal `ACTIVE_PHASE` = 8 tanpa commit (preseden `docs/fase7/Laporan_Akhir_Fase7.md:77`). (v1.2: alternatif `rilis: true` gugur, H8-11 (a); pertanyaan siapa menaikkan `ACTIVE_PHASE` terjawab: paket CR-135 sesudah sign-off atas izin Horii) | [V2] pola | ✔ (dikonfirmasi Horii 12-11-2026); ✔ H8-11 (a) |
| F8-51 | **Key C4** (impor isi QnA dev): C4 adalah milestone tersendiri (menunggu PN8-6, bisa jauh sesudah C2 merge), sehingga mengikuti §2.1 "satu milestone = satu key = satu branch": bawaan = key MAKE tersendiri yang ditetapkan reviewer saat PN8-6 diputus (nomor berikutnya yang kosong), branch `fase8/<key>-impor-isi-qna`. Alternatif: bila PN8-6 diputus sebelum C2 merge, C4 menjadi bagian akhir branch MAKE-094. **v1.2 — H8-13 (b), Horii 16-11-2026:** C4 **keluar dari DoD/sign-off Fase 8** dan dilacak di Trello DEPLOY-002 (alternatif C2 gugur karena MAKE-094 sudah merge); aturan key tetap seperti di atas; pelaksana bila PN8-6 membawa isi dev = ReadOne (pemilik MAKE-094), izin diajukan saat itu | — | ✔ (dikonfirmasi Horii 12-11-2026); ✔ H8-13 (b) |

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
| `halo-simpeg/petugas/{id}` | PUT, DELETE | 1 | ubah (ganti set kategori atomik); hapus bila tidak ada tiket aktif (rumusan gugur v1.3 → K-A4 (1'): 422 `petugas_terakhir` bila petugas itu petugas terakhir, aktif atau nonaktif, sebuah kategori ber-tiket Open); v1.4: PUT yang membuat kategori ber-tiket Open kehilangan petugas aktif terakhir → 200 + `peringatan` (S8 (b)) | E-02 |
| `halo-simpeg/petugas/kandidat` | GET `?search=` | 1 | pencarian di server (F8-07); v1.3: `search` 2..50 karakter, maks 20 hasil `{id_user, label}`; v1.4: data `{items, peringatan}` (S1) | E-02 |
| `halo-simpeg/kontak` | GET | ~~1–7 (aktif), 1 (semua)~~ (gugur v1.3) → **1** | v1.3: semua status, `Halaman<T>`, filter `status`, `q` | E-07 |
| `halo-simpeg/kontak/aktif` (v1.3) | GET | 1–7 | kontak `status=1`, array, urut `created_at` naik [K `LS:16`] (Q-A0-7) | E-07 |
| `halo-simpeg/kontak`, `…/{id}` | POST, PUT, DELETE | 1 | | E-07 |
| `halo-simpeg/{kategori\|petugas\|kontak}/{id}/status` (v1.3) | PATCH | 1 | `{status: 0\|1}` (domain master 0/1); nilai lain → 422; status sama → 200 tanpa tulis/audit; diaudit (K-A1, AC-E02.9); respons `{...baris, peringatan}` (§6.1b) | E-02, E-07 |
| `halo-simpeg/petugas/saya` (v1.3) | GET | 1–7 | selalu 200 `PetugasSaya` (bukan petugas → `petugas:false`); dipakai FE untuk tab antrean dan panel "Perlu ditindak" | E-02/E-04 |
| `halo-simpeg/tiket` | GET | 1–7 | `peran=saya\|petugas`, filter `status`, `kategori`, `q`; urut pesan terakhir desc; paginasi. v1.3: + `baca=semua\|belum\|sudah`, `perlu_tindak=1` (hanya dengan `peran=petugas`, selain itu 422); `status=open\|closed`; urutan dan pemecah seri §6.1b; v1.4: `peran=petugas` tanpa tiket milik sendiri (T1), `q` = subjek atau isi pesan (T11) | E-04 |
| `halo-simpeg/tiket` | POST (multipart) | `UL_PEGAWAI` (Q8-01) | `id_question_category`, `subject`, `msg`, `arsip?` | E-03 |
| `halo-simpeg/tiket/ringkasan` | GET | 1–7 | belum dibaca per sisi, `perlu_tindak`; v1.3: + `badge`, `total` (§6.2) | E-06 |
| `halo-simpeg/tiket/{id}` | GET | 1–7 | pemilik atau petugas kategori; **tanpa efek samping** | E-04 |
| `halo-simpeg/tiket/{id}/baca` | POST | 1–7 | menyetel `is_read` (pemilik) / `is_read_off` (petugas); v1.3: pemilik **hanya** `is_read` apa pun status petugasnya, petugas aktif non-pemilik `is_read_off`; idempoten; 200 `Ringkasan` (§6.2) | E-04 |
| `halo-simpeg/tiket/{id}/pesan` | POST (multipart) | 1–7 | `msg`, `arsip?`, `ingatkan_24_jam?` (petugas) | E-04 |
| `halo-simpeg/tiket/{id}/pesan/{idPesan}/lampiran` | GET | 1–7 | berkas dialirkan setelah cek akses | E-04 |
| `halo-simpeg/tiket/{id}/tutup` | POST | 1–7 | F8-08 | E-05 |
| `halo-simpeg/tiket/{id}` | DELETE | 1–7 | F8-09 (v1.4: pemilik selalu 403, T2 (b)) | E-05 |
| `halo-simpeg/tiket/ekspor` | GET `?ds&de&format=xlsx` | 1–7 | hanya petugas (F8-20); 403 selain petugas; v1.3: petugas **aktif** (`bisa_ekspor`), 403 `bukan_petugas`; periode kosong 200 JSON (§6.1b) | E-09 |

**Pencocokan Matriks Modul E (v1.3, K-A7 A0).** Matriks Modul E hanya memuat satu baris (`hr/support/halo_simpeg`,
`conversations`, `add_conversation`, `close_inbox` → role 1–7; judulnya "Semua role (1-8)" tetapi barisnya 1–7, sama dengan kode
legacy, sehingga F8-04 tetap). Method lain dicocokkan ke `userAuth` legacy (`SC`): master `kategori`/`petugas`/`kontak` role 1
(`SC:13-136`, `:164-287`, `:315-438`); `POST tiket` `UL_PEGAWAI` (`SC:608`); daftar, detail, balas, tutup, hapus, ekspor role 1–7
(`SC:466`, `:498`, `:550`, `:663`, `:710`, `:753`); `petugas/saya`, `kontak/aktif`, `tiket/{id}/baca`, dan endpoint lampiran = [V2]
baru. Semua baris masuk `kontrak()` `KontrakMatriksRoleTest` (MAKE-070 master, MAKE-071 tiket). Route literal (`kategori/opsi`,
`petugas/saya`, `petugas/kandidat`, `kontak/aktif`, `tiket/ringkasan`, `tiket/ekspor`) didaftarkan **sebelum** `(:num)`.

Pesan galat berbahasa Indonesia dan mengikuti teks legacy bila ada (mis. "Percakapan ini telah ditutup …" [K `LS:1117`],
"Percakapan ini tidak memerlukan balasan …" [K `LS:1121`]).

#### 6.1a Kewajiban A0 / MAKE-070: butir kontrak yang wajib dibekukan (v1.2)

Tabel §6.1 di atas = kontrak awal, **bukan** kontrak final. Butir berikut belum diputus di PRD ini; **Handling Case mengusulkan
nilainya di laporan A0**, reviewer menyetujui, lalu MAKE-070 (master) dan MAKE-071 (tiket) **membekukannya di README
`backend/app/Controllers/Api/HaloSimpeg/README.md`** sebelum B1 FE mulai (§3). Perubahan sesudah beku = CR lewat reviewer.

**Status v1.3: semua kewajiban terpenuhi.** Handling Case mengusulkan nilainya di laporan A0, reviewer menyetujui (16-11-2026,
dua putaran revisi), dan Horii memutus butir yang menjadi wewenangnya (16-11-2026). Hasilnya = §6.1b. MAKE-070/071 tetap
**membekukan** kontrak di README `Controllers/Api/HaloSimpeg` (README itu menjadi sumber beku final; bila berbeda dengan §6.1b,
selisihnya diselesaikan lewat reviewer sebelum beku).

| No | Butir yang wajib dibekukan | Batasan yang sudah berlaku | Paket | Status (v1.3) |
|---|---|---|---|---|
| K-A1 | Endpoint ubah status master terpisah dari PUT payload penuh untuk kategori, petugas, dan kontak (bentuk `PATCH …/{id}/status`, nama path final) | AGENTS.md §1 (aksi ⋮ Aktifkan/Nonaktifkan), pola chatbot §6.5 dan `backend/app/Config/Routes.php:71`; domain status master 0/1 (DBV-022 DQ-5, F8-03) | MAKE-070 | ✔ `PATCH halo-simpeg/{kategori\|petugas\|kontak}/{id}/status`, `{status: 0\|1}` (FE memetakan Aktifkan = 1, Nonaktifkan = 0; beda dengan chatbot 1/2); PUT tetap payload penuh dan boleh memuat `status` |
| K-A2 | Parameter daftar kontak "aktif" (role 1–7) vs "semua" (role 1): nama parameter dan perilaku bila role selain 1 meminta "semua" | F8-18, AC-E07.1 | MAKE-070 | ✔ dua path `kontak/aktif` (1–7) dan `kontak` (1) (Q-A0-7, reviewer) |
| K-A3 | Endpoint atau field "petugas saya": apakah pengguna petugas aktif, kategori yang ditugaskan, dipakai FE untuk AC-E08.1 dan badge | F8-06, §6.2 | MAKE-070/071 | ✔ `GET petugas/saya` → `PetugasSaya` (§6.1b); bentuk K-A3 laporan A0 putaran pertama (dengan `id_support_officer`) gugur |
| K-A4 | Definisi "hapus petugas bila tidak ada tiket aktif" (tiket terikat ke kategori, `receiver` = 0, bukan ke petugas): kapan hapus ditolak, pesan galat, dan AC-nya (AC-E02.8) | F8-09, `LS:709-711`, B-12 | MAKE-070 | ✔ (1') Horii 16-11-2026 (§7.2) |
| K-A5 | Bentuk respons daftar dan detail (nama field, paginasi: nama parameter halaman/ukuran, batas ukuran halaman, metadata), kode galat (`code` snake_case di amplop v2) | Amplop v2 + field `code`; pola `Config\Chatbot::$perHalamanBawaan`/`$perHalamanMaks` (`backend/app/Config/Chatbot.php:59`, `:61`) sebagai pembanding, bukan nilai | MAKE-070/071 | ✔ §6.1b (nama respons Indonesia berobjek; bentuk K-A5 putaran pertama gugur) |
| K-A6 | Pola kunci path lampiran baru (`halo-simpeg/{nip}/{id_inbox}/…` atau pola kunci unggahan v2 `YYYY/MM/<32 hex>.<ext>`) | F8-17, T-4; keduanya privat | MAKE-071 | ✔ `halo-simpeg/{YYYY}/{MM}/{32 hex}.{ext}` (F8-17) |
| K-A7 | Pencocokan tabel §6.1 dengan Matriks Role × Endpoint Modul E (role per route), lalu daftar baris yang masuk `KontrakMatriksRoleTest` | F8-04, AC-E02.7 | A0 → MAKE-070/071 | ✔ §6.1 (paragraf pencocokan Matriks) |
| K-A8 | Angka throttle, batas panjang, dan batas baris ekspor (§7.3a) | BP8-10, §7.3a | A0 → MAKE-071/074 | ✔ §7.3a, dikonfirmasi Horii 16-11-2026 (Q-A0-8) |

#### 6.1b Kontrak helpdesk disetujui A0 (v1.3)

Sumber: daftar kontrak final laporan A0 (Handling Case, disetujui reviewer 16-11-2026), yang menggantikan K-A3, K-A5, dan
rincian kontrak putaran pertama laporan itu, ditambah kebutuhan laporan B0 (Worker Muse) yang disetujui reviewer. **README
`backend/app/Controllers/Api/HaloSimpeg/README.md` saat MAKE-070 (master) dan MAKE-071 (tiket) = sumber beku final**; tabel di
bawah adalah bentuk ringkas yang wajib diikuti README itu. Perubahan sesudah beku = CR lewat reviewer (kedua jalur). `types.ts` /
`schemas.ts` FE mengikuti README beku, bukan laporan B0.

**Konvensi.** Amplop v2 `{status, data, message}` + `code` opsional snake_case (aditif); waktu ISO-8601 UTC berakhiran `Z` (pola
`PusatNotifikasiService::iso`); teks polos (BP8-4); `Halaman<T> = {items, total, page, per_page}` (`page` ≥ 1, `per_page` 1..100
bawaan 20 lewat `ListQuery`, angka di `Config\HaloSimpeg`; parameter berbentuk array → 422; v1.4: aturan pasti `page`/`per_page` = S3 di bawah). **Masukan** multipart/JSON memakai
nama legacy (`id_question_category`, `subject`, `msg`, `arsip`, `ingatkan_24_jam`, `category[]`, `id_user`, `email`, `no_hp`,
`name`, `contact`, `status`); **respons** memakai nama Indonesia ringkas berobjek, pola README chatbot
(`backend/app/Controllers/Api/HaloSimpeg/Chatbot/README.md`), satu set nama yang sama di README, fixture, dan FE. **Id akun petugas
tidak pernah dikirim ke klien non-role-1** (`msg_sender`/`closed_by` mentah tidak dikirim). Role dicek di filter route bila bisa
(tersapu `KontrakMatriksRoleTest`); kewenangan objek di `AksesTiket` (BP8-2), yang juga menghitung semua flag `bisa_*` (satu
sumber dengan penegakan).

**Master**

| Bentuk | Field |
|---|---|
| `Kategori` | `id`, `nama`, `status: 0\|1`, `jumlah_petugas`, `bisa_hapus` (false bila dipakai tiket/petugas), `dibuat_pada`, `diubah_pada` |
| `KategoriOpsi` | `id`, `nama` (hanya status 1; `GET kategori/opsi`, array) |
| `Petugas` | `id`, `id_user`, `label` (§7.2: role 1 nama akun; lain NIP – nama – jabatan), `email`, `no_hp\|null`, `status: 0\|1`, `kategori: {id, nama, status}[]` (urut `id`), `bisa_hapus`, `alasan_tidak_bisa_hapus: string\|null` (teks 422 `petugas_terakhir` bila `bisa_hapus=false`) |
| `PetugasKandidat` | `id_user`, `label` (`GET petugas/kandidat?search=` 2..50 karakter, maks 20 hasil); v1.4: dibungkus `{items: PetugasKandidat[], peringatan: string\|null}` (S1) |
| `PetugasSaya` | `{petugas: bool, aktif: bool, kategori: {id, nama, status}[], bisa_ekspor: bool}`; bukan petugas → `{petugas:false, aktif:false, kategori:[], bisa_ekspor:false}`; `id_support_officer` tidak dikirim; `bisa_ekspor` = petugas aktif berapa pun kategorinya |
| `Kontak` | `id`, `nama`, `kontak`, `email\|null`, `status: 0\|1`, `dibuat_pada`, `diubah_pada`; `kontak/aktif` = array tanpa `status`/stempel |
| PATCH `…/{id}/status` | 200 `{...baris master, peringatan: string\|null}`. Petugas: menonaktifkan petugas **aktif terakhir** sebuah kategori yang punya tiket Open → `peringatan` = "Petugas ini adalah petugas aktif terakhir pada kategori {daftar nama}. Tiket terbuka pada kategori tersebut tidak memiliki penjawab sampai petugas lain diaktifkan."; selain itu `null` (kategori/kontak selalu `null`) |
| POST/PUT/DELETE master | POST 201 baris; PUT 200 baris (v1.4: petugas `Petugas & {peringatan}`, S8 (b)); DELETE 200 `{id}`; tanpa GET detail (S9) |

**Tiket**

| Bentuk | Field |
|---|---|
| `TiketBaris` | `id`, `subjek`, `kategori: {id, nama}\|null`, `status: 'open'\|'closed'`, `dibuat_pada`, `pemilik_nama`, `sebagai_petugas`, `belum_dibaca` (menurut sisi pemanggil), `perlu_tindak` (ekspresi SQL sama dengan `Ringkasan.perlu_tindak`; `false` bila pemanggil bukan petugas tiket itu), `pesan_terakhir: {id_inbox_msg, waktu, potongan (100 code point), pengirim_label (aturan daftar D7 (a)), lampiran: {nama, tipe}\|null}\|null` (kolom LAMPIRAN TERAKHIR [K `VI:76`, `:103-109`]; satu subkueri yang sama dengan pengurutan, tanpa N+1), `bisa_balas`, `bisa_tutup`, `bisa_hapus` |
| `TiketDetail` | `TiketBaris` + `allow_reply`, `snapshot: {nip, jabatan, satker}\|null` (`null` bagi pemilik dan non-petugas; terisi bagi petugas tiket itu atau role 1 [K `VD:21-26`]; v1.4, T3: hanya petugas tiket itu yang bukan pemiliknya, termasuk untuk `pengingat`), `ditutup_oleh_label: string\|null` [V2] (aturan detail D7 (a): nama bila penutup = pemilik atau peninjau role 1, selain itu "Admin"; `null` bila Open atau `closed_by` kosong), `pengingat: {aktif, jatuh_tempo}\|null` (petugas saja), `pesan: Pesan[]` (urut `waktu` naik, `id_inbox_msg` naik) |
| `Pesan` | `id_inbox_msg`, `waktu`, `isi`, `pengirim_label` (aturan detail D7 (a)), `milik_saya`, `dari_pemilik`, `urutan_pengirim: int`, `lampiran: {nama, tipe, ukuran\|null}\|null` |
| `Ringkasan` | `{belum_dibaca_saya, belum_dibaca_petugas, perlu_tindak, badge, total}` (aturan §6.2) |
| `GET tiket` | `peran=saya\|petugas` (bawaan `saya`; `petugas` untuk non-petugas/nonaktif → 200 `Halaman` kosong), `status=open\|closed`, `kategori=<id>`, `q` (subjek/isi pesan, LIKE ter-escape), `baca=semua\|belum\|sudah` (bawaan BE `semua`; bawaan per tab ditentukan FE), `perlu_tindak=1` (hanya dengan `peran=petugas`, selain itu 422; `total` hasilnya = `Ringkasan.perlu_tindak`, diuji), `page`, `per_page` |
| Respons tulis tiket | POST tiket → 201 `TiketDetail`; POST pesan → 201 `{pesan: Pesan, tiket: TiketBaris}`; POST tutup → 200 `TiketBaris`; DELETE → 200 `{id}`; POST baca → 200 `Ringkasan` |
| Lampiran | `nama` = `lampiran-{id_tiket}-{id_pesan}.{ext}` = `Content-Disposition`; `tipe` dari ekstensi `AturanLampiran`; path tidak pernah dikirim (AC-E08.9); v1.4: `tipe` = MIME, `ukuran` = `null` [V2] (T4) |
| Ekspor ada data | 200 xlsx + `Content-Disposition: attachment; filename="Rekap_Halo_Simpeg_{ds}_{de}.xlsx"` + audit unduhan |
| Ekspor kosong | 200 JSON `{status:'success', data:{kosong:true, jumlah:0}, message:'Tidak ada data Halo Simpeg pada periode ini.'}`, `Content-Type: application/json`, tanpa `Content-Disposition`, tanpa audit |

**Urutan dan pemecah seri.** Pesan terakhir per tiket = `ORDER BY created_at DESC, id_inbox_msg DESC LIMIT 1`. Daftar =
`pesan_terakhir.waktu DESC` dengan NULL terakhir (tiket tanpa pesan ditampilkan di akhir [V2], B-20), lalu `id_inbox DESC`.
`disiplin_ticket.updated_at` tidak dipakai sebagai "pesan terakhir" karena ber-`ON UPDATE` (ikut berubah saat baca/tutup).

**Penyamaran nama pengirim (D7 (a), Horii 16-11-2026: ikut legacy per layar).** `pengirim_label` dihitung BE; FE menampilkannya
apa adanya.

| Layar | Aturan `pengirim_label` |
|---|---|
| Daftar (`pesan_terakhir.pengirim_label`) | nama pengirim bila pengirim = pemilik tiket; selain itu **"Admin" bagi semua peninjau**, termasuk role 1 dan petugas yang menulis pesan itu [K `VI:110-117`] |
| Detail (`Pesan.pengirim_label`) | nama pengirim bila pengirim = pemilik **atau** peninjau = role 1; selain itu "Admin" [K `VD:48-55`] |

Nama bergelar (`msg_sender_name`) berlaku bagi semua akun **ber-NIP** role apa pun (PRD §7.4; legacy hanya `UserLevel` 2/6,
`LS:1580-1588`, B-28). `milik_saya` membedakan gelembung kirim/terima. `urutan_pengirim` = 0 untuk pemilik; petugas 1..n menurut
kemunculan pertama (urut `waktu`, `id_inbox_msg`) [K `VD:5-14`, warna gelembung `color-N` `:44`; legacy menomori relatif terhadap
peninjau, v2 per tiket relatif pemilik = [V2] kecil, B-23]; tanpa rahasia, tidak dapat dibalik ke id dan tidak dapat dikaitkan
antar tiket (usulan `kunci_pengirim` HMAC gugur v1.3).

**Galat** (semua teks, batas panjang §7.3a, dan throttle ditulis lengkap di README):

| Status | `code` | Teks |
|---|---|---|
| 404 | — | "Data percakapan tidak ditemukan." (tiket; BP8-2: tiket orang lain = tidak ditemukan); master: "Data tidak ditemukan." |
| 403 | `bukan_petugas` | "Fitur ini hanya untuk petugas Halo Simpeg yang aktif." (ekspor) |
| 403 | `bukan_pihak_berwenang` | "Anda tidak berwenang melakukan tindakan ini pada percakapan ini." (pihak yang bisa melihat tetapi tidak berhak, mis. pemilik menghapus, termasuk tiket NULL) |
| 422 | `tiket_ditutup` | "Percakapan ini telah di tutup oleh admin. Silahkan tambah pertanyaan baru untuk memulai percakapan baru." [K `LS:1117`] |
| 422 | `tiket_tanpa_balasan` | "Percakapan ini tidak memerlukan balasan. Silahkan tambah pertanyaan baru untuk memulai percakapan baru." [K `LS:1121`] |
| 422 | `tiket_sudah_ditutup` | "Percakapan ini telah ditutup sebelumnya." [K `SC:672`, dipangkas] |
| 422 | `tiket_tanpa_kategori` | "Pertanyaan ini tidak memiliki kategori. Silakan tambah pertanyaan baru." (H8-16 (a1)) |
| 422 | `ekspor_terlalu_besar` | "Data pada periode ini terlalu besar untuk diekspor. Persempit periode." (satu teks netral untuk batas baris dan ukuran) |
| 422 | `kategori_dipakai` | "Kategori masih dipakai tiket atau petugas. Nonaktifkan kategori ini." |
| 422 | `kategori_duplikat` | "Kategori sudah terdaftar." [K `LS:392-424`] |
| 422 | `petugas_duplikat` | "Akun ini sudah terdaftar sebagai petugas." [K `LS:765-788`] |
| 422 | `petugas_terakhir` | "Petugas ini petugas terakhir pada kategori {nama} yang masih memiliki tiket terbuka. Tambahkan petugas lain lebih dulu." |
| 422 | `kontak_duplikat` | "Nomor kontak sudah terdaftar." [K `LS:176-197`] |
| 422 | (per kolom) | `errors.<field>` berbentuk array, termasuk `errors.arsip` dan `errors.ingatkan_24_jam`; teks per field di §7.3a |
| 429 | `throttle` | tiket: "Terlalu banyak pertanyaan baru. Coba lagi dalam 10 menit."; pesan: "Terlalu banyak pesan. Coba lagi dalam satu menit." |

**Fixture** sintetis di **`backend/tests/_support/HaloSimpeg/`** (di luar `Chatbot/`), dengan trait verifikasi bahwa fixture =
respons nyata (pola `backend/tests/_support/HaloSimpeg/Chatbot/ChatbotFixtureTrait.php`); masuk DoD MAKE-070/071/074; FE menyalinnya
ke `frontend/src/features/halo-simpeg/__tests__/fixtures/` (tanpa `*.php`), pola chatbot §3:

| Kelompok | Fixture |
|---|---|
| Master | `kategori-daftar`, `kategori-opsi`, `petugas-daftar` (termasuk `bisa_hapus=false` + alasan), `kandidat`, `petugas-saya` (aktif, nonaktif, bukan petugas), `kontak-aktif`, `kontak-daftar`, `status-patch`, `status-patch-peringatan` |
| Tiket | `tiket-daftar` (pemilik; petugas; dilihat role 1 → "Admin" di `pesan_terakhir`), `tiket-detail` (pemilik; petugas; yang sama dilihat role 1 → nama; kategori NULL `bisa_balas=false`; `snapshot` null dan terisi; `ditutup_oleh_label`; 4 kasus label: pesan pemilik, pesan petugas dilihat pemilik, pesan petugas dilihat petugas lain, pesan petugas sendiri), `tiket-ringkasan` (termasuk `badge > total`), `baca` |
| Galat | `galat-422-tiket` (termasuk `errors.arsip`), `galat-422-ditutup`, `galat-422-tanpa-kategori`, `galat-422-petugas-terakhir`, `galat-422-ekspor`, `galat-403-bukan-petugas`, `galat-403-bukan-pihak-berwenang`, `galat-404`, `galat-429`, `ekspor-kosong` |

**Selisih README master MAKE-070 terhadap v1.3 (v1.4, diterima reviewer 17-11-2026).** Rincian lengkap = bagian "Selisih
terhadap PRD v1.3" README `backend/app/Controllers/Api/HaloSimpeg/README.md` (di `main` `56a700b1`); bila berbeda dengan
tabel master di atas, README itu yang berlaku (R8-24).

| No | Ringkas | Label |
|---|---|---|
| S1 | `GET petugas/kandidat` → `{items, peringatan}` | [V2] |
| S2 | filter `status` + `q` juga untuk daftar kategori dan petugas | [V2] |
| S3 | `per_page` diambil sebagai bilangan bulat (> 100 → 100, < 1 → 1); `page` di luar 1..1.000.000 → 422 `errors.page` | [V2] |
| S4 | teks 422 tambahan (`category`, `search`, `id`) | [V2] |
| S5 | kandidat hanya akun aktif; petugas yang sudah terdaftar tidak disaring, simpan → 422 `petugas_duplikat` | [V2] / [K] |
| S6 | label berpemisah `" - "` + nama bergelar | [K] |
| S7 | PUT set kategori disimpan sebagai selisih (lepas + sisip) dalam satu transaksi | [V2] |
| S8 | PUT petugas → 200 + `peringatan` (bukan 422) bila sebuah kategori ber-tiket Open kehilangan petugas aktif terakhir | [V2], ✔ Horii 17-11-2026 (b) |
| S9 | tanpa `GET {jalur}/{id}`; DELETE → 200 `{id}` | [V2] |
| S10 | fixture tambahan (`kandidat-peringatan`, `petugas-put-peringatan`, galat master; `petugas-saya` tiga berkas) | [V2] |
| S11 | `status` opsional saat POST master, bawaan 1 | [V2] |
| S12 | kandidat pegawai dan petugas aktif hanya akun ber-role 1–7, karena role 8 tanpa akses (F8-04) | [V2] |

**Keputusan MAKE-071 sebelum README tiket beku (v1.4, 17-11-2026).** T2 = keputusan Horii; lainnya keputusan reviewer CR.
README tiket beku di branch MAKE-071 dan **belum di `main`**; sesudah masuk `main`, README itu menjadi sumber beku final bagian
tiket (rinciannya tidak disalin ke PRD).

| No | Keputusan | Bagian terkait |
|---|---|---|
| T1 | `peran=petugas` mengecualikan tiket milik sendiri (selaras sisi petugas §6.2) | §6.1, `GET tiket` |
| T2 | ✔ Horii (b), F8-09 harfiah: pemilik selalu 403 `bukan_pihak_berwenang` untuk hapus walau ia petugas aktif kategori itu; petugas lain di kategori yang sama boleh menghapus | F8-09, AC-E05.11 |
| T3 | `snapshot` dan `pengingat` hanya bagi petugas tiket itu yang bukan pemiliknya | `TiketDetail` |
| T4 | `lampiran.tipe` = MIME; `ukuran` = `null` [V2] | `Pesan`, Lampiran |
| T5 | galat berkas lampiran masuk `errors.arsip` | Galat |
| T6 | teks galat tambahan, termasuk [K] "Data pegawai tidak ditemukan didalam sistem." | §7.3, Galat |
| T7 | urutan cek balas + kunci `FOR UPDATE` (pola W1, §7.2) | F8-10, §7.4 |
| T8 | aturan `ingatkan_24_jam` dirinci di README | F8-11 |
| T9 | bentuk respons tiap aksi dirinci di README | Respons tulis tiket |
| T10 | daftar fixture tiket final di README | Fixture |
| T11 | `q` mencari subjek atau isi pesan | `GET tiket` |
| T12 | nama pengirim bergelar | §7.4, B-28 |
| T13 | syarat DBV Q6 (`status IN (0, 1)` eksplisit + test EXPLAIN) dan Q8 (c) (ambang yang sama) ditulis di README | §7.1 |

### 6.2 Penghitung belum dibaca (E-06, titik sambung lonceng)

`GET halo-simpeg/tiket/ringkasan` → `{ belum_dibaca_saya, belum_dibaca_petugas, perlu_tindak, total }`. Aturan hitung [K
`LN:12-60`]: tiket milik pengguna dengan `is_read=2`, ditambah (bila petugas aktif) tiket kategorinya dengan `is_read_off=2`
yang bukan milik pengguna sendiri (tidak dihitung dua kali). Hanya membaca `disiplin_ticket`.

**Aturan hitung final (v1.3, A0 + keputusan reviewer dan Horii 16-11-2026).** Satu kelas
`App\Libraries\HaloSimpeg\RingkasanTiket implements RingkasanTiketInterface` dipakai `GET halo-simpeg/tiket/ringkasan` dan
`jumlahBelumDibaca()` lonceng (BP8-1: hanya membaca `disiplin_ticket` dan `support_officer`/`officer_category`;
`PusatNotifikasiService` tidak diubah). Bentuk respons: `{belum_dibaca_saya, belum_dibaca_petugas, perlu_tindak, badge, total}`.

| Bagian | Aturan |
|---|---|
| `belum_dibaca_saya` | `sender = id_pengguna` AND `is_read = 2` [K `LN:13`]; termasuk tiket berkategori NULL milik sendiri (H8-16 (a1)) |
| `belum_dibaca_petugas` | hanya bila petugas **aktif** [V2 F8-06; legacy tidak cek]: `id_question_category ∈ kategori petugas` AND `is_read_off = 2` AND `sender <> id_pengguna`. **Semua** tiket milik sendiri dikecualikan dari sisi petugas (Q-A0-4, keputusan reviewer: ikut PRD; legacy hanya mengecualikan tiket sendiri yang `is_read=2`, `LN:13-21`, `:44`), sehingga tiket sendiri di kategori sendiri hanya dihitung di sisi pemilik. Tiket NULL tidak pernah masuk sisi petugas |
| `perlu_tindak` | sisi petugas aktif, bukan milik sendiri: `status = 0` AND (`is_read_off = 2` OR (`notify_pic = 1` AND `notify_at < sekarang UTC`)) [K `MC:292`, F8-11]; ekspresi yang sama dipakai `TiketBaris.perlu_tindak` dan filter `GET tiket?perlu_tindak=1` |
| `badge` | jumlah tiket **distinct** (belum dibaca di sisi mana pun ∪ `perlu_tindak`) (D8 (a)); tiket yang belum dibaca sekaligus `perlu_tindak` dihitung sekali; dipakai badge menu Halo Simpeg |
| `total` | `belum_dibaca_saya + belum_dibaca_petugas` = angka lonceng = `jumlahBelumDibaca()` (AC-E06.9); `perlu_tindak` tidak ditambahkan |
| Role | route 1–7; `jumlahBelumDibaca()` untuk role di luar 1–7 (role 8) = **0**, bukan `null` |

**Penanda baca (E7 final, v1.3).**

| Kejadian | Hasil | Label |
|---|---|---|
| `POST …/baca` oleh **pemilik** | hanya `is_read = 1`, **apa pun** status petugasnya (pemilik yang juga petugas kategori itu tidak menyetel `is_read_off`) | [K `LS:907-912`, if/else] |
| `POST …/baca` oleh petugas **aktif** kategori yang bukan pemilik | `is_read_off = 1` | [K `LS:914-918`]; syarat aktif [V2 F8-06] |
| `POST …/baca` oleh pihak lain | 404 (tidak melihat tiket, BP8-2) | — |
| Baca berulang | idempoten: tanpa tulis dan tanpa audit bila nilai sudah 1; respons 200 `Ringkasan` | [V2] |
| Balasan pemilik | `is_read = 1`, `is_read_off = 2` | sisi pembalas pemilik = 1 dan sisi lawan = 2: [I] (H8-10) |
| Balasan petugas | `is_read_off = 1`, `is_read = 2` (+ `notify_pic`/`notify_at` per F8-11) | `is_read_off = 1` [K `LS:1344-1347`]; `is_read = 2` [I] (H8-10) |

Frasa "pemilik yang juga petugas kategori itu menyetel keduanya" (rincian putaran pertama laporan A0) **gugur v1.3**. Test wajib:
pemilik yang juga petugas kategori itu membalas lalu `POST …/baca` → `is_read_off` tetap 2, dan petugas lain di kategori yang sama
masih melihat tiket itu belum dibaca + `perlu_tindak`. Test AC-E06.9: angka untuk pemilik, petugas, dan petugas-pemilik tanpa hitung
ganda; §7.8 (5): `tiket_belum_dibaca` tidak memuat `inbox`.

### 6.3 Frontend (E-08, E-10)

Rute Halo Simpeg dipasang di `nav.config.ts` (item `halo`, `phase: 8`) dengan `roles` 1–7; menu tampil saat `ACTIVE_PHASE`
dinaikkan ke 8 di paket rilis CR-135 (H8-11 (a), F8-50), sebelumnya QA lewat URL langsung. Mengganti `halo.store.ts`
(memori, branch redesign) menjadi store/service API (ADR-021/022). Tampilan:

| Tampilan | Pengguna | Isi |
|---|---|---|
| Drawer / halaman pegawai (Gambar 33) | pemilik | daftar tiket saya + form tiket baru (kategori, subjek, pesan, lampiran) + thread; badge status Open/Closed. v1.3: **halaman** `/halo-simpeg` (bukan drawer global, agar tidak bertumpuk dengan launcher Vanya dan mudah di-deep-link) dengan tata letak master-detail yang sama dengan petugas; kartu "Kontak Helpdesk" dari `kontak/aktif` |
| Inbox petugas (Gambar 34) | petugas | antrean tiket kategorinya (kiri), thread + balasan (kanan), tutup/hapus lewat menu ⋮, "Ingatkan 24 jam". v1.3: ⋮ Lihat detail → Tutup → Hapus mengisi panel kanan; panel "Perlu ditindak"; tombol ekspor bila `bisa_ekspor` |
| Admin: Kategori, Petugas, Kontak | role 1 | tabel `page-fill` + ⋮ (Edit, Aktifkan/Nonaktifkan, Hapus); tanpa frame Figma → ikut pola Master Data/PIC layanan dan dicatat (AGENTS.md §3.6). v1.3: Aktifkan/Nonaktifkan lewat `PATCH …/status` (`hidden` sesuai status); ⋮ Hapus petugas `disabled` bila `bisa_hapus = false` (AGENTS.md §1 butir 4); 422 tetap ditangani (`UiNotice`); Nonaktifkan yang mengembalikan `peringatan` → `UiNotice` nada peringatan; form Petugas: kandidat lewat pencarian server ber-debounce + penjaga urutan, kategori multi lewat `UiPilihBanyak`. Tombol ekspor bawaan DataTables di list master legacy tidak dibawa (tidak ada di §6.1, B-31). v1.4 (keputusan reviewer 17-11-2026): path admin FE = `/halo-simpeg-admin/:bagian` (bukan `/halo-simpeg/admin`), supaya sesudah CR-135 tidak ada dua item sidebar yang aktif bersamaan |
| **Widget Vanya** (E-10) | role 2/6/7 (K-8) | tombol avatar melayang di pojok kanan bawah semua halaman ber-layout (`RedesignShell.vue`), kecuali selama sesi wajib ganti password (F8-43); kartu sapaan saat hover/fokus, ditambah peek otomatis sekali per sesi browser untuk semua perangkat [K dev `dev:views/hr/chatbot/chatbot_popup.php:413-422`]; jendela chat: salam, bubble topik → bubble pertanyaan, tombol "Topik" dan "Populer", kotak ketik maks 300 karakter, 👍/👎 untuk jawaban cocok; tampilan ikut dev disesuaikan token v2 (K-11) |
| **Admin: Chatbot Manager** (E-10) | role 1 (K-10) | Kategori (judul, status, "Bubble Vanya"), Tag (judul, status, tombol ekspor `UiExportMenu`), QnA (kategori, tag multi, opsi alternatif, status, pertanyaan, jawaban HTML + pratinjau `SafeHtml`; tombol "Unduh template" dan "Impor Excel" di level halaman), Statistik (KPI + tiga tabel top-10), Riwayat (tabel berhalaman, filter tanggal/status cocok/feedback, tanpa aksi baris). Tabel `page-fill` + ⋮ (Edit → Aktifkan/Nonaktifkan → Hapus) |

**Helpdesk FE final (v1.3, laporan B0 + keputusan reviewer dan Horii 16-11-2026):**

- **Tata letak master-detail (keputusan reviewer).** Bawaan layar petugas **dan** pemilik = master-detail ala Gambar 34: daftar kiri
  berupa tabel biasa (`<table>`) dengan ⋮ per baris (AGENTS.md §1), urutan item **Lihat detail** → **Tutup** (`hidden` bila Closed
  atau `bisa_tutup = false`, `ConfirmDialog` teks legacy `VI:136`) → **Hapus** (`danger`, `hidden` bila `bisa_hapus = false`,
  `ConfirmDialog` teks `VI:140`); `testid` `halo-tiket-actions-<id>`, label "Aksi untuk <subjek>". "Lihat detail" melakukan
  `router.push` ke `/halo-simpeg/tiket/:id`, dan rute itu menjadi keadaan panel kanan (deep-link lonceng/push dan tombol kembali
  berfungsi; fokus pindah ke judul thread). Baris tidak dapat diklik; tanpa `listbox`/`option` dan **tanpa pengecualian AGENTS.md**.
  Layar < 1024 px: daftar dan thread tampil sebagai dua halaman pada rute yang sama (panel kanan layar penuh + "Kembali ke
  daftar") = perilaku responsif, bukan deviasi. Label "Tutup" mengikuti §7.7 (tanpa CR AGENTS.md). Layar petugas dan master tanpa
  frame Figma (H8-9) dicatat per layar di laporan B1–B3 dan README.
- **Filter baca.** `baca=semua|belum|sudah` lewat `UiSelect`; bawaan tab antrean = `belum` [K `VI:51`, `:198`], bawaan tab "Tiket
  saya" = `semua` [V2] (Horii 16-11-2026; konstanta `BACA_BAWAAN_ANTREAN`, `BACA_BAWAAN_SAYA`). Filter kategori di tab antrean tampil
  bila petugas punya ≥ 2 kategori [K `VI:31-36`]; di tab "Tiket saya" = [V2] §7.4. Tab antrean dan panel "Perlu ditindak" diturunkan
  dari `GET petugas/saya`, tidak dari role.
- **Ringkasan + badge.** State modul singleton dengan polling lembut saat pindah halaman (pola `useRingkasanNotifikasi`), aktif
  hanya bila `ACTIVE_PHASE >= 8` **dan** role sesi ∈ 1–7 **dan** `!auth.mustChangePassword` (preseden lonceng
  `RedesignShell.vue:117-125`, Vanya `:199`); polling dihentikan dan angka dikosongkan saat syarat berubah (`watch` +
  `resetHaloSimpeg()`). Badge disisipkan lewat fungsi murni `sisipBadgeHalo(groups, ringkasan.badge)` di `features/halo-simpeg/`
  yang dipakai computed `groups` `RedesignShell.vue:107` (`0` → tanpa badge; `buildNav` tidak diubah). Item `halo`
  (`nav.config.ts:320`) tanpa `to`/`roles` sampai CR-135, sehingga badge baru tampil di QA lewat build lokal yang juga mengisi
  `to`/`roles` (checklist CR-132 bagian B); CR-135 wajib menambah test badge pada `NAV` asli. Lonceng tetap memakai `total`.
- **Lain-lain.** `POST …/baca` dipanggil eksplisit saat thread dibuka (BP8-7) dan respons `Ringkasan` langsung memperbarui badge;
  lampiran (termasuk kolom "Lampiran terakhir") dibuka lewat E-04 (blob + object URL); ekspor: service helpdesk memeriksa
  `Content-Type` respons 200 (`application/json` → hasil "kosong" + `message` di `UiNotice` tanpa `simpanBerkas`; xlsx →
  `simpanBerkas`), `ambilBerkas` tidak diubah; dialog periode memakai tanggal kalender lokal (tanpa `toISOString()`), akhir ≥ mulai,
  maksimum hari ini, wajib isi dengan pesan legacy (`VI:214-256`, `:305-310`); popover snapshot NIP/jabatan/satker lewat `UiTooltip`
  untuk petugas/role 1; tiket kategori NULL berlabel "—" dan `bisa_balas = false` menyembunyikan form balas; Zod memakai angka
  §7.3a sebagai konstanta tunggal (panjang dihitung code point); keadaan memuat/kosong/galat (403/404/500) per layar.

Menu E-10 (✔ F8-43): Pengaturan → **"Chatbot Manager"** (role 1, `phase: 8`) dengan submenu Kategori, Tag, QnA, Statistik, Riwayat
(nama menu ikut dev `dev:controllers/hr/Chatbot.php:39`). Widget tidak memakai item menu; komponen dipasang sekali di
`RedesignShell.vue` (`<main id="main-content">` di `frontend/src/shared/layouts/RedesignShell.vue:189`; widget `<VanyaWidget>` `:199`, rujukan `603af133`) dan dirender hanya bila role sesi ∈
`UL_PEGAWAI` **dan** `!auth.mustChangePassword` (pola `NotifikasiPushShell` `:187`), dengan gerbang rilis F8-50. Kerangka redesign `67fa7678` tidak memuat chatbot. Di `main` `603af133`
folder `features/halo-simpeg/chatbot/` sudah berisi MAKE-095; folder helpdesk `features/halo-simpeg/` lainnya baru berisi README + `.gitkeep`.

Kerangka tampilan berupa **data contoh** ada di branch `feat/frontend-ui-redesign` (commit `67fa7678`: `halo.store.ts`, `types.ts`,
`components/HaloDrawer.vue`, `views/AdminHaloPage.vue`) dan **belum ada di `main`** (di `main` hanya README modul dan item menu `halo`
ber-`phase: 8` di `nav.config.ts:262` pada basis `f7b9221e`; di `603af133` item itu ada di `:320`, tanpa `to` dan `roles`). Kerangka itu mengasumsikan satu percakapan per pengguna dan admin = role 1 & 3 (`types.ts`, "ASUMSI");
keduanya diganti oleh model F8-02 dan F8-04/F8-06. Bila branch redesign belum di `main` saat M5, M5 mengambil berkas-berkas itu sebagai titik awal.

### 6.4 Titik sambung ke Fase 6 (B5 / MAKE-066)

| Kebutuhan | Disediakan Fase 6 | Dipakai Fase 8 |
|---|---|---|
| Kirim push | `PushNotifGatewayInterface` + `FcmV1Adapter`/`MockFcmAdapter` (`Config/Services.php`) | `PushHaloSimpegJob` (bila H8-14 = (b)) atau `PushPesanJob` lewat `AntrePushPesan` (bila (a)) memanggil gateway yang sama |
| Penyimpanan token | `firebase_token` (`nip`, `fcm_token`, `platform`, `token_hash`; DBV-027); token web terikat akun (`id_pengguna`, `backend/app/Libraries/Push/PenerimaTokenFcm.php:16`) | v1.0: "penerima ditentukan per NIP → semua token aktif (web + Android)". **v1.2:** rumusan per NIP bertentangan dengan F8-13/AC-E06.7 untuk petugas role 1 tanpa NIP → **H8-14** (§12) menetapkan penerima per akun atau per NIP |
| Antrean | queue `push` (`queue:work push`); titik sambung Halo Simpeg yang sudah ada: `AntrePushPesan` → job `push-pesan` (`backend/app/Config/Queue.php:56-57`, `backend/app/Jobs/PushPesanJob.php:15`), mencari token Android per NIP dan token web lewat `pengguna.nip` (`PenerimaTokenFcm.php:41`, `:80-88`) | v1.0: job baru `push-halo-simpeg` di `Config/Queue.php`. **v1.2:** pilihan job mengikuti H8-14 ((a) `push-pesan` + CR berkas bersama Push untuk penerima per akun; (b) job Halo Simpeg tersendiri); diputus sesudah A0 (T-1) |
| Token tidak valid | job menghapus token invalid dari error `UNREGISTERED`/`INVALID_ARGUMENT` | pola sama (`PushNotifLayananJob`: retry terbatas `TRIES`) |
| Catatan percobaan | `integrasi_log` (F6-24, `IntegrasiLogInterface`) | tiap job mencatat hasil teredaksi |
| Daftar token web | `POST api/v1/integrasi/fcm-token` | tidak diubah |

Fase 8 **tidak** mengubah job `push-notif` (`PushNotifLayananJob`, terikat `inbox_msg`) — memakai job terpisah agar BP8-1 terjaga.

**Keputusan final (v1.3, ✔ H8-14 (a), Horii 16-11-2026).** Kolom "Dipakai Fase 8" di atas yang menyebut opsi (b) / job
`push-halo-simpeg` **gugur v1.3**. Yang berlaku:

| Butir | Isi |
|---|---|
| Produsen | `TicketNotifService` (sesudah commit) → `AntrePushPesan::antreAkun(list<int> $idPengguna, judul, isi, data, kanal)`, job `push-pesan` yang sudah ada (`backend/app/Config/Queue.php:56-57`), kanal `semua` (F8-15); payload F8-16 `{modul:'halo-simpeg', id_inbox, url}` sampai PN8-1/H8-8 |
| Penerima | semua pihak (petugas dan pemilik) per `id_pengguna`; pemilik selalu ber-NIP sehingga token Android tetap ketemu lewat `pengguna.nip` (setara legacy `inbox.nip`); petugas role 1 tanpa NIP menerima token web per akun (AC-E06.7); pelaku dikecualikan; akun dan petugas nonaktif dilewati [K B4]; token yang sama dikirim sekali |
| CR berkas bersama **CR-137** | `AntrePushPesan.php` (method baru aditif, `:48` `antre(nip[])` tetap), `PushPesanJob.php` (membaca `nip[]` lama **atau** `id_pengguna[]` baru, `:37-61`), `PenerimaTokenFcm.php` (`webAkun(list<int>)` web per `id_pengguna` akun aktif, `androidAkun(list<int>)` Android lewat `pengguna.nip` bila ada, hasil digabung lalu dideduplikasi), `backend/app/Libraries/Push/README.md` (satu kalimat). `PushNotifGatewayInterface` dan `push-notif` tidak disentuh (BP8-1). Commit terpisah `fix(push): …` (CR-137) di branch MAKE-072 |
| Test CR-137 | regresi pemakai lain `AntrePushPesan` (`PushAndroidCuti`, `PushAndroidLkhKonket`: cuti/LKH/Konket); job lama `nip[]` yang sudah antre tetap terkirim; akun role 1 tanpa NIP menerima token web; dedup |

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
| `kategori/{id}/status` | PATCH | 1 | masuk `{status: 1\|2}`; dipakai aksi ⋮ Aktifkan/Nonaktifkan; diaudit (✔, pola `backend/app/Config/Routes.php:71`, `RoutesKonten.php:60`) | `:100-118` |
| `tag` | GET, POST | 1 | daftar berhalaman (urut id menurun), tambah | `:133-186` |
| `tag/{id}` | GET, PUT, DELETE | 1 | detail; ubah (judul, status 1/2); hapus = status 10 | `:158-214` |
| `tag/{id}/status` | PATCH | 1 | masuk `{status: 1\|2}`; aksi ⋮ Aktifkan/Nonaktifkan; diaudit (✔) | `:188-205` |
| `tag/ekspor` | GET `?format=xlsx` | 1 | xlsx daftar tag (F8-47); format lain → 422 | `:216-252` |
| `qna` | GET, POST | 1 | daftar berhalaman (urut id menurun; filter status, kategori, tag, `q`) dengan nama kategori dan tag; tambah (F8-41) | `:258-336` |
| `qna/{id}` | GET, PUT, DELETE | 1 | detail (+ tag, berpenanda status F8-49); ubah (ganti set tag atomik); hapus = status 10 | `:293-377` |
| `qna/{id}/status` | PATCH | 1 | masuk `{status: 1\|2}`; aksi ⋮ Aktifkan/Nonaktifkan tanpa mengirim ulang jawaban/tag (tanpa sanitasi ulang dan tanpa ganti relasi); diaudit (✔) | `:338-368` |
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
| `support_officer` | `id_support_officer` PK, `id_user` (→ `pengguna.id_pengguna`), `email` (wajib di form, `LS:756`), `no_hp` (opsional, `LS:798`), `status` (tipe legacy [I], domain 0/1 [K]; ralat v1.4), `created_at` | Kolom `email`/`no_hp`/`created_at` diturunkan dari kode legacy (DDL produksi tak tersedia; label per kolom diralat v1.4, tabel di bawah). v2: `status` TINYINT 0/1 + CHECK; `id_user` NOT NULL, UNIQUE (petugas unik per akun, `LS:765-788`), FK RESTRICT; `updated_at` ditambah (audit) [V2]. Bahan Baku E-01 menyebut `support_officer.nip` dan `id_category` — **tidak** dipakai karena menyimpang dari legacy dan dari DQ-4 (impor lurus); dicatat sebagai deviasi dokumen (Q8-13) |
| `officer_category` | `id` PK (nama [I], v1.4), `id_support_officer`, `id_question_category`, `created_at` | [K `LS:803-812`]; v2: UNIQUE `(id_support_officer, id_question_category)`, FK RESTRICT (aksi FK legacy [I], v1.4; rumusan "legacy CASCADE" gugur); penggantian set kategori = hapus + sisip dalam satu transaksi di service [K `LS:627-629`] |
| `help_desk_contact` | `id_help_desk_contact` PK, `name`, `contact`, `email`, `status` (0/1), `created_at`, `updated_at` ([V2], v1.4) | [K `LS:31-210`]; unik nomor `contact` ditegakkan aplikasi di legacy (`LS:178-197`) → v2: UNIQUE setelah pra-cek duplikat di data impor |

**Ralat label (v1.4, hasil review DBV-028 CR-6 dan CR-9; label final per kolom = `backend/docs/db-review/DBV-028-skema-helpdesk.md`
§2):**

| Butir | Label v1.4 | Catatan |
|---|---|---|
| `updated_at` ketiga tabel master | [V2] | tidak ada di legacy; stempel ubah untuk audit |
| `created_at` ketiga tabel | kolom [K]; isi legacy dari DEFAULT DB [I]; penghapusan DEFAULT [V2] (BP8-8) | aplikasi legacy tidak pernah mengisi kolom ini (DBV-028 bagian Bahan); v2 diisi aplikasi (`StempelUtc`), NULL-able tanpa DEFAULT (DBV-028 Q7) |
| PK `officer_category.id` | [I] | tidak dirujuk kode legacy (DBV-028 Q10) |
| Aksi FK legacy "CASCADE" | [I] | tanpa DDL; `delete_officer` `LS:711` tidak menyentuh `officer_category` (DBV-028 Q9); v2 RESTRICT [V2] |
| Tipe kolom `status` legacy | [I] | domain 0/1 tetap [K]; v2 TINYINT + CHECK [V2] |

**ALTER aditif yang diusulkan (keputusan DBV):** indeks `disiplin_ticket (sender, updated_at)` dan
`(id_question_category, status, is_read_off)` untuk daftar/antrean; indeks `disiplin_ticket_msg (id_inbox, created_at)`;
keputusan **FK `nip` → `pegawai.nip`** yang ditahan di `MigrationsDitahan/AddFkNipLayananFase4.php`
(`fk_nip_disiplin_ticket_to_pegawai`): dipasang hanya bila audit yatim setelah impor = 0, bila tidak validasi di aplikasi.
Domain `is_read`/`is_read_off` (1/2) dan `notify_pic` (0/1/2) tidak diubah.

**Koreksi indeks (v1.3, A0 + review putaran 2; nilai final = hasil review DBV-028).** Usulan `disiplin_ticket (sender,
updated_at)` di atas **gugur v1.3**: `updated_at` ber-`ON UPDATE` (berubah saat baca/tutup) sehingga tidak melayani urutan.

| Tabel | Indeks yang diajukan ke DBV | Untuk |
|---|---|---|
| `disiplin_ticket` | `(id_question_category, is_read_off, status)`, **atau** `(id_question_category, status, is_read_off)` dengan `status IN (0,1)` ditulis eksplisit di kueri ringkasan; dokumen DBV-028 memuat `EXPLAIN` kedua bentuk dan DBV memilih (v1.4: bentuk kedua, Q6) | antrean, `belum_dibaca_petugas`, `perlu_tindak` |
| `disiplin_ticket` | `(sender, status)` (PK sudah ikut di indeks sekunder InnoDB) | daftar `peran=saya` + filter status, `belum_dibaca_saya` |
| `disiplin_ticket_msg` | `(id_inbox, created_at)` | thread + subkueri pesan terakhir (`ORDER BY created_at DESC, id_inbox_msg DESC LIMIT 1`) |

- Opsi terukur untuk DBV (v1.4: diputus Q8, di bawah): kolom `last_msg_at` (diisi aplikasi saat sisip pesan) bila `EXPLAIN` daftar pada data uji besar buruk;
  tanpa itu, urutan memakai subkueri lewat indeks `disiplin_ticket_msg`.
- **Dua KEY FK menjadi redundan** terhadap indeks komposit berawalan sama: `fk_id_question_category_i_qc`
  (`backend/app/Database/Migrations/2026-10-26-100500_CreateDisiplinTicketFase4.php:97`) dan `fk_id_inbox_im_qc` (`:121`). Membuang
  atau mempertahankannya **diputus DBV** (v1.4: dipertahankan, Q4) (FK tetap butuh satu indeks berawalan kolomnya); migration lama tidak diedit.
- Tabel baru (usulan A0, label final di dokumen DBV): `support_officer.id_user` **INT UNSIGNED** NOT NULL UNIQUE FK RESTRICT ke
  `pengguna.id_pengguna` (tipe sama dengan kolom acuan); `email` VARCHAR(150) [I], `no_hp` VARCHAR(20) NULL [I], `status` TINYINT
  CHECK (0,1); `officer_category` UNIQUE `(id_support_officer, id_question_category)` + KEY `(id_question_category)`;
  `help_desk_contact.contact` VARCHAR(20) UNIQUE, `name` VARCHAR(150), `email` NULL ≤ 150 [I]. Panjang [I] sampai D1 tiga tabel
  (Q-A0-3, tidak menahan DBV-028). `question_category`: UNIQUE nama sesudah pra-cek atau unik di aplikasi seperti legacy = keputusan
  DBV.
- Kueri pra-cek (ditulis di dokumen, dijalankan DBV/reviewer di salinan dev, hanya angka): `contact` ganda; `id_user` ganda;
  pasangan petugas-kategori ganda; `officer_category` yatim dua arah; `support_officer.status` di luar '0'/'1'; nama kategori ganda
  (trim, ci); `support_officer.id_user` yang tidak ada di `pengguna`; irisan tiket NULL + Open + sender yatim (B6) dan `attachments`
  yang tidak cocok pola segmen `^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$` (B7) untuk MAKE-097.

**Keputusan DB Validator DBV-028 (v1.4, WS-2 17-11-2026; DBV-028 §8):**
- **Q4:** dua KEY FK redundan **tidak** dibuang di DBV-028; boleh menyusul lewat migration ALTER dengan kartu DBV sendiri.
- **Q6:** indeks `(id_question_category, status, is_read_off)` dengan `status IN (0, 1)` eksplisit di kueri ringkasan sisi
  petugas, dibekukan di README + test EXPLAIN MAKE-071 (T13); `(sender, status)` dan `disiplin_ticket_msg (id_inbox,
  created_at)` disetujui.
- **Q8: `last_msg_at` TIDAK masuk DBV-028**, dengan syarat: (a) Q-H19 + EXPLAIN daftar petugas wajib dijalankan di gladi impor
  sebelum cutover (hasilnya dicatat di runbook MAKE-074); (b) bila pemicunya terpenuhi (Q-H19 > 5.000 atau daftar petugas
  > 100 ms), kartu DBV `last_msg_at` wajib masuk `main` sebelum go-live; (c) test EXPLAIN MAKE-071 memakai ambang yang sama.
- **Q9:** Q-H4 (`officer_category` yatim ke petugas) = INFO; dibuang + jumlahnya dicatat di laporan impor MAKE-097.
- **Q10:** PK `officer_category.id` = [I]; dicocokkan dengan salinan legacy saat MAKE-097 tanpa mengubah skema v2.

**Tidak** ada tabel notifikasi/antrean baru (digantikan `integrasi_log` dan queue Fase 6). Label [K]/[V2]/[I] per kolom di dokumen DBV.

**AC-E01.1** `backend/docs/db-review/DBV-028-*.md` di-approve DBV (v1.4: ✔ SETUJU Q1–Q10, di `main` `a5cb7300`); **AC-E01.2** migration naik/turun bersih di DB scratch dan
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
- **Hapus petugas (v1.3, K-A4 (1'), Horii 16-11-2026).** Tiket terikat ke kategori (`receiver = 0` [K]), bukan ke petugas, sehingga
  rumusan "tidak ada tiket aktif milik petugas" **gugur v1.3**. Hapus fisik [K]: `officer_category` lalu `support_officer` dalam
  satu transaksi dengan snapshot `before`. Hapus → **422 `petugas_terakhir`** bila petugas itu petugas **terakhir** (aktif **atau**
  nonaktif) sebuah kategori yang masih punya tiket Open (menutup celah Nonaktifkan → Hapus); selain itu boleh. **Nonaktifkan**
  petugas aktif terakhir tetap boleh (offboarding darurat) → 200 + `peringatan` (§6.1b). `Petugas.bisa_hapus` +
  `alasan_tidak_bisa_hapus` dihitung dari aturan yang sama agar ⋮ Hapus `disabled`. Runbook MAKE-074/MAKE-097 menghitung tiket
  Open di kategori tanpa petugas aktif sesudah impor.
- **Batas field master (v1.3):** §7.3a. Domain status master tetap 0/1 (berbeda dari chatbot 1/2). Tombol ekspor Excel/CSV bawaan
  DataTables di list master legacy tidak dibawa (B-31).
- **v1.4 (MAKE-070 di `main` `56a700b1`; keputusan Horii dan reviewer 17-11-2026):** README master beku dengan selisih S1–S12
  (§6.1b). **S8 (b):** PUT petugas yang membuat sebuah kategori ber-tiket Open kehilangan petugas aktif terakhirnya (kategori
  dilepas dari set, atau `status` diubah ke 0) tetap disimpan → 200 + `peringatan` dengan teks yang sama seperti Nonaktifkan
  (setara K-A4 (1')); hanya hapus permanen yang diblok. **Kunci transaksi (W1, keputusan reviewer):** cek hapus dan peringatan
  dihitung di dalam transaksi sesudah baris petugas, relasi kategorinya, dan kategori terkait dikunci; semua pembacaan sebelum
  kunci terakhir wajib locking read, sehingga dua admin yang bersamaan menghapus/menonaktifkan dua petugas terakhir tidak bisa
  sama-sama lolos. Aturan yang sama dipakai MAKE-071 (T7). Route admin FE: `/halo-simpeg-admin/:bagian` (§6.3).

**AC-E02.1** CRUD kategori/petugas/kontak role 1; role lain 403; **AC-E02.2** satu kategori dengan ≥2 petugas, satu petugas dengan ≥2
kategori; **AC-E02.3** petugas duplikat ditolak 422; **AC-E02.4** hapus kategori yang dipakai → 422; **AC-E02.5** kandidat hanya
pegawai aktif satker kepegawaian + role 1 (v1.4: akun aktif; pegawai ber-role 1–7, S5/S12); **AC-E02.6** semua tulis tercatat di `audit_logs`; **AC-E07.1** role 1–7 melihat kontak
aktif, hanya role 1 melihat semua dan mengubah.

**AC tambahan v1.2:** **AC-E02.7** semua route helpdesk `api/v1/halo-simpeg/*` (di luar `chatbot/`) terdaftar dan lolos
`backend/tests/feature/KontrakMatriksRoleTest.php` dengan `PREFIKS_CAKUPAN` diperluas ke `halo-simpeg` (`:60`), role per route
sesuai hasil pencocokan Matriks Modul E di A0 (K-A7); **AC-E02.8** aturan hapus petugas sesuai definisi yang dibekukan di A0/MAKE-070
(K-A4) dan teruji dua arah (boleh / ditolak 422); **v1.3 (1'):** petugas kedua (aktif) di kategori ber-tiket Open → hapus boleh;
petugas terakhir (aktif atau nonaktif) kategori ber-tiket Open → 422 `petugas_terakhir`; Nonaktifkan petugas aktif terakhir → 200 +
`peringatan` tidak `null`; `bisa_hapus` sama dengan hasil aksi (test kesetaraan); v1.4: PUT yang melepas kategori aktif terakhir ber-tiket Open → 200 + `peringatan` (S8 (b)); **AC-E02.9** Aktifkan/Nonaktifkan kategori, petugas, dan kontak lewat endpoint
status terpisah (K-A1) tanpa mengirim ulang payload penuh, dan tercatat di `audit_logs`; v1.3: status sama dengan nilai sekarang
→ 200 tanpa tulis dan tanpa audit (idempoten); nilai di luar 0/1 → 422. **AC-E07.1 (v1.3):** diuji pada dua path `kontak/aktif`
(role 1–7) dan `kontak` (role 1; role 2–7 → 403 dari filter route).

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
- **v1.3:** throttle dicek **sebelum** unggah (lewat batas → 429 tanpa baris tertulis dan tanpa berkas tersimpan); berkas
  disimpan sebelum transaksi (urutan legacy) dengan kunci F8-17 dan dicatat `PenjagaBerkas`; respons 201 `TiketDetail` (§6.1b).
- **v1.4 (T5, T6):** teks galat pelapor tanpa data pegawai = [K] "Data pegawai tidak ditemukan didalam sistem."; galat berkas
  lampiran di `errors.arsip`; teks lain di README tiket (§6.1b).

**AC-E03.1** tiket tersimpan di `disiplin_ticket` berstatus Open dengan pesan pertama; **AC-E03.2** `inbox`/`inbox_msg` tidak
bertambah satu baris pun (test eksplisit); **AC-E03.3** kategori nonaktif/tidak ada → 422; **AC-E03.4** pesan kosong, subjek kosong
→ 422; **AC-E03.5** lampiran >5 MB / ekstensi di luar daftar / isi tidak cocok ekstensi → 422 dan tidak ada baris; **AC-E03.6**
gagal di tengah transaksi → tidak ada tiket, pesan, atau berkas sisa; **AC-E03.7** role di luar `UL_PEGAWAI` (Q8-01) → 403; **AC-E03.8**
snapshot jabatan/satker tersimpan; **AC-E03.9** melebihi throttle → 429 (angka per §7.3a); **AC-E03.10** push antre sesudah commit dan kegagalan
antrean tidak menggagalkan tiket.

#### 7.3a Angka terukur helpdesk (v1.2): ditetapkan di A0, dikonfirmasi Horii

**✔ FINAL (v1.3): angka diusulkan A0 dan dikonfirmasi Horii 16-11-2026 (Q-A0-8, "ikut rekomendasi semua").** Tabel final di
bawah menggantikan tabel v1.2 sesudahnya (kolom "Nilai" v1.2 yang berbunyi "Ditetapkan di A0…" gugur v1.3). Semua angka di
`Config\HaloSimpeg` dan dapat ditimpa `.env`. Throttle memakai `Services::throttler()` dengan kunci per `id_pengguna` (pola
`DokumenService::wajibThrottle`, `backend/app/Libraries/Dashboard/Dokumen/DokumenService.php:353-360`). Panjang = `mb_strlen`
(code point) **sesudah trim**; galat 422 per kolom `errors.<field>`.

| Angka / field | Nilai final | Teks galat / catatan |
|---|---|---|
| Throttle buat tiket | **5 per 10 menit** per akun (kapasitas 5, 600 detik) | 429 "Terlalu banyak pertanyaan baru. Coba lagi dalam 10 menit." |
| Throttle kirim pesan | **20 per menit** per akun (satu kunci untuk balas di semua tiket) | 429 "Terlalu banyak pesan. Coba lagi dalam satu menit." |
| tiket `id_question_category` | wajib, ada, status 1 | "Kategori wajib dipilih." / "Kategori tidak tersedia." |
| tiket `subject` | wajib, ≤ **250** [K kolom VARCHAR(250)] | "Subjek wajib diisi." / "Subjek maksimal 250 karakter." |
| tiket/pesan `msg` | wajib, ≤ **5.000** (kolom TEXT 65.535 byte ≈ 16.383 karakter utf8mb4, jadi jauh di bawah batas kolom) | "Pesan wajib diisi." / "Pesan maksimal 5.000 karakter." |
| `arsip` | opsional; 9 ekstensi, ≤ 5 MB, isi cocok (`AturanLampiran`/`ValidasiBerkas`) | teks `ValidasiBerkas` yang ada |
| `ingatkan_24_jam` | {0,1}; 1 hanya petugas aktif bukan pemilik (F8-11) | "Nilai pengingat tidak valid." / "Pengingat 24 jam hanya dapat diatur petugas." |
| kategori `question_category` | wajib, ≤ **250** [K], unik (trim, ci) | "Nama kategori wajib diisi." / "Nama kategori maksimal 250 karakter." / `kategori_duplikat` |
| `status` master | {0,1} | "Status tidak valid." |
| petugas `id_user` | wajib, akun aktif, kandidat F8-07, unik | "Akun petugas wajib dipilih." / "Akun tidak memenuhi syarat petugas." / `petugas_duplikat` |
| petugas `category[]` | ≥ 1, semua ada | "Minimal satu kategori." |
| petugas `email` | wajib, format email, ≤ **150** [I] | "Email wajib diisi." / "Format email tidak valid." / "Email maksimal 150 karakter." |
| petugas `no_hp` | opsional, ≤ **20** [I] | "Nomor HP maksimal 20 karakter." |
| kontak `name` | wajib, ≤ **150** [I] | "Nama wajib diisi." / "Nama maksimal 150 karakter." |
| kontak `contact` | wajib, ≤ **20** [I], unik | "Nomor kontak wajib diisi." / "Nomor kontak maksimal 20 karakter." / `kontak_duplikat` |
| kontak `email` | **opsional**, format email, ≤ **150** [I] (legacy form tanpa validasi, `views/hr/support/help_desk/form.php:52-56`) | "Format email tidak valid." / "Email maksimal 150 karakter." |
| kandidat `search` | 2..50 karakter, maks 20 hasil | "Kata kunci minimal 2 karakter." |
| ekspor `ds`, `de` | wajib, `Y-m-d`, `ds` ≤ `de`; tanggal kalender WIB → rentang UTC `[ds 00:00 WIB, de+1 00:00 WIB)`; tanpa batas hari | "Tanggal awal dan akhir wajib diisi." / "Tanggal awal tidak boleh melebihi tanggal akhir." |
| ekspor `format` | hanya `xlsx` (AC-E09.6) | "Format ekspor tidak didukung." |
| Batas baris ekspor `eksporMaksBaris` | **10.000** baris pesan per berkas (dihitung `COUNT` sebelum membangun berkas) | 422 `ekspor_terlalu_besar` (teks netral §6.1b); tanpa berkas terpotong |
| Batas ukuran ekspor `eksporMaksKarakter` | `SUM(CHAR_LENGTH(msg))` ≤ ambang; **ambang ditetapkan lewat pengukuran memori nyata di test MAKE-074** | 422 `ekspor_terlalu_besar` (teks sama) |
| Potong sel ekspor | **32.767** karakter per sel (batas sel Excel), penanda "… [dipotong]" | [V2] (B-27); pengecualian AC-E09.8 |
| Paginasi | `per_page` 1..100, bawaan 20 (`perHalamanBawaan`, `perHalamanMaks`) | parameter array → 422 |

Panjang kolom master [I] di atas adalah batas aplikasi; nilai final mengikuti tipe kolom DBV-028, dan bila D1 tiga tabel didapat
(Q-A0-3), batas aplikasi = panjang kolom.

Tabel v1.2 (riwayat; kolom "Nilai" digantikan tabel final di atas):

Angka di bawah **belum final**. Handling Case mengusulkan angkanya di laporan A0 (K-A8), reviewer memeriksa, **Horii
mengonfirmasi**, lalu angka ditulis di `Config\HaloSimpeg` (bisa ditimpa `.env`) dan di README kontrak. Sebelum itu
AC-E03.9, AC-E09.* yang bergantung pada angka belum bisa diuji tepat. Nilai **[usulan]** hanya diberikan bila ada sumber legacy.

| Angka | Sumber legacy | Nilai |
|---|---|---|
| Throttle buat tiket per akun | Tidak ada (legacy tanpa batas, BP8-10) | Ditetapkan di A0, dikonfirmasi Horii (pembanding v2: throttle chatbot F8-36 `backend/app/Config/Chatbot.php:41-45`; bukan usulan) |
| Throttle kirim pesan per akun | Tidak ada | Ditetapkan di A0, dikonfirmasi Horii |
| Panjang `subject` | Kolom `subject` VARCHAR(250) (`backend/app/Database/Migrations/2026-10-26-100500_CreateDisiplinTicketFase4.php:79`, rename 1:1 legacy) | **[usulan] 250 karakter** [K] |
| Panjang `msg` | Tidak ada batas aplikasi (validasi legacy hanya "tidak boleh kosong" `LS:1505-1526`); kolom `msg` TEXT (`…CreateDisiplinTicketFase4.php:114`, batas teknis 65.535 byte) | Ditetapkan di A0, dikonfirmasi Horii (tidak boleh melebihi batas kolom) |
| Panjang nama kategori | `question_category` ≤ 250 (§7.2) | **[usulan] 250 karakter** [K] |
| Batas baris ekspor E-09 | Tidak ada (legacy memuat seluruh periode `LS:1600-1830`) | Ditetapkan di A0, dikonfirmasi Horii, beserta perilaku bila terlampaui (422 berpesan, bukan berkas terpotong diam-diam) |

### 7.4 E-04 — Daftar & percakapan (MAKE-071)

[K `LS:817-927`, `LS:1097-1305`, `LS:1307-1430`, `LS:1567-1597`, `VI`, `VD`].
- **Daftar** (`peran=saya|petugas`): `saya` = `sender` = pengguna; `petugas` = `id_question_category` ∈ kategori petugas aktif
  (F8-06). Gabungan menandai `sebagai_petugas`. Hanya tiket yang punya pesan ditampilkan (`LS:868`) (gugur v1.3: tiket tanpa pesan
  ditampilkan di akhir [V2], lihat butir v1.3 di bawah). Kolom: tanggal tiket, subjek,
  kategori, pengirim (petugas melihat), tanggal & isi pesan terakhir (terpotong), status Open/Closed, penanda belum dibaca per sisi
  (`VI:87-92`). Urut pesan terakhir desc (`LS:878-883`). Filter kategori/status/kata kunci; paginasi di server.
- **Detail:** semua pesan urut `created_at` ascending; lampiran per pesan; tiket orang lain → 404 (BP8-2).
- **Tandai baca:** `POST …/baca` — pemilik menyetel `is_read=1`; petugas menyetel `is_read_off=1` (`LS:907-920`).
- **Balas:** F8-10. `msg_sender` = id pengguna, `msg_sender_name` = nama bergelar untuk akun ber-NIP (`LS:1581-1588`),
  `msg_receiver` = pemilik bila pembalas petugas; bila pembalas pemilik → `receiver` tiket (0) (`LS:1590-1595`). Petugas boleh
  `ingatkan_24_jam` (F8-11).
- Petugas yang juga pemilik tiket di kategorinya sendiri: dua sisi berlaku (tidak dihitung ganda di ringkasan). (v1.3: diperjelas
  §6.2 — tiket milik sendiri hanya dihitung di sisi pemilik, dan `POST …/baca` oleh pemilik hanya menyetel `is_read`.)
- **v1.3 (A0 + keputusan reviewer dan Horii 16-11-2026):**
  - **Isi daftar** legacy = tiket sendiri ∪ tiket kategori petugas (bukan semua tiket). Tiket **tanpa pesan** (mungkin sesudah impor,
    `backend/app/Libraries/Impor/AturanTiketLama.php:102-114`) **ditampilkan di akhir** dengan `pesan_terakhir: null` [V2, B-20],
    agar tidak ada tiket Open tersembunyi yang tidak bisa ditutup (exit criteria E-05); urutan §6.1b.
  - **Bentuk respons, flag, penyamaran, urutan_pengirim:** §6.1b. Penyamaran D7 (a) ikut legacy per layar [K]; id akun petugas tidak
    dikirim ke non-role-1.
  - **Filter baca** `baca=semua|belum|sudah` dan **`perlu_tindak=1`** (§6.1b); `peran=petugas` untuk non-petugas/nonaktif → 200
    kosong (konsisten AC-E04.5).
  - **Tiket berkategori NULL (✔ H8-16 (a1), Horii 16-11-2026, tanpa ubah data):** tampil di daftar pemiliknya [K]; detail dan baca
    oleh pemilik = [V2] (detail legacy memakai INNER JOIN `question_category`, `LS:897-901`, sehingga tiket NULL tidak bisa dibuka
    di legacy, B-21); balas → 422 `tiket_tanpa_kategori`; tutup hanya pemilik; hapus → 403 `bukan_pihak_berwenang` (F8-09 [V2]);
    tidak terlihat petugas mana pun dan tidak ada perluasan akses role 1 (field `antrean_tanpa_kategori` tidak dibuat); tidak dihitung
    di sisi petugas; tidak ikut ekspor; tiket NULL yang pengirimnya yatim tidak terlihat siapa pun (sama dengan legacy) dan jumlahnya,
    beserta jumlah tiket NULL, dicatat di runbook MAKE-074 (kueri B6). Flag: `bisa_balas=false`, `bisa_tutup=true` (pemilik),
    `bisa_hapus=false`.
  - **Balas oleh pemilik yang petugas kategori lain** diizinkan [V2, B-19] (F8-08).
  - **Nama bergelar** untuk semua akun ber-NIP (bukan hanya `UserLevel` 2/6, `LS:1580-1588`) [V2 kecil, B-28].
- **v1.4 (keputusan MAKE-071, §6.1b):** `peran=petugas` mengecualikan tiket milik sendiri (T1); `q` mencari subjek atau isi
  pesan (T11); `snapshot`/`pengingat` hanya bagi petugas tiket itu yang bukan pemilik (T3); urutan cek balas + kunci `FOR
  UPDATE` (T7); nama pengirim bergelar (T12).

**AC-E04.1** pemilik hanya melihat tiketnya; petugas melihat tiket kategorinya saja; pengguna lain 404; **AC-E04.2** pesan tersimpan
urut di `disiplin_ticket_msg` dan tidak bercampur antar tiket (test dua tiket paralel); **AC-E04.3** balasan pada tiket Closed atau
`allow_reply=0` ditolak dengan pesan legacy; **AC-E04.4** penanda baca: balasan petugas → `is_read=2`, balasan pemilik →
`is_read_off=2`, buka thread → ditandai dibaca sesuai sisi; v1.3: diuji per skenario pembanding legacy (i) pemilik `POST baca` →
`is_read=1`, (ii) petugas `POST baca` → `is_read_off=1`, (iii) petugas membalas → `is_read_off=1`, `is_read=2`, (iv) pemilik membalas →
`is_read=1`, `is_read_off=2`, ditambah test pemilik-petugas §6.2 (`is_read_off` tetap 2); **AC-E04.5** petugas nonaktif tidak melihat antrean (F8-06) tetapi
tetap melihat tiket miliknya; **AC-E04.6** daftar terpaginasi dan terurut pesan terakhir; **AC-E04.7** unduh lampiran hanya untuk
pihak berhak, header aman (`Content-Disposition`, tipe dari daftar); **berlaku untuk lampiran lama (path legacy impor) dan baru**,
dan tetap berfungsi baik saat saklar `arsip.penyajianBerkasLegacy` bernilai `publik` maupun `privat` (v1.2, F8-17); **AC-E04.8** `GET` tidak mengubah baris (test); **AC-E04.9**
teks dengan tanda kutip/backslash tersimpan utuh (BP8-4); **AC-E04.10** balasan petugas dengan `ingatkan_24_jam` menyetel
`notify_pic=1`, `notify_at` = +24 jam UTC, dan balasan petugas berikutnya mereset ke 2; v1.3: nilai 1 dari pemilik/non-petugas → 422,
nilai di luar {0,1} → 422 `errors.ingatkan_24_jam`.

**AC tambahan v1.2:** **AC-E04.11** tiket berkategori NULL (impor F-02: kolom nullable
`backend/app/Database/Migrations/2026-10-26-100500_CreateDisiplinTicketFase4.php:78`, dihitung `INFO_KATEGORI_NULL`
`backend/app/Libraries/Impor/AturanTiketLama.php:73`, `:119`, `:132`) diperlakukan sesuai keputusan **H8-16** (§12): siapa yang
melihat, menutup, dan menghapusnya, serta bagaimana dihitung di ringkasan dan ekspor; ada test per perlakuan yang dipilih.
Sebelum H8-16 diputus, AC ini ◻ dan perlakuannya diusulkan A0. **v1.3 — ✔ H8-16 (a1):** test pemilik melihat di daftar dan
membuka detail (`kategori: null`, `bisa_balas=false`, `bisa_tutup=true`, `bisa_hapus=false`), balas → 422 `tiket_tanpa_kategori`,
petugas mana pun dan role 1 non-pemilik → 404, tidak dihitung di `belum_dibaca_petugas`/`perlu_tindak`, tidak ikut ekspor.

**AC tambahan v1.3:** **AC-E04.12** penyamaran D7 (a): fixture/test 4 kasus label
detail (§6.1b) + pasangan daftar dilihat role 1 → "Admin" dan detail yang sama dilihat role 1 → nama; respons ke non-role-1 tidak
memuat id akun petugas; **AC-E04.13** `perlu_tindak=1` hanya sah dengan `peran=petugas` (selain itu 422) dan `total`-nya =
`Ringkasan.perlu_tindak`; **AC-E04.14** flag `bisa_balas`/`bisa_tutup`/`bisa_hapus` sama dengan hasil aksi untuk pemilik, petugas
aktif, petugas nonaktif, pemilik yang petugas kategori lain (B-19), dan tiket NULL; **AC-E04.15** tiket tanpa pesan tampil di akhir
daftar dengan `pesan_terakhir: null` (B-20); urutan dan pemecah seri §6.1b teruji.

### 7.5 E-05 — Tutup & hapus (MAKE-071)

[K `LS:1432-1503`, `SC:661-749`]. Tutup: F8-08; sudah ditutup → 422 "Percakapan ini telah ditutup sebelumnya …" (`SC:672`).
Hapus: F8-09 (fisik + audit `before` + hapus berkas; v1.3: hapus pesan dulu lalu tiket dalam satu transaksi, karena FK v2 RESTRICT
sedangkan legacy CASCADE; berkas lewat `PenjagaBerkas`, di bawah). Setelah ditutup tidak bisa dibalas; alur berujung ke satu node akhir
("Closed"), tidak ada status menggantung (Bahan Baku E-05).

**AC-E05.1** tutup mengubah status & flag sesuai F8-08 dan `closed_by` terisi; **AC-E05.2** balasan sesudah tutup ditolak;
**AC-E05.3** menutup dua kali ditolak; **AC-E05.4** pihak tak berhak tutup/hapus → 404/403; **AC-E05.5** hapus meninggalkan jejak
audit dan menghapus pesan serta berkas lampiran tiket itu saja (v1.3: berkas yang masih dirujuk baris lain tidak dihapus; berkas
ber-path anomali tertinggal dan tercatat, Q-A0-6 (a')); **AC-E05.6** tidak ada tiket Open yang tak bisa ditutup (test
alur penuh buat → balas → tutup).

**AC tambahan v1.2:** **AC-E05.7** hapus tiket **impor** yang lampirannya ber-path legacy (`arsip/{nip}/inbox/…`, termasuk nama
berkas berspasi atau berkarakter di luar `[A-Za-z0-9._-]`) **tidak gagal** dan tidak meninggalkan baris yatim. Catatan teknis:
`LocalStorageAdapter::hapus()` (`backend/app/Libraries/Kepegawaian/Lampiran/LocalStorageAdapter.php:62-69`) memakai validasi segmen
ketat `lengkap()` (`:135-146`) yang menolak path legacy semacam itu, sedangkan `baca()` menerima path legacy lewat
`PathLegacy::normalisasi()` (`:104-109`). A0 memutus salah satu: (a) berkas lampiran lama **tidak dihapus** dari storage dan
jumlahnya dicatat (berkas yang sama juga tersaji lewat `/berkas/`, H8-5 (a)); opsi ini menyimpang dari F8-09/Q8-03 sehingga
butuh konfirmasi Horii; atau (b) dihapus
lewat CR adapter (hapus path legacy ternormalisasi, tetap di dalam akar). Test memakai nama berkas berspasi.
**v1.3 — ✔ Q-A0-6 (a') (Horii 16-11-2026), menggantikan opsi (a)/(b) di atas:** tanpa CR adapter. Urutan hapus tiket: (1) di dalam
transaksi, setiap path lampiran pesan tiket itu dicatat lewat `PenjagaBerkas::catat($path, 'disiplin_ticket_msg', 'attachments')`;
(2) pesan lalu tiket dihapus (FK `disiplin_ticket_msg` RESTRICT, DBV-022 V1), commit; (3) `selesaikan()`
(`backend/app/Libraries/Kepegawaian/Lampiran/PenjagaBerkas.php:101-112`) menghapus berkas **hanya bila tidak ada baris lain yang
merujuknya** (nama berkas legacy `lampiran_{id_qc}_{YmdHis}` tidak unik per pesan, B-29) dan menangkap path legacy yang ditolak
`lengkap()` (log `info` tanpa path, ref sha1). `hapus()` **tidak** dipanggil langsung. Path legacy berasal dari respons server arsip
(`LS:988`, `:1004`, `:1159-1161`); NIP kosong menghasilkan `arsip//inbox/…` yang ditolak segmen kosong
(`LocalStorageAdapter.php:141`). Klaim "hampir semua terhapus" diganti **"diukur pra-cek"** (kueri B7, angka saja); jumlah berkas
tak terhapus untuk audit/runbook diambil dari pra-cek itu. Test: dua tiket merujuk path legacy yang sama → hapus tiket pertama, tiket
kedua tetap bisa mengunduh; path berspasi → hapus tiket tidak gagal, berkas tidak terhapus, tercatat.
**AC-E05.8** perlakuan tiket berkategori NULL saat tutup/hapus mengikuti H8-16 (AC-E04.11), sehingga tidak ada tiket Open yang
menggantung tanpa pihak yang dapat menutupnya (exit criteria E-05). **v1.3 — ✔ H8-16 (a1):** pemilik menutup tiket NULL → 200;
pemilik menghapus → 403 `bukan_pihak_berwenang`; tiket NULL Open yang pengirimnya yatim tidak dapat ditutup siapa pun (sama
dengan legacy) dan jumlahnya dicatat di runbook MAKE-074 (kueri B6); bila angkanya > 0, Horii dapat memilih tutup otomatis khusus
irisan itu saat cutover (keputusan data, di luar Fase 8).
**AC tambahan v1.3:** **AC-E05.9** pemilik yang petugas kategori lain dapat menutup tiketnya sendiri (B-19); **AC-E05.10** `POST
tutup` → 200 `TiketBaris`, `DELETE` → 200 `{id}`, `ditutup_oleh_label` sesuai aturan detail D7 (a).
**AC tambahan v1.4:** **AC-E05.11** (T2 (b)) pemilik tiket yang juga petugas aktif kategori tiket itu menghapus → 403
`bukan_pihak_berwenang`; petugas aktif lain di kategori yang sama menghapus → 200 `{id}`.

### 7.6 E-06 — Notifikasi tiket (MAKE-072)

[K `LS:1029-1090`, `LS:1202-1300`, `LS:1373-1425`, `CR:987-1066`].
- `TicketNotifService` dipanggil **sesudah commit** oleh `TiketService`/`PercakapanService`; menentukan penerima (`PenerimaTiket`),
  lalu mengantre `PushHaloSimpegJob` per pesan (v1.2: atau `AntrePushPesan` → `push-pesan` bila H8-14 = (a); §6.4).
  **v1.3 (✔ H8-14 (a)):** `AntrePushPesan::antreAkun(id_pengguna[])` → `push-pesan`; `PushHaloSimpegJob` gugur; berkas Push =
  CR-137 (§6.4).
- **Pembagian paket (v1.2):** binding `RingkasanTiketInterface` (ganti NoOp) dan paket test §7.8 butir (5) masuk **MAKE-071**
  bersama `GET tiket/ringkasan`; MAKE-072 hanya push.
- **Penerima:** tiket baru → petugas aktif kategori; balasan petugas → pemilik; balasan pemilik → petugas aktif kategori; tutup →
  pihak lawan (F8-14). Pelaku tidak menerima notifikasi buatannya sendiri.
- **Job:** ambil token `firebase_token` penerima (deduplikasi), kirim lewat `PushNotifGatewayInterface`, hapus token invalid, retry
  terbatas (`TRIES`), catat hasil ke `integrasi_log`. Idempoten (pesan yang sudah dikirim tidak dikirim ulang — penanda pada job,
  bukan kolom baru). Payload: F8-16 (v1.4: payload terbatas, H8-8 belum ada).
- Penghitung belum dibaca: §6.2 (sumber badge menu dan, bila Q8-06 = B, lonceng F-08).
- **Tidak** menulis `inbox`/`inbox_msg`/`show_notif`.

**AC-E06.1** tiket baru mengantre push ke semua petugas aktif kategori (dan hanya kategori itu); **AC-E06.2** balasan mengantre
ke pihak lawan, tidak ke pelaku; **AC-E06.3** push gagal tidak menggagalkan pesan; token invalid dihapus; tidak retry selamanya;
**AC-E06.4** dengan `MockFcmAdapter` job lolos test; dengan `FcmV1Adapter` + `FakeTransport` (setelah MAKE-066) lolos test —
CI tidak memanggil layanan nyata; **AC-E06.5** payload tidak memuat NIP, jabatan, satker, token, atau isi pesan lengkap;
**AC-E06.6** `inbox`/`inbox_msg` tidak berubah oleh seluruh alur Halo Simpeg dan `disiplin_ticket(_msg)` tidak berubah oleh
alur approval Fase 4 (dua test regresi, BP8-1); **AC-E06.7** petugas role 1 dan role 6 (bukan hanya role 2) menerima push (perbaikan B-5);
v1.2: termasuk petugas role 1 **tanpa NIP**, dengan cara penerima sesuai **H8-14** (bila H8-14 = (c), AC ini dipersempit menjadi
"petugas ber-NIP" atas keputusan Horii); **v1.3:** H8-14 = (a), jadi AC ini tidak dipersempit: test akun role 1 tanpa NIP menerima
token web lewat `antreAkun` (CR-137), plus regresi pemakai `nip[]` lama;
**AC-E06.8** uji push sampai ke perangkat (Bahan Baku E-06) dilakukan **real** oleh reviewer sebelum deploy — dilacak MT/DEPLOY (bukan sign-off; v1.4: dicatat ke DEPLOY-001.1, H8-8).
**AC tambahan v1.2:** **AC-E06.9** binding `ringkasanTiket` di `backend/app/Config/Services.php:799-806` tidak lagi memakai
`RingkasanTiketNoOp`; implementasi `RingkasanTiketInterface` menghitung sesuai §6.2, dan `GET api/v1/notifikasi/ringkasan`
(`PusatNotifikasiService` `:110`) mengembalikan `tiket_belum_dibaca` berupa angka (bukan `null`) untuk pemilik, petugas, dan
petugas yang juga pemilik (tanpa hitung ganda), serta angka itu sama dengan `GET halo-simpeg/tiket/ringkasan`; v1.3: sama dengan
`Ringkasan.total` (bukan `badge`), 0 untuk role 1–8 tanpa tiket (role 8 = 0), dan commit binding sekaligus memperbarui
`backend/tests/Notifikasi/PusatNotifikasiTest.php:72-86` (yang kini meng-`assertNull` `:84`) serta README notifikasi dua sisi (§3);
**AC-E06.10** angka tiket di lonceng F-08 dapat ditelusuri sesuai keputusan **H8-15** (§12) dan diuji di QA (ulang C-N10 Fase 7,
`docs/fase7/Checklist_QA_Lapis1_Fase7.md:398`). **v1.3 — ✔ H8-15 (b):** entri "Tiket Halo Simpeg (n)" di `NotifikasiPage.vue`
tampil bila angka > 0 dan disembunyikan bila 0/`null`, menuju tab antrean bila sisi petugas punya angka, selain itu `/halo-simpeg`
(test Vitest kedua arah navigasi); QA memuat skenario petugas role 3 dan role 4 (role 4/5 tidak bisa membuka lonceng, sehingga
lewat badge menu).

### 7.7 E-08 — Frontend (MAKE-073)

Per §6.3. Detail: halaman daftar tiket dengan `UiSearchInput`, `UiSelect` (kategori/status), `UiBadge` (Open/Closed, belum dibaca);
thread bergaya gelembung (Gambar 33/34) dengan lampiran; form tiket baru (kategori, subjek, pesan, `UiFileField`); aksi baris tiket
petugas lewat ⋮ (Tutup, Hapus; label baku AGENTS.md §1; v1.3: **Lihat detail → Tutup → Hapus** di tabel petugas **dan** pemilik,
master-detail §6.3); panel "Perlu ditindak" untuk petugas; badge angka pada item menu Halo
Simpeg; tampilan kosong/galat/memuat. Teks pesan ditampilkan sebagai teks (tanpa `v-html`). Tanpa data contoh; `halo.store.ts` mock
(branch redesign) tidak dibawa. Test Vitest per komponen (keyboard + ARIA, AGENTS.md §3.3) dan test klien API asli (bukan hanya mock, pelajaran
amplop axios). QA Lapis 1 oleh reviewer: daftar tiket, thread, badge status, form buat tiket, beda tampilan pemilik vs petugas
(Bahan Baku E-08).

**AC-E08.1** pemilik melihat "tiket saya"; petugas melihat antrean kategorinya + tiket sendiri; **AC-E08.2** badge status & belum
dibaca benar; **AC-E08.3** form tiket menolak input tidak valid dan menampilkan galat 422 per kolom; **AC-E08.4** semua kontrol dapat
dioperasikan keyboard dan beraria benar; **AC-E08.5** halaman admin memakai `page-fill` + ⋮; **AC-E08.6** tidak ada `window.confirm`,
`<select>` native, atau `title` sebagai tooltip; **AC-E08.7** QA Lapis 1 vs Figma lolos (frame yang belum ada dicatat; bila H8-9 =
tidak ada Figma, diuji terhadap pola komponen redesign). **v1.3 (✔ H8-9: frame helpdesk belum ada):** AC-E08.7 diuji terhadap pola
redesign (pemilik: Gambar 33; petugas: master-detail Gambar 34; master: pola Master Data/PIC layanan) saat B5/QA bersama reviewer;
tanpa test Vitest, dicatat di checklist CR-132 bagian B.
**AC tambahan v1.2:** **AC-E08.8** sesudah logout atau login akun lain, store tiket dan badge ringkasan helpdesk kosong (reset dari
`clearSession()` `frontend/src/features/auth/stores/auth.store.ts:52-60`, pola `resetChatbot()` `:56`), dibuktikan Vitest "store
kosong sesudah logout"; **AC-E08.9** lampiran (lama dan baru) dibuka hanya lewat endpoint E-04, tidak lewat URL `/berkas/`.
**AC tambahan v1.3:** **AC-E08.10** ⋮ "Lihat detail" mengubah rute dan mengisi panel kanan; klik baris tidak berbuat apa-apa; tidak
ada `role="option"` di DOM; viewport < 1024 px menampilkan satu panel; **AC-E08.11** polling ringkasan tidak memanggil `GET
halo-simpeg/tiket/ringkasan` untuk role 8, sesi wajib ganti password, atau `ACTIVE_PHASE` < 8 (role 2 → satu request; adapter
pencatat yang menolak request lain); **AC-E08.12** badge menu = `Ringkasan.badge` (`sisipBadgeHalo`: ada, nol → tanpa badge, item
tidak ada), titik penanda saat sidebar ciut, lonceng tetap `total`, fixture `badge > total`; **AC-E08.13** bawaan filter baca per tab
(antrean `belum`, "Tiket saya" `semua`) dan panel "Perlu ditindak" memanggil service dengan `perlu_tindak: 1`; **AC-E08.14** ekspor:
200 JSON → tanpa unduhan dan pesan tampil, 200 xlsx → nama dari header, 422 → pesan galat; validasi periode (hari ini lolos, besok
ditolak, mulai = akhir lolos); **AC-E08.15** batas field Zod = §7.3a (= batas lolos, batas+1 ditolak) dan keadaan
memuat/kosong/galat (403/404/500) per layar; **AC-E08.16** admin Petugas: ⋮ Hapus `disabled` dari `bisa_hapus`, 422 tampil di
`UiNotice`, Nonaktifkan dengan `peringatan` menampilkan `UiNotice` peringatan. **v1.4:** route admin
master = `/halo-simpeg-admin/:bagian` (keputusan reviewer 17-11-2026, §6.3); QA bagian B memakai satker kepegawaian demo di Web Config untuk
kandidat petugas, dan butir yang bergantung pada H8-7 ditandai bersyarat.

### 7.8 Verifikasi "thread tidak ter-mix" (exit criteria Bahan Baku)

Paket test lintas modul (milik MAKE-071/072, dijalankan di CI; v1.2: butir (1)(2)(4)(5) di MAKE-071, butir (3) di MAKE-072): (1) dua tiket berbeda dengan pesan bersilang tidak saling
menampakkan pesan; (2) alur penuh Halo Simpeg tidak menambah baris `inbox`/`inbox_msg`; (3) alur penuh cuti/TB/IB/PMK (Fase 4)
tidak menambah baris `disiplin_ticket(_msg)` (AC-06.4, `NotifikasiTidakKeTicketingTest` yang sudah ada tetap hijau); (4) petugas kategori A tidak melihat tiket kategori B; (5) lonceng/ringkasan
tidak memuat notifikasi approval.

### 7.9 E-09 — Ekspor rekap petugas (MAKE-074)

F8-20 [K `LS:1600-1830`, `SC:751-786`]. Parameter `ds`, `de` (wajib, inklusif), `format=xlsx` (satu-satunya format legacy,
`SC:763`). Judul "REKAP PIC HALO SIMPEG {nama}" + periode; kolom bernomor; Excel lewat library yang sudah dipakai (ADR-032/035),
sinkron dengan batas baris (angka §7.3a). NIP penanya sebagai teks. Tombol ekspor di halaman petugas (`UiExportMenu`).
**v1.2 (◻ usulan reviewer, menunggu konfirmasi Horii saat review PRD v1.2, F8-20):** `ds`/`de` = tanggal kalender WIB → rentang UTC `[ds 00:00 WIB, de+1 00:00 WIB)` tanpa `DATE()` atas kolom (pola F8-42,
BP8-8; stempel tiket impor sudah UTC, DBV-029 R1a); kolom TIMESTAMP ditulis WIB; unduhan diaudit tanpa isi (pola F7-27
`backend/app/Libraries/Dashboard/Statistik/StatistikService.php:169`, F-10 `backend/app/Libraries/Kepegawaian/Arsip/ArsipService.php:203`).
**v1.3 — ✔ Q-A0-9 (Horii 16-11-2026):** usulan di atas dikonfirmasi (label "◻ usulan reviewer" gugur). Tambahan v1.3: izin =
`bisa_ekspor` (petugas **aktif**, berapa pun kategorinya, [V2] terhadap `VI:13`, B-24); periode kosong = 200 JSON tanpa berkas dan
tanpa audit (§6.1b); pengaman baris + ukuran dan potong sel 32.767 (§7.3a); tiket berkategori NULL tidak ikut; urut tiket lalu
pesan; nama berkas `Rekap_Halo_Simpeg_{ds}_{de}.xlsx`; XLSX ditulis `EksporTabel` (OOXML lewat ZipArchive, semua sel teks).

**AC-E09.1** hanya petugas (v1.3: petugas **aktif** = `bisa_ekspor`; petugas berkategori tunggal boleh; bukan petugas/nonaktif → 403
`bukan_petugas`, diuji bersama flag); **AC-E09.2** periode inklusif per hari WIB: tiket 00:00 WIB tanggal `ds` dan 23:59 WIB tanggal `de`
ikut, 23:59 WIB sehari sebelum `ds` tidak ikut (v1.2; ✔ v1.3 Q-A0-9); **AC-E09.3** tiket kategori lain tidak ikut (v1.3: termasuk tiket kategori NULL);
**AC-E09.4** NIP tidak berubah menjadi angka ilmiah; **AC-E09.5** periode tanpa data → 200 dengan pesan kosong (bukan berkas rusak) (v1.3: **200 JSON**
`{status:'success', data:{kosong:true, jumlah:0}, message}` dengan `Content-Type: application/json` dan tanpa `Content-Disposition`, fixture
`ekspor-kosong`); **AC-E09.6** format selain xlsx → 422 (v1.3: juga `ds`/`de` kosong atau `ds` > `de` → 422, teks §7.3a);
**AC-E09.7** (v1.2; ✔ v1.3 Q-A0-9) setiap unduhan ekspor tercatat di `audit_logs` (siapa, periode, jumlah baris), tanpa isi percakapan, NIP penanya, atau isi berkas;
v1.3: ekspor periode kosong **tidak diaudit** (tidak ada berkas; dicatat di README), diuji;
**AC-E09.8** (v1.2) periode yang melebihi batas baris §7.3a → 422 berpesan Bahasa Indonesia, tidak menghasilkan berkas terpotong. **v1.3:** juga
melebihi batas ukuran `SUM(CHAR_LENGTH(msg))` → 422 `ekspor_terlalu_besar` (teks netral yang sama; ambang diukur di test MAKE-074). "Tidak
terpotong" berarti **baris**: isi sel yang melebihi 32.767 karakter dipotong dengan penanda "… [dipotong]" [V2] sebagai **pengecualian AC ini**
(B-27), diuji dengan pesan sintetis > 32.767 karakter.

### 7.10 E-10 — Chatbot "Vanya" (DBV-033 skema, MAKE-094 BE, MAKE-095 FE)

Keputusan: F8-23..F8-33 (✔ K-1..K-11); bawaan teknis: F8-34..F8-51 (✔ dikonfirmasi Horii 12-11-2026). Singkatan rujukan legacy dev (relatif `application/`
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
| `log_chatbot` | `id_log` int **signed** PK, `pesan_user` TEXT, `top_1_id_qna` int NOT NULL tanpa FK, `top_1_skor` **FLOAT**, `top_3_kandidat` longtext (JSON), `status_match` tinyint NULL, `feedback` tinyint NULL, `waktu_interaksi` datetime DEFAULT CURRENT_TIMESTAMP; KEY `idx_log_qna_feedback (top_1_id_qna, status_match, feedback)` (`dev:LC:749-756`, `:800`) | **Anonim** (K-4): tanpa kolom identitas (sama dengan dev); waktu UTC diisi aplikasi (BP8-8, bukan DEFAULT DB); indeks `waktu_interaksi` (tidak ada di dev) + indeks ranking feedback (pembanding: indeks dev `idx_log_qna_feedback`, yang juga melayani rekap feedback `dev:LC:635-659` dan bubble pertanyaan `:597-616`; usulan awal `(status_match, top_1_id_qna)`); CHECK domain `status_match`/`feedback`. **✔ untuk DBV-033:** `top_1_skor` DECIMAL(5,4) (pembulatan 4 desimal terjaga saat dibaca/AVG) atau FLOAT ikut dev; `id_log` unsigned atau signed ikut dev |

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
   (mis. "01" = "1", "007" = "7", "1e1" = "10"); token lain dibandingkan sebagai string biasa. Bawaan ✔: ditiru (kunci peta
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
  disimpan di store fitur chatbot agar tidak hilang saat pindah halaman, dan dibersihkan saat muat ulang atau keluar (✔ [V2] UX;
  di dev percakapan hilang setiap pindah halaman karena halaman dimuat ulang). Pembersihan saat keluar/ganti akun dipanggil dari
  `clearSession()` di `frontend/src/features/auth/stores/auth.store.ts` (pola `resetSesiKonten()`; v1.2: `clearSession()` `:52-60`, `resetChatbot()` `:56`), mengosongkan pesan,
  status buka, dan token feedback, sehingga pengguna berikutnya di browser yang sama tidak melihat percakapan sebelumnya (BP8-15).
- **Avatar:** F8-45. Gerak dikurangi untuk `prefers-reduced-motion` (`dev:VP:201-204`).

#### 7.10.4 Admin Chatbot Manager (MAKE-094 API, MAKE-095 layar) [K dev `dev:CB:57-211`]

- **Kategori:** judul wajib ("Judul kategori tidak boleh kosong!"), `tampil_bubble` Ya/Tidak dengan petunjuk dev
  (`dev:V/form_kategori.php:41-50`), status 1/2 saat ubah; kolom tabel No, Kategori, Bubble, Status (`dev:V/list_kategori.php:39-46`).
- **Tag:** judul wajib, status 1/2; kolom No, Tag, Status; urut id menurun (`dev:LC:139`); ekspor F8-47.
- **QnA:** kategori wajib (status 1), tag multi (status 1; `shared/ui` di `241ef9e5` belum punya kontrol pilihan ganda —
  `UiSelect`/`UiCombobox` tunggal — sehingga bawaan ✔ = daftar `UiCheckbox` dengan pencarian, atau komponen baru lewat reviewer
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
`audit_logs` maupun log aplikasi (v1.2: sisi "tidak masuk log aplikasi" belum dikunci test di MAKE-094 dan ditambah lewat CR-133,
pelaksana usulan ReadOne, izin diajukan ke Horii sebelum paket dibuka, dengan logger uji); **AC-E10.20** widget: dapat dioperasikan keyboard (buka, pilih bubble, kirim, nilai, Escape),
ARIA benar, tidak dirender untuk role di luar `UL_PEGAWAI`, saat sesi wajib ganti password, atau sebelum gerbang rilis F8-50
terbuka; sesudah logout atau login akun lain store chatbot kosong (pesan, status buka, token) dan widget hilang bila role sesi baru
di luar `UL_PEGAWAI`; kartu sapaan tetap berfungsi (tanpa galat) bila `sessionStorage` melempar galat; **AC-E10.21** halaman admin `page-fill` + ⋮ dengan urutan dan label
baku, Hapus lewat `ConfirmDialog`; tidak ada `v-html` di luar `SafeHtml`, `<select>`/`window.confirm` native, atau `title` sebagai
tooltip; **AC-E10.22** QA Lapis 1 chatbot (reviewer) dibandingkan dengan acuan visual widget dev (PN8-8, K-11), butir: launcher +
kartu sapaan/peek, salam pembuka, bubble topik → bubble pertanyaan, "Topik"/"Populer", fallback + saran, penilaian 👍/👎 + "Coba
lagi", galat koneksi/429, layar sempit, `prefers-reduced-motion`, dan lima halaman admin (Kategori, Tag, QnA + impor,
Statistik, Riwayat); **AC-E10.23** regresi FAQ dan helpdesk tetap hijau (dijalankan kedua jalur chatbot di gate masing-masing dan
diperiksa reviewer di M7).

**AC admin & impor tambahan (v1.1, ✔ F8-40/41/48/49/46):**
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
isi; v1.2: di luar DoD Fase 8, H8-13 (b)): dengan CSV sintetis, hanya QnA status 1 dibawa; kategori/tag status 2 yang masih dipakai QnA aktif dibawa sebagai status 2;
QnA tanpa kategori, relasi yatim/ganda dilaporkan dan tidak dibawa; jawaban disanitasi dan baris yang berubah dilaporkan; satu
transaksi; perintah menolak berjalan bila tabel QnA sudah berisi; repo tidak memuat CSV/isi dev.

#### 7.10.7 Test

- **BE (MAKE-094):** unit test mesin murni dengan fixture pembanding di `backend/tests/_support/HaloSimpeg/Chatbot/` (nilai harapan
  dihasilkan sekali dari mesin dev di luar repo, toleransi 0,0001; kode dev tidak masuk repo; siapa dan bagaimana menjalankan mesin
  dev = pertanyaan §12); test fitur per endpoint (`extends Tests\Support\DatabaseTestCase`); test token (sah, akun lain, diubah,
  tidak terurai, kedaluwarsa, kunci kosong/pendek); test impor dengan berkas xlsx sintetis yang dibuat di dalam test; test impor isi
  dev C4 dengan CSV sintetis; test ekspor dan template; test throttle per kelompok; contoh respons JSON sintetis di
  `backend/tests/_support/HaloSimpeg/Chatbot/*.json` untuk fixture FE (disalin FE, §3).
- **Skenario minimum fixture pembanding (AC-E10.3, ✔):** (1) kecocokan biasa di atas ambang; (2) skor tepat 0,25; (3) huruf
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
- **Impor/cutover (v1.2, H8-6):** urutan: akun `pengguna` (UP-1) → master T12b (F8-21, MAKE-097: `id_user` disalin apa adanya,
  id yang tidak ada di `pengguna` v2 dibuang dan jumlahnya dicatat, K-M11/K-M12; **tanpa** tabel pemetaan id) → tiket & pesan
  (F-02) → berkas lampiran (path `attachments`, tetap ber-path legacy dan disajikan aplikasi lewat E-04, F8-17). **v1.3 (T-5, Q-A0-5):**
  salinan berkas lampiran tiket lama ke storage v2 bergantung pada baris `disiplin_ticket_msg` di `ManifestLampiran::KOLOM`
  (MAKE-097); runbook mencatat jumlah tiket NULL, irisan NULL + Open + sender yatim (B6), `attachments` ber-path anomali (B7), dan
  tiket Open di kategori tanpa petugas aktif.
- **Push bersama (v1.3, H8-14 (a)):** perubahan `Libraries/Push/*` + `PushPesanJob` = CR-137, aditif dan kompatibel mundur bagi
  pemakai `AntrePushPesan` lain (cuti, LKH/Konket).
- **Lonceng F-08 (v1.3, H8-15 (b)):** `NotifikasiPage.vue` menampilkan entri tiket dari `tiket_belum_dibaca` yang sudah ada; tidak ada
  perubahan kontrak `GET notifikasi/ringkasan` selain angka yang kini terisi (AC-E06.9).
- **Arsip F-10 (v1.2, H8-5 (a)):** alias publik `/berkas/` F-10 tidak dipersempit untuk subfolder `inbox`; Halo Simpeg tidak
  mengubah `Config\Arsip` maupun README-deploy §1l, dan tidak bergantung pada nilai saklar `arsip.penyajianBerkasLegacy`.
- **Privasi:** tidak ada pencatatan isi pesan di log aplikasi/queue; `audit_logs` menyimpan metadata + snapshot `before` hanya untuk hapus.
- **Chatbot ↔ helpdesk (E-10):** tidak berbagi tabel, service, atau route; chatbot tidak membuat tiket dan tidak menulis
  `disiplin_ticket(_msg)`, `inbox(_msg)`, atau push (K-9). Badge/ringkasan Halo Simpeg tidak menghitung apa pun dari chatbot.
- **Chatbot ↔ FAQ (G-10):** dua sumber konten terpisah; chatbot memakai ulang `HtmlSanitizer` dan `SafeHtml` FAQ tanpa mengubahnya;
  tidak ada sinkronisasi isi FAQ ↔ QnA [K dev].
- **Chatbot ↔ impor/cutover:** isi QnA dev hanya dibawa bila F8-27 menetapkan demikian, lewat langkah runbook terpisah dari impor
  helpdesk, dengan perintah impor sekali jalan yang membaca CSV di luar repo (F8-46); `log_chatbot` dev tidak dimigrasi (§1.2).
- **Komponen FE bersama (v1.4):** `shared/utils/galatKolom.ts` dan `shared/ui/UiPilihBanyak.vue` dipakai chatbot dan helpdesk
  (R10, CR-134 paket 1). Teks info paginasi `UiPagination` (`frontend/src/shared/ui/UiPagination.vue:42`) diganti ke Bahasa
  Indonesia "Menampilkan a sampai b dari n data" lewat CR-141 (Horii 17-11-2026, berjalan) dan berlaku untuk semua tabel.
- **Satker kepegawaian (v1.4, CR-139):** kandidat petugas Halo Simpeg dan PIC layanan membaca satker lewat satu method
  `WebConfigService::satkerKepegawaian()`.

## 9. Ketergantungan

| Dari | Ke | Catatan |
|---|---|---|
| PRD Fase 8 v1.0 di `main` | Semua koding | F8-01 |
| DBV-028 | MAKE-070 → MAKE-071 → MAKE-072/073 | skema dulu; MAKE-073 (FE) menunggu kontrak API MAKE-071 beku; v1.4: DBV-028 ✔ dan MAKE-070 ✔ di `main` |
| MAKE-070 (master) | MAKE-071 (tiket butuh kategori & petugas) | |
| MAKE-071 (M3) | MAKE-074 BE (ekspor + penutup BE) | v1.2: M6 BE cukup menunggu M3 (selaras `8_1.md` A5) |
| MAKE-074 BE + MAKE-073 (M5) | MAKE-074 FE (B4 tombol ekspor) | v1.2 |
| DBV-028 di `main` | MAKE-097 (T12b impor master) | v1.2, H8-12 |
| A0 disetujui | H8-14, H8-15, H8-16 diputus | v1.2; H8-14 sebelum MAKE-072, H8-16 sebelum MAKE-071 selesai, H8-15 sebelum B1/MAKE-071 binding lonceng. **v1.3: ✔ terpenuhi 16-11-2026** (A0 ✔, B0 ✔, H8-14 (a), H8-15 (b), H8-16 (a1)) |
| CR-136 (PRD v1.3) di `main` | README beku MAKE-070/071 | v1.3; README mengikuti §6.1b |
| MAKE-072 (branch) | CR-137 berkas bersama Push | v1.3; commit terpisah di branch MAKE-072 |
| DBV-028 di `main` | MAKE-097 + T-5 (`ManifestLampiran`) | v1.3; cutover lampiran tiket lama |
| Gladi impor sebelum cutover | Go-live | v1.4, DBV-028 Q8: Q-H19 + EXPLAIN daftar petugas; bila pemicu terpenuhi, kartu DBV `last_msg_at` di `main` sebelum go-live |
| Konfirmasi Biro SDM (H8-7) | Cutover | v1.4: bukan syarat QA; QA bagian B memakai satker kepegawaian demo |
| MAKE-066 (Fase 6 B5) | M4 push real | ✔ di `main` (`9debb366`) |
| DBV-027 (`firebase_token.platform`, `token_hash`) | M4 | dari Fase 6 |
| Fase 7 F-08 (lonceng) | Q8-06 = B | konsumen `ringkasan` |
| Fase 7 F-02 + UP-1 | Impor data tiket & master | bukan koding |
| PN8-1 / PN-3 | Uji push real, payload Android | sebelum deploy |
| PRD v1.1 di `main` (CR-121) | Koding E-10 dan DBV-033 | gerbang §0.1 |
| D1 chatbot (PN8-5) | DBV-033 | DDL acuan; bukan prasyarat PRD |
| DBV-033 | MAKE-094 → MAKE-095 | MAKE-095 menunggu kontrak API MAKE-094 beku (§6.5) |
| Keputusan isi K-5 (PN8-6) | C4 (perintah impor isi QnA) dan impor saat cutover | C4 = koding kecil sesudah C2; impor = cutover. v1.2: di luar DoD Fase 8 (H8-13 (b), DEPLOY-002) |
| Acuan visual widget dev (PN8-8) | QA Lapis 1 chatbot (AC-E10.22) | bukan koding |
| DBV-028 / MAKE-070..074 / MAKE-066 / F-08 | — | **tidak** ada ketergantungan dengan E-10 (dua arah) |

## 10. Urutan kerja (rincian per jalur: `8_1.md` BE, `8_2.md` FE)

| M | Isi | Key | Menunggu |
|---|---|---|---|
| M0 | Laporan pemahaman ⛔ reviewer (A0 BE Handling Case + B0 FE Worker Muse; wajib memuat kewajiban §6.1a, §7.3a, usulan H8-14/H8-15/H8-16, T-1..T-4, H8-10) | — | A0/B0 mulai sesudah CR-130 di `main`; laporan A0/B0 disetujui reviewer sesudah CR-131 (PRD v1.2) di `main`. **v1.3: ✔ A0 dan B0 disetujui 16-11-2026** |
| M1 | Skema pendukung (PR `[CR][DBV]`), E-01 | DBV-028 | M0 (v1.3: ▶ dibuka 16-11-2026; v1.4: ✔ di `main` `a5cb7300`) |
| M2 | Master kategori/petugas/kontak — **BE** (layar admin FE ada di M5), E-02/E-07 | MAKE-070 | M1 di `main` (v1.4: ✔ di `main` `56a700b1`) |
| M3 | Tiket, percakapan, tutup/hapus, lampiran, ringkasan BE + binding `RingkasanTiketInterface` + §7.8 (5), E-03/E-04/E-05 | MAKE-071 | M2 (v1.4: ▶ berjalan) |
| M4 | Notifikasi push + penerima + job, E-06 (v1.3: + CR-137 berkas bersama Push, commit terpisah) | MAKE-072 (+ CR-137) | M3; MAKE-066 ✔ di `main`; H8-14 (✔ (a), v1.3) |
| M5 | Frontend pemilik/petugas **dan layar admin** Kategori/Petugas/Kontak, E-08 | MAKE-073 | kontrak API M2 + M3 beku (v1.3: B1 bagian master boleh mulai sesudah MAKE-070 beku, `8_2.md` §2; v1.4: MAKE-070 ✔; ▶ B1 bagian master selesai review, masih di branch) |
| M6 | Ekspor rekap (BE) + tombol ekspor (FE kecil lewat Jalur FE), penutup (progres, README, runbook cutover), E-09 | MAKE-074 | v1.2: BE menunggu M3; tombol FE (B4) menunggu MAKE-074 BE + M5 |
| M6' | Impor master helpdesk T12b (salin `id_user` per H8-6); v1.3: + T-5 (Q-A0-5) `ManifestLampiran` | MAKE-097 | DBV-028 di `main` (v1.2, H8-12; v1.4: ▶ PR #42) |
| M7 | Reviewer: QA Lapis 1 Fase 8 (checklist CR-132; termasuk chatbot, AC-E10.22/23), laporan akhir `docs/fase8/Laporan_Akhir_Fase8.md` → ⛔ sign-off Horii (penutup seluruh development) | — | M4, M5, M6 (BE + B4 FE), M6', C3 (v1.2: **tidak** menunggu C4, H8-13 (b)) |
| M8 | Rilis menu chatbot + helpdesk bersama (H8-11 (a)): `ACTIVE_PHASE` 5 → 8, pembersihan `rilis`, item `halo` `to`/`roles` | CR-135 | sign-off M7; ⛔ izin Horii |

Sub-jalur **chatbot E-10** (paralel dengan M1–M6; pelaksana §3.1: BE ReadOne, FE Worker Muse, izin #8 selesai; C1–C3 ✔ di `main`):

| M | Isi | Key | Menunggu |
|---|---|---|---|
| C0 | Laporan pemahaman E-10 ⛔ reviewer (termasuk cek ulang rujukan `dev:`) | — | PRD v1.1 |
| C1 | Skema chatbot (PR `[DBV]`), DDL acuan dari D1 chatbot | DBV-033 | C0; D1 chatbot (PN8-5) |
| C2 | BE chatbot: mesin jawab + test pembanding, endpoint widget, CRUD admin (+ `PATCH …/status`), impor/template, ekspor tag, statistik, riwayat, throttle, token; kontrak API dibekukan di README `Controllers/Api/HaloSimpeg/Chatbot`; README `Libraries/HaloSimpeg/Chatbot` dan `Models/HaloSimpeg/Chatbot`; bagian chatbot di `backend/docs/progress/08-HaloSimpeg.md`; pemeriksaan kunci token di `README-deploy.md` | MAKE-094 | C1 di `main` |
| C3 | FE chatbot: widget Vanya + halaman admin Chatbot Manager + menu; README `features/halo-simpeg/chatbot` | MAKE-095 | kontrak API C2 beku |
| C4 | (bila F8-27 membawa isi dev) perintah impor sekali jalan isi QnA dari CSV di luar repo (F8-46, AC-E10.30) + langkah runbook cutover isi QnA. **v1.2: pasca Fase 8, di luar DoD (H8-13 (b)), dilacak DEPLOY-002; pelaksana ReadOne** | key ✔ F8-51 (MAKE tersendiri dari reviewer saat PN8-6) | C2 ✔; keputusan PN8-6 (saat deploy) |

Status sub-jalur chatbot (v1.2, `main` `603af133`): C1 ✔ DBV-033 `3fb8c5f7`, C2 ✔ MAKE-094 `4af0c188`, C3 ✔ MAKE-095
`2a8f405d`; sisa QA Lapis 1 (CR-132 bagian A), CR-133, CR-134 dst., dan rilis CR-135. Pelaksana CR-133 (ReadOne) dan CR-134+
(ReadOne BE, Worker Muse FE) = usulan; izin workload pekerjaan chatbot pasca-#8 diajukan ke Horii sebelum paket dibuka (◻).
**v1.4:** CR-132 ✔, CR-133 ✔, CR-134 paket 1 dan 2 ✔ di `main` dalam izin [#10-WorkloadQAChatbot]; QA Lapis 1 bagian A
putaran 1 selesai 17-11-2026; sisa rilis CR-135.

Setiap M: branch baru dari `origin/main` → gate cepat → push → CI `quality-gate` hijau → lapor → ⛔ merge oleh reviewer CR (izin Horii per paket).

## 11. Definition of Done & exit criteria

**Fase 8 DONE** bila: E-01..E-10 DONE (AC terpenuhi, di `main`; E-10 = DBV-033 + MAKE-094 + MAKE-095, AC-E10.1..29; AC-E10.30/C4
**tidak** termasuk, v1.2 H8-13 (b)); verifikasi "thread tidak ter-mix" (§7.8) hijau; regresi Fase 4
(notifikasi approval, C-05/C-06) dan FAQ (G-10) hijau; QA Lapis 1 Fase 8 lolos (checklist `docs/fase8/Checklist_QA_Lapis1_Fase8.md`, CR-132), termasuk widget dan Chatbot Manager; runbook cutover (impor master T12b MAKE-097 dengan `id_user` disalin apa adanya dan jumlah baris dibuang tercatat (H8-6, K-M11/K-M12), berkas
lampiran lama lewat E-04 dan risiko sadar H8-5 (a), kunci token chatbot README-deploy §1m; v1.3: salinan lampiran tiket lama lewat
T-5 (Q-A0-5), jumlah tiket NULL dan irisan NULL + Open + sender yatim, jumlah berkas lampiran ber-path anomali, tiket Open di kategori tanpa
petugas aktif) tercatat; fixture `backend/tests/_support/HaloSimpeg/` §6.1b lengkap dan terverifikasi trait (v1.3); laporan akhir `docs/fase8/Laporan_Akhir_Fase8.md` (status per paket + SHA, temuan QA, butir yang ditunda ke DEPLOY-002); **sign-off Horii sebagai penutup seluruh development**.
Kartu Trello Fase 8 (DEV-009, QASMTASK-111..118, QASMTASK-123, QASMTASK-124; §1.1) dan baris timeline **Notion** Fase 8 (E.1..E.4,
E.5 = E-09, E.6 = E-10) disinkron final (v1.2). **Di luar DoD, dilacak DEPLOY-002 (C4/K-5) dan MT-010/DEPLOY-001.1 (uji push
real):** uji push real (wajib lolos sebelum deploy, pola F6-23), keputusan K-5 dan C4 impor isi QnA dev (H8-13 (b)), dan langkah deploy lain (kunci §1m, T12b di server). **Sesudah
sign-off:** rilis menu bersama CR-135 (H8-11 (a), ⛔ izin Horii). **v1.4:** runbook juga mencatat hasil Q-H19 + EXPLAIN
daftar petugas pada gladi impor (DBV-028 Q8 (a)); bila pemicunya terpenuhi, kartu DBV `last_msg_at` wajib di `main` sebelum
go-live (Q8 (b)); konfirmasi satker kepegawaian oleh Biro SDM (H8-7) = butir cutover; uji push real (H8-8) di DEPLOY-001.1.

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
| Q8-13 | **Impor master** (`support_officer`, `officer_category`, `help_desk_contact`) dan **nama kolom** (`id_user` vs `nip` di Bahan Baku E-01) | **A** impor master dengan `id_user`→`id_pengguna` (pemetaan UP-1); skema ikut legacy. **B** buat ulang manual lewat UI saat cutover. **C** skema memakai `nip` seperti Bahan Baku | **A** (impor lurus; DQ-4) | **Ikut saran (Horii 03-11-2026).** **v1.2:** cara impor `id_user` diperjelas H8-6 (Horii 13-11-2026): disalin apa adanya tanpa tabel pemetaan UP-1, id tak dikenal dibuang + jumlahnya dicatat (K-M11/K-M12 MAKE-069); skema tetap `id_user` (opsi A) |
| Q8-14 | **Pembagian pelaksana**: satu coder BE→FE berurutan atau dua jalur paralel (BE: M1–M4, FE: M5 setelah kontrak API M3 beku)? Pelaksana jalur? | **A** satu jalur berurutan. **B** dua jalur paralel (pola Fase 6) | **B** bila ada dua pelaksana; kontrak API M3 dibekukan di PRD sebelum FE mulai | **Ikut saran (Horii 03-11-2026).** Pembagian jalur: §3, `8_1.md`, `8_2.md`. |
| Q8-15 | **Model percakapan**: desain Figma/mock = satu utas per pegawai (drawer); legacy = tiket per topik dengan kategori + subjek (data impor bertipe ini) | **A** ikut legacy: tiket per topik, tampilan chat per tiket (F8-02). **B** satu utas per pegawai (menyimpang; kategori/petugas dan impor perlu rancang ulang) | **A** | **Ikut saran (Horii 03-11-2026).** |

**Trigger `inbox_msg_afIns`** (D1:7730-7739) tetap berlabel [I] dan menjadi kewajiban M0; ekstraksi oleh DBA/Horii menyusul (dump D1 tidak ada di mesin reviewer).

**Register H8 (v1.2).** Keputusan pelaksanaan helpdesk sesudah PRD v1.1 (`docs/fase8/Kesiapan_Fase8.md` §6.1: H8-1, H8-2, H8-5, H8-6
dijawab Horii 13-11-2026, H8-4 terjawab lewat H8-1) dan keputusan Horii 16-11-2026 ("ikut rekomendasi semua": H8-3, H8-11, H8-12, H8-13). H8-7..H8-10 **tetap terbuka** dan dijawab Horii
sebelum paket terkait. H8-14..H8-16 **diputus sesudah A0** Handling Case (opsi + rekomendasi di bawah; keputusan yang mengubah
data atau mengurangi fungsi = Horii). **v1.3:** H8-14..H8-16 ✔ diputus Horii 16-11-2026 ("ikut rekomendasi semua"), H8-9 ✔ dijawab
Horii 16-11-2026, PN8-8 ✔ diterima; yang **masih terbuka**: H8-7 (sebelum QA), H8-8 (sebelum MAKE-072), H8-10 (sebelum MAKE-071;
tanpa ekstraksi tetap [I], tetapi penanda baca sisi pembalas petugas dan buka-detail sudah [K], F8-10). **v1.4 (Horii 17-11-2026):** H8-10 ✔ tetap [I]; H8-7 dan H8-8 dijawab "belum ada" dengan
perlakuan pengganti (baris di bawah); tidak ada lagi butir H8 yang menahan koding.

| ID | Butir | Opsi | Rekomendasi | Keputusan / status |
|---|---|---|---|---|
| H8-1 | Izin workload helpdesk dan anggotanya | — | — | ✔ Horii 13-11-2026: [#9-WorkloadHaloSimpeg]; BE Handling Case, DB Validator DBV-028 WS-2, FE menyusul (§3.1) |
| H8-2 | Kapan helpdesk mulai | (a) sekarang (b) sesudah MAKE-093 (c) sesudah F-10 sign-off | — | ✔ Horii 13-11-2026: (c). Terpenuhi: F-10 sign-off Horii 16-11-2026 pada `main` `fd9e8371`, dicatat CR-129 `603af133` |
| H8-3 | Pelaksana FE helpdesk (MAKE-073 + B4) | (a) Worker Muse (b) Worker Fix Bug (c) berurutan BE → FE | (a) | ✔ Horii 16-11-2026: **(a) Worker Muse**, amandemen izin #9 (anggota FE); cadangan dan penanggung perbaikan QA = Worker Fix Bug. Butir dirumuskan ulang 16-11-2026 (Kesiapan `:241`; rumusan semula di `603af133` `:207`: Q8-14 B tetap / A) |
| H8-4 | DB Validator DBV-028 | — | — | ✔ terjawab lewat H8-1 (WS-2) |
| H8-5 | Lampiran tiket lama `arsip/{nip}/inbox/…` vs alias publik `/berkas/` F-10 | (a) ikut legacy (b) subfolder `inbox` dikecualikan (c) diputus saat cutover | — | ✔ Horii 13-11-2026: **(a)**, risiko sadar (F8-17, BP8-5, R8-6); aplikasi tetap menyajikan lampiran lama lewat E-04 (addendum ADR-038 §8, README-deploy §1l) |
| H8-6 | Impor master T12b: pemetaan UP-1 (F8-21 v1.0) atau salin apa adanya (K-M11/K-M12) | (a) K-M11/K-M12 (b) F8-21 v1.0 | — | ✔ Horii 13-11-2026: **"seperti legacy saja"** = (a); F8-21, §8, R8-2, DoD, `8_1.md` A5 diselaraskan di v1.2 |
| H8-7 | Konfirmasi Biro SDM: satker `101` = satker kepegawaian (F8-07) | konfirmasi / tetap bersyarat | jawab sebelum QA (tidak menahan kode: kode memakai `id_satker_kepegawaian`) | ✔ Horii 17-11-2026 (v1.4): **belum ada** → QA bagian B memakai satker kepegawaian demo (Web Config) untuk kandidat petugas; butir QA yang bergantung pada H8-7 ditandai bersyarat; konfirmasi Biro SDM menjadi butir cutover (F8-07 tetap bersyarat) |
| H8-8 | Kontak pemilik app Android (PN8-1, kunci `data` push) | dapat kontak / payload terbatas dulu | jawab sebelum MAKE-072; tanpa jawaban MAKE-072 memakai payload terbatas dan uji push real dicatat ke DEPLOY | ✔ Horii 17-11-2026 (v1.4): **belum ada** → MAKE-072 memakai payload terbatas F8-16; uji push real (G8-7) dicatat ke DEPLOY-001.1 |
| H8-9 | Frame Figma helpdesk (PN8-4) | ada (serahkan) / ikut pola layar v2 | jawab sebelum B3; tanpa Figma AC-E08.7 diuji terhadap pola komponen redesign | ✔ Horii 16-11-2026 (v1.3): **frame Figma helpdesk belum ada** → daftar tiket petugas = master-detail ala Gambar 34, master Kategori/Petugas/Kontak = pola Master Data/PIC layanan (AGENTS.md:93-94), layar pemilik = Gambar 33; tiap layar tanpa frame dicatat; AC-E08.7 diuji terhadap pola redesign; gerbang B3 terpenuhi; PN8-4 ditutup |
| H8-10 | Ekstraksi trigger `inbox_msg_afIns` | ekstrak dari DB dev / tetap [I] | jawab sebelum MAKE-071; "tidak" boleh: F8-10 tetap [I] dan diuji per skenario AC-E04.4 | ✔ Horii 17-11-2026 (v1.4): **tetap [I]**; sisi lawan ke 2 dan sisi pembalas pemilik tetap [I], diuji per skenario AC-E04.4; gerbang MAKE-071 terpenuhi. v1.3: ekstraksi D1 tidak tersedia di mesin A0; penanda baca buka-detail dan sisi pembalas petugas sudah [K] (`LS:907-919`, `:1344-1347`), yang tetap [I] hanya sisi lawan = 2 dan sisi pembalas pemilik = 1 (F8-10, §6.2) |
| H8-11 | Gerbang rilis menu Halo Simpeg / chatbot | (a) dirilis bersama di penutup Fase 8 (pola F8-50) (b) chatbot dirilis lebih dulu lewat CR kecil | (a) | ✔ Horii 16-11-2026: **(a)**, paket rilis **CR-135** sesudah sign-off atas izin Horii (F8-50, M8) |
| H8-12 | Key dan agen T12b impor master | (a) bagian MAKE-074 oleh Handling Case (b) paket MAKE-069 lanjutan **MAKE-097** oleh ReadOne | (b) | ✔ Horii 16-11-2026: **(b) MAKE-097 oleh ReadOne**, review DB WS-2, mulai sesudah DBV-028 di `main`; amandemen scope izin #9 |
| H8-13 | C4 impor isi QnA dev vs sign-off | (a) C4 sebelum sign-off (b) C4 keluar dari DoD, dilacak DEPLOY-002 | (b) | ✔ Horii 16-11-2026: **(b)**; key C4 tetap per F8-51 saat PN8-6 |
| H8-14 | Penerima push petugas tanpa NIP (AC-E06.7; F8-13 vs `AntrePushPesan` per NIP, §6.4) | (a) penerima per `id_pengguna` lewat `push-pesan` + CR berkas bersama Push (token web per akun, token Android per NIP bila ada); (b) job Halo Simpeg tersendiri (rencana v1.0, `PushHaloSimpegJob`); (c) AC-E06.7 dipersempit ke "petugas ber-NIP" | **(a)**: memakai titik sambung MAKE-066 yang ada dan tidak menduplikasi job; (c) hanya bila Horii menerima pengurangan fungsi | ✔ Horii 16-11-2026 (v1.3): **(a)** `AntrePushPesan::antreAkun(id_pengguna[])` aditif; berkas Push bersama = **CR-137**, commit terpisah di branch MAKE-072, test regresi cuti/LKH/Konket (F8-13, §6.4) |
| H8-15 | Tujuan klik angka tiket di lonceng F-08 | (a) badge menu Halo Simpeg saja, lonceng hanya angka (pola berita F-07) (b) entri "Tiket Halo Simpeg (n)" di panel/halaman notifikasi yang menuju route Halo Simpeg (CR berkas bersama `features/notifikasi`) | **(b)**: angka bisa ditelusuri | ✔ Horii 16-11-2026 (v1.3): **(b)** entri "Tiket Halo Simpeg (n)" di `NotifikasiPage.vue` menuju route Halo Simpeg (tab antrean bila sisi petugas > 0); petugas role 4/5 (di luar `NOTIFIKASI_ROLES`) lewat badge menu; BE tanpa perubahan kontrak (F8-19, AC-E06.10) |
| H8-16 | Tiket impor berkategori NULL (AC-E04.11, AC-E05.8) | (a) hanya terlihat dan dapat ditutup/dihapus role 1 (b) dipetakan ke kategori penampung saat impor (c) ditutup otomatis saat cutover | **(a)** sebagai bawaan tanpa mengubah data; (b)/(c) mengubah data sehingga butuh keputusan Horii | ✔ Horii 16-11-2026 (v1.3): **(a1)** ikut legacy tanpa ubah data — opsi (a) "role 1" di kolom Opsi **gugur v1.3** (A0 menulis ulang opsinya: (a1) hanya pemilik, (a2) role 1 aktif ikut mengelola = perluasan akses isi percakapan, (b) kategori penampung, (c) tutup otomatis). Hanya pemilik melihat dan menutup; balas → 422 `tiket_tanpa_kategori`; hapus oleh pemilik → 403 `bukan_pihak_berwenang` (F8-09 [V2]); tidak ada antrean/perluasan role 1; tidak dihitung sisi petugas; tidak ikut ekspor; jumlah + irisan sender yatim dicatat runbook (§7.4, AC-E04.11, AC-E05.8) |
| PN8-8 | Acuan visual widget dev (AC-E10.22) | Horii menyerahkan tangkapan layar / reviewer merender ulang view dev di luar repo | serahkan sekarang | ✔ (v1.3) tangkapan layar widget chatbot dev diterima dari Horii 16-11-2026; disimpan di luar repo (memuat foto avatar), tidak di-commit; selisih dibahas di CR-132 bagian A / CR-134. **v1.4 — ✔ Horii 17-11-2026 "ikut rekomendasi":** (1) avatar tetap ilustrasi SVG, foto tidak dipakai (PN8-7); (2) aksen biru `brand-tertiary`, bukan pink dev; (3) saat memuat, tombol/chip disabled + indikator (perilaku v2); (4) launcher 64 px dan gaya nama "Vanya" tetap v2; (5) perbaikan [K] avatar mini rata atas tuntas di CR-134. Butir (1)–(4) = [V2] disengaja, bukan cacat QA (F8-33, README `features/halo-simpeg/chatbot`) |

**Butir turunan A0/B0 (v1.3).** Keputusan Horii 16-11-2026 ("ikut rekomendasi semua" atas opsi yang diajukan reviewer) dan
keputusan reviewer CR 16-11-2026 (wewenang reviewer: kontrak dan pemilik berkas).

| ID | Butir | Opsi | Rekomendasi | Keputusan |
|---|---|---|---|---|
| Q-A0-1 | Pembalas otomatis "sudah baca" di sisinya sendiri (trigger [I]) | ya / tidak | ya | Terjawab sebagian lewat fakta legacy: sisi pembalas petugas = 1 [K `LS:1344-1347`]; sisi pembalas pemilik = 1 tetap [I] (H8-10) (F8-10, §6.2) |
| Q-A0-2 (K-A4) | Hapus petugas | (1') hapus → 422 bila petugas terakhir (aktif/nonaktif) kategori ber-tiket Open, Nonaktifkan → 200 + peringatan; (2) ikut legacy selalu boleh + peringatan | (1') | ✔ Horii: **(1')** + `bisa_hapus`, `alasan_tidak_bisa_hapus` (§7.2, AC-E02.8) |
| Q-A0-3 | D1 struktur tiga tabel master dari salinan dev | minta / tidak | minta bila mudah | Tidak menahan DBV-028; tanpa D1 panjang kolom tetap [I] dan diputus DBV |
| Q-A0-4 | Sisi petugas mengecualikan semua tiket milik sendiri atau hanya yang `is_read=2` (`LN:13-21`, `:44`) | PRD / legacy | PRD | ✔ reviewer: ikut PRD §6.2 (semua tiket sendiri dikecualikan) |
| Q-A0-5 (T-5) | Pemilik baris `disiplin_ticket_msg` di `ManifestLampiran::KOLOM` | MAKE-097 / CR kecil | MAKE-097 | ✔ reviewer: **MAKE-097 (ReadOne)** + test + 2 kueri pra-cek + runbook; Handling Case tidak menyentuh berkas itu |
| Q-A0-6 | Hapus lampiran legacy | (a') `PenjagaBerkas::catat` + `selesaikan()` (hitung rujukan; anomali tertinggal tercatat); (b) CR adapter | (a') | ✔ Horii: **(a')** (F8-09, §7.5) |
| Q-A0-7 (K-A2) | Kontak aktif vs semua | dua path / parameter | dua path | ✔ reviewer: `GET kontak/aktif` (1–7, array) dan `GET kontak` (1, `Halaman<T>`); menggeser baris §6.1 |
| Q-A0-8 (K-A8) | Angka throttle, panjang, ekspor | — | angka A0 | ✔ Horii: §7.3a final |
| Q-A0-9 | F8-20 WIB↔UTC + audit unduhan AC-E09.7 | ikut usulan reviewer / tidak | ikut | ✔ Horii: disetujui; ekspor kosong tidak diaudit |
| D7 | Penyamaran nama petugas | (a) ikut legacy per layar; (b) diseragamkan, role 1 melihat nama di daftar [V2] | (a) dari B0; (b) dari A0 | ✔ Horii: **(a)** ikut legacy per layar, dihitung BE (§6.1b) |
| D8 | Angka badge menu | (a) `badge` = distinct(belum dibaca ∪ `perlu_tindak`), `total` tetap; (b) penanda kedua; (c) dibiarkan | (a) | ✔ Horii: **(a)** (F8-12, §6.2) |
| R9(1) | Bawaan filter baca tab "Tiket saya" | semua / belum | semua | ✔ Horii: **semua** [V2]; antrean tetap `belum` [K] (B-25) |
| R3 | Indikator badge saat sidebar ciut | (a) tidak perlu; (b) titik penanda | (b) | ✔ Horii: **(b)** CR kecil berkas bersama `AppSidebar.vue`, commit terpisah dalam #9 (MAKE-073) |
| R10 | Helper galat 422 + pilih banyak chatbot ke `shared/` | langkah 1 (aditif, helpdesk saja) + langkah 2 (migrasi chatbot) | keduanya | Langkah 1 dalam #9 (MAKE-073); ✔ Horii: **langkah 2 disetujui**, dikerjakan bersama paket perbaikan QA chatbot (CR-134 dst.), syarat test chatbot 83/83; duplikasi sementara = B-30. **v1.4 (keputusan reviewer 17-11-2026):** langkah 1 dipindah dari MAKE-073 (B1) ke CR-134 paket 1; langkah 1 dan 2 **selesai** (di `main` per `67f3303c`), B-30 tertutup |
| Konvensi | Nama field respons, ekspor kosong, filter baca, `urutan_pengirim`, `bisa_ekspor`, urutan, `ditutup_oleh_label`, `snapshot`, tata letak master-detail | — | — | ✔ reviewer 16-11-2026 (§6.1b, §6.3) |

**Keputusan 17-11-2026 (v1.4).** Horii (keputusan bisnis), reviewer CR (kontrak, pemilik berkas, urutan paket), dan DB
Validator WS-2 (skema). Keputusan Horii atas PN8-8, H8-7, H8-8, dan H8-10 dicatat di baris masing-masing pada register H8 di atas.

| ID | Butir | Opsi | Rekomendasi | Keputusan |
|---|---|---|---|---|
| S8 | PUT petugas yang membuat kategori ber-tiket Open kehilangan petugas aktif terakhir (MAKE-070) | (a) 422 seperti hapus; (b) simpan + `peringatan`, setara Nonaktifkan K-A4 (1') | — | ✔ Horii: **(b)** 200 + `peringatan`; hanya hapus permanen yang diblok (§7.2) |
| T2 | Pemilik tiket yang juga petugas aktif kategori itu menghapus tiketnya (MAKE-071) | (a) boleh sebagai petugas; (b) F8-09 harfiah: pemilik selalu 403 | — | ✔ Horii: **(b)** pemilik selalu 403 `bukan_pihak_berwenang`; petugas lain di kategori yang sama boleh (F8-09, AC-E05.11) |
| Izin #10 | Workload pekerjaan QA chatbot pasca-#8 (CR-133, CR-134 dst.) | — | — | ✔ Horii: **[#10-WorkloadQAChatbot]**; CR-133 oleh ReadOne; CR-134 dst. oleh Worker Muse (FE) dan ReadOne (BE) |
| CR-141 | Teks info paginasi bersama (`UiPagination`, "Showing … entries") | — | — | ✔ Horii: "Menampilkan a sampai b dari n data" untuk semua tabel; paket CR-141 berjalan (§8) |
| S1–S12 (selain S8) | Selisih README master MAKE-070 terhadap v1.3 | — | — | ✔ reviewer: diterima (§6.1b, README master) |
| T1–T13 (selain T2) | Keputusan MAKE-071 sebelum README tiket beku | — | — | ✔ reviewer (§6.1b) |
| W1 | Kunci transaksi MAKE-070 | — | — | ✔ reviewer: semua pembacaan sebelum kunci terakhir = locking read (§7.2); dipakai juga MAKE-071 (T7) |
| Path admin FE | Route layar master helpdesk | `/halo-simpeg/admin` / `/halo-simpeg-admin/:bagian` | — | ✔ reviewer: `/halo-simpeg-admin/:bagian`, supaya sesudah CR-135 tidak ada dua item sidebar yang aktif bersamaan (§6.3) |
| R10 langkah 1 | Paket pelaksana | MAKE-073 (B1) / CR-134 paket 1 | — | ✔ reviewer: CR-134 paket 1; selesai (§3) |
| DBV-028 Q8–Q10 | `last_msg_at`, kelas Q-H4, PK `officer_category.id` | — | — | ✔ DB Validator WS-2: Q8 tidak di DBV-028 dengan syarat (a)–(c), Q9 INFO, Q10 [I] (§7.1) |

**E-10 Chatbot "Vanya" (v1.1).** Pertanyaan K-1..K-11 dari dokumen kerja reviewer `Mapping_Chatbot_Fase8.md` §6 (12-11-2026). Syarat
pada K-5 tetap berlaku.

| ID | Pertanyaan | Opsi | Saran | Keputusan |
|---|---|---|---|---|
| K-1 | **Penempatan chatbot** | **a** task baru E-10 di PRD Fase 8 (v1.1). **b** fase/paket tersendiri sesudah Fase 8 | **a** (Fase 8 belum mulai koding) | **Ikut saran (Horii 12-11-2026).** F8-23; key revisi CR-121 |
| K-2 | **Nama & persona** | **a** tetap "Vanya" + avatar legacy dev. **b** nama netral "Chatbot SIMPEG" | **a** | **Ikut saran (Horii 12-11-2026).** F8-24 |
| K-3 | **Skema** | **a** DBV terpisah (DBV-033) agar DBV-028 tidak tertahan D1 chatbot. **b** digabung ke DBV-028 | **a** | **Ikut saran (Horii 12-11-2026).** F8-25 |
| K-4 | **Identitas di `log_chatbot`** | **a** anonim seperti legacy dev + kepemilikan feedback lewat token bertanda tangan per log. **b** simpan `id_pengguna` | **a** | **Ikut saran (Horii 12-11-2026).** F8-26; rincian token = bawaan ✔ F8-35 |
| K-5 | **Isi QnA awal** | **a** mulai kosong, diisi admin/impor Excel. **b** bawa isi QnA dari DB dev (D1) | bergantung D1: bila isi dev sudah dikurasi, **b** | **Ikut saran (Horii 12-11-2026).** F8-27. **Ditunda ke saat deploy** (Horii 12-11-2026, sesudah D1 lengkap; dicatat di Trello DEPLOY-002, PN8-6) |
| K-6 | **Jawaban berformat** | **a** HTML dibatasi whitelist seperti FAQ v2. **b** teks polos | **a** | **Ikut saran (Horii 12-11-2026).** F8-28, BP8-14 |
| K-7 | **Ekspor QnA** | **a** hanya ekspor tag seperti legacy dev. **b** tambah ekspor QnA | **a** | **Ikut saran (Horii 12-11-2026).** F8-29 |
| K-8 | **Siapa melihat widget** | **a** role 2/6/7 seperti legacy dev. **b** semua role yang login | **a** | **Ikut saran (Horii 12-11-2026).** F8-30 |
| K-9 | **Hubungan dengan Halo Simpeg** | **a** berdiri sendiri. **b** tombol "Tanya petugas" (buat tiket) bila tidak terjawab | **a**; **b** sebagai usulan lanjutan | **Ikut saran (Horii 12-11-2026).** F8-31; **b** dicatat di luar scope (§1.2) |
| K-10 | **Akses admin** | **a** role 1 saja. **b** role 1 + 3 | **a** | **Ikut saran (Horii 12-11-2026).** F8-32 |
| K-11 | **Desain** | **a** ikut tampilan widget legacy dev disesuaikan token v2, tanpa menunggu Figma. **b** tunggu frame Figma | **a** | **Ikut saran (Horii 12-11-2026).** F8-33 |
| K-12 | **Judul kategori/tag kembar** (D1: 2 kategori, 11 tag) | **a** dibiarkan seperti dev, tanpa judul unik. **b** digabung saat impor, lalu judul dibuat unik | **b** (impor Excel mencocokkan menurut judul) | **Ikut saran (Horii 12-11-2026).** F8-40, F8-46 |
| K-13 | **Kategori uji & kategori kosong** dari dev | **a** dibawa apa adanya. **b** kategori uji/nonaktif tidak dibawa; kategori aktif kosong tetap dibawa | **b** | **Ikut saran (Horii 12-11-2026).** F8-46 |

**Bawaan teknis E-10 — DIKONFIRMASI Horii 12-11-2026 ("setuju semua"), termasuk bawaan kecil tanpa nomor di §6.5 dan §7.10.** Rincian di §5.2.

| ID | Pertanyaan | Bawaan (✔ dikonfirmasi 12-11-2026) |
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
| F8-50 | **Gerbang rilis** menu Chatbot Manager dan widget selama `ACTIVE_PHASE` = 5; siapa yang menaikkan `ACTIVE_PHASE`/menghapus `rilis`? | Pola lonceng F-08: menu tanpa `rilis`, widget dijaga `ACTIVE_PHASE >= 8`; yang menaikkan `ACTIVE_PHASE` = keputusan Horii/reviewer di penutup rilis (di luar MAKE-095). v1.2: ✔ H8-11 (a), CR-135 |
| F8-51 | Key milestone C4 (impor isi QnA dev)? | Key MAKE tersendiri dari reviewer saat PN8-6 diputus; atau bagian akhir MAKE-094 bila PN8-6 diputus sebelum C2 merge (v1.2: alternatif MAKE-094 gugur; C4 di luar DoD, H8-13 (b)) |

Prinsip umum: bila coder menemukan perilaku legacy yang belum diputus, default = ikut legacy [K]; deviasi hanya untuk alasan
teknis wajib/keamanan dasar dan **wajib** dicatat di laporan milestone + `backend/docs/progress/08-HaloSimpeg.md`.

## 13. Risiko

| ID | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R8-1 | Payload push tidak cocok dengan app Android (kunci `data`) | Notifikasi Android tidak membuka tiket | PN8-1 / Q8-08 sebelum M4; test kontrak payload |
| R8-2 | `id_user`/`sender`/`msg_sender` legacy tidak cocok dengan `id_pengguna` v2 saat impor (v1.2: id disalin apa adanya, H8-6) | Tiket yatim, petugas tak terhubung | v1.2: `id_user` master disalin apa adanya (K-M11); id yang tidak ada di `pengguna` v2 dibuang dan jumlahnya dicatat di laporan impor MAKE-097 (K-M12, F8-21), lalu diperiksa reviewer sebelum cutover; audit yatim sebelum FK `nip` |
| R8-3 | F-02 (sortir `inbox`) belum selesai | Data lama tidak bisa diimpor | Tidak menahan koding; menahan cutover (PN8-2) |
| R8-4 | Logika baca-otomatis yang dulu di trigger `inbox_msg_afIns` ditebak keliru | Badge belum-dibaca salah | M0 mencocokkan dengan D1:7730-7739 (via reviewer); test per skenario (AC-E04.4). v1.3: sebagian sudah [K] dari kode legacy (F8-10, §6.2); sisa [I] hanya sisi lawan = 2 dan sisi pembalas pemilik = 1 |
| R8-5 | Pesan impor berisi backslash hasil `addslashes` (`LS:1576`) | Teks tampil dengan `\'` | Keputusan normalisasi di F-02/impor; FE tidak men-strip |
| R8-6 | Isi percakapan = data pribadi; kebocoran lewat lampiran/URL/log | Pelanggaran privasi | BP8-2/BP8-5, endpoint unduh ber-otorisasi, fixture sintetis, payload minimal. **v1.2 — risiko sadar H8-5 (a) (Horii 13-11-2026):** lampiran tiket **lama** ber-path `arsip/{nip}/inbox/…` tetap dapat diunduh tanpa login lewat alias publik `/berkas/` F-10 seperti legacy (F10-18, README-deploy §1l); diterima Horii, tidak dimitigasi di Fase 8. Aplikasi tidak menautkan `/berkas/` (lampiran lama dan baru lewat E-04, AC-E04.7, AC-E08.9); unggahan v2 tetap privat; mempersempit alias kelak = keputusan Horii lewat QF10-2 / saklar `arsip.penyajianBerkasLegacy` (`backend/app/Config/Arsip.php:12-17`) |
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
| R8-18 | (v1.2) Petugas role 1 tanpa NIP tidak menerima push karena titik sambung push mencari token per NIP (§6.4, F8-13) | AC-E06.7 gagal; petugas admin tidak tahu ada tiket baru | H8-14 diputus sebelum MAKE-072; badge menu + lonceng (AC-E06.9) tetap memberi tahu di web. v1.3: ✔ H8-14 (a), penerima per `id_pengguna` lewat CR-137 (§6.4); sisa risiko = regresi pemakai `AntrePushPesan` lain, dimitigasi test regresi cuti/LKH/Konket |
| R8-19 | (v1.2) Hapus tiket impor berlampiran legacy gagal di `LocalStorageAdapter::hapus()` atau meninggalkan berkas | Hapus tiket galat atau berkas yatim | AC-E05.7; perlakuan diputus di A0 (CR adapter atau tidak dihapus atas konfirmasi Horii). v1.3: ✔ Q-A0-6 (a') `PenjagaBerkas` (hitung rujukan, anomali tertinggal tercatat); jumlah anomali diukur pra-cek B7; lampiran lama baru tersedia di storage v2 sesudah T-5 (Q-A0-5, MAKE-097) |
| R8-20 | (v1.2) Tiket impor berkategori NULL tidak punya pemilik antrean | Tiket Open menggantung, melanggar exit criteria E-05 | H8-16, AC-E04.11, AC-E05.8; dicatat di runbook MAKE-074. v1.3: ✔ H8-16 (a1): tiket NULL dapat ditutup pemiliknya; tiket NULL Open berpengirim yatim tetap menggantung seperti legacy, dihitung kueri B6, dan bila > 0 Horii dapat memilih tutup otomatis khusus irisan itu saat cutover |
| R8-21 | (v1.3) Identitas petugas (nama/id akun) terbuka ke pemilik lewat respons API | Pelanggaran aturan penyamaran legacy dan privasi petugas | Penyamaran dihitung BE (D7 (a)); `msg_sender`/`closed_by` mentah tidak dikirim ke non-role-1; `urutan_pengirim` tanpa id; fixture 4 kasus + pasangan role 1 (AC-E04.12) |
| R8-22 | (v1.3) Ekspor periode panjang dengan pesan impor sangat panjang menghabiskan memori | Ekspor gagal atau server lambat | Batas baris 10.000 + batas ukuran `SUM(CHAR_LENGTH(msg))` (ambang diukur di test MAKE-074) + potong sel 32.767 (AC-E09.8) |
| R8-23 | (v1.3) Duplikasi sementara helper galat/pilih banyak chatbot vs `shared/` (R10) | Perbaikan di satu versi tidak ikut di versi lain | Langkah 2 di paket QA chatbot (CR-134 dst.) dengan syarat test chatbot 83/83; dicatat di README `features/halo-simpeg` dan laporan B1 (B-30). v1.4: ✔ **tertutup**, langkah 1 dan 2 selesai di CR-134 paket 1 (versi `chatbot/` dihapus) |
| R8-24 | (v1.3) README beku MAKE-070/071 menyimpang dari §6.1b | FE membangun terhadap bentuk yang salah | README = sumber beku final; selisih diselesaikan lewat reviewer sebelum beku; fixture diverifikasi trait terhadap respons nyata. v1.4: README master beku dengan selisih S1–S12 yang diterima reviewer (§6.1b); README tiket beku di branch MAKE-071 |
| R8-25 | (v1.4) Daftar petugas lambat pada volume nyata (subkueri pesan terakhir tanpa `last_msg_at`) | Antrean petugas lambat sesudah cutover | DBV-028 Q8: Q-H19 + EXPLAIN pada gladi impor; bila Q-H19 > 5.000 atau daftar petugas > 100 ms, kartu DBV `last_msg_at` di `main` sebelum go-live; test EXPLAIN MAKE-071 dengan ambang sama; saran DBV untuk MAKE-071: bawaan daftar petugas = filter Open |

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
| B-11 | UI hanya petugas yang melihat tombol tutup/hapus, controller mengizinkan pemilik juga | `VI:133-142` vs `SC:676-690`, `SC:719-733` | Tutup: keduanya (F8-08); hapus: petugas saja (F8-09); v1.3: v2 menampilkan Tutup bagi pemilik di ⋮ menurut `bisa_tutup` (B-19, B-26) |
| B-12 | Hapus kategori/petugas tanpa cek ketergantungan | `LS:363-365`, `LS:709-711` | FK RESTRICT + pesan 422 (E-02) |
| B-13 | Popup login hanya muncul untuk petugas ber-role 2/6; petugas role 1 tidak diingatkan | `MC:271` | F8-12 |
| B-14 | `support_officer.email` wajib di form tetapi tidak ditemukan dipakai mengirim surel di berkas yang dibaca | `LS:756` [I] | Dipertahankan sebagai data kontak |
| B-15 | Cabang debug berkode-keras NIP di controller | `SC:469-471` | Tidak dibawa (F8-22) |
| B-16 | Unik nomor kontak/petugas hanya di aplikasi (kondisi balapan) | `LS:176-199`, `LS:765-788` | UNIQUE di DB (F8-03) |
| B-17 | Ekspor hanya memuat tiket yang pernah dijawab petugas yang sama dalam periode | `LS:1603-1609` | F8-20 (diperluas) |
| B-18 | Antrean push dihapus sebelum dikirim → pesan hilang bila gagal | `CR:1013-1020` | Fase 6 (queue + retry); BP8-6 |
| B-19 | (v1.3) Pemilik yang tercatat sebagai petugas kategori **lain** ditolak membalas, menutup, dan menghapus tiketnya sendiri (controller hanya memeriksa `officer_category` bila akun itu petugas) | `SC:571-579`, `:676-684`, `:719-727` | [V2]: pemilik selalu boleh balas dan tutup (F8-08); hapus tetap hanya petugas (F8-09); test balas/tutup |
| B-20 | (v1.3) Daftar menyembunyikan tiket tanpa pesan | `LS:868` | [V2]: ditampilkan di akhir dengan `pesan_terakhir: null` agar tidak ada tiket Open tersembunyi (AC-E04.15) |
| B-21 | (v1.3) Detail memakai INNER JOIN `question_category`, sehingga tiket berkategori NULL tidak bisa dibuka | `LS:897-901` | [V2]: pemilik dapat membuka, menandai baca, dan menutup; balas 422 (H8-16 (a1)) |
| B-22 | (v1.3) Penyamaran nama petugas berbeda per layar: daftar "Admin" bagi semua peninjau bila pengirim pesan terakhir bukan pemilik; detail "Admin" hanya bagi peninjau bukan role 1 | `VI:110-117`; `VD:48-55`, `views/template/modal/pic_halo_simpeg.php:42-47` | [K] dipertahankan per layar (D7 (a), Horii 16-11-2026), dihitung BE; perbedaan dua aturan dicatat sebagai perilaku legacy yang disengaja; id akun petugas tidak dikirim ke non-role-1 [V2] |
| B-23 | (v1.3) Penomoran pengirim (warna gelembung) relatif terhadap peninjau | `VD:5-14`, `:44` | [V2] kecil: `urutan_pengirim` per tiket relatif pemilik (0 = pemilik, petugas 1..n menurut kemunculan pertama) |
| B-24 | (v1.3) Tombol ekspor hanya tampil bagi petugas dengan lebih dari satu kategori | `VI:13-14` | [V2]: `bisa_ekspor` = petugas aktif berapa pun kategorinya (F8-20, AC-E09.1) |
| B-25 | (v1.3) Filter "Belum Dibaca" tercentang bawaan di daftar | `VI:51`, `:198` | Antrean petugas [K] bawaan `belum`; tab "Tiket saya" bawaan `semua` [V2] (Horii 16-11-2026) |
| B-26 | (v1.3) Pemilik bukan petugas boleh menghapus tiketnya di controller (UI tidak menampilkan) | `SC:728-733` | [V2] (F8-09): pemilik → 403 `bukan_pihak_berwenang`, termasuk tiket NULL (tiket NULL hanya dapat ditutup); v1.4 (T2 (b)): juga pemilik yang petugas aktif kategori tiket itu |
| B-27 | (v1.3) Pesan legacy tanpa batas panjang (TEXT), melebihi batas sel Excel 32.767 karakter | `LS:1505-1526`; `CreateDisiplinTicketFase4.php:114` | [V2]: sel ekspor dipotong 32.767 dengan penanda "… [dipotong]" sebagai pengecualian AC-E09.8 ("tidak terpotong" = baris) |
| B-28 | (v1.3) Nama bergelar pengirim pesan hanya untuk `UserLevel` 2/6 | `LS:1580-1588` | [V2] kecil: semua akun ber-NIP role apa pun (§7.4) |
| B-29 | (v1.3) Nama berkas lampiran tidak unik per pesan (`lampiran_{id_qc}_{YmdHis}`), path dari respons server arsip; NIP kosong menghasilkan `arsip//inbox/…` | `LS:979-981`, `:988`, `:1004`, `:1159-1161` | Hapus lewat `PenjagaBerkas` (hitung rujukan; path anomali tertinggal tercatat, Q-A0-6 (a')); jumlah diukur pra-cek B7 |
| B-30 | (v1.3) **Deviasi sementara v2 (bukan anomali legacy):** helper galat 422 dan komponen pilih banyak ada dua salinan (`features/halo-simpeg/chatbot/galat.ts` + `chatbot/components/admin/TagPilihan.vue` vs `shared/utils/galatKolom.ts` + `shared/ui/UiPilihBanyak.vue`) | R10 langkah 1 | Dicatat di README `features/halo-simpeg` dan laporan B1; dihapus di R10 langkah 2 (paket QA chatbot CR-134 dst., test chatbot 83/83). v1.4: ✔ selesai di CR-134 paket 1 (`629d4387`): versi `chatbot/` dihapus, chatbot memakai `shared/` |
| B-31 | (v1.3) Tombol ekspor Excel/CSV bawaan DataTables di list master (kategori, petugas, kontak) | `views/hr/support/categories/list.php:143-150` dan setara | Tidak dibawa (tidak ada di §6.1); endpoint baru bila Horii menghendaki |

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
| Route file modul | Daftar `$routeFiles` | `backend/app/Config/Routing.php:34-61` (v1.2, `603af133`) |
| Layout & menu | `RedesignShell.vue` (titik pasang widget); item `halo` Fase 8 di grup "Pusat Bantuan"; `ACTIVE_PHASE` = 5 dan semantik `rilis` | `frontend/src/shared/layouts/RedesignShell.vue:189` (widget `:199`), `frontend/src/shared/layouts/nav.config.ts:320`, `:97`, `:122-127` (v1.2, `603af133`) |
| Syarat render saat wajib ganti password; gerbang fase | `NotifikasiPushShell v-if="!auth.mustChangePassword"`; lonceng `ACTIVE_PHASE >= FASE_LONCENG`; filter JWT menolak route tanpa `jwt:ganti_password` | `frontend/src/shared/layouts/RedesignShell.vue:187`, `:117-119` (v1.2, `603af133`); `backend/app/Filters/JwtAuthFilter.php:49` |
| Ubah status terpisah | `PATCH …/(:segment)/status` | `backend/app/Config/Routes.php:45`, `:71`; `backend/app/Config/RoutesKonten.php:60`, `:70`; `backend/app/Config/RoutesDashboard.php:51` |
| Route FE per fitur; reset store saat keluar | import `routes.*` per fitur; `clearSession()` memanggil `resetSesiKonten()` dan `resetChatbot()` | `frontend/src/router/index.ts:13-27`; `frontend/src/features/auth/stores/auth.store.ts:52-60` (v1.2, `603af133`) |
| UI | `UiStatTile`, `UiTooltip`, `UiFileField`, `UiExportMenu`, `UiCheckbox`, `UiSearchInput`, `UiPagination`, `UiBadge` | `frontend/src/shared/ui/` |

## Lampiran D — Riwayat versi

| Versi | Tanggal | Perubahan |
|---|---|---|
| v0.1 | 03-11-2026 | Draf awal dari riset kode legacy (`0cf7e67`: `L_support`, `Support`, `MY_Controller`, `L_notification`, `Cron`, view support) dan v2 (`f7b9221e`): usulan F8-01..F8-22, pertanyaan Q8-01..Q8-15, peta legacy → v2, anomali B-1..B-18, risiko R8-1..R8-9, milestone M0..M7. Tidak ada kode dan tidak ada migration |
| v1.0 | 03-11-2026 | Keputusan Horii 03-11-2026: Q8-01..Q8-15 semua ikut saran (F8-xx menjadi ✔; syarat Q8-06, Q8-08, Q8-11 tetap dicatat); key final DBV-028 dan MAKE-070..074 (MAKE-069 dipakai paket impor cutover tahap 2); dua jalur paralel BE/FE (§3, `8_1.md`, `8_2.md`); trigger `inbox_msg_afIns` tetap [I] + kewajiban M0 |
| v1.1 | 12-11-2026 | **CR-121.** Tambah **E-10 Chatbot "Vanya"** dari bahan legacy dev (rujukan `dev:`, label [K dev]) atas keputusan Horii 12-11-2026 K-1..K-11 (semua ikut saran; K-5 bersyarat D1): header, gerbang E-10 (§0.1), PN8-5..PN8-7, scope (§1.1) dan di luar scope (§1.2, baris chatbot lama diganti), BP8-14/15, jalur chatbot (§3), glosarium, keputusan F8-23..F8-33 (✔) dan bawaan teknis F8-34..F8-47 (✔), FE (§6.3), endpoint (§6.5), spesifikasi §7.10 (AC-E10.1..23), lintas modul, ketergantungan, sub-jalur C0..C4, DoD, register K-1..K-11 + pertanyaan terbuka, risiko R8-10..R8-16, Lampiran A.2a, B.2 (A1..A16), C (komponen E-10). Key DBV-033, MAKE-094, MAKE-095. Rujukan v2 E-10 dicek di `241ef9e5`. Penyuntingan hasil review v1.1 (masih 12-11-2026): hasil D1 chatbot blok [1]–[3] dipakai (§7.10.1 [K dev D1], PN8-5, R8-14), PN8-8 acuan visual, bawaan baru F8-48..F8-51 (+ F8-34a/b, F8-46a di §12), `PATCH …/status`, syarat render widget (wajib ganti password, gerbang rilis), semantik tokenisasi (token "0", kesetaraan numerik), CSV isi dev di luar repo, keunikan judul tanpa UNIQUE biasa, AC-E10.24..30 dan perluasan AC-E10.2/3/8/10/11/15/17/18/20/22, pembagian berkas chatbot (§3), R8-17. Tidak ada kode dan tidak ada migration |
| v1.1 (lanjutan) | 12-11-2026 | Hasil D1 chatbot blok [1]–[6] dicatat (PN8-5); keputusan Horii 12-11-2026: K-5 ditunda ke saat deploy (PN8-6, F8-27, Trello DEPLOY-002), K-12 judul kembar digabung saat impor + judul unik (F8-40 ✔), K-13 kategori uji/nonaktif tidak dibawa, kategori aktif kosong dibawa (F8-46); F8-46a gugur (0 QnA tanpa kategori) |
| v1.1 (konfirmasi) | 12-11-2026 | Horii: "setuju semua" → bawaan teknis E-10 F8-34..F8-51 dan bawaan kecil tanpa nomor (§6.5, §7.10) dikonfirmasi (◻ → ✔) |
| v1.2 | 16-11-2026 | **CR-131.** Keputusan Horii 13-11-2026 (H8-1, H8-2, H8-5, H8-6; H8-4 terjawab lewat H8-1) dan 16-11-2026 ("ikut rekomendasi semua": H8-3 FE helpdesk = Worker Muse; H8-11 (a) rilis bersama CR-135; H8-12 T12b = MAKE-097 ReadOne + review DB WS-2; H8-13 (b) C4 keluar dari DoD → DEPLOY-002) dimasukkan: header (Trello, key CR-130..135 dan MAKE-097, "CR-095 dst." gugur), gerbang helpdesk terbuka dan status E-10 di `main` dengan SHA (§0.1), kolom kartu Trello (§1.1), di luar scope UAT/BAST, cutover produksi + rollback, hapus kolom hash legacy, C4 (§1.2), BP8-5/F8-17/R8-6 risiko sadar H8-5 (a) dengan lampiran lama tetap lewat E-04, F8-21/§8/R8-2/Q8-13/DoD/`8_1.md` A5 mengikuti H8-6 (K-M11/K-M12), tabel paket × key × agen × izin (§3.1), berkas bersama baru (`KontrakMatriksRoleTest`, reset store helpdesk di `clearSession()`, `features/notifikasi` dan berkas Push bersyarat), kontradiksi push per NIP vs petugas role 1 (F8-13, §6.4, H8-14), binding `RingkasanTiketInterface` (F8-19, AC-E06.9), kewajiban A0 kontrak (§6.1a K-A1..K-A8) dan angka (§7.3a, [usulan] hanya bila ada sumber legacy), AC baru AC-E02.7..9, AC-E04.11, AC-E05.7/8, AC-E06.9/10, AC-E08.8/9, AC-E09.7/8 (+ AC-E09.2 WIB↔UTC), ketergantungan M6/B4 dan §7.8 (5) ke MAKE-071, DoD (C4 keluar, Notion, checklist CR-132), M6'/M8 di §10, register H8 (H8-14..H8-16 terbuka sesudah A0; H8-7..H8-10 tetap terbuka), R8-18..R8-20, rujukan baris basi (`nav.config.ts`, `Routing.php`, `RedesignShell.vue`, `router/index.ts`, `auth.store.ts`) dicek ulang di `603af133`. Penyelarasan hasil verifikasi (masih 16-11-2026): syarat mulai A0/B0 seragam (§0.1, §3.1, §10 M0, `8_1.md` A0, `8_2.md` B0), M7 menunggu M4/M5, kartu uji push real MT-010/DEPLOY-001.1, pelaksana CR-133/CR-134+ = usulan (izin pasca-#8 ◻), WIB↔UTC dan AC-E09.7 = usulan menunggu konfirmasi Horii, SHA sign-off F-10 `fd9e8371`, addendum ADR-038 dan catatan README-deploy §1l untuk lampiran tiket lama. Tidak ada kode dan tidak ada migration |
| v1.3 | 16-11-2026 | **CR-136.** Hasil laporan A0 (Handling Case, disetujui reviewer 16-11-2026, dua putaran revisi) dan laporan B0 (Worker Muse, disetujui final 16-11-2026) dimasukkan, beserta keputusan Horii 16-11-2026 ("ikut rekomendasi semua"): H8-14 (a) `antreAkun` + CR-137 berkas bersama Push; H8-15 (b) entri `NotifikasiPage` (role 4/5 lewat badge menu); H8-16 (a1) tiket NULL ikut legacy tanpa ubah data; K-A4 (1') hapus petugas terakhir 422 + Nonaktifkan dengan peringatan; Q-A0-6 (a') hapus lampiran lewat `PenjagaBerkas`; angka Q-A0-8 (§7.3a final); penyamaran D7 (a); badge D8 (a); bawaan tab "Tiket saya" `semua`; titik penanda sidebar ciut; R10 langkah 1 (dalam #9) dan langkah 2 (paket QA chatbot, test 83/83); Q-A0-9 WIB↔UTC + audit unduhan; H8-9 (frame helpdesk belum ada, PN8-4 ditutup); PN8-8 (tangkapan layar widget dev diterima). Keputusan reviewer CR 16-11-2026: Q-A0-4, Q-A0-5 (T-5 di MAKE-097), Q-A0-7 (kontak dua path), konvensi nama respons, ekspor kosong 200 JSON, filter `baca`, `urutan_pengirim`, `bisa_ekspor`, `ditutup_oleh_label`, `snapshot`, urutan + pemecah seri, tata letak master-detail tanpa pengecualian AGENTS.md. Bagian yang diubah: header (versi, basis `ef83356e`, key CR-136/CR-137, sumber), §0.1 status A0/B0/A1, §0.2 PN8-4/PN8-8, §3 (BE-3, FE, tabel berkas bersama final, ManifestLampiran milik MAKE-097, adapter tidak diubah), §3.1 (status, CR-136, CR-137, T-5, sub-langkah MAKE-073), §5.2 (F8-06, F8-08..F8-13, F8-17..F8-21), §6.1 (kontak dua path, status, `petugas/saya`, parameter tiket, pencocokan Matriks), §6.1a (terpenuhi), §6.1b baru (kontrak + galat + fixture), §6.2 (aturan hitung, `badge`, penanda baca E7), §6.3 (FE final), §6.4 (H8-14 (a)), §7.1 (indeks, KEY FK redundan, pra-cek), §7.2 (K-A4 (1'), AC-E02.8/9, AC-E07.1), §7.3/§7.3a (angka final), §7.4 (AC-E04.4/10/11, AC-E04.12..15 baru), §7.5 (`PenjagaBerkas`, AC-E05.5/7/8, AC-E05.9/10 baru), §7.6 (AC-E06.7/9/10), §7.7 (AC-E08.7, AC-E08.10..16 baru), §7.9 (AC-E09.1..8), §8, §9, §10, §11, §12 (register H8 + butir turunan A0/B0), §13 (R8-4, R8-18..R8-20, R8-21..R8-24 baru), Lampiran B (B-11, B-19..B-31). Sembilan usulan CR-136 dari laporan A0 dimasukkan (potong sel 32.767, ekspor kosong tidak diaudit, pemilik tiket NULL 403, urutan, `urutan_pengirim`, kontak dua path + `petugas/saya` + `bisa_ekspor`, penanda baca, indeks DBV-028, fixture `8_1.md` A3). Rumusan yang gugur ditandai "(gugur v1.3)". Tidak ada kode dan tidak ada migration |
| v1.4 | 17-11-2026 | **CR-138.** Ralat status paket sesudah DBV-028 (PR #41, `a5cb7300`), MAKE-070 (`56a700b1`), CR-132 (`67f3303c`), CR-133 (`19a11ed5`), CR-134 paket 1 (di `main` per `67f3303c`) dan paket 2 (`d659acf6`), serta CR-139 (`ccb90f78`) masuk `main`; MAKE-071, MAKE-097 (PR #42), dan MAKE-073 B1 berjalan. Keputusan Horii 17-11-2026: PN8-8 rinci "ikut rekomendasi" (F8-33, PN8-7), H8-10 tetap [I] (F8-10), izin [#10-WorkloadQAChatbot], S8 (b), T2 (b) (F8-09, AC-E05.11 baru), H8-8 belum ada (payload terbatas, DEPLOY-001.1), H8-7 belum ada (satker demo saat QA, konfirmasi = butir cutover), teks paginasi CR-141. Keputusan reviewer CR 17-11-2026: selisih README master S1–S12 dan keputusan MAKE-071 T1–T13 diringkas di §6.1b (README = sumber beku), kunci transaksi W1 (§7.2), path admin FE `/halo-simpeg-admin/:bagian` (§6.3), R10 langkah 1 dari MAKE-073 ke CR-134 paket 1 (§3, B-30, R8-23 tertutup). Keputusan DB Validator DBV-028: Q4, Q6, Q8 dengan syarat (a)–(c), Q9, Q10 (§7.1, §9, §11, R8-25 baru). Ralat label §7.1: `updated_at` [V2], `created_at` legacy [I] + penghapusan DEFAULT [V2], PK `officer_category.id` [I], aksi FK legacy [I], tipe status legacy [I]. Key CR-138..CR-141. Baris Versi di header diringkas (rincian versi lama di lampiran ini). Rujukan baru dicek di `d659acf6`. Tidak ada kode dan tidak ada migration |
