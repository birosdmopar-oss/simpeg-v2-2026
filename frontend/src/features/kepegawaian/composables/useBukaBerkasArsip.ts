/**
 * Aksi "Buka" berkas di halaman Arsip (F-10, MAKE-091; PRD F10-18, F10-37, §9.3). Jalur dipilih dari `akses_berkas` item,
 * bukan dari saklar global:
 *  - `publik` (berkas legacy): `window.open(url_berkas, 'arsip-popup', 'width=600,height=600,noopener')`, tanpa API.
 *  - `privat` (berkas unggahan v2) + `pratinjau`: popup dibuka SINKRON di handler klik (sesudah `await` akan diblokir
 *    pemblokir popup) tanpa `noopener` (dengan `noopener` `window.open` mengembalikan null sehingga lokasi popup tidak bisa
 *    diisi), lalu `opener = null`. Berkas diambil lewat `ambilBerkas` (klien `api`, interceptor refresh token ikut), blob
 *    dibuat ulang dengan tipe dari DAFTAR TERTUTUP menurut ekstensi nama berkas — bukan tipe respons mentah, karena object
 *    URL `blob:` mewarisi origin aplikasi (HTML/SVG tidak boleh berjalan di sana). Object URL dicabut sesudah jeda tetap
 *    dan saat komponen di-unmount.
 *  - `privat` + `unduh`: tanpa popup; `ambilBerkas` lalu `simpanBerkas` (nama dari `Content-Disposition`).
 * Semua galat menutup popup. 429/404/403 tampil sebagai pesan di halaman (`galat`), bukan JSON mentah. 401 sesudah refresh
 * gagal dan 403 `password_change_required` ditangani interceptor (login / ganti password), tanpa pesan tambahan.
 */
import { onBeforeUnmount, ref } from 'vue'

import { isApiError, PASSWORD_CHANGE_REQUIRED_CODE } from '@/lib/axios'

import type { ArsipBerkas } from '../arsip.types'
import { describeApiError } from '../services/apiErrors'
import { ambilBerkas, simpanBerkas } from '../services/berkasUnduhan'

/** Nama jendela popup (sama untuk jalur publik dan privat; membuka berkas lain memakai ulang jendela yang sama). */
export const NAMA_POPUP_ARSIP = 'arsip-popup'
const UKURAN_POPUP = 'width=600,height=600'
/** Jeda pencabutan object URL pratinjau (tanpa deteksi selesai-muat penampil PDF; PRD F10-37 (5)). */
export const JEDA_CABUT_MS = 60_000

export const PESAN_POPUP_DIBLOKIR = 'Popup diblokir browser. Izinkan popup untuk situs ini.'
export const PESAN_TERLALU_BANYAK = 'Terlalu banyak membuka berkas arsip. Coba lagi dalam satu menit.'
const PESAN_UMUM = 'Berkas tidak dapat dibuka. Coba lagi.'
const PESAN_MEMUAT = 'Memuat berkas…'

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
  if (error.status === 403 && dikenal && badan.code === PASSWORD_CHANGE_REQUIRED_CODE) return null
  // Body JSON yang terbaca (diurai `ambilBerkas`) membawa pesan server; bila tidak terbaca pakai pesan baku.
  const dariServer = dikenal && typeof badan.message === 'string' ? badan.message : ''
  if (dariServer !== '') return dariServer
  return error.status === 429 ? PESAN_TERLALU_BANYAK : describeApiError(error).message
}

export function useBukaBerkasArsip() {
  /** Pesan galat tampil di halaman (`UiNotice`); null = tidak ada. */
  const galat = ref<string | null>(null)
  /** `id_attachment` berkas privat yang sedang dimuat (item Buka-nya `disabled`). */
  const sedangDimuat = ref<number | null>(null)

  const timerCabut = new Map<string, ReturnType<typeof setTimeout>>()

  function jadwalkanCabut(url: string): void {
    timerCabut.set(
      url,
      setTimeout(() => {
        timerCabut.delete(url)
        URL.revokeObjectURL(url)
      }, JEDA_CABUT_MS),
    )
  }

  onBeforeUnmount(() => {
    for (const [url, timer] of timerCabut) {
      clearTimeout(timer)
      URL.revokeObjectURL(url)
    }
    timerCabut.clear()
  })

  /** Boleh dibuka bila URL ada dan tidak sedang memuat berkas privat lain. */
  function bisaDibuka(item: ArsipBerkas): boolean {
    return item.url_berkas !== null && item.akses_berkas !== null && sedangDimuat.value !== item.id_attachment
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

  async function bukaPrivat(item: ArsipBerkas, url: string, popup: Window | null): Promise<void> {
    sedangDimuat.value = item.id_attachment
    try {
      const berkas = await ambilBerkas(url, `arsip-${item.id_attachment}`)
      if (popup === null) {
        simpanBerkas(berkas)
        return
      }
      const blob = new Blob([berkas.blob], { type: tipePratinjau(berkas.nama) })
      const objectUrl = URL.createObjectURL(blob)
      jadwalkanCabut(objectUrl)
      popup.location.href = objectUrl
    } catch (error) {
      popup?.close()
      galat.value = pesanGalat(error)
    } finally {
      sedangDimuat.value = null
    }
  }

  /** Handler klik ⋮ → Buka. Tidak melempar; galat masuk `galat`. Harus dipanggil sinkron dari event klik. */
  function buka(item: ArsipBerkas): void {
    galat.value = null
    const url = item.url_berkas
    if (url === null || item.akses_berkas === null || sedangDimuat.value === item.id_attachment) return

    if (item.akses_berkas === 'publik') {
      window.open(url, NAMA_POPUP_ARSIP, `${UKURAN_POPUP},noopener`)
      return
    }

    let popup: Window | null = null
    if (item.jenis_buka !== 'unduh') {
      popup = window.open('', NAMA_POPUP_ARSIP, UKURAN_POPUP)
      if (popup === null) {
        galat.value = PESAN_POPUP_DIBLOKIR
        return
      }
      isiPopupMemuat(popup)
    }
    void bukaPrivat(item, url, popup)
  }

  return { galat, sedangDimuat, bisaDibuka, buka }
}
