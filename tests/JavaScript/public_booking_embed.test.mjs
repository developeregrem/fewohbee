import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const template = readFileSync(new URL('../../templates/Settings/OnlineBooking/index.html.twig', import.meta.url), 'utf8');
const [, scriptSource] = template.match(/{% set iframeScript %}([\s\S]*?){% endset %}/);
const script = scriptSource.split('\n').map((line) => line.trim()).join(' ').trim();
const origin = 'https://booking.example.test:8443';

function bookingHost() {
    const iframe = {
        src: `${origin}/book?embed=1`,
        contentWindow: {},
        style: { height: '' },
        getBoundingClientRect: () => ({ top: 300 }),
    };
    const scrolls = [];
    let onMessage;
    runInNewContext(script, {
        URL,
        document: { getElementById: (id) => id === 'fhb-booking' ? iframe : null },
        window: {
            addEventListener(event, listener) {
                assert.equal(event, 'message');
                onMessage = listener;
            },
            scrollY: 200,
            scrollTo({ top, behavior }) {
                scrolls.push({ top, behavior });
            },
        },
    });

    return {
        iframe,
        scrolls,
        send(data, sender = {}) {
            onMessage({ data, source: iframe.contentWindow, origin, ...sender });
        },
    };
}

test('resizes and scrolls for messages from the booking iframe', () => {
    const host = bookingHost();

    host.send({ type: 'fhb-resize', height: 1200 });
    host.send({ type: 'fhb-scroll', offset: 600 });
    host.send({ type: 'fhb-scroll' });

    assert.equal(host.iframe.style.height, '1200px');
    assert.deepEqual(host.scrolls, [{ top: 1100, behavior: 'smooth' }, { top: 500, behavior: 'smooth' }]);
});

test('ignores messages from another window or origin', () => {
    const host = bookingHost();

    for (const sender of [{ source: {} }, { origin: 'https://booking.example.test' }]) {
        host.send({ type: 'fhb-resize', height: 1200 }, sender);
        host.send({ type: 'fhb-scroll', offset: 600 }, sender);
    }

    assert.equal(host.iframe.style.height, '');
    assert.deepEqual(host.scrolls, []);
});

test('ignores invalid heights and offsets', () => {
    const host = bookingHost();

    host.send({ type: 'fhb-resize' });
    for (const value of [-1, NaN, Infinity, '600', null]) {
        host.send({ type: 'fhb-resize', height: value });
        host.send({ type: 'fhb-scroll', offset: value });
    }

    assert.equal(host.iframe.style.height, '');
    assert.deepEqual(host.scrolls, []);
});
