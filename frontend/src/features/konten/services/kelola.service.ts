/**
 * Konten admin Fase 7 — klien kelola popup/banner (role 1; PRD Fase 7 v2.0 §5.3-5.4). Bentuk endpoint masih draf sampai
 * BE F-04/F-05 masuk. Unggah gambar lewat multipart `berkas` (jpg/jpeg/png/gif, ≤ 5 MB; validasi mengikat di server).
 */
import { api } from '@/lib/axios'
import type { SelectOption } from '@/shared/ui'

import type {
  JenisKontenGambar,
  KontenGambarForm,
  KontenGambarListApi,
  KontenGambarRow,
  KontenGambarRowApi,
  SpesifikasiTarget,
} from '../types'

export const GAMBAR_EKSTENSI = ['jpg', 'jpeg', 'png', 'gif']
export const GAMBAR_MAKS_MB = 5

export function mapRow(r: KontenGambarRowApi): KontenGambarRow {
  const status = String(r.status ?? '1')
  return {
    id: String(r.id),
    keterangan: r.keterangan ?? '',
    url: r.url ?? '',
    gambar_url: r.gambar_url ?? null,
    tgl_mulai: r.tgl_mulai.slice(0, 10),
    tgl_akhir: r.tgl_akhir.slice(0, 10),
    jam_mulai: r.tgl_mulai.slice(11, 16),
    jam_akhir: r.tgl_akhir.slice(11, 16),
    urutan: Number(r.urutan ?? 0),
    status: status === '2' || status === '10' ? status : '1',
    jumlah_penerima: Number(r.jumlah_penerima ?? 0),
  }
}

function toFormData(form: KontenGambarForm, berkas: File | null, jenis: JenisKontenGambar): FormData {
  const fd = new FormData()
  fd.append('keterangan', form.keterangan.trim())
  fd.append('url', form.url.trim())
  // Popup = DATETIME WIB (DBV-032 §3.1); banner = DATE (§3.3).
  const waktu = (tgl: string, jam: string): string => (jenis === 'popup' ? `${tgl} ${jam || '00:00'}` : tgl)
  fd.append('tgl_mulai', waktu(form.tgl_mulai, form.jam_mulai))
  fd.append('tgl_akhir', waktu(form.tgl_akhir, form.jam_akhir || '23:59'))
  fd.append('target', JSON.stringify(form.target))
  if (berkas) fd.append('berkas', berkas)
  return fd
}

export function kelolaService(jenis: JenisKontenGambar) {
  const base = `/konten/${jenis}`
  return {
    async list(params: { page: number; per_page: number; status?: string; search?: string }): Promise<{ items: KontenGambarRow[]; total: number }> {
      const { data } = await api.get<KontenGambarListApi>(base, { params })
      return { items: (data?.items ?? []).map(mapRow), total: data?.total ?? 0 }
    },
    async create(form: KontenGambarForm, berkas: File): Promise<KontenGambarRow> {
      const { data } = await api.post<KontenGambarRowApi>(base, toFormData(form, berkas, jenis))
      return mapRow(data)
    },
    /** Gambar opsional saat ubah: tanpa berkas, gambar lama dipertahankan. */
    async update(id: string, form: KontenGambarForm, berkas: File | null): Promise<KontenGambarRow> {
      const fd = toFormData(form, berkas, jenis)
      fd.append('_method', 'PUT')
      const { data } = await api.post<KontenGambarRowApi>(`${base}/${id}`, fd)
      return mapRow(data)
    },
    async setStatus(id: string, status: '1' | '2'): Promise<void> {
      await api.patch(`${base}/${id}/status`, { status })
    },
    async geser(id: string, arah: 'naik' | 'turun'): Promise<void> {
      await api.post(`${base}/${id}/urutan`, { arah })
    },
    async remove(id: string): Promise<void> {
      await api.delete(`${base}/${id}`)
    },
    /** Target tersimpan + label "NIP - nama" untuk NIP yang dipilih/dikecualikan. */
    async target(id: string): Promise<{ target: SpesifikasiTarget; labelPegawai: Record<string, string> }> {
      const { data } = await api.get<SpesifikasiTarget & { label_pegawai?: Record<string, string> }>(`${base}/${id}/target`)
      return {
        target: { unit_satker: data?.unit_satker ?? [], pegawai: data?.pegawai ?? [], kecuali: data?.kecuali ?? [] },
        labelPegawai: data?.label_pegawai ?? {},
      }
    },
    async opsiUnitSatker(): Promise<SelectOption[]> {
      const { data } = await api.get<Array<{ value: string; label: string }>>('/konten/opsi/unit-satker')
      return data ?? []
    },
  }
}
