<script setup>
defineProps({ ai: { type: Object, required: true } })
const emit = defineEmits(['open'])
</script>

<template>
  <div class="stack">
    <div class="summary">
      <span class="kicker">Всего запросов к нейросетям</span>
      <span class="num num--lg">{{ ai.total }}</span>
      <span class="stacked">
        <span v-for="g in ai.groups" :key="g.name" :style="{ width: g.w, background: g.c }" />
      </span>
      <span class="legend">
        <span v-for="g in ai.groups" :key="g.name" class="legend-item">
          <span class="swatch" :style="{ background: g.c }" />{{ g.name }} <b>{{ g.pct }}</b>
        </span>
      </span>
    </div>

    <template v-for="g in ai.groups" :key="g.name">
      <div class="section-head">
        <span class="kicker kicker--strong">{{ g.name }}</span>
        <span class="muted">{{ g.v }} · {{ g.pct }}</span>
      </div>
      <button v-for="m in g.items" :key="m.name" class="row row--line model" @click="emit('open', m.detail)">
        <span class="model-line">
          <span class="model-name">{{ m.name }}</span>
          <span class="model-v">{{ m.v }}</span>
          <span class="model-pct">{{ m.pct }}</span>
        </span>
        <span class="meter"><span :style="{ width: m.w, background: g.c }" /></span>
      </button>
    </template>

    <p class="note">Процент — доля от всех запросов к нейросетям (текст + фото) за период. Редиректы распределены по категориям: редирект-текст в «Текст», редирект-фото в «Фото».</p>

    <!-- Отдельный блок: соотношение редиректов -->
    <template v-if="ai.redirects">
      <div class="section-head">
        <span class="kicker kicker--strong">Редиректы</span>
        <span class="muted">всего {{ ai.redirects.total }}</span>
      </div>
      <div v-for="r in ai.redirects.items" :key="r.name" class="row row--line model">
        <span class="model-line">
          <span class="model-name"><span class="dot" :style="{ background: r.c }" />{{ r.name }}</span>
          <span class="model-v">{{ r.v }}</span>
          <span class="model-pct">{{ r.pct }}</span>
        </span>
        <span class="meter"><span :style="{ width: r.w, background: r.c }" /></span>
      </div>
      <p class="note">Соотношение редиректов между текстом и изображениями (от общего числа редиректов).</p>
    </template>
  </div>
</template>

<style scoped>
.summary { padding: 20px 16px 16px; border-bottom: 2px solid var(--color-divider); display: flex; flex-direction: column; gap: 8px; }
.stacked { display: flex; height: 12px; margin-top: 6px; }
.stacked > span { height: 100%; }
.legend { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 12px; }
.legend-item { display: flex; align-items: center; gap: 6px; }
.swatch { width: 10px; height: 10px; }
.model { padding: 12px 16px; display: flex; flex-direction: column; gap: 8px; }
.model-line { display: grid; grid-template-columns: minmax(0, 1fr) auto 56px; gap: 12px; align-items: baseline; font-size: 14px; }
.model-name { font-weight: 600; display: flex; align-items: center; gap: 6px; }
.dot { width: 8px; height: 8px; }
.model-v { color: var(--color-neutral-700); }
.model-pct { text-align: right; font-weight: 800; }
</style>
