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

namespace Pimcore\Model\Document;

use Pimcore\Logger;
use Pimcore\Model;
use Pimcore\Model\Asset;
use Pimcore\Model\Document;

/**
 * @method \Pimcore\Model\Document\Link\Dao getDao()
 */
class Link extends Model\Document
{
    use Model\Element\Traits\ScheduledTasksTrait;

    /**
     * Contains the ID of the internal ID
     *
     * @internal
     *
     * @var int|null
     */
    protected ?int $internal = null;

    /**
     * Contains the type of the internal ID
     *
     * @internal
     *
     * @var string|null
     */
    protected ?string $internalType = null;

    /**
     * Contains object of linked Document|Asset|DataObject
     *
     * @internal
     *
     * @var Model\Element\ElementInterface|null
     */
    protected ?Model\Element\ElementInterface $object = null;

    /**
     * Contains the direct link as plain text
     *
     * @internal
     */
    protected string $direct = '';

    /**
     * Type of the link (internal/direct)
     *
     * @internal
     */
    protected string $linktype = 'internal';

    /**
     * {@inheritdoc}
     */
    protected string $type = 'link';

    /**
     * path of the link
     *
     * @internal
     */
    protected string $href = '';

    /**
     * {@inheritdoc}
     */
    protected function resolveDependencies(): array
    {
        $dependencies = parent::resolveDependencies();

        if ($this->getLinktype() === 'internal') {
            $element = $this->getElement();

            if ($element instanceof Document || $element instanceof Asset) {
                $key = $this->getInternalType() . '_' . $element->getId();

                $dependencies[$key] = [
                    'id' => $element->getId(),
                    'type' => $this->getInternalType(),
                ];
            }
        }

        return $dependencies;
    }

    public function getCacheTags(array $tags = []): array
    {
        $tags = parent::getCacheTags($tags);

        if ($this->getLinktype() === 'internal') {
            $element = $this->getElement();
            if ($element instanceof Document || $element instanceof Asset) {
                if ($element->getId() != $this->getId() && !array_key_exists($element->getCacheTag(), $tags)) {
                    $tags = $element->getCacheTags($tags);
                }
            }
        }

        return $tags;
    }

    /**
     * Returns the plain text path of the link
     *
     * @return string
     */
    public function getHref(): string
    {
        $path = '';
        if ($this->getLinktype() === 'internal') {
            $element = $this->getElement();
            if ($element instanceof Document || $element instanceof Asset) {
                $path = $element->getFullPath();
            } else {
                if ($element instanceof Model\DataObject\Concrete) {
                    if ($linkGenerator = $element->getClass()->getLinkGenerator()) {
                        $path = $linkGenerator->generate(
                            $element,
                            [
                                'document' => $this,
                                'context' => $this,
                            ]
                        );
                    }
                }
            }
        } else {
            $path = $this->getDirect();
        }

        $this->href = $path;

        return $path;
    }

    /**
     * Returns the plain text path of the link needed for the editmode
     *
     * @return string
     */
    public function getRawHref(): string
    {
        $rawHref = '';
        if ($this->getLinktype() === 'internal') {
            $element = $this->getElement();
            if (
                $element instanceof Document ||
                $element instanceof Asset ||
                $element instanceof Model\DataObject\Concrete
            ) {
                $rawHref = $element->getFullPath();
            }
        } else {
            $rawHref = $this->getDirect();
        }

        return $rawHref;
    }

    /**
     * Returns the path of the link including the anchor and parameters
     *
     * @return string
     */
    public function getLink(): string
    {
        $path = $this->getHref();

        $parameters = $this->getProperty('navigation_parameters');
        if (strlen($parameters) > 0) {
            $path .= '?' . str_replace('?', '', $parameters);
        }

        $anchor = $this->getProperty('navigation_anchor');
        if (strlen($anchor) > 0) {
            $path .= '#' . str_replace('#', '', $anchor);
        }

        return $path;
    }

    /**
     * Returns the id of the internal document|asset which is linked
     *
     * @return int|null
     */
    public function getInternal(): ?int
    {
        return $this->internal;
    }

    /**
     * Returns the direct link (eg. http://www.pimcore.org/test)
     *
     * @return string
     */
    public function getDirect(): string
    {
        return $this->direct;
    }

    /**
     * Returns the type of the link (internal/direct)
     *
     * @return string
     */
    public function getLinktype(): string
    {
        return $this->linktype;
    }

    public function setInternal(int $internal): static
    {
        if (!empty($internal)) {
            $this->internal = (int) $internal;
            $this->setObjectFromId();
        } else {
            $this->internal = null;
        }

        return $this;
    }

    public function setDirect(string $direct): static
    {
        $this->direct = $direct;

        return $this;
    }

    public function setLinktype(string $linktype): static
    {
        $this->linktype = $linktype;

        return $this;
    }

    public function getInternalType(): ?string
    {
        return $this->internalType;
    }

    public function setInternalType(?string $type): static
    {
        $this->internalType = $type;

        return $this;
    }

    public function getElement(): ?Model\Element\ElementInterface
    {
        if ($this->object instanceof Model\Element\ElementInterface) {
            return $this->object;
        }
        if ($this->setObjectFromId()) {
            return $this->object;
        }

        return null;
    }

    public function setElement(?Model\Element\ElementInterface $element): static
    {
        $this->object = $element;

        return $this;
    }

    /**
     * @deprecated use getElement() instead, will be removed in Pimcore 11
     *
     * @return Model\Element\ElementInterface|null
     */
    public function getObject(): ?Model\Element\ElementInterface
    {
        trigger_deprecation(
            'pimcore/pimcore',
            '10.0',
            'The Link::getObject() method is deprecated, use Link::getElement() instead.'
        );

        return $this->getElement();
    }

    /**
     * @param Model\Element\ElementInterface $object
     *
     * @return $this
     *
     *@deprecated use getElement() instead, will be removed in Pimcore 11
     *
     */
    public function setObject(Model\Element\ElementInterface $object): static
    {
        trigger_deprecation(
            'pimcore/pimcore',
            '10.0',
            'The Link::setObject() method is deprecated, use Link::setElement() instead.'
        );

        $this->setElement($object);

        return $this;
    }

    private function setObjectFromId(): ?Model\Element\ElementInterface
    {
        try {
            if ($this->internal) {
                if ($this->internalType == 'document') {
                    if ($this->getId() == $this->internal) {
                        throw new \Exception('Prevented infinite redirection loop: attempted to linking "' . $this->getKey() . '" to itself. ');
                    }
                    $this->object = Document::getById($this->internal);
                } elseif ($this->internalType == 'asset') {
                    $this->object = Asset::getById($this->internal);
                } elseif ($this->internalType == 'object') {
                    $this->object = Model\DataObject\Concrete::getById($this->internal);
                }
            }
        } catch (\Exception $e) {
            Logger::warn((string) $e);
            $this->internalType = '';
            $this->internal = null;
            $this->object = null;
        }

        return $this->object;
    }

    /**
     * returns the ready-use html for this link
     *
     * @return string
     */
    public function getHtml(): string
    {
        $attributes = [
            'class',
            'target',
            'title',
            'accesskey',
            'tabindex',
            'rel' => 'relation',
        ];

        $link = $this->getLink();
        $link .= $this->getProperty('navigation_parameters') . $this->getProperty('navigation_anchor');

        $attribs = [];
        foreach ($attributes as $key => $name) {
            $key = is_numeric($key) ? $name : $key;
            $value = $this->getProperty('navigation_' . $name);
            if ($value) {
                $attribs[] = $key . '="' . $value . '"';
            }
        }

        return '<a href="' . $link . '" ' . implode(' ', $attribs) . '>' . htmlspecialchars($this->getProperty('navigation_name')) . '</a>';
    }

    /**
     * {@inheritdoc}
     */
    protected function update(array $params = [])
    {
        parent::update($params);

        $this->saveScheduledTasks();
    }

    public function __sleep()
    {
        $finalVars = [];
        $parentVars = parent::__sleep();

        $blockedVars = ['object'];

        foreach ($parentVars as $key) {
            if (!in_array($key, $blockedVars)) {
                $finalVars[] = $key;
            }
        }

        return $finalVars;
    }
}
