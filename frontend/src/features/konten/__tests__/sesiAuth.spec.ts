/** Q7-02: penanda "popup sudah tampil" dikosongkan saat keluar (sesi login baru → popup tampil lagi). */
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/features/auth/services/auth.service', () => ({ authService: { logout: vi.fn().mockResolvedValue(undefined) } }))
vi.mock('@/features/integrasi/composables/notifikasiPush', () => ({ lepasTokenPush: vi.fn().mockResolvedValue(undefined) }))

import { useAuthStore } from '@/features/auth/stores/auth.store'

import { popupSudahDitampilkan, tandaiPopupDitampilkan } from '../sesi'

describe('resetSesiKonten di alur logout', () => {
  it('logout mengosongkan penanda popup', async () => {
    setActivePinia(createPinia())
    tandaiPopupDitampilkan()
    expect(popupSudahDitampilkan()).toBe(true)
    await useAuthStore().logout()
    expect(popupSudahDitampilkan()).toBe(false)
  })
})
