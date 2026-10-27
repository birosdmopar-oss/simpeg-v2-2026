/**
 * UiSelect `size` (CR-063): `md` (default, form/dialog) = h-11 / text-body1 / rounded-lg; `sm` = SAMA PERSIS dengan kotak
 * pencarian di baris filter (py-2, text-sm, rounded-md, tanpa tinggi tetap) supaya dropdown dan pencarian sejajar setinggi.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import UiSelect from '../UiSelect.vue'

const options = [{ value: '1', label: 'Aktif' }]
/** Utilitas kotak pencarian di baris filter (MasterDataView/HariLiburView/UserManagementView). */
const KOTAK_PENCARIAN = ['py-2', 'text-sm', 'rounded-md', 'border']

const trigger = (size?: 'md' | 'sm', extra: Record<string, unknown> = {}) =>
  mount(UiSelect, { props: { options, ariaLabel: 'Uji', ...(size ? { size } : {}), ...extra } }).get('[role="combobox"]')

describe('UiSelect size', () => {
  it('default = md: h-11, text-body1, rounded-lg (perilaku form/dialog tidak berubah)', () => {
    for (const t of [trigger(), trigger('md')]) {
      expect(t.classes()).toEqual(expect.arrayContaining(['h-11', 'text-body1', 'rounded-lg', 'border']))
      expect(t.classes()).not.toContain('text-sm')
      expect(t.classes()).not.toContain('rounded-md')
    }
  })

  it('sm = utilitas kotak pencarian (py-2, text-sm, rounded-md, border), tanpa h-11/text-body1/rounded-lg', () => {
    const t = trigger('sm')
    expect(t.classes()).toEqual(expect.arrayContaining(KOTAK_PENCARIAN))
    for (const tidak of ['h-11', 'text-body1', 'rounded-lg']) expect(t.classes(), tidak).not.toContain(tidak)
  })

  it('sm juga berlaku pada mode searchable (kolom ketik) dan trigger terkunci; chevron tetap di tengah vertikal', () => {
    const w = mount(UiSelect, { props: { options, ariaLabel: 'Uji', size: 'sm', searchable: true } })
    expect(w.get('input[role="combobox"]').classes()).toEqual(expect.arrayContaining(KOTAK_PENCARIAN))

    const mati = mount(UiSelect, { props: { options, ariaLabel: 'Uji', size: 'sm', disabled: true } })
    expect(mati.get('[role="combobox"]').classes()).toEqual(expect.arrayContaining(['py-2', 'text-sm', 'rounded-md']))
    const chevron = mati.get('svg')
    expect(chevron.classes()).toEqual(expect.arrayContaining(['top-1/2', '-translate-y-1/2']))
  })
})
