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

// ========================================
// Bulk Operations Functions
// ========================================

/**
 * Get all selected checkbox IDs
 */
function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.bulk-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

/**
 * Toggle bulk actions toolbar
 */
function toggleBulkActions() {
    const selectedIds = getSelectedIds();
    const toolbar = document.getElementById('bulkActionsToolbar');

    if (toolbar) {
        if (selectedIds.length > 0) {
            toolbar.style.display = 'flex';
            document.getElementById('selectedCount').textContent = selectedIds.length;
        } else {
            toolbar.style.display = 'none';
        }
    }
}

/**
 * Select/deselect all checkboxes
 */
function toggleSelectAll(checkbox) {
    const bulkCheckboxes = document.querySelectorAll('.bulk-checkbox');
    bulkCheckboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    toggleBulkActions();
}

/**
 * Bulk delete records
 */
function bulkDelete(entityType, baseUrl) {
    const selectedIds = getSelectedIds();

    if (selectedIds.length === 0) {
        alert('Please select items to delete');
        return;
    }

    if (!confirm(`Are you sure you want to delete ${selectedIds.length} item(s)?`)) {
        return;
    }

    ajax(baseUrl + '/bulkoperations/delete', 'POST', {
        entity_type: entityType,
        ids: selectedIds
    }, function(err, response) {
        if (err) {
            alert('Error: ' + err.message);
        } else if (response.success) {
            alert(`Successfully deleted ${response.deleted} item(s)`);
            window.location.reload();
        } else {
            alert('Error: ' + (response.error || 'Unknown error'));
        }
    });
}

/**
 * Bulk update status
 */
function bulkUpdateStatus(entityType, status, baseUrl) {
    const selectedIds = getSelectedIds();

    if (selectedIds.length === 0) {
        alert('Please select items to update');
        return;
    }

    ajax(baseUrl + '/bulkoperations/updateStatus', 'POST', {
        entity_type: entityType,
        ids: selectedIds,
        status: status
    }, function(err, response) {
        if (err) {
            alert('Error: ' + err.message);
        } else if (response.success) {
            alert(`Successfully updated ${response.updated} item(s)`);
            window.location.reload();
        } else {
            alert('Error: ' + (response.error || 'Unknown error'));
        }
    });
}

/**
 * Bulk assign to agent
 */
function bulkAssign(entityType, userId, baseUrl) {
    const selectedIds = getSelectedIds();

    if (selectedIds.length === 0) {
        alert('Please select items to assign');
        return;
    }

    if (!userId) {
        alert('Please select an agent');
        return;
    }

    ajax(baseUrl + '/bulkoperations/assign', 'POST', {
        entity_type: entityType,
        ids: selectedIds,
        user_id: userId
    }, function(err, response) {
        if (err) {
            alert('Error: ' + err.message);
        } else if (response.success) {
            alert(`Successfully assigned ${response.updated} item(s)`);
            window.location.reload();
        } else {
            alert('Error: ' + (response.error || 'Unknown error'));
        }
    });
}

/**
 * Show bulk email modal
 */
function showBulkEmailModal(entityType) {
    const selectedIds = getSelectedIds();

    if (selectedIds.length === 0) {
        alert('Please select recipients');
        return;
    }

    document.getElementById('bulkEmailModal').style.display = 'flex';
    document.getElementById('bulkEmailEntityType').value = entityType;
    document.getElementById('bulkEmailIds').value = JSON.stringify(selectedIds);
}

/**
 * Close bulk email modal
 */
function closeBulkEmailModal() {
    document.getElementById('bulkEmailModal').style.display = 'none';
}

/**
 * Send bulk email
 */
function sendBulkEmail(baseUrl) {
    const subject = document.getElementById('bulkEmailSubject').value;
    const message = document.getElementById('bulkEmailMessage').value;
    const entityType = document.getElementById('bulkEmailEntityType').value;
    const ids = JSON.parse(document.getElementById('bulkEmailIds').value);

    if (!subject || !message) {
        alert('Please fill in all fields');
        return;
    }

    ajax(baseUrl + '/bulkoperations/email', 'POST', {
        entity_type: entityType,
        ids: ids,
        subject: subject,
        message: message
    }, function(err, response) {
        if (err) {
            alert('Error: ' + err.message);
        } else if (response.success) {
            alert(`Successfully sent email to ${response.sent} recipient(s)`);
            closeBulkEmailModal();
        } else {
            alert('Error: ' + (response.error || 'Unknown error'));
        }
    });
}

/**
 * Bulk convert leads to clients
 */
function bulkConvertLeads(baseUrl) {
    const selectedIds = getSelectedIds();

    if (selectedIds.length === 0) {
        alert('Please select leads to convert');
        return;
    }

    if (!confirm(`Convert ${selectedIds.length} lead(s) to clients?`)) {
        return;
    }

    ajax(baseUrl + '/bulkoperations/convertLeads', 'POST', {
        ids: selectedIds
    }, function(err, response) {
        if (err) {
            alert('Error: ' + err.message);
        } else if (response.success) {
            alert(`Successfully converted ${response.converted} lead(s) to clients`);
            window.location.reload();
        } else {
            alert('Error: ' + (response.error || 'Unknown error'));
        }
    });
}

/**
 * Bulk export selected records
 */
function bulkExport(entityType) {
    const selectedIds = getSelectedIds();

    if (selectedIds.length === 0) {
        alert('Please select items to export');
        return;
    }

    // Create hidden form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = window.BASE_URL + '/bulkoperations/export';

    const idsInput = document.createElement('input');
    idsInput.type = 'hidden';
    idsInput.name = 'ids[]';
    selectedIds.forEach(id => {
        const input = idsInput.cloneNode();
        input.value = id;
        form.appendChild(input);
    });

    const typeInput = document.createElement('input');
    typeInput.type = 'hidden';
    typeInput.name = 'entity_type';
    typeInput.value = entityType;
    form.appendChild(typeInput);

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}
