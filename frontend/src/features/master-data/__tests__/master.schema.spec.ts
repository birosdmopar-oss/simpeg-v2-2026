/**
 * Modul G — skema form master generik & rantai induk (prioritas testing FE: schemas & composables, ADR-027).
 */
import { describe, expect, it } from 'vitest'

import { ancestorChain, buildMasterSchema } from '../schemas/master.schema'
import type { MasterMeta } from '../types'

const meta = (key: string, pk: string, name: string, parent: MasterMeta['parent'] = null, idLen = 10): MasterMeta => ({
  key,
  label: key,
  primary_key: pk,
  id_max_length: idLen,
  name_field: name,
  name_label: `Nama ${key}`,
  name_max_length: 100,
  parent,
  auto_increment: false,
  has_order: true,
  has_status: true,
  fields: [],
})

const provinsi = meta('provinsi', 'id_provinsi', 'nama_provinsi')
const kab = meta('kabupaten-kota', 'id_kabupaten_kota', 'nama_kabupaten_kota', { field: 'id_provinsi', entity: 'provinsi' })
const kec = meta('kecamatan', 'id_kecamatan', 'nama_kecamatan', { field: 'id_kabupaten_kota', entity: 'kabupaten-kota' })
const kel = meta('kelurahan', 'id_kelurahan', 'nama_kelurahan', { field: 'id_kecamatan', entity: 'kecamatan' })
const agama = meta('agama', 'id_agama', 'nama_agama', null, 5)
const all = [agama, provinsi, kab, kec, kel]

describe('buildMasterSchema', () => {
  it('create: kode + nama wajib, order opsional', () => {
    const schema = buildMasterSchema(agama, false)
    expect(schema.safeParse({ id_agama: '7', nama_agama: 'Kepercayaan', order: '' }).success).toBe(true)
    expect(schema.safeParse({ id_agama: '7', nama_agama: 'Kepercayaan', order: '3' }).success).toBe(true)

    const empty = schema.safeParse({ id_agama: '', nama_agama: '  ' })
    expect(empty.success).toBe(false)
    if (!empty.success) expect(empty.error.issues.map((i) => i.path[0])).toEqual(expect.arrayContaining(['id_agama', 'nama_agama']))
  })

  it('kode: panjang maksimal sesuai meta & tanpa spasi (sama dengan regex backend)', () => {
    const schema = buildMasterSchema(agama, false)
    expect(schema.safeParse({ id_agama: '123456', nama_agama: 'X' }).success).toBe(false)
    expect(schema.safeParse({ id_agama: 'A B', nama_agama: 'X' }).success).toBe(false)
    expect(schema.safeParse({ id_agama: 'A.b-1', nama_agama: 'X' }).success).toBe(true)
  })

  it('order harus bilangan bulat >= 1', () => {
    const schema = buildMasterSchema(agama, false)
    expect(schema.safeParse({ id_agama: '7', nama_agama: 'X', order: '0' }).success).toBe(false)
    expect(schema.safeParse({ id_agama: '7', nama_agama: 'X', order: '1.5' }).success).toBe(false)
  })

  it('edit: kode tidak divalidasi (tidak bisa diubah); master berinduk wajib memilih induk', () => {
    const schema = buildMasterSchema(kel, true)
    expect(schema.safeParse({ nama_kelurahan: 'Petojo', id_kecamatan: '317101' }).success).toBe(true)
    const noParent = schema.safeParse({ nama_kelurahan: 'Petojo', id_kecamatan: '' })
    expect(noParent.success).toBe(false)
    if (!noParent.success) expect(noParent.error.issues[0]?.path[0]).toBe('id_kecamatan')
  })
})

describe('ancestorChain', () => {
  it('kelurahan → provinsi, kabupaten-kota, kecamatan (akar dulu)', () => {
    expect(ancestorChain(kel, all).map((m) => m.key)).toEqual(['provinsi', 'kabupaten-kota', 'kecamatan'])
  })

  it('master tanpa induk → rantai kosong', () => {
    expect(ancestorChain(agama, all)).toEqual([])
    expect(ancestorChain(provinsi, all)).toEqual([])
  })

  it('tahan terhadap siklus konfigurasi', () => {
    const a = meta('a', 'id_a', 'nama_a', { field: 'id_b', entity: 'b' })
    const b = meta('b', 'id_b', 'nama_b', { field: 'id_a', entity: 'a' })
    expect(ancestorChain(a, [a, b]).map((m) => m.key)).toEqual(['a', 'b'])
  })
})
