/**
 * Peek otomatis kartu sapaan Vanya sekali per sesi browser untuk semua perangkat (`dev:VP:413-422`, PRD §7.10.3).
 * sessionStorage dibungkus try/catch (bisa tidak tersedia / melempar galat) dengan cadangan memori, pola
 * `features/konten/sesi.ts`. Tidak ikut `resetChatbot()`: penanda ini milik sesi browser, bukan sesi login.
 */
const KUNCI = 'simpeg.chatbot.vanya.peek'
let diMemori = false

export function peekSudahTampil(): boolean {
  if (diMemori) return true
  try {
    return sessionStorage.getItem(KUNCI) === '1'
  } catch {
    return false
  }
}

export function tandaiPeekTampil(): void {
  diMemori = true
  try {
    sessionStorage.setItem(KUNCI, '1')
  } catch {
    /* penyimpanan tidak tersedia — penjaga memori tetap berlaku di halaman ini */
  }
}

/** Hanya untuk test. */
export function _resetPeek(): void {
  diMemori = false
}
