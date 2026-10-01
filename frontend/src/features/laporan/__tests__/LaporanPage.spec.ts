/** LaporanPage (§4.1.3) — tiga tipe laporan dari data contoh: Subtotal, pencarian tabel, popup Export. */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import { Role } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { LAPORAN } from '../laporan.mock'
import { laporanService } from '../laporan.service'
import LaporanPage from '../views/LaporanPage.vue'

async function mountPage(tipe: string) {
  loginAs(Role.SUPER_ADMIN)
  const router = createShellRouter()
  await router.push(`/laporan/${tipe}`)
  await router.isReady()
  const wrapper = mount(LaporanPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

const settle = () => new Promise((r) => setTimeout(r, 40))

describe('LaporanPage', () => {
  it('Unit Kerja: Subtotal PNS 1.826 + PPPK 310 = 2.136 (sesuai mockup)', async () => {
    const wrapper = await mountPage('unit-kerja')
    const sub = wrapper.get('[data-testid="laporan-subtotal"]').text()
    expect(sub).toContain('1.826')
    expect(sub).toContain('310')
    expect(sub).toContain('2.136')
    expect(wrapper.findAll('[data-testid^="laporan-row-"]')).toHaveLength(LAPORAN['unit-kerja'].baris.length)
  })

  it('pencarian menyaring baris tabel; tanpa hasil menampilkan keadaan kosong', async () => {
    const wrapper = await mountPage('unit-kerja')
    const input = wrapper.get('[data-testid="laporan-table"]').element.closest('section')!.querySelector('input')!
    input.value = 'Pemasaran'
    input.dispatchEvent(new Event('input'))
    await settle()
    await flushPromises()
    expect(wrapper.findAll('[data-testid^="laporan-row-"]')).toHaveLength(1)
    input.value = 'zzz-tidak-ada'
    input.dispatchEvent(new Event('input'))
    await settle()
    await flushPromises()
    expect(wrapper.find('[data-testid="laporan-empty"]').exists()).toBe(true)
  })

  it('tipe Struktural memakai jenjang jabatan dan tanpa filter unit', async () => {
    const wrapper = await mountPage('struktural')
    expect(wrapper.text()).toContain('Laporan Struktural')
    expect(wrapper.text()).toContain('Eselon II')
    expect(wrapper.text()).not.toContain('Semua unit kerja')
  })

  it('popup Export menawarkan PDF, Excel, dan Cetak Dokumen', async () => {
    const wrapper = await mountPage('jenis-kelamin')
    await wrapper.get('[data-testid="export-trigger"]').trigger('click')
    await flushPromises()
    const formats = [...document.body.querySelectorAll('[data-format]')].map((n) => n.getAttribute('data-format'))
    expect(formats).toEqual(['pdf', 'xlsx', 'print'])
  })
})

describe('laporanService', () => {
  it('filter unit menyempitkan baris; unit tak dikenal → semua baris', async () => {
    const unit = LAPORAN['unit-kerja'].baris[1].label
    expect((await laporanService.get('unit-kerja', unit)).baris).toHaveLength(1)
    expect((await laporanService.get('unit-kerja', 'tak-ada')).baris).toHaveLength(LAPORAN['unit-kerja'].baris.length)
  })

  it('total tiap laporan konsisten (2.136 pegawai)', () => {
    for (const tipe of ['unit-kerja', 'jenis-kelamin', 'struktural'] as const) {
      const total = LAPORAN[tipe].baris.flatMap((b) => b.values).reduce((a, b) => a + b, 0)
      expect(total).toBe(2136)
    }
  })
})
