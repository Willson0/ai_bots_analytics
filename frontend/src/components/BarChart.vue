<script setup>
defineProps({
  series: { type: Array, required: true },   // [{ h: '42%', c: 'var(--...)' }]
  height: { type: Number, default: 56 },
  from: { type: String, default: '' },
  to: { type: String, default: '' },
})
</script>

<template>
  <span class="bars" :style="{ height: height + 'px' }">
    <span v-for="(b, i) in series" :key="i" class="bar" :style="{ height: b.h, background: b.c }" />
  </span>
  <span class="axis"><span>{{ from }}</span><span>{{ to }}</span></span>
</template>

<style scoped>
.bars { display: flex; align-items: flex-end; gap: 2px; border-bottom: 2px solid var(--color-text); }
.bar { flex: 1; }
.axis { display: flex; justify-content: space-between; font-size: 11px; color: var(--color-neutral-700); }
</style>
