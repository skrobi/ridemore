<?php
// Variables $seoData, $urlType, $urlSlug are available from index.php
$isSpecificPage = isset($seoData) && $seoData;

// Default meta values
$pageTitle = 'Wyzwania rowerowe | Zweryfikowane przejazdy | RideMore';
$pageDescription = 'Wybierasz trasę. Podejmujesz wyzwanie. RideMore weryfikuje pełne ukończenie przejazdu i przyznaje medale. Wystarczy że jedziesz.';
$pageUrl = 'https://ridemore.bike';
$pageImage = 'https://ridemore.bike/og/just-ride-more.jpg';
$ogTitle = 'Wyzwania rowerowe. Wystarczy że jedziesz.';
$ogDescription = 'RideMore to wyzwania rowerowe z bezwzględną weryfikacją GPS. Wybierasz trasę, jedziesz i sprawdzamy czy dowiozłeś cel. Wystarczy że jedziesz.';
$twitterTitle = 'Nie liczy się „prawie". Liczy się cel.';
$twitterDescription = 'Wyzwania rowerowe z weryfikacją GPS. Medale tylko za realizację celu. Wystarczy że jedziesz.';

// Override with specific page data
if ($isSpecificPage) {
    $pageTitle = htmlspecialchars($seoData['name']) . ' | RideMore';
    $pageDescription = htmlspecialchars($seoData['description'] ?? $seoData['tagline'] ?? $pageDescription);
    $pageUrl = 'https://ridemore.bike/' . $urlType . '/' . $urlSlug;
    
    // Use challenge/route specific image if available
    if (!empty($seoData['image_url'])) {
        $pageImage = htmlspecialchars($seoData['image_url']);
    }
    
    // Specific OG content
    $ogTitle = htmlspecialchars($seoData['name']);
    $ogDescription = $pageDescription;
    $twitterTitle = htmlspecialchars($seoData['name']);
    $twitterDescription = $pageDescription;
}
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#00d4ff">
<meta name="description" content="<?= $pageDescription ?>">
<title><?= $pageTitle ?></title>

<!-- Canonical URL -->
<link rel="canonical" href="<?= $pageUrl ?>">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $ogTitle ?>">
<meta property="og:description" content="<?= $ogDescription ?>">
<meta property="og:url" content="<?= $pageUrl ?>">
<meta property="og:image" content="<?= $pageImage ?>">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $twitterTitle ?>">
<meta name="twitter:description" content="<?= $twitterDescription ?>">
<meta name="twitter:image" content="<?= $pageImage ?>">

<meta name="color-scheme" content="light only">
<meta name="keywords" content="wyzwania rowerowe, challenge rowerowy, medale rowerowe, trasy rowerowe gps, gravel mtb szosa">

<!-- PWA -->
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/x-icon" href="<?= asset('favicon.ico') ?>">

<!-- Styles -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<link rel="stylesheet" href="<?= asset('css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('css/layout.css') ?>">
<link rel="stylesheet" href="<?= asset('css/components.css') ?>">
<link rel="stylesheet" href="<?= asset('css/route-card.css') ?>">
<link rel="stylesheet" href="<?= asset('css/badge.css') ?>">
<link rel="stylesheet" href="<?= asset('css/ranking.css') ?>">
<link rel="stylesheet" href="<?= asset('css/responsive.css') ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">

<!-- Alpine.js -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<!-- App Config -->
<script src="<?= asset('js/config/routeStyles.js') ?>"></script>
<script>
  window.APP_CONFIG = {
    baseUrl: '<?= get_base_url() ?>',
    apiUrl: '<?= rtrim(get_base_url(), '/') ?>/api',
    asset: function(path) {
      return this.baseUrl + '/' + path.replace(/^\//, '');
    },
    api: function(endpoint) {
      return this.apiUrl + '/' + endpoint.replace(/^\//, '');
    }
  };
</script>

<?php if ($seoData): ?>
<script>
window._seoHydrate = {
  type: '<?= $urlType ?>',
  id: <?= $urlSlug === 'route' ? $seoData['route_id'] : $seoData['challenge_id'] ?>,
  slug: '<?= $urlSlug ?>'
};
</script>
<?php endif; ?>


<?php if ($isSpecificPage): ?>
<!-- JSON-LD Structured Data -->
<script type="application/ld+json">
<?= json_encode($seoData['schema_org'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?>
</script>
<?php endif; ?>

<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-8DS0V2V5J6"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-8DS0V2V5J6');
</script>