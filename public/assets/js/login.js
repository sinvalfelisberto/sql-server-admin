(() => {
    'use strict';

    const input = document.getElementById('pass');
    const toggle = document.getElementById('pass-toggle');
    if (!input || !toggle) return;

    // Without JavaScript the button stays hidden and the field remains a password field.
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', String(show));
        toggle.title = toggle.ariaLabel = show ? 'Hide password' : 'Show password';
        input.focus();
    });
})();
