/**
 * Cache respons folder Arsip per NIP (F-10, MAKE-091). `GET pegawai/{nip}/arsip` memeriksa keberadaan berkas SEMUA baris
 * pegawai (berat, PRD §8.1), sedangkan halaman tabel hanya memerlukannya untuk identitas + label folder (breadcrumb /
 * judul). ArsipPage mengisi cache; ArsipFolderPage memakainya bila NIP sama dan memanggil API hanya bila kosong (mis. URL
 * folder dibuka langsung). Hanya satu NIP disimpan, dan cache dibuang saat memuat ulang halaman folder utama.
 */
import type { ArsipFolderResponse } from './arsip.types'

let terakhir: { nip: string; data: ArsipFolderResponse } | null = null

export function simpanFolderArsip(nip: string, data: ArsipFolderResponse): void {
  terakhir = { nip, data }
}

export function ambilFolderArsip(nip: string): ArsipFolderResponse | null {
  return terakhir !== null && terakhir.nip === nip ? terakhir.data : null
}

export function kosongkanFolderArsip(): void {
  terakhir = null
}
