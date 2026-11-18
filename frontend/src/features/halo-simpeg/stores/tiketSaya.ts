/**
 * State layar pemilik "Tiket saya" (B2, MAKE-073) tingkat modul: daftar (filter + halaman) dan tiket yang sedang dibuka di
 * panel kanan. Bertahan lintas rute `/halo-simpeg` ↔ `/halo-simpeg/tiket/:id` (halaman dipasang ulang), hanya di memori,
 * dan dikosongkan oleh `resetHaloSimpeg()` (logout, AC-E08.8) lewat `daftarkanResetHalo`.
 * - Penjaga generasi: respons yang tiba sesudah reset dibuang. Penjaga urutan: hanya muat daftar/detail terakhir yang dipakai.
 * - `POST baca` hanya sekali per pembukaan panel dan hanya bila `belum_dibaca` (review B2), lewat `bacaTiket` (penjaga
 *   generasi ringkasan, review B1 N2).
 * - Flag `bisa_*` selalu dari BE (tidak dihitung ulang di FE, T2).
 */
import { reactive, readonly } from 'vue'

import { isApiError } from '@/lib/axios'

import { BACA_BAWAAN_SAYA, PER_PAGE_MASTER } from '../constants'
import { tiketHaloService } from '../services/tiket.service'
import type { BalasForm, FilterTiket, Halaman, Pesan, TiketBaris, TiketBaruForm, TiketDetail } from '../types'
import { bacaTiket, daftarkanResetHalo, type StatusMuat } from './haloSimpeg'

export interface GalatMuat {
  status: number | null
  pesan: string
}

interface StateTiketSaya {
  filter: FilterTiket
  daftar: Halaman<TiketBaris> | null
  statusDaftar: StatusMuat
  galatDaftar: GalatMuat | null
  idDibuka: number | null
  detail: TiketDetail | null
  statusDetail: StatusMuat
  galatDetail: GalatMuat | null
}

const filterAwal = (): FilterTiket => ({ peran: 'saya', baca: BACA_BAWAAN_SAYA, page: 1, per_page: PER_PAGE_MASTER })

const awal = (): StateTiketSaya => ({
  filter: filterAwal(),
  daftar: null,
  statusDaftar: 'awal',
  galatDaftar: null,
  idDibuka: null,
  detail: null,
  statusDetail: 'awal',
  galatDetail: null,
})

const state = reactive<StateTiketSaya>(awal())
let generasi = 0
let urutanDaftar = 0
let urutanDetail = 0

export const tiketSayaState = readonly(state)

const galatDari = (e: unknown, cadangan: string): GalatMuat =>
  isApiError(e) ? { status: e.status, pesan: e.message } : { status: null, pesan: cadangan }

export async function muatDaftarSaya(): Promise<void> {
  const gen = generasi
  const urutan = ++urutanDaftar
  state.statusDaftar = 'memuat'
  state.galatDaftar = null
  try {
    const hasil = await tiketHaloService.list({ ...state.filter, peran: 'saya' })
    if (gen !== generasi || urutan !== urutanDaftar) return
    state.daftar = hasil
    state.statusDaftar = 'siap'
  } catch (e) {
    if (gen !== generasi || urutan !== urutanDaftar) return
    state.statusDaftar = 'galat'
    state.galatDaftar = galatDari(e, 'Daftar percakapan tidak dapat dimuat.')
  }
}

/** Ubah filter; selain perpindahan halaman, halaman kembali ke 1. */
export function ubahFilterSaya(ubah: Partial<FilterTiket>): Promise<void> {
  state.filter = { ...state.filter, ...ubah, peran: 'saya', page: ubah.page ?? 1 }
  return muatDaftarSaya()
}

/** Ganti baris di daftar (dan ringkasan detail yang terbuka) dengan bentuk terbaru dari BE. */
function terapkanBaris(baris: TiketBaris): void {
  if (state.daftar) state.daftar = { ...state.daftar, items: state.daftar.items.map((t) => (t.id === baris.id ? baris : t)) }
  if (state.detail?.id === baris.id) state.detail = { ...state.detail, ...baris }
}

/**
 * Buka tiket di panel kanan: muat detail, lalu `POST baca` sekali bila `belum_dibaca` (respons → ringkasan badge).
 * Membuka ulang id yang sama memuat ulang detail tetapi baca hanya dikirim bila masih belum dibaca.
 */
export async function bukaTiket(id: number): Promise<void> {
  const gen = generasi
  const urutan = ++urutanDetail
  state.idDibuka = id
  state.statusDetail = 'memuat'
  state.galatDetail = null
  if (state.detail?.id !== id) state.detail = null
  try {
    const detail = await tiketHaloService.detail(id)
    if (gen !== generasi || urutan !== urutanDetail) return
    state.detail = detail
    state.statusDetail = 'siap'
    if (detail.belum_dibaca) {
      try {
        const ringkasan = await bacaTiket(id)
        if (ringkasan === null || gen !== generasi || state.detail?.id !== id) return
        state.detail = { ...state.detail, belum_dibaca: false }
        if (state.daftar) {
          state.daftar = { ...state.daftar, items: state.daftar.items.map((t) => (t.id === id ? { ...t, belum_dibaca: false } : t)) }
        }
      } catch {
        // Penanda baca gagal: thread tetap tampil; badge disegarkan polling berikutnya.
      }
    }
  } catch (e) {
    if (gen !== generasi || urutan !== urutanDetail) return
    state.statusDetail = 'galat'
    state.galatDetail = galatDari(e, 'Percakapan tidak dapat dimuat.')
  }
}

export function tutupPanel(): void {
  urutanDetail += 1
  state.idDibuka = null
  state.detail = null
  state.statusDetail = 'awal'
  state.galatDetail = null
}

/** E-05 tutup: baris dari BE diterapkan. Galat dilempar ke pemanggil (ditampilkan di layar). */
export async function tutupTiket(id: number): Promise<void> {
  const gen = generasi
  const baris = await tiketHaloService.tutup(id)
  if (gen === generasi) terapkanBaris(baris)
}

/** E-05 hapus: baris dibuang; panel ditutup bila tiket itu sedang dibuka. */
export async function hapusTiket(id: number): Promise<void> {
  const gen = generasi
  await tiketHaloService.hapus(id)
  if (gen !== generasi) return
  if (state.daftar) {
    state.daftar = { ...state.daftar, items: state.daftar.items.filter((t) => t.id !== id), total: Math.max(0, state.daftar.total - 1) }
  }
  if (state.idDibuka === id) tutupPanel()
}

/** E-03: tiket baru menjadi detail terbuka; daftar dimuat ulang (urutan pesan terakhir). */
export async function buatTiket(form: TiketBaruForm): Promise<TiketDetail | null> {
  const gen = generasi
  const detail = await tiketHaloService.buat(form)
  if (gen !== generasi) return null
  urutanDetail += 1
  state.idDibuka = detail.id
  state.detail = detail
  state.statusDetail = 'siap'
  void muatDaftarSaya()
  return detail
}

/** E-04: pesan baru ditambahkan ke thread; baris tiket diperbarui (T9). */
export async function balasTiket(id: number, form: BalasForm): Promise<Pesan | null> {
  const gen = generasi
  const hasil = await tiketHaloService.balas(id, form)
  if (gen !== generasi) return null
  if (state.detail?.id === id) state.detail = { ...state.detail, pesan: [...state.detail.pesan, hasil.pesan] }
  terapkanBaris(hasil.tiket)
  return hasil.pesan
}

export function resetTiketSaya(): void {
  generasi += 1
  urutanDaftar += 1
  urutanDetail += 1
  Object.assign(state, awal())
}

daftarkanResetHalo(resetTiketSaya)
