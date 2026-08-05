/*!
    * Start Bootstrap - SB Admin v7.0.7 (https://startbootstrap.com/template/sb-admin)
    * Copyright 2013-2023 Start Bootstrap
    * Licensed under MIT (https://github.com/StartBootstrap/startbootstrap-sb-admin/blob/master/LICENSE)
    */
    // 
// Scripts
// 

window.addEventListener('DOMContentLoaded', event => {

    // Toggle the side navigation
    const sidebarToggle = document.body.querySelector('#sidebarToggle');
    if (sidebarToggle) {
        const mobileSidebarQuery = window.matchMedia('(max-width: 991.98px)');
        const mobileSidebarKey = 'openap|mobile-sidebar-open';
        const applyMobileSidebarState = () => {
            if (!mobileSidebarQuery.matches) {
                document.documentElement.classList.remove('openap-mobile-sidebar-open');
                return;
            }
            document.body.classList.toggle(
                'sb-sidenav-toggled',
                localStorage.getItem(mobileSidebarKey) === 'true'
            );
            document.documentElement.classList.remove('openap-mobile-sidebar-open');
        };

        applyMobileSidebarState();
        sidebarToggle.addEventListener('click', event => {
            event.preventDefault();
            document.body.classList.toggle('sb-sidenav-toggled');
            if (mobileSidebarQuery.matches) {
                localStorage.setItem(
                    mobileSidebarKey,
                    document.body.classList.contains('sb-sidenav-toggled') ? 'true' : 'false'
                );
            }
        });

        const closeMobileSidebar = () => {
            if (!mobileSidebarQuery.matches) {
                return;
            }
            document.body.classList.remove('sb-sidenav-toggled');
            document.documentElement.classList.remove('openap-mobile-sidebar-open');
            try {
                localStorage.setItem(mobileSidebarKey, 'false');
            } catch (error) {
                // The visual close must still work when storage is unavailable.
            }
        };

        mobileSidebarQuery.addEventListener('change', event => {
            if (event.matches) {
                applyMobileSidebarState();
            } else {
                document.body.classList.remove('sb-sidenav-toggled');
            }
        });
    }

});
