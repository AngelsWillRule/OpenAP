import {
    setCSRFTokenHeader,
    getCookie,
    setCookie,
    disableValidation,
    setDarkMode,
    setLightMode
} from "./helpers.js";

import { initHostapd } from "./ui/hostapd.js";
import { initSystem } from "./ui/system.js";
import { initLogin } from "./ui/login.js?v=20260802-required-fields";

// ajax handlers
import { initHostapd_ajax } from "./ajax/hostapd.js?v=20260720-openap-optimal-width-2";
import { initSession_ajax } from "./ajax/session.js?v=20260730-mobile-session-expiry-3";
import { initSystem_ajax} from "./ajax/system.js";

document.addEventListener('DOMContentLoaded', () => {
    console.info("OpenAP app.js initialized");

    // Treat the mobile bottom navigation as primary app tabs. Besides
    // replacing entries, remember the latest tab so mobile back/forward cache
    // restores cannot reveal an older primary tab.
    const mobilePrimaryRoutes = new Set(['/', '/dashboard', '/ap_configuration', '/dhcp_setting']);
    const mobilePrimaryRouteKey = 'openap|mobile-primary-route';
    const mobileNavIndexKey = 'openap|mobile-nav-previous-index';
    const isMobilePrimaryNavigation = () => window.matchMedia('(max-width: 767.98px)').matches;
    const enforceLatestMobilePrimaryRoute = () => {
        if (!isMobilePrimaryNavigation() || !mobilePrimaryRoutes.has(window.location.pathname)) {
            return;
        }
        try {
            const latestRoute = sessionStorage.getItem(mobilePrimaryRouteKey);
            if (latestRoute && latestRoute !== window.location.pathname && mobilePrimaryRoutes.has(latestRoute)) {
                window.location.replace(latestRoute);
            }
        } catch (error) {
            // History replacement still works when session storage is blocked.
        }
    };

    if (isMobilePrimaryNavigation() && mobilePrimaryRoutes.has(window.location.pathname)) {
        try {
            const navigation = performance.getEntriesByType('navigation')[0];
            const restoredByHistory = navigation && navigation.type === 'back_forward';
            const latestRoute = sessionStorage.getItem(mobilePrimaryRouteKey);
            if (restoredByHistory && latestRoute && latestRoute !== window.location.pathname && mobilePrimaryRoutes.has(latestRoute)) {
                window.location.replace(latestRoute);
                return;
            }
            sessionStorage.setItem(mobilePrimaryRouteKey, window.location.pathname);
        } catch (error) {
            // Keep navigation usable in private or hardened browser contexts.
        }
    }

    window.addEventListener('pageshow', enforceLatestMobilePrimaryRoute);
    window.addEventListener('popstate', enforceLatestMobilePrimaryRoute);

    const mobileBottomNav = document.querySelector('.openap-mobile-bottom-nav[data-active-index]');
    if (mobileBottomNav && isMobilePrimaryNavigation()) {
        const activeIndex = Number(mobileBottomNav.dataset.activeIndex);
        try {
            const previousIndex = Number(sessionStorage.getItem(mobileNavIndexKey));
            if (Number.isInteger(previousIndex) && previousIndex >= 0 && previousIndex <= 2 && previousIndex !== activeIndex) {
                mobileBottomNav.style.setProperty('--openap-mobile-active', previousIndex);
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        mobileBottomNav.classList.add('is-sliding');
                        mobileBottomNav.style.setProperty('--openap-mobile-active', activeIndex);
                    });
                });
            }
            sessionStorage.setItem(mobileNavIndexKey, String(activeIndex));
        } catch (error) {
            mobileBottomNav.style.setProperty('--openap-mobile-active', activeIndex);
        }
    }

    document.querySelectorAll('.openap-mobile-bottom-link[href]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            const destination = new URL(link.href, window.location.href);
            if (destination.origin !== window.location.origin || destination.href === window.location.href) {
                return;
            }
            event.preventDefault();
            try {
                sessionStorage.setItem(mobilePrimaryRouteKey, destination.pathname);
                const currentNav = link.closest('.openap-mobile-bottom-nav');
                if (currentNav) {
                    sessionStorage.setItem(mobileNavIndexKey, currentNav.dataset.activeIndex || '0');
                }
            } catch (error) {
                // Continue with replacement navigation when storage is blocked.
            }
            window.location.replace(destination.href);
        });
    });

    // Reproduce the mockup's subtle upward page entrance, but only after a
    // real sidebar navigation. OpenAP renders server-side pages, so a short
    // session flag carries the transition intent across the page load.
    const pageTransitionKey = 'openap|sidebar-page-transition';
    const pageContent = document.querySelector('#layoutSidenav_content main > .container-fluid');
    try {
        const supportsCrossDocumentTransition = 'onpageswap' in window;
        if (pageContent && !supportsCrossDocumentTransition && sessionStorage.getItem(pageTransitionKey) === '1') {
            sessionStorage.removeItem(pageTransitionKey);
            pageContent.classList.add('openap-page-enter');
        } else if (supportsCrossDocumentTransition) {
            sessionStorage.removeItem(pageTransitionKey);
        }
    } catch (error) {
        // Storage can be unavailable in hardened/private browser contexts.
    }

    const sidebarNav = document.querySelector('.sb-sidenav-menu .nav');
    const sidebarIndicator = sidebarNav?.querySelector('.openap-sidebar-indicator');
    const sidebarItems = sidebarNav ? Array.from(sidebarNav.querySelectorAll(':scope > .sb-nav-link-icon[data-openap-sidebar-index]')) : [];
    const sidebarIndexKey = 'openap|sidebar-active-index';
    const reducedNavigationMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    const moveSidebarIndicator = (item, animate = true) => {
        if (!sidebarNav || !sidebarIndicator || !item) return;
        sidebarNav.classList.toggle('is-sliding', animate && !reducedNavigationMotion.matches);
        sidebarIndicator.style.setProperty('--openap-sidebar-top', `${item.offsetTop}px`);
        sidebarIndicator.style.setProperty('--openap-sidebar-height', `${item.offsetHeight}px`);
        sidebarIndicator.classList.add('is-ready');
        sidebarItems.forEach((candidate) => candidate.classList.toggle('active', candidate === item));
    };

    const currentSidebarItem = sidebarItems.find((item) => {
        const link = item.querySelector('a.nav-link[href]');
        return link && new URL(link.href, window.location.href).pathname === window.location.pathname;
    });
    if (currentSidebarItem) {
        let previousSidebarItem = null;
        try {
            const previousIndex = Number(sessionStorage.getItem(sidebarIndexKey));
            previousSidebarItem = Number.isInteger(previousIndex) ? sidebarItems[previousIndex] : null;
        } catch (error) {
            // Start directly at the active item when storage is unavailable.
        }
        if (previousSidebarItem && previousSidebarItem !== currentSidebarItem && !reducedNavigationMotion.matches) {
            moveSidebarIndicator(previousSidebarItem, false);
            requestAnimationFrame(() => requestAnimationFrame(() => moveSidebarIndicator(currentSidebarItem, true)));
        } else {
            moveSidebarIndicator(currentSidebarItem, false);
        }
        try {
            sessionStorage.setItem(sidebarIndexKey, currentSidebarItem.dataset.openapSidebarIndex || '0');
        } catch (error) {
            // The visual state does not depend on storage.
        }
    }

    document.querySelectorAll('.sb-sidenav-menu a.nav-link[href]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const destination = new URL(link.href, window.location.href);
            if (destination.origin !== window.location.origin || destination.hash || link.hasAttribute('download')) {
                return;
            }

            event.preventDefault();
            const selectedItem = link.closest('.sb-nav-link-icon[data-openap-sidebar-index]');
            moveSidebarIndicator(selectedItem, true);

            try {
                sessionStorage.setItem(pageTransitionKey, '1');
                sessionStorage.setItem(sidebarIndexKey, selectedItem?.dataset.openapSidebarIndex || '0');
            } catch (error) {
                // Navigation must continue even when storage is unavailable.
            }

            const mobileSidebar = window.matchMedia('(max-width: 991.98px)').matches;
            const animationDelay = reducedNavigationMotion.matches ? 0 : 340;
            const closeDelay = mobileSidebar && !reducedNavigationMotion.matches ? 180 : 0;
            window.setTimeout(() => {
                if (mobileSidebar) {
                    document.body.classList.remove('sb-sidenav-toggled');
                    document.documentElement.classList.remove('openap-mobile-sidebar-open');
                    try {
                        localStorage.setItem('openap|mobile-sidebar-open', 'false');
                    } catch (error) {
                        // Closing the drawer must not depend on storage.
                    }
                }
                window.setTimeout(() => {
                    if (destination.href !== window.location.href) {
                        window.location.assign(destination.href);
                    }
                }, closeDelay);
            }, animationDelay);
        });
    });

    let hostapdPageInitialized = false;
    const initializeHostapdPage = () => {
        if (hostapdPageInitialized) return;
        initHostapd();
        initHostapd_ajax();
        hostapdPageInitialized = true;
    };

    // Initialize the appropriate module based on the current path
    const path = window.location.pathname;
    console.log(`Current path: ${path}`);
    switch (path) {
        case '/dashboard':
        case '/':
            // initDashboard();
            break;
        case '/hostapd_conf':
        case '/hostapd_conf/':
            initializeHostapdPage();
            break;
        case '/system_info':
            initSystem();
            initSystem_ajax();
            break;
        case '/login':
            initLogin();
            break;
        default:
            console.warn(`No initialization function defined for path: ${path}`);
    }

    // Rewritten or prefixed routes may not expose /hostapd_conf verbatim.
    // The form itself is the reliable signal that hotspot controls are present.
    if (document.querySelector('#cbxopenapband, #cbxhwmode')) {
        initializeHostapdPage();
    }

    // --------- Global initialization ---------
    initSession_ajax();
    $(document).ajaxSend(setCSRFTokenHeader);
    globalThis.getCookie = getCookie;
    globalThis.setCookie = setCookie;
    globalThis.disableValidation = disableValidation;

    // Enable Bootstrap tooltips
    $('[data-bs-toggle="tooltip"]').tooltip()

    // Allows closing of sidebar when content overlay is clicked
    $(document).on('click', '.sb-sidenav-toggled #layoutSidenav_content', function() {
        // Only apply on mobile style nav
        if (window.innerWidth < 992) {
            $('#sidebarToggle').trigger('click');
        }
    });

    // Sets focus on a specified tab
    jQuery(function() {
        // Store hash in URL
        $('a[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
            var hash = $(e.target).attr('href');
            history.pushState(null, null, hash);
        });

        // Activate tab based on URL hash
        var hash = window.location.hash;
        if (hash) {
            $('.nav-link[href="' + hash + '"]').tab('show');
        }
    });

    // Event listener for Bootstrap's form validation
    window.addEventListener('load', function() {
        // Fetch all the forms we want to apply custom Bootstrap validation styles to
        var forms = document.getElementsByClassName('needs-validation');
        // Loop over them and prevent submission
        var validation = Array.prototype.filter.call(forms, function(form) {
            form.addEventListener('submit', function(event) {
            if (form.checkValidity() === false) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
            }, false);
        });
    }, false);

    // Input masks
    $('.ip_address').mask('0ZZ.0ZZ.0ZZ.0ZZ', {
        translation: {
            'Z': {
                pattern: /[0-9]/, optional: true
            }
        },
        placeholder: "___.___.___.___"
    });
    $('.mac_address').mask('FF:FF:FF:FF:FF:FF', {
        translation: {
            'F': {
                pattern: /[0-9a-fA-F]/, optional: false
            }
        },
        placeholder: "__:__:__:__:__:__"
    });
    $('.cidr').mask('099.099.099.099/099', {
        translation: {
            '0': { pattern: /[0-9]/ }
        },
        placeholder: "___.___.___.___/___"
    });

    // Show hide password functionality
    $(document).on("click", ".js-toggle-password", function(e) {
        var button = $(e.currentTarget);
        var field  = $(button.data("bsTarget"));

        if (field.is(":input")) {
            e.preventDefault();

            if (!button.data("__toggle-with-initial")) {
                $("i", button).removeClass("fas fa-eye").addClass(button.attr("data-toggle-with")); 
            }

            if (field.attr("type") === "password") {
                field.attr("type", "text");
            } else {
                $("i", button).removeClass("fas fa-eye-slash").addClass("fas fa-eye");
                field.attr("type", "password");
            }
        }
    });

    // Static Array method
    Array.range = (start, end) => Array.from({length: (end - start)}, (v, k) => k + start);


    const systemModeToggle = $('.system-mode-toggle');
    const darkModeToggle = $('.dark-mode-toggle');
    darkModeToggle.on('change', function() {
        const isChecked = $(this).is(':checked');
        if (isChecked) {
            setDarkMode();
        } else {
            setLightMode();
        }
        systemModeToggle.removeClass('active').prop('checked', false);
        setCookie('use_system_color_scheme', 'false', 365);
    });

    // Update color mode on system preference change if set to use system preference
    const preferredColorScheme = window.matchMedia('(prefers-color-scheme: dark)');
    const systemColorScheme = preferredColorScheme.matches ? 'dark' : 'light';
    setCookie('system_color_scheme', systemColorScheme, 365);
    preferredColorScheme.addEventListener('change', function(event) {
        const useSystem = getCookie('use_system_color_scheme') === 'true';
        if (event.matches) {
            if (useSystem) setDarkMode(true);
            setCookie('system_color_scheme', 'dark', 365);
        } else {
            if (useSystem) setLightMode(true);
            setCookie('system_color_scheme', 'light', 365);
            
        }
    });
    
    systemModeToggle.on('click', function() {
        const systemColorScheme = preferredColorScheme.matches ? 'dark' : 'light';
        // update cookie for PHP context
        setCookie('system_color_scheme', systemColorScheme, 365);

        const isButton = $(this).hasClass('btn');
        const useSystem = getCookie('use_system_color_scheme') === 'true' || false;

        if (useSystem) {
            setCookie('use_system_color_scheme', 'false', 365);
            const userTheme = getCookie('theme_mode') || 'light';
            if (userTheme === 'dark') {
                setDarkMode();
            } else {
                setLightMode();
            }
            // Update state and sync System->Theme toggle
            if (isButton) {
                $(this).removeClass('active');
                $('#settings-system-mode').prop('checked', false);
            } else {
                $(this).prop('checked', false);
                $('#navbar-system-mode').removeClass('active');
            }
        } else {
            setCookie('use_system_color_scheme', 'true', 365);
            if (systemColorScheme === 'dark') {
                setDarkMode(true);
            } else {
                setLightMode(true);
            }
            // Update state and sync System->Theme toggle
            if (isButton) {
                $(this).addClass('active');
                $('#settings-system-mode').prop('checked', true);
            } else {
                $(this).prop('checked', true);
                $('#navbar-system-mode').addClass('active');
            }
        }
    });

    // Handle stacking of multiple Bootstrap modals
    $(document).on('show.bs.modal', '.modal', function () {
        // Calculate increasing z-index based on how many modals are currently visible
        // 1050 is Bootstrap's base modal z-index
        const zIndex = 1050 + 10 * $('.modal:visible').length;

        $(this).css('z-index', zIndex);

        // Give the backdrop a slightly lower z-index and mark it as stacked
        // Small delay ensures Bootstrap has created the backdrop
        setTimeout(() => {
            $('.modal-backdrop').not('.modal-stack')
            .css('z-index', zIndex - 1)
            .addClass('modal-stack');
        }, 10);
    });

    // To auto-close Bootstrap alerts; time is in milliseconds
    const alertTimeout = parseInt(getCookie('alert_timeout'), 10);
    window.setTimeout(
        function() {
            $(".alert").not(".openap-persistent-alert").fadeTo(500, 0).slideUp(500, function(){
                $(this).remove();
            });
        },
        !isNaN(alertTimeout) && alertTimeout > 0 ? alertTimeout : 5000
    );
});
