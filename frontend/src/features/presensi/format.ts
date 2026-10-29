/**
 * Format tampilan laporan Presensi (hanya penyajian; angka dihitung server, ADR-025).
 */

/** "0.40" → "0,40%" (koma desimal Indonesia); kosong → "-". */
export function persenId(nilai: string | null | undefined): string {
  if (nilai === null || nilai === undefined || nilai === '') return '-'
  return `${nilai.replace('.', ',')}%`
}
