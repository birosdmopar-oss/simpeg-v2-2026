/**
 * UiTooltip (AGENTS.md bagian 3, CR-060) — pengganti atribut `title`: muncul saat pemicu difokus keyboard atau di-hover,
 * role="tooltip", pemicu terhubung lewat aria-describedby, Esc menutup, tanpa title bawaan browser. Konten dirender di
 * portal <body>.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import UiTooltip from '../UiTooltip.vue'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

const tooltip = () => document.body.querySelector<HTMLElement>('[role="tooltip"], [data-testid="ui-tooltip"]')

function mountTip(props: Record<string, unknown> = {}) {
  return mount(UiTooltip, {
    props: { content: 'Nama berkas lengkap.pdf', ...props },
    slots: { default: '<button type="button" data-testid="pemicu">Buka</button>' },
    attachTo: document.body,
  })
}

describe('UiTooltip', () => {
  it('tidak tampil sebelum interaksi; pemicu tidak memakai atribut title bawaan browser', () => {
    const w = mountTip()
    const pemicu = w.get('[data-testid="pemicu"]')

    expect(tooltip()).toBeNull()
    expect(pemicu.attributes('title')).toBeUndefined()
    expect(pemicu.attributes('data-tooltip')).toBe('Nama berkas lengkap.pdf')
  })

  it('CR-068: atribut & listener yang diberikan ke UiTooltip diteruskan ke elemen pemicu (pola as-child)', async () => {
    let klik = 0
    const w = mount(UiTooltip, {
      props: { content: 'Aksi' },
      attrs: { 'aria-haspopup': 'menu', 'data-uji': 'x', onClick: () => klik++ },
      slots: { default: '<button type="button" data-testid="pemicu">Buka</button>' },
      attachTo: document.body,
    })
    const pemicu = w.get('[data-testid="pemicu"]')

    expect(pemicu.attributes('aria-haspopup')).toBe('menu')
    expect(pemicu.attributes('data-uji')).toBe('x')
    await pemicu.trigger('click')
    expect(klik).toBe(1)
  })

  it('fokus keyboard memunculkan tooltip berisi teks, pemicu mendapat aria-describedby ke tooltip', async () => {
    const w = mountTip()
    const pemicu = w.get('[data-testid="pemicu"]')

    ;(pemicu.element as HTMLElement).focus()
    await pemicu.trigger('focus')
    await flushPromises()

    const tip = tooltip()
    expect(tip).not.toBeNull()
    expect(tip?.textContent).toContain('Nama berkas lengkap.pdf')
    const role = document.body.querySelector('[role="tooltip"]')
    expect(role?.textContent).toContain('Nama berkas lengkap.pdf')
    expect(pemicu.attributes('aria-describedby')).toBe(role?.id)
  })

  it('Escape menutup tooltip', async () => {
    const w = mountTip()
    const pemicu = w.get('[data-testid="pemicu"]')
    ;(pemicu.element as HTMLElement).focus()
    await pemicu.trigger('focus')
    await flushPromises()
    expect(tooltip()).not.toBeNull()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(tooltip()).toBeNull()
  })

  it('blur menutup tooltip', async () => {
    const w = mountTip()
    const pemicu = w.get('[data-testid="pemicu"]')
    ;(pemicu.element as HTMLElement).focus()
    await pemicu.trigger('focus')
    await flushPromises()
    expect(tooltip()).not.toBeNull()

    await pemicu.trigger('blur')
    await flushPromises()
    expect(tooltip()).toBeNull()
  })

  it('content kosong atau disabled: tidak pernah tampil dan tanpa data-tooltip', async () => {
    const kosong = mountTip({ content: '' })
    const pemicu = kosong.get('[data-testid="pemicu"]')
    await pemicu.trigger('focus')
    await flushPromises()
    expect(tooltip()).toBeNull()
    expect(pemicu.attributes('data-tooltip')).toBeUndefined()
    kosong.unmount()

    const mati = mountTip({ disabled: true })
    await mati.get('[data-testid="pemicu"]').trigger('focus')
    await flushPromises()
    expect(tooltip()).toBeNull()
  })
})
