/**
 * Klien Laporan Tukin (MAKE-048; README `Controllers/Api/Presensi` "Laporan Tukin MAKE-041"). `api` sudah membuka
 * amplop `{status, data}` (service membaca `res.data` langsung, CR-059) dan MySQLi mengirim angka/flag sebagai string,
 * jadi semua dinormalkan di sini:
 *   GET presensi/tukin/pegawai[-il]?unit_satker=&jenis_pegawai[]=&search=&page=&per_page=   daftar (IL: hanya search + paginasi)
 *   GET presensi/tukin/laporan/{nip}?bulan=            laporan per pegawai (PDF: `/pdf`)
 */
import { ambilBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'
import { api, isApiError } from '@/lib/axios'

import type { HarianLaporan, ParamLaporan, StatusHarian } from '../types.laporan'
import type { DaftarPegawaiTukin, LaporanTukinPegawai, PegawaiTukin } from '../types.laporan.tukin'

const SUMBER = ['bkn', 'bawaan'] as const

const teks = (v: unknown): string => (v === null || v === undefined ? '' : String(v))
const teksOrNull = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : null)
const angka = (v: unknown, bawaan = 0): number => {
  const n = Number(v)
  return v === null || v === undefined || v === '' || !Number.isFinite(n) ? bawaan : n
}
const rekam = (v: unknown): Record<string, unknown> => (typeof v === 'object' && v !== null && !Array.isArray(v) ? (v as Record<string, unknown>) : {})
const daftar = (v: unknown): Record<string, unknown>[] => (Array.isArray(v) ? (v as Record<string, unknown>[]) : [])

export function normalisasiPegawai(r: Record<string, unknown>): PegawaiTukin {
  const pd = rekam(r.pendidikan)
  return {
    nip: teks(r.nip),
    nama: teks(r.nama),
    gol_ruang: teks(r.gol_ruang),
    pangkat: teks(r.pangkat),
    jabatan: teks(r.jabatan),
    eselon: teks(r.eselon),
    unit: teks(r.unit),
    satker: teks(r.satker),
    pendidikan: { strata: teks(pd.strata), jurusan: teks(pd.jurusan), institusi: teks(pd.institusi) },
    jenis_pegawai: teks(r.jenis_pegawai),
  }
}

export function normalisasiHarian(r: Record<string, unknown>): HarianLaporan {
  return {
    tanggal: teks(r.tanggal),
    status: teks(r.status) as StatusHarian,
    kode: Array.isArray(r.kode) ? r.kode.map(teks) : [],
    jam_masuk: teksOrNull(r.jam_masuk),
    jam_pulang: teksOrNull(r.jam_pulang),
    potongan: teks(r.potongan),
    keterangan: teks(r.keterangan),
    kategori_konket: r.kategori_konket === null || r.kategori_konket === undefined ? null : angka(r.kategori_konket),
  }
}

function peta<T>(v: unknown, ubah: (x: unknown) => T): Record<string, T> {
  return Object.fromEntries(Object.entries(rekam(v)).map(([k, x]) => [k, ubah(x)]))
}

export function normalisasiLaporan(d: Record<string, unknown>): LaporanTukinPegawai {
  const id = rekam(d.identitas)
  const per = rekam(d.periode)
  const peny = d.penyesuaian === null || d.penyesuaian === undefined ? null : rekam(d.penyesuaian)
  return {
    identitas: {
      nip: teks(id.nip),
      nama: teks(id.nama),
      gol_ruang: teksOrNull(id.gol_ruang),
      satker: teks(id.satker),
      jabatan: teks(id.jabatan),
      kelas_jabatan: id.kelas_jabatan === null || id.kelas_jabatan === undefined ? null : angka(id.kelas_jabatan),
      predikat_skp: id.predikat_skp === null || id.predikat_skp === undefined ? null : teks(id.predikat_skp),
    },
    periode: {
      awal: teks(per.awal),
      akhir: teks(per.akhir),
      dihitung_sampai: teks(per.dihitung_sampai),
      sumber: SUMBER.includes(per.sumber as 'bkn') ? (per.sumber as 'bkn' | 'bawaan') : 'bawaan',
    },
    harian: daftar(d.harian).map(normalisasiHarian),
    jumlah: peta(d.jumlah, (x) => angka(x)),
    rincian: peta(d.rincian, teks),
    total_presensi: teks(d.total_presensi),
    total_skp: teks(d.total_skp),
    total_potongan: teks(d.total_potongan),
    alasan: teksOrNull(d.alasan),
    penyesuaian: peny === null ? null : { persen: teks(peny.persen), alasan: teks(peny.alasan) },
    keterangan: teks(d.keterangan),
  }
}

function normalisasiDaftar(d: Record<string, unknown>): DaftarPegawaiTukin {
  return {
    items: daftar(d.items).map(normalisasiPegawai),
    total: angka(d.total),
    page: angka(d.page, 1),
    per_page: angka(d.per_page, 25),
    bulan_tersedia: daftar(d.bulan_tersedia).map((b) => ({ bulan: teks(b.bulan), label: teks(b.label) })),
  }
}

/** Parameter daftar: bulan tidak dikirim (daftar tidak bergantung bulan; `bulan_tersedia` ada di respons). */
function paramDaftar(p: Omit<ParamLaporan, 'bulan'>, il: boolean): Record<string, unknown> {
  const q: Record<string, unknown> = { page: p.page, per_page: p.per_page }
  if (p.search) q.search = p.search
  if (!il && p.unit_satker !== undefined) q.unit_satker = p.unit_satker
  if (!il && p.jenis_pegawai && p.jenis_pegawai.length > 0) q.jenis_pegawai = p.jenis_pegawai
  return q
}

/** Kode galat terkendali envelope error (`code`, mis. `konfigurasi_tukin_tidak_lengkap`); null bila tidak ada. */
export function kodeGalat(error: unknown): string | null {
  if (!isApiError(error)) return null
  const data = error.original.response?.data as { code?: unknown } | undefined
  return typeof data?.code === 'string' ? data.code : null
}

export const laporanTukinService = {
  async daftar(p: Omit<ParamLaporan, 'bulan'>, il = false): Promise<DaftarPegawaiTukin> {
    const { data } = await api.get<Record<string, unknown>>(il ? '/presensi/tukin/pegawai-il' : '/presensi/tukin/pegawai', { params: paramDaftar(p, il) })
    return normalisasiDaftar(data)
  },

  async laporan(nip: string, bulan: string): Promise<LaporanTukinPegawai> {
    const { data } = await api.get<Record<string, unknown>>(`/presensi/tukin/laporan/${encodeURIComponent(nip)}`, { params: { bulan } })
    return normalisasiLaporan(data)
  },

  /** PDF per pegawai; nama berkas dari server (`tukin_<nip>_<YYYY-MM>.pdf`). */
  pdf(nip: string, bulan: string): Promise<BerkasUnduhan> {
    return ambilBerkas(`/presensi/tukin/laporan/${encodeURIComponent(nip)}/pdf`, `tukin_${nip}_${bulan}`, { bulan })
  },
}

const PESAN_KODE: Record<string, string> = {
  konfigurasi_tukin_tidak_lengkap: 'Konfigurasi tarif Tukin belum lengkap. Hubungi admin.',
  rentang_puasa_ganda: 'Rentang puasa pada konfigurasi bertumpuk. Hubungi admin.',
  data_tukin_belum_tersedia: 'Data Tukin pegawai ini belum tersedia untuk bulan tersebut.',
  data_tukin_tidak_sah: 'Laporan Tukin pegawai ini tidak dapat dihitung.',
  periode_tidak_didukung: 'Laporan periode sebelum April 2024 tersedia di arsip SIMPEG lama.',
}

/** Pesan siap tampil untuk galat laporan: `code` terkendali, lalu status HTTP; selain itu pesan umum. */
export function pesanGalatLaporan(error: unknown): string {
  const code = kodeGalat(error)
  if (code !== null && PESAN_KODE[code] !== undefined) return PESAN_KODE[code]
  if (isApiError(error)) {
    if (error.status === 403) return 'Anda tidak berhak melihat laporan pegawai ini.'
    if (error.status === 404) return 'Pegawai tidak ditemukan.'
    if (error.status === 422) return error.errors?.bulan?.[0] ?? 'Periksa kembali bulan yang dipilih.'
    if (error.isNetworkError) return 'Tidak dapat terhubung ke server. Periksa koneksi Anda.'
  }
  return 'Terjadi kesalahan. Coba lagi.'
}
