jQuery(function ($) {

    function openModal() {
        $("#lrp-event-modal").fadeIn(200);
    }

    function closeModal() {
        $("#lrp-event-modal").fadeOut(200);
        $("#lrp-event-form")[0].reset();
        $("#lrp-event-id").val("");
    }

    // Add new event button
    $(document).on("click", ".lrp-add-event", function (e) {
        e.preventDefault();
        $("#lrp-event-id").val("");
        $("#lrp-nsid").val("");
        $("#lrp-event-name").val("");
        $("#lrp-is-active").prop("checked", true);
        openModal();
    });

    // Cancel modal
    $(document).on("click", ".lrp-cancel-event", function () {
        closeModal();
    });

    // Edit event
    $(document).on("click", ".lrp-edit-event", function () {
        let row = $(this).closest("tr");

        $("#lrp-event-id").val(row.data("id"));
        $("#lrp-nsid").val(row.find(".lrp-nsid").text().trim());
        $("#lrp-event-name").val(row.find(".lrp-event-name").text().trim());

        $("#lrp-is-active").prop("checked", row.find(".lrp-toggle-active").is(":checked"));

        openModal();
    });

    // Save (Add / Edit)
    $(document).on("click", "#lrp-save-event", function () {

        let eventName = $("#lrp-event-name").val().trim();
        if (!eventName) {
            alert("Event name is required.");
            return;
        }

        let formData = {
            action: lrpEvents.ajax_action_save,
            id: $("#lrp-event-id").val(),
            NSID: $("#lrp-nsid").val(),
            event_name: eventName,
            is_active: $("#lrp-is-active").is(":checked") ? 1 : 0
        };

        // VERY IMPORTANT: send correct nonce field name
        formData[lrpEvents.nonce_field_name] = lrpEvents.nonce;

        $.post(lrpEvents.ajax_url, formData, function (response) {

            if (!response || !response.success) {
                alert(response?.data?.message || "Request failed");
                return;
            }

            location.reload(); // reload event list
        });

    });

    // Delete event
    $(document).on("click", ".lrp-delete-event", function () {
        if (!confirm(lrpEvents.confirm_delete)) return;

        let formData = {
            action: lrpEvents.ajax_action_delete,
            id: $(this).data("id")
        };

        formData[lrpEvents.nonce_field_name] = lrpEvents.nonce;

        $.post(lrpEvents.ajax_url, formData, function (response) {
            if (!response || !response.success) {
                alert(response?.data?.message || "Delete failed");
                return;
            }
            location.reload();
        });
    });

    // Toggle active switch
    $(document).on("change", ".lrp-toggle-active", function () {

        let formData = {
            action: lrpEvents.ajax_action_toggle,
            id: $(this).data("id"),
            active: $(this).is(":checked") ? 1 : 0
        };

        formData[lrpEvents.nonce_field_name] = lrpEvents.nonce;

        $.post(lrpEvents.ajax_url, formData, function (response) {
            if (!response || !response.success) {
                alert(response?.data?.message || "Update failed");
            }
        });

    });

});