<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_role('admin');

$pageTitle = 'Settings';
$active = 'settings';
$error = '';
$success = '';

$fields = [
    'resort_name'    => 'Resort Name',
    'resort_address' => 'Address',
    'resort_phone'   => 'Phone',
    'resort_email'   => 'Email',
    'resort_website' => 'Website',
    'wifi_name'      => 'Wi-Fi Name (SSID)',
    'wifi_password'  => 'Wi-Fi Password',
];

// =====================================================
// Save submission
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resortName = trim($_POST['resort_name'] ?? '');

    if ($resortName === '') {
        $error = 'Resort name is required.';
    } else {
        foreach ($fields as $key => $label) {
            $value = trim($_POST[$key] ?? '');
            $stmt = mysqli_prepare($conn, "
                INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        $_SESSION['flash_success'] = 'Settings saved successfully.';
        header('Location: settings.php');
        exit;
    }
}

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// =====================================================
// Load current settings into a simple [key => value] array
// =====================================================
$current = [];
$rows = fetch_all($conn, "SELECT setting_key, setting_value FROM settings");
foreach ($rows as $row) {
    $current[$row['setting_key']] = $row['setting_value'];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h3>Settings</h3>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card-form">
            <div class="section-title">Resort Information</div>
            <div class="text-muted small mb-3">Used on printed receipts, invoices, and anywhere the resort's details are shown.</div>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Resort Name *</label>
                    <input type="text" name="resort_name" class="form-control" required maxlength="150"
                           value="<?php echo e($current['resort_name'] ?? ''); ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="resort_address" class="form-control" rows="2" maxlength="255"><?php echo e($current['resort_address'] ?? ''); ?></textarea>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="resort_phone" class="form-control" maxlength="30"
                               value="<?php echo e($current['resort_phone'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="resort_email" class="form-control" maxlength="150"
                               value="<?php echo e($current['resort_email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Website</label>
                    <input type="text" name="resort_website" class="form-control" maxlength="150"
                           placeholder="https://example.com"
                           value="<?php echo e($current['resort_website'] ?? ''); ?>">
                </div>

                <hr class="my-4">

                <div class="section-title">Guest Wi-Fi</div>
                <div class="text-muted small mb-3">Shown on receipts / welcome info so guests can connect easily.</div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Wi-Fi Name (SSID)</label>
                        <input type="text" name="wifi_name" class="form-control" maxlength="100"
                               value="<?php echo e($current['wifi_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Wi-Fi Password</label>
                        <input type="text" name="wifi_password" class="form-control" maxlength="100"
                               value="<?php echo e($current['wifi_password'] ?? ''); ?>">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card-form">
            <div class="section-title">Receipt Preview</div>
            <div class="text-muted small mb-3">How this information will appear on printed receipts.</div>

            <div class="p-3 rounded" style="background:#f4f6f9; font-family: Georgia, 'Times New Roman', serif;">
                <div class="fw-bold fs-5"><?php echo e($current['resort_name'] ?: 'Resort Name'); ?></div>
                <?php if (!empty($current['resort_address'])): ?>
                    <div class="small text-muted mt-1"><?php echo nl2br(e($current['resort_address'])); ?></div>
                <?php endif; ?>
                <?php if (!empty($current['resort_phone']) || !empty($current['resort_email'])): ?>
                    <div class="small text-muted mt-1">
                        <?php echo e($current['resort_phone'] ?? ''); ?>
                        <?php if (!empty($current['resort_phone']) && !empty($current['resort_email'])): ?> &middot; <?php endif; ?>
                        <?php echo e($current['resort_email'] ?? ''); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($current['resort_website'])): ?>
                    <div class="small text-muted"><?php echo e($current['resort_website']); ?></div>
                <?php endif; ?>

                <?php if (!empty($current['wifi_name'])): ?>
                <hr class="my-2">
                <div class="small">
                    <strong>Guest Wi-Fi:</strong> <?php echo e($current['wifi_name']); ?>
                    <?php if (!empty($current['wifi_password'])): ?>
                        <br><strong>Password:</strong> <?php echo e($current['wifi_password']); ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>