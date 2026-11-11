/**
 * Konten admin Fase 7 — klien kelola berita, DIBO, dan master sederhananya (kategori berita, jenis DIBO).
 * Bentuk endpoint masih draf (PRD Fase 7 v2.0 §5.5-5.6) sampai BE F-07/F-12 masuk.
 */
import { api } from '@/lib/axios'

import type {
  BeritaForm,
  BeritaKelolaRow,
  BeritaKelolaRowApi,
  DiboBerkasInput,
  DiboForm,
  DiboRow,
  DiboRowApi,
  JenisMasterKonten,
  LampiranBerita,
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
    ada_lampiran: r.ada_lampiran === true,
    status: (['0', '1', '2', '10'].includes(s) ? s : '0') as StatusBerita,
  }
}

export function mapDibo(r: DiboRowApi): DiboRow {
  return {
    id: String(r.id),
    nomor: r.nomor ?? '',
    tahun: String(r.tahun ?? ''),
    tentang: r.tentang,
    jenis: r.jenis ?? '',
    id_jenis: r.id_jenis == null ? '' : String(r.id_jenis),
    keterangan: r.keterangan ?? '',
    berkas: r.berkas ?? [],
    status: status3(r.status),
  }
}

function toFormData(form: BeritaForm, lampiran: File | null): FormData {
  const fd = new FormData()
  fd.append('judul', form.judul.trim())
  fd.append('id_kategori', form.id_kategori)
  fd.append('isi', form.isi)
  fd.append('publish_at', form.publish_jam ? `${form.publish_at} ${form.publish_jam}` : form.publish_at)
  fd.append('target', form.target)
  if (lampiran) fd.append('lampiran', lampiran)
  return fd
}

export const beritaKelolaService = {
  async list(params: { page: number; per_page: number; status?: string }): Promise<{ items: BeritaKelolaRow[]; total: number }> {
    const { data } = await api.get<Daftar<BeritaKelolaRowApi>>('/konten/berita', { params: { ...params, kelola: 1 } })
    return { items: (data?.items ?? []).map(mapBerita), total: data?.total ?? 0 }
  },
  async detail(id: string): Promise<{ form: BeritaForm; lampiran: LampiranBerita | null }> {
    const { data } = await api.get<{
      judul: string
      id_kategori: number | string
      isi?: string
      publish_at?: string
      publish_jam?: string
      target?: string
      lampiran?: LampiranBerita | null
    }>(`/konten/berita/${id}`)
    return {
      form: {
        judul: data.judul,
        id_kategori: String(data.id_kategori ?? ''),
        isi: data.isi ?? '',
        publish_at: data.publish_at ?? '',
        publish_jam: data.publish_jam ?? '',
        target: data.target ?? '',
      },
      lampiran: data.lampiran ?? null,
    }
  },
  /** Multipart: lampiran opsional (satu berkas); tanpa berkas saat ubah, lampiran lama dipertahankan. */
  async create(form: BeritaForm, lampiran: File | null): Promise<BeritaKelolaRow> {
    const { data } = await api.post<BeritaKelolaRowApi>('/konten/berita', toFormData(form, lampiran))
    return mapBerita(data)
  },
  async update(id: string, form: BeritaForm, lampiran: File | null): Promise<BeritaKelolaRow> {
    const { data } = await api.post<BeritaKelolaRowApi>(`/konten/berita/${id}`, toFormData(form, lampiran))
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
  /** Multipart `file_1..3`; saat ubah, berkas yang tidak dikirim dipertahankan, `hapus_file_2/3 = 1` menghapusnya. */
  async simpan(id: string | null, form: DiboForm, berkas: DiboBerkasInput): Promise<DiboRow> {
    const fd = new FormData()
    fd.append('id_jenis', form.id_jenis)
    fd.append('nomor', form.nomor.trim())
    fd.append('tahun', form.tahun.trim())
    fd.append('tentang', form.tentang.trim())
    fd.append('keterangan', form.keterangan.trim())
    for (const no of [1, 2, 3] as const) {
      const f = berkas[no]
      if (f) fd.append(`file_${no}`, f)
    }
    if (berkas.hapus2) fd.append('hapus_file_2', '1')
    if (berkas.hapus3) fd.append('hapus_file_3', '1')
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
  const map = (r: { id: number | string; nama: string; urutan?: number | null; status?: number | string | null; allow_ap?: number | null }): MasterKontenRow => ({
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
