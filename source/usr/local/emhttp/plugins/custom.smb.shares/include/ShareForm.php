<?php

/* Custom SMB Shares - Share Form
 * Shared form for Add/Update pages (following Docker plugin pattern)
 */

global $var, $docroot; // For CSRF token and docroot - provided by Unraid's page renderer

require_once "$docroot/plugins/custom.smb.shares/include/lib.php";

$shareName = $_GET['name'] ?? '';
$shares = loadShares();

// Find share by name
$share = [];
$shareIndex = -1;
if ($shareName) {
    foreach ($shares as $idx => $s) {
        if ($s['name'] === $shareName) {
            $share = $s;
            $shareIndex = $idx;
            break;
        }
    }
}

$isNew = empty($share);
$security = $share['security'] ?? 'public';
$showUserAccess = in_array($security, ['secure', 'private']);
?>

<link rel="stylesheet" href="/plugins/custom.smb.shares/css/feedback.css">
<link rel="stylesheet" href="/webGui/styles/jquery.filetree.css">
<link rel="stylesheet" href="/webGui/styles/jquery.switchbutton.css">
<script src="/plugins/custom.smb.shares/js/feedback.js"></script>
<script src="/webGui/javascript/jquery.filetree.js"></script>
<script src="/webGui/javascript/jquery.switchbutton.js"></script>

<style>
.advanced, div.title.advanced { display: none; }
.fileTree { position: absolute; z-index: 100; background: var(--background-color); border: 1px solid var(--border-color); max-height: 300px; overflow: auto; }
#user-access-title { margin-top: 2rem; }
#user-access-section { margin-top: 0; }

/* Permission grid styles */
.permission-section { margin: 1rem 0; }
.permission-label { font-weight: bold; display: block; margin-bottom: 0.5rem; }
.permission-preview { font-family: monospace; background: var(--shade-bg-color); padding: 2px 8px; border-radius: 3px; margin-left: 0.5rem; }
.permission-grid { border-collapse: collapse; margin: 0.5rem 0; }
.permission-grid th, .permission-grid td { padding: 0.4rem 1rem; text-align: center; border: 1px solid var(--border-color); }
.permission-grid th { background: var(--shade-bg-color); font-weight: normal; font-size: 0.9em; }
.permission-grid td:first-child { text-align: left; font-weight: bold; background: var(--shade-bg-color); }
.permission-grid input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
</style>

<form markdown="1" method="POST" action="/plugins/custom.smb.shares/<?=$isNew ? 'add' : 'update'?>.php" onsubmit="return prepareForm(this)">
<input type="hidden" name="csrf_token" value="<?=$var['csrf_token']?>">
<input type="hidden" name="original_name" value="<?=htmlspecialchars($share['name'] ?? '')?>">
<input type="hidden" name="user_access" value="<?=htmlspecialchars($share['user_access'] ?? '{}')?>">

<div class="title"><span class="left inline-flex flex-row items-center gap-1"><i class="fa fa-folder title"></i>_(Share Settings)_</span><span class="right"></span></div>
<div markdown="1" class="shade">
_(Share Name)_:
: <input type="text" name="name" value="<?=htmlspecialchars($share['name'] ?? '')?>" maxlength="40" required <?=$isNew ? '' : 'readonly'?>>

> The share name can be up to 40 characters. This name will be visible when browsing the network.
>
> Share names are case-sensitive and must be unique across all shares.

_(Path)_:
: <input type="text" name="path" value="<?=htmlspecialchars($share['path'] ?? '')?>" required placeholder="_(Click to browse...)_" onclick="openPathBrowser(this)" readonly>

> The path to the directory you want to share. Must be under /mnt/ (e.g., /mnt/user/myshare).
>
> Click to browse and select a directory.

_(Comment)_:
: <input type="text" name="comment" value="<?=htmlspecialchars($share['comment'] ?? '')?>">

> An optional description for this share. This comment is visible when browsing the network.
</div>

<div class="title"><span class="left inline-flex flex-row items-center gap-1"><i class="fa fa-windows title"></i>_(SMB Security Settings)_</span><span class="right"></span></div>
<div markdown="1" class="shade">
_(Export)_:
: <select name="export" onchange="toggleTimeMachineOptions(this)">
<?=mk_option($share['export'] ?? 'e', 'e', _('Yes'))?>
<?=mk_option($share['export'] ?? 'e', 'eh', _('Yes (hidden)'))?>
<?=mk_option($share['export'] ?? 'e', 'et', _('Yes (Time Machine)'))?>
<?=mk_option($share['export'] ?? 'e', 'eth', _('Yes (Time Machine, hidden)'))?>
<?=mk_option($share['export'] ?? 'e', '-', _('No'))?>
</select>

> Controls whether this share is exported via SMB. **Yes** makes it visible, **hidden** makes it accessible but not browseable, **Time Machine** configures it for macOS backups.

<div markdown="1" id="tm-options" style="display:<?=in_array($share['export'] ?? '', ['et', 'eth']) ? 'block' : 'none'?>">
_(Time Machine volume size limit)_:
: <input type="text" name="volsizelimit" value="<?=htmlspecialchars($share['volsizelimit'] ?? '')?>" placeholder="_(e.g. 500000)_"> MB

> For Time Machine shares, limits the maximum backup size in MB. Leave empty for no limit.
</div>

_(Security)_:
: <select name="security" onchange="toggleSecurityMode(this)">
<?=mk_option($share['security'] ?? 'public', 'public', _('Public'))?>
<?=mk_option($share['security'] ?? 'public', 'secure', _('Secure'))?>
<?=mk_option($share['security'] ?? 'public', 'private', _('Private'))?>
</select>

> **Public** - All users have read/write access. **Secure** - All users read, specified users write. **Private** - Only specified users have access.
</div>

<div class="title" id="user-access-title" style="display:<?=$showUserAccess ? 'flex' : 'none'?>"><span class="left inline-flex flex-row items-center gap-1"><i class="fa fa-user title"></i>_(SMB User Access)_</span><span class="right"></span></div>
<div markdown="1" class="shade" id="user-access-section" style="display:<?=$showUserAccess ? 'block' : 'none'?>">
<div id="userAccessList" class="user-access-list">
<p><em>_(Loading users...)_</em></p>
</div>
</div>

<div class="title advanced"><span class="left inline-flex flex-row items-center gap-1"><i class="fa fa-cog title"></i>_(Advanced Settings)_</span><span class="right"></span></div>
<div markdown="1" class="shade advanced">
_(Case-sensitive names)_:
: <select name="case_sensitive">
<?=mk_option($share['case_sensitive'] ?? 'auto', 'auto', _('Auto'))?>
<?=mk_option($share['case_sensitive'] ?? 'auto', 'yes', _('Yes'))?>
<?=mk_option($share['case_sensitive'] ?? 'auto', 'forced', _('Force lower'))?>
</select>

> **Auto** - Let Samba decide. **Yes** - Case-sensitive (Linux-style). **Force lower** - Convert all names to lowercase.

_(Hide dot files)_:
: <select name="hide_dot_files">
<?=mk_option($share['hide_dot_files'] ?? 'yes', 'yes', _('Yes'))?>
<?=mk_option($share['hide_dot_files'] ?? 'yes', 'no', _('No'))?>
</select>

> When **Yes**, files starting with a dot (.) are hidden from directory listings.

_(Enhanced macOS support)_:
: <select name="fruit">
<?=mk_option($share['fruit'] ?? 'no', 'no', _('No'))?>
<?=mk_option($share['fruit'] ?? 'no', 'yes', _('Yes'))?>
</select>

> Enable the Fruit VFS module for better macOS compatibility. Improves handling of resource forks, extended attributes, and Finder metadata. Automatically enabled for Time Machine shares.
</div>

<div class="title advanced"><span class="left inline-flex flex-row items-center gap-1"><i class="fa fa-lock title"></i>_(Permission Settings)_</span><span class="right"></span></div>
<div markdown="1" class="shade advanced">

<div class="permission-section">
<label class="permission-label">_(File permissions)_ <span class="permission-preview" id="create_mask_preview"><?=htmlspecialchars($share['create_mask'] ?? '0664')?></span></label>
<input type="hidden" name="create_mask" value="<?=htmlspecialchars($share['create_mask'] ?? '0664')?>">
<table class="permission-grid">
<thead><tr><th></th><th>_(Read)_</th><th>_(Write)_</th><th>_(Execute)_</th></tr></thead>
<tbody>
<tr><td>_(Owner)_</td>
<td><input type="checkbox" data-target="create_mask" data-role="owner" data-perm="r"></td>
<td><input type="checkbox" data-target="create_mask" data-role="owner" data-perm="w"></td>
<td><input type="checkbox" data-target="create_mask" data-role="owner" data-perm="x"></td></tr>
<tr><td>_(Group)_</td>
<td><input type="checkbox" data-target="create_mask" data-role="group" data-perm="r"></td>
<td><input type="checkbox" data-target="create_mask" data-role="group" data-perm="w"></td>
<td><input type="checkbox" data-target="create_mask" data-role="group" data-perm="x"></td></tr>
<tr><td>_(Others)_</td>
<td><input type="checkbox" data-target="create_mask" data-role="others" data-perm="r"></td>
<td><input type="checkbox" data-target="create_mask" data-role="others" data-perm="w"></td>
<td><input type="checkbox" data-target="create_mask" data-role="others" data-perm="x"></td></tr>
</tbody>
</table>
</div>

> Permissions for newly created files. Default: Owner read/write, Group read/write, Others read.

<div class="permission-section">
<label class="permission-label">_(Directory permissions)_ <span class="permission-preview" id="directory_mask_preview"><?=htmlspecialchars($share['directory_mask'] ?? '0775')?></span></label>
<input type="hidden" name="directory_mask" value="<?=htmlspecialchars($share['directory_mask'] ?? '0775')?>">
<table class="permission-grid">
<thead><tr><th></th><th>_(Read)_</th><th>_(Write)_</th><th>_(Execute)_</th></tr></thead>
<tbody>
<tr><td>_(Owner)_</td>
<td><input type="checkbox" data-target="directory_mask" data-role="owner" data-perm="r"></td>
<td><input type="checkbox" data-target="directory_mask" data-role="owner" data-perm="w"></td>
<td><input type="checkbox" data-target="directory_mask" data-role="owner" data-perm="x"></td></tr>
<tr><td>_(Group)_</td>
<td><input type="checkbox" data-target="directory_mask" data-role="group" data-perm="r"></td>
<td><input type="checkbox" data-target="directory_mask" data-role="group" data-perm="w"></td>
<td><input type="checkbox" data-target="directory_mask" data-role="group" data-perm="x"></td></tr>
<tr><td>_(Others)_</td>
<td><input type="checkbox" data-target="directory_mask" data-role="others" data-perm="r"></td>
<td><input type="checkbox" data-target="directory_mask" data-role="others" data-perm="w"></td>
<td><input type="checkbox" data-target="directory_mask" data-role="others" data-perm="x"></td></tr>
</tbody>
</table>
</div>

> Permissions for newly created directories. Default: Owner full access, Group read/execute, Others read/execute.

_(Force user)_:
: <input type="text" name="force_user" value="<?=htmlspecialchars($share['force_user'] ?? '')?>" placeholder="_(e.g. nobody)_">

> Force all file operations as this user. Leave empty to use connecting user's identity.

_(Force group)_:
: <input type="text" name="force_group" value="<?=htmlspecialchars($share['force_group'] ?? '')?>" placeholder="_(e.g. users)_">

> Force all file operations to use this group. Leave empty to use connecting user's group.

_(Hosts allow)_:
: <input type="text" name="hosts_allow" value="<?=htmlspecialchars($share['hosts_allow'] ?? '')?>" placeholder="_(e.g. 192.168.1.0/24)_">

> Comma-separated list of hosts/networks allowed to access this share. Leave empty to allow all.

_(Hosts deny)_:
: <input type="text" name="hosts_deny" value="<?=htmlspecialchars($share['hosts_deny'] ?? '')?>" placeholder="_(e.g. 192.168.1.100)_">

> Comma-separated list of hosts/networks denied access to this share. Deny rules are checked after allow rules.
</div>

&nbsp;
: <span class="inline-block">
    <input type="submit" value="<?=$isNew ? _('Add Share') : _('Apply')?>" onclick="this.value='<?=$isNew ? _('Adding...') : _('Applying...')?>'">
    <input type="button" value="_(Done)_" onclick="done()">
  </span>

</form>

<script>
function done() {
    location.href = '/SMBShares';
}

function openPathBrowser(el) {
    var $input = $(el);
    var $nameInput = $('input[name="name"]');
    
    // Skip if fileTree is already open
    if ($input.next('.fileTree').length) {
        $input.next('.fileTree').slideUp('fast', function(){ $(this).remove(); });
        return;
    }
    
    // Create fileTree container
    var r = Math.floor((Math.random()*10000)+1);
    $input.after("<div id='fileTree"+r+"' class='fileTree' style='display:none'></div>");
    var $ft = $('#fileTree'+r);
    
    $ft.fileTree({
        root: '/mnt/user',
        top: '/mnt',
        filter: 'HIDE_FILES_FILTER',
        allowBrowsing: true
    }, function(file) {
        // File selected - ignore for folders only
    }, function(folder) {
        // Folder clicked - update input but keep tree open for drilling down
        $input.val(folder.replace(/\/\/+/g, '/'));
        
        // Auto-populate name from folder name (only if name field is editable)
        if (!$nameInput.prop('readonly')) {
            var name = folder.split('/').filter(Boolean).pop();
            if (name) {
                $nameInput.val(name);
            }
        }
        // Don't close - let user continue drilling down or click outside to close
    });
    
    // Position and show
    $ft.css({
        'position': 'absolute',
        'left': $input.position().left,
        'top': $input.position().top + $input.outerHeight(),
        'width': Math.max($input.width(), 400),
        'z-index': 1000
    });
    
    // Close on click outside
    $(document).on('mouseup.filetree'+r, function(e) {
        if (!$ft.is(e.target) && $ft.has(e.target).length === 0 && !$input.is(e.target)) {
            $ft.slideUp('fast', function(){ $(this).remove(); });
            $(document).off('mouseup.filetree'+r);
        }
    });
    
    $ft.slideDown('fast');
}

function toggleTimeMachineOptions(select) {
    var val = $(select).val();
    var show = (val === 'et' || val === 'eth');
    $('#tm-options').toggle(show);
}

function toggleSecurityMode(select) {
    var mode = $(select).val();
    var showUserAccess = (mode === 'secure' || mode === 'private');
    $('#user-access-title').toggle(showUserAccess);
    $('#user-access-section').toggle(showUserAccess);
    if (showUserAccess) {
        populateUserAccess(mode);
    }
}

function populateUserAccess(mode) {
    var $container = $('#userAccessList');
    var currentAccess = {};
    
    try {
        currentAccess = JSON.parse($('input[name="user_access"]').val() || '{}');
    } catch (e) {
        currentAccess = {};
    }
    
    $container.html('<p><em><?=_("Loading users...")?></em></p>');
    
    $.get('/plugins/custom.smb.shares/get-users.php')
        .done(function(response) {
            if (!response.success || !response.users) {
                $container.html('<p class="error"><?=_("Failed to load users")?></p>');
                return;
            }
            
            if (response.users.length === 0) {
                $container.html('<p><em><?=_("No users found on system")?></em></p>');
                return;
            }
            
            var defaultAccess = (mode === 'secure') ? 'read-only' : 'no-access';
            var html = '<dl>';
            response.users.forEach(function(user) {
                var access = currentAccess[user.name] || defaultAccess;
                html += '<dt>' + user.name + '</dt>';
                html += '<dd><select name="access_' + user.name + '" data-user="' + user.name + '" onchange="updateUserAccess(this)">';
                html += '<option value="no-access"' + (access === 'no-access' ? ' selected' : '') + '><?=_("No Access")?></option>';
                html += '<option value="read-only"' + (access === 'read-only' ? ' selected' : '') + '><?=_("Read-only")?></option>';
                html += '<option value="read-write"' + (access === 'read-write' ? ' selected' : '') + '><?=_("Read/Write")?></option>';
                html += '</select></dd>';
            });
            html += '</dl>';
            
            $container.html(html);
        })
        .fail(function() {
            $container.html('<p class="error"><?=_("Failed to load users")?></p>');
        });
}

function updateUserAccess(select) {
    var $select = $(select);
    var $hidden = $('input[name="user_access"]');
    var access = {};
    
    try {
        access = JSON.parse($hidden.val() || '{}');
    } catch (e) {
        access = {};
    }
    
    access[$select.data('user')] = $select.val();
    $hidden.val(JSON.stringify(access));
}

function prepareForm(form) {
    var name = form.name.value.trim();
    var path = form.path.value.trim();
    
    if (!name) {
        swal({title: "<?=_('Error')?>", text: "<?=_('Share name is required')?>", type: 'error'});
        return false;
    }
    
    if (!path || !path.startsWith('/mnt/')) {
        swal({title: "<?=_('Error')?>", text: "<?=_('Path must start with /mnt/')?>", type: 'error'});
        return false;
    }
    
    // Submit via AJAX
    var $form = $(form);
    var $submitBtn = $form.find('input[type="submit"]');
    var originalText = $submitBtn.val();
    
    $submitBtn.val('<?=_('Saving')?>...').prop('disabled', true);
    
    $.post($form.attr('action'), $form.serialize())
        .done(function(response) {
            if (response.success) {
                // Redirect to main plugin page
                location.href = '/SMBShares';
            } else {
                swal({title: "<?=_('Error')?>", text: response.error || "<?=_('Unknown error')?>", type: 'error'});
                $submitBtn.val(originalText).prop('disabled', false);
            }
        })
        .fail(function(xhr) {
            var error = "<?=_('Request failed')?>";
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.error) error = resp.error;
            } catch(e) {}
            swal({title: "<?=_('Error')?>", text: error, type: 'error'});
            $submitBtn.val(originalText).prop('disabled', false);
        });
    
    return false; // Prevent normal form submission
}

// Permission mask helpers
function octalToCheckboxes(octal, target) {
    // Parse octal string (e.g., "0664" or "664")
    var val = octal.replace(/^0/, '');
    while (val.length < 3) val = '0' + val;
    
    var perms = {
        owner: parseInt(val[0], 10),
        group: parseInt(val[1], 10),
        others: parseInt(val[2], 10)
    };
    
    ['owner', 'group', 'others'].forEach(function(role) {
        var p = perms[role];
        $('input[data-target="' + target + '"][data-role="' + role + '"][data-perm="r"]').prop('checked', (p & 4) !== 0);
        $('input[data-target="' + target + '"][data-role="' + role + '"][data-perm="w"]').prop('checked', (p & 2) !== 0);
        $('input[data-target="' + target + '"][data-role="' + role + '"][data-perm="x"]').prop('checked', (p & 1) !== 0);
    });
}

function checkboxesToOctal(target) {
    var result = '0';
    ['owner', 'group', 'others'].forEach(function(role) {
        var val = 0;
        if ($('input[data-target="' + target + '"][data-role="' + role + '"][data-perm="r"]').is(':checked')) val += 4;
        if ($('input[data-target="' + target + '"][data-role="' + role + '"][data-perm="w"]').is(':checked')) val += 2;
        if ($('input[data-target="' + target + '"][data-role="' + role + '"][data-perm="x"]').is(':checked')) val += 1;
        result += val;
    });
    return result;
}

function updatePermissionMask(target) {
    var octal = checkboxesToOctal(target);
    $('input[name="' + target + '"]').val(octal);
    $('#' + target + '_preview').text(octal);
}

// Initialize on page load
$(function() {
    // Add Advanced View toggle to first title bar only (like Docker plugin)
    var ctrl = "<span class='status' style='float:right;margin-right:10px;'><input type='checkbox' class='advancedview'></span>";
    $('div.title:first').append(ctrl);
    $('.advancedview').switchButton({labels_placement:'left', on_label: "<?=_('Advanced View')?>", off_label: "<?=_('Basic View')?>"});
    $('.advancedview').change(function() {
        var status = $(this).is(':checked');
        if (status) {
            $('.advanced').slideDown('fast');
        } else {
            $('.advanced').slideUp('fast');
        }
    });
    
    // Initialize security mode
    var security = $('select[name="security"]').val();
    if (security === 'secure' || security === 'private') {
        populateUserAccess(security);
    }
    
    // Initialize permission checkboxes from current values
    octalToCheckboxes($('input[name="create_mask"]').val() || '0664', 'create_mask');
    octalToCheckboxes($('input[name="directory_mask"]').val() || '0775', 'directory_mask');
    
    // Update hidden field when checkboxes change
    $('input[data-target]').on('change', function() {
        updatePermissionMask($(this).data('target'));
    });
});
</script>
