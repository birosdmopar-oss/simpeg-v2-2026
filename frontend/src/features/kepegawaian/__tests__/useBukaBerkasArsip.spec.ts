/**
 * Aksi "Buka" halaman Arsip (F-10, MAKE-091; AC1-18, PRD F10-37): jalur `publik` (window.open + noopener, tanpa API),
 * jalur `privat` pratinjau (popup sinkron sebelum API selesai, blob bertipe dari daftar tertutup, revoke tertunda +
 * saat unmount), jalur `privat` unduh (tanpa popup, `simpanBerkas`), serta galat (popup ditutup, pesan di halaman).
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('../services/berkasUnduhan', () => ({ ambilBerkas: vi.fn(), simpanBerkas: vi.fn() }))

import type { ArsipBerkas } from '../arsip.types'
import { JEDA_CABUT_MS, PESAN_POPUP_DIBLOKIR, PESAN_TERLALU_BANYAK, tipePratinjau, useBukaBerkasArsip } from '../composables/useBukaBerkasArsip'
import { ambilBerkas, simpanBerkas } from '../services/berkasUnduhan'

const ambil = vi.mocked(ambilBerkas)
const simpan = vi.mocked(simpanBerkas)

function item(overrides: Partial<ArsipBerkas> = {}): ArsipBerkas {
  return {
    id_attachment: 123,
    nama_arsip: 'SK Jabatan',
    tag: 'SK Jabatan',
    display_name: null,
    id_riwayat: 9,
    jenis_riwayat: 'Jabatan',
    id_entri: '456',
    ringkasan_riwayat: '—',
    file_type: 'application/pdf',
    created_at: '2026-10-08 03:15:00',
    berkas_ada: true,
    status_berkas: 'ada',
    akses_berkas: 'privat',
    url_berkas: 'pegawai/<nip>/arsip/berkas/123/buka',
    jenis_buka: 'pratinjau',
    aksi: { edit: false, hubungkan: false, hapus: false },
    ...overrides,
  }
}

/** Galat seperti dari interceptor: `body` sudah diurai `ambilBerkas` (response.data = objek), atau Blob bila tak terbaca. */
function galat(status: number, body: unknown, message = `HTTP ${status}`) {
  return { status, message, errors: null, isNetworkError: false, original: { response: { status, data: body } } }
}

function pasang() {
  const Host = defineComponent({
    setup() {
      return useBukaBerkasArsip()
    },
    render: () => h('div'),
  })
  const wrapper = mount(Host)
  return { wrapper, vm: wrapper.vm as unknown as ReturnType<typeof useBukaBerkasArsip> }
}

function popupTiruan() {
  return { location: { href: '' }, close: vi.fn(), opener: {} as unknown, closed: false, document: { title: '', body: { textContent: '' } } }
}

let openSpy: ReturnType<typeof vi.spyOn>
let buatUrl: ReturnType<typeof vi.fn>
let cabutUrl: ReturnType<typeof vi.fn>
const blobsDibuat: Blob[] = []

beforeEach(() => {
  ambil.mockReset()
  simpan.mockReset()
  blobsDibuat.length = 0
  openSpy = vi.spyOn(window, 'open')
  buatUrl = vi.fn((blob: Blob) => {
    blobsDibuat.push(blob)
    return `blob:uji/${blobsDibuat.length}`
  })
  cabutUrl = vi.fn()
  Object.defineProperty(URL, 'createObjectURL', { value: buatUrl, configurable: true, writable: true })
  Object.defineProperty(URL, 'revokeObjectURL', { value: cabutUrl, configurable: true, writable: true })
})

afterEach(() => {
  openSpy.mockRestore()
  vi.useRealTimers()
})

describe('tipePratinjau — daftar tertutup menurut ekstensi', () => {
  it.each([
    ['SK.pdf', 'application/pdf'],
    ['SK.PDF', 'application/pdf'],
    ['foto.jpg', 'image/jpeg'],
    ['foto.jpeg', 'image/jpeg'],
    ['foto.png', 'image/png'],
    ['foto.gif', 'image/gif'],
    ['halaman.html', 'application/octet-stream'],
    ['gambar.svg', 'application/octet-stream'],
    ['tanpa-ekstensi', 'application/octet-stream'],
  ])('%s → %s', (nama, tipe) => {
    expect(tipePratinjau(nama)).toBe(tipe)
  })
})

describe('Buka — akses_berkas = publik', () => {
  it('memanggil window.open 600×600 + noopener dan tidak memanggil API', () => {
    openSpy.mockReturnValue(null)
    const { vm } = pasang()
    vm.buka(item({ akses_berkas: 'publik', url_berkas: '/berkas/arsip/<nip>/<sub>/SK.pdf' }))
    expect(openSpy).toHaveBeenCalledTimes(1)
    expect(openSpy).toHaveBeenCalledWith('/berkas/arsip/<nip>/<sub>/SK.pdf', 'arsip-popup', 'width=600,height=600,noopener')
    expect(ambil).not.toHaveBeenCalled()
  })

  it('url_berkas null → tidak dibuka (disabled) dan window.open tidak dipanggil', () => {
    const { vm } = pasang()
    const hilang = item({ akses_berkas: null, url_berkas: null, status_berkas: 'hilang', berkas_ada: false, jenis_buka: null })
    expect(vm.bisaDibuka(hilang)).toBe(false)
    vm.buka(hilang)
    expect(openSpy).not.toHaveBeenCalled()
    expect(ambil).not.toHaveBeenCalled()
  })
})

describe('Buka — akses_berkas = privat, pratinjau (F10-37)', () => {
  it('popup dibuka SINKRON sebelum permintaan API selesai, tanpa noopener, opener di-null-kan', async () => {
    const popup = popupTiruan()
    openSpy.mockReturnValue(popup as unknown as Window)
    let selesai!: (v: { blob: Blob; nama: string }) => void
    ambil.mockReturnValue(new Promise((resolve) => (selesai = resolve)))

    const { vm } = pasang()
    vm.buka(item())

    // Sinkron: window.open sudah terpanggil padahal ambilBerkas belum selesai.
    expect(openSpy).toHaveBeenCalledWith('', 'arsip-popup', 'width=600,height=600')
    expect(String(openSpy.mock.calls[0]?.[2])).not.toContain('noopener')
    expect(popup.opener).toBeNull()
    expect(popup.document.body.textContent).toBe('Memuat berkas…')
    expect(ambil).toHaveBeenCalledWith('pegawai/<nip>/arsip/berkas/123/buka', 'arsip-123')
    expect(popup.location.href).toBe('')
    expect(vm.sedangDimuat).toBe(123)
    expect(vm.bisaDibuka(item())).toBe(false)

    selesai({ blob: new Blob(['x'], { type: 'application/pdf' }), nama: 'SK Jabatan.pdf' })
    await vi.waitFor(() => expect(popup.location.href).toBe('blob:uji/1'))
    expect(vm.sedangDimuat).toBeNull()
    expect(popup.close).not.toHaveBeenCalled()
    expect(simpan).not.toHaveBeenCalled()
  })

  it('tipe blob dari daftar tertutup: respons text/html bernama .pdf → application/pdf; ekstensi lain → octet-stream', async () => {
    openSpy.mockReturnValue(popupTiruan() as unknown as Window)
    const { vm } = pasang()

    ambil.mockResolvedValueOnce({ blob: new Blob(['<script>'], { type: 'text/html' }), nama: 'SK.pdf' })
    vm.buka(item())
    await vi.waitFor(() => expect(blobsDibuat).toHaveLength(1))
    expect(blobsDibuat[0]?.type).toBe('application/pdf')

    ambil.mockResolvedValueOnce({ blob: new Blob(['<script>'], { type: 'text/html' }), nama: 'halaman.html' })
    vm.buka(item())
    await vi.waitFor(() => expect(blobsDibuat).toHaveLength(2))
    expect(blobsDibuat[1]?.type).toBe('application/octet-stream')

    ambil.mockResolvedValueOnce({ blob: new Blob(['<svg/>'], { type: 'image/svg+xml' }), nama: 'gambar.svg' })
    vm.buka(item())
    await vi.waitFor(() => expect(blobsDibuat).toHaveLength(3))
    expect(blobsDibuat[2]?.type).toBe('application/octet-stream')
  })

  it('object URL dicabut sesudah penundaan tetap (fake timer)', async () => {
    vi.useFakeTimers()
    openSpy.mockReturnValue(popupTiruan() as unknown as Window)
    ambil.mockResolvedValue({ blob: new Blob(['x']), nama: 'SK.pdf' })
    const { vm } = pasang()
    vm.buka(item())
    await vi.advanceTimersByTimeAsync(0)
    expect(buatUrl).toHaveBeenCalledTimes(1)
    expect(cabutUrl).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(JEDA_CABUT_MS - 1)
    expect(cabutUrl).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    expect(cabutUrl).toHaveBeenCalledWith('blob:uji/1')
  })

  it('object URL yang belum dicabut dicabut saat komponen di-unmount (dan timernya dibatalkan)', async () => {
    vi.useFakeTimers()
    openSpy.mockReturnValue(popupTiruan() as unknown as Window)
    ambil.mockResolvedValue({ blob: new Blob(['x']), nama: 'SK.pdf' })
    const { wrapper, vm } = pasang()
    vm.buka(item())
    await vi.advanceTimersByTimeAsync(0)
    expect(cabutUrl).not.toHaveBeenCalled()
    wrapper.unmount()
    expect(cabutUrl).toHaveBeenCalledWith('blob:uji/1')
    await vi.advanceTimersByTimeAsync(JEDA_CABUT_MS)
    expect(cabutUrl).toHaveBeenCalledTimes(1)
  })

  it('popup diblokir (window.open null) → pesan, tanpa panggilan API', () => {
    openSpy.mockReturnValue(null)
    const { vm } = pasang()
    vm.buka(item())
    expect(vm.galat).toBe(PESAN_POPUP_DIBLOKIR)
    expect(ambil).not.toHaveBeenCalled()
  })
})

describe('Buka — akses_berkas = privat, unduh (F10-37 (4))', () => {
  it('tanpa window.open; berkas disimpan lewat simpanBerkas dengan nama berekstensi', async () => {
    const berkas = { blob: new Blob(['x']), nama: 'SK Jabatan.docx' }
    ambil.mockResolvedValue(berkas)
    const { vm } = pasang()
    vm.buka(item({ jenis_buka: 'unduh' }))
    expect(openSpy).not.toHaveBeenCalled()
    await vi.waitFor(() => expect(simpan).toHaveBeenCalledWith(berkas))
    expect(buatUrl).not.toHaveBeenCalled()
    expect(vm.sedangDimuat).toBeNull()
  })
})

describe('Buka — galat (privat)', () => {
  it.each([
    [429, { message: 'Terlalu banyak membuka berkas arsip. Coba lagi dalam satu menit.' }, 'Terlalu banyak membuka berkas arsip. Coba lagi dalam satu menit.'],
    [404, { message: 'Berkas tidak ditemukan.' }, 'Berkas tidak ditemukan.'],
    [403, { message: 'Anda tidak berhak mengakses data ini.' }, 'Anda tidak berhak mengakses data ini.'],
  ])('HTTP %i: popup ditutup, pesan server tampil di halaman', async (status, body, pesan) => {
    const popup = popupTiruan()
    openSpy.mockReturnValue(popup as unknown as Window)
    ambil.mockRejectedValue(galat(status, body, pesan))
    const { vm } = pasang()
    vm.buka(item())
    await vi.waitFor(() => expect(vm.galat).toBe(pesan))
    expect(popup.close).toHaveBeenCalledTimes(1)
    expect(buatUrl).not.toHaveBeenCalled()
    expect(vm.sedangDimuat).toBeNull()
  })

  it('429 dengan body tak terbaca (Blob) → cadangan 429 khusus arsip, bukan teks mentah axios', async () => {
    const popup = popupTiruan()
    openSpy.mockReturnValue(popup as unknown as Window)
    ambil.mockRejectedValue(galat(429, new Blob(['<html>'], { type: 'text/html' }), 'Request failed with status code 429'))
    const { vm } = pasang()
    vm.buka(item())
    await vi.waitFor(() => expect(vm.galat).toBe(PESAN_TERLALU_BANYAK))
    expect(popup.close).toHaveBeenCalled()
  })

  it('404 dengan body tak terbaca → pesan baku, bukan JSON/teks mentah', async () => {
    openSpy.mockReturnValue(popupTiruan() as unknown as Window)
    ambil.mockRejectedValue(galat(404, new Blob(['x']), 'Request failed with status code 404'))
    const { vm } = pasang()
    vm.buka(item())
    await vi.waitFor(() => expect(vm.galat).toBe('Data tidak ditemukan.'))
  })

  it('401 (refresh gagal): popup ditutup, TANPA pesan tambahan (interceptor mengalihkan ke /login)', async () => {
    const popup = popupTiruan()
    openSpy.mockReturnValue(popup as unknown as Window)
    ambil.mockRejectedValue(galat(401, { message: 'Sesi berakhir.' }))
    const { vm } = pasang()
    vm.buka(item())
    await vi.waitFor(() => expect(popup.close).toHaveBeenCalled())
    expect(vm.galat).toBeNull()
  })

  it('403 password_change_required: popup ditutup, tanpa pesan tambahan', async () => {
    const popup = popupTiruan()
    openSpy.mockReturnValue(popup as unknown as Window)
    ambil.mockRejectedValue(galat(403, { code: 'password_change_required', message: 'Ganti password dulu.' }))
    const { vm } = pasang()
    vm.buka(item())
    await vi.waitFor(() => expect(popup.close).toHaveBeenCalled())
    expect(vm.galat).toBeNull()
  })

  it('galat pada jenis unduh: tidak ada popup untuk ditutup; pesan tampil', async () => {
    ambil.mockRejectedValue(galat(404, { message: 'Berkas tidak ditemukan.' }))
    const { vm } = pasang()
    vm.buka(item({ jenis_buka: 'unduh' }))
    await vi.waitFor(() => expect(vm.galat).toBe('Berkas tidak ditemukan.'))
    expect(openSpy).not.toHaveBeenCalled()
    expect(simpan).not.toHaveBeenCalled()
  })

  it('membuka lagi menghapus pesan galat sebelumnya', async () => {
    openSpy.mockReturnValue(popupTiruan() as unknown as Window)
    ambil.mockRejectedValueOnce(galat(404, { message: 'Berkas tidak ditemukan.' }))
    const { vm } = pasang()
    vm.buka(item())
    await vi.waitFor(() => expect(vm.galat).toBe('Berkas tidak ditemukan.'))
    ambil.mockResolvedValueOnce({ blob: new Blob(['x']), nama: 'SK.pdf' })
    vm.buka(item())
    expect(vm.galat).toBeNull()
  })
})
