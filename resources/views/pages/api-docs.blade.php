<x-app-layout :title="__('API docs').' · '.config('app.name')">
@php
    // Every value below that can change (address, limits, fields, abilities, similarity,
    // version) comes from the controller — read from the running code and config.
    $nf = fn ($n) => number_format((int) $n, 0, '.', ' ');
    $json = fn ($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $page = (int) ($limits['page_size'] ?? 1000);
    $sample = fn (string $code) => str_replace(['__BASE__', '__PAGE__'], [$base, (string) $page], $code);
    $sim = fn (string $method, float $fallback) => (float) ($similarity[$method] ?? $fallback);
    $requestId = '033efd90-57f2-4c47-a49e-07632c5d218f';

    $fieldInfo = [
        'supplier_tax_office' => ['строка', 'Налоговый орган продавца'],
        'supplier_name' => ['строка', 'Название продавца'],
        'supplier_tin' => ['строка / число', 'VÖEN продавца'],
        'recipient_tax_office' => ['строка', 'Налоговый орган покупателя'],
        'recipient_name' => ['строка', 'Название покупателя'],
        'recipient_tin' => ['строка / число', 'VÖEN покупателя'],
        'invoice_type' => ['строка', 'Вид е-накладной'],
        'invoice_date' => ['дата', 'Дата накладной: 01.10.2026 или 2026-10-01'],
        'approval_date' => ['дата', 'Дата подтверждения накладной'],
        'series' => ['строка', 'Серия накладной'],
        'number' => ['строка', 'Номер накладной; серия + номер определяют накладную'],
        'declared_group' => ['строка', 'Группа товара, которую указал поставщик'],
        'declared_code' => ['строка', 'Код, который указал поставщик (только хранится)'],
        'unit' => ['строка', 'Единица измерения, как в накладной — вернётся в ответе'],
        'quantity' => ['число', 'Количество: 12 или "1 234,5"'],
        'excise_amount' => ['число', 'Сумма акциза'],
        'vat_taxable_amount' => ['число', 'Сумма операций, облагаемых ƏDV'],
        'non_vat_taxable_amount' => ['число', 'Сумма операций, не облагаемых ƏDV'],
        'vat_exempt_amount' => ['число', 'Сумма операций, освобождённых от ƏDV'],
        'zero_rated_vat_amount' => ['число', 'Сумма операций по ставке ƏDV 0%'],
        'vat_amount' => ['число', 'Сумма ƏDV'],
        'road_tax' => ['число', 'Дорожный налог'],
        'total_amount' => ['число', 'Итоговая сумма: 85 или "1 234,50"'],
    ];

    $methodInfo = [
        'human' => ['human', 'Решение человека'],
        'trash' => ['trash', 'Правила или модель-сортер: в строке не назван товар'],
        'memory' => ['memory', 'В памяти есть проверенный ответ на это название'],
        'web_search' => ['web_search', 'Методы разошлись; позицию уверенно определил веб-поиск, и она есть среди кандидатов наших механизмов'],
        'consensus' => ['consensus', 'Согласны два независимых метода: ИИ-модель (Direct) и векторный поиск по каталогу'],
        'sorter' => ['sorter', 'ИИ-модель и модель-сортер согласны, что это услуга'],
        'ensemble' => ['ensemble', 'Методы разошлись; позицию выбрало повторное голосование по каталогу'],
        'ai' => ['ai', 'Выбрано автоматически, подробности не сохранились'],
        'web_search_ungrounded' => ['web_search', 'Методы разошлись; веб-поиск определил позицию, но без опоры на наши механизмы'],
    ];

    $postMinimal = ['items' => [['name' => 'ANSIMAR-400mg-N20-TAB'], ['name' => 'X7 NANO BLACK 4 SIQARET YENI']]];
    $postFull = ['items' => [
        ['name' => 'X7 NANO BLACK 4 SIQARET YENI', 'unit' => 'blok', 'quantity' => 10, 'series' => 'MT', 'number' => '1001',
            'invoice_date' => '01.10.2026', 'supplier_tin' => '1234567890', 'total_amount' => '85,00'],
        ['name' => 'Su Sirab 0.5', 'unit' => 'ədəd', 'quantity' => 24, 'series' => 'MT', 'number' => '1001'],
    ]];
    $accepted = ['request_id' => $requestId, 'status' => 'accepted', 'lines' => 2, 'names' => 0, 'answered' => 0,
        'created_at' => '2026-10-02T09:00:41+00:00', 'error' => null];
    $error422 = ['message' => 'Every item needs a name. (and 1 more error)', 'errors' => [
        'items.1.name' => ['Every item needs a name.'],
        'items.4.name' => ['A name can be at most '.($limits['max_name_length'] ?? 5000).' characters.'],
    ]];
    $status = ['request_id' => $requestId, 'status' => 'processing', 'lines' => 5, 'names' => 4, 'answered' => 3,
        'created_at' => '2026-10-02T09:00:41+00:00', 'error' => null, 'offset' => 0, 'limit' => $page, 'classified_items' => [
            ['name' => 'X7 NANO BLACK 4 SIQARET YENI', 'category' => '2402', 'similarity' => $sim('consensus', 0.93), 'kind' => 'good', 'units' => ['blok'],
                'status' => 'answered', 'method' => 'consensus',
                'reason' => 'Two independent methods agree: the AI model (Direct) chose 2402, and the catalog vector search ranks it #1 of 3.'],
            ['name' => 'Hesab-faktura 123', 'category' => null, 'similarity' => $sim('trash', 0.98), 'kind' => 'trash', 'units' => [],
                'status' => 'answered', 'method' => 'trash', 'reason' => 'Only a document reference, date or period — no product is named.'],
            ['name' => 'Fuga 2', 'category' => null, 'similarity' => null, 'kind' => null, 'units' => [], 'status' => 'needs_review',
                'method' => 'needs_human', 'reason' => 'The methods diverged; the web search suggests 9504 but is not confident enough (60%) — a human needs to decide.'],
            ['name' => 'ANSIMAR-400mg-N20-TAB', 'category' => null, 'similarity' => null, 'kind' => null, 'units' => [], 'status' => 'pending',
                'method' => 'in_progress', 'reason' => 'Still being classified.'],
        ]];
    $resultsExample = ['id' => 2181, 'batch' => $requestId, 'source_text' => 'X7 NANO BLACK 4 SIQARET YENI', 'kind' => 'good',
        'resolution' => 'agreed', 'final_code' => '2402', 'method' => 'consensus',
        'reason' => 'Two independent methods agree: the AI model (Direct) chose 2402, and the catalog vector search ranks it #1 of 3.',
        'final_name' => null, 'confirmed_by' => null, 'confirmed_at' => null, 'results' => [
            ['mechanism' => 'vector', 'matched_code' => '2402100000', 'top_headings' => ['2402', '8543', '2403'], 'kind' => 'good',
                'confidence' => 0.653, 'status' => 'auto_confirmed', 'model' => 'gpu:base', 'candidates' => [['code' => '2402100000', 'kind' => 'good', 'score' => 0.6531]]],
            ['mechanism' => 'direct', 'matched_code' => '2402', 'kind' => 'good', 'confidence' => 0.9, 'status' => 'auto_confirmed', 'model' => 'gpu:tuned',
                'explanation' => 'SIQARET means cigarette; a physical good, classified under heading 2402.'],
            ['mechanism' => 'sorter', 'kind' => 'good', 'confidence' => 0.9997, 'status' => 'sorted', 'explanation' => 'Sorter: good 100%, trash 0%, service 0%.'],
        ]];
    $uploadsExample = ['batch' => $requestId, 'total' => 2, 'returned' => 2, 'limit' => 200, 'resolutions' => ['agreed' => 2], 'items' => [
        ['id' => 2187, 'batch' => $requestId, 'source_text' => 'Marlboro Gold', 'kind' => 'good', 'resolution' => 'agreed', 'final_code' => '2402',
            'method' => 'memory', 'reason' => 'The name matched a verified answer in memory (source: an earlier unanimous AI decision).',
            'mechanisms' => ['cache' => ['code' => '2402', 'status' => 'auto_confirmed', 'confidence' => 1]]],
    ]];

    $toc = [
        'overview' => 'Обзор',
        'start' => 'Быстрый старт',
        'auth' => 'Авторизация',
        'flow' => 'Как проходит запрос',
        'post-classify' => ['POST', '/classify'],
        'get-classify' => ['GET', '/classify/{id}'],
        'health' => ['GET', '/health-check'],
        'version' => ['GET', '/version'],
        'results' => ['GET', '/results, /uploads'],
        'errors' => 'Коды ответов',
        'tips' => 'Рекомендации',
    ];

    // Code samples (__BASE__ / __PAGE__ are filled in by \$sample).
    $code1 = $sample(<<<'TXT'
TOKEN="1|…"   # Настройки → API-токены

# 1. отправить позиции
curl -X POST __BASE__/classify \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"items":[{"name":"ANSIMAR-400mg-N20-TAB"},{"name":"X7 NANO BLACK 4 SIQARET YENI"}]}'
# → {"request_id":"033efd90-57f2-4c47-a49e-07632c5d218f","status":"accepted",...}

# 2. забрать ответы — повторять, пока "status" не станет "done"
curl __BASE__/classify/033efd90-57f2-4c47-a49e-07632c5d218f \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
TXT);
    $code2 = $sample(<<<'TXT'
curl -X POST __BASE__/classify \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"items":[{"name":"ANSIMAR-400mg-N20-TAB"},{"name":"X7 NANO BLACK 4 SIQARET YENI","unit":"blok"}]}'
TXT);
    $code3 = $sample(<<<'TXT'
import requests

BASE = "__BASE__"
HEADERS = {"Authorization": f"Bearer {TOKEN}", "Accept": "application/json"}

response = requests.post(f"{BASE}/classify", headers=HEADERS, json={
    "items": [
        {"name": "ANSIMAR-400mg-N20-TAB"},
        {"name": "X7 NANO BLACK 4 SIQARET YENI", "unit": "blok"},
    ],
})
response.raise_for_status()              # 202 Accepted
request_id = response.json()["request_id"]
TXT);
    $code4 = $sample(<<<'TXT'
$http = new \GuzzleHttp\Client([
    'base_uri' => '__BASE__/',
    'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'],
]);

$response = $http->post('classify', ['json' => ['items' => [
    ['name' => 'ANSIMAR-400mg-N20-TAB'],
    ['name' => 'X7 NANO BLACK 4 SIQARET YENI', 'unit' => 'blok'],
]]]);                                    // 202 Accepted
$requestId = json_decode((string) $response->getBody(), true)['request_id'];
TXT);
    $code5 = $sample(<<<'TXT'
curl "__BASE__/classify/$REQUEST_ID?offset=0&limit=__PAGE__" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
TXT);
    $code6 = $sample(<<<'TXT'
import time

# ждём, пока автоматика ответит по всем названиям
while True:
    state = requests.get(f"{BASE}/classify/{request_id}", headers=HEADERS).json()
    if state["status"] in ("done", "failed"):
        break
    time.sleep(15)

# забираем все ответы постранично
answers, offset = [], 0
while offset < state["names"]:
    page = requests.get(f"{BASE}/classify/{request_id}", headers=HEADERS,
                        params={"offset": offset, "limit": __PAGE__}).json()
    answers += page["classified_items"]
    offset += page["limit"]

by_name = {a["name"]: a for a in answers}    # имена — ровно как отправляли
TXT);
    $code7 = $sample(<<<'TXT'
// ждём, пока автоматика ответит по всем названиям
do {
    sleep(15);
    $state = json_decode((string) $http->get("classify/$requestId")->getBody(), true);
} while (! in_array($state['status'], ['done', 'failed'], true));

// забираем все ответы постранично
$answers = [];
for ($offset = 0; $offset < $state['names']; $offset += __PAGE__) {
    $page = json_decode((string) $http->get("classify/$requestId", [
        'query' => ['offset' => $offset, 'limit' => __PAGE__],
    ])->getBody(), true);
    array_push($answers, ...$page['classified_items']);
}
TXT);
    $codeHealth = $sample(<<<'TXT'
curl __BASE__/health-check \
  -H "Authorization: Bearer $TOKEN"
TXT);
    $codeVersion = $sample(<<<'TXT'
curl __BASE__/version \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
TXT);


    // Tables.
    $flowSteps = [
        ['accepted', 'Принят', 'Тело сохранено, запрос ждёт разбора. Обычно секунды.'],
        ['processing', 'В работе', 'Названия идут по конвейеру; ответы появляются по одному.'],
        ['done', 'Готово', 'Автоматика ответила по всем названиям.'],
        ['failed', 'Ошибка', 'Запрос не удалось прочитать — причина в error.'],
    ];
    $requestFields = [
        'status' => 'accepted → processing → done, или failed',
        'lines' => 'Сколько позиций было в запросе',
        'names' => 'Сколько разных названий — записей в classified_items',
        'answered' => 'По скольким названиям автоматика уже закончила',
        'error' => 'Причина, если status = failed',
        'classified_items' => 'Ответы этой страницы (offset, limit)',
    ];
    $itemFields = [
        'name' => 'Название ровно так, как его прислали',
        'category' => 'Код XİF MN: обычно позиция из 4 знаков (2402); 99 — услуга; 10 знаков, если человек выбрал точный код. null — мусор или ответа пока нет',
        'kind' => 'good — товар, service — услуга, trash — в строке не назван товар (номер документа, дата, ФИО…)',
        'units' => 'Единицы измерения, с которыми название пришло в строках запроса; [] если не присылали',
        'status' => 'pending — классифицируется; answered — есть ответ; needs_review — решит человек',
        'similarity' => 'Насколько можно доверять ответу (таблица ниже); null, пока ответа нет',
        'method' => 'Каким способом найден ответ; пока его нет — in_progress или needs_human',
        'reason' => 'Почему такой ответ — коротко, по-английски',
    ];
    $healthChecks = [
        'database' => 'База данных отвечает',
        'redis' => 'Очередь отвечает',
        'workers' => 'Воркеры очереди запущены и не на паузе — иначе принятые запросы не обработаются',
        'sorter' => 'Сервис модели-сортера жив',
        'embedder' => 'В Ollama есть модель эмбеддингов',
        'vector search' => 'Работает поиск ближайших соседей по каталогу',
        'llm' => 'Модели, которые принимают решения, всё ещё есть у своих провайдеров (список моделей кешируется на 5 минут)',
    ];
    $versionFields = [
        'model_version' => 'Версия классификатора целиком — меняется при смене любой модели, порога, промпта или правила, от которых зависят ответы',
        'trained_at' => 'Дата самой свежей модели, которую обучали мы',
        'api_version' => 'Версия этого API (маршруты и поля)',
        'components' => 'Модели, на которых цепочка настроена прямо сейчас; выключенные части не показываются. direct = gpu:… — модель на своём GPU-сервере, пока он не запущен, работает direct_fallback',
    ];
    $errorCodes = [
        ['200', 'Успешный GET', 'JSON (health-check — текст)'],
        ['202', 'POST /api/classify принят', '{"request_id": "…", "status": "accepted", …}'],
        ['401', 'Нет токена, он неверный или удалён', '{"message": "Unauthenticated."}'],
        ['403', 'У токена нет нужного права', '{"message": "Invalid ability provided."}'],
        ['404', 'Нет такого запроса / позиции', '{"message": "No such request."}'],
        ['422', 'Тело запроса не прошло проверку', '{"message": "…", "errors": {"items.1.name": ["…"]}}'],
        ['500', 'health-check: что-то не работает', 'Health check failed — …'],
    ];

    $th = 'font-medium py-2 pr-4 align-bottom';
    $td = 'py-2.5 pr-4 align-top';
@endphp

<section class="p-5 sm:p-8">
  <div class="max-w-[1180px] lg:grid lg:grid-cols-[210px_minmax(0,1fr)] lg:gap-10">

    {{-- Contents --}}
    <aside class="hidden lg:block">
      <nav class="sticky top-6 text-sm">
        <p class="kicker mb-3">Содержание</p>
        @foreach($toc as $id => $item)
          <a href="#{{ $id }}" class="flex items-center gap-2 py-1.5 text-muted hover:text-ink">
            @if(is_array($item))
              @include('pages.partials.api-method', ['method' => $item[0]])<span class="font-mono text-xs">{{ $item[1] }}</span>
            @else
              {{ $item }}
            @endif
          </a>
        @endforeach
      </nav>
    </aside>

    <div class="min-w-0 space-y-6">

      {{-- Overview --}}
      <div id="overview" class="scroll-mt-6">
        <p class="kicker mb-1.5">{{ __('API docs') }}</p>
        <h1 class="font-display text-4xl mb-3">API классификатора</h1>
        <p class="text-muted max-w-[720px]">
          Отправляете названия товаров и услуг из накладных — получаете для каждого код XİF MN, тип (товар, услуга или мусор),
          насколько ответу можно доверять и почему он такой. Всё через HTTP и JSON, с токеном в заголовке.
        </p>
        <div class="card-flat p-4 mt-5 flex flex-wrap items-center gap-x-6 gap-y-3">
          <div class="min-w-0">
            <p class="kicker mb-1">Базовый адрес</p>
            <p class="font-mono text-sm break-all">{{ $base }}</p>
          </div>
          <div class="flex flex-wrap gap-2 text-xs">
            <span class="px-2 py-1 rounded-md bg-line/40">JSON</span>
            <span class="px-2 py-1 rounded-md bg-line/40">Authorization: Bearer</span>
            <span class="px-2 py-1 rounded-md bg-line/40">асинхронно</span>
            <span class="px-2 py-1 rounded-md bg-line/40">до {{ $nf($limits['max_items'] ?? 100000) }} позиций в запросе</span>
          </div>
        </div>
      </div>

      {{-- Quick start --}}
      <div id="start" class="card p-6 scroll-mt-6">
        <h2 class="font-display text-2xl mb-4">Быстрый старт</h2>
        <ol class="space-y-2 mb-5 text-sm">
          <li><span class="font-semibold">1.</span> Получите токен: <a href="{{ route('settings') }}" class="link-under">Настройки → API-токены</a>, право «{{ $abilities['classify'] ?? 'classify' }}». Токен показывается один раз — сохраните его.</li>
          <li><span class="font-semibold">2.</span> Отправьте позиции: <span class="font-mono">POST /api/classify</span> — сразу вернётся <span class="font-mono">request_id</span>.</li>
          <li><span class="font-semibold">3.</span> Забирайте ответы: <span class="font-mono">GET /api/classify/{request_id}</span> раз в 10–30 секунд, пока <span class="font-mono">status</span> не станет <span class="font-mono">done</span>.</li>
        </ol>
        @include('pages.partials.api-code', ['code' => $code1])
      </div>

      {{-- Auth --}}
      <div id="auth" class="card p-6 scroll-mt-6">
        <h2 class="font-display text-2xl mb-3">Авторизация</h2>
        <p class="text-sm text-muted mb-4">
          Каждый запрос — с токеном в заголовке <span class="font-mono">Authorization</span>. Токены выпускают и удаляют в
          <a href="{{ route('settings') }}" class="link-under">Настройках → API-токены</a>. Токен виден один раз, сразу после создания:
          хранится только его хеш, восстановить нельзя — только выпустить новый. Там же видно, когда токен использовали последний раз.
        </p>
        @include('pages.partials.api-code', ['code' => "Authorization: Bearer <token>\nAccept: application/json"])
        <div class="overflow-x-auto mt-5">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-muted border-b hair"><th class="{{ $th }}">Право токена</th><th class="{{ $th }}">Что открывает</th></tr></thead>
            <tbody>
              <tr class="border-b hair">
                <td class="{{ $td }}"><span class="font-mono">classify</span><div class="text-faint text-xs">{{ $abilities['classify'] ?? '' }}</div></td>
                <td class="{{ $td }} font-mono text-xs">POST /api/classify<br>GET /api/classify/{id}</td>
              </tr>
              <tr class="border-b hair">
                <td class="{{ $td }}"><span class="font-mono">results</span><div class="text-faint text-xs">{{ $abilities['results'] ?? '' }}</div></td>
                <td class="{{ $td }} font-mono text-xs">GET /api/results/{item}<br>GET /api/uploads/{batch}</td>
              </tr>
              <tr>
                <td class="{{ $td }}">любой действующий токен</td>
                <td class="{{ $td }} font-mono text-xs">GET /api/health-check<br>GET /api/version</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="text-sm text-muted mt-4">
          Нет токена или он неверный/удалён → <span class="font-mono">401</span> <span class="font-mono text-xs">{"message": "Unauthenticated."}</span>.
          У токена нет нужного права → <span class="font-mono">403</span> <span class="font-mono text-xs">{"message": "Invalid ability provided."}</span>.
        </p>
      </div>

      {{-- Flow --}}
      <div id="flow" class="card p-6 scroll-mt-6">
        <h2 class="font-display text-2xl mb-3">Как проходит запрос</h2>
        <p class="text-sm text-muted mb-5">
          Классификация занимает время: память проверенных ответов, правила, модель-сортер, ИИ-механизмы, веб-поиск при расхождении,
          в конце — проверка людьми. Поэтому API асинхронный: запрос принимается сразу, ответы появляются по мере готовности.
        </p>
        <div class="grid sm:grid-cols-4 gap-3 text-sm mb-5">
          @foreach($flowSteps as [$code, $title, $text])
            <div class="card-flat p-4">
              <p class="font-mono text-xs {{ $code === 'failed' ? 'text-stamp' : ($code === 'done' ? 'text-ledger' : 'text-muted') }}">{{ $code }}</p>
              <p class="font-medium mt-1">{{ $title }}</p>
              <p class="text-muted text-xs mt-1">{{ $text }}</p>
            </div>
          @endforeach
        </div>
        <ul class="text-sm space-y-1.5 list-disc pl-5">
          <li>Один запрос — одна загрузка в системе: она видна в «Очереди проверки» с подписью <span class="font-mono">API · &lt;имя токена&gt;</span> и проходит тот же конвейер, что загрузка файлом.</li>
          <li>Название, на которое автоматика не нашла уверенного ответа, получает статус <span class="font-mono">needs_review</span> — его решит человек, и ответ появится в том же запросе.</li>
          <li>Человек может исправить и уже выданный ответ. Чтобы забрать изменения, запросите результаты ещё раз.</li>
        </ul>
      </div>

      {{-- POST /api/classify --}}
      <div id="post-classify" class="card p-6 scroll-mt-6">
        <div class="flex flex-wrap items-center gap-3 mb-2">
          @include('pages.partials.api-method', ['method' => 'POST'])
          <h2 class="font-mono text-xl">/api/classify</h2>
          <span class="text-xs px-2 py-0.5 rounded-md bg-line/40">право classify</span>
        </div>
        <p class="text-sm text-muted mb-5">
          Отправить позиции на классификацию. Отвечает сразу — <span class="font-mono">202</span> и <span class="font-mono">request_id</span>;
          сама классификация идёт в фоне. Тело — JSON (<span class="font-mono">Content-Type: application/json</span>).
        </p>

        <h3 class="font-medium mb-2">Тело запроса</h3>
        <div class="overflow-x-auto mb-5">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-muted border-b hair"><th class="{{ $th }}">Поле</th><th class="{{ $th }}">Тип</th><th class="{{ $th }}">Обязательно</th><th class="{{ $th }}">Описание</th></tr></thead>
            <tbody>
              <tr class="border-b hair">
                <td class="{{ $td }} font-mono">items</td><td class="{{ $td }}">массив</td><td class="{{ $td }} text-stamp font-medium">да</td>
                <td class="{{ $td }}">Позиции — от 1 до {{ $nf($limits['max_items'] ?? 100000) }}</td>
              </tr>
              <tr class="border-b hair">
                <td class="{{ $td }} font-mono">items[].name</td><td class="{{ $td }}">строка</td><td class="{{ $td }} text-stamp font-medium">да</td>
                <td class="{{ $td }}">Название из накладной, до {{ $nf($limits['max_name_length'] ?? 5000) }} символов. Не пустое.</td>
              </tr>
              <tr>
                <td class="{{ $td }} font-mono">items[].&lt;поле&gt;</td><td class="{{ $td }}">—</td><td class="{{ $td }}">нет</td>
                <td class="{{ $td }}">Любое поле строки накладной (Şablon) из таблицы ниже</td>
              </tr>
            </tbody>
          </table>
        </div>

        <details class="mb-5">
          <summary class="cursor-pointer text-sm font-medium">Необязательные поля строки накладной ({{ count($fields) }})</summary>
          <div class="overflow-x-auto mt-3">
            <table class="w-full text-sm">
              <thead><tr class="text-left text-muted border-b hair"><th class="{{ $th }}">Поле</th><th class="{{ $th }}">Тип</th><th class="{{ $th }}">Что это</th></tr></thead>
              <tbody>
                @foreach($fields as $field)
                  <tr class="border-b hair last:border-0">
                    <td class="{{ $td }} font-mono text-xs">{{ $field }}</td>
                    <td class="{{ $td }} whitespace-nowrap">{{ $fieldInfo[$field][0] ?? '—' }}</td>
                    <td class="{{ $td }}">{{ $fieldInfo[$field][1] ?? '—' }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </details>

        <ul class="text-sm space-y-1.5 list-disc pl-5 mb-6">
          <li><span class="font-medium">В классификатор уходит только название.</span> Код поставщика и другие поля на ответ не влияют.</li>
          <li>Строка с полями накладной сохраняется так же, как строка из загруженного файла Şablon — её видно на странице «Накладные» и в ИИ-чате. Накладная с той же серией и номером, загруженная раньше, второй раз не пишется, но названия всё равно классифицируются.</li>
          <li>Строка только с названием накладную не создаёт. Неизвестные поля молча игнорируются.</li>
          <li>Повторная отправка — это новый запрос со своим <span class="font-mono">request_id</span>. Названия, на которые уже есть проверенные ответы, отвечаются мгновенно из памяти.</li>
        </ul>

        <div class="grid xl:grid-cols-2 gap-4 mb-6">
          @include('pages.partials.api-code', ['label' => 'Минимальный запрос', 'code' => $json($postMinimal)])
          @include('pages.partials.api-code', ['label' => 'С полями накладной', 'code' => $json($postFull)])
        </div>

        <div x-data="{ tab: 'curl' }" class="mb-6">
          <div class="flex items-center gap-1 mb-2">
            <p class="kicker mr-2">Пример</p>
            @foreach(['curl' => 'curl', 'python' => 'Python', 'php' => 'PHP'] as $tabKey => $tabName)
              <button type="button" @click="tab = '{{ $tabKey }}'" class="text-xs px-2.5 py-1 rounded-md transition"
                      :class="tab === '{{ $tabKey }}' ? 'bg-ink text-paper' : 'bg-surface border hair text-muted hover:text-ink'">{{ $tabName }}</button>
            @endforeach
          </div>
          <div x-show="tab === 'curl'">@include('pages.partials.api-code', ['code' => $code2])</div>
          <div x-show="tab === 'python'" x-cloak>@include('pages.partials.api-code', ['code' => $code3])</div>
          <div x-show="tab === 'php'" x-cloak>@include('pages.partials.api-code', ['code' => $code4])</div>
        </div>

        <h3 class="font-medium mb-2">Ответы</h3>
        <div class="space-y-4">
          @include('pages.partials.api-code', ['label' => '202 Accepted · заголовок Location: /api/classify/{request_id}', 'code' => $json($accepted)])
          @include('pages.partials.api-code', ['label' => '422 · тело не прошло проверку (первые 20 ошибок)', 'code' => $json($error422)])
        </div>
      </div>

      {{-- GET /api/classify/{id} --}}
      <div id="get-classify" class="card p-6 scroll-mt-6">
        <div class="flex flex-wrap items-center gap-3 mb-2">
          @include('pages.partials.api-method', ['method' => 'GET'])
          <h2 class="font-mono text-xl">/api/classify/{request_id}</h2>
          <span class="text-xs px-2 py-0.5 rounded-md bg-line/40">право classify</span>
        </div>
        <p class="text-sm text-muted mb-5">Состояние запроса и ответы по названиям — страницами. Неизвестный <span class="font-mono">request_id</span> → <span class="font-mono">404</span> <span class="font-mono text-xs">{"message": "No such request."}</span>.</p>

        <h3 class="font-medium mb-2">Параметры</h3>
        <div class="overflow-x-auto mb-5">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-muted border-b hair"><th class="{{ $th }}">Параметр</th><th class="{{ $th }}">Где</th><th class="{{ $th }}">По умолчанию</th><th class="{{ $th }}">Описание</th></tr></thead>
            <tbody>
              <tr class="border-b hair"><td class="{{ $td }} font-mono">request_id</td><td class="{{ $td }}">путь</td><td class="{{ $td }}">—</td><td class="{{ $td }}">uuid из ответа на POST</td></tr>
              <tr class="border-b hair"><td class="{{ $td }} font-mono">offset</td><td class="{{ $td }}">query</td><td class="{{ $td }}">0</td><td class="{{ $td }}">С какой записи начать</td></tr>
              <tr><td class="{{ $td }} font-mono">limit</td><td class="{{ $td }}">query</td><td class="{{ $td }}">{{ $nf($page) }}</td><td class="{{ $td }}">Сколько записей вернуть, максимум {{ $nf($limits['max_page_size'] ?? 2000) }}</td></tr>
            </tbody>
          </table>
        </div>

        @include('pages.partials.api-code', ['label' => '200 OK', 'code' => $json($status)])

        <div class="grid xl:grid-cols-2 gap-6 mt-6">
          <div>
            <h3 class="font-medium mb-2">Запрос целиком</h3>
            <table class="w-full text-sm">
              <tbody>
                @foreach($requestFields as $name => $text)
                  <tr class="border-b hair last:border-0"><td class="{{ $td }} font-mono text-xs">{{ $name }}</td><td class="{{ $td }}">{{ $text }}</td></tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <div>
            <h3 class="font-medium mb-2">Одно название</h3>
            <table class="w-full text-sm">
              <tbody>
                @foreach($itemFields as $name => $text)
                  <tr class="border-b hair last:border-0"><td class="{{ $td }} font-mono text-xs">{{ $name }}</td><td class="{{ $td }}">{{ $text }}</td></tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>

        <div class="card-flat p-4 mt-6 text-sm">
          <p class="font-medium mb-1">Названия — ровно как прислали</p>
          <p class="text-muted">
            Записи идут по одной на каждое разное название, в порядке первого появления; одинаковые строки схлопываются.
            Названия, которые отличаются регистром или пробелами, классифицируются один раз, но в ответе каждое написание — отдельной записью,
            поэтому сопоставлять можно простым сравнением строк. Регистр учитывается по-азербайджански (I ↔ ı, İ ↔ i), так что
            <span class="font-mono">ANSIMAR</span> и <span class="font-mono">ansimar</span> — разные названия.
          </p>
        </div>

        <h3 class="font-medium mt-6 mb-2">similarity</h3>
        <p class="text-sm text-muted mb-3">
          Это <span class="font-medium text-ink">не косинусная близость</span>, а измеренная доля верных ответов среди найденных тем же способом,
          на уровне 4-значной позиции. Удобно для порога: что ниже — на ручную проверку у себя. Значения — настройка системы;
          их пересчитывают по контрольным данным после смены моделей.
        </p>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-muted border-b hair"><th class="{{ $th }}">method</th><th class="{{ $th }}">Как найден ответ</th><th class="{{ $th }} text-right">similarity</th></tr></thead>
            <tbody>
              @foreach($similarity as $key => $value)
                <tr class="border-b hair last:border-0">
                  <td class="{{ $td }} font-mono text-xs">{{ $methodInfo[$key][0] ?? $key }}</td>
                  <td class="{{ $td }}">{{ $methodInfo[$key][1] ?? '—' }}</td>
                  <td class="{{ $td }} text-right tnum font-mono">{{ number_format((float) $value, 2) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        <div x-data="{ tab: 'curl' }" class="mt-6">
          <div class="flex items-center gap-1 mb-2">
            <p class="kicker mr-2">Пример</p>
            @foreach(['curl' => 'curl', 'python' => 'Python', 'php' => 'PHP'] as $tabKey => $tabName)
              <button type="button" @click="tab = '{{ $tabKey }}'" class="text-xs px-2.5 py-1 rounded-md transition"
                      :class="tab === '{{ $tabKey }}' ? 'bg-ink text-paper' : 'bg-surface border hair text-muted hover:text-ink'">{{ $tabName }}</button>
            @endforeach
          </div>
          <div x-show="tab === 'curl'">@include('pages.partials.api-code', ['code' => $code5])</div>
          <div x-show="tab === 'python'" x-cloak>@include('pages.partials.api-code', ['code' => $code6])</div>
          <div x-show="tab === 'php'" x-cloak>@include('pages.partials.api-code', ['code' => $code7])</div>
        </div>
      </div>

      {{-- GET /api/health-check --}}
      <div id="health" class="card p-6 scroll-mt-6">
        <div class="flex flex-wrap items-center gap-3 mb-2">
          @include('pages.partials.api-method', ['method' => 'GET'])
          <h2 class="font-mono text-xl">/api/health-check</h2>
          <span class="text-xs px-2 py-0.5 rounded-md bg-line/40">любой токен</span>
        </div>
        <p class="text-sm text-muted mb-5">
          Для мониторинга: работает ли всё, что нужно классификатору. Каждый пункт проверяется в момент запроса, быстро и бесплатно —
          ничего не классифицируется, поэтому под нагрузкой проверка не краснеет. Ответ — простой текст.
        </p>
        <div class="grid xl:grid-cols-2 gap-4 mb-5">
          @include('pages.partials.api-code', ['label' => 'Запрос', 'code' => $codeHealth])
          <div class="space-y-4">
            @include('pages.partials.api-code', ['label' => '200 OK', 'code' => 'Health check passed'])
            @include('pages.partials.api-code', ['label' => '500 · что именно не работает', 'code' => 'Health check failed — workers: no queue workers are running (Horizon is down); llm: TokenFactory (fallback, base) does not offer deepseek-ai/DeepSeek-V4-Flash-0731'])
          </div>
        </div>
        <table class="w-full text-sm">
          <thead><tr class="text-left text-muted border-b hair"><th class="{{ $th }}">Пункт</th><th class="{{ $th }}">Что проверяется</th></tr></thead>
          <tbody>
            @foreach($healthChecks as $name => $text)
              <tr class="border-b hair last:border-0"><td class="{{ $td }} font-mono text-xs whitespace-nowrap">{{ $name }}</td><td class="{{ $td }}">{{ $text }}</td></tr>
            @endforeach
          </tbody>
        </table>
      </div>

      {{-- GET /api/version --}}
      <div id="version" class="card p-6 scroll-mt-6">
        <div class="flex flex-wrap items-center gap-3 mb-2">
          @include('pages.partials.api-method', ['method' => 'GET'])
          <h2 class="font-mono text-xl">/api/version</h2>
          <span class="text-xs px-2 py-0.5 rounded-md bg-line/40">любой токен</span>
        </div>
        <p class="text-sm text-muted mb-5">Какая версия классификатора отвечает и на каких моделях. Ниже — настоящий ответ этой установки прямо сейчас.</p>
        <div class="grid xl:grid-cols-2 gap-4 mb-5">
          @include('pages.partials.api-code', ['label' => 'Запрос', 'code' => $codeVersion])
          @include('pages.partials.api-code', ['label' => '200 OK', 'code' => $json($version)])
        </div>
        <table class="w-full text-sm">
          <tbody>
            @foreach($versionFields as $name => $text)
              <tr class="border-b hair last:border-0"><td class="{{ $td }} font-mono text-xs">{{ $name }}</td><td class="{{ $td }}">{{ $text }}</td></tr>
            @endforeach
          </tbody>
        </table>
      </div>

      {{-- Results API --}}
      <div id="results" class="card p-6 scroll-mt-6">
        <div class="flex flex-wrap items-center gap-3 mb-2">
          @include('pages.partials.api-method', ['method' => 'GET'])
          <h2 class="font-mono text-xl">/api/results/{item} · /api/uploads/{batch}</h2>
          <span class="text-xs px-2 py-0.5 rounded-md bg-line/40">право results</span>
        </div>
        <p class="text-sm text-muted mb-5">
          Подробности для разбора: как именно принималось решение — каждый механизм, его кандидаты и уверенность.
          Для интеграции обычно хватает <span class="font-mono">/api/classify</span>; это — для тех, кто хочет заглянуть внутрь.
        </p>
        <div class="overflow-x-auto mb-5">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-muted border-b hair"><th class="{{ $th }}">Маршрут</th><th class="{{ $th }}">Параметры</th><th class="{{ $th }}">Что возвращает</th></tr></thead>
            <tbody>
              <tr class="border-b hair">
                <td class="{{ $td }} font-mono text-xs whitespace-nowrap">GET /api/results/{item}</td>
                <td class="{{ $td }}"><span class="font-mono">item</span> — id позиции</td>
                <td class="{{ $td }}">Одна позиция со всеми шагами решения</td>
              </tr>
              <tr>
                <td class="{{ $td }} font-mono text-xs whitespace-nowrap">GET /api/uploads/{batch}</td>
                <td class="{{ $td }}"><span class="font-mono">batch</span> — у API-запроса это его request_id;<br><span class="font-mono">limit</span> — 1…1000, по умолчанию 200;<br><span class="font-mono">resolution</span> — фильтр, например conflict</td>
                <td class="{{ $td }}">Позиции загрузки, кратко, со сводкой по исходам</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="space-y-4">
          @include('pages.partials.api-code', ['label' => 'GET /api/results/2181 · 200 OK (кандидаты и трассировки сокращены)', 'code' => $json($resultsExample)])
          @include('pages.partials.api-code', ['label' => 'GET /api/uploads/{batch}?limit=200 · 200 OK', 'code' => $json($uploadsExample)])
        </div>
      </div>

      {{-- Errors --}}
      <div id="errors" class="card p-6 scroll-mt-6">
        <h2 class="font-display text-2xl mb-4">Коды ответов</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-muted border-b hair"><th class="{{ $th }}">Код</th><th class="{{ $th }}">Когда</th><th class="{{ $th }}">Тело</th></tr></thead>
            <tbody>
              @foreach($errorCodes as [$code, $when, $body])
                <tr class="border-b hair last:border-0">
                  <td class="{{ $td }} font-mono {{ $code[0] === '2' ? 'text-ledger' : 'text-stamp' }}">{{ $code }}</td>
                  <td class="{{ $td }}">{{ $when }}</td>
                  <td class="{{ $td }} font-mono text-xs">{{ $body }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>

      {{-- Tips --}}
      <div id="tips" class="card p-6 scroll-mt-6">
        <h2 class="font-display text-2xl mb-4">Рекомендации</h2>
        <ul class="text-sm space-y-2 list-disc pl-5">
          <li>Опрашивайте <span class="font-mono">GET /api/classify/{id}</span> раз в 10–30 секунд. Новые названия идут через ИИ и занимают секунды–минуты; большие запросы — дольше.</li>
          <li>Если названий больше {{ $nf($page) }} — листайте страницы через <span class="font-mono">offset</span>, ориентируясь на <span class="font-mono">names</span>.</li>
          <li>Сохраняйте <span class="font-mono">request_id</span>: по нему результаты можно забрать в любой момент, в том числе после проверки людьми.</li>
          <li>Ставьте свой порог по <span class="font-mono">similarity</span>; <span class="font-mono">needs_review</span> и всё ниже порога — на ручную проверку.</li>
          <li>Больше {{ $nf($limits['max_items'] ?? 100000) }} позиций — разбейте на несколько запросов.</li>
          <li>Подключите <span class="font-mono">GET /api/health-check</span> к мониторингу — он сразу покажет, если пропали воркеры или провайдер убрал модель.</li>
        </ul>
      </div>

    </div>
  </div>
</section>

<script>
  // Copy without the clipboard API — it needs HTTPS, and the app may run on plain HTTP.
  function apiDocsCopy(text) {
    const area = document.createElement('textarea');
    area.value = text;
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    document.execCommand('copy');
    area.remove();
  }
</script>
</x-app-layout>
