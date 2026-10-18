<?php
// FILE: /app/controllers/PropertiesController.php

/**
 * SplashEstate CRM - Properties Controller
 * Listing CRUD, filters, detail page (gallery, map, deals, matching leads).
 * Detail URL: /properties/view/{id} (routed to show()).
 */

class PropertiesController extends Controller {

    private $propertyModel;
    private $userModel;
    private $clientModel;

    public function __construct() {
        $this->propertyModel = $this->model('Property');
        $this->userModel = $this->model('User');
        $this->clientModel = $this->model('Client');
    }

    /**
     * Property list with filters
     */
    public function index() {
        $this->requirePermission('properties.view');

        $tenantId = $this->getTenantId();
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $perPage = 20;

        $filters = array();
        foreach (array('property_type', 'listing_type', 'status', 'city', 'search', 'min_price', 'max_price', 'bedrooms') as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key]) && trim($_GET[$key]) !== '') {
                $filters[$key] = trim($_GET[$key]);
            }
        }

        $totalProperties = $this->propertyModel->count($tenantId, $filters);
        $totalPages = (int)ceil($totalProperties / $perPage);
        $properties = $this->propertyModel->getPropertiesWithInfo($tenantId, $page, $perPage, $filters);

        $this->view('properties/index', array(
            'title' => 'Properties',
            'properties' => $properties,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalProperties' => $totalProperties,
            'filters' => $filters,
            'cities' => $this->propertyModel->getCities($tenantId)
        ));
    }

    /**
     * Property detail page (URL: properties/view/{id})
     * @param int $id Property ID
     */
    public function show($id) {
        $this->requirePermission('properties.view');

        $tenantId = $this->getTenantId();
        $property = $this->propertyModel->getPropertyWithInfo((int)$id, $tenantId);
        if (!$property) {
            $this->setFlash('error', 'Property not found');
            $this->redirect('properties/index');
        }

        $imageModel = $this->model('PropertyImage');
        $attachmentModel = $this->model('Attachment');
        $dealModel = $this->model('Deal');

        $this->view('properties/view', array(
            'title' => $property['title'],
            'property' => $property,
            'images' => $imageModel->getPropertyImages($property['id'], $tenantId),
            'attachments' => $attachmentModel->getByRelated($tenantId, 'property', $property['id']),
            'deals' => can('deals.view') ? $dealModel->getByProperty($property['id'], $tenantId) : array(),
            'matchingLeads' => can('leads.view') ? $this->propertyModel->getMatchingLeads($property, $tenantId) : array()
        ));
    }

    /**
     * Create form / handler
     */
    public function create() {
        $this->requirePermission('properties.create');

        $tenantId = $this->getTenantId();

        if (!checkQuota($tenantId, 'properties')) {
            $this->setFlash('error', 'Property limit reached or no active subscription. Please upgrade your plan.');
            $this->redirect('properties/index');
        }

        $formData = array('status' => 'available', 'listing_agent_id' => $this->getUserId());
        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('properties/create');

            list($formData, $errors) = $this->collectInput($tenantId);

            if (empty($errors)) {
                $formData['tenant_id'] = $tenantId;
                $this->geocode($formData, null);

                $propertyId = $this->propertyModel->createProperty($formData);
                if ($propertyId) {
                    logActivity($this->getUserId(), $tenantId, 'created', 'property', $propertyId);
                    $saved = $this->propertyModel->getById($propertyId, $tenantId);
                    dispatchEvent($tenantId, 'property.created', $saved ? $saved : array('id' => $propertyId));
                    $this->setFlash('success', 'Property created successfully');
                    $this->redirect('properties/view/' . $propertyId);
                }
                $errors['general'] = 'Failed to create property. Please try again.';
            }
        }

        $this->view('properties/create', array(
            'title' => 'Add Property',
            'agents' => $this->userModel->getUsersByTenant($tenantId),
            'clients' => $this->clientModel->getAllForTenant($tenantId, 'first_name'),
            'errors' => $errors,
            'formData' => $formData
        ));
    }

    /**
     * Edit form / handler
     * @param int $id Property ID
     */
    public function edit($id) {
        $this->requirePermission('properties.edit');

        $tenantId = $this->getTenantId();
        $property = $this->propertyModel->getById((int)$id, $tenantId);
        if (!$property) {
            $this->setFlash('error', 'Property not found');
            $this->redirect('properties/index');
        }

        $formData = $property;
        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requirePostWithCsrf('properties/edit/' . (int)$property['id']);

            list($input, $errors) = $this->collectInput($tenantId);
            $formData = array_merge($property, $input);

            if (empty($errors)) {
                $this->geocode($input, $property);

                if ($this->propertyModel->update($property['id'], $tenantId, $input)) {
                    logActivity($this->getUserId(), $tenantId, 'updated', 'property', $property['id']);
                    $saved = $this->propertyModel->getById($property['id'], $tenantId);
                    if ($saved) {
                        $saved['old_status'] = $property['status'];
                    }
                    dispatchEvent($tenantId, 'property.updated', $saved ? $saved : array('id' => (int)$property['id']));
                    $this->setFlash('success', 'Property updated successfully');
                    $this->redirect('properties/view/' . (int)$property['id']);
                }
                $errors['general'] = 'Failed to update property. Please try again.';
            }
        }

        $this->view('properties/edit', array(
            'title' => 'Edit Property',
            'property' => $property,
            'formData' => $formData,
            'agents' => $this->userModel->getUsersByTenant($tenantId),
            'clients' => $this->clientModel->getAllForTenant($tenantId, 'first_name'),
            'errors' => $errors
        ));
    }

    /**
     * Delete (POST + CSRF only)
     * @param int $id Property ID
     */
    public function delete($id) {
        $this->requirePermission('properties.delete');
        $this->requirePostWithCsrf('properties/index');

        $tenantId = $this->getTenantId();
        $property = $this->propertyModel->getById((int)$id, $tenantId);
        if (!$property) {
            $this->setFlash('error', 'Property not found');
            $this->redirect('properties/index');
        }

        $imageModel = $this->model('PropertyImage');
        $imageModel->deleteAllForProperty($property['id'], $tenantId);

        if ($this->propertyModel->delete($property['id'], $tenantId)) {
            logActivity($this->getUserId(), $tenantId, 'deleted', 'property', $property['id']);
            dispatchEvent($tenantId, 'property.deleted', $property);
            $this->setFlash('success', 'Property deleted successfully');
        } else {
            $this->setFlash('error', 'Failed to delete property');
        }

        $this->redirect('properties/index');
    }

    /**
     * Read and validate the property form.
     * Values are stored raw (trimmed) and escaped on output.
     * @param int $tenantId Tenant ID
     * @return array array($data, $errors)
     */
    private function collectInput($tenantId) {
        $str = function ($key, $max = 255) {
            $value = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
            return mb_substr($value, 0, $max);
        };

        $data = array(
            'title' => $str('title'),
            'description' => $str('description', 65000),
            'property_type' => $str('property_type', 20),
            'sub_type' => $str('sub_type', 100),
            'listing_type' => $str('listing_type', 20),
            'price' => $str('price', 20),
            'bedrooms' => $str('bedrooms', 5),
            'bathrooms' => $str('bathrooms', 5),
            'square_feet' => $str('square_feet', 10),
            'lot_size' => $str('lot_size', 12),
            'year_built' => $str('year_built', 4),
            'address' => $str('address'),
            'city' => $str('city', 100),
            'state' => $str('state', 100),
            'zip' => $str('zip', 20),
            'status' => $str('status', 20),
            'owner_id' => $str('owner_id', 11),
            'listing_agent_id' => $str('listing_agent_id', 11)
        );

        $errors = validateRequired($data, array('title', 'property_type', 'listing_type', 'price', 'address', 'city', 'state', 'zip'));

        if ($data['property_type'] !== '' && !in_array($data['property_type'], Property::TYPES, true)) {
            $errors['property_type'] = 'Invalid property type';
        }
        if ($data['listing_type'] !== '' && !in_array($data['listing_type'], Property::LISTING_TYPES, true)) {
            $errors['listing_type'] = 'Invalid listing type';
        }
        if ($data['status'] === '') {
            $data['status'] = 'available';
        } elseif (!in_array($data['status'], Property::STATUSES, true)) {
            $errors['status'] = 'Invalid status';
        }

        $data['price'] = str_replace(array(',', '$', ' '), '', $data['price']);
        if ($data['price'] !== '' && (!is_numeric($data['price']) || (float)$data['price'] < 0 || (float)$data['price'] > 9999999999999)) {
            $errors['price'] = 'Price must be a positive number';
        }

        $numeric = array(
            'bedrooms' => array('int', 0, 1000),
            'bathrooms' => array('float', 0, 99),
            'square_feet' => array('int', 0, 100000000),
            'lot_size' => array('float', 0, 99999999),
            'year_built' => array('int', 1600, (int)date('Y') + 5)
        );
        foreach ($numeric as $field => $rule) {
            $value = str_replace(',', '', $data[$field]);
            if ($value === '') {
                $data[$field] = null;
                continue;
            }
            $valid = $rule[0] === 'int' ? preg_match('/^\d+$/', $value) === 1 : is_numeric($value);
            if (!$valid || (float)$value < $rule[1] || (float)$value > $rule[2]) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is invalid';
                continue;
            }
            $data[$field] = $rule[0] === 'int' ? (int)$value : (float)$value;
        }

        foreach (array('description', 'sub_type') as $field) {
            if ($data[$field] === '') {
                $data[$field] = null;
            }
        }

        // Owner must be a client of this tenant
        if ($data['owner_id'] === '') {
            $data['owner_id'] = null;
        } elseif (!ctype_digit($data['owner_id']) || !$this->clientModel->getById((int)$data['owner_id'], $tenantId)) {
            $errors['owner_id'] = 'Selected owner was not found';
            $data['owner_id'] = null;
        } else {
            $data['owner_id'] = (int)$data['owner_id'];
        }

        // Listing agent must be a user of this tenant
        if ($data['listing_agent_id'] === '') {
            $data['listing_agent_id'] = null;
        } elseif (!ctype_digit($data['listing_agent_id']) || !$this->isTenantUser((int)$data['listing_agent_id'], $tenantId)) {
            $errors['listing_agent_id'] = 'Selected agent was not found';
            $data['listing_agent_id'] = null;
        } else {
            $data['listing_agent_id'] = (int)$data['listing_agent_id'];
        }

        return array($data, $errors);
    }

    /**
     * Whether a user id belongs to the tenant
     */
    private function isTenantUser($userId, $tenantId) {
        foreach ($this->userModel->getUsersByTenant($tenantId) as $user) {
            if ((int)$user['id'] === (int)$userId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Geocode the address into latitude/longitude. Only calls the API when the
     * address changed or coordinates are missing. Never fails the save.
     * @param array &$data Property data being saved
     * @param array|null $existing Current row (edit) or null (create)
     */
    private function geocode(&$data, $existing) {
        if ($existing !== null) {
            $changed = false;
            foreach (array('address', 'city', 'state', 'zip') as $field) {
                if ((string)$existing[$field] !== (string)$data[$field]) {
                    $changed = true;
                }
            }
            if (!$changed && !empty($existing['latitude']) && !empty($existing['longitude'])) {
                return;
            }
            if ($changed) {
                // Old coordinates no longer describe the new address
                $data['latitude'] = null;
                $data['longitude'] = null;
            }
        }

        try {
            require_once APP_PATH . '/helpers/GoogleMapsService.php';
            $maps = new GoogleMapsService();
            if (!$maps->isConfigured()) {
                return;
            }
            $result = $maps->geocodeAddress($data['address'] . ', ' . $data['city'] . ', ' . $data['state'] . ' ' . $data['zip']);
            if ($result) {
                $data['latitude'] = $result['latitude'];
                $data['longitude'] = $result['longitude'];
            }
        } catch (Throwable $e) {
            error_log('Geocoding failed: ' . $e->getMessage());
        }
    }
}
