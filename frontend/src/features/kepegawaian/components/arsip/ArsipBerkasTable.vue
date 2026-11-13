<script setup lang="ts">
/**
 * Tabel berkas satu folder / "Semua berkas" (F-10, MAKE-091 Tahap 1 + MAKE-096 Tahap 2, PRD §9.2–§9.3): Nama Arsip | Jenis Riwayat
 * (hanya di "Semua berkas") | Data Riwayat | Status Berkas (badge) | Aksi ⋮ (sticky di kanan; tabel menggulir di dalam kartu, bukan
 * halaman — AC1-15). Aksi baris lewat `RowActionsMenu` (AGENTS.md §1), urutan Edit → Buka → Hubungkan → Hapus (`danger`):
 * Edit/Hubungkan/Hapus `hidden` bila `aksi.*` item false atau `bacaSaja` (role 4/5), dan `disabled` selama ada aksi tulis yang
 * berjalan (`memproses`); Buka `disabled` bila `url_berkas` null atau berkas privat sedang dimuat. Cara buka mengikuti
 * `akses_berkas` item (`useBukaBerkasArsip`); galatnya tampil sebagai `UiNotice` di atas tabel. Aksi tulis dipancarkan ke
 * halaman (dialog / konfirmasi). Semua teks diinterpolasi (tanpa `v-html`).
 */
import { FileSearch } from 'lucide-vue-next'
import { computed, watch } from 'vue'

import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiBadge, UiNotice, UiTooltip } from '@/shared/ui'

import type { ArsipBerkas } from '../../arsip.types'
import { useBukaBerkasArsip } from '../../composables/useBukaBerkasArsip'

const props = defineProps<{
  items: ArsipBerkas[]
  loading: boolean
  /** Kolom "Jenis Riwayat" hanya di tampilan "Semua berkas". */
  tampilJenis: boolean
  /** Ada kata pencarian aktif: keadaan kosong berarti "tidak cocok", bukan "belum ada berkas". */
  mencari?: boolean
  /** Role hanya-lihat (4/5): semua aksi tulis disembunyikan walau server mengirim `aksi.*`. */
  bacaSaja?: boolean
  /** Ada aksi tulis yang sedang berjalan: Edit / Hubungkan / Hapus `disabled`. */
  memproses?: boolean
}>()

const emit = defineEmits<{ edit: [item: ArsipBerkas]; hubungkan: [item: ArsipBerkas]; hapus: [item: ArsipBerkas] }>()

const { galat, bisaDibuka, buka } = useBukaBerkasArsip()

// Pesan galat Buka milik daftar yang sedang tampil: dibuang saat daftar (halaman / pencarian / folder) berganti.
watch(
  () => props.items,
  () => {
    galat.value = null
  },
)

const colspan = computed(() => (props.tampilJenis ? 5 : 4))

function aksiBaris(item: ArsipBerkas): RowAction[] {
  const tulis = props.bacaSaja ? { edit: false, hubungkan: false, hapus: false } : item.aksi
  return [
    { key: 'edit', label: 'Edit', hidden: !tulis.edit, disabled: props.memproses },
    { key: 'buka', label: 'Buka', disabled: !bisaDibuka(item) },
    { key: 'hubungkan', label: 'Hubungkan', hidden: !tulis.hubungkan, disabled: props.memproses },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !tulis.hapus, disabled: props.memproses },
  ]
}

function onAksi(key: string, item: ArsipBerkas): void {
  if (key === 'buka') buka(item)
  else if (key === 'edit') emit('edit', item)
  else if (key === 'hubungkan') emit('hubungkan', item)
  else if (key === 'hapus') emit('hapus', item)
}
</script>

<template>
  <div class="space-y-3">
    <UiNotice v-if="galat" tone="danger" dismissible data-testid="arsip-buka-galat" @dismiss="galat = null">{{ galat }}</UiNotice>

    <div class="scrollbar-slim overflow-x-auto rounded-lg border border-slate-200">
      <table class="w-full min-w-[40rem] border-collapse text-left text-body2" data-testid="arsip-berkas-table">
        <thead>
          <tr class="border-b border-slate-200 font-semibold text-slate-900">
            <th scope="col" class="px-4 py-2.5">Nama Arsip</th>
            <th v-if="tampilJenis" scope="col" class="px-4 py-2.5">Jenis Riwayat</th>
            <th scope="col" class="px-4 py-2.5">Data Riwayat</th>
            <th scope="col" class="px-4 py-2.5">Status Berkas</th>
            <th scope="col" class="sticky right-0 bg-white px-4 py-2.5 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <template v-if="loading">
            <tr v-for="n in 3" :key="`s-${n}`" aria-hidden="true">
              <td :colspan="colspan" class="px-4 py-3"><div class="h-4 w-2/3 animate-pulse rounded bg-slate-200" /></td>
            </tr>
          </template>
          <tr v-else-if="items.length === 0">
            <td :colspan="colspan" class="px-4 py-8 text-center" data-testid="arsip-berkas-kosong">
              <FileSearch class="mx-auto mb-2 h-6 w-6 text-slate-300" aria-hidden="true" />
              <p class="text-slate-600">{{ mencari ? 'Tidak ada berkas yang cocok dengan pencarian.' : 'Belum ada berkas.' }}</p>
            </td>
          </tr>
          <template v-else>
            <tr v-for="item in items" :key="item.id_attachment" :data-testid="`arsip-berkas-row-${item.id_attachment}`">
              <td class="max-w-72 px-4 py-2.5 text-slate-800">
                <UiTooltip :content="item.nama_arsip"><span class="block truncate">{{ item.nama_arsip }}</span></UiTooltip>
              </td>
              <td v-if="tampilJenis" class="px-4 py-2.5 text-slate-700">{{ item.jenis_riwayat }}</td>
              <td class="max-w-72 px-4 py-2.5 text-slate-600">
                <UiTooltip :content="item.ringkasan_riwayat"><span class="block truncate">{{ item.ringkasan_riwayat }}</span></UiTooltip>
              </td>
              <td class="px-4 py-2.5">
                <UiBadge v-if="item.status_berkas === 'hilang'" tone="danger" :data-testid="`arsip-badge-hilang-${item.id_attachment}`">Berkas hilang</UiBadge>
                <UiBadge v-else-if="item.status_berkas === 'tak_dapat_dibuka'" tone="warning" :data-testid="`arsip-badge-tak-dapat-dibuka-${item.id_attachment}`">
                  Tidak dapat dibuka
                </UiBadge>
                <span v-else class="text-slate-400">—</span>
              </td>
              <td class="sticky right-0 bg-white px-4 py-2.5 text-right">
                <RowActionsMenu
                  :actions="aksiBaris(item)"
                  :label="`Aksi untuk ${item.nama_arsip}`"
                  :testid="`arsip-actions-${item.id_attachment}`"
                  @select="(key) => onAksi(key, item)"
                />
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>
</template>
