/**
 * ArsipTable (B-18, MAKE-009) — daftar lampiran satu baris riwayat dari `GET pegawai/{nip}/lampiran`, unggah (cek awal
 * jenis/ukuran dari aturan, galat 422 `errors.berkas` tampil), menu ⋮ Unduh → Hapus (Hapus hanya bila boleh, lewat
 * ConfirmDialog; disabled untuk lampiran terakhir kode wajib dan untuk semua baris selama satu aksi berjalan; 422
 * `errors.berkas` tampil), dan keadaan galat 403.
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
    expect(wrapper.findAll('thead th').at(-1)?.classes()).toEqual(expect.arrayContaining(['sticky', 'right-0']))
    expect(wrapper.get('[data-testid="arsip-row-1"]').findAll('td').at(-1)?.classes()).toEqual(expect.arrayContaining(['sticky', 'right-0']))
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

  it('Unduh memanggil endpoint unduh dengan id lampiran; URL blob dicabut tertunda', async () => {
    svc.download.mockResolvedValue(new Blob(['%PDF'], { type: 'application/pdf' }))
    URL.createObjectURL = vi.fn(() => 'blob:uji')
    URL.revokeObjectURL = vi.fn()
    const klik = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
    const timer = vi.spyOn(globalThis, 'setTimeout')
    const wrapper = await mountTable()
    try {
      await selectRowAction(wrapper, 'lampiran-actions-2', 'unduh')
      await flushPromises()
      expect(svc.download).toHaveBeenCalledWith(NIP, 2)
      expect(URL.createObjectURL).toHaveBeenCalled()
      expect(klik).toHaveBeenCalledOnce()
      expect(URL.revokeObjectURL).not.toHaveBeenCalled()
      const tunda = timer.mock.calls.find(([, ms]) => ms === 1000)
      expect(tunda).toBeDefined()
      ;(tunda?.[0] as () => void)()
      expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:uji')
    } finally {
      timer.mockRestore()
      klik.mockRestore()
    }
  })

  it('lampiran terakhir kode wajib: Hapus disabled; kode tidak wajib atau masih ada pengganti: aktif', async () => {
    svc.list.mockResolvedValue([lampiran(1)])
    const wajib = await mountTable()
    expect((await rowMenuActions(wajib, 'lampiran-actions-1')).find((i) => i.key === 'hapus')?.disabled).toBe(true)
    wajib.unmount()

    const tidakWajib = await mountTable({ aturan: { ...ATURAN, wajib: false } })
    expect((await rowMenuActions(tidakWajib, 'lampiran-actions-1')).find((i) => i.key === 'hapus')?.disabled).toBe(false)
    tidakWajib.unmount()

    svc.list.mockResolvedValue([lampiran(1), lampiran(2)])
    const duaBerkas = await mountTable()
    expect((await rowMenuActions(duaBerkas, 'lampiran-actions-1')).find((i) => i.key === 'hapus')?.disabled).toBe(false)
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

  it('selama satu hapus berjalan, Hapus di SEMUA baris disabled', async () => {
    let selesai: () => void = () => undefined
    svc.remove.mockImplementation(() => new Promise<void>((resolve) => (selesai = resolve)))
    svc.list.mockResolvedValue([lampiran(1), lampiran(2), lampiran(3)])
    const wrapper = await mountTable()
    await selectRowAction(wrapper, 'lampiran-actions-1', 'hapus')
    ;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()
    expect(svc.remove).toHaveBeenCalledWith(NIP, 1)
    expect((await rowMenuActions(wrapper, 'lampiran-actions-2')).find((i) => i.key === 'hapus')?.disabled).toBe(true)
    expect((await rowMenuActions(wrapper, 'lampiran-actions-3')).find((i) => i.key === 'hapus')?.disabled).toBe(true)
    selesai()
    await flushPromises()
    expect((await rowMenuActions(wrapper, 'lampiran-actions-2')).find((i) => i.key === 'hapus')?.disabled).toBe(false)
  })

  it('hapus ditolak 422 → pesan errors.berkas dari server tampil, bukan pesan generik', async () => {
    svc.remove.mockRejectedValue(apiError(422, { berkas: ['Lampiran wajib tidak boleh dihapus semua.'] }))
    svc.list.mockResolvedValue([lampiran(1), lampiran(2)])
    const wrapper = await mountTable()
    await selectRowAction(wrapper, 'lampiran-actions-1', 'hapus')
    ;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()
    expect(wrapper.get('[data-testid="arsip-notice"]').text()).toContain('Lampiran wajib tidak boleh dihapus semua.')
  })
})

describe('ArsipTable — unggah', () => {
  it('berkas valid diunggah ke target lalu daftar dimuat ulang', async () => {
    svc.upload.mockResolvedValue(lampiran(3))
    const wrapper = await mountTable()
    const file = new File(['%PDF-1.4'], 'ijazah.pdf', { type: 'application/pdf' })
    await pilihBerkas(wrapper, file)
    expect(svc.upload).toHaveBeenCalledWith(NIP, TARGET, file, {})
    expect(svc.list).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-testid="arsip-notice"]').text()).toContain('ijazah.pdf diunggah.')
  })

  it('CR-049 D-F6: seret-lepas berkas ke area unggah → diunggah, pratinjau tampil selama unggah', async () => {
    let selesai: (v: unknown) => void = () => undefined
    svc.upload.mockImplementation(() => new Promise((ok) => (selesai = ok)) as never)
    const wrapper = await mountTable()
    const file = new File(['%PDF-1.4'], 'ijazah.pdf', { type: 'application/pdf' })
    await wrapper.get('[data-testid="arsip-dropzone"]').trigger('drop', { dataTransfer: { files: [file] } })
    expect(svc.upload).toHaveBeenCalledWith(NIP, TARGET, file, {})
    expect(wrapper.get('[data-testid="arsip-pratinjau"]').text()).toContain('Mengunggah ijazah.pdf')
    expect(wrapper.get('[data-testid="arsip-pratinjau-ikon"]').classes()).toContain('text-danger')
    selesai(lampiran(3))
    await flushPromises()
    expect(wrapper.find('[data-testid="arsip-pratinjau"]').exists()).toBe(false)

    // Berkas di luar aturan lewat seret ditolak dengan pesan yang sama.
    await wrapper.get('[data-testid="arsip-dropzone"]').trigger('drop', { dataTransfer: { files: [new File(['x'], 'foto.png')] } })
    expect(wrapper.get('[data-testid="arsip-notice"]').text()).toContain('Jenis file .png tidak diperbolehkan.')
    expect(svc.upload).toHaveBeenCalledTimes(1)
  })

  it('jenis atau ukuran di luar aturan ditolak sebelum dikirim', async () => {
    const wrapper = await mountTable()
    await pilihBerkas(wrapper, new File(['x'], 'foto.png', { type: 'image/png' }))
    expect(wrapper.get('[data-testid="arsip-notice"]').text()).toContain('Jenis file .png tidak diperbolehkan.')
    await pilihBerkas(wrapper, new File([new Uint8Array(1024 * 1024 + 1)], 'besar.pdf', { type: 'application/pdf' }))
    expect(wrapper.get('[data-testid="arsip-notice"]').text()).toContain('Ukuran file melebihi 1 MB.')
    // CR-055 Q4R2-I2: nama tanpa ekstensi → pesan jelas.
    await pilihBerkas(wrapper, new File(['%PDF'], 'sk', { type: 'application/pdf' }))
    expect(wrapper.get('[data-testid="arsip-notice"]').text()).toContain('Nama berkas tidak memiliki ekstensi.')
    expect(svc.upload).not.toHaveBeenCalled()
  })

  it('422 errors.berkas dari server tampil apa adanya', async () => {
    svc.upload.mockRejectedValue(apiError(422, { berkas: ['Jenis berkas tidak diizinkan (hanya pdf).'] }))
    const wrapper = await mountTable()
    await pilihBerkas(wrapper, new File(['x'], 'palsu.pdf', { type: 'application/pdf' }))
    expect(wrapper.get('[data-testid="arsip-notice"]').text()).toContain('Jenis berkas tidak diizinkan (hanya pdf).')
  })

  it('CR-054 (QA R2-I4): isi tidak sesuai ekstensi → pesan server tampil di tempat galat jenis/ukuran, baris tidak bertambah', async () => {
    svc.upload.mockRejectedValue(apiError(422, { berkas: ['Jenis isi berkas tidak sesuai dengan ekstensinya.'] }))
    const wrapper = await mountTable({ aturan: { ...ATURAN, ekstensi: ['pdf', 'xlsx'] } })
    await pilihBerkas(wrapper, new File(['%PDF-1.4'], 'rekap.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }))
    expect(svc.upload).toHaveBeenCalledTimes(1)
    const notice = wrapper.get('[data-testid="arsip-notice"]')
    expect(notice.text()).toContain('Jenis isi berkas tidak sesuai dengan ekstensinya.')
    expect(svc.list).toHaveBeenCalledTimes(1)
  })
})
