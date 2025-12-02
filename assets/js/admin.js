(function($){
    function gatherSettings() {
        var obj = {};
        $('tr[data-plugin]').each(function(){
            var plugin = $(this).data('plugin');
            var complete = $(this).find('.toggle-complete').prop('checked') ? true : false;
            var adminOnly = $(this).find('.toggle-adminonly').prop('checked') ? true : false;
            obj[plugin] = { complete: complete, admin_only: adminOnly };
        });
        return obj;
    }

    $(function(){
        // Highlight rows based on current checkbox states
        function refreshRowHighlights() {
            $('tr[data-plugin]').each(function(){
                var $tr = $(this);
                var complete = $tr.find('.toggle-complete').prop('checked');
                var adminOnly = $tr.find('.toggle-adminonly').prop('checked');
                $tr.removeClass('dd-blocked dd-blocked-complete dd-blocked-admin');
                if ( complete ) { $tr.addClass('dd-blocked dd-blocked-complete'); }
                else if ( adminOnly ) { $tr.addClass('dd-blocked dd-blocked-admin'); }
                // update badge text
                var $badge = $tr.find('.dd-badge');
                if ( complete ) { $badge.text('Blocked (Complete)').removeClass('dd-badge-active').addClass('dd-badge-blocked'); }
                else if ( adminOnly ) { $badge.text('Blocked (Admin-only)').removeClass('dd-badge-active').addClass('dd-badge-blocked'); }
                else { $badge.text('Active').removeClass('dd-badge-blocked').addClass('dd-badge-active'); }
            });
        }

        // initialize highlights once DOM ready
        refreshRowHighlights();

        // Enforce mutual exclusivity between toggles in the UI
        $(document).on('change', '.toggle-complete', function(){
            if ( $(this).prop('checked') ) {
                $(this).closest('tr').find('.toggle-adminonly').prop('checked', false);
            }
            refreshRowHighlights();
        });
        $(document).on('change', '.toggle-adminonly', function(){
            if ( $(this).prop('checked') ) {
                $(this).closest('tr').find('.toggle-complete').prop('checked', false);
            }
            refreshRowHighlights();
        });

        $('#dans-debloater-save').on('click', function(e){
            e.preventDefault();
            var data = gatherSettings();
            $('#dans-debloater-status').text('Saving...');
            // include nonce as a body param for servers that expect it in the request body
            data._wpnonce = DansDebloater.nonce;
            // include our signed short-lived token so server can authenticate without cookies
            data.auth_token = DansDebloater.auth_token;
            $('#dans-debloater-save').prop('disabled', true).append('<span class="dd-spinner" id="dd-save-spinner"></span>');
            $.ajax({
                url: DansDebloater.rest_base,
                method: 'POST',
                data: JSON.stringify( data ),
                contentType: 'application/json; charset=utf-8'
            }).done(function(){
                // Show saved status briefly then reload so the blocker logic runs with fresh data
                $('#dans-debloater-status').text('Saved. Reloading...');
                setTimeout(function(){ location.reload(); }, 350);
            }).fail(function(){
                $('#dans-debloater-status').text('Error saving.');
            }).always(function(){
                $('#dans-debloater-save').prop('disabled', false);
                $('#dd-save-spinner').remove();
            });
        });

        $('#dans-debloater-dropin-toggle').on('click', function(e){
            e.preventDefault();
            var $btn = $(this);
            var action = $btn.data('action');
            if ( action === 'install' && ! DansDebloater.dropinWritable ) {
                $('#dans-debloater-dropin-status').text('Directory not writable.');
                return;
            }

            $('#dans-debloater-dropin-status').text((action === 'install') ? 'Installing...' : 'Removing...');
            $btn.prop('disabled', true).append('<span class="dd-spinner" id="dd-dropin-spinner"></span>');

            var payload = {
                action: action,
                auth_token: DansDebloater.auth_token,
                _wpnonce: DansDebloater.nonce
            };

            $.ajax({
                url: DansDebloater.rest_dropin,
                method: 'POST',
                data: JSON.stringify( payload ),
                contentType: 'application/json; charset=utf-8'
            }).done(function(){
                $('#dans-debloater-dropin-status').text('Completed. Reloading...');
                setTimeout(function(){ location.reload(); }, 400);
            }).fail(function(xhr){
                var msg = 'Error';
                if ( xhr && xhr.responseJSON && xhr.responseJSON.message ) {
                    msg = xhr.responseJSON.message;
                }
                $('#dans-debloater-dropin-status').text(msg);
                $btn.prop('disabled', false);
                $('#dd-dropin-spinner').remove();
            });
        });

        // Live search filter
        $('#dans-debloater-search').on('input', function(){
            var q = $(this).val().toLowerCase().trim();
            $('tr[data-plugin]').each(function(){
                var $tr = $(this);
                var name = $tr.find('.plugin-name strong').text().toLowerCase();
                var desc = $tr.find('.row-description').text().toLowerCase();
                if ( q === '' || name.indexOf(q) !== -1 || desc.indexOf(q) !== -1 ) {
                    $tr.show();
                } else {
                    $tr.hide();
                }
            });
        });
    });
})(jQuery);
