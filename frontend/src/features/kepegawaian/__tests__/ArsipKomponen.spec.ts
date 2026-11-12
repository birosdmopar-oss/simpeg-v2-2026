/**
 * Komponen halaman Arsip (F-10, MAKE-091): ArsipFolderGrid (urutan, badge hilang / tak dapat dibuka, tautan folder) dan
 * ArsipBerkasTable (badge, kolom Jenis hanya di "Semua berkas", menu ⋮ lewat `data-action`, Buka publik/privat, galat,
 * tanpa HTML mentah).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../services/berkasUnduhan', () => ({ ambilBerkas: vi.fn(), simpanBerkas: vi.fn() }))

import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import type { ArsipBerkas, ArsipFolder } from '../arsip.types'
import ArsipBerkasTable from '../components/arsip/ArsipBerkasTable.vue'
import ArsipFolderGrid from '../components/arsip/ArsipFolderGrid.vue'
import { ambilBerkas, simpanBerkas } from '../services/berkasUnduhan'

import { NIP } from './fixtures'

const ambil = vi.mocked(ambilBerkas)
const simpan = vi.mocked(simpanBerkas)

afterEach(() => {
  document.body.innerHTML = ''
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

function berkas(id: number, overrides: Partial<ArsipBerkas> = {}): ArsipBerkas {
  return {
    id_attachment: id,
    nama_arsip: `Arsip ${id}`,
    tag: `Arsip ${id}`,
    display_name: null,
    id_riwayat: 9,
    jenis_riwayat: 'Jabatan',
    id_entri: '456',
    ringkasan_riwayat: 'Analis (Satker A)',
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

describe('ArsipFolderGrid', () => {
  async function pasang(folderList: ArsipFolder[], total = 40, hilang = 2, tak = 1) {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/arsip/:nip/:folder', name: 'arsip-folder', component: { render: () => h('div') } }],
    })
    await router.push(`/arsip/${NIP}/semua`)
    await router.isReady()
    return mount(ArsipFolderGrid, {
      props: { nip: NIP, folder: folderList, total, totalHilang: hilang, totalTakDapatDibuka: tak },
      global: { plugins: [router] },
    })
  }

  it('"Semua berkas" paling atas; Belum Terhubung dan Tanpa Jenis paling bawah; sisanya menurut urutan', async () => {
    const wrapper = await pasang([
      folder(null, 'Tanpa Jenis', null, 1),
      folder(0, 'Belum Terhubung', 0, 3),
      folder(9, 'Jabatan', 6, 12, 1),
      folder(1, 'Pendidikan', 2, 5),
    ])
    const urutan = wrapper.findAll('[data-testid^="arsip-folder-"]').filter((el) => /^arsip-folder-(semua|\d+|tanpa_jenis)$/.test(el.attributes('data-testid') ?? ''))
    expect(urutan.map((el) => el.attributes('data-testid'))).toEqual([
      'arsip-folder-semua',
      'arsip-folder-1',
      'arsip-folder-9',
      'arsip-folder-0',
      'arsip-folder-tanpa_jenis',
    ])
  })

  it('kartu memuat label, jumlah berkas, badge hilang / tidak dapat dibuka hanya bila > 0', async () => {
    const wrapper = await pasang([folder(9, 'Jabatan', 6, 12, 1, 0), folder(1, 'Pendidikan', 2, 5, 0, 2)])
    expect(wrapper.get('[data-testid="arsip-folder-jumlah-semua"]').text()).toBe('40 berkas')
    expect(wrapper.get('[data-testid="arsip-folder-hilang-semua"]').text()).toContain('2')
    expect(wrapper.get('[data-testid="arsip-folder-9"]').text()).toContain('Jabatan')
    expect(wrapper.get('[data-testid="arsip-folder-jumlah-9"]').text()).toBe('12 berkas')
    expect(wrapper.find('[data-testid="arsip-folder-hilang-9"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="arsip-folder-tak-dapat-dibuka-9"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="arsip-folder-hilang-1"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="arsip-folder-tak-dapat-dibuka-1"]').text()).toContain('2')
  })

  it('setiap kartu menaut ke /arsip/<nip>/<folder>; Belum Terhubung = 0, Tanpa Jenis = tanpa_jenis', async () => {
    const wrapper = await pasang([folder(0, 'Belum Terhubung', 0, 3), folder(null, 'Tanpa Jenis', null, 1), folder(9, 'Jabatan', 6, 12)])
    expect(wrapper.get('[data-testid="arsip-folder-semua"]').attributes('href')).toBe(`/arsip/${NIP}/semua`)
    expect(wrapper.get('[data-testid="arsip-folder-9"]').attributes('href')).toBe(`/arsip/${NIP}/9`)
    expect(wrapper.get('[data-testid="arsip-folder-0"]').attributes('href')).toBe(`/arsip/${NIP}/0`)
    expect(wrapper.get('[data-testid="arsip-folder-tanpa_jenis"]').attributes('href')).toBe(`/arsip/${NIP}/tanpa_jenis`)
  })

  it('label folder diinterpolasi sebagai teks (tanpa HTML)', async () => {
    const wrapper = await pasang([folder(9, '<img src=x onerror=alert(1)>', 6, 1)])
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.get('[data-testid="arsip-folder-9"]').text()).toContain('<img src=x onerror=alert(1)>')
  })
})

describe('ArsipBerkasTable', () => {
  let openSpy: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    ambil.mockReset()
    simpan.mockReset()
    openSpy = vi.spyOn(window, 'open').mockReturnValue(null)
  })
  afterEach(() => openSpy.mockRestore())

  const ITEMS: ArsipBerkas[] = [
    berkas(1),
    berkas(2, { akses_berkas: 'publik', url_berkas: '/berkas/arsip/<nip>/<sub>/SK.pdf' }),
    berkas(3, { berkas_ada: false, status_berkas: 'hilang', akses_berkas: null, url_berkas: null, jenis_buka: null }),
    berkas(4, { status_berkas: 'tak_dapat_dibuka', akses_berkas: null, url_berkas: null, jenis_buka: null }),
  ]

  function pasang(items: ArsipBerkas[] = ITEMS, tampilJenis = true, loading = false) {
    return mount(ArsipBerkasTable, { props: { items, loading, tampilJenis }, attachTo: document.body })
  }

  it('baris, badge status, dan ⋮ per baris (testid + aria-label "Aksi untuk <nama>")', () => {
    const wrapper = pasang()
    expect(wrapper.find('[data-testid="arsip-berkas-table"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid^="arsip-berkas-row-"]')).toHaveLength(4)
    expect(wrapper.find('[data-testid="arsip-badge-hilang-3"]').text()).toBe('Berkas hilang')
    expect(wrapper.find('[data-testid="arsip-badge-tak-dapat-dibuka-4"]').text()).toBe('Tidak dapat dibuka')
    expect(wrapper.find('[data-testid="arsip-badge-hilang-1"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="arsip-actions-1"]').attributes('aria-label')).toBe('Aksi untuk Arsip 1')
  })

  it('kolom Jenis Riwayat hanya di "Semua berkas"', () => {
    expect(pasang(ITEMS, true).text()).toContain('Jenis Riwayat')
    expect(pasang(ITEMS, false).text()).not.toContain('Jenis Riwayat')
  })

  it('kolom Aksi sticky di kanan (AC1-15) dan tabel menggulir di dalam kartu', () => {
    const wrapper = pasang()
    const kepala = wrapper.findAll('th').at(-1)
    expect(kepala?.text()).toBe('Aksi')
    expect(kepala?.classes()).toEqual(expect.arrayContaining(['sticky', 'right-0']))
    expect(wrapper.get('[data-testid="arsip-actions-1"]').element.closest('td')?.classList.contains('sticky')).toBe(true)
    expect(wrapper.get('[data-testid="arsip-berkas-table"]').element.parentElement?.classList.contains('overflow-x-auto')).toBe(true)
  })

  it('menu ⋮ Tahap 1 hanya berisi "Buka"; aktif bila url_berkas ada, disabled bila hilang / tidak dapat dibuka', async () => {
    const wrapper = pasang()
    expect(await rowMenuActions(wrapper, 'arsip-actions-1')).toEqual([{ key: 'buka', label: 'Buka', disabled: false, danger: false }])
    expect(await rowMenuActions(wrapper, 'arsip-actions-2')).toEqual([{ key: 'buka', label: 'Buka', disabled: false, danger: false }])
    expect(await rowMenuActions(wrapper, 'arsip-actions-3')).toEqual([{ key: 'buka', label: 'Buka', disabled: true, danger: false }])
    expect(await rowMenuActions(wrapper, 'arsip-actions-4')).toEqual([{ key: 'buka', label: 'Buka', disabled: true, danger: false }])
  })

  it('Buka publik → window.open popup 600×600 + noopener, tanpa API', async () => {
    const wrapper = pasang()
    await selectRowAction(wrapper, 'arsip-actions-2', 'buka')
    expect(openSpy).toHaveBeenCalledWith('/berkas/arsip/<nip>/<sub>/SK.pdf', 'arsip-popup', 'width=600,height=600,noopener')
    expect(ambil).not.toHaveBeenCalled()
  })

  it('Buka pada baris disabled tidak memanggil window.open maupun API', async () => {
    const wrapper = pasang()
    await selectRowAction(wrapper, 'arsip-actions-3', 'buka')
    await selectRowAction(wrapper, 'arsip-actions-4', 'buka')
    expect(openSpy).not.toHaveBeenCalled()
    expect(ambil).not.toHaveBeenCalled()
  })

  it('Buka privat pratinjau dengan popup diblokir → pesan di halaman (UiNotice) tanpa panggilan API', async () => {
    const wrapper = pasang()
    await selectRowAction(wrapper, 'arsip-actions-1', 'buka')
    expect(openSpy).toHaveBeenCalledWith('', 'arsip-popup', 'width=600,height=600')
    expect(ambil).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="arsip-buka-galat"]').text()).toContain('Popup diblokir browser.')
  })

  it('Buka privat unduh → tanpa window.open; disimpan lewat simpanBerkas', async () => {
    const berkasUnduh = { blob: new Blob(['x']), nama: 'Arsip 5.docx' }
    ambil.mockResolvedValue(berkasUnduh)
    const wrapper = pasang([berkas(5, { jenis_buka: 'unduh' })])
    await selectRowAction(wrapper, 'arsip-actions-5', 'buka')
    await vi.waitFor(() => expect(simpan).toHaveBeenCalledWith(berkasUnduh))
    expect(openSpy).not.toHaveBeenCalled()
  })

  it('galat 429 saat buka privat → pesan tampil di halaman, bisa ditutup', async () => {
    ambil.mockRejectedValue({
      status: 429,
      message: 'x',
      errors: null,
      isNetworkError: false,
      original: { response: { status: 429, data: { message: 'Terlalu banyak membuka berkas arsip. Coba lagi dalam satu menit.' } } },
    })
    const wrapper = pasang([berkas(5, { jenis_buka: 'unduh' })])
    await selectRowAction(wrapper, 'arsip-actions-5', 'buka')
    await flushPromises()
    expect(wrapper.get('[data-testid="arsip-buka-galat"]').text()).toContain('Terlalu banyak membuka berkas arsip.')
  })

  it('keadaan kosong dan memuat', () => {
    const kosong = pasang([])
    expect(kosong.get('[data-testid="arsip-berkas-kosong"]').text()).toContain('Belum ada berkas.')
    const memuat = pasang([], true, true)
    expect(memuat.find('[data-testid="arsip-berkas-kosong"]').exists()).toBe(false)
    expect(memuat.findAll('tbody tr[aria-hidden="true"]').length).toBeGreaterThan(0)
  })

  it('nama arsip dan ringkasan diinterpolasi sebagai teks (tanpa v-html)', () => {
    const wrapper = pasang([berkas(7, { nama_arsip: '<img src=x onerror=alert(1)>', ringkasan_riwayat: '<b>tebal</b>' })])
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('b').exists()).toBe(false)
    expect(wrapper.get('[data-testid="arsip-berkas-row-7"]').text()).toContain('<img src=x onerror=alert(1)>')
  })
})
