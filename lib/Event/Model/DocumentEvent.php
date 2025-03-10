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

namespace Pimcore\Event\Model;

use Pimcore\Event\Traits\ArgumentsAwareTrait;
use Pimcore\Model\Document;
use Symfony\Contracts\EventDispatcher\Event;

class DocumentEvent extends Event implements ElementEventInterface
{
    use ArgumentsAwareTrait;

    protected Document $document;

    /**
     * DocumentEvent constructor.
     *
     * @param Document $document
     * @param array $arguments
     */
    public function __construct(Document $document, array $arguments = [])
    {
        $this->document = $document;
        $this->arguments = $arguments;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function setDocument(Document $document)
    {
        $this->document = $document;
    }

    public function getElement(): Document
    {
        return $this->getDocument();
    }
}
