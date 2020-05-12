<?php
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

    const PRODUCT_AVAILABLE = 'в наличии';
    const PRODUCT_NOT_AVAILABLE = 'нет в наличии';
    const FORMAT_XML = 'xml';
    const FORMAT_CSV = 'csv';

    private $protocol;
    private $siteId;
    private $siteInfo = [];
    private $iblockId;
    private $priceField;
    private $fileName;
    private $elements = [];
    private $sort = [];
    private $selectFields = [
        "ID", "IBLOCK_ID", "CODE", "TIMESTAMP_X", "IBLOCK_SECTION_ID", "NAME", "PREVIEW_PICTURE",
        "DETAIL_PICTURE", "DETAIL_PAGE_URL", "DETAIL_TEXT", "PREVIEW_TEXT", 'PROPERTY_CML2_LINK'
    ];
    private $filter;

    private $mapping = [
        'id' => '.ID',
        'title' => '.NAME',
        'link' => '.LINK',
        'description' => '.TEXT',
        'condition' => 'новый',
        'availability' => '.AVAILABLE',
        'image_link' => '.IMG',
        'identifier_exists' => 'no'
    ];
    private $mappingExt = [];

    /**
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
     * @param $url
     * @return string
     */
    private function getUrl($url) {
        return $this->siteInfo['url'].$url;
    }

    /**
     *
     */
    private function createSelectFields() {
        $this->selectFields[] = $this->priceField;
    }

    /**
     *
     */
    private function createFilter() {
        $this->filter = [
            "IBLOCK_ID" => $this->iblockId,
            "ACTIVE" => "Y",
            ">=".$this->priceField => 0
        ];
    }

    /**
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
     *
     */
    private function obtainElements() {
        // var
        $parentCache = [];

        $dbRes = \CIBlockElement::GetList($this->sort, $this->filter, false, false, $this->selectFields);
        while ($arRes = $dbRes->GetNext()) {
            $element = [
                'ID' => $arRes['ID'],
                'NAME' => $arRes['NAME'],
                'SECTION_ID' => $arRes["IBLOCK_SECTION_ID"],
                'LINK' => $this->getUrl($arRes["DETAIL_PAGE_URL"]),
                'IMG' => $this->getUrl($arRes["DETAIL_PICTURE"] > 0 ?
                    \CFile::GetPath($arRes["DETAIL_PICTURE"]) :
                    \CFile::GetPath($arRes["PREVIEW_PICTURE"])),
                'TEXT' => empty($arRes['PREVIEW_TEXT']) ?
                    strip_tags($arRes['DETAIL_TEXT']) :
                    strip_tags($arRes['PREVIEW_TEXT']),
                'AVAILABLE' => $arRes["CATALOG_QUANTITY"] > 0 ? self::PRODUCT_AVAILABLE : self::PRODUCT_NOT_AVAILABLE,
                'PRICE' => $this->getPrice($arRes['ID'])
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
            }

            $this->elements[] = $element;
        }
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
     * @param $str
     * @return bool|int
     */
    private function writeXml($str) {
        return file_put_contents($this->fileName, $str.PHP_EOL, FILE_APPEND);
    }

    /**
     * @param $item
     */
    private function writeItemNode($item) {
        // open
        $this->writeXml('<item>');

        // content
        foreach ($this->mapping as $tag=>$field) {
            if (is_callable($field)) {
                $value = $field($item);
            } else {
                $macro = explode('.', $field);
                if ($macro[0] === '' || $macro[0] === 'element') {
                    $value = $item[$macro[1]];
                } else {
                    $value = $field;
                }
            }

            $str = sprintf('<g:%s>%s</g:%s>', $tag, $value, $tag);
            $this->writeXml($str);
        }

        // close
        $this->writeXml('</item>');
    }

    /**
     *
     */
    private function createXml() {
        // first delete old file
        if (file_exists($this->fileName)) {
            unlink($this->fileName);
        }

        // prepare mapping
        $this->mapping = array_merge($this->mapping, $this->mappingExt);

        // header
        $this->writeXml('<?xml version="1.0"?>');
        $this->writeXml('<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">');
        $this->writeXml('<channel>');
        $this->writeXml('<title>'.$this->siteInfo['siteName'].'</title>');
        $this->writeXml('<link>'.$this->siteInfo['url'].'</link>');
        $this->writeXml('<description></description>');

        // elements
        foreach ($this->elements as $item) {
            $this->writeItemNode($item);
        }

        // closing tags
        $this->writeXml('</channel>');
        $this->writeXml('</rss>');
    }

    /**
     * @param mixed $iblockId
     */
    public function setIblockId($iblockId) {
        $this->iblockId = $iblockId;
    }

    /**
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
        // include bitrix modules
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');
        Loader::includeModule('sale');

        // params
        $this->siteId = $siteId ?? Context::getCurrent()->getSite();
        $this->protocol = $protocol.'://';
        $this->getSiteInfo();

        // complex mapping
        $this->addMapping('price', function ($item) {
            return $item['PRICE']['PRICE'].' '.$item['PRICE']['CURRENCY'];
        });
    }

    /**
     * Экспорт файла в указанном формате
     * @param $fileName
     * @param string $format
     */
    public function export($fileName, $format = self::FORMAT_XML) {
        // init params
        $this->fileName = $_SERVER['DOCUMENT_ROOT'].$fileName;
        $this->createSelectFields();
        $this->createFilter();

        if (empty($this->elements)) {
            $this->obtainElements();
        }

        switch ($format) {
            case self::FORMAT_XML:
                $this->createXml();
                break;
        }
    }

    /**
     * @param $name
     * @param $value
     */
    public function addMapping($name, $value) {
        $this->mappingExt[$name] = $value;
    }

}