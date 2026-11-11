# Laporan Akhir Fase 7 — Dashboard, Konten, Notifikasi (MAKE-088)

Acuan: PRD Fase 7 **v2.1** (`docs/fase7/PRD_Fase7_v2_Dashboard-Konten-Notifikasi.md`, §10 DoD, §11 perubahan v2.1, register
Q7-01..Q7-47 dan Q-D12..Q-D20, Lampiran A), PRD Fase 7 v1.0 (F-03/F-09), DBV-032
(`backend/docs/db-review/DBV-032-konten-interaksi-fase7.md`), progres `backend/docs/progress/07-Dashboard.md`, checklist
`docs/fase7/Checklist_QA_Lapis1_Fase7.md`. Disusun reviewer CR 11-11-2026 dari `main` `29ee1d34` (seluruh paket Fase 7 + perbaikan
temuan QA sudah di `main`).

> **Status:** **FASE 7 DONE — sign-off Horii 11-11-2026** (laporan ini masuk `main` bersama sign-off). F-01, F-02, F-04..F-08, F-11..F-13 + ISSUE-058 di `main`; DBV-032 disetujui DB Validator;
> regresi gate penuh lolos; QA Lapis 1 Fase 7 **lolos** — lima temuan (T-03, T-05, T-06, T-07, T-08) diperbaiki CR-117, CR-117
> lanjutan, CR-118, CR-119 dan diuji ulang sebelum sign-off. F-10 ditunda (PRD §1.2). F-02 eksekusi data nyata dan impor tier 14
> data nyata = cutover (G7-4), bukan syarat sign-off.

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
| Trello DEV-008 + QASMTASK-098..110 (kecuali 107) + Notion disinkron | Sesudah sign-off (§9) |
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
