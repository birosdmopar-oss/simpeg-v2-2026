# DB Validator Review: DBV-034, pra-cek FK ditahan dan normalisasi impor kolom NIP ber-FK

**Key review:** `DBV-034` (kode `CR-172`). Judul PR: `[CR][DBV] CR-172 / DBV-034 pra-cek FK ditahan dan normalisasi impor`.
Branch `fix/cr-172-pracek-fk-impor` dibuat dari `main` `6ca7e1f8`.

Alur `[CR]+[DBV]`:
1. Coder membuka PR.
2. DB Validator (WS-2) me-review dan memberi approve.
3. Reviewer CR me-merge dengan izin Horii.

**Status:** ⏳ menunggu review DB Validator.

**Penulis:** coder ReadOne (izin `[#19-Fk422DanCrKecil]` sub #19.2), 22-11-2026.

**Asal kebutuhan:** audit izin #19 bagian A, celah **G5** (pra-cek) dan **G6** (normalisasi impor). Semua rujukan baris
mengacu ke `main` `6ca7e1f8`.

**Label sumber:**
- **[K]**: perilaku kode/DDL yang sudah ada.
- **[V2]**: keputusan v2.
- **[I]**: inferensi coder; perlu konfirmasi DB Validator.

---

## 0. Ringkasan

| Celah | Masalah | Perubahan | Skema berubah? |
|---|---|---|---|
| G5 | Pra-cek `AddFkNipLayananFase4` melewatkan `''` dan nilai yang hanya berisi spasi. Pra-cek `AddFkPegawaiDiPenggunaFaqRate` menghitungnya sebagai "NIP tidak ada di pegawai". Padahal ALTER menolak keduanya (1452) tanpa jumlah per kolom. | Pra-cek menghitung kosong/spasi-saja **terpisah** dari yatim, dengan jumlah per kolom dan saran sesuai `IS_NULLABLE`. | Tidak. FK, ALTER, aturan ON DELETE/UPDATE, dan `down()` tidak berubah. |
| G6 | Importer tidak menormalkan kolom approver `riwayat_cuti` dan `pa_layanan.nip`. Pada tiket, NIP `''` tidak terlihat di eskalasi bila tiket sudah ambigu karena pengirim atau subjek. | Kolom tersebut di-trim dan karakter tak terlihatnya dibuang; `''` menjadi NULL (dicatat). Kode eskalasi tiket selalu memuat "nip kosong". | Tidak. |

NIP **yatim tidak pernah di-NULL-kan diam-diam**. Yatim tetap ditolak pra-cek FK ditahan (fail-closed).

## 1. G5: pra-cek migration FK ditahan

### 1.1 Masalah [K]

- `MigrationsDitahan/AddFkNipLayananFase4.php` `assertNoOrphans` memakai filter `IS NOT NULL AND <> ''`.
  - Dengan collation `utf8mb4_unicode_ci` (PAD SPACE), `'   ' = ''`. Akibatnya nilai kosong dan spasi-saja **dilewati**.
  - `ADD CONSTRAINT` lalu menolaknya (tidak ada `pegawai.nip = ''`). Pesannya 1452, tanpa jumlah per kolom.
  - Kolom NOT NULL (`petugas_layanan.nip`, `disiplin_ticket.nip`) tidak bisa di-NULL-kan, sehingga pesan lama "perbaiki/NULL-kan" menyesatkan untuk kolom itu.
- `MigrationsDitahan/AddFkPegawaiDiPenggunaFaqRate.php` sudah menghitung `''` sebagai masalah, karena filternya hanya `IS NOT NULL`. Masalahnya, `''` dilaporkan sebagai "NIP yang tidak ada di pegawai", sehingga operator mencari pegawai yang hilang padahal nilainya kosong.
- `MigrationsDitahan/AddFkSertifikasiDiRiwayatDiklat.php` **tidak berpola sama**. Kolomnya `riwayat_diklat.id_rumpun_sertifikasi`/`id_lembaga_sertifikasi` bertipe `int DEFAULT NULL`, jadi tidak bisa berisi `''`. Berkas ini tidak diubah.

### 1.2 Perubahan (hanya fungsi pra-cek dan pesan) [V2]

Kedua berkas NIP sekarang memakai satu kueri per kolom (`WHERE kolom IS NOT NULL`):

```sql
SUM(TRIM(kolom) = '')                                                  AS kosong,
SUM(TRIM(kolom) <> '' AND NOT EXISTS (pegawai.nip = kolom))            AS yatim
```

- **kosong** = `''` atau spasi-saja. Pesannya `<tabel>.<kolom>: N <label> dengan NIP kosong/spasi-saja (...)`, dengan saran berikut:
  - kolom NULL-able (`IS_NULLABLE = 'YES'`): "kolom NULL-able: NULL-kan";
  - kolom NOT NULL: "kolom NOT NULL: perbaiki NIP atau buang barisnya, tidak bisa di-NULL-kan".
  - Nullability dibaca dari `information_schema.COLUMNS` lewat helper baru `nullable()`, bukan di-hardcode.
- **yatim** = nilai berisi yang tidak sama dengan `pegawai.nip` mana pun. Pesannya tetap seperti sebelumnya.
- Kalimat penutup `AddFkNipLayananFase4` diubah dari "perbaiki/NULL-kan" menjadi "perbaiki baris tersebut (NULL-kan hanya di kolom NULL-able; audit sql/DBV-022-audit-layanan.sql)". Penutup `AddFkPegawaiDiPenggunaFaqRate` tidak berubah.
- Docblock kelas `AddFkNipLayananFase4` (:31-33) yang menyebut "string kosong diabaikan" disesuaikan dengan perilaku baru.

**Tidak berubah:** `FOREIGN_KEYS`, `up()` selain pemanggilan pra-cek, ALTER, `ON DELETE/UPDATE RESTRICT`, rollback FK sebagian, dan `down()`.

### 1.3 Pra-cek ⇔ ALTER (tidak ada nilai yang lolos pra-cek lalu ditolak ALTER)

Pembandingan FK InnoDB memakai collation kolom (PAD SPACE). Akibatnya per jenis nilai:

| Nilai anak | Pra-cek baru | ALTER | Diuji |
|---|---|---|---|
| NULL | lolos | lolos | ✔ |
| `''`, `'   '` | **kosong** | ditolak 1452 | ✔ |
| `'<nip sah>'` | lolos | lolos | ✔ |
| `'<nip sah>   '` (spasi di belakang) | lolos (`=` PAD SPACE) | lolos | ✔ (up() sukses) |
| `' <nip sah>'` (spasi di depan) | **yatim** | ditolak 1452 | ✔ |
| NIP tak ada di `pegawai` | **yatim** | ditolak 1452 | ✔ (test lama) |
| tab, NBSP, atau karakter tak terlihat saja | **yatim** (TRIM SQL hanya membuang spasi) | ditolak 1452 | [I] tidak diuji terpisah; sifatnya sama dengan yatim |

Karakter tak terlihat sudah dibuang importer (`Transformasi::kode`) untuk kolom `kolomKode`, jadi baris jenis terakhir hanya
muncul dari data yang tidak lewat importer.

### 1.4 Pengecualian mengedit MigrationsDitahan ⚠️

Berkas `MigrationsDitahan/` ada di `main` tetapi **belum pernah dijalankan** di lingkungan mana pun (tidak tercatat di
tabel `migrations`). Aturan "migration yang sudah ada di `main` tidak diedit" ditujukan untuk migration yang sudah
dijalankan. Perubahan ini dibatasi pada pra-cek dan pesannya, dan **wajib disetujui DB Validator** (keputusan Q1).

## 2. G6: normalisasi importer `impor:legacy`

### 2.1 Masalah [K]

- `riwayat_cuti` (`DaftarTabel.php`, T6) hanya memberi `kolomKode: ['nip']`.
  - `nip_atasan_langsung` dan `nip_yang_menyetujui` tidak di-trim.
  - Nilai `''` pada kedua kolom itu tidak menjadi NULL, karena `Transformasi::rujukan` hanya berlaku untuk kolom yang sudah ber-FK di tujuan, sedangkan FK-nya masih ditahan.
- `pa_layanan` (T12) tidak punya `kolomKode`, jadi `nip` disalin apa adanya.
- `disiplin_ticket` (T13, `AturanTiketLama::klasifikasi`): rantai `if/elseif` hanya mencatat alasan pertama.
  - Tiket yang ambigu karena pengirim (`A_PENGIRIM`) atau subjek (`A_SUBJEK`) dan juga ber-NIP `''` hanya berkode alasan pertama.
  - Bila operator memutus tiket itu `"__impor"`, NIP `''` tertulis tanpa pernah terlihat di daftar eskalasi.

### 2.2 Perubahan [V2]

| Berkas | Perubahan |
|---|---|
| `DefinisiTabel.php` | Opsi baru `kosongKeNull: list<string>`. Hanya boleh berisi kolom yang juga ada di `kolomKode`; kolom lain membuat definisi gagal. |
| `PengimporLegacy.php::ubahNilai` | Sesudah normalisasi `kolomKode`, hasil `''` pada kolom `kosongKeNull` diubah menjadi NULL dan dicatat sebagai `<kolom> kosong→NULL (DBV-034)` di laporan. Kolom tujuan NOT NULL membuat impor gagal (salah definisi). |
| `DaftarTabel.php` T6 `riwayat_cuti` | Isi `kolomKode` menjadi `['nip', 'nip_atasan_langsung', 'nip_yang_menyetujui']`. Isi `kosongKeNull` menjadi kedua kolom approver. |
| `DaftarTabel.php` T12 `pa_layanan` | Ditambah `kolomKode: ['nip']` dan `kosongKeNull: ['nip']`. Kolom ini NULL-able (DQ-17). |
| `AturanTiketLama.php` | Alasan pengirim/subjek tetap memakai rantai yang sama. NIP kosong **selalu** ditambahkan, sehingga kodenya menjadi mis. `F-02 A-ESK pengirim tidak sah + F-02 A-ESK nip kosong`. Tiket yang hanya ber-NIP kosong tetap berkode `A_NIP` saja. |

Normalisasi `kolomKode` adalah perilaku yang sudah ada (CR-089): karakter tak terlihat U+200B..U+200F, U+2060, dan U+FEFF dibuang
(dicatat), lalu nilai di-trim. Yang ditambah hanyalah ketiga kolom di atas.

### 2.3 Yang sengaja TIDAK dilakukan

- **NIP yatim tidak di-NULL-kan.** Ini keputusan data. Yatim tetap tertulis, lalu `AddFkNipLayananFase4` menolak memasang FK sampai datanya dibereskan.
- **NIP `''` pada tiket tidak di-NULL-kan**, karena `disiplin_ticket.nip` NOT NULL. Nilai itu kini terlihat di eskalasi, sehingga putusan `"__impor"` diambil dengan sadar. Pra-cek G5 lalu melaporkannya sebagai "kosong, kolom NOT NULL".
- **Tidak ada `rujukan` (yatim → impor gagal) untuk kolom approver.** Lihat opsi Q3.
- `Keputusan::nilai` (`PengimporLegacy.php` awal `ubahNilai`) mengembalikan nilai pemetaan berkas keputusan **sebelum** `kode()`/`rujukan()`. Pemetaan manual ke `' X '` atau `''` tidak dinormalkan. Hal ini hanya dicatat dan tidak diubah, karena berkas keputusan adalah putusan eksplisit operator.

### 2.4 Dampak cutover

- Baris legacy dengan approver `''` atau spasi-saja masuk sebagai NULL. Arti NULL sama dengan "belum ada atasan/pejabat" di aplikasi v2, karena kolomnya memang NULL-able. Jumlahnya tercatat di laporan impor sebagai koreksi per kolom.
- Approver yang berisi NIP dengan spasi atau karakter tak terlihat menjadi NIP bersih. Nilai ini sebelumnya akan dihitung yatim (spasi depan) atau lolos tetapi kotor (spasi belakang).
- `pa_layanan.nip` diperlakukan sama.
- Tiket berkode ganda muncul di `impor:sortir-inbox` dengan kode gabungan. Berkas keputusan **lama** yang dibuat per id tetap berlaku, karena kunci putusannya id, bukan kode. Hanya label hitungan "belum diputuskan" yang berubah teksnya.
- Tidak ada perubahan skema, jadi tidak perlu migrate.

## 3. Keputusan yang diminta dari DB Validator

| Q | Pertanyaan | Usulan coder |
|---|---|---|
| Q1 | Menyetujui pengecualian mengedit dua berkas `MigrationsDitahan/` (hanya pra-cek dan pesan; belum pernah dijalankan)? | Setuju |
| Q2 | `''`/spasi-saja pada kolom approver `riwayat_cuti` dan `pa_layanan.nip` → NULL saat impor (dicatat), bukan gagal? | NULL (kolom NULL-able, artinya sama) |
| Q3 | Approver yatim di `riwayat_cuti`: (a) dibiarkan dan ditolak pra-cek FK ditahan, **atau** (b) ditambah `rujukan` agar impor T6 langsung gagal dengan jumlah per kolom, **atau** (c) `yatimKeNull` (NULL + dicatat)? | (a); (c) ditolak karena menghapus jejak persetujuan diam-diam |
| Q4 | Tiket ber-NIP `''` dengan putusan `"__impor"`: (a) dibiarkan tertulis dan dilaporkan pra-cek G5, **atau** (b) importer menolak `"__impor"` untuk kode yang memuat `A_NIP` (hanya boleh `"__buang"`, seperti pesan tanpa induk)? | (b) bila FK `disiplin_ticket.nip` tetap dipakai (DQ-4); (a) bila baris FK itu dicoret |
| Q5 | `Keputusan::nilai` melewati normalisasi `kode()`/`rujukan()`: cukup dicatat? | Cukup dicatat |

## 4. Cara uji

DB scratch MySQL 8 di 3307, `db-isolasi-penuh`.

| Test | Isi |
|---|---|
| `tests/database/Layanan/PraCekFkDitahanKosongTest.php` | Dua kelas ditahan. `''`/spasi-saja dihitung per kolom dan terpisah dari yatim. Saran NOT NULL tanpa "NULL-kan". NIP berspasi depan dihitung yatim. Tidak ada FK terpasang sebagian. Sesudah data dibereskan, `up()` memasang FK, termasuk NIP berspasi belakang. `down()` dijalankan di tearDown. |
| `tests/Impor/ImporLegacyNormalisasiNipFkTest.php` | T6 `riwayat_cuti` approver: trim, karakter tak terlihat dibuang, `''`/spasi → NULL, yatim tetap (bukan NULL), koreksi tercatat. T12 `pa_layanan.nip`: perlakuan yang sama. |
| `tests/Impor/ImporLegacyTiketLamaTest.php::testNipKosongTetapTerlihatWalauTiketSudahAmbiguKarenaPengirimAtauSubjek` | Kode gabungan pengirim/subjek + NIP kosong. |

- Kelima test baru **merah di `6ca7e1f8`**: kode `app/` dikembalikan ke `main`, sedangkan test tetap versi baru.
- Regresi yang dijalankan ulang dan hijau: `MigrasiLayananSiklusTest`, `NipReferenceRegistryTest`, `ImporLegacyTiketLamaTest`, `ImporLegacyTier456Test`, `ImporLegacyMasterHaloTest`.

## 5. Checklist DB Validator

- [ ] Pra-cek: tidak ada nilai yang lolos pra-cek lalu ditolak ALTER (Bagian 1.3).
- [ ] Pesan kolom NOT NULL tidak menyarankan NULL.
- [ ] Hanya pra-cek dan pesan yang berubah di `MigrationsDitahan/` (diff).
- [ ] Normalisasi impor hanya format; yatim tidak di-NULL-kan.
- [ ] Q1–Q5 diputuskan.
