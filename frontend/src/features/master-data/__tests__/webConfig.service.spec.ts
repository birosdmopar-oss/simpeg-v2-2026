/**
 * G-09 Web Config (DBV-006/CR-030) — bentuk request webConfigService ke /web-config, termasuk key legacy ber-`/`.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import { api } from '@/lib/axios'

import { webConfigPath, webConfigService } from '../services/webConfig.service'

beforeEach(() => {
  for (const fn of [api.get, api.post, api.put, api.patch, api.delete]) vi.mocked(fn).mockReset()
})

describe('webConfigService', () => {
  it('path: segmen di-encode terpisah, garis miring key legacy tetap', () => {
    expect(webConfigPath('nama_kementerian')).toBe('/web-config/nama_kementerian')
    expect(webConfigPath('TL1/PSW1')).toBe('/web-config/TL1/PSW1')
    expect(webConfigPath('a b/c?d')).toBe('/web-config/a%20b/c%3Fd')
  })

  it('list, get, save, remove', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({ data: { items: [{ config_name: 'TK' }] } })
    expect(await webConfigService.list()).toEqual([{ config_name: 'TK' }])
    expect(api.get).toHaveBeenCalledWith('/web-config')

    vi.mocked(api.get).mockResolvedValueOnce({ data: { config_name: 'TK' } })
    await webConfigService.get('TK')
    expect(api.get).toHaveBeenLastCalledWith('/web-config/TK')

    vi.mocked(api.put).mockResolvedValue({ data: { config_name: 'TL1/PSW1', config_value: '0.5' } })
    await webConfigService.save('TL1/PSW1', { config_value: '0.50', remark: 'uji' })
    expect(api.put).toHaveBeenCalledWith('/web-config/TL1/PSW1', { config_value: '0.50', remark: 'uji' })

    vi.mocked(api.delete).mockResolvedValue({ data: { deleted: true, item: { config_name: 'TK' } } })
    expect((await webConfigService.remove('TK')).deleted).toBe(true)
    expect(api.delete).toHaveBeenCalledWith('/web-config/TK')
  })
})
