# PRD WS-2 — Pegawai Inti, Organisasi & Alur Khusus (Fase 3, Modul B)

| | |
|---|---|
| Status | **DISETUJUI 07-10-2026** — turunan dari usulan pembagian Fase 3 (`fase3_pembagian_workstream.md`); keputusan user dicatat di §9 |
| Pelaksana | Qoder-2 |
| Estimasi | ≈24 hari-agen (relatif, bukan tanggal kalender) |
| Cakupan | Build saja (BE + FE + test yang diwajibkan DoD + `check.sh`). QA Lapis 1, review independen, dan sesi QA di luar cakupan |
| Basis & alur merge | Branch sendiri dari `main`; masuk `main` **per milestone** sebagai CR-only setelah review CR; gate penuh sekali sebelum push (AGENTS.md §3). Tidak ada branch integrasi/trunk |
| Key review | CR-047 (S0-B), CR-053..CR-058 (milestone WS-2) — lihat §7.1 |
| Mitra | WS-1 (Qoder-1) — lihat `PRD_WS1.md` |
| Briefing Sprint 0 | `docs/fase3/BRIEFING_S0B_Qoder2_frontend.md` |
| Acuan Fase 3 | Kontrak task `docs/fase3/03-Kepegawaian.md`; hak akses `docs/fase3/MATRIKS_ROLE_MODUL_B.md`; library PDF `docs/adr/ADR-033-library-pdf-tcpdf.md` |

---

## 0. Prasyarat & basis build

| # | Prasyarat | Status 07-10-2026 |
|---|---|---|
| P-1 | Fase 2 sign-off | **Selesai 07-10-2026** |
| P-2 | Skema B-01/B-02 (PR #20, DBV-012/013), G-02 (PR #17/#18), G-03 (PR #21), G-09 (PR #19) di `main` | **Selesai** — build berangkat dari `main`, bukan dari draf |
| P-3 | CR-044 — CI GitHub Actions | Dikerjakan sesi utama paralel dengan Sprint 0 |
| P-4 | CR-045 — PHPUnit cepat + base case test DB baru (migrate sekali + transaksi per test) | Dikerjakan sesi utama paralel. Fixture/test Fase 3 **wajib** memakai base case ini begitu masuk `main` |
| P-5 | DBV-019 — FK G-02 ↔ pegawai/riwayat (PR #23) | Dikerjakan sesi utama paralel. Fixture/test Fase 3 **wajib** mematuhi FK ini (baris master unit/satker/jabatan disediakan fixture) |

Alur: setiap WS bekerja di branch sendiri dari `main` dan rebase ke `main` saat paket milestone lain sudah masuk. Serah-terima antar-WS terjadi **lewat `main`**, bukan lewat branch integrasi.

---

## 1. Latar belakang & tujuan

Di luar pola riwayat standar, Modul B punya data inti pegawai dan alur dengan mesin status sendiri. WS-2 memegang fondasi yang dipakai bersama (lingkup akses, lampiran, fondasi frontend), data inti pegawai, organisasi, serta alur non-standar.

**Tujuan**
1. Fondasi bersama siap lebih dulu: PegawaiScope nyata (h3) dan Lampiran B-18 nyata (h5) untuk WS-1.
2. Koreksi NIP aman: cascade seluruh rujukan NIP Fase 3 dalam satu transaksi, rollback penuh.
3. Biodata dua jalur (langsung vs approval) dengan mesin `flag_update` sendiri.
4. Jabatan/Mutasi, Struktur Organisasi, Konket, LKH, dan halaman pegawai (daftar, detail, pencarian, export, DRH) tersambung API.

**Bukan tujuan**
- RiwayatEngine, SnapshotSync, dan jenis riwayat karier (milik WS-1).
- Antrean Butuh Approval (Modul F), `user_lokasi_presensi`, `aa_lkh`, adapter penyimpanan remote (Fase 6).

---

## 2. Pengguna & hak akses

| Role | Kebutuhan |
|---|---|
| 1 (admin) | Ubah biodata langsung, approve/reject biodata, tambah/hapus pegawai, koreksi NIP |
| 2/3/6/7 | Ubah biodata lewat approval (`flag_update`) |
| 3 | Admin terbatas lingkup unit/satker (`validateAccess_AP` atas `pegawai_mutasi_jabatan`) |
| 4/5/8 | Akses sesuai matriks; role 8 tanpa panel filter pencarian |
| UL_PEGAWAI | Hanya data NIP sendiri; mengajukan Konket dan LKH |
| Atasan (`nip_atasan`) | Approver LKH (ikut legacy) |

Hak akses mengikuti **Matriks v2** (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`; keputusan #4), termasuk Jabatan tambah/hapus sesuai Matriks, **kecuali approver LKH = atasan langsung** (ikut legacy). Izin disimpan sebagai data di Definisi per jenis (Jabatan) atau di konfigurasi service masing-masing alur non-engine.

---

## 3. Kebutuhan fungsional

### 3.1 Sprint 0 — fondasi frontend (S0-B, CR-047, ±h0–1,5)
Rincian siap-serah: `docs/fase3/BRIEFING_S0B_Qoder2_frontend.md`.
1. Port **per berkas** dari commit `3420e1a` (branch `origin/feat/frontend-ui-redesign`) ke branch di atas `main`: `shared/layouts/*` dan `features/kepegawaian/**`; AppShell dipensiunkan, 6 pembungkus halaman dipindah. Merge langsung dilarang (3 commit bertrailer terlarang 9dd8b1e/265aba1/ad2fc91, banyak konflik).
2. Pecah `riwayat.config.ts`, `RIWAYAT_MENUS`, `riwayat.spec.ts` menjadi `riwayat/jenis/<key>.ts` dengan registry `import.meta.glob`; `DetailPegawaiPage` merender tab dari registry. **Tab Konket dan Karpeg/Karis tidak di-port** (keputusan #5).
3. `routes.pegawai.ts` (WS-2) dan `routes.riwayat.ts` (WS-1), diimpor sekali dari `router/index.ts`. Daftarkan semua menu Fase 3 di `nav.config`: Daftar Pegawai, Struktur, Konket, Usulan Karpeg/Karis, Verifikasi LKH — plus semua menu yang sudah ada di `main` (termasuk Web Config dan Lokasi Presensi dari PR #19/#21).
4. Samakan `types.ts` dengan kolom DDL PR #20.
5. `StatusBadge` dan `ApprovalDialog` di shared (alasan wajib saat tolak, slot diff untuk B-04). Item ⋮ "Setujui"/"Tolak" di grup aksi lain, sebelum "Hapus".
6. `useCascadeOptions` dan `masterService.options` di-re-export lewat `src/shared` (tidak dipindah).
7. Data contoh tidak masuk `main` sebelum tersambung API: berkas `*.mock.ts` tidak dipakai runtime, dan menu Fase 3 disembunyikan lewat `ACTIVE_PHASE` sampai B-20 penutup.

### 3.2 Task berurutan
| # | Task | Isi | Est | ±Hari |
|---|---|---|---|---|
| 2 | **PegawaiScope nyata** → serah ke WS-1 | UL_PEGAWAI hanya NIP sendiri; 4/5/8 sesuai matriks; role 3 = port `validateAccess_AP` atas pmj (satker, atau unit bila admin tanpa satker); aturan lingkup unit destinasi 21 / unit lain 7 ikut legacy (keputusan #10); cast VARCHAR→INT sampai DBV-009 | 1,5 | 1,5–3 |
| 3 | **B-18 Lampiran** → serah ke WS-1 | `LocalStorageAdapter`; `document_attachment(NIP, id_riwayat, id_entri)` dengan kode dari `jenis_rwy`; batas ukuran/ekstensi **per jenis 1/2/5 MB ikut legacy** dari Definisi (keputusan #6); simpan/hapus di transaksi pemanggil (kompensasi berkas saat rollback); hapus keras + audit (DBV #9); helper kolom berkas; endpoint + `ArsipTable` | 2 | 3–5 |
| 4 | **B-06 Koreksi NIP** (+ butir B-21 cascade) | `NipCascadeService` 1 transaksi ikut `update_nip` legacy (keputusan #10): salin `pegawai` → arahkan ulang `TabelAnakNip::sampaiFase(3)` + `jabatan_koordinasi.nip` (registry CR-036 diperbarui agar memuatnya) + `pengguna` + `faq_rate` → hapus baris lama; cek keunikan; rollback penuh; role 1; dialog FE | 2 | 5–7 |
| 5 | **B-03 Biodata dua jalur** + backend dasar B-20 | Role 1 langsung ke `pegawai`; role 2/3/6/7 → `flag_update=1` + satu `pegawai_hist` pending per NIP; `pegawai_foto`; role 3 di luar lingkup → 403. GET list/detail + descriptor tab; DataUmumForm tersambung API | 2,5 | 7–9,5 |
| 6 | B-04 Approval biodata | Approve: flag 3, salin ke `pegawai`, sinkron `pengguna.email`. Reject: flag 2, alasan wajib, `pegawai` tidak berubah (bug legacy `L_employee.php:1098` tidak ditiru). Cek lingkup; dialog diff | 1,5 | 9,5–11 |
| 7 | B-05 Tambah & Hapus pegawai | `AccountProvisioner` (UserLevel dari `id_jenis_pegawai`, jaga regresi A-09); hapus = status 10 + akun nonaktif; status 1→2 menonaktifkan akun; `pegawai_sisa_cuti` dicatat untuk C-01 | 1,5 | 11–12,5 |
| 8 | **B-07 Jabatan/Mutasi + Plt/Plh** | `Definisi/Jabatan.php` di atas **engine-v1** + aturan snapshot pmj (`jenis_jabatan≠3`, seri ≥); dropdown & salinan nama G-02 (termasuk `jabatan_koordinasi`/`rumpun_jabatan`); Plt/Plh tanpa approval; izin sesuai Matriks v2; tab FE. Hanya menulis Definisi + controller; hook baru diajukan ke WS-1 | 3 | 12,5–15,5 |
| 9 | B-19 Struktur Organisasi | Tree unit→satker→jabatan→pegawai (+Plt/Plh); `so/full` = sesi login; `jabatan/so` = UL_ALL; halaman tersambung | 2 | 15,5–17,5 |
| 10 | B-13 Konket *(katup)* | Lihat §3.3 | 1,5 | 17,5–19 |
| 11 | B-12b LKH | Approver atasan langsung (`nip_atasan`, ikut legacy — pengecualian keputusan #4); status 3 = Revisi; tanggal minimum 4 hari kerja (HariLiburService); unit/satker/atasan dari pmj; PDF dengan **TCPDF** (keputusan #7, ADR-033); `aa_lkh` ditunda (DBV #13) | 2,5 | 19–21,5 |
| 12 | B-20 penutup | Advanced search (role 1,3,4,5; panel filter disembunyikan untuk 8), export, Cetak DRH PDF (1,2,3,4,5; TCPDF), integrasi semua tab; naikkan `ACTIVE_PHASE` ke 3 | 2,5 | 21,5–24 |

### 3.3 Halaman Usulan Konket (keputusan #5, 05-10)
- Service tipis **di luar engine**: `absen_ijin` status W/V/X/10, `affect_tukin` (dependency Fase 5), `hari_libur`.
- **Halaman sendiri**, bukan tab di Detail Pegawai.
- **Layout ikut legacy** (`Konket.php`): index untuk UL_PEGAWAI, daftar/antrian `list_ad`, alur proses; izin per aksi mengikuti Matriks v2 (keputusan #4).
- **Style ikut redesign**: token + komponen shared, aksi baris lewat ⋮.
- Catatan: `docs/fase3/03-Kepegawaian.md` menyebut B-13 "Kondisi Kerja: Dinas Luar/WFH/WFO"; build mengikuti arti legacy (`absen_ijin`), deviasi dicatat di progres.

### 3.4 Library PDF (keputusan #7)
- **TCPDF** (mesin yang dipakai legacy, `PdfCreator extends TCPDF`), ditambahkan ke `composer.json` oleh WS-2 dengan **ADR-033** (`docs/adr/ADR-033-library-pdf-tcpdf.md`) yang mencatat lisensi GPL-2.0 dan dasar pemakaian internal. ADR disetujui sebelum ±h19.
- Cadangan: **dompdf** bila TCPDF bermasalah; antarmuka pembangkit PDF dibuat tipis agar penggantian tidak menyentuh LKH/DRH.

---

## 4. Kebutuhan non-fungsional
- Koreksi NIP, biodata, tambah/hapus pegawai, dan lampiran: **satu transaksi**, rollback penuh (termasuk kompensasi berkas).
- Lingkup akses diperiksa di setiap endpoint; di luar lingkup → 403.
- Audit untuk semua perubahan data dan approval.
- Arsip remote legacy tidak disalin (repo publik); hanya `LocalStorageAdapter`.
- Kualitas: `./check.sh` lolos. Test wajib DoD (bagian dari build, keputusan #9): B-06 dan butir cascade B-21.
- Test ber-DB memakai base case CR-045 (bila sudah di `main`) dan fixture yang mematuhi FK DBV-019.

---

## 5. Kepemilikan berkas

| Milik WS-2 | Tidak boleh disentuh WS-2 |
|---|---|
| `RoutesPegawai.php`, `PegawaiScope`, `Attachment*`/`StorageAdapter`, `NipCascadeService`, `TabelAnakNip`, `composer.json` (termasuk library PDF), Auth, `Definisi/Jabatan.php`, layanan biodata/struktur/LKH/Konket, `routes.pegawai.ts`, `router/index.ts`, `nav.config.ts`, `DetailPegawaiPage`, `StatusBadge`, `ApprovalDialog`, halaman Daftar Pegawai/Struktur/Konket/LKH | Engine, SnapshotSync, `BaseSnapshotModel`, Definisi selain Jabatan, `RoutesRiwayat.php`, `routes.riwayat.ts`, `Routes.php`/`Services.php` (setelah S0), **semua migration yang sudah ada di `main`**, `_support` milik PR #20 (`LepasMigrationKepegawaianTrait.php`, `SkemaKepegawaianTestTrait.php`, `Kepegawaian/SkemaD1*.php`), berkas base case CR-045 |

---

## 6. Dependency

| WS-2 butuh | Dari | Butuh / siap | Cara melepas |
|---|---|---|---|
| Interface Scope/Lampiran + fixture | WS-1 S0-A (CR-046) | h1,5 / h1 | Kontrak S0 di `main` |
| Descriptor tab | WS-1 | h7 / S0 (stub) | Kontrak beku; tab muncul otomatis saat WS-1 mendaftarkan jenis |
| **engine-v1 beku** (B-07) | WS-1 M2 (CR-049) | **h12,5 / h9,5** | Slack 3 hari; fallback tukar B-07 dengan Konket/LKH |
| Tabel riwayat WS-1 (DRH) | WS-1 | h21,5 / `main` | Hanya baca |
| Snapshot pmj | `main` | h1,5 | Fixture S0-A |
| Base case test (CR-045), FK DBV-019 | Sesi utama | S0 | Fixture hanya-INSERT; rebase saat masuk `main` |

| WS-1 butuh dari WS-2 | Siap |
|---|---|
| PegawaiScope nyata | h3 (masuk `main` bersama paket WS-2 M1) |
| B-18 Lampiran nyata + helper kolom berkas | h5 (paket WS-2 M1) |
| Port FE + router/nav + detail B-03 | h1,5 (S0-B) / h9,5 (WS-2 M2) |

**Eksternal:** G-02 (#17/#18), G-03 (#21), G-09 (#19) — semuanya sudah di `main`; DBV-009 (tipe `id_satker`, cast sementara); DBV-019 (PR #23, FK G-02↔pegawai); library PDF (ADR-033, mPDF sebelum ±h19).

---

## 7. Milestone & kriteria selesai
| Milestone | Key | ±Hari | Kriteria |
|---|---|---|---|
| S0-B fondasi FE | CR-047 | h1,5 | Port per berkas, registry tab, route/nav, StatusBadge/ApprovalDialog di `main` tanpa data contoh runtime |
| M1 Scope + Lampiran nyata | CR-053 | h5 | Test lingkup role 2/3/4/5/8 + UL_PEGAWAI hijau; simpan/hapus transaksional + kompensasi berkas hijau; masuk `main` untuk WS-1 |
| M2 Koreksi NIP + detail pegawai live | CR-054 | h9,5 | Cascade + rollback penuh teruji (B-21); list/detail + descriptor tab tersambung |
| M3 Approval biodata + tambah/hapus | CR-055 | h12,5 | B-04/B-05 tersambung; regresi A-09 hijau |
| M4 Jabatan + Struktur live | CR-056 | h17,5 | B-07 di atas engine-v1, snapshot pmj benar; B-19 tersambung |
| M5 Konket + LKH | CR-057 | h21,5 | Halaman Konket & Verifikasi LKH tersambung; ADR-033 (TCPDF) disetujui |
| M6 Selesai | CR-058 | h24 | B-20 penutup tersambung, `ACTIVE_PHASE` = 3; `check.sh` hijau |

### 7.1 Peta key CR Fase 3 (keputusan #11: satu key per paket milestone per WS)

Key yang sudah terpakai sebelum Fase 3: CR-044 (CI), CR-045 (PHPUnit cepat + MySQL test), DBV-019 (FK G-02↔pegawai, PR #23).

| Paket | WS | Isi | Key |
|---|---|---|---|
| S0-A | WS-1 | Kontrak backend | CR-046 |
| S0-B | WS-2 | Fondasi frontend | CR-047 |
| WS-1 M1 | WS-1 | SnapshotSync + RiwayatEngine BE | CR-048 |
| WS-1 M2 | WS-1 | B-10 + B-08 + freeze engine-v1 | CR-049 |
| WS-1 M3 | WS-1 | FE mesin riwayat + B-09 + B-14 | CR-050 |
| WS-1 M4 | WS-1 | B-16 + B-11 + B-17 (+ halaman Karpeg/Karis) | CR-051 |
| WS-1 M5 | WS-1 | B-12a SKP + B-15 AK + B-21 butir snapshot | CR-052 |
| WS-2 M1 | WS-2 | PegawaiScope + B-18 Lampiran | CR-053 |
| WS-2 M2 | WS-2 | B-06 Koreksi NIP + B-03 Biodata (+ backend dasar B-20) | CR-054 |
| WS-2 M3 | WS-2 | B-04 Approval biodata + B-05 Tambah/Hapus | CR-055 |
| WS-2 M4 | WS-2 | B-07 Jabatan + B-19 Struktur | CR-056 |
| WS-2 M5 | WS-2 | B-13 Konket + B-12b LKH + ADR-033 PDF (TCPDF) | CR-057 |
| WS-2 M6 | WS-2 | B-20 penutup | CR-058 |

Perbaikan hasil review memakai key paket yang sama. Bila katup dipakai (mis. Konket pindah ke WS-1), task ikut key paket tujuan. Kebutuhan skema baru di luar build memakai key DBV baru (DBV-020 dst.) yang dialokasikan sesi utama.

---

## 8. Risiko
| Risiko | Mitigasi |
|---|---|
| G-02 perlu koreksi | Sudah di `main`; koreksi lewat migration baru (DBV baru); hanya paruh kedua WS-2 terdampak; katup Konket/LKH |
| engine-v1 terlambat | Tukar B-07 dengan Konket/LKH (+±4 hari slack) |
| WS-2 tertinggal | Katup: Konket pindah ke WS-1; DRH digeser paling akhir |
| TCPDF bermasalah saat port template | ADR-033 sudah diputus; cadangan dompdf di balik antarmuka tipis; LKH/DRH di ekor |
| Kontrak beda dengan skema/legacy (`status 0` vs `flag_update`, `id_parent` vs `id_entri`+`id_riwayat`, arti Konket) | Build ikut skema/legacy; deviasi dicatat di progres |
| Port FE bentrok dengan halaman yang berubah di `main` sejak redesign (Web Config, Lokasi Presensi, master) | Port per berkas hanya `shared/layouts/*` + `features/kepegawaian/**`; versi `main` menang untuk berkas lain; route file terpisah |
| Antrian merge per milestone (gate penuh ±80 menit) menunda serah-terima Scope/B-18 | Paket M1 diserahkan begitu siap; gate penuh sekali pada commit yang diserahkan (AGENTS.md §3); CR-045 mempercepat PHPUnit |

---

## 9. Keputusan (disetujui user 07-10-2026)

| # | Keputusan | Dampak ke WS-2 |
|---|---|---|
| 1 | Gugur: build berangkat dari `main` (PR #20 & G-02 sudah di `main`, Fase 2 sign-off 07-10) | Tidak ada build di atas draf |
| 2 | Pembagian WS-1/WS-2 sesuai PRD: B-18 di WS-2, B-15 di WS-1, B-12 dipecah (SKP WS-1, LKH WS-2), B-20 dipecah (dasar di B-03, penutup di akhir) | Cakupan §3 |
| 3 | Tanpa branch integrasi: tiap WS branch sendiri dari `main`, masuk `main` per milestone sebagai CR-only, gate penuh sekali sebelum push | §0, §7 |
| 4 | Hak akses ikut Matriks v2 (Hukdis 1/3, Jabatan & AK sesuai Matriks, Karpeg/Karis 1,2,4,5,7 sesuai DoD), **kecuali approver LKH = atasan langsung** (legacy). Izin disimpan sebagai data | §2, B-07, LKH |
| 5 | (05-10) Konket & Karpeg/Karis = halaman usulan mandiri, layout legacy, style redesign | §3.3; tab tidak di-port |
| 6 | Batas lampiran per jenis 1/2/5 MB ikut legacy | B-18 |
| 7 | PDF: TCPDF via ADR-033 (`docs/adr/ADR-033-library-pdf-tcpdf.md`; lisensi LGPL-3.0, sama dengan legacy); fallback dompdf | §3.4 |
| 8 | Status 3 "Diproses" di belakang flag, nonaktif default | Engine (WS-1); LKH memakai status 3 = Revisi sesuai legacy |
| 9 | Unit/feature test yang diwajibkan DoD (B-06/08/09/14, B-21) + gate = bagian build; QA Lapis 1/review/sesi QA tidak | §4 |
| 10 | Default ikut legacy: acuan KGB = KP/KGB terakhir; cascade NIP ikut `update_nip` legacy + `jabatan_koordinasi.nip` (TabelAnakNip CR-036 memuatnya); masa hukdis = `masa_sanksi_bulan`; lingkup unit destinasi 21 / unit lain 7 ikut legacy | B-06, PegawaiScope |
| 11 | Satu key CR per paket milestone per WS; key WS mulai CR-046 | §7.1 |

## 10. Aturan kerja
Worktree + DB scratch sendiri (`simpeg_v2_ws2*`, tanpa salin `.env` dev), `composer install` + `npm ci`, commit Bahasa Indonesia `feat(scope): CR-0xx …` dengan key paket (§7.1) **tanpa** trailer `Co-Authored-By`/penyebutan AI, tanpa `.env`/kredensial, repo publik tanpa IP/host internal. Gate cepat selama kerja; gate penuh **sekali** pada commit yang diserahkan, dijalankan sebagai proses lepas (AGENTS.md §3). Tidak push ke `main` — branch diserahkan untuk review CR. Hanya mengedit berkas milik WS-2; aksi baris lewat ⋮ (AGENTS.md §1).
