# Progres Fase 6 — Modul H: Integrasi & API Eksternal

Acuan: `docs/fase6/PRD_Fase6_Integrasi.md`. Pembagian: `docs/fase6/6_2.md` (Jalur A, Qoder B) dan `docs/fase6/6_1.md`
(Jalur B, Qoder A). ✅ GATE-F6 terbuka 02-11-2026 (UP-1 LOLOS, `docs/up1/Hasil_UP-1.md`).

## Status

| Task | Jalur | Key | Status |
|---|---|---|---|
| H-01 | A + B | DBV-026, DBV-027 | Belum mulai |
| H-02 | B | MAKE-065 | Belum mulai |
| H-03 | B | MAKE-067 | Belum mulai |
| H-04 | B | MAKE-066 | Belum mulai |
| H-05/H-06/H-07 | A | MAKE-059, MAKE-060 | Belum mulai |
| H-08 | A | MAKE-061 | Belum mulai |
| H-09 | A | (pemetaan) | Belum mulai |
| H-10 | B | MAKE-064 | Belum mulai |
| H-11 | A | MAKE-062 | A2a selesai (versi lokal), menunggu review |
| H-12 | A | MAKE-062 | Belum mulai |
| Fondasi | A / B | MAKE-058 / MAKE-063 | Belum mulai |

## Bagian A — API masuk & job terjadwal

(diisi Jalur A per milestone: keputusan, deviasi, catatan)

### A2a — MAKE-062 status TB/CLTN harian (H-11), versi lokal

`php spark layanan:status-harian [--dry-run] [--tanggal=YYYY-MM-DD]` (PRD §7.9, F6-20, CR-098). Aturan murni di
`Libraries/Layanan/StatusHarian/PenentuStatusHarian` (tanggal diinjeksi), lapisan DB `StatusHarianService`, command
`Commands/LayananStatusHarian`. ADR-037; jadwal dan langkah run pertama di `README-deploy.md` bagian 1e.

Keputusan implementasi (reviewer CR 04-11-2026):
- Induk TB dan tiap perpanjangan (`riwayat_tb_perpan`) = segmen terpisah, sama dengan `BebasPresensiTb`. Bentrok memakai
  `tgl_mulai` segmen yang sedang aktif. Celah antara akhir TB dan perpanjangan yang belum mulai: kembali 1 lalu 2 saat
  perpanjangan mulai (INFO `celah_tb`); perpanjangan bersambung tidak berkedip.
- Pegawai `status ≠ 1` dilewati (INFO); `id_jenis_status` selain 1/2/3 tidak disentuh.
- Pelaku audit = `AkunSistemIntegrasi::id()`; belum siap → `EXIT_ERROR` sebelum menulis; dry-run tanpa akun.
- Tanpa `integrasi_log` (bukan integrasi): ringkasan jumlah ke CLI + `log_message('info')`; audit per perubahan di
  `audit_logs` (`jenis_audit` `layanan.status_harian`).
- Transaksi kecil per pegawai (kunci baris, cek belum berubah, update, audit); kegagalan satu pegawai dihitung, kode keluar
  `EXIT_ERROR`. `GET_LOCK` sibuk → `EXIT_ERROR`.
- Dry-run tanggal lampau dihitung terhadap status pegawai SAAT INI (diberi label di keluaran).
- Pegawai berstatus 3 dengan TB aktif tanpa CLTN aktif menjadi 2 (Q6-24 butir "selain itu"); status 2/3 hanya dipulihkan ke 1
  bila ada riwayat TB (untuk 2) atau CLTN status 1 (untuk 3) yang sudah berakhir.

`pengguna:sinkron-nama [--dry-run]` (F6-21 bersyarat; padanan legacy `Cron.php:2818-2877` `sync_nama_pengguna`, reviewer CR 04-11-2026):
`Libraries/Kepegawaian/Pegawai/SinkronNamaPengguna`, `Commands/PenggunaSinkronNama`. `pengguna.name` (kolom ada di v2) disamakan dengan
`StrukturService::namaGelar` untuk pengguna `status = 1`, `user_level = 2` (`Role::PEGAWAI`, padanan `UserLevel 2` legacy), ber-NIP, pegawai
`status = 1`; hanya bila berbeda dan nama tidak kosong; akun sistem dan soft-delete dikecualikan; dipotong 150 karakter (INFO);
transaksi kecil per akun + `audit_logs` (`jenis_audit` `pengguna.sinkron_nama`, pelaku akun sistem); `GET_LOCK`. Jadwal harian 01:00
WIB masih USULAN (PN-5).

## Bagian B — Klien integrasi keluar

(diisi Qoder A per milestone: keputusan, deviasi, catatan)
