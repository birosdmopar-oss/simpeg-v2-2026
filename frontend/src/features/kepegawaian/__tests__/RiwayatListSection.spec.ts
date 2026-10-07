/**
 * RiwayatListSection / RiwayatTabHost — tab riwayat engine dari API dengan hak aksi HANYA dari descriptor:
 * tombol tambah (can_create), menu ⋮ Edit → Setujui/Tolak (can_process, baris Menunggu) → Hapus (can_delete),
 * proses lewat ApprovalDialog, galat 422 (errors.<kolom>, errors["berkas.<id>"]) di form, lampiran wajib saat tambah,
 * dan keadaan galat 403/501. Tab non-engine (lkh) → "belum tersedia".
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../riwayat/riwayat.service', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../riwayat/riwayat.service')>()
  return {
    ...actual,
    riwayatService: { list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(), process: vi.fn() },
  }
})
vi.mock('@/shared/services/masterOptions', () => ({
  masterOptions: vi.fn().mockResolvedValue([{ id: '7', nama: 'S1', parent: null }]),
}))

import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import pendidikan from '../riwayat/jenis/pendidikan'
import lkh from '../riwayat/jenis/lkh'
import RiwayatTabHost from '../riwayat/RiwayatTabHost.vue'
import { riwayatService } from '../riwayat/riwayat.service'
import type { RiwayatTabDescriptor } from '../types'

import { apiError, descriptor, NIP, pendidikanRow } from './fixtures'

const svc = vi.mocked(riwayatService)

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  svc.list.mockResolvedValue([pendidikanRow(1, { status: 1 }), pendidikanRow(2, { status: 0 }), pendidikanRow(3, { status: 2, reason_note: 'Ijazah buram' })])
})

async function mountTab(desc: Partial<RiwayatTabDescriptor> = {}, config = pendidikan) {
  const wrapper = mount(RiwayatTabHost, {
    props: { nip: NIP, descriptor: descriptor(config.jenis, config.title, desc), config },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

async function setInput(selector: string, value: string): Promise<void> {
  const el = q<HTMLInputElement | HTMLSelectElement>(selector)
  if (!el) throw new Error(`isian ${selector} tidak ada`)
  el.value = value
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
  await flushPromises()
}

async function chooseFile(idRiwayat: number, file: File): Promise<void> {
  const input = q<HTMLInputElement>(`[data-testid="riwayat-berkas-${idRiwayat}"] input[type="file"]`)
  if (!input) throw new Error(`isian berkas ${idRiwayat} tidak ada`)
  Object.defineProperty(input, 'files', { value: [file], configurable: true })
  input.dispatchEvent(new Event('change'))
  await flushPromises()
}

async function submitForm(): Promise<void> {
  q('[data-testid="riwayat-form"]')?.dispatchEvent(new Event('submit'))
  await flushPromises()
  await new Promise((r) => setTimeout(r, 0))
  await flushPromises()
}

describe('RiwayatListSection — data & tampilan', () => {
  it('memuat GET riwayat/{jenis}: kolom konfigurasi + Status + Aksi; badge status; alasan tolak tampil', async () => {
    const wrapper = await mountTab()
    expect(svc.list).toHaveBeenCalledWith(NIP, 'pendidikan')
    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Jenjang', 'Jurusan', 'Institusi', 'Tanggal Lulus', 'Status', 'Aksi'])
    expect(wrapper.get('[data-testid="riwayat-row-1"]').text()).toContain('30 Agustus 2012')
    expect(wrapper.get('[data-testid="riwayat-row-1"] [data-testid="status-badge"]').text()).toBe('Disetujui')
    expect(wrapper.get('[data-testid="riwayat-row-2"] [data-testid="status-badge"]').text()).toBe('Menunggu')
    expect(wrapper.get('[data-testid="riwayat-row-3"]').text()).toContain('Ijazah buram')
  })

  it('501 (stub) → keterangan "belum tersedia", bukan tabel kosong', async () => {
    svc.list.mockRejectedValue(apiError(501))
    const wrapper = await mountTab()
    expect(wrapper.get('[data-testid="riwayat-failure"]').text()).toBe('Fitur ini belum tersedia di server.')
    expect(wrapper.find('[data-testid="riwayat-table"]').exists()).toBe(false)
  })

  it('403 → pesan tidak berhak', async () => {
    svc.list.mockRejectedValue(apiError(403))
    const wrapper = await mountTab()
    expect(wrapper.get('[data-testid="riwayat-failure"]').text()).toBe('Anda tidak berhak mengakses data ini.')
  })

  it('tab non-engine (lkh) → "belum tersedia" tanpa memanggil API riwayat', async () => {
    const wrapper = await mountTab({}, lkh)
    expect(wrapper.get('[data-testid="riwayat-belum-tersedia"]').text()).toContain('Laporan Kerja Harian')
    expect(svc.list).not.toHaveBeenCalled()
  })
})

describe('RiwayatListSection — hak dari descriptor & menu ⋮', () => {
  it('semua hak: menu ⋮ Edit → Setujui → Tolak → Hapus pada baris Menunggu; label aksesibel memuat nama baris', async () => {
    const wrapper = await mountTab({ can_process: true })
    expect(wrapper.find('[data-testid="riwayat-add"]').exists()).toBe(true)
    const items = await rowMenuActions(wrapper, 'riwayat-actions-2')
    expect(items.map((i) => i.label)).toEqual(['Edit', 'Setujui', 'Tolak', 'Hapus'])
    expect(items.at(-1)?.danger).toBe(true)
    expect(wrapper.get('[data-testid="riwayat-actions-2"]').attributes('aria-label')).toBe('Aksi untuk S1')
  })

  it('baris Disetujui: Setujui/Tolak tidak tampil', async () => {
    const wrapper = await mountTab({ can_process: true })
    expect((await rowMenuActions(wrapper, 'riwayat-actions-1')).map((i) => i.key)).toEqual(['edit', 'hapus'])
  })

  it('tanpa hak apa pun: tanpa tombol tambah dan tanpa kolom Aksi', async () => {
    const wrapper = await mountTab({ can_create: false, can_edit: false, can_delete: false, can_process: false })
    expect(wrapper.find('[data-testid="riwayat-add"]').exists()).toBe(false)
    expect(wrapper.findAll('thead th').map((th) => th.text())).not.toContain('Aksi')
    expect(wrapper.find('[data-testid="riwayat-row-1"]').exists()).toBe(true)
  })

  it('hanya can_process: menu berisi Setujui/Tolak saja', async () => {
    const wrapper = await mountTab({ can_create: false, can_edit: false, can_delete: false, can_process: true })
    expect((await rowMenuActions(wrapper, 'riwayat-actions-2')).map((i) => i.key)).toEqual(['setujui', 'tolak'])
  })
})

describe('RiwayatListSection — proses (Setujui/Tolak)', () => {
  it('Tolak: alasan wajib; terisi → POST process { aksi: tolak, reason_note } lalu muat ulang', async () => {
    svc.process.mockResolvedValue({})
    const wrapper = await mountTab({ can_process: true })
    await selectRowAction(wrapper, 'riwayat-actions-2', 'tolak')
    expect(q('[data-testid="approval-dialog"]')?.getAttribute('data-mode')).toBe('tolak')

    q('[data-testid="approval-confirm"]')?.click()
    await flushPromises()
    expect(svc.process).not.toHaveBeenCalled()

    const textarea = q<HTMLTextAreaElement>('[data-testid="approval-reason"]')!
    textarea.value = 'Ijazah tidak terbaca'
    textarea.dispatchEvent(new Event('input'))
    q('[data-testid="approval-confirm"]')?.click()
    await flushPromises()

    expect(svc.process).toHaveBeenCalledWith(NIP, 'pendidikan', '2', { aksi: 'tolak', reason_note: 'Ijazah tidak terbaca' })
    expect(svc.list).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-testid="riwayat-notice"]').text()).toContain('ditolak')
  })

  it('galat server saat proses (422 reason_note) tampil di dialog', async () => {
    svc.process.mockRejectedValue(apiError(422, { reason_note: ['Alasan wajib diisi saat menolak.'] }))
    const wrapper = await mountTab({ can_process: true })
    await selectRowAction(wrapper, 'riwayat-actions-2', 'setujui')
    q('[data-testid="approval-confirm"]')?.click()
    await flushPromises()
    expect(q('[data-testid="approval-error"]')?.textContent).toContain('Alasan wajib diisi saat menolak.')
  })
})

describe('RiwayatListSection — tambah/ubah/hapus', () => {
  const pdf = new File(['%PDF'], 'ijazah.pdf', { type: 'application/pdf' })

  it('tambah tanpa ijazah (lampiran wajib 14) → pesan, tidak memanggil API', async () => {
    const wrapper = await mountTab()
    await wrapper.get('[data-testid="riwayat-add"]').trigger('click')
    await flushPromises()
    await setInput('[data-field="id_jenjang_pendidikan"] select, select[data-field="id_jenjang_pendidikan"]', '7')
    await setInput('input[data-field="tgl_lulus"], [data-field="tgl_lulus"] input', '2012-08-30')
    await submitForm()
    // Validasi VeeValidate/Zod asinkron: tunggu hasilnya, bukan jumlah tick tetap (runner CI lebih lambat).
    await vi.waitFor(() => expect(q('[data-testid="riwayat-berkas-error-14"]')?.textContent).toContain('Ijazah wajib diunggah.'))
    expect(svc.create).not.toHaveBeenCalled()
  })

  it('tambah lengkap → create(nip, jenis, fields, values, { 14: File }); galat 422 dipetakan ke kolom & berkas', async () => {
    svc.create.mockRejectedValueOnce(apiError(422, { tgl_lulus: ['Tanggal lulus wajib diisi.'], 'berkas.14': ['Berkas harus pdf.'] }))
    const wrapper = await mountTab()
    await wrapper.get('[data-testid="riwayat-add"]').trigger('click')
    await flushPromises()
    await setInput('[data-field="id_jenjang_pendidikan"] select, select[data-field="id_jenjang_pendidikan"]', '7')
    await setInput('input[data-field="tgl_lulus"], [data-field="tgl_lulus"] input', '2012-08-30')
    await chooseFile(14, pdf)
    await submitForm()

    await vi.waitFor(() => expect(svc.create).toHaveBeenCalledTimes(1))
    await flushPromises()
    const [nip, jenis, fields, values, berkas] = svc.create.mock.calls[0]
    expect([nip, jenis, fields]).toEqual([NIP, 'pendidikan', pendidikan.fields])
    expect(values).toMatchObject({ id_jenjang_pendidikan: '7', tgl_lulus: '2012-08-30' })
    expect(berkas).toEqual({ 14: pdf })
    expect(q('[data-testid="riwayat-dialog"]')?.textContent).toContain('Tanggal lulus wajib diisi.')
    expect(q('[data-testid="riwayat-berkas-error-14"]')?.textContent).toContain('Berkas harus pdf.')
  })

  it('edit: dialog terisi nilai baris (kolom DDL); simpan → update(…, id, …); berkas tidak wajib saat ubah', async () => {
    svc.update.mockResolvedValue({})
    const wrapper = await mountTab()
    await selectRowAction(wrapper, 'riwayat-actions-1', 'edit')
    expect(q<HTMLInputElement>('input[data-field="no_ijazah"], [data-field="no_ijazah"] input')?.value).toBe('IJZ-1')
    await submitForm()
    await vi.waitFor(() => expect(svc.update).toHaveBeenCalled())
    await flushPromises()
    expect(svc.update).toHaveBeenCalledWith(NIP, 'pendidikan', '1', pendidikan.fields, expect.objectContaining({ no_ijazah: 'IJZ-1' }), {})
    expect(wrapper.get('[data-testid="riwayat-notice"]').text()).toContain('tersimpan')
  })

  it('hapus: konfirmasi → DELETE; galat 403 tampil sebagai pesan', async () => {
    svc.remove.mockRejectedValue(apiError(403))
    const wrapper = await mountTab()
    await selectRowAction(wrapper, 'riwayat-actions-3', 'hapus')
    const confirm = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')
    confirm?.click()
    await flushPromises()
    expect(svc.remove).toHaveBeenCalledWith(NIP, 'pendidikan', '3')
    expect(wrapper.get('[data-testid="riwayat-notice"]').text()).toBe('Anda tidak berhak mengakses data ini.')
  })
})
