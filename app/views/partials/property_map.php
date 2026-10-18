<?php
// FILE: /app/views/partials/property_map.php
/**
 * Property location card (embedded in properties/view).
 *
 * Variables:
 * - $property  Property row (address, city, state, zip, latitude, longitude, title)
 * - $mapHeight Optional CSS height of the map (default 320px)
 *
 * With GOOGLE_MAPS_API_KEY and valid coordinates an interactive map is
 * rendered via the Maps JavaScript API (the only place the key reaches the
 * page). Otherwise a placeholder with plain Google Maps links is shown.
 */
require_once APP_PATH . '/helpers/GoogleMapsService.php';

$mapsService = new GoogleMapsService();
$mapHeightCss = (isset($mapHeight) && preg_match('/^\d{2,4}px$/', (string)$mapHeight)) ? $mapHeight : '320px';
$mapLat = isset($property['latitude']) ? $property['latitude'] : null;
$mapLng = isset($property['longitude']) ? $property['longitude'] : null;
$hasCoords = GoogleMapsService::isValidCoordinate($mapLat, $mapLng);
$showMap = $mapsService->isConfigured() && $hasCoords;
$fullAddress = trim(implode(', ', array_filter(array(
    (string)($property['address'] ?? ''),
    (string)($property['city'] ?? ''),
    trim((string)($property['state'] ?? '') . ' ' . (string)($property['zip'] ?? ''))
), 'strlen')));
$mapDomId = 'property-map-' . (int)$property['id'];
$mapCallback = 'initPropertyMap' . (int)$property['id'];
?>
<div class="card">
    <div class="card-header"><h2>Location</h2></div>
    <div class="card-body">
        <?php if ($showMap): ?>
            <div id="<?php echo e($mapDomId); ?>" style="width: 100%; height: <?php echo e($mapHeightCss); ?>; border-radius: 6px; background: #ecf0f1;"></div>
            <script>
            window.<?php echo $mapCallback; ?> = function () {
                var position = { lat: <?php echo json_encode((float)$mapLat); ?>, lng: <?php echo json_encode((float)$mapLng); ?> };
                var map = new google.maps.Map(document.getElementById(<?php echo json_encode($mapDomId); ?>), {
                    zoom: 15, center: position, streetViewControl: true, mapTypeControl: false
                });
                var marker = new google.maps.Marker({ position: position, map: map, title: <?php echo json_encode((string)$property['title'], JSON_HEX_TAG | JSON_HEX_AMP); ?> });
                var content = document.createElement('div');
                var strong = document.createElement('strong');
                strong.textContent = <?php echo json_encode((string)$property['title'], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
                var small = document.createElement('div');
                small.textContent = <?php echo json_encode($fullAddress, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
                content.appendChild(strong);
                content.appendChild(small);
                var info = new google.maps.InfoWindow({ content: content });
                marker.addListener('click', function () { info.open(map, marker); });
            };
            </script>
            <?php echo $mapsService->getMapScript($mapCallback); ?>
        <?php else: ?>
            <div class="empty-state" style="padding: 2rem 1rem; background: #f8f9fa; border: 1px dashed #bdc3c7; border-radius: 6px;">
                <p class="text-muted">
                    <?php if (!$mapsService->isConfigured()): ?>
                        Interactive map is not configured (set GOOGLE_MAPS_API_KEY).
                    <?php else: ?>
                        Coordinates are not available for this address yet.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="mt-2">
            <strong><?php echo e($property['address'] ?? ''); ?></strong><br>
            <span class="text-muted"><?php echo e(trim(($property['city'] ?? '') . ', ' . ($property['state'] ?? '') . ' ' . ($property['zip'] ?? ''))); ?></span>
            <?php if ($hasCoords): ?>
                <br><span class="small text-muted">Coordinates: <?php echo e(number_format((float)$mapLat, 6) . ', ' . number_format((float)$mapLng, 6)); ?></span>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-1 flex-wrap mt-1">
            <?php $mapQuery = $hasCoords ? ((float)$mapLat . ',' . (float)$mapLng) : $fullAddress; ?>
            <a class="btn btn-outline btn-sm" target="_blank" rel="noopener noreferrer" href="<?php echo e(GoogleMapsService::searchUrl($fullAddress)); ?>">Open in Google Maps</a>
            <a class="btn btn-outline btn-sm" target="_blank" rel="noopener noreferrer" href="<?php echo e(GoogleMapsService::directionsUrl($mapQuery)); ?>">Directions</a>
        </div>
    </div>
</div>
