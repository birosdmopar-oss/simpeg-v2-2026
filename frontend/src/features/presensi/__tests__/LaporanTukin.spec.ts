/**
 * MAKE-048 — halaman Laporan Tukin (klien asli, adapter palsu): halaman daftar (lingkup role 1 vs 3, filter, ⋮ Lihat laporan → Unduh PDF) serta laporan per pegawai (identitas, tabel
 * harian ber-color-coding legacy, baris non-kerja bila API mengirim, ringkasan, periode berjalan, galat 403/422, "Saya").
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, { status: number; body: unknown; headers?: Record<string, string> }>,
  permintaan: [] as { method: string; url: string; params: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const url = config.url ?? ''
    const method = (config.method ?? 'get').toLowerCase()
    server.permintaan.push({ method, url, params: config.params })
    const balasan = server.balasan[`${method} ${url}`]
    if (!balasan) throw new Error(`tidak ada balasan palsu untuk ${method} ${url}`)
    const response: AxiosResponse = { status: balasan.status, statusText: 'x', headers: balasan.headers ?? {}, config, data: balasan.body }
    if (balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})
vi.mock('@/features/master-data/services/master.service', () => ({ masterService: { options: vi.fn(async () => []) } }))

import { Role, type RoleCode } from '@/features/auth/types'
import * as berkas from '@/features/kepegawaian/services/berkasUnduhan'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import LaporanTukinDaftarPage from '../views/LaporanTukinDaftarPage.vue'
import LaporanTukinPegawaiPage from '../views/LaporanTukinPegawaiPage.vue'

enableAutoUnmount(afterEach)

const NIP = '199001012020011001'
const sukses = (data: unknown) => ({ status: 200, body: { status: 'success', data } })
const galat = (status: number, code?: string, errors?: Record<string, string[]>) => ({
  status,
  body: { status: 'error', message: 'x', ...(code ? { code } : {}), ...(errors ? { errors } : {}) },
})

const pegawai = (nip = NIP, nama = 'Budi Santoso') => ({
  nip, nama, gol_ruang: 'III/c', pangkat: 'Penata', jabatan: 'Analis', eselon: '', unit: 'Biro Uji', satker: 'Satker Uji',
  pendidikan: { strata: 'S1', jurusan: 'HUKUM', institusi: 'Univ Uji' }, jenis_pegawai: 'PNS', aksi: { lihat_laporan: true },
})
const daftar = (items = [pegawai()]) => ({
  items, total: String(items.length), page: '1', per_page: '25', lingkup: null,
  bulan_tersedia: [{ bulan: '2026-10', label: 'Oktober 2026' }],
})
const harian = (tanggal: string, status: string, kode: string[] = [], ekstra: Record<string, unknown> = {}) => ({
  tanggal, status, kode, jam_masuk: null, jam_pulang: null, potongan: '0.00', keterangan: '', kategori_konket: null, ...ekstra,
})
const laporan = (ekstra: Record<string, unknown> = {}) => ({
  identitas: { nip: NIP, nama: 'Budi Santoso', gol_ruang: 'III/c', satker: 'Satker Uji', jabatan: 'Analis', kelas_jabatan: '9', id_unit: '1', id_satker: '2', predikat_skp: 'Baik' },
  periode: { awal: '2026-09-16', akhir: '2026-10-15', dihitung_sampai: '2026-10-15', sumber: 'bawaan' },
  harian: [
    harian('2026-10-01', 'hadir', ['TL2', 'PSW1'], { jam_masuk: '07:45:00', jam_pulang: '16:00:00', potongan: '0.40', keterangan: 'TL2 - PSW1' }),
    harian('2026-10-02', 'TK', ['TK'], { potongan: '1.00', keterangan: 'TK' }),
    harian('2026-10-03', 'cuti', [], { keterangan: 'Cuti tahunan' }),
    harian('2026-10-04', 'tugas_belajar', [], { keterangan: 'Tugas Belajar' }),
    harian('2026-10-05', 'konket', [], { keterangan: 'Konket', kategori_konket: '13' }),
    harian('2026-10-10', 'akhir_pekan', [], { keterangan: 'Sabtu' }),
    harian('2026-10-14', 'libur', [], { keterangan: '(Libur) Hari Uji' }),
    harian('2026-09-16', 'di_luar_masa_kerja'),
  ],
  jumlah: { TL1: '0', TL2: '1', PSW1: '1', TK: '1', CS: '0' },
  rincian: { TL: '0.2', PSW: '0.2', TA: '0', TK: '1', CUTI: '0' },
  total_presensi: '1.4', total_skp: '0', total_potongan: '1.4', alasan: null, penyesuaian: null, keterangan: '',
  ...ekstra,
})

/** Permintaan ke `url` (shell juga memanggil API lain, mis. pengingat cuti). */
const minta = (url: string) => server.permintaan.filter((p) => p.url === url)

beforeEach(() => {
  server.balasan = {}
  server.permintaan = []
})

const q = (s: string): HTMLElement | null => document.body.querySelector<HTMLElement>(s)

async function mountDaftar(role: RoleCode = Role.SUPER_ADMIN, il = false) {
  loginAs(role)
  const router = createShellRouter()
  router.addRoute({ path: '/presensi/tukin', name: 'presensi-tukin', component: { render: () => null } })
  router.addRoute({ path: '/presensi/tukin/il', name: 'presensi-tukin-il', component: { render: () => null } })
  router.addRoute({ path: '/presensi/tukin/:nip', name: 'presensi-tukin-pegawai', component: { render: () => null } })
  await router.push('/presensi/tukin')
  const wrapper = mount(LaporanTukinDaftarPage, { props: { il }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  await flushPromises()
  return { wrapper, router }
}

describe('LaporanTukinDaftarPage', () => {
  it('role 1: filter Unit/Satker tampil, parameter awal tanpa bulan, tabel + ⋮ Lihat laporan → Unduh PDF', async () => {
    server.balasan['get /presensi/tukin/pegawai'] = sukses(daftar())
    const { wrapper } = await mountDaftar(Role.SUPER_ADMIN)

    expect(wrapper.get('[data-testid="filter-laporan"]').text()).toMatch(/Unit/)
    expect(minta('/presensi/tukin/pegawai')[0]!.params).toEqual({ page: 1, per_page: 25, unit_satker: '0' })
    expect(wrapper.get(`[data-testid="tukin-row-${NIP}"]`).text()).toContain('Budi Santoso')
    expect((await rowMenuActions(wrapper, `tukin-actions-${NIP}`)).map((a) => [a.key, a.label])).toEqual([['lihat', 'Lihat laporan'], ['pdf', 'Unduh PDF']])
  })

  it('role 3: tidak ada pemilih Unit/Satker dan tidak mengirim unit_satker (dipaksa server)', async () => {
    server.balasan['get /presensi/tukin/pegawai'] = sukses(daftar())
    const { wrapper } = await mountDaftar(Role.ADMIN_SATKER)

    expect(minta('/presensi/tukin/pegawai')[0]!.params).toEqual({ page: 1, per_page: 25 })
    expect(wrapper.get('[data-testid="filter-laporan"]').text()).not.toMatch(/Unit|Satker/)
    expect(wrapper.find('[data-testid="tukin-tab-il"]').exists()).toBe(false)
  })

  it('Lihat laporan membawa bulan filter ke halaman laporan', async () => {
    server.balasan['get /presensi/tukin/pegawai'] = sukses(daftar())
    const { wrapper, router } = await mountDaftar()
    await selectRowAction(wrapper, `tukin-actions-${NIP}`, 'lihat')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('presensi-tukin-pegawai')
    expect(router.currentRoute.value.params.nip).toBe(NIP)
    expect(String(router.currentRoute.value.query.bulan)).toMatch(/^\d{4}-\d{2}$/)
  })

  it('Unduh PDF menyimpan berkas dari server; gagal → pesan, tidak menyimpan', async () => {
    server.balasan['get /presensi/tukin/pegawai'] = sukses(daftar())
    server.balasan[`get /presensi/tukin/laporan/${NIP}/pdf`] = {
      status: 200, body: new Blob(['x']), headers: { 'content-disposition': `attachment; filename="tukin_${NIP}_2026-10.pdf"` },
    }
    const simpan = vi.spyOn(berkas, 'simpanBerkas').mockImplementation(() => undefined)
    const { wrapper } = await mountDaftar()
    await selectRowAction(wrapper, `tukin-actions-${NIP}`, 'pdf')
    await flushPromises()
    expect(simpan).toHaveBeenCalledWith({ blob: expect.any(Blob), nama: `tukin_${NIP}_2026-10.pdf` })

    simpan.mockClear()
    server.balasan[`get /presensi/tukin/laporan/${NIP}/pdf`] = galat(422, 'data_tukin_belum_tersedia')
    await selectRowAction(wrapper, `tukin-actions-${NIP}`, 'pdf')
    await flushPromises()
    expect(q('[data-testid="tukin-unduh-galat"]')?.textContent).toContain('belum tersedia')
    expect(simpan).not.toHaveBeenCalled()
    simpan.mockRestore()
  })

  it('daftar kosong dan galat 403 ditampilkan sebagai pesan', async () => {
    server.balasan['get /presensi/tukin/pegawai'] = sukses(daftar([]))
    expect((await mountDaftar()).wrapper.find('[data-testid="tukin-pegawai-kosong"]').exists()).toBe(true)

    server.balasan['get /presensi/tukin/pegawai'] = galat(403)
    expect((await mountDaftar()).wrapper.get('[data-testid="tukin-daftar-galat"]').text()).toContain('tidak berhak')
  })

  it('varian Instansi Lain memakai /pegawai-il tanpa filter unit/jenis', async () => {
    server.balasan['get /presensi/tukin/pegawai-il'] = sukses(daftar())
    const { wrapper } = await mountDaftar(Role.SUPER_ADMIN, true)
    expect(minta('/presensi/tukin/pegawai-il')[0]).toMatchObject({ params: { page: 1, per_page: 25 } })
    expect(wrapper.find('[data-testid="filter-jenis-pegawai"]').exists()).toBe(false)
    expect(wrapper.get('h1').text()).toBe('Laporan Tukin Instansi Lain')
  })
})

async function mountLaporan(opts: { role?: RoleCode; saya?: boolean; nip?: string; query?: string } = {}) {
  loginAs(opts.role ?? Role.SUPER_ADMIN, { nip: opts.saya ? NIP : null })
  const router = createShellRouter()
  router.addRoute({ path: '/presensi/tukin/:nip', name: 'presensi-tukin-pegawai', component: { render: () => null } })
  router.addRoute({ path: '/presensi/tukin/saya', name: 'presensi-tukin-saya', component: { render: () => null } })
  await router.push(opts.saya ? '/presensi/tukin/saya' : `/presensi/tukin/${opts.nip ?? NIP}${opts.query ?? '?bulan=2026-10'}`)
  const wrapper = mount(LaporanTukinPegawaiPage, { props: { saya: opts.saya ?? false }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  await flushPromises()
  return { wrapper, router }
}

describe('LaporanTukinPegawaiPage', () => {
  it('identitas, periode, ringkasan kode, rincian, total (koma desimal)', async () => {
    server.balasan[`get /presensi/tukin/laporan/${NIP}`] = sukses(laporan())
    const { wrapper } = await mountLaporan()

    expect(minta(`/presensi/tukin/laporan/${NIP}`)[0]!.params).toEqual({ bulan: '2026-10' })
    expect(wrapper.get('[data-testid="tukin-identitas"]').text()).toContain('Budi Santoso')
    expect(wrapper.get('[data-testid="tukin-identitas"]').text()).toContain('III/c')
    expect(wrapper.get('[data-testid="tukin-periode"]').text()).toContain('bawaan')
    expect(wrapper.find('[data-testid="tukin-berjalan"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="tukin-jumlah"] [data-kode="TL2"]').text()).toContain('1')
    expect(wrapper.get('[data-kolom="TK"]').text()).toBe('1%')
    expect(wrapper.get('[data-kolom="TL"]').text()).toBe('0,2%')
    expect(wrapper.get('[data-testid="tukin-total-potongan"]').text()).toBe('1,4%')
  })

  it('tabel harian: chip kode, warna baris ikut legacy per status, baris non-kerja tampil bila dikirim API', async () => {
    server.balasan[`get /presensi/tukin/laporan/${NIP}`] = sukses(laporan())
    const { wrapper } = await mountLaporan()

    const baris = (tgl: string) => wrapper.get(`[data-testid="harian-${tgl}"]`)
    expect(baris('2026-10-01').findAll('[data-kode]').map((c) => c.text())).toEqual(['TL2', 'PSW1'])
    expect(baris('2026-10-01').classes().join(' ')).not.toMatch(/status-/)
    expect(baris('2026-10-02').classes().join(' ')).not.toMatch(/status-/)
    expect(baris('2026-10-03').classes()).toContain('bg-status-ungu-soft/80')
    expect(baris('2026-10-04').classes()).toContain('bg-status-pink-soft/80')
    expect(baris('2026-10-05').classes()).toContain('bg-status-jingga-soft/70')
    expect(baris('2026-10-10').classes()).toContain('bg-status-abu-soft/80')
    expect(baris('2026-10-10').text()).toContain('Akhir pekan')
    expect(baris('2026-10-10').text()).toContain('Sabtu')
    expect(baris('2026-10-10').text()).toContain('0,00%')
    expect(baris('2026-10-10').findAll('td')[2]!.text()).toBe('-')
    expect(baris('2026-10-14').classes()).toContain('bg-status-biru-soft/70')
    expect(baris('2026-10-14').text()).toContain('(Libur) Hari Uji')
    expect(baris('2026-09-16').classes()).toContain('bg-status-abu-soft/80')
    expect(wrapper.find('[data-testid="legenda-status-presensi"]').exists()).toBe(true)
  })

  it('periode berjalan diberi catatan "dihitung sampai"; penyesuaian dan keterangan tampil', async () => {
    server.balasan[`get /presensi/tukin/laporan/${NIP}`] = sukses(
      laporan({
        periode: { awal: '2026-10-16', akhir: '2026-11-15', dihitung_sampai: '2026-10-28', sumber: 'bkn' },
        penyesuaian: { persen: '-20', alasan: 'Pemutihan' },
        keterangan: 'Cuti tahunan (1 Oktober 2026)',
      }),
    )
    const { wrapper } = await mountLaporan()
    expect(wrapper.get('[data-testid="tukin-berjalan"]').text()).toContain('28 Oktober 2026')
    expect(wrapper.get('[data-testid="tukin-periode"]').text()).toContain('BKN')
    expect(wrapper.get('[data-testid="tukin-penyesuaian"]').text()).toContain('Pemutihan')
    expect(wrapper.get('[data-testid="tukin-keterangan"]').text()).toContain('Cuti tahunan')
  })

  it('"Saya" memakai NIP akun tanpa input NIP; role tanpa NIP tidak memanggil API', async () => {
    server.balasan[`get /presensi/tukin/laporan/${NIP}`] = sukses(laporan())
    const { wrapper } = await mountLaporan({ role: Role.PEGAWAI, saya: true })
    expect(minta(`/presensi/tukin/laporan/${NIP}`)).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Laporan Tukin Saya')
    expect(wrapper.find('[data-testid="tukin-kembali"]').exists()).toBe(false)
  })

  it('NIP orang lain → 403 server tampil sebagai pesan, tanpa data', async () => {
    server.balasan['get /presensi/tukin/laporan/199901012021011009'] = galat(403)
    const { wrapper } = await mountLaporan({ role: Role.PEGAWAI, nip: '199901012021011009' })
    expect(wrapper.get('[data-testid="tukin-laporan-galat"]').text()).toContain('tidak berhak')
    expect(wrapper.find('[data-testid="tukin-identitas"]').exists()).toBe(false)
  })

  it('galat 422 terkendali menampilkan pesan per code', async () => {
    server.balasan[`get /presensi/tukin/laporan/${NIP}`] = galat(422, 'periode_tidak_didukung')
    const { wrapper } = await mountLaporan({ query: '?bulan=2024-01' })
    expect(wrapper.get('[data-testid="tukin-laporan-galat"]').text()).toContain('arsip SIMPEG lama')
  })

  it('Unduh PDF memakai bulan terpilih', async () => {
    server.balasan[`get /presensi/tukin/laporan/${NIP}`] = sukses(laporan())
    server.balasan[`get /presensi/tukin/laporan/${NIP}/pdf`] = {
      status: 200, body: new Blob(['x']), headers: { 'content-disposition': `attachment; filename="tukin_${NIP}_2026-10.pdf"` },
    }
    const simpan = vi.spyOn(berkas, 'simpanBerkas').mockImplementation(() => undefined)
    const { wrapper } = await mountLaporan()
    await wrapper.get('[data-testid="tukin-unduh-pdf"]').trigger('click')
    await flushPromises()
    expect(server.permintaan.at(-1)).toMatchObject({ url: `/presensi/tukin/laporan/${NIP}/pdf`, params: { bulan: '2026-10' } })
    expect(simpan).toHaveBeenCalled()
    simpan.mockRestore()
  })
})
