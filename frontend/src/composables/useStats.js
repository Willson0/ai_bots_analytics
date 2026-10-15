import { reactive, ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { PERIODS, ALL_PERIODS, QUERY_GROUPS, TABS } from '../data/constants'
import { apiGet, apiSend } from '../api'

// Сентинел «Все боты» (сводная статистика). Намеренно не 'all',
// чтобы не пересекаться с 'all' в фильтрах ссылок/контрагентов.
const ALL_BOTS = '__all__'

// ---------- форматирование ----------

const fmt = (n) => Math.round(Number(n) || 0).toLocaleString('ru-RU')
const money = (n) => fmt(n) + ' ₽'
// Бэкенд отдаёт проценты числом (12.3) — форматируем в «12,3%».
const pctNum = (p) => (Number(p) || 0).toFixed(1).replace('.', ',') + '%'
const pctOf = (part, whole) => (whole > 0 ? (part / whole) * 100 : 0)
const pad2 = (i) => String(i).padStart(2, '0')

// Ряд для BarChart: [{ h: '42%', c }]. Последний столбец — акцентный.
function mapBars(series) {
  const arr = Array.isArray(series) ? series : []
  const max = Math.max(...arr.map((s) => s.count), 1)
  return arr.map((s, i) => ({
    h: Math.max(4, (s.count / max) * 100) + '%',
    c: i === arr.length - 1 ? 'var(--color-accent)' : 'var(--color-neutral-800)',
    label: s.label,
    count: s.count,
  }))
}

// Объект детализации для нижней шторки (chart опционален).
const detail = (title, value, series = null) => ({ title, value, series, delta: '', dColor: 'inherit' })

// ---------- composable ----------

export function useStats(props) {
  const state = reactive({
    bot: null,
    period: '30',
    cp: null,        // выбранный контрагент (id) — одиночный, null = все
    linkSel: [],     // выбранные ссылки (id) — множественный, пусто = все
    tab: 'gen',
    sheet: null,     // null | 'bot' | 'link' | 'cp' | 'detail'
    detailData: null,
    q: '',
  })

  // Данные с бэкенда
  const bots = ref([])
  const contragents = ref([])
  const links = ref([])
  const data = ref(null)
  const loading = ref(false)
  const error = ref('')
  const updatedAt = ref(null)
  // Ключ выборки, по которой сейчас показаны данные (для «параметры изменились»).
  const loadedKey = ref(null)

  // Текущий пользователь и его права
  const access = ref(null)
  const denied = ref(false)

  // Сводная статистика по всем ботам (фоновый расчёт).
  const allAwaiting = ref(false) // идёт расчёт в фоне — ждём уведомление
  const allPeriod = ref('all')   // период сводки: '7' | '30' | '365' | 'all'
  let allPollTimer = null
  const allMode = computed(() => state.bot === ALL_BOTS)
  // Значение time для текущего периода сводки.
  const allTimeValue = () => (ALL_PERIODS.find((p) => p.k === allPeriod.value) || ALL_PERIODS[ALL_PERIODS.length - 1]).time

  // ---------- загрузка ----------

  // Клиентский кэш уже загруженных комбинаций (бот|период|контрагент|ссылка),
  // чтобы переключение назад показывало данные мгновенно, без нового запроса.
  const cacheStore = new Map()
  const cacheKey = () => {
    if (state.bot === ALL_BOTS) return 'all:' + allPeriod.value // сводка: ключ по периоду
    const lks = [...state.linkSel].sort((a, b) => a - b).join('_')
    return `${state.bot}|${state.period}|${state.cp ?? ''}|${lks}`
  }

  let statToken = 0

  async function loadBots() {
    bots.value = await apiGet('/bots')
    if (!bots.value.length) {
      error.value = 'Нет доступных ботов'
      return
    }
    if (!state.bot) state.bot = bots.value[0].id
  }

  async function loadFilters() {
    if (!state.bot) return
    const f = await apiGet('/statistics/filters', { bot: state.bot })
    contragents.value = f.contragents || []
    links.value = f.links || []
    // Оставляем в фильтрах только то, что есть у выбранного бота.
    if (state.cp !== null && !contragents.value.some((c) => c.id === state.cp)) state.cp = null
    state.linkSel = state.linkSel.filter((id) => links.value.some((l) => l.id === id))
  }

  async function loadStats(force = false) {
    if (!state.bot) return
    const key = cacheKey()

    // Есть в кэше и не форсим обновление — отдаём мгновенно.
    if (!force && cacheStore.has(key)) {
      const hit = cacheStore.get(key)
      data.value = hit.data
      updatedAt.value = hit.at
      loadedKey.value = key
      loading.value = false
      error.value = ''
      return
    }

    const my = ++statToken
    loading.value = true
    error.value = ''
    try {
      const p = PERIODS.find((x) => x.k === state.period) || PERIODS[0]
      const res = await apiGet('/statistics', {
        time: p.time,
        bot: state.bot,
        contragent: state.cp != null ? [state.cp] : [],
        link: state.linkSel,
        refresh: force ? 1 : undefined, // сброс серверного кэша при принудительном обновлении
      })
      if (my !== statToken) return // пришёл устаревший ответ — игнорируем
      data.value = res
      updatedAt.value = new Date()
      loadedKey.value = key
      cacheStore.set(key, { data: res, at: updatedAt.value })
    } catch (e) {
      if (my === statToken) error.value = e.message || 'Ошибка загрузки'
    } finally {
      if (my === statToken) loading.value = false
    }
  }

  // ---------- сводная статистика по всем ботам ----------

  function stopAllPoll() {
    if (allPollTimer) { clearInterval(allPollTimer); allPollTimer = null }
  }

  function applyAll(res) {
    data.value = res.data
    updatedAt.value = res.generated_at ? new Date(res.generated_at) : new Date()
    loadedKey.value = cacheKey()
    allAwaiting.value = false
    loading.value = false
    error.value = ''
  }

  // Периодически опрашиваем сервер по выбранному периоду, пока расчёт не готов.
  function startAllPoll(time) {
    stopAllPoll()
    allPollTimer = setInterval(async () => {
      if (state.bot !== ALL_BOTS) { stopAllPoll(); return }
      try {
        const res = await apiGet('/statistics/all', { time })
        if (state.bot !== ALL_BOTS) { stopAllPoll(); return }
        if (res.status === 'ready') { applyAll(res); stopAllPoll() }
        else if (res.status === 'error') { error.value = res.message || 'Ошибка расчёта'; allAwaiting.value = false; stopAllPoll() }
        // 'queued' / 'none' — продолжаем ждать
      } catch (e) { /* молча ждём следующий тик */ }
    }, 5000)
  }

  // Запуск сводного расчёта (по кнопке). force — пересчитать заново.
  async function startAll(force) {
    stopAllPoll()
    const my = ++statToken
    const time = allTimeValue()
    loading.value = true
    error.value = ''
    allAwaiting.value = false
    try {
      const res = await apiSend('POST', '/statistics/all', { time, refresh: force ? 1 : undefined })
      if (my !== statToken) return
      if (res.status === 'ready') {
        applyAll(res)
      } else if (res.status === 'error') {
        error.value = res.message || 'Не удалось собрать статистику'
        loading.value = false
      } else { // queued — считается в фоне
        allAwaiting.value = true
        loading.value = false
        startAllPoll(time)
      }
    } catch (e) {
      if (my === statToken) { error.value = e.message || 'Ошибка запроса'; loading.value = false }
    }
  }

  // При входе в режим «Все боты» / смене периода — показать готовый отчёт, если есть.
  async function checkAll() {
    const time = allTimeValue()
    try {
      const res = await apiGet('/statistics/all', { time })
      if (state.bot !== ALL_BOTS) return
      if (res.status === 'ready') applyAll(res)
      else if (res.status === 'queued') { allAwaiting.value = true; startAllPoll(time) }
    } catch (e) { /* молча — пользователь нажмёт «Собрать» */ }
  }

  // Собрать статистику (по кнопке). В режиме «Все боты» — фоновый расчёт.
  const build = () => (state.bot === ALL_BOTS ? startAll(false) : loadStats(false))
  // Принудительное обновление (мимо кэшей фронта и сервера).
  const reload = () => (state.bot === ALL_BOTS ? startAll(true) : loadStats(true))

  // Статистика НЕ загружается автоматически — только по кнопке «Собрать».
  async function loadMe() {
    try {
      access.value = await apiGet('/me')
      // Начальная вкладка — первая доступная.
      const allowed = access.value?.sections || []
      if (allowed.length && !allowed.includes(state.tab)) state.tab = allowed[0]
    } catch (e) {
      if (e.status === 401) denied.value = true
      else error.value = e.message || 'Не удалось авторизоваться'
      throw e
    }
  }

  // Сначала авторизация, затем списки ботов/фильтров.
  onMounted(async () => {
    try {
      await loadMe()
      await loadBots()
    } catch (e) {
      // ошибки уже разложены в denied/error
    }
  })

  // Смена бота — перечитываем только фильтры (контрагенты/ссылки), без статистики.
  watch(() => state.bot, async (v) => {
    stopAllPoll()
    allAwaiting.value = false
    if (!v) return
    if (v === ALL_BOTS) { checkAll(); return } // сводка: фильтры не нужны
    try {
      await loadFilters()
    } catch (e) {
      error.value = e.message || 'Ошибка загрузки фильтров'
    }
  })

  // Смена периода сводки — показать готовый отчёт для него или предложить собрать.
  watch(allPeriod, () => {
    if (state.bot !== ALL_BOTS) return
    stopAllPoll()
    allAwaiting.value = false
    checkAll()
  })

  onUnmounted(stopAllPoll)

  // При изменении любого параметра: если такая комбинация уже есть в кэше
  // фронта — сразу показываем её (без кнопки «Собрать»). Если нет — данные
  // остаются скрытыми (dirty), пользователь жмёт «Собрать статистику».
  // Запрос на сервер тут НЕ отправляется.
  watch(() => cacheKey(), () => {
    if (loading.value) return
    const key = cacheKey()
    if (cacheStore.has(key)) {
      const hit = cacheStore.get(key)
      data.value = hit.data
      updatedAt.value = hit.at
      loadedKey.value = key
      error.value = ''
    }
  })

  // Есть ли уже собранные данные и совпадают ли они с текущей выборкой.
  const hasData = computed(() => data.value !== null)
  const dirty = computed(() => cacheKey() !== loadedKey.value)

  // ---------- вспомогательное ----------

  const currentBot = computed(() => bots.value.find((b) => b.id === state.bot) || null)
  const botPlatform = computed(() => (state.bot === ALL_BOTS ? 'ВСЕ' : (currentBot.value?.platform === 'MAX' ? 'MAX' : 'TG')))
  const botLabel = computed(() => (state.bot === ALL_BOTS ? 'Все боты' : (currentBot.value?.name || '—')))
  const cpName = (id) => contragents.value.find((c) => c.id === id)?.name
  const linkName = (id) => links.value.find((l) => l.id === id)?.name

  const updatedLabel = computed(() => {
    if (!updatedAt.value) return ''
    return updatedAt.value.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
  })
  const status = computed(() => {
    if (loading.value) return 'Обновление…'
    if (allAwaiting.value) return 'Считаем в фоне…'
    if (error.value) return 'Ошибка: ' + error.value
    if (updatedAt.value) return 'Обновлено ' + updatedLabel.value
    return '—'
  })

  // ---------- период / навигация ----------

  const period = computed(() => {
    const list = state.bot === ALL_BOTS ? ALL_PERIODS : PERIODS
    const key = state.bot === ALL_BOTS ? allPeriod.value : state.period
    const p = list.find((x) => x.k === key) || list[0]
    const s = data.value?.users?.active?.series || data.value?.users?.new?.series || []
    return { ...p, from: s.length ? s[0].label : '', to: s.length ? s[s.length - 1].label : '' }
  })
  const periods = computed(() => {
    if (state.bot === ALL_BOTS) return ALL_PERIODS.map((p) => ({ k: p.k, label: p.label, active: p.k === allPeriod.value }))
    return PERIODS.map((p) => ({ k: p.k, label: p.label, active: p.k === state.period }))
  })
  const tabs = computed(() => {
    const allowed = access.value?.sections || null
    return TABS
      .filter(([k]) => !allowed || allowed.includes(k))
      .map(([k, label]) => ({ k, label, active: k === state.tab }))
  })
  const isAdmin = computed(() => !!access.value?.is_admin)
  const canGeneral = computed(() => !!access.value?.general)

  // ---------- Общее ----------

  const gen = computed(() => {
    const u = data.value?.users
    const empty = { big: [], pair: [], refs: [] }
    if (!u) return empty

    const ofNew = (m) => pctNum(m.percent) + ' от новых'
    const refs = u.referrals || {}
    const refRow = (key, label, m, desc) => ({
      key, label,
      v: fmt(m.count), pct: pctNum(m.percent), w: (m.percent || 0) + '%',
      delta: '', dColor: 'inherit',
      detail: detail(label, fmt(m.count)),
    })

    return {
      big: [
        {
          key: 'act', label: 'Активные юзеры',
          v: fmt(u.active.total),
          sub: 'уникальные, кто сделал хотя бы 1 запрос к нейросети за период',
          delta: '', dColor: 'inherit',
          series: mapBars(u.active.series),
          // Разбивка активных: новые (появились в периоде) / старые (были раньше).
          breakdown: [
            { label: 'Новые', hint: 'появились в базе за период', v: fmt(u.active.new), pct: pctNum(pctOf(u.active.new, u.active.total)) },
            { label: 'Старые', hint: 'были в базе до периода', v: fmt(u.active.old), pct: pctNum(pctOf(u.active.old, u.active.total)) },
          ],
          detail: detail('Активные юзеры', fmt(u.active.total), mapBars(u.active.series)),
        },
        {
          key: 'new', label: 'Новые юзеры',
          v: fmt(u.new.total),
          sub: 'впервые написали /start и появились в базе за период',
          delta: '', dColor: 'inherit',
          series: mapBars(u.new.series),
          detail: detail('Новые юзеры', fmt(u.new.total), mapBars(u.new.series)),
        },
      ],
      pair: [
        { key: 'prem', label: 'С Telegram Premium', v: fmt(u.new_premium.count), sub: ofNew(u.new_premium) + ' новых с Premium', delta: '', dColor: 'inherit', detail: detail('Новые с Telegram Premium', fmt(u.new_premium.count)) },
        { key: 'op', label: 'Подписались на ОП', v: fmt(u.subscribed_op.count), sub: ofNew(u.subscribed_op) + ' прошли обязательную подписку', delta: '', dColor: 'inherit', detail: detail('Подписались на ОП', fmt(u.subscribed_op.count)) },
      ],
      refs: [
        refRow('lref', 'По реф. ссылкам', refs.links || { count: 0, percent: 0 }),
        refRow('uref', 'От пользователей', refs.users || { count: 0, percent: 0 }),
        refRow('unref', 'Неизвестно', refs.unknown || { count: 0, percent: 0 }),
      ],
    }
  })

  // ---------- Нейросети ----------

  const ai = computed(() => {
    const q = data.value?.queries
    const empty = { total: '0', delta: '', dColor: 'inherit', groups: [], redirects: null }
    if (!q) return empty

    const allCounts = QUERY_GROUPS.flatMap((g) => (q.top_models?.[g.key] || []).map((m) => m.count))
    const maxM = Math.max(...allCounts, 1)

    const groups = QUERY_GROUPS.map((g) => {
      const bt = q.by_type?.[g.key] || { count: 0, percent: 0 }
      const items = (q.top_models?.[g.key] || []).map((m) => ({
        name: m.model,
        v: fmt(m.count),
        pct: pctNum(m.percent),
        w: (m.count / maxM) * 100 + '%',
        detail: detail(m.model, fmt(m.count)),
      }))
      return { name: g.name, c: g.c, v: fmt(bt.count), pct: pctNum(bt.percent), w: (bt.percent || 0) + '%', items }
    })

    // Отдельный блок редиректов: соотношение редирект-текст / редирект-фото.
    const r = q.redirects || { total: 0, text: { count: 0, percent: 0 }, image: { count: 0, percent: 0 } }
    const redirects = {
      total: fmt(r.total),
      items: [
        { name: 'Текст', c: 'var(--color-text)', v: fmt(r.text.count), pct: pctNum(r.text.percent), w: (r.text.percent || 0) + '%' },
        { name: 'Фото', c: 'var(--color-accent)', v: fmt(r.image.count), pct: pctNum(r.image.percent), w: (r.image.percent || 0) + '%' },
      ],
    }

    return { total: fmt(q.total), delta: '', dColor: 'inherit', groups, redirects }
  })

  // ---------- Покупки ----------

  const shop = computed(() => {
    const m = data.value?.monetization
    const empty = { revenue: [], counts: [], checks: [], perNew: '0 ₽', trialConv: null, trialToPro: null, products: [] }
    if (!m) return empty

    const rev = m.revenue || { total: 0, new: 0, old: 0 }
    const pur = m.purchases || { total: 0, one_time: 0, auto: 0 }
    const chk = m.avg_check || { with_trial: 0, without_trial: 0 }
    const tc = m.trial_conversion || { count: 0, percent: 0 }
    const t2p = m.trial_to_pro || { count: 0, percent: 0 }

    return {
      // Общая выручка + новые/старые
      revenue: {
        total: money(rev.total),
        rows: [
          { label: 'Новые', hint: 'стартовали в периоде', v: money(rev.new), detail: detail('Выручка — новые юзеры', money(rev.new)) },
          { label: 'Старые', hint: 'стартовали до периода', v: money(rev.old), detail: detail('Выручка — старые юзеры', money(rev.old)) },
        ],
      },
      // Кол-во покупок + разовые/авто
      counts: {
        total: fmt(pur.total),
        rows: [
          { label: 'Разовые', hint: 'самостоятельная покупка', v: fmt(pur.one_time), detail: detail('Разовые покупки', fmt(pur.one_time)) },
          { label: 'Автопродления', hint: 'автоматическое списание', v: fmt(pur.auto), detail: detail('Автопродления', fmt(pur.auto)) },
        ],
      },
      checks: [
        { key: 'chk1', label: 'Средний чек', v: money(chk.with_trial), sub: 'с учётом пробных', detail: detail('Средний чек (с пробными)', money(chk.with_trial)) },
        { key: 'chk2', label: 'Средний чек', v: money(chk.without_trial), sub: 'без учёта пробных', detail: detail('Средний чек (без пробных)', money(chk.without_trial)) },
      ],
      perNew: money(m.revenue_per_new_start),
      trialConv: { v: fmt(tc.count), pct: pctNum(tc.percent) },
      trialToPro: { pct: pctNum(t2p.percent), sub: fmt(t2p.count) + ' из ' + fmt(t2p.base != null ? t2p.base : 0) + ' купивших пробную перешли на PRO' },
      products: (m.top_products || []).map((p) => ({
        name: p.name,
        v: fmt(p.sold),
        price: money(p.price),
        rev: money(p.revenue),
        detail: detail(p.name, fmt(p.sold)),
      })),
    }
  })

  // ---------- Кнопки ----------

  const btn = computed(() => {
    const b = data.value?.buttons
    const top = b && Array.isArray(b.top) ? b.top : []
    const maxB = Math.max(...top.map((x) => x.count), 1)
    return {
      total: b && b.total != null ? fmt(b.total) : '—',
      users: b && b.users != null ? fmt(b.users) : '—',
      delta: '',
      dColor: 'inherit',
      items: top.map((x, i) => ({
        n: pad2(i + 1),
        name: x.name,
        v: fmt(x.count),
        pct: pctNum(x.percent),
        w: (x.count / maxB) * 100 + '%',
        detail: detail('Кнопка «' + x.name + '»', fmt(x.count)),
      })),
    }
  })

  // ---------- Фильтры ----------

  const filters = computed(() => {
    const linkNames = state.linkSel.map((id) => linkName(id) || ('#' + id))
    const cpLabel = state.cp != null ? (cpName(state.cp) || ('#' + state.cp)) : 'Все'
    const linkLabel = linkNames.length ? linkNames.join(', ') : 'Все ссылки'
    const hasFilter = state.cp != null || linkNames.length > 0
    const short = hasFilter
      ? [state.cp != null ? cpLabel : null, ...linkNames].filter(Boolean).join(' · ')
      : 'все ссылки и контрагенты'
    return {
      linkLabel, cpLabel, hasFilter, short,
      linkActive: state.linkSel.length > 0,
      cpActive: state.cp != null,
    }
  })

  // ---------- Нижняя шторка ----------

  const sheet = computed(() => {
    const st = state
    let s = {}

    if (st.sheet === 'bot') {
      const opts = bots.value.map((b) => ({ id: b.id, name: b.name, sub: b.platform === 'MAX' ? 'MAX' : 'Telegram' }))
      // «Все боты» — только тем, у кого есть право на общую статистику.
      if (canGeneral.value) opts.unshift({ id: ALL_BOTS, name: 'Все боты', sub: 'Сводная статистика за всё время' })
      s = {
        isPick: true, multi: false, kicker: 'Обзор статистики', title: 'Бот', ph: 'Поиск бота',
        options: opts,
        current: st.bot,
      }
    } else if (st.sheet === 'link') {
      // Если выбран контрагент — показываем только его ссылки.
      const pool = st.cp != null
        ? links.value.filter((l) => l.contragent === st.cp)
        : links.value
      s = {
        isPick: true, multi: true, selected: st.linkSel,
        kicker: 'Фильтр · можно несколько', title: 'Ссылки', ph: 'Поиск по ссылке или контрагенту',
        options: [{ id: 'all', name: 'Все ссылки', sub: st.cp != null ? 'Все ссылки контрагента' : 'Без фильтра по ссылкам' }]
          .concat(pool.map((l) => ({ id: l.id, name: l.name, sub: l.contragent_name || cpName(l.contragent) || 'Без контрагента' }))),
      }
    } else if (st.sheet === 'cp') {
      s = {
        isPick: true, multi: false, kicker: 'Фильтр', title: 'Контрагент', ph: 'Поиск по названию или ссылке',
        current: st.cp,
        options: [{ id: 'all', name: 'Все', sub: 'Без фильтра по контрагентам' }]
          .concat(contragents.value.map((c) => ({
            id: c.id, name: c.name,
            sub: links.value.filter((l) => l.contragent === c.id).map((l) => l.name).join(', ') || '—',
          }))),
      }
    }

    if (s.isPick) {
      const q = st.q.trim().toLowerCase()
      s.options = s.options.map((o) => ({
        ...o,
        on: s.multi
          ? (o.id === 'all' ? s.selected.length === 0 : s.selected.includes(o.id))
          : (o.id === 'all' ? s.current == null : o.id === s.current),
      }))
      if (q) s.options = s.options.filter((o) => o.id !== 'all' && (o.name + ' ' + o.sub).toLowerCase().includes(q))
      s.none = s.options.length === 0
      return s
    }

    if (st.sheet === 'detail' && st.detailData) {
      const d = st.detailData
      return {
        isDetail: true, kicker: 'Подробно', title: d.title, v: d.value,
        delta: d.delta || '', dColor: d.dColor || 'inherit',
        series: d.series || null,
        hasBreak: false, rows: [],
      }
    }
    return s
  })

  // ---------- действия ----------

  // Во время загрузки блокируем действия, инициирующие новый запрос,
  // чтобы не плодить параллельные обращения к серверу.
  const setPeriod = (k) => {
    if (loading.value) return
    if (state.bot === ALL_BOTS) allPeriod.value = k
    else state.period = k
  }
  const setTab = (k) => { state.tab = k }
  const openSheet = (type) => { if (loading.value) return; state.sheet = type; state.q = '' }
  const openDetail = (d) => { state.sheet = 'detail'; state.detailData = d }
  const closeSheet = () => { state.sheet = null }
  const resetFilters = () => { if (loading.value) return; state.cp = null; state.linkSel = [] }

  const toggle = (arr, id) => {
    const i = arr.indexOf(id)
    if (i >= 0) arr.splice(i, 1)
    else arr.push(id)
  }

  function pickOption(id) {
    if (loading.value) return
    if (state.sheet === 'bot') {
      state.bot = id
      state.sheet = null           // бот — одиночный выбор, закрываем
      return
    }
    if (state.sheet === 'cp') {
      // Контрагент — одиночный выбор. Меняем контрагента → сбрасываем ссылки
      // (их набор зависит от контрагента). Закрываем шторку.
      state.cp = id === 'all' ? null : id
      state.linkSel = []
      state.sheet = null
      return
    }
    if (state.sheet === 'link') {
      // Ссылки — мультивыбор, шторку НЕ закрываем.
      if (id === 'all') state.linkSel = []
      else toggle(state.linkSel, id)
    }
  }

  return {
    state,
    period, periods, tabs, gen, ai, shop, btn, filters, sheet,
    botPlatform, botLabel, status, loading, error, reload,
    build, hasData, dirty,
    access, denied, isAdmin, canGeneral,
    allMode, allAwaiting,
    setPeriod, setTab, openSheet, openDetail, closeSheet, resetFilters, pickOption,
  }
}
