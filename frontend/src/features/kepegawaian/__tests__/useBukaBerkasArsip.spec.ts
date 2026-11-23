/**
 * Aksi "Buka" halaman Arsip (F-10, MAKE-091; AC1-18, PRD F10-37): jalur `publik` (window.open + noopener, tanpa API),
 * jalur `privat` pratinjau (popup sinkron sebelum API selesai, blob bertipe dari daftar tertutup, isi popup = halaman
 * pembungkus dengan tautan Unduh bernama arsip, object URL hidup selama popup terbuka — CR-126 T-06), jalur `privat` unduh (tanpa popup, `simpanBerkas`), serta galat (popup ditutup, pesan di halaman).
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('../services/berkasUnduhan', () => ({ ambilBerkas: vi.fn(), simpanBerkas: vi.fn() }))

import { SELANG_PERIKSA_POPUP_MS, SELANG_SIAP_POPUP_MS, cabutObjectUrlArsip, namaUnduhArsip } from '../arsip.popup'
import type { ArsipBerkas } from '../arsip.types'
import { PESAN_GANTI_PASSWORD, PESAN_POPUP_DIBLOKIR, PESAN_TERLALU_BANYAK, tipePratinjau, useBukaBerkasArsip } from '../composables/useBukaBerkasArsip'
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

/** Popup tiruan dengan dokumen nyata (jsdom) agar isi pembungkus bisa diperiksa. */
function popupTiruan(href = 'about:blank') {
  const popup = {
    location: { href, replace: vi.fn((alamat: string) => void (popup.location.href = alamat)) },
    close: vi.fn(),
    opener: {} as unknown,
    closed: false,
    document: document.implementation.createHTMLDocument(''),
  }
  Object.defineProperty(popup.document, 'readyState', { value: 'complete' }) // jsdom: dokumen buatan belum 'complete'
  return popup
}

const sel = <T extends Element = HTMLElement>(popup: ReturnType<typeof popupTiruan>, q: string) => popup.document.querySelector<T>(q)

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
  cabutObjectUrlArsip()
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
    expect(popup.document.title).toBe('Memuat berkas…')
    expect(ambil).toHaveBeenCalledWith('pegawai/<nip>/arsip/berkas/123/buka', 'arsip-123')
    expect(sel(popup, 'iframe')).toBeNull()
    expect(vm.sedangDimuat).toBe(123)
    expect(vm.bisaDibuka(item())).toBe(false)

    selesai({ blob: new Blob(['x'], { type: 'application/pdf' }), nama: 'SK Jabatan.pdf' })
    await vi.waitFor(() => expect(sel(popup, '[data-arsip="unduh"]')).not.toBeNull())
    expect(popup.location.href).toBe('about:blank') // popup TIDAK dinavigasi ke blob:
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

  // CR-186: sebelumnya judul test ini mengunci "interceptor tak memicu handler untuk body Blob". Sejak CR-186 interceptor
  // mengurai body Blob JSON 403 dan MEMICU handler ganti password (diuji dengan klien asli di lib/__tests__/axios.blob.spec.ts);
  // galat yang sampai ke composable sudah berbody objek terurai. Composable tetap menutup popup dan menampilkan pesan
  // sebagai jaring pengaman, jadi asersi di sini sengaja berubah dari "handler tidak terpicu" menjadi "body terurai → pesan".
  it('403 password_change_required (body sudah diurai interceptor, handler dipicu di sana): popup ditutup, pesan server tampil', async () => {
    const popup = popupTiruan()
    openSpy.mockReturnValue(popup as unknown as Window)
    ambil.mockRejectedValue(galat(403, { code: 'password_change_required', message: 'Ganti password dulu.' }))
    const { vm } = pasang()
    vm.buka(item())
    await vi.waitFor(() => expect(vm.galat).toBe('Ganti password dulu.'))
    expect(popup.close).toHaveBeenCalled()
  })

  // CR-186: tetap berlaku sesudah interceptor memicu handler; tanpa `message` server composable memakai cadangan.
  it('403 password_change_required tanpa pesan server (handler dipicu interceptor) → cadangan, klik Buka tidak diam', async () => {
    openSpy.mockReturnValue(popupTiruan() as unknown as Window)
    ambil.mockRejectedValue(galat(403, { code: 'password_change_required' }))
    const { vm } = pasang()
    vm.buka(item())
    await vi.waitFor(() => expect(vm.galat).toBe(PESAN_GANTI_PASSWORD))
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

describe('Buka — pembukaan beruntun (satu popup bernama sama)', () => {
  it('selama satu berkas privat dimuat, SEMUA Buka privat lain diabaikan (tanpa popup, tanpa API, tanpa jatah throttle)', async () => {
    const popupA = popupTiruan()
    openSpy.mockReturnValue(popupA as unknown as Window)
    let gagalA!: (e: unknown) => void
    ambil.mockReturnValue(new Promise((_, tolak) => (gagalA = tolak)))
    const { vm } = pasang()
    const A = item({ id_attachment: 1 })
    const B = item({ id_attachment: 2, url_berkas: 'pegawai/<nip>/arsip/berkas/2/buka' })

    vm.buka(A)
    expect(vm.bisaDibuka(B)).toBe(false)
    expect(vm.bisaDibuka(A)).toBe(false)
    vm.buka(B)
    vm.buka(A)
    expect(openSpy).toHaveBeenCalledTimes(1)
    expect(ambil).toHaveBeenCalledTimes(1)

    // Galat A menutup popup A dan tampil; sesudahnya Buka privat aktif lagi.
    gagalA(galat(404, { message: 'Berkas tidak ditemukan.' }))
    await vi.waitFor(() => expect(vm.galat).toBe('Berkas tidak ditemukan.'))
    expect(popupA.close).toHaveBeenCalledTimes(1)
    expect(vm.sedangDimuat).toBeNull()
    expect(vm.bisaDibuka(B)).toBe(true)
  })

  it('berkas publik tetap bisa dibuka saat berkas privat sedang dimuat', () => {
    openSpy.mockReturnValue(popupTiruan() as unknown as Window)
    ambil.mockReturnValue(new Promise(() => undefined))
    const { vm } = pasang()
    vm.buka(item())
    const publik = item({ id_attachment: 9, akses_berkas: 'publik', url_berkas: '/berkas/arsip/<nip>/SK.pdf' })
    expect(vm.bisaDibuka(publik)).toBe(true)
    vm.buka(publik)
    expect(openSpy).toHaveBeenLastCalledWith('/berkas/arsip/<nip>/SK.pdf', 'arsip-popup', 'width=600,height=600,noopener')
  })

  it('komponen di-unmount saat berkas dimuat → hasil basi: popup ditutup, object URL tidak dibuat', async () => {
    const popup = popupTiruan()
    openSpy.mockReturnValue(popup as unknown as Window)
    let selesai!: (v: { blob: Blob; nama: string }) => void
    ambil.mockReturnValue(new Promise((resolve) => (selesai = resolve)))
    const { wrapper, vm } = pasang()
    vm.buka(item())
    wrapper.unmount()
    selesai({ blob: new Blob(['x']), nama: 'SK.pdf' })
    await vi.waitFor(() => expect(popup.close).toHaveBeenCalled())
    expect(buatUrl).not.toHaveBeenCalled()
    expect(sel(popup, 'iframe')).toBeNull()
    expect(popup.document.body.textContent).toBe('Memuat berkas…')
  })
})

describe('Buka privat — isi popup pembungkus (CR-126 T-06)', () => {
  async function bukaDenganBerkas(nama: string, tipeBlob: string, overrides: Partial<ArsipBerkas> = {}) {
    const popup = popupTiruan()
    openSpy.mockReturnValue(popup as unknown as Window)
    ambil.mockResolvedValue({ blob: new Blob(['x'], { type: tipeBlob }), nama })
    const { wrapper, vm } = pasang()
    vm.buka(item(overrides))
    await vi.waitFor(() => expect(sel(popup, '[data-arsip="unduh"]')).not.toBeNull())
    return { popup, wrapper, vm }
  }

  it('PDF: tautan Unduh (download = nama aman, href = object URL), iframe penampil, judul = nama arsip', async () => {
    const { popup } = await bukaDenganBerkas('apa-saja.pdf', 'application/pdf', { nama_arsip: 'SK Jabatan' })
    const unduh = sel<HTMLAnchorElement>(popup, '[data-arsip="unduh"]')
    expect(unduh?.getAttribute('download')).toBe('SK Jabatan.pdf')
    expect(unduh?.getAttribute('href')).toBe('blob:uji/1')
    expect(unduh?.textContent).toBe('Unduh')
    const penampil = sel(popup, '[data-arsip="penampil"]')
    expect(penampil?.tagName).toBe('IFRAME')
    expect(penampil?.getAttribute('src')).toBe('blob:uji/1')
    expect(popup.document.title).toBe('SK Jabatan')
    expect(sel(popup, '[data-arsip="nama"]')?.textContent).toBe('SK Jabatan')
  })

  it('gambar: <img> penampil dan ekstensi dari nama berkas server', async () => {
    const { popup } = await bukaDenganBerkas('foto.JPG', 'image/jpeg', { nama_arsip: 'Foto KTP' })
    expect(sel(popup, '[data-arsip="penampil"]')?.tagName).toBe('IMG')
    expect(sel(popup, '[data-arsip="unduh"]')?.getAttribute('download')).toBe('Foto KTP.jpg')
  })

  it('nama arsip berisi HTML tampil sebagai teks (tanpa elemen b / img)', async () => {
    const { popup } = await bukaDenganBerkas('x.pdf', 'application/pdf', { nama_arsip: '<b>x</b><img src=x onerror=alert(1)>' })
    expect(popup.document.querySelector('b')).toBeNull()
    expect(popup.document.querySelector('img')).toBeNull()
    expect(sel(popup, '[data-arsip="nama"]')?.textContent).toBe('<b>x</b><img src=x onerror=alert(1)>')
    expect(popup.document.title).toBe('<b>x</b><img src=x onerror=alert(1)>')
  })

  it('jalur publik tidak berubah: window.open(url, arsip-popup, ...noopener), tanpa API dan tanpa object URL', () => {
    openSpy.mockReturnValue(null)
    const { vm } = pasang()
    vm.buka(item({ akses_berkas: 'publik', url_berkas: '/berkas/arsip/<nip>/SK.pdf' }))
    expect(openSpy).toHaveBeenCalledWith('/berkas/arsip/<nip>/SK.pdf', 'arsip-popup', 'width=600,height=600,noopener')
    expect(ambil).not.toHaveBeenCalled()
    expect(buatUrl).not.toHaveBeenCalled()
  })
})

describe('namaUnduhArsip — nama berkas unduhan yang aman', () => {
  it.each([
    ['SK Jabatan', null, 'x.pdf', 'SK Jabatan.pdf'],
    ['SK: Jabatan/2026 <A>?', null, 'x.pdf', 'SK Jabatan 2026 A.pdf'],
    ['a\\b|c*d"e', null, 'x.png', 'a b c d e.png'],
    ['  \u0007Kontrol\u0000 ', null, 'x.gif', 'Kontrol.gif'],
    ['SK.pdf', null, 'x.pdf', 'SK.pdf'],
    ['SK.PDF', null, 'x.pdf', 'SK.pdf'],
    ['', 'Nama Tampil', 'x.pdf', 'Nama Tampil.pdf'],
    ['', null, 'x.pdf', 'berkas.pdf'],
    ['///', '', 'x.jpeg', 'berkas.jpeg'],
    ['..', null, 'x.pdf', 'berkas.pdf'],
    ['Surat', null, 'tanpa-ekstensi', 'Surat'],
    ['Surat', null, 'x.docx', 'Surat'],
  ])('%j (display %j, server %s) → %s', (arsip, tampil, server, harapan) => {
    expect(namaUnduhArsip(arsip, tampil, server)).toBe(harapan)
  })

  it('dipotong per code point (emoji tidak terbelah) dan ekstensi tetap ada', () => {
    const hasil = namaUnduhArsip('\u{1F600}'.repeat(400), null, 'x.pdf')
    expect(hasil.endsWith('.pdf')).toBe(true)
    expect([...hasil.slice(0, -4)]).toHaveLength(150)
    expect(hasil).not.toMatch(/[\ud800-\udbff](?![\udc00-\udfff])/)
  })
})

describe('Buka privat — masa hidup object URL (CR-126 T-06)', () => {
  async function bukaFake(popup: ReturnType<typeof popupTiruan>, id = 123) {
    openSpy.mockReturnValue(popup as unknown as Window)
    ambil.mockResolvedValue({ blob: new Blob(['x']), nama: 'SK.pdf' })
    const host = pasang()
    host.vm.buka(item({ id_attachment: id }))
    await vi.advanceTimersByTimeAsync(0)
    return host
  }

  it('TIDAK dicabut selama popup terbuka (jauh melewati 60 detik)', async () => {
    vi.useFakeTimers()
    await bukaFake(popupTiruan())
    expect(buatUrl).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(10 * 60_000)
    expect(cabutUrl).not.toHaveBeenCalled()
  })

  it('dicabut sesudah popup ditutup dan selang pemeriksaan lewat; pemeriksaan berhenti (tanpa timer bocor)', async () => {
    vi.useFakeTimers()
    const popup = popupTiruan()
    await bukaFake(popup)
    expect(vi.getTimerCount()).toBe(1)
    popup.closed = true
    await vi.advanceTimersByTimeAsync(SELANG_PERIKSA_POPUP_MS - 1)
    expect(cabutUrl).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    expect(cabutUrl).toHaveBeenCalledTimes(1)
    expect(cabutUrl).toHaveBeenCalledWith('blob:uji/1')
    expect(vi.getTimerCount()).toBe(0)
    await vi.advanceTimersByTimeAsync(SELANG_PERIKSA_POPUP_MS * 3)
    expect(cabutUrl).toHaveBeenCalledTimes(1)
  })

  it('dicabut saat popup yang sama dipakai ulang untuk berkas lain; URL baru hidup', async () => {
    vi.useFakeTimers()
    const popup = popupTiruan()
    const { vm } = await bukaFake(popup)
    ambil.mockResolvedValue({ blob: new Blob(['y']), nama: 'Lain.pdf' })
    vm.buka(item({ id_attachment: 124, nama_arsip: 'Lain' }))
    expect(cabutUrl).toHaveBeenCalledWith('blob:uji/1') // sinkron saat klik, sebelum berkas baru siap
    await vi.advanceTimersByTimeAsync(0)
    expect(sel(popup, '[data-arsip="unduh"]')?.getAttribute('href')).toBe('blob:uji/2')
    expect(cabutUrl).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(1)
  })

  it('dicabut saat berkas publik dibuka di jendela yang sama', async () => {
    vi.useFakeTimers()
    const { vm } = await bukaFake(popupTiruan())
    openSpy.mockReturnValue(null)
    vm.buka(item({ id_attachment: 9, akses_berkas: 'publik', url_berkas: '/berkas/arsip/<nip>/SK.pdf' }))
    expect(cabutUrl).toHaveBeenCalledWith('blob:uji/1')
    expect(vi.getTimerCount()).toBe(0)
  })

  it('unmount halaman saat popup MASIH terbuka: object URL tetap (tautan Unduh hidup), dicabut kemudian saat popup ditutup', async () => {
    vi.useFakeTimers()
    const popup = popupTiruan()
    const { wrapper } = await bukaFake(popup)
    wrapper.unmount()
    expect(cabutUrl).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(1)
    popup.closed = true
    await vi.advanceTimersByTimeAsync(SELANG_PERIKSA_POPUP_MS)
    expect(cabutUrl).toHaveBeenCalledWith('blob:uji/1')
    expect(vi.getTimerCount()).toBe(0)
  })

  it('unmount halaman saat popup SUDAH tertutup: object URL dicabut dan timer berhenti', async () => {
    vi.useFakeTimers()
    const popup = popupTiruan()
    const { wrapper } = await bukaFake(popup)
    popup.closed = true
    wrapper.unmount()
    expect(cabutUrl).toHaveBeenCalledWith('blob:uji/1')
    expect(vi.getTimerCount()).toBe(0)
  })

  it('dicabut saat logout (cabutObjectUrlArsip dipanggil dari clearSession)', async () => {
    vi.useFakeTimers()
    await bukaFake(popupTiruan())
    cabutObjectUrlArsip()
    expect(cabutUrl).toHaveBeenCalledWith('blob:uji/1')
    expect(vi.getTimerCount()).toBe(0)
  })
})

describe('Buka privat — jendela bernama sama masih memuat dokumen lain (R-1)', () => {
  it('dokumen bukan about:blank (mis. berkas publik) dinavigasi dulu ke about:blank, baru diisi pembungkus', async () => {
    vi.useFakeTimers()
    const popup = popupTiruan('/berkas/arsip/<nip>/sk_uji.pdf')
    // Navigasi tiruan selesai asinkron: href baru tampil sesudah beberapa selang.
    popup.location.replace = vi.fn((alamat: string) => {
      setTimeout(() => void (popup.location.href = alamat), SELANG_SIAP_POPUP_MS * 2)
    })
    openSpy.mockReturnValue(popup as unknown as Window)
    ambil.mockResolvedValue({ blob: new Blob(['x']), nama: 'SK.pdf' })
    const { vm } = pasang()
    vm.buka(item())
    // Sinkron di klik: window.open dipanggil dan navigasi ke about:blank dimulai; belum ada yang ditulis ke dokumen lama.
    expect(openSpy).toHaveBeenCalledTimes(1)
    expect(popup.location.replace).toHaveBeenCalledWith('about:blank')
    expect(popup.document.body.textContent).toBe('')
    await vi.advanceTimersByTimeAsync(SELANG_SIAP_POPUP_MS)
    expect(sel(popup, '[data-arsip="unduh"]')).toBeNull() // berkas sudah tiba, tetapi dokumen belum about:blank
    expect(buatUrl).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(SELANG_SIAP_POPUP_MS * 3)
    expect(popup.location.href).toBe('about:blank')
    expect(sel(popup, '[data-arsip="unduh"]')?.getAttribute('href')).toBe('blob:uji/1')
    expect(vm.galat).toBeNull()
  })

  it('dokumen about:blank langsung diisi tanpa navigasi', async () => {
    vi.useFakeTimers()
    const popup = popupTiruan()
    openSpy.mockReturnValue(popup as unknown as Window)
    ambil.mockResolvedValue({ blob: new Blob(['x']), nama: 'SK.pdf' })
    const { vm } = pasang()
    vm.buka(item())
    await vi.advanceTimersByTimeAsync(0)
    expect(popup.location.replace).not.toHaveBeenCalled()
    expect(sel(popup, '[data-arsip="unduh"]')).not.toBeNull()
  })

  it('jendela tak kunjung menjadi about:blank → popup ditutup dan pesan galat, tanpa object URL', async () => {
    vi.useFakeTimers()
    const popup = popupTiruan('/berkas/arsip/<nip>/sk_uji.pdf')
    popup.location.replace = vi.fn() // navigasi tidak pernah selesai
    openSpy.mockReturnValue(popup as unknown as Window)
    ambil.mockResolvedValue({ blob: new Blob(['x']), nama: 'SK.pdf' })
    const { vm } = pasang()
    vm.buka(item())
    await vi.advanceTimersByTimeAsync(10_000)
    expect(popup.close).toHaveBeenCalledTimes(1)
    expect(buatUrl).not.toHaveBeenCalled()
    expect(vm.galat).toBe('Berkas tidak dapat dibuka. Coba lagi.')
    expect(vm.sedangDimuat).toBeNull()
  })
})

describe('Buka privat — kepemilikan jendela (R-2)', () => {
  it('hasil basi dari instans yang sudah di-unmount tidak menutup popup yang kini dipakai instans lain', async () => {
    const popup = popupTiruan()
    openSpy.mockReturnValue(popup as unknown as Window)
    let selesaiA!: (v: { blob: Blob; nama: string }) => void
    ambil.mockReturnValueOnce(new Promise((resolve) => (selesaiA = resolve)))
    const A = pasang()
    A.vm.buka(item({ id_attachment: 1 }))
    A.wrapper.unmount()
    // Instans B membuka berkas lain di jendela bernama sama (memakai ulang popup).
    ambil.mockReturnValueOnce(new Promise(() => undefined))
    const B = pasang()
    B.vm.buka(item({ id_attachment: 2 }))
    selesaiA({ blob: new Blob(['x']), nama: 'SK.pdf' })
    await vi.waitFor(() => expect(ambil).toHaveBeenCalledTimes(2))
    await Promise.resolve()
    await Promise.resolve()
    expect(popup.close).not.toHaveBeenCalled()
    expect(buatUrl).not.toHaveBeenCalled()
  })

  it('berkas publik dibuka di tengah pemuatan privat: hasil privat tidak menimpa dokumen publik', async () => {
    const popup = popupTiruan()
    openSpy.mockReturnValue(popup as unknown as Window)
    let selesai!: (v: { blob: Blob; nama: string }) => void
    ambil.mockReturnValue(new Promise((resolve) => (selesai = resolve)))
    const { vm } = pasang()
    vm.buka(item())
    vm.buka(item({ id_attachment: 9, akses_berkas: 'publik', url_berkas: '/berkas/arsip/<nip>/SK.pdf' }))
    selesai({ blob: new Blob(['x']), nama: 'SK.pdf' })
    await vi.waitFor(() => expect(vm.sedangDimuat).toBeNull())
    expect(buatUrl).not.toHaveBeenCalled()
    expect(sel(popup, '[data-arsip="unduh"]')).toBeNull()
    expect(popup.close).not.toHaveBeenCalled()
  })
})
