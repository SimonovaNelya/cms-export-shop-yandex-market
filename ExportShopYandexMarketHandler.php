<?php
/**
 * @author Semenov Alexander <semenov@skeeks.com>
 * @link http://skeeks.com/
 * @copyright 2010 SkeekS (СкикС)
 * @date 29.08.2016
 */
namespace skeeks\cms\exportShopYandexMarket;

use skeeks\cms\cmsWidgets\treeMenu\TreeMenuCmsWidget;
use skeeks\cms\export\ExportHandler;
use skeeks\cms\export\ExportHandlerFilePath;
use skeeks\cms\importCsv\handlers\CsvHandler;
use skeeks\cms\importCsv\helpers\CsvImportRowResult;
use skeeks\cms\importCsv\ImportCsvHandler;
use skeeks\cms\importCsvContent\widgets\MatchingInput;
use skeeks\cms\components\Cms;
use skeeks\cms\models\CmsContent;
use skeeks\cms\models\CmsContentElement;
use skeeks\cms\models\Tree;
use skeeks\cms\models\CmsContentPropertyEnum;
use skeeks\cms\models\CmsTree;
use skeeks\cms\modules\admin\widgets\BlockTitleWidget;
use skeeks\cms\relatedProperties\models\RelatedPropertiesModel;
use yii\db\ActiveQuery;
use skeeks\cms\query\CmsActiveQuery;
use skeeks\cms\relatedProperties\PropertyType;
use skeeks\cms\relatedProperties\propertyTypes\PropertyTypeElement;
use skeeks\cms\relatedProperties\propertyTypes\PropertyTypeList;
use skeeks\cms\shop\models\ShopCmsContentElement;
use skeeks\cms\shop\models\ShopProduct;
use skeeks\cms\widgets\formInputs\selectTree\SelectTree;
use skeeks\modules\cms\money\models\Currency;
use yii\base\Exception;
use yii\bootstrap\Alert;
use yii\console\Application;
use yii\data\Pagination;


use yii\db\Query;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\widgets\ActiveForm;

/**
 * @property CmsContent $cmsContent
 *
 * Class CsvContentHandler
 *
 * @package skeeks\cms\importCsvContent
 */
class ExportShopYandexMarketHandler extends ExportHandler
{
    /**
     * @var null выгружаемый контент
     */
    public $content_id = null;

    /**
     * @var null раздел и его подразделы попадут в выгрузку
     */
    public $tree_id = null;

    /**
     * @var null базовый путь сайта
     */
    public $base_url = null;

    /**
     * @var string путь к результирующему файлу
     */
    public $file_path = '';


    /**
     * @var string
     */
    public $shop_name = '';

    /**
     * @var string
     */
    public $shop_company = '';

    /**
     * @var string
     */
    public $available = '';


    /**
     * @var string
     */
    public $vendor = '';

    /**
     * @var string
     */
    public $vendor_code = '';

    /**
     * @var null
     */
    public $market_dir = null;

    /**
     * @var int
     */
    public $max_urlsets = 200;

    public $vendorArray = '';
    public $vendorCodeArray = '';


    /**
     * @var string
     */
    public $default_delivery = '';
    public $default_pickup = '';
    public $default_store = '';
    public $default_sales_notes = '';


    public $filter_property = '';
    public $filter_property_value = '';

    public $mem_start = null;


    public function init()
    {
        $this->name = \Yii::t('skeeks/exportShopYandexMarket', '[Xml] Simple exports of goods in yandex market');

        if (!$this->file_path)
        {
            $rand = \Yii::$app->formatter->asDate(time(), "Y-M-d") . "-" . \Yii::$app->security->generateRandomString(5);
            $this->file_path = "/export/yandex-market/content-{$rand}.xml";
        }

        if (!$this->market_dir)
        {
            $this->market_dir = "/export/YaMarket/";
        }


        if (!$this->base_url)
        {
            if (!\Yii::$app instanceof Application)
            {
                $this->base_url = Url::base(true);
            }
        }

        parent::init();
    }


    /**
     * @return null|CmsContent
     */
    public function getCmsContent()
    {
        if (!$this->content_id)
        {
            return null;
        }

        return CmsContent::findOne($this->content_id);
    }



    /**
     * Соответствие полей
     * @var array
     */
    public $matching = [];

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [

            ['content_id' , 'required'],
            ['content_id' , 'integer'],

            ['tree_id' , 'required'],
            ['tree_id' , 'integer'],

            ['base_url' , 'required'],
            ['base_url' , 'url'],

            ['vendor' , 'string'],
            ['vendor_code' , 'string'],

            ['shop_name' , 'string'],
            ['shop_company' , 'string'],
            ['available' , 'integer'],

            ['default_sales_notes' , 'string'],

            ['default_pickup' , 'string'],
            ['default_store' , 'string'],
            ['default_delivery' , 'string'],

            ['filter_property' , 'string'],
            ['filter_property_value' , 'string'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'content_id'        => \Yii::t('skeeks/exportShopYandexMarket', 'Контент'),
            'tree_id'        => \Yii::t('skeeks/exportShopYandexMarket', 'Выгружаемые категории'),
            'base_url'        => \Yii::t('skeeks/exportShopYandexMarket', 'Базовый url'),
            'vendor'        => \Yii::t('skeeks/exportShopYandexMarket', 'Производитель или бренд'),
            'vendor_code'        => \Yii::t('skeeks/exportShopYandexMarket', 'Артикул производителя'),
            'shop_name'        => \Yii::t('skeeks/exportShopYandexMarket', 'Короткое название магазина'),
            'shop_company'        => \Yii::t('skeeks/exportShopYandexMarket', 'Полное наименование компании, владеющей магазином'),
            'available'        => \Yii::t('skeeks/exportShopYandexMarket', 'Поставить у всех активных товаров статус "Под Заказ"'),

            'default_sales_notes'        => \Yii::t('skeeks/exportShopYandexMarket', 'вариантах оплаты, описания акций и распродаж '),
            'default_delivery'        => \Yii::t('skeeks/exportShopYandexMarket', 'Возможность курьерской доставки'),
            'default_pickup'        => \Yii::t('skeeks/exportShopYandexMarket', 'Возможность самовывоза из пунктов выдачи'),
            'default_store'        => \Yii::t('skeeks/exportShopYandexMarket', 'Возможность купить товар в розничном магазине'),

            'filter_property'        => \Yii::t('skeeks/exportShopYandexMarket', 'Признак выгрузки'),
            'filter_property_value'        => \Yii::t('skeeks/exportShopYandexMarket', 'Значение'),
        ]);
    }
    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'shop_name'        => \Yii::t('skeeks/exportShopYandexMarket', 'Короткое название магазина, должно содержать не более 20 символов. В названии нельзя использовать слова, не имеющие отношения к наименованию магазина, например «лучший», «дешевый», указывать номер телефона и т. п.
Название магазина должно совпадать с фактическим названием магазина, которое публикуется на сайте. При несоблюдении данного требования наименование может быть изменено Яндекс.Маркетом самостоятельно без уведомления магазина.'),
            'shop_company'        => \Yii::t('skeeks/exportShopYandexMarket', 'Полное наименование компании, владеющей магазином. Не публикуется, используется для внутренней идентификации.'),
            'default_delivery'        => \Yii::t('skeeks/exportShopYandexMarket', 'Для всех товаров магазина, по умолчанию'),
            'default_pickup'        => \Yii::t('skeeks/exportShopYandexMarket', 'Для всех товаров магазина, по умолчанию'),
            'default_store'        => \Yii::t('skeeks/exportShopYandexMarket', 'Для всех товаров магазина, по умолчанию'),
            'default_sales_notes'        => \Yii::t('skeeks/exportShopYandexMarket', 'Элемент используется для отражения информации о:
 минимальной сумме заказа, минимальной партии товара, необходимости предоплаты (указание элемента обязательно);
 вариантах оплаты, описания акций и распродаж (указание элемента необязательно).
Допустимая длина текста в элементе — 50 символов. .'),
        ]);
    }

    /**
     * @param ActiveForm $form
     */
    public function renderConfigForm(ActiveForm $form)
    {
        parent::renderConfigForm($form);

        echo BlockTitleWidget::widget([
            'content' => 'Общий настройки магазина'
        ]);

        echo $form->field($this, 'base_url');
        echo $form->field($this, 'shop_name');
        echo $form->field($this, 'shop_company');

        echo $form->field($this, 'content_id')->listBox(
            array_merge(['' => ' - '], CmsContent::getDataForSelect(true, function(ActiveQuery $activeQuery)
            {
                $activeQuery->andWhere([
                    'id' => \yii\helpers\ArrayHelper::map(\skeeks\cms\shop\models\ShopContent::find()->all(), 'content_id', 'content_id')
                ]);
            })), [
            'size' => 1,
            'data-form-reload' => 'true'
        ]);

        echo $form->field($this, 'tree_id')->widget(
            SelectTree::className(),
            [
                'mode' => SelectTree::MOD_SINGLE
            ]
        );

        if ($this->content_id)
        {
            echo BlockTitleWidget::widget([
                'content' => 'Настройки данных товаров'
            ]);


            echo $form->field($this, 'available')->checkbox(\Yii::$app->formatter->booleanFormat);


            echo $form->field($this, 'default_delivery')->listBox(
                ArrayHelper::merge(['' => ' - '], \Yii::$app->cms->booleanFormat()), [
                'size' => 1,
            ]);

            echo $form->field($this, 'default_pickup')->listBox(
                ArrayHelper::merge(['' => ' - '], \Yii::$app->cms->booleanFormat()), [
                'size' => 1,
            ]);

            echo $form->field($this, 'default_store')->listBox(
                ArrayHelper::merge(['' => ' - '], \Yii::$app->cms->booleanFormat()), [
                'size' => 1,
            ]);

            echo $form->field($this, 'default_sales_notes')->textInput([
                'maxlength' => 50
            ]);

            echo "<hr />";

            echo $form->field($this, 'vendor')->listBox(
                ArrayHelper::merge(['' => ' - '], $this->getAvailableFields()), [
                'size' => 1,
            ]);

            echo $form->field($this, 'vendor_code')->listBox(
                ArrayHelper::merge(['' => ' - '], $this->getAvailableFields()), [
                'size' => 1,
            ]);



            echo BlockTitleWidget::widget([
                'content' => 'Фильтрация'
            ]);

            echo Alert::widget([
                'options' => [
                    'class' => 'alert-info',
                ],
                'body' => 'В выгрузку попадают только активные товары и предложения. Дополнительно можно ограничить выборку опциями ниже.'
            ]);

            echo $form->field($this, 'filter_property')->listBox(
                ArrayHelper::merge(['' => ' - '], $this->getAvailableFields()), [
                'size' => 1,
                'data-form-reload' => 'true'
            ]);

            if ($this->filter_property)
            {
                if ($propertyName = $this->getRelatedPropertyName($this->filter_property))
                {
                    $element = new CmsContentElement([
                        'content_id' => $this->cmsContent->id
                    ]);

                    if ($property = $element->relatedPropertiesModel->getRelatedProperty($propertyName))
                    {
                        if ($property->handler instanceof PropertyTypeList)
                        {
                            echo $form->field($this, 'filter_property_value')->listBox(
                                ArrayHelper::merge(['' => ' - '], ArrayHelper::map($property->enums, 'id', 'value')), [
                                'size' => 1,
                            ]);
                        } else
                        {
                            echo $form->field($this, 'filter_property_value');
                        }
                    } else
                    {
                        echo $form->field($this, 'filter_property_value');
                    }
                }

            }

        }

    }


    public function getAvailableFields()
    {
        if (!$this->cmsContent)
        {
            return [];
        }

        $element = new CmsContentElement([
            'content_id' => $this->cmsContent->id
        ]);

        $fields = [];

        foreach ($element->relatedPropertiesModel->attributeLabels() as $key => $name)
        {
            $fields['property.' . $key] = $name . " [свойство]";
        }

        return array_merge(['' => ' - '], $fields);
    }


    public function getRelatedPropertyName($fieldName)
    {
        if (strpos("field_" . $fieldName, 'property.'))
        {
            $realName = str_replace("property.", "", $fieldName);
            return $realName;
        }
    }

    public function getElementName($fieldName)
    {
        if (strpos("field_" . $fieldName, 'element.'))
        {
            $realName = str_replace("element.", "", $fieldName);
            return $realName;
        }

        return '';
    }

    public function export()
    {

        //TODO: if console app
        \Yii::$app->urlManager->baseUrl = $this->base_url;
        \Yii::$app->urlManager->scriptUrl = $this->base_url;

        ini_set("memory_limit","16192M");
        set_time_limit(0);
        $this->mem_start = memory_get_usage();
        //Создание дирректории
        if ($dirName = dirname($this->rootFilePath))
        {
            $this->result->stdout("Создание дирректории\n");

            if (!is_dir($dirName) && !FileHelper::createDirectory($dirName))
            {
                throw new Exception("Не удалось создать директорию для файла");
            }
        }
        $this->_appendOffers();
        $this->_doxml();

        return $this->result;
    }

    protected function _doxml()
    {
        $imp = new \DOMImplementation();
        $dtd = $imp->createDocumentType('yml_catalog', '', "shops.dtd");
        $xml               = $imp->createDocument('', '', $dtd);
        $xml->encoding     = 'utf-8';
        //$xml->formatOutput = true;

        $yml_catalog = $xml->appendChild(new \DOMElement('yml_catalog'));
        $yml_catalog->appendChild(new \DOMAttr('date', date('Y-m-d H:i:s')));

        $this->result->stdout("\tДобавление основной информации\n");

        $shop = $yml_catalog->appendChild(new \DOMElement('shop'));

        $shop->appendChild(new \DOMElement('name', $this->shop_name ? htmlspecialchars($this->shop_name) : htmlspecialchars(\Yii::$app->name)));
        $shop->appendChild(new \DOMElement('company', $this->shop_company ? htmlspecialchars($this->shop_company) : htmlspecialchars(\Yii::$app->name)));
        $shop->appendChild(new \DOMElement('email', htmlspecialchars('info@skeeks.com')));
        $shop->appendChild(new \DOMElement('url', htmlspecialchars(
            $this->base_url
        )));
        $shop->appendChild(new \DOMElement('platform', "SkeekS CMS"));


        $this->_appendCurrencies($shop);
        $this->_appendCategories($shop);


        /**
         * Проверяем наличие файлов с оферами в папке. Загружаем их в файл маркета.
         */

        $files = scandir($this->rootMarketTempDir);

        if ($files)
        {
            $xoffers = $shop->appendChild(new \DOMElement('offers'));
            foreach ($files as $file) {
                if (!strpos($file, '.xml'))
                {
                    continue;
                }
                $doc = new \DOMDocument();
                $doc->preserveWhiteSpace = false;

                $doc->load($this->rootMarketTempDir.'/'.$file);

                $ListOffer = $doc->getElementsByTagName("offer");

                foreach ($ListOffer as $offer){
                    $node = $xml->importNode($offer, true); //выбираем корнев. узел
                    $xoffers->appendChild($node); //добавляем дочерним к корневом <src>
                }

                unlink($this->rootMarketTempDir.'/'.$file);
                unset($file, $fileXML, $dataText);

            }
        }
        $xml->formatOutput = true;
        $xml->save($this->rootFilePath);
        return $this->result;
    }

    /**
     * @param \DOMElement $shop
     *
     * @return $this
     */
    protected function _appendCurrencies(\DOMElement $shop)
    {
        $this->result->stdout("\tДобавление валют\n");
        /**
         * @var Currency $currency
         */
        $xcurrencies = $shop->appendChild(new \DOMElement('currencies'));
		foreach (Currency::find()->active()->orderBy(['priority' => SORT_ASC])->all() as $currency)
		{
			$xcurr = $xcurrencies->appendChild(new \DOMElement('currency'));
			$xcurr->appendChild(new \DOMAttr('id', $currency->code));
			$xcurr->appendChild(new \DOMAttr('rate', (float) $currency->course));
		}

        return $this;
    }

    /**
     * @param \DOMElement $shop
     *
     * @return $this
     */
    protected function _appendCategories(\DOMElement $shop)
    {
        /**
         * @var CmsTree $rootTree
         * @var CmsTree $tree
         */
        $rootTree = CmsTree::findOne($this->tree_id);

        $this->result->stdout("\tВставка категорий\n");

        if ($rootTree)
        {
            $xcategories = $shop->appendChild(new \DOMElement('categories'));

            $trees = $rootTree->getDescendants()->active()->orderBy(['level' => SORT_ASC])->all();
            $trees = ArrayHelper::merge([$rootTree], $trees);
            foreach ($trees as $tree)
            {
                /*$xcategories->appendChild($this->__xml->importNode($cat->toXML()->documentElement, TRUE));*/

                $xcurr = $xcategories->appendChild(new \DOMElement('category', $tree->name));
                $xcurr->appendChild(new \DOMAttr('id', $tree->id));
                if ($tree->parent && $tree->id != $rootTree->id)
                {
                    $xcurr->appendChild(new \DOMAttr('parentId', $tree->parent->id));
                }
                unset($tree);
            }
        }

    }

    /**
     * @param \DOMElement $shop
     *
     * @return $this
     */
    protected function _appendOffers()
    {

        $activeTotalQuery = (new Query())
            ->select('id, name, image_id, code, tree_id')
            ->from(CmsContentElement::tableName())
            ->andWhere(['`cms_content_element`.`active`' => Cms::BOOL_Y ])
            ->andWhere(['`cms_content_element`.`content_id`' => $this->content_id]);

      /*  $activeTotalQuery = ShopCmsContentElement::find()
            ->with('relatedElementProperties')
            ->andWhere(['`cms_content_element`.`active`' => Cms::BOOL_Y ])
            ->andWhere(['`cms_content_element`.`content_id`' => $this->content_id])
        ;
*/
        /* Если есть условие фильтрации, добавляем его сразу в запрос, чтобы не обрабатывать лишние строки. */
  /*      if ($this->filter_property)
        {
            if ($propertyName = $this->getRelatedPropertyName($this->filter_property)) {
                $activeTotalQuery
                    ->joinWith('relatedElementProperties map')
                    ->joinWith('relatedElementProperties.property property')

                    ->andWhere(['property.code'     => $propertyName]);

                if ($this->filter_property_value)
                    $activeTotalQuery->andWhere(['map.value'         => $this->filter_property_value]);
            }

        }*/

        $activeTotalCount = $activeTotalQuery->count();

        if ($activeTotalCount)
        {
            $successAdded = 0;
            //$xoffers = $shop->appendChild(new \DOMElement('offers'));

            //$activeTotalCountQuery->select(['`cms_content_element`.`id`']);

            $i = 0;
            $pages = new Pagination([
                'totalCount'        => $activeTotalCount,
                'defaultPageSize'   => $this->max_urlsets,
                'pageSizeLimit'   => [1, $this->max_urlsets],
            ]);

            for ($i >= 0; $i < $pages->pageCount; $i ++)
            {
                $pages->setPage($i);

                $this->result->stdout("\t\t\t\t Page = {$i}\n");
                $this->result->stdout("\t\t\t\t Offset = {$pages->offset}\n");
                $this->result->stdout("\t\t\t\t limit = {$pages->limit}\n");

                $result = [];
                $this->result->stdout("\tЗанято памяти: \n". (memory_get_usage() - $this->mem_start));
                $elements = $activeTotalQuery->offset($pages->offset)->limit($pages->limit)->all();

                foreach ($elements as &$element)
                {
                    /*
                     * @var ShopCmsContentElement $element
                     */
                    //$element = ShopCmsContentElement::findOne($elementId['id'])->joinWith('relatedElementProperties map');

                    try
                    {

                        if (!$element)
                        {
                            throw new Exception("Нет данных для магазина");
                            continue;
                        }

                        $shopProduct = (new Query())
                            ->select('shop_product.id, shop_product.quantity, shop_product_price.price, shop_product.purchasing_currency, shop_product.product_type')
                            ->from('shop_product')
                            ->leftJoin('`shop_product_price`',"`shop_product`.id='shop_product_price.product_id'")
                            ->where(['`shop_product`.id' => $element['id']])
                            ->orderBy(['`shop_product_price`.updated_at' => SORT_DESC])
                            ->one();
                        $productPrice =  (new Query())
                            ->select('shop_product_price.price')
                            ->from('shop_product_price')
                            ->where(['shop_product_price.product_id' => $element['id']])
                            ->orderBy(['`shop_product_price`.updated_at' => SORT_DESC])
                        ->one();
                        $shopProduct['price'] = $productPrice['price'];
                        unset($productPrice);
                        if (!$shopProduct)
                        {

                            throw new Exception("Нет данных для магазина");
                            continue;
                        }

                        if (!$shopProduct['price'])
                        {
                            throw new Exception("Нет цены");
                            continue;
                        }

                        if ($shopProduct['quantity'] <= 0)
                        {

                            throw new Exception("Нет в наличии");
                            continue;
                        }

                        if ($shopProduct['product_type'] == ShopProduct::TYPE_SIMPLE)
                        {
                            $result[$element['id']] = $this->_initTextOffer($element, $shopProduct);

                        }else
                        {
                            $offers = $element->tradeOffers;
                            foreach ($offers as $offer)
                            {
                                $result[] = $this->_initTextOffer($element, $shopProduct);
                                unset($offer);
                            }
                        }

                        $successAdded ++;
                        $element = null;
                    } catch (\Exception $e)
                    {
                        $this->result->stdout("\t\t{$element->id} — {$e->getMessage()}\n", Console::FG_RED);
                        $element = null;
                        continue;
                    }
                    memory_get_peak_usage();
                }
                $publicUrl = $this->generateDataFile("temp_offers_page{$i}.xml", $result);
                $this->result->stdout("\tФайл успешно сгенерирован: {$publicUrl}\n");

                $files[] = $publicUrl;
                unset($result, $publicUrl);
                $this->result->stdout("\tЗанято памяти: \n". (memory_get_usage() - $this->mem_start));
                $this->result->stdout("\tАктивных товаров найдено: {$activeTotalCount}\n");
            }

            unset($pages, $activeTotalCount);

            $this->result->stdout("\tДобавлено в файл: {$successAdded}\n");
        }
        return;
    }

    protected function _initTextOffer($elementArray, $shopProdArray)
    {
        $this->result->stdout("\tТовар: \n". $elementArray['id']);
        $propertyName = null;
        $attributeValue = null;

        if (!$shopProdArray)
        {
            throw new Exception("Нет данных для магазина");
        }

        if ($this->filter_property)
        {
            $propertyName = $this->getRelatedPropertyName($this->filter_property);

            $filterValue = $this->getRelatedPropertyValue($propertyName, $elementArray['id']);

            if (!$filterValue)
            {
                unset($propertyName, $element);
                throw new Exception("Не найдено свойство для фильтрации");
            }

            if ($this->filter_property_value)
            {
                $pos = strpos($this->filter_property_value,',');

                if ($pos !== false)
                {
                    $filter_property_value = explode(',', $this->filter_property_value);

                    if (!in_array($filterValue, $filter_property_value))
                    {
                        unset($filterValue, $element, $filter_property_value);
                        throw new Exception("Не найдено свойство для фильтрации");
                    }
                }
                else
                {
                    if ($filterValue != $this->filter_property_value)
                    {
                        unset($filterValue, $element);
                        throw new Exception("Не найдено свойство для фильтрации");
                    }
                }
            }
        }

        $data = [];
        $data['offer']['id'] = $elementArray['id'];

        if ($shopProdArray['quantity']>0)
        {
            if ($this->available)
                $data['offer']['available'] = 'false';
            else
                $data['offer']['available'] = 'true';
        } else
        {
            throw new Exception("Нет в наличии");
        }

        $data['url'] = $this->getUrl($elementArray);

        $data['name'] = htmlspecialchars($elementArray['name']);

        if ($this->getImage($elementArray['image_id']))
        {
            $data['picture'] = $this->getImage($elementArray['image_id']);
        }
        if ($elementArray['tree_id'])
        {
            $data['categoryId'] = $elementArray['tree_id'];
        }

        if ($shopProdArray['price'])
        {
            $data['price'] = \Yii::$app->formatter->asInteger($shopProdArray['price']);
            $data['currencyId'] = $shopProdArray['purchasing_currency'];
        }

        /**
         * формируем бренд
         */
        if ($this->vendor)
        {
            if ($propertyName = $this->getRelatedPropertyName($this->vendor)) {

                $filterValue = $this->getRelatedPropertyValue($propertyName, $elementArray['id']);

                if ($filterValue)
                {
                    if (!$this->vendorArray[$filterValue])
                    {
                        $brandModel = (new Query())
                            ->select('name')
                            ->from(Tree::tableName())
                            ->where(['id' => $filterValue])
                            ->one();

                        if ($brandModel)
                        {
                            $this->vendorArray =
                                [
                                    $filterValue =>  $brandModel['name']
                                ];
                            $data['vendor'] = htmlspecialchars($brandModel['name']);
                        }

                    }
                    else
                    {
                        $data['vendor'] = htmlspecialchars($this->vendorArray[$filterValue]);
                    }
                unset($filterValue, $brandModel);
                }

            }

        }
        /**
         * формируем артикул
         */
        if ($this->vendor_code)
        {
            $filterValue = $this->getRelatedPropertyValue($propertyName, $elementArray['id']);

            if ($filterValue)
            {
                if (!$this->vendorCodeArray[$filterValue])
                {
                    $articuleModel = (new Query())
                        ->select('name')
                        ->from(Tree::tableName())
                        ->where(['id' => $filterValue])
                        ->one();
                    if ($articuleModel)
                    {
                        $this->vendorCodeArray =
                            [
                                $filterValue =>  $articuleModel['name']
                            ];
                        $data['vendorCode'] = htmlspecialchars($articuleModel['name']);
                    }

                }
                else
                {
                    $data['vendorCode'] = htmlspecialchars($this->vendorArray[$filterValue]);
                }
                unset($filterValue, $articuleModel);
            }
        }

        if ($this->default_delivery)
        {
            if ($this->default_delivery == 'Y')
            {
                $data['delivery'] = 'true';
            } else if ($this->default_delivery == 'N')
            {
                $data['delivery'] = 'false';
            }
        }

        if ($this->default_store)
        {
            if ($this->default_store == 'Y')
            {
                $data['store'] = 'true';
            } else if ($this->default_store == 'N')
            {
                $data['store'] = 'false';
            }
        }

        if ($this->default_pickup)
        {
            if ($this->default_pickup == 'Y')
            {
                $data['pickup'] = 'true';
            } else if ($this->default_pickup == 'N')
            {
                $data['pickup'] = 'false';
            }
        }

        if ($this->default_sales_notes)
        {
            $data['sales_notes'] = $this->default_sales_notes;
        }
        $element = null;
        return $data;
    }

    /**
     * @return mixed
     */
    public function getRootMarketTempDir()
    {
        return \Yii::getAlias($this->alias  . $this->market_dir);
    }

    /**
     * @param $propertyName
     * @param $id
     */
    public function getRelatedPropertyValue($propertyName, $id)
    {
        if (!$propertyName OR !$id)
            return;

        $filterSelect = (new Query())
            ->select('`cms_content_element_property`.value')
            ->from('`cms_content_element_property`')
            ->leftJoin('`cms_content_property`',"`cms_content_property`.code='". $propertyName."'")
            ->where('`cms_content_element_property`.`property_id`=`cms_content_property`.id')
            ->andWhere(['`cms_content_element_property`.`element_id`' => $id])
            ->one()
        ;
        if ($filterSelect && $filterSelect['value'])
        {
            $value = $filterSelect['value'];
            unset($filterSelect);
            return $value;
        }

        return;
    }

    /**
     * @param $imageId
     * @return bool|string
     */
    public function getImage($imageId)
    {
        if (!$imageId)
            return false;

        $image = (new Query())
            ->select('`cms_storage_file`.cluster_file')
            ->from('`cms_storage_file`')
            ->where(['`cms_storage_file`.id' => $imageId])
            ->one()
        ;
        if ($image['cluster_file'])
        {
            $imageUrl = \Yii::$app->urlManager->baseUrl.'/uploads/all/'.$image['cluster_file'];
            unset($image);
            return htmlspecialchars($imageUrl);
        }

        return false;
    }

    /**
     * @return bool|string
     */
    public function getUrl($elementArray)
    {

        if (!$elementArray)
            return;

        $parentUrl = '';
        if ($elementArray['tree_id'])
        {
            $parentTree = (new Query())
                ->select('`cms_tree`.code, `cms_tree`.pids')
                ->from('`cms_tree`')
                ->where(['`cms_tree`.id' => $elementArray['tree_id']])
                ->one()
            ;

            if ($parentTree['pids'])
            {
                $pidsArr = str_replace('/', ', ', $parentTree['pids']);

                $parentTrees = (new Query())
                    ->select('`cms_tree`.code')
                    ->from('`cms_tree`')
                    ->where(['`cms_tree`.id'=>explode(',', $pidsArr)])
                    ->all()
                ;
                $parentUrl = '';
                foreach ($parentTrees as &$item) {
                    if ($item['code'])
                    {
                        $parentUrl = $parentUrl.'/'.$item['code'];
                    }
                }
            }
        }
        unset($parentTrees, $parentTree);
        return htmlspecialchars(\Yii::$app->urlManager->baseUrl.$parentUrl.'/'.$elementArray['code'].'/');
    }


    /**
     *
     * @param $dataFileName
     * @param $data
     * @return bool|string
     * @throws Exception
     */
    protected function generateDataFile($dataFileName, $data)
    {
        $this->result->stdout("\t$dataFileName: {$dataFileName}\n");
        $rootFilePath               = $this->rootMarketTempDir . "/" . $dataFileName;
        $rootFilePath               = FileHelper::normalizePath($rootFilePath);
        $this->result->stdout("\t$rootFilePath: {$rootFilePath}\n");
        //Создание дирректории
        if ($dirName = dirname($rootFilePath))
        {
            $this->result->stdout("\t\tПапка: {$dirName}\n");

            if (!is_dir($dirName) && !FileHelper::createDirectory($dirName))
            {
                throw new Exception("Не удалось создать директорию для файла");
            }
        }

        $this->result->stdout("\t\tГенерация файла: {$rootFilePath}\n");

        $treeSitemapContent = \Yii::$app->view->render('@skeeks/cms/exportShopYandexMarket/views/urlsets', [
            'data' => $data
        ]);

        $fp = fopen($rootFilePath, 'w');
        // записываем в файл текст
        fwrite($fp, $treeSitemapContent);
        // закрываем
        fclose($fp);

        if (!file_exists($rootFilePath))
        {
            throw new Exception("\t\tНе удалось создать файл");
        }
        unset($data);
        return $rootFilePath;
    }
}