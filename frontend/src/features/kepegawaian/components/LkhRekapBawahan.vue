<script setup lang="ts">
/**
 * Rekap LKH bawahan (MAKE-014; legacy `hr/rwy/lkh/list_rekap` + `rekap/{nip}`; keputusan user 13-10-2026 Q-M6-4):
 * pilih bulan → tabel bawahan langsung dengan jumlah LKH per status; ⋮ "Lihat LKH" membuka daftar LKH bawahan itu pada
 * bulan yang sama (semua status kecuali dihapus). Penentuan bawahan oleh server.
 */
import { ref, watch } from 'vue'

import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import { UiBadge, UiButton, UiNotice, UiTextField } from '@/shared/ui'

import { describeApiError } from '../services/apiErrors'
import { lkhService } from '../services/lkh.service'
import type { LkhRekap, LkhRekapBawahan, LkhRekapItem, StatusLkh } from '../types'

const STATUS: Record<StatusLkh, { label: string; tone: 'warning' | 'success' | 'danger' | 'info' }> = {
  0: { label: 'Menunggu', tone: 'warning' },
  1: { label: 'Disetujui', tone: 'success' },
  2: { label: 'Ditolak', tone: 'danger' },
  3: { label: 'Revisi', tone: 'info' },
}

const bulan = ref('')
const rekap = ref<LkhRekap | null>(null)
const detail = ref<LkhRekapBawahan | null>(null)
const memuat = ref(false)
const galat = ref('')
let token = 0

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  galat.value = ''
  detail.value = null
  try {
    const hasil = await lkhService.rekap(bulan.value || undefined)
    if (saya !== token) return
    rekap.value = hasil
    if (!bulan.value) bulan.value = hasil.bulan
  } catch (e) {
    if (saya !== token) return
    rekap.value = null
    galat.value = describeApiError(e).message
  } finally {
    if (saya === token) memuat.value = false
  }
}

watch(bulan, (baru, lama) => {
  // Bulan bawaan dari server tidak memicu muat kedua.
  if (lama === '' && rekap.value?.bulan === baru) return
  void muat()
})
void muat()

async function lihat(row: LkhRekapItem): Promise<void> {
  galat.value = ''
  try {
    detail.value = await lkhService.rekapBawahan(row.nip, bulan.value || undefined)
  } catch (e) {
    galat.value = describeApiError(e).message
  }
}
</script>

<template>
  <div class="space-y-4" data-testid="lkh-rekap">
    <div class="max-w-xs">
      <UiTextField v-model="bulan" type="month" label="Bulan" data-testid="lkh-rekap-bulan" />
    </div>
    <UiNotice v-if="galat" tone="danger" data-testid="lkh-rekap-error">{{ galat }}</UiNotice>

    <div class="scrollbar-slim overflow-x-auto">
      <table class="w-full min-w-[44rem] border-collapse text-left text-body1" data-testid="lkh-rekap-table">
        <thead>
          <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
            <th scope="col" class="px-4 py-3">Bawahan</th>
            <th scope="col" class="px-4 py-3 text-right">Menunggu</th>
            <th scope="col" class="px-4 py-3 text-right">Revisi</th>
            <th scope="col" class="px-4 py-3 text-right">Disetujui</th>
            <th scope="col" class="px-4 py-3 text-right">Ditolak</th>
            <th scope="col" class="px-4 py-3 text-right">Total</th>
            <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <tr v-if="memuat && !rekap">
            <td colspan="7" class="px-4 py-8 text-center text-body2 text-slate-500">Memuat…</td>
          </tr>
          <tr v-else-if="!rekap || rekap.items.length === 0">
            <td colspan="7" class="px-4 py-10 text-center text-body2 text-slate-500" data-testid="lkh-rekap-empty">Tidak ada bawahan langsung.</td>
          </tr>
          <tr v-for="row in rekap?.items ?? []" v-else :key="row.nip" :data-testid="`lkh-rekap-row-${row.nip}`">
            <td class="px-4 py-3">
              <span class="block font-medium text-slate-900">{{ row.nama }}</span>
              <span class="block text-body2 text-slate-500">{{ row.nip }}<template v-if="row.jabatan"> · {{ row.jabatan }}</template></span>
            </td>
            <td class="px-4 py-3 text-right">{{ row.menunggu }}</td>
            <td class="px-4 py-3 text-right">{{ row.revisi }}</td>
            <td class="px-4 py-3 text-right">{{ row.disetujui }}</td>
            <td class="px-4 py-3 text-right">{{ row.ditolak }}</td>
            <td class="px-4 py-3 text-right font-semibold">{{ row.total }}</td>
            <td class="sticky right-0 bg-white px-4 py-3 text-right">
              <RowActionsMenu
                :actions="[{ key: 'lihat', label: 'Lihat LKH' }]"
                :label="`Aksi untuk rekap ${row.nama}`"
                :testid="`lkh-rekap-actions-${row.nip}`"
                @select="() => lihat(row)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <section v-if="detail" class="rounded-xl border border-slate-200 p-4" data-testid="lkh-rekap-detail">
      <div class="mb-3 flex items-start justify-between gap-3">
        <div>
          <h3 class="text-h6 text-slate-900">LKH {{ detail.pegawai.nama }}</h3>
          <p class="text-body2 text-slate-500">{{ detail.pegawai.nip }} · bulan {{ detail.bulan }}</p>
        </div>
        <UiButton variant="secondary" appearance="soft" @click="detail = null">Tutup</UiButton>
      </div>
      <p v-if="detail.items.length === 0" class="text-body2 text-slate-500">Belum ada LKH pada bulan ini.</p>
      <ul v-else class="divide-y divide-slate-200">
        <li v-for="lkh in detail.items" :key="lkh.id" class="flex flex-wrap items-center justify-between gap-2 py-2" :data-testid="`lkh-rekap-lkh-${lkh.id}`">
          <span class="text-slate-700">{{ lkh.tgl_laporan }} · {{ lkh.jumlah }} kegiatan</span>
          <UiBadge :tone="STATUS[lkh.status].tone">{{ STATUS[lkh.status].label }}</UiBadge>
        </li>
      </ul>
    </section>
  </div>
</template>
