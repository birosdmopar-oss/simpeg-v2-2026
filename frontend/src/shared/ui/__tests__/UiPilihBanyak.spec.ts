/**
 * UiPilihBanyak (CR-134, R10 langkah 1) — pilihan ganda custom pengganti `<select multiple>`: ARIA fieldset/legend/checkbox,
 * keterkaitan petunjuk & galat, fokus keyboard, penyaringan, dan mempertahankan nilai yang tidak ada di opsi.
 */
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'

import UiPilihBanyak, { type OpsiPilihBanyak } from '../UiPilihBanyak.vue'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

const OPSI: OpsiPilihBanyak[] = [
  { id: 1, label: 'Cuti' },
  { id: 2, label: 'Gaji' },
  { id: 3, label: 'Kenaikan Pangkat', badge: { teks: 'Tidak Aktif', status: 0 } },
]

function pasang(awal: number[] = [], props: Record<string, unknown> = {}) {
  const Host = defineComponent({
    setup() {
      const nilai = ref<number[]>(awal)
      return { nilai }
    },
    render() {
      return h('div', [
        h(UiPilihBanyak, {
          modelValue: this.nilai,
          opsi: OPSI,
          legend: 'Kategori',
          labelCari: 'Cari kategori',
          teksKosong: 'Tidak ada kategori yang cocok.',
          testid: 'uji-pilih',
          'onUpdate:modelValue': (v: number[]) => (this.nilai = v),
          ...props,
        }),
        h('output', { 'data-testid': 'nilai' }, this.nilai.join(',')),
      ])
    },
  })
  return mount(Host, { attachTo: document.body })
}

const kotak = (id: number) => document.querySelector<HTMLInputElement>(`[data-testid="uji-pilih-${id}"] input[type="checkbox"]`)!
const nilai = () => document.querySelector('[data-testid="nilai"]')?.textContent ?? ''

describe('UiPilihBanyak', () => {
  it('ARIA: fieldset + legend dengan jumlah terpilih, checkbox berlabel, tanpa select native', () => {
    pasang([2])
    const fs = document.querySelector('[data-testid="uji-pilih"]')!
    expect(fs.tagName).toBe('FIELDSET')
    expect(fs.querySelector('legend')?.textContent?.replace(/\s+/g, ' ').trim()).toBe('Kategori (1 dipilih)')
    expect(document.querySelector('select')).toBeNull()
    expect(kotak(2).checked).toBe(true)
    expect(kotak(1).checked).toBe(false)
    expect(document.querySelector('[data-testid="uji-pilih-1"] label')?.textContent).toContain('Cuti')
    expect(document.querySelector('input[type="search"], input')?.getAttribute('placeholder')).toBe('Cari kategori')
  })

  it('keyboard: kotak pencarian dan tiap checkbox fokusable berurutan (Tab), input asli tidak display:none', () => {
    pasang()
    const fokusable = [...document.querySelectorAll<HTMLInputElement>('[data-testid="uji-pilih"] input')]
    expect(fokusable).toHaveLength(4)
    for (const el of fokusable) {
      expect(el.tabIndex).toBe(0)
      el.focus()
      expect(document.activeElement).toBe(el)
    }
    expect(kotak(1).className).toContain('opacity-0')
  })

  it('mencentang (Spasi/klik = change) menambah id menurut urutan opsi; melepas menghapusnya', async () => {
    const w = pasang([3])
    kotak(1).checked = true
    await w.find('[data-testid="uji-pilih-1"] input').trigger('change')
    expect(nilai()).toBe('1,3')
    kotak(3).checked = false
    await w.find('[data-testid="uji-pilih-3"] input').trigger('change')
    expect(nilai()).toBe('1')
  })

  it('id terpilih yang tidak ada di opsi tetap dipertahankan saat opsi lain diubah', async () => {
    const w = pasang([99])
    kotak(2).checked = true
    await w.find('[data-testid="uji-pilih-2"] input').trigger('change')
    expect(nilai()).toBe('2,99')
  })

  it('penyaringan menyembunyikan opsi tanpa mengubah nilai; teks kosong bila tidak ada yang cocok', async () => {
    const w = pasang([1])
    await w.find('input:not([type="checkbox"])').setValue('gaji')
    expect(document.querySelector('[data-testid="uji-pilih-1"]')).toBeNull()
    expect(document.querySelector('[data-testid="uji-pilih-2"]')).not.toBeNull()
    expect(nilai()).toBe('1')
    await w.find('input:not([type="checkbox"])').setValue('zzz')
    expect(document.querySelector('[data-kosong]')?.textContent).toBe('Tidak ada kategori yang cocok.')
  })

  it('badge opsi tampil dengan data-status', () => {
    pasang()
    const badge = document.querySelector('[data-testid="uji-pilih-3"] [data-status="0"]')
    expect(badge?.textContent).toBe('Tidak Aktif')
  })

  it('petunjuk dan galat terhubung lewat aria-describedby; galat = role alert + aria-invalid', () => {
    pasang([], { petunjuk: 'Minimal satu kategori.', error: 'Kategori wajib dipilih.' })
    const fs = document.querySelector('[data-testid="uji-pilih"]')!
    expect(fs.getAttribute('aria-invalid')).toBe('true')
    const ids = (fs.getAttribute('aria-describedby') ?? '').split(' ')
    expect(ids).toHaveLength(2)
    expect(ids.map((id) => document.getElementById(id)?.textContent)).toEqual(['Minimal satu kategori.', 'Kategori wajib dipilih.'])
    expect(document.querySelector('[role="alert"]')?.textContent).toBe('Kategori wajib dipilih.')
    expect(kotak(1).getAttribute('aria-invalid')).toBe('true')
  })

  it('tanpa petunjuk/galat: tanpa aria-describedby', () => {
    pasang()
    expect(document.querySelector('[data-testid="uji-pilih"]')!.hasAttribute('aria-describedby')).toBe(false)
  })

  it('disabled mengunci kotak ceklis saja; kotak cari tetap aktif', () => {
    pasang([], { disabled: true })
    expect(document.querySelector('[data-testid="uji-pilih"]')!.hasAttribute('disabled')).toBe(false)
    for (const id of [1, 2, 3]) expect(kotak(id).disabled).toBe(true)
    const cari = document.querySelector<HTMLInputElement>('[data-testid="uji-pilih"] input[type="search"]')!
    expect(cari.disabled).toBe(false)
  })

  it('cari=false menyembunyikan kotak pencarian', () => {
    pasang([], { cari: false })
    expect(document.querySelectorAll('[data-testid="uji-pilih"] input[type="search"]')).toHaveLength(0)
  })
  it('Enter di kotak cari tidak mengirim form induk', async () => {
    const kirim = vi.fn((e: Event) => e.preventDefault())
    const Host = defineComponent({
      render: () =>
        h('form', { onSubmit: kirim }, [
          h(UiPilihBanyak, { modelValue: [], opsi: OPSI, legend: 'Kategori', labelCari: 'Cari kategori', teksKosong: '-', testid: 'uji-pilih' }),
        ]),
    })
    const w = mount(Host, { attachTo: document.body })
    const cari = w.get('input[type="search"]')
    const ev = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
    cari.element.dispatchEvent(ev)
    await w.vm.$nextTick()
    expect(ev.defaultPrevented).toBe(true)
    expect(kirim).not.toHaveBeenCalled()
  })
})
