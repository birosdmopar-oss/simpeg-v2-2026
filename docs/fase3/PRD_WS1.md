# PRD WS-1 — Mesin Riwayat & Riwayat Karier/Administrasi (Fase 3, Modul B)

| | |
|---|---|
| Status | **DRAF** — turunan dari usulan pembagian Fase 3 (`fase3_pembagian_workstream.md`); keputusan §6 selain #5 masih menunggu persetujuan |
| Pelaksana | Qoder-1 |
| Estimasi | ≈24,5 hari-agen (relatif, bukan tanggal kalender) |
| Cakupan | Build saja (BE + FE + test yang diwajibkan DoD + `check.sh`). QA Lapis 1, review independen, dan sesi QA di luar cakupan |
| Mitra | WS-2 (Qoder-2) — lihat `PRD_WS2.md` |

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
- Mengubah skema PR #20 atau migration yang sudah ada.

---

## 2. Pengguna & hak akses

| Role | Kebutuhan |
|---|---|
| UL_PEGAWAI (pegawai) | Mengajukan riwayat milik sendiri (status 0), melihat status dan alasan tolak |
| Admin (1, dan role lain sesuai Definisi) | Input langsung (status 1), menyetujui/menolak usulan, menghapus (status 10) |
| Role 3 | Sama dengan admin, terbatas lingkup unit/satker via `PegawaiScope` (disediakan WS-2) |
| Role 2 (Karpeg/Karis) | Memproses usulan Karpeg/Karis (guard legacy: index/add/edit/delete 2/7, `list_ad` 1/4/5, process 2) |

Izin per aksi per role disimpan **sebagai data di Definisi** tiap jenis. Selisih Matriks v2 vs guard legacy (Hukdis, AK, Karpeg/Karis) menunggu keputusan §6 #4; perubahannya cukup satu baris Definisi.

---

## 3. Kebutuhan fungsional

### 3.1 Sprint 0 — kontrak backend (S0-A, ±h0–1)
1. `Config/Routing.php` `$routeFiles` → `RoutesRiwayat.php` (WS-1) dan `RoutesPegawai.php` (WS-2). Setelah itu `Routes.php` tidak disentuh.
2. `Config/Services.php` disentuh **sekali**: daftarkan semua service Fase 3 sebagai stub/fake (`pegawaiScope`, `attachmentService`, `storageAdapter`, `riwayatRegistry`, `riwayatService`, `snapshotSync`, `pegawaiService`, `biodataService`, `nipCascade`, `strukturService`, `lkhService`, `konketService`).
3. Interface + fake: `PegawaiScope`/`FakePegawaiScope`, `AttachmentService`/`StorageAdapter` + fake memori, `RiwayatDefinisi` (satu berkas per jenis di `Libraries/Kepegawaian/Riwayat/Definisi/*.php`, auto-discovery), `StatusRiwayat` 0/1/2/10 (nilai 3 di belakang flag), stub `RiwayatRegistry::descriptorUntuk(AuthContext, pegawai)`.
4. `tests/_support/Kepegawaian/PegawaiFixtureTrait.php` (baru): pegawai + `pegawai_mutasi_jabatan` + akun `pengguna`.
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

### 3.4 Pilot & freeze
| Task | Isi | ±Hari |
|---|---|---|
| B-10 Pendidikan (pilot) | Self-service 1,2,3,6,7; snapshot jenjang tertinggi; status INT NULL (DBV #8); lampiran nyata dari WS-2 | 6,5–8 |
| B-08 KP | Multi-target kp/cpns/pns; `KenaikanPangkat::validasiTmt` (CR-025). Setelah lolos → **FREEZE engine-v1**, diserahkan ke trunk | 8–9,5 |

Setelah freeze, perubahan engine hanya **aditif** dan hanya oleh WS-1. Permintaan hook baru dari WS-2 diajukan ke WS-1.

### 3.5 Frontend mesin riwayat (±h9,5–12)
- Klien API per jenis; tab dirender dari registry `riwayat/jenis/<key>.ts` dan izin dari descriptor.
- Aksi baris lewat ⋮ (`RowActionsMenu`, AGENTS.md §1): Edit → … → Setujui/Tolak (grup aksi lain) → Hapus; `ApprovalDialog` (alasan wajib saat tolak).
- Master-select/cascade, field berkas, field hitung, pemetaan error 422.
- Mode **halaman usulan mandiri** (dipakai Karpeg/Karis).

### 3.6 Jenis riwayat (fan-out, ±h12–24)
| # | Jenis | Catatan khusus | Est |
|---|---|---|---|
| 7 | B-09 KGB | `validasiJarak` dengan acuan `riwayat_kgb`/`riwayat_kp`/`pegawai_cpns` (default sementara CR-025 #3) | 1 |
| 8 | B-14 Hukdis | `hitungTanggalBerakhir`; status ditulis 1 (DBV #10); `masa_sanksi_bulan` sampai K5b diputuskan | 1 |
| 9 | B-16 Keluarga & Alamat | `detail_anak`; alamat multi-baris → 2 snapshot; wilayah berjenjang | 2 |
| 10 | B-11 Diklat & Seminar | Snapshot diklat jenis 1; dropdown `id_sub_group_jabatan` (G-02); seminar tanpa snapshot | 1,5 |
| 11 | B-17 TJ, Organisasi, Karpeg, Karis/Karsu | TJ snapshot 26/27/28. **Karpeg/Karis = usulan mandiri** (lihat §3.7) | 3 |
| 12 | B-12a SKP *(katup)* | `riwayat_skp` lewat engine; `riwayat_skp_periodik`/`bkn_periode_ekinper` diedit role 1 tanpa approval | 1,5 |
| 13 | B-15 AK | `riwayat_ak`→`pegawai_ak`, `riwayat_ak_siasn`→`pegawai_ak_siasn` (DBV #6), `konv_ak`/`ignore_konv_ak`; role 6 diblok; baca `riwayat_kp` + `riwayat_mutasi_jabatan` | 2 |
| 14 | B-21 butir snapshot | Test ber-DB "snapshot hanya di approval final" | 0,5 |

### 3.7 Halaman Usulan Karpeg/Karis (keputusan #5, 05-10)
- **Halaman sendiri**, bukan tab di Detail Pegawai (tab redesign tidak di-port).
- **Layout ikut legacy** (`Karpeg.php`): menu sendiri, daftar/antrian `list_ad`, form usulan, alur proses oleh role 2.
- **Style ikut redesign**: token + komponen shared, aksi baris lewat ⋮.
- Berkas `file_1..5` memakai helper kolom berkas B-18 (WS-2).
- Route di `routes.riwayat.ts`; entri nav sudah dibuat WS-2 di S0 — WS-1 tidak menyentuh `router/index.ts`/`nav.config.ts`.

---

## 4. Kebutuhan non-fungsional
- Semua tulis riwayat + snapshot + lampiran dalam **satu transaksi**; rollback penuh bila gagal.
- Audit untuk create/update/hapus/approve/reject.
- Lingkup akses wajib lewat `PegawaiScope` di setiap endpoint (403 bila di luar lingkup).
- Waktu disimpan UTC.
- Kualitas: `./check.sh` lolos (PHPStan 5, PHP-CS-Fixer, PHPUnit, ESLint, vue-tsc, Vitest, build). Test wajib DoD: B-08, B-09, B-14.

---

## 5. Kepemilikan berkas

| Milik WS-1 | Tidak boleh disentuh WS-1 |
|---|---|
| `RoutesRiwayat.php`, `Libraries/Kepegawaian/Riwayat/**` (engine, SnapshotSync, Definisi selain Jabatan), `BaseSnapshotModel`, `BaseRiwayatController`, `routes.riwayat.ts`, `riwayat/jenis/<key>.ts` milik WS-1, halaman Usulan Karpeg/Karis | `Routes.php`/`Services.php` (setelah S0), `RoutesPegawai.php`, `composer.json`, `TabelAnakNip`, router/nav, `DetailPegawaiPage`, `StatusBadge`/`ApprovalDialog` (perubahan lewat WS-2), migration `2026-09-30-1*`, `_support` PR #20 |

---

## 6. Dependency

| WS-1 butuh | Dari | Butuh / siap | Cara melepas |
|---|---|---|---|
| PegawaiScope | WS-2 #2 | h2,5 / h3 | Interface + fake S0 |
| Lampiran B-18 | WS-2 #3 | h6,5 / h5 | Fake S0; versi nyata siap duluan |
| Port FE + detail B-03 | WS-2 | h9,5 / h1,5 & h9,5 | Sementara tab pakai nip dari route |
| Tabel `riwayat_mutasi_jabatan` (B-15) | WS-2 B-07 | h22 / trunk | Hanya baca; isi dari fixture |

| WS-2 butuh dari WS-1 | Siap |
|---|---|
| Descriptor tab (kontrak) | S0 (stub) |
| **engine-v1 beku** (untuk B-07) | **h9,5** — jalur kritis lintas WS |

---

## 7. Milestone & kriteria selesai
| Milestone | ±Hari | Kriteria |
|---|---|---|
| Kontrak S0 beku | h1 | Interface, fake, README API, fixture tersedia di trunk |
| SnapshotSync | h2,5 | Test unit pemilih murni + multi-target hijau |
| Engine BE | h6,5 | Alur draft→approve/reject→snapshot→audit→lingkup hijau dengan fake |
| **engine-v1 FREEZE** | **h9,5** | B-10 + B-08 lolos dengan lampiran & scope nyata; diserahkan ke trunk |
| FE mesin riwayat | h12 | Tab B-10/B-08 tersambung API di Detail Pegawai |
| Selesai | h24,5 | Semua jenis §3.6 + halaman Karpeg/Karis tersambung; `check.sh` hijau |

---

## 8. Risiko
| Risiko | Mitigasi |
|---|---|
| Engine jadi titik gagal tunggal | Dua pilot (B-10, B-08 multi-target) sebelum freeze; perubahan pasca-freeze hanya aditif |
| Skema PR #20 berubah (16 keputusan DBV §8 masih terbuka) | Aturan disimpan sebagai data di Definisi; rebase saat trunk dibangun ulang |
| Freeze terlambat → B-07 WS-2 tertahan | Katup: WS-2 menukar B-07 dengan Konket/LKH (+±4 hari slack) |
| WS-1 tertinggal | Katup: B-12a SKP pindah ke WS-2 |
| PHPUnit flaky di DB bersama | DB scratch `simpeg_v2_ws1*`, tanpa salin `.env` dev |

---

## 9. Keputusan terbuka yang memengaruhi WS-1
- §6 #4 izin Hukdis (1,3 vs 1), AK (1), Karpeg/Karis (process 2 tanpa 3).
- §6 #6 batas lampiran per jenis (1/2/5 MB legacy vs 1–2 MB DoD).
- §6 #8 status 3 "Diproses" di belakang flag.
- §6 #10 default: acuan KGB (CR-025 #3), masa hukdis (K5b).
- §6 #11 alokasi key CR.

## 10. Aturan kerja
Worktree + DB scratch sendiri, `composer install` + `npm ci`, commit Bahasa Indonesia `feat(scope): …` dengan key CR/DBV **tanpa** trailer `Co-Authored-By`/penyebutan AI, tidak push ke `main`, hanya mengedit berkas milik WS-1, aksi baris lewat ⋮ (AGENTS.md §1).
