# Progress Fase 3 — Modul B: Kepegawaian Core

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak task lengkap: `03-Kepegawaian.md`. Aturan kalkulasi dan tanggal bisnis: `backend/app/Libraries/Kepegawaian/README.md`.

**Terakhir diperbarui:** 30 September 2026 (CR-025: persiapan tanpa skema — kalkulasi murni periode KP, jarak KGB, akhir hukdis, dan masa kerja untuk B-21; branch `cr-025/aturan-kepegawaian-fase3`, belum di main)
**Entry criteria:** Fase 1 + Fase 2 DONE — belum terpenuhi (G-01/G-02/G-03/G-09 masih terbuka di `02-MasterData.md`). Pekerjaan di file ini sampai entry criteria terpenuhi hanya **persiapan yang tidak butuh tabel baru**, dikerjakan paralel sambil skema Fase 3 menunggu review DB Validator.

| Task | Status | Ringkas |
|---|---|---|
| B-08 Riwayat KP | TODO | Validasi TMT 1 April/1 Oktober sudah tersedia sebagai kalkulasi murni (CR-025); CRUD, snapshot, dan pemanggilan dari service menunggu B-02 |
| B-09 Riwayat KGB | TODO | Validasi jarak 2 tahun sudah tersedia (CR-025); isi daftar acuan (KP ikut atau tidak) menunggu pertanyaan terbuka #3 |
| B-14 Riwayat Hukdis | TODO | Hitung tanggal berakhir dari masa sanksi sudah tersedia (CR-025); kolom masa numerik per SK = keputusan skema B-02 |
| B-21 Unit test Modul Kepegawaian | **IN_PROGRESS** | 3 dari 5 butir DoD lolos sebagai unit test murni (periode KP, jarak KGB, akhir hukdis). Sisa: cascade koreksi NIP + rollback (B-06) dan snapshot sync hanya di approval final (B-03), keduanya butuh tabel B-01/B-02 |
| B-01..B-07, B-10..B-13, B-15..B-20 | TODO | Belum dimulai |

## Persiapan tanpa skema

### CR-025 — kalkulasi murni Kepegawaian (bagian B-21) — pra-review internal sisi CR

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
