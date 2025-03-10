<?php
declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\EcommerceFrameworkBundle\IndexService\ProductList;

use Monolog\Logger;
use Pimcore\Bundle\EcommerceFrameworkBundle\Exception\InvalidConfigException;
use Pimcore\Bundle\EcommerceFrameworkBundle\FilterService\FilterType\Findologic\SelectCategory;
use Pimcore\Bundle\EcommerceFrameworkBundle\IndexService\Config\FindologicConfigInterface;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\AbstractCategory;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\IndexableInterface;
use Psr\Log\LoggerInterface;

class DefaultFindologic implements ProductListInterface
{
    /**
     * @var string
     */
    protected mixed $userIp = '';

    /**
     * @var string
     */
    protected mixed $referer = '';

    protected string $revision = '0.1';

    /**
     * @var IndexableInterface[]|null
     */
    protected ?array $products = null;

    protected string $tenantName;

    protected FindologicConfigInterface $tenantConfig;

    protected ?int $totalCount = null;

    protected string $variantMode = ProductListInterface::VARIANT_MODE_INCLUDE;

    protected int $limit = 10;

    protected int $offset = 0;

    protected ?AbstractCategory $category = null;

    protected bool $inProductList = true;

    /**
     * json result from findologic
     *
     * @var \SimpleXMLElement
     */
    protected \SimpleXMLElement $response;

    /**
     * @var array<string,\stdClass>
     */
    protected ?array $groupedValues = null;

    protected array $conditions = [];

    protected array $queryConditions = [];

    protected ?float $conditionPriceFrom = null;

    protected ?float $conditionPriceTo = null;

    protected ?string $order = null;

    protected string|array $orderKey;

    protected LoggerInterface $logger;

    protected array $supportedOrderKeys = ['label', 'price', 'salesFrequency', 'dateAdded'];

    protected int $timeout = 3;

    public function __construct(FindologicConfigInterface $tenantConfig, LoggerInterface $pimcoreEcommerceFindologicLogger)
    {
        $this->tenantName = $tenantConfig->getTenantName();
        $this->tenantConfig = $tenantConfig;

        // init logger
        $this->logger = $pimcoreEcommerceFindologicLogger;

        // set defaults for required params
        $this->userIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?: $_SERVER['REMOTE_ADDR'];
        $this->referer = $_SERVER['HTTP_REFERER'];
    }

    /** @inheritDoc */
    public function getProducts(): array
    {
        if ($this->products === null) {
            $this->load();
        }

        return $this->products;
    }

    public function addCondition(array|string $condition, string $fieldname = '')
    {
        $this->products = null;
        $this->conditions[$fieldname][] = $condition;
    }

    public function resetCondition(string $fieldname): void
    {
        $this->products = null;
        unset($this->conditions[$fieldname]);
    }

    /**
     * Adds query condition to product list for fulltext search
     * Fieldname is optional but highly recommended - needed for resetting condition based on fieldname
     * and exclude functionality in group by results
     *
     * @param string $condition
     * @param string $fieldname
     */
    public function addQueryCondition(string $condition, string $fieldname = '')
    {
        $this->products = null;
        $this->queryConditions[$fieldname][] = $condition;
    }

    /**
     * Reset query condition for fieldname
     *
     * @param string $fieldname
     */
    public function resetQueryCondition(string $fieldname)
    {
        $this->products = null;
        unset($this->queryConditions[$fieldname]);
    }

    /**
     * resets all conditions of product list
     */
    public function resetConditions()
    {
        $this->conditions = [];
        $this->queryConditions = [];
        $this->conditionPriceFrom = null;
        $this->conditionPriceTo = null;
        $this->products = null;
    }

    public function addRelationCondition(string $fieldname, array|string $condition)
    {
        $this->products = null;
        $this->addCondition($condition, $fieldname);
    }

    /**
     * @param float|null $from
     * @param float|null $to
     */
    public function addPriceCondition(?float $from = null, ?float $to = null)
    {
        $this->products = null;
        $this->conditionPriceFrom = $from;
        $this->conditionPriceTo = $to;
    }

    public function setInProductList(bool $inProductList): void
    {
        $this->products = null;
        $this->inProductList = (bool)$inProductList;
    }

    public function getInProductList(): bool
    {
        return $this->inProductList;
    }

    public function setOrder(string $order)
    {
        $this->products = null;
        $this->order = $order;
    }

    public function getOrder(): ?string
    {
        return $this->order;
    }

    /**
     * @param array|string $orderKey either single field name, or array of field names or array of arrays (field name, direction)
     */
    public function setOrderKey(array|string $orderKey)
    {
        $this->products = null;
        $this->orderKey = $orderKey;
    }

    public function getOrderKey(): array|string
    {
        return $this->orderKey;
    }

    public function setLimit(int $limit)
    {
        if ($this->limit != $limit) {
            $this->products = null;
        }
        $this->limit = $limit;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function setOffset(int $offset)
    {
        if ($this->offset != $offset) {
            $this->products = null;
        }
        $this->offset = $offset;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function setCategory(AbstractCategory $category)
    {
        $this->products = null;
        $this->category = $category;
    }

    public function getCategory(): ?AbstractCategory
    {
        return $this->category;
    }

    public function setVariantMode(string $variantMode)
    {
        $this->products = null;
        $this->variantMode = $variantMode;
    }

    public function getVariantMode(): string
    {
        return $this->variantMode;
    }

    /**
     * @return IndexableInterface[]
     */
    public function load(): array
    {
        // init
        $params = [];

        // add conditions
        $params = $this->buildSystemConditions($params);
        $params = $this->buildFilterConditions($params);
        $params = $this->buildQueryConditions($params);
        $params = $this->buildSorting($params);

        // add paging
        $params['first'] = $this->getOffset();
        $params['count'] = $this->getLimit();

        // send request
        $data = $this->sendRequest($params);

        // TODO error handling

        // load products found
        $this->products = [];
        foreach ($data->products->children() as $item) {
            $id = null;

            // variant handling
            switch ($this->getVariantMode()) {
                case self::VARIANT_MODE_INCLUDE:
                case self::VARIANT_MODE_HIDE:
                    $id = (int) $item['id'];

                    break;
                case self::VARIANT_MODE_VARIANTS_ONLY:
                case self::VARIANT_MODE_INCLUDE_PARENT_OBJECT:
                    throw new InvalidConfigException('Variant Mode ' . $this->getVariantMode() . ' not supported.');
            }

            if ($id) {
                $product = $this->tenantConfig->getObjectMockupById($id);
                if ($product) {
                    $this->products[] = $product;
                }
            } else {
                $this->getLogger()->error(sprintf('object "%s" not found', $id));
            }
        }

        // extract grouped values
        $this->groupedValues = [];
        $filters = json_encode($data->filters);
        $filters = json_decode($filters);
        if ($filters->filter) {
            foreach ($filters->filter as $filter) {
                $this->groupedValues[$filter->name] = $filter;
            }
        }

        // save request
        $this->totalCount = (int)$data->results->count;
        $this->response = $data;

        return $this->products;
    }

    /**
     * builds system conditions
     *
     * @param array $filter
     *
     * @return array
     */
    protected function buildSystemConditions(array $filter): array
    {
        // add sub tenant filter
        $tenantCondition = $this->tenantConfig->getSubTenantCondition();
        if ($tenantCondition) {
            $filter['usergrouphash'] = $tenantCondition;
        }

        // variant handling
        switch ($this->getVariantMode()) {
            case self::VARIANT_MODE_HIDE:
                break;

            case self::VARIANT_MODE_INCLUDE:
                break;

            default:
            case self::VARIANT_MODE_INCLUDE_PARENT_OBJECT:
                break;
        }

        return $filter;
    }

    /**
     * builds filter condition of user specific conditions
     *
     * @param array $params
     *
     * @return array
     */
    protected function buildFilterConditions(array $params): array
    {
        foreach ($this->conditions as $fieldname => $condition) {
            if (is_array($condition)) {
                foreach ($condition as $cond) {
                    $params['attrib'][$fieldname] = array_merge($params['attrib'][$fieldname] ?: [], $cond);
                }
            } else {
                $params['attrib'][$fieldname] = $condition;
            }
        }

        if ($this->getCategory()) {
            $params['attrib']['cat'][] = $this->buildCategoryTree($this->getCategory());
        }

        if ($this->conditionPriceTo) {
            $params['attrib']['price']['max'] = $this->conditionPriceTo;
        }

        if ($this->conditionPriceFrom) {
            $params['attrib']['price']['min'] = $this->conditionPriceFrom;
        }

        return $params;
    }

    /**
     * create category path
     *
     * @param AbstractCategory $currentCat
     *
     * @return string
     */
    public function buildCategoryTree(AbstractCategory $currentCat): string
    {
        $catTree = $currentCat->getId();
        while ($currentCat->getParent() instanceof $currentCat) {
            $catTree = $currentCat->getParentId() . '_' . $catTree;
            $currentCat = $currentCat->getParent();
        }

        return $catTree;
    }

    /**
     * builds query condition of query filters
     *
     * @param array $params
     *
     * @return array
     */
    protected function buildQueryConditions(array $params): array
    {
        $query = '';

        foreach ($this->queryConditions as $fieldname => $condition) {
            $query .= is_array($condition)
                ? implode(' ', $condition)
                : $condition
            ;
        }

        if ($query) {
            $params['query'] = $query;
        }

        return $params;
    }

    protected function buildSorting(array $params): array
    {
        // add sorting
        if ($this->getOrderKey()) {
            if (is_array($this->getOrderKey())) {
                $orderKey = $this->getOrderKey();
                $order = reset($orderKey);
                if (true === in_array($order[0], $this->supportedOrderKeys)) {
                    $params['order'] = $order[0] . ($order[1] ? ' ' . $order[1] : '');
                }
            } else {
                if (true === in_array($this->getOrderKey(), $this->supportedOrderKeys)) {
                    $params['order'] = $this->getOrderKey() . ($this->getOrder() ? ' ' . $this->getOrder() : '');
                }
            }
        }

        return $params;
    }

    /**
     * prepares all group by values for given field names and cache them in local variable
     * considers both - normal values and relation values
     *
     * @param string $fieldname
     * @param bool $countValues
     * @param bool $fieldnameShouldBeExcluded
     *
     * @throws \Exception
     */
    public function prepareGroupByValues(string $fieldname, bool $countValues = false, bool $fieldnameShouldBeExcluded = true): void
    {
        // nothing todo
    }

    /**
     * resets all set prepared group by values
     *
     * @return void
     */
    public function resetPreparedGroupByValues(): void
    {
        // nothing todo
    }

    /**
     * prepares all group by values for given field names and cache them in local variable
     * considers both - normal values and relation values
     *
     *
     */
    public function prepareGroupByRelationValues(string $fieldname, bool $countValues = false, bool $fieldnameShouldBeExcluded = true): void
    {
        // nothing todo
    }

    /**
     * prepares all group by values for given field names and cache them in local variable
     * considers both - normal values and relation values
     *
     *
     */
    public function prepareGroupBySystemValues(string $fieldname, bool $countValues = false, bool $fieldnameShouldBeExcluded = true): void
    {
        // nothing todo
    }

    /**
     * loads group by values based on relation fieldname either from local variable if prepared or directly from product index
     *
     * @param string $fieldname
     * @param bool $countValues
     * @param bool $fieldnameShouldBeExcluded => set to false for and-conditions
     *
     * @return array
     *
     * @throws \Exception
     */
    public function getGroupBySystemValues(string $fieldname, bool $countValues = false, bool $fieldnameShouldBeExcluded = true): array
    {
        return $this->getGroupByValues($fieldname, $countValues, $fieldnameShouldBeExcluded);
    }

    public function getGroupByValues(string $fieldname, bool $countValues = false, bool $fieldnameShouldBeExcluded = true): array
    {
        // init
        $groups = [];

        // load values
        if ($this->groupedValues === null) {
            $this->doLoadGroupByValues();
        }

        if (array_key_exists($fieldname, $this->groupedValues)) {
            $field = $this->groupedValues[$fieldname];

            // special handling for nested category filters
            if ($this->getCategory() && $fieldname === SelectCategory::FIELDNAME) {
                $catTree = $this->buildCategoryTree($this->getCategory());

                $categories = explode('_', $catTree);

                foreach ($categories as $cat) {
                    if (is_array($field->items->item)) {
                        foreach ($field->items->item as $entry) {
                            if ($entry->name == $cat) {
                                $field = $entry;

                                break;
                            }
                        }
                    } else {
                        $field = $field->items->item;
                    }
                }
            } elseif ($fieldname === 'price') {
                $field = $this->groupedValues[$fieldname];

                $groups[] = [
                    'value' => null, 'label' => null, 'count' => null, 'parameter' => $field->attributes->totalRange,
                ];
            } elseif ($fieldname === SelectCategory::FIELDNAME) {
                $rec = function (array $items) use (&$rec, &$groups) {
                    foreach ($items as $item) {
                        $groups[$item->name] = [
                            'value' => $item->name, 'label' => $item->name, 'count' => $item->frequency, 'parameter' => $item->parameters,
                        ];

                        if ($item->items) {
                            $list = is_array($item->items->item)
                                ? $item->items->item
                                : [$item->items->item]
                            ;
                            $rec($list);
                        }
                    }
                };

                $rec($field->items->item);
            }

            if ($field->items->item) {
                $hits = $field->items->item instanceof \stdClass
                    ? [$field->items->item]
                    : $field->items->item
                ;

                foreach ($hits as $item) {
                    $groups[] = [
                        'value' => $item->name, 'label' => $item->name, 'count' => $item->frequency, 'parameter' => $item->parameters,
                    ];
                }
            }
        }

        return $groups;
    }

    public function getGroupByRelationValues(string $fieldname, bool $countValues = false, bool $fieldnameShouldBeExcluded = true): array
    {
        // init
        $relations = [];

        // load and resort data
        $values = $this->getGroupByValues($fieldname, $countValues, $fieldnameShouldBeExcluded);
        foreach ($values as $item) {
            $relations[] = $item['value'];
        }

        return $relations;
    }

    protected function doLoadGroupByValues()
    {
        // init
        $params = [];

        // add conditions
        $params = $this->buildSystemConditions($params);
        $params = $this->buildFilterConditions($params);
        $params = $this->buildQueryConditions($params);
        $params = $this->buildSorting($params);

        // add paging
        $params['first'] = $this->getOffset();
        $params['count'] = $this->getLimit();

        // send request
        $data = $this->sendRequest($params);

        // TODO error handling
        //        if(array_key_exists('error', $data))
        //        {
        //            throw new Exception($data['error']);
        //        }
        //        $searchResult = $data->searchResult;

        // extract grouped values
        $this->groupedValues = [];
        $filters = json_encode($data->filters);
        $filters = json_decode($filters);
        foreach ($filters->filter as $item) {
            $this->groupedValues[$item->name] = $item;
        }

        // save request
        $this->totalCount = (int)$data->results->count;
    }

    /**
     * @param array $params
     *
     * @return \SimpleXMLElement
     *
     * @throws \Exception
     */
    protected function sendRequest(array $params): \SimpleXMLElement
    {
        // add system params
        $params = [
            'shopkey' => $this->tenantConfig->getClientConfig('shopKey'), 'shopurl' => $this->tenantConfig->getClientConfig('shopUrl'), 'userip' => $this->userIp, 'referer' => $this->referer, 'revision' => $this->revision,
        ] + $params;

        // we have different end points for search and navigation
        $endpoint = array_key_exists('query', $params)
            ? 'index.php'
            : 'selector.php'
        ;

        // create url
        $url = sprintf(
            'http://%1$s/ps/xml_2.0/%2$s?',
            $this->tenantConfig->getClientConfig('serviceUrl'),
            $endpoint
        );
        $url .= http_build_query($params);

        $this->getLogger()->info('Request: ' . $url);

        // start request
        $start = microtime(true);
        $client = \Pimcore::getContainer()->get('pimcore.http_client');
        $response = $client->request('GET', $url, [
            'timeout' => $this->timeout,
        ]);
        $this->getLogger()->info('Duration: ' . number_format(microtime(true) - $start, 3));

        if ($response->getStatusCode() != 200) {
            throw new \Exception((string)$response->getBody());
        }

        return simplexml_load_string((string)$response->getBody());
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function count(): int
    {
        $this->getProducts();

        return $this->totalCount ?? 0;
    }

    /**
     * @return IndexableInterface|false
     */
    public function current(): bool|IndexableInterface
    {
        $this->getProducts();

        return current($this->products);
    }

    /**
     * Returns an collection of items for a page.
     *
     * @param int $offset Page offset
     * @param int $itemCountPerPage Number of items per page
     *
     * @return array
     */
    public function getItems(int $offset, int $itemCountPerPage): array
    {
        $this->setOffset($offset);
        $this->setLimit($itemCountPerPage);

        return $this->getProducts();
    }

    public function key(): ?int
    {
        $this->getProducts();

        return key($this->products);
    }

    public function next(): void
    {
        $this->getProducts();
        next($this->products);
    }

    public function rewind(): void
    {
        $this->getProducts();
        reset($this->products);
    }

    public function valid(): bool
    {
        return $this->current() !== false;
    }
}
