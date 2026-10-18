<?php
// FILE: /app/views/auth/reset.php
require APP_PATH . '/views/layouts/header.php';
$errors = isset($errors) && is_array($errors) ? $errors : array();
?>

<div class="auth-container">
    <div class="auth-box">
        <h1>Reset Password</h1>

        <?php if (!empty($invalid)): ?>
            <div class="alert alert-error">This password reset link is invalid or has expired.</div>
            <p><a href="<?php echo e(url('auth/forgot')); ?>" class="btn btn-primary btn-block">Request a new link</a></p>
        <?php else: ?>
            <form method="POST" action="<?php echo e(url('auth/reset/' . $token)); ?>">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" class="form-control" minlength="8" maxlength="72"
                           autocomplete="new-password" required autofocus>
                    <div class="form-help">At least 8 characters.</div>
                    <?php if (isset($errors['password'])): ?>
                        <span class="error"><?php echo e($errors['password']); ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="8" maxlength="72"
                           autocomplete="new-password" required>
                    <?php if (isset($errors['confirm_password'])): ?>
                        <span class="error"><?php echo e($errors['confirm_password']); ?></span>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Set New Password</button>
            </form>
        <?php endif; ?>

        <div class="auth-links">
            <a href="<?php echo e(url('auth/login')); ?>">Back to Login</a>
        </div>
    </div>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>
