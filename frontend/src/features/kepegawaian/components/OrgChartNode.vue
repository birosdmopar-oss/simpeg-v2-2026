<script setup lang="ts">
/**
 * Satu kotak bagan struktur organisasi (B-19; gaya redesign commit 3420e1a §4.1.2, hierarki templat legacy so_012025):
 * jabatan + teks pejabat legacy ("Nama, Gol, NIP"; awalan Plt./Plh.). Kotak lowong = "Lowong" (legacy kelas `empty`),
 * Plt/Plh = garis oranye + lencana (legacy kelas `plt`), kotak JF = garis putus-putus. Rekursif; garis penghubung
 * murni CSS (`.org-tree`).
 */
import { UiBadge } from '@/shared/ui'

import type { OrgNode } from '../types'

defineProps<{ node: OrgNode }>()
</script>

<template>
  <li>
    <article
      class="org-card relative w-60 rounded-xl border bg-white px-4 pb-3 pt-3 text-left shadow-card"
      :class="[
        node.jf ? 'border-dashed border-slate-300 text-center' : 'border-slate-200 border-t-4',
        !node.jf && (node.jenis === 'plt' || node.jenis === 'plh') ? 'border-t-amber-500' : '',
        !node.jf && node.jenis !== 'plt' && node.jenis !== 'plh' ? 'border-t-brand-primary' : '',
      ]"
      :data-testid="`org-node-${node.id}`"
    >
      <p class="text-[0.6875rem] font-semibold leading-4 text-slate-800" :class="node.jf ? 'italic text-slate-500' : ''">
        {{ node.jabatan }}
      </p>
      <p v-if="node.sub" class="mt-1 text-caption text-slate-500">{{ node.sub }}</p>
      <template v-if="!node.jf">
        <span class="my-2 block h-0.5 w-12 rounded-full bg-gradient-to-r from-brand-primary to-brand-secondary" aria-hidden="true" />
        <p v-if="node.pejabat" class="flex items-start gap-2 text-body2 text-slate-900">
          <span class="min-w-0 break-words">{{ node.pejabat }}</span>
          <UiBadge v-if="node.jenis === 'plt' || node.jenis === 'plh'" tone="warning" :data-testid="`org-node-${node.id}-jenis`">
            {{ node.jenis === 'plt' ? 'Plt.' : 'Plh.' }}
          </UiBadge>
        </p>
        <p v-else class="text-body2 italic text-slate-400" :data-testid="`org-node-${node.id}-lowong`">Lowong</p>
      </template>
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
