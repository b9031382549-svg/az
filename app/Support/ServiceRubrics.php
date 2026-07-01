<?php

namespace App\Support;

/**
 * Azerbaijani titles for the 4-digit XİF MN service positions (chapter 99).
 * Service names in the catalog are flat, so position titles can't be derived
 * from a breadcrumb — these are hand-authored from the real member items of each
 * position (a CPA/CPC-based service classification). Subposition (6-digit) titles
 * are derived from their own leaves by the rubricator builder; this map covers
 * the 64 positions, which are the first fork of the services branch.
 */
final class ServiceRubrics
{
    /** @var array<string, string> */
    public const POSITIONS = [
        '9901' => 'Kənd təsərrüfatı bitkiçilik xidmətləri',
        '9902' => 'Meşə təsərrüfatı xidmətləri',
        '9903' => 'Balıqçılıq və su bitkiçiliyinə yardımçı xidmətlər',
        '9909' => 'Dağ-mədən və neft-qaz hasilatına yardımçı xidmətlər',
        '9913' => 'Toxuculuq materiallarının rənglənməsi və emalı xidmətləri',
        '9918' => 'Çap xidmətləri və çap məhsulları',
        '9925' => 'Metalların emalı və örtülməsi xidmətləri',
        '9933' => 'Metal məmulatları və avadanlıqların təmiri və quraşdırılması',
        '9935' => 'Elektrik enerjisi və qaz təchizatı xidmətləri',
        '9936' => 'Su təchizatı və təmizlənməsi xidmətləri',
        '9937' => 'Kanalizasiya və tullantı sularının təmizlənməsi',
        '9938' => 'Tullantıların yığılması və emalı xidmətləri',
        '9939' => 'Ətraf mühitin bərpası və təmizləmə xidmətləri',
        '9941' => 'Binaların tikintisi (ümumtikinti) işləri',
        '9942' => 'Mülki tikinti işləri (yollar, qurğular)',
        '9943' => 'İxtisaslaşmış tikinti işləri',
        '9945' => 'Avtomobil ticarəti üzrə xidmətlər',
        '9946' => 'Topdansatış ticarəti xidmətləri',
        '9947' => 'Pərakəndə satış ticarəti xidmətləri',
        '9949' => 'Quru nəqliyyatı xidmətləri',
        '9950' => 'Su nəqliyyatı xidmətləri',
        '9951' => 'Hava nəqliyyatı xidmətləri',
        '9952' => 'Anbar və nəqliyyata yardımçı xidmətlər',
        '9953' => 'Poçt və kuryer xidmətləri',
        '9955' => 'Yerləşmə (qonaqlama) xidmətləri',
        '9956' => 'İaşə (yemək) xidmətləri',
        '9958' => 'Kitablar və nəşriyyat məhsulları',
        '9959' => 'Kino, video və media hazırlanması xidmətləri',
        '9960' => 'Radio və televiziya yayımı xidmətləri',
        '9961' => 'Telekommunikasiya xidmətləri',
        '9962' => 'İnformasiya texnologiyaları və proqram təminatı xidmətləri',
        '9963' => 'Verilənlərin emalı və veb-hostinq xidmətləri',
        '9964' => 'Kredit və depozit (bank) xidmətləri',
        '9965' => 'Sığorta və təkrarsığorta xidmətləri',
        '9966' => 'Maliyyə bazarlarına yardımçı və broker xidmətləri',
        '9968' => 'Daşınmaz əmlak üzrə xidmətlər',
        '9969' => 'Hüquq və hüquqi məsləhət xidmətləri',
        '9970' => 'İdarəetmə və konsaltinq xidmətləri',
        '9971' => 'Memarlıq və mühəndislik xidmətləri',
        '9972' => 'Elmi tədqiqat və işləmələr xidmətləri',
        '9973' => 'Reklam və marketinq xidmətləri',
        '9974' => 'Dizayn və foto xidmətləri',
        '9975' => 'Baytarlıq xidmətləri',
        '9977' => 'İcarə və lizinq xidmətləri',
        '9978' => 'İşədüzəltmə (məşğulluq) xidmətləri',
        '9979' => 'Səyahət agentliyi xidmətləri',
        '9980' => 'Təhlükəsizlik və mühafizə xidmətləri',
        '9981' => 'Binaların təmizlənməsi və abadlaşdırma xidmətləri',
        '9982' => 'İnzibati və ofis dəstəyi xidmətləri',
        '9984' => 'Dövlət idarəetməsi üzrə inzibati xidmətlər',
        '9985' => 'Təhsil xidmətləri',
        '9986' => 'Səhiyyə (tibbi) xidmətləri',
        '9987' => 'Yaşayış təminatlı sosial xidmətlər',
        '9988' => 'Yaşayış təminatsız sosial xidmətlər',
        '9990' => 'Səhnə sənəti və əyləncə xidmətləri',
        '9991' => 'Kitabxana, arxiv və muzey xidmətləri',
        '9992' => 'Qumar və mərc oyunları xidmətləri',
        '9993' => 'İdman və istirahət xidmətləri',
        '9994' => 'Üzvlük təşkilatlarının xidmətləri',
        '9995' => 'Ayaqqabı və şəxsi əşyaların təmiri',
        '9996' => 'Kimyəvi təmizləmə və boyama xidmətləri',
        '9997' => 'Ev təsərrüfatlarının muzdlu işçi xidmətləri',
        '9998' => 'Ev təsərrüfatlarının fərdi istehlak xidmətləri',
        '9999' => 'Ekstraterritorial təşkilatların xidmətləri',
    ];

    public static function title(string $position): ?string
    {
        return self::POSITIONS[$position] ?? null;
    }
}
