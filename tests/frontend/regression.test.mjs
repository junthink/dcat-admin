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

async function assertReceivesPointer(locator, description) {
    const result = await locator.evaluate(element => {
        const rect = element.getBoundingClientRect();
        const top = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);
        return {hit: !!top && (element === top || element.contains(top)), covering: top?.outerHTML.slice(0, 180)};
    });
    assert.ok(result.hit, `${description}: covered by ${result.covering}`);
}

async function waitForLayer(page) {
    await page.locator('.layui-layer-page form').waitFor();
    await page.waitForFunction(() => getComputedStyle(document.querySelector('.layui-layer-page')).opacity === '1');
}

async function waitForTabs(page) {
    await page.waitForFunction(() => !!jQuery('#linked-tabs').data('dcat-tab-instance') && !!jQuery('#local-tabs').data('dcat-tab-instance'));
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

test('toast notifications remain visible and positioned in every theme', async t => {
    const page = await pageForTest(t);
    await login(page);
    for (const theme of ['default', 'blue', 'blue-light', 'green']) {
        await page.goto(`${base}/admin/auth/users?theme=${theme}`);
        for (const type of ['success', 'error']) {
            await page.evaluate(type => {
                toastr.remove();
                Dcat[type](`${type} notification`, null, {timeOut: 0, extendedTimeOut: 0, showDuration: 0});
            }, type);
            const toast = page.locator(`#toast-container > .toast-${type}`);
            await toast.waitFor({state: 'visible'});
            assert.equal(await toast.innerText(), `${type} notification`);
            const styles = await toast.evaluate(element => ({
                opacity: Number(getComputedStyle(element).opacity),
                position: getComputedStyle(element.parentElement).position,
                bounds: element.getBoundingClientRect().toJSON(),
                viewportWidth: window.innerWidth,
            }));
            assert.ok(styles.opacity > 0, `${theme}: ${type} toast must have visible opacity`);
            assert.equal(styles.position, 'fixed', `${theme}: ${type} toast container must stay fixed`);
            assert.ok(styles.bounds.top >= 0 && styles.bounds.left >= 0 && styles.bounds.right <= styles.viewportWidth,
                `${theme}: ${type} toast must stay inside the viewport`);
        }
    }
});

test('confirmation dialogs stay above quick-edit forms and remain clickable in every theme', async t => {
    const page = await pageForTest(t);
    await login(page);
    for (const theme of ['default', 'blue', 'blue-light', 'green']) {
        await page.goto(`${base}/admin/auth/users?theme=${theme}`);
        await page.locator('.grid-dropdown-actions [data-toggle=dropdown]').first().click();
        await page.locator('.quick-edit').first().click();
        await page.locator('.layui-layer-page form').waitFor();
        await page.evaluate(() => Dcat.confirm('Quick edit confirmation', 'Continue?', () => {
            window.__quickEditConfirmed = true;
        }, () => {
            window.__quickEditCancelled = true;
        }));
        const levels = await page.evaluate(() => ({
            editor: Number(getComputedStyle(document.querySelector('.layui-layer-page')).zIndex),
            confirmation: Number(getComputedStyle(document.querySelector('.swal2-container')).zIndex),
        }));
        assert.ok(levels.confirmation > levels.editor, `${theme}: confirmation must stay above quick edit`);
        await page.locator('.swal2-cancel').click();
        await page.waitForFunction(() => window.__quickEditCancelled === true);
        assert.equal(await page.locator('.layui-layer-page form').isVisible(), true, theme);
        await page.evaluate(() => Dcat.confirm('Quick edit confirmation', 'Continue?', () => {
            window.__quickEditConfirmed = true;
        }));
        await page.locator('.swal2-confirm').click();
        await page.waitForFunction(() => window.__quickEditConfirmed === true);
    }
});

test('notifications remain clickable over maximized and mobile quick-edit forms', async t => {
    const page = await pageForTest(t);
    await login(page);
    for (const [theme, width] of [['default', 1280], ['blue', 1280], ['blue-light', 1280], ['green', 1280], ['default', 390]]) {
        await page.setViewportSize({width, height: 844});
        await page.goto(`${base}/admin/auth/users?theme=${theme}`);
        await page.locator('.grid-dropdown-actions [data-toggle=dropdown]').first().click();
        await page.locator('.quick-edit').first().click();
        await waitForLayer(page);
        if (width > 800) await page.locator('.layui-layer-max').click();
        await page.waitForFunction(() => document.querySelector('.layui-layer-page').getBoundingClientRect().width >= innerWidth - 1);
        await page.evaluate(() => Dcat.error('Validation error', null, {timeOut: 0, extendedTimeOut: 0, showDuration: 0, hideDuration: 0, closeButton: true}));
        const toast = page.locator('#toast-container .toast-error');
        await toast.waitFor();
        await assertReceivesPointer(toast, `${theme}/${width}: notification`);
        if (width === 390) await page.screenshot({path: `${results}/toast-mobile-overlay.png`});
        await toast.locator('.toast-close-button').click();
        await toast.waitFor({state: 'detached'});
    }
});

test('rich-text dialogs accept focus and save links inside Layer and Bootstrap forms', async t => {
    const page = await pageForTest(t);
    await login(page);
    for (const type of ['layer', 'modal']) {
        await page.goto(`${base}/admin/regression/overlays`);
        await page.locator(type === 'layer' ? '#open-layer-form' : '#open-editor-modal').click();
        const owner = type === 'layer' ? '.layui-layer-page' : '.modal.show';
        await page.locator(`${owner} form`).waitFor();
        await page.waitForFunction(selector => {
            const textarea = document.querySelector(`${selector} textarea[name=overlay_editor]`);
            return textarea && tinymce.get(textarea.id)?.initialized && getComputedStyle(document.querySelector(selector)).opacity === '1';
        }, owner);
        const id = await page.locator(`${owner} textarea[name=overlay_editor]`).getAttribute('id');
        await page.evaluate(id => {const editor = tinymce.get(id); editor.focus(); editor.execCommand('mceLink');}, id);
        const dialog = page.locator('.tox-dialog');
        await dialog.waitFor();
        await assertReceivesPointer(dialog.getByRole('button', {name: 'Save', exact: true}), `${type}: editor dialog`);
        const url = `https://example.test/${type}`;
        const input = dialog.getByLabel('URL', {exact: true});
        await input.fill(url);
        assert.equal(await input.evaluate(element => document.activeElement === element), true, `${type}: URL input focus`);
        await dialog.getByLabel('Text to display', {exact: true}).fill(`${type} link`);
        if (type === 'layer') await page.screenshot({path: `${results}/editor-layer-dialog.png`});
        await dialog.getByRole('button', {name: 'Save', exact: true}).click();
        await dialog.waitFor({state: 'detached'});
        assert.match(await page.evaluate(id => tinymce.get(id).getContent(), id), new RegExp(`https://example\\.test/${type}`));
        if (type === 'layer') await page.locator('.layui-layer-close').click();
    }
});

test('inline grid editors stay usable in modals across themes and dark mode', async t => {
    const page = await pageForTest(t);
    await login(page);
    for (const theme of ['default', 'blue', 'blue-light', 'green']) {
        for (const dark of [false, true]) {
            await page.goto(`${base}/admin/regression/grid?modal=1&theme=${theme}`);
            if (dark) await page.evaluate(() => Dcat.darkMode.display(true));
            await page.locator('#open-grid-modal').click();
            await page.locator('.modal.show').waitFor();
            await page.waitForFunction(() => getComputedStyle(document.querySelector('.modal.show')).opacity === '1');
            await page.locator('.modal.show [data-editinline=popover]').click();
            const popover = page.locator('.popover.show');
            await popover.waitFor();
            await assertReceivesPointer(popover.locator('input.ie-input'), `${theme}/${dark}: inline editor`);
            await popover.locator('input.ie-input').fill('Edited value');
            assert.equal(await popover.locator('input.ie-input').evaluate(el => document.activeElement === el), true);
            await popover.locator('.ie-cancel').click();
            await popover.waitFor({state: 'detached'});
        }
    }
});

test('color picker remains interactive in Layer forms without a global popover override', async t => {
    const page = await pageForTest(t);
    await login(page);
    await page.goto(`${base}/admin/regression/overlays?color=1`);
    await page.locator('#open-layer-form').click();
    await waitForLayer(page);
    const input = page.locator('.layui-layer-page input[name=color]');
    await input.click();
    const palette = page.locator('.colorpicker:visible .colorpicker-saturation');
    await palette.waitFor();
    await assertReceivesPointer(palette, 'color picker in Layer form');
    await palette.click({position: {x: 40, y: 40}});
    assert.notEqual(await input.inputValue(), '#6d8be6');
});

test('scrolling grids keep row menus visible, anchored and interactive', async t => {
    const page = await pageForTest(t);
    await login(page);
    await page.goto(`${base}/admin/regression/grid?scroll=1`);
    const wrapper = page.locator('.table-scrollbar-x');
    assert.equal(await wrapper.evaluate(el => el.scrollWidth > el.clientWidth && getComputedStyle(el).overflowX === 'auto'), true);
    const toggle = page.locator('.grid-dropdown-actions [data-toggle=dropdown]').first();
    await toggle.click();
    const menu = page.locator('.grid-dropdown-actions .dropdown-menu.show');
    await menu.waitFor();
    const action = menu.locator('.regression-row-action');
    await assertReceivesPointer(action, 'scrolling grid row action');
    await page.screenshot({path: `${results}/grid-row-menu.png`});
    const before = await menu.boundingBox();
    await page.mouse.wheel(0, 60);
    await page.waitForFunction(y => Math.abs(document.querySelector('.grid-dropdown-actions .dropdown-menu.show').getBoundingClientRect().y - y) > 20, before.y);
    await assertReceivesPointer(action, 'row action after scrolling');
    await action.click();
    assert.equal(await page.evaluate(() => window.rowActionClicked), true);
    await menu.waitFor({state: 'hidden'});
    assert.equal(await wrapper.evaluate(el => getComputedStyle(el).overflowX), 'auto');
    await toggle.click();
    await menu.waitFor();
    await page.evaluate(() => jQuery(document).trigger('pjax:beforeReplace'));
    await menu.waitFor({state: 'hidden'});
});

test('side filters retain visible controls when narrow screens are resized', async t => {
    const page = await pageForTest(t, {viewport: {width: 320, height: 844}});
    await login(page);
    await page.goto(`${base}/admin/regression/grid`);
    await page.locator('.filter-button-group > button').first().click();
    await page.waitForFunction(() => getComputedStyle(document.querySelector('.slider-panel.open')).right === '0px');
    for (const width of [320, 390, 844]) {
        await page.setViewportSize({width, height: 844});
        const bounds = await page.locator('.slider-panel.open').boundingBox();
        assert.ok(bounds.x >= -1 && bounds.x + bounds.width <= width + 1, `${width}: panel must fit`);
        const field = await page.locator('.slider-panel.open input[name=name]').boundingBox();
        assert.ok(field.x >= 0 && field.x + field.width <= width, `${width}: filter input must fit`);
        const search = page.locator('.slider-panel.open button.submit');
        const box = await search.boundingBox();
        assert.ok(box.x >= 0 && box.x + box.width <= width, `${width}: search button must fit`);
        await assertReceivesPointer(search, `${width}: filter search`);
        if (width === 320) await page.screenshot({path: `${results}/filter-mobile.png`});
    }
    await page.setViewportSize({width: 320, height: 844});
    await page.locator('.slider-panel.open input[name=name]').fill('Administrator');
    await page.locator('.slider-panel.open button.submit').click();
    await page.waitForURL(url => url.searchParams.get('name') === 'Administrator');
});

test('linked tabs keep their full order and render only the selected content', async t => {
    const page = await pageForTest(t);
    await login(page);
    await page.goto(`${base}/admin/regression/tabs?_t=receive&page=7`);
    await waitForTabs(page);
    const links = page.locator('#linked-tabs > .dcat-tabs-nav > .nav-item > .nav-link');
    const order = await links.allTextContents();
    assert.equal(order[0], '活跃日历');
    assert.equal(order[3], '收礼记录');
    assert.equal(await page.locator('#linked-tabs .nav-link.active').innerText(), '收礼记录');
    assert.equal(await page.locator('[data-loaded-tab]').count(), 1);
    await links.filter({hasText: '设备信息'}).click();
    await page.waitForURL(url => url.searchParams.get('_t') === 'device');
    await page.locator('[data-loaded-tab=device]').waitFor();
    assert.equal(new URL(page.url()).searchParams.get('page'), '1');
    assert.deepEqual(await links.allTextContents(), order);
    assert.equal(await page.locator('[data-loaded-tab]').getAttribute('data-loaded-tab'), 'device');
    await page.goto(`${base}/admin/regression/tabs?no_search=1`);
    assert.equal(await page.locator('#linked-tabs [data-tab-search-toggle]').count(), 0);
});

test('compact tab search supports keyboard navigation and stays scoped to its widget', async t => {
    const page = await pageForTest(t);
    await login(page);
    await page.goto(`${base}/admin/regression/tabs`);
    await waitForTabs(page);
    const root = page.locator('#linked-tabs');
    const nav = root.locator('.dcat-tabs-nav');
    const height = (await nav.boundingBox()).height;
    const order = await nav.locator('.nav-link').allTextContents();
    const toggle = root.locator('[data-tab-search-toggle]');
    await toggle.click();
    const input = root.locator('[data-tab-search-input]');
    await input.fill('DEvICE');
    assert.equal(await root.locator('.dcat-tab-search-result:visible').count(), 1);
    assert.equal(await root.locator('.dcat-tab-search-result:visible').innerText(), '设备信息');
    assert.equal((await nav.boundingBox()).height, height);
    assert.deepEqual(await nav.locator('.nav-link').allTextContents(), order);
    assert.equal(await page.locator('[data-loaded-tab]').getAttribute('data-loaded-tab'), 'receive');
    await input.press('ArrowDown');
    assert.equal(await root.locator('.dcat-tab-search-result:visible').evaluate(el => document.activeElement === el), true);
    await page.keyboard.press('Enter');
    await page.waitForURL(url => url.searchParams.get('_t') === 'device');
    await page.locator('[data-loaded-tab=device]').waitFor();
    await waitForTabs(page);
    const local = page.locator('#local-tabs');
    await local.locator('[data-tab-search-toggle]').click();
    await local.locator('[data-tab-search-input]').fill('Second');
    await local.locator('[data-tab-search-input]').press('Enter');
    await page.waitForFunction(() => location.hash === '#tab_local-second');
    assert.equal(await local.locator('.tab-pane.active').innerText(), 'Second local content');
    assert.equal(await root.locator('.nav-link.active').innerText(), '设备信息');
    await local.locator('[data-tab-search-toggle]').click();
    await local.locator('[data-tab-search-input]').press('Escape');
    assert.equal(await local.locator('[data-tab-search-panel]').isVisible(), false);
    assert.equal(await local.locator('[data-tab-search-toggle]').evaluate(el => document.activeElement === el), true);
});

test('tab search handles no matches, unsafe text and IME input without navigating', async t => {
    const page = await pageForTest(t);
    await login(page);
    await page.goto(`${base}/admin/regression/tabs`);
    await waitForTabs(page);
    const root = page.locator('#linked-tabs');
    await root.locator('[data-tab-search-toggle]').click();
    const input = root.locator('[data-tab-search-input]');
    await input.fill('<img src=x onerror="window.__tabSearchXss=1">');
    assert.equal(await root.locator('.dcat-tab-search-result:visible').count(), 0);
    assert.equal(await root.locator('[data-tab-search-empty]').isVisible(), true);
    assert.equal(await page.evaluate(() => window.__tabSearchXss), undefined);
    await root.evaluate(element => {
        const form = document.createElement('form');
        form.addEventListener('submit', event => {event.preventDefault(); window.unexpectedTabSubmit = true;});
        element.replaceWith(form);
        form.append(element);
    });
    await input.press('Enter');
    assert.equal(await page.evaluate(() => window.unexpectedTabSubmit), undefined);
    await input.fill('设备');
    const url = page.url();
    await input.evaluate(el => el.dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', isComposing: true, bubbles: true})));
    assert.equal(page.url(), url);
    assert.equal(await root.locator('.dcat-tab-search-result:visible').count(), 1);
    await page.locator('#local-tabs .nav-link').first().click();
    assert.equal(await root.locator('[data-tab-search-panel]').isVisible(), false);
});

test('tab search panels fit narrow screens and all color themes in dark mode', async t => {
    const page = await pageForTest(t);
    await login(page);
    for (const theme of ['default', 'blue', 'blue-light', 'green']) {
        for (const width of [1280, 320]) {
            await page.setViewportSize({width, height: 844});
            await page.goto(`${base}/admin/regression/tabs?theme=${theme}`);
            await waitForTabs(page);
            if (width === 320) await page.evaluate(() => Dcat.darkMode.display(true));
            const root = page.locator('#linked-tabs');
            const height = (await root.locator('.dcat-tabs-nav').boundingBox()).height;
            const iconFits = await root.locator('[data-tab-search-toggle]').evaluate(button => {
                const bounds = button.getBoundingClientRect();
                const icon = button.querySelector('i').getBoundingClientRect();
                return icon.left >= bounds.left && icon.right <= bounds.right
                    && Math.abs((icon.left + icon.right) / 2 - (bounds.left + bounds.right) / 2) < 1;
            });
            assert.ok(iconFits, `${theme}/${width}: search icon must fit and stay centered`);
            if (theme === 'green' && width === 1280) await root.screenshot({path: `${results}/tab-search-closed.png`});
            await root.locator('[data-tab-search-toggle]').click();
            const input = root.locator('[data-tab-search-input]');
            await input.fill('记录');
            await assertReceivesPointer(input, `${theme}/${width}: search input`);
            const bounds = await root.locator('[data-tab-search-panel]').boundingBox();
            assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width + 1, `${theme}/${width}: panel must fit`);
            assert.equal((await root.locator('.dcat-tabs-nav').boundingBox()).height, height);
            if (theme === 'green') await page.screenshot({path: `${results}/tab-search-${width}.png`});
            await input.press('Escape');
        }
    }
});

test('content tabs honor hashes and searchable vertical themes without changing other widgets', async t => {
    const page = await pageForTest(t);
    await login(page);
    await page.goto(`${base}/admin/regression/tabs?vertical=1#tab_local-second`);
    await waitForTabs(page);
    const local = page.locator('#local-tabs');
    assert.equal(await local.locator('.tab-pane.active').innerText(), 'Second local content');
    const toggle = local.locator('[data-tab-search-toggle]');
    await assertReceivesPointer(toggle, 'vertical tab search');
    await toggle.click();
    await local.locator('[data-tab-search-input]').fill('First');
    await local.locator('[data-tab-search-input]').press('Enter');
    await page.waitForFunction(() => location.hash === '#tab_local-first');
    assert.equal(await local.locator('.tab-pane.active').innerText(), 'First local content');
    assert.equal(await page.locator('#linked-tabs .nav-link.active').innerText(), '收礼记录');
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
