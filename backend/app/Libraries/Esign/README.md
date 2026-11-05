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

## `services/s_esign` untuk MuDig (F6-16 / Q6-05 = A, MAKE-065 B4b)

Port `controllers/services/S_esign.php`. Controller `Controllers/Services/SEsignController` (filter `klienLayanan:esign`,
MAKE-058/ADR-036), logika `LayananEsignMudig`, batas unggahan `Config\EsignMudig`. Route = sub-group `s_esign` di
`Config/RoutesServices.php` (berkas bersama Jalur A), responder `ResponsLayanan` mode string. Runbook: `README-deploy.md` §1c.

Amplop [K] `{"status":"1"|"2","msg","data"}` — `status` **string** seperti `S_esign.php` (berbeda dengan `s_stat`/`s_dm` yang
int), HTTP 200; auth gagal 401 dari filter.

| Endpoint | Masukan | Keluaran |
|---|---|---|
| `GET services/s_esign/check_user` | `nik` | `status` "1" bila gateway `1111`; `msg` selalu `null` [K bug legacy: kunci `message` vs `msg`], `data` `[]` |
| `POST services/s_esign/sign_pdf` | wajib [K]: `nik`, `passphrase`, multipart `file` (PDF, ≤ `pdfMaks` 10 MB) dan `imageTTD` (JPEG/PNG, ≤ `gambarMaks` 2 MB); opsional dengan bawaan legacy: `tampilan`, `width`, `height`, `tag_koordinat`, `reason`, `location`; untuk `esign_hist`: `nama_lengkap`, `modul`, `nip`, `id_rw`, `file_pdf_to_sign`, `ip_address`, `user_agent` | sukses: `msg` "Berhasil menandatangani dokumen secara digital.", `data.signed_file` = base64 PDF ber-TTE; gagal: pesan gateway teredaksi; parameter wajib kurang/berkas tidak sah/lewat batas: "Invalid parameter." |

- NIK dikirim pemanggil, jadi gateway dibuat lewat `Services::esignGatewayNikLangsung()` (BSrE tanpa pencarian `pegawai.nik`; mock
  tanpa menulis pengenal ke log). Kontrak `EsignGatewayInterface` tidak berubah.
- `esign_hist` wajib [V2] tanpa menambah parameter wajib (legacy hanya mencatat bila `nama_lengkap`, `modul`, `file_pdf_to_sign`
  dikirim): satu baris per percobaan yang sampai ke gateway (status 1/2, `status_code` = kode gateway). Isian kosong:
  `nama_lengkap` → nama bergelar `pegawai` bila NIP dikenal, selain itu `''`; `modul` → `'MuDig'`; `file_pdf_to_sign` → nama
  berkas unggahan (basename, 256); `nip` tak dikenal → NULL; `id_rw` hanya angka (lainnya NULL); `ip_address`/`user_agent` dari
  POST [K], dipotong panjang kolom.
- Berkas tidak ditulis ke disk aplikasi. Respons `Cache-Control: no-store`. NIK/passphrase tidak masuk respons/log.
