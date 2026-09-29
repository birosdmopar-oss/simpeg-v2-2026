/**
 * RedesignShell — menu per role, item aktif, blok profil (Ganti Password & Keluar), collapse sidebar, drawer mobile,
 * breadcrumb, dan tautan lewati-ke-konten. Menggantikan AppShell.spec.ts (AppShell lama dipensiunkan).
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
  it('Super Admin melihat menu fase 0–3 dan slot konten dirender di <main>', async () => {
    const { wrapper } = await mountShell(Role.SUPER_ADMIN)
    const links = wrapper.get('[data-testid="nav-main"]').findAll('a').map((a) => a.text())

    expect(links).toEqual(['Dashboards', 'Daftar Pegawai', 'Struktur Organisasi', 'Master Data', 'Hari Libur', 'Manajemen Akun', 'FAQ'])
    expect(wrapper.get('main#main-content [data-testid="content"]').text()).toBe('isi halaman')
  })

  it('Pegawai hanya melihat Dashboards, Struktur Organisasi, FAQ', async () => {
    const { wrapper } = await mountShell(Role.PEGAWAI)
    expect(wrapper.get('[data-testid="nav-main"]').findAll('a').map((a) => a.text())).toEqual(['Dashboards', 'Struktur Organisasi', 'FAQ'])
    expect(wrapper.find('[data-testid="nav-pegawai"]').exists()).toBe(false)
  })

  it('item aktif memakai aria-current="page"; halaman anak (detail pegawai) tetap menandai Daftar Pegawai', async () => {
    const { wrapper, router } = await mountShell(Role.SUPER_ADMIN, '/pegawai')
    expect(wrapper.get('[data-testid="nav-pegawai"]').attributes('aria-current')).toBe('page')
    expect(wrapper.get('[data-testid="nav-home"]').attributes('aria-current')).toBeUndefined()

    await router.push('/pegawai/197001011995011001')
    await flushPromises()
    expect(wrapper.get('[data-testid="nav-pegawai"]').attributes('aria-current')).toBe('page')
  })

  it('Dashboards hanya aktif di "/" (bukan semua path)', async () => {
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
    expect(wrapper.get('[data-testid="nav-pegawai"] span').classes()).toContain('sr-only')
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
