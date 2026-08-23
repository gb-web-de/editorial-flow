import { defineConfig, devices } from '@playwright/test'

/*
 * Dev-only E2E harness, exercising the board and wizard flows as a blackbox
 * against a real, DDEV-served backend. Nothing here builds or bundles the
 * extension's own JavaScript - it stays plain ES modules loaded via TYPO3's
 * importmap.
 *
 * Point EDITORIALFLOW_BASE_URL at whichever DDEV instance is running for the
 * checkout under test (`ddev describe` prints its URL) - the project name in
 * .ddev/config.yaml isn't unique across worktrees, so no fixed default is
 * assumed here.
 */
export const authFile = '.Build/playwright/backend-auth.json'

/**
 * The demo roles a spec can run as, from `editorialflow:democontent`. Not all
 * nine: these are the ones whose permissions differ in a way a spec asserts on.
 */
export const demoUsers = ['editor', 'reviewer', 'approver', 'observer'] as const

export const authFileFor = (user: string): string => `.Build/playwright/backend-auth-${user}.json`

/**
 * Everything a write-enabled run creates carries this in its title, so the
 * teardown project can find its own leftovers and nothing else. One value per
 * run - two runs against the same instance must not clean up after each other.
 *
 * Written back into the environment rather than kept as a module constant,
 * because this config is re-imported in every worker process Playwright forks.
 * A plain `Date.now()` therefore produced a DIFFERENT id in the teardown worker
 * than in the one that created the cards - the cleanup ran, found nothing that
 * matched, and reported success while leaving every card behind. Workers inherit
 * the parent's environment, so `??=` is what makes the whole run agree.
 */
process.env.EDITORIALFLOW_RUN_ID ??= `e2e-${Date.now()}`
export const runId = process.env.EDITORIALFLOW_RUN_ID

export default defineConfig({
  testDir: './Tests/Playwright',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: process.env.EDITORIALFLOW_BASE_URL ?? 'https://editorial-flow.ddev.site',
    trace: 'retain-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
    },
    {
      // storageState belongs here rather than in the top-level `use`: applied
      // globally it is also handed to the setup project, which then fails
      // trying to read the very file it exists to write.
      name: 'chromium',
      testIgnore: /write\//,
      use: { ...devices['Desktop Chrome'], storageState: authFile },
      dependencies: ['setup'],
    },
    {
      /*
       * The specs that write. Kept in a project of their own so the read-only
       * ones above stay exactly that - a run that only wants to know whether
       * the board renders should not leave anything behind on the installation
       * it was pointed at.
       *
       * `teardown` is Playwright's own hook for "run this project afterwards,
       * pass or fail", which is what makes the cleanup unconditional.
       */
      name: 'chromium-write',
      testMatch: /write\/(?!teardown).*\.spec\.ts/,
      use: { ...devices['Desktop Chrome'], storageState: authFile },
      dependencies: ['setup'],
      teardown: 'cleanup',
    },
    {
      name: 'cleanup',
      testMatch: /write\/teardown\.spec\.ts/,
      use: { ...devices['Desktop Chrome'], storageState: authFile },
    },
  ],
})
