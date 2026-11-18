/**
 * Dialog ekspor rekap petugas (B4, MAKE-074 FE; AC-E08.14 / AC-E09.5). Batas picker = hari ini WIB [K]: hari ini lolos, besok
 * ditolak picker, ds = de lolos; ds ≤ de dijaga silang. Hasil kosong (200 JSON) → notice tanpa unduhan; berkas → simpanBerkas;
 * 403 bukan_petugas → pesan BE + petugas/saya disegarkan; 422 per kolom / terlalu besar → pesan BE. Service di-mock; bentuk galat =
 * fixture kontrak MAKE-074 (salinan dari `main`).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { AxiosError, AxiosHeaders } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'

const svc = vi.hoisted(() => ({ ekspor: vi.fn(), simpan: vi.fn(), muatPetugasSaya: vi.fn() }))
vi.mock('../services/tiket.service', async (asli) => ({
  ...(await asli<typeof import('../services/tiket.service')>()),
  tiketHaloService: { ekspor: svc.ekspor },
}))
vi.mock('@/features/kepegawaian/services/berkasUnduhan', async (asli) => ({
  ...(await asli<typeof import('@/features/kepegawaian/services/berkasUnduhan')>()),
  simpanBerkas: svc.simpan,
}))
// Review B4 T3: "hari ini WIB" komponen di-mock ke tanggal tetap yang BERBEDA dari hari ini lokal, supaya test mengikat batas `max`
// picker ke `hariIniWib()` (bukan ke `today()` kalender lokal). Fungsi aslinya diuji terpisah lewat `importActual`.
const HARI_MOCK = '2030-06-15'
vi.mock('../constants', async (asli) => ({
  ...(await asli<typeof import('../constants')>()),
  hariIniWib: () => HARI_MOCK,
}))
vi.mock('../stores/haloSimpeg', async (asli) => ({
  ...(await asli<typeof import('../stores/haloSimpeg')>()),
  muatPetugasSaya: svc.muatPetugasSaya,
}))

import EksporRekapDialog from '../components/EksporRekapDialog.vue'
import { TEKS_EKSPOR } from '../constants'
import eksporKosong from './fixtures/ekspor-kosong.json'
import galat403 from './fixtures/galat-403-bukan-petugas.json'
import galat422 from './fixtures/galat-422-ekspor.json'
import galat422Besar from './fixtures/galat-422-ekspor-terlalu-besar.json'

function apiError(status: number, body: { message: string; code?: string; errors?: Record<string, string[]> }) {
  const res = { status, statusText: 'x', headers: new AxiosHeaders(), config: { headers: new AxiosHeaders() }, data: { status: 'error', ...body } }
  return { status, message: body.message, errors: body.errors ?? null, isNetworkError: false, original: new AxiosError('x', 'ERR', undefined, null, res as never) }
}

// Jam tidak dipalsukan (Date beku membuat invoker event Vue mengabaikan blur): "hari ini" picker = HARI_MOCK; zona WIB fungsi
// asli diuji terpisah dengan Date eksplisit.
const SEKARANG = new Date('2026-11-18T20:00:00Z') // = 19-11-2026 03:00 WIB
const HARI_INI = HARI_MOCK
/** Geser tanggal `YYYY-MM-DD` sebanyak n hari (kalender UTC murni, tanpa jam lokal). */
function geser(iso: string, n: number): string {
  const [y, m, d] = iso.split('-').map(Number) as [number, number, number]
  const t = new Date(Date.UTC(y, m - 1, d + n))
  return `${t.getUTCFullYear()}-${String(t.getUTCMonth() + 1).padStart(2, '0')}-${String(t.getUTCDate()).padStart(2, '0')}`
}
const BESOK = geser(HARI_INI, 1)
const tampil = (iso: string) => iso.split('-').reverse().join('/')

async function pasang() {
  const open = ref(true)
  const Host = defineComponent({
    setup: () => () => h(EksporRekapDialog, { open: open.value, 'onUpdate:open': (v: boolean) => (open.value = v) }),
  })
  const w = mount(Host, { attachTo: document.body })
  await flushPromises()
  return { w, open }
}

const input = (nama: 'ds' | 'de') => document.querySelector(`input[name="${nama}"]`) as HTMLInputElement
async function ketik(nama: 'ds' | 'de', teks: string) {
  const el = input(nama)
  el.value = teks
  el.dispatchEvent(new Event('input'))
  await flushPromises()
  el.dispatchEvent(new Event('blur'))
  await flushPromises()
}
const bantuan = (nama: 'ds' | 'de') =>
  (input(nama).getAttribute('aria-describedby') ?? '')
    .split(' ')
    .filter(Boolean)
    .map((i) => document.getElementById(i)?.textContent?.trim() ?? '')
    .join(' ')
async function kirim() {
  ;(document.querySelector('[data-testid="halo-ekspor-form"]') as HTMLFormElement).dispatchEvent(new Event('submit'))
  await flushPromises()
}

beforeEach(() => {
  for (const f of Object.values(svc)) f.mockReset()
  svc.muatPetugasSaya.mockResolvedValue(undefined)
})
afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('hariIniWib', () => {
  it('memakai zona Asia/Jakarta, bukan UTC', async () => {
    const { hariIniWib } = await vi.importActual<typeof import('../constants')>('../constants')
    expect(hariIniWib(SEKARANG)).toBe('2026-11-19')
    expect(hariIniWib(new Date('2026-11-18T16:59:59Z'))).toBe('2026-11-18')
  })
})

describe('EksporRekapDialog — periode', () => {
  it('kosong → galat klien teks BE di kedua kolom, tanpa request', async () => {
    await pasang()
    await kirim()
    expect(svc.ekspor).not.toHaveBeenCalled()
    expect(bantuan('ds')).toContain(TEKS_EKSPOR.wajib)
    expect(bantuan('de')).toContain(TEKS_EKSPOR.wajib)
  })

  it('hari ini WIB lolos; ds = de lolos → dikirim YYYY-MM-DD', async () => {
    svc.ekspor.mockResolvedValue({ jenis: 'kosong', pesan: eksporKosong.message })
    await pasang()
    await ketik('ds', tampil(HARI_INI))
    await ketik('de', tampil(HARI_INI))
    expect([bantuan('ds'), bantuan('de')]).toEqual(['', ''])
    await kirim()
    expect(svc.ekspor).toHaveBeenCalledWith(HARI_INI, HARI_INI)
  })

  it('T3: max picker = hariIniWib() (tanggal mock ≠ hari ini lokal), bukan kalender lokal', async () => {
    const { w } = await pasang()
    expect(w.findAllComponents({ name: 'UiDatePicker' }).map((c) => c.props('max'))).toEqual([HARI_MOCK, HARI_MOCK])
  })

  it('besok (WIB) ditolak picker: nilai tidak diterima + pesan rentang; kirim → wajib diisi, tanpa request', async () => {
    await pasang()
    await ketik('ds', tampil(geser(HARI_INI, -5)))
    await ketik('de', tampil(BESOK))
    expect(bantuan('de')).toContain('Tanggal di luar rentang yang diizinkan.')
    await kirim()
    expect(svc.ekspor).not.toHaveBeenCalled()
    expect(bantuan('de')).toContain(TEKS_EKSPOR.wajib)
  })

  it('batas silang: de < ds ditolak picker (min = ds); ds > de ditolak picker (max = de)', async () => {
    await pasang()
    await ketik('ds', '10/11/2026')
    await ketik('de', '09/11/2026')
    expect(bantuan('de')).toContain('Tanggal di luar rentang yang diizinkan.')
    await ketik('de', '12/11/2026')
    expect(bantuan('de')).not.toContain('Tanggal di luar rentang yang diizinkan.')
    await ketik('ds', '13/11/2026')
    expect(bantuan('ds')).toContain('Tanggal di luar rentang yang diizinkan.')
    // Ketikan ds di luar batas mengosongkan nilai ds (perilaku UiDatePicker) → kirim ditolak klien, tanpa request.
    await kirim()
    expect(svc.ekspor).not.toHaveBeenCalled()
    expect(bantuan('ds')).toContain(TEKS_EKSPOR.wajib)
  })
})

describe('EksporRekapDialog — hasil', () => {
  async function isiSah() {
    await ketik('ds', '01/11/2026')
    await ketik('de', '18/11/2026')
  }
  // Periode tetap di masa lalu terhadap "hari ini" mana pun sesudah 18-11-2026.

  it('kosong (200 JSON) → notice pesan BE, tanpa unduhan, dialog tetap terbuka', async () => {
    svc.ekspor.mockResolvedValue({ jenis: 'kosong', pesan: eksporKosong.message })
    const { open } = await pasang()
    await isiSah()
    await kirim()
    expect(document.querySelector('[data-testid="halo-ekspor-kosong"]')?.textContent?.trim()).toBe(eksporKosong.message)
    expect(svc.simpan).not.toHaveBeenCalled()
    expect(open.value).toBe(true)
  })

  it('berkas → simpanBerkas (blob + nama) lalu dialog tertutup', async () => {
    const blob = new Blob(['PK'])
    svc.ekspor.mockResolvedValue({ jenis: 'berkas', nama: 'Rekap_Halo_Simpeg_2026-11-01_2026-11-18.xlsx', blob })
    const { open } = await pasang()
    await isiSah()
    await kirim()
    expect(svc.ekspor).toHaveBeenCalledWith('2026-11-01', '2026-11-18')
    expect(svc.simpan).toHaveBeenCalledWith({ blob, nama: 'Rekap_Halo_Simpeg_2026-11-01_2026-11-18.xlsx' })
    expect(open.value).toBe(false)
  })

  it('403 bukan_petugas → pesan BE + petugas/saya disegarkan paksa', async () => {
    svc.ekspor.mockRejectedValue(apiError(403, galat403))
    await pasang()
    await isiSah()
    await kirim()
    expect(document.querySelector('[data-testid="halo-ekspor-galat"]')?.textContent?.trim()).toBe(galat403.message)
    expect(svc.muatPetugasSaya).toHaveBeenCalledWith(true)
    expect(svc.simpan).not.toHaveBeenCalled()
  })

  it('422 ekspor_terlalu_besar → pesan BE (notice), tanpa unduhan; petugas/saya tidak dimuat ulang', async () => {
    svc.ekspor.mockRejectedValue(apiError(422, galat422Besar))
    await pasang()
    await isiSah()
    await kirim()
    expect(document.querySelector('[data-testid="halo-ekspor-galat"]')?.textContent?.trim()).toBe(galat422Besar.message)
    expect(svc.muatPetugasSaya).not.toHaveBeenCalled()
  })

  it('422 per kolom ds/de → galat di kolom, tanpa notice ganda', async () => {
    svc.ekspor.mockRejectedValue(apiError(422, galat422))
    await pasang()
    await isiSah()
    await kirim()
    expect(bantuan('ds')).toContain('Tanggal awal dan akhir wajib diisi.')
    expect(bantuan('de')).toContain('Tanggal awal dan akhir wajib diisi.')
    expect(document.querySelector('[data-testid="halo-ekspor-galat"]')).toBeNull()
  })

  it('dibuka ulang → isian dan pesan dikosongkan', async () => {
    svc.ekspor.mockResolvedValue({ jenis: 'kosong', pesan: eksporKosong.message })
    const { open } = await pasang()
    await isiSah()
    await kirim()
    open.value = false
    await flushPromises()
    open.value = true
    await flushPromises()
    expect([input('ds').value, input('de').value]).toEqual(['', ''])
    expect(document.querySelector('[data-testid="halo-ekspor-kosong"]')).toBeNull()
  })
})

describe('T2: request berjalan sesudah Batal / buka ulang', () => {
  it('tutup saat ekspor tertunda → lepas → tanpa unduhan/pesan; buka ulang → tombol aktif, form bersih', async () => {
    let lepas!: (v: unknown) => void
    svc.ekspor.mockReturnValueOnce(new Promise((r) => (lepas = r)))
    const { open } = await pasang()
    await ketik('ds', '01/11/2026')
    await ketik('de', '18/11/2026')
    await kirim()
    const tombol = () => document.querySelector('[data-testid="halo-ekspor-kirim"]') as HTMLButtonElement
    expect(tombol().disabled).toBe(true)
    open.value = false
    await flushPromises()
    open.value = true
    await flushPromises()
    expect(tombol().disabled).toBe(false)
    expect(tombol().textContent?.trim()).toBe('Ekspor Excel')
    lepas({ jenis: 'berkas', nama: 'lama.xlsx', blob: new Blob(['PK']) })
    await flushPromises()
    expect(svc.simpan).not.toHaveBeenCalled()
    expect(open.value).toBe(true)
    expect(tombol().disabled).toBe(false)
    expect(document.querySelector('[data-testid="halo-ekspor-kosong"]')).toBeNull()
  })

  it('galat dari generasi lama juga diabaikan (tanpa notice, tanpa muat ulang petugas/saya)', async () => {
    let tolak!: (e: unknown) => void
    svc.ekspor.mockReturnValueOnce(new Promise((_r, j) => (tolak = j)))
    const { open } = await pasang()
    await ketik('ds', '01/11/2026')
    await ketik('de', '18/11/2026')
    await kirim()
    open.value = false
    await flushPromises()
    tolak(apiError(403, galat403))
    await flushPromises()
    open.value = true
    await flushPromises()
    expect(document.querySelector('[data-testid="halo-ekspor-galat"]')).toBeNull()
    expect(svc.muatPetugasSaya).not.toHaveBeenCalled()
  })

  it('T5: notice galat tanpa role="alert" bersarang', async () => {
    svc.ekspor.mockRejectedValue(apiError(422, galat422Besar))
    await pasang()
    await ketik('ds', '01/11/2026')
    await ketik('de', '18/11/2026')
    await kirim()
    const notice = document.querySelector('[data-testid="halo-ekspor-galat"]')!
    expect(notice.querySelectorAll('[role="alert"]')).toHaveLength(0)
    expect(document.querySelectorAll('[role="alert"]')).toHaveLength(1)
  })
})
