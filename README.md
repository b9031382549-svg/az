# eInvoice AI

Сервис над электронными счетами-фактурами Азербайджана:

- **Классификатор.** Строка счёта («название товара или услуги») превращается в код
  XİF MN (каталог около 11,6 тыс. кодов). Сервис определяет товар это или услуга,
  выдаёт код и его надёжность. Спорные строки уходят на проверку человеку.
- **Чат по счетам.** Вопрос на естественном языке превращается в SQL. Модель пишет
  только читающие запросы: они проверяются и выполняются под отдельной ролью БД
  только на чтение.
- **API для интеграции:** `POST /api/classify`, `GET /api/classify/{id}`,
  `/api/health-check`, `/api/version`. Описание: [API.md](API.md) и страница
  `/api-docs` в самом приложении.

Вход в веб-интерфейс по логину и паролю. API работает по токенам, их выпускают
в Settings → API tokens.

## Состав

Всё работает в Docker, прод описан в `docker-compose.prod.yml`:

| Сервис | Что делает |
|---|---|
| `app` | nginx + php-fpm (Laravel 13, Livewire 4), порт 80 |
| `worker` | фоновые задачи через Laravel Horizon: классификация, загрузки, эмбеддинги |
| `scheduler` | периодические задачи (`routes/console.php`) |
| `pgsql` | PostgreSQL 16 + pgvector (HNSW-индекс для векторного поиска) |
| `redis` | очередь Horizon |
| `ollama` | эмбеддинги (модель `bge-ft-v3`, 1024 измерения) |
| `sorter` | небольшая модель XLM-R (ONNX, CPU): товар / услуга / мусор |

Вне этого набора:
- **GPU-сервер с vLLM:** Llama-3.3-70B-Instruct и LoRA-адаптер `xif`. Это модели
  `gpu:base` и `gpu:tuned` в настройках.
- **OpenAI-совместимые провайдеры LLM:** веб-поиск по спорным строкам и запасная
  модель на время, когда GPU не отвечает.

Какая модель отвечает за какой этап, задаётся в `.env`
(см. [.env.prod.example](.env.prod.example)).

## Чего нет в git

Эти файлы передаются отдельно:

- модель сортера: `model.onnx`, `tokenizer.json`, `meta.json` с sha256 → каталог на
  хосте, который монтируется в `sorter` (в `docker-compose.prod.yml` это
  `/opt/az-assets/sorter-v2`);
- модель эмбеддингов `bge-ft-v3` (GGUF + Modelfile) → `ollama create bge-ft-v3 -f Modelfile`
  внутри контейнера `ollama`;
- LoRA-адаптер `xif` для Llama-3.3-70B → на GPU-сервер;
- данные БД: каталог XİF MN с нашими дополнениями, синонимами и векторами, таможенные
  прецеденты, память ответов (`answer_cache`). Переносятся дампом, от них сильно
  зависит качество;
- секреты: пароли БД, `APP_KEY`, ключи провайдеров LLM.

## Установка

```bash
cp .env.prod.example .env            # заполнить пустые значения
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d pgsql redis ollama sorter

run() { docker compose -f docker-compose.prod.yml run --rm app php artisan "$@"; }
run migrate --force
run db:seed --force                  # учётка admin / admin — сменить пароль сразу
```

Затем загружаем дамп с нашей установки: каталог, прецеденты и память ответов вместе
с векторами. Векторы подходят только к той же модели эмбеддингов (`bge-ft-v3` в Ollama).
После этого выполняем шаги обновления (ниже).

Без дампа каталог можно собрать из исходного реестра XİF MN:
`run data:import-catalog <файл.xls>`, затем `run data:embed-catalog --queue`
(около двух часов на 4 ядрах CPU). Но так не будет наших дополнений к каталогу,
прецедентов и памяти, и качество будет заметно ниже.

## Обновление

На каждом выпуске выполняется одно и то же. Все шаги идемпотентны:

```bash
run() { docker compose -f docker-compose.prod.yml run --rm app php artisan "$@"; }
docker compose -f docker-compose.prod.yml build
run migrate --force
run nlsql:grant                                                       # права роли чата
run db:seed --class='Database\Seeders\MetadataCatalogSeeder' --force  # описания колонок для чата
run data:build-rubricator                                             # дерево рубрикатора
run db:seed --class='Database\Seeders\HsCardsSeeder' --force          # карточки ТН ВЭД
run catalog:sync-card-synonyms
run catalog:build-search-text
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec -T -u www-data app php artisan optimize
```

`optimize` запускается от `www-data`, как и php-fpm. Если запустить его от root,
кэши будут принадлежать root, и часть страниц начнёт отдавать 500.

Миграции выполняются только этим шагом, никогда не из entrypoint контейнера. После
изменения `.env` нужно снова выполнить `optimize`: в проде настройки кэшируются.

## Проверка

- `GET /up` — приложение отвечает.
- `GET /api/health-check` (с токеном) — БД, Redis, воркеры Horizon, сортер,
  модель эмбеддингов, векторный индекс, доступность моделей. Все проверки мгновенные
  и бесплатные: классификацию они не запускают.

## Разработка

```bash
docker compose up -d                 # dev-стек: исходники смонтированы, php artisan serve
composer setup                       # первый запуск
composer dev                         # сервер + очередь + логи + vite
php artisan test                     # PHPUnit, sqlite в памяти, внешние сервисы не нужны
./vendor/bin/pint                    # форматирование (Laravel Pint)
```

PHP 8.3, Node 22 (`npm ci`). Настройки читаются через `config()` (`config/*.php`), не
через `env()` вне конфигов. Фоновая работа идёт через Horizon (Redis), не через `sync`.
