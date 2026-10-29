/**
 * Klien Rekap Kehadiran & Rekap Uang Makan (5B MAKE-042, PRD 5B §6.2, §7.4; PRD 5C §7.5) lewat `api` (`@/lib/axios`,
 * ADR-022). Semua hanya baca:
 *   GET presensi/rekap-kehadiran?bulan=&unit_satker=&jenis_pegawai[]=&page=&per_page=        role 1, 3, 4
 *   GET presensi/rekap-kehadiran/xlsx | xlsx-harian?bulan=&unit_satker=&jenis_pegawai[]=      unduhan (seluruh lingkup)
 *   GET presensi/uang-makan/rekap?bulan=&unit_satker=&jenis_pegawai[]=&page=&per_page=       role 1, 3 (baris NIP + tanggal)
 *   GET presensi/uang-makan/rekap/xlsx?…                                                      unduhan
 *   GET presensi/uang-makan/rekap-il?bulan=&page=&per_page=  dan  …/rekap-il/xlsx            role 1 (Instansi Lain)
 * Interceptor `@/lib/axios` sudah membuka amplop {status, data} (CR-059). Angka dari MySQLi dinormalkan di sini; nominal
 * tidak ada di rekap uang makan (F5B-20). Lingkup, aturan hari, dan jam kerja ditentukan server (ADR-025).
 */
import { api } from '@/lib/axios'
import { ambilBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'

const angka = (v: unknown): number => (v === null || v === undefined || v === '' ? 0 : Number(v))

/** Satu baris rekap kehadiran per pegawai (`items[]` ringkas, PRD 5B §7.4.1 + kolom `tb` [V2]). */
export interface BarisRekapKehadiran {
  nip: string
  nama: string
  jabatan: string | null
  hari_kerja: number
  hadir: number
  tl: number
  psw: number
  tk: number
  sakit: number
  dinas_luar: number
  lain_lain: number
  cuti: number
  tidak_hadir: number
  tb: number
}

/** Satu baris uang makan: satu per hari dibayar (F5B-2, F5B-20). */
export interface BarisUangMakan {
  nip: string
  /** `YYYY-MM-DD`. */
  tgl_absen: string
}

export interface DaftarRekap<T> {
  items: T[]
  total: number
  page: number
  per_page: number
  bulan: string
  lingkup: unknown
}

/** Parameter bersama rekap. `page`/`per_page` hanya untuk daftar (unduhan memakai lingkup penuh). */
export interface QueryRekap {
  bulan: string
  unit_satker?: string
  jenis_pegawai?: string[]
  page?: number
  per_page?: number
}

type Params = Record<string, string | number | string[]>

function paramRekap(q: QueryRekap, halaman: boolean): Params {
  const params: Params = { bulan: q.bulan }
  if (q.unit_satker) params.unit_satker = q.unit_satker
  if (q.jenis_pegawai && q.jenis_pegawai.length > 0) params.jenis_pegawai = [...q.jenis_pegawai]
  if (halaman) {
    params.page = q.page ?? 1
    params.per_page = q.per_page ?? 25
  }
  return params
}

function daftarDari<T>(data: DaftarRekap<T>, bentuk: (b: T) => T, perPage: number): DaftarRekap<T> {
  return {
    items: (data.items ?? []).map(bentuk),
    total: angka(data.total),
    page: angka(data.page) || 1,
    per_page: angka(data.per_page) || perPage,
    bulan: String(data.bulan ?? ''),
    lingkup: data.lingkup,
  }
}

const kehadiranBaris = (b: BarisRekapKehadiran): BarisRekapKehadiran => ({
  nip: String(b.nip),
  nama: String(b.nama ?? ''),
  jabatan: b.jabatan === null || b.jabatan === undefined || b.jabatan === '' ? null : String(b.jabatan),
  hari_kerja: angka(b.hari_kerja),
  hadir: angka(b.hadir),
  tl: angka(b.tl),
  psw: angka(b.psw),
  tk: angka(b.tk),
  sakit: angka(b.sakit),
  dinas_luar: angka(b.dinas_luar),
  lain_lain: angka(b.lain_lain),
  cuti: angka(b.cuti),
  tidak_hadir: angka(b.tidak_hadir),
  tb: angka(b.tb),
})

const uangMakanBaris = (b: BarisUangMakan): BarisUangMakan => ({ nip: String(b.nip), tgl_absen: String(b.tgl_absen) })

export const rekapKehadiranService = {
  async daftar(q: QueryRekap): Promise<DaftarRekap<BarisRekapKehadiran>> {
    const { data } = await api.get<DaftarRekap<BarisRekapKehadiran>>('/presensi/rekap-kehadiran', { params: paramRekap(q, true) })
    return daftarDari(data, kehadiranBaris, q.per_page ?? 25)
  },

  /** `bentuk` = `xlsx` (ringkas) atau `xlsx-harian` (grid harian). */
  async unduh(bentuk: 'xlsx' | 'xlsx-harian', q: QueryRekap): Promise<BerkasUnduhan> {
    return ambilBerkas(`/presensi/rekap-kehadiran/${bentuk}`,`rekap_kehadiran_${bentuk === 'xlsx' ? '' : 'harian_'}${q.bulan}`, paramRekap(q, false))
  },
}

export const rekapUangMakanService = {
  async daftar(q: QueryRekap, il: boolean): Promise<DaftarRekap<BarisUangMakan>> {
    const { data } = await api.get<DaftarRekap<BarisUangMakan>>(il ? '/presensi/uang-makan/rekap-il' : '/presensi/uang-makan/rekap', {
      params: il ? { bulan: q.bulan, page: q.page ?? 1, per_page: q.per_page ?? 25 } : paramRekap(q, true),
    })
    return daftarDari(data, uangMakanBaris, q.per_page ?? 25)
  },

  async unduh(q: QueryRekap, il: boolean): Promise<BerkasUnduhan> {
    return ambilBerkas(il ?'/presensi/uang-makan/rekap-il/xlsx' : '/presensi/uang-makan/rekap/xlsx', `rekap_uang_makan_${il ? 'il_' : ''}${q.bulan}`, il ? { bulan: q.bulan } : paramRekap(q, false))
  },
}
