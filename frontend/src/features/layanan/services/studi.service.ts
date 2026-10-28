/**
 * Klien Modul C — Tugas Belajar (`tb`) dan Izin Belajar (`ib`) dalam satu fondasi berparameter jenis (PRD Fase 4 §6.7–6.8;
 * kontrak README Layanan "Tugas Belajar — C-07" dan "Izin Belajar — C-08"). `api` sudah membuka amplop, jadi service
 * membaca `res.data` langsung (CR-059); angka dan flag dinormalkan di sini (CR-049). Aturan bisnis ada di server
 * (ADR-025/026): FE hanya meneruskan isian dan menampilkan hak dari `aksi {}`, `can_*`, dan `adalah_pic`.
 *
 *   GET    {jenis}?mode=&status=&search=&page=&per_page=   daftar (`items`, `can_create`, `can_process`)
 *   GET    {jenis}/opsi?nip=                                jenjang, `slot_berkas`, `berkas_wajib`, `adalah_pic`
 *   GET    {jenis}/{id}                                     detail (+ `tahap`, `aksi`, `lampiran`, `surat`, `perpanjangan`)
 *   POST   {jenis}  |  POST {jenis}/{id} (+ `_method=PUT`)   tambah / ubah (multipart)
 *   DELETE {jenis}/{id}                                     hapus fisik
 *   POST   {jenis}/{id}/process                             setujui / tolak (berkas opsional)
 *   GET    {jenis}/{id}/lampiran/{idAttachment} | {jenis}/{id}/surat   unduh berkas
 *   TB saja: POST tb/{id}/perpanjangan, PUT|DELETE tb/perpanjangan/{id}
 */
import { ambilBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'
import { api } from '@/lib/axios'

import type { AksiLayanan, TahapLayanan, NadaStatus } from '../types'

import { flag, normalisasiAksi, normalisasiNada, normalisasiTahap, num, teksOrNull } from './normalisasi'

export type StudiJenis = 'tb' | 'ib'

export const MODE_STUDI = ['saya', 'pic', 'admin'] as const
export type ModeStudi = (typeof MODE_STUDI)[number]

export interface SlotBerkas {
  tag: string
  label: string
}

export interface StudiLampiran {
  id_attachment: number
  tag: string | null
  display_name: string | null
}

export interface PerpanjanganStudi {
  id: number
  tgl_mulai_perpan: string
  tgl_akhir_perpan: string
  lampiran: StudiLampiran[]
}

export interface StudiItem {
  id: number
  nip: string
  nama: string | null
  id_jenjang_pendidikan: number | null
  institusi_pendidikan: string
  jurusan_pendidikan: string
  lokasi_pendidikan: string
  lembaga_yang_mendanai: string
  up: string
  tgl_mulai: string
  tgl_akhir: string
  dalam_negeri: number
  tembusan: string | null
  keterangan: string | null
  status: number
  label_status: string
  nada_status: NadaStatus
  tahap: TahapLayanan[]
  aksi: AksiLayanan
  sk: { no_sk: string | null; tgl_sk: string | null; ds: string | null; no_ds: string | null; tgl_ds: string | null; perihal_ds: string | null }
  reason_note: string | null
}

export interface StudiDetail extends StudiItem {
  status_penuh: boolean | null
  lampiran: StudiLampiran[]
  surat: { id_attachment: number; jenis: string }[]
  perpanjangan: PerpanjanganStudi[]
}

export interface StudiListQuery {
  mode: ModeStudi
  status?: string
  search?: string
  page: number
  per_page: number
}

export interface StudiListResponse {
  items: StudiItem[]
  total: number
  page: number
  per_page: number
  mode: ModeStudi
  can_create: boolean
  can_process: boolean
}

export interface JenjangOpsi {
  id_jenjang_pendidikan: number
  jenjang_pendidikan: string
  jenjang_pendidikan_singkat: string
}

export interface StudiOpsi {
  jenjang_pendidikan: JenjangOpsi[]
  up: string
  pegawai: { nip: string; nama: string | null } | null
  slot_berkas: SlotBerkas[]
  berkas_wajib: boolean
  can_create: boolean
  adalah_pic: boolean
}

/** Isian form (field DDL; SK hanya dikirim role 1). Nilai numerik dikirim apa adanya. */
export type StudiIsian = Record<string, string | number | null | undefined>

export type BerkasForm = Record<string, File | undefined>

function daftarRecord(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? (value as Record<string, unknown>[]) : []
}

/** Nama kolom SK per jenis (`ds_tb`, `no_ds_tb`, dst.): nama kolom DDL/kontrak, bukan aturan bisnis. */
export function kolomSkStudi(jenis: StudiJenis) {
  return { no_sk: 'no_sk', tgl_sk: 'tgl_sk', ds: `ds_${jenis}`, no_ds: `no_ds_${jenis}`, tgl_ds: `tgl_ds_${jenis}`, perihal_ds: `perihal_ds_${jenis}` }
}

export function normalisasiStudi(raw: Record<string, unknown>, jenis: StudiJenis): StudiItem {
  const k = kolomSkStudi(jenis)
  const tampilan = { aksi: normalisasiAksi(raw.aksi), tahap: normalisasiTahap(raw.tahap) }
  const teks = (v: unknown): string => String(v ?? '')
  return {
    id: num(raw[`id_riwayat_${jenis}`]),
    nip: teks(raw.nip),
    nama: teksOrNull(raw.nama),
    id_jenjang_pendidikan: raw.id_jenjang_pendidikan === null || raw.id_jenjang_pendidikan === undefined ? null : num(raw.id_jenjang_pendidikan),
    institusi_pendidikan: teks(raw.institusi_pendidikan),
    jurusan_pendidikan: teks(raw.jurusan_pendidikan),
    lokasi_pendidikan: teks(raw.lokasi_pendidikan),
    lembaga_yang_mendanai: teks(raw.lembaga_yang_mendanai),
    up: teks(raw.up),
    tgl_mulai: teks(raw.tgl_mulai),
    tgl_akhir: teks(raw.tgl_akhir),
    dalam_negeri: num(raw.dalam_negeri, 1),
    tembusan: teksOrNull(raw.tembusan),
    keterangan: teksOrNull(raw.keterangan),
    status: num(raw.status),
    label_status: teks(raw.label_status),
    nada_status: normalisasiNada(raw.nada_status),
    tahap: tampilan.tahap,
    aksi: tampilan.aksi,
    sk: {
      no_sk: teksOrNull(raw[k.no_sk]),
      tgl_sk: teksOrNull(raw[k.tgl_sk]),
      ds: teksOrNull(raw[k.ds]),
      no_ds: teksOrNull(raw[k.no_ds]),
      tgl_ds: teksOrNull(raw[k.tgl_ds]),
      perihal_ds: teksOrNull(raw[k.perihal_ds]),
    },
    reason_note: teksOrNull(raw.reason_note),
  }
}

function normalisasiLampiran(raw: unknown): StudiLampiran[] {
  return daftarRecord(raw).map((l) => ({ id_attachment: num(l.id_attachment), tag: teksOrNull(l.tag), display_name: teksOrNull(l.display_name) }))
}

function normalisasiDetail(raw: Record<string, unknown>, jenis: StudiJenis): StudiDetail {
  return {
    ...normalisasiStudi(raw, jenis),
    status_penuh: raw.status_penuh === undefined ? null : flag(raw.status_penuh),
    lampiran: normalisasiLampiran(raw.lampiran),
    surat: daftarRecord(raw.surat).map((s) => ({ id_attachment: num(s.id_attachment), jenis: String(s.jenis ?? '') })),
    perpanjangan: daftarRecord(raw.perpanjangan).map((p) => ({
      id: num(p.id_riwayat_tb_perpan),
      tgl_mulai_perpan: String(p.tgl_mulai_perpan ?? ''),
      tgl_akhir_perpan: String(p.tgl_akhir_perpan ?? ''),
      lampiran: normalisasiLampiran(p.lampiran),
    })),
  }
}

/** FormData dari isian dan berkas; `_method=PUT` untuk ubah (pola PMK/Cuti). Field `null` dikirim sebagai string kosong. */
function formData(isian: StudiIsian, berkas: BerkasForm, method?: 'PUT'): FormData {
  const fd = new FormData()
  for (const [k, v] of Object.entries(isian)) if (v !== undefined) fd.append(k, v === null ? '' : String(v))
  for (const [k, file] of Object.entries(berkas)) if (file) fd.append(k, file)
  if (method) fd.append('_method', method)
  return fd
}

export function createStudiService(jenis: StudiJenis) {
  const base = `/${jenis}`
  return {
    jenis,

    async list(query: StudiListQuery): Promise<StudiListResponse> {
      const params: Record<string, string | number> = { mode: query.mode, page: query.page, per_page: query.per_page }
      if (query.status) params.status = query.status
      if (query.search) params.search = query.search
      const { data } = await api.get<Record<string, unknown>>(base, { params })
      return {
        items: daftarRecord(data.items).map((r) => normalisasiStudi(r, jenis)),
        total: num(data.total),
        page: num(data.page, 1),
        per_page: num(data.per_page, query.per_page),
        mode: (MODE_STUDI as readonly string[]).includes(String(data.mode)) ? (data.mode as ModeStudi) : query.mode,
        can_create: flag(data.can_create),
        can_process: flag(data.can_process),
      }
    },

    async opsi(nip?: string): Promise<StudiOpsi> {
      const { data } = await api.get<Record<string, unknown>>(`${base}/opsi`, { params: nip ? { nip } : undefined })
      const slot = (data.slot_berkas ?? {}) as Record<string, unknown>
      const pegawai = (data.pegawai ?? null) as Record<string, unknown> | null
      return {
        jenjang_pendidikan: daftarRecord(data.jenjang_pendidikan).map((j) => ({
          id_jenjang_pendidikan: num(j.id_jenjang_pendidikan),
          jenjang_pendidikan: String(j.jenjang_pendidikan ?? ''),
          jenjang_pendidikan_singkat: String(j.jenjang_pendidikan_singkat ?? ''),
        })),
        up: String(data.up ?? ''),
        pegawai: pegawai ? { nip: String(pegawai.nip ?? ''), nama: teksOrNull(pegawai.nama) } : null,
        slot_berkas: Object.entries(slot).map(([tag, label]) => ({ tag, label: String(label) })),
        berkas_wajib: flag(data.berkas_wajib),
        can_create: flag(data.can_create),
        adalah_pic: flag(data.adalah_pic),
      }
    },

    async detail(id: number): Promise<StudiDetail> {
      const { data } = await api.get<Record<string, unknown>>(`${base}/${id}`)
      return normalisasiDetail(data, jenis)
    },

    async tambah(isian: StudiIsian, berkas: BerkasForm): Promise<StudiItem> {
      const { data } = await api.post<Record<string, unknown>>(base, formData(isian, berkas))
      return normalisasiStudi(data, jenis)
    },

    async ubah(id: number, isian: StudiIsian, berkas: BerkasForm): Promise<StudiItem> {
      const { data } = await api.post<Record<string, unknown>>(`${base}/${id}`, formData(isian, berkas, 'PUT'))
      return normalisasiStudi(data, jenis)
    },

    async hapus(id: number): Promise<void> {
      await api.delete(`${base}/${id}`)
    },

    /** `payload`: `{aksi, reason_note?, no_sk?, tgl_sk?, ds_<jenis>?, …}` (nama kolom dari `kolomSkStudi`). */
    async proses(id: number, payload: StudiIsian, berkas: BerkasForm = {}): Promise<void> {
      if (Object.values(berkas).some(Boolean)) await api.post(`${base}/${id}/process`, formData(payload, berkas))
      else await api.post(`${base}/${id}/process`, payload)
    },

    async lampiran(id: number, idAttachment: number, dasar: string): Promise<BerkasUnduhan> {
      return ambilBerkas(`${base}/${id}/lampiran/${idAttachment}`, dasar)
    },

    async surat(id: number, dasar: string): Promise<BerkasUnduhan> {
      return ambilBerkas(`${base}/${id}/surat`, dasar)
    },

    /** Perpanjangan TB (role 1). Tanggal awal kosong → server memberi bawaan (akhir efektif + 1 hari). */
    async tambahPerpanjangan(idTb: number, isian: StudiIsian, berkas: BerkasForm): Promise<void> {
      await api.post(`${base}/${idTb}/perpanjangan`, formData(isian, berkas))
    },

    async ubahPerpanjangan(id: number, isian: { tgl_mulai_perpan: string; tgl_akhir_perpan: string }): Promise<void> {
      await api.put(`${base}/perpanjangan/${id}`, isian)
    },

    async hapusPerpanjangan(id: number): Promise<void> {
      await api.delete(`${base}/perpanjangan/${id}`)
    },
  }
}

export type StudiService = ReturnType<typeof createStudiService>
export const tbService = createStudiService('tb')
export const ibService = createStudiService('ib')
