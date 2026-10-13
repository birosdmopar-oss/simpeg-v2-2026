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
  'ak-siasn',
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
  it('16 berkas: tabel §2.3.5 + ak-siasn (WS-1 M3)', () => {
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

  it('aturan lampiran = salinan Definisi backend (kode jenis_rwy, wajib, batas MB, jpg|jpeg|png|gif|pdf); jenis lain kosong', () => {
    const ext = ['jpg', 'jpeg', 'png', 'gif', 'pdf']
    const ringkas = (jenis: string) => configFor(jenis)?.lampiran.map((l) => [l.id_riwayat, l.wajib, l.batas_mb, l.ekstensi])
    // Pendidikan.php, Kp.php (MAKE-005), Kgb.php, Hukdis.php (MAKE-006).
    expect(ringkas('pendidikan')).toEqual([[14, true, 5, ext], [39, false, 5, ext], [40, false, 5, ext]])
    expect(ringkas('kp')).toEqual([[11, true, 5, ext]])
    expect(ringkas('kgb')).toEqual([[10, true, 5, ext]])
    expect(ringkas('hukdis')).toEqual([[7, true, 5, ext]])
    // MAKE-007: Keluarga (akte kode 3 per anak, bukan di sini), Alamat (KTP wajib kecuali kantor), Diklat/Seminar 1 MB.
    expect(ringkas('keluarga')).toEqual([[20, true, 5, ext]])
    expect(ringkas('alamat')).toEqual([[1, true, 5, ext]])
    expect(configFor('alamat')?.lampiran[0]?.wajibBila).toEqual({ field: 'jenis_alamat', notIn: ['3'] })
    expect(ringkas('diklat')).toEqual([[5, true, 1, ext]])
    expect(ringkas('seminar')).toEqual([[22, true, 1, ext]])
    expect(ringkas('tanda-jasa')).toEqual([[23, true, 5, ext]])
    expect(ringkas('organisasi')).toEqual([[13, true, 5, ext]])
    expect(ringkas('skp')).toEqual([[32, true, 5, ext]]) // MAKE-008
    // MAKE-012 (WS-2): Jabatan.php — SK 9 wajib, SK PS JFT 36 wajib bersyarat, perjanjian kerja 41 opsional.
    expect(ringkas('jabatan')).toEqual([[9, true, 5, ext], [36, true, 5, ext], [41, false, 5, ext]])
    expect(configFor('jabatan')?.lampiran[1]?.wajibBila).toEqual({ field: 'status_jft', in: ['2'] })
    const berlampiran = ['pendidikan', 'kp', 'kgb', 'hukdis', 'keluarga', 'alamat', 'diklat', 'seminar', 'tanda-jasa', 'organisasi', 'skp', 'jabatan']
    for (const config of Object.values(RIWAYAT_REGISTRY)) {
      if (!berlampiran.includes(config.jenis)) expect(config.lampiran, config.jenis).toEqual([])
    }
  })

  it('dependsOn menunjuk isian ref lain di jenis yang sama; showWhen menunjuk isian yang ada', () => {
    for (const config of Object.values(RIWAYAT_REGISTRY)) {
      const names = config.fields.map((f) => f.name)
      for (const f of config.fields) {
        if (f.dependsOn) expect(config.fields.find((g) => g.name === f.dependsOn)?.type, `${config.jenis}.${f.name}`).toBe('ref')
        if (f.showWhen) expect(names, `${config.jenis}.${f.name}`).toContain(f.showWhen.field)
      }
    }
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
  const kosong = Object.fromEntries((configFor('alamat')?.fields ?? []).map((f) => [f.name, '']))
  const valid = { ...kosong, jenis_alamat: '1', alamat_utama: '2', alamat: 'Jl. Contoh 1', id_provinsi: '31', id_kabupaten_kota: '3171', id_kecamatan: '3171010', id_kelurahan: '3171010001' }

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
    expect(messages).toContain('Masa Kerja Golongan (Tahun) harus berupa angka')
  })

  it('isian bersyarat (showWhen): wajib hanya saat tampil', () => {
    const schema = buildRiwayatSchema(configFor('pendidikan')?.fields ?? [])
    const dasar = { id_jenjang_pendidikan: '3', id_bidang_pendidikan: '', bidang_pendidikan_lain: '', id_jurusan_pendidikan: '', jurusan_pendidikan_lain: '', institusi_pendidikan: 'SMA 1', no_ijazah: 'X', tgl_lulus: '2008-06-30', no_sk_penc_glr: '', nem: '', ipk: '', keterangan: '' }
    expect(schema.safeParse(dasar).success).toBe(true) // SMA: bidang/jurusan tersembunyi

    const s1 = schema.safeParse({ ...dasar, id_jenjang_pendidikan: '7' })
    const paths = s1.success ? [] : s1.error.issues.map((i) => i.path.join('.'))
    expect(paths).toEqual(['id_bidang_pendidikan', 'id_jurusan_pendidikan'])

    const lain = schema.safeParse({ ...dasar, id_jenjang_pendidikan: '7', id_bidang_pendidikan: '98', id_jurusan_pendidikan: '10' })
    expect(lain.success ? [] : lain.error.issues.map((i) => i.message)).toEqual(['Bidang Pendidikan Lain wajib diisi'])
  })
})
