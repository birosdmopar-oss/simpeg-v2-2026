/**
 * Registry tab riwayat (Sprint 0-2 §2.3.5): satu berkas per jenis `riwayat/jenis/<slug>.ts`, dimuat
 * `import.meta.glob`; nama berkas = slug beku; karpeg/kariskarsu/konket bukan tab; tab dirender dari descriptor.
 * Juga skema Zod dinamis form riwayat.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

import { RIWAYAT_REGISTRY, configFor, tabsFromDescriptors } from '../riwayat/registry'
import { buildRiwayatSchema } from '../riwayat/riwayat.schema'
import { JENIS_ENGINE, JENIS_NON_ENGINE } from '../types'

import { descriptor } from './fixtures'

const EXPECTED = [
  'ak',
  'alamat',
  'diklat',
  'hukdis',
  'jabatan',
  'keluarga',
  'kgb',
  'kp',
  'lkh',
  'organisasi',
  'pendidikan',
  'seminar',
  'skp',
  'skp-periodik',
  'tanda-jasa',
]

describe('registry riwayat', () => {
  it('15 berkas sesuai tabel §2.3.5 (ak-siasn ditambah WS-1 kemudian)', () => {
    expect(Object.keys(RIWAYAT_REGISTRY).sort()).toEqual(EXPECTED)
  })

  it('slug engine = 17 slug beku backend; non-engine = lkh', () => {
    expect(JENIS_ENGINE).toHaveLength(17)
    expect(JENIS_NON_ENGINE).toEqual(['lkh'])
    for (const slug of EXPECTED) expect([...JENIS_ENGINE, ...JENIS_NON_ENGINE]).toContain(slug)
  })

  it('karpeg, kariskarsu, dan konket bukan tab', () => {
    for (const slug of ['karpeg', 'kariskarsu', 'konket', 'riwayat-konket', 'karpeg-karis-karsu']) expect(configFor(slug)).toBeUndefined()
  })

  it.each(Object.values(RIWAYAT_REGISTRY))('$jenis: konfigurasi utuh (PK id_riwayat_*, kolom & isian unik, lkh non-engine)', (config) => {
    expect(config.primaryKey).toMatch(/^id_riwayat_[a-z_]+$/)
    expect(config.columns.length).toBeGreaterThan(0)
    expect(new Set(config.fields.map((f) => f.name)).size).toBe(config.fields.length)
    expect(config.engine).toBe(config.jenis !== 'lkh')
    for (const f of config.fields) {
      expect(f.name).toMatch(/^[a-z][a-z0-9_]*$/) // nama kolom DDL snake_case
      if (f.type === 'ref') expect(f.entity).toBeTruthy()
      if (f.type === 'select') expect(f.options?.length).toBeGreaterThan(0)
    }
  })

  it('aturan lampiran belum diisi di FE sampai disalin dari Definisi backend (tidak menebak dari fixture test)', () => {
    for (const config of Object.values(RIWAYAT_REGISTRY)) expect(config.lampiran, config.jenis).toEqual([])
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('tabsFromDescriptors: urutan backend, hanya can_view, jenis tanpa berkas registry dibuang (+ peringatan dev)', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    const tabs = tabsFromDescriptors([
      descriptor('kgb', 'Riwayat KGB'),
      descriptor('pendidikan', 'Riwayat Pendidikan', { can_view: false }),
      descriptor('karpeg', 'Karpeg'),
      descriptor('jabatan', 'Riwayat Jabatan'),
      descriptor('lkh', 'Laporan Kerja Harian'),
    ])
    expect(tabs.map((t) => t.descriptor.jenis)).toEqual(['kgb', 'jabatan', 'lkh'])
    expect(tabs[0].config.jenis).toBe('kgb')
    // Hanya karpeg (tanpa berkas) yang diperingatkan; pendidikan dibuang karena can_view=false, tanpa peringatan.
    expect(warn).toHaveBeenCalledTimes(1)
    expect(warn.mock.calls[0][0]).toContain('"karpeg"')
  })
})

describe('buildRiwayatSchema', () => {
  const schema = buildRiwayatSchema(configFor('alamat')?.fields ?? [])
  const valid = { jenis_alamat: '1', alamat_utama: '2', alamat: 'Jl. Contoh 1', id_provinsi: '', id_kabupaten_kota: '', id_kecamatan: '', id_kelurahan: '', kd_pos: '', keterangan: '' }

  it('nilai valid (opsional boleh kosong) lolos', () => {
    expect(schema.safeParse(valid).success).toBe(true)
  })

  it('wajib kosong & select di luar opsi ditolak dengan pesan', () => {
    const result = schema.safeParse({ ...valid, alamat: '', jenis_alamat: '9' })
    expect(result.success).toBe(false)
    const messages = result.success ? [] : result.error.issues.map((i) => i.message)
    expect(messages).toContain('Alamat wajib diisi')
    expect(messages).toContain('Jenis Alamat tidak valid')
  })

  it('angka & tanggal divalidasi formatnya', () => {
    const kgb = buildRiwayatSchema(configFor('kgb')?.fields ?? [])
    const result = kgb.safeParse({ no_sk: '', tgl_sk: '2024-13', tmtsk: '2024-01-01', mker_gol_th: 'abc', gaji_pokok: '', jym: '', keterangan: '' })
    const messages = result.success ? [] : result.error.issues.map((i) => i.message)
    expect(messages).toContain('Tanggal SK tidak valid')
    expect(messages).toContain('Masa Kerja Golongan (tahun) harus berupa angka')
  })
})
