/*
 * Stands in for @typo3/backend/element/qrcode-element.js. preview-link.js only
 * imports it to have <typo3-qrcode> defined; the element itself fetches its
 * image from the backend, which a test has no use for - the attributes it is
 * given are what preview-link.js decides.
 */
export const imported = { count: 0 }

imported.count++
