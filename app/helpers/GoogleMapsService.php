<?php
// FILE: /app/helpers/GoogleMapsService.php

/**
 * SplashEstate CRM - Google Maps Service
 * Google Maps integration for geocoding and map rendering.
 *
 * Without an API key the service is a graceful no-op: isConfigured() is
 * false, geocoding returns false and no script/map markup is produced.
 * The API key is only ever written into a page by getMapScript() (the
 * Maps JavaScript loader, which requires it client-side); server-side
 * geocoding keeps it on the server.
 */

class GoogleMapsService {

    private $apiKey;
    private $geocodeEndpoint = 'https://maps.googleapis.com/maps/api/geocode/json';
    private $jsEndpoint = 'https://maps.googleapis.com/maps/api/js';
    private $timeout = 8;
    private $connectTimeout = 4;

    /**
     * @param string|null $apiKey Google Maps API key (defaults to GOOGLE_MAPS_API_KEY)
     */
    public function __construct($apiKey = null) {
        if ($apiKey === null || $apiKey === '') {
            $apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
        }
        $this->apiKey = trim((string)$apiKey);
    }

    /**
     * Whether an API key is available
     * @return bool
     */
    public function isConfigured() {
        return $this->apiKey !== '';
    }

    /**
     * Geocode an address to get latitude and longitude
     * @param string $address Full address string
     * @return array|false array(latitude, longitude, formatted_address, place_id) or false
     */
    public function geocodeAddress($address) {
        $address = trim((string)$address);
        if (!$this->isConfigured() || $address === '' || trim($address, ' ,') === '') {
            return false;
        }

        // http_build_query() URL-encodes every value
        $data = $this->requestJson($this->geocodeEndpoint . '?' . http_build_query(array(
            'address' => $address,
            'key' => $this->apiKey
        )));
        if ($data === false) {
            return false;
        }

        $result = $data['results'][0];
        if (!isset($result['geometry']['location']['lat'], $result['geometry']['location']['lng'])) {
            return false;
        }
        $lat = $result['geometry']['location']['lat'];
        $lng = $result['geometry']['location']['lng'];
        if (!self::isValidCoordinate($lat, $lng)) {
            return false;
        }

        return array(
            'latitude' => (float)$lat,
            'longitude' => (float)$lng,
            'formatted_address' => isset($result['formatted_address']) ? $result['formatted_address'] : $address,
            'place_id' => isset($result['place_id']) ? $result['place_id'] : null
        );
    }

    /**
     * Reverse geocode coordinates to get address
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @return array|false Address components or false
     */
    public function reverseGeocode($lat, $lng) {
        if (!$this->isConfigured() || !self::isValidCoordinate($lat, $lng)) {
            return false;
        }

        $data = $this->requestJson($this->geocodeEndpoint . '?' . http_build_query(array(
            'latlng' => (float)$lat . ',' . (float)$lng,
            'key' => $this->apiKey
        )));
        if ($data === false) {
            return false;
        }

        $result = $data['results'][0];
        return array(
            'formatted_address' => isset($result['formatted_address']) ? $result['formatted_address'] : '',
            'address_components' => isset($result['address_components']) ? $result['address_components'] : array(),
            'place_id' => isset($result['place_id']) ? $result['place_id'] : null
        );
    }

    /**
     * Script tag for the Google Maps JavaScript API (the only place the key is
     * written into a page; restrict the key by HTTP referrer in Google Cloud).
     * @param string|null $callback Global JS function name to call when loaded
     * @return string HTML ('' when not configured)
     */
    public function getMapScript($callback = null) {
        if (!$this->isConfigured()) {
            return '';
        }
        $query = array('key' => $this->apiKey, 'loading' => 'async');
        if ($callback !== null && preg_match('/^[A-Za-z_$][A-Za-z0-9_$.]*$/', $callback)) {
            $query['callback'] = $callback;
        }
        $url = $this->jsEndpoint . '?' . http_build_query($query);
        return '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" async defer></script>';
    }

    /**
     * Public Google Maps search link for an address (no API key needed)
     * @param string $query Address or "lat,lng"
     * @return string URL
     */
    public static function searchUrl($query) {
        return 'https://www.google.com/maps/search/?' . http_build_query(array('api' => 1, 'query' => (string)$query));
    }

    /**
     * Public Google Maps directions link (no API key needed)
     * @param string $destination Address or "lat,lng"
     * @return string URL
     */
    public static function directionsUrl($destination) {
        return 'https://www.google.com/maps/dir/?' . http_build_query(array('api' => 1, 'destination' => (string)$destination));
    }

    /**
     * Whether a latitude/longitude pair is valid (0,0 is treated as "unset")
     * @param mixed $lat
     * @param mixed $lng
     * @return bool
     */
    public static function isValidCoordinate($lat, $lng) {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }
        $lat = (float)$lat;
        $lng = (float)$lng;
        if ($lat == 0.0 && $lng == 0.0) {
            return false;
        }
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    /**
     * Calculate distance between two coordinates (in kilometers)
     * @return float Distance in kilometers
     */
    public function calculateDistance($lat1, $lng1, $lat2, $lng2) {
        $earthRadius = 6371; // kilometers

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Test API connection
     * @return array Test result
     */
    public function testConnection() {
        if (!$this->isConfigured()) {
            return array('success' => false, 'message' => 'Google Maps API key is not configured');
        }
        $result = $this->geocodeAddress('1600 Amphitheatre Parkway, Mountain View, CA');
        if ($result && isset($result['latitude'])) {
            return array('success' => true, 'message' => 'Google Maps API connection successful');
        }
        return array('success' => false, 'message' => 'Failed to geocode test address');
    }

    /**
     * Parse address components from geocoding result
     * @param array $addressComponents Address components from Google
     * @return array Parsed address fields
     */
    public function parseAddressComponents($addressComponents) {
        $parsed = array(
            'street_number' => '',
            'route' => '',
            'city' => '',
            'state' => '',
            'country' => '',
            'postal_code' => ''
        );

        foreach ((array)$addressComponents as $component) {
            $types = isset($component['types']) ? (array)$component['types'] : array();
            $long = isset($component['long_name']) ? $component['long_name'] : '';
            $short = isset($component['short_name']) ? $component['short_name'] : $long;

            if (in_array('street_number', $types)) {
                $parsed['street_number'] = $long;
            } elseif (in_array('route', $types)) {
                $parsed['route'] = $long;
            } elseif (in_array('locality', $types)) {
                $parsed['city'] = $long;
            } elseif (in_array('administrative_area_level_1', $types)) {
                $parsed['state'] = $short;
            } elseif (in_array('country', $types)) {
                $parsed['country'] = $long;
            } elseif (in_array('postal_code', $types)) {
                $parsed['postal_code'] = $long;
            }
        }

        $parsed['address'] = trim($parsed['street_number'] . ' ' . $parsed['route']);

        return $parsed;
    }

    /**
     * GET a Geocoding API URL and return the decoded body when status is OK
     * with at least one result. Never throws; failures are logged without the key.
     * @param string $url
     * @return array|false
     */
    private function requestJson($url) {
        $body = $this->makeRequest($url);
        if ($body === false) {
            return false;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['status'])) {
            error_log('Google Maps API: invalid JSON response');
            return false;
        }
        if ($data['status'] !== 'OK' || empty($data['results']) || !is_array($data['results'])) {
            // ZERO_RESULTS is a normal outcome; other statuses indicate a config problem
            if ($data['status'] !== 'ZERO_RESULTS') {
                $message = isset($data['error_message']) ? ' - ' . $data['error_message'] : '';
                error_log('Google Maps API status ' . $data['status'] . $message);
            }
            return false;
        }
        return $data;
    }

    /**
     * Make HTTP GET request with short timeouts
     * @param string $url Request URL
     * @return string|false Response body or false
     */
    private function makeRequest($url) {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch); // no-op (and deprecated) on PHP 8+
            }

            if ($errno !== 0 || $response === false) {
                error_log('Google Maps API request failed: ' . $error);
                return false;
            }
        } else {
            $context = stream_context_create(array('http' => array(
                'method' => 'GET',
                'timeout' => $this->timeout,
                'ignore_errors' => true
            )));
            $response = @file_get_contents($url, false, $context);
            $httpCode = 0;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s?/', $http_response_header[0], $m)) {
                $httpCode = (int)$m[1];
            }
            if ($response === false) {
                error_log('Google Maps API request failed (stream)');
                return false;
            }
        }

        if ($httpCode !== 200) {
            error_log('Google Maps API returned HTTP ' . $httpCode);
            return false;
        }

        return $response;
    }
}
