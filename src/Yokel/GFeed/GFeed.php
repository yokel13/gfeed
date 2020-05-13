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
        'DETAIL_PICTURE', 'DETAIL_PAGE_URL', 'DETAIL_TEXT', 'PREVIEW_TEXT', 'PROPERTY_CML2_LINK'
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
        while ($arRes = $dbRes->GetNext()) {
            $morePhoto = [];
            if (!empty($arRes['PROPERTY_MORE_PHOTO_VALUE'])) {
                foreach ($arRes['PROPERTY_MORE_PHOTO_VALUE'] as $photoId) {
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
                'MORE_PHOTO' => $morePhoto
            ];

            if ($arRes['PROPERTY_CML2_LINK_VALUE'] > 0) {
                if (array_key_exists($arRes['PROPERTY_CML2_LINK_VALUE'], $parentCache)) {
                    $element['PARENT'] = $parentCache[$arRes['PROPERTY_CML2_LINK_VALUE']];
                } else {
                    $dbParent = \CIBlockElement::GetByID($arRes['PROPERTY_CML2_LINK_VALUE']);
                    if ($obParent = $dbParent->GetNextElement()) {
                        $arParent = $obParent->GetFields();
                        $arParent['PROPS'] = $obParent->GetProperties();

                        $element['PARENT'] = [
                            'ID' => $arParent['ID'],
                            'NAME' => $arParent['NAME'],
                            'LINK' => $this->getUrl($arParent["DETAIL_PAGE_URL"]),
                            'TEXT' => empty($arParent['PREVIEW_TEXT']) ?
                                strip_tags($arParent['DETAIL_TEXT']) :
                                strip_tags($arParent['PREVIEW_TEXT']),
                            'PROPS' => $arParent['PROPS']
                        ];
                        $parentCache[$arRes['PROPERTY_CML2_LINK_VALUE']] = $element['PARENT'];
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
            }

            $this->elements[] = $element;
        }
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
     * Setter iblockId
     * @param mixed $iblockId
     */
    public function setIblockId($iblockId) {
        $this->iblockId = $iblockId;
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

        // Цена товара с валютой
        $this->addMappingAll('price', function ($item) {
            return $item['PRICE']['PRICE'].' '.$item['PRICE']['CURRENCY'];
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
     * Добавляет кастомный маппинг для всех форматов
     * @param $name
     * @param $value
     */
    public function addMappingAll($name, $value) {
        $this->mappingXmlExt[$name] = $value;
        $this->mappingCsvExt[$name] = $value;
    }

}