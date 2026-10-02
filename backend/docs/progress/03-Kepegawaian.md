# Progress Fase 3 — Modul B: Kepegawaian Core

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak task lengkap: `03-Kepegawaian.md`. Aturan kalkulasi dan tanggal bisnis: `backend/app/Libraries/Kepegawaian/README.md`.

**Terakhir diperbarui:** 2 Oktober 2026. CR-036 menambah persiapan tanpa skema: registry kolom NIP untuk B-06, format NIP, dan BUP pensiun. Branch `cr-036/aturan-murni-fase3-lanjutan`, belum di main (sudah di-rebase ke main 2 Oktober 2026). Persiapan ini pengecualian urutan fase yang disetujui user 01-10-2026. Sebelumnya CR-029 (1 Oktober 2026): CR-025 sudah di main — merge `a540703` (commit `5852270`) 30-09-2026. CR-025 menambah kalkulasi murni periode KP, jarak KGB, akhir hukdis, dan masa kerja untuk B-21 (branch `cr-025/aturan-kepegawaian-fase3`).
**Entry criteria:** Fase 1 + Fase 2 DONE — belum terpenuhi (G-01/G-02/G-03/G-09 masih terbuka di `02-MasterData.md`). Pekerjaan di file ini sampai entry criteria terpenuhi hanya **persiapan yang tidak butuh tabel baru**, dikerjakan paralel sambil skema Fase 3 menunggu review DB Validator.

| Task | Status | Ringkas |
|---|---|---|
| B-08 Riwayat KP | TODO | Validasi TMT 1 April/1 Oktober sudah tersedia sebagai kalkulasi murni (CR-025); CRUD, snapshot, dan pemanggilan dari service menunggu B-02 |
| B-09 Riwayat KGB | TODO | Validasi jarak 2 tahun sudah tersedia (CR-025); isi daftar acuan (KP ikut atau tidak) menunggu pertanyaan terbuka #3 |
| B-14 Riwayat Hukdis | TODO | Hitung tanggal berakhir dari masa sanksi sudah tersedia (CR-025); kolom masa numerik per SK = keputusan skema B-02 |
| B-06 Koreksi NIP (Cascade) | TODO | Bahan murni sudah ada (CR-036): registry 79 kolom yang merujuk NIP (`TabelAnakNip::sampaiFase(3)` = 45 untuk Fase 3) dan cek format/koreksi NIP. `NipCascadeService`, transaksi, rollback, dan cek keunikan menunggu B-01/B-02 dan keputusan README CR-036 #1–#3 |
| B-21 Unit test Modul Kepegawaian | **IN_PROGRESS** | 3 dari 5 butir DoD lolos sebagai unit test murni (periode KP, jarak KGB, akhir hukdis). Sisa: cascade koreksi NIP + rollback (B-06) dan snapshot sync hanya di approval final (B-03), keduanya butuh tabel B-01/B-02. Registry NIP untuk butir cascade sudah ada; test ber-DB yang mencocokkannya dengan `information_schema` menyusul |
| B-01..B-05, B-07, B-10..B-13, B-15..B-20 | TODO | Belum dimulai. BUP/TMT pensiun untuk prediksi dashboard B-19/B-20 sudah tersedia (CR-036) |

## Persiapan tanpa skema

### CR-025 — kalkulasi murni Kepegawaian (bagian B-21) — pra-review internal sisi CR; di main lewat merge `a540703` 30-09-2026

Key review **CR-025** (CR saja). Tidak mengubah skema, jadi tidak ada dokumen `docs/db-review/` dan tidak perlu review DB Validator. Tanpa migration, tanpa FE, tanpa endpoint.

**Sudah jalan**
- `app/Libraries/Kepegawaian/Kalkulasi/` (namespace `App\Libraries\Kepegawaian\Kalkulasi`):
  - `TanggalBisnis`: validasi input (delegasi `HariLiburRules::isValidDate`), `hariIni()` Asia/Jakarta, tambah bulan/tahun dijepit akhir bulan, selisih bulan penuh, format "1 April 2027".
  - `HasilKalkulasi`: hasil gagal input pengguna → `lemparBilaGagal($field)` → 422.
  - `KenaikanPangkat` + `JenisKp`: periode 1 April/1 Oktober (SRS-FR-025), proyeksi reguler/percepatan setara legacy `next_kp`.
  - `KenaikanGajiBerkala` + `JenisReferensiTmt` + `ReferensiTmt`: jarak minimal 2 tahun dari acuan pendahulu (SRS-FR-026), proyeksi setara legacy `next_kgb`.
  - `HukumanDisiplin`: akhir = TMT + masa (SRS-FR-027, MTC-021), masa SK ?? masa bawaan `jenis_hukdis.masa_sanksi_bulan` (K5b).
  - `MasaKerja`: MKG kalender murni, TMT CPNS dari NIP, format `formatMker`.
- Test `tests/unit/Kepegawaian/Kalkulasi/` (tanpa DB): `TanggalBisnisTest`, `KenaikanPangkatTest` (+ `JenisKp`), `KenaikanGajiBerkalaTest`, `HukumanDisiplinTest`, `MasaKerjaTest`, `HasilKalkulasiTest`, dan penjaga arsitektur `KalkulasiMurniTest` (tanpa DB/HTTP/session/jam kecuali `hariIni()`). 135 test, 480 assertion.
- Uji mutasi: 32 mutasi pada kode kalkulasi (penjepitan, zona WIB, batas inklusif periode/jarak/36 bulan, prioritas acuan, masa SK vs bawaan, off-by-one, penjaga arsitektur, dll.) semuanya membuat test gagal; tanpa folder `Kalkulasi/` test gagal (125 error, 9 failure).
- Quality gate backend (PHPStan level 5, PHP-CS-Fixer, PHPUnit penuh ber-DB) lolos pada 30-09-2026: PHPStan tanpa error, CS-Fixer 0 dari 255 berkas, PHPUnit 670 test / 17.696 assertion.
- README modul (`app/Libraries/Kepegawaian/README.md`): aturan tanggal bisnis/zona, tabel aturan [K]/[V2] + deviasi, keputusan K-CR025-1..12, pertanyaan terbuka, dan cara integrasi B-08/B-09/B-14/B-19.

**Belum / di luar CR-025**
- Pemanggilan dari service ber-DB B-08/B-09/B-14 dan proyeksi dashboard B-19/H-08.
- B-21 butir cascade NIP + rollback (B-06) dan snapshot sync di approval final (B-03): test ber-DB di `tests/Kepegawaian/` setelah B-01/B-02.
- Hari kerja (Senin–Jumat dikurangi `hari_libur`) ditunda ke Fase 5 (K-CR025-12).
- Kelayakan KP (maks. golongan per pendidikan/eselon), aturan cron kelipatan 4 tahun `get_all_kp`, dan proyeksi pensiun → H-08.
- Penundaan KP/KGB karena hukdis tidak ada di legacy; perlu keputusan produk bila diinginkan.

**Pertanyaan terbuka** (detail dan usulan di README modul): #1 validasi [V2] hanya untuk input pengguna; #2 jenis KP yang bebas periode (usulan: 1 dan 2); #3 TMT KP sebagai acuan jarak KGB (SRS vs legacy); #4 akhir hukdis TMT + masa atau − 1 hari (usulan: literal SRS/MTC-021); #5 impor legacy/sinkron SIASN tidak divalidasi.

**Catatan lokasi test:** kontrak B-21 menyebut `tests/Kepegawaian/`. Test kalkulasi murni ditaruh di `tests/unit/Kepegawaian/` mengikuti konvensi repo (`tests/unit` = kelas tanpa DB, mis. `tests/unit/Libraries/HariLiburRulesTest.php`); test ber-DB B-21 tetap di `tests/Kepegawaian/`.

### CR-036 — registry kolom NIP, format NIP, dan BUP pensiun (bahan B-06/B-21) — pra-review internal sisi CR

Key review **CR-036** (CR saja). User menyetujuinya 01-10-2026 sebagai pengecualian urutan fase, seperti CR-025. Tidak mengubah skema, jadi tidak ada dokumen `docs/db-review/`. Tanpa migration, FE, dan endpoint. Rincian aturan, sumber, keputusan K-CR036-1..6, dan pertanyaan terbuka ada di README modul, bagian "Registry kolom NIP dan format NIP".

**Sudah jalan**
- `app/Libraries/Kepegawaian/Nip/` (namespace `App\Libraries\Kepegawaian\Nip`):
  - `TabelAnakNip` (+ `RujukanNip`, `JenisRujukanNip`, `KeberadaanV2`): registry statis 79 kolom.
    - 75 FK legacy `REFERENCES pegawai (nip)` di 70 tabel dump struktur produksi 01-10-2026, dengan nama FK legacy.
    - `pengguna.username`, yang diganti kode Ubah NIP legacy (`L_employee.php:589-599`).
    - `gaji_pegawai.nip` (Slip Gaji).
    - `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui`, yang tanpa FK di legacy tetapi wajib FK di v2 (DoD C-01).
    - Keberadaan v2: `Ada` 61, `TidakDiimpor` 11, `BelumDiputuskan` 7.
    - Per fase: 0: 1, 1: 2, 2: 2, 3: 40 (B-01 16 + B-02 24, sama dengan draf DBV-012/013 revisi D1 termasuk `pegawai_ak_siasn`), 4: 8, 5: 2, 6: 1, 7: 5.
    - Method: `semua`, `perFase`, `sampaiFase`, `perJenis`, `perKeberadaan`.
  - `FormatNip`:
    - Format NIP 1–18 digit ASCII, sama dengan aturan akun DBV-010. Auth tidak punya versi publik yang bisa dipanggil kelas murni.
    - `periksaKoreksi` untuk B-06: NIP lama wajib, NIP baru valid dan berbeda dari NIP lama.
    - Hasil gagal memakai `HasilKalkulasi` → 422.
- `app/Libraries/Kepegawaian/Kalkulasi/Pensiun`: BUP setia legacy `getTanggalPensiun` (`jabatan.umur_pensiun` > 0, Struktural Eselon I/II 60, selain itu 58), sesuai SRS-FR-033 (58/60/65). TMT pensiun = tanggal 1 bulan sesudah ulang tahun ke-BUP. Untuk tanggal lahir 29–31, legacy meluap; deviasi ini terdokumentasi.
- Test tanpa DB:
  - `tests/unit/Kepegawaian/Nip/TabelAnakNipTest`: tidak ada duplikat, entri lengkap, jumlah per jenis/fase/keberadaan, fase 3 = draf B-01/B-02.
  - `FormatNipTest`: kasus batas, koreksi NIP, keselarasan dengan `UserService::validateNip`/`PenggunaModel::NIP_MAX_DIGITS`.
  - `tests/unit/Kepegawaian/Kalkulasi/PensiunTest`: urutan BUP, deviasi tanggal 29–31, identik dengan legacy untuk tanggal 1–28 pada 1950–1975 × 58/60/65.
  - `KalkulasiMurniTest` kini juga menjaga folder `Nip/` dan menutup celah yang ditemukan di CR-027: pemanggilan berawalan `\` (`\date()`, `\random_int()`, `\CodeIgniter\I18n\Time::now()`) sebelumnya lolos karena PHP 8 menokenisasinya sebagai T_NAME_FULLY_QUALIFIED. Daftar fungsi terlarang diperluas (varian `date_create*`, angka acak, `getenv`/`env`) dan superglobal dilarang, mengikuti `SlipGajiMurniTest`; self-test memuat contoh pelanggaran baru.
- Uji mutasi sederhana (2 Oktober 2026): 40 mutan pada `Pensiun`, `FormatNip`, `TabelAnakNip`, dan pemindai `KalkulasiMurniTest` (BUP 58/60/65, urutan `umur_pensiun` → Eselon I/II, TMT tanggal 1 bulan berikutnya, awal bulan lahir, batas 1/18/19 digit, `\z`, digit non-ASCII, trim, koreksi NIP sama/beda, batas fase, awalan `\`). 38 mati; 2 yang lolos setara secara perilaku: `in_array` ketat → longgar (parameter sudah `?int`) dan `perFase` tanpa cek `Ada` (entri selain `Ada` selalu ber-fase `null`, dijaga `testSetiapEntriLengkap`).
- Rebase ke main 2 Oktober 2026: registry diselaraskan dengan revisi D1 draf DBV-012 (PR #20), yang memasukkan `pegawai_ak_siasn` ke B-01 (keputusan 8 #6, belum disetujui DB Validator).

**Belum / di luar CR-036**
- `NipCascadeService` (B-06): transaksi salin → arahkan ulang → hapus, cek keunikan NIP baru (`pegawai` dan `username` akun lain), audit log, dan role 1 only. Semua menunggu B-01/B-02.
- Test ber-DB B-21 yang mencocokkan registry dengan `information_schema` dan menguji cascade + rollback, setelah tabel B-01/B-02 ada. Draf DBV-012 sudah punya `NipReferenceRegistryTest` untuk tabel B-01/B-02; penyatuannya adalah pertanyaan terbuka CR-036 #5.
- Pemakaian `Pensiun` di dashboard (B-19/B-20), cron `rekomendasi_pensiun` (H-08), dan daftar pensiun (F-09).
- Validasi gelar dan nama tidak dikerjakan. Legacy hanya punya format tampilan `formatNamaGelar` (`helpers/function_helper.php:102-108`) tanpa validasi, dan spesifikasi v2 tidak menyebut aturan gelar.

**Pertanyaan terbuka CR-036** (detail dan usulan di README modul):
1. Username akun saat koreksi NIP (usulan: ganti hanya bila username = NIP lama; password tidak disentuh).
2. `gaji_pegawai.nip` dan kolom atasan `riwayat_cuti` ikut dikoreksi. Kolom `riwayat_cuti` wajib ikut karena FK RESTRICT.
3. Kolom NIP non-FK di luar registry.
4. Tujuh tabel `BelumDiputuskan`.
5. Penyatuan dengan registry draf DBV-012.
6. Pensiun PPPK memakai `mhpk_akhir`.
