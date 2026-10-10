<?php
// Street-view style walkthrough from the school entrance to the Guidance Office.
// Photos, map and route live in assets/guidance-walk/ (see route.json).
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';

$user = AuthMiddleware::requireRole([ROLE_STUDENT]);

$walkBase = BASE_URL . '/assets/guidance-walk/';
$walk = json_decode(file_get_contents(__DIR__ . '/../assets/guidance-walk/route.json'), true);
$cfg = [
    'map' => $walkBase . $walk['map'],
    'mapW' => $walk['mapW'],
    'mapH' => $walk['mapH'],
    'route' => $walk['route'],
    'stops' => $walk['stops'],
    'captions' => (object)$walk['captions'],
    'imgs' => array_map(fn($i) => ['src' => $walkBase . $i['file'], 'w' => $i['w'], 'h' => $i['h']], $walk['imgs']),
];

$pageTitle = 'Locate the Guidance Office';
include __DIR__ . '/../partials/header.php';
?>
<link rel="stylesheet" href="<?= $walkBase ?>walk.css">
<h3 class="mb-1">Locate the Guidance Office</h3>
<p class="text-muted mb-3">Walk the route from the school entrance to the Guidance Office. Use the arrows to move forward or back, and drag to look around.</p>
<div id="gw"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="<?= $walkBase ?>walk.js"></script>
<script>GuidanceWalk.init(document.getElementById('gw'), <?= json_encode($cfg, JSON_UNESCAPED_SLASHES) ?>);</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
