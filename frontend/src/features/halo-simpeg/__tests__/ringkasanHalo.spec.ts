/**
 * Ringkasan helpdesk + gerbang polling (AC-E08.11): `GET tiket/ringkasan` hanya bila `ACTIVE_PHASE >= 8`, role 1–7, dan
 * tidak wajib ganti password; syarat berubah → polling berhenti + angka dikosongkan (`resetHaloSimpeg`). Polling 60 detik,
 * muat ulang lembut tanpa request ganda, penjaga generasi/urutan di store.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const fase = vi.hoisted(() => ({ aktif: 8 }))
vi.mock('@/shared/layouts/nav.config', async (asli) => ({
  ...(await asli<typeof import('@/shared/layouts/nav.config')>()),
  get ACTIVE_PHASE() {
    return fase.aktif
  },
}))
const svc = vi.hoisted(() => ({ ringkasan: vi.fn(), baca: vi.fn() }))
vi.mock('../services/tiket.service', async (asli) => ({
  ...(await asli<typeof import('../services/tiket.service')>()),
  tiketHaloService: svc,
}))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode, type User } from '@/features/auth/types'

import { INTERVAL_RINGKASAN_MS } from '../constants'
import { resetPolling, useRingkasanHalo } from '../composables/useRingkasanHalo'
import { bacaTiket, haloState, muatRingkasan, resetHaloSimpeg, setRingkasanHalo } from '../stores/haloSimpeg'
import type { Ringkasan } from '../types'
import tiketRingkasan from './fixtures/tiket-ringkasan.json'

const RINGKASAN = tiketRingkasan.data as Ringkasan

function login(role: RoleCode, mustChange = false): void {
  useAuthStore().setSession({ username: 'uji', user_level: role, must_change_password: mustChange } as User)
}

function pasang() {
  let api: ReturnType<typeof useRingkasanHalo> | null = null
  const w = mount(
    defineComponent({
      setup() {
        const r = useRingkasanHalo()
        api = r
        return () => h('span', { 'data-testid': 'badge' }, String(r.badge.value))
      },
    }),
  )
  return { w, api: () => api! }
}

function tertunda<T>() {
  let lepas: (v: T) => void = () => {}
  const janji = new Promise<T>((r) => (lepas = r))
  return { janji, lepas }
}

beforeEach(() => {
  setActivePinia(createPinia())
  fase.aktif = 8
  resetPolling()
  resetHaloSimpeg()
  svc.ringkasan.mockReset().mockResolvedValue(RINGKASAN)
  svc.baca.mockReset()
})

afterEach(() => {
  vi.useRealTimers()
  resetPolling()
})

describe('gerbang polling ringkasan (AC-E08.11)', () => {
  it('role 2, fase 8 → tepat satu request; badge = Ringkasan.badge (bukan total)', async () => {
    login(Role.PEGAWAI)
    const { w } = pasang()
    await flushPromises()
    expect(svc.ringkasan).toHaveBeenCalledTimes(1)
    expect(w.get('[data-testid="badge"]').text()).toBe(String(RINGKASAN.badge))
    expect(RINGKASAN.badge).toBeGreaterThan(RINGKASAN.total)
  })

  it.each([
    ['role 8 (Pimpinan)', () => login(Role.PIMPINAN)],
    ['wajib ganti password', () => login(Role.PEGAWAI, true)],
    ['ACTIVE_PHASE 5', () => {
      fase.aktif = 5
      login(Role.PEGAWAI)
    }],
    ['tanpa sesi', () => undefined],
  ])('%s → tanpa request, badge 0', async (_n, siapkan) => {
    siapkan()
    const { w, api } = pasang()
    api().muatUlang()
    await flushPromises()
    expect(svc.ringkasan).not.toHaveBeenCalled()
    expect(w.get('[data-testid="badge"]').text()).toBe('0')
  })

  it('syarat berubah (role 2 → wajib ganti password) → polling berhenti dan angka dikosongkan; kembali → dimulai lagi', async () => {
    vi.useFakeTimers()
    login(Role.PEGAWAI)
    const { w } = pasang()
    await flushPromises()
    expect(haloState.ringkasan).toEqual(RINGKASAN)
    login(Role.PEGAWAI, true)
    await flushPromises()
    expect(haloState.ringkasan).toBeNull()
    expect(w.get('[data-testid="badge"]').text()).toBe('0')
    await vi.advanceTimersByTimeAsync(INTERVAL_RINGKASAN_MS * 2)
    expect(svc.ringkasan).toHaveBeenCalledTimes(1)
    login(Role.PEGAWAI)
    await flushPromises()
    expect(svc.ringkasan).toHaveBeenCalledTimes(2)
  })

  it('polling 60 detik selama aktif; berhenti saat pemakai terakhir dilepas', async () => {
    vi.useFakeTimers()
    login(Role.SUPER_ADMIN)
    const { w } = pasang()
    await flushPromises()
    await vi.advanceTimersByTimeAsync(INTERVAL_RINGKASAN_MS)
    expect(svc.ringkasan).toHaveBeenCalledTimes(2)
    w.unmount()
    await vi.advanceTimersByTimeAsync(INTERVAL_RINGKASAN_MS * 2)
    expect(svc.ringkasan).toHaveBeenCalledTimes(2)
  })

  it('shell dipasang ulang dengan urutan nyata (unmount lama → mount baru): satu request, satu timer (review B1 N4)', async () => {
    vi.useFakeTimers()
    const t = tertunda<Ringkasan>()
    svc.ringkasan.mockReturnValueOnce(t.janji)
    login(Role.PEGAWAI)
    const a = pasang()
    a.w.unmount()
    const b = pasang()
    t.lepas(RINGKASAN)
    await flushPromises()
    expect(svc.ringkasan).toHaveBeenCalledTimes(1)
    expect(b.w.get('[data-testid="badge"]').text()).toBe(String(RINGKASAN.badge))
    await vi.advanceTimersByTimeAsync(INTERVAL_RINGKASAN_MS)
    expect(svc.ringkasan).toHaveBeenCalledTimes(2)
  })

  it('muat ulang saat request berjalan tidak menggandakan', async () => {
    const t = tertunda<Ringkasan>()
    svc.ringkasan.mockReturnValueOnce(t.janji)
    login(Role.PEGAWAI)
    const { api } = pasang()
    api().muatUlang()
    api().muatUlang()
    t.lepas(RINGKASAN)
    await flushPromises()
    expect(svc.ringkasan).toHaveBeenCalledTimes(1)
  })
})

describe('store ringkasan', () => {
  it('galat → angka terakhir dipertahankan', async () => {
    setRingkasanHalo(RINGKASAN)
    svc.ringkasan.mockRejectedValueOnce(new Error('jaringan'))
    await muatRingkasan()
    expect(haloState.ringkasan).toEqual(RINGKASAN)
  })

  it('respons sesudah reset dibuang; setRingkasanHalo (POST baca) menang atas muat yang lebih lama', async () => {
    const t = tertunda<Ringkasan>()
    svc.ringkasan.mockReturnValueOnce(t.janji)
    const muat = muatRingkasan()
    resetHaloSimpeg()
    t.lepas(RINGKASAN)
    await muat
    expect(haloState.ringkasan).toBeNull()

    const t2 = tertunda<Ringkasan>()
    svc.ringkasan.mockReturnValueOnce(t2.janji)
    const muat2 = muatRingkasan()
    const dariBaca = { ...RINGKASAN, badge: 0, perlu_tindak: 0 }
    setRingkasanHalo(dariBaca)
    t2.lepas(RINGKASAN)
    await muat2
    expect(haloState.ringkasan).toEqual(dariBaca)
  })
})

describe('bacaTiket — penjaga generasi (review B1 N2)', () => {
  it('sukses → ringkasan dari respons POST baca terpasang', async () => {
    const dariBaca = { ...RINGKASAN, badge: 0, perlu_tindak: 0 }
    svc.baca.mockResolvedValueOnce(dariBaca)
    await expect(bacaTiket(5)).resolves.toEqual(dariBaca)
    expect(svc.baca).toHaveBeenCalledWith(5)
    expect(haloState.ringkasan).toEqual(dariBaca)
  })

  it('baca tertunda → reset (logout) → respons tiba → ringkasan tetap null', async () => {
    const t = tertunda<Ringkasan>()
    svc.baca.mockReturnValueOnce(t.janji)
    const baca = bacaTiket(5)
    resetHaloSimpeg()
    t.lepas(RINGKASAN)
    await expect(baca).resolves.toBeNull()
    expect(haloState.ringkasan).toBeNull()
  })

  it('baca A tertunda → reset → GET B mulai → baca A tiba → hasil GET B tetap terpasang', async () => {
    const a = tertunda<Ringkasan>()
    const b = tertunda<Ringkasan>()
    svc.baca.mockReturnValueOnce(a.janji)
    svc.ringkasan.mockReturnValueOnce(b.janji)
    const bacaA = bacaTiket(5)
    resetHaloSimpeg()
    const muatB = muatRingkasan()
    a.lepas({ ...RINGKASAN, badge: 9 })
    await bacaA
    const ringkasanB = { ...RINGKASAN, badge: 2 }
    b.lepas(ringkasanB)
    await muatB
    expect(haloState.ringkasan).toEqual(ringkasanB)
  })
})

describe('tab tersembunyi dan pelepasan listener (review B1 N3)', () => {
  const terlihatSebagai = (v: DocumentVisibilityState) => vi.spyOn(document, 'visibilityState', 'get').mockReturnValue(v)
  const ubahTerlihat = () => document.dispatchEvent(new Event('visibilitychange'))

  afterEach(() => vi.restoreAllMocks())

  it('tab tersembunyi → interval tidak memanggil; terlihat lagi + visibilitychange → tepat satu panggilan', async () => {
    vi.useFakeTimers()
    login(Role.PEGAWAI)
    pasang()
    await flushPromises()
    expect(svc.ringkasan).toHaveBeenCalledTimes(1)
    terlihatSebagai('hidden')
    await vi.advanceTimersByTimeAsync(INTERVAL_RINGKASAN_MS)
    ubahTerlihat()
    await flushPromises()
    expect(svc.ringkasan).toHaveBeenCalledTimes(1)
    terlihatSebagai('visible')
    ubahTerlihat()
    await flushPromises()
    expect(svc.ringkasan).toHaveBeenCalledTimes(2)
  })

  it.each([
    ['unmount pemakai terakhir', (w: { unmount: () => void }) => w.unmount()],
    ['logout (clearSession)', () => useAuthStore().clearSession()],
    ['wajib ganti password', () => login(Role.PEGAWAI, true)],
  ])('sesudah %s → visibilitychange + interval tidak memanggil lagi', async (_n, akhiri) => {
    vi.useFakeTimers()
    login(Role.PEGAWAI)
    const { w } = pasang()
    await flushPromises()
    expect(svc.ringkasan).toHaveBeenCalledTimes(1)
    akhiri(w)
    await flushPromises()
    terlihatSebagai('visible')
    ubahTerlihat()
    await vi.advanceTimersByTimeAsync(INTERVAL_RINGKASAN_MS * 2)
    expect(svc.ringkasan).toHaveBeenCalledTimes(1)
  })
})
