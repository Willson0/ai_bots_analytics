<template>
    <div class="app-container">
        <!-- Верхняя панель навигации -->
        <div class="top-bar">
      <span class="close-btn">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
          <path d="M18 6 6 18M6 6l12 12"></path>
        </svg>
        Закрыть
      </span>
            <span class="title">НейроБот</span>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="5" cy="12" r="1"></circle>
                <circle cx="12" cy="12" r="1"></circle>
                <circle cx="19" cy="12" r="1"></circle>
            </svg>
        </div>

        <!-- Заголовок -->
        <div class="header">
            <span class="kicker">Панель администратора</span>
            <h1>Статистика</h1>
            <span class="meta">Обновлено 28 сен, 14:32 · бот запущен 12 марта 2026</span>
        </div>

        <!-- Выбор периода -->
        <div class="periods">
            <button
                v-for="p in periods"
                :key="p.k"
                @click="selectPeriod(p.k)"
                :style="{ background: p.bg, color: p.fg }"
                class="period-btn"
            >
                {{ p.label }}
            </button>
        </div>

        <!-- Фильтры -->
        <div class="filters">
            <button @click="openLink" class="filter-btn">
        <span class="filter-content">
          <span class="filter-label">Ссылка</span>
          <span class="filter-value" :style="{ color: linkColor }">{{ linkLabel }}</span>
        </span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="m6 9 6 6 6-6"></path>
                </svg>
            </button>
            <button @click="openCp" class="filter-btn">
        <span class="filter-content">
          <span class="filter-label">Контрагент</span>
          <span class="filter-value" :style="{ color: cpColor }">{{ cpLabel }}</span>
        </span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="m6 9 6 6 6-6"></path>
                </svg>
            </button>
        </div>

        <!-- Активный фильтр -->
        <div v-if="hasFilter" class="active-filter">
            <span>Фильтр: {{ filterText }}</span>
            <button @click="resetFilters" class="reset-btn">Сбросить</button>
        </div>

        <!-- Табы -->
        <div class="tabs">
            <button
                v-for="t in tabs"
                :key="t.k"
                @click="selectTab(t.k)"
                class="tab-btn"
                :style="{ borderBottomColor: t.line, color: t.fg }"
            >
                {{ t.label }}
            </button>
        </div>

        <!-- Контент: Общее -->
        <div v-if="isGen" class="content">
            <!-- Большие карточки -->
            <button
                v-for="k in gen.big"
                :key="k.label"
                @click="k.open"
                class="big-card"
            >
        <span class="card-header">
          <span class="card-label">{{ k.label }}</span>
          <span class="card-delta" :style="{ color: k.dColor }">{{ k.delta }}</span>
        </span>
                <span class="card-value">{{ k.v }}</span>
                <span class="card-sub">{{ k.sub }}</span>
                <span v-if="showCharts" class="chart">
          <span
              v-for="(b, i) in k.series"
              :key="i"
              class="bar"
              :style="{ height: b.h, background: b.c }"
          ></span>
        </span>
                <span v-if="showCharts" class="axis">
          <span>{{ axisFrom }}</span>
          <span>{{ axisTo }}</span>
        </span>
            </button>

            <!-- Пары -->
            <div class="pair-grid">
                <button
                    v-for="k in gen.pair"
                    :key="k.label"
                    @click="k.open"
                    class="pair-card"
                >
                    <span class="card-label">{{ k.label }}</span>
                    <span class="card-value">{{ k.v }}</span>
                    <span class="card-sub">{{ k.sub }}</span>
                    <span class="card-delta" :style="{ color: k.dColor }">{{ k.delta }}</span>
                </button>
            </div>

            <!-- Рефералы -->
            <div class="section-header">
                <span class="section-title">Рефералы за период</span>
                <span class="section-meta">% от новых юзеров</span>
            </div>
            <button
                v-for="r in gen.refs"
                :key="r.label"
                @click="r.open"
                class="ref-card"
            >
        <span class="ref-info">
          <span class="ref-title">{{ r.label }}</span>
          <span class="ref-details">{{ r.v }} юзеров <b :style="{ color: r.dColor }">{{ r.delta }}</b></span>
        </span>
                <span class="ref-pct">{{ r.pct }}</span>
                <span class="progress-bar">
          <span class="progress-fill" :style="{ width: r.w }"></span>
        </span>
            </button>
            <div class="spacer"></div>
        </div>

        <!-- Контент: Нейросети -->
        <div v-if="isAi" class="content">
            <div class="ai-total">
        <span class="card-header">
          <span class="card-label">Всего запросов к нейросетям</span>
          <span class="card-delta" :style="{ color: ai.dColor }">{{ ai.delta }}</span>
        </span>
                <span class="card-value">{{ ai.total }}</span>
                <span class="ai-groups-bar">
          <span
              v-for="g in ai.groups"
              :key="g.name"
              :style="{ width: g.w, background: g.c }"
          ></span>
        </span>
                <span class="ai-legend">
          <span v-for="g in ai.groups" :key="g.name" class="legend-item">
            <span class="legend-dot" :style="{ background: g.c }"></span>
            {{ g.name }} <b>{{ g.pct }}</b>
          </span>
        </span>
            </div>

            <template v-for="g in ai.groups" :key="g.name">
                <div class="section-header">
                    <span class="section-title">{{ g.name }}</span>
                    <span class="section-meta">{{ g.v }} · {{ g.pct }}</span>
                </div>
                <button
                    v-for="m in g.items"
                    :key="m.name"
                    @click="m.open"
                    class="model-card"
                >
          <span class="model-info">
            <span class="model-name">{{ m.name }}</span>
            <span class="model-count">{{ m.v }}</span>
            <span class="model-pct">{{ m.pct }}</span>
          </span>
                    <span class="progress-bar-thin">
            <span class="progress-fill" :style="{ width: m.w, background: g.c }"></span>
          </span>
                </button>
            </template>
            <p class="note">Процент — доля от всех запросов к нейросетям за выбранный период, включая фото и редиректы.</p>
        </div>

        <!-- Контент: Покупки -->
        <div v-if="isShop" class="content">
            <div class="pair-grid">
                <button
                    v-for="k in shop.kpis"
                    :key="k.label"
                    @click="k.open"
                    class="pair-card"
                >
                    <span class="card-label">{{ k.label }}</span>
                    <span class="card-value">{{ k.v }}</span>
                    <span class="card-sub">{{ k.sub }}</span>
                    <span class="card-delta" :style="{ color: k.dColor }">{{ k.delta }}</span>
                </button>
            </div>

            <div class="section-header">
                <span class="section-title">Подписки</span>
                <span class="section-meta">оформили за период · {{ shop.subsTotal }}</span>
            </div>
            <div class="pair-grid">
                <button
                    v-for="s in shop.subs"
                    :key="s.name"
                    @click="s.open"
                    class="sub-card"
                >
          <span class="sub-label">
            <span class="sub-dot" :style="{ background: s.c }"></span>
            {{ s.name }}
          </span>
                    <span class="card-value">{{ s.v }}</span>
                    <span class="card-sub">{{ s.pct }} от всех</span>
                </button>
            </div>

            <div class="trial-banner">
                <span class="trial-pct">{{ shop.trialRenew }}</span>
                <span class="trial-text"><b>Продлевают пробную подписку</b><br>{{ shop.trialRenewSub }}</span>
            </div>

            <div class="section-header">
                <span class="section-title">Покупки по товарам</span>
                <span class="section-meta">всего {{ shop.count }}</span>
            </div>
            <button
                v-for="p in shop.products"
                :key="p.name"
                @click="p.open"
                class="product-card"
            >
                <span class="product-name">{{ p.name }}</span>
                <span class="product-value">{{ p.v }}</span>
                <span class="product-price">{{ p.price }}</span>
                <span class="product-rev">{{ p.rev }}</span>
            </button>
        </div>

        <!-- Контент: Кнопки -->
        <div v-if="isBtn" class="content">
            <div class="btn-total">
        <span class="card-header">
          <span class="card-label">Нажатий на кнопки</span>
          <span class="card-delta" :style="{ color: btn.dColor }">{{ btn.delta }}</span>
        </span>
                <span class="card-value">{{ btn.total }}</span>
                <span class="card-sub">{{ btn.users }} уникальных пользователей</span>
            </div>
            <button
                v-for="m in btn.items"
                :key="m.name"
                @click="m.open"
                class="btn-card"
            >
        <span class="btn-info">
          <span class="btn-n">{{ m.n }}</span>
          <span class="btn-name">{{ m.name }}</span>
          <span class="btn-count">{{ m.v }}</span>
          <span class="btn-pct">{{ m.pct }}</span>
        </span>
                <span class="progress-bar-thin">
          <span class="progress-fill" :style="{ width: m.w }"></span>
        </span>
            </button>
            <p class="note">Нажмите на строку, чтобы увидеть динамику за период.</p>
        </div>

        <!-- Bottom Sheet -->
        <div v-if="sheetOpen" class="sheet-backdrop" @click="closeSheet"></div>
        <div v-if="sheetOpen" class="sheet">
            <div class="sheet-header">
        <span class="sheet-title-block">
          <span class="sheet-kicker">{{ sheet.kicker }}</span>
          <span class="sheet-title">{{ sheet.title }}</span>
        </span>
                <button @click="closeSheet" class="close-sheet-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                        <path d="M18 6 6 18M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Picker Mode -->
            <div v-if="sheet.isPick">
                <div class="search-box">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="search-icon">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.3-4.3"></path>
                    </svg>
                    <input
                        class="input search-input"
                        type="search"
                        v-model="q"
                        :placeholder="sheet.ph"
                        autofocus
                    >
                </div>
                <div v-if="sheet.none" class="empty-state">
                    <span class="empty-title">Ничего не найдено</span>
                    <span class="empty-text">Попробуйте другой запрос</span>
                </div>
                <button
                    v-for="o in sheet.options"
                    :key="o.id"
                    @click="o.pick"
                    class="option-btn"
                    :style="{ background: o.bg }"
                >
          <span class="option-info">
            <span class="option-name">{{ o.name }}</span>
            <span class="option-sub">{{ o.sub }}</span>
          </span>
                    <svg v-if="o.on" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="2.6">
                        <path d="M20 6 9 17l-5-5"></path>
                    </svg>
                </button>
                <div class="spacer"></div>
            </div>

            <!-- Detail Mode -->
            <div v-if="sheet.isDetail">
                <div class="detail-card">
          <span class="card-header">
            <span class="detail-meta">{{ periodLabel }} · {{ filterShort }}</span>
            <span class="card-delta" :style="{ color: sheet.dColor }">{{ sheet.delta }}</span>
          </span>
                    <span class="detail-value">{{ sheet.v }}</span>
                    <span class="detail-chart">
            <span
                v-for="(b, i) in sheet.series"
                :key="i"
                class="bar"
                :style="{ height: b.h, background: b.c }"
            ></span>
          </span>
                    <span class="axis">
            <span>{{ axisFrom }}</span>
            <span>{{ axisTo }}</span>
          </span>
                </div>
                <div v-if="sheet.hasBreak" class="section-header-small">По ссылкам</div>
                <div v-if="sheet.hasBreak" v-for="r in sheet.rows" :key="r.name" class="detail-row">
          <span class="row-info">
            <span class="row-name">{{ r.name }}</span>
            <span class="row-cp">{{ r.cp }}</span>
          </span>
                    <span class="row-value">{{ r.v }}</span>
                    <span class="progress-bar-thin full-width">
            <span class="progress-fill" :style="{ width: r.w }"></span>
          </span>
                </div>
                <div class="spacer"></div>
            </div>
        </div>
    </div>
</template>
