<script setup>
/**
 * sgrjr/dispatch — from-any-page capture widget (Vue 3, framework-agnostic host).
 *
 * Published via `php artisan vendor:publish --tag=dispatch-vue`. Drop it into any
 * layout that is present on every authenticated page. It POSTs to the package's
 * headless capture endpoint; no build step or package-specific tooling required.
 *
 * Usage:
 *   import DispatchWidget from '@/vendor/dispatch/DispatchWidget.vue'
 *   <DispatchWidget />                       // defaults to POST /dispatch/capture
 *   <DispatchWidget endpoint="/dispatch/capture" />
 */
import { ref, computed } from 'vue'

const props = defineProps({
  endpoint: { type: String, default: '/dispatch/capture' },
  // 'float' = fixed floating button (default); 'inline' = a plain trigger the
  // host places itself (e.g. in a footer). The modal is identical in both.
  variant: { type: String, default: 'float' },
  label: { type: String, default: 'Feedback' },
  // Quick links to the feature's core pages, shown inside the modal, e.g.
  // [{ label: 'My submissions', href: '/dispatch/mine' }, ...]. Host-supplied
  // so it can show role-appropriate destinations.
  links: { type: Array, default: () => [] },
  // Base path of the package's own routes (matches config('dispatch.routes.prefix')).
  // Used to build the built-in nav links when `links` isn't supplied — so the
  // host gets navigation for free and never has to hard-code the package's URLs.
  basePath: { type: String, default: '/dispatch' },
})

const open = ref(false)
const title = ref('')
const type = ref('bug')
const description = ref('')
const files = ref([])
const submitting = ref(false)
const result = ref(null)
const error = ref('')

const canSubmit = computed(() => title.value.trim().length > 0 && !submitting.value)

// Built-in navigation to the feature's core pages. The host needs no knowledge
// of the package's routes; it may override via `links`, or set `basePath` if it
// changed the package's route prefix.
const navLinks = computed(() =>
  props.links.length
    ? props.links
    : [
        { label: 'My submissions', href: `${props.basePath}/mine` },
        { label: 'Feedback board', href: `${props.basePath}/board` },
        { label: 'New', href: `${props.basePath}/new` },
      ],
)

function csrfToken() {
  const el = document.head.querySelector('meta[name="csrf-token"]')
  return el ? el.getAttribute('content') : ''
}

function addFiles(list) {
  for (const f of list) {
    if (f && f.type && f.type.startsWith('image/')) files.value.push(f)
    else if (f) files.value.push(f)
  }
}

function onPaste(e) {
  const items = e.clipboardData && e.clipboardData.items
  if (!items) return
  for (const item of items) {
    if (item.kind === 'file') {
      const file = item.getAsFile()
      if (file) files.value.push(file)
    }
  }
}

function onDrop(e) {
  e.preventDefault()
  if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files)
}

function onPick(e) {
  addFiles(e.target.files)
  e.target.value = ''
}

function removeFile(i) {
  files.value.splice(i, 1)
}

function reset() {
  title.value = ''
  type.value = 'bug'
  description.value = ''
  files.value = []
  error.value = ''
  result.value = null
}

function close() {
  open.value = false
  // keep result briefly? just reset for next time
  if (result.value) reset()
}

async function submit() {
  if (!canSubmit.value) return
  submitting.value = true
  error.value = ''

  const body = new FormData()
  body.append('title', title.value.trim())
  body.append('type', type.value)
  body.append('description', description.value)
  body.append('page_url', window.location.href)
  files.value.forEach((f) => body.append('files[]', f))

  try {
    const res = await fetch(props.endpoint, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
      credentials: 'same-origin',
      body,
    })

    if (!res.ok) {
      const payload = await res.json().catch(() => ({}))
      error.value = payload.message || `Something went wrong (${res.status}).`
      return
    }

    result.value = await res.json()
    title.value = ''
    description.value = ''
    files.value = []
  } catch (e) {
    error.value = 'Network error — please try again.'
  } finally {
    submitting.value = false
  }
}

function previewUrl(file) {
  return file.type && file.type.startsWith('image/') ? URL.createObjectURL(file) : null
}
</script>

<template>
  <div class="dispatch-widget">
    <button
      v-if="variant === 'float'"
      type="button"
      class="dw-fab"
      title="Report a bug or suggest a feature"
      @click="open = true"
    >
      <span aria-hidden="true">✎</span>
      <span class="dw-fab-label">{{ label }}</span>
    </button>
    <button
      v-else
      type="button"
      class="dw-trigger"
      title="Report a bug or suggest a feature"
      @click="open = true"
    >
      {{ label }}
    </button>

    <div v-if="open" class="dw-overlay" @click.self="close">
      <div class="dw-modal" role="dialog" aria-modal="true" @paste="onPaste">
        <header class="dw-head">
          <strong>Report a bug / suggest a feature</strong>
          <button type="button" class="dw-x" @click="close" aria-label="Close">×</button>
        </header>

        <nav v-if="navLinks.length" class="dw-links">
          <span class="dw-links-label">Go to</span>
          <a v-for="l in navLinks" :key="l.href" :href="l.href" class="dw-navlink">{{ l.label }}</a>
        </nav>

        <div v-if="result" class="dw-success">
          <p>Thanks — logged as <strong>{{ result.code }}</strong>.</p>
          <a v-if="result.url" :href="result.url" class="dw-link">View it</a>
          <button type="button" class="dw-secondary" @click="reset">Report another</button>
        </div>

        <form v-else class="dw-body" @submit.prevent="submit" @dragover.prevent @drop="onDrop">
          <label class="dw-field">
            <span>Title</span>
            <input v-model="title" type="text" maxlength="255" placeholder="Short summary" autofocus />
          </label>

          <label class="dw-field">
            <span>Type</span>
            <select v-model="type">
              <option value="bug">Bug</option>
              <option value="feature">Feature</option>
            </select>
          </label>

          <label class="dw-field">
            <span>Details</span>
            <textarea v-model="description" rows="4" placeholder="What happened? Paste a screenshot here (Ctrl/Cmd+V)."></textarea>
          </label>

          <div class="dw-files">
            <label class="dw-attach">
              <input type="file" multiple accept="image/*,application/pdf,text/plain" @change="onPick" hidden />
              <span>Attach / paste screenshot</span>
            </label>
            <ul v-if="files.length" class="dw-thumbs">
              <li v-for="(f, i) in files" :key="i">
                <img v-if="previewUrl(f)" :src="previewUrl(f)" alt="" />
                <span v-else class="dw-fileicon">{{ f.name }}</span>
                <button type="button" @click="removeFile(i)" aria-label="Remove">×</button>
              </li>
            </ul>
          </div>

          <p v-if="error" class="dw-error">{{ error }}</p>

          <footer class="dw-foot">
            <button type="button" class="dw-secondary" @click="close">Cancel</button>
            <button type="submit" class="dw-primary" :disabled="!canSubmit">
              {{ submitting ? 'Sending…' : 'Send' }}
            </button>
          </footer>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.dispatch-widget {
  --dw-accent: var(--dispatch-accent, #ea7317);
  --dw-fg: var(--dispatch-fg, #1f2933);
  --dw-bg: var(--dispatch-bg, #ffffff);
  --dw-border: var(--dispatch-border, #d7dee5);
}
.dw-fab {
  position: fixed; right: 1.25rem; bottom: 1.25rem; z-index: 9998;
  display: inline-flex; align-items: center; gap: 0.4rem;
  padding: 0.6rem 0.9rem; border: none; border-radius: 999px;
  background: var(--dw-accent); color: #fff; cursor: pointer;
  box-shadow: 0 6px 20px rgba(0,0,0,.18); font-size: 0.9rem;
}
.dw-fab-label { font-weight: 600; }
.dw-trigger {
  background: none; border: none; padding: 0; margin: 0;
  color: inherit; font: inherit; cursor: pointer; text-decoration: underline;
}
.dw-trigger:hover { opacity: 0.85; }
.dw-overlay {
  position: fixed; inset: 0; z-index: 9999; display: flex;
  align-items: center; justify-content: center;
  background: rgba(15,23,32,.45); padding: 1rem;
}
.dw-modal {
  width: 100%; max-width: 30rem; background: var(--dw-bg); color: var(--dw-fg);
  border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,.3); overflow: hidden;
}
.dw-head { display: flex; justify-content: space-between; align-items: center;
  padding: 0.9rem 1rem; border-bottom: 1px solid var(--dw-border); }
.dw-x { border: none; background: none; font-size: 1.4rem; line-height: 1; cursor: pointer; color: inherit; }
.dw-body, .dw-success { padding: 1rem; display: flex; flex-direction: column; gap: 0.75rem; }
.dw-field { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.85rem; }
.dw-field span { font-weight: 600; }
.dw-field input, .dw-field select, .dw-field textarea {
  padding: 0.5rem 0.6rem; border: 1px solid var(--dw-border); border-radius: 6px;
  font: inherit; color: inherit; background: var(--dw-bg);
}
.dw-attach { display: inline-block; padding: 0.45rem 0.7rem; border: 1px dashed var(--dw-border);
  border-radius: 6px; cursor: pointer; font-size: 0.82rem; }
.dw-thumbs { list-style: none; margin: 0.5rem 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.5rem; }
.dw-thumbs li { position: relative; }
.dw-thumbs img { width: 56px; height: 56px; object-fit: cover; border-radius: 6px; border: 1px solid var(--dw-border); }
.dw-fileicon { display: inline-block; max-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.7rem; }
.dw-thumbs button { position: absolute; top: -6px; right: -6px; width: 18px; height: 18px; border-radius: 50%;
  border: none; background: #111; color: #fff; cursor: pointer; line-height: 1; }
.dw-foot { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.25rem; }
.dw-primary { background: var(--dw-accent); color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; font-weight: 600; }
.dw-primary:disabled { opacity: .55; cursor: not-allowed; }
.dw-secondary { background: none; border: 1px solid var(--dw-border); padding: 0.5rem 0.9rem; border-radius: 6px; cursor: pointer; color: inherit; }
.dw-error { color: #c0392b; font-size: 0.82rem; margin: 0; }
.dw-link { color: var(--dw-accent); font-weight: 600; }
.dw-links { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; padding: 0.55rem 1rem; border-bottom: 1px solid var(--dw-border); font-size: 0.8rem; }
.dw-links-label { opacity: 0.55; }
.dw-navlink { color: var(--dw-accent); text-decoration: none; font-weight: 600; }
.dw-navlink:hover { text-decoration: underline; }
@media (prefers-color-scheme: dark) {
  .dispatch-widget { --dispatch-fg: #e7edf3; --dispatch-bg: #1b232c; --dispatch-border: #33414f; }
}
</style>
