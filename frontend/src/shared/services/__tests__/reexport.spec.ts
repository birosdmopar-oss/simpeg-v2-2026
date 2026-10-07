/**
 * Re-export lewat `src/shared` (Sprint 0-2): modul Fase 3 memakai cascade & opsi master tanpa mengimpor
 * `features/master-data` langsung; berkas asal tidak dipindah.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/features/master-data/services/master.service', () => ({
  masterService: { options: vi.fn().mockResolvedValue([{ id: '31', nama: 'DKI Jakarta' }]) },
}))

import * as cascadeAsal from '@/features/master-data/composables/useCascadeOptions'
import { masterService } from '@/features/master-data/services/master.service'
import { resolveAncestorPath, useCascadeOptions } from '@/shared/composables/useCascadeOptions'
import { masterOptions } from '@/shared/services/masterOptions'

describe('re-export shared', () => {
  it('useCascadeOptions & resolveAncestorPath = fungsi asal master-data', () => {
    expect(useCascadeOptions).toBe(cascadeAsal.useCascadeOptions)
    expect(resolveAncestorPath).toBe(cascadeAsal.resolveAncestorPath)
  })

  it('masterOptions meneruskan argumen ke masterService.options', async () => {
    await expect(masterOptions('kota', '31', { aktif: '1' })).resolves.toEqual([{ id: '31', nama: 'DKI Jakarta' }])
    expect(masterService.options).toHaveBeenCalledWith('kota', '31', { aktif: '1' })
  })
})
