/**
 * UiCard `fill` (CR-069): kartu mengisi sisa tinggi halaman isi-penuh; badannya menjadi kolom flex agar `.table-fill`
 * (area gulir tabel) memperoleh tinggi tertentu dan header kartu tetap terlihat. Tanpa `fill`, tata letak lama dipakai.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import UiCard from '../UiCard.vue'

describe('UiCard fill (CR-069)', () => {
  it('tanpa fill: kartu & badan tidak memakai flex (perilaku lama)', () => {
    const w = mount(UiCard, { props: { title: 'Biasa' }, slots: { default: '<p>isi</p>' } })
    expect(w.element.className).not.toContain('flex-1')
    expect(w.find('section > div').classes()).not.toContain('flex-col')
  })

  it('fill: kartu dan badan menjadi kolom flex yang bisa menyusutkan tinggi (min-h-0)', () => {
    const w = mount(UiCard, { props: { title: 'Tabel', fill: true }, slots: { default: '<div class="table-fill">x</div>' } })
    const card = w.element as HTMLElement
    const body = w.find('section > div')
    expect(card.className).toContain('flex-1')
    expect(card.className).toContain('min-h-0')
    expect(body.classes()).toEqual(expect.arrayContaining(['flex', 'min-h-0', 'flex-1', 'flex-col']))
    expect(body.find('.table-fill').exists()).toBe(true)
  })
})
