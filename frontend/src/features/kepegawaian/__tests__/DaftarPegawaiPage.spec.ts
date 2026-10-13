/**
 * DaftarPegawaiPage (B-20) — data dari GET pegawai (page/per_page/search), kolom = kolom DDL `pegawai`, keadaan
 * galat 404/501 tanpa data contoh, popup "Filter Kolom", aksi baris ⋮ per role (AGENTS.md §1), tombol role 1.
 * Debounce 250 ms ditunggu dengan timer sungguhan.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/pegawai.service', () => ({ pegawaiService: { list: vi.fn(), detail: vi.fn(), hapus: vi.fn(), tambah: vi.fn() } }))
vi.mock('@/features/master-data/services/master.service', () => ({ masterService: { options: vi.fn().mockResolvedValue([]) } }))

import { Role, type RoleCode } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { masterService } from '@/features/master-data/services/master.service'

import { pegawaiService } from '../services/pegawai.service'
import DaftarPegawaiPage from '../views/DaftarPegawaiPage.vue'

import { apiError, listItem } from './fixtures'

const list = vi.mocked(pegawaiService.list)
const hapus = vi.mocked(pegawaiService.hapus)
const ITEMS = Array.from({ length: 10 }, (_, i) => listItem(i + 1))

const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))
async function settle(): Promise<void> {
  await wait(300)
  await flushPromises()
}

async function mountList(role: RoleCode = Role.SUPER_ADMIN) {
  loginAs(role)
  const router = createShellRouter()
  await router.push('/pegawai')
  await router.isReady()
  const wrapper = mount(DaftarPegawaiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

const rows = (w: ReturnType<typeof mount>) => w.findAll('[data-testid^="pegawai-row-"]')
const headers = (w: ReturnType<typeof mount>) => w.findAll('thead tr:first-child th').map((th) => th.text())

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  list.mockReset()
  list.mockResolvedValue({ items: ITEMS, total: 57, page: 1, per_page: 10 })
})

describe('DaftarPegawaiPage — data', () => {
  it('GET pegawai halaman 1 / 10 baris; header kolom bawaan + Aksi paling kanan', async () => {
    const { wrapper } = await mountList()
    expect(list).toHaveBeenCalledWith({ page: 1, per_page: 10, search: undefined, status: '1', id_unit: '', id_satker: '', id_jenis_pegawai: '', sort: 'nama', order: 'asc' })
    expect(rows(wrapper)).toHaveLength(10)
    expect(headers(wrapper)).toEqual(['Nama/NIP', 'Jabatan', 'Satuan Kerja', 'KP (Gol)', 'Jenis Pegawai', 'Aksi'])
  })

  it('nama lengkap (glr_akhir) menaut ke halaman detail', async () => {
    const { wrapper } = await mountList()
    const second = ITEMS[1]
    const link = wrapper.get(`[data-testid="pegawai-row-${second.nip}"] a`)
    expect(link.text()).toBe('Pegawai 2, S.Kom.')
    expect(link.attributes('href')).toBe(`/pegawai/${second.nip}`)
  })

  it('pencarian & jumlah baris dikirim ke server dan kembali ke halaman 1', async () => {
    const { wrapper } = await mountList()
    await wrapper.get('input[type="search"]').setValue('budi')
    await settle()
    expect(list).toHaveBeenLastCalledWith({ page: 1, per_page: 10, search: 'budi', status: '1', id_unit: '', id_satker: '', id_jenis_pegawai: '', sort: 'nama', order: 'asc' })
  })

  it('panel filter (MAKE-014): status, unit → satker bertingkat, jenis, urutan dikirim ke server; halaman kembali 1', async () => {
    const options = vi.mocked(masterService.options)
    options.mockImplementation(async (entity: string, parent?: string | null) => {
      if (entity === 'unit') return [{ id: '5', nama: 'Unit A', parent: null }]
      if (entity === 'satker') return parent === '5' ? [{ id: '50', nama: 'Satker A1', parent: '5' }] : []
      return [{ id: '1', nama: 'PNS', parent: null }]
    })
    const { wrapper } = await mountList()
    const pilih = async (testid: string, nilai: string) => {
      await wrapper.get(`[data-testid="${testid}"] select`).setValue(nilai)
      await flushPromises()
    }
    expect(wrapper.get('[data-testid="pegawai-filter-satker"] select').attributes('disabled')).toBeDefined()

    await pilih('pegawai-filter-status', '2')
    await pilih('pegawai-filter-unit', '5')
    expect(options).toHaveBeenCalledWith('satker', '5')
    await pilih('pegawai-filter-satker', '50')
    await pilih('pegawai-filter-jenis', '1')
    await pilih('pegawai-filter-sort', 'gol_ruang')
    await pilih('pegawai-filter-order', 'desc')
    await settle()

    expect(list).toHaveBeenLastCalledWith({ page: 1, per_page: 10, search: undefined, status: '2', id_unit: '5', id_satker: '50', id_jenis_pegawai: '1', sort: 'gol_ruang', order: 'desc' })

    // Ganti unit → satker yang tidak cocok dikosongkan.
    await pilih('pegawai-filter-unit', '')
    await settle()
    expect(list.mock.lastCall?.[0]).toMatchObject({ id_unit: '', id_satker: '' })
    options.mockReset()
    options.mockResolvedValue([])
  })

  it('role 8 (Pimpinan): panel filter disembunyikan, pencarian tetap', async () => {
    const { wrapper } = await mountList(Role.PIMPINAN)
    expect(wrapper.find('[data-testid="pegawai-filter"]').exists()).toBe(false)
    expect(list).toHaveBeenCalledWith({ page: 1, per_page: 10, search: undefined })
  })

  it.each([
    [501, 'Fitur ini belum tersedia di server.'],
    [404, 'Data tidak ditemukan.'],
  ])('galat %i → pesan jelas tanpa tabel/keadaan kosong dan tanpa data contoh', async (status, message) => {
    list.mockRejectedValue(apiError(status))
    const { wrapper } = await mountList()
    expect(wrapper.get('[data-testid="pegawai-failure"]').text()).toBe(message)
    // Saat galat: tabel, keadaan kosong "Tidak ada pegawai yang cocok", dan paginasi disembunyikan.
    expect(wrapper.find('[data-testid="pegawai-table"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="pegawai-empty"]').exists()).toBe(false)
    expect(wrapper.find('nav[aria-label="Paginasi"]').exists()).toBe(false)
  })
})

describe('DaftarPegawaiPage — popup Filter Kolom', () => {
  const picker = () => document.body.querySelector<HTMLElement>('[data-testid="column-picker"]')
  const checkbox = (label: string) =>
    [...(picker()?.querySelectorAll('label') ?? [])].find((l) => l.textContent?.trim() === label)?.querySelector('input') ?? undefined

  it('memilih Agama lalu Apply Column menambah kolom; Nama/NIP terkunci', async () => {
    const { wrapper } = await mountList()
    await wrapper.get('[data-testid="column-picker-trigger"]').trigger('click')
    await flushPromises()
    expect(checkbox('Nama/NIP')).toBeUndefined()
    expect(checkbox('Jenis Pegawai')?.checked).toBe(true)

    const input = checkbox('Agama')
    if (!input) throw new Error('checkbox Agama tidak ada')
    input.checked = true
    input.dispatchEvent(new Event('change'))
    await flushPromises()
    document.body.querySelector<HTMLElement>('[data-testid="column-picker-apply"]')?.click()
    await flushPromises()
    expect(headers(wrapper)).toContain('Agama')
    expect(wrapper.get(`[data-testid="pegawai-row-${ITEMS[0].nip}"]`).text()).toContain('Islam')
  })
})

describe('DaftarPegawaiPage — aksi & role', () => {
  const first = ITEMS[0]

  it('Super Admin: ⋮ "Lihat detail" lalu "Hapus" (merah, paling bawah)', async () => {
    const { wrapper } = await mountList()
    const items = await rowMenuActions(wrapper, `pegawai-actions-${first.nip}`)
    expect(items.map((i) => i.label)).toEqual(['Lihat detail', 'Hapus'])
    expect(items[1].danger).toBe(true)
  })

  it('Admin Satker tanpa "Hapus"; label aksesibel memuat nama pegawai', async () => {
    const { wrapper } = await mountList(Role.ADMIN_SATKER)
    expect((await rowMenuActions(wrapper, `pegawai-actions-${first.nip}`)).map((i) => i.key)).toEqual(['detail'])
    expect(wrapper.get(`[data-testid="pegawai-actions-${first.nip}"]`).attributes('aria-label')).toBe(`Aksi untuk ${first.nama}`)
  })

  it('"Hapus" → ConfirmDialog → DELETE pegawai/{nip} → pemberitahuan + muat ulang (B-05)', async () => {
    hapus.mockResolvedValue({ deleted: true, soft_delete: false, item: ITEMS[0] as never })
    const { wrapper } = await mountList()
    await selectRowAction(wrapper, `pegawai-actions-${first.nip}`, 'hapus')
    await flushPromises()
    const ya = [...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('Ya, hapus'))
    expect(ya).toBeDefined()
    ya?.click()
    await vi.waitFor(() => expect(hapus).toHaveBeenCalledWith(first.nip))
    await vi.waitFor(() => expect(wrapper.text()).toContain('dihapus permanen'))
    await vi.waitFor(() => expect(list).toHaveBeenCalledTimes(2))
  })

  it('"Hapus" ditolak 422 (masih punya data terkait) → pesan server ditampilkan', async () => {
    hapus.mockRejectedValue(apiError(422, { nip: ['Pegawai tidak dapat dihapus karena masih memiliki data terkait (riwayat, jabatan, akun, dll.).'] }))
    const { wrapper } = await mountList()
    await selectRowAction(wrapper, `pegawai-actions-${first.nip}`, 'hapus')
    await flushPromises()
    ;[...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('Ya, hapus'))?.click()
    await vi.waitFor(() => expect(wrapper.text()).toContain('masih memiliki data terkait'))
  })

  it('"Lihat detail" membuka /pegawai/:nip', async () => {
    const { wrapper, router } = await mountList()
    await selectRowAction(wrapper, `pegawai-actions-${first.nip}`, 'detail')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe(`/pegawai/${first.nip}`)
  })

  it('"+ Data Pegawai" hanya Super Admin dan membuka dialog Tambah Pegawai (B-05)', async () => {
    const admin = await mountList()
    await admin.wrapper.get('[data-testid="add-pegawai"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-testid="tambah-pegawai-dialog"]')).not.toBeNull()
    expect(document.body.querySelector('[data-field="nip"]')).not.toBeNull()
    admin.wrapper.unmount()

    const { wrapper } = await mountList(Role.MENTERI)
    expect(wrapper.find('[data-testid="add-pegawai"]').exists()).toBe(false)
  })
})
