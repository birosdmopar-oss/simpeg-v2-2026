# Rencana M7 — Frontend Modul C (MAKE-026)

Status: **usulan untuk Horii (belum ada kode FE)** · 28-10-2026 · acuan: `PRD_Fase4.md` v1.3 §6.11, §9.

## 1. Prasyarat & penanda

- Branch kerja: `fase4/make-026-frontend-layanan` dari `main` terbaru, dibuka setelah M6 (MAKE-025) masuk `main`.
- Halaman cuti/TB/IB/PMK boleh mulai lebih awal (backend M3/M4/M5 sudah di `main`); halaman Status Layanan & PIC menunggu M6.
- Penanda menu `NavItem.rilis` / `NavChild.roles` **didefinisikan WS-2 (M7 FE Fase 5A tahap a)**. Fase 4 memakai definisi itu; tidak dibuat versi sendiri. Bila belum di `main` saat M7 mulai, entri menu Fase 4 ditunda ke PR terakhir (penutup).
- `ACTIVE_PHASE` 3 → 4 hanya di commit penutup (AC-11.8).
- Figma Modul C belum ada: acuan Gambar 17 + pola halaman Fase 3/7 + checklist §6.11.8 (Q-18).

## 2. Fondasi bersama (PR-0, dikerjakan Claude, kecil dan berurutan)

Dikerjakan dulu supaya halaman lain tidak saling menunggu:

1. `features/layanan/types.ts`, `roles.ts` (konstanta role per menu §6.11.2), `routes.layanan.ts` (rute terdaftar, menu belum aktif), impor aditif di `router/index.ts`.
2. `services/` dasar + normalisasi `Number(...)` (CR-049) dan test Vitest dengan payload string.
3. `components/StatusLayananBadge.vue` + `cutiStatus.ts` (label/nada §6.11.4), pembungkus `TangguhkanDialog` di atas `ApprovalDialog` (tanpa mengubah `ApprovalDialog`).
4. Pola tabel + ⋮ + `useStickyActionsShadow` yang dipakai ulang (contoh satu halaman acuan di PR-1).

## 3. Halaman, pembagian kerja, urutan merge

Claude = penalaran/form kompleks/keamanan (kalkulator, e-sign passphrase, proses). Worker Haiku = halaman pola berulang, setelah PR-1 memberi contoh acuan.

| PR | Isi | Pelaksana | Syarat backend | Catatan |
|---|---|---|---|---|
| PR-0 | Fondasi §2 | Claude | – | merge pertama |
| PR-1 | Cuti: `CutiPage` (3 tab), `CutiDetailPage` (stepper, riwayat, lampiran, surat), `CutiProsesDialog`, `PengingatCutiDialog` | Claude | M3, M4 | halaman acuan tabel/⋮/stepper |
| PR-2 | `CutiFormDialog` + kalkulator real-time (`POST cuti/hitung-lama`, debounce 300 ms), unggah bukti, role 1 pilih pegawai | Claude | M3 | tanpa hitung kuota di FE |
| PR-3 | `EsignDialog` (passphrase `type=password`, dikirim di body, tidak disimpan), aksi Terbitkan TTE / Unduh surat | Claude | M4 | bagian keamanan |
| PR-4 | `SaldoCutiPage`, `PetugasApprovalCutiPage` (CRUD ⋮) | Haiku | M3 | pola master data |
| PR-5 | TB: `TbPage`, `TbDetailPage`, `TbFormDialog`, `TbProsesDialog`, `TbPerpanjanganDialog` | Haiku (+ review Claude) | M5 | role 1,2,3,6 (tanpa 7) |
| PR-6 | IB: pola TB tanpa perpanjangan | Haiku | M5 | role 1,2,3,7 (tanpa 6) |
| PR-7 | PMK: `PmkPage` (4 tab), `PmkDetailPage`, `PmkFormDialog`, `PmkProsesDialog` | Claude | M5 | alur Proses/Setujui/Tolak |
| PR-8 | `LayananStatusPage` (filter, UiStepper, PIC, ⋮ Lihat detail, paginasi) | Claude | **M6** | Gambar 17 |
| PR-9 | `PetugasLayananPage` (CRUD PIC, role 1) | Haiku | **M6** | |
| PR-10 | Penutup: entri menu `nav.config.ts` per role, `ACTIVE_PHASE = 4`, Vitest menu per role, QA responsif 1440/600/390, progres | Claude | semua | satu-satunya yang menaikkan fase |

Urutan merge: PR-0 → PR-1 → (PR-2, PR-3, PR-4 paralel) → (PR-5, PR-6, PR-7 paralel) → PR-8 → PR-9 → PR-10. PR-5/6 baru dibuka setelah PR-1 merged agar Haiku punya contoh. Satu worktree dan satu DB scratch per paket; Haiku bekerja di worktree terpisah dengan batas berkas per PR (`features/layanan/views/<modul>/**`) supaya tidak tumpang tindih.

## 4. Aturan yang berlaku untuk semua PR

- AGENTS §1 (⋮, urutan item, `testid` `<fitur>-actions-<id>`), §3 (komponen custom `shared/ui`, tanpa native/`title`/`window.confirm`).
- `shared/**` hanya aditif; dilarang mengubah `ApprovalDialog`/`StatusBadge`/`approvalActions.ts`.
- Mock hanya di test; tak ada logika kuota/hari kerja/tahap di FE; 422 per kolom, 403/404 berpesan Indonesia.
- Gate cepat per PR: `cd frontend && npm run check` + Vitest terarah; CI hijau sebelum lapor; tidak merge tanpa izin reviewer/Horii.
- Commit `feat(layanan): … (MAKE-026)`, tanpa trailer AI.

## 5. Risiko & pertanyaan untuk Horii

1. Definisi `NavItem.rilis`/`NavChild.roles` dari WS-2 belum di `main` — PR-10 bergantung padanya.
2. Frame Figma Layanan (bila desainer punya) menggantikan checklist §6.11.8 — ditanyakan sebelum PR-8.
3. Apakah Haiku boleh mengerjakan PR-5/6 tanpa review Claude per PR (usulan: tetap direview Claude sebelum diajukan ke reviewer CR).
4. Keterbatasan M4: admin role 1 tanpa NIP tidak menerima notifikasi "Perlu Diproses Admin"; FE menampilkan antrean lewat tab Perlu Persetujuan + pengingat (tidak butuh perubahan).
