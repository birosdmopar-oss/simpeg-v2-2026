/**
 * StrukturOrganisasiPage (B-19, MAKE-012): bagan dari `GET struktur-organisasi`, pejabat definitif/Plt/Plh/lowong,
 * filter unit → satker, periode kosong, galat tanpa data contoh.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/struktur.service', () => ({ strukturService: { pohon: vi.fn() } }))
vi.mock('@/shared/services/masterOptions', () => ({ masterOptions: vi.fn() }))

import { Role } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'
import { masterOptions } from '@/shared/services/masterOptions'

import { strukturService } from '../services/struktur.service'
import type { OrgNode, StrukturOrganisasi } from '../types'
import StrukturOrganisasiPage from '../views/StrukturOrganisasiPage.vue'

import { apiError } from './fixtures'

const pohon = vi.mocked(strukturService.pohon)
const opsi = vi.mocked(masterOptions)

const simpul = (id: number, jabatan: string, extra: Partial<OrgNode> = {}): OrgNode => ({
  id: `sj-${id}`,
  id_jabatan: id,
  id_atasan: null,
  jabatan,
  level_struktur: 2,
  id_unit: 1,
  unit: 'Unit A',
  id_satker: 10,
  satker: 'Satker A',
  pejabat: null,
  children: [],
  ...extra,
})

const DATA: StrukturOrganisasi = {
  periode: { id_periode_struktur_jabatan: 3, periode_struktur_jabatan: '2025' },
  filter: { id_unit: null, id_satker: null },
  nodes: [
    simpul(1, 'Sekretaris', {
      pejabat: { nip: '197001012000011001', nama_lengkap: 'Dr. Budi, M.Si.', gol_ruang: 'IV/d', jenis: 'definitif', pejabat_so: '' },
      children: [
        simpul(2, 'Kepala Biro', {
          id_atasan: 1,
          pejabat: { nip: '198001012005011002', nama_lengkap: 'Siti', gol_ruang: null, jenis: 'plt', pejabat_so: '' },
        }),
        simpul(3, 'Kepala Bagian', { id_atasan: 1 }),
      ],
    }),
  ],
}

async function mountPage() {
  loginAs(Role.PEGAWAI)
  const router = createShellRouter()
  router.addRoute({ path: '/struktur-organisasi', name: 'org-structure', component: StrukturOrganisasiPage })
  await router.push('/struktur-organisasi')
  await router.isReady()
  const wrapper = mount(StrukturOrganisasiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  pohon.mockReset()
  opsi.mockReset()
  pohon.mockResolvedValue(DATA)
  opsi.mockImplementation(async (entity: string) =>
    entity === 'unit' ? [{ id: '1', nama: 'Unit A', parent: null }] : [{ id: '10', nama: 'Satker A', parent: '1' }],
  )
})

describe('StrukturOrganisasiPage', () => {
  it('menampilkan bagan: pejabat definitif, Plt berlencana, jabatan lowong', async () => {
    const w = await mountPage()

    expect(pohon).toHaveBeenCalledWith({ id_unit: '', id_satker: '' })
    expect(w.get('[data-testid="struktur-periode"]').text()).toContain('2025')
    expect(w.get('[data-testid="org-node-sj-1"]').text()).toContain('Dr. Budi, M.Si.')
    expect(w.get('[data-testid="org-node-sj-1"]').text()).toContain('IV/d')
    expect(w.find('[data-testid="org-node-sj-1-jenis"]').exists()).toBe(false)
    expect(w.get('[data-testid="org-node-sj-2-jenis"]').text()).toBe('Plt.')
    expect(w.find('[data-testid="org-node-sj-3-lowong"]').exists()).toBe(true)
  })

  it('filter unit memuat opsi satker lalu memuat ulang; satker mempersempit', async () => {
    const w = await mountPage()

    await w.get('[data-testid="struktur-filter-unit"] select').setValue('1')
    await flushPromises()
    expect(opsi).toHaveBeenCalledWith('satker', '1')
    expect(pohon).toHaveBeenLastCalledWith({ id_unit: '1', id_satker: '' })

    await w.get('[data-testid="struktur-filter-satker"] select').setValue('10')
    await flushPromises()
    expect(pohon).toHaveBeenLastCalledWith({ id_unit: '1', id_satker: '10' })
  })

  it('tanpa periode aktif → pemberitahuan; galat API → pesan tanpa bagan', async () => {
    pohon.mockResolvedValueOnce({ periode: null, filter: { id_unit: null, id_satker: null }, nodes: [] })
    const w = await mountPage()
    expect(w.find('[data-testid="struktur-tanpa-periode"]').exists()).toBe(true)

    pohon.mockRejectedValueOnce(apiError(500))
    const w2 = await mountPage()
    expect(w2.find('[data-testid="struktur-error"]').exists()).toBe(true)
    expect(w2.find('[data-testid="struktur-pohon"]').exists()).toBe(false)
  })
})
