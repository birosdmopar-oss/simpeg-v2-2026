<script setup lang="ts">
/**
 * Tab "Butuh Approval" (legacy `hr/notification/need_approval`): ringkasan antrean per modul dengan tautan ke halaman
 * antrean modulnya, plus daftar riwayat lintas pegawai yang menunggu proses (role 1/3 — v2 tidak punya halaman antrean
 * riwayat lain). Proses tetap di halaman sumber (aksi ⋮ "Proses").
 */
import { ArrowRight } from 'lucide-vue-next'
import { onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import { isApiError } from '@/lib/axios'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiBadge, UiNotice, UiPagination } from '@/shared/ui'
import { formatApiDateTime } from '@/shared/utils/dateTime'

import { notifikasiService } from '../services/notifikasi.service'
import type { ButuhApproval, ItemApproval } from '../types'

const PER_PAGE = 20
const AKSI: RowAction[] = [{ key: 'proses', label: 'Proses' }]

const router = useRouter()
const data = ref<ButuhApproval | null>(null)
const page = ref(1)
const memuat = ref(false)
const galat = ref('')

async function muat(): Promise<void> {
  memuat.value = true
  galat.value = ''
  try {
    data.value = await notifikasiService.butuhApproval({ page: page.value, per_page: PER_PAGE })
  } catch (e) {
    galat.value = isApiError(e) && e.message ? e.message : 'Antrean approval gagal dimuat.'
  } finally {
    memuat.value = false
  }
}

watch(page, () => void muat())
onMounted(() => void muat())

function onAction(key: string, row: ItemApproval): void {
  if (key === 'proses' && row.url_fe) void router.push(row.url_fe)
}
</script>

<template>
  <div data-testid="notifikasi-butuh-approval">
    <UiNotice v-if="galat" tone="danger" class="mb-4">{{ galat }}</UiNotice>

    <p v-if="memuat && !data" class="py-8 text-center text-body2 text-slate-500">Memuat…</p>
    <p v-else-if="data && data.grup.length === 0" class="py-10 text-center text-body2 text-slate-500" data-testid="butuh-approval-empty">
      Tidak ada data yang menunggu persetujuan Anda.
    </p>

    <ul v-if="data && data.grup.length > 0" class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="butuh-approval-grup">
      <li v-for="g in data.grup" :key="g.kode">
        <RouterLink
          v-if="g.url_fe"
          :to="g.url_fe"
          class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3 transition hover:border-brand-primary hover:bg-brand-primary/5"
          :data-testid="`butuh-approval-grup-${g.kode}`"
        >
          <span class="text-body1 font-medium text-slate-900">{{ g.label }}</span>
          <span class="flex items-center gap-2">
            <UiBadge tone="warning">{{ g.jumlah }}</UiBadge>
            <ArrowRight class="h-4 w-4 text-slate-500" aria-hidden="true" />
          </span>
        </RouterLink>
      </li>
    </ul>

    <template v-if="data?.riwayat && data.riwayat.total > 0">
      <h3 class="mb-3 text-body1 font-semibold text-slate-900">Riwayat pegawai menunggu proses</h3>
      <div class="scrollbar-slim overflow-x-auto">
        <table class="w-full min-w-[44rem] border-collapse text-left text-body1" data-testid="butuh-approval-riwayat">
          <thead>
            <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
              <th scope="col" class="px-4 py-3">Diperbarui</th>
              <th scope="col" class="px-4 py-3">Jenis</th>
              <th scope="col" class="px-4 py-3">Pegawai</th>
              <th scope="col" class="px-4 py-3">Status</th>
              <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            <tr v-for="row in data.riwayat.items" :key="row.id" :data-testid="`butuh-approval-row-${row.id}`">
              <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ formatApiDateTime(row.waktu) ?? '—' }}</td>
              <td class="px-4 py-3 text-slate-900">{{ row.judul }}</td>
              <td class="px-4 py-3">
                <span class="block font-medium text-slate-900">{{ row.nama ?? '—' }}</span>
                <span class="block text-body2 text-slate-500">{{ row.nip }}</span>
              </td>
              <td class="px-4 py-3"><UiBadge tone="warning" size="sm">{{ row.status_label ?? 'Menunggu' }}</UiBadge></td>
              <td class="sticky right-0 bg-white px-4 py-3 text-right">
                <RowActionsMenu
                  :actions="AKSI"
                  :label="`Aksi untuk ${row.judul} ${row.nama ?? row.nip ?? ''}`"
                  :testid="`butuh-approval-actions-${row.id.replace(':', '-')}`"
                  @select="(key) => onAction(key, row)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <UiPagination v-if="data.riwayat.total > PER_PAGE" v-model:page="page" class="mt-4" :per-page="PER_PAGE" :total="data.riwayat.total" />
    </template>
  </div>
</template>
