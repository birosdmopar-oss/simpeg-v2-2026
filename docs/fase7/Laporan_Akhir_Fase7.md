# Laporan Akhir Fase 7 — Dashboard, Konten, Notifikasi (MAKE-088)

Acuan: PRD Fase 7 **v2.1** (`docs/fase7/PRD_Fase7_v2_Dashboard-Konten-Notifikasi.md`, §10 DoD, §11 perubahan v2.1, register
Q7-01..Q7-47 dan Q-D12..Q-D20, Lampiran A), PRD Fase 7 v1.0 (F-03/F-09), DBV-032
(`backend/docs/db-review/DBV-032-konten-interaksi-fase7.md`), progres `backend/docs/progress/07-Dashboard.md`, checklist
`docs/fase7/Checklist_QA_Lapis1_Fase7.md`. Disusun reviewer CR 11-11-2026 dari `main` `29ee1d34` (seluruh paket Fase 7 + perbaikan
temuan QA sudah di `main`).

> **Status:** **FASE 7 DONE — sign-off Horii 11-11-2026** (laporan ini masuk `main` bersama sign-off). F-01, F-02, F-04..F-08, F-11..F-13 + ISSUE-058 di `main`; DBV-032 disetujui DB Validator;
> regresi gate penuh lolos; QA Lapis 1 Fase 7 **lolos** — lima temuan (T-03, T-05, T-06, T-07, T-08) diperbaiki CR-117, CR-117
> lanjutan, CR-118, CR-119 dan diuji ulang sebelum sign-off. F-10 ditunda (PRD §1.2), lalu dibuka kembali 12-11-2026 (§10).
> F-02 eksekusi data nyata dan impor tier 14 data nyata = cutover (G7-4), bukan syarat sign-off.

## 1. Laporan

```
Status: F-01, F-02, F-04..F-08, F-11..F-13 + ISSUE-058 DONE di main; QA Lapis 1 Fase 7 LOLOS
        (217 butir: ✅ / [test-otomatis] / — beralasan; 0 ❌ terbuka; temuan T-03/05/06/07/08 diperbaiki & diuji ulang)
Branch/SHA/CI: main 29ee1d34 — CI Quality gate #778 hijau
               regresi gate penuh ./check.sh @ab1ff02d (build Fase 7 lengkap): 4.130 PHPUnit / 69.945 asersi (3 skip),
               1.684 Vitest, build, EXIT=0 (log wt/fase6b/gate-regresi-f7.log)
               gate penuh CR-119 @a143324d: 4.134 PHPUnit / 70.026 asersi (3 skip), FE lolos, EXIT=0
               Checklist terisi + laporan ini: docs saja (branch docs/fase7-qa-lapis1-hasil)
Berkas utama: §2
Test & validasi: §3 (DoD §10 → bukti), §4 (QA Lapis 1), §5 (regresi)
Deviasi [V2] & keputusan: §6
Blocker / pertanyaan terbuka: §7; blocker deploy/go-live: §8
```

## 2. Paket Fase 7 di `main`

Pelaksana (izin [#4-WorkloadParalelFase7]): Jalur A konten — Worker Fix Bug; Jalur B interaksi + skema — ReadOne; Jalur C
notifikasi, sortir inbox, impor — Handling Case; Jalur D beranda + integrasi — Worker Muse; DB Validator — WS-2; reviewer CR.
Setiap merge atas izin Horii.

| Task | Isi | Key | SHA di `main` |
|---|---|---|---|
| PRD | v2.0 sisa Modul F + ISSUE-058 (39 keputusan bawaan + 8 pilihan Horii) | CR-112 | `57914b45` |
| PRD | v2.1 — koreksi Q7-03/42/44, lonceng semua role, impor T14 masuk scope, register §11 | CR-114 | `b1f084bf` |
| ISSUE-058 | `GET api/v1/integrasi/log` + halaman Admin → Integrasi (deviasi K1 Fase 6) | MAKE-078 | `e1a3dd89` |
| F-01 | Skema 13 tabel konten & interaksi (PR #39), seed `jenis_dibo`, registry NIP | DBV-032 | `958b4c6b` |
| F-08 | Pusat Notifikasi (agregator baca-saja, lonceng semua role, Butuh Approval/Histori) | MAKE-085 | `477ae686` |
| F-08 | `tableExists` berprefix tanpa cache + test penjaga arsitektur | CR-113 | `32dc2d98` |
| F-02 | Sortir inbox legacy → tiket Halo Simpeg + manifest | MAKE-086 | `477ae686` |
| F-13 | Beranda admin/pegawai per role (M1, M2, lanjutan) | MAKE-087 | `477ae686`, `4deac666` |
| F-04, F-05, F-07, F-12 | Popup, banner, berita (+ kategori, persetujuan role 3, lampiran), DIBO (+ jenis) | MAKE-079..082 | `f2d5580a` |
| F-06 | Form digital (kelola, isi, respons, XLSX) + label target bersama | MAKE-083, CR-115 | `7a021f8a` |
| F-13 | M3 bagian A — konten Jalur A di beranda, gerbang `FASE_KONTEN` | MAKE-087 | `472d5a2b` |
| Impor | Impor legacy tier 14 (13 tabel) + `impor:berkas-konten` | MAKE-089 | `5afeb31e` |
| F-11 | Survei kepuasan layanan (SKL) pegawai + rekap role 1; halaman admin dalam shell | MAKE-084 | `614e9e5e` |
| F-13 | M3 bagian B — kartu ringkasan SKL beranda role 1 (build Fase 7 lengkap) | MAKE-087 | `ab1ff02d` |
| Temuan pra-QA | DIBO berjenis nonaktif disembunyikan dari pembaca non-admin (K1 checklist) | CR-116 | `61ff64a0` |
| QA | Checklist QA Lapis 1 Fase 7 | MAKE-088 | `875b8e5b` |
| Temuan QA T-06 + T-05 | Kelola Konten di-mount ulang per halaman (popup↔banner, kategori↔jenis DIBO) + kolom Tautan | CR-117 | `eab1b2f6` |
| Temuan QA T-03 | Antrean dialog deterministik (+ respons Form Digital tanpa data basi) | CR-118 | `4c70a80a` |
| Temuan QA T-08 | Gambar popup/banner diunduh sekali per path selama carousel/dialog hidup | CR-117 lanjutan | `d99f690e` |
| Temuan QA T-07 | Validasi target unit/satker aktif pada popup, banner, form digital | CR-119 | `29ee1d34` |

F-03 (statistik) dan F-09 (dokumen) sudah selesai di fasenya (PRD v1.0, MAKE-036/037) dan ikut regresi §5.

## 3. DoD §10 → bukti

| DoD PRD §10 | Bukti |
|---|---|
| F-01..F-13 (kecuali F-10) + ISSUE-058 di `main`, AC terpenuhi | §2; AC per task diuji test otomatis modul (`tests/database/Dashboard/*`, Konten, Interaksi, Notifikasi, Impor, Integrasi) dan QA §4 |
| DBV-032 disetujui DB Validator | PR #39, approve WS-2 (DB Validator), di `main` `958b4c6b`; audit `DBV-032-audit-konten-interaksi.sql` (QA B-A2) |
| Regresi gate penuh hijau (termasuk `NotifikasiTidakKeTicketingTest`, statistik F-03, dokumen F-09) | §5 |
| QA Lapis 1 Fase 7 lolos | §4 |
| Laporan akhir mencatat deviasi [V2] + cara rollback (Lampiran A) | §6 |
| Trello DEV-008 + QASMTASK-098..110 (kecuali 107) + Notion disinkron | Sesudah sign-off: ✔ 12-11-2026 (§9.1) |
| Sign-off Horii | ✅ 11-11-2026 |

## 4. QA Lapis 1 Fase 7

Checklist `docs/fase7/Checklist_QA_Lapis1_Fase7.md` (hasil per butir + §T). Dijalankan reviewer CR 11-11-2026 di app demo
lokal `main` `875b8e5b` (DB demo direset, data `seed_demo.php` → `qa_fase4_data.php` → `qa_fase7_{a,b,c,d}.php`, data
sintetis; `ACTIVE_PHASE` 7 lokal tanpa commit, GF1–GF2 di 5, dikembalikan di GF5). Butir API/DB bagian C, D, B-B, B-E
dijalankan paralel oleh agen workflow (tanpa temuan) lalu bagian tampilannya diuji reviewer. Uji ulang temuan di `main`
`4c70a80a` dan `29ee1d34`.

| Bagian | Hasil |
|---|---|
| GF gating fase | ✅ 5/5 (GF3: lonceng titik merah sesuai desain, T-01) |
| B-A skema DBV-032 | ✅ 4/4 + 1 [test-otomatis] (B-A2 catatan T-02) |
| A-A popup | ✅ 15/15 (A-A6 sesudah CR-117) |
| A-B banner | ✅ 7/7 + 2 [test-otomatis] (T-08 diperbaiki CR-117 lanjutan) |
| A-C berita | ✅ 13/13 |
| A-D berita role 3 & persetujuan (+ saklar Lampiran A) | ✅ 12/12 |
| A-E DIBO | ✅ 14/14 |
| A-F master kategori & jenis DIBO | ✅ 8/8 |
| A-G keamanan (path traversal, sanitasi, nama berkas, audit) | ✅ 10/10 (A-G2 sesudah CR-119) |
| B-B / B-C form digital | ✅ 21/21 |
| B-D / B-E SKL | ✅ 16/16 |
| B-G antrean dialog | ✅ 4/4 (B-G1 sesudah CR-118) |
| C-N Pusat Notifikasi | ✅ 10/10 + 1 [test-otomatis] |
| C-F sortir inbox / C-I impor tier 14 | ✅ 5/5, ✅ 9/9 |
| D-A..D-H beranda | ✅ 46, D-C6 — (data demo; logika dibuktikan test otomatis), D-G4 [test-otomatis]; D-F3 sesudah CR-118 |
| D-I integrasi (ISSUE-058) | ✅ 12/12 |
| **Total** | **217 butir: ✅ 211, [test-otomatis] 5, — 1 (beralasan), ❌ 0** |

**Temuan (§T checklist):**

| # | Temuan | Status |
|---|---|---|
| T-06 | Satu instans view dipakai bergantian popup↔banner dan kategori berita↔jenis DIBO: data basi, aksi ⋮ mengenai entitas yang salah (berat) | **Diperbaiki CR-117** (`:key` per halaman + test pindah route); diuji ulang ✅ |
| T-05 | Daftar Kelola Popup/Banner tanpa kolom Tautan (legacy menampilkan) | **Diperbaiki CR-117**; diuji ulang ✅ |
| T-03 | Urutan antrean dialog bergantung balapan network | **Diperbaiki CR-118** (pendaftaran sinkron sebelum fetch, batas aman 20 detik); diuji ulang ✅ |
| T-08 | Carousel banner mengunduh ulang gambar setiap slide (endpoint `no-store`) | **Diperbaiki CR-117 lanjutan** (cache object URL per path); diuji ulang: 2 request/30 detik ✅ |
| T-07 | Target popup/banner (dan form digital) menerima unit/satker nonaktif atau tidak ada | **Diperbaiki CR-119** (422 seperti berita); diuji ulang ✅ |
| T-04 | Lonceng memanggil `notifikasi/ringkasan` (403) selama wajib ganti password | Catatan (keputusan Horii) |
| T-01 | Lonceng titik merah, angka di aria-label | Sesuai desain redesign (keputusan Horii) |
| T-02 | Kueri audit DBV-032 memakai domain status legacy; di DB v2 dua baris WAJIB_0 bernilai 1 | Catatan dokumen; bukan bug |
| O-2..O-8 | Kosmetik/teks checklist/info (rel tautan berita, `allow_ap` di respons jenis DIBO, label KARIS/KARSU vs KARISKARSU, `dapat_dihapus` role 3, format tanggal/angka, teks checklist, log adapter mock F6) | Catatan; tanpa CR |

Keamanan: path traversal popup/DIBO/lampiran → 404 + peringatan log; berkas privat ber-nama acak (tidak di `public/`); sanitasi
berita membuang `<script>`, `<iframe>`, `<img>`, atribut `on*`/`style`, `javascript:`/`data:`; kritik/saran SKL, isi jawaban
form, dan isi notifikasi tidak masuk `audit_logs` maupun log berkas; rahasia `.env` dashboard tidak ada di repo (D-H1).

## 5. Regresi gate penuh

| SHA | Hasil |
|---|---|
| `ab1ff02d` (build Fase 7 lengkap) | `./check.sh` EXIT=0 — PHPStan level 5 tanpa galat, CS-Fixer bersih, PHPUnit 4.130 test / 69.945 asersi (3 skip; termasuk `NotifikasiTidakKeTicketingTest`, `TableExistsBerprefixTest`, statistik F-03, dokumen F-09), Vitest 162 berkas / 1.684 test, build |
| `a143324d` (CR-119, basis `4c70a80a`) | `./check.sh` EXIT=0 — PHPStan, CS-Fixer, PHPUnit 4.134 test / 70.026 asersi (3 skip), FE lolos; CI #775 hijau |
| CR-117 `eab1b2f6`, CR-118 `7103aa16`, CR-117 lanjutan `d99f690e` (FE saja, basis sudah gate penuh) | CI #770, #771, #776 hijau; `npm run check` gabungan CR-117 + CR-118 lokal 164 berkas / 1.695 test, build |
| `29ee1d34` (`main` akhir) | CI Quality gate #778 hijau |

## 6. Deviasi & keputusan

Register lengkap PRD §4.2 (Q7-01..Q7-47), §11 (perubahan v2.1, 20 butir) dan Q-D12..Q-D20. Ringkas:

- **Lampiran A — deviasi [V2] yang bisa dikembalikan tanpa ubah kode:**
  - Komposisi pegawai admin: donut + tile [V2] → `.env` `dashboard.tampilanKomposisi = angka` (11 tile urutan legacy; QA D-B2).
  - Kartu Tanda Jasa satker/unit: tahun acuan kementerian [V2] → `.env` `dashboard.tandaJasaIkutLegacy = true`.
  - Berita diubah role 3 langsung berlaku (= legacy, bawaan) → `Config\Konten::$beritaUbahRole3KembaliMenunggu = true` bila
    ingin kembali menunggu persetujuan (QA A-D11).
  - Editor berita teks sederhana + sanitasi server (Q7-10): kembali ke editor kaya tidak disarankan (celah XSS); butuh CR baru.
- **Ikut legacy [K]:** penargetan popup/banner/form (pegawai eksplisit tanpa filter status, diabaikan bila kementerian; §11-6);
  popup tampil setiap login, ditutup di gambar terakhir; lonceng di semua role 1–8 dengan angka per role (§11-5); alur
  persetujuan berita role 3; kelengkapan data beranda (port `kd_rpangkat_2`, Q-D13..Q-D17); pengecualian NIP dashboard per
  lingkup (Q7-41, Q-D12, Q-D18) dari `.env` rahasia server; urutan antrean dialog form → pengingat cuti → popup → SKL.
- **[V2] (lebih ketat dari legacy):** gambar/lampiran/berkas privat lewat endpoint terautentikasi dengan kunci acak; hapus
  permanen popup/banner dengan snapshot audit (§11-8); DIBO hapus lunak + bisa dipulihkan, pegawai hanya status 1 dengan jenis
  aktif (§11-12, CR-116); validasi target unit/satker aktif pada semua jalur tulis (CR-119); batas gambar `Config\Konten`
  (§11-7); `news.show_notif` diisi service, bukan trigger (§11-11); kolom legacy NULL-able tetap NULL-able, wajib di form (§11-13);
  koreksi NIP ikut tabel Fase 7 (`NipCascadeService::FASE` = 7, §11-14); gating `ACTIVE_PHASE` < 7 tanpa request (§11-16).
- **Ditunda / di luar scope:** F-10 Arsip Digital; histori PIC TB/IB/PMK `show_pl` (§11-18); angka tiket lonceng (NoOp sampai
  Fase 8, Q8-06); tiket Halo Simpeg buat/balas/tutup (Fase 8).

## 7. Butir terbuka sebelum sign-off

| No | Butir | Status |
|---|---|---|
| 1 | Merge checklist terisi + laporan ini ke `main` (docs saja) | Izin Horii 11-11-2026 (bersama sign-off) |

Tidak ada temuan QA atau pertanyaan ⛔ Fase 7 lain yang terbuka.

## 8. Blocker deploy / go-live (bukan blocker sign-off Fase 7)

1. **D1 dari salinan dev:** angka D1 Fase 7 (jumlah/ganda/yatim/sebaran) diambil dari simpegdev; diverifikasi ulang di
   produksi saat cutover (§11-1).
2. **Cutover data:** F-02 sortir inbox data nyata (G7-4) dan impor tier 14 + `impor:berkas-konten` data nyata (MAKE-089) dengan
   berkas keputusan; jalankan `DBV-032-audit-konten-interaksi.sql` di salinan legacy (baris WAJIB_0 = domain legacy, T-02).
3. **Konfigurasi server (`.env` rahasia, README-deploy §1k):** `dashboard.nipDisembunyikan`, `dashboard.penggunaLihatSemua`,
   `dashboard.nipDikecualikanUlangTahun` diisi operator dari legacy; tidak pernah di repo.
4. **Dampak CR-119 pada data impor:** popup/banner/form legacy yang targetnya memuat unit/satker yang sudah nonaktif tetap
   tersimpan apa adanya, tetapi akan ditolak (422) saat diedit sampai token itu dibuang dari isian.
5. **Aktivasi:** `ACTIVE_PHASE` dinaikkan ke 7 hanya saat rilis Fase 7 (menu, lonceng, dialog, konten beranda masih diam di 5).

## 9. Setelah sign-off

- Sinkron Trello DEV-008 + QASMTASK-098..110 (kecuali 107), tutup ISSUE-058; Notion baris Fase 7 → selesai.
- Cabut izin [#4-WorkloadParalelFase7] dan [#5-MonitorFlowHarness].
- Bersih-bersih worktree, branch, DB scratch, dan log gate Fase 7 + perapian `simpeg_v2_testing` di 3306 (izin Horii per objek,
  hanya sesudah sign-off).

### 9.1 Status tindak lanjut sign-off (13-11-2026)

Sumber: fakta reviewer 13-11-2026 (tidak tercatat di repo); dicek ulang ke reviewer sebelum dokumen ini di-merge.

| Butir §9 | Status |
|---|---|
| Sinkron Trello DEV-008 + QASMTASK-098..110 (kecuali 107) dan Notion baris Fase 7 | ✔ 12-11-2026 (izin Horii). ◻ Penutupan ISSUE-058 dicek ulang |
| Cabut izin [#4-WorkloadParalelFase7] dan [#5-MonitorFlowHarness] | ✔ 11-11-2026 |
| Bersih-bersih worktree, branch, DB scratch, log gate Fase 7 + perapian `simpeg_v2_testing` di 3306 | **Dilewati** atas keputusan Horii 12-11-2026: objek Fase 7 dibiarkan, `simpeg_v2_testing` tidak dirapikan. Penghapusan tetap butuh izin Horii per objek |

## 10. Addendum F-10 Arsip Digital (sesudah sign-off 11-11-2026)

Addendum ini **tidak** mengubah sign-off Fase 7 di atas (F-01, F-02, F-04..F-08, F-11..F-13 + ISSUE-058). Isinya hanya status
F-10 yang dibuka kembali sesudah sign-off. Disusun reviewer CR 13-11-2026 dari `main` `483c5892`. Branch yang belum di `main`
dibaca langsung dari `origin` dengan SHA yang disebut di bawah. Nomor CI, status izin, dan pelaksana per paket = fakta reviewer
13-11-2026 (tidak tercatat di repo); SHA branch dicek ulang tepat sebelum commit.

> **Status F-10:** **belum selesai.** Tahap 1 sebagian sudah di `main` (MAKE-090, MAKE-091), MAKE-093 belum, Tahap 2 baru dimulai,
> dan QA Lapis 1 F-10 belum dijalankan. **Sign-off F-10: ◻ menunggu Horii.**

### 10.1 Latar

- **Ditunda.** F-10 dikecualikan dari Fase 7 (PRD Fase 7 §1.2; checklist `docs/fase7/Checklist_QA_Lapis1_Fase7.md:16`; §6 di
  atas "Ditunda / di luar scope"). PRD F-10 mencatat penundaan sejak 20-10-2026 (`docs/fase7/PRD_Fase7_F10_Arsip-Digital.md:7`).
- **Dibuka kembali Horii 12-11-2026**, sesudah sign-off Fase 7 11-11-2026, dengan cakupan bertahap: Tahap 1 untuk melihat, membuka,
  menandai berkas hilang, dan menampilkan folder; Tahap 2 untuk aksi legacy (F10-04).
- **Keputusan Horii 12-11-2026 #1–#18** (F10-01..F10-18, 🔒 final, PRD §3.1 `:93-115`). Intinya:
  - F-10 memakai penyimpanan lokal v2 (MAKE-069 opsi A), tanpa server arsip terpisah. J3.06 gugur (F10-01, F10-02) [V2].
  - Halaman baru "Arsip" ada di modul Kepegawaian. Tombol lama berganti label menjadi "Arsip Data Umum" (F10-06, F10-15).
  - Role 4/5 hanya melihat, role 8 ditolak [K] (F10-07).
  - Aksi Tahap 2 ikut legacy dengan pola keamanan v2 (F10-09). Tidak ada unggah dari halaman Arsip [K] (F10-11).
  - Pembukaan daftar arsip pegawai lain dicatat [V2] (F10-17).
  - Berkas dibuka lewat dua jalur (F10-18, §10.5).
- **Plan B (PRD §14).** Atas permintaan Horii, PRD memuat versi penyesuaian sebagai pembanding. Pilihan Horii 12-11-2026: PB-1 = B,
  PB-2 = B, PB-3 = A, PB-4 = B, PB-5 = A, PB-6 = A, PB-7 = A (PRD §14.1 `:665-682`). PB-1 dan PB-2 digabung sesuai klarifikasi
  Horii "PB-2 + audit PB-1": berkas legacy tetap publik; berkas unggahan v2 privat dengan audit + throttle. Bawaan teknis
  F10-19..F10-38 sudah dikonfirmasi Horii (QF10-6 ✔), dan kode 38 ikut legacy (QF10-8 ✔).

### 10.2 PRD F-10

| Versi | Isi | Key | SHA | Status |
|---|---|---|---|---|
| v1.3 | PRD disetujui Horii 12-11-2026 ("Setuju, boleh merge PRD F-10"): keputusan F10-01..F10-38, Plan B, Lampiran A | CR-120 | `241ef9e5` | Di `main` (G10-1 ✔) |
| v1.4 | Angka D1 salinan dev (§10, G10-3 ✔, B10-1, §8.1, QF10-1, QF10-5 ✔) + koreksi F10-37 (6): 403 `password_change_required` pada unduhan blob menampilkan pesan, tidak memicu handler interceptor (sesuai MAKE-091) | CR-122 | `448b302c` (branch `docs/cr-122-prd-f10-v14`) | **Belum di `main`**: CI manual #793 berjalan; izin merge sudah ada |

Rujukan baris di addendum ini memakai PRD v1.3 di `main`, kecuali yang ditandai "v1.4". Pada bagian yang dirujuk, nomor
barisnya sama di kedua versi.

### 10.3 Paket F-10

Pelaksana Tahap 1 (izin [#7-WorkloadF10], disetujui Horii 12-11-2026): MAKE-090 — WS-2; MAKE-091 — Worker Fix Bug; MAKE-093 —
Handling Case. Tahap 2 dimulai 13-11-2026 di bawah izin yang sama lewat perubahan anggota yang disetujui Horii 13-11-2026: MAKE-092
(BE) — WS-2; MAKE-096 (FE, key baru) — Worker Fix Bug; urutan merge BE lebih dulu. Skema database tidak berubah, jadi tanpa DBV
(PRD §8.4).

| Paket | Isi | Branch / SHA | CI | Gate penuh | Status |
|---|---|---|---|---|---|
| MAKE-090 BE Tahap 1 | Folder + daftar berkas (`akses_berkas`, tanda berkas hilang), endpoint privat `buka` + audit `arsip_unduh` + throttle, catatan pembukaan daftar (F10-17), `Config\Arsip` (saklar F10-24 + regex), rute dev, kontrak README | `fa1f6686` | #787 hijau | `./check.sh` @`fa1f6686` EXIT=0 — PHPUnit 4.176 test / 70.819 asersi (3 skip), Vitest 1.701 | **Di `main`** |
| MAKE-091 FE Tahap 1 | Halaman Arsip (route, menu, pemilih pegawai, folder, tabel, ⋮ Buka lewat URL publik atau popup + blob), tautan Detail Pegawai, label "Arsip Data Umum" | merge `483c5892` (branch `f10/make-091-arsip-fe` @`80c8c410`) | #790 hijau | FE saja di atas basis yang sudah lolos gate penuh: `npm run check` lolos (Vitest 170 berkas / 1.826 test) | **Di `main`** |
| MAKE-093 Penyajian berkas legacy | `README-deploy.md` §1 (contoh server) dan §1l (Nginx `/berkas/` dua pola legacy, hak baca, tabel hasil verifikasi G10-4), ADR-038, koreksi dokumen usang §7.5 + `PathArsipLegacyTerbacaTest`, `ReadmeBerkasNginxTest` (regex + CSP README §1l vs `Config\Arsip`) | `f10/make-093-penyajian-berkas` @`29ca410c` | #791 hijau | Sedang diulang (Handling Case) | **Belum di `main`** |
| MAKE-092 BE Tahap 2 | Ubah Nama, Hubungkan, Hapus arsip yatim (`AksiArsip`), ekspor `ArsipService::ekspor()` lewat `EksporTabel` (tanpa kelas `EksporArsip` seperti usulan PRD §8.3), `GET …/arsip/ekspor`, `GET …/arsip/opsi-hubungkan`, filter `belum_terhubung`, kunci aksi F10-31/F10-38, kontrak README | `f10/make-092-arsip-aksi-be` @`c49d5a42` (satu commit di atas `483c5892`) | ◻ belum dicek | — | Berjalan, belum di `main` |
| MAKE-096 FE Tahap 2 | Dialog Edit/Hubungkan, Hapus, ekspor, filter jenis + "Belum terhubung" di halaman Arsip | `f10/make-096-arsip-aksi-fe` @`149b816a` (satu commit di atas `483c5892`) | ◻ belum dicek | — | Berjalan, belum di `main` |

Catatan: PRD §13 menulis MAKE-092 sebagai satu paket "BE+FE Tahap 2" (`:654`). Dalam pelaksanaan, bagian FE dipisah menjadi key
baru **MAKE-096** (perubahan anggota izin #7 disetujui Horii 13-11-2026). ✔ Pemisahan ini tercatat di PRD F-10 v1.5 §13 (CR-123).

### 10.4 D1 F-10 (ringkas)

Sumber: PRD v1.4 §10 baris "Angka D1" (v1.4 `:533`) dan B10-1 (v1.4 `:46`). Angka diambil dari **salinan dev legacy (simpegdev)**,
bukan produksi, dan diverifikasi ulang di produksi saat cutover (G10-3). Folder fisik tidak diukur.

| Butir | Angka |
|---|---|
| Baris `document_attachment` / pegawai | 157.910 baris / 4.852 pegawai; arsip tanpa pegawai 0 |
| Belum terhubung (kode 0) | 32.665 |
| `id_riwayat` NULL / `path` kosong atau NULL | 2 (folder "Tanpa Jenis", QF10-5 ✔) / 2 ("Tidak dapat dibuka") |
| Kode | Tidak ada kode di luar `jenis_rwy`; kode 42 tidak ada di dev; kode 37/38 selalu tanpa `id_entri` |
| Pola path | Semua `arsip/…`; **0** `assets/upload/…` (QF10-1; alias tetap dipasang demi paritas legacy) |
| Ekstensi | ±99,3% bisa dipratinjau (pdf, png, jpg, jpeg, gif); sisanya diunduh |
| Menggantung (F10-16) | 7.891 (riwayat tidak ada 2.902, riwayat status 10 sebanyak 88, status lain 4.901) |
| Maksimum per pegawai | 576 baris (221 pegawai > 100), jadi paling banyak 576 pemeriksaan `ada()` per permintaan folder (v1.4 §8.1) |
| Kapasitas (dari `file_size`, satuan KB) | ±172 GB; muatan awal 2020 ±97 GB, 2021 ±34 GB, 2022–2025 ±7–12 GB per tahun (B10-1) |

### 10.5 Deviasi sadar

| Butir | Label | Isi | Cara kembali / catatan |
|---|---|---|---|
| F10-18 (a) — URL publik berkas legacy | [K] (membalik pola privat v2) | Berkas legacy (`arsip/…`, `assets/upload/…`) dibuka lewat URL publik Nginx `/berkas/` **tanpa** cek izin per berkas dan tanpa audit unduh. Horii memilihnya sesudah diberi tahu risikonya (URL bisa ditebak, KTP/NPWP legacy terbuka bagi yang tahu URL), PRD `:115`; risiko R1–R7, R9, R10 di PRD §7.6 `:355-369` | Saklar `arsip.penyajianBerkasLegacy` = `privat` (F10-24, PRD `:127`; Lampiran A `:794`). Alasan dan opsi lanjutan (alamat terpisah, URL bertanda tangan) dicatat di **ADR-038** (`docs/adr/ADR-038-penyajian-berkas-arsip-legacy-url-publik.md`, branch MAKE-093; status "Diusulkan 12-11-2026", belum di `main`) |
| F10-18 (b) + F10-08 — berkas unggahan v2 | [V2] | Berkas unggahan v2 (termasuk lampiran layanan 42–50 dan surat cuti ber-TTE kode 45) privat lewat `GET pegawai/{nip}/arsip/berkas/{id}/buka`, dengan audit + throttle pola F-09. URL publik `/berkas/YYYY/MM/<kunci>` → 404 | Pola privat Fase 3 dipertahankan |
| PB-4 = B (F10-31, F10-38) | [V2] | Role 2/6/7 tidak bisa meng-Hubungkan arsip ke riwayat Disetujui (403 seperti B-18). Ubah Nama kode 37 hanya untuk role 1. Akibatnya, pegawai praktis hanya bisa meng-Hubungkan ke kode 38 (PRD `:134`; §14.3 PB-4 `:725-733`) | Plan A (tanpa kunci) = legacy; perlu keputusan Horii baru |
| F10-37 (6) — 403 `password_change_required` pada blob | [V2], koreksi v1.4 | FE menampilkan pesan server, tidak memicu handler ganti password (interceptor membaca `code` saat body masih Blob) | Perbaikan interceptor untuk semua unduhan blob = CR terpisah di luar F-10. ◻ PRD v1.4 §9.3 `:521` belum diselaraskan (masih menulis 403 `password_change_required` → handler ganti password) |

### 10.6 Butir tersisa sebelum sign-off F-10

| No | Butir | Status |
|---|---|---|
| 1 | Gate penuh MAKE-093 lolos → review → merge ke `main` (izin Horii) | ◻ Gate sedang diulang |
| 2 | PRD v1.4 (CR-122) masuk `main` | ◻ Menunggu CI manual #793 (izin merge sudah ada). §9.3 `:521` v1.4 belum diselaraskan dengan F10-37 (6) `:140`: amandemen CR-122 sebelum merge atau CR berikutnya (◻ reviewer) |
| 3 | Tahap 2: MAKE-092 (BE) dan MAKE-096 (FE) ke `main` dengan AC2-01..AC2-13 (PRD `:239-254`), lalu regresi gate penuh | ◻ Berjalan |
| 4 | Izin Tahap 2: perubahan anggota [#7-WorkloadF10] untuk MAKE-092/MAKE-096 | ✔ Horii 13-11-2026 (fakta reviewer) |
| 5 | Pencatatan pemisahan MAKE-096 (FE) dari MAKE-092 (BE) | ✔ PRD F-10 v1.5 §13 (CR-123) |
| 6 | Kontrak README Kepegawaian untuk endpoint Tahap 2 (DoD PRD §11.4 `:601`); perubahannya ada di branch MAKE-092 dan ikut merge | ◻ Ikut butir 3 |
| 7 | **G10-4**: setelan Nginx `/berkas/` di server dev terpasang dan terverifikasi (`curl -I`, kunci v2 → 404, hak baca user Nginx, uji Chrome/Edge/Firefox untuk kedua jalur), hasil dicatat di tabel verifikasi README-deploy §1l (PRD `:45`) | ◻ Operator/Horii. Menurut PRD §7.4 `:342` dan §11.3 `:575` QA Lapis 1 dijalankan di server dev dengan Nginx, bukan rute dev, jadi G10-4 menahan seluruh QA Lapis 1 F-10; bila reviewer/Horii mengizinkan butir non-[S] di lokal (checklist K-11), G10-4 hanya menahan bagian N/R/GF4 |
| 8 | Checklist `docs/fase7/Checklist_QA_Lapis1_F10.md` masuk `main` (CR-123) dan disepakati reviewer, termasuk butir ◻ §K | ◻ |
| 9 | Data uji sintetis + berkas sintetis + akun per role di lingkungan QA (checklist K-03), tanpa NIP nyata | ◻ Prasyarat QA |
| 10 | QA Lapis 1 F-10 dengan checklist di atas (PRD §11.3 `:574-597`; urutan PRD §13 `:657`) | ◻ Belum dijalankan |
| 11 | ADR-038 masuk `main` bersama MAKE-093; laporan akhir F-10 (deviasi + cara kembali, Lampiran A) | ◻ |
| 12 | Sinkron Trello QASMTASK-107 (+ butir explorer QASMTASK-110) dan Notion (PRD §11.4 `:599-608`) | ◻ Sesudah sign-off |
| 13 | **Sign-off F-10** | ◻ **menunggu Horii** |

MTC-046 (banyak lampiran terindeks, satu berkas hilang ditandai) masuk fase Manual Testing tersendiri dan tidak menahan sign-off
(PRD `:597`).

**Tindak lanjut di luar F-10 / sesudah sign-off F-10** (tidak menahan sign-off):

| No | Butir | Status |
|---|---|---|
| L1 | QF10-2: hitungan manifest per `tabel.kolom` (berkas kolom lain di subtree `arsip/` dan `assets/upload/`) dilaporkan ke Horii sebelum rilis; pertanyaan mempersempit alias hanya bila angkanya menunjukkan berkas kolom lain (PRD `:636`, R6 `:365`) | ◻ |
| L2 | [I] kolom `url` yang mungkin ikut terkirim di respons lampiran B-18: dicek di salinan dev; bila benar, CR terpisah menambah `url` ke `KOLOM_INTERNAL` (PRD `:72`, `:350`) | ◻ |
| L3 | CR interceptor agar 403 `password_change_required` pada unduhan blob memicu handler ganti password (F10-37 (6) v1.4 `:140`) | ◻ CR terpisah |
| L4 | Cabut izin [#7-WorkloadF10] (berakhir saat F-10 sign-off) dan bersih-bersih worktree/DB scratch F-10 (izin Horii per objek, pola §9) | ◻ Sesudah sign-off |

### 10.7 Blocker deploy / cutover F-10 (bukan syarat sign-off, PRD §11.4)

1. **B10-1 kapasitas disk** (F10-03, Trello DEPLOY-002). Butir yang harus ada: penanggung jawab disk, kapasitas + pemantauan, backup
   folder berkas, dan retensi berkas yatim. D1 salinan dev menunjukkan ±172 GB berkas yang disalin MAKE-069, dengan pertumbuhan
   ±7–12 GB per tahun. Akar berkas tidak boleh berisi symlink; disk yang dipindah di-mount langsung ke path akar (PRD `:46`).
2. **B10-2 QR surat cuti ber-TTE legacy** (F10-14) masih menunjuk server arsip lama. Pilihannya belum diputus: URL lama read-only,
   atau migrasi/redirect QR ke v2. Butir ini menahan pematian server arsip legacy (PRD `:47`).
3. **B10-3 Nginx `/berkas/` di server produksi** (dua pola legacy) + README-deploy §1l. Butir ini menahan rilis F-10 ke produksi (PRD
   `:48`).

**Sign-off F-10: ◻ menunggu Horii.**
