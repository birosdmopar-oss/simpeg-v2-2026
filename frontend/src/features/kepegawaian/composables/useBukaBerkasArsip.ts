/**
 * Aksi "Buka" berkas di halaman Arsip (F-10, MAKE-091 + CR-126 T-06; PRD F10-18, F10-37, §9.3). Jalur dipilih dari
 * `akses_berkas` item, bukan dari saklar global:
 *  - `publik` (berkas legacy): `window.open(url_berkas, 'arsip-popup', 'width=600,height=600,noopener')`, tanpa API
 *    (object URL popup privat sebelumnya dicabut: jendela bernama sama dipakai ulang).
 *  - `privat` (berkas unggahan v2) + `pratinjau`: popup dibuka SINKRON di handler klik (sesudah `await` akan diblokir
 *    pemblokir popup) tanpa `noopener` (dengan `noopener` `window.open` mengembalikan null sehingga isi popup tidak bisa
 *    diisi), lalu `opener = null`. Berkas diambil lewat `ambilBerkas` (klien `api`, interceptor refresh token ikut), blob
 *    dibuat ulang dengan tipe dari DAFTAR TERTUTUP menurut ekstensi nama berkas — bukan tipe respons mentah, karena object
 *    URL `blob:` mewarisi origin aplikasi (HTML/SVG tidak boleh berjalan di sana). Popup TIDAK dinavigasi ke `blob:`
 *    (penampil PDF Chromium akan menamai unduhan dengan UUID); isinya halaman pembungkus (`arsip.popup.ts`, DOM API):
 *    nama arsip, tautan "Unduh" bernama arsip, dan penampil iframe (PDF) / img. Object URL hidup selama popup terbuka,
 *    dicabut saat popup ditutup (diperiksa berkala), dipakai ulang untuk berkas lain, halaman di-unmount, atau logout.
 *  - `privat` + `unduh`: tanpa popup; `ambilBerkas` lalu `simpanBerkas` (nama dari `Content-Disposition`).
 * Semua galat menutup popup. 429/404/403 tampil sebagai pesan di halaman (`galat`), bukan JSON mentah. 401 sesudah refresh
 * gagal ditangani interceptor (login) tanpa pesan tambahan. 403 `password_change_required` pada unduhan blob tidak memicu
 * handler interceptor (body masih Blob saat `code` dibaca), jadi pesan server / cadangan ditampilkan di halaman.
 */
import { onBeforeUnmount, ref } from 'vue'

import { isApiError, PASSWORD_CHANGE_REQUIRED_CODE } from '@/lib/axios'

import {
  cabutObjectUrlArsip,
  cabutObjectUrlBilaPopupTertutup,
  catatObjectUrlArsip,
  isiPopupArsip,
  namaUnduhArsip,
  popupAdalahBlank,
  siapkanPopupBlank,
} from '../arsip.popup'
import type { ArsipBerkas } from '../arsip.types'
import { describeApiError } from '../services/apiErrors'
import { ambilBerkas, simpanBerkas } from '../services/berkasUnduhan'

/** Nama jendela popup (sama untuk jalur publik dan privat; membuka berkas lain memakai ulang jendela yang sama). */
export const NAMA_POPUP_ARSIP = 'arsip-popup'
const UKURAN_POPUP = 'width=600,height=600'

export const PESAN_POPUP_DIBLOKIR = 'Popup diblokir browser. Izinkan popup untuk situs ini.'
export const PESAN_TERLALU_BANYAK = 'Terlalu banyak membuka berkas arsip. Coba lagi dalam satu menit.'
const PESAN_UMUM = 'Berkas tidak dapat dibuka. Coba lagi.'
const PESAN_MEMUAT = 'Memuat berkas…'
export const PESAN_GANTI_PASSWORD = 'Anda harus mengganti password terlebih dahulu.'

/** Daftar tertutup tipe pratinjau menurut ekstensi (huruf kecil); selain ini selalu `application/octet-stream`. */
const TIPE_PRATINJAU: Readonly<Record<string, string>> = {
  pdf: 'application/pdf',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  gif: 'image/gif',
}
const TIPE_LAIN = 'application/octet-stream'

/** Tipe blob pratinjau dari ekstensi nama berkas; tak dikenal → `application/octet-stream`. */
export function tipePratinjau(nama: string): string {
  const titik = nama.lastIndexOf('.')
  const ekstensi = titik >= 0 ? nama.slice(titik + 1).trim().toLowerCase() : ''
  return TIPE_PRATINJAU[ekstensi] ?? TIPE_LAIN
}

/** Pesan galat buka berkas: `null` = tanpa pesan (ditangani interceptor). */
function pesanGalat(error: unknown): string | null {
  if (!isApiError(error)) return PESAN_UMUM
  if (error.status === 401) return null
  const badan = error.original.response?.data as { code?: unknown; message?: unknown } | undefined
  const dikenal = badan !== undefined && badan !== null && typeof badan === 'object'
  // Body JSON yang terbaca (diurai `ambilBerkas`) membawa pesan server; bila tidak terbaca pakai pesan baku.
  const dariServer = dikenal && typeof badan.message === 'string' ? badan.message : ''
  // Interceptor membaca `code` saat body masih Blob, jadi handler ganti password tidak jalan untuk unduhan blob:
  // tampilkan pesannya agar klik Buka tidak diam.
  if (error.status === 403 && dikenal && badan.code === PASSWORD_CHANGE_REQUIRED_CODE) return dariServer !== '' ? dariServer : PESAN_GANTI_PASSWORD
  if (dariServer !== '') return dariServer
  return error.status === 429 ? PESAN_TERLALU_BANYAK : describeApiError(error).message
}

/**
 * Pemilik jendela `arsip-popup` (satu jendela bernama sama untuk SEMUA instans): hanya permintaan yang masih pemilik yang
 * boleh mengisi atau menutupnya. Pembukaan baru (privat/publik) mengambil alih, sehingga hasil basi — termasuk dari
 * instans yang sudah di-unmount — tidak menutup/menimpa popup milik permintaan lain.
 */
let pemilikPopup = 0

export function useBukaBerkasArsip() {
  /** Pesan galat tampil di halaman (`UiNotice`); null = tidak ada. */
  const galat = ref<string | null>(null)
  /** `id_attachment` berkas privat yang sedang dimuat. Selama terisi SEMUA item Buka privat `disabled` (satu popup bernama sama). */
  const sedangDimuat = ref<number | null>(null)

  /** Token permintaan privat: hanya permintaan terakhir (dan komponen yang masih terpasang) boleh mengisi popup / galat. */
  let urutan = 0

  onBeforeUnmount(() => {
    urutan++ // hasil permintaan yang masih berjalan menjadi basi
    cabutObjectUrlBilaPopupTertutup()
  })

  /** Boleh dibuka bila URL ada dan (untuk berkas privat) tidak ada berkas privat lain yang sedang dimuat. */
  function bisaDibuka(item: ArsipBerkas): boolean {
    if (item.url_berkas === null || item.akses_berkas === null) return false
    return item.akses_berkas === 'publik' || sedangDimuat.value === null
  }

  function isiPopupMemuat(popup: Window): void {
    try {
      popup.opener = null
      popup.document.title = PESAN_MEMUAT
      popup.document.body.textContent = PESAN_MEMUAT
    } catch {
      // Popup lintas-origin / belum siap: tidak kritis, lokasi tetap bisa diisi.
    }
  }

  async function bukaPrivat(item: ArsipBerkas, url: string, popup: Window | null, siap: Promise<boolean>): Promise<void> {
    const ini = ++urutan
    const milik = popup === null ? 0 : pemilikPopup
    const pemilik = (): boolean => popup !== null && milik === pemilikPopup
    let tiba = false
    sedangDimuat.value = item.id_attachment
    // Jendela yang masih memuat dokumen lain baru bisa ditulisi sesudah dinavigasi ke about:blank (R-1).
    if (popup !== null) {
      void siap.then((ok) => {
        if (ok && !tiba && ini === urutan && pemilik()) isiPopupMemuat(popup)
      })
    }
    try {
      const berkas = await ambilBerkas(url, `arsip-${item.id_attachment}`)
      tiba = true
      const popupSiap = popup === null ? true : await siap
      if (ini !== urutan || (popup !== null && !pemilik())) {
        // Basi (komponen di-unmount, atau jendela sudah diambil alih): jangan buat object URL yang tak akan dicabut,
        // dan tutup popup HANYA bila masih milik permintaan ini.
        if (pemilik()) popup?.close()
        return
      }
      if (popup === null) {
        simpanBerkas(berkas)
        return
      }
      if (!popupSiap) throw new Error('popup tidak siap')
      const tipe = tipePratinjau(berkas.nama)
      const objectUrl = URL.createObjectURL(new Blob([berkas.blob], { type: tipe }))
      catatObjectUrlArsip(objectUrl, popup)
      const namaArsip = item.nama_arsip.trim() !== '' ? item.nama_arsip : (item.display_name ?? '')
      isiPopupArsip(popup, {
        namaArsip: namaArsip !== '' ? namaArsip : 'Berkas',
        namaUnduh: namaUnduhArsip(item.nama_arsip, item.display_name, berkas.nama),
        objectUrl,
        tipe,
      })
    } catch (error) {
      if (pemilik()) popup?.close()
      if (ini === urutan) galat.value = pesanGalat(error)
    } finally {
      if (ini === urutan) sedangDimuat.value = null
    }
  }

  /** Handler klik ⋮ → Buka. Tidak melempar; galat masuk `galat`. Harus dipanggil sinkron dari event klik. */
  function buka(item: ArsipBerkas): void {
    if (!bisaDibuka(item)) return
    galat.value = null
    const url = item.url_berkas
    if (url === null) return

    if (item.akses_berkas === 'publik') {
      cabutObjectUrlArsip() // jendela bernama sama dinavigasi ke URL publik: isi blob sebelumnya hilang
      pemilikPopup++ // hasil privat yang masih berjalan tidak boleh menimpa/menutup dokumen publik ini
      window.open(url, NAMA_POPUP_ARSIP, `${UKURAN_POPUP},noopener`)
      return
    }

    let popup: Window | null = null
    let siap: Promise<boolean> = Promise.resolve(true)
    if (item.jenis_buka !== 'unduh') {
      cabutObjectUrlArsip() // popup bernama sama dipakai ulang untuk berkas ini
      popup = window.open('', NAMA_POPUP_ARSIP, UKURAN_POPUP)
      if (popup === null) {
        galat.value = PESAN_POPUP_DIBLOKIR
        return
      }
      pemilikPopup++
      // Dokumen jendela bukan about:blank (mis. berkas publik sebelumnya): navigasi dimulai sinkron di klik ini.
      siap = siapkanPopupBlank(popup)
      if (popupAdalahBlank(popup)) isiPopupMemuat(popup)
    }
    void bukaPrivat(item, url, popup, siap)
  }

  return { galat, sedangDimuat, bisaDibuka, buka }
}
