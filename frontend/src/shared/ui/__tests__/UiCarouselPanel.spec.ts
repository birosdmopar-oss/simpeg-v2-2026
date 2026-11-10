/**
 * UiCarouselPanel (AGENTS.md §3, F-13 MAKE-087) — pola WAI-ARIA carousel: region + aria-roledescription, slide "i dari n",
 * tombol Sebelumnya/Berikutnya + indikator (aria-current), keyboard ←/→/Home/End, autoplay yang berhenti saat dijeda
 * (aria-pressed), saat hover/fokus, dan bila prefers-reduced-motion; slot empty untuk daftar kosong.
 */
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

import UiCarouselPanel from '../UiCarouselPanel.vue'

enableAutoUnmount(afterEach)

const asliMatchMedia = window.matchMedia

function setReducedMotion(reduce: boolean): void {
  window.matchMedia = ((query: string) => ({
    matches: reduce && query.includes('reduce'),
    media: query,
    onchange: null,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    addListener: () => undefined,
    removeListener: () => undefined,
    dispatchEvent: () => false,
  })) as typeof window.matchMedia
}

function pasang(items: string[], interval = 5000) {
  return mount(UiCarouselPanel, {
    props: { items, label: 'Ulang tahun hari ini', interval },
    slots: {
      item: ({ item }: { item: unknown }) => h('p', { 'data-testid': 'isi' }, String(item)),
      empty: () => h('p', 'Tidak ada data'),
    },
    attachTo: document.body,
  })
}

const terlihat = (w: ReturnType<typeof pasang>): string[] =>
  w.findAll('[role="group"]').filter((s) => (s.element as HTMLElement).style.display !== 'none').map((s) => s.text())

beforeEach(() => {
  setReducedMotion(false)
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
  window.matchMedia = asliMatchMedia
  document.body.innerHTML = ''
})

describe('UiCarouselPanel', () => {
  it('ARIA: region carousel berlabel; slide group "i dari n"; hanya slide aktif terlihat', () => {
    const w = pasang(['A', 'B', 'C'])
    const region = w.get('[data-testid="ui-carousel"]')

    expect(region.attributes('role')).toBe('region')
    expect(region.attributes('aria-roledescription')).toBe('carousel')
    expect(region.attributes('aria-label')).toBe('Ulang tahun hari ini')
    expect(w.findAll('[role="group"]').map((s) => s.attributes('aria-label'))).toEqual(['1 dari 3', '2 dari 3', '3 dari 3'])
    expect(w.findAll('[role="group"]')[0]?.attributes('aria-roledescription')).toBe('slide')
    expect(terlihat(w)).toEqual(['A'])
  })

  it('tombol Berikutnya/Sebelumnya berputar melingkar; indikator menandai slide aktif', async () => {
    const w = pasang(['A', 'B', 'C'])

    await w.get('[data-testid="ui-carousel-berikut"]').trigger('click')
    expect(terlihat(w)).toEqual(['B'])
    await w.get('[data-testid="ui-carousel-sebelum"]').trigger('click')
    await w.get('[data-testid="ui-carousel-sebelum"]').trigger('click')
    expect(terlihat(w)).toEqual(['C'])

    const indikator = w.findAll('[data-testid="ui-carousel-indikator"] button')
    expect(indikator.map((b) => b.attributes('aria-current'))).toEqual([undefined, undefined, 'true'])
    await indikator[0]!.trigger('click')
    expect(terlihat(w)).toEqual(['A'])
  })

  it('keyboard: ←/→ pindah, Home/End ke awal/akhir', async () => {
    const w = pasang(['A', 'B', 'C'])
    const region = w.get('[data-testid="ui-carousel"]')

    expect(region.attributes('tabindex')).toBe('0')
    await region.trigger('keydown', { key: 'ArrowRight' })
    expect(terlihat(w)).toEqual(['B'])
    await region.trigger('keydown', { key: 'End' })
    expect(terlihat(w)).toEqual(['C'])
    await region.trigger('keydown', { key: 'Home' })
    expect(terlihat(w)).toEqual(['A'])
    await region.trigger('keydown', { key: 'ArrowLeft' })
    expect(terlihat(w)).toEqual(['C'])
  })

  it('autoplay maju tiap selang; tombol Jeda (aria-pressed) menghentikan, Putar melanjutkan', async () => {
    const w = pasang(['A', 'B', 'C'], 1000)
    const jeda = w.get('[data-testid="ui-carousel-jeda"]')

    expect(w.get('[data-testid="ui-carousel"] [aria-live]').attributes('aria-live')).toBe('off')
    await vi.advanceTimersByTimeAsync(1000)
    expect(terlihat(w)).toEqual(['B'])

    await jeda.trigger('click')
    expect(jeda.attributes('aria-pressed')).toBe('true')
    expect(jeda.attributes('aria-label')).toBe('Putar otomatis')
    expect(w.get('[data-testid="ui-carousel"] [aria-live]').attributes('aria-live')).toBe('polite')
    await vi.advanceTimersByTimeAsync(3000)
    expect(terlihat(w)).toEqual(['B'])

    await jeda.trigger('click')
    await vi.advanceTimersByTimeAsync(1000)
    expect(terlihat(w)).toEqual(['C'])
  })

  it('autoplay berhenti saat hover / fokus di dalam panel', async () => {
    const w = pasang(['A', 'B'], 1000)
    const region = w.get('[data-testid="ui-carousel"]')

    await region.trigger('mouseenter')
    await vi.advanceTimersByTimeAsync(3000)
    expect(terlihat(w)).toEqual(['A'])
    await region.trigger('mouseleave')
    await region.trigger('focusin')
    await vi.advanceTimersByTimeAsync(3000)
    expect(terlihat(w)).toEqual(['A'])
  })

  it('prefers-reduced-motion: tanpa autoplay dan tanpa tombol Jeda', async () => {
    setReducedMotion(true)
    const w = pasang(['A', 'B'], 1000)

    await vi.advanceTimersByTimeAsync(5000)
    expect(terlihat(w)).toEqual(['A'])
    expect(w.find('[data-testid="ui-carousel-jeda"]').exists()).toBe(false)
  })

  it('satu item: tanpa kontrol; daftar kosong: slot empty', () => {
    expect(pasang(['A']).find('[data-testid="ui-carousel-berikut"]').exists()).toBe(false)
    const kosong = pasang([])
    expect(kosong.get('[data-testid="ui-carousel-kosong"]').text()).toBe('Tidak ada data')
  })
})
