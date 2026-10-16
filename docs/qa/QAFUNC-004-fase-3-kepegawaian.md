# QAFUNC-004 (Fase 3): QA Fungsional Kepegawaian Core — QASMTASK-043..063

QA fungsional Fase 3 (B-01..B-21) atas izin user (reviewer CR), 15-10-2026, commit `main` = `576ee8f`. Dijalankan dalam tiga bagian paralel: **A** (B-01..B-06, B-18, B-20 API), **B** (B-07..B-17, Konket, LKH, usulan), **C** (B-19, B-21 + regresi Fase 1–2). Bagian UI (QA Lapis 1) ada di `QAUI-003-fase-3-lapis1-kepegawaian.md`. Temuan diperbaiki CR-049/CR-050 (ISSUE-033..039) lalu diuji ulang di `QAFUNC-004-R1-fase-3-kepegawaian.md` dan `QAFUNC-004-QAUI-003-R2-fase-3-kepegawaian.md`.

> Catatan: rujukan `scratchpad/…` di laporan ini menunjuk berkas kerja QA lokal (harness, log mentah, tangkapan layar) yang **tidak dikomit**. Seluruh data uji sintetis (NIP & nama fiktif); kredensial ditulis "password seed lokal".

## QAFUNC Fase 3 bagian A: Data Pegawai (B-01..B-06, B-18, B-20 API) — QASMTASK-043..048, 060, 062

| | |
|---|---|
| **Jenis** | QA Functional black-box API + cek DB (tanpa UI; UI/QA Lapis 1 = bagian D), atas izin reviewer CR |
| **Tanggal eksekusi** | 15 Oktober 2026 |
| **Commit yang diuji** | `main` = `576ee8f` (MAKE-001..019, CR-045..048, DBV-019), worktree detached `wt/qa3a` |
| **Lingkungan** | PHP 8.4.2, MySQL 8.0.30 instance test port 3307, DB scratch `simpeg_v2_qa3a` (+ `simpeg_v2_qa3a_t` untuk PHPUnit), `utf8mb4_unicode_ci`; `php spark migrate --all` + `MasterDataSeeder` (memanggil `AuthSeeder`); `.env` dari `.env.example` (development, captcha `mock`, JWT/encryption key acak lokal); `php spark serve --port 8111`. Akun seed (password seed lokal) + 3 akun admin role 3 tanpa satker (unit 20 / 21 / 30) + data pegawai sintetis (NIP sintetis, nama "… Fiktif") |
| **Data lingkup** | Unit 6 (UPT), 7 (Unit Lain), 20 (Kelembagaan), 21 (Destinasi), 30; satker BPO 37/38/41 (unit 7), 42 (unit 21), 43 (unit 20), 44 (unit 6), 45 (unit 30). Pegawai target: unit20/s43, UPT6/s44, unit21/s42, unit7/s37, unit7/s41, **unit30/s38 (BPO bukan unit 7)**, unit30/s45, tanpa pmj. Admin role 3: satker 43, tanpa satker unit 20, unit 21, unit 30 |
| **Alat** | Skrip Python `requests` + klien `mysql` (skrip & log di `scratchpad/qa3a/`: `t_scope`, `t_bio`/`t_bio2`, `t_appr`, `t_siklus`, `t_lamp`/`t_lamp2`/`t_dik`, `t_arsip`, `t_nip`, `t_b20`, `isi_anak.py`) + PHPUnit terarah |
| **Rujukan** | `scratchpad/qa3-dod.md` (B-01..B-21), `scratchpad/qa3-dod-trello.md` (checklist resmi), README `Controllers/Api/Kepegawaian` & `Libraries/Kepegawaian`, `backend/docs/progress/03-Kepegawaian.md`, `docs/fase3/MATRIKS_ROLE_MODUL_B.md`, dokumen `backend/docs/db-review/`, komentar DB Validator PR #20 dan PR #24 |
| **Hasil** | **Semua butir DoD fungsional bagian A lolos** (±460 cek API/DB PASS + PHPUnit 125 + 44 + 22 test OK). Tidak ada temuan Blocker/Major/Medium. Temuan: 1 Minor (A-F1 ekspor CSV), 2 Trivial (A-F2 status dokumen DBV basi, A-F3 seed `jenis_pegawai`), 7 Info. Butir UI (profile card, badge, modal, uploader) dan "Lolos MTC" di luar cakupan bagian A |

FAIL di log skrip (`t_scope` 6, `t_bio` 3, `t_appr` 2, `t_siklus` 1, `t_lamp` 4, `t_b20` 4) adalah harapan uji QA yang salah atau data uji yang belum lengkap (mis. foto wajib saat biodata pertama, kolom `pegawai_kp.tgl_sk` wajib, harapan 422 untuk parameter daftar yang memang longgar), **bukan** cacat aplikasi; setiap butir diuji ulang dengan data/harapan yang benar (`t_bio2`, `t_lamp2`, `t_dik`, cek manual) dan semuanya PASS. Rinciannya di bagian "Catatan skrip".

## Ringkasan per QASMTASK

| QASMTASK | Task | Hasil (bagian A) | Temuan |
|---|---|---|---|
| 043 | B-01 skema pegawai & snapshot | **PASS dengan catatan** | A-F2 (Trivial), A-F9 (Info) |
| 044 | B-02 skema riwayat & lampiran | **PASS dengan catatan** | A-F2, A-F9 |
| 045 | B-03 biodata dua jalur | **PASS** (butir UI → bagian D, MTC → N/A) | — |
| 046 | B-04 approval biodata | **PASS dengan catatan** (modal → bagian D, MTC → N/A) | — |
| 047 | B-05 tambah & hapus pegawai | **PASS sesuai keputusan user 13-10-2026** (hapus FISIK) (modal → bagian D) | A-F3 (Trivial), A-F7 (Info) |
| 048 | B-06 koreksi NIP | **PASS dengan catatan** (dialog → bagian D, MTC → N/A) | A-F4, A-F5 (Info) |
| 060 | B-18 lampiran + Arsip Kepegawaian 37/38 | **PASS** (uploader drag-and-drop → bagian D, MTC → N/A) | A-F8 (Info) |
| 062 | B-20 bagian API (daftar, detail, pencarian, ekspor, DRH) | **PASS dengan catatan** (Lapis 1 → bagian D) | A-F1 (Minor), A-F6, A-F10 (Info) |

## Rincian per DoD

### QASMTASK-043 — B-01 Migration Tabel Pegawai & Snapshot

| DoD (dokumen + checklist Trello) | Hasil | Bukti |
|---|---|---|
| Keputusan PK (`id_pegawai` vs `nip`) FINAL & tercatat | PASS dengan catatan | K1 di `B-01-B-02-pegawai-riwayat-schema.md:41` (PK `pegawai.nip` VARCHAR(30)); DB Validator PR #20 (06-10): "sejak PR ini masuk, keputusan tersebut sebaiknya diperlakukan final". Tidak ada berkas ADR di repo (ADR-001..030 di luar repo, tidak bisa diverifikasi dari sini) |
| Tabel `pegawai`, `pegawai_hist`, seluruh `pegawai_*` snapshot dibuat | PASS | `information_schema`: `pegawai`, `pegawai_hist`, `pegawai_foto` + 19 snapshot (`pegawai_ak`, `_ak_siasn`, `_alamat`, `_alamat_kantor`, `_cpns`, `_diklat`, `_hukdis`, `_keluarga`, `_kgb`, `_kp`, `_mutasi_jabatan`, `_pendidikan`, `_plh`, `_plt`, `_pns`, `_tanda_jasa`), semua InnoDB `utf8mb4_unicode_ci`; collation kolom tunggal `utf8mb4_unicode_ci`, default schema sama |
| FK aktif ke seluruh master Tier 1 | PASS | `pegawai` → agama/jenis_pegawai/jenis_status/provinsi/kabupaten_kota; snapshot → master (pangkat, jenis_kp, jenjang/bidang/jurusan pendidikan, diklat, hukdis, tanda_jasa, gol_pppk, wilayah) + riwayat sumber; DBV-019: semua 53 nama FK di `2026-10-07-100000/100100` ada di DB (0 hilang); semua FK RESTRICT/RESTRICT. Uji penegakan: `INSERT riwayat_kgb nip='000…'` → `1452 fk_nip_rwykgb_to_pegawai`. Catatan A-F9 |
| DB Validator cross-check kolom vs Legacy Audit | PASS | Dokumen `B-01-B-02-…md` (perbandingan per tabel vs D1); komentar DB Validator PR #20: "Seluruh angka yang diklaim developer cocok dengan hasil ukur saya" |
| Skema direview & di-approve DB Validator | PASS dengan catatan (A-F2) | PR #20 (DBV-012/013): "✅ Skema layak disetujui … DBV-012 dan DBV-013 disetujui dari sisi skema" (jjoseph48, 06-10), merged 06-10. PR #24 (DBV-019): "✅ Disetujui … boleh dilanjutkan ke merge" (07-10), merged 13-10. Dokumen `db-review` sendiri masih berstatus ⏳ MENUNGGU (A-F2) |
| Regresi skema | PASS | PHPUnit `tests/database/Kepegawaian` (FkG02, NipReferenceRegistry, PegawaiSnapshotSchema, RiwayatSchema, SnapshotRiwayatFk): OK 22 test / 3965 assertion |

### QASMTASK-044 — B-02 Migration Tabel Riwayat

| DoD | Hasil | Bukti |
|---|---|---|
| Seluruh `riwayat_*` + `document_attachment` dibuat | PASS | 18 `riwayat_*` (ak, ak_siasn, alamat, diklat, hukdis, kariskarsu, karpeg, keluarga, kgb, kp, lckh, mutasi_jabatan, organisasi, pendidikan, seminar, skp, skp_periodik, tanda_jasa) + `detail_anak`, `document_attachment`, `jenis_rwy`, `absen_ijin`; 26 CHECK constraint (status per tabel) |
| FK aktif `riwayat_*.nip` → `pegawai.nip` | PASS dengan catatan (A-F9) | Semua `riwayat_*.nip` ber-FK RESTRICT kecuali `riwayat_skp_periodik.nip` (tanpa FK — keputusan DBV-013, data tarik e-Kinerja); `riwayat_lckh.nip_atasan` juga ber-FK; total 40 FK masuk `pegawai(nip)` |
| Mapping `jenis_rwy` = lookup/enum eksplisit, bukan hardcode | PASS | Tabel `jenis_rwy` (22 kode: 1 alamat … 38 arsip_du, 39–42) + FK `document_attachment.id_riwayat` → `jenis_rwy`; Definisi memakai konstanta bernama (`Organisasi::LAMPIRAN_ORGANISASI = 13`, `ArsipDataUmum::KODE_ARSIP = 38`), kode di DB terverifikasi (`jenis_rwy 13 = organisasi / riwayat_organisasi`) |
| Skema direview & di-approve DB Validator | PASS dengan catatan (A-F2) | Sama dengan B-01 (PR #20 keputusan #11 "Setuju lookup jenis_rwy + FK") |

### QASMTASK-045 — B-03 CRUD Biodata (Dua Jalur)

| DoD | Hasil | Bukti |
|---|---|---|
| Role 1 edit → langsung update `pegawai` | PASS | `PUT pegawai/{nip}/biodata` role 1 → 200 `jalur: langsung`; `pegawai` berubah (nama, npwp, nama master turunan `DKI Jakarta`/`Jakarta Pusat`/`Islam`), `flag_update` 0, tidak ada `pegawai_hist`, `audit_logs` pegawai +1; email akun pegawai berakun disinkron (`pengguna.email = p9@…`) |
| Role 2/3/6/7 edit → `pegawai_hist` pending, `pegawai` belum berubah | PASS (sesuai legacy: pending = `flag_update` 1) | Role 2: 200 `jalur: pengajuan`, `pegawai` (nama/npwp/no_hp) tetap, `pegawai.flag_update` 0→1, 1 baris `pegawai_hist` `flag_update` 1, email akun belum berubah; pengajuan ulang memperbarui baris yang sama. Role 6, role 7, akun role 7 lain, role 3 (satker 43 → pegawai unit 20) dan admin unit 21 (→ unit 7/s37): semua `jalur: pengajuan`. Kolom `status` pegawai_hist = status pegawai; penanda pending = `flag_update` [K] |
| Isian per role | PASS | Role 2/6/7 ubah `nama`, `status`, `id_jenis_pegawai` → 422 `errors.<kolom>` "Anda tidak berhak mengubah isian ini."; nilai sama (nama sekarang) diterima 200; role 3 boleh `id_jenis_pegawai` (pengajuan memuat 2, `pegawai` tetap 1 sampai disetujui), `nama` → 422; role 1 `nip` berbeda → 422; validasi: email format, npwp wajib, kabupaten bukan milik provinsi, jenis status tidak cocok status → 422 per kolom |
| Role 3 dibatasi satker/unit sendiri → 403 | PASS | Satker 43 → unit 21 / tanpa pmj: 403; admin unit 20 → UPT: 403; admin unit 21 → unit 30/s38 (BPO bukan unit 7): 403; admin unit 30 → unit 21: 403; role 2 → NIP lain 403, NIP tidak ada 403 (anti-oracle); role 4/5/8 → 403; tanpa token 401; role 1 NIP tidak ada 404 |
| Foto | PASS | Pengajuan + foto → `pegawai_hist.foto` terisi, `pegawai.foto` tetap NULL; role 1 + foto → `pegawai.foto` + baris `pegawai_foto`; teks/PDF bernama `.png` → 422 "Jenis berkas tidak diizinkan (hanya jpg, jpeg, png)."; > 5 MB → 422 "Ukuran berkas melebihi 5 MB."; foto wajib bila pegawai belum punya foto → 422 `foto` [K]; `GET pegawai/{nip}/foto` role 4 → 200 `image/png`, `nosniff`, `no-store`; role 2 atas NIP lain 403; tanpa foto 404 |
| Email unik / skip | PASS | Pengajuan dengan email akun lain → 422 `email` "Email sudah dipakai akun lain…"; jalur langsung role 1 → 200, `email_skipped: true` (biodata tersimpan, email akun tidak ditimpa) |
| Role 1 menimpa pengajuan aktif | PASS | Pegawai UPT dengan pengajuan aktif, role 1 edit → `pegawai_hist.flag_update` 2 tanpa alasan, `pegawai.flag_update` 0 (keputusan user 13-10-2026) |
| Matriks `hr/employee/edit` = 1, 2, 3, 6, 7 | PASS | Lihat baris di atas |
| Profile card & badge 'Menunggu Persetujuan' (UI) | — | Bagian D (QAUI). API mengirim `pengajuan.flag_update` 1 di `GET pegawai/{nip}` pemilik |
| Lolos MTC-015/016 | N/A (fase MT) | — |
| Regresi PHPUnit | PASS | `BiodataTest`, `PegawaiEndpointTest` bagian dari 125 test OK |

### QASMTASK-046 — B-04 Approval Draft Biodata

| DoD | Hasil | Bukti |
|---|---|---|
| Approve → data `pegawai_hist` masuk `pegawai` | PASS (penanda [K] `flag_update` 3) | Role 1 setujui pengajuan role 2 → 200; `pegawai.npwp` = nilai pengajuan `2224`, `pegawai.foto` terisi, foto masuk galeri `pegawai_foto` (+1), email akun disinkron `p2@…`, `pegawai_hist.flag_update` 3, `approved_by` terisi, `show_ua_biro` 1; role 3 bersatker setujui → `id_jenis_pegawai` 1→2, `show_ua_upt` 1; admin unit 21 tanpa satker → `show_ua_deputi` 1. DoD "status jadi 1" dibaca sebagai penanda disetujui: v2 ikut legacy (`flag_update` 3, README `Libraries/Kepegawaian` §B-04) |
| Reject → status ditolak, `pegawai` tidak berubah | PASS | Admin unit 21 tolak pengajuan pegawai unit 30/s38 → `pegawai_hist.flag_update` 2 + `reason_note`; `pegawai` (npwp, email akun) tidak berubah kecuali `pegawai.flag_update` 2 |
| Alasan wajib saat tolak | PASS | Tanpa `reason_note` → 422 "Alasan penolakan wajib diisi."; spasi saja → 422; `aksi` lain → 422 `aksi`; proses ulang pengajuan yang sudah diproses → 422 "Pengajuan ini sudah diproses atau tidak lagi berlaku." |
| Antrean jalur proses (CR-045) | PASS | `GET biodata/pengajuan`: role 1 = semua pengajuan menunggu; satker 43 = hanya unit 20/s43; **admin unit 20 = hanya unit 20 (pengajuan UPT unit 6 tidak tampil)**; admin unit 21 = unit 21 + satker 37/38/41 (termasuk unit 30/s38 yang detailnya 403); admin unit 30 = unit 30; role 2/4/5/6/7/8 → 403; tanpa token 401; `search` jalan |
| Lingkup proses | PASS | Proses oleh role 2/4 → 403; role 3 satker lain → 403; admin unit 20 atas pengajuan UPT → 403; admin unit 21 atas BPO bukan unit 7 → 200 (jalur proses, keputusan CR-045) |
| Pemilik melihat diff | PASS | `GET biodata/pengajuan/{id}` oleh pemilik role 2 → 200 `perubahan` [{kolom, lama, baru}] (16 kolom termasuk `foto: false→true`, `email`), `can_process: false`, path foto tidak dikirim; role 7 lain → 403/404; role 3 di luar lingkup → 403/404; role 1 `can_process: true`; foto pengajuan pemilik 200, role 4 403/404; id tidak ada 404; pemilik melihat pengajuan ditolak + `reason_note` |
| `GET pegawai/{nip}` | PASS | Pemilik: `pengajuan` (flag 1); role 1: `aksi.proses_biodata: true` |
| Matriks `hr/employee/process` = 1, 3 | PASS | Di atas |
| Modal reject kolom alasan (UI) | — | Bagian D |
| Lolos MTC-015 | N/A (fase MT) | — |
| Regresi PHPUnit | PASS | `ApprovalBiodataTest` OK |

### QASMTASK-047 — B-05 Tambah & Hapus Pegawai

| DoD | Hasil | Bukti |
|---|---|---|
| Tambah → akun `pengguna` otomatis (A-09) | PASS | `POST pegawai` multipart role 1 → 201 `{pegawai, akun}`; `akun.username` = NIP, `created: true`, `initial_password` = NIP, `user_level` 2; DB: `pengguna.nip` = NIP, status 1, hash `$argon2id`; login username NIP + password NIP → 200; `Cache-Control: no-store`; status awal 1 walau dikirim `status=2`; foto ke `pegawai.foto` + galeri; audit `pegawai create`, `pengguna create`. Jenis pegawai 2 → akun role 6 [K] (`L_employee.php:158`) |
| Validasi tambah | PASS | NIP sudah pegawai → 422 `nip`; NIP = username akun lain → 422 "NIP ini sudah dipakai sebagai username akun lain…"; NIP tertaut akun soft-deleted → 422 "…sudah tertaut ke akun lain…"; `12AB` / 19 digit → 422; tanpa foto → 422 `foto`; email dipakai akun lain → 201, email akun NULL (dilewati) |
| Role 1 only | PASS | Tambah role 2–8 → 403 (tidak ada baris tercipta); tanpa token 401; hapus role 2–8 → 403, tanpa token 401 |
| Hapus → soft delete pegawai | **PASS sesuai keputusan user 13-10-2026** (hapus FISIK ikut legacy, README Controllers B-05) | `DELETE pegawai/{nip}` → 200 `{deleted: true, soft_delete: false, item}`; baris `pegawai` hilang; galeri `pegawai_foto` ikut terhapus + berkas foto dibuang dari disk; audit `pegawai delete`, `pegawai_foto delete`, `pengguna update`. NIP tidak ada → 404 |
| Hapus → akun terkait dinonaktifkan | PASS (akun dilepas) | Akun: `nip` NULL + `status` 0; semua refresh token dicabut (0 aktif); login ditolak |
| Data turunan → 422 | PASS | Hapus pegawai ber-pmj (unit 30) dan pegawai ber-riwayat/pengajuan → 422 `errors.nip` "Pegawai tidak dapat dihapus karena masih memiliki data terkait…", data & akun utuh |
| Status 1→2 menonaktifkan akun PNS saja | PASS | Role 1 ubah status 1→2 (+ jenis status 3) pegawai akun `user_level` 2 → akun status 0, refresh token dicabut; akun `user_level` 7 → tetap aktif; 2→1 tidak mengaktifkan ulang; daftar `status=2` memuatnya. Access token lama tetap berlaku sampai `exp` (A-F7, ADR-004) |
| Regression test A-09 dijalankan ulang | PASS | PHPUnit `AccountProvisionerTest`, `EmailUnikTest`, `ValidasiNipPegawaiTest` + `SiklusPegawaiTest` (10 test, termasuk "Tambah pegawai foto dan akun A-09") OK |
| Modal konfirmasi hapus (UI) | — | Bagian D |
| Catatan | A-F3 | Seed `MasterDataSeeder` memberi id `jenis_pegawai` 2 = PPPK, sementara pemetaan akun [K] memakai id legacy 2 = PTT, 6 = PPPK |

### QASMTASK-048 — B-06 Koreksi NIP (Cascade)

| DoD | Hasil | Bukti |
|---|---|---|
| Ubah NIP → cascade 20+ tabel dalam 1 transaksi | PASS | Pegawai target diisi 1 baris di setiap tabel perujuk NIP (`isi_anak.py`): 44 kolom/45 baris sebelum (19 tabel `riwayat_*`, 19 snapshot, `detail_anak` lewat keluarga, `absen_ijin`, `faq_rate`, `konv_ak`, `ignore_konv_ak`, `pegawai_hist`, `pegawai_plt/plh`, `document_attachment` 2 (lampiran organisasi + arsip DU 38), `jabatan_koordinasi`, `riwayat_lckh.nip_atasan` milik pegawai lain, `dm_user_lokasi_presensi` JSON). `POST pegawai/{nip}/koreksi-nip` role 1 → 200 `tabel` 46 entri / 46 baris, `dilewati: []`, `sesi_dicabut: 5`; jumlah baris per kolom sama sebelum/sesudah; baris `pegawai` lama terhapus, baru ada; `GET pegawai/{baru}` 200, `{lama}` 404 |
| Data dummy 20+ record konsisten | PASS | Di atas; satu-satunya sisa di NIP lama: `riwayat_skp_periodik.nip` (di luar registry — A-F4) |
| JSON & kolom khusus | PASS | `dm_user_lokasi_presensi.target_peg`: elemen NIP lama → baru, NIP "mirip" (awalan sama + `9`) tidak tersentuh, `excl_peg` = [baru], `*_desc` awalan "NIP - " ikut diganti; `jabatan_koordinasi.nip` ikut; arsip DU (`document_attachment` 38, `id_entri` NULL) ikut kolom `NIP` |
| Akun & sesi | PASS | `username` & `nip` akun = NIP baru, password direset ke NIP baru (login baru/baru 200, login username lama ditolak), seluruh refresh token dicabut; audit `pengguna update` (password dimasking `***`) |
| ROLLBACK PENUH bila 1 tabel gagal | PASS | Tabel uji `qa_anak_luar` (FK RESTRICT ke `pegawai.nip`, di luar registry) di DB scratch → koreksi gagal 500 di langkah "hapus lama"; semua 45 rujukan tetap di NIP lama, NIP baru tidak ada, akun (username/password/status) & token tidak berubah, JSON tidak berubah, tidak ada `audit_logs` baru. Pesan 500 di development memuat pesan DB (A-F5) |
| Validasi | PASS | Role 2–8 dan akun role 2 pemilik → 403; tanpa token 401; NIP lama tidak ada 404; `nip_baru` kosong / `12AB` / 19 digit / sama / dipakai pegawai lain (pesan memuat nama pemilik [K]) / username akun lain / NIP akun lain / angka JSON / array → 422 `nip_baru`, data tidak berubah |
| Unit test WAJIB (cascade + rollback) | PASS | PHPUnit `NipCascadeTest` OK (bagian dari 125 test) |
| Form peringatan/konfirmasi (UI) | — | Bagian D |
| Lolos MTC-017/018 | N/A (fase MT) | — |

### QASMTASK-060 — B-18 Upload Lampiran (+ Arsip Kepegawaian 37/38)

| DoD | Hasil | Bukti |
|---|---|---|
| Batas ukuran sesuai legacy | PASS (legacy per jenis, bukan 1-2 MB rata) | Organisasi/Pendidikan 5 MB (legacy `L_organisasi.php:114`, `L_pendidikan.php:179` "5MB"): 5 MB+ → 422 "Ukuran berkas melebihi 5 MB."; Diklat 1 MB (legacy `L_diklat.php:142`): 1 MB+1 B → 422 "Ukuran berkas melebihi 1 MB.", ±1000 KB → 201; berkas kosong → 422 "Berkas kosong." |
| Tersimpan dengan `id_parent` + `jenis_rwy` benar | PASS | Tambah organisasi + `berkas[13]` (satu request) → `document_attachment` `NIP` = pemilik, `id_riwayat` 13 (= `jenis_rwy` organisasi), `id_entri` = PK baris; respons tanpa `path`, kunci `NIP` huruf besar; tanpa lampiran wajib → 422 `berkas.13` |
| Tipe/ukuran ditolak dengan pesan jelas; validasi dari ISI | PASS | Teks/ZIP/HTML bernama `.pdf`/`.jpg`, EXE (`MZ`) bernama `.png`, `.docx` pada jenis tanpa docx → 422 "Jenis berkas tidak diizinkan (hanya jpg, jpeg, png, gif, pdf)."; OOXML: DOCX asli → 201 (`file_ext` docx); ZIP biasa bernama `.docx`/`.xlsx` → 422 "Isi berkas tidak sesuai dengan jenis docx/xlsx."; ZIP ber-`[Content_Types].xml` tanpa `word/` → 422; teks bernama `.doc` → 422; nama simpan dibangkitkan server, nama tampil tanpa folder (A-F8) |
| Daftar/unggah/unduh/hapus + izin | PASS | `GET lampiran` tanpa `id_entri`/`id_riwayat` → 422; `id_riwayat` 99 → 404; `id_entri` tidak ada → 404; tanpa token 401. Organisasi (izin 1,2,3,7): daftar role 1 & admin unit 21 → 200, role 3 satker lain / admin unit 30 → 403, role 4/5/6/7/8 → 403; unggah role 4/6/3-luar → 403, admin unit 21 / role 1 → 201. Unduh pemilik → 200, isi identik, `Content-Disposition: attachment` (nama UTF-8 `filename*`), `nosniff`, `private, no-store`; CRLF/kutip di nama tidak menyuntik header. Hukdis (izin 1,3): role 2 daftar/unggah lampiran hukdis miliknya → 403 |
| Lintas NIP 404 | PASS | Unduh/hapus lampiran milik NIP A lewat path NIP B (role 1) → 404; role 7 lewat NIP sendiri → 404 |
| Urutan 403/404 anti-oracle | PASS | Role 3 di luar lingkup: unduh id ada vs id 999999 → 403/403; role 7 hapus di NIP lain: id ada vs tidak → 403/403; NIP sendiri id tidak ada → 404; role 2 NIP tidak ada → 403 |
| Lampiran wajib terakhir → 422 | PASS | Hapus lampiran hingga tersisa satu (hard delete, `soft_delete: false`, audit `document_attachment delete`); hapus yang terakhir (role 2 maupun role 1) → 422 `berkas` "Lampiran wajib tidak boleh dihapus semua. Unggah berkas pengganti dulu…" |
| Kunci baris Disetujui | PASS | Setelah setujui: role 2 unggah/hapus → 403 "Data yang sudah disetujui tidak dapat diubah."; role 1 unggah 201; role 2 unduh tetap 200 |
| `bolehAksiUntuk` CPNS pendidikan (CR-046) | PASS | Role 2 tanpa snapshot `pegawai_kp` → tambah pendidikan 403, unggah lampiran pendidikan 403, daftar & unduh 200; snapshot pangkat CPNS → unggah 201; pangkat PNS → unggah 403, hapus 403; role 1 hapus lampiran opsional 200 |
| Baris status 10 → 404 | PASS | Riwayat dihapus (status 10): daftar/unduh/unggah/hapus lampirannya → 404 (role 1 dan 2) |
| Arsip Kepegawaian 38 (`arsip_du`) | PASS | Role 2 NIP sendiri `jenis_arsip=KTP` → 201, disimpan `id_entri` NULL + `tag` KTP + `NIP`; Lain-lain tanpa nama → 422, dengan nama → tag = nama; jenis di luar daftar / tanpa jenis → 422; docx → 422 (jpg/jpeg/png/pdf); `id_entri` ≠ NIP → 404; unggah role 6/7 atas NIP lain, role 4/5/8, role 3 di luar lingkup → 403; admin unit 21 dalam lingkup, role 1, role 6 NIP sendiri → 201; daftar role 1/2/4/5/8/admin unit 21 → 200, role 3 luar / role 6 lain → 403, tanpa token 401; unduh role 4 200; hapus role 4 / role 6 → 403, pemilik 200; admin unit 21 atas pegawai unit 30/s38 → 403 (jalur detail), admin unit 30 → 200 |
| Arsip Status 37 (`status_du`) | PASS | Role 1 DOCX asli → 201 tag bawaan "Arsip Status"; `nama_arsip` → tag; unggah role 2/3/admin unit 21/4/6 → 403; daftar role 2/4/admin unit 21 → 200; hapus role 3 → 403, role 1 → 200 |
| Uploader drag-and-drop & preview (UI) | — | Bagian D |
| Lolos MTC-023/024 | N/A (fase MT) | — |
| Regresi PHPUnit | PASS | `LampiranEndpointTest`, `AttachmentServiceTest`, `ArsipDataUmumTest` OK |

### QASMTASK-062 — B-20 Detail Pegawai & Pencarian (bagian API)

| DoD | Hasil | Bukti |
|---|---|---|
| Daftar `GET pegawai` + lingkup CR-045 | PASS | Role 1/4/5/8 = semua pegawai status 1/2 (termasuk tanpa pmj); role 2/6/7 → 403; tanpa token 401. Role 3 satker 43 = {unit20/s43 ×2}; **admin unit 20 = unit 20 + UPT unit 6** (3 NIP); **admin unit 21 = unit 21 + satker 37/38/41 (termasuk unit 30/s38)** (6 NIP); admin unit 30 = unit 30 (3 NIP); pegawai tanpa pmj tidak tampil untuk role 3 manapun |
| Detail `GET pegawai/{nip}` = UL_ALL + lingkup | PASS | Admin unit 20 → UPT 403 (tampil di daftar, 403 di detail — disengaja [K]); admin unit 21 → unit 7/s37, unit 7/s41, unit 21 = 200, unit 30/s38 = 403; role 3 → NIP tidak ada 403 (anti-oracle), role 1/4 → 404; role 2/6/7 hanya NIP sendiri; role 1/4/5/8 pegawai tanpa pmj 200; tidak mengirim `foto`/`siasn_flag`/`siasn_lu`/`deleted_at` |
| `tabs` = `descriptorUntuk` | PASS | Untuk role 1/2/3/4/6/7/8: `data.tabs` tanpa `lkh` identik dengan `GET pegawai/{nip}/riwayat`; `lkh` hanya untuk pemilik/role 1/role 3 (bukan 4/5/8); contoh role 3: `hukdis` tanpa hapus (keputusan user), `ak` lihat saja; role 8 `tabs: []` |
| Filter/sort/paginasi | PASS dengan catatan (A-F6) | `id_unit=7`, `id_satker=45`, `id_jenis_pegawai=3`, `search` nama/NIP benar; `search=%`/`_` literal (0 hasil); 6 kunci sort 200; page 2 × 5 benar; `page=0` → 422; parameter tak dikenal diabaikan (A-F6) |
| Advanced search role 1, 3, 4, 5 | PASS | Katalog & pencarian: role 1/3/4/5 → 200, role 2/6/7/8 → 403, tanpa token 401. Hasil: hanya pegawai status 1; role 3 dibatasi lingkup [V2]; kriteria riwayat `org` = EXISTS baris status 1 (baris status 10 tidak ikut); OR/AND, IN/NOT IN, `>=` tanggal jalan |
| Whitelist & nilai | PASS | Atribut di luar whitelist / berupa potongan SQL → 422 `kriteria.0.atribut`; operator `REGEXP` → 422; jenis `pengguna` → 422; `last` pada jenis hanya-riwayat / atribut hanya-riwayat → 422; nilai `:nama.x:` dan `a,:b.c:` (IN) → 422 "Nilai tidak boleh memuat pola :kata:."; IN 51 nilai → 422; 0 / 11 kriteria → 422. Nilai `a:b` dan `jam 08:00` → 200; nilai berkutip `x' OR '1'='1` → 200, 0 hasil; `LIKE %` literal → 0 hasil; jumlah baris `pegawai` tidak berubah (uji tidak merusak) |
| Ekspor CSV/XLSX | PASS dengan catatan (A-F1) | Role 1/3/4/5/8 → 200, 2/6/7 → 403, tanpa token 401. CSV: BOM UTF-8, `text/csv`, `attachment`, `no-store`; jumlah baris = total daftar (23); role 3 = 2 baris lingkup, admin unit 20 = 3 (lingkup daftar memuat UPT), filter `id_unit=30` diikuti; nilai diawali `=`/`+`/`-`/`@` diberi apostrof (`'+1 =HYPERLINK(...)`, `'-2+3`, `'@SUM(A1)`). XLSX: semua sel `t="inlineStr"` (NIP 18 digit & `nip_lama` `012345` utuh), tanpa `<f>`. `format=pdf`, `kolom=password`/`foo` → 422; `POST pegawai/pencarian/export` = hasil pencarian (role 8 → 403) |
| Cetak DRH | PASS (A-F10 Info) | `GET pegawai/{nip}/drh`: role 2/6/7 NIP sendiri 200, NIP lain 403; role 1/4/5/8 200; role 3 dalam lingkup 200, luar 403; admin unit 20 → UPT 403; admin unit 21 → unit 7/s41 200; tanpa token 401; NIP tidak ada: role 1/4 404, role 3 403. PDF `%PDF`, `attachment; filename="drh_<nip>_20261015.pdf"`, `inline=1` → `inline`, `no-store`; isi memuat bagian A–D (`pdftotext`) |
| QA Lapis 1 (profile card, tab, grid, badge) | — | Bagian D |
| Regresi PHPUnit | PASS | `PegawaiEndpointTest`, `PegawaiScopeTest`, `PegawaiPencarianEksporTest`, `DrhPegawaiTest` OK |

## Temuan

| ID | Severity | Ringkas | Butir |
|---|---|---|---|
| A-F1 | Minor | Ekspor CSV menulis NIP 18 digit/NIP lama sebagai angka polos → dibuka Excel menjadi angka ilmiah (3 digit akhir hilang) dan nol depan `nip_lama` hilang | 062 |
| A-F2 | Trivial | Dokumen `db-review` B-01/B-02 dan DBV-019 (serta progres) masih berstatus "⏳ MENUNGGU REVIEW DB VALIDATOR" walau sudah disetujui & di-merge | 043, 044 |
| A-F3 | Trivial | `MasterDataSeeder` (dev/test) memberi `jenis_pegawai` id 2 = PPPK, 3 = PTT; pemetaan akun [K] memakai id legacy 2 = PTT, 6 = PPPK | 047 |
| A-F4 | Info | `riwayat_skp_periodik.nip` (dan `riwayat_skp.nip_penilai`/`nip_atasan_penilai`) tidak ikut koreksi NIP | 048 |
| A-F5 | Info | Kegagalan cascade → 500 dengan pesan DB mentah di development (production disamarkan) | 048 |
| A-F6 | Info | `GET pegawai` mengabaikan parameter tak dikenal; `GET pegawai/export` tanpa `format` = XLSX | 062 |
| A-F7 | Info | Access token lama tetap berlaku sampai `exp` setelah akun dinonaktifkan/dilepas/koreksi NIP | 047, 048 |
| A-F8 | Info | `display_name` lampiran mempertahankan karakter seperti `<script>` (hanya folder & karakter kontrol dibuang) | 060 |
| A-F9 | Info | Kolom tanpa FK: `pegawai_diklat`/`riwayat_diklat.id_sub_group_jabatan`, `riwayat_skp_periodik.nip` | 043, 044 |
| A-F10 | Info | Cetak DRH terbuka untuk role 6/7 (NIP sendiri) dan 8, berbeda dengan Matriks (1–5) | 062 |

### A-F1 — CSV ekspor: NIP berubah angka di Excel (Minor)

- **Langkah:** role 1 `GET api/v1/pegawai/export?format=csv&kolom=nip_lama` → buka berkas di Excel.
- **Hasil:** sel NIP ditulis polos (`…,199101012015011207,…`); Excel membacanya sebagai angka → `1.99101E+17`, dan bila disimpan ulang 3 digit terakhir menjadi `000`; `nip_lama` `012345` menjadi `12345`. XLSX aman (semua sel `inlineStr`).
- **Harapan:** NIP tetap teks (brief QA: "NIP teks"; kontrak README `GET pegawai/export`: "sel teks, NIP tidak berubah angka").
- **Penyebab:** `app/Libraries/Kepegawaian/Pegawai/EksporTabel.php` `csv()`/`aman()` hanya menambah apostrof untuk awalan formula; CSV memang tidak punya tipe sel.
- **Opsi perbaikan:** (a) tulis NIP/NIP lama sebagai `="199…"`. Ini bertabrakan dengan escape formula, jadi harus dikecualikan khusus kolom NIP; (b) awali NIP dengan TAB/apostrof (konsisten dengan escape: `'199…`); (c) catat sebagai keterbatasan CSV dan arahkan pengguna Excel ke XLSX (default UI). Perlu diputus reviewer; legacy (DataTables CSV) kemungkinan punya perilaku sama. Bila diputuskan [K], temuan ini gugur.
- **Keputusan tercatat:** tidak ditemukan untuk CSV (keputusan Q-M6-2 hanya menyebut format CSV/XLSX).

### A-F2 — Status dokumen review DB basi (Trivial)

- `backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md:15-17` dan `DBV-019-fk-g02-pegawai-schema.md:7-9` masih menulis "⏳ MENUNGGU REVIEW DB VALIDATOR … belum ada butir yang direview atau disetujui". Hal yang sama ada di `backend/docs/progress/03-Kepegawaian.md` bagian "Skema Fase 3": DBV-019 "PR #23 … ⏳ menunggu review" dan "FK G-02 … DBV-019 (PR #24, menunggu review DB Validator)" di baris entry criteria.
- Faktanya: DB Validator menyetujui PR #20 (06-10-2026) dan PR #24 (07-10-2026) di komentar PR; keduanya sudah di-merge (06-10 dan 13-10).
- **Opsi:** perbarui status + keputusan per butir (Bagian 7/8) dari komentar DB Validator, agar jejak approval bisa dibaca dari repo.

### A-F3 — Seed `jenis_pegawai` tidak cocok dengan pemetaan akun (Trivial)

- `tests/_support/Database/Seeds/MasterDataSeeder.php:31-33`: id 1 PNS, **2 PPPK**, 3 PTT. `AkunPegawai::roleUntukJenisPegawai()` [K] (`L_employee.php:158`): **2 → PTT (role 6)**, 6 → PPPK (role 7).
- Di lingkungan dev/QA yang memakai seeder ini, menambah pegawai berjenis "Pegawai Pemerintah dengan Perjanjian Kerja" (id 2) membuat akun role 6 (PTT). Ini terbukti di uji: `id_jenis_pegawai=2` → `user_level` 6. Produksi tidak terdampak bila master diimpor dari legacy (id legacy 2 = PTT, 6 = PPPK).
- **Opsi:** samakan id seed dengan legacy (2 = PTT, 6 = PPPK) atau beri catatan di seeder.

### A-F4 — `riwayat_skp_periodik.nip` tidak ikut koreksi NIP (Info)

- Setelah koreksi, `riwayat_skp_periodik.nip` tetap NIP lama, sehingga baris SKP periodik pegawai menjadi yatim dan tab SKP periodik kosong. Hal yang sama berlaku untuk `riwayat_skp.nip_penilai`/`nip_atasan_penilai` (rujukan ke pegawai lain).
- Perilaku ini sesuai keputusan #10 (`docs/fase3/WS2.md:377`, ikut `update_nip` legacy + `jabatan_koordinasi`), jadi **bukan temuan**. Namun README `Libraries/Kepegawaian` "Pertanyaan terbuka CR-036" #3 masih menandainya sebagai "Usulan: diputuskan per kolom di B-06". Mohon reviewer menutup pertanyaan itu secara eksplisit (data SKP periodik ditarik ulang dari e-Kinerja per NIP?).

### A-F5 — Pesan 500 cascade memuat detail DB (Info)

- Kegagalan langkah "hapus lama" → 500 `"Koreksi NIP gagal di pegawai (hapus lama): Cannot delete or update a parent row: … CONSTRAINT …"` (`NipCascadeService.php:333`, dilempar ulang `ApiController.php:70-74`). Di production `ApiExceptionHandler.php:94` mengganti pesan dengan "Terjadi kesalahan pada server.", jadi bukan kebocoran produksi. Rollback-nya sendiri benar.

### A-F6 — Parameter daftar longgar (Info)

- `GET pegawai?sort=xx|order=zz|status=10|status=a` → 200 dengan bawaan (nama, status 1/2); `per_page=101` dijepit ke 100; `id_unit=x` → 0 hasil. `GET pegawai/export` tanpa `format` → XLSX. Tidak ada data di luar lingkup yang bocor; kontrak hanya menulis `per_page ≤ 100`.

### A-F7 — Access token setelah akun dinonaktifkan (Info)

- Setelah status pegawai 1→2 (akun PNS nonaktif), pegawai dihapus (akun dilepas), atau koreksi NIP, refresh token dicabut, tetapi access token yang sudah terbit tetap 200 sampai `exp` (maks 1 jam). Ini sesuai desain ADR-004 dan sudah dicatat di QAFUNC-002.

### A-F8 — `display_name` lampiran (Info)

- Nama kiriman `../../etc/<script>.png` disimpan sebagai `display_name` `<script>.png` (folder & karakter kontrol dibuang, `ValidasiBerkas::namaTampil`). Header unduh aman (`filename` ASCII disanitasi + `filename*` UTF-8, CRLF tidak menyuntik header); frontend wajib tetap merender sebagai teks (Vue default).

### A-F9 — Kolom tanpa FK (Info)

- `pegawai_diklat.id_sub_group_jabatan` dan `riwayat_diklat.id_sub_group_jabatan` (kolom prod D1, B-01/B-02 dok :252, :808) tanpa FK ke `sub_group_jabatan`, tidak termasuk 55 FK DBV-019 (ikut D1). `riwayat_diklat.id_rumpun_sertifikasi`/`id_lembaga_sertifikasi` tanpa master (kebutuhan DBV baru, progres B-11). `riwayat_skp_periodik.nip` tanpa FK (DBV-013). Semuanya keputusan skema yang tercatat → bukan temuan.

### A-F10 — Hak Cetak DRH (Info)

- v2: UL_ALL (2/6/7 NIP sendiri; 1/3/4/5/8 lingkup), sesuai kontrak README dan legacy `controllers/hr/Employee.php:642-654` (`print_drh` = `UL_ALL`, pegawai hanya NIP sendiri). Matriks `hr/employee/print_drh` dan rencana `WS2.md:121,262` menulis 1, 2, 3, 4, 5 (sumbernya `print_drh_2`, `Employee.php:686-688`). Karena mengikuti legacy dan tertulis di kontrak, ini bukan temuan; mohon reviewer mengonfirmasi bahwa role 6/7/8 memang boleh.

## Catatan skrip (FAIL skrip yang bukan cacat)

| Skrip | FAIL skrip | Penjelasan / uji ulang |
|---|---|---|
| `t_scope` | 6 | Harapan 422 untuk `sort`/`order`/`status`/`per_page`/`id_unit` tak valid → aplikasi longgar (A-F6, Info) |
| `t_bio` | 3 | Payload tanpa `id_jenis_status` (role 1) / tanpa foto (pegawai belum berfoto) pada putaran pertama; putaran kedua + `t_bio2` (7 PASS) mencakup semua butir; `pegawai_foto` = 2 karena setiap ubah role 1 dengan foto menambah galeri (sesuai desain) |
| `t_appr` | 2 | Pengajuan akun role 7 kedua sudah ditimpa role 1 (status 1→2) sehingga tidak lagi menunggu; `flag_update` dikirim sebagai string `'1'` (bentuk MySQLi), bukan int |
| `t_siklus` | 1 | Bug skrip (`_tok` tidak terimpor); login password = NIP diverifikasi manual (200) + audit `pengguna login` |
| `t_lamp` | 4 | `pegawai_kp` gagal di-INSERT (kolom `tgl_sk` wajib) → diulang di `t_lamp2` (4 PASS); harapan nama tampil tanpa `<` (A-F8); payload diklat salah → diulang di `t_dik` (2 PASS) |
| `t_b20` | 4 | Nama gabungan `glr_awal + nama` (`'+1 =HYPERLINK`) tetap diawali apostrof; XLSX memakai `inlineStr` (tidak perlu apostrof, tanpa `<f>`); `hubung` hanya divalidasi mulai kriteria ke-2 (kode `PencarianLanjutan.php:237`); ekspor tanpa `format` = XLSX (A-F6) |

PHPUnit (DB `simpeg_v2_qa3a_t`): `NipCascadeTest`, `BiodataTest`, `ApprovalBiodataTest`, `SiklusPegawaiTest`, `LampiranEndpointTest`, `AttachmentServiceTest`, `ArsipDataUmumTest`, `PegawaiScopeTest`, `PegawaiEndpointTest`, `PegawaiPencarianEksporTest`, `DrhPegawaiTest` → **OK (125 test, 1791 assertion)**; `tests/Auth/AccountProvisionerTest` + `EmailUnikTest` + `ValidasiNipPegawaiTest` + `tests/database/Kepegawaian` → **OK (44 test)**; `tests/database/Kepegawaian` sendiri → OK (22 test, 3965 assertion).

## Bersih-bersih

- Server `php spark serve` port 8111 dihentikan.
- DB `simpeg_v2_qa3a` dan `simpeg_v2_qa3a_t` di-DROP (nama persis); tabel uji `qa_anak_luar` sudah di-DROP sebelumnya.
- `.env` worktree `wt/qa3a/backend` dihapus; berkas unggahan uji di `writable/uploads/lampiran` dihapus.
- Tidak ada perubahan kode, commit, push, atau tulisan ke Trello/Notion/GitHub (GitHub hanya dibaca lewat API publik untuk status review PR #20/#24). Instance MySQL 3307 dan Laragon 3306 tidak disentuh selain DB scratch.

## QA Fungsional Fase 3 — Bagian B (riwayat): QASMTASK-049..059 (+054 LKH, +055 Konket)

| | |
|---|---|
| **Jenis** | QA Functional black-box API + DB (tanpa Vite/UI), atas izin user (reviewer CR) |
| **Tanggal eksekusi** | 15 Oktober 2026 (hari ini bisnis WIB = Kamis 15-10-2026; dipakai untuk aturan tanggal Konket/LKH) |
| **Commit yang diuji** | `576ee8f` (`main`), worktree terpisah `scratchpad\wt\qa3b` (detached) |
| **Lingkungan** | PHP 8.4.2, MySQL 8.0.30 instance test 127.0.0.1:3307, DB scratch `simpeg_v2_qa3b` (utf8mb4_unicode_ci) hasil `php spark migrate --all` + `MasterDataSeeder` (memanggil `AuthSeeder`), `php spark serve --port 8112`, `.env` baru dari `.env.example` (`CI_ENVIRONMENT=development`, captcha `mock`, JWT/encryption key acak lokal). Akun & pegawai uji sintetis dibuat harness (NIP `198001012005011901..935`, nama "Pegawai QA <kode>", password seed lokal); master tambahan uji: `satker` 39, `struktur_jabatan` periode 2 (jabatan 1 level 3, jabatan 2 level 4 di bawah 1), `jenis_pegawai` 6, `jenis_status` 12, `jenis_konket` uji (lihat B-F4), satu `hari_libur` sementara |
| **Harness** | `scratchpad\qa3b\` — `lib.php` (HTTP + mysqli), `btc.php` (B-TC generik), `t1..t11.php`; keluaran mentah `out-*.txt` (≈700 cek) |
| **Rujukan** | `qa3-dod.md`, `qa3-dod-trello.md` (checklist resmi), Matriks `docs/fase3/MATRIKS_ROLE_MODUL_B.md`, keputusan user di README `Libraries/Kepegawaian`, README `Controllers/Api/Kepegawaian`, `Riwayat/Definisi/*.php` (izin per jenis) |
| **Hasil** | **Seluruh butir DoD fungsional bagian B PASS** (beberapa dengan catatan). Tidak ada temuan Blocker/Major/Medium/Minor. 4 temuan **Info** (B-F1..B-F4), tidak menggagalkan butir. Butir "QA Lapis 1" dinilai bagian D; butir "Lolos MTC-0xx" = N/A (fase MT). |

Catatan metodologi: 40 baris FAIL di keluaran mentah adalah **kesalahan harness** yang lalu diperbaiki dan diulang (bukan
cacat produk): payload uji kurang isian wajib (`masa_hukuman`, `jenis_jabatan_koord`, `jumlah_jp`), ekspektasi salah
(`?? 'x'` pada nilai NULL, role 4 juga berhak lihat `ak-siasn`), pemroses AK = role 1 (keputusan A1), Hukdis tanpa alur
proses, dan efek data uji (snapshot pmj terhapus saat satu-satunya riwayat jabatan dijadikan Menunggu → lihat B-F3). Uji
ulang ada di `out-t2-jab/hd/ak.txt`, `out-t4b.txt`, `out-t5b.txt`, `out-t6b.txt`, `out-t11.txt`.

## B-TC generik per jenis engine (API + DB)

`btc()` dijalankan untuk 13 jenis: pendidikan, kp, kgb, jabatan, diklat, seminar, skp, hukdis, ak, keluarga, alamat,
tanda-jasa, organisasi. Isi per jenis (self-service = pegawai pemilik; admin = role 1):

| # | Kasus | Hasil | Bukti (contoh) |
|---|---|---|---|
| 1 | Pegawai input mandiri → status 0, snapshot belum berubah (self-service) | PASS (pendidikan, diklat, seminar, skp, keluarga, alamat, organisasi) | `POST pegawai/{nip}/riwayat/pendidikan` multipart + `berkas[14]` → 201 `status=0`; hash isi `pegawai_pendidikan` sebelum = sesudah |
| 1b | Alur admin: pegawai tambah → 403; admin tambah → status 1 + snapshot langsung | PASS (kp, kgb, jabatan, hukdis, ak, tanda-jasa) | role 2 `POST …/kp` → 403; role 1 → 201 `status=1`, `pegawai_kp` berubah |
| 2 | Setujui oleh role 1/3 lewat `process` → 1, `approved_by` terisi, `show_notif=1`, `notif_date`, `show_ua_*` | PASS | pendidikan id 2: role 3 → 200 `status=1 approved_by=17`; organisasi oleh role 3 tanpa satker → `show_ua_deputi=1` |
| 3 | Snapshot `pegawai_*` hanya berubah saat approval final (+ audit manual snapshot) | PASS | `audit_logs` entity `pegawai_pendidikan` 0 → 1, `pegawai_kp` 4 → 5, `pegawai_mutasi_jabatan`, `pegawai_kgb`, `pegawai_diklat`, `pegawai_keluarga`, `pegawai_alamat`, `pegawai_tanda_jasa`, `pegawai_hukdis`, `pegawai_ak` |
| 4 | Tolak → 2 + `reason_note`; tanpa alasan → 422 `reason_note`; snapshot tidak tersentuh | PASS | `{"reason_note":["Alasan penolakan wajib diisi."]}`; hash snapshot sama |
| 5 | Proses ulang baris bukan Menunggu → 422 `status` | PASS | `{"status":["Hanya data berstatus Menunggu yang dapat diproses."]}` |
| 6 | Role 3 dibatasi lingkup (PegawaiScope nyata) | PASS | admin satker 1: GET/POST/proses pegawai satker 3 → 403, satker sendiri → 200; admin tanpa satker unit 2: pegawai unit 2 → 200, unit 1 → 403; NIP tidak ada → 403 (role 3) / 404 (role 1) |
| 7 | Role tanpa hak → 403 (izin lihat Matriks; tulis/proses legacy) | PASS | lihat tabel per jenis di bawah; tanpa token → 401 |
| 8 | Kunci baris Disetujui: pegawai ubah/hapus → 403; role 3 hapus → 403 | PASS | semua jenis self-service; role 3 DELETE baris Disetujui → 403 di 13 jenis |
| 9 | Hapus lunak → status 10, detail 404, tidak di daftar, audit `delete`, snapshot dihitung ulang | PASS | `{"deleted":true,"soft_delete":true}`; audit `create,update,update,delete` |
| 10 | Pilihan status role 1 saat ubah (KP/KGB/Pendidikan + jenis lain yang mewarisinya) + alasan wajib saat Ditolak; status 3 ditolak saat flag nonaktif; role 3 / pegawai mengirim `status` diabaikan | PASS | role 1 `PUT {status:2}` → 422 `reason_note`; `{status:2, reason_note}` → 2; `{status:3}` → 422; pegawai ubah baris Ditolak + `status:1` → 0 (diajukan ulang); role 3 → 1 |
| 11 | Cek duplikat nilai akhir → 422 | PASS | pendidikan `tgl_lulus`, kp/kgb/jabatan/hukdis `tmtsk`, skp tahun+periode (status 1) |
| 12 | Lampiran wajib/opsional per kode, tipe dari isi, ukuran | PASS | tanpa berkas → 422 `berkas.<kode>` (14, 11, 10, 9, 5, 22, 32, 7, 20, 1, 23, 13, `file_pak`); `.txt` → 422 "Jenis berkas tidak diizinkan"; kode milik jenis lain → 422 `berkas.9`; opsional 40 tersimpan `id_riwayat=40`; sertifikat diklat 1,5 MB → 422 "melebihi 1 MB" |
| 13 | 422 per field | PASS | payload kosong → `errors` per kolom (mis. KP `id_jenis_kp,id_pangkat,tmtsk,no_sk,tgl_sk,gaji_pokok,mker_th,mker_bl`) |
| 14 | Kolom sistem dari klien diabaikan; `{id}` milik NIP lain → 404; jenis tak dikenal → 404 | PASS | pegawai kirim `status=1`, `nip` lain, `approved_by` → tersimpan `status=0`, nip path |
| 15 | Snapshot saat ubah data baris Disetujui (afUpd) dan keluar dari status 1 | PASS | KP ubah `no_sk` → `pegawai_kp.no_sk` ikut; baris 2023 → Ditolak → `pegawai_kp.tmtsk` kembali `2019-10-01` |

Descriptor tab (`GET pegawai/{nip}/riwayat`) konsisten dengan izin: role 1 `hukdis` = CED (tanpa proses, legacy),
`skp-periodik`/`ak-siasn` = E; role 3 `jabatan` = EP, `tanda-jasa` = EDP, `hukdis` = CE, `ak` lihat saja; role 2 diri sendiri
`kp/kgb/jabatan/tanda-jasa/pendidikan(PNS)` lihat saja; role 4 atas pegawai JFT = jabatan, kp, kgb, tanda-jasa, ak, ak-siasn
(tanpa hak tulis); role 8 = `[]`; `karpeg`/`kariskarsu` tidak pernah jadi tab.

## Ringkasan per QASMTASK (checklist Trello)

### QASMTASK-049 — B-07 Riwayat Jabatan / Mutasi — **PASS**

| DoD | Hasil | Bukti |
|---|---|---|
| CRUD mutasi jabatan (struktural/JFT/pelaksana/Plt/Plh/koordinator) | PASS | struktural (jabatan 2, satker 1) & pelaksana 201; JFT tanpa no induk/status → 422; JFT status 2 tanpa SK PS (36) → 422 `berkas.36`; koordinator tanpa `id_jabatan_koord` → 422; Plt (jenis 3) → `jabatan` "PLT Kepala Bagian Mutasi", pmj tidak ikut; Instansi Lain tanpa teks → 422 4 field, lengkap → 201 kolom nomenklatur NULL; PPPK tanpa golongan → 422, dengan → `gol_pppk` "IX"; Plt/Plh penugasan (`penugasan/plt|plh`): role 1 tambah/ubah 201/200, role 3 tambah 403, role 4 lihat 200, role 2 403, hapus permanen (keputusan user 13-10) |
| Sub rumpun multiselect wajib | PASS | multipart `id_subrumpun_jabatan[]` berulang (1,2) → `subrumpun_jabatan` "Manajemen Sumber Daya Manusia***Manajemen Keuangan"; JSON `[]`, multipart `''`, kunci absen → 422; bukan milik rumpun / kembar → 422; PUT tanpa sub rumpun = dipertahankan; ubah rumpun tanpa sub rumpun → 422; PUT JSON `[2]` → berganti |
| Filter satker, atasan Es turunan, satker 39 | PASS | jabatan terikat satker lain → 422 `id_jabatan`; atasan diturunkan `struktur_jabatan` periode aktif: pelaksana tanpa simpul → es2=1, es3=2; Kabag bersimpul → es2=1; atasan eksplisit bukan kandidat → 422 `id_atasan_es_2`; satker 39 tanpa teks → 422 `jabatan`, dengan teks → `id_jabatan` NULL |
| Snapshot pmj hanya saat approval final | PASS | B-TC #3/#4; urutan snapshot `tmtsk DESC` (pmj ikut baris TMT terbaru, Plt dikecualikan) |
| Hak (lihat 1,2,3,4,5; tambah/hapus 1; ubah/proses 1,3) | PASS | role 3 tambah 403, ubah 200 (status 1), hapus 403; role 4/5 lihat 200 tambah 403; role 6/7/8 lihat 403 |
| Seluruh B-TC | PASS (QA Lapis 1 → bagian D) | 36/36 cek `out-t2-jab.txt` |

### QASMTASK-050 — B-08 KP — **PASS** (MTC-019/020 N/A fase MT)

| DoD | Hasil | Bukti |
|---|---|---|
| CRUD + snapshot `pegawai_kp` (juga `pegawai_cpns`/`_pns`) | PASS | B-TC; Pengangkatan CPNS → `pegawai_cpns` terisi; nama jenis/pangkat diturunkan server ("Reguler", "III/c") |
| TMT 1 April/1 Oktober, tanggal lain ditolak | PASS | 1 Mei, 2 April, jenis 5 1 Juli → 422 `tmtsk` "…harus tanggal 1 April atau 1 Oktober. Periode terdekat: …"; 1 Okt → 201; CPNS 15 Mar → 201 (keputusan CR-025); CPNS kedua → 422; ubah TMT ke 1 Nov → 422, ubah tanpa TMT → 200 |
| Unit test validasi TMT | PASS | `vendor/bin/phpunit tests/unit/Kepegawaian/Kalkulasi` → OK 141 tests / 502 assertions (termasuk `KenaikanPangkatTest`) |
| Snapshot hanya setelah approval | PASS | B-TC #3; role 1 ubah baris Disetujui → Ditolak: snapshot dihitung ulang |
| Hak (lihat 1,2,3,4,5; tulis/proses 1,3) | PASS | role 6/7/8 → 403; role 4/5 lihat saja |

### QASMTASK-051 — B-09 KGB — **PASS** (MTC-019 N/A)

| DoD | Hasil | Bukti |
|---|---|---|
| CRUD + snapshot `pegawai_kgb` | PASS | B-TC 35/35; KGB 2025-04-01 → `pegawai_kgb.tmtsk` 2025-04-01 |
| Jarak ≥ 2 tahun dari KGB/KP terakhir | PASS | KP 2019-10-01: KGB 2020-04-01 → 422 "minimal 2 tahun dari TMT KP terakhir (1 Oktober 2019)…"; 2021-10-01 → 201; 2022-10-01 → 422 (acuan KGB); KP 2023-04-01: KGB 2024-04-01 → 422, 2025-04-01 → 201 |
| Unit test jarak | PASS | `KenaikanGajiBerkalaTest` dalam 141 test di atas |

### QASMTASK-052 — B-10 Pendidikan — **PASS**

| DoD | Hasil | Bukti |
|---|---|---|
| CRUD + snapshot `pegawai_pendidikan` hanya di approval final | PASS | B-TC 34/34 (pemilik CPNS) |
| Role 2 hanya CPNS; 6/7 boleh; role 1 pilih status | PASS | role 2 PNS tambah → 403 "…untuk pegawai ini", lihat 200, descriptor `can_create:false`; role 6 & 7 → 201 status 0; role 3 tambah → 201 status 1; pegawai ubah baris Menunggu + berkas 39 (`_method=PUT`) → lampiran bertambah; hapus baris Menunggu sendiri → 10 |
| Seluruh B-TC | PASS (Lapis 1 → bagian D) | jenjang > 3 tanpa bidang/jurusan → 422 per field; role 4/5/8 lihat → 403 |

### QASMTASK-053 — B-11 Diklat & Seminar — **PASS**

| DoD | Hasil | Bukti |
|---|---|---|
| CRUD diklat & seminar, approval draft | PASS | B-TC lengkap keduanya (diklat snapshot `pegawai_diklat`; seminar tanpa snapshot) |
| Diklat jenis 5 (Sertifikasi) teks | PASS | tanpa nama/rumpun/masa berlaku → 422 4 field; nama teks bebas → 201, `id_diklat` NULL; `id_diklat` bukan milik jenis → 422; sertifikat > 1 MB → 422 (batas legacy 1 MB) |
| Hak 1,2,3,7 | PASS | role 6 lihat diklat/seminar → 403, role 7 → 200; role 4/5/8 → 403 |

### QASMTASK-054 — B-12 SKP & LKH — **PASS dengan catatan** (MTC-021 N/A)

| DoD | Hasil | Bukti |
|---|---|---|
| SKP tahunan: input + nilai/predikat, batas nilai per tahun | PASS | B-TC 30/30; 2019 nilai SKP 101 → 422 "maksimal 100"; 2021 nilai 120 → 201 (skala 120); 2020 perilaku 121 → 422; 2023 → 422 `tahun` (≥ 2023 = e-Kinerja); 2022 tanpa rating → 422; 2022 rating → periode dipaksa 1 Jan–31 Des, `nilai_skp` NULL; role 6 lihat → 403 |
| SKP periodik read-only + arsip role 1 (keputusan S1) | PASS | daftar role 2 hanya status 1; tambah role 1 → 403; role 1 unggah `f_arsip_1` → 200; role 3/pegawai unggah → 403; pegawai unduh → 200; arsip > 1 MB → 422; `bkn_periode_ekinper` tanpa UI (keputusan) — tidak diuji |
| CRUD LKH | PASS | tambah 201 status 0 (nama/unit/satker server), unik per tanggal 422, kegiatan setengah terisi 422 `kegiatan.0`, ubah/hapus, status 10 |
| Atasan otomatis + timpa role 1 | PASS | `lkh/opsi` → atasan = pemegang jabatan 1 (dari `id_atasan_es_2` pmj); `nip_atasan` kiriman pegawai diabaikan; role 1 timpa `nip_atasan` → tersimpan; NIP bukan pegawai aktif → 422 |
| Rentang 4 hari kerja + hari_libur Fase 2 | PASS | 15-10 → minimal 12-10; 9-10 & 16-10 → 422; tambah `hari_libur` 13-10 → minimal 9-10 dan 9-10 → 201 |
| Verifikasi atasan langsung (setujui/tolak/revisi oleh `nip_atasan`) | PASS | antrean `lkh/verifikasi` atasan memuat LKH; role 1/3 proses → 404; tolak tanpa catatan 422; revisi → 3, pegawai ubah → 0; setujui → 1 |
| Hapus Disetujui hanya role 1; kunci tukin | PASS | pegawai ubah/hapus Disetujui 403, role 3 hapus 403, role 1 hapus 200 → 10; LKH 25-09 (bulan lalu, hari ini ≥ tgl 5) ubah/hapus → 422 `tgl_laporan` "…perhitungan Tukin…" |
| Generate PDF hanya Disetujui | PASS | Menunggu → 422 `status`; Disetujui → 200 `application/pdf` (`%PDF`), lihat B-F2 |
| Rekap bawahan, tab LKH di detail, lingkup | PASS | `lkh/rekap?bulan=2026-10` memuat bawahan; `lkh/rekap/{nip}` 200, bukan bawahan 403, bulan depan 422; tab `lkh` muncul untuk pemilik/role 1/role 3, tidak untuk role 4; role 3 `lkh?nip=` luar lingkup 403; role 4 → 403; role 6 → 200; audit `riwayat_lckh` tercatat |
| Seluruh B-TC | PASS (Lapis 1 → bagian D) | |

### QASMTASK-055 — B-13 Konket — **PASS dengan catatan** (MTC-021 N/A; lihat B-F1, B-F4)

| DoD | Hasil | Bukti |
|---|---|---|
| CRUD `absen_ijin` (arti legacy, keputusan) | PASS | pegawai ajukan → 201 W; ubah selama W → 200; ubah V → 403; hapus pemilik (tanpa syarat status, legacy) → 10; role 1/3 lingkup |
| konket_min_date + validasi legacy | PASS | opsi `tanggal_minimal` 2026-10-01; mulai 30-09 → 422; tanggal depan → 422; akhir < mulai → 422; tanpa bukti → 422 `file_bukti`; irisan W/V → 422; jenis terlarang role → 422 |
| Sakit | PASS | non-PTT 2 hari kerja → 422 "…harus melalui Cuti Sakit"; Jum+akhir pekan → 201; Senin berikutnya → 422 berturut-turut; PTT 2 hari → 201 |
| Dinas gabungan + peserta lingkup role 3 | PASS | role 3: peserta luar lingkup → 422 `peserta`, tanpa peserta → 422, peserta lingkup → 201 + 2 baris `id_parent`; pegawai boleh peserta mana pun (keputusan [K]); proses baris peserta → 422; setujui induk → peserta V; hapus induk → 3 baris status 10 |
| Role 4/5 lihat saja, role 8 tidak | PASS | antrean & detail 200, `can_create:false`, tambah/proses 403; role 8 → 403 |
| Proses V/X, alasan wajib, hanya W | PASS | tolak tanpa alasan 422; setujui → V; proses ulang → 422; role 3 atas pegawai luar lingkup: detail 404, proses 403; antrean role 3 hanya lingkup |
| Unduh bukti | PASS | pemilik 200 `%PDF`, pegawai lain 404 |
| Flag `affect_tukin` dari master terbawa | PASS dengan catatan (B-F1) | master per jenis dikirim di `konket/opsi`; kolom baris `affect_tukin` default 1, diubah admin (role 3 dinas gabungan `affect_tukin=2` → induk & peserta 2); pengajuan pegawai jenis Dinas (master 2) tersimpan 1 — sama dengan legacy |
| Audit | PASS | `audit_logs` entity `absen_ijin` create/update |

### QASMTASK-056 — B-14 Hukdis — **PASS** (MTC-021 N/A)

| DoD | Hasil | Bukti |
|---|---|---|
| CRUD hanya role 1, 3 (lainnya 403); hapus hanya role 1 (keputusan user) | PASS | role 3 tambah 201, ubah 200, hapus 403; role 1 hapus → 10; pegawai pemilik lihat riwayat & lampiran hukdis sendiri → 403; role 2/4/5/6/7/8 → 403; tidak ada alur proses (legacy) → `process` 403 juga untuk role 1 |
| Tanggal berakhir otomatis dari masa sanksi | PASS | jenis 6 bulan TMT 2024-01-01 → `akhir_hukdis` 2024-07-01, `masa_hukuman` "6 Bulan"; 12 bulan TMT 2025-01-31 → 2026-01-31; ubah TMT + akhir dikosongkan → dihitung ulang 2024-08-01; jenis tanpa masa → 422 `akhir_hukdis`+`masa_hukuman`; akhir < TMT → 422 |
| Unit test kalkulasi | PASS | `HukumanDisiplinTest` dalam 141 test |

### QASMTASK-057 — B-15 Angka Kredit — **PASS** (sesuai keputusan A6: `konv_ak`/`ignore_konv_ak` tidak dibangun; PermenPAN-RB lewat `dm_ak_jf` menunggu DBV-020)

| DoD | Hasil | Bukti |
|---|---|---|
| CRUD `riwayat_ak` + snapshot `pegawai_ak` | PASS | B-TC (pemroses role 1, keputusan A1); tambah → snapshot = baris terbaru; `appr_by/appr_at` terisi saat proses |
| Isian turunan server + pratinjau | PASS | `GET …/riwayat/ak/pratinjau?tgl_pak=2024-07-15` → jabatan/pangkat III/b/TMT/masa kerja; format salah → 422 `tgl_pak`; role 2 → 403; pegawai struktural → 403; tambah dengan `jabatan`/`nama` dari klien → diabaikan, `nilai_ak` = 25 + 12,5 = 37,5 |
| `nilai_ak` Current AK tidak tertimpa | PASS | baris `jenis_ak=1` `nilai_ak=100` diubah AK lama/baru → tetap 100; baris `jenis_ak=2` → dihitung ulang 40 |
| Role 6 di-block; role 2/7 hanya JFT | PASS | role 6 → 403; role 2 JFT 200, non-JFT 403; role 7 JFT 200, non-JFT 403; role 4 200; role 5 403; role 3 lihat 200, proses 403; tambah untuk struktural → 403 |
| AK SIASN | PASS | role 2 JFT lihat 200; tambah role 1 → 403 (read-only) |
| Seluruh B-TC | PASS (Lapis 1 → bagian D) | Catatan: uji proses memakai baris yang dipaksa Menunggu lewat DB (alur admin tidak menghasilkan status 0) |

### QASMTASK-058 — B-16 Keluarga & Alamat — **PASS** (MTC-022 N/A)

| DoD | Hasil | Bukti |
|---|---|---|
| CRUD keluarga + `detail_anak` terhubung ke NIP | PASS | anak tanpa akte → 422 `berkas`; dengan akte → 201, `jumlah_anak` 1, akte `document_attachment` `id_riwayat=3`, `id_entri`=id anak, `NIP` pemilik; pegawai lain lihat anak 403; keluarga disetujui → `pegawai_keluarga.jumlah_anak` 1; pegawai tambah anak pada keluarga Disetujui 403; role 3 hapus anak 403; role 1 tambah anak → snapshot 2; role 1 hapus anak → hapus keras, `jumlah_anak` 1 |
| CRUD alamat; snapshot setelah approval | PASS | B-TC; setujui → `pegawai_alamat.id_kelurahan` terisi; Kantor tanpa KTP → 201; KTP/Domisili tanpa KTP → 422 `berkas.1`; role 6 lihat 200 |
| Wilayah berjenjang Fase 2 | PASS (server) | kelurahan bukan milik kecamatan → 422 `id_kelurahan` (+ `kd_pos`); kabupaten bukan milik provinsi → 422; `master/kelurahan/options` memuat `parent` untuk dropdown (perilaku dropdown di UI → bagian D) |
| Seluruh B-TC | PASS (Lapis 1 → bagian D) | keluarga 1,2,3,7 (role 6/4/5/8 → 403); alamat 1,2,3,6,7 |

### QASMTASK-059 — B-17 Karpeg, Karis/Karsu, Tanda Jasa, Organisasi — **PASS** (MTC-022 N/A)

| DoD | Hasil | Bukti |
|---|---|---|
| Karpeg: pengajuan tercatat & bisa di-approve | PASS | `usulan/karpeg/saya` role 2 `can_create`; berkas wajib kurang → 422 `berkas.file_2..5`; "Karena Kehilangan" tanpa surat → 422; lengkap → 201 status 0; antrean `usulan/karpeg` role 1/4/5 200, role 3/2/6/8 403; role 4 proses 403; role 1 setujui → 1; pemilik ubah Disetujui 403; unduh berkas kolom 200 |
| Tanda terima PDF | PASS | sebelum disetujui → 422 `status`; sesudah → 200 `application/pdf` `%PDF`; pegawai lain → 403 |
| Karis/Karsu | PASS | tanpa keluarga → `syarat_terpenuhi:false`, ajukan → 422 `keluarga`; dengan keluarga → 201; tolak tanpa alasan 422, dengan alasan 2; pemilik hapus → 10 |
| Tanda Jasa | PASS | B-TC; role 3 tambah 403 (tambah role 1, legacy), ubah 200; LAIN-LAIN tanpa nama → 422; role 4/5 lihat saja |
| Organisasi | PASS | B-TC; role 6 → 403 |
| RBAC sesuai matriks/keputusan | PASS | lihat Matriks; tulis/proses ikut legacy per Definisi |
| Seluruh B-TC | PASS (Lapis 1 → bagian D) | |

## Temuan

| ID | Severity | Ringkasan | Keputusan tercatat? |
|---|---|---|---|
| B-F1 | Info | Konket: `absen_ijin.affect_tukin` tidak disalin dari `jenis_konket.affect_tukin`; pengajuan pegawai selalu 1 | Sebagian ([K] legacy) |
| B-F2 | Info | TCPDF 6.11.4 menulis WARNING deprecation di log setiap render PDF | Tidak |
| B-F3 | Info | Pegawai tanpa baris pmj tidak bisa diproses role 3 (fail-closed) | Ya ([V2] CR-045) |
| B-F4 | Info | Jenis Konket terlarang di-hard-code per `id_jenis_konket` (2/4/5/6); pasangan id ↔ old_id seeder fiktif membuat Dinas/Sakit tidak bisa diajukan pegawai | Sebagian (seeder menyebut fiktif) |

**B-F1 (Info) — affect_tukin Konket.** Langkah: pegawai `POST konket` kategori 8 (Dinas, master `affect_tukin=2`) →
`absen_ijin.affect_tukin = 1`. Hanya role 1/3 yang bisa mengisi `affect_tukin` (`KonketService.php:406-449`, default
`:191`). Ini sama dengan legacy (radio di `form_ad.php:116-122`, presensi legacy membaca `absen_ijin.affect_tukin`).
DoD menulis "flag affect_tukin dari master konket terbawa". Bukan cacat bila arti legacy dipakai. Usulan: catat di
dependency Fase 5 bahwa yang dipakai kolom baris, bukan kolom master. Bila maksud DoD adalah menyalin flag master, ubah
`tambah()` agar mengisi `affect_tukin` dari jenis bila admin tidak mengirimnya.

**B-F2 (Info) — deprecation TCPDF.** `writable/logs/log-2026-10-15.log`: `WARNING … [DEPRECATED] TCPDF is deprecated: migrate
to tecnickcom/tc-lib-pdf … tcpdf.php on line 1905`, satu baris per render (tanda terima, LKH). `composer.json` `^6.6`
terpasang 6.11.4. PDF tetap valid. Usulan: catat di ADR-033/daftar cutover, atau kunci versi tanpa notice; jangan sampai
log produksi penuh.

**B-F3 (Info) — pegawai tanpa pmj.** Bila satu-satunya riwayat jabatan Disetujui diubah role 1 ke Menunggu/Ditolak,
snapshot `pegawai_mutasi_jabatan` dihapus (benar). Akibatnya pegawai itu di luar lingkup role 3 di semua jalur, jadi
proses hanya bisa oleh role 1 (`out-t2.txt` percobaan pertama: role 3 → 403). Ini konsekuensi deviasi [V2] fail-closed yang
terdokumentasi (README Scope, CR-045). Tidak perlu tindakan, cukup diketahui admin.

**B-F4 (Info) — id jenis Konket.** `KonketService::jenisTerlarang()` (`:792-795`) menolak `id_jenis_konket` 2/4/5/6 untuk
pegawai/role 3, sedangkan input memakai `old_id` (kategori). Dengan seeder uji (id 2 = Dinas old 8, id 4 = Sakit old 10,
pasangan fiktif menurut docblock seeder), pegawai tidak bisa mengajukan Dinas/Sakit. QA memakai jenis uji tambahan (id 7/9)
untuk menguji alur. Usulan: verifikasi pasangan id ↔ old_id saat impor produksi (cutover) sebelum go-live.

## Tidak diuji / di luar cakupan bagian B
- QA Lapis 1 (grid, badge warna, modal alasan) → bagian D. Butir "Lolos MTC-0xx" → fase Manual Testing.
- Endpoint lampiran mandiri (`pegawai/{nip}/lampiran*`, B-18) hanya diuji sebatas lampiran di request riwayat dan
  daftar lampiran; uji penuh B-18 milik bagian lain.
- Tanda terima Kariskarsu (alur sama dengan Karpeg, hanya Karpeg yang diuji sampai PDF).
- PHPUnit ber-DB (`tests/Kepegawaian`) tidak dijalankan di sini (B-21, bagian lain); hanya unit murni Kalkulasi.

## Bersih-bersih
Server `php spark serve` 8112 dihentikan; DB `simpeg_v2_qa3b` di-DROP (nama persis); `.env` worktree dihapus; worktree
`scratchpad\wt\qa3b` dilepas (`git worktree remove --force`). Harness dan keluaran mentah tetap di `scratchpad\qa3b\`
(NIP sintetis; `lib.php` memakai password seed lokal yang sama dengan `AuthSeeder`, bukan kredensial nyata).

## QA Fase 3 — Bagian C: B-19 Struktur Organisasi + Plt/Plh, B-21 unit test, regresi Fase 1–2, exit criteria

| | |
|---|---|
| **Jenis** | QA Functional (black-box API + DB + test suite), atas izin reviewer CR |
| **Tanggal eksekusi** | 15 Oktober 2026 |
| **Commit yang diuji** | `576ee8f` (`main`), worktree detached `scratchpad\wt\qa3c` |
| **Lingkungan** | PHP 8.4.2, MySQL 8.0.30 instance 3307; `php spark serve` port 8113 (tanpa Vite); DB API `simpeg_v2_qa3c` (migrate --all + `MasterDataSeeder` (memanggil `AuthSeeder`) + data sintetis `scratchpad\qa3c-data.sql`: unit 6/7/16/19, satker 20/37/38/49/50/60/61/99/104, web_config, 22 jabatan + struktur_jabatan periode aktif 2, 15 pegawai fiktif, riwayat jabatan/KP, Plt/Plh); DB PHPUnit `simpeg_v2_qa3c_t`; captcha `mock`; akun seed per role (password seed lokal) |
| **Rujukan** | `03-Kepegawaian.md` B-19, B-21, exit criteria; checklist Trello QASMTASK-061/063 (`qa3-dod-trello.md`); README `Controllers/Api/Kepegawaian` (B-19/Plt-Plh, keputusan user 13-10-2026); legacy `L_jabatan.php` so_012025 (:6402), `M_simpeg.php` getLUS_so_012025[_lst] (:94/:125), `function_helper.php` getPLT (:1946) |
| **Hasil** | **QASMTASK-061 PASS dengan catatan** (semua butir fungsional lolos, dinilai sesuai keputusan user Q6 templat legacy; MTC-025 N/A fase MT; 1 temuan Trivial + 3 Info). **QASMTASK-063 PASS**: PHPUnit penuh **1801 test / 32.980 assertion / 0 gagal / 0 error / 0 skip** (12:17); frontend `npm run check` lolos (ESLint 0 warning, vue-tsc, Vitest **63 file / 764 test**, build). Regresi Fase 1–2 via API: tidak ada yang rusak. |

## QASMTASK-061 — B-19 Struktur Organisasi (+ B-07 bagian Plt/Plh)

### Checklist Trello

| Butir | Hasil | Bukti |
|---|---|---|
| Tree unit → satker → jabatan → pegawai | PASS sesuai keputusan user Q6 (13-10-2026, templat legacy so_012025) | `GET struktur-organisasi` per pilihan: `0` → `kementerian` (menteri, wamen, staf_ahli, inspektorat=jabatan 2060, sekretaris.list_es2, deputi[].list_es2); `1` (= web_config `id_unit_kepegawaian`) → `sesmen` (es1 → es2 Kepala Biro → es3 → es4; Kepala Pusat Data satker 49 dan Es.IV satker 49 dibuang); `2` → `deputi` (es1, es2_sek "Sekretariat Deputi", es2 → es3 → es4, awalan "Kepala " dibuang); `16` → `inspektorat_unit`; `sat_104` → `inspektorat`; `sat_37` → `bpo` (SPI jadi `es3_sek`); `sat_60`/`sat_61` → `upt`. Setiap kotak = jabatan + teks pejabat "Nama bergelar, Gol, NIP" |
| Expand/collapse node | PASS sesuai keputusan user Q6 (bagan statis legacy, bukan tree generik) | — (UI dinilai bagian D) |
| Search jump ke node | PASS sesuai keputusan user Q6 (pengganti: pilihan unit/satker + bulan, legacy) | `pilihan` = unit aktif urut `order` + satker hanya untuk unit 19 (satker 104 saja), unit 6 (semua satkernya), unit 7 (37/38/41 saja; satker 99 unit 7 tidak ditawarkan) — sama dengan `getLUS_so_012025_lst`; unit 6 `disabled:true` |
| Lolos MTC-025 | N/A (fase MT) | — |

### DoD dokumen + cakupan tugas

| Uji | Hasil | Bukti |
|---|---|---|
| Format per pilihan `id_unit_satker` | PASS | lihat baris tree di atas; `''` → kementerian, `' 1 '` → sesmen (di-trim) |
| Unit 6 → 422 | PASS | `?id_unit_satker=6` → 422 `errors.id_unit_satker` "Data struktur organisasi Unit / Satker yang dipilih tidak tersedia."; juga 422 untuk `sat_99` (satker unit 7 di luar 37/38/41), `999`, `abc`, `01`, `id_unit_satker[]=0`, `sat_49`/`sat_50` (lihat C-F3) |
| Definitif → Plt aktif → Plh [V2] → lowong | PASS | Kabiro SDM: definitif Bunga **menang atas** Plt Candra yang juga aktif; Kabiro Umum: tanpa definitif → "Plt. Dewi …" (Plh Eko pada jabatan yang sama kalah); Deputi: Plt Gita `status=2` diabaikan [V2] → "Plh. Fajar …" (`jenis:"plh"`, `is_plt:true`); Direktur Uji: Plt Lina `tgl_akhir` 2026-10-14 (kemarin) → lowong (`pejabat:""`, PDF "Lowong"); setelah Plt baru via API → "Plt. Bunga …", lalu Plt itu di-PUT `status=2` → jatuh ke Plh id 3 "Plh. Bunga …" |
| Aturan pejabat definitif ikut legacy | PASS | riwayat `status=0` (Candra jab 113) dan `jenis_jabatan=2` (jab 114) tidak dihitung; pegawai `id_jenis_status=2` (Tugas Belajar, jab 118) tidak dihitung; pegawai `status=2` `tmt_status` 2026-09-15 tampil di bulan 2026-08, lowong di 2026-09/2026-10; per NIP baris TMT terbaru (Indah pindah ke pelaksana 2026-10-01 → Kabag Mutasi Oktober = Hadi TMT 2026-10-05, September = Indah); NIP diawali `12345678910` → nama saja ("Akun Dummy Legacy"); golongan = KP disetujui TMT ≤ tanggal (Bunga IV/b, bukan IV/c TMT 2026-11-01) |
| Parameter `bulan` | PASS (lihat C-F1) | `2025-01` → 200 `tanggal 2025-01-31`; `2024-12`, `2026-11` (bulan depan), `2026-13`, `Okt`, `2025-1` → 422 `errors.bulan`; kosong → bulan berjalan `tanggal 2026-10-15` (hari ini); `2026-09` → `tanggal 2026-09-30` dan Kasubbag Mutasi TMT 2026-09-30 ikut terhitung (akhir bulan inklusif), `2026-08` → lowong; Plh Fajar (1–31 Okt) tidak tampil di 2026-09; `bulan_tersedia` 2026-10 … 2025-01 |
| Periode struktur aktif saja [V2] | PASS | `periode` = id 2 (id terbesar berstatus 1); jabatan 121 yang hanya ada di struktur periode 1 tidak muncul |
| PDF tabel | PASS (lihat C-F4) | `GET struktur-organisasi/pdf?id_unit_satker=0&bulan=2026-10` → 200 `application/pdf`, `attachment; filename="struktur_organisasi_0_2026-10.pdf"`, `no-store`, `nosniff`, isi `%PDF-1.7`, A4 landscape 1 halaman; tabel Jabatan \| Pejabat berindentasi sesuai format, "Lowong" miring, judul + "Kondisi 15 Oktober 2026 · Periode struktur 2024" (screenshot `scratchpad\qa3c\so.png`); `inline=1` → `Content-Disposition: inline`; `sat_37` → baris penutup "Jabatan Fungsional dan Jabatan Pelaksana"; pilihan/bulan tidak sah → 422 JSON |
| Hak lihat semua role login | PASS | `struktur-organisasi` dan `/pdf` role 1–8 → 200; tanpa token → 401 |
| Plt/Plh lihat 1/3/4/5, lingkup role 3 | PASS | `GET penugasan/plt` & `/plh`: role 1/3/4/5 → 200, role 2/6/7/8 → 403, tanpa token 401; `can_create` true hanya role 1; role 3 (akun satker 1) hanya melihat id 5 (satker 1), `GET penugasan/plt/6` (satker 2) → 404; `search=Bunga` → 2, `status=2` → 1; jenis `xyz` → 404, id 999 → 404 |
| Plt/Plh CRUD role 1 | PASS | POST JSON role 1 → 201 (`updated_by` 1, nama rujukan terisi); role 2/3/4/5 → 403; PUT role 1 → 200, role 3/4 → 403, id 999 → 404; validasi 422 per kolom: body kosong (11 kolom), `tgl_akhir < tgl_mulai`, `2026-02-30`, NIP tidak ada, `id_jabatan` 99999, `status=3`, `keterangan` 256, `id_unit` array, jabatan koordinasi beda jenis; jenis jabatan 2 (koordinasi) → 201, kolom group/sub group/jabatan NULL |
| Hapus permanen | PASS | DELETE role 3/4/5/2 → 403; role 1 → 200 `{deleted:true, soft_delete:false, id:8}`, baris hilang dari `pegawai_plt`, `audit_logs` event `delete` dengan `before_json` (nip_plt, lampiran), berkas lampiran terhapus dari storage; DELETE ulang → 404; DELETE plh → 200 |
| Lampiran unduh | PASS | multipart PDF → 201 `ada_lampiran:true`; `.txt` dan berkas biner bernama `.pdf` → 422 "Jenis berkas tidak diizinkan …"; 4,87 MB → 201, 5,16 MB → 422 "Ukuran berkas melebihi 5 MB."; unduh role 1/3/4/5 → 200 (isi identik byte-per-byte, `attachment; filename="lampiran_plt_8.pdf"`, `nosniff`, `no-store`), role 2/6 → 403, role 3 di luar lingkup → 404, tanpa berkas → 404, path arsip legacy `arsip/…` → 404; PUT multipart (`_method=PUT`) mengganti berkas, berkas lama terhapus sesudah commit (audit `update` before/after lampiran) |
| Audit penugasan | PASS | `audit_logs` `pegawai_plt`/`pegawai_plh` `create`/`update`/`delete` ber-actor id_pengguna 1 |

## QASMTASK-063 — B-21 Unit Test

Run: `php -d memory_limit=2G vendor\bin\phpunit --no-coverage` di worktree (.env uji → `simpeg_v2_qa3c_t` 3307), detached, log `scratchpad\qa3c-phpunit.log` + JUnit `scratchpad\qa3c-junit.xml`. Hasil: **OK (1801 tests, 32980 assertions)**, 0 failure/error/skip/risky, 12:17, stderr kosong. Test Kepegawaian: 73 berkas / 759 test.

| Butir checklist | Hasil | Test yang menutupnya |
|---|---|---|
| Kalkulasi periode KP (kelipatan Apr/Okt) | PASS | `tests/unit/Kepegawaian/Kalkulasi/KenaikanPangkatTest.php` (42 test: `testIsTanggalPeriode`, `testValidasiTmtBukanPeriodeDitolak`, `testValidasiTmtTanpaKewajibanPeriodeUntukPengangkatan`, `testProyeksiRegulerSetaraLegacy`, …); integrasi `tests/Kepegawaian/Riwayat/KpTest.php::testTmtKpWajibPeriodeAprilOktober` (19 test) |
| Jarak KGB 2 tahun | PASS | `tests/unit/Kepegawaian/Kalkulasi/KenaikanGajiBerkalaTest.php` (28 test: `testTepatDuaTahunDariKgbValid`, `testKurangDariDuaTahunDitolak`, `testKpLebihBaruMenjadiAcuan`, `testBatasDari29FebruariDijepit`, …); `Riwayat/KgbTest.php::testJarakMinimalDuaTahunDariKpAtauKgbTerakhir` (19 test) |
| Tanggal berakhir hukdis | PASS | `tests/unit/Kepegawaian/Kalkulasi/HukumanDisiplinTest.php` (20 test: `testMasaSkMenangAtasMasaBawaan`, `testTanggalPinggir`, …); `Riwayat/HukdisTest.php::testTanggalBerakhirDihitungOtomatisDariMasaSanksi`, `testUbahMengosongkanAkhirMenghitungUlang` (17 test) |
| Cascade koreksi NIP + rollback | PASS | `tests/Kepegawaian/NipCascadeTest.php` (9 test / 318 assertion): `testCascadeSeluruhTabelRegistryDalamSatuTransaksi` (assert ≥ 20 tabel), `testRollbackPenuhBilaSatuTabelGagal` (entri registry rusak di akhir → semua tabel kembali ke NIP lama, tanpa audit), `testRegistryCocokDenganSkema`, `testRole1Saja`; unit `tests/unit/Kepegawaian/Nip/TabelAnakNipTest.php` |
| Snapshot sync hanya final approval | PASS | `tests/Kepegawaian/Riwayat/SnapshotApprovalFinalTest.php` (`testPendidikanSnapshotHanyaSaatDisetujui`, `testAlamatDuaTargetHanyaSaatDisetujui`, `testKpInputAdminDanUbahStatusRole1`), `SnapshotSyncTest.php` (6 test termasuk `testAuditManualCreateUpdateDeleteDenganPelaku`, `testDiLuarTransaksiPemanggilTetapAtomik`), `HukdisTest::testBtcTolakStatus2SnapshotTidakTersentuh` |
| Terintegrasi check.sh | PASS | `check.sh` → `composer check` → `@test` = `phpunit --no-coverage` atas `./tests` (seluruh test di atas ikut); CI `.github/workflows/quality-gate.yml` (CR-044) menjalankan PHPUnit per shard di MySQL 8.0 + MariaDB 10.4 |

Catatan: DoD menyebut `tests/Kepegawaian/`; test kalkulasi murni ada di `tests/unit/Kepegawaian/` — tetap dalam suite yang sama (bukan temuan).

**Frontend** `cd frontend && npm ci && npm run check` (log `scratchpad\qa3c-npmcheck.log`): `eslint . --max-warnings=0` lolos, `vue-tsc -b` lolos, Vitest **63 file / 764 test passed** (67 s), `vite build` lolos (3,08 s), exit 0. `npm ci`: 369 paket, satu peringatan deprecate `lucide-vue-next@1.0.0` (Info, bukan gate).

## Regresi Fase 1–2 (API)

| Uji | Hasil | Bukti |
|---|---|---|
| Login role 1–8 | PASS | 8 × 200 (token); password salah → 401; captcha kosong → 422; akun nonaktif → 401; `auth/me` role 2/3 → 200 |
| Ganti password | PASS | password lemah → 422 tiga pesan kebijakan; password lama salah → 422; sah → 200 `{changed:true, sessions_revoked:true}`; login password lama → 401, baru → 200 |
| Manajemen akun + MAKE-015 | PASS | daftar role 1 → 200 (14), role 3 → 200 (lingkup satker), role 2/4 → 403; buat akun NIP tidak terdaftar → 422 `nip` "NIP tidak terdaftar sebagai pegawai."; NIP `abc` → 422; role 2 tanpa NIP → 422; NIP terdaftar → 201; NIP kembar → 422 "NIP sudah memiliki akun."; akun non-pegawai role 4 tanpa NIP → 201; tautkan NIP tidak terdaftar via PUT → 422, terdaftar → 200; role 3 buat akun satker lain → 403, satker sendiri → 201; PATCH status → 200 |
| Master options untuk form riwayat | PASS | `sub-group-jabatan/options?parent=1` (role 2) → item membawa `extra.need_satker` ("1"); `satker/options?parent=7` → 37/38/99; `jabatan/options?id_sub_group_jabatan=2&id_satker=20` → hanya 4 jabatan satker 20, urut nama; `id_sub_group_jabatan=3` (JF tanpa satker) → jabatan JF; options tanpa token → 401 |
| Master CRUD & hari libur RBAC | PASS | `master/jabatan` role 2 → 403, role 1 → 200; `POST master/agama` role 1 → 201; `hari-libur` role 4 → 200, role 2 → 403 |

## Temuan

| ID | Severity | Ringkasan |
|---|---|---|
| C-F1 | Trivial | `bulan[]=…` (array) diam-diam dianggap bulan berjalan, tidak 422 |
| C-F2 | Info | Pegawai yang `created_at`-nya hari ini tidak dihitung sebagai pejabat pada bulan berjalan ([K]) |
| C-F3 | Info | Format `pusat` (satker 49/50) tidak bisa dicapai lewat pilihan ([K]) |
| C-F4 | Info | PDF: label satker di bawah jabatan tidak ikut terindentasi |

**C-F1 — `bulan` array diterima diam-diam (Trivial).** Langkah: `GET struktur-organisasi?bulan[]=2026-09` (role 1). Hasil: 200, `bulan:"2026-10"` (bulan berjalan). Harapan: 422 `errors.bulan`, konsisten dengan `id_unit_satker[]=0` → 422 dan pola ISSUE-023/CR-019 (isian array ditolak). Penyebab: `StrukturService::bulanDanTanggal()` (`app/Libraries/Kepegawaian/Struktur/StrukturService.php:555`) memperlakukan non-string sama dengan kosong. Opsi: tolak `! is_string($bulan) && $bulan !== null` dengan `ValidationException::forField('bulan', …)`. Dampak: tidak ada (hanya baca); bukan keputusan tercatat.

**C-F2 — pegawai dibuat hari ini tidak dihitung (Info, [K]).** Pegawai sintetis dengan `created_at` = hari ini dan riwayat jabatan disetujui (Direktur Politeknik Uji) → lowong di bulan berjalan karena syarat `p.created_at < tanggal` (`StrukturService.php:470`, tanggal = hari ini tanpa jam). Ikut legacy `peg.created_at<'{$maxRwyDate}'` (`L_jabatan.php` so_012025); dicatat agar tidak dilaporkan sebagai bug saat uji manual.

**C-F3 — format `pusat` tidak terjangkau (Info, [K]).** `sat_49`/`sat_50` → 422 karena pilihan hanya menawarkan satker unit 19/6/7, sama dengan `getLUS_so_012025_lst` (`M_simpeg.php:125`). Cabang `pusat` hanya terpakai bila satker 49/50 berada di unit 6 pada data produksi; kode komentar sudah mencatat "Format pusat tidak punya view di legacy 2025".

**C-F4 — indentasi label satker di PDF (Info).** Pada PDF tabel, sub-label satker (font 7 pt) di bawah nama jabatan rata kiri, tidak mengikuti indentasi jabatannya (`StrukturService.php:596-597`, `padding-left` pada `<td>` tidak dihormati TCPDF; indentasi jabatan datang dari `&nbsp;`). Kosmetik, isi benar (screenshot `scratchpad\qa3c\so.png`).

Tidak ada temuan Blocker/Major/Medium/Minor.

## Exit criteria Fase 3

| Kriteria | Status | Dasar |
|---|---|---|
| Seluruh task DONE | **Sebagian (bergantung bagian lain)** | Dari bagian C: B-19 (061) PASS dengan catatan, B-21 (063) PASS. B-01..B-18, B-20 dinilai bagian A/B/D |
| B-TC lolos di semua riwayat | **Bergantung bagian A/B (fungsional) dan D (QA Lapis 1)** | Bukti otomatis pendukung dari bagian C: test B-TC per riwayat hijau (`BtcUjiKpTest`, `BtcUjiPendidikanTest`, `SeminarBtcTest`, `OrganisasiBtcTest`, `HukdisTest::testBtc*`, `SnapshotApprovalFinalTest`, dll. dalam run 1801/0 gagal) |
| B-21 unit test pass | **TERPENUHI** | PHPUnit penuh 1801 test / 32.980 assertion / 0 gagal; 5 butir B-21 dipetakan ke test di atas; terintegrasi `check.sh` + CI |
| Horii sign-off | **Belum** | menunggu laporan gabungan QA Fase 3 |
| (pendukung) Quality gate frontend | TERPENUHI | ESLint/vue-tsc/Vitest 764/build lolos |
| (pendukung) Regresi Fase 1–2 | TERPENUHI | tabel regresi di atas |

## Bersih-bersih

Server `spark serve` 8113 dihentikan; DB `simpeg_v2_qa3c` dan `simpeg_v2_qa3c_t` di-DROP; `backend/.env` worktree dihapus; berkas unggahan uji di `writable/uploads` worktree dihapus. Tidak ada perubahan kode, commit, atau push.
