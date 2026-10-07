# Matriks Role × Endpoint — Modul B (Kepegawaian Core)

| | |
|---|---|
| Sumber | `Matriks_Role_x_Endpoint_SIMPEG_v2.docx` versi 1.0, status "Final — hasil review & keputusan Horii", berkas terakhir diubah 08-09-2026 (dokumen proyek di luar repo, folder Bahan Baku SIMPEG). Disalin ke repo 07-10-2026 |
| Cakupan salinan | Bagian 1 (definisi role), Bagian 2 Modul B (tabel endpoint), Bagian 3 butir 2–3 (konstanta role & pola akses). Modul lain tidak disalin |
| Keputusan 07-10-2026 | Hak akses Fase 3 **ikut Matriks v2** (tabel di bawah), **kecuali approver LKH = atasan langsung** (ikut legacy) |
| Dipakai oleh | `docs/fase3/SPRINT0_1_backend.md`, `docs/fase3/SPRINT0_2_frontend.md`, `docs/fase3/WS1.md`, `docs/fase3/WS2.md`, kontrak task `docs/fase3/03-Kepegawaian.md` |

Isi tabel disalin apa adanya dari dokumen sumber. Bila sumber berubah, perbarui berkas ini dan catat tanggalnya di atas.

## 1. Legenda role

Role v2 tetap 8, dipetakan 1:1 dari `UserLevel` legacy. Konstanta di `backend/app/Constants/Role.php` mengikuti penomoran ini.

| Kode | Konstanta | Nama Role | Deskripsi (dari sumber) |
|---|---|---|---|
| 1 | `SUPER_ADMIN` | Super Admin | Akses penuh seluruh modul, termasuk Master Data, User Management, dan approval final. |
| 2 | `PEGAWAI` | Pegawai / PNS | Pegawai berstatus PNS — self-service (ajukan riwayat, cuti, dsb), lihat data pribadi. |
| 3 | `ADMIN_SATKER` | Admin Satker | Admin scoped per unit/satker — approval tingkat pertama, kelola data pegawai di satker sendiri. |
| 4 | `ADMIN_VIEW_ESELON1` | Admin View / Eselon 1 | Akses view-only lintas satker, level Eselon 1 — laporan & statistik, tidak approve/edit. |
| 5 | `MENTERI` | Menteri | Pimpinan tertinggi — akses view/laporan strategis lintas kementerian. |
| 6 | `PTT` | PTT | Pegawai Tidak Tetap — self-service setara Pegawai, dengan pembatasan modul tertentu (mis. tidak eligible Angka Kredit). |
| 7 | `PPPK` | PPPK | Pegawai Pemerintah dengan Perjanjian Kerja — self-service setara Pegawai, dengan pembatasan modul tertentu. |
| 8 | `PIMPINAN` | Pimpinan | Pimpinan/pejabat struktural lain (non-Menteri) dengan akses view/laporan setara level 4-5. |

Tipe akses non-`UserLevel` yang muncul di Modul B:

- `UL_ALL` — siapa saja yang sudah login (semua 8 role).
- `Session Logged In` — sama seperti `UL_ALL`, dipakai di beberapa endpoint AJAX/service internal.

## 2. Tabel endpoint Modul B (salinan apa adanya)

Pengantar dari sumber: modul dengan endpoint terbanyak — 20+ sub-modul riwayat, mayoritas pola "Admin CRUD (1,3) + Pegawai View/Submit (2,4,5 atau 6,7)".

Endpoint ditulis dalam bentuk rute legacy (CI3). Endpoint v2 (`api/v1/...`) ditetapkan di kontrak API `backend/app/Controllers/Api/Kepegawaian/README.md`.

| Method | Endpoint / Fungsi | Role Akses | Keterangan |
|---|---|---|---|
| GET | `hr/employee/index` | 1, 3, 4, 5, 8 | Tabel master seluruh pegawai, filter unit/satker |
| GET | `hr/employee/list_spe` | 1 | Daftar pegawai per snapshot tanggal (backdate) |
| GET/POST | `hr/employee/add` | 1 | Tambah pegawai baru |
| GET/POST | `hr/employee/edit/{nip?}` | 1, 2, 3, 6, 7 | Update biodata; pegawai biasa masuk pegawai_hist |
| GET/POST | `hr/employee/edit_nip` | 1 | Koreksi NIP, cascade ke seluruh riwayat |
| GET | `hr/employee/detail/{nip}` | UL_ALL | Detail profil lengkap + tabulasi riwayat |
| GET/POST | `hr/employee/process/{id_hist}` | 1, 3 | Verifikasi (setuju/tolak) draft perubahan biodata |
| GET | `hr/employee/delete/{nip}` | 1 | Soft/hard delete pegawai & akun terkait |
| GET | `hr/employee/print_drh/{nip}` | 1, 2, 3, 4, 5 | Export/cetak DRH standar BKN |
| GET | `hr/employee/advsearch` | 1, 3, 4, 5 | Pencarian multi-parameter |
| GET | `hr/so/full` | Session Logged In | Bagan interaktif struktur organisasi satker |
| GET/POST | `hr/rwy/kp/*` | 1, 3 (Admin), 2, 4, 5 (View) | Riwayat kenaikan pangkat/golongan PNS |
| GET/POST | `hr/rwy/kgb/*` | 1, 3 (Admin), 2, 4, 5 (View) | SK Kenaikan Gaji Berkala |
| GET/POST | `hr/rwy/jabatan/*` | 1, 3 (Admin), 2, 4, 5 (View) | Mutasi jabatan struktural/JFT/pelaksana |
| GET | `hr/rwy/jabatan/so` | UL_ALL | Pohon hierarki struktur organisasi |
| GET/POST | `hr/rwy/skp/*` | 1, 2, 3, 7 | Input & rekap penilaian SKP |
| GET/POST | `hr/rwy/lkh/*` | 1, 2, 3, 6, 7 | Aktivitas harian, verifikasi atasan, PDF LKH |
| GET/POST | `hr/rwy/konket/*` | 1, 2, 3, 6, 7 | Kondisi kerja (Dinas Luar/WFH/WFO) |
| GET/POST | `hr/rwy/hukdis/*` | 1, 3 | Sanksi hukuman disiplin |
| GET/POST | `hr/rwy/pendidikan/*` | 1, 2, 3, 6, 7 | Riwayat pendidikan formal |
| GET/POST | `hr/rwy/diklat/*` | 1, 2, 3, 7 | Riwayat diklat struktural/teknis/fungsional |
| GET/POST | `hr/rwy/seminar/*` | 1, 2, 3, 7 | Riwayat kursus/workshop/sertifikasi |
| GET/POST | `hr/rwy/ak/*` | 1, 2, 3, 4, 7 | Penetapan Angka Kredit |
| GET/POST | `hr/rwy/keluarga/*` | 1, 2, 3, 7 | Data pasangan, orang tua, anak |
| GET/POST | `hr/rwy/alamat/*` | 1, 2, 3, 6, 7 | Histori alamat domisili & KTP |
| GET/POST | `hr/rwy/karpeg/*` | 1, 2, 4, 5, 7 | Usulan penerbitan Kartu Pegawai |
| GET/POST | `hr/rwy/kariskarsu/*` | 1, 2, 4, 5, 7 | Usulan penerbitan Karis/Karsu |
| GET/POST | `hr/rwy/tj/*` | 1, 3 (Admin), 2, 4, 5 (View) | Riwayat tanda kehormatan |

## 3. Tampilan per role (turunan mekanis dari Bagian 2)

Tabel ini hanya menyusun ulang kolom "Role Akses" di Bagian 2 menjadi satu kolom per role, tanpa tambahan isi. Bila berbeda, Bagian 2 yang berlaku.

Kode sel: **A** = Admin, **V** = View (hanya untuk baris yang di sumbernya ditandai "(Admin)"/"(View)"); **Y** = tercantum di "Role Akses" tanpa pembagian Admin/View; **-** = tidak tercantum. Baris `UL_ALL` dan `Session Logged In` diisi Y untuk semua role sesuai legenda.

| Endpoint | 1 SUPER_ADMIN | 2 PEGAWAI | 3 ADMIN_SATKER | 4 ADMIN_VIEW_ESELON1 | 5 MENTERI | 6 PTT | 7 PPPK | 8 PIMPINAN | Role Akses (sumber) |
|---|---|---|---|---|---|---|---|---|---|
| `hr/employee/index` | Y | - | Y | Y | Y | - | - | Y | 1, 3, 4, 5, 8 |
| `hr/employee/list_spe` | Y | - | - | - | - | - | - | - | 1 |
| `hr/employee/add` | Y | - | - | - | - | - | - | - | 1 |
| `hr/employee/edit/{nip?}` | Y | Y | Y | - | - | Y | Y | - | 1, 2, 3, 6, 7 |
| `hr/employee/edit_nip` | Y | - | - | - | - | - | - | - | 1 |
| `hr/employee/detail/{nip}` | Y | Y | Y | Y | Y | Y | Y | Y | UL_ALL |
| `hr/employee/process/{id_hist}` | Y | - | Y | - | - | - | - | - | 1, 3 |
| `hr/employee/delete/{nip}` | Y | - | - | - | - | - | - | - | 1 |
| `hr/employee/print_drh/{nip}` | Y | Y | Y | Y | Y | - | - | - | 1, 2, 3, 4, 5 |
| `hr/employee/advsearch` | Y | - | Y | Y | Y | - | - | - | 1, 3, 4, 5 |
| `hr/so/full` | Y | Y | Y | Y | Y | Y | Y | Y | Session Logged In |
| `hr/rwy/kp/*` | A | V | A | V | V | - | - | - | 1, 3 (Admin), 2, 4, 5 (View) |
| `hr/rwy/kgb/*` | A | V | A | V | V | - | - | - | 1, 3 (Admin), 2, 4, 5 (View) |
| `hr/rwy/jabatan/*` | A | V | A | V | V | - | - | - | 1, 3 (Admin), 2, 4, 5 (View) |
| `hr/rwy/jabatan/so` | Y | Y | Y | Y | Y | Y | Y | Y | UL_ALL |
| `hr/rwy/skp/*` | Y | Y | Y | - | - | - | Y | - | 1, 2, 3, 7 |
| `hr/rwy/lkh/*` | Y | Y | Y | - | - | Y | Y | - | 1, 2, 3, 6, 7 |
| `hr/rwy/konket/*` | Y | Y | Y | - | - | Y | Y | - | 1, 2, 3, 6, 7 |
| `hr/rwy/hukdis/*` | Y | - | Y | - | - | - | - | - | 1, 3 |
| `hr/rwy/pendidikan/*` | Y | Y | Y | - | - | Y | Y | - | 1, 2, 3, 6, 7 |
| `hr/rwy/diklat/*` | Y | Y | Y | - | - | - | Y | - | 1, 2, 3, 7 |
| `hr/rwy/seminar/*` | Y | Y | Y | - | - | - | Y | - | 1, 2, 3, 7 |
| `hr/rwy/ak/*` | Y | Y | Y | Y | - | - | Y | - | 1, 2, 3, 4, 7 |
| `hr/rwy/keluarga/*` | Y | Y | Y | - | - | - | Y | - | 1, 2, 3, 7 |
| `hr/rwy/alamat/*` | Y | Y | Y | - | - | Y | Y | - | 1, 2, 3, 6, 7 |
| `hr/rwy/karpeg/*` | Y | Y | - | Y | Y | - | Y | - | 1, 2, 4, 5, 7 |
| `hr/rwy/kariskarsu/*` | Y | Y | - | Y | Y | - | Y | - | 1, 2, 4, 5, 7 |
| `hr/rwy/tj/*` | A | V | A | V | V | - | - | - | 1, 3 (Admin), 2, 4, 5 (View) |

## 4. Keterangan per riwayat

Pembagian View/Admin per jenis riwayat, disalin dari Bagian 2:

| Riwayat | Admin | View | Tanpa pembagian Admin/View di sumber |
|---|---|---|---|
| KP (`hr/rwy/kp/*`) | 1, 3 | 2, 4, 5 | — |
| KGB (`hr/rwy/kgb/*`) | 1, 3 | 2, 4, 5 | — |
| Jabatan / Mutasi (`hr/rwy/jabatan/*`) | 1, 3 | 2, 4, 5 | — |
| Tanda Jasa (`hr/rwy/tj/*`) | 1, 3 | 2, 4, 5 | — |
| SKP (`hr/rwy/skp/*`) | — | — | 1, 2, 3, 7 |
| LKH (`hr/rwy/lkh/*`) | — | — | 1, 2, 3, 6, 7 |
| Konket (`hr/rwy/konket/*`) | — | — | 1, 2, 3, 6, 7 |
| Hukdis (`hr/rwy/hukdis/*`) | — | — | 1, 3 |
| Pendidikan (`hr/rwy/pendidikan/*`) | — | — | 1, 2, 3, 6, 7 |
| Diklat (`hr/rwy/diklat/*`) | — | — | 1, 2, 3, 7 |
| Seminar (`hr/rwy/seminar/*`) | — | — | 1, 2, 3, 7 |
| Angka Kredit (`hr/rwy/ak/*`) | — | — | 1, 2, 3, 4, 7 |
| Keluarga (`hr/rwy/keluarga/*`) | — | — | 1, 2, 3, 7 |
| Alamat (`hr/rwy/alamat/*`) | — | — | 1, 2, 3, 6, 7 |
| Karpeg (`hr/rwy/karpeg/*`) | — | — | 1, 2, 4, 5, 7 |
| Karis/Karsu (`hr/rwy/kariskarsu/*`) | — | — | 1, 2, 4, 5, 7 |
| Organisasi (`hr/rwy/organisasi/*`) | — | — | 1, 2, 3, 7 |

Pola akses berulang menurut sumber (Bagian 3 butir 3), yang relevan untuk Modul B:

- "Admin CRUD, Pegawai View" — pola 1,3 (Admin) + 2,4,5 (View) muncul di banyak riwayat (KP, KGB, Jabatan, Tanda Jasa).
- "Self-service submit" — pola 1,2,3,6,7 atau 1,2,3,7 muncul di modul yang pegawai bisa ajukan sendiri (Cuti, SKP, LKH, Pendidikan, dst.), dengan 6/7 kadang dikecualikan tergantung eligibility (mis. PTT tidak eligible Angka Kredit).
- "View-only leadership" — pola 1,3,4,5,8 muncul di endpoint laporan/statistik.

## 5. Catatan

Catatan berikut menandai sel yang tidak bisa dibaca tunggal dari sumber. Isi tabel di atas **tidak** diubah; penyelesaiannya diputus reviewer CR atau pemilik proyek, lalu dicatat di Definisi riwayat atau di PRD.

1. **Approver LKH.** Keterangan `hr/rwy/lkh/*` menyebut "verifikasi atasan". Keputusan 07-10-2026: approver LKH = atasan langsung (ikut legacy), bukan diturunkan dari daftar role di baris ini.
2. **Pembagian per aksi.** Kecuali empat riwayat berpola Admin/View (KP, KGB, Jabatan, Tanda Jasa), sumber hanya memberi satu daftar role per riwayat (`/*`). Sumber tidak merinci role mana yang boleh lihat, tambah, ubah, hapus, atau proses (setuju/tolak). Hal ini termasuk SKP, Pendidikan, Diklat, Seminar, Keluarga, Alamat, Organisasi, Konket, AK, Karpeg, dan Karis/Karsu.
3. **Role 4 di AK.** `hr/rwy/ak/*` mencantumkan role 4 (Admin View / Eselon 1), yang di legenda bersifat view-only. Sumber tidak menyebut apakah role 4 di baris ini hanya melihat.
4. **Karpeg & Karis/Karsu tanpa role 3.** Kedua baris tidak mencantumkan role 3 (Admin Satker), tetapi mencantumkan 4 dan 5. Sumber tidak menyebut siapa yang memproses usulan.
5. **Hukdis tanpa view pegawai.** `hr/rwy/hukdis/*` hanya role 1, 3. Sumber tidak menyebut apakah pegawai yang bersangkutan boleh melihat riwayatnya sendiri.
6. **Detail pegawai vs tab riwayat.** `hr/employee/detail/{nip}` = `UL_ALL` dengan keterangan "tabulasi riwayat", sedangkan sebagian riwayat tidak mencantumkan role 4, 5, 6, atau 8. Sumber tidak menyebut tab mana yang tampil untuk role tersebut di halaman detail.
7. **`hr/employee/delete/{nip}`.** Keterangan "Soft/hard delete" tidak memilih salah satu. Kontrak task B-05 (`docs/fase3/03-Kepegawaian.md`) menyebut soft delete.
8. **Konket.** Keterangan "Kondisi kerja (Dinas Luar/WFH/WFO)" berbeda dengan arti modul Konket di legacy. `docs/fase3/WS2.md` (§3.4.3) mencatat bahwa build mengikuti arti legacy.
9. **`hr/so/full` vs `hr/rwy/jabatan/so`.** Yang pertama ditulis `Session Logged In`, yang kedua `UL_ALL`. Menurut legenda keduanya setara (semua role yang sudah login).
