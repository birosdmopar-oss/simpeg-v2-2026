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
