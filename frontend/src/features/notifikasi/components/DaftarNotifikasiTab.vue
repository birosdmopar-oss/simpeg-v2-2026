<script setup lang="ts">
/**
 * Tab "Notifikasi" (legacy `hr/notification/index`): daftar gabungan milik pengguna, terbaru dulu.
 * Aksi baris ⋮: Buka → Tandai dibaca → Hapus (ConfirmDialog). "Buka" menandai dibaca lalu membuka halaman sumber
 * (Q7-31: dibaca saat item dibuka). Hapus hanya untuk notifikasi riwayat (`dapat_dihapus`); layanan tanpa hapus (Q7-33).
 */
import { CheckCheck } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiBadge, UiButton, UiNotice, UiPagination, UiSelect, type SelectOption } from '@/shared/ui'
import { formatApiDateTime } from '@/shared/utils/dateTime'

import { useRingkasanNotifikasi } from '../composables/useRingkasanNotifikasi'
import { notifikasiService } from '../services/notifikasi.service'
import type { DaftarNotifikasi, ItemNotifikasi, StatusDaftar } from '../types'

const props = defineProps<{ bolehHapus: boolean }>()

const PER_PAGE = 20
const STATUS_OPSI: SelectOption[] = [
  { value: 'semua', label: 'Semua' },
  { value: 'belum', label: 'Belum dibaca' },
]

const router = useRouter()
const { muatUlang } = useRingkasanNotifikasi()

const data = ref<DaftarNotifikasi | null>(null)
const status = ref<StatusDaftar>('semua')
const page = ref(1)
const memuat = ref(false)
const notice = ref<{ tone: 'danger' | 'success'; text: string } | null>(null)
const hapusRow = ref<ItemNotifikasi | null>(null)
const hapusBusy = ref(false)

const adaBelum = computed(() => (data.value?.belum_dibaca ?? 0) > 0)

function pesan(e: unknown, fallback: string): string {
  return isApiError(e) && e.message ? e.message : fallback
}

async function muat(): Promise<void> {
  memuat.value = true
  try {
    data.value = await notifikasiService.daftar({ status: status.value, page: page.value, per_page: PER_PAGE })
  } catch (e) {
    notice.value = { tone: 'danger', text: pesan(e, 'Notifikasi gagal dimuat.') }
  } finally {
    memuat.value = false
  }
}

watch(status, () => {
  page.value = 1
  void muat()
})
watch(page, () => void muat())
onMounted(() => void muat())

function waktu(row: ItemNotifikasi): string {
  return formatApiDateTime(row.waktu) ?? '—'
}

function toneStatus(label: string | null): 'success' | 'danger' | 'warning' | 'info' | 'neutral' {
  switch (label) {
    case 'Disetujui':
      return 'success'
    case 'Ditolak':
      return 'danger'
    case 'Revisi':
    case 'Menunggu':
    case 'Diproses':
      return 'warning'
    case null:
      return 'neutral'
    default:
      return 'info'
  }
}

function actionsFor(row: ItemNotifikasi): RowAction[] {
  return [
    { key: 'buka', label: 'Buka' },
    { key: 'baca', label: 'Tandai dibaca', hidden: row.dibaca },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !props.bolehHapus || !row.dapat_dihapus },
  ]
}

async function tandai(ids: string[] | 'semua'): Promise<void> {
  try {
    await notifikasiService.baca(ids === 'semua' ? { semua: true } : { ids })
    await Promise.all([muat(), muatUlang()])
  } catch (e) {
    notice.value = { tone: 'danger', text: pesan(e, 'Notifikasi gagal ditandai dibaca.') }
  }
}

async function buka(row: ItemNotifikasi): Promise<void> {
  if (!row.dibaca) {
    try {
      await notifikasiService.baca({ ids: [row.id] })
      void muatUlang()
    } catch {
      // Tetap buka halaman sumber walau penanda baca gagal.
    }
  }
  if (row.url_fe) await router.push(row.url_fe)
}

function onAction(key: string, row: ItemNotifikasi): void {
  if (key === 'buka') void buka(row)
  else if (key === 'baca') void tandai([row.id])
  else if (key === 'hapus') hapusRow.value = row
}

async function hapus(): Promise<void> {
  const row = hapusRow.value
  if (!row) return
  hapusBusy.value = true
  try {
    await notifikasiService.hapus(row.id)
    notice.value = { tone: 'success', text: 'Notifikasi dihapus.' }
    hapusRow.value = null
    await Promise.all([muat(), muatUlang()])
  } catch (e) {
    notice.value = { tone: 'danger', text: pesan(e, 'Notifikasi gagal dihapus.') }
    hapusRow.value = null
  } finally {
    hapusBusy.value = false
  }
}
</script>

<template>
  <div data-testid="notifikasi-daftar">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div class="w-full max-w-xs">
        <UiSelect v-model="status" label="Tampilkan" :options="STATUS_OPSI" data-testid="notifikasi-filter-status" />
      </div>
      <UiButton variant="secondary" :disabled="!adaBelum" data-testid="notifikasi-baca-semua" @click="tandai('semua')">
        <CheckCheck class="h-4 w-4" aria-hidden="true" /> Tandai semua dibaca
      </UiButton>
    </div>

    <UiNotice v-if="notice" :tone="notice.tone" class="mb-4" data-testid="notifikasi-notice">{{ notice.text }}</UiNotice>

    <div class="scrollbar-slim overflow-x-auto">
      <table class="w-full min-w-[44rem] border-collapse text-left text-body1" data-testid="notifikasi-table">
        <thead>
          <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
            <th scope="col" class="px-4 py-3">Waktu</th>
            <th scope="col" class="px-4 py-3">Jenis</th>
            <th scope="col" class="px-4 py-3">Keterangan</th>
            <th scope="col" class="px-4 py-3">Status</th>
            <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <tr v-if="memuat && !data">
            <td colspan="5" class="px-4 py-8 text-center text-body2 text-slate-500">Memuat…</td>
          </tr>
          <tr v-else-if="!data || data.items.length === 0">
            <td colspan="5" class="px-4 py-10 text-center text-body2 text-slate-500" data-testid="notifikasi-empty">
              {{ status === 'belum' ? 'Tidak ada notifikasi yang belum dibaca.' : 'Belum ada notifikasi.' }}
            </td>
          </tr>
          <tr
            v-for="row in data?.items ?? []"
            v-else
            :key="row.id"
            :class="row.dibaca ? '' : 'bg-brand-primary/5'"
            :data-testid="`notifikasi-row-${row.id}`"
          >
            <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ waktu(row) }}</td>
            <td class="px-4 py-3" :class="row.dibaca ? 'text-slate-700' : 'font-semibold text-slate-900'">{{ row.judul }}</td>
            <td class="max-w-md px-4 py-3 text-slate-700">
              <span class="line-clamp-2">{{ row.keterangan }}</span>
              <span v-if="row.reason_note" class="block text-caption text-slate-500">Catatan: {{ row.reason_note }}</span>
            </td>
            <td class="px-4 py-3">
              <div class="flex flex-wrap gap-1">
                <UiBadge v-if="row.status_label" :tone="toneStatus(row.status_label)" size="sm">{{ row.status_label }}</UiBadge>
                <UiBadge :tone="row.dibaca ? 'neutral' : 'primary'" size="sm">{{ row.dibaca ? 'Dibaca' : 'Belum Dibaca' }}</UiBadge>
              </div>
            </td>
            <td class="sticky right-0 bg-white px-4 py-3 text-right">
              <RowActionsMenu
                :actions="actionsFor(row)"
                :label="`Aksi untuk ${row.judul}`"
                :testid="`notifikasi-actions-${row.id.replace(':', '-')}`"
                @select="(key) => onAction(key, row)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <UiPagination v-if="data && data.total > PER_PAGE" v-model:page="page" class="mt-4" :per-page="PER_PAGE" :total="data.total" />

    <ConfirmDialog
      :open="hapusRow !== null"
      title="Hapus notifikasi?"
      :description="hapusRow ? `Notifikasi ${hapusRow.judul} dihapus dari daftar Anda. Data riwayatnya tidak berubah.` : ''"
      confirm-label="Ya, hapus"
      danger
      :loading="hapusBusy"
      @update:open="(v) => (hapusRow = v ? hapusRow : null)"
      @confirm="hapus"
    />
  </div>
</template>
