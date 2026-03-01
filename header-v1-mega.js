(() => {
    const desktopQuery = window.matchMedia('(min-width: 1321px)');
    const normalizeEscapedHyphenClass = (className) => className.replace(/\\?u002d/gi, '-');

    const getPanelKeyFromItem = (item) => {
        const directLink = item.querySelector(':scope > a[data-acasa-panel-key]');
        if (directLink && directLink.dataset.acasaPanelKey) {
            return directLink.dataset.acasaPanelKey;
        }

        for (const className of item.classList) {
            if (className.indexOf('acasa-panel-key-') === 0) {
                return className.replace('acasa-panel-key-', '');
            }
        }

        return '';
    };

    const getPanelKeyFromPanel = (panel) => {
        for (const className of panel.classList) {
            const normalizedClass = normalizeEscapedHyphenClass(className);
            if (normalizedClass.indexOf('acasa-mega-panel--') === 0) {
                return normalizedClass.replace('acasa-mega-panel--', '');
            }

            // Accept both legacy and manually corrected class patterns:
            // - acasa-mega-panel--programe
            // - acasa-mega-panel-programe
            if (
                normalizedClass.indexOf('acasa-mega-panel-') === 0 &&
                normalizedClass !== 'acasa-mega-panel' &&
                normalizedClass.indexOf('acasa-mega-panel__') !== 0
            ) {
                return normalizedClass.replace('acasa-mega-panel-', '');
            }
        }

        return '';
    };

    const initNavigation = (nav) => {
        if (!desktopQuery.matches) {
            return;
        }

        const shell = nav.parentElement ? nav.parentElement.querySelector(':scope > .acasa-mega-shell') : null;
        if (!shell) {
            return;
        }

        const panels = Array.from(shell.querySelectorAll('.acasa-mega-panel'));
        if (!panels.length) {
            return;
        }

        const panelMap = new Map();
        panels.forEach((panel) => {
            const key = getPanelKeyFromPanel(panel);
            if (key) {
                panelMap.set(key, panel);
            }
        });

        const topItems = Array.from(nav.querySelectorAll(':scope > ul > li'));
        const activatableItems = [];

        topItems.forEach((item) => {
            const key = getPanelKeyFromItem(item);
            if (!key || !panelMap.has(key)) {
                return;
            }

            item.classList.add('acasa-has-mega-panel');
            item.setAttribute('data-acasa-panel-key', key);
            activatableItems.push(item);
        });

        if (!activatableItems.length) {
            return;
        }

        let activeKey = '';

        const clearActive = () => {
            activeKey = '';
            nav.classList.remove('acasa-mega-open');
            shell.classList.remove('acasa-mega-open');

            activatableItems.forEach((item) => item.classList.remove('acasa-panel-active'));
            panels.forEach((panel) => panel.classList.remove('is-active'));
        };

        const setActive = (key) => {
            if (!key || !panelMap.has(key)) {
                clearActive();
                return;
            }

            if (activeKey === key) {
                return;
            }

            activeKey = key;
            nav.classList.add('acasa-mega-open');
            shell.classList.add('acasa-mega-open');

            activatableItems.forEach((item) => {
                item.classList.toggle('acasa-panel-active', item.getAttribute('data-acasa-panel-key') === key);
            });

            panels.forEach((panel) => panel.classList.remove('is-active'));
            panelMap.get(key).classList.add('is-active');
        };

        activatableItems.forEach((item) => {
            item.addEventListener('mouseenter', () => setActive(item.getAttribute('data-acasa-panel-key') || ''));
            item.addEventListener('focusin', () => setActive(item.getAttribute('data-acasa-panel-key') || ''));
        });

        const scope = nav.closest('.inside-navigation') || nav.parentElement;
        if (scope) {
            scope.addEventListener('mouseleave', clearActive);
            scope.addEventListener('focusout', (event) => {
                const nextTarget = event.relatedTarget;
                if (!(nextTarget instanceof Node) || !scope.contains(nextTarget)) {
                    clearActive();
                }
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                clearActive();
            }
        });

        const previewKey = new URL(window.location.href).searchParams.get('acasaMega');
        if (previewKey) {
            setActive(previewKey);
        }
    };

    const bootstrap = () => {
        const navigations = document.querySelectorAll('.site-header #site-navigation .main-nav.gb-navigation');
        navigations.forEach((nav) => initNavigation(nav));
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
