# yokel/gfeed

Класс для создание фида в xml и csv

## Системные требования

- PHP 7 и выше

## Установка

Через composer

```
composer require yokel/gfeed
```

## Использование

```php
// Создать экземпляр класса
$feed = new \Yokel\GFeed\GFeed();

// Указать идентификатор инфоблока с товарами
// второй параметр - идентификатор родительского инфоблока (обязательный, если есть ТП)
$feed->setIblockId(25, 24);

// Указать название поля с ценой товара (для фильтрации - исключить товары с нулевой ценой)
$feed->setPriceField('CATALOG_PRICE_1');

// Экспорт в xml
$feed->export('/feed.xml');

// Экспорт в csv
$feed->export('/feed.csv', \Yokel\GFeed\GFeed::FORMAT_CSV);

// Экспорт в yml
$feed->export('/feed.xml', \Yokel\GFeed\GFeed::FORMAT_YML);
```

### Константы

```php
const PRODUCT_AVAILABLE_XML = 'в наличии';
const PRODUCT_NOT_AVAILABLE_XML = 'нет в наличии';
const PRODUCT_NEW_XML = 'новый';
const PRODUCTS_AVAILABLE_CSV = 'in stock';
const PRODUCTS_NOT_AVAILABLE_CSV = 'out of stock';
const PRODUCT_NEW_CSV = 'new';
const FORMAT_XML = 'xml';
const FORMAT_CSV = 'csv';
const FORMAT_YML = 'yml';
``` 

### Маппинг полей

Добавляет или переопределяет поля в создаваемом файле xml/csv

```php
// Добавляет маппинг для xml
function addMappingXml($name, $value)

// Добавляет маппинг для csv
function addMappingCsv($name, $value)

// Добавляет маппинг для yml
function addMappingYml($name, $value)

// Добавляет маппинг для всех
function addMappingAll($name, $value)
```

- $name - название поля в файле
- $value - значение поля

#### Примеры использования

- Простой тип

```php
// Подставляет значение 567 в поле google_product_category
$feed->addMappingCsv('google_product_category', '567');
```

- Макрос

```php
// Подставляет значение поля SECTION_CODE из товара в поле custom_label_0 в файле 
$feed->addMappingXml('custom_label_0', 'element.SECTION_CODE');
//или
$feed->addMappingXml('custom_label_0', '.SECTION_CODE');

// Подставляет значение поля SECTION_CODE из родительского товара (для ТП)
$feed->addMappingXml('custom_label_0', 'parent.SECTION_CODE');
```

Доступные макросы для товара:

.ID - id товара в инфоблоке

.NAME - название товара

.SECTION_ID - id раздела в инфоблоке

.SECTION_CODE - код раздела в инфоблоке

.LINK - ссылка на карточку товара

.IMG - ссылка на картинку товара (PREVIEW_PICTURE или DETAIL_PICTURE или первая из MORE_PHOTO)

.TEXT - описание товара (PREVIEW_TEXT или DETAIL_TEXT)

Доступные макросы для родительского товара:

parent.ID - id родительского товара в инфоблоке

parent.NAME - название родительского товара

parent.SECTION_ID - id раздела родительского в инфоблоке

parent.SECTION_CODE - код раздела родительского в инфоблоке

parent.LINK - ссылка на карточку родительского товара

parent.TEXT - описание родительского товара (PREVIEW_TEXT или DETAIL_TEXT)

- Вычисляемое значение

```php
// $item - товар из инфоблока
$feed->addMappingAll('description', function ($item) {
    return $item['PARENT']['PROPS']['DESCRIPTION']['VALUE']['TEXT'];
});
```