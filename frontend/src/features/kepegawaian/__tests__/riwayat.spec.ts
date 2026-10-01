/**
 * Mesin riwayat generik (17 tab Detail Pegawai, B-07…B-18) — integritas konfigurasi & data contoh, skema Zod dinamis,
 * layanan in-memory, dan alur tambah/ubah/hapus/cari/paginasi/hanya-lihat pada daftar maupun rekaman (Data Alamat).
 * VeeValidate menunda validasi ±5 ms → test menunggu timer sungguhan (settle).
 */
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import { RIWAYAT_MENUS } from '../options'
import { RIWAYAT_BY_KEY, RIWAYAT_CONFIGS, VERIFIKASI_STATUS } from '../riwayat/riwayat.config'
import { SAMPLES } from '../riwayat/riwayat.mock'
import { buildRiwayatSchema } from '../riwayat/riwayat.schema'
import { riwayatService } from '../riwayat/riwayat.service'
import RiwayatListSection from '../riwayat/RiwayatListSection.vue'
import RiwayatRecordSection from '../riwayat/RiwayatRecordSection.vue'

const NIP = '197001011995011001'

const settle = async (): Promise<void> => {
  await new Promise((resolve) => setTimeout(resolve, 40))
  await flushPromises()
}

beforeEach(() => riwayatService.reset())
afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('konfigurasi & data contoh', () => {
  it('17 tab (Data Umum ditangani halaman sendiri) dan setiap kuncinya ada di menu Detail Pegawai', () => {
    expect(RIWAYAT_CONFIGS).toHaveLength(17)
    expect(new Set(RIWAYAT_CONFIGS.map((c) => c.key)).size).toBe(17)
    const menuKeys = RIWAYAT_MENUS.map((m) => m.key)
    for (const c of RIWAYAT_CONFIGS) expect(menuKeys, c.key).toContain(c.key)
    expect(RIWAYAT_MENUS.filter((m) => !RIWAYAT_BY_KEY[m.key]).map((m) => m.key)).toEqual(['data-umum'])
  })

  it.each(RIWAYAT_CONFIGS.map((c) => [c.key, c] as const))('%s: nama isian unik, kolom merujuk isian, opsi select ada', (_key, c) => {
    const names = c.fields.map((f) => f.name)
    expect(new Set(names).size).toBe(names.length)
    for (const col of c.columns) expect(names, `kolom ${col.key}`).toContain(col.key)
    for (const f of c.fields.filter((x) => x.type === 'select')) expect(f.options?.length, f.name).toBeGreaterThan(0)
    if (c.kind === 'list') expect(c.fields.some((f) => f.required)).toBe(true)
  })

  it.each(RIWAYAT_CONFIGS.map((c) => [c.key, c] as const))('%s: setiap baris contoh lolos skema (data contoh valid)', (key, c) => {
    const schema = buildRiwayatSchema(c.fields)
    const samples = SAMPLES[key]
    expect(samples?.length, key).toBeGreaterThan(0)
    if (c.kind === 'record') expect(samples).toHaveLength(1)
    for (const row of samples) {
      const parsed = schema.safeParse(row)
      expect(parsed.success, `${key}: ${parsed.success ? '' : JSON.stringify(parsed.error.flatten().fieldErrors)}`).toBe(true)
    }
  })

  it('status verifikasi contoh mencakup ketiga nilai pada sedikitnya satu tab (Disetujui, Menunggu, Ditolak)', async () => {
    const seen = new Set<string>()
    for (const c of RIWAYAT_CONFIGS) for (const r of await riwayatService.list(NIP, c.key)) seen.add(r.status_verifikasi)
    expect([...seen].sort()).toEqual([...VERIFIKASI_STATUS].sort())
  })
})

describe('buildRiwayatSchema', () => {
  const schema = buildRiwayatSchema([
    { name: 'nama', label: 'Nama', type: 'text', required: true, max: 5 },
    { name: 'tahun', label: 'Tahun', type: 'number', required: true },
    { name: 'tgl', label: 'Tanggal', type: 'date' },
    { name: 'jenis', label: 'Jenis', type: 'select', options: ['A', 'B'] },
  ])
  const ok = { nama: 'Budi', tahun: '2020', tgl: '', jenis: '' }
  const msg = (v: Record<string, string>, field: string) => schema.safeParse(v).error?.flatten().fieldErrors[field]?.[0]

  it('nilai valid (opsional boleh kosong) lolos', () => {
    expect(schema.safeParse(ok).success).toBe(true)
    expect(schema.safeParse({ ...ok, tgl: '2026-09-29', jenis: 'A', tahun: '12.5' }).success).toBe(true)
  })

  it('wajib kosong, angka salah, tanggal salah, select di luar opsi, terlalu panjang', () => {
    expect(msg({ ...ok, nama: '  ' }, 'nama')).toBe('Nama wajib diisi')
    expect(msg({ ...ok, tahun: '' }, 'tahun')).toBe('Tahun wajib diisi')
    expect(msg({ ...ok, tahun: '20a' }, 'tahun')).toBe('Tahun harus berupa angka')
    expect(msg({ ...ok, tgl: '29/09/2026' }, 'tgl')).toBe('Tanggal tidak valid')
    expect(msg({ ...ok, jenis: 'Z' }, 'jenis')).toBe('Jenis tidak valid')
    expect(msg({ ...ok, nama: 'Terlalu panjang' }, 'nama')).toBe('Nama maksimal 5 karakter')
  })
})

describe('riwayatService (in-memory)', () => {
  it('daftar awal = contoh; tambah menaruh di atas dengan status "Menunggu Verifikasi"', async () => {
    const before = await riwayatService.list(NIP, 'riwayat-pendidikan')
    expect(before).toHaveLength(SAMPLES['riwayat-pendidikan'].length)
    const created = await riwayatService.create(NIP, 'riwayat-pendidikan', { jenjang: 'S3', institusi: 'X', tahun_lulus: '2030' })
    expect(created.status_verifikasi).toBe('Menunggu Verifikasi')
    const after = await riwayatService.list(NIP, 'riwayat-pendidikan')
    expect(after[0].id).toBe(created.id)
    expect(after).toHaveLength(before.length + 1)
  })

  it('ubah mereset status menjadi menunggu; hapus membuang baris; NIP dan tab saling terpisah', async () => {
    const [first] = await riwayatService.list(NIP, 'riwayat-pangkat')
    expect(first.status_verifikasi).toBe('Disetujui')
    const updated = await riwayatService.update(NIP, 'riwayat-pangkat', first.id, { golongan: 'IV/a', no_sk: 'x', tanggal_sk: '2026-01-01', tmt_golongan: '2026-02-01' })
    expect(updated.status_verifikasi).toBe('Menunggu Verifikasi')

    await riwayatService.remove(NIP, 'riwayat-pangkat', first.id)
    expect((await riwayatService.list(NIP, 'riwayat-pangkat')).some((r) => r.id === first.id)).toBe(false)
    expect(await riwayatService.list('999', 'riwayat-pangkat')).toHaveLength(SAMPLES['riwayat-pangkat'].length)
    expect(await riwayatService.list(NIP, 'riwayat-kgb')).toHaveLength(SAMPLES['riwayat-kgb'].length)
  })

  it('daftar yang dikembalikan adalah salinan (mengubahnya tidak mengubah penyimpanan)', async () => {
    const rows = await riwayatService.list(NIP, 'tanda-jasa')
    rows[0].nama_tanda_jasa = 'DIRUSAK'
    expect((await riwayatService.list(NIP, 'tanda-jasa'))[0].nama_tanda_jasa).not.toBe('DIRUSAK')
  })

  it('rekaman: getRecord tanpa id/status; saveRecord menimpa; kunci tak dikenal melempar error', async () => {
    const rec = await riwayatService.getRecord(NIP, 'data-alamat')
    expect(rec.kota).toBe('Jakarta Pusat')
    expect('id' in rec || 'status_verifikasi' in rec).toBe(false)
    await riwayatService.saveRecord(NIP, 'data-alamat', { ...rec, kota: 'Bandung' })
    expect((await riwayatService.getRecord(NIP, 'data-alamat')).kota).toBe('Bandung')
    await expect(riwayatService.list(NIP, 'ngawur')).rejects.toThrow('Tab riwayat tidak dikenal')
  })
})

/* ------------------------------ komponen ------------------------------ */

const listConfig = (key: string) => RIWAYAT_BY_KEY[key]

function mountList(key = 'riwayat-pendidikan', readonly = false) {
  return mount(RiwayatListSection, { props: { nip: NIP, config: listConfig(key), readonly }, attachTo: document.body })
}

/** Kontrol yang dilabeli `text` di seluruh dokumen (dialog di-portal ke body). */
function ctl(text: string): HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement {
  const label = [...document.body.querySelectorAll('label')].find((l) => l.textContent?.replace(/\s*\*$/, '').trim() === text)
  const el = label ? document.getElementById(label.getAttribute('for') ?? '') : null
  if (!el) throw new Error(`kontrol "${text}" tidak ada`)
  return el as HTMLInputElement
}

function setValue(text: string, value: string): void {
  const el = ctl(text)
  el.value = value
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }))
}

async function submitDialog(): Promise<void> {
  await settle()
  document.body.querySelector('[data-testid="riwayat-form"]')?.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
  await settle()
}

const rowsOf = (w: VueWrapper) => w.findAll('[data-testid^="riwayat-row-"]')

describe('RiwayatListSection', () => {
  it('menampilkan judul, kolom dari konfigurasi + Verifikasi + Aksi, dan baris contoh dengan badge status', async () => {
    const w = mountList()
    await settle()
    expect(w.text()).toContain('Riwayat Pendidikan')
    expect(w.findAll('thead th').map((th) => th.text())).toEqual(['Jenjang', 'Institusi', 'Jurusan', 'Tahun Lulus', 'No. Ijazah', 'Verifikasi', 'Aksi'])
    expect(rowsOf(w)).toHaveLength(3)
    expect(w.text()).toContain('Disetujui')
    expect(w.text()).toContain('Menunggu Verifikasi')
  })

  it('kolom tanggal diformat Indonesia dan nilai kosong menjadi "—"', async () => {
    const keluarga = mountList('data-keluarga')
    await settle()
    expect(keluarga.text()).toContain('2 Agustus 2014')
    keluarga.unmount()

    const org = mountList('riwayat-organisasi')
    await settle()
    expect(org.text()).toContain('—') // tahun_selesai kosong pada baris ke-2
  })

  it('pencarian menyaring & menampilkan keadaan kosong; dikosongkan → kembali', async () => {
    const w = mountList()
    await settle()
    await w.get('input[type="search"]').setValue('universitas')
    expect(rowsOf(w)).toHaveLength(1)
    await w.get('input[type="search"]').setValue('zzz-tidak-ada')
    expect(w.get('[data-testid="riwayat-empty"]').text()).toContain('Tidak ada data yang cocok')
    await w.get('input[type="search"]').setValue('')
    expect(rowsOf(w)).toHaveLength(3)
  })

  it('daftar kosong menawarkan tombol tambah', async () => {
    await riwayatService.remove(NIP, 'tanda-jasa', 1)
    await riwayatService.remove(NIP, 'tanda-jasa', 2)
    const w = mountList('tanda-jasa')
    await settle()
    expect(w.get('[data-testid="riwayat-empty"]').text()).toContain('Belum ada tanda jasa')
    expect(w.get('[data-testid="riwayat-empty"]').text()).toContain('Tambah tanda jasa')
  })

  it('paginasi 5 baris per halaman', async () => {
    for (let i = 0; i < 4; i++) await riwayatService.create(NIP, 'riwayat-pendidikan', { jenjang: 'S1', institusi: `Inst ${i}`, tahun_lulus: '2020' })
    const w = mountList()
    await settle()
    expect(rowsOf(w)).toHaveLength(5)
    expect(w.text()).toContain('Showing 1 to 5 of 7 entries')
    await w.findAll('nav[aria-label="Paginasi"] button').find((b) => b.text() === '2')?.trigger('click')
    expect(rowsOf(w)).toHaveLength(2)
  })

  it('aksi baris lewat menu ⋮: Edit lalu Hapus (merah); label aksesibel memuat nama baris', async () => {
    const w = mountList()
    await settle()
    const id = rowsOf(w)[0].attributes('data-testid')?.replace('riwayat-row-', '')
    const actions = await rowMenuActions(w, `riwayat-actions-${id}`)
    expect(actions.map((a) => a.label)).toEqual(['Edit', 'Hapus'])
    expect(actions[1].danger).toBe(true)
    expect(w.get(`[data-testid="riwayat-actions-${id}"]`).attributes('aria-label')).toBe('Aksi untuk SMA/SMK')
  })

  it('tambah: dialog terbuka, submit kosong menampilkan error, isian valid menambah baris di atas + pesan backend', async () => {
    const w = mountList()
    await settle()
    await w.get('[data-testid="riwayat-add"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-testid="riwayat-dialog"]')?.textContent).toContain('Tambah pendidikan')

    await submitDialog()
    expect(document.body.textContent).toContain('Jenjang wajib diisi')
    expect(document.body.textContent).toContain('Institusi wajib diisi')
    expect(rowsOf(w)).toHaveLength(3)

    setValue('Jenjang', 'S3')
    setValue('Institusi', 'Universitas Baru')
    setValue('Tahun Lulus', '2030')
    await submitDialog()

    expect(rowsOf(w)).toHaveLength(4)
    expect(rowsOf(w)[0].text()).toContain('Universitas Baru')
    expect(rowsOf(w)[0].text()).toContain('Menunggu Verifikasi')
    expect(w.get('[role="status"]').text()).toContain('B-10')
    expect(document.body.querySelector('[data-testid="riwayat-dialog"]')).toBeNull()
  })

  it('angka salah ditolak dengan pesan per field', async () => {
    const w = mountList()
    await settle()
    await w.get('[data-testid="riwayat-add"]').trigger('click')
    await flushPromises()
    setValue('Jenjang', 'S1')
    setValue('Institusi', 'X')
    setValue('Tahun Lulus', 'dua ribu')
    await submitDialog()
    expect(document.body.textContent).toContain('Tahun Lulus harus berupa angka')
    expect(rowsOf(w)).toHaveLength(3)
  })

  it('edit: dialog terisi nilai baris; simpan memperbarui baris dan mengembalikannya ke "Menunggu Verifikasi"', async () => {
    const w = mountList()
    await settle()
    const first = rowsOf(w)[0]
    const id = first.attributes('data-testid')?.replace('riwayat-row-', '') ?? ''
    await selectRowAction(w, `riwayat-actions-${id}`, 'edit')
    await flushPromises()
    expect(document.body.querySelector('[data-testid="riwayat-dialog"]')?.textContent).toContain('Edit pendidikan')
    expect(ctl('Institusi').value).toBe('SMA Negeri 1 Contoh')

    setValue('Institusi', 'SMA Negeri 99 Contoh')
    await submitDialog()
    expect(w.get(`[data-testid="riwayat-row-${id}"]`).text()).toContain('SMA Negeri 99 Contoh')
    expect(w.get(`[data-testid="riwayat-row-${id}"]`).text()).toContain('Menunggu Verifikasi')
  })

  async function openDelete(w: VueWrapper): Promise<string> {
    await settle()
    const id = rowsOf(w)[0].attributes('data-testid')?.replace('riwayat-row-', '') ?? ''
    await selectRowAction(w, `riwayat-actions-${id}`, 'hapus')
    return id
  }

  it('hapus: meminta konfirmasi; "Batal" tidak menghapus apa pun', async () => {
    const w = mountList()
    await openDelete(w)
    expect(document.body.querySelector('[role="alertdialog"]')?.textContent).toContain('Hapus pendidikan?')

    ;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Batal')?.click()
    await settle()
    expect(rowsOf(w)).toHaveLength(3)
    expect(w.find('[role="status"]').exists()).toBe(false)
  })

  it('hapus: "Ya, hapus" menghapus baris dan memberi pesan backend (B-10)', async () => {
    const w = mountList()
    const id = await openDelete(w)
    ;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await settle()
    expect(rowsOf(w)).toHaveLength(2)
    expect(w.find(`[data-testid="riwayat-row-${id}"]`).exists()).toBe(false)
    expect(w.get('[role="status"]').text()).toContain('B-10')
  })

  it('hanya-lihat: tanpa tombol tambah dan tanpa kolom Aksi, data tetap tampil', async () => {
    const w = mountList('riwayat-pendidikan', true)
    await settle()
    expect(w.find('[data-testid="riwayat-add"]').exists()).toBe(false)
    expect(w.findAll('thead th').map((th) => th.text())).not.toContain('Aksi')
    expect(rowsOf(w)).toHaveLength(3)
  })
})

describe('RiwayatRecordSection (Data Alamat)', () => {
  const mountRecord = (readonly = false) => mount(RiwayatRecordSection, { props: { nip: NIP, config: listConfig('data-alamat'), readonly }, attachTo: document.body })

  it('memuat rekaman ke dalam form; simpan valid memberi pesan jujur (B-16); batal mengembalikan', async () => {
    const w = mountRecord()
    await settle()
    expect(ctl('Kab./Kota').value).toBe('Jakarta Pusat')

    setValue('Kab./Kota', 'Depok')
    await settle()
    document.body.querySelector('[data-testid="riwayat-record-form"]')?.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await settle()
    expect(w.get('[role="status"]').text()).toContain('B-16')
    expect((await riwayatService.getRecord(NIP, 'data-alamat')).kota).toBe('Depok')

    setValue('Kab./Kota', 'Bekasi')
    await settle() // debounce validasi VeeValidate ±5 ms selesai dulu (pengguna nyata tidak bisa klik secepat itu)
    await w.get('[data-testid="riwayat-record-cancel"]').trigger('click')
    await settle()
    expect(ctl('Kab./Kota').value).toBe('Depok')
  })

  it('field wajib kosong → error dan tidak tersimpan', async () => {
    const w = mountRecord()
    await settle()
    setValue('Kelurahan/Desa', '')
    await settle()
    document.body.querySelector('[data-testid="riwayat-record-form"]')?.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await settle()
    expect(w.text()).toContain('Kelurahan/Desa wajib diisi')
    expect((await riwayatService.getRecord(NIP, 'data-alamat')).kelurahan).toBe('Cikini')
  })

  it('hanya-lihat: semua isian nonaktif dan tanpa tombol simpan', async () => {
    const w = mountRecord(true)
    await settle()
    expect(w.findAll('input, textarea').every((i) => i.attributes('disabled') !== undefined)).toBe(true)
    expect(w.find('[data-testid="riwayat-record-save"]').exists()).toBe(false)
  })
})

describe('semua 17 tab dapat dirender', () => {
  it.each(RIWAYAT_CONFIGS.map((c) => [c.key, c] as const))('%s', async (key, config) => {
    const Comp = config.kind === 'record' ? RiwayatRecordSection : RiwayatListSection
    const w = mount(Comp, { props: { nip: NIP, config, readonly: false }, attachTo: document.body })
    await settle()
    expect(w.get(`[data-testid="riwayat-section-${key}"]`).text()).toContain(config.title)
    if (config.kind === 'list') expect(rowsOf(w).length).toBeGreaterThan(0)
    else expect(w.findAll('input, textarea').length).toBeGreaterThan(3)
  })
})
