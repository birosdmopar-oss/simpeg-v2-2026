/**
 * TambahPegawaiDialog (B-05, MAKE-011): foto wajib, kirim `POST pegawai` multipart, langkah hasil menampilkan password
 * awal A-09 sekali + peringatan email tidak disimpan; galat 422 `errors.nip` di isian NIP.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/pegawai.service', () => ({ pegawaiService: { tambah: vi.fn() } }))
vi.mock('@/features/master-data/services/master.service', () => ({
  masterService: {
    // Opsi semua isian ref yang dipakai ISIAN (provinsi 31, kabupaten 3171, agama/jenis pegawai/jenis status 1).
    options: vi.fn().mockResolvedValue(['31', '3171', '1'].map((id) => ({ id, nama: `Opsi ${id}`, parent: null }))),
  },
}))

import { createShellRouter } from '@/shared/layouts/__tests__/shellTestUtils'

import TambahPegawaiDialog from '../components/TambahPegawaiDialog.vue'
import { pegawaiService } from '../services/pegawai.service'

import { apiError, pegawai } from './fixtures'

const tambah = vi.mocked(pegawaiService.tambah)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)
const field = (name: string) =>
  q<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(`[data-field="${name}"] select, [data-field="${name}"] input, [data-field="${name}"] textarea, select[data-field="${name}"], input[data-field="${name}"]`)

const ISIAN: Record<string, string> = {
  nip: '198505052010012001', nama: 'Pegawai Baru', tgl_lahir: '1985-05-05', id_provinsi_lahir: '31', id_kabupaten_kota_lahir: '3171',
  id_agama: '1', jenis_kelamin: '2', status_pernikahan: '1', npwp: '1', nik: '2', bpjs_kes: '3', no_taspen: '4', email: 'a@contoh.test',
  no_hp: '5', jenis_kerabat: 'Anak', no_telp_kerabat: '6', id_jenis_pegawai: '1', id_jenis_status: '1',
}

async function isiForm(): Promise<void> {
  for (const [name, value] of Object.entries(ISIAN)) {
    const el = field(name)
    if (!el) throw new Error(`isian ${name} tidak ada`)
    el.value = value
    el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
    // Isian ref berjenjang: memilih provinsi mengosongkan & memuat ulang kabupaten, tunggu sebelum isian berikutnya.
    await flushPromises()
  }
}

async function pilihFoto(): Promise<void> {
  const input = q<HTMLInputElement>('[data-testid="tambah-pegawai-berkas-0"] input[type="file"]')
  if (!input) throw new Error('isian foto tidak ada')
  Object.defineProperty(input, 'files', { value: [new File(['x'], 'foto.png', { type: 'image/png' })], configurable: true })
  input.dispatchEvent(new Event('change'))
  await flushPromises()
}

async function submit(): Promise<void> {
  q('[data-testid="tambah-pegawai-form"]')?.dispatchEvent(new Event('submit'))
  await flushPromises()
  await new Promise((r) => setTimeout(r, 0))
  await flushPromises()
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  // Tanpa kurung kurawal: nilai kembalian beforeEach dijalankan Vitest sebagai teardown (memanggil mock lagi).
  tambah.mockReset()
})

describe('TambahPegawaiDialog', () => {
  it('foto wajib: tanpa foto tidak mengirim', async () => {
    mount(TambahPegawaiDialog, { props: { open: true }, global: { plugins: [createShellRouter()] }, attachTo: document.body })
    await flushPromises()
    await isiForm()
    await submit()
    await vi.waitFor(() => expect(q('[data-testid="tambah-pegawai-berkas-error-0"]')?.textContent ?? '').toContain('wajib diunggah'))
    expect(tambah).not.toHaveBeenCalled()
  })

  it('sukses: kirim isian + foto; tampilkan username + password awal (= NIP, segera ganti) + peringatan email', async () => {
    tambah.mockResolvedValue({
      pegawai: pegawai({ nip: ISIAN.nip }),
      akun: { id_pengguna: 5, username: ISIAN.nip, user_level: 2, created: true, initial_password: ISIAN.nip, email_skipped: true },
    })
    const wrapper = mount(TambahPegawaiDialog, { props: { open: true }, global: { plugins: [createShellRouter()] }, attachTo: document.body })
    await flushPromises()
    await isiForm()
    await pilihFoto()
    await submit()

    await vi.waitFor(() => expect(tambah).toHaveBeenCalledTimes(1))
    const [payload, foto] = tambah.mock.calls[0]
    expect(payload).toMatchObject({ nip: ISIAN.nip, nama: 'Pegawai Baru' })
    expect(payload).not.toHaveProperty('status')
    expect(foto.name).toBe('foto.png')
    await vi.waitFor(() => expect(q('[data-testid="tambah-pegawai-password"]')?.textContent).toBe(ISIAN.nip))
    expect(q('[data-testid="tambah-pegawai-ganti-password"]')?.textContent).toContain('segera masuk dan mengganti password')
    expect(q('[data-testid="tambah-pegawai-email-skipped"]')).not.toBeNull()
    expect(wrapper.emitted('created')).toHaveLength(1)
  })

  it('422 errors.nip ditampilkan di isian NIP', async () => {
    tambah.mockRejectedValue(apiError(422, { nip: ['NIP sudah terdaftar di dalam sistem; gunakan NIP yang berbeda.'] }))
    mount(TambahPegawaiDialog, { props: { open: true }, global: { plugins: [createShellRouter()] }, attachTo: document.body })
    await flushPromises()
    await isiForm()
    await pilihFoto()
    await submit()
    await vi.waitFor(() => expect(q('[data-field="nip"]')?.textContent ?? '').toContain('NIP sudah terdaftar'))
  })
})
