import { reactive, ref, computed, watch, onMounted } from 'vue'
import { PERIODS, QUERY_GROUPS, TABS } from '../data/constants'
import { apiGet } from '../api'

// ---------- форматирование ----------

const fmt = (n) => Math.round(Number(n) || 0).toLocaleString('ru-RU')
const money = (n) => fmt(n) + ' ₽'
// Бэкенд отдаёт проценты числом (12.3) — форматируем в «12,3%».
const pctNum = (p) => (Number(p) || 0).toFixed(1).replace('.', ',') + '%'
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

  // ---------- загрузка ----------

  // Клиентский кэш уже загруженных комбинаций (бот|период|контрагент|ссылка),
  // чтобы переключение назад показывало данные мгновенно, без нового запроса.
  const cacheStore = new Map()
  const cacheKey = () => {
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

  // Собрать статистику по текущей выборке (по кнопке). Использует кэш.
  const build = () => loadStats(false)
  // Принудительное обновление текущей выборки (мимо кэша фронта и сервера).
  const reload = () => loadStats(true)

  // Статистика НЕ загружается автоматически — только по кнопке «Собрать».
  // Автоматически подтягиваем лишь списки ботов и фильтров.
  onMounted(async () => {
    try {
      // loadBots выставит state.bot, watch ниже подтянет фильтры.
      await loadBots()
    } catch (e) {
      error.value = e.message || 'Не удалось загрузить данные'
    }
  })

  // Смена бота — перечитываем только фильтры (контрагенты/ссылки), без статистики.
  watch(() => state.bot, async (v) => {
    if (!v) return
    try {
      await loadFilters()
    } catch (e) {
      error.value = e.message || 'Ошибка загрузки фильтров'
    }
  })

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
  const botPlatform = computed(() => (currentBot.value?.platform === 'MAX' ? 'MAX' : 'TG'))
  const botLabel = computed(() => currentBot.value?.name || '—')
  const cpName = (id) => contragents.value.find((c) => c.id === id)?.name
  const linkName = (id) => links.value.find((l) => l.id === id)?.name

  const updatedLabel = computed(() => {
    if (!updatedAt.value) return ''
    return updatedAt.value.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
  })
  const status = computed(() => {
    if (loading.value) return 'Обновление…'
    if (error.value) return 'Ошибка: ' + error.value
    if (updatedAt.value) return 'Обновлено ' + updatedLabel.value
    return '—'
  })

  // ---------- период / навигация ----------

  const period = computed(() => {
    const p = PERIODS.find((x) => x.k === state.period) || PERIODS[0]
    const s = data.value?.users?.active?.series || data.value?.users?.new?.series || []
    return { ...p, from: s.length ? s[0].label : '', to: s.length ? s[s.length - 1].label : '' }
  })
  const periods = computed(() => PERIODS.map((p) => ({ k: p.k, label: p.label, active: p.k === state.period })))
  const tabs = computed(() => TABS.map(([k, label]) => ({ k, label, active: k === state.tab })))

  // ---------- Общее ----------

  const gen = computed(() => {
    const u = data.value?.users
    const empty = { big: [], pair: [], refs: [] }
    if (!u) return empty

    const ofNew = (m) => pctNum(m.percent) + ' новых юзеров'
    return {
      big: [
        { key: 'act', label: 'Активные юзеры', v: fmt(u.active.total), sub: 'пользовались ботом за период', delta: '', dColor: 'inherit', series: mapBars(u.active.series), detail: detail('Активные юзеры', fmt(u.active.total), mapBars(u.active.series)) },
        { key: 'new', label: 'Новые юзеры', v: fmt(u.new.total), sub: 'впервые запустили бота за период', delta: '', dColor: 'inherit', series: mapBars(u.new.series), detail: detail('Новые юзеры', fmt(u.new.total), mapBars(u.new.series)) },
      ],
      pair: [
        { key: 'prem', label: 'С Telegram Premium', v: fmt(u.new_premium.count), sub: ofNew(u.new_premium), delta: '', dColor: 'inherit', detail: detail('Новые с Telegram Premium', fmt(u.new_premium.count)) },
        { key: 'op', label: 'Подписались на ОП', v: fmt(u.subscribed_op.count), sub: ofNew(u.subscribed_op), delta: '', dColor: 'inherit', detail: detail('Подписались на ОП', fmt(u.subscribed_op.count)) },
      ],
      refs: [
        { key: 'lref', label: 'По реф. ссылкам', v: fmt(u.from_referral_links.count), pct: pctNum(u.from_referral_links.percent), w: (u.from_referral_links.percent || 0) + '%', delta: '', dColor: 'inherit', detail: detail('Рефералы по реф. ссылкам', fmt(u.from_referral_links.count)) },
        { key: 'uref', label: 'От пользователей', v: fmt(u.from_other_users.count), pct: pctNum(u.from_other_users.percent), w: (u.from_other_users.percent || 0) + '%', delta: '', dColor: 'inherit', detail: detail('Рефералы от пользователей', fmt(u.from_other_users.count)) },
      ],
    }
  })

  // ---------- Нейросети ----------

  const ai = computed(() => {
    const q = data.value?.queries
    if (!q) return { total: '0', delta: '', dColor: 'inherit', groups: [] }

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

    return { total: fmt(q.total), delta: '', dColor: 'inherit', groups }
  })

  // ---------- Покупки ----------

  const shop = computed(() => {
    const m = data.value?.monetization
    const empty = { kpis: [], subs: [], subsTotal: '0', trialRenew: '0,0%', trialRenewSub: '', count: '0', products: [] }
    if (!m) return empty

    const trial = m.trial_subs || { count: 0, percent: 0 }
    const pro = m.pro_subs || { count: 0, percent: 0 }
    const t2p = m.trial_to_pro || { count: 0, percent: 0 }
    const subsTotal = (trial.count || 0) + (pro.count || 0)

    return {
      kpis: [
        { key: 'chk', label: 'Средний чек', v: money(m.avg_check), sub: 'на одну покупку', delta: '', dColor: 'inherit', detail: detail('Средний чек', money(m.avg_check)) },
        { key: 'start', label: 'Доход со старта', v: money(m.revenue_per_active), sub: 'доход за период / активные', delta: '', dColor: 'inherit', detail: detail('Доход со старта', money(m.revenue_per_active)) },
      ],
      subs: [
        { name: 'Пробная', c: 'var(--color-neutral-500)', v: fmt(trial.count), pct: pctNum(trial.percent), detail: detail('Пробные подписки', fmt(trial.count)) },
        { name: 'PRO', c: 'var(--color-accent)', v: fmt(pro.count), pct: pctNum(pro.percent), detail: detail('PRO подписки', fmt(pro.count)) },
      ],
      subsTotal: fmt(subsTotal),
      trialRenew: pctNum(t2p.percent),
      trialRenewSub: fmt(t2p.count) + ' из ' + fmt(trial.count) + ' перешли на платную',
      count: fmt(m.purchases || 0),
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
      s = {
        isPick: true, multi: false, kicker: 'Обзор статистики', title: 'Бот', ph: 'Поиск бота',
        options: bots.value.map((b) => ({ id: b.id, name: b.name, sub: b.platform === 'MAX' ? 'MAX' : 'Telegram' })),
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
  const setPeriod = (k) => { if (loading.value) return; state.period = k }
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
    setPeriod, setTab, openSheet, openDetail, closeSheet, resetFilters, pickOption,
  }
}
