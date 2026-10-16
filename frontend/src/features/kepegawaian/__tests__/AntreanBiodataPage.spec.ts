/**
 * AntreanBiodataPage (B-04 × B-20 penutup, MAKE-014): antrean `GET biodata/pengajuan`, pencarian, ⋮ Setujui → Tolak →
 * Lihat pegawai; proses lewat PengajuanBiodataDialog lalu antrean dimuat ulang.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/pegawai.service', () => ({
  pegawaiService: { antreanPengajuan: vi.fn(), pengajuan: vi.fn(), prosesPengajuan: vi.fn(), fotoPengajuan: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { pegawaiService } from '../services/pegawai.service'
import AntreanBiodataPage from '../views/AntreanBiodataPage.vue'

const svc = vi.mocked(pegawaiService)
const NIP = '198001012005011001'
const item = { id_pegawai_hist: 9, nip: NIP, nama: 'Budi', glr_awal: null, glr_akhir: 'S.Kom.', unit: 'Unit A', satker: 'Satker A', jabatan: 'Analis', tgl_permohonan: '2026-10-12 09:00:00' }
const menunggu = { id_pegawai_hist: 9, flag_update: 1, reason_note: null, created_at: null, updated_at: null, notif_date: null }

async function mountPage() {
  loginAs(Role.ADMIN_SATKER)
  const router = createShellRouter()
  await router.push('/biodata/pengajuan')
  await router.isReady()
  const wrapper = mount(AntreanBiodataPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  svc.antreanPengajuan.mockResolvedValue({ items: [item], total: 1, page: 1, per_page: 20 })
  svc.pengajuan.mockResolvedValue({
    pengajuan: { ...menunggu, nip: NIP, nama: 'Budi', ada_foto: false },
    pegawai: { nip: NIP, nama: 'Budi', ada_foto: false, email: null },
    perubahan: [{ kolom: 'no_hp', lama: '0811', baru: '0822' }],
    can_process: true,
  })
})

describe('AntreanBiodataPage', () => {
  it('daftar antrean + ⋮ Setujui → Tolak → Lihat pegawai; label aksesibel', async () => {
    const { wrapper } = await mountPage()
    expect(svc.antreanPengajuan).toHaveBeenCalledWith({ page: 1, per_page: 20, search: undefined })
    expect(wrapper.get('[data-testid="antrean-biodata-row-9"]').text()).toContain('Budi, S.Kom.')
    expect((await rowMenuActions(wrapper, 'biodata-actions-9')).map((a) => a.key)).toEqual(['setujui', 'tolak', 'detail'])
    expect(wrapper.get('[data-testid="biodata-actions-9"]').attributes('aria-label')).toBe('Aksi untuk pengajuan Budi, S.Kom.')
  })

  it('Setujui → dialog dengan perubahan → proses → notifikasi & muat ulang', async () => {
    svc.prosesPengajuan.mockResolvedValue({
      pengajuan: { ...menunggu, flag_update: 3, nip: NIP, nama: 'Budi', ada_foto: false },
      pegawai: { nip: NIP, nama: 'Budi', ada_foto: false, email: null },
      perubahan: [],
      can_process: false,
    })
    const { wrapper } = await mountPage()
    await selectRowAction(wrapper, 'biodata-actions-9', 'setujui')
    await flushPromises()
    expect(document.body.querySelector('[data-testid="pengajuan-diff"] [data-kolom="no_hp"]')?.textContent).toContain('0822')
    document.body.querySelector<HTMLButtonElement>('[data-testid="approval-confirm"]')?.click()
    await vi.waitFor(() => expect(svc.prosesPengajuan).toHaveBeenCalledWith(9, expect.objectContaining({ aksi: 'setujui' })))
    await vi.waitFor(() => expect(wrapper.get('[data-testid="antrean-biodata-notice"]').text()).toContain('disetujui'))
    expect(svc.antreanPengajuan).toHaveBeenCalledTimes(2)
  })

  it('Lihat pegawai membuka detail', async () => {
    const { wrapper, router } = await mountPage()
    await selectRowAction(wrapper, 'biodata-actions-9', 'detail')
    await flushPromises()
    expect(router.currentRoute.value.params.nip).toBe(NIP)
  })
})
