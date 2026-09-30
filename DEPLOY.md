# Деплой проекта на сервер

Проект поднимается через Docker Compose: nginx + php-fpm (Laravel) + MySQL + сборщик фронта (Vue).
Фронт и API живут на **одном домене**: `/` — фронт, `/api` — Laravel.

> Для Telegram Mini App обязателен HTTPS, поэтому нужен домен и SSL-сертификат.

---

## 0. Что нужно заранее

- VPS с Ubuntu 22.04+ (root или sudo).
- Домен, A-запись которого указывает на IP сервера (например `stats.example.com`).
- Токен Telegram-бота (от @BotFather).
- Доступ к БД каждого бота (host/логин/пароль/имя) — их вносим в таблицу `bots`.

---

## 1. Установка Docker

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER      # чтобы docker работал без sudo
# выйти и зайти заново по SSH (или: newgrp docker)
docker version
docker compose version             # compose v2 уже входит в комплект
```

---

## 2. Забрать код

```bash
cd /opt                            # или любой каталог
git clone <URL-репозитория> ai_bots_analytics
cd ai_bots_analytics
```

---

## 3. Создать рабочие конфиги из .example

В репозитории лежат шаблоны (`*.example`), а реальные файлы в `.gitignore` — их создаём вручную:

```bash
cp docker-compose.yml.example      docker-compose.yml
cp nginx/default.conf.example      nginx/default.conf
cp php/Dockerfile.example          php/Dockerfile          # если Dockerfile ещё нет
cp backend/.env.example            backend/.env
cp frontend/src/config.example.json frontend/src/config.json
```

---

## 4. Прописать свой домен

Замените домен `abeta.app` на свой в двух местах.

**`docker-compose.yml`** — пути к сертификатам в сервисе `nginx`:

```yaml
      - /etc/letsencrypt/live/ВАШ_ДОМЕН/privkey.pem:/etc/ssl/private/privkey.pem
      - /etc/letsencrypt/live/ВАШ_ДОМЕН/fullchain.pem:/etc/ssl/certs/fullchain.pem
```

**`nginx/default.conf`** — можно добавить `server_name ВАШ_ДОМЕН;` в оба `server`-блока (не обязательно, сервер и так дефолтный). Пути к `fullchain.pem` / `privkey.pem` уже указывают на смонтированные сертификаты — их менять не нужно.

---

## 5. Получить SSL-сертификат (Let's Encrypt)

Порт 80 должен быть свободен (nginx-контейнер ещё не запущен):

```bash
sudo apt update && sudo apt install -y certbot
sudo certbot certonly --standalone -d ВАШ_ДОМЕН
```

Сертификаты появятся в `/etc/letsencrypt/live/ВАШ_ДОМЕН/` — их и монтирует compose.
Продление: `sudo certbot renew` (можно в cron; после продления перезапустить nginx-контейнер).

---

## 6. Настроить backend/.env

Главное — подключение к БД (в сети Docker хост БД называется `mysql`) и ключ приложения:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ВАШ_ДОМЕН

# Основная БД (реестр ботов). Значения совпадают с сервисом mysql в docker-compose.
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravel_db
DB_USERNAME=laravel_user
DB_PASSWORD=laravel_pass

# Чтобы не заводить таблицы sessions/cache/jobs — используем файлы:
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync

# Токен бота (нужен утилитам отправки в Telegram)
TELEGRAM_BOT_TOKEN=123456:ВАШ_ТОКЕН
```

`APP_KEY` оставьте пустым — сгенерируем в шаге 8.

---

## 7. Настроить frontend/src/config.json

Так как фронт и API на одном домене, оставьте относительный путь:

```json
{ "apiBase": "/api" }
```

(Отдельный адрес бэкенда указывают здесь только если API на другом домене — тогда, помимо URL, на бэке понадобится CORS.)

---

## 8. Запуск

```bash
docker compose up -d --build
```

Что произойдёт:
- `mysql` — поднимет базу `laravel_db`;
- `frontend` — выполнит `npm install && npm run build`, положит сборку в `frontend/dist` (первый билд занимает пару минут — следите за логами: `docker compose logs -f frontend`);
- `php` — контейнер с Laravel;
- `nginx` — отдаёт фронт и проксирует `/api` в php.

Инициализация Laravel (один раз):

```bash
docker compose exec php composer install --no-dev --optimize-autoloader
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --force
docker compose exec php php artisan storage:link
# права на запись:
docker compose exec php chmod -R 775 storage bootstrap/cache
```

---

## 9. Завести ботов в таблице bots

Основная БД хранит только реестр ботов и данные подключения к БД **каждого** бота.
Добавьте строки (host/логин/пароль/имя БД — это реквизиты БД конкретного бота):

```bash
docker compose exec php php artisan tinker
```
```php
\App\Models\Bots::create([
  'name'        => 'tg_hypergpt',
  'db_host'     => '10.0.0.5',      // хост БД бота (виден из контейнера php)
  'bd_login'    => 'bot_user',
  'bd_password' => 'bot_password',
  'bd_name'     => 'hypergpt',
]);
```

> Важно: `db_host` каждого бота должен быть доступен из контейнера `php`.
> Если БД бота — это тот же MySQL из compose, укажите `db_host = mysql`.
> Если внешний сервер — откройте к нему сетевой доступ с этого VPS.

---

## 10. Подключить Web App в Telegram

У @BotFather:
- `/newapp` (или `/setmenubutton`) → выбрать бота → указать URL `https://ВАШ_ДОМЕН`.

После этого кнопка/мини-приложение откроет статистику прямо в Telegram.

---

## 11. Проверка

```bash
curl https://ВАШ_ДОМЕН/api/bots         # должен вернуться JSON со списком ботов
```
Откройте `https://ВАШ_ДОМЕН` в браузере — увидите экран статистики. В самом Telegram — через кнопку Web App.

---

## Частые команды

```bash
docker compose ps                       # статус
docker compose logs -f php              # логи Laravel/php
docker compose logs -f frontend         # логи сборки фронта
docker compose restart nginx            # перезапустить nginx (например после renew)

# Пересобрать фронт после изменений:
docker compose exec frontend npm run build && docker compose restart nginx

# Обновить бэкенд после git pull:
docker compose exec php composer install --no-dev --optimize-autoloader
docker compose exec php php artisan migrate --force
docker compose exec php php artisan config:clear
```

---

## Возможные грабли

- **502 на `/api`** — не поднялся php или composer-зависимости не установлены. Смотрите `docker compose logs php`.
- **Пустой фронт / 404** — сборка ещё идёт или `frontend/dist` пуст. Проверьте `docker compose logs -f frontend`.
- **`Connection refused` при запросе статистики** — `db_host` бота недоступен из контейнера php (сеть/файрвол/неверные реквизиты в таблице `bots`).
- **Не открывается в Telegram** — Mini App требует валидный HTTPS-сертификат и точный URL в BotFather.
