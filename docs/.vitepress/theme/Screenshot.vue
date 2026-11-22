<script setup lang="ts">
import { computed } from 'vue'
import { useData, withBase } from 'vitepress'

// Browser capture specs generate both themes from disposable panel, Fleet, and Grafana fixtures.
const props = defineProps<{ name: string; alt: string }>()
const { isDark } = useData()
const source = computed(() => withBase(`/screenshots/${isDark.value ? 'dark' : 'light'}/${props.name}.png`))
</script>

<template>
  <figure class="cdnf-shot">
    <img :src="source" :alt="alt" width="1440" height="900" loading="lazy" />
  </figure>
</template>
