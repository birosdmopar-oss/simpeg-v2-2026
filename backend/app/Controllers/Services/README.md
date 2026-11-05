# `services/*` — endpoint kompatibilitas legacy antar-sistem (Fase 6 Jalur A)

Controller tipis untuk path legacy `services/<controller>/<method>` (di luar `api/v1`, PRD Fase 6 §3/§6.1): `s_stat` (A6
MAKE-061, H-08), `s_dm` (A4 MAKE-059), `s_esign` (B4b). Route di `Config/RoutesServices.php` (berkas bersama Jalur A), auth
per sub-group lewat filter `klienLayanan:<hak>` (A3, ADR-036): tanpa/salah Basic → **401**
`{"status":2,"msg":"Authentication failed","data":{}}` + `WWW-Authenticate: Basic realm="SIMPEG"`; throttle → 429. Amplop
bisnis INT `{"status":1|2,"msg":"…","data":…}` HTTP 200 lewat `Libraries/Integrasi/Layanan/ResponsLayanan` (bukan amplop
SIMAPI "01"/"02"). Logika di `Libraries/Integrasi/*`; controller tidak mengulang auth, tidak menulis DB.

## `s_stat` — hak `stat` (MuDig), `SStatController` (MAKE-061)

Port `controllers/services/S_stat.php` [K]; aturan di `Libraries/Integrasi/Stat/*` (lihat README modul). "Hari ini" = tanggal
bisnis WIB.

| Endpoint | Keluaran `data` | Sumber legacy |
|---|---|---|
| `GET services/s_stat/rekomendasi_kp` | daftar KP tahun ini format SIMPEG: `nama, nip, gol_ruang, tmtsk_pangkat, tgl_kp, periode_kp, kp_bl, pangkat, jabatan, eselon, id_unit, id_satker, satker, strata, id_jenis_kp, arsip_cpns[], arsip_pns[], arsip_last_kp[], arsip_skp{Y-1:[],Y-2:[]}`; tanggal `d M Y` ("01 Apr 2026"), `periode_kp` "Apr 2026", `kp_bl` "April"; urut `gol_ruang` DESC, `id_satker` ASC | `S_stat:21`, `L_employee::get_all_kp()` :2416 |
| `GET services/s_stat/rekomendasi_kp_uns[?id_unit=&id_satker=]` | format MuDig 37 kolom (`tmt_kp_new` mentah `Y-m-d`, `pangkat_new` dari `pangkat.order+1`, `mker_*_new`, `ins_induk` = `web_config.nama_kementerian`) + `arsip_cpns, arsip_pns, arsip_last_kp, arsip_skp{Y-1,Y-2}, arsip_last_jab, arsip_last_pak, lu_ppak, lu_pcpns, lu_ppns, lu_pkp, lu_pmj, lu_rskp_y1, lu_rskp_y2`. **`id_unit` atau `id_satker` tidak kosong → `data: []`** (`[K] bug legacy Q6-37`, AC-08.4; nilai kosong = tanpa filter) | `S_stat:433` |
| `GET services/s_stat/rekomendasi_pensiun` | PNS dengan `tgl_pensiun` tahun ini: `nama, nip, tgl_lahir, jabatan, tmtsk_jabatan, satker, eselon, pensiun_th, pensiun_bl, tgl_pensiun, id_unit, id_satker, usia_th, usia_bl, arsip_cpns, arsip_pns, arsip_last_kp, arsip_skp{Y-1}`; urut `tgl_pensiun` DESC, `id_satker` ASC | `S_stat:737`, `get_all_pensiun()` :1324, `getTanggalPensiun()` FH:2400 |
| `GET services/s_stat/rekomendasi_kgb` | PNS+PPPK dengan `tmtsk_kgb` tahun ini/depan: `nama, nip, tmtsk_kgb, kgb_th, kgb_bl, no_sk_last_kgb, tmtsk_last_kgb, gaji_last_kgb, eselon, jabatan, satker, id_unit, id_satker, gol_ruang, id_jenis_pegawai, jenis_pegawai, arsip_last_kgb[]`; [V2] urut `tmtsk_kgb` ASC (legacy tidak terurut, F6-09) | `S_stat:936`, `get_all_kgb()` :4095 |
| `GET services/s_stat/list_tb` · `list_ib` | `riwayat_tb.*` / `riwayat_ib.*` status 0/1/3 + `id_unit`, `id_satker` (snapshot jabatan); [V2] urut PK ASC. Kolom berkas (`file_sp`, `file_1..3`, `sp_admin_deputi` [B1: path legacy], `file_surat_*`) = URL unduh/kosong; kolom kosong diisi URL lampiran v2 kode 46–50 bila ada (P4) | `S_stat:1268`, `:1310` |
| `GET services/s_stat/list_karpeg` · `list_kariskarsu` | `riwayat_*.*` status 0/1 + `glr_awal, nama, glr_akhir, jenis_kelamin` + `id_sub_group_jabatan, sub_group_jabatan, id_unit, id_satker, unit, satker, jabatan`; urut `updated_at DESC` / `id DESC` | `S_stat:1352`, `:1398` |
| `GET services/s_stat/berkas/{jenis}/{nip}/{id}` | **[V2] Q6-38** unduh lampiran `document_attachment`: `jenis` ∈ `kp` (11), `skp` (32), `jabatan` (9), `kgb` (10), serta `lampiran_tb` (46), `surat_tb` (47), `lampiran_ib` (49), `surat_ib` (50) untuk berkas TB/IB pasca-cutover (P4); `id` = `id_attachment`. Stream `attachment; filename="s_stat_{jenis}_{id}.{ext}"`, mime dari isi, `nosniff`, `no-store` | — |
| `GET services/s_stat/berkas/{jenis}/{nip}/{id}/{kolom}` | **[V2] Q6-38** unduh berkas kolom: `pak` (`pegawai_ak.id_riwayat_ak`, `file_pak`), `tb`/`ib`/`karpeg`/`kariskarsu` (PK baris, kolom daftar putih di `PenyajiStat::JENIS_KOLOM`, termasuk `sp_admin_deputi` tb/ib) | — |

Aturan berkas (Q6-38, keputusan Horii 05-11-2026): semua nilai `arsip_*`/`file_*` di respons = URL absolut ke endpoint unduh
di atas (dibangun hanya oleh `PenyajiStat::urlBerkas()`), kosong/`[]` bila berkas tidak ada di storage v2; path internal tidak
pernah keluar. Endpoint unduh membuka berkas hanya bila jenis dalam daftar putih, milik `{nip}`, dan barisnya memang bisa
muncul di keluaran (lampiran: entri snapshot `pegawai_cpns/pns/kp`, `pegawai_kgb`, `pegawai_mutasi_jabatan`; `skp`: `riwayat_skp`
status 1 tahun Y-1/Y-2; berkas kolom: status yang muncul di `list_*`); selain itu **404 seragam**
`{"status":2,"msg":"Berkas tidak ditemukan.","data":{}}`. Tanpa pembatasan per populasi rekomendasi (keputusan reviewer).

Tidak di-port: `rekomendasi_kp_uns_20230516`, `rekomendasi_kgb_20230706` (kode mati), varian satker/unit pensiun/KGB
(dikomentari di legacy). Test: `tests/database/Integrasi/SStatKontrakTest.php` (HTTP), `StatServiceTest.php` (kolom/arsip),
`tests/unit/Integrasi/Stat/*` (aturan murni).
