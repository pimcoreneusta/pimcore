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

namespace Pimcore\Bundle\CoreBundle\EventListener;

use Pimcore\Bundle\CoreBundle\EventListener\Traits\ResponseInjectionTrait;
use Pimcore\Tool\Session;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @internal
 */
class MaintenancePageListener
{
    use ResponseInjectionTrait;

    protected ?string $templateCode = null;

    public function __construct(protected KernelInterface $kernel)
    {
    }

    public function setTemplateCode(string $code): void
    {
        $this->templateCode = $code;
    }

    public function getTemplateCode(): ?string
    {
        return $this->templateCode;
    }

    public function loadTemplateFromPath(string $path): void
    {
        $templateFile = PIMCORE_PROJECT_ROOT . $path;
        if (file_exists($templateFile)) {
            $this->setTemplateCode(file_get_contents($templateFile));
        }
    }

    public function loadTemplateFromResource(string $path): void
    {
        $templateFile = $this->kernel->locateResource($path);
        if (file_exists($templateFile)) {
            $this->setTemplateCode(file_get_contents($templateFile));
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $maintenance = false;
        $file = \Pimcore\Tool\Admin::getMaintenanceModeFile();

        if (!is_file($file)) {
            return;
        }

        $conf = include($file);
        if (isset($conf['sessionId'])) {
            $requestSessionId = Session::getSessionId();

            $maintenance = true;
            if ($conf['sessionId'] === $requestSessionId) {
                $maintenance = false;
            }
        } else {
            @unlink($file);
        }

        // do not activate the maintenance for the server itself
        // this is to avoid problems with monitoring agents
        $serverIps = ['127.0.0.1'];

        if ($maintenance && !in_array($request->getClientIp(), $serverIps)) {
            $response = new Response($this->getTemplateCode(), 503);
            $event->setResponse($response);
        }
    }
}
