/**
 * Impor Slip Gaji (D-10, AC-FE.2) [K `admin_import.php`]: pratinjau sebelum commit (status per baris dari server, baris
 * galat ditandai + pesannya), opsi "Timpa data yang sudah ada" bawaan tercentang, commit hanya lewat `ConfirmDialog`
 * dengan nilai timpa yang dipilih, 409 ditampilkan dengan ajakan unggah ulang, 422 berkas ditampilkan. Tanpa validasi
 * impor di FE (ADR-025). Payload memakai angka string (MySQLi).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
  isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'status' in v && 'original' in v,
}))

import { api } from '@/lib/axios'

import SlipGajiImpor from '../components/SlipGajiImpor.vue'

const post = vi.mocked(api.post)
const ok = <T>(data: T) => Promise.resolve({ data, headers: {} })
const gagal = (status: number, message = 'x', errors: Record<string, string[]> | null = null) =>
  Promise.reject({ status, message, errors, isNetworkError: false, original: {} })

const PRATINJAU = {
  token_pratinjau: 'tok123',
  kedaluwarsa_at: '2026-10-28T05:00:00Z',
  timpa_bawaan: true,
  filter: null,
  ringkasan: { valid: '1', duplikat: '1', galat: '1' },
  info: {},
  baris: [
    { nomor: '2', sheet: 'GPP', nip: '199001012020011001', nama: 'Budi', periode: '2026-09', status: 'valid', rencana_bila_timpa: 'sisip', rencana_bila_tidak_timpa: 'sisip', galat: [], info: [], nilai: {} },
    { nomor: '3', sheet: 'GPP', nip: '199001012020011002', nama: 'Ani', periode: '2026-09', status: 'duplikat', rencana_bila_timpa: 'perbarui', rencana_bila_tidak_timpa: 'lewati', galat: [], info: [], nilai: {} },
    {
      nomor: '4', sheet: 'TK', nip: '123', nama: null, periode: '2026-09', status: 'galat', rencana_bila_timpa: 'gagal', rencana_bila_tidak_timpa: 'gagal',
      galat: [{ kode: 'nip_tidak_valid', pesan: 'NIP harus 18 digit angka.', sheet: 'TK', baris: '4' }], info: [], nilai: {},
    },
  ],
}

const testid = (id: string) => document.body.querySelector<HTMLElement>(`[data-testid="${id}"]`)
const tombol = (teks: string) => Array.from(document.body.querySelectorAll<HTMLButtonElement>('button')).find((b) => b.textContent?.trim() === teks)

async function mountImpor() {
  const wrapper = mount(SlipGajiImpor, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

async function unggah(nama = 'gaji.xlsx'): Promise<void> {
  const input = document.body.querySelector<HTMLInputElement>('input[type="file"]')
  if (!input) throw new Error('input berkas tidak ada')
  Object.defineProperty(input, 'files', { value: [new File(['x'], nama)], configurable: true })
  input.dispatchEvent(new Event('change'))
  await flushPromises()
}

async function pratinjau(): Promise<void> {
  await unggah()
  testid('impor-validasi')?.click()
  await flushPromises()
}

beforeEach(() => {
  post.mockReset()
})
afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('Impor Slip Gaji — pratinjau & commit', () => {
  it('tombol validasi nonaktif sebelum berkas dipilih; pratinjau: status per baris, galat ditandai + pesannya, ringkasan', async () => {
    post.mockImplementation(() => ok(PRATINJAU) as never)
    await mountImpor()
    expect((testid('impor-validasi') as HTMLButtonElement).disabled).toBe(true)

    await pratinjau()

    expect(post).toHaveBeenCalledWith('/slip-gaji/impor/pratinjau', expect.any(FormData))
    expect(testid('impor-ringkasan')?.textContent).toMatch(/3\s*Total Baris/)
    const galat = testid('impor-baris-TK-4')
    expect(galat?.dataset.status).toBe('galat')
    expect(galat?.className).toContain('bg-danger-soft')
    expect(galat?.textContent).toContain('NIP harus 18 digit angka.')
    expect(testid('impor-baris-GPP-2')?.textContent).toContain('Siap disimpan')
    expect(testid('impor-baris-GPP-3')?.textContent).toContain('akan diperbarui')
  })

  it('"Timpa data yang sudah ada" bawaan tercentang; dilepas → duplikat dilewati; commit lewat ConfirmDialog dengan timpa=false', async () => {
    post.mockImplementation(((url: string) =>
      url === '/slip-gaji/impor/pratinjau'
        ? ok(PRATINJAU)
        : ok({ disisip: '1', diperbarui: '0', dilewati: '1', gagal: '1', baris: [{ nip: '199001012020011001', periode: '2026-09', hasil: 'sisip', galat: [] }] })) as never)
    const wrapper = await mountImpor()
    await pratinjau()

    const centang = document.body.querySelector<HTMLInputElement>('[data-testid="impor-timpa"] input[type="checkbox"]')
    expect(centang?.checked).toBe(true)
    centang?.click()
    await flushPromises()
    expect(testid('impor-baris-GPP-3')?.textContent).toContain('akan dilewati')

    testid('impor-simpan')?.click()
    await flushPromises()
    expect(post).toHaveBeenCalledTimes(1)
    expect(document.body.textContent).toContain('Simpan 1 baris ke database gaji pegawai?')

    tombol('Ya, Simpan ke Database')?.click()
    await flushPromises()

    expect(post).toHaveBeenLastCalledWith('/slip-gaji/impor/pratinjau/tok123/commit', { timpa: false })
    expect(testid('impor-hasil')?.textContent).toContain('Proses Impor Selesai')
    expect(wrapper.emitted('selesai')?.[0]).toEqual(['2026-09'])
  })

  it('409 saat commit → pesan server + ajakan unggah ulang', async () => {
    post.mockImplementation(((url: string) =>
      url === '/slip-gaji/impor/pratinjau'
        ? ok(PRATINJAU)
        : gagal(409, 'Data slip NIP 199001012020011001 periode September 2026 sudah ditambahkan proses lain.')) as never)
    await mountImpor()
    await pratinjau()
    testid('impor-simpan')?.click()
    await flushPromises()
    tombol('Ya, Simpan ke Database')?.click()
    await flushPromises()

    expect(testid('impor-failure')?.textContent).toContain('sudah ditambahkan proses lain')
    expect(testid('impor-failure')?.textContent).toContain('unggah ulang')
  })

  it('422 pratinjau (format berkas) → pesan server, tetap di langkah unggah', async () => {
    post.mockImplementation(() => gagal(422, 'Format berkas harus .xlsx atau .xls.', { berkas: ['Format berkas harus .xlsx atau .xls.'] }) as never)
    await mountImpor()
    await pratinjau()

    expect(testid('impor-failure')?.textContent).toContain('Format berkas harus .xlsx atau .xls.')
    expect(testid('impor-galat-berkas')?.textContent).toContain('Format berkas')
    expect(testid('impor-pratinjau')).toBeNull()
  })
})

describe('CR-082 T5A-02: label galat hasil impor', () => {
  it('pesan yang sudah berawalan "Sheet <sheet> baris <n>" tidak diberi awalan lagi; pesan tanpa awalan diberi awalan', async () => {
    post.mockImplementation(((url: string) =>
      url === '/slip-gaji/impor/pratinjau'
        ? ok(PRATINJAU)
        : ok({
            disisip: '0',
            diperbarui: '0',
            dilewati: '0',
            gagal: '2',
            baris: [
              {
                nip: '199001012020011001',
                periode: '2026-09',
                hasil: 'gagal',
                galat: [
                  { kode: 'kolom', pesan: 'Sheet GPP baris 9: kolom gjpokok tidak valid.', sheet: 'GPP', baris: 9 },
                  { kode: 'nip', pesan: 'NIP tidak terdaftar.', sheet: 'TK', baris: 10 },
                ],
              },
            ],
          })) as never)
    await mountImpor()
    await pratinjau()
    testid('impor-simpan')?.click()
    await flushPromises()
    tombol('Ya, Simpan ke Database')?.click()
    await flushPromises()

    const butir = Array.from(testid('impor-hasil-galat')?.querySelectorAll('li') ?? []).map((li) => li.textContent?.trim())
    expect(butir).toEqual(['Sheet GPP baris 9: kolom gjpokok tidak valid.', 'Sheet TK baris 10: NIP tidak terdaftar.'])
  })
})
