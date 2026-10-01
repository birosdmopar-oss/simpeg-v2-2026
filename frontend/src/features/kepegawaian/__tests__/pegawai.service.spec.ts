/**
 * pegawai.service (+ data contoh) — filter/paginasi meniru server, konsistensi data contoh dengan skema Data Umum,
 * bagan organisasi. Data contoh HARUS lolos dataUmumSchema: form Detail Pegawai membuka data ini apa adanya.
 */
import { describe, expect, it } from 'vitest'

import { dataUmumSchema } from '../schemas/dataUmum.schema'
import { FACETS, PEGAWAI_ROWS } from '../services/pegawai.mock'
import { filterPegawai, findOrgSubtree, pegawaiService } from '../services/pegawai.service'

const base = { page: 1, per_page: 10 }

describe('data contoh', () => {
  it('57 pegawai, NIP unik dan 18 digit, email memakai domain terpesan .test (bukan data asli)', () => {
    expect(PEGAWAI_ROWS).toHaveLength(57)
    expect(new Set(PEGAWAI_ROWS.map((r) => r.nip)).size).toBe(57)
    for (const row of PEGAWAI_ROWS) {
      expect(row.nip).toMatch(/^\d{18}$/)
      expect(row.email.endsWith('@contoh.test')).toBe(true)
    }
  })

  it('sebaran wajar: mayoritas PNS aktif; baris pertama bukan pensiun/PPPK', () => {
    const aktif = PEGAWAI_ROWS.filter((r) => r.status_pegawai === 'Aktif').length
    expect(aktif).toBeGreaterThan(50)
    expect(PEGAWAI_ROWS[0]).toMatchObject({ jenis_pegawai: 'PNS', status_pegawai: 'Aktif' })
  })

  it('setiap pegawai punya detail yang lolos skema Data Umum (semua field wajib valid)', async () => {
    for (const row of PEGAWAI_ROWS) {
      const detail = await pegawaiService.detail(row.nip)
      const parsed = dataUmumSchema.safeParse(detail?.data_umum)
      expect(parsed.success, `${row.nip}: ${parsed.success ? '' : JSON.stringify(parsed.error.flatten().fieldErrors)}`).toBe(true)
    }
  })

  it('tanggal lahir detail = ISO yang cocok dengan NIP', async () => {
    const detail = await pegawaiService.detail(PEGAWAI_ROWS[0].nip)
    const nip = PEGAWAI_ROWS[0].nip
    expect(detail?.data_umum.tanggal_lahir).toBe(`${nip.slice(0, 4)}-${nip.slice(4, 6)}-${nip.slice(6, 8)}`)
  })
})

describe('filterPegawai', () => {
  it('halaman pertama 10 baris dari total 57; halaman terakhir sisanya (7)', () => {
    const first = filterPegawai(base)
    expect(first.items).toHaveLength(10)
    expect(first.total).toBe(57)
    expect(filterPegawai({ ...base, page: 6 }).items).toHaveLength(7)
    expect(filterPegawai({ ...base, page: 7 }).items).toHaveLength(0)
  })

  it('page < 1 diperlakukan sebagai halaman 1', () => {
    expect(filterPegawai({ ...base, page: 0 }).items[0].nip).toBe(PEGAWAI_ROWS[0].nip)
  })

  it('pencarian nama tidak peka huruf besar/kecil dan juga mencari NIP', () => {
    const target = PEGAWAI_ROWS[5]
    expect(filterPegawai({ ...base, per_page: 100, search: target.nama.toUpperCase() }).items.map((r) => r.nip)).toContain(target.nip)
    expect(filterPegawai({ ...base, search: target.nip }).items.map((r) => r.nip)).toEqual([target.nip])
    expect(filterPegawai({ ...base, search: 'zzzz-tidak-ada' })).toEqual({ items: [], total: 0 })
  })

  it('filter panel: jenis pegawai, status, unit (satker), group + sub group', () => {
    const pns = filterPegawai({ ...base, per_page: 100, jenis_pegawai: 'PNS' })
    expect(pns.items.every((r) => r.jenis_pegawai === 'PNS')).toBe(true)

    const satker = FACETS.unit[0].value
    const bySatker = filterPegawai({ ...base, per_page: 100, unit: satker })
    expect(bySatker.total).toBeGreaterThan(0)
    expect(bySatker.items.every((r) => r.satuan_kerja === satker)).toBe(true)

    const fungsional = filterPegawai({ ...base, per_page: 100, group_jabatan: 'Jabatan Fungsional', sub_group_jabatan: 'Ahli Muda' })
    expect(fungsional.total).toBeGreaterThan(0)
    expect(fungsional.items.every((r) => r.group_jabatan === 'Jabatan Fungsional' && r.sub_group_jabatan === 'Ahli Muda')).toBe(true)
  })

  it('filter digabung bersifat AND', () => {
    const both = filterPegawai({ ...base, per_page: 100, jenis_pegawai: 'PNS', status_pegawai: 'Pensiun' })
    expect(both.items.every((r) => r.jenis_pegawai === 'PNS' && r.status_pegawai === 'Pensiun')).toBe(true)
    expect(both.total).toBeLessThan(filterPegawai({ ...base, per_page: 100, jenis_pegawai: 'PNS' }).total)
  })

  it('filter per kolom cocok sebagian; kunci kolom tak dikenal & nilai kosong diabaikan', () => {
    const gol = filterPegawai({ ...base, per_page: 100, columns: { golongan: 'IV/' } })
    expect(gol.total).toBeGreaterThan(0)
    expect(gol.items.every((r) => r.golongan.includes('IV/'))).toBe(true)

    expect(filterPegawai({ ...base, columns: { tidak_ada: 'x', jabatan: '' } }).total).toBe(57)
  })
})

describe('facets & bagan organisasi', () => {
  it('Periode SKP: 6 bulan berakhir September 2025; sub group tersedia untuk setiap group', async () => {
    const facets = await pegawaiService.facets()
    expect(facets.periode_skp).toHaveLength(6)
    expect(facets.periode_skp[0]).toEqual({ value: '2025-09', label: 'September 2025' })
    for (const group of facets.group_jabatan) expect(facets.sub_group_jabatan[group.value]?.length).toBeGreaterThan(0)
  })

  it('detail NIP tak dikenal → null (bukan error)', async () => {
    expect(await pegawaiService.detail('000')).toBeNull()
  })

  it('bagan: default seluruh pohon; subpohon per id; id tak dikenal → seluruh pohon', async () => {
    const full = await pegawaiService.orgTree()
    expect(full.id).toBe('menteri')
    expect((await pegawaiService.orgTree('sekmen')).children).toHaveLength(3)
    expect(findOrgSubtree('tidak-ada').id).toBe('menteri')
  })

  it('pilihan akar hanya simpul yang punya anak (subpohon bermakna)', async () => {
    const roots = await pegawaiService.orgRoots()
    expect(roots.map((r) => r.value)).toEqual(['menteri', 'wamen', 'sekmen'])
  })
})
