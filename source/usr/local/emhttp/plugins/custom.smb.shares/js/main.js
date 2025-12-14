/* global $, swal, showSuccess, showError, dialogStyle, console */

/**
 * Toggle Time Machine options visibility based on Export selection
 */
window.toggleTimeMachineOptions = function (select) {
    var $select = $(select);
    var $form = $select.closest('form');
    var isTimeMachine = $select.val() === 'et' || $select.val() === 'eth';
    $form.find('.tm-option').toggle(isTimeMachine);
};

/**
 * Toggle Security mode - show/hide SMB User Access tab
 */
window.toggleSecurityMode = function (select) {
    var $select = $(select);
    var $form = $select.closest('form');
    var mode = $select.val();
    var showUserAccess = mode === 'secure' || mode === 'private';

    $form.find('.tab-button[data-tab="smb-access"]').toggle(showUserAccess);

    if (showUserAccess) {
        populateUserAccess($form, mode);
    }
};

/**
 * Populate user access list with system users
 * @param {jQuery} $form - The form element containing the user access list
 * @param {string} mode - Security mode ('secure' or 'private')
 */
function populateUserAccess($form, mode)
{
    var $container = $form.find('[data-component="userAccessList"]');
    var currentAccess = {};

    try {
        currentAccess = JSON.parse($form.find('input[name="user_access"]').val() || '{}');
    } catch (e) {
        currentAccess = {};
    }

    $container.html('<p><em>Loading users...</em></p>');

    $.get('/plugins/custom.smb.shares/get-users.php')
        .done(function (response) {
            if (!response.success || !response.users) {
                $container.html('<p class="error">Failed to load users: ' + (response.error || 'Unknown error') + '</p>');
                return;
            }

            if (response.users.length === 0) {
                $container.html('<p><em>No users found on system</em></p>');
                return;
            }

            var html = '<table class="user-access-table"><tbody>';
            response.users.forEach(function (user) {
                var access = currentAccess[user.name] || getDefaultAccess(mode);
                html += '<tr>';
                html += '<td><strong>' + user.name + '</strong></td>';
                html += '<td><select name="access_' + user.name + '" data-user="' + user.name + '" onchange="updateUserAccess(this)">';
                html += '<option value="no-access"' + (access === 'no-access' ? ' selected' : '') + '>No Access</option>';
                html += '<option value="read-only"' + (access === 'read-only' ? ' selected' : '') + '>Read-only</option>';
                html += '<option value="read-write"' + (access === 'read-write' ? ' selected' : '') + '>Read/Write</option>';
                html += '</select></td>';
                html += '</tr>';
            });
            html += '</tbody></table>';

            $container.html(html);
        })
        .fail(function (xhr, status, error) {
            console.error('Failed to load users:', status, error);
            $container.html('<p class="error">Failed to load users: ' + error + '</p>');
        });
}

/**
 * Get default access level based on security mode
 * @param {string} mode - Security mode ('secure' or 'private')
 * @returns {string} Default access level ('read-only' or 'no-access')
 */
function getDefaultAccess(mode)
{
    return mode === 'secure' ? 'read-only' : 'no-access';
}

/**
 * Update hidden user_access field when dropdown changes
 */
window.updateUserAccess = function (select) {
    var $select = $(select);
    var $form = $select.closest('form');
    var $hidden = $form.find('input[name="user_access"]');
    var access = {};

    try {
        access = JSON.parse($hidden.val() || '{}');
    } catch (e) {
        access = {};
    }

    access[$select.data('user')] = $select.val();
    $hidden.val(JSON.stringify(access));
};

/**
 * Switch tabs in modal forms using data attributes
 * Called from tab button click - uses data-tab attribute on button
 */
$(document).on('click', '.tab-button[data-tab]', function () {
    var $button = $(this);
    var tabName = $button.data('tab');
    var $form = $button.closest('form');

    $form.find('.tab-content').removeClass('active');
    $form.find('.tab-button').removeClass('active');
    $form.find('[data-tab-content="' + tabName + '"]').addClass('active');
    $button.addClass('active');

    // Populate users when SMB Access tab is clicked
    if (tabName === 'smb-access') {
        var mode = $form.find('select[name="security"]').val();
        populateUserAccess($form, mode);
    }
});

/**
 * Handle form submission via AJAX for share add/update
 */
$(document).on('submit', '#dialogShare form', function (e) {
    e.preventDefault();

    var $form = $(this);
    var $dialog = $form.closest('.ui-dialog-content');
    var action = $form.attr('action');
    var isAdd = action.indexOf('add.php') !== -1;

    $.ajax({
        url: action,
        type: 'POST',
        data: $form.serialize(),
        dataType: 'json',
        success: function (response) {
            if (response.success) {
                $dialog.dialog('close');
                showSuccess(response.message || (isAdd ? 'Share added' : 'Share updated'));
                location.reload();
            } else {
                showError(response.error || 'Operation failed');
            }
        },
        error: function (xhr) {
            var msg = 'Request failed';
            try {
                var resp = JSON.parse(xhr.responseText);
                msg = resp.error || msg;
            } catch (e) {
                // ignore parse error
            }
            showError(msg);
        }
    });
});

$(function () {

// Initialize tablesorter
    $('.tablesorter').tablesorter({
        sortList: [[0,0]],
        headers: {
            5: { sorter: false }
        }
    });

    checkSambaStatus();
    setInterval(checkSambaStatus, 30000);

    function checkSambaStatus()
    {
        $.get('/plugins/custom.smb.shares/status.php', function (data) {
            var status = $('#samba-status');
            if (data.includes('running') || data.includes('active')) {
                status.html('<i class="fa fa-check green-text"></i> Samba is running');
            } else {
                status.html('<i class="fa fa-times red-text"></i> Samba is not running');
            }
        }).fail(function () {
            $('#samba-status').html('<i class="fa fa-warning orange-text"></i> Status unknown');
        });
    }

    window.reloadSamba = function () {
        var btn = $('#reloadBtn');
        var originalText = btn.val();
        btn.prop('disabled', true).html(originalText + ' <span class="spinner"></span>');

        $.post('/plugins/custom.smb.shares/reload.php', function (response) {
            if (response.success) {
                showSuccess('Samba reloaded successfully');
            } else {
                showError('Failed to reload Samba: ' + response.error);
            }
            btn.prop('disabled', false).val(originalText);
            checkSambaStatus();
        }, 'json').fail(function (xhr) {
            showError('Failed to reload Samba');
            btn.prop('disabled', false).val(originalText);
        });
    };

});

