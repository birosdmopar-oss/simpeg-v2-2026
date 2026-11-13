/**
 * Klien Arsip (F-10, MAKE-091) lewat klien `api` ASLI dengan adapter axios tiruan — memastikan URL, parameter, dan bahwa
 * amplop `{status, data}` dibuka interceptor menjadi tipe respons (pelajaran `res.data.data`: mock `api.get` saja tidak
 * menangkap amplop yang lupa dibuka).
 */
import type { AxiosAdapter, InternalAxiosRequestConfig } from 'axios'
import { afterEach, describe, expect, it } from 'vitest'

import { api } from '@/lib/axios'

import { arsipService } from '../services/arsip.service'

import { NIP } from './fixtures'

const asli = api.defaults.adapter
let diminta: InternalAxiosRequestConfig[] = []

function pasangAdapter(data: unknown): void {
  diminta = []
  const adapter: AxiosAdapter = (config) => {
    diminta.push(config)
    return Promise.resolve({ data: { status: 'success', data }, status: 200, statusText: 'OK', headers: {}, config })
  }
  api.defaults.adapter = adapter
}

afterEach(() => {
  api.defaults.adapter = asli
})

describe('arsipService — amplop {status,data}', () => {
  it('folder: GET /pegawai/<nip>/arsip, amplop terbuka menjadi ArsipFolderResponse', async () => {
    const payload = { pegawai: { nip: NIP, nama: 'Budi', glr_depan: null, glr_belakang: null }, folder: [], total: 0, total_hilang: 0, total_tak_dapat_dibuka: 0, penyajian_berkas_legacy: 'publik', aksi: { tulis: false, jenis_hubungkan: [] } }
    pasangAdapter(payload)
    const hasil = await arsipService.folder(NIP)
    expect(diminta).toHaveLength(1)
    expect(diminta[0]?.method).toBe('get')
    expect(diminta[0]?.url).toBe(`/pegawai/${NIP}/arsip`)
    expect(hasil).toEqual(payload)
    expect(hasil.pegawai.nip).toBe(NIP)
  })

  it('berkas: GET /pegawai/<nip>/arsip/berkas, amplop terbuka menjadi { items, total, page, per_page }', async () => {
    const payload = { items: [], total: 0, page: 1, per_page: 25 }
    pasangAdapter(payload)
    const hasil = await arsipService.berkas(NIP, { page: 1, per_page: 25 })
    expect(diminta[0]?.url).toBe(`/pegawai/${NIP}/arsip/berkas`)
    expect(hasil).toEqual(payload)
    expect(Array.isArray(hasil.items)).toBe(true)
  })

  it('params: id_jenis_rwy=0 tetap terkirim, tanpa_jenis terkirim, q kosong tidak terkirim', async () => {
    pasangAdapter({ items: [], total: 0, page: 1, per_page: 25 })
    await arsipService.berkas(NIP, { id_jenis_rwy: 0, q: '', page: 1, per_page: 25 })
    expect(diminta[0]?.params).toEqual({ id_jenis_rwy: 0, page: 1, per_page: 25 })

    await arsipService.berkas(NIP, { id_jenis_rwy: 'tanpa_jenis', q: '100%_' })
    expect(diminta[1]?.params).toEqual({ id_jenis_rwy: 'tanpa_jenis', q: '100%_' })

    await arsipService.berkas(NIP)
    expect(diminta[2]?.params).toEqual({})
  })

  it('NIP di-encode di URL', async () => {
    pasangAdapter({ items: [], total: 0, page: 1, per_page: 25 })
    await arsipService.berkas('a/b')
    expect(diminta[0]?.url).toBe('/pegawai/a%2Fb/arsip/berkas')
  })
})

describe('arsipService — Tahap 2 (MAKE-096) lewat klien asli', () => {
  it('ubahNama: PATCH /pegawai/<nip>/arsip/berkas/<id> dengan body { tag }', async () => {
    pasangAdapter(null)
    await arsipService.ubahNama(NIP, 77, 'Nama Baru')
    expect(diminta[0]?.method).toBe('patch')
    expect(diminta[0]?.url).toBe(`/pegawai/${NIP}/arsip/berkas/77`)
    expect(JSON.parse(String(diminta[0]?.data))).toEqual({ tag: 'Nama Baru' })
  })

  it('hubungkan: POST …/berkas/<id>/hubungkan; kode 38 tanpa id_entri', async () => {
    pasangAdapter(null)
    await arsipService.hubungkan(NIP, 77, { id_riwayat: 9, id_entri: '456' })
    await arsipService.hubungkan(NIP, 77, { id_riwayat: 38 })
    expect(diminta[0]?.method).toBe('post')
    expect(diminta[0]?.url).toBe(`/pegawai/${NIP}/arsip/berkas/77/hubungkan`)
    expect(JSON.parse(String(diminta[0]?.data))).toEqual({ id_riwayat: 9, id_entri: '456' })
    expect(JSON.parse(String(diminta[1]?.data))).toEqual({ id_riwayat: 38 })
  })

  it('hapus: DELETE …/berkas/<id> (bukan GET)', async () => {
    pasangAdapter(null)
    await arsipService.hapus(NIP, 77)
    expect(diminta[0]?.method).toBe('delete')
    expect(diminta[0]?.url).toBe(`/pegawai/${NIP}/arsip/berkas/77`)
  })

  it('opsiHubungkan: GET …/opsi-hubungkan?id_riwayat=, amplop terbuka menjadi { items }', async () => {
    const payload = { id_riwayat: 9, butuh_data: true, items: [{ id_entri: '456', ringkasan_riwayat: 'Analis' }] }
    pasangAdapter(payload)
    const hasil = await arsipService.opsiHubungkan(NIP, 9)
    expect(diminta[0]?.method).toBe('get')
    expect(diminta[0]?.url).toBe(`/pegawai/${NIP}/arsip/opsi-hubungkan`)
    expect(diminta[0]?.params).toEqual({ id_riwayat: 9 })
    expect(hasil).toEqual(payload)
  })

  it('belum_terhubung=1 hanya dikirim bila true (daftar berkas)', async () => {
    pasangAdapter({ items: [], total: 0, page: 1, per_page: 25 })
    await arsipService.berkas(NIP, { belum_terhubung: true, id_jenis_rwy: 9 })
    await arsipService.berkas(NIP, { belum_terhubung: false })
    expect(diminta[0]?.params).toEqual({ id_jenis_rwy: 9, belum_terhubung: 1 })
    expect(diminta[1]?.params).toEqual({})
  })

  it('ekspor: GET …/ekspor?format=&filter sama, responseType blob, tanpa page/per_page/sort', async () => {
    pasangAdapter(new Blob(['x']))
    await arsipService.ekspor(NIP, 'csv', { id_jenis_rwy: 'tanpa_jenis', belum_terhubung: true, q: 'sk', page: 3, per_page: 25, sort: 'nama', order: 'asc' })
    expect(diminta[0]?.method).toBe('get')
    expect(diminta[0]?.url).toBe(`/pegawai/${NIP}/arsip/ekspor`)
    expect(diminta[0]?.responseType).toBe('blob')
    expect(diminta[0]?.params).toEqual({ format: 'csv', id_jenis_rwy: 'tanpa_jenis', belum_terhubung: 1, q: 'sk' })
  })
})
