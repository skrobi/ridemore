<?php
require_once 'includes/functions.php';

$challengeId = isset($_GET['challenge_id']) ? (int) $_GET['challenge_id'] : null;
$editRouteId = isset($_GET['route_id']) ? (int) $_GET['route_id'] : null;
?>
<!DOCTYPE html>
<html lang="pl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>Planner tras - RideMore.Bike</title>

        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

        <!-- ✅ Leaflet MarkerCluster -->
        <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
        <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
        <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>

        <link rel="stylesheet" href="<?= asset('css/base.css') ?>">
        <link rel="stylesheet" href="<?= asset('css/planner-layout.css') ?>">
    </head>

    <body>
        <div class="planner-container" x-data="plannerApp" x-cloak>

            <!-- SIDEBAR -->
            <div class="planner-sidebar">
                <?php include 'templates/planner/sidebar.php'; ?>
            </div>

            <!-- MAP -->
            <div class="planner-map">
                <div id="planner-map"></div>
                <div class="map-toolbar"></div>

                <div class="routing-indicator" x-show="isRouting">
                    <i class="fas fa-spinner"></i>
                    <span>Wyznaczam trasę...</span>
                </div>
            </div>

        </div>

        <script>
            window.PLANNER_CONFIG = {
                baseUrl: '<?= get_base_url() ?>',
                apiUrl: '<?= get_base_url() ?>/api',
                challengeId: <?= $challengeId ? $challengeId : 'null' ?>,
                editRouteId: <?= $editRouteId ? $editRouteId : 'null' ?>,
                osrmProxy: '<?= get_base_url() ?>/api/planner/osrm-proxy.php',
                challengeRoutesApi: '<?= get_base_url() ?>/api/planner/challenge-routes.php',
                saveApi: '<?= get_base_url() ?>/api/planner/save.php',
                snapThreshold: 500,
                defaultCenter: [52.0, 19.0],
                defaultZoom: 7
            };

            window.APP_CONFIG = {
                baseUrl: '<?= get_base_url() ?>',
                api: (endpoint) => '<?= get_base_url() ?>/api/' + endpoint
            };
        </script>

        <!-- Planner JS - KOLEJNOŚĆ WAŻNA! -->
        <script defer src="<?= asset('js/planner/PlannerState.js') ?>"></script>
        <script defer src="<?= asset('js/planner/PlannerMap.js') ?>"></script>
        <script defer src="<?= asset('js/planner/PlannerSnap.js') ?>"></script>
        <script defer src="<?= asset('js/planner/PlannerToolbar.js') ?>"></script>
        <script defer src="<?= asset('js/planner/PlannerRouteRenderer.js') ?>"></script>
        <script defer src="<?= asset('js/planner/PlannerRouter.js') ?>"></script>
        <script defer src="<?= asset('js/planner/PlannerWaypoints.js') ?>"></script>
        <script defer src="<?= asset('js/planner/PlannerHistory.js') ?>"></script>
        <script defer src="<?= asset('js/planner/PlannerChallengeLayer.js') ?>"></script>
        <script defer src="<?= asset('js/planner/PlannerPOI.js') ?>"></script>
        <script defer src="<?= asset('js/config/routeStyles.js') ?>"></script>
        
        <script defer src="<?= asset('js/Toast.js') ?>"></script>
        <script defer src="<?= asset('js/planner/PlannerGeo.js') ?>"></script>

        <script defer src="<?= asset('js/map/BaseLayer.js') ?>"></script>
        <script defer src="<?= asset('js/map/POILayer.js') ?>"></script>
        <script defer src="<?= asset('js/map/POIManager.js') ?>"></script>
        <script defer src="<?= asset('js/planner/PlannerPOIManager.js') ?>"></script>
        <script defer src="<?= asset('js/map/LayerControlsUI.js') ?>"></script>
        <script defer src="<?= asset('js/map/CacheManager.js') ?>"></script>

        
        

        <!-- KOMPONENTY PRZED PlannerApp -->
        <script defer src="<?= asset('js/planner/WaypointList.js') ?>"></script>

        <!-- PlannerApp NA KOŃCU (przed Alpine) -->
        <script defer src="<?= asset('js/planner/PlannerApp.js') ?>"></script>

        <!-- Alpine ostatni -->
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    </body>
</html>