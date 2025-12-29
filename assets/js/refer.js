jQuery(document).ready(function($){
  $('#share_earn').on('click', function(e){
  console.log("clicke in refer file")
    e.preventDefault();
    var $btn = $(this);
    var email = $('#refer_email').val();
    if (!email || email.indexOf('@') === -1) {
      $('#refer-message').text('Please enter a valid email address.').css('color','red').show();
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
        // Only show message when server provides a non-empty message.
        if (msg) {
          // choose color: success (green) for 'email_sent', warning (amber) for other non-empty
          var color = (resp.data.status === 'email_sent') ? 'green' : '#b8860b';
          $('#refer-message').text(msg).css('color', color).show();
        } else {
          // Silent: nothing shown on UI when message is empty
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
});