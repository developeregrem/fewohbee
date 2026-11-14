/**
 * Calls handler(dx, dy, target, startState) for each one-finger swipe on the
 * element that is reasonably quick, so slow drags are not taken for a
 * gesture. startState is what onStart(event) returned when the finger went down.
 */
export function onSwipe(element, signal, handler, onStart = null) {
    if (!element) {
        return;
    }
    let touchStart = null;
    element.addEventListener('touchstart', (event) => {
        touchStart = event.touches.length === 1
            ? { x: event.touches[0].clientX, y: event.touches[0].clientY, time: Date.now(), target: event.target, state: onStart?.(event) }
            : null;
    }, { passive: true, signal });
    element.addEventListener('touchend', (event) => {
        if (!touchStart || event.changedTouches.length !== 1) {
            return;
        }
        const dx = event.changedTouches[0].clientX - touchStart.x;
        const dy = event.changedTouches[0].clientY - touchStart.y;
        const quick = Date.now() - touchStart.time <= 800;
        const { target, state } = touchStart;
        touchStart = null;
        if (quick) {
            handler(dx, dy, target, state);
        }
    }, { passive: true, signal });
}

/**
 * A mostly sideways swipe: 'forward' for right to left, 'backward' for left
 * to right, otherwise null.
 */
export function horizontalSwipeDirection(dx, dy) {
    if (Math.abs(dx) >= 60 && Math.abs(dx) >= 1.5 * Math.abs(dy)) {
        return dx < 0 ? 'forward' : 'backward';
    }
    return null;
}
