<?php
// ============================================================
// settings.php  — Admin: Barangay settings management
// All changes apply immediately across the entire system.
// ============================================================
session_start();
require_once 'includes/db.php';
require_once 'includes/site_settings.php';
requireAdminLogin();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Update barangay info + branding ───────────────────────
    if ($action === 'update_info') {
        $fields = [
            'barangay_name'  => trim($_POST['barangay_name']  ?? ''),
            'address'        => trim($_POST['address']        ?? ''),
            'contact_number' => trim($_POST['contact_number'] ?? ''),
            'email'          => trim($_POST['email']          ?? ''),
            'theme_color'    => trim($_POST['theme_color']    ?? '#0066cc'),
        ];

        // Validate email
        if ($fields['email'] && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!$fields['barangay_name']) {
            $error = 'Barangay name is required.';
        } else {
            foreach ($fields as $key => $val) {
                dbExecute(
                    "INSERT INTO barangay_settings (setting_key, setting_value)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = ?",
                    [$key, $val, $val]
                );
            }

            // Handle logo upload
            if (!empty($_FILES['logo']['tmp_name'])) {
                $ext     = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $maxSize = 2 * 1024 * 1024; // 2 MB

                if (!in_array($ext, $allowed)) {
                    $error = 'Logo must be JPG, PNG, WebP, or GIF.';
                } elseif ($_FILES['logo']['size'] > $maxSize) {
                    $error = 'Logo file must be under 2 MB.';
                } else {
                    $logoDir  = 'assets/images/';
                    $logoPath = $logoDir . 'logo_custom.' . $ext;

                    // Delete old custom logo if different extension
                    foreach (['jpg','jpeg','png','gif','webp'] as $e) {
                        $old = $logoDir . 'logo_custom.' . $e;
                        if ($e !== $ext && file_exists($old)) @unlink($old);
                    }

                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $logoPath)) {
                        dbExecute(
                            "INSERT INTO barangay_settings (setting_key, setting_value)
                             VALUES ('logo_path', ?)
                             ON DUPLICATE KEY UPDATE setting_value = ?",
                            [$logoPath, $logoPath]
                        );
                    } else {
                        $error = 'Failed to upload logo. Check folder permissions on assets/images/.';
                    }
                }
            }

            if (!$error) {
                logActivity('Updated barangay settings', 'Settings');
                $success = 'Settings saved! Changes are now live across the entire system.';
            }
        }
    }

    // ── Change admin password ──────────────────────────────────
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $admin = dbFetchOne("SELECT * FROM admin_users WHERE id=?", [$_SESSION['admin_id']]);

        if (!password_verify($current, $admin['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            dbExecute(
                "UPDATE admin_users SET password_hash=? WHERE id=?",
                [password_hash($new, PASSWORD_BCRYPT), $_SESSION['admin_id']]
            );
            logActivity('Changed admin password', 'Settings');
            $success = 'Password updated successfully.';
        }
    }
}

// Always reload fresh settings after any save
$settings = getSiteSettings();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Settings — <?= getSiteName() ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_admin.php'; ?>
  <main class="main">
    <div class="topbar">
      <div>
        <h3>Settings</h3>
        <div class="text-muted">Changes apply immediately across the entire system — sidebars, headers, colors, and logo.</div>
      </div>
    </div>

    <?php if ($success): ?>
      <div style="background:#dcfce7;color:#166534;padding:14px 18px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;gap:10px">
        <span style="font-size:18px">✅</span> <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div style="background:#fee2e2;color:#991b1b;padding:14px 18px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;gap:10px">
        <span style="font-size:18px">❌</span> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <!-- ── Barangay Information ─────────────────────────────── -->
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update_info">

      <div class="card">
        <h3>🏛️ Barangay Information</h3>
        <p class="text-muted" style="margin-bottom:18px">This name and contact info appear throughout the system.</p>
        <div class="form-grid">
          <div class="form-group">
            <label>Barangay Name *</label>
            <input type="text" name="barangay_name" class="form-control"
              value="<?= htmlspecialchars($settings['barangay_name'] ?? 'Barangay San Isidro') ?>"
              required placeholder="e.g., Barangay San Isidro">
          </div>
          <div class="form-group">
            <label>Address</label>
            <input type="text" name="address" class="form-control"
              value="<?= htmlspecialchars($settings['address'] ?? '') ?>"
              placeholder="e.g., 123 Sampaguita St., Zone 2">
          </div>
          <div class="form-group">
            <label>Contact Number</label>
            <input type="text" name="contact_number" class="form-control"
              value="<?= htmlspecialchars($settings['contact_number'] ?? '') ?>"
              placeholder="e.g., (02) 555-1234">
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" class="form-control"
              value="<?= htmlspecialchars($settings['email'] ?? '') ?>"
              placeholder="e.g., info@barangay.gov.ph">
          </div>
        </div>
      </div>

      <!-- ── Branding ────────────────────────────────────────── -->
      <div class="card" style="margin-top:20px">
        <h3>🎨 Branding &amp; Theme</h3>
        <p class="text-muted" style="margin-bottom:18px">
          The logo appears on all sidebars and login pages. The theme color changes buttons, active links, and accents.
        </p>
        <div class="form-grid">

          <!-- Logo -->
          <div class="form-group">
            <label>System Logo</label>
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:12px">
              <img id="logoPreview"
                src="<?= htmlspecialchars($settings['logo_path'] ?? 'assets/images/logo.jpg') ?>"
                alt="Current Logo"
                style="height:64px;width:64px;border-radius:10px;object-fit:cover;border:2px solid var(--border)">
              <div>
                <div style="font-size:13px;font-weight:600">Current Logo</div>
                <div style="font-size:12px;color:var(--text-light)">JPG, PNG, WebP · Max 2 MB</div>
              </div>
            </div>
            <input type="file" name="logo" accept="image/*" class="form-control"
              onchange="previewLogo(this)">
            <small class="text-muted">Upload a new logo to replace the current one.</small>
          </div>

          <!-- Theme color -->
          <div class="form-group">
            <label>Theme Color</label>
            <div style="display:flex;align-items:center;gap:14px;margin-top:6px">
              <input type="color" name="theme_color" id="themeColorPicker"
                value="<?= htmlspecialchars($settings['theme_color'] ?? '#0066cc') ?>"
                style="width:60px;height:60px;border:none;background:none;cursor:pointer;border-radius:10px"
                oninput="previewTheme(this.value)">
              <div>
                <div style="font-size:13px;font-weight:600">Pick a color</div>
                <div id="themeColorHex" style="font-size:13px;font-family:monospace;color:var(--text-light)">
                  <?= htmlspecialchars($settings['theme_color'] ?? '#0066cc') ?>
                </div>
              </div>
            </div>
            <div style="margin-top:16px">
              <div style="font-size:13px;font-weight:600;margin-bottom:8px">Quick presets</div>
              <div style="display:flex;gap:10px;flex-wrap:wrap">
                <?php
                $presets = [
                    '#0066cc' => 'Blue',
                    '#16a34a' => 'Green',
                    '#7c3aed' => 'Purple',
                    '#dc2626' => 'Red',
                    '#ea580c' => 'Orange',
                    '#0891b2' => 'Teal',
                    '#1e293b' => 'Dark',
                ];
                foreach ($presets as $hex => $label):
                ?>
                <button type="button"
                  onclick="document.getElementById('themeColorPicker').value='<?= $hex ?>'; previewTheme('<?= $hex ?>')"
                  title="<?= $label ?>"
                  style="width:32px;height:32px;border-radius:8px;background:<?= $hex ?>;border:3px solid <?= ($settings['theme_color'] ?? '#0066cc') === $hex ? '#000' : 'transparent' ?>;cursor:pointer;transition:transform 0.15s"
                  onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'">
                </button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

        </div>

        <!-- Live preview strip -->
        <div style="margin-top:20px;padding:16px;background:var(--muted);border-radius:10px;border:1px solid var(--border)">
          <div style="font-size:13px;font-weight:600;margin-bottom:12px;color:var(--text-light)">LIVE PREVIEW</div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button type="button" class="btn btn-primary btn-sm" id="previewBtn">Primary Button</button>
            <span class="badge badge-success">Active Badge</span>
            <span style="color:var(--primary);font-weight:600" id="previewLink">Link Text</span>
            <div style="height:4px;width:120px;background:var(--primary);border-radius:2px" id="previewBar"></div>
          </div>
        </div>
      </div>

      <div style="margin-top:20px;margin-bottom:20px">
        <button class="btn btn-primary" type="submit">
          💾 Save All Settings
        </button>
        <span style="margin-left:14px;font-size:13px;color:var(--text-light)">
          Changes take effect immediately on save.
        </span>
      </div>
    </form>

    <!-- ── Security / Password ─────────────────────────────── -->
    <form method="POST">
      <input type="hidden" name="action" value="change_password">
      <div class="card">
        <h3>🔒 Security Settings</h3>
        <p class="text-muted" style="margin-bottom:18px">Change your admin account password.</p>
        <div class="form-grid">
          <div class="form-group">
            <label>Current Password</label>
            <div style="position:relative">
              <input type="password" name="current_password" id="curPwd" class="form-control" placeholder="Enter current password">
              <span onclick="togglePwd('curPwd',this)" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:16px;user-select:none">👁️</span>
            </div>
          </div>
          <div class="form-group">
            <label>New Password</label>
            <div style="position:relative">
              <input type="password" name="new_password" id="newPwd" class="form-control" placeholder="Min. 8 characters"
                oninput="checkPwdStrength(this.value)">
              <span onclick="togglePwd('newPwd',this)" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:16px;user-select:none">👁️</span>
            </div>
            <!-- Password strength bar -->
            <div style="margin-top:8px;height:4px;background:var(--border);border-radius:2px;overflow:hidden">
              <div id="pwdStrengthBar" style="height:100%;width:0%;background:#ef4444;border-radius:2px;transition:all 0.3s"></div>
            </div>
            <small id="pwdStrengthLabel" class="text-muted"></small>
          </div>
          <div class="form-group">
            <label>Confirm New Password</label>
            <div style="position:relative">
              <input type="password" name="confirm_password" id="conPwd" class="form-control" placeholder="Repeat new password">
              <span onclick="togglePwd('conPwd',this)" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:16px;user-select:none">👁️</span>
            </div>
          </div>
        </div>
        <button class="btn btn-secondary" type="submit" style="margin-top:4px">🔒 Update Password</button>
      </div>
    </form>

    <!-- ── Backup & Restore ─────────────────────────────────── -->
    <div class="card" style="margin-top:20px">
      <h3>💾 Backup &amp; Restore</h3>
      <p class="text-muted">Create snapshots of the barangay database for safekeeping.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
        <button class="btn btn-secondary btn-sm"
          onclick="showToast('Contact your server administrator or use phpMyAdmin to download a .sql backup.','info')">
          📥 Create Backup
        </button>
        <button class="btn btn-secondary btn-sm"
          onclick="showToast('To restore: import a .sql file via phpMyAdmin or your database management tool.','info')">
          📤 Restore Backup
        </button>
      </div>
    </div>

    <!-- ── System Info ──────────────────────────────────────── -->
    <div class="card" style="margin-top:20px">
      <h3>ℹ️ System Information</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-top:12px">
        <div style="padding:14px;background:var(--muted);border-radius:8px">
          <div style="font-size:12px;color:var(--text-light);font-weight:600;margin-bottom:4px">SYSTEM NAME</div>
          <div style="font-weight:600">ALAB-SI</div>
          <div style="font-size:12px;color:var(--text-light)">Local Assistance for Barangay Services</div>
        </div>
        <div style="padding:14px;background:var(--muted);border-radius:8px">
          <div style="font-size:12px;color:var(--text-light);font-weight:600;margin-bottom:4px">PHP VERSION</div>
          <div style="font-weight:600"><?= PHP_VERSION ?></div>
        </div>
        <div style="padding:14px;background:var(--muted);border-radius:8px">
          <div style="font-size:12px;color:var(--text-light);font-weight:600;margin-bottom:4px">LOGGED IN AS</div>
          <div style="font-weight:600"><?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?></div>
          <div style="font-size:12px;color:var(--text-light)"><?= htmlspecialchars($_SESSION['admin_role'] ?? '') ?></div>
        </div>
        <div style="padding:14px;background:var(--muted);border-radius:8px">
          <div style="font-size:12px;color:var(--text-light);font-weight:600;margin-bottom:4px">SERVER TIME</div>
          <div style="font-weight:600"><?= date('M d, Y g:i A') ?></div>
        </div>
      </div>
    </div>

  </main>
</div>

<script src="assets/js/app.js"></script>
<script>
// ── Logo preview ──────────────────────────────────────────────
function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = (e) => {
    document.getElementById('logoPreview').src = e.target.result;
  };
  reader.readAsDataURL(input.files[0]);
}

// ── Theme color live preview ──────────────────────────────────
function previewTheme(color) {
  document.documentElement.style.setProperty('--primary', color);
  // Approximate light version
  document.getElementById('themeColorHex').textContent = color;
}

// ── Password show/hide toggle ─────────────────────────────────
function togglePwd(id, icon) {
  const input = document.getElementById(id);
  if (input.type === 'password') {
    input.type = 'text';
    icon.textContent = '🙈';
  } else {
    input.type = 'password';
    icon.textContent = '👁️';
  }
}

// ── Password strength checker ─────────────────────────────────
function checkPwdStrength(val) {
  const bar   = document.getElementById('pwdStrengthBar');
  const label = document.getElementById('pwdStrengthLabel');
  let score = 0;
  if (val.length >= 8)                        score++;
  if (/[A-Z]/.test(val))                      score++;
  if (/[0-9]/.test(val))                      score++;
  if (/[^A-Za-z0-9]/.test(val))              score++;

  const levels = [
    { pct: '0%',   color: '#ef4444', text: '' },
    { pct: '25%',  color: '#ef4444', text: 'Weak' },
    { pct: '50%',  color: '#f59e0b', text: 'Fair' },
    { pct: '75%',  color: '#3b82f6', text: 'Good' },
    { pct: '100%', color: '#10b981', text: 'Strong' },
  ];
  const lvl = levels[score] ?? levels[0];
  bar.style.width      = lvl.pct;
  bar.style.background = lvl.color;
  label.textContent    = lvl.text;
  label.style.color    = lvl.color;
}
</script>
</body>
</html>
