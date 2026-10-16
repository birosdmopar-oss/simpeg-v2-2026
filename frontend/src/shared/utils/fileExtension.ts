/**
 * Ekstensi nama berkas untuk pemeriksaan sisi klien (CR-055 Q4R2-I2). Sama dengan PHP `pathinfo(..., EXTENSION)` yang
 * dipakai `ValidasiBerkas` server: bagian sesudah titik TERAKHIR, kosong bila tidak ada titik (`sk`) atau titik di akhir
 * (`sk.`). Bukan `split('.').pop()`, yang mengembalikan nama itu sendiri untuk nama tanpa titik.
 */
export function fileExtension(name: string): string {
  const titik = name.lastIndexOf('.')
  return titik === -1 ? '' : name.slice(titik + 1).toLowerCase()
}

/**
 * Pesan galat jenis berkas, atau null bila ekstensinya diperbolehkan. Nama tanpa ekstensi ditolak dengan pesan
 * tersendiri (server CR-054 menolaknya 422 "Jenis isi berkas tidak sesuai dengan ekstensinya.").
 */
export function fileExtensionError(name: string, allowed: readonly string[]): string | null {
  const ext = fileExtension(name)
  const daftar = allowed.map((e) => `.${e}`).join(', ')
  if (ext === '') return `Nama berkas tidak memiliki ekstensi. Gunakan berkas berekstensi ${daftar}.`
  if (!allowed.includes(ext)) return `Jenis file .${ext} tidak diperbolehkan.`
  return null
}
