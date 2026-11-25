/**
 * CR-190: varian `appearance="v5"` (SIMPEG V5 "Liquid Glass") di UiButton, UiTextField, dan UiNotice. Varian ini
 * aditif: test di bawah juga mengunci bahwa tampilan bawaan tidak ikut berubah.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter, RouterLink } from 'vue-router'

import { UiButton, UiNotice, UiTextField } from '..'

describe('UiButton appearance="v5"', () => {
  it('pill 38 navy + glow, ring fokus 2 lapis; tidak memakai kelas tampilan bawaan', () => {
    const w = mount(UiButton, { props: { appearance: 'v5', block: true }, slots: { default: 'Masuk' } })
    const cls = w.classes()
    expect(cls).toEqual(expect.arrayContaining(['h-[38px]', 'rounded-full', 'bg-v5-primary', 'text-white', 'shadow-v5-button', 'w-full']))
    expect(cls).toEqual(expect.arrayContaining(['hover:bg-v5-primary-hover', 'active:bg-v5-primary-pressed']))
    // R1 P3: ring utilitas (menang atas :focus-visible global main.css), bukan box-shadow yang tertimpa.
    expect(cls).toEqual(
      expect.arrayContaining(['focus-visible:outline-none', 'focus-visible:ring-2', 'focus-visible:ring-v5-blue', 'focus-visible:ring-offset-2', 'focus-visible:ring-offset-white']),
    )
    expect(cls).not.toContain('bg-brand-tertiary')
    expect(cls).not.toContain('rounded-lg')
    expect(w.text()).toBe('Masuk')
  })

  it('loading: tetap navy, disabled, aria-busy, spinner', () => {
    const w = mount(UiButton, { props: { appearance: 'v5', loading: true }, slots: { default: 'Memproses...' } })
    expect(w.attributes('disabled')).toBeDefined()
    expect(w.attributes('aria-busy')).toBe('true')
    expect(w.find('svg.animate-spin').exists()).toBe(true)
    expect(w.classes()).toContain('bg-v5-primary')
    expect(w.classes()).toContain('cursor-progress')
  })

  it('disabled (bukan loading): abu V5 tanpa aria-busy', () => {
    const w = mount(UiButton, { props: { appearance: 'v5', disabled: true } })
    expect(w.attributes('disabled')).toBeDefined()
    expect(w.attributes('aria-busy')).toBeUndefined()
    expect(w.classes()).toEqual(expect.arrayContaining(['bg-v5-disabled', 'text-v5-text-disabled']))
    expect(w.classes()).not.toContain('bg-v5-primary')
  })

  it('variant secondary: isi abu V5 12%, teks gelap, tanpa glow navy', () => {
    const cls = mount(UiButton, { props: { appearance: 'v5', variant: 'secondary' } }).classes()
    expect(cls).toEqual(expect.arrayContaining(['h-[38px]', 'rounded-full', 'bg-v5-disabled', 'text-v5-text', 'hover:bg-v5-secondary-hover', 'focus-visible:ring-v5-blue']))
    expect(cls).not.toContain('bg-v5-primary')
    expect(cls).not.toContain('shadow-v5-button')
  })

  it('variant secondary + `to`: root RouterLink (<a href>) dengan kelas V5 secondary dan atribut pemanggil', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'awal', component: { render: () => null } },
        { path: '/login', name: 'login', component: { render: () => null } },
      ],
    })
    await router.push('/')
    await router.isReady()
    const w = mount(UiButton, {
      props: { appearance: 'v5', variant: 'secondary', block: true, to: { name: 'login' } },
      attrs: { 'data-testid': 'kembali' },
      slots: { default: 'Kembali ke halaman masuk' },
      global: { plugins: [router] },
    })
    expect(w.findComponent(RouterLink).exists()).toBe(true)
    expect(w.element.tagName).toBe('A')
    expect(w.attributes('href')).toBe('/login')
    expect(w.attributes('data-testid')).toBe('kembali')
    expect(w.classes()).toEqual(expect.arrayContaining(['bg-v5-disabled', 'rounded-full', 'w-full']))
    expect(w.text()).toBe('Kembali ke halaman masuk')
  })

  it('tampilan bawaan tidak berubah', () => {
    const cls = mount(UiButton).classes()
    expect(cls).toEqual(expect.arrayContaining(['bg-brand-tertiary', 'h-10', 'rounded-lg']))
    expect(cls.some((c) => c.includes('v5'))).toBe(false)
  })
})

describe('UiTextField appearance="v5"', () => {
  it('label di atas + tanda wajib, kotak 38 radius 12 border V5', () => {
    const w = mount(UiTextField, { props: { appearance: 'v5', label: 'Username / NIP', required: true } })
    const label = w.find('label')
    expect(label.text()).toBe('Username / NIP *')
    expect(label.classes()).toContain('text-v5-text')
    const input = w.find('input')
    expect(label.attributes('for')).toBe(input.attributes('id'))
    expect(input.attributes('aria-required')).toBe('true')
    expect(input.classes()).toEqual(expect.arrayContaining(['h-[38px]', 'rounded-v5-md', 'border-v5-border']))
    expect(input.classes()).toEqual(expect.arrayContaining(['focus:ring-2', 'focus:ring-v5-blue', 'focus:ring-offset-2', 'focus:ring-offset-white']))
    expect(input.attributes('aria-invalid')).toBeUndefined()
  })

  it('error: aria-invalid, aria-describedby → helper role="alert" dengan ikon, border & ring merah', () => {
    const w = mount(UiTextField, { props: { appearance: 'v5', label: 'Password', state: 'error', helpText: 'Password wajib diisi.' } })
    const input = w.find('input')
    expect(input.attributes('aria-invalid')).toBe('true')
    const help = w.find(`#${input.attributes('aria-describedby')}`)
    expect(help.attributes('role')).toBe('alert')
    expect(help.text()).toBe('Password wajib diisi.')
    expect(help.classes()).toContain('text-v5-error-strong')
    expect(help.find('svg[aria-hidden="true"]').exists()).toBe(true)
    expect(input.classes()).toEqual(expect.arrayContaining(['border-v5-error', 'focus:ring-2', 'focus:ring-v5-error', 'focus:ring-offset-2']))
    expect(input.classes()).not.toContain('focus:ring-v5-blue')
  })

  it('disabled: isi abu V5', () => {
    const input = mount(UiTextField, { props: { appearance: 'v5', disabled: true } }).find('input')
    expect(input.attributes('disabled')).toBeDefined()
    expect(input.classes()).toContain('bg-v5-disabled')
  })

  it('tampilan bawaan (stacked) tidak berubah: tanpa kelas v5 dan tanpa ikon di helper', () => {
    const w = mount(UiTextField, { props: { label: 'L', state: 'error', helpText: 'Salah' } })
    expect(w.find('label').classes()).toEqual(['mb-1', 'block', 'text-body2', 'font-medium', 'text-danger'])
    expect(w.find('input').classes()).toEqual(expect.arrayContaining(['h-11', 'rounded-lg', 'border-danger']))
    expect(w.html()).not.toContain('v5')
    expect(w.find('p').find('svg').exists()).toBe(false)
  })
})

describe('UiNotice appearance="v5"', () => {
  it.each([
    ['danger', 'alert', 'bg-v5-error-subtle', 'text-v5-error-strong'],
    ['warning', 'alert', 'bg-v5-warning-subtle', 'text-v5-warning-strong'],
    ['success', 'status', 'bg-v5-success-subtle', 'text-v5-success-strong'],
    ['info', 'status', 'bg-v5-info-subtle', 'text-v5-info-strong'],
  ] as const)('tone %s → role %s, latar %s, judul %s', (tone, role, bg, strong) => {
    const w = mount(UiNotice, { props: { appearance: 'v5', tone, title: 'Judul' }, slots: { default: 'Isi pesan' } })
    expect(w.attributes('role')).toBe(role)
    expect(w.attributes('data-appearance')).toBe('v5')
    expect(w.classes()).toEqual(expect.arrayContaining([bg, 'rounded-v5-md']))
    const judul = w.find('p')
    expect(judul.text()).toBe('Judul')
    expect(judul.classes()).toContain(strong)
    expect(w.find('svg[aria-hidden="true"]').exists()).toBe(true)
    expect(w.text()).toContain('Isi pesan')
  })

  it('tanpa judul: isi memakai gaya judul; teks sama persis dengan slot', () => {
    const w = mount(UiNotice, { props: { appearance: 'v5', tone: 'danger' }, slots: { default: 'Username atau password salah.' } })
    expect(w.find('p').exists()).toBe(false)
    expect(w.text()).toBe('Username atau password salah.')
    expect(w.html()).toContain('text-v5-error-strong')
  })

  it('dismissible tetap memancarkan dismiss', async () => {
    const w = mount(UiNotice, { props: { appearance: 'v5', dismissible: true } })
    await w.get('button[aria-label="Tutup pesan"]').trigger('click')
    expect(w.emitted('dismiss')).toHaveLength(1)
  })

  it('tampilan bawaan tidak berubah', () => {
    const w = mount(UiNotice, { props: { tone: 'danger' }, slots: { default: 'x' } })
    expect(w.attributes('role')).toBe('alert')
    expect(w.classes()).toEqual(expect.arrayContaining(['rounded-xl', 'bg-danger-soft']))
    expect(w.attributes('data-appearance')).toBeUndefined()
  })
})
