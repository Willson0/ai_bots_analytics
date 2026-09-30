<script setup>
import BarChart from './BarChart.vue'

defineProps({
  sheet: { type: Object, required: true },
  periodLabel: String,
  filterShort: String,
  axisFrom: String,
  axisTo: String,
})
const q = defineModel('q', { type: String, default: '' })
const emit = defineEmits(['close', 'pick'])

// Аналог autoFocus из исходника
const vFocus = { mounted: (el) => el.focus() }
</script>

<template>
  <div class="sheet-backdrop" @click="emit('close')" />
  <div class="sheet" role="dialog" aria-modal="true" :aria-label="sheet.title">
    <div class="sheet-head">
      <span class="stack">
        <span class="kicker sheet-kicker">{{ sheet.kicker }}</span>
        <span class="sheet-title">{{ sheet.title }}</span>
      </span>
      <button class="close" aria-label="Закрыть" @click="emit('close')">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12" /></svg>
      </button>
    </div>

    <!-- Выбор бота / ссылки / контрагента -->
    <template v-if="sheet.isPick">
      <div class="search">
        <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
        <input v-model="q" v-focus class="input search-input" type="search" :placeholder="sheet.ph">
      </div>
      <div v-if="sheet.none" class="empty">
        <span class="empty-title">Ничего не найдено</span>
        <span class="empty-sub">Попробуйте другой запрос</span>
      </div>
      <button
        v-for="o in sheet.options" :key="o.id"
        class="option" :class="{ 'option--on': o.on }"
        @click="emit('pick', o.id)"
      >
        <span class="stack" style="min-width: 0">
          <span class="option-name">{{ o.name }}</span>
          <span class="muted ellipsis">{{ o.sub }}</span>
        </span>
        <svg v-if="o.on" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="2.6"><path d="M20 6 9 17l-5-5" /></svg>
      </button>
      <div class="spacer" />
    </template>

    <!-- Детализация метрики -->
    <template v-if="sheet.isDetail">
      <div class="detail">
        <span class="split">
          <span class="muted">{{ periodLabel }} · {{ filterShort }}</span>
          <span class="delta" :style="{ color: sheet.dColor }">{{ sheet.delta }}</span>
        </span>
        <span class="num num--xl">{{ sheet.v }}</span>
        <BarChart v-if="sheet.series && sheet.series.length" class="detail-chart" :series="sheet.series" :height="110" :from="axisFrom" :to="axisTo" />
      </div>
      <template v-if="sheet.hasBreak">
        <div class="section-head"><span class="kicker kicker--strong">По ссылкам</span></div>
        <div v-for="r in sheet.rows" :key="r.name" class="break-row">
          <span class="break-line">
            <span class="stack" style="min-width: 0">
              <span class="option-name" style="font-size: 14px">{{ r.name }}</span>
              <span class="muted">{{ r.cp }}</span>
            </span>
            <span class="break-v">{{ r.v }}</span>
          </span>
          <span class="meter"><span :style="{ width: r.w, background: 'var(--color-accent)' }" /></span>
        </div>
      </template>
      <div class="spacer" />
    </template>
  </div>
</template>

<style scoped>
.sheet-backdrop {
  position: fixed; inset: 0; z-index: 20;
  background: color-mix(in srgb, var(--color-neutral-900) 50%, transparent);
  animation: fadeIn .18s ease-out;
}
.sheet {
  position: fixed; z-index: 21; bottom: 0; left: 0; right: 0; max-width: 440px; margin: 0 auto;
  max-height: 84vh; overflow: auto; background: var(--color-bg);
  border-top: 3px solid var(--color-accent); box-shadow: var(--shadow-lg);
  animation: sheetIn .24s cubic-bezier(.2, .8, .2, 1);
}
.sheet-head {
  position: sticky; top: 0; background: var(--color-bg); z-index: 1;
  display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 8px;
  padding: 14px 16px; border-bottom: 2px solid var(--color-divider);
}
.sheet-kicker { color: var(--color-accent-700); }
.sheet-title { font-family: var(--font-heading); font-weight: 800; font-size: 20px; line-height: 1.15; }
.close {
  width: 40px; height: 40px; border: 1px solid var(--color-divider); background: transparent;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
}
.close:hover { background: color-mix(in srgb, var(--color-text) 7%, transparent); }

.search { padding: 12px 16px; border-bottom: 2px solid var(--color-divider); position: relative; }
.search-icon { position: absolute; left: 28px; top: 50%; transform: translateY(-50%); color: var(--color-neutral-600); pointer-events: none; }
.search-input { min-height: 44px; padding-left: 36px; font-size: 15px; }

.empty { padding: 20px 16px; display: flex; flex-direction: column; gap: 4px; }
.empty-title { font-size: 15px; font-weight: 600; }
.empty-sub { font-size: 13px; color: var(--color-neutral-700); }

.option {
  width: 100%; border: 0; border-bottom: 1px solid var(--color-divider); background: transparent;
  text-align: left; cursor: pointer; padding: 14px 16px;
  display: grid; grid-template-columns: minmax(0, 1fr) 20px; gap: 12px; align-items: center;
}
.option--on { background: var(--color-accent-100); }
.option:hover { background: color-mix(in srgb, var(--color-text) 5%, transparent); }
.option-name { font-size: 15px; font-weight: 600; }

.detail { padding: 18px 16px 16px; display: flex; flex-direction: column; gap: 6px; border-bottom: 2px solid var(--color-divider); }
.detail-chart { margin-top: 12px; }
.break-row { padding: 12px 16px; border-bottom: 1px solid var(--color-divider); display: flex; flex-direction: column; gap: 6px; }
.break-line { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: baseline; }
.break-v { font-size: 16px; font-weight: 800; }
</style>
