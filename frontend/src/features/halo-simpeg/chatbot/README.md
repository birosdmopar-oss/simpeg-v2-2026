# Chatbot "Vanya" — frontend (Fase 8 E-10, MAKE-095)

Acuan: PRD Fase 8 v1.1 (`docs/fase8/PRD_Fase8_HaloSimpeg.md`) §6.3, §6.5, §7.10.3–7.10.7 dan `docs/fase8/8_2.md` §5.
Mesin jawab adalah pencocokan teks lokal TF-IDF di backend (bukan AI/LLM). Jalur chatbot **terpisah** dari helpdesk Halo
Simpeg: tidak memakai service, store, route, atau komponen helpdesk.

## Isi

| Berkas | Isi |
|---|---|
| `types.ts`, `schemas.ts` | Tipe komponen + skema Zod respons API (koersi angka/boolean PHP). Penyesuaian kontrak MAKE-094 cukup di `schemas.ts`. |
| `services/chatbot.service.ts` | Klien `api/v1/halo-simpeg/chatbot/*` lewat `@/lib/axios` (amplop dibuka interceptor). Widget: `widget/kategori`, `widget/kategori/{id}/pertanyaan`, `widget/populer`, `widget/balas`, `widget/feedback`. Admin: CRUD `kategori`/`tag`/`qna` + `PATCH …/{id}/status`, `qna/opsi`, `qna/template`, `qna/impor`, `tag/ekspor`, `statistik`, `riwayat`. |
| `stores/percakapan.ts` | State percakapan tingkat modul (bukan Pinia): bertahan lintas halaman (RedesignShell dipasang ulang per route), hanya di memori. `resetChatbot()` dipanggil dari `auth.store.ts` `clearSession()` (pesan, status buka, token feedback kosong; respons yang datang sesudah reset dibuang). |
| `stores/peek.ts` | Peek kartu sapaan sekali per sesi browser (sessionStorage dalam try/catch + cadangan memori). |
| `components/widget/` | `VanyaWidget` (launcher, kartu sapaan, jendela chat), `VanyaAvatar` (SVG dev di-port), `VanyaPenilaian` (👍/👎). |
| `components/admin/` | `MasterChatbotFormDialog` (kategori/tag), `QnaFormDialog`, `TagPilihan` (tag ganda: `UiCheckbox` + `UiSearchInput`), `ImporQnaDialog`, `StatusChatbotBadge`. |
| `views/ChatbotManagerPage.vue`, `views/admin/*` | Chatbot Manager role 1: Kategori, Tag (+ ekspor xlsx), QnA (+ template & impor), Statistik, Riwayat. |
| `routes.chatbot.ts` | `/chatbot-manager/:bagian` (kategori, tag, qna, statistik, riwayat; nama route `chatbot-<bagian>`); `/chatbot-manager` → kategori. |
| `__tests__/` | Vitest widget, admin, route/menu, dan klien API asli dengan `fixtures/` (contoh respons sintetis). |

## Aturan

- **Gerbang render widget** (F8-30/F8-43/F8-50): `ACTIVE_PHASE >= FASE_CHATBOT (8)`, role sesi ∈ `UL_PEGAWAI` (2/6/7), dan
  tidak sedang wajib ganti password. Dipasang sekali di `shared/layouts/RedesignShell.vue`.
- **Menu** Pengaturan → "Chatbot Manager" (role 1, `phase: 8`, tanpa `rilis`): tersembunyi sampai `ACTIVE_PHASE ≥ 8`; QA lewat
  URL langsung (pola F7-14). Menu dan widget terbuka bersamaan.
- **HTML hanya lewat `SafeHtml`** (BP8-14): jawaban widget dan pratinjau jawaban di form QnA. Pesan pengguna, teks sistem,
  judul, riwayat, dan statistik selalu teks ter-escape; tidak ada `v-html` lain.
- **Tabel admin** `page-fill` + `table-fill` + `UiCard fill`; aksi baris lewat ⋮ (`RowActionsMenu`): Edit → Aktifkan/Nonaktifkan
  (`PATCH …/status`) → Hapus (status 10, `ConfirmDialog`, tanpa Pulihkan). `testid` `chatbot-<kategori|tag|qna>-actions-<id>`.
- **Status induk** (F8-49): tag Tidak Aktif terpasang berbadge dan dipertahankan; tag Dihapus tidak tampil dan tidak dikirim;
  kategori Tidak Aktif/Dihapus tampil berpenanda di form (kategori Dihapus harus diganti, ditegakkan BE 422).
- Tampilan widget mengikuti view widget dev (`dev:views/hr/chatbot/chatbot_popup.php`) dengan token desain v2 (K-11); tanpa
  frame Figma (dicatat). Teks sapaan, salam, pengantar bubble, fallback, galat, dan penilaian mengikuti dev.

## Kontrak & fixture

Kontrak beku: README `backend/app/Controllers/Api/HaloSimpeg/Chatbot` (MAKE-094 @fd1e74ed). `__tests__/fixtures/*.json` adalah
**salinan** contoh respons sintetis `backend/tests/_support/HaloSimpeg/Chatbot/*.json` (tanpa `pembanding.json` milik test mesin
BE); test FE tidak membaca `backend/`, jadi salin ulang bila kontrak berubah lewat CR. Isi QnA dev tidak pernah masuk repo.

Catatan kontrak yang ditangani FE:
- galat 422 membawa `errors` **string** per kolom (`{"pesan": "…"}`), bukan array → baca lewat `galat.ts` (`galatPerKolom`);
- POST kategori/tag/QnA tanpa `status` (status awal 1), PUT dengan `status`;
- riwayat `waktu` ISO-8601 berzona WIB; filter `feedback=0` = belum dinilai;
- statistik `top_pesan[].pesan_user`; `pertanyaan: null` → `#<id_qna>`.
