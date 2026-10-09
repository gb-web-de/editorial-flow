/*
 * Stands in for @typo3/backend/utility/top-level-module-import.js. Only reached
 * from inside a frame; jsdom's window is its own top, so this records that it
 * was not used where it should not be.
 */
export const calls = []

export async function topLevelModuleImport(specifier) {
  calls.push(specifier)
}
