# BKN — token & e-Kinerja (H-10, MAKE-063/MAKE-064)

Klien BKN Fase 6 Jalur B. Bentuk autentikasi ikut legacy (diekstrak tanpa nilai oleh reviewer, CR-092/Q6-17); alamat dan
kredensial hanya di `.env` server (`integrations.bkn.*`, daftar di `backend/.env.example`). Runbook: `README-deploy.md` §1e.

## `TokenBkn` (MAKE-063)

- Token API gateway: POST form, Basic `clientId:clientSecret`, `grant_type=client_credentials`.
- Token SSO SIASN: POST form, Basic kredensial gateway, `grant_type=password` + `client_id`/`username`/`password`
  (+ `totp` bila dikonfigurasi).
- Token e-Kinerja: `ekinerjaToken` statis dari `.env` bila terisi (legacy memakai token literal di kode — v2 hanya dari
  konfigurasi), selain itu SSO e-Kinerja (`ekinerjaApiUser`/`ekinerjaApiPass` + `ekinerjaSso*`).
- Cache sampai `expires_in − 60` detik; respons 401 → token dibuang, permintaan diulang **sekali**
  (`kirimSiasn`/`kirimEkinerja`). Header SIASN: `Authorization: Bearer <gateway>` + `Auth: bearer <SSO>` + `accept`.
- `tlsVerify` wajib `true` dan `timeout` > 0 (divalidasi saat dibuat). Galat token → `GalatTokenBkn` (pesan tanpa nilai).

## e-Kinerja (`Ekinerja/*`, MAKE-064, PRD §7.8, F6-15)

- `EkinerjaKlien` → `PemetaEkinerja` (validasi & pemetaan per baris, tanggal dua format `ParserTanggalBkn`) →
  `SinkronEkinerja` (satu transaksi pola proyek; galat DB → batal semua + `DatabaseException`).
- Periode `bkn_periode_ekinper`: hanya INSERT id baru [K `EB:9`]. SKP periodik `riwayat_skp_periodik`: kunci
  `id_pns + periode_id + skp_id` (UNIQUE DBV-027, Q6-10); baru → INSERT, `hasil_akhir` berubah → UPDATE [K `EB:205`];
  idempoten. NIP tanpa pasangan `id_pns_siasn` / tidak dikenal dihitung saja, tanpa NIP di log (AC-10.2).
- `PemilihPeriode`: tanggal WIB > 19 → periode bulan berikutnya [K `EB:209-234`]; id berawalan `SKPManual` dilewati.
- `TarikEkinerja`: orkestrasi untuk command dan job; status `ok` / `gagal` / `periode_tidak_ada` / `dilewati_manual`;
  satu ringkasan `integrasi_log` (jumlah saja).
- `AntreTarikEkinerja` + job `bkn-tarik` (antrean `integrasi`): tombol admin "tarik ulang"
  (`POST api/v1/integrasi/bkn/tarik`, super admin) mengantre job, tidak memanggil BKN di request.

| Command | Isi |
|---|---|
| `bkn:tarik-periode [--dry-run]` | Tarik periode e-Kinerja baru. |
| `bkn:tarik-skp-periodik [--tahun=YYYY --bulan=M] [--sebelumnya] [--dry-run]` | Tarik SKP periodik periode terpilih (bawaan aturan tanggal 19) atau periode tertentu (`--tahun` + `--bulan` berpasangan); `--sebelumnya` = tarik juga satu periode sebelumnya (dipakai jadwal harian, PRD §6.4). Periode belum ada → jalankan `bkn:tarik-periode` dulu. |

`integrasi:status` memperingatkan bila periode Tukin berjalan belum punya SKP periodik (Tukin bergantung pada data ini:
tanpa baris → potongan bawaan 32% [K]).
