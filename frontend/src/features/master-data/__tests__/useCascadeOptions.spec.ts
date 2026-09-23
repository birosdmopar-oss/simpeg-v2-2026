/**
 * Modul G — dropdown berjenjang wilayah 4 level (G-07 DoD): tiap level memuat options dari induk terpilih,
 * mengganti pilihan mengosongkan level di bawahnya.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

vi.mock('../services/master.service', () => ({
  masterService: { options: vi.fn(), get: vi.fn() },
}))

import { resolveAncestorPath, useCascadeOptions } from '../composables/useCascadeOptions'
import { masterService } from '../services/master.service'
import type { MasterMeta, MasterOption, MasterRow } from '../types'

const meta = (key: string, parent: MasterMeta['parent'] = null): MasterMeta => ({
  key,
  label: key,
  primary_key: `id_${key}`,
  id_max_length: 10,
  name_field: `nama_${key}`,
  name_label: key,
  name_max_length: 100,
  parent,
  auto_increment: false,
  has_order: true,
  has_status: true,
  fields: [],
})

const chain = [
  meta('provinsi'),
  meta('kabupaten-kota', { field: 'id_provinsi', entity: 'provinsi' }),
  meta('kecamatan', { field: 'id_kabupaten_kota', entity: 'kabupaten-kota' }),
]

const data: Record<string, MasterOption[]> = {
  'provinsi|': [{ id: '31', nama: 'DKI Jakarta', parent: null }],
  'kabupaten-kota|31': [{ id: '3171', nama: 'Jakarta Pusat', parent: '31' }],
  'kecamatan|3171': [{ id: '317101', nama: 'Gambir', parent: '3171' }],
}

beforeEach(() => {
  vi.mocked(masterService.options).mockReset()
  vi.mocked(masterService.options).mockImplementation(async (entity, parent) => data[`${entity}|${parent ?? ''}`] ?? [])
})

describe('useCascadeOptions', () => {
  it('init tanpa path: hanya level akar yang dimuat', async () => {
    const c = useCascadeOptions(ref(chain))
    await c.init()
    expect(c.levels.value.map((l) => l.options.length)).toEqual([1, 0, 0])
    expect(masterService.options).toHaveBeenCalledTimes(1)
    expect(c.leafValue()).toBe('')
  })

  it('memilih level memuat level berikutnya dari induk terpilih', async () => {
    const c = useCascadeOptions(ref(chain))
    await c.init()
    await c.select(0, '31')
    expect(c.levels.value[1]?.options.map((o) => o.id)).toEqual(['3171'])
    await c.select(1, '3171')
    expect(c.levels.value[2]?.options.map((o) => o.id)).toEqual(['317101'])
    await c.select(2, '317101')
    expect(c.leafValue()).toBe('317101')
  })

  it('mengganti level atas mengosongkan level di bawahnya', async () => {
    const c = useCascadeOptions(ref(chain))
    await c.init(['31', '3171', '317101'])
    expect(c.leafValue()).toBe('317101')
    await c.select(0, '')
    expect(c.levels.value.map((l) => l.value)).toEqual(['', '', ''])
    expect(c.levels.value[2]?.options).toEqual([])
  })
})

describe('resolveAncestorPath', () => {
  it('menelusuri induk ke atas dari id induk langsung', async () => {
    const rows: Record<string, MasterRow> = {
      'kecamatan/317101': { id_kecamatan: '317101', id_kabupaten_kota: '3171', order: 1, status: '1' },
      'kabupaten-kota/3171': { id_kabupaten_kota: '3171', id_provinsi: '31', order: 1, status: '1' },
    }
    vi.mocked(masterService.get).mockImplementation(async (entity, id) => {
      const row = rows[`${entity}/${id}`]
      if (!row) throw new Error(`unexpected ${entity}/${id}`)
      return row
    })
    expect(await resolveAncestorPath(chain, '317101')).toEqual(['31', '3171', '317101'])
  })
})
