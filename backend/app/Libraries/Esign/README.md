# E-sign BSrE (H-02, MAKE-065)

Adapter BSrE nyata Fase 6 Jalur B (PRD Fase 6 §7.2, F6-17/F6-18; PRD v1.0f CR-097 Q6-18..Q6-23). Konfigurasi, TLS, URL
publik QR, dan pencabutan akses publik: `README-deploy.md` §1c.

- `BsreEsignAdapter` (`EsignGatewayInterface`, kontrak tidak diubah): Basic `username:password`; `checkUser` =
  `GET api/user/status/{nik}` (`1111` → `'200'`); `signPdf` = `POST api/sign/pdf` multipart, `nik`/`passphrase` di query
  ikut legacy. Sukses **hanya** bila badan respons PDF. POST tanpa retry, redirect tidak diikuti, timeout/koneksi →
  `status_code` `timeout`/`koneksi`. NIK, passphrase, dan kredensial tidak pernah masuk log/DB/respons.
  `MockEsignAdapter` untuk dev/test (`integrations.esign.driver = mock`).
- TLS ikut legacy atas keputusan Horii 03-11-2026 (Q6-18): `esign.tlsVerify = false` diizinkan khusus e-sign, dengan
  peringatan di log, `integrasi:status`, dan `integrasi:uji-koneksi esign`. Bawaan repo `true`.

## Surat cuti ber-TTE (F6-18, `Libraries/Layanan/Cuti/*`)

- `EsignCutiService` (`POST cuti/{id}/esign`): dua langkah (K1) — persetujuan disimpan dulu, lalu penanda tangan pemilik
  tugas mengetik passphrase sendiri; gagal → persetujuan tetap, boleh diulang. Batas 5 percobaan gagal / 15 menit per
  penanda tangan per cuti (K2, `esign_hist` status 2 → 429). Passphrase hanya untuk Setujui/Tangguhkan (K3a). Sertifikat
  dicek dulu (`GET cuti/{id}/esign/status`, cache 5 menit; gateway mati tidak dihitung K2). `KunciSuratCuti` = `GET_LOCK`
  per cuti (409).
- `QrTtd` (K3b): `imageTTD` = QR (logo tengah) berisi URL PDF ber-TTE publik `api/v1/publik/surat-cuti/{token}/pdf`.
- `TokenSuratCutiService` (DBV-030, `surat_cuti_token`: hash + salinan terenkripsi, tanpa NIP): token acak 64 heks, satu
  per cuti, dibuang saat cuti dihapus (Q2), tidak dirotasi sesudah TTE (Q3); `php spark surat-cuti:token` untuk
  nonaktif/aktif (tercatat `audit_logs`).
- `VerifikasiSuratCutiService` + `SuratCutiPublikController` (K3c): halaman publik `/verify/surat-cuti/{token}` (data
  minimal, tanpa NIP/unduh) dan PDF publik; throttle, 404 seragam, `no-store` + `noindex`. Dasar URL =
  `layanan.urlPublikSurat` (https wajib di production).

B4b (`services/s_esign` untuk MuDig) menunggu fondasi Jalur A (MAKE-058).
