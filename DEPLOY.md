# Деплой на сервер (в поддиректорию `/statistics`)

Сценарий: на сервере уже работает чужой бэкенд на домене `api.gptbackend.ru`
(хостовый nginx проксирует `/` на `127.0.0.1:8000`). Наш проект нужно поставить на
**тот же домен**, ничего не сломав, и отдавать по префиксу:

- **Фронт:** `https://api.gptbackend.ru/statistics/`
- **API:** `https://api.gptbackend.ru/statistics/api/...`

Наш стек полностью изолирован в Docker и **не занимает порты 80/443** — их держит
хостовый nginx. Наш nginx-контейнер слушает только `127.0.0.1:8080`, а хостовый nginx
проксирует туда префикс `/statistics/`. Существующий бэкенд (`location /` → `:8000`)
остаётся нетронутым.

```
                          api.gptbackend.ru  (хостовый nginx, SSL)
                          ├── location /            → 127.0.0.1:8000   (ЧУЖОЙ бэкенд, не трогаем)
                          └── location /statistics/ → 127.0.0.1:8080   (НАШ docker-nginx)
                                                        ├── /      → фронт (Vue, dist)
                                                        └── /api/  → Laravel (php-fpm)
```

> `/statistics/api/bots` → хостовый nginx срезает `/statistics` → наш nginx получает
> `/api/bots` → Laravel. Внутри роуты остаются `/api/*`, менять их не нужно.

---

## 0. Что нужно заранее

- Доступ по SSH к серверу (sudo).
- Установленный Docker (проверьте: `docker version`, `docker compose version`;
  если нет — `curl -fsSL https://get.docker.com | sh` и `sudo usermod -aG docker $USER`).
- Токен Telegram-бота (@BotFather).
- Реквизиты БД каждого бота (host/логин/пароль/имя) — внесём в таблицу `bots`.

Порты `8080` и `8101` на `127.0.0.1` должны быть свободны (у чужого бэкенда — `8000`, не пересекаемся).

---

## 1. Забрать код

```bash
cd /opt
git clone <URL-репозитория> ai_bots_analytics
cd ai_bots_analytics
```

---

## 2. Создать рабочие конфиги из шаблонов

Реальные `docker-compose.yml` и `nginx/default.conf` в `.gitignore` — создаём их из
готовых шаблонов под этот сценарий (`docker-compose.statistics.yml`, `nginx/statistics.conf`),
которые уже настроены: без SSL, наш nginx на `127.0.0.1:8080`.

```bash
cp docker-compose.statistics.yml     docker-compose.yml
cp nginx/statistics.conf             nginx/default.conf
cp php/Dockerfile.example            php/Dockerfile           # если ещё нет
cp backend/.env.example              backend/.env
cp frontend/src/config.example.json  frontend/src/config.json
```

Проверьте, что `docker-compose.yml` публикует наш nginx как `127.0.0.1:8080:80`
(а не `80:80`/`443:443`), а `nginx/default.conf` слушает только `listen 80;` без SSL.

---

## 3. backend/.env

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.gptbackend.ru/statistics

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravel_db
DB_USERNAME=laravel_user
DB_PASSWORD=laravel_pass

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync

TELEGRAM_BOT_TOKEN=123456:ВАШ_ТОКЕН
```
`APP_KEY` оставьте пустым — сгенерируем в шаге 5.

---

## 4. frontend/src/config.json

Фронт живёт под `/statistics/`, API — под `/statistics/api`:

```json
{ "apiBase": "/statistics/api" }
```

(Базовый путь `/statistics/` уже прописан в `vite.config.js` — трогать не нужно.)

---

## 5. Запуск docker-стека

```bash
docker compose up -d --build
```

- `mysql` — база `laravel_db` (только на `127.0.0.1:8101`);
- `frontend` — `npm install && npm run build` → `frontend/dist` (следите: `docker compose logs -f frontend`);
- `php` — Laravel;
- `nginx` — наш, на `127.0.0.1:8080`.

Инициализация Laravel:
```bash
docker compose exec php composer install --no-dev --optimize-autoloader
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --force
docker compose exec php php artisan storage:link
docker compose exec php chmod -R 775 storage bootstrap/cache
```

Проверка, что наш стек отвечает локально (ещё до хостового nginx):
```bash
curl -i http://127.0.0.1:8080/api/bots      # ожидаем JSON (или []), не 502
curl -sI http://127.0.0.1:8080/             # ожидаем 200 и index.html
```

---

## 6. Подключить в хостовый nginx (НЕ ломая чужой бэкенд)

Открой конфиг существующего сайта:
```bash
sudo nano /etc/nginx/sites-enabled/default
```

В **существующий** блок `server { server_name api.gptbackend.ru; listen 443 ssl; … }`
добавь только эти строки (блок `location /` для чужого бэкенда оставь как есть):

```nginx
    # --- Statistics Mini App (наш проект) ---
    location = /statistics { return 301 /statistics/; }

    location /statistics/ {
        proxy_pass http://127.0.0.1:8080/;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        # Статистика может считаться 1-2 минуты — поднимаем таймауты.
        proxy_connect_timeout 75s;
        proxy_send_timeout 300s;
        proxy_read_timeout 300s;
    }
```

Префикс `/statistics/` длиннее, чем `/`, поэтому nginx отдаёт его нам, а всё остальное —
по-прежнему чужому бэкенду. Ничего удалять не нужно.

Проверь и применить:
```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 7. Завести ботов в таблице bots

```bash
docker compose exec php php artisan tinker
```
```php
\App\Models\Bots::create([
  'name'        => 'tg_hypergpt',
  'platform'    => 'tg',           // 'tg' (Telegram) или 'max' (MAX)
  'db_host'     => '10.0.0.5',      // хост БД бота, доступный из контейнера php
  'bd_login'    => 'bot_user',
  'bd_password' => 'bot_password',
  'bd_name'     => 'hypergpt',
]);
```
Если БД бота — этот же MySQL из compose, укажи `db_host = mysql`.
Если внешняя — открой к ней сетевой доступ с сервера.

---

## 8. Telegram

У @BotFather: `/newapp` или `/setmenubutton` → URL `https://api.gptbackend.ru/statistics/`
(со слэшем на конце).

---

## 9. Финальная проверка

```bash
curl https://api.gptbackend.ru/statistics/api/bots     # JSON со списком ботов
```
Открой `https://api.gptbackend.ru/statistics/` в браузере — экран статистики.
Чужой бэкенд проверь отдельно (его обычный адрес) — он должен работать как раньше.

---

## Обновление

```bash
git pull
docker compose exec php composer install --no-dev --optimize-autoloader
docker compose exec php php artisan migrate --force
docker compose exec php php artisan config:clear
docker compose exec frontend npm run build      # пересобрать фронт
docker compose restart nginx
```

---

## Грабли

- **502 на `/statistics/api`** — не поднялся php/`composer install`, или наш nginx не слушает 8080.
  Проверь `curl http://127.0.0.1:8080/api/bots` и `docker compose logs php nginx`.
- **Пустой фронт / 404 на ассеты** — фронт не собран под `base=/statistics/` или `dist` пуст.
  Проверь `docker compose logs -f frontend` и что в `dist/index.html` пути начинаются с `/statistics/`.
- **Сломался чужой сайт** — значит зацепили `location /` или порты 80/443. Наш стек должен
  публиковаться только на `127.0.0.1:8080`; в хостовый nginx добавляется ТОЛЬКО `location /statistics/`.
- **`Connection refused` при запросе статистики** — `db_host` бота недоступен из контейнера php.
- **Не открывается в Telegram** — URL Mini App должен быть точным, с `/statistics/` и по HTTPS.
