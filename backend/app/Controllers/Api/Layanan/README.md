# Modul C — Layanan & Approval (Fase 4)

Controller REST API modul ini (`App\Controllers\Api\Layanan\*`). Semua controller extends `App\Controllers\Api\ApiController`, route di-group `api/v1/...` dengan filter `jwt` + `role:*` (lihat `Config\Routes`).

Dokumentasi endpoint (method, path, payload, role akses) ditulis di sini per task — SOP Bagian 2 langkah 4.

## Cuti (MAKE-022 M3, PRD Fase 4 §5.1, §6.2–§6.4)

Prefix `api/v1/`, envelope ADR-001 (`{status, data}` / `{status: error, message, errors}`). Filter `jwt` di grup; role
route **1, 2, 3, 6, 7** kecuali `POST cuti/hitung-lama` (semua login). Role di luar route → **403**. Hak per baris (D5)
di `CutiService`: baris yang tidak boleh dilihat → **404**; terlihat tetapi tidak berhak → **403**. Rute didefinisikan di
`Config/RoutesLayanan.php`; kontrak role di `tests/feature/KontrakMatriksRoleTest.php`.

| Method & path | Role route | Isi |
|---|---|---|
| `GET cuti?mode=saya\|persetujuan\|admin&status=&id_jenis_cuti=&tahun=&search=&id_unit=&id_satker=&page=&per_page=` | 1,2,3,6,7 | `{items, total, page, per_page, mode, can_create}`. Bawaan: role 1/3 `admin`, lainnya `saya`. `mode=admin` untuk role 2/6/7 → 403 (role 3 dibatasi lingkup, role 1 semua; data `YEAR(tgl_mulai) >= tahun−2`). `persetujuan`: NIP pemanggil = AL (status 0, dua langkah) atau PyB (status 3 / status 0 langkah tunggal). Item admin memuat `perlu_atas_nama` |
| `GET cuti/opsi?nip=` | 1,2,3,6,7 | `{jenis_cuti[], atasan_langsung {nip,nama,jabatan,bisa_memproses}\|null, pyb[] (+bisa_memproses), langkah_tunggal, eselon_i, saldo, tgl_akhir_maks, ketua_tim[], can_create}`. `nip` hanya dibaca untuk role 1. Role 3/6: `can_create=false`. `bisa_memproses` hanya informasi (PRD §6.4.10) |
| `POST cuti/hitung-lama` | semua login | Body JSON `{id_jenis_cuti, tgl_mulai, tgl_akhir?, durasi?, nip?, id_riwayat_cuti?}` → `{lama_cuti, satuan_lama_cuti, tgl_akhir, hari_libur[{tanggal, keterangan}], kuota\|null}`. Tanpa efek tulis; galat → 422 per kolom. `nip` hanya role 1. `kuota` hanya untuk jenis 1 |
| `POST cuti` | 1,2,3,6,7 (3, 6 → 403) | Multipart/JSON → **201** item. Role 2/7: untuk diri sendiri, status dipaksa 0. Role 1: `nip` + `status` 0\|1 (1 = langsung disetujui dan memotong saldo). Field: `id_jenis_cuti`, `tgl_mulai`, `tgl_akhir` (jenis 2: `durasi` 1–3), `no_telp_saat_cuti`, `alamat_saat_cuti`, `keterangan` (≥ 10), `nip_atasan_langsung`, `nip_yang_menyetujui` (kecuali eselon I), `ketua_tim[]`, `file_bukti` (5 MB jpg/jpeg/png/gif/pdf). `lama_cuti` dan snapshot **tidak** diterima dari klien |
| `GET cuti/{id}` | 1,2,3,6,7 | Item + `ketua_tim_detail[]`, `lampiran[]`, `surat[]`, `riwayat_approval`. Boleh melihat: pemilik, AL, PyB, ketua tim, role 1, role 3 dalam lingkup; lainnya 404 |
| `PUT cuti/{id}` (multipart: `POST` + `_method=PUT`) | 1,2,3,6,7 | Pemilik (2/7): status 0 dan `tgl_mulai >= hari ini`; role 1: status 0. Status ≠ 0 → 422 `errors.status`; tanggal lewat → 422 `errors.tgl_mulai`; bukan pemilik → 403 |
| `DELETE cuti/{id}` | 1,2,3,6,7 | Hapus **fisik** (D6): pemilik status 0/2, role 1 semua status, role 3 → 403. Refund saldo jenis 1 status 1/4 (`KuotaCuti::kembalikan` dengan snapshot), CLTN berjalan dipulihkan, lampiran dihapus, audit `before`. `{deleted: true, soft_delete: false, id}` |
| `GET cuti/{id}/lampiran`, `GET cuti/{id}/surat` | 1,2,3,6,7 | Unduh (`attachment`, `nosniff`, `no-store`); surat hanya status 1/4 (ber-TTE bila ada, kalau tidak versi belum TTE). 404 bila tidak ada |

Kode galat 422 (`errors.<field>`): `id_jenis_cuti`, `tgl_mulai`, `tgl_akhir`, `durasi`, `no_telp_saat_cuti`, `alamat_saat_cuti`,
`keterangan`, `nip_atasan_langsung`, `nip_yang_menyetujui`, `ketua_tim`, `file_bukti`, `lama_cuti` (kuota), `status`. Semua
galat dikumpulkan dalam satu respons (A-09). Audit tidak memuat isi berkas.

### Persetujuan, tunda, pengingat, saldo, petugas approval (M3 bagian c)

| Method & path | Role route | Isi |
|---|---|---|
| `POST cuti/{id}/process` | 1,2,3,6,7 | Body JSON `{aksi: setujui\|tolak\|tangguhkan, reason_note?, atas_nama?}` → 200 item terbaru. Urutan: baris terlihat (404) → pemanggil AL/PyB baris itu atau role 1 dengan `atas_nama` (403) → body (422) → transaksi terkunci → tahap (422 `errors.status`) → kuota diulang saat final (422 `errors.lama_cuti`) → tulis → efek final (hanya status 1/4). `tolak` tanpa `reason_note` → 422; `tangguhkan` hanya jenis 1 (`errors.aksi`). `atas_nama`: bukan role 1 → 403; approver tahap aktif masih terjangkau → 422 `errors.status`; `reason_note` wajib untuk semua aksi |
| `POST cuti/{id}/tunda` | 1,2,3,6,7 | Approver tahap aktif; sebelum `tgl_mulai` 12:00 WIB (inklusif); set `skip_al`/`skip_pyb = 1`, status tidak berubah. Bukan approver → 403; bukan tahapnya atau lewat batas → 422 `errors.status` |
| `GET cuti/pengingat` | 1,2,3,6,7 | `{items, total}`: antrean `persetujuan` pemanggil yang flag tahapnya masih 0. Flag direset listener `auth.login` (hanya NIP yang login) |
| `GET cuti/saldo?tahun=&search=&id_unit=&id_satker=&page=&per_page=` | 1,3 | Daftar saldo per tahun (bawaan tahun ini; `tahun` > tahun ini → 422); role 3 dibatasi lingkup; role 4/5 → 403 |
| `POST cuti/saldo` | 1,3 | `{target: 1\|2, nip?, tahun, sisa_cuti, sisa_cuti_n1, sisa_cuti_n2, utang_cuti, sisa_cuti_ditangguhkan?}`; batas D8 per kolom (422); target 2 yang sudah punya baris → 422 `errors.nip` (tidak dilewati diam-diam). Respons **201** kedua target berbentuk sama (lihat contoh di bawah); target 1 = semua pegawai aktif jenis 1/6 dalam lingkup: yang sudah punya baris tahun itu **dilewati** (`alasan_kode = sudah_punya_saldo`), yang belum **dibuat** |
| `PUT cuti/saldo/{id}`, `DELETE cuti/saldo/{id}` | 1,3 | Ubah kolom saldo; hapus fisik + audit; di luar lingkup role 3 → 404 |
| `GET cuti/petugas-approval` | 1,3 | Aturan petugas `pa_layanan` (jenis 1) + nama/NIP/jabatan petugas (pemegang saat ini, Plt bila kosong); role 3 hanya `id_unit_pemohon` = unit pemanggil |
| `POST cuti/petugas-approval`, `PUT …/{id}`, `DELETE …/{id}` | 1,3 | Whitelist kolom; `petugas` = `struk_<id_jabatan>` \| `koord_<id_jabatan_koord>` \| `other_<nip>`; GJ pemohon 2/3 → `gol_ruang_pemohon` wajib, selain itu `id_sub_group_jabatan_pemohon`; ubah hanya baris status 1; role 3 tambah wajib `id_unit_pemohon` = unit sendiri (422), ubah/hapus unit lain → 403; hapus fisik + audit |
## Tugas Belajar — C-07 (MAKE-024 M5, PRD Fase 4 §5.2, §6.7)

Route: `Config/RoutesLayananStudi.php` ([V2] berkas route terpisah dari cuti `RoutesLayanan.php`, satu berkas per workstream —
disetujui reviewer CR 27-10-2026). Filter `jwt` + `role:1,2,3,6` (`TbService::ROLE_ROUTE`); **role 7 → 403** (DoD 070-4),
role 4/5/8 → 403. Aturan rinci di service (`Libraries/Layanan/Tb/*`). Urutan galat §7.10: 401 → 403 role → 404 baris tidak
terlihat → 403 bukan pelaku → 422 (`errors.status` untuk status/tanggal yang tidak mengizinkan).

| Method & path | Role | Isi |
|---|---|---|
| `GET tb?mode=saya\|pic\|admin&status=&search=&page=&per_page=` | 1,2,3,6 | `{items, total, page, per_page, mode, can_create, can_process}`. Bawaan role 1/3 `admin` (3 = lingkup `terapkanKeQuery`), lainnya `saya`. `pic` = semua status 0 tanpa batas unit (D14) bila PIC TB aktif, selain itu 403; `admin` untuk role 2/6 → 403 |
| `GET tb/opsi?nip=` | 1,2,3,6 | `{jenjang_pendidikan[] (status 1, id > 3), up, pegawai {nip + snapshot} \| null, slot_berkas {file_sp…file_3: label}, berkas_wajib, can_create}`; `nip` hanya role 1/3 (3: lingkup) |
| `POST tb` (multipart) | 1,2,3,6 | 201 + detail. Role 2/6 diri sendiri (isian `nip` diabaikan), status 0; role 3 `nip` dalam lingkup, status 0; role 1 `nip` PNS (`id_jenis_pegawai = 1`, 422 `errors.nip`), `status` 0\|1 (1 → data SK wajib + efek final, TB-07). Berkas slot `file_sp`, `file_1`, `file_2`, `file_3` wajib (role 1 bebas), 5 MB, `jpg jpeg png gif pdf doc docx` |
| `GET tb/{id}` | 1,2,3,6 | Pemilik, PIC TB aktif, role 1, role 3 lingkup; lainnya 404. Kolom DDL + `label_status`, `nada_status`, `tahap[]`, `aksi {ubah, hapus, proses, unduh_surat, perpanjangan}`, `lampiran[]` (ber-`tag` slot), `surat[]` (SK kode 47), `perpanjangan[]` (+`lampiran[]`), `status_penuh` |
| `PUT tb/{id}` (multipart `POST` + `_method=PUT`) | 1,2,3,6 | Pemilik: status 0 & `tgl_mulai > hari ini`; role 3: status 0 + lingkup; role 1: semua kecuali 2 (status 0 → 1 boleh; final tidak bisa dikembalikan). Berkas per slot mengganti lampiran slot itu; wajib bila slot belum berisi (lampiran v2 ber-`tag` atau kolom `file_*` impor) |
| `DELETE tb/{id}` | 1,2,3,6 | Pemilik & role 3 (lingkup): status 0; role 1: semua. Hapus fisik + audit + perpanjangan & semua berkas; TB disetujui dalam rentang → `pegawai.id_jenis_status` kembali 1 |
| `POST tb/{id}/process` | 1,2,3,6 | PIC TB aktif; status 0. `{aksi: setujui\|tolak, reason_note, no_sk, tgl_sk, ds_tb, no_ds_tb, tgl_ds_tb?, perihal_ds_tb?}` + berkas opsional `file_surat_tb` (PDF 5 MB → kode 47). Setujui: data SK wajib, efek final; tolak: `reason_note` wajib ≤ 255 byte. Selain status 0 → 422 `errors.status` |
| `POST tb/{id}/perpanjangan` | 1,2,3,6 | Role 1 saja (lain 403); induk status 1. `tgl_mulai_perpan` (bawaan akhir efektif + 1 hari), `tgl_akhir_perpan`, berkas `file_sperpan` wajib → kode 48. 201 + detail induk |
| `PUT tb/perpanjangan/{id}`, `DELETE tb/perpanjangan/{id}` | 1,2,3,6 | Role 1 saja; `PUT` dua tanggal saja [K]. Ringkasan `tgl_*_perpan` induk diperbarui di transaksi yang sama |
| `GET tb/{id}/lampiran/{id_attachment}` | 1,2,3,6 | Hak lihat detail; lampiran harus milik baris (kode 46/47 id itu, atau 48 milik perpanjangannya), selain itu 404. `attachment`, `nosniff`, `no-store` |
| `GET tb/{id}/surat` | 1,2,3,6 | Hak lihat detail; hanya status 1 (lain 404). SK unggahan PIC bila ada, kalau tidak PDF dirender dari `Views/pdf/surat_tb.php` (kop kementerian, penanda tangan Kabiro kepegawaian dari WebConfig) |

Contoh proses: `POST api/v1/tb/12/process` body `{"aksi": "tolak", "reason_note": "Berkas BAN PT belum ada"}` → 200 detail
(`status` 2, `label_status` "Ditolak"). Galat: `{"status": "error", "message": "Validasi gagal.", "errors": {"reason_note": ["Alasan ditolak tidak boleh kosong."]}}`.

## Izin Belajar — C-08 (MAKE-024 M5, PRD Fase 4 §5.3, §6.8)

Route: `Config/RoutesLayananStudi.php`, filter `jwt` + `role:1,2,3,7` (`IbService::ROLE_ROUTE`); **role 6 → 403** (DoD 071-3),
role 4/5/8 → 403. Pola sama dengan TB kecuali yang disebut di bawah.

| Method & path | Role | Isi |
|---|---|---|
| `GET ib?mode=saya\|pic\|admin&status=&search=&page=&per_page=` | 1,2,3,7 | Seperti TB; `pic` = PIC IB aktif (`petugas_layanan` jenis 4), tanpa batas unit |
| `GET ib/opsi?nip=` | 1,2,3,7 | Seperti TB; `slot_berkas` = `file_sp`, `file_1`, `file_2`; `berkas_wajib` selalu `true` |
| `POST ib` (multipart) | 1,2,3,7 | 201 + detail. Role 2/7 diri sendiri; role 1 & 3 `nip` (3 lingkup) — input admin berfungsi dengan berkas (IB-01). Status **selalu 0** (`status` ≠ 0 dari role 1 → 422 `errors.status`). Tiga slot wajib **semua role** termasuk role 1 [K] |
| `GET ib/{id}` | 1,2,3,7 | Pemilik, PIC IB aktif, role 1, role 3 lingkup; lainnya 404. Seperti TB tanpa `status_penuh`/`perpanjangan[]`; `surat[]` = Surat IB kode 50 |
| `PUT ib/{id}`, `DELETE ib/{id}` | 1,2,3,7 | Aturan sama TB (pemilik status 0 & `tgl_mulai > hari ini`; role 3 status 0 + lingkup; role 1 kecuali 2 / hapus semua), tanpa ubah status oleh admin. Hapus fisik + audit + berkas |
| `POST ib/{id}/process` | 1,2,3,7 | PIC IB aktif; status 0. `{aksi: setujui\|tolak, reason_note, no_sk, tgl_sk, ds_ib, no_ds_ib, tgl_ds_ib?, perihal_ds_ib?}`. Setujui → **Surat IB PDF dibuat otomatis** (`Views/pdf/surat_ib.php`, penanda tangan Kabiro) dan disimpan kode 50 dalam transaksi yang sama; tanpa efek pegawai/presensi/tukin |
| `GET ib/{id}/lampiran/{id_attachment}` | 1,2,3,7 | Hak lihat detail; lampiran kode 49/50 milik baris itu, selain itu 404 |
| `GET ib/{id}/surat` | 1,2,3,7 | Hak lihat detail; Surat IB tersimpan terbaru (hanya status 1), selain itu 404 |

## PMK — C-09 (MAKE-024 M5, PRD Fase 4 §5.4, §6.9)

Route: `Config/RoutesLayananStudi.php`, filter `jwt` + `role:1,2,3` (`DasarPmkService::ROLE_ROUTE`); **role 4–8 → 403**
(DoD 072-4). Role 3 lihat saja (tulis → 403). PMK hanya PNS (P-17).

| Method & path | Role | Isi |
|---|---|---|
| `GET pmk?nip=&page=&per_page=` | 1,2,3 | Riwayat PMK final (`riwayat_pmk`, impor/SIASN): role 2 diri sendiri, 3 lingkup, 1 semua; item + `arsip[]` (kode 42); `can_edit` |
| `PUT pmk/{id}` (multipart `arsip`) | 1,2,3 | Role 1 saja: tambah arsip SK (wajib, 2 MB, `jpg jpeg png gif pdf`) → daftar arsip |
| `DELETE pmk/{id}` | 1,2,3 | Role 1 saja: hapus fisik + audit + arsip |
| `DELETE pmk/{id}/arsip/{id_attachment}` | 1,2,3 | Role 1 saja; minimal satu arsip tersisa (422 `errors.arsip`); arsip bukan milik baris → 404 |
| `GET pmk/pengajuan?mode=saya\|pic\|admin&status=&search=` | 1,2,3 | `saya` (role 2), `pic` (PIC PMK aktif: status 0 dan 3, tanpa batas unit [K P-19]), `admin` (role 1 semua, role 3 lingkup lihat saja); bawaan role 1/3 `admin` |
| `GET pmk/opsi?nip=` | 1,2,3 | `{jenis_pmk[] (Swasta, Negeri), pegawai, tmt_cpns (pegawai_cpns.tmtsk), tgl_awal_maks (TMT − 1 hari), berkas {kolom: label}, can_create}` |
| `POST pmk/hitung-mker` | 1,2,3 | `{tgl_awal, tgl_akhir}` → `{mker_th, mker_bl, peringatan}` (diff y/m, hari diabaikan [K]) |
| `POST pmk/pengajuan` (multipart) | 1,2,3 | Role 2 PNS diri sendiri; role 1 `nip` PNS; role 3 → 403. `jenis_pmk`, `instansi`, `tgl_awal`, `tgl_akhir`, `mker_th`, `mker_bl` (dari klien [K P-4]) + berkas kolom `file_kontrak`, `file_paklaring`, `file_surat_phk`, `file_slip_gaji` wajib (2 MB, `jpg jpeg png gif pdf`). Status 0. 201 + detail |
| `GET pmk/pengajuan/{id}` | 1,2,3 | Pemilik, PIC PMK aktif, role 1, role 3 lingkup; lainnya 404 (status 0 boleh dibuka, Q-16). Kolom berkas = penanda `true/false` (path internal tidak dikirim), `label_status`, `nada_status`, `tahap[]`, `aksi {ubah, hapus, proses}`, `aksi_tersedia[]` |
| `PUT pmk/pengajuan/{id}` (multipart `POST` + `_method=PUT`) | 1,2,3 | Pemilik (role 2) / role 1, tanpa syarat status; status tidak berubah [K D19]. Berkas pengganti per kolom; wajib bila kolom kosong |
| `DELETE pmk/pengajuan/{id}` | 1,2,3 | Pemilik / role 1; status 1 → 422 `errors.status` [K]; hapus fisik + audit + berkas (P-9) |
| `POST pmk/pengajuan/{id}/process` | 1,2,3 | PIC PMK aktif; status 0/3. `{aksi: proses\|setujui\|tolak, reason_note}`: 0 → 3, 0/3 → 1, 0/3 → 2 (alasan wajib ≤ 255 byte); 1/2 → 422 `errors.status`. Tanpa salin ke `riwayat_pmk`, tanpa ubah masa kerja (D16) |

| `GET pmk/pengajuan/{id}/berkas/{kolom}` | 1,2,3 | **[V2] gap PRD §5.4** (disetujui Horii 27-10-2026; legacy membuka lewat tautan langsung `views/hr/employee/rwy/pmk/req/admin/form.php:142-214`, `pathToSrc()` `helpers/function_helper.php:314`). Hak = hak lihat detail (404 bila tak terlihat). `{kolom}` hanya `file_kontrak`, `file_paklaring`, `file_surat_phk`, `file_slip_gaji` (lain → 404). Berkas dari kolom lewat KolomBerkas di dalam akar storage; kolom kosong, path impor legacy yang belum ada di storage v2, atau path yang ditolak storage (traversal) → 404. Isi dialirkan dengan mime dari isi, nama `pmk_{id}_{kolom}.{ext}` (tanpa path internal), `attachment` (`inline=1` → inline), `nosniff`, `no-store` |

Contoh respons `POST cuti/saldo` (data sintetis), target 1, tahun 2026:

```json
{
  "status": "success",
  "data": {
    "message": "Saldo cuti tahun 2026 dibuat untuk 2 pegawai; 1 pegawai dilewati karena sudah memiliki saldo.",
    "ringkasan": {"tahun": 2026, "target": 1, "jumlah_populasi": 3, "jumlah_dibuat": 2, "jumlah_dilewati": 1},
    "dibuat": [{"nip": "199001010001010001", "nama": "Contoh Satu, S.T."}, {"nip": "199001010001010002", "nama": "Contoh Dua"}],
    "dilewati": [{"nip": "199001010001010003", "nama": "Contoh Tiga", "alasan_kode": "sudah_punya_saldo", "alasan": "Pegawai sudah memiliki saldo cuti tahun 2026."}]
  }
}
```

Semua sudah punya saldo: `jumlah_dibuat = 0`, `dibuat = []`, `message` "Tidak ada saldo cuti tahun 2026 yang dibuat; N pegawai dilewati karena sudah memiliki saldo." Audit ringkasan
(`jenis_audit = cuti.saldo_massal`) memuat angka yang sama dan daftar `nip_dibuat`/`nip_dilewati` tanpa nama.

## Dashboard Status Layanan & PIC — C-10 (MAKE-025 M6, PRD Fase 4 §5.5, §6.10)

Route: `Config/RoutesLayananDashboard.php`, filter `jwt` + `role:1,2,3,4,5,6,7` (`LayananDashboardService::ROLE_ROUTE`,
`PetugasLayananService::ROLE_ROUTE`); **role 8 → 403**. Tulis PIC role 1 saja (role 2–7 → 403 di service).

| Method & path | Role | Isi |
|---|---|---|
| `GET layanan/status?jenis=&status=&id_unit=&id_satker=&search=&page=&per_page=` | 1–7 | Gabungan `riwayat_cuti`, `riwayat_tb`, `riwayat_ib`, `riwayat_pmk_req`, urut `created_at DESC`. Lingkup `PegawaiScope::terapkanKeQuery` per jenis: role 1/4/5 semua, role 2/6/7 NIP sendiri, role 3 jalur daftar. Modul per role: 6 cuti + TB, 7 cuti + IB, lainnya keempatnya (`jenis` di luar modul role → daftar kosong). `jenis` `cuti\|tb\|ib\|pmk`; `status` `berjalan` (non-final) \| `selesai` (final) \| kode angka per jenis (wajib bersama `jenis`, tanpa `jenis` → 422 `errors.status`); `search` nama/NIP; `per_page` 1..100 (bawaan 20). Parameter tidak sah → 422 |
| `GET layanan/jenis` | 1–7 | Master `jenis_layanan` aktif `[{id_jenis_layanan, jenis_layanan, module_name}]` |
| `GET layanan/petugas?id_jenis_layanan=&status=&search=&page=&per_page=` | 1–7 | Role 1 semua status (filter `status` 0/1); role 2–7 hanya PIC aktif. Urut `status DESC, id DESC`. `can_create` (role 1) |
| `GET layanan/petugas/kandidat?search=` | **1** | **[V2] gap PRD §5.5** (disetujui Horii 27-10-2026; legacy memuat pilihan di form, `L_layanan.php` `form_off`). Kandidat PIC [K]: `pegawai.status = 1`, `id_jenis_pegawai = 1`, `pmj.id_satker` = `id_satker_kepegawaian` (WebConfigService), urut nama, maks 50 → `{items: [{nip, nama}], peringatan}`. `search` (nama/NIP, maks 100 karakter, lebih → 422) dicari di server. Satker kosong → `items: []` + `peringatan`. Role 2–8 → 403 |
| `POST layanan/petugas` | 1–7 | Role 1 saja. `{id_jenis_layanan, nip, status?}` (status bawaan 1). 201 + item |
| `PUT layanan/petugas/{id}` | 1–7 | Role 1 saja. Kolom yang tidak dikirim tetap; tidak ada → 404 |
| `DELETE layanan/petugas/{id}` | 1–7 | Role 1 saja. Hapus fisik + audit [K `L_layanan.php:166`] → `{deleted: true, soft_delete: false}`; tidak ada → 404 |

Item `GET layanan/status`:

```json
{
  "jenis": "tb", "jenis_layanan": "Tugas Belajar", "id": 12, "nip": "199001012015011001", "nama": "Contoh Pegawai, S.E.",
  "unit": "Unit Contoh", "satker": "Satker Contoh", "tanggal_pengajuan": "2026-10-21 01:00:00", "status": 0,
  "ringkasan": "Tugas Belajar S.1 - Universitas Contoh (Manajemen), 01-01-2027 s/d 31-12-2028",
  "label_status": "Menunggu PIC", "nada_status": "warning",
  "tahap": [
    {"kode": "diajukan", "label": "Diajukan", "state": "done", "nama_petugas": null, "waktu": "2026-10-21 01:00:00"},
    {"kode": "pic", "label": "Verifikasi PIC", "state": "current", "nama_petugas": null, "waktu": null},
    {"kode": "selesai", "label": "Selesai", "state": "todo", "nama_petugas": null, "waktu": null}
  ],
  "petugas": [{"peran": "PIC", "nip": "198001012005011001", "nama": "Contoh PIC"}], "catatan": null,
  "url_detail": "/layanan/tb/12", "aksi": {"lihat_detail": true}
}
```

- `tahap`, `label_status`, `nada_status` dari penyaji modul yang sama dengan halaman modul (`CutiPenyaji`, `TampilanStudi`,
  `TampilanPmk`); tahap cuti diberi `waktu` (Diajukan = `created_at`, Selesai = `updated_at` bila final).
- `petugas`: cuti = Atasan Langsung & Pejabat Berwenang dari snapshot (`nama_atasan_langsung`, `nama_yang_menyetujui`;
  langkah tunggal hanya satu); TB/IB/PMK = semua PIC aktif jenis 3/4/5. Tanpa PIC aktif → `[]` + `catatan` "PIC belum ditunjuk".
- `url_detail` = rute halaman FE (`/layanan/cuti/{id}`, `/layanan/tb/{id}`, `/layanan/ib/{id}`, `/layanan/pmk/pengajuan/{id}`);
  `null` dan `aksi.lihat_detail = false` bila pemanggil tidak punya route detail modul itu (role 4/5) atau, untuk role 3,
  pegawainya di luar jalur detail PegawaiScope. Tidak ada aksi tulis.

Item `layanan/petugas`: `{id_petugas_layanan, id_jenis_layanan, jenis_layanan, nip, nama, jabatan, satker, unit, status,
label_status, created_at, updated_at, aksi {ubah, aktifkan, nonaktifkan, hapus}}` (aksi hanya `true` untuk role 1).

Validasi tulis (422 per field): `id_jenis_layanan` wajib, ada dan **aktif** (perbaikan opsi nonaktif legacy; dicek saat
tambah atau saat diubah); `nip` kandidat = pegawai `status = 1`, PNS (`id_jenis_pegawai = 1`), `pmj.id_satker` = satker
kepegawaian (`WebConfigService::value('id_satker_kepegawaian')`; kosong → `errors.nip` "Satker kepegawaian belum diatur di
Web Config; PIC belum dapat ditunjuk.") [K `L_layanan.php:38-46`], dicek saat tambah atau saat NIP diubah (PIC lama yang
tidak lagi memenuhi syarat tetap bisa dinonaktifkan); `status` 0/1; duplikat `(id_jenis_layanan, nip)` → `errors.nip`
"Petugas sudah terdaftar untuk layanan ini." [K `L_layanan.php:192-242`].
