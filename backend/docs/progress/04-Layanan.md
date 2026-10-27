# Progress Fase 4 — Modul C: Layanan & Approval

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). **Acuan utama & sumber kebenaran: `docs/fase4/PRD_Fase4.md` v1.1 (26-10-2026; v1.0 18-10-2026).** Aturan proyek: `AGENTS.md`.

**Terakhir diperbarui:** 27 Oktober 2026 (DBV-022 disetujui DBV; M2 berjalan di PR #29; refund saldo MAKE-022 di main 189b8d4). Sebelumnya: 26 Oktober 2026 (M2b di main 3fbf08e; PR #29 DBV-022) — **take over** (pelaksana berganti; coder sebelumnya Wahyu sampai 20-10). M1 **selesai**: dokumen DBV-022 + 9 draf migration + 1 FK ditahan di `MigrationsDitahan/` + audit #11/#12, diverifikasi di DB scratch. Branch `fase4/dbv-022-skema-layanan` di-rebase ke `main` `4d4ae92`. PR `[DBV-022]` dibuat reviewer CR dari branch ini.

**Entry criteria:** **terpenuhi** — Fase 3 DONE (QA fungsional + QA UI Lapis 1 selesai 15-10-2026, temuan ditutup CR-049/050/051/054/055; `backend/docs/progress/03-Kepegawaian.md`). Tabel `pegawai`, snapshot `pegawai_*`, `riwayat_*` Fase 3, `jenis_rwy`, `document_attachment` sudah di `main`.

**Milestone (PRD §9):** M0 pemahaman → M1 dokumen DBV-022 → M2 migration → M3 cuti → M4 e-sign + notifikasi → M5 TB/IB/PMK → M6 dashboard + PIC → M7 frontend → M8 verifikasi.

| Task | Milestone | Key | Status | Ringkas |
|---|---|---|---|---|
| C-01 Migration layanan + pemisahan inbox/ticketing | M1, M2 | DBV-022 | **IN_PROGRESS** | M0 ✅ (19-10, diulang 26-10). M1 ✅ 26-10: dokumen DBV-022 (21 butir + Bagian 10 draf migration + Bagian 11 verifikasi developer) + `sql/DBV-022-audit-layanan.sql` 12 bagian + 9 draf migration & 1 FK ditahan di `app/Database/MigrationsDitahan/` (tidak jalan di CI). Bagian 7 **disetujui DBV 27-10** (PR #29). **M2 berjalan 27-10** (PR #29): 9 draf dipindah ke `Migrations/` (nama & DDL tetap), `AddFkNipLayananFase4` tetap ditahan sampai audit yatim lingkungan target = 0, registry NIP Fase 4 (`FASE` = 4, DQ-15 `ketua_tim` menunggu verifikasi delta DBV), 3 test skema — DBV-022 Bagian 12 |
| C-02 Pengajuan cuti | M2b, M3 | MAKE-022 | **IN_PROGRESS** | M2b ✅ DI MAIN 3fbf08e (26-10): `ValidasiTanggalCuti` (murni). Sisa (M3, service DB + tumpang tindih) menunggu M2 merged |
| C-03 Kalkulasi kuota N/N-1/N-2 + `calculate_lc` | M2b, M3 | MAKE-022 | **IN_PROGRESS** | M2b ✅ DI MAIN 3fbf08e: `HitungLamaCuti`, `PorsiTahunCuti`, `KuotaCuti`, `SaldoCuti`, `Config\Cuti` + `KuotaBoundaryTest` (K1–K21, T9, T11–T14). Sisa (M3, `KuotaCutiService` ber-DB) menunggu M2 merged; ⛔ Q-02 untuk bagian saldo tahunan. MAKE-022 refund per kolom = max(batas D8, snapshot riwayat_cuti), Q-05 v1.3 — `SnapshotSaldoCuti` + `KuotaCuti::kembalikan()` (DI MAIN 189b8d4, 27-10) |
| C-04 Approval berjenjang cuti | M2b, M3 | MAKE-022 | **IN_PROGRESS** | M2b ✅ DI MAIN 3fbf08e: `StatusCuti`, `TransisiCuti`, `EfekFinalCuti`, `PengingatCuti` (murni; single end node diuji). Sisa (M3: AL/PyB, `CutiApprovalService`) menunggu M2 merged; ⛔ Q-07 untuk bagian tunda pengingat |
| C-05 Penerbitan surat cuti e-sign (mock) | M4 | MAKE-023 | TODO | Menunggu M3 merged |
| C-06 Notifikasi approval | M4 | MAKE-023 | TODO | ⛔ DILARANG mulai sebelum DBV-022 disetujui (PRD §6.1.4) |
| C-07 Tugas Belajar | M5 | MAKE-024 | **IN_PROGRESS** | 27-10 branch `fase4/make-024-tb-ib-pmk-m5`: inti TB (CRUD, proses PIC, perpanjangan, `bebasPresensiPenuh`, surat) + route + kontrak + 6 berkas test; notifikasi lewat titik sambung no-op sampai M4 |
| C-08 Izin Belajar | M5 | MAKE-024 | **IN_PROGRESS** | 27-10 inti IB (CRUD, proses PIC, Surat IB otomatis kode 50) + route + kontrak + 4 berkas test; notifikasi lewat titik sambung no-op sampai M4 |
| C-09 PMK | M5 | MAKE-024 | **IN_PROGRESS** | 27-10 inti PMK (pengajuan, opsi, kalkulator, proses PIC 0→3→1/2, riwayat final + arsip SK) + route + kontrak + 5 berkas test; notifikasi lewat titik sambung no-op sampai M4 |
| C-10 Dashboard status layanan + PIC | M6 | MAKE-025 | TODO | Menunggu M3 & M5 merged |
| C-11 Frontend Modul Layanan | M7 | MAKE-026 | TODO | Per halaman setelah backend-nya merged; `ACTIVE_PHASE = 4` hanya di commit penutup |
| C-12 Unit test & verifikasi | M8 | MAKE-027 | TODO | Menunggu M3–M7 merged |

---

## M0 — Baca PRD, inspeksi repo, laporan pemahaman (19-10-2026)

**Sudah jalan.** PRD `docs/fase4/PRD_Fase4.md` dibaca seluruhnya (1403 baris). Repo diinspeksi: tidak ada kode/migration Modul C di `main`; berkas yang akan dipakai ulang diverifikasi ada; path/nama yang berbeda dari PRD dicatat di bawah.

**Belum jalan.** Seluruh M1–M8. Belum ada berkas Fase 4 yang dibuat atau diubah selain berkas progres ini (belum di-commit).

**Temuan inspeksi yang menyimpang dari teks PRD** (tidak mengubah keputusan; diusulkan dipakai apa adanya dari repo):

1. `NipCascadeService` ada di `backend/app/Libraries/Kepegawaian/NipCascadeService.php`, bukan `…/Kepegawaian/Nip/NipCascadeService.php` seperti ditulis PRD §6.1 file boundary. Registry `Nip/TabelAnakNip.php` memang di subfolder `Nip/`.
2. Nama service container: `Config\Services::esignGateway()` dan `pushNotifGateway()` → pemanggilan yang benar `service('esignGateway')` / `service('pushNotifGateway')`, bukan `service('esign')` seperti ditulis PRD §6.5.1. Nomor baris yang dirujuk PRD (`Services.php:462`, `:490`) cocok.
3. `Config\Queue::$jobHandlers` saat ini berisi `dummy` dan `reset-password-email`; `push-notif` ditambahkan aditif di M4.
4. Kode `jenis_rwy` tertinggi di `main` = **42** (`pmk`, `tabel_entri` = `riwayat_pmk`, sudah menyiapkan tempat untuk Fase 4). Usulan kode baru 43..49 (PRD §6.1.1 #21) tidak bentrok.
5. `App\Interfaces\Layanan\StatusTugasBelajarInterface` dan `Config\Services::statusTugasBelajar()` belum ada — dibuat di M5 (aditif, sesuai PRD §6.7.5).
6. `main` kini di `39b5331` (commit PRD), satu commit di atas basis kode yang ditulis PRD (`2b712ed`); selisihnya dokumen saja.

**Keputusan & deviasi.** Belum ada. Seluruh butir ◻ §12 memakai default PRD (disetujui 18-10-2026).

**Blocker (menunggu reviewer CR).**

| # | Blocker | Menghambat |
|---|---|---|
| B1 | **Sumber DDL D1** (`simpeg01_struktur_lengkap_20261001.sql`) tidak ada di repo maupun di mesin ini. PRD §6.1.2 butir 3 mewajibkan dokumen DBV-022 memuat "DDL D1 apa adanya (baris)" per tabel, sementara §6.1.1 hanya memuat ringkasan kolom | M1 (C-01) — tidak bisa dimulai |
| B2 | **Q-07** (reset `skip_al`/`skip_pyb` saat login) — saran default menawarkan **dua** mekanisme alternatif (event `auth.login` + listener, **atau** `GET cuti/pengingat?reset=1` dari FE); perlu dipilih satu | Bagian tunda pengingat di M3 |
| B3 | **Q-01** — laporan M3 wajib memuat jumlah pejabat AL/PyB ber-role 4/5/8 dari data impor; data impor produksi tidak tersedia di lingkungan ini | Laporan M3 |
| B4 | Konfirmasi nomor key: DBV-022, MAKE-022..027 (PRD §2.2 "dikonfirmasi reviewer CR di M0") | M1 dst. |
| B5 | **Q-06(a)** nilai konkret `Config\Layanan` (nama/jabatan Menteri, id jabatan Kabiro kepegawaian, id satker/unit kepegawaian, kop PDF) — default menyebut "diisi saat cutover"; untuk test dibutuhkan nilai contoh | M3, M5, M6 |
| B6 | **Q-20** (DQ-3, DQ-4, DQ-6, DQ-7, DQ-9, DQ-10, DQ-12, DQ-16, DQ-17, DQ-18, DQ-20, DQ-21, nomor `jenis_rwy`) = keputusan DB Validator di DBV-022 | M2 |

**Berkas terakhir yang disentuh.** `backend/docs/progress/04-Layanan.md` (berkas ini).

**Test yang gagal/belum.** Belum ada test Fase 4.

---

## M1 — Dokumen DBV-022 + kueri audit (19/20-10-2026)

**Pembaruan 20-10-2026 (D1 diterima).** Blocker B1 selesai: berkas `simpeg01_struktur_lengkap_20261001.sql` diterima dan diverifikasi otentik (nomor baris cocok dengan rujukan PRD: `esign_hist` 891, `inbox` 1369, `jenis_cuti` 1634, `riwayat_cuti` 4396). Bagian 2 dokumen terisi untuk **21 butir tabel**: DDL D1 (kolom, tipe, COMMENT, index, nama FK + aksi, collation, AUTO_INCREMENT) + usulan v2 + tabel deviasi `[K]/[V2]/[I]` + perlakuan 6 trigger legacy + risiko impor.

Temuan dari D1 yang mengubah/menutup butir PRD:

1. **Butir `[I]` PRD §6.4.2 T4 tertutup → `[K]`**: COMMENT `riwayat_cuti.status_pyb` (D1:4431) memang menyebut `3: Disetujui AL - Waiting PYB`. PRD meminta ini diverifikasi di laporan M3; sudah terjawab di C-01.
2. `riwayat_cuti.nip` **sudah** `varchar(30)` — tidak perlu penyempitan V4 (hanya kolom approver, `petugas_layanan.nip`, `pa_layanan.nip`, `disiplin_ticket.nip` yang perlu).
3. FK `riwayat_cuti` → `jenis_cuti` di D1 bernama **`id_jenis_cuti`** (nama constraint = nama kolom) → pertanyaan baru **DQ-22**.
4. `jenis_cuti` PK komposit tetap aman disederhanakan: FK menunjuk kolom terdepan PK, jadi usulan DQ-9 tidak memutus FK.
5. `AUTO_INCREMENT` D1 membuktikan batas id master: `jenis_cuti`=7 (id 1..6), `jenis_layanan`=6 (id 1..5), `jenis_pa`=2 (id 1) — menguatkan seed literal.
6. **`jenis_rwy` usulan direvisi dari 7 ke 8 kode (43..50)**: `surat_cuti` dan `surat_cuti_tte` dipisah sebagai jawaban DQ-21, agar `document_attachment` Fase 3 tidak perlu kolom `tag` baru (melanggar K3/§2.7).
7. Pertanyaan baru dari D1: **DQ-22** (nama FK), **DQ-23** (`jenis_layanan.url_*`), **DQ-24** (typo `jenis_pa.udpated_at`), **DQ-25** (`firebase_token.auth_token` dibuang), **DQ-26** (`firebase_token` tanpa PK), **DQ-27** (`petugas_layanan.id_jenis_layanan` NULL-able).
8. **Dump hanya struktur** (`-- Data exporting was unselected.`) → kueri audit Bagian 6 **tetap belum bisa dijalankan** (B2 masih terbuka). Usulan coder dibuat fail-safe: FK yang bergantung hasil audit ditaruh di `MigrationsDitahan/` dengan pra-cek fail-closed.

**Sudah jalan (19-10-2026).**

- `backend/docs/db-review/DBV-022-layanan-fase4-schema.md` — Bagian 0 (ringkasan 21 butir tabel D18), Bagian 1 (konvensi K1, G2, V1–V5, status, K3, MigrationsDitahan), **Bagian 3 (pemisahan `inbox` v2 vs `disiplin_ticket` — bagian inti D10/DoD 064-2/064-3/064-6)**, Bagian 4 (registry NIP termasuk jenis baru untuk `ketua_tim`), Bagian 5 (urutan impor J1.07), Bagian 6 (peta kueri audit), Bagian 7 (usulan coder untuk DQ-3, DQ-4, DQ-6, DQ-7, DQ-9, DQ-10, DQ-12, DQ-16, DQ-17, DQ-18, DQ-20, DQ-21 + nomor `jenis_rwy`), Bagian 8 (rencana test skema), Bagian 9 (blocker).
- `backend/docs/db-review/sql/DBV-022-audit-layanan.sql` — 10 bagian kueri **baca-saja** sesuai PRD §6.1.2 butir 7, berklasifikasi WAJIB_0/INFO (pola DBV-011/DBV-019). Bagian 7.3 sengaja dikomentari (nama tabel pengguna legacy belum pasti tanpa D1).

Seluruh isi diturunkan dari PRD Fase 4 yang sudah disetujui; tidak ada tipe kolom, nama FK, atau nilai domain yang dikarang sendiri.

**Belum jalan.**

- **Bagian 2 dokumen (DDL D1 apa adanya + DDL v2 usulan + tabel deviasi per kolom untuk 21 butir)** — tertahan B1.
- Draf migration `2026-10-xx-xxxxxx_Create*.php` dan `MigrationsDitahan/Add*Fase4*.php` — tertahan B1 (tipe/panjang kolom tidak diketahui).
- Seed uji `Layanan*Seeder.php`, perubahan `TabelAnakNip.php`/`NipCascadeService.php`, test skema `tests/database/Layanan/**` — menunggu bentuk skema final.
- Kueri audit **belum dijalankan** (tidak ada akses salinan dump legacy).

**Keputusan & deviasi.** Belum ada yang mengikat; seluruh isi Bagian 7 masih berstatus *usulan coder* sampai DB Validator memutus (PRD §12 Q-20).

**Blocker.**

| # | Blocker | Menghambat |
|---|---|---|
| B1 | ✅ **SELESAI 20-10-2026** — D1 diterima dan terverifikasi | — |
| B2 | Dump yang diterima **hanya struktur** (tanpa data), sehingga `sql/DBV-022-audit-layanan.sql` belum bisa dijalankan | Keputusan DQ-3, DQ-10, DQ-12, DQ-18, DQ-20, DQ-27 |
| B2b | **14 butir DQ Bagian 7 belum diputus DB Validator** | Draf migration ditahan agar tidak ditulis dua kali |
| B3 | Salinan kode legacy di mesin kerja (`simpegdev…/application/`) **bukan** versi yang dirujuk PRD (`1_legacy_simpeg…`); nomor baris tidak cocok pada uji acak `Cuti.php:584-591`, `L_cuti.php:1440`, `L_cuti.php:2645-2651` | Verifikasi `[K]` di M3–M5 |
| B4 | **Q-07** — saran default menawarkan dua mekanisme alternatif (event `auth.login` + listener, atau `GET cuti/pengingat?reset=1`); perlu dipilih satu | Bagian tunda pengingat M3 |
| B5 | **Q-01** — laporan M3 wajib memuat jumlah AL/PyB ber-role 4/5/8 dari data impor; data impor tidak tersedia di lingkungan ini | Laporan M3 |
| B6 | **Q-06(a)** — nilai konkret `Config\Layanan` (Menteri, Kabiro kepegawaian, satker/unit kepegawaian, kop PDF) untuk seed & test | M3, M5, M6 |
| B7 | Konfirmasi nomor key DBV-022 / MAKE-022..027 (PRD §2.2: dikonfirmasi reviewer CR di M0) | M1 dst. |

**Berkas terakhir yang disentuh.** `backend/docs/db-review/DBV-022-layanan-fase4-schema.md`, `backend/docs/db-review/sql/DBV-022-audit-layanan.sql`.

---

## M0 ulang — take over 26-10-2026 (laporan PRD §9)

Pelaksana berganti (permintaan coder Wahyu). PRD v1.1 (`docs/fase4-prd-v1-1`, 13 keputusan Horii 26-10) dan dokumen DBV-022 dibaca ulang seluruhnya; repo `main` `4d4ae92` diinspeksi.

**Ringkasan pemahaman per task.** C-01 skema 21 butir (D18) + pemisahan notifikasi/ticketing (D10) → PR `[DBV-022]`, M2 di PR yang sama. C-02..C-04 cuti (MAKE-022): pengajuan, kuota N/N-1/N-2, approval AL→PyB dengan status 0/1/2/3/4 + `status_al`/`status_pyb`; Tahap 2b (library murni) **diizinkan** sebelum DBV-022 disetujui (PRD v1.1 §6.1.4). C-05 e-sign mock + C-06 notifikasi (MAKE-023) ⛔ sebelum DBV-022 disetujui. C-07..C-09 TB/IB/PMK (MAKE-024) satu langkah approval (D14/D15/D16). C-10 dashboard + PIC (MAKE-025), C-11 FE (MAKE-026), C-12 verifikasi (MAKE-027).

**Berkas yang dipakai ulang (diverifikasi ada di `main`).** `pegawai` + snapshot (DBV-012), `riwayat_*` Fase 3 (DBV-013), `jenis_rwy` (kode ≤ 42) + `document_attachment` (DBV-013), `MigrationsDitahan/AddFkPegawaiDiPenggunaFaqRate.php` & `AddFkSertifikasiDiRiwayatDiklat.php` (pola fail-closed), `2026-10-13-100000_CreateMasterSertifikasi.php` (pola CREATE SQL mentah), `Nip/TabelAnakNip.php` + `NipCascadeService` (registry, M2), `KopDokumen` (kop K1, D#9), `AttachmentService`, `HariKerja` (hanya sebagai acuan paritas; cuti menghitung sendiri, PRD v1.1 §6.3.2), `StatusHarian::JENIS_CUTI` (id 1..6 dipakai Tukin v2).

**Konfirmasi.** Tidak ada kode, model, route, maupun migration Modul C di `main` `4d4ae92` (`git diff origin/main -- backend/app/Database/Migrations/` kosong; tidak ada `app/**/Layanan/**`). Migration terakhir di `main`: `2026-10-13-100200`.

**Butir ⛔ PRD §12 yang masih terbuka setelah v1.1:** Q-01 (jumlah AL/PyB ber-role dari data impor — laporan M3), Q-02 (saldo tahunan awal — bagian kuota M3), Q-06 (nilai konkret `Config\Layanan` — M3/M5/M6), Q-07 (reset `skip_*` — tunda pengingat M3), Q-18 (M4), Q-20 (= keputusan DBV Bagian 7 → M2). Tidak ada yang menghambat M1 maupun Tahap 2b.

---

## M1 — pembaruan take over 26-10-2026 (selesai)

**Sudah jalan.**

- Branch di-rebase bersih ke `main` `4d4ae92` (2 commit dokumen Wahyu dipertahankan).
- Status dokumen DBV-022 dikoreksi: "⏳ Menunggu review DB Validator; isi = usulan coder, belum direview reviewer CR maupun DB Validator". Rujukan PRD → v1.1; `inbox.id_jenis_layanan` TINYINT → INT (V5, PK `jenis_layanan` INT); baris H `jenis_rwy` 43..50.
- **9 draf migration + 1 FK ditahan** di `backend/app/Database/MigrationsDitahan/` (dokumen Bagian 10): kelompok A cuti, B TB/IB, C PMK, D `inbox`/`inbox_msg` v2, E `disiplin_ticket`/`_msg` + `question_category` (di master), F `petugas_layanan`/`pa_layanan`, G `esign_hist`/`firebase_token`, H seed `jenis_rwy` 43..50, dan `AddFkNipLayananFase4.php` (FK NIP approver/PIC/pa_layanan/tiket → `pegawai`, fail-closed). Nama berkas sudah berstempel waktu `2026-10-26-1000xx` agar pemindahan ke `Migrations/` tidak mengubah nama. **Tidak** menyentuh ENUM `audit_logs.event` (DBV-023) maupun `NipCascadeService::FASE` (M2).
- `sql/DBV-022-audit-layanan.sql` + Bagian 11 (saldo negatif / di luar D8) dan 12 (`LENGTH(reason_note*) > 255`).
- Verifikasi developer di DB scratch `simpeg_v2_t_dbv022` (3307) — dokumen Bagian 11: 100/204/45 → 120 tabel/235 FK/84 CHECK (+5 FK ditahan = 240); 60+ uji constraint data sintetis (1452/1451/1062/3819/1048/1364) sesuai; `inbox` v2 tanpa 5 kolom ticketing; FK ditahan menolak jalan bila ada yatim (pesan per kolom), NIP kosong diabaikan; rollback → migrate ulang menghasilkan DDL identik. `php -l`, PHP-CS-Fixer, PHPStan L5 lolos pada `MigrationsDitahan/`.

**Belum jalan (M2, PR yang sama, setelah keputusan DBV Bagian 7).** Pemindahan draf ke `Migrations/`, registry NIP (`TabelAnakNip`, `NipCascadeService::FASE` = 4), tiga test skema `tests/database/Layanan/**`, `NipReferenceRegistryTest` diperbarui, gate penuh. Kueri audit tetap belum dijalankan (B2).

**Keputusan & deviasi coder (menunggu DBV).** UNIQUE `pegawai_sisa_cuti (nip, tahun)`, UNIQUE + NOT NULL `petugas_layanan`, NOT NULL `disiplin_ticket_msg.id_inbox` dipasang di CREATE (tabel v2 kosong; normalisasi di runbook impor) — bukan ditahan; FK `disiplin_ticket.nip` ikut ditahan (bukan tanpa FK) agar DBV cukup mencoret satu baris; `petugas_layanan.status` CHECK (0,1,10); `inbox*.created_at` tanpa DEFAULT. Rincian: dokumen Bagian 10 catatan 1–5.

**Blocker.** B2 (audit data produksi) dan B2b (14 DQ) tetap terbuka — menghambat M2, bukan M1. B7 selesai (key dikonfirmasi reviewer: DBV-022, MAKE-022 Tahap 2b).

**Berkas terakhir yang disentuh.** `backend/app/Database/MigrationsDitahan/*Fase4*.php`, `backend/docs/db-review/DBV-022-layanan-fase4-schema.md`, `backend/docs/db-review/sql/DBV-022-audit-layanan.sql`, berkas ini.

---

## M2b — Tahap 2b: library murni cuti (26-10-2026) — DI MAIN 3fbf08e

**Sudah jalan.** Branch `fase4/make-022-cuti` dari `main` 02cb088, CI hijau (run 40322925610), fast-forward ke `main`
3fbf08e (izin Horii). `Config\Cuti` (§6.3.1) + 10 kelas murni `backend/app/Libraries/Layanan/Cuti/` (`AturanCuti`,
`StatusCuti`, `SaldoCuti`, `HitungLamaCuti`, `PorsiTahunCuti`, `ValidasiTanggalCuti`, `KuotaCuti`, `TransisiCuti`,
`EfekFinalCuti`, `PengingatCuti`) + 5 test `backend/tests/unit/Layanan/Cuti/` (86 test, 1065 asersi): `CutiMurniTest`
(pemindai kemurnian + paritas `Config\Cuti` = `AturanCuti::bawaan()`), `HitungLamaCutiTest` (K22–K24, T1, T4–T8, paritas
`HariKerja` Tukin), `KuotaBoundaryTest` (K1–K21, T9, T11–T14), `ValidasiTanggalCutiTest` (T2, T3, T7, T8),
`TransisiCutiTest` (T4–T10, T16–T18, single end node). README `Libraries/Layanan/README.md`. Tanpa
Model/controller/route/migration (§6.1.4).

**Keputusan coder — disetujui Horii 26-10** (dicatat di PRD v1.1 §14): batas `tgl_akhir ≤ 31-12 tahun acuan + 1` hanya
jenis 1; paritas T13 hanya saldo ternormalisasi legacy; teks pesan batas jenis 5/6 [V2]; berkas ke-10 `AturanCuti`.

**Belum jalan.** M3 (service ber-DB, AL/PyB, tumpang tindih, notifikasi) — menunggu DBV-022 (PR #29) disetujui dan M2 merged.

---

## M2 — migration Modul C aktif (27-10-2026, PR #29)

**Sudah jalan.**
- 9 berkas `2026-10-26-100000..100800` dipindah dari `MigrationsDitahan/` ke `Migrations/` (nama & DDL tetap).
- `AddFkNipLayananFase4.php` **tetap** di `MigrationsDitahan/` sampai audit yatim lingkungan target = 0 (keputusan reviewer CR 27-10, PRD §6.1 / AC-01.4).
- Registry NIP Fase 4: `NipCascadeService::FASE` = 4, `TabelAnakNip` 88 entri, jenis baru `DaftarNipBerpemisah` untuk `riwayat_cuti.ketua_tim` (DQ-15, usulan coder menunggu verifikasi delta DBV; perilaku tepi di DBV-022 Bagian 12.3).
- Test skema: `tests/database/Layanan/{SkemaLayananTest,SkemaInboxTicketTest,MigrasiLayananSiklusTest}.php`.

**Verifikasi 27-10:** 3307 — 100/204/45 → 120/235/84 (+20/+31/+39), rollback → migrate ulang identik; kueri audit bebas error 1267/1271 (DBV-022 Bagian 12.5–12.6); CI hijau `bc3b759`.

---

## M3 — Cuti (MAKE-022, 27-10-2026, branch `fase4/make-022-cuti-m3`)

**Sudah jalan.** CRUD cuti, `cuti/opsi`, `cuti/hitung-lama`, kuota/potong/refund (`KuotaCutiService` membungkus `KuotaCuti` murni; refund memakai `SnapshotSaldoCuti`),
state machine (`CutiApprovalService` memakai `TransisiCuti`), efek final (`EfekFinalCutiService`: saldo, CLTN, titik panggil `PenerbitSuratCutiInterface` no-op untuk M4),
tunda + pengingat + listener `ResetTundaCuti` (event `auth.login` aditif), proses atas nama (`ApproverTerjangkau`), `pa_layanan`, kelola saldo, `SaldoTahunanCuti` +
command `cuti:saldo-tahunan`. Route di `Config/RoutesLayanan.php`; kontrak di `Controllers/Api/Layanan/README.md`; kueri audit approver di `04-Layanan-audit-approver-cuti.sql`.

**Keputusan [V2] (disetujui reviewer 27-10).**
1. `audit_logs.event` ber-ENUM: penanda `cuti.saldo_tahunan`, `cuti.saldo_massal`, `cuti.process_atas_nama`, `cuti.cltn_aktif`/`cuti.cltn_pulih` dibawa di `after_json.jenis_audit`
   (event `create`/`update`); tanpa perubahan skema.
2. `RiwayatCutiModel extends BaseAuditableModel` (bukan `BaseSnapshotModel`): cuti tidak punya tabel snapshot; efek final eksplisit lewat query builder + audit manual.
3. Zona bisnis = `Asia/Jakarta` (`TanggalBisnis::ZONA`), bukan `appTimezone` (bawaan UTC): "hari ini", batas tunda 12:00, dan tahun acuan (`created_at` UTC → WIB).
4. Command `cuti:saldo-tahunan` menolak eksekusi tulis tahun < `Config\Cuti::$tahunTulisSaldoMulai` (2027); `--dry-run` tetap untuk 2026 (PRD §6.3.6).
5. Notifikasi cuti bukan bagian M3 (M4 memakai `NotifikasiLayananInterface`).

**Catatan yang perlu dicek reviewer.** Tambah saldo target `1` dibuat massal dengan satu baris audit ringkasan (bukan per baris); target `2` per baris lewat model. `GET cuti/opsi`
mengembalikan `ketua_tim[]` seluruh pegawai aktif sesuai PRD (daftar panjang; FE mungkin perlu pencarian di M7).

**Rujukan legacy AL/PyB (untuk QA).** `AtasanLangsungCuti` = port `getAL_es2()` (`1_legacy_simpeg.kemenparekraf.go.id/application/helpers/function_helper.php:1700-1945`);
`PejabatBerwenangCuti` = port `getListPYB()` (`application/libraries/hr/rwy/L_cuti.php:12-279`); `ApproverTerjangkau` tidak punya padanan legacy (PRD §6.4.10).
`Lkh\AtasanLangsung::untuk()` (port `getAL()` `function_helper.php:1479`) TIDAK dipakai karena perilakunya berbeda: urutan kolom atasan
`es4_koord → es4 → es3_koord → es3 → es2 → es1` (cuti: `es4 → es3 → es2 → es1` tanpa koordinator), tanpa prioritas Plh (cuti: Plh aktif mengganti pemegang definitif,
`function_helper.php:1760-1790`), dan Plt tanpa batas tanggal (cuti: Plt wajib dalam rentang tanggal, `:1795-1810`). Kontrak LKH beku, jadi tidak diubah.

**Ukuran `GET cuti/opsi` → `ketua_tim[]`** (semua pegawai aktif, `{nip, nama}` terurut nama, satu kueri): pada data seed sintetis 8 pegawai respons `ketua_tim` ≈ 0,6 KB;
per elemen ≈ 90 byte, sehingga 5.000 pegawai ≈ 450 KB sebelum kompresi. Dibuka CR terpisah bila terasa berat di data nyata.
## M5 — TB, IB, PMK (MAKE-024, 27-10-2026, branch `fase4/make-024-tb-ib-pmk-m5` dari main `598a5f3`)

Inti M5 saja (§9): penyambungan notifikasi menunggu M4; e-sign, frontend, `ACTIVE_PHASE` di luar cakupan; tanpa migration.
DB uji scratch `simpeg_v2_t_make024` port 3307.

**Keputusan reviewer CR 27-10 (dicatat [V2], masuk PRD revisi berikutnya):**
1. Route TB/IB/PMK di berkas sendiri `Config/RoutesLayananStudi.php` (+ satu baris `Routing::$routeFiles`); cuti M3 di
   `RoutesLayanan.php`. PRD §5 menyebut satu berkas `RoutesLayanan.php`.
2. Titik sambung notifikasi `App\Interfaces\Layanan\NotifikasiLayananInterface` + `NotifikasiNoOp` +
   `Config\Services::notifikasiLayanan()` — milik M5; binding diganti NotifikasiService di M4 (MAKE-023).
3. Slot berkas TB/IB di `document_attachment.tag` (nama kolom legacy), pola Arsip Data Umum; kolom `file_*` impor dianggap
   slot berisi; ganti slot = hapus lama + simpan baru dalam satu transaksi; kode satu-slot tanpa `tag`.

**Sudah jalan — C-07 Tugas Belajar.** Route (12) + baris `KontrakMatriksRoleTest` (prefiks `tb`, `TbService::ROLE_ROUTE`);
`TbController`; service `LayananStudi`/`StudiPengajuanService`/`StudiApprovalService`, `Tb/{TbService, TbApprovalService,
PerpanjanganTbService, StatusTugasBelajar, SuratTbService, EfekTbTrait}`, `PetugasLayananService`, `PenandaTanganKabiro`;
model `Models/Layanan/{LayananModel, RiwayatTbModel, RiwayatTbPerpanModel, RiwayatIbModel}`; murni baru
`Studi/{BerkasStudi, TampilanStudi}`; view `Views/pdf/surat_tb.php`; `Config\Services::statusTugasBelajar()`. Test
`tests/Layanan/Tb/{TbCrudTest, TbApprovalTest, TbPerpanjanganTest, BebasPresensiPenuhTest, TbRoleTest, TbSingleEndNodeTest}`
(49 test). Dokumentasi endpoint: `Controllers/Api/Layanan/README.md`; keputusan coder: `Libraries/Layanan/README.md`.

**Sudah jalan — C-07 TB:** SHA `944f358`, CI run 40460415030 hijau.

**Sudah jalan — C-08 Izin Belajar.** Route (9) + baris kontrak (prefiks `ib`, `IbService::ROLE_ROUTE`); `IbController`;
`Ib/{IbService, IbApprovalService, SuratIbService}`; view `Views/pdf/surat_ib.php`. Test `tests/Layanan/Ib/{IbCrudTest,
IbApprovalTest, IbRoleTest, IbSingleEndNodeTest}` (20 test).

**Sudah jalan — C-08 IB:** SHA `df3b43b`, CI run 40461106005 hijau.

**Sudah jalan — C-09 PMK.** Route (12) + baris kontrak (prefiks `pmk`, `DasarPmkService::ROLE_ROUTE`); `PmkController`;
`Pmk/{DasarPmkService, PmkPengajuanService, PmkApprovalService, RiwayatPmkService, TampilanPmk}`; model
`RiwayatPmkModel`, `RiwayatPmkReqModel`. Test `tests/Layanan/Pmk/{PmkPengajuanTest, PmkApprovalTest, PmkRoleTest,
PmkSingleEndNodeTest, RiwayatPmkTest}` (32 test).

**Review TB (reviewer CR 27-10):** tahap TB diterima. Deviasi #3 (efek `id_jenis_status` per tanggal inklusif, legacy
`L_tb.php:1169-1179` dan `:746-757` membandingkan dengan jam sekarang) **disetujui Horii** [V2]; #2, #4–#7 diterima [V2]
(masuk PRD v1.4); #1 pemindai kemurnian diganti daftar eksplisit berkas murni/lapisan DB (sama dengan keputusan M3).

**Catatan gap (tidak dikerjakan M5, menunggu keputusan Horii):** TB yang disetujui sebelum `tgl_mulai`, atau TB yang sudah
berakhir, tidak mengubah `pegawai.id_jenis_status` secara otomatis (tanpa cron) — sama dengan legacy. Konsumen Fase 5
memakai `bebasPresensiPenuh`, bukan `id_jenis_status`.

**[V2] Unduh berkas pengajuan PMK** (gap PRD §5.4, disetujui Horii 27-10-2026): `GET pmk/pengajuan/{id}/berkas/{kolom}`
(role 1, 2, 3; hak = lihat detail; whitelist empat kolom; traversal/path impor tanpa berkas → 404). Legacy membuka lewat
tautan langsung (`views/hr/employee/rwy/pmk/req/admin/form.php:142-214`, `pathToSrc()` `helpers/function_helper.php:314`).
Test `tests/Layanan/Pmk/PmkBerkasTest.php`.

**Review final M5 (reviewer CR 27-10):** `e70cff6` diterima.

**Belum jalan.** Penyambungan notifikasi (M4), gate penuh.

### Butir runbook cutover dari M5 (catatan reviewer CR 27-10-2026, setelah M5 masuk main `ffee734`)

1. **Migrasi berkas legacy ke storage v2.** Path impor legacy (arsip remote) di `riwayat_tb.file_sp/file_1..3/file_surat_tb`,
   `riwayat_tb_perpan.file_sperpan`, `riwayat_ib.file_sp/file_1/file_2/file_surat_ib`, dan `riwayat_pmk_req.file_kontrak/
   file_paklaring/file_surat_phk/file_slip_gaji` belum ada di storage v2, sehingga unduhnya menjawab **404** (pola Konket,
   PRD §7.4). Berkas baru bisa dibuka setelah dipindah ke storage v2 saat cutover: TB/IB ke `document_attachment` kode
   46–50 dengan `tag` slot (Q-21, §6.1.2 #6); PMK req tetap di kolom dengan path relatif akar storage v2 (segmen hanya
   `[A-Za-z0-9._-]`, tanpa `..`).
2. **Validasi jenis berkas saat migrasi.** Unduh menyajikan mime dari isi berkas dan mendukung `inline=1`. Unggahan v2 aman
   karena `ValidasiBerkas` membatasi jenis dari isi (TB/IB `jpg jpeg png gif pdf doc docx`, PMK `jpg jpeg png gif pdf`;
   HTML/SVG ditolak — diverifikasi 27-10 untuk HTML-sebagai-pdf, SVG, `.html`, SVG-sebagai-png pada PMK/TB/IB). Berkas
   legacy yang dipindah **tidak** melewati validasi itu: skrip migrasi wajib menyaring jenis dari isi dengan whitelist yang
   sama (atau menyisihkan berkas di luar whitelist untuk ditinjau) agar tidak ada HTML/SVG yang tersaji inline.

**Perbaikan review 27-10 (balapan).** Pemeriksaan yang bergantung keadaan diulang di bawah lock pegawai: `ubah` (status 0, pemilik, `tgl_mulai`), `hapus` (hak hapus pemilik 0/2), `simpan`
(tumpang tindih + kuota, termasuk input admin status 1), `process` (NIP pemohon dibaca ulang setelah lock). Seam uji: `CutiService::sebelumTransaksi()` (kosong di produksi), dipakai `CutiBalapanTest`.
Saldo massal memakai jalur PROSES `PegawaiScope`; ubah `pa_layanan` role 3 menegakkan unit sendiri; `tunda` hanya pada status 0/3; alasan setujui tidak disimpan di `reason_note_al`
(T4 = NULL) dan alasan atas nama non-tolak hanya di audit (`jenis_audit`).

**Batasan diketahui.** Baris dengan AL terisi dan PyB kosong: `ResetTundaCuti` tidak mereset `skip_pyb` bagi AL (dampak rendah; PyB wajib saat ajukan, hanya data impor yang bisa begitu).
