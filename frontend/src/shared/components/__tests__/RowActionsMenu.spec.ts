/**
 * RowActionsMenu — menu aksi baris ⋮ (aturan UI "aksi baris lewat menu titik tiga"): label aksesibel trigger, item
 * hidden tidak dirender, item disabled tidak memicu `select`, item danger dipisah separator dan berwarna merah, event
 * `select` mengirim key, trigger nonaktif bila tidak ada aksi yang terlihat.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { Pencil, Trash2 } from 'lucide-vue-next'
import { afterEach, describe, expect, it } from 'vitest'

import RowActionsMenu from '../RowActionsMenu.vue'
import type { RowAction } from '../rowActions'

import { openRowMenu, renderedActions, rowMenuActions, selectRowAction } from './rowActionsMenu.helpers'

const TESTID = 'row-actions-1'

function mountMenu(actions: RowAction[], extra: { disabled?: boolean } = {}) {
  return mount(RowActionsMenu, {
    props: { actions, label: 'Aksi untuk Islam', testid: TESTID, ...extra },
    attachTo: document.body,
  })
}

function menuOnPage(): HTMLElement | null {
  return document.body.querySelector(`[data-testid="${TESTID}-menu"]`)
}

// Hook after berjalan terbalik (stack): pembersihan body didaftarkan dulu agar jalan SETELAH auto-unmount.
afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('RowActionsMenu', () => {
  it('trigger ⋮ memakai label sebagai aria-label & title, menu tertutup sampai diklik', async () => {
    const wrapper = mountMenu([{ key: 'edit', label: 'Edit', icon: Pencil }])
    const trigger = wrapper.get(`[data-testid="${TESTID}"]`)

    expect(trigger.element.tagName).toBe('BUTTON')
    expect(trigger.attributes('aria-label')).toBe('Aksi untuk Islam')
    expect(trigger.attributes('title')).toBe('Aksi untuk Islam')
    expect(trigger.attributes('aria-haspopup')).toBe('menu')
    expect(trigger.attributes('aria-expanded')).toBe('false')
    expect(trigger.attributes('disabled')).toBeUndefined()
    expect(menuOnPage()).toBeNull()

    const menu = await openRowMenu(wrapper, TESTID)
    expect(menu.getAttribute('role')).toBe('menu')
    expect(trigger.attributes('aria-expanded')).toBe('true')
    // Konten di-Portal ke body, bukan di dalam komponen.
    expect(wrapper.element.contains(menu)).toBe(false)
  })

  it('bisa dibuka dengan keyboard (Enter pada trigger)', async () => {
    const wrapper = mountMenu([{ key: 'edit', label: 'Edit' }])

    await wrapper.get(`[data-testid="${TESTID}"]`).trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(menuOnPage()).not.toBeNull()
  })

  it('item hidden tidak dirender; urutan item mengikuti actions', async () => {
    const wrapper = mountMenu([
      { key: 'edit', label: 'Edit' },
      { key: 'activate', label: 'Aktifkan', hidden: true },
      { key: 'deactivate', label: 'Nonaktifkan' },
      { key: 'restore', label: 'Pulihkan', hidden: true },
    ])

    const items = await rowMenuActions(wrapper, TESTID)

    expect(items.map((i) => [i.key, i.label])).toEqual([
      ['edit', 'Edit'],
      ['deactivate', 'Nonaktifkan'],
    ])
    expect(document.body.querySelector('[data-action="activate"]')).toBeNull()
    expect(document.body.querySelector('[data-action="restore"]')).toBeNull()
  })

  it('item disabled tetap tampil (data-disabled/aria-disabled) tetapi tidak memicu select', async () => {
    const wrapper = mountMenu([
      { key: 'edit', label: 'Edit' },
      { key: 'delete', label: 'Hapus', danger: true, disabled: true },
    ])

    const menu = await openRowMenu(wrapper, TESTID)
    const item = menu.querySelector<HTMLElement>('[data-action="delete"]')
    expect(item?.hasAttribute('data-disabled')).toBe(true)
    expect(item?.getAttribute('aria-disabled')).toBe('true')
    expect(menu.querySelector('[data-action="edit"]')?.hasAttribute('data-disabled')).toBe(false)

    await selectRowAction(wrapper, TESTID, 'delete')

    expect(wrapper.emitted('select')).toBeUndefined()
    expect(menuOnPage()).not.toBeNull()
  })

  it('item danger diberi separator di atasnya dan kelas merah; item biasa tidak', async () => {
    const wrapper = mountMenu([
      { key: 'edit', label: 'Edit', icon: Pencil },
      { key: 'delete', label: 'Hapus', icon: Trash2, danger: true },
    ])

    const menu = await openRowMenu(wrapper, TESTID)
    const separators = menu.querySelectorAll('[role="separator"]')
    expect(separators).toHaveLength(1)
    expect(separators[0]?.nextElementSibling?.getAttribute('data-action')).toBe('delete')
    expect(renderedActions(menu).map((i) => [i.key, i.danger])).toEqual([
      ['edit', false],
      ['delete', true],
    ])
    // Ikon ikut dirender tetapi disembunyikan dari pembaca layar.
    expect(menu.querySelector('[data-action="delete"] svg')?.getAttribute('aria-hidden')).toBe('true')
  })

  it('item danger yang menjadi item pertama terlihat tidak diberi separator', async () => {
    const wrapper = mountMenu([
      { key: 'edit', label: 'Edit', hidden: true },
      { key: 'delete', label: 'Hapus', danger: true },
    ])

    const menu = await openRowMenu(wrapper, TESTID)

    expect(menu.querySelector('[role="separator"]')).toBeNull()
    expect(renderedActions(menu).map((i) => i.key)).toEqual(['delete'])
  })

  it('memilih item memancarkan select berisi key lalu menutup menu', async () => {
    const wrapper = mountMenu([
      { key: 'edit', label: 'Edit' },
      { key: 'delete', label: 'Hapus', danger: true },
    ])

    await selectRowAction(wrapper, TESTID, 'delete')

    expect(wrapper.emitted('select')).toEqual([['delete']])
    expect(menuOnPage()).toBeNull()
    expect(wrapper.get(`[data-testid="${TESTID}"]`).attributes('aria-expanded')).toBe('false')

    await selectRowAction(wrapper, TESTID, 'edit')
    expect(wrapper.emitted('select')).toEqual([['delete'], ['edit']])
  })

  it.each([
    ['semua aksi hidden', [{ key: 'restore', label: 'Pulihkan', hidden: true }], {}],
    ['tanpa aksi', [], {}],
    ['prop disabled', [{ key: 'edit', label: 'Edit' }], { disabled: true }],
  ])('trigger nonaktif dan menu tidak bisa dibuka: %s', async (_case, actions: RowAction[], extra) => {
    const wrapper = mountMenu(actions, extra)
    const trigger = wrapper.get(`[data-testid="${TESTID}"]`)

    expect(trigger.attributes('disabled')).toBeDefined()
    await trigger.trigger('click')
    await trigger.trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(menuOnPage()).toBeNull()
    expect(wrapper.emitted('select')).toBeUndefined()
  })
})
