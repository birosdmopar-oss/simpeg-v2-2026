# PRD WS-2 — Pegawai Inti, Organisasi & Alur Khusus (Fase 3, Modul B)

| | |
|---|---|
| Status | **DRAF** — turunan dari usulan pembagian Fase 3 (`fase3_pembagian_workstream.md`); keputusan §6 selain #5 masih menunggu persetujuan |
| Pelaksana | Qoder-2 |
| Estimasi | ≈24 hari-agen (relatif, bukan tanggal kalender) |
| Cakupan | Build saja (BE + FE + test yang diwajibkan DoD + `check.sh`). QA Lapis 1, review independen, dan sesi QA di luar cakupan |
| Mitra | WS-1 (Qoder-1) — lihat `PRD_WS1.md` |

---

## 1. Latar belakang & tujuan

Di luar pola riwayat standar, Modul B punya data inti pegawai dan alur dengan mesin status sendiri. WS-2 memegang fondasi yang dipakai bersama (lingkup akses, lampiran, fondasi frontend), data inti pegawai, organisasi, serta alur non-standar.

**Tujuan**
1. Fondasi bersama siap lebih dulu: PegawaiScope nyata (h3) dan Lampiran B-18 nyata (h5) untuk WS-1.
2. Koreksi NIP aman: cascade 45 rujukan dalam satu transaksi, rollback penuh.
3. Biodata dua jalur (langsung vs approval) dengan mesin `flag_update` sendiri.
4. Jabatan/Mutasi, Struktur Organisasi, Konket, LKH, dan halaman pegawai (daftar, detail, pencarian, export, DRH) tersambung API.

**Bukan tujuan**
- RiwayatEngine, SnapshotSync, dan jenis riwayat karier (milik WS-1).
- Antrean Butuh Approval (Modul F), `user_lokasi_presensi`, `aa_lkh`, adapter penyimpanan remote (Fase 6).

---

## 2. Pengguna & hak akses

| Role | Kebutuhan |
|---|---|
| 1 (admin) | Ubah biodata langsung, approve/reject biodata, tambah/hapus pegawai, koreksi NIP, jabatan tambah/hapus |
| 2/3/6/7 | Ubah biodata lewat approval (`flag_update`) |
| 3 | Admin terbatas lingkup unit/satker (`validateAccess_AP` atas `pegawai_mutasi_jabatan`) |
| 4/5/8 | Akses sesuai matriks; role 8 tanpa panel filter pencarian |
| UL_PEGAWAI | Hanya data NIP sendiri; mengajukan Konket dan LKH |
| Atasan (`nip_atasan`) | Approver LKH (legacy) |

Selisih Matriks v2 vs guard legacy (Jabatan, LKH) menunggu keputusan §6 #4.

---

## 3. Kebutuhan fungsional

### 3.1 Sprint 0 — fondasi frontend (S0-B, ±h0–1,5)
1. Port **per berkas** dari commit `3420e1a` (branch redesign) ke branch di atas trunk: `shared/layouts/*` dan `features/kepegawaian/**`; AppShell dipensiunkan, 6 pembungkus halaman dipindah. Merge langsung dilarang (3 commit bertrailer terlarang, 12 konflik).
2. Pecah `riwayat.config.ts`, `RIWAYAT_MENUS`, `riwayat.spec.ts` menjadi `riwayat/jenis/<key>.ts` dengan registry `import.meta.glob`; `DetailPegawaiPage` merender tab dari registry. **Tab Konket dan Karpeg/Karis tidak di-port** (keputusan #5).
3. `routes.pegawai.ts` (WS-2) dan `routes.riwayat.ts` (WS-1), diimpor sekali dari `router/index.ts`. Daftarkan semua menu Fase 3 di `nav.config`: Daftar Pegawai, Struktur, Konket, Usulan Karpeg/Karis, Verifikasi LKH.
4. Samakan `types.ts` dengan kolom DDL PR #20.
5. `StatusBadge` dan `ApprovalDialog` di shared (alasan wajib saat tolak, slot diff untuk B-04). Item ⋮ "Setujui"/"Tolak" di grup aksi lain, sebelum "Hapus".
6. `useCascadeOptions` dan `masterService.options` di-re-export lewat `src/shared` (tidak dipindah).
7. Data contoh tetap di branch, tidak masuk `main` sebelum tersambung API.

### 3.2 Task berurutan
| # | Task | Isi | Est | ±Hari |
|---|---|---|---|---|
| 2 | **PegawaiScope nyata** → serah ke WS-1 | UL_PEGAWAI hanya NIP sendiri; 4/5/8 sesuai matriks; role 3 = port `validateAccess_AP` atas pmj (satker, atau unit bila admin tanpa satker); cast VARCHAR→INT sampai DBV-009 | 1,5 | 1,5–3 |
| 3 | **B-18 Lampiran** → serah ke WS-1 | `LocalStorageAdapter`; `document_attachment(NIP, id_riwayat, id_entri)` dengan kode dari `jenis_rwy`; batas ukuran/ekstensi dari Definisi; simpan/hapus di transaksi pemanggil (kompensasi berkas saat rollback); hapus keras + audit (DBV #9); helper kolom berkas; endpoint + `ArsipTable` | 2 | 3–5 |
| 4 | **B-06 Koreksi NIP** (+ butir B-21 cascade) | `NipCascadeService` 1 transaksi: salin `pegawai` → arahkan ulang `TabelAnakNip::sampaiFase(3)` (45 entri) + `pengguna` + `faq_rate` → hapus baris lama; cek keunikan; rollback penuh; role 1; dialog FE | 2 | 5–7 |
| 5 | **B-03 Biodata dua jalur** + backend dasar B-20 | Role 1 langsung ke `pegawai`; role 2/3/6/7 → `flag_update=1` + satu `pegawai_hist` pending per NIP; `pegawai_foto`; role 3 di luar lingkup → 403. GET list/detail + descriptor tab; DataUmumForm tersambung API | 2,5 | 7–9,5 |
| 6 | B-04 Approval biodata | Approve: flag 3, salin ke `pegawai`, sinkron `pengguna.email`. Reject: flag 2, alasan wajib, `pegawai` tidak berubah (bug legacy `L_employee.php:1098` tidak ditiru). Cek lingkup; dialog diff | 1,5 | 9,5–11 |
| 7 | B-05 Tambah & Hapus pegawai | `AccountProvisioner` (UserLevel dari `id_jenis_pegawai`, jaga regresi A-09); hapus = status 10 + akun nonaktif; status 1→2 menonaktifkan akun; `pegawai_sisa_cuti` dicatat untuk C-01 | 1,5 | 11–12,5 |
| 8 | **B-07 Jabatan/Mutasi + Plt/Plh** | `Definisi/Jabatan.php` di atas **engine-v1** + aturan snapshot pmj (`jenis_jabatan≠3`, seri ≥); dropdown & salinan nama G-02 (termasuk `jabatan_koordinasi`/`rumpun_jabatan`); Plt/Plh tanpa approval; tab FE. Hanya menulis Definisi + controller; hook baru diajukan ke WS-1 | 3 | 12,5–15,5 |
| 9 | B-19 Struktur Organisasi | Tree unit→satker→jabatan→pegawai (+Plt/Plh); `so/full` = sesi login; `jabatan/so` = UL_ALL; halaman tersambung | 2 | 15,5–17,5 |
| 10 | B-13 Konket *(katup)* | Lihat §3.3 | 1,5 | 17,5–19 |
| 11 | B-12b LKH | Approver atasan (`nip_atasan`); status 3 = Revisi; tanggal minimum 4 hari kerja (HariLiburService); unit/satker/atasan dari pmj; PDF; `aa_lkh` ditunda (DBV #13) | 2,5 | 19–21,5 |
| 12 | B-20 penutup | Advanced search (role 1,3,4,5; panel filter disembunyikan untuk 8), export, Cetak DRH PDF (1,2,3,4,5), integrasi semua tab | 2,5 | 21,5–24 |

### 3.3 Halaman Usulan Konket (keputusan #5, 05-10)
- Service tipis **di luar engine**: `absen_ijin` status W/V/X/10, `affect_tukin` (dependency Fase 5), `hari_libur`.
- **Halaman sendiri**, bukan tab di Detail Pegawai.
- **Layout ikut legacy** (`Konket.php`): index untuk UL_PEGAWAI, daftar/antrian `list_ad` role 1/3/4, proses role 1/3.
- **Style ikut redesign**: token + komponen shared, aksi baris lewat ⋮.
- Catatan: `03-Kepegawaian.md` menyebut B-13 "Kondisi Kerja: Dinas Luar/WFH/WFO"; build mengikuti arti legacy (`absen_ijin`), deviasi dicatat di progres.

---

## 4. Kebutuhan non-fungsional
- Koreksi NIP, biodata, tambah/hapus pegawai, dan lampiran: **satu transaksi**, rollback penuh (termasuk kompensasi berkas).
- Lingkup akses diperiksa di setiap endpoint; di luar lingkup → 403.
- Audit untuk semua perubahan data dan approval.
- Arsip remote legacy tidak disalin (repo publik); hanya `LocalStorageAdapter`.
- Kualitas: `./check.sh` lolos. Test wajib DoD: B-06.

---

## 5. Kepemilikan berkas

| Milik WS-2 | Tidak boleh disentuh WS-2 |
|---|---|
| `RoutesPegawai.php`, `PegawaiScope`, `Attachment*`/`StorageAdapter`, `NipCascadeService`, `TabelAnakNip`, `composer.json` (termasuk library PDF), Auth, `Definisi/Jabatan.php`, layanan biodata/struktur/LKH/Konket, `routes.pegawai.ts`, `router/index.ts`, `nav.config.ts`, `DetailPegawaiPage`, `StatusBadge`, `ApprovalDialog`, halaman Daftar Pegawai/Struktur/Konket/LKH | Engine, SnapshotSync, `BaseSnapshotModel`, Definisi selain Jabatan, `RoutesRiwayat.php`, `routes.riwayat.ts`, `Routes.php`/`Services.php` (setelah S0), migration `2026-09-30-1*`, `_support` PR #20 |

---

## 6. Dependency

| WS-2 butuh | Dari | Butuh / siap | Cara melepas |
|---|---|---|---|
| Interface Scope/Lampiran + fixture | WS-1 S0-A | h1,5 / h1 | Kontrak S0 |
| Descriptor tab | WS-1 | h7 / S0 (stub) | Kontrak beku; tab muncul otomatis saat WS-1 mendaftarkan jenis |
| **engine-v1 beku** (B-07) | WS-1 #5 | **h12,5 / h9,5** | Slack 3 hari; fallback tukar B-07 dengan Konket/LKH |
| Tabel riwayat WS-1 (DRH) | WS-1 | h21,5 / trunk | Hanya baca |
| Snapshot pmj | trunk | h1,5 | Fixture S0-A |

| WS-1 butuh dari WS-2 | Siap |
|---|---|
| PegawaiScope nyata | h3 |
| B-18 Lampiran nyata + helper kolom berkas | h5 |
| Port FE + router/nav + detail B-03 | h1,5 / h9,5 |

**Eksternal:** G-02 (#17/#18, sudah di trunk), DBV-009 (tipe `id_satker`, cast sementara), PR #19 (saat rebase menu Web Config dipindah ke `nav.config`), library PDF (ADR + lisensi sebelum ±h19).

---

## 7. Milestone & kriteria selesai
| Milestone | ±Hari | Kriteria |
|---|---|---|
| Fondasi FE | h1,5 | Port per berkas, registry tab, route/nav, StatusBadge/ApprovalDialog di trunk |
| Scope nyata | h3 | Test lingkup role 2/3/4/5/8 + UL_PEGAWAI hijau; diserahkan ke WS-1 |
| Lampiran nyata | h5 | Simpan/hapus transaksional + kompensasi berkas hijau; diserahkan ke WS-1 |
| Koreksi NIP | h7 | Cascade 45 rujukan + rollback penuh teruji (B-21) |
| Detail pegawai live | h9,5 | List/detail + descriptor tab tersambung |
| Jabatan live | h15,5 | B-07 di atas engine-v1, snapshot pmj benar |
| Selesai | h24 | B-19, Konket, LKH, B-20 tersambung; `check.sh` hijau |

---

## 8. Risiko
| Risiko | Mitigasi |
|---|---|
| G-02 direvisi/terlambat merge | Hanya paruh kedua terdampak; katup Konket/LKH |
| engine-v1 terlambat | Tukar B-07 dengan Konket/LKH (+±4 hari slack) |
| WS-2 tertinggal | Katup: Konket pindah ke WS-1; DRH digeser paling akhir |
| Library PDF belum disetujui | ADR sebelum ±h19; LKH/DRH di ekor |
| Kontrak beda dengan skema/legacy (`status 0` vs `flag_update`, `id_parent` vs `id_entri`+`id_riwayat`, arti Konket) | Build ikut skema/legacy; deviasi dicatat di progres |
| Konflik dengan PR #19 | Route file terpisah; trunk tanpa G-03/G-09 |

---

## 9. Keputusan terbuka yang memengaruhi WS-2
- §6 #4 izin Jabatan tambah/hapus (1), approver LKH (atasan).
- §6 #6 batas lampiran per jenis.
- §6 #7 library PDF (LKH, DRH).
- §6 #10 default: cascade NIP (CR-036 #1–#5), aturan lingkup UNIT_DESTINASI 21/UNIT_LAIN 7.
- §6 #11 alokasi key CR.

## 10. Aturan kerja
Worktree + DB scratch sendiri (`simpeg_v2_ws2*`, tanpa salin `.env` dev), `composer install` + `npm ci`, commit Bahasa Indonesia `feat(scope): …` dengan key CR/DBV **tanpa** trailer `Co-Authored-By`/penyebutan AI, tidak push ke `main`, hanya mengedit berkas milik WS-2, aksi baris lewat ⋮ (AGENTS.md §1).
