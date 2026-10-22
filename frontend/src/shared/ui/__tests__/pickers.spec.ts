/**
 * UiDatePicker / UiTimePicker / UiMonthPicker lewat UiTextField (AGENTS.md bagian 3, CR-060) — interaksi pengguna
 * nyata: ketik manual, buka popup, pilih lewat klik dan keyboard, min/max, ARIA, dan BUKAN input native bawaan browser.
 * Popup dirender di portal <body>.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'

import UiTextField from '../UiTextField.vue'

afterEach(() => {
  document.body.innerHTML = ''
  vi.useRealTimers()
})
enableAutoUnmount(afterEach)

const q = <E extends Element = HTMLElement>(sel: string) => document.body.querySelector<E>(sel)
const qa = (sel: string) => [...document.body.querySelectorAll<HTMLElement>(sel)]

/** Pembungkus dengan v-model supaya nilai berubah sungguhan (seperti di form). */
function mountField(type: 'date' | 'time' | 'month', initial = '', extra: Record<string, unknown> = {}) {
  const Host = defineComponent({
    setup() {
      const value = ref(initial)
      return { value }
    },
    render() {
      return h('div', [
        h(UiTextField, { label: 'Uji', type, name: 'uji', modelValue: this.value, 'onUpdate:modelValue': (v: string) => (this.value = v), ...extra }),
        h('output', { 'data-testid': 'nilai' }, this.value),
      ])
    },
  })
  return mount(Host, { attachTo: document.body })
}

const nilai = () => q('[data-testid="nilai"]')?.textContent ?? ''

async function ketik(el: HTMLInputElement, text: string): Promise<void> {
  el.value = text
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

async function key(el: Element, k: string, init: KeyboardEventInit = {}): Promise<void> {
  el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...init }))
  await flushPromises()
}

describe('bukan input native bawaan browser', () => {
  it.each(['date', 'time', 'month'] as const)('type="%s" dirender sebagai kolom teks custom + tombol popup (tanpa input type date/time/month)', (type) => {
    mountField(type)
    expect(document.body.querySelector('input[type="date"], input[type="time"], input[type="month"]')).toBeNull()
    const input = q<HTMLInputElement>('input[name="uji"]')
    expect(input?.type).toBe('text')
    expect(input?.getAttribute('aria-haspopup')).toBe('dialog')
    expect(q(`[data-testid="ui-${type}-toggle"]`)).not.toBeNull()
  })
})

describe('UiDatePicker', () => {
  it('menampilkan dd/mm/yyyy dari nilai YYYY-MM-DD; mengetik tanggal lengkap mengirim YYYY-MM-DD (semua format ketikan)', async () => {
    mountField('date', '2026-08-17')
    const input = q<HTMLInputElement>('input[name="uji"]') as HTMLInputElement
    expect(input.value).toBe('17/08/2026')

    for (const text of ['18/08/2026', '19-08-2026', '20.08.2026', '21082026', '2026-08-22']) {
      await ketik(input, text)
      expect(nilai(), text).toBe(text.length === 10 && text.startsWith('2026') ? text : `2026-08-${text.slice(0, 2)}`)
    }
  })

  it('ketikan parsial tidak mengubah nilai; kosong mengosongkan; format salah saat blur dikosongkan + pesan error berperan alert', async () => {
    mountField('date', '2026-08-17')
    const input = q<HTMLInputElement>('input[name="uji"]') as HTMLInputElement

    await ketik(input, '18/08/20')
    expect(nilai()).toBe('2026-08-17')

    await ketik(input, '31/02/2026')
    input.dispatchEvent(new Event('blur'))
    await flushPromises()
    expect(nilai()).toBe('')
    expect(q('[role="alert"]')?.textContent).toContain('Format tanggal tidak valid')
    expect(input.getAttribute('aria-invalid')).toBe('true')

    await ketik(input, '')
    expect(nilai()).toBe('')
    expect(q('[role="alert"]')).toBeNull()
  })

  it('blur merapikan ketikan ISO menjadi dd/mm/yyyy', async () => {
    mountField('date')
    const input = q<HTMLInputElement>('input[name="uji"]') as HTMLInputElement
    await ketik(input, '2026-08-17')
    expect(nilai()).toBe('2026-08-17')
    input.dispatchEvent(new Event('blur'))
    await flushPromises()
    expect(input.value).toBe('17/08/2026')
  })

  it('tombol kalender membuka dialog berisi grid bulan; klik hari memilih, menutup, dan mengembalikan fokus ke kolom', async () => {
    mountField('date', '2026-10-20')
    const toggle = q<HTMLButtonElement>('[data-testid="ui-date-toggle"]') as HTMLButtonElement
    expect(toggle.getAttribute('aria-expanded')).toBe('false')

    toggle.click()
    await flushPromises()

    const panel = q('[data-testid="ui-date-panel"]')
    expect(panel?.getAttribute('role')).toBe('dialog')
    expect(toggle.getAttribute('aria-expanded')).toBe('true')
    expect(q('[data-testid="ui-date-title"]')?.textContent).toBe('Oktober 2026')
    const grid = q('table[role="grid"]')
    expect(grid?.getAttribute('aria-label')).toBe('Oktober 2026')
    expect([...(grid?.querySelectorAll('th') ?? [])].map((t) => t.textContent?.trim())).toEqual(['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'])

    const day = (iso: string) => q<HTMLButtonElement>(`[data-iso="${iso}"]`) as HTMLButtonElement
    expect(day('2026-10-20').getAttribute('aria-label')).toBe('20 Oktober 2026')
    expect(day('2026-10-20').closest('td')?.getAttribute('aria-selected')).toBe('true')
    expect(day('2026-10-21').closest('td')?.getAttribute('aria-selected')).toBe('false')

    day('2026-10-25').click()
    await flushPromises()

    expect(nilai()).toBe('2026-10-25')
    expect(q('[data-testid="ui-date-panel"]')).toBeNull()
    expect((q('input[name="uji"]') as HTMLInputElement).value).toBe('25/10/2026')
    expect(document.activeElement).toBe(q('input[name="uji"]'))
  })

  it('navigasi bulan dan tahun lewat tombol header', async () => {
    mountField('date', '2026-10-20')
    q<HTMLButtonElement>('[data-testid="ui-date-toggle"]')?.click()
    await flushPromises()

    q<HTMLButtonElement>('[data-testid="ui-date-next-month"]')?.click()
    await flushPromises()
    expect(q('[data-testid="ui-date-title"]')?.textContent).toBe('November 2026')
    q<HTMLButtonElement>('[data-testid="ui-date-prev-year"]')?.click()
    await flushPromises()
    expect(q('[data-testid="ui-date-title"]')?.textContent).toBe('November 2025')
    q<HTMLButtonElement>('[data-testid="ui-date-prev-month"]')?.click()
    await flushPromises()
    expect(q('[data-testid="ui-date-title"]')?.textContent).toBe('Oktober 2025')
  })

  it('keyboard di grid: panah, Home/End, PageUp/PageDown (Shift = tahun), Enter memilih, Esc menutup', async () => {
    mountField('date', '2026-10-20') // Selasa
    q<HTMLButtonElement>('[data-testid="ui-date-toggle"]')?.click()
    await flushPromises()

    const focused = () => (document.activeElement as HTMLElement | null)?.getAttribute('data-iso')
    expect(focused()).toBe('2026-10-20')

    const grid = q('table[role="grid"]') as HTMLElement
    const press = async (k: string, init: KeyboardEventInit = {}) => key(document.activeElement ?? grid, k, init)

    await press('ArrowRight')
    expect(focused()).toBe('2026-10-21')
    await press('ArrowDown')
    expect(focused()).toBe('2026-10-28')
    await press('ArrowLeft')
    expect(focused()).toBe('2026-10-27')
    await press('ArrowUp')
    expect(focused()).toBe('2026-10-20')
    await press('Home')
    expect(focused()).toBe('2026-10-19') // Senin
    await press('End')
    expect(focused()).toBe('2026-10-25') // Minggu
    await press('PageDown')
    expect(focused()).toBe('2026-11-25')
    expect(q('[data-testid="ui-date-title"]')?.textContent).toBe('November 2026')
    await press('PageUp', { shiftKey: true })
    expect(focused()).toBe('2025-11-25')
    // Roving tabindex: hanya sel fokus yang tabindex 0.
    expect(qa('[data-iso][tabindex="0"]')).toHaveLength(1)

    await press('Enter')
    // Enter pada <button> memicu klik (perilaku native); jsdom tidak melakukannya otomatis → klik eksplisit.
    ;(document.activeElement as HTMLElement).click()
    await flushPromises()
    expect(nilai()).toBe('2025-11-25')
    expect(q('[data-testid="ui-date-panel"]')).toBeNull()
  })

  it('Escape menutup kalender tanpa mengubah nilai dan mengembalikan fokus ke kolom', async () => {
    mountField('date', '2026-10-20')
    q<HTMLButtonElement>('[data-testid="ui-date-toggle"]')?.click()
    await flushPromises()
    expect(q('[data-testid="ui-date-panel"]')).not.toBeNull()

    await key(document.activeElement as Element, 'Escape')
    expect(q('[data-testid="ui-date-panel"]')).toBeNull()
    expect(nilai()).toBe('2026-10-20')
    expect(document.activeElement).toBe(q('input[name="uji"]'))
  })

  it('min/max: hari di luar rentang aria-disabled dan tidak bisa dipilih; ketikan di luar rentang ditolak', async () => {
    mountField('date', '2026-10-20', { min: '2026-10-10', max: '2026-10-25' })
    q<HTMLButtonElement>('[data-testid="ui-date-toggle"]')?.click()
    await flushPromises()

    const luar = q<HTMLButtonElement>('[data-iso="2026-10-05"]') as HTMLButtonElement
    expect(luar.getAttribute('aria-disabled')).toBe('true')
    luar.click()
    await flushPromises()
    expect(nilai()).toBe('2026-10-20')
    expect(q('[data-testid="ui-date-panel"]')).not.toBeNull()
    expect(q('[data-iso="2026-10-12"]')?.getAttribute('aria-disabled')).toBeNull()

    q<HTMLButtonElement>('[data-iso="2026-10-12"]')?.click()
    await flushPromises()
    expect(nilai()).toBe('2026-10-12')

    const input = q<HTMLInputElement>('input[name="uji"]') as HTMLInputElement
    await ketik(input, '01/10/2026')
    expect(nilai()).toBe('2026-10-12') // di luar rentang: tidak dikirim
    input.dispatchEvent(new Event('blur'))
    await flushPromises()
    expect(nilai()).toBe('')
    expect(q('[role="alert"]')?.textContent).toContain('di luar rentang')
  })

  it('"Hari ini" memilih tanggal LOKAL hari ini (bukan tanggal UTC) dan "Kosongkan" menghapus nilai', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 9, 20, 0, 30, 0)) // 20 Okt 00:30 lokal
    mountField('date')
    q<HTMLButtonElement>('[data-testid="ui-date-toggle"]')?.click()
    await flushPromises()

    expect(q('[data-iso="2026-10-20"]')?.getAttribute('aria-current')).toBe('date')
    q<HTMLButtonElement>('[data-testid="ui-date-today"]')?.click()
    await flushPromises()
    expect(nilai()).toBe('2026-10-20')

    q<HTMLButtonElement>('[data-testid="ui-date-toggle"]')?.click()
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="ui-date-clear"]')?.click()
    await flushPromises()
    expect(nilai()).toBe('')
    expect((q('input[name="uji"]') as HTMLInputElement).value).toBe('')
  })

  it('disabled: kolom dan tombol tidak bisa dipakai', async () => {
    mountField('date', '2026-10-20', { disabled: true })
    const toggle = q<HTMLButtonElement>('[data-testid="ui-date-toggle"]') as HTMLButtonElement
    expect(toggle.disabled).toBe(true)
    expect((q('input[name="uji"]') as HTMLInputElement).disabled).toBe(true)
    toggle.click()
    await flushPromises()
    expect(q('[data-testid="ui-date-panel"]')).toBeNull()
  })

  it('label terhubung ke kolom ketik (for/id) dan wajib bertanda aria-required', () => {
    mountField('date', '', { required: true })
    const label = q('label') as HTMLLabelElement
    const input = q<HTMLInputElement>('input[name="uji"]') as HTMLInputElement
    expect(label.htmlFor).toBe(input.id)
    expect(input.getAttribute('aria-required')).toBe('true')
  })
})

describe('UiTimePicker', () => {
  it('menampilkan HH:mm; mengetik jam lengkap mengirim HH:mm (H:mm, HH.mm, HHmm); salah format saat blur dikosongkan + pesan', async () => {
    mountField('time', '08:05')
    const input = q<HTMLInputElement>('input[name="uji"]') as HTMLInputElement
    expect(input.value).toBe('08:05')

    for (const [text, hasil] of [['9:30', '09:30'], ['10.45', '10:45'], ['1115', '11:15']] as const) {
      await ketik(input, text)
      expect(nilai(), text).toBe(hasil)
    }

    await ketik(input, '25:99')
    input.dispatchEvent(new Event('blur'))
    await flushPromises()
    expect(nilai()).toBe('')
    expect(q('[role="alert"]')?.textContent).toContain('Format jam tidak valid')
  })

  it('popup berisi dua listbox (Jam, Menit); klik jam lalu menit memilih dan menutup', async () => {
    mountField('time', '08:05')
    q<HTMLButtonElement>('[data-testid="ui-time-toggle"]')?.click()
    await flushPromises()

    const listboxes = qa('[role="listbox"]')
    expect(listboxes).toHaveLength(2)
    expect(qa('[data-col="h"]')).toHaveLength(24)
    expect(q('[data-col="h"][data-value="8"]')?.getAttribute('aria-selected')).toBe('true')
    expect(q('[data-col="m"][data-value="5"]')?.getAttribute('aria-selected')).toBe('true')

    q<HTMLButtonElement>('[data-col="h"][data-value="13"]')?.click()
    await flushPromises()
    expect(nilai()).toBe('13:05')
    expect(q('[data-testid="ui-time-panel"]')).not.toBeNull() // jam: tetap terbuka

    q<HTMLButtonElement>('[data-col="m"][data-value="30"]')?.click()
    await flushPromises()
    expect(nilai()).toBe('13:30')
    expect(q('[data-testid="ui-time-panel"]')).toBeNull()
  })

  it('keyboard: ↑/↓ dalam kolom, → pindah ke Menit, Enter/klik memilih, Esc menutup', async () => {
    mountField('time', '08:00')
    q<HTMLButtonElement>('[data-testid="ui-time-toggle"]')?.click()
    await flushPromises()

    const at = () => {
      const el = document.activeElement as HTMLElement
      return `${el.getAttribute('data-col')}${el.getAttribute('data-value')}`
    }
    expect(at()).toBe('h8')
    await key(document.activeElement as Element, 'ArrowDown')
    expect(at()).toBe('h9')
    await key(document.activeElement as Element, 'End')
    expect(at()).toBe('h23')
    await key(document.activeElement as Element, 'Home')
    expect(at()).toBe('h0')
    await key(document.activeElement as Element, 'ArrowRight')
    expect(at()).toBe('m0')
    await key(document.activeElement as Element, 'ArrowDown')
    expect(at()).toBe('m5')
    ;(document.activeElement as HTMLElement).click()
    await flushPromises()
    expect(nilai()).toBe('08:05')
    expect(q('[data-testid="ui-time-panel"]')).toBeNull()

    q<HTMLButtonElement>('[data-testid="ui-time-toggle"]')?.click()
    await flushPromises()
    await key(document.activeElement as Element, 'Escape')
    expect(q('[data-testid="ui-time-panel"]')).toBeNull()
    expect(nilai()).toBe('08:05')
  })
})

describe('UiMonthPicker', () => {
  it('menampilkan mm/yyyy dari YYYY-MM; mengetik bulan lengkap mengirim YYYY-MM; salah format saat blur dikosongkan + pesan', async () => {
    mountField('month', '2026-10')
    const input = q<HTMLInputElement>('input[name="uji"]') as HTMLInputElement
    expect(input.value).toBe('10/2026')

    for (const [text, hasil] of [['11/2026', '2026-11'], ['3-2027', '2027-03'], ['2028-12', '2028-12']] as const) {
      await ketik(input, text)
      expect(nilai(), text).toBe(hasil)
    }

    await ketik(input, '13/2026')
    input.dispatchEvent(new Event('blur'))
    await flushPromises()
    expect(nilai()).toBe('')
    expect(q('[role="alert"]')?.textContent).toContain('Format bulan tidak valid')
  })

  it('popup: header tahun, 12 bulan; klik memilih dan menutup; navigasi tahun', async () => {
    mountField('month', '2026-10')
    q<HTMLButtonElement>('[data-testid="ui-month-toggle"]')?.click()
    await flushPromises()

    expect(q('[data-testid="ui-month-year"]')?.textContent).toBe('2026')
    expect(qa('[data-month]')).toHaveLength(12)
    expect(q('[data-month="10"]')?.getAttribute('aria-pressed')).toBe('true')
    expect(q('[data-month="10"]')?.getAttribute('aria-label')).toBe('Oktober 2026')

    q<HTMLButtonElement>('[data-testid="ui-month-next-year"]')?.click()
    await flushPromises()
    expect(q('[data-testid="ui-month-year"]')?.textContent).toBe('2027')

    q<HTMLButtonElement>('[data-month="2"]')?.click()
    await flushPromises()
    expect(nilai()).toBe('2027-02')
    expect(q('[data-testid="ui-month-panel"]')).toBeNull()
    expect((q('input[name="uji"]') as HTMLInputElement).value).toBe('02/2027')
  })

  it('keyboard: ←/→ ±1 bulan, ↑/↓ ±3, PageUp/PageDown ±1 tahun, Home/End, Esc menutup', async () => {
    mountField('month', '2026-10')
    q<HTMLButtonElement>('[data-testid="ui-month-toggle"]')?.click()
    await flushPromises()

    const at = () => (document.activeElement as HTMLElement).getAttribute('data-month')
    const year = () => q('[data-testid="ui-month-year"]')?.textContent
    expect(at()).toBe('10')

    await key(document.activeElement as Element, 'ArrowRight')
    expect(at()).toBe('11')
    await key(document.activeElement as Element, 'ArrowRight')
    await key(document.activeElement as Element, 'ArrowRight') // lewat Desember → Januari tahun berikut
    expect([at(), year()]).toEqual(['1', '2027'])
    await key(document.activeElement as Element, 'ArrowDown')
    expect(at()).toBe('4')
    await key(document.activeElement as Element, 'ArrowUp')
    expect(at()).toBe('1')
    await key(document.activeElement as Element, 'PageUp')
    expect(year()).toBe('2026')
    await key(document.activeElement as Element, 'End')
    expect(at()).toBe('12')
    await key(document.activeElement as Element, 'Home')
    expect(at()).toBe('1')

    await key(document.activeElement as Element, 'Escape')
    expect(q('[data-testid="ui-month-panel"]')).toBeNull()
    expect(nilai()).toBe('2026-10')
    expect(document.activeElement).toBe(q('input[name="uji"]'))
  })
})
