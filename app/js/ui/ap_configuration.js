(function () {
    'use strict';

    var form = document.getElementById('apConfigurationForm');
    if (!form) return;

    var band = document.getElementById('apcBand');
    var channel = document.getElementById('apcChannel');
    var mode = document.getElementById('apcMode');
    var modeDisplay = document.getElementById('apcModeDisplay');
    var width = document.getElementById('apcWidth');
    var info = document.getElementById('apcChipInfo');
    var suggestion = document.getElementById('apcWidthSuggestion');
    var psk = document.getElementById('apcPsk');
    var togglePsk = document.getElementById('apcTogglePsk');
    var security = document.getElementById('apcSecurity');
    var encryption = document.getElementById('apcEncryption');
    var pskRow = document.getElementById('apcPskRow');
    var securityNote = document.getElementById('apcSecurityNote');
    var openSecurityModalElement = document.getElementById('apOpenSecurityConfirmModal');
    var openSecurityConfirm = document.getElementById('apOpenSecurityConfirm');
    var pendingOpenSecuritySubmitter = null;
    var openSecurityConfirmed = false;
    var ssidInput = form.querySelector('[name="ssid"]');
    var txPowerInput = form.querySelector('[name="txpower"]');
    var settingToggles = Array.from(form.querySelectorAll('#apcIsolation, #apcHiddenSsid'));
    var savedSettings = null;
    var options = Array.from(channel.querySelectorAll('option[data-band]')).map(function (option) {
        return {
            band: option.dataset.band,
            value: option.value,
            label: option.textContent.trim(),
            maxDbm: parseInt(option.dataset.maxDbm || '30', 10)
        };
    });
    var initialWidth = parseInt(width.dataset.currentWidth || '20', 10);

    function validWidths(selected, is5, available) {
        var set = new Set(available.map(function (item) { return parseInt(item.value, 10); }));
        var widths = [20];
        if (!is5) {
            if (selected <= 7 && set.has(selected + 4)) widths.push(40);
            return widths;
        }
        var blocks40 = [[36,40],[44,48],[52,56],[60,64],[100,104],[108,112],[116,120],[124,128],[132,136],[140,144],[149,153],[157,161]];
        var blocks80 = [[36,40,44,48],[52,56,60,64],[100,104,108,112],[116,120,124,128],[132,136,140,144],[149,153,157,161]];
        var contains = function (block) { return block[0] === selected && block.every(function (item) { return set.has(item); }); };
        if (blocks40.some(contains)) widths.push(40);
        if (blocks80.some(contains)) widths.push(80);
        return widths;
    }

    function syncRadio(optimize) {
        var selectedBand = band.value === '5' ? '5' : '24';
        var is5 = selectedBand === '5';
        var previous = channel.value;
        var available = options.filter(function (item) { return item.band === selectedBand; });
        channel.replaceChildren();
        available.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.value;
            option.textContent = item.label;
            option.dataset.maxDbm = String(item.maxDbm);
            channel.appendChild(option);
        });
        if (available.some(function (item) { return item.value === previous; })) channel.value = previous;
        mode.value = is5 ? 'ac' : 'n';
        if (modeDisplay) {
            modeDisplay.value = is5 ? '802.11a / 802.11ac (5 GHz)' : '802.11n (2.4 GHz)';
        }
        var allowed = validWidths(parseInt(channel.value || '0', 10), is5, available);
        width.replaceChildren();
        var selectedWidth = optimize ? 20 : parseInt(width.dataset.currentWidth || String(initialWidth), 10);
        if (!allowed.includes(selectedWidth)) selectedWidth = 20;
        allowed.forEach(function (value) {
            var option = document.createElement('option');
            option.value = String(value);
            option.textContent = value + ' MHz';
            width.appendChild(option);
        });
        width.value = String(selectedWidth);
        width.disabled = false;
        width.dataset.currentWidth = String(selectedWidth);
        var selectedOption = channel.options[channel.selectedIndex];
        var maxDbm = selectedOption ? parseInt(selectedOption.dataset.maxDbm || '30', 10) : 30;
        txPowerInput.max = String(maxDbm);
        if (parseInt(txPowerInput.value || '0', 10) > maxDbm) {
            txPowerInput.value = String(maxDbm);
        }
        if (suggestion) suggestion.textContent = '';
        info.textContent = (is5 ? '802.11a/ac' : '802.11n') + ' · ' + available.length + ' channels';
        return allowed;
    }

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

    function showToast(level, title, text, duration) {
        var previous = document.getElementById('apConfigurationToast');
        if (previous) previous.remove();
        var toast = document.createElement('div');
        toast.id = 'apConfigurationToast';
        toast.className = 'openap-ap-config-toast ' + level;
        toast.setAttribute('role', level === 'danger' ? 'alert' : 'status');
        var icon = level === 'success' ? 'fa-check-circle'
            : (level === 'info' ? 'fa-info-circle' : 'fa-exclamation-circle');
        toast.innerHTML = '<i class="fas ' + icon + '" aria-hidden="true"></i>'
            + '<div class="openap-toast-message"><strong></strong><span></span></div>'
            + (level === 'danger' ? '<button type="button" class="openap-toast-close" aria-label="Close error"><i class="fas fa-times"></i></button><button type="button" class="openap-toast-copy"><i class="far fa-copy"></i><span>Copy error</span></button>' : '');
        toast.querySelector('strong').textContent = title;
        toast.querySelector('span').textContent = text;
        toast.dataset.copyText = title + ': ' + text;
        document.body.appendChild(toast);
        window.requestAnimationFrame(function () { toast.classList.add('show'); });
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
        window.setTimeout(function () {
            toast.classList.add('leaving');
            toast.classList.remove('show');
            window.setTimeout(function () { toast.remove(); }, 180);
        }, duration);
    }

    function invalidFieldMessage(field) {
        var group = field.closest('.hfield-group');
        var label = group ? group.querySelector('.hfield-label') : null;
        var fieldName = label ? label.textContent.trim() : (field.name || 'Field');
        if (field.validity.valueMissing) return fieldName + ' is required.';
        if (field.validity.tooShort) {
            return fieldName + ' must contain at least ' + field.minLength + ' characters.';
        }
        if (field.validity.tooLong) {
            return fieldName + ' must contain no more than ' + field.maxLength + ' characters.';
        }
        if (field.validity.rangeUnderflow || field.validity.rangeOverflow) {
            return fieldName + ' must be between ' + field.min + ' and ' + field.max + '.';
        }
        return 'Check the value entered for ' + fieldName + '.';
    }

    function responseMessages(html) {
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        return Array.from(parsed.querySelectorAll('.alert')).map(function (alert) {
            return {
                danger: alert.classList.contains('alert-danger'),
                success: alert.classList.contains('alert-success'),
                text: alert.textContent.trim()
            };
        }).filter(function (message) { return message.text !== ''; });
    }

    function captureSettings() {
        return {
            ssid: ssidInput.value.trim(),
            band: band.value,
            channel: channel.value,
            width: width.value,
            txPower: txPowerInput.value,
            security: security.value,
            psk: psk.value,
            isolation: document.getElementById('apcIsolation').checked,
            hiddenSsid: document.getElementById('apcHiddenSsid').checked
        };
    }

    function savedSettingsMessage(before, after) {
        var changes = [];
        var addChange = function (label, oldValue, newValue, suffix) {
            if (String(oldValue) !== String(newValue)) {
                changes.push(label + ': ' + oldValue + ' \u2192 ' + newValue + (suffix || ''));
            }
        };
        addChange('Network name', before.ssid, after.ssid);
        addChange('Band', before.band === '5' ? '5 GHz' : '2.4 GHz',
            after.band === '5' ? '5 GHz' : '2.4 GHz');
        addChange('Channel', before.channel, after.channel);
        addChange('Width', before.width, after.width, ' MHz');
        addChange('TX power', before.txPower, after.txPower, ' dBm');
        addChange('Security', before.security === 'none' ? 'None' : 'WPA2-PSK',
            after.security === 'none' ? 'None' : 'WPA2-PSK');
        if (after.security !== 'none' && before.psk !== after.psk) changes.push('WiFi password updated');
        if (before.isolation !== after.isolation) {
            changes.push('AP Isolation ' + (after.isolation ? 'enabled' : 'disabled'));
        }
        if (before.hiddenSsid !== after.hiddenSsid) {
            changes.push('Hidden SSID ' + (after.hiddenSsid ? 'enabled' : 'disabled'));
        }
        return {
            title: changes.length === 0 ? 'Configuration saved'
                : (changes.length === 1 ? 'Setting saved' : (changes.length + ' settings saved')),
            text: changes.length ? changes.join(' \u2022 ') : 'Configuration saved without changes.'
        };
    }

    function refreshHotspotSummary() {
        return fetch('/ap_configuration?summary=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.text();
        }).then(function (html) {
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var fresh = parsed.querySelector('.openap-ap-configuration-page .hs-section');
            var current = document.querySelector('.openap-ap-configuration-page .hs-section');
            if (!fresh || !current) throw new Error('Hotspot summary not found');
            current.innerHTML = fresh.innerHTML;

            var freshControls = parsed.querySelector('#apConfigurationForm .btn-group-ss');
            var currentControls = document.querySelector('#apConfigurationForm .btn-group-ss');
            if (freshControls && currentControls) {
                currentControls.innerHTML = freshControls.innerHTML;
            }

            var freshServices = parsed.querySelector('.openap-service-status-card');
            var currentServices = document.querySelector('.openap-service-status-card');
            if (freshServices && currentServices) {
                currentServices.innerHTML = freshServices.innerHTML;
            }
        });
    }

    function waitForSavedRadioSettings(data, deadline, requireExactMatch) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, 1200);
        }).then(function () {
            return fetch('/ap_configuration?verify=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store'
            });
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.text();
        }).then(function (html) {
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var savedChannel = parsed.querySelector('#apcChannel');
            var savedWidth = parsed.querySelector('#apcWidth');
            var channelMatches = savedChannel
                && String(savedChannel.value) === String(data.get('channel') || '');
            var widthMatches = savedWidth
                && String(savedWidth.value || savedWidth.dataset.currentWidth || '')
                    === String(data.get('openap_channel_width') || '');
            // A successful POST already confirms that hostapd accepted and
            // saved the configuration. Drivers may normalize the channel or
            // width while the radio restarts, so in that case the form being
            // readable again is enough to confirm that the UI is ready.
            // Keep the exact comparison when the POST connection was
            // interrupted, because then it is our only proof that the save
            // reached the server.
            if (savedChannel && savedWidth
                && (!requireExactMatch || (channelMatches && widthMatches))) return;
            throw new Error('Radio settings are not ready yet.');
        }).catch(function (error) {
            if (Date.now() >= deadline) throw error;
            return waitForSavedRadioSettings(data, deadline, requireExactMatch);
        });
    }

    band.addEventListener('change', function () { syncRadio(true); });
    channel.addEventListener('change', function () { syncRadio(true); });
    width.addEventListener('change', function () {
        width.dataset.currentWidth = width.value;
        if (suggestion) suggestion.textContent = '';
    });
    settingToggles.forEach(function (control) {
        control.dataset.savedValue = control.checked ? '1' : '0';
    });
    togglePsk.addEventListener('click', function () {
        var reveal = psk.type === 'password';
        psk.type = reveal ? 'text' : 'password';
        togglePsk.querySelector('i').className = 'fas ' + (reveal ? 'fa-eye-slash' : 'fa-eye');
    });

    function syncSecurity() {
        var isOpen = security.value === 'none';
        psk.disabled = isOpen;
        psk.required = !isOpen;
        pskRow.hidden = isOpen;
        encryption.value = isOpen ? 'None' : 'CCMP';
        var copy = securityNote.querySelector('span');
        copy.textContent = isOpen
            ? 'Open networks have no password or traffic encryption. Anyone within range can connect.'
            : 'WPA2-PSK with AES/CCMP is used for client compatibility.';
    }
    security.addEventListener('change', syncSecurity);

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var submitter = event.submitter;
        if (!submitter) return;
        if (submitter.name === 'SaveHostAPDSettings'
            && security.value === 'none'
            && !openSecurityConfirmed) {
            pendingOpenSecuritySubmitter = submitter;
            bootstrap.Modal.getOrCreateInstance(openSecurityModalElement).show();
            return;
        }
        openSecurityConfirmed = false;
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            var invalidField = form.querySelector(':invalid');
            showToast('danger', 'Check required fields',
                invalidField ? invalidFieldMessage(invalidField) : 'Complete the required fields before saving.',
                5000);
            if (invalidField) invalidField.focus({ preventScroll: false });
            return;
        }
        var data = new FormData(form);
        data.set(submitter.name, submitter.value || '1');
        var submittedSettings = captureSettings();
        var saveSummary = savedSettingsMessage(savedSettings || submittedSettings, submittedSettings);
        settingToggles.forEach(function (control) { control.disabled = true; });
        var originalButtonHtml = submitter.innerHTML;
        var actionLabels = {
            SaveHostAPDSettings: 'Saving…',
            RestartHotspot: 'Restarting…',
            StopHotspot: 'Stopping…',
            StartHotspot: 'Starting…'
        };
        submitter.disabled = true;
        submitter.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> '
            + (actionLabels[submitter.name] || 'Working…');
        submitter.setAttribute('aria-busy', 'true');
        fetch(form.action || '/hostapd_conf', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: data
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.text();
        }).then(function (html) {
            var messages = responseMessages(html);
            var failure = messages.find(function (message) { return message.danger; });
            if (failure) throw new Error(failure.text);
            if (submitter.name === 'SaveHostAPDSettings'
                && !messages.some(function (message) { return message.success; })) {
                throw new Error('OpenAP did not confirm that the settings were saved.');
            }
            var ssid = String(data.get('ssid') || 'WiFi hotspot').trim();
            if (submitter.name === 'SaveHostAPDSettings') {
                width.dataset.currentWidth = String(data.get('openap_channel_width') || '20');
                settingToggles.forEach(function (control) {
                    control.dataset.savedValue = control.checked ? '1' : '0';
                });
            }
            var successMessages = {
                SaveHostAPDSettings: saveSummary.text,
                RestartHotspot: ssid + ' successfully restarted.',
                StopHotspot: ssid + ' successfully stopped.',
                StartHotspot: ssid + ' successfully started.'
            };
            if (submitter.name === 'SaveHostAPDSettings') {
                showToast('info', 'Reconnecting',
                    'The WiFi radio is restarting. Waiting to verify the saved channel…', 45000);
                return waitForSavedRadioSettings(data, Date.now() + 45000, false).then(function () {
                    savedSettings = submittedSettings;
                    showToast('success', saveSummary.title,
                        successMessages.SaveHostAPDSettings, 4000);
                    return refreshHotspotSummary().catch(function () {});
                }).finally(function () {
                    submitter.disabled = false;
                    settingToggles.forEach(function (control) { control.disabled = false; });
                    submitter.innerHTML = originalButtonHtml;
                    submitter.removeAttribute('aria-busy');
                });
            }
            showToast('success', 'Operation successful',
                successMessages[submitter.name] || 'Hotspot service updated.',
                2000);
            window.setTimeout(function () {
                refreshHotspotSummary().catch(function () {
                    // The configuration is already saved. Leave the current
                    // summary in place rather than causing a full-page jump.
                }).finally(function () {
                    submitter.disabled = false;
                    settingToggles.forEach(function (control) { control.disabled = false; });
                    submitter.innerHTML = originalButtonHtml;
                    submitter.removeAttribute('aria-busy');
                });
            }, 700);
        }).catch(function (error) {
            var interrupted = submitter.name === 'SaveHostAPDSettings'
                && (error instanceof TypeError || /Failed to fetch|NetworkError|Load failed/i.test(error.message));
            if (interrupted) {
                showToast('info', 'Reconnecting',
                    'The WiFi radio is restarting. Waiting to verify the saved channel…', 45000);
                return waitForSavedRadioSettings(data, Date.now() + 45000, true).then(function () {
                    width.dataset.currentWidth = String(data.get('openap_channel_width') || '20');
                    settingToggles.forEach(function (control) {
                        control.dataset.savedValue = control.checked ? '1' : '0';
                    });
                    savedSettings = submittedSettings;
                    showToast('success', saveSummary.title, saveSummary.text, 4000);
                    return refreshHotspotSummary().catch(function () {});
                }).catch(function (verifyError) {
                    settingToggles.forEach(function (control) {
                        control.checked = control.dataset.savedValue === '1';
                    });
                    showToast('danger', 'Operation failed',
                        'Unable to verify hotspot settings after reconnecting: ' + verifyError.message,
                        4000);
                }).finally(function () {
                    submitter.disabled = false;
                    settingToggles.forEach(function (control) { control.disabled = false; });
                    submitter.innerHTML = originalButtonHtml;
                    submitter.removeAttribute('aria-busy');
                });
            }
            submitter.disabled = false;
            settingToggles.forEach(function (control) {
                control.disabled = false;
                control.checked = control.dataset.savedValue === '1';
            });
            submitter.innerHTML = originalButtonHtml;
            submitter.removeAttribute('aria-busy');
            showToast('danger', 'Operation failed', error.message, 4000);
        });
    });

    var roleSwap = document.getElementById('apRoleSwap');
    var roleModalElement = document.getElementById('apInterfaceRoleModal');
    var roleModalConfirm = document.getElementById('apRoleModalConfirm');
    var roleConfirmView = document.getElementById('apInterfaceRoleConfirmView');
    var roleProgressView = document.getElementById('apInterfaceRoleProgressView');
    var roleSuccessView = document.getElementById('apInterfaceRoleSuccessView');
    var roleModalContent = document.getElementById('apInterfaceRoleModalContent');
    function waitForInterfaceRoles(deadline) {
        return fetch('/ajax/networking/get_interface_role_apply_status.php?t=' + Date.now(), {
            credentials: 'same-origin', cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        }).then(function (result) {
            if (result.status === 'success') return result;
            if (result.status === 'failed') throw new Error('The role change failed and OpenAP attempted to restore the previous roles.');
            if (Date.now() >= deadline) throw new Error('Timed out while waiting for the Wi-Fi roles to become ready.');
            return new Promise(function (resolve) { window.setTimeout(resolve, 900); })
                .then(function () { return waitForInterfaceRoles(deadline); });
        }).catch(function (error) {
            if (Date.now() >= deadline || /failed|Timed out/.test(error.message)) throw error;
            return new Promise(function (resolve) { window.setTimeout(resolve, 900); })
                .then(function () { return waitForInterfaceRoles(deadline); });
        });
    }
    function setRoleStepDone(element) {
        element.className = 'done';
        element.querySelector('i').className = 'fas fa-check-circle';
    }
    function setRoleStepActive(element) {
        element.className = 'active';
        element.querySelector('i').className = 'fas fa-circle-notch fa-spin';
    }
    if (roleSwap && roleModalElement && roleModalConfirm) {
        roleSwap.addEventListener('click', function () {
            var currentApName = roleSwap.dataset.apName;
            var currentUplinkName = roleSwap.dataset.uplinkName;
            roleConfirmView.hidden = false;
            roleProgressView.hidden = true;
            roleSuccessView.hidden = true;
            roleModalContent.classList.remove('is-success');
            roleModalConfirm.disabled = false;
            document.querySelector('#apInterfaceRoleProgressView .openap-role-transfer-visual').classList.remove('complete', 'is-exchanging', 'is-finishing');
            document.getElementById('apRoleProgressTitle').textContent = 'Switching wireless roles';
            document.getElementById('apRoleProgressCaption').textContent = 'OpenAP is restarting both Wi-Fi interfaces.';
            document.getElementById('apRoleModalNewAp').textContent = currentUplinkName;
            document.getElementById('apRoleModalNewUplink').textContent = currentApName;
            document.getElementById('apRoleProgressOldAp').textContent = currentApName;
            document.getElementById('apRoleProgressNewAp').textContent = currentUplinkName;
            bootstrap.Modal.getOrCreateInstance(roleModalElement).show();
        });
        roleModalConfirm.addEventListener('click', function () {
            roleModalConfirm.disabled = true;
            var original = roleSwap.innerHTML;
            roleSwap.disabled = true;
            roleSwap.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            roleConfirmView.hidden = true;
            roleProgressView.hidden = false;
            var prepareStep = document.getElementById('apRoleStepPrepare');
            var applyStep = document.getElementById('apRoleStepApply');
            var verifyStep = document.getElementById('apRoleStepVerify');
            window.setTimeout(function () { setRoleStepDone(prepareStep); setRoleStepActive(applyStep); }, 700);
            var data = new FormData();
            data.set('csrf_token', form.querySelector('[name="csrf_token"]').value);
            data.set('ap_mac', roleSwap.dataset.uplinkMac);
            data.set('uplink_mac', roleSwap.dataset.apMac);
            fetch('/ajax/networking/apply_interface_roles.php', {
                method: 'POST', credentials: 'same-origin', body: data
            }).then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok || !body.success) throw new Error(body.message || ('HTTP ' + response.status));
                    setRoleStepDone(applyStep);
                    setRoleStepActive(verifyStep);
                    return waitForInterfaceRoles(Date.now() + 70000);
                });
            }).then(function (result) {
                setRoleStepDone(verifyStep);
                var transferVisual = document.querySelector('#apInterfaceRoleProgressView .openap-role-transfer-visual');
                var oldApText = document.getElementById('apRoleProgressOldAp');
                var oldUplinkText = document.getElementById('apRoleProgressNewAp');
                transferVisual.classList.add('is-finishing');
                window.setTimeout(function () {
                    [oldApText, oldUplinkText].forEach(function (line, index) {
                        window.setTimeout(function () {
                            line.animate([
                                { transform: 'translateY(-50%)', opacity: 1 },
                                { transform: 'translateY(65%)', opacity: 0 }
                            ], { duration: 330, easing: 'cubic-bezier(.55,0,1,.45)', fill: 'forwards' });
                        }, index * 45);
                    });
                }, 220);
                window.setTimeout(function () {
                    oldApText.textContent = result.ap;
                    oldUplinkText.textContent = result.uplink;
                    [oldApText, oldUplinkText].forEach(function (line) {
                        line.getAnimations().forEach(function (animation) { animation.cancel(); });
                        line.animate([
                            { transform: 'translateY(65%)', opacity: 0 },
                            { transform: 'translateY(-58%)', opacity: 1, offset: .82 },
                            { transform: 'translateY(-50%)', opacity: 1 }
                        ], { duration: 440, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'forwards' });
                    });
                    transferVisual.classList.add('complete');
                }, 650);
                document.getElementById('apRoleProgressTitle').textContent = 'Wireless roles updated';
                document.getElementById('apRoleProgressCaption').textContent = 'Access point: ' + result.ap + ' · Wi-Fi uplink: ' + result.uplink;
                function animateRoleCards() {
                    var replacements = [
                        [document.getElementById('apRoleCurrentApName'), result.ap],
                        [document.getElementById('apRoleCurrentApDetails'), roleSwap.dataset.uplinkDetails],
                        [document.getElementById('apRoleCurrentUplinkName'), result.uplink],
                        [document.getElementById('apRoleCurrentUplinkDetails'), roleSwap.dataset.apDetails]
                    ];
                    replacements.forEach(function (entry, index) {
                        window.setTimeout(function () {
                            entry[0].animate([
                                { transform: 'translateY(0)', opacity: 1 },
                                { transform: 'translateY(115%)', opacity: 0 }
                            ], { duration: 330, easing: 'cubic-bezier(.55,0,1,.45)', fill: 'forwards' });
                        }, index * 45);
                    });
                    window.setTimeout(function () {
                        replacements.forEach(function (entry) {
                            entry[0].textContent = entry[1];
                            entry[0].getAnimations().forEach(function (animation) { animation.cancel(); });
                            entry[0].animate([
                                { transform: 'translateY(115%)', opacity: 0 },
                                { transform: 'translateY(-8%)', opacity: 1, offset: .82 },
                                { transform: 'translateY(0)', opacity: 1 }
                            ], { duration: 440, easing: 'cubic-bezier(.22,1,.36,1)' });
                        });
                    }, 430);
                }
                window.setTimeout(function () {
                    document.getElementById('apRoleSuccessAp').textContent = result.ap;
                    document.getElementById('apRoleSuccessUplink').textContent = result.uplink;
                    roleProgressView.hidden = true;
                    roleSuccessView.hidden = false;
                    roleModalContent.classList.add('is-success');
                }, 1300);
                window.setTimeout(function () {
                    bootstrap.Modal.getOrCreateInstance(roleModalElement).hide();
                }, 3300);
                roleModalElement.addEventListener('hidden.bs.modal', function () {
                    animateRoleCards();
                    window.setTimeout(function () { window.location.reload(); }, 1200);
                }, { once: true });
            }).catch(function (error) {
                document.getElementById('apRoleProgressTitle').textContent = 'Unable to change wireless roles';
                document.getElementById('apRoleProgressCaption').textContent = error.message;
                roleSwap.disabled = false;
                roleSwap.innerHTML = original;
                window.setTimeout(function () {
                    roleProgressView.hidden = true;
                    roleConfirmView.hidden = false;
                    roleModalConfirm.disabled = false;
                }, 3000);
            });
        });
    }

    openSecurityConfirm.addEventListener('click', function () {
        var submitter = pendingOpenSecuritySubmitter;
        pendingOpenSecuritySubmitter = null;
        openSecurityConfirmed = true;
        bootstrap.Modal.getOrCreateInstance(openSecurityModalElement).hide();
        form.requestSubmit(submitter);
    });
    openSecurityModalElement.addEventListener('hidden.bs.modal', function () {
        if (!openSecurityConfirmed) pendingOpenSecuritySubmitter = null;
    });

    syncRadio(false);
    syncSecurity();
    savedSettings = captureSettings();
}());
