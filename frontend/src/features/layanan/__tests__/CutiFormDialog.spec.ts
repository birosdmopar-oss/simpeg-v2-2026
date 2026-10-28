/**
 * Paket 2 M7 (MAKE-026) — CutiFormDialog + kalkulator real-time: debounce 300 ms ke `POST cuti/hitung-lama`, panel
 * lama/tanggal akhir/hari libur/kuota SEMUA dari server (tanpa hitung di FE), Simpan tidak dikunci hasil kalkulator,
 * 422 per kolom, jenis Besar (durasi), lampiran wajib jenis Sakit, role 1 (NIP + status), ubah (prefill + PUT).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/kepegawaian/services/pegawai.service', () => ({ pegawaiService: { list: vi.fn() } }))
vi.mock('../services/cuti.service', () => ({
  cutiService: { list: vi.fn(), detail: vi.fn(), process: vi.fn(), tunda: vi.fn(), remove: vi.fn(), pengingat: vi.fn(), lampiran: vi.fn(), surat: vi.fn(), opsi: vi.fn(), hitungLama: vi.fn(), create: vi.fn(), update: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import { pegawaiService } from '@/features/kepegawaian/services/pegawai.service'
import type { ApiError } from '@/lib/axios'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import CutiFormDialog from '../components/CutiFormDialog.vue'
import { cutiService } from '../services/cuti.service'
import type { CutiDetail, CutiItem, CutiOpsi, HitungLamaCuti } from '../types'

const svc = vi.mocked(cutiService)
const pegawaiList = vi.mocked(pegawaiService.list)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const apiError = (status: number, errors: Record<string, string[]> | null = null): ApiError =>
  ({ status, message: `HTTP ${status}`, errors, isNetworkError: false, original: new Error('x') }) as ApiError

const OPSI: CutiOpsi = {
  jenis_cuti: [
    { id_jenis_cuti: 1, nama_jenis_cuti: 'Cuti Tahunan', pakai_durasi: false, lampiran_wajib: false },
    { id_jenis_cuti: 2, nama_jenis_cuti: 'Cuti Besar', pakai_durasi: true, lampiran_wajib: false },
    { id_jenis_cuti: 3, nama_jenis_cuti: 'Cuti Sakit', pakai_durasi: false, lampiran_wajib: true },
  ],
  atasan_langsung: { nip: '197001012000011001', nama: 'Dra. Siti Aminah', jabatan: 'Kepala Bagian', bisa_memproses: true },
  pyb: [{ nip: '196501012000011001', nama: 'Drs. Hadi Pranoto', jabatan: 'Kepala Biro', bisa_memproses: true }],
  langkah_tunggal: false,
  eselon_i: false,
  saldo: null,
  tgl_akhir_maks: '2027-12-31',
  ketua_tim: [
    { nip: '111', nama: 'Ketua Satu' },
    { nip: '222', nama: 'Ketua Dua' },
  ],
  can_create: true,
}

const HITUNG: HitungLamaCuti = {
  lama_cuti: 3,
  satuan_lama_cuti: 'Hari',
  tgl_akhir: null,
  hari_libur: [{ tanggal: '2026-11-05', keterangan: 'Libur Nasional Uji' }],
  kuota: {
    tahun: 2026,
    sisa_cuti: 12,
    sisa_cuti_n1: 6,
    sisa_cuti_n2: 4,
    saldo_tahun_ini: 19,
    menunggu_tahun_ini: 1,
    diajukan_tahun_ini: 3,
    saldo_tahun_depan: 0,
    menunggu_tahun_depan: 0,
    diajukan_tahun_depan: 0,
    sisa_setelah_tahun_ini: 16,
    sisa_setelah_tahun_depan: 0,
    cukup: true,
    pesan: '',
  },
}

const ITEM = { id: 5 } as CutiItem

async function mountDialog(role: number = Role.PEGAWAI, props: { row?: CutiItem | null } = {}) {
  loginAs(role as never)
  const router = createShellRouter()
  await router.push('/')
  await router.isReady()
  const wrapper = mount(CutiFormDialog, { props: { open: true, row: props.row ?? null }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

function isi(sel: string, nilai: string): void {
  const el = q<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(sel)
  if (!el) throw new Error(`isian ${sel} tidak ada`)
  el.value = nilai
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
}

async function isiDasar(jenis = '1'): Promise<void> {
  isi('[data-testid="cuti-jenis"] select', jenis)
  isi('[data-testid="cuti-tgl-mulai"] input', '2026-11-04')
  await flushPromises()
}

async function lewatJeda(ms = 300): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}

afterEach(() => {
  document.body.innerHTML = ''
  vi.useRealTimers()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  svc.opsi.mockResolvedValue(OPSI)
  svc.hitungLama.mockResolvedValue(HITUNG)
  pegawaiList.mockReset()
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
})

describe('CutiFormDialog — kalkulator real-time', () => {
  it('debounce 300 ms: satu panggilan hitung-lama per perubahan beruntun; panel menampilkan angka server', async () => {
    await mountDialog()
    await isiDasar()
    isi('[data-testid="cuti-tgl-akhir"] input', '2026-11-06')
    await vi.advanceTimersByTimeAsync(299)
    expect(svc.hitungLama).not.toHaveBeenCalled()

    await lewatJeda(2)

    expect(svc.hitungLama).toHaveBeenCalledTimes(1)
    expect(svc.hitungLama).toHaveBeenCalledWith({ id_jenis_cuti: 1, tgl_mulai: '2026-11-04', tgl_akhir: '2026-11-06' })
    expect(q('[data-testid="kalkulator-lama"]')?.textContent).toContain('3 Hari')
    expect(q('[data-testid="kalkulator-libur"]')?.textContent).toContain('Libur Nasional Uji')
    expect(q('[data-testid="kalkulator-saldo"]')?.textContent).toBe('19')
    expect(q('[data-testid="kalkulator-sisa-setelah"]')?.textContent?.trim()).toBe('16')
    expect(q('[data-testid="kalkulator-tahun-depan"]')).toBeNull()
    expect(q('[data-testid="kalkulator-kuota"]')?.textContent).toContain('N-1')
  })

  it('pengajuan lintas tahun: porsi tahun depan + sisa setelah dari server; kekurangan negatif', async () => {
    svc.hitungLama.mockResolvedValue({
      ...HITUNG,
      kuota: {
        ...HITUNG.kuota!,
        diajukan_tahun_ini: 2,
        diajukan_tahun_depan: 3,
        saldo_tahun_depan: 12,
        menunggu_tahun_depan: 1,
        sisa_setelah_tahun_ini: 17,
        sisa_setelah_tahun_depan: -2,
        cukup: false,
        pesan: 'Tidak cukup',
      },
    })
    await mountDialog()
    await isiDasar()
    isi('[data-testid="cuti-tgl-akhir"] input', '2027-01-05')
    await lewatJeda()

    expect(q('[data-testid="kalkulator-tahun-depan"]')?.textContent).toContain('Porsi Tahun 2027')
    expect(q('[data-testid="kalkulator-sisa-setelah"]')?.textContent?.trim()).toBe('17')
    expect(q('[data-testid="kalkulator-sisa-setelah-depan"]')?.textContent?.trim()).toBe('-2')
  })

  it('jenis Besar memakai durasi: tanggal akhir ditampilkan dari server, tidak dihitung FE', async () => {
    svc.hitungLama.mockResolvedValue({ ...HITUNG, lama_cuti: 31, tgl_akhir: '2026-12-03', kuota: null, hari_libur: [] })
    await mountDialog()
    await isiDasar('2')
    expect(q('[data-testid="cuti-tgl-akhir"]')).toBeNull()
    isi('[data-testid="cuti-durasi"] select', '1')
    await lewatJeda()

    expect(svc.hitungLama).toHaveBeenCalledWith({ id_jenis_cuti: 2, tgl_mulai: '2026-11-04', durasi: 1 })
    expect(q('[data-testid="kalkulator-tgl-akhir"]')?.textContent).toContain('3 Desember 2026')
    expect(q('[data-testid="kalkulator-kuota"]')).toBeNull()
  })

  it('kuota tidak cukup: peringatan dari server tampil tetapi Simpan tidak dikunci', async () => {
    svc.hitungLama.mockResolvedValue({ ...HITUNG, kuota: { ...HITUNG.kuota!, cukup: false, pesan: 'Sisa Cuti anda tidak mencukupi.' } })
    svc.create.mockResolvedValue({ id: 9 } as CutiItem)
    const wrapper = await mountDialog()
    await isiDasar()
    isi('[data-testid="cuti-tgl-akhir"] input', '2026-11-06')
    await lewatJeda()

    expect(q('[data-testid="kalkulator-tidak-cukup"]')?.textContent).toContain('tidak mencukupi')
    expect(q<HTMLButtonElement>('[data-testid="cuti-simpan"]')?.disabled).toBe(false)

    isi('[data-testid="cuti-telp"] input', '08123')
    isi('[data-testid="cuti-alamat"] input', 'Jl. Uji')
    isi('[data-testid="cuti-keterangan"]', 'Keperluan keluarga besar')
    q('[data-testid="cuti-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(svc.create).toHaveBeenCalled()
    expect(wrapper.emitted('saved')).toBeTruthy()
  })

  it('galat 422 kalkulator tampil sebagai pesan, bukan menghalangi form', async () => {
    svc.hitungLama.mockRejectedValue(apiError(422, { tgl_mulai: ['Tanggal mulai cuti tidak boleh hari libur.'] }))
    await mountDialog()
    await isiDasar()
    isi('[data-testid="cuti-tgl-akhir"] input', '2026-11-06')
    await lewatJeda()

    expect(q('[data-testid="cuti-tgl-mulai"]')?.textContent).toContain('hari libur')
    expect(q<HTMLButtonElement>('[data-testid="cuti-simpan"]')?.disabled).toBe(false)
  })
})

describe('CutiFormDialog — simpan', () => {
  async function isiSemua(jenis = '1'): Promise<void> {
    await isiDasar(jenis)
    if (jenis !== '2') isi('[data-testid="cuti-tgl-akhir"] input', '2026-11-06')
    isi('[data-testid="cuti-telp"] input', '08123456789')
    isi('[data-testid="cuti-alamat"] input', 'Jl. Uji No. 1')
    isi('[data-testid="cuti-keterangan"]', 'Keperluan keluarga besar')
    await flushPromises()
  }

  it('pegawai: kirim isian (AL readonly dari opsi, PyB tunggal terpilih otomatis, ketua tim) tanpa lama_cuti', async () => {
    svc.create.mockResolvedValue({ id: 9 } as CutiItem)
    const wrapper = await mountDialog()
    expect(q('[data-testid="cuti-atasan"]')?.textContent).toContain('Dra. Siti Aminah')
    await isiSemua()
    const cb = [...document.body.querySelectorAll<HTMLInputElement>('[data-testid="cuti-ketua-tim"] input[type="checkbox"]')]
    cb[1]!.click()
    await flushPromises()
    expect(q('[data-testid="cuti-ketua-jumlah"]')?.textContent).toContain('1 dipilih')

    q('[data-testid="cuti-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(svc.create).toHaveBeenCalledWith(
      {
        id_jenis_cuti: '1',
        tgl_mulai: '2026-11-04',
        tgl_akhir: '2026-11-06',
        no_telp_saat_cuti: '08123456789',
        alamat_saat_cuti: 'Jl. Uji No. 1',
        keterangan: 'Keperluan keluarga besar',
        ketua_tim: ['222'],
        nip_atasan_langsung: '197001012000011001',
        nip_yang_menyetujui: '196501012000011001',
      },
      null,
    )
    expect(wrapper.emitted('saved')).toHaveLength(1)
    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false])
  })

  it('422 ditampilkan per kolom dan kuota (lama_cuti) di bawah kalkulator', async () => {
    svc.create.mockRejectedValue(
      apiError(422, { tgl_mulai: ['Tanggal mulai tidak boleh sebelum hari ini.'], keterangan: ['Keterangan minimal 10 karakter.'], lama_lama: ['x'], lama_cuti: ['Sisa cuti tidak mencukupi.'] }),
    )
    const wrapper = await mountDialog()
    await isiSemua()

    q('[data-testid="cuti-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(q('[data-testid="cuti-tgl-mulai"]')?.textContent).toContain('tidak boleh sebelum hari ini')
    expect(q('[data-testid="cuti-form"]')?.textContent).toContain('Keterangan minimal 10 karakter.')
    expect(q('[data-testid="cuti-lama-error"]')?.textContent).toContain('tidak mencukupi')
    expect(q('[data-testid="cuti-form-error"]')?.textContent).toContain('x')
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('jenis Sakit tanpa lampiran ditolak di FE (pesan di kolom lampiran), tidak memanggil server', async () => {
    await mountDialog()
    await isiSemua('3')

    q('[data-testid="cuti-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(svc.create).not.toHaveBeenCalled()
    expect(q('[data-testid="cuti-file-error"]')?.textContent).toContain('wajib')
  })

  it('jenis Besar mengirim durasi tanpa tgl_akhir', async () => {
    svc.create.mockResolvedValue({ id: 9 } as CutiItem)
    await mountDialog()
    await isiDasar('2')
    isi('[data-testid="cuti-durasi"] select', '2')
    isi('[data-testid="cuti-telp"] input', '08123456789')
    isi('[data-testid="cuti-alamat"] input', 'Jl. Uji No. 1')
    isi('[data-testid="cuti-keterangan"]', 'Keperluan keluarga besar')
    q('[data-testid="cuti-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    const isianKirim = svc.create.mock.calls[0]![0]
    expect(isianKirim.durasi).toBe('2')
    expect(isianKirim).not.toHaveProperty('tgl_akhir')
  })

  it('eselon I: tanpa AL/PyB (pesan diproses admin) dan tidak mengirim nip pejabat', async () => {
    svc.opsi.mockResolvedValue({ ...OPSI, eselon_i: true, atasan_langsung: null, pyb: [] })
    svc.create.mockResolvedValue({ id: 9 } as CutiItem)
    await mountDialog()
    expect(q('[data-testid="cuti-eselon-i"]')).not.toBeNull()
    await isiSemua()
    q('[data-testid="cuti-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(svc.create.mock.calls[0]![0]).not.toHaveProperty('nip_yang_menyetujui')
    expect(svc.create.mock.calls[0]![0]).not.toHaveProperty('nip_atasan_langsung')
  })

  it('tanpa jenis cuti yang boleh diajukan: pesan dan Simpan nonaktif', async () => {
    svc.opsi.mockResolvedValue({ ...OPSI, jenis_cuti: [], can_create: false })
    await mountDialog()
    expect(q('[data-testid="cuti-tidak-bisa"]')).not.toBeNull()
    expect(q<HTMLButtonElement>('[data-testid="cuti-simpan"]')?.disabled).toBe(true)
  })
})

describe('CutiFormDialog — role 1 dan ubah', () => {
  it('role 1: cari pegawai (combobox, server), pilih saran memuat opsi?nip=; kirim nip + status', async () => {
    svc.create.mockResolvedValue({ id: 9 } as CutiItem)
    pegawaiList.mockResolvedValue({
      items: [{ nip: '198001012005011001', nama: 'Budi Santoso', glr_awal: 'Drs.', glr_akhir: 'M.Si.' }],
      total: 1,
      page: 1,
      per_page: 10,
    } as never)
    await mountDialog(Role.SUPER_ADMIN)
    expect(svc.opsi).not.toHaveBeenCalled()
    expect(q('[data-testid="cuti-jenis"]')).toBeNull()

    isi('[data-testid="cuti-pegawai"] input', 'budi')
    await lewatJeda()
    expect(pegawaiList).toHaveBeenCalledWith({ page: 1, per_page: 10, search: 'budi' })

    isi('[data-testid="cuti-pegawai"] input', '198001012005011001 — Drs. Budi Santoso, M.Si.')
    await flushPromises()
    expect(svc.opsi).toHaveBeenCalledWith('198001012005011001')
    expect(q('[data-testid="cuti-pegawai-terpilih"]')?.textContent).toContain('198001012005011001')

    await isiDasar()
    isi('[data-testid="cuti-tgl-akhir"] input', '2026-11-06')
    isi('[data-testid="cuti-telp"] input', '08123456789')
    isi('[data-testid="cuti-alamat"] input', 'Jl. Uji No. 1')
    isi('[data-testid="cuti-keterangan"]', 'Keperluan keluarga besar')
    q('[data-testid="cuti-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(svc.create.mock.calls[0]![0]).toMatchObject({ nip: '198001012005011001', status: '0' })
    await lewatJeda()
    expect(svc.hitungLama).toHaveBeenLastCalledWith(expect.objectContaining({ nip: '198001012005011001' }))
  })

  it('role 1: ketikan kurang dari 3 huruf tidak memanggil server', async () => {
    await mountDialog(Role.SUPER_ADMIN)
    isi('[data-testid="cuti-pegawai"] input', 'bu')
    await lewatJeda()
    expect(pegawaiList).not.toHaveBeenCalled()
  })

  it('ubah: prefill dari detail, kalkulator mengecualikan id sendiri, simpan lewat update', async () => {
    const detail = {
      id: 5,
      nip: '198001012005011001',
      id_jenis_cuti: 1,
      tgl_mulai: '2026-11-04',
      tgl_akhir: '2026-11-06',
      no_telp_saat_cuti: '0811',
      alamat_saat_cuti: 'Jl. Lama',
      keterangan: 'Keperluan lama sekali',
      nip_yang_menyetujui: '196501012000011001',
      ketua_tim_detail: [{ nip: '111', nama: 'Ketua Satu' }],
    } as unknown as CutiDetail
    svc.detail.mockResolvedValue(detail)
    svc.update.mockResolvedValue({ id: 5 } as CutiItem)
    await mountDialog(Role.PEGAWAI, { row: ITEM })
    await lewatJeda()

    expect(svc.detail).toHaveBeenCalledWith(5)
    expect(q<HTMLInputElement>('[data-testid="cuti-telp"] input')?.value).toBe('0811')
    expect(q('[data-testid="cuti-ketua-jumlah"]')?.textContent).toContain('1 dipilih')
    expect(svc.hitungLama).toHaveBeenLastCalledWith(expect.objectContaining({ id_riwayat_cuti: 5 }))

    q('[data-testid="cuti-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(svc.update).toHaveBeenCalledWith(5, expect.objectContaining({ alamat_saat_cuti: 'Jl. Lama', ketua_tim: ['111'] }), null)
    expect(svc.create).not.toHaveBeenCalled()
  })

  it('403 saat memuat opsi tampil sebagai pesan baku', async () => {
    svc.opsi.mockRejectedValue(apiError(403))
    await mountDialog()
    expect(q('[data-testid="cuti-form-error"]')?.textContent).toContain('tidak berhak')
  })
})
