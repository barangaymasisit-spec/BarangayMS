<?php

function renderToast(string $text, string $type = 'success'): void {
    if (!in_array($type, ['success', 'error', 'info'], true)) {
        throw new InvalidArgumentException('Unsupported toast type.');
    }
    $role = $type === 'error' ? 'alert' : 'status';
    echo '<div class="toast-viewport" role="' . $role . '" aria-live="' . ($type === 'error' ? 'assertive' : 'polite') . '" aria-atomic="false">';
    echo '<div class="app-toast app-toast-' . $type . '" data-toast>';
    echo '<span class="app-toast-icon" aria-hidden="true"></span>';
    echo '<span class="app-toast-message">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
    echo '<button type="button" class="app-toast-close" aria-label="Dismiss notification">×</button>';
    echo '</div></div>';
}
