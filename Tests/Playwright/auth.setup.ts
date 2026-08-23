import { test as setup, expect, type Page } from '@playwright/test'
import { mkdirSync } from 'node:fs'
import { dirname } from 'node:path'
import { authFile, authFileFor, demoUsers } from '../../playwright.config'

/*
 * Logs into the TYPO3 backend once per role and persists each session as its
 * own storageState, so a spec can say "as the reviewer" with test.use() instead
 * of logging in again (playwright.config.ts's projects pick these up).
 *
 * Several roles rather than one, because the permission spread is the point:
 * editorialflow:democontent creates users who deliberately cannot do the same
 * things, and a board that looks right as an admin says nothing about what an
 * editor sees.
 *
 * Credentials come from the environment for the admin, since they depend on
 * whichever DDEV instance EDITORIALFLOW_BASE_URL points at. The demo users do
 * not: their password is fixed by the command that creates them.
 */
async function signIn(page: Page, username: string, password: string, file: string): Promise<void> {
  await page.goto('/typo3/')

  await page.getByLabel(/username/i).fill(username)
  await page.getByLabel(/password/i).fill(password)
  await page.getByRole('button', { name: /^login$/i }).click()

  await expect(page.locator('#typo3-module-menu, .modulemenu')).toBeVisible({ timeout: 15000 })

  mkdirSync(dirname(file), { recursive: true })
  await page.context().storageState({ path: file })
}

setup('authenticate as the administrator', async ({ page }) => {
  // Defaults match the credentials the README documents for the dev instance,
  // so a run that forgets the environment fails on something real rather than
  // on a password nobody configured.
  await signIn(
    page,
    process.env.EDITORIALFLOW_BE_USER ?? 'admin',
    process.env.EDITORIALFLOW_BE_PASSWORD ?? 'Password.1',
    authFile,
  )
})

for (const user of demoUsers) {
  setup(`authenticate as ${user}`, async ({ page }) => {
    /*
     * A demo user that is not there is not a failure of this setup - an
     * installation may simply not have been seeded. The specs that need the
     * role skip on a missing state file rather than failing here, which keeps
     * "the seeding was not run" apart from "the role cannot do what it should".
     */
    try {
      await signIn(page, user, 'Password.1', authFileFor(user))
    } catch {
      setup.skip(true, `demo user "${user}" does not exist - run editorialflow:democontent`)
    }
  })
}
