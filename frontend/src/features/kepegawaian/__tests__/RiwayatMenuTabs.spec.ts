/**
 * RiwayatMenuTabs (CR-049 D-F5): tab aktif dibawa ke area terlihat saat dipasang (`?tab=` dari tautan) dan saat nilai
 * berganti dari luar, bukan hanya saat dipilih dari popup.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, onTestFinished, vi } from 'vitest'

import RiwayatMenuTabs from '../components/RiwayatMenuTabs.vue'

const MENUS = [
  { key: 'data-umum', label: 'Data Umum' },
  { key: 'jabatan', label: 'Riwayat Jabatan' },
  { key: 'hukdis', label: 'Hukuman Disiplin' },
]

afterEach(() => {
  document.body.innerHTML = ''
  vi.restoreAllMocks()
})
enableAutoUnmount(afterEach)

describe('RiwayatMenuTabs', () => {
  it('scrollIntoView tab aktif saat mount dan saat modelValue berganti', async () => {
    const dipanggil: string[] = []
    // jsdom tidak punya scrollIntoView.
    const asli = HTMLElement.prototype.scrollIntoView
    HTMLElement.prototype.scrollIntoView = function (this: HTMLElement) {
      dipanggil.push(this.textContent?.trim() ?? '')
    }
    onTestFinished(() => {
      HTMLElement.prototype.scrollIntoView = asli
    })
    const wrapper = mount(RiwayatMenuTabs, { props: { modelValue: 'hukdis', menus: MENUS }, attachTo: document.body })
    await flushPromises()
    expect(dipanggil.at(-1)).toContain('Hukuman Disiplin')

    await wrapper.setProps({ modelValue: 'jabatan' })
    await flushPromises()
    expect(dipanggil.at(-1)).toContain('Riwayat Jabatan')
  })
})
