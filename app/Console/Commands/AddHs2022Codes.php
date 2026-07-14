<?php

namespace App\Console\Commands;

use App\Support\AzFold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Add the HS-2022 headings that our HS-2017 registry is missing but that actually
 * occur in real invoices: 2404 (nicotine/vape products) and 3827 (halogenated
 * refrigerant mixtures). The AZ State Customs 2022 tariff file is not available to
 * us, so these entries follow the authoritative WCO HS-2022 six-digit structure;
 * the 10-digit code is the six-digit subheading + "0000" (national placeholder to
 * be overwritten if/when the official AZ 2022 file is imported). Names are the
 * official HS-2022 wording (EN/RU) plus an AZ rendering in the registry's style;
 * synonyms are tuned to the real invoice vocabulary (ELFBAR/HQD/VUSE, "birdəfəlik
 * elektron siqaret", "puffs", freon/xladagent…) so the vector actually retrieves them.
 *
 * Idempotent (upsert on code). Leaves embedding NULL so `data:embed-catalog` picks
 * the new rows up. Run `data:embed-catalog` afterwards.
 */
class AddHs2022Codes extends Command
{
    protected $signature = 'catalog:add-hs2022 {--dry : list what would change without writing}';

    protected $description = 'Add missing HS-2022 headings (2404 nicotine/vape, 3827 refrigerant mixtures) to the catalog';

    public function handle(): int
    {
        $h2404 = 'Tərkibində tütün, bərpa edilmiş tütün, nikotin və ya tütün və ya nikotin əvəzediciləri olan, yandırılmadan tənəffüs (inhalyasiya) yolu ilə istifadə üçün nəzərdə tutulmuş məhsullar; nikotinin insan orqanizminə daxil edilməsi üçün nəzərdə tutulmuş digər tütün və nikotin məhsulları';
        $h2404en = 'Products containing tobacco, reconstituted tobacco, nicotine, or tobacco or nicotine substitutes, intended for inhalation without combustion; other tobacco and nicotine products intended for the intake of nicotine into the human body';
        $h2404ru = 'Продукты, содержащие табак, восстановленный табак, никотин или заменители табака или никотина, предназначенные для вдыхания без горения; прочие содержащие никотин продукты, предназначенные для введения никотина в организм человека';

        $h3827 = 'Metanın, etanın və ya propanın halogenləşdirilmiş törəmələrini ehtiva edən qarışıqlar, başqa yerdə adı çəkilməyən və ya təsnif olunmayan';
        $h3827en = 'Mixtures containing halogenated derivatives of methane, ethane or propane, not elsewhere specified or included';
        $h3827ru = 'Смеси, содержащие галогенированные производные метана, этана или пропана, в другом месте не поименованные или не включенные';

        // vape/e-liquid vocabulary shared across the inhalation subheadings
        $vape = 'veyp, вейп, vape, elektron siqaret, электронная сигарета, e-cigarette, birdəfəlik elektron siqaret, disposable vape, elektron qəlyan, puff, puffs, pod, pods, pod sistem, ELFBAR, HQD, VUSE, Nasty Fix, Orion Bar, MR FOG, Vapengin, FRIZ, Lost Vape, Slap Juice';
        $refr = 'freon, фреон, xladagent, soyuducu qaz, soğuducu qaz, хладагент, refrigerant, refrigerant qaz, iqlim qazı, kondisioner qazı, R-407, R-407C, R-410, R-410A, R-134a, R-404A, R-32, HFC, ГФУ';

        $rows = [
            // ---- 2404: products for inhalation without combustion ----
            ['2404110000', 'yandırılmadan tənəffüs üçün nəzərdə tutulmuş məhsullar:– – tərkibində tütün və ya bərpa edilmiş tütün olan', 'products intended for inhalation without combustion:– – containing tobacco or reconstituted tobacco', 'продукты, предназначенные для вдыхания без горения:– – содержащие табак или восстановленный табак', $h2404, $h2404en, $h2404ru, 'IQOS, HEETS, isidilən tütün, qızdırılan tütün, tütün stik, tütün çubuğu, heated tobacco, heat-not-burn, стик, стики, табачные стики, айкос, glo, TENBEKI, tənbəki stik'],
            ['2404120000', 'yandırılmadan tənəffüs üçün nəzərdə tutulmuş məhsullar:– – tərkibində nikotin olan digərləri', 'products intended for inhalation without combustion:– – other, containing nicotine', 'продукты, предназначенные для вдыхания без горения:– – прочие, содержащие никотин', $h2404, $h2404en, $h2404ru, $vape.', nikotinli maye, veyp mayesi, elektron siqaret mayesi, e-maye, e-liquid, vape liquid, жидкость для вейпа, жидкость для электронных сигарет, никотиновая жидкость, salt nikotin, солевой никотин, mg nikotin'],
            ['2404190000', 'yandırılmadan tənəffüs üçün nəzərdə tutulmuş məhsullar:– – digərləri', 'products intended for inhalation without combustion:– – other', 'продукты, предназначенные для вдыхания без горения:– – прочие', $h2404, $h2404en, $h2404ru, 'nikotinsiz maye, nikotinsiz veyp mayesi, безникотиновая жидкость, nicotine-free e-liquid, aromatik veyp mayesi, nikotinsiz elektron siqaret'],
            // ---- 2404: other (oral / transdermal / other) ----
            ['2404910000', 'digərləri:– – oral (ağız vasitəsilə) istifadə üçün', 'other:– – for oral application', 'прочие:– – для перорального введения', $h2404, $h2404en, $h2404ru, 'nikotin yastıqcıqları, nikotin pauç, oral nikotin, ağız nikotini, snus, снюс, никотиновые подушечки, nicotine pouches, VELO, nikotinli pastil'],
            ['2404920000', 'digərləri:– – transdermal (dəri vasitəsilə) istifadə üçün', 'other:– – for transdermal application', 'прочие:– – для трансдермального введения', $h2404, $h2404en, $h2404ru, 'nikotin plasteri, nikotin yaması, никотиновый пластырь, nicotine patch, transdermal nikotin, dəri plasteri'],
            ['2404990000', 'digərləri:– – digərləri', 'other:– – other', 'прочие:– – прочие', $h2404, $h2404en, $h2404ru, 'digər nikotin məhsulları, nikotin məhsulu, nikotin əvəzediciləri, прочие никотиновые продукты, digər tütün məhsulları'],
            // ---- 3827: halogenated refrigerant mixtures (HFC blends etc.) ----
            ['3827310000', 'tərkibində hidroftoruglerodlar (HFC) olan, lakin CFC və ya HCFC olmayan:– – digərləri', 'containing hydrofluorocarbons (HFCs) but not chlorofluorocarbons (CFCs) or hydrochlorofluorocarbons (HCFCs):– – other', 'содержащие гидрофторуглероды (ГФУ), но не содержащие хлорфторуглероды (ХФУ) или гидрохлорфторуглероды (ГХФУ):– – прочие', $h3827, $h3827en, $h3827ru, $refr],
            ['3827390000', 'tərkibində hidroftoruglerodlar (HFC) olan:– – digərləri', 'containing hydrofluorocarbons (HFCs):– – other', 'содержащие гидрофторуглероды (ГФУ):– – прочие', $h3827, $h3827en, $h3827ru, $refr],
            ['3827900000', 'digərləri', 'other', 'прочие', $h3827, $h3827en, $h3827ru, $refr],
        ];

        $now = now();
        $added = 0;
        $updated = 0;
        foreach ($rows as [$code, $leafAz, $leafEn, $leafRu, $hAz, $hEn, $hRu, $syn]) {
            $name = $hAz.':– '.$leafAz;
            $nameEn = $hEn.':– '.$leafEn;
            $nameRu = $hRu.':– '.$leafRu;
            $searchText = AzFold::fold($name.' '.$syn);

            $attrs = [
                'name' => $name,
                'name_en' => $nameEn,
                'name_ru' => $nameRu,
                'unit' => null,
                'kind' => 'good',
                'chapter' => substr($code, 0, 2),
                'position' => substr($code, 0, 4),
                'subposition' => substr($code, 0, 6),
                'is_active' => true,
                'synonyms' => $syn,
                'search_text' => $searchText,
                'updated_at' => $now,
            ];

            $exists = DB::table('catalog')->where('code', $code)->exists();
            $this->line(($exists ? '~ ' : '+ ').$code.'  '.mb_substr($leafAz, 0, 60));

            if ($this->option('dry')) {
                continue;
            }

            if ($exists) {
                // keep any existing embedding? No — synonyms/name changed, force re-embed.
                DB::table('catalog')->where('code', $code)->update($attrs + ['embedding' => null, 'embedded_at' => null]);
                $updated++;
            } else {
                DB::table('catalog')->insert($attrs + ['code' => $code, 'created_at' => $now]);
                $added++;
            }
        }

        $this->newLine();
        $this->info($this->option('dry')
            ? 'dry run: '.count($rows).' entries would be upserted.'
            : "done: {$added} added, {$updated} updated (embedding=NULL → run `data:embed-catalog`).");

        return self::SUCCESS;
    }
}
