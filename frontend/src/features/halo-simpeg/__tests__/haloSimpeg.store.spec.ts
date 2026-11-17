/**
 * State modul helpdesk (B1): `petugas/saya` sebagai sumber tab antrean/ekspor (bukan role), cache sekali per sesi, penjaga
 * generasi (respons sesudah `resetHaloSimpeg()` dibuang) dan urutan (respons muat lama dibuang), serta reset dari
 * `clearSession()` (AC-E08.8).
 */
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const svc = vi.hoisted(() => ({ saya: vi.fn() }))
vi.mock('../services/master.service', async (asli) => ({
  ...(await asli<typeof import('../services/master.service')>()),
  petugasHaloService: svc,
}))

import { useAuthStore } from '@/features/auth/stores/auth.store'

import { bisaEkspor, haloState, muatPetugasSaya, petugasAktif, resetHaloSimpeg } from '../stores/haloSimpeg'
import type { PetugasSaya } from '../types'
import sayaAktif from './fixtures/petugas-saya-aktif.json'
import sayaBukan from './fixtures/petugas-saya-bukan.json'
import sayaNonaktif from './fixtures/petugas-saya-nonaktif.json'

const AKTIF = sayaAktif.data as PetugasSaya
const NONAKTIF = sayaNonaktif.data as PetugasSaya
const BUKAN = sayaBukan.data as PetugasSaya

function tertunda<T>() {
  let lepas: (v: T) => void = () => {}
  const janji = new Promise<T>((r) => (lepas = r))
  return { janji, lepas }
}

beforeEach(() => {
  setActivePinia(createPinia())
  resetHaloSimpeg()
  svc.saya.mockReset()
})

describe('state helpdesk — petugas/saya', () => {
  it('petugas aktif → petugasAktif + bisaEkspor; dimuat sekali (cache), paksa memuat ulang', async () => {
    svc.saya.mockResolvedValue(AKTIF)
    await Promise.all([muatPetugasSaya(), muatPetugasSaya()])
    await muatPetugasSaya()
    expect(svc.saya).toHaveBeenCalledTimes(1)
    expect(haloState.statusPetugasSaya).toBe('siap')
    expect(petugasAktif.value).toBe(true)
    expect(bisaEkspor.value).toBe(true)
    await muatPetugasSaya(true)
    expect(svc.saya).toHaveBeenCalledTimes(2)
  })

  it.each([
    ['petugas nonaktif', NONAKTIF],
    ['bukan petugas', BUKAN],
  ])('%s → tanpa antrean dan tanpa ekspor', async (_n, data) => {
    svc.saya.mockResolvedValue(data)
    await muatPetugasSaya()
    expect(petugasAktif.value).toBe(false)
    expect(bisaEkspor.value).toBe(false)
  })

  it('galat → status galat + pesan amplop; belum siap sehingga muat berikutnya mencoba lagi', async () => {
    svc.saya.mockRejectedValueOnce({ status: 500, message: 'Terjadi kesalahan server.', errors: null, isNetworkError: false, original: new AxiosError('x') })
    await muatPetugasSaya()
    expect(haloState.statusPetugasSaya).toBe('galat')
    expect(haloState.galatPetugasSaya).toBe('Terjadi kesalahan server.')
    svc.saya.mockResolvedValueOnce(AKTIF)
    await muatPetugasSaya()
    expect(haloState.statusPetugasSaya).toBe('siap')
  })

  it('penjaga urutan: respons muat lama yang tiba sesudah muat baru dibuang', async () => {
    const lama = tertunda<PetugasSaya>()
    svc.saya.mockReturnValueOnce(lama.janji).mockResolvedValueOnce(NONAKTIF)
    const pertama = muatPetugasSaya()
    await muatPetugasSaya(true)
    lama.lepas(AKTIF)
    await pertama
    expect(haloState.petugasSaya).toEqual(NONAKTIF)
  })

  it('resetHaloSimpeg: state kosong dan respons yang tiba sesudah reset dibuang', async () => {
    const t = tertunda<PetugasSaya>()
    svc.saya.mockReturnValueOnce(t.janji)
    const muat = muatPetugasSaya()
    resetHaloSimpeg()
    t.lepas(AKTIF)
    await muat
    expect(haloState.petugasSaya).toBeNull()
    expect(haloState.statusPetugasSaya).toBe('awal')
    expect(petugasAktif.value).toBe(false)
  })

  it('clearSession() (logout) memanggil resetHaloSimpeg (AC-E08.8)', async () => {
    svc.saya.mockResolvedValue(AKTIF)
    await muatPetugasSaya()
    expect(petugasAktif.value).toBe(true)
    useAuthStore().clearSession()
    expect(haloState.petugasSaya).toBeNull()
    expect(haloState.ringkasan).toBeNull()
    expect(haloState.statusPetugasSaya).toBe('awal')
  })
})
