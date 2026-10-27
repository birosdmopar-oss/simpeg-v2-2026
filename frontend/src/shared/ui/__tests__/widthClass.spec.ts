/**
 * CR-060 (regresi tata letak baris filter): akar UiSelect/UiTextField memakai `w-full` HANYA bila pemanggil tidak memberi
 * kelas lebar. Sebelumnya `class="w-52"` ikut ditambahkan menjadi `w-full w-52` dan `w-full` menang (CSS), sehingga filter
 * melebar penuh dan turun ke baris sendiri.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import UiSelect from '../UiSelect.vue'
import UiTextField from '../UiTextField.vue'
import { hasWidthClass } from '../widthClass'

const options = [{ value: '1', label: 'Aktif' }]

describe('hasWidthClass', () => {
  it('mengenali kelas lebar (string, array, objek, varian responsif) dan mengabaikan kelas lain', () => {
    for (const yes of ['w-52', 'mt-2 w-40', 'md:w-1/2', ['px-2', 'w-56'], { 'w-44': true }, 'basis-1/3', 'flex-1', 'sm:flex-auto', 'w-full']) {
      expect(hasWidthClass(yes), JSON.stringify(yes)).toBe(true)
    }
    for (const no of ['', 'mt-2', 'max-w-xs', 'min-w-0', 'md:col-span-2', 'flex flex-col', ['px-2'], { 'w-44': false }, undefined, null]) {
      expect(hasWidthClass(no), JSON.stringify(no)).toBe(false)
    }
  })
})

describe('akar komponen form dan kelas lebar pemanggil', () => {
  it('UiSelect: tanpa kelas lebar → w-full; dengan w-52 → w-52 saja (tanpa w-full)', () => {
    expect(mount(UiSelect, { props: { options } }).classes()).toContain('w-full')

    const sempit = mount(UiSelect, { props: { options }, attrs: { class: 'w-52' } })
    expect(sempit.classes()).toContain('w-52')
    expect(sempit.classes()).not.toContain('w-full')
  })

  it('UiSelect: kelas responsif/flex dan class bentuk array juga menggantikan w-full; max-w tetap bersama w-full', () => {
    for (const cls of ['md:w-1/2', 'flex-1', ['w-40']]) {
      expect(mount(UiSelect, { props: { options }, attrs: { class: cls } }).classes(), JSON.stringify(cls)).not.toContain('w-full')
    }
    expect(mount(UiSelect, { props: { options }, attrs: { class: 'max-w-xs' } }).classes()).toEqual(expect.arrayContaining(['w-full', 'max-w-xs']))
  })

  it('UiTextField (date/time/text): sama — w-52 dari pemanggil berlaku tanpa w-full', () => {
    for (const type of ['text', 'date', 'time']) {
      const w = mount(UiTextField, { props: { type }, attrs: { class: 'w-52' } })
      expect(w.classes(), type).toContain('w-52')
      expect(w.classes(), type).not.toContain('w-full')
    }
    expect(mount(UiTextField, { props: { type: 'text' } }).classes()).toContain('w-full')
  })
})
