document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-toast]').forEach(function (toast) {
        const viewport = toast.closest('.toast-viewport');
        const closeButton = toast.querySelector('.app-toast-close');
        let dismissTimer;
        let isClosing = false;

        const dismiss = function () {
            if (isClosing) {
                return;
            }
            isClosing = true;
            window.clearTimeout(dismissTimer);
            toast.classList.add('is-leaving');
            window.setTimeout(function () {
                if (viewport) {
                    viewport.remove();
                }
            }, 240);
        };
        const startDismissTimer = function () {
            window.clearTimeout(dismissTimer);
            if (isClosing || toast.matches(':hover') || toast.contains(document.activeElement)) {
                return;
            }
            dismissTimer = window.setTimeout(dismiss, 5500);
        };

        if (closeButton) {
            closeButton.addEventListener('click', dismiss);
        }
        toast.addEventListener('mouseenter', function () {
            window.clearTimeout(dismissTimer);
        });
        toast.addEventListener('mouseleave', startDismissTimer);
        toast.addEventListener('focusin', function () {
            window.clearTimeout(dismissTimer);
        });
        toast.addEventListener('focusout', function (event) {
            if (!toast.contains(event.relatedTarget)) {
                startDismissTimer();
            }
        });
        startDismissTimer();
    });
});
