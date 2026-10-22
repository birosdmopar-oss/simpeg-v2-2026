<script setup lang="ts">
/**
 * Sidebar mengambang redesign (Laporan Redesign §4, Gambar 12): kartu putih bersudut besar berisi logo,
 * blok profil, lalu grup menu. Item aktif berlatar navy #1C3964 penuh.
 *
 * - Mode ciut (ikon) HANYA di desktop (lg): `ciut = collapsed (preferensi) && isDesktop`. Drawer mobile SELALU mode penuh
 *   (logo + nama, profil lengkap, ikon + label) dan memakai tombol tutup (X); preferensi ciut tidak diubah saat viewport
 *   berpindah (CR-060 butir I).
 * - Tombol ciutkan/perluas menjorok setengah ke luar tepi kanan sidebar, sejajar logo (Figma), di LUAR kontainer gulir
 *   sehingga tidak terpotong; hanya tampil di desktop (CR-060 butir H).
 * - Blok profil membuka menu "Ganti Password" / "Keluar"; panel selebar trigger dan item seragam dengan item nav
 *   (CR-060 butir G). Fungsi logout sudah ada sejak Fase 1 sehingga tidak boleh hilang.
 * - Daftar menu = area gulir mandiri (`overflow-y-auto`); tinggi sidebar = viewport (`lg:h-screen`).
 *
 * ASET SEMENTARA: lencana "S" pada logo adalah placeholder; lambang Kemenpar final belum tersedia.
 */
import { ChevronDown, ChevronsLeft, ChevronsRight, KeyRound, LogOut, X } from 'lucide-vue-next'
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuPortal,
  DropdownMenuRoot,
  DropdownMenuTrigger,
} from 'radix-vue'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useIsDesktop } from '@/shared/composables/useIsDesktop'
import { UiAvatar, UiTooltip } from '@/shared/ui'

import type { NavChild, NavGroup, NavItem } from './nav.config'

const props = defineProps<{
  groups: NavGroup[]
  /** Preferensi ciut pengguna (tersimpan); efektif hanya di desktop. */
  collapsed: boolean
  mobileOpen: boolean
  userName: string
  userSubtitle: string
}>()

const emit = defineEmits<{
  'update:collapsed': [value: boolean]
  'update:mobileOpen': [value: boolean]
  logout: []
}>()

const route = useRoute()
const router = useRouter()
const isDesktop = useIsDesktop()
/** Mode ciut efektif: preferensi AND desktop (drawer mobile selalu penuh). */
const ciut = computed(() => props.collapsed && isDesktop.value)

function pathOf(to: NavItem['to'] | NavChild['to']): string | null {
  if (!to) return null
  return router.resolve(to).path
}

/** Aktif bila path sama, atau path halaman berada di bawahnya (mis. /pegawai/1985… → menu Daftar Pegawai). */
function isActivePath(path: string | null): boolean {
  if (!path) return false
  if (path === '/') return route.path === '/'
  return route.path === path || route.path.startsWith(`${path}/`)
}

const isActive = (item: NavItem): boolean => isActivePath(pathOf(item.to))
const isChildActive = (child: NavChild): boolean => isActivePath(pathOf(child.to))
const hasActiveChild = (item: NavItem): boolean => (item.children ?? []).some(isChildActive)

const openKeys = ref<Set<string>>(new Set())

function syncOpenWithRoute(): void {
  for (const group of props.groups) {
    for (const item of group.items) {
      if (item.children && hasActiveChild(item)) openKeys.value.add(item.key)
    }
  }
}
syncOpenWithRoute()
watch(() => route.path, syncOpenWithRoute)

function toggleParent(item: NavItem): void {
  if (ciut.value) {
    emit('update:collapsed', false)
    openKeys.value.add(item.key)
    return
  }
  if (openKeys.value.has(item.key)) openKeys.value.delete(item.key)
  else openKeys.value.add(item.key)
}

function closeMobile(): void {
  emit('update:mobileOpen', false)
}

/** Kelas dasar item nav = juga item menu profil (padding horizontal dan radius seragam, CR-060 G). */
const itemBase =
  'group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-body1 transition-colors focus-visible:ring-offset-0'
const itemIdle = 'text-slate-700 hover:bg-slate-100'
const itemActive = 'bg-brand-primary text-white shadow-float'
/** Mode ciut: ikon di tengah kolom tanpa jarak label. */
const itemCiut = 'justify-center gap-0 px-0'
const menuItemBase = 'flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-body1 outline-none'
</script>

<template>
  <!--
    `z-40` mobile (drawer di atas overlay z-30); `lg:z-30` agar tombol ciut yang menjorok ke kolom konten tidak tertutup
    pembungkus navbar sticky (z-20) dan tetap di bawah dialog/dropdown (z-50).
  -->
  <aside
    class="fixed inset-y-0 left-0 z-40 flex w-72 max-w-[85vw] flex-col p-4 transition-transform duration-200 lg:sticky lg:top-0 lg:z-30 lg:h-screen lg:translate-x-0 lg:p-5 lg:pr-0"
    :class="[mobileOpen ? 'translate-x-0' : '-translate-x-full', ciut ? 'lg:w-[6.5rem]' : 'lg:w-[19rem]']"
    aria-label="Sidebar"
    data-testid="app-sidebar"
  >
    <!--
      Panel sidebar bersudut bulat: `overflow-hidden` memotong isi (termasuk scrollbar menu) mengikuti lengkung di semua
      browser (CR-063). Yang TIDAK boleh terpotong ada di luar panel ini: tombol ciutkan (anak langsung <aside>) serta
      dropdown profil dan tooltip menu (radix portal ke <body>).
    -->
    <div
      class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-[1.75rem] border border-white/70 bg-white shadow-panel lg:bg-white/85 lg:backdrop-blur"
      data-testid="sidebar-panel"
    >
      <!-- Logo (+ tombol tutup di drawer mobile) -->
      <div class="flex items-center gap-2 px-5 pb-3 pt-5" :class="ciut ? 'justify-center px-3' : 'justify-between'">
        <div class="flex min-w-0 items-center gap-3">
          <span
            class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-brand-primary text-h6 font-bold text-white ring-2 ring-brand-secondary"
            aria-hidden="true"
          >
            S
          </span>
          <span v-if="!ciut" class="truncate text-h5 font-semibold tracking-tight text-slate-900">SIMPEG</span>
        </div>

        <button
          type="button"
          class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 lg:hidden"
          aria-label="Tutup menu"
          data-testid="sidebar-close"
          @click="closeMobile"
        >
          <X class="h-4 w-4" aria-hidden="true" />
        </button>
      </div>

      <!-- Profil -->
      <div class="border-b border-slate-200/80 px-4 pb-4" :class="ciut ? 'px-3' : ''">
        <DropdownMenuRoot :modal="false">
          <DropdownMenuTrigger
            class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left transition hover:bg-slate-100 data-[state=open]:bg-slate-100"
            :class="ciut ? 'justify-center px-0' : ''"
            :aria-label="`Menu pengguna ${userName}`"
            data-testid="user-menu"
          >
            <UiAvatar :name="userName" size="md" />
            <span v-if="!ciut" class="min-w-0 flex-1">
              <UiTooltip :content="userName">
                <span class="block truncate text-body1 font-semibold text-slate-900" data-testid="user-name">{{ userName }}</span>
              </UiTooltip>
              <span class="block truncate text-caption text-slate-500">{{ userSubtitle }}</span>
            </span>
            <ChevronDown v-if="!ciut" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
          </DropdownMenuTrigger>

          <DropdownMenuPortal>
            <DropdownMenuContent
              align="start"
              :side-offset="6"
              :collision-padding="8"
              class="z-50 w-[var(--radix-dropdown-menu-trigger-width)] min-w-48 rounded-xl border border-slate-200 bg-white p-1 text-body2 shadow-panel"
              data-testid="user-menu-panel"
            >
              <DropdownMenuItem
                :class="[menuItemBase, 'text-slate-700 data-[highlighted]:bg-slate-100']"
                data-testid="nav-change-password"
                @select="router.push({ name: 'change-password' })"
              >
                <KeyRound class="h-5 w-5 shrink-0" aria-hidden="true" /> Ganti Password
              </DropdownMenuItem>
              <DropdownMenuItem
                :class="[menuItemBase, 'text-danger data-[highlighted]:bg-danger-soft']"
                data-testid="nav-logout"
                @select="emit('logout')"
              >
                <LogOut class="h-5 w-5 shrink-0" aria-hidden="true" /> Keluar
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenuPortal>
        </DropdownMenuRoot>
      </div>

      <!--
        Menu (area gulir mandiri). `relative` = kotak penampung bagi label `sr-only` (absolute) item menu saat ciut; tanpa itu
        label lolos dari overflow-y-auto dan menambah tinggi dokumen (window bisa tergulir beberapa piksel, CR-060).
      -->
      <nav class="sidebar-scroll relative min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4" :class="ciut ? 'px-3' : ''" aria-label="Navigasi utama" data-testid="nav-main">
        <div v-for="group in groups" :key="group.key" class="space-y-1">
          <p v-if="group.title && !ciut" class="px-3 pb-1 pt-1 text-overline uppercase text-slate-400">
            {{ group.title }}
          </p>
          <hr v-else-if="group.title" class="mx-3 border-slate-200" />

          <template v-for="item in group.items" :key="item.key">
            <!-- Item dengan submenu -->
            <div v-if="item.children">
              <button
                type="button"
                :class="[itemBase, ciut ? itemCiut : '', hasActiveChild(item) ? 'bg-slate-100 font-medium text-brand-primary' : itemIdle]"
                :aria-expanded="openKeys.has(item.key)"
                :data-testid="`nav-${item.key}`"
                @click="toggleParent(item)"
              >
                <component :is="item.icon" class="h-5 w-5 shrink-0" aria-hidden="true" />
                <span :class="ciut ? 'sr-only' : 'flex-1 truncate text-left'">{{ item.label }}</span>
                <ChevronDown
                  v-if="!ciut"
                  class="h-4 w-4 shrink-0 text-slate-400 transition-transform"
                  :class="openKeys.has(item.key) ? 'rotate-180' : '-rotate-90'"
                  aria-hidden="true"
                />
              </button>

              <ul v-if="openKeys.has(item.key) && !ciut" class="mt-1 space-y-1">
                <li v-for="child in item.children" :key="child.key">
                  <RouterLink
                    :to="child.to"
                    :class="[itemBase, 'pl-4 text-body2', isChildActive(child) ? itemActive : itemIdle]"
                    :aria-current="isChildActive(child) ? 'page' : undefined"
                    :data-testid="`nav-${child.key}`"
                    @click="closeMobile"
                  >
                    <span
                      class="h-2 w-2 shrink-0 rounded-full border-2"
                      :class="isChildActive(child) ? 'border-white' : 'border-slate-400'"
                      aria-hidden="true"
                    />
                    {{ child.label }}
                  </RouterLink>
                </li>
              </ul>
            </div>

            <!-- Item biasa (ciut: label hanya lewat tooltip custom + sr-only) -->
            <UiTooltip v-else-if="item.to" :content="ciut ? item.label : ''" side="right">
              <RouterLink
                :to="item.to"
                :class="[itemBase, ciut ? itemCiut : '', isActive(item) ? itemActive : itemIdle]"
                :aria-current="isActive(item) ? 'page' : undefined"
                :data-testid="`nav-${item.key}`"
                @click="closeMobile"
              >
                <component :is="item.icon" class="h-5 w-5 shrink-0" aria-hidden="true" />
                <span :class="ciut ? 'sr-only' : 'flex-1 truncate'">{{ item.label }}</span>
                <span
                  v-if="item.badge && !ciut"
                  class="inline-flex min-w-5 items-center justify-center rounded-full bg-danger px-1.5 text-caption font-medium text-white"
                >
                  {{ item.badge }}
                </span>
              </RouterLink>
            </UiTooltip>
          </template>
        </div>
      </nav>
    </div>

    <!--
      Tombol ciutkan/perluas: di LUAR kartu dan kontainer gulir, menjorok setengah ke luar tepi kanan, sejajar logo
      (Figma). Hanya desktop; tidak ada di drawer mobile.
    -->
    <button
      v-if="isDesktop"
      type="button"
      class="absolute -right-3 top-[3.875rem] z-10 hidden h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-card transition hover:bg-slate-100 hover:text-brand-primary lg:inline-flex"
      :aria-label="ciut ? 'Perluas menu' : 'Ciutkan menu'"
      :aria-expanded="!ciut"
      data-testid="sidebar-collapse"
      @click="emit('update:collapsed', !collapsed)"
    >
      <ChevronsRight v-if="ciut" class="h-4 w-4" aria-hidden="true" />
      <ChevronsLeft v-else class="h-4 w-4" aria-hidden="true" />
    </button>
  </aside>
</template>
