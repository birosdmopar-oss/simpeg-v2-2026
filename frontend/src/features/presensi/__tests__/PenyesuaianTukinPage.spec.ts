/**
 * MAKE-050 (PRD 5C §7.4, AC-FE5C.6): Penyesuaian Tukin — daftar, ⋮ Edit → Hapus (danger + ConfirmDialog), dialog tambah
 * dengan galat 422 per field, dan hanya role 1. Service di-mock; bentuk amplop diuji di penyesuaianTukin.amplop.spec.ts.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/penyesuaianTukin.service', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../services/penyesuaianTukin.service')>()
  return {
    ...actual,
    penyesuaianTukinService: { daftar: vi.fn(), tambah: vi.fn(), ubah: vi.fn(), hapus: vi.fn() },
  }
})

import { Role } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { penyesuaianTukinService, type PenyesuaianTukin } from '../services/penyesuaianTukin.service'
import PenyesuaianTukinPage from '../views/PenyesuaianTukinPage.vue'
import { PENYESUAIAN_TUKIN_ROLES } from '../roles'
import { presensiRoutes } from '../routes.presensi'

const svc = vi.mocked(penyesuaianTukinService)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const baris = (over: Partial<PenyesuaianTukin> = {}): PenyesuaianTukin => ({
  id: 4,
  nip: '198001012005011001',
  nama: 'Budi Santoso',
  bulan_mulai: '2024-04',
  bulan_akhir: '2024-06',
  persen: '-20.00',
  alasan: 'Predikat Kinerja Sangat Kurang',
  status: 1,
  created_at: null,
  updated_at: null,
  ...over,
})

async function mountPage(role: number = Role.SUPER_ADMIN, items: PenyesuaianTukin[] = [baris()]) {
  loginAs(role as never)
  svc.daftar.mockResolvedValue({ items, total: items.length, page: 1, per_page: 25 })
  const router = createShellRouter()
  router.addRoute({ path: '/presensi/tukin/penyesuaian', name: 'presensi-penyesuaian-tukin', component: PenyesuaianTukinPage })
  await router.push('/presensi/tukin/penyesuaian')
  await router.isReady()
  const wrapper = mount(PenyesuaianTukinPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper }
}

const isiInput = async (name: string, nilai: string) => {
  const input = q<HTMLInputElement>(`input[name="${name}"]`)
  if (!input) throw new Error(`input ${name} tidak ada`)
  input.value = nilai
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

afterEach(() => {
  document.body.innerHTML = ''
  window.sessionStorage.clear()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  svc.daftar.mockReset()
  svc.tambah.mockReset()
  svc.ubah.mockReset()
  svc.hapus.mockReset()
})

describe('akses role', () => {
  it('hanya role 1 (Super Admin) yang punya route dan menu', () => {
    expect(PENYESUAIAN_TUKIN_ROLES).toEqual([Role.SUPER_ADMIN])
    const rute = presensiRoutes.find((r) => r.name === 'presensi-penyesuaian-tukin')
    expect(rute?.meta?.roles).toEqual([Role.SUPER_ADMIN])
  })
})

describe('daftar penyesuaian', () => {
  it('menampilkan baris, periode, persen dengan koma desimal, dan badge status', async () => {
    const { wrapper } = await mountPage()
    expect(wrapper.find('[data-testid="penyesuaian-tukin-baris-4"]').text()).toContain('April 2024 s.d. Juni 2024')
    expect(wrapper.find('[data-testid="penyesuaian-tukin-baris-4"]').text()).toContain('-20,00%')
    expect(wrapper.find('[data-testid="penyesuaian-tukin-status-4"]').text()).toBe('Aktif')
  })

  it('label ⋮ memakai nama pegawai dan periode, testid penyesuaian-actions-<id>', async () => {
    const { wrapper } = await mountPage()
    const tombol = wrapper.get('[data-testid="penyesuaian-actions-4"]')
    expect(tombol.attributes('aria-label')).toBe('Aksi untuk penyesuaian Budi Santoso April 2024 s.d. Juni 2024')
  })

  it('item ⋮ berurutan Edit lalu Hapus (danger)', async () => {
    const { wrapper } = await mountPage()
    const menu = await rowMenuActions(wrapper, 'penyesuaian-actions-4')
    expect(menu.map((m) => [m.key, m.label, m.danger])).toEqual([
      ['edit', 'Edit', false],
      ['hapus', 'Hapus', true],
    ])
  })

  it('tabel kosong menampilkan pesan kosong', async () => {
    const { wrapper } = await mountPage(Role.SUPER_ADMIN, [])
    expect(wrapper.find('[data-testid="penyesuaian-tukin-kosong"]').exists()).toBe(true)
  })
})

describe('hapus lewat ⋮ dengan konfirmasi', () => {
  it('memilih Hapus membuka ConfirmDialog; konfirmasi memanggil hapus(id) dan memuat ulang', async () => {
    svc.hapus.mockResolvedValue(undefined)
    const { wrapper } = await mountPage()
    await selectRowAction(wrapper, 'penyesuaian-actions-4', 'hapus')
    await flushPromises()

    expect(svc.hapus).not.toHaveBeenCalled()
    const konfirm = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')
    expect(konfirm).toBeTruthy()
    konfirm?.click()
    await flushPromises()

    expect(svc.hapus).toHaveBeenCalledWith(4)
    expect(svc.daftar).toHaveBeenCalledTimes(2)
  })
})

describe('tambah dan ubah', () => {
  it('Edit membuka dialog terisi; NIP terkunci dan status tampil', async () => {
    const { wrapper } = await mountPage()
    await selectRowAction(wrapper, 'penyesuaian-actions-4', 'edit')
    await flushPromises()

    expect(q('[data-testid="penyesuaian-tukin-dialog"]')).not.toBeNull()
    expect(q<HTMLInputElement>('input[name="nip"]')?.disabled).toBe(true)
    expect(q<HTMLInputElement>('input[name="persen"]')?.value).toBe('-20.00')
    expect(q('[data-testid="penyesuaian-tukin-status"]')).not.toBeNull()
  })

  it('galat 422 per field tampil di bawah field terkait', async () => {
    const { wrapper } = await mountPage(Role.SUPER_ADMIN, [])
    svc.tambah.mockRejectedValue({
      isNetworkError: false,
      original: null,
      status: 422,
      message: 'Data tidak valid.',
      errors: { bulan_mulai: ['Bulan mulai minimal 2024-04.'] },
    })
    await wrapper.get('[data-testid="penyesuaian-tukin-tambah"]').trigger('click')
    await flushPromises()
    await isiInput('nip', '198001012005011001')
    await isiInput('bulan_mulai', '2024-01')
    await isiInput('bulan_akhir', '2024-02')
    await isiInput('persen', '-20')
    await isiInput('alasan', 'Uji')
    await q('[data-testid="penyesuaian-tukin-form"]')?.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()

    expect(document.body.textContent).toContain('Bulan mulai minimal 2024-04.')
    expect(document.body.textContent).toContain('Periksa kembali isian yang ditandai.')
  })
})
