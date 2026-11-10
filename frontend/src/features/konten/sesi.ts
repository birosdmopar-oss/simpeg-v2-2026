/**
 * Penjaga "sekali per sesi login" untuk popup beranda (Q7-02): popup tidak muncul ulang bila HomeView di-mount ulang
 * dalam sesi yang sama. Memakai sessionStorage (dibungkus try/catch — bisa tidak tersedia) dengan cadangan memori.
 * `resetSesiKonten()` dipanggil saat logout/login baru (titik sambung oleh pemilik auth) agar login berikutnya
 * menampilkan popup lagi, sesuai legacy (tampil tiap login).
 */
const KUNCI = 'simpeg.konten.popup.sesi'
let diMemori = false

export function popupSudahDitampilkan(): boolean {
  if (diMemori) return true
  try {
    return sessionStorage.getItem(KUNCI) === '1'
  } catch {
    return false
  }
}

export function tandaiPopupDitampilkan(): void {
  diMemori = true
  try {
    sessionStorage.setItem(KUNCI, '1')
  } catch {
    /* penyimpanan tidak tersedia — penjaga memori tetap berlaku di sesi halaman ini */
  }
}

export function resetSesiKonten(): void {
  diMemori = false
  try {
    sessionStorage.removeItem(KUNCI)
  } catch {
    /* abaikan */
  }
}
