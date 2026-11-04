# Modul H — Integrasi & API Eksternal (Fase 6)

Service/business logic modul ini (`App\Libraries\Integrasi\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

Jalur B (integrasi **keluar**, `docs/fase6/6_1.md`) memakai fondasi di folder ini; modul per layanan punya README sendiri:

| Layanan | Task | Key | README | Runbook |
|---|---|---|---|---|
| BKN e-Kinerja | H-10 | MAKE-064 | `Libraries/Bkn/README.md` | `README-deploy.md` §1e |
| SIASN BKN | H-03 | MAKE-067 | `Libraries/Siasn/README.md` | `README-deploy.md` §1e |
| E-sign BSrE | H-02 | MAKE-065 | `Libraries/Esign/README.md` | `README-deploy.md` §1c |
| Push FCM HTTP v1 | H-04 | MAKE-066 | `Libraries/Push/README.md` | `README-deploy.md` §1b, §1d |

Jalur A (API masuk SIMAPI/`services/*`, job terjadwal H-11/H-12) didokumentasikan Jalur A.

## Fondasi klien keluar (MAKE-063, PRD Fase 6 §7.0.2)

- `Http\KlienHttp` + `Http\Permintaan`/`Respons`/`CurlTransport` (`FakeTransport` untuk test): satu-satunya jalan panggilan
  HTTP keluar. Retry **hanya GET** (galat transport, 429, 5xx; backoff eksponensial + jitter); POST/PUT/DELETE tidak pernah
  di-retry (BP-3). Redirect tidak diikuti. Panggilan tidak boleh di dalam transaksi DB bisnis (BP-4).
- `Http\Redaktor`: sensor kunci/header sensitif, token Bearer/Basic, nilai literal `Permintaan::$rahasia` (NIK, passphrase,
  NIP, id PNS — juga bentuk ter-encode), dan angka 18 digit. Parameter rahasia diberi `#[\SensitiveParameter]` agar tidak
  tercetak di backtrace.
- `Log\IntegrasiLog` (`IntegrasiLogInterface::catat(CatatanIntegrasi)`, kontrak dikunci CR-091): satu baris `integrasi_log`
  per percobaan + satu ringkasan per proses. URL, query, header, dan body tidak dicatat; `created_at` UTC. Gagal menulis log
  tidak menggagalkan operasi (BP-7).
- `KonfigurasiIntegrasi::pastikan()`: validasi kunci `.env` per pemakaian (AC-K.4); pesan hanya menyebut nama kunci.
- `AkunSistemIntegrasi` (DBV-027 §4, Z-1 opsi c): akun `sistem.integrasi` untuk pelaku `updated_by`; dibuat command, bukan
  seed; `id()` dipanggil di awal setiap job penulis (akun belum siap → gagal eksplisit).

## Command

| Command | Isi |
|---|---|
| `integrasi:siapkan-akun-sistem` | Buat akun sistem integrasi (idempoten; akun menyimpang ditolak, tidak diperbaiki). Sesudah impor `pengguna`, sebelum cron integrasi aktif. |
| `integrasi:status [--jam=24]` | Agregat sukses/gagal per layanan dari `integrasi_log` + peringatan periode Tukin tanpa SKP periodik (F6-15) + status TLS BSrE. Exit 1 bila ada kegagalan/peringatan (pemantauan cron). |
| `integrasi:bersihkan-log [--hari=180] [--dry-run]` | Hapus `integrasi_log` lebih tua dari retensi (`integrations.logRetensiHari`). Mingguan. |
| `integrasi:uji-koneksi <bkn\|siasn\|esign\|fcm>` | Uji koneksi NYATA, manual (bukan bagian `check.sh`). `fcm` = token OAuth baru + `messages:send` `validate_only` ke topik uji, tanpa kirim ke perangkat (CR-101). |

Opsi CLI diurai `Libraries\Cli\OpsiCli` (`--k=v` dan `--k v`; opsi salah → exit 7). Koneksi DB command berjalan lama dibuka
lewat `Libraries\Cli\KoneksiCli`.

## Aturan bersama Jalur B

- Tanpa panggilan jaringan nyata di test; fixture sintetis (NIP/NIK/nama fiktif).
- Kredensial dan alamat hanya di `.env` server; `backend/.env.example` berisi kunci kosong.
- Command penulis memakai `GET_LOCK` per jenis (run kedua → exit 1 tanpa panggilan API), transaksi pola proyek, dan
  ringkasan `integrasi_log` berisi jumlah saja (tanpa NIP/nama/token).
