export function initLogin() {
    console.info("OpenAP login module initialized");

    const params = new URLSearchParams(window.location.search);
    const redirectUrl = $('#redirect-url').val() || params.get('action') || '/';
    $('#modal-admin-login').modal('show');
    $('#redirect-url').val(redirectUrl);
    $('#username').focus();
    $('#username').addClass("focusedInput");

    const form = document.getElementById('admin-login-form');
    if (!form || form.dataset.csrfRefreshBound === 'true') {
        return;
    }
    form.dataset.csrfRefreshBound = 'true';
    form.addEventListener('submit', async (event) => {
        if (form.dataset.csrfReady === 'true') {
            return;
        }

        if (!form.checkValidity()) {
            event.preventDefault();
            form.classList.add('was-validated');

            const invalidField = form.querySelector(':invalid');
            const feedback = document.getElementById('openapLoginFeedback');
            if (feedback && invalidField) {
                const fieldName = invalidField.id === 'username' ? 'Username' : 'Password';
                feedback.innerHTML = `<div class="alert alert-danger py-2 mb-3" role="alert" style="font-size:13px;text-align:center">${fieldName} is required.</div>`;
            }
            invalidField?.focus();
            form.reportValidity();
            return;
        }
        event.preventDefault();

        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
        }
        try {
            const separator = window.location.search ? '&' : '?';
            const response = await fetch(
                `${window.location.pathname}${window.location.search}${separator}csrf_refresh=${Date.now()}`,
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {'X-OpenAP-CSRF-Refresh': '1'}
                }
            );
            if (!response.ok) {
                throw new Error(`CSRF refresh failed with HTTP ${response.status}`);
            }
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const freshToken = page.querySelector('input[name="csrf_token"]')?.value || '';
            const tokenField = form.querySelector('input[name="csrf_token"]');
            if (!freshToken || !tokenField) {
                throw new Error('CSRF refresh did not return a token');
            }
            tokenField.value = freshToken;
            form.dataset.csrfReady = 'true';
            HTMLFormElement.prototype.submit.call(form);
        } catch (error) {
            console.error('Unable to refresh the login session', error);
            form.dataset.csrfReady = 'false';
            if (submitButton) {
                submitButton.disabled = false;
            }
            window.location.reload();
        }
    });
}
