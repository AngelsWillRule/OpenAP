import { getCSRFToken } from "../helpers.js";

export function showSessionExpiredModal() {
    const returnUrl = window.location.pathname + window.location.search + window.location.hash;
    const params = new URLSearchParams({
        action: returnUrl,
        reason: 'session_expired'
    });
    window.location.replace(`/login?${params.toString()}`);
}

export function initSession_ajax() {
    function checkSession() {
        // skip session check if on login page
        if (window.location.pathname === '/login') {
            return;
        }
        var csrfToken = getCSRFToken();
        $.post('ajax/session/do_check_session.php',{'csrf_token': csrfToken},function (data) {
            if (data.status === 'session_expired') {
                clearInterval(sessionCheckInterval);
                showSessionExpiredModal();
            }
        }).fail(function (jqXHR, status, err) {
            console.error("Error checking session status:", status, err);
        });
    }
    let sessionCheckInterval = setInterval(checkSession, 5000);
}
