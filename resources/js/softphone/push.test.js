import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPushSubscriber, urlBase64ToUint8Array } from './push.js';

/*
 * WhatsApp inbound calling — Web Push opt-in. Every browser API (service
 * worker registration, PushManager, Notification) is faked, same spirit as
 * FakePeerConnection/FakeAudioContext elsewhere in this suite: these prove
 * ordering/idempotency/error-handling, not real push delivery.
 */

describe('urlBase64ToUint8Array()', () => {
    it('converts a base64url VAPID key into the raw bytes PushManager expects', () => {
        // "hello" base64url-encoded (unpadded).
        const bytes = urlBase64ToUint8Array('aGVsbG8');

        expect(Array.from(bytes)).toEqual([104, 101, 108, 108, 111]);
    });

    it('substitutes base64url\'s - and _ back to standard base64 + and /', () => {
        // Bytes [0xfb, 0xff, 0xbf] encode as standard base64 "+/+/" and as
        // base64url "-_-_" (unpadded) — proves the substitution actually runs,
        // not just padding.
        const bytes = urlBase64ToUint8Array('-_-_');

        expect(Array.from(bytes)).toEqual([0xfb, 0xff, 0xbf]);
    });
});

function fakeSubscription(endpoint = 'https://push.example/abc') {
    return {
        endpoint,
        unsubscribe: vi.fn(async () => true),
        toJSON: () => ({ endpoint, keys: { p256dh: 'p256dh-value', auth: 'auth-value' } }),
    };
}

function fakeEnv({ permission = 'default', existingSubscription = null, subscribeResult = fakeSubscription() } = {}) {
    const registration = {
        pushManager: {
            getSubscription: vi.fn(async () => existingSubscription),
            subscribe: vi.fn(async () => subscribeResult),
        },
    };

    const nav = {
        serviceWorker: {
            register: vi.fn(async () => registration),
            ready: Promise.resolve(registration),
        },
    };

    const notificationApi = {
        permission,
        requestPermission: vi.fn(async () => 'granted'),
    };

    const fetchImpl = vi.fn(async () => ({ ok: true }));

    return { nav, notificationApi, fetchImpl, registration };
}

beforeEach(() => {
    global.window = global.window || {};
    global.window.PushManager = function () {};
});

describe('createPushSubscriber()', () => {
    it('is unsupported when no VAPID public key is configured', () => {
        const { nav, notificationApi } = fakeEnv();
        const subscriber = createPushSubscriber({ nav, notificationApi, vapidPublicKey: null, urls: {} });

        expect(subscriber.supported).toBe(false);
    });

    it('is unsupported when the browser has no serviceWorker API', () => {
        const { notificationApi } = fakeEnv();
        const subscriber = createPushSubscriber({ nav: {}, notificationApi, vapidPublicKey: 'key', urls: {} });

        expect(subscriber.supported).toBe(false);
    });

    it('permission() reflects Notification.permission when supported', () => {
        const { nav, notificationApi } = fakeEnv({ permission: 'denied' });
        const subscriber = createPushSubscriber({ nav, notificationApi, vapidPublicKey: 'key', urls: {} });

        expect(subscriber.permission()).toBe('denied');
    });

    it('permission() is "unsupported" when the browser cannot do push at all', () => {
        const subscriber = createPushSubscriber({ nav: {}, notificationApi: undefined, vapidPublicKey: 'key', urls: {} });

        expect(subscriber.permission()).toBe('unsupported');
    });

    it('enable() requests permission, subscribes, and posts the subscription to the server', async () => {
        const subscription = fakeSubscription();
        const { nav, notificationApi, fetchImpl, registration } = fakeEnv({ subscribeResult: subscription });
        const subscriber = createPushSubscriber({
            nav, notificationApi, fetchImpl,
            vapidPublicKey: 'BOuZvbHPtnw9NEh5pE52CK81z31cjUmKCSmRxI0DSOdLpyOh09ToRI-B2dbVoLFFkIc7I7FMBNMcpW6RUbcudNw',
            urls: { subscribe: '/push-subscriptions' },
        });

        const result = await subscriber.enable();

        expect(result).toEqual({ ok: true, reason: null });
        expect(notificationApi.requestPermission).toHaveBeenCalledOnce();
        expect(registration.pushManager.subscribe).toHaveBeenCalledOnce();
        expect(fetchImpl).toHaveBeenCalledWith('/push-subscriptions', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify(subscription.toJSON()),
        }));
    });

    it('enable() does not re-subscribe when a subscription already exists — just re-posts it', async () => {
        const existing = fakeSubscription('https://push.example/already-subscribed');
        const { nav, notificationApi, fetchImpl, registration } = fakeEnv({ existingSubscription: existing });
        const subscriber = createPushSubscriber({ nav, notificationApi, fetchImpl, vapidPublicKey: 'key', urls: { subscribe: '/push-subscriptions' } });

        const result = await subscriber.enable();

        expect(result).toEqual({ ok: true, reason: null });
        expect(notificationApi.requestPermission).not.toHaveBeenCalled();
        expect(registration.pushManager.subscribe).not.toHaveBeenCalled();
        expect(fetchImpl).toHaveBeenCalledWith('/push-subscriptions', expect.objectContaining({ method: 'POST' }));
    });

    it('enable() reports permission-denied when the user denies the prompt', async () => {
        const { nav, notificationApi, fetchImpl } = fakeEnv();
        notificationApi.requestPermission = vi.fn(async () => 'denied');
        const subscriber = createPushSubscriber({ nav, notificationApi, fetchImpl, vapidPublicKey: 'key', urls: {} });

        const result = await subscriber.enable();

        expect(result).toEqual({ ok: false, reason: 'permission-denied' });
        expect(fetchImpl).not.toHaveBeenCalled();
    });

    it('enable() reports subscribe-failed, never throws, when pushManager.subscribe() rejects', async () => {
        const { nav, notificationApi, fetchImpl, registration } = fakeEnv();
        const subscribeError = new Error('AbortError: subscribe failed');
        registration.pushManager.subscribe = vi.fn(async () => {
            throw subscribeError;
        });
        const subscriber = createPushSubscriber({ nav, notificationApi, fetchImpl, vapidPublicKey: 'key', urls: {} });

        const result = await subscriber.enable();

        expect(result).toEqual({ ok: false, reason: 'subscribe-failed', error: subscribeError });
    });

    it('enable() reports unsupported as a no-op', async () => {
        const subscriber = createPushSubscriber({ nav: {}, notificationApi: undefined, vapidPublicKey: 'key', urls: {} });

        await expect(subscriber.enable()).resolves.toEqual({ ok: false, reason: 'unsupported' });
    });

    it('isSubscribed() reflects whether a subscription currently exists', async () => {
        const { nav, notificationApi } = fakeEnv({ existingSubscription: fakeSubscription() });
        const subscriber = createPushSubscriber({ nav, notificationApi, vapidPublicKey: 'key', urls: {} });

        await expect(subscriber.isSubscribed()).resolves.toBe(true);
    });

    it('disable() unsubscribes locally and tells the server to forget the endpoint', async () => {
        const subscription = fakeSubscription('https://push.example/to-remove');
        const { nav, notificationApi, fetchImpl } = fakeEnv({ existingSubscription: subscription });
        const subscriber = createPushSubscriber({ nav, notificationApi, fetchImpl, vapidPublicKey: 'key', urls: { unsubscribe: '/push-subscriptions' } });

        const ok = await subscriber.disable();

        expect(ok).toBe(true);
        expect(subscription.unsubscribe).toHaveBeenCalledOnce();
        expect(fetchImpl).toHaveBeenCalledWith('/push-subscriptions', expect.objectContaining({
            method: 'DELETE',
            body: JSON.stringify({ endpoint: 'https://push.example/to-remove' }),
        }));
    });

    it('disable() with no existing subscription is a safe no-op', async () => {
        const { nav, notificationApi, fetchImpl } = fakeEnv({ existingSubscription: null });
        const subscriber = createPushSubscriber({ nav, notificationApi, fetchImpl, vapidPublicKey: 'key', urls: {} });

        await expect(subscriber.disable()).resolves.toBe(true);
        expect(fetchImpl).not.toHaveBeenCalled();
    });

    it('disable() still tells the server even when the local unsubscribe() call throws', async () => {
        const subscription = fakeSubscription();
        subscription.unsubscribe = vi.fn(async () => {
            throw new Error('already gone');
        });
        const { nav, notificationApi, fetchImpl } = fakeEnv({ existingSubscription: subscription });
        const subscriber = createPushSubscriber({ nav, notificationApi, fetchImpl, vapidPublicKey: 'key', urls: { unsubscribe: '/push-subscriptions' } });

        await expect(subscriber.disable()).resolves.toBe(true);
        expect(fetchImpl).toHaveBeenCalledWith('/push-subscriptions', expect.objectContaining({ method: 'DELETE' }));
    });

    it('a network failure posting the subscription never throws', async () => {
        const { nav, notificationApi } = fakeEnv();
        const fetchImpl = vi.fn(async () => {
            throw new Error('network down');
        });
        const subscriber = createPushSubscriber({ nav, notificationApi, fetchImpl, vapidPublicKey: 'key', urls: { subscribe: '/push-subscriptions' } });

        await expect(subscriber.enable()).resolves.toEqual({ ok: true, reason: null });
    });
});
