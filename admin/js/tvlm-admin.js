jQuery(document).ready(function($) {
    // Global variables
    var currentEditingId = null;

    // Verberg pages section bij start
    $('.tvlm-pages-section').hide();

    // Initialize translate button
    $('#tvlm-bulk-translate').on('click', function(e) {
        e.preventDefault();
        showLanguageSelector();
    });

    // Initialize UI elements
    function initializeUI() {
        console.log('TVLM Admin JS loaded');
        
        // Initialize tooltips if present
        if (typeof $.fn.tooltip === 'function') {
            $('[data-tooltip]').tooltip();
        }

        // Initialize staging if we're on the staging page
        if ($('.tvlm-staging-controls').length) {
            loadStagingItems();
            initializeFilters();
        }
    }

    // Language selector dialog
    function showLanguageSelector() {
        var $dialog = $('#tvlm-bulk-translate-dialog');
        
        $dialog.dialog({
            title: 'Select Translation Language',
            modal: true,
            width: 400,
            closeOnEscape: true,
            buttons: {
                "Show Available Pages": function() {
                    var targetLang = $('#tvlm-bulk-target-language').val();
                    if (!targetLang) {
                        alert('Please select a target language');
                        return;
                    }
                    loadAvailablePages(targetLang);
                    $(this).dialog('close');
                },
                "Cancel": function() {
                    $(this).dialog('close');
                }
            }
        });
    }

    // Load available pages for selected language
    function loadAvailablePages(targetLang) {
        $.ajax({
            url: tvlm_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'tvlm_get_available_pages',
                target_lang: targetLang,
                nonce: tvlm_ajax.nonce
            },
            beforeSend: function() {
                showLoading();
            },
            success: function(response) {
                if (response.success) {
                    updatePagesSection(response.data.pages, targetLang);
                } else {
                    alert(response.data.message || 'Error loading pages');
                }
            },
            error: function() {
                alert('Server error occurred');
            },
            complete: function() {
                hideLoading();
            }
        });
    }

    // Update pages section with data
    function updatePagesSection(pages, targetLang) {
        var $tbody = $('.tvlm-pages-table tbody').empty();
        
        pages.forEach(function(page) {
            var status = page.translation_status ? 
                page.translation_status.translation_status : 'not-started';
            
            var $row = $('<tr>').append(
                $('<td>').addClass('column-title').text(page.post_title),
                $('<td>').addClass('column-status').text(page.post_status),
                $('<td>').addClass('column-translation')
                    .append($('<span>')
                        .addClass('tvlm-status tvlm-status-' + status)
                        .text(formatStatus(status))),
                $('<td>').addClass('column-modified').text(formatDate(page.post_modified)),
                $('<td>').addClass('column-actions')
                    .append($('<button>')
                        .addClass('button tvlm-translate-single')
                        .text('Translate')
                        .data({
                            'page-id': page.ID,
                            'target-lang': targetLang
                        })
                    )
            );
            
            $tbody.append($row);
        });

        $('.tvlm-pages-section').slideDown();
        
        // Initialize single page translate buttons
        initializeSingleTranslateButtons();
    }

    // Handle individual page translation
    function initializeSingleTranslateButtons() {
        $('.tvlm-translate-single').on('click', function() {
            var $button = $(this);
            var pageId = $button.data('page-id');
            var targetLang = $button.data('target-lang');

            copyPageToStaging(pageId, targetLang, $button);
        });
    }

    // Copy single page to staging
    function copyPageToStaging(pageId, targetLang, $button) {
        $button.prop('disabled', true).text('Copying...');

        $.ajax({
            url: tvlm_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'tvlm_copy_to_staging',
                page_id: pageId,
                target_lang: targetLang,
                nonce: tvlm_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $button.closest('tr').find('.column-translation span')
                        .removeClass()
                        .addClass('tvlm-status tvlm-status-pending')
                        .text('Pending');
                    
                    showNotice('Page copied to staging successfully', 'success');
                } else {
                    showNotice(response.data.message, 'error');
                }
            },
            error: function() {
                showNotice('Server error occurred', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Translate');
            }
        });
    }

    // Helper functions
    function showLoading() {
        if (!$('.tvlm-loading').length) {
            $('<div class="tvlm-loading">').text('Loading available pages...').insertBefore('.tvlm-pages-section');
        }
    }

    function hideLoading() {
        $('.tvlm-loading').remove();
    }

    function formatStatus(status) {
        return status.charAt(0).toUpperCase() + 
               status.slice(1).replace(/_/g, ' ');
    }

    function formatDate(dateStr) {
        return new Date(dateStr).toLocaleDateString();
    }

    function showNotice(message, type) {
        var $notice = $('<div>')
            .addClass('notice notice-' + type + ' is-dismissible')
            .append($('<p>').text(message))
            .append(
                $('<button>')
                    .addClass('notice-dismiss')
                    .append($('<span>').addClass('screen-reader-text').text('Dismiss this notice'))
            );

        $('#wpbody-content').find('.wrap').first().prepend($notice);

        // Auto dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() { $(this).remove(); });
        }, 5000);

        // Handle dismiss button
        $notice.find('.notice-dismiss').on('click', function() {
            $(this).closest('.notice').fadeOut(function() { $(this).remove(); });
        });
    }

    // Initialize on page load
    initializeUI();
});