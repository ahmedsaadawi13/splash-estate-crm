<?php
// FILE: /app/controllers/SavedSearchController.php

/**
 * SplashEstate CRM - Saved Search Controller
 * JSON API for the saved-search dropdown in views/partials/advanced_filters.php.
 *
 *   GET  /savedsearch/index?module=leads   list the user's searches
 *   GET  /savedsearch/load/{id}            one search with its filters
 *   GET  /savedsearch/default?module=leads the user's default search (or null)
 *   POST /savedsearch/create               {name, module, filters{}, is_default}
 *   POST /savedsearch/update/{id}          {name, filters{}}
 *   POST /savedsearch/delete/{id}
 *   POST /savedsearch/setDefault/{id}      {is_default: 1|0}
 *
 * Every search is scoped to the logged-in user AND tenant; writes need CSRF.
 */

class SavedSearchController extends Controller {

    private $searchModel;

    public function __construct() {
        $this->searchModel = $this->model('SavedSearch');
    }

    /**
     * List saved searches
     */
    public function index() {
        $this->authenticate();
        $module = $this->moduleParam(isset($_GET['module']) ? $_GET['module'] : null, true);

        $this->jsonResponse(array(
            'success' => true,
            'searches' => $this->searchModel->getUserSearches($this->getUserId(), $this->getTenantId(), $module)
        ));
    }

    /**
     * Create a saved search
     */
    public function create() {
        $this->authenticate(true);
        $input = $this->input();

        $module = $this->moduleParam(isset($input['module']) ? $input['module'] : null, false);
        $name = $this->nameParam($input);
        $filters = SavedSearch::sanitizeFilters(isset($input['filters']) ? $input['filters'] : array(), $module);
        if (empty($filters)) {
            $this->fail('Apply at least one filter before saving a search', 422);
        }
        if ($this->searchModel->countForUser($this->getUserId(), $this->getTenantId(), $module) >= SavedSearch::MAX_PER_MODULE) {
            $this->fail('You have reached the maximum of ' . SavedSearch::MAX_PER_MODULE . ' saved searches for this module', 422);
        }

        $isDefault = !empty($input['is_default']);
        $id = $this->searchModel->createSearch($this->getTenantId(), $this->getUserId(), $module, $name, $filters, $isDefault);
        if (!$id) {
            $this->fail('Failed to save search', 500);
        }

        $this->jsonResponse(array(
            'success' => true,
            'search_id' => $id,
            'search' => $this->present($this->searchModel->findForUser($id, $this->getUserId(), $this->getTenantId())),
            'message' => 'Search saved'
        ), 201);
    }

    /**
     * Update name / filters
     * @param int $id
     */
    public function update($id = 0) {
        $this->authenticate(true);
        $search = $this->findOrFail($id);
        $input = $this->input();

        $name = $this->nameParam($input);
        $filters = isset($input['filters'])
            ? SavedSearch::sanitizeFilters($input['filters'], $search['module'])
            : $this->searchModel->parseFilters($search['filters']);
        if (empty($filters)) {
            $this->fail('A saved search needs at least one filter', 422);
        }

        $this->searchModel->updateSearch($search['id'], $this->getUserId(), $this->getTenantId(), $name, $filters);
        if (isset($input['is_default'])) {
            if (!empty($input['is_default'])) {
                $this->searchModel->setAsDefault($search['id'], $this->getUserId(), $this->getTenantId(), $search['module']);
            } else {
                $this->searchModel->clearDefault($search['id'], $this->getUserId(), $this->getTenantId());
            }
        }

        $this->jsonResponse(array(
            'success' => true,
            'search' => $this->present($this->searchModel->findForUser($search['id'], $this->getUserId(), $this->getTenantId())),
            'message' => 'Search updated'
        ));
    }

    /**
     * Delete a saved search
     * @param int $id
     */
    public function delete($id = 0) {
        $this->authenticate(true);
        $search = $this->findOrFail($id);

        if (!$this->searchModel->deleteForUser($search['id'], $this->getUserId(), $this->getTenantId())) {
            $this->fail('Failed to delete search', 500);
        }
        $this->jsonResponse(array('success' => true, 'message' => 'Search deleted'));
    }

    /**
     * Set (or with is_default=0, clear) the default search for its module
     * @param int $id
     */
    public function setDefault($id = 0) {
        $this->authenticate(true);
        $search = $this->findOrFail($id);
        $input = $this->input();

        $makeDefault = !isset($input['is_default']) || !empty($input['is_default']);
        $ok = $makeDefault
            ? $this->searchModel->setAsDefault($search['id'], $this->getUserId(), $this->getTenantId(), $search['module'])
            : $this->searchModel->clearDefault($search['id'], $this->getUserId(), $this->getTenantId());
        if (!$ok) {
            $this->fail('Failed to update default search', 500);
        }
        $this->jsonResponse(array(
            'success' => true,
            'is_default' => $makeDefault ? 1 : 0,
            'message' => $makeDefault ? 'Default search set' : 'Default search cleared'
        ));
    }

    /**
     * Load one saved search
     * @param int $id
     */
    public function load($id = 0) {
        $this->authenticate();
        $search = $this->findOrFail($id);
        $this->jsonResponse(array('success' => true, 'search' => $this->present($search)));
    }

    /**
     * The default search of a module (search = null when none)
     */
    public function default() {
        $this->authenticate();
        $module = $this->moduleParam(isset($_GET['module']) ? $_GET['module'] : null, false);
        $search = $this->searchModel->getDefault($this->getUserId(), $this->getTenantId(), $module);
        $this->jsonResponse(array('success' => true, 'search' => $search ? $this->present($search) : null));
    }

    // ------------------------------------------------------------------

    /**
     * Login (JSON 401 instead of a redirect), tenant, and for writes POST + CSRF
     * @param bool $write
     */
    private function authenticate($write = false) {
        if (!$this->isLoggedIn()) {
            $this->fail('Authentication required', 401);
        }
        if (!$this->getTenantId()) {
            $this->fail('Saved searches are only available inside a tenant account', 403);
        }
        if ($write) {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->fail('Method not allowed', 405);
            }
            $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
            if ($token === '' && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
                $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
            }
            if (!$this->verifyCsrfToken($token)) {
                $this->fail('Invalid security token. Please reload the page.', 419);
            }
        }
    }

    /**
     * @param mixed $id
     * @return array Search row
     */
    private function findOrFail($id) {
        $id = (int)$id;
        $search = $id > 0 ? $this->searchModel->findForUser($id, $this->getUserId(), $this->getTenantId()) : false;
        if (!$search) {
            $this->fail('Saved search not found', 404);
        }
        return $search;
    }

    /**
     * @param mixed $module
     * @param bool $optional
     * @return string|null
     */
    private function moduleParam($module, $optional) {
        if ($optional && ($module === null || $module === '')) {
            return null;
        }
        if (!SavedSearch::isValidModule($module)) {
            $this->fail('Invalid module', 422);
        }
        return $module;
    }

    /**
     * @param array $input
     * @return string
     */
    private function nameParam($input) {
        $name = isset($input['name']) && is_string($input['name']) ? trim(preg_replace('/\s+/', ' ', $input['name'])) : '';
        if ($name === '') {
            $this->fail('Please enter a name for the search', 422);
        }
        if (strlen($name) > 100) {
            $this->fail('Name must be 100 characters or fewer', 422);
        }
        return $name;
    }

    /**
     * JSON body or form fields
     * @return array
     */
    private function input() {
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode(file_get_contents('php://input'), true);
            return is_array($decoded) ? $decoded : array();
        }
        return $_POST;
    }

    /**
     * Public representation of a search row
     * @param array $search
     * @return array
     */
    private function present($search) {
        return array(
            'id' => (int)$search['id'],
            'name' => $search['name'],
            'module' => $search['module'],
            'filters' => (object)$this->searchModel->parseFilters($search['filters']),
            'is_default' => (int)$search['is_default']
        );
    }

    /**
     * @param string $message
     * @param int $code
     */
    private function fail($message, $code) {
        $this->jsonResponse(array('success' => false, 'error' => $message, 'message' => $message), $code);
    }
}
