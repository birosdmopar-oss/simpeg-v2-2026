/**
 * Klien Modul C — Saldo Cuti (PRD Fase 4 §6.2, README Layanan "Persetujuan, tunda, pengingat, saldo"). Role 1, 3.
 * `api` sudah membuka amplop `{status, data}`, jadi service membaca `res.data` langsung (CR-059); angka dinormalkan
 * di sini (CR-049).
 *   GET    cuti/saldo?tahun=&search=&id_unit=&id_satker=&page=&per_page=   daftar (`items`, `total`, `tahun`)
 *   POST   cuti/saldo                                                       `{target, nip?, tahun, sisa_cuti, …}` → 201 ringkasan
 *   PUT    cuti/saldo/{id}                                                  ubah kolom saldo
 *   DELETE cuti/saldo/{id}                                                  hapus fisik
 */
import { api } from '@/lib/axios'

import { num } from './normalisasi'

export interface SaldoCuti {
  id: number
  nip: string
  nama: string
  tahun: number
  sisa_cuti: number
  sisa_cuti_n1: number
  sisa_cuti_n2: number
  sisa_cuti_ditangguhkan: number
  utang_cuti: number
  jabatan: string | null
  satker: string | null
}

export interface SaldoListQuery {
  tahun?: string
  search?: string
  id_unit?: string
  id_satker?: string
  page: number
  per_page: number
}

export interface SaldoListResponse {
  items: SaldoCuti[]
  total: number
  page: number
  per_page: number
  tahun: number
}

/** Kolom saldo yang dapat diisi (form tambah & ubah). */
export interface SaldoIsian {
  sisa_cuti: number
  sisa_cuti_n1: number
  sisa_cuti_n2: number
  utang_cuti: number
  sisa_cuti_ditangguhkan?: number
}

export interface SaldoTambahPayload extends SaldoIsian {
  target: 1 | 2
  nip?: string
  tahun: number
}

export interface PegawaiDilewati {
  nip: string
  nama: string
  alasan_kode: string
  alasan: string
}

export interface HasilTambahSaldo {
  message: string
  ringkasan: { tahun: number; target: number; jumlah_populasi: number; jumlah_dibuat: number; jumlah_dilewati: number }
  dibuat: { nip: string; nama: string }[]
  dilewati: PegawaiDilewati[]
}

function daftarRecord(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? (value as Record<string, unknown>[]) : []
}

export function normalisasiSaldo(raw: Record<string, unknown>): SaldoCuti {
  const teks = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : null)
  return {
    id: num(raw.id_pegawai_sisa_cuti),
    nip: String(raw.nip ?? ''),
    nama: String(raw.nama ?? ''),
    tahun: num(raw.tahun),
    sisa_cuti: num(raw.sisa_cuti),
    sisa_cuti_n1: num(raw.sisa_cuti_n1),
    sisa_cuti_n2: num(raw.sisa_cuti_n2),
    sisa_cuti_ditangguhkan: num(raw.sisa_cuti_ditangguhkan),
    utang_cuti: num(raw.utang_cuti),
    jabatan: teks(raw.jabatan),
    satker: teks(raw.satker),
  }
}

export const cutiSaldoService = {
  async list(query: SaldoListQuery): Promise<SaldoListResponse> {
    const params: Record<string, string | number> = { page: query.page, per_page: query.per_page }
    if (query.tahun) params.tahun = query.tahun
    if (query.search) params.search = query.search
    if (query.id_unit) params.id_unit = query.id_unit
    if (query.id_satker) params.id_satker = query.id_satker
    const { data } = await api.get<Record<string, unknown>>('/cuti/saldo', { params })
    return {
      items: daftarRecord(data.items).map(normalisasiSaldo),
      total: num(data.total),
      page: num(data.page, 1),
      per_page: num(data.per_page, query.per_page),
      tahun: num(data.tahun),
    }
  },

  async tambah(payload: SaldoTambahPayload): Promise<HasilTambahSaldo> {
    const { data } = await api.post<Record<string, unknown>>('/cuti/saldo', payload)
    return {
      message: String(data.message ?? ''),
      ringkasan: {
        tahun: num((data.ringkasan as Record<string, unknown> | undefined)?.tahun),
        target: num((data.ringkasan as Record<string, unknown> | undefined)?.target),
        jumlah_populasi: num((data.ringkasan as Record<string, unknown> | undefined)?.jumlah_populasi),
        jumlah_dibuat: num((data.ringkasan as Record<string, unknown> | undefined)?.jumlah_dibuat),
        jumlah_dilewati: num((data.ringkasan as Record<string, unknown> | undefined)?.jumlah_dilewati),
      },
      dibuat: daftarRecord(data.dibuat).map((d) => ({ nip: String(d.nip ?? ''), nama: String(d.nama ?? '') })),
      dilewati: daftarRecord(data.dilewati).map((d) => ({
        nip: String(d.nip ?? ''),
        nama: String(d.nama ?? ''),
        alasan_kode: String(d.alasan_kode ?? ''),
        alasan: String(d.alasan ?? ''),
      })),
    }
  },

  async ubah(id: number, payload: SaldoIsian): Promise<void> {
    await api.put(`/cuti/saldo/${id}`, payload)
  },

  async hapus(id: number): Promise<void> {
    await api.delete(`/cuti/saldo/${id}`)
  },
}
