# Adapter data Tukin (`Libraries/Presensi/TukinData`) — Fase 5A M6 (MAKE-033)

Kode ber-DB yang menyiapkan argumen `KalkulasiTukin::hitung()` (library murni `Presensi/Tukin`, tidak diubah) dari data
v2. Acuan: PRD `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md` §7.7. Legacy = rekap aktif `laporan_tukin_us_skp`
(`libraries/hr/L_presensi.php`, K-1) dan pengambil data pegawainya `L_employee::get_all_pegawai_dh_spe`
(`libraries/hr/L_employee.php`), salinan legacy `771f8b4`. 5A **tidak** membuat endpoint Tukin (D-08 = 5B).

## Kontrak

`TukinDataService::siapkan(nip, tahun, bulan, ?hariIni)` → `ArgumenTukin {periode, hariLibur, harian, konfigurasi,
pegawai}`. Rentang hari libur & harian = awal periode **sebelumnya** s.d. akhir periode berjalan (lookback cuti sakit,
README `Presensi/Tukin` "Kontrak data"). Galat terkendali = turunan `GalatDataTukin`:

| Kelas | Kapan |
|---|---|
| `KonfigurasiTukinTidakLengkap` | Tarif wajib `TL1/PSW1`, `TL2/PSW2`, `TL3/PSW3`, `TA`, `TK` belum diatur di Web Config (tidak pernah diganti 0) |
| `RentangPuasaGanda` | Lebih dari satu `rentang_puasa` beririsan dengan periode |
| `DataTukinTidakSah` | Baris `bkn_periode_ekinper` ganda/tidak berformat `MM-DD`, periode BKN tidak berakhir di bulan itu, pegawai tidak ada, atau nilai ditolak library (mis. tarif 0,01% → faktor 0,2 tidak bulat) |
| `DataTukinBelumTersedia` | Sumber cuti/TB bawaan 5A (fail-closed sampai 5B) |

Sumber cuti/TB dipilih `Config\Presensi::$sumberCutiTb` (bawaan `SumberCutiTbBelumTersedia`); 5B menggantinya dengan data
Fase 4. 5A **dilarang** membaca `riwayat_cuti`/`riwayat_tb`.

## Aturan per sumber

| Sumber | Aturan | Label / rujukan |
|---|---|---|
| Konfigurasi | Tarif dari `WebConfigService::values()`; decimal (float) dikembalikan ke string kanonik ≤ 2 desimal karena `KonfigurasiTukin` menolak float. Tarif opsional = `KonfigurasiTukin::TARIF_BAWAAN`; `LKH` tidak dipakai. Jam: 6 key `time` grup "Jam Kerja & Puasa" (F5-16). Puasa: rentang `rentang_puasa` (`date_range_list`) yang beririsan dengan periode | PRD §7.7.2–7.7.3 |
| Periode | `bkn_periode_ekinper` `tahun` = T, `bulan` = MM dua digit, `jenis_periode IS NULL` (tanpa saring `status`); awal/akhir = `T-{periode_awal}`/`T-{periode_akhir}`; Januari mulai 16 Desember; tanpa baris → 16 bulan lalu s.d. 15 | [K] `L_presensi.php:11852-11876` |
| Hari libur | `HariLiburService::tanggalLibur` (status aktif), DATE tanpa konversi zona; akhir pekan oleh `HariKerja` | PRD §7.7.4 |
| Predikat SKP | `riwayat_skp_periodik` `status = 1`, `periode_id` = id baris BKN, `hasil_akhir IS NOT NULL`, huruf kecil; tanpa baris BKN/data → null | [K] `L_presensi.php:12397-12401` |
| Jumlah anak | `detail_anak` `tgl_lahir` ≤ akhir periode milik `riwayat_keluarga` `status = 1` **dan `tgl_perkawinan` ≤ akhir periode** (syarat perkawinan tidak disebut PRD; disetujui reviewer CR 20-10-2026 sebagai perilaku legacy) | [K] `L_presensi.php:12170-12198` |
| TMT keluar | `pegawai.tmt_status` bila `pegawai.status = 2` | [V2] README Tukin |
| TMT masuk | Lihat bagian berikut | [K] + satu [V2] |
| Konket | `absen_ijin` `status = 'V'`, `affect_tukin = 1`, rentang `DATE(date_start)`..`DATE(date_end)` beririsan; setiap tanggal mendapat `{affect_tukin, kategori}`; tanggal tumpang tindih → baris terakhir menurut `date_start` naik | [K] `L_presensi.php:12343-12373` |
| Presensi | `dh_online` jam lokal apa adanya — **menyusul** setelah DBV-023 (D-01) merged | PRD §7.7.6 |

## TMT masuk pegawai baru (keputusan reviewer CR 20-10-2026)

1. `gol_ruang` dan `tmtsk_pangkat` = baris `riwayat_kp` `status = 1` terakhir (`tmtsk DESC`) dengan `tmtsk` ≤ **awal**
   periode. Bila tidak ada dan pegawai dibuat (`created_at`) setelah akhir periode, batasnya ≤ **akhir** periode.
   [K] `L_employee.php:6439`, `:6453`, `:6555-6560`.
2. PPPK (`id_jenis_pegawai` = `Jabatan::JENIS_PEGAWAI_PPPK` = 6): kedua nilai ditimpa `gol_pppk` dan `tmtsk` dari
   `riwayat_mutasi_jabatan` (bukan snapshot `pegawai_mutasi_jabatan`) `status = 1`, `jenis_jabatan = 1` terakhir
   (`tmtsk DESC`) dengan `tmtsk` ≤ akhir periode. Batas ini = `$maxDateCreatedPeg` legacy (`$strCM_end_BKN`), bukan
   `$maxRwyDate` yang dikomentari di kueri itu. [K] `L_employee.php:6470-6482` (kueri), `:6580-6584` (penimpaan memakai
   `id_jenis_pegawai == JP_PPPK`); dikonfirmasi reviewer CR 20-10-2026.
3. Rekap: `gol_ruang` lebih dari 5 karakter diawali "CPNS" → `tmtMasuk = tmtsk_pangkat`; selain itu PPPK →
   `tmtMasuk = tmtsk_pangkat` bila **setelah** hari kerja pertama periode (`$firstDayHK_CM`, dihitung `HariKerja` atas
   periode + libur); lainnya null. [K] `L_presensi.php:12703-12716`.
4. **[V2]** Cabang PPPK di rekap (langkah 3) mengenali PPPK lewat **id** (`Jabatan::JENIS_PEGAWAI_PPPK`), bukan nama
   `pegawai.jenis_pegawai == 'PPPK'` seperti legacy `L_presensi.php:12707`. Alasan: nama master `jenis_pegawai` dapat
   diubah Super Admin lewat CRUD, sehingga cek nama bisa gagal diam-diam dan membuat potongan Tukin salah; legacy sendiri
   memakai id di tempat lain (`:12621`, `L_employee.php:6580`). Satu pengenal (id 6) dipakai di semua langkah.

Risiko yang tercatat: hasil langkah 1–2 bergantung pada kelengkapan `riwayat_kp`/`riwayat_mutasi_jabatan` hasil impor;
butir cutover dicatat reviewer CR di PRD 5A dan runbook impor.
