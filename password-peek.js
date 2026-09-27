// Desktop version of the phone behaviour: the last typed character shows for a
// moment before turning into a dot. Phones already do this natively, so only
// run on mouse/trackpad devices. The real <input type="password"> keeps the
// value; a mask span drawn on top only changes what is displayed while focused.
(function () {
    if (!window.matchMedia || !window.matchMedia('(pointer: fine)').matches) return;

    const DOT = /firefox/i.test(navigator.userAgent) ? '●' : '•';
    const PEEK_MS = 1000;

    document.querySelectorAll('input[type="password"]').forEach(function (input) {
        const parent = input.parentElement;
        if (getComputedStyle(parent).position === 'static') parent.style.position = 'relative';

        const mask = document.createElement('span');
        const text = document.createElement('span');
        mask.setAttribute('aria-hidden', 'true');
        mask.style.cssText = 'position:absolute;display:none;align-items:center;overflow:hidden;white-space:pre;pointer-events:none;';
        mask.appendChild(text);
        input.insertAdjacentElement('afterend', mask);

        // Our own caret: the browser's caret is placed by dot widths, so it lands
        // inside a peeked letter (letters are wider than dots).
        const caret = document.createElement('span');
        caret.style.cssText = 'display:inline-block;width:1px;height:1.15em;margin-right:-1px;vertical-align:middle;background:currentColor;animation:pw-peek-blink 1s step-end infinite;';
        if (!document.getElementById('pw-peek-style')) {
            const style = document.createElement('style');
            style.id = 'pw-peek-style';
            style.textContent = '@keyframes pw-peek-blink{50%{opacity:0}}';
            document.head.appendChild(style);
        }

        let peekIndex = -1;
        let timer = null;
        let ink = '';

        function render() {
            const active = document.activeElement === input && input.type === 'password' && input.value !== '';
            if (!active) {
                mask.style.display = 'none';
                input.style.color = '';
                input.style.caretColor = '';
                ink = '';
                return;
            }
            const cs = getComputedStyle(input);
            if (!ink) ink = cs.color;
            const padL = parseFloat(cs.paddingLeft);
            mask.style.left = (input.offsetLeft + input.clientLeft + padL) + 'px';
            mask.style.top = (input.offsetTop + input.clientTop) + 'px';
            mask.style.width = (input.clientWidth - padL - parseFloat(cs.paddingRight)) + 'px';
            mask.style.height = input.clientHeight + 'px';
            mask.style.font = cs.font;
            mask.style.letterSpacing = cs.letterSpacing;
            mask.style.color = ink;
            mask.style.display = 'flex';

            const v = input.value;
            const shown = peekIndex >= 0 && peekIndex < v.length
                ? DOT.repeat(peekIndex) + v[peekIndex] + DOT.repeat(v.length - peekIndex - 1)
                : DOT.repeat(v.length);
            const at = input.selectionStart;
            text.textContent = shown.slice(0, at);
            if (at === input.selectionEnd) {
                caret.style.animation = 'none';
                void caret.offsetWidth; // restart blink so the caret is solid while typing
                caret.style.animation = 'pw-peek-blink 1s step-end infinite';
                text.appendChild(caret);
            }
            text.appendChild(document.createTextNode(shown.slice(at)));
            text.style.transform = 'translateX(' + (-input.scrollLeft) + 'px)';

            input.style.color = 'transparent';
            input.style.caretColor = 'transparent';
        }

        input.addEventListener('input', function (e) {
            clearTimeout(timer);
            peekIndex = e.inputType === 'insertText' && e.data && e.data.length === 1 ? input.selectionStart - 1 : -1;
            if (peekIndex >= 0) {
                timer = setTimeout(function () { peekIndex = -1; render(); }, PEEK_MS);
            }
            requestAnimationFrame(render);
        });
        ['focus', 'blur', 'keydown', 'keyup', 'click', 'select', 'scroll'].forEach(function (ev) {
            input.addEventListener(ev, function () {
                if (ev === 'blur') peekIndex = -1;
                requestAnimationFrame(render);
            });
        });
        // The show/hide eye button flips input.type; re-render when it does.
        new MutationObserver(render).observe(input, { attributes: true, attributeFilter: ['type'] });
    });
})();
