/**
 * DetailPegawaiPage (B-20) — data dari GET pegawai/{nip}; tab = "Data Umum" + descriptor `data.tabs` (urutan backend,
 * hanya can_view, hanya jenis yang punya berkas registry; karpeg/kariskarsu tidak pernah jadi tab); keadaan galat
 * 403/404/501 tanpa data contoh; ?tab= tersimpan; aksi cetak/arsip/hapus memberi tahu belum tersedia.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/pegawai.service', () => ({
  pegawaiService: { detail: vi.fn(), list: vi.fn(), updateBiodata: vi.fn(), koreksiNip: vi.fn(), foto: vi.fn(), pengajuan: vi.fn(), prosesPengajuan: vi.fn(), hapus: vi.fn(), fotoPengajuan: vi.fn(), drh: vi.fn() },
}))
vi.mock('@/features/master-data/services/master.service', () => ({ masterService: { options: vi.fn().mockResolvedValue([]) } }))
vi.mock('../services/lampiran.service', () => ({ lampiranService: { list: vi.fn().mockResolvedValue([]), upload: vi.fn(), download: vi.fn(), remove: vi.fn() } }))
vi.mock('../services/lkh.service', () => ({
  lkhService: { list: vi.fn().mockResolvedValue({ items: [], meta: { page: 1, per_page: 20, total: 0 } }), opsi: vi.fn().mockResolvedValue(null), pdf: vi.fn(), remove: vi.fn() },
}))
vi.mock('../riwayat/riwayat.service', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../riwayat/riwayat.service')>()
  return { ...actual, riwayatService: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), update: vi.fn(), remove: vi.fn(), process: vi.fn() } }
})

import { Role, type RoleCode } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { lampiranService } from '../services/lampiran.service'
import { lkhService } from '../services/lkh.service'
import { pegawaiService } from '../services/pegawai.service'
import DetailPegawaiPage from '../views/DetailPegawaiPage.vue'

import { apiError, descriptor, NIP, pegawaiDetail } from './fixtures'

const detail = vi.mocked(pegawaiService.detail)
const updateBiodata = vi.mocked(pegawaiService.updateBiodata)
const koreksiNip = vi.mocked(pegawaiService.koreksiNip)
const pengajuanDetail = vi.mocked(pegawaiService.pengajuan)
const prosesPengajuan = vi.mocked(pegawaiService.prosesPengajuan)
const hapus = vi.mocked(pegawaiService.hapus)
const fotoPengajuan = vi.mocked(pegawaiService.fotoPengajuan)

const TABS = [
  descriptor('jabatan', 'Riwayat Jabatan'),
  descriptor('pendidikan', 'Riwayat Pendidikan'),
  descriptor('kgb', 'Riwayat KGB', { can_view: false }),
  descriptor('karpeg', 'Karpeg'),
  descriptor('lkh', 'Laporan Kerja Harian'),
]

async function mountDetail(path = `/pegawai/${NIP}`, role: RoleCode = Role.SUPER_ADMIN, nipAkun: string | null = null) {
  loginAs(role, { nip: nipAkun })
  const router = createShellRouter()
  await router.push(path)
  await router.isReady()
  const wrapper = mount(DetailPegawaiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

const tabLabels = (w: ReturnType<typeof mount>) => w.findAll('[role="tab"]').map((t) => t.text())

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  detail.mockReset()
  detail.mockResolvedValue(pegawaiDetail(TABS))
})

describe('DetailPegawaiPage — data', () => {
  it('memanggil GET pegawai/{nip}; kartu identitas dari kolom DDL (glr_awal, nama, glr_akhir, status, tgl_lahir)', async () => {
    const { wrapper } = await mountDetail()
    expect(detail).toHaveBeenCalledWith(NIP)
    expect(wrapper.get('[data-testid="pegawai-name"]').text()).toBe('Dr. Budi Contoh, S.E., M.M.')
    expect(wrapper.get('[data-testid="pegawai-status"]').text()).toBe('Aktif')
    expect(wrapper.get('[data-testid="pegawai-header"]').text()).toContain('1 Januari 1990')
    // CR-049 D-F8: jenis pegawai badge berwarna + QR berisi NIP (dibangkitkan lokal).
    expect(wrapper.get('[data-testid="pegawai-jenis"]').classes()).toContain('text-brand-tertiary')
    await vi.waitFor(() => expect(wrapper.get('[data-testid="pegawai-qr"]').attributes('src')).toMatch(/^data:image\/png;base64,/))
    expect(wrapper.get('[data-testid="pegawai-qr"]').attributes('alt')).toBe(`Kode QR NIP ${NIP}`)
  })

  it('CR-051 R1D-F1: badge jenis dari id_jenis_pegawai (string API, nama master panjang)', async () => {
    detail.mockResolvedValue(pegawaiDetail(TABS, { id_jenis_pegawai: '6' as never, jenis_pegawai: 'Pegawai Pemerintah dengan Perjanjian Kerja' }))
    const { wrapper } = await mountDetail()
    expect(wrapper.get('[data-testid="pegawai-jenis"]').classes()).toContain('bg-info-soft')
    expect(wrapper.get('[data-testid="pegawai-jenis"]').text()).toBe('Pegawai Pemerintah dengan Perjanjian Kerja')
    // CR-054 (QA R2-I1): satu baris, dibatasi lebar wadah (elipsis di 390), teks penuh di title.
    const badge = wrapper.get('[data-testid="pegawai-jenis"]')
    expect(badge.classes()).toEqual(expect.arrayContaining(['whitespace-nowrap', 'max-w-full']))
    expect(badge.attributes('title')).toBe('Pegawai Pemerintah dengan Perjanjian Kerja')
    expect(badge.element.parentElement?.className).toContain('min-w-0')
  })

  it('tab Data Umum (bawaan) menampilkan biodata kolom pegawai, hanya-lihat sampai "Ubah Data Umum" dipilih', async () => {
    detail.mockResolvedValue(pegawaiDetail(TABS, {}, { aksi: { ubah_biodata: false, ubah_langsung: false, koreksi_nip: false, proses_biodata: false } }))
    const { wrapper } = await mountDetail()
    const du = wrapper.get('[data-testid="data-umum"]')
    expect(du.get('[data-field="bpjs_kes"]').text()).toContain('0001234567890')
    expect(du.get('[data-field="jenis_kelamin"]').text()).toContain('Laki-laki')
    expect(du.get('[data-field="status_pernikahan"]').text()).toContain('Menikah')
    expect(du.get('[data-field="npwp"]').text()).toContain('—')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-ubah-data-umum"]').exists()).toBe(false)
  })

  it('"Ubah Data Umum" (aksi.ubah_biodata) membuka form; pengajuan → pemberitahuan + detail dimuat ulang', async () => {
    detail.mockResolvedValue(
      pegawaiDetail(TABS, { npwp: '1', bpjs_kes: '2', no_taspen: '3', jenis_kerabat: 'Anak', no_telp_kerabat: '4', ada_foto: true }, {
        aksi: { ubah_biodata: true, ubah_langsung: false, koreksi_nip: false, proses_biodata: false },
        pengajuan: { id_pegawai_hist: 9, flag_update: 2, reason_note: 'Lengkapi NPWP', created_at: null, updated_at: null, notif_date: null },
      }),
    )
    updateBiodata.mockResolvedValue({ jalur: 'pengajuan', pegawai: pegawaiDetail(), pengajuan: null })
    const { wrapper } = await mountDetail(`/pegawai/${NIP}`, Role.PEGAWAI)

    expect(wrapper.get('[data-testid="pengajuan-status"]').text()).toContain('ditolak: Lengkapi NPWP')
    await wrapper.get('[data-testid="btn-ubah-data-umum"]').trigger('click')
    expect(wrapper.find('[data-testid="data-umum-form"]').exists()).toBe(true)

    await wrapper.get('[data-testid="data-umum-form"] form').trigger('submit')
    await flushPromises()
    await new Promise((r) => setTimeout(r, 0))
    await flushPromises()

    await vi.waitFor(() => expect(updateBiodata).toHaveBeenCalledWith(NIP, expect.objectContaining({ no_telp_kerabat: '4' }), null))
    await vi.waitFor(() => expect(wrapper.text()).toContain('berhasil diajukan dan menunggu verifikasi admin'))
    await vi.waitFor(() => expect(detail).toHaveBeenCalledTimes(2))
    await vi.waitFor(() => expect(wrapper.find('[data-testid="data-umum-form"]').exists()).toBe(false))
  })

  it.each([
    [404, 'not-found', 'Pegawai tidak ditemukan'],
    [403, 'forbidden', 'Akses ditolak'],
    [501, 'unavailable', 'Data pegawai belum tersedia'],
  ] as const)('galat %i → keadaan "%s" tanpa data contoh', async (status, kind, title) => {
    detail.mockRejectedValue(apiError(status))
    const { wrapper } = await mountDetail()
    const card = wrapper.get('[data-testid="pegawai-failure"]')
    expect(card.attributes('data-kind')).toBe(kind)
    expect(card.text()).toContain(title)
    expect(wrapper.find('[data-testid="pegawai-header"]').exists()).toBe(false)
  })
})

describe('DetailPegawaiPage — tab dari descriptor', () => {
  it('urutan backend; can_view=false dan karpeg dibuang; lkh (non-engine) tetap tab', async () => {
    const { wrapper } = await mountDetail()
    expect(tabLabels(wrapper)).toEqual(['Data Umum', 'Riwayat Jabatan', 'Riwayat Pendidikan', 'Laporan Kerja Harian'])
  })

  it('memilih tab merender RiwayatTabHost jenis itu dan menyimpan ?tab=<slug>', async () => {
    const { wrapper, router } = await mountDetail()
    await wrapper.findAll('[role="tab"]').find((t) => t.text() === 'Riwayat Pendidikan')?.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query.tab).toBe('pendidikan')
    expect(wrapper.find('[data-testid="riwayat-section-pendidikan"]').exists()).toBe(true)
  })

  it('?tab=lkh membuka tab non-engine LKH (GET lkh?nip=, MAKE-014); ?tab yang tidak ada di descriptor kembali ke Data Umum', async () => {
    const lkh = await mountDetail(`/pegawai/${NIP}?tab=lkh`)
    expect(lkh.wrapper.find('[data-testid="riwayat-belum-tersedia"]').exists()).toBe(false)
    expect(lkh.wrapper.find('[data-testid="lkh-tab-table"]').exists()).toBe(true)
    expect(vi.mocked(lkhService.list)).toHaveBeenCalledWith({ page: 1, per_page: 20, nip: NIP })
    lkh.wrapper.unmount()

    const { wrapper } = await mountDetail(`/pegawai/${NIP}?tab=kgb`)
    expect(wrapper.get('[role="tab"][aria-selected="true"]').text()).toBe('Data Umum')
  })

  it('descriptor kosong → hanya tab Data Umum', async () => {
    detail.mockResolvedValue(pegawaiDetail([]))
    const { wrapper } = await mountDetail()
    expect(tabLabels(wrapper)).toEqual(['Data Umum'])
  })
})

describe('DetailPegawaiPage — aksi kartu', () => {
  it('Cetak DRH (legacy UL_ALL): 1/3/4/5/8 ya; 2/6/7 hanya miliknya; Hapus (⋯) hanya Super Admin', async () => {
    for (const [role, nipAkun, print, more] of [
      [Role.SUPER_ADMIN, null, true, true],
      [Role.PIMPINAN, null, true, false],
      [Role.PEGAWAI, NIP, true, false],
      [Role.PEGAWAI, '198501012010011002', false, false],
      [Role.PTT, null, false, false],
    ] as const) {
      detail.mockResolvedValue(pegawaiDetail(TABS, {}, { aksi: { ubah_biodata: false, ubah_langsung: false, koreksi_nip: role === Role.SUPER_ADMIN, proses_biodata: false } }))
      const { wrapper } = await mountDetail(`/pegawai/${NIP}`, role, nipAkun)
      expect(wrapper.find('[data-testid="btn-cetak"]').exists(), `cetak role ${role}`).toBe(print)
      expect(wrapper.find('[data-testid="btn-more"]').exists(), `hapus role ${role}`).toBe(more)
      wrapper.unmount()
    }
  })

  it('breadcrumb: "Daftar Pegawai" hanya ditautkan bagi role daftar (D-F15)', async () => {
    const admin = await mountDetail()
    expect(admin.wrapper.find('nav[aria-label="Breadcrumb"] a[href="/pegawai"]').exists()).toBe(true)
    admin.wrapper.unmount()
    const milik = await mountDetail(`/pegawai/${NIP}`, Role.PEGAWAI, NIP)
    expect(milik.wrapper.get('nav[aria-label="Breadcrumb"]').text()).not.toContain('Daftar Pegawai')
  })

  it('Cetak → DRH: unduh PDF dari server (nama berkas dari Content-Disposition); Cetak Data Umum tidak ditawarkan', async () => {
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:drh'), revokeObjectURL: vi.fn() })
    const klik = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
    vi.mocked(pegawaiService.drh).mockResolvedValue({ blob: new Blob(['%PDF']), nama: `drh_${NIP}_20261013.pdf` })
    const { wrapper } = await mountDetail()
    await wrapper.get('[data-testid="btn-cetak"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-action="cetak-data-umum"]')).toBeNull()
    document.body.querySelector<HTMLElement>('[data-action="cetak-drh"]')?.click()
    await flushPromises()
    expect(pegawaiService.drh).toHaveBeenCalledWith(NIP)
    expect(klik).toHaveBeenCalled()
    klik.mockRestore()
  })

  it('Koreksi NIP (⋯, aksi.koreksi_nip): isi → tinjau → konfirmasi → pindah ke URL NIP baru', async () => {
    const BARU = '199001012015011999'
    koreksiNip.mockResolvedValue({ nip_lama: NIP, nip_baru: BARU, tabel: {}, dilewati: [], sesi_dicabut: 0 })
    const { wrapper, router } = await mountDetail()

    await wrapper.get('[data-testid="btn-more"]').trigger('click')
    await flushPromises()
    const item = document.body.querySelector<HTMLElement>('[data-action="koreksi-nip"]')
    expect(item).not.toBeNull()
    item?.click()
    await flushPromises()

    const dialog = () => document.body.querySelector('[data-testid="koreksi-nip-dialog"]')
    expect(dialog()).not.toBeNull()
    const input = document.body.querySelector<HTMLInputElement>('[data-testid="koreksi-nip-input"] input, input[data-testid="koreksi-nip-input"]')
    expect(input).not.toBeNull()
    if (input) {
      input.value = BARU
      input.dispatchEvent(new Event('input'))
    }
    await flushPromises()
    document.body.querySelector<HTMLElement>('[data-testid="koreksi-nip-lanjut"]')?.click()
    await flushPromises()
    expect(document.body.querySelector('[data-testid="koreksi-nip-tinjau"]')?.textContent).toContain(BARU)
    document.body.querySelector<HTMLElement>('[data-testid="koreksi-nip-konfirmasi"]')?.click()
    await flushPromises()

    expect(koreksiNip).toHaveBeenCalledWith(NIP, BARU)
    expect(router.currentRoute.value.params.nip).toBe(BARU)
  })

  it('B-04: aksi.proses_biodata → "Tolak Pengajuan" membuka ApprovalDialog dengan perbandingan; alasan wajib; kirim proses', async () => {
    const menunggu = { id_pegawai_hist: 9, flag_update: 1, reason_note: null, created_at: null, updated_at: null, notif_date: null }
    detail.mockResolvedValue(pegawaiDetail(TABS, {}, { pengajuan: menunggu, aksi: { ubah_biodata: true, ubah_langsung: true, koreksi_nip: true, proses_biodata: true } }))
    pengajuanDetail.mockResolvedValue({
      pengajuan: { ...menunggu, nip: NIP, nama: 'Budi Contoh', ada_foto: false },
      pegawai: { nip: NIP, nama: 'Budi Contoh', ada_foto: false, email: 'a@contoh.test' },
      perubahan: [{ kolom: 'no_hp', lama: '0811', baru: '0822' }, { kolom: 'foto', lama: false, baru: true }],
      can_process: true,
    })
    prosesPengajuan.mockResolvedValue({
      pengajuan: { ...menunggu, flag_update: 2, nip: NIP, nama: 'Budi Contoh', ada_foto: false },
      pegawai: { nip: NIP, nama: 'Budi Contoh', ada_foto: false, email: null },
      perubahan: [],
      can_process: false,
    })
    const { wrapper } = await mountDetail()

    await wrapper.get('[data-testid="btn-tolak-pengajuan"]').trigger('click')
    await flushPromises()
    expect(pengajuanDetail).toHaveBeenCalledWith(9)
    const diff = document.body.querySelector('[data-testid="pengajuan-diff"]')
    expect(diff?.querySelector('[data-kolom="no_hp"]')?.textContent).toContain('0822')
    expect(diff?.querySelector('[data-kolom="foto"]')?.textContent).toContain('Ada foto')

    const textarea = document.body.querySelector<HTMLTextAreaElement>('[data-testid="approval-dialog"] textarea, textarea')
    expect(textarea).not.toBeNull()
    if (textarea) {
      textarea.value = 'Lengkapi NPWP'
      textarea.dispatchEvent(new Event('input'))
    }
    await flushPromises()
    const tolak = [...document.body.querySelectorAll<HTMLButtonElement>('button')].filter((b) => b.textContent?.trim() === 'Tolak').at(-1)
    tolak?.click()
    await vi.waitFor(() => expect(prosesPengajuan).toHaveBeenCalledWith(9, { aksi: 'tolak', reason_note: 'Lengkapi NPWP' }))
    await vi.waitFor(() => expect(wrapper.text()).toContain('Pengajuan perubahan data umum ditolak.'))
  })

  it('MAKE-014: pemilik melihat perubahan yang diajukannya (tanpa tombol proses)', async () => {
    const menunggu = { id_pegawai_hist: 9, flag_update: 1, reason_note: null, created_at: null, updated_at: null, notif_date: null }
    detail.mockResolvedValue(pegawaiDetail(TABS, {}, { pengajuan: menunggu, aksi: { ubah_biodata: true, ubah_langsung: false, koreksi_nip: false, proses_biodata: false } }))
    pengajuanDetail.mockResolvedValue({
      pengajuan: { ...menunggu, nip: NIP, nama: 'Budi Contoh', ada_foto: false },
      pegawai: { nip: NIP, nama: 'Budi Contoh', ada_foto: false, email: null },
      perubahan: [{ kolom: 'no_hp', lama: '0811', baru: '0822' }],
      can_process: false,
    })
    const { wrapper } = await mountDetail(`/pegawai/${NIP}`, Role.PEGAWAI)

    expect(wrapper.find('[data-testid="btn-tolak-pengajuan"]').exists()).toBe(false)
    await wrapper.get('[data-testid="btn-lihat-perubahan"]').trigger('click')
    await flushPromises()
    expect(pengajuanDetail).toHaveBeenCalledWith(9)
    expect(wrapper.get('[data-testid="perubahan-saya"] [data-kolom="no_hp"]').text()).toContain('0822')

    await wrapper.get('[data-testid="btn-lihat-perubahan"]').trigger('click')
    expect(wrapper.find('[data-testid="perubahan-saya"]').exists()).toBe(false)
  })

  it('B-04: foto pengajuan tampil (object URL dicabut saat ditutup); can_process false → peringatan + tombol nonaktif', async () => {
    const create = vi.fn(() => 'blob:pengajuan')
    const revoke = vi.fn()
    Object.assign(URL, { createObjectURL: create, revokeObjectURL: revoke })
    const menunggu = { id_pegawai_hist: 9, flag_update: 1, reason_note: null, created_at: null, updated_at: null, notif_date: null }
    detail.mockResolvedValue(pegawaiDetail(TABS, {}, { pengajuan: menunggu, aksi: { ubah_biodata: true, ubah_langsung: true, koreksi_nip: true, proses_biodata: true } }))
    pengajuanDetail.mockResolvedValue({
      pengajuan: { ...menunggu, nip: NIP, nama: 'Budi Contoh', ada_foto: true },
      pegawai: { nip: NIP, nama: 'Budi Contoh', ada_foto: true, email: null },
      perubahan: [{ kolom: 'foto', lama: true, baru: true }],
      can_process: false,
    })
    fotoPengajuan.mockResolvedValue(new Blob(['x'], { type: 'image/png' }))
    const { wrapper } = await mountDetail()

    await wrapper.get('[data-testid="btn-setujui-pengajuan"]').trigger('click')
    await flushPromises()

    await vi.waitFor(() => expect(document.body.querySelector('[data-testid="pengajuan-foto"]')?.getAttribute('src')).toBe('blob:pengajuan'))
    expect(fotoPengajuan).toHaveBeenCalledWith(9)
    expect(document.body.querySelector('[data-testid="pengajuan-tidak-bisa-diproses"]')).not.toBeNull()
    expect(document.body.querySelector<HTMLButtonElement>('[data-testid="approval-confirm"]')?.disabled).toBe(true)

    // Tutup dialog → object URL dicabut.
    ;[...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === 'Batal')?.click()
    await vi.waitFor(() => expect(revoke).toHaveBeenCalledWith('blob:pengajuan'))
  })

  it('B-05: ⋯ "Hapus Pegawai" → konfirmasi (permanen) → DELETE pegawai/{nip} → kembali ke daftar', async () => {
    hapus.mockResolvedValue({ deleted: true, soft_delete: false, item: pegawaiDetail() })
    const { wrapper, router } = await mountDetail()
    await wrapper.get('[data-testid="btn-more"]').trigger('click')
    await flushPromises()
    document.body.querySelector<HTMLElement>('[data-action="hapus-pegawai"]')?.click()
    await flushPromises()
    expect(document.body.textContent).toContain('PERMANEN')
    const ya = [...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('Ya, hapus'))
    ya?.click()
    await vi.waitFor(() => expect(hapus).toHaveBeenCalledWith(NIP))
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('pegawai-list'))
  })

  it('"Arsip Kepegawaian" membuka arsip Data Umum (38) & Arsip Status (37) dengan id_entri = NIP (MAKE-014)', async () => {
    const { wrapper } = await mountDetail()
    await wrapper.get('[data-testid="btn-arsip"]').trigger('click')
    await flushPromises()

    expect(document.body.querySelector('[data-testid="arsip-kepegawaian"]')).not.toBeNull()
    expect(vi.mocked(lampiranService.list)).toHaveBeenCalledWith(NIP, { id_riwayat: 38, id_entri: NIP })
    expect(vi.mocked(lampiranService.list)).toHaveBeenCalledWith(NIP, { id_riwayat: 37, id_entri: NIP })
    // Super Admin: unggah arsip DU ditahan sampai jenis dipilih.
    const unggah = document.body.querySelector<HTMLButtonElement>(`[data-testid="arsip-38-${NIP}"] [data-testid="arsip-upload"]`)
    expect(unggah?.disabled).toBe(true)
    // Alasan ditahan tampil sebagai teks dan dirujuk aria-describedby.
    const alasan = document.body.querySelector(`[data-testid="arsip-38-${NIP}"] [data-testid="arsip-tahan"]`)
    expect(alasan?.textContent).toContain('Pilih jenis arsip dulu.')
    expect(unggah?.getAttribute('aria-describedby')).toBe(alasan?.id)
    const jenis = document.body.querySelector<HTMLSelectElement>('[data-testid="arsip-du-jenis"] select')
    if (jenis) {
      jenis.value = 'KTP'
      jenis.dispatchEvent(new Event('change'))
    }
    await flushPromises()
    expect(unggah?.disabled).toBe(false)
  })

  it('Arsip Kepegawaian: role 4 hanya melihat (tanpa unggah)', async () => {
    const { wrapper } = await mountDetail(`/pegawai/${NIP}`, Role.ADMIN_VIEW_ESELON1)
    await wrapper.get('[data-testid="btn-arsip"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-testid="arsip-kepegawaian"] [data-testid="arsip-upload"]')).toBeNull()
  })
})
