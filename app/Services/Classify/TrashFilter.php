<?php

namespace App\Services\Classify;

use App\Models\ClassificationItem;
use App\Support\AzFold;

/**
 * The step right after the answer cache, BEFORE any AI: a line whose name says nothing about
 * WHAT was sold — only a contract / invoice / letter reference, a date or period, a bare
 * number, a vehicle plate, an e-mail or a company name — cannot be classified by anyone, so it
 * is marked 'trash' instead of being pushed through the paid pipeline (which would still force
 * some code onto it).
 *
 * Deliberately CONSERVATIVE. Measured on ~510k human-labelled names (the "Data samples for
 * SLM" file, ~12k TRASH): these rules flag ~2k names at ~98% precision — i.e. only the ~16%
 * of trash that is recognisable by FORM. Person names, bare brands and addresses look exactly
 * like goods ("Babayev" is chocolate, "Davidov" cigarettes, "Krakov" sausage), so they are
 * left to the pipeline. The same wording can be either: "müqaviləyə əsasən xidmət haqqı" names
 * a service (a fee), "müqaviləyə əsasən" alone names nothing — the test is whether ANY word
 * remains once the paperwork words are taken out.
 *
 * Everything is matched on AzFold'ed text, so "TARIXLI" / "tarixli" / "tarıxlı" are one word.
 */
final class TrashFilter
{
    /** Rule keys, in the order they are tried (also the vocabulary of the trace row). */
    public const RULES = ['no_letters', 'car_plate', 'email', 'company', 'paperwork'];

    /**
     * Paperwork stems (folded) that name a document, not a product — each may carry any run
     * of the suffixes below ("müqaviləyə" = muqavile+ye, "tarixinədək" = tarix+ine+dek).
     */
    private const DOC_STEMS = 'tarix|esas|muqavile|mugavile|muqavil|mektub|qaime|hesab|faktura|protokol|sifaris'
        .'|order|invoice|akt|qebz|odenis|sayli|nomreli|nomre|declaration|beyanname|razilasma|sened';

    private const DOC_SUFFIXES = 'e|a|ye|ya|in|un|nin|nun|den|dan|de|da|i|u|si|su|leri|lari|ler|lar|ine|ina|li|lu'
        .'|inde|inda|sine|sina|siz|dek|en|dir|s';

    /** Month names (folded AZ + EN) — any one case ending allowed ("Martda", "Aprelin"). */
    private const MONTHS_LATIN = 'yanvar|fevral|mart|aprel|may|iyun|iyul|avqust|sentyabr|sentabr|oktyabr|oktiyabr'
        .'|noyabr|dekabr|january|february|march|april|june|july|august|september|october|november|december';

    private const MONTH_SUFFIXES = 'i|u|a|e|da|de|dan|den|in|ya|ye|dek';

    /** Words that happen to START like a month but are goods: yeast, liquid, martini. */
    private const NOT_MONTHS = ['maya', 'maye', 'mayi', 'marta', 'martin'];

    /** Russian months with their case endings — a closed list, so "мартини" never matches. */
    private const MONTHS_CYRILLIC = 'январ[ьяею]|феврал[ьяею]|март[ае]?|апрел[ьяею]|ма[йяе]|июн[ьяею]|июл[ьяею]'
        .'|август[ае]?|сентябр[ьяею]|октябр[ьяею]|ноябр[ьяею]|декабр[ьяею]';

    /** The same paperwork in Russian — a closed list of endings, like the months. */
    private const DOC_CYRILLIC = 'договор(?:а|у|ом|е|ы|ов)?|сч[её]т(?:а|у|ом|е|ы|ов)?|накладн(?:ая|ой|ую|ые|ых)'
        .'|письм(?:о|а|у|ом)|оплат(?:а|ы|у|ой)|акт(?:а|у|ом|е|ы|ов)?';

    /** Filler that dates and references are glued together with (folded). */
    private const FILLER = [
        'il', 'ilin', 'ili', 'iller', 'ay', 'ayi', 'ayin', 'aylar', 'aylari', 'ayina', 'ayinda',
        'uzre', 'ucun', 'den', 'dan', 'dek', 'qeder', 'ci', 'cu', 'nci', 'ncu', 'li', 'lu',
        'ile', 've', 'arasi', 'dovr', 'dovru', 'rub', 'yarim', 'n', 'nr', 'i', 'ii', 'iii', 'iv',
        'the', 'of', 'for', 'and', 'to', 'from', 'dated',
        'за', 'от', 'по', 'г', 'год', 'года', 'месяц',
    ];

    /** Legal forms a bare company name ends with (folded). */
    private const LEGAL_FORMS = 'mmc|qsc|asc|llc|ltd|ооо|оао';

    /**
     * Human-readable reason per rule — in $locale (null = the current one). The trace row
     * stores it in English; the decision page shows it in the reviewer's language.
     */
    public static function explain(string $rule, ?string $locale = null): string
    {
        return match ($rule) {
            'no_letters' => __('Only digits and symbols — no product is named.', [], $locale),
            'car_plate' => __('A vehicle registration number, not a product.', [], $locale),
            'email' => __('An e-mail address, not a product.', [], $locale),
            'company' => __('Only a company name — no product is named.', [], $locale),
            'paperwork' => __('Only a document reference, date or period — no product is named.', [], $locale),
            default => __('Not a product.', [], $locale),
        };
    }

    /** Which rule marks $text as trash, or null when it may name a product. */
    public function reason(string $text): ?string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', $text));
        // Fold + drop combining marks (a stray "i̇" would otherwise split a word in two).
        $folded = (string) preg_replace('/\p{Mn}/u', '', AzFold::fold($plain));

        if (! preg_match('/\p{L}/u', $folded)) {
            return 'no_letters'; // the documented rule: a name of only digits is trash
        }
        if (preg_match('/^\d{2}[\s-]?[a-z]{2}[\s-]?\d{3}$/u', $folded)) {
            return 'car_plate';
        }
        if (preg_match('/^[\w.+-]+@[\w-]+\.[\w.]+$/u', $folded)) {
            return 'email';
        }
        // Only a company: short, ends with its legal form, nothing product-like around it
        // (digits, brackets or "_"/"--" mean a product line that merely names its supplier).
        if (count(explode(' ', $folded)) <= 4
            && preg_match('/(?<![\p{L}\p{N}])(?:'.self::LEGAL_FORMS.')[^\p{L}\p{N}]*$/u', $folded)
            && ! preg_match('/[\d()_]|--/u', $folded)) {
            return 'company';
        }

        return $this->contentWords($folded) === [] ? 'paperwork' : null;
    }

    /**
     * Resolve an item as trash when its name is. Writes a 'trash' trace row (which rule, why)
     * and sets the item's resolution — terminal, no mechanism jobs. Returns true when trashed.
     *
     * Never touches an item already decided (a human's confirm must not be flipped by a
     * re-dispatch) nor one a human took OUT of trash ("classify anyway" leaves the trace row
     * as 'overridden'). Both checks run only for the rare names the rules match.
     */
    public function apply(ClassificationItem $item): bool
    {
        $rule = $this->reason((string) $item->source_text);
        if ($rule === null || ! in_array($item->resolution, ['pending', 'trash'], true)) {
            return false;
        }
        if ($item->results()->where('mechanism', 'trash')->where('status', 'overridden')->exists()) {
            return false;
        }

        $item->results()->updateOrCreate(
            ['mechanism' => 'trash'],
            [
                'matched_code' => null,
                'catalog_id' => null,
                'kind' => null,
                'status' => 'trash',
                'confidence' => null,
                'candidates' => [],
                'explanation' => self::explain($rule, 'en'),
                'trace' => ['rule' => $rule],
                'model' => null,
            ],
        );

        $item->update([
            'resolution' => 'trash',
            'final_code' => null,
            'final_catalog_id' => null,
            'kind' => null,
        ]);

        // Trash is answered the instant it is recognised — no pipeline runs.
        ClassificationItem::markAnswered($item->id);

        return true;
    }

    /**
     * The words left once dates, numbers, paperwork words and their filler are taken out —
     * what the line says about the product itself.
     *
     * @return array<int, string>
     */
    private function contentWords(string $folded): array
    {
        $doc = '/^(?:(?:'.self::DOC_STEMS.')(?:'.self::DOC_SUFFIXES.')*|'.self::DOC_CYRILLIC.')$/u';
        $month = '/^(?:(?:'.self::MONTHS_LATIN.')(?:'.self::MONTH_SUFFIXES.')?|'.self::MONTHS_CYRILLIC.')$/u';

        // Next to a paperwork word, a number with a few letters glued in ("001/IE/18",
        // "AZE/18/021") is the document's number — drop it. Not a longer word glued to digits
        // ("9986901990-xidmət", "dolabı(0.9x0.4"): that is the product. And without a paperwork
        // word such a chunk may well be the product itself ("A-92", "15w40"), so it stays.
        preg_match_all('/\p{L}+/u', $folded, $m);
        if (array_filter($m[0], fn (string $w) => (bool) preg_match($doc, $w)) !== []) {
            $folded = (string) preg_replace_callback('/\S*\d\S*/u',
                fn (array $c) => preg_match_all('/\p{L}/u', $c[0]) <= 3 ? ' ' : $c[0], $folded);
            preg_match_all('/\p{L}+/u', $folded, $m);
        }

        return array_values(array_filter($m[0], fn (string $w) => ! in_array($w, self::FILLER, true)
            && ! preg_match($doc, $w)
            && (in_array($w, self::NOT_MONTHS, true) || ! preg_match($month, $w))));
    }
}
