# Modul F — Dashboard & News (Fase 7)

Service/business logic modul ini (`App\Libraries\Dashboard\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

## F-03 Statistik (MAKE-036) — `App\Libraries\Dashboard\Statistik\*`

- `StatistikService` — orkestrasi: role, lingkup (`lingkup`/`id` → 422/404/403), cache, ekspor XLSX, audit unduhan. Pabrik statis (tidak ada entri `Config\Services`).
- `KueriStatistik` — kueri agregat per jenis di atas `pegawai` + snapshot `pegawai_*` (padanan `libraries/hr/L_chart.php` legacy), `PegawaiScope::terapkanKeQuery` di setiap builder.
- `LingkupStatistik` (VO kementerian/unit/satker), `BentukLaporan` (kategori × seri → baris/subtotal/total), `BucketUsia` dan `UrutanGolongan` (kalkulasi murni, ber-unit-test).
- `StatistikCache` — key `dashboard_stat_{jenis}_{lingkup}_{id}_{scope}`, TTL 900, invalidasi lewat listener event riwayat + hook `PegawaiModel`; jalur lain basi ≤ TTL (lihat docblock kelas).

Kontrak endpoint: `app/Controllers/Api/Dashboard/README.md`; keputusan: `docs/fase7/PRD_Fase7_Statistik-GeneratorDokumen.md` §5.

## F-13 Beranda (MAKE-087, Jalur D) — `App\Libraries\Dashboard\Beranda\*`

- `RingkasanBeranda` — `GET dashboard/ringkasan`: keluarga admin = tile komposisi + status hari ini per lingkup (kementerian
  / satker / unit) dengan aturan hitung, letak pengecualian NIP (Q7-41), dan TB perpanjangan ikut `L_user::dashboard_sa|
  dashboard_satker|dashboard_unit` legacy; cache angka tetap lewat `CacheService`. Keluarga pegawai = prediksi, bawahan,
  kelengkapan.
- `PengingatBeranda` (4 kartu admin), `DirekturBeranda` (role 1/4), `PrediksiPegawai` + `BawahanPegawai` (beranda pegawai),
  `UlangTahunBeranda` (Q7-45, aturan `function_helper.php:1419-1475`; urutan [I] NIP ASC; Q-D18 `.env` rahasia),
  `KelengkapanData` (kartu kelengkapan role 2 PNS, port `kd_r*`), `ProyeksiTandaJasa` (port murni `get_all_tj` + `next_tj`).
- Konfigurasi: `Config\Dashboard` (`$tampilanKomposisi` Q7-36; RAHASIA hanya `.env` server: `$nipDisembunyikan` Q7-41,
  `$penggunaLihatSemua` Q-D12, `$nipDikecualikanUlangTahun` Q-D18; `$idJabatanDirektur` Q7-38, `$tandaJasaIkutLegacy`,
  `$ttlRingkasanDetik`).

### Deviasi dari legacy — Tanda Jasa satker/unit (keputusan Horii 10-11-2026, [V2])

**(a) Perilaku legacy persis.** Kartu "Tanda Jasa" beranda admin role 3 dihitung `L_user::dashboard_satker` (:448-466) /
`dashboard_unit` (padanan) atas `L_employee::get_tj_satker` / `get_tj_unit` (`L_employee.php:3177-3470`):
- proyeksi selalu memakai tahun berjalan: `get_tj_*($id)` tanpa argumen tahun → lama bekerja per `{tahun berjalan}-08-01`;
- pembanding tanggal TEPAT: bulan < 9 → `tgl_penghargaan == "{tahun}-08-01"`; bulan ≥ 9 → `== "{tahun+1}-08-01"`.
Akibatnya mulai September, proyeksi (tahun berjalan) dibandingkan dengan 1 Agustus tahun depan, padahal pegawai yang genap
10/20/30 tahun pada 1 Agustus tahun depan belum memenuhi syarat menurut proyeksi tahun berjalan → kartu ≈0 pada
September–Desember. Kementerian (`dashboard_sa` + `get_all_tj($nextYear)`, :55-85) tidak terkena karena tahun acuannya
ikut bergeser.

**(b) Perubahan v2.** Satker/unit memakai tahun acuan yang SAMA dengan kementerian (tahun berjalan bila bulan < 9, tahun depan
bila ≥ 9) untuk proyeksi; pembanding tetap tanggal tepat `{tahun acuan}-08-01` (operator `=` legacy satker/unit
dipertahankan — hanya kesalahan tahunnya yang diperbaiki). Lingkup tetap `pmj.id_satker` / `pmj.id_unit` [K]. Kementerian
tidak berubah (`≤` tahun acuan).

Alasan tidak menyeragamkan operator ke `≤` kementerian: `≤` ikut menghitung tunggakan tahun-tahun sebelumnya (pegawai yang
terlewat dan belum menerima); memindahkannya ke satker/unit adalah deviasi tambahan yang tidak diminta dan mengubah angka
Januari–Agustus juga. Dengan `=` angka Januari–Agustus identik dengan legacy; hanya September–Desember yang berubah.

**(c) Dampak angka.** Januari–Agustus: sama dengan legacy. September–Desember: satker/unit kini menghitung pegawai yang genap
10/20/30 tahun pada 1 Agustus tahun depan dan belum memiliki tingkat itu (sebelumnya ≈0).

**(d) Kembali ke legacy.** `Config\Dashboard::$tandaJasaIkutLegacy = true` (atau `.env` `dashboard.tandaJasaIkutLegacy = true`)
tanpa ubah kode; bawaan `false`. Kedua mode dijaga `tests/database/Dashboard/PengingatBerandaTest.php`. Saklar ikut sidik kunci
cache ringkasan, jadi perubahan langsung berlaku.

### Kelengkapan data beranda pegawai (`KelengkapanData`, keputusan Horii 10-11-2026 Q-D13..Q-D17 semua A)

Sumber [K]: `L_user::dashboard_pns` blok KELENGKAPAN DATA (`L_user.php:644-701`), `L_employee::kd_r*` (`L_employee.php:9497-10770`),
view `dashboard_pns.php:104-119`. Kartu tampil hanya bila persen < 100; persen dibulatkan `round()` seperti legacy.

| Kartu (`kode`) | Aturan [K] |
|---|---|
| Data Umum (`data-umum`) | 11 kolom `pegawai` (nama, tgl_lahir, provinsi & kab/kota lahir, agama, jenis kelamin, NPWP, HP, telp kerabat, status nikah, foto) yang terisi (≠ NULL/'') × 100/11 |
| Data Alamat (`alamat`) | ada snapshot `pegawai_alamat` → 100, selain itu 0 |
| Data Keluarga (`keluarga`) | menikah (`status_pernikahan` = 1) tanpa snapshot `pegawai_keluarga` → 0, selain itu 100 |
| Data Pendidikan (`pendidikan`) | jenjang tertinggi = maks(dari golongan CPNS, bawaan S.1; snapshot pendidikan); tiap jenjang wajib aturan itu yang punya `riwayat_pendidikan` status ≠ 2/10 × 100/n (S.1 juga menerima D.IV) |
| Data Pangkat (`kp`) | `kd_rpangkat_2`, lihat Q-D15 di bawah |
| Data Jabatan (`jabatan`) | ada snapshot `pegawai_mutasi_jabatan` → 100, selain itu 0 |
| Data KGB (`kgb`) | jadwal KGB dari TMT CPNS (snapshot, atau tahun-bulan dari NIP) maju 1/2 tahun sampai hari ini, entri terakhir dibuang; tahun KGB unik (status ≠ 2/10) / jadwal × 100 |
| Data Tanda Jasa (`tanda-jasa`) | masa kerja sejak TMT CPNS ≥ 30: (XXX+XX+X) × 100/3; 20–29: (XX+X) × 50; 10–19: X × 100; < 10: 100 (baris dihitung apa adanya) |

Keputusan dan deviasi:
- **Q-D13 (A):** kartu Pelatihan (`kd_rdiklat`) tidak pernah tampil — legacy mulai dari 100 lalu menambah, jadi selalu ≥ 100.
- **Q-D14 (A):** tanpa batas atas; KGB/Tanda Jasa bisa > 100 → kartu tidak tampil, sama dengan legacy.
- **Q-D15 (A), Data Pangkat:** rentang golongan dari baris Pengangkatan CPNS/PNS terakhir (bawaan III/a tanpa cek CPNS) sampai
  riwayat KP terakhir (`tmtsk` ASC; [I] pemecah seri `id_riwayat_kp` ASC), dikurangi golongan yang dilompati penyesuaian ijazah
  (jenis 5; lompatan per 4 tahun dari riwayat sebelumnya); total = rentang (+1 cek "CPNS <gol>" bila riwayat terakhir bukan
  CPNS). "Terisi" = riwayat KP v2 status ≠ 2/10 ber-`gol_ruang` itu yang punya lampiran `document_attachment` kode KP (11) dengan
  berkas ADA di storage v2 (mekanisme s_stat MAKE-061; legacy memakai konstanta kode dari berkas terlarang). Jalur legacy yang
  tak berujung (`while(key() !== gol)` tidak menemukan golongan) atau berakhir TypeError (`prev()` = false lalu `count(false)`)
  → 0. Pointer array PHP dimodelkan eksplisit (`KelengkapanData::persenPangkat`); saat pengembangan port dibandingkan dengan
  salinan legacy pada 20.000 kasus acak (0 beda).
- **Q-D16 (A):** PPPK (role 7) tanpa kartu; PTT (role 6) tanpa kartu [K].
- **Q-D17 (A):** pengecualian legacy untuk NIP berawalan `123456789` tidak dibawa.
- [I] TMT CPNS dari NIP yang tidak bisa dibaca sebagai tanggal → kartu KGB/Tanda Jasa tidak dihitung (legacy galat fatal).
- [I] "Hari ini" = tanggal bisnis WIB; tanggal ≤ hari ini dianggap sudah lewat (legacy `new DateTime()` berjam).
