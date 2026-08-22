/*
 * Stands in for @typo3/core/ajax/ajax-request.js, which only exists inside the
 * backend's importmap. It records what was sent so a test can assert on the
 * request, and lets a test decide what comes back.
 */
export const recorded = {
  url: null,
  queryArguments: null,
  body: null,
}

/*
 * Every request in order, which `recorded` cannot express: it keeps the LAST
 * one only, and a flow like the close dialog's is two or three requests whose
 * order is the thing under test (the handover has to reach /attach before the
 * close, or it silently moves nothing).
 */
export const requests = []

export const behaviour = {
  resolved: {},
  rejectWith: null,
  // Answers taken in order, one per request, falling back to `resolved` once
  // exhausted. An entry may be `{ throw: … }` to reject that one request only.
  queue: [],
}

export function reset() {
  recorded.url = null
  recorded.queryArguments = null
  recorded.body = null
  requests.length = 0
  behaviour.resolved = {}
  behaviour.rejectWith = null
  behaviour.queue = []
}

export default class AjaxRequest {
  constructor(url) {
    recorded.url = url
    this.url = url
    this.queryArguments = null
  }

  withQueryArguments(queryArguments) {
    recorded.queryArguments = queryArguments
    this.queryArguments = queryArguments
    return this
  }

  async post(body) {
    recorded.body = body
    requests.push({ url: this.url, method: 'POST', body, queryArguments: this.queryArguments })

    return this.answer()
  }

  async get() {
    requests.push({ url: this.url, method: 'GET', body: null, queryArguments: this.queryArguments })

    return this.answer()
  }

  /*
   * AjaxRequest throws on any non-2xx answer, and what it throws still carries
   * the response body - which is how a caller reads the code and message the
   * server rejected with. `rejectWith` therefore stands in for both: a thrown
   * AjaxResponse, and a genuine transport failure.
   */
  async answer() {
    if (behaviour.rejectWith !== null) {
      throw behaviour.rejectWith
    }

    if (behaviour.queue.length > 0) {
      const next = behaviour.queue.shift()
      if (next !== null && typeof next === 'object' && 'throw' in next) {
        throw next.throw
      }
      return { resolve: async () => next }
    }

    return { resolve: async () => behaviour.resolved }
  }
}
