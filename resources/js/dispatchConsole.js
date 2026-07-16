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

function stringify(v) {
  if (v instanceof Error) return v.message;
  if (typeof v === 'string') return v;
  try {
    return JSON.stringify(v);
  } catch (e) {
    return String(v);
  }
}

function shortStack(err) {
  if (!err || !err.stack) return undefined;
  return String(err.stack).split('\n').slice(0, 8).join('\n');
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
      push({ type: 'console.error', message: args.map(stringify).join(' ') });
      orig(...args);
    };
  }

  window.addEventListener('error', (e) => {
    push({
      type: 'error',
      message: e && e.message ? e.message : stringify(e && e.error),
      source: e && e.filename ? `${e.filename}:${e.lineno}:${e.colno}` : undefined,
      stack: shortStack(e && e.error),
    });
  });

  window.addEventListener('unhandledrejection', (e) => {
    const r = e ? e.reason : null;
    push({
      type: 'unhandledrejection',
      message: r && r.message ? r.message : stringify(r),
      stack: shortStack(r),
    });
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
