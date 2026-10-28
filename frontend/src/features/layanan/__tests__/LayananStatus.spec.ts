/**
 * Paket 8 M7 (MAKE-026) — Status Layanan: service dengan KLIEN ASLI (amplop backend nyata, string MySQLi, tautan internal
 * saja) dan halaman (filter Unit/Satker hanya role 1/3/4/5, Jenis/Status/pencarian/jumlah baris, stepper + PIC dari server,
 * ⋮ "Lihat detail" hanya bila server mengizinkan, navigasi ke `url_detail`, galat 403, keadaan kosong).
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, { status: number; body: unknown }>,
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
    const response: AxiosResponse = { status: balasan.status, statusText: 'x', headers: {}, config, data: balasan.body }
    if (balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})
vi.mock('@/features/master-data/services/master.service', () => ({ masterService: { options: vi.fn() } }))

import { Role } from '@/features/auth/types'
import { masterService } from '@/features/master-data/services/master.service'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { layananStatusService } from '../services/layananStatus.service'
import LayananStatusPage from '../views/LayananStatusPage.vue'

const options = vi.mocked(masterService.options)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const sukses = (data: unknown) => ({ status: 200, body: { status: 'success', data } })
const galat = (status: number) => ({ status, body: { status: 'error', message: 'x', errors: null } })

const item = (extra: Record<string, unknown> = {}) => ({
  jenis: 'tb',
  jenis_layanan: 'Tugas Belajar',
  id: '12',
  nip: '199001012015011001',
  nama: 'Contoh Pegawai, S.E.',
  unit: 'Unit Contoh',
  satker: 'Satker Contoh',
  tanggal_pengajuan: '2026-10-21 01:00:00',
  status: '0',
  ringkasan: 'Tugas Belajar S.1 - Universitas Contoh',
  label_status: 'Menunggu PIC',
  nada_status: 'warning',
  tahap: [
    { kode: 'diajukan', label: 'Diajukan', state: 'done', nama_petugas: null, waktu: '2026-10-21 01:00:00' },
    { kode: 'pic', label: 'Verifikasi PIC', state: 'current', nama_petugas: null, waktu: null },
    { kode: 'selesai', label: 'Selesai', state: 'todo', nama_petugas: null, waktu: null },
  ],
  petugas: [{ peran: 'PIC', nip: '198001012005011001', nama: 'Contoh PIC' }],
  catatan: null,
  url_detail: '/layanan/tb/12',
  aksi: { lihat_detail: '1' },
  ...extra,
})

const daftar = (items: unknown[], total = items.length) => sukses({ items, total: String(total), page: '1', per_page: '10', jenis: [] })

beforeEach(() => {
  server.balasan = {}
  server.permintaan = []
  options.mockReset()
  options.mockResolvedValue([{ id: 'U1', nama: 'Unit Satu', parent: null }])
})

describe('layananStatusService — amplop backend nyata lewat klien asli', () => {
  it('list() membuka amplop, menormalkan string, tahap + waktu, petugas, dan parameter', async () => {
    server.balasan['get /layanan/status'] = daftar([item()], 41)

    const hasil = await layananStatusService.list({ page: 2, per_page: 10, jenis: 'tb', status: 'berjalan', id_unit: 'U1', id_satker: '', search: 'contoh' })

    expect(hasil.total).toBe(41)
    expect(hasil.page).toBe(1)
    const row = hasil.items[0]!
    expect(row.id).toBe(12)
    expect(row.status).toBe(0)
    expect(row.jenis).toBe('tb')
    expect(row.nada_status).toBe('warning')
    expect(row.tahap).toHaveLength(3)
    expect(row.tahap[0]).toMatchObject({ kode: 'diajukan', state: 'done', waktu: '2026-10-21 01:00:00' })
    expect(row.petugas).toEqual([{ peran: 'PIC', nip: '198001012005011001', nama: 'Contoh PIC' }])
    expect(row.lihat_detail).toBe(true)
    expect(row.url_detail).toBe('/layanan/tb/12')
    expect(server.permintaan[0]?.params).toEqual({ page: 2, per_page: 10, jenis: 'tb', status: 'berjalan', id_unit: 'U1', search: 'contoh' })
  })

  it('tautan tidak aman (https://, //, tanpa /) dibuang; lihat_detail=false tanpa url', async () => {
    server.balasan['get /layanan/status'] = daftar([
      item({ id: '1', url_detail: 'https://jahat.example/x' }),
      item({ id: '2', url_detail: '//jahat.example/x' }),
      item({ id: '3', url_detail: 'layanan/tb/3' }),
      item({ id: '4', url_detail: null, aksi: { lihat_detail: '0' } }),
      item({ id: '5', url_detail: '/layanan/tb/5', aksi: { lihat_detail: '0' } }),
    ])

    const { items } = await layananStatusService.list({ page: 1, per_page: 10 })

    expect(items.map((r) => [r.url_detail, r.lihat_detail])).toEqual([
      [null, false],
      [null, false],
      [null, false],
      [null, false],
      ['/layanan/tb/5', false],
    ])
  })

  it('data kosong/rusak tidak melempar; jenis tak dikenal tidak merusak daftar', async () => {
    server.balasan['get /layanan/status'] = sukses({})
    expect((await layananStatusService.list({ page: 1, per_page: 10 })).items).toEqual([])

    server.balasan['get /layanan/status'] = daftar([{ jenis: 'xyz', id: '1' }])
    expect((await layananStatusService.list({ page: 1, per_page: 10 })).items[0]?.petugas).toEqual([])
  })

  it('422 diteruskan sebagai ApiError', async () => {
    server.balasan['get /layanan/status'] = { status: 422, body: { status: 'error', message: 'Validasi gagal.', errors: { status: ['Filter status kode memerlukan jenis layanan.'] } } }
    await expect(layananStatusService.list({ page: 1, per_page: 10, status: '1' })).rejects.toMatchObject({ status: 422 })
  })
})

describe('LayananStatusPage', () => {
  async function mountPage(role: number = Role.PEGAWAI) {
    loginAs(role as never)
    const router = createShellRouter()
    router.addRoute({ path: '/layanan/status', name: 'layanan-status', component: LayananStatusPage })
    router.addRoute({ path: '/layanan/tb/:id', name: 'tb-detail', component: { render: () => null } })
    await router.push('/layanan/status')
    await router.isReady()
    const wrapper = mount(LayananStatusPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    return { wrapper, router }
  }

  afterEach(() => {
    document.body.innerHTML = ''
  })
  enableAutoUnmount(afterEach)

  it('baris: avatar + NIP + satker, jenis + ringkasan, stepper dan badge dari server, PIC', async () => {
    server.balasan['get /layanan/status'] = daftar([item()])
    const { wrapper } = await mountPage()

    const baris = wrapper.get('[data-testid="layanan-row-tb-12"]')
    expect(baris.text()).toContain('Contoh Pegawai, S.E.')
    expect(baris.text()).toContain('199001012015011001')
    expect(baris.get('[data-testid="layanan-jenis"]').text()).toBe('Tugas Belajar')
    expect(baris.text()).toContain('Tugas Belajar S.1 - Universitas Contoh')
    expect(baris.text()).toContain('Verifikasi PIC')
    expect(baris.get('[data-testid="status-layanan-badge"]').text()).toBe('Menunggu PIC')
    expect(baris.get('[data-testid="layanan-petugas"]').text()).toContain('PIC: Contoh PIC')
  })

  it('tanpa PIC: catatan server tampil; ⋮ satu item Lihat detail dan membuka url_detail', async () => {
    server.balasan['get /layanan/status'] = daftar([item({ petugas: [], catatan: 'PIC belum ditunjuk' })])
    const { wrapper, router } = await mountPage()
    expect(wrapper.get('[data-testid="layanan-petugas"]').text()).toContain('PIC belum ditunjuk')

    expect((await rowMenuActions(wrapper, 'layanan-actions-tb-12')).map((a) => a.key)).toEqual(['detail'])
    await selectRowAction(wrapper, 'layanan-actions-tb-12', 'detail')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/layanan/tb/12')
  })

  it('tanpa hak detail (role 4/5): tidak ada menu ⋮', async () => {
    server.balasan['get /layanan/status'] = daftar([item({ url_detail: null, aksi: { lihat_detail: '0' } })])
    const { wrapper } = await mountPage(Role.ADMIN_VIEW_ESELON1)

    expect(wrapper.find('[data-testid="layanan-actions-tb-12"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="layanan-row-tb-12"]').text()).toContain('Contoh Pegawai')
  })

  it('filter Unit/Satker hanya untuk role 1, 3, 4, 5; role 2 tanpa filter itu dan tanpa panggilan opsi master', async () => {
    server.balasan['get /layanan/status'] = daftar([])
    const { wrapper } = await mountPage(Role.PEGAWAI)
    expect(wrapper.get('[data-testid="layanan-status-filter"]').text()).not.toContain('Unit')
    expect(options).not.toHaveBeenCalled()
    wrapper.unmount()

    await mountPage(Role.SUPER_ADMIN)
    expect(q('[data-testid="layanan-status-filter"]')?.textContent).toContain('Unit')
    expect(options).toHaveBeenCalledWith('unit', undefined)
  })

  it('filter Jenis dan Status mengirim parameter ke server dan kembali ke halaman 1', async () => {
    server.balasan['get /layanan/status'] = daftar([])
    const { wrapper } = await mountPage()
    const awal = server.permintaan.length

    const jenis = wrapper.get('[data-testid="layanan-status-filter"]').findAll('select')[0]!
    await jenis.setValue('pmk')
    await flushPromises()
    const status = wrapper.get('[data-testid="layanan-status-filter"]').findAll('select')[1]!
    await status.setValue('berjalan')
    await flushPromises()

    expect(server.permintaan.length).toBeGreaterThan(awal)
    expect(server.permintaan.at(-1)?.params).toMatchObject({ page: 1, per_page: 10, jenis: 'pmk', status: 'berjalan' })
  })

  it('403 → pesan baku Indonesia; daftar kosong → "Tidak ada data pada lingkup ini."', async () => {
    server.balasan['get /layanan/status'] = galat(403)
    const { wrapper } = await mountPage()
    expect(q('[data-testid="layanan-status-error"]')?.textContent).toContain('tidak berhak')
    wrapper.unmount()

    server.balasan['get /layanan/status'] = daftar([])
    await mountPage()
    expect(q('[data-testid="layanan-status-empty"]')?.textContent).toContain('Tidak ada data pada lingkup ini')
  })

  it('paginasi muncul bila total melebihi jumlah baris', async () => {
    server.balasan['get /layanan/status'] = daftar([item()], 35)
    const { wrapper } = await mountPage()
    expect(wrapper.text()).toContain('35')
    expect(wrapper.find('nav[aria-label], [role="navigation"]').exists()).toBe(true)
  })
})
