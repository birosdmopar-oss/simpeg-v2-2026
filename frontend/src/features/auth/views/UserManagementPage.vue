<script setup lang="ts">
// Pembungkus route /akun: UserManagementView di dalam RedesignShell.
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import UserManagementView from './UserManagementView.vue'
</script>

<template>
  <RedesignShell>
    <UserManagementView />
  </RedesignShell>
</template>
