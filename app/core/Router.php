<?php
// FILE: /app/core/Router.php

/**
 * SplashEstate CRM - Router Class
 * Handles URL routing and dispatches to appropriate controllers.
 *
 * URL format: /{controller}/{method}/{param1}/{param2}...
 *   - controller segments may be camelCase or kebab-case
 *     (e.g. "propertyGallery" or "property-gallery" -> PropertyGalleryController)
 *   - only public, non-magic methods declared on the controller are routable
 *   - an unknown controller or method returns 404
 *   - /{controller}/view/{id} is dispatched to the controller's show($id) action,
 *     because view() is the base Controller's template renderer
 */

class Router {
    private $controller = 'DashboardController';
    private $method = 'index';
    private $params = array();

    /**
     * Constructor - Parse URL and route to controller
     */
    public function __construct() {
        $url = $this->parseUrl();

        // Check for API routes first
        if (isset($url[0]) && $url[0] === 'api') {
            $this->routeApi($url);
            return;
        }

        if (isset($url[0]) && $url[0] !== '') {
            $name = self::resolveControllerName($url[0], APP_PATH . '/controllers', 'Controller');
            if ($name === null) {
                $this->notFound();
                return;
            }
            $this->controller = $name . 'Controller';
            unset($url[0]);
        }

        // Require and instantiate the controller
        require_once APP_PATH . '/controllers/' . $this->controller . '.php';
        $controller = new $this->controller;

        // Resolve method
        if (isset($url[1]) && $url[1] !== '') {
            $method = self::toMethodName($url[1]);
            // "view" is reserved for Controller::view() (template rendering),
            // so record detail pages are implemented as show() actions.
            if ($method === 'view') {
                $method = 'show';
            }
            if ($method === null || !self::isRoutable($controller, $method)) {
                $this->notFound();
                return;
            }
            $this->method = $method;
            unset($url[1]);
        }

        // Get params
        $this->params = $url ? array_values($url) : array();

        // Guard against missing required arguments (avoids ArgumentCountError)
        $reflection = new ReflectionMethod($controller, $this->method);
        if (count($this->params) < $reflection->getNumberOfRequiredParameters()) {
            $this->notFound();
            return;
        }

        call_user_func_array(array($controller, $this->method), $this->params);
    }

    /**
     * Route API requests: /api/{resource}/{method}/{params...}
     */
    private function routeApi($url) {
        header('Content-Type: application/json');

        $name = isset($url[1]) ? self::resolveControllerName($url[1], APP_PATH . '/controllers/api', 'ApiController') : null;
        $file = $name !== null ? APP_PATH . '/controllers/api/' . $name . 'ApiController.php' : null;

        if ($file === null) {
            http_response_code(404);
            echo json_encode(array('error' => 'API resource not found'));
            exit;
        }

        require_once $file;
        $controllerName = $name . 'ApiController';
        $controller = new $controllerName;

        $method = isset($url[2]) ? self::toMethodName($url[2]) : 'index';

        if ($method === null || !self::isRoutable($controller, $method)) {
            http_response_code(404);
            echo json_encode(array('error' => 'Endpoint not found'));
            exit;
        }

        $params = array_slice($url, 3);
        $reflection = new ReflectionMethod($controller, $method);
        if (count($params) < $reflection->getNumberOfRequiredParameters()) {
            http_response_code(400);
            echo json_encode(array('error' => 'Missing parameters'));
            exit;
        }

        call_user_func_array(array($controller, $method), $params);
        exit;
    }

    /**
     * Whether a controller method may be called from a URL
     */
    private static function isRoutable($controller, $method) {
        if (!method_exists($controller, $method) || strpos($method, '__') === 0) {
            return false;
        }
        $reflection = new ReflectionMethod($controller, $method);
        if (!$reflection->isPublic() || $reflection->isStatic()) {
            return false;
        }
        // Do not expose methods inherited from the base Controller class
        $declaring = $reflection->getDeclaringClass()->getName();
        return $declaring !== 'Controller';
    }

    /**
     * Convert a URL segment to a controller base name ("property-gallery" -> "PropertyGallery")
     */
    private static function toControllerName($segment) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $segment)) {
            return null;
        }
        $parts = preg_split('/[-_]/', $segment);
        $name = '';
        foreach ($parts as $part) {
            $name .= ucfirst($part);
        }
        return $name;
    }

    /**
     * Find the controller for a URL segment, case-insensitively, so that
     * "savedsearch", "savedSearch" and "saved-search" all resolve to
     * SavedSearchController.php on case-sensitive filesystems.
     * @return string|null Controller base name (without suffix)
     */
    private static function resolveControllerName($segment, $dir, $suffix) {
        $name = self::toControllerName($segment);
        if ($name === null) {
            return null;
        }
        if (file_exists($dir . '/' . $name . $suffix . '.php')) {
            return $name;
        }
        $wanted = strtolower($name . $suffix . '.php');
        foreach ((array)glob($dir . '/*' . $suffix . '.php') as $file) {
            if (strtolower(basename($file)) === $wanted) {
                return substr(basename($file), 0, -strlen($suffix . '.php'));
            }
        }
        return null;
    }

    /**
     * Convert a URL segment to a method name ("toggle-status" -> "toggleStatus")
     */
    private static function toMethodName($segment) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $segment)) {
            return null;
        }
        if (strpos($segment, '-') === false) {
            return $segment;
        }
        $parts = explode('-', $segment);
        $name = array_shift($parts);
        foreach ($parts as $part) {
            $name .= ucfirst($part);
        }
        return $name;
    }

    /**
     * Send a 404 page
     */
    private function notFound() {
        http_response_code(404);
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
        if (strpos($accept, 'application/json') !== false) {
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'Not found'));
        } else {
            echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body>'
               . '<h1>404 - Page not found</h1><p><a href="' . BASE_URL . '/dashboard/index">Back to dashboard</a></p>'
               . '</body></html>';
        }
        exit;
    }

    /**
     * Parse URL from request
     * @return array URL components
     */
    private function parseUrl() {
        if (isset($_GET['url'])) {
            return explode('/', filter_var(trim($_GET['url'], '/'), FILTER_SANITIZE_URL));
        }
        return array();
    }
}
