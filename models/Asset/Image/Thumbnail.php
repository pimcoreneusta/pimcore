<?php

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

namespace Pimcore\Model\Asset\Image;

use Pimcore\Event\AssetEvents;
use Pimcore\Event\FrontendEvents;
use Pimcore\Logger;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Image;
use Pimcore\Model\Asset\Thumbnail\ImageThumbnailTrait;
use Pimcore\Model\Exception\NotFoundException;
use Pimcore\Tool;
use Symfony\Component\EventDispatcher\GenericEvent;

final class Thumbnail
{
    use ImageThumbnailTrait;

    /**
     * @internal
     *
     * @var bool[]
     */
    protected static array $hasListenersCache = [];

    /**
     * @param Image $asset
     * @param string|array|Thumbnail\Config|null $config
     * @param bool $deferred
     */
    public function __construct(Image $asset, array|string|Thumbnail\Config $config = null, bool $deferred = true)
    {
        $this->asset = $asset;
        $this->deferred = $deferred;
        $this->config = $this->createConfig($config ?? []);
    }

    public function getPath(bool $deferredAllowed = true, bool $cacheBuster = false): string
    {
        $pathReference = null;
        if ($this->getConfig()) {
            if ($this->useOriginalFile($this->asset->getFilename()) && $this->getConfig()->isSvgTargetFormatPossible()) {
                // we still generate the raster image, to get the final size of the thumbnail
                // we use getRealFullPath() here, to avoid double encoding (getFullPath() returns already encoded path)
                $pathReference = [
                    'src' => $this->asset->getRealFullPath(),
                    'type' => 'asset',
                ];
            }
        }

        if (!$pathReference) {
            $pathReference = $this->getPathReference($deferredAllowed);
        }

        $path = $this->convertToWebPath($pathReference);

        if ($cacheBuster) {
            $path = $this->addCacheBuster($path, ['cacheBuster' => true], $this->getAsset());
        }

        if ($this->hasListeners(FrontendEvents::ASSET_IMAGE_THUMBNAIL)) {
            $event = new GenericEvent($this, [
                'pathReference' => $pathReference,
                'frontendPath' => $path,
            ]);
            \Pimcore::getEventDispatcher()->dispatch($event, FrontendEvents::ASSET_IMAGE_THUMBNAIL);
            $path = $event->getArgument('frontendPath');
        }

        return $path;
    }

    protected function hasListeners(string $eventName): bool
    {
        if (!isset(self::$hasListenersCache[$eventName])) {
            self::$hasListenersCache[$eventName] = \Pimcore::getEventDispatcher()->hasListeners($eventName);
        }

        return self::$hasListenersCache[$eventName];
    }

    protected function useOriginalFile(string $filename): bool
    {
        if ($this->getConfig()) {
            if (!$this->getConfig()->isRasterizeSVG() && preg_match("@\.svgz?$@", $filename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool $deferredAllowed
     *
     * @internal
     *
     */
    public function generate(bool $deferredAllowed = true): void
    {
        $deferred = false;
        $generated = false;

        if ($this->asset && empty($this->pathReference)) {
            // if no correct thumbnail config is given use the original image as thumbnail
            if (!$this->config) {
                $this->pathReference = [
                    'type' => 'asset',
                    'src' => $this->asset->getRealFullPath(),
                ];
            } else {
                try {
                    $deferred = $deferredAllowed && $this->deferred;
                    $this->pathReference = Thumbnail\Processor::process($this->asset, $this->config, null, $deferred, $generated);
                } catch (\Exception $e) {
                    Logger::error("Couldn't create thumbnail of image " . $this->asset->getRealFullPath());
                    Logger::error($e->getMessage());
                }
            }
        }

        if (empty($this->pathReference)) {
            $this->pathReference = [
                'type' => 'error',
                'src' => '/bundles/pimcoreadmin/img/filetype-not-supported.svg',
            ];
        }

        if ($this->hasListeners(AssetEvents::IMAGE_THUMBNAIL)) {
            $event = new GenericEvent($this, [
                'deferred' => $deferred,
                'generated' => $generated,
            ]);
            \Pimcore::getEventDispatcher()->dispatch($event, AssetEvents::IMAGE_THUMBNAIL);
        }
    }

    /**
     * @return string Public path to thumbnail image.
     */
    public function __toString()
    {
        return $this->getPath(true);
    }

    private function addCacheBuster(string $path, array $options, Asset $asset): string
    {
        if (isset($options['cacheBuster']) && $options['cacheBuster']) {
            if (!str_starts_with($path, 'http')) {
                $path = '/cache-buster-' . $asset->getVersionCount() . $path;
            }
        }

        return $path;
    }

    private function getSourceTagHtml(Image\Thumbnail\Config $thumbConfig, string $mediaQuery, Image $image, array $options): string
    {
        $sourceTagAttributes = [];
        $sourceTagAttributes['srcset'] = $this->getSrcset($thumbConfig, $image, $options, $mediaQuery);
        $thumb = $image->getThumbnail($thumbConfig, true);

        if ($mediaQuery) {
            $sourceTagAttributes['media'] = $mediaQuery;
            $thumb->reset();
        }

        if (isset($options['previewDataUri'])) {
            $sourceTagAttributes['data-srcset'] = $sourceTagAttributes['srcset'];
            unset($sourceTagAttributes['srcset']);
        }

        if (!isset($options['disableWidthHeightAttributes'])) {
            if ($thumb->getWidth()) {
                $sourceTagAttributes['width'] = $thumb->getWidth();
            }

            if ($thumb->getHeight()) {
                $sourceTagAttributes['height'] = $thumb->getHeight();
            }
        }

        $sourceTagAttributes['type'] = $thumb->getMimeType();

        $sourceCallback = $options['sourceCallback'] ?? null;
        if ($sourceCallback) {
            $sourceTagAttributes = $sourceCallback($sourceTagAttributes);
        }

        return '<source ' . array_to_html_attribute_string($sourceTagAttributes) . ' />';
    }

    /**
     * Get generated HTML for displaying the thumbnail image in a HTML document.
     *
     * @param array $options Custom configuration
     *
     * @return string
     */
    public function getHtml(array $options = []): string
    {
        /** @var Image $image */
        $image = $this->getAsset();
        $thumbConfig = $this->getConfig();

        $pictureTagAttributes = $options['pictureAttributes'] ?? []; // this is used for the html5 <picture> element

        if ((isset($options['lowQualityPlaceholder']) && $options['lowQualityPlaceholder']) && !Tool::isFrontendRequestByAdmin()) {
            $previewDataUri = $image->getLowQualityPreviewDataUri();
            if (!$previewDataUri) {
                // use a 1x1 transparent GIF as a fallback if no LQIP exists
                $previewDataUri = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
            }

            // this gets used in getImagTag() later
            $options['previewDataUri'] = $previewDataUri;
        }

        $isAutoFormat = $thumbConfig instanceof Image\Thumbnail\Config ? strtolower($thumbConfig->getFormat()) === 'source' : false;

        if ($isAutoFormat) {
            // ensure the default image is not WebP
            $this->pathReference = [];
        }

        $pictureCallback = $options['pictureCallback'] ?? null;
        if ($pictureCallback) {
            $pictureTagAttributes = $pictureCallback($pictureTagAttributes);
        }

        $html = '<picture ' . array_to_html_attribute_string($pictureTagAttributes) . '>' . "\n";

        if ($thumbConfig instanceof Image\Thumbnail\Config) {
            $mediaConfigs = $thumbConfig->getMedias();

            // currently only max-width is supported, the key of the media is WIDTHw (eg. 400w) according to the srcset specification
            ksort($mediaConfigs, SORT_NUMERIC);
            array_push($mediaConfigs, $thumbConfig->getItems()); //add the default config at the end - picturePolyfill v4

            foreach ($mediaConfigs as $mediaQuery => $config) {
                $sourceHtml = $this->getSourceTagHtml($thumbConfig, $mediaQuery, $image, $options);
                if (!empty($sourceHtml)) {
                    if ($isAutoFormat) {
                        foreach ($thumbConfig->getAutoFormatThumbnailConfigs() as $autoFormatConfig) {
                            $autoFormatThumbnailHtml = $this->getSourceTagHtml($autoFormatConfig, $mediaQuery, $image, $options);
                            if (!empty($autoFormatThumbnailHtml)) {
                                $html .= "\t" . $autoFormatThumbnailHtml . "\n";
                            }
                        }
                    }

                    $html .= "\t" . $sourceHtml . "\n";
                }
            }
        }

        if (!($options['disableImgTag'] ?? null)) {
            $html .= "\t" . $this->getImageTag($options) . "\n";
        }

        $html .= '</picture>' . "\n";

        if (isset($options['useDataSrc']) && $options['useDataSrc']) {
            $html = preg_replace('/ src(set)?=/i', ' data-src$1=', $html);
        }

        return $html;
    }

    public function getImageTag(array $options = [], array $removeAttributes = []): string
    {
        /** @var Image $image */
        $image = $this->getAsset();
        $attributes = $options['imgAttributes'] ?? [];
        $callback = $options['imgCallback'] ?? null;

        if (isset($options['previewDataUri'])) {
            $attributes['src'] = $options['previewDataUri'];
        } else {
            $path = $this->getPath(true);
            $attributes['src'] = $this->addCacheBuster($path, $options, $image);
        }

        if (!isset($options['disableWidthHeightAttributes'])) {
            if ($this->getWidth()) {
                $attributes['width'] = $this->getWidth();
            }

            if ($this->getHeight()) {
                $attributes['height'] = $this->getHeight();
            }
        }

        $altText = !empty($options['alt']) ? $options['alt'] : (!empty($attributes['alt']) ? $attributes['alt'] : '');
        $titleText = !empty($options['title']) ? $options['title'] : (!empty($attributes['title']) ? $attributes['title'] : '');

        if (empty($titleText) && (!isset($options['disableAutoTitle']) || !$options['disableAutoTitle'])) {
            if ($image->getMetadata('title')) {
                $titleText = $image->getMetadata('title');
            }
        }

        if (empty($altText) && (!isset($options['disableAutoAlt']) || !$options['disableAutoAlt'])) {
            if ($image->getMetadata('alt')) {
                $altText = $image->getMetadata('alt');
            } elseif (isset($options['defaultalt'])) {
                $altText = $options['defaultalt'];
            } else {
                $altText = $titleText;
            }
        }

        // get copyright from asset
        if ($image->getMetadata('copyright') && (!isset($options['disableAutoCopyright']) || !$options['disableAutoCopyright'])) {
            if (!empty($altText)) {
                $altText .= ' | ';
            }
            if (!empty($titleText)) {
                $titleText .= ' | ';
            }
            $altText .= ('© ' . $image->getMetadata('copyright'));
            $titleText .= ('© ' . $image->getMetadata('copyright'));
        }

        $attributes['alt'] = $altText;
        if (!empty($titleText)) {
            $attributes['title'] = $titleText;
        }

        if (!isset($attributes['loading'])) {
            $attributes['loading'] = 'lazy';
        }

        foreach ($removeAttributes as $attribute) {
            unset($attributes[$attribute]);
        }

        if ($callback) {
            $attributes = $callback($attributes);
        }

        $thumbConfig = $this->getConfig();
        if ($thumbConfig) {
            $srcsetAttribute = isset($options['previewDataUri']) ? 'data-srcset' : 'srcset';

            $attributes[$srcsetAttribute] = $this->getSrcset($thumbConfig, $image, $options);
        }

        $htmlImgTag = '';
        if (!empty($attributes)) {
            $htmlImgTag = '<img ' . array_to_html_attribute_string($attributes) . ' />';
        }

        return $htmlImgTag;
    }

    /**
     * @param string $name
     * @param int $highRes
     *
     * @return Thumbnail
     *
     * @throws \Exception
     */
    public function getMedia(string $name, int $highRes = 1): Thumbnail
    {
        $thumbConfig = $this->getConfig();
        $mediaConfigs = $thumbConfig->getMedias();

        if (isset($mediaConfigs[$name])) {
            $thumbConfigRes = clone $thumbConfig;
            $thumbConfigRes->selectMedia($name);
            $thumbConfigRes->setHighResolution($highRes);
            $thumbConfigRes->setMedias([]);
            /** @var Image $asset */
            $asset = $this->getAsset();
            $thumb = $asset->getThumbnail($thumbConfigRes);

            return $thumb;
        } else {
            throw new \Exception("Media query '" . $name . "' doesn't exist in thumbnail configuration: " . $thumbConfig->getName());
        }
    }

    /**
     * Get a thumbnail image configuration.
     *
     * @param string|array|Thumbnail\Config $selector Name, array or object describing a thumbnail configuration.
     *
     * @throws NotFoundException
     */
    private function createConfig(array|string|Thumbnail\Config $selector): Thumbnail\Config
    {
        $thumbnailConfig = Thumbnail\Config::getByAutoDetect($selector);

        if (!empty($selector) && $thumbnailConfig === null) {
            throw new NotFoundException('Thumbnail definition "' . (is_string($selector) ? $selector : '') . '" does not exist');
        }

        return $thumbnailConfig;
    }

    /**
     * Get value that can be directly used ina srcset HTML attribute for images.
     *
     * @param string|null $mediaQuery Can be empty string if no media queries are defined.
     *
     * @return string Relative paths to different thunbnail images with 1x and 2x resolution
     */
    private function getSrcset(Image\Thumbnail\Config $thumbConfig, Image $image, array $options, ?string $mediaQuery = null): string
    {
        $srcSetValues = [];
        foreach ([1, 2] as $highRes) {
            $thumbConfigRes = clone $thumbConfig;
            if ($mediaQuery) {
                $thumbConfigRes->selectMedia($mediaQuery);
            }
            $thumbConfigRes->setHighResolution($highRes);
            $thumb = $image->getThumbnail($thumbConfigRes, true);

            $descriptor = $highRes . 'x';
            $srcSetValues[] = $this->addCacheBuster($thumb . ' ' . $descriptor, $options, $image);

            if ($this->useOriginalFile($this->asset->getFilename()) && $this->getConfig()->isSvgTargetFormatPossible()) {
                break;
            }
        }

        return implode(', ', $srcSetValues);
    }
}
