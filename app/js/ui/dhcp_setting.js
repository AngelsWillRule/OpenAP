(function () {
    'use strict';
    var form = document.getElementById('dhcpSettingForm');
    if (!form) return;
    var preset = document.getElementById('dhcpDnsPreset');
    var policy = document.getElementById('dhcpDnsPolicy');
    var advertised = document.getElementById('dhcpAdvertisedDns');
    var upstream = document.getElementById('dhcpUpstreamDns');
    var network = document.getElementById('dhcpNetworkAddress');
    var subnet = document.getElementById('dhcpSubnet');
    var gateway = document.getElementById('dhcpGateway');
    var rangeStart = document.getElementById('dhcpRangeStart');
    var rangeEnd = document.getElementById('dhcpRangeEnd');
    var encryptedMode = form.dataset.encryptedDns === '1';
    var leaseTime = form.elements.dhcp_lease_time;
    var savedSettings = null;

    function copyToastText(value) {
        if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(value);
        var handler = function (event) {
            event.clipboardData.setData('text/plain', value);
            event.preventDefault();
        };
        document.addEventListener('copy', handler, { once: true });
        var copied = document.execCommand('copy');
        if (!copied) document.removeEventListener('copy', handler);
        return copied ? Promise.resolve() : Promise.reject(new Error('copy failed'));
    }

    function showToast(level, text, title) {
        var old = document.getElementById('apConfigurationToast');
        if (old) old.remove();
        var toast = document.createElement('div');
        toast.id = 'apConfigurationToast';
        toast.className = 'openap-ap-config-toast ' + level;
        toast.setAttribute('role', level === 'danger' ? 'alert' : 'status');
        toast.innerHTML = '<i class="fas ' + (level === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i><div class="openap-toast-message"><strong></strong><span></span></div>'
            + (level === 'danger' ? '<button type="button" class="openap-toast-close" aria-label="Close error"><i class="fas fa-times"></i></button><button type="button" class="openap-toast-copy"><i class="far fa-copy"></i><span>Copy error</span></button>' : '');
        toast.querySelector('strong').textContent = title
            || (level === 'success' ? 'Operation successful' : 'Operation failed');
        toast.querySelector('span').textContent = text;
        toast.dataset.copyText = toast.querySelector('strong').textContent + ': ' + text;
        document.body.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('show'); });
        if (level === 'danger') {
            toast.querySelector('.openap-toast-close').addEventListener('click', function () { toast.remove(); });
            toast.querySelector('.openap-toast-copy').addEventListener('click', function () {
                copyToastText(toast.dataset.copyText).then(function () {
                    toast.querySelector('.openap-toast-copy span').textContent = 'Copied';
                }).catch(function () {
                    toast.querySelector('.openap-toast-copy span').textContent = 'Copy failed';
                });
            });
            return;
        }
        setTimeout(function () {
            toast.classList.add('leaving');
            toast.classList.remove('show');
            setTimeout(function () { toast.remove(); }, 180);
        }, level === 'success' ? 2000 : 4000);
    }

    function invalidFieldMessage(field) {
        var group = field.closest('.hfield-group');
        var label = group ? group.querySelector('.hfield-label') : null;
        var fieldName = label ? label.textContent.trim() : (field.name || 'Field');
        if (field.validity.valueMissing) return fieldName + ' is required.';
        if (field.validity.patternMismatch) {
            if (field.name === 'dhcp_lease_time') {
                return fieldName + ' must use a value such as 30m, 12h or 7d.';
            }
            return 'Check the format entered for ' + fieldName + '.';
        }
        if (field.validity.tooShort) {
            return fieldName + ' must contain at least ' + field.minLength + ' characters.';
        }
        if (field.validity.tooLong) {
            return fieldName + ' must contain no more than ' + field.maxLength + ' characters.';
        }
        return 'Check the value entered for ' + fieldName + '.';
    }

    function captureSettings() {
        return {
            subnet: subnet.value.trim(),
            gateway: gateway.value.trim(),
            rangeStart: rangeStart.value.trim(),
            rangeEnd: rangeEnd.value.trim(),
            leaseTime: leaseTime.value.trim(),
            dnsPolicy: policy.value,
            advertisedDns: advertised.value.trim(),
            upstreamDns: upstream.value.trim()
        };
    }

    function savedSettingsMessage(before, after) {
        var changes = [];
        var addChange = function (label, oldValue, newValue) {
            if (String(oldValue) !== String(newValue)) {
                changes.push(label + ': ' + oldValue + ' \u2192 ' + newValue);
            }
        };
        var policyLabel = function (value) {
            return value === 'local' ? 'Local DNS' : 'External DNS';
        };
        addChange('Hotspot subnet', before.subnet, after.subnet);
        addChange('Gateway', before.gateway, after.gateway);
        addChange('Range start', before.rangeStart, after.rangeStart);
        addChange('Range end', before.rangeEnd, after.rangeEnd);
        addChange('Lease time', before.leaseTime, after.leaseTime);
        addChange('DNS policy', policyLabel(before.dnsPolicy), policyLabel(after.dnsPolicy));
        addChange('Advertised DNS', before.advertisedDns, after.advertisedDns);
        addChange('Upstream DNS', before.upstreamDns, after.upstreamDns);
        return {
            title: changes.length === 0 ? 'Configuration saved'
                : (changes.length === 1 ? 'Setting saved' : changes.length + ' settings saved'),
            text: changes.length ? changes.join(' \u2022 ') : 'Configuration saved without changes.'
        };
    }

    function selectedPresetAddresses() {
        if (!preset) return '';
        var option = preset.options[preset.selectedIndex];
        return option && option.dataset.addresses ? option.dataset.addresses : '';
    }
    function syncDnsPolicy(applyPreset) {
        if (encryptedMode) {
            advertised.value = gateway.value.trim();
            upstream.value = '127.0.2.1';
            return;
        }
        var local = policy.value === 'local';
        var addresses = selectedPresetAddresses();
        var custom = preset.value === 'custom';
        advertised.readOnly = local || !custom;
        upstream.readOnly = !custom;
        if (addresses && applyPreset) upstream.value = addresses;
        if (local) {
            advertised.value = form.elements.dhcp_gateway.value.trim();
        } else if (addresses) {
            advertised.value = addresses;
        } else if (applyPreset && advertised.value.trim() === form.elements.dhcp_gateway.value.trim()) {
            advertised.value = upstream.value.trim();
        }
    }
    function syncNetwork() {
        var parts = network.value.trim().split('.');
        var startHost = rangeStart.value.trim().split('.').pop() || '50';
        var endHost = rangeEnd.value.trim().split('.').pop() || '200';
        subnet.value = network.value.trim() + '/24';
        if (parts.length === 4 && parts.every(function (part) { return /^\d{1,3}$/.test(part) && Number(part) <= 255; })) {
            var prefix = parts.slice(0, 3).join('.');
            parts[3] = '1';
            gateway.value = parts.join('.');
            rangeStart.value = prefix + '.' + startHost;
            rangeEnd.value = prefix + '.' + endHost;
            if (policy.value === 'local') advertised.value = gateway.value;
        } else {
            gateway.value = '';
        }
    }
    if (preset) preset.addEventListener('change', function () { syncDnsPolicy(true); });
    policy.addEventListener('change', function () { syncDnsPolicy(true); });
    network.addEventListener('input', syncNetwork);
    syncNetwork();
    syncDnsPolicy(false);
    savedSettings = captureSettings();

    function waitForApply(deadline) {
        return fetch('/ajax/networking/get_dhcp_apply_status.php?t=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.json(); })
            .then(function (result) {
                if (result.status === 'success') return;
                if (result.status === 'failed') throw new Error('DHCP configuration failed and the previous settings were restored.');
                if (Date.now() >= deadline) throw new Error('Timed out while applying DHCP settings.');
                return new Promise(function (resolve) { setTimeout(resolve, 800); }).then(function () { return waitForApply(deadline); });
            })
            .catch(function (error) {
                if (Date.now() >= deadline || /failed|Timed out/.test(error.message)) throw error;
                return new Promise(function (resolve) { setTimeout(resolve, 800); }).then(function () { return waitForApply(deadline); });
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            var invalidField = form.querySelector(':invalid');
            showToast(
                'danger',
                invalidField ? invalidFieldMessage(invalidField) : 'Complete the required fields before saving.',
                'Check required fields'
            );
            if (invalidField) invalidField.focus({ preventScroll: false });
            return;
        }
        var button = event.submitter;
        var original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
        var data = new FormData(form);
        data.set('SaveDhcpSettings', '1');
        var submittedSettings = captureSettings();
        var saveSummary = savedSettingsMessage(savedSettings || submittedSettings, submittedSettings);
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 12000);
        fetch('/dhcp_setting', { method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: data, signal: controller.signal })
            .then(function (response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.text(); })
            .then(function (html) {
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                var error = parsed.querySelector('.alert-danger');
                if (error) throw new Error(error.textContent.trim());
                if (!parsed.querySelector('.alert-success')) throw new Error('OpenAP did not confirm the save operation.');
                return waitForApply(Date.now() + 45000);
            }).then(function () {
                document.getElementById('dhcpWidgetRange').textContent = data.get('dhcp_start') + ' - ' + data.get('dhcp_end');
                document.getElementById('dhcpWidgetLease').textContent = data.get('dhcp_lease_time');
                document.getElementById('dhcpWidgetDns').textContent = data.get('dhcp_advertised_dns');
                savedSettings = submittedSettings;
                showToast('success', saveSummary.text, saveSummary.title);
            })
            .catch(function (error) { showToast('danger', error.message); })
            .finally(function () { clearTimeout(timeout); button.disabled = false; button.innerHTML = original; });
    });

    var encryptedDnsForm = document.getElementById('encryptedDnsForm');
    var encryptedDnsButton = document.getElementById('applyEncryptedDns');
    if (encryptedDnsForm && encryptedDnsButton) {
        encryptedDnsForm.addEventListener('submit', function () {
            if (!encryptedDnsForm.checkValidity()) {
                encryptedDnsForm.classList.add('was-validated');
                return;
            }
            encryptedDnsButton.setAttribute('aria-busy', 'true');
            encryptedDnsButton.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Applying…';
        });
    }

    var encryptedDnsResult = document.getElementById('encryptedDnsOperationResult');
    if (encryptedDnsResult) {
        showToast(
            encryptedDnsResult.dataset.level === 'success' ? 'success' : 'danger',
            encryptedDnsResult.dataset.message || 'Encrypted DNS operation completed.'
        );
    }
}());
