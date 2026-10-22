/**
 * RedesignShell — menu per role, item aktif, blok profil (Ganti Password & Keluar), collapse sidebar, drawer mobile,
 * breadcrumb, dan tautan lewati-ke-konten. Menggantikan AppShell.spec.ts (AppShell lama dipensiunkan): asersi menu per
 * role & Web Config dipindah ke sini dan ke nav.config.spec.ts; Ganti Password + Keluar untuk semua role di bawah.
 * Layout sungguhan tidak bisa diukur di jsdom; lebar 375px dicek di browser, test ini mengunci class/atribut kuncinya.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/auth/services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn().mockResolvedValue(undefined), changePassword: vi.fn() },
}))

import { authService } from '@/features/auth/services/auth.service'
import { Role, type RoleCode } from '@/features/auth/types'

import RedesignShell from '../RedesignShell.vue'

import { createShellRouter, loginAs } from './shellTestUtils'

async function mountShell(role: RoleCode, path = '/', extra: Parameters<typeof loginAs>[1] = {}, breadcrumbs?: { label: string }[]) {
  loginAs(role, extra)
  const router = createShellRouter()
  await router.push(path)
  await router.isReady()
  const wrapper = mount(RedesignShell, {
    props: { breadcrumbs },
    slots: { default: '<p data-testid="content">isi halaman</p>' },
    global: { plugins: [router] },
    attachTo: document.body,
  })
  return { wrapper, router }
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  window.localStorage.clear()
  vi.mocked(authService.logout).mockClear()
})

describe('RedesignShell — menu', () => {
  it('Super Admin melihat menu Fase 3 + menu main dan slot konten dirender di <main>', async () => {
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    const links = wrapper.get('[data-testid="nav-main"]').findAll('a').map((a) => a.text())

    expect(links).toEqual([
      'Beranda', 'Daftar Pegawai', 'Pengajuan Data Umum', 'Struktur Organisasi', 'Plt/Plh', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH',
      'Master Data', 'Angka Kredit JF', 'Web Config', 'Hari Libur', 'Manajemen Akun', 'FAQ',
    ])
    expect(wrapper.get('[data-testid="nav-web-config"]').attributes('href')).toBe('/web-config')
    expect(wrapper.get('main#main-content [data-testid="content"]').text()).toBe('isi halaman')
  })

  it('Pegawai melihat menu Fase 3 miliknya tanpa Daftar Pegawai', async () => {
    const { wrapper } = await mountShell(Role.PEGAWAI)
    expect(wrapper.get('[data-testid="nav-main"]').findAll('a').map((a) => a.text())).toEqual([
      'Beranda', 'Struktur Organisasi', 'Usulan Konket', 'Usulan Karpeg/Karis', 'Verifikasi LKH', 'FAQ',
    ])
    expect(wrapper.find('[data-testid="nav-pegawai"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="nav-struktur"]').attributes('href')).toBe('/struktur-organisasi')
  })

  it('Pegawai ber-NIP: menu "Kelola Data" menuju profil sendiri (CR-049)', async () => {
    const { wrapper } = await mountShell(Role.PEGAWAI, '/', { nip: '199001012015011001' })
    expect(wrapper.get('[data-testid="nav-data-saya"]').attributes('href')).toBe('/pegawai/199001012015011001')
  })

  it('item aktif memakai aria-current="page"; halaman anak tetap menandai menu induknya', async () => {
    const { wrapper, router } = await mountShell(Role.SUPER_ADMIN, '/master')
    expect(wrapper.get('[data-testid="nav-master-data"]').attributes('aria-current')).toBe('page')
    expect(wrapper.get('[data-testid="nav-home"]').attributes('aria-current')).toBeUndefined()

    await router.push('/master/agama')
    await flushPromises()
    expect(wrapper.get('[data-testid="nav-master-data"]').attributes('aria-current')).toBe('page')
  })

  it('wajib ganti password (MAKE-020): semua menu disembunyikan, menu profil tetap ada', async () => {
    const { wrapper } = await mountShell(Role.SUPER_ADMIN, '/ganti-password', { must_change_password: true })
    expect(wrapper.find('[data-testid="nav-main"] a').exists()).toBe(false)
    expect(wrapper.find('[data-testid="nav-master-data"]').exists()).toBe(false)

    await wrapper.get('[data-testid="user-menu"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[data-testid="nav-change-password"]')).not.toBeNull()
    expect(document.body.querySelector('[data-testid="nav-logout"]')).not.toBeNull()
  })

  it('Beranda hanya aktif di "/" (bukan semua path)', async () => {
    const { wrapper } = await mountShell(Role.SUPER_ADMIN, '/faq')
    expect(wrapper.get('[data-testid="nav-home"]').attributes('aria-current')).toBeUndefined()
    expect(wrapper.get('[data-testid="nav-faq"]').attributes('aria-current')).toBe('page')
  })
})

describe('RedesignShell — profil & sesi', () => {
  it('blok profil menampilkan nama & NIP (atau label role bila akun tanpa NIP)', async () => {
    const withNip = await mountShell(Role.PEGAWAI, '/', { name: 'Budi Contoh', nip: '197001011995011001' })
    expect(withNip.wrapper.get('[data-testid="user-menu"]').text()).toContain('Budi Contoh')
    expect(withNip.wrapper.get('[data-testid="user-menu"]').text()).toContain('197001011995011001')
    withNip.wrapper.unmount()

    const noNip = await mountShell(Role.SUPER_ADMIN, '/', { name: 'Administrator', nip: null })
    expect(noNip.wrapper.get('[data-testid="user-menu"]').text()).toContain('Super Admin')
  })

  it.each(Object.values(Role))('role %i punya "Ganti Password" dan "Keluar" di menu profil', async (role) => {
    const { wrapper } = await mountShell(role)
    await wrapper.get('[data-testid="user-menu"]').trigger('click')
    await flushPromises()

    expect(document.body.querySelector('[data-testid="nav-change-password"]')?.textContent).toContain('Ganti Password')
    expect(document.body.querySelector('[data-testid="nav-logout"]')?.textContent).toContain('Keluar')
  })

  it('"Ganti Password" membuka /ganti-password', async () => {
    const { wrapper, router } = await mountShell(Role.PEGAWAI)
    await wrapper.get('[data-testid="user-menu"]').trigger('click')
    await flushPromises()
    document.body.querySelector<HTMLElement>('[data-testid="nav-change-password"]')?.click()
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('change-password')
  })

  it('"Keluar" memanggil logout lalu mengarahkan ke login', async () => {
    const { wrapper, router } = await mountShell(Role.SUPER_ADMIN)
    await wrapper.get('[data-testid="user-menu"]').trigger('click')
    await flushPromises()
    document.body.querySelector<HTMLElement>('[data-testid="nav-logout"]')?.click()
    await flushPromises()

    expect(authService.logout).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('login')
  })
})

describe('RedesignShell — sidebar & layout', () => {
  it('collapse mengubah lebar sidebar, menyembunyikan label secara visual (sr-only), dan diingat', async () => {
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    const sidebar = wrapper.get('[data-testid="app-sidebar"]')
    const toggle = wrapper.get('[data-testid="sidebar-collapse"]')

    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(sidebar.classes()).toContain('lg:w-[19rem]')

    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(sidebar.classes()).toContain('lg:w-[6.5rem]')
    // Label tetap ada untuk pembaca layar.
    expect(wrapper.get('[data-testid="nav-master-data"] span').classes()).toContain('sr-only')
    expect(window.localStorage.getItem('simpeg.sidebar.collapsed')).toBe('1')
  })

  it('status collapse tersimpan dibaca lagi saat dibuka ulang', async () => {
    window.localStorage.setItem('simpeg.sidebar.collapsed', '1')
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    expect(wrapper.get('[data-testid="app-sidebar"]').classes()).toContain('lg:w-[6.5rem]')
  })

  it('mobile: sidebar tersembunyi (-translate-x-full), hamburger membuka drawer + overlay, overlay menutup', async () => {
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    const sidebar = wrapper.get('[data-testid="app-sidebar"]')
    expect(sidebar.classes()).toContain('-translate-x-full')
    expect(wrapper.find('[data-testid="sidebar-overlay"]').exists()).toBe(false)

    await wrapper.get('[data-testid="topbar-open-menu"]').trigger('click')
    expect(sidebar.classes()).toContain('translate-x-0')
    expect(sidebar.classes()).not.toContain('-translate-x-full')

    await wrapper.get('[data-testid="sidebar-overlay"]').trigger('click')
    expect(sidebar.classes()).toContain('-translate-x-full')
  })

  it('breadcrumb hanya dirender bila diberikan; ruas terakhir bertanda halaman aktif', async () => {
    const without = await mountShell(Role.SUPER_ADMIN)
    expect(without.wrapper.find('nav[aria-label="Breadcrumb"]').exists()).toBe(false)
    without.wrapper.unmount()

    const { wrapper } = await mountShell(Role.SUPER_ADMIN, '/', {}, [{ label: 'Home' }, { label: 'Daftar Pegawai' }])
    const crumbs = wrapper.get('nav[aria-label="Breadcrumb"]')
    expect(crumbs.findAll('li').map((li) => li.text())).toEqual(['Home', 'Daftar Pegawai'])
    expect(crumbs.get('[aria-current="page"]').text()).toBe('Daftar Pegawai')
  })

  it('menyediakan tautan "Lewati ke konten" ke #main-content', async () => {
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    const skip = wrapper.get('a[href="#main-content"]')
    expect(skip.text()).toBe('Lewati ke konten')
    expect(skip.classes()).toContain('sr-only')
  })

  it('lonceng notifikasi tanpa titik merah bila tidak ada notifikasi belum dibaca', async () => {
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    const bell = wrapper.get('[data-testid="topbar-bell"]')
    expect(bell.attributes('aria-label')).toBe('Notifikasi')
    expect(bell.find('span').exists()).toBe(false)
  })
})

describe('RedesignShell — top navbar sticky (AGENTS.md bagian 3, CR-060)', () => {
  it('navbar dibungkus pembungkus sticky top-0 di atas konten tetapi di bawah drawer mobile dan dialog', async () => {
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    const sticky = wrapper.get('[data-testid="app-topbar-sticky"]')

    expect(sticky.classes()).toEqual(expect.arrayContaining(['sticky', 'top-0', 'z-20']))
    expect(sticky.find('[data-testid="app-topbar"]').exists()).toBe(true)

    // z-20 < overlay/drawer mobile (z-30/z-40) supaya navbar tidak menimpa drawer.
    expect(wrapper.get('[data-testid="app-sidebar"]').classes()).toContain('z-40')
    // Navbar ada SEBELUM konten di DOM; konten tidak berada di dalam pembungkus sticky.
    expect(sticky.find('#main-content').exists()).toBe(false)
  })
})

/** matchMedia palsu yang bisa diubah dan menembakkan event `change` (CR-060 butir I). */
function fakeViewport(desktop: boolean) {
  const listeners = new Set<(e: { matches: boolean }) => void>()
  let matches = desktop
  const original = window.matchMedia
  window.matchMedia = ((query: string) => ({
    get matches() {
      return matches
    },
    media: query,
    addEventListener: (_: string, fn: (e: { matches: boolean }) => void) => listeners.add(fn),
    removeEventListener: (_: string, fn: (e: { matches: boolean }) => void) => listeners.delete(fn),
    addListener: () => undefined,
    removeListener: () => undefined,
    dispatchEvent: () => true,
    onchange: null,
  })) as unknown as typeof window.matchMedia
  return {
    set(value: boolean) {
      matches = value
      for (const fn of listeners) fn({ matches: value })
    },
    restore() {
      window.matchMedia = original
    },
  }
}

describe('RedesignShell — sidebar: tombol ciut di tepi, dropdown profil, ciut hanya desktop (CR-060 G/H/I)', () => {
  let viewport: ReturnType<typeof fakeViewport> | null = null
  afterEach(() => {
    viewport?.restore()
    viewport = null
  })

  it('H: tombol ciutkan di luar kontainer gulir, menjorok ke luar tepi kanan, label ARIA berganti, mode lebar tidak berubah', async () => {
    viewport = fakeViewport(true)
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    const sidebar = wrapper.get('[data-testid="app-sidebar"]')
    const toggle = wrapper.get('[data-testid="sidebar-collapse"]')
    const nav = wrapper.get('[data-testid="nav-main"]')

    // Di luar elemen yang overflow-y-auto (tidak terpotong), anak langsung <aside>, posisi absolut menjorok setengah.
    expect(nav.element.contains(toggle.element)).toBe(false)
    expect(toggle.element.parentElement).toBe(sidebar.element)
    expect(toggle.classes()).toEqual(expect.arrayContaining(['absolute', '-right-3', 'z-10', 'rounded-full']))
    expect(nav.classes()).toContain('overflow-y-auto')
    // Di atas konten/navbar sticky tetapi di bawah dialog/dropdown (z-50).
    expect(sidebar.classes()).toContain('lg:z-30')

    expect(toggle.attributes('aria-label')).toBe('Ciutkan menu')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(sidebar.classes()).toContain('lg:w-[19rem]')
    expect(wrapper.get('[data-testid="nav-master-data"] span').classes()).not.toContain('sr-only')

    await toggle.trigger('click')
    expect(toggle.attributes('aria-label')).toBe('Perluas menu')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(sidebar.classes()).toContain('lg:w-[6.5rem]')
    // Ciut: logo, avatar, dan ikon menu satu kolom terpusat; tombol tetap di luar kontainer gulir.
    expect(wrapper.get('[data-testid="user-menu"]').classes()).toContain('justify-center')
    expect(wrapper.get('[data-testid="nav-master-data"]').classes()).toEqual(expect.arrayContaining(['justify-center', 'gap-0']))
    expect(wrapper.find('[data-testid="user-name"]').exists()).toBe(false)
  })

  it('G: panel menu profil selebar trigger; item w-full dengan padding dan radius sama seperti item nav; nama panjang lewat tooltip', async () => {
    viewport = fakeViewport(true)
    const { wrapper } = await mountShell(Role.SUPER_ADMIN, '/', { name: 'Hadi Wibowo Susanto Kusumawardhani, S.Kom., M.M.' })
    const trigger = wrapper.get('[data-testid="user-menu"]')

    await trigger.trigger('click')
    await flushPromises()
    const panel = document.body.querySelector('[data-testid="user-menu-panel"]')
    expect(panel, 'panel menu profil terbuka').not.toBeNull()
    expect(panel?.className).toContain('w-[var(--radix-dropdown-menu-trigger-width)]')

    const navItem = wrapper.get('[data-testid="nav-master-data"]').classes()
    const items = ['nav-change-password', 'nav-logout'].map((id) => document.body.querySelector<HTMLElement>(`[data-testid="${id}"]`))
    for (const item of items) {
      expect(item).not.toBeNull()
      const cls = [...(item?.classList ?? [])]
      // Padding horizontal, radius, dan w-full sama dengan item navigasi.
      for (const shared of ['w-full', 'px-3', 'py-2.5', 'rounded-xl']) {
        expect(cls, shared).toContain(shared)
        expect(navItem, shared).toContain(shared)
      }
    }

    // Nama panjang: dipotong (truncate) dan nama lengkap lewat UiTooltip, bukan atribut title.
    const name = wrapper.get('[data-testid="user-name"]')
    expect(name.classes()).toContain('truncate')
    expect(name.attributes('title')).toBeUndefined()
    expect(name.attributes('data-tooltip')).toBe('Hadi Wibowo Susanto Kusumawardhani, S.Kom., M.M.')
  })

  it('I: preferensi ciut + viewport mobile → drawer mode penuh, tanpa tombol ciutkan; kembali ke desktop → ciut lagi; preferensi tidak tertimpa', async () => {
    window.localStorage.setItem('simpeg.sidebar.collapsed', '1')
    viewport = fakeViewport(false)
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    const sidebar = wrapper.get('[data-testid="app-sidebar"]')

    // Mobile: mode penuh walau preferensi ciut = 1.
    expect(sidebar.classes()).toContain('lg:w-[19rem]')
    expect(sidebar.classes()).not.toContain('lg:w-[6.5rem]')
    expect(wrapper.find('[data-testid="sidebar-collapse"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="nav-master-data"] span').classes()).not.toContain('sr-only')
    expect(wrapper.get('[data-testid="nav-master-data"] span').text()).not.toBe('')
    expect(wrapper.find('[data-testid="user-name"]').exists()).toBe(true)
    expect(sidebar.text()).toContain('SIMPEG')
    expect(wrapper.get('[data-testid="nav-master-data"]').classes()).not.toContain('justify-center')
    expect(wrapper.get('[data-testid="sidebar-close"]').attributes('aria-label')).toBe('Tutup menu')
    // Preferensi tersimpan tidak berubah.
    expect(window.localStorage.getItem('simpeg.sidebar.collapsed')).toBe('1')

    // Kembali ke desktop: ciut aktif lagi sesuai preferensi, tombol ada kembali.
    viewport.set(true)
    await flushPromises()
    expect(sidebar.classes()).toContain('lg:w-[6.5rem]')
    expect(wrapper.get('[data-testid="sidebar-collapse"]').attributes('aria-label')).toBe('Perluas menu')
    expect(wrapper.get('[data-testid="nav-master-data"] span').classes()).toContain('sr-only')
    expect(window.localStorage.getItem('simpeg.sidebar.collapsed')).toBe('1')

    // Pindah ke mobile lagi lalu kembali: preferensi tetap.
    viewport.set(false)
    await flushPromises()
    expect(sidebar.classes()).toContain('lg:w-[19rem]')
    viewport.set(true)
    await flushPromises()
    expect(window.localStorage.getItem('simpeg.sidebar.collapsed')).toBe('1')
  })

  it('I: drawer mobile — fokus pindah ke tombol tutup saat dibuka, Esc menutup dan fokus kembali ke tombol pemicu', async () => {
    viewport = fakeViewport(false)
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    const opener = wrapper.get('[data-testid="topbar-open-menu"]')

    await opener.trigger('click')
    await flushPromises()
    expect(document.activeElement).toBe(wrapper.get('[data-testid="sidebar-close"]').element)
    expect(wrapper.get('[data-testid="app-sidebar"]').classes()).toContain('translate-x-0')

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await flushPromises()
    expect(wrapper.get('[data-testid="app-sidebar"]').classes()).toContain('-translate-x-full')
    expect(document.activeElement).toBe(opener.element)

    // Tombol tutup (X) juga menutup dan mengembalikan fokus.
    await opener.trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="sidebar-close"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="app-sidebar"]').classes()).toContain('-translate-x-full')
    expect(document.activeElement).toBe(opener.element)
  })
})

describe('RedesignShell — --page-top diukur dari posisi <main> (CR-060, window tidak boleh menggulir di halaman isi-penuh)', () => {
  const asli = HTMLElement.prototype.getBoundingClientRect

  afterEach(() => {
    HTMLElement.prototype.getBoundingClientRect = asli
  })

  it('tanpa pengukuran (jsdom: top 0) CSS var tidak dipasang sehingga cadangan main.css berlaku', async () => {
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    expect((wrapper.get('[data-testid="app-shell"]').element as HTMLElement).style.getPropertyValue('--page-top')).toBe('')
  })

  it('top <main> terukur dipasang sebagai --page-top di akar shell dan diukur ulang saat jendela diubah', async () => {
    let top = 137
    HTMLElement.prototype.getBoundingClientRect = function (this: HTMLElement) {
      return { top: this.id === 'main-content' ? top : 0, left: 0, right: 0, bottom: 0, width: 0, height: 0, x: 0, y: 0, toJSON: () => ({}) } as DOMRect
    }
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    const root = wrapper.get('[data-testid="app-shell"]').element as HTMLElement
    expect(root.style.getPropertyValue('--page-top')).toBe('137px')

    // Breadcrumb/navbar berubah tinggi → posisi main bergeser → diukur ulang (event resize jendela).
    top = 170.5
    window.dispatchEvent(new Event('resize'))
    await flushPromises()
    expect(root.style.getPropertyValue('--page-top')).toBe('170.5px')
  })

  it('breadcrumb ditambahkan → diukur ulang', async () => {
    let top = 104
    HTMLElement.prototype.getBoundingClientRect = function (this: HTMLElement) {
      return { top: this.id === 'main-content' ? top : 0, left: 0, right: 0, bottom: 0, width: 0, height: 0, x: 0, y: 0, toJSON: () => ({}) } as DOMRect
    }
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    const root = wrapper.get('[data-testid="app-shell"]').element as HTMLElement
    expect(root.style.getPropertyValue('--page-top')).toBe('104px')

    top = 137
    await wrapper.setProps({ breadcrumbs: [{ label: 'Home' }, { label: 'Master Data' }] })
    await flushPromises()
    expect(root.style.getPropertyValue('--page-top')).toBe('137px')
  })
})
