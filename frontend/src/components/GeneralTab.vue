<script setup>
import BarChart from './BarChart.vue'

defineProps({
  gen: { type: Object, required: true },
  showCharts: { type: Boolean, default: true },
  axisFrom: String,
  axisTo: String,
})
const emit = defineEmits(['open'])
</script>

<template>
  <div class="stack">
    <button v-for="k in gen.big" :key="k.key" class="row row--rule big" @click="emit('open', k.detail)">
      <span class="split">
        <span class="kicker">{{ k.label }}</span>
      </span>
      <span class="num num--lg">{{ k.v }}</span>
      <span class="muted">{{ k.sub }}</span>

      <!-- Разбивка активных на новые/старые -->
      <span v-if="k.breakdown" class="breakdown">
        <span v-for="b in k.breakdown" :key="b.label" class="bd-item">
          <span class="bd-top"><b>{{ b.v }}</b> <span class="muted">{{ b.pct }}</span></span>
          <span class="bd-label">{{ b.label }}</span>
          <span class="muted bd-hint">{{ b.hint }}</span>
        </span>
      </span>

      <template v-if="showCharts">
        <BarChart class="chart" :series="k.series" :from="axisFrom" :to="axisTo" />
      </template>
    </button>

    <div class="grid-2">
      <button v-for="k in gen.pair" :key="k.key" class="cell pair" @click="emit('open', k.detail)">
        <span class="kicker">{{ k.label }}</span>
        <span class="num num--md">{{ k.v }}</span>
        <span class="muted">{{ k.sub }}</span>
      </button>
    </div>

    <div class="section-head">
      <span class="kicker kicker--strong">Рефералы за период</span>
      <span class="muted">% от новых юзеров</span>
    </div>
    <button v-for="r in gen.refs" :key="r.key" class="row row--line ref" @click="emit('open', r.detail)">
      <span class="ref-text">
        <span class="ref-label">{{ r.label }}</span>
        <span class="muted">{{ r.v }} юзеров</span>
      </span>
      <span class="num ref-pct">{{ r.pct }}</span>
      <span class="meter meter--thick ref-meter"><span :style="{ width: r.w, background: 'var(--color-accent)' }" /></span>
    </button>
    <div class="spacer" />
  </div>
</template>

<style scoped>
.big { padding: 20px 16px 16px; display: flex; flex-direction: column; gap: 6px; }
.chart { margin-top: 10px; }
.pair { padding: 16px; border-bottom: 2px solid var(--color-divider); }
.ref { padding: 14px 16px; display: grid; grid-template-columns: minmax(0, 1fr) 72px; gap: 6px 16px; align-items: center; }
.ref-text { display: flex; flex-direction: column; gap: 2px; }
.ref-label { font-size: 14px; font-weight: 600; }
.ref-pct { font-size: 24px; letter-spacing: 0; text-align: right; }
.ref-meter { grid-column: 1 / -1; }

.breakdown { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
.bd-item {
  display: flex; flex-direction: column; gap: 2px;
  padding: 10px 12px; border: 1px solid var(--color-divider);
}
.bd-top { font-size: 16px; }
.bd-top b { font-weight: 800; }
.bd-label { font-size: 13px; font-weight: 700; }
.bd-hint { font-size: 11px; }
</style>
