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
  CutiIsian,
  CutiOpsi,
  HitungLamaCuti,
  HitungLamaPayload,
  KuotaHitung,
  PejabatCuti,
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

function normalisasiPejabat(raw: unknown): PejabatCuti | null {
  if (typeof raw !== 'object' || raw === null) return null
  const r = raw as Record<string, unknown>
  return { nip: String(r.nip ?? ''), nama: String(r.nama ?? ''), jabatan: teksOrNull(r.jabatan), bisa_memproses: flag(r.bisa_memproses) }
}

function normalisasiKuota(raw: unknown): KuotaHitung | null {
  if (typeof raw !== 'object' || raw === null) return null
  const r = raw as Record<string, unknown>
  return {
    tahun: num(r.tahun),
    sisa_cuti: num(r.sisa_cuti),
    sisa_cuti_n1: num(r.sisa_cuti_n1),
    sisa_cuti_n2: num(r.sisa_cuti_n2),
    saldo_tahun_ini: num(r.saldo_tahun_ini),
    menunggu_tahun_ini: num(r.menunggu_tahun_ini),
    diajukan_tahun_ini: num(r.diajukan_tahun_ini),
    saldo_tahun_depan: num(r.saldo_tahun_depan),
    menunggu_tahun_depan: num(r.menunggu_tahun_depan),
    diajukan_tahun_depan: num(r.diajukan_tahun_depan),
    cukup: flag(r.cukup),
    pesan: String(r.pesan ?? ''),
  }
}

function formData(isian: CutiIsian, berkas: File | null, method?: 'PUT'): FormData {
  const fd = new FormData()
  for (const [k, v] of Object.entries(isian)) {
    if (k === 'ketua_tim' || v === undefined) continue
    fd.append(k, String(v))
  }
  for (const nip of isian.ketua_tim ?? []) fd.append('ketua_tim[]', nip)
  if (berkas) fd.append('file_bukti', berkas)
  if (method) fd.append('_method', method)
  return fd
}

export const cutiService = {
  async opsi(nip?: string): Promise<CutiOpsi> {
    const { data } = await api.get<Record<string, unknown>>('/cuti/opsi', { params: nip ? { nip } : undefined })
    const saldo = data.saldo as Record<string, unknown> | null
    return {
      jenis_cuti: daftarRecord(data.jenis_cuti).map((j) => ({ id_jenis_cuti: num(j.id_jenis_cuti), nama_jenis_cuti: String(j.nama_jenis_cuti ?? '') })),
      atasan_langsung: normalisasiPejabat(data.atasan_langsung),
      pyb: daftarRecord(data.pyb).map((p) => normalisasiPejabat(p)!),
      langkah_tunggal: flag(data.langkah_tunggal),
      eselon_i: flag(data.eselon_i),
      saldo: saldo
        ? {
            tahun: num(saldo.tahun),
            sisa_cuti: num(saldo.sisa_cuti),
            sisa_cuti_n1: num(saldo.sisa_cuti_n1),
            sisa_cuti_n2: num(saldo.sisa_cuti_n2),
            saldo_tahun_ini: num(saldo.saldo_tahun_ini),
            saldo_tahun_depan: num(saldo.saldo_tahun_depan),
          }
        : null,
      tgl_akhir_maks: teksOrNull(data.tgl_akhir_maks),
      ketua_tim: daftarRecord(data.ketua_tim).map((k) => ({ nip: String(k.nip ?? ''), nama: String(k.nama ?? '') })),
      can_create: flag(data.can_create),
    }
  },

  async hitungLama(payload: HitungLamaPayload): Promise<HitungLamaCuti> {
    const { data } = await api.post<Record<string, unknown>>('/cuti/hitung-lama', payload)
    return {
      lama_cuti: num(data.lama_cuti),
      satuan_lama_cuti: String(data.satuan_lama_cuti ?? ''),
      tgl_akhir: teksOrNull(data.tgl_akhir),
      hari_libur: daftarRecord(data.hari_libur).map((h) => ({ tanggal: String(h.tanggal ?? ''), keterangan: String(h.keterangan ?? '') })),
      kuota: normalisasiKuota(data.kuota),
    }
  },

  async create(isian: CutiIsian, berkas: File | null): Promise<CutiItem> {
    const { data } = await api.post<Record<string, unknown>>('/cuti', formData(isian, berkas))
    return normalisasiCuti(data)
  },

  async update(id: number, isian: CutiIsian, berkas: File | null): Promise<CutiItem> {
    const { data } = await api.post<Record<string, unknown>>(`/cuti/${id}`, formData(isian, berkas, 'PUT'))
    return normalisasiCuti(data)
  },

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
