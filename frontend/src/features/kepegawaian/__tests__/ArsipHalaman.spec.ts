/**
 * Halaman Arsip (F-10, MAKE-091): route + guard + pengalihan role 2/6/7 (AC1-03), akses role 1–7 / role 8 ditolak
 * (AC1-04), ArsipPage (pemilih pegawai admin, folder, galat, kosong), ArsipFolderPage (tabel per folder, pencarian,
 * paginasi, breadcrumb, folder tidak sah).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Router } from 'vue-router'

vi.mock('../services/arsip.service', () => ({ arsipService: { folder: vi.fn(), berkas: vi.fn() } }))
vi.mock('../services/pegawai.service', () => ({ pegawaiService: { list: vi.fn() } }))

import { Role, type RoleCode } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import type { ArsipBerkas, ArsipBerkasResponse, ArsipFolder, ArsipFolderResponse } from '../arsip.types'
import { kosongkanFolderArsip, simpanFolderArsip } from '../arsip.cache'
import { filterDariFolder, labelFolder, namaPegawaiArsip, urutkanFolder } from '../arsip.utils'
import { alihkanArsipSendiri } from '../routes.arsip'
import { arsipService } from '../services/arsip.service'
import { pegawaiService } from '../services/pegawai.service'
import ArsipFolderPage from '../views/ArsipFolderPage.vue'
import ArsipPage from '../views/ArsipPage.vue'

import { NIP } from './fixtures'

const folderApi = vi.mocked(arsipService.folder)
const berkasApi = vi.mocked(arsipService.berkas)
const pegawaiList = vi.mocked(pegawaiService.list)

const NIP_LAIN = '198505052010012002'
afterEach(() => {
  document.body.innerHTML = ''
  vi.useRealTimers()
})
enableAutoUnmount(afterEach)

function folder(id: number | null, label: string, urutan: number | null, jumlah: number, hilang = 0, tak = 0): ArsipFolder {
  return {
    id_jenis_rwy: id,
    kode: id === null ? 'tanpa_jenis' : id === 0 ? 'belum_terhubung' : `jenis_${id}`,
    label,
    urutan,
    jumlah,
    jumlah_hilang: hilang,
    jumlah_tak_dapat_dibuka: tak,
  }
}

function ringkas(opsi: Partial<ArsipFolderResponse> = {}): ArsipFolderResponse {
  return {
    pegawai: { nip: NIP, nama: 'Budi Contoh', glr_depan: 'Dr.', glr_belakang: 'S.H.' },
    folder: [folder(9, 'Jabatan', 6, 12, 1), folder(0, 'Belum Terhubung', 0, 3)],
    total: 15,
    total_hilang: 1,
    total_tak_dapat_dibuka: 0,
    penyajian_berkas_legacy: 'publik',
    aksi: { tulis: false, jenis_hubungkan: [] },
    ...opsi,
  }
}

function berkas(id: number, overrides: Partial<ArsipBerkas> = {}): ArsipBerkas {
  return {
    id_attachment: id,
    nama_arsip: `Arsip ${id}`,
    tag: null,
    display_name: null,
    id_riwayat: 9,
    jenis_riwayat: 'Jabatan',
    id_entri: '1',
    ringkasan_riwayat: '—',
    file_type: 'application/pdf',
    created_at: '2026-10-08 03:15:00',
    berkas_ada: true,
    status_berkas: 'ada',
    akses_berkas: 'privat',
    url_berkas: `pegawai/${NIP}/arsip/berkas/${id}/buka`,
    jenis_buka: 'pratinjau',
    aksi: { edit: false, hubungkan: false, hapus: false },
    ...overrides,
  }
}

function hasilBerkas(items: ArsipBerkas[], total = items.length, page = 1): ArsipBerkasResponse {
  return { items, total, page, per_page: 25 }
}

async function routerArsip(path: string, role: RoleCode = Role.SUPER_ADMIN, extra: { nip?: string | null } = {}): Promise<Router> {
  loginAs(role, { nip: extra.nip ?? null })
  const router = createShellRouter()
  await router.push(path)
  await router.isReady()
  return router
}

beforeEach(() => {
  kosongkanFolderArsip()
  folderApi.mockReset()
  berkasApi.mockReset()
  pegawaiList.mockReset()
})

describe('alihkanArsipSendiri — role 2/6/7 selalu ke arsip sendiri tanpa pesan galat (AC1-03)', () => {
  it.each([Role.PEGAWAI, Role.PTT, Role.PPPK])('role %i: /arsip → /arsip/<nip sendiri>', (role) => {
    expect(alihkanArsipSendiri({ name: 'arsip', params: {} }, role, NIP)).toEqual({ name: 'arsip-pegawai', params: { nip: NIP }, replace: true })
  })

  it.each([Role.PEGAWAI, Role.PTT, Role.PPPK])('role %i: /arsip/<nip lain> → /arsip/<nip sendiri>', (role) => {
    expect(alihkanArsipSendiri({ name: 'arsip-pegawai', params: { nip: NIP_LAIN } }, role, NIP)).toEqual({
      name: 'arsip-pegawai',
      params: { nip: NIP },
      replace: true,
    })
  })

  it('/arsip/<nip lain>/<folder> → /arsip/<nip sendiri>/<folder> (folder dipertahankan)', () => {
    expect(alihkanArsipSendiri({ name: 'arsip-folder', params: { nip: NIP_LAIN, folder: '9' } }, Role.PEGAWAI, NIP)).toEqual({
      name: 'arsip-folder',
      params: { nip: NIP, folder: '9' },
      replace: true,
    })
  })

  it('NIP sendiri dibiarkan', () => {
    expect(alihkanArsipSendiri({ name: 'arsip-pegawai', params: { nip: NIP } }, Role.PEGAWAI, NIP)).toBe(true)
    expect(alihkanArsipSendiri({ name: 'arsip-folder', params: { nip: NIP, folder: 'semua' } }, Role.PPPK, NIP)).toBe(true)
  })

  it.each([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI])('role %i (admin) tidak dialihkan', (role) => {
    expect(alihkanArsipSendiri({ name: 'arsip', params: {} }, role, null)).toBe(true)
    expect(alihkanArsipSendiri({ name: 'arsip-pegawai', params: { nip: NIP_LAIN } }, role, NIP)).toBe(true)
  })

  it('akun 2/6/7 tanpa NIP: dibiarkan (server yang menolak)', () => {
    expect(alihkanArsipSendiri({ name: 'arsip', params: {} }, Role.PEGAWAI, null)).toBe(true)
  })
})

describe('utilitas arsip', () => {
  it('namaPegawaiArsip: gelar depan + nama, gelar belakang sesudah koma', () => {
    expect(namaPegawaiArsip({ nama: 'Budi Contoh', glr_depan: 'Dr.', glr_belakang: 'S.H.' })).toBe('Dr. Budi Contoh, S.H.')
    expect(namaPegawaiArsip({ nama: 'Budi Contoh', glr_depan: null, glr_belakang: null })).toBe('Budi Contoh')
  })

  it('urutkanFolder: urutan naik, Belum Terhubung (0) lalu Tanpa Jenis paling bawah', () => {
    const hasil = urutkanFolder([folder(null, 'Tanpa Jenis', null, 1), folder(0, 'Belum Terhubung', 0, 1), folder(9, 'Jabatan', 6, 1), folder(1, 'Pendidikan', 2, 1)])
    expect(hasil.map((f) => f.id_jenis_rwy)).toEqual([1, 9, 0, null])
  })

  it('filterDariFolder: semua / angka / tanpa_jenis / tidak sah', () => {
    expect(filterDariFolder('semua')).toEqual({})
    expect(filterDariFolder('9')).toEqual({ id_jenis_rwy: 9 })
    expect(filterDariFolder('0')).toEqual({ id_jenis_rwy: 0 })
    expect(filterDariFolder('tanpa_jenis')).toEqual({ id_jenis_rwy: 'tanpa_jenis' })
    expect(filterDariFolder('9;DROP')).toBeNull()
    expect(filterDariFolder('abc')).toBeNull()
  })

  it('labelFolder', () => {
    expect(labelFolder('semua', [])).toBe('Semua berkas')
    expect(labelFolder('9', [folder(9, 'Jabatan', 6, 1)])).toBe('Jabatan')
    expect(labelFolder('tanpa_jenis', [folder(null, 'Tanpa Jenis', null, 1)])).toBe('Tanpa Jenis')
    expect(labelFolder('77', [])).toBe('Folder')
    expect(labelFolder('0', [])).toBe('Belum Terhubung')
    expect(labelFolder('tanpa_jenis', [])).toBe('Tanpa Jenis')
  })
})

describe('ArsipPage', () => {
  async function pasang(path: string, role: RoleCode = Role.SUPER_ADMIN) {
    const router = await routerArsip(path, role)
    const wrapper = mount(ArsipPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    return { wrapper, router }
  }

  it('/arsip/:nip: memuat folder dari API dan menampilkan nama pegawai + grid folder', async () => {
    folderApi.mockResolvedValue(ringkas())
    const { wrapper } = await pasang(`/arsip/${NIP}`)
    expect(folderApi).toHaveBeenCalledWith(NIP)
    expect(wrapper.get('[data-testid="arsip-pegawai-nama"]').text()).toBe('Dr. Budi Contoh, S.H.')
    expect(wrapper.find('[data-testid="arsip-folder-semua"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="arsip-folder-9"]').text()).toContain('Jabatan')
    expect(wrapper.get('[data-testid="arsip-folder-0"]').text()).toContain('Belum Terhubung')
    // Admin tidak memanggil pemilih saat NIP ada.
    expect(wrapper.find('[data-testid="arsip-pemilih-pegawai"]').exists()).toBe(false)
  })

  it('/arsip tanpa NIP: tampil pemilih pegawai, tidak memanggil API folder', async () => {
    const { wrapper } = await pasang('/arsip')
    expect(wrapper.find('[data-testid="arsip-pemilih-pegawai"]').exists()).toBe(true)
    expect(folderApi).not.toHaveBeenCalled()
  })

  it('pemilih: ≥ 3 huruf memanggil GET pegawai?search= (debounce 300 ms); memilih saran membuka /arsip/<nip>', async () => {
    vi.useFakeTimers()
    pegawaiList.mockResolvedValue({
      items: [{ nip: NIP, nama: 'Budi Contoh', glr_awal: 'Dr.', glr_akhir: 'S.H.' }],
      total: 1,
      page: 1,
      per_page: 10,
    } as never)
    const router = await routerArsip('/arsip')
    const wrapper = mount(ArsipPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()

    const input = wrapper.get('[data-testid="arsip-pemilih-pegawai"] input')
    await input.setValue('bu')
    await vi.advanceTimersByTimeAsync(400)
    expect(pegawaiList).not.toHaveBeenCalled()

    await input.setValue('bud')
    await vi.advanceTimersByTimeAsync(299)
    expect(pegawaiList).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    expect(pegawaiList).toHaveBeenCalledWith({ page: 1, per_page: 10, search: 'bud' })

    folderApi.mockResolvedValue(ringkas())
    await input.setValue(`${NIP} — Dr. Budi Contoh, S.H.`)
    await vi.advanceTimersByTimeAsync(0)
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('arsip-pegawai')
    expect(router.currentRoute.value.params.nip).toBe(NIP)
  })

  it('pegawai tanpa berkas → keterangan kosong, tanpa grid', async () => {
    folderApi.mockResolvedValue(ringkas({ folder: [], total: 0, total_hilang: 0 }))
    const { wrapper } = await pasang(`/arsip/${NIP}`)
    expect(wrapper.find('[data-testid="arsip-kosong"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="arsip-folder-grid"]').exists()).toBe(false)
  })

  it('403 dari server → pesan galat baku, tanpa grid', async () => {
    folderApi.mockRejectedValue({ status: 403, message: 'x', errors: null, isNetworkError: false, original: {} })
    const { wrapper } = await pasang(`/arsip/${NIP_LAIN}`, Role.ADMIN_SATKER)
    expect(wrapper.get('[data-testid="arsip-failure"]').text()).toBe('Anda tidak berhak mengakses data ini.')
    expect(wrapper.find('[data-testid="arsip-folder-grid"]').exists()).toBe(false)
  })

  it('nama pegawai tidak dirender sebagai HTML', async () => {
    folderApi.mockResolvedValue(ringkas({ pegawai: { nip: NIP, nama: '<img src=x onerror=alert(1)>', glr_depan: null, glr_belakang: null } }))
    const { wrapper } = await pasang(`/arsip/${NIP}`)
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.get('[data-testid="arsip-pegawai-nama"]').text()).toBe('<img src=x onerror=alert(1)>')
  })

  it.each([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PEGAWAI, Role.PTT, Role.PPPK])('role %i dapat memuat halaman', async (role) => {
    folderApi.mockResolvedValue(ringkas())
    const { wrapper } = await pasang(`/arsip/${NIP}`, role)
    expect(wrapper.find('[data-testid="arsip-page"]').exists()).toBe(true)
  })
})

describe('ArsipFolderPage', () => {
  async function pasang(path: string, role: RoleCode = Role.SUPER_ADMIN) {
    const router = await routerArsip(path, role)
    const wrapper = mount(ArsipFolderPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    return { wrapper, router }
  }

  beforeEach(() => {
    folderApi.mockResolvedValue(ringkas())
    berkasApi.mockResolvedValue(hasilBerkas([berkas(1), berkas(2, { status_berkas: 'hilang', berkas_ada: false, akses_berkas: null, url_berkas: null, jenis_buka: null })]))
  })

  it('"Semua berkas": memuat daftar tanpa filter jenis, kolom Jenis Riwayat tampil, judul + jumlah', async () => {
    const { wrapper } = await pasang(`/arsip/${NIP}/semua`)
    expect(berkasApi).toHaveBeenCalledWith(NIP, { q: '', page: 1, per_page: 25 })
    expect(wrapper.get('[data-testid="arsip-folder-judul"]').text()).toBe('Semua berkas')
    expect(wrapper.text()).toContain('2 berkas')
    expect(wrapper.get('[data-testid="arsip-berkas-table"]').text()).toContain('Jenis Riwayat')
    expect(wrapper.findAll('[data-testid^="arsip-berkas-row-"]')).toHaveLength(2)
    expect(wrapper.find('[data-testid="arsip-badge-hilang-2"]').exists()).toBe(true)
  })

  it('folder jenis: filter id_jenis_rwy, kolom Jenis Riwayat disembunyikan, judul dari label folder', async () => {
    const { wrapper } = await pasang(`/arsip/${NIP}/9`)
    expect(berkasApi).toHaveBeenCalledWith(NIP, { id_jenis_rwy: 9, q: '', page: 1, per_page: 25 })
    expect(wrapper.get('[data-testid="arsip-folder-judul"]').text()).toBe('Jabatan')
    expect(wrapper.get('[data-testid="arsip-berkas-table"]').text()).not.toContain('Jenis Riwayat')
  })

  it('folder Tanpa Jenis dan Belum Terhubung (0) memakai filter yang benar', async () => {
    await pasang(`/arsip/${NIP}/tanpa_jenis`)
    expect(berkasApi).toHaveBeenLastCalledWith(NIP, { id_jenis_rwy: 'tanpa_jenis', q: '', page: 1, per_page: 25 })
    berkasApi.mockClear()
    await pasang(`/arsip/${NIP}/0`)
    expect(berkasApi).toHaveBeenLastCalledWith(NIP, { id_jenis_rwy: 0, q: '', page: 1, per_page: 25 })
  })

  it('folder tidak sah → pesan, tanpa memanggil API berkas', async () => {
    const { wrapper } = await pasang(`/arsip/${NIP}/abc`)
    expect(berkasApi).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="arsip-failure"]').text()).toBe('Folder tidak ditemukan.')
  })

  it('breadcrumb: Arsip › nama pegawai (gelar) › nama folder', async () => {
    const { wrapper } = await pasang(`/arsip/${NIP}/9`)
    const crumb = wrapper.get('nav[aria-label="Breadcrumb"]')
    expect(crumb.findAll('li').map((li) => li.text())).toEqual(['Arsip', 'Dr. Budi Contoh, S.H.', 'Jabatan'])
    expect(crumb.findAll('a').map((a) => a.attributes('href'))).toEqual(['/arsip', `/arsip/${NIP}`])
    expect(wrapper.get('[data-testid="arsip-kembali"]').attributes('href')).toBe(`/arsip/${NIP}`)
  })

  it('pencarian: debounce 300 ms, q dikirim apa adanya, kembali ke halaman 1', async () => {
    const { wrapper } = await pasang(`/arsip/${NIP}/semua`)
    vi.useFakeTimers()
    berkasApi.mockClear()
    await wrapper.get('[data-testid="arsip-cari"] input').setValue(' 100%_ ')
    await vi.advanceTimersByTimeAsync(299)
    expect(berkasApi).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()
    expect(berkasApi).toHaveBeenCalledWith(NIP, { q: '100%_', page: 1, per_page: 25 })
  })

  it('paginasi: total > per_page menampilkan UiPagination; pindah halaman memuat ulang', async () => {
    berkasApi.mockResolvedValue(hasilBerkas([berkas(1)], 60))
    const { wrapper } = await pasang(`/arsip/${NIP}/semua`)
    expect(wrapper.text()).toContain('60 berkas')
    berkasApi.mockClear()
    const halaman2 = wrapper.findAll('button').find((b) => b.text() === '2')
    expect(halaman2).toBeDefined()
    await halaman2?.trigger('click')
    await flushPromises()
    expect(berkasApi).toHaveBeenCalledWith(NIP, { q: '', page: 2, per_page: 25 })
  })

  it('galat 403 dari daftar berkas → pesan baku, tanpa tabel', async () => {
    berkasApi.mockRejectedValue({ status: 403, message: 'x', errors: null, isNetworkError: false, original: {} })
    const { wrapper } = await pasang(`/arsip/${NIP}/semua`, Role.ADMIN_SATKER)
    expect(wrapper.get('[data-testid="arsip-failure"]').text()).toContain('Anda tidak berhak mengakses data ini.')
    expect(wrapper.find('[data-testid="arsip-berkas-table"]').exists()).toBe(false)
  })
})

describe('ArsipFolderPage — pemulihan galat, keadaan kosong, cache folder', () => {
  async function pasang(path: string) {
    const router = await routerArsip(path)
    const wrapper = mount(ArsipFolderPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  beforeEach(() => {
    folderApi.mockResolvedValue(ringkas())
    berkasApi.mockResolvedValue(hasilBerkas([berkas(1)]))
  })

  it('galat daftar: kotak cari tetap ada, tombol "Coba lagi" memuat ulang dan memulihkan tabel', async () => {
    berkasApi.mockRejectedValueOnce({ status: 500, message: 'x', errors: null, isNetworkError: false, original: {} })
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    expect(wrapper.get('[data-testid="arsip-failure"]').text()).toContain('Terjadi kesalahan. Coba lagi.')
    expect(wrapper.find('[data-testid="arsip-cari"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="arsip-berkas-table"]').exists()).toBe(false)

    await wrapper.get('[data-testid="arsip-coba-lagi"]').trigger('click')
    await flushPromises()
    expect(berkasApi).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="arsip-failure"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="arsip-berkas-row-1"]').exists()).toBe(true)
  })

  it('folder tidak sah tetap mengganti seluruh kartu (tanpa kotak cari)', async () => {
    const wrapper = await pasang(`/arsip/${NIP}/abc`)
    expect(wrapper.get('[data-testid="arsip-failure"]').text()).toBe('Folder tidak ditemukan.')
    expect(wrapper.find('[data-testid="arsip-cari"]').exists()).toBe(false)
  })

  it('pencarian tanpa hasil → "Tidak ada berkas yang cocok dengan pencarian."; tanpa pencarian → "Belum ada berkas."', async () => {
    berkasApi.mockResolvedValue(hasilBerkas([]))
    const wrapper = await pasang(`/arsip/${NIP}/semua`)
    expect(wrapper.get('[data-testid="arsip-berkas-kosong"]').text()).toBe('Belum ada berkas.')
    vi.useFakeTimers()
    await wrapper.get('[data-testid="arsip-cari"] input').setValue('zzz')
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(wrapper.get('[data-testid="arsip-berkas-kosong"]').text()).toBe('Tidak ada berkas yang cocok dengan pencarian.')
  })

  it('cache folder: dipakai ulang bila NIP sama (API folder tidak dipanggil lagi), dipanggil bila kosong atau NIP lain', async () => {
    simpanFolderArsip(NIP, ringkas())
    const dariCache = await pasang(`/arsip/${NIP}/9`)
    expect(folderApi).not.toHaveBeenCalled()
    expect(dariCache.get('[data-testid="arsip-folder-judul"]').text()).toBe('Jabatan')

    folderApi.mockClear()
    simpanFolderArsip(NIP_LAIN, ringkas())
    await pasang(`/arsip/${NIP}/9`)
    expect(folderApi).toHaveBeenCalledTimes(1)
    expect(folderApi).toHaveBeenCalledWith(NIP)

    folderApi.mockClear()
    kosongkanFolderArsip()
    await pasang(`/arsip/${NIP}/9`)
    expect(folderApi).toHaveBeenCalledTimes(1)
  })

  it('ArsipPage mengisi cache sehingga halaman folder berikutnya tidak memanggil API folder lagi', async () => {
    const router = await routerArsip(`/arsip/${NIP}`)
    mount(ArsipPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    expect(folderApi).toHaveBeenCalledTimes(1)
    folderApi.mockClear()
    await pasang(`/arsip/${NIP}/9`)
    expect(folderApi).not.toHaveBeenCalled()
  })
})
