<script setup lang="ts">
import { withBase } from 'vitepress'

const props = defineProps<{ title: string; link: string }>()
const href = /^https?:/.test(props.link) ? props.link : withBase(props.link)
</script>

<template>
  <a class="cdnf-card" :href="href">
    <span class="cdnf-card-title">{{ title }}</span>
    <span class="cdnf-card-body"><slot /></span>
  </a>
</template>
