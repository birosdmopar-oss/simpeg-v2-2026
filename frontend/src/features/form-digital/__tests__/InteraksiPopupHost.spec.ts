/**
 * MAKE-083 — host interaksi di shell (Q7-40, syarat reviewer 11-11):
 * - ACTIVE_PHASE < 7 → tanpa request;
 * - role selain 2/6/7 atau sesi wajib ganti password → tanpa request;
 * - daftar kosong → satu request, tanpa dialog dan tanpa giliran antrean;
 * - ada form → form digital tampil LEBIH DULU, pengingat cuti menunggu sampai form terakhir disimpan (urutan legacy).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/layouts/nav.config', async (asli) => ({ ...(await asli<typeof import('@/shared/layouts/nav.config')>()), ACTIVE_PHASE: 7 }))
vi.mock('../services/formDigital.service', () => ({ formDigitalService: { saya: vi.fn(), isi: vi.fn() } }))
vi.mock('@/features/layanan/services/cuti.service', () => ({ cutiService: { pengingat: vi.fn(), tunda: vi.fn() } }))
vi.mock('@/features/auth/services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn().mockResolvedValue(undefined), changePassword: vi.fn() },
}))

import { Role, type RoleCode } from '@/features/auth/types'
import { cutiService } from '@/features/layanan/services/cuti.service'
import type { CutiItem } from '@/features/layanan/types'
import { resetAntreanDialog } from '@/shared/composables/antreanDialog'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import InteraksiPopupHost from '../components/InteraksiPopupHost.vue'
import { formDigitalService } from '../services/formDigital.service'
import type { FormSaya } from '../types'

const svc = vi.mocked(formDigitalService)
const cuti = vi.mocked(cutiService)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const form = (id: number): FormSaya => ({
  id,
  judul_form: `Form ${id}`,
  keterangan: null,
  tgl_mulai: '2026-11-01',
  tgl_akhir: '2026-11-30',
  form: { versi: 1, pertanyaan: [{ id: 'nama', tipe: 'teks', label: 'Nama', wajib: false }] },
})

async function mountShell(role: RoleCode, extra: Parameters<typeof loginAs>[1] = {}) {
  loginAs(role, extra)
  const router = createShellRouter()
  router.addRoute({ path: '/layanan/cuti/:id', name: 'layanan-cuti-detail', component: { render: () => null } })
  await router.push('/')
  await router.isReady()
  mount(RedesignShell, { slots: { default: '<p>beranda</p>' }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
}

afterEach(() => {
  resetAntreanDialog()
  document.body.innerHTML = ''
  window.sessionStorage.clear()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  window.localStorage.clear()
  svc.saya.mockResolvedValue([])
  svc.isi.mockResolvedValue({ id: 1 })
  cuti.pengingat.mockResolvedValue({ items: [], total: 0 })
})

describe('InteraksiPopupHost', () => {
  it('ACTIVE_PHASE < 7 → tidak memanggil API', async () => {
    loginAs(Role.PEGAWAI)
    mount(InteraksiPopupHost, { props: { fase: 6 } })
    await flushPromises()
    expect(svc.saya).not.toHaveBeenCalled()
  })

  it('role di luar 2/6/7 dan sesi wajib ganti password → tidak memanggil API', async () => {
    for (const role of [Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN]) {
      loginAs(role)
      mount(InteraksiPopupHost, { props: { fase: 7 } }).unmount()
    }
    loginAs(Role.PEGAWAI, { must_change_password: true } as Parameters<typeof loginAs>[1])
    mount(InteraksiPopupHost, { props: { fase: 7 } }).unmount()
    await flushPromises()
    expect(svc.saya).not.toHaveBeenCalled()
  })

  it('tanpa form aktif: satu request, tanpa dialog', async () => {
    await mountShell(Role.PEGAWAI)
    expect(svc.saya).toHaveBeenCalledTimes(1)
    expect(q('[data-testid="form-isian-dialog"]')).toBeNull()
  })

  it('form digital lebih dulu, pengingat cuti menunggu sampai form terakhir disimpan', async () => {
    svc.saya.mockResolvedValue([form(1), form(2)])
    cuti.pengingat.mockResolvedValue({
      items: [
        {
          id: 5,
          nama: 'Budi Santoso',
          nama_jenis_cuti: 'Cuti Tahunan',
          tgl_mulai: '2026-11-04',
          tgl_akhir: '2026-11-06',
          lama_cuti: 3,
          label_status: 'Menunggu Atasan',
          nada_status: 'warning',
          tahap: [],
          aksi: { tunda: true },
        } as unknown as CutiItem,
      ],
      total: 1,
    })
    await mountShell(Role.PEGAWAI)

    expect(q('[data-testid="form-isian-dialog"]')?.textContent).toContain('Form 1')
    expect(q('[data-testid="pengingat-cuti-dialog"]')).toBeNull()

    q<HTMLButtonElement>('[data-testid="form-isian-simpan"]')!.click()
    await flushPromises()
    expect(q('[data-testid="form-isian-dialog"]')?.textContent).toContain('Form 2')
    expect(q('[data-testid="pengingat-cuti-dialog"]')).toBeNull()

    q<HTMLButtonElement>('[data-testid="form-isian-simpan"]')!.click()
    await flushPromises()
    expect(q('[data-testid="form-isian-dialog"]')).toBeNull()
    expect(q('[data-testid="pengingat-cuti-dialog"]')).not.toBeNull()
    expect(svc.isi.mock.calls.map((c) => c[0])).toEqual([1, 2])
  })
})
