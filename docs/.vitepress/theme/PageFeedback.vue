<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useData } from 'vitepress'

const { page } = useData()
const answer = ref<'yes' | 'no' | null>(null)
const key = computed(() => `cdnf-docs-helpful:${page.value.relativePath}`)

function read() {
  try {
    const stored = localStorage.getItem(key.value)
    answer.value = stored === 'yes' || stored === 'no' ? stored : null
  } catch {
    answer.value = null
  }
}

function choose(value: 'yes' | 'no') {
  answer.value = value
  try {
    localStorage.setItem(key.value, value)
  } catch {
    // The answer still shows for this visit.
  }
}

watch(key, read, { immediate: true })

const issueUrl = computed(() => {
  const title = encodeURIComponent(`Docs: ${page.value.title}`)
  const body = encodeURIComponent(`Page: ${page.value.relativePath}\n\nWhat was missing or unclear?\n`)
  return `https://github.com/vaheed/CDNFoundry/issues/new?labels=documentation&title=${title}&body=${body}`
})
</script>

<template>
  <div class="cdnf-feedback" data-testid="docs-feedback">
    <template v-if="answer === null">
      <span>Was this page helpful?</span>
      <button type="button" data-testid="docs-feedback-yes" @click="choose('yes')">Yes</button>
      <button type="button" data-testid="docs-feedback-no" @click="choose('no')">No</button>
    </template>
    <template v-else-if="answer === 'yes'">
      <span role="status">Thanks for the feedback.</span>
    </template>
    <template v-else>
      <span role="status">Sorry about that.</span>
      <a :href="issueUrl" target="_blank" rel="noopener" data-testid="docs-feedback-issue">Tell us what was missing</a>
    </template>
  </div>
</template>
