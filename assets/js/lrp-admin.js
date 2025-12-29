jQuery(document).ready(function($) {
    // Customer checkbox handling
    $('#netsuite_customer').on('change', function() {
        if ($(this).is(':checked')) {
            $('#loyalty_customer').prop('checked', false);
            $('#netsuite_form').show();
            $('#loyalty_form').hide();
            $('#netsuite_form input[name="customer_type"]').val('netsuite');
            $('#submit-section').show();
        } else {
            $('#netsuite_form').hide();
            $('#netsuite_form input[name="customer_type"]').val('');
            $('#submit-section').hide();
        }
    });

    $('#loyalty_customer').on('change', function() {
        if ($(this).is(':checked')) {
            $('#netsuite_customer').prop('checked', false);
            $('#loyalty_form').show();
            $('#netsuite_form').hide();
            $('#loyalty_form input[name="customer_type"]').val('loyalty');
            $('#submit-section').show();
        } else {
            $('#loyalty_form').hide();
            $('#loyalty_form input[name="customer_type"]').val('');
            $('#submit-section').hide();
        }
    });

    // Tab navigation
    $('.lrp-tab').on('click', function(e) {
        e.preventDefault();
        var $tab = $(this);
        var target = $tab.attr('href');
        if ($(target).length) {
            $('.lrp-tab').removeClass('active');
            $('.lrp-tab-pane').removeClass('active');
            $tab.addClass('active');
            $(target).addClass('active');
        }
    });

    // Form submission validation
    $('#netsuite_form, #loyalty_form').on('submit', function(e) {
        var $form = $(this);
        var customerType = $form.find('input[name="customer_type"]').val();
        var errors = [];

        if (!customerType) {
            errors.push('Please select a customer type before submitting.');
        }

        if (customerType === 'netsuite') {
            if (!$('#license_key_netsuite').val()) errors.push('License Key is required.');
            if (!$('#product_code').val()) errors.push('Product Code is required.');
            if (!$('#account_id').val()) errors.push('Account ID is required.');
            if (!$('#license_url').val()) errors.push('License URL is required.');
        } else if (customerType === 'loyalty') {
            if (!$('#license_key_loyalty').val()) errors.push('License Key is required.');
            if (!$('#username').val()) errors.push('Username is required.');
            if (!$('#password').val()) errors.push('Password is required.');
        }

        if (errors.length > 0) {
            e.preventDefault();
            var $notice = $('<div class="notice notice-error is-dismissible"><p>' + errors.join(' ') + '</p></div>');
            $('.lrp-admin-container').prepend($notice);
            setTimeout(function() { $notice.fadeOut(400, function() { $(this).remove(); }); }, 5000);
        }
    });

    // Handle loyalty points input toggle
    $('#lrp_points').on('change', function() {
        var $container = $(this).closest('.options_group');
        if ($(this).val() !== '' && parseInt($(this).val()) >= 0) {
            $container.show();
        } else {
            $container.hide();
        }
    });

    // Handle edit button click for loyalty tiers
    $('.edit-option').on('click', function() {
        var $td = $(this).closest('td');
        var $status = $td.find('.active-status');
        var currentText = $status.text().trim();
        var newText = currentText === 'No' ? 'Yes' : 'No';
        var newValue = newText === 'Yes' ? 1 : 0;
        $status.text(newText);
        // Update hidden input field
        var tier = $(this).data('tier');
        $('#' + tier + '_active').val(newValue);
    });

    // Toggle visibility for sensitive fields
    $('.toggle-visibility').on('click', function() {
        var field = $(this).data('field');
        var $span = $('#' + field);
        var isHidden = $span.data('hidden');
        if (isHidden) {
            $span.text($span.data('value'));
            $span.data('hidden', false);
            $(this).find('i').removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            $span.text('****');
            $span.data('hidden', true);
            $(this).find('i').removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });
    
    // Store the initial points value from the database
    var initialPoints = $('#_lrp_loyalty_points').val();

    $('#_lrp_eligible_loyalty').on('change', function() {
        if ($(this).is(':checked')) {
            $('#lrp_loyalty_points_container').show();
                // Restore the initial points value if the input is empty
                if (!$('#_lrp_loyalty_points').val()) {
                    $('#_lrp_loyalty_points').val(initialPoints);
                    }
                } else {
                    $('#lrp_loyalty_points_container').hide();
                    // Do not clear the input value to preserve it
                    }
                });

            // Update initialPoints when the user changes the input
    $('#_lrp_loyalty_points').on('change', function() {
        if ($(this).val() && parseInt($(this).val()) >= 0) {
            initialPoints = $(this).val();
         }
       });
       
});