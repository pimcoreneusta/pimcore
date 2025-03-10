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

namespace Pimcore\Bundle\EcommerceFrameworkBundle\CartManager;

use Pimcore\Bundle\EcommerceFrameworkBundle\Exception\InvalidConfigException;
use Pimcore\Bundle\EcommerceFrameworkBundle\Exception\VoucherServiceException;
use Pimcore\Bundle\EcommerceFrameworkBundle\Factory;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\AbstractSetProductEntry;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\CheckoutableInterface;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\MockProduct;
use Pimcore\Bundle\EcommerceFrameworkBundle\VoucherService\PricingManagerTokenInformation;
use Pimcore\Bundle\EcommerceFrameworkBundle\VoucherService\Reservation;
use Pimcore\Logger;
use Pimcore\Model\AbstractModel;
use Pimcore\Model\DataObject\Concrete;

abstract class AbstractCart extends AbstractModel implements CartInterface
{
    protected ?int $userId = null;

    /**
     * @var CartItemInterface[]|null
     */
    protected ?array $items = null;

    public array $checkoutData = [];

    protected string $name;

    protected ?\DateTime $creationDate = null;

    protected ?int $creationDateTimestamp = null;

    protected ?\DateTime $modificationDate = null;

    protected ?int $modificationDateTimestamp = null;

    protected string|int|null $id = null;

    /**
     * @var CartItemInterface[]
     */
    protected array $giftItems = [];

    protected ?CartPriceCalculatorInterface $priceCalculator = null;

    protected ?int $itemAmount = null;

    protected ?int $subItemAmount = null;

    protected ?int $mainAndSubItemAmount = null;

    protected ?int $itemCount = null;

    protected ?int $subItemCount = null;

    protected ?int $mainAndSubItemCount = null;

    public function __construct()
    {
        $this->setCreationDate(new \DateTime());
    }

    abstract protected function getCartItemClassName(): string;

    abstract protected function getCartCheckoutDataClassName(): string;

    /**
     * @param CheckoutableInterface&Concrete $product
     * @param int $count
     * @param string|null $itemKey
     * @param bool $replace
     * @param array $params
     * @param AbstractSetProductEntry[] $subProducts
     * @param string|null $comment
     *
     * @return string
     */
    public function addItem(CheckoutableInterface $product, int $count, string $itemKey = null, bool $replace = false, array $params = [], array $subProducts = [], string $comment = null): string
    {
        if (empty($itemKey)) {
            $itemKey = (string) $product->getId();

            if (!empty($subProducts)) {
                $itemKey = $itemKey . '_' . uniqid();
            }
        }

        return $this->updateItem($itemKey, $product, $count, $replace, $params, $subProducts, $comment);
    }

    /**
     * @param string $itemKey
     * @param CheckoutableInterface&Concrete $product
     * @param int $count
     * @param bool $replace
     * @param array $params
     * @param AbstractSetProductEntry[] $subProducts
     * @param string|null $comment
     *
     * @return string
     */
    public function updateItem(string $itemKey, CheckoutableInterface $product, int $count, bool $replace = false, array $params = [], array $subProducts = [], string $comment = null): string
    {
        //load items first in order to lazyload items (if they are lazy loaded)
        $this->getItems();

        if (!array_key_exists($itemKey, $this->items)) {
            $className = $this->getCartItemClassName();
            $item = new $className();
            $item->setCart($this);
        } else {
            $item = $this->items[$itemKey];
        }

        $item->setProduct($product);
        $item->setItemKey($itemKey);
        if ($comment !== null) {
            $item->setComment($comment);
        }
        if ($replace) {
            $item->setCount($count);
        } else {
            $item->setCount($item->getCount() + $count);
        }

        if (!empty($subProducts)) {
            $subItems = [];
            foreach ($subProducts as $subProduct) {
                if (array_key_exists($subProduct->getProduct()->getId(), $subItems)) {
                    $subItem = $subItems[$subProduct->getProduct()->getId()];
                    $subItem->setCount($subItem->getCount() + $subProduct->getQuantity());
                } else {
                    $className = $this->getCartItemClassName();
                    $subItemKey = (string)$subProduct->getProduct()->getId();
                    $subItem = new $className();
                    $subItem->setCart($this);
                    $subItem->setItemKey($subItemKey);
                    $subItem->setProduct($subProduct->getProduct());
                    $subItem->setCount($subProduct->getQuantity());
                    $subItems[$subItemKey] = $subItem;
                }
            }
            $item->setSubItems($subItems);
        }

        $this->items[$itemKey] = $item;

        // trigger cart has been modified
        $this->modified();

        return $itemKey;
    }

    /**
     * updates count of specific cart item
     *
     * @param string $itemKey
     * @param int $count
     *
     * @return CartItemInterface
     */
    public function updateItemCount(string $itemKey, int $count): CartItemInterface
    {
        //load items first in order to lazyload items (if they are lazy loaded)
        $this->getItems();

        if (!empty($this->items[$itemKey])) {
            $this->items[$itemKey]->setCount($count);
        }

        return $this->items[$itemKey];
    }

    /**
     * @param CheckoutableInterface&Concrete $product
     * @param int $count
     * @param string|null $itemKey
     * @param bool $replace
     * @param array $params
     * @param array $subProducts
     * @param string|null $comment
     *
     * @return string
     */
    public function addGiftItem(CheckoutableInterface $product, int $count, string $itemKey = null, bool $replace = false, array $params = [], array $subProducts = [], string $comment = null): string
    {
        if (empty($itemKey)) {
            $itemKey = (string) $product->getId();

            if (!empty($subProducts)) {
                $itemKey = $itemKey . '_' . uniqid();
            }
        }

        return $this->updateGiftItem($itemKey, $product, $count, $replace, $params, $subProducts, $comment);
    }

    /**
     * @param string $itemKey
     * @param CheckoutableInterface&Concrete $product
     * @param int $count
     * @param bool $replace
     * @param array $params
     * @param array $subProducts
     * @param string|null $comment
     *
     * @return string
     */
    public function updateGiftItem(string $itemKey, CheckoutableInterface $product, int $count, bool $replace = false, array $params = [], array $subProducts = [], string $comment = null): string
    {
        // item already exists?
        if (!array_key_exists($itemKey, $this->giftItems)) {
            $className = $this->getCartItemClassName();
            $item = new $className();
            $item->setCart($this);
        } else {
            $item = $this->giftItems[$itemKey];
        }

        // update item
        $item->setProduct($product, false);
        $item->setItemKey($itemKey);
        $item->setComment((string)$comment);
        if ($replace) {
            $item->setCount($count, false);
        } else {
            $item->setCount($item->getCount() + $count, false);
        }

        // handle sub products
        if (!empty($subProducts)) {
            $subItems = [];
            foreach ($subProducts as $subProduct) {
                if (isset($subItems[$subProduct->getProduct()->getId()])) {
                    $subItem = $subItems[$subProduct->getProduct()->getId()];
                    $subItem->setCount($subItem->getCount() + $subProduct->getQuantity());
                } else {
                    $className = $this->getCartItemClassName();
                    $subItem = new $className();
                    $subItem->setCart($this);
                    $subItem->setItemKey($subProduct->getProduct()->getId());
                    $subItem->setProduct($subProduct->getProduct());
                    $subItem->setCount($subProduct->getQuantity());
                    $subItems[$subProduct->getProduct()->getId()] = $subItem;
                }
            }
            $item->setSubItems($subItems);
        }

        $this->giftItems[$itemKey] = $item;

        return $itemKey;
    }

    public function clear(): void
    {
        $this->items = [];
        $this->giftItems = [];

        $this->removeAllVoucherTokens();

        // trigger cart has been modified
        $this->modified();
    }

    /**
     * @param string $countSubItems - use one of COUNT_MAIN_ITEMS_ONLY, COUNT_MAIN_OR_SUB_ITEMS, COUNT_MAIN_AND_SUB_ITEMS
     *
     * @return int
     *
     * @throws InvalidConfigException
     */
    public function getItemAmount(string $countSubItems = self::COUNT_MAIN_ITEMS_ONLY): int
    {
        switch ($countSubItems) {
            case self::COUNT_MAIN_OR_SUB_ITEMS:

                if ($this->subItemAmount == null) {
                    $count = 0;
                    $items = $this->getItems();
                    if (!empty($items)) {
                        foreach ($items as $item) {
                            $subItems = $item->getSubItems();
                            if ($subItems) {
                                foreach ($subItems as $subItem) {
                                    $count += ($subItem->getCount() * $item->getCount());
                                }
                            } else {
                                $count += $item->getCount();
                            }
                        }
                    }
                    $this->subItemAmount = $count;
                }

                return $this->subItemAmount;

            case self::COUNT_MAIN_AND_SUB_ITEMS:

                if ($this->mainAndSubItemAmount == null) {
                    $count = 0;
                    $items = $this->getItems();
                    if (!empty($items)) {
                        foreach ($items as $item) {
                            $subItems = $item->getSubItems();
                            if ($subItems) {
                                foreach ($subItems as $subItem) {
                                    $count += ($subItem->getCount() * $item->getCount());
                                }
                            }
                            $count += $item->getCount();
                        }
                    }
                    $this->mainAndSubItemAmount = $count;
                }

                return $this->mainAndSubItemAmount;

            case self::COUNT_MAIN_ITEMS_ONLY:

                if ($this->itemAmount == null) {
                    $count = 0;
                    $items = $this->getItems();
                    if (!empty($items)) {
                        foreach ($items as $item) {
                            $count += $item->getCount();
                        }
                    }
                    $this->itemAmount = $count;
                }

                return $this->itemAmount;

            default:
                throw new InvalidConfigException('Invalid value for $countSubItems: ' . $countSubItems);
        }
    }

    /**
     * @param string $countSubItems - use one of COUNT_MAIN_ITEMS_ONLY, COUNT_MAIN_OR_SUB_ITEMS, COUNT_MAIN_AND_SUB_ITEMS
     *
     * @return int
     *
     * @throws InvalidConfigException
     */
    public function getItemCount(string $countSubItems = self::COUNT_MAIN_ITEMS_ONLY): int
    {
        switch ($countSubItems) {
            case self::COUNT_MAIN_OR_SUB_ITEMS:

                if ($this->subItemCount == null) {
                    $items = $this->getItems();
                    $count = 0;

                    if (!empty($items)) {
                        foreach ($items as $item) {
                            $subItems = $item->getSubItems();
                            if (!empty($subItems)) {
                                $count += count($subItems);
                            } else {
                                $count++;
                            }
                        }
                    }
                    $this->subItemCount = $count;
                }

                return $this->subItemCount;

            case self::COUNT_MAIN_AND_SUB_ITEMS:

                if ($this->mainAndSubItemCount == null) {
                    $items = $this->getItems();
                    $count = count($items);

                    if (!empty($items)) {
                        foreach ($items as $item) {
                            $subItems = $item->getSubItems();
                            $count += count($subItems);
                        }
                    }
                    $this->mainAndSubItemCount = $count;
                }

                return $this->mainAndSubItemCount;

            case self::COUNT_MAIN_ITEMS_ONLY:

                if ($this->itemCount == null) {
                    $items = $this->getItems();
                    $this->itemCount = count($items);
                }

                return $this->itemCount;

            default:
                throw new InvalidConfigException('Invalid value for $countSubItems: ' . $countSubItems);
        }
    }

    public function getItems(): array
    {
        $this->items = $this->items ? $this->items : [];

        return $this->items;
    }

    public function getItem(string $itemKey): ?CartItemInterface
    {
        //load items first in order to lazyload items (if they are lazy loaded)
        $this->getItems();

        return array_key_exists($itemKey, $this->items) ? $this->items[$itemKey] : null;
    }

    public function isEmpty(): bool
    {
        return count($this->getItems()) === 0;
    }

    /**
     * @return CartItemInterface[]
     */
    public function getGiftItems(): array
    {
        //make sure that cart is calculated
        if (!$this->getPriceCalculator()->isCalculated()) {
            $this->getPriceCalculator()->calculate();
        }

        return $this->giftItems;
    }

    public function getGiftItem(string $itemKey): ?CartItemInterface
    {
        //make sure that cart is calculated
        if (!$this->getPriceCalculator()->isCalculated()) {
            $this->getPriceCalculator()->calculate();
        }

        return array_key_exists($itemKey, $this->giftItems) ? $this->giftItems[$itemKey] : null;
    }

    /**
     * @param CartItemInterface[]|null $items
     */
    public function setItems(?array $items)
    {
        $this->items = $items;

        // trigger cart has been modified
        $this->modified();
    }

    public function removeItem(string $itemKey): void
    {
        //load items first in order to lazyload items (if they are lazy loaded)
        $this->getItems();

        unset($this->items[$itemKey]);

        // trigger cart has been modified
        $this->modified();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getIsBookable(): bool
    {
        foreach ($this->getItems() as $item) {
            if (!$item->getProduct()->getOSIsBookable($item->getCount())) {
                return false;
            }
        }

        return true;
    }

    public function setId(int|string $id)
    {
        $this->id = $id;
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getCreationDate(): \DateTime
    {
        if (empty($this->creationDate) && $this->creationDateTimestamp) {
            $this->creationDate = new \DateTime();
            $this->creationDate->setTimestamp($this->creationDateTimestamp);
        }

        return $this->creationDate;
    }

    /**
     * @param \DateTime|null $creationDate
     */
    public function setCreationDate(\DateTime $creationDate = null): void
    {
        $this->creationDate = $creationDate;
        if ($creationDate) {
            $this->creationDateTimestamp = $creationDate->getTimestamp();
        } else {
            $this->creationDateTimestamp = null;
        }
    }

    public function setCreationDateTimestamp(int $creationDateTimestamp)
    {
        $this->creationDateTimestamp = $creationDateTimestamp;
        $this->creationDate = null;
    }

    public function getCreationDateTimestamp(): ?int
    {
        return $this->creationDateTimestamp;
    }

    public function getModificationDate(): ?\DateTime
    {
        if (empty($this->modificationDate) && $this->modificationDateTimestamp) {
            $this->modificationDate = new \DateTime();
            $this->modificationDate->setTimestamp($this->modificationDateTimestamp);
        }

        return $this->modificationDate;
    }

    /**
     * @param \DateTime|null $modificationDate
     */
    public function setModificationDate(\DateTime $modificationDate = null): void
    {
        $this->modificationDate = $modificationDate;
        if ($modificationDate) {
            $this->modificationDateTimestamp = $modificationDate->getTimestamp();
        } else {
            $this->modificationDateTimestamp = null;
        }
    }

    public function setModificationDateTimestamp(int $modificationDateTimestamp)
    {
        $this->modificationDateTimestamp = $modificationDateTimestamp;
        $this->modificationDate = null;
    }

    public function getModificationDateTimestamp(): ?int
    {
        return $this->modificationDateTimestamp;
    }

    public function getUserId(): int
    {
        return $this->userId ?: Factory::getInstance()->getEnvironment()->getCurrentUserId();
    }

    public function setUserId(int $userId)
    {
        $this->userId = (int)$userId;
    }

    abstract public function save(): void;

    abstract public function delete(): void;

    public function getCheckoutData(string $key): ?string
    {
        $entry = $this->checkoutData[$key] ?? null;
        if ($entry) {
            return $this->checkoutData[$key]->getData();
        }

        return null;
    }

    public function setCheckoutData(string $key, string $data)
    {
        $className = $this->getCartCheckoutDataClassName();
        $value = new $className();
        $value->setCart($this);
        $value->setKey($key);
        $value->setData($data);
        $this->checkoutData[$key] = $value;
    }

    public function getPriceCalculator(): CartPriceCalculatorInterface
    {
        if (empty($this->priceCalculator)) {
            $this->priceCalculator = Factory::getInstance()->getCartManager()->getCartPriceCalculator($this);
        }

        return $this->priceCalculator;
    }

    public function setPriceCalculator(CartPriceCalculatorInterface $priceCalculator)
    {
        $this->priceCalculator = $priceCalculator;
    }

    public function modified(): static
    {
        $this->setModificationDateTimestamp(time());

        $this->itemAmount = null;
        $this->subItemAmount = null;
        $this->mainAndSubItemAmount = null;
        $this->itemCount = null;
        $this->subItemCount = null;
        $this->mainAndSubItemCount = null;

        //don't use getter here because reset is only necessary if price calculator is already there
        if ($this->priceCalculator) {
            $this->priceCalculator->reset();
        }

        $this->validateVoucherTokenReservations();

        $this->giftItems = [];

        return $this;
    }

    /**
     * @param int $count
     *
     * @return array<int, CartItemInterface>
     */
    public function getRecentlyAddedItems(int $count): array
    {
        // get last items
        $index = [];
        foreach ($this->getItems() as $item) {
            $index[$item->getAddedDate()->getTimestamp()] = $item;
        }
        krsort($index);

        return array_slice($index, 0, $count);
    }

    /**
     * sorts all items in cart according to a given callback function
     *
     * @param callable $value_compare_func
     *
     * @return $this
     */
    public function sortItems(callable $value_compare_func): static
    {
        return $this;
    }

    /**
     * Adds a voucher token to the cart's checkout data and reserves it.
     *
     * @param string $code
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function addVoucherToken(string $code): bool
    {
        $service = Factory::getInstance()->getVoucherService();
        if ($service->checkToken($code, $this)) {
            if ($service->reserveToken($code, $this)) {
                $index = 'voucher_' . $code;
                $this->setCheckoutData($index, $code);
                $this->save();

                $this->modified();

                return true;
            }
        }

        return false;
    }

    /**
     * Checks if an error code is a defined Voucher Error Code.
     *
     * @param int $errorCode
     *
     * @return bool
     */
    public function isVoucherErrorCode($errorCode): bool
    {
        return $errorCode > 0 && $errorCode < 10;
    }

    /**
     * Removes all tokens form cart and releases the token reservations.
     *
     * @throws InvalidConfigException
     */
    public function removeAllVoucherTokens()
    {
        foreach ($this->getVoucherTokenCodes() as $code) {
            $this->removeVoucherToken($code);
        }
    }

    /**
     * Removes a token from cart and releases token reservation.
     *
     * @param string $code
     *
     * @return bool
     *
     *@throws \Exception
     *
     */
    public function removeVoucherToken(string $code): bool
    {
        $service = Factory::getInstance()->getVoucherService();
        $key = array_search($code, $this->getVoucherTokenCodes());

        if ($key !== false) {
            if ($service->releaseToken($code, $this)) {
                unset($this->checkoutData['voucher_' . $code]);
                $this->save();

                $this->modified();

                return true;
            }
        } else {
            throw new VoucherServiceException('No Token with code ' . $code . ' in this cart.', VoucherServiceException::ERROR_CODE_NOT_FOUND_IN_CART);
        }

        return false;
    }

    /**
     * Filters checkout data and returns an array of strings with the assigns tokens.
     *
     * @return string[]
     */
    public function getVoucherTokenCodes(): array
    {
        $tokens = [];
        foreach ($this->checkoutData as $key => $value) {
            $exp_key = explode('_', $key);
            if ($exp_key[0] == 'voucher') {
                $tokens[] = $value->getData();
            }
        }

        return $tokens;
    }

    /**
     * @return PricingManagerTokenInformation[]
     */
    public function getPricingManagerTokenInformationDetails(): array
    {
        $voucherService = Factory::getInstance()->getVoucherService();

        return $voucherService->getPricingManagerTokenInformationDetails($this);
    }

    /**
     * Checks if checkout data voucher tokens are valid reservations
     */
    protected function validateVoucherTokenReservations()
    {
        if ($this->getVoucherTokenCodes()) {
            $order = Factory::getInstance()->getOrderManager()->getOrderFromCart($this);
            $appliedVoucherCodes = [];
            if ($order) {
                foreach ($order->getVoucherTokens() as $voucherToken) {
                    $appliedVoucherCodes[$voucherToken->getToken()] = $voucherToken->getToken();
                }
            }

            //check for each voucher token if reservation is valid or it is already applied to order
            foreach ($this->getVoucherTokenCodes() as $code) {
                $reservation = Reservation::get($code, $this);
                if (!$reservation && !array_key_exists($code, $appliedVoucherCodes)) {
                    unset($this->checkoutData['voucher_'.$code]);
                }
            }
        }
    }

    /**
     * Should be added to the cart
     *
     * @param CartItemInterface $item
     *
     * @return bool
     */
    protected static function isValidCartItem(CartItemInterface $item): bool
    {
        $product = $item->getProduct();
        if ($product instanceof CheckoutableInterface && !$product instanceof MockProduct) {
            return true;
        }

        Logger::warn('Product ' . $item->getProduct()->getId() . ' not found');

        return false;
    }
}
