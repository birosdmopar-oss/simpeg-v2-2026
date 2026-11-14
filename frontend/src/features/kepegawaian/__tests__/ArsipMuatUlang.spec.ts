/**
 * ArsipFolderPage — muat ulang sesudah mutasi (MAKE-096, perbaikan review): data folder lama dipertahankan saat GET folder
 * gagal (dengan "Coba lagi"), filter jenis yatim di-reset bila foldernya lenyap, mutasi gagal karena baris basi memuat ulang
 * daftar + folder tanpa pesan sukses.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/arsip.service', () => ({
  arsipService: { folder: vi.fn(), berkas: vi.fn(), ubahNama: vi.fn(), hubungkan: vi.fn(), hapus: vi.fn(), opsiHubungkan: vi.fn(), ekspor: vi.fn() },
}))
vi.mock('../services/berkasUnduhan', () => ({ ambilBerkas: vi.fn(), simpanBerkas: vi.fn() }))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import { selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { ambilFolderArsip, kosongkanFolderArsip, simpanFolderArsip } from '../arsip.cache'
import type { ArsipBerkas, ArsipBerkasResponse, ArsipFolder, ArsipFolderResponse } from '../arsip.types'
import { arsipService } from '../services/arsip.service'
import ArsipFolderPage from '../views/ArsipFolderPage.vue'

import { NIP } from './fixtures'

const folderApi = vi.mocked(arsipService.folder)
const berkasApi = vi.mocked(arsipService.berkas)
const hapusApi = vi.mocked(arsipService.hapus)
const ubahNamaApi = vi.mocked(arsipService.ubahNama)

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

function folder(id: number, label: string, urutan: number, jumlah: number): ArsipFolder {
  return { id_jenis_rwy: id, kode: `jenis_${id}`, label, urutan, jumlah, jumlah_hilang: 0, jumlah_tak_dapat_dibuka: 0 }
}

function ringkas(folders: ArsipFolder[] = [folder(9, 'Jabatan', 6, 12), folder(0, 'Belum Terhubung', 0, 3)]): ArsipFolderResponse {
  return {
    pegawai: { nip: NIP, nama: 'Budi Contoh', glr_depan: null, glr_belakang: null },
    folder: folders,
    total: 15,
    total_hilang: 0,
    total_tak_dapat_dibuka: 0,
    penyajian_berkas_legacy: 'publik',
    aksi: { tulis: true, jenis_hubungkan: [{ id_jenis_rwy: 38, label: 'Data Umum' }] },
  }
}

function berkas(id: number): ArsipBerkas {
  return {
    id_attachment: id,
    nama_arsip: `Arsip ${id}`,
    tag: `Arsip ${id}`,
    display_name: null,
    id_riwayat: 0,
    jenis_riwayat: 'Belum Terhubung',
    id_entri: null,
    ringkasan_riwayat: '—',
    file_type: 'application/pdf',
    created_at: '2026-10-08 03:15:00',
    berkas_ada: true,
    status_berkas: 'ada',
    akses_berkas: 'privat',
    url_berkas: `pegawai/${NIP}/arsip/berkas/${id}/buka`,
    jenis_buka: 'pratinjau',
    aksi: { edit: true, hubungkan: true, hapus: true },
  }
}

function hasil(items: ArsipBerkas[]): ArsipBerkasResponse {
  return { items, total: items.length, page: 1, per_page: 25 }
}

function galat(status: number, message: string, errors: Record<string, string[]> | null = null) {
  return { status, message, errors, isNetworkError: false, original: { response: { status, data: { message, errors } } } }
}

async function pasang() {
  loginAs(Role.SUPER_ADMIN)
  const router = createShellRouter()
  await router.push(`/arsip/${NIP}/semua`)
  await router.isReady()
  const wrapper = mount(ArsipFolderPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

async function hapusBaris(wrapper: ReturnType<typeof mount>, id: number): Promise<void> {
  await selectRowAction(wrapper, `arsip-actions-${id}`, 'hapus')
  ;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
  await flushPromises()
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  kosongkanFolderArsip()
  for (const fn of [folderApi, berkasApi, hapusApi, ubahNamaApi]) fn.mockReset()
  folderApi.mockResolvedValue(ringkas())
  berkasApi.mockResolvedValue(hasil([berkas(1), berkas(2)]))
})

describe('muat ulang sesudah mutasi', () => {
  it('GET folder gagal: data folder lama dipertahankan, ada "Coba lagi" yang memulihkan', async () => {
    hapusApi.mockResolvedValue(undefined)
    const wrapper = await pasang()
    folderApi.mockRejectedValueOnce({ status: 500, message: 'x', errors: null, isNetworkError: false, original: {} })
    await hapusBaris(wrapper, 1)
    expect(wrapper.get('[data-testid="arsip-galat-folder"]').text()).toContain('Terjadi kesalahan. Coba lagi.')
    expect(ambilFolderArsip(NIP)).toBeNull() // cache dikosongkan sesudah mutasi dan tidak diisi data basi
    expect(wrapper.findAll('[data-testid="arsip-filter-jenis"] option').map((o) => o.text())).toEqual(expect.arrayContaining(['Jabatan']))
    await wrapper.get('[data-testid="arsip-folder-coba-lagi"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="arsip-galat-folder"]').exists()).toBe(false)
  })

  it('folder yang dipilih di filter lenyap → select direset dan kueri tidak lagi memakai id_jenis_rwy itu', async () => {
    hapusApi.mockResolvedValue(undefined)
    const wrapper = await pasang()
    await wrapper.get('[data-testid="arsip-filter-jenis"] select').setValue('0')
    await flushPromises()
    expect(berkasApi).toHaveBeenLastCalledWith(NIP, { id_jenis_rwy: 0, q: '', page: 1, per_page: 25 })
    folderApi.mockResolvedValue(ringkas([folder(9, 'Jabatan', 6, 12)]))
    await hapusBaris(wrapper, 1)
    expect(wrapper.get<HTMLSelectElement>('[data-testid="arsip-filter-jenis"] select').element.value).toBe('')
    expect(berkasApi).toHaveBeenLastCalledWith(NIP, { q: '', page: 1, per_page: 25 })
  })

  it('Hapus 404 (baris basi) → daftar dan folder dimuat ulang, tanpa pesan sukses', async () => {
    hapusApi.mockRejectedValue(galat(404, 'Arsip sudah tidak ada.'))
    const wrapper = await pasang()
    berkasApi.mockClear()
    folderApi.mockClear()
    await hapusBaris(wrapper, 1)
    expect(berkasApi).toHaveBeenCalledTimes(1)
    expect(folderApi).toHaveBeenCalledTimes(1)
    const teks = wrapper.get('[data-testid="arsip-pesan"]').text()
    expect(teks).toContain('Arsip sudah tidak ada.')
    expect(teks).not.toContain('Arsip dihapus.')
  })

  it('Hapus 422 errors.id_attachment (sudah terhubung di server) juga memuat ulang daftar', async () => {
    hapusApi.mockRejectedValue(galat(422, 'x', { id_attachment: ['Data arsip ini telah terhubung ke data riwayat pegawai.'] }))
    const wrapper = await pasang()
    berkasApi.mockClear()
    await hapusBaris(wrapper, 1)
    expect(berkasApi).toHaveBeenCalledTimes(1)
  })

  it('dialog Edit 404 → daftar dimuat ulang, pesan galat tetap tampil di dialog', async () => {
    ubahNamaApi.mockRejectedValue(galat(404, 'Data tidak ditemukan.'))
    const wrapper = await pasang()
    await selectRowAction(wrapper, 'arsip-actions-2', 'edit')
    berkasApi.mockClear()
    q<HTMLElement>('[data-testid="arsip-edit-simpan"]')?.click()
    await flushPromises()
    expect(berkasApi).toHaveBeenCalledTimes(1)
    expect(q('[data-testid="arsip-edit-galat"]')?.textContent).toContain('Data tidak ditemukan.')
  })

  it('selama Hapus berjalan: Edit/Hubungkan/Hapus baris lain disabled, Buka tetap aktif', async () => {
    let selesai!: () => void
    hapusApi.mockReturnValue(new Promise<void>((resolve) => (selesai = resolve)))
    const wrapper = await pasang()
    await hapusBaris(wrapper, 1)
    await wrapper.get('[data-testid="arsip-actions-2"]').trigger('click')
    await flushPromises()
    const item = (aksi: string) => q(`[data-testid="arsip-actions-2-menu"] [data-action="${aksi}"]`)
    expect(item('edit')?.hasAttribute('data-disabled')).toBe(true)
    expect(item('hubungkan')?.hasAttribute('data-disabled')).toBe(true)
    expect(item('hapus')?.hasAttribute('data-disabled')).toBe(true)
    expect(item('buka')?.hasAttribute('data-disabled')).toBe(false)
    selesai()
    await flushPromises()
  })
})

describe('cache folder terikat sesi (P-1)', () => {
  it('cache milik sesi sebelumnya dibuang saat clearSession → GET folder dipanggil lagi', async () => {
    simpanFolderArsip(NIP, ringkas())
    useAuthStore().clearSession()
    await pasang()
    expect(folderApi).toHaveBeenCalledWith(NIP)
  })
})

describe('folder gagal dimuat saat pertama kali (P-2)', () => {
  it('Hubungkan menampilkan galat muat + "Coba lagi", bukan "tidak tersedia untuk Anda"; sesudah berhasil jenis bisa dipilih', async () => {
    folderApi.mockRejectedValueOnce({ status: 500, message: 'x', errors: null, isNetworkError: false, original: {} })
    const wrapper = await pasang()
    await selectRowAction(wrapper, 'arsip-actions-1', 'hubungkan')
    expect(q('[data-testid="arsip-hubungkan-tanpa-jenis"]')).toBeNull()
    expect(q('[data-testid="arsip-hubungkan-gagal-muat"]')?.textContent).toContain('Daftar jenis riwayat belum dapat dimuat.')
    folderApi.mockClear()
    q<HTMLElement>('[data-testid="arsip-hubungkan-coba-lagi"]')?.click()
    await flushPromises()
    expect(folderApi).toHaveBeenCalledWith(NIP)
    expect(q('[data-testid="arsip-hubungkan-gagal-muat"]')).toBeNull()
    const opsi = [...document.body.querySelectorAll('[data-testid="arsip-hubungkan-jenis"] option')].map((o) => o.textContent?.trim())
    expect(opsi).toContain('Data Umum')
  })
})

describe('label folder khusus sesudah habis (P-5)', () => {
  it('hapus satu-satunya baris folder 0 → judul tetap "Belum Terhubung"', async () => {
    hapusApi.mockResolvedValue(undefined)
    berkasApi.mockResolvedValue(hasil([berkas(1)]))
    loginAs(Role.SUPER_ADMIN)
    const router = createShellRouter()
    await router.push(`/arsip/${NIP}/0`)
    await router.isReady()
    const wrapper = mount(ArsipFolderPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    expect(wrapper.get('[data-testid="arsip-folder-judul"]').text()).toBe('Belum Terhubung')
    folderApi.mockResolvedValue(ringkas([folder(9, 'Jabatan', 6, 12)]))
    await hapusBaris(wrapper, 1)
    expect(wrapper.get('[data-testid="arsip-folder-judul"]').text()).toBe('Belum Terhubung')
  })
})
