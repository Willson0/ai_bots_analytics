<script setup>
defineProps({ shop: { type: Object, required: true } })
const emit = defineEmits(['open'])
</script>

<template>
  <div class="stack">
    <div class="grid-2">
      <button v-for="k in shop.kpis" :key="k.key" class="cell kpi" @click="emit('open', k.detail)">
        <span class="kicker">{{ k.label }}</span>
        <span class="num num--sm">{{ k.v }}</span>
        <span class="muted">{{ k.sub }}</span>
        <span class="delta" :style="{ color: k.dColor }">{{ k.delta }}</span>
      </button>
    </div>

    <div class="section-head">
      <span class="kicker kicker--strong">Подписки</span>
      <span class="muted">оформили за период · {{ shop.subsTotal }}</span>
    </div>
    <div class="grid-2">
      <button v-for="s in shop.subs" :key="s.name" class="cell sub" @click="emit('open', s.detail)">
        <span class="sub-name muted"><span class="dot" :style="{ background: s.c }" />{{ s.name }}</span>
        <span class="num num--md" style="letter-spacing: 0">{{ s.v }}</span>
        <span class="muted">{{ s.pct }} от всех</span>
      </button>
    </div>

    <div class="trial">
      <span class="num trial-num">{{ shop.trialRenew }}</span>
      <span class="trial-text"><b>Продлевают пробную подписку</b><br>{{ shop.trialRenewSub }}</span>
    </div>

    <div class="section-head">
      <span class="kicker kicker--strong">Покупки по товарам</span>
      <span class="muted">всего {{ shop.count }}</span>
    </div>
    <button v-for="p in shop.products" :key="p.name" class="row row--line product" @click="emit('open', p.detail)">
      <span class="product-name">{{ p.name }}</span>
      <span class="product-v">{{ p.v }}</span>
      <span class="muted">{{ p.price }}</span>
      <span class="muted" style="text-align: right">{{ p.rev }}</span>
    </button>
  </div>
</template>

<style scoped>
.kpi { padding: 18px 16px 16px; border-bottom: 2px solid var(--color-divider); }
.sub { padding: 16px; gap: 4px; border-bottom: 1px solid var(--color-divider); }
.sub-name { display: flex; align-items: center; gap: 6px; }
.dot { width: 8px; height: 8px; }
.trial {
  padding: 16px; background: var(--color-accent); color: var(--color-bg);
  display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 16px; align-items: center;
  border-bottom: 2px solid var(--color-divider);
}
.trial-num { font-size: 44px; }
.trial-text { font-size: 13px; line-height: 1.35; }
.product { padding: 12px 16px; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 12px; align-items: baseline; }
.product-name { font-size: 14px; font-weight: 600; }
.product-v { font-size: 18px; font-weight: 800; text-align: right; }
</style>
