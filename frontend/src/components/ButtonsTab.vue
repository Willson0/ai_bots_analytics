<script setup>
import { computed, ref } from 'vue'

const props = defineProps({ btn: { type: Object, required: true } })
const emit = defineEmits(['open'])

const PREVIEW = 10
const expanded = ref(false)
const allItems = computed(() => props.btn.items || [])
const visible = computed(() => (expanded.value ? allItems.value : allItems.value.slice(0, PREVIEW)))
const hasMore = computed(() => allItems.value.length > PREVIEW)
</script>

<template>
  <div class="stack">
    <div class="summary">
      <span class="split">
        <span class="kicker">Нажатий на кнопки</span>
        <span class="delta" :style="{ color: btn.dColor }">{{ btn.delta }}</span>
      </span>
      <span class="num num--lg">{{ btn.total }}</span>
      <span class="muted">{{ btn.users }} уникальных пользователей</span>
    </div>

    <button v-for="m in visible" :key="m.n" class="row row--line item" @click="emit('open', m.detail)">
      <span class="line">
        <span class="idx">{{ m.n }}</span>
        <span class="name">{{ m.name }}</span>
        <span class="v">{{ m.v }}</span>
        <span class="pct">{{ m.pct }}</span>
      </span>
      <span class="meter indent"><span :style="{ width: m.w, background: 'var(--color-text)' }" /></span>
    </button>

    <button v-if="hasMore" class="more" @click="expanded = !expanded">
      {{ expanded ? 'Свернуть' : `Посмотреть все (${allItems.length})` }}
    </button>

    <p class="note">Нажмите на строку, чтобы увидеть динамику за период.</p>
  </div>
</template>

<style scoped>
.summary { padding: 20px 16px 16px; border-bottom: 2px solid var(--color-divider); display: flex; flex-direction: column; gap: 6px; }
.item { padding: 12px 16px; display: flex; flex-direction: column; gap: 8px; }
.line { display: grid; grid-template-columns: 22px minmax(0, 1fr) auto 52px; gap: 10px; align-items: baseline; font-size: 14px; }
.idx { font-size: 12px; color: var(--color-neutral-600); }
.name { font-weight: 600; }
.v { color: var(--color-neutral-700); }
.pct { text-align: right; font-weight: 800; }
.indent { margin-left: 32px; }
.more { width: 100%; padding: 14px 16px; background: none; border: none; border-top: 1px solid var(--color-divider); font-size: 14px; font-weight: 700; color: var(--color-text); cursor: pointer; }
</style>
