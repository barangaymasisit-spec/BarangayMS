document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (event) {
        const target = event.target;
        const logoutLink = target instanceof Element ? target.closest('a[data-confirm]') : null;
        if (logoutLink && !window.confirm(logoutLink.dataset.confirm || 'Are you sure?')) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    const currentUrl = new URL(window.location.href);
    if (currentUrl.searchParams.get('csrf_recovered') === '1') {
        currentUrl.searchParams.delete('csrf_recovered');
        history.replaceState({}, '', currentUrl.toString());
    }

    function updateClock() {
        const now = new Date();
        const currentDate = document.getElementById('currentDate');
        const currentTime = document.getElementById('currentTime');
        if (!currentDate || !currentTime) {
            return;
        }
        const optionsDate = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        const optionsTime = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
        currentDate.textContent = now.toLocaleDateString('en-US', optionsDate);
        currentTime.textContent = now.toLocaleTimeString('en-US', optionsTime);
    }

    updateClock();
    setInterval(updateClock, 1000);

    const mobileSidebarToggle = document.querySelector('.mobile-sidebar-toggle');
    const sidebarBackdrop = document.querySelector('.sidebar-backdrop');
    const closeMobileSidebar = function () {
        document.body.classList.remove('sidebar-open');
        if (mobileSidebarToggle) {
            mobileSidebarToggle.setAttribute('aria-expanded', 'false');
        }
    };
    const openMobileSidebar = function () {
        document.body.classList.add('sidebar-open');
        if (mobileSidebarToggle) {
            mobileSidebarToggle.setAttribute('aria-expanded', 'true');
        }
    };

    if (mobileSidebarToggle) {
        mobileSidebarToggle.addEventListener('click', function () {
            const isOpen = document.body.classList.contains('sidebar-open');
            if (isOpen) {
                closeMobileSidebar();
            } else {
                openMobileSidebar();
            }
        });
    }

    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', closeMobileSidebar);
    }

    document.querySelectorAll('.sidebar a[href]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (this.getAttribute('href') && this.getAttribute('href') !== '#') {
                sessionStorage.setItem('sidebarScrollY', String(window.scrollY));
                const sidebar = this.closest('.sidebar');
                if (sidebar) {
                    sessionStorage.setItem('sidebarMenuScroll', String(sidebar.scrollTop));
                }
                sessionStorage.setItem('sidebarTarget', this.getAttribute('href'));
                if (window.innerWidth <= 768) {
                    closeMobileSidebar();
                }
            }
        });
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) {
            closeMobileSidebar();
        }
    });

    document.querySelectorAll('a[href]').forEach(function (link) {
        const href = link.getAttribute('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || link.target === '_blank') {
            return;
        }

        const linkUrl = new URL(href, window.location.href);
        if (linkUrl.origin !== window.location.origin || linkUrl.pathname !== window.location.pathname) {
            return;
        }

        link.addEventListener('click', function () {
            sessionStorage.setItem('pageScrollY', String(window.scrollY));
            sessionStorage.setItem('pageScrollPath', window.location.pathname);
        });
    });

    const savedTarget = sessionStorage.getItem('sidebarTarget');
    const savedScroll = Number(sessionStorage.getItem('sidebarScrollY') || 0);
    const currentPage = window.location.pathname.split('/').pop() || '';
    const shouldRestoreSidebarScroll = !!savedTarget && currentPage === savedTarget.replace(/^\.\//, '') && window.location.search === '';

    if (shouldRestoreSidebarScroll) {
        window.scrollTo({ top: savedScroll, left: 0, behavior: 'auto' });
    }

    const pageScrollPath = sessionStorage.getItem('pageScrollPath');
    const pageScrollY = Number(sessionStorage.getItem('pageScrollY') || 0);
    // A #anchor in the URL (e.g. Edit -> #personnel-form) wins over the saved position.
    if (pageScrollPath === window.location.pathname && pageScrollY > 0 && !window.location.hash) {
        window.scrollTo({ top: pageScrollY, left: 0, behavior: 'auto' });
    }

    // Keep the sidebar's own scroll position across page loads (zoomed / short screens).
    const sidebarMenuScroll = sessionStorage.getItem('sidebarMenuScroll');
    const sidebarEl = document.querySelector('.sidebar');
    if (sidebarEl && sidebarMenuScroll !== null) {
        sidebarEl.scrollTop = Number(sidebarMenuScroll);
    }
    sessionStorage.removeItem('sidebarMenuScroll');

    sessionStorage.removeItem('sidebarTarget');
    sessionStorage.removeItem('sidebarScrollY');
    sessionStorage.removeItem('pageScrollPath');
    sessionStorage.removeItem('pageScrollY');

    document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function (form) {
        if (!form.querySelector('input[name="csrf_token"]')) {
            return;
        }
        form.addEventListener('submit', async function (event) {
            if (form.dataset.csrfRefreshing === '1') {
                return;
            }
            event.preventDefault();
            form.dataset.csrfRefreshing = '1';
            try {
                const response = await fetch('csrf_token.php', {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) {
                    throw new Error('Unable to refresh form token');
                }
                const data = await response.json();
                const tokenInput = form.querySelector('input[name="csrf_token"]');
                if (!data.csrf_token || !tokenInput) {
                    throw new Error('Invalid form token response');
                }
                tokenInput.value = data.csrf_token;
                // form.submit() drops the clicked button's name/value, so carry it over.
                const submitter = event.submitter;
                if (submitter && submitter.name && !form.querySelector('input[type="hidden"][data-submitter]')) {
                    const carried = document.createElement('input');
                    carried.type = 'hidden';
                    carried.name = submitter.name;
                    carried.value = submitter.value;
                    carried.dataset.submitter = '1';
                    form.appendChild(carried);
                }
                form.submit();
            } catch (error) {
                form.dataset.csrfRefreshing = '0';
                alert('Your session token could not be refreshed. Please reload the page and try again.');
            }
        });
    });

    const residentPhotoInput = document.getElementById('residentPhoto');
    const residentPreview = document.getElementById('previewImage');
    if (residentPhotoInput && residentPreview) {
        residentPhotoInput.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file) {
                return;
            }
            const previewUrl = URL.createObjectURL(file);
            residentPreview.src = previewUrl;
            residentPreview.onload = function () {
                URL.revokeObjectURL(previewUrl);
            };
        });
    }

    document.querySelectorAll('input.branding-upload').forEach(function (input) {
        const preview = document.getElementById(input.dataset.preview);
        input.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file || !preview) {
                return;
            }
            const previewUrl = URL.createObjectURL(file);
            preview.src = previewUrl;
            preview.onload = function () {
                URL.revokeObjectURL(previewUrl);
            };
        });
    });

    document.querySelectorAll('button.js-not-implemented, a.js-not-implemented').forEach(function (element) {
        element.addEventListener('click', function (event) {
            event.preventDefault();
            alert('This feature is not yet implemented.');
        });
    });

    document.querySelectorAll('button.btn-search').forEach(function (button) {
        const form = button.closest('form');
        if (form) {
            button.addEventListener('click', function () {
                form.submit();
            });
        }
    });

    // Checked live (not a sticky flag) so a cleared or untouched form doesn't block auto-refresh forever.
    function hasUnsavedFormChanges() {
        return Array.from(document.querySelectorAll('form')).some(function (form) {
            return Array.from(form.elements).some(function (el) {
                if (el.disabled || el.readOnly || el.type === 'hidden') {
                    return false;
                }
                if (el.type === 'checkbox' || el.type === 'radio') {
                    return el.checked !== el.defaultChecked;
                }
                if (el.type === 'file') {
                    return el.files.length > 0;
                }
                if (el.tagName === 'SELECT') {
                    const defaultIndex = Array.from(el.options).findIndex(function (option) { return option.defaultSelected; });
                    return el.selectedIndex !== (defaultIndex < 0 ? 0 : defaultIndex);
                }
                return 'defaultValue' in el && el.value !== el.defaultValue;
            });
        });
    }

    let syncVersion = null;
    let syncCheckInProgress = false;

    async function checkForApplicationUpdates() {
        if (document.hidden || syncCheckInProgress || hasUnsavedFormChanges()) {
            return;
        }

        syncCheckInProgress = true;
        try {
            const response = await fetch('sync_version.php?_=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            if (!response.ok) {
                return;
            }

            const data = await response.json();
            if (syncVersion === null) {
                syncVersion = data.version || '';
            } else if (data.version && data.version !== syncVersion) {
                window.location.reload();
            }
        } catch (error) {
            // A temporary network failure should not interrupt the current page.
        } finally {
            syncCheckInProgress = false;
        }
    }

    checkForApplicationUpdates();
    setInterval(checkForApplicationUpdates, 5000);
    document.addEventListener('visibilitychange', checkForApplicationUpdates);

    const birthDateInput = document.getElementById('birthDate') || document.getElementById('birthdate');
    const ageInput = document.getElementById('age');
    if (birthDateInput && ageInput) {
        function updateAge() {
            if (!birthDateInput.value) {
                ageInput.value = '';
                return;
            }

            const [birthYear, birthMonth, birthDay] = birthDateInput.value.split('-').map(Number);
            const today = new Date();
            let years = today.getFullYear() - birthYear;
            const birthdayPassed = today.getMonth() + 1 > birthMonth
                || (today.getMonth() + 1 === birthMonth && today.getDate() >= birthDay);

            if (!birthdayPassed) {
                years -= 1;
            }

            ageInput.value = years >= 0 ? years : '';
        }

        birthDateInput.addEventListener('input', updateAge);
        birthDateInput.addEventListener('change', updateAge);
        updateAge();
    }
});
