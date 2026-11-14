# Kesiapan Fase 8 — Halo Simpeg (helpdesk E-01..E-09) dan E-10 Chatbot "Vanya"

| Butir | Isi |
|---|---|
| Dokumen | `docs/fase8/Kesiapan_Fase8.md` — daftar periksa **sebelum** helpdesk Halo Simpeg dimulai. Tidak mengubah PRD, tidak membuka koding, dan tidak menunjuk pelaksana |
| Acuan | PRD Fase 8 **v1.1** (`docs/fase8/PRD_Fase8_HaloSimpeg.md`: §0 gerbang & PN8-1..PN8-8, §1, §3, §10, §11, §12, §13), [`8_1.md`](8_1.md) (Jalur BE), [`8_2.md`](8_2.md) (Jalur FE), Laporan Akhir Fase 7 (`docs/fase7/Laporan_Akhir_Fase7.md`), PRD F-10 v1.3 (`docs/fase7/PRD_Fase7_F10_Arsip-Digital.md`) dan v1.4 (branch `docs/cr-122-prd-f10-v14` @`448b302c`) |
| Basis kode | `main` = **`483c5892`** (branch kerja `docs/cr-123-f10-qa-kesiapan-f8`). Rujukan `path:baris` di bawah sudah dicek di basis ini, kecuali yang disebut berasal dari branch lain |
| Disusun | 13-11-2026, reviewer CR |
| Status | **Draf kesiapan.** Butir ◻ di §6 menunggu keputusan Horii; butir teknis ◻ di §6.2 diputus reviewer saat M0 |

Tanda: **✔** terpenuhi (dengan bukti), **◻** belum terpenuhi / menunggu keputusan, **⛔** titik tunggu, **—** tidak berlaku.
Label **[K]** / **[V2]** / **[I]** mengikuti PRD Fase 8 §Cara pakai 5. Fakta yang hanya berasal dari laporan sesi (tidak tercatat di
repo) diberi catatan "fakta reviewer 13-11-2026".

## 0. Ringkasan

```
Status: Fase 7 SIGN-OFF Horii 11-11-2026 (main 0af9be4f), F-10 dikecualikan lalu dibuka kembali 12-11-2026.
        Gerbang koding helpdesk (PRD §0.1) TERPENUHI: PRD v1.0 dan v1.1 di main, MAKE-066 (FCM) di main,
        F-08 lonceng di main, F-02 sortir inbox (kode) di main, tabel disiplin_ticket(_msg) + question_category ada.
        E-10 berjalan (izin [#8-WorkloadChatbot]); helpdesk BELUM dimulai dan BELUM punya kode izin workload.
Usulan:  kode izin [#9-WorkloadHaloSimpeg] (anggota ◻, §4), mulai dari M0 laporan pemahaman Jalur BE (§5).
Butir terbuka: 11 keputusan Horii (§6.1), 7 keputusan teknis reviewer di M0 (§6.2), 10 risiko (§7).
Paling penting: H8-5 lampiran tiket lama ber-path arsip/… vs penyajian publik F-10 (F10-18); H8-6 T12b impor master.
```

## 1. Status gerbang koding (PRD §0.1, §9)

| No | Butir gerbang | Sumber PRD | Status | Bukti di repo |
|---|---|---|---|---|
| G8-1 | PRD v1.0 masuk `main` sebelum koding atau migration DBV-028 | `PRD_Fase8_HaloSimpeg.md:43` | ✔ | Commit `d126c779` (03-11-2026, "PRD Fase 8 v1.0 — keputusan Horii Q8-01..Q8-15") leluhur `483c5892` |
| G8-2 | PRD v1.1 (E-10, CR-121) di `main` | `:49`, `:799` | ✔ | Merge `faaac019` (12-11-2026) + `32bd1db2` (bawaan teknis E-10 dikonfirmasi), leluhur `483c5892` |
| G8-3 | Sebelum gerbang boleh: membaca PRD, M0 tanpa commit kode, menjawab §12 | `:44` | — | Berlaku sekarang; M0 helpdesk belum ada |
| G8-4 | M1–M3 tidak bergantung pada Fase 6 | `:45-46` | ✔ | Fase 6 pun sudah sign-off 10-11-2026 (`docs/fase6/Laporan_Akhir_Fase6.md:7`, `:73`) |
| G8-5 | M4 (push) menunggu **MAKE-066** (adapter FCM HTTP v1) di `main` | `:46-47`, `:794` | ✔ | Commit `9debb366` (MAKE-066) leluhur `483c5892`; `FcmV1Adapter` terdaftar di `backend/app/Config/Services.php:543-544`, `MockFcmAdapter` `:542`; job antrean `push` di `backend/app/Config/Queue.php:46-57` |
| G8-6 | DBV-027 (`firebase_token.platform`, `token_hash`) untuk M4 | `:795` | ✔ | `backend/app/Database/Migrations/2026-11-03-100000_CreateIntegrasiKeluarFase6.php:23-24` |
| G8-7 | Uji push real sebelum deploy (bukan sign-off) | `:47-48`, `:836-837` | ◻ | Dilacak MT/DEPLOY; pemilik token Android = pertanyaan cutover DEPLOY-001.1 (`backend/app/Libraries/Push/README.md:16-17`) |
| G8-8 | Lonceng F-08 sudah ada saat M5 → Q8-06 = B (sumber lonceng) | `:257`, `:796`, `:852` | ✔ | F-08 MAKE-085 di `main` (`docs/fase7/Laporan_Akhir_Fase7.md:42`); titik sambung `backend/app/Interfaces/Notifikasi/RingkasanTiketInterface.php:9-21`, binding NoOp `backend/app/Config/Services.php:797-806`, dipakai `backend/app/Libraries/Notifikasi/Pusat/PusatNotifikasiService.php:74`, `:110`. Konsekuensi: §6.2 T-2 |
| G8-9 | E-10: DBV-033 baru disusun sesudah D1 chatbot blok [1]–[6] | `:50-52`, `:65` | ✔ | Status "DITERIMA 12-11-2026" tercatat di PRD `:51-52`, `:65`; isi D1 di luar repo (tidak dicek) |
| G8-10 | E-10: MAKE-094 menunggu DBV-033 di `main`; MAKE-095 menunggu kontrak MAKE-094 beku | `:52-53`, `:801` | ◻ | DBV-033 di branch `fase8/dbv-033-skema-chatbot` @`99be40a0` (dua commit di atas `483c5892`: `a682b84c` skema, `99be40a0` perbaikan review CR), belum di `main` (`git grep DBV-033` di `main` hanya mengenai `docs/fase8/*`). C0 ReadOne dan C0 Worker Muse diterima reviewer 13-11-2026; C1 dan C3 berjalan (fakta reviewer 13-11-2026). SHA dicek ulang tepat sebelum commit |
| G8-11 | Helpdesk dan chatbot tidak saling bergantung | `:53-54`, `:804` | ✔ | Berkas milik terpisah (PRD §3 `:156-168`) |
| G8-12 | F-10 (sisa Fase 7) sign-off sebelum M7 / sign-off Fase 8, karena sign-off Fase 8 = penutup seluruh development | `:817`, `:833-835` | ◻ | Sign-off F-10 masih ◻ (`docs/fase7/Laporan_Akhir_Fase7.md` §10.6). Tidak menahan mulai helpdesk |

**Kesimpulan:** gerbang teknis helpdesk **terbuka**. Yang belum ada hanya **izin workload + pelaksana** (§4) dan keputusan §6.1.

## 2. Prasyarat non-teknis (PRD §0.2)

| ID | Kebutuhan | Pemilik | Menahan | Status 13-11-2026 | Bukti / catatan |
|---|---|---|---|---|---|
| PN8-1 | Pemilik app Android mengonfirmasi kunci `data` push Halo Simpeg (Q8-08) dan penulis token Android ke DB v2 | Horii/TL | Payload final MAKE-072, uji push real | ◻ | Tidak ada bukti konfirmasi di repo. Token Android tanpa endpoint v2, "pertanyaan cutover DEPLOY-001.1" (`backend/app/Libraries/Push/README.md:16-17`). Tidak menahan M1–M3; MAKE-072 jalan dengan kunci `data` terbatas (F8-16, `PRD:254`) |
| PN8-2 | F-02 sortir `inbox` legacy selesai, hasil siap diimpor ke `disiplin_ticket(_msg)` | Reviewer/DBV | Impor data tiket (bukan koding) | ✔ kode / ◻ data nyata | MAKE-086 di `main` (`b98e6432`, `7c16b03c`; `docs/fase7/Laporan_Akhir_Fase7.md:44`): tier 13 `backend/app/Libraries/Impor/AturanTiketLama.php:11-16`, perintah `impor:sortir-inbox` (`backend/app/Commands/ImporSortirInbox.php`). Eksekusi data nyata = cutover G7-4 (`Laporan_Akhir_Fase7.md:11-12`, `:162`) |
| PN8-3 | Keputusan impor master `support_officer`, `officer_category`, `help_desk_contact` (Q8-13) + daftar petugas aktif & kategori berlaku | Horii/Biro SDM | Cutover | ✔ keputusan / ◻ data & kode impor | Q8-13 = A (`PRD:859`). Kode impor T12b **belum ada**, menunggu DBV-028 (`docs/migrasi/MAKE-069-rencana.md:4`, `:31`, `:108-112`; `backend/app/Libraries/Impor/README.md:159`). Aturan T12b berbeda dengan F8-21 → H8-6. Satker kepegawaian `101` (F8-07, bersyarat Q8-11, `PRD:245`, `:857`) belum dikonfirmasi Biro SDM → H8-7 |
| PN8-4 | Frame Figma halaman yang belum ada (daftar tiket petugas, master kategori/petugas/kontak) | Horii/desainer | QA visual | ◻ | Tidak bisa dicek dari repo. Tanpa frame → ikut pola halaman lain dan dicatat (`AGENTS.md:93-94`) → H8-9 |
| PN8-5 | D1 chatbot dari DB dev legacy (blok [1]–[6]) | Horii | DBV-033 | ✔ | `PRD:65` (diterima 12-11-2026; rincian di luar repo) |
| PN8-6 | Keputusan isi QnA awal (K-5) | Horii | Isi awal chatbot saat cutover | ◻ ditunda ke deploy | `PRD:66`, `:271`, `:874` (Trello DEPLOY-002) |
| PN8-7 | Foto avatar Vanya (opsional) | Horii/desainer | Tidak menahan | — | `PRD:67` (SVG bawaan dipakai) |
| PN8-8 | Acuan visual widget dev untuk QA chatbot (AC-E10.22) | Horii/reviewer | QA Lapis 1 chatbot | ◻ | `PRD:68` |
| (M0) | Ekstraksi trigger `inbox_msg_afIns` (D1:7730-7739) untuk penanda baca F8-10 | DBA/Horii lewat reviewer | Tetap [I] bila belum ada | ◻ | `PRD:248`, `:863`, R8-4 `:920`; kewajiban A0 (`8_1.md:31`) → H8-10 |

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
`Config/RoutesHaloSimpeg.php`, `backend/docs/progress/08-HaloSimpeg.md`, berkas DBV-028. Folder modul baru berisi README
(`backend/app/{Controllers/Api,Libraries,Models}/HaloSimpeg/README.md`, `frontend/src/features/halo-simpeg/README.md` + `.gitkeep`).
Kerangka FE berdata contoh hanya ada di branch `feat/frontend-ui-redesign` commit `67fa7678` (`halo.store.ts`, `types.ts`,
`HaloDrawer.vue`, `AdminHaloPage.vue`, `__tests__/halo.spec.ts`), sesuai `8_2.md:21-23`.

## 3. Keterkaitan dengan F-10 (berjalan) dan E-10 (sudah mulai)

### 3.1 Posisi pekerjaan lain

| Paket | Isi | Pelaksana (fakta reviewer 13-11-2026) | Status |
|---|---|---|---|
| F-10 MAKE-090 | BE Arsip Tahap 1 | WS-2 | ✔ di `main` `fa1f6686` |
| F-10 MAKE-091 | FE Arsip Tahap 1 | Worker Fix Bug | ✔ di `main` `483c5892` |
| F-10 MAKE-093 | Penyajian berkas legacy (README-deploy §1/§1l, ADR-038, `ReadmeBerkasNginxTest`) | Handling Case | Branch `f10/make-093-penyajian-berkas` @`29ca410c`, gate penuh diulang |
| F-10 CR-122 | PRD F-10 v1.4 | Reviewer | Branch `docs/cr-122-prd-f10-v14` @`448b302c` (satu berkas dokumen) |
| F-10 MAKE-092 / MAKE-096 | Tahap 2 BE / FE | WS-2 / Worker Fix Bug | Mulai 13-11-2026: branch `f10/make-092-arsip-aksi-be` @`c49d5a42` dan `f10/make-096-arsip-aksi-fe` @`149b816a` (masing-masing satu commit di atas `483c5892`), belum di `main`; CI/gate ◻ belum dicek. MAKE-096 belum tercatat di PRD F-10 (`main` maupun v1.4 menyebut MAKE-092 sebagai "BE+FE Tahap 2", `PRD_Fase7_F10_Arsip-Digital.md:654`) |
| E-10 C0 | Laporan pemahaman E-10 (⛔ reviewer, `8_2.md:74`, PRD `:823`) | ReadOne, Worker Muse | ✔ diterima reviewer 13-11-2026 (fakta reviewer) |
| E-10 C1/C2 | DBV-033 + MAKE-094 | ReadOne (DB Validator: WS-2) | C1 berjalan (branch DBV-033 @`99be40a0`, G8-10) |
| E-10 C3 | MAKE-095 | Worker Muse | C3 berjalan; koding penuh baru sesudah kontrak MAKE-094 beku (`8_2.md:70`) → §6.2 T-7 |

Izin [#8-WorkloadChatbot] (E-10) = fakta reviewer 13-11-2026, disetujui Horii 13-11-2026; tidak tercatat di repo.
| G10-4 | Verifikasi Nginx `/berkas/` di server dev | Operator/Horii | ◻ (`PRD_Fase7_F10_Arsip-Digital.md:45`) |
| B10-1..B10-3 | Blocker deploy/cutover F-10 | Horii/operator | ◻, bukan syarat sign-off (`:46-48`, `:608`) |

### 3.2 Anti-tabrakan berkas

Helpdesk tidak memakai berkas milik F-10 maupun chatbot (PRD §3 `:163-168`). Titik temu ada di **berkas bersama** (hanya
aditif, commit terpisah, `PRD:180-185`) dan **komponen yang dipakai tanpa diubah**:

| Berkas | Disentuh oleh | Aturan |
|---|---|---|
| `frontend/src/shared/layouts/nav.config.ts` | MAKE-091 (sudah, item `arsip` `:171`), MAKE-092/096 (kemungkinan), MAKE-095 (menu Chatbot Manager), MAKE-073 (item `halo` `:302`) | Aditif; yang merge kedua rebase dari `origin/main`, reviewer memeriksa konflik (`PRD:176-178`) |
| `frontend/src/router/index.ts` | MAKE-091 (sudah, `arsipRoutes`), MAKE-095 (satu baris import), MAKE-073 (route Halo Simpeg) | Sama |
| `frontend/src/shared/layouts/RedesignShell.vue` | MAKE-095 (titik pasang widget dekat `:186-188`), MAKE-073 (badge) | Sama |
| `backend/app/Config/Routing.php` (`$routeFiles` `:33-59`) | MAKE-094 (`RoutesChatbot.php`), MAKE-070 (`RoutesHaloSimpeg.php`) | Satu baris per route file |
| `backend/app/Config/Services.php` | MAKE-071/072 (binding `ringkasanTiket` `:797-806`, layanan helpdesk), MAKE-094 (bila perlu) | Aditif; penggantian binding NoOp = T-2 |
| `backend/app/Config/Queue.php` (`$jobHandlers` `:46-57`) | MAKE-072 (bila job baru, lihat T-1) | Aditif |
| `README-deploy.md` | MAKE-093 (§1, §1l; +149 baris di branch), MAKE-094 (pemeriksaan kunci F8-35) | Aditif; MAKE-093 lebih dulu |
| `backend/docs/progress/08-HaloSimpeg.md` | MAKE-094 (bagian chatbot), MAKE-070..074 (bagian helpdesk) | **Belum ada**: jalur yang pertama membuatnya, jalur lain menambah bagian (T-6) |
| `EksporTabel.php` | Ekspor arsip F-10 Tahap 2 (lewat `EksporTabel`, `PRD_Fase7_F10_Arsip-Digital.md:233`), E-09, E-10 ekspor tag | **Dipakai, tidak diubah** (`PRD:190-192`); kebutuhan ubah = CR |
| `LocalStorageAdapter.php`, `AturanLampiran.php`, `ValidasiBerkas.php` | MAKE-093 (docblock `LocalStorageAdapter.php:12-13`, komentar saja), MAKE-071 (lampiran tiket, BP8-5) | Dipakai, tidak diubah oleh helpdesk |

Keterkaitan isi: lampiran tiket lama di storage legacy ber-path `arsip/{nip}/inbox/…` (F8-17, `PRD:255`; label [K] dari PRD,
legacy tidak dibuka ulang) cocok dengan regex berkas legacy F-10 `^arsip/(?:[^/.][^/]*/)*[^/.][^/]*$`
(`backend/app/Config/Arsip.php:35-38`), yang disajikan **publik** lewat Nginx `/berkas/` tanpa cek izin (F10-18 (a),
`PRD_Fase7_F10_Arsip-Digital.md:115`). PRD F-10 sudah mencakup hal ini secara umum: R6 (`:365`) menyatakan alias `arsip/` membuka
seluruh subtree salinan MAKE-069 termasuk berkas kolom lain [K], dan QF10-2 (`:636`) mengatur pertanyaan ke Horii **hanya bila**
manifest menunjukkan berkas kolom lain di subtree itu dan Horii ingin mempersempitnya. Lampiran tiket adalah kasus khusus R6/QF10-2;
yang khas adalah isinya percakapan pribadi, sedangkan PRD Fase 8 mewajibkan unduh lewat endpoint ber-otorisasi (BP8-5 `PRD:136`,
R8-6 `:922`). → **H8-5**.

### 3.3 Satu gate per mesin (3307)

- Gate penuh **satu pada satu waktu per mesin** (`AGENTS.md:138`) dan memakai DB test/scratch sendiri (`AGENTS.md:140-143`,
  port 3307; `backend/.env.example:23-26`, `:38`). Pola F-10: worktree + DB scratch 3307 milik sendiri dengan `.env` minimal
  (`PRD_Fase7_F10_Arsip-Digital.md:82`).
- Gate penuh CI menggantikan gate penuh lokal; di lokal cukup gate cepat (`AGENTS.md:103-106`, `:108-113`). Satu `./check.sh`
  penuh per mesin juga ditegaskan PRD Fase 8 (`PRD:124-125`).
- Bila helpdesk dimulai, antrean gate penuh lokal di mesin yang sama bisa mencapai lima aliran (MAKE-093, MAKE-092, MAKE-096,
  C1/C2, helpdesk). Usulan urutan antre: MAKE-093 → MAKE-092/096 → DBV-033/MAKE-094 → MAKE-095 → paket helpdesk, diatur reviewer;
  paket helpdesk awal (M0, DBV-028) tidak butuh gate penuh lokal (M0 tanpa kode; DBV-028 cukup gate cepat + CI).

## 4. Usulan pelaksanaan helpdesk

### 4.1 Jalur dan key (sudah dikunci PRD, Q8-14 = B)

| Jalur | Milestone | Key | Branch | Menunggu | Sumber |
|---|---|---|---|---|---|
| BE | A0 laporan pemahaman | — | — | PRD v1.0 ✔ | `8_1.md:31` |
| BE | A1 skema (PR `[DBV]`) | DBV-028 | `fase8/dbv-028-skema-helpdesk` | A0 | `8_1.md:32` |
| BE | A2 master kategori/petugas/kontak + kontrak beku | MAKE-070 | `fase8/make-070-master-halo` | A1 di `main` | `8_1.md:33` |
| BE | A3 tiket, percakapan, tutup/hapus, lampiran, ringkasan + kontrak beku | MAKE-071 | `fase8/make-071-tiket` | A2 | `8_1.md:34` |
| BE | A4 notifikasi push | MAKE-072 | `fase8/make-072-notif-tiket` | A3; MAKE-066 ✔ | `8_1.md:35` |
| BE | A5 ekspor rekap + penutup BE | MAKE-074 | `fase8/make-074-ekspor-penutup` | A3 | `8_1.md:36` |
| FE | B0 laporan pemahaman | — | — | PRD v1.0 ✔ | `8_2.md:33` |
| FE | B1–B3, B5 fondasi, pemilik, petugas + admin, penutup | MAKE-073 | `fase8/make-073-fe-halo` | kontrak A2 + A3 beku | `8_2.md:34-38` |
| FE | B4 tombol ekspor | MAKE-074 (FE) | `fase8/make-074-fe-ekspor` | A5 | `8_2.md:37` |

Key tidak bertabrakan: DBV-028 dicadangkan untuk helpdesk sejak Fase 6/7 (`docs/migrasi/MAKE-069-rencana.md:238`,
`docs/migrasi/Mapping_Migrasi_Tidak_Dimigrasi.md:53`); MAKE-070..074 hanya muncul di `docs/fase8/*` (`git grep`). Key perbaikan
review "CR-095 dst." di header PRD (`PRD:13`) sudah usang: CR-096..CR-122 terpakai paket lain (mis. CR-096 `890eb1df`, CR-122
PRD F-10 v1.4) → T-5.

Karena MAKE-066 dan F-08 sudah di `main`, A4 bisa langsung memakai `FcmV1Adapter` + transport palsu (tanpa tahap mock,
`8_1.md:38`), dan A3/A4 menyediakan sumber lonceng (Q8-06 = B).

### 4.2 Usulan kode izin workload **[#9-WorkloadHaloSimpeg]**

Scope usulan: Fase 8 helpdesk E-01..E-09 — A0–A5 (`8_1.md` §2), B0–B5 (`8_2.md` §2), review skema DBV-028, dan berkas bersama
aditif PRD §3. **Di luar scope:** E-10 (tetap izin [#8-WorkloadChatbot]), F-10, impor T12b (menunggu H8-6), cutover data.

Anggota: **✔ diputus Horii 13-11-2026 (H8-1)** — BE Handling Case, FE menyusul, DB Validator WS-2. Tabel opsi di bawah disimpan sebagai catatan. Opsi berdasarkan ketersediaan (fakta reviewer 13-11-2026):

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
| 0 | ⛔ Horii menyetujui [#9-WorkloadHaloSimpeg] (scope + anggota) dan menjawab H8-2/H8-3/H8-4; status kartu Trello/Notion Fase 8 → berjalan (helpdesk) (DoD PRD `:836` mewajibkan kartu Fase 8 + kartu QA disinkron) | Horii, reviewer CR | — |
| 1 | **A0 laporan pemahaman BE** (tanpa commit kode), wajib memuat: trigger `inbox_msg_afIns` (H8-10), rekonsiliasi T-1..T-4, daftar berkas bersama §3.2, rujukan `path:baris` PRD yang bergeser (T-3), dampak H8-5 pada E-04 lampiran, dan rencana test exit criteria: verifikasi "thread tidak ter-mix" §7.8 + regresi Fase 4 C-05/C-06 + FAQ G-10 (`PRD:833-834`) | Pelaksana BE | Langkah 0 |
| 1b | B0 laporan pemahaman FE (boleh menyusul) | Pelaksana FE | Penunjukan FE |
| 2 | ⛔ Reviewer menyetujui A0 dan memutus T-1..T-7 | Reviewer CR | Langkah 1 |
| 3 | **DBV-028** (PR `[DBV]`): `support_officer`, `officer_category`, `help_desk_contact`, ALTER indeks, keputusan FK `nip`, kueri pra-cek duplikat `help_desk_contact.contact` (`8_1.md:43-45`); klasifikasi kolom DATETIME untuk T12b (`backend/docs/db-review/DBV-029-impor-cutover-tahap2.md:165-166`) | Pelaksana BE + DB Validator | Langkah 2 |
| 4 | MAKE-070 master BE → kontrak master beku di README `Controllers/Api/HaloSimpeg` | Pelaksana BE | DBV-028 di `main` |
| 5 | MAKE-071 tiket BE + ringkasan + sumber lonceng (T-2) → kontrak tiket beku | Pelaksana BE | MAKE-070 |
| 6 | MAKE-073 B1–B3 (FE) | Pelaksana FE | Kontrak langkah 4 + 5 beku |
| 7 | MAKE-072 push (adapter real + transport palsu; payload terbatas sampai PN8-1) | Pelaksana BE | MAKE-071 |
| 8 | MAKE-074 BE ekspor + penutup; B4 tombol ekspor; B5 penutup FE | BE + FE | MAKE-071, MAKE-073 |
| 9 | M7: QA Lapis 1 Fase 8 (helpdesk + chatbot), `docs/fase8/Laporan_Akhir_Fase8.md` → ⛔ sign-off Horii (penutup seluruh development) | Reviewer CR, Horii | M6, C3, C4 bila K-5 membawa isi dev (`PRD:817`, `:833-835`); F-10 sign-off (G8-12) |

Setiap milestone: branch baru dari `origin/main` → gate cepat → push → CI hijau → lapor → ⛔ merge oleh reviewer atas izin Horii
per paket (`PRD:829`).

## 6. Keputusan sebelum start

### 6.1 Keputusan Horii

| ID | Pertanyaan | Opsi | Menahan |
|---|---|---|---|
| H8-1 | Setujui kode izin **[#9-WorkloadHaloSimpeg]** dengan scope §4.2, dan siapa anggotanya (Jalur BE, Jalur FE, DB Validator DBV-028)? — ✔ **Horii 13-11-2026: disetujui** ("Boleh, lanjutkan") sesuai usulan reviewer: Jalur BE = Handling Case (sesudah MAKE-093 di main), Jalur FE = ditunjuk sesudah kontrak MAKE-070/071 beku, DB Validator DBV-028 = WS-2 (sesudah review DBV-033). Mulai mengikuti H8-2. | §4.2 | Semua |
| H8-2 | Kapan helpdesk mulai? — ✔ **Horii 13-11-2026: (c) sesudah F-10 sign-off.** Helpdesk (A0 dst.) belum dimulai sampai F-10 sign-off; E-10 chatbot tetap berjalan di izin #8. | (a) A0 sekarang, paralel dengan F-10 Tahap 2 dan E-10 (A0 tanpa commit kode). (b) Sesudah MAKE-093 merge (Handling Case bebas). (c) Sesudah F-10 sign-off | Langkah 1 |
| H8-3 | ◻ Bila pelaksana FE belum tersedia: tetap Q8-14 = B (FE ditunjuk sesudah kontrak beku), atau pindah ke Q8-14 opsi A (satu pelaksana berurutan BE → FE)? | B tetap / A | MAKE-073 |
| H8-4 | ◻ DB Validator DBV-028 | §4.2 baris DB Validator (a)/(b)/(c) | DBV-028 |
| H8-5 | **Pemicu QF10-2 untuk subfolder `inbox`.** Lampiran tiket lama ber-path `arsip/{nip}/inbox/…` cocok dengan pola berkas legacy F-10 yang disajikan publik lewat Nginx `/berkas/` (§3.2; R6 `PRD_Fase7_F10_Arsip-Digital.md:365`). Isinya data percakapan pribadi (R8-6 `PRD:922`). Apakah Horii ingin mempersempit alias untuk subfolder `inbox` (QF10-2 `:636`), atau keputusan "persis legacy, URL publik" F10-18 tetap berlaku untuk lampiran percakapan Halo Simpeg? — ✔ **Horii 13-11-2026: (a) ikut legacy** — lampiran tiket lama di `arsip/{nip}/inbox/…` tetap tersaji lewat alias publik F-10 seperti legacy (F10-18 berlaku; QF10-2 tidak mempersempit). Unggahan tiket baru v2 tetap privat (BP8-5). Dicatat sebagai risiko sadar (R8-6) dan ADR-038. | (a) Ya, berlaku (ikut legacy). (b) Dikecualikan: subfolder `inbox` tidak disajikan Nginx dan lampiran lama hanya lewat endpoint ber-otorisasi E-04 (perlu CR ke README-deploy §1l / `Config\Arsip` / MAKE-069). (c) Diputus saat cutover, koding E-04 memakai endpoint ber-otorisasi untuk semua lampiran | E-04 lampiran, cutover, README-deploy §1l |
| H8-6 | **Impor master T12b**: F8-21 menulis `id_user` dipetakan lewat tabel pemetaan UP-1 dan baris tanpa pemetaan dilaporkan (`PRD:259`); rencana MAKE-069 menulis `id_user` disalin langsung tanpa tabel pemetaan (K-M11) dan id yang tidak ada dibuang + dicatat jumlahnya (K-M12) (`docs/migrasi/MAKE-069-rencana.md:110-111`). Mana yang berlaku, dan key/pelaksana kode T12b sesudah DBV-028? — ✔ **Horii 13-11-2026: "seperti legacy saja"** — `id_user` master helpdesk disalin apa adanya dari legacy (rencana MAKE-069 K-M11/K-M12: tanpa tabel pemetaan UP-1; id yang tidak ada di `pengguna` v2 dibuang + jumlahnya dicatat). F8-21 diselaraskan saat M0 (CR). | (a) Ikut K-M11/K-M12 (PRD dikoreksi lewat CR). (b) Ikut F8-21. Key: bagian MAKE-074 (penutup + runbook) atau paket MAKE-069 lanjutan | Cutover; isi DBV-028 tidak berubah |
| H8-7 | ◻ Konfirmasi Biro SDM bahwa satker `101` = satker kepegawaian (syarat F8-07/Q8-11) | — | Data kandidat petugas saat QA/cutover (kode memakai `id_satker_kepegawaian`) |
| H8-8 | ◻ Siapa menghubungi pemilik app Android untuk PN8-1 (kunci `data` push + penulis token Android, DEPLOY-001.1)? | — | Payload final MAKE-072, uji push real |
| H8-9 | ◻ Apakah ada frame Figma untuk daftar tiket petugas dan master kategori/petugas/kontak (PN8-4)? | Ada (serahkan) / tidak (ikut pola, `AGENTS.md:93-94`) | QA visual |
| H8-10 | ◻ Apakah ekstraksi trigger `inbox_msg_afIns` (D1:7730-7739) bisa diberikan sebelum MAKE-071? | Ya / tidak (tetap [I], diuji per skenario AC-E04.4) | Penanda baca F8-10 |
| H8-11 | ◻ Gerbang rilis menu Halo Simpeg dan badge: PRD §6.3 hanya menyebut item `halo` `phase: 8` (`PRD:338`), sedangkan `ACTIVE_PHASE` = 5 (`frontend/src/shared/layouts/nav.config.ts:95`). Ikut pola F8-50 chatbot (QA lewat URL langsung, menu dibuka saat `ACTIVE_PHASE` dinaikkan di penutup rilis)? Preseden opsi (a): Fase 7 mencatat `ACTIVE_PHASE` dinaikkan ke 7 hanya saat rilis (`docs/fase7/Laporan_Akhir_Fase7.md:168`), F-10 ikut gerbang Fase 7 (QF10-3, `PRD_Fase7_F10_Arsip-Digital.md:637`), dan kenaikan konstanta = keputusan Horii/reviewer di penutup rilis (F8-50 `PRD:907`) | (a) Ikut F8-50. (b) Menu `rilis: true` saat MAKE-073 masuk `main` | MAKE-073 B1 |

### 6.2 Keputusan teknis reviewer saat M0 (bukan keputusan bisnis)

| ID | Butir | Bukti | Usulan |
|---|---|---|---|
| T-1 | ◻ PRD merencanakan job baru `push-halo-simpeg` / `PushHaloSimpegJob` (`PRD:158`, `:366`), padahal MAKE-066 sudah menyediakan `AntrePushPesan` sebagai titik sambung Halo Simpeg ke job `push-pesan` (Android) (`backend/app/Libraries/Push/README.md:11-15`; `README-deploy.md:228`) | PRD ditulis sebelum MAKE-066 | A0 membandingkan kedua jalur; BP8-1 (job terpisah dari `push-notif`) tetap dijaga |
| T-2 | ◻ Implementasi `RingkasanTiketInterface` dan penggantian binding NoOp di `Config/Services.php:797-806` belum disebut di PRD/8_1 | `RingkasanTiketInterface.php:13`; `Laporan_Akhir_Fase7.md:147-148` | Masuk MAKE-071 bersama `GET tiket/ringkasan` (aturan hitung §6.2 PRD sama) |
| T-3 | ◻ Rujukan `path:baris` PRD bergeser karena MAKE-091: item `halo` `nav.config.ts:301` → `:302`, `ACTIVE_PHASE` `:94` → `:95`, semantik `rilis` `:120-124` → `:121-125`, pola Integrasi `:293` → `:294` (`PRD:294`, `:357`, `:1081`). `RedesignShell.vue:186`, `:188` dan `Config/Routing.php:34-59` masih tepat | `git diff 241ef9e5 483c5892` | Dicatat di laporan A0; PRD dikoreksi bersama CR berikutnya |
| T-4 | ◻ Path lampiran v2 usulan `halo-simpeg/{nip}/{id_inbox}/…` (F8-17) berbeda dengan pola kunci unggahan v2 `YYYY/MM/<32 hex>.<ext>` yang dipakai F-10 (F10-18 (b), `PRD_Fase7_F10_Arsip-Digital.md:115`) | — | A0 memilih satu pola; keduanya tidak cocok regex Nginx legacy, jadi tetap privat |
| T-5 | ◻ Key perbaikan review/QA Fase 8 "CR-095 dst." (`PRD:13`) usang | CR-096 `890eb1df` dst. sudah terpakai | Reviewer memberi nomor CR kosong berikutnya saat dibutuhkan |
| T-6 | ◻ Siapa membuat `backend/docs/progress/08-HaloSimpeg.md` pertama kali (belum ada; milik bersama helpdesk + chatbot) | `ls backend/docs/progress` (00..07) | Jalur yang merge lebih dulu membuat kerangka dua bagian |
| T-7 | ◻ C3 (MAKE-095) berjalan sebelum DBV-033/MAKE-094 di `main` | `8_2.md:70` | Pastikan C3 sebatas kerangka sampai kontrak MAKE-094 beku |

## 7. Risiko

| ID | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| RK-1 | Konflik di berkas bersama FE (`nav.config.ts`, `router/index.ts`, `RedesignShell.vue`) antara MAKE-092/096, MAKE-095, MAKE-073 | Rebase berulang, menu/route hilang saat merge | Aditif + commit terpisah; yang merge kedua rebase; reviewer memeriksa (`PRD:176-178`); test `nav.config.spec.ts` |
| RK-2 | Konflik di berkas bersama BE (`Config/Services.php`, `Config/Routing.php`, `Config/Queue.php`, `README-deploy.md`, progres 08) | Sama | Sama; T-1, T-2, T-6 diputus di M0 |
| RK-3 | Antrean gate penuh lokal di satu mesin (3307) dengan sampai lima aliran | Lapor tertunda | Gate cepat lokal + CI sebagai gate penuh (`AGENTS.md:103-113`); urutan antre §3.3 |
| RK-4 | Beban WS-2 (MAKE-092 + DB Validator DBV-033, ditambah DBV-028) | Review skema tertahan | H8-4 |
| RK-5 | Lampiran tiket lama terbuka lewat URL publik `/berkas/arsip/…` | Isi percakapan (data pribadi) bisa diunduh tanpa login | H8-5 (pemicu QF10-2, `PRD_Fase7_F10_Arsip-Digital.md:636`; R6 `:365`) sebelum cutover; E-04 tetap memakai endpoint ber-otorisasi (BP8-5) |
| RK-6 | Desain PRD Fase 8 mendahului implementasi MAKE-066 dan F-08 | Duplikasi job push, binding lonceng terlewat | T-1, T-2 di A0 |
| RK-7 | Komponen yang wajib dipakai tanpa diubah (`EksporTabel`, `LocalStorageAdapter`, `AturanLampiran`, `ValidasiBerkas`, `HtmlSanitizer`) diminta berubah oleh F-10 Tahap 2, E-09, atau E-10 | Regresi lintas modul | Perubahan hanya lewat CR reviewer (`PRD:190-192`); regresi gate penuh |
| RK-8 | Pelaksana dari agen yang lama tidak aktif bekerja dengan dependensi basi atau `.env` yang salah | Gate lolos palsu atau menyentuh DB yang salah | Worktree baru dari `origin/main`, `composer install` + `npm ci`, `.env` minimal 3307 (`backend/.env.example:23-26`) |
| RK-9 | Impor master T12b belum punya kode dan aturannya berbeda antardokumen (H8-6) | Petugas/kategori tidak terbawa saat cutover | H8-6; T12b disusun sesudah DBV-028 |
| RK-10 | Risiko PRD R8-1..R8-17 (`PRD:913-933`) tetap berlaku, terutama R8-1 (payload Android), R8-4 (trigger), R8-6 (privasi) | — | PN8-1, H8-10, BP8-2/BP8-5 |
