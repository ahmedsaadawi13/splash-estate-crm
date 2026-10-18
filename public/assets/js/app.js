// FILE: /public/assets/js/app.js
// SplashEstate CRM - Main JavaScript File

document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 300);
        }, 5000);
    });

    // Confirm delete actions
    const deleteButtons = document.querySelectorAll('.btn-delete');
    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item?')) {
                e.preventDefault();
            }
        });
    });

    // Form validation
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;

            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('error');
                } else {
                    field.classList.remove('error');
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    });

    // File upload handler
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(function(input) {
        input.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file size (5MB)
                if (file.size > 5242880) {
                    alert('File size must be less than 5MB');
                    e.target.value = '';
                    return;
                }

                // Validate file type
                const allowedTypes = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
                const ext = file.name.split('.').pop().toLowerCase();
                if (!allowedTypes.includes(ext)) {
                    alert('File type not allowed. Allowed types: ' + allowedTypes.join(', '));
                    e.target.value = '';
                    return;
                }

                // Display file name
                const label = input.nextElementSibling;
                if (label && label.classList.contains('file-label')) {
                    label.textContent = file.name;
                }
            }
        });
    });

    // Toggle mobile menu
    const menuToggle = document.querySelector('.menu-toggle');
    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            const menu = document.querySelector('.navbar-menu');
            menu.classList.toggle('active');
        });
    }

    // Auto-complete for search fields
    const searchInputs = document.querySelectorAll('input[data-autocomplete]');
    searchInputs.forEach(function(input) {
        let timeout;
        input.addEventListener('keyup', function(e) {
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                // Implement autocomplete logic here
                console.log('Searching for: ' + e.target.value);
            }, 300);
        });
    });

    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-tooltip]');
    tooltips.forEach(function(element) {
        element.addEventListener('mouseenter', function() {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = element.getAttribute('data-tooltip');
            document.body.appendChild(tooltip);

            const rect = element.getBoundingClientRect();
            tooltip.style.top = (rect.top - tooltip.offsetHeight - 5) + 'px';
            tooltip.style.left = (rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)) + 'px';
        });

        element.addEventListener('mouseleave', function() {
            const tooltip = document.querySelector('.tooltip');
            if (tooltip) {
                tooltip.remove();
            }
        });
    });

    // Dashboard charts placeholder
    // You can integrate Chart.js or other charting library here
    console.log('SplashEstate CRM loaded successfully');
});

// Helper function to make AJAX requests
function ajax(url, method, data, callback) {
    const xhr = new XMLHttpRequest();
    xhr.open(method, url, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    if (typeof CSRF_TOKEN !== 'undefined' && method.toUpperCase() !== 'GET') {
        xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
    }

    xhr.onload = function() {
        if (xhr.status >= 200 && xhr.status < 300) {
            const response = JSON.parse(xhr.responseText);
            callback(null, response);
        } else {
            callback(new Error('Request failed: ' + xhr.statusText), null);
        }
    };

    xhr.onerror = function() {
        callback(new Error('Request failed'), null);
    };

    if (data) {
        xhr.send(JSON.stringify(data));
    } else {
        xhr.send();
    }
}

// Format currency
function formatCurrency(amount) {
    return '$' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

// Format date
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
}

// Bulk operations live in app/views/partials/bulk_operations.php
