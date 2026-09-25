/**
 * CR-009 — masterService: filter field allowlist (meta `filters`) dikirim sebagai parameter query daftar & dropdown,
 * nilai kosong tidak dikirim; parameter lama (search/status/parent/page) tetap sama.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn() },
}))

import { api } from '@/lib/axios'

import { masterService } from '../services/master.service'

const get = vi.mocked(api.get)

beforeEach(() => {
  get.mockReset()
  get.mockResolvedValue({ data: [] })
})

describe('masterService — filter field (CR-009)', () => {
  it('list: filter terisi digabung ke parameter query, yang kosong dibuang', async () => {
    await masterService.list('uji-diklat', { search: '', status: '1', page: 2, per_page: 20, filters: { jenis: '2', aktif: '' } })

    expect(get).toHaveBeenCalledWith('/master/uji-diklat', { params: { status: '1', page: 2, per_page: 20, jenis: '2' } })
  })

  it('options: parent + filter; tanpa keduanya params kosong', async () => {
    await masterService.options('pangkat', null, { cpns: '1' })
    expect(get).toHaveBeenLastCalledWith('/master/pangkat/options', { params: { cpns: '1' } })

    await masterService.options('kabupaten-kota', '31', { tipe: '' })
    expect(get).toHaveBeenLastCalledWith('/master/kabupaten-kota/options', { params: { parent: '31' } })

    await masterService.options('agama')
    expect(get).toHaveBeenLastCalledWith('/master/agama/options', { params: {} })
  })
})
