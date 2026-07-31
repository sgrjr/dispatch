/**
 * sgrjr/dispatch — lightweight, dependency-free client diagnostics.
 *
 * Installs once and keeps a small ring buffer of recent console.error output,
 * uncaught errors, and unhandled promise rejections, so the feedback widget can
 * attach "what actually happened" (not just a screenshot) to a report. Pure
 * best-effort: every path is guarded and never throws into the host app.
 *
 * The widget self-installs this on import, but a host may also call
 * installConsoleCapture() at the very top of its app entry for earliest capture.
 */

const MAX = 25;
const buffer = [];

/** Cap on a single stringified value, so one fat object can't crowd the buffer. */
const MAX_VALUE_CHARS = 500;

/**
 * Is this something we can safely serialize by VALUE rather than by tag?
 *
 * Only plain objects and arrays qualify. Everything else — class instances,
 * framework proxies, DOM nodes, host objects — gets reported by its internal
 * class instead of probed, because probing arbitrary objects is what made this
 * fragile in the first place. Reading the prototype can itself trip a Proxy
 * trap, so even this check is guarded.
 */
function isPlainValue(v) {
  if (Array.isArray(v)) return true;
  try {
    const proto = Object.getPrototypeOf(v);
    // Realm-agnostic on purpose. `proto === Object.prototype` is the obvious
    // test but compares against THIS realm's intrinsic, so a plain object from
    // an iframe or a separately-bundled chunk would be misread as exotic and
    // reported as a bare tag with its contents dropped. A plain object's
    // prototype is instead the one whose OWN prototype is null; a class
    // instance's is not (Widget.prototype -> Object.prototype -> null).
    if (proto === null) return true;
    return Object.getPrototypeOf(proto) === null;
  } catch (e) {
    return false;
  }
}

/** JSON.stringify that tolerates circular refs and BigInt. undefined on failure. */
function safeJson(v) {
  try {
    const seen = new WeakSet();
    return JSON.stringify(v, (key, value) => {
      if (typeof value === 'bigint') return `${value}n`;
      if (value !== null && typeof value === 'object') {
        if (seen.has(value)) return '[circular]';
        seen.add(value);
      }
      return value;
    });
  } catch (e) {
    return undefined;
  }
}

/**
 * Best-effort value -> string. MUST NOT throw: it runs inside the patched
 * console.error, so anything that escapes here replaces the error the host was
 * trying to report (and suppresses it entirely, since the throw happens before
 * the original console.error is called).
 *
 * The failure that motivated the layering: Vue's global errorHandler logs the
 * component INSTANCE alongside the error. That instance is a proxy which
 * resolves neither `valueOf` nor `toString`, so `String(instance)` raises
 * "Cannot convert object to primitive value" — and JSON.stringify has already
 * failed on it (circular), so a plain `catch { return String(v) }` fallback was
 * the one call guaranteed to blow up on exactly the input it was meant to
 * rescue.
 */
function stringify(v) {
  try {
    if (v instanceof Error) return v.message;
    if (typeof v === 'string') return v;
    if (v === null) return 'null';
    if (v === undefined) return 'undefined';

    if (typeof v === 'object') {
      // Serialize by value only when it is safe to look inside.
      if (isPlainValue(v)) {
        const json = safeJson(v);
        // undefined for values JSON can't represent — fall through to the tag.
        if (json !== undefined) {
          return json.length > MAX_VALUE_CHARS
            ? `${json.slice(0, MAX_VALUE_CHARS)}…`
            : json;
        }
      }

      // Never throws: reads only the internal class / Symbol.toStringTag.
      try {
        return Object.prototype.toString.call(v);
      } catch (e) {
        return '[unserializable object]';
      }
    }

    // Primitives, incl. symbols (String(sym) is legal; `${sym}` is not).
    return String(v);
  } catch (e) {
    return '[unserializable]';
  }
}

function shortStack(err) {
  try {
    if (!err || !err.stack) return undefined;
    return String(err.stack).split('\n').slice(0, 8).join('\n');
  } catch (e) {
    return undefined;
  }
}

function push(entry) {
  try {
    buffer.push({ ...entry, at: new Date().toISOString() });
    if (buffer.length > MAX) buffer.shift();
  } catch (e) {
    /* never throw into the host */
  }
}

export function installConsoleCapture() {
  if (typeof window === 'undefined' || window.__dispatchCaptureInstalled) return;
  window.__dispatchCaptureInstalled = true;

  const orig = typeof console !== 'undefined' && console.error ? console.error.bind(console) : null;
  if (orig) {
    console.error = (...args) => {
      // Capture is wrapped separately from the passthrough, and the passthrough
      // runs LAST-but-unconditionally. push()'s own try/catch is not enough:
      // args.map(stringify) is evaluated as an ARGUMENT, i.e. outside it, so a
      // throw there used to escape console.error AND skip orig() — silently
      // replacing the host's error with the capture layer's own.
      try {
        push({ type: 'console.error', message: args.map(stringify).join(' ') });
      } catch (e) {
        /* never throw into the host */
      }
      orig(...args);
    };
  }

  window.addEventListener('error', (e) => {
    try {
      push({
        type: 'error',
        message: e && e.message ? e.message : stringify(e && e.error),
        source: e && e.filename ? `${e.filename}:${e.lineno}:${e.colno}` : undefined,
        stack: shortStack(e && e.error),
      });
    } catch (err) {
      /* never throw into the host */
    }
  });

  window.addEventListener('unhandledrejection', (e) => {
    try {
      const r = e ? e.reason : null;
      push({
        type: 'unhandledrejection',
        message: r && r.message ? r.message : stringify(r),
        stack: shortStack(r),
      });
    } catch (err) {
      /* never throw into the host */
    }
  });
}

export function getConsoleErrors() {
  return buffer.slice();
}

/**
 * The structured context to attach to a feedback submission. Reliable, free,
 * and never mis-renders — unlike a screenshot.
 */
export function getDispatchContext() {
  const w = typeof window !== 'undefined' ? window : null;
  return {
    url: w ? w.location.href : null,
    referrer: typeof document !== 'undefined' ? document.referrer || null : null,
    user_agent: typeof navigator !== 'undefined' ? navigator.userAgent : null,
    viewport: w ? { w: w.innerWidth, h: w.innerHeight, dpr: w.devicePixelRatio } : null,
    captured_at: new Date().toISOString(),
    console_errors: getConsoleErrors(),
  };
}
