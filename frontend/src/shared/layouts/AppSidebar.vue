<script setup lang="ts">
/**
 * Sidebar mengambang redesign (Laporan Redesign §4, Gambar 12): kartu putih bersudut besar berisi logo,
 * tombol collapse «, blok profil, lalu grup menu. Item aktif berlatar navy #1C3964 penuh.
 *
 * - Collapse → mode ikon (label tetap ada untuk pembaca layar lewat sr-only).
 * - Layar < lg: sidebar menjadi drawer; pembukaannya dikendalikan RedesignShell lewat `mobileOpen`.
 * - Blok profil membuka menu kecil "Ganti Password" / "Keluar" (mockup tidak menggambar logout, tetapi fungsi
 *   ini sudah ada sejak Fase 1 sehingga tidak boleh hilang).
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
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { UiAvatar } from '@/shared/ui'

import type { NavChild, NavGroup, NavItem } from './nav.config'

const props = defineProps<{
  groups: NavGroup[]
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
  if (props.collapsed) {
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

const itemBase =
  'group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-body1 transition-colors focus-visible:ring-offset-0'
const itemIdle = 'text-slate-700 hover:bg-slate-100'
const itemActive = 'bg-brand-primary text-white shadow-float'
</script>

<template>
  <aside
    class="fixed inset-y-0 left-0 z-40 flex w-72 max-w-[85vw] flex-col p-4 transition-transform duration-200 lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:translate-x-0 lg:p-5 lg:pr-0"
    :class="[mobileOpen ? 'translate-x-0' : '-translate-x-full', collapsed ? 'lg:w-[6.5rem]' : 'lg:w-[19rem]']"
    aria-label="Sidebar"
    data-testid="app-sidebar"
  >
    <div class="flex min-h-0 flex-1 flex-col rounded-[1.75rem] border border-white/70 bg-white shadow-panel lg:bg-white/85 lg:backdrop-blur">
      <!-- Logo + collapse -->
      <div class="flex items-center justify-between gap-2 px-5 pb-3 pt-5">
        <div class="flex min-w-0 items-center gap-3">
          <span
            class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-brand-primary text-h6 font-bold text-white ring-2 ring-brand-secondary"
            aria-hidden="true"
            title="Logo sementara"
          >
            S
          </span>
          <span v-if="!collapsed" class="truncate text-h5 font-semibold tracking-tight text-slate-900">SIMPEG</span>
        </div>

        <button
          type="button"
          class="hidden h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 transition hover:bg-slate-200 lg:inline-flex"
          :aria-label="collapsed ? 'Perluas sidebar' : 'Ciutkan sidebar'"
          :aria-expanded="!collapsed"
          data-testid="sidebar-collapse"
          @click="emit('update:collapsed', !collapsed)"
        >
          <ChevronsRight v-if="collapsed" class="h-4 w-4" aria-hidden="true" />
          <ChevronsLeft v-else class="h-4 w-4" aria-hidden="true" />
        </button>

        <button
          type="button"
          class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 lg:hidden"
          aria-label="Tutup menu"
          @click="closeMobile"
        >
          <X class="h-4 w-4" aria-hidden="true" />
        </button>
      </div>

      <!-- Profil -->
      <div class="border-b border-slate-200/80 px-4 pb-4">
        <DropdownMenuRoot :modal="false">
          <DropdownMenuTrigger
            class="flex w-full items-center gap-3 rounded-xl p-2 text-left transition hover:bg-slate-100 data-[state=open]:bg-slate-100"
            :aria-label="`Menu pengguna ${userName}`"
            data-testid="user-menu"
          >
            <UiAvatar :name="userName" size="md" />
            <span v-if="!collapsed" class="min-w-0 flex-1">
              <span class="block truncate text-body1 font-semibold text-slate-900">{{ userName }}</span>
              <span class="block truncate text-caption text-slate-500">{{ userSubtitle }}</span>
            </span>
            <ChevronDown v-if="!collapsed" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
          </DropdownMenuTrigger>

          <DropdownMenuPortal>
            <DropdownMenuContent
              align="start"
              :side-offset="6"
              class="z-50 min-w-48 rounded-xl border border-slate-200 bg-white p-1 text-body2 shadow-panel"
            >
              <DropdownMenuItem
                class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-slate-700 outline-none data-[highlighted]:bg-slate-100"
                data-testid="nav-change-password"
                @select="router.push({ name: 'change-password' })"
              >
                <KeyRound class="h-4 w-4" aria-hidden="true" /> Ganti Password
              </DropdownMenuItem>
              <DropdownMenuItem
                class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-danger outline-none data-[highlighted]:bg-danger-soft"
                data-testid="nav-logout"
                @select="emit('logout')"
              >
                <LogOut class="h-4 w-4" aria-hidden="true" /> Keluar
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenuPortal>
        </DropdownMenuRoot>
      </div>

      <!-- Menu -->
      <nav class="scrollbar-slim min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4" aria-label="Navigasi utama" data-testid="nav-main">
        <div v-for="group in groups" :key="group.key" class="space-y-1">
          <p v-if="group.title && !collapsed" class="px-3 pb-1 pt-1 text-overline uppercase text-slate-400">
            {{ group.title }}
          </p>
          <hr v-else-if="group.title" class="mx-3 border-slate-200" />

          <template v-for="item in group.items" :key="item.key">
            <!-- Item dengan submenu -->
            <div v-if="item.children">
              <button
                type="button"
                :class="[itemBase, hasActiveChild(item) ? 'bg-slate-100 font-medium text-brand-primary' : itemIdle]"
                :aria-expanded="openKeys.has(item.key)"
                :data-testid="`nav-${item.key}`"
                @click="toggleParent(item)"
              >
                <component :is="item.icon" class="h-5 w-5 shrink-0" aria-hidden="true" />
                <span :class="collapsed ? 'sr-only' : 'flex-1 truncate text-left'">{{ item.label }}</span>
                <ChevronDown
                  v-if="!collapsed"
                  class="h-4 w-4 shrink-0 text-slate-400 transition-transform"
                  :class="openKeys.has(item.key) ? 'rotate-180' : '-rotate-90'"
                  aria-hidden="true"
                />
              </button>

              <ul v-if="openKeys.has(item.key) && !collapsed" class="mt-1 space-y-1">
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

            <!-- Item biasa -->
            <RouterLink
              v-else-if="item.to"
              :to="item.to"
              :class="[itemBase, isActive(item) ? itemActive : itemIdle]"
              :aria-current="isActive(item) ? 'page' : undefined"
              :title="collapsed ? item.label : undefined"
              :data-testid="`nav-${item.key}`"
              @click="closeMobile"
            >
              <component :is="item.icon" class="h-5 w-5 shrink-0" aria-hidden="true" />
              <span :class="collapsed ? 'sr-only' : 'flex-1 truncate'">{{ item.label }}</span>
              <span
                v-if="item.badge && !collapsed"
                class="inline-flex min-w-5 items-center justify-center rounded-full bg-danger px-1.5 text-caption font-medium text-white"
              >
                {{ item.badge }}
              </span>
            </RouterLink>
          </template>
        </div>
      </nav>
    </div>
  </aside>
</template>
