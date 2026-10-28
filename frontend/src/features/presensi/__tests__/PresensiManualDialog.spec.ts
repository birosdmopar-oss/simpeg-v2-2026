/**
 * PresensiManualDialog (D-03, AC-FE.5): Tambah mengirim nip/tgl/jam (kosong = null); Edit mengunci NIP & tanggal dan
 * mengirim jam yang tidak diubah apa adanya (detik tidak hilang → bukti lokasi tidak dikosongkan server, ◻ Q5A-16);
 * 422 per kolom ditampilkan; 403 `bukan_akun_presensi_manual` → pesan server. Validasi mengikat di server.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'status' in v && 'original' in v,
}))

import { api } from '@/lib/axios'

import PresensiManualDialog from '../components/PresensiManualDialog.vue'
import type { BarisPresensiAdmin } from '../types'

const post = vi.mocked(api.post)
const put = vi.mocked(api.put)
const gagal = (status: number, message = 'x', errors: Record<string, string[]> | null = null) =>
  Promise.reject({ status, message, errors, isNetworkError: false, original: {} })

const BARIS: BarisPresensiAdmin = {
  nip: '199001012020011001', tgl: '2026-10-27', jam_masuk: '07:00:30', jam_pulang: '16:00:00', sumber: 1,
  lokasi_masuk: 'Kantor Pusat', lokasi_pulang: null, aksi: { ubah: true, hapus: true },
}

const testid = (id: string) => document.body.querySelector<HTMLElement>(`[data-testid="${id}"]`)
const input = (id: string) => document.body.querySelector<HTMLInputElement>(`[data-testid="${id}"] input, input[data-testid="${id}"]`)

async function isi(id: string, nilai: string): Promise<void> {
  const el = input(id)
  if (!el) throw new Error(`input ${id} tidak ada`)
  el.value = nilai
  el.dispatchEvent(new Event('input'))
  el.dispatchEvent(new Event('change'))
  await flushPromises()
}

async function mountDialog(baris: BarisPresensiAdmin | null, nipAwal = '') {
  const wrapper = mount(PresensiManualDialog, { props: { open: true, baris, nipAwal }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  post.mockReset()
  put.mockReset()
})
afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('PresensiManualDialog', () => {
  it('Edit: NIP & tanggal terkunci; jam tidak diubah dikirim apa adanya (dengan detik), jam yang diubah dikirim baru', async () => {
    put.mockImplementation(() => Promise.resolve({ data: {}, headers: {} }) as never)
    const wrapper = await mountDialog(BARIS)
    expect(input('presensi-manual-nip')?.disabled).toBe(true)
    expect(input('presensi-manual-tgl')?.disabled).toBe(true)

    await isi('presensi-manual-jam-pulang', '17:00')
    testid('presensi-manual-simpan')?.click()
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/presensi/manual/199001012020011001/2026-10-27', { jam_masuk: '07:00:30', jam_pulang: '17:00' })
    expect(wrapper.emitted('saved')?.[0]?.[0]).toContain('diperbarui')
    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false])
  })

  it('Tambah: nip dari detail pegawai, 422 per kolom ditampilkan tanpa menutup dialog', async () => {
    post.mockImplementation(() =>
      gagal(422, 'Validasi gagal.', { tgl: ['Presensi pegawai ini pada tanggal tersebut sudah ada. Gunakan Edit.'], jam_masuk: ['Isi minimal salah satu: jam masuk atau jam pulang.'] }) as never)
    const wrapper = await mountDialog(null, '199001012020011001')
    expect(input('presensi-manual-nip')?.value).toBe('199001012020011001')
    expect(input('presensi-manual-nip')?.disabled).toBe(false)

    testid('presensi-manual-simpan')?.click()
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/presensi/manual', { nip: '199001012020011001', tgl: '', jam_masuk: null, jam_pulang: null })
    expect(testid('presensi-manual-form')?.textContent).toContain('sudah ada. Gunakan Edit.')
    expect(testid('presensi-manual-form')?.textContent).toContain('Isi minimal salah satu')
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('403 bukan akun khusus → pesan server', async () => {
    post.mockImplementation(() => gagal(403, 'Hanya akun khusus yang dapat mengelola presensi manual.') as never)
    await mountDialog(null, '1990')
    testid('presensi-manual-simpan')?.click()
    await flushPromises()
    expect(testid('presensi-manual-error')?.textContent).toContain('Hanya akun khusus')
  })
})
