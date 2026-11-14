/**
 * Dialog Arsip Tahap 2 — perbaikan review MAKE-096: isian awal Ubah Nama saat tag hanya spasi, isian dinonaktifkan selama
 * proses, event `basi` pada baris basi (404), pesan 422 `errors.id_riwayat`, pesan bila tidak ada jenis tujuan.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/arsip.service', () => ({
  arsipService: { ubahNama: vi.fn(), hubungkan: vi.fn(), opsiHubungkan: vi.fn() },
}))

import type { ArsipBerkas } from '../arsip.types'
import ArsipEditDialog from '../components/arsip/ArsipEditDialog.vue'
import ArsipHubungkanDialog from '../components/arsip/ArsipHubungkanDialog.vue'
import { arsipService } from '../services/arsip.service'

import { NIP } from './fixtures'

const ubahNama = vi.mocked(arsipService.ubahNama)
const hubungkan = vi.mocked(arsipService.hubungkan)
const opsi = vi.mocked(arsipService.opsiHubungkan)

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)
const JABATAN = [{ id_jenis_rwy: 9, label: 'Jabatan' }]

function galat(status: number, message: string, errors: Record<string, string[]> | null = null) {
  return { status, message, errors, isNetworkError: false, original: { response: { status, data: { message, errors } } } }
}

function berkas(overrides: Partial<ArsipBerkas> = {}): ArsipBerkas {
  return {
    id_attachment: 77,
    nama_arsip: 'SK CPNS',
    tag: 'SK CPNS',
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
    url_berkas: `pegawai/${NIP}/arsip/berkas/77/buka`,
    jenis_buka: 'pratinjau',
    aksi: { edit: true, hubungkan: true, hapus: true },
    ...overrides,
  }
}

async function pilih(sel: string, nilai: string): Promise<void> {
  const select = q<HTMLSelectElement>(`${sel} select`)
  if (!select) throw new Error(`select ${sel} tidak ada`)
  select.value = nilai
  select.dispatchEvent(new Event('change'))
  await flushPromises()
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  ubahNama.mockReset()
  hubungkan.mockReset()
  opsi.mockReset()
})

describe('ArsipEditDialog — perbaikan review', () => {
  it('tag hanya spasi → isian awal memakai nama_arsip', async () => {
    mount(ArsipEditDialog, { props: { open: true, nip: NIP, item: berkas({ tag: '   ', nama_arsip: 'Berkas 77' }) }, attachTo: document.body })
    await flushPromises()
    expect(q<HTMLInputElement>('[data-testid="arsip-edit-nama"] input')?.value).toBe('Berkas 77')
  })

  it('isian dinonaktifkan selama menyimpan; 404 memancarkan basi', async () => {
    let tolak!: (e: unknown) => void
    ubahNama.mockReturnValue(new Promise<void>((_, reject) => (tolak = reject)))
    const wrapper = mount(ArsipEditDialog, { props: { open: true, nip: NIP, item: berkas() }, attachTo: document.body })
    await flushPromises()
    q<HTMLElement>('[data-testid="arsip-edit-simpan"]')?.click()
    await flushPromises()
    expect(q<HTMLInputElement>('[data-testid="arsip-edit-nama"] input')?.disabled).toBe(true)
    tolak(galat(404, 'Data tidak ditemukan.'))
    await flushPromises()
    expect(wrapper.emitted('basi')).toHaveLength(1)
  })

  it('batas 255 dihitung per code point: 130 emoji diterima, 256 huruf ditolak di klien', async () => {
    ubahNama.mockResolvedValue(undefined)
    const wrapper = mount(ArsipEditDialog, { props: { open: true, nip: NIP, item: berkas() }, attachTo: document.body })
    await flushPromises()
    const input = q<HTMLInputElement>('[data-testid="arsip-edit-nama"] input')
    if (!input) throw new Error('input tidak ada')
    input.value = '😀'.repeat(130)
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    q<HTMLElement>('[data-testid="arsip-edit-simpan"]')?.click()
    await flushPromises()
    expect(ubahNama).toHaveBeenCalledTimes(1)
    wrapper.unmount()
    ubahNama.mockClear()
    mount(ArsipEditDialog, { props: { open: true, nip: NIP, item: berkas() }, attachTo: document.body })
    await flushPromises()
    const input2 = q<HTMLInputElement>('[data-testid="arsip-edit-nama"] input')
    if (!input2) throw new Error('input tidak ada')
    input2.value = 'a'.repeat(256)
    input2.dispatchEvent(new Event('input'))
    await flushPromises()
    q<HTMLElement>('[data-testid="arsip-edit-simpan"]')?.click()
    await flushPromises()
    expect(ubahNama).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('maksimal 255')
  })

  it('422 errors.tag bukan baris basi → tidak memancarkan basi', async () => {
    ubahNama.mockRejectedValue(galat(422, 'x', { tag: ['Nama arsip tidak valid.'] }))
    const wrapper = mount(ArsipEditDialog, { props: { open: true, nip: NIP, item: berkas() }, attachTo: document.body })
    await flushPromises()
    q<HTMLElement>('[data-testid="arsip-edit-simpan"]')?.click()
    await flushPromises()
    expect(wrapper.emitted('basi')).toBeUndefined()
  })
})

describe('ArsipHubungkanDialog — perbaikan review', () => {
  it('select dinonaktifkan selama POST; 404 memancarkan basi', async () => {
    opsi.mockResolvedValue({ id_riwayat: 9, butuh_data: true, items: [{ id_entri: '456', ringkasan_riwayat: 'Analis' }] })
    let tolak!: (e: unknown) => void
    hubungkan.mockReturnValue(new Promise<void>((_, reject) => (tolak = reject)))
    const wrapper = mount(ArsipHubungkanDialog, { props: { open: true, nip: NIP, item: berkas(), jenisHubungkan: JABATAN }, attachTo: document.body })
    await flushPromises()
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '9')
    await pilih('[data-testid="arsip-hubungkan-entri"]', '456')
    q<HTMLElement>('[data-testid="arsip-hubungkan-simpan"]')?.click()
    await flushPromises()
    expect(q<HTMLSelectElement>('[data-testid="arsip-hubungkan-jenis"] select')?.disabled).toBe(true)
    expect(q<HTMLSelectElement>('[data-testid="arsip-hubungkan-entri"] select')?.disabled).toBe(true)
    tolak(galat(404, 'Data riwayat tidak ditemukan.'))
    await flushPromises()
    expect(wrapper.emitted('basi')).toHaveLength(1)
  })

  it('404 tujuan → opsi dimuat ulang, entri dikosongkan, pesan 404 dipertahankan, tombol disabled', async () => {
    opsi.mockResolvedValue({ id_riwayat: 9, butuh_data: true, items: [{ id_entri: '456', ringkasan_riwayat: 'Analis' }] })
    hubungkan.mockRejectedValue(galat(404, 'Tujuan sudah tidak ada.'))
    const wrapper = mount(ArsipHubungkanDialog, { props: { open: true, nip: NIP, item: berkas(), jenisHubungkan: JABATAN }, attachTo: document.body })
    await flushPromises()
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '9')
    await pilih('[data-testid="arsip-hubungkan-entri"]', '456')
    q<HTMLElement>('[data-testid="arsip-hubungkan-simpan"]')?.click()
    await flushPromises()
    expect(opsi).toHaveBeenCalledTimes(2)
    expect(q('[data-testid="arsip-hubungkan-galat"]')?.textContent).toContain('Tujuan sudah tidak ada.')
    expect(q<HTMLSelectElement>('[data-testid="arsip-hubungkan-entri"] select')?.value).toBe('')
    expect(q<HTMLButtonElement>('[data-testid="arsip-hubungkan-simpan"]')?.disabled).toBe(true)
    expect(wrapper.emitted('basi')).toHaveLength(1)
  })

  it('opsi-hubungkan 422 errors.id_riwayat → pesan server tampil', async () => {
    opsi.mockRejectedValue(galat(422, 'x', { id_riwayat: ['Jenis riwayat tidak dapat dihubungkan.'] }))
    mount(ArsipHubungkanDialog, { props: { open: true, nip: NIP, item: berkas(), jenisHubungkan: JABATAN }, attachTo: document.body })
    await flushPromises()
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '9')
    expect(q('[data-testid="arsip-hubungkan-galat"]')?.textContent).toContain('Jenis riwayat tidak dapat dihubungkan.')
  })

  it('jenisHubungkan null (folder gagal dimuat) → galat muat + Coba lagi memancarkan muatUlang', async () => {
    const wrapper = mount(ArsipHubungkanDialog, { props: { open: true, nip: NIP, item: berkas(), jenisHubungkan: null }, attachTo: document.body })
    await flushPromises()
    expect(q('[data-testid="arsip-hubungkan-tanpa-jenis"]')).toBeNull()
    expect(q('[data-testid="arsip-hubungkan-gagal-muat"]')?.textContent).toContain('belum dapat dimuat')
    q<HTMLElement>('[data-testid="arsip-hubungkan-coba-lagi"]')?.click()
    await flushPromises()
    expect(wrapper.emitted('muatUlang')).toHaveLength(1)
  })

  it('jenisHubungkan kosong → pesan penjelasan, bukan dialog buntu', async () => {
    mount(ArsipHubungkanDialog, { props: { open: true, nip: NIP, item: berkas(), jenisHubungkan: [] }, attachTo: document.body })
    await flushPromises()
    expect(q('[data-testid="arsip-hubungkan-tanpa-jenis"]')?.textContent).toContain('Tidak ada jenis riwayat tujuan')
  })
})
