# -*- coding: utf-8 -*-
import json, textwrap

RES = '/home/b/az/storage/app/accuracy/results.jsonl'
VER = '/home/b/az/storage/app/accuracy/verdicts.json'
OUT = '/home/b/az/docs/ТЕСТ-ПО-ФЕДОРУ.md'

rows = [json.loads(l) for l in open(RES, encoding='utf-8') if l.strip()]
verd = json.load(open(VER, encoding='utf-8'))
by_name = {v['name']: v for v in verd}

def cell(p):
    if p is None: return '—'
    if p.get('service'): return 'SVC'
    return p.get('heading') or '—'

def esc(s):
    return (s or '').replace('|', '\\|').replace('\n', ' ').strip()

def short(s, n=58):
    s = ' '.join((s or '').split())
    return s if len(s) <= n else s[:n-1] + '…'

# ---- totals ----
cols = ['vector','broker','direct','search','ensemble']
def tally(subset):
    out = {}
    for c in cols:
        ok = sum(1 for r in subset if r.get(c+'_ok'))
        tot = sum(1 for r in subset if c in r)
        out[c] = (ok, tot)
    return out
gd = [r for r in rows if not r['gold_service']]
sv = [r for r in rows if r['gold_service']]
T, Tg, Ts = tally(rows), tally(gd), tally(sv)

L = []
w = L.append

w('# ТЕСТ ПО ФЕДОРУ')
w('')
w('Тест точности инструментов классификации XİF MN (Task 2) на эталоне Федора.')
w('')
w('- **Дата:** 2026-07-10')
w('- **Набор:** 100 позиций из `fedor_test_100.xlsx` = **77 товаров + 23 услуги**')
w('- **Эталон:** 4-значный HS-heading + флаг «услуга» (у Федора нет полного 10-значного кода)')
w('- **Подвыборка:** `validated` (там, где Claude и GPT сошлись) → лёгкая, числа оптимистичнее случайной выборки')
w('- **Команда:** `docker compose exec -T app php artisan classify:accuracy-test --fresh` — см. [accuracy-test.md](accuracy-test.md)')
w('- **Сырые данные:** `storage/app/accuracy/results.{jsonl,csv}`, спорные — `storage/app/accuracy/disputes.json`')
w('')
w('## Методология')
w('')
w('Каждый инструмент запускается **независимо** на одном и том же тексте, без answer-cache и очереди. Модели переопределены под ПРОД (сверено по SSH):')
w('')
w('| Инструмент | Модель (как на проде) | Веб-поиск |')
w('|---|---|---|')
w('| vector (`VectorMechanism`) | rerank/brief/expand = `deepseek-chat`, tier1 = `qwen-2.5-7b` | нет |')
w('| broker (`BrokerDescentMechanism`) | `deepseek-chat` | нет |')
w('| direct (`DirectLlmMechanism`) | `gpt-oss-120b` | нет |')
w('| search (`SearchResolverService`) | `deepseek-v4-flash:online` | **да** |')
w('| ensemble | консенсус 2-из-3 (vector/broker/direct) → иначе уверенный heading поиска | — |')
w('')
w('**Судья не тестировался** — на проде он спящий (`AdjudicateItemJob` нигде не диспатчится, 0 строк), живой тай-брейкер = поиск.')
w('')
w('**Скоринг:** услуга — верно, если инструмент тоже сказал «услуга»; товар — верно, если первые 4 цифры кода == heading Федора. Нет ответа = неверно. `search` форс-запускается на всех (в проде — только на conflict), чтобы получить свою метрику.')
w('')
w('## Результаты')
w('')
w('| Инструмент | Точность | Верно | Неверно | Товары | Услуги | Без ответа |')
w('|---|---|---|---|---|---|---|')
order = ['search','ensemble','broker','vector','direct']
abst = {c: sum(1 for r in rows if c in r and not r[c]['service'] and r[c]['heading'] is None) for c in cols}
for c in order:
    ok, tot = T[c]
    g_ok, g_tot = Tg[c]; s_ok, s_tot = Ts[c]
    w(f"| **{c.upper()}** | **{round(100*ok/tot)}%** | {ok} | {tot-ok} | {g_ok}/{g_tot} ({round(100*g_ok/g_tot)}%) | {s_ok}/{s_tot} ({round(100*s_ok/s_tot)}%) | {abst[c]} |")
w('')
w('**Порядок:** поиск (78%) > ансамбль (77%) > брокер (62%) > вектор (57%) > директ (25%).')
w('')
w('- **Поиск** — лучший: заново опознаёт товар в вебе, обходит лексические ловушки.')
w('- **Ансамбль ≈ поиск**, лучший на услугах (87%).')
w('- **Директ** проваливается: 49 «нет ответа», **на услугах 0/23** (kind=service не выдаёт ни разу). Как самостоятельный инструмент почти бесполезен и вредит консенсусу.')
w('')

# ---- adjudication ----
w('## Разбор спорных строк (ансамбль ≠ Федор): кто прав')
w('')
w('23 расхождения системы с Федором разобраны по правилам ГС (по одному арбитру на строку, с описаниями конкурирующих позиций).')
w('')
w('| Кто прав | Кол-во |')
w('|---|---|')
w('| ✅ Федор прав (тулы ошиблись) | 21 |')
w('| ❌ Тулы правы (Федор ошибся) | 1 |')
w('| ⚖️ Спорно даже у экспертов | 1 |')
w('')
w('**Вывод: эталон крепкий.** «Нечестной» точности из-за плохого gold почти нет — пересчёт даёт ансамблю ~78% вместо 77%. Промахи — реальные ошибки инструментов.')
w('')
w('### Таблица вердиктов')
w('')
w('Колонка «Кто прав» — по оси «правильный код совпал с Федором?»; пометка *(альт.)* = у инструмента был защитимый по ГС альтернативный вариант.')
w('')
w('| # | Товар | Федор | Верно | Кто прав | Почему (кратко) |')
w('|---|---|---|---|---|---|')

def who(v):
    if v['index'] == 9:  # disposable vape 2404 vs 8543 — unsettled even among experts
        return '⚖️ спорно'
    if v['correct_heading'] == v['fedor_pick']:
        return '✅ Федор' + (' *(альт.)*' if v['verdict'] == 'both_defensible' else '')
    return '❌ Тулы'

for v in verd:
    why = short(esc(v['reasoning']), 150)
    w(f"| {v['index']} | {esc(short(v['product_ru'],50))} | {v['fedor_pick']} | {v['correct_heading']} | {who(v)} | {why} |")
w('')
w('### Полное обоснование по каждой спорной строке')
w('')
for v in verd:
    d = next((r for r in json.load(open('/home/b/az/storage/app/accuracy/disputes.json', encoding='utf-8'))['rows'] if r['name']==v['name']), None)
    picks = d['picks'] if d else {}
    pk = ', '.join(f"{k}={val}" for k,val in picks.items())
    w(f"**[{v['index']}] {esc(v['name'])}**")
    w('')
    w(f"- Товар: {esc(v['product_ru'])}")
    w(f"- Федор: `{v['fedor_pick']}` · тулы: {pk} · **верно: `{v['correct_heading']}`** · кто прав: {who(v)} · арбитр: `{v['verdict']}` (conf {v['confidence']})")
    w(f"- {esc(v['reasoning'])}")
    w('')

w('## Главные причины промахов')
w('')
w('1. **Транслит / лексические ловушки** (самое частое): «motor»→двигатель (8407), «DUSEŞ/Дюшес»→инструменты, «podteypnik»→подшипник (8482), «с сахаром»→сахар (1701), «крем»→обувной (3405), «Göyçay/2022»→вода/календарь. Инструменты цепляются за токены, а не за суть товара.')
w('2. **Переработанный vs сырой / готовый vs тесто:** джем→свежие фрукты (0809 вместо 2007), хлебцы→хлеб (1905 вместо 1904), сгущёнка→сахар.')
w('3. **Косметика/БАД vs лекарство:** 3304/2106 путают с 3004 по «фарм»-ассоциации названия.')
w('4. **Тонкие правила ГС:** тара vs посуда (3923/3924), часть vs блок (8473/8471), новые коды HS-2022 (2404/8543 для вейпов).')
w('5. **Услуги:** директ (и часто search) не распознаёт не-товарные строки — ФИО, телеком-биллинг.')
w('')
w('## Находка: дыра не в моделях, а в ансамбле')
w('')
w('На 6+ строках правильный ответ у одного из инструментов **был** (обычно у поиска), но система его не выдала:')
w('')
w('- **Оффлайновый трио согласованно ошибается** → консенсус «2-из-3» фиксирует неверный код и **не спрашивает поиск**: сгущёнка (все три → 1701), джем (0809), крем (3004). Поиск знал верный ответ — проигнорирован.')
w('- **На conflict поиск угадал, но не прошёл порог уверенности 0.8** → ансамбль промолчал (насосы 8413).')
w('')
w('**Вывод:** ансамбль можно поднять с 77% примерно к ~83% **без смены моделей** — больше доверять поиску (он и так лучший): консультировать его даже при «согласии» трио и/или снизить порог его вмешательства.')
w('')

# ---- full product list ----
w('## Полный список 100 товаров с результатами')
w('')
w('Легенда: значение = 4-значный heading, `SVC` = услуга, `—` = нет ответа. Столбец **✓** — верен ли ответ системы (ансамбля).')
w('')
w('| # | Товар | Gold | Vec | Brk | Dir | Srch | **Ens** | ✓ |')
w('|---|---|---|---|---|---|---|---|---|')
for i, r in enumerate(rows, 1):
    g = 'SVC' if r['gold_service'] else (r['gold_heading'] or '—')
    ok = '✅' if r.get('ensemble_ok') else '❌'
    w(f"| {i} | {esc(short(r['name'],56))} | **{g}** | {cell(r.get('vector'))} | {cell(r.get('broker'))} | {cell(r.get('direct'))} | {cell(r.get('search'))} | **{cell(r.get('ensemble'))}** | {ok} |")
w('')
w('---')
w('*Отчёт сгенерирован из `storage/app/accuracy/results.jsonl` + адъюдикации 23 спорных строк.*')

open(OUT, 'w', encoding='utf-8').write('\n'.join(L))
print('wrote', OUT)
print('lines', len(L), '| products', len(rows), '| verdicts', len(verd))
