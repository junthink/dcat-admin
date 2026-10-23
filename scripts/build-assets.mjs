import {build, transform} from 'esbuild';
import * as sass from 'sass';
import postcss from 'postcss';
import autoprefixer from 'autoprefixer';
import {cp, mkdir, mkdtemp, readFile, readdir, rm, stat, writeFile} from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import {pathToFileURL} from 'node:url';

const production = process.argv.includes('--production');
const destination = production ? 'resources/dist' : 'resources/pre-dist';
const assets = 'resources/assets';
const plugins = `${assets}/dcat/plugins`;
const themes = {blue: '#6d8be6', 'blue-light': '#62a8ea', green: '#4e9876'};
const bundleOptions = {bundle: true, format: 'iife', platform: 'browser', target: ['es2020'], minify: production, legalComments: 'inline'};
const json = file => readFile(file, 'utf8').then(JSON.parse);
async function copy(source, target) {
    await mkdir(path.dirname(target), {recursive: true});
    await cp(source, target, {recursive: true});
}
async function synchronizeVendors() {
    await rm(`${assets}/sass/bootstrap`, {recursive: true, force: true});
    await copy('node_modules/bootstrap/scss', `${assets}/sass/bootstrap`);
    const sources = ['jquery/dist/jquery.min.js', 'popper.js/dist/umd/popper.min.js', 'bootstrap/dist/js/bootstrap.min.js', 'perfect-scrollbar/dist/perfect-scrollbar.min.js'];
    const vendor = (await Promise.all(sources.map(file => readFile(`node_modules/${file}`, 'utf8')))).join('\n')
        + '\n' + await readFile(`${assets}/dcat/js/vendor-legacy.js`, 'utf8');
    const compiled = await transform(vendor, {minify: true, legalComments: 'inline', target: 'es2020'});
    await writeFile(`${plugins}/vendors.min.js`, compiled.code);
    await copy('node_modules/moment/min/moment.min.js', `${plugins}/moment/moment.min.js`);
    await copy('node_modules/moment/min/moment-with-locales.min.js', `${plugins}/moment/moment-with-locales.min.js`);
    await copy('node_modules/moment/min/locales.min.js', `${plugins}/moment/locales.min.js`);
    await rm(`${plugins}/moment-timezone`, {recursive: true, force: true});
    await copy('node_modules/moment-timezone/builds', `${plugins}/moment-timezone`);
    await copy('node_modules/underscore/underscore-umd-min.js', `${plugins}/editor-md/lib/underscore.min.js`);
    await copy('node_modules/raphael/raphael.min.js', `${plugins}/editor-md/lib/raphael.min.js`);
    await copy('node_modules/toastr/build/toastr.min.js', `${plugins}/extensions/toastr.min.js`);
    await copy('node_modules/katex/dist', `${plugins}/editor-md/lib/katex`);
    await rm(`${plugins}/editor-md/lib/codemirror`, {recursive: true, force: true});
    for (const folder of ['lib', 'addon', 'mode', 'theme']) await copy(`node_modules/codemirror/${folder}`, `${plugins}/editor-md/lib/codemirror/${folder}`);
    await copy('node_modules/codemirror/LICENSE', `${plugins}/editor-md/lib/codemirror/LICENSE`);
    const cm = `${plugins}/editor-md/lib/codemirror`;
    await copy(`${cm}/lib/codemirror.css`, `${cm}/codemirror.min.css`);
    await writeFile(`${cm}/codemirror.min.js`, (await transform(await readFile(`${cm}/lib/codemirror.js`, 'utf8'), {minify: true, legalComments: 'inline'})).code);
    const modes = ['xml', 'javascript', 'css', 'htmlmixed', 'clike', 'php', 'markdown', 'gfm', 'sass', 'shell', 'sql', 'http', 'go', 'dart', 'coffeescript', 'nginx', 'python', 'perl', 'lua', 'r', 'ruby', 'rst', 'smarty', 'vb', 'vbscript', 'velocity', 'xquery', 'yaml', 'erlang', 'pug'];
    const addons = ['edit/trailingspace', 'dialog/dialog', 'search/searchcursor', 'search/search', 'scroll/annotatescrollbar', 'search/matchesonscrollbar', 'display/placeholder', 'edit/closetag', 'fold/foldcode', 'fold/foldgutter', 'fold/indent-fold', 'fold/brace-fold', 'fold/xml-fold', 'fold/markdown-fold', 'fold/comment-fold', 'mode/overlay', 'selection/active-line', 'edit/closebrackets', 'display/fullscreen', 'search/match-highlighter'];
    // Editor.md loads these bundles in sequence; overlay is also a mode dependency.
    for (const [name, files] of Object.entries({modes: [`${cm}/addon/mode/overlay.js`, `${cm}/mode/meta.js`, ...modes.map(mode => `${cm}/mode/${mode}/${mode}.js`)], addons: addons.map(addon => `${cm}/addon/${addon}.js`)})) {
        const source = (await Promise.all(files.map(file => readFile(file, 'utf8')))).join('\n');
        await writeFile(`${cm}/${name}.min.js`, (await transform(source, {minify: true, legalComments: 'inline'})).code);
    }
    const languages = `${plugins}/tinymce/langs`;
    const temporary = await mkdtemp(path.join(os.tmpdir(), 'dcat-tinymce-langs-'));
    try {
        await copy(languages, `${temporary}/langs`);
        await rm(`${plugins}/tinymce`, {recursive: true, force: true});
        await copy('node_modules/tinymce', `${plugins}/tinymce`);
        await copy(`${temporary}/langs`, languages);
    } finally { await rm(temporary, {recursive: true, force: true}); }
    // TinyMCE bundles its own DOMPurify. Upgrade that copy as well as our direct dependency.
    const purifyModule = (await readFile('node_modules/dompurify/dist/purify.es.mjs', 'utf8'))
        .replace(/var purify_default = createDOMPurify\(\);[\s\S]*$/, 'return createDOMPurify();');
    for (const file of ['tinymce.js', 'themes/silver/theme.js']) {
        const target = `${plugins}/tinymce/${file}`;
        const source = await readFile(target, 'utf8');
        const start = source.indexOf('/*! @license DOMPurify ');
        const endMarker = 'var purify = createDOMPurify();';
        const end = source.indexOf(endMarker, start);
        if (start < 0 || end < 0) throw new Error(`Review the embedded DOMPurify integration in TinyMCE ${file} before upgrading.`);
        const patched = '/*! TinyMCE: see license.md. Modified 2026-10-23: embeds the locked DOMPurify security release; see scripts/build-assets.mjs. */\n'
            + source.slice(0, start) + 'var purify = (() => {\n' + purifyModule + '\n})();' + source.slice(end + endMarker.length);
        await writeFile(target, patched);
        await writeFile(target.replace(/\.js$/, '.min.js'), (await transform(patched, {minify: true, format: 'iife', legalComments: 'inline', target: 'es2020'})).code);
    }
    await build({...bundleOptions, minify: true, entryPoints: [`${assets}/dcat/js/marked-compat.js`], outfile: `${plugins}/editor-md/lib/marked.min.js`});
    const editor = await readFile(`${plugins}/editor-md/editormd.js`, 'utf8');
    await writeFile(`${plugins}/editor-md/editormd.min.js`, (await transform(editor, {minify: true, legalComments: 'inline'})).code);
}

async function compileStyles(source, target, color = null) {
    const warnings = new Set();
    const sourceText = (color ? `$primary: ${color};\n` : '') + await readFile(source, 'utf8');
    const result = sass.compileString(sourceText, {url: pathToFileURL(path.resolve(source)), style: production ? 'compressed' : 'expanded', quietDeps: true,
        logger: {warn: message => warnings.add(message), debug: () => {}}});
    const css = await postcss([autoprefixer]).process(result.css, {from: source, to: target, map: false});
    await mkdir(path.dirname(target), {recursive: true});
    await writeFile(target, css.css);
    if (warnings.size) console.log(`${source}: compiled legacy SCSS (${warnings.size} deprecation notices).`);
}

async function compileTheme(name, color) {
    const suffix = name === 'default' ? '' : `-${name}`;
    await Promise.all([
        compileStyles(`${assets}/adminlte/scss/AdminLTE.scss`, `${destination}/adminlte/adminlte${suffix}.css`, color),
        compileStyles(`${assets}/dcat/sass/dcat-app.scss`, `${destination}/dcat/css/dcat-app${suffix}.css`, color),
    ]);
}

async function compile() {
    await synchronizeVendors();
    await rm(destination, {recursive: true, force: true});
    for (const folder of ['images', 'fonts']) await copy(`${assets}/${folder}`, `${destination}/${folder}`);
    await copy(plugins, `${destination}/dcat/plugins`);
    await rm(`${assets}/licenses`, {recursive: true, force: true});
    for (const name of ['jquery', 'bootstrap', 'popper.js', 'perfect-scrollbar', 'moment', 'moment-timezone', 'codemirror', 'marked', 'dompurify', 'underscore', 'raphael', 'katex', 'sweetalert2', 'toastr', 'tinymce']) {
        for (const file of await readdir(`node_modules/${name}`)) {
            if (/^(licen[cs]e|copying|notices)/i.test(file)) await copy(`node_modules/${name}/${file}`, `${assets}/licenses/${name}/${file}`);
        }
    }
    await copy(`${assets}/licenses`, `${destination}/licenses`);
    const jobs = [
        compileStyles(`${assets}/adminlte/scss/AdminLTE.scss`, `${destination}/adminlte/adminlte.css`),
        compileStyles(`${assets}/dcat/sass/dcat-app.scss`, `${destination}/dcat/css/dcat-app.css`),
        build({...bundleOptions, entryPoints: [`${assets}/adminlte/js/AdminLTE.js`], outfile: `${destination}/adminlte/adminlte.js`}),
        build({...bundleOptions, entryPoints: [`${assets}/dcat/js/dcat-app.js`], outfile: `${destination}/dcat/js/dcat-app.js`}),
        copy(`${assets}/dcat/sass/nunito.css`, `${destination}/dcat/css/nunito.css`),
    ];
    for (const [name, color] of Object.entries(themes)) jobs.push(compileTheme(name, color));
    for (const file of await readdir(`${assets}/dcat/extra`)) {
        if (file.endsWith('.js')) jobs.push(build({...bundleOptions, entryPoints: [`${assets}/dcat/extra/${file}`], outfile: `${destination}/dcat/extra/${file}`}));
        if (file.endsWith('.scss')) jobs.push(compileStyles(`${assets}/dcat/extra/${file}`, `${destination}/dcat/extra/${file.replace(/scss$/, 'css')}`));
    }
    await Promise.all(jobs);
    const packageInfo = await json('package-lock.json');
    await writeFile(`${destination}/dependency-versions.json`, JSON.stringify(Object.fromEntries(Object.entries(packageInfo.packages).filter(([name]) => name && !name.slice(13).includes('node_modules/')).map(([name, value]) => [name.replace(/^node_modules\//, ''), value.version])), null, 2) + '\n');
    console.log(`Built ${destination}`);
}
const requestedTheme = process.argv.find(arg => arg.startsWith('--theme='))?.slice(8);
if (requestedTheme) {
    if (!/^[a-z][a-z0-9-]{0,63}$/.test(requestedTheme)) throw new Error('Invalid theme name.');
    const color = process.argv.find(arg => arg.startsWith('--color='))?.slice(8) || themes[requestedTheme];
    if (requestedTheme !== 'default' && !/^#[\da-f]{3}(?:[\da-f]{3})?$/i.test(color || '')) throw new Error('A theme requires a valid hex color.');
    await compileTheme(requestedTheme, color);
    console.log(`Built theme ${requestedTheme}`);
} else await compile();
if (process.argv.includes('--watch')) {
    let running = false, checking = false, pending = false;
    async function fingerprint(directory = assets) {
        const values = [];
        for (const entry of await readdir(directory, {withFileTypes: true})) {
            const file = `${directory}/${entry.name}`;
            if (file === plugins || file === `${assets}/licenses` || file === `${assets}/sass/bootstrap`) continue;
            if (entry.isDirectory()) values.push(await fingerprint(file));
            else { const info = await stat(file); values.push(`${file}:${info.size}:${info.mtimeMs}`); }
        }
        return values.sort().join('|');
    }
    let previous = await fingerprint();
    async function rebuild() {
        if (running) return;
        running = true;
        try {
            do { pending = false; await compile(); } while (pending);
        } catch (error) { console.error(error); }
        finally { running = false; }
    }
    // Poll source metadata: reliable on macOS, Linux, and bind-mounted development volumes.
    // Generated vendor and license files are excluded to prevent rebuild loops.
    setInterval(async () => {
        if (checking) return;
        checking = true;
        try {
            const current = await fingerprint();
            if (current !== previous) { previous = current; pending = true; await rebuild(); }
        } catch (error) { console.error(error); }
        finally { checking = false; }
    }, 500);
    console.log('Watching resources/assets');
}
