/**
 * Alpine directives shared across the app.
 *
 * @param {import('alpinejs').Alpine} Alpine
 */
export default function registerDirectives(Alpine) {
    /**
     * x-autofocus — focuses an element on mount and re-focuses it whenever the
     * window regains focus. The POS scan field relies on this: a USB barcode
     * scanner is just a keyboard, so it only works while the field has focus.
     */
    Alpine.directive('autofocus', (el) => {
        const focus = () => el.focus({ preventScroll: true });

        requestAnimationFrame(focus);
        window.addEventListener('focus', focus);

        return () => window.removeEventListener('focus', focus);
    });

    /**
     * x-rise — a short entrance animation, optionally delayed so a row of
     * cards settles in one after another.
     *
     * The animation itself lives in app.css. It has to be a CSS animation
     * rather than a scripted one: a scripted move leaves its transform on the
     * element afterwards, and a moved element becomes the frame every `fixed`
     * child is measured from — which would pin the till's payment sheet and
     * total bar to the page instead of the screen. Reduced motion is honoured
     * in the stylesheet.
     */
    Alpine.directive('rise', (el, { expression }) => {
        const delay = Number(expression) || 0;

        if (delay > 0) {
            el.style.animationDelay = `${delay}s`;
        }

        el.classList.add('rise');
    });

    /**
     * x-tick — draws the tick on a success mark rather than flashing it on.
     *
     * The ring swells, then the tick is drawn stroke by stroke, which is what
     * makes a cashier glance at it and know the bill went through without
     * reading a word. The element must contain a `[data-ring]` and a
     * `[data-check]` SVG path; see the success-tick component.
     */
    Alpine.directive('tick', (el) => {
        const ring = el.querySelector('[data-ring]');
        const check = el.querySelector('[data-check]');

        if (! check) {
            return;
        }

        const length = check.getTotalLength();

        check.style.strokeDasharray = `${length}`;
        check.style.strokeDashoffset = `${length}`;

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            check.style.strokeDashoffset = '0';

            return;
        }

        if (ring) {
            window.motion.animate(
                ring,
                { transform: ['scale(0.6)', 'scale(1)'], opacity: [0, 1] },
                { duration: 0.28, easing: [0.34, 1.56, 0.64, 1] },
            );
        }

        window.motion.animate(
            check,
            { strokeDashoffset: [length, 0] },
            { duration: 0.32, delay: 0.14, easing: 'ease-out' },
        );
    });

    /**
     * $money — formats integer paisa for display inside Alpine expressions.
     */
    Alpine.magic('money', () => window.money.withSymbol);
}
