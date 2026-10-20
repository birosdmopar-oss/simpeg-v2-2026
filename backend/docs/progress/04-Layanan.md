# Progress Fase 4 — Modul C: Layanan & Approval

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). **Acuan utama & sumber kebenaran: `docs/fase4/PRD_Fase4.md` v1.0 (18-10-2026).** Aturan proyek: `AGENTS.md`.

**Terakhir diperbarui:** 20 Oktober 2026 — M1 dokumen **lengkap** (D1 diterima; 21 butir tabel terisi DDL + deviasi). Branch: `fase4/dbv-022-skema-layanan` (worktree, dari `main` `6806c38`). Status CI: perubahan dokumen + satu `.sql` di `backend/docs/` — `.sql` di luar `paths-ignore` sehingga CI terpicu; hasil belum terbaca (tidak ada `gh`, API anonim 403).

**Entry criteria:** **terpenuhi** — Fase 3 DONE (QA fungsional + QA UI Lapis 1 selesai 15-10-2026, temuan ditutup CR-049/050/051/054/055; `backend/docs/progress/03-Kepegawaian.md`). Tabel `pegawai`, snapshot `pegawai_*`, `riwayat_*` Fase 3, `jenis_rwy`, `document_attachment` sudah di `main`.

**Milestone (PRD §9):** M0 pemahaman → M1 dokumen DBV-022 → M2 migration → M3 cuti → M4 e-sign + notifikasi → M5 TB/IB/PMK → M6 dashboard + PIC → M7 frontend → M8 verifikasi.

| Task | Milestone | Key | Status | Ringkas |
|---|---|---|---|---|
| C-01 Migration layanan + pemisahan inbox/ticketing | M1, M2 | DBV-022 | **IN_PROGRESS** | M0 selesai. M1: dokumen `DBV-022-layanan-fase4-schema.md` **lengkap** (21 butir tabel: DDL D1 + usulan v2 + deviasi; pemisahan inbox/ticketing; registry NIP; impor; 27 butir DQ) + `sql/DBV-022-audit-layanan.sql`. **Migration menunggu keputusan 14 butir DQ** (Blocker B4) |
| C-02 Pengajuan cuti | M3 | MAKE-022 | TODO | Menunggu M2 merged |
| C-03 Kalkulasi kuota N/N-1/N-2 + `calculate_lc` | M3 | MAKE-022 | TODO | Menunggu M2 merged; ⛔ Q-02 untuk bagian saldo tahunan |
| C-04 Approval berjenjang cuti | M3 | MAKE-022 | TODO | Menunggu M2 merged; ⛔ Q-07 untuk bagian tunda pengingat |
| C-05 Penerbitan surat cuti e-sign (mock) | M4 | MAKE-023 | TODO | Menunggu M3 merged |
| C-06 Notifikasi approval | M4 | MAKE-023 | TODO | ⛔ DILARANG mulai sebelum DBV-022 disetujui (PRD §6.1.4) |
| C-07 Tugas Belajar | M5 | MAKE-024 | TODO | Inti menunggu M2; penyambungan notifikasi menunggu M4 |
| C-08 Izin Belajar | M5 | MAKE-024 | TODO | idem |
| C-09 PMK | M5 | MAKE-024 | TODO | idem |
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
