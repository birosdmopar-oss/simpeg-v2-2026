/**
 * Modul G — skema form untuk master dengan aturan khusus:
 * field tambahan generik (G-02/G-03/G-06), hari libur (G-08), dan web config (G-09).
 */
import { describe, expect, it } from 'vitest'

import { buildMasterSchema, hariLiburSchema, webConfigSchema, WEB_CONFIG_VALUE_RULES } from '../schemas/master.schema'
import type { MasterFieldMeta, MasterMeta } from '../types'

const baseMeta = (fields: MasterFieldMeta[], overrides: Partial<MasterMeta> = {}): MasterMeta => ({
  key: 'contoh',
  label: 'Contoh',
  primary_key: 'id_contoh',
  id_max_length: 5,
  auto_increment: false,
  name_field: 'nama',
  name_label: 'Nama',
  name_max_length: 100,
  has_order: true,
  has_status: true,
  parent: null,
  fields,
  ...overrides,
})

const field = (over: Partial<MasterFieldMeta> & { name: string }): MasterFieldMeta => ({
  label: over.name,
  type: 'text',
  required: false,
  options: null,
  hint: null,
  ...over,
})

describe('buildMasterSchema — field tambahan', () => {
  it('field wajib bertipe angka: menolak teks & kosong, menerima angka (mis. radius lokasi presensi)', () => {
    const schema = buildMasterSchema(baseMeta([field({ name: 'radius_meter', type: 'int', required: true })]), false)

    expect(schema.safeParse({ id_contoh: 'L01', nama: 'Kantor', radius_meter: '50' }).success).toBe(true)
    expect(schema.safeParse({ id_contoh: 'L01', nama: 'Kantor', radius_meter: 'lima' }).success).toBe(false)
    expect(schema.safeParse({ id_contoh: 'L01', nama: 'Kantor', radius_meter: '' }).success).toBe(false)
    expect(schema.safeParse({ id_contoh: 'L01', nama: 'Kantor', radius_meter: '10.5' }).success).toBe(false)
  })

  it('field opsional bertipe angka boleh dikosongkan (mis. zonasi satker)', () => {
    const schema = buildMasterSchema(baseMeta([field({ name: 'zonasi', type: 'int' })]), false)

    expect(schema.safeParse({ id_contoh: 'S1', nama: 'Satker', zonasi: '' }).success).toBe(true)
    expect(schema.safeParse({ id_contoh: 'S1', nama: 'Satker', zonasi: '120' }).success).toBe(true)
    expect(schema.safeParse({ id_contoh: 'S1', nama: 'Satker', zonasi: 'pagi' }).success).toBe(false)
  })

  it('field decimal menerima koordinat negatif (latitude/longitude)', () => {
    const schema = buildMasterSchema(
      baseMeta([
        field({ name: 'latitude', type: 'decimal', required: true }),
        field({ name: 'longitude', type: 'decimal', required: true }),
      ]),
      false,
    )

    expect(schema.safeParse({ id_contoh: 'L01', nama: 'Kantor', latitude: '-6.1753900', longitude: '106.8271500' }).success).toBe(true)
    expect(schema.safeParse({ id_contoh: 'L01', nama: 'Kantor', latitude: '-6,17', longitude: '106.8' }).success).toBe(false)
  })

  it('field select hanya menerima nilai dari daftar (mis. affect_tukin)', () => {
    const schema = buildMasterSchema(
      baseMeta([
        field({
          name: 'affect_tukin',
          type: 'select',
          required: true,
          options: [
            { value: '0', label: 'Tidak' },
            { value: '1', label: 'Ya' },
          ],
        }),
      ]),
      false,
    )

    expect(schema.safeParse({ id_contoh: 'WFH', nama: 'WFH', affect_tukin: '1' }).success).toBe(true)
    expect(schema.safeParse({ id_contoh: 'WFH', nama: 'WFH', affect_tukin: '9' }).success).toBe(false)
  })

  it('master ber-PK auto increment tidak meminta kode, master tanpa order tidak memvalidasi order', () => {
    const autoInc = buildMasterSchema(baseMeta([], { auto_increment: true }), false)
    expect(autoInc.safeParse({ nama: 'Unit Baru' }).success).toBe(true)

    const noOrder = buildMasterSchema(baseMeta([], { has_order: false, auto_increment: true }), false)
    const parsed = noOrder.safeParse({ nama: 'Pemetaan' })
    expect(parsed.success).toBe(true)
    if (parsed.success) expect('order' in parsed.data).toBe(false)
  })
})

describe('hariLiburSchema (G-08)', () => {
  const valid = { id_jenis_libur: 'NAS', nama: 'Tahun Baru', tgl_mulai: '2026-01-01', tgl_akhir: '2026-01-01' }

  it('menerima rentang valid, termasuk satu hari', () => {
    expect(hariLiburSchema.safeParse(valid).success).toBe(true)
    expect(hariLiburSchema.safeParse({ ...valid, tgl_akhir: '2026-01-05' }).success).toBe(true)
  })

  it('menolak tgl_akhir lebih awal dari tgl_mulai', () => {
    const result = hariLiburSchema.safeParse({ ...valid, tgl_mulai: '2026-01-05', tgl_akhir: '2026-01-01' })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues[0]?.path[0]).toBe('tgl_akhir')
      expect(result.error.issues[0]?.message).toMatch(/lebih awal/i)
    }
  })

  it('menolak tanggal kosong/format salah dan jenis libur kosong', () => {
    expect(hariLiburSchema.safeParse({ ...valid, tgl_mulai: '' }).success).toBe(false)
    expect(hariLiburSchema.safeParse({ ...valid, tgl_mulai: '01-01-2026' }).success).toBe(false)
    expect(hariLiburSchema.safeParse({ ...valid, id_jenis_libur: '' }).success).toBe(false)
  })
})

describe('webConfigSchema (G-09, MTC-012)', () => {
  const base = { config_name: 'tarif_uang_makan_pns', keterangan: '' }

  it.each([
    ['integer', '41000', true],
    ['integer', 'empat puluh ribu', false],
    ['integer', '41000.5', false],
    ['decimal', '0.5', true],
    ['decimal', '0,5', false],
    ['time', '07:30', true],
    ['time', '25:00', false],
    ['boolean', '1', true],
    ['boolean', 'kadang', false],
    ['string', 'KEMENTERIAN PARIWISATA', true],
  ] as const)('tipe %s dengan nilai "%s" → valid: %s', (tipe_data, config_value, expected) => {
    expect(webConfigSchema.safeParse({ ...base, tipe_data, config_value }).success).toBe(expected)
  })

  it('menolak key berspasi dan nilai kosong', () => {
    expect(webConfigSchema.safeParse({ ...base, config_name: 'ada spasi', tipe_data: 'string', config_value: 'x' }).success).toBe(false)
    expect(webConfigSchema.safeParse({ ...base, tipe_data: 'string', config_value: '' }).success).toBe(false)
  })

  it('pesan bantuan per tipe tersedia untuk ditampilkan di form', () => {
    expect(WEB_CONFIG_VALUE_RULES.integer.message).toMatch(/bilangan bulat/i)
    expect(WEB_CONFIG_VALUE_RULES.time.message).toMatch(/HH:MM/)
  })
})
