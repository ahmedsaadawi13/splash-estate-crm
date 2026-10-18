<?php
// FILE: /app/views/auth/forgot.php
require APP_PATH . '/views/layouts/header.php';
$messageType = isset($messageType) && in_array($messageType, array('info', 'error', 'success'), true) ? $messageType : 'info';
?>

<div class="auth-container">
    <div class="auth-box">
        <h1>Forgot Password</h1>
        <p class="text-muted">Enter your account email and we will send you a link to reset your password.</p>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo e(url('auth/forgot')); ?>">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" class="form-control" autocomplete="email" required
                       value="<?php echo e(isset($email) ? $email : ''); ?>">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
        </form>

        <div class="auth-links">
            <a href="<?php echo e(url('auth/login')); ?>">Back to Login</a>
        </div>
    </div>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>
