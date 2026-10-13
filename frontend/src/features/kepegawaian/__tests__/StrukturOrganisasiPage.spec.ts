/**
 * StrukturOrganisasiPage (B-19, MAKE-012; ikut templat legacy so_012025): pemilih unit/satker (?id_unit_satker=),
 * judul, susunan bagan per format (kementerian / unit / satker), Plt/Plh & lowong, kotak JF, galat tanpa data contoh.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/struktur.service', () => ({ strukturService: { bagan: vi.fn(), pdf: vi.fn() } }))

import { Role } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { strukturService } from '../services/struktur.service'
import { susunBagan } from '../soTemplat'
import type { SoKotak, StrukturOrganisasi } from '../types'
import StrukturOrganisasiPage from '../views/StrukturOrganisasiPage.vue'

import { apiError } from './fixtures'

const bagan = vi.mocked(strukturService.bagan)

const k = (jabatan: string, pejabat = '', extra: Partial<SoKotak> = {}): SoKotak => ({
  jabatan,
  pejabat,
  is_plt: false,
  jenis: pejabat ? 'definitif' : null,
  ...extra,
})

const dasar = (extra: Partial<StrukturOrganisasi>): StrukturOrganisasi => ({
  periode: { id_periode_struktur_jabatan: 3, periode_struktur_jabatan: '2025' },
  tanggal: '2026-10-13',
  bulan: '2026-10',
  bulan_tersedia: [
    { value: '2026-10', label: 'Oktober 2026' },
    { value: '2026-09', label: 'September 2026' },
  ],
  id_unit_satker: '0',
  kementerian: 'Kementerian Uji',
  pilihan: [
    { value: '5', label: 'Deputi Uji', level: 1, disabled: false },
    { value: '6', label: 'UPT', level: 1, disabled: true },
    { value: 'sat_9', label: 'Balai Uji', level: 2, disabled: false },
  ],
  judul: 'Kementerian Uji',
  format: 'kementerian',
  data: {},
  ...extra,
})

const KEMENTERIAN = dasar({
  data: {
    menteri: k('Menteri', 'Menteri Uji'),
    wakil_menteri: k('Wakil Menteri', 'Wamen Uji'),
    staf_ahli: [k('Staf Ahli Uji')],
    inspektorat: k('Inspektur Jenderal', 'Irjen, IV/e, 1'),
    sekretaris: k('Sekretaris Kementerian', 'Budi, IV/e, 197001012000011001', {
      list_es2: [k('Kepala Biro', 'Plt. Siti, IV/a, 2', { jenis: 'plt', is_plt: true, satker: 'Biro A' })],
    }),
    deputi: [k('Deputi Bidang Uji', '', { list_es2: [k('Direktur Uji')] })],
  },
})

async function mountPage(query = '') {
  loginAs(Role.PEGAWAI)
  const router = createShellRouter()
  router.addRoute({ path: '/struktur-organisasi', name: 'org-structure', component: StrukturOrganisasiPage })
  await router.push(`/struktur-organisasi${query}`)
  await router.isReady()
  const wrapper = mount(StrukturOrganisasiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

const teks = (w: ReturnType<typeof mount>) => w.get('[data-testid="struktur-pohon"]').text()

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  bagan.mockReset()
  bagan.mockResolvedValue(KEMENTERIAN)
})

describe('StrukturOrganisasiPage', () => {
  it('kementerian: Menteri + Wakil → staf ahli, inspektorat, sekretaris (Es.II), deputi; Plt berlencana, lowong', async () => {
    const { wrapper } = await mountPage()

    expect(bagan).toHaveBeenCalledWith('0', undefined)
    expect(wrapper.get('[data-testid="struktur-judul"]').text()).toContain('Kementerian Uji')
    const t = teks(wrapper)
    for (const s of ['Menteri Uji', 'Wamen Uji', 'Staf Ahli Uji', 'Irjen, IV/e, 1', 'Budi, IV/e, 197001012000011001', 'Plt. Siti', 'Deputi Bidang Uji', 'Direktur Uji']) {
      expect(t).toContain(s)
    }
    expect(wrapper.findAll('[data-testid$="-jenis"]').map((b) => b.text())).toEqual(['Plt.'])
    expect(wrapper.findAll('[data-testid$="-lowong"]').length).toBeGreaterThan(0)
  })

  it('pemilih: opsi dari backend (unit 6 nonaktif); memilih → ?id_unit_satker= dan muat ulang', async () => {
    const { wrapper, router } = await mountPage()
    const select = wrapper.get<HTMLSelectElement>('[data-testid="struktur-pilihan"]')

    expect(select.findAll('option').map((o) => o.text())).toEqual(['Kementerian Uji', '─ Deputi Uji', '─ UPT', '─── Balai Uji'])
    expect(select.find('option[value="6"]').attributes('disabled')).toBeDefined()

    bagan.mockResolvedValueOnce(dasar({ id_unit_satker: 'sat_9', format: 'upt', judul: 'Balai Uji', data: { es2: k('Balai Uji'), es3: [k('Subbagian Uji')], es4: [] } }))
    await select.setValue('sat_9')
    await flushPromises()

    expect(router.currentRoute.value.query.id_unit_satker).toBe('sat_9')
    expect(bagan).toHaveBeenLastCalledWith('sat_9', undefined)
    expect(teks(wrapper)).toContain('Subbagian Uji')
    expect(teks(wrapper)).toContain('Jabatan Fungsional dan Jabatan Pelaksana')
  })

  it('pemilih bulan → ?bulan= dan muat ulang; Unduh PDF memakai bulan aktif', async () => {
    const { wrapper, router } = await mountPage()
    bagan.mockResolvedValueOnce({ ...KEMENTERIAN, bulan: '2026-09' })
    await wrapper.get<HTMLSelectElement>('[data-testid="struktur-bulan"]').setValue('2026-09')
    await flushPromises()
    expect(router.currentRoute.value.query.bulan).toBe('2026-09')
    expect(bagan).toHaveBeenLastCalledWith('0', '2026-09')

    vi.mocked(strukturService.pdf).mockResolvedValue(new Blob(['%PDF'], { type: 'application/pdf' }))
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:so'), revokeObjectURL: vi.fn() })
    const klik = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
    await wrapper.get('[data-testid="struktur-unduh"]').trigger('click')
    await flushPromises()
    expect(strukturService.pdf).toHaveBeenCalledWith('0', '2026-09')
    expect(klik).toHaveBeenCalled()
    klik.mockRestore()
  })

  it('susunan unit: Es.I → sekretariat (Es.III + JF) lalu Es.II → Es.III → Es.IV / JF', () => {
    const pohon = susunBagan(dasar({
      format: 'deputi',
      data: {
        es1: k('Deputi'),
        es2_sek: [k('Sekretariat Deputi', '', { es3: [k('Bagian Umum')] })],
        es2: [k('Direktorat A', '', { es3: [k('Subdit 1', '', { es4: [k('Seksi 1')] }), k('Subdit 2', '', { es4: [] })] })],
      },
    }))

    expect(pohon.map((n) => n.jabatan)).toEqual(['Deputi'])
    const [sek, dir] = pohon[0]!.children
    expect(sek!.children.map((n) => n.jabatan)).toEqual(['Bagian Umum', 'Jabatan Fungsional dan Jabatan Pelaksana'])
    expect(dir!.children.map((n) => n.children.map((c) => c.jabatan))).toEqual([['Seksi 1'], ['Jabatan Fungsional dan Jabatan Pelaksana']])
  })

  it('susunan satker BPO: Es.II → sekretariat, SPI, Es.III (→ Es.IV / JF), Es.IV tanpa induk', () => {
    const pohon = susunBagan(dasar({
      format: 'bpo',
      data: {
        es2_sek: k('Sekretariat Badan'),
        es2: k('Badan Pelaksana Uji'),
        es3_sek: k('Satuan Pemeriksaan Intern'),
        es3: [k('Divisi A', '', { es4: [k('Unit A1')] }), k('Divisi B', '', { es4: [] })],
        es4: [k('Unit Lepas')],
      },
    }))

    expect(pohon.map((n) => n.jabatan)).toEqual(['Badan Pelaksana Uji'])
    expect(pohon[0]!.children.map((n) => n.jabatan)).toEqual(['Sekretariat Badan', 'Satuan Pemeriksaan Intern', 'Divisi A', 'Divisi B', 'Unit Lepas'])
    expect(pohon[0]!.children[2]!.children.map((n) => n.jabatan)).toEqual(['Unit A1'])
    expect(pohon[0]!.children[3]!.children.map((n) => n.jf)).toEqual([true])
  })

  it('tanpa periode aktif → pemberitahuan; galat API → pesan tanpa bagan', async () => {
    bagan.mockResolvedValueOnce(dasar({ periode: null, data: { staf_ahli: [], deputi: [] } }))
    const { wrapper } = await mountPage()
    expect(wrapper.find('[data-testid="struktur-tanpa-periode"]').exists()).toBe(true)

    bagan.mockRejectedValueOnce(apiError(422))
    const { wrapper: w2 } = await mountPage('?id_unit_satker=sat_999')
    expect(bagan).toHaveBeenLastCalledWith('sat_999', undefined)
    expect(w2.find('[data-testid="struktur-error"]').exists()).toBe(true)
    expect(w2.find('[data-testid="struktur-pohon"]').exists()).toBe(false)
  })
})
