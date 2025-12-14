/**
 * Show notification message with stacking support
 * @param {string} message - Message to display
 * @param {string} type - Notification type ('success', 'error', or 'warning')
 */
function showNotification(message, type)
{
    // Calculate top position based on existing notifications
    var topOffset = 20;
    $('.notification').each(function () {
        topOffset += $(this).outerHeight() + 10;
    });

    var notification = $('<div class="notification notification-' + type + '"></div>');
    notification.text(message);
    notification.css('top', topOffset + 'px');
    $('body').append(notification);

    setTimeout(function () {
        notification.addClass('show');
    }, 10);

    setTimeout(function () {
        notification.removeClass('show');
        setTimeout(function () {
            notification.remove();
            // Reposition remaining notifications
            repositionNotifications();
        }, 300);
    }, 3000);
}

/**
 * Reposition notifications after one is removed
 */
function repositionNotifications()
{
    var topOffset = 20;
    $('.notification').each(function () {
        $(this).css('top', topOffset + 'px');
        topOffset += $(this).outerHeight() + 10;
    });
}

/**
 * Show success notification
 * @param {string} message - Success message
 */
function showSuccess(message)
{
    showNotification(message, 'success');
}

/**
 * Show error notification
 * @param {string} message - Error message
 */
function showError(message)
{
    showNotification(message, 'error');
}

/**
 * Show warning notification
 * @param {string} message - Warning message
 */
function showWarning(message)
{
    showNotification(message, 'warning');
}
