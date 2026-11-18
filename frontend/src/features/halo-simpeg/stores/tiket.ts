/**
 * State tiket Halo Simpeg tingkat modul (B2/B3, MAKE-073). Hanya di memori; dikosongkan oleh `resetHaloSimpeg()` (logout,
 * AC-E08.8) lewat `daftarkanResetHalo`.
 *
 * **Daftar (pabrik, keputusan reviewer B3):** `buatDaftarTiket(peran, …)` menghasilkan instance dengan state sendiri — filter,
 * halaman, generasi, dan urutan muat masing-masing — sehingga filter/halaman tab "Tiket saya" dan "Antrean" tidak saling
 * bocor. Instance: `daftarSaya` (`peran=saya`, baca bawaan `semua` [V2]), `daftarAntrean` (`peran=petugas`, baca bawaan `belum`
 * [K]), dan `daftarPerluTindak` (panel "Perlu ditindak": `peran=petugas&perlu_tindak=1`, 5 butir, filter tetap).
 *
 * **Detail & aksi (bersama):** satu panel kanan (`detailTiket`). Tutup/hapus/balas menerapkan respons BE ke baris yang sama di
 * semua daftar yang sudah dimuat; panel "Perlu ditindak" dimuat ulang karena keanggotaannya bisa berubah. Flag `bisa_*` selalu
 * dari BE (T2). `POST baca` sekali per pembukaan dan hanya bila `belum_dibaca`, lewat `bacaTiket` (penjaga generasi, B1 N2).
 * Ringkasan dimuat ulang lewat store (`muatRingkasan`) bila baris tadinya belum dibaca atau aksi dari sisi petugas.
 */
import { reactive, readonly } from 'vue'

import { isApiError } from '@/lib/axios'

import { BACA_BAWAAN_ANTREAN, BACA_BAWAAN_SAYA, PER_PAGE_MASTER } from '../constants'
import { tiketHaloService } from '../services/tiket.service'
import type { BalasForm, FilterTiket, Halaman, PeranTiket, Pesan, TiketBaris, TiketBaruForm, TiketDetail } from '../types'
import { bacaTiket, daftarkanResetHalo, haloState, muatRingkasan, type StatusMuat } from './haloSimpeg'

export interface GalatMuat {
  status: number | null
  pesan: string
}

interface StateDaftar {
  filter: FilterTiket
  daftar: Halaman<TiketBaris> | null
  statusDaftar: StatusMuat
  galatDaftar: GalatMuat | null
}

const galatDari = (e: unknown, cadangan: string): GalatMuat =>
  isApiError(e) ? { status: e.status, pesan: e.message } : { status: null, pesan: cadangan }

/**
 * Pabrik daftar tiket per `peran`. `tetap` = bagian filter yang tidak bisa diubah pemakai (mis. `perlu_tindak` panel).
 */
export function buatDaftarTiket(peran: PeranTiket, filterAwal: Omit<FilterTiket, 'peran'>, tetap: Partial<FilterTiket> = {}) {
  const awal = (): StateDaftar => ({
    filter: { ...filterAwal, ...tetap, peran },
    daftar: null,
    statusDaftar: 'awal',
    galatDaftar: null,
  })
  const state = reactive<StateDaftar>(awal())
  let generasi = 0
  let urutan = 0

  async function muat(): Promise<void> {
    const gen = generasi
    const u = ++urutan
    state.statusDaftar = 'memuat'
    state.galatDaftar = null
    try {
      const hasil = await tiketHaloService.list({ ...state.filter, ...tetap, peran })
      if (gen !== generasi || u !== urutan) return
      state.daftar = hasil
      state.statusDaftar = 'siap'
    } catch (e) {
      if (gen !== generasi || u !== urutan) return
      state.statusDaftar = 'galat'
      state.galatDaftar = galatDari(e, 'Daftar percakapan tidak dapat dimuat.')
    }
  }

  /** Ubah filter; selain perpindahan halaman, halaman kembali ke 1. */
  function ubahFilter(ubah: Partial<FilterTiket>): Promise<void> {
    state.filter = { ...state.filter, ...ubah, ...tetap, peran, page: ubah.page ?? 1 }
    return muat()
  }

  function terapkanBaris(baris: TiketBaris): void {
    if (state.daftar) state.daftar = { ...state.daftar, items: state.daftar.items.map((t) => (t.id === baris.id ? baris : t)) }
  }

  function tandaiDibaca(id: number): void {
    if (state.daftar) state.daftar = { ...state.daftar, items: state.daftar.items.map((t) => (t.id === id ? { ...t, belum_dibaca: false } : t)) }
  }

  function buangBaris(id: number): void {
    if (!state.daftar || !state.daftar.items.some((t) => t.id === id)) return
    state.daftar = { ...state.daftar, items: state.daftar.items.filter((t) => t.id !== id), total: Math.max(0, state.daftar.total - 1) }
  }

  function baris(id: number): TiketBaris | undefined {
    return state.daftar?.items.find((t) => t.id === id)
  }

  function reset(): void {
    generasi += 1
    urutan += 1
    Object.assign(state, awal())
  }

  return { peran, state: readonly(state), muat, ubahFilter, terapkanBaris, tandaiDibaca, buangBaris, baris, reset }
}

export type DaftarTiket = ReturnType<typeof buatDaftarTiket>

const dasar = { page: 1, per_page: PER_PAGE_MASTER }

export const daftarSaya = buatDaftarTiket('saya', { ...dasar, baca: BACA_BAWAAN_SAYA })
export const daftarAntrean = buatDaftarTiket('petugas', { ...dasar, baca: BACA_BAWAAN_ANTREAN })
export const daftarPerluTindak = buatDaftarTiket('petugas', { page: 1, per_page: 5, baca: 'semua' }, { perlu_tindak: true })

const semuaDaftar: DaftarTiket[] = [daftarSaya, daftarAntrean, daftarPerluTindak]

// --- detail (panel kanan, bersama) -----------------------------------------------------------------------------------------

interface StateDetail {
  idDibuka: number | null
  detail: TiketDetail | null
  statusDetail: StatusMuat
  galatDetail: GalatMuat | null
}

const detailAwal = (): StateDetail => ({ idDibuka: null, detail: null, statusDetail: 'awal', galatDetail: null })
const detailState = reactive<StateDetail>(detailAwal())
let generasiDetail = 0
let urutanDetail = 0

export const detailTiket = readonly(detailState)

function terapkanKeSemua(baris: TiketBaris): void {
  for (const d of semuaDaftar) d.terapkanBaris(baris)
  if (detailState.detail?.id === baris.id) detailState.detail = { ...detailState.detail, ...baris }
}

/** Apakah tiket `id` belum dibaca menurut data yang sudah dimuat (baris daftar mana pun atau detail). */
function belumDibaca(id: number): boolean {
  return semuaDaftar.some((d) => d.baris(id)?.belum_dibaca === true) || (detailState.detail?.id === id && detailState.detail.belum_dibaca)
}

/** Apakah aksi pada tiket `id` dilakukan dari sisi petugas (mengubah antrean / panel / ringkasan sisi petugas). */
function sisiPetugas(id: number): boolean {
  return (
    daftarAntrean.baris(id) !== undefined ||
    daftarPerluTindak.baris(id) !== undefined ||
    (detailState.detail?.id === id && detailState.detail.sebagai_petugas)
  )
}

/** Muat ulang panel "Perlu ditindak" bila sudah pernah dimuat (keanggotaannya ditentukan BE). */
function segarkanPanel(): void {
  if (daftarPerluTindak.state.statusDaftar !== 'awal') void daftarPerluTindak.muat()
}

/**
 * Review B3 R3 — satu pemicu muat panel per aksi: panel memuat ulang sendiri saat `Ringkasan.perlu_tindak` berubah (watcher
 * `PanelPerluTindak`). Sesudah aksi, ringkasan dimuat (paksa) dan ditunggu; panel disegarkan langsung hanya bila angkanya
 * tidak berubah (keanggotaan tetap bisa berubah) atau ringkasan belum pernah ada.
 */
async function segarkanSesudahAksi(opsi: { ringkasan: boolean; panel: boolean }): Promise<void> {
  const sebelum = haloState.ringkasan?.perlu_tindak
  if (opsi.ringkasan) await muatRingkasan(true)
  const sesudah = haloState.ringkasan?.perlu_tindak
  if (opsi.panel && (sebelum === undefined || sesudah === sebelum)) segarkanPanel()
}

/**
 * Buka tiket di panel kanan: muat detail, lalu `POST baca` sekali bila `belum_dibaca` (respons → ringkasan badge).
 * Membuka ulang id yang sama memuat ulang detail tetapi baca hanya dikirim bila masih belum dibaca.
 */
export async function bukaTiket(id: number): Promise<void> {
  const gen = generasiDetail
  const urutan = ++urutanDetail
  detailState.idDibuka = id
  detailState.statusDetail = 'memuat'
  detailState.galatDetail = null
  if (detailState.detail?.id !== id) detailState.detail = null
  try {
    const detail = await tiketHaloService.detail(id)
    if (gen !== generasiDetail || urutan !== urutanDetail) return
    detailState.detail = detail
    detailState.statusDetail = 'siap'
    if (detail.belum_dibaca) {
      try {
        const sebelumBaca = haloState.ringkasan?.perlu_tindak
        const ringkasan = await bacaTiket(id)
        if (ringkasan === null || gen !== generasiDetail) return
        // Review B2 P1 (a): baris ditandai dibaca walau panel sudah pindah/ditutup; detail hanya bila id masih sama.
        for (const d of semuaDaftar) d.tandaiDibaca(id)
        if (detailState.detail?.id === id) detailState.detail = { ...detailState.detail, belum_dibaca: false }
        // Ringkasan sudah dipasang dari respons baca; panel hanya bila angkanya tidak berubah (watcher menangani sisanya).
        // Review S3: ringkasan belum ada sebelum baca (watcher tidak punya pembanding) → panel tetap disegarkan.
        if (detail.sebagai_petugas && (sebelumBaca === undefined || haloState.ringkasan?.perlu_tindak === sebelumBaca)) segarkanPanel()
      } catch {
        // Penanda baca gagal: thread tetap tampil; badge disegarkan polling berikutnya.
      }
    }
  } catch (e) {
    if (gen !== generasiDetail || urutan !== urutanDetail) return
    detailState.statusDetail = 'galat'
    detailState.galatDetail = galatDari(e, 'Percakapan tidak dapat dimuat.')
  }
}

export function tutupPanel(): void {
  urutanDetail += 1
  Object.assign(detailState, detailAwal())
}

/**
 * E-05 tutup: baris dari BE diterapkan ke semua daftar. BE menyetel `is_read = 1` → ringkasan dimuat ulang bila baris tadinya
 * belum dibaca atau aksi dari sisi petugas (P1 (b)); tiket yang sedang terbuka dimuat ulang (P1 (c)). Galat dilempar.
 */
export async function tutupTiket(id: number): Promise<void> {
  const gen = generasiDetail
  const tadinyaBelum = belumDibaca(id)
  const petugas = sisiPetugas(id)
  const baris = await tiketHaloService.tutup(id)
  if (gen !== generasiDetail) return
  terapkanKeSemua(baris)
  void segarkanSesudahAksi({ ringkasan: tadinyaBelum || petugas, panel: petugas })
  if (detailState.idDibuka === id) void bukaTiket(id)
}

/** E-05 hapus: baris dibuang dari semua daftar; panel ditutup bila tiket itu sedang dibuka. */
export async function hapusTiket(id: number): Promise<void> {
  const gen = generasiDetail
  const petugas = sisiPetugas(id)
  await tiketHaloService.hapus(id)
  if (gen !== generasiDetail) return
  for (const d of semuaDaftar) d.buangBaris(id)
  if (petugas) void segarkanSesudahAksi({ ringkasan: true, panel: true })
  if (detailState.idDibuka === id) tutupPanel()
}

/** E-03: tiket baru menjadi detail terbuka; daftar "Tiket saya" dimuat ulang (urutan pesan terakhir). */
export async function buatTiket(form: TiketBaruForm): Promise<TiketDetail | null> {
  const gen = generasiDetail
  const detail = await tiketHaloService.buat(form)
  if (gen !== generasiDetail) return null
  urutanDetail += 1
  detailState.idDibuka = detail.id
  detailState.detail = detail
  detailState.statusDetail = 'siap'
  void daftarSaya.muat()
  return detail
}

/** E-04: pesan baru ditambahkan ke thread; baris tiket diperbarui (T9). Balasan petugas memuat ulang detail (pengingat). */
export async function balasTiket(id: number, form: BalasForm): Promise<Pesan | null> {
  const gen = generasiDetail
  const tadinyaBelum = belumDibaca(id)
  const petugas = sisiPetugas(id)
  const hasil = await tiketHaloService.balas(id, form)
  if (gen !== generasiDetail) return null
  if (detailState.detail?.id === id) detailState.detail = { ...detailState.detail, pesan: [...detailState.detail.pesan, hasil.pesan] }
  terapkanKeSemua(hasil.tiket)
  void segarkanSesudahAksi({ ringkasan: tadinyaBelum || petugas, panel: petugas })
  if (petugas) {
    // Pengingat 24 jam diatur BE dari balasan petugas → detail dimuat ulang agar `pengingat` terbaru tampil.
    if (detailState.idDibuka === id) void bukaTiket(id)
  }
  return hasil.pesan
}

export function resetTiket(): void {
  for (const d of semuaDaftar) d.reset()
  generasiDetail += 1
  urutanDetail += 1
  Object.assign(detailState, detailAwal())
}

daftarkanResetHalo(resetTiket)
