/**
 * PenugasanFormDialog + penugasan.service (B-07 Plt/Plh, MAKE-012; F6 review CR): submit edit → `update` dengan isian &
 * berkas `lampiran`; galat 422 `errors.lampiran` tampil di isian berkas; klien mengirim multipart (+ `_method=PUT`) dan
 * membaca nama berkas unduhan dari Content-Disposition (fallback ekstensi tipe blob).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})
vi.mock('@/features/master-data/services/master.service', () => ({
  masterService: { options: vi.fn().mockResolvedValue(['1', '2', '5'].map((id) => ({ id, nama: `Opsi ${id}`, parent: null }))) },
}))

import { api } from '@/lib/axios'
import { createShellRouter } from '@/shared/layouts/__tests__/shellTestUtils'

import PenugasanFormDialog from '../components/PenugasanFormDialog.vue'
import { penugasanService } from '../services/penugasan.service'
import type { PenugasanItem } from '../types'

import { apiError } from './fixtures'

const post = vi.mocked(api.post)
const get = vi.mocked(api.get)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const ROW = {
  id: 7,
  nip: '198001012005011001',
  nip_plt: '198001012005011001',
  nama_lengkap: 'Pegawai Uji',
  status: 1,
  jenis_jabatan: 1,
  jenis_jabatan_koord: null,
  tgl_mulai: '2026-01-01',
  tgl_akhir: '2026-12-31',
  id_group_jabatan: 1,
  id_sub_group_jabatan: 2,
  id_jabatan: 5,
  jabatan: 'Kepala Biro',
  id_jabatan_koord: null,
  jabatan_koord: null,
  id_unit: 1,
  unit: 'Unit',
  id_satker: 2,
  satker: 'Satker',
  id_group_jabatan_plt: 1,
  id_sub_group_jabatan_plt: 2,
  id_unit_plt: 1,
  id_satker_plt: 2,
  keterangan: null,
  ada_lampiran: false,
} as PenugasanItem

async function mountDialog() {
  const router = createShellRouter()
  const wrapper = mount(PenugasanFormDialog, { props: { open: true, jenis: 'plt', row: ROW }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

async function pilihLampiran(): Promise<void> {
  const input = q<HTMLInputElement>('[data-testid="penugasan-berkas-lampiran"] input[type="file"]')
  if (!input) throw new Error('isian lampiran tidak ada')
  Object.defineProperty(input, 'files', { value: [new File(['%PDF'], 'sk.pdf', { type: 'application/pdf' })], configurable: true })
  input.dispatchEvent(new Event('change'))
  await flushPromises()
}

async function submit(): Promise<void> {
  q('[data-testid="penugasan-form"]')?.dispatchEvent(new Event('submit'))
  await flushPromises()
  await new Promise((r) => setTimeout(r, 0))
  await flushPromises()
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  post.mockReset()
  get.mockReset()
})

describe('PenugasanFormDialog', () => {
  it('submit edit → POST multipart _method=PUT berisi isian + lampiran; emit saved', async () => {
    post.mockResolvedValue({ data: ROW })
    const wrapper = await mountDialog()
    await pilihLampiran()
    await submit()

    // Validasi VeeValidate asinkron: tunggu sampai kiriman terjadi.
    await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(1))
    const [url, body] = post.mock.calls[0]!
    expect(url).toBe('/penugasan/plt/7')
    const fd = body as FormData
    expect(fd.get('_method')).toBe('PUT')
    expect(fd.get('nip_plt')).toBe('198001012005011001')
    expect(fd.get('id_jabatan')).toBe('5')
    expect((fd.get('lampiran') as File).name).toBe('sk.pdf')
    await vi.waitFor(() => expect(wrapper.emitted('saved')).toHaveLength(1))
  })

  it('422 errors.lampiran tampil di isian berkas; dialog tetap terbuka', async () => {
    post.mockRejectedValue(apiError(422, { lampiran: ['Ukuran berkas melebihi 5 MB.'] }))
    const wrapper = await mountDialog()
    await pilihLampiran()
    await submit()

    await vi.waitFor(() => expect(q('[data-testid="penugasan-berkas-lampiran"]')?.textContent).toContain('Ukuran berkas melebihi 5 MB.'))
    expect(wrapper.emitted('saved')).toBeUndefined()
  })
})

describe('penugasanService.lampiran', () => {
  it('nama dari Content-Disposition; tanpa header → fallback ekstensi dari tipe blob', async () => {
    get.mockResolvedValueOnce({ data: new Blob(['x'], { type: 'application/pdf' }), headers: { 'content-disposition': 'attachment; filename="lampiran_plt_7.pdf"' } })
    expect((await penugasanService.lampiran('plt', 7)).nama).toBe('lampiran_plt_7.pdf')

    get.mockResolvedValueOnce({ data: new Blob(['x'], { type: 'image/png' }), headers: {} })
    expect((await penugasanService.lampiran('plh', 3)).nama).toBe('lampiran_plh_3.png')
    expect(get).toHaveBeenLastCalledWith('/penugasan/plh/3/lampiran', { responseType: 'blob' })
  })
})
