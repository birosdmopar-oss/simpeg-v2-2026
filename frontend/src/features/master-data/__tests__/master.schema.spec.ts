/**
 * Modul G — skema form master generik & rantai induk (prioritas testing FE: schemas & composables, ADR-027).
 */
import { describe, expect, it } from 'vitest'

import { ancestorChain, buildMasterSchema, fieldsMissingFromRow, MASTER_HTML_MAX_BYTES } from '../schemas/master.schema'
import type { MasterMeta } from '../types'

const meta = (
  key: string,
  pk: string,
  name: string,
  parent: MasterMeta['parent'] = null,
  extra: Partial<MasterMeta> = {},
): MasterMeta => ({
  key,
  label: key,
  primary_key: pk,
  id_max_length: 10,
  id_digits: null,
  auto_increment: false,
  name_field: name,
  name_label: `Nama ${key}`,
  name_max_length: 255,
  has_order: true,
  has_status: true,
  parent,
  fields: [],
  ...extra,
})

// Skema legacy (DBV-001): kode wilayah tepat N digit, agama/jenis_* PK AUTO_INCREMENT, kolom legacy tambahan.
const provinsi = meta('provinsi', 'id_provinsi', 'provinsi', null, { id_digits: 2, id_max_length: 2 })
const kab = meta('kabupaten-kota', 'id_kabupaten_kota', 'kabupaten_kota', { field: 'id_provinsi', entity: 'provinsi' }, { id_digits: 4 })
const kec = meta('kecamatan', 'id_kecamatan', 'kecamatan', { field: 'id_kabupaten_kota', entity: 'kabupaten-kota' }, { id_digits: 7 })
const kel = meta('kelurahan', 'id_kelurahan', 'kelurahan', { field: 'id_kecamatan', entity: 'kecamatan' }, { id_digits: 10 })
const agama = meta('agama', 'id_agama', 'agama', null, { auto_increment: true, id_max_length: 3, name_max_length: 30 })
const jenisStatus = meta('jenis-status', 'id_jenis_status', 'jenis_status', null, {
  auto_increment: true,
  fields: [
    {
      name: 'status_pegawai',
      label: 'Status Pegawai',
      type: 'select',
      required: true,
      options: [
        { value: '1', label: 'Aktif' },
        { value: '2', label: 'Tidak Aktif' },
      ],
      hint: null,
    },
  ],
})
const kodeBebas = meta('kode-bebas', 'id_kode', 'nama_kode', null, { id_max_length: 5 })
const all = [agama, provinsi, kab, kec, kel]

describe('buildMasterSchema', () => {
  it('create master AUTO_INCREMENT: kode tidak diminta, nama wajib, order opsional', () => {
    const schema = buildMasterSchema(agama, false)
    expect(schema.safeParse({ agama: 'Kepercayaan', order: '' }).success).toBe(true)
    expect(schema.safeParse({ agama: 'Kepercayaan', order: '3' }).success).toBe(true)

    const empty = schema.safeParse({ agama: '  ' })
    expect(empty.success).toBe(false)
    if (!empty.success) expect(empty.error.issues.map((i) => i.path[0])).toEqual(['agama'])
  })

  it('kode wilayah legacy: tepat N digit angka tanpa titik (sama dengan rules backend)', () => {
    const schema = buildMasterSchema(kec, false)
    const base = { kecamatan: 'Gambir', id_kabupaten_kota: '3171' }
    expect(schema.safeParse({ ...base, id_kecamatan: '3171010' }).success).toBe(true)
    expect(schema.safeParse({ ...base, id_kecamatan: '317101' }).success).toBe(false)
    expect(schema.safeParse({ ...base, id_kecamatan: '31710100' }).success).toBe(false)
    expect(schema.safeParse({ ...base, id_kecamatan: '31.71.01' }).success).toBe(false)
    expect(buildMasterSchema(provinsi, false).safeParse({ id_provinsi: '3A', provinsi: 'X' }).success).toBe(false)
  })

  it('kode bebas: panjang maksimal sesuai meta & tanpa spasi', () => {
    const schema = buildMasterSchema(kodeBebas, false)
    expect(schema.safeParse({ id_kode: '123456', nama_kode: 'X' }).success).toBe(false)
    expect(schema.safeParse({ id_kode: 'A B', nama_kode: 'X' }).success).toBe(false)
    expect(schema.safeParse({ id_kode: 'A.b-1', nama_kode: 'X' }).success).toBe(true)
  })

  it('field tambahan select wajib (status_pegawai) hanya menerima opsi yang ada', () => {
    const schema = buildMasterSchema(jenisStatus, false)
    expect(schema.safeParse({ jenis_status: 'Pensiun', status_pegawai: '2' }).success).toBe(true)
    expect(schema.safeParse({ jenis_status: 'Pensiun', status_pegawai: '3' }).success).toBe(false)
    const missing = schema.safeParse({ jenis_status: 'Pensiun', status_pegawai: '' })
    expect(missing.success).toBe(false)
    if (!missing.success) expect(missing.error.issues[0]?.path[0]).toBe('status_pegawai')
  })

  it('order harus bilangan bulat >= 1', () => {
    const schema = buildMasterSchema(agama, false)
    expect(schema.safeParse({ agama: 'X', order: '0' }).success).toBe(false)
    expect(schema.safeParse({ agama: 'X', order: '1.5' }).success).toBe(false)
  })

  it('edit: kode tidak divalidasi (tidak bisa diubah); master berinduk wajib memilih induk', () => {
    const schema = buildMasterSchema(kel, true)
    expect(schema.safeParse({ kelurahan: 'Petojo', id_kecamatan: '3171010' }).success).toBe(true)
    const noParent = schema.safeParse({ kelurahan: 'Petojo', id_kecamatan: '' })
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

describe('field html & batas byte (DBV-002 E3/E5)', () => {
  const faqArticle = meta(
    'faq-article',
    'id_faq_article',
    'title',
    { field: 'id_faq_sub_topic', entity: 'faq-sub-topic' },
    {
      auto_increment: true,
      fields: [{ name: 'content', label: 'Isi Artikel', type: 'html', required: true, options: null, hint: null }],
    },
  )
  const faqTopic = meta('faq-topic', 'id_faq_topic', 'faq_topic', null, {
    auto_increment: true,
    fields: [{ name: 'remark', label: 'Keterangan', type: 'textarea', required: false, options: null, hint: null, max_bytes: 255 }],
  })
  const base = { title: 'Cara reset password', id_faq_sub_topic: '1' }

  it('html wajib: kosong / spasi saja ditolak dengan pesan Indonesia', () => {
    const schema = buildMasterSchema(faqArticle, false)
    expect(schema.safeParse({ ...base, content: '<p>Buka menu Akun.</p>' }).success).toBe(true)
    for (const content of ['', '   ', undefined]) {
      const result = schema.safeParse({ ...base, content })
      expect(result.success).toBe(false)
      if (!result.success) {
        expect(result.error.issues[0]?.path[0]).toBe('content')
        expect(result.error.issues[0]?.message).toBe('Isi Artikel wajib diisi.')
      }
    }
  })

  it('html dibatasi 1.000.000 byte bila meta tidak menyebut max_bytes', () => {
    const schema = buildMasterSchema(faqArticle, false)
    expect(MASTER_HTML_MAX_BYTES).toBe(1_000_000)
    expect(schema.safeParse({ ...base, content: 'a'.repeat(MASTER_HTML_MAX_BYTES) }).success).toBe(true)
    const tooLong = schema.safeParse({ ...base, content: 'a'.repeat(MASTER_HTML_MAX_BYTES + 1) })
    expect(tooLong.success).toBe(false)
    if (!tooLong.success) expect(tooLong.error.issues[0]?.message).toBe('Isi Artikel maksimal 1.000.000 byte.')
  })

  it('max_bytes dari meta dihitung byte UTF-8, bukan karakter (TINYTEXT remark)', () => {
    const schema = buildMasterSchema(faqTopic, false)
    expect(schema.safeParse({ faq_topic: 'Akun', remark: '' }).success).toBe(true)
    expect(schema.safeParse({ faq_topic: 'Akun', remark: 'a'.repeat(255) }).success).toBe(true)
    // 128 karakter "é" = 256 byte → ditolak walau < 255 karakter.
    const multibyte = schema.safeParse({ faq_topic: 'Akun', remark: 'é'.repeat(128) })
    expect(multibyte.success).toBe(false)
    if (!multibyte.success) expect(multibyte.error.issues[0]?.message).toBe('Keterangan maksimal 255 byte.')
  })
})

describe('fieldsMissingFromRow (listExclude)', () => {
  const faqArticle = meta('faq-article', 'id_faq_article', 'title', null, {
    fields: [{ name: 'content', label: 'Isi Artikel', type: 'html', required: true, options: null, hint: null }],
  })

  it('field yang tidak ada di baris list harus dimuat dari detail saat edit', () => {
    expect(fieldsMissingFromRow(faqArticle, { id_faq_article: 1, title: 'A', order: 1, status: '1' }).map((f) => f.name)).toEqual(['content'])
    expect(fieldsMissingFromRow(faqArticle, { id_faq_article: 1, title: 'A', content: '' })).toEqual([])
    expect(fieldsMissingFromRow(faqArticle, null)).toEqual([])
  })
})
