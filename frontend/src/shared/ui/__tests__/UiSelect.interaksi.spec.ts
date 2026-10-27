/**
 * UiSelect custom (AGENTS.md bagian 3, CR-060) — interaksi pengguna nyata: klik trigger → pilih opsi lewat klik dan
 * lewat keyboard, ARIA combobox/listbox/option, typeahead, opsi disabled, clearable, searchable, cermin <select> native
 * tersembunyi, dan di dalam dialog (daftar tidak menutup dialog, Esc menutup daftar dulu).
 * Daftar opsi dirender di portal <body>, jadi dicari lewat `document`.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { DialogContent, DialogDescription, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, ref } from 'vue'

import UiSelect from '../UiSelect.vue'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

const OPTIONS = [
  { value: 'islam', label: 'Islam' },
  { value: 'kristen', label: 'Kristen' },
  { value: 'katolik', label: 'Katolik', disabled: true },
  { value: 'hindu', label: 'Hindu' },
  { value: 'buddha', label: 'Buddha' },
]

const q = <E extends Element = HTMLElement>(sel: string) => document.body.querySelector<E>(sel)
const listbox = () => q('[role="listbox"]')
const optionEls = () => [...document.body.querySelectorAll<HTMLElement>('[role="option"]')]
const optionLabels = () => optionEls().map((o) => o.textContent?.trim())

function mountSelect(props: Record<string, unknown> = {}) {
  return mount(UiSelect, { props: { label: 'Agama', options: OPTIONS, modelValue: '', ...props }, attachTo: document.body })
}

const trigger = (w: ReturnType<typeof mountSelect>) => w.get('[role="combobox"]')

async function key(el: Element, k: string, init: KeyboardEventInit = {}): Promise<void> {
  el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...init }))
  await flushPromises()
}

describe('UiSelect custom — tampilan dan ARIA', () => {
  it('yang terlihat/berfokus hanya trigger combobox; <select> native adalah cermin tersembunyi (aria-hidden, tabindex -1, sr-only)', () => {
    const w = mountSelect({ name: 'agama', modelValue: 'hindu' })
    const t = trigger(w)

    expect(t.element.tagName).toBe('BUTTON')
    expect(t.attributes('aria-haspopup')).toBe('listbox')
    expect(t.attributes('aria-expanded')).toBe('false')
    expect(t.text()).toBe('Hindu')

    const mirror = w.get('select[name="agama"]')
    expect(mirror.attributes('aria-hidden')).toBe('true')
    expect(mirror.attributes('tabindex')).toBe('-1')
    expect(mirror.classes()).toContain('sr-only')
    expect((mirror.element as HTMLSelectElement).value).toBe('hindu')
    // Label menunjuk ke trigger (bukan ke cermin).
    expect(w.get('label').attributes('for')).toBe(t.attributes('id'))
  })

  it('placeholder tampil di trigger saat kosong; tanpa label memakai ariaLabel; required/invalid/help terhubung', () => {
    const w = mountSelect({ label: '', ariaLabel: 'Jumlah baris', placeholder: 'Pilih agama', required: true, state: 'error', helpText: 'Wajib dipilih' })
    const t = trigger(w)

    expect(t.text()).toBe('Pilih agama')
    expect(t.attributes('aria-label')).toBe('Jumlah baris')
    expect(t.attributes('aria-required')).toBe('true')
    expect(t.attributes('aria-invalid')).toBe('true')
    expect(w.get('[role="alert"]').text()).toBe('Wajib dipilih')
    expect(t.attributes('aria-describedby')).toBe(w.get('[role="alert"]').attributes('id'))
  })

  it('klik trigger membuka listbox berisi role=option (aria-selected + aria-disabled) dan menutupnya lagi dengan klik trigger', async () => {
    const w = mountSelect({ modelValue: 'kristen' })
    const t = trigger(w)

    await t.trigger('click')
    expect(t.attributes('aria-expanded')).toBe('true')
    expect(listbox()).not.toBeNull()
    expect(t.attributes('aria-controls')).toBe(listbox()?.id)
    expect(optionLabels()).toEqual(['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha'])

    const byLabel = (label: string) => optionEls().find((o) => o.textContent?.trim() === label) as HTMLElement
    expect(byLabel('Kristen').getAttribute('aria-selected')).toBe('true')
    expect(byLabel('Islam').getAttribute('aria-selected')).toBe('false')
    expect(byLabel('Katolik').getAttribute('aria-disabled')).toBe('true')
    // Fokus tetap di trigger; opsi aktif ditunjuk lewat aria-activedescendant.
    expect(t.attributes('aria-activedescendant')).toBe(byLabel('Kristen').id)

    await t.trigger('click')
    expect(t.attributes('aria-expanded')).toBe('false')
    expect(listbox()).toBeNull()
  })
})

describe('UiSelect custom — memilih lewat klik', () => {
  it('klik opsi mengirim update:modelValue, menutup daftar, dan cermin native ikut nilai baru', async () => {
    const w = mountSelect()
    await trigger(w).trigger('click')

    optionEls().find((o) => o.textContent?.trim() === 'Hindu')?.click()
    await flushPromises()

    expect(w.emitted('update:modelValue')?.[0]).toEqual(['hindu'])
    expect(listbox()).toBeNull()

    await w.setProps({ modelValue: 'hindu' })
    expect((w.get('select').element as HTMLSelectElement).value).toBe('hindu')
    expect(trigger(w).text()).toBe('Hindu')
  })

  it('opsi disabled tidak bisa dipilih lewat klik', async () => {
    const w = mountSelect()
    await trigger(w).trigger('click')

    optionEls().find((o) => o.textContent?.trim() === 'Katolik')?.click()
    await flushPromises()

    expect(w.emitted('update:modelValue')).toBeUndefined()
    expect(listbox()).not.toBeNull()
  })

  it('perubahan lewat cermin native (alat/test lama) tetap mengirim update:modelValue', async () => {
    const w = mountSelect()
    await w.get('select').setValue('buddha')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['buddha'])
  })
})

describe('UiSelect custom — keyboard', () => {
  it('Enter/Spasi/ArrowDown membuka; panah memindahkan opsi aktif melewati opsi disabled; Enter memilih', async () => {
    const w = mountSelect()
    const t = trigger(w)
    ;(t.element as HTMLElement).focus()

    await key(t.element, 'ArrowDown')
    expect(listbox()).not.toBeNull()
    expect(optionEls()[0]?.getAttribute('data-active')).toBe('true')

    await key(t.element, 'ArrowDown') // → Kristen
    await key(t.element, 'ArrowDown') // Katolik disabled dilewati → Hindu
    expect(optionEls().find((o) => o.hasAttribute('data-active'))?.textContent?.trim()).toBe('Hindu')
    expect(t.attributes('aria-activedescendant')).toBe(optionEls()[3]?.id)

    await key(t.element, 'Enter')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['hindu'])
    expect(listbox()).toBeNull()
    expect(document.activeElement).toBe(t.element)
  })

  it('Home/End, ArrowUp, dan Spasi memilih', async () => {
    const w = mountSelect()
    const t = trigger(w)
    await key(t.element, 'Enter')

    await key(t.element, 'End')
    expect(optionEls().find((o) => o.hasAttribute('data-active'))?.textContent?.trim()).toBe('Buddha')
    await key(t.element, 'ArrowUp')
    expect(optionEls().find((o) => o.hasAttribute('data-active'))?.textContent?.trim()).toBe('Hindu')
    await key(t.element, 'Home')
    expect(optionEls().find((o) => o.hasAttribute('data-active'))?.textContent?.trim()).toBe('Islam')

    await key(t.element, ' ')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['islam'])
  })

  it('Escape menutup daftar tanpa mengubah nilai dan mengembalikan fokus ke trigger; Tab menutup', async () => {
    const w = mountSelect({ modelValue: 'islam' })
    const t = trigger(w)
    await key(t.element, 'Enter')
    await key(t.element, 'ArrowDown')
    expect(listbox()).not.toBeNull()

    await key(t.element, 'Escape')
    expect(listbox()).toBeNull()
    expect(w.emitted('update:modelValue')).toBeUndefined()
    expect(document.activeElement).toBe(t.element)

    await key(t.element, 'Enter')
    expect(listbox()).not.toBeNull()
    await key(t.element, 'Tab')
    expect(listbox()).toBeNull()
  })

  it('typeahead: mengetik huruf melompat ke opsi berawalan itu (melewati yang disabled)', async () => {
    const w = mountSelect()
    const t = trigger(w)
    await key(t.element, 'Enter')

    await key(t.element, 'b')
    expect(optionEls().find((o) => o.hasAttribute('data-active'))?.textContent?.trim()).toBe('Buddha')
    await key(t.element, 'Enter')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['buddha'])
  })

  it('disabled: trigger tidak bisa dibuka dengan klik maupun keyboard', async () => {
    const w = mountSelect({ disabled: true })
    const t = trigger(w)

    await t.trigger('click')
    await key(t.element, 'Enter')
    expect(listbox()).toBeNull()
    expect(t.attributes('disabled')).toBeDefined()
  })

  it('event blur diteruskan saat trigger kehilangan fokus (validasi form on-blur)', async () => {
    const w = mountSelect()
    await trigger(w).trigger('blur')
    expect(w.emitted('blur')).toHaveLength(1)
  })
})

describe('UiSelect custom — clearable dan searchable', () => {
  it('clearable: opsi placeholder pertama bisa dipilih untuk mengosongkan nilai', async () => {
    const w = mountSelect({ clearable: true, placeholder: 'Semua agama', modelValue: 'hindu' })
    await trigger(w).trigger('click')

    expect(optionLabels()[0]).toBe('Semua agama')
    expect(optionEls()[0]?.getAttribute('aria-selected')).toBe('false')
    optionEls()[0]?.click()
    await flushPromises()

    expect(w.emitted('update:modelValue')?.[0]).toEqual([''])
  })

  it('tanpa clearable, placeholder bukan opsi di daftar', async () => {
    const w = mountSelect({ placeholder: 'Pilih agama' })
    await trigger(w).trigger('click')
    expect(optionLabels()).not.toContain('Pilih agama')
  })

  it('searchable: kolom ketik menyaring opsi, Enter memilih yang aktif, tanpa hasil tampil "Tidak ada pilihan."', async () => {
    const w = mountSelect({ searchable: true })
    const input = trigger(w)
    expect(input.element.tagName).toBe('INPUT')
    expect(input.attributes('aria-autocomplete')).toBe('list')

    await input.trigger('click')
    expect(optionEls()).toHaveLength(5)

    ;(input.element as HTMLInputElement).value = 'k'
    await input.trigger('input')
    expect(optionLabels()).toEqual(['Kristen', 'Katolik'])

    ;(input.element as HTMLInputElement).value = 'zzz'
    await input.trigger('input')
    expect(optionEls()).toHaveLength(0)
    expect(listbox()?.textContent).toContain('Tidak ada pilihan.')

    ;(input.element as HTMLInputElement).value = 'bud'
    await input.trigger('input')
    expect(optionLabels()).toEqual(['Buddha'])
    await key(input.element, 'Enter')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['buddha'])
  })
})

describe('UiSelect custom — di dalam dialog (layer radix)', () => {
  const Host = defineComponent({
    setup() {
      const value = ref('')
      const open = ref(true)
      return () =>
        h(DialogRoot, { open: open.value, 'onUpdate:open': (v: boolean) => (open.value = v) }, () =>
          h(DialogPortal, null, () =>
            h(DialogContent, { 'data-testid': 'dlg' }, () => [
              h(DialogTitle, null, () => 'Form'),
              h(DialogDescription, null, () => 'Deskripsi'),
              h(UiSelect, { label: 'Agama', options: OPTIONS, modelValue: value.value, 'onUpdate:modelValue': (v: string) => (value.value = v) }),
              h('p', { 'data-testid': 'nilai' }, value.value),
            ]),
          ),
        )
    },
  })

  it('memilih opsi lewat klik tidak menutup dialog dan nilai terpasang', async () => {
    mount(Host, { attachTo: document.body })
    await flushPromises()
    expect(q('[data-testid="dlg"]')).not.toBeNull()

    q<HTMLElement>('[role="combobox"]')?.click()
    await flushPromises()
    expect(listbox()).not.toBeNull()

    optionEls().find((o) => o.textContent?.trim() === 'Kristen')?.click()
    await flushPromises()

    expect(q('[data-testid="nilai"]')?.textContent).toBe('kristen')
    expect(q('[data-testid="dlg"]')).not.toBeNull()
  })

  it('Escape saat daftar terbuka menutup daftar saja; dialog tetap terbuka', async () => {
    mount(Host, { attachTo: document.body })
    await flushPromises()

    const t = q<HTMLElement>('[role="combobox"]') as HTMLElement
    t.focus()
    await key(t, 'Enter')
    expect(listbox()).not.toBeNull()

    await key(t, 'Escape')
    expect(listbox()).toBeNull()
    expect(q('[data-testid="dlg"]')).not.toBeNull()
  })
})
