/**
 * ArsipFolderPage Tahap 2 (MAKE-096; AC2-01..AC2-13 sisi FE): filter "Semua berkas" (UiSelect jenis + UiCheckbox "Belum
 * terhubung": centang me-reset select, gabungan berlaku bersama), ekspor Excel/CSV mengikuti filter, aksi baris (Edit,
 * Hubungkan, Hapus + ConfirmDialog) lewat menu ⋮ dengan muat ulang daftar + folder sesudah mutasi (AC2-12), role 4/5 hanya
 * lihat, pesan galat server di halaman.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/arsip.service', () => ({
  arsipService: { folder: vi.fn(), berkas: vi.fn(), ubahNama: vi.fn(), hubungkan: vi.fn(), hapus: vi.fn(), opsiHubungkan: vi.fn(), ekspor: vi.fn() },
}))
vi.mock('../services/berkasUnduhan', () => ({ ambilBerkas: vi.fn(), simpanBerkas: vi.fn() }))

import { Role, type RoleCode } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { ambilFolderArsip, kosongkanFolderArsip, simpanFolderArsip } from '../arsip.cache'
import type { ArsipBerkas, ArsipBerkasResponse, ArsipFolder, ArsipFolderResponse } from '../arsip.types'
import { arsipService } from '../services/arsip.service'
import { simpanBerkas } from '../services/berkasUnduhan'
import ArsipFolderPage from '../views/ArsipFolderPage.vue'

import { NIP } from './fixtures'

const folderApi = vi.mocked(arsipService.folder)
const berkasApi = vi.mocked(arsipService.berkas)
const hapusApi = vi.mocked(arsipService.hapus)
const ubahNamaApi = vi.mocked(arsipService.ubahNama)
const hubungkanApi = vi.mocked(arsipService.hubungkan)
const eksporApi = vi.mocked(arsipService.ekspor)
const simpan = vi.mocked(simpanBerkas)

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

function folder(id: number | null, label: string, urutan: number | null, jumlah: number): ArsipFolder {
  return { id_jenis_rwy: id, kode: id === null ? 'tanpa_jenis' : `jenis_${id}`, label, urutan, jumlah, jumlah_hilang: 0, jumlah_tak_dapat_dibuka: 0 }
}

function ringkas(jenisHubungkan: number[] = [38]): ArsipFolderResponse {
  return {
    pegawai: { nip: NIP, nama: 'Budi Contoh', glr_depan: null, glr_belakang: null },
    folder: [folder(9, 'Jabatan', 6, 12), folder(0, 'Belum Terhubung', 0, 3)],
    total: 15,
    total_hilang: 0,
    total_tak_dapat_dibuka: 0,
    penyajian_berkas_legacy: 'publik',
    aksi: { tulis: true, jenis_hubungkan: jenisHubungkan.map((k) => ({ id_jenis_rwy: k, label: k === 38 ? 'Data Umum' : `Jenis ${k}` })) },
  }
}

function berkas(id: number, idRiwayat = 0): ArsipBerkas {
  return {
    id_attachment: id,
    nama_arsip: `Arsip ${id}`,
    tag: `Arsip ${id}`,
    display_name: null,
    id_riwayat: idRiwayat,
    jenis_riwayat: idRiwayat === 0 ? 'Belum Terhubung' : 'Jabatan',
    id_entri: null,
    ringkasan_riwayat: '—',
    file_type: 'application/pdf',
    created_at: '2026-10-08 03:15:00',
    berkas_ada: true,
    status_berkas: 'ada',
    akses_berkas: 'privat',
    url_berkas: `pegawai/${NIP}/arsip/berkas/${id}/buka`,
    jenis_buka: 'pratinjau',
    aksi: { edit: true, hubungkan: idRiwayat === 0, hapus: idRiwayat === 0 },
  }
}

function hasil(items: ArsipBerkas[], total = items.length, page = 1): ArsipBerkasResponse {
  return { items, total, page, per_page: 25 }
}

function galat(status: number, message: string, errors: Record<string, string[]> | null = null) {
  return { status, message, errors, isNetworkError: false, original: { response: { status, data: { message, errors } } } }
}

async function pasang(path: string, role: RoleCode = Role.SUPER_ADMIN) {
  loginAs(role)
  const router = createShellRouter()
  await router.push(path)
  await router.isReady()
  const wrapper = mount(ArsipFolderPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

async function pilihJenis(wrapper: ReturnType<typeof mount>, nilai: string): Promise<void> {
  const select = wrapper.get('[data-testid="arsip-filter-jenis"] select')
  await select.setValue(nilai)
  await flushPromises()
}

async function centang(wrapper: ReturnType<typeof mount>, nilai: boolean): Promise<void> {
  await wrapper.get('[data-testid="arsip-filter-belum-terhubung"] input[type="checkbox"]').setValue(nilai)
  await flushPromises()
}

afterEach(() => {
  document.body.innerHTML = ''
  vi.useRealTimers()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  kosongkanFolderArsip()
  for (const fn of [folderApi, berkasApi, hapusApi, ubahNamaApi, hubungkanApi, eksporApi, simpan]) fn.mockReset()
  folderApi.mockResolvedValue(ringkas())
  berkasApi.mockResolvedValue(hasil([berkas(1), berkas(2, 9)]))
})

describe('filter "Semua berkas"', () => {
  it('select jenis (dari folder) dan centang "Belum terhubung" hanya di "Semua berkas"', async () => {
    const semua = await pasang(`/arsip/${NIP}/semua`)
    expect(semua.find('[data-testid="arsip-filter-jenis"]').exists()).toBe(true)
    expect(semua.find('[data-testid="arsip-filter-belum-terhubung"]').exists()).toBe(true)
    const opsi = [...semua.findAll('[data-testid="arsip-filter-jenis"] option')].map((o) => o.text())
    expect(opsi).toEqual(expect.arrayContaining(['Jabatan', 'Belum Terhubung']))
    semua.unmount()
    const folder9 = await pasang(`/arsip/${NIP}/9`)
    expect(folder9.find('[data-testid="arsip-filter-jenis"]').exists()).toBe(false)
    expect(folder9.find('[data-testid="arsip-filter-belum-terhubung"]').exists()).toBe(false)
  })

  it('memilih jenis mengirim id_jenis_rwy dan kembali ke halaman 1', async () => {
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    berkasApi.mockClear()
    await pilihJenis(wrapper, '9')
    expect(berkasApi).toHaveBeenLastCalledWith(NIP, { id_jenis_rwy: 9, q: '', page: 1, per_page: 25 })
  })

  it('mencentang "Belum terhubung" me-reset select ke "Semua" dan mengirim belum_terhubung', async () => {
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    await pilihJenis(wrapper, '9')
    berkasApi.mockClear()
    await centang(wrapper, true)
    expect(wrapper.get<HTMLSelectElement>('[data-testid="arsip-filter-jenis"] select').element.value).toBe('')
    expect(berkasApi).toHaveBeenLastCalledWith(NIP, { belum_terhubung: true, q: '', page: 1, per_page: 25 })
  })

  it('jenis dipilih sesudah mencentang → keduanya berlaku bersama (AND), hasil kosong tampil keterangan pencarian', async () => {
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    await centang(wrapper, true)
    berkasApi.mockResolvedValue(hasil([]))
    berkasApi.mockClear()
    await pilihJenis(wrapper, '9')
    expect(berkasApi).toHaveBeenLastCalledWith(NIP, { id_jenis_rwy: 9, belum_terhubung: true, q: '', page: 1, per_page: 25 })
    expect(wrapper.get('[data-testid="arsip-berkas-kosong"]').text()).toBe('Tidak ada berkas yang cocok dengan pencarian.')
  })

  it('mengosongkan centang melepas filter belum_terhubung', async () => {
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    await centang(wrapper, true)
    berkasApi.mockClear()
    await centang(wrapper, false)
    expect(berkasApi).toHaveBeenLastCalledWith(NIP, { q: '', page: 1, per_page: 25 })
  })
})

describe('ekspor', () => {
  async function ekspor(format: 'xlsx' | 'csv'): Promise<void> {
    q<HTMLElement>('[data-testid="export-trigger"]')?.click()
    await flushPromises()
    q<HTMLElement>(`[data-testid="export-menu"] [data-format="${format}"]`)?.click()
    await flushPromises()
  }

  async function ketikCari(wrapper: ReturnType<typeof mount>, teks: string): Promise<void> {
    const input = wrapper.get('[data-testid="arsip-cari"] input')
    await input.setValue(teks)
    await vi.advanceTimersByTimeAsync(400) // melewati debounce 300 ms
    await flushPromises()
  }

  it('mengikuti filter lengkap (centang → jenis → pencarian), sama dengan kueri daftar tanpa page/per_page, dan menyimpan berkas', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const berkasEkspor = { blob: new Blob(['x']), nama: 'arsip.xlsx' }
    eksporApi.mockResolvedValue(berkasEkspor)
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    await centang(wrapper, true)
    await pilihJenis(wrapper, '9')
    await ketikCari(wrapper, 'sk cpns')
    const terakhir = berkasApi.mock.calls.at(-1)?.[1]
    expect(terakhir).toEqual({ id_jenis_rwy: 9, belum_terhubung: true, q: 'sk cpns', page: 1, per_page: 25 })
    await ekspor('xlsx')
    expect(eksporApi).toHaveBeenCalledWith(NIP, 'xlsx', { id_jenis_rwy: 9, belum_terhubung: true, q: 'sk cpns' })
    expect(simpan).toHaveBeenCalledWith(berkasEkspor)
  })

  it('folder tanpa_jenis: ekspor memakai id_jenis_rwy "tanpa_jenis" (+ pencarian)', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    eksporApi.mockResolvedValue({ blob: new Blob(['x']), nama: 'arsip.csv' })
    const wrapper = await pasang(`/arsip/${NIP}/tanpa_jenis`)
    await ketikCari(wrapper, 'lama')
    await ekspor('csv')
    expect(eksporApi).toHaveBeenCalledWith(NIP, 'csv', { id_jenis_rwy: 'tanpa_jenis', q: 'lama' })
  })

  it('di folder tertentu memakai filter folder; CSV', async () => {
    eksporApi.mockResolvedValue({ blob: new Blob(['x']), nama: 'arsip.csv' })
    await pasang(`/arsip/${NIP}/9`)
    await ekspor('csv')
    expect(eksporApi).toHaveBeenCalledWith(NIP, 'csv', { id_jenis_rwy: 9 })
  })

  it.each([Role.ADMIN_VIEW_ESELON1, Role.MENTERI])('role %s (4/5) juga boleh mengekspor (semua role 1–7)', async (role) => {
    eksporApi.mockResolvedValue({ blob: new Blob(['x']), nama: 'arsip.xlsx' })
    const wrapper = await pasang(`/arsip/${NIP}/semua`, role)
    expect(wrapper.find('[data-testid="arsip-ekspor"]').exists()).toBe(true)
    await ekspor('xlsx')
    expect(eksporApi).toHaveBeenCalledTimes(1)
  })

  it('galat ekspor → pesan di halaman, tanpa menyimpan berkas', async () => {
    eksporApi.mockRejectedValue(galat(403, 'Ekspor ditolak server.'))
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    await ekspor('xlsx')
    expect(wrapper.get('[data-testid="arsip-pesan"]').text()).toContain('Ekspor ditolak server.')
    expect(simpan).not.toHaveBeenCalled()
  })
})

describe('aksi baris', () => {
  it('role 1: Edit/Buka/Hubungkan/Hapus pada arsip yatim', async () => {
    const admin = await pasang(`/arsip/${NIP}/semua`)
    expect((await rowMenuActions(admin, 'arsip-actions-1')).map((a) => a.key)).toEqual(['edit', 'buka', 'hubungkan', 'hapus'])
  })

  it.each([Role.ADMIN_VIEW_ESELON1, Role.MENTERI])('role %s (4/5) hanya Buka walau aksi.* dari server true', async (role) => {
    const lihat = await pasang(`/arsip/${NIP}/semua`, role)
    expect((await rowMenuActions(lihat, 'arsip-actions-1')).map((a) => a.key)).toEqual(['buka'])
  })

  it('Hapus: ConfirmDialog → DELETE → daftar + folder dimuat ulang, cache dikosongkan, pesan sukses (AC2-12)', async () => {
    hapusApi.mockResolvedValue(undefined)
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    simpanFolderArsip(NIP, ringkas())
    expect(folderApi).toHaveBeenCalledTimes(1)
    const dataBaru = { ...ringkas(), total: 14 }
    folderApi.mockResolvedValue(dataBaru)
    await selectRowAction(wrapper, 'arsip-actions-1', 'hapus')
    expect(q('[role="alertdialog"], [role="dialog"]')?.textContent).toContain('Berkas fisik tidak ikut dihapus')
    expect(hapusApi).not.toHaveBeenCalled()
    berkasApi.mockClear()
    ;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()
    expect(hapusApi).toHaveBeenCalledWith(NIP, 1)
    expect(berkasApi).toHaveBeenCalledTimes(1)
    expect(folderApi).toHaveBeenCalledTimes(2)
    expect(ambilFolderArsip(NIP)).toEqual(dataBaru) // diisi ulang dari API terbaru, bukan sisa cache lama
    expect(wrapper.get('[data-testid="arsip-pesan"]').text()).toContain('Arsip dihapus.')
  })

  it('Hapus ditolak 422 (bukan kode 0) → pesan legacy apa adanya; daftar dimuat ulang karena baris sudah berubah', async () => {
    const pesan = 'Data arsip ini telah terhubung ke data riwayat pegawai. Silahkan hubungkan data arsip lain yang belum terhubung ke data riwayat.'
    hapusApi.mockRejectedValue(galat(422, 'Periksa kembali isian yang ditandai.', { id_attachment: [pesan] }))
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    await selectRowAction(wrapper, 'arsip-actions-1', 'hapus')
    berkasApi.mockClear()
    ;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()
    expect(wrapper.get('[data-testid="arsip-pesan"]').text()).toContain(pesan)
    expect(berkasApi).toHaveBeenCalledTimes(1)
  })

  it('Hapus baris terakhir di halaman > 1 → mundur ke halaman sebelumnya', async () => {
    hapusApi.mockResolvedValue(undefined)
    berkasApi.mockResolvedValue(hasil([berkas(1)], 26, 1))
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    const halaman2 = wrapper.findAll('button').find((b) => b.text() === '2')
    berkasApi.mockResolvedValue(hasil([berkas(30)], 26, 2))
    await halaman2?.trigger('click')
    await flushPromises()
    expect(berkasApi).toHaveBeenLastCalledWith(NIP, { q: '', page: 2, per_page: 25 })
    await selectRowAction(wrapper, 'arsip-actions-30', 'hapus')
    berkasApi.mockReset()
    berkasApi.mockImplementation((_nip, kueri) => Promise.resolve(kueri?.page === 2 ? hasil([], 25, 2) : hasil([berkas(1)], 25, 1)))
    ;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()
    expect(berkasApi).toHaveBeenLastCalledWith(NIP, { q: '', page: 1, per_page: 25 })
    expect(wrapper.find('[data-testid="arsip-berkas-row-1"]').exists()).toBe(true)
  })

  it('Edit: dialog Ubah Nama → PATCH → muat ulang daftar + pesan sukses', async () => {
    ubahNamaApi.mockResolvedValue(undefined)
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    await selectRowAction(wrapper, 'arsip-actions-2', 'edit')
    const input = q<HTMLInputElement>('[data-testid="arsip-edit-nama"] input')
    expect(input?.value).toBe('Arsip 2')
    if (input) {
      input.value = 'Nama Baru'
      input.dispatchEvent(new Event('input'))
    }
    await flushPromises()
    berkasApi.mockClear()
    q<HTMLElement>('[data-testid="arsip-edit-simpan"]')?.click()
    await flushPromises()
    expect(ubahNamaApi).toHaveBeenCalledWith(NIP, 2, 'Nama Baru')
    expect(berkasApi).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-testid="arsip-pesan"]').text()).toContain('Nama arsip diubah.')
  })

  it('Hubungkan: dialog menawarkan aksi.jenis_hubungkan (role 2/6/7: hanya kode 38) → POST → muat ulang daftar dan folder', async () => {
    hubungkanApi.mockResolvedValue(undefined)
    const wrapper = await pasang(`/arsip/${NIP}/semua`, Role.PEGAWAI)
    await selectRowAction(wrapper, 'arsip-actions-1', 'hubungkan')
    const opsi = [...document.body.querySelectorAll('[data-testid="arsip-hubungkan-jenis"] option')].map((o) => o.textContent?.trim() ?? '').filter((t) => t)
    expect(opsi.filter((o) => o !== 'Pilih jenis riwayat')).toEqual(['Data Umum'])
    const select = q<HTMLSelectElement>('[data-testid="arsip-hubungkan-jenis"] select')
    if (select) {
      select.value = '38'
      select.dispatchEvent(new Event('change'))
    }
    await flushPromises()
    berkasApi.mockClear()
    folderApi.mockClear()
    q<HTMLElement>('[data-testid="arsip-hubungkan-simpan"]')?.click()
    await flushPromises()
    expect(hubungkanApi).toHaveBeenCalledWith(NIP, 1, { id_riwayat: 38 })
    expect(berkasApi).toHaveBeenCalledTimes(1)
    expect(folderApi).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-testid="arsip-pesan"]').text()).toContain('Arsip dihubungkan.')
  })

  it('baris dengan aksi.hubungkan = false (sudah terhubung) → item Hubungkan hidden', async () => {
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    expect((await rowMenuActions(wrapper, 'arsip-actions-2')).map((a) => a.key)).toEqual(['edit', 'buka'])
  })

  it('selama Hapus berjalan: Edit/Hubungkan/Hapus baris lain disabled, Buka tetap aktif', async () => {
    let selesai!: () => void
    hapusApi.mockReturnValue(new Promise<void>((resolve) => (selesai = resolve)))
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    await selectRowAction(wrapper, 'arsip-actions-1', 'hapus')
    ;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()
    const aksi = await rowMenuActions(wrapper, 'arsip-actions-2')
    expect(aksi.find((a) => a.key === 'edit')?.disabled).toBe(true)
    expect(aksi.find((a) => a.key === 'buka')?.disabled).toBe(false)
    selesai()
    await flushPromises()
  })
})
