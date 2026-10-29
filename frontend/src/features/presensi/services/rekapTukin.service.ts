/**
 * Klien Rekap Tukin (MAKE-049; README `Controllers/Api/Presensi` "Laporan Tukin MAKE-041"). `api` sudah membuka amplop
 * `{status, data}` (service membaca `res.data` langsung, CR-059); angka/flag MySQLi berupa string dinormalkan di sini:
 *   GET presensi/tukin/rekap?bulan=&unit_satker=&jenis_pegawai[]=&page=&per_page=   rekap (+ `/xlsx`, `/pdf`)
 *   GET presensi/tukin/rekap-il?bulan=                                                rekap Instansi Lain (+ `/xlsx`)
 * Ekspor XLSX = seluruh lingkup tanpa paginasi; PDF hanya unit/satker ≤ batas baris server (selain itu 422 `unit_satker`).
 */
import { ambilBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'
import { api, isApiError } from '@/lib/axios'

import type { GalatBaris, ParamLaporan } from '../types.laporan'
import type { BarisRekapTukin, FormatEksporRekap, PenguranganRekap, RekapTukin } from '../types.laporan.rekap'

const teks = (v: unknown): string => (v === null || v === undefined ? '' : String(v))
const teksOrNull = (v: unknown): string | null => (v === null || v === undefined || v === '' ? null : String(v))
const angka = (v: unknown, bawaan = 0): number => {
  const n = Number(v)
  return v === null || v === undefined || v === '' || !Number.isFinite(n) ? bawaan : n
}
const rekam = (v: unknown): Record<string, unknown> => (typeof v === 'object' && v !== null && !Array.isArray(v) ? (v as Record<string, unknown>) : {})

const KOLOM: (keyof PenguranganRekap)[] = ['TL', 'PSW', 'TK', 'TA', 'CUTI', 'TB']

export function normalisasiBaris(r: Record<string, unknown>): BarisRekapTukin {
  const peng = r.pengurangan === null || r.pengurangan === undefined ? null : rekam(r.pengurangan)
  const peny = r.penyesuaian === null || r.penyesuaian === undefined ? null : rekam(r.penyesuaian)
  const g = r.galat === null || r.galat === undefined ? null : rekam(r.galat)
  const galat: GalatBaris | null = g === null ? null : { code: teks(g.code), message: teks(g.message) }
  return {
    nip: teks(r.nip),
    nama: teks(r.nama),
    unit_es1: teks(r.unit_es1),
    unit_es2: teks(r.unit_es2),
    pengurangan: peng === null ? null : (Object.fromEntries(KOLOM.map((k) => [k, teks(peng[k])])) as unknown as PenguranganRekap),
    total_presensi: teksOrNull(r.total_presensi),
    total_skp: teksOrNull(r.total_skp),
    total_potongan: teksOrNull(r.total_potongan),
    keterangan: teks(r.keterangan),
    penyesuaian: peny === null ? null : { persen: teks(peny.persen), alasan: teks(peny.alasan) },
    galat,
  }
}

export function normalisasiRekap(d: Record<string, unknown>): RekapTukin {
  const per = rekam(d.periode)
  const l = d.lingkup === null || d.lingkup === undefined ? null : rekam(d.lingkup)
  return {
    items: (Array.isArray(d.items) ? (d.items as Record<string, unknown>[]) : []).map(normalisasiBaris),
    total: angka(d.total),
    page: angka(d.page, 1),
    per_page: angka(d.per_page, 25),
    bulan: teks(d.bulan),
    periode: { awal: teks(per.awal), akhir: teks(per.akhir) },
    lingkup: l === null ? null : { tipe: teks(l.tipe), id: l.id === null || l.id === undefined ? null : angka(l.id), kode: teks(l.kode), nama: teks(l.nama) },
    jumlah_galat: angka(d.jumlah_galat),
  }
}

/** Parameter query: Instansi Lain hanya `bulan` (+ paginasi); selain itu unit_satker dan jenis_pegawai[] bila terisi. */
function paramRekap(p: ParamLaporan, il: boolean, ekspor: boolean): Record<string, unknown> {
  const q: Record<string, unknown> = { bulan: p.bulan }
  if (!ekspor) {
    q.page = p.page
    q.per_page = p.per_page
  }
  if (!il && p.unit_satker !== undefined) q.unit_satker = p.unit_satker
  if (!il && p.jenis_pegawai && p.jenis_pegawai.length > 0) q.jenis_pegawai = p.jenis_pegawai
  return q
}

export const rekapTukinService = {
  async daftar(p: ParamLaporan, il = false): Promise<RekapTukin> {
    const { data } = await api.get<Record<string, unknown>>(il ? '/presensi/tukin/rekap-il' : '/presensi/tukin/rekap', { params: paramRekap(p, il, false) })
    return normalisasiRekap(data)
  },

  /** XLSX seluruh lingkup (nama dari server: `laporan_tukin_<lingkup>_<YYYY-MM>.xlsx`) atau PDF (hanya bukan IL). */
  ekspor(format: FormatEksporRekap, p: ParamLaporan, il = false): Promise<BerkasUnduhan> {
    const dasar = `laporan_tukin_${p.bulan}`
    const rute = il ? '/presensi/tukin/rekap-il/xlsx' : `/presensi/tukin/rekap/${format}`
    // `jenis_pegawai` tetap array (axios menyerialkan `jenis_pegawai[]=1&jenis_pegawai[]=6`); tipe `ambilBerkas` hanya melebar ke string.
    return ambilBerkas(rute, dasar, paramRekap(p, il, true) as unknown as Record<string, string>)
  },
}

const PESAN_KODE: Record<string, string> = {
  periode_tidak_didukung: 'Laporan periode sebelum April 2024 tersedia di arsip SIMPEG lama.',
  konfigurasi_tukin_tidak_lengkap: 'Konfigurasi tarif Tukin belum lengkap. Hubungi admin.',
}

/** Pesan siap tampil untuk galat rekap/ekspor: kolom 422 (unit_satker/bulan/jenis_pegawai) dari server, lalu status HTTP. */
export function pesanGalatRekap(error: unknown): string {
  if (!isApiError(error)) return 'Terjadi kesalahan. Coba lagi.'
  const code = (error.original.response?.data as { code?: unknown } | undefined)?.code
  if (typeof code === 'string' && PESAN_KODE[code] !== undefined) return PESAN_KODE[code]
  if (error.status === 422) {
    for (const k of ['unit_satker', 'bulan', 'jenis_pegawai']) {
      const pesan = error.errors?.[k]?.[0]
      if (pesan) return pesan
    }
    return 'Periksa kembali filter yang dipilih.'
  }
  if (error.status === 403) return 'Anda tidak berhak melihat rekap ini.'
  if (error.isNetworkError) return 'Tidak dapat terhubung ke server. Periksa koneksi Anda.'
  return 'Terjadi kesalahan. Coba lagi.'
}
