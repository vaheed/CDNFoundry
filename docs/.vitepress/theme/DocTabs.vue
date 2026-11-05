<script setup lang="ts">
import { ref } from 'vue'

// One tab per label. The panel for label N is the slot named tab-N (1-based).
const props = defineProps<{ labels: string[] }>()
const active = ref(0)
const id = `tabs-${Math.random().toString(36).slice(2, 8)}`

function move(event: KeyboardEvent) {
  const last = props.labels.length - 1
  if (event.key === 'ArrowRight') active.value = active.value === last ? 0 : active.value + 1
  else if (event.key === 'ArrowLeft') active.value = active.value === 0 ? last : active.value - 1
  else if (event.key === 'Home') active.value = 0
  else if (event.key === 'End') active.value = last
  else return
  event.preventDefault()
  document.getElementById(`${id}-tab-${active.value}`)?.focus()
}
</script>

<template>
  <div class="cdnf-tabs">
    <div class="cdnf-tablist" role="tablist" @keydown="move">
      <button
        v-for="(label, index) in labels"
        :id="`${id}-tab-${index}`"
        :key="label"
        class="cdnf-tab"
        role="tab"
        type="button"
        :aria-selected="active === index"
        :aria-controls="`${id}-panel-${index}`"
        :tabindex="active === index ? 0 : -1"
        @click="active = index"
      >{{ label }}</button>
    </div>
    <div
      v-for="(label, index) in labels"
      v-show="active === index"
      :id="`${id}-panel-${index}`"
      :key="label"
      class="cdnf-tabpanel"
      role="tabpanel"
      :aria-labelledby="`${id}-tab-${index}`"
    >
      <slot :name="`tab-${index + 1}`" />
    </div>
  </div>
</template>
