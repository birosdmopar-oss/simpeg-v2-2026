# features/notifikasi — F-08 Pusat Notifikasi (MAKE-085)

Kontrak API: `backend/app/Controllers/Api/Notifikasi/README.md`. PRD Fase 7 v2.0 §5.9, §7.

## Isi
| Berkas | Fungsi |
|---|---|
| `services/notifikasi.service.ts` | Klien `notifikasi*`. `api` sudah membuka amplop, jadi dibaca `res.data` (CR-059); nilai dinormalkan |
| `composables/useRingkasanNotifikasi.ts` | Ringkasan lonceng: SATU instance bersama, polling 60 detik saat tab terlihat, berhenti saat pemakai terakhir dilepas; galat mempertahankan nilai terakhir; `null` sumber ≠ 0 |
| `views/NotifikasiPage.vue` | `/notifikasi?tab=notifikasi\|butuh-approval\|histori-approval`; tab per role (`roles.ts`, Q7-29) |
| `components/*Tab.vue` | Daftar (⋮ Buka → Tandai dibaca → Hapus + ConfirmDialog), Butuh Approval (grup + riwayat, ⋮ Proses), Histori (bulan, ⋮ Buka) |
| `roles.ts` | Role ikut legacy, sama dengan filter route backend |
| `index.ts` | Ekspor publik untuk modul lain |

## Ekspor publik (kontrak C → D)
- `useRingkasanNotifikasi()` → `{ ringkasan, total, muatUlang }`.
- `NOTIFIKASI_ROUTE` (`notifikasi`; tab lewat `query.tab`).
- `BUTUH_APPROVAL_ROLES` [1, 2, 3], `NOTIFIKASI_DAFTAR_ROLES` [2, 3, 6, 7], `NOTIFIKASI_ROLES` [1, 2, 3, 6, 7].

## Titik sambung di berkas bersama
- `shared/layouts/RedesignShell.vue`: memasang `useRingkasanNotifikasi`.
  - Mengisi `AppTopbar :unread`.
  - Memuat ulang ringkasan tiap pindah rute.
  - Klik lonceng membuka `/notifikasi` untuk role yang punya halamannya.
  - Logout memanggil `resetRingkasanNotifikasi`.
- `shared/layouts/AppTopbar.vue`: event `open-notifikasi`.
- `shared/layouts/nav.config.ts`: item `notifikasi` `phase: FASE_LONCENG` (7), tersembunyi sampai ACTIVE_PHASE ≥ 7.
- Lonceng (angka, polling, klik) juga menunggu `ACTIVE_PHASE ≥ FASE_LONCENG` (keputusan reviewer 10-11-2026, pola F7-14):
  sebelum itu tampil polos tanpa titik merah. QA lewat URL `/notifikasi` langsung.
- `router/index.ts`: `notifikasiRoutes`.

## Catatan
- Tanpa frame Figma untuk halaman ini (mockup `67fa7678` hanya punya topbar): mengikuti pola halaman daftar lain
  (AGENTS.md §3.6).
- "Buka" menandai dibaca lalu membuka `url_fe` (Q7-31). Hapus hanya untuk notifikasi riwayat (`dapat_dihapus`, Q7-33).

Catatan: `berita_belum_dibaca` kini angka (sumber berita F-07 aktif); hanya `tiket_belum_dibaca` yang null sampai Fase 8.
