import assert from 'node:assert/strict';
import {before, after, test} from 'node:test';
import {spawn, spawnSync} from 'node:child_process';
import {existsSync} from 'node:fs';
import {mkdir} from 'node:fs/promises';
import net from 'node:net';
import {chromium} from 'playwright';

let browser, server, base;
const password = 'browser-regression-password-32chars';
const results = 'tests/.browser-results';
const php = process.env.DCAT_PHP_BINARY || 'php';
async function waitUntil(predicate, description) {
    const deadline = Date.now() + 15000;
    while (Date.now() < deadline) {
        if (await predicate()) return;
        await new Promise(resolve => setTimeout(resolve, 50));
    }
    throw new Error(`Timed out: ${description}`);
}
before(async () => {
    await mkdir(results, {recursive: true});
    const socket = net.createServer();
    await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    base = `http://127.0.0.1:${port}`;
    const env = {...process.env, DCAT_BROWSER_URL: base};
    const setup = spawnSync(php, ['-d', 'opcache.enable=0', 'tests/frontend/setup.php'], {env, encoding: 'utf8'});
    assert.equal(setup.status, 0, setup.stdout + setup.stderr);
    server = spawn(php, ['-d', 'opcache.enable=0', '-S', `127.0.0.1:${port}`, '-t', 'tests/.browser-runtime/public', 'tests/frontend/router.php'], {env, stdio: 'ignore'});
    await waitUntil(async () => {try {return (await fetch(`${base}/admin/auth/login`)).ok;} catch {return false;}}, 'Laravel fixture server');
    const chrome = process.env.DCAT_CHROME_PATH || (existsSync('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome') ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : undefined);
    browser = await chromium.launch({headless: true, ...(chrome ? {executablePath: chrome} : {})});
});
after(async () => {
    await browser?.close();
    server?.kill('SIGTERM');
});
async function pageForTest(t, options = {}) {
    const context = await browser.newContext(options);
    const page = await context.newPage();
    const errors = [], failedAssets = [], external = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => {
        if (response.status() >= 400 && /\/vendor\//.test(response.url())) failedAssets.push(`${response.status()} ${response.url()}`);
    });
    page.on('request', request => {if (!request.url().startsWith(base) && /^https?:/.test(request.url())) external.push(request.url());});
    t.after(async () => {
        await context.close();
        assert.deepEqual(errors, [], 'Unexpected browser JavaScript exceptions');
        assert.deepEqual(failedAssets, [], 'Static asset failures');
        assert.deepEqual(external, [], 'Bundled widgets must work without remote CDN scripts');
    });
    return page;
}
async function login(page) {
    await page.goto(`${base}/admin/auth/login`);
    assert.equal(await page.locator('[name=password]').getAttribute('maxlength'), '4096');
    await page.locator('[name=username]').fill('browser-admin');
    await page.locator('[name=password]').fill(password);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(`${base}/admin/auth/users`);
    await page.locator('table').first().waitFor();
}
async function widgets(page) {
    await login(page);
    await page.goto(`${base}/admin/regression`);
    await page.waitForFunction(() => window.tinymce?.get()?.[0]?.initialized && document.querySelector('.CodeMirror')?.CodeMirror && window.DOMPurify?.isSupported);
}

test('long-password login, user grid search/sort, and CSRF-protected logout', async t => {
    const page = await pageForTest(t);
    await login(page);
    assert.match(await page.locator('body').innerText(), /browser-admin/);
    await page.goto(`${base}/admin/auth/users?_sort[column]=id&_sort[type]=desc`);
    assert.match(await page.locator('body').innerText(), /browser-admin/);
    await page.goto(`${base}/admin/auth/users?_search_=browser-admin`);
    assert.match(await page.locator('body').innerText(), /browser-admin/);
    assert.equal((await page.request.get(`${base}/admin/auth/logout`)).status(), 405);
    assert.equal((await page.request.post(`${base}/admin/auth/logout`, {headers: {'Accept': 'application/json'}})).status(), 419);
    const token = await page.evaluate(() => Dcat.token);
    const logout = await page.request.post(`${base}/admin/auth/logout`, {form: {_token: token}});
    assert.equal(logout.status(), 200);
    await page.goto(`${base}/admin/auth/users`);
    assert.match(page.url(), /\/auth\/login$/);
});

test('user create, edit, and delete with actual Laravel forms', async t => {
    const page = await pageForTest(t);
    await login(page);
    await page.goto(`${base}/admin/auth/users/create`);
    await page.locator('[name=username]').fill('browser-created-user');
    await page.locator('[name=name]').fill('Browser Created User');
    await page.locator('[name=password]').fill('browser-test-123');
    await page.locator('[name=password_confirmation]').fill('browser-test-123');
    const createResponse = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/auth/users' && r.request().method() === 'POST');
    await page.locator('form.form-horizontal button.submit').first().click();
    const created = await (await createResponse).json();
    assert.equal(created.status, true, JSON.stringify(created));
    await page.goto(`${base}/admin/auth/users?_search_=browser-created-user`);
    const row = page.locator('tr').filter({hasText: 'browser-created-user'});
    await row.waitFor();
    const id = await row.locator('input.grid-row-checkbox').getAttribute('data-id');
    const key = id || await row.locator('input[type=checkbox]').getAttribute('value');
    assert.ok(key);
    await page.goto(`${base}/admin/auth/users/${key}/edit`);
    await page.locator('[name=name]').fill('Browser Updated User');
    const updateResponse = page.waitForResponse(r => new URL(r.url()).pathname === `/admin/auth/users/${key}` && r.request().method() === 'POST');
    await page.locator('form.form-horizontal button.submit').first().click();
    const updated = await (await updateResponse).json();
    assert.equal(updated.status, true, JSON.stringify(updated));
    await page.goto(`${base}/admin/auth/users?_search_=browser-created-user`);
    assert.match(await page.locator('body').innerText(), /Browser Updated User/);
    const token = await page.evaluate(() => Dcat.token);
    const deleted = await page.request.delete(`${base}/admin/auth/users/${key}`, {form: {_token: token}});
    assert.equal((await deleted.json()).status, true);
    await page.reload();
    assert.doesNotMatch(await page.locator('tbody').innerText(), /browser-created-user/);
});

test('Markdown renders tables, code, tasks and TOC while blocking stored and live XSS', async t => {
    const page = await pageForTest(t);
    await widgets(page);
    assert.equal(await page.evaluate(() => window.__xss), undefined);
    assert.equal(await page.locator('.markdown-body table tbody tr').count(), 1);
    assert.match(await page.locator('.editormd-html-preview').innerText(), /Safe heading/);
    assert.equal(await page.locator('[onerror], [onmouseover]').count(), 0);
    const payload = '# Heading " onmouseover="window.__xss=4\n\n[TOC]\n\n<img src=x onerror="window.__xss=5"><script>window.__xss=6</script>\n\n[x](javascript:window.__xss=7)\n\n$$x^2$$';
    await page.evaluate(value => document.querySelector('.CodeMirror').CodeMirror.setValue(value), payload);
    await page.waitForFunction(() => document.querySelector('.editormd-preview-container .katex'));
    assert.equal(await page.evaluate(() => window.__xss), undefined);
    assert.equal(await page.locator('.editormd-preview [onerror], .editormd-preview [onmouseover], .editormd-preview a[href^="javascript:"]').count(), 0);
    assert.equal(await page.evaluate(() => String(window.Element).includes('[native code]')), true);
    assert.equal(await page.evaluate(() => DOMPurify.sanitize('<img onerror=alert(1)><script>alert(2)</script>')), '<img>');
    await page.screenshot({path: `${results}/widgets-desktop.png`, fullPage: true});
});

test('Markdown flowcharts and sequence diagrams work with updated Raphael', async t => {
    const page = await pageForTest(t);
    await widgets(page);
    await page.evaluate(() => {
        const div = document.createElement('div'); div.id = 'diagrams-test'; document.body.append(div);
        editormd.markdownToHTML('diagrams-test', {markdown: '```flow\nst=>start: Start\ne=>end: End\nst->e\n```\n\n```seq\nAlice->Bob: Hello\n```', flowChart: true, sequenceDiagram: true});
    });
    await page.locator('#diagrams-test .flowchart svg').waitFor();
    await page.locator('#diagrams-test .sequence-diagram svg').waitFor();
});

test('TinyMCE 8 edits, sanitizes, synchronizes textarea, and uploads real images', async t => {
    const page = await pageForTest(t);
    await widgets(page);
    assert.equal(await page.evaluate(() => tinymce.majorVersion), '8');
    await page.evaluate(() => {const editor = tinymce.get()[0]; editor.setContent('<p>Updated <strong>rich text</strong></p><img src=x onerror="window.__editorXss=1"><script>window.__editorXss=2</script>'); editor.fire('Change');});
    assert.equal(await page.evaluate(() => window.__editorXss), undefined);
    assert.doesNotMatch(await page.evaluate(() => tinymce.get()[0].getContent()), /onerror|<script/);
    assert.match(await page.locator('textarea[name=richtext]').inputValue(), /Updated/);
    const response = await page.evaluate(async () => {
        const editor = tinymce.get()[0];
        const image = new Blob([Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j/AkAAAAASUVORK5CYII='), c => c.charCodeAt(0))], {type: 'image/png'});
        const body = new FormData(); body.append('file', image, 'image.png');
        const uploaded = await fetch(editor.options.get('images_upload_url'), {method: 'POST', body});
        return {status: uploaded.status, json: await uploaded.json()};
    });
    assert.equal(response.status, 200, JSON.stringify(response));
    assert.match(response.json.location, /\/storage\/images\/tinymce\//);
    assert.equal((await page.request.get(new URL(response.json.location, base).href)).status(), 200);
});

test('generic file upload UI persists the file and verifies ownership on deletion', async t => {
    const page = await pageForTest(t);
    await widgets(page);
    const uploaded = page.waitForResponse(r => r.url().includes('/dcat-api/form/upload') && r.request().method() === 'POST');
    await page.locator('.field_attachment input[type=file]').first().setInputFiles({name: 'browser-attachment.txt', mimeType: 'text/plain', buffer: Buffer.from('browser upload fixture')});
    const response = await (await uploaded).json();
    assert.equal(response.status, true, JSON.stringify(response));
    const path = response.data.id;
    assert.match(path, /^files\//);
    await page.waitForFunction(value => document.querySelector('[name=attachment]').value === value, path);
    assert.equal((await page.request.get(new URL(response.data.url, base).href)).status(), 200);
    const token = await page.evaluate(() => Dcat.token);
    const deleted = await page.request.post(`${base}/admin/dcat-api/form/destroy-file`, {form: {_token: token, _form_: 'BrowserWidgetsForm', _column: 'attachment', key: path}});
    assert.equal(deleted.status(), 200, await deleted.text());
    assert.equal((await page.request.get(new URL(response.data.url, base).href)).status(), 404);
});

test('date picker, select, notifications and upgraded confirmation dialog remain interactive', async t => {
    const page = await pageForTest(t);
    await widgets(page);
    await page.locator('[name=published_at]').click();
    await page.locator('.bootstrap-datetimepicker-widget').waitFor({state: 'visible'});
    assert.equal(await page.locator('[name=published_at]').inputValue(), '2026-10-23');
    await page.locator('[name=title]').click();
    await page.locator('select[name=status]').selectOption('ready');
    assert.equal(await page.locator('select[name=status]').inputValue(), 'ready');
    await page.evaluate(() => {Dcat.success('<img src=x onerror="window.__toastXss=1">Safe notification'); Dcat.confirm('Confirm test', 'Continue?', () => {window.__confirmed = true;});});
    await page.locator('.swal2-confirm').click();
    assert.equal(await page.evaluate(() => window.__confirmed), true);
    assert.equal(await page.evaluate(() => window.__toastXss), undefined);
    assert.equal(await page.locator('#toast-container [onerror]').count(), 0);
});

test('all bundled color themes load compiled styles and retain primary button colors', async t => {
    const page = await pageForTest(t);
    await login(page);
    for (const [theme, expected] of Object.entries({blue: 'rgb(109, 139, 230)', 'blue-light': 'rgb(98, 168, 234)', green: 'rgb(78, 152, 118)'})) {
        await page.goto(`${base}/admin/regression?theme=${theme}`);
        await page.waitForFunction(() => window.tinymce?.get()?.[0]?.initialized && document.querySelector('.CodeMirror')?.CodeMirror);
        assert.ok(await page.locator(`link[href*="dcat-app-${theme}.css"]`).count());
        const color = await page.evaluate(() => getComputedStyle(document.querySelector('[name=title]').closest('form').querySelector('button[type=submit],button.submit')).backgroundColor);
        assert.equal(color, expected, theme);
    }
});

test('mobile layout initializes editors and widgets without JavaScript errors', async t => {
    const page = await pageForTest(t, {viewport: {width: 390, height: 844}, isMobile: true, deviceScaleFactor: 1});
    await widgets(page);
    assert.equal(await page.evaluate(() => tinymce.get().length), 1);
    assert.equal(await page.locator('.CodeMirror').count(), 1);
    await page.screenshot({path: `${results}/widgets-mobile.png`, fullPage: true});
});
