/**
 * Percakapan widget Vanya (PRD Fase 8 v1.1 §7.10.3). State tingkat modul (pola `features/konten/sesi.ts`), bukan Pinia:
 * `RedesignShell` dipasang ulang per route, jadi isi percakapan dan status buka/tutup harus hidup di luar komponen agar
 * tidak hilang saat pindah halaman, tetapi hanya di memori (tanpa localStorage) sehingga hilang saat muat ulang.
 * `resetChatbot()` dipanggil dari `clearSession()` auth store: pesan, status buka, dan token feedback dikosongkan agar
 * pengguna berikutnya di browser yang sama tidak melihat percakapan sebelumnya (BP8-15).
 */
import { reactive, readonly } from 'vue'

import { isApiError } from '@/lib/axios'

import { TEKS_VANYA } from '../constants'
import { galatPerKolom } from '../galat'
import { vanyaService } from '../services/chatbot.service'
import type { NilaiFeedback, PertanyaanWidget, TopikWidget } from '../types'

export type StatusPenilaian = 'siap' | 'mengirim' | 'terkirim' | 'gagal'

export interface Penilaian {
  idLog: number
  token: string
  status: StatusPenilaian
  nilai: NilaiFeedback | null
  galat: string
}

/** `jam` = waktu lokal HH:MM saat pesan masuk, tampil kecil di bawah balon (`dev:VP:227`, `:245-257`). */
export type PesanChat = { id: number; jam: string } & (
  | { jenis: 'user'; teks: string }
  | { jenis: 'sistem'; teks: string; galat?: boolean }
  | { jenis: 'jawaban'; html: string; penilaian: Penilaian | null }
  | { jenis: 'topik'; teks: string; topik: TopikWidget[] }
  /** `teks` kosong = saran yang menempel pada balasan sebelumnya (tanpa pengantar). */
  | { jenis: 'pertanyaan'; teks: string; pertanyaan: PertanyaanWidget[] }
)

interface StatePercakapan {
  terbuka: boolean
  sudahMulai: boolean
  mengetik: boolean
  pesan: PesanChat[]
}

const state = reactive<StatePercakapan>({ terbuka: false, sudahMulai: false, mengetik: false, pesan: [] })

let urutan = 0
/** Dinaikkan saat reset; respons yang datang sesudah reset dibuang (tidak bocor ke sesi berikutnya). */
let generasi = 0

type PesanBaru = PesanChat extends infer P ? (P extends PesanChat ? Omit<P, 'id' | 'jam'> : never) : never

const dua = (n: number): string => String(n).padStart(2, '0')

function jamSekarang(): string {
  const d = new Date()
  return `${dua(d.getHours())}:${dua(d.getMinutes())}`
}

function tambah(p: PesanBaru): void {
  urutan += 1
  state.pesan.push({ ...p, id: urutan, jam: jamSekarang() } as PesanChat)
}

/** 429 (throttle) dan 422/403 membawa pesan server berbahasa Indonesia (422 per kolom didahulukan, mis. `errors.pesan`). */
function pesanGalat(e: unknown, baku: string): string {
  const kolom = Object.values(galatPerKolom(e))[0]
  if (isApiError(e) && e.status === 422 && kolom) return kolom
  if (isApiError(e) && e.status !== null && [403, 422, 429].includes(e.status) && e.message) return e.message
  return baku
}

/** `senyap` = tanpa pesan galat (populer cadangan saat topik kosong, `dev:VP:345-354`). */
async function muat<T>(aksi: () => Promise<T>, berhasil: (hasil: T) => Promise<void> | void, senyap = false): Promise<void> {
  const gen = generasi
  state.mengetik = true
  try {
    const hasil = await aksi()
    if (gen === generasi) await berhasil(hasil)
  } catch (e) {
    if (gen === generasi && !senyap) tambah({ jenis: 'sistem', teks: pesanGalat(e, TEKS_VANYA.galatMuat), galat: true })
  } finally {
    if (gen === generasi) state.mengetik = false
  }
}

/** Tombol "Populer"; `senyap` saat menjadi cadangan topik kosong: daftar kosong/galat tidak memunculkan pesan. */
function tampilPopuler(senyap = false): Promise<void> {
  return muat(
    () => vanyaService.populer(),
    (daftar) => {
      if (daftar.length > 0) tambah({ jenis: 'pertanyaan', teks: TEKS_VANYA.pengantarPopuler, pertanyaan: daftar })
      else if (!senyap) tambah({ jenis: 'sistem', teks: TEKS_VANYA.populerKosong })
    },
    senyap,
  )
}

/** Bubble topik; tanpa topik → populer tanpa pesan galat (`dev:VP:356-362`). */
function tampilTopik(): Promise<void> {
  return muat(
    () => vanyaService.topik(),
    async (daftar) => {
      if (daftar.length > 0) tambah({ jenis: 'topik', teks: TEKS_VANYA.pengantarTopik, topik: daftar })
      else await tampilPopuler(true)
    },
  )
}

function buka(): void {
  state.terbuka = true
  if (state.sudahMulai) return
  state.sudahMulai = true
  tambah({ jenis: 'sistem', teks: TEKS_VANYA.salam })
  void tampilTopik()
}

function tutup(): void {
  state.terbuka = false
}

function pilihTopik(topik: TopikWidget): Promise<void> {
  tambah({ jenis: 'user', teks: topik.judul })
  return muat(
    () => vanyaService.pertanyaan(topik.id),
    (daftar) => {
      if (daftar.length === 0) tambah({ jenis: 'sistem', teks: TEKS_VANYA.topikKosong })
      else tambah({ jenis: 'pertanyaan', teks: TEKS_VANYA.pengantarPertanyaan(topik.judul), pertanyaan: daftar })
    },
  )
}

async function balas(teks: string, idQna?: number): Promise<void> {
  const gen = generasi
  tambah({ jenis: 'user', teks })
  state.mengetik = true
  try {
    const hasil = await vanyaService.balas(teks, idQna)
    if (gen !== generasi) return
    if (hasil.cocok) {
      const penilaian =
        hasil.bisa_feedback && hasil.id_log !== null && hasil.token_feedback
          ? { idLog: hasil.id_log, token: hasil.token_feedback, status: 'siap' as const, nilai: null, galat: '' }
          : null
      tambah({ jenis: 'jawaban', html: hasil.balasan, penilaian })
    } else {
      // Fallback / korpus kosong: teks sistem (bukan HTML), lalu saran QnA populer bila ada.
      tambah({ jenis: 'sistem', teks: hasil.balasan })
      if (hasil.saran.length > 0) tambah({ jenis: 'pertanyaan', teks: '', pertanyaan: hasil.saran })
    }
  } catch (e) {
    if (gen === generasi) tambah({ jenis: 'sistem', teks: pesanGalat(e, TEKS_VANYA.galatKoneksi), galat: true })
  } finally {
    if (gen === generasi) state.mengetik = false
  }
}

/** Pertanyaan dari bubble/saran: teks pertanyaan + `id_qna` (`dev:VP:341`, F8-48). */
function pilihPertanyaan(p: PertanyaanWidget): Promise<void> {
  return balas(p.pertanyaan, p.id_qna)
}

/** Ketikan: trim; kosong diabaikan; lebih dari batas tidak dikirim (BE tetap menegakkan). */
function kirim(teks: string, maks: number): Promise<void> | null {
  const bersih = teks.trim()
  if (bersih === '' || [...bersih].length > maks) return null
  return balas(bersih)
}

async function nilai(idPesan: number, n: NilaiFeedback): Promise<void> {
  const pesan = state.pesan.find((p) => p.id === idPesan)
  if (!pesan || pesan.jenis !== 'jawaban' || !pesan.penilaian || pesan.penilaian.status === 'mengirim') return
  const p = pesan.penilaian
  const gen = generasi
  p.status = 'mengirim'
  p.nilai = n
  p.galat = ''
  try {
    await vanyaService.feedback(p.idLog, n, p.token)
    if (gen === generasi) p.status = 'terkirim'
  } catch (e) {
    if (gen !== generasi) return
    p.status = 'gagal'
    // Pesan server + "Coba lagi:" (`dev:VP:303`); galat jaringan → teks baku dev.
    p.galat = isApiError(e) && !e.isNetworkError && e.message ? e.message : TEKS_VANYA.penilaianGagal
  }
}

export function resetChatbot(): void {
  generasi += 1
  state.terbuka = false
  state.sudahMulai = false
  state.mengetik = false
  state.pesan.splice(0)
}

export function usePercakapanVanya() {
  return {
    state: readonly(state),
    buka,
    tutup,
    tampilTopik,
    tampilPopuler,
    pilihTopik,
    pilihPertanyaan,
    kirim,
    nilai,
  }
}
