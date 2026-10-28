/**
 * Klien Presensi (D-02 online, D-03 manual, data presensi admin F5-15; PRD Fase 5A §6.4–6.5) lewat `api`
 * (`@/lib/axios`, ADR-022):
 *   GET    presensi/saya/hari-ini                         status hari ini (tanggal/jam lokal server, jendela, lokasi)
 *   POST   presensi/cek-lokasi        {latitude, longitude, akurasi?}   putusan geofence (tidak menulis)
 *   POST   presensi/masuk | pulang    {latitude?, longitude?, akurasi?}
 *   GET    presensi/saya?bulan=YYYY-MM&page=&per_page=   riwayat pribadi
 *   GET    presensi/pegawai?search=&id_unit=&id_satker=&page=&per_page=   daftar pegawai (lingkup server)
 *   GET    presensi/pegawai/{nip}?tgl_mulai=&tgl_akhir=&page=&per_page=   detail presensi pegawai
 *   POST   presensi/manual | PUT/DELETE presensi/manual/{nip}/{tgl}      akun khusus
 * Interceptor `@/lib/axios` sudah membuka amplop {status, data}: `response.data` = payload (CR-059). Koordinat selalu di
 * body, tidak pernah di query string. Angka & boolean (MySQLi → string) dinormalkan di sini; jendela waktu, geofence,
 * dan hak akses ditentukan server (ADR-025).
 */
import { api } from '@/lib/axios'
import { masterOptions } from '@/shared/services/masterOptions'

import type {
  BarisPresensi,
  BarisPresensiAdmin,
  CekLokasi,
  DaftarPegawaiPresensi,
  DetailPresensiPegawai,
  HasilRekamPresensi,
  IsianPresensiManual,
  PresensiHariIni,
  RiwayatPresensi,
  SumberPresensi,
} from '../types'

const teks = (v: unknown): string | null => (v === null || v === undefined || v === '' ? null : String(v))
const bool = (v: unknown): boolean => v === true || v === 1 || v === '1' || v === 'true'
const angka = (v: unknown): number => (v === null || v === undefined || v === '' ? 0 : Number(v))
const angkaAtauNull = (v: unknown): number | null => (v === null || v === undefined || v === '' ? null : Number(v))
const sumber = (v: unknown): SumberPresensi => (v === null || v === undefined || v === '' ? null : Number(v) === 2 ? 2 : 1)

function baris(b: BarisPresensi): BarisPresensi {
  return { tgl: String(b.tgl), jam_masuk: teks(b.jam_masuk), jam_pulang: teks(b.jam_pulang), sumber: sumber(b.sumber) }
}

/** Koordinat perangkat untuk body permintaan (tanpa akurasi bila tidak ada). */
export interface Koordinat {
  latitude: number
  longitude: number
  akurasi?: number | null
}

function bodyKoordinat(k: Koordinat | null): Record<string, number> {
  if (!k) return {}
  const body: Record<string, number> = { latitude: k.latitude, longitude: k.longitude }
  if (k.akurasi !== null && k.akurasi !== undefined && Number.isFinite(k.akurasi)) body.akurasi = Math.round(k.akurasi * 100) / 100
  return body
}

function jamKosong(v: string): string | null {
  return v.trim() === '' ? null : v.trim()
}

export interface OpsiMaster {
  value: string
  label: string
}

export const presensiService = {
  async hariIni(): Promise<PresensiHariIni> {
    const { data } = await api.get<PresensiHariIni>('/presensi/saya/hari-ini')
    return {
      tanggal_lokal: String(data.tanggal_lokal),
      jam_lokal: String(data.jam_lokal),
      zonasi_menit: angka(data.zonasi_menit),
      jendela: {
        masuk: [String(data.jendela?.masuk?.[0] ?? ''), String(data.jendela?.masuk?.[1] ?? '')],
        pulang: [String(data.jendela?.pulang?.[0] ?? ''), String(data.jendela?.pulang?.[1] ?? '')],
      },
      boleh_masuk: bool(data.boleh_masuk),
      boleh_pulang: bool(data.boleh_pulang),
      presensi: data.presensi ? { jam_masuk: teks(data.presensi.jam_masuk), jam_pulang: teks(data.presensi.jam_pulang) } : null,
      wajib_geofence: bool(data.wajib_geofence),
      lokasi: (data.lokasi ?? []).map((l) => ({
        id_lokasi_presensi: angka(l.id_lokasi_presensi),
        nama_lokasi: String(l.nama_lokasi),
        latitude: angka(l.latitude),
        longitude: angka(l.longitude),
        radius: angka(l.radius),
      })),
    }
  },

  async cekLokasi(k: Koordinat): Promise<CekLokasi> {
    const { data } = await api.post<CekLokasi>('/presensi/cek-lokasi', bodyKoordinat(k))
    const t = data.lokasi_terdekat
    return {
      wajib_geofence: bool(data.wajib_geofence),
      dalam_radius: bool(data.dalam_radius),
      lokasi_terdekat: t ? { id: angka(t.id), nama: String(t.nama), radius: angka(t.radius), jarak_m: angka(t.jarak_m) } : null,
    }
  },

  async rekam(jenis: 'masuk' | 'pulang', k: Koordinat | null): Promise<HasilRekamPresensi> {
    const { data } = await api.post<HasilRekamPresensi>(`/presensi/${jenis}`, bodyKoordinat(k))
    return { tgl: String(data.tgl), jam_masuk: teks(data.jam_masuk), jam_pulang: teks(data.jam_pulang), jarak_m: angkaAtauNull(data.jarak_m) }
  },

  async riwayatSaya(bulan: string, page = 1, perPage = 20): Promise<RiwayatPresensi> {
    const params: Record<string, string | number> = { page, per_page: perPage }
    if (bulan) params.bulan = bulan
    const { data } = await api.get<RiwayatPresensi>('/presensi/saya', { params })
    return { bulan: String(data.bulan), items: (data.items ?? []).map(baris), total: angka(data.total), page: angka(data.page) || 1, per_page: angka(data.per_page) || perPage }
  },

  async daftarPegawai(q: { search?: string; id_unit?: string; id_satker?: string; page?: number; per_page?: number }): Promise<DaftarPegawaiPresensi> {
    const params: Record<string, string | number> = {}
    if (q.search && q.search.trim() !== '') params.search = q.search.trim()
    if (q.id_unit) params.id_unit = q.id_unit
    if (q.id_satker) params.id_satker = q.id_satker
    if (q.page) params.page = q.page
    if (q.per_page) params.per_page = q.per_page
    const { data } = await api.get<DaftarPegawaiPresensi>('/presensi/pegawai', { params })
    return {
      items: (data.items ?? []).map((p) => ({ nip: String(p.nip), nama: String(p.nama ?? ''), unit: teks(p.unit), satker: teks(p.satker) })),
      total: angka(data.total),
      page: angka(data.page) || 1,
      per_page: angka(data.per_page) || 20,
      can_kelola_manual: bool(data.can_kelola_manual),
    }
  },

  async detailPegawai(nip: string, q: { tgl_mulai?: string; tgl_akhir?: string; page?: number }): Promise<DetailPresensiPegawai> {
    const params: Record<string, string | number> = {}
    if (q.tgl_mulai) params.tgl_mulai = q.tgl_mulai
    if (q.tgl_akhir) params.tgl_akhir = q.tgl_akhir
    if (q.page) params.page = q.page
    const { data } = await api.get<DetailPresensiPegawai>(`/presensi/pegawai/${encodeURIComponent(nip)}`, { params })
    return {
      pegawai: { nip: String(data.pegawai?.nip ?? nip), nama: String(data.pegawai?.nama ?? '') },
      items: (data.items ?? []).map(
        (b): BarisPresensiAdmin => ({
          ...baris(b),
          nip: String(b.nip),
          lokasi_masuk: teks(b.lokasi_masuk),
          lokasi_pulang: teks(b.lokasi_pulang),
          aksi: { ubah: bool(b.aksi?.ubah), hapus: bool(b.aksi?.hapus) },
        }),
      ),
      total: angka(data.total),
      page: angka(data.page) || 1,
      per_page: angka(data.per_page) || 100,
      can_create: bool(data.can_create),
    }
  },

  async tambahManual(isian: IsianPresensiManual): Promise<void> {
    await api.post('/presensi/manual', { nip: isian.nip.trim(), tgl: isian.tgl, jam_masuk: jamKosong(isian.jam_masuk), jam_pulang: jamKosong(isian.jam_pulang) })
  },

  async ubahManual(nip: string, tgl: string, jam: { jam_masuk: string; jam_pulang: string }): Promise<void> {
    await api.put(`/presensi/manual/${encodeURIComponent(nip)}/${encodeURIComponent(tgl)}`, { jam_masuk: jamKosong(jam.jam_masuk), jam_pulang: jamKosong(jam.jam_pulang) })
  },

  async hapusManual(nip: string, tgl: string): Promise<void> {
    await api.delete(`/presensi/manual/${encodeURIComponent(nip)}/${encodeURIComponent(tgl)}`)
  },

  async opsiUnit(): Promise<OpsiMaster[]> {
    return (await masterOptions('unit')).map((o) => ({ value: String(o.id), label: String(o.nama) }))
  },

  async opsiSatker(idUnit: string): Promise<OpsiMaster[]> {
    return (await masterOptions('satker', idUnit)).map((o) => ({ value: String(o.id), label: String(o.nama) }))
  },
}

const HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu']
const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']

/** `YYYY-MM-DD` → { hari: "Senin", tanggal: "27 Oktober 2026" } dari kalender lokal (tanpa zona/`toISOString`). */
export function formatTanggal(tgl: string): { hari: string; tanggal: string } {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(tgl)
  if (!m) return { hari: '', tanggal: tgl }
  const [, y, mo, d] = m
  const hari = HARI[new Date(Number(y), Number(mo) - 1, Number(d)).getDay()] ?? ''
  return { hari, tanggal: `${Number(d)} ${BULAN[Number(mo) - 1] ?? ''} ${y}` }
}

/** "07:15:00" → "07:15"; null → "-". */
export function formatJam(jam: string | null): string {
  if (!jam) return '-'
  return jam.length >= 5 ? jam.slice(0, 5) : jam
}

export const LABEL_SUMBER: Record<'1' | '2' | 'null', string> = { '1': 'Online', '2': 'Manual', null: 'Impor' }
export function labelSumber(s: SumberPresensi): string {
  return s === null ? LABEL_SUMBER.null : LABEL_SUMBER[String(s) as '1' | '2']
}
