<script setup lang="ts">
// Pembungkus route /ganti-password: ChangePasswordView di dalam AppShell.
import AppShell from '@/shared/components/AppShell.vue'

import ChangePasswordView from './ChangePasswordView.vue'
</script>

<template>
  <AppShell>
    <ChangePasswordView />
  </AppShell>
</template>
