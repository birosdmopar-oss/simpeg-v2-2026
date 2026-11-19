# DB Validator Review — DBV-021 audit yatim FK G-02 per tabel anak (ISSUE-045)

**Key review:** `DBV-021`. Judul PR: `[DBV] DBV-021 audit yatim FK G-02 per tabel anak (ISSUE-045)`.
Branch `dbv/dbv-021-audit-yatim-fk-g02` (dari `main` `efd7d37f`). Alur DBV-only: PR, DB Validator (WS-2) me-review, merge
atas izin Horii. **Tanpa migration, tanpa perubahan skema**: hanya kueri baca-saja, test, dan dokumen.

**Status:** menunggu review DB Validator.

**Asal kebutuhan:** keputusan review DB Validator PR #26 (15-10-2026) saat menyetujui DBV-011 2.10 (audit yatim FK G-02,
DBV-019): "perluasan menjadi 10 kueri ringkasan per tabel anak = unit lanjutan sebelum gladi impor". Nomor DBV-021
dicadangkan untuk ISSUE-045 sejak PRD Fase 4. Berkas `DBV-019-audit-yatim-fk-g02.sql` hanya memuat **pola** per FK
(Bagian 1, contoh satu FK) dan **satu** contoh ringkasan per tabel anak (Bagian 2, `pegawai_plh`); operator harus
menurunkan sendiri 54 kueri lain dari DBV-019 Bagian 2.3. DBV-021 menuliskannya lengkap.

**Rujukan:** `DBV-019-fk-g02-pegawai-schema.md` (Bagian 2.3, 6), `DBV-011-runbook-impor-master-zona-waktu.md` 2.10,
`docs/migrasi/MAKE-069-rencana.md` (T10/T11, Bagian 6), migration `2026-10-07-100000_AddFkG02Pegawai` dan
`2026-10-07-100100_AddFkG02Riwayat`.

**Label sumber:** **[K]** ikut kode/DDL legacy; **[V2]** deviasi v2 yang disengaja; **[I]** inferensi.

---

## 0. Ringkasan

| # | Berkas | Jenis | Isi |
|---|---|---|---|
| 1 | `backend/docs/db-review/sql/DBV-021-audit-yatim-fk-g02-per-tabel.sql` | baru, baca-saja | Bagian A: 10 kueri ringkasan (satu per tabel anak, 55 kolom FK, WAJIB_0). Bagian B: contoh nilai yatim per FK (INFO, maksimal 10 id master) |
| 2 | `backend/tests/database/Kepegawaian/AuditYatimFkG02Test.php` | baru | Mengunci cakupan = konstanta `FOREIGN_KEYS` kedua migration DBV-019; kueri berjalan di skema v2; DB bersih = 0; nilai `0` dan id asing terhitung, NULL tidak; contoh tanpa NIP |
| 3 | `DBV-011-runbook-impor-master-zona-waktu.md` 2.10 | ubah teks | Langkah 1 "Audit = 0" memakai berkas DBV-021 (Bagian A), berkas DBV-019 tetap untuk Bagian 3–4 |
| 4 | `docs/migrasi/MAKE-069-rencana.md` | ubah teks | Prasyarat gladi T10/T11: audit DBV-021 di salinan legacy dan di DB gladi |

Tidak ada objek DB yang dibuat/diubah. Skrip tidak menulis apa pun.

## 1. Cakupan (55 FK, 10 tabel anak) [K D1]

Sumber tunggal = konstanta `FOREIGN_KEYS` kedua migration DBV-019 (sama dengan DBV-019 Bagian 2.3). Semua kolom anak
nullable [K] (`DEFAULT NULL`); induk = PK master G-02.

| Kode | Tabel anak | FK | Induk |
|---|---|---:|---|
| A01 | `pegawai_mutasi_jabatan` | 13 | `jabatan` (5), `jabatan_koordinasi` (3), `group_jabatan`, `sub_group_jabatan`, `unit`, `satker`, `rumpun_jabatan` |
| A02 | `pegawai_ak` | 1 | `jabatan` |
| A03 | `pegawai_ak_siasn` | 1 | `jabatan` |
| A04 | `riwayat_mutasi_jabatan` | 13 | sama dengan A01 |
| A05 | `pegawai_plt` | 10 | `group_jabatan` (2), `sub_group_jabatan` (2), `unit` (2), `satker` (2), `jabatan`, `jabatan_koordinasi` |
| A06 | `pegawai_plh` | 10 | sama dengan A05 (contoh Bagian 2 DBV-019, disalin ulang di sini agar satu berkas lengkap) |
| A07 | `riwayat_lckh` | 2 | `unit`, `satker` |
| A08 | `riwayat_ak` | 1 | `jabatan` |
| A09 | `riwayat_ak_siasn` | 1 | `jabatan` |
| A10 | `konv_ak` | 3 | `jabatan`, `unit`, `satker` |
| | **Jumlah** | **55** | |

Daftar brief ISSUE-045 menyebut 9 tabel anak tanpa `pegawai_plh` (sudah punya contoh di DBV-019); dicek ulang terhadap
migration: tabel anak FK G-02 = 10, sehingga 10 kueri ringkasan sesuai keputusan PR #26.

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
| `contoh_nilai` = `0` (atau `''`) | → NULL di transformasi impor | Importer T10/T11 sudah menerapkan "id G-02 = 0 → NULL" (MAKE-069 T10); di salinan legacy angka ini wajar, di DB v2 harus 0 |
| id asing | impor dulu baris master yang hilang (status 10 bila tidak dipakai), atau NULL-kan dengan catatan rekonsiliasi | Diputus per kasus, dicatat di log 2.8; tidak dihapus diam-diam |
| di DB v2 sesudah impor (FK nonaktif) | audit diulang sesudah perbaikan; aplikasi tidak dibuka sebelum Bagian A = 0 | Pra-cek migration DBV-019 menjadi pemeriksaan kedua (fail-closed) |

## 4. Kapan dijalankan (prasyarat gladi MAKE-069)

1. **Salinan legacy, sebelum gladi impor** (perkiraan awal): nama tabel/kolom = D1. Hasil `0`/id asing dicatat per FK di
   lembar hasil; id asing diputus sebelum gladi (impor master hilang / NULL + catatan).
2. **DB gladi v2 sesudah T10/T11**: bila impor berjalan dengan FK aktif (jalur disarankan: `migrate` di DB kosong lebih
   dulu, MAKE-069 Bagian 6 langkah 1), Bagian A harus 0 dan berfungsi sebagai bukti. Bila impor memakai
   FOREIGN_KEY_CHECKS = 0, Bagian A = 0 **wajib** sebelum aplikasi dibuka.
3. Bagian 3 (FK masuk `pengguna`/`faq_rate`) dan Bagian 4 (master status 10) tetap dari `DBV-019-audit-yatim-fk-g02.sql`.

## 5. Verifikasi

| Pemeriksaan | Hasil |
|---|---|
| Cakupan berkas = konstanta `FOREIGN_KEYS` (nama FK, kolom, induk, PK induk), 10 kueri A + 10 kueri B, 55 FK di B | `AuditYatimFkG02Test::testBerkasMencakupTepat55FkMigration` |
| Kueri berjalan di skema v2; DB bersih = 0; `0` dan id asing terhitung per baris; NULL bukan yatim; contoh = id master, tanpa NIP | `AuditYatimFkG02Test::testDbBersihNolDanYatimTerhitung` (data sintetis, FK dinonaktifkan sementara untuk menanam yatim) |
| Uji di DB scratch lokal | menunggu jeda test lokal dicabut (izin #13); hasil ditambahkan di sini |

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
