<script setup lang="ts">
/**
 * Halaman Manajemen Akun (A-12, MTC-004/005): tabel sort/search, form tambah/edit, modal konfirmasi nonaktifkan/hapus.
 * Hanya dirender untuk role 1 & 3 (route meta roles + menu di RedesignShell); scoping satker ditegakkan backend.
 * Akun non-pegawai (DBV-010/CR-013) boleh tanpa NIP: baris milik sendiri dikenali lewat id_pengguna, bukan NIP.
 */
import { ArrowDown, ArrowUp, ArrowUpDown, Pencil, Plus, Search, Trash2, UserCheck, UserX } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'

import UserFormDialog from '../components/UserFormDialog.vue'
import { usersService } from '../services/users.service'
import { useAuthStore } from '../stores/auth.store'
import { Role, ROLE_LABELS, type RoleCode, type User, type UserListQuery } from '../types'

type SortKey = NonNullable<UserListQuery['sort']>

const auth = useAuthStore()

const items = ref<User[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(10)
const loading = ref(false)
const error = ref('')
const search = ref('')
const roleFilter = ref<RoleCode | ''>('')
const statusFilter = ref<'0' | '1' | ''>('')
const sort = ref<SortKey>('username')
const order = ref<'asc' | 'desc'>('asc')

const formOpen = ref(false)
const editing = ref<User | null>(null)

const confirm = ref<{ open: boolean; kind: 'toggle' | 'delete'; user: User | null; loading: boolean }>({
  open: false,
  kind: 'toggle',
  user: null,
  loading: false,
})

const notice = ref('')

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

const columns: Array<{ key: SortKey; label: string }> = [
  { key: 'username', label: 'Username' },
  { key: 'name', label: 'Nama' },
  { key: 'nip', label: 'NIP' },
  { key: 'user_level', label: 'Role' },
  { key: 'status', label: 'Status' },
  { key: 'last_login_at', label: 'Login terakhir' },
]

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const result = await usersService.list({
      search: search.value,
      user_level: roleFilter.value,
      status: statusFilter.value,
      sort: sort.value,
      order: order.value,
      page: page.value,
      per_page: perPage.value,
    })
    items.value = result.items
    total.value = result.total
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal memuat daftar akun.'
  } finally {
    loading.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    void load()
  }, 300)
})
watch([roleFilter, statusFilter], () => {
  page.value = 1
  void load()
})

function toggleSort(key: SortKey): void {
  if (sort.value === key) {
    order.value = order.value === 'asc' ? 'desc' : 'asc'
  } else {
    sort.value = key
    order.value = 'asc'
  }
  void load()
}

function goTo(next: number): void {
  page.value = Math.min(Math.max(1, next), totalPages.value)
  void load()
}

function openCreate(): void {
  editing.value = null
  formOpen.value = true
}

function openEdit(user: User): void {
  editing.value = user
  formOpen.value = true
}

function onSaved(user: User): void {
  notice.value = editing.value ? `Akun ${user.username} diperbarui.` : `Akun ${user.username} dibuat dan bisa langsung dipakai login.`
  void load()
}

function askToggle(user: User): void {
  confirm.value = { open: true, kind: 'toggle', user, loading: false }
}

function askDelete(user: User): void {
  confirm.value = { open: true, kind: 'delete', user, loading: false }
}

/** Baris akun yang sedang login (aksi nonaktifkan/hapus dimatikan). Dibandingkan per id_pengguna: NIP bisa NULL. */
function isSelf(user: User): boolean {
  return auth.user !== null && user.id_pengguna === auth.user.id_pengguna
}

/** Aksi baris untuk menu titik tiga (aturan UI: semua aksi per baris lewat RowActionsMenu). */
function userActions(user: User): RowAction[] {
  const self = isSelf(user)
  return [
    { key: 'edit', label: 'Edit', icon: Pencil },
    user.status === '1'
      ? { key: 'toggle', label: 'Nonaktifkan', icon: UserX, disabled: self }
      : { key: 'toggle', label: 'Aktifkan', icon: UserCheck, disabled: self },
    { key: 'delete', label: 'Hapus', icon: Trash2, danger: true, disabled: self },
  ]
}

function onUserAction(user: User, key: string): void {
  if (key === 'edit') openEdit(user)
  else if (key === 'toggle') askToggle(user)
  else if (key === 'delete') askDelete(user)
}

async function onConfirm(): Promise<void> {
  const target = confirm.value.user
  if (!target) return
  confirm.value.loading = true
  try {
    if (confirm.value.kind === 'toggle') {
      const next = target.status === '1' ? '0' : '1'
      await usersService.setStatus(target.id_pengguna, next)
      notice.value = next === '0' ? `Akun ${target.username} dinonaktifkan; tidak bisa lagi dipakai login.` : `Akun ${target.username} diaktifkan.`
    } else {
      await usersService.remove(target.id_pengguna)
      notice.value = `Akun ${target.username} dihapus.`
    }
    confirm.value.open = false
    await load()
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Aksi gagal.'
    confirm.value.open = false
  } finally {
    confirm.value.loading = false
  }
}

const confirmText = computed(() => {
  const u = confirm.value.user
  if (!u) return { title: '', description: '' }
  if (confirm.value.kind === 'delete') {
    return { title: `Hapus akun ${u.username}?`, description: 'Akun dihapus (soft delete) dan seluruh sesi login akun tersebut dicabut. Tindakan ini tidak dapat dibatalkan dari halaman ini.' }
  }
  return u.status === '1'
    ? { title: `Nonaktifkan akun ${u.username}?`, description: 'Akun tidak akan bisa login sampai diaktifkan kembali. Sesi yang sedang berjalan ikut dicabut.' }
    : { title: `Aktifkan akun ${u.username}?`, description: 'Akun akan bisa dipakai login kembali.' }
})

function formatDate(value: string | null): string {
  if (!value) return '—'
  const d = new Date(value.replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? value : d.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-h4 text-slate-900">Manajemen Akun</h1>
        <p class="text-sm text-slate-500">
          {{ auth.role === Role.ADMIN_SATKER ? `Akun di satker ${auth.user?.id_satker ?? ''}` : 'Seluruh akun pengguna' }}
        </p>
      </div>
      <button
        type="button"
        class="inline-flex h-10 items-center gap-2 rounded-lg bg-brand-tertiary text-white transition hover:bg-[#1667e0] px-4 text-body1 font-medium"
        data-testid="user-add"
        @click="openCreate"
      >
        <Plus class="h-4 w-4" /> Tambah Akun
      </button>
    </div>

    <p v-if="notice" class="rounded-xl border border-success/30 bg-success-soft px-3 py-2 text-sm text-[#1c7a4a]" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-xl border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-[#a52b2c]" role="alert">{{ error }}</p>

    <div class="flex flex-wrap gap-3 rounded-card border border-slate-200 bg-white p-4 shadow-card">
      <label class="relative flex-1 min-w-[200px]">
        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input
          v-model="search"
          type="search"
          placeholder="Cari username / NIP / nama"
          class="h-11 w-full rounded-lg border border-slate-300 bg-white pl-9 pr-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/25"
          data-testid="user-search"
        />
      </label>
      <select v-model="roleFilter" class="h-11 rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none transition focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/25">
        <option value="">Semua role</option>
        <option v-for="(label, code) in ROLE_LABELS" :key="code" :value="Number(code)">{{ code }} — {{ label }}</option>
      </select>
      <select v-model="statusFilter" class="h-11 rounded-lg border border-slate-300 bg-white px-3 text-sm outline-none transition focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/25">
        <option value="">Semua status</option>
        <option value="1">Aktif</option>
        <option value="0">Nonaktif</option>
      </select>
    </div>

    <div class="scrollbar-slim overflow-x-auto rounded-card border border-slate-200 bg-white shadow-card">
      <table class="min-w-full text-sm">
        <thead class="border-b border-slate-200 bg-white text-left text-sm font-semibold text-slate-900">
          <tr>
            <th v-for="col in columns" :key="col.key" class="px-4 py-3">
              <button type="button" class="flex items-center gap-1 hover:text-slate-800" @click="toggleSort(col.key)">
                {{ col.label }}
                <ArrowUp v-if="sort === col.key && order === 'asc'" class="h-3.5 w-3.5" />
                <ArrowDown v-else-if="sort === col.key" class="h-3.5 w-3.5" />
                <ArrowUpDown v-else class="h-3.5 w-3.5 opacity-40" />
              </button>
            </th>
            <th class="px-4 py-3">Satker</th>
            <th class="px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <tr v-if="loading">
            <td colspan="8" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="8" class="px-4 py-8 text-center text-slate-500">Tidak ada akun yang cocok.</td>
          </tr>
          <tr v-for="u in items" v-else :key="u.id_pengguna" class="transition-colors hover:bg-slate-50/70" :data-testid="`user-row-${u.id_pengguna}`">
            <td class="px-4 py-3 font-medium text-slate-800">{{ u.username }}</td>
            <td class="px-4 py-3 text-slate-700">{{ u.name ?? '—' }}</td>
            <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ u.nip ?? '—' }}</td>
            <td class="px-4 py-3">{{ u.user_level }} — {{ ROLE_LABELS[u.user_level] }}</td>
            <td class="px-4 py-3">
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-caption font-medium"
                :class="u.status === '1' ? 'bg-success-soft text-[#1c7a4a]' : 'bg-slate-200 text-slate-600'"
              >
                {{ u.status === '1' ? 'Aktif' : 'Nonaktif' }}
              </span>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ formatDate(u.last_login_at) }}</td>
            <td class="px-4 py-3 text-slate-600">{{ u.id_satker ?? '—' }}</td>
            <td class="px-4 py-3">
              <div class="flex justify-end">
                <RowActionsMenu
                  :actions="userActions(u)"
                  :label="`Aksi untuk ${u.username}`"
                  :testid="`user-actions-${u.id_pengguna}`"
                  @select="onUserAction(u, $event)"
                />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600">
      <span>{{ total }} akun · halaman {{ page }} dari {{ totalPages }}</span>
      <div class="flex gap-1">
        <button type="button" class="rounded-lg bg-slate-100 px-3 py-1.5 text-slate-700 transition hover:bg-slate-200 disabled:opacity-40" :disabled="page <= 1" @click="goTo(page - 1)">Sebelumnya</button>
        <button type="button" class="rounded-lg bg-slate-100 px-3 py-1.5 text-slate-700 transition hover:bg-slate-200 disabled:opacity-40" :disabled="page >= totalPages" @click="goTo(page + 1)">Berikutnya</button>
      </div>
    </div>

    <UserFormDialog v-model:open="formOpen" :user="editing" @saved="onSaved" />

    <ConfirmDialog
      v-model:open="confirm.open"
      :title="confirmText.title"
      :description="confirmText.description"
      :danger="confirm.kind === 'delete' || confirm.user?.status === '1'"
      :confirm-label="confirm.kind === 'delete' ? 'Hapus' : confirm.user?.status === '1' ? 'Nonaktifkan' : 'Aktifkan'"
      :loading="confirm.loading"
      @confirm="onConfirm"
    />
  </section>
</template>
