/**
 * CR-186 (K-C): BE menghapus token pratinjau untuk semua hasil commit sesudah muat (PRD 5A AC-D10.7, §7.2.x), jadi
 * sesudah commit gagal karena 404/409/410/422/≥500 tombol Simpan tidak ditawarkan lagi (klik berikutnya pasti 404).
 * 500 ber-envelope menampilkan pesan server; 502/504 proxy tanpa envelope tidak boleh mengklaim "Tidak ada data yang
 * disimpan" (commit mungkin sudah sukses). 401/403 dan galat jaringan mempertahankan pratinjau.
 * Diuji dengan KLIEN ASLI (`createApiClient` + interceptor) dan amplop backend nyata; hanya adapter yang palsu.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  commit: [] as ({ status: number; body: unknown } | 'jaringan')[],
  panggilanCommit: 0,
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const url = config.url ?? ''
    let b: { status: number; body: unknown } | 'jaringan'
    if (url === '/slip-gaji/impor/pratinjau') b = { status: 200, body: { status: 'success', data: PRATINJAU } }
    else if (url.endsWith('/commit')) {
      server.panggilanCommit += 1
      b = server.commit.shift() ?? { status: 404, body: { status: 'error', message: 'Pratinjau tidak ditemukan atau sudah kedaluwarsa.' } }
    } else throw new Error(`tidak ada balasan palsu untuk ${url}`)
    if (b === 'jaringan') throw new AxiosError('Network Error', 'ERR_NETWORK', config, {})
    const response: AxiosResponse = { status: b.status, statusText: 'x', headers: {}, config, data: b.body }
    if (b.status >= 400) throw new AxiosError(`Request failed with status code ${b.status}`, 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import SlipGajiImpor from '../components/SlipGajiImpor.vue'

const PRATINJAU = {
  token_pratinjau: 'tok123',
  kedaluwarsa_at: '2026-10-28T05:00:00Z',
  timpa_bawaan: '1',
  filter: null,
  ringkasan: { valid: '1', duplikat: '0', galat: '0' },
  info: {},
  baris: [
    { nomor: '2', sheet: 'GPP', nip: '199001012020011001', nama: 'Budi', periode: '2026-09', status: 'valid', rencana_bila_timpa: 'sisip', rencana_bila_tidak_timpa: 'sisip', galat: [], info: [], nilai: {} },
  ],
}

const galatBe = (status: number, message: string, errors?: Record<string, string[]>) => ({ status, body: { status: 'error', message, ...(errors ? { errors } : {}) } })

const testid = (id: string) => document.body.querySelector<HTMLElement>(`[data-testid="${id}"]`)
const tombol = (teks: string) => Array.from(document.body.querySelectorAll<HTMLButtonElement>('button')).find((b) => b.textContent?.trim() === teks)

async function sampaiPratinjau() {
  const wrapper = mount(SlipGajiImpor, { attachTo: document.body })
  await flushPromises()
  const input = document.body.querySelector<HTMLInputElement>('input[type="file"]')
  if (!input) throw new Error('input berkas tidak ada')
  Object.defineProperty(input, 'files', { value: [new File(['x'], 'gaji.xlsx')], configurable: true })
  input.dispatchEvent(new Event('change'))
  await flushPromises()
  testid('impor-validasi')?.click()
  await flushPromises()
  return wrapper
}

async function simpan(): Promise<void> {
  testid('impor-simpan')?.click()
  await flushPromises()
  tombol('Ya, Simpan ke Database')?.click()
  await flushPromises()
}

beforeEach(() => {
  server.commit = []
  server.panggilanCommit = 0
})
afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('Impor Slip Gaji — commit gagal (CR-186)', () => {
  it('500 ber-envelope → pesan server tampil, Simpan hilang, tidak ada commit kedua; Batal tetap ada', async () => {
    server.commit = [galatBe(500, 'Impor gagal diproses. Tidak ada data yang disimpan.')]
    await sampaiPratinjau()
    await simpan()

    expect(testid('impor-failure')?.textContent).toContain('Impor gagal diproses. Tidak ada data yang disimpan.')
    expect(testid('impor-failure')?.textContent).toContain('unggah ulang')
    expect(testid('impor-simpan')).toBeNull()
    await simpan()
    expect(server.panggilanCommit).toBe(1)
    expect(testid('impor-batal')).not.toBeNull()
  })

  it('422 commit → pesan server tampil, Simpan hilang, tidak ada commit kedua', async () => {
    server.commit = [galatBe(422, 'Opsi timpa harus bernilai true atau false.', { timpa: ['Opsi timpa harus bernilai true atau false.'] })]
    await sampaiPratinjau()
    await simpan()

    expect(testid('impor-failure')?.textContent).toContain('Opsi timpa harus bernilai true atau false.')
    expect(testid('impor-simpan')).toBeNull()
    await simpan()
    expect(server.panggilanCommit).toBe(1)
  })

  it.each([502, 504])('%i proxy tanpa envelope → tanpa klaim "Tidak ada data yang disimpan", minta cek data dulu, Simpan hilang', async (status) => {
    server.commit = [{ status, body: '<html><body>Gateway Timeout</body></html>' }]
    await sampaiPratinjau()
    await simpan()

    const teks = testid('impor-failure')?.textContent ?? ''
    expect(teks).not.toContain('Tidak ada data yang disimpan')
    expect(teks).toContain('mungkin sudah tersimpan')
    expect(teks).not.toContain('Coba lagi')
    expect(testid('impor-simpan')).toBeNull()
    expect(testid('impor-batal')).not.toBeNull()
  })

  it.each([
    [409, 'Data slip NIP 199001012020011001 periode September 2026 sudah ditambahkan proses lain. Tidak ada data yang disimpan; ulangi unggah dan pratinjau.'],
    [410, 'Pratinjau sudah kedaluwarsa.'],
    [404, 'Pratinjau tidak ditemukan atau sudah kedaluwarsa.'],
  ])('pagar: %i tetap konflik dengan pesan server + ajakan unggah ulang', async (status, pesan) => {
    server.commit = [galatBe(status, pesan)]
    await sampaiPratinjau()
    await simpan()

    expect(testid('impor-failure')?.textContent).toContain(pesan)
    expect(testid('impor-failure')?.textContent).toContain('unggah ulang')
    expect(testid('impor-simpan')).toBeNull()
  })

  it('pagar: galat jaringan mempertahankan pratinjau dan Simpan (boleh dicoba lagi)', async () => {
    server.commit = ['jaringan', { status: 200, body: { status: 'success', data: { disisip: '1', diperbarui: '0', dilewati: '0', gagal: '0', baris: [] } } }]
    await sampaiPratinjau()
    await simpan()

    expect(testid('impor-failure')?.textContent).toContain('Tidak dapat terhubung')
    expect(testid('impor-pratinjau')).not.toBeNull()
    expect(testid('impor-simpan')).not.toBeNull()
    await simpan()
    expect(server.panggilanCommit).toBe(2)
    expect(testid('impor-hasil')).not.toBeNull()
  })

  it('pagar: 403 tidak dianggap token habis (pratinjau + Simpan tetap)', async () => {
    server.commit = [galatBe(403, 'Anda tidak berhak mengakses data ini.')]
    await sampaiPratinjau()
    await simpan()

    expect(testid('impor-pratinjau')).not.toBeNull()
    expect(testid('impor-simpan')).not.toBeNull()
  })

  it('pagar: sukses → emit selesai dengan periode hasil', async () => {
    server.commit = [{ status: 200, body: { status: 'success', data: { disisip: '1', diperbarui: '0', dilewati: '0', gagal: '0', baris: [{ nip: '199001012020011001', periode: '2026-09', hasil: 'sisip', galat: [] }] } } }]
    const wrapper = await sampaiPratinjau()
    await simpan()

    expect(wrapper.emitted('selesai')?.[0]).toEqual(['2026-09'])
    expect(testid('impor-hasil')).not.toBeNull()
  })

  it('unggah ulang sesudah token habis menawarkan Simpan lagi', async () => {
    server.commit = [galatBe(500, 'Impor gagal diproses. Tidak ada data yang disimpan.')]
    await sampaiPratinjau()
    await simpan()
    expect(testid('impor-simpan')).toBeNull()

    testid('impor-batal')?.click()
    await flushPromises()
    const input = document.body.querySelector<HTMLInputElement>('input[type="file"]')
    Object.defineProperty(input, 'files', { value: [new File(['x'], 'gaji.xlsx')], configurable: true })
    input?.dispatchEvent(new Event('change'))
    await flushPromises()
    testid('impor-validasi')?.click()
    await flushPromises()
    expect(testid('impor-simpan')).not.toBeNull()
    expect(testid('impor-failure')).toBeNull()
  })
})
