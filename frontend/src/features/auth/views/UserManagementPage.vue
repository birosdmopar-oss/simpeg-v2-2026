<script setup lang="ts">
// Pembungkus route /akun: UserManagementView di dalam AppShell.
import AppShell from '@/shared/components/AppShell.vue'

import UserManagementView from './UserManagementView.vue'
</script>

<template>
  <AppShell>
    <UserManagementView />
  </AppShell>
</template>
