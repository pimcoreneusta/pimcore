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

namespace Pimcore\Model;

/**
 * @internal
 *
 * @method Dependency\Dao getDao()
 * @method void save()
 */
class Dependency extends AbstractModel
{
    /**
     * The ID of the object to get dependencies for
     *
     * @var int
     */
    protected int $sourceId;

    /**
     * The type of the object to get dependencies for
     *
     * @var string
     */
    protected string $sourceType;

    /**
     * Contains the ID/type of objects which are required for the given source object (sourceId/sourceType)
     *
     * @var array
     */
    protected array $requires = [];

    /**
     * Static helper to get the dependencies for the given sourceId & type
     *
     * @param int $id
     * @param string $type
     *
     * @return Dependency
     */
    public static function getBySourceId(int $id, string $type): Dependency
    {
        $d = new self();
        $d->getDao()->getBySourceId($id, $type);

        return $d;
    }

    /**
     * Add a requirement to the source object
     *
     * @param int $id
     * @param string $type
     */
    public function addRequirement(int $id, string $type)
    {
        $this->requires[] = [
            'type' => $type,
            'id' => $id,
        ];
    }

    /**
     * Used when element gets deleted. Removes entries (by source = element) and
     * schedules a sanity check for the affected targets.
     *
     * @param Element\ElementInterface $element
     */
    public function cleanAllForElement(Element\ElementInterface $element)
    {
        $this->getDao()->cleanAllForElement($element);
    }

    /**
     * Cleanup the dependencies for current source id.
     * Can be used for updating the dependencies.
     */
    public function clean()
    {
        $this->requires = [];
        $this->getDao()->clear();
    }

    public function getSourceId(): int
    {
        return $this->sourceId;
    }

    /**
     * @param int|null $offset
     * @param int|null $limit
     *
     * @return array
     */
    public function getRequires(int $offset = null, int $limit = null): array
    {
        if ($offset !== null) {
            return array_slice($this->requires, $offset, $limit);
        }

        return $this->requires;
    }

    /**
     * @param int|null $offset
     * @param int|null $limit
     *
     * @return array
     */
    public function getRequiredBy(int $offset = null, int $limit = null): array
    {
        return $this->getDao()->getRequiredBy($offset, $limit);
    }

    /**
     * @param string|null $orderBy
     * @param string|null $orderDirection
     * @param int|null $offset
     * @param int|null $limit
     *
     * @return array
     */
    public function getRequiredByWithPath(int $offset = null, int $limit = null, string $orderBy = null, string $orderDirection = null): array
    {
        return $this->getDao()->getRequiredByWithPath($offset, $limit, $orderBy, $orderDirection);
    }

    public function setSourceId(int $sourceId): static
    {
        $this->sourceId = (int) $sourceId;

        return $this;
    }

    public function setRequires(array $requires): static
    {
        $this->requires = $requires;

        return $this;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $sourceType): static
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getRequiresTotalCount(): int
    {
        return count($this->requires);
    }

    public function getRequiredByTotalCount(): int
    {
        return $this->getDao()->getRequiredByTotalCount();
    }

    /**
     * Check if the source object is required by an other object (an other object depends on this object)
     *
     * @return bool
     */
    public function isRequired(): bool
    {
        return $this->getRequiredByTotalCount() > 0;
    }
}
