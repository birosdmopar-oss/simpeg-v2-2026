/**
 * Klien Kelola Slip Gaji (D-10/D-11 jalur admin, role 1, 3; PRD Fase 5A §6.2) lewat `api` (`@/lib/axios`, ADR-022):
 *   GET  slip-gaji?periode=&unit_satker=&status_view=&search=&page=&per_page=   monitoring (periode wajib)
 *   GET  slip-gaji/{id}                                                         rincian 28 kolom (tidak mengubah status)
 *   PUT  slip-gaji/{id}                                                         koreksi 28 kolom (desimal titik)
 *   POST slip-gaji/{id}/buka-ulang                                              reset status & token aktif
 *   GET  slip-gaji/{id}/pdf                                                     PDF kapan saja
 *   POST slip-gaji/impor/pratinjau (multipart)                                  pratinjau impor
 *   POST slip-gaji/impor/pratinjau/{token}/commit                               simpan ke database
 *   GET  master/satker/options                                                  opsi filter satker (role 1)
 * Interceptor `@/lib/axios` sudah membuka amplop {status, data}: `response.data` = payload (CR-059). Nominal dibiarkan
 * string desimal server; angka & boolean lain (MySQLi → string) dinormalkan di sini. Lingkup role 3, status, aksi, dan
 * validasi impor seluruhnya dari server (ADR-025).
 */
import { api } from '@/lib/axios'
import { masterOptions } from '@/shared/services/masterOptions'

import { ambilBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'

import type {
  AksiSlipAdmin,
  BarisPratinjau,
  DaftarMonitoring,
  GalatImpor,
  HasilImpor,
  MonitoringQuery,
  PratinjauImpor,
  RencanaImpor,
  SlipAdmin,
  SlipMonitoring,
  StatusBarisImpor,
} from '../types'

const teks = (v: unknown): string | null => (v === null || v === undefined || v === '' ? null : String(v))
const bool = (v: unknown): boolean => v === true || v === 1 || v === '1' || v === 'true'
const angka = (v: unknown): number => (v === null || v === undefined || v === '' ? 0 : Number(v))
const nominal = (v: unknown): string => (v === null || v === undefined || v === '' ? '0.00' : String(v))
const nominalSemua = (o: Record<string, unknown> | undefined): Record<string, string> =>
  Object.fromEntries(Object.entries(o ?? {}).map(([k, v]) => [k, nominal(v)]))

function aksi(a: Partial<Record<keyof AksiSlipAdmin, unknown>> | undefined): AksiSlipAdmin {
  return { lihat: bool(a?.lihat), cetak: bool(a?.cetak), koreksi: bool(a?.koreksi), buka_ulang: bool(a?.buka_ulang) }
}

function monitoring(r: SlipMonitoring): SlipMonitoring {
  return {
    id: Number(r.id),
    nip: String(r.nip),
    nama: teks(r.nama),
    unit_satker: String(r.unit_satker ?? ''),
    periode: String(r.periode),
    dibuka: bool(r.dibuka),
    viewed_at: teks(r.viewed_at),
    viewed_at_wib: teks(r.viewed_at_wib),
    viewed_ip: teks(r.viewed_ip),
    aksi: aksi(r.aksi),
  }
}

function slipAdmin(s: SlipAdmin): SlipAdmin {
  const hari = s.uang_makan?.hari
  return {
    id: Number(s.id),
    nip: String(s.nip),
    nama: teks(s.nama),
    periode: String(s.periode),
    label: String(s.label),
    dibuka: bool(s.dibuka),
    viewed_at: teks(s.viewed_at),
    viewed_at_wib: teks(s.viewed_at_wib),
    viewed_ip: teks(s.viewed_ip),
    rincian: nominalSemua(s.rincian),
    ringkasan: nominalSemua(s.ringkasan as unknown as Record<string, unknown>) as unknown as SlipAdmin['ringkasan'],
    uang_makan: {
      nominal: nominal(s.uang_makan?.nominal),
      hari: hari === null || hari === undefined || (hari as unknown) === '' ? null : Number(hari),
      periode_kehadiran: teks(s.uang_makan?.periode_kehadiran),
      tersedia: bool(s.uang_makan?.tersedia),
    },
    aksi: aksi(s.aksi),
    pegawai: {
      nip: String(s.pegawai?.nip ?? s.nip),
      nama: teks(s.pegawai?.nama) ?? teks(s.nama),
      pangkat: teks(s.pegawai?.pangkat) ?? '-',
      jabatan: teks(s.pegawai?.jabatan) ?? '-',
      unit: teks(s.pegawai?.unit) ?? '-',
      satker: teks(s.pegawai?.satker) ?? '-',
    },
  }
}

function galat(list: GalatImpor[] | undefined): GalatImpor[] {
  return (list ?? []).map((g) => ({ kode: String(g.kode), pesan: String(g.pesan), sheet: String(g.sheet ?? ''), baris: angka(g.baris) }))
}

function baris(b: BarisPratinjau): BarisPratinjau {
  return {
    nomor: angka(b.nomor),
    sheet: String(b.sheet ?? ''),
    nip: teks(b.nip),
    nama: teks(b.nama),
    periode: teks(b.periode),
    status: String(b.status) as StatusBarisImpor,
    rencana_bila_timpa: String(b.rencana_bila_timpa) as RencanaImpor,
    rencana_bila_tidak_timpa: String(b.rencana_bila_tidak_timpa) as RencanaImpor,
    galat: galat(b.galat),
  }
}

export interface OpsiSatker {
  value: string
  label: string
}

export const slipGajiAdminService = {
  async daftar(q: MonitoringQuery): Promise<DaftarMonitoring> {
    const params: Record<string, string | number> = { periode: q.periode }
    if (q.unit_satker) params.unit_satker = q.unit_satker
    if (q.status_view && q.status_view !== 'semua') params.status_view = q.status_view
    if (q.search && q.search.trim() !== '') params.search = q.search.trim()
    if (q.page) params.page = q.page
    if (q.per_page) params.per_page = q.per_page
    const { data } = await api.get<DaftarMonitoring>('/slip-gaji', { params })
    return {
      items: (data.items ?? []).map(monitoring),
      total: angka(data.total),
      page: angka(data.page) || 1,
      per_page: angka(data.per_page) || 20,
      ringkasan: { jumlah: angka(data.ringkasan?.jumlah), dibuka: angka(data.ringkasan?.dibuka), belum_dibuka: angka(data.ringkasan?.belum_dibuka) },
      periode_tersedia: (data.periode_tersedia ?? []).map(String),
    }
  },

  async detail(id: number): Promise<SlipAdmin> {
    const { data } = await api.get<SlipAdmin>(`/slip-gaji/${id}`)
    return slipAdmin(data)
  },

  async koreksi(id: number, nilai: Record<string, string>): Promise<SlipAdmin> {
    const { data } = await api.put<SlipAdmin>(`/slip-gaji/${id}`, nilai)
    return slipAdmin(data)
  },

  async bukaUlang(id: number): Promise<void> {
    await api.post(`/slip-gaji/${id}/buka-ulang`)
  },

  pdf(id: number, nip: string, periode: string): Promise<BerkasUnduhan> {
    return ambilBerkas(`/slip-gaji/${id}/pdf`, `slip_gaji_${nip}_${periode}`)
  },

  /** `filter` `YYYY-MM` opsional → `filter_bulan` + `filter_tahun` (server menolak bila hanya salah satu). */
  async pratinjau(berkas: File, filter?: string | null): Promise<PratinjauImpor> {
    const form = new FormData()
    form.append('berkas', berkas)
    const m = filter ? /^(\d{4})-(\d{2})$/.exec(filter) : null
    if (m) {
      form.append('filter_bulan', String(Number(m[2])))
      form.append('filter_tahun', m[1] as string)
    }
    const { data } = await api.post<PratinjauImpor>('/slip-gaji/impor/pratinjau', form)
    return {
      token_pratinjau: String(data.token_pratinjau),
      kedaluwarsa_at: teks(data.kedaluwarsa_at),
      timpa_bawaan: data.timpa_bawaan === undefined ? true : bool(data.timpa_bawaan),
      filter: teks(data.filter),
      ringkasan: { valid: angka(data.ringkasan?.valid), duplikat: angka(data.ringkasan?.duplikat), galat: angka(data.ringkasan?.galat) },
      baris: (data.baris ?? []).map(baris),
    }
  },

  async commit(token: string, timpa: boolean): Promise<HasilImpor> {
    const { data } = await api.post<HasilImpor>(`/slip-gaji/impor/pratinjau/${encodeURIComponent(token)}/commit`, { timpa })
    return {
      disisip: angka(data.disisip),
      diperbarui: angka(data.diperbarui),
      dilewati: angka(data.dilewati),
      gagal: angka(data.gagal),
      baris: (data.baris ?? []).map((b) => ({ nip: teks(b.nip), periode: teks(b.periode), hasil: String(b.hasil) as RencanaImpor, galat: galat(b.galat) })),
    }
  },

  async opsiSatker(): Promise<OpsiSatker[]> {
    return (await masterOptions('satker')).map((o) => ({ value: String(o.id), label: String(o.nama) }))
  },
}

/** Label bulan `YYYY-MM` → "Oktober 2026" (nama bulan Indonesia, tanpa Date/zona waktu). */
const NAMA_BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']
export function labelPeriode(kunci: string | null): string {
  const m = kunci ? /^(\d{4})-(\d{2})$/.exec(kunci) : null
  const nama = m ? NAMA_BULAN[Number(m[2]) - 1] : undefined
  return m && nama ? `${nama} ${m[1]}` : (kunci ?? '-')
}
