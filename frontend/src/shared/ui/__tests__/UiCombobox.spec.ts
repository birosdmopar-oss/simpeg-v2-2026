/**
 * UiCombobox via UiTextField `suggestions` (AGENTS.md bagian 3, CR-060) — pengganti <input list>/<datalist>: teks bebas
 * dengan saran custom; ARIA combobox/listbox/option, keyboard, penyaringan, Esc, dan tanpa <datalist> bawaan browser.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, ref } from 'vue'

import UiTextField from '../UiTextField.vue'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

const SARAN = ['Pariwisata', 'Perhotelan', 'Ekonomi Kreatif', 'Pendidikan']

const q = <E extends Element = HTMLElement>(sel: string) => document.body.querySelector<E>(sel)
const opts = () => [...document.body.querySelectorAll<HTMLElement>('[role="listbox"] [role="option"]')]
const labels = () => opts().map((o) => o.textContent?.trim())

function mountBox(initial = '', suggestions: string[] = SARAN) {
  const Host = defineComponent({
    setup() {
      const value = ref(initial)
      const list = ref(suggestions)
      return { value, list }
    },
    render() {
      return h('div', [
        h(UiTextField, { label: 'Bidang', name: 'bidang', modelValue: this.value, suggestions: this.list, 'onUpdate:modelValue': (v: string) => (this.value = v) }),
        h('output', { 'data-testid': 'nilai' }, this.value),
      ])
    },
  })
  return mount(Host, { attachTo: document.body })
}

const input = () => q<HTMLInputElement>('input[name="bidang"]') as HTMLInputElement
const nilai = () => q('[data-testid="nilai"]')?.textContent ?? ''

async function ketik(text: string): Promise<void> {
  input().value = text
  input().dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

async function key(k: string): Promise<void> {
  input().dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }))
  await flushPromises()
}

describe('UiCombobox (UiTextField suggestions)', () => {
  it('role=combobox tanpa <datalist>/atribut list bawaan browser; daftar tertutup sebelum interaksi', () => {
    mountBox()
    expect(document.body.querySelector('datalist')).toBeNull()
    expect(input().hasAttribute('list')).toBe(false)
    expect(input().getAttribute('role')).toBe('combobox')
    expect(input().getAttribute('aria-autocomplete')).toBe('list')
    expect(input().getAttribute('aria-expanded')).toBe('false')
    expect(q('[role="listbox"]')).toBeNull()
  })

  it('ArrowDown membuka semua saran; ↓/↑ memindah opsi aktif (aria-activedescendant), Enter memilih', async () => {
    mountBox()
    input().focus()
    await key('ArrowDown')

    expect(input().getAttribute('aria-expanded')).toBe('true')
    expect(labels()).toEqual(SARAN)
    expect(input().getAttribute('aria-controls')).toBe(q('[role="listbox"]')?.id)
    expect(input().getAttribute('aria-activedescendant')).toBe(opts()[0]?.id)
    expect(opts()[0]?.getAttribute('aria-selected')).toBe('true')

    await key('ArrowDown')
    await key('ArrowDown')
    expect(input().getAttribute('aria-activedescendant')).toBe(opts()[2]?.id)
    await key('ArrowUp')
    expect(input().getAttribute('aria-activedescendant')).toBe(opts()[1]?.id)

    await key('Enter')
    expect(nilai()).toBe('Perhotelan')
    expect(q('[role="listbox"]')).toBeNull()
    expect(document.activeElement).toBe(input())
  })

  it('mengetik menyaring saran (abaikan huruf besar/kecil); nilai bebas tetap sah tanpa memilih saran', async () => {
    mountBox()
    await ketik('pe')
    expect(labels()).toEqual(['Perhotelan', 'Pendidikan'])

    await ketik('Bidang Baru Sama Sekali')
    expect(nilai()).toBe('Bidang Baru Sama Sekali')
    expect(q('[role="listbox"]')).toBeNull() // tidak ada yang cocok: daftar tidak tampil
    expect(input().getAttribute('aria-expanded')).toBe('false')
  })

  it('klik saran mengisi kolom; Enter tanpa saran aktif tidak menimpa ketikan', async () => {
    mountBox()
    await ketik('eko')
    expect(labels()).toEqual(['Ekonomi Kreatif'])

    await key('Enter') // tidak ada opsi aktif: dibiarkan untuk form
    expect(nilai()).toBe('eko')

    opts()[0]?.click()
    await flushPromises()
    expect(nilai()).toBe('Ekonomi Kreatif')
    expect(q('[role="listbox"]')).toBeNull()
  })

  it('Escape menutup daftar saja (nilai tidak berubah); Tab menutup', async () => {
    mountBox()
    await ketik('p')
    expect(opts().length).toBeGreaterThan(0)

    await key('Escape')
    expect(q('[role="listbox"]')).toBeNull()
    expect(nilai()).toBe('p')

    await ketik('pa')
    expect(q('[role="listbox"]')).not.toBeNull()
    await key('Tab')
    expect(q('[role="listbox"]')).toBeNull()
  })

  it('saran yang dimuat belakangan (awal kosong) tetap memakai kontrol yang sama dan muncul begitu tersedia', async () => {
    const w = mountBox('', [])
    expect(input().getAttribute('role')).toBe('combobox')
    await ketik('pa')
    expect(q('[role="listbox"]')).toBeNull()

    ;(w.vm as unknown as { list: string[] }).list = SARAN
    await flushPromises()
    await ketik('pa')
    expect(labels()).toEqual(['Pariwisata'])
  })
})
