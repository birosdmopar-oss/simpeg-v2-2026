<script setup lang="ts">
/**
 * Halaman Usulan Karpeg/Karis (B-17, WS-1 M4 MAKE-007; WS1.md §3.4.7, keputusan #5): halaman sendiri (bukan tab Detail
 * Pegawai), layout ikut legacy (`hr/rwy/karpeg`, `hr/rwy/kariskarsu`, antrean `list_ad`), style redesign. Dua tab:
 * Kartu Pegawai dan Karis/Karsu (judul pegawai menurut jenis kelamin). Isi tiap tab = mode halaman usulan mandiri
 * (UsulanMandiri): pengusul (role 2/7) melihat usulannya sendiri, role 1/4/5 melihat antrean (role 1 memproses).
 * Pilihan tab tersimpan di `?jenis=`.
 */
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiTabs } from '@/shared/ui'

import { USULAN_KARISKARSU, USULAN_KARPEG } from '../riwayat/usulan.config'
import UsulanMandiri from '../riwayat/UsulanMandiri.vue'

const route = useRoute()
const router = useRouter()

const CONFIGS = [USULAN_KARPEG, USULAN_KARISKARSU]
const items = CONFIGS.map((c) => ({ value: c.jenis, label: c.title }))

const jenis = computed<string>({
  get: () => (CONFIGS.some((c) => c.jenis === route.query.jenis) ? String(route.query.jenis) : USULAN_KARPEG.jenis),
  set: (value) => void router.replace({ query: { ...route.query, jenis: value } }),
})
const config = computed(() => CONFIGS.find((c) => c.jenis === jenis.value) ?? USULAN_KARPEG)

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Usulan Karpeg/Karis' }]
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="space-y-4" data-testid="usulan-karpeg-karis-page">
      <UiTabs v-model="jenis" :items="items" aria-label="Jenis usulan" />
      <UsulanMandiri :key="config.jenis" :config="config" />
    </div>
  </RedesignShell>
</template>
