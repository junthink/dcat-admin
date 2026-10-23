import {marked as current, Renderer} from 'marked';
import DOMPurify from 'dompurify';

// Keep Editor.md's renderer API while parsing with the maintained Marked engine.
class LegacyRenderer {
    constructor() { this.options = {headerPrefix: ''}; }
    code(text, lang, escaped) {
        return Renderer.prototype.code.call(this, {text, lang, escaped});
    }
}

function adapt(legacy) {
    const renderer = new Renderer();
    const adapters = {
        link: (t, p) => [t.href, t.title, p.parseInline(t.tokens)],
        heading: (t, p) => [DOMPurify.sanitize(p.parseInline(t.tokens), {ALLOWED_TAGS: []}), t.depth, t.text],
        paragraph: (t, p) => [p.parseInline(t.tokens), t],
        code: t => [t.text, t.lang, t.escaped],
        tablecell: (t, p) => [p.parseInline(t.tokens), {header: t.header, align: t.align}],
        listitem: (t, p) => [p.parse(t.tokens, t.loose)],
    };
    for (const [name, args] of Object.entries(adapters)) {
        if (typeof legacy[name] !== 'function') continue;
        renderer[name] = function (token) {
            legacy.parser = this.parser;
            return legacy[name](...args(token, this.parser));
        };
    }
    return renderer;
}

let defaults = {};
function marked(source, options = {}) {
    if (!DOMPurify.isSupported) throw new Error('Secure Markdown rendering is unavailable in this browser.');
    const settings = {...defaults, ...options, async: false};
    if (settings.renderer) settings.renderer = adapt(settings.renderer);
    // Removed legacy options must not turn HTML sanitization off.
    for (const key of ['sanitize', 'tables', 'smartLists', 'smartypants', 'headerPrefix']) delete settings[key];
    return DOMPurify.sanitize(current.parse(String(source), settings), {USE_PROFILES: {html: true}});
}
marked.Renderer = LegacyRenderer;
marked.setOptions = options => { defaults = {...defaults, ...options}; return marked; };
marked.parse = marked;
window.marked = marked;
window.DOMPurify = DOMPurify;
