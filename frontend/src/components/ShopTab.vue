<script setup>
defineProps({ shop: { type: Object, required: true } })
const emit = defineEmits(['open'])
</script>

<template>
  <div class="stack">
    <!-- Общая выручка -->
    <div class="block">
      <span class="kicker kicker--strong">Общая выручка</span>
      <span class="num num--lg">{{ shop.revenue.total }}</span>
      <span class="muted">все поступления за период</span>
    </div>
    <div class="grid-2">
      <button v-for="r in shop.revenue.rows" :key="r.label" class="cell split-cell" @click="emit('open', r.detail)">
        <span class="kicker">{{ r.label }}</span>
        <span class="num num--sm">{{ r.v }}</span>
        <span class="muted">{{ r.hint }}</span>
      </button>
    </div>

    <!-- Количество покупок -->
    <div class="block">
      <span class="kicker kicker--strong">Количество покупок</span>
      <span class="num num--md" style="letter-spacing: 0">{{ shop.counts.total }}</span>
      <span class="muted">поступлений за период</span>
    </div>
    <div class="grid-2">
      <button v-for="r in shop.counts.rows" :key="r.label" class="cell split-cell" @click="emit('open', r.detail)">
        <span class="kicker">{{ r.label }}</span>
        <span class="num num--md" style="letter-spacing: 0">{{ r.v }}</span>
        <span class="muted">{{ r.hint }}</span>
      </button>
    </div>

    <!-- Средние чеки -->
    <div class="grid-2">
      <button v-for="k in shop.checks" :key="k.key" class="cell kpi" @click="emit('open', k.detail)">
        <span class="kicker">{{ k.label }}</span>
        <span class="num num--sm">{{ k.v }}</span>
        <span class="muted">{{ k.sub }}</span>
      </button>
    </div>

    <!-- Доход со старта -->
    <div class="block block--line">
      <span class="kicker kicker--strong">Доход со старта</span>
      <span class="num num--md">{{ shop.perNew }}</span>
      <span class="muted">выручка новых юзеров / число новых юзеров</span>
    </div>

    <!-- Конверсия в пробную -->
    <div class="conv">
      <span class="num conv-num">{{ shop.trialConv.pct }}</span>
      <span class="conv-text"><b>Конверсия в пробную подписку</b><br>{{ shop.trialConv.v }} новых юзеров купили пробную</span>
    </div>

    <!-- Продление пробной на PRO -->
    <div class="conv conv--alt">
      <span class="num conv-num">{{ shop.trialToPro.pct }}</span>
      <span class="conv-text"><b>Переходят с пробной на PRO</b><br>{{ shop.trialToPro.sub }}</span>
    </div>

    <!-- Товары -->
    <div class="section-head">
      <span class="kicker kicker--strong">Покупки по товарам</span>
    </div>
    <button v-for="p in shop.products" :key="p.name" class="row row--line product" @click="emit('open', p.detail)">
      <span class="product-name">{{ p.name }}</span>
      <span class="product-v">{{ p.v }}</span>
      <span class="muted">{{ p.price }}</span>
      <span class="muted" style="text-align: right">{{ p.rev }}</span>
    </button>
    <div class="spacer" />
  </div>
</template>

<style scoped>
.block { padding: 18px 16px 14px; display: flex; flex-direction: column; gap: 4px; }
.block--line { border-top: 1px solid var(--color-divider); border-bottom: 2px solid var(--color-divider); }
.split-cell { padding: 14px 16px; border-bottom: 1px solid var(--color-divider); gap: 4px; }
.kpi { padding: 16px; border-bottom: 1px solid var(--color-divider); gap: 4px; }

.conv {
  padding: 16px; background: var(--color-accent); color: var(--color-bg);
  display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 16px; align-items: center;
  border-bottom: 2px solid var(--color-divider);
}
.conv--alt { background: var(--color-text); }
.conv-num { font-size: 40px; }
.conv-text { font-size: 13px; line-height: 1.35; }

.product { padding: 12px 16px; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 12px; align-items: baseline; }
.product-name { font-size: 14px; font-weight: 600; }
.product-v { font-size: 18px; font-weight: 800; text-align: right; }
</style>
