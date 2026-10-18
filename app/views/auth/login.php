<?php
// FILE: /app/views/auth/login.php
require APP_PATH . '/views/layouts/header.php';
$errors = isset($errors) && is_array($errors) ? $errors : array();
?>

<div class="auth-container">
    <div class="auth-box">
        <h1>Login to SplashEstate CRM</h1>

        <?php if (isset($errors['general'])): ?>
            <div class="alert alert-error"><?php echo e($errors['general']); ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo e(url('auth/login')); ?>">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" class="form-control" autocomplete="username" autofocus
                       value="<?php echo e(isset($email) ? $email : ''); ?>" required>
                <?php if (isset($errors['email'])): ?>
                    <span class="error"><?php echo e($errors['email']); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" required>
                <?php if (isset($errors['password'])): ?>
                    <span class="error"><?php echo e($errors['password']); ?></span>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>

        <div class="auth-links">
            <a href="<?php echo e(url('auth/register')); ?>">Create an account</a>
            <a href="<?php echo e(url('auth/forgot')); ?>">Forgot password?</a>
        </div>
    </div>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>
