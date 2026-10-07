# PRD WS-1 — Mesin Riwayat & Riwayat Karier/Administrasi (Fase 3, Modul B)

| | |
|---|---|
| Status | **DISETUJUI 07-10-2026** — turunan dari usulan pembagian Fase 3 (`fase3_pembagian_workstream.md`); keputusan user dicatat di §9 |
| Pelaksana | Qoder-1 |
| Estimasi | ≈24,5 hari-agen (relatif, bukan tanggal kalender) |
| Cakupan | Build saja (BE + FE + test yang diwajibkan DoD + `check.sh`). QA Lapis 1, review independen, dan sesi QA di luar cakupan |
| Basis & alur merge | Branch sendiri dari `main`; masuk `main` **per milestone** sebagai CR-only setelah review CR; gate penuh sekali sebelum push (AGENTS.md §3). Tidak ada branch integrasi/trunk |
| Key review | CR-046 (S0-A), CR-048..CR-052 (milestone WS-1) — lihat §7.1 |
| Mitra | WS-2 (Qoder-2) — lihat `PRD_WS2.md` |
| Briefing Sprint 0 | `docs/fase3/BRIEFING_S0A_Qoder1_backend.md` |

---

## 0. Prasyarat & basis build

| # | Prasyarat | Status 07-10-2026 |
|---|---|---|
| P-1 | Fase 2 sign-off | **Selesai 07-10-2026** |
| P-2 | Skema B-01/B-02 (PR #20, DBV-012/013) dan G-02 (PR #17/#18) di `main` | **Selesai** — build berangkat dari `main`, bukan dari draf |
| P-3 | CR-044 — CI GitHub Actions | Dikerjakan sesi utama paralel dengan Sprint 0 |
| P-4 | CR-045 — PHPUnit cepat + base case test DB baru (migrate sekali + transaksi per test) | Dikerjakan sesi utama paralel. Fixture Fase 3 **wajib** memakai base case ini begitu masuk `main` |
| P-5 | DBV-019 — FK G-02 ↔ pegawai/riwayat (PR #23) | Dikerjakan sesi utama paralel. Fixture Fase 3 **wajib** mematuhi FK ini (baris master unit/satker/jabatan disediakan fixture) |

Alur: setiap WS bekerja di branch sendiri dari `main` dan rebase ke `main` saat paket milestone lain (WS-1 atau WS-2) sudah masuk. Serah-terima antar-WS terjadi **lewat `main`** (paket milestone yang sudah di-merge), bukan lewat branch integrasi.

---

## 1. Latar belakang & tujuan

Sebagian besar data Modul B berpola sama: **baris riwayat ber-approval yang menyinkronkan tabel snapshot**. WS-1 membangun satu mesin generik (RiwayatEngine + SnapshotSync), membuktikannya lewat dua pilot, membekukannya, lalu memakainya untuk semua jenis riwayat karier/administrasi.

**Tujuan**
1. Satu engine riwayat yang dipakai 11 jenis riwayat tanpa kode khusus per jenis di luar berkas Definisi.
2. Snapshot hanya berubah pada approval final dan selalu konsisten dengan baris status 1.
3. Engine dibekukan (engine-v1) paling lambat ±h9,5 agar WS-2 bisa membangun B-07 Jabatan di atasnya.
4. Semua tab riwayat WS-1 tersambung API di Detail Pegawai, plus halaman usulan Karpeg/Karis.

**Bukan tujuan**
- Biodata, akun, koreksi NIP, lampiran, lingkup akses, jabatan/struktur, Konket, LKH, halaman daftar pegawai (milik WS-2).
- Mengubah skema PR #20 atau migration yang sudah ada di `main`.

---

## 2. Pengguna & hak akses

| Role | Kebutuhan |
|---|---|
| UL_PEGAWAI (pegawai) | Mengajukan riwayat milik sendiri (status 0), melihat status dan alasan tolak |
| Admin (1, dan role lain sesuai Definisi) | Input langsung (status 1), menyetujui/menolak usulan, menghapus (status 10) |
| Role 3 | Sama dengan admin, terbatas lingkup unit/satker via `PegawaiScope` (disediakan WS-2) |
| Karpeg & Karis/Karsu | Role 1, 2, 4, 5, 7 (DoD B-17, Matriks v2); pembagian per aksi (ajukan, daftar/antrian, proses) mengikuti Matriks v2 Bagian 2 Modul B |

Hak akses mengikuti **Matriks v2** (keputusan #4): Hukdis role 1/3, AK sesuai Matriks, Karpeg/Karis role 1, 2, 4, 5, 7 sesuai DoD, Tanda Jasa role 1, 3 (admin) dan 2, 4, 5 (lihat), Organisasi role 1, 2, 3, 7. Izin per aksi per role disimpan **sebagai data di Definisi** tiap jenis, sehingga perubahan cukup satu baris Definisi. Pengecualian (approver LKH = atasan langsung, ikut legacy) ada di WS-2.

---

## 3. Kebutuhan fungsional

### 3.1 Sprint 0 — kontrak backend (S0-A, CR-046, ±h0–1)
Rincian siap-serah: `docs/fase3/BRIEFING_S0A_Qoder1_backend.md`.
1. `Config/Routing.php` `$routeFiles` → `RoutesRiwayat.php` (WS-1) dan `RoutesPegawai.php` (WS-2). Setelah itu `Routes.php` tidak disentuh.
2. `Config/Services.php` disentuh **sekali**: daftarkan semua service Fase 3 sebagai stub/fake (`pegawaiScope`, `attachmentService`, `storageAdapter`, `riwayatRegistry`, `riwayatService`, `snapshotSync`, `pegawaiService`, `biodataService`, `nipCascade`, `strukturService`, `lkhService`, `konketService`).
3. Interface + fake: `PegawaiScope`/`FakePegawaiScope`, `AttachmentService`/`StorageAdapter` + fake memori, `RiwayatDefinisi` (satu berkas per jenis di `Libraries/Kepegawaian/Riwayat/Definisi/*.php`, auto-discovery), `StatusRiwayat` 0/1/2/10 (nilai 3 di belakang flag, nonaktif default), stub `RiwayatRegistry::descriptorUntuk(AuthContext, pegawai)`.
4. `tests/_support/Kepegawaian/PegawaiFixtureTrait.php` (baru): pegawai + `pegawai_mutasi_jabatan` + akun `pengguna`, beserta baris master G-02 (unit, satker, jabatan) yang dirujuk sehingga lolos FK DBV-019; memakai base case CR-045 bila sudah di `main`.
5. Kontrak API di `Controllers/Api/Kepegawaian/README.md`: `pegawai/{nip}`, `pegawai/{nip}/riwayat/{jenis}[/{id}[/process]]`, endpoint lampiran, JSON descriptor tab `{jenis,label,can_view,can_create,can_edit,can_delete,can_process}`, payload snake_case = kolom DDL, kode 403/404/422, approve/reject diaudit sebagai `update`.
6. `docs/progress/03-Kepegawaian.md` dipecah satu baris per task dengan kolom WS.

### 3.2 SnapshotSync (±h1–2,5)
- Memilih ulang baris status 1 dengan filter + urutan per target (doc DBV-012 §5.1); pemilih = fungsi murni.
- Multi-target: `riwayat_kp`→kp/cpns/pns, `riwayat_alamat`→alamat/alamat_kantor.
- Snapshot di-DELETE bila tidak ada baris yang memenuhi.
- Berjalan di transaksi pemanggil dengan audit manual; dipicu di **setiap** transisi masuk/keluar status 1.

### 3.3 RiwayatEngine BE (±h2,5–6,5)
- Model generik (pola MasterModel); service list/get/create/update/hapus (status 10)/process.
- Status awal: 0 untuk UL_PEGAWAI, 1 untuk admin. Guard baris yang sudah disetujui. `approved_by`, `reason_note`.
- `show_ua_*` per role (pola `L_kp` 649-668); `show_notif`/`notif_date` UTC + antrean notifikasi; salin nama master.
- BaseRiwayatController, loop route di `RoutesRiwayat.php`, trait test B-TC generik.
- Isi Definisi: tabel/PK, field (pola MasterField), izin, alur (self-service/admin/usulan), domain status, aturan snapshot, aturan lampiran (wajib, `jenis_rwy`, MB, ekstensi), hook (validate/beforeSave/afterApprove).
- Status 3 "Diproses" hanya aktif bila flag dinyalakan (keputusan #8); default nonaktif sehingga domain efektif 0/1/2/10.

### 3.4 Pilot & freeze
| Task | Isi | ±Hari |
|---|---|---|
| B-10 Pendidikan (pilot) | Self-service 1,2,3,6,7; snapshot jenjang tertinggi; status INT NULL (DBV #8); lampiran nyata dari WS-2 | 6,5–8 |
| B-08 KP | Multi-target kp/cpns/pns; `KenaikanPangkat::validasiTmt` (CR-025). Setelah lolos → **FREEZE engine-v1**, masuk `main` sebagai paket milestone WS-1 M2 (CR-049) | 8–9,5 |

Setelah freeze, perubahan engine hanya **aditif** dan hanya oleh WS-1. Permintaan hook baru dari WS-2 diajukan ke WS-1.

### 3.5 Frontend mesin riwayat (±h9,5–12)
- Klien API per jenis; tab dirender dari registry `riwayat/jenis/<key>.ts` dan izin dari descriptor.
- Aksi baris lewat ⋮ (`RowActionsMenu`, AGENTS.md §1): Edit → … → Setujui/Tolak (grup aksi lain) → Hapus; `ApprovalDialog` (alasan wajib saat tolak).
- Master-select/cascade, field berkas, field hitung, pemetaan error 422.
- Mode **halaman usulan mandiri** (dipakai Karpeg/Karis).

### 3.6 Jenis riwayat (fan-out, ±h12–24)
| # | Jenis | Catatan khusus | Est |
|---|---|---|---|
| 7 | B-09 KGB | `validasiJarak`; acuan jarak = **KP/KGB terakhir** (ikut legacy, keputusan #10 — menutup CR-025 #3) | 1 |
| 8 | B-14 Hukdis | `hitungTanggalBerakhir`; status ditulis 1 (DBV #10); masa hukdis = `masa_sanksi_bulan` (keputusan #10, menutup K5b); izin role 1/3 (Matriks v2) | 1 |
| 9 | B-16 Keluarga & Alamat | `detail_anak`; alamat multi-baris → 2 snapshot; wilayah berjenjang | 2 |
| 10 | B-11 Diklat & Seminar | Snapshot diklat jenis 1; dropdown `id_sub_group_jabatan` (G-02); seminar tanpa snapshot | 1,5 |
| 11 | B-17 TJ, Organisasi, Karpeg, Karis/Karsu | TJ snapshot 26/27/28. **Karpeg/Karis = usulan mandiri** (lihat §3.7) | 3 |
| 12 | B-12a SKP *(katup)* | `riwayat_skp` lewat engine; `riwayat_skp_periodik`/`bkn_periode_ekinper` diedit role 1 tanpa approval | 1,5 |
| 13 | B-15 AK | `riwayat_ak`→`pegawai_ak`, `riwayat_ak_siasn`→`pegawai_ak_siasn` (DBV #6), `konv_ak`/`ignore_konv_ak`; izin sesuai Matriks v2; role 6 diblok; baca `riwayat_kp` + `riwayat_mutasi_jabatan` | 2 |
| 14 | B-21 butir snapshot | Test ber-DB "snapshot hanya di approval final" | 0,5 |

### 3.7 Halaman Usulan Karpeg/Karis (keputusan #5, 05-10)
- **Halaman sendiri**, bukan tab di Detail Pegawai (tab redesign tidak di-port).
- **Layout ikut legacy** (`Karpeg.php`): menu sendiri, daftar/antrian `list_ad`, form usulan, alur proses.
- **Style ikut redesign**: token + komponen shared, aksi baris lewat ⋮.
- Hak akses role 1, 2, 4, 5, 7 (DoD B-17); pembagian per aksi dari Matriks v2, disimpan di Definisi.
- Berkas `file_1..5` memakai helper kolom berkas B-18 (WS-2).
- Route di `routes.riwayat.ts`; entri nav sudah dibuat WS-2 di S0 — WS-1 tidak menyentuh `router/index.ts`/`nav.config.ts`.

---

## 4. Kebutuhan non-fungsional
- Semua tulis riwayat + snapshot + lampiran dalam **satu transaksi**; rollback penuh bila gagal.
- Audit untuk create/update/hapus/approve/reject.
- Lingkup akses wajib lewat `PegawaiScope` di setiap endpoint (403 bila di luar lingkup).
- Waktu disimpan UTC.
- Batas lampiran **per jenis 1/2/5 MB ikut legacy** (keputusan #6), disimpan di aturan lampiran Definisi.
- Kualitas: `./check.sh` lolos (PHPStan 5, PHP-CS-Fixer, PHPUnit, ESLint, vue-tsc, Vitest, build). Test wajib DoD (bagian dari build, keputusan #9): B-08, B-09, B-14, dan butir snapshot B-21.
- Test ber-DB memakai base case CR-045 (bila sudah di `main`) dan fixture yang mematuhi FK DBV-019.

---

## 5. Kepemilikan berkas

| Milik WS-1 | Tidak boleh disentuh WS-1 |
|---|---|
| `RoutesRiwayat.php`, `Libraries/Kepegawaian/Riwayat/**` (engine, SnapshotSync, Definisi selain Jabatan), `BaseSnapshotModel`, `BaseRiwayatController`, `routes.riwayat.ts`, `riwayat/jenis/<key>.ts` milik WS-1, halaman Usulan Karpeg/Karis | `Routes.php`/`Services.php` (setelah S0), `RoutesPegawai.php`, `composer.json`, `TabelAnakNip`, router/nav, `DetailPegawaiPage`, `StatusBadge`/`ApprovalDialog` (perubahan lewat WS-2), **semua migration yang sudah ada di `main`**, `_support` milik PR #20 (`LepasMigrationKepegawaianTrait.php`, `SkemaKepegawaianTestTrait.php`, `Kepegawaian/SkemaD1*.php`), berkas base case CR-045 |

---

## 6. Dependency

| WS-1 butuh | Dari | Butuh / siap | Cara melepas |
|---|---|---|---|
| PegawaiScope | WS-2 #2 (paket WS-2 M1) | h2,5 / h3–5 | Interface + fake S0; versi nyata masuk lewat `main` |
| Lampiran B-18 | WS-2 #3 (paket WS-2 M1) | h6,5 / h5 | Fake S0; versi nyata masuk `main` sebelum pilot |
| Port FE + detail B-03 | WS-2 | h9,5 / h1,5 & h9,5 | Sementara tab pakai nip dari route |
| Tabel `riwayat_mutasi_jabatan` (B-15) | WS-2 B-07 | h22 / `main` | Hanya baca; isi dari fixture |
| Base case test (CR-045), FK DBV-019 | Sesi utama | S0 | Fixture ditulis hanya-INSERT; rebase saat masuk `main` |

| WS-2 butuh dari WS-1 | Siap |
|---|---|
| Descriptor tab (kontrak) | S0 (stub) |
| **engine-v1 beku** (untuk B-07) | **h9,5** (paket WS-1 M2 di `main`) — jalur kritis lintas WS |

---

## 7. Milestone & kriteria selesai
| Milestone | Key | ±Hari | Kriteria |
|---|---|---|---|
| S0-A kontrak beku | CR-046 | h1 | Interface, fake, README API, fixture tersedia di `main` |
| M1 SnapshotSync + Engine BE | CR-048 | h6,5 | Test unit pemilih murni + multi-target hijau; alur draft→approve/reject→snapshot→audit→lingkup hijau dengan fake |
| **M2 engine-v1 FREEZE** | CR-049 | **h9,5** | B-10 + B-08 lolos dengan lampiran & scope nyata; masuk `main` |
| M3 FE mesin riwayat + B-09/B-14 | CR-050 | h14 | Tab B-10/B-08/B-09/B-14 tersambung API di Detail Pegawai; test DoD B-09/B-14 hijau |
| M4 B-16/B-11/B-17 + Karpeg/Karis | CR-051 | h20,5 | Tab tersambung; halaman Usulan Karpeg/Karis tersambung |
| M5 Selesai | CR-052 | h24,5 | B-12a, B-15, butir snapshot B-21 tersambung/hijau; `check.sh` hijau |

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
| WS-2 M5 | WS-2 | B-13 Konket + B-12b LKH + ADR PDF (mPDF) | CR-057 |
| WS-2 M6 | WS-2 | B-20 penutup | CR-058 |

Perbaikan hasil review memakai key paket yang sama. Bila katup dipakai (mis. B-12a pindah ke WS-2), task ikut key paket tujuan. Kebutuhan skema baru di luar build memakai key DBV baru (DBV-020 dst.) yang dialokasikan sesi utama.

---

## 8. Risiko
| Risiko | Mitigasi |
|---|---|
| Engine jadi titik gagal tunggal | Dua pilot (B-10, B-08 multi-target) sebelum freeze; perubahan pasca-freeze hanya aditif |
| Skema PR #20 perlu koreksi | Skema sudah di `main`; koreksi hanya lewat migration ALTER baru dengan key DBV baru (di luar build); aturan disimpan sebagai data di Definisi; WS rebase ke `main` |
| Freeze terlambat → B-07 WS-2 tertahan | Katup: WS-2 menukar B-07 dengan Konket/LKH (+±4 hari slack) |
| WS-1 tertinggal | Katup: B-12a SKP pindah ke WS-2 |
| Antrian merge per milestone (gate penuh ±80 menit) menunda serah-terima | Gate penuh sekali pada commit yang diserahkan; reviewer tidak mengulang bila tree identik (AGENTS.md §3); CR-045 mempercepat PHPUnit |
| PHPUnit flaky di DB bersama | DB scratch `simpeg_v2_ws1*`, tanpa salin `.env` dev; base case CR-045 |

---

## 9. Keputusan (disetujui user 07-10-2026)

| # | Keputusan | Dampak ke WS-1 |
|---|---|---|
| 1 | Gugur: build berangkat dari `main` (PR #20 & G-02 sudah di `main`, Fase 2 sign-off 07-10) | Tidak ada pengecualian urutan fase; tidak ada build di atas draf |
| 2 | Pembagian WS-1/WS-2 sesuai PRD: B-18 di WS-2, B-15 di WS-1, B-12 dipecah (SKP WS-1, LKH WS-2), B-20 dipecah | Cakupan §3 |
| 3 | Tanpa branch integrasi: tiap WS branch sendiri dari `main`, masuk `main` per milestone sebagai CR-only, gate penuh sekali sebelum push | §0, §7 |
| 4 | Hak akses ikut Matriks v2 (Hukdis 1/3, Jabatan & AK sesuai Matriks, Karpeg/Karis 1,2,4,5,7 sesuai DoD), kecuali approver LKH = atasan langsung (legacy). Izin disimpan sebagai data di Definisi per jenis | §2, Definisi |
| 5 | (05-10) Konket & Karpeg/Karis = halaman usulan mandiri, layout legacy, style redesign | §3.7 |
| 6 | Batas lampiran per jenis 1/2/5 MB ikut legacy | §4, aturan lampiran Definisi |
| 7 | PDF: mPDF (ikut legacy) via ADR baru (lisensi GPL-2.0, pemakaian internal); fallback dompdf bila lisensi ditolak | Milik WS-2 (composer) |
| 8 | Status 3 "Diproses" di belakang flag, nonaktif default | `StatusRiwayat`, engine |
| 9 | Unit/feature test yang diwajibkan DoD (B-06/08/09/14, B-21) + gate = bagian build; QA Lapis 1/review/sesi QA tidak | §4 |
| 10 | Default ikut legacy: acuan KGB = KP/KGB terakhir; cascade NIP ikut `update_nip` legacy (+ `jabatan_koordinasi.nip`); masa hukdis = `masa_sanksi_bulan`; lingkup unit destinasi 21/unit lain 7 ikut legacy | B-09, B-14 |
| 11 | Satu key CR per paket milestone per WS; key WS mulai CR-046 | §7.1 |

## 10. Aturan kerja
Worktree + DB scratch sendiri (`simpeg_v2_ws1*`, tanpa salin `.env` dev), `composer install` + `npm ci`, commit Bahasa Indonesia `feat(scope): CR-0xx …` dengan key paket (§7.1) **tanpa** trailer `Co-Authored-By`/penyebutan AI, tanpa `.env`/kredensial, repo publik tanpa IP/host internal. Gate cepat selama kerja; gate penuh **sekali** pada commit yang diserahkan, dijalankan sebagai proses lepas (AGENTS.md §3). Tidak push ke `main` — branch diserahkan untuk review CR. Hanya mengedit berkas milik WS-1; aksi baris lewat ⋮ (AGENTS.md §1).
