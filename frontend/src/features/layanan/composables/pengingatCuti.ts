/** Penanda "pengingat cuti sudah tampil pada sesi tab ini" (PRD §6.4.7); gagal akses penyimpanan → tidak fatal. */
const KUNCI = 'layanan-pengingat-cuti-tampil'

export function sudahDitampilkan(): boolean {
  try {
    return window.sessionStorage.getItem(KUNCI) === '1'
  } catch {
    return false
  }
}

export function tandaiDitampilkan(): void {
  try {
    window.sessionStorage.setItem(KUNCI, '1')
  } catch {
    // Penyimpanan tidak tersedia → pengingat bisa tampil lagi pada muat berikutnya.
  }
}

/** Dipanggil saat Keluar supaya login berikutnya di tab yang sama menampilkan pengingat lagi. */
export function resetPengingatCuti(): void {
  try {
    window.sessionStorage.removeItem(KUNCI)
  } catch {
    // abaikan
  }
}
