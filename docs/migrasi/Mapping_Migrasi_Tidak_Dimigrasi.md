# Mapping Migrasi — tabel legacy yang tidak dimigrasi

**Status:** daftar kerja repo, 20-10-2026. Mapping Migrasi resmi ada di dokumen Bahan Baku
(`Mapping_Migrasi_Data_SIMPEG_v2.docx`, di luar repo). Tabel di bawah dipakai untuk menyelaraskan docx itu: setiap baris
ditandai **"tidak dimigrasi"** di docx dengan dasar keputusan yang sama. Kalau ada keputusan baru, perbarui berkas ini
dulu, lalu docx.

Arti istilah:
- **Tidak dimigrasi**: tabel tidak dibuat di skema v2 dan isinya tidak diimpor.
- **Dibuat kosong**: tabel ada di v2, tetapi data legacy tidak diimpor (v2 mulai dari nol).

Rujukan baris D1 = dump struktur produksi `simpeg01_struktur_lengkap_20261001.sql` (sumber DDL legacy lokal).

## 1. Auth & akun (A-01)

| Tabel legacy | Perlakuan | Dasar |
|---|---|---|
| `token` | Dibuat kosong (nama sama, isi beda; dikosongkan migration A-01) | `backend/docs/db-review/A-01-auth-schema.md` Bagian 8 #11 |
| `login_attempts` (log percobaan login) | Dibuat kosong (log, mulai fresh) | A-01 |
| `pengguna_2021` … `pengguna_2024` (cadangan, kemungkinan berisi plaintext) | Tidak dimigrasi | A-01 #11 |
| `password_resets`, `oauth_*`, `ci_sessions`, `user_log`, `d_user*`, `sal_user`, `login_mysapk` | Tidak dimigrasi | A-01 #11 |
| kolom `pengguna.password_decode` (plaintext) | Kolom dibuang, tidak dimigrasi | A-01 (tabel kolom `pengguna`) |

## 2. Kepegawaian (B-01/B-02, DBV-020)

| Tabel legacy | Perlakuan | Dasar |
|---|---|---|
| Arsip trigger `d_*`, `da_deleted` | Tidak dimigrasi (jejak hapus diganti `audit_logs`) | `B-01-B-02-pegawai-riwayat-schema.md` butir 8 |
| Tabel cadangan bertanggal `*_YYYYMMDD`, `riwayat_lckh_2019` … `riwayat_lckh_2022` | Tidak dimigrasi | B-01/B-02 |
| Antrean `simapi_*` | Tidak dimigrasi | B-01/B-02 butir 8 |
| `pegawai_hist` yang tidak berstatus pending | Tidak dimigrasi (hanya baris pending yang diimpor) | B-01/B-02 butir 8 |
| `user_lokasi_presensi` (D1:7595-7605) | Tidak dimigrasi; dibangun ulang dari aturan `dm_user_lokasi_presensi` (G-03) | `DBV-020-skema-tambahan-fase3.md` butir 6 (keputusan #8) |

## 3. Master Data (G-09)

| Data legacy | Perlakuan | Dasar |
|---|---|---|
| Nilai `web_config` path logo (`logo_kementerian_pdf`, `logo_wonderful_pdf`) | Nilai tidak diimpor (path absolut server lama) | `G-09-web-config-schema.md` aturan impor #4 |
| Nilai `web_config` kosong | Tidak diimpor (v2 memakai bawaan) | G-09 aturan impor #7 |

## 4. Layanan & notifikasi (Fase 4, PRD_Fase4 C-01 / DBV-022)

| Tabel legacy | Perlakuan | Dasar |
|---|---|---|
| `d_riwayat_cuti` (arsip trigger) | Tidak dimigrasi | `docs/fase4/PRD_Fase4.md` §6 C-01 "Tabel yang TIDAK dibuat" |
| Cadangan bertanggal: `riwayat_cuti_2020…`, `riwayat_tb_20201204`, `riwayat_ib_20201213`, `pa_layanan_20211122`, `petugas_approval_20211122`, `inbox_msg_20251027`, `pegawai_sisa_cuti_20xx` / `_2024…2026` | Tidak dimigrasi | PRD_Fase4 C-01 |
| `layanan`, `layanan_pegawai`, `riwayat_layanan` | Belum diputuskan / tidak diimpor (registry) | PRD_Fase4 C-01 |
| Antrean push: `fb_pn_queue`, `andr_notif_queue`, `andr_topic_queue`, `firebase_message_logs` | Tidak dimigrasi | PRD_Fase4 C-01 |
| `fb_token` (token desktop per `id_user`) | Tidak dimigrasi (kecuali DBV memutuskan lain) | PRD_Fase4 C-01 #20 |
| `firebase_token` | Dibuat kosong (Tier 6, tidak dimigrasi) | PRD_Fase4 C-01 #20 (D18) |
| `email_notification` | Tidak dimigrasi | PRD_Fase4 C-01 |
| `support_officer`, `officer_category`, `help_desk_contact` | Bukan Fase 4 (diputuskan di Fase 8) | PRD_Fase4 C-01 |

## 5. Presensi & Slip Gaji (Fase 5A, DBV-023)

| Tabel legacy | Perlakuan | Dasar |
|---|---|---|
| `user_geo` (D1:7547-7560) | Tidak dimigrasi (bukti koordinat kini kolom `dh_online`) | PRD 5A F5-12; DBV-023 DBV-Q7 |
| `dh_online_coordinate` (D1:406-416) | Tidak dimigrasi | F5-12; DBV-Q7 |
| `d_dh_online` (arsip trigger `dh_online_beDel`) | Tidak dimigrasi; trigger tidak dibawa, diganti `audit_logs` | F5-14, Q5A-15; DBV-Q4/Q7 |
| `absen_real`, `absen_kategori` | Tidak dimigrasi | F5-12; DBV-Q7 |
| `user_lokasi_presensi` | Lihat Bagian 2 | DBV-020 butir 6 |

Catatan 5A: `dh_online` dan `gaji_pegawai` **dimigrasi** (DBV-023 Bagian 7). Volume dan isi `gaji_pegawai` produksi masih
menunggu kartu DBA ISSUE-049 (Q5A-02). Runbook impor yang memuat daftar ini:
`backend/docs/db-review/DBV-011-runbook-impor-master-zona-waktu.md` bagian 2.11 (di branch DBV-023 sampai M2).

## 6. Yang perlu diselaraskan di docx Mapping Migrasi

1. Tandai setiap tabel di Bagian 1–5 sebagai "tidak dimigrasi" atau "dibuat kosong", dengan kolom dasar seperti di atas.
2. Ubah entri `user_lokasi_presensi` menjadi "tidak dimigrasi": rencana awal G-03 Bagian 6.2 #6 (diimpor bersama
   `pegawai`) sudah gugur oleh DBV-020.
3. Untuk tabel cadangan bertanggal dan arsip `d_*`, cukup satu aturan pola, tidak perlu satu baris per tabel.
