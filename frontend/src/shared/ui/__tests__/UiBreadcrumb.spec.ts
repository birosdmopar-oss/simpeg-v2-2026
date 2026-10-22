/**
 * UiBreadcrumb (CR-062) — link breadcrumb memakai biru SIMPEG `text-brand-primary` (navy, sama dengan sidebar aktif/navbar),
 * bukan biru aksi `text-brand-tertiary`; ruas terakhir tetap teks biasa dengan aria-current="page".
 */
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'

import { UiBreadcrumb } from '..'

async function mountCrumb(items: Array<{ label: string; to?: string }>) {
  const stub = { render: () => null }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: stub },
      { path: '/laporan', name: 'laporan', component: stub },
    ],
  })
  await router.push('/')
  await router.isReady()
  return mount(UiBreadcrumb, { props: { items }, global: { plugins: [router] } })
}

describe('UiBreadcrumb — warna link biru SIMPEG', () => {
  it('semua link (ikon rumah + teks, dan tautan tengah) memakai text-brand-primary, bukan brand-tertiary; hover underline', async () => {
    const w = await mountCrumb([{ label: 'Home', to: '/' }, { label: 'Laporan', to: '/laporan' }, { label: 'Unit Kerja' }])
    const links = w.findAll('a')

    expect(links.map((l) => l.text())).toEqual(['Home', 'Laporan'])
    for (const link of links) {
      expect(link.classes(), link.text()).toContain('text-brand-primary')
      expect(link.classes(), link.text()).not.toContain('text-brand-tertiary')
      expect(link.classes(), link.text()).toContain('hover:underline')
    }
    // Ikon rumah ada di dalam link pertama dan ikut warna teksnya.
    expect(links[0]?.find('svg').exists()).toBe(true)
    expect(w.html()).not.toContain('brand-tertiary')
  })

  it('ruas terakhir = teks biasa (bukan link) dengan aria-current="page" dan warna netral', async () => {
    const w = await mountCrumb([{ label: 'Home', to: '/' }, { label: 'Usulan Karpeg/Karis' }])
    // Ruas terakhir = <span> (bukan link). Catatan: RouterLink sendiri menambah aria-current pada link rute aktif; yang diuji di sini
    // hanya ruas terakhir komponen.
    const last = w.get('li:last-child span[aria-current="page"]')

    expect(last.element.tagName).toBe('SPAN')
    expect(last.text()).toBe('Usulan Karpeg/Karis')
    expect(last.classes()).toContain('text-slate-600')
    expect(last.classes()).not.toContain('text-brand-primary')
    expect(w.findAll('a')).toHaveLength(1)
  })
})
