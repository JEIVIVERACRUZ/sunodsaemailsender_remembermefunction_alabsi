<?php
// ============================================================
// resident_myprofile.php  — Resident: View/update profile
// ============================================================
session_start();
require_once 'includes/db.php';
requireResidentLogin();

$residentId = $_SESSION['resident_id'];
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $phone      = trim($_POST['phone']      ?? '');
        $street     = trim($_POST['street']     ?? '');
        $zone       = trim($_POST['zone']       ?? '');
        $house      = trim($_POST['house']      ?? '');
        $occupation = trim($_POST['occupation'] ?? '');
        $workplace  = trim($_POST['workplace']  ?? '');

        dbExecute(
            "UPDATE residents SET phone=?, street_address=?, zone_purok=?, house_number=?,
             occupation=?, workplace=?, updated_at=NOW() WHERE id=?",
            [$phone, $street, $zone, $house, $occupation, $workplace, $residentId]
        );
        logActivity('Updated resident profile', 'Profile', 'resident');
        $success = 'Profile updated successfully.';
    }

    // ── Household member management (head only, AJAX) ──────────
    if (in_array($action, ['hh_add_member','hh_remove_member','hh_update_member','hh_add_suggested'])) {
        $me = dbFetchOne("SELECT household_id, is_household_head FROM residents WHERE id=?", [$residentId]);
        if (!$me['is_household_head'] || !$me['household_id']) {
            jsonError('Only the household head can manage members.');
        }
        $hhId = (int)$me['household_id'];

        if ($action === 'hh_add_member') {
            $fullName = trim($_POST['full_name'] ?? '');
            $relation = trim($_POST['relation']  ?? '');
            $age      = (int)($_POST['age']      ?? 0);
            if (!$fullName || !$relation) jsonError('Name and relation are required.');
            dbInsert(
                "INSERT INTO household_members (household_id, full_name, relation, age, status)
                 VALUES (?,?,?,?,'Pending')",
                [$hhId, $fullName, $relation, $age ?: null]
            );
            dbExecute(
                "UPDATE households SET member_count=(SELECT COUNT(*) FROM household_members WHERE household_id=?) WHERE id=?",
                [$hhId, $hhId]
            );
            logActivity('Added household member', 'Profile', 'resident');
            jsonOk([], 'Member added.');
        }

        // Add a suggested (same-address) registered resident as a member
        if ($action === 'hh_add_suggested') {
            $suggestedId = (int)($_POST['resident_id'] ?? 0);
            $relation    = trim($_POST['relation']     ?? 'Relative');
            if (!$suggestedId) jsonError('Invalid resident.');

            // Prevent duplicates
            $alreadyMember = dbFetchOne(
                "SELECT id FROM household_members WHERE household_id=? AND resident_id=?",
                [$hhId, $suggestedId]
            );
            if ($alreadyMember) jsonError('This resident is already a household member.');

            $r = dbFetchOne(
                "SELECT CONCAT(first_name,' ',last_name) AS full_name FROM residents WHERE id=?",
                [$suggestedId]
            );
            if (!$r) jsonError('Resident not found.');

            dbInsert(
                "INSERT INTO household_members (household_id, resident_id, full_name, relation, status)
                 VALUES (?,?,?,?,'Pending')",
                [$hhId, $suggestedId, $r['full_name'], $relation]
            );
            // Link that resident to this household
            dbExecute(
                "UPDATE residents SET household_id=? WHERE id=?",
                [$hhId, $suggestedId]
            );
            dbExecute(
                "UPDATE households SET member_count=(SELECT COUNT(*) FROM household_members WHERE household_id=?) WHERE id=?",
                [$hhId, $hhId]
            );
            logActivity('Added suggested resident as household member', 'Profile', 'resident');
            jsonOk([], $r['full_name'] . ' added to your household.');
        }

        if ($action === 'hh_remove_member') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $member   = dbFetchOne("SELECT relation, household_id, resident_id FROM household_members WHERE id=?", [$memberId]);
            if (!$member || (int)$member['household_id'] !== $hhId) jsonError('Member not found.');
            if ($member['relation'] === 'Head') jsonError('Cannot remove the household head.');
            // Unlink resident record if linked
            if ($member['resident_id']) {
                dbExecute("UPDATE residents SET household_id=NULL WHERE id=?", [$member['resident_id']]);
            }
            dbExecute("DELETE FROM household_members WHERE id=?", [$memberId]);
            dbExecute(
                "UPDATE households SET member_count=(SELECT COUNT(*) FROM household_members WHERE household_id=?) WHERE id=?",
                [$hhId, $hhId]
            );
            logActivity('Removed household member', 'Profile', 'resident');
            jsonOk([], 'Member removed.');
        }

        if ($action === 'hh_update_member') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $fullName = trim($_POST['full_name']  ?? '');
            $relation = trim($_POST['relation']   ?? '');
            $age      = (int)($_POST['age']       ?? 0);
            $member   = dbFetchOne("SELECT household_id FROM household_members WHERE id=?", [$memberId]);
            if (!$member || (int)$member['household_id'] !== $hhId) jsonError('Member not found.');
            dbExecute(
                "UPDATE household_members SET full_name=?, relation=?, age=? WHERE id=?",
                [$fullName, $relation, $age ?: null, $memberId]
            );
            logActivity('Updated household member', 'Profile', 'resident');
            jsonOk([], 'Member updated.');
        }
        exit;
    }

    // ── Request to become household head ──────────────────────
    if ($action === 'request_head') {
        // Must not already be a head or have a pending request
        if ($resident['is_household_head'] ?? false) jsonError('You are already a household head.');

        $existing = dbFetchOne(
            "SELECT id, status FROM head_requests WHERE resident_id=? AND status='Pending' LIMIT 1",
            [$residentId]
        );
        if ($existing) jsonError('You already have a pending request. Please wait for admin review.');

        $houseNo = trim($_POST['house_number'] ?? $resident['house_number'] ?? '');
        $zone    = trim($_POST['zone_purok']   ?? $resident['zone_purok']   ?? '');
        $street  = trim($_POST['street']       ?? $resident['street_address'] ?? '');
        $reason  = trim($_POST['reason']       ?? '');

        if (!$houseNo || !$zone) jsonError('House number and zone/purok are required to request head status.');

        // Check for conflicting household at the same address
        $dup = dbFetchOne(
            "SELECT id FROM households
             WHERE LOWER(TRIM(house_number)) = LOWER(TRIM(?))
               AND LOWER(TRIM(zone_purok))   = LOWER(TRIM(?))
             LIMIT 1",
            [$houseNo, $zone]
        );
        if ($dup) jsonError('A household already exists at that address. You can ask the current head to add you as a member.');

        dbInsert(
            "INSERT INTO head_requests (resident_id, house_number, zone_purok, street_address, reason)
             VALUES (?,?,?,?,?)",
            [$residentId, $houseNo, $zone, $street, $reason]
        );
        logActivity('Submitted household head request', 'Profile', 'resident');
        jsonOk([], 'Your request has been submitted. The barangay admin will review it shortly.');
    }

    // ── Cancel a pending head request ─────────────────────────
    if ($action === 'cancel_head_request') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $req   = dbFetchOne("SELECT id FROM head_requests WHERE id=? AND resident_id=? AND status='Pending'", [$reqId, $residentId]);
        if (!$req) jsonError('Request not found or already processed.');
        dbExecute("DELETE FROM head_requests WHERE id=?", [$reqId]);
        logActivity('Cancelled household head request', 'Profile', 'resident');
        jsonOk([], 'Request cancelled.');
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $r = dbFetchOne("SELECT password_hash FROM residents WHERE id=?", [$residentId]);
        if (!password_verify($current, $r['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            dbExecute(
                "UPDATE residents SET password_hash=? WHERE id=?",
                [password_hash($new, PASSWORD_BCRYPT), $residentId]
            );
            $success = 'Password updated successfully.';
        }
    }
}

$resident = dbFetchOne(
    "SELECT *, TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) AS age FROM residents WHERE id=?",
    [$residentId]
);

// ── Head request status ───────────────────────────────────────
$headRequest = dbFetchOne(
    "SELECT * FROM head_requests WHERE resident_id=? ORDER BY created_at DESC LIMIT 1",
    [$residentId]
);

// ── Household data ────────────────────────────────────────────
$household = null;
$members   = [];
$suggested = [];   // same-address registered residents not yet in this household

if ($resident['household_id']) {
    $household = dbFetchOne(
        "SELECT h.*, CONCAT(r.first_name,' ',r.last_name) AS head_name
         FROM households h
         LEFT JOIN residents r ON h.household_head = r.id
         WHERE h.id=?",
        [$resident['household_id']]
    );
    if ($household) {
        $members = dbFetchAll(
            "SELECT * FROM household_members WHERE household_id=? ORDER BY relation='Head' DESC, id ASC",
            [$household['id']]
        );

        // Suggestions: registered residents at same house+zone not yet members
        if ($resident['is_household_head'] && $resident['house_number'] && $resident['zone_purok']) {
            // IDs already in this household
            $memberResidentIds = array_filter(array_column($members, 'resident_id'));
            $placeholders = $memberResidentIds ? implode(',', array_fill(0, count($memberResidentIds), '?')) : '0';
            $suggested = dbFetchAll(
                "SELECT id, CONCAT(first_name,' ',last_name) AS full_name, barangay_id
                 FROM residents
                 WHERE id != ?
                   AND LOWER(TRIM(house_number)) = LOWER(TRIM(?))
                   AND LOWER(TRIM(zone_purok))   = LOWER(TRIM(?))
                   AND status != 'Inactive'
                   AND id NOT IN ($placeholders)",
                array_merge([$residentId, $resident['house_number'], $resident['zone_purok']], $memberResidentIds)
            );
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Profile — Barangay San Isidro</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_resident.php'; ?>
  <main class="main">
    <div class="topbar">
      <div>
        <h3>My Profile</h3>
        <div class="text-muted">View and update your personal information</div>
      </div>
      <?php if ($resident['is_household_head']): ?>
      <div class="topbar-actions">
        <span style="font-size:12px;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:6px;padding:4px 10px;font-weight:600">🏠 Household Head</span>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($success): ?><div style="background:#dcfce7;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Personal Information (read-only) -->
    <div class="card">
      <h3>Personal Information</h3>
      <div class="form-grid" style="gap:18px;margin-bottom:18px">
        <div class="form-group"><label>First Name</label><input type="text" value="<?= htmlspecialchars($resident['first_name']) ?>" readonly></div>
        <div class="form-group"><label>Last Name</label><input type="text" value="<?= htmlspecialchars($resident['last_name']) ?>" readonly></div>
        <div class="form-group"><label>Email</label><input type="email" value="<?= htmlspecialchars($resident['email']) ?>" readonly></div>
        <div class="form-group"><label>Barangay ID</label><input type="text" value="<?= htmlspecialchars($resident['barangay_id']) ?>" readonly></div>
        <div class="form-group"><label>Date of Birth</label><input type="date" value="<?= htmlspecialchars($resident['date_of_birth'] ?? '') ?>" readonly></div>
        <div class="form-group"><label>Age</label><input type="text" value="<?= htmlspecialchars($resident['age'] ?? '') ?>" readonly></div>
        <div class="form-group"><label>Gender</label><input type="text" value="<?= htmlspecialchars($resident['gender'] ?? '') ?>" readonly></div>
        <div class="form-group"><label>Status</label><input type="text" value="<?= htmlspecialchars($resident['status']) ?>" readonly></div>
      </div>
    </div>

    <!-- Editable Info -->
    <form method="POST">
      <input type="hidden" name="action" value="update_profile">
      <div class="card">
        <h3>Residence &amp; Employment <span style="font-size:13px;color:var(--primary);font-weight:400">(editable)</span></h3>
        <div class="form-grid" style="gap:18px">
          <div class="form-group"><label>Phone</label><input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($resident['phone'] ?? '') ?>" placeholder="09xx xxx xxxx"></div>
          <div class="form-group"><label>Street Address</label><input type="text" name="street" class="form-control" value="<?= htmlspecialchars($resident['street_address'] ?? '') ?>"></div>
          <div class="form-group">
            <label>Zone / Purok</label>
            <select name="zone" class="form-control">
              <option value="">Select...</option>
              <?php
              $puroks = dbFetchAll("SELECT name FROM puroks ORDER BY name");
              $zones  = $puroks ?: [['name'=>'Zone 1'],['name'=>'Zone 2'],['name'=>'Zone 3'],['name'=>'Zone 4']];
              foreach ($zones as $z) {
                  $sel = ($resident['zone_purok'] === $z['name']) ? 'selected' : '';
                  echo "<option value=\"".htmlspecialchars($z['name'])."\" $sel>".htmlspecialchars($z['name'])."</option>";
              }
              ?>
            </select>
          </div>
          <div class="form-group"><label>House Number</label><input type="text" name="house" class="form-control" value="<?= htmlspecialchars($resident['house_number'] ?? '') ?>"></div>
          <div class="form-group"><label>Occupation</label><input type="text" name="occupation" class="form-control" value="<?= htmlspecialchars($resident['occupation'] ?? '') ?>"></div>
          <div class="form-group"><label>Workplace</label><input type="text" name="workplace" class="form-control" value="<?= htmlspecialchars($resident['workplace'] ?? '') ?>"></div>
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:16px">💾 Save Changes</button>
      </div>
    </form>

    <!-- ── Household Section ──────────────────────────────────── -->
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
        <h3 style="margin:0">My Household</h3>
        <?php if ($household && $resident['is_household_head']): ?>
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('hhAddModal').style.display='flex'">+ Add Member</button>
        <?php endif; ?>
      </div>

      <?php if ($household): ?>
      <!-- Household info bar -->
      <div style="background:var(--muted);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;display:flex;gap:24px;flex-wrap:wrap">
        <div><span style="color:var(--text-light)">House No:</span> <strong><?= htmlspecialchars($household['house_number'] ?: '—') ?></strong></div>
        <div><span style="color:var(--text-light)">Zone:</span> <strong><?= htmlspecialchars($household['zone_purok'] ?: '—') ?></strong></div>
        <div><span style="color:var(--text-light)">Address:</span> <strong><?= htmlspecialchars($household['address'] ?: '—') ?></strong></div>
        <div><span style="color:var(--text-light)">Head:</span> <strong><?= htmlspecialchars($household['head_name'] ?? '—') ?></strong></div>
        <div><span style="color:var(--text-light)">Members:</span> <strong><?= (int)$household['member_count'] ?></strong></div>
      </div>

      <!-- Members table -->
      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Relation</th>
              <th>Age</th>
              <th>Status</th>
              <?php if ($resident['is_household_head']): ?><th>Action</th><?php endif; ?>
            </tr>
          </thead>
          <tbody id="hhMembersBody">
            <?php if ($members): foreach ($members as $m):
              $badge = $m['status']==='Verified'?'badge-success':($m['status']==='Inactive'?'badge-danger':'badge-warning');
            ?>
            <tr id="hhrow-<?= $m['id'] ?>">
              <td><?= htmlspecialchars($m['full_name']) ?></td>
              <td><?= htmlspecialchars($m['relation']) ?></td>
              <td><?= $m['age'] ?: '—' ?></td>
              <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($m['status']) ?></span></td>
              <?php if ($resident['is_household_head']): ?>
              <td style="display:flex;gap:6px">
                <?php if ($m['relation'] !== 'Head'): ?>
                <button class="btn btn-sm" style="background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;padding:4px 8px;border-radius:6px;cursor:pointer;font-size:12px"
                  onclick='openHhEdit(<?= htmlspecialchars(json_encode($m)) ?>)'>Edit</button>
                <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:4px 8px;border-radius:6px;cursor:pointer;font-size:12px"
                  onclick="hhRemove(<?= $m['id'] ?>)">Remove</button>
                <?php else: ?>
                <span style="font-size:12px;color:var(--text-light)">You</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5" style="text-align:center;color:#aaa;padding:12px">No members yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- ── Suggested residents (same address) — HEAD ONLY ── -->
      <?php if ($resident['is_household_head'] && $suggested): ?>
      <div style="margin-top:20px;border-top:1px solid #f3f4f6;padding-top:16px">
        <div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:10px">
          💡 Residents at the same address — add them to your household:
        </div>
        <div style="display:flex;flex-direction:column;gap:8px" id="suggestedList">
          <?php foreach ($suggested as $s): ?>
          <div id="sug-<?= $s['id'] ?>" style="display:flex;align-items:center;justify-content:space-between;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px">
            <div>
              <span style="font-weight:500;font-size:14px"><?= htmlspecialchars($s['full_name']) ?></span>
              <span style="font-size:12px;color:#6b7280;margin-left:8px"><?= htmlspecialchars($s['barangay_id']) ?></span>
            </div>
            <button class="btn btn-sm" style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:5px 12px;border-radius:6px;font-size:12px;cursor:pointer"
              onclick="addSuggested(<?= $s['id'] ?>, <?= htmlspecialchars(json_encode($s['full_name'])) ?>)">
              + Add
            </button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php elseif ($resident['is_household_head']): ?>
        <p class="text-muted">You are marked as a household head but your household record is missing. Please contact the barangay office.</p>
      <?php else: ?>
        <p class="text-muted">You are not currently assigned to a household. Contact the barangay office if you need to be added.</p>
      <?php endif; ?>
    </div>

    <!-- ── Request to be Household Head ─────────────────────── -->
    <?php if (!$resident['is_household_head']): ?>
    <div class="card">
      <h3>🏠 Become a Household Head</h3>
      <?php
        $canRequest  = !$headRequest || $headRequest['status'] === 'Rejected';
        $isPending   = $headRequest && $headRequest['status'] === 'Pending';
        $isApproved  = $headRequest && $headRequest['status'] === 'Approved';
      ?>

      <?php if ($isPending): ?>
      <!-- Pending state -->
      <div style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:8px;padding:14px 16px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
          <div style="font-weight:600;margin-bottom:2px">⏳ Request Pending Review</div>
          <div style="font-size:13px">Submitted <?= date('M j, Y', strtotime($headRequest['created_at'])) ?> for House <?= htmlspecialchars($headRequest['house_number']) ?>, <?= htmlspecialchars($headRequest['zone_purok']) ?>.</div>
          <?php if ($headRequest['reason']): ?>
          <div style="font-size:12px;color:#78350f;margin-top:4px">Your reason: "<?= htmlspecialchars($headRequest['reason']) ?>"</div>
          <?php endif; ?>
        </div>
        <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:13px"
          onclick="cancelHeadRequest(<?= $headRequest['id'] ?>)">Cancel Request</button>
      </div>

      <?php elseif ($isApproved): ?>
      <!-- Should not appear normally (page reloads after approval promotes to head) -->
      <div style="background:#dcfce7;color:#166534;border:1px solid #86efac;border-radius:8px;padding:14px 16px">
        ✅ Your request was approved! Please refresh the page.
      </div>

      <?php elseif ($canRequest): ?>
      <?php if ($headRequest && $headRequest['status'] === 'Rejected'): ?>
      <div style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px">
        ❌ Your previous request was <strong>rejected</strong><?= $headRequest['admin_note'] ? ': "' . htmlspecialchars($headRequest['admin_note']) . '"' : '.' ?>
        You may submit a new request below.
      </div>
      <?php endif; ?>

      <p style="font-size:14px;color:#374151;margin-bottom:16px">
        Submit a request to be recognized as the head of your household. The barangay admin will review and approve your request.
      </p>
      <div style="display:grid;gap:14px">
        <div class="form-group">
          <label>House Number *</label>
          <input type="text" id="hrHouseNo" class="form-control"
            value="<?= htmlspecialchars($resident['house_number'] ?? '') ?>"
            placeholder="e.g., Lot 5, Blk 2">
        </div>
        <div class="form-group">
          <label>Zone / Purok *</label>
          <select id="hrZone" class="form-control">
            <option value="">Select...</option>
            <?php
            $puroksHr = dbFetchAll("SELECT name FROM puroks ORDER BY name");
            $zonesHr  = $puroksHr ?: [['name'=>'Zone 1'],['name'=>'Zone 2'],['name'=>'Zone 3'],['name'=>'Zone 4']];
            foreach ($zonesHr as $z) {
                $sel = ($resident['zone_purok'] === $z['name']) ? 'selected' : '';
                echo "<option value=\"".htmlspecialchars($z['name'])."\" $sel>".htmlspecialchars($z['name'])."</option>";
            }
            ?>
          </select>
        </div>
        <div class="form-group">
          <label>Street Address</label>
          <input type="text" id="hrStreet" class="form-control"
            value="<?= htmlspecialchars($resident['street_address'] ?? '') ?>"
            placeholder="e.g., 123 Sampaguita St.">
        </div>
        <div class="form-group">
          <label>Reason / Message to Admin <span style="font-weight:400;color:#9ca3af">(optional)</span></label>
          <textarea id="hrReason" class="form-control" rows="3" placeholder="e.g., I am the registered owner of this lot and the primary provider for our family."></textarea>
        </div>
        <div>
          <button class="btn btn-primary" onclick="submitHeadRequest()">📨 Submit Request</button>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Change Password -->
    <form method="POST">
      <input type="hidden" name="action" value="change_password">
      <div class="card">
        <h3>Account Security</h3>
        <div class="form-grid">
          <div class="form-group"><label>Current Password</label><input type="password" name="current_password" class="form-control" placeholder="Enter current password"></div>
          <div class="form-group"><label>New Password</label><input type="password" name="new_password" class="form-control" placeholder="Min. 8 characters"></div>
          <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" class="form-control" placeholder="Repeat new password"></div>
        </div>
        <button class="btn btn-secondary btn-sm" type="submit" style="margin-top:8px">🔒 Change Password</button>
      </div>
    </form>
  </main>
</div>

<!-- Add Household Member Modal -->
<div id="hhAddModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:12px;padding:28px;width:90%;max-width:420px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h3 style="margin:0">Add Household Member</h3>
      <button onclick="document.getElementById('hhAddModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9ca3af">✕</button>
    </div>
    <div style="display:grid;gap:14px">
      <div class="form-group">
        <label>Full Name *</label>
        <input type="text" id="hhAmName" class="form-control" placeholder="Member's full name">
      </div>
      <div class="form-group">
        <label>Relation to You *</label>
        <select id="hhAmRelation" class="form-control">
          <option value="">Select...</option>
          <option>Spouse</option><option>Son</option><option>Daughter</option>
          <option>Father</option><option>Mother</option><option>Sibling</option>
          <option>Grandchild</option><option>Grandparent</option><option>Relative</option><option>Other</option>
        </select>
      </div>
      <div class="form-group">
        <label>Age</label>
        <input type="number" id="hhAmAge" class="form-control" placeholder="Age" min="0" max="120">
      </div>
      <div style="display:flex;gap:10px;margin-top:8px">
        <button class="btn btn-primary" style="flex:1" onclick="hhAddMember()">Add</button>
        <button class="btn btn-secondary" style="flex:1" onclick="document.getElementById('hhAddModal').style.display='none'">Cancel</button>
      </div>
    </div>
  </div>
</div>

<!-- Add Suggested Member Modal (relation picker) -->
<div id="hhSugModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:12px;padding:28px;width:90%;max-width:380px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 style="margin:0">Add to Household</h3>
      <button onclick="document.getElementById('hhSugModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9ca3af">✕</button>
    </div>
    <p style="font-size:14px;color:#374151;margin:0 0 14px">Adding: <strong id="sugName"></strong></p>
    <input type="hidden" id="sugResidentId">
    <div class="form-group">
      <label>Relation to You *</label>
      <select id="sugRelation" class="form-control">
        <option value="">Select...</option>
        <option>Spouse</option><option>Son</option><option>Daughter</option>
        <option>Father</option><option>Mother</option><option>Sibling</option>
        <option>Grandchild</option><option>Grandparent</option><option>Relative</option><option>Other</option>
      </select>
    </div>
    <div style="display:flex;gap:10px;margin-top:14px">
      <button class="btn btn-primary" style="flex:1" onclick="confirmAddSuggested()">Add</button>
      <button class="btn btn-secondary" style="flex:1" onclick="document.getElementById('hhSugModal').style.display='none'">Cancel</button>
    </div>
  </div>
</div>

<!-- Edit Household Member Modal -->
<div id="hhEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:12px;padding:28px;width:90%;max-width:420px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h3 style="margin:0">Edit Member</h3>
      <button onclick="document.getElementById('hhEditModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9ca3af">✕</button>
    </div>
    <input type="hidden" id="hhEmId">
    <div style="display:grid;gap:14px">
      <div class="form-group">
        <label>Full Name *</label>
        <input type="text" id="hhEmName" class="form-control">
      </div>
      <div class="form-group">
        <label>Relation *</label>
        <select id="hhEmRelation" class="form-control">
          <option>Spouse</option><option>Son</option><option>Daughter</option>
          <option>Father</option><option>Mother</option><option>Sibling</option>
          <option>Grandchild</option><option>Grandparent</option><option>Relative</option><option>Other</option>
        </select>
      </div>
      <div class="form-group">
        <label>Age</label>
        <input type="number" id="hhEmAge" class="form-control" min="0" max="120">
      </div>
      <div style="display:flex;gap:10px;margin-top:8px">
        <button class="btn btn-primary" style="flex:1" onclick="hhSaveMember()">Save</button>
        <button class="btn btn-secondary" style="flex:1" onclick="document.getElementById('hhEditModal').style.display='none'">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/app.js"></script>
<script>
async function hhAddMember() {
  const fd = new FormData();
  fd.append('action',    'hh_add_member');
  fd.append('full_name', document.getElementById('hhAmName').value.trim());
  fd.append('relation',  document.getElementById('hhAmRelation').value);
  fd.append('age',       document.getElementById('hhAmAge').value);
  const res = await fetch('resident_myprofile.php', {method:'POST', body:fd});
  const d   = await res.json();
  if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 800); }
  else showToast(d.message, 'error');
}

function openHhEdit(m) {
  document.getElementById('hhEmId').value       = m.id;
  document.getElementById('hhEmName').value     = m.full_name;
  document.getElementById('hhEmRelation').value = m.relation;
  document.getElementById('hhEmAge').value      = m.age || '';
  document.getElementById('hhEditModal').style.display = 'flex';
}

async function hhSaveMember() {
  const fd = new FormData();
  fd.append('action',    'hh_update_member');
  fd.append('member_id', document.getElementById('hhEmId').value);
  fd.append('full_name', document.getElementById('hhEmName').value.trim());
  fd.append('relation',  document.getElementById('hhEmRelation').value);
  fd.append('age',       document.getElementById('hhEmAge').value);
  const res = await fetch('resident_myprofile.php', {method:'POST', body:fd});
  const d   = await res.json();
  if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 800); }
  else showToast(d.message, 'error');
}

async function hhRemove(memberId) {
  if (!confirm('Remove this member from your household?')) return;
  const fd = new FormData();
  fd.append('action',    'hh_remove_member');
  fd.append('member_id', memberId);
  const res = await fetch('resident_myprofile.php', {method:'POST', body:fd});
  const d   = await res.json();
  if (d.success) {
    document.getElementById('hhrow-' + memberId)?.remove();
    showToast('Member removed.', 'info');
  } else showToast(d.message, 'error');
}

// ── Suggested resident flow ───────────────────────────────────
function addSuggested(residentId, name) {
  document.getElementById('sugResidentId').value = residentId;
  document.getElementById('sugName').textContent = name;
  document.getElementById('sugRelation').value   = '';
  document.getElementById('hhSugModal').style.display = 'flex';
}

// ── Household Head Request ────────────────────────────────────
async function submitHeadRequest() {
  const houseNo = document.getElementById('hrHouseNo')?.value.trim();
  const zone    = document.getElementById('hrZone')?.value;
  const street  = document.getElementById('hrStreet')?.value.trim();
  const reason  = document.getElementById('hrReason')?.value.trim();

  if (!houseNo || !zone) { showToast('House number and zone are required.', 'error'); return; }

  const fd = new FormData();
  fd.append('action',       'request_head');
  fd.append('house_number', houseNo);
  fd.append('zone_purok',   zone);
  fd.append('street',       street);
  fd.append('reason',       reason);

  const res = await fetch('resident_myprofile.php', {method:'POST', body:fd});
  const d   = await res.json();
  if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 900); }
  else showToast(d.message, 'error');
}

async function cancelHeadRequest(requestId) {
  if (!confirm('Cancel your household head request?')) return;
  const fd = new FormData();
  fd.append('action',     'cancel_head_request');
  fd.append('request_id', requestId);
  const res = await fetch('resident_myprofile.php', {method:'POST', body:fd});
  const d   = await res.json();
  if (d.success) { showToast('Request cancelled.', 'info'); setTimeout(() => location.reload(), 800); }
  else showToast(d.message, 'error');
}

async function confirmAddSuggested() {
  const relation = document.getElementById('sugRelation').value;
  if (!relation) { showToast('Please select a relation.', 'error'); return; }

  const residentId = document.getElementById('sugResidentId').value;
  const fd = new FormData();
  fd.append('action',      'hh_add_suggested');
  fd.append('resident_id', residentId);
  fd.append('relation',    relation);

  const res = await fetch('resident_myprofile.php', {method:'POST', body:fd});
  const d   = await res.json();

  if (d.success) {
    document.getElementById('hhSugModal').style.display = 'none';
    // Remove the suggested card
    document.getElementById('sug-' + residentId)?.remove();
    showToast(d.message, 'success');
    setTimeout(() => location.reload(), 900);
  } else {
    showToast(d.message, 'error');
  }
}
</script>
</body>
</html>