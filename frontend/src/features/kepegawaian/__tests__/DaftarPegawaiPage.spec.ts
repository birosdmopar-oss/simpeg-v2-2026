/**
 * DaftarPegawaiPage (B-20) — data dari GET pegawai (page/per_page/search), kolom = kolom DDL `pegawai`, keadaan
 * galat 404/501 tanpa data contoh, popup "Filter Kolom", aksi baris ⋮ per role (AGENTS.md §1), tombol role 1.
 * Debounce 250 ms ditunggu dengan timer sungguhan.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/pegawai.service', () => ({ pegawaiService: { list: vi.fn(), detail: vi.fn() } }))

import { Role, type RoleCode } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { pegawaiService } from '../services/pegawai.service'
import DaftarPegawaiPage from '../views/DaftarPegawaiPage.vue'

import { apiError, listItem } from './fixtures'

const list = vi.mocked(pegawaiService.list)
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
    expect(list).toHaveBeenCalledWith({ page: 1, per_page: 10, search: undefined })
    expect(rows(wrapper)).toHaveLength(10)
    expect(headers(wrapper)).toEqual(['Nama/NIP', 'Jenis Pegawai', 'Jenis Status', 'Aksi'])
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
    expect(list).toHaveBeenLastCalledWith({ page: 1, per_page: 10, search: 'budi' })
  })

  it.each([
    [501, 'Fitur ini belum tersedia di server.'],
    [404, 'Data tidak ditemukan.'],
  ])('galat %i → pesan jelas, tabel kosong tanpa data contoh', async (status, message) => {
    list.mockRejectedValue(apiError(status))
    const { wrapper } = await mountList()
    expect(wrapper.get('[data-testid="pegawai-failure"]').text()).toBe(message)
    expect(rows(wrapper)).toHaveLength(0)
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

  it('"Lihat detail" membuka /pegawai/:nip', async () => {
    const { wrapper, router } = await mountList()
    await selectRowAction(wrapper, `pegawai-actions-${first.nip}`, 'detail')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe(`/pegawai/${first.nip}`)
  })

  it('"+ Data Pegawai" hanya Super Admin dan memberi pesan belum tersedia', async () => {
    const admin = await mountList()
    await admin.wrapper.get('[data-testid="add-pegawai"]').trigger('click')
    expect(admin.wrapper.text()).toContain('Form tambah pegawai belum tersedia')
    admin.wrapper.unmount()

    const { wrapper } = await mountList(Role.MENTERI)
    expect(wrapper.find('[data-testid="add-pegawai"]').exists()).toBe(false)
  })
})
