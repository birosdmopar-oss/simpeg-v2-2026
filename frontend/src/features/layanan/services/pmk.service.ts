/**
 * Klien Modul C — PMK (PRD Fase 4 §5.4, §6.9, README controller Layanan). `api` sudah membuka amplop `{status, data}`
 * (interceptor): service membaca `res.data` langsung (CR-059) dan menormalkan string MySQLi (CR-049).
 *   GET    pmk?nip=&page=&per_page=                       riwayat PMK final (+ `arsip[]`, `can_edit`)
 *   GET    pmk/{id}/arsip/{id_attachment}                 unduh arsip SK (semua yang boleh melihat riwayat)
 *   PUT    pmk/{id} (POST multipart + `_method=PUT`)      tambah arsip SK (role 1)
 *   DELETE pmk/{id}, pmk/{id}/arsip/{id_attachment}       hapus riwayat / arsip (role 1)
 *   GET    pmk/pengajuan?mode=saya|pic|admin              daftar pengajuan (+ `can_create`, `can_process`)
 *   GET    pmk/pengajuan/{id}                             detail (+ `aksi {}`, `aksi_tersedia[]`, `tahap[]`)
 *   GET    pmk/opsi?nip=, POST pmk/hitung-mker            opsi form dan kalkulator masa kerja (server)
 *   POST   pmk/pengajuan, POST …/{id} (+ `_method=PUT`)   ajukan/ubah (multipart, 4 berkas)
 *   DELETE pmk/pengajuan/{id}; POST …/{id}/process        hapus; proses PIC `{aksi, reason_note}`
 *   GET    pmk/pengajuan/{id}/berkas/{kolom}              unduh berkas pengajuan
 */
import { ambilBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'
import { api } from '@/lib/axios'

import type {
  AksiProsesPmk,
  ArsipPmk,
  HitungMker,
  KolomBerkasPmk,
  ModePmk,
  PmkIsian,
  PmkItem,
  PmkListResponse,
  PmkOpsi,
  PmkQuery,
  RiwayatPmkItem,
  RiwayatPmkResponse,
} from '../types'

import { flag, normalisasiTampilan, num, teksOrNull } from './normalisasi'

export const KOLOM_BERKAS_PMK: readonly KolomBerkasPmk[] = ['file_kontrak', 'file_paklaring', 'file_surat_phk', 'file_slip_gaji']

const MODE: readonly ModePmk[] = ['saya', 'pic', 'admin']
const AKSI: readonly AksiProsesPmk[] = ['proses', 'setujui', 'tolak']

function daftarRecord(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? (value as Record<string, unknown>[]) : []
}

export function normalisasiPmk(raw: Record<string, unknown>): PmkItem {
  return {
    ...normalisasiTampilan(raw),
    id: num(raw.id_riwayat_pmk_req),
    nip: String(raw.nip ?? ''),
    jenis_pmk: String(raw.jenis_pmk ?? ''),
    instansi: String(raw.instansi ?? ''),
    tgl_awal: String(raw.tgl_awal ?? ''),
    tgl_akhir: String(raw.tgl_akhir ?? ''),
    mker_th: num(raw.mker_th),
    mker_bl: num(raw.mker_bl),
    status: num(raw.status),
    reason_note: teksOrNull(raw.reason_note),
    berkas: Object.fromEntries(KOLOM_BERKAS_PMK.map((k) => [k, flag(raw[k])])) as Record<KolomBerkasPmk, boolean>,
    aksi_tersedia: (Array.isArray(raw.aksi_tersedia) ? raw.aksi_tersedia : []).filter((a): a is AksiProsesPmk => AKSI.includes(a as AksiProsesPmk)),
    created_at: teksOrNull(raw.created_at),
    approved_at: teksOrNull(raw.approved_at),
  }
}

function normalisasiArsip(value: unknown): ArsipPmk[] {
  return daftarRecord(value).map((a) => ({ id_attachment: num(a.id_attachment), display_name: teksOrNull(a.display_name) }))
}

function normalisasiRiwayat(raw: Record<string, unknown>): RiwayatPmkItem {
  return {
    id: num(raw.id_riwayat_pmk),
    nip: String(raw.nip ?? ''),
    no_sk: String(raw.no_sk ?? ''),
    tgl_sk: String(raw.tgl_sk ?? ''),
    instansi: teksOrNull(raw.instansi),
    tgl_awal: teksOrNull(raw.tgl_awal),
    tgl_akhir: teksOrNull(raw.tgl_akhir),
    mker_th: num(raw.mker_th),
    mker_bl: num(raw.mker_bl),
    arsip: normalisasiArsip(raw.arsip),
  }
}

function formData(isian: PmkIsian, berkas: Partial<Record<KolomBerkasPmk, File>>, method?: 'PUT'): FormData {
  const fd = new FormData()
  for (const [k, v] of Object.entries(isian)) if (v !== undefined) fd.append(k, String(v))
  for (const [kolom, file] of Object.entries(berkas)) if (file) fd.append(kolom, file)
  if (method) fd.append('_method', method)
  return fd
}

export const pmkService = {
  async list(query: PmkQuery): Promise<PmkListResponse> {
    const params: Record<string, string | number> = { mode: query.mode, page: query.page, per_page: query.per_page }
    if (query.status) params.status = query.status
    if (query.search) params.search = query.search
    const { data } = await api.get<Record<string, unknown>>('/pmk/pengajuan', { params })
    return {
      items: daftarRecord(data.items).map(normalisasiPmk),
      total: num(data.total),
      page: num(data.page, 1),
      per_page: num(data.per_page),
      mode: MODE.includes(data.mode as ModePmk) ? (data.mode as ModePmk) : query.mode,
      can_create: flag(data.can_create),
      can_process: flag(data.can_process),
    }
  },

  async detail(id: number): Promise<PmkItem> {
    const { data } = await api.get<Record<string, unknown>>(`/pmk/pengajuan/${id}`)
    return normalisasiPmk(data)
  },

  async opsi(nip?: string): Promise<PmkOpsi> {
    const { data } = await api.get<Record<string, unknown>>('/pmk/opsi', { params: nip ? { nip } : undefined })
    const pegawai = data.pegawai as Record<string, unknown> | null
    const berkas = (data.berkas ?? {}) as Record<string, unknown>
    return {
      jenis_pmk: (Array.isArray(data.jenis_pmk) ? data.jenis_pmk : []).map(String),
      pegawai: pegawai ? { nip: String(pegawai.nip ?? ''), nama: String(pegawai.nama ?? '') } : null,
      tmt_cpns: teksOrNull(data.tmt_cpns),
      tgl_awal_maks: teksOrNull(data.tgl_awal_maks),
      berkas: Object.fromEntries(Object.entries(berkas).map(([k, v]) => [k, String(v)])),
      can_create: flag(data.can_create),
      adalah_pic: flag(data.adalah_pic),
    }
  },

  async hitungMker(tglAwal: string, tglAkhir: string): Promise<HitungMker> {
    const { data } = await api.post<Record<string, unknown>>('/pmk/hitung-mker', { tgl_awal: tglAwal, tgl_akhir: tglAkhir })
    return { mker_th: num(data.mker_th), mker_bl: num(data.mker_bl), peringatan: teksOrNull(data.peringatan) }
  },

  async create(isian: PmkIsian, berkas: Partial<Record<KolomBerkasPmk, File>>): Promise<PmkItem> {
    const { data } = await api.post<Record<string, unknown>>('/pmk/pengajuan', formData(isian, berkas))
    return normalisasiPmk(data)
  },

  async update(id: number, isian: PmkIsian, berkas: Partial<Record<KolomBerkasPmk, File>>): Promise<PmkItem> {
    const { data } = await api.post<Record<string, unknown>>(`/pmk/pengajuan/${id}`, formData(isian, berkas, 'PUT'))
    return normalisasiPmk(data)
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/pmk/pengajuan/${id}`)
  },

  async process(id: number, aksi: AksiProsesPmk, reasonNote?: string): Promise<void> {
    await api.post(`/pmk/pengajuan/${id}/process`, reasonNote ? { aksi, reason_note: reasonNote } : { aksi })
  },

  berkas(id: number, kolom: KolomBerkasPmk): Promise<BerkasUnduhan> {
    return ambilBerkas(`/pmk/pengajuan/${id}/berkas/${kolom}`, `pmk-${id}-${kolom}`)
  },

  async riwayat(query: { nip?: string; page: number; per_page: number }): Promise<RiwayatPmkResponse> {
    const params: Record<string, string | number> = { page: query.page, per_page: query.per_page }
    if (query.nip) params.nip = query.nip
    const { data } = await api.get<Record<string, unknown>>('/pmk', { params })
    return {
      items: daftarRecord(data.items).map(normalisasiRiwayat),
      total: num(data.total),
      page: num(data.page, 1),
      per_page: num(data.per_page),
      can_edit: flag(data.can_edit),
    }
  },

  /** Unduh arsip SK riwayat final (`GET pmk/{id}/arsip/{id_attachment}`, [V2] gap PRD §5.4; hak = hak lihat riwayat). */
  arsip(id: number, idAttachment: number): Promise<BerkasUnduhan> {
    return ambilBerkas(`/pmk/${id}/arsip/${idAttachment}`, `arsip-pmk-${id}-${idAttachment}`)
  },

  async tambahArsip(id: number, berkas: File): Promise<ArsipPmk[]> {
    const fd = new FormData()
    fd.append('arsip', berkas)
    fd.append('_method', 'PUT')
    const { data } = await api.post<unknown>(`/pmk/${id}`, fd)
    return normalisasiArsip(data)
  },

  async hapusRiwayat(id: number): Promise<void> {
    await api.delete(`/pmk/${id}`)
  },

  async hapusArsip(id: number, idAttachment: number): Promise<void> {
    await api.delete(`/pmk/${id}/arsip/${idAttachment}`)
  },
}
