<?php
// ============================================================
// mapping.php  — Admin: Geographic mapping + purok management
// ============================================================
session_start();
require_once 'includes/db.php';
requireAdminLogin();

// ── AJAX handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_purok') {
        $name   = trim($_POST['name']        ?? '');
        $color  = trim($_POST['color']       ?? '#34d399');
        $coords = trim($_POST['coordinates'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        if (!$name) jsonError('Purok name is required.');
        dbInsert(
            "INSERT INTO puroks (name, description, color, coordinates) VALUES (?,?,?,?)",
            [$name, $desc, $color, $coords]
        );
        logActivity("Saved purok: $name", 'Mapping');
        jsonOk([], "Purok '$name' saved.");
    }
    if ($_POST['action'] === 'delete_purok') {
        $id = (int)($_POST['id'] ?? 0);
        dbExecute("DELETE FROM puroks WHERE id=?", [$id]);
        logActivity("Deleted purok #$id", 'Mapping');
        jsonOk([], 'Purok deleted.');
    }
    exit;
}

$puroks = dbFetchAll("SELECT * FROM puroks ORDER BY created_at DESC");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Geographic Mapping — Barangay San Isidro</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
  <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css"/>
  <style>
    #map { height:560px;border-radius:10px;margin-top:16px;box-shadow:0 1px 3px rgba(0,0,0,.1) }
    .purok-item { display:flex;justify-content:space-between;align-items:center;padding:12px;margin:8px 0;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb }
    .purok-name { font-weight:600;color:#1f2937 }
    .purok-color { width:22px;height:22px;border-radius:4px;border:2px solid #e5e7eb;flex-shrink:0 }
  </style>
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_admin.php'; ?>
  <main class="main">
    <div class="topbar">
      <div>
        <h3>Geographic Mapping</h3>
        <div class="text-muted">Visualize resident locations, zones, and community points.</div>
      </div>
    </div>

    <div class="card">
      <h3>Interactive Map &amp; Purok Management</h3>
      <p class="text-muted">Draw zones on the map, name them, and save to the database.</p>
      <div class="form-grid" style="margin-top:16px">
        <div class="form-group">
          <label>Search Resident</label>
          <input type="text" id="residentSearch" placeholder="Search by name or address">
        </div>
        <div class="form-group">
          <label>Purok / Zone Filter</label>
          <select id="zoneFilter" onchange="filterByPurok(this.value)">
            <option value="">All Puroks</option>
            <?php foreach ($puroks as $p): ?>
              <option value="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div id="map"></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px">
      <!-- Drawing tools -->
      <div class="card">
        <h3>Drawing Tools</h3>
        <p class="text-muted">Draw a purok zone on the map, then fill in the details and save.</p>
        <div style="display:grid;gap:12px;margin-top:16px">
          <div class="form-group">
            <label>Purok Name *</label>
            <input type="text" id="purokName" class="form-control" placeholder="e.g., Purok Mabuhay">
          </div>
          <div class="form-group">
            <label>Description</label>
            <input type="text" id="purokDesc" class="form-control" placeholder="Optional description">
          </div>
          <div class="form-group">
            <label>Color</label>
            <input type="color" id="purokColor" value="#34d399">
          </div>
        </div>
        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn btn-primary" onclick="savePurok()">💾 Save Purok</button>
          <button class="btn" onclick="clearDrawing()" style="margin-left:0">🗑️ Clear Drawing</button>
        </div>
        <div style="margin-top:12px;padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;font-size:13px;color:#666">
          <strong>How to use:</strong>
          <ul style="margin:8px 0;padding-left:20px">
            <li>Use the polygon or rectangle tool on the map toolbar</li>
            <li>Click to add points; double-click to finish</li>
            <li>Fill in the purok name above and click Save</li>
          </ul>
        </div>
      </div>

      <!-- Saved puroks list -->
      <div class="card">
        <h3>Saved Puroks</h3>
        <p class="text-muted">Puroks drawn and saved to the database.</p>
        <div id="puroksList" style="margin-top:16px;max-height:380px;overflow-y:auto">
          <?php if ($puroks): foreach ($puroks as $p): ?>
          <div class="purok-item" data-id="<?= $p['id'] ?>" data-coords='<?= htmlspecialchars($p['coordinates'] ?? '[]') ?>'>
            <div>
              <div class="purok-name"><?= htmlspecialchars($p['name']) ?></div>
              <small class="text-muted"><?= htmlspecialchars($p['description'] ?? 'No description') ?> &bull; <?= (int)$p['resident_count'] ?> residents</small>
            </div>
            <div class="purok-color" style="background:<?= htmlspecialchars($p['color']) ?>"></div>
            <div style="display:flex;gap:6px">
              <button class="btn btn-sm" style="padding:4px 8px;font-size:12px" onclick="focusPurok(<?= $p['id'] ?>)">Focus</button>
              <button class="btn btn-sm" style="padding:4px 8px;font-size:12px;background:#fee2e2;color:#991b1b;border-color:#fca5a5" onclick="deletePurok(<?= $p['id'] ?>, this)">Delete</button>
            </div>
          </div>
          <?php endforeach; else: ?>
          <p class="text-muted" style="padding:12px 0">No puroks saved yet. Draw on the map and save.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</div>

<!-- Purok data for JS -->
<script>
const savedPuroks = <?= json_encode($puroks) ?>;
</script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
<script src="assets/js/app.js"></script>
<script>
let mapInstance, drawnItems, lastDrawnLayer = null;

document.addEventListener('DOMContentLoaded', function() {
  mapInstance = L.map('map').setView([14.1153, 121.1476], 15);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap', maxZoom: 19
  }).addTo(mapInstance);

  drawnItems = new L.FeatureGroup().addTo(mapInstance);

  const drawControl = new L.Control.Draw({
    edit: { featureGroup: drawnItems },
    draw: { polygon:true, rectangle:true, polyline:false, circle:false, marker:true }
  });
  mapInstance.addControl(drawControl);

  // Load saved puroks onto the map
  savedPuroks.forEach(p => {
    if (!p.coordinates) return;
    try {
      const coords = JSON.parse(p.coordinates);
      if (!coords || !coords.length) return;
      const layer = L.polygon(coords, {
        color: p.color, fillColor: p.color, fillOpacity: 0.2, weight: 2
      });
      layer.bindPopup(`<b>${p.name}</b><br>${p.description || ''}`);
      layer.addTo(mapInstance);
      layer._purokId = p.id;
    } catch(e) {}
  });

  // Load resident markers
  fetch('api/residents_map.php')
    .then(r => r.json())
    .then(data => {
      if (!data.residents) return;
      data.residents.forEach(r => {
        if (!r.latitude || !r.longitude) return;
        L.marker([r.latitude, r.longitude])
          .bindPopup(`<b>${r.full_name}</b><br>${r.zone_purok || ''}`)
          .addTo(mapInstance);
      });
    }).catch(() => {});

  mapInstance.on('draw:created', function(e) {
    if (lastDrawnLayer) drawnItems.removeLayer(lastDrawnLayer);
    lastDrawnLayer = e.layer;
    drawnItems.addLayer(lastDrawnLayer);
    showToast('Zone drawn! Fill in the name and click Save.', 'info');
  });
});

async function savePurok() {
  const name  = document.getElementById('purokName').value.trim();
  const desc  = document.getElementById('purokDesc').value.trim();
  const color = document.getElementById('purokColor').value;

  if (!name) { showToast('Please enter a purok name.', 'error'); return; }

  let coords = '[]';
  if (lastDrawnLayer && lastDrawnLayer.getLatLngs) {
    const lls = lastDrawnLayer.getLatLngs();
    const flat = Array.isArray(lls[0]) ? lls[0] : lls;
    coords = JSON.stringify(flat.map(ll => [ll.lat, ll.lng]));
  }

  const fd = new FormData();
  fd.append('action',      'save_purok');
  fd.append('name',        name);
  fd.append('description', desc);
  fd.append('color',       color);
  fd.append('coordinates', coords);

  const res  = await fetch('mapping.php', { method:'POST', body:fd });
  const data = await res.json();
  if (data.success) {
    showToast(data.message, 'success');
    setTimeout(() => location.reload(), 800);
  } else {
    showToast(data.message, 'error');
  }
}

function clearDrawing() {
  if (lastDrawnLayer) { drawnItems.removeLayer(lastDrawnLayer); lastDrawnLayer = null; }
  document.getElementById('purokName').value = '';
  document.getElementById('purokDesc').value = '';
}

async function deletePurok(id, btn) {
  if (!confirm('Delete this purok?')) return;
  const fd = new FormData();
  fd.append('action','delete_purok'); fd.append('id',id);
  const res  = await fetch('mapping.php',{method:'POST',body:fd});
  const data = await res.json();
  if (data.success) { btn.closest('.purok-item').remove(); showToast('Purok deleted.','info'); }
}

function focusPurok(id) {
  const item   = document.querySelector(`.purok-item[data-id="${id}"]`);
  const coords = JSON.parse(item?.dataset.coords || '[]');
  if (coords.length && mapInstance) {
    const poly = L.polygon(coords);
    mapInstance.fitBounds(poly.getBounds().pad(0.2));
  }
}

function filterByPurok(name) {
  // placeholder — full implementation would show/hide layers by purok name
  showToast(name ? `Filtering by ${name}` : 'Showing all puroks', 'info');
}
</script>
</body>
</html>
