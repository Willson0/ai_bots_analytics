<script setup>
import { ref } from 'vue'

defineProps({
  // [{ h: '42%', c: 'var(--...)', label: '00:00', count: 123 }]
  series: { type: Array, required: true },
  height: { type: Number, default: 56 },
  from: { type: String, default: '' },
  to: { type: String, default: '' },
})

const sel = ref(null)
const pick = (i) => { sel.value = sel.value === i ? null : i }
const fmt = (n) => Math.round(Number(n) || 0).toLocaleString('ru-RU')
</script>

<template>
  <span class="chart">
    <!-- Подсказка по выбранному столбику: дата · количество -->
    <span class="readout" :class="{ 'readout--on': sel !== null }">
      <template v-if="sel !== null && series[sel]">
        <b>{{ series[sel].label }}</b> · {{ fmt(series[sel].count) }}
      </template>
      <template v-else>Нажмите на столбик</template>
    </span>

    <span class="bars" :style="{ height: height + 'px' }">
      <span
        v-for="(b, i) in series" :key="i"
        class="col" :class="{ 'col--on': sel === i }"
        @click="pick(i)"
      >
        <span class="bar" :style="{ height: b.h, background: sel === i ? 'var(--color-accent)' : b.c }" />
      </span>
    </span>

    <span class="axis"><span>{{ from }}</span><span>{{ to }}</span></span>
  </span>
</template>

<style scoped>
.chart { display: block; }
.readout {
  display: block; font-size: 12px; color: var(--color-neutral-600);
  min-height: 16px; margin-bottom: 4px;
}
.readout--on { color: var(--color-text); }
.readout b { font-weight: 800; }

.bars { display: flex; align-items: flex-end; gap: 2px; border-bottom: 2px solid var(--color-text); }
.col {
  flex: 1; display: flex; align-items: flex-end; height: 100%;
  cursor: pointer; min-width: 0;
}
.col:hover .bar { filter: brightness(1.15); }
.col--on { background: color-mix(in srgb, var(--color-accent) 12%, transparent); }
.bar { width: 100%; }

.axis { display: flex; justify-content: space-between; font-size: 11px; color: var(--color-neutral-700); }
</style>
