<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/config.php';
$conn = getDBConnection();

$message = '';
$messageType = '';

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appId  = intval($_POST['pwd_app_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note   = trim($_POST['admin_notes'] ?? '');

    if ($appId > 0 && in_array($action, ['approve', 'reject'])) {
        $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
        $pwdApproved = ($action === 'approve') ? 1 : 0;

        // Get acc_id from application
        $appStmt = $conn->prepare("SELECT acc_id FROM PWD_APPLICATIONS WHERE pwd_app_id = ?");
        $appStmt->bind_param("i", $appId);
        $appStmt->execute();
        $appRow = $appStmt->get_result()->fetch_assoc();
        $appStmt->close();

        if ($appRow) {
            $accId = $appRow['acc_id'];

            // Update application
            $updStmt = $conn->prepare("UPDATE PWD_APPLICATIONS SET status = ?, admin_notes = ?, reviewed_at = NOW() WHERE pwd_app_id = ?");
            $updStmt->bind_param("ssi", $newStatus, $note, $appId);
            $updStmt->execute();
            $updStmt->close();

            // Update user account
            $uUpdStmt = $conn->prepare("UPDATE USER_ACCOUNT SET pwd_approved = ? WHERE acc_id = ?");
            $uUpdStmt->bind_param("ii", $pwdApproved, $accId);
            $uUpdStmt->execute();
            $uUpdStmt->close();

            // Mark related notifications as read
            $nStmt = $conn->prepare("UPDATE ADMIN_NOTIFICATIONS SET is_read = 1 WHERE type = 'pwd_application' AND reference_id = ?");
            $nStmt->bind_param("i", $appId);
            $nStmt->execute();
            $nStmt->close();

            $message = "Application #$appId has been " . $newStatus . ".";
            $messageType = ($action === 'approve') ? 'success' : 'error';
        }
    }
}

// Fetch all applications
$applications = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'PWD_APPLICATIONS'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $res = $conn->query("
        SELECT p.*, u.firstName, u.lastName, u.email
        FROM PWD_APPLICATIONS p
        JOIN USER_ACCOUNT u ON p.acc_id = u.acc_id
        ORDER BY FIELD(p.status, 'pending', 'rejected', 'approved'), p.submitted_at DESC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $applications[] = $row;
        }
    }
}

// Count pending
$pendingCount = 0;
foreach ($applications as $a) {
    if ($a['status'] === 'pending') $pendingCount++;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PWD Applications - Admin</title>
    <link rel="icon" type="image/png" href="images/brand x.png" />
    <link rel="stylesheet" href="css/admin-panel.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
    <style>
        .pwd-admin-header { margin-bottom: 28px; }
        .pwd-admin-header h1 { font-size: 1.6rem; color: #fff; font-weight: 700; }
        .pwd-admin-header p { color: rgba(255,255,255,0.5); font-size: 0.9rem; margin-top: 4px; }

        .pwd-table-wrap { overflow-x: auto; }
        .pwd-table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(255,255,255,0.04);
            border-radius: 12px;
            overflow: hidden;
            font-size: 0.9rem;
        }
        .pwd-table th {
            background: rgba(85,138,206,0.18);
            color: #7ab5ff;
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .pwd-table td {
            padding: 14px 16px;
            color: #e0e0e0;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            vertical-align: middle;
        }
        .pwd-table tr:last-child td { border-bottom: none; }
        .pwd-table tr:hover td { background: rgba(85,138,206,0.06); }

        .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-badge.pending  { background: rgba(255,193,7,0.15);  color: #ffd966; border: 1px solid rgba(255,193,7,0.3); }
        .status-badge.approved { background: rgba(76,175,80,0.15);  color: #8ec98e; border: 1px solid rgba(76,175,80,0.3); }
        .status-badge.rejected { background: rgba(244,67,54,0.12);  color: #ff8a8a; border: 1px solid rgba(244,67,54,0.3); }

        .view-img-link {
            display: inline-block;
            padding: 5px 14px;
            background: rgba(85,138,206,0.2);
            color: #7ab5ff;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            border: 1px solid rgba(85,138,206,0.3);
            transition: all 0.2s;
        }
        .view-img-link:hover { background: rgba(85,138,206,0.35); color: #fff; }

        .action-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .note-input {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 6px;
            color: #fff;
            padding: 6px 10px;
            font-size: 0.82rem;
            width: 160px;
        }
        .btn-approve {
            background: rgba(76,175,80,0.8);
            color: #fff;
            border: none;
            padding: 7px 16px;
            border-radius: 18px;
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-approve:hover { background: #4CAF50; }
        .btn-reject {
            background: rgba(220,53,69,0.8);
            color: #fff;
            border: none;
            padding: 7px 16px;
            border-radius: 18px;
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-reject:hover { background: #dc3545; }

        .msg-box {
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-weight: 500;
        }
        .msg-box.success { background: rgba(76,175,80,0.12); color: #8ec98e; border: 1px solid rgba(76,175,80,0.3); }
        .msg-box.error   { background: rgba(244,67,54,0.12);  color: #ff8a8a; border: 1px solid rgba(244,67,54,0.3); }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: rgba(255,255,255,0.35);
            font-size: 0.95rem;
        }

        .notif-badge {
            display: inline-block;
            background: #e74c3c;
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 12px;
            margin-left: 6px;
            vertical-align: middle;
        }

        /* Image modal */
        .img-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.88);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .img-modal-overlay.active { display: flex; }
        .img-modal-box {
            background: #1a1a2e;
            border-radius: 12px;
            padding: 20px;
            max-width: 90vw;
            max-height: 90vh;
            overflow: auto;
            position: relative;
        }
        .img-modal-box img { max-width: 80vw; max-height: 75vh; border-radius: 8px; display: block; }
        .img-modal-close {
            position: absolute;
            top: 10px;
            right: 14px;
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5rem;
            cursor: pointer;
            font-weight: 700;
        }
        .img-modal-title { color: #ccc; font-size: 0.9rem; margin-bottom: 12px; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="profile-section">
            <img src="images/brand x.png" alt="Profile Picture" class="profile-pic clickable-logo" onclick="toggleLogout()" style="cursor:pointer;" />
            <h2>Admin</h2>
        </div>
        <nav class="sidebar-nav">
            <a href="admin-panel.php">Dashboard</a>
            <a href="add-show.php">Add Shows</a>
            <a href="view-shows.php">List Shows</a>
            <a href="view-bookings.php">List Bookings</a>
            <a href="view-deleted-movies.php">Deleted Movies</a>
            <a href="admin-pwd-applications.php" class="active">
                PWD Applications
                <?php if ($pendingCount > 0): ?>
                    <span class="notif-badge"><?= $pendingCount ?></span>
                <?php endif; ?>
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn" id="logoutBtn" style="display:none;">Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header>
            <h1>PWD <span class="highlight">Applications</span></h1>
        </header>

        <?php if ($message): ?>
        <div class="msg-box <?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <div class="pwd-admin-header">
            <p>Review and manage PWD discount applications submitted by users. Approved users will receive a 20% seat discount at checkout.</p>
        </div>

        <?php if (empty($applications)): ?>
            <div class="empty-state">No PWD applications yet.</div>
        <?php else: ?>
        <div class="pwd-table-wrap">
            <table class="pwd-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Email</th>
                        <th>PWD ID Number</th>
                        <th>ID Image</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Admin Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($applications as $app): ?>
                    <tr>
                        <td><?= $app['pwd_app_id'] ?></td>
                        <td><strong><?= htmlspecialchars(trim($app['firstName'] . ' ' . $app['lastName'])) ?></strong></td>
                        <td><?= htmlspecialchars($app['email']) ?></td>
                        <td><?= htmlspecialchars($app['pwd_id_number']) ?></td>
                        <td>
                            <a href="#" class="view-img-link" onclick="openImgModal('<?= htmlspecialchars($app['pwd_id_image']) ?>', '<?= htmlspecialchars($app['pwd_id_number']) ?>'); return false;">
                                View Image
                            </a>
                        </td>
                        <td><span class="status-badge <?= $app['status'] ?>"><?= ucfirst($app['status']) ?></span></td>
                        <td><?= date('M d, Y H:i', strtotime($app['submitted_at'])) ?></td>
                        <td><?= htmlspecialchars($app['admin_notes'] ?? '') ?></td>
                        <td>
                            <?php if ($app['status'] === 'pending'): ?>
                            <form method="POST" class="action-form" onsubmit="return confirm('Are you sure?');">
                                <input type="hidden" name="pwd_app_id" value="<?= $app['pwd_app_id'] ?>">
                                <input type="text" name="admin_notes" class="note-input" placeholder="Optional note...">
                                <button type="submit" name="action" value="approve" class="btn-approve">Approve</button>
                                <button type="submit" name="action" value="reject" class="btn-reject">Reject</button>
                            </form>
                            <?php elseif ($app['status'] === 'approved'): ?>
                            <form method="POST" class="action-form" onsubmit="return confirm('Revoke approval?');">
                                <input type="hidden" name="pwd_app_id" value="<?= $app['pwd_app_id'] ?>">
                                <input type="text" name="admin_notes" class="note-input" placeholder="Reason...">
                                <button type="submit" name="action" value="reject" class="btn-reject">Revoke</button>
                            </form>
                            <?php elseif ($app['status'] === 'rejected'): ?>
                            <form method="POST" class="action-form" onsubmit="return confirm('Re-approve?');">
                                <input type="hidden" name="pwd_app_id" value="<?= $app['pwd_app_id'] ?>">
                                <button type="submit" name="action" value="approve" class="btn-approve">Re-approve</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </main>

    <!-- Image Modal -->
    <div class="img-modal-overlay" id="imgModal">
        <div class="img-modal-box">
            <button class="img-modal-close" onclick="closeImgModal()">×</button>
            <p class="img-modal-title" id="imgModalTitle"></p>
            <img id="imgModalImg" src="" alt="PWD ID">
        </div>
    </div>

    <script>
    function toggleLogout() {
        const btn = document.getElementById('logoutBtn');
        btn.style.display = (btn.style.display === 'none' || btn.style.display === '') ? 'block' : 'none';
    }

    function openImgModal(src, pwdId) {
        document.getElementById('imgModalImg').src = src;
        document.getElementById('imgModalTitle').textContent = 'PWD ID: ' + pwdId;
        document.getElementById('imgModal').classList.add('active');
    }

    function closeImgModal() {
        document.getElementById('imgModal').classList.remove('active');
        document.getElementById('imgModalImg').src = '';
    }

    document.getElementById('imgModal').addEventListener('click', function(e) {
        if (e.target === this) closeImgModal();
    });
    </script>
</body>
</html>
