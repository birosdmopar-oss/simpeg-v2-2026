/**
 * G-08 Hari Libur (DBV-003/CR-010) — bentuk request hariLiburService ke endpoint /hari-libur dan dropdown jenis libur.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import { api } from '@/lib/axios'

import { hariLiburService } from '../services/hariLibur.service'

const row = {
  id_libur: 2,
  id_jenis_libur: 2,
  jenis_libur: 'Cuti Bersama',
  tgl_mulai: '2026-03-19',
  tgl_akhir: '2026-03-20',
  nama_libur: 'Cuti Bersama Idul Fitri',
  keterangan: null,
  status: '1',
  created_at: null,
  updated_at: null,
  updated_by: null,
}

beforeEach(() => {
  for (const fn of [api.get, api.post, api.put, api.patch, api.delete]) vi.mocked(fn).mockReset()
})

describe('hariLiburService', () => {
  it('list: parameter kosong tidak dikirim', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { items: [row], total: 1, page: 1, per_page: 20 } })

    const result = await hariLiburService.list({ tahun: '2026', search: '', status: '', page: 1, per_page: 20 })

    expect(api.get).toHaveBeenCalledWith('/hari-libur', { params: { tahun: '2026', page: 1, per_page: 20 } })
    expect(result.items[0]?.nama_libur).toBe('Cuti Bersama Idul Fitri')
  })

  it('get/create/update/setStatus/remove memakai endpoint & body yang benar', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: row })
    vi.mocked(api.post).mockResolvedValue({ data: row })
    vi.mocked(api.put).mockResolvedValue({ data: row })
    vi.mocked(api.patch).mockResolvedValue({ data: { ...row, status: '2' } })
    vi.mocked(api.delete).mockResolvedValue({ data: { deleted: true, soft_delete: true, item: { ...row, status: '10' } } })
    const payload = { tgl_mulai: '2026-03-19', tgl_akhir: '2026-03-20', id_jenis_libur: '2', nama_libur: 'Cuti Bersama Idul Fitri', status: '1' as const }

    await hariLiburService.get('2')
    expect(api.get).toHaveBeenCalledWith('/hari-libur/2')

    await hariLiburService.create(payload)
    expect(api.post).toHaveBeenCalledWith('/hari-libur', payload)

    await hariLiburService.update('2', { nama_libur: 'Cuti Bersama', keterangan: '' })
    expect(api.put).toHaveBeenCalledWith('/hari-libur/2', { nama_libur: 'Cuti Bersama', keterangan: '' })

    expect((await hariLiburService.setStatus('2', '2')).status).toBe('2')
    expect(api.patch).toHaveBeenCalledWith('/hari-libur/2/status', { status: '2' })

    expect((await hariLiburService.remove('2')).soft_delete).toBe(true)
    expect(api.delete).toHaveBeenCalledWith('/hari-libur/2')
  })

  it('jenisOptions: dropdown master jenis-libur (UL_ALL, hanya aktif)', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: [{ id: '1', nama: 'Libur Nasional', parent: null }] })

    expect(await hariLiburService.jenisOptions()).toEqual([{ id: '1', nama: 'Libur Nasional', parent: null }])
    expect(api.get).toHaveBeenCalledWith('/master/jenis-libur/options')
  })
})
