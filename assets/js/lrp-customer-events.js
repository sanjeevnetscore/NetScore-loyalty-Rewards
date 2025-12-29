/*!
 * lrp-customer-events.js
 * Modal + AJAX for customer events (self-contained)
 */
jQuery(function($){

    // --- ensure modal markup exists (create once) ---
    if ($('#lrp-customer-events-modal').length === 0) {
        $('body').append(
            '<div id="lrp-customer-events-modal" aria-hidden="true">' +
                '<div class="lrp-modal-box">' +
                    '<a href="#" class="lrp-modal-close" title="Close">✕</a>' +
                    '<div class="lrp-modal-content"><p class="lrp-loading">Loading…</p></div>' +
                '</div>' +
            '</div>'
        );
    }

    // --- helper functions (must be defined BEFORE handlers) ---
    function lrpOpenModal(html) {
        var $modal = $('#lrp-customer-events-modal');
        $modal.find('.lrp-modal-content').html(html);
        $modal.fadeIn(150).attr('aria-hidden','false');
        // close on backdrop click
        $modal.off('click.modalClose').on('click.modalClose', function(e){
            if ( e.target === this ) { lrpCloseModal(); }
        });
    }

    function lrpCloseModal() {
        $('#lrp-customer-events-modal').fadeOut(120).attr('aria-hidden','true');
    }

    // optional helper to perform AJAX for a given page
    function fetchEvents(customer_id, page) {
        page = page || 1;
        // show loading UI
        lrpOpenModal('<p class="lrp-loading">Loading…</p>');

        var ajaxUrl = (window.lrp_admin_params && lrp_admin_params.ajax_url) ? lrp_admin_params.ajax_url : '/wp-admin/admin-ajax.php';
        var nonce = (window.lrp_admin_params && lrp_admin_params.nonce) ? lrp_admin_params.nonce : '';

        return $.ajax({
            url: ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'lrp_get_customer_events',
                security: nonce,
                customer_id: customer_id,
                page: page
            }
        }).done(function(resp){
            if ( resp && resp.success && resp.data && resp.data.html ) {
                lrpOpenModal(resp.data.html);
            } else {
                var msg = (resp && resp.data) ? resp.data : 'Failed to load events';
                lrpOpenModal('<div class="notice notice-error"><p>' + $('<div>').text(msg).html() + '</p></div>');
            }
        }).fail(function(jqXHR, textStatus){
            var body = jqXHR && jqXHR.responseText ? jqXHR.responseText : textStatus;
            lrpOpenModal('<div class="notice notice-error"><p>AJAX error while fetching events.</p><pre>' + $('<div>').text(body).html() + '</pre></div>');
        });
    }

    // --- handlers (call helpers) ---

    // close click
    $(document).on('click', '.lrp-modal-close', function(e){
        e.preventDefault();
        lrpCloseModal();
    });

    // eye icon click (open modal and load page 1)
    $(document).on('click', '.lrp-open-events', function(e){
        e.preventDefault();
        var $btn = $(this);
        var customer_id = $btn.data('customer-id');
        if ( ! customer_id ) return;
        fetchEvents(customer_id, 1);
    });

    // pagination links inside modal (delegated)
    $(document).on('click', '.lrp-events-page', function(e){
        e.preventDefault();
        var $link = $(this);
        var cid = $link.data('customer-id');
        var page = parseInt( $link.data('page'), 10 ) || 1;
        if ( ! cid ) return;
        fetchEvents(cid, page).done(function(){
            // scroll modal content to top after load
            $('#lrp-customer-events-modal .lrp-modal-content').scrollTop(0);
        });
    });

});