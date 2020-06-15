<?php

/**
 * @author      Andrey Konovalov <hello@yokel.tech>
 * @copyright   Copyright (c), 2020 Andrey Konovalov
 * @license     MIT public license
 */
namespace Yokel\GFeed;

use Bitrix\Main\Loader,
    Bitrix\Main\Type\DateTime,
    Bitrix\Main\Context;

/**
 * Class GFeed
 *
 * @package Yokel
 */
class GFeed {

    const PRODUCT_AVAILABLE_XML = 'в наличии';
    const PRODUCT_NOT_AVAILABLE_XML = 'нет в наличии';
    const PRODUCT_NEW_XML = 'новый';
    const PRODUCTS_AVAILABLE_CSV = 'in stock';
    const PRODUCTS_NOT_AVAILABLE_CSV = 'out of stock';
    const PRODUCT_NEW_CSV = 'new';
    const FORMAT_XML = 'xml';
    const FORMAT_CSV = 'csv';
    const FORMAT_YML = 'yml';

    /**
     * @var string Протокол (http|https)
     */
    private $protocol;

    /**
     * @var string Идентификатор сайта
     */
    private $siteId;

    /**
     * @var array Параметры сайта
     */
    private $siteInfo = [];

    /**
     * @var int Идентификатор инфоблока с товарами
     */
    private $iblockId;

    /**
     * @var int Идентификатор родительского инфоблока (для торговых предложений)
     */
    private $parentIblockId;

    /**
     * @var string Код поля с ценой (для фильтрации)
     */
    private $priceField;

    /**
     * @var string Путь к файлу xml
     */
    private $fileNameXml;

    /**
     * @var string Путь к файлу csv
     */
    private $fileNameCsv;

    /**
     * @var string Путь к файлу yml
     */
    private $fileNameYml;

    /**
     * @var array Список товаров
     */
    private $elements = [];

    /**
     * @var array Сортировка для выборки товаров
     */
    private $sort = [];

    /**
     * @var array Выбираемые поля товара
     */
    private $selectFields = [
        'ID', 'IBLOCK_ID', 'CODE', 'TIMESTAMP_X', 'IBLOCK_SECTION_ID', 'NAME', 'PREVIEW_PICTURE', 'PROPERTY_MORE_PHOTO',
        'DETAIL_PICTURE', 'DETAIL_PAGE_URL', 'DETAIL_TEXT', 'PREVIEW_TEXT', 'PROPERTY_CML2_LINK', 'PROPERTY_SEASON'
    ];

    /**
     * @var array Фильтр для товаров
     */
    private $filter;

    /**
     * @var array Маппинг полей для xml
     */
    private $mappingXml = [
        'id' => '.ID',
        'title' => '.NAME',
        'link' => '.LINK',
        'description' => '.TEXT',
        'condition' => self::PRODUCT_NEW_XML,
        'availability' => '.AVAILABLE_XML',
        'image_link' => '.IMG',
        'identifier_exists' => 'no'
    ];

    /**
     * @var array Кастомный маппинг для xml
     */
    private $mappingXmlExt = [];

    /**
     * @var array Маппинг полей для csv
     */
    private $mappingCsv = [
        'id' => '.ID',
        'title' => '.NAME',
        'link' => '.LINK',
        'image_link' => '.IMG',
        'price' => '',
        'description' => '.TEXT',
        'availability' => '.AVAILABLE_CSV',
        'condition' => self::PRODUCT_NEW_CSV,
        'brand' => '',
        'google_product_category' => ''
    ];

    /**
     * @var array Маппинг полей для yml
     */
    private $mappingYml = [
        'url' => '.LINK',
        'currencyId' => 'RUB',
        'categoryId' => 'parent.SECTION_ID',
        'picture' => '.IMG',
        'model' => '.NAME',
        'description' => '.TEXT'
    ];

    /**
     * @var array Кастомный маппинг для yml
     */
    private $mappingYmlExt = [];

    /**
     * @var array Кастомный маппинг для csv
     */
    private $mappingCsvExt = [];

    /**
     * @var bool Режим отладки
     */
    public $debug = false;

    /**
     * Получает информацию о сайте из БД
     * @return void
     */
    private function getSiteInfo() {
        $dbSite = \Bitrix\Main\SiteTable::getList([
            'filter' => [
                'LID' => $this->siteId
            ]
        ]);
        if ($arSite = $dbSite->fetch()) {
            $this->siteInfo = [
                'siteName' => $arSite['NAME'],
                'serverName' => $arSite['SERVER_NAME'],
                'url' => $this->protocol.$arSite['SERVER_NAME']
            ];
        }
    }

    /**
     * Формирует url относительно сайта
     * @param $url
     * @return string
     */
    private function getUrl($url) {
        return $this->siteInfo['url'].$url;
    }

    /**
     * Поля для выборки
     */
    private function createSelectFields() {
        $this->selectFields[] = $this->priceField;
    }

    /**
     * Создаёт фильтр для выборки
     */
    private function createFilter() {
        $this->filter = [
            'IBLOCK_ID' => $this->iblockId,
            'ACTIVE' => 'Y',
            '>='.$this->priceField => 0
        ];
    }

    /**
     * Возвращает цену товара
     * @param $productId
     * @return array
     */
    private function getPrice($productId) {
        $arPrice = \CCatalogProduct::GetOptimalPrice($productId, 1, [], 'N', [], $this->siteId);

        return [
            'PRICE' => $arPrice['RESULT_PRICE']['DISCOUNT_PRICE'],
            'CURRENCY' => $arPrice['RESULT_PRICE']['CURRENCY']
        ];
    }

    /**
     * Получает информацию о разделе
     * @param $id
     * @return mixed
     */
    private function getSection($id) {
        $dbRes = \CIBlockSection::GetByID($id);
        return $dbRes->GetNext();
    }

    /**
     * Получает список товаров
     */
    private function obtainElements() {
        // var
        $parentCache = [];

        // в режиме отладки найти 1 товар
        if ($this->debug) {
            $arNavStartParams = [
                'nTopCount' => 1
            ];
        } else {
            $arNavStartParams = false;
        }

        $dbRes = \CIBlockElement::GetList($this->sort, $this->filter, false, $arNavStartParams, $this->selectFields);
        while ($obRes = $dbRes->GetNextElement()) {
            $arRes = $obRes->GetFields();
            $arRes['PROPS'] = $obRes->GetProperties();

            $morePhoto = [];
            if (!empty($arRes['PROPS']['MORE_PHOTO']['VALUE'])) {
                foreach ($arRes['PROPS']['MORE_PHOTO']['VALUE'] as $photoId) {
                    $morePhoto[] = $this->getUrl(\CFile::GetPath($photoId));
                }
            }

            // картинка товара
            $img = false;
            if ($arRes['DETAIL_PICTURE'] > 0) {
                $img = $this->getUrl(\CFile::GetPath($arRes['DETAIL_PICTURE']));
            } elseif ($arRes['PREVIEW_PICTURE'] > 0) {
                $img = $this->getUrl(\CFile::GetPath($arRes['PREVIEW_PICTURE']));
            } elseif (!empty($morePhoto)) {
                $img = $morePhoto[0];
            }

            $element = [
                'ID' => $arRes['ID'],
                'NAME' => $arRes['NAME'],
                'SECTION_ID' => $arRes['IBLOCK_SECTION_ID'],
                'LINK' => $this->getUrl($arRes['DETAIL_PAGE_URL']),
                'IMG' => $img,
                'TEXT' => empty($arRes['PREVIEW_TEXT']) ?
                    strip_tags($arRes['DETAIL_TEXT']) :
                    strip_tags($arRes['PREVIEW_TEXT']),
                'AVAILABLE' => $arRes['CATALOG_QUANTITY'] > 0,
                'AVAILABLE_XML' => $arRes['CATALOG_QUANTITY'] > 0 ?
                    self::PRODUCT_AVAILABLE_XML :
                    self::PRODUCT_NOT_AVAILABLE_XML,
                'AVAILABLE_CSV' => $arRes['CATALOG_QUANTITY'] > 0 ?
                    self::PRODUCTS_AVAILABLE_CSV :
                    self::PRODUCTS_NOT_AVAILABLE_CSV,
                'PRICE' => $this->getPrice($arRes['ID']),
                'MORE_PHOTO' => $morePhoto,
                'PROPS' => $arRes['PROPS']
            ];

            if ($arRes['PROPERTY_CML2_LINK_VALUE'] > 0) {
                // это торговое предложение
                if (array_key_exists($arRes['PROPS']['CML2_LINK']['VALUE'], $parentCache)) {
                    $element['PARENT'] = $parentCache[$arRes['PROPS']['CML2_LINK']['VALUE']];
                } else {
                    $dbParent = \CIBlockElement::GetByID($arRes['PROPS']['CML2_LINK']['VALUE']);
                    if ($obParent = $dbParent->GetNextElement()) {
                        $arParent = $obParent->GetFields();
                        $arParent['PROPS'] = $obParent->GetProperties();

                        $element['PARENT'] = [
                            'ID' => $arParent['ID'],
                            'NAME' => $arParent['NAME'],
                            'LINK' => $this->getUrl($arParent["DETAIL_PAGE_URL"]),
                            'SECTION' => $this->getSection($arParent['IBLOCK_SECTION_ID']),
                            'TEXT' => empty($arParent['PREVIEW_TEXT']) ?
                                strip_tags($arParent['DETAIL_TEXT']) :
                                strip_tags($arParent['PREVIEW_TEXT']),
                            'PROPS' => $arParent['PROPS']
                        ];
                        $element['PARENT']['SECTION_ID'] = $element['PARENT']['SECTION']['ID'];
                        $element['PARENT']['SECTION_CODE'] = $element['PARENT']['SECTION']['CODE'];

                        $parentCache[$arRes['PROPS']['CML2_LINK']['VALUE']] = $element['PARENT'];
                    }
                }

                // если картинка не нашлась в ТП
                if (!$img) {
                    if ($element['PARENT']['DETAIL_PICTURE'] > 0) {
                        $element['IMG'] = $this->getUrl(\CFile::GetPath($element['PARENT']['DETAIL_PICTURE']));
                    } elseif ($element['PARENT']['PREVIEW_PICTURE'] > 0) {
                        $element['IMG'] = $this->getUrl(\CFile::GetPath($element['PARENT']['PREVIEW_PICTURE']));
                    } elseif (!empty($element['PARENT']['PROPS']['MORE_PHOTO']['VALUE'])) {
                        $element['IMG'] = $this->getUrl(
                            \CFile::GetPath($element['PARENT']['PROPS']['MORE_PHOTO']['VALUE'][0])
                        );
                    }
                }
            } else {
                // это простой товар
                $element['SECTION'] = $this->getSection($element['SECTION_ID']);
                $element['SECTION_CODE'] = $element['SECTION']['CODE'];
            }

            $this->elements[] = $element;
        }
    }

    /**
     * Получает разделы каталога
     * @return array
     */
    private function obtainSection() {
        // var
        $sections = [];

        $rsSections = \CIBlockSection::GetList(
            [
                'ID' => 'ASC'
            ],
            [
                'IBLOCK_ID' => $this->parentIblockId,
                'GLOBAL_ACTIVE' => 'Y'
            ]
        );
        while ($arSection = $rsSections->Fetch()) {
            $sections[] = $arSection;
        }

        return $sections;
    }

    /**
     * Вычисляет значение поля
     * @param $field
     * @param $item
     * @return mixed
     */
    private function getValue($field, $item) {
        if (is_callable($field)) {
            $value = $field($item);
        } else {
            $macro = explode('.', $field);
            if ($macro[0] === '' || $macro[0] === 'element') {
                if (is_array($item[$macro[1]])) {
                    $value = reset($item[$macro[1]]);
                } else {
                    $value = $item[$macro[1]];
                }
            } elseif ($macro[0] === 'parent') {
                $value = $item['PARENT'][$macro[1]];
            } else {
                $value = $field;
            }
        }

        return $value;
    }

    /**
     * @param $string
     * @return null|string|string[]
     */
    private function utf8_for_xml($string) {
        return preg_replace (
            '/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u',
            ' ',
            $string
        );
    }

    /**
     * Записывает xml в файл
     * @param $str
     * @return bool|int
     */
    private function writeXml($str) {
        return file_put_contents($this->fileNameXml, $str.PHP_EOL, FILE_APPEND);
    }

    /**
     * Записывает узел в xml
     * @param $item
     */
    private function writeItemNode($item) {
        // открыть
        $this->writeXml('<item>');

        // контент
        foreach ($this->mappingXml as $tag=>$field) {
            $str = sprintf('<g:%s>%s</g:%s>', $tag, $this->getValue($field, $item), $tag);
            $this->writeXml($str);
        }

        // закрыть
        $this->writeXml('</item>');
    }

    /**
     * Заполняет xml-файл
     */
    private function createXml() {
        // сначала удалить старый файл
        if (file_exists($this->fileNameXml)) {
            unlink($this->fileNameXml);
        }

        // маппинг
        $this->mappingXml = array_merge($this->mappingXml, $this->mappingXmlExt);

        // заголовок
        $this->writeXml('<?xml version="1.0"?>');
        $this->writeXml('<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">');
        $this->writeXml('<channel>');
        $this->writeXml('<title>'.$this->siteInfo['siteName'].'</title>');
        $this->writeXml('<link>'.$this->siteInfo['url'].'</link>');
        $this->writeXml('<description></description>');

        // записать товары
        foreach ($this->elements as $item) {
            $this->writeItemNode($item);
        }

        // закрыть теги
        $this->writeXml('</channel>');
        $this->writeXml('</rss>');
    }

    /**
     * Записывает строку в csv-файл
     * @param $csv_arr
     * @param string $delimiter
     * @param string $enclosure
     * @return bool|int
     */
    private function fputcsv($csv_arr, $delimiter = ';', $enclosure = '"') {
        if (!is_array($csv_arr)) {
            return(false);
        }

        for ($i = 0, $n = count($csv_arr); $i < $n;  $i ++) {
            if (!is_numeric($csv_arr[$i])) {
                $csv_arr[$i] =  $enclosure.str_replace($enclosure, $enclosure.$enclosure,  $csv_arr[$i]).$enclosure;
            }

            if (($delimiter === '.') && (is_numeric($csv_arr[$i]))) {
                $csv_arr[$i] =  $enclosure.$csv_arr[$i].$enclosure;
            }
        }
        $str = implode($delimiter,  $csv_arr).PHP_EOL;
        file_put_contents($this->fileNameCsv, $str, FILE_APPEND);

        return strlen($str);
    }

    /**
     * Формирует массив для записи в csv-файл
     * @param $item
     * @return bool|int
     */
    private function writeCsvRow($item) {
        // var
        $row = [];

        foreach ($this->mappingCsv as $tag=>$field) {
            $row[] = $this->getValue($field, $item);
        }

        return $this->fputcsv($row);
    }

    /**
     * Создаёт csv-файл
     */
    private function createCsv() {
        // Сначала удалить старый файл
        if (file_exists($this->fileNameCsv)) {
            unlink($this->fileNameCsv);
        }

        // Маппинг
        $this->mappingCsv = array_merge($this->mappingCsv, $this->mappingCsvExt);

        // Заголовки столбцов
        $this->fputcsv(array_keys($this->mappingCsv));

        // Товары
        foreach ($this->elements as $item) {
            $this->writeCsvRow($item);
        }
    }

    /**
     * Записывает yml в файл
     * @param $str
     * @param string $escapeChar
     * @return bool|int
     */
    private function writeYml($str, $escapeChar = PHP_EOL) {
        return file_put_contents($this->fileNameYml, $str.$escapeChar, FILE_APPEND);
    }

    /**
     * Открывает тег
     * @param $tag
     * @param array $params
     * @param bool $escape
     * @return bool|int
     */
    private function openTag($tag, $params = [], $escape = false) {
        $tag = "<$tag";
        if (!empty($params)) {
            $arParams = [];
            foreach ($params as $key=>$value) {
                $arParams[] = $key.'="'.$value.'"';
            }
            $tag .= ' '.implode(' ', $arParams);
        }
        $tag .= ">";

        return $this->writeYml($tag, $escape ? PHP_EOL : '');
    }

    /**
     * Закрывает тег
     * @param $tag
     * @return bool|int
     */
    private function closeTag($tag) {
        return $this->writeYml("</$tag>");
    }

    /**
     * Записывает тег
     * @param $tag
     * @param $value
     * @param array $params
     */
    private function addTag($tag, $value, $params = []) {
        $this->openTag($tag, $params);
        $this->writeYml($value, '');
        $this->closeTag($tag);
    }

    /**
     * Записывает offer в yml
     * @param $item
     */
    private function writeYmlOffer($item) {
        // открыть
        $this->openTag('offer', [
            'id' => $item['ID'],
            'type' => 'vendor.model',
            'available' => $item['AVAILABLE'] ? 'true' : 'false'
        ], true);

        // контент
        foreach ($this->mappingYml as $tag=>$field) {
            $this->addTag($tag, $this->getValue($field, $item));
        }

        // закрыть
        $this->closeTag('offer');
    }

    /**
     * Создаёт yml-файл
     */
    private function createYml() {
        // Сначала удалить старый файл
        if (file_exists($this->fileNameYml)) {
            unlink($this->fileNameYml);
        }

        // маппинг
        $this->mappingYml = array_merge($this->mappingYml, $this->mappingYmlExt);

        // заголовок
        $this->writeYml('<?xml version="1.0" encoding="utf-8"?>');
        $this->openTag('yml_catalog', [
            'date' => date('Y-m-d H:i')
        ], true);
        $this->openTag('shop', [], true);
        $this->addTag('name', $this->siteInfo['siteName']);
        $this->addTag('company', $this->siteInfo['siteName']);
        $this->addTag('agency', 'LIGHTHOUSE');
        $this->addTag('url', $this->siteInfo['url']);

        // валюты
        $this->openTag('currencies', [], true);
        $this->addTag('currency', '', [
            'id' => 'RUB',
            'rate' => 1
        ]);
        $this->closeTag('currencies');

        // категории
        $sections = $this->obtainSection();
        $this->openTag('categories', [], true);
        foreach ($sections as $section) {
            $sectParams = [
                'id' => $section['ID']
            ];
            if ($section['IBLOCK_SECTION_ID'] > 0) {
                $sectParams['parentId'] = $section['IBLOCK_SECTION_ID'];
            }
            $this->addTag('category', $section['NAME'], $sectParams);
        }
        $this->closeTag('categories');

        // товары
        $this->addTag('cpa', '1');
        $this->openTag('offers', [], true);
        foreach ($this->elements as $item) {
            $this->writeYmlOffer($item);
        }
        $this->closeTag('offers');

        // закрыть теги
        $this->closeTag('shop');
        $this->closeTag('yml_catalog');
    }

    /**
     * Setter iblockId
     * @param mixed $iblockId
     * @param $parentIblockId
     */
    public function setIblockId($iblockId, $parentIblockId = 0) {
        $this->iblockId = $iblockId;
        $this->parentIblockId = $parentIblockId;
    }

    /**
     * Setter priceField
     * @param mixed $priceField
     */
    public function setPriceField($priceField) {
        $this->priceField = $priceField;
    }

    /**
     * GFeed constructor.
     * @param string $protocol
     * @param bool $siteId
     */
    public function __construct($protocol = 'http', $siteId = null) {
        // use
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');
        Loader::includeModule('sale');

        // Параметры
        $this->siteId = $siteId ?? Context::getCurrent()->getSite();
        $this->protocol = $protocol.'://';
        $this->getSiteInfo();

        // Цена товара (с валютой для xml и csv)
        $this->addMappingCsv('price', function ($item) {
            return $item['PRICE']['PRICE'].' '.$item['PRICE']['CURRENCY'];
        });
        $this->addMappingXml('price', function ($item) {
            return $item['PRICE']['PRICE'].' '.$item['PRICE']['CURRENCY'];
        });
        $this->addMappingYml('price', function ($item) {
            return $item['PRICE']['PRICE'];
        });
    }

    /**
     * Экспорт файла в указанном формате
     * @param $fileName
     * @param string $format
     */
    public function export($fileName, $format = self::FORMAT_XML) {
        // инициализируем параметры для выборки
        $this->createSelectFields();
        $this->createFilter();

        // получение списка товаров
        if (empty($this->elements)) {
            $this->obtainElements();
        }

        switch ($format) {
            case self::FORMAT_XML:
                $this->fileNameXml = $_SERVER['DOCUMENT_ROOT'].$fileName;
                $this->createXml();
                break;
            case self::FORMAT_CSV:
                $this->fileNameCsv = $_SERVER['DOCUMENT_ROOT'].$fileName;
                $this->createCsv();
                break;
            case self::FORMAT_YML:
                $this->fileNameYml = $_SERVER['DOCUMENT_ROOT'].$fileName;
                $this->createYml();
                break;
        }
    }

    /**
     * Добавляет кастомный маппинг для xml
     * @param $name
     * @param $value
     */
    public function addMappingXml($name, $value) {
        $this->mappingXmlExt[$name] = $value;
    }

    /**
     * Добавляет кастомный маппинг для csv
     * @param $name
     * @param $value
     */
    public function addMappingCsv($name, $value) {
        $this->mappingCsvExt[$name] = $value;
    }

    /**
     * Добавляет кастомный маппинг для yml
     * @param $name
     * @param $value
     */
    public function addMappingYml($name, $value) {
        $this->mappingYmlExt[$name] = $value;
    }

    /**
     * Добавляет кастомный маппинг для всех форматов
     * @param $name
     * @param $value
     */
    public function addMappingAll($name, $value) {
        $this->mappingXmlExt[$name] = $value;
        $this->mappingCsvExt[$name] = $value;
        $this->mappingYmlExt[$name] = $value;
    }

}