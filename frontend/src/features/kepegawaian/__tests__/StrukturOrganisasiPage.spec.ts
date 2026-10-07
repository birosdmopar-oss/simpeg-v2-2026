/**
 * StrukturOrganisasiPage (§4.1.2, B-19) — bagan pohon dari data contoh, pemilih unit (subpohon), zoom, dan Export
 * yang jujur soal backend. Ukuran layout tidak bisa diukur di jsdom (lebar 0) sehingga "fit" tidak mengubah zoom.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import { Role } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { flattenOrg, ORG_TREE } from '../services/pegawai.mock'
import StrukturOrganisasiPage from '../views/StrukturOrganisasiPage.vue'

async function mountPage(role = Role.PEGAWAI) {
  loginAs(role)
  const router = createShellRouter()
  await router.push('/struktur-organisasi')
  await router.isReady()
  const wrapper = mount(StrukturOrganisasiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('StrukturOrganisasiPage', () => {
  it('semua role login boleh melihat (Pegawai) dan seluruh simpul pohon dirender', async () => {
    const wrapper = await mountPage(Role.PEGAWAI)
    const nodes = wrapper.findAll('[data-testid^="org-node-"]')

    expect(nodes).toHaveLength(flattenOrg(ORG_TREE).length)
    expect(wrapper.get('[data-testid="org-node-menteri"]').text()).toContain('Menteri Pariwisata')
    expect(wrapper.get('[data-testid="org-node-menteri"]').text()).toContain(ORG_TREE.nama)
  })

  it('hierarki bersarang: Deputi berada di bawah Wakil Menteri, bukan langsung di bawah Menteri', async () => {
    const wrapper = await mountPage()
    const wamen = wrapper.get('[data-testid="org-node-wamen"]').element.closest('li')
    expect(wamen?.querySelector('[data-testid="org-node-dep-pemasaran"]')).not.toBeNull()

    const root = wrapper.get('[data-testid="org-node-menteri"]').element.closest('li')
    expect(root?.querySelector(':scope > ul')).not.toBeNull()
  })

  it('bagan diberi nama aksesibel', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('ul[aria-label="Bagan struktur organisasi"]').exists()).toBe(true)
    expect(wrapper.find('select[aria-label="Pilih unit organisasi"]').exists()).toBe(true)
  })

  it('memilih unit menampilkan subpohonnya saja; mengosongkan kembali ke seluruh bagan', async () => {
    const wrapper = await mountPage()
    const select = wrapper.get('select[aria-label="Pilih unit organisasi"]')

    await select.setValue('sekmen')
    await flushPromises()
    expect(wrapper.findAll('[data-testid^="org-node-"]')).toHaveLength(4) // Sekmen + 3 anak
    expect(wrapper.find('[data-testid="org-node-menteri"]').exists()).toBe(false)

    await select.setValue('')
    await flushPromises()
    expect(wrapper.findAll('[data-testid^="org-node-"]')).toHaveLength(flattenOrg(ORG_TREE).length)
  })

  it('opsi pilihan unit hanya simpul yang punya anak', async () => {
    const wrapper = await mountPage()
    const values = wrapper.get('select[aria-label="Pilih unit organisasi"]').findAll('option').map((o) => o.attributes('value'))
    expect(values).toEqual(['', 'menteri', 'wamen', 'sekmen'])
  })

  it('zoom: perkecil/perbesar mengubah persentase; tombol terkunci di batas; "sesuaikan" memulihkan', async () => {
    const wrapper = await mountPage()
    const level = () => wrapper.get('[data-testid="zoom-level"]').text()
    expect(level()).toBe('100%')

    await wrapper.get('[data-testid="zoom-out"]').trigger('click')
    expect(level()).toBe('90%')
    await wrapper.get('[data-testid="zoom-in"]').trigger('click')
    await wrapper.get('[data-testid="zoom-in"]').trigger('click')
    expect(level()).toBe('110%')

    for (let i = 0; i < 10; i++) await wrapper.get('[data-testid="zoom-in"]').trigger('click')
    expect(level()).toBe('150%')
    expect(wrapper.get('[data-testid="zoom-in"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="zoom-fit"]').trigger('click')
    await flushPromises()
    expect(level()).toBe('100%') // jsdom: lebar 0 → tidak diskalakan
    expect(wrapper.get('[data-testid="zoom-in"]').attributes('disabled')).toBeUndefined()
  })

  it('zoom minimum 30% mengunci tombol perkecil', async () => {
    const wrapper = await mountPage()
    for (let i = 0; i < 10; i++) await wrapper.get('[data-testid="zoom-out"]').trigger('click')
    expect(wrapper.get('[data-testid="zoom-level"]').text()).toBe('30%')
    expect(wrapper.get('[data-testid="zoom-out"]').attributes('disabled')).toBeDefined()
  })

  it('Export → Export PDF memberi pesan belum tersambung (B-19), bukan berpura-pura mengunduh', async () => {
    const wrapper = await mountPage()
    await wrapper.get('[data-testid="export-trigger"]').trigger('click')
    await flushPromises()
    document.body.querySelector<HTMLElement>('[data-format="pdf"]')?.click()
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toContain('B-19')
  })

  it('breadcrumb Home › Struktur Organisasi', async () => {
    const wrapper = await mountPage()
    expect(wrapper.get('nav[aria-label="Breadcrumb"]').findAll('li').map((li) => li.text())).toEqual(['Home', 'Struktur Organisasi'])
  })
})
