/**
 * Klien Modul C — Cuti (PRD Fase 4 §6.2–§6.4, README controller Layanan). `api` sudah membuka amplop `{status, data}`
 * (interceptor), jadi service membaca `res.data` langsung — BUKAN `res.data.data` (CR-059). Semua kode/angka dinormalkan
 * di sini (CR-049); halaman menerima nilai bertipe.
 *   GET    cuti?mode=&status=&search=&page=&per_page=   daftar (`items`, `total`, `can_create`)
 *   GET    cuti/{id}                                    detail (+ lampiran, surat, riwayat approval)
 *   POST   cuti/{id}/process                            `{aksi, reason_note?, atas_nama?}`
 *   POST   cuti/{id}/tunda                              tunda pengingat
 *   DELETE cuti/{id}                                    hapus
 *   GET    cuti/pengingat                               antrean persetujuan yang belum ditunda
 *   GET    cuti/{id}/lampiran | cuti/{id}/surat         unduh berkas
 */
import { ambilBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'
import { api } from '@/lib/axios'

import type {
  CutiDetail,
  CutiItem,
  CutiListQuery,
  CutiListResponse,
  CutiPengingatResponse,
  CutiProsesPayload,
  LampiranCuti,
  ModeCuti,
  SuratCuti,
} from '../types'

import { flag, normalisasiTampilan, num, teksOrNull } from './normalisasi'

const MODE: readonly ModeCuti[] = ['saya', 'persetujuan', 'admin']

export function normalisasiCuti(raw: Record<string, unknown>): CutiItem {
  return {
    ...normalisasiTampilan(raw),
    id: num(raw.id_riwayat_cuti),
    nip: String(raw.nip ?? ''),
    nama: String(raw.nama ?? ''),
    id_jenis_cuti: num(raw.id_jenis_cuti),
    nama_jenis_cuti: String(raw.nama_jenis_cuti ?? ''),
    tgl_mulai: String(raw.tgl_mulai ?? ''),
    tgl_akhir: String(raw.tgl_akhir ?? ''),
    lama_cuti: num(raw.lama_cuti),
    status: num(raw.status),
    keterangan: String(raw.keterangan ?? ''),
    no_telp_saat_cuti: String(raw.no_telp_saat_cuti ?? ''),
    alamat_saat_cuti: String(raw.alamat_saat_cuti ?? ''),
    nip_atasan_langsung: teksOrNull(raw.nip_atasan_langsung),
    nama_atasan_langsung: teksOrNull(raw.nama_atasan_langsung),
    nip_yang_menyetujui: teksOrNull(raw.nip_yang_menyetujui),
    nama_yang_menyetujui: teksOrNull(raw.nama_yang_menyetujui),
    ada_lampiran: flag(raw.ada_lampiran),
    ada_surat: flag(raw.ada_surat),
    perlu_atas_nama: flag(raw.perlu_atas_nama),
    created_at: teksOrNull(raw.created_at),
  }
}

function daftarRecord(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? (value as Record<string, unknown>[]) : []
}

function normalisasiDetail(raw: Record<string, unknown>): CutiDetail {
  const approval = (raw.riwayat_approval ?? {}) as Record<string, unknown>
  return {
    ...normalisasiCuti(raw),
    ketua_tim_detail: daftarRecord(raw.ketua_tim_detail).map((k) => ({ nip: String(k.nip ?? ''), nama: String(k.nama ?? '') })),
    lampiran: daftarRecord(raw.lampiran).map(
      (l): LampiranCuti => ({ id_attachment: num(l.id_attachment), display_name: teksOrNull(l.display_name) }),
    ),
    surat: daftarRecord(raw.surat).map(
      (s): SuratCuti => ({
        id_attachment: num(s.id_attachment),
        jenis: s.jenis === 'tte' ? 'tte' : 'belum_tte',
        display_name: teksOrNull(s.display_name),
        created_at: teksOrNull(s.created_at),
      }),
    ),
    riwayat_approval: {
      keterangan_petugas_approval: teksOrNull(approval.keterangan_petugas_approval),
      reason_note_al: teksOrNull(approval.reason_note_al),
      reason_note_pyb: teksOrNull(approval.reason_note_pyb),
    },
  }
}

export const cutiService = {
  async list(query: CutiListQuery): Promise<CutiListResponse> {
    const params: Record<string, string | number> = { mode: query.mode, page: query.page, per_page: query.per_page }
    if (query.status) params.status = query.status
    if (query.search) params.search = query.search
    const { data } = await api.get<Record<string, unknown>>('/cuti', { params })
    return {
      items: daftarRecord(data.items).map(normalisasiCuti),
      total: num(data.total),
      page: num(data.page, 1),
      per_page: num(data.per_page),
      mode: MODE.includes(data.mode as ModeCuti) ? (data.mode as ModeCuti) : query.mode,
      can_create: flag(data.can_create),
    }
  },

  async detail(id: number): Promise<CutiDetail> {
    const { data } = await api.get<Record<string, unknown>>(`/cuti/${id}`)
    return normalisasiDetail(data)
  },

  async process(id: number, payload: CutiProsesPayload): Promise<CutiItem> {
    const { data } = await api.post<Record<string, unknown>>(`/cuti/${id}/process`, payload)
    return normalisasiCuti(data)
  },

  async tunda(id: number): Promise<CutiItem> {
    const { data } = await api.post<Record<string, unknown>>(`/cuti/${id}/tunda`)
    return normalisasiCuti(data)
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/cuti/${id}`)
  },

  async pengingat(): Promise<CutiPengingatResponse> {
    const { data } = await api.get<Record<string, unknown>>('/cuti/pengingat')
    return { items: daftarRecord(data.items).map(normalisasiCuti), total: num(data.total) }
  },

  lampiran(id: number): Promise<BerkasUnduhan> {
    return ambilBerkas(`/cuti/${id}/lampiran`, `lampiran-cuti-${id}`)
  },

  surat(id: number): Promise<BerkasUnduhan> {
    return ambilBerkas(`/cuti/${id}/surat`, `surat-cuti-${id}`)
  },
}
