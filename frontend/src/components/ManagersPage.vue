<script setup>
import { ref, reactive, onMounted } from 'vue'
import { apiGet, apiSend } from '../api'

const emit = defineEmits(['close'])

const SECTIONS = [['gen', 'Общее'], ['ai', 'Нейросети'], ['shop', 'Покупки'], ['btn', 'Кнопки']]

const managers = ref([])
const bots = ref([])
const loading = ref(true)
const error = ref('')
const saving = ref(false)

// Контрагенты по ботам (ленивая подгрузка для ограничений)
const botCps = reactive({})

const editing = ref(false)
const form = reactive({
  id: null,
  telegram_id: '',
  name: '',
  is_admin: false,
  botsAll: true,
  bots: [],
  sections: ['gen', 'ai', 'shop', 'btn'],
  general: false,
  contragents: {}, // { botId: { all: bool, ids: [] } }
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [m, b] = await Promise.all([apiGet('/managers'), apiGet('/bots')])
    managers.value = m
    bots.value = b
  } catch (e) {
    error.value = e.message || 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}
onMounted(load)

const botName = (id) => bots.value.find((b) => b.id === id)?.name || ('#' + id)

function resetForm() {
  form.id = null
  form.telegram_id = ''
  form.name = ''
  form.is_admin = false
  form.botsAll = true
  form.bots = []
  form.sections = ['gen', 'ai', 'shop', 'btn']
  form.general = false
  form.contragents = {}
}

function startAdd() {
  resetForm()
  editing.value = true
}

function startEdit(m) {
  const p = m.permissions || {}
  form.id = m.id
  form.telegram_id = String(m.telegram_id)
  form.name = m.name || ''
  form.is_admin = !!m.is_admin
  form.botsAll = (p.bots ?? 'all') === 'all'
  form.bots = Array.isArray(p.bots) ? p.bots.slice() : []
  form.sections = Array.isArray(p.sections) ? p.sections.slice() : ['gen', 'ai', 'shop', 'btn']
  form.general = !!p.general
  form.contragents = {}
  const cps = p.contragents || {}
  for (const [bid, val] of Object.entries(cps)) {
    form.contragents[bid] = val === 'all' ? { all: true, ids: [] } : { all: false, ids: (val || []).slice() }
  }
  editing.value = true
}

function toggleArr(arr, v) {
  const i = arr.indexOf(v)
  if (i >= 0) arr.splice(i, 1)
  else arr.push(v)
}

async function ensureBotCps(botId) {
  if (botCps[botId]) return
  try {
    const f = await apiGet('/statistics/filters', { bot: botId })
    botCps[botId] = f.contragents || []
  } catch (e) {
    botCps[botId] = []
  }
}

function cpState(botId) {
  if (!form.contragents[botId]) form.contragents[botId] = { all: true, ids: [] }
  return form.contragents[botId]
}

async function toggleCpRestrict(botId) {
  const st = cpState(botId)
  st.all = !st.all
  if (!st.all) await ensureBotCps(botId)
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    const permissions = {
      bots: form.botsAll ? 'all' : form.bots.slice(),
      sections: form.sections.slice(),
      general: form.general,
      contragents: {},
    }
    // Ограничения по контрагентам — только для конкретных ботов с «Выбрать».
    const scopeBots = form.botsAll ? [] : form.bots
    for (const bid of scopeBots) {
      const st = form.contragents[bid]
      if (st && !st.all) permissions.contragents[String(bid)] = st.ids.slice()
    }

    const body = {
      telegram_id: Number(form.telegram_id),
      name: form.name || null,
      is_admin: form.is_admin,
      permissions,
    }

    if (form.id) await apiSend('PUT', '/managers/' + form.id, body)
    else await apiSend('POST', '/managers', body)

    editing.value = false
    await load()
  } catch (e) {
    error.value = e.message || 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

async function remove(m) {
  if (!confirm('Удалить менеджера ' + (m.name || m.telegram_id) + '?')) return
  try {
    await apiSend('DELETE', '/managers/' + m.id)
    await load()
  } catch (e) {
    error.value = e.message || 'Не удалось удалить'
  }
}

function accessSummary(m) {
  if (m.is_admin) return 'Админ · полный доступ'
  const p = m.permissions || {}
  const b = (p.bots === 'all' || !p.bots) ? 'все боты' : (p.bots.length + ' бот(ов)')
  const s = (p.sections || []).length
  return `${b} · разделов: ${s}${p.general ? ' · общая стата' : ''}`
}
</script>

<template>
  <div class="mgr">
    <div class="mgr-head">
      <button class="back" type="button" @click="emit('close')">← Назад</button>
      <span class="mgr-title">Менеджеры</span>
      <button v-if="!editing" class="add" type="button" @click="startAdd">+ Добавить</button>
      <span v-else style="width: 72px" />
    </div>

    <div v-if="error" class="err">{{ error }}</div>

    <!-- Список -->
    <template v-if="!editing">
      <div v-if="loading" class="muted pad">Загрузка…</div>
      <div v-else-if="!managers.length" class="muted pad">Менеджеров пока нет. Нажмите «Добавить».</div>
      <div v-for="m in managers" :key="m.id" class="m-row">
        <span class="m-main">
          <span class="m-name">{{ m.name || ('ID ' + m.telegram_id) }}<span v-if="m.is_admin" class="badge">АДМИН</span></span>
          <span class="muted">ID {{ m.telegram_id }} · {{ accessSummary(m) }}</span>
        </span>
        <span class="m-actions">
          <button class="link-btn" type="button" @click="startEdit(m)">Изм.</button>
          <button class="link-btn danger" type="button" @click="remove(m)">Удалить</button>
        </span>
      </div>
    </template>

    <!-- Форма -->
    <template v-else>
      <div class="field">
        <label>Telegram ID</label>
        <input v-model="form.telegram_id" class="input" type="number" inputmode="numeric" placeholder="напр. 1337592809" />
      </div>
      <div class="field">
        <label>Имя (для списка)</label>
        <input v-model="form.name" class="input" type="text" placeholder="напр. Менеджер Аня" />
      </div>

      <label class="check">
        <input type="checkbox" v-model="form.is_admin" />
        <span><b>Администратор</b> — полный доступ + управление менеджерами</span>
      </label>

      <template v-if="!form.is_admin">
        <div class="section-head"><span class="kicker kicker--strong">Доступ к ботам</span></div>
        <label class="check">
          <input type="checkbox" v-model="form.botsAll" />
          <span>Все боты</span>
        </label>
        <template v-if="!form.botsAll">
          <div v-for="b in bots" :key="b.id" class="bot-block">
            <label class="check">
              <input type="checkbox" :checked="form.bots.includes(b.id)" @change="toggleArr(form.bots, b.id)" />
              <span>{{ b.name }} <span class="muted">{{ b.platform }}</span></span>
            </label>
            <!-- Ограничение контрагентов для выбранного бота -->
            <div v-if="form.bots.includes(b.id)" class="cp-restrict">
              <label class="check small">
                <input type="checkbox" :checked="!cpState(b.id).all" @change="toggleCpRestrict(b.id)" />
                <span>Ограничить контрагентов</span>
              </label>
              <div v-if="!cpState(b.id).all" class="cp-list">
                <label v-for="c in (botCps[b.id] || [])" :key="c.id" class="check small">
                  <input type="checkbox" :checked="cpState(b.id).ids.includes(c.id)" @change="toggleArr(cpState(b.id).ids, c.id)" />
                  <span>{{ c.name }}</span>
                </label>
                <span v-if="!(botCps[b.id] || []).length" class="muted small">нет контрагентов</span>
              </div>
            </div>
          </div>
        </template>

        <div class="section-head"><span class="kicker kicker--strong">Разделы</span></div>
        <label v-for="[k, lbl] in SECTIONS" :key="k" class="check">
          <input type="checkbox" :checked="form.sections.includes(k)" @change="toggleArr(form.sections, k)" />
          <span>{{ lbl }}</span>
        </label>

        <div class="section-head"><span class="kicker kicker--strong">Прочее</span></div>
        <label class="check">
          <input type="checkbox" v-model="form.general" />
          <span>Доступ к общей статистике (по всем ботам)</span>
        </label>
      </template>

      <div class="form-actions">
        <button class="save" type="button" :disabled="saving || !form.telegram_id" @click="save">
          {{ saving ? 'Сохранение…' : 'Сохранить' }}
        </button>
        <button class="cancel" type="button" @click="editing = false">Отмена</button>
      </div>
    </template>
    <div class="spacer" />
  </div>
</template>

<style scoped>
.mgr { display: flex; flex-direction: column; }
.mgr-head {
  position: sticky; top: 0; z-index: 5; background: var(--color-bg);
  display: grid; grid-template-columns: 72px 1fr auto; align-items: center;
  padding: 12px 16px; border-bottom: 2px solid var(--color-divider);
}
.mgr-title { text-align: center; font-family: var(--font-heading); font-weight: 800; font-size: 18px; }
.back, .add { border: 0; background: transparent; cursor: pointer; font: inherit; color: var(--color-accent-700); font-weight: 700; }
.add { text-align: right; }

.err { margin: 10px 16px; padding: 10px 12px; background: var(--color-accent-100); color: var(--color-accent-800); font-size: 13px; }
.pad { padding: 16px; }
.muted { font-size: 12px; color: var(--color-neutral-700); }
.small { font-size: 12px; }

.m-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: center; padding: 14px 16px; border-bottom: 1px solid var(--color-divider); }
.m-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.m-name { font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.badge { font-size: 10px; font-weight: 800; letter-spacing: .06em; padding: 2px 5px; background: var(--color-text); color: var(--color-bg); }
.m-actions { display: flex; gap: 12px; }
.link-btn { border: 0; background: transparent; cursor: pointer; font-weight: 700; color: var(--color-accent-700); }
.link-btn.danger { color: var(--color-accent-700); text-decoration: underline; }

.field { padding: 12px 16px 0; display: flex; flex-direction: column; gap: 4px; }
.field label { font-size: 12px; color: var(--color-neutral-700); }
.input { min-height: 44px; padding: 10px 12px; border: 2px solid var(--color-divider); background: var(--color-surface); font: inherit; }

.check { display: flex; align-items: flex-start; gap: 10px; padding: 10px 16px; font-size: 14px; cursor: pointer; }
.check input { margin-top: 2px; width: 18px; height: 18px; }
.check.small { padding: 6px 0; font-size: 13px; }

.bot-block { border-bottom: 1px solid var(--color-divider); padding-bottom: 6px; }
.cp-restrict { padding: 0 16px 8px 44px; }
.cp-list { display: flex; flex-direction: column; gap: 0; padding-left: 8px; }

.form-actions { display: flex; gap: 10px; padding: 16px; }
.save { flex: 1; border: 2px solid var(--color-accent); background: var(--color-accent); color: var(--color-bg); font-weight: 800; padding: 12px; cursor: pointer; text-transform: uppercase; letter-spacing: .04em; }
.save:disabled { opacity: .5; cursor: default; }
.cancel { border: 2px solid var(--color-text); background: transparent; color: var(--color-text); font-weight: 800; padding: 12px 18px; cursor: pointer; }

.section-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px 6px; }
.kicker { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--color-neutral-700); font-weight: 600; }
.kicker--strong { color: var(--color-text); font-weight: 800; }
.spacer { height: 24px; }
</style>
