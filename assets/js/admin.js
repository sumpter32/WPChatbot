jQuery(document).ready(function($) {
    
    // Test connection functionality
    $('#owui-test-connection').click(function() {
        var btn = $(this);
        var originalText = btn.text();
        btn.prop('disabled', true).text('Testing...');
        
        $.post(owui_admin_ajax.ajax_url, {
            action: 'owui_test_connection',
            nonce: owui_admin_ajax.nonce
        }, function(response) {
            btn.prop('disabled', false).text(originalText);
            
            if (response.success) {
                showNotice('success', '✅ ' + response.data);
            } else {
                showNotice('error', '❌ ' + response.data);
            }
        }).fail(function() {
            btn.prop('disabled', false).text(originalText);
            showNotice('error', '❌ Connection test failed');
        });
    });
    
    // Load models functionality
    $('#load-models-btn').click(function() {
        var btn = $(this);
        btn.prop('disabled', true).text('Loading...');
        
        $.post(owui_admin_ajax.ajax_url, {
            action: 'owui_get_models',
            nonce: owui_admin_ajax.nonce
        }, function(response) {
            btn.prop('disabled', false).text('Refresh Models');
            
            if (response.success) {
                var select = $('#chatbot_model');
                var currentValue = select.val();
                select.empty().append('<option value="">Select a model...</option>');
                
                response.data.forEach(function(model) {
                    select.append('<option value="' + model + '">' + model + '</option>');
                });
                
                if (currentValue) {
                    select.val(currentValue);
                }
                
                showNotice('success', 'Models loaded successfully!');
            } else {
                showNotice('error', 'Error loading models: ' + response.data);
            }
        }).fail(function() {
            btn.prop('disabled', false).text('Refresh Models');
            showNotice('error', 'Failed to load models');
        });
    });
    
    // Export functionality
    $('#owui-export-csv, #export-history').click(function() {
        window.location.href = owui_admin_ajax.ajax_url + '?action=owui_export_csv&nonce=' + owui_admin_ajax.nonce;
    });
    
    // Export contacts
    $('#export-contacts').click(function() {
        window.location.href = owui_admin_ajax.ajax_url + '?action=owui_export_contacts&nonce=' + owui_admin_ajax.nonce;
    });
    
    // Clear history functionality
    $('#clear-history').click(function() {
        if (!confirm('Are you sure you want to clear all chat history? This cannot be undone.')) {
            return;
        }
        
        var btn = $(this);
        btn.prop('disabled', true).text('Clearing...');
        
        $.post(owui_admin_ajax.ajax_url, {
            action: 'owui_clear_history',
            nonce: owui_admin_ajax.nonce
        }, function(response) {
            btn.prop('disabled', false).text('Clear History');
            
            if (response.success) {
                location.reload();
            } else {
                showNotice('error', 'Error clearing history: ' + response.data);
            }
        });
    });
    
    // Auto-refresh stats every 30 seconds on dashboard
    if ($('.owui-dashboard-stats').length) {
        setInterval(function() {
            $.post(owui_admin_ajax.ajax_url, {
                action: 'owui_get_stats',
                nonce: owui_admin_ajax.nonce
            }, function(response) {
                if (response.success) {
                    $('.stat-box').each(function(i) {
                        $(this).find('.stat-number').text(response.data[i]);
                    });
                }
            });
        }, 30000);
    }
    
    // Utility function to show notices
    function showNotice(type, message) {
        var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
        
        $('.wrap h1').after(notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            notice.fadeOut();
        }, 5000);
    }
    
    // Form validation
    $('form').submit(function() {
        var requiredFields = $(this).find('[required]');
        var isValid = true;
        
        requiredFields.each(function() {
            if (!$(this).val().trim()) {
                $(this).css('border-color', '#d63638');
                isValid = false;
            } else {
                $(this).css('border-color', '');
            }
        });
        
        if (!isValid) {
            showNotice('error', 'Please fill in all required fields.');
            return false;
        }
    });
});
