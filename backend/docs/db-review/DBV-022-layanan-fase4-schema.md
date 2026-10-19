# DB Validator Review — DBV-022 skema Modul C Layanan Fase 4 + pemisahan `inbox` (notifikasi) vs `disiplin_ticket` (ticketing)

**Key review:** `DBV-022`. Judul PR yang direncanakan: `[DBV-022] Skema Modul C Fase 4: layanan, notifikasi inbox, ticketing
disiplin_ticket`. Branch `fase4/dbv-022-skema-layanan` (dari `main` `6806c38`). Alur: `[DBV]` (+`[MAKE]` bila ada kode) → PR →
DB Validator review/approve → reviewer CR merge (AGENTS.md §2, PRD §6.1.4).

**Status:** 🚧 **DRAF TIDAK LENGKAP — BELUM SIAP DIREVIEW.** Bagian 2 (DDL per tabel) belum dapat ditulis karena sumber
**D1** (`simpeg01_struktur_lengkap_20261001.sql`) tidak tersedia di lingkungan coder; lihat **Bagian 9 (Blocker)**. Dokumen ini
**bukan** "review DB Validator" dan **belum** disetujui siapa pun. Bagian yang sudah final di bawah seluruhnya diturunkan dari
`docs/fase4/PRD_Fase4.md` v1.0 (18-10-2026) yang sudah disetujui user/reviewer CR, bukan dari tafsiran coder.

**Asal kebutuhan:** PRD Fase 4 §6.1 (C-01), keputusan user **D18** (satu unit review untuk seluruh tabel Modul C) dan **D10**
(pemisahan notifikasi vs ticketing). Prinsip #4 Mapping Migrasi yang menyebut `inbox` dipakai untuk notifikasi dikoreksi di sini
(lihat Bagian 3); revisi dokumen Mapping sendiri = Q-19, di luar coder.

**Rujukan:** `B-01-B-02-pegawai-riwayat-schema.md` (DBV-012/013: konvensi K1, G2, V1–V5, aturan snapshot);
`DBV-019-fk-g02-pegawai-schema.md` (pola `app/Database/MigrationsDitahan/` + pra-cek yatim fail-closed);
`DBV-020-skema-tambahan-fase3.md` (pola unit skema gabungan, `down()` fail-closed);
`DBV-011-runbook-impor-master-zona-waktu.md` (runbook impor tunggal, klasifikasi WAJIB_0/INFO).
**D1** = dump struktur produksi `simpeg01` 01-10-2026 (`simpeg01_struktur_lengkap_20261001.sql`, **di luar repo**).
Kode legacy = folder aplikasi SIMPEG legacy (`application/…`, **di luar repo**; rujukan `berkas:baris`).

**Label sumber:** **[K]** terkonfirmasi DDL D1 / kode legacy · **[V2]** keputusan v2 yang disengaja (alasan ditulis)
· **[I]** inferensi dari kode bila DDL tidak eksplisit (wajib disebut).

---

## 0. Ringkasan

Satu unit review untuk **21 butir** (PRD §6.1.1, D18): 9 tabel layanan, 4 tabel notifikasi/ticketing, 5 master pendukung,
`esign_hist`, `firebase_token`, `question_category`, dan penambahan kode `jenis_rwy`.

| # | Kelompok | Tabel / butir | Sifat |
|---|---|---|---|
| A | Cuti | `riwayat_cuti`, `riwayat_cuti_notif_kt`, `pegawai_sisa_cuti`, `jenis_cuti` | CREATE [K] D1 + deviasi |
| B | TB / IB | `riwayat_tb`, `riwayat_tb_perpan`, `riwayat_ib` | CREATE [K] D1 + deviasi |
| C | PMK | `riwayat_pmk`, `riwayat_pmk_req` | CREATE [K] D1 + deviasi |
| D | **Notifikasi (baru)** | `inbox` v2, `inbox_msg` v2 | CREATE **[V2] desain baru** — satu arah |
| E | **Ticketing (Fase 8)** | `disiplin_ticket`, `disiplin_ticket_msg`, `question_category` | CREATE [K] rename dari `inbox`/`inbox_msg` legacy |
| F | Petugas / aturan approval | `jenis_layanan`, `petugas_layanan`, `pa_layanan`, `jenis_pa` | CREATE [K] D1 + deviasi |
| G | Integrasi (dimajukan) | `esign_hist`, `firebase_token` | CREATE [K] D1 + deviasi |
| H | Kode | `jenis_rwy` kode baru 43..49 (usulan) | Migration **baru** (bukan edit seed lama) |

**Inti keputusan yang diminta:** Bagian 3 (pemisahan `inbox` vs `disiplin_ticket`) dan Bagian 7 (DQ-3, DQ-4, DQ-6, DQ-7, DQ-9,
DQ-10, DQ-12, DQ-16, DQ-17, DQ-18, DQ-20, DQ-21, nomor `jenis_rwy`) = PRD §12 **Q-20**.

**Tabel yang TIDAK dibuat** (PRD §6.1.1): `d_riwayat_cuti` (arsip trigger — diganti `audit_logs`), seluruh cadangan bertanggal
(`riwayat_cuti_2020…`, `riwayat_tb_20201204`, `riwayat_ib_20201213`, `pa_layanan_20211122`, `petugas_approval_20211122`,
`inbox_msg_20251027`, `pegawai_sisa_cuti_20xx`), `layanan`/`layanan_pegawai`/`riwayat_layanan`, antrean push legacy
(`fb_pn_queue`, `andr_notif_queue`, `andr_topic_queue`, `firebase_message_logs`), `fb_token`, `email_notification`,
`support_officer`/`officer_category`/`help_desk_contact` (Fase 8).

---

## 1. Konvensi yang dipakai

Diambil dari DBV-012/013 Bagian 1 dan PRD §2.6; berlaku ke seluruh tabel unit ini.

| Kode | Konvensi | Akibat di DBV-022 |
|---|---|---|
| K1 | PK `pegawai` = `nip` VARCHAR(30) `utf8mb4_unicode_ci`; ganti NIP lewat B-06 (`NipCascadeService`), bukan cascade DB | Semua FK ke `pegawai` RESTRICT; semua kolom NIP ber-FK VARCHAR(30) (V4); registry NIP Bagian 4 |
| G2 | Collation tabel `utf8mb4_unicode_ci` (tidak ditulis per kolom); `AUTO_INCREMENT=<n>` dan `ROW_FORMAT` tidak ditulis; kolom audit diisi aplikasi (UTC, `id_pengguna`) | Tabel D1 bercollation `utf8mb4_0900_ai_ci` (`inbox`, `inbox_msg`, `pa_layanan`, `question_category`, `firebase_token`) dikonversi; counter AUTO_INCREMENT disalin saat impor (Bagian 5) |
| V1 | Aksi FK D1 `CASCADE`/`SET NULL` → **RESTRICT/RESTRICT**, nama FK legacy dipertahankan | Termasuk `riwayat_tb_perpan` → `riwayat_tb` (D1 CASCADE): hapus anak dikerjakan service dalam transaksi yang sama (PRD §6.7.4) |
| V2 | CHECK domain status/flag ditambahkan | Daftar CHECK per tabel di Bagian 2; nilai di luar domain dinormalkan saat impor setelah audit Bagian 6 |
| V3 | Trigger legacy **tidak dibawa**; logikanya pindah ke service | `riwayat_cuti_beDel` (arsip) → `audit_logs` `delete` dengan `before` (D6); `riwayat_cuti_beUpd`, `riwayat_tb`/`riwayat_ib` `beUpd` (`show_notif`) → `NotifikasiService` (PRD §6.6.2); `riwayat_tb_perpan_af*` (ringkasan MAX tanggal ke induk) → `PerpanjanganTbService`; `inbox_msg_afIns` → Fase 8 (dicatat, tidak dibuat) |
| V4 | Kolom NIP ber-FK VARCHAR(50)/(18) → **VARCHAR(30)** | `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui` (50), `petugas_layanan.nip` (50), `pa_layanan.nip` (50), `disiplin_ticket.nip` (18) — diaudit `MAX(CHAR_LENGTH(...))` (Bagian 6) |
| V5 | Kolom FK ke master Fase 2/3 disamakan tipenya dengan PK master v2 | `riwayat_tb.id_jenjang_pendidikan`, `riwayat_ib.id_jenjang_pendidikan` TINYINT → INT (PK `jenjang_pendidikan` v2 INT) |
| Status | Master 1/2/10; status riwayat/layanan **ikut produksi**: cuti 0/1/2/3/4, TB/IB 0/1/2/3, PMK req 0/1/2/3 | Cuti **tanpa** 10 (hapus fisik, D6) |
| K3 | Migration yang sudah ada di `main` **tidak diedit**; koreksi lewat migration baru | Kode `jenis_rwy` baru = migration terpisah, bukan edit `2026-09-30-130000_CreateJenisRwy.php` |
| MigrationsDitahan | FK yang datanya bisa yatim ditaruh di `app/Database/MigrationsDitahan/` dengan pra-cek yatim **fail-closed** (pola DBV-019/DBV-020) | Usulan untuk FK approver `riwayat_cuti` (DQ-3) dan FK NIP tabel yang NIP-nya tanpa FK di legacy |

---

## 2. DDL per tabel

> ⏳ **BELUM DITULIS — menunggu D1.** PRD §6.1.1 memuat *ringkasan* kolom inti dan usulan v2 per tabel, tetapi §6.1.2 butir 3
> mewajibkan bagian ini memuat **DDL D1 apa adanya (baris)** + DDL v2 usulan + tabel deviasi per kolom/index/FK/CHECK.
> Tipe dan panjang sebagian besar kolom (mis. `riwayat_cuti.nama`, `pangkat`, `jabatan`, `satker`, `keterangan`,
> `reason_note*`, `file_*`; seluruh kolom snapshot `riwayat_tb`/`riwayat_ib`) **tidak** tercantum di PRD. Menuliskannya tanpa D1
> berarti menebak, yang dilarang PRD ("Cara pakai" #3). Lihat Bagian 9.
>
> Yang **sudah pasti** dan tidak menunggu D1 tercantum di Bagian 1 (konvensi per tabel), Bagian 3 (`inbox` v2 dan
> `inbox_msg` v2 = desain baru, sepenuhnya ditentukan PRD), Bagian 4, 5, 6, 7, dan 8.

Kerangka yang akan diisi per tabel begitu D1 tersedia (urutan = PRD §6.1.1 #1..#21):

1. `riwayat_cuti` · 2. `riwayat_cuti_notif_kt` · 3. `jenis_cuti` · 4. `pegawai_sisa_cuti` · 5. `riwayat_tb` ·
6. `riwayat_tb_perpan` · 7. `riwayat_ib` · 8. `riwayat_pmk` · 9. `riwayat_pmk_req` · 10. `disiplin_ticket` ·
11. `disiplin_ticket_msg` · 12. `inbox` (v2) · 13. `inbox_msg` (v2) · 14. `question_category` · 15. `jenis_layanan` ·
16. `petugas_layanan` · 17. `pa_layanan` · 18. `jenis_pa` · 19. `esign_hist` · 20. `firebase_token` · 21. kode `jenis_rwy` baru.

Tiap butir berisi: DDL D1 (baris) → DDL v2 usulan → tabel deviasi `[K]/[V2]/[I]` per kolom/index/FK/CHECK + alasan →
perlakuan trigger legacy → risiko impor.

---

## 3. Pemisahan `inbox` (notifikasi) vs `disiplin_ticket` (ticketing) — **bagian inti**

Dasar: keputusan user **D10**; DoD C-01 butir 064-2/064-3/064-6; PRD §6.1.1 #10–#13, §6.6, §7.1.

### 3.1 Temuan T1 — mengapa dipisah

Tabel `inbox`/`inbox_msg` **legacy** bukan tabel notifikasi: isinya 100% tiket Halo Simpeg dua arah (balasan, status
open/closed, `allow_reply`, `closed_by`, penugasan petugas) — rujukan kode `L_support.php:997, 1006, 1169, 1341`. Notifikasi
legacy sendiri tidak punya tabel: ia ditempel sebagai kolom `show_notif`/`notif_date` di tiap tabel riwayat ditambah trigger DB
dan antrean push (`andr_notif_queue` dkk.) — temuan N1/N2.

Memakai satu tabel untuk keduanya akan memaksa notifikasi membawa kolom tiket (status open/closed, balasan) yang tidak punya
arti bagi notifikasi, dan sebaliknya membuat tiket Halo Simpeg (Fase 8) bergantung pada tabel yang dipakai alur approval. Karena
itu D10 membelah keduanya **sejak C-01**, dan DoD mewajibkan kedua skema direview **sekaligus** di sini walau ticketing baru
dipakai Fase 8.

### 3.2 `inbox` v2 + `inbox_msg` v2 — notifikasi satu arah [V2]

Desain baru (tidak ada padanannya di D1). Usulan PRD §6.1.1 #12/#13:

**`inbox`** — satu induk per penerima per pengajuan.

| Kolom | Tipe usulan | Catatan |
|---|---|---|
| `id_inbox` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `nip_penerima` | VARCHAR(30) NOT NULL | FK → `pegawai(nip)` RESTRICT (K1/V4); registry NIP Bagian 4 |
| `id_pengguna_penerima` | INT NULL | Opsional — DQ-4: penerima diidentifikasi NIP, `id_pengguna` atau keduanya |
| `modul` | VARCHAR(20) NOT NULL | `cuti` \| `tb` \| `ib` \| `pmk`; CHECK domain [V2] |
| `id_jenis_layanan` | TINYINT NULL | FK → `jenis_layanan` RESTRICT; NULL untuk cuti (cuti bukan `jenis_layanan`) |
| `id_rwy` | BIGINT NOT NULL | Id baris sumber; **tanpa FK** (polimorfik lintas 4 tabel) |
| `judul` | VARCHAR(150) NOT NULL | |
| `url` | VARCHAR(255) NOT NULL | Rute FE tujuan |
| `is_read` | TINYINT NOT NULL DEFAULT 0 | CHECK 0/1 |
| `read_at` | DATETIME NULL | |
| `created_at` | DATETIME NOT NULL | UTC, diisi aplikasi (G2) |

Index: `(nip_penerima, is_read, created_at)`; usulan tambahan `(modul, id_rwy)` untuk penelusuran per pengajuan.

**`inbox_msg`** — satu baris per kejadian di bawah satu `inbox`, **tanpa balasan**.

| Kolom | Tipe usulan | Catatan |
|---|---|---|
| `id_inbox_msg` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `id_inbox` | BIGINT UNSIGNED NOT NULL | FK → `inbox` RESTRICT |
| `tipe` | TINYINT NOT NULL | 1 diajukan, 2 keputusan, 3 perubahan, 4 tembusan; CHECK |
| `status_sumber` | TINYINT NOT NULL | Status baris sumber saat kejadian (AC-06.1) |
| `isi` | VARCHAR(500) NOT NULL | Templat §6.6.2 |
| `reason_note` | VARCHAR(255) NULL | Alasan tolak (Q-23: ≤ 255 byte) |
| `push_status` | TINYINT NOT NULL DEFAULT 0 | 0 antre / 1 terkirim / 2 gagal / 3 tanpa token; CHECK |
| `created_at` | DATETIME NOT NULL | UTC |

**Larangan yang diuji otomatis (AC-01.2).** `inbox` dan `inbox_msg` v2 **DILARANG** memiliki kolom: `status` (open/closed),
`allow_reply`, `closed_by`, `is_read_off`, `notify_pic`, serta kolom balasan/thread apa pun. `SkemaInboxTicketTest`
menegaskan **ketiadaan** kolom-kolom itu, bukan sekadar keberadaan tabel.

DB Validator boleh menyederhanakan rancangan di atas, **asalkan** dua tabel tetap ada dan tetap satu arah (DoD 064-2).

### 3.3 `disiplin_ticket` + `disiplin_ticket_msg` — ticketing dua arah [K], skema final Fase 8

Rename 1:1 dari `inbox`/`inbox_msg` **legacy** (D1:1369-1393 dan D1:1398-1414), dengan penyesuaian konvensi: collation
`utf8mb4_0900_ai_ci` → `utf8mb4_unicode_ci` (G2), FK RESTRICT (V1), `nip` VARCHAR(18) → VARCHAR(30) (V4), CHECK domain (V2),
dan `disiplin_ticket_msg.id_inbox` NULL → NOT NULL [V2] bila audit Bagian 6.7.4 menunjukkan 0 baris NULL.

Kolom pembeda yang **wajib tetap ada** di sini (dan dilarang ada di `inbox` v2): `status` 0 Open / 1 Closed, `allow_reply`,
`closed_by`, `is_read_off`, `notify_pic`, `notify_at`, `receiver`/`receiver_name`, `msg_sender`/`msg_receiver`, `attachments`.

Fase 4 **hanya membuat skemanya**; tidak ada endpoint, model, UI, maupun baris data ticketing yang ditulis Fase 4
(PRD §1.2). `question_category` ikut dibuat karena dirujuk FK `disiplin_ticket`.

### 3.4 Rencana impor (konsekuensi pemisahan)

- Seluruh isi `inbox`/`inbox_msg` **legacy** → `disiplin_ticket`/`disiplin_ticket_msg` (dikerjakan Fase 8).
- `inbox` v2 **dimulai kosong**. Pengisian dari `riwayat_cuti_notif_kt`/`show_notif` legacy = opsi yang harus diputuskan
  DB Validator bersama F-02 (sortir impor inbox, Fase 7); usulan coder: **tidak** diimpor (notifikasi bersifat sesaat,
  riwayat keputusan sudah ada di `riwayat_*` + `audit_logs`).
- Regression Fase 4: setelah seluruh skenario cuti/TB/IB/PMK dijalankan, `disiplin_ticket` dan `disiplin_ticket_msg`
  **= 0 baris** (AC-06.4).

---

## 4. Registry NIP

Aturan AGENTS/PRD §2.6: setiap kolom NIP baru wajib diklasifikasi di `backend/app/Libraries/Kepegawaian/Nip/TabelAnakNip.php`
dan diuji `backend/tests/database/Kepegawaian/NipReferenceRegistryTest.php`; cakupan koreksi NIP
(`backend/app/Libraries/Kepegawaian/NipCascadeService.php`) diperluas ke tabel Fase 4, dengan konstanta
`NipCascadeService::FASE` dinaikkan ke **4** (test memakai `sampaiFase(NipCascadeService::FASE)`, bukan angka tetap, agar
Fase 5A dapat menaikkannya ke 5 tanpa konflik).

| Kolom | Tabel | Usulan klasifikasi |
|---|---|---|
| `nip` | `riwayat_cuti`, `riwayat_tb`, `riwayat_ib`, `riwayat_pmk`, `riwayat_pmk_req`, `pegawai_sisa_cuti`, `esign_hist`, `firebase_token` | FK v2 (RESTRICT); `esign_hist.nip` nullable |
| `nip_atasan_langsung`, `nip_yang_menyetujui` | `riwayat_cuti` | FK v2 **ditahan** sampai audit yatim = 0 (DQ-3); sampai itu diperlakukan NON_FK + ikut cascade |
| `nip_kt` | `riwayat_cuti_notif_kt` | FK v2 (bagian PK komposit) |
| `nip` | `petugas_layanan`, `pa_layanan` | FK v2 atau NON_FK — DQ-12/DQ-17 |
| `nip` | `disiplin_ticket` | NON_FK atau FK ditahan — DQ-4/DQ-6 |
| `ketua_tim` | `riwayat_cuti` | **Jenis registry baru**: TEXT berisi banyak NIP dipisah `***` (DQ-15). Cascade harus mengganti elemen di dalam string, bukan seluruh nilai kolom |

`riwayat_cuti.ketua_tim` adalah satu-satunya kolom multi-NIP di repo sampai saat ini; bentuk jenis registrinya diputuskan
DB Validator. Audit elemen non-NIP: Bagian 6 butir 9.

---

## 5. Urutan impor (J1.07)

Digabung ke runbook impor DBV-011. Urutan (PRD §6.1.2 butir 6):

```
jenis_cuti, jenis_layanan, jenis_pa, question_category
  -> petugas_layanan, pa_layanan
  -> pegawai_sisa_cuti
  -> riwayat_cuti -> riwayat_cuti_notif_kt
  -> riwayat_tb -> riwayat_tb_perpan
  -> riwayat_ib
  -> riwayat_pmk_req, riwayat_pmk
  -> esign_hist (utuh — bukti legal, append-only)
  -> disiplin_ticket -> disiplin_ticket_msg
  -> inbox v2 (kosong; lihat 3.4)
```

Counter AUTO_INCREMENT disalin (pola DBV-011 `DBV-011-counter-auto-increment-v2.sql`). Normalisasi nilai di luar domain
dilakukan setelah hasil audit Bagian 6 dibaca DB Validator — bukan diputuskan coder.

---

## 6. Kueri audit baca-saja

Berkas: `backend/docs/db-review/sql/DBV-022-audit-layanan.sql` (10 bagian, hanya `SELECT`, dijalankan di salinan dump —
bukan di produksi legacy dan bukan di DB dev v2).

| Bagian | Isi | Klasifikasi |
|---|---|---|
| 1 | `riwayat_cuti` NIP approver: yatim, kosong, panjang > 30 | WAJIB_0 (prasyarat aktivasi FK, DQ-3) |
| 2 | Distribusi `status`, `status_al`, `status_pyb`, `rated`, `skip_*`, `status_regen`, `id_jenis_cuti`, `satuan_lama_cuti` | INFO + prasyarat CHECK |
| 3 | `pegawai_sisa_cuti`: duplikat `(nip, tahun)`, `tahun = 0`, nilai negatif, pelanggaran batas D8 | WAJIB_0 untuk UNIQUE (DQ-10) |
| 4 | Distribusi status TB/IB/PMK/PMK req + `jenis_pmk`; NIP yatim keempat tabel; perpanjangan yatim | WAJIB_0 + INFO (DQ-18) |
| 5 | `petugas_layanan`: NIP yatim/panjang, status di luar {0,1,10}, `id_jenis_layanan` NULL/yatim, duplikat | WAJIB_0 + INFO (DQ-12) |
| 6 | `pa_layanan`: NIP yatim, 11 id master yatim | WAJIB_0 (DQ-17) |
| 7 | `inbox`/`inbox_msg` legacy: kategori NULL/yatim, NIP yatim, domain flag, induk NULL/yatim, tabrakan collation | WAJIB_0 + INFO (DQ-4/DQ-6) |
| 8 | `esign_hist`: status di luar ('1','2'), NIP yatim, panjang `ip_address`; `firebase_token` | INFO (DQ-7) |
| 9 | `riwayat_cuti.ketua_tim`: elemen yang bukan NIP pegawai | INFO (DQ-15) |
| 10 | `riwayat_cuti_notif_kt`: distribusi status, baris yatim | WAJIB_0 + INFO |

Bagian 7.3 (pelaku tiket terhadap tabel pengguna legacy) sengaja dibiarkan **dikomentari**: nama tabel/kolom pengguna di
legacy berbeda dari v2 dan belum dapat dipastikan tanpa D1.

**Hasil audit: belum dijalankan** — tidak ada akses ke salinan dump legacy di lingkungan coder (Bagian 9).

---

## 7. Keputusan yang diminta dari DB Validator

PRD §12 Q-20. Butir yang sudah terjawab keputusan user tidak diajukan ulang.

| # | Pertanyaan | Usulan coder |
|---|---|---|
| DQ-3 | FK `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui`: di CREATE atau ditahan; perlakuan yatim | **Ditahan** di `MigrationsDitahan/` dengan pra-cek fail-closed (pola DBV-019) sampai audit Bagian 6.1 = 0; kolom VARCHAR(30) nullable, snapshot nama/jabatan tetap disimpan |
| DQ-4 | `inbox`/`inbox_msg` v2: penerima `nip` vs `id_pengguna`; `id_inbox` di `disiplin_ticket_msg` dipertahankan/rename | `nip_penerima` sebagai kunci utama penerima (approver & PIC diidentifikasi NIP di seluruh Modul C), `id_pengguna_penerima` opsional; nama kolom legacy `id_inbox` dipertahankan agar impor Fase 8 lurus |
| DQ-6 | FK ke `pengguna` untuk `disiplin_ticket.sender/receiver/closed_by`, `_msg.msg_sender/msg_receiver` (0 = semua petugas) | Tanpa FK (nilai sentinel `0` tidak dapat di-FK-kan tanpa baris khusus); divalidasi aplikasi di Fase 8 |
| DQ-7 | `esign_hist`: tipe `status`, kolom `status_code` [V2], `ip_address` 45 | Pertahankan `status` VARCHAR [K] + CHECK ('1','2'); tambah `status_code` VARCHAR(10) NULL; `ip_address` → VARCHAR(45) (IPv6); index `(modul, id_rw)`; `nik` tetap [K] dengan catatan PII |
| DQ-9 | `jenis_cuti` PK komposit → tunggal + UNIQUE | PK tunggal `id_jenis_cuti` + UNIQUE `nama_jenis_cuti`; id **wajib** 1..6 seperti legacy (Tukin v2 meng-hard-code `StatusHarian::JENIS_CUTI`); seed literal 6 baris di migration (pola `jenis_rwy`); tanpa UI master di Fase 4 |
| DQ-10 | `pegawai_sisa_cuti` UNIQUE `(nip, tahun)`, CHECK ≥ 0 | UNIQUE bila audit duplikat = 0; CHECK ≥ 0 untuk kelima kolom saldo; batas atas D8 ditegakkan aplikasi (§6.3.6), tidak sebagai CHECK, karena data impor bisa melanggarnya |
| DQ-12 | `petugas_layanan.status` domain & FK `nip` | Domain [K] 0/1 dengan CHECK (0,1,10) bila audit menemukan 10; FK `nip` ditahan seperti DQ-3; UNIQUE `(id_jenis_layanan, nip)` bila audit = 0 |
| DQ-16 | Index approval tambahan | Tambahkan `(nip_atasan_langsung, status)` dan `(nip_yang_menyetujui, status)` — dipakai `GET cuti?mode=persetujuan` dan `GET cuti/pengingat` |
| DQ-17 | `id_sub_group_jabatan`, `id_jabatan_al`, `id_jabatan_pyb` tanpa FK | Tanpa FK (nilai historis snapshot; master bisa berubah) |
| DQ-18 | CHECK status TB/IB (0..3) dan default `riwayat_pmk_req.status` | CHECK 0..3; default kolom dipertahankan [K] **atau** diubah ke 0 — aplikasi selalu mengisi eksplisit sehingga keduanya aman; usulan: ikuti [K] agar impor tidak berubah arti |
| DQ-20 | Kolom `file_*` NOT NULL [K] di `riwayat_tb`/`riwayat_ib` sementara berkas v2 di `document_attachment` | Jadikan NULL-able [V2]; nilai impor dipertahankan apa adanya; berkas baru v2 masuk `document_attachment` |
| DQ-21 | Pembeda versi surat (belum TTE vs ber-TTE) | Dua kode `jenis_rwy` terpisah (lebih sederhana dari kolom `tag` baru di `document_attachment`, dan tidak mengubah tabel Fase 3) |
| DQ-jenis_rwy | Nomor kode baru | Usulan 43 `cuti`, 44 `surat_cuti`, 45 `tb`, 46 `surat_tb`, 47 `tb_perpan`, 48 `ib`, 49 `surat_ib`. Kode tertinggi di `main` saat ini = 42 (`pmk`) — tidak bentrok |

---

## 8. Rencana test skema

Berkas (PRD §6.1 file boundary):

| Berkas | Isi |
|---|---|
| `backend/tests/database/Layanan/SkemaLayananTest.php` | `information_schema` per tabel layanan: kolom, tipe, NULL, default, PK, index, FK + aksi, collation, COMMENT; error 1452 (FK), 1451 (hapus induk), 1062 (UNIQUE); CHECK domain |
| `backend/tests/database/Layanan/SkemaInboxTicketTest.php` | **AC-01.2/01.3/01.6**: `inbox`/`inbox_msg` v2 **tidak** punya `status`, `allow_reply`, `closed_by`, `is_read_off`, `notify_pic`; `disiplin_ticket`/`_msg` **punya** `status` open/closed + CHECK; keduanya tabel terpisah |
| `backend/tests/database/Layanan/MigrasiLayananSiklusTest.php` | Siklus `down()`/`up()` tiap migration (`#[Group('db-isolasi-penuh')]`); migration FK ditahan menolak jalan bila ada yatim (fail-closed) |
| `backend/tests/database/Kepegawaian/NipReferenceRegistryTest.php` (diperbarui) | **AC-01.7**: seluruh kolom NIP Fase 4 terklasifikasi, termasuk `ketua_tim`, `nip_kt`, `esign_hist.nip`, `petugas_layanan.nip`, `pa_layanan.nip`, `inbox.nip_penerima`, `disiplin_ticket.nip`, `firebase_token.nip` |

Semua test ber-DB `extends Tests\Support\DatabaseTestCase`; DDL bertanda `#[Group('db-isolasi-penuh')]`; DB scratch
`simpeg_v2_t_dbv022*` di instance MySQL test **port 3307** (`tools/mysql-test/`). **AC-01.8**: `git diff origin/main --
backend/app/Database/Migrations/` hanya berisi berkas baru.

---

## 9. Status & blocker

| # | Blocker | Akibat |
|---|---|---|
| **B1** | **D1 (`simpeg01_struktur_lengkap_20261001.sql`) tidak tersedia di lingkungan coder** — tidak ada di repo (memang tidak boleh), tidak ada di mesin kerja. PRD §6.1.1 hanya memuat ringkasan kolom inti; tipe/panjang/NULL sebagian besar kolom tidak tercantum | **Bagian 2 tidak dapat ditulis; migration (M1 draf dan M2) tidak dapat dibuat.** Menuliskannya = menebak (dilarang) |
| **B2** | Tidak ada akses ke salinan dump legacy untuk **menjalankan** kueri audit Bagian 6 | Hasil audit kosong; keputusan DQ-3, DQ-10, DQ-12, DQ-18, DQ-20 bergantung pada angka ini |
| **B3** | Salinan kode legacy yang ada di mesin kerja (`simpegdev…/application/`) **bukan** versi yang dirujuk PRD (`1_legacy_simpeg…`): nomor baris tidak cocok pada pemeriksaan acak `Cuti.php:584-591`, `L_cuti.php:1440`, `L_cuti.php:2645-2651` | Rujukan `[K] berkas:baris` tidak dapat diverifikasi; relevan untuk M3–M5, belum untuk C-01 |

**Yang dibutuhkan agar C-01 bisa lanjut:** berkas D1 (atau kutipan `SHOW CREATE TABLE` untuk 20 tabel di Bagian 0) dan —
untuk Bagian 6 — hasil kueri audit dari pihak yang punya akses salinan dump.

Sampai B1 terjawab, status C-01 = **IN_PROGRESS**, bukan siap review.
