# Progress Fase 3 — Modul B: Kepegawaian Core

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak task lengkap: `03-Kepegawaian.md`. Aturan kalkulasi dan tanggal bisnis: `backend/app/Libraries/Kepegawaian/README.md`.

**Terakhir diperbarui:** 7 Oktober 2026 — S0-A (MAKE-002, Sprint 0-1): kontrak backend Fase 3 dibekukan, tabel status dipecah per task dengan kolom WS & Key. Riwayat sebelumnya: CR-036 (2 Oktober 2026, registry kolom NIP, format NIP, BUP pensiun) dan CR-025 (di main lewat merge `a540703` 30-09-2026, kalkulasi murni KP/KGB/hukdis/masa kerja) — rincian di bagian "Persiapan tanpa skema".
**Entry criteria:** **terpenuhi** — Fase 1 + Fase 2 DONE (Fase 2 sign-off 07-10-2026); skema B-01/B-02 (44 tabel, DBV-012/DBV-013, PR #20) dan master G-02/G-03/G-09 sudah di `main`. FK G-02 ↔ pegawai/riwayat = DBV-019 (PR #24, menunggu review DB Validator).

Pembagian kerja Fase 3: WS-1 (mesin riwayat + riwayat karier/administrasi, `docs/fase3/WS1.md`) dan WS-2 (lingkup, lampiran, NIP, biodata, jabatan/struktur, Konket, LKH, halaman pegawai, `docs/fase3/WS2.md`). Key per paket milestone = peta `docs/fase3/WS1.md` §3.8.1. **Setiap WS hanya mengubah baris miliknya** di tabel ini.

| Task | WS | Key | Status | Ringkas |
|---|---|---|---|---|
| B-01 Migration tabel pegawai & snapshot | selesai | DBV-012 (+ DBV-019 FK G-02) | DONE (skema) | Tabel `pegawai` + snapshot `pegawai_*` di `main` (PR #20). FK snapshot → master G-02 = DBV-019 (PR #24) |
| B-02 Migration tabel riwayat | selesai | DBV-013 | DONE (skema) | Tabel `riwayat_*`, `jenis_rwy`, `document_attachment` di `main` (PR #20) |
| B-03 CRUD biodata (dua jalur) | WS-2 | MAKE-010 | TODO | Termasuk backend dasar B-20 |
| B-04 Approval draft biodata | WS-2 | MAKE-011 | TODO | |
| B-05 Tambah & hapus pegawai | WS-2 | MAKE-011 | TODO | |
| B-06 Koreksi NIP (cascade) | WS-2 | MAKE-010 | TODO | Bahan murni sudah ada (CR-036): registry 79 kolom NIP (`TabelAnakNip::sampaiFase(3)` = 45), `FormatNip`. Service `nipCascade` terdaftar (stub S0-A) |
| B-07 Riwayat jabatan / mutasi | WS-2 | MAKE-012 | TODO | Slug `jabatan` |
| B-08 Riwayat KP | WS-1 | MAKE-005 | TODO | Validasi TMT 1 April/1 Oktober sudah ada (CR-025) |
| B-09 Riwayat KGB | WS-1 | MAKE-006 | TODO | Validasi jarak 2 tahun sudah ada (CR-025); acuan jarak = KP/KGB terakhir (keputusan #10) |
| B-10 Riwayat pendidikan | WS-1 | MAKE-005 | TODO | Bersama freeze engine-v1 |
| B-11 Riwayat diklat & seminar | WS-1 | MAKE-007 | TODO | |
| B-12 Riwayat SKP & LKH | WS-1 (B-12a SKP) / WS-2 (B-12b LKH) | MAKE-008 / MAKE-013 | TODO | Approver LKH = atasan langsung (ikut legacy) |
| B-13 Riwayat Konket | WS-2 | MAKE-013 | TODO | Halaman usulan mandiri, bukan jenis engine |
| B-14 Riwayat hukdis | WS-1 | MAKE-006 | TODO | Akhir hukdis dari masa sanksi sudah ada (CR-025) |
| B-15 Riwayat angka kredit | WS-1 | MAKE-008 | TODO | Slug `ak`, `ak-siasn` |
| B-16 Riwayat keluarga & alamat | WS-1 | MAKE-007 | TODO | |
| B-17 Karpeg, Karis/Karsu, tanda jasa, organisasi | WS-1 | MAKE-007 | TODO | Karpeg/Karis = halaman usulan mandiri |
| B-18 Upload lampiran | WS-2 | MAKE-009 | TODO | Batas 1/2/5 MB per jenis (ikut legacy); interface beku S0-A |
| B-19 Struktur organisasi | WS-2 | MAKE-012 | TODO | |
| B-20 Detail pegawai & pencarian (frontend) | WS-2 | MAKE-003 (fondasi) / MAKE-010 (dasar) / MAKE-014 (penutup) | TODO | Menu Fase 3 tersembunyi (`ACTIVE_PHASE = 2`) sampai penutup |
| B-21 Unit test Modul Kepegawaian | WS-1 (snapshot) / WS-2 (cascade NIP) | MAKE-008 / MAKE-010 | **IN_PROGRESS** | 3 dari 5 butir DoD lolos (CR-025: periode KP, jarak KGB, akhir hukdis). Sisa: snapshot sync hanya di approval final (WS-1) dan cascade NIP + rollback (WS-2) |

Paket lintas task: S0-A kontrak backend (MAKE-002, bagian di bawah), S0-B fondasi frontend (MAKE-003, WS-2), WS-1 M1 SnapshotSync + RiwayatEngine (MAKE-004).

## S0-A — MAKE-002 (kontrak backend Fase 3)

Branch `ws1/make-002-s0a-kontrak-backend`; tanpa perubahan skema. Kontrak lengkap: `app/Controllers/Api/Kepegawaian/README.md` bagian "Kontrak API Modul B". Yang dibekukan saat MAKE-002 masuk `main` (hanya berubah aditif, oleh pemiliknya):

- **Route terpisah:** `Config/RoutesRiwayat.php` (WS-1) dan `Config/RoutesPegawai.php` (WS-2) lewat `Routing::$routeFiles`; `Routes.php` tidak disentuh lagi. Kerangka grup `api/v1`, path kontrak yang belum didaftarkan menjawab 404 terkendali.
- **Service:** 12 service didaftarkan sekali di `Config\Services` dengan tipe kembalian interface — `pegawaiScope`, `attachmentService`, `storageAdapter`, `riwayatRegistry`, `riwayatService`, `snapshotSync`, `pegawaiService`, `biodataService`, `nipCascade`, `strukturService`, `lkhService`, `konketService`. Stub fail-closed di `App\Libraries\Kepegawaian\Stub` (scope menolak semua; lainnya `App\Exceptions\BelumTersediaException` 501); fake hanya di `tests/_support/Kepegawaian`.
- **Interface lintas-WS:** `PegawaiScopeInterface`, `AttachmentServiceInterface`, `StorageAdapterInterface`, value object `AturanLampiran` (milik WS-1, dipakai WS-2; keputusan reviewer CR 07-10-2026); `RiwayatRegistryInterface`, `RiwayatServiceInterface`, `SnapshotSyncInterface` (milik WS-1); interface penanda service WS-2 lain.
- **Mesin riwayat sebagai data:** `RiwayatDefinisi` (satu berkas per jenis, auto-discovery `RiwayatRegistry`), `StatusRiwayat` (0/1/2/10; 3 di belakang flag `Config\Kepegawaian::$statusDiprosesAktif`, nonaktif default), `AksiRiwayat`, `AlurRiwayat`, `AturanSnapshot`, daftar slug `{jenis}` (`JenisRiwayat::SLUG`), descriptor tab.
- **Fixture bersama:** `Tests\Support\Kepegawaian\PegawaiFixtureTrait` — master G-02 → `pegawai` → `pegawai_mutasi_jabatan` → `pengguna`; hanya INSERT, dipakai di test `DatabaseTestCase` (base case MAKE-001, sudah di `main`), patuh FK DBV-019, NIP sintetis 18 digit.
- **Revisi review CR (07-10-2026):** lampiran = daftar `AturanLampiran` per kode `jenis_rwy` + lookup registry `definisiUntukLampiran()`/`aturanLampiran()`; lampiran satu request dengan tambah/ubah riwayat (`$berkas`, `wajib` ditegakkan engine); `AttachmentService` terikat NIP (`ambil`/`hapus(nip, id)`) + aturan izin per jenis; filter `jwt` di level grup kedua berkas route (izin riwayat di service — penyimpangan §2.3.1 disetujui reviewer CR); scope registry di-resolve tiap dipakai; fixture menolak akun tanpa pegawai, menurunkan rantai dari `id_jabatan`, helper `buatJabatanKoordinasi`/`buatRumpunJabatan`/`buatRiwayatMutasiJabatan`.
- **Test:** `tests/unit/Kepegawaian/` (StatusRiwayat, AturanLampiran, AturanSnapshot, registry + descriptor + lookup lampiran, stub fail-closed reflektif, fake lampiran) dan `tests/Kepegawaian/` (service bertipe interface + wiring scope, route riwayat/pegawai ber-`jwt` per pemilik, fixture lolos FK + rollback).

## WS-1 M1 — MAKE-004 (SnapshotSync + RiwayatEngine backend)

Branch `ws1/make-004-m1-engine-be`; tanpa perubahan skema/migration. `Config\Services`: baris stub `riwayatService` dan
`snapshotSync` diganti kelas nyata (`RiwayatEngine`, `SnapshotSync`; kelas stub tetap ada untuk test fail-closed). Belum
ada Definisi jenis nyata (mulai M2: B-10 + B-08), jadi endpoint riwayat di produksi menjawab 404 "Jenis riwayat tidak
ditemukan" sampai Definisi pertama masuk. Rincian: `app/Libraries/Kepegawaian/README.md` bagian "Mesin riwayat".

- **SnapshotSync** (`Riwayat/SnapshotSync.php`) + **PemilihSnapshot** (fungsi murni): pilih ulang baris status 1 per
  `AturanSnapshot` (filter logika tiga nilai SQL, urutan berprioritas termasuk kolom tabel join, seri → PK terbesar [V2]),
  multi-target, DELETE bila kosong, audit manual create/update/delete (entity = tabel snapshot), di transaksi pemanggil.
- **RiwayatEngine** (`Riwayat/RiwayatEngine.php`, service `riwayatService`): daftar/detail/tambah/ubah/hapus (status
  10)/proses; urutan pemeriksaan kontrak (jenis → izin Definisi → PegawaiScope → NIP → baris milik NIP); status awal/ubah
  ikut legacy (data di Definisi); kunci baris Disetujui; validasi field `MasterField` + ref master; hook
  validate/beforeSave/afterApprove; lampiran satu transaksi (`berkas.<id_riwayat>`); kunci baris `pegawai` `FOR UPDATE`;
  `approved_by`, `reason_note`, `show_ua_*`, `show_notif`/`notif_date` UTC; event notifikasi `riwayatNotifikasi` sesudah
  commit. Model generik `App\Models\Kepegawaian\RiwayatModel` (audit otomatis, hapus lunak = event `delete`).
- **HTTP:** `RiwayatController` (`BaseRiwayatController`) + loop route per slug `JenisRiwayat::SLUG` di
  `Config/RoutesRiwayat.php`.
- **Kontrak (aditif):** `SnapshotSyncInterface::sinkronkan(..., ?AuthContext $pelaku = null)`; `RiwayatDefinisi::statusAwal()`,
  `statusSetelahUbah()`, `roleKunciHapusDisetujui()`, `urutanDaftar()`; README kontrak API bagian "Rincian engine (M1)".
- **Test:** `tests/unit/Kepegawaian/Riwayat/PemilihSnapshotTest.php` (pemilih murni per aturan dok DBV-012 §5.1),
  `tests/Kepegawaian/Riwayat/SnapshotSyncTest.php`, `RiwayatEngineTest.php` (draft → setujui/tolak → snapshot → audit →
  lingkup, kunci, validasi, lampiran, rollback), `RiwayatEndpointTest.php`, dan trait B-TC generik
  `Tests\Support\Kepegawaian\RiwayatBtcTrait` (dipakai `BtcUjiPendidikanTest`, `BtcUjiKpTest`; jenis nyata memakainya
  mulai M2) dengan Definisi uji `tests/_support/Kepegawaian/Riwayat/` dan fake scope/lampiran S0.

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
