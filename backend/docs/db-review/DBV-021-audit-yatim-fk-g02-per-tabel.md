# DB Validator Review — DBV-021 audit yatim FK G-02 per tabel anak (ISSUE-045)

**Key review:** `DBV-021`. Judul PR: `[DBV] DBV-021 audit yatim FK G-02 per tabel anak (ISSUE-045)`.
Branch `dbv/dbv-021-audit-yatim-fk-g02` (dari `main` `efd7d37f`). Alur DBV-only: PR, DB Validator (WS-2) me-review, merge
atas izin Horii. **Tanpa migration, tanpa perubahan skema**: hanya kueri baca-saja, test, dan dokumen.

**Status:** ✅ **disetujui DB Validator (WS-2) dengan catatan** C-1..C-3 (dikerjakan di putaran ini); ke-4 keputusan §6 disetujui.

**Asal kebutuhan:** keputusan review DB Validator PR #26 (15-10-2026) saat menyetujui DBV-011 2.10 (audit yatim FK G-02,
DBV-019): "perluasan menjadi 10 kueri ringkasan per tabel anak = unit lanjutan sebelum gladi impor". Nomor DBV-021
dicadangkan untuk ISSUE-045 sejak PRD Fase 4. Berkas `DBV-019-audit-yatim-fk-g02.sql` hanya memuat **pola** per FK
(Bagian 1, contoh satu FK) dan **satu** contoh ringkasan per tabel anak (Bagian 2, `pegawai_plh`); operator harus
menurunkan sendiri 54 kueri lain dari DBV-019 Bagian 2.3. DBV-021 menuliskannya lengkap.

**Rujukan:** `DBV-019-fk-g02-pegawai-schema.md` (Bagian 2.3, 6), `DBV-011-runbook-impor-master-zona-waktu.md` 2.10,
`docs/migrasi/MAKE-069-rencana.md` (T3/T10/T11, Bagian 6), migration `2026-10-07-100000_AddFkG02Pegawai` dan
`2026-10-07-100100_AddFkG02Riwayat`.

**Label sumber:** **[K]** ikut kode/DDL legacy; **[V2]** deviasi v2 yang disengaja; **[I]** inferensi.

---

## 0. Ringkasan

| # | Berkas | Jenis | Isi |
|---|---|---|---|
| 1 | `backend/docs/db-review/sql/DBV-021-audit-yatim-fk-g02-per-tabel.sql` | baru, baca-saja | Bagian A: 10 kueri ringkasan (satu per tabel anak, 55 kolom FK, WAJIB_0). Bagian B: contoh nilai yatim per FK (INFO, maksimal 10 id master) |
| 2 | `backend/tests/database/Kepegawaian/AuditYatimFkG02Test.php` | baru | Mengunci cakupan = konstanta `FOREIGN_KEYS` kedua migration DBV-019; kueri berjalan di skema v2; DB bersih = 0; nilai `0` dan id asing terhitung, NULL tidak; contoh tanpa NIP |
| 3 | `DBV-011-runbook-impor-master-zona-waktu.md` 2.10 | ubah teks | Langkah 1 "Audit = 0" memakai berkas DBV-021 (Bagian A), berkas DBV-019 tetap untuk Bagian 3–4 |
| 4 | `docs/migrasi/MAKE-069-rencana.md` | ubah teks | Prasyarat gladi T3/T10/T11: audit DBV-021 di salinan legacy dan di DB gladi |

Tidak ada objek DB yang dibuat/diubah. Skrip tidak menulis apa pun.

## 1. Cakupan (55 FK, 10 tabel anak) [K D1]

Sumber tunggal = konstanta `FOREIGN_KEYS` kedua migration DBV-019 (sama dengan DBV-019 Bagian 2.3). Semua kolom anak
nullable [K] (`DEFAULT NULL`); induk = PK master G-02.

| Kode | Tabel anak | FK | Induk | Tier impor |
|---|---|---:|---|---|
| A01 | `pegawai_mutasi_jabatan` | 13 | `jabatan` (5), `jabatan_koordinasi` (3), `group_jabatan`, `sub_group_jabatan`, `unit`, `satker`, `rumpun_jabatan` | T3 |
| A02 | `pegawai_ak` | 1 | `jabatan` | T11 |
| A03 | `pegawai_ak_siasn` | 1 | `jabatan` | T11 |
| A04 | `riwayat_mutasi_jabatan` | 13 | sama dengan A01 | T3 |
| A05 | `pegawai_plt` | 10 | `group_jabatan` (2), `sub_group_jabatan` (2), `unit` (2), `satker` (2), `jabatan`, `jabatan_koordinasi` | T3 |
| A06 | `pegawai_plh` | 10 | sama dengan A05 (contoh Bagian 2 DBV-019, disalin ulang di sini agar satu berkas lengkap) | T3 |
| A07 | `riwayat_lckh` | 2 | `unit`, `satker` | T10 |
| A08 | `riwayat_ak` | 1 | `jabatan` | T10 |
| A09 | `riwayat_ak_siasn` | 1 | `jabatan` | T10 |
| A10 | `konv_ak` | 3 | `jabatan`, `unit`, `satker` | T10 |
| | **Jumlah** | **55** | | T3 (4), T10 (4), T11 (2) |

Daftar brief ISSUE-045 menyebut 9 tabel anak tanpa `pegawai_plh` (sudah punya contoh di DBV-019); dicek ulang terhadap
migration: tabel anak FK G-02 = 10, sehingga 10 kueri ringkasan sesuai keputusan PR #26.

**Batas cakupan:** audit ini hanya untuk 55 FK DBV-019 (snapshot/riwayat/penugasan → master G-02). Ada 28 FK G-02 lain di
luar cakupan: `pa_layanan` 9, `peta_jabatan` 3, `struktur_jabatan` 2, `dm_ak_jf` 1, `subrumpun_jabatan` 1, dan antar-master
(`jabatan` 3, `jabatan_koordinasi` 7, `satker` 1, `sub_group_jabatan` 1). FK itu dijaga cek rujukan importer + FK aktif
saat impor (review DB WS-2 C-1). Bila gladi memakai `FOREIGN_KEY_CHECKS = 0`, FK tersebut perlu audit sendiri (kartu
terpisah).

## 2. Bentuk kueri

**Bagian A (WAJIB_0)** — satu pemindaian per tabel anak, pola Bagian 2 DBV-019 (T-1 PR #26: `COALESCE(SUM(…), 0)` agar
tabel kosong terbaca 0):

```sql
SELECT '<anak>' AS tabel_anak, COUNT(*) AS baris,
  COALESCE(SUM(c.<kolom> IS NOT NULL AND pNN.<pk_induk> IS NULL), 0) AS `<nama_fk>`, …
FROM <anak> c
LEFT JOIN <induk> pNN ON pNN.<pk_induk> = c.<kolom> …;
```

- Induk di-JOIN lewat PK, jadi baris anak tidak berlipat; satu alias per FK (A01/A04 punya 5 JOIN ke `jabatan`).
- NULL = "tidak dirujuk", bukan yatim. Nilai `0`/`''` **dihitung yatim** (di legacy lolos karena FOREIGN_KEY_CHECKS = 0).
- `baris` = jumlah baris tabel anak (INFO, untuk rekonsiliasi dengan laporan impor).
- Nama kolom hasil = nama FK DBV-019, sehingga hasil > 0 langsung menunjuk FK yang akan ditolak pra-cek migration.

**Bagian B (INFO)** — dibaca bila Bagian A > 0. Per tabel anak satu kueri `UNION ALL` per FK:
`fk`, `kolom`, `induk`, `nilai_yatim_berbeda` (COUNT DISTINCT), `contoh_nilai` (maksimal 10 nilai FK, urut).

- **Hanya nilai FK** (id master yang tidak ada). PK baris anak pada tabel snapshot `pegawai_*` = NIP, jadi PK, NIP, nama,
  dan kolom lain baris anak **tidak** ditampilkan. Lembar hasil aman disalin ke kartu tanpa redaksi.
- Nilai `0` muncul sebagai `0`, sehingga operator langsung membedakan "id 0 → NULL" (aturan normalisasi) dari id asing.

## 3. Tindak lanjut bila > 0 (aturan DBV-011 2.10, tidak berubah)

| Temuan Bagian B | Tindakan | Catatan |
|---|---|---|
| `contoh_nilai` = `0` (atau `''`) | → NULL di transformasi impor | Transformasi impor tier tabel itu (T3/T10/T11, lihat §1) menerapkan "id G-02 = 0 → NULL" (MAKE-069 T10; B-01/B-02 §6 #4); di salinan legacy angka ini wajar, di DB v2 harus 0 |
| id asing | impor dulu baris master yang hilang (status 10 bila tidak dipakai), atau NULL-kan dengan catatan rekonsiliasi | Diputus per kasus, dicatat di log 2.8; tidak dihapus diam-diam |
| di DB v2 sesudah impor (FK nonaktif) | audit diulang sesudah perbaikan; aplikasi tidak dibuka sebelum Bagian A = 0 | Pra-cek migration DBV-019 menjadi pemeriksaan kedua (fail-closed) |

## 4. Kapan dijalankan (prasyarat gladi MAKE-069)

1. **Salinan legacy, sebelum gladi impor** (perkiraan awal): nama tabel/kolom = D1. Hasil `0`/id asing dicatat per FK di
   lembar hasil; id asing diputus sebelum gladi (impor master hilang / NULL + catatan).
2. **DB gladi v2 sesudah T3, T10, dan T11** (tier per tabel di §1): bila impor berjalan dengan FK aktif (jalur disarankan: `migrate` di DB kosong lebih
   dulu, MAKE-069 Bagian 6 langkah 1), Bagian A harus 0 dan berfungsi sebagai bukti. Bila impor memakai
   FOREIGN_KEY_CHECKS = 0, Bagian A = 0 **wajib** sebelum aplikasi dibuka.
3. Bagian 3 (FK masuk `pengguna`/`faq_rate`) dan Bagian 4 (master status 10) tetap dari `DBV-019-audit-yatim-fk-g02.sql`.
4. Galat di tengah berkas (tanpa `--force`, mis. "Table doesn't exist" di salinan legacy yang tidak memuat salah satu tabel
   anak) menghentikan kueri berikutnya: catat galat itu di lembar hasil, buang blok A/B tabel tersebut **di salinan kerja
   berkas** (bukan di repo), lalu jalankan ulang. DB ber-`DBPrefix`: sesuaikan nama tabel di salinan kerja berkas (pola
   runbook DBV-028), bukan di repo.

## 5. Verifikasi

| Pemeriksaan | Hasil |
|---|---|
| Cakupan berkas = konstanta `FOREIGN_KEYS`: Bagian A tepat 55 kolom FK tanpa duplikat (nama, kolom, induk, PK induk, JOIN); Bagian B tepat 55 SELECT tanpa duplikat, label + kolom anak + FROM/JOIN/ON/WHERE cocok per FK; 10 kueri A + 10 kueri B | `AuditYatimFkG02Test::testBerkasMencakupTepat55FkMigration` |
| Kueri berjalan di skema v2; DB bersih = 0; `0` dan id asing terhitung per baris; NULL bukan yatim; contoh = id master, tanpa NIP | `AuditYatimFkG02Test::testDbBersihNolDanYatimTerhitung` (data sintetis, FK dinonaktifkan sementara untuk menanam yatim) |
| Uji lokal (DB scratch 3307, PHP 8.4.27) | `AuditYatimFkG02Test` OK (2 test), `FkG02Test` OK (3 test) |
| Review DB WS-2 | MySQL 8.0.30: berkas SQL utuh tanpa `--force` EXIT=0; test 2/144 |
| MariaDB 10.4 | CI #926 (Quality gate) hijau |

## 6. Keputusan yang diminta dari DB Validator

| # | Usulan | Rekomendasi |
|---|---|---|
| 1 | Berkas baru terpisah (DBV-021) yang melengkapi DBV-019, bukan menimpa `DBV-019-audit-yatim-fk-g02.sql` (yang sudah disetujui) | Setujui |
| 2 | `pegawai_plh` ikut sebagai A06 (10 tabel anak, sesuai keputusan PR #26) walau sudah ada contoh di DBV-019 | Setujui |
| 3 | Bagian B hanya menampilkan nilai FK (maks. 10), tanpa PK baris anak | Setujui |
| 4 | Runbook DBV-011 2.10 langkah 1 dan MAKE-069 merujuk berkas DBV-021 | Setujui |

## 7. Checklist DB Validator

- [ ] Cakupan 55 FK / 10 tabel anak cocok dengan DBV-019 Bagian 2.3
- [ ] Kueri hanya `SELECT`, berjalan di MySQL 8 / MariaDB tanpa `--force`
- [ ] Bagian B tidak memuat data pribadi
- [ ] Rujukan runbook DBV-011 2.10 dan MAKE-069 tepat
