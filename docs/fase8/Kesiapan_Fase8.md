# Kesiapan Fase 8 — Halo Simpeg (helpdesk E-01..E-09) dan E-10 Chatbot "Vanya"

| Butir | Isi |
|---|---|
| Dokumen | `docs/fase8/Kesiapan_Fase8.md` — daftar periksa **sebelum** helpdesk Halo Simpeg dimulai. Tidak mengubah PRD dan tidak membuka koding; pelaksana yang dicatat di sini adalah yang sudah diputus Horii (§4.2, §6.1), kecuali yang ditandai **usulan** (CR-133, CR-134+, CR-135; izin/keputusan diajukan ke Horii sebelum paket dibuka) |
| Acuan | PRD Fase 8 **v1.1** (v1.3 CR-136: hasil A0/B0 dan kontrak §6.1b) (`docs/fase8/PRD_Fase8_HaloSimpeg.md`: §0 gerbang & PN8-1..PN8-8, §1, §3, §10, §11, §12, §13), [`8_1.md`](8_1.md) (Jalur BE), [`8_2.md`](8_2.md) (Jalur FE), Laporan Akhir Fase 7 (`docs/fase7/Laporan_Akhir_Fase7.md`, addendum F-10 §10), PRD F-10 v1.6 (`docs/fase7/PRD_Fase7_F10_Arsip-Digital.md`, di `main`) |
| Basis kode | `main` = **`603af133`** (branch kerja `docs/cr-130-f8-status`; versi sebelumnya disusun di `483c5892`, CR-123). Rujukan `path:baris` di bawah dicek ulang di `603af133` untuk berkas yang berubah sejak `483c5892` (`nav.config.ts`, `RedesignShell.vue`, `router/index.ts`, `auth.store.ts`, `Config/Routing.php`, `KontrakMatriksRoleTest.php`, `LocalStorageAdapter.php`, `README-deploy.md`, `backend/.env.example`, `Laporan_Akhir_Fase7.md`, PRD F-10); berkas rujukan lain tidak berubah di rentang itu; berkas yang baru dibuat sesudah `483c5892` (mis. `backend/docs/progress/08-HaloSimpeg.md` `9f9d6052`, `backend/app/Controllers/Api/HaloSimpeg/Chatbot/README.md`) dirujuk langsung di `603af133`. **CR-136:** rujukan `path:baris` yang ditambah atau diubah di pembaruan ini dicek di `ef83356e` (`main` sesudah CR-130/CR-131); di rentang `603af133..ef83356e` berkas di luar `docs/fase8/` yang berubah hanya `README-deploy.md` (catatan Halo Simpeg di bagian pola `/berkas/`, baris `:257` tetap), ADR-038, dan progres 08. Rujukan `PRD:baris` tetap mengacu PRD v1.1; teks PRD v1.3 dirujuk per bagian (§) |
| Disusun | 13-11-2026, reviewer CR; diperbarui 16-11-2026 (CR-130) dan 16-11-2026 (CR-136: status A0/B0/A1, keputusan H8-9, H8-14..H8-16, PN8-8; rujukan baru dicek di `ef83356e`) |
| Status | **Siap mulai helpdesk.** Gerbang §1 terpenuhi (G8-7 = deploy). Langkah berikut (§5): A0 (Handling Case) dan B0 (Worker Muse) mulai sesudah CR-130 di `main`; laporan A0/B0 disetujui reviewer sesudah CR-131 (PRD v1.2) di `main`. Merge CR-130/CR-131 atas izin Horii. Butir ◻ di §6.1 dijawab Horii sebelum paket terkait; butir teknis ◻ di §6.2 diputus reviewer saat A0. **CR-136 (16-11-2026):** A0 ✔ dan B0 ✔ disetujui reviewer; Horii memutus H8-14 (a), H8-15 (b), H8-16 (a1) dan butir turunan A0/B0, menjawab H8-9 (frame helpdesk belum ada), PN8-8 diterima; A1/DBV-028 dibuka (Handling Case, review DB WS-2). Masih terbuka: H8-7, H8-8, H8-10 |

Tanda: **✔** terpenuhi (dengan bukti), **◻** belum terpenuhi / menunggu keputusan, **⛔** titik tunggu, **—** tidak berlaku.
Label **[K]** / **[V2]** / **[I]** mengikuti PRD Fase 8 §Cara pakai 5. Fakta yang hanya berasal dari laporan sesi (tidak tercatat di
repo) diberi catatan "fakta reviewer 13-11-2026" atau "fakta reviewer 16-11-2026".

## 0. Ringkasan

```
Status: Fase 7 SIGN-OFF Horii 11-11-2026 (main 0af9be4f); F-10 SIGN-OFF Horii 16-11-2026 (main fd9e8371,
        addendum ditutup CR-129 603af133). Gerbang koding helpdesk (PRD §0.1) TERPENUHI, termasuk G8-10
        (E-10 di main: DBV-033 3fb8c5f7, MAKE-094 4af0c188, MAKE-095 2a8f405d) dan G8-12 (F-10 sign-off).
        E-10: kode di main, belum QA Lapis 1 dan belum rilis menu. Helpdesk: belum ada kode (sesuai H8-2).
Izin:   [#9-WorkloadHaloSimpeg] aktif, diamandemen 16-11-2026 (FE = Worker Muse, T12b/MAKE-097 = ReadOne).
        [#8-WorkloadChatbot]: pekerjaan selesai.
Urutan: A0 (Handling Case) dan B0 (Worker Muse) mulai sesudah CR-130 di main; laporan A0/B0 disetujui
        reviewer sesudah CR-131 (PRD v1.2) di main → DBV-028 → MAKE-070 ...
        CR-136 (16-11-2026): A0 dan B0 DISETUJUI; A1/DBV-028 DIBUKA (PR [CR][DBV]); kontrak final = PRD
        v1.3 §6.1b; berkas bersama Push = CR-137 (commit terpisah di branch MAKE-072).
Butir terbuka: H8-7 (sebelum QA), H8-8 (sebelum MAKE-072), H8-10 (sebelum MAKE-071; penanda baca
        sisi pembalas petugas dan buka-detail sudah [K], sisa [I] = sisi lawan). Diputus 16-11-2026
        (CR-136): H8-9, H8-14 (a), H8-15 (b), H8-16 (a1), butir turunan A0/B0 (§6.1a), PN8-8, T-1..T-4,
        T-8; 10 risiko aktif (§7; RK-3, RK-4, RK-6 lewat). Pemetaan kartu Trello/Notion: §8.
```

## 1. Status gerbang koding (PRD §0.1, §9)

| No | Butir gerbang | Sumber PRD | Status | Bukti di repo |
|---|---|---|---|---|
| G8-1 | PRD v1.0 masuk `main` sebelum koding atau migration DBV-028 | `PRD_Fase8_HaloSimpeg.md:43` | ✔ | Commit `d126c779` (03-11-2026, "PRD Fase 8 v1.0 — keputusan Horii Q8-01..Q8-15") leluhur `603af133` |
| G8-2 | PRD v1.1 (E-10, CR-121) di `main` | `:49`, `:799` | ✔ | Merge `faaac019` (12-11-2026) + `32bd1db2` (bawaan teknis E-10 dikonfirmasi), leluhur `603af133` |
| G8-3 | Sebelum gerbang boleh: membaca PRD, M0 tanpa commit kode, menjawab §12 | `:44` | — | Gerbang sudah terbuka. A0 (Handling Case) dan B0 (Worker Muse) mulai sesudah CR-130 di `main`; laporan A0/B0 disetujui reviewer sesudah CR-131 (PRD v1.2) di `main` (§5). **CR-136:** A0 ✔ disetujui reviewer 16-11-2026, B0 ✔ disetujui final 16-11-2026 (tanpa commit kode) |
| G8-4 | M1–M3 tidak bergantung pada Fase 6 | `:45-46` | ✔ | Fase 6 pun sudah sign-off 10-11-2026 (`docs/fase6/Laporan_Akhir_Fase6.md:7`, `:73`) |
| G8-5 | M4 (push) menunggu **MAKE-066** (adapter FCM HTTP v1) di `main` | `:46-47`, `:794` | ✔ | Commit `9debb366` (MAKE-066) leluhur `603af133`; `FcmV1Adapter` terdaftar di `backend/app/Config/Services.php:543-544`, `MockFcmAdapter` `:542`; job antrean `push` di `backend/app/Config/Queue.php:46-57` |
| G8-6 | DBV-027 (`firebase_token.platform`, `token_hash`) untuk M4 | `:795` | ✔ | `backend/app/Database/Migrations/2026-11-03-100000_CreateIntegrasiKeluarFase6.php:23-24` |
| G8-7 | Uji push real sebelum deploy (bukan sign-off) | `:47-48`, `:836-837` | ◻ | Dilacak MT/DEPLOY; pemilik token Android = pertanyaan cutover DEPLOY-001.1 (`backend/app/Libraries/Push/README.md:16-17`) |
| G8-8 | Lonceng F-08 sudah ada saat M5 → Q8-06 = B (sumber lonceng) | `:257`, `:796`, `:852` | ✔ | F-08 MAKE-085 di `main` (`docs/fase7/Laporan_Akhir_Fase7.md:42`); titik sambung `backend/app/Interfaces/Notifikasi/RingkasanTiketInterface.php:9-21`, binding NoOp `backend/app/Config/Services.php:797-806`, dipakai `backend/app/Libraries/Notifikasi/Pusat/PusatNotifikasiService.php:74`, `:110`. Konsekuensi: §6.2 T-2 |
| G8-9 | E-10: DBV-033 baru disusun sesudah D1 chatbot blok [1]–[6] | `:50-52`, `:65` | ✔ | Status "DITERIMA 12-11-2026" tercatat di PRD `:51-52`, `:65`; isi D1 di luar repo (tidak dicek) |
| G8-10 | E-10: MAKE-094 menunggu DBV-033 di `main`; MAKE-095 menunggu kontrak MAKE-094 beku | `:52-53`, `:801` | ✔ | DBV-033 merge `3fb8c5f7` (PR #40, 13-11-2026); MAKE-094 di `main` `4af0c188` (fast-forward 14-11-2026, izin Horii), kontrak dibekukan di `fd1e74ed` (`backend/app/Controllers/Api/HaloSimpeg/Chatbot/README.md`), `RoutesChatbot.php` di `backend/app/Config/Routing.php:61`; MAKE-095 disesuaikan ke kontrak beku (`355dc032`) lalu merge `2a8f405d` (14-11-2026) sesudah MAKE-094 di `main` |
| G8-11 | Helpdesk dan chatbot tidak saling bergantung | `:53-54`, `:804` | ✔ | Berkas milik terpisah (PRD §3 `:156-168`) |
| G8-12 | F-10 (sisa Fase 7) sign-off sebelum M7 / sign-off Fase 8, karena sign-off Fase 8 = penutup seluruh development | `:817`, `:833-835` | ✔ | **Sign-off F-10 Horii 16-11-2026**, `main` `fd9e8371` (`docs/fase7/Laporan_Akhir_Fase7.md:293`, §10.6 butir 13); addendum ditutup CR-129 `603af133`. Sekaligus memenuhi H8-2 (helpdesk mulai sesudah F-10 sign-off) |

**Kesimpulan:** semua gerbang mulai helpdesk **terpenuhi** (G8-7 adalah syarat deploy). Izin workload [#9-WorkloadHaloSimpeg] dan
pelaksana sudah diputus (§4.2). A0 (Handling Case) dan B0 (Worker Muse) mulai sesudah CR-130 di `main`; laporan A0/B0
disetujui reviewer sesudah CR-131 (PRD v1.2) di `main`. Merge CR-130/CR-131 atas izin Horii (§5). **CR-136 (16-11-2026):**
CR-130 dan CR-131 di `main` (`ef83356e`); A0 dan B0 disetujui; A1/DBV-028 dibuka. MAKE-070/071 dibuka sesudah DBV-028 di `main`.

## 2. Prasyarat non-teknis (PRD §0.2)

| ID | Kebutuhan | Pemilik | Menahan | Status 16-11-2026 | Bukti / catatan |
|---|---|---|---|---|---|
| PN8-1 | Pemilik app Android mengonfirmasi kunci `data` push Halo Simpeg (Q8-08) dan penulis token Android ke DB v2 | Horii/TL | Payload final MAKE-072, uji push real | ◻ | Tidak ada bukti konfirmasi di repo. Token Android tanpa endpoint v2, "pertanyaan cutover DEPLOY-001.1" (`backend/app/Libraries/Push/README.md:16-17`). Tidak menahan M1–M3; MAKE-072 jalan dengan kunci `data` terbatas (F8-16, `PRD:254`) → H8-8 |
| PN8-2 | F-02 sortir `inbox` legacy selesai, hasil siap diimpor ke `disiplin_ticket(_msg)` | Reviewer/DBV | Impor data tiket (bukan koding) | ✔ kode / ◻ data nyata | MAKE-086 di `main` (`b98e6432`, `7c16b03c`; `docs/fase7/Laporan_Akhir_Fase7.md:44`): tier 13 `backend/app/Libraries/Impor/AturanTiketLama.php:11-16`, perintah `impor:sortir-inbox` (`backend/app/Commands/ImporSortirInbox.php`). Eksekusi data nyata = cutover G7-4 (`Laporan_Akhir_Fase7.md:11-12`, `:162`) |
| PN8-3 | Keputusan impor master `support_officer`, `officer_category`, `help_desk_contact` (Q8-13) + daftar petugas aktif & kategori berlaku | Horii/Biro SDM | Cutover | ✔ keputusan / ◻ data & kode impor | Q8-13 = A (`PRD:859`). Aturan = H8-6 (salin `id_user` apa adanya). Kode impor T12b **belum ada**, menunggu DBV-028 (CR-136: DBV-028 dibuka 16-11-2026; `docs/migrasi/MAKE-069-rencana.md:4`, `:31`, `:108-112`; `backend/app/Libraries/Impor/README.md:159`); key dan pelaksana = H8-12 (MAKE-097, ReadOne). Satker kepegawaian `101` (F8-07, bersyarat Q8-11, `PRD:245`, `:857`) belum dikonfirmasi Biro SDM → H8-7 |
| PN8-4 | Frame Figma halaman yang belum ada (daftar tiket petugas, master kategori/petugas/kontak) | Horii/desainer | QA visual | ✔ (CR-136) | Tidak bisa dicek dari repo. Tanpa frame → ikut pola halaman lain dan dicatat (`AGENTS.md:93-94`) → H8-9. **Ditutup 16-11-2026:** H8-9 dijawab Horii, frame helpdesk belum ada (PRD v1.3 §0.2, §12) |
| PN8-5 | D1 chatbot dari DB dev legacy (blok [1]–[6]) | Horii | DBV-033 | ✔ | `PRD:65` (diterima 12-11-2026; rincian di luar repo) |
| PN8-6 | Keputusan isi QnA awal (K-5) | Horii | Isi awal chatbot saat cutover | ◻ ditunda ke deploy | `PRD:66`, `:271`, `:874` (Trello DEPLOY-002). C4 impor isi QnA dev **keluar dari DoD/sign-off** Fase 8 (H8-13 (b), Horii 16-11-2026); key C4 tetap diberikan saat PN8-6 diputus (F8-51) |
| PN8-7 | Foto avatar Vanya (opsional) | Horii/desainer | Tidak menahan | — | `PRD:67` (SVG bawaan dipakai) |
| PN8-8 | Acuan visual widget dev untuk QA chatbot (AC-E10.22) | Horii | QA Lapis 1 chatbot (AC-E10.22) | ✔ (CR-136) | `PRD:68`. 16-11-2026: diputus acuan = tangkapan layar widget dev dari Horii; **diterima 16-11-2026** (fakta reviewer 16-11-2026), disimpan di luar repo karena memuat foto avatar, tidak di-commit; selisih dibahas di CR-132 bagian A / CR-134 |
| (M0) | Ekstraksi trigger `inbox_msg_afIns` (D1:7730-7739) untuk penanda baca F8-10 | DBA/Horii lewat reviewer | Tetap [I] bila belum ada | ◻ | `PRD:248`, `:863`, R8-4 `:920`; kewajiban A0 (`8_1.md:55`) → H8-10. CR-136: A0 tidak menemukan ekstraksi D1; penanda buka-detail dan sisi pembalas petugas sudah [K] (PRD v1.3 F8-10, §6.2), sisa [I] = sisi lawan |

Prasyarat skema yang **sudah ada** (DBV-022, tidak dibuat ulang, `PRD:410-413`):

| Tabel | Bukti |
|---|---|
| `question_category` | `backend/app/Database/Migrations/2026-10-26-100000_CreateMasterLayananFase4.php:38`, `:145-146` |
| `disiplin_ticket` | `backend/app/Database/Migrations/2026-10-26-100500_CreateDisiplinTicketFase4.php:38`, `:76-100` (FK `id_question_category` RESTRICT `:100`) |
| `disiplin_ticket_msg` (kolom `attachments` TEXT) | `…_CreateDisiplinTicketFase4.php:107-122` |
| FK `nip` ditahan | `backend/app/Database/MigrationsDitahan/AddFkNipLayananFase4.php:48` (`fk_nip_disiplin_ticket_to_pegawai`; keputusan di DBV-028, `PRD:425-426`) |
| Test penjaga dua rumah (BP8-1) | `backend/tests/Layanan/Notifikasi/NotifikasiTidakKeTicketingTest.php`, `backend/tests/database/Layanan/SkemaInboxTicketTest.php` |

Komponen v2 yang dipakai ulang (Lampiran C PRD) ada di `main`: `AturanLampiran`, `ValidasiBerkas`, `LocalStorageAdapter`,
`HtmlSanitizer`, `EksporTabel`, `StempelUtc`, `BaseAuditableModel`, `WebConfigService`, `PetugasLayananService`,
`IntegrasiLogInterface`, `RowActionsMenu`, `ConfirmDialog`, `SafeHtml`. Yang **belum** ada: `Config/HaloSimpeg.php`,
`Config/RoutesHaloSimpeg.php`, berkas DBV-028. `backend/docs/progress/08-HaloSimpeg.md` **sudah ada** (dibuat MAKE-094 `9f9d6052`,
bagian chatbot; bagian helpdesk ditambah aditif, T-6). Folder modul berisi README + subfolder `Chatbot/` milik E-10
(`backend/app/{Controllers/Api,Libraries,Models}/HaloSimpeg/`); di `frontend/src/features/halo-simpeg/` hanya `chatbot/` yang berisi
kode, subfolder helpdesk masih kosong. Kerangka FE helpdesk berdata contoh hanya ada di branch `feat/frontend-ui-redesign` commit
`67fa7678` (`halo.store.ts`, `types.ts`, `HaloDrawer.vue`, `AdminHaloPage.vue`, `__tests__/halo.spec.ts`), sesuai `8_2.md:21-23`.

## 3. Keterkaitan dengan F-10 (sign-off 16-11-2026) dan E-10 (kode di `main`)

### 3.1 Posisi pekerjaan lain

| Paket | Isi | Pelaksana (fakta reviewer 13–16-11-2026) | Status |
|---|---|---|---|
| F-10 MAKE-090 | BE Arsip Tahap 1 | WS-2 | ✔ di `main` `fa1f6686` |
| F-10 MAKE-091 | FE Arsip Tahap 1 | Worker Fix Bug | ✔ di `main` `483c5892` |
| F-10 MAKE-093 | Penyajian berkas legacy (README-deploy §1/§1l, ADR-038, `ReadmeBerkasNginxTest`) | Handling Case | ✔ di `main` (merge `a0418d1f`, 13-11-2026) |
| F-10 CR-122 | PRD F-10 v1.4 | Reviewer | ✔ di `main` `448b302c` (v1.5 CR-123 dan v1.6 CR-128 menyusul, di `main`) |
| F-10 MAKE-092 / MAKE-096 | Tahap 2 BE / FE | WS-2 / Worker Fix Bug | ✔ di `main` (MAKE-092 `f3827106` 14-11-2026, MAKE-096 `07d5a7a2` 15-11-2026); QA Lapis 1 F-10 selesai 15–16-11-2026; perbaikan QA CR-127 `e092c058` dan CR-126 `fd9e8371` di `main`; **F-10 sign-off Horii 16-11-2026**; addendum ditutup CR-129 `603af133` |
| E-10 C0 | Laporan pemahaman E-10 (⛔ reviewer, `8_2.md:74`, PRD `:823`) | ReadOne, Worker Muse | ✔ diterima reviewer 13-11-2026 (fakta reviewer) |
| E-10 C1 | DBV-033 skema chatbot | ReadOne (DB Validator: WS-2) | ✔ merge `3fb8c5f7` (PR #40, 13-11-2026) |
| E-10 C2 | MAKE-094 BE chatbot | ReadOne | ✔ di `main` `4af0c188` (fast-forward 14-11-2026, izin Horii); kontrak beku di `backend/app/Controllers/Api/HaloSimpeg/Chatbot/README.md`; matriks role chatbot di `backend/tests/feature/KontrakMatriksRoleTest.php:60`. Catatan progres C2 di `backend/docs/progress/08-HaloSimpeg.md` diperbarui ReadOne lewat reviewer |
| E-10 C3 | MAKE-095 FE chatbot | Worker Muse | ✔ merge `2a8f405d` (14-11-2026), sesudah MAKE-094 di `main` (→ §6.2 T-7). Menu Chatbot Manager `phase: 8` tanpa `rilis` (`frontend/src/shared/layouts/nav.config.ts:297-312`), widget di `frontend/src/shared/layouts/RedesignShell.vue:198-199` |
| E-10 C4 | Impor isi QnA dev | ReadOne (key saat PN8-6, F8-51) | — keluar dari DoD/sign-off (H8-13 (b), Horii 16-11-2026), dilacak di Trello DEPLOY-002 |
| E-10 QA | QA Lapis 1 chatbot (checklist CR-132), test AC-E10.19 (CR-133), perbaikan temuan (CR-134+) | Reviewer + Worker Muse (QA); usulan: ReadOne (CR-133, BE CR-134+), Worker Muse (FE CR-134+), izin workload pasca-#8 ◻ | ◻ belum mulai; PN8-8 ✔ (acuan visual AC-E10.22 diterima 16-11-2026, CR-136). R10 langkah 2 (migrasi helper chatbot ke `shared/`) ✔ disetujui Horii 16-11-2026, dikerjakan di CR-134 dst. dengan syarat test chatbot 83/83 |
| E-10 rilis | Kenaikan `ACTIVE_PHASE` 5 → 8 (`nav.config.ts:97`) | — | ◻ bersama helpdesk di penutup Fase 8 (H8-11 (a)), paket CR-135 sesudah sign-off atas izin Horii |
| Helpdesk E-01..E-09 | A0–A5, B0–B5, DBV-028, MAKE-070..074, MAKE-097 | §4.2 | ▶ berjalan (CR-136): A0 ✔ disetujui reviewer 16-11-2026, B0 ✔ disetujui final 16-11-2026, A1/DBV-028 dibuka 16-11-2026 (Handling Case, review DB WS-2); belum ada kode helpdesk di `main` |
| G10-4 | Verifikasi Nginx `/berkas/` (rancangan awal: server dev) | Reviewer + Horii | ✔ 16-11-2026 lewat K-12 (Nginx lokal; checklist QA F-10); sisa server di daftar periksa deploy README-deploy §1l (B10-3) |
| B10-1..B10-3 | Blocker deploy/cutover F-10 | Horii/operator | ◻, bukan syarat sign-off (PRD §0 B10-1..B10-3, §11.4) |

Izin [#8-WorkloadChatbot] (E-10) = fakta reviewer 13-11-2026, disetujui Horii 13-11-2026; tidak tercatat di repo. Pekerjaan izin #8
(C0–C3) selesai (fakta reviewer 16-11-2026). Pelaksana CR-133 (ReadOne) dan CR-134+ (ReadOne BE, Worker Muse FE) adalah usulan;
izin workload untuk pekerjaan chatbot pasca-#8 diajukan ke Horii sebelum paket dibuka (◻).

### 3.2 Anti-tabrakan berkas

Helpdesk tidak memakai berkas milik F-10 maupun chatbot (PRD §3 `:163-168`). Titik temu ada di **berkas bersama** (hanya
aditif, commit terpisah, `PRD:180-185`) dan **komponen yang dipakai tanpa diubah**:

| Berkas | Disentuh oleh | Aturan |
|---|---|---|
| `frontend/src/shared/layouts/nav.config.ts` | MAKE-091 (sudah, item `arsip` `:173`), MAKE-095 (sudah, menu Chatbot Manager `:297-312`), MAKE-073 (item `halo` `:320`, belum ber-`to`/`roles`), CR-135 (`ACTIVE_PHASE` `:97`) | Aditif; yang merge kedua rebase dari `origin/main`, reviewer memeriksa konflik (`PRD:176-178`) |
| `frontend/src/router/index.ts` | MAKE-091 (sudah, `arsipRoutes`), MAKE-095 (sudah, `f2060723`), MAKE-073 (route Halo Simpeg) | Sama |
| `frontend/src/shared/layouts/RedesignShell.vue` | MAKE-095 (sudah, widget `:198-199`), MAKE-073 (badge) | Sama |
| `frontend/src/features/auth/stores/auth.store.ts` (`clearSession()` `:52-56`) | MAKE-095 (sudah, `resetChatbot()` `:56`), MAKE-073 (reset store tiket/badge helpdesk) | Aditif; test "store kosong sesudah logout" |
| `backend/app/Config/Routing.php` (`$routeFiles` `:33-61`) | MAKE-094 (sudah, `RoutesChatbot.php` `:61`), MAKE-070 (`RoutesHaloSimpeg.php`) | Satu baris per route file |
| `backend/tests/feature/KontrakMatriksRoleTest.php` | MAKE-094 (sudah, prefiks `halo-simpeg/chatbot` `:60`), MAKE-070 (prefiks helpdesk `halo-simpeg`) | Aditif |
| `backend/app/Config/Services.php` | MAKE-071/072 (binding `ringkasanTiket` `:797-806`, layanan helpdesk) | Aditif; penggantian binding NoOp = T-2. **CR-136:** satu commit berkas bersama MAKE-071 bersama `PusatNotifikasiTest` (assert `tiket_belum_dibaca`) dan README notifikasi BE/FE (PRD v1.3 §3) |
| `backend/app/Config/Queue.php` (`$jobHandlers` `:46-57`) | MAKE-072 (bila job baru, lihat T-1) | Aditif. **CR-136:** tidak ada job baru (T-1 ✔, H8-14 (a)) |
| `backend/app/Libraries/Push/AntrePushPesan.php` (`antre()` `:48`), `PenerimaTokenFcm.php`, `README.md`; `backend/app/Jobs/PushPesanJob.php` (`process()` `:37`) | **CR-137** (CR-136: `antreAkun(id_pengguna[])` aditif, H8-14 (a)) | Commit terpisah di branch MAKE-072; pemanggil `nip[]` lama dan job yang sudah antre tidak berubah; test regresi cuti/LKH/Konket (`PushAndroidCuti.php`, `PushAndroidLkhKonket.php`) |
| `backend/app/Libraries/Lampiran/ManifestLampiran.php` (`KOLOM` `:25-51`) | MAKE-097 (CR-136: baris `disiplin_ticket_msg` + test, T-8) | Aditif; Handling Case tidak menyentuh berkas ini |
| `frontend/src/features/notifikasi/views/NotifikasiPage.vue` | MAKE-073 (CR-136: entri "Tiket Halo Simpeg (n)", H8-15 (b)) | Aditif, commit terpisah; hanya role `NOTIFIKASI_ROLES` (`frontend/src/features/notifikasi/roles.ts:16`) |
| `frontend/src/shared/layouts/AppSidebar.vue` (badge `:258-261`) | MAKE-073 (CR-136: titik penanda saat sidebar ciut) | CR kecil, commit terpisah dalam #9; di `ef83356e` belum ada item ber-badge dan belum ada test badge |
| `frontend/src/shared/utils/galatKolom.ts`, `frontend/src/shared/ui/UiPilihBanyak.vue` (berkas baru) | MAKE-073 (CR-136: R10 langkah 1, dipakai helpdesk) | Aditif, commit terpisah; chatbot tidak disentuh di #9 |
| `frontend/src/features/halo-simpeg/chatbot/galat.ts`, `chatbot/components/admin/TagPilihan.vue` | CR-134 dst. (CR-136: R10 langkah 2, izin Horii 16-11-2026) | Migrasi ke `shared/` lalu hapus versi lokal; syarat test chatbot 83/83. Sampai saat itu duplikasi = deviasi sementara |
| `README-deploy.md` | MAKE-093 (sudah, §1, §1l), MAKE-094 (sudah, §1m kunci token chatbot), MAKE-074 (runbook penutup) | Aditif |
| `backend/docs/progress/08-HaloSimpeg.md` | MAKE-094/095 (bagian chatbot, sudah), MAKE-070..074 dan MAKE-097 (bagian helpdesk) | **Sudah ada** (MAKE-094 `9f9d6052`); jalur lain menambah bagian (T-6 ✔) |
| `EksporTabel.php` | Ekspor arsip F-10 Tahap 2 (lewat `EksporTabel`, `PRD_Fase7_F10_Arsip-Digital.md:234`), E-09, E-10 ekspor tag | **Dipakai, tidak diubah** (`PRD:190-192`); kebutuhan ubah = CR |
| `LocalStorageAdapter.php`, `AturanLampiran.php`, `ValidasiBerkas.php` | MAKE-093 (docblock `LocalStorageAdapter.php:12-13`, komentar saja), MAKE-071 (lampiran tiket, BP8-5) | Dipakai, tidak diubah oleh helpdesk. **CR-136:** Q-A0-6 (a'): hapus lampiran tiket lewat `PenjagaBerkas::catat` + `selesaikan()` (`backend/app/Libraries/Kepegawaian/Lampiran/PenjagaBerkas.php:47`, `:76`), adapter tidak diubah |

Keterkaitan isi: lampiran tiket lama di storage legacy ber-path `arsip/{nip}/inbox/…` (F8-17, `PRD:255`; label [K] dari PRD,
legacy tidak dibuka ulang) cocok dengan regex berkas legacy F-10 `^arsip/(?:[^/.][^/]*/)*[^/.][^/]*$`
(`backend/app/Config/Arsip.php:35-38`), yang disajikan **publik** lewat Nginx `/berkas/` tanpa cek izin (F10-18 (a),
`PRD_Fase7_F10_Arsip-Digital.md:115`). PRD F-10 sudah mencakup hal ini secara umum: R6 (`:366`) menyatakan alias `arsip/` membuka
seluruh subtree salinan MAKE-069 termasuk berkas kolom lain [K], dan QF10-2 (`:641`) mengatur pertanyaan ke Horii **hanya bila**
manifest menunjukkan berkas kolom lain di subtree itu dan Horii ingin mempersempitnya. Lampiran tiket adalah kasus khusus R6/QF10-2;
yang khas adalah isinya percakapan pribadi, sedangkan PRD Fase 8 mewajibkan unduh lewat endpoint ber-otorisasi (BP8-5 `PRD:136`,
R8-6 `:922`). → **H8-5** (✔ (a) ikut legacy, 13-11-2026). Di aplikasi v2, lampiran lama tetap disajikan lewat endpoint E-04
(AC-E04.7) dan dicatat di PRD v1.2 (CR-131).

### 3.3 Satu gate per mesin (3307)

- Gate penuh **satu pada satu waktu per mesin** (`AGENTS.md:138`) dan memakai DB test/scratch sendiri (`AGENTS.md:140-143`,
  port 3307; `backend/.env.example:23-26`, `:38`). Pola F-10: worktree + DB scratch 3307 milik sendiri dengan `.env` minimal
  (`PRD_Fase7_F10_Arsip-Digital.md:82`).
- Gate penuh CI menggantikan gate penuh lokal; di lokal cukup gate cepat (`AGENTS.md:103-106`, `:108-113`). Satu `./check.sh`
  penuh per mesin juga ditegaskan PRD Fase 8 (`PRD:124-125`).
- ✔ **Lewat (16-11-2026):** usulan antrean 13-11-2026 untuk lima aliran (MAKE-093 → MAKE-092/096 → DBV-033/MAKE-094 → MAKE-095 →
  helpdesk) tidak berlaku lagi karena empat aliran pertama sudah di `main`. Aliran yang tersisa: helpdesk (Handling Case, Worker
  Muse), MAKE-097 (ReadOne), dan CR-133/CR-134+ chatbot (pelaksana usulan, izin workload pasca-#8 ◻, §3.1). Urutan gate penuh lokal di antara aliran itu diatur reviewer; A0 dan B0
  tanpa kode, DBV-028 cukup gate cepat + CI.

## 4. Usulan pelaksanaan helpdesk

### 4.1 Jalur dan key (sudah dikunci PRD, Q8-14 = B)

| Jalur | Milestone | Key | Branch | Menunggu | Sumber |
|---|---|---|---|---|---|
| BE | A0 laporan pemahaman | — | — | PRD v1.0 ✔; mulai sesudah CR-130 di `main`; laporan disetujui reviewer sesudah CR-131 (PRD v1.2) di `main` (§5). ✔ **disetujui 16-11-2026** (CR-136) | `8_1.md:55` |
| BE | A1 skema (PR `[DBV]`; CR-136: `[CR][DBV]`) | DBV-028 | `fase8/dbv-028-skema-helpdesk` | A0 ✔ — ▶ dibuka 16-11-2026 | `8_1.md:56` |
| BE | A2 master kategori/petugas/kontak + kontrak beku | MAKE-070 | `fase8/make-070-master-halo` | A1 di `main` | `8_1.md:57` |
| BE | A3 tiket, percakapan, tutup/hapus, lampiran, ringkasan + kontrak beku | MAKE-071 | `fase8/make-071-tiket` | A2 | `8_1.md:58` |
| BE | A4 notifikasi push (CR-136: + CR berkas bersama Push, commit terpisah di branch yang sama) | MAKE-072 + CR-137 | `fase8/make-072-notif-tiket` | A3; MAKE-066 ✔; H8-14 ✔ (a) | `8_1.md:59` |
| BE | A5 ekspor rekap + penutup BE | MAKE-074 | `fase8/make-074-ekspor-penutup` | A3 | `8_1.md:60` |
| FE | B0 laporan pemahaman | — | — | PRD v1.0 ✔; H8-3 ✔ (Worker Muse); mulai sesudah CR-130 di `main`; laporan disetujui reviewer sesudah CR-131 (PRD v1.2) di `main` (§5). ✔ **disetujui final 16-11-2026** (CR-136) | `8_2.md:78` |
| FE | B1–B3, B5 fondasi, pemilik, petugas + admin, penutup | MAKE-073 | `fase8/make-073-fe-halo` | kontrak A2 + A3 beku (CR-136: B1 bagian master boleh mulai sesudah MAKE-070 beku, `8_2.md` §2) | `8_2.md:79-81`, `:83` |
| FE | B4 tombol ekspor | MAKE-074 (FE) | `fase8/make-074-fe-ekspor` | A5 | `8_2.md:82` |
| Impor | T12b impor master helpdesk (salin `id_user` apa adanya, H8-6); CR-136: + baris `disiplin_ticket_msg` di `ManifestLampiran::KOLOM` (T-8) | MAKE-097 | ditetapkan reviewer saat paket dibuka | DBV-028 di `main` | H8-12 (Horii 16-11-2026); Q-A0-5 (reviewer 16-11-2026) |

Key tidak bertabrakan: DBV-028 dicadangkan untuk helpdesk sejak Fase 6/7 (`docs/migrasi/MAKE-069-rencana.md:238`,
`docs/migrasi/Mapping_Migrasi_Tidak_Dimigrasi.md:53`); MAKE-070..074 hanya muncul di `docs/fase8/*` (`git grep`). Key perbaikan
review "CR-095 dst." di header PRD (`PRD:13`) sudah usang: CR-096..CR-129 terpakai paket lain (mis. CR-096 `890eb1df`, CR-129
`603af133`) → T-5 ✔ (key Fase 8 berikutnya dari reviewer: CR-130..CR-135, MAKE-097).

Karena MAKE-066 dan F-08 sudah di `main`, A4 bisa langsung memakai `FcmV1Adapter` + transport palsu (tanpa tahap mock,
`8_1.md:63`), dan A3/A4 menyediakan sumber lonceng (Q8-06 = B).

### 4.2 Kode izin workload **[#9-WorkloadHaloSimpeg]**

Scope (disetujui 13-11-2026, H8-1): Fase 8 helpdesk E-01..E-09 — A0–A5 (`8_1.md` §2), B0–B5 (`8_2.md` §2), review skema DBV-028,
dan berkas bersama aditif PRD §3. **Amandemen 16-11-2026** (Horii, "ikut rekomendasi semua"; fakta reviewer 16-11-2026):
impor master T12b masuk scope sebagai **MAKE-097** (ReadOne, review DB WS-2; H8-12). **Di luar scope:** E-10 (izin
[#8-WorkloadChatbot], pekerjaannya selesai), F-10, C4 impor isi QnA (DEPLOY-002, H8-13 (b)), cutover data.

Anggota: **✔ diputus Horii 13-11-2026 (H8-1)**, diamandemen 16-11-2026 (H8-3, H8-12):

| Peran | Anggota | Sumber |
|---|---|---|
| Jalur BE (A0–A5: DBV-028 penulis, MAKE-070, MAKE-071, MAKE-072, MAKE-074 BE) | **Handling Case** | H8-1 |
| Jalur FE (B0–B5: MAKE-073, MAKE-074 FE) | **Worker Muse**; cadangan dan penanggung perbaikan QA: Worker Fix Bug | H8-3 (amandemen 16-11-2026) |
| DB Validator DBV-028 | **WS-2** | H8-1 (H8-4 ✔) |
| Impor master T12b (MAKE-097) | **ReadOne**; review DB WS-2 | H8-12 (amandemen 16-11-2026) |

Tabel opsi 13-11-2026 di bawah disimpan sebagai catatan. Opsi berdasarkan ketersediaan (fakta reviewer 13-11-2026):

| Peran | Opsi | Ketersediaan | Pertimbangan |
|---|---|---|---|
| Jalur BE (A0–A5) | **(a)** Handling Case | Bebas sesudah MAKE-093 lolos gate ulang dan di-merge | Pernah memegang Jalur C Fase 7: notifikasi F-08 dan sortir inbox F-02 → `disiplin_ticket(_msg)` (`docs/fase7/Laporan_Akhir_Fase7.md:32-33`, `:42`, `:44`), domain paling dekat |
| | (b) Worker Fable / Worker Haiku / WS-1 | Tidak aktif sejak lama | Perlu worktree baru, `composer install` + `npm ci`, DB scratch 3307 + `.env` minimal, dan membaca PRD dari awal |
| | (c) WS-2 sesudah MAKE-092 | Sibuk F-10 Tahap 2 + DB Validator E-10 | Menunda mulai; beban menumpuk |
| Jalur FE (B0–B5) | **(a)** Belum ditunjuk sekarang | — | FE baru koding penuh sesudah kontrak MAKE-070 + MAKE-071 beku (`8_2.md:7-8`); penunjukan bisa menyusul tanpa menahan BE |
| | (b) Worker Fix Bug sesudah MAKE-096 | Sibuk F-10 Tahap 2 | Baru memegang FE F-10 (MAKE-091) dan berkas bersama yang sama |
| | (c) Worker Muse sesudah MAKE-095 | Sibuk E-10 | Satu pelaksana untuk berkas bersama FE (`nav.config.ts`, `RedesignShell.vue`, `router/index.ts`) mengurangi konflik, tetapi berurutan |
| | (d) Pelaksana Jalur BE berlanjut ke FE | — | Sama dengan Q8-14 opsi A (berurutan), menyimpang dari keputusan Q8-14 = B (`PRD:860`) → H8-3 |
| | (e) Worker Fable / Worker Haiku / WS-1 | Tidak aktif sejak lama | Sama dengan BE (b) |
| DB Validator DBV-028 | (a) WS-2 | Sibuk MAKE-092 + review DBV-033 | Konsisten dengan DBV Fase 7 dan E-10; beban tiga paket |
| | (b) DB Validator lain yang ditunjuk Horii | — | Perlu membaca DBV-022 §2.10–2.14, §3 |
| | (c) WS-2, dijadwalkan sesudah review DBV-033 selesai | — | DBV-028 menunggu; A0 tetap bisa jalan |

## 5. Urutan mulai yang disarankan

| Langkah | Isi | Pelaku | Menunggu |
|---|---|---|---|
| 0 | ✔ Horii menyetujui [#9-WorkloadHaloSimpeg] (13-11-2026, H8-1) dan menjawab H8-2, H8-4, H8-5, H8-6 (13-11-2026, H8-4 lewat H8-1) serta H8-3, H8-11, H8-12, H8-13 (16-11-2026, ikut rekomendasi); kartu Trello/Notion Fase 8 disinkron reviewer 16-11-2026 (QASMTASK-123, QASMTASK-124 berlabel Testing-QA-Func, DEV-009 "(10 task)", Notion E.5/E.6 dengan E.6 = Berjalan; §8). Status kartu helpdesk → berjalan saat A0 dimulai (DoD PRD `:836`) | Horii, reviewer CR | — |
| 0a | **CR-130** sinkron dokumen status: dokumen ini + `backend/docs/progress/08-HaloSimpeg.md` (bagian progres BE dari ReadOne, FE dari Worker Muse, lewat reviewer) | Reviewer CR | ⛔ izin merge Horii |
| 0b | **CR-131** PRD Fase 8 v1.2 (H8-5, H8-6, H8-11..H8-13, tabel paket × key × agen × izin, kolom kartu Trello, kontrak helpdesk, angka terukur, AC baru); kontrak helpdesk ditulis sebagai kewajiban A0/MAKE-070 dan H8-14..H8-16 sebagai butir terbuka; nilainya (kontrak beku, angka, keputusan H8-14..16) masuk **PRD v1.3** sesudah langkah 2 | Reviewer CR | ⛔ merge atas izin Horii; laporan A0/B0 disetujui reviewer sesudah CR-131 di `main` (✔ CR-130 dan CR-131 di `main` `ef83356e`) |
| 0c | **CR-136** PRD Fase 8 v1.3 (hasil A0/B0, keputusan Horii dan reviewer 16-11-2026, kontrak §6.1b, angka §7.3a final, indeks §7.1, AC baru) + `8_1.md` (fixture A3), `8_2.md`, dokumen ini, progres 08; key CR berkas bersama Push = **CR-137** | Reviewer CR | Langkah 2 ✔; ⛔ merge atas izin Horii, sebelum README beku MAKE-070/071 |
| 1 | **A0 laporan pemahaman BE** (tanpa commit kode), wajib memuat: trigger `inbox_msg_afIns` (H8-10), rekonsiliasi T-1..T-4, push petugas tanpa NIP (H8-14), tiket impor berkategori NULL (H8-16), daftar berkas bersama §3.2, rujukan `path:baris` PRD yang bergeser (T-3), dampak H8-5 pada E-04 lampiran (lampiran lama tetap lewat E-04), hapus berkas lampiran legacy, usulan kontrak/PATCH status/angka throttle, dan rencana test exit criteria: verifikasi "thread tidak ter-mix" §7.8 + regresi Fase 4 C-05/C-06 + FAQ G-10 (`PRD:833-834`) | Handling Case | Mulai sesudah CR-130 di `main` (langkah 0a); laporan disetujui reviewer sesudah CR-131 (PRD v1.2) di `main` (langkah 0b, 2). ✔ **disetujui reviewer 16-11-2026** (CR-136) |
| 1b | B0 laporan pemahaman FE + rencana berkas bersama (nav, router, shell, `clearSession`, lonceng/H8-15); paralel dengan A0–A2 | Worker Muse | H8-3 ✔; mulai sesudah CR-130 di `main` (langkah 0a); laporan disetujui reviewer sesudah CR-131 (PRD v1.2) di `main` (langkah 0b, 2). ✔ **disetujui final 16-11-2026** (CR-136) |
| 2 | ⛔ Reviewer menyetujui laporan A0 dan B0 dan memutus T-1..T-4; Horii memutus H8-14..H8-16 dari usulan A0 — ✔ **16-11-2026** (PRD v1.3 §12; CR-136 memasukkan hasilnya ke PRD) | Reviewer CR, Horii | Langkah 1, 1b, 0b (CR-131 di `main`) |
| 3 | **DBV-028** (PR `[DBV]`; CR-136: `[CR][DBV]`, reviewer CR merge atas izin Horii): `support_officer`, `officer_category`, `help_desk_contact`, ALTER indeks, keputusan FK `nip`, kueri pra-cek duplikat `help_desk_contact.contact` (`8_1.md:70-71`); klasifikasi kolom DATETIME untuk T12b (`backend/docs/db-review/DBV-029-impor-cutover-tahap2.md:165-166`) | Handling Case + DB Validator WS-2 | Langkah 2 (✔) — ▶ **dibuka 16-11-2026**; indeks dan pra-cek mengikuti PRD v1.3 §7.1 |
| 4 | MAKE-070 master BE → kontrak master beku di README `Controllers/Api/HaloSimpeg` | Handling Case | DBV-028 di `main` |
| 4b | **MAKE-097** T12b impor master helpdesk (salin `id_user` apa adanya, H8-6); CR-136: + baris `disiplin_ticket_msg` di `ManifestLampiran::KOLOM` + test + 2 kueri pra-cek + runbook (T-8) | ReadOne, review DB WS-2 | DBV-028 di `main` |
| 5 | MAKE-071 tiket BE + ringkasan + sumber lonceng (T-2) → kontrak tiket beku (CR-136: README mengikuti PRD v1.3 §6.1b) | Handling Case | MAKE-070; H8-10 (tanpa ekstraksi tetap [I] untuk sisi lawan; sisi pembalas petugas dan buka-detail sudah [K]) |
| 6 | MAKE-073 B1–B3 (FE); CR-136: + berkas bersama `NotifikasiPage.vue` (H8-15), `AppSidebar.vue` (titik penanda), R10 langkah 1, masing-masing commit terpisah | Worker Muse | Kontrak langkah 4 + 5 beku (CR-136: B1 bagian master boleh mulai sesudah MAKE-070 beku, `8_2.md` §2); H8-9 sebelum B3 (✔ 16-11-2026, frame belum ada → pola redesign) |
| 7 | MAKE-072 push (adapter real + transport palsu; payload terbatas sampai PN8-1; penerima sesuai H8-14); CR-136: H8-14 (a) → **CR-137** berkas bersama Push, commit terpisah di branch MAKE-072, test regresi cuti/LKH/Konket | Handling Case | MAKE-071; H8-8 |
| 8 | MAKE-074 BE ekspor + penutup; B4 tombol ekspor; B5 penutup FE | Handling Case + Worker Muse | MAKE-071, MAKE-073 |
| C | Chatbot: CR-132 checklist QA Lapis 1 Fase 8 (bagian A chatbot bisa jalan sekarang), CR-133 test AC-E10.19, CR-134+ perbaikan temuan QA | Reviewer CR + Worker Muse (QA); usulan pelaksana CR-133 ReadOne, CR-134+ ReadOne (BE) / Worker Muse (FE) | PN8-8 ✔ (CR-136, acuan visual AC-E10.22 diterima 16-11-2026); CR-133/CR-134+ menunggu izin workload pasca-#8 dari Horii (◻); R10 langkah 2 ✔ disetujui Horii 16-11-2026, ikut CR-134 dst. |
| 9 | M7: QA Lapis 1 Fase 8 (helpdesk + chatbot), `docs/fase8/Laporan_Akhir_Fase8.md` → ⛔ sign-off Horii (penutup seluruh development) | Reviewer CR, Horii | M6, C3 ✔; F-10 sign-off ✔ (G8-12). C4 bukan syarat (H8-13 (b)) |
| 10 | **CR-135** paket rilis: `ACTIVE_PHASE` 5 → 8, pembersihan `rilis`, item `halo` diberi `to`/`roles`; chatbot dan helpdesk dirilis bersama (H8-11 (a)) | usulan: Worker Muse (atau Worker Fix Bug), diputus Horii saat paket dibuka | Sign-off Fase 8; ⛔ izin Horii |

Setiap milestone: branch baru dari `origin/main` → gate cepat → push → CI hijau → lapor → ⛔ merge oleh reviewer atas izin Horii
per paket (`PRD:829`).

## 6. Keputusan sebelum start

### 6.1 Keputusan Horii

| ID | Pertanyaan | Opsi | Menahan |
|---|---|---|---|
| H8-1 | Setujui kode izin **[#9-WorkloadHaloSimpeg]** dengan scope §4.2, dan siapa anggotanya (Jalur BE, Jalur FE, DB Validator DBV-028)? — ✔ **Horii 13-11-2026: disetujui** ("Boleh, lanjutkan") sesuai usulan reviewer: Jalur BE = Handling Case (sesudah MAKE-093 di main), Jalur FE = ditunjuk sesudah kontrak MAKE-070/071 beku, DB Validator DBV-028 = WS-2 (sesudah review DBV-033). Mulai mengikuti H8-2. Amandemen 16-11-2026: H8-3, H8-12 | §4.2 | Semua |
| H8-2 | Kapan helpdesk mulai? — ✔ **Horii 13-11-2026: (c) sesudah F-10 sign-off.** Helpdesk (A0 dst.) belum dimulai sampai F-10 sign-off; E-10 chatbot tetap berjalan di izin #8. ✔ **Terpenuhi 16-11-2026** (F-10 sign-off, G8-12) | (a) A0 sekarang, paralel dengan F-10 Tahap 2 dan E-10 (A0 tanpa commit kode). (b) Sesudah MAKE-093 merge (Handling Case bebas). (c) Sesudah F-10 sign-off | Langkah 1 |
| H8-3 | Bila pelaksana FE belum tersedia: tetap Q8-14 = B (FE ditunjuk sesudah kontrak beku), atau pindah ke Q8-14 opsi A (satu pelaksana berurutan BE → FE)? — ✔ **Horii 16-11-2026: Worker Muse** sebagai pelaksana FE helpdesk (MAKE-073 + B4), cadangan dan penanggung perbaikan QA Worker Fix Bug; Q8-14 = B tetap. Bentuknya amandemen izin #9 (anggota FE) | B tetap / A | MAKE-073 |
| H8-4 | DB Validator DBV-028 — ✔ **dijawab lewat H8-1 (13-11-2026): WS-2** | §4.2 baris DB Validator (a)/(b)/(c) | DBV-028 |
| H8-5 | **Pemicu QF10-2 untuk subfolder `inbox`.** Lampiran tiket lama ber-path `arsip/{nip}/inbox/…` cocok dengan pola berkas legacy F-10 yang disajikan publik lewat Nginx `/berkas/` (§3.2; R6 `PRD_Fase7_F10_Arsip-Digital.md:366`). Isinya data percakapan pribadi (R8-6 `PRD:922`). Apakah Horii ingin mempersempit alias untuk subfolder `inbox` (QF10-2 `:641`), atau keputusan "persis legacy, URL publik" F10-18 tetap berlaku untuk lampiran percakapan Halo Simpeg? — ✔ **Horii 13-11-2026: (a) ikut legacy** — lampiran tiket lama di `arsip/{nip}/inbox/…` tetap tersaji lewat alias publik F-10 seperti legacy (F10-18 berlaku; QF10-2 tidak mempersempit). Unggahan tiket baru v2 tetap privat (BP8-5). Dicatat sebagai risiko sadar (R8-6) dan ADR-038 | (a) Ya, berlaku (ikut legacy). (b) Dikecualikan: subfolder `inbox` tidak disajikan Nginx dan lampiran lama hanya lewat endpoint ber-otorisasi E-04 (perlu CR ke README-deploy §1l / `Config\Arsip` / MAKE-069). (c) Diputus saat cutover, koding E-04 memakai endpoint ber-otorisasi untuk semua lampiran | E-04 lampiran, cutover, README-deploy §1l |
| H8-6 | **Impor master T12b**: F8-21 menulis `id_user` dipetakan lewat tabel pemetaan UP-1 dan baris tanpa pemetaan dilaporkan (`PRD:259`); rencana MAKE-069 menulis `id_user` disalin langsung tanpa tabel pemetaan (K-M11) dan id yang tidak ada dibuang + dicatat jumlahnya (K-M12) (`docs/migrasi/MAKE-069-rencana.md:110-111`). Mana yang berlaku, dan key/pelaksana kode T12b sesudah DBV-028? — ✔ **Horii 13-11-2026: "seperti legacy saja"** — `id_user` master helpdesk disalin apa adanya dari legacy (rencana MAKE-069 K-M11/K-M12: tanpa tabel pemetaan UP-1; id yang tidak ada di `pengguna` v2 dibuang + jumlahnya dicatat). F8-21 diselaraskan di PRD v1.2 (CR-131). Key/pelaksana → H8-12 | (a) Ikut K-M11/K-M12 (PRD dikoreksi lewat CR). (b) Ikut F8-21. Key: bagian MAKE-074 (penutup + runbook) atau paket MAKE-069 lanjutan | Cutover; isi DBV-028 tidak berubah |
| H8-7 | ◻ Konfirmasi Biro SDM bahwa satker `101` = satker kepegawaian (syarat F8-07/Q8-11). 16-11-2026: dijawab **sebelum QA** | — | Data kandidat petugas saat QA/cutover (kode memakai `id_satker_kepegawaian`) |
| H8-8 | ◻ Siapa menghubungi pemilik app Android untuk PN8-1 (kunci `data` push + penulis token Android, DEPLOY-001.1)? 16-11-2026: dijawab **sebelum MAKE-072**; bila belum ada, MAKE-072 memakai payload terbatas dan uji push real (G8-7) dicatat ke DEPLOY | — | Payload final MAKE-072, uji push real |
| H8-9 | ✔ Apakah ada frame Figma untuk daftar tiket petugas dan master kategori/petugas/kontak (PN8-4)? 16-11-2026: dijawab **sebelum B3** — ✔ **Horii 16-11-2026: belum ada**; daftar tiket petugas = master-detail ala Gambar 34, master = pola Master Data/PIC layanan, tiap layar tanpa frame dicatat, AC-E08.7 diuji terhadap pola redesign; gerbang B3 terpenuhi | Ada (serahkan) / tidak (ikut pola, `AGENTS.md:93-94`) | QA visual |
| H8-10 | ◻ Apakah ekstraksi trigger `inbox_msg_afIns` (D1:7730-7739) bisa diberikan sebelum MAKE-071? 16-11-2026: dijawab **sebelum MAKE-071**. CR-136: tetap ◻, tetapi sebagian sudah [K] dari kode legacy menurut laporan A0: membuka detail menyetel sisi pembuka = 1 (pemilik → `is_read`, petugas → `is_read_off`; di v2 lewat `POST tiket/{id}/baca`), dan balasan petugas menyetel `is_read_off=1`. Yang tetap [I] tanpa ekstraksi hanya penyetelan sisi lawan ke 2 dan sisi pembalas pemilik (PRD v1.3 F8-10, §6.2) | Ya / tidak (tetap [I], diuji per skenario AC-E04.4) | Penanda baca F8-10 |
| H8-11 | Gerbang rilis menu Halo Simpeg dan badge: PRD §6.3 hanya menyebut item `halo` `phase: 8` (`PRD:338`), sedangkan `ACTIVE_PHASE` = 5 (`frontend/src/shared/layouts/nav.config.ts:97`). Ikut pola F8-50 chatbot (QA lewat URL langsung, menu dibuka saat `ACTIVE_PHASE` dinaikkan di penutup rilis)? Preseden opsi (a): Fase 7 mencatat `ACTIVE_PHASE` dinaikkan ke 7 hanya saat rilis (`docs/fase7/Laporan_Akhir_Fase7.md:168`), F-10 ikut gerbang Fase 7 (QF10-3, `PRD_Fase7_F10_Arsip-Digital.md:642`), dan kenaikan konstanta = keputusan Horii/reviewer di penutup rilis (F8-50 `PRD:907`) — ✔ **Horii 16-11-2026: (a)** chatbot dan helpdesk dirilis bersama di penutup Fase 8, paket rilis CR-135 sesudah sign-off atas izin Horii. Item `halo` (`nav.config.ts:320`) diberi `to`/`roles` di MAKE-073/CR-135 | (a) Ikut F8-50. (b) Menu `rilis: true` saat MAKE-073 masuk `main` | MAKE-073 B1, CR-135 |
| H8-12 | Key dan pelaksana T12b (impor master helpdesk) — ✔ **Horii 16-11-2026: (b)** paket baru **MAKE-097** oleh ReadOne, review DB oleh WS-2, mulai sesudah DBV-028 di `main`. Bentuknya amandemen scope izin #9 (§4.2) | (a) Bagian MAKE-074 oleh Handling Case. (b) Paket MAKE-069 lanjutan dengan key baru MAKE-097 oleh ReadOne | MAKE-097, cutover |
| H8-13 | C4 impor isi QnA dev vs sign-off — ✔ **Horii 16-11-2026: (b)** C4 keluar dari DoD/sign-off Fase 8 dan dilacak di Trello DEPLOY-002; key C4 tetap diberikan saat PN8-6 diputus (F8-51). DoD/M7 di PRD ditulis ulang di v1.2 (CR-131) | (a) C4 dibuat sebelum sign-off sebagai pengaman. (b) C4 dikeluarkan dari DoD dan dilacak di DEPLOY-002 | DoD M7 |
| H8-14 | ✔ **Penerima push petugas tanpa NIP** (AC-E06.7): `AntrePushPesan` berbasis NIP (README-deploy `:257`, "per NIP"), padahal F8-07/F8-13/AC-E06.7 mewajibkan petugas role 1 (tidak ber-NIP) ikut menerima push. Diputus **sesudah A0** Handling Case (Horii 16-11-2026). Rekomendasi reviewer: **(a)**, memakai titik sambung yang ada tanpa menduplikasi job; (c) hanya bila pengurangan fungsi diterima. Terkait T-1. ✔ **Horii 16-11-2026: (a)** `antreAkun(id_pengguna[])` aditif; berkas Push bersama = **CR-137**, commit terpisah di branch MAKE-072, test regresi cuti/LKH/Konket | (a) Penerima per `id_pengguna` lewat `push-pesan` + CR berkas bersama Push. (b) Job Halo Simpeg tersendiri (rencana PRD `:158`, `:366`). (c) AC dipersempit ke "petugas ber-NIP" | MAKE-072 |
| H8-15 | ✔ **Tujuan klik angka lonceng F-08 untuk tiket.** Diputus **sesudah A0** (bersama B0). Rekomendasi reviewer: **entri panel menuju route Halo Simpeg**, supaya angka bisa ditelusuri. ✔ **Horii 16-11-2026: (b)** entri "Tiket Halo Simpeg (n)" di `NotifikasiPage.vue` (tab antrean bila sisi petugas > 0); role 4/5 lewat badge menu | (a) Badge menu saja (pola berita F-07). (b) Entri "Tiket Halo Simpeg (n)" di panel lonceng menuju route Halo Simpeg | MAKE-071 (ringkasan), MAKE-073 |
| H8-16 | ✔ **Tiket impor berkategori NULL** (`backend/app/Libraries/Impor/AturanTiketLama.php:73`, `:119`, `:132`) tidak punya pemilik antrean. Diputus **sesudah A0** dari usulan Handling Case. Rekomendasi reviewer: (a) bila cukup tanpa mengubah data; opsi (b)/(c) mengubah data sehingga wajib keputusan Horii. ✔ **Horii 16-11-2026: (a1)** ikut legacy tanpa ubah data — hanya pemilik melihat dan menutup, balas 422, hapus oleh pemilik 403, tanpa perluasan role 1 (opsi (a) "role 1" gugur), jumlah + irisan sender yatim dicatat runbook | (a) Hanya terlihat role 1. (b) Kategori penampung saat impor. (c) Ditutup otomatis saat cutover | MAKE-071, impor tiket saat cutover |

PN8-8 (acuan visual widget dev, §2): ✔ tangkapan layar widget dev diterima dari Horii 16-11-2026 (CR-136); disimpan di luar repo, tidak di-commit.

### 6.1a Butir turunan A0/B0 yang diputus Horii 16-11-2026 (CR-136)

Horii memutus semua butir di bawah dengan "ikut rekomendasi semua" (16-11-2026, diteruskan reviewer; fakta reviewer 16-11-2026).
Isi lengkap ada di PRD v1.3 (§6.1b kontrak, §6.3 FE, §7.3a angka, §12 register).

| Butir | Keputusan | Paket |
|---|---|---|
| K-A4 hapus petugas | ✔ (1'): hapus → 422 `petugas_terakhir` bila petugas itu petugas terakhir (aktif atau nonaktif) sebuah kategori yang punya tiket Open; Nonaktifkan → 200 + `peringatan`; respons `Petugas` memuat `bisa_hapus` + `alasan_tidak_bisa_hapus` (⋮ Hapus `disabled`) | MAKE-070, MAKE-073 |
| Q-A0-6 hapus lampiran legacy | ✔ (a'): `PenjagaBerkas::catat` di dalam transaksi + `selesaikan()` sesudah commit (hitung rujukan); berkas anomali tertinggal dan tercatat; adapter tidak diubah | MAKE-071 |
| Q-A0-8 angka (PRD v1.3 §7.3a) | ✔ throttle tiket 5/10 menit, pesan 20/menit; `subject` 250, `msg` 5.000 (`mb_strlen` sesudah trim, code point); nama kategori 250; petugas email 150, `no_hp` 20; kontak `name` 150, `contact` 20, `email` opsional ≤ 150 ([I] sampai DBV-028); ekspor 10.000 baris + batas ukuran `SUM(CHAR_LENGTH(msg))` (ambang diukur di MAKE-074) → 422 `ekspor_terlalu_besar` teks netral; isi sel dipotong 32.767 [V2] | MAKE-070, MAKE-071, MAKE-074 |
| Penyamaran D7 | ✔ (a) legacy per layar: daftar `pesan_terakhir.pengirim_label` "Admin" bagi semua peninjau bila pengirim bukan pemilik [K VI:110-117]; detail "Admin" bagi peninjau bukan role 1 bila pengirim bukan pemilik [K VD:48-55]; id akun petugas tidak dikirim ke non-role-1 | MAKE-071 |
| Badge D8 | ✔ (a): `ringkasan.badge` = distinct(belum dibaca ∪ `perlu_tindak`); `total` tetap = angka lonceng (AC-E06.9) | MAKE-071, MAKE-073 |
| Bawaan filter baca | ✔ tab "Tiket saya" = `semua` [V2]; antrean = `belum` [K] | MAKE-073 |
| Indikator sidebar ciut | ✔ titik penanda; CR kecil berkas bersama `AppSidebar.vue` dalam #9, commit terpisah | MAKE-073 |
| R10 helper bersama FE | ✔ langkah 1 (`shared/utils/galatKolom.ts`, `shared/ui/UiPilihBanyak.vue`, aditif, dipakai helpdesk) dalam #9; langkah 2 (migrasi chatbot ke `shared/`) disetujui, dikerjakan bersama paket perbaikan QA chatbot (CR-134 dst.), syarat test chatbot 83/83 (VanyaWidget 36, chatbot.amplop 21, ChatbotManager 23, VanyaShell 3) | MAKE-073; CR-134 dst. |
| Q-A0-9 / F8-20 ekspor | ✔ `ds`/`de` hari kalender WIB → rentang UTC; kolom waktu ditulis WIB; setiap unduhan diaudit (AC-E09.7); ekspor kosong tidak diaudit | MAKE-074 |

Keputusan reviewer CR 16-11-2026 atas A0/B0 (bukan keputusan bisnis, PRD v1.3 §6.1b): Q-A0-4 ikut PRD §6.2 (semua tiket milik
sendiri dikecualikan dari sisi petugas); Q-A0-5 = T-8; Q-A0-7 kontak dua path `GET kontak/aktif` (role 1–7, array) dan
`GET kontak` (role 1, `Halaman<T>`); input multipart bernama legacy, respons bernama Indonesia berobjek (`kategori: {id, nama}|null`);
ekspor kosong = 200 JSON `{kosong: true, jumlah: 0}` tanpa `Content-Disposition`; filter `baca=semua|belum|sudah`;
`urutan_pengirim` per tiket (0 = pemilik) mengganti kunci HMAC; `bisa_ekspor` = petugas aktif; `ditutup_oleh_label` ikut aturan
detail D7 (a); `snapshot` bernilai `null` bagi pemilik/non-petugas; urutan daftar dan pesan terakhir dengan pemecah seri; layar
petugas master-detail Gambar 34 dengan ⋮ Lihat detail → Tutup → Hapus (tanpa listbox, tanpa pengecualian AGENTS.md; < 1024 px dua
halaman = responsif).

### 6.2 Keputusan teknis reviewer saat M0 (bukan keputusan bisnis)

| ID | Butir | Bukti | Usulan |
|---|---|---|---|
| T-1 | ✔ (CR-136: H8-14 (a), `PushHaloSimpegJob` gugur, PRD v1.3 §6.4) PRD merencanakan job baru `push-halo-simpeg` / `PushHaloSimpegJob` (`PRD:158`, `:366`), padahal MAKE-066 sudah menyediakan `AntrePushPesan` sebagai titik sambung Halo Simpeg ke job `push-pesan` (Android) (`backend/app/Libraries/Push/README.md:11-15`; `README-deploy.md:257`) | PRD ditulis sebelum MAKE-066 | A0 membandingkan kedua jalur bersama H8-14; BP8-1 (job terpisah dari `push-notif`) tetap dijaga |
| T-2 | ✔ (CR-136: `RingkasanTiket` di MAKE-071, commit binding ikut memperbarui `PusatNotifikasiTest` + README notifikasi, PRD v1.3 §3, §6.2) Implementasi `RingkasanTiketInterface` dan penggantian binding NoOp di `Config/Services.php:797-806` belum disebut di PRD/8_1 | `RingkasanTiketInterface.php:13`; `Laporan_Akhir_Fase7.md:147-148` | Masuk MAKE-071 bersama `GET tiket/ringkasan` (aturan hitung §6.2 PRD sama); AC baru di PRD v1.2 (CR-131) |
| T-3 | ✔ (CR-136: A0 mengecek rujukan di `ef83356e`; di luar `docs/fase8/`, `git diff 603af133 ef83356e` hanya mengubah `README-deploy.md`, ADR-038, dan progres 08) Rujukan `path:baris` PRD bergeser karena MAKE-091 dan MAKE-094/095. Di `603af133`: item `halo` `nav.config.ts:301` → `:320`, `ACTIVE_PHASE` `:94` → `:97`, semantik `rilis` `:120-124` → `:123-125`, pola Integrasi `:293` → `:296` (`PRD:294`, `:357`, `:1081`); `RedesignShell.vue:186`, `:188` → `<main>` `:189`, widget `:198-199`; `Config/Routing.php:34-59` → `:33-61` | `git diff 241ef9e5 603af133` | Dicatat di laporan A0; PRD dikoreksi di v1.2 (CR-131) |
| T-4 | ✔ (CR-136: `halo-simpeg/{YYYY}/{MM}/{32 hex}.{ext}`, PRD v1.3 F8-17) Path lampiran v2 usulan `halo-simpeg/{nip}/{id_inbox}/…` (F8-17) berbeda dengan pola kunci unggahan v2 `YYYY/MM/<32 hex>.<ext>` yang dipakai F-10 (F10-18 (b), `PRD_Fase7_F10_Arsip-Digital.md:115`) | — | A0 memilih satu pola; keduanya tidak cocok regex Nginx legacy, jadi tetap privat |
| T-5 | ✔ Key perbaikan review/QA Fase 8 "CR-095 dst." (`PRD:13`) usang. **Diputus reviewer 16-11-2026:** CR-130 sinkron status, CR-131 PRD v1.2, CR-132 checklist QA Lapis 1 Fase 8, CR-133 test AC-E10.19, CR-134+ perbaikan QA chatbot, CR-135 rilis menu; MAKE-097 T12b | CR-096 `890eb1df` s.d. CR-129 `603af133` sudah terpakai | Header PRD dikoreksi di v1.2 (CR-131); key berikutnya tetap dari reviewer |
| T-6 | ✔ Siapa membuat `backend/docs/progress/08-HaloSimpeg.md` pertama kali. **Terjawab:** dibuat jalur chatbot (MAKE-094 `9f9d6052`, 13-11-2026) dengan pembuka "tiap jalur menambah bagiannya sendiri secara aditif" (`backend/docs/progress/08-HaloSimpeg.md:3-5`) | `git log --diff-filter=A` | Helpdesk menambah bagian sendiri; isi progres chatbot diperbarui di CR-130 lewat reviewer |
| T-7 | ✔ C3 (MAKE-095) berjalan sebelum DBV-033/MAKE-094 di `main`. **Tertutup:** kontrak MAKE-094 dibekukan di `fd1e74ed`, MAKE-095 dicocokkan ke kontrak beku (`355dc032`), `main` berisi MAKE-094 digabung ke branch MAKE-095 (`dfabe84e`), lalu merge `2a8f405d` | `8_2.md:70`; `git log 483c5892..2a8f405d` | — |
| T-8 | ✔ (CR-136) `disiplin_ticket_msg.attachments` tidak tercantum di `ManifestLampiran::KOLOM` (`backend/app/Libraries/Lampiran/ManifestLampiran.php:25-51`), sehingga `lampiran:manifest`/`lampiran:tarik` tidak menyalin lampiran tiket lama (= temuan T-5 laporan A0) | laporan A0 | Diputus reviewer 16-11-2026 (Q-A0-5): baris + test + 2 kueri pra-cek + runbook masuk **MAKE-097 (ReadOne)**; Handling Case tidak menyentuh berkas itu (PRD v1.3 §3) |

## 7. Risiko

| ID | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| RK-1 | Konflik di berkas bersama FE (`nav.config.ts`, `router/index.ts`, `RedesignShell.vue`, `auth.store.ts`). MAKE-092/096 dan MAKE-095 sudah di `main`; tersisa MAKE-073 dan CR-135 | Rebase berulang, menu/route hilang saat merge | Aditif + commit terpisah; yang merge kedua rebase; reviewer memeriksa (`PRD:176-178`); test `nav.config.spec.ts`. Satu pelaksana FE (Worker Muse, H8-3) untuk MAKE-073 dan MAKE-095 mengurangi konflik |
| RK-2 | Konflik di berkas bersama BE (`Config/Services.php`, `Config/Routing.php`, `Config/Queue.php`, `KontrakMatriksRoleTest.php`, `README-deploy.md`, progres 08) | Sama | Sama; T-1, T-2 diputus di A0; T-6 ✔. **CR-136:** T-1 ✔, T-2 ✔; daftar berkas bersama final di PRD v1.3 §3 dan §3.2 di atas (CR-137 Push, binding `RingkasanTiket` + `PusatNotifikasiTest`, `ManifestLampiran` di MAKE-097), tiap berkas commit terpisah |
| RK-3 | ✔ **Lewat (16-11-2026).** Antrean gate penuh lokal lima aliran di satu mesin (3307): empat aliran sudah di `main` (§3.3) | — | — |
| RK-4 | ✔ **Lewat (16-11-2026).** Beban WS-2 (MAKE-092 + DB Validator DBV-033): keduanya selesai; WS-2 kini memegang DBV-028 dan review DB MAKE-097 (H8-4 ✔, H8-12 ✔) | — | — |
| RK-5 | Lampiran tiket lama terbuka lewat URL publik `/berkas/arsip/…` | Isi percakapan (data pribadi) bisa diunduh tanpa login | H8-5 ✔ (a) ikut legacy: diterima sebagai risiko sadar (R8-6, ADR-038), dicatat di PRD v1.2 (CR-131); di aplikasi v2 lampiran tetap disajikan lewat endpoint E-04 (BP8-5) |
| RK-6 | ✔ **Lewat (16-11-2026, CR-136).** Desain PRD Fase 8 mendahului implementasi MAKE-066 dan F-08: T-1, T-2, H8-14 diputus; `PushHaloSimpegJob` gugur, binding `RingkasanTiket` masuk MAKE-071 | — | — |
| RK-7 | Komponen yang wajib dipakai tanpa diubah (`EksporTabel`, `LocalStorageAdapter`, `AturanLampiran`, `ValidasiBerkas`, `HtmlSanitizer`) diminta berubah oleh E-09 atau hapus lampiran legacy E-05 | Regresi lintas modul | Perubahan hanya lewat CR reviewer (`PRD:190-192`); regresi gate penuh. **CR-136:** hapus lampiran legacy tanpa mengubah adapter (Q-A0-6 (a'), `PenjagaBerkas`); batas ekspor dijaga di service helpdesk sebelum `EksporTabel` dipanggil (PRD v1.3 §7.3a, §7.9) |
| RK-8 | Pelaksana dari agen yang lama tidak aktif bekerja dengan dependensi basi atau `.env` yang salah | Gate lolos palsu atau menyentuh DB yang salah | Worktree baru dari `origin/main`, `composer install` + `npm ci`, `.env` minimal 3307 (`backend/.env.example:23-26`) |
| RK-9 | Impor master T12b belum punya kode | Petugas/kategori tidak terbawa saat cutover | Aturan diputus (H8-6 ✔), key dan pelaksana diputus (H8-12 ✔ MAKE-097 ReadOne); MAKE-097 disusun sesudah DBV-028 di `main`. **CR-136:** lampiran tiket lama ikut tersalin sesudah baris `disiplin_ticket_msg` di `ManifestLampiran::KOLOM` (T-8, MAKE-097) |
| RK-10 | Risiko PRD R8-1..R8-17 (`PRD:913-933`) tetap berlaku, terutama R8-1 (payload Android), R8-4 (trigger), R8-6 (privasi) | — | PN8-1, H8-10, BP8-2/BP8-5. **CR-136:** R8-4 sebagian [K] (H8-10, §6.1); PRD v1.3 menambah R8-21..R8-24 (identitas petugas lewat API, memori ekspor, duplikasi helper R10, README beku menyimpang dari §6.1b) |
| RK-11 | (CR-136) CR-137 mengubah berkas Push bersama (`AntrePushPesan`, `PushPesanJob`, `PenerimaTokenFcm`) yang dipakai push cuti/LKH/Konket | Push Android modul lain gagal atau ganda; job `nip[]` yang sudah antre tidak terkirim | Method `antreAkun` aditif, `antre(nip[])` tetap; commit terpisah di branch MAKE-072; test regresi `PushAndroidCuti`/`PushAndroidLkhKonket` + job lama `nip[]` + dedup token (PRD v1.3 §6.4) |
| RK-12 | (CR-136) Tiket impor berkategori NULL milik pengirim yatim tidak terlihat siapa pun (H8-16 (a1), tanpa ubah data) | Tiket Open menggantung di data lama | Jumlah tiket NULL dan irisan NULL + Open + sender yatim dicatat runbook MAKE-074 lewat kueri pra-cek (ditulis, tidak dijalankan di dev); bila irisan > 0, Horii dapat memilih tutup otomatis khusus irisan itu saat cutover (laporan A0) |
| RK-13 | (CR-136) Duplikasi sementara `chatbot/galat.ts` + `TagPilihan.vue` dengan versi `shared/` (R10) | Perbaikan di satu versi tidak ikut di versi lain | R8-23: langkah 2 di CR-134 dst. dengan syarat test chatbot 83/83; deviasi sementara dicatat di README `features/halo-simpeg` dan laporan B1 |

## 8. Pemetaan kartu Trello / Notion Fase 8

Kartu Trello di list "Fase 8 — Halo Simpeg", epik **DEV-009 (10 task)**. Baris Notion = database timeline "SIMPEG v2 — Timeline
Proyek" (baris E.1–E.4 berasal dari timeline Excel; E.5 dan E.6 ditambahkan 16-11-2026). Sinkron Trello/Notion terakhir dijalankan
reviewer 16-11-2026 (akun Horii terverifikasi; fakta reviewer 16-11-2026). PRD Fase 8 menambah kolom kartu per task di v1.2 (CR-131).
Kolom status diperbarui CR-136 (A0 ✔, B0 ✔, A1/DBV-028 dibuka); status kartu helpdesk di Trello/Notion menjadi berjalan saat A0
dimulai (DoD PRD `:836`), dan sinkron kartu sesudah A0/B0 dicatat reviewer saat dijalankan (◻).

| Fitur | Kartu Trello | Baris Notion | Paket key | Agen | Status 16-11-2026 |
|---|---|---|---|---|---|
| E-01 Skema pendukung helpdesk | QASMTASK-111 | E.1 | A0 (tanpa key) → DBV-028 | Handling Case (penulis), WS-2 (DB Validator) | ▶ A0 ✔ 16-11-2026; DBV-028 dibuka 16-11-2026 (PR `[CR][DBV]`) |
| E-02 Master kategori & petugas | QASMTASK-112 | E.2 | MAKE-070 | Handling Case | ◻ belum mulai (kontrak disetujui di A0; menunggu DBV-028 di `main`) |
| E-03 Buat tiket | QASMTASK-113 | E.2 | MAKE-071 | Handling Case | ◻ belum mulai (kontrak disetujui di A0) |
| E-04 Thread percakapan | QASMTASK-114 | E.3 | MAKE-071 | Handling Case | ◻ belum mulai (kontrak disetujui di A0; H8-10 ◻) |
| E-05 Tutup/hapus tiket | QASMTASK-115 | E.3 | MAKE-071 | Handling Case | ◻ belum mulai (H8-16 ✔ (a1), Q-A0-6 ✔ (a')) |
| E-06 Notifikasi tiket (push + lonceng F-08) | QASMTASK-116 | E.3 | MAKE-072 + CR-137 (berkas bersama Push, CR-136); binding ringkasan lonceng di MAKE-071 (T-2) | Handling Case | ◻ belum mulai (H8-14 ✔ (a), H8-15 ✔ (b); H8-8 ◻) |
| E-07 Kontak helpdesk | QASMTASK-117 | E.4 | MAKE-070 | Handling Case | ◻ belum mulai (dua path kontak, keputusan reviewer 16-11-2026) |
| E-08 Frontend Halo Simpeg | QASMTASK-118 | E.4 | B0 (tanpa key) → MAKE-073 (B1–B3, B5; berkas bersama `NotifikasiPage.vue`, `AppSidebar.vue`, R10 langkah 1, commit terpisah) | Worker Muse (cadangan Worker Fix Bug) | ▶ B0 ✔ 16-11-2026; B1 dibuka sesudah kontrak MAKE-070 beku di README `Controllers/Api/HaloSimpeg`; bagian B1 yang memakai kontrak tiket (service/store tiket, ringkasan, badge) serta B2–B3 menunggu README MAKE-071 beku; H8-9 ✔ (frame belum ada) |
| E-09 Ekspor rekap petugas | QASMTASK-123 | E.5 | MAKE-074 (BE + penutup), MAKE-074 FE (B4) | Handling Case (BE), Worker Muse (FE) | ◻ belum mulai (angka dan Q-A0-9 ✔ 16-11-2026) |
| E-10 Chatbot "Vanya" | QASMTASK-124 (label diganti ke Testing-QA-Func 16-11-2026, sinkron reviewer; keputusan Horii 16-11-2026) | E.6 (Berjalan) | DBV-033 ✔ `3fb8c5f7`, MAKE-094 ✔ `4af0c188`, MAKE-095 ✔ `2a8f405d`; CR-133; CR-134+; C4 (DEPLOY-002) | ReadOne (BE), Worker Muse (FE), WS-2 (DB Validator DBV-033); usulan: ReadOne (CR-133, BE CR-134+), Worker Muse (FE CR-134+), izin workload pasca-#8 ◻ | Kode di `main`, menunggu QA Lapis 1 (PN8-8 ✔ 16-11-2026, CR-136); R10 langkah 2 ikut CR-134 dst. |
| Impor master helpdesk T12b (+ `ManifestLampiran` T-8, CR-136) | ◻ belum ditetapkan | — | MAKE-097 | ReadOne, review DB WS-2 | ◻ menunggu DBV-028 di `main` |
| PRD Fase 8 v1.3 + dokumen Fase 8 (CR-136) | — (dokumen) | — | CR-136 | Reviewer CR | ▶ disusun 16-11-2026; ⛔ merge atas izin Horii, sebelum README beku MAKE-070/071 |
| Berkas bersama Push (CR-137, CR-136) | — (ikut kartu E-06) | — | CR-137 | Handling Case | ◻ di branch MAKE-072, commit terpisah |
| QA Lapis 1 Fase 8 (helpdesk + chatbot) | ◻ belum ditetapkan | QAU8, QAF8 (timeline Excel: QAUI-007, QAFUNC-009, MT-008) | CR-132 (checklist) | Reviewer CR + Worker Muse | ◻ bagian A chatbot bisa mulai; bagian B sesudah MAKE-073 |
| Rilis menu (`ACTIVE_PHASE` 5 → 8) | — | — | CR-135 | usulan: Worker Muse (atau Worker Fix Bug), diputus Horii saat paket dibuka | ◻ sesudah sign-off Fase 8, izin Horii (H8-11 (a)) |
| Di luar DoD Fase 8: C4 impor isi QnA, uji push real (G8-7) | DEPLOY-002 (C4); DEPLOY-001.1 (token Android) | — | C4 (key saat PN8-6, F8-51) | ReadOne (C4) | — ditunda ke deploy (H8-13 (b)) |

Ringkasan FASE 8 di Notion per 16-11-2026: 0/6 baris timeline selesai, 0/10 task Done (DEV-009).
