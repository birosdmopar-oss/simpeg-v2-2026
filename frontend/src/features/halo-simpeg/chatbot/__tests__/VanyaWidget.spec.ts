import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const fase = vi.hoisted(() => ({ aktif: 8 }))
vi.mock('@/shared/layouts/nav.config', async (asli) => ({
  ...(await asli<typeof import('@/shared/layouts/nav.config')>()),
  get ACTIVE_PHASE() {
    return fase.aktif
  },
}))

const svc = vi.hoisted(() => ({ topik: vi.fn(), pertanyaan: vi.fn(), populer: vi.fn(), balas: vi.fn(), feedback: vi.fn() }))
vi.mock('../services/chatbot.service', async (asli) => ({ ...(await asli<typeof import('../services/chatbot.service')>()), vanyaService: svc }))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode, type User } from '@/features/auth/types'

import VanyaWidget from '../components/widget/VanyaWidget.vue'
import { _resetPeek } from '../stores/peek'
import { resetChatbot } from '../stores/percakapan'
import type { BalasanWidget } from '../types'
import galat422Pesan from './fixtures/galat-422-pesan.json'

function apiError(status: number | null, message: string) {
  return { status, message, errors: null, isNetworkError: status === null, original: new AxiosError('x') }
}

const cocok = (over: Partial<BalasanWidget> = {}): BalasanWidget => ({
  balasan: '<p>Cuti tahunan <b>12 hari</b>.</p>',
  cocok: true,
  skor: 0.8123,
  id_log: 41,
  bisa_feedback: true,
  token_feedback: 'tok.en',
  saran: [],
  ...over,
})

let wrapper: VueWrapper | null = null

function login(role: RoleCode, mustChange = false): void {
  useAuthStore().user = { username: 'uji', user_level: role, must_change_password: mustChange } as User
}

async function pasang(role: RoleCode = Role.PEGAWAI, mustChange = false): Promise<VueWrapper> {
  login(role, mustChange)
  wrapper = mount(VanyaWidget, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

async function bukaChat(w: VueWrapper): Promise<void> {
  await w.get('[data-testid="vanya-launcher"]').trigger('click')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  fase.aktif = 8
  resetChatbot()
  _resetPeek()
  sessionStorage.clear()
  for (const f of Object.values(svc)) f.mockReset()
  svc.topik.mockResolvedValue([{ id: 3, judul: 'Cuti' }])
  svc.pertanyaan.mockResolvedValue([{ id_qna: 7, pertanyaan: 'Berapa jatah cuti tahunan?' }])
  svc.populer.mockResolvedValue([{ id_qna: 9, pertanyaan: 'Cara cek slip gaji?' }])
  svc.balas.mockResolvedValue(cocok())
  svc.feedback.mockResolvedValue(undefined)
})

afterEach(() => {
  vi.useRealTimers()
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})

describe('VanyaWidget — gerbang render', () => {
  it.each([Role.PEGAWAI, Role.PTT, Role.PPPK])('tampil untuk role %s (UL_PEGAWAI)', async (role) => {
    const w = await pasang(role)
    expect(w.find('[data-testid="vanya-launcher"]').exists()).toBe(true)
  })

  it.each([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])('tidak dirender untuk role %s', async (role) => {
    const w = await pasang(role)
    expect(w.find('[data-testid="vanya-widget"]').exists()).toBe(false)
  })

  it('tidak dirender saat wajib ganti password', async () => {
    const w = await pasang(Role.PEGAWAI, true)
    expect(w.find('[data-testid="vanya-widget"]').exists()).toBe(false)
  })

  it('tidak dirender sebelum gerbang rilis F8-50 (ACTIVE_PHASE < 8)', async () => {
    fase.aktif = 7
    const w = await pasang()
    expect(w.find('[data-testid="vanya-widget"]').exists()).toBe(false)
  })

  it('hilang bila sesi berganti ke role di luar UL_PEGAWAI', async () => {
    const w = await pasang()
    login(Role.SUPER_ADMIN)
    await flushPromises()
    expect(w.find('[data-testid="vanya-widget"]').exists()).toBe(false)
  })
})

describe('VanyaWidget — launcher & kartu sapaan', () => {
  it('launcher ber-aria-label, kartu sapaan saat fokus, peek sekali per sesi browser (1,5 dtk → 4,5 dtk)', async () => {
    vi.useFakeTimers()
    const w = await pasang()
    const launcher = w.get('[data-testid="vanya-launcher"]')
    expect(launcher.attributes('aria-label')).toBe('Chat dengan Vanya, asisten virtual SDMO')
    expect(launcher.attributes('title')).toBeUndefined()
    expect(launcher.attributes('aria-expanded')).toBe('false')
    // Peek pertama.
    expect(sessionStorage.getItem('simpeg.chatbot.vanya.peek')).toBe('1')
    expect(w.find('[data-testid="vanya-kartu"]').exists()).toBe(false)
    await vi.advanceTimersByTimeAsync(1500)
    expect(w.get('[data-testid="vanya-kartu"]').text()).toBe('Halo, Saya Vanya, asisten virtual SDMO, klik untuk mulai chat!')
    await vi.advanceTimersByTimeAsync(4500)
    expect(w.find('[data-testid="vanya-kartu"]').exists()).toBe(false)
    vi.useRealTimers()
    w.unmount()

    // Pasang ulang (pindah halaman): tidak peek lagi, tetapi fokus memunculkan kartu.
    const w2 = mount(VanyaWidget, { attachTo: document.body })
    wrapper = w2
    await flushPromises()
    expect(w2.find('[data-testid="vanya-kartu"]').exists()).toBe(false)
    await w2.get('[data-testid="vanya-launcher"]').trigger('focus')
    expect(w2.get('[data-testid="vanya-kartu"]').text()).toContain('klik untuk mulai chat!')
  })

  it('kartu sapaan tetap berfungsi bila sessionStorage melempar galat', async () => {
    const get = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('diblokir')
    })
    const set = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('diblokir')
    })
    try {
      const w = await pasang()
      await w.get('[data-testid="vanya-launcher"]').trigger('mouseenter')
      expect(w.find('[data-testid="vanya-kartu"]').exists()).toBe(true)
    } finally {
      get.mockRestore()
      set.mockRestore()
    }
  })
})

describe('VanyaWidget — alur percakapan', () => {
  it('buka: dialog berlabel, salam + bubble topik, fokus ke kotak ketik; Escape menutup dan fokus kembali ke launcher', async () => {
    const w = await pasang()
    await bukaChat(w)
    const dialog = w.get('[role="dialog"]')
    expect(dialog.attributes('aria-labelledby')).toBe('vanya-judul')
    expect(w.get('#vanya-judul').text()).toBe('Chat dengan Vanya')
    expect(w.get('[data-testid="vanya-isi"]').attributes('aria-live')).toBe('polite')
    expect(w.findAll('[data-pesan="sistem"]')).toHaveLength(1)
    expect(w.find('[data-testid="vanya-topik-3"]').exists()).toBe(true)
    expect(document.activeElement).toBe(w.get('[data-testid="vanya-input"]').element)

    expect(w.get('[data-testid="vanya-launcher"]').attributes('aria-expanded')).toBe('true')
    expect(w.get('[data-pesan="topik"]').text()).toContain('Pilih topik yang ingin Anda tanyakan:')

    // Escape dari mana pun (dev: listener dokumen) menutup dan mengembalikan fokus ke launcher.
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(w.find('[role="dialog"]').exists()).toBe(false)
    expect(document.activeElement).toBe(w.get('[data-testid="vanya-launcher"]').element)

    // Launcher membuka lagi tanpa salam ulang, lalu menutup (toggle, dev:VP:382).
    await bukaChat(w)
    expect(w.findAll('[data-pesan="sistem"]')).toHaveLength(1)
    await bukaChat(w)
    expect(w.find('[role="dialog"]').exists()).toBe(false)
  })

  it('tanpa topik → populer tanpa pesan galat', async () => {
    svc.topik.mockResolvedValue([])
    const w = await pasang()
    await bukaChat(w)
    expect(svc.populer).toHaveBeenCalledTimes(1)
    expect(w.find('[data-testid="vanya-pertanyaan-9"]').exists()).toBe(true)
    expect(w.text()).toContain('Pertanyaan yang sering ditanyakan pegawai lain:')
    expect(w.find('[data-pesan="galat"]').exists()).toBe(false)
  })

  it('tanpa topik dan tanpa populer → senyap; tombol Populer kosong → pesan populer kosong', async () => {
    svc.topik.mockResolvedValue([])
    svc.populer.mockResolvedValue([])
    const w = await pasang()
    await bukaChat(w)
    expect(w.findAll('[data-pesan="sistem"]')).toHaveLength(1)
    await w.get('[data-testid="vanya-btn-populer"]').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('Belum ada pertanyaan populer saat ini. Silakan ketik pertanyaan Anda.')
  })

  it('topik → pertanyaan → jawaban (SafeHtml) → nilai 👍 → terima kasih', async () => {
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-topik-3"]').trigger('click')
    await flushPromises()
    expect(svc.pertanyaan).toHaveBeenCalledWith(3)
    expect(w.findAll('[data-pesan="user"]').at(-1)?.text()).toBe('Cuti')
    expect(w.text()).toContain('Berikut pertanyaan yang sering ditanyakan seputar Cuti. Silakan pilih:')

    await w.get('[data-testid="vanya-pertanyaan-7"]').trigger('click')
    await flushPromises()
    expect(svc.balas).toHaveBeenCalledWith('Berapa jatah cuti tahunan?', 7)
    const jawaban = w.get('[data-pesan="jawaban"]')
    expect(jawaban.get('[data-testid="safe-html"]').html()).toContain('<b>12 hari</b>')

    await jawaban.get('[data-action="suka"]').trigger('click')
    await flushPromises()
    expect(svc.feedback).toHaveBeenCalledWith(41, 1, 'tok.en')
    expect(jawaban.text()).toContain('Terima kasih atas penilaiannya!')
    expect(jawaban.find('[data-action="tidak-suka"]').exists()).toBe(false)
    expect(jawaban.get('[data-action="suka"]').attributes('aria-pressed')).toBe('true')
  })

  it('topik tanpa pertanyaan → "Belum ada pertanyaan pada topik ini."', async () => {
    svc.pertanyaan.mockResolvedValue([])
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-topik-3"]').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('Belum ada pertanyaan pada topik ini.')
  })

  it('ketikan: di-trim, kosong diabaikan', async () => {
    const w = await pasang()
    await bukaChat(w)
    const input = w.get('[data-testid="vanya-input"]')
    await input.setValue('   ')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    expect(svc.balas).not.toHaveBeenCalled()

    await input.setValue('  cuti besar  ')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    expect(svc.balas).toHaveBeenCalledWith('cuti besar', undefined)
    expect((input.element as HTMLInputElement).value).toBe('')
  })

  it('fallback: teks sistem (bukan HTML) + saran; tanpa penilaian; saran mengirim id_qna', async () => {
    svc.balas.mockResolvedValueOnce(
      cocok({
        cocok: false,
        balasan: 'Maaf, Vanya belum memahami pertanyaan Anda. Mungkin salah satu pertanyaan berikut yang Anda maksud?',
        bisa_feedback: false,
        token_feedback: null,
        saran: [{ id_qna: 9, pertanyaan: 'Cara cek slip gaji?' }],
      }),
    )
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-input"]').setValue('asdf')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    expect(w.find('[data-pesan="jawaban"]').exists()).toBe(false)
    expect(w.text()).toContain('Vanya belum memahami')
    expect(w.find('[data-action="suka"]').exists()).toBe(false)

    await w.get('[data-testid="vanya-pertanyaan-9"]').trigger('click')
    await flushPromises()
    expect(svc.balas).toHaveBeenLastCalledWith('Cara cek slip gaji?', 9)
  })

  it('jawaban cocok tanpa bisa_feedback tidak menampilkan penilaian', async () => {
    svc.balas.mockResolvedValueOnce(cocok({ bisa_feedback: false, token_feedback: null }))
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-input"]').setValue('cuti')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    expect(w.find('[data-pesan="jawaban"]').exists()).toBe(true)
    expect(w.find('[data-testid^="vanya-penilaian-"]').exists()).toBe(false)
  })

  it('tombol Topik dan Populer', async () => {
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-btn-populer"]').trigger('click')
    await flushPromises()
    expect(svc.populer).toHaveBeenCalledTimes(1)
    await w.get('[data-testid="vanya-btn-topik"]').trigger('click')
    await flushPromises()
    expect(svc.topik).toHaveBeenCalledTimes(2)
  })

  it('jawaban berisi skrip/handler tidak menghasilkan skrip atau atribut on* di DOM', async () => {
    svc.balas.mockResolvedValueOnce(cocok({ balasan: '<p onclick="alert(1)">Hai</p><script>alert(2)</script><a href="javascript:alert(3)">x</a>' }))
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-input"]').setValue('hai')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    const html = w.get('[data-pesan="jawaban"]').html()
    expect(html).not.toContain('<script')
    expect(html).not.toMatch(/onclick/i)
    expect(html).not.toContain('javascript:')
  })

  it('pesan pengguna ditampilkan sebagai teks, bukan HTML', async () => {
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-input"]').setValue('<img src=x onerror=alert(1)>')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    const user = w.findAll('[data-pesan="user"]').at(-1)!
    expect(user.find('img').exists()).toBe(false)
    expect(user.text()).toBe('<img src=x onerror=alert(1)>')
  })
})

describe('VanyaWidget — review CR (M-5..M-8)', () => {
  it('M-5: batas 300 per code point — 300 emoji terkirim utuh; 301 ASCII dipangkas ke 300 sebelum dikirim', async () => {
    const w = await pasang()
    await bukaChat(w)
    const input = w.get('[data-testid="vanya-input"]')
    const emoji = '😀'.repeat(300)
    await input.setValue(emoji)
    expect((input.element as HTMLInputElement).value).toBe(emoji)
    expect(w.get('#vanya-sisa').text()).toBe('Sisa 0 karakter')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    expect(svc.balas).toHaveBeenLastCalledWith(emoji, undefined)

    await input.setValue('a'.repeat(301))
    await flushPromises()
    expect((input.element as HTMLInputElement).value).toBe('a'.repeat(300))
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    expect(svc.balas).toHaveBeenLastCalledWith('a'.repeat(300), undefined)
    expect(svc.balas).not.toHaveBeenCalledWith('a'.repeat(301), undefined)
  })

  it('M-5: store menolak kiriman lebih dari 300 code point (penjaga kedua)', async () => {
    const { usePercakapanVanya } = await import('../stores/percakapan')
    expect(usePercakapanVanya().kirim('a'.repeat(301), 300)).toBeNull()
    expect(svc.balas).not.toHaveBeenCalled()
  })

  it('M-6: fokus kembali ke kotak ketik sesudah memilih chip, Topik/Populer, dan menilai', async () => {
    const w = await pasang()
    await bukaChat(w)
    const input = w.get('[data-testid="vanya-input"]').element
    await w.get('[data-testid="vanya-topik-3"]').trigger('click')
    await flushPromises()
    expect(document.activeElement).toBe(input)

    ;(w.get('[data-testid="vanya-btn-populer"]').element as HTMLButtonElement).focus()
    await w.get('[data-testid="vanya-btn-populer"]').trigger('click')
    await flushPromises()
    expect(document.activeElement).toBe(input)

    await w.get('[data-testid="vanya-pertanyaan-7"]').trigger('click')
    await flushPromises()
    expect(document.activeElement).toBe(input)

    ;(w.get('[data-action="suka"]').element as HTMLButtonElement).focus()
    await w.get('[data-action="suka"]').trigger('click')
    await flushPromises()
    expect(document.activeElement).toBe(input)
  })

  it('M-7: jam HH:MM di bawah setiap balon', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 10, 14, 8, 5))
    const w = await pasang()
    await bukaChat(w)
    const jam = w.findAll('[data-testid="vanya-jam"]').map((j) => j.text())
    expect(jam.length).toBeGreaterThan(0)
    expect(new Set(jam)).toEqual(new Set(['08:05']))
    vi.useRealTimers()
  })

  it('M-8: respons balas yang tiba sesudah logout + login pegawai lain dibuang', async () => {
    let lepas: (v: BalasanWidget) => void = () => {}
    svc.balas.mockImplementationOnce(() => new Promise<BalasanWidget>((r) => (lepas = r)))
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-input"]').setValue('cuti')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    expect(w.find('[data-testid="vanya-mengetik"]').exists()).toBe(true)

    useAuthStore().clearSession()
    login(Role.PTT)
    await flushPromises()
    lepas(cocok())
    await flushPromises()

    expect(w.find('[data-testid="vanya-mengetik"]').exists()).toBe(false)
    await bukaChat(w)
    expect(w.findAll('[data-pesan="user"]')).toHaveLength(0)
    expect(w.find('[data-pesan="jawaban"]').exists()).toBe(false)
    expect(w.findAll('[data-pesan="sistem"]').filter((p) => p.text().startsWith('Halo, saya Vanya'))).toHaveLength(1)
  })

  it('M-8: feedback yang masih menunggu saat logout tidak mengubah apa pun sesudahnya', async () => {
    let lepas: () => void = () => {}
    svc.feedback.mockImplementationOnce(() => new Promise<void>((r) => (lepas = r)))
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-input"]').setValue('cuti')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    await w.get('[data-action="suka"]').trigger('click')
    await flushPromises()

    useAuthStore().clearSession()
    login(Role.PEGAWAI)
    await flushPromises()
    lepas()
    await flushPromises()

    await bukaChat(w)
    expect(w.find('[data-pesan="jawaban"]').exists()).toBe(false)
    expect(w.find('[data-testid^="vanya-penilaian-"]').exists()).toBe(false)
    expect(w.text()).not.toContain('Terima kasih atas penilaiannya!')
  })
})

describe('VanyaWidget — galat', () => {
  it('galat muat data → pesan muat; 429 balas → pesan throttle server; koneksi → pesan koneksi', async () => {
    svc.topik.mockRejectedValueOnce(apiError(500, 'x'))
    const w = await pasang()
    await bukaChat(w)
    expect(w.get('[data-pesan="galat"]').text()).toContain('Vanya sedang tidak dapat memuat data')

    svc.balas.mockRejectedValueOnce(apiError(429, 'Terlalu banyak pertanyaan. Coba lagi dalam satu menit.'))
    await w.get('[data-testid="vanya-input"]').setValue('cuti')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    expect(w.findAll('[data-pesan="galat"]').at(-1)?.text()).toBe('Terlalu banyak pertanyaan. Coba lagi dalam satu menit.')

    svc.balas.mockRejectedValueOnce(apiError(null, 'Tidak dapat terhubung'))
    await w.get('[data-testid="vanya-input"]').setValue('cuti')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    expect(w.findAll('[data-pesan="galat"]').at(-1)?.text()).toContain('terjadi gangguan koneksi')
  })

  it('balas 422 dengan errors.pesan (array, amplop v2) → pesan kolom ditampilkan', async () => {
    svc.balas.mockRejectedValueOnce({ ...apiError(422, galat422Pesan.message), errors: galat422Pesan.errors })
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-input"]').setValue('cuti')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    expect(w.findAll('[data-pesan="galat"]').at(-1)?.text()).toBe('Pesan wajib diisi (maks 300 karakter).')
  })

  it('feedback gagal → pesan server + "Coba lagi:", tombol aktif kembali; 👎 sukses → terima kasih 👎', async () => {
    svc.feedback.mockRejectedValueOnce(apiError(403, 'Sesi penilaian tidak ditemukan. Silakan ajukan pertanyaan lagi.'))
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-input"]').setValue('cuti')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    await w.get('[data-action="tidak-suka"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-testid="vanya-penilaian-label"]').text()).toBe('Sesi penilaian tidak ditemukan. Silakan ajukan pertanyaan lagi. Coba lagi:')
    expect(w.get('[data-action="suka"]').attributes('disabled')).toBeUndefined()

    await w.get('[data-action="tidak-suka"]').trigger('click')
    await flushPromises()
    expect(svc.feedback).toHaveBeenLastCalledWith(41, -1, 'tok.en')
    expect(w.get('[data-pesan="jawaban"]').text()).toContain('Terima kasih, masukan Anda membantu kami memperbaiki jawaban.')
  })

  it('feedback gagal karena jaringan → "Penilaian gagal terkirim. Coba lagi:"', async () => {
    svc.feedback.mockRejectedValueOnce(apiError(null, 'Tidak dapat terhubung ke server.'))
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-input"]').setValue('cuti')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    await w.get('[data-action="suka"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-testid="vanya-penilaian-label"]').text()).toBe('Penilaian gagal terkirim. Coba lagi:')
  })
})

describe('VanyaWidget — lintas halaman & logout', () => {
  it('percakapan bertahan saat widget dipasang ulang, dan kosong sesudah logout (clearSession)', async () => {
    const w = await pasang()
    await bukaChat(w)
    await w.get('[data-testid="vanya-input"]').setValue('cuti')
    await w.get('[data-testid="vanya-form"]').trigger('submit')
    await flushPromises()
    w.unmount()

    const w2 = mount(VanyaWidget, { attachTo: document.body })
    wrapper = w2
    await flushPromises()
    expect(w2.find('[role="dialog"]').exists()).toBe(true)
    expect(w2.find('[data-pesan="jawaban"]').exists()).toBe(true)

    useAuthStore().clearSession()
    login(Role.PEGAWAI)
    await flushPromises()
    expect(w2.find('[role="dialog"]').exists()).toBe(false)
    expect(w2.get('[data-testid="vanya-launcher"]').attributes('aria-expanded')).toBe('false')
    await bukaChat(w2)
    expect(w2.find('[data-pesan="jawaban"]').exists()).toBe(false)
    expect(w2.findAll('[data-pesan="user"]')).toHaveLength(0)
  })
})
