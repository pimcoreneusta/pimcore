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

namespace Pimcore\Extension\Document\Areabrick;

use Pimcore\Extension\Document\Areabrick\Exception\ConfigurationException;
use Pimcore\Model\Document\Editable;
use Pimcore\Model\Document\Editable\Area\Info;
use Pimcore\Model\Document\PageSnippet;
use Pimcore\Templating\Renderer\EditableRenderer;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

abstract class AbstractAreabrick implements AreabrickInterface, TemplateAreabrickInterface, ContainerAwareInterface
{
    use ContainerAwareTrait;

    protected EditableRenderer $editableRenderer;

    /**
     * Called in AreabrickPass
     *
     * @param EditableRenderer $editableRenderer
     */
    public function setEditableRenderer(EditableRenderer $editableRenderer)
    {
        $this->editableRenderer = $editableRenderer;
    }

    protected ?string $id = null;

    /**
     * {@inheritdoc}
     */
    public function setId(string $id)
    {
        // make sure ID is only set once
        if (null !== $this->id) {
            throw new ConfigurationException(sprintf(
                'Brick ID is immutable (trying to set ID %s for brick %s)',
                $id,
                $this->id
            ));
        }

        $this->id = $id;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->id ? ucfirst($this->id) : '';
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion(): string
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getIcon(): ?string
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function hasTemplate(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function action(Info $info): ?\Symfony\Component\HttpFoundation\Response
    {
        // noop - implement as needed
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function postRenderAction(Info $info): ?\Symfony\Component\HttpFoundation\Response
    {
        // noop - implement as needed
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getHtmlTagOpen(Info $info): string
    {
        return '<div class="pimcore_area_' . $info->getId() . ' pimcore_area_content '. $this->getOpenTagCssClass($info) .'">';
    }

    protected function getOpenTagCssClass(Info $info): ?string
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getHtmlTagClose(Info $info): string
    {
        return '</div>';
    }

    protected function getDocumentEditable(PageSnippet $document, string $type, string $inputName, array $options = []): Editable\EditableInterface
    {
        return $this->editableRenderer->getEditable($document, $type, $inputName, $options);
    }

    public function needsReload(): bool
    {
        return false;
    }
}
