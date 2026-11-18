/**
 * State helpdesk Halo Simpeg tingkat modul (bukan Pinia, pola `chatbot/stores/percakapan.ts`): bertahan lintas halaman
 * (RedesignShell dipasang ulang per route), hanya di memori.
 * - `petugasSaya` (`GET petugas/saya`) = sumber tab antrean, panel "Perlu ditindak", centang ingatkan, dan tombol ekspor;
 *   bukan role (F8-06, K-A3).
 * - `ringkasan` (`GET tiket/ringkasan`, README MAKE-071 §6.2) = angka badge menu (`badge`) dan tujuan entri lonceng;
 *   dimuat `muatRingkasan()` (dipanggil composable `useRingkasanHalo` yang bergerbang, AC-E08.11) dan diperbarui langsung
 *   dari respons `POST …/baca` lewat `setRingkasanHalo()`. Galat muat mempertahankan angka terakhir (pola lonceng).
 * `resetHaloSimpeg()` dipanggil dari `auth.store.ts` `clearSession()` (AC-E08.8): semua state dikosongkan, dan respons yang
 * tiba sesudah reset dibuang lewat penjaga generasi. Penjaga urutan membuang respons muat lama yang tiba sesudah muat baru.
 */
import { computed, reactive, readonly } from 'vue'

import { isApiError } from '@/lib/axios'

import { petugasHaloService } from '../services/master.service'
import { tiketHaloService } from '../services/tiket.service'
import type { PetugasSaya, Ringkasan } from '../types'

export type StatusMuat = 'awal' | 'memuat' | 'siap' | 'galat'

interface StateHalo {
  petugasSaya: PetugasSaya | null
  statusPetugasSaya: StatusMuat
  galatPetugasSaya: string
  ringkasan: Ringkasan | null
}

const awal = (): StateHalo => ({ petugasSaya: null, statusPetugasSaya: 'awal', galatPetugasSaya: '', ringkasan: null })

const state = reactive<StateHalo>(awal())

/** Naik pada setiap reset: respons milik sesi sebelumnya dibuang. */
let generasi = 0
/** Naik pada setiap muat `petugas/saya`: hanya respons muat terakhir yang dipakai. */
let urutanMuat = 0
let janjiMuat: Promise<void> | null = null
/** Naik pada setiap muat / pasang ringkasan: respons ringkasan yang lebih lama dibuang. */
let urutanRingkasan = 0

export const haloState = readonly(state)

/** Petugas aktif → tab antrean, panel "Perlu ditindak", centang ingatkan. */
export const petugasAktif = computed(() => state.petugasSaya?.petugas === true && state.petugasSaya.aktif)

/** Tombol ekspor rekap (B4) hanya bila `bisa_ekspor`. */
export const bisaEkspor = computed(() => state.petugasSaya?.bisa_ekspor === true)

/**
 * Muat `petugas/saya` sekali per sesi (cache); `paksa` memuat ulang (mis. sesudah admin mengubah petugas). Pemanggil yang
 * datang saat muat berjalan menunggu janji yang sama.
 */
export function muatPetugasSaya(paksa = false): Promise<void> {
  if (!paksa && state.statusPetugasSaya === 'siap') return Promise.resolve()
  if (!paksa && janjiMuat) return janjiMuat
  const gen = generasi
  const urutan = ++urutanMuat
  state.statusPetugasSaya = 'memuat'
  state.galatPetugasSaya = ''
  const janji = petugasHaloService
    .saya()
    .then((hasil) => {
      if (gen !== generasi || urutan !== urutanMuat) return
      state.petugasSaya = hasil
      state.statusPetugasSaya = 'siap'
    })
    .catch((e: unknown) => {
      if (gen !== generasi || urutan !== urutanMuat) return
      state.statusPetugasSaya = 'galat'
      state.galatPetugasSaya = isApiError(e) ? e.message : 'Data petugas Halo Simpeg tidak dapat dimuat.'
    })
    .finally(() => {
      if (janjiMuat === janji) janjiMuat = null
    })
  janjiMuat = janji
  return janji
}

/**
 * Pasang ringkasan secara sinkron; muat ringkasan yang masih berjalan tidak menimpanya. Pemanggil yang memasang sesudah
 * `await` wajib memeriksa generasi lebih dulu (pakai `bacaTiket` untuk respons `POST …/baca`).
 */
export function setRingkasanHalo(ringkasan: Ringkasan | null): void {
  urutanRingkasan += 1
  state.ringkasan = ringkasan
}

/**
 * `POST tiket/{id}/baca` (BP8-7) lalu pasang `Ringkasan` dari responsnya — satu-satunya jalur komponen untuk menandai baca
 * (review B1 N2). Generasi dicatat sebelum request: respons yang tiba sesudah `resetHaloSimpeg()` (logout / ganti akun)
 * dibuang **tanpa** menaikkan urutan ringkasan, sehingga muat ringkasan sesi baru tidak ikut terbuang.
 * Galat dilempar ke pemanggil (thread tetap tampil; badge disegarkan polling berikutnya).
 * @returns ringkasan terpasang, atau `null` bila respons milik sesi lama.
 */
export async function bacaTiket(id: number): Promise<Ringkasan | null> {
  const gen = generasi
  const hasil = await tiketHaloService.baca(id)
  if (gen !== generasi) return null
  setRingkasanHalo(hasil)
  return hasil
}

let janjiRingkasan: Promise<void> | null = null

/**
 * `GET tiket/ringkasan`. Pemanggil yang datang saat muat berjalan memakai janji yang sama (shell dipasang ulang per route +
 * muat ulang pindah halaman tidak menggandakan request). Galat → angka terakhir dipertahankan (tidak mengganggu halaman).
 */
export function muatRingkasan(): Promise<void> {
  if (janjiRingkasan) return janjiRingkasan
  const gen = generasi
  const urutan = ++urutanRingkasan
  // Dibungkus Promise agar galat sinkron pun tidak pernah menggagalkan pemanggil (mis. tutup/balas memuat ulang ringkasan).
  const janji = Promise.resolve()
    .then(() => tiketHaloService.ringkasan())
    .then((hasil) => {
      if (gen === generasi && urutan === urutanRingkasan) state.ringkasan = hasil
    })
    .catch(() => {
      // Dipertahankan nilai terakhir; percobaan berikutnya pada siklus polling / pindah halaman.
    })
    .finally(() => {
      if (janjiRingkasan === janji) janjiRingkasan = null
    })
  janjiRingkasan = janji
  return janji
}

/** Store helpdesk lain (mis. `tiketSaya`) mendaftarkan reset-nya di sini agar ikut dikosongkan tanpa import melingkar. */
const resetTambahan = new Set<() => void>()
export function daftarkanResetHalo(fn: () => void): void {
  resetTambahan.add(fn)
}

/** Dipanggil dari `clearSession()` (logout / sesi berganti): kosongkan semua state helpdesk. */
export function resetHaloSimpeg(): void {
  for (const fn of resetTambahan) fn()
  generasi += 1
  urutanMuat += 1
  urutanRingkasan += 1
  janjiMuat = null
  janjiRingkasan = null
  Object.assign(state, awal())
}
