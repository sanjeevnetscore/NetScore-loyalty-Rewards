jQuery(function($) {
    // Refer and Earn (unchanged)
    console.log("checkoutloaded")
      // Helper: returns true if email contains any disallowed char
function emailHasInvalidChars(email) {
    if (!email) return true;
    // disallow + - * / , $ % & ( ) ! #
    var re = /[+\-\*\/,\$%&()!#]/;
    return re.test(email);
}
$('#share_earn').on('click', function(e){
    e.preventDefault();
    var $btn = $(this);
    var email = $('#refer_email').val().trim();

    if (!email || email.indexOf('@') === -1) {
      $('#refer-message').text('Please enter a valid email address.').css('color','red').show();
      return;
    }

    if (emailHasInvalidChars(email)) {
      $('#refer-message').text('Enter valid email address').css('color','red').show();
      return;
    }

    var data = {
      action: 'lrp_refer_friend',
      refer_email: email,
      security: $btn.data('security') || (typeof lrp_checkout_params !== 'undefined' ? lrp_checkout_params.refer_nonce : '')
    };
    $btn.prop('disabled', true).text('Sending...');
    $.post(lrp_checkout_params.ajax_url, data, function(resp){
      $btn.prop('disabled', false).text('Share & Earn');
      if (!resp) {
        $('#refer-message').text('Unexpected response from server.').css('color','red').show();
        return;
      }
      if (resp.success) {
        var msg = resp.data && resp.data.message ? resp.data.message : '';
        if (msg) {
          var color = (resp.data.status === 'email_sent') ? 'green' : '#b8860b';
          $('#refer-message').text(msg).css('color', color).show();
        } else {
          $('#refer-message').hide().text('');
        }
      } else {
        var err = (resp.data && resp.data.message) ? resp.data.message : 'An error occurred';
        $('#refer-message').text(err).css('color','red').show();
      }
    }).fail(function(xhr){
      $btn.prop('disabled', false).text('Share & Earn');
      var msg = 'AJAX request failed';
      if (xhr && xhr.responseText) msg += ': ' + xhr.responseText;
      $('#refer-message').text(msg).css('color','red').show();
    });
});


    // --- Gift card generation: add same email validation (UX) ---
    function showAlertOrMessage(text) {
        // prefer consistent UX — use #refer-message if visible on page, otherwise alert
        if ($('#refer-message').length) {
            $('#refer-message').text(text).css('color', 'red').show();
        } else {
            alert(text);
        }
    }

    function clearAlertOrMessage() {
        if ($('#refer-message').length) $('#refer-message').hide().text('');
    }


    function updateRedeemAmount(points, pointValue, loyaltyValue) {
        points = parseFloat(points) || 0;
        pointValue = parseFloat(pointValue) || 1;
        loyaltyValue = parseFloat(loyaltyValue) || 1;
        let amt = 0;

        if (points > 0) {
            // Simple conversion using configured values (no tier logic)
            amt = (pointValue !== 0) ? (points / pointValue) * loyaltyValue : 0;
        }

        $('#redeemAmountDisplay').text(amt ? 'Gift card value will be: $' + amt.toFixed(2) : '');
    }

    $('#points_to_redeem').on('input keypress', function(e) {
   console.log('LRP params');
        if (e.type === 'keypress') {
            if (e.which === 46 || (e.which < 48 || e.which > 57)) {
                e.preventDefault();
            }
        } else if (e.type === 'input') {
            var value = $(this).val();
            var cleanedValue = value.replace(/[^0-9]/g, '');
            var points = parseInt(cleanedValue) || 0;
			console.log('LRP params',availablePoints);
            if (points > availablePoints) {
                cleanedValue = availablePoints.toString();
                points = availablePoints;
            }

            if (value !== cleanedValue) {
                $(this).val(cleanedValue);
                showNotice('Only whole numbers up to ' + availablePoints + ' points are allowed.', 'error');
            }

            updateRedeemAmount(points, lrp_checkout_params.point_value, lrp_checkout_params.loyalty_value);
        }
    });


   $('#generate_gift_card').on('click', function () {

    var points = $('#points_to_redeem').val();
    var email  = $('#receiver_email').val().trim();

    if (!points || points <= 0) {
        alert('Enter valid points');
        return;
    }
    if (!email || email.indexOf('@') === -1) {
        alert('Enter valid email');
        return;
    }
    if (emailHasInvalidChars(email)) {
        alert('Enter valid email address');
        return;
    }

    // 🔒 Disable button + show loader
    $('#generate_gift_card').prop('disabled', true);
    $('#lrp-fullscreen-loader').fadeIn(200);

    $.post(lrp_checkout_params.ajax_url, {
        action: 'lrp_generate_gift_card',
        security: lrp_checkout_params.nonce,
        points: points,
        email: email
    })
    .done(function (res) {

        if (res && res.success && res.data && res.data.message) {
            alert(res.data.message);
            location.reload(); // success only
        } 
        else if (res && res.data && res.data.message) {
            alert(res.data.message);
        } 
        else {
            alert('Unexpected response from server.');
        }

    })
    .fail(function (xhr) {
        var msg = 'Request failed';
        if (xhr && xhr.responseText) {
            msg += ': ' + xhr.responseText;
        }
        alert(msg);
    })
    .always(function () {
        // ✅ ALWAYS hide loader + re-enable button
        $('#lrp-fullscreen-loader').fadeOut(200);
        $('#generate_gift_card').prop('disabled', false);
    });
	});

    // Update Profile (unchanged)
    $('#update-profile-form').on('submit', function(e) {
        e.preventDefault();
        var btn = $('#save-profile-btn');
        btn.prop('disabled', true).text('Saving...');
        var formData = $(this).serialize() + '&action=lrp_update_profile&save_profile=1';
        $.ajax({
            url: lrp_checkout_params.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    var dob = response.data.dob;
                    var anniversary = response.data.anniversary;
                    var tyre_type = response.data.tyre_type;
                    var points_added = response.data.points_added || 0;
                    var $display = $('#lrp-profile-display');
                    var displayHtml = '<div class="lrp-profile-info">';
                    if (dob) {
                        displayHtml += '<p><strong>Date of Birth:</strong> <span id="display-birthday">' + dob + '</span></p>';
                    }
                    if (anniversary) {
                        displayHtml += '<p><strong>Anniversary:</strong> <span id="display-anniversary">' + anniversary + '</span></p>';
                    }
                    if (tyre_type) {
                        displayHtml += '<p><strong>Tyre Type:</strong> ' + tyre_type + ' <small>(Determined automatically by your Loyalty Tier)</small></p>';
                    }
                    displayHtml += '</div>';
                    $display.html(displayHtml);
                    $('#update-profile-form').hide();
                    var message = response.data.message;
                    if (points_added > 0) {
                        message += ' You earned ' + points_added + ' points!';
                    }
                    $('.woocommerce-message').text(message).show();
                    setTimeout(function() {
                        $('.woocommerce-message').fadeOut();
                    }, 5000);
                } else {
                    $('.woocommerce-message').text(response.data.message || 'Failed to update profile.').show();
                    setTimeout(function() {
                        $('.woocommerce-message').fadeOut();
                    }, 3000);
                }
            },
            error: function(xhr, status, error) {
                $('.woocommerce-message').text('An error occurred: ' + error).show();
                setTimeout(function() {
                    $('.woocommerce-message').fadeOut();
                }, 3000);
            },
            complete: function() {
                btn.prop('disabled', false).text('Save Changes');
            }
        });
    });

    // Edit Profile Button
    $(document).on('click', '#edit-profile-btn', function() {
        $('#update-profile-form').show();
        $('.lrp-profile-info').hide();
    });

    // Loyalty Points
    const pointsInput = $('#lrp_points');
    const applyButton = $('#apply_points');
    const removeButton = $('#remove_points');
    const savingsDisplay = $('.lrp-info');
    const useAllCheckbox = $('#lrp_use_all');
    const availablePoints = parseInt(lrp_checkout_params.available_points) || 0;
    const maxRedeemablePoints = parseInt(lrp_checkout_params.max_redeemable_points) || 0;
    const pointValue = lrp_checkout_params.point_value; // Now 10
    const loyaltyValue = lrp_checkout_params.loyalty_value; // Now 2
    const tier = lrp_checkout_params.tier;
    const pointsPerDollar = lrp_checkout_params.points_per_dollar;


    function showNotice(message, type) {
        if (typeof wc_add_notice === 'function') {
            wc_add_notice(message, type);
        } else {
            const noticeClass = type === 'success' ? 'woocommerce-message' : 'woocommerce-error';
            const $notice = $(`<div class="${noticeClass}">${message}</div>`);
            $('.woocommerce-notices-wrapper').html($notice);
            setTimeout(() => $notice.fadeOut(), 5000);
        }
    }

        function updateDisplay(points) {
        points = parseInt(points) || 0;
        const pv = parseFloat(pointValue) || 1;
        const lv = parseFloat(loyaltyValue) || 1;
        const saving = pv !== 0 ? ((points / pv) * lv).toFixed(2) : '0.00';
        savingsDisplay.text(`You will be spending ${points} points (SAVING $${saving})`);
        applyButton.prop('disabled', points <= 0 || points > maxRedeemablePoints);
        removeButton.css('display', points > 0 ? 'inline-block' : 'none');

    }


       pointsInput.on('input', function() {
        let points = parseInt($(this).val()) || 0;
        if (points > maxRedeemablePoints) {
            points = maxRedeemablePoints;
            $(this).val(maxRedeemablePoints);
        }
        updateDisplay(points);
    });
     
     pointsInput.on('input', function(e) {
        var value = $(this).val();
        // Remove any non-numeric characters (e.g., decimal points)
        value = value.replace(/[^0-9]/g, '');
        $(this).val(value); // Update the input with the cleaned value
    });

    useAllCheckbox.on('change', function() {
        if (this.checked) {
            pointsInput.val(maxRedeemablePoints);
            updateDisplay(maxRedeemablePoints);
        } else {
            pointsInput.val(0);
            updateDisplay(0);
        }
    });

    applyButton.on('click', function(e) {
        e.preventDefault();
        const points = parseInt(pointsInput.val()) || 0;
        if (points <= 0 || points > maxRedeemablePoints) {
            showNotice('Please enter a valid number of points.', 'error');
            return;
        }
        applyButton.prop('disabled', true).text('Applying...');
        $.ajax({
            url: lrp_checkout_params.ajax_url,
            type: 'POST',
            data: {
                action: 'lrp_apply_points',
                nonce: lrp_checkout_params.nonce,
                points: points
            },
            success: function(response) {
                if (response.success) {
                    updateDisplay(points);
                    useAllCheckbox.prop('checked', points == maxRedeemablePoints);
                    if (response.data.fragments) {
                        $.each(response.data.fragments, function(key, value) {
                            if ($(key).length) {
                                $(key).replaceWith(value);
                            } else if (key === '.woocommerce-checkout-review-order-table') {
                                $('.woocommerce-checkout-review-order').html(value);
                            }
                        });
                    }
                    $(document.body).trigger('update_checkout');
                    showNotice(response.data.message, 'success');
                } else {
                    showNotice(response.data.message || 'Failed to apply points.', 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotice('An error occurred while applying points: ' + error, 'error');
            },
            complete: function() {
                applyButton.prop('disabled', false).text('Apply');
            }
        });
    });

    removeButton.on('click', function() {
        $.ajax({
            url: lrp_checkout_params.ajax_url,
            type: 'POST',
            data: {
                action: 'lrp_remove_points',
                nonce: lrp_checkout_params.nonce
            },
            success: function(response) {
                if (response.success) {
                    pointsInput.val(0);
                    useAllCheckbox.prop('checked', false);
                    updateDisplay(0);
                    if (response.data.fragments) {
                        $.each(response.data.fragments, function(key, value) {
                            if ($(key).length) {
                                $(key).replaceWith(value);
                            } else if (key === '.woocommerce-checkout-review-order-table') {
                                $('.woocommerce-checkout-review-order').html(value);
                            }
                        });
                    }
                    if (response.data.updated_points !== undefined) {
                        $('.lrp-points-balance').text(response.data.updated_points);
                    }
                    $(document.body).trigger('update_checkout');
                    showNotice(response.data.message, 'success');
                } else {
                    showNotice(response.data.message || 'Failed to remove points.', 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotice('An error occurred while removing points: ' + error, 'error');
            }
        });
    });

    
$('.lrp-share-btn').on('click', function(e) {
    e.preventDefault();
    var $btn = $(this);
    var type = $btn.data('type');
    var points = $btn.data('points');
    var url = $btn.data('url');
    var title = $btn.data('title');
    $.ajax({
        url: lrp_checkout_params.ajax_url,
        type: 'POST',
        data: {
            action: 'lrp_share_social',
            type: type,
            points: points,
            url: url, // Pass the URL to the server
            title: title, // Pass the title to the server
            nonce: lrp_checkout_params.share_nonce
        },
        success: function(response) {
            if (response.success && response.data.redirect_url) {
                if (type === 'facebook') {
                    window.open(response.data.redirect_url, '_blank', 'width=600,height=400');
                } else if (type === 'email') {
                    window.location.href = response.data.redirect_url;
                }
            } else {
                alert(response.data.message || 'An error occurred. Please try again.');
            }
        },
        error: function() {
            alert('An error occurred. Please try again.');
        }
    });
});
    
    // Initial display update
    updateDisplay(parseInt(pointsInput.val()) || 0);
});