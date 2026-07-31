/**
 * Regression tests for resources/js/dispatchConsole.js.
 *
 *   node tests/js/console-capture.test.mjs
 *
 * Dependency-free and runs on plain node — the package ships no JS toolchain and
 * this is not worth adding one for. PHPUnit only scans tests/Feature, so these
 * live alongside it without colliding.
 *
 * WHAT THIS PINS. The capture layer wraps console.error. If anything inside that
 * wrapper throws, it does not merely lose a log line — it REPLACES the error the
 * host was trying to report and skips the original console.error entirely, so the
 * real error never reaches the developer. The whole contract is therefore
 * "never throw, whatever you are handed".
 *
 * The input that broke it in production was a Vue component instance: Vue's
 * global errorHandler logs the instance alongside the error, and that instance is
 * a proxy resolving neither valueOf nor toString. JSON.stringify fails on it
 * (circular) and String() raises "Cannot convert object to primitive value" — so
 * a bare `catch { return String(v) }` fallback was the one call guaranteed to
 * blow up on exactly the input it existed to rescue.
 *
 * The source is loaded and evaluated directly (ESM exports stripped) so these
 * exercise the REAL implementation, including its module-private helpers.
 */
import fs from 'node:fs';
import path from 'node:path';
import url from 'node:url';
import vm from 'node:vm';

const here = path.dirname(url.fileURLToPath(import.meta.url));
const target = process.argv[2] || path.join(here, '..', '..', 'resources', 'js', 'dispatchConsole.js');
const src = fs.readFileSync(target, 'utf8').replace(/^export /gm, '');

const results = [];
const ok = (name, pass, detail = '') => results.push({ name, pass, detail });

/**
 * Stands in for the Vue component instance. Deliberately MORE hostile than the
 * real thing — it also throws from the getPrototypeOf trap — so the guards are
 * tested past the case that actually occurred.
 */
const makeHostile = () => new Proxy({}, {
  get(t, p) {
    if (p === 'valueOf' || p === 'toString' || p === Symbol.toPrimitive) {
      throw new Error('Cannot convert object to primitive value');
    }
    return Reflect.get(t, p);
  },
  getPrototypeOf() { throw new Error('proxy getPrototypeOf trap'); },
});

// ---------------------------------------------------------------- stringify --

{
  const ctx = { console, Symbol, Object, Array, JSON, WeakSet, String, Error, Date, Reflect, Proxy };
  vm.createContext(ctx);
  vm.runInContext(`${src}\nglobalThis.__stringify = stringify;`, ctx);
  const s = ctx.__stringify;

  const circular = { name: 'root' };
  circular.self = circular;
  class Widget { constructor() { this.a = 1; } }

  const cases = [
    ['Error yields its message', () => s(new Error('boom')) === 'boom'],
    ['string passes through', () => s('hello') === 'hello'],
    ['null / undefined are literal', () => s(null) === 'null' && s(undefined) === 'undefined'],
    ['number', () => s(42) === '42'],
    ['symbol does not throw', () => s(Symbol('x')).includes('Symbol')],
    ['plain object serializes by value', () => s({ a: 1 }) === '{"a":1}'],
    ['array serializes by value', () => s([1, 2]) === '[1,2]'],
    ['circular object degrades, never throws', () => s(circular).includes('circular')],
    ['BigInt inside an object survives', () => s({ n: 1n }) === '{"n":"1n"}'],
    // Non-plain objects are reported by TAG, never probed — probing arbitrary
    // host objects is the fragility this layer exists to avoid.
    ['class instance reported by tag, not probed', () => s(new Widget()) === '[object Object]'],
    ['hostile proxy returns a string instead of throwing', () => typeof s(makeHostile()) === 'string'],
    ['oversized output is capped', () => s({ big: 'x'.repeat(5000) }).length <= 520],
  ];

  for (const [name, fn] of cases) {
    let pass = false;
    let detail = '';
    try {
      pass = fn() === true;
    } catch (e) {
      detail = `THREW ${e.message}`;
    }
    ok(`stringify: ${name}`, pass, detail);
  }
}

// ------------------------------------------------- console.error passthrough --

{
  const seen = [];
  const listeners = {};
  const ctx = {
    window: { addEventListener: (n, fn) => { listeners[n] = fn; } },
    console: { error: (...args) => seen.push(args) },
    Symbol, Object, Array, JSON, WeakSet, String, Error, Date, Reflect, Proxy,
  };
  vm.createContext(ctx);
  vm.runInContext(
    `${src}\nglobalThis.__install = installConsoleCapture; globalThis.__errors = getConsoleErrors;`,
    ctx
  );
  ctx.__install();

  const hostile = makeHostile();
  let threw = null;
  try {
    ctx.console.error('plain message', hostile, { a: 1 });
  } catch (e) {
    threw = e;
  }

  ok('passthrough: console.error does not throw', threw === null, threw ? threw.message : '');
  ok('passthrough: the ORIGINAL console.error still ran', seen.length === 1, `calls=${seen.length}`);
  ok('passthrough: all args forwarded untouched', seen[0]?.length === 3);
  ok('passthrough: first arg is the host message', seen[0]?.[0] === 'plain message');
  ok('passthrough: hostile arg forwarded by reference', seen[0]?.[1] === hostile);
  ok('passthrough: the entry was still captured', ctx.__errors().length === 1);

  let listenerThrew = null;
  try {
    listeners.error?.({ message: '', error: hostile });
    listeners.unhandledrejection?.({ reason: hostile });
  } catch (e) {
    listenerThrew = e;
  }
  ok('passthrough: error/rejection listeners do not throw', listenerThrew === null,
    listenerThrew ? listenerThrew.message : '');
}

// ------------------------------------------------------------------- report --

let failed = 0;
for (const r of results) {
  if (!r.pass) failed++;
  console.log(`${r.pass ? 'PASS' : 'FAIL'}  ${r.name}${r.detail ? `  -- ${r.detail}` : ''}`);
}
console.log(`\n${results.length - failed}/${results.length} passed`);
process.exit(failed ? 1 : 0);
