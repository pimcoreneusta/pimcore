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

namespace Pimcore\Bundle\AdminBundle\Controller\Admin\External {
    use Pimcore\Bundle\AdminBundle\Controller\AdminController;
    use Pimcore\Controller\KernelControllerEventInterface;
    use Pimcore\Tool\Session;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\Event\ControllerEvent;
    use Symfony\Component\HttpKernel\Profiler\Profiler;
    use Symfony\Component\Routing\Annotation\Route;

    /**
     * @internal
     */
    class AdminerController extends AdminController implements KernelControllerEventInterface
    {
        protected string $adminerHome = '';

        /**
         * @Route("/external_adminer/adminer", name="pimcore_admin_external_adminer_adminer")
         *
         */
        public function adminerAction(?Profiler $profiler): Response
        {
            if ($profiler) {
                $profiler->disable();
            }

            // disable debug error handler while including adminer
            $errorHandler = set_error_handler(function () {
            });

            chdir($this->adminerHome . 'adminer');
            include($this->adminerHome . 'adminer/index.php');

            set_error_handler($errorHandler);

            // empty fake response, unfortunately Adminer uses flush() very heavily so we're not able to buffer, rewrite
            // and put the into a proper response object :(
            $response = new Response();

            return $this->mergeAdminerHeaders($response);
        }

        /**
         * @Route("/external_adminer/{path}", name="pimcore_admin_external_adminer_proxy", requirements={"path"=".*"})
         * @Route("/adminer/{path}", name="pimcore_admin_external_adminer_proxy_1", requirements={"path"=".*"})
         * @Route("/externals/{path}", name="pimcore_admin_external_adminer_proxy_2", requirements={"path"=".*"}, defaults={"type": "external"})
         *
         * @param Request $request
         *
         * @return Response
         */
        public function proxyAction(Request $request): Response
        {
            $response = new Response();
            $content = '';

            // proxy for resources
            $path = $request->get('path');
            if (preg_match("@\.(css|js|ico|png|jpg|gif)$@", $path)) {
                if ($request->get('type') == 'external') {
                    $path = '../' . $path;
                }

                if (strpos($path, 'static/') === 0) {
                    $path = 'adminer/' . $path;
                }

                $filePath = $this->adminerHome . '/' . $path;

                // it seems that css files need the right content-type (Chrome)
                if (preg_match('@.css$@', $path)) {
                    $response->headers->set('Content-Type', 'text/css');
                } elseif (preg_match('@.js$@', $path)) {
                    $response->headers->set('Content-Type', 'text/javascript');
                }

                if (file_exists($filePath)) {
                    $content = file_get_contents($filePath);

                    if (preg_match('@default.css$@', $path)) {
                        // append custom styles, because in Adminer everything is hardcoded
                        $content .= file_get_contents($this->adminerHome . 'designs/konya/adminer.css');
                        $content .= file_get_contents(PIMCORE_WEB_ROOT . '/bundles/pimcoreadmin/css/adminer-modifications.css');
                    }
                }
            }

            $response->setContent($content);

            return $this->mergeAdminerHeaders($response);
        }

        public function onKernelControllerEvent(ControllerEvent $event)
        {
            if (!$event->isMainRequest()) {
                return;
            }

            // PHP 7.0 compatibility of adminer (throws some warnings)
            ini_set('display_errors', 0);

            // only for admins
            $this->checkPermission('adminer');

            // call this to keep the session 'open' so that Adminer can write to it
            $session = Session::get();

            $this->adminerHome = PIMCORE_COMPOSER_PATH . '/vrana/adminer/';
        }

        /**
         * Merges http-headers set from Adminer via headers function
         * to the Symfony Response Object
         *
         * @param Response $response
         *
         * @return Response
         */
        protected function mergeAdminerHeaders(Response $response): Response
        {
            if (!headers_sent()) {
                $headersRaw = headers_list();

                foreach ($headersRaw as $header) {
                    $header = explode(':', $header, 2);
                    list($headerKey, $headerValue) = $header;

                    if ($headerKey && $headerValue) {
                        $response->headers->set($headerKey, $headerValue);
                    }
                }

                header_remove();
            }

            return $response;
        }
    }
}

namespace {
    use Pimcore\Cache;
    use Pimcore\Tool\Session;

    if (!function_exists('adminer_object')) {
        // adminer plugin
        function adminer_object(): AdminerPimcore
        {
            $pluginDir = PIMCORE_COMPOSER_PATH . '/vrana/adminer/plugins';

            // required to run any plugin
            include_once $pluginDir . '/plugin.php';

            // autoloader
            foreach (glob($pluginDir . '/*.php') as $filename) {
                include_once $filename;
            }

            $plugins = [
                new \AdminerFrames(),
                new \AdminerDumpDate,
                new \AdminerDumpJson,
                new \AdminerDumpBz2,
                new \AdminerDumpZip,
                new \AdminerDumpXml,
                new \AdminerDumpAlter,
            ];

            // support for SSL (at least for PDO)
            $driverOptions = \Pimcore\Db::get()->getParams()['driverOptions'] ?? [];
            $ssl = [
                'key' => $driverOptions[\PDO::MYSQL_ATTR_SSL_KEY] ?? null,
                'cert' => $driverOptions[\PDO::MYSQL_ATTR_SSL_CERT] ?? null,
                'ca' => $driverOptions[\PDO::MYSQL_ATTR_SSL_CA] ?? null,
            ];
            if ($ssl['key'] !== null || $ssl['cert'] !== null || $ssl['ca'] !== null) {
                $plugins[] = new \AdminerLoginSsl($ssl);
            }

            class AdminerPimcore extends \AdminerPlugin
            {
                public function name(): string
                {
                    return '';
                }

                public function loginForm()
                {
                    parent::loginForm();
                    echo '<script' . nonce() . ">document.querySelector('input[name=auth\\\\[db\\\\]]').value='" . $this->database() . "'; document.querySelector('form').submit()</script>";
                }

                /**
                 * @param bool $create
                 *
                 * @return string
                 */
                public function permanentLogin($create = false): string
                {
                    // key used for permanent login
                    return Session::getSessionId();
                }

                /**
                 * @param string $login
                 * @param string $password
                 *
                 * @return bool
                 */
                public function login($login, $password): bool
                {
                    return true;
                }

                public function credentials(): array
                {
                    $params = \Pimcore\Db::get()->getParams();

                    $host = $params['host'] ?? null;
                    if ($port = $params['port'] ?? null) {
                        $host .= ':' . $port;
                    }

                    // server, username and password for connecting to database
                    $result = [
                        $host,
                        $params['user'] ?? null,
                        $params['password'] ?? null,
                    ];

                    return $result;
                }

                public function database(): string
                {
                    $db = \Pimcore\Db::get();
                    // database name, will be escaped by Adminer
                    return $db->getDatabase();
                }

                public function databases($flush = true)
                {
                    $cacheKey = 'pimcore_adminer_databases';

                    if (!$return = Cache::load($cacheKey)) {
                        $db = Pimcore\Db::get();
                        $return = $db->fetchAllAssociative('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA');

                        foreach ($return as &$ret) {
                            $ret = $ret['SCHEMA_NAME'];
                        }

                        Cache::save($return, $cacheKey);
                    }

                    return $return;
                }
            }

            return new AdminerPimcore($plugins);
        }
    }
}
