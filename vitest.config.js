import { defineConfig } from 'vitest/config';

// Unit tests for the softphone's state / reconciliation / media-lifecycle logic,
// run with browser APIs mocked. They do NOT prove real WebRTC connectivity — that
// needs a real Meta number, a real browser and a real network (Build Step 7).
export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.js'],
    },
});
