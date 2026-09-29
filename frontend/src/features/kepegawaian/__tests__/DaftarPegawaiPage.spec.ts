/**
 * DaftarPegawaiPage (§4.1.5, B-20) — memuat & memaginasi data, pencarian, filter panel (+ dependensi group→sub group),
 * filter per kolom, popup "Filter Kolom", aksi baris ⋮ per role (AGENTS.md §1), tombol yang dibatasi role 1, serta
 * pesan "belum tersambung backend". Debounce 250 ms ditunggu dengan timer sungguhan.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { Role, type RoleCode } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { PEGAWAI_ROWS } from '../services/pegawai.mock'
import { filterPegawai, pegawaiService } from '../services/pegawai.service'
import DaftarPegawaiPage from '../views/DaftarPegawaiPage.vue'

const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))
/** Lewati debounce 250 ms lalu biarkan promise selesai. */
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
const info = (w: ReturnType<typeof mount>) => w.get('nav[aria-label="Paginasi"]').element.parentElement?.querySelector('p')?.textContent?.trim()
const panelSelects = (w: ReturnType<typeof mount>) => w.get('[data-testid="filter-panel"]').findAll('select')

afterEach(() => {
  document.body.innerHTML = ''
  vi.restoreAllMocks()
})
enableAutoUnmount(afterEach)

describe('DaftarPegawaiPage — data', () => {
  it('memuat 10 baris pertama dengan info paginasi', async () => {
    const { wrapper } = await mountList()
    expect(rows(wrapper)).toHaveLength(10)
    expect(info(wrapper)).toBe('Showing 1 to 10 of 57 entries')
  })

  it('header kolom bawaan + kolom Aksi paling kanan (AGENTS.md §1)', async () => {
    const { wrapper } = await mountList()
    const headers = wrapper.findAll('thead tr:first-child th').map((th) => th.text())
    expect(headers).toEqual(['Nama/NIP', 'KP (Gol)', 'Jabatan', 'Satuan Kerja', 'Unit', 'Aksi'])
  })

  it('nama pegawai menaut ke halaman detail', async () => {
    const { wrapper } = await mountList()
    const first = PEGAWAI_ROWS[0]
    expect(wrapper.get(`[data-testid="pegawai-row-${first.nip}"] a`).attributes('href')).toBe(`/pegawai/${first.nip}`)
  })

  it('paginasi: halaman 2 menampilkan 11–20', async () => {
    const { wrapper } = await mountList()
    const two = wrapper.findAll('nav[aria-label="Paginasi"] button').find((b) => b.text() === '2')
    await two?.trigger('click')
    await settle()
    expect(info(wrapper)).toBe('Showing 11 to 20 of 57 entries')
    expect(rows(wrapper)[0].attributes('data-testid')).toBe(`pegawai-row-${PEGAWAI_ROWS[10].nip}`)
  })

  it('jumlah baris 25 mengubah ukuran halaman dan kembali ke halaman 1', async () => {
    const { wrapper } = await mountList()
    await wrapper.get('select[aria-label="Jumlah baris"]').setValue('25')
    await settle()
    expect(rows(wrapper)).toHaveLength(25)
    expect(info(wrapper)).toBe('Showing 1 to 25 of 57 entries')
  })

  it('kegagalan memuat menampilkan pesan error, bukan tabel kosong yang menyesatkan', async () => {
    vi.spyOn(pegawaiService, 'list').mockRejectedValueOnce(new Error('jaringan'))
    const { wrapper } = await mountList()
    expect(wrapper.text()).toContain('Data pegawai gagal dimuat')
  })
})

describe('DaftarPegawaiPage — pencarian & filter', () => {
  it('pencarian tanpa hasil → keadaan kosong; dikosongkan → data kembali', async () => {
    const { wrapper } = await mountList()
    const search = wrapper.get('input[placeholder="Search"]')

    await search.setValue('zzzz-tidak-ada')
    await settle()
    expect(rows(wrapper)).toHaveLength(0)
    expect(wrapper.get('[data-testid="pegawai-empty"]').text()).toContain('Tidak ada pegawai yang cocok')

    await search.setValue('')
    await settle()
    expect(rows(wrapper)).toHaveLength(10)
  })

  it('pencarian nama mempersempit hasil sesuai layanan', async () => {
    const { wrapper } = await mountList()
    const target = PEGAWAI_ROWS[7]
    await wrapper.get('input[placeholder="Search"]').setValue(target.nip)
    await settle()
    expect(rows(wrapper).map((r) => r.attributes('data-testid'))).toEqual([`pegawai-row-${target.nip}`])
  })

  it('Jenis Pegawai = PPPK hanya menampilkan PPPK dan menghitung total yang benar', async () => {
    const { wrapper } = await mountList()
    await panelSelects(wrapper)[3].setValue('PPPK')
    await settle()
    const expected = filterPegawai({ page: 1, per_page: 100, jenis_pegawai: 'PPPK' }).total
    expect(info(wrapper)).toBe(`Showing 1 to ${Math.min(10, expected)} of ${expected} entries`)
    expect(wrapper.findAll('tbody tr').length).toBeLessThanOrEqual(10)
  })

  it('Sub Group Jabatan nonaktif sampai Group dipilih, lalu dikosongkan saat Group berganti', async () => {
    const { wrapper } = await mountList()
    const [, , , , group, sub] = panelSelects(wrapper)
    expect(sub.attributes('disabled')).toBeDefined()

    await group.setValue('Jabatan Fungsional')
    expect(sub.attributes('disabled')).toBeUndefined()
    expect(sub.findAll('option').map((o) => o.text())).toContain('Ahli Muda')

    await sub.setValue('Ahli Muda')
    expect((sub.element as HTMLSelectElement).value).toBe('Ahli Muda')
    await group.setValue('Jabatan Struktural')
    expect((sub.element as HTMLSelectElement).value).toBe('')
  })

  it('tombol corong menyembunyikan/menampilkan panel filter (Gambar 19) dengan aria-pressed', async () => {
    const { wrapper } = await mountList()
    const toggle = wrapper.get('[data-testid="toggle-filter"]')
    expect(toggle.attributes('aria-pressed')).toBe('true')
    expect(wrapper.find('[data-testid="filter-panel"]').exists()).toBe(true)

    await toggle.trigger('click')
    expect(toggle.attributes('aria-pressed')).toBe('false')
    expect(wrapper.find('[data-testid="filter-panel"]').exists()).toBe(false)
  })

  it('filter per kolom (Jabatan) menyaring baris', async () => {
    const { wrapper } = await mountList()
    await wrapper.get('[data-testid="filter-jabatan"]').setValue('Arsiparis')
    await settle()
    expect(rows(wrapper).length).toBeGreaterThan(0)
    for (const row of rows(wrapper)) expect(row.text()).toContain('Arsiparis')
  })
})

describe('DaftarPegawaiPage — popup Filter Kolom', () => {
  const picker = () => document.body.querySelector<HTMLElement>('[data-testid="column-picker"]')

  function checkbox(label: string): HTMLInputElement | undefined {
    const el = [...(picker()?.querySelectorAll('label') ?? [])].find((l) => l.textContent?.trim() === label)
    return el?.querySelector('input') ?? undefined
  }

  async function open(wrapper: ReturnType<typeof mount>): Promise<void> {
    await wrapper.get('[data-testid="column-picker-trigger"]').trigger('click')
    await flushPromises()
  }

  it('membuka popup berisi "Toggle All Column" dan daftar kolom; kolom bawaan sudah tercentang', async () => {
    const { wrapper } = await mountList()
    await open(wrapper)

    expect(picker()?.textContent).toContain('Toggle All Column')
    expect(picker()?.textContent).toContain('Daftar kolom')
    expect(checkbox('Jabatan')?.checked).toBe(true)
    expect(checkbox('NIP Lama')?.checked).toBe(false)
    expect(checkbox('Nama/NIP')).toBeUndefined() // terkunci, tidak ada di popup
  })

  it('memilih NIP Lama lalu Apply Column menambah kolom tepat setelah Nama/NIP', async () => {
    const { wrapper } = await mountList()
    await open(wrapper)

    const input = checkbox('NIP Lama')
    if (!input) throw new Error('checkbox NIP Lama tidak ada')
    input.checked = true
    input.dispatchEvent(new Event('change'))
    await flushPromises()
    document.body.querySelector<HTMLElement>('[data-testid="column-picker-apply"]')?.click()
    await flushPromises()

    expect(wrapper.findAll('thead tr:first-child th').map((th) => th.text())).toEqual([
      'Nama/NIP', 'NIP Lama', 'KP (Gol)', 'Jabatan', 'Satuan Kerja', 'Unit', 'Aksi',
    ])
  })

  it('pilihan yang tidak di-Apply tidak mengubah tabel (ditutup dengan ×)', async () => {
    const { wrapper } = await mountList()
    await open(wrapper)
    const input = checkbox('Email')
    if (!input) throw new Error('checkbox Email tidak ada')
    input.checked = true
    input.dispatchEvent(new Event('change'))
    await flushPromises()

    document.body.querySelector<HTMLElement>('button[aria-label="Tutup"]')?.click()
    await flushPromises()
    expect(wrapper.findAll('thead tr:first-child th').map((th) => th.text())).not.toContain('Email')
  })

  it('pencarian kolom: judul berganti "Hasil pencarian ditemukan"; tanpa hasil → "Tidak ada hasil ditemukan..."', async () => {
    const { wrapper } = await mountList()
    await open(wrapper)
    const search = document.body.querySelector<HTMLInputElement>('[data-testid="column-picker-search"]')
    if (!search) throw new Error('kotak cari kolom tidak ada')

    search.value = 'nip'
    search.dispatchEvent(new Event('input'))
    await flushPromises()
    expect(picker()?.textContent).toContain('Hasil pencarian ditemukan')
    expect(picker()?.textContent).toContain('Toggle All Result')
    expect(checkbox('NIP Lama')).toBeDefined()
    expect(checkbox('Agama')).toBeUndefined()

    search.value = 'rumah'
    search.dispatchEvent(new Event('input'))
    await flushPromises()
    expect(document.body.querySelector('[data-testid="column-picker-empty"]')?.textContent).toContain('Tidak ada hasil ditemukan...')
  })
})

describe('DaftarPegawaiPage — aksi & role', () => {
  const first = PEGAWAI_ROWS[0]
  const testid = `pegawai-actions-${first.nip}`

  it('Super Admin: menu ⋮ berisi "Lihat detail" lalu "Hapus" (merah, paling bawah)', async () => {
    const { wrapper } = await mountList(Role.SUPER_ADMIN)
    const actions = await rowMenuActions(wrapper, testid)
    expect(actions.map((a) => a.label)).toEqual(['Lihat detail', 'Hapus'])
    expect(actions[1].danger).toBe(true)
  })

  it('role lain (Admin Satker) tidak punya "Hapus"; label aksesibel memuat nama pegawai', async () => {
    const { wrapper } = await mountList(Role.ADMIN_SATKER)
    expect((await rowMenuActions(wrapper, testid)).map((a) => a.label)).toEqual(['Lihat detail'])
    expect(wrapper.get(`[data-testid="${testid}"]`).attributes('aria-label')).toBe(`Aksi untuk ${first.nama}`)
  })

  it('"Lihat detail" membuka /pegawai/:nip', async () => {
    const { wrapper, router } = await mountList()
    await selectRowAction(wrapper, testid, 'detail')
    expect(router.currentRoute.value.fullPath).toBe(`/pegawai/${first.nip}`)
  })

  it('"Hapus" meminta konfirmasi lalu memberi tahu bahwa backend (B-05) belum tersambung', async () => {
    const { wrapper } = await mountList()
    await selectRowAction(wrapper, testid, 'hapus')

    const dialog = document.body.querySelector('[role="alertdialog"]')
    expect(dialog?.textContent).toContain('Hapus pegawai?')
    expect(dialog?.textContent).toContain(first.nip)

    const confirm = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')
    confirm?.click()
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toContain('B-05')
    // Data tidak benar-benar berubah.
    expect(info(wrapper)).toBe('Showing 1 to 10 of 57 entries')
  })

  it('"+ Data Pegawai" hanya untuk Super Admin dan memberi pesan belum tersedia', async () => {
    const admin = await mountList(Role.SUPER_ADMIN)
    await admin.wrapper.get('[data-testid="add-pegawai"]').trigger('click')
    expect(admin.wrapper.get('[role="status"]').text()).toContain('B-05')
    admin.wrapper.unmount()

    const satker = await mountList(Role.ADMIN_SATKER)
    expect(satker.wrapper.find('[data-testid="add-pegawai"]').exists()).toBe(false)
  })

  it('Export → "Export PDF" memberi pesan belum tersambung (B-20)', async () => {
    const { wrapper } = await mountList()
    await wrapper.get('[data-testid="export-trigger"]').trigger('click')
    await flushPromises()
    document.body.querySelector<HTMLElement>('[data-format="pdf"]')?.click()
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toContain('B-20')
  })

  it('pesan dapat ditutup', async () => {
    const { wrapper } = await mountList()
    await wrapper.get('[data-testid="add-pegawai"]').trigger('click')
    await wrapper.get('button[aria-label="Tutup pesan"]').trigger('click')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })
})
