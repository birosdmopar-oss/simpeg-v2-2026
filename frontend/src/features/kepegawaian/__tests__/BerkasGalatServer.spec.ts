/**
 * Q4R3-I1 (CR-056): setelah galat 422 server karena berkas, memilih berkas pengganti menghapus pesan galat server lama
 * untuk kolom/kode itu (RiwayatFieldForm: lampiran `berkas.<id>` dan kolom `berkas.<kolom>`; UsulanFormDialog: kolom
 * `berkas.<kolom>`). Galat server baru (kiriman berikutnya) tampil lagi, dan kolom lain tidak terpengaruh.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/master-data/services/master.service', () => ({
  masterService: { options: vi.fn() },
}))
vi.mock('@/lib/axios', () => ({ api: { get: vi.fn() } }))

import RiwayatFieldForm from '../riwayat/RiwayatFieldForm.vue'
import UsulanFormDialog from '../riwayat/UsulanFormDialog.vue'
import type { UsulanConfig } from '../riwayat/usulan.config'
import { T, type BerkasKolomDef, type LampiranDef } from '../riwayat/riwayat.config'
import type { RiwayatValidationErrors } from '../services/apiErrors'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

const q = <E extends Element = HTMLElement>(sel: string) => document.body.querySelector<E>(sel)
const pdf = (nama: string) => new File(['%PDF'], nama, { type: 'application/pdf' })

async function pilihBerkas(container: string, file: File): Promise<void> {
  const input = q<HTMLInputElement>(`${container} input[type="file"]`)
  if (!input) throw new Error(`isian berkas ${container} tidak ada`)
  Object.defineProperty(input, 'files', { value: [file], configurable: true })
  input.dispatchEvent(new Event('change'))
  await flushPromises()
}

const lampiran: LampiranDef[] = [
  { id_riwayat: 14, label: 'Ijazah', wajib: true, batas_mb: 5, ekstensi: ['pdf'] },
  { id_riwayat: 15, label: 'Transkrip', wajib: false, batas_mb: 5, ekstensi: ['pdf'] },
]
const berkasKolom: BerkasKolomDef[] = [
  { kolom: 'file_1', label: 'SK CPNS', wajib: false },
  { kolom: 'file_2', label: 'Foto', wajib: false },
]
const galat = (extra: Partial<RiwayatValidationErrors> = {}): RiwayatValidationErrors => ({
  fields: { 'berkas.file_1': 'Berkas bukan PDF yang valid.', 'berkas.file_2': 'Berkas terlalu besar.' },
  berkas: { 14: 'Isi berkas tidak sesuai ekstensi.', 15: 'Berkas rusak.' },
  general: [],
  ...extra,
})

async function mountForm(serverErrors: RiwayatValidationErrors | null) {
  const wrapper = mount(RiwayatFieldForm, {
    props: { fields: [T('nama', 'Nama')], lampiran, berkasKolom, serverErrors },
    attachTo: document.body,
  })
  await flushPromises()
  await flushPromises()
  return wrapper
}

describe('RiwayatFieldForm — galat server berkas hilang saat berkas diganti (Q4R3-I1)', () => {
  it('lampiran: memilih berkas pengganti menghapus galat kodenya saja', async () => {
    await mountForm(galat())
    expect(q('[data-testid="riwayat-berkas-error-14"]')?.textContent).toContain('Isi berkas tidak sesuai ekstensi.')
    expect(q('[data-testid="riwayat-berkas-error-15"]')?.textContent).toContain('Berkas rusak.')

    await pilihBerkas('[data-testid="riwayat-berkas-14"]', pdf('ijazah-baru.pdf'))

    expect(q('[data-testid="riwayat-berkas-error-14"]')).toBeNull()
    expect(q('[data-testid="riwayat-berkas-error-15"]')?.textContent).toContain('Berkas rusak.')
  })

  it('kolom berkas: memilih berkas pengganti menghapus galat kolomnya saja', async () => {
    await mountForm(galat())
    expect(q('[data-testid="riwayat-berkas-error-file_1"]')?.textContent).toContain('Berkas bukan PDF yang valid.')

    await pilihBerkas('[data-testid="riwayat-berkas-file_1"]', pdf('sk.pdf'))

    expect(q('[data-testid="riwayat-berkas-error-file_1"]')).toBeNull()
    expect(q('[data-testid="riwayat-berkas-error-file_2"]')?.textContent).toContain('Berkas terlalu besar.')
  })

  it('galat server baru untuk kode yang sama tampil lagi setelah kiriman berikutnya', async () => {
    const wrapper = await mountForm(galat())
    await pilihBerkas('[data-testid="riwayat-berkas-14"]', pdf('ijazah-baru.pdf'))
    await pilihBerkas('[data-testid="riwayat-berkas-file_1"]', pdf('sk.pdf'))
    expect(q('[data-testid="riwayat-berkas-error-14"]')).toBeNull()
    expect(q('[data-testid="riwayat-berkas-error-file_1"]')).toBeNull()

    await wrapper.setProps({ serverErrors: galat({ berkas: { 14: 'Berkas pengganti juga ditolak.' }, fields: { 'berkas.file_1': 'Berkas pengganti ditolak.' } }) })
    await flushPromises()

    expect(q('[data-testid="riwayat-berkas-error-14"]')?.textContent).toContain('Berkas pengganti juga ditolak.')
    expect(q('[data-testid="riwayat-berkas-error-file_1"]')?.textContent).toContain('Berkas pengganti ditolak.')
  })
})

describe('UsulanFormDialog — galat server berkas hilang saat berkas diganti (Q4R3-I1)', () => {
  const config: UsulanConfig = {
    jenis: 'karpeg',
    title: 'Uji',
    singular: 'usulan uji',
    titleMenurutJenisKelamin: null,
    fields: [],
    berkas: [
      { kolom: 'file_1', label: 'SK CPNS', wajib: false },
      { kolom: 'file_2', label: 'Foto', wajib: false },
    ],
    batas_mb: 5,
    ekstensi: ['pdf'],
  }

  it('memilih berkas pengganti menghapus galat kolomnya; galat baru tampil lagi', async () => {
    const wrapper = mount(UsulanFormDialog, {
      props: {
        open: true,
        config,
        row: null,
        serverErrors: { fields: { 'berkas.file_1': 'Berkas bukan PDF yang valid.', 'berkas.file_2': 'Berkas terlalu besar.' }, umum: [] },
      },
      attachTo: document.body,
    })
    await flushPromises()
    expect(q('[data-testid="usulan-berkas-error-file_1"]')?.textContent).toContain('Berkas bukan PDF yang valid.')

    await pilihBerkas('[data-testid="usulan-berkas-file_1"]', pdf('sk.pdf'))

    expect(q('[data-testid="usulan-berkas-error-file_1"]')).toBeNull()
    expect(q('[data-testid="usulan-berkas-error-file_2"]')?.textContent).toContain('Berkas terlalu besar.')

    await wrapper.setProps({ serverErrors: { fields: { 'berkas.file_1': 'Berkas pengganti ditolak.' }, umum: [] } })
    await flushPromises()
    expect(q('[data-testid="usulan-berkas-error-file_1"]')?.textContent).toContain('Berkas pengganti ditolak.')
  })
})
