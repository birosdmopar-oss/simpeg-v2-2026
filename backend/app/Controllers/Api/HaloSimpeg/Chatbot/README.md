# Controllers/Api/HaloSimpeg/Chatbot — API chatbot "Vanya" (Fase 8 E-10, MAKE-094)

Kontrak **BEKU** untuk MAKE-095 (FE). Acuan: PRD Fase 8 v1.1 §6.5, §7.10, F8-34..F8-51. Perubahan sesudah beku = CR yang
disepakati kedua jalur lewat reviewer. Contoh respons JSON sintetis: `backend/tests/_support/HaloSimpeg/Chatbot/*.json`
(disalin FE ke `frontend/src/features/halo-simpeg/chatbot/__tests__/fixtures/`).

Prefix `api/v1/halo-simpeg/chatbot`, route di `Config/RoutesChatbot.php`. Semua endpoint filter `jwt` + `role`:
- **widget** = role 2/6/7 (`Role::UL_PEGAWAI`, F8-30);
- **admin** = role 1 (F8-32).

Role lain → 403 dari filter route. Amplop v2:
- sukses `{status: "success", data}`;
- galat `{status: "error", message, errors?, code?}`;
- 422 validasi membawa `errors` per field.

Pesan galat berbahasa Indonesia mengikuti teks dev bila ada. Semua tulis non-GET (A3); ubah status lewat `PATCH …/{id}/status`
(F8-38).

## Tipe bersama

| Tipe | Bentuk |
|---|---|
| `Status` | `1` Aktif, `2` Tidak Aktif, `10` Dihapus (status 10 tidak pernah dikirim ke daftar admin, kecuali kategori QnA yang sudah dihapus, F8-49d) |
| `Halaman<T>` | `{items: T[], total: number, page: number, per_page: number}` |
| `Ringkas` | `{id: number, judul: string, status: Status}` |
| `PertanyaanBubble` | `{id_qna: number, pertanyaan: string}` |

Query daftar (`page` ≥ 1, `per_page` 1..100, bawaan 20) memakai penjaga `ListQuery`: parameter berbentuk array → 422.

## Widget (role 2/6/7)

Throttle per akun (F8-36):
- `balas`: 20 per menit;
- `feedback`: 30 per menit;
- tiga GET widget: kuota **gabungan** 60 per menit.

Lewat batas → 429 `"Terlalu banyak permintaan ke Vanya. Coba lagi dalam satu menit."`.

| Endpoint | Masuk | Keluar `data` |
|---|---|---|
| `GET widget/kategori` | — | `{id, judul}[]`: kategori status 1, `tampil_bubble = 1`, punya ≥ 1 QnA status 1; urut id menaik |
| `GET widget/kategori/{id}/pertanyaan` | `id` di path | `PertanyaanBubble[]`, maks 6 QnA status 1 di kategori itu: unggulan dulu, lalu paling sering cocok, lalu id menaik. Kategori tidak ada/tidak aktif/`id` ≤ 0 → `[]` |
| `GET widget/populer` | — | `PertanyaanBubble[]`, 3 QnA unggulan status 1, id menurun |
| `POST widget/balas` | `{pesan: string, id_qna?: number}` | `Balasan` (di bawah) |
| `POST widget/feedback` | `{id_log: number, nilai: 1 \| -1, token: string}` | `{id_log, nilai}` |

**`Balasan`** = `{balasan, cocok, skor, id_log, bisa_feedback, token_feedback?, saran}`:
- `balasan`: string. Cocok → `jawaban` QnA (HTML tersanitasi, render **hanya** lewat `SafeHtml`). Tidak cocok → teks sistem
  "Maaf, Vanya belum memahami pertanyaan Anda. Mungkin salah satu pertanyaan berikut yang Anda maksud?".
- `cocok`: boolean.
- `skor`: number, skor asli 4 desimal (jalur bubble 1).
- `id_log`: number, atau `null` hanya bila korpus kosong.
- `bisa_feedback`: boolean = cocok **dan** kunci token tersedia.
- `token_feedback`: string, hanya ada bila `bisa_feedback`.
- `saran`: `PertanyaanBubble[]`, maks 3, hanya saat tidak cocok.

Aturan `balas`:
- **Jalur bubble:** `id_qna` QnA status 1 → jawaban QnA itu, skor 1, cocok. Teks `pesan` klien diabaikan; log memakai
  `pertanyaan` QnA (F8-48).
- **Jalur ketikan:** `id_qna` tidak sah/tidak ada → jalur ketikan. `pesan` di-trim, wajib 1..300 karakter (`mb_strlen`)
  → bila tidak, 422 `errors.pesan`.
- **Korpus kosong:** 200 `{balasan: "Database QnA masih kosong.", cocok: false, skor: 0, id_log: null, bisa_feedback: false,
  saran: []}`, tanpa baris log (F8-44).
- Setiap balas lain menulis tepat satu baris `log_chatbot` anonim. Balas yang kena 429 tidak menulis log.

Aturan `feedback`:
- token tidak terurai/diubah/kedaluwarsa (24 jam)/milik akun lain, atau kunci token tidak tersedia → **403** "Sesi penilaian
  tidak ditemukan. Silakan ajukan pertanyaan lagi.";
- log tidak cocok → **422** "Jawaban ini tidak dapat dinilai.";
- `nilai` selain 1/-1 → 422 `errors.nilai`;
- nilai boleh ditimpa selama token berlaku.

## Admin (role 1)

### Kategori

| Endpoint | Masuk | Keluar `data` |
|---|---|---|
| `GET kategori` | `?status=1\|2&q&page&per_page` (tanpa `status` = 1 dan 2) | `Halaman<Kategori>` urut id menaik |
| `POST kategori` | `{judul, tampil_bubble: 0\|1}` | `Kategori` (201), status 1 |
| `GET kategori/{id}` | — | `Kategori` |
| `PUT kategori/{id}` | `{judul, status: 1\|2, tampil_bubble: 0\|1}` | `Kategori` |
| `PATCH kategori/{id}/status` | `{status: 1\|2}` | `Kategori` |
| `DELETE kategori/{id}` | — | `null` (status 10) |

`Kategori` = `{id, judul, status, tampil_bubble: 0|1}`. Judul wajib ("Judul kategori tidak boleh kosong!"), maks 150, unik
di antara status 1/2 (trim, abaikan huruf besar/kecil dan aksen sesuai collation) → 422 `errors.judul` "Judul kategori sudah
dipakai.". Baris status 10 → 404 di GET/PUT/PATCH/DELETE.

### Tag

| Endpoint | Masuk | Keluar `data` |
|---|---|---|
| `GET tag` | `?status=1\|2&q&page&per_page` | `Halaman<Tag>` urut id **menurun** |
| `POST tag` | `{judul}` | `Tag` (201) |
| `GET tag/{id}` | — | `Tag` |
| `PUT tag/{id}` | `{judul, status: 1\|2}` | `Tag` |
| `PATCH tag/{id}/status` | `{status: 1\|2}` | `Tag` |
| `DELETE tag/{id}` | — | `null` |
| `GET tag/ekspor?format=xlsx` | — | berkas `Export_Tag_QnA_{Ymd_His}.xlsx` (WIB), kolom No, Tag, Status ("Aktif"/"Tidak Aktif"), status 1 dan 2; format lain → 422 |

`Tag` = `{id, judul, status}`. Judul wajib ("Judul tag tidak boleh kosong!"), maks 100, unik seperti kategori ("Judul tag
sudah dipakai.").

### QnA

| Endpoint | Masuk | Keluar `data` |
|---|---|---|
| `GET qna` | `?status=1\|2&kategori=<id>&tag=<id>&q&page&per_page` | `Halaman<QnaBaris>` urut id menurun |
| `POST qna` | `QnaSimpan` | `QnaDetail` (201) |
| `GET qna/{id}` | — | `QnaDetail` |
| `PUT qna/{id}` | `QnaSimpan` | `QnaDetail` |
| `PATCH qna/{id}/status` | `{status: 1\|2}` | `QnaDetail` (jawaban dan tag tidak diubah) |
| `DELETE qna/{id}` | — | `null` |
| `GET qna/opsi` | — | `{kategori: {id, judul}[], tag: {id, judul}[]}` status 1, urut judul |
| `GET qna/template` | — | berkas `Template_Import_QnA.xlsx`, kolom "Pertanyaan", "Jawaban", "Kategori", "Tag (Pisahkan dengan koma)" + 1 baris contoh |
| `POST qna/impor` | multipart, field `berkas` (.xlsx) | `HasilImpor` |

- `QnaBaris` = `{id, pertanyaan, is_fallback_option: 0|1, status, kategori: Ringkas | null, tag: Ringkas[]}`.
  - `kategori.status` bisa 2 atau 10 (badge "Tidak Aktif"/"Dihapus", F8-49).
  - `tag` memuat status 1 dan 2; tag status 10 tidak dikirim.
- `QnaDetail` = `QnaBaris` + `jawaban` (HTML tersanitasi).
- `QnaSimpan` = `{pertanyaan, jawaban, id_kategori, tag: number[], is_fallback_option: 0|1, status?: 1|2}` (status bawaan 1).
  - Pertanyaan wajib ("Pertanyaan tidak boleh kosong!").
  - Jawaban wajib; kosong sesudah sanitasi → 422 `errors.jawaban` "Jawaban tidak boleh kosong!".
  - `id_kategori` wajib dan berstatus 1 ("Kategori wajib dipilih!"). Saat ubah, kategori lama status 2 boleh dipertahankan;
    kategori lama status 10 tidak diganti → 422 `errors.id_kategori` "Kategori QnA sudah dihapus. Pilih kategori lain.".
  - `tag`: id yang **ditambahkan** wajib status 1 → 422 `errors.tag` "Tag tidak aktif atau tidak ditemukan.". Tag lama status 2
    yang tetap dikirim dipertahankan; tag lama status 10 diabaikan (relasinya tetap di DB).
  - Simpan QnA + set tag dalam satu transaksi; ubah = ganti seluruh set.
- `HasilImpor` = `{ditambah, kategori_baru, tag_baru, dilewati: {baris, alasan}[], galat: {baris, alasan}[]}`.
  - `galat` tidak kosong → **tidak ada** baris tersimpan dan `ditambah = 0` (tetap 200, laporan per baris).
  - `baris` = nomor baris Excel (judul = baris 1).
  - Berkas bukan .xlsx / > 2 MB / > 1.000 baris data / rusak / judul kolom tidak sama template → 422 `errors.berkas`
    ("Format template tidak sesuai." untuk judul kolom).
  - Sukses: tampilkan "Data QnA beserta Kategori dan Tag berhasil diimpor!".

### Statistik & riwayat

| Endpoint | Masuk | Keluar `data` |
|---|---|---|
| `GET statistik` | — | `Statistik` |
| `GET riwayat` | `?dari=Y-m-d&sampai=Y-m-d&cocok=1\|0&feedback=1\|-1\|0&page&per_page` | `Halaman<Riwayat>` urut waktu terbaru |

- `Statistik` = `{kpi, kepuasan, top_pesan, top_qna_cocok, top_qna_negatif}`:
  - `kpi` = `{total_chat, chat_hari_ini, rata_skor, rata_skor_cocok}`; rata-rata 4 desimal, 0 bila tidak ada log; "hari
    ini" = hari kalender WIB.
  - `kepuasan` = `{membantu, tidak_membantu, total, persen_puas}` dari log cocok; persen 1 desimal, 0 bila total 0.
  - `top_pesan` = `{pesan_user, jumlah}[]` maks 10, dikelompokkan per teks pesan.
  - `top_qna_cocok` = `{id_qna, pertanyaan: string | null, jumlah}[]` maks 10.
  - `top_qna_negatif` = `{id_qna, pertanyaan: string | null, jumlah}[]` maks 10, hanya 👎 > 0, urut 👎 menurun.
  - `pertanyaan: null` = QnA sudah tidak ada → FE menampilkan `#<id_qna>`.
- `Riwayat` = `{id_log, waktu, pesan_user, pertanyaan_teratas: string | null, skor, cocok: boolean, feedback: 1 | -1 | null}`.
  - `waktu` = ISO-8601 berzona WIB (`2026-11-13T08:30:00+07:00`).
  - `pertanyaan_teratas: null` → FE menampilkan "(Tidak ada kecocokan)".
  - Filter `dari`/`sampai` = hari WIB inklusif (dikonversi ke rentang UTC); `feedback=0` = belum dinilai.
  - Tanggal tidak valid → 422.

Semua teks selain `balasan`/`jawaban` adalah teks polos: tampilkan ter-escape, tanpa `v-html` (BP8-14).
