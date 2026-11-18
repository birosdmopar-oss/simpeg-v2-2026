/**
 * State helpdesk Halo Simpeg tingkat modul (bukan Pinia, pola `chatbot/stores/percakapan.ts`): bertahan lintas halaman
 * (RedesignShell dipasang ulang per route), hanya di memori.
 * - `petugasSaya` (`GET petugas/saya`) = sumber tab antrean, panel "Perlu ditindak", centang ingatkan, dan tombol ekspor;
 *   bukan role (F8-06, K-A3).
 * - `ringkasan` = angka badge/entri lonceng helpdesk. **Sementara** kosong sampai README MAKE-071 beku (B1 bagian tiket);
 *   dicocokkan ulang dengan README itu.
 * `resetHaloSimpeg()` dipanggil dari `auth.store.ts` `clearSession()` (AC-E08.8): semua state dikosongkan, dan respons yang
 * tiba sesudah reset dibuang lewat penjaga generasi. Penjaga urutan membuang respons muat lama yang tiba sesudah muat baru.
 */
import { computed, reactive, readonly } from 'vue'

import { isApiError } from '@/lib/axios'

import { petugasHaloService } from '../services/master.service'
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
 * Pasang ringkasan helpdesk (badge menu, tujuan entri lonceng). Sumber = `GET tiket/ringkasan` / respons `POST …/baca`
 * (MAKE-071); sementara belum ada pemanggil di aplikasi.
 */
export function setRingkasanHalo(ringkasan: Ringkasan | null): void {
  state.ringkasan = ringkasan
}

/** Dipanggil dari `clearSession()` (logout / sesi berganti): kosongkan semua state helpdesk. */
export function resetHaloSimpeg(): void {
  generasi += 1
  urutanMuat += 1
  janjiMuat = null
  Object.assign(state, awal())
}
