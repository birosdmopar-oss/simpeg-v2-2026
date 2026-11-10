<script setup lang="ts">
/**
 * Tab "Histori Approval" (legacy `hr/notification/ah`): data yang sudah diproses pengguna pada bulan terpilih
 * (12 bulan terakhir, dari server). Aksi ⋮ "Buka" membuka halaman sumber.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { isApiError } from '@/lib/axios'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiBadge, UiNotice, UiPagination, UiSelect, type SelectOption } from '@/shared/ui'
import { formatApiDateTime } from '@/shared/utils/dateTime'

import { notifikasiService } from '../services/notifikasi.service'
import type { HistoriApproval, ItemApproval } from '../types'

const PER_PAGE = 20
const AKSI: RowAction[] = [{ key: 'buka', label: 'Buka' }]
const NAMA_BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']

const router = useRouter()
const data = ref<HistoriApproval | null>(null)
const bulan = ref('')
const page = ref(1)
const memuat = ref(false)
const galat = ref('')

const opsiBulan = computed<SelectOption[]>(() =>
  (data.value?.pilihan_bulan ?? []).map((b) => {
    const [t, m] = b.split('-')
    return { value: b, label: `${NAMA_BULAN[Number(m) - 1] ?? m} ${t}` }
  }),
)

async function muat(): Promise<void> {
  memuat.value = true
  galat.value = ''
  try {
    data.value = await notifikasiService.historiApproval({ bulan: bulan.value || undefined, page: page.value, per_page: PER_PAGE })
    if (!bulan.value) bulan.value = data.value.bulan
  } catch (e) {
    galat.value = isApiError(e) && e.message ? e.message : 'Histori approval gagal dimuat.'
  } finally {
    memuat.value = false
  }
}

watch(bulan, (_baru, lama) => {
  if (lama === '') return
  page.value = 1
  void muat()
})
watch(page, () => void muat())
onMounted(() => void muat())

function onAction(key: string, row: ItemApproval): void {
  if (key === 'buka' && row.url_fe) void router.push(row.url_fe)
}

function toneStatus(label: string | null): 'success' | 'danger' | 'warning' | 'neutral' {
  if (label === 'Disetujui') return 'success'
  if (label === 'Ditolak') return 'danger'
  if (label === 'Revisi') return 'warning'
  return 'neutral'
}
</script>

<template>
  <div data-testid="notifikasi-histori">
    <div class="mb-4 w-full max-w-xs">
      <UiSelect v-model="bulan" label="Bulan" :options="opsiBulan" data-testid="histori-bulan" />
    </div>

    <UiNotice v-if="galat" tone="danger" class="mb-4">{{ galat }}</UiNotice>

    <div class="scrollbar-slim overflow-x-auto">
      <table class="w-full min-w-[44rem] border-collapse text-left text-body1" data-testid="histori-table">
        <thead>
          <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
            <th scope="col" class="px-4 py-3">Waktu</th>
            <th scope="col" class="px-4 py-3">Jenis</th>
            <th scope="col" class="px-4 py-3">Pegawai</th>
            <th scope="col" class="px-4 py-3">Status</th>
            <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <tr v-if="memuat && !data">
            <td colspan="5" class="px-4 py-8 text-center text-body2 text-slate-500">Memuat…</td>
          </tr>
          <tr v-else-if="!data || data.items.length === 0">
            <td colspan="5" class="px-4 py-10 text-center text-body2 text-slate-500" data-testid="histori-empty">
              Belum ada data yang Anda proses pada bulan ini.
            </td>
          </tr>
          <tr v-for="row in data?.items ?? []" v-else :key="row.id" :data-testid="`histori-row-${row.id}`">
            <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ formatApiDateTime(row.waktu) ?? '—' }}</td>
            <td class="px-4 py-3 text-slate-900">{{ row.judul }}</td>
            <td class="px-4 py-3">
              <span class="block font-medium text-slate-900">{{ row.nama ?? '—' }}</span>
              <span class="block text-body2 text-slate-500">{{ row.nip }}</span>
            </td>
            <td class="px-4 py-3">
              <UiBadge v-if="row.status_label" :tone="toneStatus(row.status_label)" size="sm">{{ row.status_label }}</UiBadge>
              <span v-else class="text-slate-500">—</span>
            </td>
            <td class="sticky right-0 bg-white px-4 py-3 text-right">
              <RowActionsMenu
                :actions="AKSI"
                :label="`Aksi untuk ${row.judul} ${row.nama ?? row.nip ?? ''}`"
                :testid="`histori-actions-${row.id.replace(':', '-')}`"
                @select="(key) => onAction(key, row)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <UiPagination v-if="data && data.total > PER_PAGE" v-model:page="page" class="mt-4" :per-page="PER_PAGE" :total="data.total" />
  </div>
</template>
