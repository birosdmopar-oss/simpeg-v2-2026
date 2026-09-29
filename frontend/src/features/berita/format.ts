/** Format tanggal berita "21 Agustus 2025, 21:44" dari ISO tanpa zona (data contoh) — tanpa Date agar bebas zona waktu. */
const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']

export function formatTanggalWaktu(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(iso)
  if (!m) return iso
  return `${Number(m[3])} ${BULAN[Number(m[2]) - 1]} ${m[1]}, ${m[4]}:${m[5]}`
}

export function formatTanggal(iso: string): string {
  return formatTanggalWaktu(iso).split(',')[0]
}
