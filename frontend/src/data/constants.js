// Статические справочники интерфейса.
// Данные статистики приходят с бэкенда (см. composables/useStats.js).

// Периоды. k -> отправляемый на бэкенд time: '1'->1, '7'->7, '30'->30, 'all'->0.
export const PERIODS = [
  { k: '1',   time: 1,  label: 'Сутки',     long: 'за сутки' },
  { k: '7',   time: 7,  label: '7 дней',    long: 'за 7 дней' },
  { k: '30',  time: 30, label: '30 дней',   long: 'за 30 дней' },
  { k: 'all', time: 0,  label: 'Всё время', long: 'за всё время' },
]

// Категории запросов к нейросети и их цвета (совпадают с бэкендом: text/image/redirect).
export const QUERY_GROUPS = [
  { key: 'text',     name: 'Текст',     c: 'var(--color-text)' },
  { key: 'image',    name: 'Фото',      c: 'var(--color-accent)' },
  { key: 'redirect', name: 'Редиректы', c: 'var(--color-neutral-500)' },
]

export const TABS = [['gen', 'Общее'], ['ai', 'Нейросети'], ['shop', 'Покупки'], ['btn', 'Кнопки']]
