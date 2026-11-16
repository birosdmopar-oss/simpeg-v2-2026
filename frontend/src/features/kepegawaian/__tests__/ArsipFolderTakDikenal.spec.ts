/**
 * ArsipFolderPage — folder berangka yang tidak dikenal (CR-126 T-01; BE CR-127): GET daftar berkas menjawab 404
 * "Folder tidak ditemukan." untuk `id_jenis_rwy` yang tidak ada di master jenis. FE menampilkan pesan itu (tanpa tabel,
 * tanpa "Belum ada berkas."), membedakannya dari 404 lain (mis. NIP tidak ada), dan tidak menyimpan daftar jenis sendiri.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/arsip.service', () => ({
  arsipService: { folder: vi.fn(), berkas: vi.fn(), ubahNama: vi.fn(), hubungkan: vi.fn(), hapus: vi.fn(), opsiHubungkan: vi.fn(), ekspor: vi.fn() },
}))
vi.mock('../services/berkasUnduhan', () => ({ ambilBerkas: vi.fn(), simpanBerkas: vi.fn() }))

import { Role } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { kosongkanFolderArsip } from '../arsip.cache'
import type { ArsipFolderResponse } from '../arsip.types'
import { arsipService } from '../services/arsip.service'
import ArsipFolderPage from '../views/ArsipFolderPage.vue'

import { NIP } from './fixtures'

const folderApi = vi.mocked(arsipService.folder)
const berkasApi = vi.mocked(arsipService.berkas)

function ringkas(): ArsipFolderResponse {
  return {
    pegawai: { nip: NIP, nama: 'Budi Contoh', glr_depan: null, glr_belakang: null },
    folder: [{ id_jenis_rwy: 9, kode: 'jenis_9', label: 'Jabatan', urutan: 6, jumlah: 12, jumlah_hilang: 0, jumlah_tak_dapat_dibuka: 0 }],
    total: 12,
    total_hilang: 0,
    total_tak_dapat_dibuka: 0,
    penyajian_berkas_legacy: 'publik',
    aksi: { tulis: true, jenis_hubungkan: [{ id_jenis_rwy: 38, label: 'Data Umum' }] },
  }
}

function galat(status: number, message: string) {
  return { status, message, errors: null, isNetworkError: false, original: { response: { status, data: { message, errors: null } } } }
}

async function pasang(path: string) {
  loginAs(Role.SUPER_ADMIN)
  const router = createShellRouter()
  await router.push(path)
  await router.isReady()
  const wrapper = mount(ArsipFolderPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  kosongkanFolderArsip()
  folderApi.mockReset()
  berkasApi.mockReset()
  folderApi.mockResolvedValue(ringkas())
})

describe('folder berangka tidak dikenal (404 dari BE)', () => {
  it('404 "Folder tidak ditemukan." → pesan itu, judul "Folder", tanpa tabel / "Belum ada berkas." / ekspor / coba lagi', async () => {
    berkasApi.mockRejectedValue(galat(404, 'Folder tidak ditemukan.'))
    const wrapper = await pasang(`/arsip/${NIP}/2`)
    expect(berkasApi).toHaveBeenCalledWith(NIP, { id_jenis_rwy: 2, q: '', page: 1, per_page: 25 })
    expect(wrapper.get('[data-testid="arsip-failure"]').text()).toBe('Folder tidak ditemukan.')
    expect(wrapper.get('[data-testid="arsip-folder-judul"]').text()).toBe('Folder')
    expect(wrapper.find('[data-testid="arsip-berkas-kosong"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Belum ada berkas.')
    expect(wrapper.find('[data-testid="arsip-ekspor"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="arsip-coba-lagi"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="arsip-cari"]').exists()).toBe(false)
  })

  it('404 lain (mis. NIP tidak ada) tidak diperlakukan sebagai folder tak dikenal: pesan baku + Coba lagi tetap ada', async () => {
    berkasApi.mockRejectedValue(galat(404, 'Pegawai tidak ditemukan (uji).'))
    const wrapper = await pasang(`/arsip/${NIP}/9`)
    const teks = wrapper.get('[data-testid="arsip-failure"]').text()
    expect(teks).not.toContain('Folder tidak ditemukan.')
    expect(wrapper.find('[data-testid="arsip-coba-lagi"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="arsip-ekspor"]').exists()).toBe(true)
  })

  it('jenis sah tetapi tanpa berkas (200 kosong) → tetap "Belum ada berkas."', async () => {
    berkasApi.mockResolvedValue({ items: [], total: 0, page: 1, per_page: 25 })
    const wrapper = await pasang(`/arsip/${NIP}/9`)
    expect(wrapper.find('[data-testid="arsip-failure"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="arsip-berkas-kosong"]').text()).toContain('Belum ada berkas.')
  })

  it.each(['semua', '0', 'tanpa_jenis'])('folder khusus %s tetap berfungsi (200)', async (folder) => {
    berkasApi.mockResolvedValue({ items: [], total: 0, page: 1, per_page: 25 })
    const wrapper = await pasang(`/arsip/${NIP}/${folder}`)
    expect(wrapper.find('[data-testid="arsip-failure"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="arsip-berkas-kosong"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="arsip-ekspor"]').exists()).toBe(true)
  })

  it('nilai bukan angka (mis. "abc") tetap "Folder tidak ditemukan." tanpa memanggil API daftar', async () => {
    const wrapper = await pasang(`/arsip/${NIP}/abc`)
    expect(berkasApi).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="arsip-failure"]').text()).toBe('Folder tidak ditemukan.')
  })

  it('pindah dari folder tak dikenal ke folder sah memulihkan tampilan', async () => {
    berkasApi.mockRejectedValueOnce(galat(404, 'Folder tidak ditemukan.'))
    const wrapper = await pasang(`/arsip/${NIP}/2`)
    expect(wrapper.find('[data-testid="arsip-berkas-kosong"]').exists()).toBe(false)
    berkasApi.mockResolvedValue({ items: [], total: 0, page: 1, per_page: 25 })
    const router = wrapper.vm.$router
    await router.push(`/arsip/${NIP}/9`)
    await flushPromises()
    expect(wrapper.find('[data-testid="arsip-failure"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="arsip-berkas-kosong"]').text()).toContain('Belum ada berkas.')
  })
})
