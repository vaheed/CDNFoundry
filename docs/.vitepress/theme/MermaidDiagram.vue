<template>
  <figure
    class="cdnf-diagram"
    :class="{ 'cdnf-diagram-failed': failure }"
    :aria-busy="rendering ? 'true' : 'false'"
  >
    <div
      v-if="renderedSvg"
      class="cdnf-diagram-svg"
      v-html="renderedSvg"
    />
    <div v-else class="cdnf-diagram-fallback">
      <strong>{{ rendering ? 'Rendering diagram…' : 'Diagram source' }}</strong>
      <p v-if="failure">
        The interactive diagram could not render. The complete source remains
        visible below and the surrounding page describes the same flow.
      </p>
      <pre><code>{{ source }}</code></pre>
    </div>
  </figure>
</template>

<script lang="ts">
let chartSequence = 0
let renderQueue: Promise<void> = Promise.resolve()

function serialize(render: () => Promise<void>): Promise<void> {
  const result = renderQueue.then(render, render)
  renderQueue = result.catch(() => undefined)
  return result
}
</script>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'

const props = defineProps<{
  graph: string
}>()

const source = computed(() => decodeURIComponent(props.graph))
const renderedSvg = ref('')
const failure = ref('')
const rendering = ref(false)

let observer: MutationObserver | undefined
let active = true
let dark = false

async function renderDiagram(): Promise<void> {
  rendering.value = true
  await serialize(async () => {
    if (!active) {
      return
    }

    try {
      const { default: mermaid } = await import('mermaid')
      mermaid.initialize({
        securityLevel: 'strict',
        startOnLoad: false,
        theme: dark ? 'dark' : 'neutral'
      })
      const id = `cdnf-mermaid-${++chartSequence}`
      const { svg } = await mermaid.render(id, source.value)
      if (active) {
        renderedSvg.value = svg
        failure.value = ''
        rendering.value = false
      }
    } catch (error) {
      if (active) {
        failure.value = error instanceof Error ? error.message : String(error)
        rendering.value = false
        console.error('CDNFoundry documentation diagram render failed', error)
      }
    }
  })
}

onMounted(async () => {
  dark = document.documentElement.classList.contains('dark')
  observer = new MutationObserver(() => {
    const nextDark = document.documentElement.classList.contains('dark')
    if (nextDark !== dark) {
      dark = nextDark
      void renderDiagram()
    }
  })
  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class']
  })
  await renderDiagram()
})

onUnmounted(() => {
  active = false
  observer?.disconnect()
})
</script>
