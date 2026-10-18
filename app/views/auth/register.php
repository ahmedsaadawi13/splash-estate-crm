<?php
// FILE: /app/views/auth/register.php
require APP_PATH . '/views/layouts/header.php';
$errors = isset($errors) && is_array($errors) ? $errors : array();
$formData = isset($formData) && is_array($formData) ? $formData : array();
?>

<div class="auth-container">
    <div class="auth-box">
        <h1>Create Your Account</h1>

        <?php if (isset($errors['general'])): ?>
            <div class="alert alert-error">
                <?php echo e($errors['general']); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo e(url('auth/register')); ?>">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="company_name">Company Name *</label>
                <input type="text" id="company_name" name="company_name" class="form-control"
                       value="<?php echo isset($formData['company_name']) ? e($formData['company_name']) : ''; ?>" required>
                <?php if (isset($errors['company_name'])): ?>
                    <span class="error"><?php echo e($errors['company_name']); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="first_name">First Name *</label>
                <input type="text" id="first_name" name="first_name" class="form-control"
                       value="<?php echo isset($formData['first_name']) ? e($formData['first_name']) : ''; ?>" required>
                <?php if (isset($errors['first_name'])): ?>
                    <span class="error"><?php echo e($errors['first_name']); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="last_name">Last Name *</label>
                <input type="text" id="last_name" name="last_name" class="form-control"
                       value="<?php echo isset($formData['last_name']) ? e($formData['last_name']) : ''; ?>" required>
                <?php if (isset($errors['last_name'])): ?>
                    <span class="error"><?php echo e($errors['last_name']); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" class="form-control"
                       value="<?php echo isset($formData['email']) ? e($formData['email']) : ''; ?>" required>
                <?php if (isset($errors['email'])): ?>
                    <span class="error"><?php echo e($errors['email']); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="tel" id="phone" name="phone" class="form-control"
                       value="<?php echo isset($formData['phone']) ? e($formData['phone']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="password">Password *</label>
                <input type="password" id="password" name="password" class="form-control" minlength="8" maxlength="72" autocomplete="new-password" required>
                <div class="form-help">At least 8 characters.</div>
                <?php if (isset($errors['password'])): ?>
                    <span class="error"><?php echo e($errors['password']); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password *</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="8" maxlength="72" autocomplete="new-password" required>
                <?php if (isset($errors['confirm_password'])): ?>
                    <span class="error"><?php echo e($errors['confirm_password']); ?></span>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Create Account</button>
        </form>

        <div class="auth-links">
            <a href="<?php echo e(url('auth/login')); ?>">Already have an account? Login</a>
        </div>
    </div>
</div>

<?php require APP_PATH . '/views/layouts/footer.php'; ?>
