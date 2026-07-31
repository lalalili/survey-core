import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    // 只掃 tests/js；vendor/ 與 node_modules/ 底下的套件自帶測試不納入。
    include: ['tests/js/**/*.test.js'],
    environment: 'node',
  },
});
