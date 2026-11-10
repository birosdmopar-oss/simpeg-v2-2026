/**
 * Klien F-08 Pusat Notifikasi (MAKE-085). `api` sudah membuka amplop `{status, data}` (interceptor), jadi service membaca
 * `res.data` langsung — BUKAN `res.data.data` (CR-059). Nilai dinormalkan di sini; halaman menerima nilai bertipe.
 *   GET    notifikasi/ringkasan                         kontrak lonceng
 *   GET    notifikasi?status=&page=&per_page=           daftar gabungan
 *   POST   notifikasi/baca                              `{ ids }` | `{ semua: true }` → `{ diubah }`
 *   DELETE notifikasi/{sumber}/{id}                     hapus dari daftar (riwayat saja)
 *   GET    notifikasi/butuh-approval?page=&per_page=    grup antrean + item riwayat (role 1/3)
 *   GET    notifikasi/histori-approval?bulan=&page=     histori per bulan
 */
import { api } from '@/lib/axios'

import type {
  ButuhApproval,
  DaftarNotifikasi,
  GrupApproval,
  HistoriApproval,
  ItemApproval,
  ItemNotifikasi,
  RingkasanNotifikasi,
  StatusDaftar,
} from '../types'

type Raw = Record<string, unknown>

const angka = (v: unknown): number => (typeof v === 'number' && Number.isFinite(v) ? v : Number(v ?? 0) || 0)
const angkaAtauNull = (v: unknown): number | null => (v === null || v === undefined ? null : angka(v))
const teks = (v: unknown): string => (v === null || v === undefined ? '' : String(v))
const teksAtauNull = (v: unknown): string | null => (v === null || v === undefined || v === '' ? null : String(v))
const daftar = (v: unknown): Raw[] => (Array.isArray(v) ? (v as Raw[]) : [])

export function normalisasiRingkasan(raw: Raw): RingkasanNotifikasi {
  return {
    notifikasi_belum_dibaca: angka(raw.notifikasi_belum_dibaca),
    butuh_approval: angka(raw.butuh_approval),
    berita_belum_dibaca: angkaAtauNull(raw.berita_belum_dibaca),
    tiket_belum_dibaca: angkaAtauNull(raw.tiket_belum_dibaca),
    diperbarui_at: teks(raw.diperbarui_at),
  }
}

export function normalisasiItem(raw: Raw): ItemNotifikasi {
  return {
    id: teks(raw.id),
    sumber: teks(raw.sumber),
    judul: teks(raw.judul),
    keterangan: teks(raw.keterangan),
    status_label: teksAtauNull(raw.status_label),
    reason_note: teksAtauNull(raw.reason_note),
    url_fe: teks(raw.url_fe),
    dibaca: raw.dibaca === true,
    waktu: teksAtauNull(raw.waktu),
    dapat_dihapus: raw.dapat_dihapus === true,
  }
}

function normalisasiApproval(raw: Raw): ItemApproval {
  return {
    id: teks(raw.id),
    sumber: teks(raw.sumber),
    judul: teks(raw.judul),
    keterangan: teks(raw.keterangan),
    status_label: teksAtauNull(raw.status_label),
    nip: teksAtauNull(raw.nip),
    nama: teksAtauNull(raw.nama),
    url_fe: teks(raw.url_fe),
    waktu: teksAtauNull(raw.waktu),
  }
}

function normalisasiGrup(raw: Raw): GrupApproval {
  return { kode: teks(raw.kode), label: teks(raw.label), jumlah: angka(raw.jumlah), url_fe: teksAtauNull(raw.url_fe) }
}

export const notifikasiService = {
  async ringkasan(): Promise<RingkasanNotifikasi> {
    const res = await api.get<Raw>('/notifikasi/ringkasan')
    return normalisasiRingkasan(res.data ?? {})
  },

  async daftar(query: { status?: StatusDaftar; page?: number; per_page?: number } = {}): Promise<DaftarNotifikasi> {
    const res = await api.get<Raw>('/notifikasi', { params: query })
    const d = res.data ?? {}
    return {
      items: daftar(d.items).map(normalisasiItem),
      total: angka(d.total),
      belum_dibaca: angka(d.belum_dibaca),
      page: angka(d.page) || 1,
      per_page: angka(d.per_page) || 20,
    }
  },

  async baca(target: { ids: string[] } | { semua: true }): Promise<number> {
    const res = await api.post<Raw>('/notifikasi/baca', target)
    return angka(res.data?.diubah)
  },

  async hapus(id: string): Promise<void> {
    const [sumber, nomor] = id.split(':')
    await api.delete(`/notifikasi/${encodeURIComponent(sumber ?? '')}/${encodeURIComponent(nomor ?? '')}`)
  },

  async butuhApproval(query: { page?: number; per_page?: number } = {}): Promise<ButuhApproval> {
    const res = await api.get<Raw>('/notifikasi/butuh-approval', { params: query })
    const d = res.data ?? {}
    const r = d.riwayat as Raw | null | undefined
    return {
      total: angka(d.total),
      grup: daftar(d.grup).map(normalisasiGrup),
      riwayat: r
        ? { items: daftar(r.items).map(normalisasiApproval), total: angka(r.total), page: angka(r.page) || 1, per_page: angka(r.per_page) || 20 }
        : null,
    }
  },

  async historiApproval(query: { bulan?: string; page?: number; per_page?: number } = {}): Promise<HistoriApproval> {
    const res = await api.get<Raw>('/notifikasi/histori-approval', { params: query })
    const d = res.data ?? {}
    return {
      bulan: teks(d.bulan),
      pilihan_bulan: Array.isArray(d.pilihan_bulan) ? d.pilihan_bulan.map(String) : [],
      items: daftar(d.items).map(normalisasiApproval),
      total: angka(d.total),
      page: angka(d.page) || 1,
      per_page: angka(d.per_page) || 20,
    }
  },
}
