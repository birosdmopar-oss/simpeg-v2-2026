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

