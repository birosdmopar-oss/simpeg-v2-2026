/**
 * ArsipTable (B-18, MAKE-009) — daftar lampiran satu baris riwayat dari `GET pegawai/{nip}/lampiran`, unggah (cek awal
 * jenis/ukuran dari aturan, galat 422 `errors.berkas` tampil), menu ⋮ Unduh → Hapus (Hapus hanya bila boleh, lewat
 * ConfirmDialog), dan keadaan galat 403.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/lampiran.service', () => ({
  lampiranService: { list: vi.fn(), upload: vi.fn(), download: vi.fn(), remove: vi.fn() },
}))

import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import ArsipTable from '../components/ArsipTable.vue'
import { lampiranService } from '../services/lampiran.service'
import type { AturanLampiran, DocumentAttachment } from '../types'

import { apiError, NIP } from './fixtures'

const svc = vi.mocked(lampiranService)
const TARGET = { id_riwayat: 14, id_entri: 7 }
const ATURAN: AturanLampiran = { id_riwayat: 14, wajib: true, batas_mb: 1, ekstensi: ['pdf'] }

function lampiran(id: number, overrides: Partial<DocumentAttachment> = {}): DocumentAttachment {
  return {
    id_attachment: id,
    NIP,
    document_id: null,
    filename: `${id}.pdf`,
    id_riwayat: 14,
    nama_riwayat: 'Pendidikan',
    id_entri: '7',
    tag: null,
    url: null,
    basename: `${id}.pdf`,
    display_name: `Ijazah ${id}.pdf`,
    file_size: 2048,
    file_ext: 'pdf',
    file_type: 'application/pdf',
    created_at: '2026-10-08 03:15:00',
    updated_at: null,
    ...overrides,
  }
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  svc.list.mockResolvedValue([lampiran(1), lampiran(2, { file_size: 3 * 1024 * 1024 })])
})

async function mountTable(props: Partial<InstanceType<typeof ArsipTable>['$props']> = {}) {
  const wrapper = mount(ArsipTable, {
    props: { nip: NIP, target: TARGET, aturan: ATURAN, canUpload: true, canDelete: true, title: 'Arsip Ijazah', ...props },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

async function pilihBerkas(wrapper: Awaited<ReturnType<typeof mountTable>>, file: File): Promise<void> {
  const input = wrapper.get<HTMLInputElement>('[data-testid="arsip-input"]').element
  Object.defineProperty(input, 'files', { value: [file], configurable: true })
  input.dispatchEvent(new Event('change'))
  await flushPromises()
}

describe('ArsipTable — daftar', () => {
  it('memuat lampiran baris riwayat; nama, ukuran, petunjuk aturan tampil', async () => {
    const wrapper = await mountTable()
    expect(svc.list).toHaveBeenCalledWith(NIP, TARGET)
    expect(wrapper.text()).toContain('Jenis PDF, maksimal 1 MB.')
    expect(wrapper.get('[data-testid="arsip-row-1"]').text()).toContain('Ijazah 1.pdf')
    expect(wrapper.get('[data-testid="arsip-row-1"]').text()).toContain('2,0 KB')
    expect(wrapper.get('[data-testid="arsip-row-2"]').text()).toContain('3,0 MB')
  })

  it('kosong → keterangan belum ada berkas', async () => {
    svc.list.mockResolvedValue([])
    const wrapper = await mountTable()
    expect(wrapper.get('[data-testid="arsip-empty"]').text()).toContain('Belum ada berkas')
  })

  it('403 → pesan tidak berhak, tanpa tabel', async () => {
    svc.list.mockRejectedValue(apiError(403))
    const wrapper = await mountTable()
    expect(wrapper.get('[data-testid="arsip-failure"]').text()).toBe('Anda tidak berhak mengakses data ini.')
    expect(wrapper.find('[data-testid="arsip-table"]').exists()).toBe(false)
  })
})

describe('ArsipTable — menu ⋮', () => {
  it('Unduh → Hapus; label aksesibel memuat nama berkas', async () => {
    const wrapper = await mountTable()
    const items = await rowMenuActions(wrapper, 'lampiran-actions-1')
    expect(items.map((i) => i.label)).toEqual(['Unduh', 'Hapus'])
    expect(items[1]?.danger).toBe(true)
    expect(wrapper.get('[data-testid="lampiran-actions-1"]').attributes('aria-label')).toBe('Aksi untuk Ijazah 1.pdf')
  })

  it('tanpa hak hapus: hanya Unduh; tanpa hak unggah: tombol unggah tidak ada', async () => {
    const wrapper = await mountTable({ canDelete: false, canUpload: false })
    const items = await rowMenuActions(wrapper, 'lampiran-actions-1')
    expect(items.map((i) => i.label)).toEqual(['Unduh'])
    expect(wrapper.find('[data-testid="arsip-upload"]').exists()).toBe(false)
  })

  it('Unduh memanggil endpoint unduh dengan id lampiran', async () => {
    svc.download.mockResolvedValue(new Blob(['%PDF'], { type: 'application/pdf' }))
    URL.createObjectURL = vi.fn(() => 'blob:uji')
    URL.revokeObjectURL = vi.fn()
    const klik = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
    const wrapper = await mountTable()
    await selectRowAction(wrapper, 'lampiran-actions-2', 'unduh')
    await flushPromises()
    expect(svc.download).toHaveBeenCalledWith(NIP, 2)
    expect(URL.createObjectURL).toHaveBeenCalled()
    expect(klik).toHaveBeenCalledOnce()
    klik.mockRestore()
  })

  it('Hapus lewat ConfirmDialog lalu memuat ulang daftar', async () => {
    svc.remove.mockResolvedValue()
    const wrapper = await mountTable()
    await selectRowAction(wrapper, 'lampiran-actions-1', 'hapus')
    expect(svc.remove).not.toHaveBeenCalled()
    svc.list.mockResolvedValue([lampiran(2)])
    ;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()
    expect(svc.remove).toHaveBeenCalledWith(NIP, 1)
    expect(wrapper.get('[data-testid="arsip-notice"]').text()).toContain('Ijazah 1.pdf dihapus.')
    expect(wrapper.emitted('changed')?.[0]?.[0]).toHaveLength(1)
  })
})

describe('ArsipTable — unggah', () => {
  it('berkas valid diunggah ke target lalu daftar dimuat ulang', async () => {
    svc.upload.mockResolvedValue(lampiran(3))
    const wrapper = await mountTable()
    const file = new File(['%PDF-1.4'], 'ijazah.pdf', { type: 'application/pdf' })
    await pilihBerkas(wrapper, file)
    expect(svc.upload).toHaveBeenCalledWith(NIP, TARGET, file)
    expect(svc.list).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-testid="arsip-notice"]').text()).toContain('ijazah.pdf diunggah.')
  })

  it('jenis atau ukuran di luar aturan ditolak sebelum dikirim', async () => {
    const wrapper = await mountTable()
    await pilihBerkas(wrapper, new File(['x'], 'foto.png', { type: 'image/png' }))
    expect(wrapper.get('[data-testid="arsip-notice"]').text()).toContain('Jenis file .png tidak diperbolehkan.')
    await pilihBerkas(wrapper, new File([new Uint8Array(1024 * 1024 + 1)], 'besar.pdf', { type: 'application/pdf' }))
    expect(wrapper.get('[data-testid="arsip-notice"]').text()).toContain('Ukuran file melebihi 1 MB.')
    expect(svc.upload).not.toHaveBeenCalled()
  })

  it('422 errors.berkas dari server tampil apa adanya', async () => {
    svc.upload.mockRejectedValue(apiError(422, { berkas: ['Jenis berkas tidak diizinkan (hanya pdf).'] }))
    const wrapper = await mountTable()
    await pilihBerkas(wrapper, new File(['x'], 'palsu.pdf', { type: 'application/pdf' }))
    expect(wrapper.get('[data-testid="arsip-notice"]').text()).toContain('Jenis berkas tidak diizinkan (hanya pdf).')
  })
})
