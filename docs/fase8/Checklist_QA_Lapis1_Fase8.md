# Checklist QA Lapis 1 — Fase 8 Halo Simpeg + Chatbot "Vanya" (CR-132)

Untuk reviewer CR (pelaksana QA Lapis 1 Fase 8; PRD Fase 8 §11 DoD & exit criteria `:1350-1357`, yang mensyaratkan QA Lapis 1
lolos "termasuk widget dan Chatbot Manager"). Acuan: PRD Fase 8 **v1.3** (`main`, `docs/fase8/PRD_Fase8_HaloSimpeg.md`) —
§3.1 paket (`:257-269`), §5 register F8-xx, §6.1/§6.1b kontrak (`:387-535`), §6.2 penanda baca & ringkasan, §6.3 FE, §7
AC-E01..AC-E10 (chatbot §7.10.6 `:1182-1239`, skenario pembanding §7.10.7 `:1252`), §7.8 "thread tidak ter-mix" (`:1019-1024`),
§8 aturan lintas modul, §12 register keputusan (`:1365` dst.), Lampiran B anomali & perlakuan v2 (B.1 `:1585-1619`, B.2
`:1621` dst.); `docs/fase8/8_1.md` (BE), `docs/fase8/8_2.md` (FE), `docs/fase8/Kesiapan_Fase8.md`. Kontrak modul:
`backend/app/Controllers/Api/HaloSimpeg/Chatbot/README.md` (chatbot, beku `fd1e74ed`),
`frontend/src/features/halo-simpeg/chatbot/README.md`, dan README helpdesk `backend/app/Controllers/Api/HaloSimpeg/README.md`
(dibekukan MAKE-070). Pola: Checklist QA Lapis 1 F-10 (`docs/fase7/Checklist_QA_Lapis1_F10.md`) dan Fase 6
(`docs/fase6/Checklist_QA_Lapis1_Fase6.md`). Checklist ini **disepakati reviewer CR sebelum QA dimulai**; pertanyaan pra-QA dan
keputusannya ada di §K.

**Status (18-11-2026, CR-144; sign-off CR-145).** **SIGN-OFF Horii 18-11-2026** pada `main` `d8020fc3` (CI `quality-gate` #906
hijau). **QA Lapis 1 selesai 18-11-2026 pada `main` `e4392507` (bagian B) + verifikasi perbaikan `8bc6c509`.** Bagian A dijalankan
pada `67f3303c` (putaran 1, 17-11-2026), `d659acf6` (uji ulang T-02/T-03
dan AW17) dan `e4392507` (sisa 18-11-2026); bagian B pada `e4392507`, dengan build lokal `ACTIVE_PHASE = 8` + item `halo` tanpa
commit (dibuang sesudah QA). Perbaikan temuan bagian B diverifikasi di demo lalu masuk `main`: CR-143 `4d06a8c6` dan CR-142
`8bc6c509`. CI `quality-gate` `main` #899 hijau pada `e4392507` dan #905 hijau pada `8bc6c509`. Login akun demo atas izin Horii [#111] dan [#112].
Ringkasan sign-off 300 butir (tanpa HL dan E): GF 5 (✅ 3, ✅ sebagian 2); bagian A 90 (✅ 72, ✅ sebagian 14,
[test-otomatis] 3, [test-otomatis] sebagian 1); bagian B 159 (✅ 92, ✅ sebagian 34, [test-otomatis] 24, [test-otomatis] sebagian 3,
[mock-terbatas] 4, — 2); bagian C 46 (✅ 2, [test-otomatis] 39, [test-otomatis] sebagian 3, — 2). HL 4 (— sesudah sign-off) tidak dihitung.
**❌ terbuka: 0.** Temuan §T: T-01 (CR-134 paket 1), T-02 dan T-03 (CR-134 paket 2 `d659acf6`), T-B1 (CR-143 + CR-142), T-B3 dan
T-B4 (CR-142) diperbaiki dan diuji ulang; T-B2 menjadi kandidat CR sesudah sign-off. Pengamatan (bukan temuan): HA5, HA6, HP7, HA10,
HB8, AA14. Rincian laporan: `docs/fase8/Laporan_Akhir_Fase8.md`.

Riwayat status (17-11-2026, sebelum eksekusi). Basis penyusunan: `main` `a5cb7300`.
**Bagian A (chatbot) siap diuji sekarang.** E-10 sudah di `main`: DBV-033 merge `3fb8c5f7` (PR #40), MAKE-094 BE `4af0c188`,
MAKE-095 FE merge `2a8f405d` (`Kesiapan_Fase8.md:20`, `:47`). Banding PN8-8 dibuat terhadap `ef83356e`; diff `ef83356e..a5cb7300`
untuk kode chatbot FE/BE dan `tailwind.config.js` kosong, jadi rujukan berkas:baris di bagian A berlaku di `a5cb7300`.
**Bagian B (helpdesk) menunggu MAKE-073/MAKE-074.** Di `main` baru ada skema DBV-028 (migration `2026-11-16-100000_CreateHaloSimpegFase8`
dan `2026-11-16-100100_AlterDisiplinTicketIndeksFase8`, test `backend/tests/database/HaloSimpeg/SkemaHaloSimpegTest.php`).
MAKE-070, MAKE-071, MAKE-072 (+ CR-137), MAKE-073, MAKE-074 (BE + B4 FE), dan MAKE-097 (+ T-5) belum di `main` (PRD `:257-265`,
M7 `:1331`). Rujukan berkas:baris kode helpdesk dan nama test di bagian B dicocokkan ulang terhadap SHA QA sesudah paket itu di
`main` (§K K-19, pola K-07 F10). Izin CR-133 (test AC-E10.19 sisi log aplikasi) dan CR-134 dst. (perbaikan temuan QA chatbot)
✔ disetujui Horii 17-11-2026 (§K K-03).

**Cakupan.** Bagian A: widget "Vanya" (role 2/6/7) dan Chatbot Manager (role 1, lima halaman), termasuk butir visual AC-E10.22
terhadap acuan PN8-8 (di luar repo). Bagian B: helpdesk Halo Simpeg (layar pemilik, petugas, admin master, penanda baca,
badge/lonceng, ekspor, push mode mock), verifikasi "thread tidak ter-mix" §7.8, dan regresi Fase 4 (C-05/C-06) + FAQ (G-10).
Bagian C: butir yang cukup dicek lewat bukti test otomatis gate/CI. Bagian D: alur pencatatan temuan. Bagian E: sign-off QA.
Di luar cakupan: AC-E10.30 impor isi dev C4 (H8-13 (b), DEPLOY-002), uji push real (MT-010/DEPLOY-001.1, AC-E06.8), langkah
deploy (kunci token README-deploy §1m di server, T12b di server), dan fase Manual Testing (MT) yang berjalan tersendiri.
Rilis menu CR-135 (§HL) dijalankan sesudah sign-off dan tidak dihitung dalam ringkasan sign-off (§K K-18).

**Cara membaca nomor butir.** `GF` gerbang umum. Bagian A: `AG` prasyarat chatbot, `AW` widget fungsional, `AV` visual
widget terhadap PN8-8 (AC-E10.22), `AA` Chatbot Manager (admin), `AL` log & privasi, `AM` matriks role chatbot, `ABB` "Bukan
bug" chatbot. Bagian B memakai awalan `H` supaya tidak bentrok dengan nomor anomali Lampiran B (B-1..B-31) maupun butir bagian A:
`HG` gerbang, `HP` pemilik, `HT` petugas, `HN` tiket kategori NULL, `HR` penanda baca, `HS` penyamaran nama, `HA` admin master,
`HK` aksi baris ⋮ & komponen, `HB` badge/lonceng, `HE` ekspor, `HU` push (mock), `HV` visual AC-E08.7, `HM` matriks role,
`HX` "thread tidak ter-mix" §7.8, `HZ` regresi, `HL` rilis CR-135, `HBB` "Bukan bug" helpdesk. Bagian C: `CA` chatbot, `CB`
helpdesk/regresi. Bagian E: `E`. Butir bertanda **bergantung H8-7** menunggu konfirmasi satker kepegawaian (§K K-10). Butir
bertanda **[mock-terbatas]** hanya bisa diuji sebatas mode mock (pola Fase 6). Kode data (mis. `CK1`, `T1`) menunjuk tabel
data uji.

## Lingkungan & persiapan

**App demo lokal.** Worktree demo reviewer pada `main` terbaru (bagian A: `a5cb7300` atau sesudahnya; bagian B: SHA sesudah
MAKE-073/074), DB demo, captcha mock, driver integrasi `mock`, `.env` demo minimal (preflight gate), data sintetis. Akun: akun
demo per role 1–8 (berkas akun demo di luar repo; password tidak ditulis), ditambah akun demo sintetis tambahan bila perlu (§K
K-17). Host, port, dan nama DB tidak ditulis di checklist maupun laporan.

**Reset demo dan urutan skrip data:**

```bash
# DB demo (bukan DB dev); jalankan di worktree demo
php spark migrate --all
php spark db:seed <seeder bawaan app demo>
# skrip QA idempoten di folder demo-seed (di luar repo), ber-guard nama DB demo, penanda [QA-F8 <kode>]
```

**`ACTIVE_PHASE` dan cara membuka halaman sebelum CR-135 (fakta di `a5cb7300`).**
- `ACTIVE_PHASE = 5` (`frontend/src/shared/layouts/nav.config.ts:97`).
- **Widget Vanya** dipasang sekali di `frontend/src/shared/layouts/RedesignShell.vue:198-199` (`v-if="!auth.mustChangePassword"`)
  dan baru dirender bila `ACTIVE_PHASE >= FASE_CHATBOT` (8) serta role sesi ∈ `UL_PEGAWAI` (`chatbot/constants.ts:7`, `:10`,
  `VanyaWidget.vue:3-5`, computed `aktif` `:36-42`). Dengan nilai 5 di `main`, widget **tidak dirender sama sekali** dan tidak ada URL langsung
  untuk membukanya. QA widget butuh build lokal dengan `ACTIVE_PHASE = 8` **di worktree demo saja, tanpa commit**, lalu
  dikembalikan ke 5 (§K K-01).
- **Chatbot Manager** (`chatbot/routes.chatbot.ts`): route `/chatbot-manager/:bagian` (kategori, tag, qna, statistik, riwayat;
  `/chatbot-manager` dialihkan ke kategori) terdaftar tanpa syarat fase (`frontend/src/router/index.ts:132-133`). Guard router
  hanya memeriksa `meta.roles` = `CHATBOT_ADMIN_ROLES` (role 1); role lain dialihkan ke halaman `forbidden`
  (`router/index.ts:176-177`). Menu Pengaturan → "Chatbot Manager" ber-`phase: 8` tanpa `rilis` (`nav.config.ts:297-312`), jadi
  tersembunyi di `ACTIVE_PHASE = 5`; halaman tetap terbuka lewat **URL langsung** dengan akun role 1 (pola F7-14, README chatbot
  FE `:25-26`).
- **Halo Simpeg (helpdesk):** item `halo` masih `{ key: 'halo', … phase: 8 }` tanpa `to`/`roles` (`nav.config.ts:320`). Untuk
  bagian B, build lokal juga mengisi `to`/`roles` item `halo` supaya menu dan badge tampil (PRD `:375`, `:607-608`; `8_2.md:107-110`,
  `:131-134`), tanpa commit (§K K-01).
- Menaikkan `ACTIVE_PHASE` ke 8 juga membuka menu fase 6–7 yang masih tersembunyi. Butir menu fase lain bukan cakupan bagian A/B,
  kecuali §HL.

**`.env` uji sementara** (restart backend, kembalikan sesudah butirnya; hanya kunci yang ditulis, nilai tidak):
`chatbot.kunciTokenFeedback` diisi ≥ 32 byte acak untuk seluruh QA (README-deploy §1m), dan dikosongkan sementara hanya untuk
AL5. `arsip.penyajianBerkasLegacy` `publik`/`privat` untuk HP14. `id_satker_kepegawaian` di Web Config demo untuk HA7 (lewat UI,
dikembalikan sesudahnya).

**Isi kolom Hasil:** ✅ lolos / **✅ sebagian** (bagian yang dijalankan lolos; bagian yang tidak dijalankan disebut) / ❌ gagal
(+ nomor temuan di §T) / — tidak berlaku / **[test-otomatis]** (diterima lewat bukti test otomatis gate `main`, lihat bagian C)
/ **[test-otomatis] sebagian** (test hanya mencakup sebagian klaim; celahnya disebut) / **[mock-terbatas]**. Bukti: tangkapan
layar UI, respons API, keluaran perintah, atau baris DB demo — **tanpa** NIP/NIK, host, IP, token, kredensial, maupun isi pesan
nyata. Popover snapshot (NIP/jabatan/satker), kolom NIP ekspor, dan nama pengirim **wajib disamarkan** di tangkapan layar. Isi pesan
selalu sintetis berpenanda `[QA-F8 <kode>]`. Sebut kodenya (mis. "akun U1", "CQ2").

### Alat bantu

**Rujukan singkat.** `VanyaWidget.vue`, `VanyaAvatar.vue` = `frontend/src/features/halo-simpeg/chatbot/components/widget/…`;
`percakapan.ts` = `frontend/src/features/halo-simpeg/chatbot/stores/percakapan.ts`; `constants.ts` =
`frontend/src/features/halo-simpeg/chatbot/constants.ts`; `*.spec.ts` chatbot = `frontend/src/features/halo-simpeg/chatbot/__tests__/…`;
`WidgetEndpointTest.php`, `AdminEndpointTest.php`, `ImporQnaTest.php`, `StatistikRiwayatTest.php`, `SkemaChatbotTest.php`,
`KontrakFixtureTest.php` = `backend/tests/database/HaloSimpeg/Chatbot/…`; `MesinJawabPembandingTest.php`, `ConfigChatbotTest.php` =
`backend/tests/unit/HaloSimpeg/Chatbot/…`; `ChatbotService.php` = `backend/app/Libraries/HaloSimpeg/Chatbot/ChatbotService.php`;
`nav.config.ts` = `frontend/src/shared/layouts/nav.config.ts`; "PRD" tanpa nama = PRD Fase 8 v1.3.

**Memanggil API** (Git Bash). Token akses ada di cookie httpOnly `access_token` (DevTools → Application → Cookies); filter JWT
juga menerima header `Authorization: Bearer`. Token **jangan** ditempel di laporan. Amplop galat
`{"status":"error","message":…,"errors":{…}}`; 403 dari filter role berpesan `Forbidden`.

```bash
B=<dasar API>/api/v1
T="Authorization: Bearer <JWT>"
curl -s -H "$T" "$B/halo-simpeg/chatbot/widget/kategori"                       # bubble topik
curl -s -H "$T" "$B/halo-simpeg/chatbot/widget/populer"                        # populer
for i in $(seq 1 21); do curl -s -o /dev/null -w "%{http_code}\n" -H "$T" -H "Content-Type: application/json" \
  -d '{"pesan":"[QA-F8 AW14] cuti"}' "$B/halo-simpeg/chatbot/widget/balas"; done   # throttle balas (20/menit)
curl -s -H "$T" "$B/halo-simpeg/tiket/ringkasan"                               # bagian B (sesudah MAKE-071)
```

Path endpoint di atas dicocokkan dengan README kontrak chatbot sebelum dipakai; bila berbeda, README yang berlaku.

**Kueri DB demo** (baca saja). Di laporan cukup sebut jumlah baris, `entity`, `event`, dan nama kolom; jangan menyalin isi pesan,
NIP, atau path berkas.

```sql
SELECT COUNT(*) FROM log_chatbot;                                    -- sebelum/sesudah AW8 (AL1)
SELECT entity, event, COUNT(*) FROM audit_logs
  WHERE entity IN ('qna_kategori','qna_tag','qna','qna_has_tag') GROUP BY entity, event;   -- AL2 (entity = nama tabel model)
SELECT COUNT(*) FROM audit_logs WHERE after_json LIKE '%[QA-F8 AW%' OR before_json LIKE '%[QA-F8 AW%'; -- AL3, harus 0
```

Nama tabel/kolom dicocokkan dengan DBV-033 (`backend/docs/db-review/DBV-033-skema-chatbot.md`) dan DBV-028
(`backend/docs/db-review/DBV-028-skema-helpdesk.md`) sebelum dijalankan.

## Data uji gabungan

Semua data **sintetis**, berpenanda `[QA-F8 <kode>]` di judul/pertanyaan/subjek/pesan. Skrip ada di folder demo-seed (di luar
repo), idempoten, ber-guard DB demo.

| Kode | Data | Untuk butir |
|---|---|---|
| Akun | Akun demo per role 1–8 (berkas akun demo di luar repo) + akun demo sintetis tambahan untuk peran helpdesk (P1–P6, U1–U4) | semua |
| CK1 | Kategori chatbot aktif, `tampil_bubble = 1`, dengan ≥ 7 QnA aktif (2 unggulan) | AW5, AW6, AV3 |
| CK2 | Kategori aktif `tampil_bubble = 1` tanpa QnA aktif | AW5 |
| CK3 | Kategori **Tidak Aktif** dengan QnA aktif CQ5 | AW10, AA4 |
| CK4 | Kategori aktif `tampil_bubble = 0` dengan QnA aktif | AW5 |
| CT1, CT2, CT3 | Tag aktif, tag Tidak Aktif, tag Dihapus (status 10) | AA6, AA8, AW10 |
| CQ1 | Empat QnA unggulan aktif (populer = 3 terbaru) | AW7 |
| CQ2 | QnA aktif berpertanyaan > 300 karakter | AW6, CA28 |
| CQ3 | QnA berjawaban HTML (daftar, tautan, tebal) | AW6, AV3 |
| CQ4 | Tag berjudul berawalan `=` | AA6 (ekspor) |
| CQ5 | QnA aktif di CK3 | AW10 |
| CX1 | Berkas impor xlsx valid (kategori/tag baru, judul ganda untuk dedup, tag ganda) | AA10 |
| CX2 | Berkas impor xlsx dengan satu baris galat | AA10 |
| CX3 | Berkas bukan xlsx; berkas > 2 MB; berkas berjudul kolom salah | AA10 |
| CL | Riwayat log chatbot > 1 halaman, tersebar di beberapa hari WIB (termasuk dekat tengah malam WIB) | AA12, AA13 |
| KA, KB | Kategori helpdesk aktif | bagian B |
| KC | Kategori helpdesk nonaktif | HP4, HA |
| KD | Kategori aktif yang punya tiket Open tetapi tanpa petugas aktif (sesudah HA10 Nonaktifkan) | HA10 |
| P1 | Petugas akun role 2 ber-NIP, kategori KA | HT, HU |
| P2 | Petugas akun role 1 tanpa NIP, kategori KA + KB (≥ 2 kategori) | HT3, HU3, HS |
| P3 | Petugas akun role 6, kategori KB | HU3, HT10 |
| P4 | Petugas nonaktif, kategori KA | HT9, HE1 |
| P5 | Petugas akun role 3, kategori KA | HB6 (lonceng) |
| P6 | Petugas akun role 4, kategori KB | HB8 (badge saja, di luar `NOTIFIKASI_ROLES`) |
| U1 | Pemilik akun role 2 | HP |
| U2 | Pemilik akun role 7 | HP, HX |
| U3 | Pemilik akun role 2 yang juga petugas KB, membuat tiket di KA | HP20 (B-19) |
| U4 | P1 membuat tiket di KA (pemilik-petugas kategori yang sama) | HT12, HR5 |
| T1 | Tiket Open KA milik U1 dengan lampiran baru pdf | HP, HT, HP13 |
| T2 | Tiket Open KB milik U2, dibalas bersilang dengan T1 | HX |
| T3 | Tiket Closed KA | HP18 |
| T4 | Tiket berkategori NULL milik U1 (sisip langsung ke DB demo meniru impor F-02) | HN |
| T5 | Tiket KA milik U1 tanpa pesan (meniru impor) | HP15 |
| T6 | Tiket impor dengan lampiran ber-path legacy berspasi (berkas sintetis di storage demo) | HP13, HP14, HT11 |
| T7 | Dua tiket yang merujuk path lampiran legacy yang sama | HT11 |
| T8 | Tiket KA sudah dibaca petugas, `notify_pic = 1`, `notify_at` sudah lewat | HT5, HB1, HB2 |
| T9 | Pesan berisi tanda kutip, backslash, `<b>x</b>`, dan baris baru | HP12 |

Data ekspor > 10.000 baris dan sel > 32.767 karakter tidak ditanam di demo; cukup [test-otomatis] MAKE-074 (§K K-13). Cara membuat
T4–T7 diputus di §K K-11. Kandidat petugas (P1–P6) harus berada di satker kepegawaian demo (**bergantung H8-7**, §K K-10, K-17).

## GF. Gerbang & prasyarat umum (PRD §0, §3.1, §11)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| GF1 | §0, §11 | `git merge-base --is-ancestor 46f4d669 <SHA QA>` | PRD v1.3 (CR-136) ada di SHA QA | ✅ `46f4d669` (PRD v1.3) leluhur SHA QA `67f3303c` dan `e4392507`; `7ecbe12b` (PRD v1.4, CR-138) leluhur `e4392507` (`git merge-base --is-ancestor`) |
| GF2 | §3.1 (PRD `:257-269`) | Cek izin workload paket QA | CR-132 berjalan; CR-133 dan CR-134 dst. ✔ disetujui Horii 17-11-2026 (§K K-03); izin bagian B mengikuti izin [#9] | ✅ CR-132 di `main` `67f3303c`; CR-133 dan CR-134 dst. dalam izin [#10-WorkloadQAChatbot] (Horii 17-11-2026, §K K-03); bagian B dalam izin [#9]; login akun demo atas izin Horii [#111] dan [#112] |
| GF3 | §2.1, §11 (PRD `:1350-1357`) | Gate penuh `./check.sh` di worktree demo + CI `quality-gate` `main` pada SHA QA (preflight `.env` minimal, captcha mock) | Hijau; jumlah test, "No errors", dan tanpa "Fatal" tercatat (pola GF5 F10) | ✅ sebagian: CI #899 `main` `e4392507` hijau; angka gate penuh `./check.sh` lokal pada SHA QA tidak tercatat di hasil QA |
| GF4 | — | Cek status checklist ini | Checklist disepakati reviewer CR; butir ◻ §K yang menahan bagian yang akan diuji sudah diputus | ✅ sebagian: checklist di `main` `67f3303c`, disepakati reviewer; butir ◻ §K yang menahan uji dijalankan sesuai usulannya (K-01, K-11, K-13, K-14, K-17), kolom Keputusan §K belum diperbarui (E7) |
| GF5 | — | Jalankan skrip data uji (demo-seed, di luar repo) | Data bagian yang diuji tersedia; penanda `[QA-F8 …]` terlihat di UI | ✅ data uji `[QA-F8 …]` (demo-seed, di luar repo) tampil di UI; data bagian B tambahan (P5, P6, T3–T8) ditanam langsung di DB demo |

## Bagian A — Chatbot "Vanya" (E-10; AC-E10.1..AC-E10.29)

Status bagian A: **selesai** (✅ 72, ✅ sebagian 14, [test-otomatis] 3, [test-otomatis] sebagian 1; ❌ 0) pada `67f3303c`,
`d659acf6`, dan `e4392507`; T-01..T-03 tuntas (CR-134). Semula: siap diuji pada `a5cb7300`. Ringkasan cakupan AC-E10 (rincian bukti test di bagian C): OTO penuh 19 (AC-E10.1
dokumen + .2–.8, .10–.12, .14, .15, .17, .18, .24, .25, .27, .29); OTO + spot manual: .9, .13, .16, .28; OTO sebagian + manual:
.19 (+ CR-133), .20, .21, .26; OTO gate kedua jalur, diperiksa reviewer di M7 (regresi FAQ manual di HZ4): .23 (CA22); manual
penuh: .22; N/A: .30 (di luar DoD, PRD `:1352`). Jumlah 19 + 4 + 4 + 1 + 1 + 1 = 30. §7.8 "thread tidak ter-mix" milik
helpdesk (bagian B §HX), bukan bagian A.

### AG. Prasyarat chatbot

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| AG1 | AC-E10.1, G8-10 (`Kesiapan_Fase8.md:47`) | `git merge-base --is-ancestor` untuk `3fb8c5f7`, `4af0c188`, `2a8f405d` terhadap SHA QA | Ketiganya leluhur SHA QA | ✅ `3fb8c5f7`, `4af0c188`, `2a8f405d` leluhur SHA QA `67f3303c` |
| AG2 | F8-50 (PRD `:375`) | Build lokal worktree demo dengan `ACTIVE_PHASE = 8` (`nav.config.ts:97`), tanpa commit | Launcher Vanya tampil untuk role 2; `git status` worktree demo menunjukkan perubahan lokal saja, dan dikembalikan ke 5 sesudah QA bagian A (§K K-01) | ✅ launcher tampil untuk role 2; `git status` demo hanya `nav.config.ts` (lokal); dikembalikan ke 5 sesudah QA (AG4) |
| AG3 | F8-35, README-deploy §1m | `.env` demo berisi `chatbot.kunciTokenFeedback` ≥ 32 byte; cek `writable/logs` app demo | Tidak ada baris `[chatbot] kunci token feedback tidak tersedia`; tombol 👍/👎 tampil pada jawaban cocok | ✅ kunci terpasang (nilai tidak ditampilkan); log tanpa baris peringatan kunci; 👍/👎 tampil |
| AG4 | — | Dengan `ACTIVE_PHASE = 5` (build `main` apa adanya), akun role 1 buka `/chatbot-manager` | Dialihkan ke `/chatbot-manager/kategori`, halaman terbuka; menu "Chatbot Manager" tidak tampil di sidebar | ✅ `ACTIVE_PHASE` 5 (demo bersih): `/chatbot-manager` → `/chatbot-manager/kategori`, halaman terbuka, menu tidak tampil |

### AW. Widget — fungsional (AC-E10.5, .6, .8–.12, .14, .20, .27)

Dijalankan di build lokal AG2 dengan akun demo role 2 kecuali disebut lain.

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| AW1 | AC-E10.20 (PRD `:1217`), F8-43 | Akun role 2 yang sedang wajib ganti password login | Widget tidak dirender selama halaman ganti password (`RedesignShell.vue:199`); sesudah ganti password launcher tampil | [test-otomatis] `VanyaShell.spec.ts` (gerbang `mustChangePassword`); akun wajib ganti password tidak diuji manual |
| AW2 | F8-50 | Build `ACTIVE_PHASE = 5` (tanpa patch), akun role 2 | Launcher tidak ada di DOM; tidak ada request `halo-simpeg/chatbot/widget/*` di Network | [test-otomatis] `VanyaShell.spec.ts`, `VanyaWidget.spec.ts` (gerbang `ACTIVE_PHASE`); build fase 5 dengan role 2 tidak diuji manual |
| AW3 | AC-E10.22 (a), `constants.ts:48-49` | Muat halaman sesudah login | Kartu sapaan/peek muncul ±1,5 detik sesudah halaman dimuat, tampil ±4,5 detik, teks "**Halo, Saya Vanya**, asisten virtual SDMO, klik untuk mulai chat!"; muat ulang di tab yang sama → tidak muncul lagi (sekali per sesi) | ✅ kartu sapaan tampil dengan teks tepat; sekali per sesi (`sessionStorage`) |
| AW4 | AC-E10.20 | Klik launcher | Dialog berlabel "Chat dengan Vanya" terbuka; salam pembuka `TEKS_VANYA.salam` (`constants.ts:27-28`); fokus ke kotak ketik | ✅ dialog berlabel "Chat dengan Vanya", salam pembuka, fokus ke kotak ketik |
| AW5 | AC-E10.14 (PRD `:1201`) | Klik "Topik" | Bubble topik hanya CK1 (bukan CK2 tanpa QnA aktif, CK3 Tidak Aktif, CK4 `tampil_bubble = 0`); urut id menaik | ✅ hanya CK1 (CK2, CK3, CK4 tidak muncul) |
| AW6 | AC-E10.5, AC-E10.14, AC-E10.29 | Klik CK1, lalu klik satu pertanyaan (termasuk CQ2 dan CQ3) | Maks 6 pertanyaan (unggulan dulu); balon pengguna berisi pertanyaan; jawaban QnA itu tampil (HTML CQ3 lewat `SafeHtml`), CQ2 > 300 karakter tetap dijawab; penilaian 👍/👎 tersedia | ✅ 6 pertanyaan, unggulan dulu; HTML CQ3 tampil; CQ2 > 300 karakter terjawab; 👍/👎 ada |
| AW7 | AC-E10.14 | Klik "Populer"; ulangi saat tidak ada QnA unggulan | 3 QnA unggulan terbaru (CQ1, id menurun); tanpa unggulan → "Belum ada pertanyaan populer saat ini. Silakan ketik pertanyaan Anda." | ✅ sebagian: Populer = 3 QnA CQ1; kasus tanpa unggulan tidak diuji |
| AW8 | AC-E10.5, AC-E10.7 | Ketik pertanyaan yang cocok `[QA-F8 AW8] …` lalu Enter | Indikator "Vanya sedang mengetik" tampil, lalu jawaban + penilaian; DB demo: `log_chatbot` bertambah tepat 1 (AL1) | ✅ indikator mengetik → jawaban + penilaian; `log_chatbot` +1 |
| AW9 | AC-E10.6 (PRD `:1189`), F8-24 (PRD `:349`) | Ketik pertanyaan yang tidak cocok | Kalimat fallback dev + maks 3 saran, tanpa penilaian; klik saran → dijawab lewat jalur bubble | ✅ kalimat fallback + 3 saran, tanpa penilaian |
| AW10 | AC-E10.27 (PRD `:1234`) | Ketik pertanyaan CQ5 (QnA aktif di kategori CK3 Tidak Aktif); ketik kata yang hanya ada di tag CT2/CT3 | CQ5 terjawab lewat ketikan, CK3 tidak muncul di bubble topik; tag CT2/CT3 tidak menambah skor | ✅ sebagian: CQ5 terjawab lewat ketikan, CK3 tidak di topik; tag CT2/CT3 tidak diuji manual ([test-otomatis] CA26) |
| AW11 | AC-E10.8 (PRD `:1191`) | (1) Nilai 👍 pada jawaban A. (2) Penimpaan nilai lewat API (widget tidak menyediakan nilai ulang sesudah terkirim, `VanyaPenilaian.vue:25-26`, `:46`): `POST widget/feedback` dua kali dengan `id_log` + `token` jawaban B yang sama (nilai `1` lalu `-1`), lalu baca `log_chatbot`; atau cukup rujuk CA8. (3) Pada jawaban C yang belum dinilai: matikan backend, lalu klik 👎; nyalakan backend, lalu klik lagi | (1) "Terima kasih atas penilaiannya!"; hanya tombol terpilih yang tersisa, dalam keadaan disabled. (2) Keduanya 200; nilai di `log_chatbot` tertimpa menjadi `-1` (CA8 `testFeedbackTokenSahDanDitimpa:179`). (3) Saat gagal → "Penilaian gagal terkirim." + "Coba lagi:", kedua tombol aktif kembali; sesudah backend menyala → "Terima kasih, masukan Anda membantu kami memperbaiki jawaban." | ✅ sebagian: (1) 👍 → teks terima kasih, `feedback` = 1; (2) akun role 6, dua POST dengan token sama → 200, 200, nilai tertimpa -1; (3) gagal kirim + "Coba lagi" tidak diuji manual |
| AW12 | AC-E10.11 (PRD `:1196`) | Kirim kosong/spasi saja; tempel 300 emoji; tempel 301 karakter | Kosong → tombol kirim disabled, tidak ada request; 300 emoji lolos; input dipangkas ke 300 code point (`VanyaWidget.vue:55-75`, `percakapan.ts:163-167`), tanpa popup validasi native | ✅ kosong → kirim disabled; 300 emoji lolos; 301 dipangkas ke 300; tanpa popup native |
| AW13 | AC-E10.12 (PRD `:1198`) | Ketik `[QA-F8 AW13] <script>alert(1)</script><img src=x onerror=alert(1)>` | Tampil sebagai teks literal; tidak ada dialog; inspeksi DOM: tidak ada elemen `script`/atribut `on*` di widget | ✅ tampil literal; 0 `script`/`img`/atribut `on*` di widget |
| AW14 | AC-E10.10 (PRD `:1194`), AC-E10.22 (g) | Kirim 21 pesan dalam satu menit (UI atau `curl` di Alat bantu) | Pesan ke-21 → balon galat "Terlalu banyak permintaan ke Vanya. Coba lagi dalam satu menit." (`ChatbotService.php:28`); bubble topik/populer (kelompok baca) tetap berfungsi; `log_chatbot` tidak bertambah untuk balas yang kena 429 | ✅ 21 × 200 lalu 429 dengan teks tepat (bucket token terisi selama loop, sesuai Throttler); `widget/kategori` tetap 200 |
| AW15 | AC-E10.22 (g), `constants.ts:41-42` | Matikan backend: buka Topik; lalu kirim pesan | Muat → "Maaf, Vanya sedang tidak dapat memuat data. Silakan coba beberapa saat lagi."; kirim → "Maaf, terjadi gangguan koneksi. Silakan coba lagi." | ✅ teks galat memuat dan galat koneksi tepat; backend dinyalakan kembali |
| AW16 | AC-E10.20 (PRD `:1217`) | Hanya keyboard: Tab ke launcher, Enter/Space buka, Tab ke chip + Enter, ketik + Enter, Tab ke 👍/👎 + Enter/Space, Escape | Semua berfungsi; fokus terlihat; sesudah memilih chip/menilai fokus kembali ke kotak ketik; Escape menutup dan fokus kembali ke launcher | ✅ sebagian: Enter membuka + fokus kotak ketik, Escape menutup + fokus ke launcher (17-11); bersama AW17 (18-11): sesudah memilih chip dan menilai (Spasi) fokus kembali ke kotak ketik; Tab ke chip + Enter dan ketik + Enter tidak dicatat tersendiri |
| AW17 | AC-E10.20 | Pembaca layar (Narrator/NVDA) pada launcher, dialog, chip, penilaian | Peran dan label terbaca (launcher "Chat dengan Vanya, asisten virtual SDMO"; dialog; tombol "Membantu"/"Tidak membantu") | ✅ sebagian: peran dan label launcher, dialog, kotak ketik, chip, "Membantu"/"Tidak membantu", "Tutup chat" benar di pohon aksesibilitas Brave + keyboard (`d659acf6`); Narrator/NVDA asli tidak dijalankan |
| AW18 | AC-E10.20 (PRD `:1217-1219`) | Bertanya, pindah halaman lain, buka lagi; logout, login akun role 6; lalu bertanya lagi sebagai role 6, logout, dan login akun role 1 (atau 3) di tab yang sama | Percakapan bertahan lintas halaman; sesudah ganti akun store kosong (pesan, status buka, token); sesudah login role 1/3: launcher dan panel hilang dari DOM, tidak ada request `halo-simpeg/chatbot/widget/*` di Network, dan state percakapan tidak tersisa | ✅ percakapan bertahan lintas halaman; ganti akun role 2 → role 1 → role 6: store kosong; role 6/7 → role 3 di tab yang sama: launcher dan panel hilang, tanpa request widget |
| AW19 | AC-E10.20 | Blokir penyimpanan situs (DevTools → Application → blokir data situs / mode privat yang melempar galat `sessionStorage`) | Widget dan kartu sapaan tetap berfungsi tanpa galat di konsol yang memutus UI | ✅ sebagian: `Storage` dipaksa melempar `SecurityError` sesudah halaman dimuat → widget tetap berfungsi tanpa galat konsol; storage diblokir sejak awal muat tidak diuji |

### AV. Widget — visual terhadap acuan PN8-8 (AC-E10.22 (a)–(m), K-11)

**Legenda huruf.** PRD tidak menomori butir AC-E10.22. Huruf (a)–(j) = sepuluh butir AC-E10.22 PRD `:1221-1224` berurutan: (a)
launcher + kartu sapaan/peek, (b) salam pembuka, (c) bubble topik → bubble pertanyaan, (d) "Topik"/"Populer", (e) fallback + saran,
(f) penilaian 👍/👎 + "Coba lagi", (g) galat koneksi/429, (h) layar sempit, (i) `prefers-reduced-motion`, (j) lima halaman admin
(§AA). Huruf (k) input + tombol kirim, (l) header panel, dan (m) panel desktop adalah **butir tambahan checklist** dari hasil banding
PN8-8, bukan butir PRD.

Acuan: tangkapan layar widget dev dari Horii (diterima 16-11-2026), disebut **acuan PN8-8 (di luar repo)**; isinya tidak disalin ke
repo. Dasar: K-11/F8-33 (PRD `:358`, `:1452`), yaitu tampilan ikut widget dev dengan token v2, tanpa Figma; README chatbot FE
`:33-36`. **Keputusan Horii 17-11-2026 (PN8-8 "ikut rekomendasi", §K K-02):** avatar tetap ilustrasi SVG (foto tidak dipakai),
aksen biru `brand-tertiary` (bukan pink dev), saat memuat jawaban tombol kirim disabled + indikator (perilaku v2), launcher
64 px dan gaya nama "Vanya" tetap v2; perbaikan [K] avatar mini bot rata atas masuk CR-134. Nomor "#n" = nomor selisih di
hasil banding PN8-8 (di luar repo): 18 sama, 22 selisih = 1 temuan CR-134 (#5, §T T-01) + 9 sesuai keputusan Horii 17-11 (#2, #3,
#8, #9, #12, #15, #17, #21, #22) + 12 diterima [V2]/K-11 (keduanya di §ABB). Selisih yang tercantum di §ABB **bukan** temuan.

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| AV1 | AC-E10.22 (a) | Bandingkan launcher + kartu sapaan/peek dengan acuan PN8-8 | Tata letak dan teks sama; launcher 64 px (`VanyaWidget.vue:349`), avatar ilustrasi SVG, bayangan tipis, denyut biru, hover 1.05 (#17, #18, #2/#21 → ABB) | ✅ launcher SVG + kartu sapaan sesuai keputusan PN8-8 |
| AV2 | AC-E10.22 (b) | Salam pembuka | Teks dan urutan sama dengan dev; **avatar mini bot sejajar tepi atas balon** (#5). Di `a5cb7300` avatar masih turun ke baris terakhir (`VanyaWidget.vue:209-210` `items-end` + `mb-4`) → temuan diketahui §T T-01 (CR-134); diuji ulang sesudah CR-134 | ✅ avatar mini rata atas (T-01 diperbaiki CR-134 paket 1, diuji pada `67f3303c`) |
| AV3 | AC-E10.22 (c) | Topik → pilih CK1 → pertanyaan | Chip outline teks biru `brand-tertiary` (#9), balon pengguna biru (#8) muncul saat topik diklik, indikator mengetik juga saat memuat topik/pertanyaan (#7), chip disabled selama memuat (#12) → ABB; chip ber-ring fokus (#10) | ✅ chip outline biru, balon pengguna biru, ring fokus (ABB) |
| AV4 | AC-E10.22 (d) | Footer "Topik"/"Populer" | Pill `brand-primary`, ukuran ±1–2 px beda dari dev (#11), disabled selama memuat (#12) → ABB | ✅ pill footer `brand-primary` (ABB) |
| AV5 | AC-E10.22 (e) | Fallback + saran (AW9) | Teks sama dengan dev F8-24 (PRD `:349`; tidak tampak di acuan PN8-8); balon bot lebih rapat, ekor 2 px, tanpa bayangan (#6 → ABB) | ✅ fallback + saran; balon bot rapat (ABB) |
| AV6 | AC-E10.22 (f) | Penilaian 👍/👎 + "Coba lagi" (AW11) | Tombol penilaian di bawah jawaban, teks terima kasih/gagal sesuai `constants.ts:37-40` (tidak tampak di acuan PN8-8) | ✅ penilaian + "Terima kasih atas penilaiannya!" |
| AV7 | AC-E10.22 (g) | Galat koneksi / 429 (AW14, AW15) | Balon galat terbaca, tidak merusak tata letak percakapan (tidak tampak di acuan PN8-8) | ✅ balon galat koneksi rapi, tata letak tidak rusak |
| AV8 | AC-E10.22 (h) | Emulasi viewport 375 px, buka widget | Panel tidak terpotong, tanpa gulir horizontal halaman, input dan tombol kirim terjangkau; margin kiri ±8 px vs kanan 16 px (#20) dicatat saja, bukan temuan (ABB) | ✅ emulasi viewport 375 × 812: jendela di dalam viewport, tanpa gulir horizontal, kotak ketik dan kirim terjangkau; margin kiri 8 px vs kanan 16 px dicatat (ABB21) |
| AV9 | AC-E10.22 (i) | Aktifkan `prefers-reduced-motion` (DevTools → Rendering → Emulate CSS media feature) | Denyut launcher mati dan animasi `motion-reduce` berlaku (`VanyaWidget.vue:349`, `:416-423`); tidak ada test Vitest untuk butir ini | ✅ sebagian: aturan `prefers-reduced-motion` ada di CSS terpasang; emulasi media tidak tersedia di alat QA |
| AV10 | AC-E10.22 (k) | Input + tombol kirim: buka, ketik, kirim | Cincin fokus biru `brand-tertiary/50` langsung terlihat karena input fokus saat dibuka (#13); batas 300 tanpa popup native (#14); tombol kirim biru 36 px ikon outline (#15, #16), pudar saat kosong; **saat memuat jawaban tombol disabled + indikator** (keputusan Horii 17-11) → ABB | ✅ cincin fokus biru langsung terlihat; kirim pudar saat kosong (ABB) |
| AV11 | AC-E10.22 (l) | Header panel | Gradien `brand-primary` → `#2a4a80` (#1), font Public Sans (#4), nama "Vanya" `text-sm font-semibold` tetap v2 (#3, keputusan Horii 17-11), avatar SVG → ABB | ✅ header gradien, gaya nama v2, avatar SVG (ABB) |
| AV12 | AC-E10.22 (m) | Panel desktop (≥ 1280 px) | 384 × 576, `rounded-2xl`, border + `shadow-panel` (#19 → ABB); di layar pendek tinggi panel mengikuti viewport | ✅ panel lebar 384; tinggi mengikuti viewport (567 px pada viewport 679) |

### AA. Chatbot Manager — lima halaman admin (AC-E10.13, .15–.18, .21, .24–.28; AC-E10.22 (j))

Akun demo role 1, buka lewat URL langsung `/chatbot-manager/<bagian>` (AG4). Tidak ada frame Figma (K-11); acuan = pola redesign
`page-fill` + ⋮ (AGENTS.md §1).

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| AA1 | AC-E10.21, AC-E10.22 (j) | Buka Kategori, Tag, QnA, Statistik, Riwayat | `page-fill` + `table-fill` + `UiCard fill`; header tabel sticky, hanya body tabel menggulir; navigasi antarbagian berfungsi; judul tab "Chatbot Manager · <Bagian>" | ✅ judul tab "Chatbot Manager · <Bagian>" di lima halaman; `thead th` sticky di Kategori/Tag/QnA/Riwayat (tabel Statistik kecil, statis) |
| AA2 | AC-E10.21, AGENTS.md §1 | ⋮ pada baris Kategori, Tag, QnA | Satu ⋮ di kolom Aksi paling kanan; urutan Edit → Aktifkan/Nonaktifkan → Hapus (merah, garis pemisah, `ConfirmDialog`); tanpa Pulihkan; aria-label "Aksi untuk <judul>"; `testid` `chatbot-<kategori/tag/qna>-actions-<id>`; kolom Status hanya badge | ✅ ⋮ Kategori: Edit → Nonaktifkan/Aktifkan → Hapus (merah, pemisah); aria-label dan `testid` sesuai |
| AA3 | AC-E10.13 (PRD `:1199`) | ⋮ Nonaktifkan kategori CK1, lalu cek widget; ⋮ Aktifkan kembali | Baris tetap di daftar berbadge "Tidak Aktif", jawaban/tag QnA tidak berubah; CK1 hilang dari bubble topik selama Tidak Aktif | ✅ CK1 Tidak Aktif → `widget/kategori` kosong, baris berbadge di saringan "Tidak Aktif"; diaktifkan kembali |
| AA4 | AC-E10.13 | ⋮ Hapus satu kategori/tag/QnA sintetis (konfirmasi) | Hilang dari daftar dan widget; tidak ikut ekspor; tidak dicocokkan impor | ✅ ⋮ Hapus QnA → ConfirmDialog → hilang dari daftar, DB status 10 (hapus lunak) |
| AA5 | AC-E10.24 (PRD `:1229`) | Tambah kategori/tag berjudul sama dengan baris aktif (beda huruf/spasi tepi); lalu judul sama dengan baris yang sudah Dihapus | Ganda → 422 tampil di bawah field; judul baris Dihapus boleh dipakai lagi | ✅ judul ganda (beda huruf/spasi tepi) → 422 di bawah field; judul baris Dihapus (CK5) boleh dipakai lagi |
| AA6 | AC-E10.16 (PRD `:1209`) | Tag → ekspor; buka berkas di aplikasi spreadsheet | Kolom dan nama berkas F8-47; tag status 10 tidak ikut; CQ4 berawalan `=` tampil sebagai teks, bukan rumus | ✅ `Export_Tag_QnA_<Ymd_His>.xlsx`; sel CQ4 berawalan `=` berupa teks (tanpa rumus); tanpa `format` → 422 |
| AA7 | AC-E10.25 (PRD `:1231`), AC-E10.12 | QnA → Tambah: kosongkan pertanyaan/jawaban; pilih kategori Tidak Aktif; tag tambahan Tidak Aktif; jawaban hanya `<script>…</script>`; jawaban HTML sah | 422 per kolom; jawaban kosong sesudah sanitasi → 422; jawaban sah tersimpan dan pratinjau lewat `SafeHtml` | ✅ 422 per kolom; jawaban `<script>` saja → 422; kategori Tidak Aktif dan tag status 2 → 422 |
| AA8 | AC-E10.27 | Edit QnA yang punya tag CT2 dan kategori yang sudah Dihapus | CT2 tampil berbadge dan dipertahankan; kategori Dihapus berpenanda, simpan tanpa mengganti → 422; ganti set tag → seluruh set diganti | ✅ kategori Dihapus berpenanda, tag CT2 dipertahankan berbadge, simpan tanpa ganti → 422; pesan ganda T-02 diperbaiki CR-134 paket 2 `d659acf6` (uji ulang: satu elemen) |
| AA9 | AC-E10.28 (PRD `:1237`) | QnA → unduh template | xlsx dengan 4 judul kolom F8-47 berurutan + satu baris contoh | ✅ sebagian: `Template_Import_QnA.xlsx` sah; isi kolom tidak dibaca manual ([test-otomatis] CA27) |
| AA10 | AC-E10.15 (PRD `:1203`) | Impor CX1, CX2, CX3 | CX1: kategori/tag baru dibuat, judul ganda dideduplikasi, ringkasan baris dilewati; CX2: tidak ada yang tersimpan + daftar galat per baris; CX3: 422 (bukan xlsx, > 2 MB, judul kolom salah) | ✅ CX1 (kategori/tag baru, ringkasan baris dilewati), CX2 (tanpa simpan + galat baris), CX3 (422 tiga jenis); galat lama T-03 diperbaiki CR-134 paket 2 `d659acf6` |
| AA11 | AC-E10.26 (PRD `:1233`) | Dengan data > 1 halaman: pindah halaman, saring status/kategori/tag/`q` di Kategori, Tag, QnA; cek Network | Paginasi dan saringan dikirim ke server (query string), status 10 tidak tampil; halaman ke-2 benar (celah test, §K K-07) | ✅ sebagian: Kategori halaman 2 (baris 21–25) dan pencarian lewat server; halaman 2 Tag/QnA tidak diuji (celah test K-07) |
| AA12 | AC-E10.17 (PRD `:1210`) | Statistik dengan data CL | Angka per hari WIB, 4 dan 1 desimal, top-10 ×3 dengan `#id`, teks ter-escape | ✅ sebagian: total, rata-rata skor 4 desimal, kepuasan 1 desimal, top-10 tampil; data dekat tengah malam WIB tidak diperiksa ([test-otomatis] CA17) |
| AA13 | AC-E10.18 (PRD `:1213`) | Riwayat: saring dari/sampai (hari WIB inklusif, termasuk log dekat tengah malam WIB), pindah halaman; log berisi `<b>x</b>` | Hasil sesuai hari WIB; paginasi server; pesan tampil sebagai teks literal | ✅ sebagian: 25 baris per halaman, paginasi server, `<script>` tampil literal; saring hari WIB tidak diuji manual ([test-otomatis] CA18) |
| AA14 | AC-E10.21 | Keadaan memuat/kosong/galat (backend mati, 500) di kelima halaman | Teks memuat/kosong/galat tampil; galat dari amplop; tanpa layar kosong | ✅ + pengamatan: backend mati → banner galat amplop di Tag/QnA/Statistik/Riwayat, tanpa layar kosong; teks kosong tabel tampil bersama banner galat |
| AA15 | AC-E10.21 | Inspeksi DOM kelima halaman + dialog | Tanpa `v-html` di luar `SafeHtml`, `<select>` native yang tampil, `window.confirm`, atau atribut `title` sebagai tooltip (prop `title`/`:title` milik `UiCard`/`ConfirmDialog` bukan atribut native; daftar barisnya di CA21) | ✅ sebagian: Riwayat tanpa `<select>` native yang tampil (dua milik `UiSelect`, `sr-only`) dan tanpa `title`; halaman lain lewat cek statik CA21 |
| AA16 | AC-E10.21, AGENTS.md | Operasikan ⋮, dialog, filter, paginasi hanya dengan keyboard | Tab/Enter/Spasi/Esc/panah berfungsi; fokus terlihat | [test-otomatis] sebagian: Vitest per komponen; keyboard ⋮ dan `UiPilihBanyak` dicek manual di helpdesk (HA8) |
| AA17 | AGENTS.md (viewport) | Viewport 375 px, 768 px, desktop | Tanpa gulir horizontal halaman; tabel menggulir di dalam kartu | ✅ sebagian: 374 px kelima halaman tanpa gulir horizontal halaman, tabel menggulir di dalam kartu; desktop ✅; 768 px tidak tercatat |

### AL. Log & privasi (AC-E10.7, AC-E10.8, AC-E10.19)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| AL1 | AC-E10.7 (PRD `:1190`) | Hitung `log_chatbot` sebelum/sesudah AW8 dan AW6; lihat kolom baris baru | Bertambah tepat 1 per balas; tanpa kolom identitas pengirim | ✅ +1 per balas, tanpa kolom identitas pengirim |
| AL2 | AC-E10.19 (PRD `:1215`) | Sesudah AA3–AA10, kueri `audit_logs` (Alat bantu) | Setiap tulis admin (tambah, ubah, status, hapus, impor) tercatat; laporkan jumlah baris per `entity`/`event` | ✅ `qna` create 4 / update 1; `qna_has_tag` create 3; `qna_kategori` create 3 / update 2; `qna_tag` create 2 (hapus lunak tercatat sebagai `update`) |
| AL3 | AC-E10.19 | Cari penanda `[QA-F8 AW` di `audit_logs` | 0 baris (log chatbot dan isi pesan tidak masuk audit) | ✅ 0 baris |
| AL4 | AC-E10.19 | Sesudah sesi QA bagian A, cari penanda `[QA-F8 AW` di `writable/logs` app demo | Tidak ditemukan. Pelengkap manual; sisi "tidak masuk log aplikasi" dikunci test otomatis lewat CR-133 (§K K-06) | ✅ 0 baris `[QA-F8` di `writable/logs` demo (berkas log hari QA tidak ada; terbaru 16-11); sisi otomatis dikunci CR-133 `19a11ed5` |
| AL5 | AC-E10.8 | `.env` uji sementara: kosongkan `chatbot.kunciTokenFeedback`, restart backend, bertanya; kembalikan | Jawaban tetap tampil tanpa tombol 👍/👎; `writable/logs` memuat baris peringatan kunci (README-deploy §1m); sesudah dikembalikan penilaian tampil lagi | ✅ kunci dikosongkan: jawaban tanpa token (`bisa_feedback=false`) + baris peringatan di log; kunci dipulihkan → `bisa_feedback=true` + token (tanpa restart); tampilan tombol 👍/👎 mengikuti `bisa_feedback` [test-otomatis] |

### AM. Matriks role 1–8 — chatbot (AC-E10.9, AC-E10.20; F8-30, F8-32)

| # | Role (akun) | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| AM1 | 1 (akun demo role 1) | Build AG2: launcher; `/chatbot-manager`; API widget | Tanpa launcher; lima halaman admin terbuka; API widget 403 | ✅ tanpa launcher; API widget 403; API admin 200; lima halaman admin terbuka |
| AM2 | 2 (akun demo role 2) | Launcher; `/chatbot-manager/kategori`; API admin | Launcher tampil; URL admin → halaman `forbidden`; API admin 403 | ✅ launcher tampil; `/chatbot-manager/kategori` → halaman 403; API admin 403 |
| AM3 | 3 (akun demo role 3) | Sama dengan AM2 | Tanpa launcher; admin `forbidden`/403; API widget 403 | ✅ tanpa launcher/panel; API widget dan admin 403; tanpa menu |
| AM4 | 4 (akun demo role 4) | Sama dengan AM3 | Sama dengan AM3 | ✅ tanpa launcher, 0 request widget otomatis; API widget/admin 403; admin → /403 |
| AM5 | 5 (akun demo role 5) | Sama dengan AM3 | Sama dengan AM3 | ✅ tanpa launcher, 0 request widget otomatis; API widget/admin 403; admin → /403 |
| AM6 | 6 (akun demo role 6) | Sama dengan AM2 | Sama dengan AM2 | ✅ launcher tampil, `widget/kategori` 200, API admin 403, admin → /403, tanpa menu Chatbot Manager |
| AM7 | 7 (akun demo role 7) | Sama dengan AM2 | Sama dengan AM2 | ✅ launcher tampil, `widget/kategori` 200, API admin 403, admin → /403, tanpa menu Chatbot Manager |
| AM8 | 8 (akun demo role 8) | Semua endpoint `halo-simpeg/chatbot/*`; menu | 403 dari filter route; tanpa launcher dan tanpa menu | ✅ tanpa launcher/menu; 10 endpoint `halo-simpeg/chatbot/*` → 403 |
| AM9 | §11.1 | [test-otomatis] `KontrakMatriksRoleTest` (prefiks `halo-simpeg/chatbot`, `backend/tests/feature/KontrakMatriksRoleTest.php:60`), `WidgetEndpointTest::testRoleWidgetDanAdmin:67`, `AdminEndpointTest::testRoleAdminSaja:51` | Hijau di gate/CI SHA QA | [test-otomatis] CI #899 `main` `e4392507` hijau |

### ABB. "Bukan bug" chatbot — sesuai keputusan (PN8-8 17-11-2026, K-11, F8-50)

Pelaksana QA **tidak** mencatat hal berikut sebagai temuan. Kolom Hasil diisi ✅ bila perilaku teramati sesuai.

| # | Perilaku | Dasar | Butir terkait | Hasil |
|---|---|---|---|---|
| ABB1 | Avatar header dan avatar mini memakai ilustrasi SVG (`VanyaAvatar.vue:1-46`), bukan foto acuan PN8-8; mekanisme foto F8-45 (PRD `:370`) tidak diimplementasikan (#2) | Keputusan Horii 17-11-2026 (PN8-8), PN8-7 (PRD `:82`) | AV2, AV11 | ✅ (AV2, AV11) |
| ABB2 | Avatar launcher memakai ilustrasi SVG yang sama (#21) | Keputusan Horii 17-11-2026 (PN8-8) | AV1 | ✅ (AV1) |
| ABB3 | Balon pengguna biru `bg-brand-tertiary` (`VanyaWidget.vue:200-207`), dev pink gradien (#8) | Keputusan Horii 17-11-2026, K-11 | AV3 | ✅ (AV3) |
| ABB4 | Chip topik outline/teks biru `brand-tertiary` (`VanyaWidget.vue:238`), dev pink (#9) | Keputusan Horii 17-11-2026, K-11 | AV3 | ✅ (AV3) |
| ABB5 | Tombol kirim biru (`VanyaWidget.vue:325`), dev magenta (#15) | Keputusan Horii 17-11-2026, K-11 | AV10 | ✅ (AV10) |
| ABB6 | Aksen pink dev secara keseluruhan diganti `brand-tertiary`, tanpa token pink baru (#22) | Keputusan Horii 17-11-2026, README chatbot FE `:33-36` | AV1–AV12 | ✅ (AV1–AV12) |
| ABB7 | Indikator mengetik juga tampil saat memuat topik/populer/pertanyaan (`percakapan.ts:73-84`; `VanyaWidget.vue:270-283`); dev hanya saat kirim (#7) | [V2] K-11 (rekomendasi banding PN8-8; sejalan dengan perilaku memuat v2 yang diputus Horii 17-11-2026 untuk #12) | AV3 | ✅ (AV3, AW8) |
| ABB8 | Topik/Populer, chip, dan tombol kirim disabled selama memuat (`VanyaWidget.vue:240`, `:257`, `:292`, `:301`, `:328`) (#12) | Keputusan Horii 17-11-2026 (tombol kirim disabled + indikator) | AV3, AV4, AV10 | ✅ (AV3, AV4, AV10) |
| ABB9 | Launcher 64 px (`h-16 w-16`, `VanyaWidget.vue:349`), dev 85 px (#17) | Keputusan Horii 17-11-2026, README chatbot FE `:35` | AV1 | ✅ (AV1) |
| ABB10 | Nama "Vanya" `text-sm font-semibold` (`VanyaWidget.vue:176`), dev 16 px/700 (#3); rekomendasi banding untuk menyamakan tidak dipakai | Keputusan Horii 17-11-2026 (gaya nama tetap v2) | AV11 | ✅ (AV11) |
| ABB11 | Gradien header `brand-primary` → `#2a4a80` (`VanyaWidget.vue:170`; ujung hex arbitrer) (#1) | [V2] K-11 | AV11 | ✅ (AV11) |
| ABB12 | Font Public Sans (token redesign, `frontend/tailwind.config.js:42`), dev Montserrat (#4) | [V2] K-11 | AV11 | ✅ (AV11) |
| ABB13 | Balon bot lebih rapat, ekor 2 px, tanpa bayangan (`VanyaWidget.vue:148-149`) (#6) | [V2] K-11, rekomendasi PN8-8 | AV5 | ✅ (AV5) |
| ABB14 | Chip ber-ARIA, ring fokus, disabled saat memuat (`VanyaWidget.vue:151`, `:233`, `:240`) (#10) | [V2] AC-E10.20 | AV3, AW16 | ✅ (AV3, AW16) |
| ABB15 | Footer pill `brand-primary`, ukuran ±1–2 px beda (`VanyaWidget.vue:286`, `:290`, `:299`) (#11) | [V2] K-11 | AV4 | ✅ (AV4) |
| ABB16 | Cincin fokus input biru `brand-tertiary/50` langsung terlihat karena input fokus saat dibuka (`VanyaWidget.vue:319`) (#13) | [V2] AC-E10.20 | AV10 | ✅ (AV10) |
| ABB17 | Batas 300 per code point tanpa popup validasi native (`VanyaWidget.vue:55-75`, `:315`, `:322`; `percakapan.ts:163-167`) (#14) | [V2] F8-41, README chatbot FE | AW12, AV10 | ✅ (AW12, AV10) |
| ABB18 | Tombol kirim 36 px, ikon outline, pudar saat input kosong atau sedang memuat (`VanyaWidget.vue:323-331`) (#16) | [V2] K-11, sejalan keputusan Horii 17-11 | AV10 | ✅ (AV10) |
| ABB19 | Bayangan launcher tipis, denyut biru, hover 1.05 (`VanyaWidget.vue:361`, `:378-410`); reduced-motion sama dengan dev (#18) | [V2] K-11 | AV1, AV9 | ✅ sebagian (AV1; reduced-motion hanya dicek di CSS, AV9) |
| ABB20 | Panel 384 × 576, `rounded-2xl`, border + `shadow-panel` (`VanyaWidget.vue:165`); di layar pendek bisa lebih tinggi dari dev (#19) | [V2] K-11 | AV12 | ✅ (AV12) |
| ABB21 | Layar < 640 px: margin kiri ±8 px vs kanan 16 px (`VanyaWidget.vue:157`, `:165`) (#20) | [V2] K-11, rekomendasi PN8-8 (cukup dicek saat QA layar sempit) | AV8 | ✅ (AV8) |
| ABB22 | Headset pada ilustrasi SVG berwarna magenta `#e2116e` (`VanyaAvatar.vue:41-45`) | Bagian SVG bawaan dev, bukan selisih | AV1, AV11 | ✅ (AV1) |
| ABB23 | Menu "Chatbot Manager" dan widget belum tampil di `main` sebelum CR-135; QA lewat URL langsung / build lokal | F8-50, H8-11 (a) (PRD `:375`, `:1408`) | AG2, AG4 | ✅ (AG2, AG4) |
| ABB24 | Halaman admin bisa dibuka lewat URL langsung oleh role 1 walau menu tersembunyi | Pola F7-14, README chatbot FE `:25-26` | AG4 | ✅ (AG4) |

## Bagian B — Helpdesk Halo Simpeg (E-01..E-09; menunggu MAKE-073)

Status bagian B: **selesai 18-11-2026** pada `main` `e4392507` (✅ 92, ✅ sebagian 34, [test-otomatis] 24, [test-otomatis]
sebagian 3, [mock-terbatas] 4, — 2; ❌ 0 sesudah CR-142/CR-143 di `main` `8bc6c509`). Semula: belum dieksekusi; menunggu
MAKE-070..074, MAKE-097 (+ T-5), dan CR-137 di `main` (PRD `:257-265`, M7 `:1331`). DoD terkait: §7.8 hijau, regresi Fase 4 C-05/C-06 dan FAQ G-10 hijau, QA Lapis 1 lolos (PRD `:1352-1354`). Status kode
di `a5cb7300`: `backend/app/Controllers/Api/HaloSimpeg/` hanya `Chatbot/` + README; binding `ringkasanTiket` masih
`RingkasanTiketNoOp` (`backend/app/Config/Services.php:799-805`); `PREFIKS_CAKUPAN` masih berakhir `halo-simpeg/chatbot`
(`KontrakMatriksRoleTest.php:60`). Keputusan yang tercermin: **H8-10 tetap [I]** (keputusan Horii 17-11-2026, §K K-08; PRD `:1407` masih ◻ sampai disinkron;
F8-10 `:329`; §6.2 `:565-566`),
diuji per skenario AC-E04.4 (§HR); **H8-9** frame Figma helpdesk belum ada → AC-E08.7 diuji terhadap pola redesign AGENTS.md
(§HV); **H8-7** (satker kepegawaian) masih terbuka → butir kandidat petugas ditandai **bergantung H8-7**; H8-15 (b), H8-16 (a1),
D7 (a), D8 (a), R9(1), R3 titik penanda, R10 (PRD `:1412-1434`). Irisan dengan bagian A hanya HP24 dan HX6.

### HG. Gerbang & prasyarat bagian B

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HG1 | §0.1, §3.1 (PRD `:53-59`, `:257-265`) | Cek SHA QA memuat DBV-028 (sudah di `main` pada `a5cb7300`), MAKE-070, MAKE-071, MAKE-072 (+ CR-137), MAKE-073, MAKE-074 (BE + B4 FE), MAKE-097 (+ T-5) | Semua di `main`; SHA dicatat di Status checklist | ✅ `e4392507` memuat DBV-028 `a5cb7300`, MAKE-070 `56a700b1`, MAKE-071 `066315c5`, MAKE-097 `82d698ec`, MAKE-072 + CR-137 `64d68377`, MAKE-074 BE `947b94fd`, MAKE-073 `d90d8a75`, MAKE-074 FE `e4392507` |
| HG2 | §6.1b (PRD `:448-450`, `:527-535`) | Bandingkan README beku `backend/app/Controllers/Api/HaloSimpeg/README.md` dengan §6.1b; fixture `backend/tests/_support/HaloSimpeg/` dan salinannya di `frontend/src/features/halo-simpeg/__tests__/fixtures/` | README = §6.1b (selisih sudah diputus reviewer); fixture lengkap per tabel PRD `:533-535` dan terverifikasi trait [test-otomatis] | [test-otomatis] `KontrakFixtureTiketTest` + `HaloSimpegFixtureTrait` (respons nyata = fixture); CI #899 `main` `e4392507` hijau |
| HG3 | §2.1, §11 (PRD `:148`, `:1350-1357`) | Ulangi GF3 pada SHA QA bagian B | Hijau; jumlah test dan "No errors" tercatat | [test-otomatis] CI #899 `main` `e4392507` hijau; angka gate penuh lokal tidak tercatat (GF3) |
| HG4 | F8-50, H8-11 (a) (PRD `:375`, `:1408`) | Build lokal `ACTIVE_PHASE = 8` + `to`/`roles` item `halo` (`nav.config.ts:320`), tanpa commit | Menu dan badge tampil hanya di build lokal; perubahan tidak ter-commit dan dibuang sesudah QA (§K K-01) | ✅ build lokal tanpa commit (`git diff` hanya `nav.config.ts`); dibuang sesudah QA |
| HG5 | AC-E02.7 (PRD `:765-767`) | [test-otomatis] `KontrakMatriksRoleTest` dengan `PREFIKS_CAKUPAN` diperluas ke `halo-simpeg` | Semua route helpdesk §6.1 (PRD `:387-409`) terdaftar; route literal sebelum `(:num)` (PRD `:416-417`) | [test-otomatis] `KontrakMatriksRoleTest`, `PREFIKS_CAKUPAN` memuat `halo-simpeg`; CI #899 `main` `e4392507` hijau |
| HG6 | H8-7 (PRD `:1404`) | Cek status konfirmasi Biro SDM satker kepegawaian | Bila masih ◻, butir HA6/HA7 ditandai **bergantung H8-7** dan memakai nilai `web_config` `id_satker_kepegawaian` demo (§K K-10) | ✅ H8-7 masih ◻ → HA6/HA7 bergantung H8-7 dan memakai `id_satker_kepegawaian` demo |

### HP. Layar pemilik (`/halo-simpeg`, Gambar 33; E-03, E-04, E-05, E-08)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HP1 | AC-E08.1 (PRD `:998`), §6.3 (`:581`) | U1 buka `/halo-simpeg` | Halaman (bukan drawer global) master-detail; tab "Tiket saya" hanya tiket U1; kartu "Kontak Helpdesk" dari `GET kontak/aktif` (urut `created_at` naik, `:396`) | ✅ master-detail (bukan drawer); U1 tanpa tab antrean; kartu Kontak dari `kontak/aktif` (K1, K2) |
| HP2 | R9(1) (`:1432`), AC-E08.13 (`:1012-1013`) | Lihat filter baca bawaan "Tiket saya" | `semua` (`UiSelect`), bukan `belum` [V2] | ✅ bawaan "Semua" [V2] |
| HP3 | AC-E03.1, AC-E03.8 (`:789-793`), §7.3 (`:777-787`) | U1 buat tiket KA (subjek, pesan, lampiran pdf) | 201; pesan sukses legacy "Berhasil menambahkan data halo simpeg."; tiket Open di daftar; DB demo: `disiplin_ticket` `status = 0`, `allow_reply = 1`, `is_read = 1`, `is_read_off = 2`, snapshot jabatan/satker terisi | ✅ T1 (KA + pdf) 201, rute thread + fokus judul; DB `status=0, allow_reply=1, is_read=1, is_read_off=2`, snapshot terisi. Pesan sukses semula tidak ada (T-B1) → diperbaiki CR-143 `4d06a8c6` (BE `message`) + CR-142 `8bc6c509` (notice); notice FE diverifikasi di demo (CR-142 @`f49f075e`, teks cadangan karena BE demo belum CR-143); sisi BE `message` pada 201 [test-otomatis] (fixture + assert `message` `21a538da`, CI #900), tanpa uji demo |
| HP4 | AC-E03.3 (`:790`), `:808` | Buka opsi kategori; panggil POST dengan KC | Opsi hanya kategori aktif (`kategori/opsi`); KC → 422 "Kategori tidak tersedia." | ✅ opsi hanya KA, KB, KD; KC → 422 "Kategori tidak tersedia." |
| HP5 | AC-E03.4, AC-E08.3, AC-E08.15 (`:790`, `:999`, `:1015`), §7.3a (`:809-810`) | Subjek kosong / 251 karakter; pesan kosong / 5.001 karakter; nilai batas 250 dan 5.000 | 422 per kolom tampil di bawah field; batas lolos, batas+1 ditolak (code point sesudah trim) | ✅ kosong → 422 per kolom; subjek 251 → 422; pesan 5.000 code point lolos, 5.001 → 422 (lewat balasan, aturan sama) |
| HP6 | AC-E03.5 (`:791`), BP8-5 (`:160`) | Lampiran > 5 MB; ekstensi di luar 9 jenis; isi tidak cocok ekstensi | 422 `errors.arsip`; tidak ada baris tiket | ✅ isi tidak cocok ekstensi / `.exe` → 422 `errors.arsip`; > 5 MB → 422 (lewat balasan) |
| HP7 | AC-E03.9 (`:793`), `:806` | Buat tiket ke-6 dalam 10 menit | 429 "Terlalu banyak pertanyaan baru. Coba lagi dalam 10 menit."; tanpa baris dan tanpa berkas (`:786`) | ✅ + pengamatan: 429 dengan teks tepat sesudah 5 permintaan, tanpa baris baru; throttle dicek sebelum validasi (README), percobaan yang ditolak 422 ikut memakan kuota |
| HP8 | AC-E03.7 (`:792`), F8-04 (`:323`) | Akun demo role 1, 3, 4, 5 mencoba buat tiket (UI dan API) | Tombol/form tidak tersedia; POST → 403 | ✅ sebagian: POST tiket role 1 → 403 (HM1), role 3/4/5 → 403 (HM3–HM5); ketiadaan tombol/form di UI role 1/3/4/5 tidak tercatat |
| HP9 | AC-E08.10 (`:1008-1010`), §6.3 (`:589-597`) | Klik baris; ⋮ "Lihat detail"; tombol kembali browser; deep-link `/halo-simpeg/tiket/:id` | Klik baris tidak berbuat apa-apa; ⋮ mengubah rute dan mengisi panel kanan; fokus ke judul thread; kembali/deep-link berfungsi; tidak ada `role="option"` di DOM | ✅ sebagian: klik baris tanpa aksi; ⋮ Lihat detail → rute + panel kanan + fokus H2; tanpa `role=option`; deep-link berfungsi (HP10); tombol kembali browser tidak tercatat |
| HP10 | §6.3 (`:595-596`) | Viewport < 1024 px | Daftar dan thread tampil sebagai dua halaman pada rute sama + "Kembali ke daftar" | ✅ 375 px: daftar saja; deep-link thread + "Kembali ke daftar"; sesudah kembali fokus ke ⋮ baris tiket itu |
| HP11 | §6.1b Pesan (`:481`), `8_2.md:144-148` | Buka thread T1 sesudah petugas membalas | Gelembung kirim/terima dari `milik_saya`; warna pengirim dari `urutan_pengirim` (0 = pemilik); header subjek, kategori, pemilik, tanggal | ✅ `urutan_pengirim` 0 = pemilik, 1 = P1, 2 = P2 per tiket; `milik_saya` benar |
| HP12 | AC-E04.9 (`:889-890`), BP8-4 (`:159`) | Kirim T9 | Tersimpan dan tampil utuh sebagai teks (`pre-wrap`, tanpa `v-html`; `<b>x</b>` literal) | ✅ T9 tersimpan; `pre-wrap`, `<b>x</b>` ter-escape |
| HP13 | AC-E04.7, AC-E08.9 (`:887-889`, `:1007`), F8-17 (`:336`) | Unduh lampiran baru T1 dan lampiran lama T6; cek tab Network | Lewat `tiket/{id}/pesan/{idPesan}/lampiran` (blob), tidak lewat `/berkas/`; nama `lampiran-{id_tiket}-{id_pesan}.{ext}`; `Content-Disposition` inline/attachment sesuai ekstensi, `nosniff`, `Cache-Control: private, no-store`; path tidak ada di respons JSON | ✅ sebagian + T-B2: E-04 blob 200, `Content-Disposition` inline bernama `lampiran-…`, `nosniff`, path tidak di JSON, tanpa tautan `/berkas/`; `Cache-Control` gabungan (README: `private, no-store`) → CR sesudah sign-off |
| HP14 | AC-E04.7 (`:889`) | Ulangi HP13 untuk T6 dengan `arsip.penyajianBerkasLegacy` `publik` lalu `privat` | Keduanya 200 lewat E-04 | [test-otomatis] `penyajianBerkasLegacy` hanya memengaruhi URL `/berkas/` F-10; jalur publik/privat diuji MAKE-071; `.env` demo tidak diubah |
| HP15 | AC-E04.15 (`:906-907`), B-20 (`:1608`) | Lihat daftar U1 yang memuat T5 | T5 di akhir daftar, kolom pesan terakhir kosong (`pesan_terakhir: null`), tetap bisa dibuka dan ditutup | ✅ T5 di akhir daftar, `pesan_terakhir: null`, `bisa_tutup` true |
| HP16 | AC-E04.6 (`:887`), §6.1b (`:489-491`) | Balas T1 lalu muat ulang | Urut pesan terakhir turun; paginasi server (`per_page` bawaan 20) | ✅ sebagian: urut pesan terakhir turun, `per_page` 20 |
| HP17 | F8-08, AC-E05.1, AC-E05.10 (`:327`, `:916`, `:945-946`) | U1 ⋮ Tutup T1 (ConfirmDialog teks legacy `VI:136`) | 200; Closed; DB `status = 1`, `allow_reply = 0`, `is_read = 1`, `is_read_off = 1`, `closed_by` terisi; `ditutup_oleh_label` = nama pemilik | ✅ ConfirmDialog teks legacy → Closed; DB `status=1, allow_reply=0, is_read=1, is_read_off=1`, `closed_by` = U1; `ditutup_oleh_label` = nama pemilik |
| HP18 | AC-E04.3, AC-E05.2, AC-E05.3 (`:883`, `:916-917`), `:514-516` | Pada T3: form balas; panggil POST pesan dan POST tutup | Form balas tidak tampil; API → 422 `tiket_ditutup` (teks legacy `:514`) / `tiket_sudah_ditutup` | ✅ T3: "Percakapan ini telah ditutup." tanpa form; POST pesan → 422 `tiket_ditutup`, tutup → 422 `tiket_sudah_ditutup` |
| HP19 | F8-09, B-26 (`:328`, `:1614`) | U1 cek ⋮ pada tiketnya; panggil DELETE | Hapus `hidden`; API → 403 `bukan_pihak_berwenang` | ✅ ⋮ pemilik: Lihat detail → Tutup (Hapus `hidden`); DELETE → 403 `bukan_pihak_berwenang`; `bisa_hapus=false` |
| HP20 | AC-E05.9, B-19 (`:945`, `:1607`) | U3 (petugas KB) membalas dan menutup tiketnya di KA | Balas 201, tutup 200 [V2] | [test-otomatis] `TiketHaloSimpegTest::testFlagSamaDenganHasilAksi` (skenario pemilik yang petugas kategori lain: balas 201, tutup 200); U3 tidak diuji manual |
| HP21 | AC-E04.1 (`:881`), BP8-2 (`:157`) | U1 membuka URL detail T2 milik U2 | 404 "Data percakapan tidak ditemukan." (bukan 403) | ✅ akun pemilik lain (U2) membuka T1 → 404 "Data percakapan tidak ditemukan." |
| HP22 | AC-E04.8 (`:889`), BP8-7 (`:162`) | `GET tiket/{id}` lalu cek DB demo | Baris tidak berubah; tanda baca hanya lewat `POST …/baca` yang dipanggil eksplisit saat thread dibuka | [test-otomatis] `TiketHaloSimpegTest::testDetailPihakLain404DanGetTanpaEfekSamping`; tidak dicek manual di DB demo |
| HP23 | AC-E08.15 (`:1015-1017`), `8_2.md:163-165` | Keadaan memuat/kosong/galat (403, 404, 500 dengan muat ulang) di daftar, thread, kartu kontak | Teks sesuai; pesan galat dari amplop | [test-otomatis] sebagian: `HaloSimpegPage.spec.ts` (galat 500 + Muat ulang, kosong, 404 thread); keadaan kartu kontak dan uji manual tidak tercatat |
| HP24 | §6.3 (`:581`), §8 (`:1286-1287`) | Role 2/6/7: launcher Vanya pada `/halo-simpeg` (desktop dan sempit) | Launcher tidak menutupi tombol kirim/lampiran form balas; widget dan halaman berdiri sendiri | ✅ sebagian (semula ❌ T-B4): CR-142 memberi ruang bawah halaman; diverifikasi di demo pada CR-142 @`f49f075e`, desktop, satu akun role 2: thread digulir ke bawah, Kirim tanpa irisan dengan launcher; di posisi gulir awal Kirim masih bisa berada di bawah launcher, tetapi halaman selalu bisa digulir sampai tombol bebas; layar sempit dan role 6/7 tidak diuji; `scroll-padding` fokus (`a2f1e894`) dikunci test `564ebaa5` + CI #904, belum diverifikasi di demo |
| HP25 | AC-E04.14 (`:905`), §6.1b (`:479`, `:534`) | Untuk satu tiket Open per pelaku, baca `bisa_balas`/`bisa_tutup`/`bisa_hapus` di respons detail (Network), lalu jalankan aksinya lewat UI/API: pemilik (U1, T1), petugas aktif (P1, T1), petugas nonaktif (P4), pemilik yang petugas kategori lain (U3, B-19), tiket NULL (U1, T4) | Setiap flag sama dengan hasil aksinya (flag `true` → aksi berhasil; `false` → aksi tersembunyi/disabled dan API menolak); T4: `bisa_balas=false`, `bisa_tutup=true`, `bisa_hapus=false` (`:899`); pelengkap [test-otomatis] CB16 | ✅ sebagian: flag sama dengan aksinya untuk pemilik (HP19), P4 (HT9), T4 (HN3); U3 tidak diuji manual; pelengkap [test-otomatis] CB16 |
| HP26 | AC-E08.2 (`:998`), §6.3 (`:581`) | Daftar "Tiket saya" U1 dan antrean P1 dengan tiket Open, Closed, sudah dibaca, dan belum dibaca; buka satu tiket belum dibaca | Badge status Open/Closed sesuai `status`; penanda belum dibaca sesuai `belum_dibaca` sisi pemanggil (`:479`) dan hilang sesudah thread dibuka (HB9) | ✅ sebagian: badge Open/Closed sesuai; `belum_dibaca` U1 = 1 sesudah balasan petugas dan hilang sesudah dibuka (HB9) |

### HT. Layar petugas (antrean master-detail, Gambar 34; E-04, E-05, F8-11, F8-12)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HT1 | AC-E08.1 (`:998`), §6.3 (`:600-601`) | P1 buka `/halo-simpeg` | Tab antrean (kategori KA) + "Tiket saya"; tab dan panel diturunkan dari `GET petugas/saya`, bukan dari role | ✅ tab "Tiket Saya" + "Antrean" dari `petugas/saya` |
| HT2 | AC-E08.13 (`:1012-1013`), B-25 (`:1613`) | Lihat filter baca antrean | Bawaan `belum` [K] | ✅ bawaan "Belum dibaca" [K] |
| HT3 | §6.3 (`:599-600`) | Bandingkan P1 (1 kategori) dengan P2 (2 kategori) | Filter kategori antrean hanya untuk P2 | ✅ P2 (2 kategori) punya filter kategori; P1 (1 kategori) tidak |
| HT4 | AC-E04.1, §7.8 (4) (`:881`, `:1023`) | P1 cari T2 (KB) di antrean; buka URL detail T2 | Tidak tampil; 404 | ✅ T2 tidak di antrean P1, pencarian `q` total 0, URL detail → 404 |
| HT5 | F8-12, AC-E08.13, AC-E04.13 (`:331`, `:1012-1013`, `:904-905`) | Panel "Perlu ditindak" P1 dengan T8 | Memuat daftar sendiri lewat `perlu_tindak=1` (Network); jumlah = `Ringkasan.perlu_tindak`; "Lihat semua" ke daftar bertapis; `perlu_tindak=1` tanpa `peran=petugas` → 422 | ✅ panel memuat T8 lewat `perlu_tindak=1`; jumlah = `Ringkasan.perlu_tindak` (2); tanpa `peran` → 422 |
| HT6 | §6.3 (`:582`, `:589-594`), AGENTS.md §1 | ⋮ baris antrean | Urutan Lihat detail → Tutup → Hapus (merah, paling bawah, ConfirmDialog teks `VI:140`); Tutup `hidden` bila Closed; `testid` `halo-tiket-actions-<id>`, aria-label "Aksi untuk <subjek>" | ✅ urutan Lihat detail → Tutup → Hapus; Unduh lampiran `hidden` bila pesan terakhir tanpa lampiran |
| HT7 | F8-11, AC-E04.10 (`:330`, `:890-892`) | P1 membalas T1 dengan centang "Ingatkan Saya Kembali?", lalu membalas lagi tanpa centang | DB `notify_pic = 1`, `notify_at` +24 jam UTC, lalu reset `notify_pic = 2`, `notify_at` NULL; centang tidak tampil bagi pemilik; API dari pemilik dengan nilai 1 → 422 "Pengingat 24 jam hanya dapat diatur petugas."; nilai 2 → 422 `errors.ingatkan_24_jam` | ✅ centang → `notify_pic=1`, `notify_at` +24 jam UTC; tanpa centang → reset; nilai 2 → 422; dari pemilik → 422 |
| HT8 | §6.3 (`:613-614`), `8_2.md:147-148` | P1 arahkan ke penanda snapshot di header thread | Popover NIP/jabatan/satker lewat `UiTooltip` (bukan `title`); bagi pemilik `snapshot` null dan popover tidak ada (`:480`). Bukti disamarkan | ✅ tombol "Data pelapor" membuka `UiTooltip` (bukan `title`), bukti disamarkan; snapshot bagi pemilik null |
| HT9 | AC-E04.5, F8-06 (`:886-887`, `:325`) | Login P4 (nonaktif); panggil `GET tiket?peran=petugas` | Tab antrean/panel/ekspor tidak ada; API 200 `Halaman` kosong; tiket milik P4 sendiri tetap terlihat | ✅ P4: `petugas:true, aktif:false, bisa_ekspor:false`; `peran=petugas` 200 kosong; tiket sendiri tetap bisa dibuat |
| HT10 | F8-09, AC-E05.4, AC-E05.5 (`:328`, `:917-919`) | P1 ⋮ Hapus tiket uji terpisah di KA; P3 (KB) mencoba DELETE tiket KA | Hapus 200 `{id}`; audit `before`; pesan dan berkas tiket itu terhapus; P3 → 404 | ✅ P1 ⋮ Hapus (ConfirmDialog teks legacy) → 200, audit `before` tiket + pesan; P3 DELETE tiket KA → 404 |
| HT11 | AC-E05.7, Q-A0-6 (a') (`:922-939`, `:1426`) | Hapus T6 (path berspasi) dan tiket pertama T7 | Hapus tidak gagal; berkas T6 tertinggal dan tercatat (log tanpa path); tiket kedua T7 tetap bisa mengunduh | ✅ hapus T6 berhasil, berkas tertinggal + log tanpa path; tiket kedua T7 tetap unduh 200 |
| HT12 | §6.2 (`:568-570`), Q-A0-4 (`:1424`) | U4 (P1 sebagai pemilik di KA) membalas lalu membuka tiketnya; login P2 | `is_read_off` tetap 2; P2 masih melihat tiket itu belum dibaca + `perlu_tindak`; tiket U4 tidak masuk `belum_dibaca_petugas` P1 | ✅ U4 balas + baca → `is_read_off` tetap 2; P2 melihat `belum_dibaca` + `perlu_tindak`; tidak masuk sisi petugas P1 |
| HT13 | AC-E08.15 (`:1015-1017`) | Keadaan memuat/kosong/galat antrean dan panel | Sesuai HP23 | ✅ sebagian: panel kosong "Tidak ada tiket yang perlu ditindak."; memuat/galat [test-otomatis] `HaloSimpegPage.spec.ts` |
| HT14 | F8-12, B-13 (`:331`, `:1601`) | Login P1/P2 | Tidak ada popup login petugas; pengganti = badge + panel | ✅ sebagian: P1 tanpa popup login petugas; P2 tidak dicatat terpisah |

### HN. Tiket berkategori NULL (H8-16 (a1))

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HN1 | AC-E04.11 (`:894-900`), §7.4 (`:871-877`) | U1 lihat T4 di daftar lalu buka | Tampil dengan kategori "—"; detail terbuka (`kategori: null`); form balas disembunyikan, diganti catatan | ✅ T4 berkategori "—"; form balas diganti catatan |
| HN2 | `:517`, `8_2.md:149-151` | Paksa POST pesan T4 | 422 `tiket_tanpa_kategori` "Pertanyaan ini tidak memiliki kategori. Silakan tambah pertanyaan baru."; pesan BE tampil | ✅ 422 `tiket_tanpa_kategori` dengan teks PRD |
| HN3 | AC-E05.8 (`:940-944`) | U1 ⋮ Tutup T4; panggil DELETE | Tutup 200; Hapus `hidden`, API 403 `bukan_pihak_berwenang` | ✅ sebagian: `bisa_tutup=true`, Hapus `hidden`, DELETE → 403; tutup T4 tidak dijalankan manual ([test-otomatis] `testTiketKategoriNullHanyaPemilik`) |
| HN4 | AC-E04.11 (`:899-900`) | P1, P2, dan akun role 1 non-pemilik membuka URL T4 | 404 semua; tidak ada antrean khusus NULL | ✅ sebagian: role 1 (P2) non-pemilik → 404; T4 tidak di antrean P1/P2; URL T4 oleh P1 tidak dicatat ([test-otomatis] `testTiketKategoriNullHanyaPemilik`) |
| HN5 | §6.2 (`:550-551`) | Ringkasan U1 dan P1 sebelum/sesudah T4 dibalas di DB | Masuk `belum_dibaca_saya` U1; tidak pernah masuk `belum_dibaca_petugas`/`perlu_tindak` | [test-otomatis] sebagian: `testTiketKategoriNullHanyaPemilik` (tidak masuk `belum_dibaca_petugas`/`perlu_tindak`); sisi `belum_dibaca_saya` U1 tidak dijalankan |
| HN6 | AC-E09.3 (`:1041`) | Ekspor P2 untuk periode yang memuat T4 | T4 tidak ikut | ✅ sebagian: ekspor P1 (HE6) tanpa tiket NULL; ekspor P2 tidak dicatat |

### HR. Penanda baca F8-10 (H8-10 tetap [I], diuji per skenario AC-E04.4)

Langkah per skenario: lakukan aksi lewat UI, lalu baca `is_read`/`is_read_off` di DB demo. Label [I] berarti PRD-lah acuannya (bukan
legacy terverifikasi); selisih dengan legacy pada butir [I] bukan temuan (HBB2).

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HR1 | AC-E04.4 (i) (`:884-885`), `:561` | Pemilik membuka thread (POST baca) | `is_read = 1` saja [K `LS:907-912`] | [test-otomatis] `TiketHaloSimpegTest::testPenandaBacaPerSkenario` (i); tidak dicatat terpisah di DB demo |
| HR2 | AC-E04.4 (ii), `:562` | Petugas aktif non-pemilik membuka thread | `is_read_off = 1` [K]; syarat aktif [V2] | ✅ P1 buka T1 → `is_read_off=1` |
| HR3 | AC-E04.4 (iii), `:566` | Petugas membalas | `is_read_off = 1` [K `LS:1344-1347`], `is_read = 2` [I] (H8-10) | ✅ P1 balas → `is_read_off=1`, `is_read=2` [I] |
| HR4 | AC-E04.4 (iv), `:565` | Pemilik membalas | `is_read = 1` [I], `is_read_off = 2` [I] (H8-10) | ✅ U1 balas → `is_read=1`, `is_read_off=2` [I] |
| HR5 | `:568-570` | Pemilik-petugas KA (U4) membalas lalu POST baca | `is_read_off` tetap 2 (sama dengan HT12) | ✅ sama dengan HT12 |
| HR6 | `:564` | Buka thread yang sama dua kali | Idempoten: tanpa tulis/audit kedua; 200 `Ringkasan` | ✅ POST baca berulang → 200 `Ringkasan`, tanpa audit tambahan |
| HR7 | `:563` | Pihak lain memanggil POST baca | 404 | ✅ U2 POST baca T1 → 404 |
| HR8 | `:329` | Pesan pertama saat buat tiket | Dikecualikan: tiket baru `is_read = 1`, `is_read_off = 2` (`:782-783`) | ✅ tiket baru `is_read=1`, `is_read_off=2` |

### HS. Penyamaran nama D7 (a) dan nama bergelar

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HS1 | AC-E04.12 (`:902-904`), §6.1b (`:496-499`) | Daftar dengan pesan terakhir dari petugas: dilihat pemilik, petugas penulis, petugas lain, akun role 1 (P2) | Kolom pengirim "Admin" bagi semua peninjau, termasuk role 1 dan petugas penulisnya [K `VI:110-117`] | ✅ sebagian: pesan petugas tampil "Admin" di daftar bagi pemilik dan P2 (role 1); petugas penulis dan petugas lain di daftar tidak dicatat (P1 hanya di detail, HS2) |
| HS2 | `:499` | Detail tiket yang sama | Nama pengirim bila pengirim = pemilik atau peninjau = role 1; selain itu "Admin" (4 kasus fixture: pesan pemilik, pesan petugas dilihat pemilik, dilihat petugas lain, pesan petugas sendiri) | ✅ role 1 melihat nama bergelar petugas; P1 melihat pesannya sendiri "Admin"; pesan pemilik = nama |
| HS3 | `:457-458`, AC-E04.12 | Cek respons JSON di Network untuk akun non-role-1 | Tidak ada id akun petugas (`msg_sender`/`closed_by` mentah tidak dikirim) | ✅ JSON non-role-1 tanpa `msg_sender`/`closed_by`/`id_user` |
| HS4 | `ditutup_oleh_label` (`:480`) | Tiket ditutup petugas, dilihat pemilik dan role 1; tiket ditutup pemilik | Pemilik melihat "Admin", role 1 melihat nama; ditutup pemilik → nama | ✅ T2 ditutup P2: pemilik melihat "Admin", role 1 melihat nama; T1 ditutup pemilik → nama pemilik |
| HS5 | B-28 (`:1616`), `:501` | Pesan dari petugas ber-NIP role 3/4 | Nama bergelar dipakai untuk akun ber-NIP role apa pun (bila tidak disamarkan) | ✅ sebagian: nama bergelar akun ber-NIP role 2 (P1); akun ber-NIP role 3/4 tidak ada di demo |

### HA. Admin master Kategori / Petugas / Kontak (role 1; E-02, E-07)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HA1 | AC-E08.5 (`:1000`), §6.3 (`:583`), AGENTS.md | Akun demo role 1 buka tiga layar (Pengaturan → "Halo Simpeg", `:749`) | `page-fill` + `table-fill`, header sticky, hanya body tabel menggulir | ✅ sebagian: Kategori `page-fill` + header sticky; Petugas/Kontak dicek visual sekilas |
| HA2 | AC-E02.1 (`:760`), AC-E02.6 (`:762`) | Tambah/ubah kategori, petugas, kontak | 201/200; setiap tulis ada di `audit_logs` | ✅ create 201 (KA dan P1 lewat UI, sisanya API), delete 200 `{id}`; setiap tulis ada di `audit_logs` |
| HA3 | AC-E02.9 (`:770-772`), K-A1 (`:435`) | ⋮ Nonaktifkan lalu Aktifkan pada ketiga master; ulangi status yang sama lewat API; kirim `status: 2` | `PATCH …/status`; item `hidden` sesuai status; kolom Status hanya badge; status sama → 200 tanpa audit; nilai di luar 0/1 → 422 | ✅ PATCH 200; status sama → 200 tanpa audit; `status: 2` → 422; kolom Status hanya badge; ⋮ Edit → Nonaktifkan → Hapus |
| HA4 | AC-E02.4 (`:761`), `:519` | Hapus KA (dipakai tiket/petugas) | 422 `kategori_dipakai` "Kategori masih dipakai tiket atau petugas. Nonaktifkan kategori ini." tampil di `UiNotice` (bentuk ⋮ mengikuti §K K-12) | ✅ DELETE KA → 422 `kategori_dipakai` teks PRD; ⋮ Hapus `disabled` + keterangan (K-12: keduanya berlaku) |
| HA5 | `:520-523`, AC-E02.3 (`:761`) | Kategori duplikat (beda huruf/spasi), petugas duplikat, kontak duplikat | 422 `kategori_duplikat` / `petugas_duplikat` / `kontak_duplikat` dengan teks legacy | ✅ + pengamatan: beda huruf/spasi tepi → 422 `kategori_duplikat`; `petugas_duplikat`, `kontak_duplikat` sesuai README; spasi ganda di tengah tidak dinormalkan (sesuai PRD §7.3a) |
| HA6 | AC-E02.5 (`:761`), F8-07 (`:326`), `:822` — **bergantung H8-7** | Form Petugas: ketik 1 karakter, lalu 2+ karakter, lalu ketik cepat | Kurang dari 2 karakter → "Kata kunci minimal 2 karakter."; pencarian server ber-debounce + penjaga urutan, maks 20 hasil `label`; hanya pegawai aktif satker kepegawaian (nilai `web_config` demo) + akun role 1 aktif; isi kandidat dicocokkan ulang sesudah H8-7 | ✅ sebagian + pengamatan (bergantung H8-7): API 1 karakter → 422 "Kata kunci minimal 2 karakter.", 51 → 422; ≥ 2 karakter → saran berlabel (NIP disamarkan); UI hanya placeholder, tanpa teks galat |
| HA7 | F8-07 (`:326`) — **bergantung H8-7** | Kosongkan `id_satker_kepegawaian` di Web Config demo, buka form Petugas; kembalikan nilai | Ada peringatan satker kosong (pola `PetugasLayananService::PERINGATAN_SATKER_KOSONG`) | [test-otomatis] `MasterHaloSimpegTest` (satker kosong → hanya role 1 + peringatan); Web Config demo tidak diubah (bergantung H8-7) |
| HA8 | AC-E02.2 (`:761`), R10 langkah 1 (`:228`) | Pilih ≥ 2 kategori lewat `UiPilihBanyak` (keyboard: Tab/Spasi, pencarian); simpan tanpa kategori | Satu kategori ≥ 2 petugas dan satu petugas ≥ 2 kategori tersimpan; tanpa kategori → 422 "Minimal satu kategori." | ✅ sebagian: `UiPilihBanyak` keyboard (Spasi) mencentang; P2 2 kategori, KA ≥ 2 petugas; tanpa kategori → 422; kategori nonaktif tidak di opsi |
| HA9 | AC-E02.8, AC-E08.16 (`:767-770`, `:1016-1017`), K-A4 (1') (`:750-756`) | Petugas terakhir sebuah kategori ber-tiket Open; petugas kedua | ⋮ Hapus `disabled` + `alasan_tidak_bisa_hapus`; paksa API → 422 `petugas_terakhir`; petugas kedua boleh dihapus | ✅ petugas terakhir KD ber-tiket Open: `bisa_hapus=false` + alasan, ⋮ Hapus disabled; DELETE → 422 `petugas_terakhir` |
| HA10 | `:472`, AC-E08.16 | Nonaktifkan petugas aktif terakhir KD | 200 + `peringatan` tampil di `UiNotice` nada peringatan | ✅ + pengamatan: Nonaktifkan → 200 + `UiNotice` peringatan; Nonaktifkan tanpa ConfirmDialog (sama dengan pola master lain) |
| HA11 | F8-18, AC-E07.1 (`:337`, `:772-773`) | Admin Kontak (`GET kontak`, paginasi, filter `status`/`q`); akun role 2 memanggil `GET kontak` | Admin melihat semua status; role 2 → 403 dari filter route; kartu pemilik hanya status 1 | ✅ sebagian: role 2 `GET kontak` 403, `kontak/aktif` 200 (status 1 saja); paginasi/filter admin tidak dicatat |
| HA12 | §7.3a (`:813-821`), AC-E08.15 | Nilai batas field master (kategori 250, email 150, no_hp 20, kontak name 150 / contact 20; email kontak opsional) | Batas lolos, batas+1 → 422 per kolom | ✅ batas lolos, batas+1 → 422 untuk semua field; email kontak opsional |
| HA13 | B-31 (`:1619`) | Cek tombol level halaman | Tidak ada tombol ekspor Excel/CSV bawaan DataTables (HBB18) | ✅ tanpa tombol ekspor bawaan |

### HK. Aksi baris ⋮ dan komponen (AGENTS.md §1)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HK1 | AGENTS.md §1, BP8-11 (`:166`) | Semua tabel helpdesk (tiket pemilik, antrean, Kategori, Petugas, Kontak) | Satu ⋮ di kolom Aksi paling kanan; tanpa tombol ikon/switch per baris | ✅ semua tabel helpdesk satu ⋮ di kolom Aksi; kolom Aksi terpotong saat sidebar terbuka (T-B3) diperbaiki CR-142 `8bc6c509` (sticky kanan) |
| HK2 | AGENTS.md §1 butir 2–4 | Urutan dan label ⋮ master | Edit → Aktifkan/Nonaktifkan → Hapus (danger, garis pemisah); `hidden` untuk aksi yang tidak berlaku, `disabled` untuk yang berlaku tetapi sedang tidak boleh | ✅ urutan, label, `hidden`/`disabled` sesuai |
| HK3 | AGENTS.md §1 butir 6–7 | Aksi destruktif; aria-label dan testid | `ConfirmDialog`; "Aksi untuk <nama baris>"; `testid` `<fitur>-actions-<id>` (tiket `halo-tiket-actions-<id>`) | ✅ ConfirmDialog, aria-label, dan `testid` sesuai |
| HK4 | AC-E08.6 (`:1000-1001`), BP8-13 (`:168`) | Inspeksi DOM layar helpdesk | Tanpa `window.confirm`, `<select>` native yang tampil, atau `title` sebagai tooltip; tanggal ekspor lewat `UiTextField type=date` | ✅ tanpa `<select>` native yang tampil (milik Radix, tersembunyi), tanpa `title` sebagai tooltip |
| HK5 | AC-E08.4 (`:999`) | Operasikan form tiket, ⋮, dialog, filter hanya dengan keyboard | Tab/Enter/Spasi/Esc/panah berfungsi; fokus terlihat; ARIA benar; [test-otomatis] Vitest per komponen | [test-otomatis] Vitest per komponen; keyboard `UiPilihBanyak` dicek manual (HA8) |

### HB. Badge menu, titik penanda sidebar ciut, lonceng, entri NotifikasiPage (H8-15 (b))

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HB1 | AC-E08.12 (`:1011-1012`), F8-12/D8 (a) (`:331`, `:553`) | Build lokal HG4; login P1 dengan T8 + tiket belum dibaca | Badge item "Halo Simpeg" = `Ringkasan.badge` (distinct belum dibaca ∪ `perlu_tindak`); 0 → tanpa badge | ✅ sebagian: P1 badge "Halo Simpeg 1" = `Ringkasan.badge`, hilang sesudah thread dibuka; kasus 0 → tanpa badge untuk akun lain tidak dicatat (HB2: badge 2 > total 1) |
| HB2 | AC-E08.12, fixture `badge > total` (`:534`) | Bandingkan badge menu dengan angka lonceng saat T8 aktif | Badge > angka lonceng (lonceng = `total`) | ✅ P1: `badge` 2 > `total` 1 |
| HB3 | R3 (`:1433`), `:227` | Ciutkan sidebar | Titik penanda + teks `sr-only` jumlah (cek pembaca layar/DOM); sebelumnya badge disembunyikan saat ciut (`AppSidebar.vue:258`) | ✅ sidebar ciut: titik penanda + `sr-only` "(2)" |
| HB4 | AC-E06.9 (`:975-980`), §6.2 (`:554-555`) | `GET api/v1/notifikasi/ringkasan` dan `GET halo-simpeg/tiket/ringkasan` untuk pemilik, petugas, petugas-pemilik, role 1–8 tanpa tiket | `tiket_belum_dibaca` = `Ringkasan.total` (bukan `badge`), tanpa hitung ganda; role tanpa tiket dan role 8 = 0 (ulang C-N10 Fase 7 `docs/fase7/Checklist_QA_Lapis1_Fase7.md:398`, yang dulu `null`) | ✅ sebagian: `tiket_belum_dibaca` = `Ringkasan.total` untuk P5, P6, role 5 dan 8 (0); pemilik dan petugas-pemilik tidak dicatat terpisah |
| HB5 | AC-E06.10 (`:981-985`), F8-19 (`:338`) | Akun role 2 pemilik dengan angka > 0 buka `NotifikasiPage` | Entri "Tiket Halo Simpeg (n)" di atas tab → `/halo-simpeg` | ✅ entri "Tiket Halo Simpeg (1)" → `/halo-simpeg`, terpisah dari tab |
| HB6 | AC-E06.10 | Petugas role 3 (P5) dengan sisi petugas > 0 | Entri menuju tab antrean | [test-otomatis] `notifikasi.spec.ts` (sisi petugas > 0 → tab antrean), `entriTiket.spec.ts`; P5 tidak diuji manual |
| HB7 | AC-E06.10 | Angka 0 | Entri disembunyikan | [test-otomatis] `notifikasi.spec.ts` (`tiket_belum_dibaca` 0/null → entri tidak tampil) |
| HB8 | H8-15 (b) (`:1412`), `NOTIFIKASI_ROLES` (`frontend/src/features/notifikasi/roles.ts`) | Petugas role 4 (P6) | Tidak punya lonceng/halaman notifikasi; menelusuri angka lewat badge menu | ✅ sebagian + pengamatan: role 4 tanpa halaman notifikasi; lonceng topbar tetap berangka tetapi tidak berpindah (desain F-08, Q7-29); redaksi butir perlu diselaraskan |
| HB9 | `POST …/baca` (`:609`) | Buka thread belum dibaca | Badge turun langsung dari respons `Ringkasan` tanpa menunggu polling | ✅ sebagian: badge P1 hilang langsung sesudah thread dibuka (tanpa polling), dari respons `POST …/baca` |
| HB10 | AC-E08.11 (`:1009-1011`), `:602-605` | Pantau Network: role 8, sesi wajib ganti password, build `ACTIVE_PHASE = 5`, role 2 | Tiga yang pertama tanpa `GET halo-simpeg/tiket/ringkasan`; role 2 → satu request awal + polling lembut saat pindah halaman | ✅ sebagian: role 8 tanpa request `tiket/ringkasan`; sesi wajib ganti password, build fase 5, dan polling role 2 tidak dicatat |
| HB11 | AC-E08.8 (`:1005-1007`) | Logout, lalu login akun lain | Store tiket dan badge kosong; tidak ada angka/daftar akun sebelumnya | ✅ sebagian: ganti akun berulang, badge/antrean mengikuti akun baru; reset store [test-otomatis] `ringkasanHalo.spec.ts`, `tiket.store.spec.ts` |
| HB12 | §7.8 (5) (`:1023-1024`), §8 (`:1281-1282`) | Pemilik dengan notifikasi approval belum dibaca + tiket belum dibaca | `notifikasi_belum_dibaca` dan `tiket_belum_dibaca` terpisah; tab Notifikasi tidak memuat item tiket | ✅ sebagian: tab Notifikasi tidak memuat tiket; entri tiket terpisah |

### HE. Ekspor rekap petugas (E-09; MAKE-074 BE + B4 FE)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HE1 | AC-E09.1 (`:1039-1040`), B-24 (`:1612`) | P1 (1 kategori), P2, P4 (nonaktif), U1 (bukan petugas) | Tombol `UiExportMenu` hanya bila `bisa_ekspor` (P1, P2); P4/U1 API → 403 `bukan_petugas` "Fitur ini hanya untuk petugas Halo Simpeg yang aktif." | ✅ P1/P2/P3/P5/P6 `bisa_ekspor` true; P4 → 403 `bukan_petugas`; U1/role 5 tanpa tombol, API 403 |
| HE2 | AC-E08.14 (`:1013-1015`), `8_2.md:152-159` | Dialog periode: kosong, akhir < mulai, besok, mulai = akhir, hari ini | Pesan legacy; besok ditolak; mulai = akhir dan hari ini lolos | [test-otomatis] `EksporRekapDialog.spec.ts` (B4); dialog tidak diuji manual |
| HE3 | AC-E09.5 (`:1042-1044`), `:487` | Periode tanpa data | 200 JSON `kosong: true`; "Tidak ada data Halo Simpeg pada periode ini." di `UiNotice`; tidak ada unduhan; tanpa audit | ✅ 200 JSON `{kosong:true, jumlah:0}` + teks, tanpa `Content-Disposition`, tanpa audit |
| HE4 | `:486`, F8-20 (`:339`) | Periode berisi data | xlsx `Rekap_Halo_Simpeg_{ds}_{de}.xlsx` dari header; judul "REKAP PIC HALO SIMPEG {nama}" + periode (`:1029`); kolom NO, ID PERTANYAAN, KATEGORI, NAMA PENANYA, NIP PENANYA, PERTANYAAN / JAWABAN, TIMESTAMP; urut tiket lalu pesan | ✅ `Rekap_Halo_Simpeg_2026-11-01_2026-11-18.xlsx`; judul + periode; header F8-20; urut tiket lalu pesan |
| HE5 | AC-E09.2 (`:1040-1041`) | Tiket sintetis 00:00 WIB `ds`, 23:59 WIB `de`, 23:59 WIB sehari sebelum `ds` | Dua yang pertama ikut, yang ketiga tidak; TIMESTAMP ditulis WIB | [test-otomatis] `EksporTiketTest::testPeriodeWibKolomDanAudit`, `testRentangUtc` |
| HE6 | AC-E09.3, AC-E09.4 (`:1041-1042`) | Isi berkas P1 | Tanpa tiket KB/NULL; NIP penanya teks, bukan angka ilmiah (bukti disamarkan); tiket yang belum dijawab petugas ikut (B-17, `:1605`) | ✅ P1: hanya KA (tanpa KB/NULL), cakupan V1 (a); semua sel `inlineStr` (NIP teks); T5 tanpa pesan tidak ikut (V6) |
| HE7 | AC-E09.6 (`:1044`) | API `format=csv`; `ds` kosong; `ds` > `de` | 422 dengan teks §7.3a (`:823-824`) | ✅ `format=csv`, `ds` kosong, `ds > de` → 422 dengan teks tepat |
| HE8 | AC-E09.7 (`:1045-1046`) | Cek `audit_logs` sesudah HE4 | Satu baris: siapa, periode, jumlah baris; tanpa isi percakapan, NIP penanya, atau isi berkas | ✅ 6 unduhan = 6 baris audit `dokumen_unduhan` (jenis, format, periode, jumlah baris) tanpa isi/penanya; kosong dan 403 tidak diaudit |
| HE9 | AC-E09.8 (`:1047-1050`) | Batas 10.000 baris / ukuran / potong sel 32.767 | [test-otomatis] MAKE-074 (§K K-13); FE menampilkan 422 `ekspor_terlalu_besar` (API tiruan bila perlu) | [test-otomatis] `EksporTiketTest` `testBatasSepuluhRibuBaris`, `testBatasUkuranKarakter`, `testSelPanjangDipotong` |

### HU. Notifikasi push (E-06; mode mock, [mock-terbatas])

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HU1 | AC-E06.1 (`:965`) | U1 buat tiket KA; cek `queue_jobs` antrean `push`, job `push-pesan` | Penerima = petugas aktif KA (P1, P2, P5); P4 dan P3 tidak | ✅ [mock-terbatas] T1 → `akun` P2, P5, P1; P4 (nonaktif) dan P3 (KB) tidak |
| HU2 | AC-E06.2, F8-14 (`:965-966`, `:333`) | Petugas membalas; pemilik membalas; tutup | Antre ke pihak lawan, tidak ke pelaku; tutup memberi notifikasi | ✅ [mock-terbatas] antre ke pihak lawan, pelaku dikecualikan; tutup memberi notifikasi |
| HU3 | AC-E06.7 (`:970-973`), H8-14 (a) | P2 (role 1 tanpa NIP) dan P3 (role 6) | Menerima lewat `antreAkun(id_pengguna[])` (token web per akun; `MockFcmAdapter`) | ✅ [mock-terbatas] P2 (role 1 tanpa NIP) dan P3 (role 6) menerima lewat `akun` |
| HU4 | AC-E06.5, F8-16 (`:968`, `:335`) | Baca payload job | `{modul:'halo-simpeg', id_inbox, url}`; tanpa NIP, jabatan, satker, token, atau isi lengkap (H8-8 terbuka → payload terbatas, `:1405`) | ✅ [mock-terbatas] payload `{modul, id_inbox, url, link}`, isi terpotong 100 + `…`, tanpa NIP/jabatan/satker/token |
| HU5 | AC-E06.3, BP8-6 (`:966`, `:161`) | Matikan worker / paksa gagal | Pesan tetap tersimpan; retry terbatas | [test-otomatis] `TiketNotifTest::testGagalAntreTidakMenggagalkanPesan`, `testFcmV1TokenTidakSahDihapusDanRetryTerbatas` |
| HU6 | AC-E06.8 (`:974`) | — | Di luar QA ini: push real lewat MT-010/DEPLOY-001.1 | — |

### HV. AC-E08.7 terhadap pola redesign (H8-9: frame Figma helpdesk belum ada)

Acuan: PRD `:1001-1004`, H8-9 (`:1406`), PN8-4 (`:79`), AGENTS.md, `8_2.md:170-171`. Tanpa test Vitest; hasil dicatat di sini. Tiap
layar mencatat "Figma belum ada; acuan = …".

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HV1 | AC-E08.7 | Layar pemilik vs Gambar 33 (frontend redesign `67fa7678`, §K K-16) | Gelembung chat, form tiket, badge Open/Closed, token warna/tipografi redesign; halaman master-detail (bukan drawer) dicatat sebagai keputusan `:581` | ✅ sebagian: Figma belum ada; acuan = Gambar 33 + pola redesign; tidak dijalankan sebagai butir tersendiri, penilaian sekilas dari tangkapan layar QA; master-detail = keputusan §6.3 |
| HV2 | AC-E08.7 | Layar petugas vs Gambar 34 | Daftar kiri berupa tabel + ⋮, thread kanan, panel "Perlu ditindak"; baris tidak dapat diklik (beda dari mock = keputusan reviewer `:589-594`) | ✅ sebagian: Figma belum ada; acuan = Gambar 34; tabel + ⋮, thread kanan, panel "Perlu ditindak"; tidak dijalankan sebagai butir tersendiri, penilaian sekilas dari tangkapan layar QA |
| HV3 | AC-E08.7 | Kategori/Petugas/Kontak vs pola Master Data/PIC layanan | Judul, tombol "Tambah …", filter, tabel `page-fill`, ⋮, paginasi sama dengan layar PIC layanan | ✅ sebagian: Figma belum ada; acuan = pola Master Data/PIC layanan; tidak dijalankan sebagai butir tersendiri, penilaian sekilas dari tangkapan layar QA |
| HV4 | AC-E08.7 | Viewport 375 px, 768 px, desktop; mode gelap bila ada | Tanpa gulir horizontal halaman; navbar sticky | ✅ sebagian: 375 px dan desktop tanpa gulir horizontal halaman; 768 px dan mode gelap tidak diuji |

### HM. Matriks role 1–8 — helpdesk (F8-04, F8-05, §6.1 `:387-409`)

| # | Role (akun) | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HM1 | 1 (akun demo role 1; juga petugas P2) | Master CRUD; tiket (daftar, detail sebagai petugas, balas, tutup, hapus, ekspor); POST tiket | Master 200/201; tiket sesuai penugasan; POST tiket 403 | ✅ master 200/201 (HA); tiket sesuai penugasan P2; POST tiket 403 |
| HM2 | 2 (U1) | Buat/daftar/detail/balas/tutup tiket sendiri; master; `GET kontak` | Tiket sendiri boleh; DELETE sendiri 403; master/`kontak` 403; `kontak/aktif` 200 | ✅ U1: tiket sendiri boleh, DELETE 403, master/`kontak` 403, `kontak/aktif` 200 |
| HM3 | 3 (P5 / akun demo role 3) | Daftar/detail (sebagai petugas); POST tiket | Baca 200; POST tiket 403; lonceng ada | ✅ P5: antrean 200, POST 403, ekspor 200 |
| HM4 | 4 (P6) | Sama dengan HM3 | Baca 200; POST tiket 403; tanpa lonceng (HB8) | ✅ + pengamatan HB8: P6 antrean 200, POST 403, ekspor 200; lonceng tampil berangka (desain F-08) |
| HM5 | 5 (akun demo role 5) | Daftar, `petugas/saya`, POST tiket | Daftar 200 (kosong bila bukan petugas); `petugas/saya` `petugas:false`; POST 403 | ✅ `petugas:false`, antrean kosong, POST 403, ekspor 403 |
| HM6 | 6 (P3 / akun demo role 6) | Seperti HM2 + antrean bila petugas | Sesuai | ✅ role 6: POST 201, master 403; P3 antrean KB |
| HM7 | 7 (U2) | Seperti HM2 | Sesuai | ✅ U2: POST 201, master 403, `kontak/aktif` 200 |
| HM8 | 8 (akun demo role 8) | Semua endpoint `halo-simpeg/*` helpdesk; menu; polling | 403 dari filter route; tanpa menu; tanpa polling ringkasan (HB10); `tiket_belum_dibaca` 0 | ✅ semua `halo-simpeg/*` 403, tanpa menu, tanpa polling, `tiket_belum_dibaca` 0 |
| HM9 | §11.1 | [test-otomatis] `KontrakMatriksRoleTest` (HG5) | Hijau | [test-otomatis] CI #899 `main` `e4392507` hijau |

### HX. Verifikasi "thread tidak ter-mix" (§7.8, exit criteria Bahan Baku; PRD `:1019-1024`, BP8-1 `:156`, DoD `:1353`)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HX1 | §7.8 (1), AC-E04.2 (`:881-882`) | T1 (U1) dan T2 (U2) dibalas bersilang oleh P2 (petugas KA + KB) bergantian | Tiap thread hanya memuat pesannya; urutan `waktu`, `id_inbox_msg` naik; [test-otomatis] test dua tiket paralel MAKE-071 | ✅ P2 membalas T1/T2 bergantian: tiap thread hanya pesannya, urut naik; [test-otomatis] `testThreadTidakTerMixDanTanpaDependensiNotifikasi` |
| HX2 | §7.8 (2), AC-E03.2, AC-E06.6 (`:789-790`, `:969-970`) | Hitung `inbox` dan `inbox_msg` di DB demo sebelum dan sesudah alur penuh helpdesk (buat → balas → baca → tutup → hapus → ekspor) | Tidak berubah | ✅ `inbox` 20 / `inbox_msg` 22 sebelum dan sesudah alur penuh |
| HX3 | §7.8 (3), AC-06.4 Fase 4 (`:309`) | Hitung `disiplin_ticket` dan `disiplin_ticket_msg` sebelum dan sesudah alur cuti/TB/IB/PMK (ajukan, setujui, tolak, e-sign mock) | Tidak berubah; [test-otomatis] `backend/tests/Layanan/Notifikasi/NotifikasiTidakKeTicketingTest.php` hijau | [test-otomatis] `NotifikasiTidakKeTicketingTest` hijau (CI #899 `main` `e4392507`) |
| HX4 | §7.8 (4), AC-E04.1 | P1 (KA) mencari T2 (KB) lewat UI, `q`, dan URL detail | Tidak tampil; 404 | ✅ sama dengan HT4 |
| HX5 | §7.8 (5), `:571` | Sama dengan HB12 | Ringkasan/lonceng tiket tidak memuat notifikasi approval dan sebaliknya | ✅ sebagian: sama dengan HB12 |
| HX6 | §8 (`:1286-1287`) | Role 2 bertanya ke Vanya (cocok + fallback), lalu cek ringkasan helpdesk dan tabel tiket | Badge/ringkasan dan `disiplin_ticket(_msg)` tidak berubah; tidak ada push/tiket dari chatbot | ✅ U1 bertanya ke Vanya (cocok + fallback): `log_chatbot` +2; `disiplin_ticket(_msg)`, antrean push, dan ringkasan tidak berubah |

### HZ. Regresi Fase 4 (C-05, C-06) dan FAQ (G-10) (DoD `:1353-1354`)

C-05 = penerbitan surat cuti e-sign mock, C-06 = notifikasi approval (`docs/fase4/PRD_Fase4.md:34-35`, AC C-06 `:804-809`).

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HZ1 | C-05 (`PRD_Fase4.md:757-763`) | Terbitkan surat cuti final (akun role 1/3 dalam lingkup) | PDF ber-TTE mock tersimpan; `esign_hist` tercatat; perilaku tidak berubah | [test-otomatis] CI #899 `main` `e4392507` hijau; tidak diulang manual |
| HZ2 | C-06 AC-06.1..06.3 (`PRD_Fase4.md:805-807`) | Ajukan dan setujui/tolak cuti; cek tab Notifikasi dan lonceng | `inbox`/`inbox_msg` bertambah; job `push-notif` antre; `notifikasi_belum_dibaca` dan `butuh_approval` tetap benar bersama `tiket_belum_dibaca` | [test-otomatis] `NotifikasiTidakKeTicketingTest`, `PushNotifJobTest` hijau (CI #899 `main` `e4392507`); `tiket_belum_dibaca` terpisah teramati di HB12 |
| HZ3 | CR-137 (`:222`, `:649`) | Cuti/LKH/Konket memicu push lewat `AntrePushPesan::antre(nip[])` | Job `push-pesan` lama (`nip[]`) tetap terkirim (`MockFcmAdapter`); [test-otomatis] `backend/tests/Layanan/Notifikasi/PushAndroidCutiTest.php` dan push LKH (`backend/tests/Kepegawaian/LkhTest.php` `testPushWebRiwayatLkhIkutLegacyBilaDriverFcm:99`, `testPushTipe3HanyaBilaStatusKembaliMenunggu:138`) dan Konket (`backend/tests/Kepegawaian/KonketTest.php` `testPushAndroidProsesKePemohonDanPesertaGabunganIkutLegacy:234`); nama dicocokkan ulang sesudah CR-137 | [test-otomatis] `PushAndroidCutiTest`, push LKH, Konket hijau (CI #899 `main` `e4392507`) |
| HZ4 | G-10 (`backend/app/Controllers/Api/MasterData/README.md:208`), §8 (`:1288-1289`) | Pusat Bantuan → FAQ: daftar, pencarian, rating; admin FAQ ⋮ | Tidak berubah; konten HTML FAQ tetap lewat `SafeHtml`; [test-otomatis] `backend/tests/MasterData/FaqTest.php` hijau | [test-otomatis] `FaqTest` hijau (CI #899 `main` `e4392507`) |
| HZ5 | §3 berkas bersama (`:222-229`) | Regresi layar yang berkas bersamanya disentuh: tab Pusat Notifikasi (`NotifikasiPage.vue`), sidebar terbuka/ciut semua item, form yang memakai `UiPilihBanyak`/`galatKolom` | Tidak ada perubahan perilaku selain entri tiket dan titik penanda | [test-otomatis] sebagian: CI #899 `main` `e4392507` hijau; entri tiket (HB5), titik sidebar ciut (HB3), `UiPilihBanyak` (HA8) teramati manual |
| HZ6 | AC-E04.7 (`:889`), F-10 | Arsip F-10 untuk berkas legacy `arsip/{nip}/inbox/…` | Perilaku F-10 tidak berubah (Halo Simpeg tidak mengubah `Config\Arsip`, `:1283-1284`) | [test-otomatis] suite F-10 hijau (CI #899 `main` `e4392507`); `Config\Arsip` tidak diubah |

### HL. Rilis menu CR-135 (sesudah sign-off, ⛔ izin Horii; tidak menahan sign-off; PRD `:269`, `:375`, `:1332`, `:1361`)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| HL1 | F8-50, H8-11 (a) | Cek diff CR-135 | `ACTIVE_PHASE` 5 → 8 (`nav.config.ts:97`); `rilis` yang sudah lewat fasenya dibersihkan (mis. Integrasi `:296`); item `halo` (`:320`) diberi `to` dan `roles` 1–7 | — (sesudah sign-off, CR-135) |
| HL2 | `:607-608`, `8_2.md:109-110`, `:131-134` | [test-otomatis] test badge pada `NAV` asli (bukan `buildNav` tiruan) | Ada dan hijau; `nav.config.spec.ts` hijau | — (sesudah sign-off, CR-135) |
| HL3 | §6.3 (`:575-576`) | Login role 1–7 dan role 8 di build hasil CR-135 | Menu "Halo Simpeg" tampil untuk 1–7 dengan badge; role 8 tidak; menu admin "Halo Simpeg" dan "Chatbot Manager" untuk role 1; widget Vanya untuk 2/6/7 | — (sesudah sign-off, CR-135) |
| HL4 | F8-50 (`:375`) | Cek menu Fase 6/7 yang ikut terbuka (Integrasi, lonceng/Pusat Notifikasi, Arsip, dll.) | Tampil sesuai role; tanpa duplikat item | — (sesudah sign-off, CR-135) |

### HBB. "Bukan bug" helpdesk — sesuai keputusan (PRD §12, Lampiran B)

Pelaksana QA **tidak** mencatat hal berikut sebagai temuan. Kolom Hasil diisi ✅ bila perilaku teramati sesuai.

| # | Perilaku | Dasar | Butir terkait | Hasil |
|---|---|---|---|---|
| HBB1 | Daftar menampilkan "Admin" bagi role 1 dan bagi petugas penulisnya, sedangkan detail menampilkan nama bagi role 1 | D7 (a) (`:1430`), B-22 (`:1610`) | HS1, HS2 | ✅ (HS1, HS2) |
| HBB2 | Penanda sisi lawan = 2 dan sisi pembalas pemilik = 1 diperlakukan [I] | H8-10 tetap [I] (§K K-08; PRD `:1407`), F8-10 (`:329`) | HR3, HR4 | ✅ (HR3, HR4) |
| HBB3 | Tiket orang lain → 404, bukan 403 | BP8-2 (`:157`) | HP21, HT4 | ✅ (HP21, HT4) |
| HBB4 | Petugas nonaktif: `peran=petugas` → 200 kosong, bukan 403; push melewati petugas nonaktif | F8-06 (`:325`) | HT9, HU1 | ✅ (HT9, HU1) |
| HBB5 | Pemilik tidak bisa menghapus tiketnya (403) | F8-09, B-26 (`:328`, `:1614`) | HP19 | ✅ (HP19) |
| HBB6 | Pemilik yang petugas kategori lain boleh membalas dan menutup tiketnya | B-19 (`:1607`) | HP20 | [test-otomatis] (HP20) |
| HBB7 | Tiket ditutup tidak bisa dibuka kembali | F8-08 (`:327`) | HP18 | ✅ (HP18) |
| HBB8 | Tiket NULL hanya terlihat dan dapat ditutup pemilik, tidak dapat dihapus siapa pun, tidak terlihat role 1 | H8-16 (a1) (`:1413`) | HN1–HN4 | ✅ sebagian (HN1–HN4; tutup T4 tidak dijalankan manual) |
| HBB9 | Tiket tanpa pesan ada di akhir daftar | B-20 (`:1608`) | HP15 | ✅ (HP15) |
| HBB10 | Badge menu bisa lebih besar daripada angka lonceng | D8 (a) (`:1431`) | HB2 | ✅ (HB2) |
| HBB11 | Petugas role 4/5 tidak punya entri lonceng | H8-15 (b) (`:1412`) | HB8 | ✅ sebagian (HB8: role 4 tanpa halaman notifikasi, lonceng tetap berangka sesuai desain F-08; role 5 tidak diuji) |
| HBB12 | Bawaan filter baca "Tiket saya" = semua, antrean = belum | R9(1), B-25 (`:1432`, `:1613`) | HP2, HT2 | ✅ (HP2, HT2) |
| HBB13 | Ekspor boleh untuk petugas berkategori tunggal; ekspor kosong = pesan, bukan berkas, dan tidak diaudit | B-24, Q-A0-9 (`:1612`, `:1429`) | HE1, HE3 | ✅ (HE1, HE3) |
| HBB14 | Sel ekspor > 32.767 karakter dipotong "… [dipotong]" | B-27 (`:1615`) | HE9 | [test-otomatis] (HE9) |
| HBB15 | Lampiran lama tetap tersaji publik lewat `/berkas/` (risiko sadar); aplikasi memakai E-04 | H8-5 (a) (`:1402`), F8-17 (`:336`) | HP13, HZ6 | ✅ sebagian: aplikasi memakai E-04 (HP13); penyajian publik `/berkas/` tidak diuji (HZ6) |
| HBB16 | Berkas lampiran legacy ber-path anomali tertinggal saat hapus tiket | Q-A0-6 (a') (`:1426`), B-29 (`:1617`) | HT11 | ✅ (HT11) |
| HBB17 | Tidak ada popup login petugas; "Ingatkan Saya Kembali?" pindah ke form balas | F8-11, F8-12 (`:330-331`) | HT7, HT14 | ✅ (HT7, HT14) |
| HBB18 | Tidak ada tombol ekspor DataTables di master | B-31 (`:1619`) | HA13 | ✅ (HA13) |
| HBB19 | Warna pengirim dinomori per tiket relatif pemilik | B-23 (`:1611`) | HP11 | ✅ (HP11) |
| HBB20 | Label ⋮ "Lihat detail" dan "Tutup" di tabel tiket (di luar daftar label AGENTS.md) | `:589-597`, `8_2.md:97-99` | HT6 | ✅ (HT6) |
| HBB21 | Layar < 1024 px menampilkan daftar dan thread sebagai dua halaman | `:595-596` | HP10 | ✅ (HP10) |
| HBB22 | Menu Halo Simpeg belum tampil di `main` sebelum CR-135 (QA lewat URL/build lokal) | H8-11 (a) (`:1408`) | HG4, HL | ✅ (HG4) |
| HBB23 | Helper galat 422 dan pilih banyak ada dua salinan (chatbot vs `shared/`) sampai R10 langkah 2 di CR-134 dst. | B-30, R10 (`:1618`, `:1434`) | HA8 | — gugur: R10 langkah 2 selesai di CR-134 paket 1 (satu salinan di `shared/`) |
| HBB24 | Nama bergelar dipakai untuk akun ber-NIP role apa pun | B-28 (`:1616`) | HS5 | ✅ sebagian (HS5; akun ber-NIP role 3/4 tidak ada di demo) |

## Bagian C — Butir otomatis (cukup dicek bukti gate/CI)

Butir di bawah tidak dijalankan ulang secara manual. Pelaksana membaca isi test-nya, lalu mengisi Hasil berdasarkan CI `main`
pada SHA QA dan gate penuh GF3/HG3 (PHPUnit tidak dijalankan per butir; Vitest chatbot boleh dijalankan ulang, saat ini 83/83).
Nomor baris = baris deklarasi method di `a5cb7300`; dicocokkan ulang bila SHA QA berbeda.

### CA. Chatbot (AC-E10.1..AC-E10.30, kecuali AC-E10.22 yang manual penuh di §AV)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| CA1 | AC-E10.1 (PRD `:1182`) | Cek dokumen `backend/docs/db-review/DBV-033-skema-chatbot.md:8` | "DISETUJUI DB Validator (WS-2) @99be40a0, 13-11-2026"; merge `3fb8c5f7` (PR #40) | ✅ baris Status DBV-033: disetujui DB Validator (WS-2) @`99be40a0`, 13-11-2026; merge `3fb8c5f7` (PR #40) |
| CA2 | AC-E10.2 (`:1182`) | `SkemaChatbotTest.php` `testStrukturDanCollation:46`, `testIndeksDanFk:98`, `testDefault:143`, `testUpGagalTidakMenyentuhTabelYangSudahAda:153`, `testSeedStatus:176`, `testCheckFkDanUnique:183`, `testJudulUnikHanyaStatusAktifDanTidakAktif:222`, `testDownLaluUpKembali:237`, `testKueriAuditBerjalan:256` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA3 | AC-E10.3 (`:1184`), §7.10.7 (`:1252`) | `MesinJawabPembandingTest.php` `testSamaDenganMesinDev:46` + `backend/tests/_support/HaloSimpeg/Chatbot/pembanding.json` | Hijau; fixture 12 skenario `s01`–`s12`, 11 dibandingkan (top-1, cocok, skor 4 desimal, urutan dan skor kandidat); `s12` tag ganda dev `bandingkan: false` (beda yang diharapkan, §7.10.2 langkah 2); skenario (13) masukan ber-HTML tidak ada di fixture dan tidak dibandingkan (PRD `:1257-1258`, `xss_clean` dev). Opsional: periksa ulang satu skenario (F8-34b, `:1462`) | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA4 | AC-E10.4 (`:1187`) | `MesinJawabPembandingTest.php` `testSkorTepatAmbangDianggapCocok:63`, `testFeedbackTidakMeloloskanTetapiMembalikUrutanLolos:73`, `testPengaliFeedback:111`, `testTokenisasiDanKesetaraanAngka:95` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA5 | AC-E10.5 (`:1188`) | `WidgetEndpointTest.php` `testJalurBubbleMengabaikanPesanKlien:131` | Hijau; alur UI di AW6 | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA6 | AC-E10.6 (`:1189`) | `WidgetEndpointTest.php` `testBalasTidakCocokFallbackDanSaran:119`; `VanyaWidget.spec.ts:251` | Hijau; tampilan di AW9/AV5 | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA7 | AC-E10.7 (`:1190`) | `WidgetEndpointTest.php` `testBalasCocokMenulisSatuLogAnonimDanToken:101`, `testKorpusKosongTanpaLog:155` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA8 | AC-E10.8 (`:1191`) | `WidgetEndpointTest.php` `testFeedbackTokenSahDanDitimpa:179`, `testFeedbackDitolak:190`, `testKunciTidakTersedia:225`, `testTokenTerikatIdLog:268` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA9 | AC-E10.9 (`:1194`) | `WidgetEndpointTest.php` `testRoleWidgetDanAdmin:67`; `AdminEndpointTest.php` `testRoleAdminSaja:51`; `KontrakMatriksRoleTest.php:60`, `:170` dst. | Hijau; matriks UI di AM1–AM8 | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA10 | AC-E10.10 (`:1194`) | `WidgetEndpointTest.php` `testThrottlePerKelompok:241`, `testThrottlePulihSesudahSatuMenit:290`; `ConfigChatbotTest.php` `testNilaiBawaan:18` (20/30/60) | Hijau; pesan 429 di AW14 | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA11 | AC-E10.11 (`:1196`) | `WidgetEndpointTest.php` `testValidasiPanjangPesan:147`; `VanyaWidget.spec.ts:236`, `:323`, `:346`, `:383` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA12 | AC-E10.12 (`:1198`) | `AdminEndpointTest.php` `testQnaSimpanUbahTagDanSanitasi:152`; `ImporQnaTest.php` (sanitasi impor); `VanyaWidget.spec.ts:297` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA13 | AC-E10.13 (`:1199`) | `AdminEndpointTest.php` `testKategoriCrudStatusHapus:62`; `ChatbotManager.spec.ts:118`, `:461` | Hijau; spot UI di AA3/AA4 | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA14 | AC-E10.14 (`:1201`) | `WidgetEndpointTest.php` `testBubbleTopikPertanyaanPopuler:82`, `testBatasBubbleDanPopuler:305` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA15 | AC-E10.15 (`:1203`) | `ImporQnaTest.php` `testImporMembuatKategoriTagDanDeduplikasi:41`, `testSatuGalatTidakMenyimpanApaPun:72`, `testBerkasDitolak:92`, `testSelJauhDitolakTanpaMemuatPenuh:108`, `testSelKosongBergayaTidakDihitung:123`, `testJudulKolomEkstraDitolak:135`, `testIsiTerlaluPanjangJadiGalatBaris:140`, `testBalapanJudulTag1062:153`; `ChatbotManager.spec.ts:222`, `:314`, `:478` | Hijau; impor UI di AA10 | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA16 | AC-E10.16 (`:1209`) | `AdminEndpointTest.php` `testTagUrutIdMenurunDanEkspor:107`; `chatbot.amplop.spec.ts:236` | Hijau; berkas dibuka manual di AA6 | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA17 | AC-E10.17 (`:1210`) | `StatistikRiwayatTest.php` `testStatistikSesuaiFixture:54`, `testTopSepuluh:80`, `testStatistikKosong:91`; `ChatbotManager.spec.ts:250` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA18 | AC-E10.18 (`:1213`) | `StatistikRiwayatTest.php` `testRiwayatFilterHariWibDanPaginasi:100`, `testTanggalTidakValid:129`; `ChatbotManager.spec.ts:269`, `:445` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA19 | AC-E10.19 (`:1215`) | `AdminEndpointTest.php:85`, `:175`; `ImporQnaTest.php:69`; `WidgetEndpointTest.php:103-116` | **[test-otomatis] sebagian**: sisi `audit_logs` terkunci; sisi "tidak masuk log aplikasi" belum ada Logger uji → CR-133 (§K K-06); pelengkap manual AL3/AL4 | [test-otomatis] sisi `audit_logs` + sisi log aplikasi dikunci CR-133 `19a11ed5` (`LogAplikasiChatbotTest`); pelengkap AL3/AL4 ✅ |
| CA20 | AC-E10.20 (`:1217`) | `VanyaWidget.spec.ts:80-102` (gerbang render), `:137` (sessionStorage), `:156` (dialog berlabel, Escape, fokus kembali), `:389` (fokus), `:428`, `:452`, `:538` (logout); `VanyaShell.spec.ts:69`, `:74`, `:79` | **[test-otomatis] sebagian**; keyboard penuh, pembaca layar, dan matriks role di AW16, AW17, AM | [test-otomatis] sebagian; keyboard (AW16), pohon aksesibilitas (AW17), matriks role (AM) diuji manual |
| CA21 | AC-E10.21 (`:1220`) | `ChatbotManager.spec.ts:107`, `:118`, `:461`; cek statik `grep` di `frontend/src/features/halo-simpeg/chatbot` (tanpa `v-html` di luar `SafeHtml`, `window.confirm`, `<select>`, `title` native; hanya prop `title`/`:title` milik `UiCard`/`ConfirmDialog`: terikat `:title` di `MasterChatbotView.vue:189`, `:230` dan `StatistikChatbotView.vue:74`; statis `title="…"` di `QnaChatbotView.vue:233`, `:295` dan `RiwayatChatbotView.vue:106`; grep memakai `title=` agar keduanya tertangkap) | **[test-otomatis] sebagian**; tampilan di AA1, AA2, AA15 | [test-otomatis] sebagian; tampilan AA1, AA2, AA15 |
| CA22 | AC-E10.23 (`:1225`) | Suite FAQ: `backend/tests/MasterData/FaqTest.php`, `FaqSchemaTest.php`; `frontend/src/features/master-data/__tests__/faq.schema.spec.ts`, `faq.service.spec.ts`, `FaqRatingWidget.spec.ts`, `FaqView.spec.ts`. Sesudah R10 langkah 2 (migrasi `chatbot/galat.ts` dan `components/admin/TagPilihan.vue` ke `shared/`, CR-134 dst., `Kesiapan_Fase8.md:135`): test chatbot tetap 83/83 | Hijau di gate jalur chatbot dan helpdesk, diperiksa reviewer di M7; bagian helpdesk lihat CB | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA23 | AC-E10.24 (`:1229`) | `AdminEndpointTest.php` `testJudulUnikStatusAktifTidakAktif:88`, `testBalapan1062DiterjemahkanKe422:137`; `ImporQnaTest.php` `testBalapanJudulTag1062:153`; `SkemaChatbotTest.php:222` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA24 | AC-E10.25 (`:1231`) | `AdminEndpointTest.php` `testQnaValidasi:185`, `testBatasPanjangPertanyaanJawaban:200`, `testQnaSimpanUbahTagDanSanitasi:152`; `KontrakFixtureTest.php` `testGagalSisipRelasiTagQnaTidakTersimpan:113`; `ChatbotManager.spec.ts:132` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA25 | AC-E10.26 (`:1233`) | `AdminEndpointTest.php:78`, `:246-249` (dalam `testStatusIndukQna`); `ChatbotManager.spec.ts:421` | **[test-otomatis] sebagian**: saringan terkunci; paginasi halaman ke-2 kategori/tag/QnA tidak dikunci eksplisit (§K K-07); manual AA11 | [test-otomatis] sebagian: paginasi halaman 2 tidak dikunci eksplisit; manual AA11 sebagian (Kategori) |
| CA26 | AC-E10.27 (`:1234`) | `AdminEndpointTest.php` `testStatusIndukQna:217`; `WidgetEndpointTest.php` `testStatusIndukDiKorpus:164`, `testTagTidakAktifTidakMenambahSkor:280`; `ChatbotManager.spec.ts:166`, `:176`, `:205` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA27 | AC-E10.28 (`:1237`) | `AdminEndpointTest.php` `testTemplateImpor:125`; `chatbot.amplop.spec.ts:244` | Hijau; unduh UI di AA9 | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA28 | AC-E10.29 (`:1238`) | `WidgetEndpointTest.php` `testJalurBubbleMengabaikanPesanKlien:131` | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CA29 | AC-E10.30 (`:1239`) | — | — N/A, di luar DoD (H8-13 (b), DEPLOY-002; DoD `:1352` "AC-E10.1..29") | — |

### CB. Helpdesk dan regresi (diisi sesudah MAKE-070..074 di `main`)

| # | AC | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| CB1 | DBV-028, AC-E01.2, AC-E01.3 | `backend/tests/database/HaloSimpeg/SkemaHaloSimpegTest.php` (sudah di `main` `a5cb7300`; rincian method di CB13) | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CB2 | AC-E02.7, §11.1 | `KontrakMatriksRoleTest.php` dengan `PREFIKS_CAKUPAN` diperluas ke `halo-simpeg` (HG5, HM9) | Hijau; menunggu MAKE-070/071 | [test-otomatis] `PREFIKS_CAKUPAN` memuat `halo-simpeg`; hijau |
| CB3 | §6.1b (`:527-535`) | Trait fixture `backend/tests/_support/HaloSimpeg/` + salinan FE (HG2) | Hijau; menunggu MAKE-071 | [test-otomatis] `KontrakFixtureTiketTest::testBentukResponsTiketSamaDenganFixture`; hijau |
| CB4 | §7.8 (1), AC-E04.2 | Test dua tiket paralel MAKE-071 (HX1) | Hijau; nama test diisi sesudah MAKE-071 | [test-otomatis] `TiketHaloSimpegTest::testThreadTidakTerMixDanTanpaDependensiNotifikasi`; hijau |
| CB5 | §7.8 (3), AC-06.4 Fase 4 | `backend/tests/Layanan/Notifikasi/NotifikasiTidakKeTicketingTest.php` (HX3) | Hijau | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CB6 | C-06, CR-137 | `backend/tests/Layanan/Notifikasi/PushAndroidCutiTest.php`, `PushNotifJobTest.php`, push LKH (`backend/tests/Kepegawaian/LkhTest.php`) dan Konket (`backend/tests/Kepegawaian/KonketTest.php`) (HZ2, HZ3) | Hijau sesudah CR-137 | [test-otomatis] hijau sesudah CR-137 (CI #899 `main` `e4392507`) |
| CB7 | E-06 | Test antre push MAKE-072 (`antreAkun`, payload terbatas) (HU1–HU5) | Hijau; nama test diisi sesudah MAKE-072 | [test-otomatis] `TiketNotifTest` (penerima per akun, pelaku dikecualikan, payload terbatas); hijau |
| CB8 | AC-E09.8 | Test batas ekspor MAKE-074 (10.000 baris, ukuran, potong sel) (HE9) | Hijau; nama test diisi sesudah MAKE-074 | [test-otomatis] `EksporTiketTest` `testBatasSepuluhRibuBaris`, `testBatasUkuranKarakter`, `testSelPanjangDipotong`; hijau |
| CB9 | AC-E08.4, AC-E08.x | Vitest komponen helpdesk MAKE-073/B4 (keyboard, ⋮, keadaan memuat/galat) (HK5) | Hijau; nama test diisi sesudah MAKE-073 | [test-otomatis] `HaloSimpegPage.spec.ts`, `HaloAdmin.spec.ts`, `HaloShell.spec.ts`, `EksporRekapDialog.spec.ts`; hijau |
| CB10 | G-10, AC-E10.23 | Suite FAQ BE + FE (sama dengan CA22) pada SHA QA bagian B | Hijau | [test-otomatis] suite FAQ hijau (CI #899 `main` `e4392507`) |
| CB11 | F8-50 | Test badge pada `NAV` asli + `nav.config.spec.ts` (HL2) | Hijau; dicek saat CR-135, tidak dihitung dalam sign-off | — (sesudah sign-off, CR-135) |
| CB12 | AC-E01.1 (PRD `:734`) | Cek dokumen `backend/docs/db-review/DBV-028-skema-helpdesk.md` (baris **Status**) | Disetujui DB Validator (WS-2); SHA approve dan merge dicatat | ✅ baris Status DBV-028: SETUJU @`9189c696` (Q1–Q7) dan @`c147f24a` (Q8–Q10), WS-2 17-11-2026; di `main` `a5cb7300` (PR #41) |
| CB13 | AC-E01.2, AC-E01.3 (`:734-735`) | `SkemaHaloSimpegTest.php` (CB1): `testStrukturDanCollation:51`, `testIndeksFkDanCheck:91`, `testIndeksAditifDisiplinTicket:119`, `testUniqueFkDanCheckDitegakkan:142`, `testUpGagalMelepasTabelYangDibuatSajaDanTidakMenyentuhTabelLama:176`, `testUpIndeksGagalMelepasIndeksYangDibuatSaja:204`, `testDownLaluUpKembali:236` | Hijau; migration naik/turun bersih, `disiplin_ticket(_msg)`/`question_category` tidak dibuat ulang, FK/UNIQUE/CHECK ditegaskan | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CB14 | AC-E01.4 (`:736`) | `backend/tests/database/Layanan/SkemaInboxTicketTest.php` | Hijau (skema `inbox`/`inbox_msg` Fase 4 tidak berubah) | [test-otomatis] hijau (CI #899 `main` `e4392507`) |
| CB15 | AC-E03.6, AC-E03.10 (`:792-793`) | Test MAKE-071: gagal di tengah transaksi buat tiket; push antre sesudah commit dan kegagalan antrean tidak menggagalkan tiket | Hijau; tanpa tiket/pesan/berkas sisa saat gagal; nama test diisi sesudah MAKE-071 | [test-otomatis] `TiketLampiranTest::testGagalDiTengahTransaksiTanpaSisa`, `TiketNotifTest::testGagalAntreTidakMenggagalkanPesan`; hijau |
| CB16 | AC-E04.14, AC-E05.6 (`:905`, `:919`) | Test MAKE-071: flag `bisa_balas`/`bisa_tutup`/`bisa_hapus` = hasil aksi untuk lima jenis pelaku (HP25); test alur penuh buat → balas → tutup (tidak ada tiket Open yang tak bisa ditutup) | Hijau; nama test diisi sesudah MAKE-071 | [test-otomatis] `TiketHaloSimpegTest::testFlagSamaDenganHasilAksi`, `testTutupHapusDanAlurPenuhTanpaInbox`; hijau |
| CB17 | AC-E06.4 (`:967`) | Test MAKE-072: job push lolos dengan `MockFcmAdapter` dan dengan `FcmV1Adapter` + `FakeTransport` | Hijau; CI tidak memanggil layanan nyata; nama test diisi sesudah MAKE-072 | [test-otomatis] `TiketNotifTest::testFcmV1TokenTidakSahDihapusDanRetryTerbatas` (`FcmV1Adapter` + `FakeTransport`), `testJobTerkirimKePetugasRole1TanpaNipDanRole6`; hijau |

## Bagian D — Pencatatan temuan & alur

**Alur.** (1) Pelaksana QA mengisi kolom Hasil. Butir yang gagal diisi ❌ + nomor `T-xx`, lalu dicatat di §T (butir, temuan, dampak,
usulan). Bukti mengikuti aturan "Isi kolom Hasil". (2) Perilaku yang tercantum di §ABB/§HBB tidak dicatat sebagai temuan. Hal yang
belum diputus dicatat di §K sebagai ◻, bukan di §T. (3) Reviewer CR menilai dampak dan menetapkan apakah temuan menahan sign-off,
lalu menetapkan paket perbaikan berikut. (4) Paket perbaikan dibuka dengan izin Horii per paket (⛔ merge oleh reviewer CR), memakai
branch baru dari `origin/main` → gate cepat → push → CI `quality-gate` hijau → lapor (PRD §10). (5) Sesudah perbaikan di `main`, butir
terkait diuji ulang dan kolom Hasil diperbarui dengan SHA perbaikan. Status §T diubah ke "✔ diperbaiki (<key> `<SHA>`)" atau "✔
dicatat saja". (6) Ringkasan akhir dimasukkan ke paragraf Status dan ke `docs/fase8/Laporan_Akhir_Fase8.md` (temuan QA, butir yang
ditunda ke DEPLOY-002).

**Penerima perbaikan.**

| Jenis temuan | Paket | Pelaksana | Catatan |
|---|---|---|---|
| Chatbot FE (widget, Chatbot Manager) | CR-134 dst. | Usulan Worker Muse (`Kesiapan_Fase8.md:105`) | Izin ✔ Horii 17-11-2026; termasuk R10 langkah 2 (`Kesiapan_Fase8.md:135`) |
| Chatbot BE | CR-134 dst. | Usulan ReadOne | Izin ✔ Horii 17-11-2026 |
| AC-E10.19 sisi log aplikasi | CR-133 | ReadOne (logger uji) | Izin ✔ Horii 17-11-2026 |
| Helpdesk BE (E-01..E-09) | Perbaikan dalam paket MAKE-070..074/097 atau CR baru | Handling Case (izin [#9]) | Key dan izin per paket diputus reviewer CR + Horii |
| Helpdesk FE (B0–B4) | Perbaikan dalam paket MAKE-073/B4 atau CR baru | Worker Muse (izin [#9]) | Sama |
| Skema (DBV-028/DBV-033) | DBV-### baru | DB Validator (WS-2) | Lewat dokumen `backend/docs/db-review/` dan alur PR `[DBV]` |
| Dokumen (PRD, README, checklist) | CR dokumen | Reviewer CR | Sinkron PRD/Kesiapan sesudah keputusan |

## K. Keputusan / pertanyaan pra-QA

◻ = menunggu keputusan reviewer/Horii; ✔ = diputus.

| # | Temuan / pertanyaan | Keputusan |
|---|---|---|
| K-01 | Widget hanya dirender bila `ACTIVE_PHASE >= 8`; di `main` nilainya 5 dan tidak ada URL langsung untuk widget. Item `halo` belum punya `to`/`roles`. Chatbot Manager terbuka lewat URL langsung | ◻ Usulan: patch lokal `ACTIVE_PHASE = 8` (+ `to`/`roles` item `halo` untuk bagian B) di worktree demo, tanpa commit, dibuang sesudah QA; bukti `git status` bersih dicatat. Alternatif: menunggu CR-135 (ditolak karena CR-135 sesudah sign-off) |
| K-02 | Acuan visual PN8-8: 22 selisih widget v2 terhadap widget dev | ✔ Horii 17-11-2026, "ikut rekomendasi": avatar tetap ilustrasi SVG (foto tidak dipakai), aksen biru `brand-tertiary` (bukan pink dev), saat memuat jawaban tombol kirim disabled + indikator (perilaku v2), launcher 64 px dan gaya nama "Vanya" tetap v2; perbaikan [K] avatar mini bot rata atas (`VanyaWidget.vue:209-210`) masuk CR-134 (§T T-01). Acuan disimpan di luar repo. PRD §12 (baris PN8-8 `:1414`, yang baru mencatat penerimaan acuan 16-11) dan Kesiapan disinkron oleh reviewer CR (E7) |
| K-03 | Izin CR-133 (test AC-E10.19 log aplikasi, ReadOne) dan CR-134 dst. (perbaikan QA chatbot); PRD `:267` dan `Kesiapan_Fase8.md:105`, `:112-113`, `:238` masih bertanda ◻ izin | ✔ Disetujui Horii 17-11-2026 (ikut rekomendasi). PRD dan Kesiapan disinkron oleh reviewer CR |
| K-04 | Indikator mengetik `VanyaWidget.vue:270` juga `items-end`; banding menandainya opsional, sedangkan keputusan Horii hanya menyebut `:209-210` | ◻ Reviewer memutus apakah `:270` ikut CR-134. Sampai diputus, butir ini catatan saja, bukan temuan |
| K-05 | Rekomendasi banding #12 dan #16: catat perilaku "disabled + indikator saat memuat" dan "tombol pudar saat kosong" sebagai [V2] di README chatbot FE (`:33-36` belum memuatnya) | ◻ Usulan: catatan dokumentasi ikut CR-134 (rendah) |
| K-06 | AC-E10.19 sisi "tidak masuk log aplikasi" belum dikunci test (tidak ada Logger uji di test chatbot) | ✔ Ditutup lewat CR-133 (izin K-03). ◻ Usulan: CR-133 di `main` sebelum sign-off bagian A, karena AC-E10.19 termasuk DoD (`:1352`); AL4 sebagai pelengkap manual |
| K-07 | AC-E10.26: paginasi halaman ke-2 kategori/tag/QnA tidak terlihat dikunci eksplisit di test | ◻ Usulan: uji manual AA11; bila lolos, catat celah test sebagai T rendah dan tambahkan test di CR-134 dst. (tidak menahan sign-off) |
| K-08 | H8-10: penanda sisi lawan = 2 dan sisi pembalas pemilik = 1 tidak terverifikasi di legacy | ✔ H8-10 tetap [I] (keputusan Horii 17-11-2026); diuji per skenario AC-E04.4 (§HR); selisih dengan legacy bukan temuan (HBB2). PRD §12 (H8-10 `:1407`, masih ◻) dan Kesiapan (`Kesiapan_Fase8.md:260`, `:340`, masih ◻) disinkron oleh reviewer CR (E7) |
| K-09 | H8-9: frame Figma helpdesk belum ada | ✔ AC-E08.7 diuji terhadap pola redesign (AGENTS.md) dan Gambar 33/34 (§HV); tiap layar mencatat "Figma belum ada" |
| K-10 | H8-7 (satker kepegawaian, PRD `:1404`) masih terbuka; `Kesiapan_Fase8.md:257` menyebut "dijawab sebelum QA" | ◻ Usulan: HA6/HA7 dijalankan dengan nilai `web_config` demo dan ditandai "bergantung H8-7"; hasil kandidat dicocokkan ulang sesudah konfirmasi Biro SDM. Perlu diputus apakah H8-7 menahan sign-off bagian B atau cukup dicatat |
| K-11 | Cara membuat T4 (NULL), T5 (tanpa pesan), dan T6/T7 (path legacy) | ◻ Pilihan: sisip langsung ke DB demo atau impor F-02 dengan data sintetis; berkas sintetis di path legacy storage demo. Perlu ditetapkan juga apakah T-5 MAKE-097 (`ManifestLampiran`) diuji di demo atau cukup [test-otomatis] |
| K-12 | Kategori punya `bisa_hapus` (`:466`), tetapi §6.3 hanya mewajibkan ⋮ Hapus `disabled` untuk petugas (`:583`) | ◻ Untuk kategori yang dipakai: ⋮ Hapus `disabled` dari `bisa_hapus`, atau 422 `kategori_dipakai` di `UiNotice`? HA4 mengikuti putusan ini |
| K-13 | HE9: batas 10.000 baris, ukuran, dan potong sel butuh data besar | ◻ Usulan: cukup [test-otomatis] MAKE-074; tampilan FE 422 diuji lewat API tiruan |
| K-14 | HU (push) di demo hanya mock; H8-8 masih terbuka | ◻ Usulan: antrean diperiksa lewat `queue_jobs` + `queue:work push` dengan `MockFcmAdapter`, ditambah [test-otomatis] MAKE-072/CR-137; payload terbatas |
| K-15 | Lingkungan QA bagian A dan B sama (worktree demo reviewer) | ◻ Usulan: bagian A dijalankan sekarang di `a5cb7300` atau sesudahnya; bagian B di SHA sesudah MAKE-073/074. Rujukan bagian A dicocokkan ulang bila SHA QA bagian B memuat perubahan chatbot (mis. CR-134) |
| K-16 | Lokasi Gambar 33/34 untuk §HV | ◻ Usulan: frontend redesign `67fa7678` (branch `feat/frontend-ui-redesign`). Bila acuan visual lain dipakai, catat di Status |
| K-17 | Akun demo petugas role 3/4 harus ber-NIP di satker kepegawaian demo (kandidat F8-07, PRD `:326` [K `LS:468-491`]; petugas bisa ber-role 3/4/5, PRD `:338` F8-19 [K `LS:470-484`]) | ◻ Bila akun demo yang ada tidak memenuhi, tambah akun demo sintetis lewat skrip seed di luar repo (**bergantung H8-7**) |
| K-18 | §HL (CR-135) dijalankan sesudah sign-off sebagai verifikasi rilis (pola Fase 7 `docs/fase7/Laporan_Akhir_Fase7.md:77`) | ◻ Usulan: dicatat terpisah di checklist ini dan tidak dihitung dalam ringkasan sign-off |
| K-19 | Rujukan berkas:baris kode helpdesk, nama test, dan SHA bagian B ditulis sebelum kodenya ada | ◻ Usulan: dicocokkan ulang terhadap SHA QA sesudah MAKE-073/074 di `main` (pola K-07 F10); selisih kelas rujukan dikoreksi langsung di kolom AC/Langkah/Hasil yang diharapkan |

## T. Temuan

(Diisi pelaksana QA. Satu baris per temuan; kolom Butir memakai nomor berawalan, mis. `AV2`, `HP13`.) T-01 sudah diketahui sebelum
QA dari banding PN8-8 dan dicatat lebih dulu.

| # | Butir | Temuan | Dampak | Usulan / status |
|---|---|---|---|---|
| T-01 | AV2 (selisih PN8-8 #5) | [K] Avatar mini bot tidak sejajar tepi atas balon. Dev memakai `align-items: flex-start` (`dev:views/hr/chatbot/chatbot_popup.php:153-154`); v2 memakai `flex max-w-[92%] items-end gap-2` + `mb-4` (`VanyaWidget.vue:209-210`), sehingga avatar turun ke baris terakhir balon | Rendah, tata letak | ✔ Masuk CR-134 (keputusan Horii 17-11-2026, K-02): `:209` → `items-start`, buang `mb-4` di `:210`; tambah atau sesuaikan test bila ada yang mengunci kelas. Indikator mengetik `:270` ikut §K K-04. Pelaksana usulan Worker Muse. AV2 diuji ulang sesudah CR-134 di `main`. **✔ diperbaiki (CR-134 paket 1, di `main` per `67f3303c`; `:270` ikut)**; AV2 ✅ |
| T-02 | AA8 | Satu galat "Kategori QnA sudah dihapus. Pilih kategori lain." tampil tiga kali di dialog Ubah QnA (notice galat, galat field, notice peringatan) | Rendah, UI | ✔ diperbaiki (CR-134 paket 2 `d659acf6`); uji ulang: satu elemen sebelum dan sesudah Simpan |
| T-03 | AA10 | Dialog Impor Excel tidak membersihkan galat server sebelumnya saat berkas baru dipilih | Rendah, UI | ✔ diperbaiki (CR-134 paket 2 `d659acf6`); uji ulang: galat lama hilang saat berkas berganti |
| T-B1 | HP3 | Pesan sukses legacy "Berhasil menambahkan data halo simpeg." (PRD §7.3) tidak ada di BE maupun FE | Non-blok, paritas legacy; diperbaiki sebelum sign-off (keputusan Horii 18-11-2026) | ✔ diperbaiki (CR-143 `4d06a8c6`: `message` pada 201 buat tiket; CR-142 `8bc6c509`: notice `role=status` dari `message`, teks cadangan legacy); notice FE diverifikasi di demo (CR-142 @`f49f075e`, teks cadangan karena BE demo belum CR-143); sisi BE `message` diterima lewat test otomatis (CI #900) |
| T-B2 | HP13 | `Cache-Control` gabungan `no-store, max-age=0, no-cache, private, no-store` pada unduhan lampiran (dan endpoint lain yang memakai helper `tanpaCache`); README: `private, no-store` | Rendah, header (tetap `no-store`) | ◻ Kandidat CR sesudah sign-off (helper bersama), tidak menahan sign-off |
| T-B3 | HK1 (tabel daftar tiket) | Desktop 1536 px dengan sidebar terbuka: kolom Aksi ⋮ tabel daftar terpotong, harus gulir horizontal tabel untuk membuka ⋮ | Non-blok, UI; diperbaiki sebelum sign-off (keputusan Horii 18-11-2026) | ✔ diperbaiki (CR-142 `8bc6c509`: sel Aksi `sticky` kanan); diverifikasi di demo, ⋮ sepenuhnya di dalam wadah tabel |
| T-B4 | HP24 | Desktop, thread digulir ke bawah: launcher Vanya menutupi sebagian tombol "Kirim" form balas (AC HP24 gagal) | Menahan sign-off (AC HP24) | ✔ diperbaiki (CR-142 `8bc6c509`: ruang bawah halaman + `scroll-padding` untuk fokus, WCAG 2.4.11); ruang bawah diverifikasi di demo pada CR-142 @`f49f075e` (desktop, Kirim tanpa irisan dengan launcher sesudah digulir); `scroll-padding` (`a2f1e894`) dikunci test `564ebaa5` + CI #904, belum diverifikasi di demo |
| O-1..O-6 | HA5, HA6, HP7, HA10, HB8, AA14 | Pengamatan, bukan temuan: spasi ganda di tengah judul kategori tidak dinormalkan (sesuai PRD §7.3a); petunjuk "minimal 2 karakter" hanya placeholder; throttle tiket dicek sebelum validasi (README); Nonaktifkan petugas tanpa ConfirmDialog (pola master lain); lonceng role 4 tampil berangka (desain F-08); teks kosong tabel tampil bersama banner galat | — | ✔ dicatat saja |

## Bagian E — Sign-off QA

Sign-off QA Lapis 1 Fase 8 diberikan reviewer CR dan Horii sesudah semua syarat di bawah terpenuhi. Sesudah eksekusi, ringkasan
jumlah ✅ / [test-otomatis] / — / ❌ per bagian, daftar temuan, dan sign-off ditulis di paragraf **Status** (pola F10).

| # | Syarat | Acuan | Hasil |
|---|---|---|---|
| E1 | GF1–GF5 dan HG1–HG6 terpenuhi; SHA QA bagian A dan B dicatat | §GF, §HG | ✅ sebagian: GF1, GF2, GF5, HG1, HG4, HG6 ✅; HG2, HG3, HG5 [test-otomatis]; GF3 tanpa angka gate penuh lokal; GF4 lihat E7. SHA QA: bagian A `67f3303c` (putaran 1), `d659acf6` (uji ulang T-02/T-03, AW17), `e4392507` (sisa); bagian B `e4392507`; verifikasi perbaikan CR-142/CR-143, di `main` `8bc6c509` |
| E2 | Semua butir bagian A (AG, AW, AV, AA, AL, AM) dieksekusi; ❌ 0, atau semua temuan yang menahan sign-off sudah diperbaiki dan diuji ulang (termasuk T-01 CR-134) | PRD §11 (`:1352`), AC-E10.1..29 | ✅ semua butir bagian A dieksekusi atau diterima [test-otomatis]; ❌ 0; T-01 (CR-134 paket 1) dan T-02/T-03 (CR-134 paket 2 `d659acf6`) tuntas dan diuji ulang |
| E3 | CR-133 di `main` dan CA19 hijau (sisi log aplikasi AC-E10.19) | §K K-06 | ✅ CR-133 di `main` `19a11ed5`; CA19 hijau |
| E4 | Semua butir bagian B (HP..HZ, kecuali HL) dieksekusi; §HX (§7.8) hijau; regresi Fase 4 C-05/C-06 dan FAQ G-10 (§HZ) hijau | PRD §11 (`:1353-1354`) | ✅ sebagian: bagian B dieksekusi; §HX hijau; §HZ [test-otomatis] hijau; T-B1, T-B3, T-B4 diperbaiki (CR-143, CR-142) dan diverifikasi; T-B2 ke CR sesudah sign-off; butir yang diterima lewat test otomatis tanpa uji manual sama dengan daftar Laporan §4 (HP14, HP20, HP22, HP23 sebagian, HR1, HN5 sebagian, HA7, HK5, HB6, HB7, HE2, HE5, HE9, HU5, HX3, HZ1–HZ6, HBB6, HBB14; gerbang HG2, HG3, HG5, HM9) |
| E5 | Bagian C: CA dan CB hijau di CI `main` pada SHA QA; Vitest chatbot 83/83 sesudah R10 langkah 2 | §CA, §CB, AC-E10.23 | ✅ CA/CB hijau di CI #899 `main` `e4392507` dan CI #905 `main` `8bc6c509` (sesudah CR-143/CR-142); test chatbot 86/86 (≥ baseline 83 sesudah R10 langkah 2, laporan B1 MAKE-073) |
| E6 | §ABB dan §HBB teramati sesuai | §ABB, §HBB | ✅ sebagian: §ABB teramati (ABB19 sebagian); §HBB teramati kecuali HBB6, HBB14 ([test-otomatis]) dan HBB23 (gugur); HBB11 sebagian (role 5 tidak diuji) |
| E7 | Semua butir ◻ §K diputus (K-10 H8-7 diputus apakah menahan sign-off); PRD dan Kesiapan disinkron untuk K-02 (PN8-8), K-03 (izin CR-133/134), dan K-08 (H8-10) | §K | ✅ sebagian: K-02, K-03, K-08 disinkron di PRD v1.4 (CR-138 `7ecbe12b`); K-06 tuntas (CR-133); K-10: H8-7 = butir cutover, tidak menahan sign-off (PRD §11 v1.4); butir ◻ lain dijalankan sesuai usulan, kolom Keputusan §K belum diperbarui |
| E8 | Sign-off reviewer CR, lalu sign-off Horii; hasil dicatat di Status, `docs/fase8/Laporan_Akhir_Fase8.md`, dan `Kesiapan_Fase8.md` | PRD §11 | ✔ sign-off Horii 18-11-2026 ("Sign-off fase 8") pada `main` `d8020fc3`, sesudah laporan reviewer CR `docs/fase8/Laporan_Akhir_Fase8.md` (CR-144); dicatat di Status, laporan akhir, dan `Kesiapan_Fase8.md` (CR-145) |
| E9 | Sesudah sign-off: §HL dijalankan saat CR-135 (⛔ izin Horii), dicatat terpisah | §K K-18 | — (sesudah sign-off, CR-135) |
