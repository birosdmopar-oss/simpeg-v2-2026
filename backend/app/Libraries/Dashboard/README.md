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
  dashboard_satker|dashboard_unit` legacy; cache angka tetap lewat `CacheService`. Keluarga pegawai = kerangka (M2).
- `PengingatBeranda` (4 kartu admin), `DirekturBeranda` (role 1/4), `PrediksiPegawai` + `BawahanPegawai` (beranda pegawai),
  `UlangTahunBeranda` (Q7-45, aturan `function_helper.php:1419-1475`; urutan [I] NIP ASC; Q-D18 titik sambung kosong),
  `ProyeksiTandaJasa` (port murni `get_all_tj` + `next_tj`).
- Konfigurasi: `Config\Dashboard` (`$tampilanKomposisi` Q7-36, `$nipDisembunyikan` Q7-41 hanya `.env` server,
  `$idJabatanDirektur` Q7-38, `$ttlRingkasanDetik`).

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
