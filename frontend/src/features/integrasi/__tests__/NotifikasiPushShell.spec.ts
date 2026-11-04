/**
 * MAKE-066 (F6-13) — banner izin push web: tampil hanya saat izin belum ditanya dan belum ditunda; "Aktifkan" (klik/
 * keyboard) meminta izin lewat composable; "Nanti" menyimpan penundaan; izin sudah diberikan → token didaftarkan tanpa
 * banner; ditolak/tidak didukung → tidak tampil. Memakai komponen `shared/ui` (UiNotice role="status", UiButton).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const push = vi.hoisted(() => ({
  izin: 'default' as string,
  ditunda: false,
  aktifkanNotifikasiPush: vi.fn(async () => 'granted'),
  daftarkanTokenPush: vi.fn(async () => true),
  tandaiDitunda: vi.fn(),
}))

vi.mock('../composables/notifikasiPush', () => ({
  izinNotifikasi: () => push.izin,
  sudahDitunda: () => push.ditunda,
  aktifkanNotifikasiPush: push.aktifkanNotifikasiPush,
  daftarkanTokenPush: push.daftarkanTokenPush,
  tandaiDitunda: push.tandaiDitunda,
}))

import NotifikasiPushShell from '../components/NotifikasiPushShell.vue'

beforeEach(() => {
  push.izin = 'default'
  push.ditunda = false
  vi.clearAllMocks()
})

describe('NotifikasiPushShell', () => {
  it('izin belum ditanya → banner role status; Aktifkan meminta izin lalu banner hilang', async () => {
    const w = mount(NotifikasiPushShell)
    await flushPromises()

    const banner = w.get('[data-testid="notifikasi-push-banner"]')
    expect(banner.attributes('role')).toBe('status')

    await w.get('[data-testid="notifikasi-push-aktifkan"]').trigger('click')
    await flushPromises()

    expect(push.aktifkanNotifikasiPush).toHaveBeenCalledOnce()
    expect(w.find('[data-testid="notifikasi-push-banner"]').exists()).toBe(false)
  })

  it('tombol adalah <button> yang bisa difokus keyboard; Nanti menyimpan penundaan', async () => {
    const w = mount(NotifikasiPushShell, { attachTo: document.body })
    await flushPromises()

    const nanti = w.get('[data-testid="notifikasi-push-nanti"]')
    expect(nanti.element.tagName).toBe('BUTTON')
    ;(nanti.element as HTMLButtonElement).focus()
    expect(document.activeElement).toBe(nanti.element)

    await nanti.trigger('click')
    expect(push.tandaiDitunda).toHaveBeenCalledOnce()
    expect(push.aktifkanNotifikasiPush).not.toHaveBeenCalled()
    expect(w.find('[data-testid="notifikasi-push-banner"]').exists()).toBe(false)
    w.unmount()
  })

  it.each([
    ['granted', false, true],
    ['denied', false, false],
    ['tidak-didukung', false, false],
    ['default', true, false],
  ])('izin %s (ditunda %s) → tanpa banner; daftar diam-diam = %s', async (izin, ditunda, daftar) => {
    push.izin = izin
    push.ditunda = ditunda

    const w = mount(NotifikasiPushShell)
    await flushPromises()

    expect(w.find('[data-testid="notifikasi-push-banner"]').exists()).toBe(false)
    expect(push.daftarkanTokenPush).toHaveBeenCalledTimes(daftar ? 1 : 0)
  })
})
