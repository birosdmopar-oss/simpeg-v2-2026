/**
 * Konten admin Fase 7 — klien kelola berita, DIBO, dan master sederhananya (kategori berita, jenis DIBO).
 * Bentuk endpoint masih draf (PRD Fase 7 v2.0 §5.5-5.6) sampai BE F-07/F-12 masuk.
 */
import { api } from '@/lib/axios'

import type {
  BeritaForm,
  BeritaKelolaRow,
  BeritaKelolaRowApi,
  DiboForm,
  DiboRow,
  DiboRowApi,
  JenisMasterKonten,
  MasterKontenRow,
  StatusBerita,
} from '../types'

interface Daftar<T> {
  items?: T[]
  total?: number
}

const status3 = (s: unknown): '1' | '2' | '10' => (String(s) === '2' ? '2' : String(s) === '10' ? '10' : '1')

export function mapBerita(r: BeritaKelolaRowApi): BeritaKelolaRow {
  const s = String(r.status ?? '0')
  return {
    id: String(r.id),
    judul: r.judul,
    kategori: r.kategori ?? '',
    id_kategori: r.id_kategori == null ? '' : String(r.id_kategori),
    penulis: r.penulis ?? '',
    publish_at: r.publish_at ?? '',
    status: (['0', '1', '2', '10'].includes(s) ? s : '0') as StatusBerita,
  }
}

export function mapDibo(r: DiboRowApi): DiboRow {
  return {
    id: String(r.id),
    judul: r.judul,
    jenis: r.jenis ?? '',
    id_jenis: r.id_jenis == null ? '' : String(r.id_jenis),
    keterangan: r.keterangan ?? '',
    status: status3(r.status),
  }
}

export const beritaKelolaService = {
  async list(params: { page: number; per_page: number; status?: string }): Promise<{ items: BeritaKelolaRow[]; total: number }> {
    const { data } = await api.get<Daftar<BeritaKelolaRowApi>>('/konten/berita', { params: { ...params, kelola: 1 } })
    return { items: (data?.items ?? []).map(mapBerita), total: data?.total ?? 0 }
  },
  async detail(id: string): Promise<BeritaForm> {
    const { data } = await api.get<{ judul: string; id_kategori: number | string; isi?: string; publish_at?: string; target?: string }>(
      `/konten/berita/${id}`,
    )
    return {
      judul: data.judul,
      id_kategori: String(data.id_kategori ?? ''),
      isi: data.isi ?? '',
      publish_at: data.publish_at ?? '',
      target: data.target ?? '',
    }
  },
  async create(form: BeritaForm): Promise<BeritaKelolaRow> {
    const { data } = await api.post<BeritaKelolaRowApi>('/konten/berita', form)
    return mapBerita(data)
  },
  async update(id: string, form: BeritaForm): Promise<BeritaKelolaRow> {
    const { data } = await api.put<BeritaKelolaRowApi>(`/konten/berita/${id}`, form)
    return mapBerita(data)
  },
  /** Persetujuan (role 1): setuju / tolak (Q7-07/08). */
  async proses(id: string, aksi: 'setuju' | 'tolak', alasan?: string): Promise<void> {
    await api.post(`/konten/berita/${id}/proses`, aksi === 'tolak' ? { aksi, alasan } : { aksi })
  },
  async remove(id: string): Promise<void> {
    await api.delete(`/konten/berita/${id}`)
  },
}

export const diboKelolaService = {
  async list(params: { page: number; per_page: number; status?: string }): Promise<{ items: DiboRow[]; total: number }> {
    const { data } = await api.get<Daftar<DiboRowApi>>('/konten/dibo', { params: { ...params, kelola: 1 } })
    return { items: (data?.items ?? []).map(mapDibo), total: data?.total ?? 0 }
  },
  /** Berkas DIBO multipart `berkas`; opsional saat ubah. */
  async simpan(id: string | null, form: DiboForm, berkas: File | null): Promise<DiboRow> {
    const fd = new FormData()
    fd.append('judul', form.judul.trim())
    fd.append('id_jenis', form.id_jenis)
    fd.append('keterangan', form.keterangan.trim())
    if (berkas) fd.append('berkas', berkas)
    if (id) fd.append('_method', 'PUT')
    const { data } = await api.post<DiboRowApi>(id ? `/konten/dibo/${id}` : '/konten/dibo', fd)
    return mapDibo(data)
  },
  async setStatus(id: string, status: '1' | '2'): Promise<void> {
    await api.patch(`/konten/dibo/${id}/status`, { status })
  },
  async remove(id: string): Promise<void> {
    await api.delete(`/konten/dibo/${id}`)
  },
}

const basis = (j: JenisMasterKonten): string => (j === 'dibo-jenis' ? '/konten/dibo-jenis' : '/konten/berita/kategori')

export function masterKontenService(jenis: JenisMasterKonten) {
  const b = basis(jenis)
  const map = (r: { id: number | string; nama: string; urutan?: number | null; status?: number | string | null }): MasterKontenRow => ({
    id: String(r.id),
    nama: r.nama,
    urutan: Number(r.urutan ?? 0),
    status: status3(r.status),
    allow_ap: Number(r.allow_ap ?? 0) === 1,
  })
  type Api = Parameters<typeof map>[0]
  return {
    async list(): Promise<MasterKontenRow[]> {
      const { data } = await api.get<Daftar<Api> | Api[]>(b, { params: { kelola: 1 } })
      const items = Array.isArray(data) ? data : (data?.items ?? [])
      return items.map(map)
    },
    async simpan(id: string | null, nama: string, allowAp = false): Promise<MasterKontenRow> {
      const body = jenis === 'berita-kategori' ? { nama, allow_ap: allowAp ? 1 : 0 } : { nama }
      const { data } = id ? await api.put<Api>(`${b}/${id}`, body) : await api.post<Api>(b, body)
      return map(data)
    },
    async setStatus(id: string, status: '1' | '2'): Promise<void> {
      await api.patch(`${b}/${id}/status`, { status })
    },
    async geser(id: string, arah: 'naik' | 'turun'): Promise<void> {
      await api.post(`${b}/${id}/urutan`, { arah })
    },
    async remove(id: string): Promise<void> {
      await api.delete(`${b}/${id}`)
    },
  }
}
