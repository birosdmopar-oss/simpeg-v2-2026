# DB Validator Review — DBV-029 keputusan impor cutover tahap 2 (MAKE-069)

**Key review:** `DBV-029`. Keputusan DB Validator atas butir K-M6..K-M10 rencana impor cutover tahap 2
(`docs/migrasi/MAKE-069-rencana.md`, branch `docs/make-069-rencana` @`bd63fe46`, §1–§7). Dokumen ini **hanya keputusan**:
tidak ada perubahan skema dan tidak ada kode. Penerapannya oleh pelaksana MAKE-069 (alat `impor:legacy`).

**Pemutus:** DB Validator — sesi WS-2 atas penugasan Horii 03-11-2026 (sama dengan DBV-027), 03-11-2026.

**Tidak dibuka ulang** (sudah diputus): K-M1, K-M2, K-M3 opsi A (path lampiran tetap legacy + normalisasi baca adapter),
K-M11, K-M12 (Horii/reviewer CR 03-11-2026), Z-1 opsi (c) (DBV-027 §13).

**Sumber:** kode legacy CI3 (di luar repo; berkas rahasia tidak dibuka, pencarian dengan pengecualian berkas konfigurasi),
skema v2 `main` (`information_schema` DB scratch hasil `migrate --all`), dokumen G-03, G-06, G-10, DBV-020, DBV-022,
B-01/B-02, README alat impor (`backend/app/Libraries/Impor/README.md`). Rujukan legacy ditulis `berkas:baris` relatif
`application/`. Label: **[K]** ikut kode/DDL legacy, **[V2]** keputusan v2, **[I]** inferensi.

---

## Ringkasan keputusan

| Kode | Keputusan |
|---|---|
| K-M6 | `dm_user_lokasi_presensi` **DIIMPOR** (bukan dibangun ulang, bukan kosong) — tabel ini adalah aturan G-03 itu sendiri |
| K-M7 | `layanan`, `layanan_pegawai`, `riwayat_layanan` **TIDAK DIMIGRASI** (tabel tidak dibuat di v2), dengan audit jumlah sebelum cutover |
| K-M8 | **Setuju** transaksi per tabel untuk T10/T11, dengan pra-cek seluruh tier di depan, counter sesudah commit tabel, dan `--lanjut` berbasis berkas jejak |
| K-M9 | `old_id` hanya ada di `jenis_konket` → angka sah disalin; NULL/bukan angka/ganda → gagal ke berkas keputusan. `pegawai_hist`: dikonfirmasi `flag_update = 1`, **satu per NIP (baris terbaru)**. FAQ: dikonfirmasi `stripslashes` → `HtmlSanitizer` → `content_stripped` dihitung ulang |
| K-M10 | `waktuBisnis` T7–T13: **tidak ada**. `tanpaStripslashes` T7–T13: **tidak ada**. Rincian per kolom di Bagian 5 |
| K-S1 | Tier SIASN (B3): `siasn_ref_unor`, `siasn_referensi` mentah (`['*']`); `double_riwayat_kp`, `siasn_upload_arsip.arsip_path`/`arsip_name` `stripslashes` bawaan; `siasn_upload_arsip` `resp_*` (5 kolom) + `error_msg` mentah. Rincian di Bagian 6 |

---

## 1. K-M6 — `dm_user_lokasi_presensi` (GAP-4)

**Keputusan: DIIMPOR apa adanya** (tier tersendiri sesudah `lokasi_presensi` [T5 MAKE-056] dan `pegawai`; boleh di T7).

**Alasan.**
- `dm_user_lokasi_presensi` adalah **tabel aturan** G-03 (D1:474-493). Yang *tidak* dibuat di v2 adalah tabel ekspansinya,
  `user_lokasi_presensi` (DBV-020 keputusan #8, `Mapping_Migrasi_Tidak_Dimigrasi.md:32`). GAP-4 mencampur keduanya; tidak ada
  "aturan" lain yang bisa dipakai untuk membangun ulang tabel ini — isinya dibuat admin.
- Pembaca v2: `ResolverLokasiPresensi::untuk()` (port `Lm_lokasi.php:540-735`, `MYC:102-115` [K]) membaca **semua aturan
  status 1** per permintaan. `PresensiOnlineService` memakai `tercakup` sebagai `wajib_geofence`.

**Dampak bila dibiarkan kosong:** tidak ada pegawai yang `tercakup` → **geofence presensi online tidak berlaku untuk
siapa pun**; presensi masuk/pulang diterima dari lokasi mana saja. Ini perubahan perilaku dan celah kebijakan kehadiran
(berdampak ke rekap kehadiran dan Tukin). Karena itu kosong **tidak diterima**.

**Aturan impor:**
| Kolom | Aturan |
|---|---|
| `id_dm_user_lokasi_presensi` | PK legacy disalin, counter ikut (G-03 §3 #7) |
| `target_lp`, `target_uns`, `target_jp`, `target_peg`, `excl_peg` (+ `*_desc`), `keterangan` | `stripslashes` bawaan — legacy menyimpan JSON ber-`addslashes` (G-03 §3 #9, P-4) |
| `status` [V2] | **1** untuk semua baris (legacy tanpa status; aturan yang dihapus legacy sudah hilang fisik) |
| `hari_berlaku` | apa adanya (NULL = setiap hari) |
| `created_at`, `updated_at` | R1a (stempel) |
| `created_by`, `updated_by` | apa adanya (id akun legacy = `id_pengguna` v2, A-01 #10) |

**Audit (INFO, tidak memblokir):** id di `target_lp` yang tidak ada di `lokasi_presensi` (resolver menyaring sendiri);
NIP di `target_peg`/`excl_peg` yang tidak ada di `pegawai` (tanpa FK, disimpan; ikut koreksi NIP MAKE-011); JSON
`target_*` yang tidak bisa diurai sesudah `stripslashes` → **gagal** dengan jumlah (resolver akan mengabaikan aturan itu diam-diam).

---

## 2. K-M7 — `layanan`, `layanan_pegawai`, `riwayat_layanan`

**Keputusan: TIDAK DIMIGRASI** [K + V2]. Tabel memang tidak dibuat di v2 (PRD Fase 4 §6.1.1, DBV-022 baris "Tabel yang TIDAK
dibuat"); keputusan ini menutup status "belum diputuskan".

**Dasar.**
- **Tidak ada penulis** di kode legacy aktif: pencarian `insert/update/replace/delete` atas ketiga tabel di `application/`
  tidak menemukan penulis. Data yang ada adalah sisa fitur lama.
- `layanan_pegawai` hanya **dibaca** untuk pratinjau notifikasi admin (`libraries/hr/L_notification.php:447-458`, status
  `'0'`; `:693-705`, status `'3'` per unit). `L_employee.php:6824-6830` memasukkannya ke daftar lalu langsung `continue`.
  Fungsi notifikasi v2 sudah diganti `NotifikasiService` Fase 4.
- `riwayat_layanan` tidak dipakai kode legacy (B-01/B-02 §1, draf DBV-012 1.3); `layanan` hanya master untuk
  `layanan_pegawai`.

**Syarat cutover:**
1. Sebelum cutover DBA melaporkan **jumlah** baris `layanan_pegawai` berstatus `'0'`/`'3'` (yang masih tampil di notifikasi
   admin legacy). Bila > 0, dilaporkan ke Horii sebagai item yang hilang dari notifikasi (keputusan bisnis, bukan skema).
2. Ketiga tabel ikut cadangan legacy baca-saja yang disimpan sesudah cutover (bukan ke v2).
3. Registry NIP `TabelAnakNip`: `layanan_pegawai` `KeberadaanV2::BelumDiputuskan` → `TidakDiimpor`, dan
   `Mapping_Migrasi_Tidak_Dimigrasi.md:47` diperbarui — bersama kode MAKE-069 (perubahan aditif dokumen/registry).

---

## 3. K-M8 — transaksi per tabel (T10/T11)

**Keputusan: SETUJU**, untuk seluruh tabel T10 dan T11 (dan tabel lain yang ditandai eksplisit, mis. `absen_ijin` bila
diimpor ulang dengan volume besar). Tier lain tetap satu transaksi per tier.

**Alasan:** satu transaksi per tier atas tabel besar (`riwayat_lckh`, `document_attachment`, `absen_ijin`, snapshot
`pegawai_*`) membuat undo log InnoDB sangat besar, rollback lama, dan kunci panjang. Di dalam T10/T11 tidak ada FK antar
tabel kecuali urutan (`document_attachment` → `jenis_rwy` sudah ada; riwayat → `pegawai` dari tier sebelumnya), jadi atomisitas
per tabel cukup.

**Aturan (wajib):**
1. **Pra-cek seluruh tier di depan**, sebelum commit apa pun (sama dengan sekarang): semua tabel tier kosong, pra-cek
   UNIQUE, berkas keputusan lengkap. Gagal pra-cek → tidak ada yang ditulis.
2. **Satu transaksi per tabel**: tabel gagal → tabel itu di-rollback **utuh** (tidak pernah setengah terisi).
3. **Counter `AUTO_INCREMENT`** disalin segera sesudah commit tabel itu (DDL = commit implisit, wajib di luar transaksi).
4. **Gagal di tengah tier:** tabel yang sudah selesai tetap ter-commit; proses berhenti dengan exit ≠ 0; laporan menyebut
   tabel `selesai` / `gagal` / `belum` (jumlah saja). Tidak ada penghapusan otomatis.
5. **Berkas jejak** (di folder kerja, bukan repo; satu per DB tujuan): per tabel selesai dicatat `tier`, `tabel`, jumlah baris
   tujuan, PK maks, ringkasan berkas keputusan yang dipakai.
6. **Pengulangan:**
   - `--tier N` tanpa opsi baru: perilaku lama (semua tabel tier wajib kosong) → gagal bila tier sebagian.
   - `--tier N --lanjut`: tabel yang ada di berkas jejak **dan** jumlah/PK maks tujuannya sama → dilewati ("sudah");
     tabel kosong → diimpor; tabel tidak kosong tetapi tidak cocok dengan berkas jejak → **gagal** (tidak menghapus apa pun).
   - Mengulang satu tabel yang sudah selesai = pulihkan DB dari cadangan sebelum tier (runbook: cadangan sebelum T10 dan
     sebelum T11), bukan TRUNCATE oleh alat.
7. `--dry-run` tidak berubah (tanpa commit, tanpa berkas jejak).

---

## 4. K-M9

### 4.1 `old_id` G-06
Di antara tabel G-06 hanya **`jenis_konket`** yang punya `old_id` (`INT NOT NULL`, UNIQUE `uq_jenis_konket_old_id`, migration
`2026-09-25-120000`). `diklat`, `jenis_hukdis`, `tingkat_hukdis`, `tanda_jasa` tidak punya kolom ini. `old_id` = **kode
kategori konket** yang disimpan `absen_ijin.kategori` [K] (`L_notification.php:438`, `:684`, `:929` `jk.old_id = absen_ijin.kategori`,
`views/hr/employee/rwy/konket/form.php:81`); v2 `KonketService` mencari jenis lewat `old_id` (`:382`). Legacy tidak punya UI
pengisi `old_id` (G-06 §2.4), sehingga produksi bisa NULL/ganda/bukan angka (G-06 temuan #2).

| Kasus nilai legacy (sesudah TRIM) | Keputusan |
|---|---|
| Bilangan bulat `^[1-9][0-9]*$` ≤ 2.147.483.647, unik | Disalin sebagai INT |
| NULL / kosong / bukan angka / ≤ 0 | **Impor gagal** dengan jumlah → **berkas keputusan** per `id_jenis_konket`: kode yang dipakai (mis. kode yang terbukti dipakai `absen_ijin.kategori`), atau baris tidak diimpor bila tidak dirujuk. Alat **tidak** mengarang kode |
| Ganda (dua jenis, satu kode) | **Impor gagal** dengan jumlah → berkas keputusan memilih pemilik kode (prioritas baris ber-ID hard-code `Config\Konket` 2/4/5/6), baris lain diberi kode lain atau tidak diimpor |

**Audit WAJIB_0 sesudah T7:** nilai `absen_ijin.kategori` (sudah diimpor MAKE-056) yang tidak punya pasangan
`jenis_konket.old_id` — pengajuan konket lama tanpa jenis akan salah dihitung (`affect_tukin`) dan tidak tampil benar.

### 4.2 `pegawai_hist`
**Dikonfirmasi** saringan sumber `flag_update = 1` (B-01/B-02 §6 #1), **ditambah**: paling banyak **satu baris per NIP**, yaitu
`id_pegawai_hist` **terbesar** di antara baris `flag_update = 1` NIP itu.
- Dasar [K]: legacy menentukan pengajuan aktif dari baris **terbaru** per NIP (`L_employee.php:431-432`, `ORDER BY
  id_pegawai_hist DESC`, lalu `flag_update == '1'`); v2 juga satu pengajuan aktif per NIP (`BiodataService:216`,
  `ApprovalBiodata:94`).
- Baris `flag_update = 1` lain untuk NIP yang sama → tidak diimpor, jumlahnya dilaporkan.
- Audit INFO: `pegawai.flag_update = 1` tanpa `pegawai_hist` pending, dan sebaliknya (rekonsiliasi dilaporkan, tidak diperbaiki).

### 4.3 FAQ `faq_article`
**Dikonfirmasi**, dengan urutan: `stripslashes` (legacy menulis `content`/`content_stripped` ber-`addslashes`,
`L_faq.php:724-725`) → `HtmlSanitizer` (whitelist sama dengan `FaqArticleHooks`) → `content_stripped` **dihitung ulang** dari
konten tersanitasi dengan rumus legacy (`L_faq.php:720`, G-10). Nilai `content_stripped` legacy tidak disalin. Jumlah
artikel yang kontennya berubah oleh sanitizer dilaporkan (tanpa isi).

---

## 5. K-M10 — `waktuBisnis` dan `tanpaStripslashes` T7–T13

### 5.1 `waktuBisnis` (DATETIME yang **tidak** dikonversi R1a)
Audit `information_schema` seluruh tabel tujuan T7–T13 yang ada di `main` (T12b menunggu DBV-028): selain `created_at`/`updated_at`/`deleted_at`, kolom
DATETIME hanya yang di bawah. Tanggal bisnis lain bertipe **DATE** (tidak dikonversi, aturan #3).

| Kolom | Arti | Keputusan |
|---|---|---|
| `notif_date` (`pegawai_hist`, `riwayat_ak`, `riwayat_alamat`, `riwayat_diklat`, `riwayat_hukdis`, `riwayat_ib`, `riwayat_kariskarsu`, `riwayat_karpeg`, `riwayat_kgb`, `riwayat_organisasi`, `riwayat_pendidikan`, `riwayat_pmk_req`, `riwayat_seminar`, `riwayat_skp`, `riwayat_tanda_jasa`) | Stempel saat notifikasi dibuat (`date('Y-m-d H:i:s')` server) | Stempel → **R1a** |
| `siasn_lu` (`pegawai_hist`, `riwayat_ak`, `riwayat_pendidikan`, `riwayat_pmk`, `riwayat_tanda_jasa`) | Stempel sinkron SIASN | Stempel → **R1a** (wajib, agar perbandingan `updated_at > siasn_lu` AC-03.5 tetap konsisten dengan `updated_at` yang juga R1a) |
| `riwayat_ak.appr_at`, `riwayat_pmk_req.approved_at` | Stempel persetujuan | **R1a** |
| `pengguna.expired_at` | Kedaluwarsa password, dibandingkan dengan `now` (`L_user.php:207`, v2 `PasswordExpiry`) — instan | **R1a** (sesuai rencana T8) |
| `pengguna.last_login_at`, `pengguna.password_changed_at` | Kolom [V2], tidak ada di legacy | NULL |
| `disiplin_ticket.notify_at` | Stempel | **R1a** (sumber hasil sortir F-02) |

**Daftar `waktuBisnis` T7–T13: kosong.** Bila sumber hasil sortir F-02 (T13) atau skema DBV-028 (T12b) menambah kolom
DATETIME, kolom itu diklasifikasi di DBV-028 / saat sortir, bukan oleh pelaksana.

### 5.2 `tanpaStripslashes` (teks disalin mentah)
**Daftar T7–T13: kosong** — `stripslashes` bawaan berlaku untuk semua kolom teks. Dasar audit penulis legacy:
- Form & layanan memakai `addslashes` (mis. FAQ `L_faq.php:724-725`; `dm_user_lokasi_presensi` G-03 P-4; tiket
  `L_support.php` `set_param_conversation` `'msg' => addslashes(...)`; `esign_hist` `L_cuti.php:3395-3421` dan
  `S_esign.php` `keterangan`).
- **Job SIASN generasi 2 juga memakai `addslashes`** untuk nilai dari API (`SS/Rw_ak` 26×, `Rw_skp` 20×, `Rw_pendidikan` 18×,
  `Rw_pmk` 11×), sehingga tabel yang ikut diisi sinkron (`riwayat_ak_siasn`, `riwayat_pendidikan`, `riwayat_skp`,
  `riwayat_pmk`, `riwayat_tanda_jasa`) **tidak** dikecualikan — berbeda dengan `riwayat_skp_periodik`/`bkn_periode_ekinper`
  (e-Kinerja, GAP-8) yang mentah.
- **Kolom path berkas** (`document_attachment.path`, kolom berkas Bagian 4 MAKE-069): legacy kadang menulis dengan
  `addslashes` (`L_alamat.php:222`), kadang tanpa (`L_employee.php:296`), tetapi **selalu membaca lewat
  `pathToSrc(stripslashes(path))`** (`S_stat.php:90-124`, `Local.php:4409`). `stripslashes` saat impor menghasilkan path yang
  sama dengan yang dibuka legacy → manifest berkas K-M3 **wajib memakai nilai sesudah `stripslashes`** (opsi A tetap "path
  legacy apa adanya" dalam arti path yang dipakai legacy).
- Kolom hash/kode (`pengguna.password` md5 hex, `nik`, NIP, kode) tidak terpengaruh `stripslashes`.

Pengecualian di masa depan hanya bila audit menemukan penulis **tanpa** `addslashes` **dan** pembaca **tanpa**
`stripslashes` untuk kolom yang bisa berisi `\` — diputus DBV per kolom.

---

## 6. K-S1 — `stripslashes` tier SIASN (B3, MAKE-056 `make-056/b3-impor-fase6`)

Pertanyaan pelaksana B3 (ReadOne, branch `make-056/b3-impor-fase6` @`e6fcd1fe`), diputus DBV 03-11-2026 dari penulis legacy
(rujukan relatif `controllers/hr/services/siasn_sync/` = `SS/`):

| Tabel / kolom | Keputusan | Dasar [K] |
|---|---|---|
| `siasn_ref_unor` (semua kolom) | **Mentah** — `tanpaStripslashes ['*']` | `SS/Ref_unor.php:53-67` (insert) dan `:83-93` (update) menyalin field respons API apa adanya (`NamaUnor`, `NamaJabatan`, …) tanpa `addslashes`, lalu `insert_batch`/`update_batch` `siasn_simpeg.REF_UNOR` `:111`/`:118`. Kolom pemetaan lokal (`id_jabatan`, `id_unit`, `id_satker`, `lvl_uker`) angka, tidak terpengaruh |
| `siasn_referensi` (7 tabel sumber, semua kolom) | **Mentah** — `tanpaStripslashes ['*']` | Tidak ada penulis di job aktif; satu-satunya penulis di kode `controllers/Tester.php::reinsert_jfu_siasn_20250117` `:785-799` menyalin baris apa adanya lewat `insert_batch` tanpa `addslashes`; sisanya diisi di luar kode (impor referensi BKN). Pembaca `SS/Rw_jabatan` mencocokkan `NAMA` mentah |
| `double_riwayat_kp` | `stripslashes` bawaan | Hanya `nip` + stempel (`SS/Rw_gol.php:136-141`, `:392-393`); tidak berpengaruh |
| `siasn_upload_arsip.arsip_path` | `stripslashes` bawaan | Ditulis = `document_attachment.path` apa adanya (`SS/Rw_jabatan.php:638`, `SS/Rw_pelatihan.php:471`); kunci anti-antre-ulang legacy membandingkan nilai mentah keduanya (`Rw_jabatan :581/:631`, `Rw_pelatihan :420/:464`). Karena `document_attachment.path` diimpor dengan `stripslashes` (Bagian 5.2), kolom ini wajib diperlakukan sama agar tetap identik. Catatan: pemroses `SS/Arsip.php:70` membuka `pathToSrc($row['arsip_path'])` **tanpa** `stripslashes`; untuk path tanpa `'` `"` `\` hasilnya sama, penyimpangan dilaporkan `lampiran:periksa` |
| `siasn_upload_arsip.arsip_name` | `stripslashes` bawaan | `"Sertifikat {$row['nama_diklat']}"` (`SS/Rw_pelatihan.php:472`) memakai nama tersimpan ber-`addslashes` dari form, atau konstanta (`SS/Rw_jabatan.php:639`) |
| `siasn_upload_arsip.resp_dok_id`, `resp_dok_nama`, `resp_dok_uri`, `resp_object`, `resp_slug`, `error_msg` | **Mentah** — `tanpaStripslashes` per kolom | `resp_*` = `$respFile['data'][…]` respons BKN tanpa `addslashes` (`SS/Arsip.php:73-79`); `error_msg` = string literal kode + angka (`:84-112`). Mentah menjaga backslash dari respons BKN (mis. di `dok_uri`) |
| `siasn_upload_arsip` kolom lain (`nip`, `rw_name`, `id_rw`, `id_rw_siasn`, `id_da`, `status`) | `stripslashes` bawaan | Kode/angka/konstanta, tidak terpengaruh |

Aturan lain tier SIASN tetap DBV-027 §13.3 (K-3..K-9).

---

## 7. Hal di luar keputusan ini
- T12b (DBV-028) dan T13 (hasil sortir F-02) belum punya skema/sumber final; klasifikasi kolomnya mengikuti DBV-028 / sortir.
- Tier SIASN selain `stripslashes`: DBV-027 §13.3 (K-3..K-9).
