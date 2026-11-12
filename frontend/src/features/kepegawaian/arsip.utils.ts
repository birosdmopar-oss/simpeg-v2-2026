/** Fungsi murni halaman Arsip (F-10, MAKE-091). */
import {
  FOLDER_SEMUA,
  FOLDER_TANPA_JENIS,
  ID_BELUM_TERHUBUNG,
  type ArsipBerkasQuery,
  type ArsipFolder,
  type ArsipPegawaiRingkas,
} from './arsip.types'

/** "Dr. Budi Santoso, S.H." — gelar depan + nama, gelar belakang sesudah koma; semua teks apa adanya (tanpa HTML). */
export function namaPegawaiArsip(p: Pick<ArsipPegawaiRingkas, 'nama' | 'glr_depan' | 'glr_belakang'>): string {
  const depan = [p.glr_depan, p.nama].filter((x) => x !== null && x !== '').join(' ')
  return p.glr_belakang ? `${depan}, ${p.glr_belakang}` : depan
}

/** Segmen URL `:folder` untuk satu folder: id `jenis_rwy` atau `tanpa_jenis`. */
export function paramFolder(folder: Pick<ArsipFolder, 'id_jenis_rwy'>): string {
  return folder.id_jenis_rwy === null ? FOLDER_TANPA_JENIS : String(folder.id_jenis_rwy)
}

/**
 * Urutan tampil (AC1-09, F10-29): jenis biasa menurut `urutan` (lalu label), "Belum Terhubung" (kode 0) dan "Tanpa Jenis"
 * paling bawah. Server sudah mengirim urutan itu; ini menjaga FE tetap benar bila urutan respons berubah.
 */
export function urutkanFolder(folder: readonly ArsipFolder[]): ArsipFolder[] {
  const rank = (f: ArsipFolder): number => (f.id_jenis_rwy === null ? 2 : f.id_jenis_rwy === ID_BELUM_TERHUBUNG ? 1 : 0)
  return [...folder].sort(
    (a, b) => rank(a) - rank(b) || (a.urutan ?? Number.MAX_SAFE_INTEGER) - (b.urutan ?? Number.MAX_SAFE_INTEGER) || a.label.localeCompare(b.label, 'id'),
  )
}

/** Parameter `:folder` → filter `id_jenis_rwy` daftar berkas; `null` = parameter tidak sah. */
export function filterDariFolder(param: string): Pick<ArsipBerkasQuery, 'id_jenis_rwy'> | null {
  if (param === FOLDER_SEMUA) return {}
  if (param === FOLDER_TANPA_JENIS) return { id_jenis_rwy: FOLDER_TANPA_JENIS }
  return /^\d+$/.test(param) ? { id_jenis_rwy: Number(param) } : null
}

/** Label folder untuk breadcrumb/judul. */
export function labelFolder(param: string, folder: readonly ArsipFolder[]): string {
  if (param === FOLDER_SEMUA) return 'Semua berkas'
  return folder.find((f) => paramFolder(f) === param)?.label ?? 'Folder'
}
