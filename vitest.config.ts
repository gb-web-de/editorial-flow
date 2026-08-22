import { defineConfig } from 'vitest/config'
import { fileURLToPath } from 'node:url'

/*
 * The extension's runtime JavaScript is plain ES modules served through TYPO3's
 * importmap - there is no bundler and this config must not become one. It only
 * resolves the bare specifiers the importmap would have resolved in the browser,
 * pointing them at test doubles, so a module can be imported outside a backend.
 */
const doubles = fileURLToPath(new URL('./Tests/JavaScript/doubles', import.meta.url))

export default defineConfig({
  resolve: {
    alias: {
      '@typo3/core/ajax/ajax-request.js': `${doubles}/ajax-request.js`,
      '@typo3/backend/action-button/immediate-action.js': `${doubles}/immediate-action.js`,
      '@typo3/backend/notification.js': `${doubles}/notification.js`,
      '@typo3/backend/modal.js': `${doubles}/modal.js`,
      '@typo3/backend/enum/severity.js': `${doubles}/severity.js`,
      '~labels/editorial_flow.messages': `${doubles}/labels.js`,
      // The picker is a LitElement imported purely for its custom-element
      // registration; standing it in keeps lit out of the test environment.
      '@gb-web/editorial-flow/components/assignee-picker.js': `${doubles}/assignee-picker.js`,
      // This extension's own modules resolve to themselves - the importmap
      // maps the same prefix onto Resources/Public/JavaScript/ in the browser.
      '@gb-web/editorial-flow': fileURLToPath(new URL('./Resources/Public/JavaScript', import.meta.url)),
    },
  },
  test: {
    environment: 'jsdom',
    include: ['Tests/JavaScript/**/*.test.js'],
    restoreMocks: true,
  },
})
