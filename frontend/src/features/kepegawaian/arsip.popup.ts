/**
 * Popup pratinjau berkas privat Arsip (F-10, CR-126 T-06): nama unduhan yang aman, isi halaman pembungkus (DOM API,
 * tanpa innerHTML / document.write berisi data) dan masa hidup object URL. Modul daun (tanpa impor aplikasi) agar bisa
 * dipanggil dari `auth.store` saat logout tanpa menarik komposable Arsip.
 *
 * Mengapa pembungkus: penampil PDF Chromium memberi nama UUID pada unduhan dari URL `blob:` yang dinavigasi langsung, dan
 * nama itu tidak bisa diubah dari blob. Tautan "Unduh" (`download="<nama aman>"`) di halaman pembungkus bernama arsip.
 * Tombol unduh bawaan penampil PDF tetap bernama UUID (batasan Chromium, keputusan Horii 16-11-2026).
 */

/** Selang pemeriksaan `popup.closed` untuk mencabut object URL sesudah popup ditutup. */
export const SELANG_PERIKSA_POPUP_MS = 3_000
const NAMA_CADANGAN = 'berkas'
const NAMA_MAKS = 150
/** Ekstensi tipe pratinjau (daftar tertutup F12). */
const EKSTENSI_PRATINJAU: readonly string[] = ['pdf', 'jpg', 'jpeg', 'png', 'gif']

function ekstensiNama(nama: string): string {
  const titik = nama.lastIndexOf('.')
  const ekstensi = titik >= 0 ? nama.slice(titik + 1).trim().toLowerCase() : ''
  return EKSTENSI_PRATINJAU.includes(ekstensi) ? ekstensi : ''
}

/** Karakter terlarang nama berkas (+ kontrol C0/DEL/C1 dan pemisah baris Unicode): diganti spasi. */
function terlarang(ch: string): boolean {
  const kode = ch.codePointAt(0) ?? 0
  return '\\/:*?"<>|'.includes(ch) || kode <= 0x1f || (kode >= 0x7f && kode <= 0x9f) || kode === 0x2028 || kode === 0x2029
}

function bersihkan(teks: string | null | undefined): string {
  const tanpaTerlarang = [...(teks ?? '')].map((ch) => (terlarang(ch) ? ' ' : ch)).join('')
  return tanpaTerlarang.replace(/\s+/g, ' ').trim().replace(/^\.+|\.+$/g, '').trim()
}

/**
 * Nama unduhan: `nama_arsip` (cadangan `display_name`, lalu "berkas") dibersihkan dari `\ / : * ? " < > |`, kontrol, dan
 * spasi tepi, dipotong per code point, lalu diberi ekstensi dari nama berkas server (daftar tertutup). Tidak digandakan
 * bila nama sudah berakhiran ekstensi yang sama.
 */
export function namaUnduhArsip(namaArsip: string | null | undefined, displayName: string | null | undefined, namaServer: string): string {
  const ekstensi = ekstensiNama(namaServer)
  let dasar = bersihkan(namaArsip) || bersihkan(displayName) || NAMA_CADANGAN
  if (ekstensi !== '' && dasar.toLowerCase().endsWith(`.${ekstensi}`)) dasar = dasar.slice(0, -(ekstensi.length + 1)).trim() || NAMA_CADANGAN
  dasar = [...dasar].slice(0, NAMA_MAKS).join('').trim() || NAMA_CADANGAN
  return ekstensi === '' ? dasar : `${dasar}.${ekstensi}`
}

export interface IsiPopupArsip {
  /** Nama arsip untuk judul dan teks (tampil sebagai teks, bukan HTML). */
  namaArsip: string
  /** Nilai atribut `download` (hasil `namaUnduhArsip`). */
  namaUnduh: string
  objectUrl: string
  /** Tipe pratinjau dari daftar tertutup (`tipePratinjau`). */
  tipe: string
}

function gaya(el: HTMLElement, aturan: Record<string, string>): void {
  for (const [k, v] of Object.entries(aturan)) el.style.setProperty(k, v)
}

/** Bangun isi popup dengan DOM API. Semua teks lewat `textContent`/atribut. */
export function isiPopupArsip(popup: Window, isi: IsiPopupArsip): void {
  const doc = popup.document
  doc.title = isi.namaArsip
  doc.body.replaceChildren()
  gaya(doc.body, { margin: '0', display: 'flex', 'flex-direction': 'column', height: '100vh', 'font-family': 'system-ui, sans-serif' })

  const bar = doc.createElement('div')
  gaya(bar, { display: 'flex', 'align-items': 'center', gap: '12px', padding: '8px 12px', 'border-bottom': '1px solid #cbd5e1', background: '#f8fafc' })
  const nama = doc.createElement('span')
  nama.setAttribute('data-arsip', 'nama')
  nama.textContent = isi.namaArsip
  gaya(nama, { flex: '1', 'min-width': '0', overflow: 'hidden', 'text-overflow': 'ellipsis', 'white-space': 'nowrap', 'font-size': '14px', color: '#0f172a' })
  const unduh = doc.createElement('a')
  unduh.setAttribute('data-arsip', 'unduh')
  unduh.setAttribute('href', isi.objectUrl)
  unduh.setAttribute('download', isi.namaUnduh)
  unduh.textContent = 'Unduh'
  gaya(unduh, { padding: '6px 14px', 'border-radius': '6px', background: '#1d4ed8', color: '#ffffff', 'text-decoration': 'none', 'font-size': '14px', 'font-weight': '600' })
  bar.append(nama, unduh)
  doc.body.append(bar)

  if (isi.tipe === 'application/pdf') {
    const penampil = doc.createElement('iframe')
    penampil.setAttribute('data-arsip', 'penampil')
    penampil.setAttribute('src', isi.objectUrl)
    penampil.setAttribute('title', isi.namaArsip)
    gaya(penampil, { flex: '1', width: '100%', border: '0' })
    doc.body.append(penampil)
  } else if (isi.tipe.startsWith('image/')) {
    const wadah = doc.createElement('div')
    gaya(wadah, { flex: '1', overflow: 'auto', display: 'flex', 'align-items': 'center', 'justify-content': 'center', padding: '8px' })
    const gambar = doc.createElement('img')
    gambar.setAttribute('data-arsip', 'penampil')
    gambar.setAttribute('src', isi.objectUrl)
    gambar.setAttribute('alt', isi.namaArsip)
    gaya(gambar, { 'max-width': '100%', 'max-height': '100%', 'object-fit': 'contain' })
    wadah.append(gambar)
    doc.body.append(wadah)
  } else {
    const info = doc.createElement('p')
    info.textContent = 'Pratinjau tidak tersedia untuk jenis berkas ini. Gunakan Unduh.'
    gaya(info, { padding: '12px', 'font-size': '14px' })
    doc.body.append(info)
  }
}

// ---- Masa hidup object URL (satu popup bernama sama → satu object URL aktif) -------------------------------------

let aktif: { url: string; timer: ReturnType<typeof setInterval> } | null = null

/** Cabut object URL popup yang aktif (popup dipakai ulang, popup ditutup, halaman di-unmount, logout). */
export function cabutObjectUrlArsip(): void {
  if (aktif === null) return
  clearInterval(aktif.timer)
  URL.revokeObjectURL(aktif.url)
  aktif = null
}

/** Catat object URL yang tampil di `popup`; yang sebelumnya dicabut, dan dicabut otomatis sesudah popup ditutup. */
export function catatObjectUrlArsip(url: string, popup: Window): void {
  cabutObjectUrlArsip()
  const timer = setInterval(() => {
    let tertutup = true
    try {
      tertutup = popup.closed
    } catch {
      // Tidak terbaca: anggap tertutup agar tidak bocor.
    }
    if (tertutup) cabutObjectUrlArsip()
  }, SELANG_PERIKSA_POPUP_MS)
  aktif = { url, timer }
}
