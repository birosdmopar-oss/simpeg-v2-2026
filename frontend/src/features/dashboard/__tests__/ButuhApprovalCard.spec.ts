/**
 * MAKE-087 — kartu Butuh Approval beranda saat lonceng Jalur C dibuka (ACTIVE_PHASE ≥ FASE_LONCENG; ACTIVE_PHASE dimock 7):
 * angka `butuh_approval` dari `useRingkasanNotifikasi()` (format id-ID), tautan ke Pusat Notifikasi tab Butuh Approval;
 * "—" bila ringkasan gagal dimuat (null). Fase < 7 diuji di HomeView.spec.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
  isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'isNetworkError' in v && 'original' in v,
}))
vi.mock('@/shared/layouts/nav.config', async (asli) => ({ ...(await asli<typeof import('@/shared/layouts/nav.config')>()), ACTIVE_PHASE: 7 }))

import { resetRingkasanNotifikasi } from '@/features/notifikasi'
import { api } from '@/lib/axios'

import ButuhApprovalCard from '../components/ButuhApprovalCard.vue'

const get = vi.mocked(api.get)
const stub = { render: () => h('div') }
const RINGKASAN = { notifikasi_belum_dibaca: 2, butuh_approval: 1234, berita_belum_dibaca: null, tiket_belum_dibaca: null, diperbarui_at: '2026-11-10T08:00:00Z' }

async function pasang() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: stub },
      { path: '/notifikasi', name: 'notifikasi', component: stub },
    ],
  })
  await router.push('/')
  await router.isReady()
  const wrapper = mount(ButuhApprovalCard, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  resetRingkasanNotifikasi()
  get.mockReset()
})
afterEach(() => resetRingkasanNotifikasi())
enableAutoUnmount(afterEach)

describe('ButuhApprovalCard — ACTIVE_PHASE ≥ FASE_LONCENG', () => {
  it('angka dari ringkasan Jalur C + tautan ke tab Butuh Approval', async () => {
    get.mockResolvedValue({ data: RINGKASAN } as never)
    const wrapper = await pasang()

    expect(get).toHaveBeenCalledWith('/notifikasi/ringkasan')
    const tautan = wrapper.get('[data-testid="beranda-butuh-approval"] a')
    expect(tautan.attributes('href')).toBe('/notifikasi?tab=butuh-approval')
    expect(tautan.attributes('aria-label')).toBe('Butuh Approval: 1.234 — buka daftar')
    expect(tautan.text()).toContain('1.234')
  })

  it('ringkasan gagal dimuat → "—" (tautan tetap ada)', async () => {
    get.mockRejectedValue({ status: 500, message: 'Gagal.', errors: null, isNetworkError: false, original: new Error('x') })
    const wrapper = await pasang()

    const tautan = wrapper.get('[data-testid="beranda-butuh-approval"] a')
    expect(tautan.text()).toContain('—')
    expect(tautan.attributes('href')).toBe('/notifikasi?tab=butuh-approval')
  })
})
