# Progress Fase 8 — Modul E: Halo Simpeg

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak: `docs/fase8/PRD_Fase8_HaloSimpeg.md` (v1.1; v1.3 CR-136 untuk helpdesk), rincian jalur
`docs/fase8/8_1.md` (BE) dan `8_2.md` (FE). Berkas ini dipakai bersama jalur helpdesk dan jalur chatbot: tiap jalur menambah
bagiannya sendiri secara aditif.

## E-10 Chatbot "Vanya" — bagian BE (DBV-033, MAKE-094)

**Terakhir diperbarui:** 16 November 2026 — bagian BE chatbot SELESAI. DBV-033 di `main` `3fb8c5f7` (PR #40, 13-11-2026). MAKE-094 di
`main` `4af0c188` (fast-forward 14-11-2026, izin Horii) sesudah dua putaran review CR (W-1..W-11, V-1..V-8) dan gate penuh lolos.
Kontrak endpoint (beku, dipakai MAKE-095): `backend/app/Controllers/Api/HaloSimpeg/Chatbot/README.md`.

| Task | Key | Status | Ringkas |
|---|---|---|---|
| C1 Skema chatbot | DBV-033 | DONE (main `3fb8c5f7`, PR #40) | 6 tabel, status 1/2/10, `judul_unik` STORED + UNIQUE, relasi PK komposit, `log_chatbot` anonim + `idx_log_waktu`; dokumen `backend/docs/db-review/DBV-033-skema-chatbot.md` |
| C2 Backend chatbot | MAKE-094 | DONE (main `4af0c188`, ff 14-11-2026) | rincian di bawah |
| C4 Impor isi QnA dev | (key menyusul, F8-51) | TODO, di luar MAKE-094 | ditunda ke deploy/cutover (K-5, PN8-6) |

**C2 MAKE-094, isi akhir:**
- **Mesin:** `TfIdf` + `MesinJawab` (murni), port legacy dev apa adanya. Test pembanding 11 skenario §7.10.7 sama dengan hasil mesin
  dev (toleransi 0,0001). Beda yang diharapkan, dicatat dan dikunci test:
  - skenario 12: tag ganda dev;
  - skenario 13: HTML, karena dev memakai `xss_clean`;
  - token angka ≥ 2^53 dalam bentuk eksponen (W-5, docstring `TfIdf::kunci`).
- **Endpoint (§6.5):**
  - widget (role 2/6/7): kategori, pertanyaan, populer, balas, feedback;
  - admin (role 1): kategori/tag/QnA CRUD + `PATCH …/status`, `qna/opsi`, `qna/template`, `qna/impor`, `tag/ekspor`,
    `statistik`, `riwayat`.
- **Route:** `Config/RoutesChatbot.php`, didaftarkan satu entri di `Config/Routing.php`.
- **Role:** semua route chatbot tercatat di `backend/tests/feature/KontrakMatriksRoleTest.php` (prefiks `halo-simpeg/chatbot` di
  `PREFIKS_CAKUPAN`, 29 baris kontrak). Prefiks sengaja spesifik chatbot; jalur helpdesk menambah prefiks dan barisnya sendiri.
- **Konfigurasi:** semua angka di `Config\Chatbot`, nilai bawaannya dikunci `ConfigChatbotTest`.
- **Token feedback (F8-35):** HMAC per `id_log` + akun, 24 jam. Kunci `chatbot.kunciTokenFeedback` di `.env.example` (nama saja);
  pemeriksaannya di README-deploy §1m.
- **Throttle (F8-36):** tiga kelompok per akun (balas 20, feedback 30, baca widget gabungan 60 per menit).
- **Impor (F8-37):**
  - satu transaksi semua-atau-tidak; galat baris → 200 + laporan, tanpa simpan;
  - ukuran sheet diperiksa sebelum dimuat; sel kosong bergaya tidak dihitung (pra-cek baris memakai `setReadEmptyCells(false)`; di PhpSpreadsheet 5.10 terbukti: border sampai baris 1.500 + 2 baris data → `totalRows` 3, bukan 1.500);
  - judul kolom ekstra → 422;
  - balapan judul 1062 → 422.
- **Batas panjang:** `pertanyaan`/`jawaban` maks 65.535 byte (TEXT), sama untuk form dan impor (`BatasQna`) (sebelum W-1..W-11 batas form pertanyaan ±16.383 karakter; diubah ke 65.535 byte mengikuti TEXT, F8-41, sah; jawaban 65.535 byte sesudah sanitasi).
- **Ekspor tag/template (F8-47):** lewat `EksporTabel::xlsx`.
- **Statistik/riwayat (F8-42):** hari WIB dikonversi ke rentang UTC.
- **Performa (R8-11):**
  - jalur bubble membaca satu baris;
  - korpus dimuat tanpa kolom jawaban, sesudah validasi;
  - agregasi bubble dibatasi per kategori.
  - Ukur lokal: ±23 ms untuk korpus sintetis 303 QnA. Angka indikatif, tanpa assertion waktu.
- **Audit:** tulis admin tercatat di `audit_logs` (relasi `qna_has_tag` diaudit manual); `log_chatbot` tanpa audit.
- **Contoh respons sintetis** (22 contoh respons, disalin FE; ditambah `pembanding.json` untuk test pembanding mesin yang tidak disalin): `backend/tests/_support/HaloSimpeg/Chatbot/*.json`.

**Test** (`backend/tests/**/HaloSimpeg/Chatbot/`):
- unit `MesinJawabPembandingTest` (fixture `pembanding.json`) dan `ConfigChatbotTest`;
- fitur `WidgetEndpointTest`, `AdminEndpointTest`, `ImporQnaTest`, `StatistikRiwayatTest`;
- `KontrakFixtureTest`: 22 dari 22 fixture dicocokkan dengan respons asli, termasuk impor HTTP multipart;
- `SkemaChatbotTest` (dari DBV-033);
- role: `KontrakMatriksRoleTest`.

Gate penuh sebelum merge: PHPUnit 4.253 test, Vitest 1.826 test, PHPStan dan CS bersih.

**Catatan DB Validator N1–N5 (DBV-033 §11) — semua diterapkan di MAKE-094:**
- N1: korpus kosong tanpa log.
- N2: cek unik `_ci` + trim; 1062 → 422 yang sama.
- N3: korpus hanya QnA dan tag status 1; ada test tag status 2/10 yang masih berelasi.
- N4: `judul_unik` tidak ditulis dan tidak dikirim.
- N5: tanpa purge log (bukan blok; `idx_log_waktu` siap bila kelak perlu retensi).

**Sisa di luar MAKE-094:**
- C4 impor isi QnA dev: menunggu keputusan PN8-6 saat deploy (F8-27, F8-46, F8-51). CSV dan isi QnA dev tidak pernah masuk repo.

## E-10 Chatbot "Vanya" — bagian FE (MAKE-095)

**Terakhir diperbarui:** 16 November 2026. MAKE-095 **DONE**: masuk `main` lewat merge commit `2a8f405d` (14-11-2026), di atas
MAKE-092 `f3827106`. Branch `fase8/make-095-chatbot-fe` (HEAD `50d75d79`). Kontrak yang diikuti: README MAKE-094
`backend/app/Controllers/Api/HaloSimpeg/Chatbot/README.md`. Rincian jalur ada di `docs/fase8/8_2.md` §5.

| Task | Key | Status | Ringkas |
|---|---|---|---|
| C3 Frontend chatbot | MAKE-095 | DONE (main `2a8f405d`) | widget Vanya (role 2/6/7) + Chatbot Manager (role 1); rincian di bawah |

**C3 MAKE-095, isi** (folder `frontend/src/features/halo-simpeg/chatbot/`):
- **Widget Vanya** (`components/widget/VanyaWidget.vue`, `VanyaAvatar.vue`, `VanyaPenilaian.vue`):
  - launcher, kartu sapaan (peek), jendela chat, bubble topik/pertanyaan/populer, dan penilaian 👍/👎. Teks dan SVG di-port dari
    view dev, tanpa isi QnA dev.
  - Batas pesan 300 code point, ditegakkan di input dan di store.
  - Fokus kembali ke kotak ketik sesudah memilih chip atau menilai; Esc menutup jendela.
- **Percakapan:**
  - state modul `stores/percakapan.ts` (bukan Pinia) dan `stores/peek.ts` (sessionStorage);
  - pesan galat 403/422/429 sesuai kontrak.
- **Reset saat logout:**
  - `resetChatbot()` dipanggil dari `clearSession()` di `features/auth/stores/auth.store.ts`;
  - penjaga generasi membuang respons yang tiba sesudah reset.
- **Gerbang tampil widget** (di `VanyaWidget`): fase aktif ≥ 8, role 2/6/7, dan tidak sedang wajib ganti password.
  - Titik pasangnya `shared/layouts/RedesignShell.vue`, dengan syarat `v-if="!auth.mustChangePassword"`.
- **Chatbot Manager** (role 1, `views/ChatbotManagerPage.vue` + `views/admin/*`), lima halaman:
  - **Kategori, Tag:** `MasterChatbotView`; tag punya ekspor xlsx.
  - **QnA:** `QnaChatbotView`, dengan form `QnaFormDialog` + `TagPilihan`, impor `ImporQnaDialog`, dan unduh template.
  - **Statistik:** `StatistikChatbotView`.
  - **Riwayat:** `RiwayatChatbotView`, dengan filter tanggal, cocok, dan feedback termasuk "Belum dinilai".
- **Pola Chatbot Manager:**
  - tabel memakai `page-fill`, menu ⋮ `RowActionsMenu` (Edit → Aktifkan/Nonaktifkan → Hapus dengan `ConfirmDialog`), dan status
    hanya badge;
  - filter dan pencarian di server, dengan penjaga urutan respons;
  - halaman tanpa frame Figma, ikut pola tabel v2 (K-11).
- **Layanan:**
  - `services/chatbot.service.ts`, respons dibaca Zod (`schemas.ts`);
  - galat 422 per kolom berbentuk array v2 (`galat.ts`);
  - unduhan lewat `berkasUnduhan`, dengan nama dari Content-Disposition.
- **Route dan menu:**
  - `routes.chatbot.ts`: `/chatbot-manager/{kategori,tag,qna,statistik,riwayat}`, nama `chatbot-<bagian>`, `/chatbot-manager` →
    kategori, meta role 1. Didaftarkan di `router/index.ts`.
  - Item menu **Pengaturan → Chatbot Manager** di `shared/layouts/nav.config.ts`: fase 8, role 1, 5 anak.
- Berkas bersama diubah secara aditif dan di-commit terpisah dari fitur.

**Test:** 83 test Vitest di `__tests__/`.
- `VanyaWidget.spec.ts` 36, `ChatbotManager.spec.ts` 23, `chatbot.amplop.spec.ts` 21, dan `VanyaShell.spec.ts` 3.
- `chatbot.amplop.spec.ts` memakai klien API asli.
- `VanyaShell.spec.ts` hermetik: tanpa request jaringan.
- Fixture `__tests__/fixtures/*.json` (22 berkas) adalah salinan identik contoh respons BE `backend/tests/_support/HaloSimpeg/Chatbot/`.
- Perbaikan review CR (M-1..M-12, N-1..N-8, 14-11-2026) memakai key MAKE-095, karena masih dalam paket.
- Gate frontend `./check.sh frontend` @`50d75d79` lolos: 174 berkas / 1.909 test, ESLint, vue-tsc, build.

**Belum (penutup Fase 8):**
- QA Lapis 1 bagian chatbot (AC-E10.22) oleh reviewer.
- Acuan visual widget dev **PN8-8**: diputus 16-11-2026, acuan = tangkapan layar widget dev dari Horii; **diterima 16-11-2026** (✔, PRD v1.3 CR-136), disimpan di luar repo dan tidak di-commit.
- Rilis menu dan widget: `ACTIVE_PHASE` masih 5, sehingga widget dan menu Chatbot Manager belum tampil. Fase aktif dinaikkan ke 8 saat
  penutup Fase 8 (keputusan Horii).
- R10 langkah 2 (migrasi `chatbot/galat.ts` + `chatbot/components/admin/TagPilihan.vue` ke `shared/`, PRD v1.3 §3): disetujui Horii
  16-11-2026, dikerjakan di paket perbaikan QA chatbot (CR-134 dst.) dengan syarat 83 test chatbot di atas tetap hijau.

## Helpdesk E-01..E-09 — status (CR-136, 16-11-2026)

**Terakhir diperbarui:** 16 November 2026 (CR-136, lewat reviewer). Belum ada kode helpdesk di `main`. Bagian ini diisi tiap paket
helpdesk secara aditif (BE: Handling Case; FE: Worker Muse; T12b: ReadOne). Kontrak final: PRD v1.3 §6.1b (dibekukan di README
`backend/app/Controllers/Api/HaloSimpeg/README.md` saat MAKE-070/071).

| Task | Key | Status | Ringkas |
|---|---|---|---|
| A0 Laporan pemahaman BE | — | DONE (disetujui reviewer 16-11-2026) | dua putaran revisi; tanpa commit kode; daftar kontrak final, galat, batas per field, dan fixture masuk PRD v1.3 §6.1b, §7.3a; `8_1.md` A3 |
| B0 Laporan pemahaman FE | — | DONE (disetujui final 16-11-2026) | tanpa commit kode; keputusan FE final di PRD v1.3 §6.3; rencana berkas bersama di PRD v1.3 §3 |
| A1 Skema helpdesk | DBV-028 | IN_PROGRESS (dibuka 16-11-2026) | Handling Case menulis, review DB WS-2, PR `[CR][DBV]`; indeks dan kueri pra-cek mengikuti PRD v1.3 §7.1 |
| A2 Master | MAKE-070 | TODO | menunggu DBV-028 di `main` |
| A3 Tiket + ringkasan | MAKE-071 | TODO | menunggu MAKE-070; H8-10 ◻ |
| A4 Push | MAKE-072 + CR-137 | TODO | CR-137 = berkas bersama Push, commit terpisah di branch MAKE-072; H8-8 ◻ |
| A5 Ekspor + penutup BE | MAKE-074 | TODO | menunggu MAKE-071 |
| B1–B3, B5 Frontend | MAKE-073 | TODO | B1 dibuka sesudah kontrak MAKE-070 beku di README `Controllers/Api/HaloSimpeg`; bagian B1 yang memakai kontrak tiket (service/store tiket, ringkasan, badge) serta B2–B3 menunggu README MAKE-071 beku |
| T12b Impor master | MAKE-097 | TODO | ReadOne, review DB WS-2; menunggu DBV-028 di `main` |

**Keputusan Horii 16-11-2026** ("ikut rekomendasi semua", diteruskan reviewer):
- H8-14 (a): `AntrePushPesan::antreAkun(id_pengguna[])` aditif; berkas Push bersama = **CR-137**, commit terpisah di branch MAKE-072,
  test regresi cuti/LKH/Konket.
- H8-15 (b): entri "Tiket Halo Simpeg (n)" di `NotifikasiPage` menuju route Halo Simpeg (tab antrean bila sisi petugas > 0); petugas
  role 4/5 (di luar `NOTIFIKASI_ROLES`) lewat badge menu.
- H8-16 (a1): ikut legacy tanpa ubah data. Tiket kategori NULL hanya dilihat dan ditutup pemilik; balas → 422 `tiket_tanpa_kategori`;
  hapus oleh pemilik → 403 `bukan_pihak_berwenang` (ikut F8-09 [V2]); tidak dihitung di sisi petugas, tidak ikut ekspor; jumlah dan
  irisan sender yatim dicatat runbook.
- K-A4 (1'): hapus petugas terakhir (aktif atau nonaktif) kategori ber-tiket Open → 422 `petugas_terakhir`; Nonaktifkan → 200 +
  peringatan; `bisa_hapus` + `alasan_tidak_bisa_hapus`.
- Q-A0-6 (a'): hapus lampiran lewat `PenjagaBerkas::catat` + `selesaikan()` (hitung rujukan); anomali tertinggal tercatat.
- Q-A0-8: angka PRD v1.3 §7.3a (throttle tiket 5/10 menit, pesan 20/menit; `subject` 250, `msg` 5.000; ekspor 10.000 baris + batas
  ukuran yang diukur di MAKE-074, sel dipotong 32.767 [V2]).
- Penyamaran D7 (a) legacy per layar; badge D8 (a) `badge` = distinct(belum dibaca ∪ `perlu_tindak`), `total` tetap = lonceng; bawaan
  tab "Tiket saya" `semua` [V2], antrean `belum` [K]; titik penanda sidebar ciut (CR kecil `AppSidebar.vue`, commit terpisah).
- R10 langkah 1 (`shared/utils/galatKolom.ts`, `shared/ui/UiPilihBanyak.vue`) dalam #9; langkah 2 (migrasi chatbot) disetujui, ikut
  CR-134 dst.
- Q-A0-9 / F8-20: WIB ↔ UTC dan audit unduhan AC-E09.7; ekspor kosong tidak diaudit.
- H8-9: frame Figma helpdesk belum ada → layar ikut pola redesign dan dicatat; gerbang B3 terpenuhi.

**Keputusan reviewer CR 16-11-2026:** Q-A0-4 ikut PRD §6.2; Q-A0-5 baris `disiplin_ticket_msg` di `ManifestLampiran::KOLOM`, test, 2 kueri
pra-cek, dan runbook masuk MAKE-097 (ReadOne); Q-A0-7 kontak dua path (`kontak/aktif`, `kontak`); rincian kontrak lain di PRD
v1.3 §6.1b.

**Masih terbuka:** H8-7 (sebelum QA), H8-8 (sebelum MAKE-072), H8-10 (sebelum MAKE-071; tanpa ekstraksi tetap [I], tetapi penanda baca
sisi pembalas petugas dan buka-detail sudah [K]).
