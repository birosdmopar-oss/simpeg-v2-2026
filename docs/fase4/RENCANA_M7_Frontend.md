# Rencana M7 — Frontend Modul C (MAKE-026)

Status: **usulan untuk Horii (belum ada kode FE)** · 28-10-2026 · acuan: `PRD_Fase4.md` v1.3 §6.11, §9.

## 1. Prasyarat & penanda

- Branch kerja: `fase4/make-026-frontend-layanan` dari `main` terbaru, dibuka setelah M6 (MAKE-025) masuk `main`.
- Halaman cuti/TB/IB/PMK boleh mulai lebih awal (backend M3/M4/M5 sudah di `main`); halaman Status Layanan & PIC menunggu M6.
- Penanda menu `NavItem.rilis` / `NavChild.roles` **didefinisikan WS-2 (M7 FE Fase 5A tahap a)**. Fase 4 memakai definisi itu; tidak dibuat versi sendiri. Bila belum di `main` saat M7 mulai, entri menu Fase 4 ditunda ke paket terakhir (penutup).
- `ACTIVE_PHASE` 3 → 4 hanya di commit penutup (AC-11.8).
- Figma Modul C belum ada: acuan Gambar 17 + pola halaman Fase 3/7 + checklist §6.11.8 (Q-18).

## 2. Fondasi bersama (Paket 0, dikerjakan Coder A, kecil dan berurutan)

Dikerjakan dulu supaya halaman lain tidak saling menunggu:

1. `features/layanan/types.ts`, `roles.ts` (konstanta role per menu §6.11.2), `routes.layanan.ts` (rute terdaftar, menu belum aktif), impor aditif di `router/index.ts`.
2. `services/` dasar + normalisasi `Number(...)` (CR-049) dan test Vitest dengan payload string.
3. `components/StatusLayananBadge.vue` + `cutiStatus.ts` (label/nada §6.11.4), pembungkus `TangguhkanDialog` di atas `ApprovalDialog` (tanpa mengubah `ApprovalDialog`).
4. Pola tabel + ⋮ + `useStickyActionsShadow` yang dipakai ulang (contoh satu halaman acuan di Paket 1).

## 3. Halaman, pembagian kerja, urutan merge

Coder A = penalaran/form kompleks/keamanan (kalkulator, e-sign passphrase, proses). Coder B (sesi pendamping) = halaman pola berulang, setelah Paket 1 memberi contoh acuan.

| Paket | Isi | Pelaksana | Syarat backend | Catatan |
|---|---|---|---|---|
| Paket 0 | Fondasi §2 | Coder A | – | merge pertama |
| Paket 1 | Cuti: `CutiPage` (3 tab), `CutiDetailPage` (stepper, riwayat, lampiran, surat), `CutiProsesDialog`, `PengingatCutiDialog` | Coder A | M3, M4 | halaman acuan tabel/⋮/stepper |
| Paket 2 | `CutiFormDialog` + kalkulator real-time (`POST cuti/hitung-lama`, debounce 300 ms), unggah bukti, role 1 pilih pegawai | Coder A | M3 | tanpa hitung kuota di FE |
| Paket 3 | `EsignDialog` (passphrase `type=password`, dikirim di body, tidak disimpan), aksi Terbitkan TTE / Unduh surat | Coder A | M4 | bagian keamanan |
| Paket 4 | `SaldoCutiPage`, `PetugasApprovalCutiPage` (CRUD ⋮) | Coder B | M3 | pola master data |
| Paket 5 | TB: `TbPage`, `TbDetailPage`, `TbFormDialog`, `TbProsesDialog`, `TbPerpanjanganDialog` | Coder B (+ review Coder A) | M5 | role 1,2,3,6 (tanpa 7) |
| Paket 6 | IB: pola TB tanpa perpanjangan | Coder B | M5 | role 1,2,3,7 (tanpa 6) |
| Paket 7 | PMK: `PmkPage` (4 tab), `PmkDetailPage`, `PmkFormDialog`, `PmkProsesDialog` | Coder A | M5 | alur Proses/Setujui/Tolak |
| Paket 8 | `LayananStatusPage` (filter, UiStepper, PIC, ⋮ Lihat detail, paginasi) | Coder A | **M6** | Gambar 17 |
| Paket 9 | `PetugasLayananPage` (CRUD PIC, role 1) | Coder B | **M6** | |
| Paket 10 | Penutup: entri menu `nav.config.ts` per role, `ACTIVE_PHASE = 4`, Vitest menu per role, QA responsif 1440/600/390, progres; **pindahkan `PengingatCutiDialog` dari halaman Cuti ke setelah login (shell/beranda), sekali per sesi, PRD §6.4.7 (keputusan Horii 28-10)** | Coder A | semua | satu-satunya yang menaikkan fase |

Urutan merge: Paket 0 → Paket 1 → (Paket 2, Paket 3, Paket 4 paralel) → (Paket 5, Paket 6, Paket 7 paralel) → Paket 8 → Paket 9 → Paket 10. Paket 5/6 baru dibuka setelah Paket 1 merged agar Coder B punya contoh. Satu worktree dan satu DB scratch per paket; Coder B bekerja di worktree terpisah dengan batas berkas per paket (`features/layanan/views/<modul>/**`) supaya tidak tumpang tindih.

## 4. Aturan yang berlaku untuk semua paket

- AGENTS §1 (⋮, urutan item, `testid` `<fitur>-actions-<id>`), §3 (komponen custom `shared/ui`, tanpa native/`title`/`window.confirm`).
- `shared/**` hanya aditif; dilarang mengubah `ApprovalDialog`/`StatusBadge`/`approvalActions.ts`.
- Mock hanya di test; tak ada logika kuota/hari kerja/tahap di FE; 422 per kolom, 403/404 berpesan Indonesia.
- Gate cepat per paket: `cd frontend && npm run check` + Vitest terarah; CI hijau sebelum lapor; tidak merge tanpa izin reviewer/Horii.
- Commit `feat(layanan): … (MAKE-026)`, tanpa trailer tambahan.

## 5. Risiko & pertanyaan untuk Horii

1. Definisi `NavItem.rilis`/`NavChild.roles` dari WS-2 belum di `main` — Paket 10 bergantung padanya.
2. Frame Figma Layanan (bila desainer punya) menggantikan checklist §6.11.8 — ditanyakan sebelum Paket 8.
3. Apakah Coder B boleh mengerjakan Paket 5/6 tanpa review Coder A per paket (usulan: tetap direview Coder A sebelum diajukan ke reviewer CR).
4. Keterbatasan M4: admin role 1 tanpa NIP tidak menerima notifikasi "Perlu Diproses Admin"; FE menampilkan antrean lewat tab Perlu Persetujuan + pengingat (tidak butuh perubahan).
