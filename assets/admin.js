jQuery(document).ready(function($) {
    // 1. Run Cron Batch (Check Next X Oldest Links)
    $('#lean-blc-batch-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $spinner = $('#lean-blc-spinner');
        var $status = $('#lean-blc-status');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $status.text('Checking next batch of oldest links (rate-limited)...');

        $.post(leanBlcData.ajaxUrl, {
            action: 'lean_blc_run_batch',
            nonce: leanBlcData.nonce
        }, function(response) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            if (response.success) {
                $status.text('Batch complete! Reloading...');
                location.reload();
            } else {
                $status.text('Error: ' + (response.data || 'Batch check failed.'));
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            $status.text('Network error occurred.');
        });
    });

    // 2. Run Full Scan
    $('#lean-blc-scan-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $spinner = $('#lean-blc-spinner');
        var $status = $('#lean-blc-status');

        if (!confirm('Run a full scan across all site links? For large sites, the background cron batching is recommended to avoid long execution times.')) {
            return;
        }

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $status.text('Scanning published posts and checking URLs (strictly rate-limited)...');

        $.post(leanBlcData.ajaxUrl, {
            action: 'lean_blc_run_scan',
            nonce: leanBlcData.nonce
        }, function(response) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            if (response.success) {
                $status.text('Scan complete! Reloading...');
                location.reload();
            } else {
                $status.text('Error: ' + (response.data || 'Scan failed.'));
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            $status.text('Network error occurred while scanning.');
        });
    });

    // 3. Replace with Wayback Snapshot
    $(document).on('click', '.lean-blc-replace-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var postId = $row.data('post-id');
        var oldUrl = $row.data('url');

        if (!confirm('Replace this dead link with the Wayback Machine archive snapshot inside the post?')) {
            return;
        }

        $btn.prop('disabled', true).text('Replacing...');

        $.post(leanBlcData.ajaxUrl, {
            action: 'lean_blc_replace_link',
            nonce: leanBlcData.nonce,
            post_id: postId,
            old_url: oldUrl
        }, function(response) {
            if (response.success) {
                $row.fadeOut(300, function() { $(this).remove(); });
            } else {
                alert('Failed to replace link: ' + response.data);
                $btn.prop('disabled', false).text('Fix with Archive');
            }
        });
    });

    // 4. Dismiss Broken Link from Table
    $(document).on('click', '.lean-blc-dismiss-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var postId = $row.data('post-id');
        var url = $row.data('url');

        $btn.prop('disabled', true);
        $.post(leanBlcData.ajaxUrl, {
            action: 'lean_blc_dismiss_link',
            nonce: leanBlcData.nonce,
            post_id: postId,
            url: url
        }, function(response) {
            $row.fadeOut(300, function() { $(this).remove(); });
        });
    });

    // 5. Dismiss Global Admin Notice
    $(document).on('click', '.lean-blc-admin-notice .notice-dismiss', function(e) {
        $.post(leanBlcData.ajaxUrl, {
            action: 'lean_blc_dismiss_notice',
            nonce: leanBlcData.nonce
        });
    });

    // 6. Clear Activity Log
    $('#lean-blc-clear-log-btn').on('click', function(e) {
        e.preventDefault();
        if (!confirm('Are you sure you want to clear all activity log entries?')) {
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true).text('Clearing...');
        $.post(leanBlcData.ajaxUrl, {
            action: 'lean_blc_clear_log',
            nonce: leanBlcData.nonce
        }, function(response) {
            location.reload();
        });
    });
});
