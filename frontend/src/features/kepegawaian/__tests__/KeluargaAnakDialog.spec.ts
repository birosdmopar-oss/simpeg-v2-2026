/**
 * KeluargaAnakDialog (B-16, MAKE-007): daftar anak dari sub-resource, tambah wajib akte (kode 3), ubah tanpa akte bila
 * akte sudah ada, galat 422 errors.berkas di isian akte, hapus lewat ConfirmDialog, menu ⋮ Edit → Akte → Hapus, dan hak
 * dari pemanggil (bolehUbah/bolehHapus).
 */
import { DOMWrapper, enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../riwayat/anak.service', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../riwayat/anak.service')>()
  return { ...actual, anakService: { list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() } }
})
vi.mock('../services/lampiran.service', () => ({ lampiranService: { list: vi.fn(), upload: vi.fn(), download: vi.fn(), remove: vi.fn() } }))

import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import { anakService } from '../riwayat/anak.service'
import KeluargaAnakDialog from '../riwayat/KeluargaAnakDialog.vue'
import { lampiranService } from '../services/lampiran.service'

import { apiError, NIP } from './fixtures'

const svc = vi.mocked(anakService)
const lampiran = vi.mocked(lampiranService)

const anak = (id: number, nama: string) => ({ id_detail_anak: id, id_riwayat_keluarga: 5, urutan: id, nama, tempat_lahir: 'Jakarta', tgl_lahir: '2016-01-01', keterangan: null })

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  for (const fn of Object.values(lampiran)) fn.mockReset()
  svc.list.mockResolvedValue([anak(1, 'Anak Satu'), anak(2, 'Anak Dua')])
  lampiran.list.mockResolvedValue([])
})

async function mountDialog(hak: { bolehUbah?: boolean; bolehHapus?: boolean } = {}) {
  const wrapper = mount(KeluargaAnakDialog, {
    props: { open: true, nip: NIP, idKeluarga: '5', subject: 'Pasangan', bolehUbah: hak.bolehUbah ?? true, bolehHapus: hak.bolehHapus ?? true },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

const body = () => new DOMWrapper(document.body)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

async function setInput(name: string, value: string): Promise<void> {
  const el = q<HTMLInputElement>(`[data-field="${name}"] input, input[data-field="${name}"]`)
  if (!el) throw new Error(`isian ${name} tidak ada`)
  el.value = value
  el.dispatchEvent(new Event('input'))
  await flushPromises()
}

async function submit(): Promise<void> {
  q('[data-testid="anak-form"]')?.dispatchEvent(new Event('submit'))
  await flushPromises()
  await new Promise((r) => setTimeout(r, 0))
  await flushPromises()
}

describe('KeluargaAnakDialog', () => {
  it('memuat GET …/keluarga/{id}/anak; menu ⋮ Edit → Akte → Hapus dengan label & testid', async () => {
    await mountDialog()
    expect(svc.list).toHaveBeenCalledWith(NIP, '5')
    expect(q('[data-testid="anak-row-1"]')?.textContent).toContain('Anak Satu')
    const items = await rowMenuActions(body(), 'anak-actions-1')
    expect(items.map((i) => i.label)).toEqual(['Edit', 'Akte', 'Hapus'])
    expect(q('[data-testid="anak-actions-1"]')?.getAttribute('aria-label')).toBe('Aksi untuk Anak Satu')
  })

  it('tanpa hak ubah/hapus: tanpa Tambah, menu hanya Akte', async () => {
    await mountDialog({ bolehUbah: false, bolehHapus: false })
    expect(q('[data-testid="anak-add"]')).toBeNull()
    expect((await rowMenuActions(body(), 'anak-actions-1')).map((i) => i.key)).toEqual(['akte'])
  })

  it('tambah tanpa akte → pesan wajib, tidak memanggil API; 422 errors.berkas tampil di isian akte', async () => {
    await mountDialog()
    q<HTMLButtonElement>('[data-testid="anak-add"]')?.click()
    await flushPromises()
    for (const [n, v] of [['urutan', '3'], ['nama', 'Anak Tiga'], ['tempat_lahir', 'Bogor'], ['tgl_lahir', '2020-02-02']]) await setInput(n as string, v as string)
    await submit()
    await vi.waitFor(() => expect(q('[data-testid="anak-berkas-error-3"]')?.textContent).toContain('Akte Lahir wajib diunggah.'))
    expect(svc.create).not.toHaveBeenCalled()

    const akte = new File(['%PDF'], 'akte.pdf', { type: 'application/pdf' })
    const input = q<HTMLInputElement>('[data-testid="anak-berkas-3"] input[type="file"]')!
    Object.defineProperty(input, 'files', { value: [akte], configurable: true })
    input.dispatchEvent(new Event('change'))
    svc.create.mockRejectedValueOnce(apiError(422, { berkas: ['Jenis berkas tidak diizinkan.'] }))
    await submit()
    await vi.waitFor(() => expect(svc.create).toHaveBeenCalledTimes(1))
    expect(svc.create.mock.calls[0]?.[4]).toBe(akte)
    await vi.waitFor(() => expect(q('[data-testid="anak-berkas-error-3"]')?.textContent).toContain('Jenis berkas tidak diizinkan.'))
  })

  it('edit anak yang sudah punya akte: akte tidak wajib; update tanpa berkas', async () => {
    lampiran.list.mockResolvedValue([{ id_attachment: 9 } as never])
    svc.update.mockResolvedValue({})
    await mountDialog()
    await selectRowAction(body(), 'anak-actions-2', 'edit')
    await flushPromises()
    expect(lampiran.list).toHaveBeenCalledWith(NIP, { id_riwayat: 3, id_entri: '2' })
    await setInput('nama', 'Anak Dua Baru')
    await submit()
    await vi.waitFor(() => expect(svc.update).toHaveBeenCalledTimes(1))
    expect(svc.update.mock.calls[0]?.slice(0, 3)).toEqual([NIP, '5', '2'])
    expect(svc.update.mock.calls[0]?.[5]).toBeNull()
  })

  it('hapus: konfirmasi → DELETE lalu muat ulang', async () => {
    svc.remove.mockResolvedValue()
    const wrapper = await mountDialog()
    await selectRowAction(body(), 'anak-actions-1', 'hapus')
    ;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()
    expect(svc.remove).toHaveBeenCalledWith(NIP, '5', '1')
    expect(svc.list).toHaveBeenCalledTimes(2)
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })
})
