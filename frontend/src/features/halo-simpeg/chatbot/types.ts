/**
 * Tipe chatbot "Vanya" (PRD Fase 8 v1.1 §6.5, §7.10). Bentuk respons API divalidasi di `schemas.ts`; tipe di sini adalah
 * bentuk yang dipakai komponen. Kontrak final dibekukan di README `backend/app/Controllers/Api/HaloSimpeg/Chatbot` (MAKE-094).
 */

/** Status baku chatbot (F8-25): 1 Aktif, 2 Tidak Aktif, 10 Dihapus. */
export type StatusChatbot = 1 | 2 | 10

/** Status yang boleh dipasang lewat `PATCH …/{id}/status` (F8-38). */
export type StatusUbah = 1 | 2

// ---------------------------------------------------------------------------------------------------------------------
// Widget (role UL_PEGAWAI)
// ---------------------------------------------------------------------------------------------------------------------

/** Bubble topik: kategori aktif ber-`tampil_bubble` yang punya QnA aktif. */
export interface TopikWidget {
  id: number
  judul: string
}

/** Bubble pertanyaan / populer / saran. */
export interface PertanyaanWidget {
  id_qna: number
  pertanyaan: string
}

export interface BalasanWidget {
  /** Jawaban HTML tersanitasi (cocok) atau teks sistem (tidak cocok / korpus kosong). */
  balasan: string
  cocok: boolean
  skor: number
  /** `null` bila tidak ada baris log (korpus kosong, F8-44). */
  id_log: number | null
  bisa_feedback: boolean
  token_feedback: string | null
  saran: PertanyaanWidget[]
}

export type NilaiFeedback = 1 | -1

// ---------------------------------------------------------------------------------------------------------------------
// Admin Chatbot Manager (role 1)
// ---------------------------------------------------------------------------------------------------------------------

export interface Daftar<T> {
  items: T[]
  total: number
  page: number
  per_page: number
}

export interface KategoriRow {
  id: number
  judul: string
  tampil_bubble: boolean
  status: StatusChatbot
}

export interface TagRow {
  id: number
  judul: string
  status: StatusChatbot
}

/** Rujukan kategori/tag pada QnA beserta statusnya (badge status induk F8-49). */
export interface RujukanBerstatus {
  id: number
  judul: string
  status: StatusChatbot
}

export interface QnaRow {
  id: number
  pertanyaan: string
  is_fallback_option: boolean
  status: StatusChatbot
  kategori: RujukanBerstatus | null
  tag: RujukanBerstatus[]
}

export interface QnaDetail extends QnaRow {
  jawaban: string
}

export interface OpsiQna {
  kategori: { id: number; judul: string }[]
  tag: { id: number; judul: string }[]
}

export interface FilterDaftar {
  page: number
  per_page: number
  status?: StatusUbah
  q?: string
}

export interface FilterQna extends FilterDaftar {
  kategori?: number
  tag?: number
}

export interface KategoriForm {
  judul: string
  tampil_bubble: boolean
  status: StatusUbah
}

export interface TagForm {
  judul: string
  status: StatusUbah
}

export interface QnaForm {
  id_kategori: number | null
  tag: number[]
  is_fallback_option: boolean
  status: StatusUbah
  pertanyaan: string
  jawaban: string
}

export interface BarisImpor {
  baris: number
  alasan: string
}

export interface HasilImpor {
  ditambah: number
  kategori_baru: number
  tag_baru: number
  dilewati: BarisImpor[]
  galat: BarisImpor[]
}

export interface StatistikChatbot {
  kpi: {
    total_chat: number
    chat_hari_ini: number
    rata_skor: number
    rata_skor_cocok: number
  }
  kepuasan: {
    membantu: number
    tidak_membantu: number
    total: number
    persen_puas: number
  }
  top_pesan: { pesan: string; jumlah: number }[]
  top_qna_cocok: { id_qna: number; pertanyaan: string | null; jumlah: number }[]
  top_qna_negatif: { id_qna: number; pertanyaan: string | null; jumlah: number }[]
}

export interface RiwayatRow {
  id_log: number
  /** UTC `Y-m-d H:i:s` (BP8-8); tampil WIB. */
  waktu: string
  pesan_user: string
  /** Pertanyaan QnA kandidat teratas; `null` bila tidak ada kecocokan / QnA sudah tidak ada. */
  pertanyaan_teratas: string | null
  skor: number
  cocok: boolean
  feedback: NilaiFeedback | null
}

export interface FilterRiwayat {
  page: number
  per_page: number
  dari?: string
  sampai?: string
  cocok?: 0 | 1
  feedback?: NilaiFeedback
}
