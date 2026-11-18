# Progress Fase 8 — Modul E: Halo Simpeg

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak: `docs/fase8/PRD_Fase8_HaloSimpeg.md` (v1.1; v1.3 CR-136 dan v1.4 CR-138 untuk helpdesk), rincian jalur
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

**Sesudah MAKE-094 (izin [#10-WorkloadQAChatbot], Horii 17-11-2026):**
- CR-133 ✔ di `main` `19a11ed5` (ReadOne): test isi pesan chatbot tidak masuk log aplikasi maupun `audit_logs` (AC-E10.19).

**Sisa di luar MAKE-094:**
- C4 impor isi QnA dev: menunggu keputusan PN8-6 saat deploy (F8-27, F8-46, F8-51). CSV dan isi QnA dev tidak pernah masuk repo.

## E-10 Chatbot "Vanya" — bagian FE (MAKE-095)

**Terakhir diperbarui:** 17 November 2026 (CR-138, lewat reviewer). MAKE-095 **DONE**: masuk `main` lewat merge commit `2a8f405d` (14-11-2026), di atas
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
  - **QnA:** `QnaChatbotView`, dengan form `QnaFormDialog` + `UiPilihBanyak` (`shared/ui/`), impor `ImporQnaDialog`, dan unduh
    template. Sampai CR-134 paket 1 pemilih tag memakai komponen lokal `TagPilihan`; komponen itu sudah dihapus (R10).
  - **Statistik:** `StatistikChatbotView`.
  - **Riwayat:** `RiwayatChatbotView`, dengan filter tanggal, cocok, dan feedback termasuk "Belum dinilai".
- **Pola Chatbot Manager:**
  - tabel memakai `page-fill`, menu ⋮ `RowActionsMenu` (Edit → Aktifkan/Nonaktifkan → Hapus dengan `ConfirmDialog`), dan status
    hanya badge;
  - filter dan pencarian di server, dengan penjaga urutan respons;
  - halaman tanpa frame Figma, ikut pola tabel v2 (K-11).
- **Layanan:**
  - `services/chatbot.service.ts`, respons dibaca Zod (`schemas.ts`);
  - galat 422 per kolom berbentuk array v2 (`shared/utils/galatKolom.ts`; sampai CR-134 paket 1 lewat `chatbot/galat.ts`
    lokal, yang sudah dihapus, R10);
  - unduhan lewat `berkasUnduhan`, dengan nama dari Content-Disposition.
- **Route dan menu:**
  - `routes.chatbot.ts`: `/chatbot-manager/{kategori,tag,qna,statistik,riwayat}`, nama `chatbot-<bagian>`, `/chatbot-manager` →
    kategori, meta role 1. Didaftarkan di `router/index.ts`.
  - Item menu **Pengaturan → Chatbot Manager** di `shared/layouts/nav.config.ts`: fase 8, role 1, 5 anak.
- Berkas bersama diubah secara aditif dan di-commit terpisah dari fitur.

**Test:** 83 test Vitest di `__tests__/` saat MAKE-095 masuk `main`; CR-134 paket 1 dan 2 menambah test (rincian di commit
CR-134).
- `VanyaWidget.spec.ts` 36, `ChatbotManager.spec.ts` 23, `chatbot.amplop.spec.ts` 21, dan `VanyaShell.spec.ts` 3.
- `chatbot.amplop.spec.ts` memakai klien API asli.
- `VanyaShell.spec.ts` hermetik: tanpa request jaringan.
- Fixture `__tests__/fixtures/*.json` (22 berkas) adalah salinan identik contoh respons BE `backend/tests/_support/HaloSimpeg/Chatbot/`.
- Perbaikan review CR (M-1..M-12, N-1..N-8, 14-11-2026) memakai key MAKE-095, karena masih dalam paket.
- Gate frontend `./check.sh frontend` @`50d75d79` lolos: 174 berkas / 1.909 test, ESLint, vue-tsc, build.

**Perbaikan QA chatbot (izin [#10-WorkloadQAChatbot], Horii 17-11-2026; FE Worker Muse):**
- CR-134 paket 1 ✔ (di `main` per `67f3303c`): avatar mini rata atas [K]; catatan README [V2] PN8-8; R10 langkah 1 + 2
  (`shared/utils/galatKolom.ts` dan `shared/ui/UiPilihBanyak.vue` dibuat, `chatbot/galat.ts` dan
  `chatbot/components/admin/TagPilihan.vue` dihapus). Duplikasi R10 (PRD B-30, Kesiapan RK-13) **selesai**.
- CR-134 paket 2 ✔ (`d659acf6`): perbaikan temuan QA T-02 dan T-03.

**Belum (penutup Fase 8):**
- ~~QA Lapis 1 bagian chatbot (AC-E10.22) oleh reviewer~~ ✔ selesai: putaran 1 bagian A 17-11-2026 (checklist CR-132 `67f3303c`),
  uji ulang T-02/T-03 (CR-134 paket 2 `d659acf6`) dan AW17, sisa 18-11-2026 pada `e4392507`; 0 ❌ (CR-144).
- Acuan visual widget dev **PN8-8**: diputus 16-11-2026, acuan = tangkapan layar widget dev dari Horii; **diterima 16-11-2026** (✔, PRD v1.3 CR-136), disimpan di luar repo dan tidak di-commit.
  Keputusan tampilan Horii 17-11-2026 ("ikut rekomendasi"): avatar tetap ilustrasi SVG (foto tidak dipakai); aksen biru
  `brand-tertiary`, bukan pink dev; saat memuat, tombol/chip disabled + indikator; launcher 64 px dan gaya nama "Vanya" tetap
  v2 = [V2]; perbaikan [K] avatar mini rata atas tuntas di CR-134 (README `features/halo-simpeg/chatbot`).
- Rilis menu dan widget: `ACTIVE_PHASE` masih 5, sehingga widget dan menu Chatbot Manager belum tampil. Fase aktif dinaikkan ke 8 saat
  penutup Fase 8 (keputusan Horii).
- ~~R10 langkah 2~~ (gugur CR-138: selesai di CR-134 paket 1, lihat di atas).

## Helpdesk E-01..E-09 — status (CR-138, 17-11-2026)

**Status akhir (18 November 2026, CR-144, reviewer CR):** per `main` `8bc6c509` **semua paket helpdesk di `main`** (DBV-028,
MAKE-070, MAKE-071, MAKE-097, MAKE-072 + CR-137, MAKE-074 BE + FE, MAKE-073) beserta perbaikan temuan QA CR-143 dan CR-142.
**QA Lapis 1 Fase 8 selesai** 18-11-2026 pada `main` `e4392507` (bagian B) + verifikasi perbaikan `8bc6c509`, 0 ❌ terbuka
(checklist `docs/fase8/Checklist_QA_Lapis1_Fase8.md`, laporan `docs/fase8/Laporan_Akhir_Fase8.md`). **SIGN-OFF Horii 18-11-2026**
pada `main` `d8020fc3` (CR-145); rilis menu CR-135 sesudah sign-off (⛔ izin Horii).

Riwayat 18 November 2026 (MAKE-074, Handling Case). Per `main` `64d68377`: DBV-028, MAKE-070, MAKE-071,
MAKE-097, dan MAKE-072 + CR-137 di `main`; MAKE-074 BE berjalan; status MAKE-073 (FE) diisi Worker Muse. Riwayat 17 November
2026 (CR-138, per `main` `d659acf6`): DBV-028 dan MAKE-070 di `main`; MAKE-071, MAKE-097, dan MAKE-073 B1 berjalan. Bagian ini diisi tiap paket
helpdesk secara aditif (BE: Handling Case; FE: Worker Muse; T12b: ReadOne). Kontrak final: PRD v1.3 §6.1b (dibekukan di README
`backend/app/Controllers/Api/HaloSimpeg/README.md` saat MAKE-070/071); selisih README master terhadap PRD v1.3 (S1–S12) diringkas di PRD v1.4 §6.1b.

| Task | Key | Status | Ringkas |
|---|---|---|---|
| A0 Laporan pemahaman BE | — | DONE (disetujui reviewer 16-11-2026) | dua putaran revisi; tanpa commit kode; daftar kontrak final, galat, batas per field, dan fixture masuk PRD v1.3 §6.1b, §7.3a; `8_1.md` A3 |
| B0 Laporan pemahaman FE | — | DONE (disetujui final 16-11-2026) | tanpa commit kode; keputusan FE final di PRD v1.3 §6.3; rencana berkas bersama di PRD v1.3 §3 |
| A1 Skema helpdesk | DBV-028 | DONE (main, PR #41, `a5cb7300`) | Handling Case menulis; DB Validator WS-2 SETUJU Q1–Q10 (Q8 `last_msg_at` tidak masuk dengan syarat (a)–(c), Q9 Q-H4 INFO, Q10 PK `officer_category.id` [I]); dokumen `backend/docs/db-review/DBV-028-skema-helpdesk.md` |
| A2 Master | MAKE-070 | DONE (main `56a700b1`) | README master beku; selisih S1–S12 diterima reviewer 17-11-2026, termasuk S8 (b) Horii (PUT petugas → 200 + `peringatan`); kunci transaksi W1 (locking read sebelum kunci terakhir) |
| A3 Tiket + ringkasan | MAKE-071 | DONE (main `066315c5`) | T1–T13 diputus 17-11-2026 (T2 (b) Horii: pemilik selalu 403 hapus); README tiket beku; binding `RingkasanTiket` untuk lonceng F-08 (AC-E06.9); H8-10 ✔ tetap [I] |
| A4 Push | MAKE-072 + CR-137 | DONE (main `64d68377`) | CR-137 = berkas bersama Push, commit terpisah; penerima per akun (`antreAkun`), pelaku dikecualikan, kanal `semua`; selisih U1–U9 diterima; H8-8 belum ada → payload terbatas F8-16, uji push real ke DEPLOY-001.1 |
| A5 Ekspor + penutup BE | MAKE-074 | DONE (main `947b94fd`) | E-09 `GET tiket/ekspor` (rincian di bawah); penutup: bagian ini, README modul/Push, runbook cutover `README-deploy.md` §1n (B6, B7, Q-H19 + EXPLAIN gladi, DBV-028 Q8 (a)) |
| B1–B3, B5 Frontend | MAKE-073 | DONE (main `d90d8a75`; B5 masuk bersama MAKE-074 FE `e4392507`) | layar pemilik, petugas, admin master, badge/notifikasi; rincian di "Frontend helpdesk" di bawah; B5 penutup di branch B4 |
| B4 Frontend ekspor | MAKE-074 | DONE (main `e4392507`) | tombol "Ekspor Rekap" tab Antrean + dialog periode WIB; rincian di bawah |
| T12b Impor master | MAKE-097 | DONE (main `82d698ec`, PR #42) | ReadOne, review DB WS-2; `id_user` apa adanya, yatim dibuang + dicatat (K-M11/K-M12); lampiran tiket lama di `ManifestLampiran` (T-5/T-8); runbook `docs/migrasi/MAKE-069-rencana.md` T12b |
| Helper satker | CR-139 | DONE (main `ccb90f78`) | `WebConfigService::satkerKepegawaian()` dipakai `PetugasLayananService` dan `HaloSimpeg\PetugasService` |
| Temuan QA T-B1 (BE) | CR-143 | DONE (main `4d06a8c6`) | `message` sukses legacy "Berhasil menambahkan data halo simpeg." pada 201 buat tiket |
| Temuan QA T-B4, T-B3, T-B1 (FE) | CR-142 | DONE (main `8bc6c509`) | ruang bawah halaman + `scroll-padding` agar launcher Vanya tidak menutupi "Kirim"; kolom Aksi sticky kanan; notice sukses tiket baru dari `message` |
| QA Lapis 1 + laporan akhir | CR-132, CR-144 | DONE (main `d8020fc3`); QA selesai 18-11-2026; SIGN-OFF Horii 18-11-2026 (CR-145) | checklist terisi; T-B2 (`Cache-Control` gabungan `tanpaCache`) = kandidat CR sesudah sign-off |

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

**Keputusan Horii 17-11-2026** (CR-138, diteruskan reviewer):
- H8-10 = tetap [I]: sisi lawan ke 2 dan sisi pembalas pemilik tetap [I], diuji per skenario AC-E04.4; gerbang MAKE-071
  terpenuhi.
- H8-8 belum ada: MAKE-072 memakai payload terbatas F8-16; uji push real (G8-7) dicatat ke DEPLOY-001.1.
- H8-7 belum ada: QA bagian B memakai satker kepegawaian demo (Web Config) untuk kandidat petugas; butir yang bergantung H8-7
  ditandai bersyarat; konfirmasi Biro SDM menjadi butir cutover.
- S8 (b) MAKE-070: PUT petugas → 200 + `peringatan` (bukan 422) bila kategori ber-tiket Open kehilangan petugas aktif terakhir.
- T2 (b) MAKE-071, F8-09 harfiah: pemilik tiket selalu 403 `bukan_pihak_berwenang` untuk hapus walau petugas aktif kategori
  itu; petugas lain di kategori yang sama boleh menghapus.
- Izin [#10-WorkloadQAChatbot]: CR-133 oleh ReadOne; CR-134 dst. oleh Worker Muse (FE) dan ReadOne (BE).
- PN8-8 "ikut rekomendasi" (bagian FE chatbot di atas); teks info paginasi bersama "Menampilkan a sampai b dari n data"
  (CR-141, berjalan).

**Keputusan reviewer CR dan DB Validator 17-11-2026:** selisih README master S1–S12 diterima; keputusan MAKE-071 T1–T13 sebelum
README tiket beku; kunci transaksi W1; path admin FE `/halo-simpeg-admin/:bagian`; R10 langkah 1 dipindah dari B1 ke CR-134
paket 1 (PRD B-30 dan Kesiapan RK-13 selesai); DBV-028 Q8–Q10 (PRD v1.4 §7.1). Key baru: CR-138 (PRD v1.4), CR-139 (helper
satker), CR-140 (stabilkan test `alurInti.e2e` yang flaky di bawah beban), CR-141 (teks paginasi).

**Masih terbuka (sampai CR-136):** H8-7 (sebelum QA), H8-8 (sebelum MAKE-072), H8-10 (sebelum MAKE-071; tanpa ekstraksi tetap
[I], tetapi penanda baca sisi pembalas petugas dan buka-detail sudah [K]). **CR-138:** ketiganya dijawab Horii 17-11-2026; tidak
ada butir H8 yang terbuka.

### MAKE-074 — ekspor rekap petugas E-09 + penutup BE (18-11-2026)

- **Endpoint:** `GET api/v1/halo-simpeg/tiket/ekspor?ds&de&format=xlsx` (role 1–7, `TiketController::ekspor`,
  `EksporTiketService`). Kontrak = README `Controllers/Api/HaloSimpeg` bagian ekspor. Izin = `bisa_ekspor` (petugas aktif), selain
  itu 403 `bukan_petugas`. Periode = hari kalender WIB → `[ds 00:00 WIB, de+1 00:00 WIB)` UTC tanpa `DATE()`. Tiket kategori NULL
  dan kategori lain tidak ikut. Periode kosong → 200 JSON tanpa audit. Berkas terkirim → audit `dokumen_unduhan` tanpa isi/NIP
  penanya (AC-E09.7).
- **Pengaman (§7.3a):** `eksporMaksBaris` = 10.000 baris pesan; `eksporMaksKarakter` = **5.000.000** karakter
  (`SUM(CHAR_LENGTH(msg))`); lewat salah satu → 422 `ekspor_terlalu_besar`. Sel > 32.767 unit UTF-16 dipotong dengan penanda
  "… [dipotong]" [V2].
- **Pengukuran ambang** (`EksporTiketVolumeTest`, `RUN_VOLUME_TEST=1`; request HTTP penuh, `memory_reset_peak_usage()` sebelum
  request). Mesin dev Windows 11, PHP 8.4.2, MySQL 8 lokal (port uji), `memory_limit` 512M. Data: 10.000 baris tepat di ambang
  karakter.

  | Isi pesan | Byte | Puncak tambahan | Waktu |
  |---|---|---|---|
  | Latin | 5,2 MB | 50,7 MB | 0,4 s |
  | CJK 3 byte | 15 MB | 82,4 MB | 0,7 s |
  | Emoji 4 byte (terburuk) | 20 MB | 105,3 MB | 0,7 s |

  Batas test 128 MB (separuh `memory_limit` 256M di `README-deploy.md` §1). Pengukuran awal di 10.000.000 karakter: Latin 69 MB,
  tetapi CJK 3 byte 140 MB (lewat batas), jadi ambang diturunkan ke 5.000.000 (diterima reviewer 18-11-2026). Bila
  `memory_limit` produksi < 256M, ambang wajib diturunkan. Isi berulang memampatkan berkas (±0,3 MB); berkas
  dari teks nyata lebih besar, dan tambahannya paling banyak sebesar ukuran berkas itu.
- **Selisih V1–V13 (18-11-2026):** V3–V13 diterima reviewer (V10 penanda termasuk 32.767 + surrogate utuh; V12 baris **dan**
  karakter dicek ulang sesudah data diambil). **V1/V2 diputus Horii: (a) ikut legacy penuh [K `LS:1626`, `LS:1797-1799`]**: hanya
  pesan pemilik tiket + pesan petugas pengekspor (balasan petugas lain tidak ikut, termasuk bila pengekspor role 1; COUNT/SUM atas
  cakupan yang sama); nama/NIP = pengirim baris, tanpa penyamaran. README ekspor dan fixture bagian ini **beku**.
- **Test:** `EksporTiketTest` (AC-E09.1..E09.8: 403 bukan petugas/nonaktif/role 8, batas hari WIB, kolom dan urutan, NIP teks,
  kosong 200 tanpa audit, validasi periode/format, cakupan V1 (balasan petugas lain tidak ikut; pengekspor role 1 aturan sama, NIP
  kosong), 10.000 vs 10.001 baris, ambang karakter, potong sel, unit UTF-16 dan emoji di
  batas, rentang UTC, cek ulang baris/karakter saat pesan masuk di antara dua kueri (koneksi kedua, isolasi penuh)),
  baris `KontrakMatriksRoleTest`, fixture `ekspor-kosong`, `galat-422-ekspor`, `galat-422-ekspor-terlalu-besar`,
  `galat-403-bukan-petugas`.
- **Titipan review MAKE-072:** mock antrean di `TiketNotifTest` memakai pesan unik, sehingga `assertLogContains` tidak bisa
  terpenuhi log basi `PushRiwayatTest`.
- **Penutup:** README `Libraries/HaloSimpeg` (peta kelas + aturan bersama), README Push (status H8-8/PN8-1), runbook cutover
  `README-deploy.md` §1n (merujuk runbook T12b MAKE-097 dan berkas audit DBV-028; lembar hasil B6/B7/Q-H15/Q-H19/EXPLAIN dengan
  ambang Q8 (a)/(b) 5.000 tiket / 100 ms; butir cutover H8-7, DEPLOY-001.1, §1m, CR-135).
- **Masih terbuka (bukan syarat sign-off):** H8-8/PN8-1 payload push lengkap + uji push real = blocker deploy (DEPLOY-001.1);
  saran trim Unicode validasi `msg` (dari MAKE-072, di luar kontrak beku) = kandidat CR sesudah sign-off. Tombol ekspor FE (B4)
  sudah di `main` `e4392507`.

### Frontend helpdesk — MAKE-073 (B1–B3, B5) + MAKE-074 FE (B4) (18-11-2026, Worker Muse)

- **Branch:** `fase8/make-073-fe-halo` (B1–B3 + review R1–R12, Q1–Q7, S1–S6; HEAD `49f91f5a`) dan `fase8/make-074-fe-ekspor`
  (B4 + B5, berisi merge MAKE-073). Keduanya **di `main`** (izin Horii 18-11-2026): MAKE-073 merge `d90d8a75`, MAKE-074 FE merge
  `e4392507`. Rincian layar, berkas
  bersama, deviasi, dan tabel acuan layar tanpa Figma: README `frontend/src/features/halo-simpeg/README.md` bagian helpdesk.
- **Isi:** layar pemilik master-detail (B2); tab Antrean + panel "Perlu ditindak" + thread sisi petugas (B3); admin master Kategori /
  Petugas / Kontak `/halo-simpeg-admin/:bagian` (B3); badge menu (polling `tiket/ringkasan` 60 dtk), titik sidebar ciut, entri tiket di
  Notifikasi (H8-15 (b)); ekspor rekap petugas (B4) dengan batas hari ini WIB, periode kosong tanpa unduhan, 403/422 pesan BE.
- **Berkas bersama (aditif, commit tersendiri):** `RowAction.keterangan` + item nonaktif berketerangan tetap bisa difokus
  (`RowActionsMenu`), `UiCombobox`/`UiTextField` `saringLokal` + `pilih`, `UiTooltip` `open` opsional, menu `halo-admin`, badge
  `RedesignShell`/`AppSidebar`, `resetHaloSimpeg()` dari `clearSession()`.
- **Label [V2] FE:** bawaan baca tab "Tiket saya" `semua` (D8 (a)); kolom + filter kategori di tab "Tiket saya"; teks alasan
  tidak bisa hapus Kategori; teks validasi periode ekspor = teks BE.
- **Deviasi B4:** tanggal ketikan di luar batas picker ditolak `UiDatePicker` bersama (tidak terkirim), bukan "dikirim apa adanya"
  seperti rancangan; komponen bersama tidak diubah.
- **AC-E08.7 (H8-9):** frame Figma helpdesk belum ada; acuan per layar dicatat di README dan diuji di QA Lapis 1 §HV (tanpa Vitest).
- **QA Lapis 1 bagian B ✔ selesai 18-11-2026** (`main` `e4392507`; HV dan HB lewat build lokal): Q3 (fokus judul thread sesudah
  tiket baru) terbukti di browser (HP3). Temuan T-B4, T-B3, dan notice T-B1 diperbaiki CR-142 `8bc6c509` (sisi BE T-B1: CR-143
  `4d06a8c6`). Verifikasi demo pada CR-142 @`f49f075e`: ruang bawah T-B4 (desktop), kolom Aksi T-B3, dan notice T-B1 dengan
  teks cadangan (BE demo belum CR-143); sisi BE `message` dan `scroll-padding` (`a2f1e894`) diterima lewat test otomatis
  (CI #900, `564ebaa5` + CI #904) tanpa uji demo.
