/**
 * MAKE-049 — halaman Rekap Tukin (klien asli, adapter palsu): lingkup role 1 vs 3, kolom densitas tinggi dengan kolom
 * identitas sticky, baris galat tetap tampil, tanpa warna baris, ekspor Excel/PDF (batas PDF 422 → pesan), varian IL,
 * paginasi, galat muat.
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, { status: number; body: unknown; headers?: Record<string, string> }>,
  permintaan: [] as { method: string; url: string; params: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const url = config.url ?? ''
    const method = (config.method ?? 'get').toLowerCase()
    server.permintaan.push({ method, url, params: config.params })
    const balasan = server.balasan[`${method} ${url}`]
    if (!balasan) throw new Error(`tidak ada balasan palsu untuk ${method} ${url}`)
    const response: AxiosResponse = { status: balasan.status, statusText: 'x', headers: balasan.headers ?? {}, config, data: balasan.body }
    if (balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})
vi.mock('@/features/master-data/services/master.service', () => ({ masterService: { options: vi.fn(async () => []) } }))

import { Role, type RoleCode } from '@/features/auth/types'
import * as berkas from '@/features/kepegawaian/services/berkasUnduhan'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import RekapTukinPage from '../views/RekapTukinPage.vue'

enableAutoUnmount(afterEach)

const sukses = (data: unknown) => ({ status: 200, body: { status: 'success', data } })
const galat = (status: number, errors?: Record<string, string[]>) => ({ status, body: { status: 'error', message: 'x', ...(errors ? { errors } : {}) } })
const minta = (url: string) => server.permintaan.filter((p) => p.url === url)

const baris = (nip: string, nama: string, ekstra: Record<string, unknown> = {}) => ({
  nip, nama, unit_es1: 'Biro Uji', unit_es2: 'Satker Uji',
  pengurangan: { TL: '0.2', PSW: '0', TK: '1', TA: '0', CUTI: '0', TB: '0' },
  total_presensi: '1.2', total_skp: '0', total_potongan: '1.2', keterangan: 'Cuti tahunan', penyesuaian: null, galat: null,
  ...ekstra,
})
const rekap = (items: unknown[], ekstra: Record<string, unknown> = {}) => ({
  items, total: String(items.length), page: '1', per_page: '25', bulan: '2026-10',
  periode: { awal: '2026-09-16', akhir: '2026-10-15' }, lingkup: { tipe: 'kementerian', id: null, kode: '0', nama: 'Kementerian Uji' },
  jumlah_galat: '0', ...ekstra,
})
const ITEMS = [baris('199001012020011001', 'Budi Santoso'), baris('199201012021011002', 'Sari Dewi')]

async function mountRekap(role: RoleCode = Role.SUPER_ADMIN, il = false) {
  loginAs(role)
  const router = createShellRouter()
  router.addRoute({ path: '/presensi/tukin/rekap', name: 'presensi-rekap-tukin', component: { render: () => null } })
  router.addRoute({ path: '/presensi/tukin/rekap-il', name: 'presensi-rekap-tukin-il', component: { render: () => null } })
  await router.push('/presensi/tukin/rekap')
  const wrapper = mount(RekapTukinPage, { props: { il }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  await flushPromises()
  return { wrapper, router }
}

const bukaEkspor = async (wrapper: Awaited<ReturnType<typeof mountRekap>>['wrapper']) => {
  await wrapper.get('[data-testid="export-trigger"]').trigger('click')
  await flushPromises()
}
const pilihFormat = async (f: string) => {
  ;(document.body.querySelector(`[data-format="${f}"]`) as HTMLElement).click()
  await flushPromises()
}

beforeEach(() => {
  server.balasan = {}
  server.permintaan = []
})

describe('RekapTukinPage', () => {
  it('role 1: parameter awal (unit_satker 0), kolom urut PRD 5B §7.3.4, nomor urut, lingkup & periode', async () => {
    server.balasan['get /presensi/tukin/rekap'] = sukses(rekap(ITEMS))
    const { wrapper } = await mountRekap()

    expect(minta('/presensi/tukin/rekap')[0]!.params).toMatchObject({ unit_satker: '0', page: 1, per_page: 25 })
    expect(wrapper.get('[data-testid="filter-laporan"]').text()).toMatch(/Unit/)
    const heads = wrapper.findAll('thead th').map((h) => h.text())
    expect(heads.slice(0, 5)).toEqual(['No', 'Nama', 'NIP', 'Unit Es. I', 'Unit Es. II'])
    expect(heads).toContain('Jumlah Pengurangan (%)')
    expect(heads.slice(6, 10)).toEqual(['Total Pengurangan Presensi (%)', 'Total Pengurangan SKP (%)', 'Total Potongan (%)', 'Keterangan'])
    expect(wrapper.findAll('thead th').filter((h) => ['TL', 'PSW', 'TK', 'TA', 'CUTI', 'TB'].includes(h.text())).map((h) => h.text())).toEqual(['TL', 'PSW', 'TK', 'TA', 'CUTI', 'TB'])
    expect(wrapper.get('[data-testid="rekap-row-199001012020011001"]').findAll('td')[0]!.text()).toBe('1')
    expect(wrapper.get('[data-testid="rekap-row-199201012021011002"]').findAll('td')[0]!.text()).toBe('2')
    expect(wrapper.get('[data-testid="rekap-ringkas"]').text()).toContain('Kementerian Uji')
    expect(wrapper.get('[data-testid="rekap-ringkas"]').text()).toContain('2 pegawai')
  })

  it('densitas tinggi: No/Nama/NIP sticky kiri dengan offset berurutan; angka koma Indonesia rata kanan', async () => {
    server.balasan['get /presensi/tukin/rekap'] = sukses(rekap(ITEMS))
    const { wrapper } = await mountRekap()

    const sel = wrapper.get('[data-testid="rekap-row-199001012020011001"]').findAll('td')
    expect(sel.slice(0, 3).every((c) => c.classes().includes('col-sticky-kiri'))).toBe(true)
    expect(sel[1]!.attributes('style')).toContain('--sticky-kiri: 3rem')
    expect(sel[2]!.attributes('style')).toContain('--sticky-kiri: 17rem')
    expect(wrapper.findAll('thead th').slice(0, 3).every((h) => h.classes().includes('col-sticky-kiri'))).toBe(true)
    expect(wrapper.get('[data-testid="rekap-row-199001012020011001"] [data-kolom="TL"]').text()).toBe('0,2')
    expect(wrapper.get('[data-testid="rekap-row-199001012020011001"] [data-kolom="TL"]').classes()).toContain('text-right')
    expect(wrapper.get('[data-testid="rekap-row-199001012020011001"] [data-kolom="total_potongan"]').text()).toBe('1,2')
  })

  it('tanpa warna baris (legacy rekap tanpa warna) dan tanpa blok penanda tangan di layar', async () => {
    server.balasan['get /presensi/tukin/rekap'] = sukses(rekap(ITEMS))
    const { wrapper } = await mountRekap()
    expect(wrapper.get('[data-testid="rekap-row-199001012020011001"]').classes().join(' ')).not.toMatch(/status-/)
    expect(wrapper.text().toLowerCase()).not.toContain('penanda tangan')
  })

  it('baris galat tetap tampil dengan penanda dan pesan; baris lain tidak hilang', async () => {
    server.balasan['get /presensi/tukin/rekap'] = sukses(
      rekap(
        [
          baris('199001012020011001', 'Budi Santoso'),
          baris('199201012021011002', 'Sari Dewi', { pengurangan: null, total_presensi: null, total_skp: null, total_potongan: null, keterangan: '', galat: { code: 'data_tukin_belum_tersedia', message: 'Data Tukin belum tersedia.' } }),
        ],
        { jumlah_galat: '1' },
      ),
    )
    const { wrapper } = await mountRekap()

    expect(wrapper.findAll('[data-testid^="rekap-row-"]')).toHaveLength(2)
    expect(wrapper.get('[data-testid="rekap-galat-199201012021011002"]').text()).toContain('Data Tukin belum tersedia.')
    expect(wrapper.get('[data-testid="rekap-row-199201012021011002"] [data-kolom="TL"]').text()).toBe('—')
    expect(wrapper.get('[data-testid="rekap-jumlah-galat"]').text()).toContain('1 pegawai')
    expect(wrapper.find('[data-testid="rekap-galat-199001012020011001"]').exists()).toBe(false)
  })

  it('role 3: tanpa pemilih Unit/Satker dan tanpa unit_satker (dipaksa server), tanpa tab Instansi Lain', async () => {
    server.balasan['get /presensi/tukin/rekap'] = sukses(rekap(ITEMS))
    const { wrapper } = await mountRekap(Role.ADMIN_SATKER)
    expect(minta('/presensi/tukin/rekap')[0]!.params).not.toHaveProperty('unit_satker')
    expect(wrapper.get('[data-testid="filter-laporan"]').text()).not.toMatch(/Unit|Satker/)
    expect(wrapper.find('[data-testid="rekap-tab-il"]').exists()).toBe(false)
  })

  it('ekspor Excel memakai filter aktif dan menyimpan berkas dari server', async () => {
    server.balasan['get /presensi/tukin/rekap'] = sukses(rekap(ITEMS))
    server.balasan['get /presensi/tukin/rekap/xlsx'] = {
      status: 200, body: new Blob(['x']), headers: { 'content-disposition': 'attachment; filename="laporan_tukin_0_2026-10.xlsx"' },
    }
    const simpan = vi.spyOn(berkas, 'simpanBerkas').mockImplementation(() => undefined)
    const { wrapper } = await mountRekap()
    await bukaEkspor(wrapper)
    expect([...document.body.querySelectorAll('[data-format]')].map((n) => n.getAttribute('data-format'))).toEqual(['xlsx', 'pdf'])
    await pilihFormat('xlsx')

    expect(minta('/presensi/tukin/rekap/xlsx')[0]!.params).toMatchObject({ unit_satker: '0' })
    expect(minta('/presensi/tukin/rekap/xlsx')[0]!.params).not.toHaveProperty('page')
    expect(simpan).toHaveBeenCalledWith({ blob: expect.any(Blob), nama: 'laporan_tukin_0_2026-10.xlsx' })
    simpan.mockRestore()
  })

  it('ekspor PDF di atas batas / lingkup 0 → 422 unit_satker tampil sebagai pesan, tanpa menyimpan', async () => {
    server.balasan['get /presensi/tukin/rekap'] = sukses(rekap(ITEMS))
    server.balasan['get /presensi/tukin/rekap/pdf'] = galat(422, { unit_satker: ['Persempit lingkup atau unduh XLSX.'] })
    const simpan = vi.spyOn(berkas, 'simpanBerkas').mockImplementation(() => undefined)
    const { wrapper } = await mountRekap()
    await bukaEkspor(wrapper)
    await pilihFormat('pdf')

    expect(wrapper.get('[data-testid="rekap-ekspor-galat"]').text()).toContain('Persempit lingkup atau unduh XLSX.')
    expect(simpan).not.toHaveBeenCalled()
    simpan.mockRestore()
  })

  it('varian Instansi Lain: /rekap-il, tanpa filter unit/jenis, hanya ekspor Excel', async () => {
    server.balasan['get /presensi/tukin/rekap-il'] = sukses(rekap(ITEMS, { lingkup: null }))
    const { wrapper } = await mountRekap(Role.SUPER_ADMIN, true)

    expect(minta('/presensi/tukin/rekap-il')[0]!.params).toEqual({ bulan: expect.stringMatching(/^\d{4}-\d{2}$/), page: 1, per_page: 25 })
    expect(wrapper.get('h1').text()).toBe('Rekap Tukin Instansi Lain')
    expect(wrapper.find('[data-testid="filter-jenis-pegawai"]').exists()).toBe(false)
    await bukaEkspor(wrapper)
    expect([...document.body.querySelectorAll('[data-format]')].map((n) => n.getAttribute('data-format'))).toEqual(['xlsx'])
  })

  it('tab Pegawai/Instansi Lain untuk role 1 berpindah route', async () => {
    server.balasan['get /presensi/tukin/rekap'] = sukses(rekap(ITEMS))
    const { wrapper, router } = await mountRekap()
    await wrapper.get('[data-testid="rekap-tab-il"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('presensi-rekap-tukin-il')
  })

  it('paginasi server: halaman 2 meminta page=2 dan nomor urut melanjutkan', async () => {
    server.balasan['get /presensi/tukin/rekap'] = sukses(rekap(ITEMS, { total: '60' }))
    const { wrapper } = await mountRekap()
    const halaman2 = wrapper.findAll('button').find((b) => b.text().trim() === '2')
    expect(halaman2).toBeDefined()
    await halaman2!.trigger('click')
    await flushPromises()
    expect(minta('/presensi/tukin/rekap').at(-1)!.params).toMatchObject({ page: 2 })
    expect(wrapper.get('[data-testid="rekap-row-199001012020011001"]').findAll('td')[0]!.text()).toBe('26')
  })

  it('kosong dan galat muat (403) ditampilkan sebagai pesan', async () => {
    server.balasan['get /presensi/tukin/rekap'] = sukses(rekap([]))
    expect((await mountRekap()).wrapper.find('[data-testid="rekap-tukin-kosong"]').exists()).toBe(true)

    server.balasan['get /presensi/tukin/rekap'] = galat(403)
    expect((await mountRekap()).wrapper.get('[data-testid="rekap-tukin-galat"]').text()).toContain('tidak berhak')
  })
})
