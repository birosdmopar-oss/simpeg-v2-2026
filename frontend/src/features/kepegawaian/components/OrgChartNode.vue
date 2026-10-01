<script setup lang="ts">
/**
 * Satu simpul bagan struktur organisasi (§4.1.2, Gambar 13): kartu putih bergaris navy di atas, avatar bulat menempel
 * di sudut kiri-atas, jabatan (kecil, tebal, bergaris navy→kuning) di kanan, nama besar, nama lengkap abu-abu.
 * Komponen rekursif — memanggil dirinya sendiri untuk anak-anaknya. Garis penghubung dibuat murni CSS
 * (kelas `.org-tree`, lihat <style>) sehingga tidak butuh library bagan.
 *
 * Penyimpangan dari mockup: tata letak pohon atas→bawah (bukan tulang punggung tengah dengan kartu kiri/kanan) dan
 * tanpa garis putus-putus oranye untuk relasi koordinatif — dokumen menyebut "plugin Organization Chart" yang
 * belum dipilih. Menunggu keputusan.
 * ASET SEMENTARA: avatar memakai placeholder dari registry.
 */
import { UiAvatar } from '@/shared/ui'
import { placeholderAvatar } from '@/shared/ui/placeholderAssets'

import type { OrgNode } from '../types'

defineProps<{ node: OrgNode }>()
</script>

<template>
  <li>
    <article
      class="org-card relative w-60 rounded-xl border border-slate-200 border-t-4 border-t-brand-primary bg-white px-4 pb-3 pt-4 text-left shadow-card"
      :data-testid="`org-node-${node.id}`"
    >
      <div class="flex items-start gap-3">
        <UiAvatar :name="node.nama" :src="placeholderAvatar(node.foto_seed)" size="lg" alt="" class="shrink-0" />
        <p class="min-w-0 flex-1 text-right text-[0.6875rem] font-semibold leading-4 text-slate-800">
          {{ node.jabatan }}
          <span class="mt-1 ml-auto block h-0.5 w-12 rounded-full bg-gradient-to-r from-brand-primary to-brand-secondary" aria-hidden="true" />
        </p>
      </div>
      <p class="mt-3 truncate text-h6 text-slate-900">{{ node.nama }}</p>
      <p class="truncate text-caption text-slate-400">{{ node.nama_lengkap }}</p>
    </article>

    <ul v-if="node.children.length > 0">
      <OrgChartNode v-for="child in node.children" :key="child.id" :node="child" />
    </ul>
  </li>
</template>

<style>
/* Garis penghubung pohon: setiap <li> menggambar sambungan ke induknya (kiri/kanan) dan turun ke anaknya. */
.org-tree ul {
  position: relative;
  display: flex;
  justify-content: center;
  padding-top: 1.5rem;
}
.org-tree li {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.5rem 0.75rem 0;
  list-style: none;
}
.org-tree li::before,
.org-tree li::after {
  content: '';
  position: absolute;
  top: 0;
  right: 50%;
  width: 50%;
  height: 1.5rem;
  border-top: 2px solid #1c3964;
}
.org-tree li::after {
  right: auto;
  left: 50%;
  border-left: 2px solid #1c3964;
}
.org-tree li:only-child::before,
.org-tree li:only-child::after {
  display: none;
}
.org-tree li:only-child {
  padding-top: 0;
}
.org-tree li:first-child::before,
.org-tree li:last-child::after {
  border: 0 none;
}
.org-tree li:last-child::before {
  border-right: 2px solid #1c3964;
  border-radius: 0 0.75rem 0 0;
}
.org-tree li:first-child::after {
  border-radius: 0.75rem 0 0 0;
}
.org-tree ul ul::before {
  content: '';
  position: absolute;
  top: 0;
  left: 50%;
  height: 1.5rem;
  border-left: 2px solid #1c3964;
}
/* Simpul akar tidak punya sambungan ke atas. */
.org-tree > li {
  padding-top: 0;
}
.org-tree > li::before,
.org-tree > li::after {
  display: none;
}
.org-tree > li > ul {
  padding-top: 1.5rem;
}
</style>
