# Laporan Akhir Fase 8 — Halo Simpeg + Chatbot "Vanya" (CR-144)

Acuan: PRD Fase 8 **v1.4** (`docs/fase8/PRD_Fase8_HaloSimpeg.md`, §0.1 status, §3.1 paket, §5.2 keputusan, §7.8 "thread tidak
ter-mix", §11 DoD dan exit criteria, §12 register keputusan, Lampiran B anomali dan perlakuan v2), rincian jalur `docs/fase8/8_1.md`
(BE) dan `docs/fase8/8_2.md` (FE), `docs/fase8/Kesiapan_Fase8.md`, progres `backend/docs/progress/08-HaloSimpeg.md`, checklist
`docs/fase8/Checklist_QA_Lapis1_Fase8.md` (CR-132, hasil diisi CR-144), dokumen skema DBV-028
(`backend/docs/db-review/DBV-028-skema-helpdesk.md`) dan DBV-033 (`backend/docs/db-review/DBV-033-skema-chatbot.md`), runbook
cutover `README-deploy.md` §1m dan §1n. Disusun reviewer CR 18-11-2026 dari `main` `8bc6c509` (seluruh paket Fase 8 dan perbaikan
temuan QA sudah di `main`). Setiap SHA di laporan ini dicek dengan `git` terhadap `origin/main`.

> **Status:** **FASE 8 DONE — SIGN-OFF Horii 18-11-2026** pada `main` `d8020fc3` (laporan ini masuk `main` lewat CR-144, CI
> `quality-gate` #906 hijau; sign-off dicatat CR-145). QA Lapis 1 Fase 8 selesai 18-11-2026; penutup seluruh development (PRD §11).
> E-01..E-10 di `main`; DBV-028 dan DBV-033 disetujui DB Validator; QA Lapis 1 selesai pada `main` `e4392507` (bagian B) dengan
> verifikasi perbaikan pada `8bc6c509`: 300 butir sign-off, **0 ❌ terbuka**. Temuan T-01..T-03 (chatbot) dan T-B1, T-B3, T-B4 (helpdesk)
> diperbaiki (CR-134, CR-143, CR-142) dan diuji ulang; T-B2 menjadi kandidat CR sesudah sign-off (sesudahnya ✔ diperbaiki CR-147 (`e5185919`) + CR-151 (`efd7d37f`)). Rilis menu (CR-135) sesudah
> sign-off, ⛔ izin Horii. Blocker deploy di §8 bukan syarat sign-off.

## 1. Laporan

```
Status: E-01..E-10 DONE di main (E-10 = DBV-033 + MAKE-094 + MAKE-095, AC-E10.1..29; AC-E10.30/C4 di luar DoD)
        QA Lapis 1 Fase 8 SELESAI (300 butir sign-off: ✅ / ✅ sebagian / [test-otomatis] / [mock-terbatas] / — beralasan; 0 ❌ terbuka;
        temuan T-01..T-03, T-B1, T-B3, T-B4 diperbaiki & diuji ulang; T-B2 sesudah sign-off:
        ✔ CR-147 e5185919 + CR-151 efd7d37f)
Branch/SHA/CI: main 8bc6c509 (CR-142, perbaikan QA terakhir); CI `quality-gate` `main` #905 hijau pada SHA ini
               SHA QA bagian B = main e4392507 (seluruh kode Fase 8) — CI quality-gate #899 hijau
               CR-143 @403d19c6 CI #900 hijau, CR-142 @564ebaa5 CI #904 hijau (branch, sebelum merge)
               Checklist terisi + laporan ini + progres + baris status PRD: docs saja, di main d8020fc3 (CR-144, CI #906 hijau)
Berkas utama: §2
Test & validasi: §3 (DoD §11 → bukti), §4 (QA Lapis 1), §5 (gate dan CI)
Deviasi [K]/[V2]/[I] & keputusan: §6
Blocker / butir terbuka sebelum sign-off: §7; blocker deploy/go-live: §8
```

## 2. Paket Fase 8 di `main`

Pelaksana (izin [#8-WorkloadChatbot], [#9-WorkloadHaloSimpeg] dengan amandemen 16-11-2026, [#10-WorkloadQAChatbot]): helpdesk BE —
Handling Case; helpdesk FE dan chatbot FE — Worker Muse; chatbot BE, T12b, dan CR-133 — ReadOne; DB Validator — WS-2; reviewer CR
(review, gate, merge, QA). Setiap merge atas izin Horii. Urutan tabel mengikuti urutan masuk `main`.

| Task | Isi | Key | SHA di `main` |
|---|---|---|---|
| E-10 skema | 6 tabel chatbot, status 1/2/10, `judul_unik` STORED + UNIQUE, `log_chatbot` anonim (PR #40) | DBV-033 | `3fb8c5f7` |
| E-10 BE | Mesin jawab TF-IDF port dev, endpoint widget (role 2/6/7) dan admin (role 1), impor/ekspor, statistik, riwayat, throttle, token feedback | MAKE-094 | `4af0c188` |
| E-10 FE | Widget "Vanya" + Chatbot Manager (lima halaman) | MAKE-095 | merge `2a8f405d` |
| Dokumen | Sinkron status Kesiapan + progres | CR-130 | `2a7b231b` |
| PRD | v1.2 — keputusan H8, tabel paket §3.1, kewajiban A0 | CR-131 | `4899d5af` (rujukan baris `ef83356e`) |
| PRD | v1.3 — hasil A0/B0, kontrak §6.1b, keputusan Horii 16-11-2026 | CR-136 | `46f4d669` |
| E-01 | Skema helpdesk `support_officer`, `officer_category`, `help_desk_contact` + indeks tiket (PR #41) | DBV-028 | `a5cb7300` |
| Perbaikan QA chatbot paket 1 | Avatar mini rata atas (T-01), catatan [V2] PN8-8, R10 langkah 1 + 2 (`galatKolom`, `UiPilihBanyak` ke `shared/`) | CR-134 | `32a619ce`..`718d3197` (di `main` per `67f3303c`) |
| QA | Checklist QA Lapis 1 Fase 8 | CR-132 | `67f3303c` |
| AC-E10.19 | Test isi pesan chatbot tidak masuk log aplikasi maupun `audit_logs` (`LogAplikasiChatbotTest`) | CR-133 | `19a11ed5` |
| E-02, E-07 | Master kategori, petugas, kontak (README master beku, S1–S12) | MAKE-070 | merge `56a700b1` |
| Helper satker | `WebConfigService::satkerKepegawaian()` | CR-139 | `ccb90f78` |
| Perbaikan QA chatbot paket 2 | T-02, T-03 | CR-134 | merge `d659acf6` |
| Stabilitas test | `alurInti.e2e` tidak flaky di bawah beban | CR-140 | `6c2b3305` |
| E-03..E-05 + ringkasan | Tiket, percakapan, lampiran (E-04), tutup/hapus, penanda baca, ringkasan + binding lonceng F-08 | MAKE-071 | merge `066315c5` |
| PRD | v1.4 — status paket, keputusan Horii/reviewer/DB Validator 17-11-2026 | CR-138 | merge `60f6754d` (commit `7ecbe12b`) |
| T12b | Impor master helpdesk + lampiran tiket lama di `ManifestLampiran` (T-5) (PR #42) | MAKE-097 | merge `82d698ec` |
| E-06 | Push tiket per akun + CR berkas bersama Push (`antreAkun`) | MAKE-072, CR-137 | merge `64d68377` |
| UI bersama | Teks info paginasi Bahasa Indonesia | CR-141 | merge `dd7cafe3` |
| E-09 BE + penutup BE | Ekspor rekap petugas, README modul, runbook cutover `README-deploy.md` §1n | MAKE-074 | merge `947b94fd` |
| E-08 | FE helpdesk: layar pemilik, petugas, admin master, badge/titik sidebar, entri Notifikasi (B1–B3) | MAKE-073 | merge `d90d8a75` |
| E-09 FE + penutup FE | Tombol + dialog ekspor rekap (B4), README FE helpdesk (B5) | MAKE-074 (FE), MAKE-073 B5 | merge `e4392507` |
| Temuan QA T-B1 (BE) | `message` sukses legacy pada 201 buat tiket | CR-143 | merge `4d06a8c6` |
| Temuan QA T-B4, T-B3, T-B1 (FE) | Ruang bawah halaman untuk launcher + `scroll-padding`, kolom Aksi sticky, notice sukses tiket baru | CR-142 | merge `8bc6c509` |
| Penutup | Laporan akhir ini, hasil checklist, progres, baris status PRD | CR-144 | `d8020fc3` (CI #906 hijau) |

Kode C4 (impor isi QnA dev) tidak dibuat: keluar dari DoD (H8-13 (b)), dilacak DEPLOY-002.

## 3. DoD §11 → bukti

| DoD PRD §11 | Bukti |
|---|---|
| E-01..E-10 DONE di `main`, AC terpenuhi (E-10 AC-E10.1..29; AC-E10.30/C4 tidak termasuk) | §2; AC per task dikunci test modul (`backend/tests/database/HaloSimpeg/*`, `Chatbot/*`, `KontrakMatriksRoleTest`, Vitest `features/halo-simpeg/**`) dan diuji QA §4 |
| Verifikasi "thread tidak ter-mix" (§7.8) hijau | Checklist §HX: HX1, HX2, HX4, HX6 ✅, HX3 [test-otomatis], HX5 ✅ sebagian; `TiketHaloSimpegTest::testThreadTidakTerMixDanTanpaDependensiNotifikasi`, `NotifikasiTidakKeTicketingTest` |
| Regresi Fase 4 (C-05/C-06) dan FAQ (G-10) hijau | Checklist §HZ [test-otomatis], CI #899 `main` `e4392507` hijau (`NotifikasiTidakKeTicketingTest`, `PushAndroidCutiTest`, push LKH/Konket, `FaqTest`, suite F-10) |
| QA Lapis 1 Fase 8 lolos, termasuk widget dan Chatbot Manager | §4 |
| Runbook cutover tercatat (T12b, berkas lampiran lama, kunci token chatbot, tiket NULL/anomali, tiket Open tanpa petugas aktif, Q-H19 + EXPLAIN) | `docs/migrasi/MAKE-069-rencana.md` T12b (MAKE-097); `README-deploy.md` §1m (kunci token) dan §1n (lembar hasil B6/B7/Q-H15/Q-H19/EXPLAIN, ambang ekspor, butir cutover) (MAKE-074); lembar hasil diisi saat gladi (§8) |
| Fixture `backend/tests/_support/HaloSimpeg/` §6.1b lengkap dan terverifikasi trait | `KontrakFixtureTiketTest`, `HaloSimpegFixtureTrait`, salinan FE `features/halo-simpeg/__tests__/fixtures/` (checklist HG2, CB3) |
| Laporan akhir (status per paket + SHA, temuan QA, butir yang ditunda ke DEPLOY-002) | Dokumen ini (§2, §4, §8) |
| Trello DEV-009, QASMTASK-111..118, QASMTASK-123, QASMTASK-124 + Notion E.1..E.6 disinkron final | ✅ 18-11-2026 sesudah sign-off (§9) |
| Sign-off Horii (penutup seluruh development) | ✅ 18-11-2026 (`main` `d8020fc3`) |

## 4. QA Lapis 1 Fase 8

Checklist `docs/fase8/Checklist_QA_Lapis1_Fase8.md` (hasil per butir + §T). Dijalankan reviewer CR di app demo lokal dengan DB demo,
captcha mock, driver integrasi `mock`, dan data sintetis berpenanda `[QA-F8 …]`; build lokal `ACTIVE_PHASE = 8` (+ `to`/`roles` item
`halo` untuk bagian B) tanpa commit, dibuang sesudah QA. Login akun demo atas izin Horii [#111] (AW17) dan [#112] (QA Fase 8,
diperluas untuk uji viewport sempit, emulasi browser). Bagian A: `67f3303c` (putaran 1, 17-11-2026), `d659acf6` (uji ulang T-02/T-03,
AW17), `e4392507` (sisa, 18-11-2026). Bagian B: `e4392507` (18-11-2026). Perbaikan bagian B diverifikasi di demo sebelum merge, lalu
masuk `main` (`4d06a8c6`, `8bc6c509`): verifikasi demo pada CR-142 @`f49f075e` (sebelum `a2f1e894`/`564ebaa5`), sisi BE CR-143
lewat test otomatis. Bukti tanpa NIP, token, kredensial, maupun isi pesan nyata; akun disebut dengan kode aktor
checklist (U1–U4, P1–P6, akun demo role N).

| Bagian | Hasil |
|---|---|
| GF gerbang umum | ✅ 3, ✅ sebagian 2 (GF3: angka gate penuh lokal tidak tercatat; GF4: kolom Keputusan §K belum diperbarui) |
| AG prasyarat chatbot | ✅ 4/4 |
| AW widget fungsional | ✅ 11, ✅ sebagian 6, [test-otomatis] 2 (AW1, AW2) |
| AV visual widget (PN8-8) | ✅ 11, ✅ sebagian 1 (AV9 reduced-motion dicek di CSS); AV2 sesudah CR-134 |
| AA Chatbot Manager | ✅ 10, ✅ sebagian 6, [test-otomatis] sebagian 1 (AA16); AA8/AA10 sesudah CR-134 paket 2 |
| AL log & privasi | ✅ 5/5 |
| AM matriks role chatbot | ✅ 8, [test-otomatis] 1 |
| ABB "bukan bug" chatbot | ✅ 23, ✅ sebagian 1 |
| HG gerbang helpdesk | ✅ 3, [test-otomatis] 3 |
| HP layar pemilik | ✅ 15, ✅ sebagian 7, [test-otomatis] 3, [test-otomatis] sebagian 1; HP3 sesudah CR-143 + CR-142, HP24 sesudah CR-142 |
| HT layar petugas | ✅ 12, ✅ sebagian 2 |
| HN tiket kategori NULL | ✅ 2, ✅ sebagian 3, [test-otomatis] sebagian 1 |
| HR penanda baca | ✅ 7, [test-otomatis] 1 |
| HS penyamaran nama | ✅ 3, ✅ sebagian 2 |
| HA admin master | ✅ 8, ✅ sebagian 4, [test-otomatis] 1 |
| HK aksi ⋮ & komponen | ✅ 4, [test-otomatis] 1; HK1 sesudah CR-142 |
| HB badge, lonceng, entri Notifikasi | ✅ 3, ✅ sebagian 7, [test-otomatis] 2 |
| HE ekspor rekap | ✅ 6, [test-otomatis] 3 |
| HU push (mock) | ✅ [mock-terbatas] 4, [test-otomatis] 1, — 1 (HU6, push real di luar QA) |
| HV visual AC-E08.7 (tanpa Figma, H8-9) | ✅ sebagian 4 (HV1–HV3 tidak dijalankan sebagai butir tersendiri; penilaian sekilas dari tangkapan layar QA) |
| HM matriks role helpdesk | ✅ 8, [test-otomatis] 1 |
| HX "thread tidak ter-mix" §7.8 | ✅ 4, ✅ sebagian 1, [test-otomatis] 1 |
| HZ regresi Fase 4 dan FAQ | [test-otomatis] 5, [test-otomatis] sebagian 1 |
| HBB "bukan bug" helpdesk | ✅ 17, ✅ sebagian 4, [test-otomatis] 2, — 1 (HBB23 gugur sesudah R10 langkah 2) |
| CA / CB butir otomatis | CA: ✅ 1, [test-otomatis] 24, [test-otomatis] sebagian 3, — 1 (AC-E10.30); CB: ✅ 1, [test-otomatis] 15, — 1 (CB11, CR-135) |
| HL rilis menu CR-135 | ✅ 4 (18-11-2026 sesudah sign-off, CR-135 `f5e730bb`; tidak dihitung dalam 300 butir) |
| **Total sign-off (tanpa HL dan E)** | **300 butir: ✅ 169, ✅ sebagian 50, [test-otomatis] 66, [test-otomatis] sebagian 7, [mock-terbatas] 4, — 4; ❌ 0** |

"✅ sebagian" berarti bagian yang dijalankan lolos dan bagian yang tidak dijalankan disebut di kolom Hasil (mis. 768 px dan mode
gelap, kasus tanpa unggulan, pembaca layar asli). Butir yang tidak diuji manual dan diterima lewat test otomatis: AW1, AW2, AA16 (sebagian),
HP14, HP20, HP22, HP23 (sebagian), HR1, HN5 (sebagian), HA7, HK5, HB6, HB7, HE2, HE5, HE9, HU5, HX3, HZ1–HZ6 (HZ5 sebagian), HBB6,
HBB14; butir gerbang AM9, HG2, HG3, HG5, HM9 (CI #899). Sisi BE T-B1 (HP3) dan `scroll-padding` T-B4 juga hanya lewat test otomatis.

**Temuan (§T checklist):**

| # | Temuan | Status |
|---|---|---|
| T-01 | [K] Avatar mini bot tidak rata atas balon (banding PN8-8 #5) | **Diperbaiki CR-134 paket 1** (di `main` per `67f3303c`, indikator mengetik ikut); AV2 ✅ |
| T-02 | Satu galat kategori QnA Dihapus tampil tiga kali di dialog Ubah QnA (AA8) | **Diperbaiki CR-134 paket 2** `d659acf6`; diuji ulang ✅ |
| T-03 | Dialog Impor Excel tidak membersihkan galat lama saat berkas berganti (AA10) | **Diperbaiki CR-134 paket 2** `d659acf6`; diuji ulang ✅ |
| T-B4 | Launcher Vanya menutupi sebagian tombol "Kirim" form balas (AC HP24 gagal) | **Diperbaiki CR-142** `8bc6c509` (ruang bawah halaman + `scroll-padding` fokus, WCAG 2.4.11); ruang bawah diverifikasi di demo pada CR-142 @`f49f075e` (desktop); `scroll-padding` (`a2f1e894`) dikunci test `564ebaa5` + CI #904, belum diverifikasi di demo; HP24 ✅ sebagian |
| T-B3 | Kolom Aksi ⋮ tabel daftar tiket terpotong saat sidebar terbuka (1536 px) | **Diperbaiki CR-142** `8bc6c509` (sel Aksi sticky kanan); diverifikasi di demo ✅ |
| T-B1 | Pesan sukses legacy "Berhasil menambahkan data halo simpeg." tidak ada (HP3) | **Diperbaiki CR-143** `4d06a8c6` (BE `message`) + **CR-142** `8bc6c509` (notice `role=status`, teks cadangan legacy); notice FE diverifikasi di demo dengan teks cadangan (BE demo belum CR-143); sisi BE `message` lewat test otomatis (CI #900) |
| T-B2 | `Cache-Control` gabungan pada unduhan lampiran dan endpoint lain yang memakai helper `tanpaCache` (HP13); tetap `no-store` | ✔ diperbaiki CR-147 (`e5185919`) + CR-151 (`efd7d37f`) sesudah sign-off (helper bersama `tanpaCache`); tidak menahan sign-off |

T-B4 dinilai menahan sign-off; T-B1 dan T-B3 non-blok, tetapi diperbaiki sebelum sign-off atas keputusan Horii 18-11-2026.

**Pengamatan (bukan temuan, dicatat saja):** HA5 spasi ganda di tengah judul kategori tidak dinormalkan (sesuai PRD §7.3a "unik
(trim, ci)"); HA6 petunjuk "minimal 2 karakter" di UI hanya placeholder, galat dari API; HP7 throttle tiket dicek sebelum validasi
(README), sehingga percobaan yang ditolak 422 ikut memakan kuota; HA10 Nonaktifkan petugas tanpa ConfirmDialog (sama dengan pola
master lain); HB8 lonceng topbar role 4 tampil berangka tanpa berpindah (desain F-08, Q7-29; redaksi butir checklist perlu
diselaraskan); AA14 teks kosong tabel Chatbot Manager tampil bersama banner galat.

Keamanan dan privasi: tiket pihak lain → 404 (bukan 403); lampiran hanya lewat E-04 (blob, `nosniff`, nama `lampiran-{id}-{id}`, path
tidak di JSON); unggahan baru ber-path acak di storage privat; isi pesan tiket di-escape (`pre-wrap`, tanpa `v-html`); respons
non-role-1 tanpa id akun petugas; payload push tanpa NIP/jabatan/satker/token; ekspor diaudit tanpa isi percakapan atau NIP penanya;
isi pesan chatbot tidak masuk `audit_logs` maupun log aplikasi (AL3, AL4, CR-133); `log_chatbot` tanpa kolom identitas;
`chatbot.kunciTokenFeedback` hanya di `.env` (nama kunci di `.env.example`).

## 5. Gate dan CI

Nomor CI dan hasil gate per paket di bawah = fakta reviewer 17–18-11-2026 (tidak seluruhnya tercatat di repo); SHA dicek dengan
`git`.

| SHA | Hasil |
|---|---|
| `e4392507` (`main`, seluruh kode Fase 8; SHA QA bagian B) | CI `quality-gate` #899 hijau |
| `d90d8a75` (`main`, MAKE-073) | CI `main` #898 hijau |
| `dd7cafe3` (`main`, CR-141) | CI `main` #882 hijau |
| `64d68377` (`main`, MAKE-072 + CR-137) | CI #880 hijau; gate penuh lokal @`2dd55db9` EXIT=0 (PHPUnit 4.367 test, Vitest 2.050 test), CI #878 hijau |
| MAKE-074 BE @`b3c4d950` (basis `947b94fd`) | Gate penuh `./check.sh` EXIT=0 (PHPUnit 4.396 test, Vitest 2.050 test), CI #886 hijau; perbaikan review CI #887 hijau |
| MAKE-094 (`4af0c188`) | Gate penuh sebelum merge: PHPUnit 4.253 test, Vitest 1.826 test, PHPStan dan CS bersih (progres 08) |
| MAKE-095 @`50d75d79` (`2a8f405d`) | `./check.sh frontend` lolos: 174 berkas / 1.909 test, ESLint, vue-tsc, build (progres 08) |
| CR-143 @`403d19c6` → `4d06a8c6` | CI #900 hijau (branch) |
| CR-142 @`564ebaa5` → `8bc6c509` | CI #904 hijau (branch) |
| `8bc6c509` (`main` akhir) | ✔ CI `quality-gate` `main` #905 hijau (18-11-2026) |
| `d8020fc3` (`main`, CR-144 laporan akhir, docs saja) | ✔ CI `quality-gate` `main` #906 hijau (18-11-2026) |

Angka gate penuh `./check.sh` lokal pada SHA QA (`e4392507`) tidak tercatat di hasil QA; bukti gate untuk SHA QA = CI #899.

## 6. Deviasi & keputusan

Register lengkap: PRD §5.2, §12 (Q8-01..Q8-15, K-1..K-11, H8-1..H8-16, PN8-x, butir A0/B0), Lampiran B (B-1..B-31, A1..A9 chatbot),
README modul, dan progres 08. Ringkas:

- **Ikut legacy [K] / [K dev]:**
  - mesin jawab chatbot TF-IDF port dev apa adanya (test pembanding 11 skenario);
  - penyamaran nama D7 (a) per layar ("Admin" di daftar bagi semua peninjau, nama di detail bagi role 1);
  - bawaan filter antrean petugas "belum dibaca" (B-25);
  - tiket berkategori NULL ikut legacy tanpa ubah data (H8-16 (a1)): hanya dilihat dan ditutup pemilik, balas → 422, tidak ikut sisi
    petugas maupun ekspor;
  - tiket ditutup tidak dibuka kembali (F8-08);
  - cakupan ekspor V1/V2 (a): pesan pemilik + pesan petugas pengekspor, nama/NIP pengirim baris tanpa penyamaran;
  - lampiran tiket lama tetap tersaji lewat alias publik `/berkas/` F-10 (H8-5 (a), risiko sadar R8-6, ADR-038), sedangkan aplikasi
    memakai E-04;
  - pesan sukses buat tiket legacy (CR-143).
- **[V2]:**
  - halaman master-detail `/halo-simpeg` (bukan drawer), baris tidak dapat diklik, aksi lewat ⋮ "Lihat detail"/"Tutup" (§6.3);
  - bawaan filter "Tiket saya" = semua (R9(1)), kolom + filter kategori di tab "Tiket saya";
  - popup login petugas diganti badge menu + panel "Perlu ditindak"; "Ingatkan Saya Kembali?" pindah ke form balas (F8-11, F8-12);
  - badge D8 (a) = distinct(belum dibaca ∪ `perlu_tindak`), bisa lebih besar dari angka lonceng (`total`);
  - pemilik selalu 403 untuk hapus tiket (F8-09, T2 (b)); petugas nonaktif → `peran=petugas` 200 kosong (F8-06); syarat petugas
    aktif pada penanda baca;
  - pemilik yang juga petugas kategori lain boleh membalas dan menutup tiketnya (B-19);
  - lampiran baru privat lewat E-04 (BP8-5), unggahan diperiksa isi + ekstensi;
  - batas §7.3a: throttle tiket 5/10 menit dan pesan 20/menit, `subject` 250, `msg` 5.000 code point, ekspor 10.000 baris dan
    5.000.000 karakter, sel dipotong 32.767 "… [dipotong]";
  - push per akun (`antreAkun`, CR-137) dengan payload terbatas F8-16 (H8-8 belum ada);
  - chatbot: status 1/2/10, token feedback HMAC per `id_log` + akun, throttle per kelompok, log tanpa identitas; tampilan widget
    menurut keputusan PN8-8 (avatar SVG, aksen `brand-tertiary`, launcher 64 px, tombol/chip disabled + indikator saat memuat);
  - teks info paginasi bersama Bahasa Indonesia (CR-141).
- **Inferensi [I] (diterima Horii):** penanda baca sisi lawan = 2 dan sisi pembalas pemilik = 1 (H8-10 tetap [I], diuji per skenario
  AC-E04.4); PK `officer_category.id` (DBV-028 Q10).
- **Cara kembali:** angka batas helpdesk dan chatbot ada di `Config\HaloSimpeg` dan `Config\Chatbot` (dapat ditimpa `.env`); ambang
  ekspor wajib diturunkan bila `memory_limit` produksi < 256M (README-deploy §1n). Deviasi perilaku [V2] lain tidak punya saklar;
  pengembaliannya butuh CR baru dengan keputusan Horii.
- **Di luar scope / ditunda:** C4 impor isi QnA dev dan K-5 (H8-13 (b), DEPLOY-002); integrasi chatbot → tiket (K-9); uji push real dan
  payload push lengkap (H8-8, PN8-1, DEPLOY-001.1).

## 7. Butir terbuka sebelum sign-off

| No | Butir | Status |
|---|---|---|
| 1 | Merge laporan ini + checklist terisi + progres 08 + baris status PRD ke `main` (CR-144, docs saja) | ✔ di `main` `d8020fc3` (CI #906 hijau) |
| 2 | Nomor dan hasil CI `main` pada `8bc6c509` (sesudah CR-143 + CR-142) dicatat di §5 | ✔ §5: CI `quality-gate` `main` #905 hijau |
| 3 | Kolom Keputusan §K checklist untuk butir ◻ yang sudah dijalankan sesuai usulan (K-01, K-04, K-05, K-07, K-10..K-19) diperbarui | ◻ sebagian (CR-187): ✔ K-01, K-11, K-13, K-14, K-17 (GF4) dan K-10 (E7, §8 butir 2); tersisa ◻ K-04, K-05, K-07, K-12, K-15, K-16, K-18, K-19 |
| 4 | Sign-off reviewer CR, lalu sign-off Horii (checklist §E E8) | ✔ Horii 18-11-2026 ("Sign-off fase 8"), `main` `d8020fc3`, sesudah laporan reviewer CR (CR-144) |

Tidak ada temuan QA ❌ yang terbuka. T-B2 dan kandidat CR lain (§9) tidak menahan sign-off; keduanya sudah di `main` (§9).

**Sign-off Fase 8: ✔ Horii 18-11-2026** pada `main` `d8020fc3` (CI `quality-gate` #906 hijau), penutup seluruh development
(PRD §11); dicatat CR-145. Tindak lanjut sesudah sign-off di §9.

## 8. Blocker deploy / go-live (bukan blocker sign-off Fase 8)

1. **Uji push real ke perangkat (H8-8, G8-7, DEPLOY-001.1).** MAKE-072 memakai payload terbatas F8-16; kunci `data` push dan penulis
   token Android v2 (PN8-1) masih pertanyaan cutover.
2. **Satker kepegawaian (H8-7).** Konfirmasi Biro SDM atas `id_satker_kepegawaian` di Web Config yang menentukan kandidat petugas;
   QA memakai nilai demo (HA6, HA7 bergantung H8-7). Butir cutover README-deploy §1n.
3. **Gladi impor T12b (MAKE-097).** Runbook `docs/migrasi/MAKE-069-rencana.md` T12b dan lembar hasil README-deploy §1n: B6, B7,
   Q-H15, Q-H19 + EXPLAIN daftar petugas (DBV-028 Q8 (a)). Bila pemicunya terpenuhi (Q-H19 > 5.000 tiket untuk satu petugas atau
   EXPLAIN > 100 ms), kartu DBV `last_msg_at` wajib di `main` sebelum go-live (Q8 (b)). Catat juga jumlah tiket NULL, irisan NULL +
   Open + sender yatim, berkas lampiran ber-path anomali, dan tiket Open di kategori tanpa petugas aktif.
4. **Ambang ekspor dan `memory_limit` (README-deploy §1n).** Ambang 10.000 baris / 5.000.000 karakter diukur dengan `memory_limit`
   ≥ 256M; bila produksi lebih kecil, ambang `halosimpeg.*` wajib diturunkan.
5. **Kunci `chatbot.kunciTokenFeedback` di produksi (README-deploy §1m).** Tanpa kunci ≥ 32 byte penilaian 👍/👎 mati dan log memuat
   peringatan.
6. **Lampiran tiket lama** `arsip/{nip}/inbox/…` ikut setelan Nginx `/berkas/` F-10 di server (B10-3, README-deploy §1l), risiko sadar
   H8-5 (a).
7. **Isi awal chatbot (PN8-6/K-5, C4, DEPLOY-002):** isi QnA dev dibawa lewat impor sekali jalan atau v2 mulai kosong; diputus Horii
   saat deploy.
8. **Rilis menu CR-135 (✔ di `main` `f5e730bb` 18-11-2026, izin Horii; §HL ✅):** `ACTIVE_PHASE` 5 → 8, pembersihan `rilis`, item `halo` diberi `to`/`roles`;
   kenaikan fase juga membuka menu Fase 6–7 yang masih tersembunyi (checklist §HL).

## 9. Setelah sign-off

- **CR-135** rilis menu + verifikasi §HL checklist (⛔ izin Horii, dicatat terpisah, tidak mengubah sign-off). ✔ di `main`
  `f5e730bb` 18-11-2026; §HL HL1–HL4 ✅ di demo (dicatat CR-146); deviasi [V2]: menu tanpa halaman (E-Kinerja/E-Talenta) disembunyikan.
- **Kandidat CR sesudah sign-off** (tidak menahan sign-off):
  - T-B2: helper `tanpaCache` membuang `Cache-Control` bawaan sebelum memasang `private, no-store`;
    ✔ di `main`: CR-147 (`e5185919`) + CR-151 (`efd7d37f`, modul lain);
  - teks Inggris tersisa di `shared/ui` dan pemilih kolom pegawai (Horii: ditunda sampai sesudah sign-off);
    ✔ di `main`: CR-150 (`2cfb0c58`) + CR-153 (`8eb2289b`, label ekspor dan placeholder cari riwayat);
  - test yang memastikan alur TB/IB/PMK tidak menulis `disiplin_ticket(_msg)` (belum ada, celah sejak Fase 4);
    ✔ di `main`: CR-149 (`7f5b1f04`);
  - validasi trim Unicode pada `msg` tiket (saran dari MAKE-072, di luar kontrak beku);
    ✔ di `main`: CR-148 (`e93b52cb`) + CR-152 (`4aa5dbbf`, sama untuk `subject` tiket).
- Sinkron Trello DEV-009, QASMTASK-111..118, QASMTASK-123, QASMTASK-124 dan Notion baris Fase 8 (E.1..E.6) → selesai.
  ✔ 18-11-2026: 11 kartu Done (butir "Lolos MTC" dan push real FCM tidak dicentang), komentar blocker deploy di DEPLOY-002 dan
  DEPLOY-001.1, ISSUE-001 diberi komentar realisasi prasyarat (penutupan menunggu keputusan Horii); Notion E.1..E.6 Selesai.
- Cabut izin [#9-WorkloadHaloSimpeg] dan [#10-WorkloadQAChatbot] ([#8-WorkloadChatbot] sudah selesai).
- Tutup lingkungan QA demo: `ACTIVE_PHASE` kembali 5 dan item `halo` tanpa patch lokal, `chatbot.kunciTokenFeedback` dihapus dari
  `.env` demo, data uji `[QA-F8 …]` dibersihkan saat reset demo. ✔ 18-11-2026 (kecuali data uji: menunggu reset demo).
- Bersih-bersih worktree, branch, dan DB scratch Fase 8 hanya dengan izin Horii per objek (pola Fase 7).
