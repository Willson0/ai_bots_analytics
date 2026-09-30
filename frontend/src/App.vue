<script setup>
import { useStats } from './composables/useStats'
import GeneralTab from './components/GeneralTab.vue'
import AiTab from './components/AiTab.vue'
import ShopTab from './components/ShopTab.vue'
import ButtonsTab from './components/ButtonsTab.vue'
import BottomSheet from './components/BottomSheet.vue'

// Бывшие настройки редактора (data-props): «Отображение»
const props = defineProps({
  showDeltas: { type: Boolean, default: true },
  showCharts: { type: Boolean, default: true },
})

const {
  state, period, periods, tabs, gen, ai, shop, btn, filters, sheet, botPlatform, botLabel, status,
  loading, error, reload, build, hasData, dirty,
  setPeriod, setTab, openSheet, openDetail, closeSheet, resetFilters, pickOption,
} = useStats(props)

const chevron = 'm6 9 6 6 6-6'
</script>

<template>
  <div class="shell">
    <!-- Нативную шапку (кнопка закрытия, заголовок) рисует сам Telegram -->
    <div class="hero">
      <span class="hero-kicker">Панель администратора</span>
      <h1 class="hero-title">Статистика</h1>
      <span class="status-line muted">
        <span class="ellipsis">{{ status }}</span>
        <button class="refresh" type="button" :disabled="loading" title="Обновить данные" @click="reload">↻</button>
      </span>
    </div>

    <button class="bot-picker" :disabled="loading" @click="openSheet('bot')">
      <span class="platform">{{ botPlatform }}</span>
      <span class="stack" style="min-width: 0">
        <span class="label">Бот</span>
        <span class="bot-name ellipsis">{{ botLabel }}</span>
      </span>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="chevron" /></svg>
    </button>

    <div class="periods">
      <button
        v-for="p in periods" :key="p.k"
        class="period" :class="{ 'period--on': p.active }"
        :disabled="loading"
        @click="setPeriod(p.k)"
      >{{ p.label }}</button>
    </div>

    <div class="grid-2 filters">
      <button class="filter" :disabled="loading" @click="openSheet('link')">
        <span class="stack" style="min-width: 0">
          <span class="label">Ссылка</span>
          <span class="filter-value ellipsis" :class="{ 'is-set': filters.linkActive }">{{ filters.linkLabel }}</span>
        </span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="chevron" /></svg>
      </button>
      <button class="filter" :disabled="loading" @click="openSheet('cp')">
        <span class="stack" style="min-width: 0">
          <span class="label">Контрагент</span>
          <span class="filter-value ellipsis" :class="{ 'is-set': filters.cpActive }">{{ filters.cpLabel }}</span>
        </span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="chevron" /></svg>
      </button>
    </div>

    <div v-if="filters.hasFilter" class="filter-banner">
      <span>Фильтр: {{ filters.short }}</span>
      <button class="reset" :disabled="loading" @click="resetFilters">Сбросить</button>
    </div>

    <!-- Кнопка сбора: запрос идёт только по ней, а не при каждом выборе -->
    <div class="build-bar">
      <button
        class="build" type="button"
        :class="{ 'build--dirty': dirty || !hasData }"
        :disabled="loading"
        @click="(dirty || !hasData) ? build() : reload()"
      >
        <span v-if="loading">Собираем статистику…</span>
        <span v-else-if="dirty || !hasData">Собрать статистику</span>
        <span v-else>Обновить данные</span>
      </button>
      <span v-if="hasData && dirty && !loading" class="build-note">Параметры изменились — соберите заново</span>
    </div>

    <!-- Данные собраны: вкладки + контент -->
    <template v-if="hasData">
      <div class="tabs">
        <button
          v-for="t in tabs" :key="t.k"
          class="tab" :class="{ 'tab--on': t.active }"
          @click="setTab(t.k)"
        >{{ t.label }}</button>
      </div>

      <div class="content">
        <GeneralTab
          v-if="state.tab === 'gen'"
          :gen="gen" :show-charts="props.showCharts" :axis-from="period.from" :axis-to="period.to"
          @open="openDetail"
        />
        <AiTab v-else-if="state.tab === 'ai'" :ai="ai" @open="openDetail" />
        <ShopTab v-else-if="state.tab === 'shop'" :shop="shop" @open="openDetail" />
        <ButtonsTab v-else-if="state.tab === 'btn'" :btn="btn" @open="openDetail" />

        <!-- Оверлей пересборки поверх уже показанных данных -->
        <div v-if="loading" class="state-overlay">
          <span class="spinner" aria-hidden="true" />
          <span class="state-title">Собираем статистику…</span>
          <span class="state-sub">Расчёт может занять до 1–2 минут.</span>
        </div>
      </div>
    </template>

    <!-- Данных ещё нет: подсказка / загрузка / ошибка -->
    <div v-else class="content">
      <div v-if="loading" class="state-overlay">
        <span class="spinner" aria-hidden="true" />
        <span class="state-title">Собираем статистику…</span>
        <span class="state-sub">Первый расчёт может занять до 1–2 минут. Дальше данные кэшируются и открываются мгновенно.</span>
      </div>
      <div v-else-if="error" class="state-overlay">
        <span class="state-title">Не удалось загрузить</span>
        <span class="state-sub">{{ error }}</span>
        <button class="retry" type="button" @click="build">Повторить</button>
      </div>
      <div v-else class="state-overlay">
        <span class="state-title">Готовы собрать статистику</span>
        <span class="state-sub">Выберите бота, период и фильтры выше, затем нажмите «Собрать статистику».</span>
      </div>
    </div>

    <BottomSheet
      v-if="state.sheet"
      v-model:q="state.q"
      :sheet="sheet"
      :period-label="period.long" :filter-short="filters.short"
      :axis-from="period.from" :axis-to="period.to"
      @close="closeSheet" @pick="pickOption"
    />
  </div>
</template>

<style scoped>
.hero { padding: 20px 16px 16px; display: flex; flex-direction: column; gap: 4px; }
.hero-kicker { font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--color-accent-700); font-weight: 600; }
.hero-title { margin: 0; font-size: 40px; line-height: 1; letter-spacing: -.03em; }

.label { font-size: 11px; color: var(--color-neutral-700); }

.bot-picker {
  width: 100%; border: 0; border-top: 2px solid var(--color-divider); background: var(--color-surface);
  padding: 12px 16px; text-align: left; cursor: pointer;
  display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 12px;
}
.bot-picker:hover { background: var(--color-neutral-200); }
.platform {
  font-size: 11px; letter-spacing: .08em; text-transform: uppercase; font-weight: 800;
  padding: 4px 6px; background: var(--color-text); color: var(--color-bg);
}
.bot-name { font-size: 16px; font-weight: 800; }

.periods {
  display: grid; grid-template-columns: repeat(4, 1fr);
  border-top: 2px solid var(--color-divider); border-bottom: 2px solid var(--color-divider);
}
.period {
  border: 0; border-right: 1px solid var(--color-divider); padding: 12px 8px; text-align: left;
  font-size: 13px; font-weight: 600; cursor: pointer; background: transparent; color: var(--color-text);
}
.period--on { background: var(--color-accent); color: var(--color-bg); }

.filters { border-bottom: 2px solid var(--color-divider); }
.filter {
  border: 0; background: transparent; padding: 10px 16px; text-align: left; cursor: pointer;
  display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 8px;
}
.filter:first-child { border-right: 1px solid var(--color-divider); }
.filter:hover { background: color-mix(in srgb, var(--color-text) 6%, transparent); }
.filter-value { font-size: 14px; font-weight: 600; color: var(--color-text); }
.filter-value.is-set { color: var(--color-accent-700); }

.filter-banner {
  display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 16px;
  background: var(--color-accent-100); border-bottom: 1px solid var(--color-divider);
  font-size: 12px; color: var(--color-accent-800);
}
.reset {
  border: 0; background: transparent; padding: 2px 0; cursor: pointer; font-weight: 600;
  color: var(--color-accent-700); text-decoration: underline; text-underline-offset: 3px;
}

.tabs {
  position: sticky; top: 0; z-index: 5; display: grid; grid-template-columns: repeat(4, 1fr);
  background: var(--color-bg); border-bottom: 2px solid var(--color-divider);
}
.tab {
  border: 0; background: transparent; padding: 14px 8px 11px; text-align: left; cursor: pointer;
  font-size: 13px; font-weight: 800; border-bottom: 3px solid transparent; color: var(--color-neutral-600);
}
.tab--on { border-bottom-color: var(--color-accent); color: var(--color-text); }

/* кнопка «Собрать статистику» */
.build-bar {
  display: flex; flex-direction: column; gap: 6px;
  padding: 14px 16px; border-bottom: 2px solid var(--color-divider);
}
.build {
  width: 100%; border: 2px solid var(--color-text); background: var(--color-text); color: var(--color-bg);
  font-weight: 800; font-size: 15px; padding: 14px 16px; cursor: pointer;
  text-transform: uppercase; letter-spacing: .04em;
}
.build:hover { background: var(--color-neutral-900); }
.build--dirty { border-color: var(--color-accent); background: var(--color-accent); }
.build--dirty:hover { background: var(--color-accent-600); }
.build:disabled { opacity: .55; cursor: default; }
.build-note { font-size: 12px; color: var(--color-accent-700); font-weight: 600; }

/* блокировка управления во время загрузки */
.bot-picker:disabled, .period:disabled, .filter:disabled, .reset:disabled { opacity: .5; cursor: default; }
.bot-picker:disabled:hover { background: var(--color-surface); }
.filter:disabled:hover { background: transparent; }

/* статус + кнопка обновления */
.status-line { display: flex; align-items: center; gap: 8px; }
.refresh {
  border: 0; background: transparent; cursor: pointer; color: var(--color-accent-700);
  font-size: 16px; line-height: 1; padding: 2px 4px;
}
.refresh:disabled { opacity: .45; cursor: default; }

/* контент с оверлеем загрузки/ошибки */
.content { position: relative; min-height: 340px; }
.state-overlay {
  position: absolute; inset: 0; z-index: 4;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 12px; padding: 32px 24px; text-align: center;
  background: color-mix(in srgb, var(--color-bg) 86%, transparent);
}
.state-title { font-family: var(--font-heading); font-weight: 800; font-size: 18px; }
.state-sub { font-size: 13px; color: var(--color-neutral-700); max-width: 320px; line-height: 1.4; }
.spinner {
  width: 40px; height: 40px; border-radius: 50%;
  border: 3px solid var(--color-neutral-300); border-top-color: var(--color-accent);
  animation: spin .8s linear infinite;
}
.retry {
  margin-top: 4px; border: 2px solid var(--color-text); background: var(--color-text);
  color: var(--color-bg); font-weight: 800; font-size: 13px; padding: 8px 16px; cursor: pointer;
}
.retry:hover { background: var(--color-neutral-900); }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
</style>
