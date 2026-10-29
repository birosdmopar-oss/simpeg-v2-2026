/**
 * Format tampilan laporan Presensi 5C yang dipakai beberapa halaman (PRD 5C §7.1). Tanpa `Date` dan tanpa zona waktu:
 * bulan `YYYY-MM` diubah langsung dari teks.
 */

const NAMA_BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']

/** `YYYY-MM` → "April 2024". Teks yang tidak sesuai dikembalikan apa adanya. */
export function labelBulan(bulan: string): string {
  const [y, m] = bulan.split('-')
  const nama = NAMA_BULAN[Number(m) - 1]
  return nama ? `${nama} ${y}` : bulan
}
