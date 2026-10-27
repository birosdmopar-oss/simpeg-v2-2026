# Modul C — Layanan & Approval (Fase 4)

Service/business logic modul ini (`App\Libraries\Layanan\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

## Cuti — logika murni (`Cuti/`, MAKE-022 Tahap 2b, PRD Fase 4 v1.1 §6.1.4)

Dikerjakan **sebelum** DBV-022 disetujui karena tidak bergantung tabel Modul C: semua kelas `final` dengan method statis
(kecuali value object `readonly`), **tanpa DB, HTTP, session, jam sistem, angka acak, konfigurasi, lingkungan, atau I/O**
— dijaga `tests/unit/Layanan/Cuti/CutiMurniTest.php` (`PemindaiKemurnian`, pengecualian hanya `createFromFormat('!Y-m-d')`).
"Hari ini", "sekarang", daftar tanggal libur (`HariLiburService::tanggalLibur()`, status 1), baris saldo, dan jumlah
"menunggu" diterima sebagai parameter. Konstanta runtime ada di `Config\Cuti` dan disalurkan lewat `Config\Cuti::aturan()`
→ `AturanCuti` (library tidak boleh membaca `Config\*`). Service ber-DB M3 (`KuotaCutiService`, `CutiApprovalService`)
membungkus kelas ini dengan transaksi, `FOR UPDATE`, dan `audit_logs`; DILARANG ada implementasi potong/refund lain.

Label sumber: **[K]** kode legacy (`L` = `libraries/hr/L_cuti.php`, `C` = `controllers/hr/rwy/Cuti.php`, `FH` =
`helpers/function_helper.php`), **[V2]** keputusan PRD v1.1 (13 keputusan 26-10-2026).

| Kelas | Isi |
|---|---|
| `AturanCuti` | value object konstanta §6.3.1 (`KUOTA_N` 12, `MAKS_N1`/`MAKS_N2` 6, `MAKS_TOTAL` 24, `MAKS_UTANG` 12, jenis hari kerja [1,3,5] / kalender [2,4,6], `DURASI_BESAR` [1,2,3], alasan penting 1 bulan, CLTN 3 tahun, `JAM_BATAS_TUNDA` 12:00) + konstanta `JENIS_*`; `bawaan()`; `satuanHariKerja(jenis)` |
| `StatusCuti` | kode 0/1/2/3/4 [K] COMMENT D1 + label, `FINAL` {1,2,4}, `MENUNGGU` {0,3}, `EFEK_FINAL` {1,4}, domain `status_al`/`status_pyb`; tanpa 10 (D6) |
| `SaldoCuti` | value object `pegawai_sisa_cuti` (N, N-1, N-2, utang, ditangguhkan): `dariBaris`/`keBaris`, `total()`, `saldoTahunDepan()`, `periksaBatas()` D8 per kolom (§6.3.6) |
| `HitungLamaCuti` | §6.3.2: hari kerja Senin–Jumat minus libur (dihitung sendiri, paritas `Presensi\Tukin\HariKerja` diuji), hari kalender inklusif, `tglAkhirBesar()` = `addMonth` legacy dijepit −1 hari [K `FH:960-966`], `liburTerpakai()` untuk respons kalkulator |
| `PorsiTahunCuti` | §6.3.3: pecah lama ke `tahun_ini`/`tahun_depan` pada 31-12/01-01; hanya tahun acuan + 1 (di luar itu = kesalahan pemanggil); `tahunAcuan(created_at)` |
| `ValidasiTanggalCuti` | §6.2.3 + §6.3.2: `tgl_mulai`/`tgl_akhir`/`durasi` dengan semua galat dikumpulkan (A-09); batas jenis 5 (`+1 month` PHP) dan 6 (`+3 year` PHP) inklusif; batas 31-12 tahun acuan + 1 **hanya jenis 1** (lihat catatan); `periksaLama(0)` |
| `KuotaCuti` | §6.3.4/§6.3.5: `periksa()` (pesan [K `L:1648`], `saldo_tidak_terdaftar`), `potong()` N-2 → N-1 → N + utang/ditangguhkan (gagal = 422, saldo tidak berubah), `kembalikan()` N → N-1 → N-2 batas 12/6/6 tanpa negatif; jaminan impor T13 diuji |
| `TransisiCuti` | §6.4.2/§6.4.3: `langkahTunggal`, `tahapAktif`, `nipPelaku`, `proses()` → status/`status_al`/`status_pyb` (T4: `status_pyb` tidak ditulis, keputusan #3), galat `aksi`/`reason_note`/`status`; `keteranganPetugas()` pemisah `"\n"` (keputusan #7) |
| `EfekFinalCuti` | §6.4.5 predikat: efek hanya ke 1/4; `perluPotongSaldo` jenis 1; `cltnAktif` TM ≤ hari ini ≤ TA (T18); `perluSurat` |
| `PengingatCuti` | §6.4.7: `batasTunda` `tgl_mulai` 12:00:00 WIB, `bolehTunda(sekarang)` inklusif (T17), `flagTahap`, pesan [K `C:971-976`] |

Test: `tests/unit/Layanan/Cuti/{CutiMurniTest, HitungLamaCutiTest, KuotaBoundaryTest (§6.3.8 K1–K21 + §6.3.10 T9, T11–T14),
ValidasiTanggalCutiTest (T2, T3, T7, T8), TransisiCutiTest (T4–T10, T16–T18, single end node BFS)}`.

Catatan keputusan coder (dicatat untuk reviewer):

1. Batas `tgl_akhir ≤ 31-12 tahun acuan + 1` (§6.2.3) diterapkan **hanya untuk jenis 1**: batas itu berasal dari kuota
   dua tahun (keputusan #6), sedangkan CLTN sah sampai 3 tahun dan jenis 2–6 tidak memotong kuota.
2. Paritas T13 berlaku untuk saldo berbentuk ternormalisasi legacy (N terisi dulu sampai 12, lalu N-1 sampai 6, lalu N-2);
   untuk bentuk lain (mis. (5,6,6)) rumus N-2 → N-1 → N dan normalisasi memang berbeda — bentuk itu tidak pernah
   dihasilkan legacy maupun v2.
3. Pesan batas jenis 5/6 ("Lama cuti karena alasan penting maksimal 1 bulan." / "… di luar tanggungan negara maksimal 3
   tahun.") dan "Tanggal … tidak valid." adalah teks [V2] (legacy tidak punya teks server untuk kasus itu).
4. Berkas ke-10 `AturanCuti` di luar daftar §6.1.4 — perlu karena library tidak boleh membaca `Config\Cuti`.

Belum ada di Tahap 2b (M3+): Model, controller, route, migration, penentuan AL/PyB, tumpang tindih atas baris DB,
notifikasi, `KuotaCutiService`/`CutiApprovalService`.

## TB, IB, PMK — logika murni (`Studi/`, `Tb/`, `Pmk/`, MAKE-024 bagian awal, PRD Fase 4 v1.1 §6.7–§6.9)

Pola sama dengan Cuti Tahap 2b (§6.1.4): kelas `final` statis / value object `readonly`, **tanpa DB, HTTP, session, jam
sistem, angka acak, konfigurasi, lingkungan, atau I/O** — dijaga `tests/unit/Layanan/LayananStudiPmkMurniTest.php`
(`PemindaiKemurnian` pada `Studi/`, `Tb/`, `Pmk/`, dan `app/Interfaces/Layanan/`; pengecualian hanya
`createFromFormat('!Y-m-d')`). "Hari ini", master, TMT CPNS, dan baris pembanding (tumpang tindih, perpanjangan) diterima
sebagai parameter. Konstanta runtime di `Config\Layanan` → `aturanStudi()` / `aturanPmk()`. Service ber-DB M5 (`Tb/TbService`,
`Tb/StatusTugasBelajar`, `Ib/IbService`, `Pmk/PmkPengajuanService`, …, PRD §6.7–§6.9 file boundary) membungkus kelas ini.

`Studi/` = bagian yang sama untuk TB dan IB (PRD §6.8.1 "sama TB kecuali disebut"); `Tb/` = khusus TB. Label sumber: **[K]**
kode legacy (`L_tb.php`, `L_ib.php`, `L_pmk.php`, `controllers/hr/services/Local.php`, DDL `simpeg01_struktur_lengkap`),
**[V2]** keputusan PRD (Q-03, Q-11, Q-12, Q-15, Q-16, Q-23).

| Kelas | Isi |
|---|---|
| `Studi/AturanStudi` | value object: jenjang > 3, `up` bawaan, `reason_note` ≤ 255 byte; `JENIS_TB`/`JENIS_IB`, `namaLayanan()` |
| `Studi/StatusStudi` | kode 0/1/2/3 [K DDL] + label, `FINAL` {1,2}, `TUMPANG_TINDIH` {0,1,3,4} ([K] {0,3,4} + 1 [V2 TB-04]) |
| `Studi/RentangTanggal` | urai `!Y-m-d`, selisih `DateTime::diff`, irisan inklusif, tambah hari |
| `Studi/LamaTahunStudi` | `lama_tahun` = `diff.y + diff.m/12` 1 desimal [K TB-17 `Local.php:3203-3214`], dihitung server (Q-03); kolom `"3.90"` |
| `Studi/ValidasiStudi` | isian wajib TB/IB (semua galat dikumpulkan, TB-03), `tgl_akhir ≥ tgl_mulai`, jenjang, status awal TB 0\|1 + data SK bila 1, IB selalu 0; `bentrok()` + `pesanBentrok()` [K]; TB vs IB tidak dibandingkan (IB-09, Q-11) |
| `Studi/TransisiStudi` | satu langkah 0 → setujui (data SK wajib, `show_pl = 1`) / tolak (`reason_note` wajib ≤ 255 byte) → final; selain dari 0 → `errors.status` |
| `Tb/PerpanjanganTb` | validasi (wajib, `akhir ≥ mulai`, induk status 1 [V2 TB-13]), saran mulai = akhir efektif + 1 [K], akhir efektif = MAX, `ringkasan()` = MAX terpisah pengganti trigger `riwayat_tb_perpan_af*` [K] |
| `Tb/BebasPresensiTb` | `bebasPresensiPenuh(daftarTb, tanggal)`, `rentangBebasPresensi(daftarTb, dari, sampai)`, `statusPenuh()` (D15, turunan) — inti murni untuk `App\Interfaces\Layanan\StatusTugasBelajarInterface` (Fase 5 D-06) |
| `Pmk/AturanPmk` | value object: `jenis_pmk` {Swasta, Negeri} [V2 P-5], `reason_note` ≤ 255 byte, `mker` ≤ 255 (TINYINT) |
| `Pmk/StatusPmk` | kode 0/1/2/3 [K DDL] + label kolom & label UI v2, `FINAL` {1,2}, `ikutTumpangTindih` (≠ 2 [K]), `detailBolehDibuka` (Q-16), `bolehHapus` (≠ 1 [K]) |
| `Pmk/MasaKerjaPmk` | kalkulator `pmk/hitung-mker`: `mker_th = diff.y`, `mker_bl = diff.m` [K `Local.php:4574-4601`]; nilai pengajuan tetap dari klien (D19/P-4) |
| `Pmk/ValidasiPmk` | isian wajib (semua galat), enum `jenis_pmk`, `tgl_akhir ≥ tgl_awal`, `tgl_akhir < TMT CPNS` [V2 P-3], `mker` 0..255; `bentrok()` status ≠ 2 [K] |
| `Pmk/TransisiPmk` | 0 → proses → 3; 0/3 → setujui → 1, tolak → 2 (`reason_note` wajib [K] ≤ 255 byte); 1/2 final terkunci [V2 P-14]; `aksiTersedia()` |

Test: `tests/unit/Layanan/{LayananStudiPmkMurniTest, Studi/{LamaTahunStudiTest, ValidasiStudiTest, TransisiStudiTest},
Tb/{PerpanjanganTbTest, BebasPresensiTbTest}, Pmk/PmkMurniTest}` — boundary kabisat, akhir bulan, perpanjangan berganda,
lintas tahun, single end node (BFS PMK).

Catatan keputusan coder (dicatat untuk reviewer):

1. **Bebas presensi = rentang TB ATAU salah satu rentang perpanjangan** (teks PRD §6.7.5), bukan satu rentang
   `tgl_mulai..MAX(tgl_akhir, tgl_akhir_perpan)`. Akibatnya celah antara akhir TB dan awal perpanjangan **tidak** bebas,
   sama dengan `laporan_tukin`/`laporan_tukin_us_skp` (acuan Tukin). `rekap_full_xls`/`rekap_uma*` legacy menganggap
   celah itu TB (anomali, tidak dibawa). `PerpanjanganTb::tglAkhirEfektif()` (MAX) hanya untuk tampilan/saran tanggal.
2. **Status TB/IB 3 (Diproses) dan 4** hanya mungkin dari data impor; v2 tidak menulisnya dan `TransisiStudi` menolak
   memproses keduanya (`errors.status`), karena PRD hanya mendefinisikan transisi dari 0. Data impor berstatus 3/4 perlu
   keputusan cutover (dibiarkan atau dinormalkan ke 0) — tidak diputuskan di sini.
3. **Teks pesan.** Wajib isi memakai format PRD "{Label} wajib diisi." dengan label legacy. Teks tumpang tindih, urutan tanggal
   PMK, TMT CPNS, aksi, dan alasan PMK dipakai persis [K]. Teks [V2] baru: jenjang/tanggal tidak valid, status awal,
   perpanjangan, status tidak dapat diproses, dan batas 255 byte. Pesan "Alasan ditolak tidak boleh kosong." [K PMK]
   dipakai juga untuk TB/IB, karena alasan tolak TB/IB di legacy hanya wajib di klien.
4. **PMK `tgl_akhir ≥ tgl_awal`** dicek bila kedua tanggal sah. Legacy mengeceknya hanya bila belum ada galat lain, tetapi v2
   mengumpulkan semua galat.
5. **TMT CPNS tidak diketahui** (`null`, mis. data impor tanpa `pegawai_cpns`) → cek P-3 dilewati. Service M5 memutuskan
   sumber TMT (snapshot `pegawai_cpns` atau `MasaKerja::tmtCpnsDariNip`).
6. **`mker_th`/`mker_bl` divalidasi bilangan bulat 0..255** (tipe TINYINT) [V2]. Legacy hanya mengecek kosong. Hasil 0 th
   0 bl tetap diterima server [K]; peringatan "harus melebihi 1 bulan" hanya di kalkulator klien (`MasaKerjaPmk::kurangSebulan`).
7. **`App\Interfaces\Layanan\StatusTugasBelajarInterface`** dibuat sekarang (kontrak murni, tanpa implementasi) supaya
   Fase 5B bisa mengandalkan nama dan signature PRD §6.7.5. Implementasi DB dan `Config\Services::statusTugasBelajar()`
   menyusul di M5 setelah DBV-022.
8. **Hak ubah/hapus TB/IB** (pemilik status 0 dan `tgl_mulai > hari ini`, dsb., §6.7.2) belum dibuat predikatnya; bagian itu
   bergantung pada role/lingkup dan dikerjakan di service M5.

Belum ada di bagian ini (M5 setelah DBV-022 & M2): Model, controller, route, migration, berkas, PIC (`PetugasLayananService`),
notifikasi, efek `id_jenis_status`, surat TB/IB.
