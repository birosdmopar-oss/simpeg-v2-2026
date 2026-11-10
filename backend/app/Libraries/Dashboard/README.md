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
- Konfigurasi: `Config\Dashboard` (`$tampilanKomposisi` Q7-36, `$nipDisembunyikan` Q7-41 hanya `.env` server,
  `$idJabatanDirektur` Q7-38, `$ttlRingkasanDetik`).
