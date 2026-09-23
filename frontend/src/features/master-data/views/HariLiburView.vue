<script setup lang="ts">
/**
 * G-08 — Master Hari Libur (role 1). Rentang tanggal tidak boleh overlap dan tgl_mulai <= tgl_akhir;
 * keduanya divalidasi backend (422 → field) dan dicek lebih dulu di form (Zod) untuk umpan balik cepat.
 * "Hapus" = soft delete (status '0'), sama dengan master lain.
 */
import { toTypedSchema } from '@vee-validate/zod'
import { CalendarDays, Pencil, Plus, Search, Trash2, X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { useForm } from 'vee-validate'
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import FormField from '@/shared/components/FormField.vue'

import StatusBadge from '../components/StatusBadge.vue'
import StatusSwitch from '../components/StatusSwitch.vue'
import { hariLiburSchema } from '../schemas/master.schema'
import { hariLiburService } from '../services/hari-libur.service'
import { masterService } from '../services/master.service'
import type { HariLibur, MasterOption, MasterStatus } from '../types'

const items = ref<HariLibur[]>([])
const jenisOptions = ref<MasterOption[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const loading = ref(false)
const error = ref('')
const notice = ref('')
const search = ref('')
const statusFilter = ref<MasterStatus | ''>('')
const jenisFilter = ref('')
const tahunFilter = ref('')
const busyId = ref(0)

const formOpen = ref(false)
const editing = ref<HariLibur | null>(null)
const submitting = ref(false)
const formError = ref('')
const confirm = ref<{ open: boolean; row: HariLibur | null; loading: boolean }>({ open: false, row: null, loading: false })

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

const { values, handleSubmit, errors, resetForm, setFieldError, setFieldValue } = useForm({
  validationSchema: toTypedSchema(hariLiburSchema),
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const result = await hariLiburService.list({
      search: search.value.trim(),
      status: statusFilter.value,
      id_jenis_libur: jenisFilter.value,
      tahun: tahunFilter.value,
      page: page.value,
      per_page: perPage.value,
    })
    items.value = result.items
    total.value = result.total
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal memuat hari libur.'
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
watch([statusFilter, jenisFilter, tahunFilter], () => {
  page.value = 1
  void load()
})

function openForm(row: HariLibur | null): void {
  editing.value = row
  formError.value = ''
  resetForm({
    values: {
      id_jenis_libur: row?.id_jenis_libur ?? (jenisOptions.value[0]?.id ?? ''),
      nama: row?.nama ?? '',
      tgl_mulai: row?.tgl_mulai ?? '',
      tgl_akhir: row?.tgl_akhir ?? '',
      keterangan: row?.keterangan ?? '',
    },
  })
  formOpen.value = true
}

const onSubmit = handleSubmit(async (formValues) => {
  submitting.value = true
  formError.value = ''
  try {
    const payload = { ...formValues, keterangan: formValues.keterangan ?? '' }
    const saved = editing.value
      ? await hariLiburService.update(editing.value.id_hari_libur, payload)
      : await hariLiburService.create(payload)
    notice.value = editing.value ? `Hari libur "${saved.nama}" diperbarui.` : `Hari libur "${saved.nama}" ditambahkan.`
    formOpen.value = false
    await load()
  } catch (err) {
    if (isApiError(err) && err.errors) {
      for (const [field, messages] of Object.entries(err.errors)) {
        if (messages[0]) setFieldError(field as 'nama', messages[0])
      }
      formError.value = err.message
    } else {
      formError.value = isApiError(err) ? err.message : 'Terjadi kesalahan. Silakan coba lagi.'
    }
  } finally {
    submitting.value = false
  }
})

async function toggleStatus(row: HariLibur, active: boolean): Promise<void> {
  busyId.value = row.id_hari_libur
  error.value = ''
  try {
    const updated = await hariLiburService.setStatus(row.id_hari_libur, active ? '1' : '0')
    row.status = updated.status
    notice.value = active ? `"${row.nama}" diaktifkan kembali.` : `"${row.nama}" dinonaktifkan; tanggalnya bebas dipakai hari libur lain.`
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal mengubah status.'
  } finally {
    busyId.value = 0
  }
}

async function onConfirmDelete(): Promise<void> {
  const row = confirm.value.row
  if (!row) return
  confirm.value.loading = true
  try {
    await hariLiburService.remove(row.id_hari_libur)
    notice.value = `"${row.nama}" dihapus (dinonaktifkan).`
    confirm.value.open = false
    await load()
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal menghapus.'
    confirm.value.open = false
  } finally {
    confirm.value.loading = false
  }
}

function formatRange(row: HariLibur): string {
  return row.tgl_mulai === row.tgl_akhir ? row.tgl_mulai : `${row.tgl_mulai} s.d. ${row.tgl_akhir}`
}

function jenisLabel(id: string): string {
  return jenisOptions.value.find((o) => o.id === id)?.nama ?? id
}

onMounted(async () => {
  jenisOptions.value = await masterService.options('jenis-libur')
  await load()
})
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-900">Hari Libur</h1>
        <p class="text-sm text-slate-500">Kalender libur nasional & cuti bersama; dipakai perhitungan hari kerja.</p>
      </div>
      <button
        type="button"
        class="flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90"
        data-testid="hari-libur-add"
        @click="openForm(null)"
      >
        <Plus class="h-4 w-4" /> Tambah Hari Libur
      </button>
    </div>

    <p v-if="notice" class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <label class="relative min-w-[200px] flex-1">
        <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
        <input
          v-model="search"
          type="search"
          placeholder="Cari nama / keterangan"
          class="w-full rounded-md border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-brand-tertiary focus:outline-none focus:ring-2 focus:ring-brand-tertiary/40"
        />
      </label>
      <input
        v-model="tahunFilter"
        type="number"
        placeholder="Tahun"
        class="w-28 rounded-md border border-slate-300 px-3 py-2 text-sm"
        aria-label="Filter tahun"
      />
      <select v-model="jenisFilter" class="rounded-md border border-slate-300 px-3 py-2 text-sm" aria-label="Filter jenis libur">
        <option value="">Semua jenis</option>
        <option v-for="o in jenisOptions" :key="o.id" :value="o.id">{{ o.nama }}</option>
      </select>
      <select v-model="statusFilter" class="rounded-md border border-slate-300 px-3 py-2 text-sm" aria-label="Filter status">
        <option value="">Semua status</option>
        <option value="1">Aktif</option>
        <option value="0">Non-aktif</option>
      </select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Tanggal</th>
            <th class="px-4 py-3">Nama</th>
            <th class="px-4 py-3">Jenis</th>
            <th class="px-4 py-3">Keterangan</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="6" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="6" class="px-4 py-8 text-center text-slate-500">Belum ada hari libur yang cocok.</td>
          </tr>
          <tr v-for="row in items" v-else :key="row.id_hari_libur" class="hover:bg-slate-50" :data-testid="`hari-libur-row-${row.id_hari_libur}`">
            <td class="whitespace-nowrap px-4 py-3 text-slate-600">
              <CalendarDays class="mr-1 inline h-4 w-4 text-slate-400" />{{ formatRange(row) }}
            </td>
            <td class="px-4 py-3 font-medium text-slate-800">{{ row.nama }}</td>
            <td class="px-4 py-3 text-slate-600">{{ jenisLabel(row.id_jenis_libur) }}</td>
            <td class="px-4 py-3 text-slate-500">{{ row.keterangan ?? '—' }}</td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <StatusSwitch
                  :checked="row.status === '1'"
                  :disabled="busyId === row.id_hari_libur"
                  :label="`Status ${row.nama}`"
                  @toggle="toggleStatus(row, $event)"
                />
                <StatusBadge :status="row.status" />
              </div>
            </td>
            <td class="px-4 py-3">
              <div class="flex justify-end gap-1">
                <button type="button" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-primary" title="Edit" @click="openForm(row)">
                  <Pencil class="h-4 w-4" />
                </button>
                <button
                  type="button"
                  class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-red-600 disabled:opacity-40"
                  title="Hapus"
                  :disabled="row.status === '0'"
                  @click="confirm = { open: true, row, loading: false }"
                >
                  <Trash2 class="h-4 w-4" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600">
      <span>{{ total }} hari libur · halaman {{ page }} dari {{ totalPages }}</span>
      <div class="flex gap-1">
        <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40" :disabled="page <= 1" @click="((page = page - 1), load())">
          Sebelumnya
        </button>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40"
          :disabled="page >= totalPages"
          @click="((page = page + 1), load())"
        >
          Berikutnya
        </button>
      </div>
    </div>

    <DialogRoot :open="formOpen" @update:open="formOpen = $event">
      <DialogPortal>
        <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
        <DialogContent
          class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-lg bg-white p-6 shadow-xl focus:outline-none"
        >
          <div class="mb-4 flex items-start justify-between">
            <div>
              <DialogTitle class="text-lg font-semibold text-slate-900">{{ editing ? 'Edit Hari Libur' : 'Tambah Hari Libur' }}</DialogTitle>
              <DialogDescription class="text-sm text-slate-500">Rentang tanggal tidak boleh bertabrakan dengan hari libur aktif lain.</DialogDescription>
            </div>
            <DialogClose class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
              <X class="h-5 w-5" />
            </DialogClose>
          </div>

          <form class="space-y-4" novalidate data-testid="hari-libur-form" @submit="onSubmit">
            <p v-if="formError" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>

            <FormField
              :model-value="values.id_jenis_libur"
              name="id_jenis_libur"
              label="Jenis Hari Libur"
              type="select"
              required
              :options="jenisOptions.map((o) => ({ value: o.id, label: o.nama }))"
              :error="errors.id_jenis_libur"
              @update:model-value="setFieldValue('id_jenis_libur', $event)"
            />
            <FormField
              :model-value="values.nama"
              name="nama"
              label="Nama Hari Libur"
              required
              :error="errors.nama"
              @update:model-value="setFieldValue('nama', $event)"
            />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField
                :model-value="values.tgl_mulai"
                name="tgl_mulai"
                label="Tanggal Mulai"
                type="date"
                required
                :error="errors.tgl_mulai"
                @update:model-value="setFieldValue('tgl_mulai', $event)"
              />
              <FormField
                :model-value="values.tgl_akhir"
                name="tgl_akhir"
                label="Tanggal Akhir"
                type="date"
                required
                :error="errors.tgl_akhir"
                @update:model-value="setFieldValue('tgl_akhir', $event)"
              />
            </div>
            <FormField
              :model-value="values.keterangan"
              name="keterangan"
              label="Keterangan"
              type="textarea"
              :error="errors.keterangan"
              @update:model-value="setFieldValue('keterangan', $event)"
            />

            <div class="flex justify-end gap-2 pt-2">
              <DialogClose class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Batal</DialogClose>
              <button
                type="submit"
                class="rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90 disabled:opacity-60"
                :disabled="submitting"
              >
                {{ submitting ? 'Menyimpan...' : 'Simpan' }}
              </button>
            </div>
          </form>
        </DialogContent>
      </DialogPortal>
    </DialogRoot>

    <ConfirmDialog
      v-model:open="confirm.open"
      :title="`Hapus hari libur &quot;${confirm.row?.nama ?? ''}&quot;?`"
      description="Data tidak dihapus permanen: statusnya menjadi Non-aktif sehingga tidak lagi diperhitungkan sebagai hari libur, dan tanggalnya bisa dipakai entri lain."
      danger
      confirm-label="Hapus"
      :loading="confirm.loading"
      @confirm="onConfirmDelete"
    />
  </section>
</template>
